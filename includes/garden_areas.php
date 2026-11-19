<?php
// includes/garden_areas.php — Garden (Farm OS, merged Nov 18, 2026 from the standalone garden-crop-manager repo).
// Beds & areas: garden sites, nested growing areas, 2D layout, soil observations, orchard/perennial records and greenhouse readings.
// Classes keep their standalone names and the organization_id scoping (Farm OS uses one garden organization, garden_org()).
// Load through includes/garden.php. Tables: garden_* (migration 0072).
declare(strict_types=1);

namespace Garden\Domain;

use PDO;

final class GardenService
{
    /** @return list<array<string,mixed>> */
    public function sites(PDO $db, int $organizationId): array
    {
        $stmt = $db->prepare('SELECT id, name, timezone, last_spring_frost, first_fall_frost FROM garden_growing_sites WHERE organization_id = ? ORDER BY name, id');
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function locations(PDO $db, int $organizationId, int $siteId): array
    {
        $stmt = $db->prepare('SELECT id, parent_id, name, kind, area_sq_m, length_m, width_m, layout_x_m, layout_y_m, rotation_degrees, orientation, sun_exposure, irrigation, ground_cover, soil_type, notes, color, shape FROM garden_growing_locations WHERE organization_id = ? AND site_id = ? AND active = 1 ORDER BY name, id');
        $stmt->execute([$organizationId, $siteId]);
        return $stmt->fetchAll();
    }

    public function createSite(PDO $db, int $organizationId, string $name, string $timezone): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) { throw new \InvalidArgumentException('Site name must be 1–160 characters.'); }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) { throw new \InvalidArgumentException('Choose a valid timezone.'); }
        $stmt = $db->prepare('INSERT INTO garden_growing_sites (organization_id, name, timezone) VALUES (?, ?, ?)');
        $stmt->execute([$organizationId, $name, $timezone]);
        return (int)$db->lastInsertId();
    }


    public function setFrostDates(PDO $db,int $org,int $site,?string $spring,?string $fall): void
    {
        foreach ([$spring,$fall] as $date) {
            if ($date===null || $date==='') { continue; }
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$parsed || $parsed->format('Y-m-d')!==$date) { throw new \InvalidArgumentException('Enter valid frost dates.'); }
        }
        if ($spring && $fall && $spring >= $fall) { throw new \InvalidArgumentException('Fall frost must be later than spring frost.'); }
        $q=$db->prepare('UPDATE garden_growing_sites SET last_spring_frost=?,first_fall_frost=? WHERE id=? AND organization_id=?');
        $q->execute([$spring?:null,$fall?:null,$site,$org]);
        if ($q->rowCount()===0) {
            $q=$db->prepare('SELECT 1 FROM garden_growing_sites WHERE id=? AND organization_id=?');
            $q->execute([$site,$org]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Garden site not found.'); }
        }
    }

    public function createLocation(PDO $db, int $organizationId, int $siteId, string $name, string $kind, ?string $area): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) { throw new \InvalidArgumentException('Area name must be 1–160 characters.'); }
        $allowed = ['field','plot','row','raised_bed','container','greenhouse','zone','orchard','block','tree_position'];
        if (!in_array($kind, $allowed, true)) { throw new \InvalidArgumentException('Choose a valid growing area type.'); }
        $squareMeters = null;
        if ($area !== null && trim($area) !== '') {
            if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', trim($area)) || (float)$area <= 0) { throw new \InvalidArgumentException('Area must be a positive number.'); }
            $squareMeters = trim($area);
        }
        $stmt = $db->prepare('INSERT INTO garden_growing_locations (organization_id, site_id, kind, name, area_sq_m) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$organizationId, $siteId, $kind, $name, $squareMeters]);
        return (int)$db->lastInsertId();
    }
    public function updateSite(PDO $db, int $organizationId, int $siteId, string $name, string $timezone): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) { throw new \InvalidArgumentException('Site name must be 1–160 characters.'); }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) { throw new \InvalidArgumentException('Choose a valid timezone.'); }
        $stmt = $db->prepare('UPDATE garden_growing_sites SET name = ?, timezone = ? WHERE id = ? AND organization_id = ?');
        $stmt->execute([$name, $timezone, $siteId, $organizationId]);
        return $stmt->rowCount() > 0;
    }

    public function updateLocation(PDO $db, int $organizationId, int $siteId, int $locationId, string $name, string $kind, ?string $area): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) { throw new \InvalidArgumentException('Area name must be 1–160 characters.'); }
        if (!in_array($kind, ['field','plot','row','raised_bed','container','greenhouse','zone','orchard','block','tree_position'], true)) {
            throw new \InvalidArgumentException('Choose a valid growing area type.');
        }
        $squareMeters = null;
        if ($area !== null && trim($area) !== '') {
            if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', trim($area)) || (float)$area <= 0) { throw new \InvalidArgumentException('Area must be a positive number.'); }
            $squareMeters = trim($area);
        }
        $stmt = $db->prepare('UPDATE garden_growing_locations SET name = ?, kind = ?, area_sq_m = ? WHERE id = ? AND site_id = ? AND organization_id = ? AND active = 1');
        $stmt->execute([$name, $kind, $squareMeters, $locationId, $siteId, $organizationId]);
        return $stmt->rowCount() > 0;
    }

    /** Retire an area (Farm OS, Nov 18, 2026): hides it from lists and pickers; its history stays. Refused while it has
     *  planned or active plantings or areas inside it. */
    public function retireLocation(PDO $db, int $organizationId, int $siteId, int $locationId): void
    {
        $q = $db->prepare("SELECT COUNT(*) FROM garden_plantings WHERE location_id = ? AND organization_id = ? AND status IN ('planned','active')");
        $q->execute([$locationId, $organizationId]);
        if ((int)$q->fetchColumn() > 0) { throw new \InvalidArgumentException('Finish or fail its plantings first.'); }
        $q = $db->prepare('SELECT COUNT(*) FROM garden_growing_locations WHERE parent_id = ? AND organization_id = ? AND active = 1');
        $q->execute([$locationId, $organizationId]);
        if ((int)$q->fetchColumn() > 0) { throw new \InvalidArgumentException('Retire or move the beds inside it first.'); }
        $q = $db->prepare('UPDATE garden_growing_locations SET active = 0 WHERE id = ? AND site_id = ? AND organization_id = ? AND active = 1');
        $q->execute([$locationId, $siteId, $organizationId]);
        if ($q->rowCount() === 0) { throw new \InvalidArgumentException('Growing area not found.'); }
    }

    public function addChildLocation(PDO $db, int $organizationId, int $siteId, int $parentId, string $name, string $kind, ?string $area): int
    {
        $stmt = $db->prepare('SELECT id FROM garden_growing_locations WHERE id = ? AND organization_id = ? AND site_id = ? AND active = 1');
        $stmt->execute([$parentId, $organizationId, $siteId]);
        if (!$stmt->fetchColumn()) { throw new \InvalidArgumentException('Choose a parent in the current garden.'); }
        $id = $this->createLocation($db, $organizationId, $siteId, $name, $kind, $area);
        $stmt = $db->prepare('UPDATE garden_growing_locations SET parent_id = ? WHERE id = ? AND organization_id = ? AND site_id = ?');
        $stmt->execute([$parentId, $id, $organizationId, $siteId]);
        return $id;
    }

}

/** Physical footprints and 2D layout for nested garden areas, independent of Farm OS identity. */
final class GrowingAreaLayout
{
    public function save(PDO $db,int $org,int $site,int $location,string $length,string $width,string $x,string $y,string $rotation): void
    {
        $length=trim($length);$width=trim($width);$x=trim($x);$y=trim($y);
        if (($length==='')!==($width==='')) throw new \InvalidArgumentException('Enter both length and width, or leave both empty.');
        if (($x==='')!==($y==='')) throw new \InvalidArgumentException('Enter both map coordinates, or leave both empty.');
        foreach ([$length,$width] as $v) if ($v!=='' && (!preg_match('/^\d{1,7}(?:\.\d{1,3})?$/',$v) || (float)$v<=0)) throw new \InvalidArgumentException('Length and width must be positive numbers.');
        foreach ([$x,$y] as $v) if ($v!=='' && !preg_match('/^-?\d{1,7}(?:\.\d{1,3})?$/',$v)) throw new \InvalidArgumentException('Map position must be a number.');
        if (!preg_match('/^(?:0|[1-9]\d{0,2})$/',$rotation) || (int)$rotation>359) throw new \InvalidArgumentException('Rotation must be 0–359 degrees.');
        $q=$db->prepare('SELECT id,parent_id FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=? AND active=1');
        $q->execute([$location,$org,$site]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Growing area not found in this garden.');
        if ($row['parent_id']!==null && $x!=='' && $length!=='') {
            $q=$db->prepare('SELECT length_m,width_m FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=? AND active=1');
            $q->execute([$row['parent_id'],$org,$site]);$parent=$q->fetch(PDO::FETCH_ASSOC);
            if ($parent && $parent['length_m']!==null && $parent['width_m']!==null) {
                // Compute rotated rectangle extents around its center.
                $r=deg2rad((int)$rotation);
                $spanX=abs((float)$length*cos($r))+abs((float)$width*sin($r));
                $spanY=abs((float)$length*sin($r))+abs((float)$width*cos($r));
                $centerX=(float)$x+(float)$length/2;
                $centerY=(float)$y+(float)$width/2;
                if ($centerX-$spanX/2 < -0.000001 || $centerY-$spanY/2 < -0.000001
                    || $centerX+$spanX/2 > (float)$parent['length_m']+0.000001
                    || $centerY+$spanY/2 > (float)$parent['width_m']+0.000001) {
                    throw new \InvalidArgumentException('That bed would hang outside the area it sits in. Check its size and map position.');
                }
            }
        }
        $q=$db->prepare('UPDATE garden_growing_locations SET length_m=?,width_m=?,layout_x_m=?,layout_y_m=?,rotation_degrees=? WHERE id=? AND organization_id=? AND site_id=? AND active=1');
        $q->execute([$length===''?null:$length,$width===''?null:$width,$x===''?null:$x,$y===''?null:$y,(int)$rotation,$location,$org,$site]);
    }

    /** A read-only schematic, using the saved metric coordinates. */
    public function groups(array $locations): array
    {
        $groups=[];
        foreach ($locations as $location) {
            $key=$location['parent_id']===null?'site':(string)$location['parent_id'];
            $groups[$key][]=$location;
        }
        return $groups;
    }
}

final class SoilPerennials
{
    private function location(PDO $db,int $org,int $site,int $location): void
    {
        $q=$db->prepare('SELECT 1 FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=?');
        $q->execute([$location,$org,$site]);
        if (!$q->fetchColumn()) throw new \InvalidArgumentException('Growing area not found in this garden.');
    }
    private function date(string $date): void
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$d || $d->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Enter a valid observation date.');
    }
    public function soil(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT s.id,s.location_id,l.name AS location_name,s.observed_on,s.ph,s.notes FROM garden_soil_observations s JOIN garden_growing_locations l ON l.id=s.location_id AND l.organization_id=s.organization_id WHERE s.organization_id=? AND l.site_id=? ORDER BY s.observed_on DESC,s.id DESC');
        $q->execute([$org,$site]);return $q->fetchAll();
    }
    public function addSoil(PDO $db,int $org,int $site,int $location,string $date,string $ph,string $notes): int
    {
        $this->location($db,$org,$site,$location);$this->date($date);$notes=trim($notes);
        if (mb_strlen($notes)>2000) throw new \InvalidArgumentException('Notes must be 2000 characters or fewer.');
        if ($ph!=='' && (!preg_match('/^(?:[0-9]|1[0-4])(?:\.[0-9])?$/',$ph) || (float)$ph>14)) throw new \InvalidArgumentException('pH must be between 0 and 14, with one decimal.');
        if ($ph==='' && $notes==='') throw new \InvalidArgumentException('Enter pH or observation notes.');
        $q=$db->prepare('INSERT INTO garden_soil_observations (organization_id,location_id,observed_on,ph,notes) VALUES (?,?,?,?,?)');
        $q->execute([$org,$location,$date,$ph===''?null:$ph,$notes?:null]);return (int)$db->lastInsertId();
    }
    public function perennials(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT r.id,r.planting_id,r.label,r.planted_year,r.rootstock,r.notes,l.name AS location_name,COALESCE(oc.name,c.common_name) AS crop_name,COALESCE(ov.name,v.name) AS variety_name FROM garden_perennial_records r JOIN garden_plantings p ON p.id=r.planting_id AND p.organization_id=r.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE r.organization_id=? AND l.site_id=? ORDER BY l.name,r.label,r.id');
        $q->execute([$org,$site]);return $q->fetchAll();
    }
    public function addPerennial(PDO $db,int $org,int $site,int $planting,string $label,string $year,string $rootstock,string $notes): int
    {
        $q=$db->prepare("SELECT p.method,COALESCE(c.lifecycle,oc.lifecycle) AS lifecycle FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?");
        $q->execute([$planting,$org,$site]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new \InvalidArgumentException('Planting not found in this garden.');
        if ($p['method']!=='perennial_installation' && $p['lifecycle']!=='perennial') throw new \InvalidArgumentException('Choose a perennial planting.');
        $label=trim($label);$rootstock=trim($rootstock);$notes=trim($notes);
        if ($label==='' || mb_strlen($label)>120 || mb_strlen($rootstock)>120 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Check perennial label, rootstock and notes lengths.');
        if ($year!=='' && (!preg_match('/^(?:1[89]\d\d|20\d\d|21\d\d)$/',$year))) throw new \InvalidArgumentException('Enter a year between 1800 and 2199.');
        $q=$db->prepare('INSERT INTO garden_perennial_records (organization_id,planting_id,label,planted_year,rootstock,notes) VALUES (?,?,?,?,?,?)');
        $q->execute([$org,$planting,$label,$year===''?null:(int)$year,$rootstock?:null,$notes?:null]);return (int)$db->lastInsertId();
    }
}

final class SpecializedGrowing
{
    private function date(string $value): void
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$d || $d->format('Y-m-d')!==$value) throw new \InvalidArgumentException('Enter a valid observation date.');
    }
    public function greenhouseReadings(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare("SELECT r.*,l.name AS location_name FROM garden_greenhouse_readings r JOIN garden_growing_locations l ON l.id=r.location_id AND l.organization_id=r.organization_id WHERE r.organization_id=? AND l.site_id=? AND l.kind='greenhouse' ORDER BY r.observed_on DESC,r.id DESC LIMIT 100");
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function recordGreenhouse(PDO $db,int $org,int $site,int $location,string $date,string $temperature,string $humidity,string $notes): int
    {
        $q=$db->prepare("SELECT 1 FROM garden_growing_locations WHERE id=? AND organization_id=? AND site_id=? AND kind='greenhouse' AND active=1");
        $q->execute([$location,$org,$site]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Choose a greenhouse in this garden.');
        $this->date($date);$temperature=trim($temperature);$humidity=trim($humidity);$notes=trim($notes);
        if (mb_strlen($notes)>2000) throw new \InvalidArgumentException('Notes must be 2000 characters or fewer.');
        foreach ([$temperature,$humidity] as $v) if ($v!=='' && !preg_match('/^-?\d{1,2}(?:\.\d)?$/D',$v)) throw new \InvalidArgumentException('Enter readings to one decimal place.');
        if (($temperature!=='' && ((float)$temperature< -80 || (float)$temperature>80)) || ($humidity!=='' && ((float)$humidity<0 || (float)$humidity>100))) throw new \InvalidArgumentException('Temperature or humidity outside valid range.');
        if ($temperature==='' && $humidity==='' && $notes==='') throw new \InvalidArgumentException('Enter a reading or observation.');
        $q=$db->prepare('INSERT INTO garden_greenhouse_readings (organization_id,location_id,observed_on,temperature_c,humidity_pct,notes) VALUES (?,?,?,?,?,?)');
        $q->execute([$org,$location,$date,$temperature===''?null:$temperature,$humidity===''?null:$humidity,$notes?:null]);return (int)$db->lastInsertId();
    }
    public function perennialObservations(PDO $db,int $org,int $site): array
    {
        $q=$db->prepare('SELECT o.*,r.label AS perennial_label,l.name AS location_name FROM garden_perennial_observations o JOIN garden_perennial_records r ON r.id=o.perennial_id AND r.organization_id=o.organization_id JOIN garden_plantings p ON p.id=r.planting_id AND p.organization_id=r.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE o.organization_id=? AND l.site_id=? ORDER BY o.observed_on DESC,o.id DESC LIMIT 100');
        $q->execute([$org,$site]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function recordPerennialStage(PDO $db,int $org,int $site,int $perennial,string $date,string $stage,string $notes): int
    {
        if (!in_array($stage,['dormant','bud_break','flowering','fruit_set','fruit_development','harvest_ready','leaf_drop','other'],true)) throw new \InvalidArgumentException('Choose a valid perennial growth stage.');
        $q=$db->prepare('SELECT 1 FROM garden_perennial_records r JOIN garden_plantings p ON p.id=r.planting_id AND p.organization_id=r.organization_id JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE r.id=? AND r.organization_id=? AND l.site_id=?');
        $q->execute([$perennial,$org,$site]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Perennial not found in this garden.');
        $this->date($date);$notes=trim($notes);if (mb_strlen($notes)>2000) throw new \InvalidArgumentException('Notes must be 2000 characters or fewer.');
        $q=$db->prepare('INSERT INTO garden_perennial_observations (organization_id,perennial_id,observed_on,stage,notes) VALUES (?,?,?,?,?)');
        $q->execute([$org,$perennial,$date,$stage,$notes?:null]);return (int)$db->lastInsertId();
    }
}
