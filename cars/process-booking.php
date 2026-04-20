<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

ob_start();

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// VALIDATE POST DATA
// ============================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request method');
    header('Location: /gorwanda-plus/');
    exit;
}

// Get form data
$carId = intval($_POST['car_id'] ?? 0);
$pickupLocation = sanitize($_POST['pickup_location'] ?? '');
$pickupDate = $_POST['pickup_date'] ?? '';
$returnDate = $_POST['return_date'] ?? '';
$days = intval($_POST['days'] ?? 0);

// Guest information
$firstName = sanitize($_POST['first_name'] ?? '');
$lastName = sanitize($_POST['last_name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$licenseNumber = sanitize($_POST['license_number'] ?? '');
$licenseCountry = sanitize($_POST['license_country'] ?? 'Rwanda');
$dateOfBirth = $_POST['date_of_birth'] ?? '';

// Insurance and extras
$insurance = sanitize($_POST['insurance'] ?? 'basic');
$extras = $_POST['extras'] ?? [];
$specialRequests = sanitize($_POST['special_requests'] ?? '');

// Validation
$errors = [];

if (!$carId) $errors[] = 'Car ID is missing';
if (!$pickupLocation) $errors[] = 'Pickup location is required';
if (!$pickupDate) $errors[] = 'Pickup date is required';
if (!$returnDate) $errors[] = 'Return date is required';
if ($days < 1) $errors[] = 'Invalid rental duration';

if (empty($firstName)) $errors[] = 'First name is required';
if (empty($lastName)) $errors[] = 'Last name is required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if (empty($phone)) $errors[] = 'Phone number is required';
if (empty($licenseNumber)) $errors[] = 'Driver\'s license number is required';

// Date validation
$pickupDateTime = DateTime::createFromFormat('Y-m-d', $pickupDate);
$returnDateTime = DateTime::createFromFormat('Y-m-d', $returnDate);
$today = new DateTime('today');

if (!$pickupDateTime || !$returnDateTime) {
    $errors[] = 'Invalid date format';
} else {
    if ($pickupDateTime < $today) {
        $errors[] = 'Pickup date cannot be in the past';
    }
    if ($returnDateTime <= $pickupDateTime) {
        $errors[] = 'Return date must be after pickup date';
    }
    
    // Age validation (must be at least 23 for car rental)
    if (!empty($dateOfBirth)) {
        $dob = new DateTime($dateOfBirth);
        $age = $dob->diff($today)->y;
        if ($age < 23) {
            $errors[] = 'Driver must be at least 23 years old';
        }
    }
}

if (!empty($errors)) {
    $_SESSION['booking_errors'] = $errors;
    $_SESSION['booking_form_data'] = $_POST;
    setFlash('error', 'Please correct the errors in your booking');
    header('Location: /gorwanda-plus/cars/booking.php?car_id=' . $carId . '&pickup_location=' . urlencode($pickupLocation) . '&pickup_date=' . $pickupDate . '&return_date=' . $returnDate);
    exit;
}

// ============================================
// VERIFY CAR AND AVAILABILITY
// ============================================

// Get car details
$stmt = $db->prepare("
    SELECT cf.*, cr.owner_id, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cf.car_id = ? AND cf.is_active = 1
");
$stmt->execute([$carId]);
$car = $stmt->fetch();

if (!$car) {
    setFlash('error', 'Car not found');
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// Check for conflicting bookings
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
    setFlash('error', 'Car is no longer available for the selected dates');
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
    setFlash('error', 'Car is in maintenance for the selected dates');
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// ============================================
// RECALCULATE PRICES (for security)
// ============================================

// Check for seasonal pricing
$stmt = $db->prepare("
    SELECT price_multiplier FROM seasons 
    WHERE vendor_id = (SELECT vendor_id FROM vendor_profiles WHERE user_id = ?)
    AND start_date <= ? AND end_date >= ?
");
$stmt->execute([$car['owner_id'], $pickupDate, $pickupDate]);
$seasonal = $stmt->fetch();
$seasonMultiplier = $seasonal ? $seasonal['price_multiplier'] : 1;

// Calculate base price with weekly/monthly discounts
$dailyRate = $car['daily_rate'] * $seasonMultiplier;
$weeklyRate = $car['weekly_rate'] ? $car['weekly_rate'] * $seasonMultiplier : 0;
$monthlyRate = $car['monthly_rate'] ? $car['monthly_rate'] * $seasonMultiplier : 0;

$calculatedBasePrice = 0;
if ($days >= 30 && $monthlyRate > 0) {
    $months = floor($days / 30);
    $remainingDays = $days % 30;
    $calculatedBasePrice = ($months * $monthlyRate) + ($remainingDays * $dailyRate);
} elseif ($days >= 7 && $weeklyRate > 0) {
    $weeks = floor($days / 7);
    $remainingDays = $days % 7;
    $calculatedBasePrice = ($weeks * $weeklyRate) + ($remainingDays * $dailyRate);
} else {
    $calculatedBasePrice = $days * $dailyRate;
}

// Check for offers
$stmt = $db->prepare("
    SELECT * FROM offers 
    WHERE vendor_id = (SELECT vendor_id FROM vendor_profiles WHERE user_id = ?)
    AND is_active = 1 
    AND start_date <= CURDATE() 
    AND end_date >= CURDATE()
    AND (min_nights IS NULL OR min_nights <= ?)
    AND (applicable_to IS NULL OR JSON_CONTAINS(applicable_to, JSON_QUOTE(CAST(? AS CHAR)), '$'))
    ORDER BY discount_value DESC
    LIMIT 1
");
$stmt->execute([$car['owner_id'], $days, $car['rental_id']]);
$offer = $stmt->fetch();

$discountAmount = 0;
if ($offer) {
    if ($offer['offer_type'] == 'percentage') {
        $discountAmount = $calculatedBasePrice * ($offer['discount_value'] / 100);
    } elseif ($offer['offer_type'] == 'fixed') {
        $discountAmount = min($offer['discount_value'], $calculatedBasePrice);
    } elseif ($offer['offer_type'] == 'free_day') {
        $freeDays = min($offer['discount_value'], $days - 1);
        $discountAmount = $dailyRate * $freeDays;
    }
}

$subtotal = $calculatedBasePrice - $discountAmount;

// Insurance costs
$insuranceCost = 0;
if ($insurance == 'premium') {
    $insuranceCost = 15000 * $days;
} elseif ($insurance == 'full') {
    $insuranceCost = 25000 * $days;
}

// Extras costs
$extrasCost = 0;
$extrasBreakdown = [];
$extrasPrices = [
    'gps' => 5000,
    'child_seat' => 3000,
    'additional_driver' => 10000,
    'wifi' => 4000,
    'roof_rack' => 8000,
    'cooler' => 5000
];

foreach ($extras as $extra) {
    if (isset($extrasPrices[$extra])) {
        $price = $extrasPrices[$extra];
        // Check if it's a daily extra
        if (in_array($extra, ['gps', 'child_seat', 'wifi'])) {
            $price *= $days;
        }
        $extrasCost += $price;
        $extrasBreakdown[] = ['item' => $extra, 'price' => $price];
    }
}

// Calculate totals
$subtotalWithExtras = $subtotal + $insuranceCost + $extrasCost;
$taxAmount = $subtotalWithExtras * 0.18;
$serviceFee = $subtotal * 0.10;
$totalAmount = $subtotalWithExtras + $taxAmount + $serviceFee;

// ============================================
// CREATE BOOKING RECORD
// ============================================

$bookingRef = generateBookingRef();

$db->beginTransaction();

try {
    // Insert booking
    $stmt = $db->prepare("
        INSERT INTO bookings (
            booking_reference, user_id, booking_type, car_id,
            pickup_date, return_date, num_nights, num_guests,
            pickup_location, return_location,
            guest_first_name, guest_last_name, guest_email, guest_phone,
            special_requests, unit_price, total_amount, commission_amount, tax_amount,
            status, payment_status, payment_method, created_at
        ) VALUES (
            ?, ?, 'car_rental', ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW()
        )
    ");
    
    $commissionAmount = $subtotal * 0.15; // 15% commission
    
    $stmt->execute([
        $bookingRef,
        $userId,
        $carId,
        $pickupDate,
        $returnDate,
        $days,
        $pickupLocation,
        $pickupLocation, // return location same as pickup for now
        $firstName,
        $lastName,
        $email,
        $phone,
        $specialRequests,
        $dailyRate, // unit price
        $totalAmount,
        $commissionAmount,
        $taxAmount,
        $insurance // payment method field temporarily stores insurance type
    ]);
    
    $bookingId = $db->lastInsertId();
    
    // Store extras in a separate table or JSON field
    if (!empty($extrasBreakdown)) {
        // You might want to create a booking_extras table
        // For now, we can store in session or a JSON field
        $_SESSION['booking_extras'][$bookingId] = $extrasBreakdown;
    }
    
    // Log activity
    $stmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, 'car_booking_created', 'booking', ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        $bookingId,
        json_encode([
            'car_id' => $carId,
            'days' => $days,
            'amount' => $totalAmount,
            'insurance' => $insurance
        ])
    ]);
    
    $db->commit();
    
    // Clear output buffer
    ob_end_clean();
    
    // Set success message and redirect to payment
    setFlash('success', 'Booking created successfully! Please complete payment.');
    header('Location: /gorwanda-plus/payment.php?booking=' . $bookingRef . '&type=car');
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Car Booking Error: " . $e->getMessage());
    
    setFlash('error', 'An error occurred while processing your booking. Please try again.');
    
    ob_end_clean();
    header('Location: /gorwanda-plus/cars/booking.php?car_id=' . $carId . '&pickup_location=' . urlencode($pickupLocation) . '&pickup_date=' . $pickupDate . '&return_date=' . $returnDate);
    exit;
}