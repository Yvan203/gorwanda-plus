<?php
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /gorwanda-plus/login.php');
    exit;
}

// Get booking parameters
$carId = intval($_GET['car_id'] ?? 0);
$pickupDate = $_GET['pickup_date'] ?? '';
$returnDate = $_GET['return_date'] ?? '';
$pickupLocation = isset($_GET['pickup_location']) ? sanitize($_GET['pickup_location']) : '';

// Validate required parameters
if (!$carId || !$pickupDate || !$returnDate || !$pickupLocation) {
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

// Get car details
$stmt = $db->prepare("
    SELECT cf.*, cr.company_name, cr.phone as company_phone, cr.email as company_email,
           cr.pickup_locations, cr.dropoff_locations, cr.is_verified
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cf.car_id = ? AND cf.is_active = 1 AND cr.is_active = 1
");
$stmt->execute([$carId]);
$car = $stmt->fetch();

if (!$car) {
    header('Location: /gorwanda-plus/?type=cars');
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

$days = $pickupDateTime->diff($returnDateTime)->days;
if ($days == 0) $days = 1;

// Check availability again (double-check)
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
    $_SESSION['error'] = "This vehicle is no longer available for selected dates";
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Calculate prices with tax
$dailyRate = $car['daily_rate'];
$subtotal = $dailyRate * $days;
$taxRate = getTaxRate();
$taxAmount = $subtotal * ($taxRate / 100);
$totalAmount = $subtotal + $taxAmount;

$pageTitle = "Complete Your Booking - " . $car['brand'] . ' ' . $car['model'];
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* Booking Page Styles - Booking.com Inspired */
    .booking-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 20px;
    }

    .breadcrumb {
        margin-bottom: 24px;
        font-size: 13px;
    }

    .breadcrumb a {
        color: #0071c2;
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
    }

    .step.active .step-number {
        background: #0071c2;
        color: white;
        border-color: #0071c2;
    }

    .step.completed .step-number {
        background: #008009;
        color: white;
        border-color: #008009;
    }

    .step-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 2px solid #e7e7e7;
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
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        overflow: hidden;
    }

    .booking-section {
        padding: 24px;
        border-bottom: 1px solid #e7e7e7;
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

    .section-title i {
        color: #0071c2;
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
        color: #1a1a1a;
        margin-bottom: 6px;
    }

    .form-control {
        width: 100%;
        padding: 12px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: #0071c2;
        box-shadow: 0 0 0 3px rgba(0, 113, 194, 0.1);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 100px;
    }

    /* Car Summary Card */
    .car-summary {
        display: flex;
        gap: 16px;
        padding: 16px;
        background: #f5f5f5;
        border-radius: 12px;
        margin-bottom: 24px;
    }

    .car-image {
        width: 100px;
        height: 100px;
        border-radius: 8px;
        object-fit: cover;
    }

    .car-details h3 {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .car-details p {
        font-size: 13px;
        color: #6b6b6b;
        margin-bottom: 4px;
    }

    /* Rental Details */
    .rental-details {
        background: #f5f5f5;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 24px;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #e7e7e7;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    /* Booking Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .price-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
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
        border-top: 1px solid #e7e7e7;
        margin-top: 8px;
        padding-top: 16px;
        font-weight: 700;
        font-size: 18px;
    }

    .price-row.tax {
        color: #6b6b6b;
        font-size: 12px;
    }

    .confirm-btn {
        width: 100%;
        padding: 14px;
        background: #0071c2;
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
        background: #003580;
    }

    .cancel-btn {
        width: 100%;
        padding: 12px;
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 12px;
        text-decoration: none;
        display: block;
        text-align: center;
        color: #6b6b6b;
    }

    .cancel-btn:hover {
        border-color: #c41c1c;
        color: #c41c1c;
    }

    .security-note {
        text-align: center;
        font-size: 12px;
        color: #6b6b6b;
        margin-top: 16px;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .booking-layout {
            grid-template-columns: 1fr;
        }

        .booking-sidebar {
            position: static;
        }

        .progress-steps {
            display: none;
        }
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .car-summary {
            flex-direction: column;
        }

        .car-image {
            width: 100%;
            height: 150px;
        }
    }
</style>

<div class="booking-container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/gorwanda-plus/">Home</a> &gt;
        <a href="/gorwanda-plus/?type=cars">Cars</a> &gt;
        <a href="/gorwanda-plus/cars/detail.php?id=<?php echo $carId; ?>"><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></a> &gt;
        <span>Complete Booking</span>
    </div>

    <!-- Progress Steps -->
    <div class="progress-steps">
        <div class="step completed">
            <div class="step-number"><i class="bi bi-check-lg"></i></div>
            <span class="step-label">Select car</span>
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
            <!-- Car & Rental Summary -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-car-front"></i>
                    Your rental
                </h2>

                <div class="car-summary">
                    <?php
                    $carImages = json_decode($car['images'] ?? '[]', true);
                    $carImage = $carImages[0] ?? '';
                    ?>
                    <img src="<?php echo getImageUrl($carImage, 'car'); ?>"
                        alt="<?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>"
                        class="car-image"
                        onerror="this.src='https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=200&q=60'">
                    <div class="car-details">
                        <h3><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></h3>
                        <p><i class="bi bi-building"></i> <?php echo sanitize($car['company_name']); ?></p>
                        <p><i class="bi bi-gear"></i> <?php echo ucfirst($car['transmission']); ?> • <i class="bi bi-fuel-pump"></i> <?php echo ucfirst($car['fuel_type']); ?> • <i class="bi bi-people"></i> <?php echo $car['seats']; ?> seats</p>
                    </div>
                </div>

                <div class="rental-details">
                    <div class="detail-row">
                        <span>Pickup Location</span>
                        <span class="price-value"><strong><?php echo sanitize($pickupLocation); ?></strong></span>
                    </div>
                    <div class="detail-row">
                        <span>Pickup Date</span>
                        <span class="price-value"><?php echo date('l, F j, Y', strtotime($pickupDate)); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Return Date</span>
                        <span class="price-value"><?php echo date('l, F j, Y', strtotime($returnDate)); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Duration</span>
                        <span class="price-value"><?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?></span>
                    </div>
                </div>
            </div>

            <!-- Guest Information Form -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-person"></i>
                    Driver information
                </h2>

                <form method="POST" action="process-booking.php" id="bookingForm">
                    <input type="hidden" name="car_id" value="<?php echo $carId; ?>">
                    <input type="hidden" name="pickup_date" value="<?php echo $pickupDate; ?>">
                    <input type="hidden" name="return_date" value="<?php echo $returnDate; ?>">
                    <input type="hidden" name="pickup_location" value="<?php echo sanitize($pickupLocation); ?>">
                    <input type="hidden" name="days" value="<?php echo $days; ?>">
                    <input type="hidden" name="daily_rate" value="<?php echo $dailyRate; ?>">
                    <input type="hidden" name="subtotal" value="<?php echo $subtotal; ?>">
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

                    <div class="form-group">
                        <label>Driver's license number (optional)</label>
                        <input type="text" name="license_number" class="form-control" placeholder="e.g., DL12345678">
                    </div>

                    <div class="form-group">
                        <label>Special requests (optional)</label>
                        <textarea name="special_requests" class="form-control" placeholder="e.g., child seat, GPS, extra driver..."></textarea>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sidebar - Price Summary -->
        <div class="booking-sidebar">
            <div class="price-card">
                <h3 style="font-size: 18px; margin-bottom: 16px;">Price breakdown</h3>

                <div class="price-breakdown">
                    <div class="price-row">
                        <span>Car rental (<?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?>)</span>
                        <span><?php echo formatPrice($subtotal); ?></span>
                    </div>
                    <div class="price-row tax">
                        <span>VAT (<?php echo $taxRate; ?>%)</span>
                        <span><?php echo formatPrice($taxAmount); ?></span>
                    </div>
                    <div class="price-row total">
                        <span>Total you pay</span>
                        <span><?php echo formatPrice($totalAmount); ?></span>
                    </div>
                </div>

                <div style="background: #f5f5f5; border-radius: 8px; padding: 12px; margin: 16px 0;">
                    <div style="font-size: 12px; color: #6b6b6b;">
                        <i class="bi bi-check-circle-fill" style="color: #008009; font-size: 14px;"></i>
                        Free cancellation up to 24 hours before pickup
                    </div>
                </div>

                <button type="submit" form="bookingForm" class="confirm-btn" onclick="return confirmBooking()">
                    Proceed to Payment
                </button>

                <a href="/gorwanda-plus/cars/detail.php?id=<?php echo $carId; ?>&pickup_date=<?php echo $pickupDate; ?>&return_date=<?php echo $returnDate; ?>" class="cancel-btn">
                    Cancel and go back
                </a>

                <div class="security-note">
                    <i class="bi bi-shield-lock"></i> Your payment is secure<br>
                    <i class="bi bi-credit-card"></i> No fees charged yet
                </div>
            </div>

            <!-- Need Help Card -->
            <div class="price-card" style="background: #f5f5f5;">
                <h3 style="font-size: 16px; margin-bottom: 12px;">Need help?</h3>
                <p style="font-size: 13px; color: #6b6b6b; margin-bottom: 16px;">
                    Contact <?php echo sanitize($car['company_name']); ?> directly:
                </p>
                <?php if ($car['company_phone']): ?>
                    <div style="margin-bottom: 8px;">
                        <i class="bi bi-telephone-fill"></i> <a href="tel:<?php echo $car['company_phone']; ?>" style="color: #0071c2; text-decoration: none;"><?php echo $car['company_phone']; ?></a>
                    </div>
                <?php endif; ?>
                <?php if ($car['company_email']): ?>
                    <div>
                        <i class="bi bi-envelope-fill"></i> <a href="mailto:<?php echo $car['company_email']; ?>" style="color: #0071c2; text-decoration: none;"><?php echo $car['company_email']; ?></a>
                    </div>
                <?php endif; ?>
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