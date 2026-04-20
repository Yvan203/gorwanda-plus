<?php
$pageTitle = 'Bookings Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle booking actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$bookingId = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Update booking status
if ($action === 'update_status' && $bookingId > 0 && isset($_POST['status'])) {
    $newStatus = sanitize($_POST['status']);
    $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = NOW() WHERE booking_id = ?");
    $stmt->execute([$newStatus, $bookingId]);
    $_SESSION['success'] = "Booking status updated to " . ucfirst($newStatus);
    header('Location: bookings.php');
    exit;
}

// Cancel booking
if ($action === 'cancel' && $bookingId > 0) {
    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    $_SESSION['success'] = "Booking cancelled successfully";
    header('Location: bookings.php');
    exit;
}

// Confirm booking
if ($action === 'confirm' && $bookingId > 0) {
    $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed', updated_at = NOW() WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    $_SESSION['success'] = "Booking confirmed successfully";
    header('Location: bookings.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_bookings']) && is_array($_POST['selected_bookings'])) {
    $selectedIds = array_map('intval', $_POST['selected_bookings']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'confirm') {
        $stmt = $db->prepare("UPDATE bookings SET status = 'confirmed', updated_at = NOW() WHERE booking_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " bookings confirmed successfully";
    } elseif ($bulkAction === 'cancel') {
        $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE booking_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " bookings cancelled successfully";
    } elseif ($bulkAction === 'complete') {
        $stmt = $db->prepare("UPDATE bookings SET status = 'completed', updated_at = NOW() WHERE booking_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " bookings marked as completed";
    }
    header('Location: bookings.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$minAmount = isset($_GET['min_amount']) ? floatval($_GET['min_amount']) : 0;
$maxAmount = isset($_GET['max_amount']) ? floatval($_GET['max_amount']) : 0;
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        b.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.room_name
            WHEN b.booking_type = 'car_rental' THEN cf.license_plate
            ELSE t.tier_name
        END as item_detail,
        CASE 
            WHEN b.booking_type = 'stay' THEN '🏨'
            WHEN b.booking_type = 'car_rental' THEN '🚗'
            ELSE '🎟️'
        END as item_icon
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status !== 'all') {
    $sql .= " AND b.status = ?";
    $params[] = $status;
}

if ($type !== 'all') {
    $sql .= " AND b.booking_type = ?";
    $params[] = $type;
}

if ($dateFrom) {
    $sql .= " AND DATE(b.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(b.created_at) <= ?";
    $params[] = $dateTo;
}

if ($minAmount > 0) {
    $sql .= " AND b.total_amount >= ?";
    $params[] = $minAmount;
}

if ($maxAmount > 0) {
    $sql .= " AND b.total_amount <= ?";
    $params[] = $maxAmount;
}

// Sorting
switch ($sort) {
    case 'reference_asc':
        $sql .= " ORDER BY b.booking_reference ASC";
        break;
    case 'reference_desc':
        $sql .= " ORDER BY b.booking_reference DESC";
        break;
    case 'amount_asc':
        $sql .= " ORDER BY b.total_amount ASC";
        break;
    case 'amount_desc':
        $sql .= " ORDER BY b.total_amount DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY b.created_at ASC";
        break;
    default:
        $sql .= " ORDER BY b.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($status !== 'all') {
    $countSql .= " AND b.status = ?";
    $countParams[] = $status;
}
if ($type !== 'all') {
    $countSql .= " AND b.booking_type = ?";
    $countParams[] = $type;
}
if ($dateFrom) {
    $countSql .= " AND DATE(b.created_at) >= ?";
    $countParams[] = $dateFrom;
}
if ($dateTo) {
    $countSql .= " AND DATE(b.created_at) <= ?";
    $countParams[] = $dateTo;
}
if ($minAmount > 0) {
    $countSql .= " AND b.total_amount >= ?";
    $countParams[] = $minAmount;
}
if ($maxAmount > 0) {
    $countSql .= " AND b.total_amount <= ?";
    $countParams[] = $maxAmount;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalBookings = $stmt->fetchColumn();
$totalPages = ceil($totalBookings / $perPage);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
        SUM(CASE WHEN booking_type = 'stay' THEN 1 ELSE 0 END) as stay_bookings,
        SUM(CASE WHEN booking_type = 'car_rental' THEN 1 ELSE 0 END) as car_bookings,
        SUM(CASE WHEN booking_type = 'attraction' THEN 1 ELSE 0 END) as attraction_bookings,
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(commission_amount), 0) as total_commission,
        COALESCE(AVG(total_amount), 0) as avg_booking_value,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount END), 0) as today_revenue,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_bookings
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
")->fetch();

// Get daily revenue for chart (last 30 days)
$dailyRevenue = $db->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as bookings,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll();

$dates = [];
$revenues = [];
$bookingsCount = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = date('M d', strtotime($date));
    $found = false;
    foreach ($dailyRevenue as $data) {
        if ($data['date'] == $date) {
            $revenues[] = $data['revenue'];
            $bookingsCount[] = $data['bookings'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $revenues[] = 0;
        $bookingsCount[] = 0;
    }
}
?>

<style>
/* Bookings Management Styles */
.bookings-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px;
    text-align: center;
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    font-size: 0.875rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }
.stat-icon.red { background: rgba(226,17,17,0.1); color: var(--booking-danger); }
.stat-icon.cyan { background: rgba(23,162,184,0.1); color: #17a2b8; }

.stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 2px;
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
    min-width: 120px;
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

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
}

.bookings-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.bookings-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    user-select: none;
}

.bookings-table th:hover {
    background: var(--booking-gray-dark);
}

.bookings-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.bookings-table tr:hover td {
    background: var(--booking-gray-light);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
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

.status-completed {
    background: #e1f5fe;
    color: #0288d1;
}

.status-cancelled {
    background: #fce8e8;
    color: var(--booking-danger);
}

.status-no_show {
    background: #f3e5f5;
    color: #7b1fa2;
}

/* Type Badges */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.type-stay {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.type-car {
    background: rgba(147,51,234,0.1);
    color: #9333ea;
}

.type-attraction {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
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

/* Action Dropdown */
.action-dropdown {
    position: relative;
    display: inline-block;
}

.action-btn-icon {
    padding: 6px 10px;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 0.75rem;
}

.action-dropdown-content {
    display: none;
    position: absolute;
    right: 0;
    background: var(--booking-white);
    min-width: 140px;
    box-shadow: var(--shadow-md);
    border-radius: var(--radius-sm);
    border: 1px solid var(--booking-border);
    z-index: 1;
}

.action-dropdown-content a {
    color: var(--booking-text);
    padding: 8px 12px;
    text-decoration: none;
    display: block;
    font-size: 0.6875rem;
    transition: all var(--transition-fast);
}

.action-dropdown-content a:hover {
    background: var(--booking-gray-light);
}

.action-dropdown:hover .action-dropdown-content {
    display: block;
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

/* Responsive */
@media (max-width: 1400px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
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

<div class="bookings-header">
    <div class="page-title">
        <h1></h1>
    </div>
</div>

<!-- Display success/error messages -->
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
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['confirmed']); ?></div>
        <div class="stat-label">Confirmed</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-check2-all"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="bi bi-x-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['cancelled']); ?></div>
        <div class="stat-label">Cancelled</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-percent"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_commission']); ?></div>
        <div class="stat-label">Commission</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-trending-up"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['avg_booking_value']); ?></div>
        <div class="stat-label">Avg. Value</div>
    </div>
</div>

<!-- Revenue Chart -->
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Revenue Trend (Last 30 Days)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Revenue
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Bookings
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="revenueChart"></canvas>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="bookings.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Reference, guest name, email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="confirmed" <?php echo $status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Type</label>
                <select name="type">
                    <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="stay" <?php echo $type == 'stay' ? 'selected' : ''; ?>>Stays</option>
                    <option value="car_rental" <?php echo $type == 'car_rental' ? 'selected' : ''; ?>>Car Rentals</option>
                    <option value="attraction" <?php echo $type == 'attraction' ? 'selected' : ''; ?>>Experiences</option>
                </select>
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="filter-group">
                <label>Min Amount</label>
                <input type="number" name="min_amount" placeholder="Min RWF" value="<?php echo $minAmount ?: ''; ?>" step="1000">
            </div>
            <div class="filter-group">
                <label>Max Amount</label>
                <input type="number" name="max_amount" placeholder="Max RWF" value="<?php echo $maxAmount ?: ''; ?>" step="1000">
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="reference_asc" <?php echo $sort == 'reference_asc' ? 'selected' : ''; ?>>Reference A-Z</option>
                    <option value="amount_desc" <?php echo $sort == 'amount_desc' ? 'selected' : ''; ?>>Highest Amount</option>
                    <option value="amount_asc" <?php echo $sort == 'amount_asc' ? 'selected' : ''; ?>>Lowest Amount</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="bookings.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions Bar -->
<form method="POST" action="bookings.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_action">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_action_type" class="bulk-action-select">
            <option value="">Bulk Action</option>
            <option value="confirm">Confirm Selected</option>
            <option value="complete">Mark as Completed</option>
            <option value="cancel">Cancel Selected</option>
        </select>
        <button type="submit" class="bulk-apply-btn" onclick="return confirm('Are you sure you want to perform this bulk action?')">Apply</button>
        <button type="button" class="bulk-apply-btn" onclick="clearSelection()" style="background: var(--booking-text-light);">Clear</button>
    </div>
</form>

<!-- Bookings Table -->
<div class="table-container">
    <table class="bookings-table">
        <thead>
            <tr>
                <th style="width: 30px;">
                    <input type="checkbox" id="selectAllHeader">
                </th>
                <th>Reference</th>
                <th>Guest</th>
                <th>Type</th>
                <th>Item</th>
                <th>Details</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Commission</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
            <tr>
                <td colspan="11" style="text-align: center; padding: 60px;">
                    <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px; color: var(--booking-text-light);">No bookings found matching your criteria</p>
                    <a href="bookings.php" class="filter-btn" style="margin-top: 12px; display: inline-block;">Clear Filters</a>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($bookings as $booking): ?>
            <tr data-booking-id="<?php echo $booking['booking_id']; ?>">
                <td>
                    <input type="checkbox" class="booking-checkbox" value="<?php echo $booking['booking_id']; ?>">
                </td>
                <td>
                    <strong style="font-family: monospace;">#<?php echo $booking['booking_reference']; ?></strong>
                </td>
                <td>
                    <div><?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?></div>
                    <small style="font-size: 0.5625rem; color: var(--booking-text-light);"><?php echo sanitize($booking['email']); ?></small>
                </td>
                <td>
                    <span class="type-badge type-<?php echo $booking['booking_type'] == 'stay' ? 'stay' : ($booking['booking_type'] == 'car_rental' ? 'car' : 'attraction'); ?>">
                        <?php echo $booking['item_icon']; ?> <?php echo ucfirst(str_replace('_', ' ', $booking['booking_type'])); ?>
                    </span>
                </td>
                <td>
                    <div><?php echo sanitize($booking['item_name']); ?></div>
                    <?php if ($booking['item_detail']): ?>
                    <small style="font-size: 0.5625rem; color: var(--booking-text-light);"><?php echo sanitize($booking['item_detail']); ?></small>
                    <?php endif; ?>
                </td>
                <td style="font-size: 0.6875rem;">
                    <?php if ($booking['booking_type'] == 'stay'): ?>
                        <?php if ($booking['check_in_date']): ?>
                        📅 <?php echo date('M d', strtotime($booking['check_in_date'])); ?> - <?php echo date('M d', strtotime($booking['check_out_date'])); ?>
                        <br>🏠 <?php echo $booking['num_nights']; ?> nights • 👥 <?php echo $booking['num_guests']; ?> guests
                        <?php endif; ?>
                    <?php elseif ($booking['booking_type'] == 'car_rental'): ?>
                        <?php if ($booking['pickup_date']): ?>
                        📍 Pickup: <?php echo date('M d', strtotime($booking['pickup_date'])); ?>
                        <br>📍 Return: <?php echo date('M d', strtotime($booking['return_date'])); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($booking['experience_date']): ?>
                        📅 <?php echo date('M d, Y', strtotime($booking['experience_date'])); ?>
                        <?php if ($booking['start_time']): ?>
                        <br>⏰ <?php echo date('g:i A', strtotime($booking['start_time'])); ?>
                        <?php endif; ?>
                        <br>👥 <?php echo $booking['num_participants']; ?> participants
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="booking-amount" style="font-weight: 700; color: var(--booking-success);">
                    <?php echo formatPrice($booking['total_amount']); ?>
                </td>
                <td style="font-size: 0.6875rem; color: var(--booking-text-light);">
                    <?php echo formatPrice($booking['commission_amount']); ?>
                    <br><small>(<?php echo $booking['total_amount'] > 0 ? round(($booking['commission_amount'] / $booking['total_amount']) * 100, 1) : 0; ?>%)</small>
                </td>
                <td>
                    <span class="status-badge status-<?php echo $booking['status']; ?>">
                        <i class="bi bi-<?php echo $booking['status'] == 'confirmed' ? 'check-circle' : ($booking['status'] == 'pending' ? 'clock' : ($booking['status'] == 'completed' ? 'check2-all' : 'x-circle')); ?>"></i>
                        <?php echo ucfirst($booking['status']); ?>
                    </span>
                </td>
                <td>
                    <div class="action-dropdown">
                        <button class="action-btn-icon"><i class="bi bi-three-dots-vertical"></i></button>
                        <div class="action-dropdown-content">
                            <a href="booking-detail.php?id=<?php echo $booking['booking_id']; ?>">
                                <i class="bi bi-eye"></i> View Details
                            </a>
                            <?php if ($booking['status'] == 'pending'): ?>
                            <a href="?action=confirm&id=<?php echo $booking['booking_id']; ?>" onclick="return confirm('Confirm this booking?')">
                                <i class="bi bi-check-circle"></i> Confirm
                            </a>
                            <?php endif; ?>
                            <?php if ($booking['status'] == 'confirmed'): ?>
                            <a href="?action=complete&id=<?php echo $booking['booking_id']; ?>" onclick="return confirm('Mark as completed?')">
                                <i class="bi bi-check2-all"></i> Mark Completed
                            </a>
                            <?php endif; ?>
                            <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
                            <a href="?action=cancel&id=<?php echo $booking['booking_id']; ?>" onclick="return confirm('Cancel this booking?')" style="color: var(--booking-danger);">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <?php endif; ?>
                        </div>
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
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
const ctx = document.getElementById('revenueChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dates); ?>,
        datasets: [
            {
                label: 'Revenue (RWF)',
                data: <?php echo json_encode($revenues); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-revenue'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode($bookingsCount); ?>,
                borderColor: '#008009',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                tension: 0.4,
                yAxisID: 'y-bookings'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { font: { size: 10 } } },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 10,
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Revenue (RWF)') {
                            return 'Revenue: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'left',
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    },
                    font: { size: 9 }
                }
            },
            'y-bookings': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: {
                    stepSize: 1,
                    font: { size: 9 }
                }
            }
        }
    }
});

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

// Bulk selection
let selectedBookings = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.booking-checkbox:checked');
    selectedBookings.clear();
    checkboxes.forEach(cb => selectedBookings.add(cb.value));
    
    const count = selectedBookings.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    const allCheckboxes = document.querySelectorAll('.booking-checkbox');
    const selectAll = document.getElementById('selectAll');
    const selectAllHeader = document.getElementById('selectAllHeader');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
        if (selectAllHeader) selectAllHeader.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
        if (selectAllHeader) selectAllHeader.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.booking-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

document.querySelectorAll('.booking-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.booking-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

const selectAllHeader = document.getElementById('selectAllHeader');
if (selectAllHeader) {
    selectAllHeader.addEventListener('change', function(e) {
        document.querySelectorAll('.booking-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.booking-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one booking');
        return;
    }
    
    const action = document.querySelector('[name="bulk_action_type"]').value;
    if (!action) {
        e.preventDefault();
        alert('Please select a bulk action');
        return;
    }
    
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_bookings[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>