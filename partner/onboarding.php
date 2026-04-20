<?php
ob_start();
$pageTitle = 'List your property - GoRwanda+';
$hideSearch = true;
require_once '../includes/header.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = '/gorwanda-plus/partner/onboarding.php';
    ob_end_clean();
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$user = getCurrentUser();

// Initialize or get current step
if (!isset($_SESSION['onboarding_step'])) {
    $_SESSION['onboarding_step'] = 'welcome';
}

// Check if user already has business type
$businessTypes = json_decode($user['business_type'] ?? '[]', true);
if (!empty($businessTypes) && $_SESSION['onboarding_step'] === 'welcome') {
    $_SESSION['onboarding_step'] = 'property-details';
}

$step = $_GET['step'] ?? $_SESSION['onboarding_step'];
$error = '';
$success = '';

// Handle step navigation
if (isset($_POST['back'])) {
    $step = $_POST['back'];
    $_SESSION['onboarding_step'] = $step;
}

// ============================================
// STEP 1: WELCOME (Choose what to list)
// ============================================
if ($step === 'welcome' && isset($_POST['start'])) {
    $businessType = $_POST['business_type'] ?? '';
    
    if (empty($businessType)) {
        $error = 'Please select what you want to list';
    } else {
        // Save business type to user account
        $stmt = $db->prepare("UPDATE users SET business_type = ? WHERE user_id = ?");
        $stmt->execute([json_encode([$businessType]), $user['user_id']]);
        
        $_SESSION['onboarding_step'] = 'property-details';
        $step = 'property-details';
    }
}

// ============================================
// STEP 2: PROPERTY DETAILS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_details'])) {
    $_SESSION['onboarding']['details'] = [
        'name' => sanitize($_POST['property_name'] ?? ''),
        'type' => sanitize($_POST['property_type'] ?? 'hotel'),
        'description' => sanitize($_POST['description'] ?? ''),
        'star_rating' => intval($_POST['star_rating'] ?? 0)
    ];
    $_SESSION['onboarding_step'] = 'property-location';
    $step = 'property-location';
}

// ============================================
// STEP 3: LOCATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_location'])) {
    $_SESSION['onboarding']['location'] = [
        'country' => 'Rwanda',
        'city' => sanitize($_POST['city'] ?? ''),
        'address' => sanitize($_POST['address'] ?? '')
    ];
    $_SESSION['onboarding_step'] = 'amenities';
    $step = 'amenities';
}

// ============================================
// STEP 4: AMENITIES
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_amenities'])) {
    $_SESSION['onboarding']['amenities'] = $_POST['amenities'] ?? [];
    $_SESSION['onboarding_step'] = 'photos';
    $step = 'photos';
}

// ============================================
// STEP 5: PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photos'])) {
    $uploadedPhotos = $_SESSION['onboarding']['photos'] ?? [];
    
    if (!empty($_FILES['photos']['name'][0])) {
        $uploadDir = dirname(__DIR__, 2) . '/assets/images/stays/temp/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $files = $_FILES['photos'];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileExt = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                $fileName = time() . '_' . uniqid() . '.' . $fileExt;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                    $uploadedPhotos[] = $fileName;
                }
            }
        }
        $_SESSION['onboarding']['photos'] = $uploadedPhotos;
        $success = count($files['name']) . ' photo(s) uploaded successfully';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['next_from_photos'])) {
    if (empty($_SESSION['onboarding']['photos'])) {
        $error = 'Please upload at least 1 photo';
    } else {
        $_SESSION['onboarding_step'] = 'pricing';
        $step = 'pricing';
    }
}

// ============================================
// STEP 6: PRICING
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pricing'])) {
    $_SESSION['onboarding']['pricing'] = [
        'price' => floatval($_POST['price'] ?? 0),
        'currency' => 'RWF',
        'guests' => intval($_POST['guests'] ?? 2),
        'rooms' => intval($_POST['rooms'] ?? 1)
    ];
    $_SESSION['onboarding_step'] = 'review';
    $step = 'review';
}

// ============================================
// STEP 7: REVIEW & SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_listing'])) {
    // Get business type from user account
    $user = getCurrentUser();
    $businessTypes = json_decode($user['business_type'] ?? '[]', true);
    $businessType = $businessTypes[0] ?? 'stay';
    
    // Create the property
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($_SESSION['onboarding']['details']['name']));
    $slug = trim($slug, '-') . '-' . time();
    
    $stmt = $db->prepare("
        INSERT INTO stays (
            owner_id, stay_name, slug, stay_type, description,
            address, city, star_rating, amenities, main_image,
            is_active, is_verified, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
    ");
    
    $mainImage = $_SESSION['onboarding']['photos'][0] ?? null;
    $amenitiesJson = json_encode($_SESSION['onboarding']['amenities'] ?? []);
    
    $stmt->execute([
        $user['user_id'],
        $_SESSION['onboarding']['details']['name'],
        $slug,
        $_SESSION['onboarding']['details']['type'],
        $_SESSION['onboarding']['details']['description'],
        $_SESSION['onboarding']['location']['address'],
        $_SESSION['onboarding']['location']['city'],
        $_SESSION['onboarding']['details']['star_rating'],
        $amenitiesJson,
        $mainImage
    ]);
    
    $stayId = $db->lastInsertId();
    
    // Create a basic room
    $stmt = $db->prepare("
        INSERT INTO stay_rooms (
            stay_id, room_name, description, max_guests, 
            num_rooms_available, base_price, bed_configuration, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    
    $stmt->execute([
        $stayId,
        'Standard Room',
        'Comfortable room with essential amenities',
        $_SESSION['onboarding']['pricing']['guests'],
        $_SESSION['onboarding']['pricing']['rooms'],
        $_SESSION['onboarding']['pricing']['price'],
        '1 Queen Bed'
    ]);
    
    // Clear session
    unset($_SESSION['onboarding']);
    unset($_SESSION['onboarding_step']);
    
    $_SESSION['onboarding_step'] = 'complete';
    $step = 'complete';
}

// Get step progress percentage
$stepNumbers = [
    'welcome' => 0,
    'property-details' => 1,
    'property-location' => 2,
    'amenities' => 3,
    'photos' => 4,
    'pricing' => 5,
    'review' => 6,
    'complete' => 7
];
$totalSteps = 7;
$currentStepNum = $stepNumbers[$step] ?? 0;
$progress = $currentStepNum > 0 ? round(($currentStepNum / $totalSteps) * 100) : 0;
?>

<style>
/* Booking.com Exact Styling */
:root {
    --booking-blue: #003b95;
    --booking-light-blue: #f0f4ff;
    --booking-yellow: #febb02;
    --booking-gray: #f5f5f5;
    --booking-text: #1a1a1a;
    --booking-text-light: #6b6b6b;
    --booking-border: #e7e7e7;
    --booking-success: #008009;
}

* {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

body {
    background: #f5f5f5;
}

.onboarding-page {
    max-width: 800px;
    margin: 40px auto;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Header - Booking.com Style */
.onboarding-header {
    background: var(--booking-blue);
    color: white;
    padding: 32px 40px;
    border-radius: 8px 8px 0 0;
}

.onboarding-header h1 {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px 0;
}

.onboarding-header p {
    font-size: 14px;
    opacity: 0.9;
    margin: 0;
}

/* Progress Bar - Booking.com Style */
.progress-section {
    padding: 24px 40px;
    background: white;
    border-bottom: 1px solid var(--booking-border);
}

.progress-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 14px;
    color: var(--booking-text-light);
}

.progress-percentage {
    color: var(--booking-blue);
    font-weight: 600;
}

.progress-bar {
    height: 4px;
    background: var(--booking-border);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--booking-blue);
    transition: width 0.3s ease;
}

.step-indicator {
    display: flex;
    justify-content: space-between;
    margin-top: 16px;
    font-size: 12px;
    color: var(--booking-text-light);
}

.step-indicator span {
    position: relative;
}

.step-indicator span.active {
    color: var(--booking-blue);
    font-weight: 600;
}

.step-indicator span.active::before {
    content: '';
    position: absolute;
    bottom: -20px;
    left: 50%;
    transform: translateX(-50%);
    width: 8px;
    height: 8px;
    background: var(--booking-blue);
    border-radius: 50%;
}

/* Content Area */
.onboarding-content {
    padding: 40px;
}

/* Booking.com Cards */
.booking-card {
    border: 1px solid var(--booking-border);
    border-radius: 8px;
    padding: 24px;
    cursor: pointer;
    transition: all 0.2s;
}

.booking-card:hover {
    border-color: var(--booking-blue);
    box-shadow: 0 2px 8px rgba(0,57,149,0.1);
}

.booking-card.selected {
    border: 2px solid var(--booking-blue);
    background: var(--booking-light-blue);
}

.booking-card input[type="radio"],
.booking-card input[type="checkbox"] {
    display: none;
}

.booking-card-title {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 4px;
    color: var(--booking-text);
}

.booking-card-subtitle {
    font-size: 14px;
    color: var(--booking-text-light);
}

/* Options Grid */
.options-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin: 24px 0;
}

.option-item {
    border: 1px solid var(--booking-border);
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}

.option-item:hover {
    border-color: var(--booking-blue);
}

.option-item.selected {
    border: 2px solid var(--booking-blue);
    background: var(--booking-light-blue);
}

.option-item i {
    font-size: 32px;
    color: var(--booking-blue);
    margin-bottom: 12px;
    display: block;
}

.option-item span {
    font-size: 14px;
    font-weight: 500;
}

/* Form Elements - Booking.com Style */
.form-group {
    margin-bottom: 24px;
}

.form-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--booking-text);
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--booking-border);
    border-radius: 4px;
    font-size: 16px;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,57,149,0.1);
}

.form-control.error {
    border-color: #c41c1c;
}

.form-text {
    font-size: 12px;
    color: var(--booking-text-light);
    margin-top: 4px;
}

/* Amenities Grid */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin: 20px 0;
}

.amenity-checkbox {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: 1px solid var(--booking-border);
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.amenity-checkbox:hover {
    border-color: var(--booking-blue);
}

.amenity-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--booking-blue);
}

/* Photo Upload - Booking.com Style */
.photo-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    background: var(--booking-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 24px;
}

.photo-upload-area:hover {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
}

.photo-upload-area i {
    font-size: 48px;
    color: var(--booking-text-light);
    margin-bottom: 16px;
}

.photo-upload-area h4 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 8px;
}

.photo-upload-area p {
    font-size: 14px;
    color: var(--booking-text-light);
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 24px;
}

.photo-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: 4px;
    overflow: hidden;
    border: 1px solid var(--booking-border);
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
    background: #c41c1c;
}

/* Buttons - Booking.com Style */
.btn-booking-primary {
    background: var(--booking-blue);
    color: white;
    border: none;
    padding: 14px 32px;
    border-radius: 4px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}

.btn-booking-primary:hover {
    background: #00224f;
}

.btn-booking-secondary {
    background: white;
    color: var(--booking-blue);
    border: 1px solid var(--booking-blue);
    padding: 14px 32px;
    border-radius: 4px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}

.btn-booking-secondary:hover {
    background: var(--booking-light-blue);
}

.btn-booking-link {
    background: none;
    border: none;
    color: var(--booking-blue);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: underline;
}

.action-buttons {
    display: flex;
    justify-content: space-between;
    margin-top: 40px;
    padding-top: 24px;
    border-top: 1px solid var(--booking-border);
}

/* Alerts - Booking.com Style */
.alert {
    padding: 16px 20px;
    border-radius: 4px;
    margin-bottom: 24px;
    font-size: 14px;
}

.alert-success {
    background: #dff0d8;
    color: #3c763d;
    border: 1px solid #d6e9c6;
}

.alert-error {
    background: #f2dede;
    color: #a94442;
    border: 1px solid #ebccd1;
}

/* Success Page */
.success-page {
    text-align: center;
    padding: 60px 40px;
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--booking-success);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    color: white;
    font-size: 40px;
}

.success-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 16px;
}

.success-text {
    font-size: 16px;
    color: var(--booking-text-light);
    margin-bottom: 32px;
    max-width: 400px;
    margin-left: auto;
    margin-right: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .onboarding-page {
        margin: 0;
        border-radius: 0;
    }
    
    .onboarding-header {
        padding: 24px;
    }
    
    .progress-section {
        padding: 16px 24px;
    }
    
    .onboarding-content {
        padding: 24px;
    }
    
    .options-grid,
    .amenities-grid,
    .photo-grid {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 12px;
    }
    
    .btn-booking-primary,
    .btn-booking-secondary {
        width: 100%;
    }
}
</style>

<div class="onboarding-page">
    <!-- Header -->
    <div class="onboarding-header">
        <h1>List your property</h1>
        <p>Start your journey with GoRwanda+</p>
    </div>
    
    <!-- Progress Bar (hide for welcome and complete) -->
    <?php if ($step !== 'complete' && $step !== 'welcome'): ?>
    <div class="progress-section">
        <div class="progress-stats">
            <span>Complete your listing</span>
            <span class="progress-percentage"><?php echo $progress; ?>%</span>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
        </div>
        <div class="step-indicator">
            <span class="<?php echo $currentStepNum >= 1 ? 'active' : ''; ?>">Details</span>
            <span class="<?php echo $currentStepNum >= 2 ? 'active' : ''; ?>">Location</span>
            <span class="<?php echo $currentStepNum >= 3 ? 'active' : ''; ?>">Amenities</span>
            <span class="<?php echo $currentStepNum >= 4 ? 'active' : ''; ?>">Photos</span>
            <span class="<?php echo $currentStepNum >= 5 ? 'active' : ''; ?>">Pricing</span>
            <span class="<?php echo $currentStepNum >= 6 ? 'active' : ''; ?>">Review</span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Content -->
    <div class="onboarding-content">
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- STEP 1: WELCOME - Choose what to list (ONLY ONE SELECTION) -->
        <?php if ($step === 'welcome'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">What would you like to list?</h2>
        
        <form method="POST" id="welcomeForm">
            <div class="options-grid">
                <div class="option-item" onclick="selectOption(this, 'stay')">
                    <i class="bi bi-building"></i>
                    <span>Hotel / Apartment</span>
                </div>
                <div class="option-item" onclick="selectOption(this, 'car_rental')">
                    <i class="bi bi-car-front"></i>
                    <span>Car Rental</span>
                </div>
                <div class="option-item" onclick="selectOption(this, 'attraction')">
                    <i class="bi bi-ticket-perforated"></i>
                    <span>Experience / Tour</span>
                </div>
            </div>
            
            <input type="hidden" name="business_type" id="selectedBusinessType" value="">
            
            <div class="action-buttons">
                <a href="/gorwanda-plus/" class="btn-booking-link">Maybe later</a>
                <button type="submit" name="start" class="btn-booking-primary" onclick="return validateWelcomeSelection()">
                    Get started
                </button>
            </div>
        </form>
        
        <!-- STEP 2: PROPERTY DETAILS -->
        <?php elseif ($step === 'property-details'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">Tell us about your property</h2>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Property type</label>
                <select name="property_type" class="form-control">
                    <option value="hotel">Hotel</option>
                    <option value="apartment">Apartment</option>
                    <option value="guesthouse">Guest house</option>
                    <option value="lodge">Lodge</option>
                    <option value="resort">Resort</option>
                    <option value="villa">Villa</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Property name <span class="required">*</span></label>
                <input type="text" name="property_name" class="form-control" placeholder="e.g., Hotel des Mille Collines" required>
                <div class="form-text">This is how your property will appear to guests</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Describe your property, its unique features, and what guests can expect..."></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">Star rating</label>
                <select name="star_rating" class="form-control">
                    <option value="0">Not rated</option>
                    <option value="1">1 star</option>
                    <option value="2">2 stars</option>
                    <option value="3">3 stars</option>
                    <option value="4">4 stars</option>
                    <option value="5">5 stars</option>
                </select>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="back" value="welcome" class="btn-booking-link">
                    ← Back
                </button>
                <button type="submit" name="save_details" class="btn-booking-primary">
                    Continue
                </button>
            </div>
        </form>
        
        <!-- STEP 3: LOCATION -->
        <?php elseif ($step === 'property-location'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">Where is your property located?</h2>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Country/Region</label>
                <select class="form-control">
                    <option>Rwanda</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">City <span class="required">*</span></label>
                <input type="text" name="city" class="form-control" placeholder="e.g., Kigali" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Address <span class="required">*</span></label>
                <input type="text" name="address" class="form-control" placeholder="Street address" required>
                <div class="form-text">This will be shown to guests after booking</div>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="back" value="property-details" class="btn-booking-link">
                    ← Back
                </button>
                <button type="submit" name="save_location" class="btn-booking-primary">
                    Continue
                </button>
            </div>
        </form>
        
        <!-- STEP 4: AMENITIES -->
        <?php elseif ($step === 'amenities'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">What amenities do you offer?</h2>
        
        <form method="POST">
            <div class="amenities-grid">
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="wifi">
                    <span>Free WiFi</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="parking">
                    <span>Free parking</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="pool">
                    <span>Swimming pool</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="restaurant">
                    <span>Restaurant</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="spa">
                    <span>Spa</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="gym">
                    <span>Fitness center</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="ac">
                    <span>Air conditioning</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="breakfast">
                    <span>Breakfast included</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="airport_shuttle">
                    <span>Airport shuttle</span>
                </label>
                <label class="amenity-checkbox">
                    <input type="checkbox" name="amenities[]" value="pets">
                    <span>Pet friendly</span>
                </label>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="back" value="property-location" class="btn-booking-link">
                    ← Back
                </button>
                <button type="submit" name="save_amenities" class="btn-booking-primary">
                    Continue
                </button>
            </div>
        </form>
        
        <!-- STEP 5: PHOTOS -->
        <?php elseif ($step === 'photos'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">Add photos of your property</h2>
        
        <form method="POST" enctype="multipart/form-data" id="photoForm">
            <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                <i class="bi bi-cloud-upload"></i>
                <h4>Click to upload photos</h4>
                <p>or drag and drop (JPG, PNG)</p>
                <input type="file" name="photos[]" id="photoInput" multiple accept="image/*" style="display: none;">
            </div>
            
            <?php if (!empty($_SESSION['onboarding']['photos'])): ?>
            <div class="photo-grid">
                <?php foreach ($_SESSION['onboarding']['photos'] as $photo): ?>
                <div class="photo-item">
                    <img src="/gorwanda-plus/assets/images/stays/temp/<?php echo $photo; ?>" alt="Property photo">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div style="display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap;">
                <button type="submit" name="upload_photos" class="btn-booking-secondary">
                    <i class="bi bi-upload"></i> Upload selected
                </button>
                
                <?php if (!empty($_SESSION['onboarding']['photos'])): ?>
                <button type="submit" name="next_from_photos" class="btn-booking-primary">
                    Continue to pricing
                </button>
                <?php endif; ?>
            </div>
            
            <div class="action-buttons" style="margin-top: 20px;">
                <button type="submit" name="back" value="amenities" class="btn-booking-link">
                    ← Back
                </button>
            </div>
        </form>
        
        <!-- STEP 6: PRICING -->
        <?php elseif ($step === 'pricing'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">Set your price</h2>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Price per night (RWF) <span class="required">*</span></label>
                <input type="number" name="price" class="form-control" placeholder="50000" min="0" step="1000" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Maximum guests</label>
                <input type="number" name="guests" class="form-control" value="2" min="1">
            </div>
            
            <div class="form-group">
                <label class="form-label">Number of rooms</label>
                <input type="number" name="rooms" class="form-control" value="1" min="1">
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="back" value="photos" class="btn-booking-link">
                    ← Back
                </button>
                <button type="submit" name="save_pricing" class="btn-booking-primary">
                    Review listing
                </button>
            </div>
        </form>
        
        <!-- STEP 7: REVIEW -->
        <?php elseif ($step === 'review'): ?>
        <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 24px;">Review your listing</h2>
        
        <div style="background: var(--booking-gray); padding: 24px; border-radius: 8px; margin-bottom: 24px;">
            <p><strong>Property:</strong> <?php echo $_SESSION['onboarding']['details']['name'] ?? ''; ?></p>
            <p><strong>Location:</strong> <?php echo $_SESSION['onboarding']['location']['address'] ?? ''; ?>, <?php echo $_SESSION['onboarding']['location']['city'] ?? ''; ?></p>
            <p><strong>Price:</strong> RWF <?php echo number_format($_SESSION['onboarding']['pricing']['price'] ?? 0); ?>/night</p>
            <p><strong>Photos:</strong> <?php echo count($_SESSION['onboarding']['photos'] ?? []); ?> uploaded</p>
        </div>
        
        <form method="POST">
            <div class="action-buttons">
                <button type="submit" name="back" value="pricing" class="btn-booking-link">
                    ← Back
                </button>
                <button type="submit" name="submit_listing" class="btn-booking-primary">
                    Submit for review
                </button>
            </div>
        </form>
        
        <!-- STEP 8: COMPLETE -->
        <?php elseif ($step === 'complete'): ?>
        <div class="success-page">
            <div class="success-icon">
                <i class="bi bi-check-lg"></i>
            </div>
            <h2 class="success-title">Property submitted!</h2>
            <p class="success-text">
                We'll review your property and get back to you within 24-48 hours.
            </p>
            <div style="display: flex; gap: 16px; justify-content: center;">
                <a href="/gorwanda-plus/partner/dashboard.php" class="btn-booking-primary">
                    Go to dashboard
                </a>
                <a href="/gorwanda-plus/" class="btn-booking-secondary">
                    Back to home
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Select option function for welcome page
function selectOption(element, value) {
    // Remove selected class from all options
    document.querySelectorAll('.option-item').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Add selected class to clicked element
    element.classList.add('selected');
    
    // Set the hidden input value
    document.getElementById('selectedBusinessType').value = value;
}

// Validate welcome page selection
function validateWelcomeSelection() {
    let selectedValue = document.getElementById('selectedBusinessType').value;
    if (!selectedValue) {
        alert('Please select what you want to list');
        return false;
    }
    return true;
}

// Initialize - check if there's a pre-selected value
document.addEventListener('DOMContentLoaded', function() {
    // If there's already a value in the hidden input, highlight the corresponding option
    let selectedValue = document.getElementById('selectedBusinessType')?.value;
    if (selectedValue) {
        document.querySelectorAll('.option-item').forEach(item => {
            if (item.querySelector('span').innerText.toLowerCase().includes(selectedValue.replace('_', ' '))) {
                item.classList.add('selected');
            }
        });
    }
});
</script>

<?php 
ob_end_flush();
require_once '../includes/footer.php'; 
?>