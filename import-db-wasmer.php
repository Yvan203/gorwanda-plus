<?php
// import-db-wasmer.php
echo "<h1>Database Import</h1>";

$host = getenv('MYSQL_HOST');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASSWORD');
$dbname = getenv('MYSQL_DATABASE');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Read your SQL file
    $sql = file_get_contents('gorwanda_plus.sql');
    
    // Split into individual queries
    $queries = explode(';', $sql);
    $success = 0;
    $failed = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (empty($query)) continue;
        
        try {
            $pdo->exec($query);
            $success++;
        } catch (PDOException $e) {
            // Table might already exist, that's fine
            if (strpos($e->getMessage(), 'already exists') === false) {
                $failed++;
                echo "<div style='color: orange;'>Warning: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    
    echo "<p style='color: green;'>✓ Import complete! $success queries executed.</p>";
    echo "<p><a href='/' style='background: blue; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Website →</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>