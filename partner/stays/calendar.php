<?php
$pageTitle = 'Availability Calendar';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$view = isset($_GET['view']) ? sanitize($_GET['view']) : 'month';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// Calculate previous and next month
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear = $year - 1;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear = $year + 1;
}

// ============================================
// HANDLE CALENDAR ACTIONS
// ============================================

// Update availability - FIXED: removed notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    $roomId = intval($_POST['room_id']);
    $date = $_POST['date'];
    $action = $_POST['availability_action'];
    $priceOverride = isset($_POST['price_override']) && $_POST['price_override'] !== '' ? floatval($_POST['price_override']) : null;
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT sr.room_id FROM stay_rooms sr
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE sr.room_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$roomId, $userId]);
    
    if ($stmt->fetch()) {
        if ($action === 'block') {
            // Block room (set availability to 0)
            $stmt = $db->prepare("
                INSERT INTO stay_availability (room_id, date, rooms_available, is_blocked)
                VALUES (?, ?, 0, 1)
                ON DUPLICATE KEY UPDATE rooms_available = 0, is_blocked = 1, price_override = NULL
            ");
            $stmt->execute([$roomId, $date]);
        } elseif ($action === 'available') {
            // Set as available (delete the override)
            $stmt = $db->prepare("
                DELETE FROM stay_availability 
                WHERE room_id = ? AND date = ?
            ");
            $stmt->execute([$roomId, $date]);
        } elseif ($action === 'price_override') {
            // Set special price
            $stmt = $db->prepare("
                INSERT INTO stay_availability (room_id, date, rooms_available, price_override, is_blocked)
                VALUES (?, ?, 1, ?, 0)
                ON DUPLICATE KEY UPDATE rooms_available = 1, is_blocked = 0, price_override = ?
            ");
            $stmt->execute([$roomId, $date, $priceOverride, $priceOverride]);
        }
        
        // Redirect to prevent form resubmission
        header("Location: calendar.php?property=$propertyId&year=$year&month=$month&success=1");
        exit;
    }
}

// Bulk update - FIXED: removed notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $roomId = intval($_POST['room_id']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $action = $_POST['bulk_action'];
    $priceOverride = isset($_POST['bulk_price']) && $_POST['bulk_price'] !== '' ? floatval($_POST['bulk_price']) : null;
    $daysOfWeek = isset($_POST['days_of_week']) ? $_POST['days_of_week'] : [];
    
    // Generate all dates in range
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = new DateInterval('P1D');
    $dateRange = new DatePeriod($current, $interval, $end->modify('+1 day'));
    
    $updated = 0;
    
    foreach ($dateRange as $dateObj) {
        $date = $dateObj->format('Y-m-d');
        
        // Check if this day of week should be updated
        $dayOfWeek = intval($dateObj->format('w')); // 0 (Sunday) to 6 (Saturday)
        $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        
        if (empty($daysOfWeek) || in_array($dayNames[$dayOfWeek], $daysOfWeek)) {
            if ($action === 'block') {
                $stmt = $db->prepare("
                    INSERT INTO stay_availability (room_id, date, rooms_available, is_blocked)
                    VALUES (?, ?, 0, 1)
                    ON DUPLICATE KEY UPDATE rooms_available = 0, is_blocked = 1, price_override = NULL
                ");
                $stmt->execute([$roomId, $date]);
                $updated++;
            } elseif ($action === 'available') {
                $stmt = $db->prepare("
                    DELETE FROM stay_availability 
                    WHERE room_id = ? AND date = ?
                ");
                $stmt->execute([$roomId, $date]);
                $updated++;
            } elseif ($action === 'set_price' && $priceOverride) {
                $stmt = $db->prepare("
                    INSERT INTO stay_availability (room_id, date, rooms_available, price_override, is_blocked)
                    VALUES (?, ?, 1, ?, 0)
                    ON DUPLICATE KEY UPDATE rooms_available = 1, is_blocked = 0, price_override = ?
                ");
                $stmt->execute([$roomId, $date, $priceOverride, $priceOverride]);
                $updated++;
            }
        }
    }
    
    header("Location: calendar.php?property=$propertyId&year=$year&month=$month&bulk_updated=$updated");
    exit;
}

// Copy from previous period - FIXED: removed notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_from'])) {
    $roomId = intval($_POST['room_id']);
    $sourceMonth = intval($_POST['source_month']);
    $sourceYear = intval($_POST['source_year']);
    $targetMonth = intval($_POST['target_month']);
    $targetYear = intval($_POST['target_year']);
    
    // Get source data
    $stmt = $db->prepare("
        SELECT * FROM stay_availability 
        WHERE room_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
    ");
    $stmt->execute([$roomId, $sourceYear, $sourceMonth]);
    $sourceData = $stmt->fetchAll();
    
    $copied = 0;
    foreach ($sourceData as $data) {
        $sourceDate = new DateTime($data['date']);
        $targetDay = intval($sourceDate->format('d'));
        $targetDateStr = sprintf("%04d-%02d-%02d", $targetYear, $targetMonth, $targetDay);
        
        // Validate target date exists (e.g., not Feb 30)
        $targetDateObj = DateTime::createFromFormat('Y-m-d', $targetDateStr);
        if ($targetDateObj && intval($targetDateObj->format('m')) === $targetMonth) {
            if ($data['is_blocked']) {
                $stmt = $db->prepare("
                    INSERT INTO stay_availability (room_id, date, rooms_available, is_blocked)
                    VALUES (?, ?, 0, 1)
                    ON DUPLICATE KEY UPDATE rooms_available = 0, is_blocked = 1, price_override = NULL
                ");
                $stmt->execute([$roomId, $targetDateStr]);
            } elseif ($data['price_override']) {
                $stmt = $db->prepare("
                    INSERT INTO stay_availability (room_id, date, rooms_available, price_override, is_blocked)
                    VALUES (?, ?, 1, ?, 0)
                    ON DUPLICATE KEY UPDATE rooms_available = 1, is_blocked = 0, price_override = ?
                ");
                $stmt->execute([$roomId, $targetDateStr, $data['price_override'], $data['price_override']]);
            }
            $copied++;
        }
    }
    
    header("Location: calendar.php?property=$propertyId&year=$year&month=$month&copied=$copied");
    exit;
}

// Quick Action: Block all rooms - NEW HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_all_rooms'])) {
    $startDate = $_POST['block_start_date'];
    $endDate = $_POST['block_end_date'];
    $reason = isset($_POST['block_reason']) ? sanitize($_POST['block_reason']) : '';
    
    // Get all rooms for this property
    $stmt = $db->prepare("SELECT room_id FROM stay_rooms WHERE stay_id = ? AND is_active = 1");
    $stmt->execute([$propertyId]);
    $allRooms = $stmt->fetchAll();
    
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = new DateInterval('P1D');
    $dateRange = new DatePeriod($current, $interval, $end->modify('+1 day'));
    
    $blockedCount = 0;
    
    foreach ($allRooms as $room) {
        foreach ($dateRange as $dateObj) {
            $date = $dateObj->format('Y-m-d');
            $stmt = $db->prepare("
                INSERT INTO stay_availability (room_id, date, rooms_available, is_blocked)
                VALUES (?, ?, 0, 1)
                ON DUPLICATE KEY UPDATE rooms_available = 0, is_blocked = 1, price_override = NULL
            ");
            $stmt->execute([$room['room_id'], $date]);
            $blockedCount++;
        }
    }
    
    header("Location: calendar.php?property=$propertyId&year=$year&month=$month&all_blocked=$blockedCount");
    exit;
}

// Quick Action: Set all available - NEW HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_all_available'])) {
    $startDate = $_POST['avail_start_date'];
    $endDate = $_POST['avail_end_date'];
    
    // Get all rooms for this property
    $stmt = $db->prepare("SELECT room_id FROM stay_rooms WHERE stay_id = ? AND is_active = 1");
    $stmt->execute([$propertyId]);
    $allRooms = $stmt->fetchAll();
    
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = new DateInterval('P1D');
    $dateRange = new DatePeriod($current, $interval, $end->modify('+1 day'));
    
    $clearedCount = 0;
    
    foreach ($allRooms as $room) {
        foreach ($dateRange as $dateObj) {
            $date = $dateObj->format('Y-m-d');
            $stmt = $db->prepare("
                DELETE FROM stay_availability 
                WHERE room_id = ? AND date = ?
            ");
            $stmt->execute([$room['room_id'], $date]);
            $clearedCount++;
        }
    }
    
    header("Location: calendar.php?property=$propertyId&year=$year&month=$month&all_cleared=$clearedCount");
    exit;
}

// ============================================
// GET CALENDAR DATA
// ============================================

// Get all properties for filter
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Get rooms for selected property
$rooms = [];
$currentProperty = null;
if ($propertyId > 0) {
    $stmt = $db->prepare("
        SELECT 
            sr.*,
            (SELECT COUNT(*) FROM stay_availability sa 
             WHERE sa.room_id = sr.room_id 
             AND sa.date >= ? 
             AND sa.date <= LAST_DAY(?) 
             AND sa.is_blocked = 1) as blocked_days,
            (SELECT COUNT(*) FROM stay_availability sa 
             WHERE sa.room_id = sr.room_id 
             AND sa.date >= ? 
             AND sa.date <= LAST_DAY(?) 
             AND sa.price_override IS NOT NULL) as special_prices
        FROM stay_rooms sr
        WHERE sr.stay_id = ? AND sr.is_active = 1
        ORDER BY sr.base_price
    ");
    $firstDay = "$year-$month-01";
    $stmt->execute([$firstDay, $firstDay, $firstDay, $firstDay, $propertyId]);
    $rooms = $stmt->fetchAll();
    
    // Get property details
    $stmt = $db->prepare("
        SELECT stay_name, city, check_in_time, check_out_time
        FROM stays 
        WHERE stay_id = ? AND owner_id = ?
    ");
    $stmt->execute([$propertyId, $userId]);
    $currentProperty = $stmt->fetch();
}

// Get all bookings for the selected month
$bookings = [];
if ($propertyId > 0 && !empty($rooms)) {
    $roomIds = array_column($rooms, 'room_id');
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    
    $stmt = $db->prepare("
        SELECT 
            b.*,
            sr.room_name,
            sr.room_id,
            u.first_name,
            u.last_name,
            DATEDIFF(b.check_out_date, b.check_in_date) as nights
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE sr.room_id IN ($placeholders)
        AND (
            (b.check_in_date BETWEEN ? AND ?)
            OR (b.check_out_date BETWEEN ? AND ?)
            OR (? BETWEEN b.check_in_date AND b.check_out_date)
        )
        AND b.status IN ('confirmed', 'checked_in')
        ORDER BY b.check_in_date
    ");
    
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));
    $params = array_merge($roomIds, [$firstDay, $lastDay, $firstDay, $lastDay, $firstDay]);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
}

// Get availability overrides
$availability = [];
if ($propertyId > 0 && !empty($rooms)) {
    $roomIds = array_column($rooms, 'room_id');
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    
    $stmt = $db->prepare("
        SELECT * FROM stay_availability
        WHERE room_id IN ($placeholders)
        AND date >= ? AND date <= ?
    ");
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));
    $params = array_merge($roomIds, [$firstDay, $lastDay]);
    $stmt->execute($params);
    $availData = $stmt->fetchAll();
    
    foreach ($availData as $avail) {
        $availability[$avail['room_id']][$avail['date']] = $avail;
    }
}

// Get rates summary
$rates = [];
if ($propertyId > 0 && !empty($rooms)) {
    $stmt = $db->prepare("
        SELECT 
            MIN(base_price) as min_rate,
            MAX(base_price) as max_rate,
            AVG(base_price) as avg_rate
        FROM stay_rooms
        WHERE stay_id = ? AND is_active = 1
    ");
    $stmt->execute([$propertyId]);
    $rates = $stmt->fetch();
}

// Get monthly stats
$stats = [
    'total_bookings' => count($bookings),
    'total_nights' => 0,
    'total_guests' => 0,
    'occupancy_rate' => 0,
    'revenue' => 0,
    'blocked_days' => 0
];

$daysInMonth = date('t', strtotime("$year-$month-01"));
$totalPossibleNights = count($rooms) * $daysInMonth;

foreach ($bookings as $booking) {
    $stats['total_nights'] += $booking['nights'];
    $stats['total_guests'] += $booking['num_guests'];
    $stats['revenue'] += $booking['total_amount'];
}

$stats['occupancy_rate'] = $totalPossibleNights > 0 
    ? round(($stats['total_nights'] / $totalPossibleNights) * 100, 1) 
    : 0;

foreach ($availability as $roomAvail) {
    foreach ($roomAvail as $avail) {
        if ($avail['is_blocked']) {
            $stats['blocked_days']++;
        }
    }
}

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Week day names
$weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Check for success messages
$success = '';
if (isset($_GET['success'])) {
    $success = "Availability updated successfully!";
} elseif (isset($_GET['bulk_updated'])) {
    $success = "Updated " . intval($_GET['bulk_updated']) . " days successfully!";
} elseif (isset($_GET['copied'])) {
    $success = "Copied " . intval($_GET['copied']) . " days from previous period!";
} elseif (isset($_GET['all_blocked'])) {
    $success = "Blocked " . intval($_GET['all_blocked']) . " room-days successfully!";
} elseif (isset($_GET['all_cleared'])) {
    $success = "Cleared " . intval($_GET['all_cleared']) . " room-days successfully!";
}
?>

<style>
/* Calendar Specific Styles */
.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.calendar-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.calendar-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Property Selector */
.property-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.property-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--booking-text);
}

.property-selector select {
    min-width: 300px;
    padding: 10px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Stats Cards */
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

.stat-footer {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--booking-border);
}

/* Calendar Navigation */
.calendar-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.nav-buttons {
    display: flex;
    gap: 8px;
}

.nav-btn {
    padding: 8px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.nav-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.current-month {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.view-toggle {
    display: flex;
    gap: 4px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.view-toggle .view-btn {
    padding: 8px 16px;
    background: white;
    border: none;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.view-toggle .view-btn.active {
    background: var(--booking-blue);
    color: white;
}

/* Room Legend */
.room-legend {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
}

.legend-color {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

.legend-color.booked { background: var(--booking-blue); }
.legend-color.blocked { background: var(--booking-danger); }
.legend-color.special { background: var(--booking-success); }
.legend-color.available { background: #e8f5e9; border: 1px dashed var(--booking-success); }

/* Calendar Grid */
.calendar-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    margin-bottom: 24px;
}

.calendar-weekdays {
    display: grid;
    grid-template-columns: 150px repeat(7, 1fr);
    background: var(--booking-gray);
    border-bottom: 1px solid var(--booking-border);
}

.weekday-cell {
    padding: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text);
    text-align: center;
    border-right: 1px solid var(--booking-border);
}

.weekday-cell:last-child {
    border-right: none;
}

.calendar-rows {
    max-height: 600px;
    overflow-y: auto;
}

.calendar-row {
    display: grid;
    grid-template-columns: 150px repeat(7, 1fr);
    border-bottom: 1px solid var(--booking-border);
}

.calendar-row:last-child {
    border-bottom: none;
}

.room-cell {
    padding: 12px;
    background: var(--booking-gray);
    font-weight: 600;
    font-size: 0.8125rem;
    border-right: 1px solid var(--booking-border);
    position: sticky;
    left: 0;
    background: var(--booking-gray);
}

.room-info {
    display: flex;
    flex-direction: column;
}

.room-price {
    font-size: 0.6875rem;
    font-weight: 400;
    color: var(--booking-text-light);
}

.day-cell {
    padding: 8px;
    border-right: 1px solid var(--booking-border);
    min-height: 80px;
    position: relative;
    cursor: pointer;
    transition: all 0.2s;
}

.day-cell:last-child {
    border-right: none;
}

.day-cell:hover {
    background: var(--booking-light-blue);
}

.day-cell.booked {
    background: #e3f2fd;
}

.day-cell.booked:hover {
    background: #bbdefb;
}

.day-cell.blocked {
    background: #ffebee;
}

.day-cell.blocked:hover {
    background: #ffcdd2;
}

.day-cell.special-price {
    background: #e8f5e9;
}

.day-cell.special-price:hover {
    background: #c8e6c9;
}

.day-number {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.day-content {
    font-size: 0.6875rem;
}

.booking-indicator {
    background: var(--booking-blue);
    color: white;
    padding: 2px 4px;
    border-radius: 2px;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.blocked-indicator {
    background: var(--booking-danger);
    color: white;
    padding: 2px 4px;
    border-radius: 2px;
    margin-top: 2px;
    font-size: 0.625rem;
    text-align: center;
}

.price-indicator {
    background: var(--booking-success);
    color: white;
    padding: 2px 4px;
    border-radius: 2px;
    margin-top: 2px;
    font-size: 0.625rem;
    text-align: center;
}

.multi-booking {
    position: absolute;
    top: 2px;
    right: 2px;
    background: var(--booking-blue);
    color: white;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    font-size: 0.625rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Action Bar */
.action-bar {
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

.action-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.action-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
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
    max-width: 500px;
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

/* Form Styles */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--booking-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.radio-group {
    display: flex;
    gap: 20px;
    margin: 10px 0;
    flex-wrap: wrap;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.days-of-week {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    margin: 15px 0;
}

.day-checkbox {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.day-checkbox:hover {
    border-color: var(--booking-blue);
}

.day-checkbox input {
    margin-bottom: 4px;
}

/* Alert Styles */
.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: #e6f4ea;
    color: #1e7e34;
    border: 1px solid #c3e6cb;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .calendar-weekdays,
    .calendar-row {
        grid-template-columns: 120px repeat(7, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .calendar-container {
        overflow-x: auto;
    }
    
    .calendar-weekdays,
    .calendar-row {
        min-width: 800px;
    }
    
    .property-selector select {
        min-width: 100%;
    }
    
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .action-group {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="calendar-header">
    <div class="calendar-title">
        <h1>Availability Calendar</h1>
        <p>Manage room availability, prices, and view bookings</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="openBulkModal()">
            <i class="bi bi-pencil-square"></i> Bulk Update
        </button>
        <button class="btn-secondary" onclick="openCopyModal()">
            <i class="bi bi-files"></i> Copy from Month
        </button>
    </div>
</div>

<!-- Success Message -->
<?php if (!empty($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
</div>
<?php endif; ?>

<!-- Property Selector -->
<div class="property-selector">
    <label for="propertySelect"><i class="bi bi-building"></i> Select Property:</label>
    <select id="propertySelect" onchange="changeProperty(this.value)">
        <option value="">Choose a property</option>
        <?php foreach ($properties as $prop): ?>
        <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
            <?php echo sanitize($prop['stay_name']); ?> (<?php echo sanitize($prop['city'] ?? 'Rwanda'); ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($propertyId > 0 && $currentProperty): ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($rooms); ?></div>
        <div class="stat-label">Total Rooms</div>
        <div class="stat-footer"><?php echo $rates['min_rate'] ? formatPrice($rates['min_rate']) : 'N/A'; ?> - <?php echo $rates['max_rate'] ? formatPrice($rates['max_rate']) : 'N/A'; ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_bookings']; ?></div>
        <div class="stat-label">Bookings This Month</div>
        <div class="stat-footer"><?php echo $stats['total_nights']; ?> nights • <?php echo $stats['total_guests']; ?> guests</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['occupancy_rate']; ?>%</div>
        <div class="stat-label">Occupancy Rate</div>
        <div class="stat-footer"><?php echo $stats['blocked_days']; ?> blocked days</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['revenue']); ?></div>
        <div class="stat-label">Projected Revenue</div>
        <div class="stat-footer">For this month</div>
    </div>
</div>

<!-- Calendar Navigation -->
<div class="calendar-nav">
    <div class="nav-buttons">
        <a href="?property=<?php echo $propertyId; ?>&year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="nav-btn">
            <i class="bi bi-chevron-left"></i> <?php echo $monthNames[$prevMonth]; ?>
        </a>
        <a href="?property=<?php echo $propertyId; ?>&year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" class="nav-btn">
            <i class="bi bi-calendar3"></i> Today
        </a>
        <a href="?property=<?php echo $propertyId; ?>&year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="nav-btn">
            <?php echo $monthNames[$nextMonth]; ?> <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    
    <div class="current-month">
        <?php echo $monthNames[intval($month)] . ' ' . $year; ?>
    </div>
    
    <div class="view-toggle">
        <a href="?property=<?php echo $propertyId; ?>&view=month" class="view-btn <?php echo $view == 'month' ? 'active' : ''; ?>">Month</a>
        <a href="?property=<?php echo $propertyId; ?>&view=week" class="view-btn <?php echo $view == 'week' ? 'active' : ''; ?>">Week</a>
        <a href="?property=<?php echo $propertyId; ?>&view=day" class="view-btn <?php echo $view == 'day' ? 'active' : ''; ?>">Day</a>
    </div>
</div>

<!-- Room Legend -->
<div class="room-legend">
    <div class="legend-item">
        <div class="legend-color available"></div>
        <span>Available</span>
    </div>
    <div class="legend-item">
        <div class="legend-color booked"></div>
        <span>Booked</span>
    </div>
    <div class="legend-item">
        <div class="legend-color blocked"></div>
        <span>Blocked / Unavailable</span>
    </div>
    <div class="legend-item">
        <div class="legend-color special"></div>
        <span>Special Price</span>
    </div>
</div>

<!-- Calendar Grid -->
<div class="calendar-container">
    <div class="calendar-weekdays">
        <div class="weekday-cell">Rooms</div>
        <?php for ($i = 0; $i < 7; $i++): 
            $dayNum = $i + 1;
        ?>
        <div class="weekday-cell">
            <?php echo $weekDays[$i]; ?>
        </div>
        <?php endfor; ?>
    </div>
    
    <div class="calendar-rows">
        <?php 
        // Get all days in month
        $daysInMonth = date('t', strtotime("$year-$month-01"));
        $firstDayOfMonth = new DateTime("$year-$month-01");
        $firstDayWeekday = intval($firstDayOfMonth->format('w')); // 0 = Sunday
        
        foreach ($rooms as $room): 
            $roomBookings = array_filter($bookings, function($b) use ($room) {
                return $b['room_id'] == $room['room_id'];
            });
        ?>
        <div class="calendar-row">
            <div class="room-cell">
                <div class="room-info">
                    <span><?php echo sanitize($room['room_name']); ?></span>
                    <span class="room-price"><?php echo formatPrice($room['base_price']); ?>/night</span>
                </div>
            </div>
            
            <?php 
            // Show all days of the month (split into weeks)
            for ($day = 1; $day <= min(7, $daysInMonth); $day++):
                $currentDate = sprintf("%04d-%02d-%02d", $year, $month, $day);
                $isToday = $currentDate == date('Y-m-d');
                
                // Check if booked
                $dayBookings = array_filter($roomBookings, function($b) use ($currentDate) {
                    return $currentDate >= $b['check_in_date'] && $currentDate < $b['check_out_date'];
                });
                $bookingCount = count($dayBookings);
                
                // Check availability override
                $avail = isset($availability[$room['room_id']][$currentDate]) 
                    ? $availability[$room['room_id']][$currentDate] 
                    : null;
                
                $cellClass = 'day-cell';
                $cellTitle = '';
                
                if ($bookingCount > 0) {
                    $cellClass .= ' booked';
                    $guestNames = [];
                    foreach ($dayBookings as $booking) {
                        $guestNames[] = $booking['first_name'] . ' ' . substr($booking['last_name'] ?? '', 0, 1) . '.';
                    }
                    $cellTitle = "Booked by: " . implode(', ', $guestNames);
                } elseif ($avail && $avail['is_blocked']) {
                    $cellClass .= ' blocked';
                    $cellTitle = "Blocked";
                } elseif ($avail && $avail['price_override']) {
                    $cellClass .= ' special-price';
                    $cellTitle = "Special price: " . formatPrice($avail['price_override']);
                }
                
                if ($isToday) {
                    $cellClass .= ' today';
                }
            ?>
            <div class="<?php echo $cellClass; ?>" 
                 title="<?php echo $cellTitle; ?>"
                 onclick="openDayModal(<?php echo $room['room_id']; ?>, '<?php echo $currentDate; ?>', '<?php echo htmlspecialchars($room['room_name']); ?>')">
                
                <div class="day-number"><?php echo $day; ?></div>
                
                <?php if ($bookingCount > 0): ?>
                    <div class="booking-indicator">
                        <i class="bi bi-person"></i> <?php echo $bookingCount; ?>
                    </div>
                    <?php if ($bookingCount > 1): ?>
                    <div class="multi-booking"><?php echo $bookingCount; ?></div>
                    <?php endif; ?>
                <?php elseif ($avail && $avail['is_blocked']): ?>
                    <div class="blocked-indicator">
                        <i class="bi bi-lock"></i> Blocked
                    </div>
                <?php elseif ($avail && $avail['price_override']): ?>
                    <div class="price-indicator">
                        <i class="bi bi-tag"></i> <?php echo formatPrice($avail['price_override']); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Action Bar -->
<div class="action-bar">
    <div class="action-group">
        <span class="action-label">Quick Actions:</span>
        <button class="btn-secondary btn-sm" onclick="openBlockAllModal()">
            <i class="bi bi-calendar-plus"></i> Block all rooms
        </button>
        <button class="btn-secondary btn-sm" onclick="openSetAllAvailableModal()">
            <i class="bi bi-calendar-check"></i> Set all available
        </button>
    </div>
    
    <div class="action-group">
        <span class="action-label">Price Rules:</span>
        <button class="btn-secondary btn-sm" onclick="openSeasonModal()">
            <i class="bi bi-flower1"></i> Set season prices
        </button>
        <button class="btn-secondary btn-sm" onclick="openWeekendModal()">
            <i class="bi bi-calendar-week"></i> Weekend pricing
        </button>
    </div>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
    <i class="bi bi-calendar2-week" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
    <h3 style="margin-top: 16px; font-size: 1.125rem;">Select a property</h3>
    <p style="color: var(--booking-text-light); margin-top: 8px;">Please select a property from the dropdown above to view its calendar.</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Day Detail Modal -->
<div class="modal" id="dayModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="dayModalTitle">Manage Availability</h3>
            <button class="modal-close" onclick="closeModal('dayModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="dayForm">
            <div class="modal-body">
                <input type="hidden" name="room_id" id="day_room_id" value="0">
                <input type="hidden" name="date" id="day_date" value="">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--booking-gray); border-radius: var(--radius-sm);">
                    <div><strong id="day_room_name"></strong></div>
                    <div style="font-size: 0.875rem; color: var(--booking-text-light);" id="day_date_display"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="available" checked>
                            <span>Available</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="block">
                            <span>Block / Unavailable</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="price_override">
                            <span>Special Price</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="priceOverrideField" style="display: none;">
                    <label class="form-label">Special Price (RWF)</label>
                    <input type="number" name="price_override" class="form-control" min="0" step="1000" placeholder="Enter special price">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('dayModal')" style="padding: 10px 20px; border: 1px solid var(--booking-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" name="update_availability" class="btn-primary" style="padding: 10px 20px; background: var(--booking-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal" id="bulkModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bulk Update Availability</h3>
            <button class="modal-close" onclick="closeModal('bulkModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Room</label>
                    <select name="room_id" class="form-control" required>
                        <option value="">Select a room</option>
                        <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo $room['room_id']; ?>">
                            <?php echo sanitize($room['room_name']); ?> (<?php echo formatPrice($room['base_price']); ?>/night)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="date" name="start_date" class="form-control" required>
                        <span>to</span>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific days (optional)</label>
                    <div class="days-of-week">
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="sunday"> Sun
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="monday"> Mon
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="tuesday"> Tue
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="wednesday"> Wed
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="thursday"> Thu
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="friday"> Fri
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="saturday"> Sat
                        </label>
                    </div>
                    <small style="color: var(--booking-text-light);">Leave empty to apply to all days</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="bulk_action" class="form-control" onchange="toggleBulkPrice(this.value)">
                        <option value="block">Block / Unavailable</option>
                        <option value="available">Set Available</option>
                        <option value="set_price">Set Special Price</option>
                    </select>
                </div>
                
                <div class="form-group" id="bulkPriceField" style="display: none;">
                    <label class="form-label">Price (RWF)</label>
                    <input type="number" name="bulk_price" class="form-control" min="0" step="1000" placeholder="Enter price">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')" style="padding: 10px 20px; border: 1px solid var(--booking-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" name="bulk_update" class="btn-primary" style="padding: 10px 20px; background: var(--booking-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">Apply Bulk Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy from Month Modal -->
<div class="modal" id="copyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Copy Availability from Previous Month</h3>
            <button class="modal-close" onclick="closeModal('copyModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Room</label>
                    <select name="room_id" class="form-control" required>
                        <option value="">Select a room</option>
                        <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo $room['room_id']; ?>">
                            <?php echo sanitize($room['room_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Copy from</label>
                    <select name="source_month" class="form-control" id="sourceMonthSelect" required>
                        <?php
                        $prevMonthDate = new DateTime("$year-$month-01");
                        $prevMonthDate->modify('-1 month');
                        for ($i = 1; $i <= 6; $i++):
                            $sourceYear = intval($prevMonthDate->format('Y'));
                            $sourceMonth = $prevMonthDate->format('m');
                            $sourceMonthIndex = intval($sourceMonth);
                        ?>
                        <option value="<?php echo $sourceMonth; ?>" data-year="<?php echo $sourceYear; ?>">
                            <?php echo $monthNames[$sourceMonthIndex] . ' ' . $sourceYear; ?>
                        </option>
                        <?php 
                            $prevMonthDate->modify('-1 month');
                        endfor; 
                        ?>
                    </select>
                    <input type="hidden" name="source_year" id="source_year" value="">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Copy to</label>
                    <select name="target_month" class="form-control" id="targetMonthSelect" required>
                        <?php 
                        $currentMonthIndex = intval($month);
                        ?>
                        <option value="<?php echo $month; ?>" data-year="<?php echo $year; ?>">
                            <?php echo $monthNames[$currentMonthIndex] . ' ' . $year; ?> (Current)
                        </option>
                        <?php
                        $nextMonthDate = new DateTime("$year-$month-01");
                        $nextMonthDate->modify('+1 month');
                        for ($i = 1; $i <= 3; $i++):
                            $targetYear = intval($nextMonthDate->format('Y'));
                            $targetMonth = $nextMonthDate->format('m');
                            $targetMonthIndex = intval($targetMonth);
                        ?>
                        <option value="<?php echo $targetMonth; ?>" data-year="<?php echo $targetYear; ?>">
                            <?php echo $monthNames[$targetMonthIndex] . ' ' . $targetYear; ?>
                        </option>
                        <?php 
                            $nextMonthDate->modify('+1 month');
                        endfor; 
                        ?>
                    </select>
                    <input type="hidden" name="target_year" id="target_year" value="">
                </div>
                
                <div style="background: var(--booking-gray); padding: 16px; border-radius: var(--radius-sm);">
                    <i class="bi bi-info-circle"></i>
                    <small>This will copy all blocked dates and special prices from the source month to the target month (same days).</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('copyModal')" style="padding: 10px 20px; border: 1px solid var(--booking-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" name="copy_from" class="btn-primary" style="padding: 10px 20px; background: var(--booking-blue); color: white; border: none; border-radius: 6px; cursor: pointer;">Copy Availability</button>
            </div>
        </form>
    </div>
</div>

<!-- Block All Rooms Modal -->
<div class="modal" id="blockAllModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Block All Rooms</h3>
            <button class="modal-close" onclick="closeModal('blockAllModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin-bottom: 16px; color: #856404;">
                    <i class="bi bi-exclamation-triangle"></i>
                    This will block ALL rooms in this property for the selected date range.
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="date" name="block_start_date" class="form-control" required>
                        <span>to</span>
                        <input type="date" name="block_end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <input type="text" name="block_reason" class="form-control" placeholder="e.g., Maintenance, Holiday, etc.">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('blockAllModal')" style="padding: 10px 20px; border: 1px solid var(--booking-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" name="block_all_rooms" class="btn-primary" style="padding: 10px 20px; background: var(--booking-danger); color: white; border: none; border-radius: 6px; cursor: pointer;">Block All Rooms</button>
            </div>
        </form>
    </div>
</div>

<!-- Set All Available Modal -->
<div class="modal" id="setAllAvailableModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Set All Rooms Available</h3>
            <button class="modal-close" onclick="closeModal('setAllAvailableModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div style="background: #d4edda; padding: 12px; border-radius: 6px; margin-bottom: 16px; color: #155724;">
                    <i class="bi bi-info-circle"></i>
                    This will remove all blocks and special prices for ALL rooms in this property.
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="date" name="avail_start_date" class="form-control" required>
                        <span>to</span>
                        <input type="date" name="avail_end_date" class="form-control" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('setAllAvailableModal')" style="padding: 10px 20px; border: 1px solid var(--booking-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" name="set_all_available" class="btn-primary" style="padding: 10px 20px; background: var(--booking-success); color: white; border: none; border-radius: 6px; cursor: pointer;">Set All Available</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// PROPERTY SELECTION
// ============================================
function changeProperty(propertyId) {
    if (propertyId) {
        window.location.href = 'calendar.php?property=' + propertyId;
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

// Close on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = 'auto';
    }
});

// ============================================
// DAY MODAL
// ============================================
function openDayModal(roomId, date, roomName) {
    document.getElementById('day_room_id').value = roomId;
    document.getElementById('day_date').value = date;
    document.getElementById('day_room_name').textContent = roomName;
    
    // Format date for display
    const dateObj = new Date(date + 'T12:00:00');
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('day_date_display').textContent = dateObj.toLocaleDateString('en-US', options);
    
    // Reset form
    document.querySelector('input[name="availability_action"][value="available"]').checked = true;
    document.getElementById('priceOverrideField').style.display = 'none';
    document.querySelector('input[name="price_override"]').value = '';
    
    openModal('dayModal');
}

// Show/hide price field based on selection
document.querySelectorAll('input[name="availability_action"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const priceField = document.getElementById('priceOverrideField');
        priceField.style.display = this.value === 'price_override' ? 'block' : 'none';
    });
});

// ============================================
// BULK MODAL
// ============================================
function openBulkModal() {
    // Reset form
    document.querySelectorAll('.day-checkbox input').forEach(cb => cb.checked = false);
    document.getElementById('bulkPriceField').style.display = 'none';
    openModal('bulkModal');
}

function toggleBulkPrice(value) {
    const priceField = document.getElementById('bulkPriceField');
    priceField.style.display = value === 'set_price' ? 'block' : 'none';
}

// ============================================
// COPY MODAL
// ============================================
function openCopyModal() {
    openModal('copyModal');
}

// Update hidden year fields when month selection changes
document.getElementById('sourceMonthSelect')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const year = selected.getAttribute('data-year');
    if (year) {
        document.getElementById('source_year').value = year;
    }
});

document.getElementById('targetMonthSelect')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const year = selected.getAttribute('data-year');
    if (year) {
        document.getElementById('target_year').value = year;
    }
});

// Initialize year fields on page load
document.addEventListener('DOMContentLoaded', function() {
    const sourceSelect = document.getElementById('sourceMonthSelect');
    const targetSelect = document.getElementById('targetMonthSelect');
    
    if (sourceSelect && sourceSelect.options.length > 0) {
        document.getElementById('source_year').value = sourceSelect.options[0].getAttribute('data-year');
    }
    if (targetSelect && targetSelect.options.length > 0) {
        document.getElementById('target_year').value = targetSelect.options[0].getAttribute('data-year');
    }
});

// ============================================
// QUICK ACTIONS
// ============================================
function openBlockAllModal() {
    openModal('blockAllModal');
}

function openSetAllAvailableModal() {
    openModal('setAllAvailableModal');
}

function openSeasonModal() {
    alert('Season pricing feature coming soon!');
}

function openWeekendModal() {
    alert('Weekend pricing feature coming soon!');
}

// ============================================
// EXPORT CALENDAR
// ============================================
function exportCalendar() {
    alert('Export feature coming soon!');
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>