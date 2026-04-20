<?php
$pageTitle = 'Booking Management';
require_once 'includes/cars_header.php';

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
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET b.status = ?, b.cancellation_reason = ?, b.updated_at = NOW()
        WHERE b.booking_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$newStatus, $cancellationReason, $bookingId, $userId]);
    
    // If confirming, update car availability logic could go here
    if ($newStatus === 'confirmed') {
        // Optionally update car_fleet availability if needed
    }
    
    $success = "Booking status updated to " . ucfirst($newStatus);
}

// Send message to customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $bookingId = intval($_POST['booking_id']);
    $message = sanitize($_POST['message']);
    $subject = sanitize($_POST['subject'] ?? 'Question about your car rental');
    
    // Get booking details
    $stmt = $db->prepare("
        SELECT b.*, u.user_id as guest_id, u.email, u.first_name, u.last_name,
               cf.brand, cf.model
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        JOIN users u ON b.user_id = u.user_id
        WHERE b.booking_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Insert message (you'll need a messages table)
        $stmt = $db->prepare("
            INSERT INTO messages (sender_id, receiver_id, booking_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $booking['guest_id'], $bookingId, $subject, $message]);
        
        $success = "Message sent to customer successfully";
    }
}

// Check-out vehicle (mark as picked up)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_out'])) {
    $bookingId = intval($_POST['booking_id']);
    $odometerReading = intval($_POST['odometer_reading'] ?? 0);
    $fuelLevel = sanitize($_POST['fuel_level'] ?? 'full');
    $notes = sanitize($_POST['check_out_notes'] ?? '');
    
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET b.status = 'checked_out', 
            b.check_out_notes = ?,
            b.odometer_out = ?,
            b.fuel_level_out = ?,
            b.actual_pickup_date = NOW(),
            b.updated_at = NOW()
        WHERE b.booking_id = ? AND cr.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$notes, $odometerReading, $fuelLevel, $bookingId, $userId]);
    
    $success = "Vehicle checked out successfully";
}

// Check-in vehicle (mark as returned)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in'])) {
    $bookingId = intval($_POST['booking_id']);
    $odometerReading = intval($_POST['odometer_reading'] ?? 0);
    $fuelLevel = sanitize($_POST['fuel_level'] ?? 'full');
    $damageNotes = sanitize($_POST['damage_notes'] ?? '');
    $additionalCharges = floatval($_POST['additional_charges'] ?? 0);
    
    // Get original booking to calculate extra charges
    $stmt = $db->prepare("
        SELECT b.*, cf.daily_rate, cf.excess_km_charge, cf.free_km_per_day
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        WHERE b.booking_id = ? AND b.status = 'checked_out'
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Calculate extra km charges if applicable
        $extraKmCharge = 0;
        if ($odometerReading > 0 && $booking['odometer_out'] > 0) {
            $kmDriven = $odometerReading - $booking['odometer_out'];
            $freeKm = $booking['free_km_per_day'] * $booking['num_nights'];
            $extraKm = max(0, $kmDriven - $freeKm);
            $extraKmCharge = $extraKm * $booking['excess_km_charge'];
        }
        
        $totalAdditional = $additionalCharges + $extraKmCharge;
        
        $stmt = $db->prepare("
            UPDATE bookings b
            SET b.status = 'completed', 
                b.odometer_in = ?,
                b.fuel_level_in = ?,
                b.damage_notes = ?,
                b.additional_charges = ?,
                b.extra_km_charge = ?,
                b.actual_return_date = NOW(),
                b.total_amount = b.total_amount + ?,
                b.updated_at = NOW()
            WHERE b.booking_id = ?
        ");
        $stmt->execute([
            $odometerReading, $fuelLevel, $damageNotes, 
            $additionalCharges, $extraKmCharge, $totalAdditional, $bookingId
        ]);
        
        $success = "Vehicle checked in successfully";
        if ($extraKmCharge > 0) {
            $success .= ". Extra km charge: " . formatPrice($extraKmCharge);
        }
    }
}

// ============================================
// GET FILTERS
// ============================================
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateRange = isset($_GET['date_range']) ? sanitize($_GET['date_range']) : 'upcoming';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Build query conditions
$conditions = ["cr.owner_id = ?"];
$params = [$userId];

if ($vehicleId > 0) {
    $conditions[] = "cf.car_id = ?";
    $params[] = $vehicleId;
}

if ($status !== 'all') {
    $conditions[] = "b.status = ?";
    $params[] = $status;
}

// Date range logic for car rentals
if ($dateRange === 'upcoming') {
    $conditions[] = "b.pickup_date >= CURDATE()";
    $conditions[] = "b.status IN ('confirmed', 'pending')";
} elseif ($dateRange === 'current') {
    $conditions[] = "CURDATE() BETWEEN b.pickup_date AND b.return_date";
    $conditions[] = "b.status IN ('confirmed', 'checked_out')";
} elseif ($dateRange === 'past') {
    $conditions[] = "b.return_date < CURDATE()";
} elseif ($dateRange === 'today_pickup') {
    $conditions[] = "DATE(b.pickup_date) = CURDATE()";
    $conditions[] = "b.status IN ('confirmed', 'pending')";
} elseif ($dateRange === 'today_return') {
    $conditions[] = "DATE(b.return_date) = CURDATE()";
    $conditions[] = "b.status IN ('confirmed', 'checked_out')";
} elseif ($dateRange === 'custom' && $fromDate && $toDate) {
    $conditions[] = "b.pickup_date BETWEEN ? AND ?";
    $params[] = $fromDate;
    $params[] = $toDate;
}

if ($search) {
    $conditions[] = "(b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR cf.brand LIKE ? OR cf.model LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET BOOKINGS DATA
// ============================================

// Get all vehicles for filter
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// Get bookings with details
$stmt = $db->prepare("
    SELECT 
        b.*,
        cf.car_id,
        cf.brand,
        cf.model,
        cf.car_type,
        cf.daily_rate,
        cr.company_name,
        u.user_id as customer_id,
        u.first_name as customer_first_name,
        u.last_name as customer_last_name,
        u.email as customer_email,
        u.phone as customer_phone,
        DATEDIFF(b.return_date, b.pickup_date) as rental_days,
        DATEDIFF(b.pickup_date, CURDATE()) as days_until_pickup,
        TIMESTAMPDIFF(HOUR, b.pickup_date, NOW()) as hours_since_pickup,
        CASE 
            WHEN b.status = 'confirmed' AND DATE(b.pickup_date) = CURDATE() THEN 'pickup-today'
            WHEN b.status = 'checked_out' AND DATE(b.return_date) = CURDATE() THEN 'return-today'
            WHEN b.status = 'confirmed' AND b.pickup_date < CURDATE() AND b.return_date > CURDATE() THEN 'overdue-pickup'
            WHEN b.status = 'checked_out' AND b.return_date < CURDATE() THEN 'overdue-return'
            ELSE NULL
        END as alert
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN b.status = 'pending' THEN 1
            WHEN b.status = 'confirmed' AND b.pickup_date >= CURDATE() THEN 2
            WHEN b.status = 'confirmed' AND b.pickup_date < CURDATE() THEN 3
            WHEN b.status = 'checked_out' THEN 4
            WHEN b.status = 'completed' THEN 5
            WHEN b.status = 'cancelled' THEN 6
            ELSE 7
        END,
        b.pickup_date ASC
");

$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($bookings),
    'pending' => 0,
    'confirmed' => 0,
    'checked_out' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'today_pickups' => 0,
    'today_returns' => 0,
    'overdue_pickups' => 0,
    'overdue_returns' => 0,
    'total_revenue' => 0,
    'avg_booking_value' => 0,
    'total_rental_days' => 0
];

foreach ($bookings as $booking) {
    $stats[$booking['status']]++;
    if ($booking['status'] === 'confirmed' || $booking['status'] === 'completed' || $booking['status'] === 'checked_out') {
        $stats['total_revenue'] += $booking['total_amount'];
        $stats['total_rental_days'] += $booking['rental_days'];
    }
    
    // Count today's pickups
    if (date('Y-m-d', strtotime($booking['pickup_date'])) === date('Y-m-d') && $booking['status'] === 'confirmed') {
        $stats['today_pickups']++;
    }
    
    // Count today's returns
    if (date('Y-m-d', strtotime($booking['return_date'])) === date('Y-m-d') && $booking['status'] === 'checked_out') {
        $stats['today_returns']++;
    }
    
    // Count overdue
    if ($booking['alert'] === 'overdue-pickup') $stats['overdue_pickups']++;
    if ($booking['alert'] === 'overdue-return') $stats['overdue_returns']++;
}

$stats['avg_booking_value'] = $stats['total'] > 0 ? $stats['total_revenue'] / $stats['total'] : 0;

// Get upcoming pickups (next 7 days)
$stmt = $db->prepare("
    SELECT b.*, cf.brand, cf.model, u.first_name, u.last_name
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE cr.owner_id = ? 
    AND b.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND b.status = 'confirmed'
    ORDER BY b.pickup_date ASC
");
$stmt->execute([$userId]);
$upcomingPickups = $stmt->fetchAll();

// Get calendar data for the next 30 days
$stmt = $db->prepare("
    SELECT 
        b.pickup_date as date,
        COUNT(*) as pickups,
        SUM(b.num_guests) as customers
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    AND b.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND b.status IN ('confirmed', 'pending')
    GROUP BY b.pickup_date
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
    'checked_out' => ['On Rent', 'info'],
    'completed' => ['Completed', 'secondary'],
    'cancelled' => ['Cancelled', 'danger']
];

// Fuel level options
$fuelLevels = ['full', '3/4', '1/2', '1/4', 'empty'];
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
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.bookings-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
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

.stat-trend {
    font-size: 0.6875rem;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
}

.trend-up { color: var(--cars-success); }
.trend-down { color: var(--cars-danger); }
.trend-warning { color: var(--cars-warning); }

/* Filter Bar */
.filter-bar {
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

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    white-space: nowrap;
}

.filter-select, .filter-input {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
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
    color: var(--text-light);
    font-size: 0.875rem;
}

.search-box input {
    width: 100%;
    padding: 10px 16px 10px 38px;
    border: 1px solid var(--border-gray);
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
    min-width: 180px;
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.2s;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}

.quick-action-card:hover {
    border-color: var(--cars-primary);
    box-shadow: var(--shadow-sm);
    transform: translateY(-2px);
}

.quick-action-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--cars-light);
    color: var(--cars-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.quick-action-info h4 {
    font-size: 0.9375rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.quick-action-info p {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin: 0;
}

/* Bookings Table */
.bookings-table-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
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
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: var(--bg-gray);
    border-bottom: 1px solid var(--border-gray);
}

.bookings-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-gray);
    font-size: 0.8125rem;
    vertical-align: middle;
}

.bookings-table tr:last-child td {
    border-bottom: none;
}

.bookings-table tr:hover td {
    background: var(--cars-light);
}

.bookings-table tr.alert-pickup {
    background: #e6f4ea;
}

.bookings-table tr.alert-return {
    background: #fff4e6;
}

.bookings-table tr.alert-overdue {
    background: #fce8e8;
}

.booking-ref {
    font-family: monospace;
    font-weight: 600;
    color: var(--cars-primary);
    font-size: 0.75rem;
}

.customer-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.customer-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--cars-light);
    color: var(--cars-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.75rem;
}

.vehicle-info {
    display: flex;
    flex-direction: column;
}

.vehicle-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.vehicle-type {
    font-size: 0.625rem;
    color: var(--text-light);
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
    font-size: 0.625rem;
    color: var(--text-light);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.status-pending { background: #fff4e6; color: var(--cars-warning); }
.status-confirmed { background: #e6f4ea; color: var(--cars-success); }
.status-checked_out { background: var(--cars-light); color: var(--cars-primary); }
.status-completed { background: var(--bg-gray); color: var(--text-light); }
.status-cancelled { background: #fce8e8; color: var(--cars-danger); }

.amount-cell {
    font-weight: 700;
    color: var(--cars-success);
}

.action-cell {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 4px 8px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--text-dark);
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
    background: var(--cars-light);
    border-color: var(--cars-primary);
    color: var(--cars-primary);
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--cars-warning);
    color: var(--cars-warning);
}

.action-btn.success:hover {
    background: #e6f4ea;
    border-color: var(--cars-success);
    color: var(--cars-success);
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--cars-danger);
    color: var(--cars-danger);
}

/* Upcoming Calendar */
.calendar-mini {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
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
    font-size: 0.9375rem;
    font-weight: 700;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    text-align: center;
}

.calendar-day {
    padding: 8px 4px;
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    position: relative;
    background: var(--bg-gray);
}

.calendar-day.has-pickups {
    background: var(--cars-light);
    color: var(--cars-primary);
    font-weight: 600;
}

.calendar-day.has-pickups::after {
    content: attr(data-count);
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--cars-primary);
    color: white;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    font-size: 0.5625rem;
    display: flex;
    align-items: center;
    justify-content: center;
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
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group.full-width {
    grid-column: span 2;
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
    
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="bookings-header">
    <div class="bookings-title">
        <h1>Booking Management</h1>
        <p>Manage all your car rental reservations</p>
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
            <span class="trend-up"><?php echo $stats['confirmed']; ?> confirmed</span>
            <span class="trend-warning"><?php echo $stats['pending']; ?> pending</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['today_pickups']; ?></div>
        <div class="stat-label">Pickups Today</div>
        <div class="stat-trend">
            <span class="trend-up"><?php echo $stats['today_returns']; ?> returns</span>
            <span class="trend-down"><?php echo $stats['overdue_pickups']; ?> overdue</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['checked_out']; ?></div>
        <div class="stat-label">Currently Rented</div>
        <div class="stat-trend">
            <span>Active rentals</span>
            <span class="trend-down"><?php echo $stats['overdue_returns']; ?> overdue</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-trend">
            <span>Avg: <?php echo formatPrice($stats['avg_booking_value']); ?></span>
            <span><?php echo $stats['total_rental_days']; ?> days</span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <a href="?status=pending" class="quick-action-card">
        <div class="quick-action-icon">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['pending']; ?> Pending</h4>
            <p>Awaiting confirmation</p>
        </div>
    </a>
    
    <a href="?date_range=today_pickup" class="quick-action-card">
        <div class="quick-action-icon">
            <i class="bi bi-arrow-up-circle"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['today_pickups']; ?> Today</h4>
            <p>Pickups scheduled</p>
        </div>
    </a>
    
    <a href="?date_range=today_return" class="quick-action-card">
        <div class="quick-action-icon">
            <i class="bi bi-arrow-down-circle"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['today_returns']; ?> Today</h4>
            <p>Returns scheduled</p>
        </div>
    </a>
    
    <a href="?status=checked_out" class="quick-action-card">
        <div class="quick-action-icon">
            <i class="bi bi-car-front"></i>
        </div>
        <div class="quick-action-info">
            <h4><?php echo $stats['checked_out']; ?> On Rent</h4>
            <p>Currently out</p>
        </div>
    </a>
</div>

<!-- Filter Bar -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Vehicle</label>
        <select name="vehicle" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?php echo $v['car_id']; ?>" <?php echo $vehicleId == $v['car_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo sanitize($v['company_name']); ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Status</label>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all">All Status</option>
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed" <?php echo $status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="checked_out" <?php echo $status == 'checked_out' ? 'selected' : ''; ?>>On Rent</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Date Range</label>
        <select name="date_range" class="filter-select" onchange="toggleCustomDates(this.value); this.form.submit()">
            <option value="all">All Dates</option>
            <option value="upcoming" <?php echo $dateRange == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
            <option value="current" <?php echo $dateRange == 'current' ? 'selected' : ''; ?>>Current Rentals</option>
            <option value="past" <?php echo $dateRange == 'past' ? 'selected' : ''; ?>>Past</option>
            <option value="today_pickup" <?php echo $dateRange == 'today_pickup' ? 'selected' : ''; ?>>Today's Pickups</option>
            <option value="today_return" <?php echo $dateRange == 'today_return' ? 'selected' : ''; ?>>Today's Returns</option>
            <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
        </select>
    </div>
    
    <div id="customDates" style="display: <?php echo $dateRange == 'custom' ? 'flex' : 'none'; ?>; gap: 8px;">
        <input type="date" name="from_date" class="filter-input" value="<?php echo $fromDate; ?>">
        <input type="date" name="to_date" class="filter-input" value="<?php echo $toDate; ?>">
        <button type="submit" class="btn-primary btn-sm">Apply</button>
    </div>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" name="search" placeholder="Search customer, vehicle, or booking ref..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <?php if ($vehicleId || $status != 'all' || $dateRange != 'all' || $search): ?>
    <a href="bookings.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Mini Calendar -->
<?php if (!empty($calendarData)): ?>
<div class="calendar-mini">
    <div class="calendar-header">
        <h3 class="calendar-title">📅 Next 30 Days - Pickups</h3>
        <a href="calendar.php" class="btn-outline btn-sm">Full Calendar →</a>
    </div>
    <div class="calendar-grid">
        <?php
        $today = new DateTime();
        for ($i = 0; $i < 35; $i++):
            $date = clone $today;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $dayName = $date->format('D');
            $dayNum = $date->format('j');
            $hasPickups = isset($calendar[$dateStr]);
        ?>
        <div class="calendar-day <?php echo $hasPickups ? 'has-pickups' : ''; ?>" 
             data-count="<?php echo $hasPickups ? $calendar[$dateStr]['pickups'] : ''; ?>"
             title="<?php echo $hasPickups ? $calendar[$dateStr]['pickups'] . ' pickups, ' . $calendar[$dateStr]['customers'] . ' customers' : ''; ?>">
            <div style="font-size: 0.5625rem; color: var(--text-light);"><?php echo $dayName; ?></div>
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
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Pickup / Return</th>
                <th>Duration</th>
                <th>Status</th>
                <th>Amount</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-light);">
                    <i class="bi bi-inbox" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                    No bookings found
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($bookings as $booking): 
                    $statusInfo = $statusLabels[$booking['status']];
                    $rowClass = '';
                    if ($booking['alert'] == 'pickup-today') $rowClass = 'alert-pickup';
                    if ($booking['alert'] == 'return-today') $rowClass = 'alert-return';
                    if ($booking['alert'] == 'overdue-pickup' || $booking['alert'] == 'overdue-return') $rowClass = 'alert-overdue';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td>
                        <span class="booking-ref">#<?php echo $booking['booking_reference']; ?></span>
                    </td>
                    <td>
                        <div class="customer-info">
                            <div class="customer-avatar">
                                <?php echo strtoupper(substr($booking['customer_first_name'] ?? 'G', 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 0.8125rem;">
                                    <?php echo sanitize($booking['customer_first_name'] . ' ' . substr($booking['customer_last_name'] ?? '', 0, 1) . '.'); ?>
                                </div>
                                <div style="font-size: 0.625rem; color: var(--text-light);">
                                    <?php echo sanitize($booking['customer_email'] ?? 'No email'); ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="vehicle-info">
                            <span class="vehicle-name"><?php echo sanitize($booking['brand'] . ' ' . $booking['model']); ?></span>
                            <span class="vehicle-type"><?php echo ucfirst($booking['car_type']); ?> • <?php echo sanitize($booking['company_name']); ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="date-cell">
                            <span class="date-main">
                                <?php echo date('M d, Y', strtotime($booking['pickup_date'])); ?>
                            </span>
                            <span class="date-range">
                                to <?php echo date('M d, Y', strtotime($booking['return_date'])); ?>
                            </span>
                        </div>
                    </td>
                    <td><?php echo $booking['rental_days']; ?> days</td>
                    <td>
                        <span class="status-badge status-<?php echo $booking['status']; ?>">
                            <?php echo $statusInfo[0]; ?>
                        </span>
                        <?php if ($booking['alert'] == 'overdue-pickup'): ?>
                        <div style="font-size: 0.5625rem; color: var(--cars-danger); margin-top: 4px;">
                            Overdue pickup
                        </div>
                        <?php elseif ($booking['alert'] == 'overdue-return'): ?>
                        <div style="font-size: 0.5625rem; color: var(--cars-danger); margin-top: 4px;">
                            Overdue return
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
                                <?php if ($booking['alert'] == 'pickup-today'): ?>
                                <button class="action-btn success" onclick="openCheckOutModal(<?php echo htmlspecialchars(json_encode($booking)); ?>)">
                                    <i class="bi bi-arrow-up-circle"></i> Check-out
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] == 'checked_out'): ?>
                                <?php if ($booking['alert'] == 'return-today' || $booking['alert'] == 'overdue-return'): ?>
                                <button class="action-btn warning" onclick="openCheckInModal(<?php echo htmlspecialchars(json_encode($booking)); ?>)">
                                    <i class="bi bi-arrow-down-circle"></i> Check-in
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] != 'cancelled' && $booking['status'] != 'completed'): ?>
                            <button class="action-btn danger" onclick="showCancelModal(<?php echo $booking['booking_id']; ?>)">
                                <i class="bi bi-x-lg"></i> Cancel
                            </button>
                            <?php endif; ?>
                            
                            <button class="action-btn" onclick="messageCustomer(<?php echo $booking['booking_id']; ?>)">
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

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Check-out Modal -->
<div class="modal" id="checkOutModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Check-out Vehicle</h3>
            <button class="modal-close" onclick="closeModal('checkOutModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="checkout_booking_id" value="0">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--bg-gray); border-radius: var(--radius-sm);">
                    <div><strong id="checkout_vehicle"></strong></div>
                    <div style="font-size: 0.75rem; color: var(--text-light);" id="checkout_customer"></div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Odometer Reading (km)</label>
                        <input type="number" name="odometer_reading" class="form-control" min="0" step="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fuel Level</label>
                        <select name="fuel_level" class="form-control" required>
                            <?php foreach ($fuelLevels as $level): ?>
                            <option value="<?php echo $level; ?>"><?php echo ucfirst($level); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Notes</label>
                        <textarea name="check_out_notes" class="form-control" rows="2" placeholder="Any notes about vehicle condition..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('checkOutModal')">Cancel</button>
                <button type="submit" name="check_out" class="btn-primary">Check-out Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- Check-in Modal -->
<div class="modal" id="checkInModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Check-in Vehicle</h3>
            <button class="modal-close" onclick="closeModal('checkInModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="checkin_booking_id" value="0">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--bg-gray); border-radius: var(--radius-sm);">
                    <div><strong id="checkin_vehicle"></strong></div>
                    <div style="font-size: 0.75rem; color: var(--text-light);" id="checkin_customer"></div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Odometer Reading (km)</label>
                        <input type="number" name="odometer_reading" class="form-control" min="0" step="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fuel Level</label>
                        <select name="fuel_level" class="form-control" required>
                            <?php foreach ($fuelLevels as $level): ?>
                            <option value="<?php echo $level; ?>"><?php echo ucfirst($level); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Charges</label>
                        <input type="number" name="additional_charges" class="form-control" value="0" min="0" step="1000">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Damage Notes</label>
                        <textarea name="damage_notes" class="form-control" rows="2" placeholder="Any damage or issues..."></textarea>
                    </div>
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.75rem; margin: 0;">
                        <i class="bi bi-info-circle"></i> Extra km charges will be calculated automatically based on free km allowance.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('checkInModal')">Cancel</button>
                <button type="submit" name="check_in" class="btn-primary">Check-in Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- Status Update Modal -->
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
                        <option value="customer_request">Customer requested</option>
                        <option value="payment_failed">Payment failed</option>
                        <option value="vehicle_unavailable">Vehicle unavailable</option>
                        <option value="no_show">No show</option>
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

<!-- Message Customer Modal -->
<div class="modal" id="messageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Message Customer</h3>
            <button class="modal-close" onclick="closeModal('messageModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="message_booking_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <select name="subject" class="form-control">
                        <option value="Vehicle ready for pickup">Vehicle ready for pickup</option>
                        <option value="Return reminder">Return reminder</option>
                        <option value="Payment confirmation">Payment confirmation</option>
                        <option value="Question about your booking">Question about your booking</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="4" required 
                              placeholder="Write your message to the customer..."></textarea>
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

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// CHECK-OUT/IN FUNCTIONS
// ============================================
function openCheckOutModal(booking) {
    document.getElementById('checkout_booking_id').value = booking.booking_id;
    document.getElementById('checkout_vehicle').textContent = booking.brand + ' ' + booking.model;
    document.getElementById('checkout_customer').textContent = booking.customer_first_name + ' ' + (booking.customer_last_name || '');
    openModal('checkOutModal');
}

function openCheckInModal(booking) {
    document.getElementById('checkin_booking_id').value = booking.booking_id;
    document.getElementById('checkin_vehicle').textContent = booking.brand + ' ' + booking.model;
    document.getElementById('checkin_customer').textContent = booking.customer_first_name + ' ' + (booking.customer_last_name || '');
    openModal('checkInModal');
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

// ============================================
// MESSAGE FUNCTIONS
// ============================================
function messageCustomer(bookingId) {
    document.getElementById('message_booking_id').value = bookingId;
    openModal('messageModal');
}

// ============================================
// VIEW BOOKING DETAILS
// ============================================
function viewBooking(bookingId) {
    // This would open a detailed view modal
    alert('View details for booking #' + bookingId + ' (coming soon)');
}

// ============================================
// EXPORT FUNCTION
// ============================================
function exportBookings() {
    // Create CSV content
    let csv = "Reference,Customer,Email,Vehicle,Company,Pickup Date,Return Date,Days,Status,Amount\n";
    
    <?php foreach ($bookings as $booking): ?>
    csv += "#<?php echo $booking['booking_reference']; ?>,<?php echo $booking['customer_first_name'] . ' ' . $booking['customer_last_name']; ?>,<?php echo $booking['customer_email']; ?>,<?php echo $booking['brand'] . ' ' . $booking['model']; ?>,<?php echo $booking['company_name']; ?>,<?php echo $booking['pickup_date']; ?>,<?php echo $booking['return_date']; ?>,<?php echo $booking['rental_days']; ?>,<?php echo $booking['status']; ?>,<?php echo $booking['total_amount']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'car_bookings_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>