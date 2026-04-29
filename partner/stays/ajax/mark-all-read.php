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

$stmt = $db->prepare("
    UPDATE notifications 
    SET is_read = 1 
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$userId]);

header('Content-Type: application/json');
echo json_encode(['success' => true]);
