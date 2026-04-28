<?php
require_once 'includes/functions.php';

$bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$bookingId) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'] ?? 0;

// Get booking details
$stmt = $db->prepare("
    SELECT b.*, s.stay_name, sr.room_name
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Payment Successful';
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
    .success-container {
        max-width: 600px;
        margin: 60px auto;
        text-align: center;
        padding: 40px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    .success-icon {
        font-size: 64px;
        color: #008009;
        margin-bottom: 20px;
    }

    .success-title {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 16px;
    }

    .booking-details {
        background: #f5f5f5;
        border-radius: 8px;
        padding: 20px;
        margin: 24px 0;
        text-align: left;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #e7e7e7;
    }

    .btn-view {
        display: inline-block;
        padding: 12px 24px;
        background: #0071c2;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        margin: 8px;
    }

    .btn-home {
        display: inline-block;
        padding: 12px 24px;
        background: #e7e7e7;
        color: #1a1a1a;
        text-decoration: none;
        border-radius: 8px;
        margin: 8px;
    }
</style>

<div class="success-container">
    <div class="success-icon">
        <i class="bi bi-check-circle-fill"></i>
    </div>

    <h1 class="success-title">Payment Successful!</h1>
    <p>Your booking has been confirmed. A confirmation email has been sent to your email address.</p>
    <div class="action-buttons" style="margin-top: 30px;">
        <a href="/gorwanda-plus/download-invoice.php?booking_id=<?php echo $bookingId; ?>" class="btn-action btn-primary" target="_blank">
            <i class="bi bi-download"></i> Download Invoice (PDF)
        </a>
        <a href="/gorwanda-plus/bookings.php" class="btn-action btn-secondary">
            <i class="bi bi-list-ul"></i> View My Bookings
        </a>
    </div>
    <div class="booking-details">
        <div class="detail-row">
            <strong>Booking Reference</strong>
            <span><?php echo $booking['booking_reference']; ?></span>
        </div>
        <div class="detail-row">
            <strong>Property</strong>
            <span><?php echo sanitize($booking['stay_name']); ?></span>
        </div>
        <div class="detail-row">
            <strong>Room</strong>
            <span><?php echo sanitize($booking['room_name']); ?></span>
        </div>
        <div class="detail-row">
            <strong>Check-in</strong>
            <span><?php echo date('F j, Y', strtotime($booking['check_in_date'])); ?></span>
        </div>
        <div class="detail-row">
            <strong>Check-out</strong>
            <span><?php echo date('F j, Y', strtotime($booking['check_out_date'])); ?></span>
        </div>
        <div class="detail-row">
            <strong>Total Paid</strong>
            <span><?php echo formatPrice($booking['total_amount']); ?></span>
        </div>
    </div>

    <a href="stays/detail.php?id=<?php echo $booking['stay_room_id']; ?>" class="btn-view">View Booking</a>
    <a href="index.php" class="btn-home">Back to Home</a>
</div>

<?php require_once 'includes/footer.php'; ?>