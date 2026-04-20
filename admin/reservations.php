<?php
$pageTitle = 'Reservations Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$reservationId = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Update reservation status
if ($action === 'update_status' && $reservationId > 0 && isset($_POST['status'])) {
    $status = sanitize($_POST['status']);
    $stmt = $db->prepare("UPDATE table_reservations SET status = ?, updated_at = NOW() WHERE reservation_id = ?");
    $stmt->execute([$status, $reservationId]);
    $_SESSION['success'] = "Reservation status updated successfully";
    header("Location: reservations.php" . (isset($_GET['restaurant_id']) ? "?restaurant_id=" . intval($_GET['restaurant_id']) : ""));
    exit;
}

// Cancel reservation
if ($action === 'cancel' && $reservationId > 0) {
    $stmt = $db->prepare("UPDATE table_reservations SET status = 'cancelled', updated_at = NOW() WHERE reservation_id = ?");
    $stmt->execute([$reservationId]);
    $_SESSION['success'] = "Reservation cancelled successfully";
    header("Location: reservations.php" . (isset($_GET['restaurant_id']) ? "?restaurant_id=" . intval($_GET['restaurant_id']) : ""));
    exit;
}

// Delete reservation
if ($action === 'delete' && $reservationId > 0) {
    $stmt = $db->prepare("DELETE FROM table_reservations WHERE reservation_id = ?");
    $stmt->execute([$reservationId]);
    $_SESSION['success'] = "Reservation deleted successfully";
    header("Location: reservations.php" . (isset($_GET['restaurant_id']) ? "?restaurant_id=" . intval($_GET['restaurant_id']) : ""));
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$restaurantFilter = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : 0;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'date_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        tr.*,
        r.restaurant_name,
        r.stay_id,
        s.stay_name as hotel_name,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.profile_image
    FROM table_reservations tr
    LEFT JOIN restaurants r ON tr.restaurant_id = r.restaurant_id
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN users u ON tr.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (tr.confirmation_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR r.restaurant_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== 'all') {
    $sql .= " AND tr.status = ?";
    $params[] = $statusFilter;
}

if ($restaurantFilter > 0) {
    $sql .= " AND tr.restaurant_id = ?";
    $params[] = $restaurantFilter;
}

if ($dateFrom) {
    $sql .= " AND tr.reservation_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND tr.reservation_date <= ?";
    $params[] = $dateTo;
}

// Sorting
switch ($sort) {
    case 'date_asc':
        $sql .= " ORDER BY tr.reservation_date ASC, tr.reservation_time ASC";
        break;
    case 'date_desc':
        $sql .= " ORDER BY tr.reservation_date DESC, tr.reservation_time DESC";
        break;
    case 'guests_asc':
        $sql .= " ORDER BY tr.guest_count ASC";
        break;
    case 'guests_desc':
        $sql .= " ORDER BY tr.guest_count DESC";
        break;
    case 'name_asc':
        $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";
        break;
    default:
        $sql .= " ORDER BY tr.reservation_date DESC, tr.reservation_time DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM table_reservations tr
    LEFT JOIN restaurants r ON tr.restaurant_id = r.restaurant_id
    LEFT JOIN users u ON tr.user_id = u.user_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (tr.confirmation_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR r.restaurant_name LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($statusFilter !== 'all') {
    $countSql .= " AND tr.status = ?";
    $countParams[] = $statusFilter;
}
if ($restaurantFilter > 0) {
    $countSql .= " AND tr.restaurant_id = ?";
    $countParams[] = $restaurantFilter;
}
if ($dateFrom) {
    $countSql .= " AND tr.reservation_date >= ?";
    $countParams[] = $dateFrom;
}
if ($dateTo) {
    $countSql .= " AND tr.reservation_date <= ?";
    $countParams[] = $dateTo;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalReservations = $stmt->fetchColumn();
$totalPages = ceil($totalReservations / $perPage);

// Get restaurants for filter
$stmt = $db->query("SELECT restaurant_id, restaurant_name FROM restaurants WHERE is_active = 1 ORDER BY restaurant_name");
$restaurants = $stmt->fetchAll();

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(guest_count) as total_guests,
        AVG(guest_count) as avg_guests
    FROM table_reservations
")->fetch();

// Get today's reservations
$todayReservations = $db->query("
    SELECT COUNT(*) as count, SUM(guest_count) as guests
    FROM table_reservations
    WHERE reservation_date = CURDATE() AND status IN ('confirmed', 'pending')
")->fetch();

// Get upcoming reservations (next 7 days)
$upcomingReservations = $db->query("
    SELECT COUNT(*) as count, SUM(guest_count) as guests
    FROM table_reservations
    WHERE reservation_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND status IN ('confirmed', 'pending')
")->fetch();

// Get popular restaurants by reservations - FIXED: Added restaurant_id
$popularRestaurants = $db->query("
    SELECT 
        r.restaurant_id,
        r.restaurant_name,
        COUNT(tr.reservation_id) as reservation_count,
        SUM(tr.guest_count) as total_guests
    FROM restaurants r
    LEFT JOIN table_reservations tr ON r.restaurant_id = tr.restaurant_id
    WHERE tr.status IN ('confirmed', 'completed')
    GROUP BY r.restaurant_id, r.restaurant_name
    ORDER BY reservation_count DESC
    LIMIT 5
")->fetchAll();

// Status colors and icons
$statusConfig = [
    'confirmed' => ['bg' => '#e6f4ea', 'color' => '#008009', 'icon' => 'check-circle', 'label' => 'Confirmed'],
    'pending' => ['bg' => '#fff4e6', 'color' => '#ff8c00', 'icon' => 'clock', 'label' => 'Pending'],
    'completed' => ['bg' => '#e1f5fe', 'color' => '#0288d1', 'icon' => 'check-circle-fill', 'label' => 'Completed'],
    'cancelled' => ['bg' => '#fce8e8', 'color' => '#e21111', 'icon' => 'x-circle', 'label' => 'Cancelled']
];
?>

<style>


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

.stat-sub {
    font-size: 0.5625rem;
    color: var(--booking-text-lighter);
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

/* Popular Restaurants Section */
.popular-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 24px;
}

.popular-section h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.popular-list {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

.popular-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 16px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.popular-item:hover {
    background: var(--booking-blue);
    color: white;
    transform: translateY(-2px);
}

.popular-name {
    font-weight: 600;
    font-size: 0.75rem;
}

.popular-count {
    font-size: 0.625rem;
    background: rgba(0,0,0,0.1);
    padding: 2px 6px;
    border-radius: 10px;
}

/* Reservations Table */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
}

.reservations-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.reservations-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
}

.reservations-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.reservations-table tr:hover td {
    background: var(--booking-gray-light);
}

/* Status Badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

/* Guest Avatar */
.guest-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.75rem;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 6px;
}

.action-icon {
    width: 28px;
    height: 28px;
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

/* Status Update Form */
.status-form {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-select {
    padding: 4px 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    background: var(--booking-white);
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



<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div><i class="bi bi-check-circle-fill"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
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
        <div class="stat-label">Total Reservations</div>
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
        <div class="stat-icon purple">
            <i class="bi bi-people"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_guests']); ?></div>
        <div class="stat-label">Total Guests</div>
        <div class="stat-sub">Avg <?php echo number_format($stats['avg_guests'], 1); ?> per booking</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-calendar-day"></i>
        </div>
        <div class="stat-value"><?php echo number_format($todayReservations['count']); ?></div>
        <div class="stat-label">Today</div>
        <div class="stat-sub"><?php echo $todayReservations['guests']; ?> guests</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-calendar-week"></i>
        </div>
        <div class="stat-value"><?php echo number_format($upcomingReservations['count']); ?></div>
        <div class="stat-label">Next 7 Days</div>
        <div class="stat-sub"><?php echo $upcomingReservations['guests']; ?> guests</div>
    </div>
</div>

<!-- Popular Restaurants -->
<?php if (!empty($popularRestaurants)): ?>
<div class="popular-section">
    <h3><i class="bi bi-trophy"></i> Most Popular Restaurants</h3>
    <div class="popular-list">
        <?php foreach ($popularRestaurants as $rest): ?>
        <div class="popular-item" onclick="filterByRestaurant(<?php echo $rest['restaurant_id']; ?>)">
            <span class="popular-name"><?php echo sanitize($rest['restaurant_name']); ?></span>
            <span class="popular-count"><?php echo $rest['reservation_count']; ?> bookings</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="reservations.php" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Code, guest, email, restaurant..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="confirmed" <?php echo $statusFilter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Restaurant</label>
                <select name="restaurant_id">
                    <option value="0" <?php echo $restaurantFilter == 0 ? 'selected' : ''; ?>>All Restaurants</option>
                    <?php foreach ($restaurants as $rest): ?>
                    <option value="<?php echo $rest['restaurant_id']; ?>" <?php echo $restaurantFilter == $rest['restaurant_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($rest['restaurant_name']); ?>
                    </option>
                    <?php endforeach; ?>
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
                <label>Sort By</label>
                <select name="sort">
                    <option value="date_desc" <?php echo $sort == 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="date_asc" <?php echo $sort == 'date_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="guests_desc" <?php echo $sort == 'guests_desc' ? 'selected' : ''; ?>>Most Guests</option>
                    <option value="guests_asc" <?php echo $sort == 'guests_asc' ? 'selected' : ''; ?>>Least Guests</option>
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Guest Name A-Z</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="reservations.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Reservations Table -->
<div class="table-container">
    <?php if (empty($reservations)): ?>
    <div class="empty-state">
        <i class="bi bi-calendar-x" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px; color: var(--booking-text-light);">No reservations found matching your criteria</p>
        <a href="reservations.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <table class="reservations-table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Guest</th>
                <th>Restaurant</th>
                <th>Date & Time</th>
                <th>Guests</th>
                <th>Special Requests</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reservations as $res): 
                $status = $statusConfig[$res['status']] ?? $statusConfig['pending'];
                $date = new DateTime($res['reservation_date']);
                $today = new DateTime();
                $isToday = $date->format('Y-m-d') == $today->format('Y-m-d');
            ?>
            <tr>
                <td>
                    <code style="font-family: monospace; font-size: 0.6875rem;"><?php echo sanitize($res['confirmation_code']); ?></code>
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="guest-avatar">
                            <?php if ($res['profile_image']): ?>
                            <img src="<?php echo getImageUrl($res['profile_image'], 'profile'); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                            <?php echo strtoupper(substr($res['first_name'] ?? 'G', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="guest-name" style="font-weight: 600;"><?php echo sanitize($res['first_name'] . ' ' . $res['last_name']); ?></div>
                            <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo sanitize($res['email']); ?></div>
                            <?php if ($res['phone']): ?>
                            <div style="font-size: 0.5625rem; color: var(--booking-text-lighter);"><?php echo sanitize($res['phone']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="font-weight: 600;"><?php echo sanitize($res['restaurant_name']); ?></div>
                    <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo sanitize($res['hotel_name']); ?></div>
                </td>
                <td>
                    <div style="font-weight: 600;">
                        <?php echo date('M d, Y', strtotime($res['reservation_date'])); ?>
                        <?php if ($isToday): ?><span style="background: #e6f4ea; color: #008009; padding: 2px 6px; border-radius: 10px; font-size: 0.5625rem;">Today</span><?php endif; ?>
                    </div>
                    <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo date('g:i A', strtotime($res['reservation_time'])); ?></div>
                </td>
                <td>
                    <div style="font-weight: 600;"><?php echo $res['guest_count']; ?> guests</div>
                    <?php if ($res['table_preference']): ?>
                    <div style="font-size: 0.5625rem;"><?php echo ucfirst($res['table_preference']); ?> table</div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($res['special_requests']): ?>
                    <div style="max-width: 200px; font-size: 0.625rem; color: var(--booking-text-light);" title="<?php echo htmlspecialchars($res['special_requests']); ?>">
                                        <?php echo sanitize(substr($res['special_requests'], 0, 40)); ?>
                        <?php if (strlen($res['special_requests']) > 40): ?>...<?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="font-size: 0.625rem; color: var(--booking-text-lighter);">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge" style="background: <?php echo $status['bg']; ?>; color: <?php echo $status['color']; ?>;">
                        <i class="bi bi-<?php echo $status['icon']; ?>"></i>
                        <?php echo $status['label']; ?>
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <form method="POST" action="reservations.php<?php echo $restaurantFilter > 0 ? "?restaurant_id=$restaurantFilter" : ''; ?>" class="status-form" style="display: inline-flex;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="reservation_id" value="<?php echo $res['reservation_id']; ?>">
                            <select name="status" class="status-select" onchange="this.form.submit()">
                                <option value="pending" <?php echo $res['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $res['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirm</option>
                                <option value="completed" <?php echo $res['status'] == 'completed' ? 'selected' : ''; ?>>Complete</option>
                                <option value="cancelled" <?php echo $res['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancel</option>
                            </select>
                        </form>
                        <a href="?action=cancel&id=<?php echo $res['reservation_id']; ?><?php echo $restaurantFilter > 0 ? "&restaurant_id=$restaurantFilter" : ''; ?>" 
                           class="action-icon" title="Cancel" onclick="return confirm('Cancel this reservation?')">
                            <i class="bi bi-x-lg"></i>
                        </a>
                        <a href="?action=delete&id=<?php echo $res['reservation_id']; ?><?php echo $restaurantFilter > 0 ? "&restaurant_id=$restaurantFilter" : ''; ?>" 
                           class="action-icon" title="Delete" onclick="return confirm('Delete this reservation permanently?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
function filterByRestaurant(restaurantId) {
    const url = new URL(window.location.href);
    url.searchParams.set('restaurant_id', restaurantId);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>