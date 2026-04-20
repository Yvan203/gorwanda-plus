<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$currentUser = getCurrentUser();

// Get booking parameters
$carId = intval($_GET['car_id'] ?? 0);
$pickupLocation = sanitize($_GET['pickup_location'] ?? '');
$pickupDate = $_GET['pickup_date'] ?? '';
$returnDate = $_GET['return_date'] ?? '';

if (!$carId || !$pickupLocation || !$pickupDate || !$returnDate) {
    setFlash('error', 'Missing booking information');
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// Validate dates
if (strtotime($pickupDate) < strtotime(date('Y-m-d')) || strtotime($returnDate) <= strtotime($pickupDate)) {
    setFlash('error', 'Invalid dates selected');
    header('Location: /gorwanda-plus/cars/detail.php?id=' . $carId);
    exit;
}

// Calculate rental days
$pickupDateTime = new DateTime($pickupDate);
$returnDateTime = new DateTime($returnDate);
$days = $pickupDateTime->diff($returnDateTime)->days;

if ($days < 1) $days = 1;

// Get car details with all related data
$stmt = $db->prepare("
    SELECT cf.*, cr.company_name, cr.description as company_description, 
           cr.pickup_locations, cr.dropoff_locations, cr.operating_hours,
           cr.phone, cr.email, cr.logo, cr.avg_rating as company_rating,
           cr.review_count as company_reviews, cr.is_verified,
           cr.owner_id,
           (SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cr.rental_id) as avg_rating
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cf.car_id = ? AND cf.is_active = 1 AND cr.is_active = 1
");
$stmt->execute([$carId]);
$car = $stmt->fetch();

if (!$car) {
    setFlash('error', 'Car not found or unavailable');
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// ============================================
// AVAILABILITY CHECKS
// ============================================

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
    setFlash('error', 'Car is not available for the selected dates');
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
// PRICE CALCULATIONS
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

// Base price calculations
$dailyRate = $car['daily_rate'] * $seasonMultiplier;
$weeklyRate = $car['weekly_rate'] ? $car['weekly_rate'] * $seasonMultiplier : 0;
$monthlyRate = $car['monthly_rate'] ? $car['monthly_rate'] * $seasonMultiplier : 0;

// Apply weekly/monthly discounts
$basePrice = 0;
$appliedDiscount = '';
if ($days >= 30 && $monthlyRate > 0) {
    $months = floor($days / 30);
    $remainingDays = $days % 30;
    $basePrice = ($months * $monthlyRate) + ($remainingDays * $dailyRate);
    $appliedDiscount = 'Monthly rate applied';
} elseif ($days >= 7 && $weeklyRate > 0) {
    $weeks = floor($days / 7);
    $remainingDays = $days % 7;
    $basePrice = ($weeks * $weeklyRate) + ($remainingDays * $dailyRate);
    $appliedDiscount = 'Weekly rate applied';
} else {
    $basePrice = $days * $dailyRate;
}

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
$stmt->execute([$car['owner_id'], $days, $car['rental_id']]);
$offer = $stmt->fetch();

// Apply offer discount
$discountAmount = 0;
if ($offer) {
    if ($offer['offer_type'] == 'percentage') {
        $discountAmount = $basePrice * ($offer['discount_value'] / 100);
    } elseif ($offer['offer_type'] == 'fixed') {
        $discountAmount = min($offer['discount_value'], $basePrice);
    } elseif ($offer['offer_type'] == 'free_day') {
        $freeDays = min($offer['discount_value'], $days - 1);
        $discountAmount = $dailyRate * $freeDays;
    }
}

$subtotal = $basePrice - $discountAmount;

// Insurance options
$insuranceOptions = [
    'basic' => [
        'name' => 'Basic Insurance',
        'description' => 'Third-party liability only',
        'price' => 0,
        'included' => $car['insurance_included'] ?? false,
        'daily' => false
    ],
    'premium' => [
        'name' => 'Premium Coverage',
        'description' => 'Collision damage waiver + theft protection',
        'price' => 15000,
        'daily' => true
    ],
    'full' => [
        'name' => 'Full Protection',
        'description' => 'Zero excess, full coverage including windscreen and tyres',
        'price' => 25000,
        'daily' => true
    ]
];

// Extras
$extras = [
    'gps' => ['name' => 'GPS Navigation', 'price' => 5000, 'daily' => true],
    'child_seat' => ['name' => 'Child Seat', 'price' => 3000, 'daily' => true],
    'additional_driver' => ['name' => 'Additional Driver', 'price' => 10000, 'daily' => false],
    'wifi' => ['name' => 'Portable WiFi', 'price' => 4000, 'daily' => true],
    'roof_rack' => ['name' => 'Roof Rack', 'price' => 8000, 'daily' => false],
    'cooler' => ['name' => 'Cooler Box', 'price' => 5000, 'daily' => false]
];

// Calculate taxes
$taxRate = 0.18; // 18% VAT
$serviceFee = $subtotal * 0.10; // 10% service fee

$pageTitle = 'Complete your booking';
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
/* Booking.com Style for Cars */
:root {
    --booking-blue: #003b95;
    --booking-dark: #00224f;
    --booking-light: #f0f4ff;
    --booking-yellow: #febb02;
    --booking-gray: #f5f5f5;
    --booking-border: #e7e7e7;
    --booking-text: #1a1a1a;
    --booking-text-light: #6b6b6b;
    --booking-text-lighter: #a5a5a5;
    --booking-success: #008009;
    --booking-warning: #ff8c00;
    --booking-danger: #e21111;
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.1);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
}

.booking-page {
    background: var(--booking-gray);
    min-height: calc(100vh - 64px);
    padding: 32px 0;
}

.booking-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

/* Progress Bar */
.booking-progress {
    background: white;
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid var(--booking-border);
    box-shadow: var(--shadow-sm);
}

.progress-steps {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 2;
    background: white;
    padding: 0 12px;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--booking-gray);
    color: var(--booking-text-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
    margin-bottom: 8px;
    transition: all 0.2s;
}

.step-number.active {
    background: var(--booking-blue);
    color: white;
}

.step-number.completed {
    background: var(--booking-success);
    color: white;
}

.step-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--booking-text-light);
}

.step-label.active {
    color: var(--booking-blue);
}

.progress-line {
    position: absolute;
    top: 20px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--booking-border);
    z-index: 1;
}

.progress-line-fill {
    height: 100%;
    background: var(--booking-blue);
    width: 50%;
    transition: width 0.3s;
}

/* Main Grid */
.booking-grid {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
}

/* Booking Form */
.booking-form {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.form-section {
    padding: 24px;
    border-bottom: 1px solid var(--booking-border);
}

.form-section:last-child {
    border-bottom: none;
}

.section-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title i {
    color: var(--booking-blue);
    font-size: 1.25rem;
}

/* Guest Info */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--booking-text);
}

.form-label .required {
    color: var(--booking-danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,57,149,0.1);
}

.form-control.error {
    border-color: var(--booking-danger);
}

.form-text {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-top: 4px;
}

/* Location Info */
.location-badge {
    background: var(--booking-light);
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.location-badge i {
    color: var(--booking-blue);
    font-size: 1.25rem;
}

.location-badge div p {
    margin: 2px 0 0;
    font-size: 0.875rem;
    color: var(--booking-text-light);
}

/* Driver License */
.license-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
}

/* Insurance Options */
.insurance-options {
    display: grid;
    gap: 12px;
    margin-bottom: 24px;
}

.insurance-card {
    border: 2px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.insurance-card:hover {
    border-color: var(--booking-blue);
}

.insurance-card.selected {
    border-color: var(--booking-blue);
    background: var(--booking-light);
}

.insurance-radio {
    position: absolute;
    opacity: 0;
}

.insurance-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.insurance-name {
    font-weight: 700;
    font-size: 1rem;
}

.insurance-price {
    font-weight: 700;
    color: var(--booking-success);
}

.insurance-price.free {
    color: var(--booking-text-light);
}

.insurance-desc {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
}

.insurance-badge {
    display: inline-block;
    padding: 4px 8px;
    background: var(--booking-success);
    color: white;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
}

/* Extras Grid */
.extras-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-top: 16px;
}

.extra-item {
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    transition: all 0.2s;
}

.extra-item:hover {
    border-color: var(--booking-blue);
}

.extra-checkbox {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.extra-checkbox input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--booking-blue);
}

.extra-name {
    font-weight: 600;
    font-size: 0.9375rem;
}

.extra-price {
    font-weight: 700;
    color: var(--booking-success);
    font-size: 0.875rem;
    margin-left: 32px;
}

.extra-desc {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-left: 32px;
}

/* Terms */
.terms-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-md);
    margin: 20px 0;
}

.terms-checkbox input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--booking-blue);
    margin-top: 2px;
}

.terms-checkbox label {
    font-size: 0.8125rem;
    color: var(--booking-text);
    line-height: 1.5;
}

.terms-checkbox a {
    color: var(--booking-blue);
    text-decoration: none;
    font-weight: 600;
}

.terms-checkbox a:hover {
    text-decoration: underline;
}

/* Submit Button */
.btn-complete {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-dark));
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 700;
    font-size: 1.125rem;
    cursor: pointer;
    transition: all 0.2s;
    margin: 20px 0;
}

.btn-complete:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-complete:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Price Sidebar */
.price-sidebar {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    box-shadow: var(--shadow-lg);
    position: sticky;
    top: 20px;
    height: fit-content;
}

.price-header {
    padding: 20px;
    background: var(--booking-light);
    border-bottom: 1px solid var(--booking-border);
}

.price-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.price-header p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
}

.car-summary {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    gap: 12px;
}

.car-image {
    width: 80px;
    height: 80px;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--booking-gray);
    flex-shrink: 0;
}

.car-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.car-info h4 {
    font-size: 0.9375rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.car-info .company {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.car-info .location {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    display: flex;
    align-items: center;
    gap: 4px;
}

.price-breakdown {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
}

.price-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 0.875rem;
}

.price-row.discount {
    color: var(--booking-success);
}

.price-row.total {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid var(--booking-border);
    font-size: 1.125rem;
    font-weight: 700;
}

.price-label {
    color: var(--booking-text-light);
}

.price-value {
    font-weight: 600;
}

.price-value.total {
    color: var(--booking-blue);
}

.original-price {
    text-decoration: line-through;
    color: var(--booking-text-light);
    margin-right: 8px;
    font-size: 0.8125rem;
}

.selected-extras {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
}

.selected-extras h4 {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 12px;
}

.extra-tag {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    margin-bottom: 8px;
    font-size: 0.8125rem;
}

.extra-tag .remove {
    color: var(--booking-danger);
    cursor: pointer;
    font-size: 1rem;
}

.security-badge {
    padding: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
    border-top: 1px solid var(--booking-border);
}

.security-badge i {
    color: var(--booking-success);
}

/* Availability Badge */
.availability-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 16px;
}

.availability-badge.available {
    background: #e6f4ea;
    color: var(--booking-success);
}

.availability-badge.unavailable {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Responsive */
@media (max-width: 992px) {
    .booking-grid {
        grid-template-columns: 1fr;
    }
    
    .price-sidebar {
        position: static;
        order: -1;
    }
}

@media (max-width: 768px) {
    .form-row,
    .license-grid,
    .extras-grid {
        grid-template-columns: 1fr;
    }
    
    .progress-step .step-label {
        font-size: 0.6875rem;
    }
}
</style>

<div class="booking-page">
    <div class="booking-container">
        <!-- Progress Bar -->
        <div class="booking-progress">
            <div class="progress-steps">
                <div class="progress-step">
                    <div class="step-number completed">1</div>
                    <span class="step-label">Your details</span>
                </div>
                <div class="progress-step">
                    <div class="step-number active">2</div>
                    <span class="step-label active">Extras & insurance</span>
                </div>
                <div class="progress-step">
                    <div class="step-number">3</div>
                    <span class="step-label">Payment</span>
                </div>
                <div class="progress-line">
                    <div class="progress-line-fill" style="width: 50%;"></div>
                </div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="booking-grid">
            <!-- Left Column - Booking Form -->
            <div class="booking-form">
                <form id="bookingForm" method="POST" action="process-booking.php">
                    <input type="hidden" name="car_id" value="<?php echo $carId; ?>">
                    <input type="hidden" name="pickup_location" value="<?php echo sanitize($pickupLocation); ?>">
                    <input type="hidden" name="pickup_date" value="<?php echo $pickupDate; ?>">
                    <input type="hidden" name="return_date" value="<?php echo $returnDate; ?>">
                    <input type="hidden" name="days" value="<?php echo $days; ?>">
                    
                    <!-- Availability Indicator -->
                    <div class="availability-badge available" style="margin: 20px 20px 0;">
                        <i class="bi bi-check-circle-fill"></i>
                        Car available for selected dates
                    </div>

                    <!-- Guest Information -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-person-circle"></i>
                            Primary driver information
                        </h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">First name <span class="required">*</span></label>
                                <input type="text" name="first_name" class="form-control" 
                                       value="<?php echo sanitize($currentUser['first_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last name <span class="required">*</span></label>
                                <input type="text" name="last_name" class="form-control" 
                                       value="<?php echo sanitize($currentUser['last_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Email <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo sanitize($currentUser['email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone <span class="required">*</span></label>
                                <input type="tel" name="phone" class="form-control" 
                                       value="<?php echo sanitize($currentUser['phone'] ?? ''); ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Pickup/Return Summary -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-geo-alt"></i>
                            Rental details
                        </h2>
                        
                        <div class="location-badge">
                            <i class="bi bi-geo-alt-fill"></i>
                            <div>
                                <strong>Pickup & Return Location</strong>
                                <p><?php echo sanitize($pickupLocation); ?></p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Pickup date</label>
                                <input type="text" class="form-control" value="<?php echo date('l, F j, Y', strtotime($pickupDate)); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Return date</label>
                                <input type="text" class="form-control" value="<?php echo date('l, F j, Y', strtotime($returnDate)); ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Driver License Information -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-card-text"></i>
                            Driver's license
                        </h2>
                        
                        <div class="license-grid">
                            <div class="form-group">
                                <label class="form-label">License number <span class="required">*</span></label>
                                <input type="text" name="license_number" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Country of issue</label>
                                <select name="license_country" class="form-control">
                                    <option value="Rwanda">Rwanda</option>
                                    <option value="Uganda">Uganda</option>
                                    <option value="Kenya">Kenya</option>
                                    <option value="Tanzania">Tanzania</option>
                                    <option value="Burundi">Burundi</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Date of birth</label>
                                <input type="date" name="date_of_birth" class="form-control" 
                                       max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Insurance Options -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-shield-check"></i>
                            Insurance & protection
                        </h2>
                        
                        <div class="insurance-options">
                            <?php foreach ($insuranceOptions as $key => $insurance): 
                                $price = ($insurance['daily'] ?? false) ? $insurance['price'] * $days : $insurance['price'];
                            ?>
                            <label class="insurance-card <?php echo ($insurance['included'] ?? false) ? 'selected' : ''; ?>">
                                <input type="radio" name="insurance" value="<?php echo $key; ?>" 
                                       class="insurance-radio" <?php echo ($insurance['included'] ?? false) ? 'checked' : ''; ?>>
                                <div class="insurance-header">
                                    <span class="insurance-name"><?php echo $insurance['name']; ?></span>
                                    <span class="insurance-price <?php echo $price == 0 ? 'free' : ''; ?>">
                                        <?php echo $price == 0 ? 'Included' : formatPrice($price); ?>
                                    </span>
                                </div>
                                <div class="insurance-desc"><?php echo $insurance['description']; ?></div>
                                <?php if ($insurance['included'] ?? false): ?>
                                <span class="insurance-badge">Included in price</span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Optional Extras -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-plus-circle"></i>
                            Optional extras
                        </h2>
                        
                        <div class="extras-grid">
                            <?php foreach ($extras as $key => $extra): 
                                $price = ($extra['daily'] ?? false) ? $extra['price'] * $days : $extra['price'];
                            ?>
                            <div class="extra-item">
                                <div class="extra-checkbox">
                                    <input type="checkbox" name="extras[]" value="<?php echo $key; ?>" 
                                           id="extra_<?php echo $key; ?>" data-price="<?php echo $price; ?>">
                                    <label for="extra_<?php echo $key; ?>" class="extra-name"><?php echo $extra['name']; ?></label>
                                </div>
                                <div class="extra-price"><?php echo formatPrice($price); ?></div>
                                <?php if ($extra['daily'] ?? false): ?>
                                <div class="extra-desc">per day • <?php echo formatPrice($extra['price']); ?>/day</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Special Requests -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class="bi bi-chat-text"></i>
                            Special requests (optional)
                        </h2>
                        
                        <div class="form-group">
                            <textarea name="special_requests" class="form-control" rows="3" 
                                      placeholder="Any special requirements? (e.g., baby seat installation, extra equipment, etc.)"></textarea>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="form-section">
                        <div class="terms-checkbox">
                            <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                            <label for="agreeTerms">
                                I confirm that I am at least 23 years old, have a valid driver's license, 
                                and agree to the <a href="#" target="_blank">Terms of Service</a>, 
                                <a href="#" target="_blank">Privacy Policy</a>, and 
                                <a href="#" target="_blank">Rental Agreement</a>.
                            </label>
                        </div>
                        
                        <button type="submit" class="btn-complete" id="submitBtn" disabled>
                            Complete Booking • <span id="finalTotal"><?php echo formatPrice($subtotal + $serviceFee); ?></span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column - Price Sidebar -->
            <div class="price-sidebar">
                <div class="price-header">
                    <h3>Your booking summary</h3>
                    <p><?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?></p>
                </div>
                
                <!-- Car Summary -->
                <div class="car-summary">
                    <?php 
                    $carImages = json_decode($car['images'] ?? '[]', true);
                    $carImage = $carImages[0] ?? '';
                    ?>
                    <div class="car-image">
                        <img src="<?php echo getImageUrl($carImage, 'car'); ?>" 
                             alt="<?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>"
                             onerror="this.src='https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=100&q=80'">
                    </div>
                    <div class="car-info">
                        <h4><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></h4>
                        <div class="company"><?php echo sanitize($car['company_name']); ?></div>
                        <div class="location">
                            <i class="bi bi-geo-alt"></i> <?php echo sanitize($pickupLocation); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Price Breakdown -->
                <div class="price-breakdown" id="priceBreakdown">
                    <div class="price-row">
                        <span class="price-label">Daily rate × <?php echo $days; ?> days</span>
                        <span class="price-value"><?php echo formatPrice($dailyRate * $days); ?></span>
                    </div>
                    
                    <?php if ($appliedDiscount): ?>
                    <div class="price-row discount">
                        <span class="price-label"><?php echo $appliedDiscount; ?></span>
                        <span class="price-value">-<?php echo formatPrice($basePrice - ($dailyRate * $days)); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($discountAmount > 0): ?>
                    <div class="price-row discount">
                        <span class="price-label">Special offer</span>
                        <span class="price-value">-<?php echo formatPrice($discountAmount); ?></span>
                    </div>
                    <?php endif; ?>
                                        <!-- Base Price -->
                    <div class="price-row">
                        <span class="price-label">Base rental price</span>
                        <span class="price-value" id="basePrice"><?php echo formatPrice($subtotal); ?></span>
                    </div>
                    
                    <!-- Insurance (dynamic) -->
                    <div class="price-row" id="insuranceRow" style="display: none;">
                        <span class="price-label" id="insuranceLabel">Insurance</span>
                        <span class="price-value" id="insurancePrice">RWF 0</span>
                    </div>
                    
                    <!-- Extras (dynamic) -->
                    <div id="extrasList"></div>
                    
                    <!-- Service Fee -->
                    <div class="price-row">
                        <span class="price-label">Service fee</span>
                        <span class="price-value"><?php echo formatPrice($serviceFee); ?></span>
                    </div>
                    
                    <!-- Estimated Tax (18% VAT) -->
                    <div class="price-row">
                        <span class="price-label">Estimated tax (18% VAT)</span>
                        <span class="price-value" id="taxAmount"><?php echo formatPrice($subtotal * 0.18); ?></span>
                    </div>
                    
                    <!-- Total -->
                    <div class="price-row total">
                        <span class="price-label">Total (estimated)</span>
                        <span class="price-value total" id="totalPrice"><?php echo formatPrice($subtotal + $serviceFee + ($subtotal * 0.18)); ?></span>
                    </div>
                </div>
                
                <!-- Selected Extras Summary -->
                <div class="selected-extras" id="selectedExtras">
                    <h4>Selected extras</h4>
                    <div id="extrasSummary">
                        <p style="color: var(--booking-text-light); font-size: 0.8125rem; text-align: center; padding: 8px;">
                            No extras selected
                        </p>
                    </div>
                </div>
                
                <!-- What's Included -->
                <div style="padding: 20px; border-bottom: 1px solid var(--booking-border);">
                    <h4 style="font-size: 0.875rem; font-weight: 700; margin-bottom: 12px;">What's included</h4>
                    <div style="display: grid; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.8125rem;">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 0.875rem;"></i>
                            <span><?php echo $car['free_km_per_day']; ?> km/day included</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.8125rem;">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 0.875rem;"></i>
                            <span>Free cancellation up to 48h before pickup</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.8125rem;">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 0.875rem;"></i>
                            <span>24/7 roadside assistance</span>
                        </div>
                    </div>
                </div>
                
                <!-- Security Badges -->
                <div class="security-badge">
                    <span><i class="bi bi-shield-check"></i> Secure payment</span>
                    <span><i class="bi bi-lock"></i> Encrypted</span>
                    <span><i class="bi bi-credit-card"></i> Pay later</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// Dynamic Price Calculator
// ============================================
const basePrice = <?php echo $subtotal; ?>;
const days = <?php echo $days; ?>;
const taxRate = 0.18;
const serviceFee = <?php echo $serviceFee; ?>;

// Insurance prices
const insurancePrices = {
    'basic': 0,
    'premium': <?php echo ($insuranceOptions['premium']['daily'] ?? false) ? $insuranceOptions['premium']['price'] * $days : $insuranceOptions['premium']['price']; ?>,
    'full': <?php echo ($insuranceOptions['full']['daily'] ?? false) ? $insuranceOptions['full']['price'] * $days : $insuranceOptions['full']['price']; ?>
};

// Extras prices (pre-calculated)
const extrasPrices = {
    <?php foreach ($extras as $key => $extra): ?>
    '<?php echo $key; ?>': <?php echo ($extra['daily'] ?? false) ? $extra['price'] * $days : $extra['price']; ?>,
    <?php endforeach; ?>
};

// Insurance selection
document.querySelectorAll('input[name="insurance"]').forEach(radio => {
    radio.addEventListener('change', function() {
        // Update UI
        document.querySelectorAll('.insurance-card').forEach(card => {
            card.classList.remove('selected');
        });
        this.closest('.insurance-card').classList.add('selected');
        
        // Update price
        updateInsurancePrice(this.value);
        calculateTotal();
    });
});

// Extras checkboxes
document.querySelectorAll('input[name="extras[]"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        updateExtrasSummary();
        calculateTotal();
    });
});

// Terms checkbox
document.getElementById('agreeTerms').addEventListener('change', function() {
    document.getElementById('submitBtn').disabled = !this.checked;
});

function updateInsurancePrice(type) {
    const insuranceRow = document.getElementById('insuranceRow');
    const insurancePrice = document.getElementById('insurancePrice');
    const insuranceLabel = document.getElementById('insuranceLabel');
    
    if (type === 'basic') {
        insuranceRow.style.display = 'none';
    } else {
        insuranceRow.style.display = 'flex';
        insuranceLabel.textContent = type === 'premium' ? 'Premium Insurance' : 'Full Protection';
        insurancePrice.textContent = formatCurrency(insurancePrices[type]);
    }
}

function updateExtrasSummary() {
    const extrasList = document.getElementById('extrasList');
    const extrasSummary = document.getElementById('extrasSummary');
    let selectedExtras = [];
    let html = '';
    
    document.querySelectorAll('input[name="extras[]"]:checked').forEach(checkbox => {
        const value = checkbox.value;
        const label = checkbox.closest('.extra-item').querySelector('.extra-name').textContent;
        const price = extrasPrices[value];
        
        selectedExtras.push({ value, label, price });
        
        // Add to price breakdown
        html += `
            <div class="price-row" id="extra-${value}">
                <span class="price-label">${label}</span>
                <span class="price-value">${formatCurrency(price)}</span>
            </div>
        `;
        
        // Add to summary
        document.getElementById('extrasSummary').innerHTML += `
            <div class="extra-tag" id="summary-${value}">
                <span>${label}</span>
                <span>
                    ${formatCurrency(price)}
                    <i class="bi bi-x-circle remove" onclick="removeExtra('${value}')"></i>
                </span>
            </div>
        `;
    });
    
    extrasList.innerHTML = html;
    
    if (selectedExtras.length === 0) {
        extrasSummary.innerHTML = '<p style="color: var(--booking-text-light); font-size: 0.8125rem; text-align: center; padding: 8px;">No extras selected</p>';
    }
}

function removeExtra(value) {
    const checkbox = document.querySelector(`input[name="extras[]"][value="${value}"]`);
    if (checkbox) {
        checkbox.checked = false;
        
        // Remove from price breakdown
        const extraRow = document.getElementById(`extra-${value}`);
        if (extraRow) extraRow.remove();
        
        // Remove from summary
        const summaryTag = document.getElementById(`summary-${value}`);
        if (summaryTag) summaryTag.remove();
        
        // Check if any extras left
        if (document.querySelectorAll('input[name="extras[]"]:checked').length === 0) {
            document.getElementById('extrasSummary').innerHTML = '<p style="color: var(--booking-text-light); font-size: 0.8125rem; text-align: center; padding: 8px;">No extras selected</p>';
        }
        
        calculateTotal();
    }
}

function calculateTotal() {
    // Get selected insurance
    const selectedInsurance = document.querySelector('input[name="insurance"]:checked')?.value || 'basic';
    const insuranceCost = insurancePrices[selectedInsurance] || 0;
    
    // Get selected extras
    let extrasCost = 0;
    document.querySelectorAll('input[name="extras[]"]:checked').forEach(checkbox => {
        extrasCost += extrasPrices[checkbox.value];
    });
    
    // Calculate new totals
    const subtotal = basePrice + insuranceCost + extrasCost;
    const taxAmount = subtotal * taxRate;
    const total = subtotal + serviceFee + taxAmount;
    
    // Update display
    document.getElementById('taxAmount').textContent = formatCurrency(taxAmount);
    document.getElementById('totalPrice').textContent = formatCurrency(total);
    document.getElementById('finalTotal').textContent = formatCurrency(total);
}

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

// Phone number formatting
document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
    let value = this.value.replace(/\D/g, '');
    if (value.length > 0) {
        if (value.length <= 3) {
            this.value = value;
        } else if (value.length <= 6) {
            this.value = value.slice(0, 3) + ' ' + value.slice(3);
        } else {
            this.value = value.slice(0, 3) + ' ' + value.slice(3, 6) + ' ' + value.slice(6, 10);
        }
    }
});

// License number formatting
document.querySelector('input[name="license_number"]').addEventListener('input', function(e) {
    this.value = this.value.toUpperCase();
});

// Warn user if they try to leave
let formModified = false;
document.querySelectorAll('input, select, textarea').forEach(field => {
    field.addEventListener('change', () => formModified = true);
});

window.addEventListener('beforeunload', function(e) {
    if (formModified && !document.getElementById('submitBtn').disabled) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Initialize insurance display
const initialInsurance = document.querySelector('input[name="insurance"]:checked')?.value;
if (initialInsurance && initialInsurance !== 'basic') {
    updateInsurancePrice(initialInsurance);
}
</script>

<?php require_once '../includes/footer.php'; ?>