<?php
require_once 'includes/functions.php';
require_once 'config/stripe.php';

// Check if user is logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

// Get booking ID from URL
$bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$bookingId) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get booking details
$stmt = $db->prepare("
    SELECT b.*, s.stay_name, sr.room_name, u.first_name, u.last_name, u.email
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE b.booking_id = ? AND b.user_id = ? AND b.payment_status = 'pending'
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Payment - ' . $booking['booking_reference'];
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
    .payment-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 40px 20px;
    }

    .payment-layout {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 32px;
    }

    .payment-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .payment-header {
        border-bottom: 1px solid #e7e7e7;
        padding-bottom: 16px;
        margin-bottom: 24px;
    }

    .payment-header h2 {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .form-control {
        width: 100%;
        padding: 12px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 14px;
    }

    .card-element {
        padding: 12px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        background: white;
    }

    .pay-btn {
        width: 100%;
        padding: 14px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 20px;
    }

    .pay-btn:hover {
        background: #003580;
    }

    .summary-card {
        background: #f5f5f5;
        border-radius: 12px;
        padding: 24px;
        position: sticky;
        top: 20px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        font-size: 14px;
    }

    .summary-row.total {
        border-top: 1px solid #e7e7e7;
        margin-top: 8px;
        padding-top: 16px;
        font-weight: 700;
        font-size: 18px;
    }

    .secure-badge {
        text-align: center;
        margin-top: 20px;
        font-size: 12px;
        color: #6b6b6b;
    }

    @media (max-width: 768px) {
        .payment-layout {
            grid-template-columns: 1fr;
        }
    }
</style>

<script src="https://js.stripe.com/v3/"></script>

<div class="payment-container">
    <div class="payment-layout">
        <!-- Left Column - Payment Form -->
        <div>
            <div class="payment-card">
                <div class="payment-header">
                    <h2>Payment Information</h2>
                </div>

                <form id="payment-form">
                    <div class="form-group">
                        <label>Cardholder Name</label>
                        <input type="text" id="cardholder_name" class="form-control"
                            value="<?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Card Details</label>
                        <div id="card-element" class="card-element"></div>
                        <div id="card-errors" role="alert" style="color: #c41c1c; font-size: 12px; margin-top: 8px;"></div>
                    </div>

                    <button type="submit" class="pay-btn" id="submit-btn">
                        Pay <?php echo formatPrice($booking['total_amount']); ?>
                    </button>
                </form>

                <div class="secure-badge">
                    <i class="bi bi-shield-lock"></i> Secure payment powered by Stripe
                </div>
            </div>
        </div>

        <!-- Right Column - Order Summary -->
        <div>
            <div class="summary-card">
                <h3 style="margin-bottom: 16px;">Order Summary</h3>

                <div class="summary-row">
                    <span>Booking Reference</span>
                    <span><strong><?php echo $booking['booking_reference']; ?></strong></span>
                </div>
                <div class="summary-row">
                    <span>Property</span>
                    <span><?php echo sanitize($booking['stay_name']); ?></span>
                </div>
                <div class="summary-row">
                    <span>Room</span>
                    <span><?php echo sanitize($booking['room_name']); ?></span>
                </div>
                <div class="summary-row">
                    <span>Dates</span>
                    <span><?php echo date('M d', strtotime($booking['check_in_date'])); ?> - <?php echo date('M d', strtotime($booking['check_out_date'])); ?></span>
                </div>
                <div class="summary-row">
                    <span>Guests</span>
                    <span><?php echo $booking['num_guests']; ?> guests</span>
                </div>
                <div class="summary-row total">
                    <span>Total Amount</span>
                    <span><?php echo formatPrice($booking['total_amount']); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize Stripe
    const stripe = Stripe('<?php echo $_SESSION['stripe_publishable_key']; ?>');
    const elements = stripe.elements();

    // Create card element
    const cardElement = elements.create('card', {
        style: {
            base: {
                fontSize: '16px',
                color: '#32325d',
                fontFamily: 'Arial, sans-serif',
                '::placeholder': {
                    color: '#aab7c4'
                }
            },
            invalid: {
                color: '#c41c1c'
            }
        }
    });
    cardElement.mount('#card-element');

    // Handle form submission
    const form = document.getElementById('payment-form');
    const submitBtn = document.getElementById('submit-btn');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = 'Processing...';

        const cardholderName = document.getElementById('cardholder_name').value;
        const bookingId = '<?php echo $bookingId; ?>';

        // Create payment method
        const {
            paymentMethod,
            error
        } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
            billing_details: {
                name: cardholderName,
                email: '<?php echo $booking['email']; ?>'
            }
        });

        if (error) {
            const errorElement = document.getElementById('card-errors');
            errorElement.textContent = error.message;
            submitBtn.disabled = false;
            submitBtn.textContent = 'Pay <?php echo formatPrice($booking['total_amount']); ?>';
        } else {
            // Send payment method to server
            fetch('process-payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        payment_method_id: paymentMethod.id,
                        booking_id: bookingId,
                        amount: <?php echo $booking['total_amount']; ?>
                    }),
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = 'payment-success.php?booking_id=' + bookingId;
                    } else {
                        const errorElement = document.getElementById('card-errors');
                        errorElement.textContent = data.error || 'Payment failed. Please try again.';
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Pay <?php echo formatPrice($booking['total_amount']); ?>';
                    }
                })
                .catch(error => {
                    const errorElement = document.getElementById('card-errors');
                    errorElement.textContent = 'An error occurred. Please try again.';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Pay <?php echo formatPrice($booking['total_amount']); ?>';
                });
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>