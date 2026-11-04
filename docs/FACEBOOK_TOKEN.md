# Making a new Facebook Page token

<!-- docs/FACEBOOK_TOKEN.md — Nov 2026. Checked daily by admin/cron/check_social_token.php -->

The site posts new recipes, blog posts and animals to the **Petting Zoo** Facebook Page
(ID `1124617920731794`) and Instagram using `FB_PAGE_TOKEN` in `/home2/ddailnmy/bcrf-secrets.php`.
A daily check emails you if that token stops working or is about to expire, and the admin
Dashboard / Social Queue show a banner. When that happens, make a new one (about 5 minutes):

1. Open **developers.facebook.com/tools/explorer** (Graph API Explorer), signed in as the
   Facebook account that manages the Petting Zoo page.
2. **Meta App:** `BCRF Site Publisher` (App ID `4401281856803313`). **User or Page:** *Get User Access Token*.
   Tick: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `business_management`,
   `instagram_basic`, `instagram_content_publish`. Tap **Generate Access Token** and approve.
3. Make it long-lived: copy the token, open **developers.facebook.com/tools/debug/accesstoken**,
   paste, **Debug** → **Extend Access Token** → copy the new (long-lived) **user** token.
   This user token is only a stepping stone — don't save it.
4. Back in the Explorer, paste the long-lived user token in the token box and run:
   `GET me/accounts?fields=id,name,access_token`
5. In the result, find **id `1124617920731794`** (the Petting Zoo page — *not* the Farm page
   `1137952929399368`) and copy its `access_token`. Page tokens made from a long-lived user token
   don't expire on their own.
6. cPanel → File Manager → edit `/home2/ddailnmy/bcrf-secrets.php` → replace the `FB_PAGE_TOKEN` value → Save.
7. Admin → Social Queue → **Check now**. The banner should turn green and show the next renewal date.
8. If posts failed while the token was broken, reset them to retry (phpMyAdmin):
   `UPDATE social_queue SET status = 'pending', last_error = NULL WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY;`

**Why it breaks:** changing the Facebook password, removing the app from the account's Business
Integrations, a security checkpoint, losing admin on the Page, or Facebook's 90-day *data access*
expiry for apps that haven't been re-approved. The daily check catches all of these.
