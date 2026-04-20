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

// Get booking details with all相关信息
$stmt = $db->prepare("
    SELECT b.*, a.attraction_name, a.main_image, a.duration_minutes,
           a.description, a.meeting_point, a.cancellation_policy,
           at.tier_name, at.price_type,
           l.name as location_name,
           u.first_name as owner_name, u.last_name as owner_last,
           u.phone as owner_phone, u.email as owner_email,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as total_reviews,
           (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating
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

// Get weather info (simulated)
$weatherConditions = ['Sunny', 'Partly Cloudy', 'Clear', 'Warm', 'Perfect for outdoor activities'];
$weather = $weatherConditions[array_rand($weatherConditions)];
$temperature = rand(22, 28);

// Get similar experiences
$stmt = $db->prepare("
    SELECT a.*, 
           (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id) as min_price,
           (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count
    FROM attractions a
    WHERE a.attraction_id != ? AND a.category_id = ? AND a.is_active = 1
    ORDER BY a.avg_rating DESC
    LIMIT 3
");
$stmt->execute([$booking['attraction_id'], $booking['category_id'] ?? 0]);
$similarExperiences = $stmt->fetchAll();

$pageTitle = 'Booking Confirmed - ' . $booking['attraction_name'];
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

.confirmation-page {
    background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
    min-height: calc(100vh - 64px);
    padding: 40px 0;
}

.confirmation-container {
    max-width: 800px;
    margin: 0 auto;
}

.confirmation-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 48px;
    box-shadow: var(--shadow-lg);
    animation: scaleIn 0.5s ease;
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.9);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.success-animation {
    text-align: center;
    margin-bottom: 32px;
}

.success-checkmark {
    width: 100px;
    height: 100px;
    margin: 0 auto 24px;
    position: relative;
}

.check-icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, var(--success), #00a86b);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
    animation: bounceIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes bounceIn {
    0% {
        opacity: 0;
        transform: scale(0.3);
    }
    50% {
        opacity: 1;
        transform: scale(1.1);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        transform: scale(1);
    }
}

.confirmation-title {
    font-size: 2rem;
    font-weight: 800;
    color: var(--success);
    margin-bottom: 8px;
}

.confirmation-subtitle {
    font-size: 1rem;
    color: var(--text-secondary);
    margin-bottom: 32px;
}

/* Booking Reference */
.reference-box {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 24px;
    text-align: center;
    margin-bottom: 32px;
    border: 2px dashed var(--primary);
}

.reference-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.reference-value {
    font-size: 2rem;
    font-weight: 700;
    font-family: monospace;
    color: var(--primary);
    letter-spacing: 2px;
    margin-bottom: 8px;
}

.reference-note {
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

/* Details Grid */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
    margin-bottom: 32px;
}

.detail-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 20px;
    transition: var(--transition);
}

.detail-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.detail-icon {
    width: 48px;
    height: 48px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    font-size: 1.25rem;
    color: var(--primary);
}

.detail-title {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}

.detail-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 4px;
}

.detail-sub {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Experience Card */
.experience-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 24px;
    margin-bottom: 32px;
    display: flex;
    gap: 24px;
    align-items: center;
}

.experience-image {
    width: 100px;
    height: 100px;
    border-radius: var(--radius-md);
    object-fit: cover;
    background: white;
}

.experience-info {
    flex: 1;
}

.experience-name {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.experience-meta {
    display: flex;
    gap: 20px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.experience-meta i {
    color: var(--primary);
    margin-right: 4px;
}

/* Meeting Point */
.meeting-point {
    background: #fff4e6;
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 32px;
    border-left: 4px solid var(--warning);
    display: flex;
    align-items: center;
    gap: 16px;
}

.meeting-point i {
    font-size: 2rem;
    color: var(--warning);
}

.meeting-point-content h4 {
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--warning);
}

.meeting-point-content p {
    font-size: 0.9375rem;
    color: var(--text-secondary);
}

/* Weather Widget */
.weather-widget {
    background: linear-gradient(135deg, #43cea2, #185a9d);
    border-radius: var(--radius-md);
    padding: 20px;
    color: white;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.weather-icon {
    font-size: 2.5rem;
}

.weather-info h4 {
    font-weight: 700;
    margin-bottom: 4px;
}

.weather-info p {
    opacity: 0.9;
    font-size: 0.8125rem;
}

.weather-temp {
    font-size: 2rem;
    font-weight: 700;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin: 32px 0;
    flex-wrap: wrap;
}

.btn-primary {
    padding: 14px 32px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-secondary {
    padding: 14px 32px;
    background: white;
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-secondary:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateY(-2px);
}

/* Similar Experiences */
.similar-section {
    margin-top: 48px;
}

.similar-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 20px;
}

.similar-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
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
    height: 120px;
    overflow: hidden;
}

.similar-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.similar-content {
    padding: 12px;
}

.similar-name {
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 4px;
}

.similar-price {
    font-size: 0.8125rem;
    color: var(--success);
    font-weight: 600;
}

/* Share Section */
.share-section {
    text-align: center;
    margin-top: 40px;
    padding-top: 32px;
    border-top: 1px solid var(--border);
}

.share-title {
    font-size: 0.9375rem;
    font-weight: 600;
    margin-bottom: 16px;
    color: var(--text-secondary);
}

.share-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
}

.share-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text);
    text-decoration: none;
    transition: var(--transition);
}

.share-btn:hover {
    background: var(--primary);
    color: white;
    transform: translateY(-2px);
}

/* Print Styles */
@media print {
    .no-print {
        display: none;
    }
    
    .confirmation-card {
        box-shadow: none;
        border: 1px solid #000;
    }
}

/* Responsive */
@media (max-width: 768px) {
    .confirmation-card {
        padding: 24px;
    }
    
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .experience-card {
        flex-direction: column;
        text-align: center;
    }
    
    .experience-meta {
        flex-direction: column;
        gap: 8px;
    }
    
    .similar-grid {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn-primary, .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="confirmation-page">
    <div class="confirmation-container">
        <!-- Confirmation Card -->
        <div class="confirmation-card">
            <div class="success-animation">
                <div class="success-checkmark">
                    <div class="check-icon">
                        <i class="bi bi-check-lg"></i>
                    </div>
                </div>
                <h1 class="confirmation-title">Booking Confirmed!</h1>
                <p class="confirmation-subtitle">
                    Your experience has been successfully booked. A confirmation email has been sent to your inbox.
                </p>
            </div>

            <!-- Booking Reference -->
            <div class="reference-box">
                <div class="reference-label">Booking Reference</div>
                <div class="reference-value"><?php echo $bookingRef; ?></div>
                <div class="reference-note">Please keep this reference for your records</div>
            </div>

            <!-- Details Grid -->
            <div class="details-grid">
                <div class="detail-card">
                    <div class="detail-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="detail-title">Date & Time</div>
                    <div class="detail-value"><?php echo date('l, M d, Y', strtotime($booking['experience_date'])); ?></div>
                    <div class="detail-sub">
                        <?php if ($booking['start_time']): ?>
                            Starts at <?php echo date('h:i A', strtotime($booking['start_time'])); ?>
                        <?php else: ?>
                            Flexible timing
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-card">
                    <div class="detail-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="detail-title">Participants</div>
                    <div class="detail-value"><?php echo $booking['num_participants']; ?></div>
                    <div class="detail-sub">
                        <?php echo $booking['tier_name']; ?> tier
                    </div>
                </div>

                <div class="detail-card">
                    <div class="detail-icon">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="detail-title">Duration</div>
                    <div class="detail-value">
                        <?php 
                        if ($booking['duration_minutes']) {
                            $hours = floor($booking['duration_minutes'] / 60);
                            $minutes = $booking['duration_minutes'] % 60;
                            echo $hours . ' hour' . ($hours > 1 ? 's' : '');
                            if ($minutes) echo ' ' . $minutes . ' min';
                        } else {
                            echo 'Flexible';
                        }
                        ?>
                    </div>
                    <div class="detail-sub">Estimated duration</div>
                </div>

                <div class="detail-card">
                    <div class="detail-icon">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="detail-title">Total Paid</div>
                    <div class="detail-value"><?php echo formatPrice($booking['total_amount']); ?></div>
                    <div class="detail-sub">
                        Paid via <?php 
                        echo $booking['payment_method'] === 'momo' ? 'MTN MoMo' : 
                             ($booking['payment_method'] === 'card' ? 'Credit Card' : 'Bank Transfer'); 
                        ?>
                    </div>
                </div>
            </div>

            <!-- Experience Card -->
            <div class="experience-card">
                <img src="<?php echo getImageUrl($booking['main_image'] ?? '', 'attraction'); ?>" 
                     alt="<?php echo sanitize($booking['attraction_name']); ?>" 
                     class="experience-image"
                     onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=100&q=60'">
                <div class="experience-info">
                    <h3 class="experience-name"><?php echo sanitize($booking['attraction_name']); ?></h3>
                    <div class="experience-meta">
                        <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($booking['location_name'] ?? 'Rwanda'); ?></span>
                        <span><i class="bi bi-star-fill text-warning"></i> <?php echo number_format($booking['avg_rating'] ?? 0, 1); ?> (<?php echo $booking['total_reviews'] ?? 0; ?> reviews)</span>
                        <span><i class="bi bi-shield-check text-success"></i> Verified provider</span>
                    </div>
                </div>
            </div>

            <!-- Meeting Point -->
            <?php if ($booking['meeting_point']): ?>
            <div class="meeting-point">
                <i class="bi bi-pin-map-fill"></i>
                <div class="meeting-point-content">
                    <h4>Meeting Point</h4>
                    <p><?php echo nl2br(sanitize($booking['meeting_point'])); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Weather Widget -->
            <div class="weather-widget">
                <div class="weather-icon">
                    <i class="bi bi-brightness-high-fill"></i>
                </div>
                <div class="weather-info">
                    <h4>Weather Forecast</h4>
                    <p><?php echo $weather; ?>. Perfect for your experience!</p>
                </div>
                <div class="weather-temp">
                    <?php echo $temperature; ?>°C
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons no-print">
                <a href="/gorwanda-plus/bookings.php" class="btn-primary">
                    <i class="bi bi-calendar-check"></i> View My Bookings
                </a>
                <a href="/gorwanda-plus/?type=attractions" class="btn-secondary">
                    <i class="bi bi-search"></i> Explore More
                </a>
                <button onclick="window.print()" class="btn-secondary">
                    <i class="bi bi-printer"></i> Print Confirmation
                </button>
            </div>

            <!-- Cancellation Policy -->
            <div style="margin-top: 24px; padding: 16px; background: var(--bg-secondary); border-radius: var(--radius-md); font-size: 0.8125rem; color: var(--text-secondary);">
                <i class="bi bi-info-circle-fill me-2" style="color: var(--primary);"></i>
                <strong>Cancellation Policy:</strong> 
                <?php echo $booking['cancellation_policy'] ?? 'Free cancellation up to 24 hours before the experience.'; ?>
            </div>
        </div>

        <!-- Similar Experiences -->
        <?php if (!empty($similarExperiences)): ?>
        <div class="similar-section no-print">
            <h3 class="similar-title">You might also like</h3>
            <div class="similar-grid">
                <?php foreach ($similarExperiences as $similar): ?>
                <a href="detail.php?id=<?php echo $similar['attraction_id']; ?>" class="similar-card">
                    <div class="similar-image">
                        <img src="<?php echo getImageUrl($similar['main_image'] ?? '', 'attraction'); ?>" 
                             alt="<?php echo sanitize($similar['attraction_name']); ?>"
                             onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=200&q=60'">
                    </div>
                    <div class="similar-content">
                        <div class="similar-name"><?php echo sanitize($similar['attraction_name']); ?></div>
                        <div class="similar-price">From <?php echo formatPrice($similar['min_price']); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Share Section -->
        <div class="share-section no-print">
            <div class="share-title">Share your excitement</div>
            <div class="share-buttons">
                <a href="#" class="share-btn" onclick="shareOnFacebook()"><i class="bi bi-facebook"></i></a>
                <a href="#" class="share-btn" onclick="shareOnTwitter()"><i class="bi bi-twitter-x"></i></a>
                <a href="#" class="share-btn" onclick="shareOnWhatsApp()"><i class="bi bi-whatsapp"></i></a>
                <a href="#" class="share-btn" onclick="shareByEmail()"><i class="bi bi-envelope"></i></a>
            </div>
        </div>
    </div>
</div>

<script>
// Share functions
function shareOnFacebook() {
    const url = encodeURIComponent(window.location.href);
    const text = encodeURIComponent('I just booked <?php echo addslashes($booking['attraction_name']); ?> on GoRwanda+!');
    window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank');
}

function shareOnTwitter() {
    const url = encodeURIComponent(window.location.href);
    const text = encodeURIComponent('I just booked <?php echo addslashes($booking['attraction_name']); ?> on GoRwanda+!');
    window.open(`https://twitter.com/intent/tweet?url=${url}&text=${text}`, '_blank');
}

function shareOnWhatsApp() {
    const text = encodeURIComponent('I just booked <?php echo addslashes($booking['attraction_name']); ?> on GoRwanda+! Check it out: ' + window.location.href);
    window.open(`https://wa.me/?text=${text}`, '_blank');
}

function shareByEmail() {
    const subject = encodeURIComponent('My GoRwanda+ Booking: <?php echo addslashes($booking['attraction_name']); ?>');
    const body = encodeURIComponent('I just booked <?php echo addslashes($booking['attraction_name']); ?> on GoRwanda+.\n\nBooking Reference: <?php echo $bookingRef; ?>\nDate: <?php echo date('M d, Y', strtotime($booking['experience_date'])); ?>\n\nView details: ' + window.location.href);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
}

// Print optimization
window.onbeforeprint = function() {
    document.querySelectorAll('.no-print').forEach(el => el.style.display = 'none');
};

window.onafterprint = function() {
    document.querySelectorAll('.no-print').forEach(el => el.style.display = '');
};

// Confetti animation (optional)
function launchConfetti() {
    const duration = 3 * 1000;
    const animationEnd = Date.now() + duration;
    const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 };

    function randomInRange(min, max) {
        return Math.random() * (max - min) + min;
    }

    const interval = setInterval(function() {
        const timeLeft = animationEnd - Date.now();

        if (timeLeft <= 0) {
            return clearInterval(interval);
        }

        const particleCount = 50 * (timeLeft / duration);
        confetti(Object.assign({}, defaults, { 
            particleCount, 
            origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } 
        }));
        confetti(Object.assign({}, defaults, { 
            particleCount, 
            origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } 
        }));
    }, 250);
}

// Auto-launch confetti on page load
document.addEventListener('DOMContentLoaded', function() {
    if (typeof confetti === 'function') {
        launchConfetti();
    }
});
</script>

<!-- Include confetti library (optional) -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1"></script>

<?php require_once '../includes/footer.php'; ?>