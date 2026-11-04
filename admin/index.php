<?php // admin/index.php
// batcaveroadfarm.com/admin/ -> the admin dashboard. (config.php sends it to admin.batcaveroadfarm.com
// instead once admin/use_subdomain.txt exists.)
require_once __DIR__ . '/../config.php';
header('Location: /admin/admin.php', true, 302);
exit;
