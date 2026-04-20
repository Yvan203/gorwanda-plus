<?php
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

$propertyId = intval($_POST['property_id'] ?? 0);
$message = sanitize($_POST['message'] ?? '');
$subject = sanitize($_POST['subject'] ?? 'Question about your property');

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

// Get owner_id from property
$stmt = $db->prepare("SELECT owner_id FROM stays WHERE stay_id = ? AND is_active = 1");
$stmt->execute([$propertyId]);
$stay = $stmt->fetch();

if (!$stay) {
    echo json_encode(['success' => false, 'error' => 'Property not found']);
    exit;
}

// Get vendor_id
$stmt = $db->prepare("SELECT vendor_id FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$stay['owner_id']]);
$vendor = $stmt->fetch();

if (!$vendor) {
    echo json_encode(['success' => false, 'error' => 'Vendor not found']);
    exit;
}

// Insert message
$stmt = $db->prepare("
    INSERT INTO messages (
        sender_id, receiver_id, subject, message, 
        booking_id, is_read, created_at
    ) VALUES (?, ?, ?, ?, NULL, 0, NOW())
");
$success = $stmt->execute([
    $currentUser['user_id'],
    $stay['owner_id'],
    $subject,
    $message
]);

if ($success) {
    echo json_encode([
        'success' => true, 
        'message' => 'Message sent successfully',
        'data' => [
            'id' => $db->lastInsertId(),
            'time' => date('M d, Y h:i A')
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
}