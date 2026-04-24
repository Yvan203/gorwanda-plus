<?php
require_once 'includes/functions.php';
require_once 'config/stripe.php';
require_once 'includes/notifications.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please login first']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$paymentMethodId = $input['payment_method_id'] ?? '';
$bookingId = intval($input['booking_id'] ?? 0);
$amount = floatval($input['amount'] ?? 0);

if (!$paymentMethodId || !$bookingId || !$amount) {
    echo json_encode(['success' => false, 'error' => 'Missing required information']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get booking details
$stmt = $db->prepare("
    SELECT b.*, u.email, u.first_name, u.last_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE b.booking_id = ? AND b.user_id = ? AND b.payment_status = 'pending'
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['success' => false, 'error' => 'Booking not found']);
    exit;
}

// For RWF (no decimals), send the amount as is
$amountInCents = round($amount);

try {
    // Create payment intent - simpler version
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $amountInCents,
        'currency' => 'rwf',
        'payment_method' => $paymentMethodId,
        'confirm' => true,
        'automatic_payment_methods' => [
            'enabled' => true,
            'allow_redirects' => 'never'
        ],
        'description' => 'Booking #' . $booking['booking_reference'],
        'metadata' => [
            'booking_id' => $bookingId,
            'booking_reference' => $booking['booking_reference'],
            'user_id' => $userId
        ]
    ]);

    // Update booking payment status
    $stmt = $db->prepare("
        UPDATE bookings 
        SET payment_status = 'paid', 
            payment_method = 'card',
            payment_reference = ?,
            status = 'confirmed',
            updated_at = NOW()
        WHERE booking_id = ?
    ");
    $stmt->execute([$paymentIntent->id, $bookingId]);

    // Create notification for admin
    $notificationManager = new NotificationManager();

    // Notify admin
    $notificationManager->paymentReceived($bookingId, 1, [
        'reference' => $booking['booking_reference'],
        'amount' => formatPrice($amount),
        'method' => 'Credit Card'
    ]);

    // Notify property owner
    $stmt = $db->prepare("SELECT owner_id FROM stays WHERE stay_id = (SELECT stay_id FROM stay_rooms WHERE room_id = ?)");
    $stmt->execute([$booking['stay_room_id']]);
    $ownerId = $stmt->fetchColumn();

    if ($ownerId) {
        $notificationManager->paymentReceived($bookingId, $ownerId, [
            'reference' => $booking['booking_reference'],
            'amount' => formatPrice($amount),
            'method' => 'Credit Card'
        ]);
    }

    echo json_encode(['success' => true, 'payment_intent' => $paymentIntent]);
} catch (\Stripe\Exception\CardException $e) {
    echo json_encode(['success' => false, 'error' => $e->getError()->message]);
} catch (\Stripe\Exception\RateLimitException $e) {
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
} catch (\Stripe\Exception\InvalidRequestException $e) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters: ' . $e->getMessage()]);
} catch (\Stripe\Exception\AuthenticationException $e) {
    echo json_encode(['success' => false, 'error' => 'Authentication failed. Please contact support.']);
} catch (\Stripe\Exception\ApiConnectionException $e) {
    echo json_encode(['success' => false, 'error' => 'Network error. Please try again.']);
} catch (\Stripe\Exception\ApiErrorException $e) {
    echo json_encode(['success' => false, 'error' => 'Payment service error. Please try again later.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
}
