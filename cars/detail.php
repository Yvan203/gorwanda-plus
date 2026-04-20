<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

$db = getDB();

// Get dates from URL or defaults
$pickupDate = $_GET['pickup_date'] ?? date('Y-m-d', strtotime('+1 day'));
$returnDate = $_GET['return_date'] ?? date('Y-m-d', strtotime('+3 days'));
$days = max(1, (strtotime($returnDate) - strtotime($pickupDate)) / 86400);

// Get car details with all related data
$stmt = $db->prepare("
    SELECT cf.*, cr.company_name, cr.description as company_description, 
           cr.pickup_locations, cr.dropoff_locations, cr.operating_hours,
           cr.phone, cr.email, cr.logo, cr.avg_rating as company_rating,
           cr.review_count as company_reviews, cr.is_verified, cr.owner_id,
           cr.commission_rate, cr.free_cancellation, cr.instant_confirmation,
           l.name as location_name, l.latitude, l.longitude,
           (SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cr.rental_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id) as review_count,
           (SELECT COUNT(*) FROM car_locations WHERE FIND_IN_SET(location_id, cr.pickup_locations)) as location_count
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN locations l ON cr.location_id = l.location_id
    WHERE cf.car_id = ? AND cf.is_active = 1 AND cr.is_active = 1
");
$stmt->execute([$id]);
$car = $stmt->fetch();

if (!$car) {
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
    AND b.status IN ('confirmed', 'checked_out', 'pending')
    AND (
        (b.pickup_date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY))
        OR (b.return_date BETWEEN ? AND ?)
        OR (? BETWEEN b.pickup_date AND DATE_SUB(b.return_date, INTERVAL 1 DAY))
    )
");
$stmt->execute([$id, $pickupDate, $returnDate, $pickupDate, $returnDate, $pickupDate]);
$availability = $stmt->fetch();
$isAvailable = $availability['booked'] == 0;

// Check for maintenance
$stmt = $db->prepare("
    SELECT COUNT(*) as maintenance
    FROM car_maintenance
    WHERE car_id = ?
    AND status IN ('scheduled', 'in_progress')
    AND scheduled_date <= ? 
    AND DATE_ADD(scheduled_date, INTERVAL estimated_duration - 1 DAY) >= ?
");
$stmt->execute([$id, $returnDate, $pickupDate]);
$maintenance = $stmt->fetch();
$inMaintenance = $maintenance['maintenance'] > 0;

// Check for availability overrides
$stmt = $db->prepare("
    SELECT COUNT(*) as blocked
    FROM car_availability
    WHERE car_id = ?
    AND date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
    AND (is_blocked = 1 OR quantity_available < 1)
");
$stmt->execute([$id, $pickupDate, $returnDate]);
$blocked = $stmt->fetch();
$isBlocked = $blocked['blocked'] > 0;

// Final availability status
$isAvailable = $isAvailable && !$inMaintenance && !$isBlocked;

// ============================================
// PRICING CALCULATIONS
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

// Calculate base price with seasonal adjustment
$baseDailyRate = $car['daily_rate'] * $seasonMultiplier;
$baseTotal = $baseDailyRate * $days;

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
        $discountAmount = $baseTotal * ($offer['discount_value'] / 100);
    } elseif ($offer['offer_type'] == 'fixed') {
        $discountAmount = min($offer['discount_value'], $baseTotal);
    } elseif ($offer['offer_type'] == 'free_day') {
        $freeDays = min($offer['discount_value'], $days - 1);
        $discountAmount = $baseDailyRate * $freeDays;
    }
}

$subtotal = $baseTotal - $discountAmount;
$taxRate = 0.18; // 18% VAT
$taxAmount = $subtotal * $taxRate;
$serviceFee = $subtotal * 0.10; // 10% service fee
$totalAmount = $subtotal + $taxAmount + $serviceFee;

// Weekly/Monthly savings calculation
$weeklySavings = $car['weekly_rate'] > 0 && $days >= 7 
    ? ($car['daily_rate'] * 7) - $car['weekly_rate'] 
    : 0;
$monthlySavings = $car['monthly_rate'] > 0 && $days >= 30 
    ? ($car['daily_rate'] * 30) - $car['monthly_rate'] 
    : 0;

// ============================================
// OPTIONAL EXTRAS
// ============================================
$extras = [
    'gps' => ['name' => 'GPS Navigation', 'price' => 5000, 'per' => 'day', 'icon' => 'bi-pin-map'],
    'child_seat' => ['name' => 'Child Seat', 'price' => 3000, 'per' => 'day', 'icon' => 'bi-people'],
    'additional_driver' => ['name' => 'Additional Driver', 'price' => 10000, 'per' => 'total', 'icon' => 'bi-person-plus'],
    'wifi' => ['name' => 'Portable WiFi', 'price' => 7000, 'per' => 'day', 'icon' => 'bi-wifi'],
    'roof_rack' => ['name' => 'Roof Rack', 'price' => 8000, 'per' => 'total', 'icon' => 'bi-box'],
    'cooler' => ['name' => 'Cooler Box', 'price' => 4000, 'per' => 'day', 'icon' => 'bi-snow']
];

// Insurance options
$insuranceOptions = [
    'basic' => [
        'name' => 'Basic Insurance',
        'price' => 0,
        'included' => $car['insurance_included'],
        'description' => 'Third-party liability coverage',
        'icon' => 'bi-shield'
    ],
    'premium' => [
        'name' => 'Premium Coverage',
        'price' => 15000,
        'included' => false,
        'description' => 'Reduced excess, theft protection',
        'icon' => 'bi-shield-check'
    ],
    'full' => [
        'name' => 'Full Protection',
        'price' => 25000,
        'included' => false,
        'description' => 'Zero excess, full coverage',
        'icon' => 'bi-shield-fill-check'
    ]
];

// ============================================
// GET SIMILAR CARS
// ============================================
$stmt = $db->prepare("
    SELECT cf.*, 
           (SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cf.rental_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE rental_id = cf.rental_id) as review_count
    FROM car_fleet cf
    WHERE cf.rental_id = ? AND cf.car_id != ? AND cf.is_active = 1
    ORDER BY cf.daily_rate ASC
    LIMIT 3
");
$stmt->execute([$car['rental_id'], $id]);
$similarCars = $stmt->fetchAll();

// ============================================
// GET COMPANY REVIEWS
// ============================================
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           DATEDIFF(r.created_at, u.created_at) as days_as_member
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.rental_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->execute([$car['rental_id']]);
$reviews = $stmt->fetchAll();

// ============================================
// GET PICKUP LOCATIONS
// ============================================
$pickupLocs = json_decode($car['pickup_locations'] ?? '[]', true);

// Get detailed location info from car_locations table
$locationDetails = [];
if (!empty($pickupLocs)) {
    $placeholders = implode(',', array_fill(0, count($pickupLocs), '?'));
    $stmt = $db->prepare("
        SELECT * FROM car_locations 
        WHERE location_id IN ($placeholders) AND is_active = 1
    ");
    $stmt->execute($pickupLocs);
    $locationDetails = $stmt->fetchAll();
}

// ============================================
// GET IMAGES
// ============================================
$images = json_decode($car['images'] ?? '[]', true);
if (empty($images)) $images = [''];

// ============================================
// GET FEATURES
// ============================================
$features = json_decode($car['features'] ?? '[]', true);
$allFeatures = [
    'ac' => 'Air Conditioning',
    'gps' => 'GPS Navigation',
    'bluetooth' => 'Bluetooth',
    '4wd' => '4-Wheel Drive',
    'roof_rack' => 'Roof Rack',
    'cooler' => 'Cooler Box',
    'usb' => 'USB Charging',
    'cruise_control' => 'Cruise Control',
    'parking_sensors' => 'Parking Sensors',
    'reverse_camera' => 'Reverse Camera',
    'leather_seats' => 'Leather Seats',
    'sunroof' => 'Sunroof',
    'child_seat' => 'Child Seat (on request)',
    'wifi' => 'WiFi Hotspot'
];

$pageTitle = $car['brand'] . ' ' . $car['model'] . ' - Car Rental';
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
/* Modern Car Detail Page CSS */
:root {
    --primary: #0066ff;
    --primary-dark: #003b95;
    --primary-light: #f0f4ff;
    --accent: #ffb700;
    --bg: #ffffff;
    --bg-secondary: #f5f5f5;
    --text: #1a1a1a;
    --text-secondary: #595959;
    --text-muted: #a5a5a5;
    --border: #e7e7e7;
    --success: #008009;
    --warning: #ff8c00;
    --danger: #e21111;
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.car-detail-page {
    background: var(--bg-secondary);
    min-height: calc(100vh - 64px);
    padding: 32px 0;
}

/* Breadcrumb */
.breadcrumb-bar {
    margin-bottom: 24px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.breadcrumb-link {
    color: var(--primary);
    text-decoration: none;
    transition: var(--transition);
}

.breadcrumb-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

/* Header Card */
.car-header-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    animation: fadeInUp 0.5s ease;
}

.car-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    line-height: 1.2;
}

.car-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.car-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.car-badge i {
    font-size: 0.875rem;
}

.car-rating {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-dark);
    color: white;
    padding: 4px 10px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 0.875rem;
}

.car-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.car-meta i {
    color: var(--primary);
    margin-right: 4px;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    margin-left: auto;
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: var(--transition);
}

.action-btn:hover {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary);
    transform: translateY(-2px);
}

.action-btn.filled {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.action-btn.filled:hover {
    background: var(--primary-dark);
}

/* Main Grid */
.car-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
}

/* Gallery Section */
.car-gallery {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 24px;
    animation: fadeInUp 0.5s ease 0.1s both;
}

.main-image {
    position: relative;
    height: 350px;
    overflow: hidden;
    cursor: pointer;
}

.main-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.main-image:hover img {
    transform: scale(1.02);
}

.image-badge {
    position: absolute;
    bottom: 16px;
    right: 16px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 8px 16px;
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    backdrop-filter: blur(4px);
    cursor: pointer;
    transition: var(--transition);
}

.image-badge:hover {
    background: rgba(0,0,0,0.9);
}

.thumbnail-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    padding: 4px;
}

.thumbnail-item {
    height: 80px;
    cursor: pointer;
    overflow: hidden;
    opacity: 0.7;
    transition: var(--transition);
}

.thumbnail-item:hover,
.thumbnail-item.active {
    opacity: 1;
}

.thumbnail-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Main Content Cards */
.car-content-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 24px;
    transition: var(--transition);
}

.car-content-card:last-child {
    margin-bottom: 0;
}

.card-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: var(--primary);
    font-size: 1.25rem;
}

/* Features Grid */
.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.feature-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 16px;
    text-align: center;
    transition: var(--transition);
}

.feature-card:hover {
    transform: translateY(-2px);
    background: var(--primary-light);
}

.feature-icon {
    font-size: 1.5rem;
    color: var(--primary);
    margin-bottom: 8px;
}

.feature-value {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 4px;
}

.feature-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Features List */
.features-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: var(--transition);
}

.feature-item:hover {
    background: var(--primary-light);
}

.feature-item i {
    color: var(--success);
    font-size: 1.125rem;
}

.feature-item span {
    font-size: 0.9375rem;
    color: var(--text);
}

/* Rental Terms */
.terms-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.terms-list li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 0.9375rem;
}

.terms-list li:last-child {
    border-bottom: none;
}

.terms-list i {
    color: var(--primary);
    font-size: 1rem;
}

/* Pickup Locations */
.locations-container {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.location-tag {
    background: var(--primary-light);
    color: var(--primary);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.875rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
}

.location-tag:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
}

.location-tag i {
    font-size: 0.875rem;
}

/* Sidebar */
.car-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.booking-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-lg);
}

.price-display {
    text-align: center;
    margin-bottom: 24px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
}

.price-amount {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1;
    margin-bottom: 4px;
}

.price-period {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.price-weekly {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-top: 8px;
}

.price-weekly strong {
    color: var(--success);
}

/* Booking Form */
.booking-form .form-group {
    margin-bottom: 16px;
}

.booking-form label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    margin-bottom: 6px;
}

.booking-form select,
.booking-form input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: var(--transition);
}

.booking-form select:focus,
.booking-form input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.date-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

/* Price Breakdown */
.price-breakdown {
    margin: 20px 0;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.price-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.875rem;
}

.price-row.total {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 2px solid var(--border);
    font-weight: 700;
    font-size: 1rem;
}

.price-label {
    color: var(--text-secondary);
}

.price-value {
    font-weight: 600;
}

.price-value.total {
    color: var(--primary);
}

/* Reserve Button */
.btn-reserve {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    margin: 20px 0 12px;
}

.btn-reserve:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.security-note {
    text-align: center;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.security-note i {
    color: var(--success);
}

/* Company Card */
.company-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 20px;
    transition: var(--transition);
}

.company-card:hover {
    box-shadow: var(--shadow-md);
}

.company-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}

.company-logo {
    width: 60px;
    height: 60px;
    background: var(--primary-light);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--primary);
}

.company-info h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.company-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.company-rating i {
    color: var(--accent);
}
/* Company Logo in Header */
.company-logo-header {
    width: 80px;
    height: 80px;
    border-radius: var(--radius-md);
    background: var(--primary-light);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1px solid var(--border);
    transition: var(--transition);
}

.company-logo-header img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.company-logo-header:hover {
    box-shadow: var(--shadow-sm);
    transform: scale(1.02);
}

.company-logo-header:hover img {
    transform: scale(1.05);
}

.company-logo-header i {
    font-size: 2rem;
    color: var(--primary);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .company-logo-header {
        width: 60px;
        height: 60px;
    }
    
    .car-title {
        font-size: 1.25rem;
    }
}

.verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--success);
    color: white;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
}

.company-contact {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.company-contact div {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.company-contact i {
    color: var(--primary);
    width: 20px;
}

/* Reviews Section */
.reviews-section {
    margin-top: 24px;
}

.review-card {
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
}

.review-card:last-child {
    border-bottom: none;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.reviewer-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.reviewer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.reviewer-name {
    font-weight: 600;
    font-size: 0.9375rem;
}

.review-date {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.review-rating {
    display: flex;
    gap: 2px;
    color: var(--accent);
}

.review-text {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.6;
}

/* Similar Cars */
.similar-section {
    margin-top: 48px;
}

.similar-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.similar-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    text-decoration: none;
    color: var(--text);
    transition: var(--transition);
}

.similar-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
}

.similar-image {
    height: 140px;
    background: var(--bg-secondary);
    overflow: hidden;
}

.similar-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.similar-card:hover .similar-image img {
    transform: scale(1.05);
}

.similar-content {
    padding: 16px;
}

.similar-title {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 4px;
}

.similar-type {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.similar-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.similar-rating {
    background: var(--primary-dark);
    color: white;
    padding: 2px 6px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 0.75rem;
}

.similar-price {
    text-align: right;
}

.similar-price-value {
    font-weight: 700;
    color: var(--success);
    font-size: 0.9375rem;
}

.similar-price-unit {
    font-size: 0.625rem;
    color: var(--text-secondary);
}

/* Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fadeInUp {
    animation: fadeInUp 0.5s ease forwards;
}

.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.3s; }

/* Responsive */
@media (max-width: 992px) {
    .car-grid {
        grid-template-columns: 1fr;
    }
    
    .car-sidebar {
        position: static;
    }
    
    .features-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .similar-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .car-title {
        font-size: 1.5rem;
    }
    
    .car-badges {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        margin-left: 0;
        width: 100%;
        justify-content: space-between;
    }
    
    .features-grid,
    .features-list,
    .similar-grid {
        grid-template-columns: 1fr;
    }
    
    .main-image {
        height: 250px;
    }
    
    .thumbnail-item {
        height: 60px;
    }
}
</style>

<div class="car-detail-page">
    <div class="container">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-bar">
            <a href="/gorwanda-plus/" class="breadcrumb-link">Home</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <a href="/gorwanda-plus/?type=cars" class="breadcrumb-link">Cars</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <span class="text-secondary"><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></span>
        </nav>

        <!-- Header Card -->
<!-- Header Card -->
<div class="car-header-card">
    <div class="d-flex flex-wrap align-items-start justify-content-between">
        <div class="d-flex align-items-start gap-3">
<?php if (!empty($car['logo'])): ?>
<div class="company-logo-header">
    <?php 
    $logoUrl = getImageUrl($car['logo'], 'car');
    $companyName = sanitize($car['company_name']);
    ?>
    <img src="<?php echo $logoUrl; ?>" 
         alt="<?php echo $companyName; ?>"
         style="width: 100%; height: 100%; object-fit: cover;">
</div>
<?php endif; ?>
            
            <div>
                <h1 class="car-title">
                    <?php echo sanitize($car['brand'] . ' ' . $car['model']); ?> 
                    <span style="font-size: 1rem; font-weight: 400; color: var(--text-secondary);">
                        <?php echo $car['year']; ?>
                    </span>
                </h1>
                
                <div class="car-badges">
                    <span class="car-badge">
                        <i class="bi bi-building"></i> 
                        <?php echo sanitize($car['company_name']); ?>
                    </span>
                    <span class="car-badge">
                        <i class="bi bi-geo-alt"></i> 
                        <?php echo sanitize($car['location_name'] ?? 'Kigali'); ?>
                    </span>
                    <span class="car-badge">
                        <i class="bi bi-car-front"></i> 
                        <?php echo ucfirst($car['car_type']); ?>
                    </span>
                    <span class="car-badge">
                        <i class="bi bi-gear"></i> 
                        <?php echo ucfirst($car['transmission']); ?>
                    </span>
                    <span class="car-badge">
                        <i class="bi bi-fuel-pump"></i> 
                        <?php echo ucfirst($car['fuel_type']); ?>
                    </span>
                    
                    <?php if ($car['company_rating']): ?>
                    <span class="car-rating">
                        <i class="bi bi-star-fill"></i> 
                        <?php echo number_format($car['company_rating'], 1); ?>
                    </span>
                    <?php endif; ?>
                    
                    <?php if ($car['is_verified']): ?>
                    <span class="verified-badge">
                        <i class="bi bi-patch-check-fill"></i> Verified
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="car-meta">
                    <span>
                        <i class="bi bi-people"></i> 
                        <?php echo $car['seats']; ?> seats
                    </span>
                    <span>
                        <i class="bi bi-briefcase"></i> 
                        <?php echo $car['luggage_capacity']; ?> bags
                    </span>
                    <span>
                        <i class="bi bi-snow"></i> 
                        <?php echo in_array('ac', $features) ? 'AC' : 'No AC'; ?>
                    </span>
                    <?php if ($car['free_km_per_day']): ?>
                    <span>
                        <i class="bi bi-speedometer2"></i> 
                        <?php echo $car['free_km_per_day']; ?> km/day free
                    </span>
                    <?php endif; ?>
                    <?php if ($car['insurance_included']): ?>
                    <span>
                        <i class="bi bi-shield-check"></i> 
                        Insurance included
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="action-buttons">
            <button class="action-btn" onclick="shareCar()">
                <i class="bi bi-share"></i>
                <span class="d-none d-sm-inline">Share</span>
            </button>
            <button class="action-btn" onclick="toggleSave()" id="saveBtn">
                <i class="bi bi-heart"></i>
                <span class="d-none d-sm-inline">Save</span>
            </button>
        </div>
    </div>
</div>

        <!-- Main Content Grid -->
        <div class="car-grid">
            <!-- Left Column - Main Content -->
            <div class="car-main-content">
                <!-- Gallery -->
                <div class="car-gallery fadeInUp">
                    <div class="main-image" onclick="openGallery()">
                        <img src="<?php echo getImageUrl($images[0] ?? '', 'car'); ?>" 
                             alt="<?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>"
                             onerror="this.src='https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=800&q=80'">
                        <?php if (count($images) > 1): ?>
                        <div class="image-badge">
                            <i class="bi bi-images"></i>
                            <span>+<?php echo count($images) - 1; ?> photos</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($images) > 1): ?>
                    <div class="thumbnail-grid">
                        <?php foreach (array_slice($images, 1, 4) as $index => $img): ?>
                        <div class="thumbnail-item <?php echo $index === 0 ? 'active' : ''; ?>" 
                             onclick="changeMainImage(this, '<?php echo getImageUrl($img, 'car'); ?>')">
                            <img src="<?php echo getImageUrl($img, 'car'); ?>" alt="Thumbnail">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Key Features Cards -->
                <div class="car-content-card fadeInUp delay-1">
                    <h3 class="card-title">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        Key Specifications
                    </h3>
                    
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="feature-value"><?php echo $car['seats']; ?></div>
                            <div class="feature-label">Seats</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-briefcase-fill"></i></div>
                            <div class="feature-value"><?php echo $car['luggage_capacity']; ?></div>
                            <div class="feature-label">Bags</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-gear-fill"></i></div>
                            <div class="feature-value"><?php echo ucfirst($car['transmission']); ?></div>
                            <div class="feature-label">Transmission</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-fuel-pump-fill"></i></div>
                            <div class="feature-value"><?php echo ucfirst($car['fuel_type']); ?></div>
                            <div class="feature-label">Fuel</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-speedometer2"></i></div>
                            <div class="feature-value"><?php echo $car['free_km_per_day']; ?> km</div>
                            <div class="feature-label">Free/day</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                            <div class="feature-value"><?php echo $car['insurance_included'] ? 'Yes' : 'Optional'; ?></div>
                            <div class="feature-label">Insurance</div>
                        </div>
                    </div>
                </div>

                <!-- All Features -->
                <?php if (!empty($features)): ?>
                <div class="car-content-card fadeInUp delay-2">
                    <h3 class="card-title">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        Included Features
                    </h3>
                    
                    <div class="features-list">
                        <?php foreach ($features as $feature): ?>
                        <div class="feature-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span><?php echo ucfirst($feature); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rental Terms -->
                <div class="car-content-card fadeInUp delay-2">
                    <h3 class="card-title">
                        <i class="bi bi-file-text"></i>
                        Rental Terms
                    </h3>
                    
                    <ul class="terms-list">
                        <li><i class="bi bi-calendar-check"></i> Free cancellation up to 24 hours before pickup</li>
                        <li><i class="bi bi-speedometer2"></i> <?php echo $car['free_km_per_day']; ?> km included per day</li>
                        <li><i class="bi bi-currency-dollar"></i> Excess mileage: <?php echo formatPrice($car['excess_km_charge']); ?>/km</li>
                        <li><i class="bi bi-shield-check"></i> <?php echo $car['insurance_included'] ? 'Full insurance included' : 'Insurance available at pickup'; ?></li>
                        <li><i class="bi bi-person-badge"></i> Minimum driver age: 23 years</li>
                        <li><i class="bi bi-clock"></i> Operating hours: <?php echo $car['operating_hours'] ?? '24/7'; ?></li>
                    </ul>
                </div>

                <!-- Pickup Locations -->
                <div class="car-content-card fadeInUp delay-3">
                    <h3 class="card-title">
                        <i class="bi bi-geo-alt"></i>
                        Pickup & Drop-off Locations
                    </h3>
                    
                    <div class="locations-container">
                        <?php foreach ($pickupLocs as $loc): ?>
                        <span class="location-tag">
                            <i class="bi bi-geo-alt"></i>
                            <?php echo sanitize($loc); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Company Info & Reviews -->
                <div class="car-content-card">
    <div class="company-header" style="margin-bottom: 20px;">
<div class="company-logo">
    <?php if (!empty($car['logo'])): ?>
        <?php 
        $logoUrl = getImageUrl($car['logo'], 'car');
        $companyName = sanitize($car['company_name']);
        ?>
        <img src="<?php echo $logoUrl; ?>" 
             alt="<?php echo $companyName; ?>"
             style="width: 100%; height: 100%; object-fit: cover;">
    <?php else: ?>
        <i class="bi bi-building"></i>
    <?php endif; ?>
</div>
        <div class="company-info">
            <h4><?php echo sanitize($car['company_name']); ?></h4>
            <div class="company-rating">
                <i class="bi bi-star-fill"></i>
                <span><?php echo number_format($car['company_rating'], 1); ?> (<?php echo $car['company_reviews']; ?> reviews)</span>
            </div>
        </div>
    </div>
                    
                    <?php if (!empty($reviews)): ?>
                    <h3 class="card-title" style="margin-top: 20px;">
                        <i class="bi bi-star-fill text-warning"></i>
                        Recent Reviews
                    </h3>
                    
                    <div class="reviews-section">
                        <?php foreach ($reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="reviewer-info">
                                    <div class="reviewer-avatar">
                                        <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="reviewer-name">
                                            <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?>
                                        </div>
                                        <div class="review-date"><?php echo timeAgo($review['created_at']); ?></div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>" style="font-size: 0.75rem;"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <div class="review-text">
                                <?php echo sanitize($review['comment']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column - Sticky Booking Sidebar -->
            <div class="car-sidebar">
                <div class="booking-card">
                    <div class="price-display">
                        <div class="price-amount"><?php echo formatPrice($car['daily_rate']); ?></div>
                        <div class="price-period">per day</div>
                        <?php if ($car['weekly_rate']): ?>
                        <div class="price-weekly">
                            or <strong><?php echo formatPrice($car['weekly_rate']); ?></strong>/week
                        </div>
                        <?php endif; ?>
                    </div>

                    <form class="booking-form" id="bookingForm" method="GET" action="booking.php">
                        <input type="hidden" name="car_id" value="<?php echo $car['car_id']; ?>">
                        
                        <div class="form-group">
                            <label>Pickup Location</label>
                            <select name="pickup_location" class="form-select" required>
                                <option value="">Select location</option>
                                <?php foreach ($pickupLocs as $loc): ?>
                                <option value="<?php echo sanitize($loc); ?>"><?php echo sanitize($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="date-grid">
                            <div class="form-group">
                                <label>Pickup Date</label>
                                <input type="date" name="pickup_date" value="<?php echo $pickupDate; ?>" 
                                       min="<?php echo date('Y-m-d'); ?>" required onchange="updateDates()">
                            </div>
                            <div class="form-group">
                                <label>Return Date</label>
                                <input type="date" name="return_date" value="<?php echo $returnDate; ?>" required>
                            </div>
                        </div>

                        <!-- Price Breakdown (dynamic) -->
                        <div class="price-breakdown" id="priceBreakdown">
                            <div class="price-row">
                                <span class="price-label">Daily rate × <span id="daysCount"><?php echo $days; ?></span> days</span>
                                <span class="price-value"><?php echo formatPrice($car['daily_rate'] * $days); ?></span>
                            </div>
                            <?php if ($car['insurance_included']): ?>
                            <div class="price-row">
                                <span class="price-label">Insurance</span>
                                <span class="price-value">Included</span>
                            </div>
                            <?php endif; ?>
                            <div class="price-row total">
                                <span class="price-label">Total</span>
                                <span class="price-value total" id="totalPrice"><?php echo formatPrice($car['daily_rate'] * $days); ?></span>
                            </div>
                        </div>

                        <button type="submit" class="btn-reserve">
                            Continue to Book
                        </button>
                        
                        <div class="security-note">
                            <i class="bi bi-shield-check"></i>
                            <span>Free cancellation • No hidden fees</span>
                        </div>
                    </form>
                </div>

<!-- Company Contact Card -->
<div class="company-card">
    <div class="company-header">
<div class="company-logo">
    <?php if (!empty($car['logo'])): ?>
        <?php 
        $logoUrl = getImageUrl($car['logo'], 'car');
        $companyName = sanitize($car['company_name']);
        ?>
        <img src="<?php echo $logoUrl; ?>" 
             alt="<?php echo $companyName; ?>"
             style="width: 100%; height: 100%; object-fit: cover;">
    <?php else: ?>
        <i class="bi bi-building"></i>
    <?php endif; ?>
</div>
        <div>
            <h4><?php echo sanitize($car['company_name']); ?></h4>
            <div class="company-rating">
                <i class="bi bi-star-fill"></i>
                <span><?php echo number_format($car['company_rating'], 1); ?></span>
                <span class="verified-badge" style="margin-left: 8px;">
                    <i class="bi bi-patch-check-fill"></i> Verified
                </span>
            </div>
        </div>
    </div>
    
    <div class="company-contact">
        <div><i class="bi bi-telephone"></i> <?php echo $car['phone'] ?? 'Contact for details'; ?></div>
        <div><i class="bi bi-envelope"></i> <?php echo $car['email'] ?? 'info@company.com'; ?></div>
        <div><i class="bi bi-clock"></i> <?php echo $car['operating_hours'] ?? 'Mon-Sun: 08:00-20:00'; ?></div>
    </div>
</div>
            </div>
        </div>

        <!-- Similar Cars -->
        <?php if (!empty($similarCars)): ?>
        <div class="similar-section">
            <h2 class="card-title" style="font-size: 1.25rem;">More from <?php echo sanitize($car['company_name']); ?></h2>
            <div class="similar-grid">
                <?php foreach ($similarCars as $similar): ?>
                <a href="detail.php?id=<?php echo $similar['car_id']; ?>" class="similar-card">
                    <div class="similar-image">
                        <?php 
                        $simImages = json_decode($similar['images'] ?? '[]', true);
                        $simImage = $simImages[0] ?? '';
                        ?>
                        <img src="<?php echo getImageUrl($simImage, 'car'); ?>" 
                             alt="<?php echo sanitize($similar['brand'] . ' ' . $similar['model']); ?>"
                             onerror="this.src='https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=400&q=60'">
                    </div>
                    <div class="similar-content">
                        <div class="similar-title"><?php echo sanitize($similar['brand'] . ' ' . $similar['model']); ?></div>
                        <div class="similar-type"><?php echo ucfirst($similar['car_type']); ?> • <?php echo $similar['seats']; ?> seats</div>
                        <div class="similar-footer">
                            <?php if ($similar['avg_rating']): ?>
                            <span class="similar-rating"><?php echo number_format($similar['avg_rating'], 1); ?></span>
                            <?php endif; ?>
                            <div class="similar-price">
                                <span class="similar-price-value"><?php echo formatPrice($similar['daily_rate']); ?></span>
                                <span class="similar-price-unit">/day</span>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// Gallery Functions
// ============================================
function changeMainImage(thumbnail, imageSrc) {
    document.querySelector('.main-image img').src = imageSrc;
    document.querySelectorAll('.thumbnail-item').forEach(item => {
        item.classList.remove('active');
    });
    thumbnail.classList.add('active');
}



// ============================================
// Date and Price Calculations
// ============================================
function updateDates() {
    const pickup = document.querySelector('input[name="pickup_date"]').value;
    const returnDate = document.querySelector('input[name="return_date"]').value;
    
    if (pickup && returnDate) {
        if (new Date(returnDate) <= new Date(pickup)) {
            const nextDay = new Date(pickup);
            nextDay.setDate(nextDay.getDate() + 1);
            document.querySelector('input[name="return_date"]').value = nextDay.toISOString().split('T')[0];
        }
        calculateTotal();
    }
}

function calculateTotal() {
    const pickup = new Date(document.querySelector('input[name="pickup_date"]').value);
    const returnDate = new Date(document.querySelector('input[name="return_date"]').value);
    const days = Math.max(1, Math.ceil((returnDate - pickup) / (1000 * 60 * 60 * 24)));
    
    document.getElementById('daysCount').textContent = days;
    
    const dailyRate = <?php echo $car['daily_rate']; ?>;
    const total = dailyRate * days;
    
    document.getElementById('totalPrice').textContent = formatCurrency(total);
}

// Set min return date based on pickup
document.querySelector('input[name="pickup_date"]').addEventListener('change', function() {
    const returnInput = document.querySelector('input[name="return_date"]');
    const minReturn = new Date(this.value);
    minReturn.setDate(minReturn.getDate() + 1);
    returnInput.min = minReturn.toISOString().split('T')[0];
    updateDates();
});

// ============================================
// Utility Functions
// ============================================
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function shareCar() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo addslashes($car['brand'] . ' ' . $car['model']); ?>',
            text: 'Check out this car on GoRwanda+',
            url: window.location.href
        });
    } else {
        navigator.clipboard.writeText(window.location.href);
        alert('Link copied to clipboard!');
    }
}

function toggleSave() {
    const btn = document.getElementById('saveBtn');
    const isSaved = btn.classList.contains('saved');
    
    if (isSaved) {
        btn.classList.remove('saved');
        btn.innerHTML = '<i class="bi bi-heart"></i><span class="d-none d-sm-inline">Save</span>';
    } else {
        btn.classList.add('saved');
        btn.innerHTML = '<i class="bi bi-heart-fill"></i><span class="d-none d-sm-inline">Saved</span>';
    }
}

// Initialize date restrictions
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    const pickupInput = document.querySelector('input[name="pickup_date"]');
    const returnInput = document.querySelector('input[name="return_date"]');
    
    pickupInput.min = today;
    
    if (!pickupInput.value) {
        pickupInput.value = today;
    }
    
    const minReturn = new Date(pickupInput.value);
    minReturn.setDate(minReturn.getDate() + 1);
    returnInput.min = minReturn.toISOString().split('T')[0];
    
    calculateTotal();
});
</script>

<?php require_once '../includes/footer.php'; ?>