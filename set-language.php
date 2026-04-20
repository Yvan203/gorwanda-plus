<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get requested language
$lang = $_GET['lang'] ?? 'en';
$redirect = $_GET['redirect'] ?? '/gorwanda-plus/';

// Validate language
$validLanguages = ['en', 'fr', 'rw', 'sw'];
if (!in_array($lang, $validLanguages)) {
    $lang = 'en';
}

// Set language in session
$_SESSION['language'] = $lang;

// Set cookie for 30 days
setcookie('user_language', $lang, [
    'expires' => time() + (86400 * 30),
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Debug - you can remove this after testing
error_log("Language changed to: " . $lang . " - Session ID: " . session_id());

// Redirect back
header('Location: ' . $redirect);
exit;