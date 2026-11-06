<?php // includes/nc_warning.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
/*
 * Required North Carolina liability warnings (compliance review, Nov 2026).
 *
 * N.C.G.S. 99E-32 (agritourism) and 99E-8 (farm animal activity) require these
 * exact notices on posted signs AND "in clearly readable print" in every written
 * contract with a participant. Online tickets, passes and bookings are shown
 * them before checkout; the Terms page shows them too.
 *
 * The wording is the statute's — do not paraphrase or shorten it.
 * Source: ncleg.gov, Chapter 99E, Articles 1 and 4.
 */

if (!function_exists('bcrf_nc_warning_html')) {
    /** The two statutory notices: [agritourism, farm animal]. */
    function bcrf_nc_warning_texts(): array {
        $agri = 'Under North Carolina law, there is no liability for an injury to or death of a participant in an '
              . 'agritourism activity conducted at this agritourism location if such injury or death results from the '
              . 'inherent risks of the agritourism activity. Inherent risks of agritourism activities include, among '
              . 'others, risks of injury inherent to land, equipment, and animals, as well as the potential for you to '
              . 'act in a negligent manner that may contribute to your injury or death. You are assuming the risk of '
              . 'participating in this agritourism activity.';
        $farm = 'Under North Carolina law, a farm animal activity sponsor or farm animal professional is not liable '
              . 'for an injury to or the death of a participant in farm animal activities resulting exclusively from '
              . 'the inherent risks of farm animal activities. Chapter 99E of the North Carolina General Statutes.';
        return [$agri, $farm];
    }

    function bcrf_nc_warning_html(bool $compact = false): string {
        [$agri, $farm] = bcrf_nc_warning_texts();
        $cls = 'nc-warning' . ($compact ? ' nc-warning--compact' : '');
        return '<div class="' . $cls . '" role="note" aria-label="North Carolina liability warnings">'
             . '<p class="nc-warning-title"><strong>WARNING</strong></p>'
             . '<p>' . htmlspecialchars($agri) . '</p>'
             . '<p class="nc-warning-title"><strong>WARNING</strong></p>'
             . '<p>' . htmlspecialchars($farm) . '</p>'
             . ($compact ? '<p class="nc-warning-more">By continuing you agree to our '
                 . ('<a href="' . bcrf_farm_url() . '/terms/">Terms of Use</a>.</p>') : '')
             . '</div>';
    }

    /** Inline-styled version for emails (dark email template). Ticket, pass and booking confirmations (Nov 6, 2026). */
    function bcrf_nc_warning_email_html(): string {
        [$agri, $farm] = bcrf_nc_warning_texts();
        $h = '<p style="font-size:.8rem;margin:0 0 4px;color:#f5ead6"><strong>WARNING</strong></p>';
        $p = fn($t) => '<p style="font-size:.8rem;line-height:1.5;margin:0 0 10px;color:rgba(245,234,214,.85)">' . htmlspecialchars($t) . '</p>';
        return '<div style="margin:18px 0 0;padding:12px 14px;border:1px solid rgba(232,184,75,.45);border-radius:8px">'
             . $h . $p($agri) . $h . $p($farm) . '</div>';
    }
}
