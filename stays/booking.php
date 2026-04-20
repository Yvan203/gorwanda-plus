<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$currentUser = getCurrentUser();

$id = intval($_GET['id'] ?? 0);
$roomId = intval($_GET['room'] ?? 0);
$checkin = $_GET['checkin'] ?? '';
$checkout = $_GET['checkout'] ?? '';
$guests = intval($_GET['guests'] ?? 2);

if (!$id || !$roomId || !$checkin || !$checkout) {
    setFlash('error', 'Missing booking information');
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Validate dates
if (strtotime($checkin) < strtotime(date('Y-m-d')) || strtotime($checkout) <= strtotime($checkin)) {
    setFlash('error', 'Invalid dates selected');
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Get stay and room details with all related data
$stmt = $db->prepare("
    SELECT 
        s.*, 
        l.name as location_name,
        l.latitude, l.longitude,
        sr.*,
        u.first_name as owner_name,
        u.phone as owner_phone,
        u.email as owner_email,
        (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
        (SELECT COUNT(*) FROM restaurants WHERE stay_id = s.stay_id AND is_active = 1) as restaurant_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ? AND sr.room_id = ? AND s.is_active = 1
");
$stmt->execute([$id, $roomId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Room not available');
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Calculate nights and pricing
$checkinDate = new DateTime($checkin);
$checkoutDate = new DateTime($checkout);
$nights = $checkinDate->diff($checkoutDate)->days;

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

// Calculate final price
$basePrice = $booking['base_price'];
$pricePerNight = $specialPrice ? $specialPrice['price_override'] : ($basePrice * $seasonMultiplier);
$totalRoomPrice = $pricePerNight * $nights;

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
$stmt->execute([$booking['owner_id'], $nights, $id]);
$offer = $stmt->fetch();

// Apply offer discount
$discountAmount = 0;
if ($offer) {
    if ($offer['offer_type'] == 'percentage') {
        $discountAmount = $totalRoomPrice * ($offer['discount_value'] / 100);
    } elseif ($offer['offer_type'] == 'fixed') {
        $discountAmount = min($offer['discount_value'], $totalRoomPrice);
    } elseif ($offer['offer_type'] == 'free_night') {
        $freeNights = min($offer['discount_value'], $nights - 1);
        $discountAmount = $pricePerNight * $freeNights;
    }
}

$subtotal = $totalRoomPrice - $discountAmount;
$taxRate = 0.18; // 18% VAT
$taxAmount = $subtotal * $taxRate;
$serviceFee = $subtotal * 0.10; // 10% service fee
$totalAmount = $subtotal + $taxAmount + $serviceFee;

// Get room amenities
$roomAmenities = json_decode($booking['room_amenities'] ?? '[]', true);
$allAmenities = [
    'ac' => 'Air conditioning',
    'tv' => 'Flat-screen TV',
    'wifi' => 'Free WiFi',
    'minibar' => 'Minibar',
    'safe' => 'In-room safe',
    'balcony' => 'Balcony',
    'bathtub' => 'Bathtub',
    'shower' => 'Rain shower',
    'coffee_maker' => 'Coffee maker',
    'desk' => 'Work desk',
    'hair_dryer' => 'Hair dryer',
    'iron' => 'Ironing facilities'
];

// Get property amenities
$propertyAmenities = json_decode($booking['amenities'] ?? '[]', true);
$amenityIcons = [
    'wifi' => 'bi-wifi',
    'pool' => 'bi-water',
    'parking' => 'bi-p-circle',
    'restaurant' => 'bi-shop',
    'spa' => 'bi-droplet',
    'gym' => 'bi-bicycle',
    'bar' => 'bi-cup-straw',
    'room_service' => 'bi-bell',
    'ac' => 'bi-snow',
    'breakfast' => 'bi-egg-fried',
    'airport_shuttle' => 'bi-bus-front',
    'laundry' => 'bi-basket'
];

// Get similar rooms for upsell
$stmt = $db->prepare("
    SELECT sr.*, 
           (SELECT COUNT(*) FROM stay_availability sa WHERE sa.room_id = sr.room_id AND sa.date BETWEEN ? AND ? AND sa.is_blocked = 0) as available_days
    FROM stay_rooms sr
    WHERE sr.stay_id = ? AND sr.room_id != ? AND sr.is_active = 1
    AND sr.max_guests >= ?
    ORDER BY sr.base_price ASC
    LIMIT 2
");
$stmt->execute([$checkin, $checkout, $id, $roomId, $guests]);
$similarRooms = $stmt->fetchAll();

// Get cancellation policy
$policies = json_decode($booking['policies'] ?? '{}', true);
$cancellationPolicy = $policies['cancellation'] ?? 'free_24h';
$cancellationDeadline = date('Y-m-d H:i', strtotime($checkin . ' -1 day 23:59:59'));

$pageTitle = 'Complete your booking';
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
/* Booking.com Exact Styling */
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

* {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

body {
    background: var(--booking-gray);
    color: var(--booking-text);
    line-height: 1.5;
    font-size: 14px;
}

/* Booking Container */
.booking-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 20px;
}

/* Progress Bar */
.booking-progress {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
}

.progress-steps {
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    margin-bottom: 16px;
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
    width: 33%;
    transition: width 0.3s;
}

/* Main Grid */
.booking-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
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
.guest-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
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

/* Contact Options */
.contact-options {
    display: flex;
    gap: 20px;
    margin-top: 16px;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-md);
}

.contact-option {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.contact-option input[type="radio"] {
    width: 18px;
    height: 18px;
    accent-color: var(--booking-blue);
}

/* Special Requests */
textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

.char-count {
    text-align: right;
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-top: 4px;
}

/* Price Breakdown */
.price-breakdown {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 20px;
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

.hotel-summary {
    display: flex;
    gap: 12px;
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
}

.hotel-image {
    width: 80px;
    height: 80px;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--booking-gray);
    flex-shrink: 0;
}

.hotel-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hotel-info h4 {
    font-size: 0.9375rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.hotel-info .location {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 8px;
}

.hotel-info .rating {
    display: flex;
    align-items: center;
    gap: 6px;
}

.rating-badge {
    background: var(--booking-blue);
    color: white;
    padding: 2px 6px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 0.75rem;
}

.rating-text {
    font-size: 0.75rem;
    font-weight: 600;
}

.room-details {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
}

.room-name {
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 0.9375rem;
}

.room-meta {
    display: flex;
    gap: 16px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
}

.room-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.room-amenities {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}

.amenity-tag {
    background: var(--booking-gray);
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.price-list {
    padding: 20px;
}

.price-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 0.875rem;
}

.price-item.total {
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

.discount-value {
    color: var(--booking-success);
}

.original-price {
    text-decoration: line-through;
    color: var(--booking-text-light);
    margin-right: 8px;
    font-size: 0.8125rem;
}

/* Offer Banner */
.offer-banner {
    background: linear-gradient(135deg, #fff4e6, #ffe4cc);
    padding: 16px 20px;
    margin: 0 20px 20px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    gap: 12px;
    border-left: 4px solid var(--booking-warning);
}

.offer-icon {
    width: 40px;
    height: 40px;
    background: var(--booking-warning);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
}

.offer-content h4 {
    font-weight: 700;
    margin-bottom: 2px;
    font-size: 0.9375rem;
}

.offer-content p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

/* Cancellation Policy */
.cancellation-info {
    background: #e6f4ea;
    padding: 16px 20px;
    margin: 0 20px 20px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.cancellation-icon {
    color: var(--booking-success);
    font-size: 1.25rem;
}

.cancellation-content h4 {
    font-weight: 700;
    margin-bottom: 2px;
    font-size: 0.875rem;
    color: var(--booking-success);
}

.cancellation-content p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

/* Similar Rooms */
.similar-rooms {
    margin-top: 24px;
}

.similar-rooms h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
}

.similar-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.similar-room-card {
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 12px;
    background: white;
    cursor: pointer;
    transition: all 0.2s;
}

.similar-room-card:hover {
    border-color: var(--booking-blue);
    box-shadow: var(--shadow-sm);
}

.similar-room-name {
    font-weight: 600;
    font-size: 0.8125rem;
    margin-bottom: 4px;
}

.similar-room-price {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-success);
}

.similar-room-old {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-decoration: line-through;
}

/* Payment Methods */
.payment-methods {
    display: grid;
    gap: 12px;
}

.payment-method {
    border: 2px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 16px;
}

.payment-method:hover {
    border-color: var(--booking-blue);
}

.payment-method.selected {
    border-color: var(--booking-blue);
    background: var(--booking-light);
}

.payment-radio {
    width: 20px;
    height: 20px;
    border: 2px solid var(--booking-border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.payment-radio.selected {
    border-color: var(--booking-blue);
}

.payment-radio.selected::after {
    content: '';
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--booking-blue);
}

.payment-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    background: var(--booking-gray);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--booking-blue);
    flex-shrink: 0;
}

.payment-info {
    flex: 1;
}

.payment-name {
    font-weight: 700;
    margin-bottom: 2px;
    font-size: 0.9375rem;
}

.payment-desc {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

/* Terms */
.terms-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 20px 0;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-md);
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
.btn-complete-booking {
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

.btn-complete-booking:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-complete-booking:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.security-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 12px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
    border-top: 1px solid var(--booking-border);
}

.security-badge i {
    color: var(--booking-success);
}

/* Responsive */
@media (max-width: 992px) {
    .booking-grid {
        grid-template-columns: 1fr;
    }
    
    .price-breakdown {
        position: static;
        order: -1;
    }
}

@media (max-width: 768px) {
    .guest-info-grid {
        grid-template-columns: 1fr;
    }
    
    .similar-grid {
        grid-template-columns: 1fr;
    }
    
    .payment-method {
        flex-wrap: wrap;
    }
    
    .progress-step .step-label {
        font-size: 0.6875rem;
    }
}
</style>

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
                <span class="step-label active">Payment</span>
            </div>
            <div class="progress-step">
                <div class="step-number">3</div>
                <span class="step-label">Confirmation</span>
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
            <!-- Guest Information -->
            <div class="form-section">
                <h2 class="section-title">
                    <i class="bi bi-person-circle"></i>
                    Your information
                </h2>
                
                <form id="bookingForm" method="POST" action="process-booking.php">
                    <input type="hidden" name="stay_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="room_id" value="<?php echo $roomId; ?>">
                    <input type="hidden" name="checkin" value="<?php echo $checkin; ?>">
                    <input type="hidden" name="checkout" value="<?php echo $checkout; ?>">
                    <input type="hidden" name="guests" value="<?php echo $guests; ?>">
                    <input type="hidden" name="nights" value="<?php echo $nights; ?>">
                    <input type="hidden" name="price_per_night" value="<?php echo $pricePerNight; ?>">
                    <input type="hidden" name="total_amount" value="<?php echo $totalAmount; ?>">
                    
                    <div class="guest-info-grid">
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
                        
                        <div class="form-group">
                            <label class="form-label">Email address <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo sanitize($currentUser['email']); ?>" required>
                            <div class="form-text">Booking confirmation will be sent here</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone number <span class="required">*</span></label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo sanitize($currentUser['phone'] ?? ''); ?>" required>
                            <div class="form-text">For urgent contact about your stay</div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">How should we contact you?</label>
                        <div class="contact-options">
                            <label class="contact-option">
                                <input type="radio" name="contact_method" value="email" checked>
                                <span>Email</span>
                            </label>
                            <label class="contact-option">
                                <input type="radio" name="contact_method" value="phone">
                                <span>Phone/SMS</span>
                            </label>
                            <label class="contact-option">
                                <input type="radio" name="contact_method" value="whatsapp">
                                <span>WhatsApp</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Special Requests -->
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="bi bi-chat-text"></i>
                        Special requests (optional)
                    </h2>
                    
                    <div class="form-group">
                        <textarea name="special_requests" class="form-control" 
                                  placeholder="Let us know if you have any special requests (e.g., early check-in, room preferences, allergies, etc.)" 
                                  maxlength="500"></textarea>
                        <div class="char-count"><span id="charCount">0</span>/500 characters</div>
                    </div>
                </div>
                
                <!-- Payment Method -->
                <div class="form-section">
                    <h2 class="section-title">
                        <i class="bi bi-credit-card"></i>
                        Payment method
                    </h2>
                    
                    <div class="payment-methods">
                        <!-- MTN MoMo -->
                        <label class="payment-method">
                            <div class="payment-radio"></div>
                            <input type="radio" name="payment_method" value="momo" checked style="display: none;">
                            <div class="payment-icon" style="background: #ffcc00; color: #333;">
                                <i class="bi bi-phone-fill"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">MTN Mobile Money</div>
                                <div class="payment-desc">Pay instantly with MoMo • No fees</div>
                            </div>
                        </label>
                        
                        <!-- Credit/Debit Card -->
                        <label class="payment-method">
                            <div class="payment-radio"></div>
                            <input type="radio" name="payment_method" value="card" style="display: none;">
                            <div class="payment-icon" style="background: #1a1a1a; color: white;">
                                <i class="bi bi-credit-card"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">Credit / Debit Card</div>
                                <div class="payment-desc">Visa, Mastercard, Amex • Secure payment</div>
                            </div>
                        </label>
                        
                        <!-- Bank Transfer -->
                        <label class="payment-method">
                            <div class="payment-radio"></div>
                            <input type="radio" name="payment_method" value="bank" style="display: none;">
                            <div class="payment-icon" style="background: #0047ab; color: white;">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">Bank Transfer</div>
                                <div class="payment-desc">Pay via bank transfer • 24h confirmation</div>
                            </div>
                        </label>
                    </div>
                    
                    <!-- Terms and Conditions -->
                    <div class="terms-checkbox">
                        <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                        <label for="agreeTerms">
                            I agree to the <a href="#" target="_blank">Terms of Service</a>, 
                            <a href="#" target="_blank">Privacy Policy</a>, and 
                            <a href="#" target="_blank">Cancellation Policy</a>. 
                            I confirm that I am at least 18 years old.
                        </label>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn-complete-booking" id="submitBtn" disabled>
                        Complete Booking • <?php echo formatPrice($totalAmount); ?>
                    </button>
                    
                    <div class="security-badge">
                        <span><i class="bi bi-shield-check"></i> SSL Secured</span>
                        <span><i class="bi bi-lock"></i> Encrypted</span>
                        <span><i class="bi bi-check-circle"></i> Verified</span>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Right Column - Price Breakdown -->
        <div class="price-breakdown">
            <div class="price-header">
                <h3>Your booking summary</h3>
                <p><?php echo $nights; ?> night<?php echo $nights > 1 ? 's' : ''; ?> • <?php echo $guests; ?> guest<?php echo $guests > 1 ? 's' : ''; ?></p>
            </div>
            
            <!-- Hotel Summary -->
            <div class="hotel-summary">
                <div class="hotel-image">
                    <img src="<?php echo getImageUrl($booking['main_image'] ?? '', 'stay'); ?>" 
                         alt="<?php echo sanitize($booking['stay_name']); ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=100&q=80'">
                </div>
                <div class="hotel-info">
                    <h4><?php echo sanitize($booking['stay_name']); ?></h4>
                    <div class="location">
                        <i class="bi bi-geo-alt"></i>
                        <?php echo sanitize($booking['city'] ?? $booking['location_name'] ?? 'Rwanda'); ?>
                    </div>
                    <?php if ($booking['avg_rating']): ?>
                    <div class="rating">
                        <span class="rating-badge"><?php echo number_format($booking['avg_rating'], 1); ?></span>
                        <span class="rating-text"><?php echo getReviewLabel($booking['avg_rating'])[0]; ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Room Details -->
            <div class="room-details">
                <div class="room-name"><?php echo sanitize($booking['room_name']); ?></div>
                <div class="room-meta">
                    <span><i class="bi bi-people"></i> Max <?php echo $booking['max_guests']; ?> guests</span>
                    <span><i class="bi bi-rulers"></i> <?php echo $booking['size_sqm']; ?> m²</span>
                    <span><i class="bi bi-bed"></i> <?php echo $booking['bed_configuration']; ?></span>
                </div>
                
                <?php if (!empty($roomAmenities)): ?>
                <div class="room-amenities">
                    <?php foreach (array_slice($roomAmenities, 0, 4) as $amenity): ?>
                    <span class="amenity-tag">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <?php echo $allAmenities[$amenity] ?? ucfirst($amenity); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Special Offer Banner (if any) -->
            <?php if ($offer): ?>
            <div class="offer-banner">
                <div class="offer-icon">
                    <i class="bi bi-tag-fill"></i>
                </div>
                <div class="offer-content">
                    <h4><?php echo sanitize($offer['offer_name']); ?></h4>
                    <p>
                        <?php if ($offer['offer_type'] == 'percentage'): ?>
                            <?php echo $offer['discount_value']; ?>% discount applied
                        <?php elseif ($offer['offer_type'] == 'fixed'): ?>
                            <?php echo formatPrice($offer['discount_value']); ?> off
                        <?php elseif ($offer['offer_type'] == 'free_night'): ?>
                            <?php echo $offer['discount_value']; ?> free night<?php echo $offer['discount_value'] > 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Price Breakdown -->
            <div class="price-list">
                <div class="price-item">
                    <span class="price-label">Base price (<?php echo $nights; ?> nights)</span>
                    <span class="price-value">
                        <?php if ($pricePerNight != $basePrice): ?>
                        <span class="original-price"><?php echo formatPrice($basePrice * $nights); ?></span>
                        <?php endif; ?>
                        <?php echo formatPrice($totalRoomPrice); ?>
                    </span>
                </div>
                
                <?php if ($discountAmount > 0): ?>
                <div class="price-item">
                    <span class="price-label">Special offer discount</span>
                    <span class="price-value discount-value">-<?php echo formatPrice($discountAmount); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="price-item">
                    <span class="price-label">Subtotal</span>
                    <span class="price-value"><?php echo formatPrice($subtotal); ?></span>
                </div>
                
                <div class="price-item">
                    <span class="price-label">Tax (18% VAT)</span>
                    <span class="price-value"><?php echo formatPrice($taxAmount); ?></span>
                </div>
                
                <div class="price-item">
                    <span class="price-label">Service fee</span>
                    <span class="price-value"><?php echo formatPrice($serviceFee); ?></span>
                </div>
                
                <div class="price-item total">
                    <span class="price-label">Total</span>
                    <span class="price-value total"><?php echo formatPrice($totalAmount); ?></span>
                </div>
            </div>
            
            <!-- Cancellation Policy -->
            <div class="cancellation-info">
                <div class="cancellation-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="cancellation-content">
                    <h4>Free cancellation</h4>
                    <p>Cancel before <?php echo date('M d, Y \a\t h:i A', strtotime($cancellationDeadline)); ?> for a full refund</p>
                </div>
            </div>
            
            <!-- Similar Rooms (Upsell) -->
            <?php if (!empty($similarRooms)): ?>
            <div style="padding: 0 20px 20px;">
                <div class="similar-rooms">
                    <h3>Other room options</h3>
                    <div class="similar-grid">
                        <?php foreach ($similarRooms as $room): 
                            $roomTotal = $room['base_price'] * $nights;
                        ?>
                        <div class="similar-room-card" onclick="window.location.href='booking.php?id=<?php echo $id; ?>&room=<?php echo $room['room_id']; ?>&checkin=<?php echo $checkin; ?>&checkout=<?php echo $checkout; ?>&guests=<?php echo $guests; ?>'">
                            <div class="similar-room-name"><?php echo sanitize($room['room_name']); ?></div>
                            <div class="similar-room-price"><?php echo formatPrice($room['base_price']); ?>/night</div>
                            <div style="font-size: 0.6875rem; color: var(--booking-text-light); margin-top: 4px;">
                                <i class="bi bi-people"></i> Max <?php echo $room['max_guests']; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Payment method selection
document.querySelectorAll('.payment-method').forEach(method => {
    method.addEventListener('click', function() {
        document.querySelectorAll('.payment-method').forEach(m => {
            m.classList.remove('selected');
            m.querySelector('.payment-radio').classList.remove('selected');
        });
        this.classList.add('selected');
        this.querySelector('.payment-radio').classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;
    });
});

// Enable submit button only when terms are agreed
const termsCheckbox = document.getElementById('agreeTerms');
const submitBtn = document.getElementById('submitBtn');

termsCheckbox.addEventListener('change', function() {
    submitBtn.disabled = !this.checked;
});

// Character count for special requests
const specialRequests = document.querySelector('textarea[name="special_requests"]');
const charCount = document.getElementById('charCount');

if (specialRequests) {
    specialRequests.addEventListener('input', function() {
        const count = this.value.length;
        charCount.textContent = count;
        
        if (count > 450) {
            charCount.style.color = '#e21111';
        } else {
            charCount.style.color = '';
        }
    });
}

// Form validation
document.getElementById('bookingForm').addEventListener('submit', function(e) {
    const phone = document.querySelector('input[name="phone"]').value;
    const phoneRegex = /^[0-9+\-\s]{10,}$/;
    
    if (!phoneRegex.test(phone.replace(/\s/g, ''))) {
        e.preventDefault();
        alert('Please enter a valid phone number');
    }
});

// Format phone number as user types
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

// Warn user if they try to leave
let formModified = false;
document.querySelectorAll('input, textarea').forEach(field => {
    field.addEventListener('change', () => formModified = true);
});

window.addEventListener('beforeunload', function(e) {
    if (formModified && !submitBtn.disabled) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>