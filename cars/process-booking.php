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
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// Get form data
$carId = intval($_POST['car_id'] ?? 0);
$pickupDate = $_POST['pickup_date'] ?? '';
$returnDate = $_POST['return_date'] ?? '';
$pickupLocation = sanitize($_POST['pickup_location'] ?? '');
$days = intval($_POST['days'] ?? 1);
$dailyRate = floatval($_POST['daily_rate'] ?? 0);
$subtotal = floatval($_POST['subtotal'] ?? 0);
$taxAmount = floatval($_POST['tax_amount'] ?? 0);
$totalAmount = floatval($_POST['total_amount'] ?? 0);

// Driver info
$firstName = sanitize($_POST['first_name'] ?? '');
$lastName = sanitize($_POST['last_name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$licenseNumber = sanitize($_POST['license_number'] ?? '');
$specialRequests = sanitize($_POST['special_requests'] ?? '');

$currentUser = getCurrentUser();
$db = getDB();

// Validate required fields
if (!$carId || !$pickupDate || !$returnDate || !$pickupLocation) {
    $_SESSION['error'] = "Missing booking information";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Validate dates
$pickupDateTime = new DateTime($pickupDate);
$returnDateTime = new DateTime($returnDate);
$today = new DateTime();

if ($pickupDateTime < $today) {
    $_SESSION['error'] = "Pickup date cannot be in the past";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

if ($returnDateTime <= $pickupDateTime) {
    $_SESSION['error'] = "Return date must be after pickup date";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Get car and rental details
$stmt = $db->prepare("
    SELECT cf.*, cr.rental_id, cr.company_name, cr.owner_id, cr.commission_rate,
           cr.phone as company_phone, cr.email as company_email
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cf.car_id = ? AND cf.is_active = 1 AND cr.is_active = 1
");
$stmt->execute([$carId]);
$car = $stmt->fetch();

if (!$car) {
    $_SESSION['error'] = "Vehicle not found";
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// Double-check availability (prevent double booking)
$stmt = $db->prepare("
    SELECT COUNT(*) as booked
    FROM bookings b
    WHERE b.car_id = ? 
    AND b.status IN ('confirmed', 'pending')
    AND (
        (b.pickup_date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY))
        OR (b.return_date BETWEEN ? AND ?)
        OR (? BETWEEN b.pickup_date AND DATE_SUB(b.return_date, INTERVAL 1 DAY))
    )
");
$stmt->execute([$carId, $pickupDate, $returnDate, $pickupDate, $returnDate, $pickupDate]);
$availability = $stmt->fetch();

if ($availability['booked'] > 0) {
    $_SESSION['error'] = "Sorry, this vehicle is no longer available for selected dates";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Check for maintenance
$stmt = $db->prepare("
    SELECT COUNT(*) as maintenance
    FROM car_maintenance
    WHERE car_id = ?
    AND status IN ('scheduled', 'in_progress')
    AND scheduled_date <= ? 
    AND DATE_ADD(scheduled_date, INTERVAL estimated_duration - 1 DAY) >= ?
");
$stmt->execute([$carId, $returnDate, $pickupDate]);
$maintenance = $stmt->fetch();

if ($maintenance['maintenance'] > 0) {
    $_SESSION['error'] = "This vehicle is under maintenance for selected dates";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Calculate commission (for partner payout - not shown to customer)
$commissionRate = $car['commission_rate'] ?? 12;
$commissionAmount = $subtotal * ($commissionRate / 100);

// Generate booking reference
$bookingRef = generateBookingRef();

// Create booking
$stmt = $db->prepare("
    INSERT INTO bookings (
        booking_reference, user_id, booking_type, car_id,
        pickup_date, return_date, pickup_location,
        num_nights, num_guests,
        guest_first_name, guest_last_name, guest_email, guest_phone,
        special_requests, unit_price, total_amount, tax_amount,
        commission_amount, status, payment_status, created_at
    ) VALUES (
        ?, ?, 'car_rental', ?,
        ?, ?, ?,
        ?, 1,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, 'pending', 'pending', NOW()
    )
");

$result = $stmt->execute([
    $bookingRef,
    $currentUser['user_id'],
    $carId,
    $pickupDate,
    $returnDate,
    $pickupLocation,
    $days,
    $firstName,
    $lastName,
    $email,
    $phone,
    $specialRequests,
    $dailyRate,
    $totalAmount,
    $taxAmount,
    $commissionAmount
]);

if (!$result) {
    $_SESSION['error'] = "Failed to create booking. Please try again.";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

$bookingId = $db->lastInsertId();

// Create notification for admin
$notificationManager = new NotificationManager();

// Notify admin about new booking
$notificationManager->newBooking($bookingId, 1, [
    'reference' => $bookingRef,
    'guest_name' => $firstName . ' ' . $lastName,
    'item_name' => $car['brand'] . ' ' . $car['model'] . ' - ' . $car['company_name'],
    'amount' => formatPrice($totalAmount),
    'pickup_date' => $pickupDate,
    'return_date' => $returnDate,
    'pickup_location' => $pickupLocation
]);

// Notify car rental company owner
if ($car['owner_id']) {
    $notificationManager->newBooking($bookingId, $car['owner_id'], [
        'reference' => $bookingRef,
        'guest_name' => $firstName . ' ' . $lastName,
        'item_name' => $car['brand'] . ' ' . $car['model'],
        'amount' => formatPrice($totalAmount),
        'pickup_date' => $pickupDate,
        'return_date' => $returnDate,
        'pickup_location' => $pickupLocation
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
    'days' => $days,
    'car_id' => $carId,
    'pickup_location' => $pickupLocation
]);
$stmt->execute([$currentUser['user_id'], $bookingId, $details, $_SERVER['REMOTE_ADDR']]);

// Store booking info in session for payment page
$_SESSION['pending_booking'] = [
    'booking_id' => $bookingId,
    'booking_reference' => $bookingRef,
    'total_amount' => $totalAmount,
    'item_name' => $car['brand'] . ' ' . $car['model'],
    'company_name' => $car['company_name'],
    'pickup_date' => $pickupDate,
    'return_date' => $returnDate,
    'pickup_location' => $pickupLocation
];

// Redirect to payment page
header('Location: /gorwanda-plus/payment.php?booking_id=' . $bookingId);
exit;
