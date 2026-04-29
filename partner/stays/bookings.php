<?php
$pageTitle = 'Booking Management';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// HANDLE BOOKING ACTIONS
// ============================================

// Update booking status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $bookingId = intval($_POST['booking_id']);
    $newStatus = $_POST['status'];
    $cancellationReason = isset($_POST['cancellation_reason']) ? $_POST['cancellation_reason'] : null;

    // Verify ownership before updating
    $stmt = $db->prepare("
        SELECT b.booking_id 
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE b.booking_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            UPDATE bookings 
            SET status = ?, cancellation_reason = ?, updated_at = NOW()
            WHERE booking_id = ?
        ");
        $stmt->execute([$newStatus, $cancellationReason, $bookingId]);
        $success = "Booking status updated to " . ucfirst($newStatus);
    }
}

// Check-in action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in'])) {
    $bookingId = intval($_POST['booking_id']);

    $stmt = $db->prepare("
        SELECT b.booking_id 
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE b.booking_id = ? AND s.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$bookingId, $userId]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE bookings SET status = 'completed', updated_at = NOW() WHERE booking_id = ?");
        $stmt->execute([$bookingId]);
        $success = "Guest checked in successfully. Booking marked as completed.";
    }
}

// Check-out action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_out'])) {
    $bookingId = intval($_POST['booking_id']);

    $stmt = $db->prepare("
        SELECT b.booking_id 
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE b.booking_id = ? AND s.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$bookingId, $userId]);

    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE bookings SET status = 'completed', updated_at = NOW() WHERE booking_id = ?");
        $stmt->execute([$bookingId]);
        $success = "Guest checked out successfully. Booking marked as completed.";
    }
}

// ============================================
// GET FILTERS
// ============================================

$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// ============================================
// GET PROPERTIES FOR FILTER
// ============================================

$stmt = $db->prepare("SELECT stay_id, stay_name FROM stays WHERE owner_id = ? ORDER BY stay_name");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// ============================================
// BUILD BOOKINGS QUERY
// ============================================

$whereConditions = ["s.owner_id = ?"];
$params = [$userId];

if ($propertyId > 0) {
    $whereConditions[] = "s.stay_id = ?";
    $params[] = $propertyId;
}

// Map status values
$validStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
if ($status !== 'all' && in_array($status, $validStatuses)) {
    $whereConditions[] = "b.status = ?";
    $params[] = $status;
}

// Date range filter
if ($dateRange === 'upcoming') {
    $whereConditions[] = "b.check_in_date >= CURDATE()";
    $whereConditions[] = "b.status IN ('confirmed', 'pending')";
} elseif ($dateRange === 'current') {
    $whereConditions[] = "CURDATE() BETWEEN b.check_in_date AND b.check_out_date";
    $whereConditions[] = "b.status IN ('confirmed', 'pending')";
} elseif ($dateRange === 'past') {
    $whereConditions[] = "b.check_out_date < CURDATE()";
} elseif ($dateRange === 'custom' && $fromDate && $toDate) {
    $whereConditions[] = "b.created_at BETWEEN ? AND ?";
    $params[] = $fromDate . ' 00:00:00';
    $params[] = $toDate . ' 23:59:59';
}
// 'all' - no date filter

// Search filter
if (!empty($search)) {
    $whereConditions[] = "(b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $whereConditions);

// ============================================
// FETCH BOOKINGS
// ============================================

$sql = "
    SELECT 
        b.*,
        s.stay_id,
        s.stay_name,
        s.city,
        s.main_image as property_image,
        sr.room_name,
        sr.room_id,
        sr.base_price,
        u.user_id as guest_id,
        u.first_name as guest_first_name,
        u.last_name as guest_last_name,
        u.email as guest_email,
        u.phone as guest_phone,
        DATEDIFF(b.check_out_date, b.check_in_date) as nights,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until_checkin
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY b.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// ============================================
// CALCULATE STATISTICS
// ============================================

$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'no_show' => 0,
    'today_checkins' => 0,
    'today_checkouts' => 0,
    'total_revenue' => 0,
    'avg_booking_value' => 0
];

$today = date('Y-m-d');

foreach ($bookings as $booking) {
    $stats['total']++;

    if (isset($stats[$booking['status']])) {
        $stats[$booking['status']]++;
    }

    if (in_array($booking['status'], ['confirmed', 'completed'])) {
        $stats['total_revenue'] += $booking['total_amount'];
    }

    if ($booking['status'] === 'confirmed' && $booking['check_in_date'] === $today) {
        $stats['today_checkins']++;
    }

    if ($booking['status'] === 'confirmed' && $booking['check_out_date'] === $today) {
        $stats['today_checkouts']++;
    }
}

if ($stats['total'] > 0) {
    $stats['avg_booking_value'] = $stats['total_revenue'] / $stats['total'];
}

// ============================================
// FETCH UPCOMING CHECK-INS (NEXT 7 DAYS)
// ============================================

$stmt = $db->prepare("
    SELECT 
        b.*, 
        s.stay_name, 
        sr.room_name, 
        u.first_name, 
        u.last_name,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ? 
    AND b.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND b.status = 'confirmed'
    ORDER BY b.check_in_date ASC
");
$stmt->execute([$userId]);
$upcomingCheckins = $stmt->fetchAll();

// ============================================
// CALENDAR DATA (NEXT 30 DAYS)
// ============================================

$stmt = $db->prepare("
    SELECT 
        b.check_in_date as date,
        COUNT(*) as checkins,
        SUM(b.num_guests) as guests
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
    AND b.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND b.status IN ('confirmed', 'pending')
    GROUP BY b.check_in_date
");
$stmt->execute([$userId]);
$calendarData = $stmt->fetchAll();

$calendar = [];
foreach ($calendarData as $item) {
    $calendar[$item['date']] = $item;
}

// Status labels and colors
$statusLabels = [
    'pending' => ['Pending', 'warning'],
    'confirmed' => ['Confirmed', 'success'],
    'completed' => ['Completed', 'secondary'],
    'cancelled' => ['Cancelled', 'danger'],
    'no_show' => ['No Show', 'dark']
];
?>

<style>
    /* Bookings Specific Styles */
    .bookings-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .bookings-title h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 4px 0;
    }

    .bookings-title p {
        font-size: 0.8125rem;
        color: #666;
        margin: 0;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #003b95;
        margin-bottom: 4px;
    }

    .stat-label {
        font-size: 0.75rem;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .stat-trend {
        font-size: 0.6875rem;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
    }

    /* Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
    }

    .filter-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filter-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
    }

    .filter-select,
    .filter-input {
        padding: 8px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.8125rem;
        background: white;
        min-width: 150px;
    }

    .search-box {
        position: relative;
        flex: 1;
        min-width: 250px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
    }

    .search-box input {
        width: 100%;
        padding: 10px 16px 10px 38px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.8125rem;
    }

    /* Quick Actions */
    .quick-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .quick-action-card {
        flex: 1;
        min-width: 200px;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: all 0.2s;
        cursor: pointer;
    }

    .quick-action-card:hover {
        border-color: #003b95;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .quick-action-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #e8f0fe;
        color: #003b95;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .quick-action-info h4 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 4px 0;
    }

    .quick-action-info p {
        font-size: 0.75rem;
        color: #666;
        margin: 0;
    }

    /* Bookings Table */
    .bookings-table-container {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .bookings-table {
        width: 100%;
        border-collapse: collapse;
    }

    .bookings-table th {
        text-align: left;
        padding: 16px 20px;
        font-size: 0.75rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .bookings-table td {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 0.8125rem;
        vertical-align: middle;
    }

    .bookings-table tr:hover td {
        background: #f8faff;
    }

    .booking-ref {
        font-family: monospace;
        font-weight: 600;
        color: #003b95;
        font-size: 0.8125rem;
    }

    .guest-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .guest-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #e8f0fe;
        color: #003b95;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .guest-name {
        font-weight: 600;
    }

    .guest-email {
        font-size: 0.6875rem;
        color: #666;
    }

    .property-name {
        font-weight: 600;
    }

    .room-name {
        font-size: 0.6875rem;
        color: #666;
    }

    .date-main {
        font-weight: 600;
    }

    .date-range {
        font-size: 0.6875rem;
        color: #666;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        border-radius: 100px;
        font-size: 0.6875rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-badge.warning {
        background: #fff4e6;
        color: #e67e22;
    }

    .status-badge.success {
        background: #e6f4ea;
        color: #008009;
    }

    .status-badge.secondary {
        background: #f3f4f6;
        color: #666;
    }

    .status-badge.danger {
        background: #fce8e8;
        color: #e21111;
    }

    .status-badge.dark {
        background: #e5e7eb;
        color: #424242;
    }

    .amount-cell {
        font-weight: 700;
        color: #008009;
    }

    .action-cell {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .action-btn {
        padding: 6px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: white;
        color: #1a1a1a;
        font-size: 0.6875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .action-btn:hover {
        background: #e8f0fe;
        border-color: #003b95;
        color: #003b95;
    }

    .action-btn.danger-btn:hover {
        background: #fce8e8;
        border-color: #e21111;
        color: #e21111;
    }

    /* Calendar Mini */
    .calendar-mini {
        background: white;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        padding: 20px;
        margin-bottom: 24px;
    }

    .calendar-title {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
        text-align: center;
    }

    .calendar-day {
        padding: 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        position: relative;
        background: #f9fafb;
    }

    .calendar-day.has-checkins {
        background: #e8f0fe;
        color: #003b95;
        font-weight: 600;
    }

    /* Alert Messages */
    .alert {
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .alert-success {
        background: #e6f4ea;
        color: #008009;
        border: 1px solid #b7dfc4;
    }

    /* Buttons */
    .btn-primary {
        background: #003b95;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.875rem;
    }

    .btn-primary:hover {
        background: #002d73;
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #1a1a1a;
        border: 1px solid #e5e7eb;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.875rem;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .btn-outline {
        background: white;
        color: #003b95;
        border: 1px solid #003b95;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.875rem;
    }

    .btn-outline:hover {
        background: #e8f0fe;
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 0.75rem;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {

        .stats-grid,
        .quick-actions {
            grid-template-columns: 1fr;
        }

        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-group {
            flex-direction: column;
            align-items: stretch;
        }

        .filter-select,
        .filter-input,
        .search-box {
            width: 100%;
        }

        .bookings-table {
            display: block;
            overflow-x: auto;
        }
    }
</style>

<div class="bookings-header">
    <div class="bookings-title">
        <h1>Booking Management</h1>
        <p>Manage all your reservations, check-ins, and guest communications</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportBookings()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <?php echo htmlspecialchars($success); ?>
        <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; cursor: pointer; font-size: 1.2rem;">&times;</button>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-trend">
            <span><?php echo $stats['pending']; ?> pending</span>
            <span><?php echo $stats['confirmed']; ?> confirmed</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['today_checkins']; ?></div>
        <div class="stat-label">Check-ins Today</div>
        <div class="stat-trend">
            <span><?php echo $stats['today_checkouts']; ?> check-outs</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value"><?php echo count($upcomingCheckins); ?></div>
        <div class="stat-label">Upcoming (7 days)</div>
        <div class="stat-trend">
            <span>Next: <?php echo !empty($upcomingCheckins) ? date('M d', strtotime($upcomingCheckins[0]['check_in_date'])) : 'None'; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_revenue']); ?> RWF</div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-trend">
            <span>Avg: <?php echo number_format($stats['avg_booking_value']); ?> RWF</span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <div class="quick-action-card" onclick="filterByStatus('pending')">
        <div class="quick-action-icon">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['pending']; ?> Pending</h4>
            <p>Require your attention</p>
        </div>
    </div>

    <div class="quick-action-card" onclick="filterByStatus('confirmed')">
        <div class="quick-action-icon">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['confirmed']; ?> Confirmed</h4>
            <p>Upcoming stays</p>
        </div>
    </div>

    <div class="quick-action-card" onclick="filterByDate('current')">
        <div class="quick-action-icon">
            <i class="bi bi-calendar-day"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['today_checkins']; ?> Today</h4>
            <p>Check-ins & check-outs</p>
        </div>
    </div>

    <div class="quick-action-card" onclick="filterByStatus('completed')">
        <div class="quick-action-icon">
            <i class="bi bi-door-open"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['completed']; ?> Completed</h4>
            <p>Past stays</p>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" action="bookings.php" class="filter-bar">
    <div class="filter-group">
        <span class="filter-label">Property:</span>
        <select name="property" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Properties</option>
            <?php foreach ($properties as $prop): ?>
                <option value="<?php echo $prop['stay_id']; ?>" <?php echo $propertyId == $prop['stay_id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($prop['stay_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="filter-group">
        <span class="filter-label">Status:</span>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed" <?php echo $status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>

    <div class="filter-group">
        <span class="filter-label">Date Range:</span>
        <select name="date_range" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?php echo $dateRange == 'all' ? 'selected' : ''; ?>>All Dates</option>
            <option value="upcoming" <?php echo $dateRange == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
            <option value="current" <?php echo $dateRange == 'current' ? 'selected' : ''; ?>>Current Stays</option>
            <option value="past" <?php echo $dateRange == 'past' ? 'selected' : ''; ?>>Past</option>
            <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
        </select>
    </div>

    <?php if ($dateRange == 'custom'): ?>
        <div class="filter-group">
            <input type="date" name="from_date" class="filter-input" value="<?php echo htmlspecialchars($fromDate); ?>" style="min-width: auto;">
            <span>to</span>
            <input type="date" name="to_date" class="filter-input" value="<?php echo htmlspecialchars($toDate); ?>" style="min-width: auto;">
            <button type="submit" class="btn-primary btn-sm">Apply</button>
        </div>
    <?php endif; ?>

    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" name="search" placeholder="Search by guest name, email, or booking ref..." value="<?php echo htmlspecialchars($search); ?>">
    </div>

    <?php if ($propertyId || $status != 'all' || $dateRange != 'all' || $search): ?>
        <a href="bookings.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Mini Calendar -->
<?php if (!empty($calendarData)): ?>
    <div class="calendar-mini">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 class="calendar-title">📅 Next 30 Days - Check-ins</h3>
            <a href="calendar.php" class="btn-outline btn-sm">Full Calendar →</a>
        </div>
        <div class="calendar-grid">
            <?php
            $today = new DateTime();
            for ($i = 0; $i < 30; $i++):
                $date = clone $today;
                $date->modify("+$i days");
                $dateStr = $date->format('Y-m-d');
                $dayName = $date->format('D');
                $dayNum = $date->format('j');
                $hasCheckins = isset($calendar[$dateStr]);
                $count = $hasCheckins ? $calendar[$dateStr]['checkins'] : 0;
            ?>
                <div class="calendar-day <?php echo $hasCheckins ? 'has-checkins' : ''; ?>"
                    title="<?php echo $hasCheckins ? $count . ' check-ins, ' . $calendar[$dateStr]['guests'] . ' guests' : ''; ?>">
                    <div style="font-size: 0.625rem; color: #999;"><?php echo $dayName; ?></div>
                    <div style="font-weight: 600;"><?php echo $dayNum; ?></div>
                    <?php if ($hasCheckins): ?>
                        <div style="font-size: 0.625rem; color: #003b95;"><?php echo $count; ?> check-in<?php echo $count > 1 ? 's' : ''; ?></div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Bookings Table -->
<div class="bookings-table-container">
    <table class="bookings-table">
        <thead>
            <tr>
                <th>Booking Ref</th>
                <th>Guest</th>
                <th>Property / Room</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Nights</th>
                <th>Guests</th>
                <th>Status</th>
                <th>Amount</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 60px 20px;">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc; display: block; margin-bottom: 16px;"></i>
                        <h4 style="margin: 0 0 8px 0; color: #666;">No bookings found</h4>
                        <p style="margin: 0; color: #999; font-size: 0.875rem;">
                            <?php if ($status != 'all' || $dateRange != 'all' || $search): ?>
                                Try adjusting your filters or <a href="bookings.php" style="color: #003b95;">clear all filters</a>
                            <?php else: ?>
                                Bookings will appear here when guests make reservations
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking):
                    $statusInfo = isset($statusLabels[$booking['status']]) ? $statusLabels[$booking['status']] : ['Unknown', 'dark'];
                ?>
                    <tr>
                        <td>
                            <span class="booking-ref">#<?php echo htmlspecialchars($booking['booking_reference']); ?></span>
                        </td>
                        <td>
                            <div class="guest-info">
                                <div class="guest-avatar">
                                    <?php echo strtoupper(substr($booking['guest_first_name'] ?? 'G', 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="guest-name">
                                        <?php echo htmlspecialchars(($booking['guest_first_name'] ?? 'Guest') . ' ' . ($booking['guest_last_name'] ?? '')); ?>
                                    </div>
                                    <div class="guest-email"><?php echo htmlspecialchars($booking['guest_email'] ?? 'No email'); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="property-name"><?php echo htmlspecialchars($booking['stay_name']); ?></div>
                            <div class="room-name"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                        </td>
                        <td>
                            <div class="date-main"><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></div>
                            <?php if ($booking['days_until_checkin'] !== null && $booking['days_until_checkin'] >= 0): ?>
                                <div class="date-range">
                                    <?php
                                    if ($booking['days_until_checkin'] == 0) echo '<span style="color: #008009;">Today</span>';
                                    elseif ($booking['days_until_checkin'] == 1) echo '<span style="color: #003b95;">Tomorrow</span>';
                                    else echo 'In ' . $booking['days_until_checkin'] . ' days';
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="date-main"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></div>
                        </td>
                        <td><?php echo $booking['nights']; ?></td>
                        <td><?php echo $booking['num_guests']; ?></td>
                        <td>
                            <span class="status-badge <?php echo $statusInfo[1]; ?>">
                                <?php echo $statusInfo[0]; ?>
                            </span>
                        </td>
                        <td class="amount-cell"><?php echo number_format($booking['total_amount']); ?> RWF</td>
                        <td>
                            <div class="action-cell">
                                <?php if ($booking['status'] == 'pending'): ?>
                                    <button class="action-btn" onclick="confirmBooking(<?php echo $booking['booking_id']; ?>)" title="Confirm Booking">
                                        <i class="bi bi-check-lg"></i> Confirm
                                    </button>
                                <?php endif; ?>

                                <?php if ($booking['status'] == 'confirmed'): ?>
                                    <button class="action-btn" onclick="checkInGuest(<?php echo $booking['booking_id']; ?>)" title="Check-in Guest">
                                        <i class="bi bi-door-open"></i> Check-in
                                    </button>
                                    <button class="action-btn" onclick="checkOutGuest(<?php echo $booking['booking_id']; ?>)" title="Check-out Guest">
                                        <i class="bi bi-door-closed"></i> Check-out
                                    </button>
                                <?php endif; ?>

                                <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
                                    <button class="action-btn danger-btn" onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" title="Cancel Booking">
                                        <i class="bi bi-x-lg"></i> Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    // Filter functions
    function filterByStatus(status) {
        window.location.href = 'bookings.php?status=' + status;
    }

    function filterByDate(range) {
        window.location.href = 'bookings.php?date_range=' + range;
    }

    // Booking action functions
    function confirmBooking(bookingId) {
        if (confirm('Are you sure you want to confirm this booking?')) {
            submitAction(bookingId, 'confirmed');
        }
    }

    function cancelBooking(bookingId) {
        var reason = prompt('Please enter a reason for cancellation:');
        if (reason !== null) {
            submitAction(bookingId, 'cancelled', reason);
        }
    }

    function checkInGuest(bookingId) {
        if (confirm('Check in this guest? This will mark the booking as completed.')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="booking_id" value="' + bookingId + '"><input type="hidden" name="check_in" value="1">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    function checkOutGuest(bookingId) {
        if (confirm('Check out this guest? This will mark the booking as completed.')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="booking_id" value="' + bookingId + '"><input type="hidden" name="check_out" value="1">';
            document.body.appendChild(form);
            form.submit();
        }
    }

    function submitAction(bookingId, status, reason) {
        var form = document.createElement('form');
        form.method = 'POST';
        var html = '<input type="hidden" name="booking_id" value="' + bookingId + '">';
        html += '<input type="hidden" name="status" value="' + status + '">';
        if (reason) {
            html += '<input type="hidden" name="cancellation_reason" value="' + reason + '">';
        }
        html += '<input type="hidden" name="update_status" value="1">';
        form.innerHTML = html;
        document.body.appendChild(form);
        form.submit();
    }

    // Export function
    function exportBookings() {
        var csv = "Booking Ref,Guest Name,Email,Property,Room,Check-in,Check-out,Nights,Guests,Status,Amount\n";

        <?php foreach ($bookings as $booking): ?>
            csv += "#<?php echo addslashes($booking['booking_reference']); ?>," +
                "\"<?php echo addslashes($booking['guest_first_name'] . ' ' . $booking['guest_last_name']); ?>\"," +
                "\"<?php echo addslashes($booking['guest_email']); ?>\"," +
                "\"<?php echo addslashes($booking['stay_name']); ?>\"," +
                "\"<?php echo addslashes($booking['room_name']); ?>\"," +
                "<?php echo $booking['check_in_date']; ?>," +
                "<?php echo $booking['check_out_date']; ?>," +
                "<?php echo $booking['nights']; ?>," +
                "<?php echo $booking['num_guests']; ?>," +
                "<?php echo $booking['status']; ?>," +
                "<?php echo $booking['total_amount']; ?>\n";
        <?php endforeach; ?>

        var blob = new Blob([csv], {
            type: 'text/csv'
        });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'bookings_export_<?php echo date('Y-m-d'); ?>.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }
</script>

<?php require_once 'includes/stays_footer.php'; ?>