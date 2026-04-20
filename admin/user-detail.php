<?php
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$userId) {
    header('Location: users.php');
    exit;
}

$pageTitle = 'User Details';
require_once 'includes/admin_header.php';

$db = getDB();

// Get user details
$stmt = $db->prepare("
    SELECT 
        u.*,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id) as total_bookings,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id AND status IN ('confirmed', 'completed')) as confirmed_bookings,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id AND status = 'cancelled') as cancelled_bookings,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE user_id = u.user_id AND status IN ('confirmed', 'completed')) as total_spent,
        (SELECT AVG(overall_rating) FROM reviews WHERE user_id = u.user_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE user_id = u.user_id) as review_count,
        (SELECT COUNT(*) FROM reviews WHERE user_id = u.user_id AND overall_rating >= 4) as positive_reviews,
        (SELECT COUNT(*) FROM reviews WHERE user_id = u.user_id AND overall_rating <= 2) as negative_reviews
    FROM users u
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

// Get user's bookings
$stmt = $db->prepare("
    SELECT 
        b.*,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN '🏨'
            WHEN b.booking_type = 'car_rental' THEN '🚗'
            ELSE '🎟️'
        END as item_icon,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.room_name
            WHEN b.booking_type = 'car_rental' THEN cf.license_plate
            ELSE t.tier_name
        END as item_detail
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

// Get user's reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        CASE 
            WHEN r.review_type = 'stay' THEN s.stay_name
            WHEN r.review_type = 'car_rental' THEN cr.company_name
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN r.review_type = 'stay' THEN '🏨'
            WHEN r.review_type = 'car_rental' THEN '🚗'
            ELSE '🎟️'
        END as item_icon
    FROM reviews r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
    LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$reviews = $stmt->fetchAll();

// Get user's activity log
$stmt = $db->prepare("
    SELECT * FROM activity_logs 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 20
");
$stmt->execute([$userId]);
$activities = $stmt->fetchAll();

// Get user's favorites
$stmt = $db->prepare("
    SELECT 
        favorite_type,
        CASE 
            WHEN favorite_type = 'stay' THEN (SELECT stay_name FROM stays WHERE stay_id = f.stay_id)
            WHEN favorite_type = 'car_rental' THEN (SELECT company_name FROM car_rentals WHERE rental_id = f.rental_id)
            ELSE (SELECT attraction_name FROM attractions WHERE attraction_id = f.attraction_id)
        END as item_name,
        CASE 
            WHEN favorite_type = 'stay' THEN '🏨'
            WHEN favorite_type = 'car_rental' THEN '🚗'
            ELSE '🎟️'
        END as item_icon,
        f.created_at
    FROM favorites f
    WHERE f.user_id = ?
    ORDER BY f.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$favorites = $stmt->fetchAll();

// Calculate user lifetime value
$lifetimeValue = $user['total_spent'];
$avgBookingValue = $user['confirmed_bookings'] > 0 ? $lifetimeValue / $user['confirmed_bookings'] : 0;

// Get user type badge class
$userTypeClass = $user['user_type'] == 'tourist' ? 'badge-tourist' : ($user['user_type'] == 'business_owner' ? 'badge-business' : 'badge-admin');
$userTypeLabel = $user['user_type'] == 'tourist' ? 'Guest' : ($user['user_type'] == 'business_owner' ? 'Partner' : 'Administrator');

$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
?>

<style>
/* User Detail Styles */
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

/* Profile Header */
.profile-header {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
    margin-bottom: 24px;
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 700;
    color: white;
    flex-shrink: 0;
}

.profile-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.profile-info {
    flex: 1;
}

.profile-name {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.profile-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.profile-details {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.detail-item i {
    width: 20px;
    color: var(--booking-blue);
}

.profile-actions {
    display: flex;
    gap: 12px;
    margin-top: 16px;
}

/* Stats Grid */
.stats-grid {
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

/* Section Cards */
.section-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    margin-bottom: 24px;
    overflow: hidden;
}

.section-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.section-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-body {
    padding: 0;
}

/* Tables */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.data-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.data-table tr:hover td {
    background: var(--booking-gray-light);
}

/* Booking Items */
.booking-item, .review-item, .activity-item, .favorite-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.booking-item:hover, .review-item:hover, .activity-item:hover, .favorite-item:hover {
    background: var(--booking-gray-light);
}

.booking-info, .review-info, .activity-info, .favorite-info {
    flex: 1;
}

.booking-title, .review-title, .activity-title, .favorite-title {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 4px;
}

.booking-meta, .review-meta, .activity-meta, .favorite-meta {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.booking-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.status-confirmed {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-cancelled {
    background: #fce8e8;
    color: var(--booking-danger);
}

.status-completed {
    background: #e1f5fe;
    color: #0288d1;
}

.booking-amount {
    font-weight: 700;
    color: var(--booking-success);
    font-size: 0.75rem;
}

.review-rating {
    display: flex;
    gap: 2px;
    margin-bottom: 4px;
}

.review-rating i {
    font-size: 0.6875rem;
    color: #ffc107;
}

.review-rating i.empty {
    color: #e0e0e0;
}

/* Badges */
.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.badge-tourist {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.badge-business {
    background: rgba(147,51,234,0.1);
    color: #9333ea;
}

.badge-admin {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.badge-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.badge-inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

.badge-verified {
    background: #e6f4ea;
    color: var(--booking-success);
}

.badge-unverified {
    background: #fff4e6;
    color: var(--booking-warning);
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
    cursor: pointer;
    border: none;
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

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px;
    color: var(--booking-text-light);
}

.empty-state i {
    font-size: 2rem;
    margin-bottom: 12px;
    color: var(--booking-text-lighter);
}

/* Responsive */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    .profile-avatar {
        margin: 0 auto;
    }
    .profile-details {
        justify-content: center;
    }
    .detail-item {
        justify-content: center;
    }
    .profile-actions {
        justify-content: center;
    }
    .data-table {
        min-width: 600px;
    }
}
</style>

<div class="detail-header">
    <a href="users.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Users
    </a>
</div>

<!-- Profile Header -->
<div class="profile-header">
    <div class="profile-avatar">
        <?php if ($user['profile_image']): ?>
        <img src="<?php echo getImageUrl($user['profile_image'], 'profile'); ?>" alt="<?php echo sanitize($user['first_name']); ?>">
        <?php else: ?>
        <?php echo $initials; ?>
        <?php endif; ?>
    </div>
    
    <div class="profile-info">
        <div class="profile-name">
            <?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?>
            <span class="user-badge <?php echo $userTypeClass; ?>">
                <i class="bi bi-<?php echo $user['user_type'] == 'tourist' ? 'person' : ($user['user_type'] == 'business_owner' ? 'building' : 'shield'); ?>"></i>
                <?php echo $userTypeLabel; ?>
            </span>
        </div>
        
        <div class="profile-badges">
            <span class="user-badge <?php echo $user['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                <i class="bi bi-<?php echo $user['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
            <span class="user-badge <?php echo $user['is_verified'] ? 'badge-verified' : 'badge-unverified'; ?>">
                <i class="bi bi-<?php echo $user['is_verified'] ? 'shield-check' : 'clock'; ?>"></i>
                <?php echo $user['is_verified'] ? 'Verified' : 'Unverified'; ?>
            </span>
            <?php if ($user['avg_rating'] > 0): ?>
            <span class="user-badge" style="background: rgba(255,193,7,0.1); color: #ffc107;">
                <i class="bi bi-star-fill"></i>
                <?php echo number_format($user['avg_rating'], 1); ?> avg rating
            </span>
            <?php endif; ?>
        </div>
        
        <div class="profile-details">
            <div class="detail-item">
                <i class="bi bi-envelope"></i>
                <span><?php echo sanitize($user['email']); ?></span>
            </div>
            <?php if ($user['phone']): ?>
            <div class="detail-item">
                <i class="bi bi-telephone"></i>
                <span><?php echo sanitize($user['phone']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($user['date_of_birth']): ?>
            <div class="detail-item">
                <i class="bi bi-calendar-heart"></i>
                <span>Born: <?php echo date('M d, Y', strtotime($user['date_of_birth'])); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($user['nationality']): ?>
            <div class="detail-item">
                <i class="bi bi-flag"></i>
                <span><?php echo sanitize($user['nationality']); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-item">
                <i class="bi bi-calendar3"></i>
                <span>Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
            </div>
            <div class="detail-item">
                <i class="bi bi-clock"></i>
                <span>Last active: <?php echo timeAgo($user['updated_at']); ?></span>
            </div>
            <?php if ($user['preferred_language']): ?>
            <div class="detail-item">
                <i class="bi bi-translate"></i>
                <span>Language: <?php echo strtoupper($user['preferred_language']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($user['preferred_currency']): ?>
            <div class="detail-item">
                <i class="bi bi-cash-stack"></i>
                <span>Currency: <?php echo $user['preferred_currency']; ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="profile-actions">
            <a href="edit-user.php?id=<?php echo $userId; ?>" class="action-btn primary">
                <i class="bi bi-pencil"></i> Edit Profile
            </a>
            <?php if ($user['is_active']): ?>
            <a href="?action=deactivate&id=<?php echo $userId; ?>" class="action-btn warning" onclick="return confirm('Deactivate this user?')">
                <i class="bi bi-eye-slash"></i> Deactivate
            </a>
            <?php else: ?>
            <a href="?action=activate&id=<?php echo $userId; ?>" class="action-btn primary" onclick="return confirm('Activate this user?')">
                <i class="bi bi-eye"></i> Activate
            </a>
            <?php endif; ?>
            <?php if (!$user['is_verified']): ?>
            <a href="?action=verify&id=<?php echo $userId; ?>" class="action-btn primary" onclick="return confirm('Verify this user?')">
                <i class="bi bi-shield-check"></i> Verify
            </a>
            <?php endif; ?>
            <?php if ($user['user_type'] != 'admin'): ?>
            <a href="?action=make_admin&id=<?php echo $userId; ?>" class="action-btn secondary" onclick="return confirm('Make this user an admin?')">
                <i class="bi bi-shield"></i> Make Admin
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($user['total_bookings']); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($user['confirmed_bookings']); ?></div>
        <div class="stat-label">Confirmed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($user['cancelled_bookings']); ?></div>
        <div class="stat-label">Cancelled</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($user['total_spent']); ?></div>
        <div class="stat-label">Total Spent</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($avgBookingValue); ?></div>
        <div class="stat-label">Avg. Booking</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($user['review_count']); ?></div>
        <div class="stat-label">Reviews</div>
    </div>
</div>

<!-- Recent Bookings -->
<div class="section-card">
    <div class="section-header">
        <h3><i class="bi bi-calendar-check"></i> Recent Bookings</h3>
        <a href="bookings.php?user=<?php echo $userId; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="section-body">
        <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>No bookings yet</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Item</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                <tr>
                    <td>
                        <a href="booking-detail.php?id=<?php echo $booking['booking_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                            #<?php echo $booking['booking_reference']; ?>
                        </a>
                    </td>
                    <td>
                        <div><?php echo $booking['item_icon']; ?> <?php echo sanitize($booking['item_name']); ?></div>
                        <?php if ($booking['item_detail']): ?>
                        <small style="font-size: 0.5625rem; color: var(--booking-text-light);"><?php echo sanitize($booking['item_detail']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($booking['created_at'])); ?></td>
                    <td class="booking-amount"><?php echo formatPrice($booking['total_amount']); ?></td>
                    <td>
                        <span class="booking-status status-<?php echo $booking['status']; ?>">
                            <i class="bi bi-<?php echo $booking['status'] == 'confirmed' ? 'check-circle' : ($booking['status'] == 'pending' ? 'clock' : 'x-circle'); ?>"></i>
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- User Reviews -->
<div class="section-card">
    <div class="section-header">
        <h3><i class="bi bi-star"></i> User Reviews</h3>
        <a href="reviews.php?user=<?php echo $userId; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="section-body">
        <?php if (empty($reviews)): ?>
        <div class="empty-state">
            <i class="bi bi-star"></i>
            <p>No reviews yet</p>
        </div>
        <?php else: ?>
        <?php foreach ($reviews as $review): ?>
        <div class="review-item">
            <div class="review-info">
                <div class="review-title">
                    <?php echo $review['item_icon']; ?> <?php echo sanitize($review['item_name']); ?>
                    <?php if ($review['title']): ?> - <?php echo sanitize($review['title']); ?><?php endif; ?>
                </div>
                <div class="review-rating">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= $review['overall_rating'] ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <?php if ($review['comment']): ?>
                <div class="review-meta">
                    <span><?php echo sanitize(substr($review['comment'], 0, 150)); ?><?php echo strlen($review['comment']) > 150 ? '...' : ''; ?></span>
                </div>
                <?php endif; ?>
                <div class="review-meta">
                    <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                </div>
            </div>
            <div>
                <a href="review-detail.php?id=<?php echo $review['review_id']; ?>" class="action-btn secondary" style="padding: 4px 12px;">
                    View Details
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Favorites -->
<?php if (!empty($favorites)): ?>
<div class="section-card">
    <div class="section-header">
        <h3><i class="bi bi-heart"></i> Favorites</h3>
    </div>
    <div class="section-body">
        <?php foreach ($favorites as $favorite): ?>
        <div class="favorite-item">
            <div class="favorite-info">
                <div class="favorite-title">
                    <?php echo $favorite['item_icon']; ?> <?php echo sanitize($favorite['item_name']); ?>
                </div>
                <div class="favorite-meta">
                    <span><i class="bi bi-calendar3"></i> Saved on <?php echo date('M d, Y', strtotime($favorite['created_at'])); ?></span>
                </div>
            </div>
            <div class="favorite-type">
                <span class="user-badge" style="background: var(--booking-gray-light);">
                    <?php echo ucfirst(str_replace('_', ' ', $favorite['favorite_type'])); ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Activity Log -->
<div class="section-card">
    <div class="section-header">
        <h3><i class="bi bi-clock-history"></i> Recent Activity</h3>
    </div>
    <div class="section-body">
        <?php if (empty($activities)): ?>
        <div class="empty-state">
            <i class="bi bi-clock"></i>
            <p>No activity recorded</p>
        </div>
        <?php else: ?>
        <?php foreach ($activities as $activity): ?>
        <div class="activity-item">
            <div class="activity-info">
                <div class="activity-title">
                    <strong><?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?></strong>
                    <?php if ($activity['entity_type']): ?>
                    on <?php echo ucfirst($activity['entity_type']); ?>
                    <?php endif; ?>
                </div>
                <?php if ($activity['details']): 
                    $details = json_decode($activity['details'], true);
                ?>
                <div class="activity-meta">
                    <?php if (isset($details['amount'])): ?>
                    <span><i class="bi bi-cash-stack"></i> <?php echo formatPrice($details['amount']); ?></span>
                    <?php endif; ?>
                    <?php if (isset($details['nights'])): ?>
                    <span><i class="bi bi-moon"></i> <?php echo $details['nights']; ?> nights</span>
                    <?php endif; ?>
                    <?php if (isset($details['room_id'])): ?>
                    <span><i class="bi bi-door-open"></i> Room #<?php echo $details['room_id']; ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="activity-meta">
                    <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></span>
                    <?php if ($activity['ip_address']): ?>
                    <span><i class="bi bi-wifi"></i> IP: <?php echo $activity['ip_address']; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Handle action confirmations
document.querySelectorAll('.action-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (this.hasAttribute('onclick')) return;
        if (this.innerText.includes('Deactivate') || this.innerText.includes('Delete') || this.innerText.includes('Remove')) {
            if (!confirm('Are you sure you want to perform this action?')) {
                e.preventDefault();
            }
        }
    });
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>