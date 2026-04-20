<?php
$pageTitle = 'Stay Details';
require_once 'includes/admin_header.php';

$stayId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$stayId) {
    header('Location: stays.php');
    exit;
}

$db = getDB();

// Get stay details
$stmt = $db->prepare("
    SELECT 
        s.*,
        l.name as location_name,
        l.latitude as location_lat,
        l.longitude as location_lng,
        u.user_id as owner_id,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        u.phone as owner_phone,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as total_rooms,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as active_rooms,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT AVG(overall_rating) FROM reviews r 
         WHERE r.stay_id = s.stay_id AND r.review_type = 'stay') as avg_rating,
        (SELECT COUNT(*) FROM reviews r 
         WHERE r.stay_id = s.stay_id AND r.review_type = 'stay') as review_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ?
");
$stmt->execute([$stayId]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: stays.php');
    exit;
}

// Get rooms
$stmt = $db->prepare("
    SELECT 
        r.*,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.stay_room_id = r.room_id AND b.status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         WHERE b.stay_room_id = r.room_id AND b.status IN ('confirmed', 'completed')) as room_revenue
    FROM stay_rooms r
    WHERE r.stay_id = ?
    ORDER BY r.room_name
");
$stmt->execute([$stayId]);
$rooms = $stmt->fetchAll();

// Get recent bookings
$stmt = $db->prepare("
    SELECT 
        b.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        sr.room_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    WHERE sr.stay_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$stayId]);
$recentBookings = $stmt->fetchAll();

// Get reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.profile_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.stay_id = ? AND r.review_type = 'stay'
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$stayId]);
$reviews = $stmt->fetchAll();

// Get images
$images = [];
if ($stay['main_image']) {
    $images[] = $stay['main_image'];
}
if ($stay['images']) {
    $galleryImages = json_decode($stay['images'], true);
    if (is_array($galleryImages)) {
        $images = array_merge($images, $galleryImages);
    }
}
$images = array_unique($images);

// Get amenities
$amenities = [];
if ($stay['amenities']) {
    $amenityKeys = json_decode($stay['amenities'], true);
    if (is_array($amenityKeys) && !empty($amenityKeys)) {
        $placeholders = implode(',', array_fill(0, count($amenityKeys), '?'));
        $stmt = $db->prepare("SELECT amenity_name, amenity_icon FROM amenities WHERE amenity_key IN ($placeholders)");
        $stmt->execute($amenityKeys);
        $amenities = $stmt->fetchAll();
    }
}
?>

<style>
/* Stay Detail Styles */
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

.stay-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.stay-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

.stay-location {
    color: var(--booking-text-light);
    font-size: 0.875rem;
    margin-bottom: 16px;
}

.stay-location i {
    margin-right: 4px;
}

/* Stats Grid */
.detail-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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
    font-size: 1.5rem;
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

/* Amenities */
.amenities-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.amenity-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: var(--booking-gray-light);
    border-radius: 20px;
    font-size: 0.6875rem;
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

/* Rooms Table */
.rooms-table {
    width: 100%;
    border-collapse: collapse;
}

.rooms-table th {
    text-align: left;
    padding: 12px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.rooms-table td {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
}

.rooms-table tr:hover td {
    background: var(--booking-gray-light);
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

.booking-room {
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

.action-btn.warning {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.action-btn.danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
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
    .stay-title {
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
    <a href="stays.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Stays
    </a>
    
    <div class="stay-title">
        <div>
            <h1><?php echo sanitize($stay['stay_name']); ?></h1>
            <div class="stay-location">
                <i class="bi bi-geo-alt"></i> <?php echo sanitize($stay['address']); ?>
                <?php if ($stay['city']): ?> • <?php echo sanitize($stay['city']); ?><?php endif; ?>
                <?php if ($stay['location_name']): ?> • <?php echo sanitize($stay['location_name']); ?><?php endif; ?>
            </div>
        </div>
        <div class="action-buttons">
            <a href="edit-stay.php?id=<?php echo $stay['stay_id']; ?>" class="action-btn primary">
                <i class="bi bi-pencil"></i> Edit Stay
            </a>
            <a href="rooms.php?stay_id=<?php echo $stay['stay_id']; ?>" class="action-btn secondary">
                <i class="bi bi-door-open"></i> Manage Rooms
            </a>
        </div>
    </div>
    
    <div style="display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
        <?php if ($stay['is_verified']): ?>
        <span class="status-badge status-verified">
            <i class="bi bi-shield-check"></i> Verified
        </span>
        <?php else: ?>
        <span class="status-badge status-pending">
            <i class="bi bi-clock"></i> Pending Verification
        </span>
        <?php endif; ?>
        
        <?php if ($stay['is_active']): ?>
        <span class="status-badge status-active">
            <i class="bi bi-check-circle"></i> Active
        </span>
        <?php else: ?>
        <span class="status-badge status-inactive">
            <i class="bi bi-x-circle"></i> Inactive
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Overview -->
<div class="detail-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stay['total_rooms']; ?></div>
        <div class="stat-label">Total Rooms</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $stay['active_rooms']; ?> active
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stay['total_bookings']); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stay['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stay['avg_rating'] ? number_format($stay['avg_rating'], 1) : 'N/A'; ?></div>
        <div class="stat-label">Avg Rating</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $stay['review_count']; ?> reviews
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo date('M d, Y', strtotime($stay['created_at'])); ?></div>
        <div class="stat-label">Listed Since</div>
    </div>
</div>

<!-- Information Grid -->
<div class="info-grid">
    <!-- Property Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-info-circle"></i> Property Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Stay Type</div>
                <div class="info-value" style="text-transform: capitalize;"><?php echo $stay['stay_type']; ?></div>
            </div>
            <?php if ($stay['star_rating'] > 0): ?>
            <div class="info-row">
                <div class="info-label">Star Rating</div>
                <div class="info-value">
                    <div class="rating-stars" style="display: inline-flex; gap: 2px;">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $stay['star_rating'] ? 'filled' : 'empty'; ?>" style="<?php echo $i <= $stay['star_rating'] ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Check-in Time</div>
                <div class="info-value"><?php echo date('h:i A', strtotime($stay['check_in_time'])); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Check-out Time</div>
                <div class="info-value"><?php echo date('h:i A', strtotime($stay['check_out_time'])); ?></div>
            </div>
            <?php if ($stay['description']): ?>
            <div class="info-row">
                <div class="info-label">Description</div>
                <div class="info-value">
                    <?php echo nl2br(sanitize(substr($stay['description'], 0, 300))); ?>
                    <?php if (strlen($stay['description']) > 300): ?>...<?php endif; ?>
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
                    <a href="users.php?view=<?php echo $stay['owner_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($stay['owner_first'] . ' ' . $stay['owner_last']); ?>
                    </a>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo sanitize($stay['owner_email']); ?></div>
            </div>
            <?php if ($stay['owner_phone']): ?>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo sanitize($stay['owner_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Amenities -->
<?php if (!empty($amenities)): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-grid-3x3-gap-fill"></i> Amenities & Features</h3>
    </div>
    <div class="info-body">
        <div class="amenities-list">
            <?php foreach ($amenities as $amenity): ?>
            <span class="amenity-badge">
                <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                <?php echo sanitize($amenity['amenity_name']); ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Image Gallery -->
<?php if (!empty($images)): ?>
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-images"></i> Image Gallery</h3>
    </div>
    <div class="info-body">
        <div class="gallery-grid">
            <?php foreach ($images as $index => $image): ?>
            <div class="gallery-item" onclick="openModal('<?php echo getImageUrl($image, 'stay'); ?>')">
                <img src="<?php echo getImageUrl($image, 'stay'); ?>" alt="Stay image <?php echo $index + 1; ?>">
                <?php if ($index === 0 && $stay['main_image'] == $image): ?>
                <span class="gallery-badge">Main</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Rooms Section -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-door-open"></i> Rooms & Suites</h3>
        <a href="rooms.php?stay_id=<?php echo $stay['stay_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            <i class="bi bi-plus-lg"></i> Manage Rooms
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($rooms)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-door-closed" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No rooms added yet</p>
            <a href="rooms.php?stay_id=<?php echo $stay['stay_id']; ?>" class="action-btn primary" style="margin-top: 12px; display: inline-block;">
                Add First Room
            </a>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="rooms-table">
                <thead>
                    <tr>
                        <th>Room Name</th>
                        <th>Max Guests</th>
                        <th>Base Price</th>
                        <th>Available Rooms</th>
                        <th>Bookings</th>
                        <th>Revenue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($room['room_name']); ?></strong>
                            <?php if ($room['description']): ?>
                            <div style="font-size: 0.625rem; color: var(--booking-text-light); margin-top: 2px;">
                                <?php echo sanitize(substr($room['description'], 0, 60)); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $room['max_guests']; ?></td>
                        <td><strong><?php echo formatPrice($room['base_price']); ?></strong><span style="font-size: 0.625rem;"> / night</span></td>
                        <td><?php echo $room['num_rooms_available']; ?></td>
                        <td><?php echo number_format($room['booking_count']); ?></td>
                        <td style="color: var(--booking-success);"><?php echo formatPrice($room['room_revenue']); ?></td>
                        <td>
                            <?php if ($room['is_active']): ?>
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

<!-- Recent Bookings -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-calendar-check"></i> Recent Bookings</h3>
        <a href="bookings.php?stay_id=<?php echo $stay['stay_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
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
                    <div class="booking-room">
                        <i class="bi bi-door-open"></i> <?php echo sanitize($booking['room_name']); ?>
                    </div>
                </div>
                <div class="booking-details">
                    <div class="booking-date">
                        <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
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
        <h3><i class="bi bi-star"></i> Guest Reviews</h3>
        <a href="reviews.php?stay_id=<?php echo $stay['stay_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
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