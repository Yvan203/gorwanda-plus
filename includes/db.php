<?php
/**
 * GoRwanda+ Database Configuration
 * File: includes/db.php
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Default WAMP user
define('DB_PASS', '');              // Default WAMP password (empty)
define('DB_NAME', 'gorwanda_plus');
define('DB_CHARSET', 'utf8mb4');

// Create database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper function to get PDO instance
function getDB() {
    global $pdo;
    return $pdo;
}

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('Africa/Kigali');
?>