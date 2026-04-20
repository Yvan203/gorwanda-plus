<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

// ============================================
// DEBUG: Capture and display all parameters
// ============================================
$debug = [];

$attractionId = intval($_GET['attraction_id'] ?? 0);
$debug['attraction_id'] = [
    'raw' => $_GET['attraction_id'] ?? 'NOT SET',
    'processed' => $attractionId,
    'valid' => $attractionId > 0
];

$tierId = intval($_GET['tier_id'] ?? 0);
$debug['tier_id'] = [
    'raw' => $_GET['tier_id'] ?? 'NOT SET',
    'processed' => $tierId,
    'valid' => $tierId > 0
];

$date = $_GET['date'] ?? '';
$debug['date'] = [
    'raw' => $_GET['date'] ?? 'NOT SET',
    'processed' => $date,
    'valid' => !empty($date)
];

$participants = intval($_GET['participants'] ?? 0);
$debug['participants'] = [
    'raw' => $_GET['participants'] ?? 'NOT SET',
    'processed' => $participants,
    'valid' => $participants > 0
];

$startTime = $_GET['start_time'] ?? '';
$debug['start_time'] = [
    'raw' => $_GET['start_time'] ?? 'NOT SET',
    'processed' => $startTime,
    'valid' => true // Optional
];

// Check what's missing
$missing = [];
if (!$attractionId) $missing[] = 'attraction_id';
if (!$tierId) $missing[] = 'tier_id';
if (empty($date)) $missing[] = 'date';
if (!$participants) $missing[] = 'participants';

// ============================================
// VALIDATION WITH DETAILED ERROR
// ============================================
if (!empty($missing)) {
    $errorMsg = 'Missing booking information: ' . implode(', ', $missing);
    
    // Log for debugging (you can check error logs)
    error_log('BOOKING DEBUG - ' . $errorMsg);
    error_log('BOOKING DEBUG - Full GET: ' . print_r($_GET, true));
    error_log('BOOKING DEBUG - Full REQUEST: ' . print_r($_REQUEST, true));
    
    setFlash('error', $errorMsg);
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get attraction details
$stmt = $db->prepare("
    SELECT a.*, c.name as category_name, l.name as location_name,
           u.first_name as owner_name, u.last_name as owner_last, 
           u.user_id as owner_id, u.email as owner_email
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE a.attraction_id = ? AND a.is_active = 1 AND a.is_verified = 1
");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    setFlash('error', 'Attraction not found');
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

// Get tier details
$stmt = $db->prepare("
    SELECT * FROM attraction_tiers 
    WHERE tier_id = ? AND attraction_id = ? AND is_active = 1
");
$stmt->execute([$tierId, $attractionId]);
$tier = $stmt->fetch();

if (!$tier) {
    setFlash('error', 'Pricing tier not available');
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Check availability for selected date
$stmt = $db->prepare("
    SELECT * FROM attraction_availability 
    WHERE tier_id = ? AND date = ? 
");
$stmt->execute([$tierId, $date]);
$availability = $stmt->fetch();

// Calculate available spots
$maxBookings = $availability ? $availability['max_bookings'] : 10;
$bookedCount = $availability ? $availability['bookings_made'] : 0;
$availableSpots = $maxBookings - $bookedCount;

// Check if enough spots available
if ($availableSpots < $participants) {
    setFlash('error', "Only $availableSpots spots available for this date");
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Check if date is blocked
if ($availability && $availability['is_blocked']) {
    setFlash('error', 'This date is not available');
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Calculate prices
$unitPrice = $availability && $availability['price_override'] ? $availability['price_override'] : $tier['base_price'];
$totalAmount = $unitPrice * $participants;
$commissionAmount = $totalAmount * 0.10; // 10% commission
$taxAmount = $totalAmount * 0.18; // 18% VAT
$grandTotal = $totalAmount + $commissionAmount + $taxAmount;

// Get user details for pre-filling form
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    $guestFirstName = sanitize($_POST['first_name']);
    $guestLastName = sanitize($_POST['last_name']);
    $guestEmail = sanitize($_POST['email']);
    $guestPhone = sanitize($_POST['phone']);
    $specialRequests = sanitize($_POST['special_requests'] ?? '');
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'momo');
    $agreeTerms = isset($_POST['terms']);
    
    // Validation
    if (empty($guestFirstName) || empty($guestLastName) || empty($guestEmail) || empty($guestPhone)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!$agreeTerms) {
        $error = 'You must agree to the terms and conditions';
    } else {
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Generate unique booking reference
            $bookingRef = 'EXP-' . strtoupper(substr(uniqid(), -6)) . '-' . date('Ymd');
            
            // Insert booking
            $stmt = $db->prepare("
                INSERT INTO bookings (
                    booking_reference, user_id, booking_type, attraction_tier_id,
                    experience_date, start_time, num_participants,
                    guest_first_name, guest_last_name, guest_email, guest_phone,
                    special_requests, unit_price, total_amount, commission_amount, tax_amount,
                    status, payment_status, created_at
                ) VALUES (?, ?, 'attraction', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
            ");
            
            $stmt->execute([
                $bookingRef,
                $userId,
                $tierId,
                $date,
                $startTime ?: null,
                $participants,
                $guestFirstName,
                $guestLastName,
                $guestEmail,
                $guestPhone,
                $specialRequests,
                $unitPrice,
                $grandTotal,
                $commissionAmount,
                $taxAmount
            ]);
            
            $bookingId = $db->lastInsertId();
            
            // Update availability
            if ($availability) {
                // Update existing availability record
                $newBookingsMade = $bookedCount + $participants;
                $stmt = $db->prepare("
                    UPDATE attraction_availability 
                    SET bookings_made = ? 
                    WHERE availability_id = ?
                ");
                $stmt->execute([$newBookingsMade, $availability['availability_id']]);
            } else {
                // Create new availability record
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made)
                    VALUES (?, ?, 10, ?)
                ");
                $stmt->execute([$tierId, $date, $participants]);
            }
            
            // Commit transaction
            $db->commit();
            
            // Redirect to payment page with booking reference
            header('Location: payment.php?booking=' . $bookingRef);
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Booking failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Complete Booking - ' . $attraction['attraction_name'];
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
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

/* Debug Panel Styles */
.debug-panel {
    background: #1a1a1a;
    color: #00ff00;
    padding: 20px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    font-family: 'Courier New', monospace;
    font-size: 0.8125rem;
    max-height: 400px;
    overflow-y: auto;
}

.debug-panel h3 {
    color: #ffb700;
    margin-bottom: 16px;
    font-size: 1rem;
}

.debug-item {
    margin-bottom: 12px;
    padding: 8px;
    background: rgba(255,255,255,0.05);
    border-radius: var(--radius-sm);
}

.debug-item.valid {
    border-left: 3px solid var(--success);
}

.debug-item.invalid {
    border-left: 3px solid var(--danger);
}

.debug-label {
    font-weight: bold;
    color: #fff;
}

.debug-raw {
    color: #888;
}

.debug-status {
    float: right;
    font-weight: bold;
}

.debug-status.valid {
    color: var(--success);
}

.debug-status.invalid {
    color: var(--danger);
}

.debug-missing {
    background: var(--danger);
    color: white;
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-top: 16px;
    font-weight: bold;
}

.booking-page {
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

/* Main Grid */
.booking-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
}

/* Main Content */
.booking-main {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 32px;
    animation: fadeInUp 0.5s ease;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title i {
    color: var(--primary);
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label .required {
    color: var(--danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: var(--transition);
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Payment Methods */
.payment-methods {
    margin: 24px 0;
}

.payment-method {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: 12px;
    cursor: pointer;
    transition: var(--transition);
}

.payment-method:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.payment-method.selected {
    border-color: var(--primary);
    background: var(--primary-light);
}

.payment-radio {
    width: 20px;
    height: 20px;
    accent-color: var(--primary);
}

.payment-icon {
    width: 40px;
    height: 40px;
    background: var(--bg-secondary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--primary);
}

.payment-info {
    flex: 1;
}

.payment-name {
    font-weight: 700;
    margin-bottom: 2px;
}

.payment-desc {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Terms */
.terms-group {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 24px 0;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.terms-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    accent-color: var(--primary);
    margin-top: 2px;
}

.terms-group label {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.5;
}

.terms-group a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
}

.terms-group a:hover {
    text-decoration: underline;
}

/* Buttons */
.btn-confirm {
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

.btn-confirm:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-confirm:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
    transition: var(--transition);
}

.btn-back:hover {
    color: var(--primary);
}

/* Sidebar */
.booking-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.summary-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-lg);
}

.summary-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.summary-item {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.summary-icon {
    width: 48px;
    height: 48px;
    background: var(--primary-light);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--primary);
    flex-shrink: 0;
}

.summary-details h4 {
    font-weight: 700;
    margin-bottom: 4px;
}

.summary-details p {
    font-size: 0.8125rem;
    color: var(--text-secondary);
    line-height: 1.5;
}

.summary-details p i {
    color: var(--primary);
    margin-right: 4px;
}

.summary-divider {
    height: 1px;
    background: var(--border);
    margin: 20px 0;
}

.price-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 0.9375rem;
}

.price-row.total {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid var(--border);
    font-size: 1.125rem;
    font-weight: 700;
}

.price-label {
    color: var(--text-secondary);
}

.price-value {
    font-weight: 600;
}

.price-value.total {
    color: var(--primary);
    font-size: 1.25rem;
}

/* Security Note */
.security-note {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #e6f4ea;
    border-radius: var(--radius-md);
    margin-top: 20px;
}

.security-note i {
    font-size: 1.5rem;
    color: var(--success);
}

.security-note-content {
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.security-note-content strong {
    color: var(--success);
    display: block;
    margin-bottom: 4px;
}

/* Availability Warning */
.availability-warning {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px;
    background: #fff4e6;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    font-size: 0.8125rem;
    color: var(--warning);
}

.availability-warning i {
    font-size: 1.125rem;
}

/* Alert Messages */
.alert {
    padding: 16px;
    border-radius: var(--radius-md);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-danger {
    background: #fce8e8;
    color: var(--danger);
    border: 1px solid #fecaca;
}

.alert-success {
    background: #e6f4ea;
    color: var(--success);
    border: 1px solid #a7f3d0;
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

/* Responsive */
@media (max-width: 992px) {
    .booking-grid {
        grid-template-columns: 1fr;
    }
    
    .booking-sidebar {
        position: static;
    }
}

@media (max-width: 768px) {
    .booking-main {
        padding: 20px;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full-width {
        grid-column: span 1;
    }
    
    .summary-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .debug-panel {
        font-size: 0.75rem;
        padding: 12px;
    }
}
</style>

<div class="booking-page">
    <div class="container">
        <!-- Debug Panel (only shown when there are issues) -->
        <?php if (!empty($missing) || isset($_GET['debug'])): ?>
        <div class="debug-panel">
            <h3><i class="bi bi-bug-fill"></i> DEBUG INFORMATION</h3>
            
            <?php foreach ($debug as $key => $info): ?>
            <div class="debug-item <?php echo $info['valid'] ? 'valid' : 'invalid'; ?>">
                <span class="debug-label"><?php echo $key; ?>:</span>
                <span class="debug-status <?php echo $info['valid'] ? 'valid' : 'invalid'; ?>">
                    <?php echo $info['valid'] ? '✓ VALID' : '✗ INVALID'; ?>
                </span>
                <br>
                <span class="debug-raw">Raw: <?php echo htmlspecialchars($info['raw']); ?></span>
                <br>
                <span class="debug-raw">Processed: <?php echo htmlspecialchars($info['processed']); ?></span>
            </div>
            <?php endforeach; ?>
            
            <?php if (!empty($missing)): ?>
            <div class="debug-missing">
                <i class="bi bi-exclamation-triangle-fill"></i>
                MISSING: <?php echo implode(', ', $missing); ?>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 16px; color: #888;">
                <strong>Full URL:</strong> <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?><br>
                <strong>Request Method:</strong> <?php echo $_SERVER['REQUEST_METHOD']; ?><br>
                <strong>All GET params:</strong> <?php echo htmlspecialchars(print_r($_GET, true)); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <nav class="breadcrumb-bar">
            <a href="/gorwanda-plus/" class="breadcrumb-link">Home</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <a href="/gorwanda-plus/?type=attractions" class="breadcrumb-link">Experiences</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <a href="detail.php?id=<?php echo $attractionId; ?>" class="breadcrumb-link">
                <?php echo sanitize($attraction['attraction_name']); ?>
            </a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <span class="text-secondary">Complete Booking</span>
        </nav>

        <!-- Error Message -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="booking-grid">
            <!-- Left Column - Booking Form -->
            <div class="booking-main">
                <h2 class="section-title">
                    <i class="bi bi-person-badge"></i>
                    Your Information
                </h2>

                <form method="POST" action="booking.php" id="bookingForm">
                    <input type="hidden" name="attraction_id" value="<?php echo $attractionId; ?>">
                    <input type="hidden" name="tier_id" value="<?php echo $tierId; ?>">
                    <input type="hidden" name="date" value="<?php echo $date; ?>">
                    <input type="hidden" name="participants" value="<?php echo $participants; ?>">
                    <input type="hidden" name="start_time" value="<?php echo $startTime; ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name <span class="required">*</span></label>
                            <input type="text" name="first_name" class="form-control" 
                                   value="<?php echo sanitize($user['first_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Last Name <span class="required">*</span></label>
                            <input type="text" name="last_name" class="form-control" 
                                   value="<?php echo sanitize($user['last_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo sanitize($user['email'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number <span class="required">*</span></label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo sanitize($user['phone'] ?? ''); ?>" 
                                   placeholder="+250 788 123 456" required>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Special Requests (Optional)</label>
                            <textarea name="special_requests" class="form-control" 
                                      placeholder="Dietary requirements, mobility issues, special occasions..."></textarea>
                        </div>
                    </div>

                    <h2 class="section-title" style="margin-top: 32px;">
                        <i class="bi bi-credit-card"></i>
                        Payment Method
                    </h2>

                    <div class="payment-methods">
                        <label class="payment-method selected">
                            <input type="radio" name="payment_method" value="momo" class="payment-radio" checked>
                            <div class="payment-icon">
                                <i class="bi bi-phone-fill"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">MTN Mobile Money</div>
                                <div class="payment-desc">Pay with MoMo - Instant confirmation</div>
                            </div>
                            <i class="bi bi-check-circle-fill text-primary"></i>
                        </label>

                        <label class="payment-method">
                            <input type="radio" name="payment_method" value="card" class="payment-radio">
                            <div class="payment-icon">
                                <i class="bi bi-credit-card"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">Credit / Debit Card</div>
                                <div class="payment-desc">Visa, Mastercard, Amex</div>
                            </div>
                        </label>

                        <label class="payment-method">
                            <input type="radio" name="payment_method" value="bank" class="payment-radio">
                            <div class="payment-icon">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="payment-info">
                                <div class="payment-name">Bank Transfer</div>
                                <div class="payment-desc">Pay via bank transfer (24h confirmation)</div>
                            </div>
                        </label>
                    </div>

                    <div class="terms-group">
                        <input type="checkbox" name="terms" id="terms" required>
                        <label for="terms">
                            I agree to the <a href="#" target="_blank">Terms of Service</a> and 
                            <a href="#" target="_blank">Privacy Policy</a>. I understand that this booking 
                            is subject to the experience provider's cancellation policy.
                        </label>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <a href="detail.php?id=<?php echo $attractionId; ?>" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back to experience
                        </a>
                        <button type="submit" name="confirm_booking" class="btn-confirm" id="confirmBtn">
                            Confirm & Pay
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column - Booking Summary -->
            <div class="booking-sidebar">
                <div class="summary-card">
                    <h3 class="summary-title">Booking Summary</h3>
                    
                    <div class="summary-item">
                        <div class="summary-icon">
                            <i class="bi bi-ticket-perforated"></i>
                        </div>
                        <div class="summary-details">
                            <h4><?php echo sanitize($attraction['attraction_name']); ?></h4>
                            <p>
                                <i class="bi bi-tag"></i> <?php echo sanitize($tier['tier_name'] ?? 'Standard'); ?><br>
                                <i class="bi bi-clock"></i> 
                                <?php 
                                if ($attraction['duration_minutes']) {
                                    $hours = floor($attraction['duration_minutes'] / 60);
                                    $minutes = $attraction['duration_minutes'] % 60;
                                    echo $hours . 'h ' . ($minutes ? $minutes . 'min' : '');
                                } else {
                                    echo 'Flexible duration';
                                }
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="summary-details">
                            <h4><?php echo date('l, F j, Y', strtotime($date)); ?></h4>
                            <p>
                                <i class="bi bi-clock"></i> <?php echo $startTime ? date('h:i A', strtotime($startTime)) : 'Flexible start time'; ?>
                            </p>
                        </div>
                    </div>

                    <div class="summary-item">
                        <div class="summary-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="summary-details">
                            <h4><?php echo $participants; ?> <?php echo $participants > 1 ? 'Participants' : 'Participant'; ?></h4>
                            <p>
                                <i class="bi bi-person"></i> Max group size: <?php echo $attraction['max_group_size'] ?? '10'; ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($availableSpots < 5): ?>
                    <div class="availability-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>Only <?php echo $availableSpots; ?> spots left at this price!</span>
                    </div>
                    <?php endif; ?>

                    <div class="summary-divider"></div>

                    <div class="price-row">
                        <span class="price-label">Price per person</span>
                        <span class="price-value"><?php echo formatPrice($unitPrice); ?></span>
                    </div>
                    <div class="price-row">
                        <span class="price-label">Participants</span>
                        <span class="price-value">x <?php echo $participants; ?></span>
                    </div>
                    <div class="price-row">
                        <span class="price-label">Subtotal</span>
                        <span class="price-value"><?php echo formatPrice($totalAmount); ?></span>
                    </div>
                    <div class="price-row">
                        <span class="price-label">Service fee (10%)</span>
                        <span class="price-value"><?php echo formatPrice($commissionAmount); ?></span>
                    </div>
                    <div class="price-row">
                        <span class="price-label">VAT (18%)</span>
                        <span class="price-value"><?php echo formatPrice($taxAmount); ?></span>
                    </div>
                    <div class="price-row total">
                        <span class="price-label">Total</span>
                        <span class="price-value total"><?php echo formatPrice($grandTotal); ?></span>
                    </div>

                    <div class="security-note">
                        <i class="bi bi-shield-check"></i>
                        <div class="security-note-content">
                            <strong>Secure Booking</strong>
                            Your payment information is encrypted and secure. We never store your full payment details.
                        </div>
                    </div>

                    <div style="margin-top: 16px; text-align: center; font-size: 0.75rem; color: var(--text-muted);">
                        <i class="bi bi-check-circle-fill text-success"></i> Free cancellation up to 24h before
                    </div>
                </div>

                <!-- Need Help -->
                <div class="summary-card" style="background: var(--bg-secondary);">
                    <h4 style="font-size: 0.9375rem; font-weight: 700; margin-bottom: 12px;">
                        <i class="bi bi-question-circle-fill me-2" style="color: var(--primary);"></i>
                        Need Help?
                    </h4>
                    <p style="font-size: 0.8125rem; color: var(--text-secondary); margin-bottom: 12px;">
                        Have questions about this experience or need assistance with your booking?
                    </p>
                    <button class="btn-back" style="width: 100%; justify-content: center; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-md);" onclick="openContactModal()">
                        <i class="bi bi-chat-dots"></i> Contact support
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Contact Support Modal -->
<div class="modal" id="contactModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Contact Support</h3>
            <button class="modal-close" onclick="closeContactModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 20px;">
                <i class="bi bi-headset" style="font-size: 3rem; color: var(--primary); margin-bottom: 16px;"></i>
                <p style="margin-bottom: 20px; font-size: 0.9375rem;">
                    Our support team is available 24/7 to help you with any questions.
                </p>
                <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 16px; text-align: left;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <i class="bi bi-telephone-fill" style="color: var(--primary);"></i>
                        <span>+250 788 123 456</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <i class="bi bi-envelope-fill" style="color: var(--primary);"></i>
                        <span>support@gorwanda.rw</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="bi bi-chat-fill" style="color: var(--primary);"></i>
                        <span>Live chat available</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-primary" onclick="closeContactModal()">Got it</button>
        </div>
    </div>
</div>

<style>
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: var(--bg-secondary);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: var(--danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--bg-secondary);
}
</style>

<script>
// Payment method selection
document.querySelectorAll('.payment-method').forEach(method => {
    method.addEventListener('click', function() {
        document.querySelectorAll('.payment-method').forEach(m => {
            m.classList.remove('selected');
        });
        this.classList.add('selected');
        this.querySelector('input[type="radio"]').checked = true;
    });
});

// Form validation and loading state
document.getElementById('bookingForm').addEventListener('submit', function(e) {
    const confirmBtn = document.getElementById('confirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Processing...';
    
    // Form will submit normally
    return true;
});

// Contact modal functions
function openContactModal() {
    document.getElementById('contactModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeContactModal() {
    document.getElementById('contactModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// Phone number formatting
const phoneInput = document.querySelector('input[name="phone"]');
if (phoneInput) {
    phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 0) {
            if (value.startsWith('250')) {
                value = '+' + value;
            } else if (value.startsWith('0')) {
                value = '+250' + value.substring(1);
            } else if (!value.startsWith('+') && value.length === 9) {
                value = '+250' + value;
            }
        }
        e.target.value = value;
    });
}

// Prevent double submission
let submitted = false;
document.getElementById('bookingForm').addEventListener('submit', function() {
    if (submitted) {
        return false;
    }
    submitted = true;
    return true;
});
</script>

<?php require_once '../includes/footer.php'; ?>