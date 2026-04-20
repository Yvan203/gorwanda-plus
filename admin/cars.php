<?php
$pageTitle = 'Car Rentals Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$rentalId = isset($_POST['rental_id']) ? intval($_POST['rental_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Verify/Deactivate/Activate Rental Company
if ($action === 'verify' && $rentalId > 0) {
    $stmt = $db->prepare("UPDATE car_rentals SET is_verified = 1, updated_at = NOW() WHERE rental_id = ?");
    $stmt->execute([$rentalId]);
    $_SESSION['success'] = "Car rental company verified successfully";
    header('Location: cars.php');
    exit;
}

if ($action === 'deactivate' && $rentalId > 0) {
    $stmt = $db->prepare("UPDATE car_rentals SET is_active = 0, updated_at = NOW() WHERE rental_id = ?");
    $stmt->execute([$rentalId]);
    $_SESSION['success'] = "Car rental company deactivated successfully";
    header('Location: cars.php');
    exit;
}

if ($action === 'activate' && $rentalId > 0) {
    $stmt = $db->prepare("UPDATE car_rentals SET is_active = 1, updated_at = NOW() WHERE rental_id = ?");
    $stmt->execute([$rentalId]);
    $_SESSION['success'] = "Car rental company activated successfully";
    header('Location: cars.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_rentals']) && is_array($_POST['selected_rentals'])) {
    $selectedIds = array_map('intval', $_POST['selected_rentals']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'verify') {
        $stmt = $db->prepare("UPDATE car_rentals SET is_verified = 1, updated_at = NOW() WHERE rental_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " companies verified successfully";
    } elseif ($bulkAction === 'deactivate') {
        $stmt = $db->prepare("UPDATE car_rentals SET is_active = 0, updated_at = NOW() WHERE rental_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " companies deactivated successfully";
    } elseif ($bulkAction === 'activate') {
        $stmt = $db->prepare("UPDATE car_rentals SET is_active = 1, updated_at = NOW() WHERE rental_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " companies activated successfully";
    }
    header('Location: cars.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$location = isset($_GET['location']) ? intval($_GET['location']) : 0;
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        cr.*,
        l.name as location_name,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id) as fleet_count,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND is_active = 1) as active_fleet,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE cf.rental_id = cr.rental_id AND b.status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE cf.rental_id = cr.rental_id AND b.status IN ('confirmed', 'completed')) as total_revenue
    FROM car_rentals cr
    LEFT JOIN locations l ON cr.location_id = l.location_id
    LEFT JOIN users u ON cr.owner_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (cr.company_name LIKE ? OR cr.address LIKE ? OR l.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status === 'active') {
    $sql .= " AND cr.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND cr.is_active = 0";
} elseif ($status === 'verified') {
    $sql .= " AND cr.is_verified = 1";
} elseif ($status === 'pending') {
    $sql .= " AND cr.is_verified = 0 AND cr.is_active = 1";
}

if ($location > 0) {
    $sql .= " AND cr.location_id = ?";
    $params[] = $location;
}

// Sorting
switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY cr.company_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY cr.company_name DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY cr.created_at ASC";
        break;
    case 'revenue_desc':
        $sql .= " ORDER BY total_revenue DESC";
        break;
    case 'bookings_desc':
        $sql .= " ORDER BY total_bookings DESC";
        break;
    case 'fleet_desc':
        $sql .= " ORDER BY fleet_count DESC";
        break;
    default:
        $sql .= " ORDER BY cr.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rentals = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM car_rentals cr
    LEFT JOIN locations l ON cr.location_id = l.location_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (cr.company_name LIKE ? OR cr.address LIKE ? OR l.name LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($status === 'active') {
    $countSql .= " AND cr.is_active = 1";
} elseif ($status === 'inactive') {
    $countSql .= " AND cr.is_active = 0";
} elseif ($status === 'verified') {
    $countSql .= " AND cr.is_verified = 1";
} elseif ($status === 'pending') {
    $countSql .= " AND cr.is_verified = 0 AND cr.is_active = 1";
}
if ($location > 0) {
    $countSql .= " AND cr.location_id = ?";
    $countParams[] = $location;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalRentals = $stmt->fetchColumn();
$totalPages = ceil($totalRentals / $perPage);

// Get filter options
$locations = $db->query("SELECT location_id, name FROM locations WHERE type IN ('city', 'region') ORDER BY name")->fetchAll();

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN is_verified = 0 AND is_active = 1 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        (SELECT COUNT(*) FROM car_fleet WHERE is_active = 1) as total_vehicles,
        (SELECT COUNT(*) FROM car_fleet WHERE status = 'available') as available_vehicles,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE b.status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE b.status IN ('confirmed', 'completed')) as total_bookings
    FROM car_rentals
")->fetch();

// Get vehicle types distribution
$vehicleTypes = $db->query("
    SELECT 
        car_type,
        COUNT(*) as count
    FROM car_fleet
    WHERE is_active = 1
    GROUP BY car_type
    ORDER BY count DESC
")->fetchAll();

// Get monthly rental revenue
$monthlyRevenue = $db->query("
    SELECT 
        DATE_FORMAT(b.created_at, '%b %Y') as month,
        DATE_FORMAT(b.created_at, '%Y-%m') as month_key,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings
    FROM bookings b
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    WHERE b.booking_type = 'car_rental' AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$revenues = [];
foreach ($monthlyRevenue as $data) {
    $months[] = $data['month'];
    $revenues[] = $data['revenue'];
}
?>

<style>
/* Car Rentals Management Styles */

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
    box-shadow: var(--shadow-md);
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
    padding: 10px 12px;
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
    padding: 10px 20px;
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
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.reset-btn:hover {
    background: var(--booking-gray-dark);
}

/* Rental Cards Grid */
.rentals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.rental-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
    position: relative;
}

.rental-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.rental-card-header {
    padding: 20px;
    background: linear-gradient(135deg, var(--booking-gray-light) 0%, var(--booking-white) 100%);
    border-bottom: 1px solid var(--booking-border);
    position: relative;
}

.company-logo {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-md);
    object-fit: cover;
    background: var(--booking-white);
    margin-bottom: 12px;
}

.company-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.company-location {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    display: flex;
    align-items: center;
    gap: 4px;
}

.status-badges {
    position: absolute;
    top: 20px;
    right: 20px;
    display: flex;
    gap: 8px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
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

.rental-card-body {
    padding: 16px 20px;
}

.fleet-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--booking-border);
}

.fleet-stat {
    text-align: center;
    flex: 1;
}

.fleet-stat-number {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.fleet-stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.performance-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.performance-item {
    background: var(--booking-gray-light);
    padding: 10px;
    border-radius: var(--radius-sm);
    text-align: center;
}

.performance-value {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--booking-text);
}

.performance-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.vehicle-types {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.vehicle-type-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: var(--booking-gray-light);
    border-radius: 20px;
    font-size: 0.625rem;
}

.vehicle-type-tag i {
    font-size: 0.75rem;
}

.rental-card-footer {
    padding: 12px 20px;
    background: var(--booking-gray-light);
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.owner-info {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.owner-info i {
    margin-right: 4px;
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
}

.action-icon.view {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.action-icon.edit {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.action-icon.verify {
    background: rgba(0,128,9,0.1);
    color: var(--booking-success);
}

.action-icon.deactivate {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
}

.action-icon:hover {
    transform: translateY(-2px);
}

/* Chart Section */
.chart-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-container {
    height: 250px;
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
    
    .rentals-grid {
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
    
    .bulk-actions {
        flex-wrap: wrap;
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

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-car-front"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Rental Companies</div>
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
            <i class="bi bi-truck"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_vehicles']); ?></div>
        <div class="stat-label">Total Vehicles</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['available_vehicles']); ?></div>
        <div class="stat-label">Available Now</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
</div>

<!-- Revenue Chart -->
<?php if (!empty($monthlyRevenue)): ?>
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Rental Revenue Trend (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Revenue
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="revenueChart"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="cars.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Company name, address..." value="<?php echo htmlspecialchars($search); ?>">
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
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                    <option value="revenue_desc" <?php echo $sort == 'revenue_desc' ? 'selected' : ''; ?>>Highest Revenue</option>
                    <option value="bookings_desc" <?php echo $sort == 'bookings_desc' ? 'selected' : ''; ?>>Most Bookings</option>
                    <option value="fleet_desc" <?php echo $sort == 'fleet_desc' ? 'selected' : ''; ?>>Largest Fleet</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="cars.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions Bar -->
<form method="POST" action="cars.php" id="bulkForm">
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

<!-- Rentals Grid -->
<div class="rentals-grid">
    <?php if (empty($rentals)): ?>
    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: var(--booking-white); border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-car-front" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px; color: var(--booking-text-light);">No car rental companies found matching your criteria</p>
        <a href="cars.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <?php foreach ($rentals as $rental): ?>
    <div class="rental-card" data-rental-id="<?php echo $rental['rental_id']; ?>">
        <div class="rental-card-header">
            <div class="status-badges">
                <?php if ($rental['is_verified']): ?>
                <span class="status-badge status-verified">
                    <i class="bi bi-shield-check"></i> Verified
                </span>
                <?php else: ?>
                <span class="status-badge status-pending">
                    <i class="bi bi-clock"></i> Pending
                </span>
                <?php endif; ?>
                
                <?php if ($rental['is_active']): ?>
                <span class="status-badge status-active">
                    <i class="bi bi-check-circle"></i> Active
                </span>
                <?php else: ?>
                <span class="status-badge status-inactive">
                    <i class="bi bi-x-circle"></i> Inactive
                </span>
                <?php endif; ?>
            </div>
            
            <?php if ($rental['logo']): ?>
            <img src="<?php echo getImageUrl($rental['logo'], 'car'); ?>" class="company-logo" alt="<?php echo sanitize($rental['company_name']); ?>">
            <?php else: ?>
            <div class="company-logo" style="background: var(--booking-gray-light); display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-car-front" style="font-size: 2rem; color: var(--booking-text-light);"></i>
            </div>
            <?php endif; ?>
            
            <div class="company-name"><?php echo sanitize($rental['company_name']); ?></div>
            <div class="company-location">
                <i class="bi bi-geo-alt"></i> <?php echo sanitize($rental['location_name'] ?? 'Location not set'); ?>
            </div>
        </div>
        
        <div class="rental-card-body">
            <div class="fleet-stats">
                <div class="fleet-stat">
                    <div class="fleet-stat-number"><?php echo $rental['fleet_count']; ?></div>
                    <div class="fleet-stat-label">Total Vehicles</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-number"><?php echo $rental['active_fleet']; ?></div>
                    <div class="fleet-stat-label">Active</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-number"><?php echo number_format($rental['total_bookings']); ?></div>
                    <div class="fleet-stat-label">Bookings</div>
                </div>
            </div>
            
            <div class="performance-stats">
                <div class="performance-item">
                    <div class="performance-value"><?php echo formatPrice($rental['total_revenue']); ?></div>
                    <div class="performance-label">Total Revenue</div>
                </div>
                <div class="performance-item">
                    <div class="performance-value"><?php echo $rental['total_bookings'] > 0 ? number_format($rental['total_revenue'] / $rental['total_bookings'], 0) : 0; ?> RWF</div>
                    <div class="performance-label">Avg. Booking</div>
                </div>
            </div>
            
            <?php
            // Get vehicle types for this rental
            $stmt = $db->prepare("
                SELECT car_type, COUNT(*) as count 
                FROM car_fleet 
                WHERE rental_id = ? AND is_active = 1 
                GROUP BY car_type 
                LIMIT 3
            ");
            $stmt->execute([$rental['rental_id']]);
            $types = $stmt->fetchAll();
            ?>
            <?php if (!empty($types)): ?>
            <div class="vehicle-types">
                <?php foreach ($types as $type): ?>
                <span class="vehicle-type-tag">
                    <i class="bi bi-car-front"></i>
                    <?php echo ucfirst($type['car_type']); ?> (<?php echo $type['count']; ?>)
                </span>
                <?php endforeach; ?>
                <?php if ($rental['fleet_count'] > 3): ?>
                <span class="vehicle-type-tag">
                    +<?php echo $rental['fleet_count'] - 3; ?> more
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="rental-card-footer">
            <div class="owner-info">
                <i class="bi bi-person"></i> <?php echo sanitize($rental['owner_first'] . ' ' . $rental['owner_last']); ?>
            </div>
            <div class="card-actions">
                <input type="checkbox" class="rental-checkbox" value="<?php echo $rental['rental_id']; ?>" style="margin-right: 8px;">
                <a href="car-detail.php?id=<?php echo $rental['rental_id']; ?>" class="action-icon view" title="View Details">
                    <i class="bi bi-eye"></i>
                </a>
                <a href="edit-car.php?id=<?php echo $rental['rental_id']; ?>" class="action-icon edit" title="Edit">
                    <i class="bi bi-pencil"></i>
                </a>
                <?php if (!$rental['is_verified'] && $rental['is_active']): ?>
                <a href="?action=verify&id=<?php echo $rental['rental_id']; ?>" class="action-icon verify" title="Verify" onclick="return confirm('Verify this company?')">
                    <i class="bi bi-shield-check"></i>
                </a>
                <?php endif; ?>
                <?php if ($rental['is_active']): ?>
                <a href="?action=deactivate&id=<?php echo $rental['rental_id']; ?>" class="action-icon deactivate" title="Deactivate" onclick="return confirm('Deactivate this company?')">
                    <i class="bi bi-eye-slash"></i>
                </a>
                <?php else: ?>
                <a href="?action=activate&id=<?php echo $rental['rental_id']; ?>" class="action-icon verify" title="Activate" onclick="return confirm('Activate this company?')">
                    <i class="bi bi-eye"></i>
                </a>
                <?php endif; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
<?php if (!empty($monthlyRevenue)): ?>
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

// Bulk selection
let selectedRentals = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.rental-checkbox:checked');
    selectedRentals.clear();
    checkboxes.forEach(cb => selectedRentals.add(cb.value));
    
    const count = selectedRentals.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all
    const allCheckboxes = document.querySelectorAll('.rental-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.rental-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// Add event listeners
document.querySelectorAll('.rental-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.rental-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

// Handle bulk form submission
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.rental-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one company');
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
        input.name = 'selected_rentals[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

// Initialize
updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>