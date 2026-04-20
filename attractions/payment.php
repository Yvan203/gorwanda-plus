<?php
require_once '../includes/functions.php';

// Require login
requireLogin();

$bookingRef = $_GET['booking'] ?? '';

if (!$bookingRef) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get booking details
$stmt = $db->prepare("
    SELECT b.*, a.attraction_name, a.main_image, a.duration_minutes,
           at.tier_name, at.price_type,
           l.name as location_name,
           u.first_name as owner_name, u.phone as owner_phone
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE b.booking_reference = ? AND b.user_id = ?
");
$stmt->execute([$bookingRef, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found');
    header('Location: /gorwanda-plus/bookings.php');
    exit;
}

// If already paid, redirect to confirmation
if ($booking['payment_status'] === 'paid') {
    header('Location: confirmation.php?booking=' . $bookingRef);
    exit;
}

// Handle payment processing
$error = '';
$success = '';
$paymentStep = $_GET['step'] ?? 'method';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['process_payment'])) {
        $paymentMethod = $_POST['payment_method'] ?? $booking['payment_method'];
        
        // Simulate payment processing
        // In production, you would integrate with actual payment gateway here
        
        // For demo, we'll simulate successful payment
        $paymentSuccessful = true;
        $paymentReference = 'PAY-' . strtoupper(uniqid()) . '-' . date('Ymd');
        
        if ($paymentSuccessful) {
            // Update booking status
            $stmt = $db->prepare("
                UPDATE bookings 
                SET payment_status = 'paid', 
                    status = 'confirmed',
                    payment_method = ?,
                    payment_reference = ?,
                    updated_at = NOW()
                WHERE booking_id = ?
            ");
            $stmt->execute([$paymentMethod, $paymentReference, $booking['booking_id']]);
            
            // Send confirmation email (simulated)
            // sendBookingConfirmationEmail($booking, $user);
            
            // Redirect to confirmation page
            header('Location: confirmation.php?booking=' . $bookingRef);
            exit;
        } else {
            $error = 'Payment failed. Please try again or use a different payment method.';
        }
    }
}

$pageTitle = 'Payment - ' . $booking['attraction_name'];
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

.payment-page {
    background: var(--bg-secondary);
    min-height: calc(100vh - 64px);
    padding: 40px 0;
    display: flex;
    align-items: center;
}

.payment-container {
    max-width: 500px;
    margin: 0 auto;
    width: 100%;
}

.payment-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 32px;
    box-shadow: var(--shadow-lg);
    animation: fadeInUp 0.5s ease;
}

.payment-header {
    text-align: center;
    margin-bottom: 32px;
}

.payment-icon {
    width: 80px;
    height: 80px;
    background: var(--primary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 2rem;
    color: var(--primary);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}

.payment-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.payment-subtitle {
    color: var(--text-secondary);
    font-size: 0.9375rem;
}

.booking-summary {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 24px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    font-size: 0.9375rem;
}

.summary-row:last-child {
    margin-bottom: 0;
    padding-top: 12px;
    border-top: 1px solid var(--border);
    font-weight: 700;
}

.summary-label {
    color: var(--text-secondary);
}

.summary-value {
    font-weight: 600;
    color: var(--text);
}

.summary-value.total {
    color: var(--primary);
    font-size: 1.125rem;
}

/* Payment Methods */
.payment-methods {
    margin-bottom: 24px;
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

.payment-method-radio {
    width: 20px;
    height: 20px;
    accent-color: var(--primary);
}

.payment-method-icon {
    width: 40px;
    height: 40px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--primary);
}

.payment-method-info {
    flex: 1;
}

.payment-method-name {
    font-weight: 700;
    margin-bottom: 2px;
}

.payment-method-desc {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* MoMo Form */
.momo-form {
    margin-top: 24px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 1rem;
    transition: var(--transition);
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.form-text {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-top: 6px;
}

/* Pin Input */
.pin-input-group {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.pin-input {
    width: 50px;
    height: 60px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    text-align: center;
    font-size: 1.5rem;
    font-weight: 700;
    transition: var(--transition);
}

.pin-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

/* Buttons */
.btn-pay {
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
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-pay:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-pay:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-pay.loading {
    position: relative;
    color: transparent;
}

.btn-pay.loading::after {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border: 2px solid white;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
    transition: var(--transition);
    margin-top: 16px;
}

.btn-back:hover {
    color: var(--primary);
}

/* Security Badges */
.security-badges {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
}

.security-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.security-badge i {
    color: var(--success);
    font-size: 1rem;
}

/* Alert */
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

/* Loading States */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255,255,255,0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    flex-direction: column;
    gap: 20px;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 3px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.loading-text {
    font-size: 1rem;
    color: var(--text);
    font-weight: 600;
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
@media (max-width: 480px) {
    .payment-card {
        padding: 24px;
    }
    
    .pin-input {
        width: 40px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .security-badges {
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
}
</style>

<div class="payment-page">
    <div class="payment-container">
        <div class="payment-card">
            <div class="payment-header">
                <div class="payment-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <h1 class="payment-title">Complete Payment</h1>
                <p class="payment-subtitle">Secure payment powered by MTN MoMo</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo $error; ?>
            </div>
            <?php endif; ?>

            <!-- Booking Summary -->
            <div class="booking-summary">
                <div class="summary-row">
                    <span class="summary-label">Booking Reference</span>
                    <span class="summary-value" style="font-family: monospace;"><?php echo $bookingRef; ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Experience</span>
                    <span class="summary-value"><?php echo sanitize($booking['attraction_name']); ?></span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Date & Time</span>
                    <span class="summary-value">
                        <?php echo date('M d, Y', strtotime($booking['experience_date'])); ?>
                        <?php if ($booking['start_time']): ?>
                        at <?php echo date('h:i A', strtotime($booking['start_time'])); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Participants</span>
                    <span class="summary-value"><?php echo $booking['num_participants']; ?> person(s)</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value total"><?php echo formatPrice($booking['total_amount']); ?></span>
                </div>
            </div>

            <!-- Payment Method Selection -->
            <?php if ($paymentStep === 'method'): ?>
            <form method="POST" id="paymentMethodForm">
                <div class="payment-methods">
                    <label class="payment-method selected">
                        <input type="radio" name="payment_method" value="momo" class="payment-method-radio" checked>
                        <div class="payment-method-icon">
                            <i class="bi bi-phone-fill"></i>
                        </div>
                        <div class="payment-method-info">
                            <div class="payment-method-name">MTN Mobile Money</div>
                            <div class="payment-method-desc">Instant payment from your MoMo account</div>
                        </div>
                        <i class="bi bi-check-circle-fill text-primary"></i>
                    </label>

                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="card" class="payment-method-radio">
                        <div class="payment-method-icon">
                            <i class="bi bi-credit-card"></i>
                        </div>
                        <div class="payment-method-info">
                            <div class="payment-method-name">Credit / Debit Card</div>
                            <div class="payment-method-desc">Visa, Mastercard, American Express</div>
                        </div>
                    </label>

                    <label class="payment-method">
                        <input type="radio" name="payment_method" value="bank" class="payment-method-radio">
                        <div class="payment-method-icon">
                            <i class="bi bi-bank"></i>
                        </div>
                        <div class="payment-method-info">
                            <div class="payment-method-name">Bank Transfer</div>
                            <div class="payment-method-desc">Manual confirmation (1-2 business days)</div>
                        </div>
                    </label>
                </div>

                <button type="submit" name="process_payment" class="btn-pay">
                    Continue to Payment
                </button>
            </form>

            <!-- MoMo Payment Form -->
            <?php elseif ($paymentStep === 'momo'): ?>
            <form method="POST" id="momoForm" class="momo-form">
                <input type="hidden" name="payment_method" value="momo">
                
                <div class="form-group">
                    <label class="form-label">MTN MoMo Number</label>
                    <input type="tel" name="momo_number" class="form-control" 
                           value="<?php echo sanitize($user['phone'] ?? ''); ?>" 
                           placeholder="078X XXX XXX" required>
                    <div class="form-text">Enter the MTN number you want to pay with</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Enter PIN</label>
                    <div class="pin-input-group">
                        <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus>
                        <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                        <input type="text" class="pin-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                    </div>
                </div>

                <button type="submit" name="process_payment" class="btn-pay" id="payBtn">
                    Pay <?php echo formatPrice($booking['total_amount']); ?>
                </button>
            </form>

            <!-- Card Payment Form -->
            <?php elseif ($paymentStep === 'card'): ?>
            <form method="POST" id="cardForm" class="momo-form">
                <input type="hidden" name="payment_method" value="card">
                
                <div class="form-group">
                    <label class="form-label">Card Number</label>
                    <input type="text" class="form-control" placeholder="1234 5678 9012 3456" required>
                </div>

                <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Expiry Date</label>
                        <input type="text" class="form-control" placeholder="MM/YY" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">CVV</label>
                        <input type="text" class="form-control" placeholder="123" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Cardholder Name</label>
                    <input type="text" class="form-control" placeholder="John Doe" required>
                </div>

                <button type="submit" name="process_payment" class="btn-pay">
                    Pay <?php echo formatPrice($booking['total_amount']); ?>
                </button>
            </form>
            <?php endif; ?>

            <!-- Back Link -->
            <a href="booking.php?attraction_id=<?php echo $booking['attraction_id']; ?>&tier_id=<?php echo $booking['attraction_tier_id']; ?>&date=<?php echo $booking['experience_date']; ?>&participants=<?php echo $booking['num_participants']; ?>" class="btn-back">
                <i class="bi bi-arrow-left"></i> Back to booking
            </a>

            <!-- Security Badges -->
            <div class="security-badges">
                <div class="security-badge">
                    <i class="bi bi-shield-check"></i>
                    <span>256-bit SSL</span>
                </div>
                <div class="security-badge">
                    <i class="bi bi-lock-fill"></i>
                    <span>Encrypted</span>
                </div>
                <div class="security-badge">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>PCI Compliant</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay" style="display: none;">
    <div class="loading-spinner"></div>
    <div class="loading-text">Processing your payment...</div>
</div>

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

// PIN input handling
document.querySelectorAll('.pin-input').forEach((input, index, inputs) => {
    input.addEventListener('keyup', (e) => {
        if (e.key >= '0' && e.key <= '9') {
            if (index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        } else if (e.key === 'Backspace') {
            if (index > 0) {
                inputs[index - 1].focus();
            }
        }
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft' && index > 0) {
            inputs[index - 1].focus();
        } else if (e.key === 'ArrowRight' && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });

    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        if (paste && /^\d+$/.test(paste)) {
            for (let i = 0; i < paste.length && i < inputs.length; i++) {
                inputs[i].value = paste[i];
            }
            inputs[Math.min(paste.length, inputs.length) - 1].focus();
        }
    });
});

// Form submission
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Show loading overlay
        document.getElementById('loadingOverlay').style.display = 'flex';
        
        // Simulate payment processing
        setTimeout(() => {
            // Create hidden input for process_payment
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'process_payment';
            input.value = '1';
            this.appendChild(input);
            
            // Submit the form
            this.submit();
        }, 2000);
    });
});

// Card number formatting
const cardInput = document.querySelector('input[placeholder="1234 5678 9012 3456"]');
if (cardInput) {
    cardInput.addEventListener('input', (e) => {
        let value = e.target.value.replace(/\s/g, '').replace(/\D/g, '');
        let formatted = '';
        for (let i = 0; i < value.length; i++) {
            if (i > 0 && i % 4 === 0) {
                formatted += ' ';
            }
            formatted += value[i];
        }
        e.target.value = formatted;
    });
}

// Expiry date formatting
const expiryInput = document.querySelector('input[placeholder="MM/YY"]');
if (expiryInput) {
    expiryInput.addEventListener('input', (e) => {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length >= 2) {
            value = value.substring(0, 2) + '/' + value.substring(2, 4);
        }
        e.target.value = value;
    });
}

// Prevent double submission
let submitted = false;
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        if (submitted) {
            return false;
        }
        submitted = true;
        return true;
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>