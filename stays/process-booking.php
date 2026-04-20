<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

// Start output buffering
ob_start();

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// VALIDATE POST DATA
// ============================================

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request method');
    header('Location: /gorwanda-plus/');
    exit;
}

// Get and validate required fields
$stayId = intval($_POST['stay_id'] ?? 0);
$roomId = intval($_POST['room_id'] ?? 0);
$checkin = $_POST['checkin'] ?? '';
$checkout = $_POST['checkout'] ?? '';
$guests = intval($_POST['guests'] ?? 0);
$nights = intval($_POST['nights'] ?? 0);
$pricePerNight = floatval($_POST['price_per_night'] ?? 0);
$totalAmount = floatval($_POST['total_amount'] ?? 0);

// Guest information
$firstName = sanitize($_POST['first_name'] ?? '');
$lastName = sanitize($_POST['last_name'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$contactMethod = sanitize($_POST['contact_method'] ?? 'email');
$specialRequests = sanitize($_POST['special_requests'] ?? '');
$paymentMethod = sanitize($_POST['payment_method'] ?? 'momo');

// Validate required fields
$errors = [];

if (!$stayId) $errors[] = 'Property ID is missing';
if (!$roomId) $errors[] = 'Room ID is missing';
if (!$checkin) $errors[] = 'Check-in date is missing';
if (!$checkout) $errors[] = 'Check-out date is missing';
if ($guests < 1) $errors[] = 'Number of guests is invalid';
if ($nights < 1) $errors[] = 'Number of nights is invalid';
if ($pricePerNight <= 0) $errors[] = 'Invalid price';
if ($totalAmount <= 0) $errors[] = 'Invalid total amount';

// Validate guest information
if (empty($firstName)) $errors[] = 'First name is required';
if (empty($lastName)) $errors[] = 'Last name is required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if (empty($phone)) $errors[] = 'Phone number is required';

// Validate dates
$checkinDate = DateTime::createFromFormat('Y-m-d', $checkin);
$checkoutDate = DateTime::createFromFormat('Y-m-d', $checkout);
$today = new DateTime('today');

if (!$checkinDate || !$checkoutDate) {
    $errors[] = 'Invalid date format';
} else {
    if ($checkinDate < $today) {
        $errors[] = 'Check-in date cannot be in the past';
    }
    if ($checkoutDate <= $checkinDate) {
        $errors[] = 'Check-out date must be after check-in date';
    }
}

// If there are errors, redirect back
if (!empty($errors)) {
    $_SESSION['booking_errors'] = $errors;
    $_SESSION['booking_form_data'] = $_POST;
    setFlash('error', 'Please correct the errors in your booking');
    header('Location: /gorwanda-plus/stays/booking.php?id=' . $stayId . '&room=' . $roomId . '&checkin=' . $checkin . '&checkout=' . $checkout . '&guests=' . $guests);
    exit;
}

// ============================================
// VERIFY OWNERSHIP AND AVAILABILITY
// ============================================

// Verify the room exists and belongs to the stay
$stmt = $db->prepare("
    SELECT s.*, sr.*, u.user_id as owner_id
    FROM stays s
    JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ? AND sr.room_id = ? AND s.is_active = 1
");
$stmt->execute([$stayId, $roomId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Room not found or unavailable');
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Check if room is available for the selected dates
$stmt = $db->prepare("
    SELECT COUNT(*) as blocked
    FROM stay_availability sa
    WHERE sa.room_id = ? 
    AND sa.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
    AND (sa.is_blocked = 1 OR sa.rooms_available < 1)
");
$stmt->execute([$roomId, $checkin, $checkout]);
$availability = $stmt->fetch();

if ($availability['blocked'] > 0) {
    setFlash('error', 'Room is no longer available for the selected dates');
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Check for existing bookings that might conflict
$stmt = $db->prepare("
    SELECT COUNT(*) as conflicting
    FROM bookings b
    WHERE b.stay_room_id = ?
    AND b.status IN ('confirmed', 'pending')
    AND (
        (b.check_in_date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY))
        OR (b.check_out_date BETWEEN ? AND ?)
        OR (? BETWEEN b.check_in_date AND DATE_SUB(b.check_out_date, INTERVAL 1 DAY))
    )
");
$stmt->execute([$roomId, $checkin, $checkout, $checkin, $checkout, $checkin]);
$conflicting = $stmt->fetch();

if ($conflicting['conflicting'] > 0) {
    setFlash('error', 'This room is already booked for the selected dates');
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
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
$stmt->execute([$booking['owner_id'], $checkin, $checkin]);
$seasonal = $stmt->fetch();
$seasonMultiplier = $seasonal ? $seasonal['price_multiplier'] : 1;

// Check for special price override
$stmt = $db->prepare("
    SELECT price_override FROM stay_availability 
    WHERE room_id = ? AND date BETWEEN ? AND ? AND price_override IS NOT NULL
    ORDER BY price_override ASC LIMIT 1
");
$stmt->execute([$roomId, $checkin, date('Y-m-d', strtotime($checkout . ' -1 day'))]);
$specialPrice = $stmt->fetch();

// Calculate base price
$basePrice = $booking['base_price'];
$calculatedPricePerNight = $specialPrice ? $specialPrice['price_override'] : ($basePrice * $seasonMultiplier);
$calculatedTotalRoomPrice = $calculatedPricePerNight * $nights;

// Check for active offers
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
$stmt->execute([$booking['owner_id'], $nights, $stayId]);
$offer = $stmt->fetch();

// Apply offer discount
$calculatedDiscountAmount = 0;
if ($offer) {
    if ($offer['offer_type'] == 'percentage') {
        $calculatedDiscountAmount = $calculatedTotalRoomPrice * ($offer['discount_value'] / 100);
    } elseif ($offer['offer_type'] == 'fixed') {
        $calculatedDiscountAmount = min($offer['discount_value'], $calculatedTotalRoomPrice);
    } elseif ($offer['offer_type'] == 'free_night') {
        $freeNights = min($offer['discount_value'], $nights - 1);
        $calculatedDiscountAmount = $calculatedPricePerNight * $freeNights;
    }
}

$calculatedSubtotal = $calculatedTotalRoomPrice - $calculatedDiscountAmount;
$calculatedTaxAmount = $calculatedSubtotal * 0.18; // 18% VAT
$calculatedServiceFee = $calculatedSubtotal * 0.10; // 10% service fee
$calculatedTotalAmount = $calculatedSubtotal + $calculatedTaxAmount + $calculatedServiceFee;

// Verify that the calculated total matches what was sent (with small tolerance for floating point)
if (abs($calculatedTotalAmount - $totalAmount) > 1) {
    // Log the discrepancy
    error_log("Price mismatch: Client sent $totalAmount, server calculated $calculatedTotalAmount");
    
    // Use server-calculated price for security
    $totalAmount = $calculatedTotalAmount;
    $pricePerNight = $calculatedPricePerNight;
}

// ============================================
// CREATE BOOKING RECORD
// ============================================

// Generate unique booking reference
$bookingRef = generateBookingRef();

// Start transaction
$db->beginTransaction();

try {
    // Insert booking
    $stmt = $db->prepare("
        INSERT INTO bookings (
            booking_reference, user_id, booking_type, stay_room_id,
            check_in_date, check_out_date, num_nights, num_guests,
            guest_first_name, guest_last_name, guest_email, guest_phone,
            special_requests, unit_price, total_amount, commission_amount, tax_amount,
            status, payment_status, payment_method, created_at
        ) VALUES (
            ?, ?, 'stay', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW()
        )
    ");
    
    $commissionAmount = $calculatedSubtotal * 0.15; // 15% commission
    
    $stmt->execute([
        $bookingRef,
        $userId,
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
        $calculatedPricePerNight,
        $totalAmount,
        $commissionAmount,
        $calculatedTaxAmount,
        $paymentMethod
    ]);
    
    $bookingId = $db->lastInsertId();
    
    // Update stay_availability if we have a booking (reduce available rooms)
    // For now, we'll rely on the booking to show unavailability
    // Future enhancement: Decrement num_rooms_available
    
    // Log the booking creation
    $stmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, created_at)
        VALUES (?, 'booking_created', 'booking', ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        $bookingId,
        json_encode([
            'stay_id' => $stayId,
            'room_id' => $roomId,
            'amount' => $totalAmount,
            'nights' => $nights
        ])
    ]);
    
    // Commit transaction
    $db->commit();
    
    // ============================================
    // SEND CONFIRMATION EMAIL
    // ============================================
    
    // Prepare email content (in production, use a proper mail library)
    $to = $email;
    $subject = "Booking Confirmation - " . $bookingRef;
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #003b95; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 30px; background: #f5f5f5; }
            .booking-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e7e7e7; }
            .total-row { font-weight: bold; font-size: 1.2em; color: #003b95; }
            .button { display: inline-block; padding: 12px 24px; background: #0066ff; color: white; text-decoration: none; border-radius: 4px; }
            .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Booking Confirmed!</h2>
                <p>Reference: " . $bookingRef . "</p>
            </div>
            <div class='content'>
                <p>Dear " . $firstName . " " . $lastName . ",</p>
                <p>Your booking has been confirmed. Thank you for choosing GoRwanda+!</p>
                
                <div class='booking-details'>
                    <h3>Booking Details</h3>
                    <div class='detail-row'><span>Property:</span> <strong>" . sanitize($booking['stay_name']) . "</strong></div>
                    <div class='detail-row'><span>Room:</span> <strong>" . sanitize($booking['room_name']) . "</strong></div>
                    <div class='detail-row'><span>Check-in:</span> <strong>" . date('l, F j, Y', strtotime($checkin)) . "</strong></div>
                    <div class='detail-row'><span>Check-out:</span> <strong>" . date('l, F j, Y', strtotime($checkout)) . "</strong></div>
                    <div class='detail-row'><span>Nights:</span> <strong>" . $nights . "</strong></div>
                    <div class='detail-row'><span>Guests:</span> <strong>" . $guests . "</strong></div>
                    <div class='detail-row total-row'><span>Total Amount:</span> <strong>" . formatPrice($totalAmount) . "</strong></div>
                </div>
                
                <p>Payment method: <strong>" . strtoupper($paymentMethod) . "</strong></p>
                <p>You will receive a separate payment confirmation once your payment is processed.</p>
                
                <p style='text-align: center; margin-top: 30px;'>
                    <a href='http://localhost/gorwanda-plus/booking-confirmation.php?ref=" . $bookingRef . "' class='button'>View Your Booking</a>
                </p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " GoRwanda+. All rights reserved.</p>
                <p>Kigali, Rwanda</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // In production, uncomment this to actually send emails
    /*
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: bookings@gorwanda.rw' . "\r\n";
    mail($to, $subject, $message, $headers);
    */
    
    // For development, log the email
    $logFile = dirname(__DIR__) . '/logs/bookings.log';
    $logEntry = date('Y-m-d H:i:s') . " - Booking $bookingRef created for user $userId\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    // ============================================
    // REDIRECT TO PAYMENT
    // ============================================
    
    // Clear any output buffers
    ob_end_clean();
    
    // Set success message
    setFlash('success', 'Booking created successfully! Please complete payment.');
    
    // Redirect to payment page
    header('Location: /gorwanda-plus/payment.php?booking=' . $bookingRef);
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    // Log error
    error_log("Booking Error: " . $e->getMessage());
    
    // Set error message
    setFlash('error', 'An error occurred while processing your booking. Please try again.');
    
    // Redirect back to booking page
    ob_end_clean();
    header('Location: /gorwanda-plus/stays/booking.php?id=' . $stayId . '&room=' . $roomId . '&checkin=' . $checkin . '&checkout=' . $checkout . '&guests=' . $guests);
    exit;
}