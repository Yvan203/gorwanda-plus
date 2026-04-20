<?php
$pageTitle = 'Booking Management';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;
$tierId = isset($_GET['tier']) ? intval($_GET['tier']) : 0;
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateRange = isset($_GET['date_range']) ? sanitize($_GET['date_range']) : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'date_asc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

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
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        JOIN attractions a ON at.attraction_id = a.attraction_id
        SET b.status = ?, b.cancellation_reason = ?, b.updated_at = NOW()
        WHERE b.booking_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$newStatus, $cancellationReason, $bookingId, $userId]);
    
    // If cancelled, update availability bookings_made
    if ($newStatus === 'cancelled') {
        $stmt = $db->prepare("
            UPDATE attraction_availability aa
            JOIN bookings b ON aa.tier_id = b.attraction_tier_id AND aa.date = b.experience_date
            SET aa.bookings_made = aa.bookings_made - b.num_participants
            WHERE b.booking_id = ?
        ");
        $stmt->execute([$bookingId]);
    }
    
    // If confirmed, ensure availability is updated
    if ($newStatus === 'confirmed') {
        $stmt = $db->prepare("
            UPDATE attraction_availability aa
            JOIN bookings b ON aa.tier_id = b.attraction_tier_id AND aa.date = b.experience_date
            SET aa.bookings_made = aa.bookings_made + b.num_participants
            WHERE b.booking_id = ? AND aa.is_blocked = 0
        ");
        $stmt->execute([$bookingId]);
    }
    
    $success = "Booking status updated to " . ucfirst($newStatus);
}

// Send message to guest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $bookingId = intval($_POST['booking_id']);
    $message = sanitize($_POST['message']);
    $subject = sanitize($_POST['subject'] ?? 'Message about your booking');
    
    // Get booking details
    $stmt = $db->prepare("
        SELECT b.*, u.user_id as guest_id, u.email, u.first_name, u.last_name, 
               a.attraction_name, at.tier_name
        FROM bookings b
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        JOIN attractions a ON at.attraction_id = a.attraction_id
        JOIN users u ON b.user_id = u.user_id
        WHERE b.booking_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Insert message into messages table
        $stmt = $db->prepare("
            INSERT INTO messages (sender_id, receiver_id, booking_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $booking['guest_id'], $bookingId, $subject, $message]);
        
        // In production, also send email
        $success = "Message sent to guest successfully";
    }
}

// Mark as no-show
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_no_show'])) {
    $bookingId = intval($_POST['booking_id']);
    
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        JOIN attractions a ON at.attraction_id = a.attraction_id
        SET b.status = 'no_show', b.updated_at = NOW()
        WHERE b.booking_id = ? AND a.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$bookingId, $userId]);
    
    $success = "Guest marked as no-show";
}

// ============================================
// BUILD QUERY CONDITIONS
// ============================================

$conditions = ["a.owner_id = ?"];
$params = [$userId];

if ($experienceId > 0) {
    $conditions[] = "a.attraction_id = ?";
    $params[] = $experienceId;
}

if ($tierId > 0) {
    $conditions[] = "at.tier_id = ?";
    $params[] = $tierId;
}

if ($status !== 'all') {
    $conditions[] = "b.status = ?";
    $params[] = $status;
}

if ($dateRange === 'today') {
    $conditions[] = "DATE(b.experience_date) = CURDATE()";
} elseif ($dateRange === 'tomorrow') {
    $conditions[] = "DATE(b.experience_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
} elseif ($dateRange === 'week') {
    $conditions[] = "b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($dateRange === 'month') {
    $conditions[] = "b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($dateRange === 'past') {
    $conditions[] = "b.experience_date < CURDATE()";
} elseif ($dateRange === 'custom' && $fromDate && $toDate) {
    $conditions[] = "b.experience_date BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
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

// Get all experiences for filter
$stmt = $db->prepare("
    SELECT attraction_id, attraction_name 
    FROM attractions 
    WHERE owner_id = ? 
    ORDER BY attraction_name
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// Get tiers for filter (if experience selected)
$tiers = [];
if ($experienceId > 0) {
    $stmt = $db->prepare("
        SELECT tier_id, tier_name 
        FROM attraction_tiers 
        WHERE attraction_id = ? 
        ORDER BY base_price
    ");
    $stmt->execute([$experienceId]);
    $tiers = $stmt->fetchAll();
} elseif (!empty($experiences)) {
    // Get all tiers across all experiences
    $expIds = array_column($experiences, 'attraction_id');
    $placeholders = implode(',', array_fill(0, count($expIds), '?'));
    $stmt = $db->prepare("
        SELECT tier_id, tier_name, attraction_id 
        FROM attraction_tiers 
        WHERE attraction_id IN ($placeholders)
        ORDER BY tier_name
    ");
    $stmt->execute($expIds);
    $tiers = $stmt->fetchAll();
}

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) as total
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalBookings = $stmt->fetchColumn();
$totalPages = ceil($totalBookings / $perPage);

// Build order by
$orderBy = match($sort) {
    'date_asc' => 'b.experience_date ASC, b.start_time ASC',
    'date_desc' => 'b.experience_date DESC, b.start_time DESC',
    'name_asc' => 'u.last_name ASC, u.first_name ASC',
    'name_desc' => 'u.last_name DESC, u.first_name DESC',
    'status' => 'b.status ASC, b.experience_date ASC',
    'amount_desc' => 'b.total_amount DESC',
    'amount_asc' => 'b.total_amount ASC',
    default => 'b.experience_date ASC, b.start_time ASC'
};

// Get bookings
$sql = "
    SELECT 
        b.*,
        a.attraction_id,
        a.attraction_name,
        a.main_image as attraction_image,
        at.tier_id,
        at.tier_name,
        at.base_price as tier_base_price,
        u.user_id as guest_id,
        u.first_name as guest_first_name,
        u.last_name as guest_last_name,
        u.email as guest_email,
        u.phone as guest_phone,
        DATEDIFF(b.experience_date, CURDATE()) as days_until,
        CASE 
            WHEN b.status = 'confirmed' AND b.experience_date = CURDATE() THEN 'today'
            WHEN b.status = 'confirmed' AND b.experience_date < CURDATE() THEN 'overdue'
            WHEN b.status = 'confirmed' AND b.experience_date > CURDATE() THEN 'upcoming'
            ELSE NULL
        END as alert
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => $totalBookings,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'no_show' => 0,
    'today' => 0,
    'tomorrow' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'total_revenue' => 0,
    'avg_booking_value' => 0,
    'total_participants' => 0
];

// Get stats from database for efficiency
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN b.status = 'no_show' THEN 1 ELSE 0 END) as no_show,
        SUM(CASE WHEN DATE(b.experience_date) = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN DATE(b.experience_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as tomorrow,
        SUM(CASE WHEN b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
        SUM(CASE WHEN b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as this_month,
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COALESCE(SUM(b.num_participants), 0) as total_participants
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    WHERE a.owner_id = ?
";

$stmt = $db->prepare($statsSql);
$stmt->execute([$userId]);
$statsData = $stmt->fetch();

$stats = array_merge($stats, $statsData);

// Get today's schedule
$todaySql = "
    SELECT b.*, a.attraction_name, at.tier_name, u.first_name, u.last_name, u.phone
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE a.owner_id = ? AND DATE(b.experience_date) = CURDATE()
    AND b.status IN ('confirmed', 'pending')
    ORDER BY b.start_time ASC
";
$stmt = $db->prepare($todaySql);
$stmt->execute([$userId]);
$todaySchedule = $stmt->fetchAll();

// Status colors and labels
$statusConfig = [
    'pending' => ['label' => 'Pending', 'color' => 'warning', 'icon' => 'clock-history'],
    'confirmed' => ['label' => 'Confirmed', 'color' => 'success', 'icon' => 'check-circle'],
    'completed' => ['label' => 'Completed', 'color' => 'info', 'icon' => 'check2-circle'],
    'cancelled' => ['label' => 'Cancelled', 'color' => 'danger', 'icon' => 'x-circle'],
    'no_show' => ['label' => 'No Show', 'color' => 'dark', 'icon' => 'person-x']
];
?>

<style>
/* Bookings Management Specific Styles */
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
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.bookings-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
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
    border: 1px solid var(--exp-border);
    transition: all 0.2s;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.stat-icon.purple { background: var(--exp-light-purple); color: var(--exp-purple); }
.stat-icon.green { background: #e6f4ea; color: #10b981; }
.stat-icon.orange { background: #fff4e6; color: #f59e0b; }
.stat-icon.blue { background: #e1f5fe; color: #0288d1; }

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    line-height: 1.2;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    font-weight: 500;
}

.stat-footer {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--exp-border);
    font-size: 0.75rem;
    display: flex;
    justify-content: space-between;
    color: var(--exp-text-light);
}

.stat-footer span {
    font-weight: 600;
    color: var(--exp-text);
}

/* Today's Schedule */
.today-schedule {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 20px;
    margin-bottom: 24px;
}

.schedule-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.schedule-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--exp-text);
    display: flex;
    align-items: center;
    gap: 8px;
}

.schedule-title i {
    color: var(--exp-purple);
}

.schedule-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
}

.schedule-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--exp-gray);
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    transition: all 0.2s;
}

.schedule-item:hover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
}

.schedule-time {
    min-width: 60px;
    text-align: center;
}

.schedule-time .hour {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-purple);
    line-height: 1.2;
}

.schedule-time .ampm {
    font-size: 0.625rem;
    color: var(--exp-text-light);
}

.schedule-info {
    flex: 1;
}

.schedule-name {
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 2px;
}

.schedule-details {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    display: flex;
    gap: 8px;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.filter-select, .filter-input {
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

.search-box {
    flex: 2;
    min-width: 250px;
    position: relative;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--exp-text-light);
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

/* Bookings Table */
.bookings-table-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    margin-bottom: 24px;
}

.bookings-table {
    width: 100%;
    border-collapse: collapse;
}

.bookings-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--exp-text-light);
    text-transform: uppercase;
    background: var(--exp-gray);
    border-bottom: 1px solid var(--exp-border);
}

.bookings-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--exp-border);
    font-size: 0.8125rem;
    vertical-align: middle;
}

.bookings-table tr:last-child td {
    border-bottom: none;
}

.bookings-table tr:hover td {
    background: var(--exp-light-purple);
}

.bookings-table tr.alert-today {
    background: #fff4e6;
}

.bookings-table tr.alert-overdue {
    background: #fce8e8;
}

.booking-ref {
    font-family: monospace;
    font-weight: 600;
    color: var(--exp-purple);
}

.guest-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.guest-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--exp-light-purple);
    color: var(--exp-purple);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.75rem;
}

.guest-details {
    display: flex;
    flex-direction: column;
}

.guest-name {
    font-weight: 600;
    font-size: 0.8125rem;
    margin-bottom: 2px;
}

.guest-email {
    font-size: 0.625rem;
    color: var(--exp-text-light);
}

.experience-info {
    display: flex;
    flex-direction: column;
}

.experience-name {
    font-weight: 600;
    font-size: 0.8125rem;
    margin-bottom: 2px;
}

.tier-name {
    font-size: 0.625rem;
    color: var(--exp-text-light);
}

.date-cell {
    display: flex;
    flex-direction: column;
}

.date-main {
    font-weight: 600;
    font-size: 0.8125rem;
}

.date-time {
    font-size: 0.625rem;
    color: var(--exp-text-light);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: capitalize;
}

.status-pending { background: #fff4e6; color: #f59e0b; }
.status-confirmed { background: #e6f4ea; color: #10b981; }
.status-completed { background: #e1f5fe; color: #0288d1; }
.status-cancelled { background: #fce8e8; color: #ef4444; }
.status-no_show { background: #e0e0e0; color: #424242; }

.amount-cell {
    font-weight: 700;
    color: var(--exp-success);
}

.action-cell {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 4px 8px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--exp-text);
    font-size: 0.625rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: #f59e0b;
    color: #f59e0b;
}

.action-btn.success:hover {
    background: #e6f4ea;
    border-color: #10b981;
    color: #10b981;
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: #ef4444;
    color: #ef4444;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    color: var(--exp-text);
    text-decoration: none;
    font-size: 0.8125rem;
    transition: all 0.2s;
}

.page-link:hover,
.page-link.active {
    background: var(--exp-purple);
    border-color: var(--exp-purple);
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
    overflow-y: auto;
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
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
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
    color: var(--exp-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--exp-gray);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: var(--exp-danger);
    color: white;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--exp-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--exp-gray);
    position: sticky;
    bottom: 0;
}

/* Form Styles */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--exp-text);
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
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
    
    .schedule-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="bookings-header">
    <div class="bookings-title">
        <h1>Booking Management</h1>
        <p>Manage all your experience bookings, check-ins, and guest communications</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportBookings()">
            <i class="bi bi-download"></i> Export
        </button>
        <button class="btn-secondary" onclick="refreshData()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">
                <i class="bi bi-calendar-check"></i>
            </div>
            <span class="stat-value"><?php echo $stats['total']; ?></span>
        </div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-footer">
            <span>Confirmed: <?php echo $stats['confirmed']; ?></span>
            <span>Pending: <?php echo $stats['pending']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">
                <i class="bi bi-people"></i>
            </div>
            <span class="stat-value"><?php echo $stats['total_participants']; ?></span>
        </div>
        <div class="stat-label">Total Participants</div>
        <div class="stat-footer">
            <span>Avg: <?php echo $stats['total'] > 0 ? round($stats['total_participants'] / $stats['total'], 1) : 0; ?> per booking</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">
                <i class="bi bi-cash-stack"></i>
            </div>
            <span class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></span>
        </div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-footer">
            <span>Avg: <?php echo formatPrice($stats['avg_booking_value']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">
                <i class="bi bi-calendar-range"></i>
            </div>
            <span class="stat-value"><?php echo $stats['today']; ?></span>
        </div>
        <div class="stat-label">Today's Bookings</div>
        <div class="stat-footer">
            <span>Tomorrow: <?php echo $stats['tomorrow']; ?></span>
            <span>This week: <?php echo $stats['this_week']; ?></span>
        </div>
    </div>
</div>

<!-- Today's Schedule -->
<?php if (!empty($todaySchedule)): ?>
<div class="today-schedule">
    <div class="schedule-header">
        <h3 class="schedule-title">
            <i class="bi bi-calendar-day"></i> Today's Schedule - <?php echo date('F j, Y'); ?>
        </h3>
        <span class="badge" style="background: var(--exp-purple); color: white; padding: 4px 12px;">
            <?php echo count($todaySchedule); ?> booking(s)
        </span>
    </div>
    
    <div class="schedule-grid">
        <?php foreach ($todaySchedule as $item): 
            $time = $item['start_time'] ? date('h:i A', strtotime($item['start_time'])) : 'Flexible';
        ?>
        <div class="schedule-item">
            <div class="schedule-time">
                <div class="hour"><?php echo date('h:i', strtotime($item['start_time'] ?? '09:00')); ?></div>
                <div class="ampm"><?php echo date('A', strtotime($item['start_time'] ?? '09:00')); ?></div>
            </div>
            <div class="schedule-info">
                <div class="schedule-name"><?php echo sanitize($item['guest_first_name'] . ' ' . $item['guest_last_name']); ?></div>
                <div class="schedule-details">
                    <span><?php echo sanitize($item['attraction_name']); ?></span>
                    <span>•</span>
                    <span><?php echo $item['num_participants']; ?> pax</span>
                </div>
            </div>
            <div>
                <span class="status-badge status-<?php echo $item['status']; ?>">
                    <?php echo $statusConfig[$item['status']]['label']; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Experience</label>
        <select name="experience" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Experiences</option>
            <?php foreach ($experiences as $exp): ?>
            <option value="<?php echo $exp['attraction_id']; ?>" <?php echo $experienceId == $exp['attraction_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($exp['attraction_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Tier</label>
        <select name="tier" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Tiers</option>
            <?php foreach ($tiers as $t): ?>
            <option value="<?php echo $t['tier_id']; ?>" <?php echo $tierId == $t['tier_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($t['tier_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Status</label>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all">All Status</option>
            <?php foreach ($statusConfig as $key => $config): ?>
            <option value="<?php echo $key; ?>" <?php echo $status == $key ? 'selected' : ''; ?>>
                <?php echo $config['label']; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Date Range</label>
        <select name="date_range" class="filter-select" onchange="toggleCustomDates(this.value); this.form.submit()">
            <option value="all">All Dates</option>
            <option value="today" <?php echo $dateRange == 'today' ? 'selected' : ''; ?>>Today</option>
            <option value="tomorrow" <?php echo $dateRange == 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
            <option value="week" <?php echo $dateRange == 'week' ? 'selected' : ''; ?>>Next 7 Days</option>
            <option value="month" <?php echo $dateRange == 'month' ? 'selected' : ''; ?>>Next 30 Days</option>
            <option value="past" <?php echo $dateRange == 'past' ? 'selected' : ''; ?>>Past</option>
            <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
        </select>
    </div>
    
    <div id="customDates" style="display: <?php echo $dateRange == 'custom' ? 'flex' : 'none'; ?>; gap: 10px;">
        <input type="date" name="from_date" class="filter-input" value="<?php echo $fromDate; ?>" placeholder="From">
        <input type="date" name="to_date" class="filter-input" value="<?php echo $toDate; ?>" placeholder="To">
        <button type="submit" class="btn-primary btn-sm">Apply</button>
    </div>
    
    <div class="filter-group">
        <label>Sort By</label>
        <select name="sort" class="filter-select" onchange="this.form.submit()">
            <option value="date_asc" <?php echo $sort == 'date_asc' ? 'selected' : ''; ?>>Date (Earliest)</option>
            <option value="date_desc" <?php echo $sort == 'date_desc' ? 'selected' : ''; ?>>Date (Latest)</option>
            <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Guest Name (A-Z)</option>
            <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Guest Name (Z-A)</option>
            <option value="status" <?php echo $sort == 'status' ? 'selected' : ''; ?>>Status</option>
            <option value="amount_desc" <?php echo $sort == 'amount_desc' ? 'selected' : ''; ?>>Amount (High to Low)</option>
            <option value="amount_asc" <?php echo $sort == 'amount_asc' ? 'selected' : ''; ?>>Amount (Low to High)</option>
        </select>
    </div>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" name="search" placeholder="Search by guest or booking ref..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <?php if ($experienceId || $tierId || $status != 'all' || $dateRange != 'all' || $search): ?>
    <a href="bookings.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success" style="padding: 12px 16px; background: #e6f4ea; color: #10b981; border-radius: var(--radius-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<!-- Results Info -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <p style="font-size: 0.8125rem; color: var(--exp-text-light);">
        Showing <strong><?php echo count($bookings); ?></strong> of <strong><?php echo $totalBookings; ?></strong> bookings
    </p>
    <p style="font-size: 0.8125rem; color: var(--exp-text-light);">
        Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?>
    </p>
</div>

<!-- Bookings Table -->
<div class="bookings-table-container">
    <table class="bookings-table">
        <thead>
            <tr>
                <th>Booking Ref</th>
                <th>Guest</th>
                <th>Experience / Tier</th>
                <th>Date & Time</th>
                <th>Participants</th>
                <th>Status</th>
                <th>Amount</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: var(--exp-text-light);">
                    <i class="bi bi-inbox" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                    No bookings found
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking): 
                    $statusInfo = $statusConfig[$booking['status']] ?? ['label' => $booking['status'], 'color' => 'secondary', 'icon' => 'question'];
                    $rowClass = '';
                    if ($booking['alert'] == 'today') $rowClass = 'alert-today';
                    if ($booking['alert'] == 'overdue') $rowClass = 'alert-overdue';
                    $timeDisplay = $booking['start_time'] ? date('h:i A', strtotime($booking['start_time'])) : 'Flexible';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td>
                        <span class="booking-ref">#<?php echo $booking['booking_reference']; ?></span>
                        <?php if ($booking['days_until'] == 0 && $booking['status'] == 'confirmed'): ?>
                        <div style="font-size: 0.5625rem; color: #f59e0b;">Today!</div>
                        <?php endif; ?>
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
                        <div class="experience-info">
                            <span class="experience-name"><?php echo sanitize($booking['attraction_name']); ?></span>
                            <span class="tier-name"><?php echo sanitize($booking['tier_name']); ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="date-cell">
                            <span class="date-main"><?php echo date('M d, Y', strtotime($booking['experience_date'])); ?></span>
                            <span class="date-time"><?php echo $timeDisplay; ?></span>
                        </div>
                    </td>
                    <td><?php echo $booking['num_participants']; ?> guest(s)</td>
                    <td>
                        <span class="status-badge status-<?php echo $booking['status']; ?>">
                            <i class="bi bi-<?php echo $statusInfo['icon']; ?>"></i>
                            <?php echo $statusInfo['label']; ?>
                        </span>
                    </td>
                    <td class="amount-cell"><?php echo formatPrice($booking['total_amount']); ?></td>
                    <td>
                        <div class="action-cell">
                            <button class="action-btn" onclick="viewBooking(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-eye"></i>
                            </button>
                            
                            <?php if ($booking['status'] == 'pending'): ?>
                            <button class="action-btn success" onclick="updateStatus(<?php echo $booking['booking_id']; ?>, 'confirm')">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] == 'confirmed' && $booking['experience_date'] == date('Y-m-d')): ?>
                            <button class="action-btn" onclick="markNoShow(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-person-x"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
                            <button class="action-btn warning" onclick="showCancelModal(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-x-lg"></i>
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

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
        <i class="bi bi-chevron-left"></i>
    </a>
    <?php endif; ?>
    
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
       class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php endif; ?>
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
                        <option value="weather">Weather conditions</option>
                        <option value="operator_issue">Operator issue</option>
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

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// BOOKING FUNCTIONS
// ============================================
function viewBooking(bookingId) {
    openModal('viewModal');
    
    // Simulate loading - in production, make AJAX call
    setTimeout(() => {
        document.getElementById('viewModalBody').innerHTML = `
            <div style="padding: 20px;">
                <p>Booking details would load here via AJAX</p>
                <p>Booking ID: ${bookingId}</p>
            </div>
        `;
    }, 500);
}

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

function markNoShow(bookingId) {
    if (confirm('Mark this guest as no-show?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="booking_id" value="${bookingId}"><input type="hidden" name="mark_no_show" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function messageGuest(bookingId) {
    document.getElementById('message_booking_id').value = bookingId;
    openModal('messageModal');
}

// ============================================
// EXPORT FUNCTION
// ============================================
function exportBookings() {
    // Create CSV content
    let csv = "Reference,Guest Name,Email,Experience,Tier,Date,Time,Participants,Status,Amount\n";
    
    <?php foreach ($bookings as $booking): ?>
    csv += "#<?php echo $booking['booking_reference']; ?>,<?php echo $booking['guest_first_name'] . ' ' . $booking['guest_last_name']; ?>,<?php echo $booking['guest_email']; ?>,<?php echo $booking['attraction_name']; ?>,<?php echo $booking['tier_name']; ?>,<?php echo $booking['experience_date']; ?>,<?php echo $booking['start_time']; ?>,<?php echo $booking['num_participants']; ?>,<?php echo $booking['status']; ?>,<?php echo $booking['total_amount']; ?>\n";
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

function refreshData() {
    window.location.reload();
}
</script>

<?php require_once 'includes/experiences_footer.php'; ?>