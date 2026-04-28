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

// Get booking details with dynamic content based on booking type
$stmt = $db->prepare("
    SELECT 
        b.*,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.room_name
            WHEN b.booking_type = 'car_rental' THEN cr.company_name
            ELSE t.tier_name
        END as item_detail,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.address
            WHEN b.booking_type = 'car_rental' THEN cr.address
            ELSE a.address
        END as item_location,
        u.first_name,
        u.last_name,
        u.email
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE b.booking_id = ? AND b.user_id = ? AND b.payment_status = 'pending'
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: index.php');
    exit;
}

// Get booking type icon and labels
$bookingTypeInfo = [
    'stay' => ['icon' => '🏨', 'label' => 'Stay / Accommodation', 'detail_label' => 'Room', 'date_label' => 'Stay Dates', 'guest_label' => 'Guests'],
    'car_rental' => ['icon' => '🚗', 'label' => 'Car Rental', 'detail_label' => 'Vehicle', 'date_label' => 'Rental Period', 'guest_label' => 'Driver'],
    'attraction' => ['icon' => '🎟️', 'label' => 'Experience', 'detail_label' => 'Tier', 'date_label' => 'Date', 'guest_label' => 'Participants']
];

$typeInfo = $bookingTypeInfo[$booking['booking_type']];

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

    .summary-divider {
        height: 1px;
        background: #e7e7e7;
        margin: 12px 0;
    }

    .booking-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        background: #f0f4ff;
        color: #0071c2;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 16px;
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

        <!-- Right Column - Order Summary (Dynamic based on booking type) -->
        <div>
            <div class="summary-card">
                <div class="booking-type-badge">
                    <span><?php echo $typeInfo['icon']; ?></span>
                    <span><?php echo $typeInfo['label']; ?></span>
                </div>

                <h3 style="margin-bottom: 16px; font-size: 16px;">Order Summary</h3>

                <!-- Booking Reference -->
                <div class="summary-row">
                    <span>Booking Reference</span>
                    <span><strong><?php echo $booking['booking_reference']; ?></strong></span>
                </div>

                <div class="summary-divider"></div>

                <!-- Dynamic Content Based on Booking Type -->
                <?php if ($booking['booking_type'] == 'stay'): ?>
                    <!-- Stay Booking Details -->
                    <div class="summary-row">
                        <span>Property</span>
                        <span><strong><?php echo sanitize($booking['item_name']); ?></strong></span>
                    </div>
                    <div class="summary-row">
                        <span>Room</span>
                        <span><?php echo sanitize($booking['item_detail']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Location</span>
                        <span><?php echo sanitize($booking['item_location']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Check-in</span>
                        <span><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Check-out</span>
                        <span><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Nights</span>
                        <span><?php echo $booking['num_nights']; ?> nights</span>
                    </div>
                    <div class="summary-row">
                        <span>Guests</span>
                        <span><?php echo $booking['num_guests']; ?> guest(s)</span>
                    </div>

                <?php elseif ($booking['booking_type'] == 'car_rental'): ?>
                    <!-- Car Rental Booking Details -->
                    <div class="summary-row">
                        <span>Vehicle</span>
                        <span><strong><?php echo sanitize($booking['item_name']); ?></strong></span>
                    </div>
                    <div class="summary-row">
                        <span>Rental Company</span>
                        <span><?php echo sanitize($booking['item_detail']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Pickup Location</span>
                        <span><?php echo sanitize($booking['pickup_location']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Pickup Date</span>
                        <span><?php echo date('M d, Y', strtotime($booking['pickup_date'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Return Date</span>
                        <span><?php echo date('M d, Y', strtotime($booking['return_date'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Duration</span>
                        <span><?php echo $booking['num_nights']; ?> day(s)</span>
                    </div>
                    <div class="summary-row">
                        <span>Driver</span>
                        <span><?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
                    </div>

                <?php elseif ($booking['booking_type'] == 'attraction'): ?>
                    <!-- Experience Booking Details -->
                    <div class="summary-row">
                        <span>Experience</span>
                        <span><strong><?php echo sanitize($booking['item_name']); ?></strong></span>
                    </div>
                    <div class="summary-row">
                        <span>Tier</span>
                        <span><?php echo sanitize($booking['item_detail']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Location</span>
                        <span><?php echo sanitize($booking['item_location']); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Date</span>
                        <span><?php echo date('M d, Y', strtotime($booking['experience_date'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Time</span>
                        <span><?php echo date('g:i A', strtotime($booking['start_time'])); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Participants</span>
                        <span><?php echo $booking['num_participants']; ?> person(s)</span>
                    </div>

                <?php endif; ?>

                <div class="summary-divider"></div>

                <!-- Price -->
                <div class="summary-row total">
                    <span>Total Amount</span>
                    <span><?php echo formatPrice($booking['total_amount']); ?></span>
                </div>

                <!-- Tax Note -->
                <div class="summary-row" style="font-size: 11px; color: #6b6b6b; padding-top: 8px;">
                    <span>Includes 18% VAT</span>
                    <span></span>
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
            fetch('/gorwanda-plus/process-payment.php', {
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
                        window.location.href = '/gorwanda-plus/payment-success.php?booking_id=' + bookingId;
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