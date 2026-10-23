<?php
// account/dismiss_nudge.php
// Hides the email-verification nudge for the rest of this session. Harmless,
// non-destructive state, so a GET link is fine. safe_next() blocks open redirects.
require_once '../config.php';
require_once '../includes/auth.php';

$_SESSION['evf_nudge_dismissed'] = 1;
header('Location: ' . safe_next((string)($_GET['next'] ?? '/')));
exit;
