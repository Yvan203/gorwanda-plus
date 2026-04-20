<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get requested currency
$currency = $_GET['currency'] ?? 'RWF';
$redirect = $_GET['redirect'] ?? '/gorwanda-plus/';

// Validate currency
$validCurrencies = ['RWF', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS'];
if (!in_array($currency, $validCurrencies)) {
    $currency = 'RWF';
}

// Set currency in session
$_SESSION['currency'] = $currency;

// Set cookie for 30 days
setcookie('user_currency', $currency, [
    'expires' => time() + (86400 * 30),
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Debug
error_log("Currency changed to: " . $currency);

// Redirect back
header('Location: ' . $redirect);
exit;