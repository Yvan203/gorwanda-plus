<?php
$pageTitle = 'Return Management';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// HANDLE RETURN ACTIONS
// ============================================

// Process return (check-in)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_return'])) {
    $bookingId = intval($_POST['booking_id']);
    $odometerReading = intval($_POST['odometer_reading'] ?? 0);
    $fuelLevel = sanitize($_POST['fuel_level'] ?? 'full');
    $damageNotes = sanitize($_POST['damage_notes'] ?? '');
    $additionalCharges = floatval($_POST['additional_charges'] ?? 0);
    $chargeReason = sanitize($_POST['charge_reason'] ?? '');
    
    // Handle damage photos
    $photoPaths = [];
    if (isset($_FILES['damage_photos']) && !empty($_FILES['damage_photos']['name'][0])) {
        $uploadDir = dirname(__DIR__, 3) . '/assets/images/damage/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $files = $_FILES['damage_photos'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileExt = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $fileName = 'damage_' . $bookingId . '_' . time() . '_' . $i . '.' . $fileExt;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                    $photoPaths[] = $fileName;
                }
            }
        }
    }
    
    // Get original booking to calculate extra km charges
    $stmt = $db->prepare("
        SELECT b.*, cf.daily_rate, cf.excess_km_charge, cf.free_km_per_day,
               cf.brand, cf.model
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        WHERE b.booking_id = ? AND b.status = 'checked_out'
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Calculate extra km charges if applicable
        $extraKmCharge = 0;
        $kmDriven = 0;
        $extraKm = 0;
        
        if ($odometerReading > 0 && $booking['odometer_out'] > 0) {
            $kmDriven = $odometerReading - $booking['odometer_out'];
            $allowedKm = $booking['free_km_per_day'] * $booking['num_nights'];
            $extraKm = max(0, $kmDriven - $allowedKm);
            $extraKmCharge = $extraKm * $booking['excess_km_charge'];
        }
        
        // Calculate rental days
        $pickup = new DateTime($booking['pickup_date'] . ' ' . ($booking['pickup_time'] ?? '10:00:00'));
        $return = new DateTime();
        $rentalDays = ceil($pickup->diff($return)->days) ?: 1;
        
        // Update booking
        $stmt = $db->prepare("
            UPDATE bookings 
            SET status = 'completed',
                actual_return_date = NOW(),
                odometer_in = ?,
                fuel_level_in = ?,
                damage_notes = ?,
                damage_photos = ?,
                additional_charges = ?,
                extra_km_charge = ?,
                total_amount = total_amount + ? + ?,
                check_in_notes = ?,
                updated_at = NOW()
            WHERE booking_id = ?
        ");
        
        $totalExtras = $additionalCharges + $extraKmCharge;
        $stmt->execute([
            $odometerReading,
            $fuelLevel,
            $damageNotes,
            !empty($photoPaths) ? json_encode($photoPaths) : null,
            $additionalCharges,
            $extraKmCharge,
            $additionalCharges,
            $extraKmCharge,
            $chargeReason,
            $bookingId
        ]);
        
        // Prepare success message
        $success = "Vehicle returned successfully";
        $details = [];
        if ($kmDriven > 0) {
            $details[] = "Distance driven: " . number_format($kmDriven) . " km";
        }
        if ($extraKm > 0) {
            $details[] = "Extra km: $extraKm (charged: " . formatPrice($extraKmCharge) . ")";
        }
        if ($additionalCharges > 0) {
            $details[] = "Additional charges: " . formatPrice($additionalCharges);
        }
        if (!empty($details)) {
            $success .= " • " . implode(" • ", $details);
        }
    }
}

// Waive extra charges
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waive_charges'])) {
    $bookingId = intval($_POST['booking_id']);
    $waiveReason = sanitize($_POST['waive_reason'] ?? 'Goodwill');
    
    $stmt = $db->prepare("
        UPDATE bookings 
        SET extra_km_charge = 0,
            additional_charges = 0,
            total_amount = total_amount - extra_km_charge - additional_charges,
            check_in_notes = CONCAT(IFNULL(check_in_notes, ''), ' Charges waived: ', ?),
            updated_at = NOW()
        WHERE booking_id = ?
    ");
    $stmt->execute([$waiveReason, $bookingId]);
    
    $success = "All extra charges waived successfully";
}

// Send invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invoice'])) {
    $bookingId = intval($_POST['booking_id']);
    
    // Get booking details for invoice
    $stmt = $db->prepare("
        SELECT b.*, u.first_name, u.last_name, u.email,
               cf.brand, cf.model, cf.license_plate
        FROM bookings b
        JOIN users u ON b.user_id = u.user_id
        JOIN car_fleet cf ON b.car_id = cf.car_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // In a real application, you would generate PDF and send email
        // For now, we'll just mark as sent
        $success = "Invoice sent to " . $booking['email'];
    }
}

// ============================================
// GET FILTERS
// ============================================
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$view = isset($_GET['view']) ? sanitize($_GET['view']) : 'today';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;

// Build query conditions
$conditions = ["cr.owner_id = ?", "b.status IN ('checked_out', 'completed')"];
$params = [$userId];

if ($vehicleId > 0) {
    $conditions[] = "cf.car_id = ?";
    $params[] = $vehicleId;
}

if ($view === 'today') {
    $conditions[] = "DATE(b.return_date) = CURDATE()";
} elseif ($view === 'tomorrow') {
    $conditions[] = "DATE(b.return_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
} elseif ($view === 'week') {
    $conditions[] = "b.return_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($view === 'overdue') {
    $conditions[] = "b.return_date < NOW() AND b.status = 'checked_out'";
} elseif ($view === 'completed') {
    $conditions[] = "b.status = 'completed'";
} elseif ($view === 'date' && $date) {
    $conditions[] = "DATE(b.return_date) = ?";
    $params[] = $date;
}

if ($status !== 'all') {
    if ($status === 'pending') {
        $conditions[] = "b.status = 'checked_out'";
    } elseif ($status === 'completed') {
        $conditions[] = "b.status = 'completed'";
    } elseif ($status === 'overdue') {
        $conditions[] = "b.return_date < NOW() AND b.status = 'checked_out'";
    }
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET RETURNS DATA
// ============================================

// Get all vehicles for filter
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// Get returns - FIXED (moved free_km_per_day to cf alias)
$stmt = $db->prepare("
    SELECT 
        b.*,
        cf.brand,
        cf.model,
        cf.car_type,
        cf.license_plate,
        cf.color,
        cf.free_km_per_day,
        cf.excess_km_charge,
        cr.company_name,
        u.user_id as customer_id,
        u.first_name as customer_first_name,
        u.last_name as customer_last_name,
        u.email as customer_email,
        u.phone as customer_phone,
        DATEDIFF(b.return_date, b.pickup_date) as expected_days,
        DATEDIFF(NOW(), b.pickup_date) as actual_days,
        TIMESTAMPDIFF(HOUR, b.return_date, NOW()) as hours_overdue,
        CASE 
            WHEN b.return_date < NOW() AND b.status = 'checked_out' THEN 'overdue'
            WHEN DATE(b.return_date) = CURDATE() THEN 'today'
            WHEN DATE(b.return_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'tomorrow'
            WHEN b.status = 'completed' THEN 'completed'
            ELSE 'upcoming'
        END as return_status,
        CASE 
            WHEN b.odometer_out > 0 AND b.odometer_in > 0 THEN b.odometer_in - b.odometer_out
            ELSE NULL
        END as km_driven,
        CASE 
            WHEN b.odometer_out > 0 AND b.odometer_in > 0 AND cf.free_km_per_day > 0 
            THEN (b.odometer_in - b.odometer_out) - (cf.free_km_per_day * DATEDIFF(b.return_date, b.pickup_date))
            ELSE 0
        END as extra_km
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN b.return_date < NOW() AND b.status = 'checked_out' THEN 1
            WHEN DATE(b.return_date) = CURDATE() THEN 2
            WHEN b.status = 'completed' THEN 3
            ELSE 4
        END,
        b.return_date ASC,
        b.return_time ASC
");

$stmt->execute($params);
$returns = $stmt->fetchAll();

// Get statistics
$stats = [
    'due_today' => 0,
    'due_tomorrow' => 0,
    'overdue' => 0,
    'completed' => 0,
    'total_revenue' => 0,
    'extra_charges' => 0,
    'avg_km' => 0,
    'total_km' => 0
];

$totalKm = 0;
$kmCount = 0;

foreach ($returns as $return) {
    if ($return['status'] === 'completed') {
        $stats['completed']++;
        $stats['total_revenue'] += $return['total_amount'];
        $stats['extra_charges'] += ($return['additional_charges'] + $return['extra_km_charge']);
        
        if ($return['km_driven'] > 0) {
            $totalKm += $return['km_driven'];
            $kmCount++;
        }
    } elseif ($return['return_status'] === 'today') {
        $stats['due_today']++;
    } elseif ($return['return_status'] === 'tomorrow') {
        $stats['due_tomorrow']++;
    } elseif ($return['return_status'] === 'overdue') {
        $stats['overdue']++;
    }
}

$stats['avg_km'] = $kmCount > 0 ? round($totalKm / $kmCount) : 0;
$stats['total_km'] = $totalKm;

// Fuel level options
$fuelLevels = ['full', '3/4', '1/2', '1/4', 'empty'];
?>

<style>
/* Returns Specific Styles */
.returns-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.returns-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.returns-title p {
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

.stat-footer {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-gray);
}

/* Date Navigation */
.date-nav {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}

.date-links {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.date-link {
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
}

.date-link:hover {
    background: var(--cars-light);
    border-color: var(--cars-primary);
    color: var(--cars-primary);
}

.date-link.active {
    background: var(--cars-primary);
    border-color: var(--cars-primary);
    color: white;
}

.date-picker {
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-picker input {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
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
    color: var(--text-light);
    white-space: nowrap;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

/* Returns Grid */
.returns-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.return-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.return-card:hover {
    box-shadow: var(--shadow-md);
}

.return-card.overdue {
    border-left: 4px solid var(--cars-danger);
}

.return-card.today {
    border-left: 4px solid var(--cars-primary);
}

.return-card.tomorrow {
    border-left: 4px solid var(--cars-warning);
}

.return-card.completed {
    opacity: 0.9;
    border-left: 4px solid var(--cars-success);
}

.return-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.return-time {
    display: flex;
    align-items: center;
    gap: 12px;
}

.time-badge {
    width: 60px;
    height: 60px;
    background: var(--cars-primary);
    color: white;
    border-radius: var(--radius-md);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.time-badge.overdue {
    background: var(--cars-danger);
}

.time-badge.today {
    background: var(--cars-primary);
}

.time-badge.completed {
    background: var(--cars-success);
}

.time-badge .hour {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.2;
}

.time-badge .ampm {
    font-size: 0.625rem;
    opacity: 0.9;
}

.time-info {
    display: flex;
    flex-direction: column;
}

.time-info .date {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 2px;
}

.time-info .status-text {
    font-size: 0.6875rem;
    color: var(--text-light);
    display: flex;
    align-items: center;
    gap: 4px;
}

.return-status-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.status-today { background: var(--cars-light); color: var(--cars-primary); }
.status-tomorrow { background: #fff4e6; color: var(--cars-warning); }
.status-overdue { background: #fce8e8; color: var(--cars-danger); }
.status-completed { background: #e6f4ea; color: var(--cars-success); }

.return-body {
    padding: 16px;
}

.customer-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.customer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--cars-light);
    color: var(--cars-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
}

.customer-details h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.customer-details p {
    font-size: 0.75rem;
    color: var(--text-light);
    margin: 2px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.customer-details i {
    width: 14px;
    color: var(--cars-primary);
}

.vehicle-info {
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.vehicle-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    color: var(--cars-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.vehicle-details {
    flex: 1;
}

.vehicle-details h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.vehicle-details p {
    font-size: 0.6875rem;
    color: var(--text-light);
}

.vehicle-stats {
    display: flex;
    gap: 16px;
    font-size: 0.75rem;
}

.vehicle-stats span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.rental-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.summary-item {
    display: flex;
    flex-direction: column;
}

.summary-label {
    font-size: 0.625rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.summary-value {
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--text-dark);
}

.summary-value.positive {
    color: var(--cars-success);
}

.summary-value.negative {
    color: var(--cars-danger);
}

.km-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 8px 12px;
    background: #fff4e6;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.km-allowed {
    color: var(--text-light);
}

.km-used {
    font-weight: 600;
}

.km-extra {
    color: var(--cars-danger);
    font-weight: 700;
}

.return-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-gray);
}

.action-btn {
    flex: 1;
    padding: 10px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--text-dark);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--cars-light);
    border-color: var(--cars-primary);
    color: var(--cars-primary);
}

.action-btn.primary {
    background: var(--cars-primary);
    color: white;
    border-color: var(--cars-primary);
}

.action-btn.primary:hover {
    background: var(--cars-dark);
}

.action-btn.success {
    background: var(--cars-success);
    color: white;
    border-color: var(--cars-success);
}

.action-btn.success:hover {
    background: #006600;
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--cars-warning);
    color: var(--cars-warning);
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--cars-danger);
    color: var(--cars-danger);
}

/* Check-in Modal */
.odometer-display {
    font-size: 2rem;
    font-weight: 700;
    color: var(--cars-primary);
    text-align: center;
    margin: 10px 0;
}

.charge-breakdown {
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-top: 16px;
}

.charge-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.8125rem;
}

.charge-row.total {
    border-top: 1px solid var(--border-gray);
    margin-top: 8px;
    padding-top: 8px;
    font-weight: 700;
    color: var(--cars-success);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .returns-grid {
        grid-template-columns: 1fr;
    }
    
    .date-nav {
        flex-direction: column;
        align-items: stretch;
    }
    
    .date-links {
        justify-content: center;
    }
    
    .date-picker {
        flex-direction: column;
    }
    
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .return-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .return-actions {
        flex-direction: column;
    }
}
</style>

<div class="returns-header">
    <div class="returns-title">
        <h1>Return Management</h1>
        <p>Process vehicle returns, assess damage, and finalize rentals</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportReturns()">
            <i class="bi bi-download"></i> Export
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['due_today']; ?></div>
        <div class="stat-label">Due Today</div>
        <div class="stat-footer"><?php echo $stats['due_tomorrow']; ?> tomorrow</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['overdue']; ?></div>
        <div class="stat-label">Overdue</div>
        <div class="stat-footer">Need immediate attention</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['completed']; ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-footer">This period</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_km']); ?> km</div>
        <div class="stat-label">Total Distance</div>
        <div class="stat-footer">Avg: <?php echo number_format($stats['avg_km']); ?> km/rental</div>
    </div>
</div>

<!-- Date Navigation -->
<div class="date-nav">
    <div class="date-links">
        <a href="?view=today" class="date-link <?php echo $view == 'today' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-day"></i> Due Today
        </a>
        <a href="?view=tomorrow" class="date-link <?php echo $view == 'tomorrow' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-plus"></i> Due Tomorrow
        </a>
        <a href="?view=week" class="date-link <?php echo $view == 'week' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-week"></i> Next 7 Days
        </a>
        <a href="?view=overdue" class="date-link <?php echo $view == 'overdue' ? 'active' : ''; ?>">
            <i class="bi bi-exclamation-triangle"></i> Overdue
        </a>
        <a href="?view=completed" class="date-link <?php echo $view == 'completed' ? 'active' : ''; ?>">
            <i class="bi bi-check-circle"></i> Completed
        </a>
    </div>
    
    <div class="date-picker">
        <input type="date" id="customDate" value="<?php echo $date; ?>">
        <button class="btn-secondary btn-sm" onclick="goToDate()">Go</button>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-group">
        <label>Vehicle</label>
        <select class="filter-select" onchange="filterByVehicle(this.value)">
            <option value="0">All Vehicles</option>
            <?php foreach ($vehicles as $v): ?>
            <option value="<?php echo $v['car_id']; ?>" <?php echo $vehicleId == $v['car_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Status</label>
        <select class="filter-select" onchange="filterByStatus(this.value)">
            <option value="all">All</option>
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending Return</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="overdue" <?php echo $status == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
        </select>
    </div>
    
    <?php if ($vehicleId || $status != 'all'): ?>
    <a href="returns.php?view=<?php echo $view; ?>" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Returns Grid -->
<?php if (empty($returns)): ?>
<div class="empty-state">
    <i class="bi bi-calendar-check"></i>
    <h3>No returns found</h3>
    <p>There are no vehicle returns scheduled for the selected period.</p>
    <a href="bookings.php" class="btn-primary">View All Bookings</a>
</div>
<?php else: ?>
<div class="returns-grid">
    <?php foreach ($returns as $return): 
        $returnTime = strtotime($return['return_date'] . ' ' . ($return['return_time'] ?? '10:00:00'));
        $isOverdue = $return['return_status'] === 'overdue';
        $isToday = $return['return_status'] === 'today';
        $isCompleted = $return['status'] === 'completed';
        
        $badgeClass = 'return-status-badge status-' . $return['return_status'];
        $timeBadgeClass = 'time-badge';
        if ($isOverdue) $timeBadgeClass .= ' overdue';
        elseif ($isToday) $timeBadgeClass .= ' today';
        elseif ($isCompleted) $timeBadgeClass .= ' completed';
    ?>
    <div class="return-card <?php echo $return['return_status']; ?>">
        <div class="return-header">
            <div class="return-time">
                <div class="<?php echo $timeBadgeClass; ?>">
                    <div class="hour"><?php echo date('h', $returnTime); ?></div>
                    <div class="ampm"><?php echo date('A', $returnTime); ?></div>
                </div>
                <div class="time-info">
                    <div class="date"><?php echo date('l, M d, Y', strtotime($return['return_date'])); ?></div>
                    <div class="status-text">
                        <?php if ($isOverdue): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                            Overdue by <?php echo abs($return['hours_overdue']); ?> hours
                        <?php elseif ($isToday): ?>
                            <i class="bi bi-clock-fill text-primary"></i>
                            Due today
                        <?php elseif ($isCompleted): ?>
                            <i class="bi bi-check-circle-fill text-success"></i>
                            Returned on <?php echo date('M d', strtotime($return['actual_return_date'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <span class="<?php echo $badgeClass; ?>">
                <?php echo ucfirst($return['return_status']); ?>
            </span>
        </div>
        
        <div class="return-body">
            <div class="customer-info">
                <div class="customer-avatar">
                    <?php echo strtoupper(substr($return['customer_first_name'] ?? 'G', 0, 1)); ?>
                </div>
                <div class="customer-details">
                    <h4><?php echo sanitize($return['customer_first_name'] . ' ' . $return['customer_last_name']); ?></h4>
                    <p><i class="bi bi-envelope"></i> <?php echo sanitize($return['customer_email'] ?? 'No email'); ?></p>
                    <p><i class="bi bi-telephone"></i> <?php echo sanitize($return['customer_phone'] ?? 'No phone'); ?></p>
                </div>
            </div>
            
            <div class="vehicle-info">
                <div class="vehicle-icon">
                    <i class="bi bi-car-front"></i>
                </div>
                <div class="vehicle-details">
                    <h4><?php echo sanitize($return['brand'] . ' ' . $return['model']); ?></h4>
                    <p><?php echo ucfirst($return['car_type']); ?> • <?php echo sanitize($return['company_name']); ?></p>
                    <?php if ($return['license_plate']): ?>
                    <p><i class="bi bi-upc-scan"></i> <?php echo $return['license_plate']; ?></p>
                    <?php endif; ?>
                </div>
                <div class="vehicle-stats">
                    <span><i class="bi bi-speedometer2"></i> <?php echo number_format($return['odometer_out'] ?? 0); ?> km</span>
                </div>
            </div>
            
            <div class="rental-summary">
                <div class="summary-item">
                    <span class="summary-label">Pickup</span>
                    <span class="summary-value"><?php echo date('M d', strtotime($return['pickup_date'])); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Expected Return</span>
                    <span class="summary-value"><?php echo date('M d', strtotime($return['return_date'])); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Duration</span>
                    <span class="summary-value"><?php echo $return['expected_days']; ?> days</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value <?php echo $return['status'] === 'completed' ? 'positive' : ''; ?>">
                        <?php echo formatPrice($return['total_amount']); ?>
                    </span>
                </div>
            </div>
            
            <?php if ($return['status'] === 'checked_out'): ?>
                <?php if ($return['extra_km'] > 0): ?>
                <div class="km-summary">
                    <span class="km-allowed">Free km: <?php echo $return['free_km_per_day'] * $return['expected_days']; ?> km</span>
                    <span class="km-used">Est. used: <?php echo number_format($return['km_driven'] ?? 0); ?> km</span>
                    <span class="km-extra">Extra: <?php echo $return['extra_km']; ?> km</span>
                </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($return['status'] === 'completed' && ($return['additional_charges'] > 0 || $return['extra_km_charge'] > 0)): ?>
            <div class="km-summary" style="background: #e6f4ea;">
                <span>Additional charges:</span>
                <span class="km-extra" style="color: var(--cars-success);">
                    +<?php echo formatPrice($return['additional_charges'] + $return['extra_km_charge']); ?>
                </span>
            </div>
            <?php endif; ?>
            
            <?php if ($return['damage_notes']): ?>
            <div style="margin-bottom: 12px; padding: 8px; background: #fce8e8; border-radius: var(--radius-sm); font-size: 0.75rem;">
                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                <strong>Damage:</strong> <?php echo sanitize($return['damage_notes']); ?>
            </div>
            <?php endif; ?>
            
            <div class="return-actions">
                <?php if ($return['status'] === 'checked_out'): ?>
                    <button class="action-btn primary" onclick="processReturn(<?php echo htmlspecialchars(json_encode($return)); ?>)">
                        <i class="bi bi-arrow-return-left"></i> Process Return
                    </button>
                    
                    <?php if ($return['extra_km'] > 0): ?>
                    <button class="action-btn warning" onclick="waiveCharges(<?php echo $return['booking_id']; ?>)">
                        <i class="bi bi-gift"></i> Waive Extras
                    </button>
                    <?php endif; ?>
                    
                <?php elseif ($return['status'] === 'completed'): ?>
                    <button class="action-btn success" onclick="viewInvoice(<?php echo $return['booking_id']; ?>)">
                        <i class="bi bi-file-pdf"></i> Invoice
                    </button>
                    
                    <button class="action-btn" onclick="sendInvoice(<?php echo $return['booking_id']; ?>)">
                        <i class="bi bi-envelope"></i> Email Invoice
                    </button>
                <?php endif; ?>
                
                <button class="action-btn" onclick="viewDetails(<?php echo $return['booking_id']; ?>)">
                    <i class="bi bi-eye"></i> Details
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Process Return Modal -->
<div class="modal" id="returnModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Process Vehicle Return</h3>
            <button class="modal-close" onclick="closeModal('returnModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="returnForm">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="return_booking_id" value="0">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--bg-gray); border-radius: var(--radius-sm);">
                    <div><strong id="return_vehicle"></strong></div>
                    <div style="font-size: 0.75rem; color: var(--text-light);" id="return_customer"></div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Odometer Reading (km) <span class="required">*</span></label>
                        <input type="number" name="odometer_reading" id="odometer_reading" class="form-control" min="0" step="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fuel Level</label>
                        <select name="fuel_level" class="form-control">
                            <?php foreach ($fuelLevels as $level): ?>
                            <option value="<?php echo $level; ?>"><?php echo ucfirst($level); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Damage Photos</label>
                        <div class="damage-photo-upload" onclick="document.getElementById('damage_photos').click()">
                            <i class="bi bi-camera"></i>
                            <p>Click to upload photos of any damage</p>
                            <input type="file" name="damage_photos[]" id="damage_photos" multiple accept="image/*" style="display: none;">
                        </div>
                        <div class="damage-photo-preview" id="damagePreview"></div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Damage Notes</label>
                        <textarea name="damage_notes" class="form-control" rows="2" placeholder="Describe any damage to the vehicle..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Charges (RWF)</label>
                        <input type="number" name="additional_charges" id="additional_charges" class="form-control" value="0" min="0" step="1000">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Charge Reason</label>
                        <input type="text" name="charge_reason" class="form-control" placeholder="e.g., Late return, cleaning fee">
                    </div>
                </div>
                
                <div id="kmCalculation" style="background: #fff4e6; padding: 16px; border-radius: var(--radius-sm); margin-top: 16px; display: none;">
                    <h4 style="font-size: 0.875rem; margin-bottom: 10px;">Extra KM Calculation</h4>
                    <div class="charge-row">
                        <span>Pickup Odometer:</span>
                        <span id="pickup_odometer">0</span>
                    </div>
                    <div class="charge-row">
                        <span>Current Reading:</span>
                        <span id="current_odometer">0</span>
                    </div>
                    <div class="charge-row">
                        <span>Distance Driven:</span>
                        <span id="distance_driven">0 km</span>
                    </div>
                    <div class="charge-row">
                        <span>Free KM Allowed:</span>
                        <span id="free_km">0 km</span>
                    </div>
                    <div class="charge-row">
                        <span>Extra KM:</span>
                        <span id="extra_km">0 km</span>
                    </div>
                    <div class="charge-row total">
                        <span>Extra KM Charge:</span>
                        <span id="extra_charge">RWF 0</span>
                    </div>
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.75rem; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="bi bi-info-circle"></i>
                        By completing this return, you confirm that the vehicle has been inspected and all charges are final.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('returnModal')">Cancel</button>
                <button type="submit" name="process_return" class="btn-primary">Complete Return</button>
            </div>
        </form>
    </div>
</div>

<!-- Waive Charges Modal -->
<div class="modal" id="waiveModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Waive Extra Charges</h3>
            <button class="modal-close" onclick="closeModal('waiveModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="waive_booking_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Reason for Waiving</label>
                    <select name="waive_reason" class="form-control">
                        <option value="Goodwill">Goodwill Gesture</option>
                        <option value="Regular Customer">Regular Customer</option>
                        <option value="Technical Issue">Technical Issue</option>
                        <option value="Delay by Staff">Delay by Staff</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <p style="font-size: 0.75rem; margin: 0;">
                        <i class="bi bi-info-circle"></i>
                        This will set all extra charges to zero. This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('waiveModal')">Cancel</button>
                <button type="submit" name="waive_charges" class="btn-warning">Waive Charges</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function goToDate() {
    const date = document.getElementById('customDate').value;
    if (date) {
        window.location.href = 'returns.php?view=date&date=' + date;
    }
}

function filterByVehicle(vehicleId) {
    const url = new URL(window.location.href);
    url.searchParams.set('vehicle', vehicleId);
    window.location.href = url.toString();
}

function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    window.location.href = url.toString();
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
// RETURN FUNCTIONS
// ============================================
function processReturn(returnData) {
    document.getElementById('return_booking_id').value = returnData.booking_id;
    document.getElementById('return_vehicle').textContent = returnData.brand + ' ' + returnData.model;
    document.getElementById('return_customer').textContent = returnData.customer_first_name + ' ' + (returnData.customer_last_name || '');
    
    // Store data for KM calculation
    window.currentReturnData = returnData;
    
    // Show KM calculation if applicable
    document.getElementById('kmCalculation').style.display = 'block';
    document.getElementById('pickup_odometer').textContent = returnData.odometer_out || 0;
    document.getElementById('free_km').textContent = (returnData.free_km_per_day * returnData.expected_days) + ' km';
    
    // Add event listener for odometer input
    document.getElementById('odometer_reading').addEventListener('input', calculateExtraKm);
    
    openModal('returnModal');
}

function calculateExtraKm() {
    const currentReading = parseInt(document.getElementById('odometer_reading').value) || 0;
    const pickupReading = window.currentReturnData?.odometer_out || 0;
    const freeKmPerDay = window.currentReturnData?.free_km_per_day || 0;
    const days = window.currentReturnData?.expected_days || 1;
    
    const distanceDriven = Math.max(0, currentReading - pickupReading);
    const freeKm = freeKmPerDay * days;
    const extraKm = Math.max(0, distanceDriven - freeKm);
    const extraCharge = extraKm * (window.currentReturnData?.excess_km_charge || 0);
    
    document.getElementById('current_odometer').textContent = currentReading;
    document.getElementById('distance_driven').textContent = distanceDriven + ' km';
    document.getElementById('extra_km').textContent = extraKm + ' km';
    document.getElementById('extra_charge').textContent = formatCurrency(extraCharge);
}

function waiveCharges(bookingId) {
    document.getElementById('waive_booking_id').value = bookingId;
    openModal('waiveModal');
}

function viewInvoice(bookingId) {
    window.location.href = 'invoice.php?booking=' + bookingId;
}

function sendInvoice(bookingId) {
    if (confirm('Send invoice to customer?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="booking_id" value="${bookingId}"><input type="hidden" name="send_invoice" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewDetails(bookingId) {
    window.location.href = 'bookings.php?booking=' + bookingId;
}

// ============================================
// DAMAGE PHOTO PREVIEW
// ============================================
document.getElementById('damage_photos')?.addEventListener('change', function() {
    const preview = document.getElementById('damagePreview');
    preview.innerHTML = '';
    
    for (let i = 0; i < this.files.length; i++) {
        const file = this.files[i];
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML += `
                <div class="preview-item">
                    <img src="${e.target.result}" alt="Damage photo">
                </div>
            `;
        }
        
        reader.readAsDataURL(file);
    }
});

// ============================================
// EXPORT FUNCTION
// ============================================
function exportReturns() {
    // Create CSV content
    let csv = "Return Date,Time,Customer,Email,Vehicle,License Plate,Pickup Date,Days,Status,Amount\n";
    
    <?php foreach ($returns as $return): ?>
    csv += "<?php echo $return['return_date']; ?>,<?php echo $return['return_time'] ?? '10:00'; ?>,<?php echo $return['customer_first_name'] . ' ' . $return['customer_last_name']; ?>,<?php echo $return['customer_email']; ?>,<?php echo $return['brand'] . ' ' . $return['model']; ?>,<?php echo $return['license_plate'] ?? ''; ?>,<?php echo $return['pickup_date']; ?>,<?php echo $return['expected_days']; ?>,<?php echo $return['status']; ?>,<?php echo $return['total_amount']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'returns_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>