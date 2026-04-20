<?php
// test-db-wasmer.php
echo "<h1>Wasmer Database Test</h1>";

// Check environment variables
echo "<h2>Environment Variables:</h2>";
echo "<pre>";
echo "WASMER_ENV: " . (getenv('WASMER_ENV') ?: 'NOT SET') . "\n";
echo "MYSQL_HOST: " . (getenv('MYSQL_HOST') ?: 'NOT SET') . "\n";
echo "MYSQL_USER: " . (getenv('MYSQL_USER') ?: 'NOT SET') . "\n";
echo "MYSQL_PASSWORD: " . (getenv('MYSQL_PASSWORD') ? '✓ SET' : 'NOT SET') . "\n";
echo "MYSQL_DATABASE: " . (getenv('MYSQL_DATABASE') ?: 'NOT SET') . "\n";
echo "</pre>";

// Test database connection
echo "<h2>Database Connection Test:</h2>";

try {
    $host = getenv('MYSQL_HOST');
    $user = getenv('MYSQL_USER');
    $pass = getenv('MYSQL_PASSWORD');
    $dbname = getenv('MYSQL_DATABASE');

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<p style='color: green;'>✓ Database connection successful!</p>";

    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    echo "<p>✓ Users count: " . $result['count'] . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}
