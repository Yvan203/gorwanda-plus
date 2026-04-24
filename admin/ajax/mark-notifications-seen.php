<?php
session_start();
require_once '../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

// Just mark that notifications have been seen (optional)
// You could add a 'last_seen_at' column if needed

echo json_encode(['success' => true]);
?>