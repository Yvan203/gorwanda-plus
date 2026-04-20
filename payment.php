<?php
ob_start();
require_once 'includes/functions.php';

$bookingRef = sanitize($_GET['booking'] ?? '');

if (!$bookingRef) {
    header('Location: /gorwanda-plus/');
    exit;
}

$db = getDB();

// Get booking details
$stmt = $db->prepare("
    SELECT b.*, s.stay_name, s.address, sr.room_name 
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.booking_reference = ? AND b.user_id = ?
");
$stmt->execute([$bookingRef, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found');
    header('Location: /gorwanda-plus/');
    exit;
}

// Handle payment simulation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Simulate MoMo payment processing
    sleep(1); // Simulate API delay
    
    // Update booking as paid
    $stmt = $db->prepare("UPDATE bookings SET payment_status = 'paid', status = 'confirmed', payment_reference = ?, payment_method = 'momo' WHERE booking_id = ?");
    $stmt->execute(['MOMO-' . uniqid(), $booking['booking_id']]);
    
    setFlash('success', 'Payment successful! Your booking is confirmed.');
    header('Location: /gorwanda-plus/booking-confirmation.php?ref=' . $bookingRef);
    exit;
}

$pageTitle = 'Payment';
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
.payment-page {
    background: var(--bg-gray);
    min-height: calc(100vh - 64px);
    padding: 40px 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.payment-card {
    background: white;
    border-radius: 24px;
    padding: 48px;
    width: 100%;
    max-width: 480px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.1);
}

.payment-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #0066ff, #003b95);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    color: white;
    font-size: 2rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.payment-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.payment-subtitle {
    color: var(--text-secondary);
    margin-bottom: 32px;
}

.amount-display {
    background: var(--bg-gray);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 32px;
}

.amount-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.amount-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--text-primary);
}

.momo-form {
    text-align: left;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-input {
    width: 100%;
    padding: 16px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    font-size: 1rem;
    text-align: center;
    letter-spacing: 2px;
    transition: all 0.3s;
}

.form-input:focus {
    outline: none;
    border-color: #ffcc00;
    box-shadow: 0 0 0 4px rgba(255, 204, 0, 0.1);
}

.btn-pay {
    width: 100%;
    padding: 18px;
    background: linear-gradient(135deg, #ffcc00, #ffb700);
    color: #1a1a1a;
    border: none;
    border-radius: 12px;
    font-size: 1.125rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.btn-pay:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255, 204, 0, 0.4);
}

.btn-pay:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.spinner {
    width: 20px;
    height: 20px;
    border: 3px solid rgba(0,0,0,0.3);
    border-top-color: #1a1a1a;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    display: none;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.security-badges {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--border-color);
}

.badge-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.booking-summary {
    background: white;
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 24px;
    text-align: left;
    border: 1px solid var(--border-color);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.875rem;
}

.summary-row:last-child {
    margin-bottom: 0;
    padding-top: 8px;
    border-top: 1px solid var(--border-color);
    font-weight: 600;
}
</style>

<div class="payment-page">
    <div class="payment-card">
        <div class="payment-icon">
            <i class="bi bi-phone-fill"></i>
        </div>
        
        <h1 class="payment-title">MTN Mobile Money</h1>
        <p class="payment-subtitle">Complete your booking payment</p>
        
        <div class="booking-summary">
            <div class="summary-row">
                <span>Booking Reference</span>
                <span style="font-family: monospace; font-weight: 600;"><?php echo $bookingRef; ?></span>
            </div>
<div class="summary-row">
    <span>Property</span>
    <span><?php echo sanitize($booking['stay_name'] ?? $booking['booking_reference']); ?></span>
</div>
            <div class="summary-row">
                <span>Total Amount</span>
                <span style="color: var(--primary-blue); font-weight: 700;"><?php echo formatPrice($booking['total_amount']); ?></span>
            </div>
        </div>
        
        <form method="POST" action="" class="momo-form" id="paymentForm">
            <div class="form-group">
                <label class="form-label">MTN MoMo Number</label>
                <input type="tel" name="phone" class="form-input" placeholder="078 XXXX XXX" 
                       value="<?php echo sanitize($currentUser['phone'] ?? ''); ?>" required
                       pattern="078[0-9]{7}" maxlength="10">
                <small style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 6px; display: block;">
                    Enter your MTN number starting with 078
                </small>
            </div>
            
            <button type="submit" class="btn-pay" id="payBtn">
                <span class="spinner" id="spinner"></span>
                <span id="btnText">Pay Now • <?php echo formatPrice($booking['total_amount']); ?></span>
            </button>
        </form>
        
        <div class="security-badges">
            <div class="badge-item">
                <i class="bi bi-shield-check text-success"></i>
                <span>SSL Secured</span>
            </div>
            <div class="badge-item">
                <i class="bi bi-lock-fill text-success"></i>
                <span>Encrypted</span>
            </div>
            <div class="badge-item">
                <i class="bi bi-check-circle-fill text-success"></i>
                <span>Verified</span>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('payBtn');
    const spinner = document.getElementById('spinner');
    const btnText = document.getElementById('btnText');
    
    btn.disabled = true;
    spinner.style.display = 'block';
    btnText.textContent = 'Processing...';
});
</script>

<?php require_once 'includes/footer.php'; ?>