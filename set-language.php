<?php
require_once __DIR__ . '/includes/functions.php';

$lang = $_GET['lang'] ?? 'en';
$redirect = sanitizeLocalRedirect($_GET['redirect'] ?? '/gorwanda-plus/');

setCurrentLanguage($lang);

error_log("Language changed to: " . getCurrentLanguage() . " - Session ID: " . session_id());

header('Location: ' . $redirect);
exit;
