<?php
$pageTitle = 'Stays Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$stayId = isset($_POST['stay_id']) ? intval($_POST['stay_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Handle verification status update
if ($action === 'verify' && $stayId > 0) {
    $stmt = $db->prepare("UPDATE stays SET is_verified = 1, updated_at = NOW() WHERE stay_id = ?");
    $stmt->execute([$stayId]);
    $_SESSION['success'] = "Stay verified successfully";
    header('Location: stays.php');
    exit;
}

// Handle deactivation
if ($action === 'deactivate' && $stayId > 0) {
    $stmt = $db->prepare("UPDATE stays SET is_active = 0, updated_at = NOW() WHERE stay_id = ?");
    $stmt->execute([$stayId]);
    $_SESSION['success'] = "Stay deactivated successfully";
    header('Location: stays.php');
    exit;
}

// Handle activation
if ($action === 'activate' && $stayId > 0) {
    $stmt = $db->prepare("UPDATE stays SET is_active = 1, updated_at = NOW() WHERE stay_id = ?");
    $stmt->execute([$stayId]);
    $_SESSION['success'] = "Stay activated successfully";
    header('Location: stays.php');
    exit;
}

// Handle bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_stays']) && is_array($_POST['selected_stays'])) {
    $selectedIds = array_map('intval', $_POST['selected_stays']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'verify') {
        $stmt = $db->prepare("UPDATE stays SET is_verified = 1, updated_at = NOW() WHERE stay_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " stays verified successfully";
    } elseif ($bulkAction === 'deactivate') {
        $stmt = $db->prepare("UPDATE stays SET is_active = 0, updated_at = NOW() WHERE stay_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " stays deactivated successfully";
    } elseif ($bulkAction === 'activate') {
        $stmt = $db->prepare("UPDATE stays SET is_active = 1, updated_at = NOW() WHERE stay_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " stays activated successfully";
    } elseif ($bulkAction === 'delete') {
        // Check if stays have bookings before deleting
        $stmt = $db->prepare("SELECT COUNT(*) FROM stays s LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id LEFT JOIN bookings b ON sr.room_id = b.stay_room_id WHERE s.stay_id IN ($placeholders) AND b.booking_id IS NOT NULL");
        $stmt->execute($selectedIds);
        $hasBookings = $stmt->fetchColumn() > 0;
        
        if ($hasBookings) {
            $_SESSION['error'] = "Cannot delete stays that have existing bookings";
        } else {
            // Delete rooms first
            $stmt = $db->prepare("DELETE sr FROM stay_rooms sr WHERE stay_id IN ($placeholders)");
            $stmt->execute($selectedIds);
            // Delete stays
            $stmt = $db->prepare("DELETE FROM stays WHERE stay_id IN ($placeholders)");
            $stmt->execute($selectedIds);
            $_SESSION['success'] = count($selectedIds) . " stays deleted successfully";
        }
    }
    header('Location: stays.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$location = isset($_GET['location']) ? intval($_GET['location']) : 0;
$starRating = isset($_GET['star_rating']) ? intval($_GET['star_rating']) : 0;
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        s.*,
        l.name as location_name,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as room_count,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as total_revenue
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (s.stay_name LIKE ? OR s.address LIKE ? OR s.city LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status === 'active') {
    $sql .= " AND s.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND s.is_active = 0";
} elseif ($status === 'verified') {
    $sql .= " AND s.is_verified = 1";
} elseif ($status === 'pending') {
    $sql .= " AND s.is_verified = 0 AND s.is_active = 1";
}

if ($type !== 'all') {
    $sql .= " AND s.stay_type = ?";
    $params[] = $type;
}

if ($location > 0) {
    $sql .= " AND s.location_id = ?";
    $params[] = $location;
}

if ($starRating > 0) {
    $sql .= " AND s.star_rating >= ?";
    $params[] = $starRating;
}

// Sorting
switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY s.stay_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY s.stay_name DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY s.created_at ASC";
        break;
    case 'revenue_desc':
        $sql .= " ORDER BY total_revenue DESC";
        break;
    case 'bookings_desc':
        $sql .= " ORDER BY booking_count DESC";
        break;
    case 'rating_desc':
        $sql .= " ORDER BY s.star_rating DESC";
        break;
    default:
        $sql .= " ORDER BY s.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$stays = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (s.stay_name LIKE ? OR s.address LIKE ? OR s.city LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($status === 'active') {
    $countSql .= " AND s.is_active = 1";
} elseif ($status === 'inactive') {
    $countSql .= " AND s.is_active = 0";
} elseif ($status === 'verified') {
    $countSql .= " AND s.is_verified = 1";
} elseif ($status === 'pending') {
    $countSql .= " AND s.is_verified = 0 AND s.is_active = 1";
}
if ($type !== 'all') {
    $countSql .= " AND s.stay_type = ?";
    $countParams[] = $type;
}
if ($location > 0) {
    $countSql .= " AND s.location_id = ?";
    $countParams[] = $location;
}
if ($starRating > 0) {
    $countSql .= " AND s.star_rating >= ?";
    $countParams[] = $starRating;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalStays = $stmt->fetchColumn();
$totalPages = ceil($totalStays / $perPage);

// Get filter options
$locations = $db->query("SELECT location_id, name FROM locations WHERE type IN ('city', 'region') ORDER BY name")->fetchAll();
$stayTypes = $db->query("SHOW COLUMNS FROM stays WHERE Field = 'stay_type'")->fetch();
preg_match("/^enum\((.*)\)$/", $stayTypes['Type'], $matches);
$stayTypes = array_map(function($value) {
    return trim($value, "'");
}, explode(',', $matches[1]));

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN is_verified = 0 AND is_active = 1 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN star_rating >= 4 THEN 1 ELSE 0 END) as top_rated,
        COALESCE(SUM(total_revenue), 0) as total_revenue
    FROM (
        SELECT 
            s.*,
            (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
             LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
             WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as total_revenue
        FROM stays s
    ) as s
")->fetch();

// Get revenue by month for chart
$revenueByMonth = $db->query("
    SELECT 
        DATE_FORMAT(b.created_at, '%b %Y') as month,
        DATE_FORMAT(b.created_at, '%Y-%m') as month_key,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$revenues = [];
foreach ($revenueByMonth as $data) {
    $months[] = $data['month'];
    $revenues[] = $data['revenue'];
}
?>

<style>
/* Stays Management Styles */
.filter-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
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
    min-width: 150px;
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
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 16px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    cursor: pointer;
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

/* Stats Bar */
.stats-bar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.stat-card-mini {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px 20px;
    flex: 1;
    min-width: 120px;
    transition: all var(--transition-fast);
    cursor: pointer;
}

.stat-card-mini:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-card-mini.active {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
}

.stat-card-mini .stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 2px;
}

.stat-card-mini .stat-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.stat-card-mini .stat-sub {
    font-size: 0.5625rem;
    color: var(--booking-text-lighter);
    margin-top: 4px;
}

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
}

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
    text-transform: uppercase;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    user-select: none;
}

.data-table th:hover {
    background: var(--booking-gray-dark);
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

.data-table tr.selected {
    background: rgba(0,102,255,0.05);
}

/* Stay Preview */
.stay-preview {
    display: flex;
    align-items: center;
    gap: 12px;
}

.stay-image {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--booking-gray-light);
}

.stay-info {
    flex: 1;
}

.stay-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.stay-name a {
    color: var(--booking-text);
    text-decoration: none;
}

.stay-name a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.stay-location {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.stay-meta {
    display: flex;
    gap: 8px;
    margin-top: 4px;
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

/* Rating Stars */
.rating-stars {
    display: inline-flex;
    gap: 2px;
}

.rating-stars i {
    font-size: 0.625rem;
}

.rating-stars i.filled {
    color: #ffc107;
}

.rating-stars i.empty {
    color: #e0e0e0;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
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
    gap: 6px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all var(--transition-fast);
}

.action-btn.view {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.action-btn.edit {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.action-btn.verify {
    background: rgba(0,128,9,0.1);
    color: var(--booking-success);
}

.action-btn.deactivate {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
}

.action-btn.activate {
    background: rgba(0,128,9,0.1);
    color: var(--booking-success);
}

.action-btn:hover {
    transform: translateY(-1px);
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
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
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

.page-link.disabled {
    opacity: 0.5;
    pointer-events: none;
}

/* Chart */
.chart-container {
    margin-bottom: 24px;
}

.revenue-chart {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
}

.revenue-chart h3 {
    font-size: 0.8125rem;
    font-weight: 700;
    margin-bottom: 16px;
}

/* Responsive */
@media (max-width: 1024px) {
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        justify-content: flex-end;
    }
    
    .data-table {
        min-width: 800px;
    }
}

@media (max-width: 768px) {
    .stats-bar {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
</style>

<!-- Display success/error messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success" style="background: #e6f4ea; color: var(--booking-success); padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <span><i class="bi bi-check-circle-fill"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error" style="background: #fce8e8; color: var(--booking-danger); padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
    <span><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Stats Bar -->
<div class="stats-bar">
    <div class="stat-card-mini <?php echo $status == 'all' ? 'active' : ''; ?>" onclick="filterByStatus('all')">
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Stays</div>
        <div class="stat-sub">All properties</div>
    </div>
    <div class="stat-card-mini <?php echo $status == 'verified' ? 'active' : ''; ?>" onclick="filterByStatus('verified')">
        <div class="stat-value"><?php echo number_format($stats['verified']); ?></div>
        <div class="stat-label">Verified</div>
        <div class="stat-sub">Ready to book</div>
    </div>
    <div class="stat-card-mini <?php echo $status == 'pending' ? 'active' : ''; ?>" onclick="filterByStatus('pending')">
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
        <div class="stat-sub">Awaiting review</div>
    </div>
    <div class="stat-card-mini <?php echo $status == 'inactive' ? 'active' : ''; ?>" onclick="filterByStatus('inactive')">
        <div class="stat-value"><?php echo number_format($stats['inactive']); ?></div>
        <div class="stat-label">Inactive</div>
        <div class="stat-sub">Not visible</div>
    </div>
    <div class="stat-card-mini">
        <div class="stat-value"><?php echo number_format($stats['top_rated']); ?></div>
        <div class="stat-label">Top Rated</div>
        <div class="stat-sub">4+ Stars</div>
    </div>
    <div class="stat-card-mini">
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-sub">Lifetime earnings</div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="stays.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Name, address, city..." value="<?php echo htmlspecialchars($search); ?>">
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
                <label>Type</label>
                <select name="type">
                    <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <?php foreach ($stayTypes as $stayType): ?>
                    <option value="<?php echo $stayType; ?>" <?php echo $type == $stayType ? 'selected' : ''; ?>>
                        <?php echo ucfirst($stayType); ?>
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
                <label>Star Rating</label>
                <select name="star_rating">
                    <option value="0" <?php echo $starRating == 0 ? 'selected' : ''; ?>>Any Rating</option>
                    <option value="5" <?php echo $starRating == 5 ? 'selected' : ''; ?>>5 Stars</option>
                    <option value="4" <?php echo $starRating == 4 ? 'selected' : ''; ?>>4+ Stars</option>
                    <option value="3" <?php echo $starRating == 3 ? 'selected' : ''; ?>>3+ Stars</option>
                    <option value="2" <?php echo $starRating == 2 ? 'selected' : ''; ?>>2+ Stars</option>
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
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="stays.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Revenue Chart (if data exists) -->
<?php if (!empty($revenueByMonth)): ?>
<div class="chart-container">
    <div class="revenue-chart">
        <h3><i class="bi bi-graph-up"></i> Revenue Trend (Last 12 Months)</h3>
        <div style="height: 250px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Actions Bar -->
<form method="POST" action="stays.php" id="bulkForm">
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
            <option value="delete">Delete Selected</option>
        </select>
        <button type="submit" class="bulk-apply-btn" onclick="return confirm('Are you sure you want to perform this bulk action?')">Apply</button>
        <button type="button" class="bulk-apply-btn" onclick="clearSelection()" style="background: var(--booking-text-light);">Clear</button>
    </div>
</form>

<!-- Stays Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 30px;">
                    <input type="checkbox" id="selectAllHeader">
                </th>
                <th>Property</th>
                <th>Type</th>
                <th>Location</th>
                <th>Rooms</th>
                <th>Rating</th>
                <th>Bookings</th>
                <th>Revenue</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($stays)): ?>
            <tr>
                <td colspan="10" style="text-align: center; padding: 60px;">
                    <i class="bi bi-building" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px; color: var(--booking-text-light);">No stays found matching your criteria</p>
                    <a href="stays.php" class="action-btn view" style="margin-top: 12px; display: inline-block;">Clear Filters</a>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($stays as $stay): ?>
            <tr data-stay-id="<?php echo $stay['stay_id']; ?>">
                <td>
                    <input type="checkbox" class="stay-checkbox" value="<?php echo $stay['stay_id']; ?>">
                </td>
                <td>
                    <div class="stay-preview">
                        <img src="<?php echo getImageUrl($stay['main_image'] ?? '', 'stay'); ?>" class="stay-image" onerror="this.src='/gorwanda-plus/assets/images/placeholders/placeholder.svg'">
                        <div class="stay-info">
                            <div class="stay-name">
                                <a href="stay-detail.php?id=<?php echo $stay['stay_id']; ?>">
                                    <?php echo sanitize($stay['stay_name']); ?>
                                </a>
                            </div>
                            <div class="stay-location">
                                <i class="bi bi-geo-alt"></i> <?php echo sanitize($stay['address']); ?>
                            </div>
                            <div class="stay-meta">
                                <span><i class="bi bi-person"></i> <?php echo sanitize($stay['owner_first'] . ' ' . $stay['owner_last']); ?></span>
                                <?php if ($stay['star_rating'] > 0): ?>
                                <span><i class="bi bi-star-fill"></i> <?php echo $stay['star_rating']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span style="text-transform: capitalize;"><?php echo $stay['stay_type']; ?></span>
                </td>
                <td>
                    <?php echo sanitize($stay['location_name'] ?? 'N/A'); ?>
                    <?php if ($stay['city']): ?>
                    <div class="stay-meta"><?php echo sanitize($stay['city']); ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo $stay['room_count']; ?></strong> rooms
                </td>
                <td>
                    <?php if ($stay['star_rating'] > 0): ?>
                    <div class="rating-stars">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $stay['star_rating'] ? 'filled' : 'empty'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php else: ?>
                    <span class="stay-meta">Not rated</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo number_format($stay['booking_count']); ?></strong>
                    <div class="stay-meta">bookings</div>
                </td>
                <td>
                    <strong style="color: var(--booking-success);"><?php echo formatPrice($stay['total_revenue']); ?></strong>
                </td>
                <td>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <?php if ($stay['is_verified']): ?>
                        <span class="status-badge status-verified">
                            <i class="bi bi-shield-check"></i> Verified
                        </span>
                        <?php else: ?>
                        <span class="status-badge status-pending">
                            <i class="bi bi-clock"></i> Pending
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
                 </td>
                 <td>
                    <div class="action-buttons">
                        <a href="stay-detail.php?id=<?php echo $stay['stay_id']; ?>" class="action-btn view" title="View Details">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit-stay.php?id=<?php echo $stay['stay_id']; ?>" class="action-btn edit" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if (!$stay['is_verified'] && $stay['is_active']): ?>
                        <a href="?action=verify&id=<?php echo $stay['stay_id']; ?>" class="action-btn verify" title="Verify" onclick="return confirm('Verify this stay?')">
                            <i class="bi bi-shield-check"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($stay['is_active']): ?>
                        <a href="?action=deactivate&id=<?php echo $stay['stay_id']; ?>" class="action-btn deactivate" title="Deactivate" onclick="return confirm('Deactivate this stay?')">
                            <i class="bi bi-eye-slash"></i>
                        </a>
                        <?php else: ?>
                        <a href="?action=activate&id=<?php echo $stay['stay_id']; ?>" class="action-btn activate" title="Activate" onclick="return confirm('Activate this stay?')">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
<?php if (!empty($revenueByMonth)): ?>
const ctx = document.getElementById('revenueChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [{
            label: 'Revenue',
            data: <?php echo json_encode($revenues); ?>,
            borderColor: '#003b95',
            backgroundColor: 'rgba(0, 59, 149, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Revenue: ' + formatCurrency(context.parsed.y);
                    }
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    }
                }
            }
        }
    }
});
<?php endif; ?>

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Bulk selection
let selectedStays = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.stay-checkbox:checked');
    selectedStays.clear();
    checkboxes.forEach(cb => selectedStays.add(cb.value));
    
    const count = selectedStays.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all header
    const allCheckboxes = document.querySelectorAll('.stay-checkbox');
    const selectAllHeader = document.getElementById('selectAllHeader');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAllHeader) selectAllHeader.checked = true;
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAllHeader) selectAllHeader.checked = false;
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.stay-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// Add event listeners
document.querySelectorAll('.stay-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAllHeader = document.getElementById('selectAllHeader');
if (selectAllHeader) {
    selectAllHeader.addEventListener('change', function(e) {
        document.querySelectorAll('.stay-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.stay-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

// Handle bulk form submission
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.stay-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one stay');
        return;
    }
    
    const action = document.querySelector('[name="bulk_action_type"]').value;
    if (!action) {
        e.preventDefault();
        alert('Please select a bulk action');
        return;
    }
    
    if (action === 'delete' && !confirm('Are you sure you want to delete ' + selected.length + ' stays? This action cannot be undone.')) {
        e.preventDefault();
        return;
    }
    
    // Add selected IDs to form
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_stays[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

// Initialize bulk actions
updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>