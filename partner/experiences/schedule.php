<?php
$pageTitle = 'Schedule & Availability';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameters
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;
$view = isset($_GET['view']) ? $_GET['view'] : 'calendar';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// Calculate previous and next month (fix for undefined array key)
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
// HANDLE AVAILABILITY ACTIONS
// ============================================

// Update availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_availability'])) {
    $tierId = intval($_POST['tier_id']);
    $date = $_POST['date'];
    $action = $_POST['availability_action'];
    $maxBookings = isset($_POST['max_bookings']) ? intval($_POST['max_bookings']) : 10;
    $priceOverride = isset($_POST['price_override']) ? floatval($_POST['price_override']) : null;
    $notes = isset($_POST['notes']) ? sanitize($_POST['notes']) : '';
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    if ($stmt->fetch()) {
        // Check for existing bookings on this date for this tier
        $stmt = $db->prepare("
            SELECT COUNT(*) as booking_count, SUM(num_participants) as total_participants
            FROM bookings b
            WHERE b.attraction_tier_id = ? 
            AND b.experience_date = ?
            AND b.status IN ('confirmed', 'pending')
        ");
        $stmt->execute([$tierId, $date]);
        $existingBookings = $stmt->fetch();
        
        $bookingCount = $existingBookings['booking_count'] ?? 0;
        $participantCount = $existingBookings['total_participants'] ?? 0;
        
        if ($action === 'block') {
            // Check if there are existing bookings before blocking
            if ($bookingCount > 0) {
                $error = "Cannot block this date - there are $bookingCount existing booking(s) with $participantCount participant(s). Please cancel or reschedule them first.";
            } else {
                // Block date (set is_blocked to true)
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked, notes)
                    VALUES (?, ?, 0, 0, 1, ?)
                    ON DUPLICATE KEY UPDATE max_bookings = 0, is_blocked = 1, price_override = NULL, notes = ?
                ");
                $stmt->execute([$tierId, $date, $notes, $notes]);
                $success = "Date blocked successfully";
            }
        } elseif ($action === 'available') {
            // Set as available
            $stmt = $db->prepare("
                INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked, notes)
                VALUES (?, ?, ?, 0, 0, ?)
                ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL, notes = ?
            ");
            $stmt->execute([$tierId, $date, $maxBookings, $notes, $maxBookings, $notes]);
            $success = "Availability updated successfully";
        } elseif ($action === 'price_override') {
            // Check if new price is lower than existing bookings (optional warning)
            $stmt = $db->prepare("
                SELECT base_price FROM attraction_tiers WHERE tier_id = ?
            ");
            $stmt->execute([$tierId]);
            $tier = $stmt->fetch();
            $regularPrice = $tier['base_price'] ?? 0;
            
            if ($priceOverride < $regularPrice && $bookingCount > 0) {
                $warning = "Warning: Setting a lower price than regular for a date with $bookingCount existing booking(s).";
            }
            
            $stmt = $db->prepare("
                INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, price_override, is_blocked, notes)
                VALUES (?, ?, ?, 0, ?, 0, ?)
                ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = ?, notes = ?
            ");
            $stmt->execute([$tierId, $date, $maxBookings, $priceOverride, $notes, $maxBookings, $priceOverride, $notes]);
            $success = "Special price set successfully";
        }
    }
}

// Bulk update availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $tierId = intval($_POST['tier_id']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $action = $_POST['bulk_action'];
    $maxBookings = isset($_POST['max_bookings']) ? intval($_POST['max_bookings']) : 10;
    $priceOverride = isset($_POST['bulk_price']) ? floatval($_POST['bulk_price']) : null;
    $daysOfWeek = isset($_POST['days_of_week']) ? $_POST['days_of_week'] : [];
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    if ($stmt->fetch()) {
        // Generate all dates in range
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($current, $interval, $end->modify('+1 day'));
        
        $updated = 0;
        $skipped = 0;
        
        foreach ($dateRange as $dateObj) {
            $date = $dateObj->format('Y-m-d');
            
            // Check if this day of week should be updated
            $dayOfWeek = strtolower($dateObj->format('l'));
            
            if (empty($daysOfWeek) || in_array($dayOfWeek, $daysOfWeek)) {
                // Check for existing bookings on this date
                $stmt = $db->prepare("
                    SELECT COUNT(*) as booking_count 
                    FROM bookings b
                    WHERE b.attraction_tier_id = ? 
                    AND b.experience_date = ?
                    AND b.status IN ('confirmed', 'pending')
                ");
                $stmt->execute([$tierId, $date]);
                $existingBookings = $stmt->fetchColumn();
                
                if ($action === 'block' && $existingBookings > 0) {
                    // Skip blocking if there are bookings
                    $skipped++;
                    continue;
                }
                
                if ($action === 'block') {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                        VALUES (?, ?, 0, 0, 1)
                        ON DUPLICATE KEY UPDATE max_bookings = 0, is_blocked = 1, price_override = NULL
                    ");
                    $stmt->execute([$tierId, $date]);
                } elseif ($action === 'available') {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                        VALUES (?, ?, ?, 0, 0)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL
                    ");
                    $stmt->execute([$tierId, $date, $maxBookings, $maxBookings]);
                } elseif ($action === 'set_price' && $priceOverride) {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, price_override, is_blocked)
                        VALUES (?, ?, ?, 0, ?, 0)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = ?
                    ");
                    $stmt->execute([$tierId, $date, $maxBookings, $priceOverride, $maxBookings, $priceOverride]);
                }
                $updated++;
            }
        }
        
        $message = "Updated $updated days successfully";
        if ($skipped > 0) {
            $message .= ". Skipped $skipped day(s) with existing bookings.";
        }
        $success = $message;
    }
}

// Copy from previous month
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_from'])) {
    $tierId = intval($_POST['tier_id']);
    $sourceMonth = intval($_POST['source_month']);
    $sourceYear = intval($_POST['source_year']);
    $targetMonth = intval($_POST['target_month']);
    $targetYear = intval($_POST['target_year']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    if ($stmt->fetch()) {
        // Get source data
        $stmt = $db->prepare("
            SELECT * FROM attraction_availability 
            WHERE tier_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
        ");
        $stmt->execute([$tierId, $sourceYear, $sourceMonth]);
        $sourceData = $stmt->fetchAll();
        
        $copied = 0;
        $skipped = 0;
        
        foreach ($sourceData as $data) {
            $sourceDate = new DateTime($data['date']);
            $targetDate = new DateTime($targetYear . '-' . $targetMonth . '-' . $sourceDate->format('d'));
            
            // Check if valid date (e.g., not Feb 30)
            if ($targetDate->format('m') == $targetMonth) {
                $targetDateStr = $targetDate->format('Y-m-d');
                
                // Check for existing bookings on target date
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM bookings b
                    WHERE b.attraction_tier_id = ? 
                    AND b.experience_date = ?
                    AND b.status IN ('confirmed', 'pending')
                ");
                $stmt->execute([$tierId, $targetDateStr]);
                $hasBookings = $stmt->fetchColumn() > 0;
                
                if ($hasBookings) {
                    $skipped++;
                    continue;
                }
                
                if ($data['is_blocked']) {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked, notes)
                        VALUES (?, ?, 0, 0, 1, ?)
                        ON DUPLICATE KEY UPDATE max_bookings = 0, is_blocked = 1, price_override = NULL, notes = ?
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['notes'], $data['notes']]);
                } elseif ($data['price_override']) {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, price_override, notes)
                        VALUES (?, ?, ?, 0, ?, ?)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = ?, notes = ?
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['max_bookings'], $data['price_override'], $data['notes'], $data['max_bookings'], $data['price_override'], $data['notes']]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                        VALUES (?, ?, ?, 0, 0)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL, notes = NULL
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['max_bookings'], $data['max_bookings']]);
                }
                $copied++;
            }
        }
        
        $message = "Copied $copied days from previous month";
        if ($skipped > 0) {
            $message .= ". Skipped $skipped day(s) with existing bookings.";
        }
        $success = $message;
    }
}

// ============================================
// GET DATA
// ============================================

// Get all experiences for this partner
$stmt = $db->prepare("
    SELECT attraction_id, attraction_name 
    FROM attractions 
    WHERE owner_id = ? 
    ORDER BY attraction_name
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// If no experience selected, use the first one
if ($experienceId === 0 && !empty($experiences)) {
    $experienceId = $experiences[0]['attraction_id'];
}

// Get tiers for selected experience
$tiers = [];
$currentExperience = null;
if ($experienceId > 0) {
    // Get experience name
    foreach ($experiences as $exp) {
        if ($exp['attraction_id'] == $experienceId) {
            $currentExperience = $exp;
            break;
        }
    }
    
    // Get tiers
    $stmt = $db->prepare("
        SELECT * FROM attraction_tiers 
        WHERE attraction_id = ? AND is_active = 1
        ORDER BY base_price ASC
    ");
    $stmt->execute([$experienceId]);
    $tiers = $stmt->fetchAll();
}

// Get availability for the selected month
$availability = [];
$bookings = [];
$stats = [
    'total_bookings' => 0,
    'total_guests' => 0,
    'total_revenue' => 0,
    'available_slots' => 0,
    'blocked_days' => 0,
    'fully_booked_days' => 0
];

if (!empty($tiers)) {
    $tierIds = array_column($tiers, 'tier_id');
    $placeholders = implode(',', array_fill(0, count($tierIds), '?'));
    
    // Get availability
    $stmt = $db->prepare("
        SELECT * FROM attraction_availability 
        WHERE tier_id IN ($placeholders)
        AND YEAR(date) = ? AND MONTH(date) = ?
    ");
    $params = array_merge($tierIds, [$year, $month]);
    $stmt->execute($params);
    $availData = $stmt->fetchAll();
    
    foreach ($availData as $avail) {
        $availability[$avail['tier_id']][$avail['date']] = $avail;
        if ($avail['is_blocked']) {
            $stats['blocked_days']++;
        } else {
            $available = $avail['max_bookings'] - $avail['bookings_made'];
            $stats['available_slots'] += max(0, $available);
            if ($available <= 0) {
                $stats['fully_booked_days']++;
            }
        }
    }
    
    // Get bookings for this month
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));
    
    $stmt = $db->prepare("
        SELECT b.*, at.tier_name, at.base_price, u.first_name, u.last_name, u.email
        FROM bookings b
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE at.tier_id IN ($placeholders)
        AND b.experience_date BETWEEN ? AND ?
        AND b.status IN ('confirmed', 'completed', 'pending')
        ORDER BY b.experience_date ASC
    ");
    $params = array_merge($tierIds, [$firstDay, $lastDay]);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
    
    // Calculate stats
    foreach ($bookings as $booking) {
        $stats['total_bookings']++;
        $stats['total_guests'] += $booking['num_participants'] ?? 1;
        $stats['total_revenue'] += $booking['total_amount'];
    }
}

// Get upcoming bookings (next 30 days)
$upcomingBookings = [];
if (!empty($tiers)) {
    $stmt = $db->prepare("
        SELECT b.*, a.attraction_name, at.tier_name, u.first_name, u.last_name, u.email, u.phone
        FROM bookings b
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        JOIN attractions a ON at.attraction_id = a.attraction_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE a.owner_id = ?
        AND b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND b.status IN ('confirmed', 'pending')
        ORDER BY b.experience_date ASC
    ");
    $stmt->execute([$userId]);
    $upcomingBookings = $stmt->fetchAll();
}

// Month names with proper integer keys
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get first day of month and number of days
$firstDayOfMonth = new DateTime("$year-$month-01");
$lastDayOfMonth = new DateTime($firstDayOfMonth->format('Y-m-t'));
$daysInMonth = $lastDayOfMonth->format('d');
$firstDayWeekday = $firstDayOfMonth->format('w'); // 0 (Sunday) to 6 (Saturday)

// Week day names
$weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Fix for month display (convert to integer for array key)
$currentMonthIndex = intval($month);
$prevMonthIndex = intval($prevMonth);
$nextMonthIndex = intval($nextMonth);
?>

<!-- Include FullCalendar CSS and JS -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

<style>
/* Schedule Management Specific Styles - same as before */
.schedule-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.schedule-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.schedule-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 0;
}

/* Experience Selector */
.experience-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.experience-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--exp-text);
}

.experience-selector select {
    min-width: 350px;
    padding: 10px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
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
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
}

/* View Tabs */
.view-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--exp-border);
    padding-bottom: 0;
}

.view-tab {
    padding: 10px 20px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--exp-text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.view-tab:hover {
    color: var(--exp-purple);
}

.view-tab.active {
    color: var(--exp-purple);
}

.view-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--exp-purple);
}

/* Calendar Navigation */
.calendar-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    background: white;
    padding: 12px 16px;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
}

.nav-buttons {
    display: flex;
    gap: 8px;
}

.nav-btn {
    padding: 8px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--exp-text);
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
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.current-month {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-text);
}

/* Calendar Grid */
.calendar-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    margin-bottom: 24px;
}

.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--exp-light-purple);
    border-bottom: 1px solid var(--exp-border);
}

.weekday-cell {
    padding: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--exp-purple);
    text-align: center;
    border-right: 1px solid var(--exp-border);
}

.weekday-cell:last-child {
    border-right: none;
}

.calendar-rows {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

.calendar-cell {
    min-height: 100px;
    padding: 8px;
    border-right: 1px solid var(--exp-border);
    border-bottom: 1px solid var(--exp-border);
    background: white;
    position: relative;
    cursor: pointer;
    transition: all 0.2s;
}

.calendar-cell:nth-child(7n) {
    border-right: none;
}

.calendar-cell:hover {
    background: var(--exp-light-purple);
}

.calendar-cell.other-month {
    background: var(--exp-gray);
    color: var(--exp-text-light);
}

.calendar-cell.today {
    background: #fff4e6;
    border: 2px solid var(--exp-warning);
}

.calendar-cell.has-bookings {
    background: #e6f4ea;
}

.calendar-cell.has-blocked {
    background: #fce8e8;
}

.calendar-cell.has-special {
    background: #fff3cd;
}

.calendar-cell.fully-booked {
    background: #ffe6e6;
}

.day-number {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 6px;
}

.booking-indicator {
    font-size: 0.625rem;
    padding: 2px 4px;
    background: var(--exp-purple);
    color: white;
    border-radius: 2px;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.blocked-indicator {
    font-size: 0.625rem;
    padding: 2px 4px;
    background: var(--exp-danger);
    color: white;
    border-radius: 2px;
    margin-top: 2px;
    text-align: center;
}

.special-indicator {
    font-size: 0.625rem;
    padding: 2px 4px;
    background: var(--exp-warning);
    color: white;
    border-radius: 2px;
    margin-top: 2px;
    text-align: center;
}

.fully-booked-indicator {
    font-size: 0.625rem;
    padding: 2px 4px;
    background: #dc3545;
    color: white;
    border-radius: 2px;
    margin-top: 2px;
    text-align: center;
}

/* Tier Selector */
.tier-selector {
    margin-bottom: 20px;
    padding: 16px;
    background: var(--exp-gray);
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
}

.tier-selector h3 {
    font-size: 0.9375rem;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--exp-purple);
}

.tier-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tier-btn {
    padding: 8px 16px;
    border: 1px solid var(--exp-border);
    border-radius: 100px;
    background: white;
    color: var(--exp-text);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.tier-btn:hover {
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.tier-btn.active {
    background: var(--exp-purple);
    color: white;
    border-color: var(--exp-purple);
}

/* Upcoming Bookings List */
.upcoming-list {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}

.upcoming-item {
    display: flex;
    align-items: center;
    padding: 16px;
    border-bottom: 1px solid var(--exp-border);
    transition: all 0.2s;
}

.upcoming-item:last-child {
    border-bottom: none;
}

.upcoming-item:hover {
    background: var(--exp-light-purple);
}

.upcoming-date {
    min-width: 60px;
    text-align: center;
    margin-right: 16px;
}

.upcoming-date .day {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    line-height: 1.2;
}

.upcoming-date .month {
    font-size: 0.625rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.upcoming-info {
    flex: 1;
}

.upcoming-title {
    font-weight: 600;
    font-size: 0.9375rem;
    margin-bottom: 4px;
}

.upcoming-details {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    display: flex;
    gap: 16px;
}

.upcoming-status {
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.status-confirmed {
    background: #e6f4ea;
    color: #10b981;
}

.status-pending {
    background: #fff4e6;
    color: #f59e0b;
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
    max-width: 500px;
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
    transition: all 0.2s;
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
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

.radio-group {
    display: flex;
    gap: 20px;
    margin: 10px 0;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.875rem;
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
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.day-checkbox:hover {
    border-color: var(--exp-purple);
}

.day-checkbox input {
    margin-bottom: 4px;
}

/* Alert Messages */
.alert-success {
    background: #e6f4ea;
    color: #10b981;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-danger {
    background: #fce8e8;
    color: #ef4444;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-warning {
    background: #fff4e6;
    color: #f59e0b;
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .calendar-cell {
        min-height: 60px;
    }
    
    .experience-selector select {
        min-width: 100%;
    }
    
    .calendar-nav {
        flex-direction: column;
        gap: 12px;
    }
    
    .upcoming-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .upcoming-date {
        margin-right: 0;
    }
}
</style>

<div class="schedule-header">
    <div class="schedule-title">
        <h1>Schedule & Availability</h1>
        <p>Manage experience schedules, availability, and bookings</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="refreshCalendar()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Experience Selector -->
<div class="experience-selector">
    <label for="experienceSelect"><i class="bi bi-ticket-perforated"></i> Select Experience:</label>
    <select id="experienceSelect" onchange="changeExperience(this.value)">
        <option value="">Choose an experience</option>
        <?php foreach ($experiences as $exp): ?>
        <option value="<?php echo $exp['attraction_id']; ?>" <?php echo $exp['attraction_id'] == $experienceId ? 'selected' : ''; ?>>
            <?php echo sanitize($exp['attraction_name']); ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($experienceId > 0 && $currentExperience): ?>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<?php if (isset($warning)): ?>
<div class="alert-warning">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $warning; ?>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_bookings']; ?></div>
        <div class="stat-label">Bookings This Month</div>
        <div class="stat-footer"><?php echo $stats['total_guests']; ?> guests</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Revenue This Month</div>
        <div class="stat-footer">Confirmed bookings</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['available_slots']; ?></div>
        <div class="stat-label">Available Slots</div>
        <div class="stat-footer"><?php echo $stats['fully_booked_days']; ?> days fully booked</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['blocked_days']; ?></div>
        <div class="stat-label">Blocked Days</div>
        <div class="stat-footer">Unavailable</div>
    </div>
</div>

<!-- View Tabs -->
<div class="view-tabs">
    <button class="view-tab <?php echo $view == 'calendar' ? 'active' : ''; ?>" onclick="switchView('calendar')">
        <i class="bi bi-calendar-week"></i> Calendar View
    </button>
    <button class="view-tab <?php echo $view == 'list' ? 'active' : ''; ?>" onclick="switchView('list')">
        <i class="bi bi-list"></i> List View
    </button>
    <button class="view-tab <?php echo $view == 'upcoming' ? 'active' : ''; ?>" onclick="switchView('upcoming')">
        <i class="bi bi-clock-history"></i> Upcoming Bookings
    </button>
</div>

<!-- CALENDAR VIEW -->
<div id="view-calendar" style="display: <?php echo $view == 'calendar' ? 'block' : 'none'; ?>;">
    
    <!-- Tier Selector -->
    <?php if (count($tiers) > 1): ?>
    <div class="tier-selector">
        <h3>Select Tier:</h3>
        <div class="tier-buttons">
            <button class="tier-btn active" onclick="selectTier('all')">All Tiers</button>
            <?php foreach ($tiers as $tier): ?>
            <button class="tier-btn" onclick="selectTier(<?php echo $tier['tier_id']; ?>)">
                <?php echo sanitize($tier['tier_name']); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Calendar Navigation -->
    <div class="calendar-nav">
        <div class="nav-buttons">
            <a href="?experience=<?php echo $experienceId; ?>&view=calendar&year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="nav-btn">
                <i class="bi bi-chevron-left"></i> <?php echo $monthNames[$prevMonthIndex]; ?>
            </a>
            <a href="?experience=<?php echo $experienceId; ?>&view=calendar&year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" class="nav-btn">
                <i class="bi bi-calendar3"></i> Today
            </a>
            <a href="?experience=<?php echo $experienceId; ?>&view=calendar&year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="nav-btn">
                <?php echo $monthNames[$nextMonthIndex]; ?> <i class="bi bi-chevron-right"></i>
            </a>
        </div>
        
        <div class="current-month">
            <?php echo $monthNames[$currentMonthIndex] . ' ' . $year; ?>
        </div>
        
        <button class="btn-outline" onclick="openBulkModal()">
            <i class="bi bi-pencil-square"></i> Bulk Update
        </button>
    </div>
    
    <!-- Calendar Grid -->
    <div class="calendar-container">
        <div class="calendar-weekdays">
            <?php foreach ($weekDays as $day): ?>
            <div class="weekday-cell"><?php echo $day; ?></div>
            <?php endforeach; ?>
        </div>
        
        <div class="calendar-rows">
            <?php
            // Calculate days from previous month
            $daysFromPrevMonth = $firstDayWeekday;
            $prevMonthDays = $daysFromPrevMonth > 0 ? date('t', strtotime("$prevYear-$prevMonth-01")) : 0;
            
            // Calculate total cells needed
            $totalCells = ceil(($daysFromPrevMonth + $daysInMonth) / 7) * 7;
            
            for ($i = 0; $i < $totalCells; $i++):
                $cellDate = null;
                $cellMonth = $month;
                $cellYear = $year;
                $isCurrentMonth = true;
                
                if ($i < $daysFromPrevMonth) {
                    // Previous month days
                    $day = $prevMonthDays - $daysFromPrevMonth + $i + 1;
                    $cellDate = "$prevYear-$prevMonth-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                    $cellMonth = $prevMonth;
                    $cellYear = $prevYear;
                    $isCurrentMonth = false;
                } elseif ($i < $daysFromPrevMonth + $daysInMonth) {
                    // Current month days
                    $day = $i - $daysFromPrevMonth + 1;
                    $cellDate = "$year-$month-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                } else {
                    // Next month days
                    $day = $i - $daysFromPrevMonth - $daysInMonth + 1;
                    $nextMonthDate = new DateTime("$nextYear-$nextMonth-01");
                    $nextMonthDate->modify('+' . ($day - 1) . ' days');
                    $cellDate = $nextMonthDate->format('Y-m-d');
                    $cellMonth = $nextMonth;
                    $cellYear = $nextYear;
                    $isCurrentMonth = false;
                }
                
                $isToday = ($cellDate == date('Y-m-d'));
                $hasBookings = false;
                $hasBlocked = false;
                $hasSpecial = false;
                $fullyBooked = false;
                $bookingCount = 0;
                
                // Check for bookings on this date
                foreach ($bookings as $booking) {
                    if ($booking['experience_date'] == $cellDate) {
                        $hasBookings = true;
                        $bookingCount++;
                    }
                }
                
                // Check availability
                $totalAvailable = 0;
                foreach ($tiers as $tier) {
                    if (isset($availability[$tier['tier_id']][$cellDate])) {
                        $avail = $availability[$tier['tier_id']][$cellDate];
                        if ($avail['is_blocked']) {
                            $hasBlocked = true;
                        }
                        if ($avail['price_override']) {
                            $hasSpecial = true;
                        }
                        $available = $avail['max_bookings'] - $avail['bookings_made'];
                        $totalAvailable += max(0, $available);
                        if ($available <= 0) {
                            $fullyBooked = true;
                        }
                    }
                }
                
                $cellClass = 'calendar-cell';
                if (!$isCurrentMonth) $cellClass .= ' other-month';
                if ($isToday) $cellClass .= ' today';
                if ($hasBookings) $cellClass .= ' has-bookings';
                if ($hasBlocked) $cellClass .= ' has-blocked';
                if ($hasSpecial) $cellClass .= ' has-special';
                if ($fullyBooked && !$hasBlocked) $cellClass .= ' fully-booked';
            ?>
            <div class="<?php echo $cellClass; ?>" onclick="openDayModal('<?php echo $cellDate; ?>')">
                <div class="day-number"><?php echo date('j', strtotime($cellDate)); ?></div>
                
                <?php if ($hasBookings): ?>
                <div class="booking-indicator">
                    <i class="bi bi-calendar-check"></i> <?php echo $bookingCount; ?> booking(s)
                </div>
                <?php endif; ?>
                
                <?php if ($hasBlocked): ?>
                <div class="blocked-indicator">
                    <i class="bi bi-lock"></i> Blocked
                </div>
                <?php endif; ?>
                
                <?php if ($hasSpecial && !$hasBlocked): ?>
                <div class="special-indicator">
                    <i class="bi bi-tag"></i> Special
                </div>
                <?php endif; ?>
                
                <?php if ($fullyBooked && !$hasBlocked && !$hasBookings): ?>
                <div class="fully-booked-indicator">
                    <i class="bi bi-x-circle"></i> Full
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- LIST VIEW -->
<div id="view-list" style="display: <?php echo $view == 'list' ? 'block' : 'none'; ?>;">
    <div class="upcoming-list">
        <table class="table" style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Tiers</th>
                    <th>Status</th>
                    <th>Bookings</th>
                    <th>Available</th>
                    <th>Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Generate list of days in month
                for ($day = 1; $day <= $daysInMonth; $day++):
                    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                    $dayBookings = array_filter($bookings, fn($b) => $b['experience_date'] == $date);
                    $bookingCount = count($dayBookings);
                    
                    // Get availability for this date across tiers
                    $dateAvailability = [];
                    $totalAvailable = 0;
                    $hasSpecial = false;
                    $hasBlocked = false;
                    $fullyBooked = true;
                    
                    foreach ($tiers as $tier) {
                        if (isset($availability[$tier['tier_id']][$date])) {
                            $avail = $availability[$tier['tier_id']][$date];
                            $dateAvailability[$tier['tier_id']] = $avail;
                            if ($avail['is_blocked']) {
                                $hasBlocked = true;
                            } else {
                                $available = $avail['max_bookings'] - $avail['bookings_made'];
                                $totalAvailable += max(0, $available);
                                if ($available > 0) {
                                    $fullyBooked = false;
                                }
                            }
                            if ($avail['price_override']) {
                                $hasSpecial = true;
                            }
                        } else {
                            // Default availability
                            $totalAvailable += 10; // Default max bookings
                            $fullyBooked = false;
                        }
                    }
                    
                    $statusText = 'Available';
                    $statusClass = 'success';
                    
                    if ($hasBlocked) {
                        $statusText = 'Blocked';
                        $statusClass = 'danger';
                    } elseif ($bookingCount > 0) {
                        $statusText = 'Has Bookings';
                        $statusClass = 'purple';
                    } elseif ($fullyBooked) {
                        $statusText = 'Fully Booked';
                        $statusClass = 'warning';
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo date('M d, Y', strtotime($date)); ?></strong>
                        <?php if ($date == date('Y-m-d')): ?>
                        <span class="badge" style="background: var(--exp-warning); color: white; margin-left: 8px;">Today</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo count($tiers); ?> tier(s)</td>
                    <td>
                        <span class="badge" style="background: var(--exp-<?php echo $statusClass; ?>); color: white;">
                            <?php echo $statusText; ?>
                        </span>
                    </td>
                    <td><?php echo $bookingCount; ?> booking(s)</td>
                    <td><?php echo $totalAvailable; ?> slots</td>
                    <td>
                        <?php if ($hasSpecial): ?>
                        <span style="color: var(--exp-warning);">Special Pricing</span>
                        <?php else: ?>
                        Standard
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn-outline btn-sm" onclick="openDayModal('<?php echo $date; ?>')">
                            <i class="bi bi-pencil"></i> Manage
                        </button>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- UPCOMING BOOKINGS VIEW -->
<div id="view-upcoming" style="display: <?php echo $view == 'upcoming' ? 'block' : 'none'; ?>;">
    <div class="upcoming-list">
        <?php if (empty($upcomingBookings)): ?>
        <div style="text-align: center; padding: 40px; color: var(--exp-text-light);">
            <i class="bi bi-calendar-check" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
            <p>No upcoming bookings in the next 30 days</p>
        </div>
        <?php else: ?>
            <?php foreach ($upcomingBookings as $booking): ?>
            <div class="upcoming-item">
                <div class="upcoming-date">
                    <div class="day"><?php echo date('d', strtotime($booking['experience_date'])); ?></div>
                    <div class="month"><?php echo date('M', strtotime($booking['experience_date'])); ?></div>
                </div>
                
                <div class="upcoming-info">
                    <div class="upcoming-title">
                        <?php echo sanitize($booking['attraction_name']); ?> - <?php echo sanitize($booking['tier_name']); ?>
                    </div>
                    <div class="upcoming-details">
                        <span><i class="bi bi-person"></i> <?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
                        <span><i class="bi bi-people"></i> <?php echo $booking['num_participants']; ?> guests</span>
                        <span><i class="bi bi-clock"></i> <?php echo date('h:i A', strtotime($booking['start_time'] ?? '09:00')); ?></span>
                        <span><i class="bi bi-telephone"></i> <?php echo sanitize($booking['phone'] ?? 'No phone'); ?></span>
                    </div>
                </div>
                
                <div>
                    <span class="upcoming-status status-<?php echo $booking['status']; ?>">
                        <?php echo ucfirst($booking['status']); ?>
                    </span>
                </div>
                
                <div style="margin-left: 16px;">
                    <a href="bookings.php?booking=<?php echo $booking['booking_id']; ?>" class="btn-outline btn-sm">
                        <i class="bi bi-eye"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div class="empty-state">
    <i class="bi bi-ticket-perforated"></i>
    <h3>Select an experience</h3>
    <p>Please select an experience from the dropdown above to manage its schedule</p>
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
                <input type="hidden" name="date" id="modal_date" value="">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--exp-gray); border-radius: var(--radius-sm);">
                    <div><strong id="modal_date_display"></strong></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Select Tier</label>
                    <select name="tier_id" id="modal_tier_id" class="form-control" required>
                        <option value="">Select a tier</option>
                        <?php foreach ($tiers as $tier): ?>
                        <option value="<?php echo $tier['tier_id']; ?>">
                            <?php echo sanitize($tier['tier_name']); ?> (<?php echo formatPrice($tier['base_price']); ?>/person)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <div class="radio-group">
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="available" checked>
                            Available
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="block">
                            Block / Unavailable
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="availability_action" value="price_override">
                            Special Price
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="maxBookingsField">
                    <label class="form-label">Max Bookings</label>
                    <input type="number" name="max_bookings" class="form-control" value="10" min="1">
                </div>
                
                <div class="form-group" id="priceOverrideField" style="display: none;">
                    <label class="form-label">Special Price (RWF)</label>
                    <input type="number" name="price_override" class="form-control" min="0" step="1000">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Add notes about this date..."></textarea>
                </div>
                
                <!-- Display existing bookings info if any -->
                <div id="existingBookingsInfo" style="display: none; margin-top: 16px; padding: 12px; background: #fce8e8; border-radius: var(--radius-sm); color: #ef4444;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="existingBookingsText"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('dayModal')">Cancel</button>
                <button type="submit" name="update_availability" class="btn-primary">Update</button>
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
                    <label class="form-label">Select Tier</label>
                    <select name="tier_id" class="form-control" required>
                        <option value="">Select a tier</option>
                        <?php foreach ($tiers as $tier): ?>
                        <option value="<?php echo $tier['tier_id']; ?>">
                            <?php echo sanitize($tier['tier_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date Range</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="date" name="start_date" class="form-control" required>
                        <span>to</span>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific days</label>
                    <div class="days-of-week">
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
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="sunday"> Sun
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="bulk_action" class="form-control" onchange="toggleBulkFields(this.value)">
                        <option value="available">Set Available</option>
                        <option value="block">Block / Unavailable</option>
                        <option value="set_price">Set Special Price</option>
                    </select>
                </div>
                
                <div class="form-group" id="bulkMaxField">
                    <label class="form-label">Max Bookings</label>
                    <input type="number" name="max_bookings" class="form-control" value="10" min="1">
                </div>
                
                <div class="form-group" id="bulkPriceField" style="display: none;">
                    <label class="form-label">Special Price (RWF)</label>
                    <input type="number" name="bulk_price" class="form-control" min="0" step="1000">
                </div>
                
                <div style="background: var(--exp-gray); padding: 12px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.75rem; margin-bottom: 4px; font-weight: 600;">Note:</p>
                    <p style="font-size: 0.6875rem; color: var(--exp-text-light);">
                        Dates with existing bookings will be skipped when blocking.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" name="bulk_update" class="btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy from Previous Month Modal -->
<div class="modal" id="copyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Copy from Previous Month</h3>
            <button class="modal-close" onclick="closeModal('copyModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Tier</label>
                    <select name="tier_id" class="form-control" required>
                        <option value="">Select a tier</option>
                        <?php foreach ($tiers as $tier): ?>
                        <option value="<?php echo $tier['tier_id']; ?>">
                            <?php echo sanitize($tier['tier_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Copy from</label>
                    <select name="source_month" class="form-control" required>
                        <?php
                        $prevMonthDate = new DateTime("$year-$month-01");
                        $prevMonthDate->modify('-1 month');
                        for ($i = 1; $i <= 3; $i++):
                            $sourceYear = $prevMonthDate->format('Y');
                            $sourceMonth = intval($prevMonthDate->format('m'));
                        ?>
                        <option value="<?php echo $sourceMonth; ?>" data-year="<?php echo $sourceYear; ?>">
                            <?php echo $monthNames[$sourceMonth] . ' ' . $sourceYear; ?>
                        </option>
                        <?php 
                            $prevMonthDate->modify('-1 month');
                        endfor; 
                        ?>
                    </select>
                    <input type="hidden" name="source_year" id="source_year" value="<?php echo $prevYear; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Copy to</label>
                    <select name="target_month" class="form-control" required>
                        <option value="<?php echo intval($month); ?>"><?php echo $monthNames[$currentMonthIndex] . ' ' . $year; ?> (Current)</option>
                        <?php
                        $nextMonthDate = new DateTime("$year-$month-01");
                        $nextMonthDate->modify('+1 month');
                        for ($i = 1; $i <= 2; $i++):
                            $targetYear = $nextMonthDate->format('Y');
                            $targetMonth = intval($nextMonthDate->format('m'));
                        ?>
                        <option value="<?php echo $targetMonth; ?>" data-year="<?php echo $targetYear; ?>">
                            <?php echo $monthNames[$targetMonth] . ' ' . $targetYear; ?>
                        </option>
                        <?php 
                            $nextMonthDate->modify('+1 month');
                        endfor; 
                        ?>
                    </select>
                    <input type="hidden" name="target_year" id="target_year" value="<?php echo $year; ?>">
                </div>
                
                <div style="background: var(--exp-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <i class="bi bi-info-circle"></i>
                    <small>Dates with existing bookings in the target month will be skipped.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('copyModal')">Cancel</button>
                <button type="submit" name="copy_from" class="btn-primary">Copy</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// VIEW SWITCHING
// ============================================
function switchView(view) {
    window.location.href = 'schedule.php?experience=<?php echo $experienceId; ?>&view=' + view + '&year=<?php echo $year; ?>&month=<?php echo $month; ?>';
}

// ============================================
// EXPERIENCE SELECTION
// ============================================
function changeExperience(expId) {
    if (expId) {
        window.location.href = 'schedule.php?experience=' + expId;
    } else {
        window.location.href = 'schedule.php';
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
// DAY MODAL
// ============================================
function openDayModal(date) {
    document.getElementById('modal_date').value = date;
    
    // Format date for display
    const dateObj = new Date(date + 'T12:00:00');
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('modal_date_display').textContent = dateObj.toLocaleDateString('en-US', options);
    
    // Reset form
    document.querySelector('input[name="availability_action"][value="available"]').checked = true;
    document.getElementById('maxBookingsField').style.display = 'block';
    document.getElementById('priceOverrideField').style.display = 'none';
    document.getElementById('existingBookingsInfo').style.display = 'none';
    document.querySelector('textarea[name="notes"]').value = '';
    
    // Check for existing bookings (this would ideally be done via AJAX)
    // For now, we'll just show the modal
    
    openModal('dayModal');
}

// Show/hide fields based on selection
document.querySelectorAll('input[name="availability_action"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const maxField = document.getElementById('maxBookingsField');
        const priceField = document.getElementById('priceOverrideField');
        
        if (this.value === 'price_override') {
            maxField.style.display = 'block';
            priceField.style.display = 'block';
        } else if (this.value === 'block') {
            maxField.style.display = 'none';
            priceField.style.display = 'none';
        } else {
            maxField.style.display = 'block';
            priceField.style.display = 'none';
        }
    });
});

// ============================================
// BULK MODAL
// ============================================
function openBulkModal() {
    openModal('bulkModal');
}

function toggleBulkFields(action) {
    const maxField = document.getElementById('bulkMaxField');
    const priceField = document.getElementById('bulkPriceField');
    
    if (action === 'set_price') {
        maxField.style.display = 'block';
        priceField.style.display = 'block';
    } else if (action === 'block') {
        maxField.style.display = 'none';
        priceField.style.display = 'none';
    } else {
        maxField.style.display = 'block';
        priceField.style.display = 'none';
    }
}

// ============================================
// COPY MODAL
// ============================================
function openCopyModal() {
    openModal('copyModal');
}

// Update hidden year fields when month selection changes
document.querySelector('select[name="source_month"]')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const year = selected.getAttribute('data-year');
    if (year) {
        document.getElementById('source_year').value = year;
    }
});

document.querySelector('select[name="target_month"]')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const year = selected.getAttribute('data-year');
    if (year) {
        document.getElementById('target_year').value = year;
    }
});

// ============================================
// TIER SELECTION
// ============================================
function selectTier(tierId) {
    document.querySelectorAll('.tier-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Here you would filter the calendar based on tier
    // For now, just show a message
    if (tierId === 'all') {
        showNotification('Showing all tiers', 'info');
    } else {
        showNotification('Filtered by selected tier', 'info');
    }
}

// ============================================
// REFRESH
// ============================================
function refreshCalendar() {
    window.location.reload();
}

// ============================================
// NOTIFICATION
// ============================================
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = 'alert-' + type;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 12px 20px;
        background: ${type === 'success' ? '#e6f4ea' : type === 'danger' ? '#fce8e8' : '#fff4e6'};
        color: ${type === 'success' ? '#10b981' : type === 'danger' ? '#ef4444' : '#f59e0b'};
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow-lg);
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideIn 0.3s ease;
    `;
    notification.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'}-fill"></i>
        <span style="flex: 1;">${message}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;"><i class="bi bi-x"></i></button>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 4000);
}
</script>

<?php require_once 'includes/experiences_footer.php'; ?>