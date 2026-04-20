<?php
$pageTitle = 'Availability Calendar';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
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

// Update day status (set as available, blocked, or maintenance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_day_status'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $date = $_POST['date'];
    $status = sanitize($_POST['status']);
    $notes = sanitize($_POST['notes'] ?? '');
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.car_id FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    
    if ($stmt->fetch()) {
        // Check if there are any bookings on this date
        $stmt = $db->prepare("
            SELECT COUNT(*) as booking_count FROM bookings
            WHERE car_id = ? 
            AND ? BETWEEN pickup_date AND DATE_SUB(return_date, INTERVAL 1 DAY)
            AND status IN ('confirmed', 'checked_out', 'pending')
        ");
        $stmt->execute([$vehicleId, $date]);
        $bookingCheck = $stmt->fetch();
        
        if ($bookingCheck['booking_count'] > 0 && $status !== 'available') {
            $error = "Cannot change status - vehicle has active bookings on this date";
        } else {
            if ($status === 'available') {
                // Remove any existing maintenance or availability overrides
                $stmt = $db->prepare("
                    DELETE FROM car_availability WHERE car_id = ? AND date = ?
                ");
                $stmt->execute([$vehicleId, $date]);
                
                $stmt = $db->prepare("
                    DELETE FROM car_maintenance 
                    WHERE car_id = ? AND scheduled_date <= ? 
                    AND DATE_ADD(scheduled_date, INTERVAL estimated_duration - 1 DAY) >= ?
                    AND status IN ('scheduled', 'in_progress')
                ");
                $stmt->execute([$vehicleId, $date, $date]);
                
                $success = "Date marked as available";
                
            } elseif ($status === 'blocked') {
                // Block the date (set availability to 0)
                $stmt = $db->prepare("
                    INSERT INTO car_availability (car_id, date, quantity_available, is_blocked, notes)
                    VALUES (?, ?, 0, 1, ?)
                    ON DUPLICATE KEY UPDATE quantity_available = 0, is_blocked = 1, notes = ?
                ");
                $stmt->execute([$vehicleId, $date, $notes, $notes]);
                
                // Remove any maintenance on this date
                $stmt = $db->prepare("
                    DELETE FROM car_maintenance 
                    WHERE car_id = ? AND scheduled_date <= ? 
                    AND DATE_ADD(scheduled_date, INTERVAL estimated_duration - 1 DAY) >= ?
                ");
                $stmt->execute([$vehicleId, $date, $date]);
                
                $success = "Date blocked successfully";
                
            } elseif ($status === 'maintenance') {
                // Add maintenance for this date
                $duration = intval($_POST['duration'] ?? 1);
                $reason = sanitize($_POST['reason'] ?? 'Maintenance');
                
                $stmt = $db->prepare("
                    INSERT INTO car_maintenance (
                        car_id, maintenance_type, description, scheduled_date,
                        estimated_duration, notes, priority, status, created_at
                    ) VALUES (?, 'maintenance', ?, ?, ?, ?, 'medium', 'scheduled', NOW())
                ");
                $stmt->execute([$vehicleId, $reason, $date, $duration, $notes]);
                
                // Remove any availability override
                $stmt = $db->prepare("
                    DELETE FROM car_availability WHERE car_id = ? AND date = ?
                ");
                $stmt->execute([$vehicleId, $date]);
                
                $success = "Maintenance scheduled for $duration day(s)";
            }
        }
    }
}

// Block multiple dates for maintenance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_dates'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $reason = sanitize($_POST['reason'] ?? 'Maintenance');
    $notes = sanitize($_POST['notes'] ?? '');
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.car_id FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    
    if ($stmt->fetch()) {
        // Calculate duration
        $duration = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;
        
        // Check for conflicts with bookings
        $stmt = $db->prepare("
            SELECT COUNT(*) as conflict_count FROM bookings
            WHERE car_id = ?
            AND pickup_date <= ?
            AND return_date >= ?
            AND status IN ('confirmed', 'checked_out', 'pending')
        ");
        $stmt->execute([$vehicleId, $endDate, $startDate]);
        $conflictCheck = $stmt->fetch();
        
        if ($conflictCheck['conflict_count'] > 0) {
            $error = "Cannot block dates - vehicle has active bookings during this period";
        } else {
            // Create maintenance record
            $stmt = $db->prepare("
                INSERT INTO car_maintenance (
                    car_id, maintenance_type, description, scheduled_date,
                    estimated_duration, notes, priority, status, created_at
                ) VALUES (?, 'maintenance', ?, ?, ?, ?, 'medium', 'scheduled', NOW())
            ");
            $stmt->execute([$vehicleId, $reason, $startDate, $duration, $notes]);
            $success = "Dates blocked successfully for maintenance";
        }
    }
}

// ============================================
// GET CALENDAR DATA
// ============================================

// Get all vehicles for filter
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cf.quantity_available,
           cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// If no vehicle selected, use the first one
if ($vehicleId === 0 && !empty($vehicles)) {
    $vehicleId = $vehicles[0]['car_id'];
}

// Get selected vehicle details
$currentVehicle = null;
foreach ($vehicles as $v) {
    if ($v['car_id'] == $vehicleId) {
        $currentVehicle = $v;
        break;
    }
}

// Get all relevant data for the selected vehicle
$bookings = [];
$maintenance = [];
$availability = [];

if ($vehicleId > 0) {
    // Get bookings for the month
    $stmt = $db->prepare("
        SELECT 
            b.*,
            u.first_name,
            u.last_name,
            u.email,
            DATEDIFF(b.return_date, b.pickup_date) as duration
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.car_id = ? 
        AND (
            (b.pickup_date BETWEEN ? AND ?)
            OR (b.return_date BETWEEN ? AND ?)
            OR (? BETWEEN b.pickup_date AND b.return_date)
        )
        AND b.status IN ('confirmed', 'checked_out', 'pending')
        ORDER BY b.pickup_date
    ");
    
    $firstDay = "$year-$month-01";
    $lastDay = date('Y-m-t', strtotime($firstDay));
    $stmt->execute([$vehicleId, $firstDay, $lastDay, $firstDay, $lastDay, $firstDay]);
    $bookings = $stmt->fetchAll();
    
    // Get maintenance for the month
    $stmt = $db->prepare("
        SELECT * FROM car_maintenance
        WHERE car_id = ?
        AND (
            (scheduled_date BETWEEN ? AND ?)
            OR (DATE_ADD(scheduled_date, INTERVAL estimated_duration - 1 DAY) BETWEEN ? AND ?)
        )
        AND status IN ('scheduled', 'in_progress')
        ORDER BY scheduled_date
    ");
    $stmt->execute([$vehicleId, $firstDay, $lastDay, $firstDay, $lastDay]);
    $maintenance = $stmt->fetchAll();
    
    // Get availability overrides
    $stmt = $db->prepare("
        SELECT * FROM car_availability
        WHERE car_id = ?
        AND date BETWEEN ? AND ?
    ");
    $stmt->execute([$vehicleId, $firstDay, $lastDay]);
    $availabilityData = $stmt->fetchAll();
    
    foreach ($availabilityData as $avail) {
        $availability[$avail['date']] = $avail;
    }
}

// Build a day-by-day status array
$dayStatus = [];
$currentDate = new DateTime("$year-$month-01");
$endDate = new DateTime($lastDay);

while ($currentDate <= $endDate) {
    $dateStr = $currentDate->format('Y-m-d');
    $status = 'available';
    $details = [];
    
    // Check bookings
    foreach ($bookings as $booking) {
        if ($dateStr >= $booking['pickup_date'] && $dateStr < $booking['return_date']) {
            $status = 'booked';
            $details['bookings'][] = $booking;
        }
    }
    
    // Check maintenance
    foreach ($maintenance as $m) {
        $start = new DateTime($m['scheduled_date']);
        $end = clone $start;
        $end->modify('+' . ($m['estimated_duration'] - 1) . ' days');
        $current = new DateTime($dateStr);
        
        if ($current >= $start && $current <= $end) {
            if ($status === 'available') {
                $status = 'maintenance';
            } elseif ($status === 'booked') {
                $status = 'conflict'; // This shouldn't happen with proper checks
            }
            $details['maintenance'][] = $m;
        }
    }
    
    // Check availability overrides
    if (isset($availability[$dateStr])) {
        $avail = $availability[$dateStr];
        if ($avail['is_blocked']) {
            $status = 'blocked';
        } elseif ($avail['quantity_available'] < $currentVehicle['quantity_available']) {
            $status = 'partial';
        }
        $details['availability'] = $avail;
    }
    
    $dayStatus[$dateStr] = [
        'status' => $status,
        'details' => $details
    ];
    
    $currentDate->modify('+1 day');
}

// Get statistics
$stats = [
    'total_bookings' => count($bookings),
    'total_maintenance' => count($maintenance),
    'total_nights' => 0,
    'total_revenue' => 0,
    'utilization' => 0,
    'available_days' => 0,
    'booked_days' => 0,
    'maintenance_days' => 0,
    'blocked_days' => 0
];

foreach ($dayStatus as $date => $data) {
    switch ($data['status']) {
        case 'booked':
            $stats['booked_days']++;
            break;
        case 'maintenance':
        case 'blocked':
            $stats['maintenance_days']++;
            break;
        case 'partial':
            $stats['booked_days'] += 0.5;
            $stats['available_days'] += 0.5;
            break;
        case 'available':
            $stats['available_days']++;
            break;
    }
}

foreach ($bookings as $booking) {
    $stats['total_revenue'] += $booking['total_amount'];
}

$totalDays = count($dayStatus);
$stats['utilization'] = $totalDays > 0 ? round((($stats['booked_days'] + $stats['maintenance_days']) / $totalDays) * 100, 1) : 0;

// Month names - Fix: Use integer keys
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
?>

<style>
/* Calendar Specific Styles - Keep all existing styles plus add these */
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
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.calendar-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
}

/* Vehicle Selector */
.vehicle-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.vehicle-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-dark);
}

.vehicle-selector select {
    min-width: 350px;
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
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
    border: 1px solid var(--border-gray);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cars-primary);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-gray);
}

/* Calendar Navigation */
.calendar-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.nav-buttons {
    display: flex;
    gap: 8px;
}

.nav-btn {
    padding: 8px 16px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--text-dark);
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
    background: var(--cars-light);
    border-color: var(--cars-primary);
    color: var(--cars-primary);
}

.current-month {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-dark);
}

.view-toggle {
    display: flex;
    gap: 4px;
    border: 1px solid var(--border-gray);
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
    text-decoration: none;
    color: var(--text-dark);
}

.view-toggle .view-btn.active {
    background: var(--cars-primary);
    color: white;
}

/* Legend */
.calendar-legend {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
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

.legend-color.booked { background: var(--cars-primary); }
.legend-color.maintenance { background: var(--cars-warning); }
.legend-color.available { background: #e8f5e9; border: 1px dashed var(--cars-success); }
.legend-color.partial { background: #fff4e6; border: 1px dashed var(--cars-warning); }
.legend-color.today { background: #e1f5fe; border: 2px solid #0288d1; }

/* Calendar Grid */
.calendar-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    overflow: hidden;
    margin-bottom: 24px;
}

.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--bg-gray);
    border-bottom: 1px solid var(--border-gray);
}

.weekday-cell {
    padding: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-dark);
    text-align: center;
    border-right: 1px solid var(--border-gray);
}

.weekday-cell:last-child {
    border-right: none;
}

.calendar-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-auto-rows: minmax(120px, auto);
}

.calendar-day {
    padding: 8px;
    border-right: 1px solid var(--border-gray);
    border-bottom: 1px solid var(--border-gray);
    min-height: 120px;
    position: relative;
    cursor: pointer;
    transition: all 0.2s;
}

.calendar-day:nth-child(7n) {
    border-right: none;
}

.calendar-day:hover {
    background: var(--cars-light);
}

.calendar-day.empty {
    background: var(--bg-gray);
    cursor: default;
}

.calendar-day.empty:hover {
    background: var(--bg-gray);
}

.calendar-day.today {
    background: #e1f5fe;
    border: 2px solid #0288d1;
}

.calendar-day.has-bookings {
    background: #fff4e6;
}

.calendar-day.has-maintenance {
    background: #fce8e8;
}

.day-number {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 4px;
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.event-item {
    padding: 4px 6px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
    transition: all 0.2s;
}

.event-item:hover {
    transform: scale(1.02);
}

.event-booking {
    background: var(--cars-primary);
    color: white;
}

.event-maintenance {
    background: var(--cars-warning);
    color: white;
}

.event-checkout {
    background: var(--cars-success);
    color: white;
}

.event-checkin {
    background: #0288d1;
    color: white;
}

.availability-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    font-size: 0.5625rem;
    padding: 2px 4px;
    border-radius: 4px;
    background: rgba(0,0,0,0.6);
    color: white;
}

/* Action Bar */
.action-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
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
    color: var(--text-light);
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
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
}

.modal-close {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: none;
    background: var(--bg-gray);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.75rem;
}

.modal-close:hover {
    background: var(--cars-danger);
    color: white;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-gray);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--bg-gray);
    position: sticky;
    bottom: 0;
}

/* Form Styles */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--text-dark);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--cars-primary);
    box-shadow: 0 0 0 3px rgba(255,140,0,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .calendar-days {
        grid-template-columns: 1fr;
    }
    
    .calendar-day {
        min-height: auto;
    }
    
    .vehicle-selector select {
        min-width: 100%;
    }
    
    .calendar-nav {
        flex-direction: column;
        align-items: stretch;
    }
    
    .nav-buttons {
        justify-content: center;
    }
    
    .current-month {
        text-align: center;
    }
    
    .view-toggle {
        justify-content: center;
    }
}
/* Status indicators for day cells */
.calendar-day.status-available { background: #e8f5e9; }
.calendar-day.status-booked { background: #fff4e6; }
.calendar-day.status-maintenance { background: #fce8e8; }
.calendar-day.status-blocked { background: #fce8e8; opacity: 0.7; }
.calendar-day.status-partial { background: #fff4e6; border: 2px dashed var(--cars-warning); }
.calendar-day.status-conflict { background: #fce8e8; border: 2px solid var(--cars-danger); }

.status-badge-small {
    display: inline-block;
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 0.5625rem;
    font-weight: 600;
    margin-top: 2px;
}

.status-badge-available { background: var(--cars-success); color: white; }
.status-badge-booked { background: var(--cars-primary); color: white; }
.status-badge-maintenance { background: var(--cars-warning); color: white; }
.status-badge-blocked { background: var(--cars-danger); color: white; }
.status-badge-partial { background: var(--cars-warning); color: white; }

/* Day detail modal */
.day-detail-status {
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
    font-weight: 600;
    text-align: center;
}

.day-detail-status.available { background: #e8f5e9; color: var(--cars-success); }
.day-detail-status.booked { background: #fff4e6; color: var(--cars-primary); }
.day-detail-status.maintenance { background: #fce8e8; color: var(--cars-warning); }
.day-detail-status.blocked { background: #fce8e8; color: var(--cars-danger); }

.conflict-warning {
    background: #fce8e8;
    border: 1px solid var(--cars-danger);
    color: var(--cars-danger);
    padding: 12px;
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>

<div class="calendar-header">
    <div class="calendar-title">
        <h1>Availability Calendar</h1>
        <p>Manage vehicle availability, view bookings and maintenance</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="openBlockModal()">
            <i class="bi bi-calendar-x"></i> Block Dates
        </button>
        <button class="btn-secondary" onclick="exportCalendar()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Vehicle Selector -->
<div class="vehicle-selector">
    <label for="vehicleSelect"><i class="bi bi-car-front"></i> Select Vehicle:</label>
    <select id="vehicleSelect" onchange="changeVehicle(this.value)">
        <option value="0">Choose a vehicle</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?php echo $v['car_id']; ?>" <?php echo $v['car_id'] == $vehicleId ? 'selected' : ''; ?>>
            <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo sanitize($v['company_name']); ?>)
            • <?php echo $v['quantity_available']; ?> available
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($vehicleId > 0 && $currentVehicle): ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_bookings']; ?></div>
        <div class="stat-label">Bookings This Month</div>
        <div class="stat-footer"><?php echo $stats['booked_days']; ?> booked days</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_maintenance']; ?></div>
        <div class="stat-label">Maintenance</div>
        <div class="stat-footer"><?php echo $stats['maintenance_days']; ?> days</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['utilization']; ?>%</div>
        <div class="stat-label">Utilization</div>
        <div class="stat-footer"><?php echo $stats['available_days']; ?> days available</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Projected Revenue</div>
        <div class="stat-footer">For this month</div>
    </div>
</div>

<!-- Calendar Navigation -->
<div class="calendar-nav">
    <div class="nav-buttons">
        <a href="?vehicle=<?php echo $vehicleId; ?>&year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="nav-btn">
            <i class="bi bi-chevron-left"></i> <?php echo $monthNames[$prevMonth]; ?>
        </a>
        <a href="?vehicle=<?php echo $vehicleId; ?>&year=<?php echo date('Y'); ?>&month=<?php echo date('m'); ?>" class="nav-btn">
            <i class="bi bi-calendar3"></i> Today
        </a>
        <a href="?vehicle=<?php echo $vehicleId; ?>&year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="nav-btn">
            <?php echo $monthNames[$nextMonth]; ?> <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    
<div class="current-month">
    <?php 
    $monthIndex = intval($month);
    echo $monthNames[$monthIndex] . ' ' . $year; 
    ?>
</div>
    
    <div class="view-toggle">
        <a href="?vehicle=<?php echo $vehicleId; ?>&view=month" class="view-btn <?php echo $view == 'month' ? 'active' : ''; ?>">Month</a>
        <a href="?vehicle=<?php echo $vehicleId; ?>&view=week" class="view-btn <?php echo $view == 'week' ? 'active' : ''; ?>">Week</a>
        <a href="?vehicle=<?php echo $vehicleId; ?>&view=day" class="view-btn <?php echo $view == 'day' ? 'active' : ''; ?>">Day</a>
    </div>
</div>

<!-- Legend -->
<div class="calendar-legend">
    <div class="legend-item">
        <div class="legend-color" style="background: #e8f5e9; border: 1px solid var(--cars-success);"></div>
        <span>Available</span>
    </div>
    <div class="legend-item">
        <div class="legend-color" style="background: #fff4e6;"></div>
        <span>Booked</span>
    </div>
    <div class="legend-item">
        <div class="legend-color" style="background: #fce8e8;"></div>
        <span>Maintenance/Blocked</span>
    </div>
    <div class="legend-item">
        <div class="legend-color" style="background: #fff4e6; border: 2px dashed var(--cars-warning);"></div>
        <span>Partially Available</span>
    </div>
    <div class="legend-item">
        <div class="legend-color" style="background: #e1f5fe; border: 2px solid #0288d1;"></div>
        <span>Today</span>
    </div>
</div>

<!-- Calendar Grid -->
<div class="calendar-container">
    <div class="calendar-weekdays">
        <?php foreach ($weekDays as $day): ?>
        <div class="weekday-cell"><?php echo $day; ?></div>
        <?php endforeach; ?>
    </div>
    
    <div class="calendar-days">
        <?php
        // Empty cells before first day
        for ($i = 0; $i < $firstDayWeekday; $i++):
        ?>
        <div class="calendar-day empty"></div>
        <?php endfor; ?>
        
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $currentDate = sprintf("%04d-%02d-%02d", $year, $month, $day);
            $isToday = $currentDate == date('Y-m-d');
            
            $dayInfo = $dayStatus[$currentDate] ?? ['status' => 'available', 'details' => []];
            $status = $dayInfo['status'];
            $details = $dayInfo['details'];
            
            $cellClass = 'calendar-day status-' . $status;
            if ($isToday) $cellClass .= ' today';
        ?>
        <div class="<?php echo $cellClass; ?>" onclick="openDayModal('<?php echo $currentDate; ?>', '<?php echo $status; ?>', <?php echo $currentVehicle['quantity_available']; ?>)">
            <div class="day-number"><?php echo $day; ?></div>
            
            <?php if ($status === 'partial'): ?>
            <div class="availability-badge">
                Limited
            </div>
            <?php endif; ?>
            
            <div class="day-events">
                <?php if (isset($details['bookings'])): ?>
                    <?php foreach (array_slice($details['bookings'], 0, 2) as $booking): ?>
                    <div class="event-item event-booking" 
                         title="Booking: <?php echo $booking['first_name'] . ' ' . ($booking['last_name'] ?? ''); ?>"
                         onclick="event.stopPropagation(); showBooking(<?php echo $booking['booking_id']; ?>)">
                        <i class="bi bi-person"></i> 
                        <?php echo substr($booking['first_name'] ?? 'Guest', 0, 1); ?>.
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($details['bookings']) > 2): ?>
                    <div class="event-item" style="background: transparent; color: var(--text-light);">
                        +<?php echo count($details['bookings']) - 2; ?> more
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($details['maintenance'])): ?>
                    <?php foreach ($details['maintenance'] as $m): ?>
                    <div class="event-item event-maintenance" 
                         title="Maintenance: <?php echo $m['description']; ?>"
                         onclick="event.stopPropagation(); showMaintenance(<?php echo $m['maintenance_id']; ?>)">
                        <i class="bi bi-tools"></i> Maint
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($status !== 'available'): ?>
            <div class="status-badge-small status-badge-<?php echo $status; ?>">
                <?php echo ucfirst($status); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
        
        <?php
        // Fill remaining cells to complete the grid
        $totalCells = $firstDayWeekday + $daysInMonth;
        $remainingCells = 42 - $totalCells; // 6 rows × 7 days = 42
        for ($i = 0; $i < $remainingCells; $i++):
        ?>
        <div class="calendar-day empty"></div>
        <?php endfor; ?>
    </div>
</div>

<!-- Action Bar -->
<div class="action-bar">
    <div class="action-group">
        <span class="action-label">Quick Actions:</span>
        <button class="btn-secondary btn-sm" onclick="openBlockModal()">
            <i class="bi bi-calendar-x"></i> Block Dates
        </button>
        <a href="maintenance.php?vehicle=<?php echo $vehicleId; ?>" class="btn-secondary btn-sm">
            <i class="bi bi-tools"></i> Maintenance
        </a>
    </div>
    
    <div class="action-group">
        <span class="action-label">View:</span>
        <a href="bookings.php?vehicle=<?php echo $vehicleId; ?>" class="btn-outline btn-sm">
            <i class="bi bi-calendar-check"></i> All Bookings
        </a>
        <a href="fleet.php" class="btn-outline btn-sm">
            <i class="bi bi-car-front"></i> Fleet
        </a>
    </div>
</div>

<?php else: ?>
<div class="empty-state">
    <i class="bi bi-calendar2-week"></i>
    <h3>Select a vehicle</h3>
    <p>Please select a vehicle from the dropdown above to view its calendar.</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Day Detail Modal -->
<div class="modal" id="dayModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="dayModalTitle">Manage Day</h3>
            <button class="modal-close" onclick="closeModal('dayModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="dayForm">
            <div class="modal-body">
                <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
                <input type="hidden" name="date" id="modal_date" value="">
                
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <div id="modal_date_display" style="font-weight: 600; margin-bottom: 10px; font-size: 1rem;"></div>
                </div>
                
                <div id="modal_current_status" class="day-detail-status"></div>
                
                <div id="conflictWarning" class="conflict-warning" style="display: none;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>This date has active bookings. Status cannot be changed.</span>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Set Status</label>
                    <select name="status" id="modal_status" class="form-control" onchange="toggleStatusFields()">
                        <option value="available">Available</option>
                        <option value="blocked">Blocked (Unavailable)</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
                
                <div id="maintenanceFields" style="display: none;">
                    <div class="form-group">
                        <label class="form-label">Duration (days)</label>
                        <input type="number" name="duration" id="modal_duration" class="form-control" value="1" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason</label>
                        <select name="reason" class="form-control">
                            <option value="Regular Maintenance">Regular Maintenance</option>
                            <option value="Repair">Repair</option>
                            <option value="Service">Service</option>
                            <option value="Cleaning">Deep Cleaning</option>
                            <option value="Inspection">Inspection</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="notes" id="modal_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('dayModal')">Cancel</button>
                <button type="submit" name="update_day_status" class="btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Block Dates Modal -->
<div class="modal" id="blockModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Block Dates for Maintenance</h3>
            <button class="modal-close" onclick="closeModal('blockModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <select name="reason" class="form-control">
                        <option value="Regular Maintenance">Regular Maintenance</option>
                        <option value="Repair">Repair</option>
                        <option value="Service">Service</option>
                        <option value="Cleaning">Deep Cleaning</option>
                        <option value="Inspection">Inspection</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional details..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('blockModal')">Cancel</button>
                <button type="submit" name="block_dates" class="btn-primary">Block Dates</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// VEHICLE SELECTION
// ============================================
function changeVehicle(vehicleId) {
    if (vehicleId) {
        window.location.href = 'calendar.php?vehicle=' + vehicleId + '&year=<?php echo $year; ?>&month=<?php echo $month; ?>';
    } else {
        window.location.href = 'calendar.php';
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
function openDayModal(date, currentStatus, totalAvailable) {
    document.getElementById('modal_date').value = date;
    
    // Format date for display
    const dateObj = new Date(date + 'T12:00:00');
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('modal_date_display').textContent = dateObj.toLocaleDateString('en-US', options);
    
    // Show current status
    const statusDiv = document.getElementById('modal_current_status');
    statusDiv.className = 'day-detail-status ' + currentStatus;
    
    let statusText = '';
    switch(currentStatus) {
        case 'available':
            statusText = '✓ Available';
            statusDiv.style.background = '#e8f5e9';
            statusDiv.style.color = 'var(--cars-success)';
            break;
        case 'booked':
            statusText = '📅 Booked';
            statusDiv.style.background = '#fff4e6';
            statusDiv.style.color = 'var(--cars-primary)';
            break;
        case 'maintenance':
            statusText = '🔧 Maintenance';
            statusDiv.style.background = '#fce8e8';
            statusDiv.style.color = 'var(--cars-warning)';
            break;
        case 'blocked':
            statusText = '🚫 Blocked';
            statusDiv.style.background = '#fce8e8';
            statusDiv.style.color = 'var(--cars-danger)';
            break;
        case 'partial':
            statusText = '⚠️ Partially Available';
            statusDiv.style.background = '#fff4e6';
            statusDiv.style.color = 'var(--cars-warning)';
            break;
    }
    statusDiv.innerHTML = '<strong>Current Status:</strong> ' + statusText;
    
    // Show/hide conflict warning
    const conflictWarning = document.getElementById('conflictWarning');
    const statusSelect = document.getElementById('modal_status');
    const maintenanceFields = document.getElementById('maintenanceFields');
    
    if (currentStatus === 'booked') {
        conflictWarning.style.display = 'flex';
        statusSelect.disabled = true;
        maintenanceFields.style.display = 'none';
    } else {
        conflictWarning.style.display = 'none';
        statusSelect.disabled = false;
        statusSelect.value = currentStatus === 'maintenance' ? 'maintenance' : (currentStatus === 'blocked' ? 'blocked' : 'available');
        toggleStatusFields();
    }
    
    openModal('dayModal');
}

function toggleStatusFields() {
    const status = document.getElementById('modal_status').value;
    const maintenanceFields = document.getElementById('maintenanceFields');
    
    if (status === 'maintenance') {
        maintenanceFields.style.display = 'block';
    } else {
        maintenanceFields.style.display = 'none';
    }
}

// ============================================
// BLOCK MODAL
// ============================================
function openBlockModal() {
    openModal('blockModal');
}

// ============================================
// VIEW DETAILS
// ============================================
function showBooking(bookingId) {
    window.location.href = 'bookings.php?booking=' + bookingId;
}

function showMaintenance(maintenanceId) {
    window.location.href = 'maintenance.php?maintenance=' + maintenanceId;
}

// ============================================
// EXPORT CALENDAR
// ============================================
function exportCalendar() {
    // Create CSV content
    let csv = "Date,Status,Bookings,Maintenance\n";
    
    <?php foreach ($dayStatus as $date => $data): ?>
    csv += "<?php echo $date; ?>,<?php echo $data['status']; ?>,<?php echo isset($data['details']['bookings']) ? count($data['details']['bookings']) : 0; ?>,<?php echo isset($data['details']['maintenance']) ? count($data['details']['maintenance']) : 0; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'calendar_<?php echo $year; ?>_<?php echo $month; ?>.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>