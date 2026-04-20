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
    $newStatus = sanitize($_POST['status']);
    $cancellationReason = isset($_POST['cancellation_reason']) ? sanitize($_POST['cancellation_reason']) : null;
    
    // Verify ownership
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        SET b.status = ?, b.cancellation_reason = ?, b.updated_at = NOW()
        WHERE b.booking_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$newStatus, $cancellationReason, $bookingId, $userId]);
    
    $success = "Booking status updated to " . ucfirst($newStatus);
}

// Send message to guest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $bookingId = intval($_POST['booking_id']);
    $message = sanitize($_POST['message']);
    $subject = sanitize($_POST['subject'] ?? 'Question about your booking');
    
    // Get booking details
    $stmt = $db->prepare("
        SELECT b.*, u.user_id as guest_id, u.email, u.first_name, s.stay_name
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        JOIN users u ON b.user_id = u.user_id
        WHERE b.booking_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Insert message
        $stmt = $db->prepare("
            INSERT INTO messages (sender_id, receiver_id, booking_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $booking['guest_id'], $bookingId, $subject, $message]);
        
        // In production, also send email
        $success = "Message sent to guest successfully";
    }
}

// Check-in/Check-out actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in'])) {
    $bookingId = intval($_POST['booking_id']);
    
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        SET b.status = 'checked_in', b.updated_at = NOW()
        WHERE b.booking_id = ? AND s.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$bookingId, $userId]);
    
    $success = "Guest checked in successfully";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_out'])) {
    $bookingId = intval($_POST['booking_id']);
    
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        JOIN stays s ON sr.stay_id = s.stay_id
        SET b.status = 'completed', b.updated_at = NOW()
        WHERE b.booking_id = ? AND s.owner_id = ? AND b.status = 'checked_in'
    ");
    $stmt->execute([$bookingId, $userId]);
    
    $success = "Guest checked out successfully";
}

// ============================================
// GET FILTERS
// ============================================

$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateRange = isset($_GET['date_range']) ? sanitize($_GET['date_range']) : 'upcoming';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Build query conditions
$conditions = ["s.owner_id = ?"];
$params = [$userId];

if ($propertyId > 0) {
    $conditions[] = "s.stay_id = ?";
    $params[] = $propertyId;
}

if ($status !== 'all') {
    $conditions[] = "b.status = ?";
    $params[] = $status;
}

if ($dateRange === 'upcoming') {
    $conditions[] = "b.check_in_date >= CURDATE()";
    $conditions[] = "b.status IN ('confirmed', 'pending')";
} elseif ($dateRange === 'current') {
    $conditions[] = "CURDATE() BETWEEN b.check_in_date AND b.check_out_date";
    $conditions[] = "b.status IN ('confirmed', 'checked_in')";
} elseif ($dateRange === 'past') {
    $conditions[] = "b.check_out_date < CURDATE()";
} elseif ($dateRange === 'custom' && $fromDate && $toDate) {
    $conditions[] = "b.created_at BETWEEN ? AND ?";
    $params[] = $fromDate . ' 00:00:00';
    $params[] = $toDate . ' 23:59:59';
}

if ($search) {
    $conditions[] = "(b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET BOOKINGS DATA
// ============================================

// Get all properties for filter
$stmt = $db->prepare("SELECT stay_id, stay_name FROM stays WHERE owner_id = ? ORDER BY stay_name");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Get bookings
$stmt = $db->prepare("
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
        DATEDIFF(b.check_in_date, CURDATE()) as days_until_checkin,
        CASE 
            WHEN b.status = 'confirmed' AND b.check_in_date = CURDATE() THEN 'check-in-today'
            WHEN b.status = 'confirmed' AND b.check_in_date < CURDATE() AND b.check_out_date > CURDATE() THEN 'active'
            WHEN b.status = 'checked_in' AND b.check_out_date = CURDATE() THEN 'check-out-today'
            ELSE NULL
        END as alert
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN b.status = 'pending' THEN 1
            WHEN b.status = 'confirmed' AND b.check_in_date >= CURDATE() THEN 2
            WHEN b.status = 'checked_in' THEN 3
            WHEN b.status = 'completed' THEN 4
            WHEN b.status = 'cancelled' THEN 5
            ELSE 6
        END,
        b.check_in_date ASC
");

$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($bookings),
    'pending' => 0,
    'confirmed' => 0,
    'checked_in' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'today_checkins' => 0,
    'today_checkouts' => 0,
    'total_revenue' => 0,
    'avg_booking_value' => 0
];

foreach ($bookings as $booking) {
    $stats[$booking['status']]++;
    if ($booking['status'] === 'confirmed' || $booking['status'] === 'completed' || $booking['status'] === 'checked_in') {
        $stats['total_revenue'] += $booking['total_amount'];
    }
    if ($booking['check_in_date'] === date('Y-m-d') && $booking['status'] === 'confirmed') {
        $stats['today_checkins']++;
    }
    if ($booking['check_out_date'] === date('Y-m-d') && $booking['status'] === 'checked_in') {
        $stats['today_checkouts']++;
    }
}

$stats['avg_booking_value'] = $stats['total'] > 0 ? $stats['total_revenue'] / $stats['total'] : 0;

// Get upcoming check-ins (next 7 days)
$stmt = $db->prepare("
    SELECT b.*, s.stay_name, sr.room_name, u.first_name, u.last_name
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

// Get calendar data for the next 30 days
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
    'checked_in' => ['Checked In', 'info'],
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
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.bookings-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
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
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--booking-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-blue);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-trend {
    font-size: 0.6875rem;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
}

.trend-up { color: var(--booking-success); }
.trend-down { color: var(--booking-danger); }

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
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
    flex-wrap: wrap;
}

.filter-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

.filter-input {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    width: 200px;
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
    color: var(--booking-text-light);
}

.search-box input {
    width: 100%;
    padding: 10px 16px 10px 38px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
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
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.2s;
    cursor: pointer;
}

.quick-action-card:hover {
    border-color: var(--booking-blue);
    box-shadow: var(--shadow-sm);
}

.quick-action-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.quick-action-info h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.quick-action-info p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

/* Bookings Table */
.bookings-table-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
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
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: var(--booking-gray);
    border-bottom: 1px solid var(--booking-border);
}

.bookings-table td {
    padding: 16px 20px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.8125rem;
    vertical-align: middle;
}

.bookings-table tr:last-child td {
    border-bottom: none;
}

.bookings-table tr:hover td {
    background: var(--booking-light-blue);
}

.bookings-table tr.alert-checkin {
    background: #fff4e6;
}

.bookings-table tr.alert-checkout {
    background: #e6f4ea;
}

.booking-ref {
    font-family: monospace;
    font-weight: 600;
    color: var(--booking-blue);
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
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.875rem;
}

.guest-details {
    display: flex;
    flex-direction: column;
}

.guest-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.guest-email {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.property-info {
    display: flex;
    flex-direction: column;
}

.property-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.room-name {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.date-cell {
    display: flex;
    flex-direction: column;
}

.date-main {
    font-weight: 600;
    margin-bottom: 2px;
}

.date-range {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
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

.status-badge.warning { background: #fff4e6; color: var(--booking-warning); }
.status-badge.success { background: #e6f4ea; color: var(--booking-success); }
.status-badge.info { background: #e1f5fe; color: #0288d1; }
.status-badge.secondary { background: var(--booking-gray); color: var(--booking-text-light); }
.status-badge.danger { background: #fce8e8; color: var(--booking-danger); }
.status-badge.dark { background: #e0e0e0; color: #424242; }

.amount-cell {
    font-weight: 700;
    color: var(--booking-success);
}

.action-cell {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 6px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--booking-warning);
    color: var(--booking-warning);
}

.action-btn.success:hover {
    background: #e6f4ea;
    border-color: var(--booking-success);
    color: var(--booking-success);
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--booking-danger);
    color: var(--booking-danger);
}

/* Upcoming Calendar */
.calendar-mini {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.calendar-title {
    font-size: 1rem;
    font-weight: 700;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    text-align: center;
}

.calendar-day {
    padding: 10px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    position: relative;
}

.calendar-day.has-checkins {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    font-weight: 600;
}

.calendar-day.has-checkins::after {
    content: attr(data-count);
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--booking-blue);
    color: white;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    font-size: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
}

.page-item {
    list-style: none;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    color: var(--booking-text);
    text-decoration: none;
    font-size: 0.8125rem;
    transition: all 0.2s;
}

.page-link:hover,
.page-link.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: white;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--booking-gray);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--booking-danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--booking-gray);
    position: sticky;
    bottom: 0;
}

/* Booking Details */
.booking-detail-section {
    margin-bottom: 24px;
}

.booking-detail-title {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--booking-blue);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.booking-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    background: var(--booking-gray);
    padding: 16px;
    border-radius: var(--radius-sm);
}

.detail-row {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.detail-value {
    font-size: 0.9375rem;
    font-weight: 600;
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
    
    .booking-detail-grid {
        grid-template-columns: 1fr;
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

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-trend">
            <span class="<?php echo $stats['pending'] > 0 ? 'trend-up' : ''; ?>">
                <?php echo $stats['pending']; ?> pending
            </span>
            <span class="<?php echo $stats['confirmed'] > 0 ? 'trend-up' : ''; ?>">
                <?php echo $stats['confirmed']; ?> confirmed
            </span>
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
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-trend">
            <span>Avg: <?php echo formatPrice($stats['avg_booking_value']); ?></span>
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
    
    <div class="quick-action-card" onclick="filterByDate('today')">
        <div class="quick-action-icon">
            <i class="bi bi-calendar-day"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['today_checkins']; ?> Today</h4>
            <p>Check-ins & check-outs</p>
        </div>
    </div>
    
    <div class="quick-action-card" onclick="filterByStatus('checked_in')">
        <div class="quick-action-icon">
            <i class="bi bi-door-open"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['checked_in']; ?> In-house</h4>
            <p>Currently staying</p>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <span class="filter-label">Property:</span>
        <select name="property" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Properties</option>
            <?php foreach ($properties as $prop): ?>
            <option value="<?php echo $prop['stay_id']; ?>" <?php echo $propertyId == $prop['stay_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($prop['stay_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <span class="filter-label">Status:</span>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all">All Status</option>
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed" <?php echo $status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="checked_in" <?php echo $status == 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    
    <div class="filter-group">
        <span class="filter-label">Date Range:</span>
        <select name="date_range" class="filter-select" onchange="toggleCustomDates(this.value); this.form.submit()">
            <option value="all">All Dates</option>
            <option value="upcoming" <?php echo $dateRange == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
            <option value="current" <?php echo $dateRange == 'current' ? 'selected' : ''; ?>>Current Stays</option>
            <option value="past" <?php echo $dateRange == 'past' ? 'selected' : ''; ?>>Past</option>
            <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
        </select>
    </div>
    
    <div id="customDates" style="display: <?php echo $dateRange == 'custom' ? 'flex' : 'none'; ?>; gap: 10px;">
        <input type="date" name="from_date" class="filter-input" value="<?php echo $fromDate; ?>" placeholder="From">
        <input type="date" name="to_date" class="filter-input" value="<?php echo $toDate; ?>" placeholder="To">
        <button type="submit" class="btn-primary btn-sm">Apply</button>
    </div>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" name="search" placeholder="Search by guest or booking ref..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <?php if ($propertyId || $status != 'all' || $dateRange != 'all' || $search): ?>
    <a href="bookings.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Mini Calendar -->
<?php if (!empty($calendarData)): ?>
<div class="calendar-mini">
    <div class="calendar-header">
        <h3 class="calendar-title">📅 Next 30 Days - Check-ins</h3>
        <a href="calendar.php" class="btn-outline btn-sm">Full Calendar →</a>
    </div>
    <div class="calendar-grid" id="miniCalendar">
        <?php
        $today = new DateTime();
        for ($i = 0; $i < 30; $i++):
            $date = clone $today;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $dayName = $date->format('D');
            $dayNum = $date->format('j');
            $hasCheckins = isset($calendar[$dateStr]);
        ?>
        <div class="calendar-day <?php echo $hasCheckins ? 'has-checkins' : ''; ?>" 
             data-count="<?php echo $hasCheckins ? $calendar[$dateStr]['checkins'] : ''; ?>"
             title="<?php echo $hasCheckins ? $calendar[$dateStr]['checkins'] . ' check-ins, ' . $calendar[$dateStr]['guests'] . ' guests' : ''; ?>">
            <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo $dayName; ?></div>
            <div style="font-weight: 600;"><?php echo $dayNum; ?></div>
        </div>
        <?php endfor; ?>
    </div>
</div>
<?php endif; ?>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
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
                <th>Check-in / Check-out</th>
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
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                    No bookings found
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking): 
                    $statusInfo = $statusLabels[$booking['status']];
                    $rowClass = '';
                    if ($booking['alert'] == 'check-in-today') $rowClass = 'alert-checkin';
                    if ($booking['alert'] == 'check-out-today') $rowClass = 'alert-checkout';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td>
                        <span class="booking-ref">#<?php echo $booking['booking_reference']; ?></span>
                    </td>
                    <td>
                        <div class="guest-info">
                            <div class="guest-avatar">
                                <?php echo strtoupper(substr($booking['guest_first_name'] ?? 'G', 0, 1)); ?>
                            </div>
                            <div class="guest-details">
                                <span class="guest-name">
                                    <?php echo sanitize($booking['guest_first_name'] . ' ' . substr($booking['guest_last_name'] ?? '', 0, 1) . '.'); ?>
                                </span>
                                <span class="guest-email"><?php echo sanitize($booking['guest_email'] ?? 'No email'); ?></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="property-info">
                            <span class="property-name"><?php echo sanitize($booking['stay_name']); ?></span>
                            <span class="room-name"><?php echo sanitize($booking['room_name']); ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="date-cell">
                            <span class="date-main">
                                <?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?>
                            </span>
                            <span class="date-range">
                                to <?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?>
                            </span>
                        </div>
                    </td>
                    <td><?php echo $booking['nights']; ?></td>
                    <td><?php echo $booking['num_guests']; ?></td>
                    <td>
                        <span class="status-badge <?php echo $statusInfo[1]; ?>">
                            <?php echo $statusInfo[0]; ?>
                        </span>
                        <?php if ($booking['days_until_checkin'] == 1 && $booking['status'] == 'confirmed'): ?>
                        <div style="font-size: 0.625rem; color: var(--booking-warning); margin-top: 4px;">
                            Tomorrow!
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="amount-cell"><?php echo formatPrice($booking['total_amount']); ?></td>
                    <td>
                        <div class="action-cell">
                            <button class="action-btn" onclick="viewBooking(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-eye"></i> View
                            </button>
                            
                            <?php if ($booking['status'] == 'pending'): ?>
                            <button class="action-btn success" onclick="updateStatus(<?php echo $booking['booking_id']; ?>, 'confirm')">
                                <i class="bi bi-check-lg"></i> Confirm
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] == 'confirmed'): ?>
                                <?php if ($booking['check_in_date'] == date('Y-m-d')): ?>
                                <button class="action-btn success" onclick="checkIn(<?php echo $booking['booking_id']; ?>)">
                                    <i class="bi bi-door-open"></i> Check-in
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] == 'checked_in'): ?>
                                <?php if ($booking['check_out_date'] == date('Y-m-d')): ?>
                                <button class="action-btn warning" onclick="checkOut(<?php echo $booking['booking_id']; ?>)">
                                    <i class="bi bi-door-closed"></i> Check-out
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
                            <button class="action-btn danger" onclick="showCancelModal(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-x-lg"></i> Cancel
                            </button>
                            <?php endif; ?>
                            
                            <button class="action-btn" onclick="messageGuest(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-chat"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Pagination (if needed) -->
<?php if (count($bookings) > 50): ?>
<div class="pagination">
    <a href="#" class="page-link"><i class="bi bi-chevron-left"></i></a>
    <a href="#" class="page-link active">1</a>
    <a href="#" class="page-link">2</a>
    <a href="#" class="page-link">3</a>
    <a href="#" class="page-link">4</a>
    <a href="#" class="page-link">5</a>
    <a href="#" class="page-link"><i class="bi bi-chevron-right"></i></a>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- View Booking Modal -->
<div class="modal" id="viewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Booking Details</h3>
            <button class="modal-close" onclick="closeModal('viewModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- Content loaded via AJAX -->
            <div style="text-align: center; padding: 40px;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal" id="statusModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3 id="statusModalTitle">Update Booking Status</h3>
            <button class="modal-close" onclick="closeModal('statusModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="status_booking_id" value="0">
                <input type="hidden" name="status" id="status_value" value="">
                
                <div id="statusMessage" style="margin-bottom: 20px;">
                    Are you sure you want to <strong id="statusAction"></strong> this booking?
                </div>
                
                <div id="cancellationReason" style="display: none;">
                    <label class="form-label">Reason for cancellation</label>
                    <select name="cancellation_reason" class="form-control">
                        <option value="guest_request">Guest requested</option>
                        <option value="payment_failed">Payment failed</option>
                        <option value="overbooking">Overbooking</option>
                        <option value="property_issue">Property issue</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
                <button type="submit" name="update_status" class="btn-primary" id="statusConfirmBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Message Guest Modal -->
<div class="modal" id="messageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Message Guest</h3>
            <button class="modal-close" onclick="closeModal('messageModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="message_booking_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" value="Question about your booking" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="5" required 
                              placeholder="Write your message to the guest..."></textarea>
                </div>
                
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <i class="bi bi-info-circle"></i>
                    <small>The guest will receive this message via email and in their account.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('messageModal')">Cancel</button>
                <button type="submit" name="send_message" class="btn-primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function filterByStatus(status) {
    window.location.href = 'bookings.php?status=' + status;
}

function filterByDate(range) {
    window.location.href = 'bookings.php?date_range=' + range;
}

function toggleCustomDates(selected) {
    const customDates = document.getElementById('customDates');
    if (customDates) {
        customDates.style.display = selected === 'custom' ? 'flex' : 'none';
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// VIEW BOOKING DETAILS
// ============================================
function viewBooking(bookingId) {
    openModal('viewModal');
    
    // Simulate loading (in production, make AJAX call)
    setTimeout(() => {
        document.getElementById('viewModalBody').innerHTML = `
            <div class="booking-detail-section">
                <h4 class="booking-detail-title">Booking Information</h4>
                <div class="booking-detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Reference</span>
                        <span class="detail-value">#GRW-2024-${bookingId}XYZ</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status</span>
                        <span class="detail-value"><span class="status-badge success">Confirmed</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-in</span>
                        <span class="detail-value">March 15, 2024</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Check-out</span>
                        <span class="detail-value">March 18, 2024</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Nights</span>
                        <span class="detail-value">3 nights</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Guests</span>
                        <span class="detail-value">2 adults</span>
                    </div>
                </div>
            </div>
            
            <div class="booking-detail-section">
                <h4 class="booking-detail-title">Guest Information</h4>
                <div class="booking-detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Name</span>
                        <span class="detail-value">John Doe</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email</span>
                        <span class="detail-value">john@example.com</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone</span>
                        <span class="detail-value">+250 788 123 456</span>
                    </div>
                </div>
            </div>
            
            <div class="booking-detail-section">
                <h4 class="booking-detail-title">Payment Summary</h4>
                <div class="booking-detail-grid">
                    <div class="detail-row">
                        <span class="detail-label">Room Rate</span>
                        <span class="detail-value">RWF 120,000 × 3 nights</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Subtotal</span>
                        <span class="detail-value">RWF 360,000</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Service Fee</span>
                        <span class="detail-value">RWF 36,000</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Tax</span>
                        <span class="detail-value">RWF 64,800</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Total</span>
                        <span class="detail-value" style="color: var(--booking-success);">RWF 460,800</span>
                    </div>
                </div>
            </div>
        `;
    }, 500);
}

// ============================================
// STATUS UPDATE FUNCTIONS
// ============================================
function updateStatus(bookingId, action) {
    document.getElementById('status_booking_id').value = bookingId;
    
    if (action === 'confirm') {
        document.getElementById('statusModalTitle').textContent = 'Confirm Booking';
        document.getElementById('statusAction').textContent = 'confirm';
        document.getElementById('status_value').value = 'confirmed';
        document.getElementById('statusConfirmBtn').className = 'btn-primary';
        document.getElementById('cancellationReason').style.display = 'none';
    } else if (action === 'cancel') {
        document.getElementById('statusModalTitle').textContent = 'Cancel Booking';
        document.getElementById('statusAction').textContent = 'cancel';
        document.getElementById('status_value').value = 'cancelled';
        document.getElementById('statusConfirmBtn').className = 'btn-danger';
        document.getElementById('cancellationReason').style.display = 'block';
    }
    
    openModal('statusModal');
}

function showCancelModal(bookingId) {
    updateStatus(bookingId, 'cancel');
}

function checkIn(bookingId) {
    if (confirm('Check in this guest?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="booking_id" value="${bookingId}"><input type="hidden" name="check_in" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function checkOut(bookingId) {
    if (confirm('Check out this guest?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="booking_id" value="${bookingId}"><input type="hidden" name="check_out" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// ============================================
// MESSAGE FUNCTIONS
// ============================================
function messageGuest(bookingId) {
    document.getElementById('message_booking_id').value = bookingId;
    openModal('messageModal');
}

// ============================================
// EXPORT FUNCTION
// ============================================
function exportBookings() {
    // Create CSV content
    let csv = "Reference,Guest Name,Email,Property,Room,Check-in,Check-out,Nights,Guests,Status,Amount\n";
    
    <?php foreach ($bookings as $booking): ?>
    csv += "#<?php echo $booking['booking_reference']; ?>,<?php echo $booking['guest_first_name'] . ' ' . $booking['guest_last_name']; ?>,<?php echo $booking['guest_email']; ?>,<?php echo $booking['stay_name']; ?>,<?php echo $booking['room_name']; ?>,<?php echo $booking['check_in_date']; ?>,<?php echo $booking['check_out_date']; ?>,<?php echo $booking['nights']; ?>,<?php echo $booking['num_guests']; ?>,<?php echo $booking['status']; ?>,<?php echo $booking['total_amount']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'bookings_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>