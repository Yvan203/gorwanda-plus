<?php
require_once '../includes/functions.php';
require_once '../includes/notifications.php';

// Check if user is logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /gorwanda-plus/login.php');
    exit;
}

// Get booking parameters
$stayId = intval($_GET['id'] ?? 0);
$roomId = intval($_GET['room'] ?? 0);
$checkin = $_GET['checkin'] ?? '';
$checkout = $_GET['checkout'] ?? '';
$guests = intval($_GET['guests'] ?? 2);

// Validate required parameters
if (!$stayId || !$roomId || !$checkin || !$checkout) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

// Get stay details
$stmt = $db->prepare("
    SELECT s.*, u.first_name as owner_first, u.last_name as owner_last, u.email as owner_email, u.phone as owner_phone
    FROM stays s
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ? AND s.is_active = 1 AND s.is_verified = 1
");
$stmt->execute([$stayId]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Get room details
$stmt = $db->prepare("
    SELECT sr.*
    FROM stay_rooms sr
    WHERE sr.room_id = ? AND sr.stay_id = ? AND sr.is_active = 1
");
$stmt->execute([$roomId, $stayId]);
$room = $stmt->fetch();

if (!$room) {
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Validate dates
$checkinDate = new DateTime($checkin);
$checkoutDate = new DateTime($checkout);
$today = new DateTime();

if ($checkinDate < $today) {
    $_SESSION['error'] = "Check-in date cannot be in the past";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

if ($checkoutDate <= $checkinDate) {
    $_SESSION['error'] = "Check-out date must be after check-in date";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

$nights = $checkinDate->diff($checkoutDate)->days;

// Validate guest count
if ($guests > $room['max_guests']) {
    $_SESSION['error'] = "Maximum " . $room['max_guests'] . " guests allowed for this room";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Check room availability for selected dates
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
    $_SESSION['error'] = "This room is not available for selected dates. Please choose different dates.";
    header('Location: /gorwanda-plus/stays/detail.php?id=' . $stayId);
    exit;
}

// Get price with any special pricing for these dates
$stmt = $db->prepare("
    SELECT price_override 
    FROM stay_availability 
    WHERE room_id = ? AND date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
    AND price_override IS NOT NULL
    LIMIT 1
");
$stmt->execute([$roomId, $checkin, $checkout]);
$specialPrice = $stmt->fetchColumn();

$basePrice = $specialPrice ?: $room['base_price'];
$subtotal = $basePrice * $nights;

// Get tax settings (from database or session)
$taxRate = 18; // Default
$taxName = "VAT";

// Try to get from session (set in admin)
if (isset($_SESSION['tax_settings'])) {
    $taxRate = $_SESSION['tax_settings']['tax_rate'] ?? 18;
    $taxName = $_SESSION['tax_settings']['tax_name'] ?? 'VAT';
} else {
    // Try to get from database if there's a settings table
    try {
        $stmt = $db->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'tax_rate'");
        $dbTax = $stmt->fetchColumn();
        if ($dbTax) $taxRate = $dbTax;
    } catch (Exception $e) {
        // Table might not exist, use default
    }
}

$taxAmount = $subtotal * ($taxRate / 100);
$totalAmount = $subtotal + $taxAmount;

$pageTitle = "Complete Your Booking - " . $stay['stay_name'];
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* Booking Page Styles - Booking.com Inspired */
    :root {
        --booking-blue: #003580;
        --booking-blue-light: #0071c2;
        --booking-yellow: #febb02;
        --booking-success: #008009;
        --booking-danger: #c41c1c;
        --booking-gray-100: #f5f5f5;
        --booking-gray-200: #e7e7e7;
        --booking-gray-500: #6b6b6b;
        --booking-gray-700: #1a1a1a;
        --booking-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        --booking-shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.12);
    }

    .booking-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 24px 20px;
    }

    /* Breadcrumb */
    .breadcrumb {
        margin-bottom: 24px;
        font-size: 13px;
    }

    .breadcrumb a {
        color: var(--booking-blue-light);
        text-decoration: none;
    }

    /* Progress Steps */
    .progress-steps {
        display: flex;
        justify-content: center;
        margin-bottom: 32px;
    }

    .step {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        position: relative;
    }

    .step.active .step-number {
        background: var(--booking-blue-light);
        color: white;
        border-color: var(--booking-blue-light);
    }

    .step.completed .step-number {
        background: var(--booking-success);
        color: white;
        border-color: var(--booking-success);
    }

    .step-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 2px solid var(--booking-gray-200);
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
    }

    .step-label {
        font-size: 14px;
        font-weight: 500;
    }

    /* Layout */
    .booking-layout {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 32px;
    }

    /* Main Content */
    .booking-main {
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 12px;
        overflow: hidden;
    }

    /* Section */
    .booking-section {
        padding: 24px;
        border-bottom: 1px solid var(--booking-gray-200);
    }

    .booking-section:last-child {
        border-bottom: none;
    }

    .section-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Form Styles */
    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--booking-gray-700);
        margin-bottom: 6px;
    }

    .form-control {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--booking-gray-200);
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--booking-blue-light);
        box-shadow: 0 0 0 2px rgba(0, 113, 194, 0.1);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }

    /* Stay Summary Card */
    .stay-summary {
        display: flex;
        gap: 16px;
        padding: 16px;
        background: var(--booking-gray-100);
        border-radius: 12px;
        margin-bottom: 24px;
    }

    .stay-image {
        width: 100px;
        height: 100px;
        border-radius: 8px;
        object-fit: cover;
    }

    .stay-details h3 {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .stay-details p {
        font-size: 13px;
        color: var(--booking-gray-500);
        margin-bottom: 4px;
    }

    /* Room Details */
    .room-details {
        background: var(--booking-gray-100);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 24px;
    }

    .room-name {
        font-weight: 700;
        font-size: 16px;
        margin-bottom: 8px;
    }

    .room-features {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        font-size: 13px;
        color: var(--booking-gray-500);
    }

    /* Guest Info Summary */
    .guest-summary {
        background: var(--booking-gray-100);
        border-radius: 12px;
        padding: 16px;
    }

    .guest-info-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid var(--booking-gray-200);
    }

    .guest-info-row:last-child {
        border-bottom: none;
    }

    /* Booking Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .price-card {
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: var(--booking-shadow-lg);
    }

    .price-breakdown {
        margin-bottom: 20px;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        font-size: 14px;
    }

    .price-row.total {
        border-top: 1px solid var(--booking-gray-200);
        margin-top: 8px;
        padding-top: 16px;
        font-weight: 700;
        font-size: 18px;
    }

    .price-row.tax {
        color: var(--booking-gray-500);
        font-size: 12px;
    }

    .price-value {
        font-weight: 600;
    }

    .confirm-btn {
        width: 100%;
        padding: 14px;
        background: var(--booking-blue-light);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
        margin-top: 20px;
    }

    .confirm-btn:hover {
        background: var(--booking-blue);
    }

    .cancel-btn {
        width: 100%;
        padding: 12px;
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 12px;
        text-decoration: none;
        display: block;
        text-align: center;
        color: var(--booking-gray-500);
    }

    .cancel-btn:hover {
        border-color: var(--booking-danger);
        color: var(--booking-danger);
    }

    .security-note {
        text-align: center;
        font-size: 12px;
        color: var(--booking-gray-500);
        margin-top: 16px;
    }

    /* Alert */
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .alert-error {
        background: #fce8e8;
        color: var(--booking-danger);
        border: 1px solid rgba(196, 28, 28, 0.2);
    }

    /* Responsive */
    @media (max-width: 992px) {
        .booking-layout {
            grid-template-columns: 1fr;
        }

        .booking-sidebar {
            position: static;
        }
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .progress-steps {
            display: none;
        }

        .stay-summary {
            flex-direction: column;
        }

        .stay-image {
            width: 100%;
            height: 150px;
        }
    }
</style>

<div class="booking-container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/gorwanda-plus/">Home</a> &gt;
        <a href="/gorwanda-plus/stays/">Stays</a> &gt;
        <a href="/gorwanda-plus/stays/detail.php?id=<?php echo $stayId; ?>"><?php echo sanitize($stay['stay_name']); ?></a> &gt;
        <span>Complete Booking</span>
    </div>

    <!-- Progress Steps -->
    <div class="progress-steps">
        <div class="step completed">
            <div class="step-number"><i class="bi bi-check-lg"></i></div>
            <span class="step-label">Select room</span>
        </div>
        <div class="step active">
            <div class="step-number">2</div>
            <span class="step-label">Complete details</span>
        </div>
        <div class="step">
            <div class="step-number">3</div>
            <span class="step-label">Payment</span>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="booking-layout">
        <!-- Main Content -->
        <div class="booking-main">
            <!-- Stay & Room Summary -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-building" style="color: var(--booking-blue-light);"></i>
                    Your stay
                </h2>
                <div class="stay-summary">
                    <img src="<?php echo getImageUrl($stay['main_image'] ?? '', 'stay'); ?>"
                        alt="<?php echo sanitize($stay['stay_name']); ?>"
                        class="stay-image"
                        onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=200&q=60'">
                    <div class="stay-details">
                        <h3><?php echo sanitize($stay['stay_name']); ?></h3>
                        <p><i class="bi bi-geo-alt"></i> <?php echo sanitize($stay['address']); ?></p>
                        <p><i class="bi bi-star-fill" style="color: #febb02;"></i> <?php echo $stay['star_rating'] ?: 'Not rated'; ?>星级</p>
                    </div>
                </div>

                <div class="room-details">
                    <div class="room-name"><?php echo sanitize($room['room_name']); ?></div>
                    <div class="room-features">
                        <span><i class="bi bi-people"></i> <?php echo $guests; ?> guests</span>
                        <span><i class="bi bi-bed"></i> <?php echo $room['bed_configuration'] ?: 'Queen bed'; ?></span>
                        <?php if ($room['size_sqm']): ?>
                            <span><i class="bi bi-rulers"></i> <?php echo $room['size_sqm']; ?> m²</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="guest-summary">
                    <div class="guest-info-row">
                        <span>Check-in</span>
                        <span class="price-value"><?php echo date('l, F j, Y', strtotime($checkin)); ?> (from <?php echo date('h:i A', strtotime($stay['check_in_time'])); ?>)</span>
                    </div>
                    <div class="guest-info-row">
                        <span>Check-out</span>
                        <span class="price-value"><?php echo date('l, F j, Y', strtotime($checkout)); ?> (until <?php echo date('h:i A', strtotime($stay['check_out_time'])); ?>)</span>
                    </div>
                    <div class="guest-info-row">
                        <span>Length of stay</span>
                        <span class="price-value"><?php echo $nights; ?> night<?php echo $nights > 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="guest-info-row">
                        <span>Guests</span>
                        <span class="price-value"><?php echo $guests; ?> adult<?php echo $guests > 1 ? 's' : ''; ?></span>
                    </div>
                </div>
            </div>

            <!-- Guest Information Form -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-person" style="color: var(--booking-blue-light);"></i>
                    Guest information
                </h2>

                <form method="POST" action="process-booking.php" id="bookingForm">
                    <input type="hidden" name="stay_id" value="<?php echo $stayId; ?>">
                    <input type="hidden" name="room_id" value="<?php echo $roomId; ?>">
                    <input type="hidden" name="checkin" value="<?php echo $checkin; ?>">
                    <input type="hidden" name="checkout" value="<?php echo $checkout; ?>">
                    <input type="hidden" name="guests" value="<?php echo $guests; ?>">
                    <input type="hidden" name="nights" value="<?php echo $nights; ?>">
                    <input type="hidden" name="base_price" value="<?php echo $basePrice; ?>">
                    <input type="hidden" name="subtotal" value="<?php echo $subtotal; ?>">
                    <input type="hidden" name="tax_rate" value="<?php echo $taxRate; ?>">
                    <input type="hidden" name="tax_amount" value="<?php echo $taxAmount; ?>">
                    <input type="hidden" name="total_amount" value="<?php echo $totalAmount; ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>First name *</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($currentUser['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Last name *</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($currentUser['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email address *</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone number *</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($currentUser['phone']); ?>" required>
                        </div>
                    </div>

                    <!-- Special Requests -->
                    <div class="form-group">
                        <label>Special requests (optional)</label>
                        <textarea name="special_requests" class="form-control" placeholder="e.g., late check-in, extra pillows, room preference..."></textarea>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="booking-sidebar">
            <!-- Price Breakdown -->
            <!-- Price Breakdown -->
            <div class="price-card">
                <h3 style="font-size: 18px; margin-bottom: 16px;">Price breakdown</h3>

                <?php
                // Calculate breakdown
                $breakdown = getPriceBreakdown($basePrice, $nights);
                ?>

                <div class="price-breakdown">
                    <div class="price-row">
                        <span><?php echo sanitize($room['room_name']); ?> (<?php echo $nights; ?> nights)</span>
                        <span class="price-value"><?php echo formatPrice($breakdown['subtotal']); ?></span>
                    </div>

                    <div class="price-row total">
                        <span>Total (tax included)</span>
                        <span class="price-value"><?php echo formatPrice($breakdown['total']); ?></span>
                    </div>

                    <div class="price-row tax" style="font-size: 11px; color: #6b6b6b; padding-top: 8px;">
                        <span>Includes <?php echo $breakdown['tax_rate']; ?>% <?php echo $breakdown['tax_name']; ?></span>
                        <span><?php echo formatPrice($breakdown['tax_amount']); ?></span>
                    </div>
                </div>

                <div style="background: var(--booking-gray-100); border-radius: 8px; padding: 12px; margin: 16px 0;">
                    <div style="font-size: 12px; color: var(--booking-gray-500);">
                        <i class="bi bi-check-circle-fill" style="color: var(--booking-success); font-size: 14px;"></i>
                        Free cancellation up to 24 hours before check-in
                    </div>
                </div>

                <button type="submit" form="bookingForm" class="confirm-btn" onclick="return confirmBooking()">
                    Proceed to Payment
                </button>

                <a href="/gorwanda-plus/stays/detail.php?id=<?php echo $stayId; ?>" class="cancel-btn">
                    Cancel and go back
                </a>

                <div class="security-note">
                    <i class="bi bi-shield-lock"></i> Your payment is secure<br>
                    <i class="bi bi-credit-card"></i> No fees charged yet
                </div>
            </div>
            <!-- Why Book With Us -->
            <div class="price-card" style="background: var(--booking-gray-100);">
                <h3 style="font-size: 16px; margin-bottom: 12px;">Why book with GoRwanda+?</h3>
                <div style="font-size: 13px; color: var(--booking-gray-500);">
                    <div style="margin-bottom: 8px;"><i class="bi bi-check-circle-fill" style="color: var(--booking-success);"></i> Best price guarantee</div>
                    <div style="margin-bottom: 8px;"><i class="bi bi-check-circle-fill" style="color: var(--booking-success);"></i> Secure payment</div>
                    <div style="margin-bottom: 8px;"><i class="bi bi-check-circle-fill" style="color: var(--booking-success);"></i> 24/7 customer support</div>
                    <div><i class="bi bi-check-circle-fill" style="color: var(--booking-success);"></i> Verified reviews from real guests</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmBooking() {
        const firstName = document.querySelector('input[name="first_name"]').value;
        const lastName = document.querySelector('input[name="last_name"]').value;
        const email = document.querySelector('input[name="email"]').value;
        const phone = document.querySelector('input[name="phone"]').value;

        if (!firstName || !lastName || !email || !phone) {
            alert('Please fill in all required fields');
            return false;
        }

        if (!email.includes('@')) {
            alert('Please enter a valid email address');
            return false;
        }

        return confirm('Please verify your booking details before proceeding to payment.');
    }
</script>

<?php require_once '../includes/footer.php'; ?>