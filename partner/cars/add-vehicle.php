<?php
$pageTitle = 'Add Vehicle';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Define upload directory
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';

// Get rental companies for this user
$stmt = $db->prepare("
    SELECT rental_id, company_name FROM car_rentals 
    WHERE owner_id = ? 
    ORDER BY company_name
");
$stmt->execute([$userId]);
$companies = $stmt->fetchAll();

// If no company exists, redirect to add company first
if (empty($companies)) {
    setFlash('warning', 'Please add a rental company first');
    header('Location: add-company.php');
    exit;
}

// Initialize session for wizard
if (!isset($_SESSION['car_wizard'])) {
    $_SESSION['car_wizard'] = [
        'step' => 1,
        'data' => []
    ];
}

$currentStep = $_SESSION['car_wizard']['step'] ?? 1;
$wizardData = $_SESSION['car_wizard']['data'] ?? [];
$error = '';
$success = '';

// Handle step navigation
if (isset($_POST['next_step'])) {
    $currentStep = intval($_POST['current_step']) + 1;
    $_SESSION['car_wizard']['step'] = $currentStep;
} elseif (isset($_POST['prev_step'])) {
    $currentStep = intval($_POST['current_step']) - 1;
    $_SESSION['car_wizard']['step'] = $currentStep;
}

// ============================================
// STEP 1: BASIC INFORMATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step1'])) {
    $step1Data = [
        'rental_id' => intval($_POST['rental_id'] ?? 0),
        'car_type' => sanitize($_POST['car_type'] ?? ''),
        'brand' => sanitize($_POST['brand'] ?? ''),
        'model' => sanitize($_POST['model'] ?? ''),
        'year' => intval($_POST['year'] ?? date('Y')),
        'transmission' => sanitize($_POST['transmission'] ?? 'manual'),
        'fuel_type' => sanitize($_POST['fuel_type'] ?? 'petrol'),
        'seats' => intval($_POST['seats'] ?? 5),
        'luggage_capacity' => intval($_POST['luggage_capacity'] ?? 2),
        'description' => sanitize($_POST['description'] ?? '')
    ];
    
    // Validate
    $errors = [];
    if ($step1Data['rental_id'] === 0) $errors[] = 'Please select a rental company';
    if (empty($step1Data['car_type'])) $errors[] = 'Car type is required';
    if (empty($step1Data['brand'])) $errors[] = 'Brand is required';
    if (empty($step1Data['model'])) $errors[] = 'Model is required';
    if ($step1Data['year'] < 2000 || $step1Data['year'] > date('Y') + 1) $errors[] = 'Please enter a valid year';
    
    if (empty($errors)) {
        $_SESSION['car_wizard']['data'] = array_merge($wizardData, $step1Data);
        $_SESSION['car_wizard']['step'] = 2;
        $currentStep = 2;
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// STEP 2: PRICING & AVAILABILITY
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step2'])) {
    $step2Data = [
        'daily_rate' => floatval($_POST['daily_rate'] ?? 0),
        'weekly_rate' => floatval($_POST['weekly_rate'] ?? 0),
        'monthly_rate' => floatval($_POST['monthly_rate'] ?? 0),
        'quantity_available' => intval($_POST['quantity_available'] ?? 1),
        'free_km_per_day' => intval($_POST['free_km_per_day'] ?? 100),
        'excess_km_charge' => floatval($_POST['excess_km_charge'] ?? 0),
        'insurance_included' => isset($_POST['insurance_included']) ? 1 : 0,
        'minimum_rental_days' => intval($_POST['minimum_rental_days'] ?? 1),
        'maximum_rental_days' => intval($_POST['maximum_rental_days'] ?? 30)
    ];
    
    // Validate
    $errors = [];
    if ($step2Data['daily_rate'] <= 0) $errors[] = 'Daily rate must be greater than 0';
    
    if (empty($errors)) {
        $_SESSION['car_wizard']['data'] = array_merge($wizardData, $step2Data);
        $_SESSION['car_wizard']['step'] = 3;
        $currentStep = 3;
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// STEP 3: FEATURES & OPTIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step3'])) {
    $step3Data = [
        'features' => $_POST['features'] ?? [],
        'custom_features' => $_POST['custom_features'] ?? []
    ];
    
    $_SESSION['car_wizard']['data'] = array_merge($wizardData, $step3Data);
    $_SESSION['car_wizard']['step'] = 4;
    $currentStep = 4;
}

// ============================================
// STEP 4: PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step4'])) {
    $uploadedPhotos = $_SESSION['car_wizard']['data']['photos'] ?? [];
    
    // Handle photo uploads
    if (!empty($_FILES['photos']['name'][0])) {
        // Create directory if not exists
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $files = $_FILES['photos'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // Validate file type
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                
                if (in_array($mimeType, $allowedTypes)) {
                    $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    $fileName = 'car_temp_' . time() . '_' . uniqid() . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                        $uploadedPhotos[] = $fileName;
                    }
                }
            }
        }
    }
    
    // Handle photo removal
    if (isset($_POST['remove_photo'])) {
        $photoToRemove = $_POST['remove_photo'];
        $uploadedPhotos = array_diff($uploadedPhotos, [$photoToRemove]);
        
        // Delete file
        $filePath = $uploadDir . $photoToRemove;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    $_SESSION['car_wizard']['data']['photos'] = $uploadedPhotos;
    
    if (isset($_POST['next_step'])) {
        if (count($uploadedPhotos) < 1) {
            $error = 'Please upload at least 1 photo';
        } else {
            $_SESSION['car_wizard']['step'] = 5;
            $currentStep = 5;
        }
    }
}

// ============================================
// STEP 5: REVIEW & SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vehicle'])) {
    $data = $_SESSION['car_wizard']['data'];
    
    // Combine features
    $allFeatures = array_merge($data['features'] ?? [], $data['custom_features'] ?? []);
    $featuresJson = json_encode($allFeatures);
    
    // Handle photos - move from temp to final (but we already saved in temp)
    $photosJson = !empty($data['photos']) ? json_encode($data['photos']) : null;
    
    // Check if created_at column exists
    $columns = $db->query("SHOW COLUMNS FROM car_fleet")->fetchAll(PDO::FETCH_COLUMN);
    
    try {
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
                $data['rental_id'],
                $data['car_type'],
                $data['brand'],
                $data['model'],
                $data['year'],
                $data['transmission'],
                $data['fuel_type'],
                $data['seats'],
                $data['luggage_capacity'],
                $data['daily_rate'],
                $data['weekly_rate'],
                $data['monthly_rate'],
                $data['quantity_available'],
                $data['free_km_per_day'],
                $data['excess_km_charge'],
                $data['insurance_included'],
                $featuresJson,
                $photosJson
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
                $data['rental_id'],
                $data['car_type'],
                $data['brand'],
                $data['model'],
                $data['year'],
                $data['transmission'],
                $data['fuel_type'],
                $data['seats'],
                $data['luggage_capacity'],
                $data['daily_rate'],
                $data['weekly_rate'],
                $data['monthly_rate'],
                $data['quantity_available'],
                $data['free_km_per_day'],
                $data['excess_km_charge'],
                $data['insurance_included'],
                $featuresJson,
                $photosJson
            ];
        }
        
        $stmt->execute($params);
        $carId = $db->lastInsertId();
        
        // Clear wizard session
        unset($_SESSION['car_wizard']);
        
        // Redirect to success page
        header('Location: vehicle-success.php?id=' . $carId);
        exit;
        
    } catch (PDOException $e) {
        $error = "Error saving vehicle: " . $e->getMessage();
    }
}

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

$stepTitles = [
    1 => 'Basic Information',
    2 => 'Pricing & Availability',
    3 => 'Features & Options',
    4 => 'Photos',
    5 => 'Review & Submit'
];

$stepDescriptions = [
    1 => 'Tell us about your vehicle',
    2 => 'Set your rates and availability',
    3 => 'What features does your vehicle have?',
    4 => 'Add photos of your vehicle',
    5 => 'Review your information before submitting'
];
?>

<style>
/* Wizard Container */
.wizard-container {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    overflow: hidden;
    margin-top: 20px;
}

/* Progress Header */
.wizard-progress {
    background: linear-gradient(135deg, var(--cars-primary), var(--cars-dark));
    color: white;
    padding: 30px;
}

.wizard-progress h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 8px 0;
}

.wizard-progress p {
    font-size: 0.875rem;
    opacity: 0.9;
    margin: 0 0 20px 0;
}

/* Step Indicators */
.step-indicators {
    display: flex;
    gap: 8px;
    margin-top: 20px;
}

.step-indicator {
    flex: 1;
    height: 4px;
    background: rgba(255,255,255,0.2);
    border-radius: 2px;
    position: relative;
    transition: all 0.3s;
}

.step-indicator.completed {
    background: var(--cars-success);
}

.step-indicator.active {
    background: white;
    box-shadow: 0 0 10px rgba(255,255,255,0.5);
}

.step-indicator.active::after {
    content: '';
    position: absolute;
    top: -6px;
    right: 0;
    width: 12px;
    height: 12px;
    background: white;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.5); opacity: 0.5; }
}

/* Step Labels */
.step-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 12px;
    font-size: 0.75rem;
    color: rgba(255,255,255,0.8);
}

.step-label {
    text-align: center;
    flex: 1;
}

.step-label.active {
    color: white;
    font-weight: 600;
}

/* Wizard Content */
.wizard-content {
    padding: 30px;
}

.step-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.step-description {
    font-size: 0.875rem;
    color: var(--text-light);
    margin-bottom: 30px;
}

/* Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 6px;
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
    padding: 10px 14px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--cars-primary);
    box-shadow: 0 0 0 3px rgba(255,140,0,0.1);
}

.form-control.error {
    border-color: var(--cars-danger);
}

.form-text {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 4px;
}

/* Features Grid */
.features-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    max-height: 300px;
    overflow-y: auto;
}

.feature-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    cursor: pointer;
    padding: 6px 8px;
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
    width: 16px;
    height: 16px;
    accent-color: var(--cars-primary);
}

/* Photo Upload */
.photo-upload-area {
    border: 2px dashed var(--border-gray);
    border-radius: var(--radius-md);
    padding: 40px;
    text-align: center;
    background: var(--bg-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 24px;
}

.photo-upload-area:hover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
}

.photo-upload-area i {
    font-size: 3rem;
    color: var(--text-light);
    margin-bottom: 12px;
}

.photo-upload-area h4 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.photo-upload-area p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
}

.photo-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--border-gray);
}

.photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-remove {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    opacity: 0;
}

.photo-item:hover .photo-remove {
    opacity: 1;
}

.photo-remove:hover {
    background: var(--cars-danger);
}

/* Review Section */
.review-section {
    background: var(--bg-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}

.review-section h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 15px;
    color: var(--cars-primary);
}

.review-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.review-item {
    display: flex;
    gap: 10px;
}

.review-item-label {
    font-size: 0.75rem;
    color: var(--text-light);
    min-width: 100px;
}

.review-item-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-dark);
}

.review-features {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.review-feature-tag {
    background: white;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.75rem;
    border: 1px solid var(--border-gray);
}

/* Action Buttons */
.wizard-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--border-gray);
}

.btn-wizard-prev {
    background: var(--white);
    color: var(--text-dark);
    border: 1px solid var(--border-gray);
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-wizard-prev:hover {
    background: var(--bg-gray);
}

.btn-wizard-next {
    background: var(--cars-primary);
    color: white;
    border: none;
    padding: 12px 32px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-wizard-next:hover {
    background: var(--cars-dark);
}

/* Custom Features */
.custom-feature-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.custom-feature-row input {
    flex: 1;
}

/* Alert */
.alert {
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-danger {
    background: #fce8e8;
    color: var(--cars-danger);
    border: 1px solid #fecaca;
}

.alert-success {
    background: #e6f4ea;
    color: var(--cars-success);
    border: 1px solid #a7f3d0;
}

/* Responsive */
@media (max-width: 768px) {
    .form-grid,
    .review-grid,
    .photo-grid,
    .features-grid {
        grid-template-columns: 1fr;
    }
    
    .wizard-content {
        padding: 20px;
    }
    
    .wizard-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .btn-wizard-prev,
    .btn-wizard-next {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="wizard-container">
    <!-- Progress Header -->
    <div class="wizard-progress">
        <h1>Add New Vehicle</h1>
        <p>Step <?php echo $currentStep; ?> of 5: <?php echo $stepTitles[$currentStep]; ?></p>
        
        <!-- Progress Bar -->
        <div class="step-indicators">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="step-indicator 
                <?php echo $i < $currentStep ? 'completed' : ''; ?> 
                <?php echo $i == $currentStep ? 'active' : ''; ?>">
            </div>
            <?php endfor; ?>
        </div>
        
        <!-- Step Labels -->
        <div class="step-labels">
            <div class="step-label <?php echo $currentStep == 1 ? 'active' : ''; ?>">Basic Info</div>
            <div class="step-label <?php echo $currentStep == 2 ? 'active' : ''; ?>">Pricing</div>
            <div class="step-label <?php echo $currentStep == 3 ? 'active' : ''; ?>">Features</div>
            <div class="step-label <?php echo $currentStep == 4 ? 'active' : ''; ?>">Photos</div>
            <div class="step-label <?php echo $currentStep == 5 ? 'active' : ''; ?>">Review</div>
        </div>
    </div>
    
    <!-- Wizard Content -->
    <div class="wizard-content">
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <!-- STEP 1: BASIC INFORMATION -->
        <?php if ($currentStep == 1): ?>
        <form method="POST" id="step1Form">
            <h2 class="step-title"><?php echo $stepTitles[1]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[1]; ?></p>
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label">Rental Company <span class="required">*</span></label>
                    <select name="rental_id" class="form-control" required>
                        <option value="">Select Company</option>
                        <?php foreach ($companies as $company): ?>
                        <option value="<?php echo $company['rental_id']; ?>" <?php echo ($wizardData['rental_id'] ?? '') == $company['rental_id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($company['company_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Car Type <span class="required">*</span></label>
                    <select name="car_type" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($carTypeOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($wizardData['car_type'] ?? '') == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Brand <span class="required">*</span></label>
                    <input type="text" name="brand" class="form-control" value="<?php echo $wizardData['brand'] ?? ''; ?>" placeholder="e.g., Toyota" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Model <span class="required">*</span></label>
                    <input type="text" name="model" class="form-control" value="<?php echo $wizardData['model'] ?? ''; ?>" placeholder="e.g., RAV4" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Year <span class="required">*</span></label>
                    <input type="number" name="year" class="form-control" value="<?php echo $wizardData['year'] ?? date('Y'); ?>" min="2000" max="<?php echo date('Y') + 1; ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Transmission</label>
                    <select name="transmission" class="form-control">
                        <?php foreach ($transmissionOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($wizardData['transmission'] ?? 'manual') == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Fuel Type</label>
                    <select name="fuel_type" class="form-control">
                        <?php foreach ($fuelTypeOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($wizardData['fuel_type'] ?? 'petrol') == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Seats</label>
                    <input type="number" name="seats" class="form-control" value="<?php echo $wizardData['seats'] ?? 5; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Luggage Capacity</label>
                    <input type="number" name="luggage_capacity" class="form-control" value="<?php echo $wizardData['luggage_capacity'] ?? 2; ?>" min="0" placeholder="Number of bags">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Describe your vehicle, its condition, special features..."><?php echo $wizardData['description'] ?? ''; ?></textarea>
                </div>
            </div>
            
            <div class="wizard-actions">
                <a href="fleet.php" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" name="save_step1" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 2: PRICING & AVAILABILITY -->
        <?php elseif ($currentStep == 2): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[2]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[2]; ?></p>
            
            <input type="hidden" name="current_step" value="2">
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Daily Rate (RWF) <span class="required">*</span></label>
                    <input type="number" name="daily_rate" class="form-control" value="<?php echo $wizardData['daily_rate'] ?? ''; ?>" min="0" step="1000" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Weekly Rate (RWF)</label>
                    <input type="number" name="weekly_rate" class="form-control" value="<?php echo $wizardData['weekly_rate'] ?? ''; ?>" min="0" step="1000">
                    <div class="form-text">Recommended: 6-7 × daily rate</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Monthly Rate (RWF)</label>
                    <input type="number" name="monthly_rate" class="form-control" value="<?php echo $wizardData['monthly_rate'] ?? ''; ?>" min="0" step="1000">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quantity Available</label>
                    <input type="number" name="quantity_available" class="form-control" value="<?php echo $wizardData['quantity_available'] ?? 1; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Free Km per Day</label>
                    <input type="number" name="free_km_per_day" class="form-control" value="<?php echo $wizardData['free_km_per_day'] ?? 100; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Excess Km Charge (RWF)</label>
                    <input type="number" name="excess_km_charge" class="form-control" value="<?php echo $wizardData['excess_km_charge'] ?? 0; ?>" min="0" step="100" placeholder="per km">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Minimum Rental (days)</label>
                    <input type="number" name="minimum_rental_days" class="form-control" value="<?php echo $wizardData['minimum_rental_days'] ?? 1; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum Rental (days)</label>
                    <input type="number" name="maximum_rental_days" class="form-control" value="<?php echo $wizardData['maximum_rental_days'] ?? 30; ?>" min="1">
                </div>
                
                <div class="form-group full-width">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="insurance_included" id="insurance_included" value="1" <?php echo isset($wizardData['insurance_included']) && $wizardData['insurance_included'] ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                        <label for="insurance_included" style="font-size: 0.875rem;">Insurance Included in Price</label>
                    </div>
                </div>
            </div>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="1" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="save_step2" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 3: FEATURES & OPTIONS -->
        <?php elseif ($currentStep == 3): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[3]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[3]; ?></p>
            
            <input type="hidden" name="current_step" value="3">
            
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 15px;">Standard Features</h3>
                <div class="features-grid" id="featuresContainer">
                    <?php foreach ($featureOptions as $key => $label): ?>
                    <label class="feature-checkbox">
                        <input type="checkbox" name="features[]" value="<?php echo $key; ?>" 
                               <?php echo in_array($key, $wizardData['features'] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 15px;">Custom Features</h3>
                <div id="custom-features-container">
                    <?php 
                    $customFeatures = $wizardData['custom_features'] ?? [];
                    if (empty($customFeatures)) {
                        $customFeatures = [''];
                    }
                    foreach ($customFeatures as $index => $feature): 
                    ?>
                    <div class="custom-feature-row">
                        <input type="text" name="custom_features[]" class="form-control" value="<?php echo htmlspecialchars($feature); ?>" placeholder="e.g., Baby seat, Winter tires">
                        <button type="button" class="btn-outline btn-sm" onclick="this.parentElement.remove()">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-outline btn-sm" onclick="addCustomFeature()" style="margin-top: 10px;">
                    <i class="bi bi-plus-lg"></i> Add Custom Feature
                </button>
            </div>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="2" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="save_step3" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 4: PHOTOS -->
        <?php elseif ($currentStep == 4): ?>
        <form method="POST" enctype="multipart/form-data" id="photoForm">
            <h2 class="step-title"><?php echo $stepTitles[4]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[4]; ?></p>
            
            <input type="hidden" name="current_step" value="4">
            
            <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                <i class="bi bi-cloud-upload"></i>
                <h4>Click to upload photos</h4>
                <p>or drag and drop (JPG, PNG) - At least 1 photo required</p>
                <input type="file" name="photos[]" id="photoInput" multiple accept="image/*" style="display: none;">
            </div>
            
            <?php if (!empty($wizardData['photos'])): ?>
            <div class="photo-grid" id="photoGrid">
                <?php foreach ($wizardData['photos'] as $photo): ?>
                <div class="photo-item">
                    <img src="/gorwanda-plus/assets/images/cars/<?php echo $photo; ?>" alt="Vehicle photo">
                    <button type="submit" name="remove_photo" value="<?php echo $photo; ?>" class="photo-remove" onclick="return confirm('Remove this photo?')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="3" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="upload_photos" class="btn-wizard-next" style="background: var(--cars-success);">
                    <i class="bi bi-upload"></i> Upload Photos
                </button>
                <button type="submit" name="next_step" value="4" class="btn-wizard-next">
                    Skip for now <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 5: REVIEW & SUBMIT -->
        <?php elseif ($currentStep == 5): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[5]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[5]; ?></p>
            
            <!-- Basic Info Review -->
            <div class="review-section">
                <h3>Basic Information</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-item-label">Company:</span>
                        <span class="review-item-value">
                            <?php 
                            $companyName = '';
                            foreach ($companies as $c) {
                                if ($c['rental_id'] == ($wizardData['rental_id'] ?? 0)) {
                                    $companyName = $c['company_name'];
                                    break;
                                }
                            }
                            echo sanitize($companyName);
                            ?>
                        </span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Car Type:</span>
                        <span class="review-item-value"><?php echo $carTypeOptions[$wizardData['car_type']] ?? $wizardData['car_type']; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Brand/Model:</span>
                        <span class="review-item-value"><?php echo sanitize($wizardData['brand'] . ' ' . $wizardData['model']); ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Year:</span>
                        <span class="review-item-value"><?php echo $wizardData['year']; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Transmission:</span>
                        <span class="review-item-value"><?php echo $transmissionOptions[$wizardData['transmission']] ?? $wizardData['transmission']; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Fuel:</span>
                        <span class="review-item-value"><?php echo $fuelTypeOptions[$wizardData['fuel_type']] ?? $wizardData['fuel_type']; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Seats:</span>
                        <span class="review-item-value"><?php echo $wizardData['seats']; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Luggage:</span>
                        <span class="review-item-value"><?php echo $wizardData['luggage_capacity']; ?> bags</span>
                    </div>
                </div>
            </div>
            
            <!-- Pricing Review -->
            <div class="review-section">
                <h3>Pricing & Availability</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-item-label">Daily Rate:</span>
                        <span class="review-item-value"><?php echo formatPrice($wizardData['daily_rate']); ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Weekly Rate:</span>
                        <span class="review-item-value"><?php echo $wizardData['weekly_rate'] ? formatPrice($wizardData['weekly_rate']) : 'N/A'; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Monthly Rate:</span>
                        <span class="review-item-value"><?php echo $wizardData['monthly_rate'] ? formatPrice($wizardData['monthly_rate']) : 'N/A'; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Available:</span>
                        <span class="review-item-value"><?php echo $wizardData['quantity_available']; ?> vehicles</span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Free Km:</span>
                        <span class="review-item-value"><?php echo $wizardData['free_km_per_day']; ?> km/day</span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Excess Km:</span>
                        <span class="review-item-value"><?php echo formatPrice($wizardData['excess_km_charge']); ?>/km</span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Insurance:</span>
                        <span class="review-item-value"><?php echo $wizardData['insurance_included'] ? 'Included' : 'Not included'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Features Review -->
            <?php 
            $allFeatures = array_merge($wizardData['features'] ?? [], $wizardData['custom_features'] ?? []);
            if (!empty($allFeatures)): 
            ?>
            <div class="review-section">
                <h3>Features</h3>
                <div class="review-features">
                    <?php foreach ($allFeatures as $feature): ?>
                    <span class="review-feature-tag">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <?php echo $featureOptions[$feature] ?? $feature; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Photos Review -->
            <?php if (!empty($wizardData['photos'])): ?>
            <div class="review-section">
                <h3>Photos (<?php echo count($wizardData['photos']); ?>)</h3>
                <div class="photo-grid" style="grid-template-columns: repeat(4, 1fr);">
                    <?php foreach (array_slice($wizardData['photos'], 0, 4) as $photo): ?>
                    <div class="photo-item">
                        <img src="/gorwanda-plus/assets/images/cars/<?php echo $photo; ?>" alt="Vehicle photo">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="4" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="submit_vehicle" class="btn-wizard-next" style="background: var(--cars-success);">
                    <i class="bi bi-check-lg"></i> Submit Vehicle
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
let customFeatureCount = <?php echo count($wizardData['custom_features'] ?? ['']); ?>;

function addCustomFeature() {
    const container = document.getElementById('custom-features-container');
    const div = document.createElement('div');
    div.className = 'custom-feature-row';
    div.innerHTML = `
        <input type="text" name="custom_features[]" class="form-control" placeholder="e.g., Baby seat, Winter tires">
        <button type="button" class="btn-outline btn-sm" onclick="this.parentElement.remove()">
            <i class="bi bi-trash"></i>
        </button>
    `;
    container.appendChild(div);
}

// Photo upload preview
document.getElementById('photoInput')?.addEventListener('change', function() {
    // Auto-submit when files are selected
    document.getElementById('photoForm').submit();
});

// Drag and drop
const uploadArea = document.querySelector('.photo-upload-area');
if (uploadArea) {
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('photoInput').files = files;
            document.getElementById('photoForm').submit();
        }
    });
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>