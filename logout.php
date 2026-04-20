<?php
require_once 'includes/functions.php';

// Clear all session data
$_SESSION = [];

// Destroy session
session_destroy();

// Redirect to home
header('Location: /gorwanda-plus/');
exit;
?>