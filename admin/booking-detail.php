<?php
$bookingId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$bookingId) {
    header('Location: bookings.php');
    exit;
}

$pageTitle = 'Booking Details';
require_once 'includes/admin_header.php';

$db = getDB();

// Get booking details with all related information
$stmt = $db->prepare("
    SELECT 
        b.*,
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.profile_image,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.address
            WHEN b.booking_type = 'car_rental' THEN cr.address
            ELSE a.address
        END as item_address,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.phone
            WHEN b.booking_type = 'car_rental' THEN cr.phone
            ELSE NULL
        END as item_phone,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.email
            WHEN b.booking_type = 'car_rental' THEN cr.email
            ELSE NULL
        END as item_email,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.room_name
            WHEN b.booking_type = 'car_rental' THEN cf.license_plate
            ELSE t.tier_name
        END as item_detail,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.max_guests
            WHEN b.booking_type = 'car_rental' THEN cf.seats
            ELSE t.max_participants
        END as item_capacity,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.star_rating
            WHEN b.booking_type = 'car_rental' THEN NULL
            ELSE NULL
        END as item_rating,
        s.stay_id,
        cr.rental_id,
        a.attraction_id,
        sr.room_id,
        cf.car_id,
        t.tier_id,
        u2.first_name as owner_first,
        u2.last_name as owner_last,
        u2.email as owner_email,
        u2.phone as owner_phone
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    LEFT JOIN users u2 ON COALESCE(s.owner_id, cr.owner_id, a.owner_id) = u2.user_id
    WHERE b.booking_id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $newStatus = sanitize($_POST['status']);
    $cancellationReason = isset($_POST['cancellation_reason']) ? sanitize($_POST['cancellation_reason']) : null;
    
    $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = NOW(), cancellation_reason = ? WHERE booking_id = ?");
    $stmt->execute([$newStatus, $cancellationReason, $bookingId]);
    $_SESSION['success'] = "Booking status updated to " . ucfirst($newStatus);
    header("Location: booking-detail.php?id=$bookingId");
    exit;
}

// Handle payment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $paymentStatus = sanitize($_POST['payment_status']);
    $paymentReference = sanitize($_POST['payment_reference']);
    
    $stmt = $db->prepare("UPDATE bookings SET payment_status = ?, payment_reference = ?, updated_at = NOW() WHERE booking_id = ?");
    $stmt->execute([$paymentStatus, $paymentReference, $bookingId]);
    $_SESSION['success'] = "Payment status updated to " . ucfirst($paymentStatus);
    header("Location: booking-detail.php?id=$bookingId");
    exit;
}

// Get booking timeline/activity
$stmt = $db->prepare("
    SELECT * FROM activity_logs 
    WHERE entity_type = 'booking' AND entity_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$bookingId]);
$activities = $stmt->fetchAll();

// Check if review exists
$stmt = $db->prepare("SELECT * FROM reviews WHERE booking_id = ?");
$stmt->execute([$bookingId]);
$review = $stmt->fetch();

// Calculate totals
$subtotal = $booking['total_amount'];
$taxAmount = $booking['tax_amount'];
$commissionAmount = $booking['commission_amount'];
$netAmount = $subtotal - $commissionAmount;

// Get booking type icon and color
$bookingTypeInfo = [
    'stay' => ['icon' => '🏨', 'color' => '#003b95', 'label' => 'Stay / Accommodation'],
    'car_rental' => ['icon' => '🚗', 'color' => '#9333ea', 'label' => 'Car Rental'],
    'attraction' => ['icon' => '🎟️', 'color' => '#ff8c00', 'label' => 'Experience / Attraction']
];
$typeInfo = $bookingTypeInfo[$booking['booking_type']];
?>

<style>
/* Booking Detail Styles */
.detail-header {
    margin-bottom: 24px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--booking-blue);
    text-decoration: none;
    font-size: 0.75rem;
    margin-bottom: 16px;
}

.back-link:hover {
    text-decoration: underline;
}

.booking-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.booking-title h1 {
    font-size: 1.25rem;
    font-weight: 700;
    margin: 0;
    font-family: monospace;
}

.booking-reference {
    color: var(--booking-text-light);
    font-size: 0.875rem;
    margin-bottom: 16px;
}

/* Stats Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.info-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.info-header h3 {
    font-size: 0.8125rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-body {
    padding: 16px;
}

.info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid var(--booking-border);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    width: 110px;
    font-weight: 600;
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.info-value {
    flex: 1;
    font-size: 0.75rem;
    color: var(--booking-text);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-confirmed {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-completed {
    background: #e1f5fe;
    color: #0288d1;
}

.status-cancelled {
    background: #fce8e8;
    color: var(--booking-danger);
}

.payment-paid {
    background: #e6f4ea;
    color: var(--booking-success);
}

.payment-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.payment-refunded {
    background: #f3e5f5;
    color: #7b1fa2;
}

.payment-failed {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Price Breakdown */
.price-breakdown {
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-top: 12px;
}

.price-row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 0.75rem;
}

.price-row.total {
    border-top: 1px solid var(--booking-border);
    margin-top: 6px;
    padding-top: 10px;
    font-weight: 700;
    font-size: 0.875rem;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.action-btn {
    padding: 8px 20px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.action-btn.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.action-btn.success {
    background: var(--booking-success);
    color: var(--booking-white);
}

.action-btn.warning {
    background: var(--booking-warning);
    color: var(--booking-white);
}

.action-btn.danger {
    background: var(--booking-danger);
    color: var(--booking-white);
}

.action-btn.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* Timeline */
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -20px;
    top: 5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--booking-blue);
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -16px;
    top: 15px;
    width: 2px;
    height: calc(100% - 10px);
    background: var(--booking-border);
}

.timeline-item:last-child::after {
    display: none;
}

.timeline-date {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.timeline-title {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.timeline-desc {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 500px;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid rgba(0,128,9,0.2);
}

/* Rating Stars */
.rating-stars {
    display: inline-flex;
    gap: 2px;
}

.rating-stars i {
    font-size: 0.75rem;
    color: #ffc107;
}

/* Responsive */
@media (max-width: 1024px) {
    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .booking-title {
        flex-direction: column;
    }
    .action-buttons {
        justify-content: center;
    }
}
</style>

<div class="detail-header">
    <a href="bookings.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Bookings
    </a>
    
    <div class="booking-title">
        <div>
            <h1><?php echo $booking['booking_reference']; ?></h1>
            <div class="booking-reference">
                Created on <?php echo date('F j, Y \a\t g:i A', strtotime($booking['created_at'])); ?>
            </div>
        </div>
        <div>
            <span class="status-badge status-<?php echo $booking['status']; ?>">
                <i class="bi bi-<?php echo $booking['status'] == 'confirmed' ? 'check-circle' : ($booking['status'] == 'pending' ? 'clock' : ($booking['status'] == 'completed' ? 'check2-all' : 'x-circle')); ?>"></i>
                <?php echo ucfirst($booking['status']); ?>
            </span>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Main Info Grid -->
<div class="info-grid">
    <!-- Booking Details -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-info-circle"></i> Booking Details</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Booking Type</div>
                <div class="info-value">
                    <?php echo $typeInfo['icon']; ?> <?php echo $typeInfo['label']; ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Item</div>
                <div class="info-value">
                    <strong><?php echo sanitize($booking['item_name']); ?></strong>
                    <?php if ($booking['item_detail']): ?>
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light); margin-top: 2px;">
                        <?php echo sanitize($booking['item_detail']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($booking['item_rating']): ?>
            <div class="info-row">
                <div class="info-label">Rating</div>
                <div class="info-value">
                    <div class="rating-stars">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $booking['item_rating'] ? '' : 'empty'; ?>" style="<?php echo $i <= $booking['item_rating'] ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Location</div>
                <div class="info-value"><?php echo sanitize($booking['item_address'] ?: 'Address not provided'); ?></div>
            </div>
            <?php if ($booking['item_phone']): ?>
            <div class="info-row">
                <div class="info-label">Contact</div>
                <div class="info-value">📞 <?php echo sanitize($booking['item_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Guest Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-person"></i> Guest Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Full Name</div>
                <div class="info-value">
                    <a href="user-detail.php?id=<?php echo $booking['user_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?>
                    </a>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo sanitize($booking['email']); ?></div>
            </div>
            <?php if ($booking['phone']): ?>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo sanitize($booking['phone']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($booking['guest_first_name'] && $booking['guest_last_name']): ?>
            <div class="info-row">
                <div class="info-label">Guest Name</div>
                <div class="info-value"><?php echo sanitize($booking['guest_first_name'] . ' ' . $booking['guest_last_name']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($booking['guest_email']): ?>
            <div class="info-row">
                <div class="info-label">Guest Email</div>
                <div class="info-value"><?php echo sanitize($booking['guest_email']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($booking['special_requests']): ?>
            <div class="info-row">
                <div class="info-label">Special Requests</div>
                <div class="info-value"><?php echo nl2br(sanitize($booking['special_requests'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Payment Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-cash-stack"></i> Payment Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Payment Status</div>
                <div class="info-value">
                    <span class="status-badge payment-<?php echo $booking['payment_status']; ?>" style="padding: 2px 10px;">
                        <i class="bi bi-<?php echo $booking['payment_status'] == 'paid' ? 'check-circle' : ($booking['payment_status'] == 'pending' ? 'clock' : 'arrow-repeat'); ?>"></i>
                        <?php echo ucfirst($booking['payment_status']); ?>
                    </span>
                </div>
            </div>
            <?php if ($booking['payment_method']): ?>
            <div class="info-row">
                <div class="info-label">Payment Method</div>
                <div class="info-value">
                    <?php 
                    $methodLabels = ['momo' => 'Mobile Money', 'card' => 'Credit/Debit Card', 'bank_transfer' => 'Bank Transfer'];
                    echo $methodLabels[$booking['payment_method']] ?? ucfirst($booking['payment_method']);
                    ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($booking['payment_reference']): ?>
            <div class="info-row">
                <div class="info-label">Transaction ID</div>
                <div class="info-value"><code><?php echo $booking['payment_reference']; ?></code></div>
            </div>
            <?php endif; ?>
            
            <div class="price-breakdown">
                <div class="price-row">
                    <span>Subtotal</span>
                    <span><?php echo formatPrice($subtotal); ?></span>
                </div>
                <div class="price-row">
                    <span>Tax (18%)</span>
                    <span><?php echo formatPrice($taxAmount); ?></span>
                </div>
                <div class="price-row">
                    <span>Platform Commission (<?php echo $booking['commission_amount'] > 0 ? round(($booking['commission_amount'] / $subtotal) * 100, 1) : 0; ?>%)</span>
                    <span>- <?php echo formatPrice($commissionAmount); ?></span>
                </div>
                <div class="price-row total">
                    <span>Partner Payout</span>
                    <span><?php echo formatPrice($netAmount); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Booking Specific Details -->
<div class="info-grid">
    <!-- Stay Details -->
    <?php if ($booking['booking_type'] == 'stay'): ?>
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-calendar-range"></i> Stay Details</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Check-in</div>
                <div class="info-value">📅 <?php echo date('l, F j, Y', strtotime($booking['check_in_date'])); ?> from <?php echo date('g:i A', strtotime($booking['check_in_time'] ?? '14:00:00')); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Check-out</div>
                <div class="info-value">📅 <?php echo date('l, F j, Y', strtotime($booking['check_out_date'])); ?> by <?php echo date('g:i A', strtotime($booking['check_out_time'] ?? '11:00:00')); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Nights</div>
                <div class="info-value"><?php echo $booking['num_nights']; ?> nights</div>
            </div>
            <div class="info-row">
                <div class="info-label">Guests</div>
                <div class="info-value"><?php echo $booking['num_guests']; ?> guest(s) (Max capacity: <?php echo $booking['item_capacity']; ?>)</div>
            </div>
            <div class="info-row">
                <div class="info-label">Unit Price</div>
                <div class="info-value"><?php echo formatPrice($booking['unit_price']); ?> / night</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Car Rental Details -->
    <?php if ($booking['booking_type'] == 'car_rental'): ?>
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-car-front"></i> Rental Details</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Pickup</div>
                <div class="info-value">
                    📍 <?php echo sanitize($booking['pickup_location'] ?: 'Not specified'); ?><br>
                    📅 <?php echo date('l, F j, Y \a\t g:i A', strtotime($booking['pickup_date'] . ' ' . ($booking['pickup_time'] ?? '10:00:00'))); ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Return</div>
                <div class="info-value">
                    📍 <?php echo sanitize($booking['return_location'] ?: 'Not specified'); ?><br>
                    📅 <?php echo date('l, F j, Y \a\t g:i A', strtotime($booking['return_date'] . ' ' . ($booking['return_time'] ?? '10:00:00'))); ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Vehicle</div>
                <div class="info-value">🚗 <?php echo sanitize($booking['item_name']); ?> (Seats: <?php echo $booking['item_capacity']; ?>)</div>
            </div>
            <div class="info-row">
                <div class="info-label">Daily Rate</div>
                <div class="info-value"><?php echo formatPrice($booking['unit_price']); ?> / day</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Experience Details -->
    <?php if ($booking['booking_type'] == 'attraction'): ?>
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-ticket-perforated"></i> Experience Details</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Date & Time</div>
                <div class="info-value">
                    📅 <?php echo date('l, F j, Y', strtotime($booking['experience_date'])); ?><br>
                    ⏰ <?php echo date('g:i A', strtotime($booking['start_time'])); ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Participants</div>
                <div class="info-value"><?php echo $booking['num_participants']; ?> person(s) (Max: <?php echo $booking['item_capacity'] ?: 'Unlimited'; ?>)</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tier</div>
                <div class="info-value"><?php echo sanitize($booking['item_detail']); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Price Type</div>
                <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $booking['price_type'] ?? 'per person')); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Vendor/Partner Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-building"></i> Partner Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Partner Name</div>
                <div class="info-value">
                    <a href="user-detail.php?id=<?php echo $booking['owner_id'] ?? 0; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($booking['owner_first'] . ' ' . $booking['owner_last']); ?>
                    </a>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Contact</div>
                <div class="info-value">
                    <?php if ($booking['owner_email']): ?>📧 <?php echo sanitize($booking['owner_email']); ?><br><?php endif; ?>
                    <?php if ($booking['owner_phone']): ?>📞 <?php echo sanitize($booking['owner_phone']); ?><?php endif; ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Commission</div>
                <div class="info-value"><?php echo $booking['commission_amount'] > 0 ? round(($booking['commission_amount'] / $subtotal) * 100, 1) : 0; ?>% (<?php echo formatPrice($booking['commission_amount']); ?>)</div>
            </div>
        </div>
    </div>
</div>

<!-- Cancellation Info -->
<?php if ($booking['status'] == 'cancelled' && $booking['cancellation_reason']): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-x-circle"></i> Cancellation Information</h3>
    </div>
    <div class="info-body">
        <div class="info-row">
            <div class="info-label">Cancelled On</div>
            <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($booking['cancellation_date'])); ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Reason</div>
            <div class="info-value"><?php echo nl2br(sanitize($booking['cancellation_reason'])); ?></div>
        </div>
        <?php if ($booking['refund_amount'] > 0): ?>
        <div class="info-row">
            <div class="info-label">Refund Amount</div>
            <div class="info-value"><?php echo formatPrice($booking['refund_amount']); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Review Section -->
<?php if ($review): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-star"></i> Guest Review</h3>
    </div>
    <div class="info-body">
        <div class="info-row">
            <div class="info-label">Rating</div>
            <div class="info-value">
                <div class="rating-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= $review['overall_rating'] ? '' : 'empty'; ?>" style="<?php echo $i <= $review['overall_rating'] ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <?php if ($review['title']): ?>
        <div class="info-row">
            <div class="info-label">Title</div>
            <div class="info-value"><?php echo sanitize($review['title']); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($review['comment']): ?>
        <div class="info-row">
            <div class="info-label">Comment</div>
            <div class="info-value"><?php echo nl2br(sanitize($review['comment'])); ?></div>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <div class="info-label">Posted</div>
            <div class="info-value"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Activity Timeline -->
<?php if (!empty($activities)): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-clock-history"></i> Activity Timeline</h3>
    </div>
    <div class="info-body">
        <div class="timeline">
            <?php foreach ($activities as $activity): ?>
            <div class="timeline-item">
                <div class="timeline-date"><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></div>
                <div class="timeline-title"><?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?></div>
                <?php if ($activity['details']): 
                    $details = json_decode($activity['details'], true);
                ?>
                <div class="timeline-desc">
                    <?php if (isset($details['amount'])): ?>Amount: <?php echo formatPrice($details['amount']); ?><?php endif; ?>
                    <?php if (isset($details['status'])): ?>Status: <?php echo ucfirst($details['status']); ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Action Buttons -->
<div class="action-buttons">
    <?php if ($booking['status'] == 'pending'): ?>
    <form method="POST" style="display: inline;" onsubmit="return confirm('Confirm this booking?')">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="status" value="confirmed">
        <button type="submit" class="action-btn success">
            <i class="bi bi-check-circle"></i> Confirm Booking
        </button>
    </form>
    <?php endif; ?>
    
    <?php if ($booking['status'] == 'confirmed'): ?>
    <form method="POST" style="display: inline;" onsubmit="return confirm('Mark this booking as completed?')">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="status" value="completed">
        <button type="submit" class="action-btn primary">
            <i class="bi bi-check2-all"></i> Mark Completed
        </button>
    </form>
    <?php endif; ?>
    
    <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
    <button class="action-btn danger" onclick="openCancelModal()">
        <i class="bi bi-x-circle"></i> Cancel Booking
    </button>
    <?php endif; ?>
    
    <?php if ($booking['payment_status'] == 'pending'): ?>
    <button class="action-btn primary" onclick="openPaymentModal()">
        <i class="bi bi-cash-stack"></i> Update Payment
    </button>
    <?php endif; ?>
    
    <a href="booking-invoice.php?id=<?php echo $bookingId; ?>" class="action-btn secondary" target="_blank">
        <i class="bi bi-printer"></i> Print Invoice
    </a>
    
    <?php if (!$review && $booking['status'] == 'completed'): ?>
    <a href="send-review-request.php?id=<?php echo $bookingId; ?>" class="action-btn secondary" onclick="return confirm('Send review request to guest?')">
        <i class="bi bi-envelope"></i> Request Review
    </a>
    <?php endif; ?>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST">
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="status" value="cancelled">
            <div class="modal-header">
                <h3>Cancel Booking</h3>
                <button type="button" class="modal-close" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Cancellation Reason</label>
                    <textarea name="cancellation_reason" class="form-control" rows="4" placeholder="Please provide reason for cancellation..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn secondary" onclick="closeCancelModal()">Close</button>
                <button type="submit" class="action-btn danger">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST">
            <input type="hidden" name="update_payment" value="1">
            <div class="modal-header">
                <h3>Update Payment Status</h3>
                <button type="button" class="modal-close" onclick="closePaymentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Payment Status</label>
                    <select name="payment_status" class="form-control" required>
                        <option value="paid" <?php echo $booking['payment_status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="pending" <?php echo $booking['payment_status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="refunded" <?php echo $booking['payment_status'] == 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                        <option value="failed" <?php echo $booking['payment_status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Transaction Reference</label>
                    <input type="text" name="payment_reference" class="form-control" value="<?php echo htmlspecialchars($booking['payment_reference']); ?>" placeholder="e.g., MOMO-123456">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn secondary" onclick="closePaymentModal()">Close</button>
                <button type="submit" class="action-btn primary">Update Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal() {
    document.getElementById('cancelModal').style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancelModal').style.display = 'none';
}

function openPaymentModal() {
    document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCancelModal();
        closePaymentModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const cancelModal = document.getElementById('cancelModal');
    const paymentModal = document.getElementById('paymentModal');
    if (e.target === cancelModal) closeCancelModal();
    if (e.target === paymentModal) closePaymentModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>