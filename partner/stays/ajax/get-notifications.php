<?php
/**
 * Get notifications for stay partner
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Verify user is a stay partner
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

if (!in_array('stay', $businessTypes)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get unread notification count
$stmt = $db->prepare("
    SELECT COUNT(*) as unread
    FROM notifications n
    WHERE n.user_id = ? AND n.is_read = 0
");
$stmt->execute([$userId]);
$unread = $stmt->fetch();

// Get recent notifications
$stmt = $db->prepare("
    SELECT 
        n.*,
        TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
    FROM notifications n
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// Format notifications
$formatted = [];
$icons = [
    'new_booking' => 'calendar-check',
    'payment_received' => 'credit-card',
    'booking_cancelled' => 'calendar-x',
    'new_review' => 'star',
    'checkin_reminder' => 'box-arrow-in-right',
    'checkout_reminder' => 'box-arrow-left',
    'low_inventory' => 'exclamation-triangle',
    'booking_confirmed' => 'check-circle',
    'system_alert' => 'gear',
];

foreach ($notifications as $n) {
    $timeAgo = '';
    $seconds = (int)$n['seconds_ago'];
    if ($seconds < 60) $timeAgo = 'Just now';
    elseif ($seconds < 3600) $timeAgo = floor($seconds/60) . ' min ago';
    elseif ($seconds < 86400) $timeAgo = floor($seconds/3600) . ' hours ago';
    else $timeAgo = floor($seconds/86400) . ' days ago';
    
    $formatted[] = [
        'id' => (int)$n['notification_id'],
        'type' => $n['type'],
        'icon' => $icons[$n['type']] ?? 'bell',
        'title' => $n['title'],
        'message' => $n['message'],
        'is_read' => (bool)$n['is_read'],
        'time_ago' => $timeAgo,
        'data' => json_decode($n['data'] ?? '{}', true),
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'unread_count' => (int)$unread['unread'],
    'notifications' => $formatted,
]);