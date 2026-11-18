<?php
// includes/garden_care.php — Garden (Farm OS, merged Nov 18, 2026 from the standalone garden-crop-manager repo).
// Care and harvest: care journal, watering schedules, multi-bed care, pests & disease, harvest grades/losses, season reports.
// Classes keep their standalone names and the organization_id scoping (Farm OS uses one garden organization, garden_org()).
// Load through includes/garden.php. Tables: garden_* (migration 0072).
declare(strict_types=1);

namespace Garden\Domain;

use PDO;

final class PlantingJournal
{
    private const CARE_KINDS = ['watering','fertilizing','weeding','pruning','pest_observation','disease_observation','treatment','other'];

    public function careEvents(PDO $db, int $organizationId, int $siteId): array
    {
        $stmt = $db->prepare('SELECT e.id,e.planting_id,e.occurred_on,e.kind,e.notes,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,l.name AS location_name FROM garden_planting_care_events e JOIN garden_plantings p ON p.id=e.planting_id AND p.organization_id=e.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE e.organization_id=? AND l.site_id=? ORDER BY e.occurred_on DESC,e.id DESC');
        $stmt->execute([$organizationId,$siteId]);
        return $stmt->fetchAll();
    }

    public function harvestEvents(PDO $db, int $organizationId, int $siteId): array
    {
        $stmt = $db->prepare('SELECT e.id,e.planting_id,e.harvested_on,e.quantity,e.unit,e.destination,e.notes,e.grade,e.loss_quantity,e.loss_reason,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,l.name AS location_name FROM garden_planting_harvest_events e JOIN garden_plantings p ON p.id=e.planting_id AND p.organization_id=e.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE e.organization_id=? AND l.site_id=? ORDER BY e.harvested_on DESC,e.id DESC');
        $stmt->execute([$organizationId,$siteId]);
        return $stmt->fetchAll();
    }

    public function logCare(PDO $db, int $organizationId, int $siteId, int $plantingId, string $date, string $kind, string $notes): int
    {
        $this->validatePlanting($db,$organizationId,$siteId,$plantingId);
        $this->validateDate($date);
        if (!in_array($kind,self::CARE_KINDS,true)) { throw new \InvalidArgumentException('Choose a valid care type.'); }
        $notes=trim($notes);
        if (mb_strlen($notes)>2000) { throw new \InvalidArgumentException('Care notes must be 2000 characters or fewer.'); }
        $stmt=$db->prepare('INSERT INTO garden_planting_care_events (organization_id,planting_id,occurred_on,kind,notes) VALUES (?,?,?,?,?)');
        $stmt->execute([$organizationId,$plantingId,$date,$kind,$notes ?: null]);
        return (int)$db->lastInsertId();
    }

    public function logHarvest(PDO $db, int $organizationId, int $siteId, int $plantingId, string $date, string $quantity, string $unit, string $destination, string $notes): int
    {
        $this->validatePlanting($db,$organizationId,$siteId,$plantingId);
        $this->validateDate($date);
        $quantity=trim($quantity);
        $unit=trim($unit);
        $destination=trim($destination);
        $notes=trim($notes);
        if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$quantity) || (float)$quantity<=0) {
            throw new \InvalidArgumentException('Harvest quantity must be positive with up to three decimal places.');
        }
        if ($unit==='' || mb_strlen($unit)>32 || mb_strlen($destination)>160 || mb_strlen($notes)>2000) {
            throw new \InvalidArgumentException('Check the harvest unit and field lengths.');
        }
        $stmt=$db->prepare('INSERT INTO garden_planting_harvest_events (organization_id,planting_id,harvested_on,quantity,unit,destination,notes) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$organizationId,$plantingId,$date,$quantity,$unit,$destination ?: null,$notes ?: null]);
        return (int)$db->lastInsertId();
    }

    private function validatePlanting(PDO $db, int $org, int $site, int $planting): void
    {
        if ($org<1 || $site<1 || $planting<1) { throw new \InvalidArgumentException('Choose a valid planting.'); }
        $stmt=$db->prepare('SELECT 1 FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $stmt->execute([$planting,$org,$site]);
        if (!$stmt->fetchColumn()) { throw new \InvalidArgumentException('Planting not found in the selected garden.'); }
    }

    private function validateDate(string $date): void
    {
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$dt || $dt->format('Y-m-d')!==$date) { throw new \InvalidArgumentException('Enter a valid event date.'); }
    }
}

final class IrrigationService
{
    private function date(string $value): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Enter a valid date.');
        return $value;
    }
    private function location(PDO $db,int $org,int $site,int $location): void
    {
        $q=$db->prepare('SELECT 1 FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=? AND active=1');
        $q->execute([$location,$org,$site]);
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Choose an active growing area in this garden.');
    }
    private function positiveInt(string $value,int $min,int $max,string $label): int
    {
        if (!preg_match('/^[1-9][0-9]*$/',$value) || (int)$value<$min || (int)$value>$max) throw new \InvalidArgumentException($label.' must be between '.$min.' and '.$max.'.');
        return (int)$value;
    }
    public function irrigation(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT p.id,p.location_id,p.interval_days,p.next_due_on,p.method,p.notes,p.active,l.name AS location_name FROM garden_irrigation_plans p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND l.site_id=? ORDER BY p.active DESC,p.next_due_on,p.id');
        $q->execute([$org,$site]);return $q->fetchAll();
    }
    public function createIrrigation(PDO $db,int $org,int $site,int $location,string $interval,string $due,string $method,string $notes): int
    {
        $this->location($db,$org,$site,$location);$days=$this->positiveInt($interval,1,365,'Watering interval');$this->date($due);
        $method=trim($method);$notes=trim($notes);
        if ($method==='' || mb_strlen($method)>80 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Enter a watering method (80 characters maximum) and notes up to 2000.');
        $q=$db->prepare('INSERT INTO garden_irrigation_plans (organization_id,location_id,interval_days,next_due_on,method,notes) VALUES (?,?,?,?,?,?)');
        $q->execute([$org,$location,$days,$due,$method,$notes?:null]);return (int)$db->lastInsertId();
    }
    public function events(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT e.id,e.plan_id,e.watered_on,e.amount,e.unit,e.notes,l.name AS location_name FROM garden_irrigation_events e JOIN garden_irrigation_plans p ON p.id=e.plan_id AND p.organization_id=e.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE e.organization_id=? AND l.site_id=? ORDER BY e.watered_on DESC,e.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll();
    }
    public function recordWatering(PDO $db,int $org,int $site,int $plan,string $date,string $amount,string $unit,string $notes): int
    {
        $this->date($date);$unit=trim($unit);$notes=trim($notes);
        if (($amount==='')!==($unit==='')) throw new \InvalidArgumentException('Provide both water quantity and unit, or neither.');
        if ($amount!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$amount) || (float)$amount<=0)) throw new \InvalidArgumentException('Water quantity must be positive, with up to three decimal places.');
        if (mb_strlen($unit)>32 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Water unit or notes are too long.');
        $ownedTransaction=$db->inTransaction();
        if (!$ownedTransaction) $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT p.id,p.interval_days,p.next_due_on FROM garden_irrigation_plans p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=? AND p.active=1 FOR UPDATE');
            $q->execute([$plan,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new \InvalidArgumentException('Active irrigation plan not found in this garden.');
            $q=$db->prepare('INSERT INTO garden_irrigation_events (organization_id,plan_id,watered_on,amount,unit,notes) VALUES (?,?,?,?,?,?)');
            $q->execute([$org,$plan,$date,$amount===''?null:$amount,$unit?:null,$notes?:null]);$id=(int)$db->lastInsertId();
            $next=(new \DateTimeImmutable($date))->modify('+'.(int)$row['interval_days'].' days')->format('Y-m-d');
            if ($next>$row['next_due_on']) {
                $q=$db->prepare('UPDATE garden_irrigation_plans SET next_due_on=? WHERE id=? AND organization_id=?');
                $q->execute([$next,$plan,$org]);
            }
            if (!$ownedTransaction) $db->commit();return $id;
        } catch (\Throwable $e) { if (!$ownedTransaction && $db->inTransaction()) $db->rollBack();throw $e; }
    }
    public function setIrrigationActive(PDO $db,int $org,int $site,int $plan,bool $active): void
    {
        $q=$db->prepare('UPDATE garden_irrigation_plans p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id SET p.active=? WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $q->execute([$active?1:0,$plan,$org,$site]);
        if ($q->rowCount()===0) {
            $q=$db->prepare('SELECT 1 FROM garden_irrigation_plans p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
            $q->execute([$plan,$org,$site]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Irrigation plan not found.');
        }
    }

}

final class CareApplications
{
    private const KINDS=['soil_amendment','fertilizing','weeding','mulching','pruning','other'];

    public function record(PDO $db,int $org,int $site,array $locations,string $kind,string $date,string $material,string $quantity,string $unit,string $notes): int
    {
        if (!in_array($kind,self::KINDS,true)) throw new \InvalidArgumentException('Choose a care activity.');
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Enter a valid care date.');
        $material=trim($material);$quantity=trim($quantity);$unit=trim($unit);$notes=trim($notes);
        if (mb_strlen($material)>160 || mb_strlen($unit)>32 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Care fields exceed allowed length.');
        if (($quantity==='')!==($unit==='')) throw new \InvalidArgumentException('Enter quantity and unit together.');
        if ($quantity!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$quantity) || (float)$quantity<=0)) throw new \InvalidArgumentException('Quantity must be positive, up to three decimals.');
        if ($kind==='soil_amendment' && $material==='') throw new \InvalidArgumentException('Name the soil amendment material.');
        $ids=array_values(array_unique(array_filter(array_map(static function($id):int {
            if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D',(string)$id)) return 0;
            return (int)$id;
        },$locations))));
        if (!$ids || count($ids)>100) throw new \InvalidArgumentException('Choose 1–100 growing areas.');
        $params=array_merge([$org,$site],$ids);
        $q=$db->prepare('SELECT COUNT(*) FROM garden_growing_locations WHERE organization_id=? AND site_id=? AND active=1 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($params);
        if ((int)$q->fetchColumn()!==count($ids)) throw new \InvalidArgumentException('Every growing area must belong to this garden.');
        $owned=$db->inTransaction();if (!$owned) $db->beginTransaction();
        try {
            $q=$db->prepare('INSERT INTO garden_care_applications (organization_id,site_id,kind,applied_on,material,quantity,unit,notes) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$org,$site,$kind,$date,$material?:null,$quantity===''?null:$quantity,$unit?:null,$notes?:null]);
            $id=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO garden_care_application_locations (organization_id,application_id,location_id) VALUES (?,?,?)');
            foreach ($ids as $location) $q->execute([$org,$id,$location]);
            if (!$owned) $db->commit();return $id;
        } catch (\Throwable $e) {if (!$owned && $db->inTransaction()) $db->rollBack();throw $e;}
    }

    public function recent(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT a.id,a.kind,a.applied_on,a.material,a.quantity,a.unit,a.notes,GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR ', ') AS area_names,COUNT(l.id) AS area_count FROM garden_care_applications a JOIN garden_care_application_locations al ON al.application_id=a.id AND al.organization_id=a.organization_id JOIN garden_growing_locations l ON l.id=al.location_id AND l.organization_id=al.organization_id WHERE a.organization_id=? AND a.site_id=? GROUP BY a.id,a.kind,a.applied_on,a.material,a.quantity,a.unit,a.notes ORDER BY a.applied_on DESC,a.id DESC LIMIT 100");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class CropHealth
{
    private function date(string $value): void
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Enter a valid date.');
    }
    private function planting(PDO $db,int $org,int $site,int $planting): void
    {
        $q=$db->prepare('SELECT 1 FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $q->execute([$planting,$org,$site]);
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Planting not found in this garden.');
    }
    private function incident(PDO $db,int $org,int $site,int $id): array
    {
        $q=$db->prepare('SELECT i.* FROM garden_crop_health_incidents i JOIN garden_plantings p ON p.id=i.planting_id AND p.organization_id=i.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE i.id=? AND i.organization_id=? AND l.site_id=?');
        $q->execute([$id,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Incident not found in this garden.');
        return $row;
    }
    public function open(PDO $db,int $org,int $site,int $planting,string $category,string $name,string $severity,string $date,string $notes): int
    {
        $this->planting($db,$org,$site,$planting);$this->date($date);
        if (!in_array($category,['pest','disease'],true) || !in_array($severity,['low','moderate','high','critical','unknown'],true)) throw new \InvalidArgumentException('Choose a valid incident category and severity.');
        $name=trim($name);$notes=trim($notes);
        if ($name==='' || mb_strlen($name)>160 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Identify the issue (160 characters max); notes up to 2000.');
        $q=$db->prepare('INSERT INTO garden_crop_health_incidents (organization_id,planting_id,category,identified_as,severity,observed_on,notes) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$org,$planting,$category,$name,$severity,$date,$notes?:null]);return (int)$db->lastInsertId();
    }
    public function intervene(PDO $db,int $org,int $site,int $incident,string $date,string $method,string $product,string $quantity,string $unit,string $notes): int
    {
        $row=$this->incident($db,$org,$site,$incident);$this->date($date);
        if ($date<$row['observed_on']) throw new \InvalidArgumentException('Intervention cannot precede observation.');
        if ($row['status']==='resolved') throw new \InvalidArgumentException('Reopen incident before recording an intervention.');
        $method=trim($method);$product=trim($product);$quantity=trim($quantity);$unit=trim($unit);$notes=trim($notes);
        if ($method==='' || mb_strlen($method)>160 || mb_strlen($product)>160 || mb_strlen($unit)>32 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Check intervention method, product and notes.');
        if (($quantity==='')!==($unit==='')) throw new \InvalidArgumentException('Quantity and unit must be entered together.');
        if ($quantity!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$quantity) || (float)$quantity<=0)) throw new \InvalidArgumentException('Quantity must be positive with up to three decimals.');
        $q=$db->prepare('INSERT INTO garden_crop_health_interventions (organization_id,incident_id,applied_on,method,product,quantity,unit,notes) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$org,$incident,$date,$method,$product?:null,$quantity===''?null:$quantity,$unit?:null,$notes?:null]);return (int)$db->lastInsertId();
    }
    public function updateOutcome(PDO $db,int $org,int $site,int $incident,string $status,string $date,string $outcome): void
    {
        $row=$this->incident($db,$org,$site,$incident);
        if (!in_array($status,['open','monitoring','resolved'],true)) throw new \InvalidArgumentException('Choose an incident status.');
        $outcome=trim($outcome);
        if (mb_strlen($outcome)>2000) throw new \InvalidArgumentException('Outcome notes must be 2000 characters or fewer.');
        if ($status==='resolved') {
            $this->date($date);
            if ($date<$row['observed_on'] || $outcome==='') throw new \InvalidArgumentException('Resolution needs a date after observation and an outcome.');
        } else $date='';
        $q=$db->prepare('UPDATE garden_crop_health_incidents SET status=?,resolved_on=?,outcome=? WHERE id=? AND organization_id=?');
        $q->execute([$status,$date?:null,$outcome?:null,$incident,$org]);
    }
    public function incidents(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT i.*,l.name AS location_name,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name FROM garden_crop_health_incidents i JOIN garden_plantings p ON p.id=i.planting_id AND p.organization_id=i.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE i.organization_id=? AND l.site_id=? ORDER BY i.status=\'resolved\',i.observed_on DESC,i.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function interventions(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT t.*,i.identified_as FROM garden_crop_health_interventions t JOIN garden_crop_health_incidents i ON i.id=t.incident_id AND i.organization_id=t.organization_id JOIN garden_plantings p ON p.id=i.planting_id AND p.organization_id=i.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE t.organization_id=? AND l.site_id=? ORDER BY t.applied_on DESC,t.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class HarvestQuality
{
    private const GRADES=['premium','standard','processing','ungraded'];

    public function annotate(PDO $db,int $org,int $site,int $harvest,string $grade,string $loss,string $reason): void
    {
        if (!in_array($grade,self::GRADES,true)) throw new \InvalidArgumentException('Choose a valid harvest grade.');
        $loss=trim($loss);$reason=trim($reason);
        if (mb_strlen($reason)>160) throw new \InvalidArgumentException('Loss reason must be 160 characters or fewer.');
        if ($loss!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$loss))) throw new \InvalidArgumentException('Loss must be zero or more with up to three decimals.');
        if ($loss!=='' && (float)$loss>0 && $reason==='') throw new \InvalidArgumentException('Provide a reason for recorded losses.');
        if ($loss==='' && $reason!=='') throw new \InvalidArgumentException('Enter a loss quantity with its reason.');
        $q=$db->prepare('SELECT h.quantity FROM garden_planting_harvest_events h JOIN garden_plantings p ON p.id=h.planting_id AND p.organization_id=h.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE h.id=? AND h.organization_id=? AND l.site_id=?');
        $q->execute([$harvest,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Harvest not found in this garden.');
        if ($loss!=='' && (float)$loss>(float)$row['quantity']) throw new \InvalidArgumentException('Loss cannot exceed harvested quantity.');
        $q=$db->prepare('UPDATE garden_planting_harvest_events SET grade=?,loss_quantity=?,loss_reason=? WHERE id=? AND organization_id=?');
        $q->execute([$grade,$loss===''?null:$loss,$reason?:null,$harvest,$org]);
    }

    public function summary(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT YEAR(h.harvested_on) AS season,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,l.name AS location_name,h.grade,h.unit,COALESCE(h.destination,'Unspecified') AS destination,SUM(h.quantity) AS gross_quantity,SUM(COALESCE(h.loss_quantity,0)) AS loss_quantity,SUM(h.quantity-COALESCE(h.loss_quantity,0)) AS usable_quantity,COUNT(*) AS harvest_count FROM garden_planting_harvest_events h JOIN garden_plantings p ON p.id=h.planting_id AND p.organization_id=h.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE h.organization_id=? AND l.site_id=? GROUP BY YEAR(h.harvested_on),COALESCE(oc.name,c.common_name),COALESCE(ov.name,v.name),l.id,l.name,h.grade,h.unit,COALESCE(h.destination,'Unspecified') ORDER BY season DESC,crop_name,variety_name,location_name,h.grade,h.unit,destination");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class SeasonReports
{
    public function harvestComparison(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT YEAR(h.harvested_on) AS season,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,h.unit,COUNT(DISTINCT p.id) AS harvested_plantings,COUNT(h.id) AS harvest_events,SUM(h.quantity) AS gross_yield,SUM(COALESCE(h.loss_quantity,0)) AS recorded_loss,SUM(h.quantity-COALESCE(h.loss_quantity,0)) AS usable_yield FROM garden_planting_harvest_events h JOIN garden_plantings p ON p.id=h.planting_id AND p.organization_id=h.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE h.organization_id=? AND l.site_id=? GROUP BY YEAR(h.harvested_on),COALESCE(oc.name,c.common_name),COALESCE(ov.name,v.name),h.unit ORDER BY crop_name,variety_name,h.unit,season DESC");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function plantingComparison(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT YEAR(p.planted_on) AS season,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,COUNT(*) AS total_plantings,SUM(p.status='planned') AS planned,SUM(p.status='active') AS active,SUM(p.status='completed') AS completed,SUM(p.status='failed') AS failed FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE p.organization_id=? AND l.site_id=? GROUP BY YEAR(p.planted_on),COALESCE(oc.name,c.common_name),COALESCE(ov.name,v.name) ORDER BY crop_name,variety_name,season DESC");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function seasonTotals(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT YEAR(h.harvested_on) AS season,h.unit,SUM(h.quantity) AS gross_yield,SUM(COALESCE(h.loss_quantity,0)) AS recorded_loss,SUM(h.quantity-COALESCE(h.loss_quantity,0)) AS usable_yield,COUNT(*) AS harvest_events FROM garden_planting_harvest_events h JOIN garden_plantings p ON p.id=h.planting_id AND p.organization_id=h.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE h.organization_id=? AND l.site_id=? GROUP BY YEAR(h.harvested_on),h.unit ORDER BY season DESC,h.unit");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
