<?php
require_once '../includes/functions.php';
require_once '../includes/notifications.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Get form data
$stayId = intval($_POST['stay_id'] ?? 0);
$roomId = intval($_POST['room_id'] ?? 0);
$checkin = $_POST['checkin'] ?? '';
$checkout = $_POST['checkout'] ?? '';
$guests = intval($_POST['guests'] ?? 2);
$nights = intval($_POST['nights'] ?? 1);
$basePrice = floatval($_POST['base_price'] ?? 0);
$subtotal = floatval($_POST['subtotal'] ?? 0);
$taxAmount = floatval($_POST['tax_amount'] ?? 0);
$totalAmount = floatval($_POST['total_amount'] ?? 0);
$taxRate = floatval($_POST['tax_rate'] ?? 18);

// Guest info
$firstName = sanitize($_POST['first_name'] ?? '');
$lastName = sanitize($_POST['last_name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$specialRequests = sanitize($_POST['special_requests'] ?? '');

$currentUser = getCurrentUser();
$db = getDB();

// Validate required fields
if (!$stayId || !$roomId || !$checkin || !$checkout) {
    $_SESSION['error'] = "Missing booking information";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Verify room availability again (double-check)
$stmt = $db->prepare("
    SELECT COUNT(*) as blocked_count 
    FROM stay_availability 
    WHERE room_id = ? 
    AND date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
    AND (is_blocked = 1 OR rooms_available <= 0)
");
$stmt->execute([$roomId, $checkin, $checkout]);
$blockedDays = $stmt->fetchColumn();

if ($blockedDays > 0) {
    $_SESSION['error'] = "Sorry, this room is no longer available for selected dates";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Get stay and room details for notification
$stmt = $db->prepare("
    SELECT s.*, u.user_id as owner_id, u.first_name as owner_first, u.last_name as owner_last, u.email as owner_email
    FROM stays s
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ?
");
$stmt->execute([$stayId]);
$stay = $stmt->fetch();

$stmt = $db->prepare("SELECT room_name FROM stay_rooms WHERE room_id = ?");
$stmt->execute([$roomId]);
$room = $stmt->fetch();

// Generate booking reference
$bookingRef = generateBookingRef();

// Create booking
$stmt = $db->prepare("
    INSERT INTO bookings (
        booking_reference, user_id, booking_type, stay_room_id,
        check_in_date, check_out_date, num_nights, num_guests,
        guest_first_name, guest_last_name, guest_email, guest_phone,
        special_requests, unit_price, total_amount, tax_amount,
        commission_amount, status, payment_status, created_at
    ) VALUES (
        ?, ?, 'stay', ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, 'pending', 'pending', NOW()
    )
");

// Calculate commission (platform fee - not shown to customer)
// This is deducted from partner payout, not added to customer bill
$commissionRate = 10; // Default, get from stay's commission_rate
$stmtCommission = $db->prepare("SELECT commission_rate FROM stays WHERE stay_id = ?");
$stmtCommission->execute([$stayId]);
$dbCommission = $stmtCommission->fetchColumn();
$commissionRate = $dbCommission ?: 10;
$commissionAmount = $subtotal * ($commissionRate / 100);

$result = $stmt->execute([
    $bookingRef,
    $currentUser['user_id'],
    $roomId,
    $checkin,
    $checkout,
    $nights,
    $guests,
    $firstName,
    $lastName,
    $email,
    $phone,
    $specialRequests,
    $basePrice,
    $totalAmount,
    $taxAmount,
    $commissionAmount
]);

if (!$result) {
    $_SESSION['error'] = "Failed to create booking. Please try again.";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

$bookingId = $db->lastInsertId();

// Create notification for admin
$notificationManager = new NotificationManager();

// Notify admin about new booking
$notificationManager->newBooking($bookingId, 1, [  // 1 = admin user ID
    'reference' => $bookingRef,
    'guest_name' => $firstName . ' ' . $lastName,
    'item_name' => $stay['stay_name'] . ' - ' . $room['room_name'],
    'amount' => formatPrice($totalAmount),
    'checkin' => $checkin,
    'checkout' => $checkout,
    'guests' => $guests
]);

// Notify property owner
if ($stay['owner_id']) {
    $notificationManager->newBooking($bookingId, $stay['owner_id'], [
        'reference' => $bookingRef,
        'guest_name' => $firstName . ' ' . $lastName,
        'item_name' => $stay['stay_name'] . ' - ' . $room['room_name'],
        'amount' => formatPrice($totalAmount),
        'checkin' => $checkin,
        'checkout' => $checkout,
        'guests' => $guests
    ]);
}

// Also log the activity
$stmt = $db->prepare("
    INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
    VALUES (?, 'booking_created', 'booking', ?, ?, ?, NOW())
");
$details = json_encode([
    'booking_reference' => $bookingRef,
    'amount' => $totalAmount,
    'nights' => $nights,
    'room_id' => $roomId,
    'stay_id' => $stayId
]);
$stmt->execute([$currentUser['user_id'], $bookingId, $details, $_SERVER['REMOTE_ADDR']]);

// Store booking info in session for payment page
$_SESSION['pending_booking'] = [
    'booking_id' => $bookingId,
    'booking_reference' => $bookingRef,
    'total_amount' => $totalAmount,
    'stay_name' => $stay['stay_name'],
    'room_name' => $room['room_name'],
    'checkin' => $checkin,
    'checkout' => $checkout
];

// Redirect to payment page
header('Location: /gorwanda-plus/payment.php?booking_id=' . $bookingId);
exit;
