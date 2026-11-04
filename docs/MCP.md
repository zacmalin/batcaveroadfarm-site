# docs/MCP.md — Farm MCP server (Claude & Perplexity)

Lets Claude or Perplexity look up and update farm data while you chat with them.

**Server URL:** `https://api.batcaveroadfarm.com/mcp`

Only an **active administrator** account can connect. Each app signs in through OAuth
on a farm page that asks for your admin email and password. Wrong passwords count
toward the same lockout as the admin login. To see or disconnect apps, go to
**Admin → AI Connections** (`/admin/mcp_connections.php`).

## What it can do

| Area | Tools |
|---|---|
| Overview | `farm_overview` (today's visits, pending bookings, new inquiries, low stock, today's POS sales, upcoming events) |
| Bookings & tickets | `list_bookings`, `get_booking`, `set_booking_status` (**confirming emails the customer**, same as the admin button) |
| Private events | `list_event_inquiries`, `set_inquiry_status` |
| Passes | `list_passes` |
| Inventory | `list_inventory`, `adjust_stock` |
| Sales | `sales_report` (POS by day and payment method, top items, booking and pass revenue) |
| Content | `list_content`, `get_content` (recipes, blog posts, events), `create_blog_draft`, `create_event_draft` (always drafts; you publish) |
| Site Info | `get_site_info` |
| Website files | `list_files`, `read_file`, `write_file`, `list_file_backups`, `restore_file_backup` |

Every change is written to the **Audit Log** as "Zac (AI: Claude)", and so on.

### File editing rules
- Only files inside the site folder, and only text files (php, html, css, js, json, md, txt, xml, svg, csv, yml).
- **Hidden:** `config.php`, anything named like a secret, credential or `.env`, plus `.git`, `vendor` and `node_modules`.
- **Read-only:** the MCP server itself (`api/`, `includes/mcp/`), the login, auth, audit and permission files, `.htaccess` and other dotfiles.
- Before every save, the old file is copied to `/home2/ddailnmy/bcrf-mcp-backups/`, outside the website. The newest 300 backups are kept, for up to 120 days. `restore_file_backup` undoes any edit.
- PHP is syntax-checked and JSON is validated. Broken files are refused.
- **Edits change the live server only, not GitHub.** Copy the change into GitHub, or the next upload from GitHub will undo it.

## Setup

1. Upload the files from the commit:
   - `api/index.php`, `api/.htaccess`
   - `includes/mcp/` (`oauth.php`, `server.php`, `tools.php`, `files.php`)
   - `admin/mcp_connections.php`, `admin/views/layout/sidebar.php`
2. Check that it's live by opening <https://api.batcaveroadfarm.com/.well-known/oauth-authorization-server>. You should see JSON.
   The database tables (`mcp_oauth_clients`, `mcp_oauth_codes`, `mcp_oauth_tokens`) are created automatically on first use.

### Claude
Add it at **claude.ai in a browser**. It then works in the phone app too.
1. Go to Settings → Connectors → **Add custom connector**.
2. Name: `Bat Cave Road Farm`. URL: `https://api.batcaveroadfarm.com/mcp`. Leave Advanced settings empty.
3. Tap **Connect**. On the farm page, enter your admin email and password, then tap **Allow**.

### Perplexity
Add it at **perplexity.ai in a browser**. The phone app may not show custom connectors.
1. Go to Settings → Connectors → **+ Custom connector** → **Remote**.
2. URL: `https://api.batcaveroadfarm.com/mcp`. Authentication: **OAuth**. Transport: **Streamable HTTP**.
3. Sign in on the farm page and tap **Allow**. In a thread, turn the connector on from the sources menu.

## Troubleshooting
- **"Not Acceptable" / 406, or the connect step fails right away:** Bluehost's ModSecurity is
  blocking the request, because it contains the app's return web address.
  Go to cPanel → Security → **ModSecurity** and turn it **off for `api.batcaveroadfarm.com` only**, never the main site.
  The sign-in page is on the api subdomain for exactly this reason.
- **The app says it was disconnected:** access tokens last 1 hour and refresh automatically for 30 days of use.
  After that, or after you disconnect it in Admin, connect again.
- **"Only an active administrator account…":** the account isn't an administrator, or it's turned off.
- **Server error (reference abc123):** search the PHP error log for `[MCP abc123]`.

## How it works (for developers)
- OAuth 2.1 Authorization Code + PKCE (S256 only), with Dynamic Client Registration (RFC 7591).
  A client secret is always issued, because Perplexity requires one. Metadata is served by RFC 8414 and RFC 9728.
- Tokens are random and stored as SHA-256 hashes only. Access tokens last 1 hour. Refresh tokens last 30 days and rotate on each use.
- Every request re-checks that the user is still an active administrator.
- `/mcp` is stateless Streamable HTTP. It takes a POST with JSON-RPC and replies in JSON, with no SSE.
  It supports `initialize`, `ping`, `tools/list` and `tools/call`; notifications get a 202.
- Code: `includes/mcp/oauth.php` (OAuth and sign-in page), `server.php` (JSON-RPC), `tools.php` (database tools), `files.php` (file tools).
