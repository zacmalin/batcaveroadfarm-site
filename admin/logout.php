<?php
ob_start(); // Buffer any output from config includes before we send the redirect header

require_once '../config.php';

// Destroy session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();

ob_end_clean(); // Discard any buffered output before redirect
header('Location: /admin/login.php');
exit;
