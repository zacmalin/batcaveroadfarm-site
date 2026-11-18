<?php
// includes/garden_seeds.php — Garden (Farm OS, merged Nov 18, 2026 from the standalone garden-crop-manager repo).
// Seeds and plantings records: seed lots, the seed stock ledger, germination tests, planting records.
// Classes keep their standalone names and the organization_id scoping (Farm OS uses one garden organization, garden_org()).
// Load through includes/garden.php. Tables: garden_* (migration 0072).
declare(strict_types=1);

namespace Garden\Domain;

use PDO;

final class GrowingRecords
{
    /** @return list<array<string,mixed>> */
    public function seedLots(PDO $db, int $org): array
    {
        $q = $db->prepare('SELECT s.id, s.variety_id, s.organization_variety_id, s.lot_code, s.source, s.vendor_id, s.acquired_on, s.quantity, s.unit, COALESCE(ov.name,v.name) AS variety_name, COALESCE(oc.name,c.common_name) AS crop_name FROM garden_seed_lots s LEFT JOIN garden_varieties v ON v.id=s.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=s.organization_variety_id AND ov.organization_id=s.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=s.organization_id WHERE s.organization_id=? ORDER BY c.common_name, v.name, s.id');
        $q->execute([$org]);
        return $q->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function plantings(PDO $db, int $org, int $site): array
    {
        $q = $db->prepare('SELECT p.id, p.location_id, p.variety_id, p.organization_variety_id, p.seed_lot_id, p.planted_on, p.method, p.quantity, p.quantity_unit, p.status, p.allocated_area_sq_m, l.name AS location_name, COALESCE(ov.name,v.name) AS variety_name, COALESCE(oc.name,c.common_name) AS crop_name, COALESCE(c.crop_family,oc.crop_family) AS crop_family FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id LEFT JOIN garden_varieties v ON v.id=p.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=p.organization_variety_id AND ov.organization_id=p.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=p.organization_id WHERE p.organization_id=? AND l.site_id=? ORDER BY p.planted_on DESC, p.id DESC');
        $q->execute([$org, $site]);
        return $q->fetchAll();
    }

    public function createSeedLot(PDO $db, int $org, int|string $variety, string $code, string $source, ?string $date, ?string $quantity, string $unit): int
    {
        [$shared,$custom] = $this->resolveVariety($db,$org,$variety);
        $code = trim($code); $source = trim($source); $unit = trim($unit);
        if (mb_strlen($code)>120 || mb_strlen($source)>160 || mb_strlen($unit)>32) { throw new \InvalidArgumentException('Seed lot field is too long.'); }
        $date = $this->date($date, false);
        $amount = $this->amount($quantity);
        if (($amount === null) !== ($unit === '')) { throw new \InvalidArgumentException('Provide both quantity and unit, or neither.'); }
        $ownedTransaction=$db->inTransaction();
        if (!$ownedTransaction) { $db->beginTransaction(); }
        try {
            $q = $db->prepare('INSERT INTO garden_seed_lots (organization_id, variety_id, organization_variety_id, lot_code, source, acquired_on, quantity, unit) VALUES (?,?,?,?,?,?,?,?)');
            $q->execute([$org,$shared,$custom,$code ?: null,$source ?: null,$date,$amount,$unit ?: null]);
            $id=(int)$db->lastInsertId();
            if ($amount !== null) {
                $q=$db->prepare("INSERT INTO garden_seed_stock_movements (organization_id,seed_lot_id,kind,delta,occurred_on,notes) VALUES (?,?,'opening',?,?,?)");
                $q->execute([$org,$id,$amount,$date ?? date('Y-m-d'),'Initial seed lot balance']);
            }
            if (!$ownedTransaction) { $db->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if (!$ownedTransaction && $db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public function createPlanting(PDO $db, int $org, int $site, int $location, int|string $variety, ?int $lot, string $date, string $method, ?string $quantity, string $unit, string $status='active'): int
    {
        if ($site<1 || $location<1) { throw new \InvalidArgumentException('Choose a garden and growing area.'); }
        [$shared,$custom] = $this->resolveVariety($db,$org,$variety);
        if (!in_array($method, ['direct_sow','transplant','perennial_installation'], true)) { throw new \InvalidArgumentException('Choose a planting method.'); }
        if (!in_array($status,['planned','active'],true)) { throw new \InvalidArgumentException('Choose planned or active planting.'); }
        $date = $this->date($date, true);
        $amount = $this->amount($quantity);
        $unit = trim($unit);
        if (mb_strlen($unit)>32 || (($amount === null) !== ($unit === ''))) { throw new \InvalidArgumentException('Provide both planting quantity and unit, or neither.'); }
        $q = $db->prepare('SELECT 1 FROM garden_growing_locations WHERE id=? AND site_id=? AND organization_id=? AND active=1');
        $q->execute([$location,$site,$org]);
        if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Choose an active growing area in this garden.'); }
        if ($lot !== null) {
            $q = $db->prepare('SELECT 1 FROM garden_seed_lots WHERE id=? AND organization_id=? AND variety_id <=> ? AND organization_variety_id <=> ?');
            $q->execute([$lot,$org,$shared,$custom]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Seed lot must belong to this organization and match the variety.'); }
        }
        $q = $db->prepare('INSERT INTO garden_plantings (organization_id,location_id,variety_id,organization_variety_id,seed_lot_id,planted_on,method,quantity,quantity_unit,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$org,$location,$shared,$custom,$lot,$date,$method,$amount,$unit ?: null,$status]);
        return (int)$db->lastInsertId();
    }


    public function setPlantingStatus(PDO $db,int $org,int $site,int $planting,string $status): void
    {
        if (!in_array($status,['planned','active','completed','failed'],true)) {
            throw new \InvalidArgumentException('Choose a valid planting status.');
        }
        $q=$db->prepare('UPDATE garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id SET p.status=? WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
        $q->execute([$status,$planting,$org,$site]);
        if ($q->rowCount()===0) {
            $q=$db->prepare('SELECT 1 FROM garden_plantings p JOIN garden_growing_locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.id=? AND p.organization_id=? AND l.site_id=?');
            $q->execute([$planting,$org,$site]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Planting not found in this garden.'); }
        }
    }

    /** @return array{?int,?int} */
    private function resolveVariety(PDO $db,int $org,int|string $variety): array
    {
        $value=(string)$variety;
        if (preg_match('/^custom:([1-9][0-9]*)$/',$value,$matches)) {
            $id=(int)$matches[1];
            $q=$db->prepare('SELECT 1 FROM garden_organization_varieties v JOIN garden_organization_crops c ON c.id=v.crop_id AND c.organization_id=v.organization_id WHERE v.id=? AND v.organization_id=? AND v.archived_at IS NULL AND c.archived_at IS NULL');
            $q->execute([$id,$org]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Custom variety not found in your organization.'); }
            return [null,$id];
        }
        if (!preg_match('/^[1-9][0-9]*$/',$value)) { throw new \InvalidArgumentException('Choose a valid variety.'); }
        $id=(int)$value;
        $q=$db->prepare('SELECT 1 FROM garden_varieties WHERE id=?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Reference variety not found.'); }
        return [$id,null];
    }

    private function amount(?string $value): ?string
    {
        if ($value === null || trim($value) === '') { return null; }
        $value = trim($value);
        if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $value) || (float)$value<=0) {
            throw new \InvalidArgumentException('Quantity must be a positive number with up to three decimals.');
        }
        return $value;
    }

    private function date(?string $value, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) { throw new \InvalidArgumentException('Enter a planting date.'); }
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Enter a valid date.'); }
        return $value;
    }
}

final class SeedStock
{
    public function movements(PDO $db,int $org,int $lot): array
    {
        $q=$db->prepare('SELECT id,kind,delta,occurred_on,notes,actor_user_id,reverses_movement_id FROM garden_seed_stock_movements WHERE organization_id=? AND seed_lot_id=? ORDER BY occurred_on DESC,id DESC');
        $q->execute([$org,$lot]);return $q->fetchAll();
    }

    public function balances(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT s.id,s.unit,s.quantity AS original_quantity,COALESCE(SUM(m.delta),0) AS balance,COUNT(m.id) AS movement_count FROM garden_seed_lots s LEFT JOIN garden_seed_stock_movements m ON m.seed_lot_id=s.id AND m.organization_id=s.organization_id WHERE s.organization_id=? GROUP BY s.id,s.unit,s.quantity ORDER BY s.id');
        $q->execute([$org]);return $q->fetchAll();
    }


    public function startTracking(PDO $db,int $org,int $lot,string $quantity,string $unit,string $date,int $actor): int
    {
        $quantity=trim($quantity); $unit=trim($unit);
        if ($org<1 || $lot<1 || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$quantity) || (float)$quantity<=0) {
            throw new \InvalidArgumentException('Enter a positive opening quantity (up to three decimal places).');
        }
        if ($unit==='' || mb_strlen($unit)>32) { throw new \InvalidArgumentException('Enter a unit (32 characters maximum).'); }
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) { throw new \InvalidArgumentException('Enter a valid opening date.'); }
        $this->validateActor($db,$org,$actor);
        $outer=$db->inTransaction();
        if (!$outer) { $db->beginTransaction(); }
        try {
            $q=$db->prepare('SELECT quantity,unit FROM garden_seed_lots WHERE id=? AND organization_id=? FOR UPDATE');
            $q->execute([$lot,$org]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new \InvalidArgumentException('Seed lot not found in your organization.'); }
            if ($row['quantity']!==null || $row['unit']!==null) {
                throw new \InvalidArgumentException('Inventory tracking has already been started for this seed lot.');
            }
            $q=$db->prepare('SELECT COUNT(*) FROM garden_seed_stock_movements WHERE organization_id=? AND seed_lot_id=?');
            $q->execute([$org,$lot]);
            if ((int)$q->fetchColumn()!==0) { throw new \InvalidArgumentException('Seed lot already has stock history.'); }
            $q=$db->prepare('UPDATE garden_seed_lots SET quantity=?,unit=? WHERE id=? AND organization_id=?');
            $q->execute([$quantity,$unit,$lot,$org]);
            $q=$db->prepare("INSERT INTO garden_seed_stock_movements (organization_id,seed_lot_id,kind,delta,occurred_on,notes,actor_user_id) VALUES (?,?,'opening',?,?,?,?)");
            $q->execute([$org,$lot,$quantity,$date,'Inventory tracking started',$actor]);
            $id=(int)$db->lastInsertId();
            if (!$outer) { $db->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if (!$outer && $db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    public function record(PDO $db,int $org,int $lot,string $kind,string $amount,string $date,string $notes,?int $actor=null): int
    {
        if ($org<1 || $lot<1) { throw new \InvalidArgumentException('Choose a seed lot.'); }
        if (!in_array($kind,['received','used','adjustment'],true)) { throw new \InvalidArgumentException('Choose a valid stock movement.'); }
        $amount=trim($amount);
        if (!preg_match('/^-?\d{1,9}(?:\.\d{1,3})?$/',$amount) || (float)$amount===0.0) {
            throw new \InvalidArgumentException('Enter a nonzero quantity with up to three decimal places.');
        }
        if (($kind==='received' && (float)$amount<=0) || ($kind==='used' && (float)$amount>=0)) {
            throw new \InvalidArgumentException('Received quantities must be positive; used quantities must be negative.');
        }
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) { throw new \InvalidArgumentException('Enter a valid movement date.'); }
        if ($actor !== null) { $this->validateActor($db,$org,$actor); }
        $notes=trim($notes);
        if (mb_strlen($notes)>500) { throw new \InvalidArgumentException('Notes must be 500 characters or fewer.'); }

        $ownedTransaction=$db->inTransaction();
        if (!$ownedTransaction) { $db->beginTransaction(); }
        try {
            // Serialize all writes for a lot using the parent row, including when no movements exist yet.
            $q=$db->prepare('SELECT unit FROM garden_seed_lots WHERE id=? AND organization_id=? FOR UPDATE');
            $q->execute([$lot,$org]);
            $unit=$q->fetchColumn();
            if ($unit===false || $unit===null || $unit==='') { throw new \InvalidArgumentException('Choose a seed lot with a tracked quantity and unit.'); }
            $q=$db->prepare('SELECT COALESCE(SUM(delta),0) FROM garden_seed_stock_movements WHERE organization_id=? AND seed_lot_id=?');
            $q->execute([$org,$lot]);
            $current=(float)$q->fetchColumn();
            if (round($current+(float)$amount,3)<0) { throw new \InvalidArgumentException('Not enough seed stock for this movement.'); }
            $q=$db->prepare('INSERT INTO garden_seed_stock_movements (organization_id,seed_lot_id,kind,delta,occurred_on,notes,actor_user_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$org,$lot,$kind,$amount,$date,$notes ?: null,$actor]);
            $id=(int)$db->lastInsertId();
            if (!$ownedTransaction) { $db->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if (!$ownedTransaction && $db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }
    public function reverse(PDO $db,int $org,int $lot,int $movement,int $actor,string $date,string $reason): int
    {
        if ($org<1 || $lot<1 || $movement<1) { throw new \InvalidArgumentException('Choose a valid stock movement.'); }
        $this->validateActor($db,$org,$actor);
        $reason=trim($reason);
        if ($reason==='' || mb_strlen($reason)>500) { throw new \InvalidArgumentException('Provide a reversal reason (500 characters maximum).'); }
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) { throw new \InvalidArgumentException('Enter a valid reversal date.'); }
        $ownedTransaction=$db->inTransaction();
        if (!$ownedTransaction) { $db->beginTransaction(); }
        try {
            $q=$db->prepare('SELECT id FROM garden_seed_lots WHERE id=? AND organization_id=? FOR UPDATE');
            $q->execute([$lot,$org]);
            if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Seed lot not found.'); }
            $q=$db->prepare('SELECT delta,kind FROM garden_seed_stock_movements WHERE id=? AND organization_id=? AND seed_lot_id=?');
            $q->execute([$movement,$org,$lot]);
            $original=$q->fetch(PDO::FETCH_ASSOC);
            if (!$original || $original['kind']==='opening' || $original['kind']==='reversal') {
                throw new \InvalidArgumentException('Only original stock changes can be reversed.');
            }
            $q=$db->prepare('SELECT 1 FROM garden_seed_stock_movements WHERE reverses_movement_id=?');
            $q->execute([$movement]);
            if ($q->fetchColumn()) { throw new \InvalidArgumentException('This movement has already been reversed.'); }
            $q=$db->prepare('SELECT COALESCE(SUM(delta),0) FROM garden_seed_stock_movements WHERE organization_id=? AND seed_lot_id=?');
            $q->execute([$org,$lot]);
            $current=(float)$q->fetchColumn();
            $reverse=number_format(-(float)$original['delta'],3,'.','');
            if (round($current+(float)$reverse,3)<0) { throw new \InvalidArgumentException('Reversal would make remaining stock negative.'); }
            $q=$db->prepare("INSERT INTO garden_seed_stock_movements (organization_id,seed_lot_id,kind,delta,occurred_on,notes,actor_user_id,reverses_movement_id) VALUES (?,?,'reversal',?,?,?,?,?)");
            $q->execute([$org,$lot,$reverse,$date,$reason,$actor,$movement]);
            $id=(int)$db->lastInsertId();
            if (!$ownedTransaction) { $db->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if (!$ownedTransaction && $db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    private function validateActor(PDO $db,int $org,int $actor): void
    {
        if ($actor<1) { throw new \InvalidArgumentException('A valid staff member is required.'); }
        // Farm OS: who may change seed stock is decided by the page (Permissions › Garden); here the actor only has to be a Farm OS user.
        $q=$db->prepare('SELECT 1 FROM users WHERE id=?');
        $q->execute([$actor]);
        if (!$q->fetchColumn()) { throw new \InvalidArgumentException('Sign in again; your staff account was not found.'); }
    }
}

/** Seed-lot germination samples, kept separate from stock movements. */
final class SeedGermination
{
    public function tests(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT t.id,t.seed_lot_id,t.started_on,t.checked_on,t.seeds_tested,t.seeds_germinated,t.method,t.notes,ROUND(100*t.seeds_germinated/t.seeds_tested,1) AS germination_percent,COALESCE(ov.name,v.name) AS variety_name,COALESCE(oc.name,c.common_name) AS crop_name,s.lot_code FROM garden_seed_germination_tests t JOIN garden_seed_lots s ON s.id=t.seed_lot_id AND s.organization_id=t.organization_id LEFT JOIN garden_varieties v ON v.id=s.variety_id LEFT JOIN garden_crops c ON c.id=v.crop_id LEFT JOIN garden_organization_varieties ov ON ov.id=s.organization_variety_id AND ov.organization_id=s.organization_id LEFT JOIN garden_organization_crops oc ON oc.id=ov.crop_id AND oc.organization_id=s.organization_id WHERE t.organization_id=? ORDER BY t.checked_on DESC,t.id DESC');
        $q->execute([$org]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary(PDO $db,int $org): array
    {
        $q=$db->prepare('SELECT seed_lot_id,COUNT(*) AS tests,SUM(seeds_tested) AS total_tested,SUM(seeds_germinated) AS total_germinated,ROUND(100*SUM(seeds_germinated)/SUM(seeds_tested),1) AS germination_percent,MAX(checked_on) AS latest_check FROM garden_seed_germination_tests WHERE organization_id=? GROUP BY seed_lot_id ORDER BY seed_lot_id');
        $q->execute([$org]);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function record(PDO $db,int $org,int $lot,string $started,string $checked,string $tested,string $germinated,string $method,string $notes): int
    {
        foreach ([$started,$checked] as $date) {
            $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$day || $day->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Enter valid germination dates.');
        }
        if ($checked<$started) throw new \InvalidArgumentException('Check date cannot precede test start.');
        if (!ctype_digit($tested)||!ctype_digit($germinated)||(int)$tested<1||(int)$tested>65535||(int)$germinated>(int)$tested) throw new \InvalidArgumentException('Germinated count must be between zero and the number tested.');
        $method=trim($method);$notes=trim($notes);
        if (mb_strlen($method)>120 || mb_strlen($notes)>2000) throw new \InvalidArgumentException('Germination notes or method are too long.');
        $q=$db->prepare('SELECT 1 FROM garden_seed_lots WHERE id=? AND organization_id=?');
        $q->execute([$lot,$org]);if (!$q->fetchColumn()) throw new \InvalidArgumentException('Seed lot not found in your organization.');
        $q=$db->prepare('INSERT INTO garden_seed_germination_tests (organization_id,seed_lot_id,started_on,checked_on,seeds_tested,seeds_germinated,method,notes) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$org,$lot,$started,$checked,(int)$tested,(int)$germinated,$method?:null,$notes?:null]);
        return (int)$db->lastInsertId();
    }
}
