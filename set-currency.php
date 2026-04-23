<?php
require_once __DIR__ . '/includes/functions.php';

$currency = $_GET['currency'] ?? 'RWF';
$redirect = sanitizeLocalRedirect($_GET['redirect'] ?? '/gorwanda-plus/');

setCurrentCurrency($currency);

error_log("Currency changed to: " . getCurrentCurrency());

header('Location: ' . $redirect);
exit;
