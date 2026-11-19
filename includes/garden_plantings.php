<?php
// includes/garden_plantings.php — Garden (Farm OS, merged Nov 18, 2026 from the standalone garden-crop-manager repo).
// Plantings: lifecycle milestones and bed occupancy, propagation trays and yield summary, succession schedules, rotation history.
// Classes keep their standalone names and the organization_id scoping (Farm OS uses one garden organization, garden_org()).
// Load through includes/garden.php. Tables: garden_* (migration 0072).
declare(strict_types=1);

namespace Garden\Domain;

use PDO;

final class PlantingLifecycle
{
    private const TYPES=['sown','germinated','hardened_off','transplanted','thinned','established','completed','failed'];

    public function events(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT e.*,l.name AS location_name,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name FROM garden_planting_lifecycle_events e JOIN garden_plantings p ON p.id=e.planting_id AND p.organization_id=e.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE e.organization_id=? AND l.site_id=? ORDER BY e.occurred_on DESC,e.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function record(PDO $db,int $org,int $site,int $planting,string $type,string $date,string $quantity,string $unit,string $notes): int
    {
        if (!in_array($type,self::TYPES,true)) throw new \InvalidArgumentException('Choose a valid lifecycle event.');
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Enter a valid event date.');
        $quantity=trim($quantity);$unit=trim($unit);$notes=trim($notes);
        if (($quantity==='')!==($unit==='') || mb_strlen($unit)>32 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Provide quantity and unit together; notes up to 2000 characters.');
        if ($quantity!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$quantity) || (float)$quantity<=0)) throw new \InvalidArgumentException('Quantity must be positive with up to three decimals.');
        $q=$db->prepare('SELECT p.planted_on,p.status FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $q->execute([$planting,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Planting not found in this garden.');
        if ($date<$row['planted_on']) throw new \InvalidArgumentException('Lifecycle event cannot precede the planting date.');
        if (in_array($row['status'],['completed','failed'],true) && !in_array($type,['completed','failed'],true)) throw new \InvalidArgumentException('Closed plantings cannot receive new growth events.');
        $owned=$db->inTransaction();if (!$owned) $db->beginTransaction();
        try {
            $q=$db->prepare('INSERT INTO garden_planting_lifecycle_events (organization_id,planting_id,event_type,occurred_on,quantity,quantity_unit,notes) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$org,$planting,$type,$date,$quantity===''?null:$quantity,$unit?:null,$notes?:null]);
            $id=(int)$db->lastInsertId();
            if (in_array($type,['completed','failed'],true)) {
                $q=$db->prepare('UPDATE garden_plantings SET status=?,ended_on=? WHERE id=? AND organization_id=?');
                $q->execute([$type,$date,$planting,$org]);
            } elseif ($row['status']==='planned') {
                $q=$db->prepare("UPDATE garden_plantings SET status='active' WHERE id=? AND organization_id=?");
                $q->execute([$planting,$org]);
            }
            if (!$owned) $db->commit();return $id;
        } catch (\Throwable $e) {if (!$owned && $db->inTransaction()) $db->rollBack();throw $e;}
    }

    /** Optional physical area used by one planting; does not infer area from plant counts. */
    public function allocateArea(PDO $db,int $org,int $site,int $planting,string $area): void
    {
        $area=trim($area);
        if ($area!=='' && (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D',$area) || (float)$area<=0)) throw new \InvalidArgumentException('Allocated area must be positive square meters, up to three decimals.');
        $q=$db->prepare('SELECT p.location_id,p.status,COALESCE(l.area_sq_m,l.length_m*l.width_m) AS capacity FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=? AND l.active=1');
        $q->execute([$planting,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Planting not found in this active garden area.');
        if (in_array($row['status'],['completed','failed'],true) && $area!=='') throw new \InvalidArgumentException('Only planned or active plantings can reserve area.');
        $owned=$db->inTransaction();if (!$owned) $db->beginTransaction();
        try {
            // Lock location to serialize concurrent allocations for the same area.
            $lock=$db->prepare('SELECT id FROM garden_growing_locations WHERE id=? AND organization_id=? FOR UPDATE');
            $lock->execute([$row['location_id'],$org]);
            if ($area!=='' && $row['capacity']!==null) {
                // Only plantings in the bed at the same time count (Nov 19, 2026): a spring crop and a fall crop can each use the whole bed.
                $q=$db->prepare("SELECT p.id,".\garden_span_sql()." FROM garden_plantings p".\garden_span_joins()." WHERE p.organization_id=? AND p.location_id=? AND p.status IN ('planned','active') AND (p.allocated_area_sq_m IS NOT NULL OR p.id=?)");
                $q->execute([$org,$row['location_id'],$planting]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
                $mine=null;$others=[];
                foreach ($rows as $r) { if ((int)$r['id']===$planting) $mine=\garden_planting_span($r); else $others[]=$r; }
                $spans=[[$mine[0],$mine[1],(float)$area]];
                foreach ($others as $r) { [$s,$e]=\garden_planting_span($r); if (\garden_spans_touch($s,$e,$mine[0],$mine[1])) $spans[]=[max($s,$mine[0]),($e===null?$mine[1]:($mine[1]===null?$e:min($e,$mine[1]))),(float)$r['allocated_area_sq_m']]; }
                if (\garden_peak_area($spans)>(float)$row['capacity']+0.00001) throw new \InvalidArgumentException('Allocated planting area exceeds the recorded growing-area capacity at the same time.');
            }
            $q=$db->prepare('UPDATE garden_plantings SET allocated_area_sq_m=? WHERE id=? AND organization_id=?');
            $q->execute([$area===''?null:$area,$planting,$org]);
            if (!$owned) $db->commit();
        } catch (\Throwable $e) {if (!$owned && $db->inTransaction()) $db->rollBack();throw $e;}
    }

    public function occupancy(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT l.id AS location_id,l.name AS location_name,COUNT(p.id) AS current_plantings,SUM(CASE WHEN p.status='planned' THEN 1 ELSE 0 END) AS planned_plantings,COALESCE(SUM(p.allocated_area_sq_m),0) AS allocated_area_sq_m,COUNT(CASE WHEN p.allocated_area_sq_m IS NULL THEN p.id END) AS unmeasured_plantings,COALESCE(l.area_sq_m,l.length_m*l.width_m) AS capacity_sq_m FROM garden_growing_locations l LEFT JOIN garden_plantings p ON p.location_id=l.id AND p.organization_id=l.organization_id AND p.status IN ('planned','active') WHERE l.organization_id=? AND l.site_id=? AND l.active=1 GROUP BY l.id,l.name,l.area_sq_m,l.length_m,l.width_m ORDER BY l.name");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class PropagationReports
{
    private function date(string $value,bool $required=true): ?string
    {
        if ($value==='' && !$required) return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Enter a valid date.');
        return $value;
    }

    private function planting(PDO $db,int $org,int $site,int $planting): void
    {
        $q=$db->prepare('SELECT 1 FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $q->execute([$planting,$org,$site]);
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Planting not found in this garden.');
    }

    public function trays(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT t.id,t.planting_id,t.label,t.cells_sown,t.germinated,t.sown_on,t.germination_checked_on,t.transplanted_on,t.transplanted_count,t.notes,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,l.name AS location_name FROM garden_propagation_trays t JOIN garden_plantings p ON p.id=t.planting_id AND p.organization_id=t.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE t.organization_id=? AND l.site_id=? ORDER BY t.sown_on DESC,t.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll();
    }

    public function createTray(PDO $db,int $org,int $site,int $planting,string $label,string $cells,string $date,string $notes): int
    {
        $this->planting($db,$org,$site,$planting);
        $label=trim($label);$notes=trim($notes);
        if ($label==='' || mb_strlen($label)>120 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Tray label required (120 characters maximum); notes up to 2000.');
        if (!preg_match('/^[1-9]\d{0,5}$/',$cells)) throw new \InvalidArgumentException('Enter 1–999999 cells sown.');
        $this->date($date);
        $q=$db->prepare('INSERT INTO garden_propagation_trays (organization_id,planting_id,label,cells_sown,sown_on,notes) VALUES (?,?,?,?,?,?)');
        $q->execute([$org,$planting,$label,$cells,$date,$notes?:null]);return (int)$db->lastInsertId();
    }

    public function updateTray(PDO $db,int $org,int $site,int $tray,string $germinated,string $checked,string $transplanted,string $transplantedCount=''): void
    {
        $q=$db->prepare('SELECT t.cells_sown,t.sown_on,t.planting_id,t.transplanted_on,t.transplanted_count,p.planted_on,p.status FROM garden_propagation_trays t JOIN garden_plantings p ON p.id=t.planting_id AND p.organization_id=t.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE t.id=? AND t.organization_id=? AND l.site_id=?');
        $q->execute([$tray,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Tray not found in this garden.');
        if ($germinated!=='' && (!preg_match('/^\d{1,6}$/',$germinated) || (int)$germinated>(int)$row['cells_sown'])) throw new \InvalidArgumentException('Germinated count must be between zero and cells sown.');
        if (($germinated==='')!==($checked==='')) throw new \InvalidArgumentException('Enter germinated count and check date together.');
        $check=$this->date($checked,false);$transplant=$this->date($transplanted,false);
        if (($check!==null && $check<$row['sown_on']) || ($transplant!==null && $transplant<$row['sown_on'])) throw new \InvalidArgumentException('Check and transplant dates cannot precede sowing.');
        $transplantedCount=trim($transplantedCount);
        if (($transplant===null && $transplantedCount!=='') || ($transplantedCount!=='' && (!preg_match('/^[1-9][0-9]{0,5}$/D',$transplantedCount) || (int)$transplantedCount>(int)$row['cells_sown']))) throw new \InvalidArgumentException('Transplant count requires a date and must not exceed cells sown.');
        if ($transplant!==null && $transplant<$row['planted_on']) throw new \InvalidArgumentException('Transplant date cannot precede the planting date.');
        if ($transplant!==null && in_array($row['status'],['completed','failed'],true) && $row['transplanted_on']===null) throw new \InvalidArgumentException('Closed plantings cannot receive new transplant milestones.');
        $owned=$db->inTransaction();if (!$owned) $db->beginTransaction();
        try {
            $q=$db->prepare('UPDATE garden_propagation_trays SET germinated=?,germination_checked_on=?,transplanted_on=?,transplanted_count=? WHERE id=? AND organization_id=?');
            $q->execute([$germinated===''?null:(int)$germinated,$check,$transplant,$transplantedCount===''?null:(int)$transplantedCount,$tray,$org]);
            if ($transplant!==null) {
                $q=$db->prepare("INSERT INTO garden_planting_lifecycle_events (organization_id,planting_id,event_type,occurred_on,quantity,quantity_unit,notes,source_tray_id) VALUES (?,?,'transplanted',?,?,?,'Recorded from propagation tray',?) ON DUPLICATE KEY UPDATE occurred_on=VALUES(occurred_on),quantity=VALUES(quantity),quantity_unit=VALUES(quantity_unit)");
                $q->execute([$org,$row['planting_id'],$transplant,$transplantedCount===''?null:(int)$transplantedCount,$transplantedCount===''?null:'seedlings',$tray]);
                if ($row['status']==='planned') $db->prepare("UPDATE garden_plantings SET status='active' WHERE id=? AND organization_id=?")->execute([$row['planting_id'],$org]);
            } else {
                $q=$db->prepare('DELETE FROM garden_planting_lifecycle_events WHERE source_tray_id=? AND organization_id=?');
                $q->execute([$tray,$org]);
            }
            if (!$owned) $db->commit();
        } catch (\Throwable $e) {if (!$owned && $db->inTransaction()) $db->rollBack();throw $e;}
    }

    public function yieldSummary(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT YEAR(h.harvested_on) AS season,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name,l.name AS location_name,h.unit,SUM(h.quantity) AS total,COUNT(*) AS harvest_count FROM garden_planting_harvest_events h JOIN garden_plantings p ON p.id=h.planting_id AND p.organization_id=h.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE h.organization_id=? AND l.site_id=? GROUP BY YEAR(h.harvested_on),COALESCE(oc.name,c.common_name),COALESCE(ov.name,v.name),l.id,l.name,h.unit ORDER BY season DESC,crop_name,variety_name,location_name,h.unit');
        $q->execute([$org,$site]);return $q->fetchAll();
    }
}

final class SuccessionSchedules
{
    public function schedules(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT s.*,l.name AS location_name,COALESCE(ov.name,v.name) AS variety_name,COALESCE(oc.name,c.common_name) AS crop_name FROM garden_succession_schedules s JOIN garden_growing_locations l ON l.id=s.location_id AND l.organization_id=s.organization_id LEFT JOIN garden_varieties v ON v.id=s.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=s.organization_variety_id AND ov.organization_id=s.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=s.organization_id WHERE s.organization_id=? AND s.site_id=? ORDER BY s.start_on,s.id');
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function rounds(PDO $db,int $org,int $schedule): array
    {
        $q=$db->prepare('SELECT r.round_number,r.due_on,r.planting_id,p.status AS planting_status FROM garden_succession_rounds r JOIN garden_succession_schedules s ON s.id=r.schedule_id AND s.organization_id=r.organization_id LEFT JOIN garden_plantings p ON p.id=r.planting_id AND p.organization_id=r.organization_id WHERE r.organization_id=? AND r.schedule_id=? ORDER BY r.round_number');
        $q->execute([$org,$schedule]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(PDO $db,int $org,int $site,int $location,string $variety,string $start,int $interval,int $rounds,string $method,string $notes): int
    {
        $dates=(new SeasonGuidance())->succession($start,$interval,$rounds);
        if (!in_array($method,['direct_sow','transplant','perennial_installation'],true)) throw new \InvalidArgumentException('Choose a planting method.');
        $notes=trim($notes);if (mb_strlen($notes)>2000) throw new \InvalidArgumentException('Notes are too long.');
        $q=$db->prepare('SELECT 1 FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=? AND active=1');
        $q->execute([$location,$org,$site]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Choose an active area in this garden.');
        $shared=null;$private=null;
        if (preg_match('/^custom:([1-9][0-9]*)$/D',$variety,$m)) {
            $private=(int)$m[1];
            $q=$db->prepare('SELECT 1 FROM garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id WHERE v.id=? AND v.organization_id=? AND v.archived_at IS NULL AND c.archived_at IS NULL');
            $q->execute([$private,$org]);
        } elseif (preg_match('/^[1-9][0-9]*$/D',$variety)) {
            $shared=(int)$variety;$q=$db->prepare('SELECT 1 FROM garden_varieties WHERE id=?');$q->execute([$shared]);
        } else throw new \InvalidArgumentException('Choose a valid variety.');
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Variety not available.');
        $owned=$db->inTransaction();if (!$owned) $db->beginTransaction();
        try {
            $q=$db->prepare('INSERT INTO garden_succession_schedules (organization_id,site_id,location_id,variety_id,organization_variety_id,start_on,interval_days,rounds,method,notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$org,$site,$location,$shared,$private,$start,$interval,$rounds,$method,$notes?:null]);
            $id=(int)$db->lastInsertId();
            $q=$db->prepare('INSERT INTO garden_succession_rounds (organization_id,schedule_id,round_number,due_on) VALUES (?,?,?,?)');
            foreach ($dates as $i=>$date) $q->execute([$org,$id,$i+1,$date]);
            if (!$owned) $db->commit();return $id;
        } catch (\Throwable $e) {if (!$owned && $db->inTransaction()) $db->rollBack();throw $e;}
    }
    public function linkPlanting(PDO $db,int $org,int $site,int $schedule,int $round,int $planting): void
    {
        $q=$db->prepare('SELECT s.location_id,s.variety_id,s.organization_variety_id,r.planting_id FROM garden_succession_schedules s JOIN garden_succession_rounds r ON r.schedule_id=s.id AND r.organization_id=s.organization_id WHERE s.id=? AND s.organization_id=? AND s.site_id=? AND r.round_number=?');
        $q->execute([$schedule,$org,$site,$round]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Schedule round not found.');
        if ($row['planting_id']!==null) throw new \InvalidArgumentException('Round already linked.');
        $q=$db->prepare('SELECT 1 FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=? AND p.location_id=? AND p.variety_id <=> ? AND p.organization_variety_id <=> ?');
        $q->execute([$planting,$org,$site,$row['location_id'],$row['variety_id'],$row['organization_variety_id']]);
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Planting must match the schedule area and variety.');
        $q=$db->prepare('UPDATE garden_succession_rounds SET planting_id=? WHERE schedule_id=? AND organization_id=? AND round_number=? AND planting_id IS NULL');
        $q->execute([$planting,$schedule,$org,$round]);if ($q->rowCount()!==1) throw new \InvalidArgumentException('Round was already linked.');
    }
}

final class FieldWorkflows
{
    public function rotationHistory(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT l.id AS location_id,l.name AS location_name,YEAR(p.planted_on) AS season,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(c.crop_family,oc.crop_family,'Unspecified') AS crop_family,COUNT(*) AS planting_count FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE p.organization_id=? AND l.site_id=? AND p.status IN ('active','completed') GROUP BY l.id,l.name,YEAR(p.planted_on),COALESCE(oc.name,c.common_name),COALESCE(c.crop_family,oc.crop_family,'Unspecified') ORDER BY l.name,season DESC,crop_name");
        $q->execute([$org,$site]);return $q->fetchAll();
    }
}
