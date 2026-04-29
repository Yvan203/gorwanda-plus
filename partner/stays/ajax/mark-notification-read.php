<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__, 3) . '/includes/db.php';

$db = getDB();
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$notificationId = isset($input['notification_id']) ? intval($input['notification_id']) : 0;

if ($notificationId > 0) {
    $stmt = $db->prepare("
        UPDATE notifications 
        SET is_read = 1 
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);
}

header('Content-Type: application/json');
echo json_encode(['success' => true]);
