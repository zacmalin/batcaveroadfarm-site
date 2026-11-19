<?php
// includes/garden_crops.php — Garden (Farm OS, merged Nov 18, 2026 from the standalone garden-crop-manager repo).
// Crops: shared reference crops, the farm's own crops and varieties, growing profiles, and frost/succession/rotation date math.
// Classes keep their standalone names and the organization_id scoping (Farm OS uses one garden organization, garden_org()).
// Load through includes/garden.php. Tables: garden_* (migration 0072).
declare(strict_types=1);

namespace Garden\Domain;

use DateTimeImmutable;
use PDO;

final class CropCatalog
{
    /** @return list<array<string,mixed>> */
    public function crops(PDO $db): array
    {
        return $db->query('SELECT id, common_name, scientific_name, crop_family, lifecycle FROM garden_crops ORDER BY common_name, id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function varieties(PDO $db): array
    {
        return $db->query('SELECT v.id, v.crop_id, v.name, v.days_to_maturity, v.maturity_basis, c.common_name AS crop_name FROM garden_varieties v JOIN garden_crops c ON c.id = v.crop_id ORDER BY c.common_name, v.name')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function varietiesForOrganization(PDO $db, int $organizationId): array
    {
        $stmt = $db->prepare('SELECT v.id, v.crop_id, v.name, v.days_to_maturity, v.maturity_basis, c.common_name AS crop_name, c.crop_family, c.lifecycle, p.display_name AS local_name, p.days_to_maturity AS local_days_to_maturity, p.maturity_basis AS local_maturity_basis, p.notes AS local_notes FROM garden_varieties v JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_variety_preferences p ON p.variety_id=v.id AND p.organization_id=? ORDER BY c.common_name, v.name');
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }

    public function saveVarietyPreference(PDO $db, int $organizationId, int $varietyId, string $displayName, ?string $days, string $basis, string $notes): void
    {
        $displayName = trim($displayName);
        $notes = trim($notes);
        if ($organizationId < 1 || $varietyId < 1 || mb_strlen($displayName) > 160 || mb_strlen($notes) > 2000) {
            throw new \InvalidArgumentException('Check the variety and field lengths.');
        }
        if (!in_array($basis, ['unknown','from_sowing','from_transplant'], true)) {
            throw new \InvalidArgumentException('Choose a valid maturity basis.');
        }
        $maturity = null;
        if ($days !== null && trim($days) !== '') {
            if (!ctype_digit(trim($days)) || (int)$days < 1 || (int)$days > 65535) {
                throw new \InvalidArgumentException('Days to maturity must be between 1 and 65535.');
            }
            $maturity = (int)$days;
        }
        $stmt = $db->prepare('INSERT INTO garden_organization_variety_preferences (organization_id,variety_id,display_name,days_to_maturity,maturity_basis,notes) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), days_to_maturity=VALUES(days_to_maturity), maturity_basis=VALUES(maturity_basis), notes=VALUES(notes), updated_at=UTC_TIMESTAMP()');
        $stmt->execute([$organizationId,$varietyId,$displayName ?: null,$maturity,$basis,$notes ?: null]);
    }
}

final class CustomCatalog
{
    public function crops(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT id,name,lifecycle,crop_family,scientific_name,archived_at FROM garden_organization_crops WHERE organization_id=? ORDER BY name,id');
        $q->execute([$org]);return $q->fetchAll();
    }

    public function varieties(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT v.id,v.crop_id,v.name,v.days_to_maturity,v.maturity_basis,v.archived_at,c.archived_at AS crop_archived_at,c.name AS crop_name,c.crop_family FROM garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id WHERE v.organization_id=? ORDER BY c.name,v.name');
        $q->execute([$org]);return $q->fetchAll();
    }

    public function createCrop(PDO $db,int $org,string $name,string $lifecycle): int
    {
        $name=trim($name);
        if ($org<1 || $name==='' || mb_strlen($name)>160) { throw new \InvalidArgumentException('Enter a crop name (160 characters maximum).'); }
        if (!in_array($lifecycle,['annual','biennial','perennial'],true)) { throw new \InvalidArgumentException('Choose a valid crop lifecycle.'); }
        $q=$db->prepare('INSERT INTO garden_organization_crops (organization_id,name,lifecycle) VALUES (?,?,?)');
        $q->execute([$org,$name,$lifecycle]);return (int)$db->lastInsertId();
    }

    public function createVariety(PDO $db,int $org,int $crop,string $name,?string $days,string $basis): int
    {
        $name=trim($name);
        if ($org<1 || $crop<1 || $name==='' || mb_strlen($name)>160) { throw new \InvalidArgumentException('Choose a crop and enter a variety name.'); }
        if (!in_array($basis,['unknown','from_sowing','from_transplant'],true)) { throw new \InvalidArgumentException('Choose a valid maturity basis.'); }
        $maturity=null;
        if ($days!==null && trim($days)!=='') {
            if (!ctype_digit(trim($days)) || (int)$days<1 || (int)$days>65535) { throw new \InvalidArgumentException('Days to maturity must be between 1 and 65535.'); }
            $maturity=(int)$days;
        }
        $q=$db->prepare('SELECT 1 FROM garden_organization_crops WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$crop,$org]);
        if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Crop does not belong to your organization.'); }
        $q=$db->prepare('INSERT INTO garden_organization_varieties (organization_id,crop_id,name,days_to_maturity,maturity_basis) VALUES (?,?,?,?,?)');
        $q->execute([$org,$crop,$name,$maturity,$basis]);return (int)$db->lastInsertId();
    }
    public function updateCrop(PDO $db,int $org,int $id,string $name,string $lifecycle): void
    {
        $name=trim($name);
        if ($org<1 || $id<1 || $name==='' || mb_strlen($name)>160 || !in_array($lifecycle,['annual','biennial','perennial'],true)) {
            throw new \InvalidArgumentException('Enter a valid crop name and lifecycle.');
        }
        $q=$db->prepare('UPDATE garden_organization_crops SET name=?,lifecycle=? WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$name,$lifecycle,$id,$org]);
        if (!$q->rowCount()) { $this->assertActiveCrop($db,$org,$id); }
    }

    public function updateVariety(PDO $db,int $org,int $id,string $name,?string $days,string $basis): void
    {
        $name=trim($name);
        if ($org<1 || $id<1 || $name==='' || mb_strlen($name)>160 || !in_array($basis,['unknown','from_sowing','from_transplant'],true)) {
            throw new \InvalidArgumentException('Enter a valid variety name and maturity basis.');
        }
        $maturity=null;
        if ($days!==null && trim($days)!=='') {
            if (!ctype_digit(trim($days)) || (int)$days<1 || (int)$days>65535) { throw new \InvalidArgumentException('Days to maturity must be between 1 and 65535.'); }
            $maturity=(int)$days;
        }
        $q=$db->prepare('UPDATE garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id SET v.name=?,v.days_to_maturity=?,v.maturity_basis=? WHERE v.id=? AND v.organization_id=? AND v.archived_at IS NULL AND c.archived_at IS NULL');
        $q->execute([$name,$maturity,$basis,$id,$org]);
        if (!$q->rowCount()) {
            $q=$db->prepare('SELECT 1 FROM garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id WHERE v.id=? AND v.organization_id=? AND v.archived_at IS NULL AND c.archived_at IS NULL');
            $q->execute([$id,$org]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Active variety not found in your organization.'); }
        }
    }

    public function archiveCrop(PDO $db,int $org,int $id): void
    {
        $this->assertActiveCrop($db,$org,$id);
        $q=$db->prepare('UPDATE garden_organization_crops SET archived_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$id,$org]);
    }

    public function archiveVariety(PDO $db,int $org,int $id): void
    {
        $q=$db->prepare('UPDATE garden_organization_varieties SET archived_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$id,$org]);
        if (!$q->rowCount()) { throw new \InvalidArgumentException('Active variety not found in your organization.'); }
    }

    private function assertActiveCrop(PDO $db,int $org,int $id): void
    {
        $q=$db->prepare('SELECT 1 FROM garden_organization_crops WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$id,$org]);
        if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Active crop not found in your organization.'); }
    }

}

/** Organization-owned growing guidance for shared or private varieties. */
final class CropGrowingProfiles
{
    public function all(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT * FROM garden_crop_growing_profiles WHERE organization_id=? ORDER BY id');
        $q->execute([$org]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(PDO $db,int $org,string $variety,array $data): void
    {
        $global=null;$private=null;
        if (preg_match('/^custom:([1-9][0-9]*)$/D',$variety,$m)) $private=(int)$m[1];
        elseif (preg_match('/^[1-9][0-9]*$/D',$variety)) $global=(int)$variety;
        else throw new \InvalidArgumentException('Choose a valid variety.');
        if ($private!==null) {
            $q=$db->prepare('SELECT 1 FROM garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id WHERE v.id=? AND v.organization_id=? AND v.archived_at IS NULL AND c.archived_at IS NULL');
            $q->execute([$private,$org]);
        } else {
            $q=$db->prepare('SELECT 1 FROM garden_varieties WHERE id=?');$q->execute([$global]);
        }
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Variety not available to your organization.');
        $enums=['sun_exposure'=>['unknown','full_sun','partial_shade','shade'],'water_need'=>['unknown','low','moderate','high']];
        $values=[];
        foreach ($enums as $key=>$allowed) {
            $v=(string)($data[$key]??'unknown');
            if (!in_array($v,$allowed,true)) throw new \InvalidArgumentException('Invalid '.$key.'.');
            $values[$key]=$v;
        }
        $decimals=['soil_ph_min'=>14,'soil_ph_max'=>14,'spacing_cm'=>9999,'row_spacing_cm'=>9999,'sow_depth_cm'=>999];
        foreach ($decimals as $key=>$max) {
            $v=trim((string)($data[$key]??''));
            if ($v!=='' && (!preg_match('/^\d{1,4}(?:\.\d)?$/D',$v) || (float)$v>$max || ($key!=='sow_depth_cm' && (float)$v<=0))) throw new \InvalidArgumentException('Invalid '.$key.'.');
            $values[$key]=$v===''?null:$v;
        }
        if (($values['soil_ph_min']===null)!==($values['soil_ph_max']===null) || ($values['soil_ph_min']!==null && (float)$values['soil_ph_min']>(float)$values['soil_ph_max'])) throw new \InvalidArgumentException('Enter a valid minimum and maximum soil pH together.');
        $ranges=['germination'=>365,'hardiness'=>13];
        foreach ($ranges as $prefix=>$max) {
            $minKey=$prefix.'_min_'.($prefix==='germination'?'days':'zone');$maxKey=$prefix.'_max_'.($prefix==='germination'?'days':'zone');
            $lo=trim((string)($data[$minKey]??''));$hi=trim((string)($data[$maxKey]??''));
            if (($lo==='')!==($hi==='') || ($lo!=='' && (!ctype_digit($lo)||!ctype_digit($hi)||(int)$lo<1||(int)$hi>$max||(int)$lo>(int)$hi))) throw new \InvalidArgumentException('Enter a valid '.$prefix.' range.');
            $values[$minKey]=$lo===''?null:(int)$lo;$values[$maxKey]=$hi===''?null:(int)$hi;
        }
        $notes=trim((string)($data['notes']??''));
        if (mb_strlen($notes)>2000) throw new \InvalidArgumentException('Notes must be 2000 characters or fewer.');
        $values['notes']=$notes?:null;
        $fields=array_keys($values);$cols=implode(',', $fields);$placeholders=implode(',',array_fill(0,count($fields),'?'));
        $updates=implode(',',array_map(static fn($key)=>$key.'=VALUES('.$key.')',$fields));
        $q=$db->prepare("INSERT INTO garden_crop_growing_profiles (organization_id,variety_id,organization_variety_id,$cols) VALUES (?,?,?,$placeholders) ON DUPLICATE KEY UPDATE $updates");
        $q->execute(array_merge([$org,$global,$private],array_values($values)));
    }

    public function updatePrivateCropTaxonomy(PDO $db,int $org,int $crop,string $family,string $scientific): void
    {
        $family=trim($family);$scientific=trim($scientific);
        if (mb_strlen($family)>120 || mb_strlen($scientific)>200) throw new \InvalidArgumentException('Crop taxonomy is too long.');
        $q=$db->prepare('UPDATE garden_organization_crops SET crop_family=?,scientific_name=? WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$family?:null,$scientific?:null,$crop,$org]);
        $q=$db->prepare('SELECT 1 FROM garden_organization_crops WHERE id=? AND organization_id=? AND archived_at IS NULL');
        $q->execute([$crop,$org]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Active crop not found in your organization.');
    }
}

/** Advisory planning calculations; never automatically creates plantings or changes stock. */
final class SeasonGuidance
{
    public function windows(string $spring,string $fall,int $before,int $after,int $duration): array
    {
        $s=$this->date($spring);$f=$this->date($fall);
        if ($f<=$s) throw new \InvalidArgumentException('Fall frost must follow spring frost.');
        foreach ([$before,$after] as $offset) if ($offset<0 || $offset>180) throw new \InvalidArgumentException('Frost offsets must be 0–180 days.');
        if ($duration<1 || $duration>365) throw new \InvalidArgumentException('Growing duration must be 1–365 days.');
        $indoor=$s->modify('-'.$before.' days');
        $outdoor=$s->modify('+'.$after.' days');
        $harvest=$outdoor->modify('+'.$duration.' days');
        return ['indoor_sow'=>$indoor->format('Y-m-d'),'outdoor_plant'=>$outdoor->format('Y-m-d'),'estimated_maturity'=>$harvest->format('Y-m-d'),'before_fall_frost'=>$harvest<$f];
    }

    public function succession(string $start,int $interval,int $rounds,string $fall=''): array
    {
        $date=$this->date($start);
        if ($interval<1 || $interval>365 || $rounds<2 || $rounds>24) throw new \InvalidArgumentException('Succession requires 2–24 rounds, 1–365 days apart.');
        $limit=$fall===''?null:$this->date($fall);$dates=[];
        for ($i=0;$i<$rounds;$i++) {
            $next=$date->modify('+'.($i*$interval).' days');
            if ($limit!==null && $next>=$limit) break;
            $dates[]=$next->format('Y-m-d');
        }
        return $dates;
    }

    /** Transplant planning anchored on outdoor date; hardening-off is advisory. */
    public function transplant(string $spring,string $fall,int $weeksIndoors,int $daysAfterFrost,int $hardenDays,int $maturityDays): array
    {
        if ($weeksIndoors<1 || $weeksIndoors>20 || $hardenDays<0 || $hardenDays>21) throw new \InvalidArgumentException('Indoor growth must be 1–20 weeks; hardening 0–21 days.');
        $window=$this->windows($spring,$fall,$weeksIndoors*7,$daysAfterFrost,$maturityDays);
        $outdoor=$this->date($window['outdoor_plant']);
        $window['harden_off_start']=$outdoor->modify('-'.$hardenDays.' days')->format('Y-m-d');
        return $window;
    }

    /** Suggest alternative areas without a matching family in the last three seasons. */
    public function rotationSuggestions(array $areas,array $history,string $family,int $year): array
    {
        $family=trim($family);
        if ($family==='' || strcasecmp($family,'Unspecified')===0) return [];
        $recent=[];
        foreach ($history as $row) {
            if (strcasecmp((string)($row['crop_family']??''),$family)!==0) continue;
            $season=(int)($row['season']??0);
            if ($season<=$year && $season>$year-3) $recent[(int)$row['location_id']]=true;
        }
        $result=[];
        foreach ($areas as $area) {
            if (!empty($area['active']) && !isset($recent[(int)$area['id']])) $result[]=$area;
        }
        return $result;
    }

    public function rotationAlerts(array $history,string $family,int $year): array
    {
        $family=trim($family);
        if ($family==='' || strtolower($family)==='unspecified') return [];
        $alerts=[];
        foreach ($history as $record) {
            if (strcasecmp((string)$record['crop_family'],$family)!==0) continue;
            $previous=(int)$record['season'];
            if ($previous<=$year && $previous>$year-3) $alerts[]=['location'=>$record['location_name'],'year'=>$previous,'family'=>$family];
        }
        return $alerts;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Enter a valid frost or planting date.');
        return $date;
    }
}

final class PlantingWindow
{
    /** @return array{start: string, end: string} */
    public static function weeksBeforeFrost(string $frostDate, int $minimumWeeks, int $maximumWeeks): array
    {
        if ($minimumWeeks < 0 || $maximumWeeks < $minimumWeeks || $maximumWeeks > 52) {
            throw new \InvalidArgumentException('Invalid planting offset range');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $frostDate);
        if (!$date || $date->format('Y-m-d') !== $frostDate) {
            throw new \InvalidArgumentException('Invalid frost date');
        }
        return [
            'start' => $date->modify("-{$maximumWeeks} weeks")->format('Y-m-d'),
            'end' => $date->modify("-{$minimumWeeks} weeks")->format('Y-m-d'),
        ];
    }
}
