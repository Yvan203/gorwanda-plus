<?php
require_once '../includes/functions.php';
$currentPage = 'restaurants';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$reservation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$reservation_id) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get reservation details - FIXED THE SQL QUERY
$stmt = $db->prepare("
    SELECT tr.*, u.first_name, u.last_name, u.email,
           res.restaurant_id, res.restaurant_name, res.cuisine_type, 
           s.stay_name as hotel_name, s.stay_id
    FROM table_reservations tr
    JOIN restaurants res ON tr.restaurant_id = res.restaurant_id
    JOIN users u ON tr.user_id = u.user_id
    LEFT JOIN stays s ON res.stay_id = s.stay_id
    WHERE tr.reservation_id = ? AND tr.user_id = ?
");
$stmt->execute([$reservation_id, $_SESSION['user_id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
    setFlash('error', 'Reservation not found');
    header('Location: /gorwanda-plus/bookings.php');
    exit;
}

$pageTitle = 'Reservation Confirmed - GoRwanda+';
require_once '../includes/header.php';
?>

<style>
/* Success Page Styles */
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

.success-container {
    max-width: 800px;
    margin: 40px auto;
    padding: 0 20px;
}

.success-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}

.success-header {
    background: linear-gradient(135deg, #003580, #0071c2);
    color: white;
    padding: 40px;
    text-align: center;
}

.success-icon {
    font-size: 64px;
    margin-bottom: 20px;
    animation: scaleIn 0.5s ease;
}

@keyframes scaleIn {
    from {
        transform: scale(0);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.success-header h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
}

.success-header p {
    font-size: 16px;
    opacity: 0.9;
    margin-bottom: 0;
}

.success-body {
    padding: 40px;
}

.confirmation-box {
    background: var(--bkg-blue-light);
    border: 2px dashed var(--bkg-blue-primary);
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    margin-bottom: 30px;
}

.confirmation-label {
    font-size: 14px;
    color: var(--bkg-gray-500);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 10px;
}

.confirmation-code {
    font-size: 32px;
    font-weight: 700;
    color: var(--bkg-blue-primary);
    letter-spacing: 2px;
    font-family: monospace;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.detail-item {
    padding: 20px;
    background: var(--bkg-gray-100);
    border-radius: 8px;
    border: 1px solid var(--bkg-gray-200);
}

.detail-label {
    font-size: 12px;
    color: var(--bkg-gray-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.detail-value {
    font-size: 18px;
    font-weight: 600;
    color: var(--bkg-gray-700);
}

.detail-value small {
    font-size: 14px;
    font-weight: 400;
    color: var(--bkg-gray-500);
}

.restaurant-info {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    margin-bottom: 30px;
}

.restaurant-icon {
    width: 60px;
    height: 60px;
    background: var(--bkg-blue-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bkg-blue-primary);
    font-size: 24px;
}

.restaurant-details h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    margin-bottom: 4px;
}

.restaurant-details p {
    font-size: 14px;
    color: var(--bkg-gray-500);
    margin-bottom: 0;
}

.action-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
}

.btn-success-primary {
    background: var(--bkg-blue-primary);
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

.btn-success-primary:hover {
    background: #005fa3;
    color: white;
}

.btn-success-secondary {
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

.btn-success-secondary:hover {
    background: var(--bkg-blue-light);
}

.help-text {
    text-align: center;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--bkg-gray-200);
    color: var(--bkg-gray-500);
    font-size: 14px;
}

.help-text a {
    color: var(--bkg-blue-primary);
    text-decoration: none;
}

.help-text a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .success-header {
        padding: 30px 20px;
    }
    
    .success-body {
        padding: 20px;
    }
    
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .confirmation-code {
        font-size: 24px;
    }
}
</style>

<div class="success-container">
    <div class="success-card">
        <div class="success-header">
            <div class="success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h1>Reservation Confirmed!</h1>
            <p>Your table has been successfully reserved</p>
        </div>
        
        <div class="success-body">
            <div class="confirmation-box">
                <div class="confirmation-label">Confirmation code</div>
                <div class="confirmation-code"><?php echo $reservation['confirmation_code']; ?></div>
            </div>
            
            <div class="details-grid">
                <div class="detail-item">
                    <div class="detail-label">Restaurant</div>
                    <div class="detail-value"><?php echo sanitize($reservation['restaurant_name']); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Cuisine</div>
                    <div class="detail-value"><?php echo sanitize($reservation['cuisine_type'] ?: 'Various'); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Date</div>
                    <div class="detail-value"><?php echo date('l, F j, Y', strtotime($reservation['reservation_date'])); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Time</div>
                    <div class="detail-value"><?php echo date('g:i A', strtotime($reservation['reservation_time'])); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Guests</div>
                    <div class="detail-value"><?php echo $reservation['guest_count']; ?> people</div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Reserved for</div>
                    <div class="detail-value"><?php echo sanitize($reservation['first_name'] . ' ' . $reservation['last_name']); ?></div>
                </div>
            </div>
            
            <?php if (!empty($reservation['hotel_name'])): ?>
            <div class="restaurant-info">
                <div class="restaurant-icon">
                    <i class="bi bi-building"></i>
                </div>
                <div class="restaurant-details">
                    <h3><?php echo sanitize($reservation['hotel_name']); ?></h3>
                    <p>Located inside this hotel</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($reservation['special_requests'])): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Special requests:</strong> <?php echo sanitize($reservation['special_requests']); ?>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="/gorwanda-plus/bookings.php" class="btn-success-primary">
                    <i class="bi bi-calendar-check"></i>
                    View my bookings
                </a>
                <a href="/gorwanda-plus/restaurants/" class="btn-success-secondary">
                    <i class="bi bi-shop"></i>
                    Browse more restaurants
                </a>
            </div>
            
            <div class="help-text">
                <i class="bi bi-envelope me-1"></i>
                A confirmation email has been sent to <strong><?php echo $reservation['email']; ?></strong><br>
                Need to cancel or modify? <a href="cancel-reservation.php?code=<?php echo $reservation['confirmation_code']; ?>">Click here</a>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>