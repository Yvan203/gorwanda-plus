<?php
$rentalId = isset($_GET['rental_id']) ? intval($_GET['rental_id']) : 0;

if (!$rentalId) {
    header('Location: cars.php');
    exit;
}

$pageTitle = 'Fleet Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Get rental company details
$stmt = $db->prepare("SELECT company_name, rental_id FROM car_rentals WHERE rental_id = ?");
$stmt->execute([$rentalId]);
$rental = $stmt->fetch();

if (!$rental) {
    header('Location: cars.php');
    exit;
}

// Handle vehicle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Add/Edit Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add_vehicle' || $action === 'edit_vehicle')) {
    $vehicleId = isset($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : 0;
    $car_type = sanitize($_POST['car_type'] ?? 'economy');
    $brand = sanitize($_POST['brand'] ?? '');
    $model = sanitize($_POST['model'] ?? '');
    $license_plate = sanitize($_POST['license_plate'] ?? '');
    $vin = sanitize($_POST['vin'] ?? '');
    $year = intval($_POST['year'] ?? 0);
    $color = sanitize($_POST['color'] ?? '');
    $transmission = sanitize($_POST['transmission'] ?? 'manual');
    $fuel_type = sanitize($_POST['fuel_type'] ?? 'petrol');
    $seats = intval($_POST['seats'] ?? 5);
    $luggage_capacity = intval($_POST['luggage_capacity'] ?? 0);
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);
    $weekly_rate = floatval($_POST['weekly_rate'] ?? 0);
    $monthly_rate = floatval($_POST['monthly_rate'] ?? 0);
    $insurance_included = isset($_POST['insurance_included']) ? 1 : 0;
    $excess_km_charge = floatval($_POST['excess_km_charge'] ?? 0);
    $free_km_per_day = intval($_POST['free_km_per_day'] ?? 100);
    $quantity_available = intval($_POST['quantity_available'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $status = sanitize($_POST['status'] ?? 'available');
    $features = isset($_POST['features']) ? json_encode($_POST['features']) : '[]';
    
    // Validation
    $errors = [];
    if (empty($brand)) $errors[] = "Brand is required";
    if (empty($model)) $errors[] = "Model is required";
    if ($daily_rate <= 0) $errors[] = "Daily rate must be greater than 0";
    
    // Handle image uploads
    $images = [];
    if ($vehicleId > 0) {
        // Get existing images
        $stmt = $db->prepare("SELECT images FROM car_fleet WHERE car_id = ?");
        $stmt->execute([$vehicleId]);
        $existing = $stmt->fetch();
        if ($existing && $existing['images']) {
            $images = json_decode($existing['images'], true) ?: [];
        }
    }
    
    // Handle new image uploads
    if (isset($_FILES['vehicle_images']) && is_array($_FILES['vehicle_images']['tmp_name'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['vehicle_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['vehicle_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['vehicle_images']['name'][$key], PATHINFO_EXTENSION);
                $filename = 'car_' . ($vehicleId > 0 ? $vehicleId : 'new') . '_' . time() . '_' . $key . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $images[] = $filename;
                }
            }
        }
    }
    
    // Remove images marked for deletion
    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
        foreach ($_POST['delete_images'] as $image_to_delete) {
            $key = array_search($image_to_delete, $images);
            if ($key !== false) {
                $file_path = $upload_dir . $image_to_delete;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                unset($images[$key]);
            }
        }
        $images = array_values($images);
    }
    
    $images_json = !empty($images) ? json_encode($images) : null;
    
    if (empty($errors)) {
        if ($action === 'add_vehicle') {
            $stmt = $db->prepare("
                INSERT INTO car_fleet (
                    rental_id, car_type, brand, model, license_plate, vin, year, color,
                    transmission, fuel_type, seats, luggage_capacity, daily_rate, weekly_rate,
                    monthly_rate, insurance_included, excess_km_charge, free_km_per_day,
                    quantity_available, is_active, status, images, features
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $rentalId, $car_type, $brand, $model, $license_plate, $vin, $year, $color,
                $transmission, $fuel_type, $seats, $luggage_capacity, $daily_rate, $weekly_rate,
                $monthly_rate, $insurance_included, $excess_km_charge, $free_km_per_day,
                $quantity_available, $is_active, $status, $images_json, $features
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Vehicle added successfully";
            }
        } elseif ($action === 'edit_vehicle' && $vehicleId > 0) {
            $stmt = $db->prepare("
                UPDATE car_fleet SET
                    car_type = ?, brand = ?, model = ?, license_plate = ?, vin = ?, year = ?, color = ?,
                    transmission = ?, fuel_type = ?, seats = ?, luggage_capacity = ?,
                    daily_rate = ?, weekly_rate = ?, monthly_rate = ?, insurance_included = ?,
                    excess_km_charge = ?, free_km_per_day = ?, quantity_available = ?,
                    is_active = ?, status = ?, images = ?, features = ?
                WHERE car_id = ? AND rental_id = ?
            ");
            $result = $stmt->execute([
                $car_type, $brand, $model, $license_plate, $vin, $year, $color,
                $transmission, $fuel_type, $seats, $luggage_capacity,
                $daily_rate, $weekly_rate, $monthly_rate, $insurance_included,
                $excess_km_charge, $free_km_per_day, $quantity_available,
                $is_active, $status, $images_json, $features, $vehicleId, $rentalId
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Vehicle updated successfully";
            }
        }
        
        header("Location: fleet.php?rental_id=$rentalId");
        exit;
    }
}

// Delete Vehicle
if ($action === 'delete_vehicle' && isset($_GET['vehicle_id'])) {
    $vehicleId = intval($_GET['vehicle_id']);
    
    // Check if vehicle has bookings
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE car_id = ? AND status IN ('confirmed', 'completed')");
    $stmt->execute([$vehicleId]);
    $hasBookings = $stmt->fetchColumn() > 0;
    
    if ($hasBookings) {
        $_SESSION['error'] = "Cannot delete vehicle with existing bookings";
    } else {
        // Delete images
        $stmt = $db->prepare("SELECT images FROM car_fleet WHERE car_id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch();
        if ($vehicle && $vehicle['images']) {
            $images = json_decode($vehicle['images'], true);
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
            foreach ($images as $image) {
                $file_path = $upload_dir . $image;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
        
        $stmt = $db->prepare("DELETE FROM car_fleet WHERE car_id = ? AND rental_id = ?");
        $stmt->execute([$vehicleId, $rentalId]);
        $_SESSION['success'] = "Vehicle deleted successfully";
    }
    
    header("Location: fleet.php?rental_id=$rentalId");
    exit;
}

// Get all vehicles
$stmt = $db->prepare("
    SELECT 
        cf.*,
        (SELECT COUNT(*) FROM bookings WHERE car_id = cf.car_id AND status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE car_id = cf.car_id AND status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT COUNT(*) FROM car_maintenance WHERE car_id = cf.car_id AND status IN ('scheduled', 'in_progress')) as pending_maintenance
    FROM car_fleet cf
    WHERE cf.rental_id = ?
    ORDER BY cf.is_active DESC, cf.car_type, cf.brand, cf.model
");
$stmt->execute([$rentalId]);
$vehicles = $stmt->fetchAll();

// Get vehicle types for filter
$vehicleTypes = $db->query("SHOW COLUMNS FROM car_fleet WHERE Field = 'car_type'")->fetch();
preg_match("/^enum\((.*)\)$/", $vehicleTypes['Type'], $matches);
$vehicleTypes = array_map(function($value) {
    return trim($value, "'");
}, explode(',', $matches[1]));

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$typeFilter = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';

// Filter vehicles
$filteredVehicles = $vehicles;
if ($search) {
    $filteredVehicles = array_filter($filteredVehicles, function($v) use ($search) {
        return stripos($v['brand'], $search) !== false || 
               stripos($v['model'], $search) !== false ||
               stripos($v['license_plate'], $search) !== false;
    });
}
if ($typeFilter !== 'all') {
    $filteredVehicles = array_filter($filteredVehicles, function($v) use ($typeFilter) {
        return $v['car_type'] === $typeFilter;
    });
}
if ($statusFilter !== 'all') {
    $filteredVehicles = array_filter($filteredVehicles, function($v) use ($statusFilter) {
        return $v['status'] === $statusFilter;
    });
}

// Get fleet statistics
$totalVehicles = count($vehicles);
$activeVehicles = count(array_filter($vehicles, function($v) { return $v['is_active']; }));
$availableVehicles = count(array_filter($vehicles, function($v) { return $v['status'] === 'available' && $v['is_active']; }));
$rentedVehicles = count(array_filter($vehicles, function($v) { return $v['status'] === 'rented'; }));
$maintenanceVehicles = count(array_filter($vehicles, function($v) { return $v['status'] === 'maintenance'; }));

// Vehicle features list
$vehicleFeatures = [
    'ac' => 'Air Conditioning',
    'gps' => 'GPS Navigation',
    'bluetooth' => 'Bluetooth',
    '4wd' => '4-Wheel Drive',
    'roof_rack' => 'Roof Rack',
    'cooler' => 'Cooler Box',
    'usb' => 'USB Port',
    'cruise_control' => 'Cruise Control',
    'parking_sensors' => 'Parking Sensors',
    'leather_seats' => 'Leather Seats',
    'sunroof' => 'Sunroof',
    'child_seat' => 'Child Seat',
    'wifi' => 'WiFi Hotspot'
];
?>

<style>
/* Fleet Management Styles */
.fleet-header {
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

/* Stats Cards */
.fleet-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    text-align: center;
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

/* Filter Section */
.filter-bar {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 150px;
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
}

.filter-buttons {
    display: flex;
    gap: 8px;
}

/* Vehicles Grid */
.vehicles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.vehicle-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
}

.vehicle-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.vehicle-image {
    position: relative;
    height: 180px;
    background: var(--booking-gray-light);
    overflow: hidden;
}

.vehicle-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: linear-gradient(135deg, var(--booking-gray-light) 0%, var(--booking-white) 100%);
}

.image-placeholder i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
}

.vehicle-status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: rgba(0,0,0,0.8);
    color: white;
}

.vehicle-card-body {
    padding: 16px;
}

.vehicle-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.vehicle-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.vehicle-price {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-success);
}

.vehicle-specs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.spec {
    text-align: center;
}

.spec i {
    font-size: 1rem;
    color: var(--booking-blue);
    display: block;
    margin-bottom: 4px;
}

.spec span {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.vehicle-features {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
}

.feature-tag {
    padding: 2px 8px;
    background: var(--booking-gray-light);
    border-radius: 12px;
    font-size: 0.5625rem;
}

.vehicle-stats {
    display: flex;
    justify-content: space-between;
    padding: 12px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
}

.stat-item {
    text-align: center;
}

.stat-number {
    font-size: 0.875rem;
    font-weight: 700;
}

.stat-text {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.vehicle-actions {
    display: flex;
    gap: 8px;
}

.btn-sm {
    flex: 1;
    padding: 8px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.btn-sm.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.btn-sm.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.btn-sm.danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
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
}

.features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 8px;
    margin-top: 8px;
}

.feature-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.6875rem;
}

.image-preview {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--booking-border);
}

.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.delete-image {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(226,17,17,0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    cursor: pointer;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

/* Responsive */
@media (max-width: 1024px) {
    .fleet-stats {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .fleet-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .vehicles-grid {
        grid-template-columns: 1fr;
    }
    .filter-bar {
        flex-direction: column;
    }
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="fleet-header">
    <a href="car-detail.php?id=<?php echo $rentalId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Company Details
    </a>
</div>

<div class="company-info-bar">
    <div class="company-info">
        <h2><?php echo sanitize($rental['company_name']); ?></h2>
        <p><i class="bi bi-car-front"></i> Fleet Management - <?php echo $totalVehicles; ?> vehicles</p>
    </div>
    <button class="btn-sm primary" onclick="openVehicleModal()" style="padding: 10px 20px;">
        <i class="bi bi-plus-lg"></i> Add New Vehicle
    </button>
</div>

<!-- Fleet Statistics -->
<div class="fleet-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalVehicles; ?></div>
        <div class="stat-label">Total Vehicles</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $activeVehicles; ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $availableVehicles; ?></div>
        <div class="stat-label">Available</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $rentedVehicles; ?></div>
        <div class="stat-label">Rented</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $maintenanceVehicles; ?></div>
        <div class="stat-label">Maintenance</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="text" id="searchInput" placeholder="Brand, model, plate..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    <div class="filter-group">
        <label>Vehicle Type</label>
        <select id="typeFilter">
            <option value="all" <?php echo $typeFilter == 'all' ? 'selected' : ''; ?>>All Types</option>
            <?php foreach ($vehicleTypes as $type): ?>
            <option value="<?php echo $type; ?>" <?php echo $typeFilter == $type ? 'selected' : ''; ?>>
                <?php echo ucfirst($type); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Status</label>
        <select id="statusFilter">
            <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>All Status</option>
            <option value="available" <?php echo $statusFilter == 'available' ? 'selected' : ''; ?>>Available</option>
            <option value="rented" <?php echo $statusFilter == 'rented' ? 'selected' : ''; ?>>Rented</option>
            <option value="maintenance" <?php echo $statusFilter == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
        </select>
    </div>
    <div class="filter-buttons">
        <button class="btn-sm primary" onclick="applyFilters()">Apply</button>
        <button class="btn-sm secondary" onclick="resetFilters()">Reset</button>
    </div>
</div>

<!-- Vehicles Grid -->
<div class="vehicles-grid" id="vehiclesGrid">
    <?php if (empty($filteredVehicles)): ?>
    <div class="empty-state" style="grid-column: 1 / -1;">
        <i class="bi bi-car-front" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No vehicles found matching your criteria</p>
        <button class="btn-sm primary" onclick="openVehicleModal()" style="margin-top: 16px;">
            <i class="bi bi-plus-lg"></i> Add Vehicle
        </button>
    </div>
    <?php else: ?>
    <?php foreach ($filteredVehicles as $vehicle): 
        $vehicleImages = $vehicle['images'] ? json_decode($vehicle['images'], true) : [];
        $firstImage = $vehicleImages[0] ?? null;
        $features = $vehicle['features'] ? json_decode($vehicle['features'], true) : [];
    ?>
    <div class="vehicle-card" data-vehicle-id="<?php echo $vehicle['car_id']; ?>">
        <div class="vehicle-image">
            <?php if ($firstImage): ?>
            <img src="<?php echo getImageUrl($firstImage, 'car'); ?>" alt="<?php echo $vehicle['brand'] . ' ' . $vehicle['model']; ?>">
            <?php else: ?>
            <div class="image-placeholder">
                <i class="bi bi-car-front"></i>
            </div>
            <?php endif; ?>
            <span class="vehicle-status-badge" style="background: <?php 
                echo $vehicle['status'] == 'available' ? '#008009' : ($vehicle['status'] == 'rented' ? '#ff8c00' : '#e21111'); 
            ?>;">
                <?php echo ucfirst($vehicle['status']); ?>
            </span>
        </div>
        
        <div class="vehicle-card-body">
            <div class="vehicle-title">
                <div class="vehicle-name"><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></div>
                <div class="vehicle-price"><?php echo formatPrice($vehicle['daily_rate']); ?><span style="font-size: 0.625rem;">/day</span></div>
            </div>
            
            <?php if ($vehicle['license_plate']): ?>
            <div style="font-size: 0.625rem; color: var(--booking-text-light); margin-bottom: 8px;">
                <i class="bi bi-tag"></i> <?php echo $vehicle['license_plate']; ?>
            </div>
            <?php endif; ?>
            
            <div class="vehicle-specs">
                <div class="spec">
                    <i class="bi bi-people"></i>
                    <span><?php echo $vehicle['seats']; ?> seats</span>
                </div>
                <div class="spec">
                    <i class="bi bi-gear"></i>
                    <span><?php echo ucfirst($vehicle['transmission']); ?></span>
                </div>
                <div class="spec">
                    <i class="bi bi-fuel-pump"></i>
                    <span><?php echo ucfirst($vehicle['fuel_type']); ?></span>
                </div>
            </div>
            
            <?php if (!empty($features)): ?>
            <div class="vehicle-features">
                <?php foreach (array_slice($features, 0, 4) as $feature): ?>
                <span class="feature-tag">
                    <i class="bi bi-<?php echo $feature; ?>"></i>
                    <?php echo $vehicleFeatures[$feature] ?? $feature; ?>
                </span>
                <?php endforeach; ?>
                <?php if (count($features) > 4): ?>
                <span class="feature-tag">+<?php echo count($features) - 4; ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="vehicle-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($vehicle['booking_count']); ?></div>
                    <div class="stat-text">Bookings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo formatPrice($vehicle['total_revenue']); ?></div>
                    <div class="stat-text">Revenue</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $vehicle['pending_maintenance']; ?></div>
                    <div class="stat-text">Maintenance</div>
                </div>
            </div>
            
            <div class="vehicle-actions">
                <button class="btn-sm secondary" onclick="openAvailabilityModal(<?php echo $vehicle['car_id']; ?>, '<?php echo addslashes($vehicle['brand'] . ' ' . $vehicle['model']); ?>')">
                    <i class="bi bi-calendar3"></i> Availability
                </button>
                <button class="btn-sm primary" onclick="editVehicle(<?php echo htmlspecialchars(json_encode($vehicle)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn-sm danger" onclick="deleteVehicle(<?php echo $vehicle['car_id']; ?>, '<?php echo addslashes($vehicle['brand'] . ' ' . $vehicle['model']); ?>')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add/Edit Vehicle Modal -->
<div id="vehicleModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="fleet.php?rental_id=<?php echo $rentalId; ?>" enctype="multipart/form-data" id="vehicleForm">
            <input type="hidden" name="action" id="vehicleAction" value="add_vehicle">
            <input type="hidden" name="vehicle_id" id="vehicleId" value="0">
            
            <div class="modal-header">
                <h3 id="modalTitle">Add New Vehicle</h3>
                <button type="button" class="modal-close" onclick="closeVehicleModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Brand *</label>
                        <input type="text" name="brand" id="brand" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Model *</label>
                        <input type="text" name="model" id="model" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Vehicle Type</label>
                        <select name="car_type" id="car_type" class="form-control">
                            <?php foreach ($vehicleTypes as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>License Plate</label>
                        <input type="text" name="license_plate" id="license_plate" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <input type="number" name="year" id="year" class="form-control" min="1990" max="<?php echo date('Y') + 1; ?>">
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="text" name="color" id="color" class="form-control">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Transmission</label>
                        <select name="transmission" id="transmission" class="form-control">
                            <option value="manual">Manual</option>
                            <option value="automatic">Automatic</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fuel Type</label>
                        <select name="fuel_type" id="fuel_type" class="form-control">
                            <option value="petrol">Petrol</option>
                            <option value="diesel">Diesel</option>
                            <option value="hybrid">Hybrid</option>
                            <option value="electric">Electric</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Seats</label>
                        <input type="number" name="seats" id="seats" class="form-control" min="1" max="50" value="5">
                    </div>
                    <div class="form-group">
                        <label>Luggage Capacity</label>
                        <input type="number" name="luggage_capacity" id="luggage_capacity" class="form-control" placeholder="Number of suitcases">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Daily Rate (RWF) *</label>
                        <input type="number" name="daily_rate" id="daily_rate" class="form-control" step="1000" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Weekly Rate (RWF)</label>
                        <input type="number" name="weekly_rate" id="weekly_rate" class="form-control" step="1000" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Monthly Rate (RWF)</label>
                        <input type="number" name="monthly_rate" id="monthly_rate" class="form-control" step="1000" min="0">
                    </div>
                    <div class="form-group">
                        <label>Free KM per Day</label>
                        <input type="number" name="free_km_per_day" id="free_km_per_day" class="form-control" value="100">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Excess KM Charge (RWF/km)</label>
                        <input type="number" name="excess_km_charge" id="excess_km_charge" class="form-control" step="100" min="0">
                    </div>
                    <div class="form-group">
                        <label>Quantity Available</label>
                        <input type="number" name="quantity_available" id="quantity_available" class="form-control" value="1" min="1">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Features</label>
                    <div class="features-grid" id="featuresGrid">
                        <?php foreach ($vehicleFeatures as $key => $label): ?>
                        <label class="feature-checkbox">
                            <input type="checkbox" name="features[]" value="<?php echo $key; ?>">
                            <i class="bi bi-<?php echo $key; ?>"></i>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Vehicle Images</label>
                    <input type="file" name="vehicle_images[]" id="vehicle_images" class="form-control" multiple accept="image/*">
                    <div id="imagePreview" class="image-preview"></div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="available">Available</option>
                            <option value="rented">Rented</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                            <label for="is_active">Active (Available for booking)</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="insurance_included" id="insurance_included" value="1">
                            <label for="insurance_included">Insurance Included</label>
                        </div>
                    </div>
                </div>
                
                <div id="deletedImagesContainer"></div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeVehicleModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- Availability Modal -->
<div id="availabilityModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 90%; width: auto;">
        <form method="POST" action="fleet.php?rental_id=<?php echo $rentalId; ?>">
            <input type="hidden" name="action" value="update_availability">
            <input type="hidden" name="vehicle_id" id="availVehicleId" value="">
            
            <div class="modal-header">
                <h3 id="availModalTitle">Vehicle Availability</h3>
                <button type="button" class="modal-close" onclick="closeAvailabilityModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="calendar-container" style="overflow-x: auto;">
                    <table class="availability-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr id="availabilityHeaders"></tr>
                        </thead>
                        <tbody id="availabilityBody"></tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeAvailabilityModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Availability</button>
            </div>
        </form>
    </div>
</div>

<script>
// Vehicle Modal
function openVehicleModal() {
    document.getElementById('modalTitle').innerText = 'Add New Vehicle';
    document.getElementById('vehicleAction').value = 'add_vehicle';
    document.getElementById('vehicleId').value = '0';
    document.getElementById('vehicleForm').reset();
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('deletedImagesContainer').innerHTML = '';
    document.querySelectorAll('.feature-checkbox input').forEach(cb => cb.checked = false);
    document.getElementById('is_active').checked = true;
    document.getElementById('vehicleModal').style.display = 'flex';
}

function editVehicle(vehicle) {
    document.getElementById('modalTitle').innerText = 'Edit Vehicle';
    document.getElementById('vehicleAction').value = 'edit_vehicle';
    document.getElementById('vehicleId').value = vehicle.car_id;
    document.getElementById('brand').value = vehicle.brand;
    document.getElementById('model').value = vehicle.model;
    document.getElementById('car_type').value = vehicle.car_type;
    document.getElementById('license_plate').value = vehicle.license_plate || '';
    document.getElementById('year').value = vehicle.year || '';
    document.getElementById('color').value = vehicle.color || '';
    document.getElementById('transmission').value = vehicle.transmission;
    document.getElementById('fuel_type').value = vehicle.fuel_type;
    document.getElementById('seats').value = vehicle.seats;
    document.getElementById('luggage_capacity').value = vehicle.luggage_capacity || '';
    document.getElementById('daily_rate').value = vehicle.daily_rate;
    document.getElementById('weekly_rate').value = vehicle.weekly_rate || '';
    document.getElementById('monthly_rate').value = vehicle.monthly_rate || '';
    document.getElementById('free_km_per_day').value = vehicle.free_km_per_day;
    document.getElementById('excess_km_charge').value = vehicle.excess_km_charge || '';
    document.getElementById('quantity_available').value = vehicle.quantity_available;
    document.getElementById('status').value = vehicle.status;
    document.getElementById('is_active').checked = vehicle.is_active == 1;
    document.getElementById('insurance_included').checked = vehicle.insurance_included == 1;
    
    // Set features
    const features = vehicle.features ? JSON.parse(vehicle.features) : [];
    document.querySelectorAll('.feature-checkbox input').forEach(cb => {
        cb.checked = features.includes(cb.value);
    });
    
    // Show existing images
    const images = vehicle.images ? JSON.parse(vehicle.images) : [];
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    images.forEach(image => {
        const div = document.createElement('div');
        div.className = 'preview-item';
        div.innerHTML = `
            <img src="<?php echo getImageUrl('', 'car'); ?>${image}" alt="Vehicle image">
            <button type="button" class="delete-image" onclick="markImageForDeletion(this, '${image}')">✕</button>
        `;
        preview.appendChild(div);
    });
    
    document.getElementById('vehicleModal').style.display = 'flex';
}

function closeVehicleModal() {
    document.getElementById('vehicleModal').style.display = 'none';
}

function markImageForDeletion(button, imageName) {
    const container = document.getElementById('deletedImagesContainer');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_images[]';
    input.value = imageName;
    container.appendChild(input);
    button.parentElement.remove();
}

// Availability Modal
function openAvailabilityModal(vehicleId, vehicleName) {
    document.getElementById('availVehicleId').value = vehicleId;
    document.getElementById('availModalTitle').innerText = `Availability - ${vehicleName}`;
    
    // Generate next 30 days calendar
    const headers = document.getElementById('availabilityHeaders');
    const body = document.getElementById('availabilityBody');
    
    const dates = [];
    const today = new Date();
    for (let i = 0; i < 30; i++) {
        const date = new Date(today);
        date.setDate(today.getDate() + i);
        dates.push(date);
    }
    
    // Create headers
    headers.innerHTML = '<th>Date</th><th>Day</th><th>Available</th><th>Price Override</th><th>Blocked</th>';
    
    // Create rows
    body.innerHTML = '';
    dates.forEach(date => {
        const dateStr = date.toISOString().split('T')[0];
        const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const dayName = dayNames[date.getDay()];
        
        const row = body.insertRow();
        row.innerHTML = `
            <td style="padding: 8px;">${dateStr}</td>
            <td style="padding: 8px; text-align: center;">${dayName}</td>
            <td style="padding: 8px;"><input type="number" name="availability[${vehicleId}][${dateStr}][quantity]" value="1" min="0" style="width: 60px; padding: 4px;"></td>
            <td style="padding: 8px;"><input type="number" name="availability[${vehicleId}][${dateStr}][price]" placeholder="Override" style="width: 100px; padding: 4px;"></td>
            <td style="padding: 8px; text-align: center;"><input type="checkbox" name="availability[${vehicleId}][${dateStr}][blocked]" value="1"></td>
        `;
    });
    
    document.getElementById('availabilityModal').style.display = 'flex';
}

function closeAvailabilityModal() {
    document.getElementById('availabilityModal').style.display = 'none';
}

// Delete Vehicle
function deleteVehicle(vehicleId, vehicleName) {
    if (confirm(`Are you sure you want to delete "${vehicleName}"? This action cannot be undone.`)) {
        window.location.href = `fleet.php?rental_id=<?php echo $rentalId; ?>&action=delete_vehicle&vehicle_id=${vehicleId}`;
    }
}

// Filters
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const type = document.getElementById('typeFilter').value;
    const status = document.getElementById('statusFilter').value;
    window.location.href = `fleet.php?rental_id=<?php echo $rentalId; ?>&search=${encodeURIComponent(search)}&type=${type}&status=${status}`;
}

function resetFilters() {
    window.location.href = `fleet.php?rental_id=<?php echo $rentalId; ?>`;
}

// Image preview on file select
document.getElementById('vehicle_images')?.addEventListener('change', function(e) {
    const preview = document.getElementById('imagePreview');
    const files = Array.from(e.target.files);
    
    files.forEach(file => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const div = document.createElement('div');
            div.className = 'preview-item';
            div.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
            preview.appendChild(div);
        }
        reader.readAsDataURL(file);
    });
});

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeVehicleModal();
        closeAvailabilityModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const vehicleModal = document.getElementById('vehicleModal');
    const availModal = document.getElementById('availabilityModal');
    if (e.target === vehicleModal) closeVehicleModal();
    if (e.target === availModal) closeAvailabilityModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>