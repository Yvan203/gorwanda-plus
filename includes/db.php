<?php
/**
 * GoRwanda+ Database Configuration
 * File: includes/db.php
 * Supports both Local (WAMP) and Wasmer Production environments
 */

// Detect environment
$is_production = getenv('WASMER_ENV') === 'production';

if ($is_production) {
    // Wasmer Production Environment
    define('DB_HOST', getenv('MYSQL_HOST') ?: 'mysql.wasmer.app');
    define('DB_USER', getenv('MYSQL_USER') ?: 'root');
    define('DB_PASS', getenv('MYSQL_PASSWORD') ?: '');
    define('DB_NAME', getenv('MYSQL_DATABASE') ?: 'gorwanda_plus');
} else {
    // Local Development (WAMP)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');          // Default WAMP user
    define('DB_PASS', '');              // Default WAMP password (empty)
    define('DB_NAME', 'gorwanda_plus');
}

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
    // Log error but don't expose details in production
    if ($is_production) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed. Please try again later.");
    } else {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Helper function to get PDO instance
function getDB() {
    global $pdo;
    return $pdo;
}

// Helper function to check if running on Wasmer
function isWasmer() {
    return getenv('WASMER_ENV') === 'production';
}

// Helper function to get base URL
function baseUrl($path = '') {
    if (isWasmer()) {
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'gorwanda-plus.wasmer.app';
        return $protocol . $host . '/' . ltrim($path, '/');
    } else {
        return '/gorwanda-plus/' . ltrim($path, '/');
    }
}

// Session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('Africa/Kigali');
?>