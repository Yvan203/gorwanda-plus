<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log the logout activity if user was logged in
if (isset($_SESSION['user_id'])) {
    // You can log this to your activity_logs table
    try {
        require_once '../includes/db.php';
        require_once '../includes/functions.php';

        $db = getDB();
        $userId = $_SESSION['user_id'];
        $userName = $_SESSION['user_name'] ?? 'Unknown';

        // Log the logout action (optional - create activity_logs table first)
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, 'logout', ?, ?, ?, NOW())
        ");
        $details = json_encode(['message' => 'User logged out successfully']);
        $stmt->execute([$userId, $details, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    } catch (Exception $e) {
        // Silently fail if logging doesn't work
        error_log("Logout logging failed: " . $e->getMessage());
    }
}

// Clear all session variables
$_SESSION = array();

// If session cookie exists, delete it
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Clear remember me cookie if exists (for "Remember Me" functionality)
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}
if (isset($_COOKIE['user_email'])) {
    setcookie('user_email', '', time() - 3600, '/');
}

// Clear any other custom cookies
if (isset($_COOKIE['currency'])) {
    // Keep currency and language? Or clear them? 
    // Usually we keep these preferences
    // setcookie('currency', '', time() - 3600, '/');
}
if (isset($_COOKIE['language'])) {
    // setcookie('language', '', time() - 3600, '/');
}

// Redirect to home page with logout message
session_start(); // Start new session for flash message
$_SESSION['flash'] = [
    'type' => 'success',
    'message' => 'You have been successfully logged out. Thank you for using GoRwanda+!'
];

// Redirect to login page or home page
header('Location: /gorwanda-plus/index.php');
exit;
