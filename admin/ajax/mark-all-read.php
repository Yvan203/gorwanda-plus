<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$nm = new NotificationManager();
$nm->markAllAsRead($_SESSION['user_id']);

echo json_encode(['success' => true]);
