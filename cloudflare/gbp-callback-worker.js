// cloudflare/gbp-callback-worker.js
// Cloudflare Worker "bcrf-gbp-callback", route api.batcaveroadfarm.com/gbp-callback.php*
// Deployed Nov 2026 (it lives in Cloudflare; this copy is for the record).
// Google Business Profile sign-in relay: Google's OAuth reply has URLs in the query string (iss, scope)
// that Bluehost's ModSecurity blocks (and ModSecurity can't be switched off in this cPanel), so this
// answers at Cloudflare and forwards only code + state to the admin page, which checks state against
// the admin session. Same logic as api/gbp-callback.php, which is now only a fallback. Stores nothing.
export default {
  fetch(request) {
    const u = new URL(request.url);
    const keep = (k) => { const v = u.searchParams.get(k) || ''; return /^[A-Za-z0-9._~\/-]{1,512}$/.test(v) ? v : ''; };
    const q = new URLSearchParams({ state: keep('state') });
    if (u.searchParams.has('error')) q.set('error', keep('error') || 'access_denied');
    else q.set('code', keep('code'));
    return new Response(null, { status: 302, headers: {
      'Location': 'https://admin.batcaveroadfarm.com/gbp_connect.php?' + q.toString(),
      'Cache-Control': 'no-store', 'Referrer-Policy': 'no-referrer' } });
  }
};
