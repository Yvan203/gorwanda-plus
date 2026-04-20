<?php
$pageTitle = 'Maintenance Management';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d', strtotime('+30 days'));

// ============================================
// HANDLE MAINTENANCE ACTIONS
// ============================================

// Add maintenance record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $maintenanceType = sanitize($_POST['maintenance_type']);
    $description = sanitize($_POST['description']);
    $scheduledDate = $_POST['scheduled_date'];
    $estimatedDuration = intval($_POST['estimated_duration'] ?? 1);
    $estimatedCost = floatval($_POST['estimated_cost'] ?? 0);
    $serviceProvider = sanitize($_POST['service_provider'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $priority = sanitize($_POST['priority'] ?? 'medium');
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.car_id FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            INSERT INTO car_maintenance (
                car_id, maintenance_type, description, scheduled_date,
                estimated_duration, estimated_cost, service_provider,
                notes, priority, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW())
        ");
        $stmt->execute([
            $vehicleId, $maintenanceType, $description, $scheduledDate,
            $estimatedDuration, $estimatedCost, $serviceProvider,
            $notes, $priority
        ]);
        $success = "Maintenance record added successfully!";
    } else {
        $error = "You don't have permission to modify this vehicle";
    }
}

// Update maintenance status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $maintenanceId = intval($_POST['maintenance_id']);
    $newStatus = sanitize($_POST['status']);
    $completedDate = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
    $actualCost = $newStatus === 'completed' ? floatval($_POST['actual_cost'] ?? 0) : null;
    
    // Verify ownership
    $stmt = $db->prepare("
        UPDATE car_maintenance cm
        JOIN car_fleet cf ON cm.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET cm.status = ?, cm.completed_date = ?, cm.actual_cost = ?
        WHERE cm.maintenance_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$newStatus, $completedDate, $actualCost, $maintenanceId, $userId]);
    $success = "Maintenance status updated to " . ucfirst($newStatus);
}

// Delete maintenance record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_maintenance'])) {
    $maintenanceId = intval($_POST['maintenance_id']);
    
    // Verify ownership
    $stmt = $db->prepare("
        DELETE cm FROM car_maintenance cm
        JOIN car_fleet cf ON cm.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cm.maintenance_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$maintenanceId, $userId]);
    $success = "Maintenance record deleted successfully!";
}

// ============================================
// GET DATA
// ============================================

// Get all vehicles for dropdown
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.quantity_available, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// Build query conditions for maintenance records
$conditions = ["cr.owner_id = ?"];
$params = [$userId];

if ($vehicleId > 0) {
    $conditions[] = "cm.car_id = ?";
    $params[] = $vehicleId;
}

if ($statusFilter !== 'all') {
    $conditions[] = "cm.status = ?";
    $params[] = $statusFilter;
}

// Date range filter
if ($dateFrom && $dateTo) {
    $conditions[] = "cm.scheduled_date BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $conditions);

// Get maintenance records
$stmt = $db->prepare("
    SELECT 
        cm.*,
        cf.brand,
        cf.model,
        cf.car_type,
        cr.company_name,
        DATEDIFF(cm.scheduled_date, CURDATE()) as days_until
    FROM car_maintenance cm
    JOIN car_fleet cf ON cm.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN cm.status = 'scheduled' AND cm.scheduled_date < CURDATE() THEN 1
            WHEN cm.status = 'scheduled' AND cm.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 2
            WHEN cm.status = 'scheduled' THEN 3
            WHEN cm.status = 'in_progress' THEN 4
            WHEN cm.status = 'completed' THEN 5
            ELSE 6
        END,
        cm.scheduled_date ASC
");
$stmt->execute($params);
$maintenanceRecords = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($maintenanceRecords),
    'scheduled' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'overdue' => 0,
    'upcoming' => 0,
    'estimated_cost' => 0,
    'actual_cost' => 0
];

foreach ($maintenanceRecords as $record) {
    $stats[$record['status']]++;
    if ($record['status'] === 'scheduled') {
        $stats['estimated_cost'] += $record['estimated_cost'];
        if ($record['scheduled_date'] < date('Y-m-d')) {
            $stats['overdue']++;
        } elseif ($record['scheduled_date'] <= date('Y-m-d', strtotime('+7 days'))) {
            $stats['upcoming']++;
        }
    } elseif ($record['status'] === 'completed') {
        $stats['actual_cost'] += $record['actual_cost'];
    } elseif ($record['status'] === 'in_progress') {
        $stats['estimated_cost'] += $record['estimated_cost'];
    }
}

// Get maintenance types for dropdown
$maintenanceTypes = [
    'oil_change' => 'Oil Change',
    'tire_rotation' => 'Tire Rotation',
    'brake_service' => 'Brake Service',
    'engine_tune_up' => 'Engine Tune-up',
    'transmission_service' => 'Transmission Service',
    'ac_service' => 'AC Service',
    'battery_replacement' => 'Battery Replacement',
    'wheel_alignment' => 'Wheel Alignment',
    'general_service' => 'General Service',
    'inspection' => 'Inspection',
    'repair' => 'Repair',
    'body_work' => 'Body Work',
    'detail_cleaning' => 'Detail Cleaning'
];

// Priority options
$priorityOptions = [
    'low' => ['label' => 'Low', 'color' => 'info'],
    'medium' => ['label' => 'Medium', 'color' => 'warning'],
    'high' => ['label' => 'High', 'color' => 'danger'],
    'critical' => ['label' => 'Critical', 'color' => 'danger']
];
?>

<style>
/* Maintenance Management Specific Styles */
.maintenance-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.maintenance-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.maintenance-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
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

.filter-select, .filter-input {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

.filter-date {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Maintenance Grid */
.maintenance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.maintenance-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.maintenance-card:hover {
    box-shadow: var(--shadow-md);
}

.maintenance-card.overdue {
    border-left: 4px solid var(--cars-danger);
}

.maintenance-card.upcoming {
    border-left: 4px solid var(--cars-warning);
}

.maintenance-card.completed {
    opacity: 0.8;
    border-left: 4px solid var(--cars-success);
}

.maintenance-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.vehicle-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.vehicle-icon {
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

.vehicle-details h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 2px 0;
}

.vehicle-details p {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin: 0;
}

.maintenance-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.badge-scheduled { background: #e6f4ea; color: var(--cars-success); }
.badge-in_progress { background: #fff4e6; color: var(--cars-warning); }
.badge-completed { background: var(--bg-gray); color: var(--text-light); }
.badge-overdue { background: #fce8e8; color: var(--cars-danger); }

.priority-badge {
    padding: 2px 6px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    text-transform: uppercase;
}

.priority-low { background: #e1f5fe; color: #0288d1; }
.priority-medium { background: #fff4e6; color: var(--cars-warning); }
.priority-high { background: #fce8e8; color: var(--cars-danger); }
.priority-critical { background: #fce8e8; color: var(--cars-danger); font-weight: 700; }

.maintenance-body {
    padding: 16px;
}

.maintenance-type {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.maintenance-description {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
    line-height: 1.5;
}

.maintenance-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
}

.detail-item i {
    color: var(--cars-primary);
    width: 16px;
}

.detail-label {
    color: var(--text-light);
}

.detail-value {
    font-weight: 600;
    color: var(--text-dark);
    margin-left: auto;
}

.maintenance-notes {
    font-size: 0.75rem;
    color: var(--text-light);
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
    font-style: italic;
}

.maintenance-footer {
    display: flex;
    gap: 8px;
    padding: 16px;
    border-top: 1px solid var(--border-gray);
    background: var(--bg-gray);
}

.footer-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--text-dark);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.footer-btn:hover {
    background: var(--cars-light);
    color: var(--cars-primary);
    border-color: var(--cars-primary);
}

.footer-btn.warning:hover {
    background: #fff4e6;
    color: var(--cars-warning);
    border-color: var(--cars-warning);
}

.footer-btn.success:hover {
    background: #e6f4ea;
    color: var(--cars-success);
    border-color: var(--cars-success);
}

.footer-btn.danger:hover {
    background: #fce8e8;
    color: var(--cars-danger);
    border-color: var(--cars-danger);
}

/* Calendar Mini View */
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
    padding: 10px 5px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    position: relative;
    background: var(--bg-gray);
}

.calendar-day.has-maintenance {
    background: var(--cars-light);
    color: var(--cars-primary);
    font-weight: 600;
}

.calendar-day.overdue {
    background: #fce8e8;
    color: var(--cars-danger);
}

.calendar-day.has-maintenance::after {
    content: attr(data-count);
    position: absolute;
    top: -5px;
    right: -5px;
    background: var(--cars-primary);
    color: white;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    font-size: 0.5625rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.calendar-day.overdue::after {
    background: var(--cars-danger);
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

.form-label .required {
    color: var(--cars-danger);
    margin-left: 2px;
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
    min-height: 80px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .maintenance-grid,
    .form-grid {
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
    .filter-input {
        width: 100%;
    }
    
    .calendar-grid {
        font-size: 0.625rem;
    }
}
</style>

<div class="maintenance-header">
    <div class="maintenance-title">
        <h1>Maintenance Management</h1>
        <p>Track vehicle maintenance, service records, and schedule repairs</p>
    </div>
    <button class="btn-primary" onclick="openAddMaintenanceModal()">
        <i class="bi bi-plus-lg"></i> Schedule Maintenance
    </button>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['scheduled']; ?></div>
        <div class="stat-label">Scheduled</div>
        <div class="stat-footer"><?php echo $stats['overdue']; ?> overdue • <?php echo $stats['upcoming']; ?> upcoming</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
        <div class="stat-label">In Progress</div>
        <div class="stat-footer">Currently being serviced</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['completed']; ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-footer">This period</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['estimated_cost']); ?></div>
        <div class="stat-label">Est. Cost</div>
        <div class="stat-footer">Actual: <?php echo formatPrice($stats['actual_cost']); ?></div>
    </div>
</div>

<!-- Filter Bar -->
<form class="filter-bar" method="GET" action="maintenance.php">
    <div class="filter-group">
        <label>Vehicle</label>
        <select name="vehicle" class="filter-select">
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
        <select name="status" class="filter-select">
            <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="scheduled" <?php echo $statusFilter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
            <option value="in_progress" <?php echo $statusFilter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" class="filter-input" value="<?php echo $dateFrom; ?>">
    </div>
    
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" class="filter-input" value="<?php echo $dateTo; ?>">
    </div>
    
    <button type="submit" class="btn-primary btn-sm">Apply Filters</button>
    <a href="maintenance.php" class="btn-secondary btn-sm">Clear</a>
</form>

<!-- Mini Calendar -->
<?php
// Get maintenance dates for calendar
$stmt = $db->prepare("
    SELECT 
        cm.scheduled_date,
        COUNT(*) as count,
        SUM(CASE WHEN cm.scheduled_date < CURDATE() AND cm.status = 'scheduled' THEN 1 ELSE 0 END) as overdue
    FROM car_maintenance cm
    JOIN car_fleet cf ON cm.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    AND cm.scheduled_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)
    GROUP BY cm.scheduled_date
");
$stmt->execute([$userId, $dateFrom, $dateFrom]);
$calendarData = $stmt->fetchAll();

$calendar = [];
foreach ($calendarData as $item) {
    $calendar[$item['scheduled_date']] = $item;
}
?>

<div class="calendar-mini">
    <div class="calendar-header">
        <h3 class="calendar-title">📅 Upcoming Maintenance</h3>
        <span style="font-size: 0.75rem; color: var(--text-light);">
            <?php echo date('M d', strtotime($dateFrom)); ?> - <?php echo date('M d', strtotime('+30 days', strtotime($dateFrom))); ?>
        </span>
    </div>
    <div class="calendar-grid">
        <?php
        $startDate = new DateTime($dateFrom);
        for ($i = 0; $i < 35; $i++):
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $dayName = $date->format('D');
            $dayNum = $date->format('j');
            $hasMaintenance = isset($calendar[$dateStr]);
            $isOverdue = $hasMaintenance && $calendar[$dateStr]['overdue'] > 0;
        ?>
        <div class="calendar-day <?php echo $isOverdue ? 'overdue' : ($hasMaintenance ? 'has-maintenance' : ''); ?>" 
             data-count="<?php echo $hasMaintenance ? $calendar[$dateStr]['count'] : ''; ?>"
             title="<?php echo $hasMaintenance ? $calendar[$dateStr]['count'] . ' maintenance tasks' : ''; ?>">
            <div style="font-size: 0.5625rem; color: var(--text-light);"><?php echo $dayName; ?></div>
            <div style="font-weight: 600;"><?php echo $dayNum; ?></div>
        </div>
        <?php endfor; ?>
    </div>
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

<!-- Maintenance Grid -->
<?php if (empty($maintenanceRecords)): ?>
<div class="empty-state">
    <i class="bi bi-tools"></i>
    <h3>No maintenance records found</h3>
    <p>Schedule your first maintenance task to keep your fleet in top condition.</p>
    <button class="btn-primary" onclick="openAddMaintenanceModal()">
        <i class="bi bi-plus-lg"></i> Schedule Maintenance
    </button>
</div>
<?php else: ?>
<div class="maintenance-grid">
    <?php foreach ($maintenanceRecords as $record): 
        $isOverdue = $record['status'] == 'scheduled' && $record['scheduled_date'] < date('Y-m-d');
        $isUpcoming = $record['status'] == 'scheduled' && $record['scheduled_date'] <= date('Y-m-d', strtotime('+7 days')) && $record['scheduled_date'] >= date('Y-m-d');
        $priorityInfo = $priorityOptions[$record['priority']] ?? ['label' => 'Medium', 'color' => 'warning'];
    ?>
    <div class="maintenance-card <?php echo $isOverdue ? 'overdue' : ($isUpcoming ? 'upcoming' : ($record['status'] == 'completed' ? 'completed' : '')); ?>">
        <div class="maintenance-header">
            <div class="vehicle-info">
                <div class="vehicle-icon">
                    <i class="bi bi-car-front"></i>
                </div>
                <div class="vehicle-details">
                    <h3><?php echo sanitize($record['brand'] . ' ' . $record['model']); ?></h3>
                    <p><?php echo sanitize($record['company_name']); ?> • <?php echo ucfirst($record['car_type']); ?></p>
                </div>
            </div>
            <div>
                <span class="priority-badge priority-<?php echo $record['priority']; ?>">
                    <?php echo $priorityInfo['label']; ?>
                </span>
            </div>
        </div>
        
        <div class="maintenance-body">
            <div class="maintenance-type">
                <?php echo $maintenanceTypes[$record['maintenance_type']] ?? ucfirst(str_replace('_', ' ', $record['maintenance_type'])); ?>
            </div>
            
            <div class="maintenance-description">
                <?php echo sanitize($record['description']); ?>
            </div>
            
            <div class="maintenance-details">
                <div class="detail-item">
                    <i class="bi bi-calendar"></i>
                    <span class="detail-label">Date:</span>
                    <span class="detail-value"><?php echo date('M d, Y', strtotime($record['scheduled_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-clock"></i>
                    <span class="detail-label">Duration:</span>
                    <span class="detail-value"><?php echo $record['estimated_duration']; ?> days</span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-tag"></i>
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span class="maintenance-badge badge-<?php echo $record['status']; ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $record['status'])); ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-cash"></i>
                    <span class="detail-label">Est. Cost:</span>
                    <span class="detail-value"><?php echo formatPrice($record['estimated_cost']); ?></span>
                </div>
                <?php if ($record['status'] == 'completed' && $record['actual_cost'] > 0): ?>
                <div class="detail-item">
                    <i class="bi bi-cash-stack"></i>
                    <span class="detail-label">Actual:</span>
                    <span class="detail-value"><?php echo formatPrice($record['actual_cost']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($record['service_provider']): ?>
                <div class="detail-item">
                    <i class="bi bi-shop"></i>
                    <span class="detail-label">Provider:</span>
                    <span class="detail-value"><?php echo sanitize($record['service_provider']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($record['notes']): ?>
            <div class="maintenance-notes">
                <i class="bi bi-chat-text"></i> <?php echo sanitize($record['notes']); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($isOverdue): ?>
            <div style="margin-bottom: 16px; padding: 8px; background: #fce8e8; color: var(--cars-danger); border-radius: var(--radius-sm); font-size: 0.75rem; text-align: center;">
                <i class="bi bi-exclamation-triangle-fill"></i> Overdue by <?php echo abs($record['days_until']); ?> days
            </div>
            <?php endif; ?>
        </div>
        
        <div class="maintenance-footer">
            <?php if ($record['status'] == 'scheduled'): ?>
            <button class="footer-btn warning" onclick="updateStatus(<?php echo $record['maintenance_id']; ?>, 'in_progress')">
                <i class="bi bi-play-circle"></i> Start
            </button>
            <button class="footer-btn success" onclick="completeMaintenance(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                <i class="bi bi-check-circle"></i> Complete
            </button>
            <?php elseif ($record['status'] == 'in_progress'): ?>
            <button class="footer-btn success" onclick="completeMaintenance(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                <i class="bi bi-check-circle"></i> Complete
            </button>
            <?php endif; ?>
            
            <?php if ($record['status'] != 'completed'): ?>
            <button class="footer-btn" onclick="editMaintenance(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                <i class="bi bi-pencil"></i> Edit
            </button>
            <?php endif; ?>
            
            <button class="footer-btn danger" onclick="deleteMaintenance(<?php echo $record['maintenance_id']; ?>)">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add Maintenance Modal -->
<div class="modal" id="addMaintenanceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Schedule Maintenance</h3>
            <button class="modal-close" onclick="closeModal('addMaintenanceModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Vehicle <span class="required">*</span></label>
                        <select name="vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?php echo $v['car_id']; ?>">
                                <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo $v['quantity_available']; ?> available)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maintenance Type <span class="required">*</span></label>
                        <select name="maintenance_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <?php foreach ($maintenanceTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-control">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Scheduled Date <span class="required">*</span></label>
                        <input type="date" name="scheduled_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Est. Duration (days)</label>
                        <input type="number" name="estimated_duration" class="form-control" value="1" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Est. Cost (RWF)</label>
                        <input type="number" name="estimated_cost" class="form-control" value="0" min="0" step="1000">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description <span class="required">*</span></label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Service Provider</label>
                        <input type="text" name="service_provider" class="form-control" placeholder="e.g., Toyota Service Center">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Additional Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addMaintenanceModal')">Cancel</button>
                <button type="submit" name="add_maintenance" class="btn-primary">Schedule Maintenance</button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Maintenance Modal -->
<div class="modal" id="completeModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Complete Maintenance</h3>
            <button class="modal-close" onclick="closeModal('completeModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="maintenance_id" id="complete_maintenance_id" value="0">
                <input type="hidden" name="status" value="completed">
                
                <div class="form-group">
                    <label class="form-label">Actual Cost (RWF)</label>
                    <input type="number" name="actual_cost" class="form-control" value="0" min="0" step="1000">
                </div>
                
                <div style="background: var(--bg-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <p style="font-size: 0.8125rem; margin: 0;">
                        <i class="bi bi-info-circle"></i> Mark this maintenance task as completed.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('completeModal')">Cancel</button>
                <button type="submit" name="update_status" class="btn-primary" style="background: var(--cars-success);">Complete</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="text-align: center;">
            <i class="bi bi-exclamation-triangle" style="font-size: 2.5rem; color: var(--cars-danger); margin-bottom: 16px;"></i>
            <p style="font-size: 0.9375rem; margin-bottom: 8px;">Are you sure you want to delete this maintenance record?</p>
            <p style="font-size: 0.75rem; color: var(--text-light);">This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="maintenance_id" id="delete_maintenance_id" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_maintenance" class="btn-primary" style="background: var(--cars-danger);">Delete Record</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden forms for status updates -->
<form method="POST" id="statusForm" style="display: none;">
    <input type="hidden" name="maintenance_id" id="status_maintenance_id">
    <input type="hidden" name="status" id="status_value">
</form>

<script>
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
// MAINTENANCE FUNCTIONS
// ============================================
function openAddMaintenanceModal() {
    openModal('addMaintenanceModal');
}

function updateStatus(maintenanceId, status) {
    document.getElementById('status_maintenance_id').value = maintenanceId;
    document.getElementById('status_value').value = status;
    document.getElementById('statusForm').submit();
}

function completeMaintenance(record) {
    document.getElementById('complete_maintenance_id').value = record.maintenance_id;
    openModal('completeModal');
}

function deleteMaintenance(id) {
    document.getElementById('delete_maintenance_id').value = id;
    openModal('deleteModal');
}

function editMaintenance(record) {
    alert('Edit functionality coming soon!');
    // This would populate the add modal with existing data
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>