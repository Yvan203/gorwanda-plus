<?php
require_once '../includes/functions.php';

$db = getDB();
$code = isset($_GET['code']) ? sanitize($_GET['code']) : '';

if (!$code) {
    header('Location: index.php');
    exit;
}

// Get reservation details
$stmt = $db->prepare("
    SELECT tr.*, u.first_name, u.last_name, u.email,
           res.restaurant_name, res.cuisine_type, s.stay_name as hotel_name
    FROM table_reservations tr
    JOIN restaurants res ON tr.restaurant_id = res.restaurant_id
    JOIN users u ON tr.user_id = u.user_id
    LEFT JOIN stays s ON res.stay_id = s.stay_id
    WHERE tr.confirmation_code = ?
");
$stmt->execute([$code]);
$reservation = $stmt->fetch();

if (!$reservation) {
    setFlash('error', 'Reservation not found');
    header('Location: index.php');
    exit;
}

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if ($action === 'cancel') {
        // Update reservation status to cancelled
        $stmt = $db->prepare("
            UPDATE table_reservations 
            SET status = 'cancelled', 
                updated_at = NOW() 
            WHERE reservation_id = ?
        ");
        $stmt->execute([$reservation['reservation_id']]);
        
        // Send cancellation email
        sendCancellationConfirmation(
            $reservation['email'],
            $reservation['first_name'] . ' ' . $reservation['last_name'],
            $reservation['restaurant_name'],
            $reservation['confirmation_code'],
            $reservation['reservation_date'],
            $reservation['reservation_time'],
            $reservation['guest_count']
        );
        
        setFlash('success', 'Your reservation has been cancelled successfully');
        header('Location: /gorwanda-plus/bookings.php');
        exit;
    }
}

// Calculate if cancellation is still possible (within 2 hours of reservation)
$reservation_datetime = strtotime($reservation['reservation_date'] . ' ' . $reservation['reservation_time']);
$now = time();
$hours_until_reservation = ($reservation_datetime - $now) / 3600;
$can_cancel = $hours_until_reservation > 2; // Free cancellation up to 2 hours before

$pageTitle = 'Cancel Reservation - GoRwanda+';
require_once '../includes/header.php';

// Email function (if not in functions.php, add it)
function sendCancellationConfirmation($email, $name, $restaurant_name, $code, $date, $time, $guests) {
    $subject = "Reservation Cancelled - " . $restaurant_name;
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: 'Inter', Arial, sans-serif; line-height: 1.6; color: #262626; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #c41c1c; color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
            .header h2 { margin: 0; font-size: 24px; }
            .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
            .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e7e7e7; }
            .detail-row:last-child { border-bottom: none; }
            .label { font-weight: 600; color: #6b6b6b; }
            .value { font-weight: 600; color: #262626; }
            .footer { text-align: center; padding: 20px; color: #6b6b6b; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>❌ Reservation Cancelled</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>" . $name . "</strong>,</p>
                <p>Your reservation has been cancelled as requested.</p>
                
                <div class='details'>
                    <h3 style='margin-top: 0; color: #c41c1c;'>Cancelled reservation details</h3>
                    
                    <div class='detail-row'>
                        <span class='label'>Confirmation code</span>
                        <span class='value'>" . $code . "</span>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Restaurant</span>
                        <span class='value'>" . $restaurant_name . "</span>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Date</span>
                        <span class='value'>" . date('l, F j, Y', strtotime($date)) . "</span>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Time</span>
                        <span class='value'>" . date('g:i A', strtotime($time)) . "</span>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Guests</span>
                        <span class='value'>" . $guests . " " . ($guests > 1 ? 'people' : 'person') . "</span>
                    </div>
                </div>
                
                <p>We hope to welcome you another time!</p>
                <p><a href='http://localhost/gorwanda-plus/restaurants/' style='color: #0071c2;'>Browse more restaurants</a></p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " GoRwanda+. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $logFile = __DIR__ . '/../../logs/emails.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logEntry = date('Y-m-d H:i:s') . " - Cancellation email sent to $email for $code\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    return true;
}
?>

<style>
/* Cancel Page Styles */
:root {
    --bkg-blue-dark: #003580;
    --bkg-blue-primary: #0071c2;
    --bkg-blue-light: #ebf3ff;
    --bkg-yellow: #feba02;
    --bkg-green: #008009;
    --bkg-red: #c41c1c;
    --bkg-gray-100: #f2f6fa;
    --bkg-gray-200: #e7e7e7;
    --bkg-gray-500: #6b6b6b;
    --bkg-gray-700: #262626;
    --shadow-sm: 0 1px 4px rgba(0,0,0,0.05);
    --shadow-md: 0 2px 8px rgba(0,0,0,0.1);
    --shadow-lg: 0 4px 16px rgba(0,0,0,0.15);
}

.cancel-container {
    max-width: 700px;
    margin: 40px auto;
    padding: 0 20px;
}

.cancel-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.cancel-header {
    background: linear-gradient(135deg, #c41c1c, #a01818);
    color: white;
    padding: 30px;
    text-align: center;
}

.cancel-icon {
    font-size: 48px;
    margin-bottom: 15px;
}

.cancel-header h1 {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 10px;
}

.cancel-body {
    padding: 30px;
}

.warning-box {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.warning-box i {
    font-size: 24px;
}

.warning-box p {
    margin: 0;
    font-size: 14px;
    line-height: 1.6;
}

.reservation-details {
    background: var(--bkg-gray-100);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--bkg-gray-200);
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--bkg-gray-200);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 600;
    color: var(--bkg-gray-500);
    font-size: 14px;
}

.detail-value {
    font-weight: 600;
    color: var(--bkg-gray-700);
    font-size: 14px;
}

.restaurant-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-blue-primary);
    margin-bottom: 5px;
}

.cuisine-badge {
    display: inline-block;
    background: var(--bkg-blue-light);
    color: var(--bkg-blue-primary);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 15px;
}

.cancel-policy {
    background: #e8f5e9;
    border: 1px solid #c8e6c9;
    color: #2e7d32;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.cancel-policy i {
    font-size: 20px;
}

.cancel-policy p {
    margin: 0;
    font-size: 14px;
}

.cancel-policy.warning {
    background: #ffebee;
    border-color: #ffcdd2;
    color: #c62828;
}

.action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
}

.btn-cancel {
    background: var(--bkg-red);
    color: white;
    border: none;
    padding: 14px 32px;
    font-weight: 600;
    font-size: 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: background-color 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-cancel:hover {
    background: #a01818;
    color: white;
}

.btn-cancel:disabled {
    background: var(--bkg-gray-500);
    cursor: not-allowed;
}

.btn-back {
    background: white;
    color: var(--bkg-blue-primary);
    border: 1px solid var(--bkg-blue-primary);
    padding: 14px 32px;
    font-weight: 600;
    font-size: 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-back:hover {
    background: var(--bkg-blue-light);
}

.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    text-align: center;
}

.modal-icon {
    font-size: 48px;
    color: #c41c1c;
    margin-bottom: 20px;
}

.modal-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 15px;
}

.modal-text {
    color: var(--bkg-gray-500);
    font-size: 14px;
    margin-bottom: 25px;
    line-height: 1.6;
}

.modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.btn-modal-confirm {
    background: #c41c1c;
    color: white;
    border: none;
    padding: 12px 24px;
    font-weight: 600;
    font-size: 14px;
    border-radius: 4px;
    cursor: pointer;
}

.btn-modal-cancel {
    background: var(--bkg-gray-100);
    color: var(--bkg-gray-700);
    border: 1px solid var(--bkg-gray-200);
    padding: 12px 24px;
    font-weight: 600;
    font-size: 14px;
    border-radius: 4px;
    cursor: pointer;
}

@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
    }
    
    .cancel-header {
        padding: 20px;
    }
    
    .cancel-header h1 {
        font-size: 20px;
    }
    
    .warning-box {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<div class="cancel-container">
    <div class="cancel-card">
        <div class="cancel-header">
            <div class="cancel-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h1>Cancel Reservation</h1>
            <p>Please confirm your cancellation</p>
        </div>
        
        <div class="cancel-body">
            <!-- Warning Message -->
            <div class="warning-box">
                <i class="bi bi-info-circle-fill"></i>
                <p>Cancelling a reservation cannot be undone. Please confirm you want to proceed.</p>
            </div>
            
            <!-- Restaurant Name -->
            <div class="restaurant-name"><?php echo sanitize($reservation['restaurant_name']); ?></div>
            
            <?php if (!empty($reservation['cuisine_type'])): ?>
            <div class="cuisine-badge"><?php echo sanitize($reservation['cuisine_type']); ?></div>
            <?php endif; ?>
            
            <!-- Reservation Details -->
            <div class="reservation-details">
                <div class="detail-row">
                    <span class="detail-label">Confirmation code</span>
                    <span class="detail-value"><?php echo $reservation['confirmation_code']; ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Date</span>
                    <span class="detail-value"><?php echo date('l, F j, Y', strtotime($reservation['reservation_date'])); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Time</span>
                    <span class="detail-value"><?php echo date('g:i A', strtotime($reservation['reservation_time'])); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Guests</span>
                    <span class="detail-value"><?php echo $reservation['guest_count']; ?> people</span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Reserved for</span>
                    <span class="detail-value"><?php echo sanitize($reservation['first_name'] . ' ' . $reservation['last_name']); ?></span>
                </div>
                
                <?php if (!empty($reservation['hotel_name'])): ?>
                <div class="detail-row">
                    <span class="detail-label">Location</span>
                    <span class="detail-value"><?php echo sanitize($reservation['hotel_name']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Cancellation Policy -->
            <?php if ($can_cancel): ?>
            <div class="cancel-policy">
                <i class="bi bi-check-circle-fill"></i>
                <p><strong>Free cancellation:</strong> You can cancel this reservation for free (up to 2 hours before).</p>
            </div>
            <?php else: ?>
            <div class="cancel-policy warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <p><strong>Late cancellation:</strong> This reservation is within 2 hours of the booking time. Cancellation may incur fees.</p>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if ($can_cancel): ?>
                <button class="btn-cancel" onclick="showCancelModal()">
                    <i class="bi bi-x-circle"></i>
                    Yes, cancel reservation
                </button>
                <?php else: ?>
                <button class="btn-cancel" disabled>
                    <i class="bi bi-x-circle"></i>
                    Cannot cancel (within 2 hours)
                </button>
                <?php endif; ?>
                
                <a href="/gorwanda-plus/bookings.php" class="btn-back">
                    <i class="bi bi-arrow-left"></i>
                    No, go back
                </a>
            </div>
            
            <p class="text-center text-muted small mt-4">
                <i class="bi bi-shield-check"></i>
                Your reservation is protected by GoRwanda+ cancellation policy
            </p>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-content">
        <div class="modal-icon">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <h3 class="modal-title">Confirm Cancellation</h3>
        <p class="modal-text">
            Are you sure you want to cancel your reservation at <strong><?php echo sanitize($reservation['restaurant_name']); ?></strong>?<br>
            This action cannot be undone.
        </p>
        <div class="modal-buttons">
            <form method="POST" action="">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn-modal-confirm">Yes, cancel now</button>
            </form>
            <button class="btn-modal-cancel" onclick="hideCancelModal()">No, keep it</button>
        </div>
    </div>
</div>

<script>
function showCancelModal() {
    document.getElementById('cancelModal').classList.add('active');
}

function hideCancelModal() {
    document.getElementById('cancelModal').classList.remove('active');
}

// Close modal when clicking outside
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideCancelModal();
    }
});

// Prevent accidental navigation
window.addEventListener('beforeunload', function(e) {
    // Only show warning if modal is open
    if (document.getElementById('cancelModal').classList.contains('active')) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>