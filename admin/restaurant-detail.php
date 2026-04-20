<?php
$restaurantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$restaurantId) {
    header('Location: restaurants.php');
    exit;
}

$pageTitle = 'Restaurant Details';
require_once 'includes/admin_header.php';

$db = getDB();

// Get restaurant details
$stmt = $db->prepare("
    SELECT 
        r.*,
        s.stay_name as hotel_name,
        s.stay_id as hotel_id,
        s.star_rating as hotel_stars,
        s.address as hotel_address,
        l.name as location_name,
        (SELECT COUNT(*) FROM menu_items mi 
         LEFT JOIN menu_categories mc ON mi.category_id = mc.category_id 
         WHERE mc.restaurant_id = r.restaurant_id) as total_menu_items,
        (SELECT COUNT(*) FROM menu_categories WHERE restaurant_id = r.restaurant_id) as total_categories,
        (SELECT COUNT(*) FROM table_reservations tr 
         WHERE tr.restaurant_id = r.restaurant_id AND tr.status IN ('confirmed', 'completed')) as total_reservations,
        (SELECT COUNT(*) FROM table_reservations tr 
         WHERE tr.restaurant_id = r.restaurant_id AND tr.status = 'pending') as pending_reservations,
        (SELECT AVG(overall_rating) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant') as avg_rating,
        (SELECT COUNT(*) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant') as review_count,
        (SELECT COUNT(*) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant' AND rev.overall_rating >= 4) as positive_reviews,
        (SELECT COUNT(*) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant' AND rev.overall_rating <= 2) as negative_reviews
    FROM restaurants r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE r.restaurant_id = ?
");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    header('Location: restaurants.php');
    exit;
}

// Get menu categories with items
$stmt = $db->prepare("
    SELECT 
        mc.*,
        (SELECT COUNT(*) FROM menu_items WHERE category_id = mc.category_id AND is_available = 1) as items_count
    FROM menu_categories mc
    WHERE mc.restaurant_id = ?
    ORDER BY mc.display_order
");
$stmt->execute([$restaurantId]);
$categories = $stmt->fetchAll();

// Get menu items for each category
$menuItems = [];
foreach ($categories as $category) {
    $stmt = $db->prepare("
        SELECT mi.*,
            (SELECT COUNT(*) FROM menu_item_options WHERE item_id = mi.item_id) as options_count
        FROM menu_items mi
        WHERE mi.category_id = ?
        ORDER BY mi.display_order
    ");
    $stmt->execute([$category['category_id']]);
    $menuItems[$category['category_id']] = $stmt->fetchAll();
}

// Get recent reservations
$stmt = $db->prepare("
    SELECT 
        tr.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone
    FROM table_reservations tr
    LEFT JOIN users u ON tr.user_id = u.user_id
    WHERE tr.restaurant_id = ?
    ORDER BY tr.reservation_date DESC, tr.reservation_time DESC
    LIMIT 10
");
$stmt->execute([$restaurantId]);
$recentReservations = $stmt->fetchAll();

// Get reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.profile_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.restaurant_id = ? AND r.review_type = 'restaurant'
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$restaurantId]);
$reviews = $stmt->fetchAll();

// Get images
$images = [];
if ($restaurant['main_image']) {
    $images[] = $restaurant['main_image'];
}
// Get gallery images from restaurant_images table
$stmt = $db->prepare("
    SELECT image_path, caption, is_main
    FROM restaurant_images
    WHERE restaurant_id = ?
    ORDER BY sort_order
");
$stmt->execute([$restaurantId]);
$galleryImages = $stmt->fetchAll();
foreach ($galleryImages as $img) {
    $images[] = $img['image_path'];
}
$images = array_unique($images);

// Parse opening hours
$openingHours = $restaurant['opening_hours'] ? json_decode($restaurant['opening_hours'], true) : [];

// Rating distribution
$ratingDistribution = [];
$stmt = $db->prepare("
    SELECT 
        overall_rating,
        COUNT(*) as count
    FROM reviews
    WHERE restaurant_id = ? AND review_type = 'restaurant'
    GROUP BY overall_rating
    ORDER BY overall_rating DESC
");
$stmt->execute([$restaurantId]);
$dist = $stmt->fetchAll();
foreach ($dist as $d) {
    $ratingDistribution[$d['overall_rating']] = $d['count'];
}
?>

<style>
/* Restaurant Detail Styles */
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

.restaurant-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.restaurant-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

.restaurant-location {
    color: var(--booking-text-light);
    font-size: 0.875rem;
    margin-bottom: 16px;
}

.restaurant-location i {
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

.status-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Menu Section */
.menu-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    margin-bottom: 24px;
    overflow: hidden;
}

.menu-header {
    padding: 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.menu-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.menu-category {
    border-bottom: 1px solid var(--booking-border);
}

.menu-category:last-child {
    border-bottom: none;
}

.category-header {
    padding: 16px;
    background: var(--booking-gray-light);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.category-name {
    font-weight: 600;
    font-size: 0.875rem;
}

.category-badge {
    background: var(--booking-blue);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.625rem;
}

.category-items {
    padding: 0;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.category-items.open {
    max-height: 2000px;
    padding: 16px;
}

.menu-items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
}

.menu-item-card {
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    padding: 12px;
    transition: all var(--transition-fast);
}

.menu-item-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.menu-item-name {
    font-weight: 700;
    font-size: 0.8125rem;
    margin-bottom: 4px;
}

.menu-item-price {
    font-weight: 700;
    color: var(--booking-success);
    font-size: 0.75rem;
    margin-top: 8px;
}

.menu-item-description {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    margin-top: 4px;
}

.menu-item-tags {
    display: flex;
    gap: 4px;
    margin-top: 8px;
    flex-wrap: wrap;
}

.menu-tag {
    font-size: 0.5625rem;
    padding: 2px 6px;
    border-radius: 10px;
    background: var(--booking-white);
}

/* Reservation List */
.reservation-list {
    max-height: 400px;
    overflow-y: auto;
}

.reservation-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.reservation-item:hover {
    background: var(--booking-gray-light);
}

.reservation-info {
    flex: 1;
}

.reservation-code {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.reservation-guest {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.reservation-datetime {
    font-size: 0.625rem;
    color: var(--booking-text-lighter);
}

.reservation-details {
    text-align: right;
}

.reservation-guests {
    font-size: 0.6875rem;
    font-weight: 600;
}

.reservation-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    margin-top: 4px;
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

.review-comment {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    line-height: 1.4;
    margin-top: 8px;
}

.review-date {
    font-size: 0.5625rem;
    color: var(--booking-text-lighter);
    margin-top: 8px;
}

/* Rating Distribution */
.rating-distribution {
    margin-top: 16px;
}

.rating-bar-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.rating-stars-small {
    display: flex;
    gap: 2px;
    width: 80px;
}

.rating-stars-small i {
    font-size: 0.5625rem;
    color: #ffc107;
}

.rating-bar {
    flex: 1;
    height: 6px;
    background: var(--booking-border);
    border-radius: 3px;
    overflow: hidden;
}

.rating-bar-fill {
    height: 100%;
    background: #ffc107;
    border-radius: 3px;
}

.rating-count {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    width: 40px;
    text-align: right;
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
    .restaurant-title {
        flex-direction: column;
    }
    .menu-items-grid {
        grid-template-columns: 1fr;
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
    <a href="restaurants.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Restaurants
    </a>
    
    <div class="restaurant-title">
        <div>
            <h1><?php echo sanitize($restaurant['restaurant_name']); ?></h1>
            <div class="restaurant-location">
                <i class="bi bi-geo-alt"></i> 
                <?php echo sanitize($restaurant['hotel_name']); ?>
                <?php if ($restaurant['location_name']): ?> • <?php echo sanitize($restaurant['location_name']); ?><?php endif; ?>
                <?php if ($restaurant['hotel_address']): ?> • <?php echo sanitize($restaurant['hotel_address']); ?><?php endif; ?>
            </div>
        </div>
        <div class="action-buttons">
            <a href="edit-restaurant.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn primary">
                <i class="bi bi-pencil"></i> Edit Restaurant
            </a>
            <a href="menu.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn secondary">
                <i class="bi bi-list-ul"></i> Manage Menu
            </a>
        </div>
    </div>
    
    <div style="display: flex; gap: 12px; margin-top: 12px;">
        <span class="status-badge <?php echo $restaurant['is_active'] ? 'status-active' : 'status-inactive'; ?>">
            <i class="bi bi-<?php echo $restaurant['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
            <?php echo $restaurant['is_active'] ? 'Active' : 'Inactive'; ?>
        </span>
        <?php if ($restaurant['cuisine_type']): ?>
        <span class="status-badge" style="background: #e1f5fe; color: #0288d1;">
            <i class="bi bi-tag"></i> <?php echo sanitize($restaurant['cuisine_type']); ?>
        </span>
        <?php endif; ?>
        <?php if ($restaurant['dress_code']): ?>
        <span class="status-badge" style="background: #f3e5f5; color: #7b1fa2;">
            <i class="bi bi-person-standing"></i> <?php echo sanitize($restaurant['dress_code']); ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Overview -->
<div class="detail-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $restaurant['total_menu_items']; ?></div>
        <div class="stat-label">Menu Items</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $restaurant['total_categories']; ?> categories
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($restaurant['total_reservations']); ?></div>
        <div class="stat-label">Total Reservations</div>
        <?php if ($restaurant['pending_reservations'] > 0): ?>
        <div style="font-size: 0.5625rem; color: var(--booking-warning); margin-top: 4px;">
            <?php echo $restaurant['pending_reservations']; ?> pending
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $restaurant['avg_rating'] ? number_format($restaurant['avg_rating'], 1) : 'N/A'; ?></div>
        <div class="stat-label">Avg Rating</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $restaurant['review_count']; ?> reviews
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $restaurant['positive_reviews']; ?></div>
        <div class="stat-label">Positive Reviews</div>
        <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: 4px;">
            <?php echo $restaurant['negative_reviews']; ?> negative
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $restaurant['seating_capacity'] ?: '—'; ?></div>
        <div class="stat-label">Seating Capacity</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $restaurant['accepts_reservations'] ? 'Yes' : 'No'; ?></div>
        <div class="stat-label">Reservations</div>
    </div>
</div>

<!-- Information Grid -->
<div class="info-grid">
    <!-- Restaurant Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-info-circle"></i> Restaurant Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Hotel</div>
                <div class="info-value">
                    <a href="stay-detail.php?id=<?php echo $restaurant['hotel_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($restaurant['hotel_name']); ?>
                    </a>
                    <?php if ($restaurant['hotel_stars'] > 0): ?>
                    <span class="hotel-stars" style="margin-left: 8px;">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $restaurant['hotel_stars'] ? '' : 'empty'; ?>" style="font-size: 0.625rem;"></i>
                        <?php endfor; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Cuisine</div>
                <div class="info-value"><?php echo sanitize($restaurant['cuisine_type'] ?: 'Not specified'); ?></div>
            </div>
            <?php if ($restaurant['description']): ?>
            <div class="info-row">
                <div class="info-label">Description</div>
                <div class="info-value"><?php echo nl2br(sanitize(substr($restaurant['description'], 0, 300))); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Dress Code</div>
                <div class="info-value"><?php echo sanitize($restaurant['dress_code'] ?: 'Casual'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Features</div>
                <div class="info-value">
                    <?php if ($restaurant['has_outdoor_seating']): ?>
                    <span class="status-badge" style="background: #e6f4ea; color: var(--booking-success); margin-right: 8px;">
                        <i class="bi bi-tree"></i> Outdoor Seating
                    </span>
                    <?php endif; ?>
                    <?php if ($restaurant['has_private_dining']): ?>
                    <span class="status-badge" style="background: #e6f4ea; color: var(--booking-success);">
                        <i class="bi bi-door-closed"></i> Private Dining
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($restaurant['phone_extension']): ?>
            <div class="info-row">
                <div class="info-label">Phone Extension</div>
                <div class="info-value"><?php echo sanitize($restaurant['phone_extension']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Opening Hours -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-clock"></i> Opening Hours</h3>
        </div>
        <div class="info-body">
            <?php if (empty($openingHours)): ?>
            <div class="info-value" style="color: var(--booking-text-light);">No opening hours specified</div>
            <?php else: ?>
            <?php if (isset($openingHours['breakfast']) && $openingHours['breakfast']): ?>
            <div class="info-row">
                <div class="info-label">Breakfast</div>
                <div class="info-value"><?php echo sanitize($openingHours['breakfast']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($openingHours['lunch']) && $openingHours['lunch']): ?>
            <div class="info-row">
                <div class="info-label">Lunch</div>
                <div class="info-value"><?php echo sanitize($openingHours['lunch']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($openingHours['dinner']) && $openingHours['dinner']): ?>
            <div class="info-row">
                <div class="info-label">Dinner</div>
                <div class="info-value"><?php echo sanitize($openingHours['dinner']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (isset($openingHours['continuous']) && $openingHours['continuous']): ?>
            <div class="info-row">
                <div class="info-label">Continuous Service</div>
                <div class="info-value"><?php echo sanitize($openingHours['continuous']); ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Menu Section -->
<div class="menu-section">
    <div class="menu-header">
        <h3><i class="bi bi-list-ul"></i> Menu</h3>
        <a href="menu.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            <i class="bi bi-pencil"></i> Edit Menu
        </a>
    </div>
    
    <?php if (empty($categories)): ?>
    <div style="text-align: center; padding: 40px;">
        <i class="bi bi-list-ul" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 12px; color: var(--booking-text-light);">No menu categories added yet</p>
        <a href="menu.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn primary" style="margin-top: 12px; display: inline-block;">
            Add Menu Items
        </a>
    </div>
    <?php else: ?>
    <?php foreach ($categories as $index => $category): 
        $items = $menuItems[$category['category_id']] ?? [];
    ?>
    <div class="menu-category">
        <div class="category-header" onclick="toggleCategory(this)">
            <span class="category-name"><?php echo sanitize($category['category_name']); ?></span>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span class="category-badge"><?php echo count($items); ?> items</span>
                <i class="bi bi-chevron-down" style="transition: transform 0.3s;"></i>
            </div>
        </div>
        <div class="category-items" id="category-<?php echo $index; ?>">
            <?php if (empty($items)): ?>
            <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                No items in this category
            </div>
            <?php else: ?>
            <div class="menu-items-grid">
                <?php foreach ($items as $item): ?>
                <div class="menu-item-card">
                    <div class="menu-item-name"><?php echo sanitize($item['item_name']); ?></div>
                    <?php if ($item['description']): ?>
                    <div class="menu-item-description"><?php echo sanitize(substr($item['description'], 0, 80)); ?></div>
                    <?php endif; ?>
                    <div class="menu-item-price"><?php echo formatPrice($item['price']); ?></div>
                    <?php if ($item['is_signature'] || $item['is_vegetarian'] || $item['is_vegan'] || $item['is_gluten_free']): ?>
                    <div class="menu-item-tags">
                        <?php if ($item['is_signature']): ?>
                        <span class="menu-tag" style="background: #ffc10720; color: #ff8c00;">⭐ Signature</span>
                        <?php endif; ?>
                        <?php if ($item['is_vegetarian']): ?>
                        <span class="menu-tag" style="background: #4caf5020; color: #4caf50;">🌱 Vegetarian</span>
                        <?php endif; ?>
                        <?php if ($item['is_vegan']): ?>
                        <span class="menu-tag" style="background: #8bc34a20; color: #689f38;">🌿 Vegan</span>
                        <?php endif; ?>
                        <?php if ($item['is_gluten_free']): ?>
                        <span class="menu-tag" style="background: #ff980020; color: #f57c00;">🚫 Gluten-Free</span>
                        <?php endif; ?>
                        <?php if ($item['is_spicy']): ?>
                        <span class="menu-tag" style="background: #f4433620; color: #f44336;">🌶️ Spicy</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
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
            <div class="gallery-item" onclick="openModal('<?php echo getImageUrl($image, 'restaurant'); ?>')">
                <img src="<?php echo getImageUrl($image, 'restaurant'); ?>" alt="Restaurant image <?php echo $index + 1; ?>">
                <?php if ($index === 0 && $restaurant['main_image'] == $image): ?>
                <span class="gallery-badge">Main</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Reservations -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-calendar-check"></i> Recent Reservations</h3>
        <a href="reservations.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($recentReservations)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No reservations yet</p>
        </div>
        <?php else: ?>
        <div class="reservation-list">
            <?php foreach ($recentReservations as $reservation): ?>
            <div class="reservation-item">
                <div class="reservation-info">
                    <div class="reservation-code">
                        <i class="bi bi-qr-code"></i> <?php echo sanitize($reservation['confirmation_code']); ?>
                    </div>
                    <div class="reservation-guest">
                        <i class="bi bi-person"></i> <?php echo sanitize($reservation['first_name'] . ' ' . $reservation['last_name']); ?>
                    </div>
                    <div class="reservation-datetime">
                        <i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($reservation['reservation_date'])); ?>
                        at <?php echo date('g:i A', strtotime($reservation['reservation_time'])); ?>
                    </div>
                </div>
                <div class="reservation-details">
                    <div class="reservation-guests">
                        <i class="bi bi-people"></i> <?php echo $reservation['guest_count']; ?> guests
                    </div>
                    <div class="reservation-status" style="background: <?php 
                        echo $reservation['status'] == 'confirmed' ? '#e6f4ea' : ($reservation['status'] == 'pending' ? '#fff4e6' : '#fce8e8');
                    ?>; color: <?php 
                        echo $reservation['status'] == 'confirmed' ? '#008009' : ($reservation['status'] == 'pending' ? '#ff8c00' : '#e21111');
                    ?>;">
                        <i class="bi bi-<?php 
                            echo $reservation['status'] == 'confirmed' ? 'check-circle' : ($reservation['status'] == 'pending' ? 'clock' : 'x-circle');
                        ?>"></i>
                        <?php echo ucfirst($reservation['status']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reviews Section -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-star"></i> Customer Reviews</h3>
        <a href="reviews.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body">
        <?php if ($restaurant['review_count'] > 0): ?>
        <!-- Rating Summary -->
        <div style="display: flex; gap: 24px; margin-bottom: 24px; flex-wrap: wrap;">
            <div style="text-align: center;">
                <div style="font-size: 2.5rem; font-weight: 700; color: var(--booking-warning);">
                    <?php echo number_format($restaurant['avg_rating'], 1); ?>
                </div>
                <div class="rating-stars" style="justify-content: center;">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= round($restaurant['avg_rating']) ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <div style="font-size: 0.625rem; color: var(--booking-text-light); margin-top: 4px;">
                    Based on <?php echo $restaurant['review_count']; ?> reviews
                </div>
            </div>
            
            <div style="flex: 1;">
                <div class="rating-distribution">
                    <?php for($rating = 5; $rating >= 1; $rating--): ?>
                    <?php $count = $ratingDistribution[$rating] ?? 0; ?>
                    <?php $percentage = $restaurant['review_count'] > 0 ? ($count / $restaurant['review_count']) * 100 : 0; ?>
                    <div class="rating-bar-item">
                        <div class="rating-stars-small">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star-fill <?php echo $i <= $rating ? '' : 'empty'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                        </div>
                        <div class="rating-count"><?php echo $count; ?></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Review List -->
        <div class="review-list" style="max-height: 400px;">
            <?php if (empty($reviews)): ?>
            <div style="text-align: center; padding: 20px;">
                <i class="bi bi-star" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 12px; color: var(--booking-text-light);">No reviews yet</p>
            </div>
            <?php else: ?>
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
                <div style="font-weight: 600; font-size: 0.75rem; margin-top: 4px;"><?php echo sanitize($review['title']); ?></div>
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
            <?php endif; ?>
        </div>
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
// Toggle category visibility
function toggleCategory(header) {
    const categoryDiv = header.nextElementSibling;
    const icon = header.querySelector('.bi-chevron-down');
    
    categoryDiv.classList.toggle('open');
    icon.style.transform = categoryDiv.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0)';
}

// Image modal
function openModal(imageSrc) {
    document.getElementById('modalImage').src = imageSrc;
    document.getElementById('imageModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('imageModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Prevent modal close when clicking on image
document.querySelector('.modal-content')?.addEventListener('click', function(e) {
    e.stopPropagation();
});

// Open first category by default
document.querySelectorAll('.category-header').forEach((header, index) => {
    if (index === 0) {
        const categoryDiv = header.nextElementSibling;
        const icon = header.querySelector('.bi-chevron-down');
        categoryDiv.classList.add('open');
        icon.style.transform = 'rotate(180deg)';
    }
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>