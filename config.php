<?php
/**
 * GoRwanda+ Configuration
 * File: config.php
 */

// Detect environment
$is_production = getenv('WASMER_ENV') === 'production';

// Application settings
if ($is_production) {
    // Production settings (Wasmer)
    define('APP_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'gorwanda-plus.wasmer.app'));
    define('APP_ENV', 'production');
    define('APP_DEBUG', false);
    define('UPLOAD_PATH', __DIR__ . '/assets/uploads/');
} else {
    // Development settings (Local WAMP)
    define('APP_URL', 'http://localhost/gorwanda-plus');
    define('APP_ENV', 'development');
    define('APP_DEBUG', true);
    define('UPLOAD_PATH', __DIR__ . '/assets/uploads/');
}

// Site settings
define('SITE_NAME', 'GoRwanda+');
define('SITE_EMAIL', 'info@gorwanda.com');
define('ADMIN_EMAIL', 'admin@gorwanda.com');

// Pagination settings
define('ITEMS_PER_PAGE', 20);

// File upload settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// Cache settings (for production)
if ($is_production) {
    define('CACHE_ENABLED', true);
    define('CACHE_PATH', __DIR__ . '/cache/');
} else {
    define('CACHE_ENABLED', false);
}
?>