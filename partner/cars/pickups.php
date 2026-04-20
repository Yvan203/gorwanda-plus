<?php
$pageTitle = 'Pickup Management';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// HANDLE PICKUP ACTIONS
// ============================================

// Process pickup (check-out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_pickup'])) {
    $bookingId = intval($_POST['booking_id']);
    $odometerReading = intval($_POST['odometer_reading'] ?? 0);
    $fuelLevel = sanitize($_POST['fuel_level'] ?? 'full');
    $notes = sanitize($_POST['check_out_notes'] ?? '');
    $damagePhotos = isset($_FILES['damage_photos']) ? $_FILES['damage_photos'] : null;
    
    // Verify ownership and get booking details
    $stmt = $db->prepare("
        SELECT b.*, cf.brand, cf.model, cf.quantity_available, u.first_name, u.last_name, u.email, u.phone
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.booking_id = ? AND cr.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Handle damage photos if any
        $photoPaths = [];
        if ($damagePhotos && !empty($damagePhotos['name'][0])) {
            $uploadDir = dirname(__DIR__, 3) . '/assets/images/damage/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            for ($i = 0; $i < count($damagePhotos['name']); $i++) {
                if ($damagePhotos['error'][$i] === UPLOAD_ERR_OK) {
                    $fileExt = pathinfo($damagePhotos['name'][$i], PATHINFO_EXTENSION);
                    $fileName = 'damage_' . $bookingId . '_' . time() . '_' . $i . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($damagePhotos['tmp_name'][$i], $uploadPath)) {
                        $photoPaths[] = $fileName;
                    }
                }
            }
        }
        
        // Update booking status to checked_out
        $stmt = $db->prepare("
            UPDATE bookings 
            SET status = 'checked_out', 
                odometer_out = ?,
                fuel_level_out = ?,
                check_out_notes = ?,
                damage_photos = ?,
                actual_pickup_date = NOW(),
                updated_at = NOW()
            WHERE booking_id = ?
        ");
        $stmt->execute([
            $odometerReading,
            $fuelLevel,
            $notes,
            !empty($photoPaths) ? json_encode($photoPaths) : null,
            $bookingId
        ]);
        
        $success = "Vehicle checked out successfully to " . $booking['first_name'] . ' ' . $booking['last_name'];
    } else {
        $error = "Booking not found or cannot be processed";
    }
}

// Reschedule pickup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule_pickup'])) {
    $bookingId = intval($_POST['booking_id']);
    $newPickupDate = $_POST['new_pickup_date'];
    $newPickupTime = $_POST['new_pickup_time'];
    $reason = sanitize($_POST['reschedule_reason'] ?? '');
    
    // Verify ownership
    $stmt = $db->prepare("
        UPDATE bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET b.pickup_date = ?, 
            b.pickup_time = ?,
            b.reschedule_reason = ?,
            b.updated_at = NOW()
        WHERE b.booking_id = ? AND cr.owner_id = ? AND b.status = 'confirmed'
    ");
    $stmt->execute([$newPickupDate, $newPickupTime, $reason, $bookingId, $userId]);
    
    $success = "Pickup rescheduled successfully";
}

// Send reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $bookingId = intval($_POST['booking_id']);
    $reminderType = sanitize($_POST['reminder_type'] ?? 'pickup');
    
    // Get booking details
    $stmt = $db->prepare("
        SELECT b.*, u.first_name, u.last_name, u.email, u.phone, cf.brand, cf.model
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.booking_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    
    if ($booking) {
        // Create reminder message
        $message = "Dear " . $booking['first_name'] . ",\n\n";
        $message .= "This is a reminder for your vehicle pickup scheduled for " . date('M d, Y', strtotime($booking['pickup_date'])) . " at " . $booking['pickup_time'] . ".\n\n";
        $message .= "Vehicle: " . $booking['brand'] . " " . $booking['model'] . "\n";
        $message .= "Please bring your driver's license and payment method.\n\n";
        $message .= "Thank you for choosing our service!";
        
        // In a real application, you would send an email/SMS here
        // For now, we'll just log it
        $success = "Reminder sent to " . $booking['email'];
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
$conditions = ["cr.owner_id = ?"];
$params = [$userId];

if ($vehicleId > 0) {
    $conditions[] = "cf.car_id = ?";
    $params[] = $vehicleId;
}

if ($view === 'today') {
    $conditions[] = "DATE(b.pickup_date) = CURDATE()";
} elseif ($view === 'tomorrow') {
    $conditions[] = "DATE(b.pickup_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
} elseif ($view === 'week') {
    $conditions[] = "b.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($view === 'date' && $date) {
    $conditions[] = "DATE(b.pickup_date) = ?";
    $params[] = $date;
}

if ($status !== 'all') {
    if ($status === 'pending') {
        $conditions[] = "b.status = 'confirmed' AND b.pickup_date >= CURDATE()";
    } elseif ($status === 'completed') {
        $conditions[] = "b.status = 'checked_out'";
    } elseif ($status === 'overdue') {
        $conditions[] = "b.status = 'confirmed' AND b.pickup_date < CURDATE()";
    }
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET PICKUPS DATA
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

// Get pickups - WITH ALL DATABASE COLUMNS
$stmt = $db->prepare("
    SELECT 
        b.*,
        cf.brand,
        cf.model,
        cf.car_type,
        cf.license_plate,
        cf.color,
        cr.company_name,
        u.user_id as customer_id,
        u.first_name as customer_first_name,
        u.last_name as customer_last_name,
        u.email as customer_email,
        u.phone as customer_phone,
        DATEDIFF(b.return_date, b.pickup_date) as rental_days,
        TIMESTAMPDIFF(HOUR, CONCAT(b.pickup_date, ' ', b.pickup_time), NOW()) as hours_until,
        CONCAT(b.pickup_date, ' ', b.pickup_time) as pickup_datetime,
        CASE 
            WHEN CONCAT(b.pickup_date, ' ', b.pickup_time) < NOW() AND b.status = 'confirmed' THEN 'overdue'
            WHEN DATE(b.pickup_date) = CURDATE() THEN 'today'
            WHEN DATE(b.pickup_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'tomorrow'
            ELSE 'upcoming'
        END as pickup_status
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN CONCAT(b.pickup_date, ' ', b.pickup_time) < NOW() AND b.status = 'confirmed' THEN 1
            WHEN DATE(b.pickup_date) = CURDATE() THEN 2
            ELSE 3
        END,
        b.pickup_date ASC,
        b.pickup_time ASC
");

$stmt->execute($params);
$pickups = $stmt->fetchAll();

// Get statistics
$stats = [
    'today' => 0,
    'tomorrow' => 0,
    'week' => 0,
    'overdue' => 0,
    'completed' => 0,
    'total_customers' => 0,
    'total_vehicles' => 0
];

foreach ($pickups as $pickup) {
    if ($pickup['pickup_status'] === 'today' && $pickup['status'] === 'confirmed') {
        $stats['today']++;
    } elseif ($pickup['pickup_status'] === 'tomorrow') {
        $stats['tomorrow']++;
    } elseif ($pickup['pickup_status'] === 'upcoming') {
        $stats['week']++;
    } elseif ($pickup['pickup_status'] === 'overdue') {
        $stats['overdue']++;
    }
    
    if ($pickup['status'] === 'checked_out') {
        $stats['completed']++;
    }
}

$stats['total_customers'] = count(array_unique(array_column($pickups, 'customer_id')));
$stats['total_vehicles'] = count(array_unique(array_column($pickups, 'car_id')));

// Fuel level options
$fuelLevels = ['full', '3/4', '1/2', '1/4', 'empty'];

// Time slots for pickup
$timeSlots = [];
for ($hour = 8; $hour <= 20; $hour++) {
    $timeSlots[] = sprintf("%02d:00", $hour);
    $timeSlots[] = sprintf("%02d:30", $hour);
}
?>

<style>
/* Pickups Specific Styles */
.pickups-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.pickups-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.pickups-title p {
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

/* Quick Date Navigation */
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

/* Pickups Grid */
.pickups-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.pickup-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.pickup-card:hover {
    box-shadow: var(--shadow-md);
}

.pickup-card.overdue {
    border-left: 4px solid var(--cars-danger);
}

.pickup-card.today {
    border-left: 4px solid var(--cars-primary);
}

.pickup-card.tomorrow {
    border-left: 4px solid var(--cars-warning);
}

.pickup-card.completed {
    opacity: 0.8;
    border-left: 4px solid var(--cars-success);
}

.pickup-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pickup-time {
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

.time-info .days-until {
    font-size: 0.6875rem;
    color: var(--text-light);
}

.pickup-status-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.status-today { background: var(--cars-light); color: var(--cars-primary); }
.status-tomorrow { background: #fff4e6; color: var(--cars-warning); }
.status-overdue { background: #fce8e8; color: var(--cars-danger); }
.status-completed { background: #e6f4ea; color: var(--cars-success); }

.pickup-body {
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

.vehicle-details h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.vehicle-details p {
    font-size: 0.6875rem;
    color: var(--text-light);
}

.booking-meta {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
}

.meta-item i {
    color: var(--cars-primary);
    width: 16px;
}

.meta-label {
    color: var(--text-light);
}

.meta-value {
    font-weight: 600;
    margin-left: auto;
}

.pickup-actions {
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

/* Check-out Modal Specific */
.damage-photo-upload {
    border: 2px dashed var(--border-gray);
    border-radius: var(--radius-sm);
    padding: 20px;
    text-align: center;
    background: var(--bg-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 16px;
}

.damage-photo-upload:hover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
}

.damage-photo-upload i {
    font-size: 1.5rem;
    color: var(--text-light);
    margin-bottom: 8px;
}

.damage-photo-preview {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 10px;
}

.preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--border-gray);
}

.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-item-remove {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.625rem;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .pickups-grid {
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
    
    .pickup-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .pickup-actions {
        flex-direction: column;
    }
}
</style>

<div class="pickups-header">
    <div class="pickups-title">
        <h1>Pickup Management</h1>
        <p>Manage vehicle pickups, check-outs, and customer coordination</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportPickups()">
            <i class="bi bi-download"></i> Export List
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['today']; ?></div>
        <div class="stat-label">Today's Pickups</div>
        <div class="stat-footer"><?php echo $stats['completed']; ?> completed</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['tomorrow']; ?></div>
        <div class="stat-label">Tomorrow</div>
        <div class="stat-footer">Scheduled</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['week']; ?></div>
        <div class="stat-label">This Week</div>
        <div class="stat-footer">Upcoming pickups</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['overdue']; ?></div>
        <div class="stat-label">Overdue</div>
        <div class="stat-footer">Need attention</div>
    </div>
</div>

<!-- Date Navigation -->
<div class="date-nav">
    <div class="date-links">
        <a href="?view=today" class="date-link <?php echo $view == 'today' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-day"></i> Today
        </a>
        <a href="?view=tomorrow" class="date-link <?php echo $view == 'tomorrow' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-plus"></i> Tomorrow
        </a>
        <a href="?view=week" class="date-link <?php echo $view == 'week' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-week"></i> Next 7 Days
        </a>
        <a href="?view=all" class="date-link <?php echo $view == 'all' ? 'active' : ''; ?>">
            <i class="bi bi-calendar3"></i> All
        </a>
    </div>
    
    <div class="date-picker">
        <input type="date" id="customDate" value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>">
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
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="overdue" <?php echo $status == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
        </select>
    </div>
    
    <?php if ($vehicleId || $status != 'all'): ?>
    <a href="pickups.php?view=<?php echo $view; ?>" class="btn-secondary btn-sm">Clear Filters</a>
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

<!-- Pickups Grid -->
<?php if (empty($pickups)): ?>
<div class="empty-state">
    <i class="bi bi-calendar-x"></i>
    <h3>No pickups found</h3>
    <p>There are no vehicle pickups scheduled for the selected period.</p>
    <a href="bookings.php" class="btn-primary">View All Bookings</a>
</div>
<?php else: ?>
<div class="pickups-grid">
    <?php foreach ($pickups as $pickup): 
        $pickupTime = strtotime($pickup['pickup_date'] . ' ' . ($pickup['pickup_time'] ?? '10:00'));
        $now = time();
        $hoursUntil = round(($pickupTime - $now) / 3600);
        
        $cardClass = 'pickup-card';
        if ($pickup['status'] === 'checked_out') {
            $cardClass .= ' completed';
        } elseif ($pickup['pickup_status'] === 'overdue') {
            $cardClass .= ' overdue';
        } elseif ($pickup['pickup_status'] === 'today') {
            $cardClass .= ' today';
        } elseif ($pickup['pickup_status'] === 'tomorrow') {
            $cardClass .= ' tomorrow';
        }
    ?>
    <div class="<?php echo $cardClass; ?>">
        <div class="pickup-header">
            <div class="pickup-time">
<div class="time-badge">
    <?php 
    $pickupDateTime = strtotime($pickup['pickup_date'] . ' ' . ($pickup['pickup_time'] ?? '10:00:00'));
    ?>
    <div class="hour"><?php echo date('h', $pickupDateTime); ?></div>
    <div class="ampm"><?php echo date('A', $pickupDateTime); ?></div>
</div>
                <div class="time-info">
                    <div class="date"><?php echo date('l, M d, Y', strtotime($pickup['pickup_date'])); ?></div>
                    <div class="days-until">
                        <?php if ($pickup['status'] === 'checked_out'): ?>
                            <i class="bi bi-check-circle-fill text-success"></i> Completed
                        <?php elseif ($hoursUntil < 0): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger"></i> Overdue by <?php echo abs($hoursUntil); ?> hours
                        <?php elseif ($hoursUntil < 24): ?>
                            <i class="bi bi-clock-fill text-warning"></i> In <?php echo $hoursUntil; ?> hours
                        <?php else: ?>
                            <i class="bi bi-calendar-check"></i> In <?php echo round($hoursUntil / 24); ?> days
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <span class="pickup-status-badge status-<?php echo $pickup['pickup_status']; ?>">
                <?php echo ucfirst($pickup['pickup_status']); ?>
            </span>
        </div>
        
        <div class="pickup-body">
            <div class="customer-info">
                <div class="customer-avatar">
                    <?php echo strtoupper(substr($pickup['customer_first_name'] ?? 'G', 0, 1)); ?>
                </div>
                <div class="customer-details">
                    <h4><?php echo sanitize($pickup['customer_first_name'] . ' ' . $pickup['customer_last_name']); ?></h4>
                    <p><i class="bi bi-envelope"></i> <?php echo sanitize($pickup['customer_email'] ?? 'No email'); ?></p>
                    <p><i class="bi bi-telephone"></i> <?php echo sanitize($pickup['customer_phone'] ?? 'No phone'); ?></p>
                </div>
            </div>
            
            <div class="vehicle-info">
                <div class="vehicle-icon">
                    <i class="bi bi-car-front"></i>
                </div>
<!-- In the vehicle info section, remove license_plate -->
<div class="vehicle-details">
    <h4><?php echo sanitize($pickup['brand'] . ' ' . $pickup['model']); ?></h4>
    <p><?php echo ucfirst($pickup['car_type']); ?> • <?php echo sanitize($pickup['company_name']); ?></p>
</div>
            </div>
            
            <div class="booking-meta">
                <div class="meta-item">
                    <i class="bi bi-calendar-range"></i>
                    <span class="meta-label">Return:</span>
                    <span class="meta-value"><?php echo date('M d', strtotime($pickup['return_date'])); ?></span>
                </div>
                <div class="meta-item">
                    <i class="bi bi-clock"></i>
                    <span class="meta-label">Duration:</span>
                    <span class="meta-value"><?php echo $pickup['rental_days']; ?> days</span>
                </div>
                <div class="meta-item">
                    <i class="bi bi-cash"></i>
                    <span class="meta-label">Amount:</span>
                    <span class="meta-value"><?php echo formatPrice($pickup['total_amount']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="bi bi-people"></i>
                    <span class="meta-label">Guests:</span>
                    <span class="meta-value"><?php echo $pickup['num_guests']; ?></span>
                </div>
            </div>
            
            <div class="pickup-actions">
                <?php if ($pickup['status'] === 'confirmed'): ?>
                    <button class="action-btn primary" onclick="processPickup(<?php echo htmlspecialchars(json_encode($pickup)); ?>)">
                        <i class="bi bi-check-lg"></i> Check-out
                    </button>
                    <button class="action-btn warning" onclick="reschedulePickup(<?php echo $pickup['booking_id']; ?>)">
                        <i class="bi bi-clock"></i> Reschedule
                    </button>
                <?php elseif ($pickup['status'] === 'checked_out'): ?>
                    <a href="returns.php?booking=<?php echo $pickup['booking_id']; ?>" class="action-btn success">
                        <i class="bi bi-arrow-return-left"></i> Process Return
                    </a>
                <?php endif; ?>
                
                <button class="action-btn" onclick="viewDetails(<?php echo $pickup['booking_id']; ?>)">
                    <i class="bi bi-eye"></i> Details
                </button>
                
                <button class="action-btn" onclick="sendReminder(<?php echo $pickup['booking_id']; ?>)">
                    <i class="bi bi-bell"></i> Remind
                </button>
            </div>
            
            <?php if ($pickup['special_requests']): ?>
            <div style="margin-top: 12px; padding: 8px; background: var(--bg-gray); border-radius: var(--radius-sm); font-size: 0.75rem;">
                <i class="bi bi-chat-text"></i> <strong>Note:</strong> <?php echo sanitize($pickup['special_requests']); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Process Pickup Modal -->
<div class="modal" id="pickupModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Process Vehicle Pickup</h3>
            <button class="modal-close" onclick="closeModal('pickupModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="pickupForm">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="pickup_booking_id" value="0">
                
                <div style="margin-bottom: 20px; padding: 12px; background: var(--bg-gray); border-radius: var(--radius-sm);">
                    <div><strong id="pickup_vehicle"></strong></div>
                    <div style="font-size: 0.75rem; color: var(--text-light);" id="pickup_customer"></div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Odometer Reading (km) <span class="required">*</span></label>
                        <input type="number" name="odometer_reading" class="form-control" min="0" step="1" required>
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
                        <label class="form-label">Damage Photos (optional)</label>
                        <div class="damage-photo-upload" onclick="document.getElementById('damage_photos').click()">
                            <i class="bi bi-camera"></i>
                            <p>Click to upload photos of existing damage</p>
                            <input type="file" name="damage_photos[]" id="damage_photos" multiple accept="image/*" style="display: none;">
                        </div>
                        <div class="damage-photo-preview" id="damagePreview"></div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Check-out Notes</label>
                        <textarea name="check_out_notes" class="form-control" rows="3" placeholder="Any notes about vehicle condition, customer instructions, etc."></textarea>
                    </div>
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.75rem; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="bi bi-info-circle"></i>
                        By checking out this vehicle, you confirm that the customer has received the vehicle in good condition and all documents are signed.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('pickupModal')">Cancel</button>
                <button type="submit" name="process_pickup" class="btn-primary">Complete Pickup</button>
            </div>
        </form>
    </div>
</div>

<!-- Reschedule Modal -->
<div class="modal" id="rescheduleModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Reschedule Pickup</h3>
            <button class="modal-close" onclick="closeModal('rescheduleModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="reschedule_booking_id" value="0">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">New Pickup Date</label>
                        <input type="date" name="new_pickup_date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Pickup Time</label>
                        <select name="new_pickup_time" class="form-control" required>
                            <option value="08:00:00">8:00 AM</option>
                            <option value="09:00:00">9:00 AM</option>
                            <option value="10:00:00">10:00 AM</option>
                            <option value="11:00:00">11:00 AM</option>
                            <option value="12:00:00">12:00 PM</option>
                            <option value="13:00:00">1:00 PM</option>
                            <option value="14:00:00">2:00 PM</option>
                            <option value="15:00:00">3:00 PM</option>
                            <option value="16:00:00">4:00 PM</option>
                            <option value="17:00:00">5:00 PM</option>
                            <option value="18:00:00">6:00 PM</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason for Reschedule</label>
                    <select name="reschedule_reason" class="form-control">
                        <option value="customer_request">Customer Request</option>
                        <option value="vehicle_unavailable">Vehicle Unavailable</option>
                        <option value="staff_availability">Staff Availability</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('rescheduleModal')">Cancel</button>
                <button type="submit" name="reschedule_pickup" class="btn-warning">Reschedule</button>
            </div>
        </form>
    </div>
</div>

<!-- Reminder Modal -->
<div class="modal" id="reminderModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Send Reminder</h3>
            <button class="modal-close" onclick="closeModal('reminderModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="booking_id" id="reminder_booking_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Reminder Type</label>
                    <select name="reminder_type" class="form-control">
                        <option value="pickup">Pickup Reminder</option>
                        <option value="documents">Documents Reminder</option>
                        <option value="payment">Payment Reminder</option>
                    </select>
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <p style="font-size: 0.75rem; margin: 0;">
                        <i class="bi bi-envelope"></i> A reminder will be sent to the customer via email and SMS.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('reminderModal')">Cancel</button>
                <button type="submit" name="send_reminder" class="btn-primary">Send Reminder</button>
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
        window.location.href = 'pickups.php?view=date&date=' + date;
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
// PICKUP FUNCTIONS
// ============================================
function processPickup(pickup) {
    document.getElementById('pickup_booking_id').value = pickup.booking_id;
    document.getElementById('pickup_vehicle').textContent = pickup.brand + ' ' + pickup.model;
    document.getElementById('pickup_customer').textContent = pickup.customer_first_name + ' ' + (pickup.customer_last_name || '');
    openModal('pickupModal');
}

function reschedulePickup(bookingId) {
    document.getElementById('reschedule_booking_id').value = bookingId;
    openModal('rescheduleModal');
}

function sendReminder(bookingId) {
    document.getElementById('reminder_booking_id').value = bookingId;
    openModal('reminderModal');
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
function exportPickups() {
    // Create CSV content
    let csv = "Date,Time,Customer,Email,Phone,Vehicle,Return Date,Days,Amount,Status\n";
    
    <?php foreach ($pickups as $pickup): ?>
    csv += "<?php echo $pickup['pickup_date']; ?>,<?php echo $pickup['pickup_time'] ?? '10:00'; ?>,<?php echo $pickup['customer_first_name'] . ' ' . $pickup['customer_last_name']; ?>,<?php echo $pickup['customer_email']; ?>,<?php echo $pickup['customer_phone']; ?>,<?php echo $pickup['brand'] . ' ' . $pickup['model']; ?>,<?php echo $pickup['return_date']; ?>,<?php echo $pickup['rental_days']; ?>,<?php echo $pickup['total_amount']; ?>,<?php echo $pickup['status']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'pickups_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>