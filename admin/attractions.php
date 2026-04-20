<?php
$pageTitle = 'Experiences Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$attractionId = isset($_POST['attraction_id']) ? intval($_POST['attraction_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Verify/Deactivate/Activate Attraction
if ($action === 'verify' && $attractionId > 0) {
    $stmt = $db->prepare("UPDATE attractions SET is_verified = 1 WHERE attraction_id = ?");
    $stmt->execute([$attractionId]);
    $_SESSION['success'] = "Experience verified successfully";
    header('Location: attractions.php');
    exit;
}

if ($action === 'deactivate' && $attractionId > 0) {
    $stmt = $db->prepare("UPDATE attractions SET is_active = 0 WHERE attraction_id = ?");
    $stmt->execute([$attractionId]);
    $_SESSION['success'] = "Experience deactivated successfully";
    header('Location: attractions.php');
    exit;
}

if ($action === 'activate' && $attractionId > 0) {
    $stmt = $db->prepare("UPDATE attractions SET is_active = 1 WHERE attraction_id = ?");
    $stmt->execute([$attractionId]);
    $_SESSION['success'] = "Experience activated successfully";
    header('Location: attractions.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_attractions']) && is_array($_POST['selected_attractions'])) {
    $selectedIds = array_map('intval', $_POST['selected_attractions']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'verify') {
        $stmt = $db->prepare("UPDATE attractions SET is_verified = 1 WHERE attraction_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " experiences verified successfully";
    } elseif ($bulkAction === 'deactivate') {
        $stmt = $db->prepare("UPDATE attractions SET is_active = 0 WHERE attraction_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " experiences deactivated successfully";
    } elseif ($bulkAction === 'activate') {
        $stmt = $db->prepare("UPDATE attractions SET is_active = 1 WHERE attraction_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " experiences activated successfully";
    }
    header('Location: attractions.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$location = isset($_GET['location']) ? intval($_GET['location']) : 0;
$difficulty = isset($_GET['difficulty']) ? sanitize($_GET['difficulty']) : 'all';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        a.*,
        c.name as category_name,
        c.icon as category_icon,
        l.name as location_name,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as tier_count,
        (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status IN ('confirmed', 'completed')) as total_revenue
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (a.attraction_name LIKE ? OR a.description LIKE ? OR a.address LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status === 'active') {
    $sql .= " AND a.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND a.is_active = 0";
} elseif ($status === 'verified') {
    $sql .= " AND a.is_verified = 1";
} elseif ($status === 'pending') {
    $sql .= " AND a.is_verified = 0 AND a.is_active = 1";
}

if ($category > 0) {
    $sql .= " AND a.category_id = ?";
    $params[] = $category;
}

if ($location > 0) {
    $sql .= " AND a.location_id = ?";
    $params[] = $location;
}

if ($difficulty !== 'all') {
    $sql .= " AND a.difficulty_level = ?";
    $params[] = $difficulty;
}

// Sorting
switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY a.attraction_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY a.attraction_name DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY a.created_at ASC";
        break;
    case 'revenue_desc':
        $sql .= " ORDER BY total_revenue DESC";
        break;
    case 'bookings_desc':
        $sql .= " ORDER BY total_bookings DESC";
        break;
    case 'rating_desc':
        $sql .= " ORDER BY a.avg_rating DESC";
        break;
    case 'price_asc':
        $sql .= " ORDER BY min_price ASC";
        break;
    default:
        $sql .= " ORDER BY a.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$attractions = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (a.attraction_name LIKE ? OR a.description LIKE ? OR a.address LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($status === 'active') {
    $countSql .= " AND a.is_active = 1";
} elseif ($status === 'inactive') {
    $countSql .= " AND a.is_active = 0";
} elseif ($status === 'verified') {
    $countSql .= " AND a.is_verified = 1";
} elseif ($status === 'pending') {
    $countSql .= " AND a.is_verified = 0 AND a.is_active = 1";
}
if ($category > 0) {
    $countSql .= " AND a.category_id = ?";
    $countParams[] = $category;
}
if ($location > 0) {
    $countSql .= " AND a.location_id = ?";
    $countParams[] = $location;
}
if ($difficulty !== 'all') {
    $countSql .= " AND a.difficulty_level = ?";
    $countParams[] = $difficulty;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalAttractions = $stmt->fetchColumn();
$totalPages = ceil($totalAttractions / $perPage);

// Get filter options
$categories = $db->query("SELECT category_id, name, icon FROM categories WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();
$locations = $db->query("SELECT location_id, name FROM locations WHERE type IN ('city', 'region', 'landmark') ORDER BY name")->fetchAll();

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN is_verified = 0 AND is_active = 1 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        AVG(avg_rating) as avg_rating,
        SUM(review_count) as total_reviews,
        (SELECT COUNT(*) FROM attraction_tiers) as total_tiers,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE b.status IN ('confirmed', 'completed')) as total_revenue
    FROM attractions
")->fetch();

// Get top categories
$topCategories = $db->query("
    SELECT 
        c.name,
        COUNT(a.attraction_id) as count,
        COALESCE(SUM(CASE WHEN a.is_active = 1 THEN 1 ELSE 0 END), 0) as active_count
    FROM categories c
    LEFT JOIN attractions a ON c.category_id = a.category_id
    GROUP BY c.category_id
    ORDER BY count DESC
    LIMIT 5
")->fetchAll();

// Difficulty levels
$difficultyLevels = ['easy', 'moderate', 'challenging'];
?>

<style>
/* Attractions Management Styles */
.attractions-header {
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
    line-height: 1.2;
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

/* Attractions Grid */
.attractions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.attraction-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
    position: relative;
}

.attraction-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-image {
    position: relative;
    height: 200px;
    overflow: hidden;
}

.card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.attraction-card:hover .card-image img {
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

.category-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
    color: white;
    display: flex;
    align-items: center;
    gap: 4px;
}

.status-badges {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: flex-end;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    color: white;
}

.status-verified {
    background: rgba(0,128,9,0.9);
}

.status-pending {
    background: rgba(255,140,0,0.9);
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

.attraction-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 6px;
    color: var(--booking-text);
}

.attraction-title a {
    color: var(--booking-text);
    text-decoration: none;
}

.attraction-title a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.attraction-location {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.rating {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
}

.stars {
    display: flex;
    gap: 2px;
}

.stars i {
    font-size: 0.6875rem;
    color: #ffc107;
}

.stars i.empty {
    color: #e0e0e0;
}

.rating-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.review-count {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.attraction-details {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 12px;
    padding: 10px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.detail {
    text-align: center;
}

.detail i {
    font-size: 0.875rem;
    color: var(--booking-blue);
    display: block;
    margin-bottom: 4px;
}

.detail span {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.detail strong {
    font-size: 0.6875rem;
    color: var(--booking-text);
    display: block;
}

.pricing {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 12px;
}

.price {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-success);
}

.price-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.tier-badge {
    background: var(--booking-gray-light);
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.625rem;
    display: flex;
    align-items: center;
    gap: 4px;
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

.owner-info {
    font-size: 0.625rem;
    color: var(--booking-text-light);
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

/* Top Categories */
.top-categories {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 24px;
}

.top-categories h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.category-list {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.category-pill {
    background: var(--booking-gray-light);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.6875rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.category-pill:hover {
    background: var(--booking-blue);
    color: white;
}

.category-pill.active {
    background: var(--booking-blue);
    color: white;
}

.category-count {
    background: rgba(0,0,0,0.1);
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 0.5625rem;
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

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .attractions-grid {
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


<!-- Display success/error messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; font-size: 1rem;">&times;</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <div>
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; font-size: 1rem;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-ticket-perforated"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Experiences</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['verified']); ?></div>
        <div class="stat-label">Verified</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-star"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['avg_rating'], 1); ?></div>
        <div class="stat-label">Avg Rating</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-grid-3x3-gap-fill"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_tiers']); ?></div>
        <div class="stat-label">Pricing Tiers</div>
    </div>
</div>

<!-- Top Categories -->
<?php if (!empty($topCategories)): ?>
<div class="top-categories">
    <h3><i class="bi bi-tags"></i> Popular Categories</h3>
    <div class="category-list">
        <?php foreach ($topCategories as $cat): ?>
        <div class="category-pill" onclick="filterByCategory(<?php echo $cat['category_id'] ?? 0; ?>)">
            <i class="bi bi-tag"></i>
            <?php echo $cat['name']; ?>
            <span class="category-count"><?php echo $cat['count']; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="attractions.php" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Experience name, description..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="verified" <?php echo $status == 'verified' ? 'selected' : ''; ?>>Verified</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Category</label>
                <select name="category">
                    <option value="0" <?php echo $category == 0 ? 'selected' : ''; ?>>All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['category_id']; ?>" <?php echo $category == $cat['category_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Location</label>
                <select name="location">
                    <option value="0" <?php echo $location == 0 ? 'selected' : ''; ?>>All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['location_id']; ?>" <?php echo $location == $loc['location_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Difficulty</label>
                <select name="difficulty">
                    <option value="all" <?php echo $difficulty == 'all' ? 'selected' : ''; ?>>All Levels</option>
                    <option value="easy" <?php echo $difficulty == 'easy' ? 'selected' : ''; ?>>Easy</option>
                    <option value="moderate" <?php echo $difficulty == 'moderate' ? 'selected' : ''; ?>>Moderate</option>
                    <option value="challenging" <?php echo $difficulty == 'challenging' ? 'selected' : ''; ?>>Challenging</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                    <option value="revenue_desc" <?php echo $sort == 'revenue_desc' ? 'selected' : ''; ?>>Highest Revenue</option>
                    <option value="bookings_desc" <?php echo $sort == 'bookings_desc' ? 'selected' : ''; ?>>Most Bookings</option>
                    <option value="rating_desc" <?php echo $sort == 'rating_desc' ? 'selected' : ''; ?>>Highest Rated</option>
                    <option value="price_asc" <?php echo $sort == 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="attractions.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions Bar -->
<form method="POST" action="attractions.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_action">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_action_type" class="bulk-action-select">
            <option value="">Bulk Action</option>
            <option value="verify">Verify Selected</option>
            <option value="activate">Activate Selected</option>
            <option value="deactivate">Deactivate Selected</option>
        </select>
        <button type="submit" class="bulk-apply-btn" onclick="return confirm('Are you sure you want to perform this bulk action?')">Apply</button>
        <button type="button" class="bulk-apply-btn" onclick="clearSelection()" style="background: var(--booking-text-light);">Clear</button>
    </div>
</form>

<!-- Attractions Grid -->
<div class="attractions-grid">
    <?php if (empty($attractions)): ?>
    <div class="empty-state" style="grid-column: 1 / -1; text-align: center; padding: 60px; background: var(--booking-white); border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-ticket-perforated" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px; color: var(--booking-text-light);">No experiences found matching your criteria</p>
        <a href="attractions.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <?php foreach ($attractions as $attraction): 
        $images = $attraction['main_image'] ? [$attraction['main_image']] : [];
        if ($attraction['gallery_images']) {
            $gallery = json_decode($attraction['gallery_images'], true);
            if (is_array($gallery)) {
                $images = array_merge($images, $gallery);
            }
        }
        $firstImage = $images[0] ?? null;
        $rating = $attraction['avg_rating'] ?: 0;
    ?>
    <div class="attraction-card" data-attraction-id="<?php echo $attraction['attraction_id']; ?>">
        <div class="card-image">
            <?php if ($firstImage): ?>
            <img src="<?php echo getImageUrl($firstImage, 'attraction'); ?>" alt="<?php echo sanitize($attraction['attraction_name']); ?>">
            <?php else: ?>
            <div class="image-placeholder">
                <i class="bi bi-ticket-perforated"></i>
            </div>
            <?php endif; ?>
            
            <?php if ($attraction['category_name']): ?>
            <div class="category-badge">
                <i class="bi <?php echo $attraction['category_icon'] ?? 'bi-tag'; ?>"></i>
                <?php echo sanitize($attraction['category_name']); ?>
            </div>
            <?php endif; ?>
            
            <div class="status-badges">
                <?php if ($attraction['is_verified']): ?>
                <span class="status-badge status-verified">
                    <i class="bi bi-shield-check"></i> Verified
                </span>
                <?php else: ?>
                <span class="status-badge status-pending">
                    <i class="bi bi-clock"></i> Pending
                </span>
                <?php endif; ?>
                
                <?php if (!$attraction['is_active']): ?>
                <span class="status-badge status-inactive">
                    <i class="bi bi-eye-slash"></i> Inactive
                </span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card-content">
            <div class="attraction-title">
                <a href="attraction-detail.php?id=<?php echo $attraction['attraction_id']; ?>">
                    <?php echo sanitize($attraction['attraction_name']); ?>
                </a>
            </div>
            
            <div class="attraction-location">
                <i class="bi bi-geo-alt"></i>
                <?php echo sanitize($attraction['location_name'] ?? 'Location not set'); ?>
                <?php if ($attraction['address']): ?> • <?php echo sanitize(substr($attraction['address'], 0, 30)); ?><?php endif; ?>
            </div>
            
            <?php if ($rating > 0 || $attraction['review_count'] > 0): ?>
            <div class="rating">
                <div class="stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= round($rating) ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="rating-value"><?php echo number_format($rating, 1); ?></span>
                <span class="review-count">(<?php echo number_format($attraction['review_count']); ?> reviews)</span>
            </div>
            <?php endif; ?>
            
            <div class="attraction-details">
                <div class="detail">
                    <i class="bi bi-hourglass-split"></i>
                    <strong><?php echo $attraction['duration_minutes'] ? floor($attraction['duration_minutes'] / 60) . 'h ' . ($attraction['duration_minutes'] % 60) . 'm' : '—'; ?></strong>
                    <span>Duration</span>
                </div>
                <div class="detail">
                    <i class="bi bi-people"></i>
                    <strong><?php echo $attraction['max_group_size'] ?: '—'; ?></strong>
                    <span>Max Group</span>
                </div>
                <div class="detail">
                    <i class="bi bi-activity"></i>
                    <strong><?php echo ucfirst($attraction['difficulty_level'] ?: '—'); ?></strong>
                    <span>Difficulty</span>
                </div>
            </div>
            
            <div class="pricing">
                <div>
                    <span class="price"><?php echo $attraction['min_price'] ? formatPrice($attraction['min_price']) : '—'; ?></span>
                    <span class="price-label"> from</span>
                </div>
                <div class="tier-badge">
                    <i class="bi bi-layers"></i>
                    <?php echo $attraction['tier_count']; ?> pricing tiers
                </div>
            </div>
            
            <div class="performance-stats">
                <div class="performance-item">
                    <div class="performance-value"><?php echo number_format($attraction['total_bookings']); ?></div>
                    <div class="performance-label">Bookings</div>
                </div>
                <div class="performance-item">
                    <div class="performance-value"><?php echo formatPrice($attraction['total_revenue']); ?></div>
                    <div class="performance-label">Revenue</div>
                </div>
                <div class="performance-item">
                    <div class="performance-value"><?php echo $attraction['commission_rate']; ?>%</div>
                    <div class="performance-label">Commission</div>
                </div>
            </div>
            
            <div class="card-footer">
                <div class="owner-info">
                    <i class="bi bi-person"></i> <?php echo sanitize($attraction['owner_first'] . ' ' . substr($attraction['owner_last'] ?? '', 0, 1) . '.'); ?>
                </div>
                <div class="card-actions">
                    <input type="checkbox" class="attraction-checkbox" value="<?php echo $attraction['attraction_id']; ?>" style="margin-right: 4px;">
                    <a href="attraction-detail.php?id=<?php echo $attraction['attraction_id']; ?>" class="action-icon" title="View Details">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="edit-attraction.php?id=<?php echo $attraction['attraction_id']; ?>" class="action-icon" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <?php if (!$attraction['is_verified'] && $attraction['is_active']): ?>
                    <a href="?action=verify&id=<?php echo $attraction['attraction_id']; ?>" class="action-icon" title="Verify" onclick="return confirm('Verify this experience?')">
                        <i class="bi bi-shield-check"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($attraction['is_active']): ?>
                    <a href="?action=deactivate&id=<?php echo $attraction['attraction_id']; ?>" class="action-icon" title="Deactivate" onclick="return confirm('Deactivate this experience?')">
                        <i class="bi bi-eye-slash"></i>
                    </a>
                    <?php else: ?>
                    <a href="?action=activate&id=<?php echo $attraction['attraction_id']; ?>" class="action-icon" title="Activate" onclick="return confirm('Activate this experience?')">
                        <i class="bi bi-eye"></i>
                    </a>
                    <?php endif; ?>
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
// Filter by category
function filterByCategory(categoryId) {
    const url = new URL(window.location.href);
    url.searchParams.set('category', categoryId);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Bulk selection
let selectedAttractions = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.attraction-checkbox:checked');
    selectedAttractions.clear();
    checkboxes.forEach(cb => selectedAttractions.add(cb.value));
    
    const count = selectedAttractions.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all
    const allCheckboxes = document.querySelectorAll('.attraction-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.attraction-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// Add event listeners
document.querySelectorAll('.attraction-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.attraction-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

// Handle bulk form submission
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.attraction-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one experience');
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
        input.name = 'selected_attractions[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

// Initialize
updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>