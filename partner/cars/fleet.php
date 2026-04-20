<?php
$pageTitle = 'Fleet Management';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Define upload directory - same as photos.php
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
$uploadUrlPath = '/gorwanda-plus/assets/images/cars/';

// ============================================
// GET COMPANY INFO
// ============================================
$stmt = $db->prepare("
    SELECT rental_id, company_name FROM car_rentals 
    WHERE owner_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$companies = $stmt->fetchAll();

$companyId = null;
if (!empty($companies)) {
    $companyId = $companies[0]['rental_id'];
}

// ============================================
// HANDLE VEHICLE ACTIONS
// ============================================

// Add/Edit Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vehicle'])) {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $rentalId = intval($_POST['rental_id']);
    $carType = sanitize($_POST['car_type']);
    $brand = sanitize($_POST['brand']);
    $model = sanitize($_POST['model']);
    $year = intval($_POST['year']);
    $transmission = sanitize($_POST['transmission']);
    $fuelType = sanitize($_POST['fuel_type']);
    $seats = intval($_POST['seats']);
    $luggageCapacity = intval($_POST['luggage_capacity']);
    $dailyRate = floatval($_POST['daily_rate']);
    $weeklyRate = floatval($_POST['weekly_rate'] ?? 0);
    $monthlyRate = floatval($_POST['monthly_rate'] ?? 0);
    $quantityAvailable = intval($_POST['quantity_available']);
    $freeKmPerDay = intval($_POST['free_km_per_day'] ?? 100);
    $excessKmCharge = floatval($_POST['excess_km_charge'] ?? 0);
    $insuranceIncluded = isset($_POST['insurance_included']) ? 1 : 0;
    $features = isset($_POST['features']) ? json_encode($_POST['features']) : '[]';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Verify ownership
    $stmt = $db->prepare("SELECT rental_id FROM car_rentals WHERE rental_id = ? AND owner_id = ?");
    $stmt->execute([$rentalId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this rental company";
    } else {
        // Handle existing images
        $existingImages = [];
        if ($vehicleId > 0) {
            $stmt = $db->prepare("SELECT images FROM car_fleet WHERE car_id = ?");
            $stmt->execute([$vehicleId]);
            $imgData = $stmt->fetch();
            if ($imgData && $imgData['images']) {
                $existingImages = json_decode($imgData['images'], true) ?: [];
            }
        }
        
        $images = $existingImages;
        
        // Handle image deletions
        if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
            $deleteImages = $_POST['delete_images'];
            foreach ($deleteImages as $delImg) {
                $filePath = $uploadDir . $delImg;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $images = array_diff($images, [$delImg]);
            }
            $images = array_values($images);
        }
        
        // Handle new image uploads
        if (isset($_FILES['vehicle_images']) && !empty($_FILES['vehicle_images']['name'][0])) {
            // Create directory if not exists
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $files = $_FILES['vehicle_images'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    // Validate file type
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                    finfo_close($finfo);
                    
                    if (in_array($mimeType, $allowedTypes)) {
                        // Generate unique filename
                        $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                        $fileName = 'car_' . ($vehicleId ?: time()) . '_' . time() . '_' . uniqid() . '.' . $fileExt;
                        $uploadPath = $uploadDir . $fileName;
                        
                        // Move uploaded file
                        if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                            // Optimize image
                            if ($mimeType === 'image/jpeg') {
                                $image = @imagecreatefromjpeg($uploadPath);
                                if ($image) {
                                    imagejpeg($image, $uploadPath, 85);
                                    imagedestroy($image);
                                }
                            } elseif ($mimeType === 'image/png') {
                                $image = @imagecreatefrompng($uploadPath);
                                if ($image) {
                                    imagealphablending($image, false);
                                    imagesavealpha($image, true);
                                    imagepng($image, $uploadPath, 8);
                                    imagedestroy($image);
                                }
                            }
                            
                            $images[] = $fileName;
                        }
                    }
                }
            }
        }
        
        $imagesJson = !empty($images) ? json_encode(array_values($images)) : null;
        
        if ($vehicleId > 0) {
            // Update existing vehicle
            $stmt = $db->prepare("
                UPDATE car_fleet SET 
                    car_type = ?, brand = ?, model = ?, year = ?,
                    transmission = ?, fuel_type = ?, seats = ?, luggage_capacity = ?,
                    daily_rate = ?, weekly_rate = ?, monthly_rate = ?, 
                    quantity_available = ?, free_km_per_day = ?, excess_km_charge = ?,
                    insurance_included = ?, features = ?, images = ?, is_active = ?
                WHERE car_id = ? AND rental_id = ?
            ");
            $stmt->execute([
                $carType, $brand, $model, $year, $transmission, $fuelType, 
                $seats, $luggageCapacity, $dailyRate, $weeklyRate, $monthlyRate,
                $quantityAvailable, $freeKmPerDay, $excessKmCharge, $insuranceIncluded,
                $features, $imagesJson, $isActive, $vehicleId, $rentalId
            ]);
            $success = "Vehicle updated successfully!";
        } else {
            // Insert new vehicle
            // Check if created_at column exists
            $columns = $db->query("SHOW COLUMNS FROM car_fleet")->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('created_at', $columns)) {
                $stmt = $db->prepare("
                    INSERT INTO car_fleet (
                        rental_id, car_type, brand, model, year, transmission, fuel_type,
                        seats, luggage_capacity, daily_rate, weekly_rate, monthly_rate,
                        quantity_available, free_km_per_day, excess_km_charge,
                        insurance_included, features, images, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                $params = [
                    $rentalId, $carType, $brand, $model, $year, $transmission, $fuelType,
                    $seats, $luggageCapacity, $dailyRate, $weeklyRate, $monthlyRate,
                    $quantityAvailable, $freeKmPerDay, $excessKmCharge, $insuranceIncluded,
                    $features, $imagesJson
                ];
            } else {
                $stmt = $db->prepare("
                    INSERT INTO car_fleet (
                        rental_id, car_type, brand, model, year, transmission, fuel_type,
                        seats, luggage_capacity, daily_rate, weekly_rate, monthly_rate,
                        quantity_available, free_km_per_day, excess_km_charge,
                        insurance_included, features, images, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $params = [
                    $rentalId, $carType, $brand, $model, $year, $transmission, $fuelType,
                    $seats, $luggageCapacity, $dailyRate, $weeklyRate, $monthlyRate,
                    $quantityAvailable, $freeKmPerDay, $excessKmCharge, $insuranceIncluded,
                    $features, $imagesJson
                ];
            }
            $stmt->execute($params);
            $success = "Vehicle added successfully!";
        }
    }
}

// Duplicate Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['duplicate_vehicle'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    
    // Get original vehicle data
    $stmt = $db->prepare("
        SELECT cf.*, cr.rental_id 
        FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    $original = $stmt->fetch();
    
    if ($original) {
        // Check if created_at column exists
        $columns = $db->query("SHOW COLUMNS FROM car_fleet")->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('created_at', $columns)) {
            $stmt = $db->prepare("
                INSERT INTO car_fleet (
                    rental_id, car_type, brand, model, year, transmission, fuel_type,
                    seats, luggage_capacity, daily_rate, weekly_rate, monthly_rate,
                    quantity_available, free_km_per_day, excess_km_charge,
                    insurance_included, features, images, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
        } else {
            $stmt = $db->prepare("
                INSERT INTO car_fleet (
                    rental_id, car_type, brand, model, year, transmission, fuel_type,
                    seats, luggage_capacity, daily_rate, weekly_rate, monthly_rate,
                    quantity_available, free_km_per_day, excess_km_charge,
                    insurance_included, features, images, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
        }
        
        $params = [
            $original['rental_id'],
            $original['car_type'],
            $original['brand'],
            $original['model'] . ' (Copy)',
            $original['year'],
            $original['transmission'],
            $original['fuel_type'],
            $original['seats'],
            $original['luggage_capacity'],
            $original['daily_rate'],
            $original['weekly_rate'],
            $original['monthly_rate'],
            1, // Start with 1 available
            $original['free_km_per_day'],
            $original['excess_km_charge'],
            $original['insurance_included'],
            $original['features'],
            $original['images']
        ];
        
        $stmt->execute($params);
        $success = "Vehicle duplicated successfully!";
    }
}

// Delete Vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vehicle'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    
    // Get images to delete
    $stmt = $db->prepare("SELECT images FROM car_fleet WHERE car_id = ?");
    $stmt->execute([$vehicleId]);
    $imgData = $stmt->fetch();
    if ($imgData && $imgData['images']) {
        $images = json_decode($imgData['images'], true) ?: [];
        foreach ($images as $img) {
            $filePath = $uploadDir . $img;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
    
    // Delete vehicle
    $stmt = $db->prepare("
        DELETE cf FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    $success = "Vehicle deleted successfully!";
}

// Toggle Vehicle Status (AJAX endpoint)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET cf.is_active = ?
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$newStatus, $vehicleId, $userId]);
    
    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    exit;
}

// ============================================
// GET FILTERS
// ============================================
$typeFilter = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$searchQuery = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$companyFilter = isset($_GET['company']) ? intval($_GET['company']) : 0;

// Build query conditions
$conditions = ["cr.owner_id = ?"];
$params = [$userId];

if ($companyFilter > 0) {
    $conditions[] = "cf.rental_id = ?";
    $params[] = $companyFilter;
}

if ($typeFilter !== 'all') {
    $conditions[] = "cf.car_type = ?";
    $params[] = $typeFilter;
}

if ($statusFilter !== 'all') {
    if ($statusFilter === 'active') {
        $conditions[] = "cf.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = "cf.is_active = 0";
    } elseif ($statusFilter === 'low_stock') {
        $conditions[] = "cf.quantity_available < 3 AND cf.quantity_available > 0";
    } elseif ($statusFilter === 'out_of_stock') {
        $conditions[] = "cf.quantity_available = 0";
    }
}

if ($searchQuery) {
    $conditions[] = "(cf.brand LIKE ? OR cf.model LIKE ? OR cf.car_type LIKE ?)";
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET FLEET DATA
// ============================================

// Get vehicles
$stmt = $db->prepare("
    SELECT 
        cf.*,
        cr.company_name,
        cr.rental_id,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.car_id = cf.car_id 
         AND b.status IN ('confirmed', 'pending')
         AND b.pickup_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)) as upcoming_bookings
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN cf.quantity_available = 0 THEN 1
            WHEN cf.quantity_available < 3 THEN 2
            ELSE 3
        END,
        cf.brand ASC,
        cf.model ASC
");
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

// Get statistics
$stats = [
    'total_vehicles' => count($vehicles),
    'total_cars' => 0,
    'active_vehicles' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
    'avg_daily_rate' => 0,
    'min_rate' => 0,
    'max_rate' => 0,
    'vehicles_with_images' => 0
];

$totalRate = 0;
$rateCount = 0;

foreach ($vehicles as $v) {
    $stats['total_cars'] += $v['quantity_available'];
    if ($v['is_active']) {
        $stats['active_vehicles']++;
    }
    if ($v['quantity_available'] < 3 && $v['quantity_available'] > 0) {
        $stats['low_stock']++;
    }
    if ($v['quantity_available'] == 0) {
        $stats['out_of_stock']++;
    }
    if (!empty($v['images']) && $v['images'] !== '[]') {
        $stats['vehicles_with_images']++;
    }
    if ($v['daily_rate'] > 0) {
        $totalRate += $v['daily_rate'];
        $rateCount++;
    }
    if ($stats['min_rate'] == 0 || $v['daily_rate'] < $stats['min_rate']) {
        $stats['min_rate'] = $v['daily_rate'];
    }
    if ($v['daily_rate'] > $stats['max_rate']) {
        $stats['max_rate'] = $v['daily_rate'];
    }
}

$stats['avg_daily_rate'] = $rateCount > 0 ? $totalRate / $rateCount : 0;

// Get unique car types for filter
$carTypes = $db->prepare("
    SELECT DISTINCT car_type FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY car_type
");
$carTypes->execute([$userId]);
$carTypes = $carTypes->fetchAll(PDO::FETCH_COLUMN);

// Car type options
$carTypeOptions = [
    'economy' => 'Economy',
    'compact' => 'Compact',
    'mid_size' => 'Mid-size',
    'full_size' => 'Full-size',
    'suv' => 'SUV',
    'luxury' => 'Luxury',
    'van' => 'Van/Minibus',
    '4x4' => '4x4 / Off-road'
];

// Transmission options
$transmissionOptions = [
    'manual' => 'Manual',
    'automatic' => 'Automatic'
];

// Fuel type options
$fuelTypeOptions = [
    'petrol' => 'Petrol',
    'diesel' => 'Diesel',
    'hybrid' => 'Hybrid',
    'electric' => 'Electric'
];

// Common features
$featureOptions = [
    'ac' => 'Air Conditioning',
    'gps' => 'GPS Navigation',
    'bluetooth' => 'Bluetooth',
    '4wd' => '4-Wheel Drive',
    'roof_rack' => 'Roof Rack',
    'cooler' => 'Cooler Box',
    'usb' => 'USB Charging',
    'cruise_control' => 'Cruise Control',
    'parking_sensors' => 'Parking Sensors',
    'reverse_camera' => 'Reverse Camera',
    'leather_seats' => 'Leather Seats',
    'sunroof' => 'Sunroof',
    'child_seat' => 'Child Seat (on request)',
    'wifi' => 'WiFi Hotspot'
];

// Icons for car types
$carTypeIcons = [
    'economy' => 'bi-car-front',
    'compact' => 'bi-car-front',
    'mid_size' => 'bi-car-front',
    'full_size' => 'bi-car-front',
    'suv' => 'bi-truck',
    'luxury' => 'bi-stars',
    'van' => 'bi-bus-front',
    '4x4' => 'bi-globe'
];
?>

<style>
/* Fleet Management Specific Styles - Booking.com Size */
.fleet-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.fleet-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.fleet-title p {
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
    letter-spacing: 0.3px;
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

.filter-search {
    flex: 2;
    min-width: 250px;
    position: relative;
}

.filter-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
}

.filter-search input {
    width: 100%;
    padding: 10px 16px 10px 38px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.filter-select {
    min-width: 150px;
    padding: 10px 16px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
}

/* Vehicle Grid */
.vehicle-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.vehicle-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.vehicle-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--cars-primary);
}

.vehicle-card.low-stock {
    border-left: 4px solid var(--cars-warning);
}

.vehicle-card.out-of-stock {
    border-left: 4px solid var(--cars-danger);
    opacity: 0.7;
}

.vehicle-image {
    height: 160px;
    background: var(--bg-gray);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-light);
    font-size: 3rem;
    overflow: hidden;
}

.vehicle-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.vehicle-image .error-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
    color: #999;
    font-size: 0.75rem;
    text-align: center;
    padding: 10px;
}

.vehicle-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 2px 6px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    z-index: 2;
}

.vehicle-badge.active {
    background: #e6f4ea;
    color: var(--cars-success);
}

.vehicle-badge.inactive {
    background: var(--bg-gray);
    color: var(--text-light);
}

/* Small Action Buttons - Booking.com Size */
.vehicle-actions {
    position: absolute;
    top: 8px;
    left: 8px;
    display: flex;
    gap: 4px;
    z-index: 2;
    opacity: 0;
    transition: opacity 0.2s;
}

.vehicle-card:hover .vehicle-actions {
    opacity: 1;
}

.action-btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: white;
    border: 1px solid var(--border-gray);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
    font-size: 0.75rem;
    padding: 0;
}

.action-btn:hover {
    background: var(--cars-primary);
    color: white;
    border-color: var(--cars-primary);
}

.action-btn.delete:hover {
    background: var(--cars-danger);
    border-color: var(--cars-danger);
}

.vehicle-content {
    padding: 16px;
}

.vehicle-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.vehicle-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.vehicle-type {
    font-size: 0.6875rem;
    color: var(--cars-primary);
    font-weight: 600;
    text-transform: uppercase;
    background: var(--cars-light);
    padding: 2px 6px;
    border-radius: 4px;
}

.vehicle-specs {
    display: flex;
    gap: 12px;
    margin-bottom: 12px;
    padding: 8px 0;
    border-top: 1px solid var(--border-gray);
    border-bottom: 1px solid var(--border-gray);
    flex-wrap: wrap;
}

.spec-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.6875rem;
    color: var(--text-light);
}

.spec-item i {
    color: var(--cars-primary);
    font-size: 0.75rem;
}

.vehicle-features {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 12px;
    min-height: 28px;
}

.feature-tag {
    background: var(--bg-gray);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.625rem;
    color: var(--text-light);
    border: 1px solid var(--border-gray);
}

.vehicle-pricing {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 12px;
}

.price-daily {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--cars-success);
}

.price-daily small {
    font-size: 0.625rem;
    font-weight: 400;
    color: var(--text-light);
}

.price-weekly {
    font-size: 0.6875rem;
    color: var(--text-light);
}

.vehicle-stock {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 16px;
    padding: 6px 0;
    border-top: 1px solid var(--border-gray);
    font-size: 0.6875rem;
}

.stock-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.stock-indicator.high { background: var(--cars-success); }
.stock-indicator.medium { background: var(--cars-warning); }
.stock-indicator.low { background: var(--cars-danger); }

.stock-text {
    font-weight: 600;
}

.stock-count {
    margin-left: auto;
    color: var(--text-light);
}

.vehicle-footer {
    display: flex;
    gap: 6px;
}

.footer-btn {
    flex: 1;
    padding: 6px 8px;
    background: var(--bg-gray);
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--text-dark);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
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

.footer-btn.primary {
    background: var(--cars-primary);
    color: white;
    border-color: var(--cars-primary);
}

.footer-btn.primary:hover {
    background: var(--cars-dark);
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
    max-width: 700px;
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

/* Features Grid */
.features-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
}

.feature-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
    background: white;
    border: 1px solid var(--border-gray);
}

.feature-checkbox:hover {
    background: var(--cars-light);
    border-color: var(--cars-primary);
}

.feature-checkbox input[type="checkbox"] {
    width: 14px;
    height: 14px;
    accent-color: var(--cars-primary);
}

/* Image Upload */
.image-upload-area {
    border: 2px dashed var(--border-gray);
    border-radius: var(--radius-sm);
    padding: 20px;
    text-align: center;
    background: var(--bg-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 16px;
}

.image-upload-area:hover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
}

.image-upload-area i {
    font-size: 1.5rem;
    color: var(--text-light);
    margin-bottom: 4px;
}

.image-upload-area p {
    font-size: 0.75rem;
    margin: 0;
}

.image-preview-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 12px;
}

.image-preview-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--border-gray);
}

.image-preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-preview-remove {
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

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
}

.empty-state i {
    font-size: 3rem;
    color: var(--text-light);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin-bottom: 20px;
}

/* Debug Info */
.debug-info {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 20px;
    font-size: 0.8125rem;
    color: #856404;
}

.debug-info code {
    background: rgba(0,0,0,0.05);
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .vehicle-grid,
    .form-grid,
    .features-grid,
    .image-preview-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-search,
    .filter-select {
        width: 100%;
    }
}
</style>

<!-- Debug Info (Remove in production) -->
<?php if (isset($_GET['debug'])): ?>
<div class="debug-info">
    <strong>Debug Info:</strong><br>
    Upload Directory: <code><?php echo htmlspecialchars($uploadDir); ?></code><br>
    Directory Exists: <code><?php echo file_exists($uploadDir) ? 'YES' : 'NO'; ?></code><br>
    Is Writable: <code><?php echo is_writable($uploadDir) ? 'YES' : 'NO'; ?></code><br>
    URL Path: <code><?php echo htmlspecialchars($uploadUrlPath); ?></code>
</div>
<?php endif; ?>

<div class="fleet-header">
    <div class="fleet-title">
        <h1>Fleet Management</h1>
        <p>Manage your vehicles, inventory, and pricing</p>
    </div>
    <button class="btn-primary" onclick="openAddVehicleModal()">
        <i class="bi bi-plus-lg"></i> Add Vehicle
    </button>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_vehicles']; ?></div>
        <div class="stat-label">Vehicle Models</div>
        <div class="stat-footer"><?php echo $stats['total_cars']; ?> total units</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['active_vehicles']; ?>/<?php echo $stats['total_vehicles']; ?></div>
        <div class="stat-label">Active Models</div>
        <div class="stat-footer"><?php echo $stats['total_vehicles'] > 0 ? round(($stats['active_vehicles'] / $stats['total_vehicles']) * 100) : 0; ?>% active</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['vehicles_with_images']; ?>/<?php echo $stats['total_vehicles']; ?></div>
        <div class="stat-label">With Images</div>
        <div class="stat-footer"><?php echo $stats['total_vehicles'] - $stats['vehicles_with_images']; ?> need photos</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_daily_rate']); ?></div>
        <div class="stat-label">Avg. Daily Rate</div>
        <div class="stat-footer">Min: <?php echo formatPrice($stats['min_rate']); ?> • Max: <?php echo formatPrice($stats['max_rate']); ?></div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-search">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search by brand, model, or type..." value="<?php echo htmlspecialchars($searchQuery); ?>">
    </div>
    
    <select class="filter-select" id="typeFilter">
        <option value="all">All Types</option>
        <?php foreach ($carTypes as $type): ?>
        <option value="<?php echo $type; ?>" <?php echo $typeFilter == $type ? 'selected' : ''; ?>>
            <?php echo ucfirst($type); ?>
        </option>
        <?php endforeach; ?>
    </select>
    
    <select class="filter-select" id="statusFilter">
        <option value="all">All Status</option>
        <option value="active" <?php echo $statusFilter == 'active' ? 'selected' : ''; ?>>Active</option>
        <option value="inactive" <?php echo $statusFilter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        <option value="low_stock" <?php echo $statusFilter == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
        <option value="out_of_stock" <?php echo $statusFilter == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
    </select>
    
    <?php if (count($companies) > 1): ?>
    <select class="filter-select" id="companyFilter">
        <option value="0">All Companies</option>
        <?php foreach ($companies as $comp): ?>
        <option value="<?php echo $comp['rental_id']; ?>" <?php echo $companyFilter == $comp['rental_id'] ? 'selected' : ''; ?>>
            <?php echo sanitize($comp['company_name']); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    
    <button class="btn-secondary" onclick="applyFilters()">Apply Filters</button>
    <button class="btn-secondary" onclick="resetFilters()">Clear</button>
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

<!-- Vehicle Grid -->
<?php if (empty($vehicles)): ?>
<div class="empty-state">
    <i class="bi bi-car-front"></i>
    <h3>No vehicles found</h3>
    <p>Add your first vehicle to start managing your fleet.</p>
    <button class="btn-primary" onclick="openAddVehicleModal()">
        <i class="bi bi-plus-lg"></i> Add Vehicle
    </button>
</div>
<?php else: ?>
<div class="vehicle-grid" id="vehicleGrid">
    <?php foreach ($vehicles as $vehicle): 
        $stockClass = 'high';
        if ($vehicle['quantity_available'] == 0) {
            $stockClass = 'low';
        } elseif ($vehicle['quantity_available'] < 3) {
            $stockClass = 'medium';
        }
        
        $features = json_decode($vehicle['features'] ?? '[]', true);
        $images = json_decode($vehicle['images'] ?? '[]', true);
        $firstImage = $images[0] ?? null;
    ?>
    <div class="vehicle-card <?php echo $vehicle['quantity_available'] == 0 ? 'out-of-stock' : ($vehicle['quantity_available'] < 3 ? 'low-stock' : ''); ?>" id="vehicle-<?php echo $vehicle['car_id']; ?>">
        <div class="vehicle-image">
            <?php if ($firstImage): 
                $imagePath = $uploadDir . $firstImage;
                $imageUrl = $uploadUrlPath . urlencode($firstImage);
                $fileExists = file_exists($imagePath);
            ?>
                <?php if ($fileExists): ?>
                    <img src="<?php echo $imageUrl; ?>" 
                         alt="<?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?>"
                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'error-placeholder\'><i class=\'bi bi-image\' style=\'font-size:2rem;display:block;margin-bottom:8px;\'></i>Image not found</div>';">
                <?php else: ?>
                    <div class="error-placeholder">
                        <i class="bi bi-image" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                        File missing<br>
                        <small><?php echo htmlspecialchars($firstImage); ?></small>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <i class="bi bi-car-front"></i>
            <?php endif; ?>
            
            <span class="vehicle-badge <?php echo $vehicle['is_active'] ? 'active' : 'inactive'; ?>">
                <?php echo $vehicle['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
            
            <!-- Small Action Buttons -->
            <div class="vehicle-actions">
                <button class="action-btn" onclick="editVehicle(<?php echo htmlspecialchars(json_encode($vehicle)); ?>)" title="Edit">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="action-btn" onclick="toggleStatus(<?php echo $vehicle['car_id']; ?>, <?php echo $vehicle['is_active']; ?>)" title="<?php echo $vehicle['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                    <i class="bi bi-<?php echo $vehicle['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                </button>
                <button class="action-btn delete" onclick="deleteVehicle(<?php echo $vehicle['car_id']; ?>, '<?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?>')" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        
        <div class="vehicle-content">
            <div class="vehicle-header">
                <h3 class="vehicle-name"><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></h3>
                <span class="vehicle-type"><?php echo ucfirst($vehicle['car_type']); ?></span>
            </div>
            
            <div class="vehicle-specs">
                <div class="spec-item">
                    <i class="bi bi-calendar"></i>
                    <span><?php echo $vehicle['year']; ?></span>
                </div>
                <div class="spec-item">
                    <i class="bi bi-gear"></i>
                    <span><?php echo ucfirst($vehicle['transmission']); ?></span>
                </div>
                <div class="spec-item">
                    <i class="bi bi-fuel-pump"></i>
                    <span><?php echo ucfirst($vehicle['fuel_type']); ?></span>
                </div>
                <div class="spec-item">
                    <i class="bi bi-people"></i>
                    <span><?php echo $vehicle['seats']; ?> seats</span>
                </div>
                <?php if ($vehicle['luggage_capacity'] > 0): ?>
                <div class="spec-item">
                    <i class="bi bi-bag"></i>
                    <span><?php echo $vehicle['luggage_capacity']; ?> bags</span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($features)): ?>
            <div class="vehicle-features">
                <?php 
                $displayFeatures = array_slice($features, 0, 3);
                foreach ($displayFeatures as $feature): 
                ?>
                <span class="feature-tag">
                    <i class="bi bi-check-circle-fill" style="color: var(--cars-success); font-size: 0.5rem;"></i>
                    <?php echo $featureOptions[$feature] ?? ucfirst($feature); ?>
                </span>
                <?php endforeach; ?>
                <?php if (count($features) > 3): ?>
                <span class="feature-tag">+<?php echo count($features) - 3; ?> more</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="vehicle-pricing">
                <div class="price-daily">
                    <?php echo formatPrice($vehicle['daily_rate']); ?> <small>/day</small>
                </div>
                <?php if ($vehicle['weekly_rate'] > 0): ?>
                <div class="price-weekly">
                    <?php echo formatPrice($vehicle['weekly_rate']); ?>/week
                </div>
                <?php endif; ?>
            </div>
            
            <div class="vehicle-stock">
                <div class="stock-indicator <?php echo $stockClass; ?>"></div>
                <span class="stock-text">
                    <?php 
                    if ($vehicle['quantity_available'] == 0) {
                        echo 'Out of Stock';
                    } elseif ($vehicle['quantity_available'] < 3) {
                        echo 'Low Stock';
                    } else {
                        echo 'In Stock';
                    }
                    ?>
                </span>
                <span class="stock-count"><?php echo $vehicle['quantity_available']; ?> available</span>
            </div>
            
            <div class="vehicle-footer">
                <a href="bookings.php?vehicle=<?php echo $vehicle['car_id']; ?>" class="footer-btn">
                    <i class="bi bi-calendar-check"></i> Bookings
                </a>
                <a href="calendar.php?vehicle=<?php echo $vehicle['car_id']; ?>" class="footer-btn">
                    <i class="bi bi-calendar-week"></i> Calendar
                </a>
                <a href="photos.php?vehicle=<?php echo $vehicle['car_id']; ?>" class="footer-btn primary">
                    <i class="bi bi-images"></i> Photos
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add/Edit Vehicle Modal -->
<div class="modal" id="vehicleModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="vehicleModalTitle">Add Vehicle</h3>
            <button class="modal-close" onclick="closeModal('vehicleModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="vehicleForm">
            <div class="modal-body">
                <input type="hidden" name="vehicle_id" id="vehicle_id" value="0">
                <input type="hidden" name="rental_id" id="rental_id" value="<?php echo $companyId; ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Car Type <span class="required">*</span></label>
                        <select name="car_type" id="car_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <?php foreach ($carTypeOptions as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Brand <span class="required">*</span></label>
                        <input type="text" name="brand" id="brand" class="form-control" placeholder="e.g., Toyota" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Model <span class="required">*</span></label>
                        <input type="text" name="model" id="model" class="form-control" placeholder="e.g., RAV4" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Year <span class="required">*</span></label>
                        <input type="number" name="year" id="year" class="form-control" min="2000" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Transmission</label>
                        <select name="transmission" id="transmission" class="form-control">
                            <?php foreach ($transmissionOptions as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fuel Type</label>
                        <select name="fuel_type" id="fuel_type" class="form-control">
                            <?php foreach ($fuelTypeOptions as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Seats</label>
                        <input type="number" name="seats" id="seats" class="form-control" value="5" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Luggage Capacity</label>
                        <input type="number" name="luggage_capacity" id="luggage_capacity" class="form-control" value="2" min="0" placeholder="Number of bags">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Daily Rate (RWF) <span class="required">*</span></label>
                        <input type="number" name="daily_rate" id="daily_rate" class="form-control" min="0" step="1000" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Weekly Rate (RWF)</label>
                        <input type="number" name="weekly_rate" id="weekly_rate" class="form-control" min="0" step="1000">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Monthly Rate (RWF)</label>
                        <input type="number" name="monthly_rate" id="monthly_rate" class="form-control" min="0" step="1000">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantity Available</label>
                        <input type="number" name="quantity_available" id="quantity_available" class="form-control" value="1" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Free Km per Day</label>
                        <input type="number" name="free_km_per_day" id="free_km_per_day" class="form-control" value="100" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Excess Km Charge (RWF)</label>
                        <input type="number" name="excess_km_charge" id="excess_km_charge" class="form-control" value="0" min="0" step="100" placeholder="per km">
                    </div>
                    
                    <div class="form-group full-width">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="insurance_included" id="insurance_included" value="1" style="width: 16px; height: 16px;">
                            <label for="insurance_included" style="font-size: 0.8125rem;">Insurance Included</label>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Features</label>
                        <div class="features-grid" id="featuresContainer">
                            <?php foreach ($featureOptions as $key => $label): ?>
                            <label class="feature-checkbox">
                                <input type="checkbox" name="features[]" value="<?php echo $key; ?>">
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Vehicle Images</label>
                        <div class="image-upload-area" onclick="document.getElementById('vehicle_images').click()">
                            <i class="bi bi-cloud-upload"></i>
                            <p>Click to upload images</p>
                            <input type="file" name="vehicle_images[]" id="vehicle_images" multiple accept="image/*" style="display: none;">
                        </div>
                        <div class="image-preview-grid" id="imagePreview"></div>
                        <div id="existingImagesContainer"></div>
                    </div>
                    
                    <div class="form-group">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="is_active" id="is_active" checked style="width: 16px; height: 16px;">
                            <label for="is_active" style="font-size: 0.8125rem;">Active (available for booking)</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('vehicleModal')">Cancel</button>
                <button type="submit" name="save_vehicle" class="btn-primary">Save Vehicle</button>
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
            <p id="deleteMessage" style="font-size: 0.9375rem; margin-bottom: 8px;">Are you sure you want to delete this vehicle?</p>
            <p style="font-size: 0.75rem; color: var(--text-light);">This action cannot be undone. Any associated bookings will be affected.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="vehicle_id" id="delete_vehicle_id" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_vehicle" class="btn-primary" style="background: var(--cars-danger);">Delete Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="vehicle_id" id="toggle_vehicle_id">
    <input type="hidden" name="current_status" id="toggle_current_status">
    <input type="hidden" name="toggle_status" value="1">
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
// VEHICLE FUNCTIONS
// ============================================
function openAddVehicleModal() {
    document.getElementById('vehicleModalTitle').textContent = 'Add Vehicle';
    document.getElementById('vehicleForm').reset();
    document.getElementById('vehicle_id').value = 0;
    document.getElementById('is_active').checked = true;
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('existingImagesContainer').innerHTML = '';
    openModal('vehicleModal');
}

function editVehicle(vehicle) {
    document.getElementById('vehicleModalTitle').textContent = 'Edit Vehicle';
    document.getElementById('vehicle_id').value = vehicle.car_id;
    document.getElementById('car_type').value = vehicle.car_type;
    document.getElementById('brand').value = vehicle.brand;
    document.getElementById('model').value = vehicle.model;
    document.getElementById('year').value = vehicle.year;
    document.getElementById('transmission').value = vehicle.transmission;
    document.getElementById('fuel_type').value = vehicle.fuel_type;
    document.getElementById('seats').value = vehicle.seats;
    document.getElementById('luggage_capacity').value = vehicle.luggage_capacity;
    document.getElementById('daily_rate').value = vehicle.daily_rate;
    document.getElementById('weekly_rate').value = vehicle.weekly_rate || '';
    document.getElementById('monthly_rate').value = vehicle.monthly_rate || '';
    document.getElementById('quantity_available').value = vehicle.quantity_available;
    document.getElementById('free_km_per_day').value = vehicle.free_km_per_day;
    document.getElementById('excess_km_charge').value = vehicle.excess_km_charge;
    document.getElementById('insurance_included').checked = vehicle.insurance_included == 1;
    document.getElementById('is_active').checked = vehicle.is_active == 1;
    
    // Check features
    let features = [];
    try {
        features = JSON.parse(vehicle.features || '[]');
    } catch(e) {
        features = [];
    }
    
    document.querySelectorAll('#featuresContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = features.includes(cb.value);
    });
    
    // Show existing images with delete checkboxes
    let images = [];
    try {
        images = JSON.parse(vehicle.images || '[]');
    } catch(e) {
        images = [];
    }
    
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    
    if (images.length > 0) {
        images.forEach((img, index) => {
            preview.innerHTML += `
                <div class="image-preview-item">
                    <img src="/gorwanda-plus/assets/images/cars/${img}" alt="Vehicle image">
                    <label class="image-preview-remove" style="background: var(--cars-danger);">
                        <input type="checkbox" name="delete_images[]" value="${img}" style="display: none;">
                        <i class="bi bi-trash" onclick="this.closest('label').querySelector('input').checked = true; this.closest('.image-preview-item').style.opacity='0.5';"></i>
                    </label>
                </div>
            `;
        });
    }
    
    openModal('vehicleModal');
}

function deleteVehicle(id, name) {
    document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>"' + name + '"</strong>?';
    document.getElementById('delete_vehicle_id').value = id;
    openModal('deleteModal');
}

function toggleStatus(id, currentStatus) {
    if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this vehicle?')) {
        document.getElementById('toggle_vehicle_id').value = id;
        document.getElementById('toggle_current_status').value = currentStatus;
        document.getElementById('toggleForm').submit();
    }
}

// ============================================
// IMAGE PREVIEW
// ============================================
document.getElementById('vehicle_images')?.addEventListener('change', function() {
    const preview = document.getElementById('imagePreview');
    
    for (let i = 0; i < this.files.length; i++) {
        const file = this.files[i];
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML += `
                <div class="image-preview-item">
                    <img src="${e.target.result}" alt="Preview">
                </div>
            `;
        }
        
        reader.readAsDataURL(file);
    }
});

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const type = document.getElementById('typeFilter').value;
    const status = document.getElementById('statusFilter').value;
    const company = document.getElementById('companyFilter') ? document.getElementById('companyFilter').value : '0';
    
    let url = 'fleet.php?';
    const params = [];
    if (search) params.push('search=' + encodeURIComponent(search));
    if (type !== 'all') params.push('type=' + type);
    if (status !== 'all') params.push('status=' + status);
    if (company && company !== '0') params.push('company=' + company);
    
    window.location.href = url + params.join('&');
}

function resetFilters() {
    window.location.href = 'fleet.php';
}

// Real-time search
document.getElementById('searchInput')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        applyFilters();
    }
});
</script>

<?php require_once 'includes/cars_footer.php'; ?>