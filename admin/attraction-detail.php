<?php
$attractionId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$attractionId) {
    header('Location: attractions.php');
    exit;
}

$pageTitle = 'Experience Details';
require_once 'includes/admin_header.php';

$db = getDB();

// Get attraction details
$stmt = $db->prepare("
    SELECT 
        a.*,
        c.name as category_name,
        c.icon as category_icon,
        l.name as location_name,
        l.latitude as location_lat,
        l.longitude as location_lng,
        u.user_id as owner_id,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        u.phone as owner_phone,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id) as total_tiers,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as active_tiers,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT AVG(overall_rating) FROM reviews r 
         WHERE r.attraction_id = a.attraction_id AND r.review_type = 'attraction') as avg_rating,
        (SELECT COUNT(*) FROM reviews r 
         WHERE r.attraction_id = a.attraction_id AND r.review_type = 'attraction') as review_count
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE a.attraction_id = ?
");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: attractions.php');
    exit;
}

// Get pricing tiers
$stmt = $db->prepare("
    SELECT 
        t.*,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.attraction_tier_id = t.tier_id AND b.status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         WHERE b.attraction_tier_id = t.tier_id AND b.status IN ('confirmed', 'completed')) as tier_revenue
    FROM attraction_tiers t
    WHERE t.attraction_id = ?
    ORDER BY t.base_price ASC
");
$stmt->execute([$attractionId]);
$tiers = $stmt->fetchAll();

// Get recent bookings
$stmt = $db->prepare("
    SELECT 
        b.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        t.tier_name,
        t.base_price as tier_price
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    WHERE t.attraction_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$attractionId]);
$recentBookings = $stmt->fetchAll();

// Get availability for next 30 days
$stmt = $db->prepare("
    SELECT 
        aa.*,
        t.tier_name
    FROM attraction_availability aa
    LEFT JOIN attraction_tiers t ON aa.tier_id = t.tier_id
    WHERE t.attraction_id = ? AND aa.date >= CURDATE() AND aa.date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY aa.date ASC
");
$stmt->execute([$attractionId]);
$availability = $stmt->fetchAll();

// Group availability by tier
$availabilityByTier = [];
foreach ($availability as $avail) {
    $availabilityByTier[$avail['tier_id']][$avail['date']] = $avail;
}

// Get reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.profile_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.attraction_id = ? AND r.review_type = 'attraction'
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$attractionId]);
$reviews = $stmt->fetchAll();

// Get images
$images = [];
if ($attraction['main_image']) {
    $images[] = $attraction['main_image'];
}
if ($attraction['gallery_images']) {
    $galleryImages = json_decode($attraction['gallery_images'], true);
    if (is_array($galleryImages)) {
        $images = array_merge($images, $galleryImages);
    }
}
$images = array_unique($images);

// Get included/excluded items
$includedItems = $attraction['included_items'] ? json_decode($attraction['included_items'], true) : [];
$excludedItems = $attraction['excluded_items'] ? json_decode($attraction['excluded_items'], true) : [];
$whatToBring = $attraction['what_to_bring'] ? json_decode($attraction['what_to_bring'], true) : [];
$startTimes = $attraction['start_times'] ? json_decode($attraction['start_times'], true) : [];
$guideLanguages = $attraction['guide_languages'] ? json_decode($attraction['guide_languages'], true) : [];

// Difficulty level mapping
$difficultyIcons = [
    'easy' => ['icon' => 'bi-emoji-smile', 'color' => '#008009', 'label' => 'Easy'],
    'moderate' => ['icon' => 'bi-arrow-left-right', 'color' => '#ff8c00', 'label' => 'Moderate'],
    'challenging' => ['icon' => 'bi-chevron-double-up', 'color' => '#e21111', 'label' => 'Challenging']
];
$difficultyInfo = $difficultyIcons[$attraction['difficulty_level']] ?? $difficultyIcons['moderate'];

$physicalIntensityMap = [
    'light' => ['icon' => 'bi-walk', 'label' => 'Light Activity'],
    'moderate' => ['icon' => 'bi-person-walking', 'label' => 'Moderate Activity'],
    'intense' => ['icon' => 'bi-person-running', 'label' => 'Intense Activity']
];
$intensityInfo = $physicalIntensityMap[$attraction['physical_intensity']] ?? $physicalIntensityMap['moderate'];
?>

<style>
/* Attraction Detail Styles */
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

.attraction-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.attraction-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

.attraction-location {
    color: var(--booking-text-light);
    font-size: 0.875rem;
    margin-bottom: 16px;
}

.attraction-location i {
    margin-right: 4px;
}

/* Stats Grid */
.detail-stats {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    text-align: center;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 4px;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
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
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.info-header h3 {
    font-size: 0.875rem;
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
    padding: 8px 0;
    border-bottom: 1px solid var(--booking-border);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    width: 120px;
    font-weight: 600;
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.info-value {
    flex: 1;
    font-size: 0.75rem;
    color: var(--booking-text);
}

/* Difficulty Badge */
.difficulty-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

/* Items Lists */
.items-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.item-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: var(--booking-gray-light);
    border-radius: 20px;
    font-size: 0.6875rem;
}

.item-badge.included i {
    color: var(--booking-success);
}

.item-badge.excluded i {
    color: var(--booking-danger);
}

/* Tiers Table */
.tiers-table {
    width: 100%;
    border-collapse: collapse;
}

.tiers-table th {
    text-align: left;
    padding: 12px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.tiers-table td {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
}

.tiers-table tr:hover td {
    background: var(--booking-gray-light);
}

/* Availability Calendar */
.calendar-container {
    overflow-x: auto;
    margin-top: 12px;
}

.availability-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.6875rem;
}

.availability-table th {
    padding: 10px;
    text-align: center;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
}

.availability-table td {
    padding: 8px;
    text-align: center;
    border: 1px solid var(--booking-border);
}

.available-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.available-yes {
    background: #e6f4ea;
    color: var(--booking-success);
}

.available-no {
    background: #fce8e8;
    color: var(--booking-danger);
}

.available-limited {
    background: #fff4e6;
    color: var(--booking-warning);
}

/* Booking List */
.booking-list {
    max-height: 400px;
    overflow-y: auto;
}

.booking-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.booking-item:hover {
    background: var(--booking-gray-light);
}

.booking-info {
    flex: 1;
}

.booking-ref {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.booking-ref a {
    color: var(--booking-text);
    text-decoration: none;
}

.booking-ref a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.booking-guest {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.booking-tier {
    font-size: 0.625rem;
    color: var(--booking-text-lighter);
}

.booking-details {
    text-align: right;
}

.booking-date {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.booking-amount {
    font-weight: 700;
    font-size: 0.75rem;
    color: var(--booking-success);
}

/* Review List */
.review-list {
    max-height: 400px;
    overflow-y: auto;
}

.review-item {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.reviewer {
    display: flex;
    align-items: center;
    gap: 8px;
}

.reviewer-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.reviewer-name {
    font-weight: 600;
    font-size: 0.75rem;
}

.review-rating {
    display: flex;
    gap: 2px;
}

.review-rating i {
    font-size: 0.6875rem;
    color: #ffc107;
}

.review-rating i.empty {
    color: #e0e0e0;
}

.review-title {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 4px;
}

.review-comment {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    line-height: 1.4;
}

.review-date {
    font-size: 0.5625rem;
    color: var(--booking-text-lighter);
    margin-top: 8px;
}

/* Gallery */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.gallery-item {
    position: relative;
    aspect-ratio: 4/3;
    border-radius: var(--radius-sm);
    overflow: hidden;
    cursor: pointer;
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.gallery-item:hover img {
    transform: scale(1.05);
}

.gallery-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.625rem;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.status-verified {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--transition-fast);
}

.action-btn.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.action-btn.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    max-width: 90%;
    max-height: 90%;
    position: relative;
}

.modal-content img {
    max-width: 100%;
    max-height: 90vh;
    object-fit: contain;
}

.modal-close {
    position: absolute;
    top: 20px;
    right: 40px;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    z-index: 10001;
}

/* Responsive */
@media (max-width: 1024px) {
    .detail-stats {
        grid-template-columns: repeat(3, 1fr);
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .detail-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .attraction-title {
        flex-direction: column;
    }
    .info-row {
        flex-direction: column;
        gap: 4px;
    }
    .info-label {
        width: auto;
    }
}
</style>

<div class="detail-header">
    <a href="attractions.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Experiences
    </a>
    
    <div class="attraction-title">
        <div>
            <h1><?php echo sanitize($attraction['attraction_name']); ?></h1>
            <div class="attraction-location">
                <i class="bi bi-geo-alt"></i> <?php echo sanitize($attraction['address'] ?: 'Address not provided'); ?>
                <?php if ($attraction['location_name']): ?> • <?php echo sanitize($attraction['location_name']); ?><?php endif; ?>
            </div>
        </div>
        <div class="action-buttons">
            <a href="edit-attraction.php?id=<?php echo $attraction['attraction_id']; ?>" class="action-btn primary">
                <i class="bi bi-pencil"></i> Edit Experience
            </a>
            <a href="tiers.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn secondary">
                <i class="bi bi-layers"></i> Manage Tiers
            </a>
        </div>
    </div>
    
    <div style="display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
        <?php if ($attraction['is_verified']): ?>
        <span class="status-badge status-verified">
            <i class="bi bi-shield-check"></i> Verified
        </span>
        <?php else: ?>
        <span class="status-badge status-pending">
            <i class="bi bi-clock"></i> Pending Verification
        </span>
        <?php endif; ?>
        
        <?php if ($attraction['is_active']): ?>
        <span class="status-badge status-active">
            <i class="bi bi-check-circle"></i> Active
        </span>
        <?php else: ?>
        <span class="status-badge status-inactive">
            <i class="bi bi-x-circle"></i> Inactive
        </span>
        <?php endif; ?>
        
        <span class="difficulty-badge" style="background: <?php echo $difficultyInfo['color']; ?>20; color: <?php echo $difficultyInfo['color']; ?>;">
            <i class="bi <?php echo $difficultyInfo['icon']; ?>"></i>
            <?php echo $difficultyInfo['label']; ?> Difficulty
        </span>
        
        <span class="difficulty-badge" style="background: rgba(0,102,255,0.1); color: var(--booking-blue);">
            <i class="bi <?php echo $intensityInfo['icon']; ?>"></i>
            <?php echo $intensityInfo['label']; ?>
        </span>
    </div>
</div>

<!-- Stats Overview -->
<div class="detail-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $attraction['total_tiers']; ?></div>
        <div class="stat-label">Pricing Tiers</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $attraction['active_tiers']; ?> active
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($attraction['total_bookings']); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($attraction['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $attraction['avg_rating'] ? number_format($attraction['avg_rating'], 1) : 'N/A'; ?></div>
        <div class="stat-label">Avg Rating</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $attraction['review_count']; ?> reviews
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $attraction['max_group_size'] ?: '—'; ?></div>
        <div class="stat-label">Max Group Size</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $attraction['duration_minutes'] ? floor($attraction['duration_minutes'] / 60) . 'h ' . ($attraction['duration_minutes'] % 60) . 'm' : '—'; ?></div>
        <div class="stat-label">Duration</div>
    </div>
</div>

<!-- Information Grid -->
<div class="info-grid">
    <!-- Experience Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-info-circle"></i> Experience Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Category</div>
                <div class="info-value">
                    <i class="bi <?php echo $attraction['category_icon'] ?? 'bi-tag'; ?>"></i>
                    <?php echo sanitize($attraction['category_name'] ?? 'Uncategorized'); ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Min Age</div>
                <div class="info-value"><?php echo $attraction['min_age'] ? $attraction['min_age'] . ' years' : 'No age restriction'; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Commission Rate</div>
                <div class="info-value"><?php echo $attraction['commission_rate']; ?>%</div>
            </div>
            <div class="info-row">
                <div class="info-label">Free Cancellation</div>
                <div class="info-value">
                    <?php if ($attraction['free_cancellation']): ?>
                    <span style="color: var(--booking-success);"><i class="bi bi-check-circle"></i> Yes</span>
                    <?php else: ?>
                    <span style="color: var(--booking-danger);"><i class="bi bi-x-circle"></i> No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Instant Confirmation</div>
                <div class="info-value">
                    <?php if ($attraction['instant_confirmation']): ?>
                    <span style="color: var(--booking-success);"><i class="bi bi-check-circle"></i> Yes</span>
                    <?php else: ?>
                    <span style="color: var(--booking-danger);"><i class="bi bi-x-circle"></i> No</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($attraction['description']): ?>
            <div class="info-row">
                <div class="info-label">Description</div>
                <div class="info-value">
                    <?php echo nl2br(sanitize(substr($attraction['description'], 0, 300))); ?>
                    <?php if (strlen($attraction['description']) > 300): ?>...<?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Owner Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-person-badge"></i> Owner Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Name</div>
                <div class="info-value">
                    <a href="users.php?view=<?php echo $attraction['owner_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($attraction['owner_first'] . ' ' . $attraction['owner_last']); ?>
                    </a>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo sanitize($attraction['owner_email']); ?></div>
            </div>
            <?php if ($attraction['owner_phone']): ?>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo sanitize($attraction['owner_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Meeting Point & Schedule -->
<div class="info-grid">
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-pin-map"></i> Meeting Point</h3>
        </div>
        <div class="info-body">
            <div class="info-value">
                <?php echo nl2br(sanitize($attraction['meeting_point'] ?: 'Meeting point information not provided')); ?>
            </div>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-clock"></i> Start Times</h3>
        </div>
        <div class="info-body">
            <div class="items-list">
                <?php if (empty($startTimes)): ?>
                <span class="item-badge">No specific times</span>
                <?php else: ?>
                <?php foreach ($startTimes as $time): ?>
                <span class="item-badge">
                    <i class="bi bi-clock"></i>
                    <?php echo date('g:i A', strtotime($time)); ?>
                </span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Included & Excluded Items -->
<div class="info-grid">
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-check-circle-fill"></i> Included</h3>
        </div>
        <div class="info-body">
            <div class="items-list">
                <?php if (empty($includedItems)): ?>
                <span class="item-badge">No items listed</span>
                <?php else: ?>
                <?php foreach ($includedItems as $item): ?>
                <span class="item-badge included">
                    <i class="bi bi-check-lg"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $item)); ?>
                </span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-x-circle-fill"></i> Excluded</h3>
        </div>
        <div class="info-body">
            <div class="items-list">
                <?php if (empty($excludedItems)): ?>
                <span class="item-badge">No items listed</span>
                <?php else: ?>
                <?php foreach ($excludedItems as $item): ?>
                <span class="item-badge excluded">
                    <i class="bi bi-x-lg"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $item)); ?>
                </span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- What to Bring -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-bag"></i> What to Bring</h3>
    </div>
    <div class="info-body">
        <div class="items-list">
            <?php if (empty($whatToBring)): ?>
            <span class="item-badge">No items listed</span>
            <?php else: ?>
            <?php foreach ($whatToBring as $item): ?>
            <span class="item-badge">
                <i class="bi bi-check-lg"></i>
                <?php echo ucfirst(str_replace('_', ' ', $item)); ?>
            </span>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Language & Cancellation -->
<div class="info-grid">
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-chat-dots"></i> Guide Languages</h3>
        </div>
        <div class="info-body">
            <div class="items-list">
                <?php if (empty($guideLanguages)): ?>
                <span class="item-badge">English</span>
                <?php else: ?>
                <?php foreach ($guideLanguages as $lang): ?>
                <span class="item-badge">
                    <i class="bi bi-translate"></i>
                    <?php echo strtoupper($lang); ?>
                </span>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-file-text"></i> Cancellation Policy</h3>
        </div>
        <div class="info-body">
            <div class="info-value">
                <?php echo nl2br(sanitize($attraction['cancellation_policy'] ?: 'Standard cancellation policy applies')); ?>
            </div>
        </div>
    </div>
</div>

<!-- Pricing Tiers -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-layers"></i> Pricing Tiers</h3>
        <a href="tiers.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            <i class="bi bi-plus-lg"></i> Manage Tiers
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($tiers)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-layers" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No pricing tiers added yet</p>
            <a href="tiers.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn primary" style="margin-top: 12px; display: inline-block;">
                Add Pricing Tier
            </a>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="tiers-table">
                <thead>
                    <tr>
                        <th>Tier Name</th>
                        <th>Price</th>
                        <th>Price Type</th>
                        <th>Max Participants</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tiers as $tier): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($tier['tier_name'] ?: 'Standard'); ?></strong>
                            <?php if ($tier['description']): ?>
                            <div style="font-size: 0.625rem; color: var(--booking-text-light); margin-top: 2px;">
                                <?php echo sanitize(substr($tier['description'], 0, 60)); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo formatPrice($tier['base_price']); ?></strong></td>
                        <td style="text-transform: capitalize;"><?php echo str_replace('_', ' ', $tier['price_type']); ?></td>
                        <td><?php echo $tier['max_participants'] ?: 'Unlimited'; ?></td>
                        <td><?php echo number_format($tier['booking_count']); ?></td>
                        <td style="color: var(--booking-success);"><?php echo formatPrice($tier['tier_revenue']); ?></td>
                        <td>
                            <?php if ($tier['is_active']): ?>
                            <span class="status-badge status-active" style="padding: 2px 8px;">Active</span>
                            <?php else: ?>
                            <span class="status-badge status-inactive" style="padding: 2px 8px;">Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Availability Calendar -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-calendar3"></i> Availability (Next 30 Days)</h3>
        <a href="availability.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            Manage Availability →
        </a>
    </div>
    <div class="info-body">
        <?php if (empty($availability)): ?>
        <div style="text-align: center; padding: 20px;">
            <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No availability data for next 30 days</p>
        </div>
        <?php else: ?>
        <div class="calendar-container">
            <table class="availability-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <?php foreach ($tiers as $tier): ?>
                        <th><?php echo sanitize($tier['tier_name'] ?: 'Standard'); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dates = [];
                    $currentDate = new DateTime();
                    for ($i = 0; $i < 30; $i++) {
                        $date = clone $currentDate;
                        $date->modify("+$i days");
                        $dates[] = $date->format('Y-m-d');
                    }
                    ?>
                    <?php foreach ($dates as $date): ?>
                    <tr>
                        <td><strong><?php echo date('M d', strtotime($date)); ?></strong></td>
                        <?php foreach ($tiers as $tier): ?>
                        <?php
                        $avail = $availabilityByTier[$tier['tier_id']][$date] ?? null;
                        $availableSpots = $avail ? ($avail['max_bookings'] - $avail['bookings_made']) : $tier['max_participants'];
                        $isBlocked = $avail ? $avail['is_blocked'] : false;
                        $priceOverride = $avail ? $avail['price_override'] : null;
                        ?>
                        <td>
                            <?php if ($isBlocked): ?>
                            <span class="available-badge available-no">Blocked</span>
                            <?php elseif ($availableSpots <= 0): ?>
                            <span class="available-badge available-no">Full</span>
                            <?php elseif ($availableSpots <= 3): ?>
                            <span class="available-badge available-limited"><?php echo $availableSpots; ?> left</span>
                            <?php else: ?>
                            <span class="available-badge available-yes">Available</span>
                            <?php endif; ?>
                            <?php if ($priceOverride): ?>
                            <div style="font-size: 0.5625rem; margin-top: 2px;"><?php echo formatPrice($priceOverride); ?></div>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Image Gallery -->
<?php if (!empty($images)): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-images"></i> Image Gallery</h3>
    </div>
    <div class="info-body">
        <div class="gallery-grid">
            <?php foreach ($images as $index => $image): ?>
            <div class="gallery-item" onclick="openModal('<?php echo getImageUrl($image, 'attraction'); ?>')">
                <img src="<?php echo getImageUrl($image, 'attraction'); ?>" alt="Experience image <?php echo $index + 1; ?>">
                <?php if ($index === 0 && $attraction['main_image'] == $image): ?>
                <span class="gallery-badge">Main</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Bookings -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-calendar-check"></i> Recent Bookings</h3>
        <a href="bookings.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($recentBookings)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No bookings yet</p>
        </div>
        <?php else: ?>
        <div class="booking-list">
            <?php foreach ($recentBookings as $booking): ?>
            <div class="booking-item">
                <div class="booking-info">
                    <div class="booking-ref">
                        <a href="booking-detail.php?id=<?php echo $booking['booking_id']; ?>">
                            #<?php echo $booking['booking_reference']; ?>
                        </a>
                    </div>
                    <div class="booking-guest">
                        <i class="bi bi-person"></i> <?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?>
                    </div>
                    <div class="booking-tier">
                        <i class="bi bi-layers"></i> <?php echo sanitize($booking['tier_name'] ?: 'Standard'); ?>
                        (<?php echo $booking['num_participants']; ?> participants)
                    </div>
                </div>
                <div class="booking-details">
                    <div class="booking-date">
                        <?php echo date('M d, Y', strtotime($booking['experience_date'])); ?>
                        <?php if ($booking['start_time']): ?> at <?php echo date('g:i A', strtotime($booking['start_time'])); ?><?php endif; ?>
                    </div>
                    <div class="booking-amount">
                        <?php echo formatPrice($booking['total_amount']); ?>
                    </div>
                    <div class="status-badge status-<?php echo $booking['status']; ?>" style="margin-top: 4px; padding: 2px 6px;">
                        <?php echo ucfirst($booking['status']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reviews -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-star"></i> Customer Reviews</h3>
        <a href="reviews.php?attraction_id=<?php echo $attraction['attraction_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($reviews)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-star" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No reviews yet</p>
        </div>
        <?php else: ?>
        <div class="review-list">
            <?php foreach ($reviews as $review): ?>
            <div class="review-item">
                <div class="review-header">
                    <div class="reviewer">
                        <div class="reviewer-avatar">
                            <?php if ($review['profile_image']): ?>
                            <img src="<?php echo getImageUrl($review['profile_image'], 'profile'); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                            <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="reviewer-name">
                            <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?>
                        </div>
                    </div>
                    <div class="review-rating">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $review['overall_rating'] ? '' : 'empty'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php if ($review['title']): ?>
                <div class="review-title"><?php echo sanitize($review['title']); ?></div>
                <?php endif; ?>
                <?php if ($review['comment']): ?>
                <div class="review-comment">
                    <?php echo sanitize(substr($review['comment'], 0, 200)); ?>
                    <?php if (strlen($review['comment']) > 200): ?>...<?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="review-date">
                    <?php echo timeAgo($review['created_at']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" onclick="closeModal()">
    <span class="modal-close">&times;</span>
    <div class="modal-content">
        <img id="modalImage" src="">
    </div>
</div>

<script>
function openModal(imageSrc) {
    document.getElementById('modalImage').src = imageSrc;
    document.getElementById('imageModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('imageModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Prevent modal close when clicking on image
document.querySelector('.modal-content')?.addEventListener('click', function(e) {
    e.stopPropagation();
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>