<?php
require_once 'includes/functions.php';
require_once 'includes/pdf_invoice.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$bookingId) {
    header('Location: /gorwanda-plus/bookings.php');
    exit;
}

// Verify booking belongs to current user
$db = getDB();
$stmt = $db->prepare("SELECT user_id FROM bookings WHERE booking_id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking || $booking['user_id'] != $_SESSION['user_id']) {
    header('Location: /gorwanda-plus/bookings.php');
    exit;
}

// Generate and download PDF
generatePDFInvoice($bookingId);
