<?php
$pageTitle = 'Restaurants Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$restaurantId = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Toggle active status
if ($action === 'toggle_active' && $restaurantId > 0) {
    $stmt = $db->prepare("UPDATE restaurants SET is_active = NOT is_active, updated_at = NOW() WHERE restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    $_SESSION['success'] = "Restaurant status updated successfully";
    header('Location: restaurants.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_restaurants']) && is_array($_POST['selected_restaurants'])) {
    $selectedIds = array_map('intval', $_POST['selected_restaurants']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'activate') {
        $stmt = $db->prepare("UPDATE restaurants SET is_active = 1, updated_at = NOW() WHERE restaurant_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " restaurants activated successfully";
    } elseif ($bulkAction === 'deactivate') {
        $stmt = $db->prepare("UPDATE restaurants SET is_active = 0, updated_at = NOW() WHERE restaurant_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " restaurants deactivated successfully";
    }
    header('Location: restaurants.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$stayId = isset($_GET['stay_id']) ? intval($_GET['stay_id']) : 0;
$cuisine = isset($_GET['cuisine']) ? sanitize($_GET['cuisine']) : 'all';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        r.*,
        s.stay_name as hotel_name,
        s.stay_id as hotel_id,
        s.star_rating as hotel_stars,
        l.name as location_name,
        (SELECT COUNT(*) FROM menu_items mi 
         LEFT JOIN menu_categories mc ON mi.category_id = mc.category_id 
         WHERE mc.restaurant_id = r.restaurant_id) as menu_items_count,
        (SELECT COUNT(*) FROM table_reservations tr 
         WHERE tr.restaurant_id = r.restaurant_id AND tr.status IN ('confirmed', 'completed')) as total_reservations,
        (SELECT AVG(overall_rating) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant') as avg_rating,
        (SELECT COUNT(*) FROM reviews rev 
         WHERE rev.restaurant_id = r.restaurant_id AND rev.review_type = 'restaurant') as review_count
    FROM restaurants r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (r.restaurant_name LIKE ? OR r.cuisine_type LIKE ? OR s.stay_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status === 'active') {
    $sql .= " AND r.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND r.is_active = 0";
}

if ($stayId > 0) {
    $sql .= " AND r.stay_id = ?";
    $params[] = $stayId;
}

if ($cuisine !== 'all') {
    $sql .= " AND r.cuisine_type LIKE ?";
    $params[] = "%$cuisine%";
}

// Sorting
switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY r.restaurant_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY r.restaurant_name DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY r.created_at ASC";
        break;
    case 'reservations_desc':
        $sql .= " ORDER BY total_reservations DESC";
        break;
    case 'rating_desc':
        $sql .= " ORDER BY avg_rating DESC NULLS LAST";
        break;
    default:
        $sql .= " ORDER BY r.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$restaurants = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM restaurants r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (r.restaurant_name LIKE ? OR r.cuisine_type LIKE ? OR s.stay_name LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($status === 'active') {
    $countSql .= " AND r.is_active = 1";
} elseif ($status === 'inactive') {
    $countSql .= " AND r.is_active = 0";
}
if ($stayId > 0) {
    $countSql .= " AND r.stay_id = ?";
    $countParams[] = $stayId;
}
if ($cuisine !== 'all') {
    $countSql .= " AND r.cuisine_type LIKE ?";
    $countParams[] = "%$cuisine%";
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalRestaurants = $stmt->fetchColumn();
$totalPages = ceil($totalRestaurants / $perPage);

// Get filter options
$stmt = $db->query("SELECT stay_id, stay_name FROM stays WHERE is_active = 1 ORDER BY stay_name");
$hotels = $stmt->fetchAll();

// Get cuisine types for filter
$stmt = $db->query("SELECT DISTINCT cuisine_type FROM restaurants WHERE cuisine_type IS NOT NULL AND cuisine_type != '' ORDER BY cuisine_type");
$cuisineTypes = $stmt->fetchAll();

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        (SELECT COUNT(*) FROM menu_items mi 
         LEFT JOIN menu_categories mc ON mi.category_id = mc.category_id) as total_menu_items,
        (SELECT COUNT(*) FROM table_reservations WHERE status IN ('confirmed', 'completed')) as total_reservations,
        (SELECT AVG(overall_rating) FROM reviews WHERE review_type = 'restaurant') as avg_rating
    FROM restaurants
")->fetch();

// Get top rated restaurants
$topRated = $db->query("
    SELECT 
        r.restaurant_name,
        r.restaurant_id,
        AVG(rev.overall_rating) as rating,
        COUNT(rev.review_id) as review_count
    FROM restaurants r
    LEFT JOIN reviews rev ON r.restaurant_id = rev.restaurant_id AND rev.review_type = 'restaurant'
    WHERE rev.overall_rating IS NOT NULL
    GROUP BY r.restaurant_id
    HAVING rating >= 4
    ORDER BY rating DESC
    LIMIT 5
")->fetchAll();

// Get cuisine distribution
$cuisineDistribution = $db->query("
    SELECT 
        cuisine_type,
        COUNT(*) as count
    FROM restaurants
    WHERE cuisine_type IS NOT NULL AND cuisine_type != ''
    GROUP BY cuisine_type
    ORDER BY count DESC
    LIMIT 8
")->fetchAll();
?>

<style>
/* Restaurants Management Styles */
.restaurants-header {
    margin-bottom: 24px;
}

/* Stats Cards */
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
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    font-size: 1.125rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }

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

/* Filter Section */
.filter-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 140px;
}

.filter-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    background: var(--booking-white);
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 20px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.filter-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.reset-btn:hover {
    background: var(--booking-gray-dark);
}

/* Top Rated & Cuisine Sections */
.insight-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.insight-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.insight-header {
    padding: 12px 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.insight-header h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.insight-body {
    padding: 16px;
}

.top-rated-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.top-rated-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: all var(--transition-fast);
    cursor: pointer;
}

.top-rated-item:hover {
    background: var(--booking-gray-light);
}

.rating-stars {
    display: flex;
    gap: 2px;
}

.rating-stars i {
    font-size: 0.6875rem;
    color: #ffc107;
}

.rating-stars i.empty {
    color: #e0e0e0;
}

.rating-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.cuisine-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.cuisine-pill {
    background: var(--booking-gray-light);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.6875rem;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.cuisine-pill:hover {
    background: var(--booking-blue);
    color: white;
}

.cuisine-pill .count {
    background: rgba(0,0,0,0.1);
    padding: 0 4px;
    border-radius: 10px;
    margin-left: 4px;
}

/* Restaurants Grid */
.restaurants-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.restaurant-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
    position: relative;
}

.restaurant-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-image {
    position: relative;
    height: 180px;
    overflow: hidden;
    background: var(--booking-gray-light);
}

.card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.restaurant-card:hover .card-image img {
    transform: scale(1.05);
}

.image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: linear-gradient(135deg, var(--booking-gray-light) 0%, var(--booking-white) 100%);
}

.image-placeholder i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
}

.status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    color: white;
}

.status-active {
    background: rgba(0,128,9,0.9);
}

.status-inactive {
    background: rgba(226,17,17,0.9);
}

.card-content {
    padding: 16px;
}

.restaurant-name {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--booking-text);
}

.restaurant-name a {
    color: var(--booking-text);
    text-decoration: none;
}

.restaurant-name a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.hotel-info {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.hotel-stars {
    display: inline-flex;
    gap: 2px;
}

.hotel-stars i {
    font-size: 0.5625rem;
    color: #ffc107;
}

.cuisine-type {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: var(--booking-gray-light);
    border-radius: 12px;
    font-size: 0.625rem;
}

.rating {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.rating-stars {
    display: flex;
    gap: 2px;
}

.rating-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.review-count {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.restaurant-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.detail-item i {
    font-size: 0.75rem;
    color: var(--booking-blue);
}

.detail-item strong {
    color: var(--booking-text);
    font-weight: 600;
}

.opening-hours {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
    padding: 8px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
}

.performance-stats {
    display: flex;
    justify-content: space-between;
    padding: 10px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
}

.performance-item {
    text-align: center;
}

.performance-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.performance-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.card-actions {
    display: flex;
    gap: 8px;
}

.action-icon {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    color: var(--booking-text);
}

.action-icon:hover {
    transform: translateY(-2px);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

/* Bulk Actions */
.bulk-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
    padding: 12px 16px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    display: none;
}

.bulk-actions.show {
    display: flex;
}

.bulk-select {
    display: flex;
    align-items: center;
    gap: 8px;
}

.bulk-select input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.bulk-select span {
    font-size: 0.75rem;
    font-weight: 500;
}

.bulk-action-select {
    padding: 6px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
}

.bulk-apply-btn {
    padding: 6px 16px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    cursor: pointer;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    color: var(--booking-text);
    text-decoration: none;
    font-size: 0.75rem;
    transition: all var(--transition-fast);
}

.page-link:hover,
.page-link.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid rgba(0,128,9,0.2);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    grid-column: 1 / -1;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .insight-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .restaurants-grid {
        grid-template-columns: 1fr;
    }
    .filter-row {
        flex-direction: column;
    }
    .filter-group {
        width: 100%;
    }
    .filter-actions {
        justify-content: flex-end;
    }
}
</style>


<!-- Display success messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-shop"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
        <div class="stat-label">Total Restaurants</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-x-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['inactive'] ?? 0); ?></div>
        <div class="stat-label">Inactive</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-egg-fried"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_menu_items'] ?? 0); ?></div>
        <div class="stat-label">Menu Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_reservations'] ?? 0); ?></div>
        <div class="stat-label">Reservations</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-star"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?></div>
        <div class="stat-label">Avg Rating</div>
    </div>
</div>

<!-- Insights Section -->
<div class="insight-grid">
    <!-- Top Rated Restaurants -->
    <div class="insight-card">
        <div class="insight-header">
            <h3><i class="bi bi-star-fill"></i> Top Rated Restaurants</h3>
        </div>
        <div class="insight-body">
            <?php if (empty($topRated)): ?>
            <p style="font-size: 0.6875rem; color: var(--booking-text-light); text-align: center;">No ratings yet</p>
            <?php else: ?>
            <div class="top-rated-list">
                <?php foreach ($topRated as $rated): ?>
                <div class="top-rated-item" onclick="filterByRestaurant(<?php echo $rated['restaurant_id']; ?>)">
                    <div class="rating-stars">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= round($rated['rating']) ? '' : 'empty'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-value"><?php echo number_format($rated['rating'], 1); ?></div>
                    <div style="flex: 1;"><?php echo sanitize($rated['restaurant_name']); ?></div>
                    <div style="font-size: 0.625rem; color: var(--booking-text-light);">(<?php echo $rated['review_count']; ?> reviews)</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Cuisine Distribution -->
    <div class="insight-card">
        <div class="insight-header">
            <h3><i class="bi bi-tags"></i> Cuisine Types</h3>
        </div>
        <div class="insight-body">
            <div class="cuisine-list">
                <div class="cuisine-pill" onclick="filterByCuisine('all')">
                    All <span class="count">(<?php echo $stats['total']; ?>)</span>
                </div>
                <?php foreach ($cuisineDistribution as $cuisine): ?>
                <div class="cuisine-pill" onclick="filterByCuisine('<?php echo addslashes($cuisine['cuisine_type']); ?>')">
                                    <?php echo sanitize($cuisine['cuisine_type']); ?>
                    <span class="count">(<?php echo $cuisine['count']; ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="restaurants.php" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Restaurant name, cuisine, hotel..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Hotel</label>
                <select name="stay_id">
                    <option value="0" <?php echo $stayId == 0 ? 'selected' : ''; ?>>All Hotels</option>
                    <?php foreach ($hotels as $hotel): ?>
                    <option value="<?php echo $hotel['stay_id']; ?>" <?php echo $stayId == $hotel['stay_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($hotel['stay_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Cuisine</label>
                <select name="cuisine">
                    <option value="all" <?php echo $cuisine == 'all' ? 'selected' : ''; ?>>All Cuisines</option>
                    <?php foreach ($cuisineTypes as $c): ?>
                    <option value="<?php echo htmlspecialchars($c['cuisine_type']); ?>" <?php echo $cuisine == $c['cuisine_type'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($c['cuisine_type']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                    <option value="reservations_desc" <?php echo $sort == 'reservations_desc' ? 'selected' : ''; ?>>Most Reservations</option>
                    <option value="rating_desc" <?php echo $sort == 'rating_desc' ? 'selected' : ''; ?>>Highest Rated</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="restaurants.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions Bar -->
<form method="POST" action="restaurants.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_action">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_action_type" class="bulk-action-select">
            <option value="">Bulk Action</option>
            <option value="activate">Activate Selected</option>
            <option value="deactivate">Deactivate Selected</option>
        </select>
        <button type="submit" class="bulk-apply-btn" onclick="return confirm('Are you sure you want to perform this bulk action?')">Apply</button>
        <button type="button" class="bulk-apply-btn" onclick="clearSelection()" style="background: var(--booking-text-light);">Clear</button>
    </div>
</form>

<!-- Restaurants Grid -->
<div class="restaurants-grid">
    <?php if (empty($restaurants)): ?>
    <div class="empty-state">
        <i class="bi bi-shop" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px; color: var(--booking-text-light);">No restaurants found matching your criteria</p>
        <a href="restaurants.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <?php foreach ($restaurants as $restaurant):
        $openingHours = $restaurant['opening_hours'] ? json_decode($restaurant['opening_hours'], true) : [];
        $rating = $restaurant['avg_rating'] ?: 0;
    ?>
    <div class="restaurant-card" data-restaurant-id="<?php echo $restaurant['restaurant_id']; ?>">
        <div class="card-image">
            <?php if ($restaurant['main_image']): ?>
            <img src="<?php echo getImageUrl($restaurant['main_image'], 'restaurant'); ?>" alt="<?php echo sanitize($restaurant['restaurant_name']); ?>">
            <?php else: ?>
            <div class="image-placeholder">
                <i class="bi bi-shop"></i>
            </div>
            <?php endif; ?>
            <span class="status-badge <?php echo $restaurant['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                <i class="bi bi-<?php echo $restaurant['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                <?php echo $restaurant['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        
        <div class="card-content">
            <div class="restaurant-name">
                <a href="restaurant-detail.php?id=<?php echo $restaurant['restaurant_id']; ?>">
                    <?php echo sanitize($restaurant['restaurant_name']); ?>
                </a>
            </div>
            
            <div class="hotel-info">
                <i class="bi bi-building"></i>
                <?php echo sanitize($restaurant['hotel_name']); ?>
                <?php if ($restaurant['hotel_stars'] > 0): ?>
                <span class="hotel-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= $restaurant['hotel_stars'] ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </span>
                <?php endif; ?>
                <?php if ($restaurant['cuisine_type']): ?>
                <span class="cuisine-type">
                    <i class="bi bi-tag"></i>
                    <?php echo sanitize($restaurant['cuisine_type']); ?>
                </span>
                <?php endif; ?>
            </div>
            
            <?php if ($rating > 0 || $restaurant['review_count'] > 0): ?>
            <div class="rating">
                <div class="rating-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= round($rating) ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-value"><?php echo number_format($rating, 1); ?></span>
                <span class="review-count">(<?php echo number_format($restaurant['review_count']); ?> reviews)</span>
            </div>
            <?php endif; ?>
            
            <div class="restaurant-details">
<div class="detail-item">
    <i class="bi bi-egg-fried"></i>
    <span><strong><?php echo number_format($restaurant['menu_items_count'] ?? 0); ?></strong> menu items</span>
</div>
<div class="detail-item">
    <i class="bi bi-calendar-check"></i>
    <span><strong><?php echo number_format($restaurant['total_reservations'] ?? 0); ?></strong> reservations</span>
</div>
                <?php if ($restaurant['seating_capacity']): ?>
                <div class="detail-item">
                    <i class="bi bi-people"></i>
                    <span><strong><?php echo $restaurant['seating_capacity']; ?></strong> seats</span>
                </div>
                <?php endif; ?>
                <?php if ($restaurant['has_outdoor_seating']): ?>
                <div class="detail-item">
                    <i class="bi bi-tree"></i>
                    <span>Outdoor seating</span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($openingHours) && isset($openingHours['breakfast']) && $openingHours['breakfast']): ?>
            <div class="opening-hours">
                <i class="bi bi-clock"></i>
                <?php 
                $hours = [];
                if (isset($openingHours['breakfast']) && $openingHours['breakfast']) $hours[] = "Breakfast: {$openingHours['breakfast']}";
                if (isset($openingHours['lunch']) && $openingHours['lunch']) $hours[] = "Lunch: {$openingHours['lunch']}";
                if (isset($openingHours['dinner']) && $openingHours['dinner']) $hours[] = "Dinner: {$openingHours['dinner']}";
                echo implode(' | ', $hours);
                ?>
            </div>
            <?php endif; ?>
            
            <div class="card-footer">
                <div class="card-actions">
                    <input type="checkbox" class="restaurant-checkbox" value="<?php echo $restaurant['restaurant_id']; ?>" style="margin-right: 4px;">
                    <a href="restaurant-detail.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="action-icon" title="View Details">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="edit-restaurant.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="action-icon" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a href="menu.php?restaurant_id=<?php echo $restaurant['restaurant_id']; ?>" class="action-icon" title="Manage Menu">
                        <i class="bi bi-list-ul"></i>
                    </a>
                    <a href="?action=toggle_active&id=<?php echo $restaurant['restaurant_id']; ?>" class="action-icon" title="<?php echo $restaurant['is_active'] ? 'Deactivate' : 'Activate'; ?>" onclick="return confirm('<?php echo $restaurant['is_active'] ? 'Deactivate' : 'Activate'; ?> this restaurant?')">
                        <i class="bi bi-<?php echo $restaurant['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
        <i class="bi bi-chevron-left"></i>
    </a>
    <?php else: ?>
    <span class="page-link disabled"><i class="bi bi-chevron-left"></i></span>
    <?php endif; ?>
    
    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++):
    ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php else: ?>
    <span class="page-link disabled"><i class="bi bi-chevron-right"></i></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Filter functions
function filterByCuisine(cuisine) {
    const url = new URL(window.location.href);
    url.searchParams.set('cuisine', cuisine);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

function filterByRestaurant(restaurantId) {
    window.location.href = `restaurant-detail.php?id=${restaurantId}`;
}

// Bulk selection
let selectedRestaurants = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.restaurant-checkbox:checked');
    selectedRestaurants.clear();
    checkboxes.forEach(cb => selectedRestaurants.add(cb.value));
    
    const count = selectedRestaurants.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all
    const allCheckboxes = document.querySelectorAll('.restaurant-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.restaurant-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// Add event listeners
document.querySelectorAll('.restaurant-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.restaurant-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

// Handle bulk form submission
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.restaurant-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one restaurant');
        return;
    }
    
    const action = document.querySelector('[name="bulk_action_type"]').value;
    if (!action) {
        e.preventDefault();
        alert('Please select a bulk action');
        return;
    }
    
    // Add selected IDs to form
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_restaurants[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

// Initialize
updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>