<?php
// /account/session.php — tiny JSON endpoint so cached pages can learn login state + a fresh CSRF token.
require_once '../config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

echo json_encode([
    'loggedIn' => is_logged_in(),
    'name'     => current_user_name(),
    'csrf'     => csrf_token(),
]);
