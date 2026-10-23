<?php
require_once '../config.php';
require_once '../includes/auth.php';
auth_logout();
$next = safe_next((string)($_GET['next'] ?? '/'));
header('Location: ' . $next);
exit;
