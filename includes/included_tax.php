<?php // includes/included_tax.php
/*
 * Sales tax included in the posted price of online tickets and annual passes (Q-09, Nov 13, 2026).
 * Admission is taxable at the general rate; Zac chose to keep posted prices, so the customer pays the same and the
 * tax is the part of the price above price ÷ (1 + rate). Needs migration 0060; before it runs, nothing is recorded.
 *
 *   bcrf_itax_part($grossCents, $bp)       tax inside a tax-included amount (rounded half-up on the pre-tax part)
 *   bcrf_itax_rate($pdo, $taxCategoryId)   rate of a tax category, or the general rate
 *   bcrf_itax_record_ticket($pdo, $id)     record tax on a paid online ticket order (per line, by the product's tax category)
 *   bcrf_itax_record_pass($pdo, $id)       record tax on a paid pass (general rate; comp passes 0)
 *   bcrf_itax_rows($pdo, $utcFrom, $utcTo) for the monthly tax report: one row each for online tickets and passes
 * Recording never throws: a failure is logged and the payment carries on.
 */

if (!function_exists('bcrf_itax_part')) {

function bcrf_itax_ready(PDO $pdo, string $table): bool {
    static $ready = [];
    if (isset($ready[$table])) return $ready[$table];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tax_cents'");
        $st->execute([$table]);
        return $ready[$table] = (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) { return $ready[$table] = false; }
}

function bcrf_itax_part(int $grossCents, int $bp): int {
    if ($grossCents <= 0 || $bp <= 0) return 0;
    $d = 10000 + $bp;
    $net = intdiv(2 * $grossCents * 10000 + $d, 2 * $d);
    return $grossCents - $net;
}

function bcrf_itax_rate(PDO $pdo, ?int $taxCategoryId = null): int {
    try {
        if ($taxCategoryId) {
            $st = $pdo->prepare("SELECT rate_bp FROM tax_categories WHERE id = ? AND archived_at IS NULL");
            $st->execute([$taxCategoryId]);
            $r = $st->fetchColumn();
            if ($r !== false) return (int)$r;
        }
        $r = $pdo->query("SELECT rate_bp FROM tax_categories WHERE code = 'general' AND archived_at IS NULL LIMIT 1")->fetchColumn();
        return $r === false ? 0 : (int)$r;
    } catch (Throwable $e) { return 0; }
}

function bcrf_itax_record_ticket(PDO $pdo, int $id): void {
    try {
        if (!bcrf_itax_ready($pdo, 'tickets')) return;
        $st = $pdo->prepare("SELECT order_json, amount_cents FROM tickets WHERE id = ? AND tax_cents IS NULL");
        $st->execute([$id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) return;
        $general = bcrf_itax_rate($pdo);
        $cat = $pdo->prepare("SELECT tax_category_id FROM inventory WHERE id = ?");
        $tax = 0; $rate = 0; $lines = 0;
        foreach ((array)json_decode((string)$t['order_json'], true) as $o) {
            $gross = (int)($o['unit_cents'] ?? 0) * (int)($o['qty'] ?? 0);
            if ($gross <= 0) continue;
            $cat->execute([(int)($o['product_id'] ?? 0)]);
            $c = $cat->fetchColumn();
            $bp = $c ? bcrf_itax_rate($pdo, (int)$c) : $general;
            $tax += bcrf_itax_part($gross, $bp);
            $rate = max($rate, $bp); $lines += $gross;
        }
        if ($lines !== (int)$t['amount_cents']) {   // order lines don't add up: tax the whole amount at the general rate
            $tax = bcrf_itax_part((int)$t['amount_cents'], $general); $rate = $general;
        }
        $pdo->prepare("UPDATE tickets SET tax_rate_bp = ?, tax_cents = ? WHERE id = ? AND tax_cents IS NULL")->execute([$rate, $tax, $id]);
    } catch (Throwable $e) { error_log('[included_tax] ticket ' . $id . ': ' . $e->getMessage()); }
}

function bcrf_itax_record_pass(PDO $pdo, int $id): void {
    try {
        if (!bcrf_itax_ready($pdo, 'passes')) return;
        $st = $pdo->prepare("SELECT amount_paid, payment_provider FROM passes WHERE id = ? AND payment_status = 'paid' AND tax_cents IS NULL");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return;
        $comp = $p['payment_provider'] === 'comp';
        $bp = $comp ? 0 : bcrf_itax_rate($pdo);
        $tax = bcrf_itax_part((int)round((float)$p['amount_paid'] * 100), $bp);
        $pdo->prepare("UPDATE passes SET tax_rate_bp = ?, tax_cents = ?, paid_at = COALESCE(paid_at, UTC_TIMESTAMP()) WHERE id = ? AND tax_cents IS NULL")
            ->execute([$bp, $tax, $id]);
    } catch (Throwable $e) { error_log('[included_tax] pass ' . $id . ': ' . $e->getMessage()); }
}

/** Monthly tax report rows: [label, rate_bp, orders, gross, net, tax] for paid orders whose tax was recorded, UTC range [from, to). */
function bcrf_itax_rows(PDO $pdo, string $utcFrom, string $utcTo): array {
    $out = [];
    $q = [
        'Online tickets (tax included)' => ['tickets', "SELECT COUNT(*) n, COALESCE(SUM(amount_cents), 0) g, COALESCE(SUM(tax_cents), 0) t, MAX(tax_rate_bp) r
                                                        FROM tickets WHERE source = 'online' AND payment_status = 'paid' AND tax_cents IS NOT NULL AND paid_at >= ? AND paid_at < ?"],
        'Annual passes (tax included)'  => ['passes',  "SELECT COUNT(*) n, COALESCE(SUM(ROUND(amount_paid * 100)), 0) g, COALESCE(SUM(tax_cents), 0) t, MAX(tax_rate_bp) r
                                                        FROM passes WHERE payment_status = 'paid' AND tax_cents IS NOT NULL AND tax_rate_bp > 0 AND paid_at >= ? AND paid_at < ?"],
    ];
    foreach ($q as $label => [$table, $sql]) {
        if (!bcrf_itax_ready($pdo, $table)) continue;
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$utcFrom, $utcTo]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || !(int)$r['n']) continue;
            $out[] = ['label' => $label, 'rate_bp' => $r['r'] === null ? null : (int)$r['r'], 'orders' => (int)$r['n'],
                      'gross' => (int)$r['g'], 'net' => (int)$r['g'] - (int)$r['t'], 'tax' => (int)$r['t']];
        } catch (Throwable $e) { error_log('[included_tax] report: ' . $e->getMessage()); }
    }
    return $out;
}

}
