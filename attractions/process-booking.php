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
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

// Get form data
$attractionId = intval($_POST['attraction_id'] ?? 0);
$tierId = intval($_POST['tier_id'] ?? 0);
$experienceDate = $_POST['experience_date'] ?? '';
$participants = intval($_POST['participants'] ?? 2);
$basePrice = floatval($_POST['base_price'] ?? 0);
$subtotal = floatval($_POST['subtotal'] ?? 0);
$taxAmount = floatval($_POST['tax_amount'] ?? 0);
$totalAmount = floatval($_POST['total_amount'] ?? 0);

// Guest info
$firstName = sanitize($_POST['first_name'] ?? '');
$lastName = sanitize($_POST['last_name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$participantNames = sanitize($_POST['participant_names'] ?? '');
$specialRequests = sanitize($_POST['special_requests'] ?? '');

$currentUser = getCurrentUser();
$db = getDB();

// Validate required fields
if (!$attractionId || !$tierId || !$experienceDate) {
    $_SESSION['error'] = "Missing booking information";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Validate date
$selectedDate = new DateTime($experienceDate);
$today = new DateTime();

if ($selectedDate < $today) {
    $_SESSION['error'] = "Experience date cannot be in the past";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Get attraction and tier details
$stmt = $db->prepare("
    SELECT a.*, t.*, 
           t.base_price as tier_base_price, t.tier_name, t.description as tier_description,
           u.user_id as owner_id, u.first_name as owner_first, u.last_name as owner_last,
           u.email as owner_email, u.phone as owner_phone
    FROM attractions a
    LEFT JOIN attraction_tiers t ON a.attraction_id = t.attraction_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE a.attraction_id = ? AND t.tier_id = ? AND a.is_active = 1 AND t.is_active = 1
");
$stmt->execute([$attractionId, $tierId]);
$bookingData = $stmt->fetch();

if (!$bookingData) {
    $_SESSION['error'] = "Experience or pricing tier not found";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Check availability for selected date
$stmt = $db->prepare("
    SELECT aa.max_bookings, aa.bookings_made, aa.is_blocked, aa.price_override
    FROM attraction_availability aa
    WHERE aa.tier_id = ? AND aa.date = ?
");
$stmt->execute([$tierId, $experienceDate]);
$availability = $stmt->fetch();

$maxBookings = $availability['max_bookings'] ?? $bookingData['max_participants'] ?? 20;
$bookingsMade = $availability['bookings_made'] ?? 0;
$isBlocked = $availability['is_blocked'] ?? false;
$availableSpots = $maxBookings - $bookingsMade;

if ($isBlocked || $availableSpots < $participants) {
    $_SESSION['error'] = "Not enough spots available for selected date. Please choose another date.";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Check for conflicting bookings (double-check)
$stmt = $db->prepare("
    SELECT COUNT(*) as conflict_count
    FROM bookings b
    WHERE b.attraction_tier_id = ? 
    AND b.status IN ('confirmed', 'pending')
    AND b.experience_date = ?
");
$stmt->execute([$tierId, $experienceDate]);
$conflicts = $stmt->fetch();

if ($conflicts['conflict_count'] + $participants > $maxBookings) {
    $_SESSION['error'] = "Selected date is no longer available. Please choose another date.";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Calculate commission (for partner payout - not shown to customer)
$commissionRate = $bookingData['commission_rate'] ?? 15;
$commissionAmount = $subtotal * ($commissionRate / 100);

// Get start time (if available)
$startTimes = json_decode($bookingData['start_times'] ?? '[]', true);
$startTime = !empty($startTimes) ? $startTimes[0] : null;

// Generate booking reference
$bookingRef = generateBookingRef();

// Create booking
$stmt = $db->prepare("
    INSERT INTO bookings (
        booking_reference, user_id, booking_type, attraction_tier_id,
        experience_date, start_time, num_participants, num_guests,
        guest_first_name, guest_last_name, guest_email, guest_phone,
        special_requests, unit_price, total_amount, tax_amount,
        commission_amount, status, payment_status, created_at
    ) VALUES (
        ?, ?, 'attraction', ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, 'pending', 'pending', NOW()
    )
");

$result = $stmt->execute([
    $bookingRef,
    $currentUser['user_id'],
    $tierId,
    $experienceDate,
    $startTime,
    $participants,
    $participants,
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
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

$bookingId = $db->lastInsertId();

// Update availability booked count
if ($availability) {
    $stmt = $db->prepare("
        UPDATE attraction_availability 
        SET bookings_made = bookings_made + ?
        WHERE tier_id = ? AND date = ?
    ");
    $stmt->execute([$participants, $tierId, $experienceDate]);
} else {
    $stmt = $db->prepare("
        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$tierId, $experienceDate, $maxBookings, $participants]);
}

// Create notification for admin
$notificationManager = new NotificationManager();

// Notify admin about new booking
$notificationManager->newBooking($bookingId, 1, [
    'reference' => $bookingRef,
    'guest_name' => $firstName . ' ' . $lastName,
    'item_name' => $bookingData['attraction_name'],
    'amount' => formatPrice($totalAmount),
    'date' => $experienceDate,
    'participants' => $participants
]);

// Notify attraction owner
if ($bookingData['owner_id']) {
    $notificationManager->newBooking($bookingId, $bookingData['owner_id'], [
        'reference' => $bookingRef,
        'guest_name' => $firstName . ' ' . $lastName,
        'item_name' => $bookingData['attraction_name'],
        'amount' => formatPrice($totalAmount),
        'date' => $experienceDate,
        'participants' => $participants,
        'tier' => $bookingData['tier_name']
    ]);
}

// Log the activity
$stmt = $db->prepare("
    INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
    VALUES (?, 'booking_created', 'booking', ?, ?, ?, NOW())
");
$details = json_encode([
    'booking_reference' => $bookingRef,
    'amount' => $totalAmount,
    'participants' => $participants,
    'tier_id' => $tierId,
    'attraction_id' => $attractionId,
    'date' => $experienceDate
]);
$stmt->execute([$currentUser['user_id'], $bookingId, $details, $_SERVER['REMOTE_ADDR']]);

// Store booking info in session for payment page
$_SESSION['pending_booking'] = [
    'booking_id' => $bookingId,
    'booking_reference' => $bookingRef,
    'total_amount' => $totalAmount,
    'item_name' => $bookingData['attraction_name'],
    'tier_name' => $bookingData['tier_name'],
    'date' => $experienceDate,
    'participants' => $participants
];

// Redirect to payment page
header('Location: /gorwanda-plus/payment.php?booking_id=' . $bookingId);
exit;
