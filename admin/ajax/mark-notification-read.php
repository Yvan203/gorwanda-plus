<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$notificationId = $data['notification_id'] ?? 0;

$nm = new NotificationManager();
$nm->markAsRead($notificationId, $_SESSION['user_id']);

echo json_encode(['success' => true]);
