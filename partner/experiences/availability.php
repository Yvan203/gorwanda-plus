<?php
$pageTitle = 'Availability Management';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameters
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;
$tierId = isset($_GET['tier']) ? intval($_GET['tier']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$view = isset($_GET['view']) ? $_GET['view'] : 'monthly';

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
// HANDLE AVAILABILITY ACTIONS
// ============================================

// Quick update availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_update'])) {
    $tierId = intval($_POST['tier_id']);
    $date = $_POST['date'];
    $maxBookings = intval($_POST['max_bookings']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    if ($stmt->fetch()) {
        // Check for existing bookings
        $stmt = $db->prepare("
            SELECT SUM(num_participants) as total_booked
            FROM bookings b
            WHERE b.attraction_tier_id = ? 
            AND b.experience_date = ?
            AND b.status IN ('confirmed', 'pending')
        ");
        $stmt->execute([$tierId, $date]);
        $booked = $stmt->fetchColumn() ?: 0;
        
        if ($maxBookings < $booked) {
            $error = "Cannot set max bookings to $maxBookings because there are already $booked bookings for this date.";
        } else {
            $stmt = $db->prepare("
                INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL
            ");
            $stmt->execute([$tierId, $date, $maxBookings, $booked, $maxBookings]);
            $success = "Availability updated successfully";
        }
    }
}

// Block/Unblock date
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    $tierId = intval($_POST['tier_id']);
    $date = $_POST['date'];
    $block = intval($_POST['block']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    if ($stmt->fetch()) {
        if ($block == 1) {
            // Check for existing bookings before blocking
            $stmt = $db->prepare("
                SELECT COUNT(*) as booking_count
                FROM bookings b
                WHERE b.attraction_tier_id = ? 
                AND b.experience_date = ?
                AND b.status IN ('confirmed', 'pending')
            ");
            $stmt->execute([$tierId, $date]);
            $bookingCount = $stmt->fetchColumn();
            
            if ($bookingCount > 0) {
                $error = "Cannot block this date - there are $bookingCount existing booking(s).";
            } else {
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                    VALUES (?, ?, 0, 0, 1)
                    ON DUPLICATE KEY UPDATE is_blocked = 1, max_bookings = 0, price_override = NULL
                ");
                $stmt->execute([$tierId, $date]);
                $success = "Date blocked successfully";
            }
        } else {
            // Unblock date
            $stmt = $db->prepare("
                DELETE FROM attraction_availability 
                WHERE tier_id = ? AND date = ? AND is_blocked = 1
            ");
            $stmt->execute([$tierId, $date]);
            $success = "Date unblocked successfully";
        }
    }
}

// Set special price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_special_price'])) {
    $tierId = intval($_POST['tier_id']);
    $date = $_POST['date'];
    $price = floatval($_POST['price']);
    $maxBookings = intval($_POST['max_bookings'] ?? 10);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id, at.base_price FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    $tier = $stmt->fetch();
    
    if ($tier) {
        // Check for existing bookings
        $stmt = $db->prepare("
            SELECT SUM(num_participants) as total_booked
            FROM bookings b
            WHERE b.attraction_tier_id = ? 
            AND b.experience_date = ?
            AND b.status IN ('confirmed', 'pending')
        ");
        $stmt->execute([$tierId, $date]);
        $booked = $stmt->fetchColumn() ?: 0;
        
        // Check if price is lower than regular and warn
        if ($price < $tier['base_price'] && $booked > 0) {
            $warning = "Warning: Setting a lower price than regular for a date with $booked existing booking(s).";
        }
        
        $stmt = $db->prepare("
            INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, price_override, is_blocked)
            VALUES (?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE max_bookings = ?, price_override = ?, is_blocked = 0
        ");
        $stmt->execute([$tierId, $date, $maxBookings, $booked, $price, $maxBookings, $price]);
        $success = "Special price set successfully";
    }
}

// Bulk update for month
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_month'])) {
    $tierId = intval($_POST['tier_id']);
    $targetMonth = intval($_POST['target_month']);
    $targetYear = intval($_POST['target_year']);
    $defaultMax = intval($_POST['default_max'] ?? 10);
    $applyWeekends = isset($_POST['apply_weekends']);
    $weekendMultiplier = floatval($_POST['weekend_multiplier'] ?? 1.0);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT at.tier_id, at.base_price FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    $tier = $stmt->fetch();
    
    if ($tier) {
        $firstDay = "$targetYear-$targetMonth-01";
        $lastDay = date('Y-m-t', strtotime($firstDay));
        
        $current = new DateTime($firstDay);
        $end = new DateTime($lastDay);
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($current, $interval, $end->modify('+1 day'));
        
        $updated = 0;
        $skipped = 0;
        
        foreach ($dateRange as $dateObj) {
            $date = $dateObj->format('Y-m-d');
            $dayOfWeek = $dateObj->format('w'); // 0 = Sunday, 6 = Saturday
            
            // Check if weekend and apply multiplier
            $maxBookings = $defaultMax;
            if ($applyWeekends && ($dayOfWeek == 0 || $dayOfWeek == 6)) {
                $maxBookings = round($defaultMax * $weekendMultiplier);
            }
            
            // Check for existing bookings
            $stmt = $db->prepare("
                SELECT SUM(num_participants) as total_booked
                FROM bookings b
                WHERE b.attraction_tier_id = ? 
                AND b.experience_date = ?
                AND b.status IN ('confirmed', 'pending')
            ");
            $stmt->execute([$tierId, $date]);
            $booked = $stmt->fetchColumn() ?: 0;
            
            if ($maxBookings < $booked) {
                $skipped++;
                continue;
            }
            
            // Update or insert availability
            $stmt = $db->prepare("
                INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL
            ");
            $stmt->execute([$tierId, $date, $maxBookings, $booked, $maxBookings]);
            $updated++;
        }
        
        $message = "Updated $updated days for " . date('F Y', strtotime($firstDay));
        if ($skipped > 0) {
            $message .= ". Skipped $skipped days due to existing bookings exceeding new limits.";
        }
        $success = $message;
    }
}

// Copy from previous month
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['copy_availability'])) {
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
                    SELECT SUM(num_participants) as total_booked
                    FROM bookings b
                    WHERE b.attraction_tier_id = ? 
                    AND b.experience_date = ?
                    AND b.status IN ('confirmed', 'pending')
                ");
                $stmt->execute([$tierId, $targetDateStr]);
                $booked = $stmt->fetchColumn() ?: 0;
                
                if ($data['is_blocked']) {
                    if ($booked > 0) {
                        $skipped++;
                        continue;
                    }
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked, notes)
                        VALUES (?, ?, 0, 0, 1, ?)
                        ON DUPLICATE KEY UPDATE max_bookings = 0, is_blocked = 1, price_override = NULL, notes = ?
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['notes'], $data['notes']]);
                } elseif ($data['price_override']) {
                    if ($data['max_bookings'] < $booked) {
                        $skipped++;
                        continue;
                    }
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, price_override, notes)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, price_override = ?, is_blocked = 0, notes = ?
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['max_bookings'], $booked, $data['price_override'], $data['notes'], $data['max_bookings'], $data['price_override'], $data['notes']]);
                } else {
                    if ($data['max_bookings'] < $booked) {
                        $skipped++;
                        continue;
                    }
                    $stmt = $db->prepare("
                        INSERT INTO attraction_availability (tier_id, date, max_bookings, bookings_made, is_blocked)
                        VALUES (?, ?, ?, ?, 0)
                        ON DUPLICATE KEY UPDATE max_bookings = ?, is_blocked = 0, price_override = NULL, notes = NULL
                    ");
                    $stmt->execute([$tierId, $targetDateStr, $data['max_bookings'], $booked, $data['max_bookings']]);
                }
                $copied++;
            }
        }
        
        $message = "Copied $copied days from " . date('F Y', strtotime("$sourceYear-$sourceMonth-01"));
        if ($skipped > 0) {
            $message .= ". Skipped $skipped days due to existing bookings.";
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
        WHERE attraction_id = ? 
        ORDER BY base_price ASC
    ");
    $stmt->execute([$experienceId]);
    $tiers = $stmt->fetchAll();
}

// If no tier selected and tiers exist, use the first one
if ($tierId === 0 && !empty($tiers)) {
    $tierId = $tiers[0]['tier_id'];
}

// Get current tier details
$currentTier = null;
if ($tierId > 0) {
    foreach ($tiers as $tier) {
        if ($tier['tier_id'] == $tierId) {
            $currentTier = $tier;
            break;
        }
    }
}

// Get availability data for the selected month and tier
$availability = [];
$bookings = [];
$stats = [
    'total_days' => 0,
    'available_days' => 0,
    'blocked_days' => 0,
    'special_price_days' => 0,
    'fully_booked_days' => 0,
    'total_capacity' => 0,
    'booked_slots' => 0,
    'utilization_rate' => 0
];

if ($tierId > 0) {
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));
    $daysInMonth = date('t', strtotime($firstDay));
    $stats['total_days'] = $daysInMonth;
    
    // Get availability records
    $stmt = $db->prepare("
        SELECT * FROM attraction_availability 
        WHERE tier_id = ? AND date BETWEEN ? AND ?
    ");
    $stmt->execute([$tierId, $firstDay, $lastDay]);
    $availRecords = $stmt->fetchAll();
    
    foreach ($availRecords as $record) {
        $availability[$record['date']] = $record;
        if ($record['is_blocked']) {
            $stats['blocked_days']++;
        } else {
            $stats['available_days']++;
            if ($record['price_override']) {
                $stats['special_price_days']++;
            }
            $available = $record['max_bookings'] - $record['bookings_made'];
            $stats['total_capacity'] += $record['max_bookings'];
            $stats['booked_slots'] += $record['bookings_made'];
            if ($available <= 0) {
                $stats['fully_booked_days']++;
            }
        }
    }
    
    // Get bookings for this month
    $stmt = $db->prepare("
        SELECT b.experience_date, b.num_participants, b.status, b.booking_reference,
               u.first_name, u.last_name
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.attraction_tier_id = ? 
        AND b.experience_date BETWEEN ? AND ?
        AND b.status IN ('confirmed', 'pending')
        ORDER BY b.experience_date ASC
    ");
    $stmt->execute([$tierId, $firstDay, $lastDay]);
    $bookingsData = $stmt->fetchAll();
    
    foreach ($bookingsData as $booking) {
        $bookings[$booking['experience_date']][] = $booking;
    }
    
    // Calculate utilization rate
    if ($stats['total_capacity'] > 0) {
        $stats['utilization_rate'] = round(($stats['booked_slots'] / $stats['total_capacity']) * 100, 1);
    }
}

// Get first day of month and number of days
$firstDayOfMonth = new DateTime("$year-$month-01");
$daysInMonth = $firstDayOfMonth->format('t');
$firstDayWeekday = $firstDayOfMonth->format('w'); // 0 (Sunday) to 6 (Saturday)

// Week day names
$weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<style>
/* Availability Management Specific Styles */
.availability-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.availability-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.availability-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 0;
}

/* Experience/Tier Selector */
.selector-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    align-items: center;
}

.selector-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.selector-group label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--exp-text);
    white-space: nowrap;
}

.selector-group select {
    min-width: 250px;
    padding: 8px 12px;
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
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
}

/* Quick Actions Bar */
.quick-actions {
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

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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
    min-height: 120px;
    padding: 8px;
    border-right: 1px solid var(--exp-border);
    border-bottom: 1px solid var(--exp-border);
    background: white;
    position: relative;
}

.calendar-cell:nth-child(7n) {
    border-right: none;
}

.calendar-cell.other-month {
    background: var(--exp-gray);
    color: var(--exp-text-light);
}

.calendar-cell.today {
    background: #fff4e6;
    border: 2px solid var(--exp-warning);
}

.day-number {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.day-status {
    font-size: 0.625rem;
    padding: 2px 6px;
    border-radius: 100px;
}

.status-available {
    background: #e6f4ea;
    color: #10b981;
}

.status-blocked {
    background: #fce8e8;
    color: #ef4444;
}

.status-special {
    background: #fff3cd;
    color: #f59e0b;
}

.availability-info {
    font-size: 0.6875rem;
    margin-bottom: 8px;
}

.capacity-bar {
    height: 4px;
    background: var(--exp-gray);
    border-radius: 2px;
    overflow: hidden;
    margin: 6px 0;
}

.capacity-fill {
    height: 100%;
    background: var(--exp-purple);
    transition: width 0.2s;
}

.booking-item {
    font-size: 0.625rem;
    padding: 2px 4px;
    background: var(--exp-light-purple);
    border-radius: 2px;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
}

.booking-item:hover {
    background: var(--exp-purple);
    color: white;
}

.quick-edit {
    position: absolute;
    top: 4px;
    right: 4px;
    opacity: 0;
    transition: opacity 0.2s;
}

.calendar-cell:hover .quick-edit {
    opacity: 1;
}

.quick-edit-btn {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: white;
    border: 1px solid var(--exp-border);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.75rem;
}

.quick-edit-btn:hover {
    background: var(--exp-purple);
    color: white;
    border-color: var(--exp-purple);
}

/* Legend */
.legend {
    display: flex;
    gap: 24px;
    margin-bottom: 24px;
    padding: 16px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
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

.legend-color.available { background: #e6f4ea; border: 1px solid #10b981; }
.legend-color.blocked { background: #fce8e8; border: 1px solid #ef4444; }
.legend-color.special { background: #fff3cd; border: 1px solid #f59e0b; }
.legend-color.booked { background: var(--exp-light-purple); border: 1px solid var(--exp-purple); }

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

.form-row {
    display: flex;
    gap: 10px;
    align-items: center;
}

.form-row .form-group {
    flex: 1;
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
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .selector-container {
        flex-direction: column;
        align-items: stretch;
    }
    
    .selector-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .selector-group select {
        min-width: 100%;
    }
    
    .calendar-cell {
        min-height: 100px;
    }
    
    .legend {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<div class="availability-header">
    <div class="availability-title">
        <h1>Availability Management</h1>
        <p>Manage daily capacity, blocks, and special pricing</p>
    </div>
</div>

<!-- Experience/Tier Selector -->
<div class="selector-container">
    <div class="selector-group">
        <label for="experienceSelect">Experience:</label>
        <select id="experienceSelect" onchange="changeExperience(this.value)">
            <option value="0">Select Experience</option>
            <?php foreach ($experiences as $exp): ?>
            <option value="<?php echo $exp['attraction_id']; ?>" <?php echo $exp['attraction_id'] == $experienceId ? 'selected' : ''; ?>>
                <?php echo sanitize($exp['attraction_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="selector-group">
        <label for="tierSelect">Tier:</label>
        <select id="tierSelect" name="tier" onchange="changeTier(this.value)">
            <option value="0">Select Tier</option>
            <?php foreach ($tiers as $tier): ?>
            <option value="<?php echo $tier['tier_id']; ?>" <?php echo $tier['tier_id'] == $tierId ? 'selected' : ''; ?>>
                <?php echo sanitize($tier['tier_name']); ?> (<?php echo formatPrice($tier['base_price']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="selector-group">
        <label for="monthSelect">Month:</label>
        <select id="monthSelect" onchange="changeMonth(this.value)">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                <?php echo $monthNames[$m]; ?>
            </option>
            <?php endfor; ?>
        </select>
    </div>
    
    <div class="selector-group">
        <label for="yearSelect">Year:</label>
        <select id="yearSelect" onchange="changeYear(this.value)">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>>
                <?php echo $y; ?>
            </option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<?php if ($tierId > 0 && $currentTier): ?>

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
        <div class="stat-value"><?php echo $stats['available_days']; ?>/<?php echo $stats['total_days']; ?></div>
        <div class="stat-label">Available Days</div>
        <div class="stat-footer"><?php echo $stats['blocked_days']; ?> blocked</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['special_price_days']; ?></div>
        <div class="stat-label">Special Price Days</div>
        <div class="stat-footer">With promotions</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['fully_booked_days']; ?></div>
        <div class="stat-label">Fully Booked</div>
        <div class="stat-footer">At capacity</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['utilization_rate']; ?>%</div>
        <div class="stat-label">Utilization Rate</div>
        <div class="stat-footer"><?php echo $stats['booked_slots']; ?>/<?php echo $stats['total_capacity']; ?> slots</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <span style="font-weight: 600; font-size: 0.875rem;">Quick Actions:</span>
    <div class="action-buttons">
        <button class="btn-secondary btn-sm" onclick="openBulkModal()">
            <i class="bi bi-calendar-range"></i> Bulk Update Month
        </button>
        <button class="btn-secondary btn-sm" onclick="openCopyModal()">
            <i class="bi bi-files"></i> Copy from Previous
        </button>
        <button class="btn-secondary btn-sm" onclick="exportAvailability()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Legend -->
<div class="legend">
    <div class="legend-item">
        <div class="legend-color available"></div>
        <span>Available - Normal pricing</span>
    </div>
    <div class="legend-item">
        <div class="legend-color blocked"></div>
        <span>Blocked - Unavailable</span>
    </div>
    <div class="legend-item">
        <div class="legend-color special"></div>
        <span>Special Price - Promotion</span>
    </div>
    <div class="legend-item">
        <div class="legend-color booked"></div>
        <span>Has Bookings</span>
    </div>
</div>

<!-- Calendar Navigation -->
<div class="calendar-nav">
    <div class="nav-buttons">
        <a href="?experience=<?php echo $experienceId; ?>&tier=<?php echo $tierId; ?>&month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="nav-btn">
            <i class="bi bi-chevron-left"></i> <?php echo $monthNames[$prevMonth]; ?>
        </a>
        <a href="?experience=<?php echo $experienceId; ?>&tier=<?php echo $tierId; ?>&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="nav-btn">
            <i class="bi bi-calendar3"></i> Today
        </a>
        <a href="?experience=<?php echo $experienceId; ?>&tier=<?php echo $tierId; ?>&month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="nav-btn">
            <?php echo $monthNames[$nextMonth]; ?> <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    
    <div class="current-month">
        <?php echo $monthNames[$month] . ' ' . $year; ?> - <?php echo sanitize($currentTier['tier_name']); ?>
    </div>
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
            $isCurrentMonth = true;
            
            if ($i < $daysFromPrevMonth) {
                // Previous month days
                $day = $prevMonthDays - $daysFromPrevMonth + $i + 1;
                $cellDate = "$prevYear-$prevMonth-" . str_pad($day, 2, '0', STR_PAD_LEFT);
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
                $isCurrentMonth = false;
            }
            
            $isToday = ($cellDate == date('Y-m-d'));
            $dayBookings = $bookings[$cellDate] ?? [];
            $bookingCount = count($dayBookings);
            $avail = $availability[$cellDate] ?? null;
            
            $status = 'available';
            $statusClass = 'status-available';
            $capacity = 10;
            $booked = 0;
            $available = 10;
            $price = $currentTier['base_price'];
            
            if ($avail) {
                if ($avail['is_blocked']) {
                    $status = 'blocked';
                    $statusClass = 'status-blocked';
                    $capacity = 0;
                    $available = 0;
                } else {
                    if ($avail['price_override']) {
                        $status = 'special';
                        $statusClass = 'status-special';
                        $price = $avail['price_override'];
                    }
                    $capacity = $avail['max_bookings'];
                    $booked = $avail['bookings_made'];
                    $available = $capacity - $booked;
                }
            }
            
            $capacityPercent = $capacity > 0 ? ($booked / $capacity) * 100 : 0;
        ?>
        <div class="calendar-cell <?php echo !$isCurrentMonth ? 'other-month' : ''; ?> <?php echo $isToday ? 'today' : ''; ?>">
            <div class="day-number">
                <span><?php echo date('j', strtotime($cellDate)); ?></span>
                <span class="day-status <?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></span>
            </div>
            
            <div class="availability-info">
                <div><strong><?php echo formatPrice($price); ?></strong></div>
                <div><?php echo $available; ?>/<?php echo $capacity; ?> slots</div>
            </div>
            
            <?php if ($capacity > 0): ?>
            <div class="capacity-bar">
                <div class="capacity-fill" style="width: <?php echo $capacityPercent; ?>%;"></div>
            </div>
            <?php endif; ?>
            
            <?php foreach (array_slice($dayBookings, 0, 2) as $booking): ?>
            <div class="booking-item" title="<?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?> - <?php echo $booking['num_participants']; ?> pax">
                <i class="bi bi-person"></i> <?php echo substr($booking['first_name'] ?? 'G', 0, 1); ?>. <?php echo $booking['num_participants']; ?>p
            </div>
            <?php endforeach; ?>
            
            <?php if ($bookingCount > 2): ?>
            <div style="font-size: 0.5625rem; color: var(--exp-text-light); margin-top: 2px;">
                +<?php echo $bookingCount - 2; ?> more
            </div>
            <?php endif; ?>
            
            <?php if ($isCurrentMonth): ?>
            <div class="quick-edit">
                <button class="quick-edit-btn" onclick="openQuickEdit('<?php echo $cellDate; ?>', <?php echo $capacity; ?>, '<?php echo $status; ?>', <?php echo $price; ?>)" title="Quick edit">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
</div>

<?php else: ?>
<div class="empty-state" style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--exp-border);">
    <i class="bi bi-calendar2-week" style="font-size: 3rem; color: var(--exp-text-light); margin-bottom: 16px;"></i>
    <h3 style="font-size: 1.125rem; margin-bottom: 8px;">Select a tier</h3>
    <p style="color: var(--exp-text-light);">Please select an experience and tier to manage availability</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Quick Edit Modal -->
<div class="modal" id="quickEditModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Quick Edit Availability</h3>
            <button class="modal-close" onclick="closeModal('quickEditModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="tier_id" value="<?php echo $tierId; ?>">
                <input type="hidden" name="date" id="quick_date" value="">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--exp-gray); border-radius: var(--radius-sm);">
                    <div><strong id="quick_date_display"></strong></div>
                    <div id="quick_tier_info"><?php echo sanitize($currentTier['tier_name'] ?? ''); ?></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="action" id="quick_action" class="form-control" onchange="toggleQuickFields()">
                        <option value="available">Available</option>
                        <option value="block">Block / Unavailable</option>
                        <option value="special">Special Price</option>
                    </select>
                </div>
                
                <div class="form-group" id="quick_capacity_field">
                    <label class="form-label">Max Bookings</label>
                    <input type="number" name="max_bookings" id="quick_capacity" class="form-control" min="1" value="10">
                </div>
                
                <div class="form-group" id="quick_price_field" style="display: none;">
                    <label class="form-label">Special Price (RWF)</label>
                    <input type="number" name="price" id="quick_price" class="form-control" min="0" step="1000">
                </div>
                
                <div id="quick_warning" style="display: none; padding: 12px; background: #fff4e6; border-radius: var(--radius-sm); color: #f59e0b; margin-top: 16px;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="quick_warning_text"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('quickEditModal')">Cancel</button>
                <button type="submit" name="quick_update" class="btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal" id="bulkModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bulk Update Month</h3>
            <button class="modal-close" onclick="closeModal('bulkModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="tier_id" value="<?php echo $tierId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Target Month</label>
                    <select name="target_month" class="form-control" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $month ? 'selected' : ''; ?>>
                            <?php echo $monthNames[$m]; ?> <?php echo $year; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                    <input type="hidden" name="target_year" value="<?php echo $year; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Default Max Bookings</label>
                    <input type="number" name="default_max" class="form-control" value="10" min="1" required>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="apply_weekends" value="1" onchange="toggleWeekendMultiplier()">
                        <span>Apply different capacity for weekends</span>
                    </label>
                </div>
                
                <div class="form-group" id="weekend_multiplier_field" style="display: none;">
                    <label class="form-label">Weekend Capacity Multiplier</label>
                    <input type="number" name="weekend_multiplier" class="form-control" value="1.5" min="0.5" max="3" step="0.1">
                    <small class="form-text">1.0 = same as weekdays, 1.5 = 50% more capacity</small>
                </div>
                
                <div style="background: var(--exp-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <i class="bi bi-info-circle"></i>
                    <small>This will update all days in the selected month. Days with existing bookings that exceed the new limits will be skipped.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" name="bulk_update_month" class="btn-primary">Apply to Month</button>
            </div>
        </form>
    </div>
</div>

<!-- Copy Modal -->
<div class="modal" id="copyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Copy Availability from Previous Month</h3>
            <button class="modal-close" onclick="closeModal('copyModal')"><i class="bi bi-x-lg"></i></button>
        </div>
<div class="form-group">
    <label class="form-label">Copy from</label>
    <select name="source_month" class="form-control" required>
        <?php
        $prevMonthDate = new DateTime("$year-$month-01");
        $prevMonthDate->modify('-1 month');
        for ($i = 1; $i <= 3; $i++):
            $sourceYear = $prevMonthDate->format('Y');
            $sourceMonth = $prevMonthDate->format('m'); // This returns "03" format
            $sourceMonthIndex = intval($sourceMonth); // Convert to integer (3)
        ?>
        <option value="<?php echo $sourceMonth; ?>" data-year="<?php echo $sourceYear; ?>">
            <?php echo $monthNames[$sourceMonthIndex] . ' ' . $sourceYear; ?>
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
        <option value="<?php echo intval($month); ?>"><?php echo $monthNames[intval($month)] . ' ' . $year; ?> (Current)</option>
        <?php
        $nextMonthDate = new DateTime("$year-$month-01");
        $nextMonthDate->modify('+1 month');
        for ($i = 1; $i <= 2; $i++):
            $targetYear = $nextMonthDate->format('Y');
            $targetMonth = $nextMonthDate->format('m'); // This returns "04" format
            $targetMonthIndex = intval($targetMonth); // Convert to integer (4)
        ?>
        <option value="<?php echo $targetMonth; ?>" data-year="<?php echo $targetYear; ?>">
            <?php echo $monthNames[$targetMonthIndex] . ' ' . $targetYear; ?>
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
                    <small>Copies all availability settings (blocks, special prices) from source month to target month. Days with existing bookings will be skipped.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('copyModal')">Cancel</button>
                <button type="submit" name="copy_availability" class="btn-primary">Copy Availability</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// NAVIGATION FUNCTIONS
// ============================================
function changeExperience(expId) {
    if (expId) {
        window.location.href = 'availability.php?experience=' + expId;
    } else {
        window.location.href = 'availability.php';
    }
}

function changeTier(tierId) {
    if (tierId) {
        window.location.href = 'availability.php?experience=<?php echo $experienceId; ?>&tier=' + tierId + '&month=<?php echo $month; ?>&year=<?php echo $year; ?>';
    }
}

function changeMonth(month) {
    window.location.href = 'availability.php?experience=<?php echo $experienceId; ?>&tier=<?php echo $tierId; ?>&month=' + month + '&year=<?php echo $year; ?>';
}

function changeYear(year) {
    window.location.href = 'availability.php?experience=<?php echo $experienceId; ?>&tier=<?php echo $tierId; ?>&month=<?php echo $month; ?>&year=' + year;
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
// QUICK EDIT
// ============================================
function openQuickEdit(date, currentCapacity, currentStatus, currentPrice) {
    document.getElementById('quick_date').value = date;
    
    // Format date
    const dateObj = new Date(date + 'T12:00:00');
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('quick_date_display').textContent = dateObj.toLocaleDateString('en-US', options);
    
    // Set current values
    document.getElementById('quick_capacity').value = currentCapacity || 10;
    document.getElementById('quick_price').value = currentPrice;
    
    // Set status dropdown
    const actionSelect = document.getElementById('quick_action');
    if (currentStatus === 'blocked') {
        actionSelect.value = 'block';
    } else if (currentStatus === 'special') {
        actionSelect.value = 'special';
    } else {
        actionSelect.value = 'available';
    }
    
    toggleQuickFields();
    openModal('quickEditModal');
}

function toggleQuickFields() {
    const action = document.getElementById('quick_action').value;
    const capacityField = document.getElementById('quick_capacity_field');
    const priceField = document.getElementById('quick_price_field');
    
    if (action === 'block') {
        capacityField.style.display = 'none';
        priceField.style.display = 'none';
    } else if (action === 'special') {
        capacityField.style.display = 'block';
        priceField.style.display = 'block';
    } else {
        capacityField.style.display = 'block';
        priceField.style.display = 'none';
    }
}

// ============================================
// BULK UPDATE
// ============================================
function openBulkModal() {
    openModal('bulkModal');
}

function toggleWeekendMultiplier() {
    const checkbox = document.querySelector('input[name="apply_weekends"]');
    const multiplierField = document.getElementById('weekend_multiplier_field');
    multiplierField.style.display = checkbox.checked ? 'block' : 'none';
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
// EXPORT
// ============================================
function exportAvailability() {
    // Create CSV content
    let csv = "Date,Day,Status,Capacity,Booked,Available,Price,Bookings\n";
    
    <?php
    for ($day = 1; $day <= $daysInMonth; $day++):
        $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
        $dayBookings = $bookings[$date] ?? [];
        $bookingCount = count($dayBookings);
        $avail = $availability[$date] ?? null;
        
        $status = 'available';
        $capacity = 10;
        $booked = 0;
        $available = 10;
        $price = $currentTier['base_price'] ?? 0;
        
        if ($avail) {
            if ($avail['is_blocked']) {
                $status = 'blocked';
                $capacity = 0;
                $available = 0;
            } else {
                if ($avail['price_override']) {
                    $status = 'special';
                    $price = $avail['price_override'];
                }
                $capacity = $avail['max_bookings'];
                $booked = $avail['bookings_made'];
                $available = $capacity - $booked;
            }
        }
        
        $dayOfWeek = date('l', strtotime($date));
    ?>
    csv += "<?php echo $date; ?>,<?php echo $dayOfWeek; ?>,<?php echo $status; ?>,<?php echo $capacity; ?>,<?php echo $booked; ?>,<?php echo $available; ?>,<?php echo $price; ?>,<?php echo $bookingCount; ?>\n";
    <?php endfor; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'availability_<?php echo $year; ?>_<?php echo $month; ?>.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/experiences_footer.php'; ?>