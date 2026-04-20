<?php
$rentalId = isset($_GET['rental_id']) ? intval($_GET['rental_id']) : 0;
$vehicleId = isset($_GET['vehicle_id']) ? intval($_GET['vehicle_id']) : 0;

$pageTitle = 'Maintenance Management';
require_once 'includes/admin_header.php';

$db = getDB();

// If rental_id is provided, get company name
$companyName = '';
if ($rentalId > 0) {
    $stmt = $db->prepare("SELECT company_name FROM car_rentals WHERE rental_id = ?");
    $stmt->execute([$rentalId]);
    $rental = $stmt->fetch();
    if ($rental) {
        $companyName = $rental['company_name'];
    }
}

// Handle maintenance actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Add/Edit Maintenance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add_maintenance' || $action === 'edit_maintenance')) {
    $maintenanceId = isset($_POST['maintenance_id']) ? intval($_POST['maintenance_id']) : 0;
    $carId = intval($_POST['car_id'] ?? 0);
    $maintenance_type = sanitize($_POST['maintenance_type'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $scheduled_date = sanitize($_POST['scheduled_date'] ?? '');
    $estimated_duration = intval($_POST['estimated_duration'] ?? 1);
    $estimated_cost = floatval($_POST['estimated_cost'] ?? 0);
    $actual_cost = !empty($_POST['actual_cost']) ? floatval($_POST['actual_cost']) : null;
    $service_provider = sanitize($_POST['service_provider'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $priority = sanitize($_POST['priority'] ?? 'medium');
    $status = sanitize($_POST['status'] ?? 'scheduled');
    $completed_date = !empty($_POST['completed_date']) ? sanitize($_POST['completed_date']) : null;
    
    // Validation
    $errors = [];
    if ($carId <= 0) $errors[] = "Vehicle selection is required";
    if (empty($maintenance_type)) $errors[] = "Maintenance type is required";
    if (empty($scheduled_date)) $errors[] = "Scheduled date is required";
    
    if (empty($errors)) {
        if ($action === 'add_maintenance') {
            $stmt = $db->prepare("
                INSERT INTO car_maintenance (
                    car_id, maintenance_type, description, scheduled_date, estimated_duration,
                    estimated_cost, actual_cost, service_provider, notes, priority, status, completed_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $carId, $maintenance_type, $description, $scheduled_date, $estimated_duration,
                $estimated_cost, $actual_cost, $service_provider, $notes, $priority, $status, $completed_date
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Maintenance record added successfully";
                // Update vehicle status if needed
                if ($status === 'in_progress' || $status === 'scheduled') {
                    $stmt = $db->prepare("UPDATE car_fleet SET status = 'maintenance' WHERE car_id = ?");
                    $stmt->execute([$carId]);
                }
            }
        } elseif ($action === 'edit_maintenance' && $maintenanceId > 0) {
            $stmt = $db->prepare("
                UPDATE car_maintenance SET
                    car_id = ?, maintenance_type = ?, description = ?, scheduled_date = ?,
                    estimated_duration = ?, estimated_cost = ?, actual_cost = ?, service_provider = ?,
                    notes = ?, priority = ?, status = ?, completed_date = ?
                WHERE maintenance_id = ?
            ");
            $result = $stmt->execute([
                $carId, $maintenance_type, $description, $scheduled_date, $estimated_duration,
                $estimated_cost, $actual_cost, $service_provider, $notes, $priority, $status, $completed_date,
                $maintenanceId
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Maintenance record updated successfully";
                // Update vehicle status
                $stmt = $db->prepare("
                    UPDATE car_fleet SET status = 
                        CASE WHEN ? IN ('in_progress', 'scheduled') THEN 'maintenance' ELSE 'available' END
                    WHERE car_id = ?
                ");
                $stmt->execute([$status, $carId]);
            }
        }
        
        header("Location: maintenance.php?rental_id=$rentalId" . ($vehicleId > 0 ? "&vehicle_id=$vehicleId" : ""));
        exit;
    }
}

// Delete Maintenance Record
if ($action === 'delete_maintenance' && isset($_GET['maintenance_id'])) {
    $maintenanceId = intval($_GET['maintenance_id']);
    
    $stmt = $db->prepare("DELETE FROM car_maintenance WHERE maintenance_id = ?");
    $stmt->execute([$maintenanceId]);
    $_SESSION['success'] = "Maintenance record deleted successfully";
    
    header("Location: maintenance.php?rental_id=$rentalId" . ($vehicleId > 0 ? "&vehicle_id=$vehicleId" : ""));
    exit;
}

// Complete Maintenance (Mark as completed)
if ($action === 'complete_maintenance' && isset($_GET['maintenance_id'])) {
    $maintenanceId = intval($_GET['maintenance_id']);
    
    $stmt = $db->prepare("
        UPDATE car_maintenance SET 
            status = 'completed',
            completed_date = NOW()
        WHERE maintenance_id = ?
    ");
    $stmt->execute([$maintenanceId]);
    
    // Get car_id to update fleet status
    $stmt = $db->prepare("SELECT car_id FROM car_maintenance WHERE maintenance_id = ?");
    $stmt->execute([$maintenanceId]);
    $carId = $stmt->fetchColumn();
    
    // Check if there are any other pending maintenance for this vehicle
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM car_maintenance 
        WHERE car_id = ? AND status IN ('scheduled', 'in_progress')
    ");
    $stmt->execute([$carId]);
    $pendingCount = $stmt->fetchColumn();
    
    if ($pendingCount == 0) {
        $stmt = $db->prepare("UPDATE car_fleet SET status = 'available' WHERE car_id = ?");
        $stmt->execute([$carId]);
    }
    
    $_SESSION['success'] = "Maintenance marked as completed";
    header("Location: maintenance.php?rental_id=$rentalId" . ($vehicleId > 0 ? "&vehicle_id=$vehicleId" : ""));
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$priorityFilter = isset($_GET['priority']) ? sanitize($_GET['priority']) : 'all';
$typeFilter = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query for maintenance records
$sql = "
    SELECT 
        cm.*,
        cf.brand,
        cf.model,
        cf.license_plate,
        cf.year,
        cr.company_name,
        cr.rental_id
    FROM car_maintenance cm
    LEFT JOIN car_fleet cf ON cm.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE 1=1
";

$params = [];

if ($rentalId > 0) {
    $sql .= " AND cr.rental_id = ?";
    $params[] = $rentalId;
}

if ($vehicleId > 0) {
    $sql .= " AND cm.car_id = ?";
    $params[] = $vehicleId;
}

if ($search) {
    $sql .= " AND (cf.brand LIKE ? OR cf.model LIKE ? OR cf.license_plate LIKE ? OR cm.maintenance_type LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($statusFilter !== 'all') {
    $sql .= " AND cm.status = ?";
    $params[] = $statusFilter;
}

if ($priorityFilter !== 'all') {
    $sql .= " AND cm.priority = ?";
    $params[] = $priorityFilter;
}

if ($typeFilter !== 'all') {
    $sql .= " AND cm.maintenance_type = ?";
    $params[] = $typeFilter;
}

if ($dateFrom) {
    $sql .= " AND DATE(cm.scheduled_date) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(cm.scheduled_date) <= ?";
    $params[] = $dateTo;
}

$sql .= " ORDER BY 
    CASE cm.status 
        WHEN 'scheduled' THEN 1
        WHEN 'in_progress' THEN 2
        WHEN 'completed' THEN 3
        ELSE 4
    END,
    cm.scheduled_date ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$maintenanceRecords = $stmt->fetchAll();

// Get statistics
$statsSql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN cm.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN cm.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN cm.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN cm.priority = 'critical' THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN cm.priority = 'high' THEN 1 ELSE 0 END) as high,
        SUM(CASE WHEN cm.priority = 'medium' THEN 1 ELSE 0 END) as medium,
        SUM(CASE WHEN cm.priority = 'low' THEN 1 ELSE 0 END) as low,
        COALESCE(SUM(cm.estimated_cost), 0) as total_estimated_cost,
        COALESCE(SUM(cm.actual_cost), 0) as total_actual_cost
    FROM car_maintenance cm
    LEFT JOIN car_fleet cf ON cm.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE 1=1
";
$statsParams = [];
if ($rentalId > 0) {
    $statsSql .= " AND cr.rental_id = ?";
    $statsParams[] = $rentalId;
}
if ($vehicleId > 0) {
    $statsSql .= " AND cm.car_id = ?";
    $statsParams[] = $vehicleId;
}
$stmt = $db->prepare($statsSql);
$stmt->execute($statsParams);
$stats = $stmt->fetch();

// Get vehicles for dropdown (if rental_id is provided)
$vehicles = [];
if ($rentalId > 0) {
    $stmt = $db->prepare("
        SELECT car_id, brand, model, license_plate, status 
        FROM car_fleet 
        WHERE rental_id = ? 
        ORDER BY brand, model
    ");
    $stmt->execute([$rentalId]);
    $vehicles = $stmt->fetchAll();
}

// Get single vehicle info if vehicle_id is provided
$vehicleInfo = null;
if ($vehicleId > 0) {
    $stmt = $db->prepare("
        SELECT cf.*, cr.company_name 
        FROM car_fleet cf
        LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ?
    ");
    $stmt->execute([$vehicleId]);
    $vehicleInfo = $stmt->fetch();
}

// Maintenance types
$maintenanceTypes = [
    'oil_change' => 'Oil Change',
    'tire_rotation' => 'Tire Rotation',
    'brake_service' => 'Brake Service',
    'engine_tune_up' => 'Engine Tune-up',
    'transmission_service' => 'Transmission Service',
    'battery_replacement' => 'Battery Replacement',
    'air_filter' => 'Air Filter Replacement',
    'coolant_flush' => 'Coolant Flush',
    'inspection' => 'General Inspection',
    'repair' => 'Repair',
    'other' => 'Other'
];

// Priority levels with colors
$priorityColors = [
    'critical' => ['bg' => '#fce8e8', 'color' => '#e21111', 'icon' => 'exclamation-triangle'],
    'high' => ['bg' => '#fff4e6', 'color' => '#ff8c00', 'icon' => 'arrow-up'],
    'medium' => ['bg' => '#e1f5fe', 'color' => '#0288d1', 'icon' => 'dash'],
    'low' => ['bg' => '#e6f4ea', 'color' => '#008009', 'icon' => 'arrow-down']
];

// Status colors
$statusColors = [
    'scheduled' => ['bg' => '#fff4e6', 'color' => '#ff8c00', 'icon' => 'clock'],
    'in_progress' => ['bg' => '#e1f5fe', 'color' => '#0288d1', 'icon' => 'arrow-repeat'],
    'completed' => ['bg' => '#e6f4ea', 'color' => '#008009', 'icon' => 'check-circle']
];
?>

<style>
/* Maintenance Management Styles */
.maintenance-header {
    margin-bottom: 24px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--booking-blue);
    text-decoration: none;
    font-size: 0.75rem;
    margin-bottom: 16px;
}

.back-link:hover {
    text-decoration: underline;
}

.company-info-bar {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.company-info h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.company-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Grid */
.maintenance-stats {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    text-align: center;
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 4px;
}

/* Button Styles */
.btn-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    line-height: 1;
}

.btn-sm.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.btn-sm.primary:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
}

.btn-sm.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
}

.btn-sm.secondary:hover {
    background: var(--booking-gray-dark);
    transform: translateY(-1px);
}

.btn-sm.danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

.btn-sm.danger:hover {
    background: rgba(226,17,17,0.2);
    transform: translateY(-1px);
}

/* Filter Section */
.filter-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 140px;
}

.filter-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    background: var(--booking-white);
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 20px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.filter-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.reset-btn:hover {
    background: var(--booking-gray-dark);
}

/* Maintenance Cards Grid */
.maintenance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.maintenance-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
    position: relative;
}

.maintenance-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header {
    padding: 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.vehicle-info {
    flex: 1;
}

.vehicle-name {
    font-weight: 700;
    font-size: 0.875rem;
    margin-bottom: 2px;
}

.vehicle-plate {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.priority-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.card-body {
    padding: 16px;
}

.maintenance-type {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.type-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.type-info {
    flex: 1;
}

.type-name {
    font-weight: 600;
    font-size: 0.8125rem;
    margin-bottom: 2px;
}

.type-description {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.maintenance-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.6875rem;
}

.detail-item i {
    font-size: 0.875rem;
    color: var(--booking-blue);
    width: 20px;
}

.cost-info {
    display: flex;
    justify-content: space-between;
    padding: 12px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
}

.cost-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.cost-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.cost-value.estimated {
    color: var(--booking-warning);
}

.cost-value.actual {
    color: var(--booking-success);
}

.service-provider {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    padding: 8px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
}

.notes {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    padding: 8px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
    font-style: italic;
}

.card-footer {
    padding: 12px 16px;
    background: var(--booking-gray-light);
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.card-actions {
    display: flex;
    gap: 8px;
}

.action-icon {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    color: var(--booking-text);
}

.action-icon:hover {
    transform: translateY(-2px);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: var(--booking-white);
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--booking-text-light);
}

.modal-close:hover {
    color: var(--booking-danger);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    background: var(--booking-white);
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

/* Alert Messages */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid rgba(0,128,9,0.2);
}

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

/* Responsive */
@media (max-width: 1200px) {
    .maintenance-stats {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .maintenance-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .maintenance-grid {
        grid-template-columns: 1fr;
    }
    .filter-row {
        flex-direction: column;
    }
    .form-row {
        grid-template-columns: 1fr;
    }
    .btn-sm {
        width: 100%;
    }
}
</style>

<div class="maintenance-header">
    <?php if ($vehicleId > 0 && $vehicleInfo): ?>
    <a href="fleet.php?rental_id=<?php echo $rentalId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Fleet
    </a>
    <?php elseif ($rentalId > 0): ?>
    <a href="car-detail.php?id=<?php echo $rentalId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Company Details
    </a>
    <?php else: ?>
    <a href="cars.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Car Rentals
    </a>
    <?php endif; ?>
</div>

<!-- Display success/error messages -->
<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; font-size: 1rem;">&times;</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <div>
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; font-size: 1rem;">&times;</button>
</div>
<?php endif; ?>

<div class="company-info-bar">
    <div class="company-info">
        <?php if ($vehicleId > 0 && $vehicleInfo): ?>
        <h2><?php echo sanitize($vehicleInfo['brand'] . ' ' . $vehicleInfo['model']); ?></h2>
        <p>
            <i class="bi bi-tag"></i> <?php echo sanitize($vehicleInfo['license_plate']); ?>
            <?php if ($vehicleInfo['company_name']): ?> • <?php echo sanitize($vehicleInfo['company_name']); ?><?php endif; ?>
        </p>
        <?php elseif ($rentalId > 0): ?>
        <h2><?php echo sanitize($companyName); ?></h2>
        <p><i class="bi bi-tools"></i> Maintenance Management - All Vehicles</p>
        <?php else: ?>
        <h2>Maintenance Management</h2>
        <p><i class="bi bi-tools"></i> All maintenance records across all companies</p>
        <?php endif; ?>
    </div>
    <button class="btn-sm primary" onclick="openMaintenanceModal()">
        <i class="bi bi-plus-lg"></i> Add Maintenance Record
    </button>
</div>

<!-- Statistics Cards -->
<div class="maintenance-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
        <div class="stat-label">Total Records</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['scheduled'] ?? 0; ?></div>
        <div class="stat-label">Scheduled</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['in_progress'] ?? 0; ?></div>
        <div class="stat-label">In Progress</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['completed'] ?? 0; ?></div>
        <div class="stat-label">Completed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['total_estimated_cost'] ?? 0); ?></div>
        <div class="stat-label">Est. Cost</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['total_actual_cost'] ?? 0); ?></div>
        <div class="stat-label">Actual Cost</div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="maintenance.php" id="filterForm">
        <?php if ($rentalId > 0): ?>
        <input type="hidden" name="rental_id" value="<?php echo $rentalId; ?>">
        <?php endif; ?>
        <?php if ($vehicleId > 0): ?>
        <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
        <?php endif; ?>
        
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Vehicle, plate, type..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="scheduled" <?php echo $statusFilter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="in_progress" <?php echo $statusFilter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Priority</label>
                <select name="priority">
                    <option value="all" <?php echo $priorityFilter == 'all' ? 'selected' : ''; ?>>All Priority</option>
                    <option value="critical" <?php echo $priorityFilter == 'critical' ? 'selected' : ''; ?>>Critical</option>
                    <option value="high" <?php echo $priorityFilter == 'high' ? 'selected' : ''; ?>>High</option>
                    <option value="medium" <?php echo $priorityFilter == 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="low" <?php echo $priorityFilter == 'low' ? 'selected' : ''; ?>>Low</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Type</label>
                <select name="type">
                    <option value="all" <?php echo $typeFilter == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <?php foreach ($maintenanceTypes as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $typeFilter == $key ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply</button>
                <a href="maintenance.php<?php echo $rentalId > 0 ? "?rental_id=$rentalId" . ($vehicleId > 0 ? "&vehicle_id=$vehicleId" : "") : ''; ?>" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Maintenance Records Grid -->
<div class="maintenance-grid">
    <?php if (empty($maintenanceRecords)): ?>
    <div class="empty-state" style="grid-column: 1 / -1;">
        <i class="bi bi-tools" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No maintenance records found</p>
        <button class="btn-sm primary" onclick="openMaintenanceModal()">
            <i class="bi bi-plus-lg"></i> Add Maintenance Record
        </button>
    </div>
    <?php else: ?>
    <?php foreach ($maintenanceRecords as $record): 
        $priorityColor = $priorityColors[$record['priority']] ?? $priorityColors['medium'];
        $statusColor = $statusColors[$record['status']] ?? $statusColors['scheduled'];
    ?>
    <div class="maintenance-card">
        <div class="card-header">
            <div class="vehicle-info">
                <div class="vehicle-name"><?php echo sanitize($record['brand'] . ' ' . $record['model']); ?></div>
                <div class="vehicle-plate">
                    <i class="bi bi-tag"></i> <?php echo sanitize($record['license_plate']); ?>
                    <?php if ($record['year']): ?> • <?php echo $record['year']; ?><?php endif; ?>
                </div>
            </div>
            <div class="priority-badge" style="background: <?php echo $priorityColor['bg']; ?>; color: <?php echo $priorityColor['color']; ?>;">
                <i class="bi bi-<?php echo $priorityColor['icon']; ?>"></i>
                <?php echo ucfirst($record['priority']); ?>
            </div>
        </div>
        
        <div class="card-body">
            <div class="maintenance-type">
                <div class="type-icon" style="background: rgba(0,102,255,0.1); color: var(--booking-blue);">
                    <i class="bi bi-wrench"></i>
                </div>
                <div class="type-info">
                    <div class="type-name"><?php echo $maintenanceTypes[$record['maintenance_type']] ?? ucfirst(str_replace('_', ' ', $record['maintenance_type'])); ?></div>
                    <?php if ($record['description']): ?>
                    <div class="type-description"><?php echo sanitize(substr($record['description'], 0, 80)); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="maintenance-details">
                <div class="detail-item">
                    <i class="bi bi-calendar3"></i>
                    <span>Scheduled: <?php echo date('M d, Y', strtotime($record['scheduled_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-clock"></i>
                    <span>Duration: <?php echo $record['estimated_duration']; ?> day(s)</span>
                </div>
                <?php if ($record['completed_date']): ?>
                <div class="detail-item">
                    <i class="bi bi-check-circle"></i>
                    <span>Completed: <?php echo date('M d, Y', strtotime($record['completed_date'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($record['service_provider']): ?>
                <div class="detail-item">
                    <i class="bi bi-building"></i>
                    <span><?php echo sanitize($record['service_provider']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="cost-info">
                <div>
                    <div class="cost-label">Estimated Cost</div>
                    <div class="cost-value estimated"><?php echo formatPrice($record['estimated_cost']); ?></div>
                </div>
                <div>
                    <div class="cost-label">Actual Cost</div>
                    <div class="cost-value actual"><?php echo $record['actual_cost'] ? formatPrice($record['actual_cost']) : '—'; ?></div>
                </div>
            </div>
            
            <?php if ($record['notes']): ?>
            <div class="notes">
                <i class="bi bi-chat"></i> <?php echo sanitize($record['notes']); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="card-footer">
            <div class="status-badge" style="background: <?php echo $statusColor['bg']; ?>; color: <?php echo $statusColor['color']; ?>;">
                <i class="bi bi-<?php echo $statusColor['icon']; ?>"></i>
                <?php echo ucfirst(str_replace('_', ' ', $record['status'])); ?>
            </div>
            <div class="card-actions">
                <a href="javascript:void(0)" onclick="editMaintenance(<?php echo htmlspecialchars(json_encode($record)); ?>)" class="action-icon" title="Edit">
                    <i class="bi bi-pencil"></i>
                </a>
                <?php if ($record['status'] !== 'completed'): ?>
                <a href="?action=complete_maintenance&maintenance_id=<?php echo $record['maintenance_id']; ?><?php echo $rentalId > 0 ? "&rental_id=$rentalId" : ''; ?><?php echo $vehicleId > 0 ? "&vehicle_id=$vehicleId" : ''; ?>" 
                   class="action-icon" title="Mark as Completed" onclick="return confirm('Mark this maintenance as completed?')">
                    <i class="bi bi-check-lg"></i>
                </a>
                <?php endif; ?>
                <a href="?action=delete_maintenance&maintenance_id=<?php echo $record['maintenance_id']; ?><?php echo $rentalId > 0 ? "&rental_id=$rentalId" : ''; ?><?php echo $vehicleId > 0 ? "&vehicle_id=$vehicleId" : ''; ?>" 
                   class="action-icon" title="Delete" onclick="return confirm('Delete this maintenance record?')">
                    <i class="bi bi-trash"></i>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add/Edit Maintenance Modal -->
<div id="maintenanceModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="maintenance.php<?php echo $rentalId > 0 ? "?rental_id=$rentalId" . ($vehicleId > 0 ? "&vehicle_id=$vehicleId" : "") : ''; ?>" id="maintenanceForm">
            <input type="hidden" name="action" id="maintenanceAction" value="add_maintenance">
            <input type="hidden" name="maintenance_id" id="maintenanceId" value="0">
            
            <div class="modal-header">
                <h3 id="modalTitle">Add Maintenance Record</h3>
                <button type="button" class="modal-close" onclick="closeMaintenanceModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <?php if ($rentalId > 0 && empty($vehicleId)): ?>
                <div class="form-group">
                    <label>Select Vehicle *</label>
                    <select name="car_id" id="car_id" class="form-control" required>
                        <option value="">Select a vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?php echo $vehicle['car_id']; ?>">
                            <?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?>
                            <?php if ($vehicle['license_plate']): ?> (<?php echo $vehicle['license_plate']; ?>)<?php endif; ?>
                            - <?php echo ucfirst($vehicle['status']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php elseif ($vehicleId > 0): ?>
                <input type="hidden" name="car_id" value="<?php echo $vehicleId; ?>">
                <div class="form-group">
                    <label>Vehicle</label>
                    <input type="text" class="form-control" value="<?php echo sanitize($vehicleInfo['brand'] . ' ' . $vehicleInfo['model'] . ' (' . $vehicleInfo['license_plate'] . ')'); ?>" disabled>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>Select Vehicle *</label>
                    <select name="car_id" id="car_id" class="form-control" required>
                        <option value="">Select a vehicle</option>
                        <?php
                        // Get all vehicles for super admin view
                        $stmt = $db->query("
                            SELECT cf.car_id, cf.brand, cf.model, cf.license_plate, cr.company_name
                            FROM car_fleet cf
                            LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
                            ORDER BY cr.company_name, cf.brand, cf.model
                        ");
                        $allVehicles = $stmt->fetchAll();
                        foreach ($allVehicles as $v):
                        ?>
                        <option value="<?php echo $v['car_id']; ?>">
                            <?php echo sanitize($v['company_name'] . ' - ' . $v['brand'] . ' ' . $v['model']); ?>
                            <?php if ($v['license_plate']): ?> (<?php echo $v['license_plate']; ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Maintenance Type *</label>
                        <select name="maintenance_type" id="maintenance_type" class="form-control" required>
                            <option value="">Select type</option>
                            <?php foreach ($maintenanceTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" id="priority" class="form-control">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" placeholder="Describe the maintenance work needed..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Scheduled Date *</label>
                        <input type="date" name="scheduled_date" id="scheduled_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Estimated Duration (days)</label>
                        <input type="number" name="estimated_duration" id="estimated_duration" class="form-control" value="1" min="1">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Estimated Cost (RWF)</label>
                        <input type="number" name="estimated_cost" id="estimated_cost" class="form-control" step="1000" min="0">
                    </div>
                    <div class="form-group">
                        <label>Actual Cost (RWF)</label>
                        <input type="number" name="actual_cost" id="actual_cost" class="form-control" step="1000" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Service Provider</label>
                    <input type="text" name="service_provider" id="service_provider" class="form-control" placeholder="e.g., Toyota Service Center">
                </div>
                
                <div class="form-group">
                    <label>Additional Notes</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Any additional notes..."></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Completion Date</label>
                        <input type="date" name="completed_date" id="completed_date" class="form-control">
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeMaintenanceModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Record</button>
            </div>
        </form>
    </div>
</div>

<script>
// Maintenance Modal
function openMaintenanceModal() {
    document.getElementById('modalTitle').innerText = 'Add Maintenance Record';
    document.getElementById('maintenanceAction').value = 'add_maintenance';
    document.getElementById('maintenanceId').value = '0';
    document.getElementById('maintenanceForm').reset();
    document.getElementById('maintenanceModal').style.display = 'flex';
}

function editMaintenance(record) {
    document.getElementById('modalTitle').innerText = 'Edit Maintenance Record';
    document.getElementById('maintenanceAction').value = 'edit_maintenance';
    document.getElementById('maintenanceId').value = record.maintenance_id;
    document.getElementById('car_id').value = record.car_id;
    document.getElementById('maintenance_type').value = record.maintenance_type;
    document.getElementById('description').value = record.description || '';
    document.getElementById('scheduled_date').value = record.scheduled_date;
    document.getElementById('estimated_duration').value = record.estimated_duration;
    document.getElementById('estimated_cost').value = record.estimated_cost;
    document.getElementById('actual_cost').value = record.actual_cost || '';
    document.getElementById('service_provider').value = record.service_provider || '';
    document.getElementById('notes').value = record.notes || '';
    document.getElementById('priority').value = record.priority;
    document.getElementById('status').value = record.status;
    document.getElementById('completed_date').value = record.completed_date || '';
    document.getElementById('maintenanceModal').style.display = 'flex';
}

function closeMaintenanceModal() {
    document.getElementById('maintenanceModal').style.display = 'none';
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMaintenanceModal();
    }
});

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('maintenanceModal');
    if (e.target === modal) {
        closeMaintenanceModal();
    }
}

// Show/hide completion date based on status
const statusSelect = document.getElementById('status');
if (statusSelect) {
    statusSelect.addEventListener('change', function() {
        const completedDateField = document.getElementById('completed_date');
        if (this.value === 'completed') {
            if (!completedDateField.value) {
                completedDateField.value = new Date().toISOString().split('T')[0];
            }
        }
    });
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>