<?php
/**
 * Unit conversion helpers for recipe display (Original / Metric / US).
 * Reads the pre-computed unit_conversions table (both directions, same-dimension).
 * All functions guarded so they can't fatally re-declare if also defined elsewhere.
 */

if (!function_exists('convertUnit')) {
    /** Convert $amount from one unit to another. Returns null if no path (incompatible dimensions). */
    function convertUnit(PDO $pdo, float $amount, int $from_unit_id, int $to_unit_id): ?float {
        if ($from_unit_id === $to_unit_id) return $amount;
        $stmt = $pdo->prepare(
            "SELECT conversion_factor FROM unit_conversions WHERE from_unit_id = ? AND to_unit_id = ?"
        );
        $stmt->execute([$from_unit_id, $to_unit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $factor = (float)$row['conversion_factor'];
        // Temperature is non-linear; factor stored as 0 is the signal to use the formula.
        if ($factor === 0.0) return convertTemperature($pdo, $amount, $from_unit_id, $to_unit_id);
        return round($amount * $factor, 4);
    }

    /** Non-linear temperature conversion (Fahrenheit <-> Celsius). */
    function convertTemperature(PDO $pdo, float $value, int $from_id, int $to_id): ?float {
        $stmt = $pdo->prepare(
            "SELECT name FROM measurement_units WHERE id IN (?, ?) ORDER BY FIELD(id, ?, ?)"
        );
        $stmt->execute([$from_id, $to_id, $from_id, $to_id]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $from = $names[0] ?? '';
        $to   = $names[1] ?? '';
        if ($from === 'Fahrenheit' && $to === 'Celsius') return round(($value - 32) * 5 / 9, 2);
        if ($from === 'Celsius' && $to === 'Fahrenheit') return round(($value * 9 / 5) + 32, 2);
        return null;
    }
}

if (!function_exists('formatRecipeQty')) {
    /**
     * Friendly number-only formatter: common fractions where close, else a clean decimal.
     * (Unit label is rendered separately, so this returns the NUMBER only.)
     */
    function formatRecipeQty(float $qty): string {
        if (!is_finite($qty)) return '';
        $fractions = [
            0.125 => "\u{215B}", // ⅛
            0.25  => "\u{00BC}", // ¼
            0.333 => "\u{2153}", // ⅓
            0.5   => "\u{00BD}", // ½
            0.667 => "\u{2154}", // ⅔
            0.75  => "\u{00BE}", // ¾
        ];
        $whole = (int)floor($qty);
        $rem   = $qty - $whole;
        foreach ($fractions as $decimal => $symbol) {
            if (abs($rem - $decimal) < 0.02) {
                return ($whole > 0 ? $whole . ' ' : '') . $symbol;
            }
        }
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('bestConvertedUnit')) {
    /**
     * Pick the friendliest same-system target unit for $qty of $fromUnitId.
     * Among the available conversions into $targetSystem, choose by magnitude:
     *   - prefer values >= 1, and among those the smallest value (largest unit)
     *     so 8 cups -> 1.9 L rather than 1893 mL;
     *   - if every candidate is < 1, take the largest value (smallest unit).
     * Skips temperature (factor 0). Returns ['qty','abbr','name'] or null (no path).
     */
    function bestConvertedUnit(PDO $pdo, float $qty, int $fromUnitId, string $targetSystem): ?array {
        $stmt = $pdo->prepare(
            "SELECT uc.conversion_factor, mu.name, mu.abbreviation, mu.system_type
             FROM   unit_conversions uc
             JOIN   measurement_units mu ON uc.to_unit_id = mu.id
             WHERE  uc.from_unit_id = ? AND mu.system_type = ?"
        );
        $stmt->execute([$fromUnitId, $targetSystem]);
        $cands = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$cands) return null;

        $best = null;
        foreach ($cands as $c) {
            $factor = (float)$c['conversion_factor'];
            if ($factor === 0.0) continue;          // temperature / non-linear
            $val = $qty * $factor;
            if ($val <= 0) continue;
            $c['__val'] = $val;
            if ($best === null) { $best = $c; continue; }
            $bv = $best['__val'];
            $cGe = ($val >= 1);
            $bGe = ($bv >= 1);
            if ($cGe && $bGe)        { if ($val < $bv) $best = $c; }   // both readable -> larger unit
            elseif ($cGe && !$bGe)   { $best = $c; }                   // prefer the one >= 1
            elseif (!$cGe && !$bGe)  { if ($val > $bv) $best = $c; }   // both tiny -> closest to 1
        }
        if ($best === null) return null;

        $abbr = (isset($best['abbreviation']) && $best['abbreviation'] !== '' && $best['abbreviation'] !== null)
            ? $best['abbreviation']
            : $best['name'];
        return [
            'qty'  => round($best['__val'], 4),
            'abbr' => $abbr,
            'name' => $best['name'],
        ];
    }
}

if (!function_exists('conversionCandidates')) {
    /**
     * All same-system target units for an ingredient, as [factor, abbr] pairs,
     * with factors relative to the ORIGINAL unit/quantity. Lets the client re-pick
     * the friendliest unit at any scale (e.g. 237 mL at 1x, ~1.19 L at 5x).
     * Includes an identity pair when the source unit is already in $targetSystem,
     * so within-system re-magnitude works too (500 mL -> 2 L when scaled).
     * Skips temperature (factor 0). Returns [] when no conversion applies.
     */
    function conversionCandidates(PDO $pdo, int $fromUnitId, string $fromAbbr, ?string $fromSystem, string $targetSystem): array {
        $out = [];
        if ($fromUnitId && $fromSystem === $targetSystem && $fromAbbr !== '') {
            $out[] = [1.0, $fromAbbr]; // identity: keep the source unit as an option
        }
        if ($fromUnitId) {
            $stmt = $pdo->prepare(
                "SELECT uc.conversion_factor, mu.name, mu.abbreviation
                 FROM   unit_conversions uc
                 JOIN   measurement_units mu ON uc.to_unit_id = mu.id
                 WHERE  uc.from_unit_id = ? AND mu.system_type = ?"
            );
            $stmt->execute([$fromUnitId, $targetSystem]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $factor = (float)$r['conversion_factor'];
                if ($factor === 0.0) continue; // temperature / non-linear
                $abbr = (isset($r['abbreviation']) && $r['abbreviation'] !== '' && $r['abbreviation'] !== null)
                    ? $r['abbreviation'] : $r['name'];
                $out[] = [$factor, $abbr];
            }
        }
        return $out;
    }
}
