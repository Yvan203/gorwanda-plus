<?php
echo "<h2>Image Directory Test</h2>";

$baseDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/';

// Check attractions directory
$attractionsDir = $baseDir . 'attractions/';
echo "<h3>Attractions Directory: {$attractionsDir}</h3>";
if (file_exists($attractionsDir)) {
    $files = scandir($attractionsDir);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && $file != 'gallery') {
            echo "<li>{$file} - " . (is_file($attractionsDir . $file) ? 'File' : 'Directory') . "</li>";
        }
    }
    echo "</ul>";
} else {
    echo "Directory does not exist!<br>";
}

// Check gallery directory
$galleryDir = $attractionsDir . 'gallery/';
echo "<h3>Gallery Directory: {$galleryDir}</h3>";
if (file_exists($galleryDir)) {
    $files = scandir($galleryDir);
    echo "<ul>";
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            echo "<li>{$file}</li>";
        }
    }
    echo "</ul>";
} else {
    echo "Directory does not exist!<br>";
}

// Show a sample image if available
$sampleImage = null;
if (file_exists($attractionsDir)) {
    $files = scandir($attractionsDir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && $file != 'gallery' && !is_dir($attractionsDir . $file)) {
            $sampleImage = $file;
            break;
        }
    }
}

if ($sampleImage) {
    echo "<h3>Sample Image:</h3>";
    echo "<img src='/gorwanda-plus/assets/images/attractions/{$sampleImage}' style='max-width: 300px; border: 2px solid green;'>";
    echo "<p>Path: /gorwanda-plus/assets/images/attractions/{$sampleImage}</p>";
}
?>