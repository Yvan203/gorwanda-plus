<?php
$pageTitle = 'Add New Experience';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Define upload paths
$uploadMainDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/';
$uploadGalleryDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/gallery/';

// Create directories if they don't exist
if (!file_exists($uploadMainDir)) {
    mkdir($uploadMainDir, 0777, true);
}
if (!file_exists($uploadGalleryDir)) {
    mkdir($uploadGalleryDir, 0777, true);
}

// Initialize session for wizard
if (!isset($_SESSION['experience_wizard'])) {
    $_SESSION['experience_wizard'] = [
        'step' => 1,
        'data' => []
    ];
}

$currentStep = $_SESSION['experience_wizard']['step'] ?? 1;
$wizardData = $_SESSION['experience_wizard']['data'] ?? [];
$error = '';
$success = '';

// Handle step navigation
if (isset($_POST['next_step'])) {
    $currentStep = intval($_POST['current_step']) + 1;
    $_SESSION['experience_wizard']['step'] = $currentStep;
} elseif (isset($_POST['prev_step'])) {
    $currentStep = intval($_POST['current_step']) - 1;
    $_SESSION['experience_wizard']['step'] = $currentStep;
}

// ============================================
// STEP 1: BASIC INFORMATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step1'])) {
    $step1Data = [
        'attraction_name' => sanitize($_POST['attraction_name'] ?? ''),
        'category_id' => intval($_POST['category_id'] ?? 0),
        'description' => sanitize($_POST['description'] ?? ''),
        'location_id' => intval($_POST['location_id'] ?? 0),
        'address' => sanitize($_POST['address'] ?? ''),
        'meeting_point' => sanitize($_POST['meeting_point'] ?? '')
    ];
    
    // Validate
    $errors = [];
    if (empty($step1Data['attraction_name'])) $errors[] = 'Experience name is required';
    if (empty($step1Data['category_id'])) $errors[] = 'Category is required';
    
    if (empty($errors)) {
        $_SESSION['experience_wizard']['data'] = array_merge($wizardData, $step1Data);
        $_SESSION['experience_wizard']['step'] = 2;
        $currentStep = 2;
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// STEP 2: LOGISTICS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step2'])) {
    $step2Data = [
        'duration_minutes' => intval($_POST['duration_minutes'] ?? 60),
        'difficulty_level' => sanitize($_POST['difficulty_level'] ?? 'easy'),
        'min_age' => intval($_POST['min_age'] ?? 0),
        'max_group_size' => intval($_POST['max_group_size'] ?? 10),
        'included_items' => $_POST['included_items'] ?? [],
        'what_to_bring' => $_POST['what_to_bring'] ?? [],
        'start_times' => $_POST['start_times'] ?? ['09:00']
    ];
    
    $_SESSION['experience_wizard']['data'] = array_merge($wizardData, $step2Data);
    $_SESSION['experience_wizard']['step'] = 3;
    $currentStep = 3;
}

// ============================================
// STEP 3: PRICING TIERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step3'])) {
    $tiers = [];
    $tierCount = intval($_POST['tier_count'] ?? 0);
    
    for ($i = 0; $i < $tierCount; $i++) {
        if (isset($_POST['tier_name_' . $i])) {
            $tiers[] = [
                'name' => sanitize($_POST['tier_name_' . $i]),
                'description' => sanitize($_POST['tier_description_' . $i] ?? ''),
                'price' => floatval($_POST['tier_price_' . $i] ?? 0),
                'price_type' => sanitize($_POST['tier_price_type_' . $i] ?? 'per_person'),
                'max_participants' => intval($_POST['tier_max_participants_' . $i] ?? 0),
                'inclusions' => $_POST['tier_inclusions_' . $i] ?? []
            ];
        }
    }
    
    // Ensure at least one tier
    if (empty($tiers)) {
        $tiers = [
            [
                'name' => 'Standard',
                'description' => 'Standard experience',
                'price' => 50000,
                'price_type' => 'per_person',
                'max_participants' => 10,
                'inclusions' => []
            ]
        ];
    }
    
    $_SESSION['experience_wizard']['data']['tiers'] = $tiers;
    $_SESSION['experience_wizard']['step'] = 4;
    $currentStep = 4;
}

// ============================================
// STEP 4: PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step4'])) {
    $uploadedMainImage = $_SESSION['experience_wizard']['data']['main_image'] ?? '';
    $uploadedPhotos = $_SESSION['experience_wizard']['data']['gallery'] ?? [];
    
    // Handle main image upload
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['main_image'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (in_array($mimeType, $allowedTypes) && $file['size'] <= 10 * 1024 * 1024) {
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = 'exp_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $uploadMainDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $uploadedMainImage = $fileName;
            }
        }
    }
    
    // Handle gallery images upload
    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $files = $_FILES['gallery_images'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                
                if (in_array($mimeType, $allowedTypes) && $files['size'][$i] <= 10 * 1024 * 1024) {
                    $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    $fileName = 'exp_gallery_' . time() . '_' . uniqid() . '.' . $fileExt;
                    $uploadPath = $uploadGalleryDir . $fileName;
                    
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
        
        $filePath = $uploadGalleryDir . $photoToRemove;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    $_SESSION['experience_wizard']['data']['main_image'] = $uploadedMainImage;
    $_SESSION['experience_wizard']['data']['gallery'] = $uploadedPhotos;
    
    if (isset($_POST['next_step'])) {
        if (empty($uploadedMainImage)) {
            $error = 'Please upload a main image';
        } else {
            $_SESSION['experience_wizard']['step'] = 5;
            $currentStep = 5;
        }
    }
}

// ============================================
// STEP 5: REVIEW & SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_experience'])) {
    $data = $_SESSION['experience_wizard']['data'];
    
    // Generate slug
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($data['attraction_name']));
    $slug = trim($slug, '-') . '-' . time();
    
    // Prepare JSON data
    $includedItemsJson = json_encode($data['included_items'] ?? []);
    $whatToBringJson = json_encode($data['what_to_bring'] ?? []);
    $startTimesJson = json_encode($data['start_times'] ?? ['09:00']);
    $galleryJson = !empty($data['gallery']) ? json_encode($data['gallery']) : null;
    
    // Insert attraction
    $stmt = $db->prepare("
        INSERT INTO attractions (
            owner_id, attraction_name, slug, category_id, description,
            location_id, address, meeting_point, duration_minutes,
            difficulty_level, min_age, max_group_size, included_items,
            what_to_bring, start_times, cancellation_policy, main_image,
            gallery_images, is_active, is_verified, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())
    ");
    
    $stmt->execute([
        $userId,
        $data['attraction_name'],
        $slug,
        $data['category_id'],
        $data['description'],
        $data['location_id'] ?: null,
        $data['address'] ?? '',
        $data['meeting_point'] ?? '',
        $data['duration_minutes'] ?? 60,
        $data['difficulty_level'] ?? 'easy',
        $data['min_age'] ?? 0,
        $data['max_group_size'] ?? 10,
        $includedItemsJson,
        $whatToBringJson,
        $startTimesJson,
        $data['cancellation_policy'] ?? '',
        $data['main_image'] ?? null,
        $galleryJson
    ]);
    
    $attractionId = $db->lastInsertId();
    
    // Insert pricing tiers
    if (!empty($data['tiers'])) {
        $tierStmt = $db->prepare("
            INSERT INTO attraction_tiers (
                attraction_id, tier_name, description, base_price,
                price_type, max_participants, inclusions, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        foreach ($data['tiers'] as $tier) {
            $inclusionsJson = json_encode($tier['inclusions'] ?? []);
            $tierStmt->execute([
                $attractionId,
                $tier['name'],
                $tier['description'],
                $tier['price'],
                $tier['price_type'],
                $tier['max_participants'] ?: null,
                $inclusionsJson
            ]);
        }
    }
    
    // Clear wizard session
    unset($_SESSION['experience_wizard']);
    
    // Redirect to success page
    header('Location: listing-success.php?id=' . $attractionId);
    exit;
}

// ============================================
// GET DATA FOR DROPDOWNS
// ============================================

// Get categories
$categories = $db->query("SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get locations
$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();

// Common items for checkboxes
$commonIncludedItems = [
    'guide' => 'Professional Guide',
    'park_fees' => 'Park Entrance Fees',
    'transport' => 'Transportation',
    'lunch' => 'Lunch',
    'water' => 'Bottled Water',
    'equipment' => 'Equipment Rental',
    'snacks' => 'Snacks',
    'drinks' => 'Drinks',
    'insurance' => 'Insurance',
    'photos' => 'Photos',
    'souvenir' => 'Souvenir',
    'pickup' => 'Hotel Pickup'
];

$commonBringItems = [
    'hiking_boots' => 'Hiking Boots',
    'rain_jacket' => 'Rain Jacket',
    'camera' => 'Camera',
    'water' => 'Water Bottle',
    'sunscreen' => 'Sunscreen',
    'hat' => 'Hat',
    'sunglasses' => 'Sunglasses',
    'snacks' => 'Snacks',
    'backpack' => 'Backpack',
    'binoculars' => 'Binoculars',
    'swimsuit' => 'Swimsuit',
    'towel' => 'Towel'
];

$difficultyLevels = [
    'easy' => 'Easy - Suitable for all fitness levels',
    'moderate' => 'Moderate - Some physical activity required',
    'challenging' => 'Challenging - High fitness level required'
];

$priceTypes = [
    'per_person' => 'Per Person',
    'per_group' => 'Per Group',
    'per_couple' => 'Per Couple'
];

$stepTitles = [
    1 => 'Basic Information',
    2 => 'Logistics & What\'s Included',
    3 => 'Pricing Tiers',
    4 => 'Photos',
    5 => 'Review & Submit'
];

$stepDescriptions = [
    1 => 'Tell us about your experience',
    2 => 'Set duration, difficulty, and what\'s included',
    3 => 'Define pricing options for your experience',
    4 => 'Upload photos to showcase your experience',
    5 => 'Review your information before submitting'
];
?>

<style>
/* Wizard Container */
.wizard-container {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    margin-bottom: 30px;
}

/* Progress Header */
.wizard-progress {
    background: linear-gradient(135deg, var(--exp-purple), var(--exp-dark-purple));
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
    background: var(--exp-success);
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
    color: var(--exp-text);
    margin-bottom: 8px;
}

.step-description {
    font-size: 0.875rem;
    color: var(--exp-text-light);
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
    color: var(--exp-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label .required {
    color: var(--exp-danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Checkbox Grid */
.checkbox-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px;
    background: var(--exp-gray);
    border-radius: var(--radius-sm);
    max-height: 250px;
    overflow-y: auto;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    cursor: pointer;
    padding: 6px 8px;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
    background: white;
    border: 1px solid var(--exp-border);
}

.checkbox-item:hover {
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
}

.checkbox-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--exp-purple);
}

/* Start Times */
.start-times-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.time-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.time-row input {
    flex: 1;
}

.time-remove {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1px solid var(--exp-border);
    background: white;
    color: var(--exp-danger);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

/* Tier Cards */
.tier-card {
    background: var(--exp-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--exp-border);
    position: relative;
}

.tier-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.tier-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--exp-purple);
}

.tier-card-remove {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: white;
    color: var(--exp-danger);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.tier-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

/* Photo Upload */
.photo-upload-area {
    border: 2px dashed var(--exp-border);
    border-radius: var(--radius-md);
    padding: 40px;
    text-align: center;
    background: var(--exp-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 24px;
}

.photo-upload-area:hover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
}

.photo-upload-area i {
    font-size: 3rem;
    color: var(--exp-text-light);
    margin-bottom: 12px;
}

.photo-upload-area h4 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.photo-upload-area p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
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
    border: 1px solid var(--exp-border);
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
    opacity: 0;
    transition: opacity 0.2s;
}

.photo-item:hover .photo-remove {
    opacity: 1;
}

.photo-remove:hover {
    background: var(--exp-danger);
}

.main-image-preview {
    max-width: 200px;
    margin-top: 16px;
}

.main-image-preview img {
    width: 100%;
    border-radius: var(--radius-sm);
    border: 2px solid var(--exp-purple);
}

/* Review Section */
.review-section {
    background: var(--exp-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}

.review-section h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 15px;
    color: var(--exp-purple);
}

.review-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.review-item {
    display: flex;
    flex-direction: column;
}

.review-item-label {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
    margin-bottom: 2px;
}

.review-item-value {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--exp-text);
}

.review-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.review-tag {
    background: white;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.75rem;
    border: 1px solid var(--exp-border);
}

/* Action Buttons */
.wizard-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--exp-border);
}

.btn-wizard-prev {
    background: white;
    color: var(--exp-text);
    border: 1px solid var(--exp-border);
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
    background: var(--exp-gray);
}

.btn-wizard-next {
    background: var(--exp-purple);
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
    background: var(--exp-dark-purple);
}

.btn-add-tier {
    background: white;
    color: var(--exp-purple);
    border: 1px dashed var(--exp-purple);
    padding: 15px;
    border-radius: var(--radius-sm);
    width: 100%;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-add-tier:hover {
    background: var(--exp-light-purple);
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
    color: var(--exp-danger);
    border: 1px solid #fecaca;
}

/* Responsive */
@media (max-width: 768px) {
    .form-grid,
    .tier-grid,
    .review-grid,
    .checkbox-grid,
    .photo-grid {
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
        <h1>Add New Experience</h1>
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
            <div class="step-label <?php echo $currentStep == 2 ? 'active' : ''; ?>">Logistics</div>
            <div class="step-label <?php echo $currentStep == 3 ? 'active' : ''; ?>">Pricing</div>
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
            
            <input type="hidden" name="current_step" value="1">
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label">Experience Name <span class="required">*</span></label>
                    <input type="text" name="attraction_name" class="form-control" 
                           value="<?php echo $wizardData['attraction_name'] ?? ''; ?>" 
                           placeholder="e.g., Gorilla Trekking in Volcanoes National Park" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Category <span class="required">*</span></label>
                    <select name="category_id" class="form-control" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>" 
                            <?php echo ($wizardData['category_id'] ?? '') == $cat['category_id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['location_id']; ?>"
                            <?php echo ($wizardData['location_id'] ?? '') == $loc['location_id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($loc['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Meeting Point / Address</label>
                    <input type="text" name="address" class="form-control" 
                           value="<?php echo $wizardData['address'] ?? ''; ?>" 
                           placeholder="Specific meeting point or address">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Detailed Meeting Instructions</label>
                    <textarea name="meeting_point" class="form-control" rows="2" 
                              placeholder="Where exactly should guests meet?"><?php echo $wizardData['meeting_point'] ?? ''; ?></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4" 
                              placeholder="Describe your experience, what makes it special..."><?php echo $wizardData['description'] ?? ''; ?></textarea>
                </div>
            </div>
            
            <div class="wizard-actions">
                <a href="listings.php" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" name="save_step1" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 2: LOGISTICS -->
        <?php elseif ($currentStep == 2): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[2]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[2]; ?></p>
            
            <input type="hidden" name="current_step" value="2">
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Duration (minutes)</label>
                    <input type="number" name="duration_minutes" class="form-control" 
                           value="<?php echo $wizardData['duration_minutes'] ?? 60; ?>" min="15" step="15">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Difficulty Level</label>
                    <select name="difficulty_level" class="form-control">
                        <?php foreach ($difficultyLevels as $key => $label): ?>
                        <option value="<?php echo $key; ?>" 
                            <?php echo ($wizardData['difficulty_level'] ?? 'easy') == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Minimum Age</label>
                    <input type="number" name="min_age" class="form-control" 
                           value="<?php echo $wizardData['min_age'] ?? 0; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum Group Size</label>
                    <input type="number" name="max_group_size" class="form-control" 
                           value="<?php echo $wizardData['max_group_size'] ?? 10; ?>" min="1">
                </div>
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">What's Included</label>
                <div class="checkbox-grid">
                    <?php foreach ($commonIncludedItems as $key => $label): ?>
                    <label class="checkbox-item">
                        <input type="checkbox" name="included_items[]" value="<?php echo $key; ?>"
                               <?php echo in_array($key, $wizardData['included_items'] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">What to Bring</label>
                <div class="checkbox-grid">
                    <?php foreach ($commonBringItems as $key => $label): ?>
                    <label class="checkbox-item">
                        <input type="checkbox" name="what_to_bring[]" value="<?php echo $key; ?>"
                               <?php echo in_array($key, $wizardData['what_to_bring'] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">Available Start Times</label>
                <div class="start-times-container" id="startTimesContainer">
                    <?php 
                    $startTimes = $wizardData['start_times'] ?? ['09:00'];
                    foreach ($startTimes as $index => $time): 
                    ?>
                    <div class="time-row">
                        <input type="time" name="start_times[]" class="form-control" value="<?php echo $time; ?>">
                        <?php if ($index > 0): ?>
                        <button type="button" class="time-remove" onclick="removeTimeRow(this)">
                            <i class="bi bi-dash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-outline btn-sm" onclick="addTimeRow()" style="margin-top: 8px;">
                    <i class="bi bi-plus"></i> Add Another Time
                </button>
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
        
        <!-- STEP 3: PRICING TIERS -->
        <?php elseif ($currentStep == 3): ?>
        <form method="POST" id="tiersForm">
            <h2 class="step-title"><?php echo $stepTitles[3]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[3]; ?></p>
            
            <input type="hidden" name="current_step" value="3">
            <input type="hidden" name="tier_count" id="tier_count" value="<?php echo count($wizardData['tiers'] ?? [1]); ?>">
            
            <div id="tiers-container">
                <?php 
                $tiers = $wizardData['tiers'] ?? [
                    [
                        'name' => 'Standard',
                        'description' => 'Standard experience',
                        'price' => 50000,
                        'price_type' => 'per_person',
                        'max_participants' => 10,
                        'inclusions' => []
                    ]
                ];
                foreach ($tiers as $index => $tier): 
                ?>
                <div class="tier-card" id="tier_<?php echo $index; ?>">
                    <div class="tier-card-header">
                        <h4 class="tier-card-title">Tier <?php echo $index + 1; ?></h4>
                        <?php if ($index > 0): ?>
                        <button type="button" class="tier-card-remove" onclick="removeTier(<?php echo $index; ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="tier-grid">
                        <div class="form-group">
                            <label class="form-label">Tier Name</label>
                            <input type="text" name="tier_name_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $tier['name']; ?>" placeholder="e.g., Standard, Premium">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Price (RWF)</label>
                            <input type="number" name="tier_price_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $tier['price']; ?>" min="0" step="1000">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Price Type</label>
                            <select name="tier_price_type_<?php echo $index; ?>" class="form-control">
                                <?php foreach ($priceTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>" 
                                    <?php echo ($tier['price_type'] ?? 'per_person') == $key ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Max Participants</label>
                            <input type="number" name="tier_max_participants_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $tier['max_participants']; ?>" min="0">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Description</label>
                            <textarea name="tier_description_<?php echo $index; ?>" class="form-control" rows="2"><?php echo $tier['description']; ?></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Tier-Specific Inclusions</label>
                            <div class="checkbox-grid" style="max-height: 150px;">
                                <?php foreach ($commonIncludedItems as $key => $label): ?>
                                <label class="checkbox-item">
                                    <input type="checkbox" name="tier_inclusions_<?php echo $index; ?>[]" value="<?php echo $key; ?>"
                                           <?php echo in_array($key, $tier['inclusions'] ?? []) ? 'checked' : ''; ?>>
                                    <?php echo $label; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="btn-add-tier" onclick="addTier()">
                <i class="bi bi-plus-lg"></i> Add Another Pricing Tier
            </button>
            
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
            
            <!-- Main Image -->
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px;">Main Image <span class="required">*</span></h3>
                <div class="photo-upload-area" onclick="document.getElementById('main_image').click()">
                    <i class="bi bi-cloud-upload"></i>
                    <h4>Click to upload main image</h4>
                    <p>This will be the cover image for your experience</p>
                    <input type="file" name="main_image" id="main_image" accept="image/*" style="display: none;">
                </div>
                
                <?php if (!empty($wizardData['main_image'])): ?>
                <div class="main-image-preview">
                    <img src="/gorwanda-plus/assets/images/attractions/<?php echo $wizardData['main_image']; ?>" alt="Main image">
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Gallery Images -->
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px;">Gallery Images</h3>
                <div class="photo-upload-area" onclick="document.getElementById('gallery_images').click()">
                    <i class="bi bi-images"></i>
                    <h4>Click to upload gallery images</h4>
                    <p>You can select multiple images</p>
                    <input type="file" name="gallery_images[]" id="gallery_images" accept="image/*" multiple style="display: none;">
                </div>
                
                <?php if (!empty($wizardData['gallery'])): ?>
                <div class="photo-grid" id="galleryPreview">
                    <?php foreach ($wizardData['gallery'] as $photo): ?>
                    <div class="photo-item">
                        <img src="/gorwanda-plus/assets/images/attractions/gallery/<?php echo $photo; ?>" alt="Gallery image">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="remove_photo" value="<?php echo $photo; ?>">
                            <button type="submit" class="photo-remove" onclick="return confirm('Remove this photo?')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="3" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="upload_photos" class="btn-wizard-next" style="background: var(--exp-success);">
                    <i class="bi bi-upload"></i> Upload Photos
                </button>
                <button type="submit" name="next_step" value="4" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
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
                        <span class="review-item-label">Experience Name</span>
                        <span class="review-item-value"><?php echo $wizardData['attraction_name'] ?? ''; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Category</span>
                        <span class="review-item-value">
                            <?php 
                            $catName = '';
                            foreach ($categories as $cat) {
                                if ($cat['category_id'] == ($wizardData['category_id'] ?? 0)) {
                                    $catName = $cat['name'];
                                    break;
                                }
                            }
                            echo $catName;
                            ?>
                        </span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Location</span>
                        <span class="review-item-value">
                            <?php 
                            $locName = '';
                            foreach ($locations as $loc) {
                                if ($loc['location_id'] == ($wizardData['location_id'] ?? 0)) {
                                    $locName = $loc['name'];
                                    break;
                                }
                            }
                            echo $locName ?: 'Not specified';
                            ?>
                        </span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Meeting Point</span>
                        <span class="review-item-value"><?php echo $wizardData['meeting_point'] ?? 'Not specified'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Logistics Review -->
            <div class="review-section">
                <h3>Logistics</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-item-label">Duration</span>
                        <span class="review-item-value"><?php echo $wizardData['duration_minutes'] ?? 60; ?> minutes</span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Difficulty</span>
                        <span class="review-item-value"><?php echo ucfirst($wizardData['difficulty_level'] ?? 'easy'); ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Min Age</span>
                        <span class="review-item-value"><?php echo $wizardData['min_age'] ?? 0; ?>+</span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Max Group</span>
                        <span class="review-item-value"><?php echo $wizardData['max_group_size'] ?? 10; ?> people</span>
                    </div>
                </div>
                
                <?php if (!empty($wizardData['included_items'])): ?>
                <div style="margin-top: 15px;">
                    <div class="review-item-label">What's Included</div>
                    <div class="review-tags">
                        <?php foreach ($wizardData['included_items'] as $item): ?>
                        <span class="review-tag"><?php echo $commonIncludedItems[$item] ?? $item; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($wizardData['what_to_bring'])): ?>
                <div style="margin-top: 15px;">
                    <div class="review-item-label">What to Bring</div>
                    <div class="review-tags">
                        <?php foreach ($wizardData['what_to_bring'] as $item): ?>
                        <span class="review-tag"><?php echo $commonBringItems[$item] ?? $item; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($wizardData['start_times'])): ?>
                <div style="margin-top: 15px;">
                    <div class="review-item-label">Start Times</div>
                    <div class="review-tags">
                        <?php foreach ($wizardData['start_times'] as $time): ?>
                        <span class="review-tag"><?php echo $time; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Pricing Tiers Review -->
            <div class="review-section">
                <h3>Pricing Tiers</h3>
                <?php foreach ($wizardData['tiers'] ?? [] as $index => $tier): ?>
                <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--exp-border);">
                    <div style="font-weight: 700; color: var(--exp-purple);"><?php echo $tier['name']; ?></div>
                    <div style="font-size: 0.875rem; color: var(--exp-text-light);"><?php echo $tier['description']; ?></div>
                    <div style="margin-top: 5px;">
                        <span style="font-weight: 700;"><?php echo formatPrice($tier['price']); ?></span>
                        <span style="font-size: 0.75rem; color: var(--exp-text-light);"> / <?php echo str_replace('_', ' ', $tier['price_type']); ?></span>
                        <?php if ($tier['max_participants'] > 0): ?>
                        <span style="margin-left: 15px; font-size: 0.75rem;">Max: <?php echo $tier['max_participants']; ?> participants</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Photos Review -->
            <?php if (!empty($wizardData['main_image']) || !empty($wizardData['gallery'])): ?>
            <div class="review-section">
                <h3>Photos</h3>
                <?php if (!empty($wizardData['main_image'])): ?>
                <div style="margin-bottom: 15px;">
                    <div class="review-item-label">Main Image</div>
                    <img src="/gorwanda-plus/assets/images/attractions/<?php echo $wizardData['main_image']; ?>" 
                         style="width: 100px; height: 100px; object-fit: cover; border-radius: var(--radius-sm); border: 2px solid var(--exp-purple);">
                </div>
                <?php endif; ?>
                
                <?php if (!empty($wizardData['gallery'])): ?>
                <div>
                    <div class="review-item-label">Gallery (<?php echo count($wizardData['gallery']); ?> images)</div>
                    <div class="photo-grid" style="grid-template-columns: repeat(4, 1fr); margin-top: 10px;">
                        <?php foreach (array_slice($wizardData['gallery'], 0, 4) as $photo): ?>
                        <div class="photo-item">
                            <img src="/gorwanda-plus/assets/images/attractions/gallery/<?php echo $photo; ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="4" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="submit_experience" class="btn-wizard-next" style="background: var(--exp-success);">
                    <i class="bi bi-check-lg"></i> Submit Experience
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
let tierIndex = <?php echo count($wizardData['tiers'] ?? [1]); ?>;

function addTimeRow() {
    const container = document.getElementById('startTimesContainer');
    const timeRow = document.createElement('div');
    timeRow.className = 'time-row';
    timeRow.innerHTML = `
        <input type="time" name="start_times[]" class="form-control" value="09:00">
        <button type="button" class="time-remove" onclick="removeTimeRow(this)">
            <i class="bi bi-dash"></i>
        </button>
    `;
    container.appendChild(timeRow);
}

function removeTimeRow(btn) {
    btn.closest('.time-row').remove();
}

function addTier() {
    const container = document.getElementById('tiers-container');
    const newTier = document.createElement('div');
    newTier.className = 'tier-card';
    newTier.id = `tier_${tierIndex}`;
    
    newTier.innerHTML = `
        <div class="tier-card-header">
            <h4 class="tier-card-title">Tier ${tierIndex + 1}</h4>
            <button type="button" class="tier-card-remove" onclick="removeTier(${tierIndex})">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        
        <div class="tier-grid">
            <div class="form-group">
                <label class="form-label">Tier Name</label>
                <input type="text" name="tier_name_${tierIndex}" class="form-control" placeholder="e.g., Standard, Premium">
            </div>
            
            <div class="form-group">
                <label class="form-label">Price (RWF)</label>
                <input type="number" name="tier_price_${tierIndex}" class="form-control" value="0" min="0" step="1000">
            </div>
            
            <div class="form-group">
                <label class="form-label">Price Type</label>
                <select name="tier_price_type_${tierIndex}" class="form-control">
                    <option value="per_person">Per Person</option>
                    <option value="per_group">Per Group</option>
                    <option value="per_couple">Per Couple</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Max Participants</label>
                <input type="number" name="tier_max_participants_${tierIndex}" class="form-control" value="0" min="0">
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">Description</label>
                <textarea name="tier_description_${tierIndex}" class="form-control" rows="2"></textarea>
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">Tier-Specific Inclusions</label>
                <div class="checkbox-grid" style="max-height: 150px;">
                    <?php foreach ($commonIncludedItems as $key => $label): ?>
                    <label class="checkbox-item">
                        <input type="checkbox" name="tier_inclusions_${tierIndex}[]" value="<?php echo $key; ?>">
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newTier);
    document.getElementById('tier_count').value = tierIndex + 1;
    tierIndex++;
}

function removeTier(index) {
    const tier = document.getElementById(`tier_${index}`);
    if (tier) {
        tier.remove();
        // Renumber remaining tiers
        const tiers = document.querySelectorAll('.tier-card');
        tiers.forEach((tier, idx) => {
            tier.querySelector('.tier-card-title').textContent = `Tier ${idx + 1}`;
            tier.id = `tier_${idx}`;
            
            // Update input names
            tier.querySelectorAll('input, select, textarea').forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    const newName = name.replace(/_\d+_/, `_${idx}_`);
                    input.setAttribute('name', newName);
                }
            });
        });
        document.getElementById('tier_count').value = tiers.length;
    }
}

// Auto-submit photo upload when files are selected
document.getElementById('main_image')?.addEventListener('change', function() {
    document.getElementById('photoForm').submit();
});

document.getElementById('gallery_images')?.addEventListener('change', function() {
    document.getElementById('photoForm').submit();
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>