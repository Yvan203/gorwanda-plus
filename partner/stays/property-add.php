<?php
$pageTitle = 'Add New Property';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Initialize session for wizard
if (!isset($_SESSION['property_wizard'])) {
    $_SESSION['property_wizard'] = [
        'step' => 1,
        'data' => []
    ];
}

$currentStep = $_SESSION['property_wizard']['step'] ?? 1;
$wizardData = $_SESSION['property_wizard']['data'] ?? [];
$error = '';
$success = '';

// Handle step navigation
if (isset($_POST['next_step'])) {
    $currentStep = intval($_POST['current_step']) + 1;
    $_SESSION['property_wizard']['step'] = $currentStep;
} elseif (isset($_POST['prev_step'])) {
    $currentStep = intval($_POST['current_step']) - 1;
    $_SESSION['property_wizard']['step'] = $currentStep;
}

// ============================================
// STEP 1: BASIC INFORMATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step1'])) {
    $step1Data = [
        'property_name' => sanitize($_POST['property_name'] ?? ''),
        'property_type' => sanitize($_POST['property_type'] ?? ''),
        'star_rating' => intval($_POST['star_rating'] ?? 0),
        'description' => sanitize($_POST['description'] ?? ''),
        'year_built' => intval($_POST['year_built'] ?? date('Y')),
        'number_of_rooms' => intval($_POST['number_of_rooms'] ?? 1),
        'number_of_floors' => intval($_POST['number_of_floors'] ?? 1),
        'check_in_time' => $_POST['check_in_time'] ?? '14:00',
        'check_out_time' => $_POST['check_out_time'] ?? '11:00'
    ];
    
    // Validate
    $errors = [];
    if (empty($step1Data['property_name'])) $errors[] = 'Property name is required';
    if (empty($step1Data['property_type'])) $errors[] = 'Property type is required';
    
    if (empty($errors)) {
        $_SESSION['property_wizard']['data'] = array_merge($wizardData, $step1Data);
        $_SESSION['property_wizard']['step'] = 2;
        $currentStep = 2;
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// STEP 2: LOCATION & CONTACT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step2'])) {
    $step2Data = [
        'country' => 'Rwanda',
        'city' => sanitize($_POST['city'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'latitude' => floatval($_POST['latitude'] ?? 0),
        'longitude' => floatval($_POST['longitude'] ?? 0),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'website' => sanitize($_POST['website'] ?? ''),
        'neighborhood' => sanitize($_POST['neighborhood'] ?? ''),
        'zip_code' => sanitize($_POST['zip_code'] ?? '')
    ];
    
    // Validate
    $errors = [];
    if (empty($step2Data['city'])) $errors[] = 'City is required';
    if (empty($step2Data['address'])) $errors[] = 'Address is required';
    
    if (empty($errors)) {
        $_SESSION['property_wizard']['data'] = array_merge($wizardData, $step2Data);
        $_SESSION['property_wizard']['step'] = 3;
        $currentStep = 3;
    } else {
        $error = implode('<br>', $errors);
    }
}

// ============================================
// STEP 3: AMENITIES
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step3'])) {
    $step3Data = [
        'amenities' => $_POST['amenities'] ?? [],
        'languages' => $_POST['languages'] ?? [],
        'policies' => [
            'children' => isset($_POST['children_allowed']),
            'pets' => isset($_POST['pets_allowed']),
            'smoking' => isset($_POST['smoking_allowed']),
            'parties' => isset($_POST['parties_allowed']),
            'check_in_instructions' => sanitize($_POST['check_in_instructions'] ?? '')
        ]
    ];
    
    $_SESSION['property_wizard']['data'] = array_merge($wizardData, $step3Data);
    $_SESSION['property_wizard']['step'] = 4;
    $currentStep = 4;
}

// ============================================
// STEP 4: ROOMS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step4'])) {
    $rooms = [];
    $roomCount = intval($_POST['room_count'] ?? 0);
    
    for ($i = 0; $i < $roomCount; $i++) {
        if (isset($_POST['room_name_' . $i])) {
            $rooms[] = [
                'name' => sanitize($_POST['room_name_' . $i]),
                'description' => sanitize($_POST['room_description_' . $i] ?? ''),
                'max_guests' => intval($_POST['room_max_guests_' . $i] ?? 2),
                'num_rooms' => intval($_POST['room_quantity_' . $i] ?? 1),
                'size' => intval($_POST['room_size_' . $i] ?? 0),
                'bed_config' => sanitize($_POST['room_bed_' . $i] ?? ''),
                'price' => floatval($_POST['room_price_' . $i] ?? 0),
                'amenities' => $_POST['room_amenities_' . $i] ?? []
            ];
        }
    }
    
    $_SESSION['property_wizard']['data']['rooms'] = $rooms;
    $_SESSION['property_wizard']['step'] = 5;
    $currentStep = 5;
}

// ============================================
// STEP 5: PHOTOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_step5'])) {
    $uploadedPhotos = $_SESSION['property_wizard']['data']['photos'] ?? [];
    
    // Handle photo uploads
    if (!empty($_FILES['photos']['name'][0])) {
        $uploadDir = dirname(__DIR__, 3) . '/assets/images/stays/temp/';
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
    }
    
    // Handle photo removal
    if (isset($_POST['remove_photo'])) {
        $photoToRemove = $_POST['remove_photo'];
        $uploadedPhotos = array_diff($uploadedPhotos, [$photoToRemove]);
        
        // Delete file
        $filePath = dirname(__DIR__, 3) . '/assets/images/stays/temp/' . $photoToRemove;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    $_SESSION['property_wizard']['data']['photos'] = $uploadedPhotos;
    
    if (isset($_POST['next_step'])) {
        if (count($uploadedPhotos) < 3) {
            $error = 'Please upload at least 3 photos';
        } else {
            $_SESSION['property_wizard']['step'] = 6;
            $currentStep = 6;
        }
    }
}

// ============================================
// STEP 6: REVIEW & SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_property'])) {
    $data = $_SESSION['property_wizard']['data'];
    
    // Generate slug
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($data['property_name']));
    $slug = trim($slug, '-') . '-' . time();
    
    // Prepare amenities JSON
    $amenitiesJson = json_encode($data['amenities'] ?? []);
    
    // Prepare policies JSON
    $policiesJson = json_encode($data['policies'] ?? []);
    
    // Insert property
    $stmt = $db->prepare("
        INSERT INTO stays (
            owner_id, stay_name, slug, stay_type, description,
            star_rating, address, city, phone, email, website,
            check_in_time, check_out_time, amenities, policies,
            main_image, is_active, is_verified, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())
    ");
    
    $mainImage = $data['photos'][0] ?? null;
    
    $stmt->execute([
        $userId,
        $data['property_name'],
        $slug,
        $data['property_type'],
        $data['description'],
        $data['star_rating'],
        $data['address'],
        $data['city'],
        $data['phone'],
        $data['email'],
        $data['website'] ?? '',
        $data['check_in_time'] . ':00',
        $data['check_out_time'] . ':00',
        $amenitiesJson,
        $policiesJson,
        $mainImage
    ]);
    
    $stayId = $db->lastInsertId();
    
    // Insert rooms
    if (!empty($data['rooms'])) {
        $roomStmt = $db->prepare("
            INSERT INTO stay_rooms (
                stay_id, room_name, description, max_guests,
                num_rooms_available, base_price, size_sqm,
                bed_configuration, room_amenities, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        foreach ($data['rooms'] as $room) {
            $roomAmenitiesJson = json_encode($room['amenities'] ?? []);
            $roomStmt->execute([
                $stayId,
                $room['name'],
                $room['description'],
                $room['max_guests'],
                $room['num_rooms'],
                $room['price'],
                $room['size'],
                $room['bed_config'],
                $roomAmenitiesJson
            ]);
        }
    }
    
    // Move photos from temp to permanent
    if (!empty($data['photos'])) {
        $tempDir = dirname(__DIR__, 3) . '/assets/images/stays/temp/';
        $permDir = dirname(__DIR__, 3) . '/assets/images/stays/';
        
        $imagePaths = [];
        foreach ($data['photos'] as $photo) {
            if (file_exists($tempDir . $photo)) {
                rename($tempDir . $photo, $permDir . $photo);
                $imagePaths[] = $photo;
            }
        }
        
        // Update stay with all images
        if (!empty($imagePaths)) {
            $stmt = $db->prepare("UPDATE stays SET images = ? WHERE stay_id = ?");
            $stmt->execute([json_encode($imagePaths), $stayId]);
        }
    }
    
    // Clear wizard session
    unset($_SESSION['property_wizard']);
    
    // Redirect to success page
    header('Location: property-success.php?id=' . $stayId);
    exit;
}

// Get locations for dropdown
$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();

// Common amenities
$commonAmenities = [
    'wifi' => 'Free WiFi',
    'pool' => 'Swimming Pool',
    'parking' => 'Free Parking',
    'restaurant' => 'Restaurant',
    'spa' => 'Spa',
    'gym' => 'Fitness Center',
    'bar' => 'Bar/Lounge',
    'room_service' => 'Room Service',
    'ac' => 'Air Conditioning',
    'breakfast' => 'Breakfast Included',
    'airport_shuttle' => 'Airport Shuttle',
    'laundry' => 'Laundry Service',
    'pets' => 'Pet Friendly',
    'family_rooms' => 'Family Rooms',
    'non_smoking' => 'Non-smoking Rooms',
    'business_center' => 'Business Center',
    'meeting_rooms' => 'Meeting Rooms',
    'elevator' => 'Elevator',
    'wheelchair' => 'Wheelchair Accessible'
];

// Room amenities
$roomAmenitiesList = [
    'ac' => 'Air Conditioning',
    'tv' => 'Flat-screen TV',
    'wifi' => 'Free WiFi',
    'minibar' => 'Minibar',
    'safe' => 'Safe',
    'balcony' => 'Balcony',
    'bathtub' => 'Bathtub',
    'shower' => 'Shower',
    'coffee_maker' => 'Coffee Maker',
    'desk' => 'Work Desk',
    'hair_dryer' => 'Hair Dryer',
    'iron' => 'Ironing Facilities',
    'kitchen' => 'Kitchenette',
    'view' => 'Mountain/City View'
];

// Languages
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'rw' => 'Kinyarwanda',
    'sw' => 'Swahili'
];

// Property types
$propertyTypes = [
    'hotel' => 'Hotel',
    'apartment' => 'Apartment',
    'guesthouse' => 'Guest House',
    'lodge' => 'Lodge',
    'resort' => 'Resort',
    'villa' => 'Villa',
    'hostel' => 'Hostel',
    'campsite' => 'Campsite'
];

$stepTitles = [
    1 => 'Basic Information',
    2 => 'Location & Contact',
    3 => 'Amenities & Policies',
    4 => 'Rooms',
    5 => 'Photos',
    6 => 'Review & Submit'
];

$stepDescriptions = [
    1 => 'Tell us about your property',
    2 => 'Where is your property located?',
    3 => 'What amenities do you offer?',
    4 => 'Add your room types',
    5 => 'Show off your property with photos',
    6 => 'Review your information before submitting'
];
?>

<style>
/* Wizard Container */
.wizard-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

/* Progress Header */
.wizard-progress {
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-dark-blue));
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
    background: var(--booking-success);
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
    color: var(--booking-text);
    margin-bottom: 8px;
}

.step-description {
    font-size: 0.875rem;
    color: var(--booking-text-light);
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
    color: var(--booking-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label .required {
    color: var(--booking-danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.form-control.error {
    border-color: var(--booking-danger);
}

.form-text {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-top: 4px;
}

/* Amenities Grid */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    max-height: 300px;
    overflow-y: auto;
}

.amenity-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    cursor: pointer;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
    background: white;
    border: 1px solid var(--booking-border);
}

.amenity-checkbox:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
}

.amenity-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--booking-blue);
}

/* Room Card */
.room-card {
    background: var(--booking-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--booking-border);
    position: relative;
}

.room-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.room-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.room-card-remove {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: white;
    color: var(--booking-danger);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.room-card-remove:hover {
    background: var(--booking-danger);
    color: white;
}

.room-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

/* Photo Upload */
.photo-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: var(--radius-md);
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
    font-size: 3rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
}

.photo-upload-area h4 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 4px;
}

.photo-upload-area p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
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
    background: var(--booking-danger);
}

/* Review Section */
.review-section {
    background: var(--booking-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    margin-bottom: 20px;
}

.review-section h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 15px;
    color: var(--booking-blue);
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
    color: var(--booking-text-light);
    min-width: 100px;
}

.review-item-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text);
}

.review-amenities {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.review-amenity-tag {
    background: white;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.75rem;
    border: 1px solid var(--booking-border);
}

/* Action Buttons */
.wizard-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--booking-border);
}

.btn-wizard-prev {
    background: var(--booking-white);
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-wizard-prev:hover {
    background: var(--booking-gray);
}

.btn-wizard-next {
    background: var(--booking-blue);
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
    background: var(--booking-dark-blue);
}

.btn-add-room {
    background: var(--booking-white);
    color: var(--booking-blue);
    border: 1px dashed var(--booking-blue);
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

.btn-add-room:hover {
    background: var(--booking-light-blue);
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
    color: var(--booking-danger);
    border: 1px solid #fecaca;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid #a7f3d0;
}

/* Responsive */
@media (max-width: 768px) {
    .form-grid,
    .room-grid,
    .review-grid,
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
        <h1>Add New Property</h1>
        <p>Step <?php echo $currentStep; ?> of 6: <?php echo $stepTitles[$currentStep]; ?></p>
        
        <!-- Progress Bar -->
        <div class="step-indicators">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="step-indicator 
                <?php echo $i < $currentStep ? 'completed' : ''; ?> 
                <?php echo $i == $currentStep ? 'active' : ''; ?>">
            </div>
            <?php endfor; ?>
        </div>
        
        <!-- Step Labels -->
        <div class="step-labels">
            <div class="step-label <?php echo $currentStep == 1 ? 'active' : ''; ?>">Basic Info</div>
            <div class="step-label <?php echo $currentStep == 2 ? 'active' : ''; ?>">Location</div>
            <div class="step-label <?php echo $currentStep == 3 ? 'active' : ''; ?>">Amenities</div>
            <div class="step-label <?php echo $currentStep == 4 ? 'active' : ''; ?>">Rooms</div>
            <div class="step-label <?php echo $currentStep == 5 ? 'active' : ''; ?>">Photos</div>
            <div class="step-label <?php echo $currentStep == 6 ? 'active' : ''; ?>">Review</div>
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
                    <label class="form-label">Property Name <span class="required">*</span></label>
                    <input type="text" name="property_name" class="form-control" 
                           value="<?php echo $wizardData['property_name'] ?? ''; ?>" 
                           placeholder="e.g., Hotel des Mille Collines" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Property Type <span class="required">*</span></label>
                    <select name="property_type" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($propertyTypes as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($wizardData['property_type'] ?? '') == $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Star Rating</label>
                    <select name="star_rating" class="form-control">
                        <option value="0">Not Rated</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($wizardData['star_rating'] ?? 0) == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?> Star<?php echo $i > 1 ? 's' : ''; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Year Built</label>
                    <input type="number" name="year_built" class="form-control" 
                           value="<?php echo $wizardData['year_built'] ?? date('Y'); ?>" 
                           min="1900" max="<?php echo date('Y'); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Number of Rooms</label>
                    <input type="number" name="number_of_rooms" class="form-control" 
                           value="<?php echo $wizardData['number_of_rooms'] ?? 1; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Number of Floors</label>
                    <input type="number" name="number_of_floors" class="form-control" 
                           value="<?php echo $wizardData['number_of_floors'] ?? 1; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Check-in Time</label>
                    <input type="time" name="check_in_time" class="form-control" 
                           value="<?php echo $wizardData['check_in_time'] ?? '14:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Check-out Time</label>
                    <input type="time" name="check_out_time" class="form-control" 
                           value="<?php echo $wizardData['check_out_time'] ?? '11:00'; ?>">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4" 
                              placeholder="Describe your property, its unique features, and what guests can expect..."><?php echo $wizardData['description'] ?? ''; ?></textarea>
                    <div class="form-text">Minimum 100 characters recommended</div>
                </div>
            </div>
            
            <div class="wizard-actions">
                <a href="properties.php" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Cancel
                </a>
                <button type="submit" name="save_step1" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 2: LOCATION & CONTACT -->
        <?php elseif ($currentStep == 2): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[2]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[2]; ?></p>
            
            <input type="hidden" name="current_step" value="2">
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <input type="text" class="form-control" value="Rwanda" readonly disabled>
                </div>
                
                <div class="form-group">
                    <label class="form-label">City <span class="required">*</span></label>
                    <input type="text" name="city" class="form-control" 
                           value="<?php echo $wizardData['city'] ?? ''; ?>" 
                           placeholder="e.g., Kigali" required>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Address <span class="required">*</span></label>
                    <input type="text" name="address" class="form-control" 
                           value="<?php echo $wizardData['address'] ?? ''; ?>" 
                           placeholder="Street address, building number" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Neighborhood/District</label>
                    <input type="text" name="neighborhood" class="form-control" 
                           value="<?php echo $wizardData['neighborhood'] ?? ''; ?>" 
                           placeholder="e.g., Nyarugenge">
                </div>
                
                <div class="form-group">
                    <label class="form-label">ZIP/Postal Code</label>
                    <input type="text" name="zip_code" class="form-control" 
                           value="<?php echo $wizardData['zip_code'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Latitude</label>
                    <input type="text" name="latitude" class="form-control" 
                           value="<?php echo $wizardData['latitude'] ?? ''; ?>" 
                           placeholder="-1.9441">
                    <div class="form-text">Optional for map accuracy</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Longitude</label>
                    <input type="text" name="longitude" class="form-control" 
                           value="<?php echo $wizardData['longitude'] ?? ''; ?>" 
                           placeholder="30.0619">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" 
                           value="<?php echo $wizardData['phone'] ?? ''; ?>" 
                           placeholder="+250 788 123 456">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" 
                           value="<?php echo $wizardData['email'] ?? ''; ?>" 
                           placeholder="contact@property.com">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Website</label>
                    <input type="url" name="website" class="form-control" 
                           value="<?php echo $wizardData['website'] ?? ''; ?>" 
                           placeholder="https://www.yourproperty.com">
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
        
        <!-- STEP 3: AMENITIES & POLICIES -->
        <?php elseif ($currentStep == 3): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[3]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[3]; ?></p>
            
            <input type="hidden" name="current_step" value="3">
            
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 15px;">Property Amenities</h3>
                <div class="amenities-grid">
                    <?php foreach ($commonAmenities as $key => $label): ?>
                    <label class="amenity-checkbox">
                        <input type="checkbox" name="amenities[]" value="<?php echo $key; ?>"
                               <?php echo in_array($key, $wizardData['amenities'] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 15px;">Languages Spoken</h3>
                <div class="amenities-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <?php foreach ($languages as $key => $label): ?>
                    <label class="amenity-checkbox">
                        <input type="checkbox" name="languages[]" value="<?php echo $key; ?>"
                               <?php echo in_array($key, $wizardData['languages'] ?? []) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="margin-bottom: 30px;">
                <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 15px;">Property Policies</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="amenity-checkbox" style="justify-content: flex-start; background: white;">
                            <input type="checkbox" name="children_allowed" value="1"
                                   <?php echo ($wizardData['policies']['children'] ?? false) ? 'checked' : ''; ?>>
                            Children allowed
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="amenity-checkbox" style="justify-content: flex-start; background: white;">
                            <input type="checkbox" name="pets_allowed" value="1"
                                   <?php echo ($wizardData['policies']['pets'] ?? false) ? 'checked' : ''; ?>>
                            Pets allowed
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="amenity-checkbox" style="justify-content: flex-start; background: white;">
                            <input type="checkbox" name="smoking_allowed" value="1"
                                   <?php echo ($wizardData['policies']['smoking'] ?? false) ? 'checked' : ''; ?>>
                            Smoking allowed
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label class="amenity-checkbox" style="justify-content: flex-start; background: white;">
                            <input type="checkbox" name="parties_allowed" value="1"
                                   <?php echo ($wizardData['policies']['parties'] ?? false) ? 'checked' : ''; ?>>
                            Parties/events allowed
                        </label>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Check-in Instructions</label>
                        <textarea name="check_in_instructions" class="form-control" rows="3" 
                                  placeholder="Special instructions for check-in, door codes, etc."><?php echo $wizardData['policies']['check_in_instructions'] ?? ''; ?></textarea>
                    </div>
                </div>
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
        
        <!-- STEP 4: ROOMS -->
        <?php elseif ($currentStep == 4): ?>
        <form method="POST" id="roomsForm">
            <h2 class="step-title"><?php echo $stepTitles[4]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[4]; ?></p>
            
            <input type="hidden" name="current_step" value="4">
            <input type="hidden" name="room_count" id="room_count" value="<?php echo count($wizardData['rooms'] ?? [1]); ?>">
            
            <div id="rooms-container">
                <?php 
                $rooms = $wizardData['rooms'] ?? [['name' => '', 'description' => '', 'max_guests' => 2, 'num_rooms' => 1, 'size' => 0, 'bed_config' => '', 'price' => 0, 'amenities' => []]];
                foreach ($rooms as $index => $room): 
                ?>
                <div class="room-card" id="room_<?php echo $index; ?>">
                    <div class="room-card-header">
                        <h4 class="room-card-title">Room <?php echo $index + 1; ?></h4>
                        <?php if ($index > 0): ?>
                        <button type="button" class="room-card-remove" onclick="removeRoom(<?php echo $index; ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="room-grid">
                        <div class="form-group">
                            <label class="form-label">Room Name</label>
                            <input type="text" name="room_name_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['name']; ?>" placeholder="e.g., Deluxe Room">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Max Guests</label>
                            <input type="number" name="room_max_guests_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['max_guests']; ?>" min="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Quantity Available</label>
                            <input type="number" name="room_quantity_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['num_rooms']; ?>" min="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Room Size (m²)</label>
                            <input type="number" name="room_size_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['size']; ?>" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bed Configuration</label>
                            <input type="text" name="room_bed_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['bed_config']; ?>" placeholder="e.g., 1 King Bed">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Price per Night (RWF)</label>
                            <input type="number" name="room_price_<?php echo $index; ?>" class="form-control" 
                                   value="<?php echo $room['price']; ?>" min="0" step="1000">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Description</label>
                            <textarea name="room_description_<?php echo $index; ?>" class="form-control" rows="2"><?php echo $room['description']; ?></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Room Amenities</label>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                                <?php foreach ($roomAmenitiesList as $key => $label): ?>
                                <label class="amenity-checkbox" style="background: white;">
                                    <input type="checkbox" name="room_amenities_<?php echo $index; ?>[]" value="<?php echo $key; ?>"
                                           <?php echo in_array($key, $room['amenities'] ?? []) ? 'checked' : ''; ?>>
                                    <?php echo $label; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="btn-add-room" onclick="addRoom()">
                <i class="bi bi-plus-lg"></i> Add Another Room Type
            </button>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="3" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="save_step4" class="btn-wizard-next">
                    Continue <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 5: PHOTOS -->
        <?php elseif ($currentStep == 5): ?>
        <form method="POST" enctype="multipart/form-data" id="photoForm">
            <h2 class="step-title"><?php echo $stepTitles[5]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[5]; ?></p>
            
            <input type="hidden" name="current_step" value="5">
            
            <div class="photo-upload-area" onclick="document.getElementById('photoInput').click()">
                <i class="bi bi-cloud-upload"></i>
                <h4>Click to upload photos</h4>
                <p>or drag and drop (JPG, PNG) - Minimum 3 photos</p>
                <input type="file" name="photos[]" id="photoInput" multiple accept="image/*" style="display: none;">
            </div>
            
            <?php if (!empty($wizardData['photos'])): ?>
            <div class="photo-grid" id="photoGrid">
                <?php foreach ($wizardData['photos'] as $photo): ?>
                <div class="photo-item">
                    <img src="/gorwanda-plus/assets/images/stays/temp/<?php echo $photo; ?>" alt="Property photo">
                    <button type="submit" name="remove_photo" value="<?php echo $photo; ?>" class="photo-remove" onclick="return confirm('Remove this photo?')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="4" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="upload_photos" class="btn-wizard-next" style="background: var(--booking-success);">
                    <i class="bi bi-upload"></i> Upload Photos
                </button>
                <button type="submit" name="next_step" value="5" class="btn-wizard-next">
                    Skip for now <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        
        <!-- STEP 6: REVIEW & SUBMIT -->
        <?php elseif ($currentStep == 6): ?>
        <form method="POST">
            <h2 class="step-title"><?php echo $stepTitles[6]; ?></h2>
            <p class="step-description"><?php echo $stepDescriptions[6]; ?></p>
            
            <!-- Basic Info Review -->
            <div class="review-section">
                <h3>Basic Information</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-item-label">Property Name:</span>
                        <span class="review-item-value"><?php echo $wizardData['property_name'] ?? ''; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Type:</span>
                        <span class="review-item-value"><?php echo $propertyTypes[$wizardData['property_type']] ?? ''; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Star Rating:</span>
                        <span class="review-item-value"><?php echo $wizardData['star_rating'] ? $wizardData['star_rating'] . ' Stars' : 'Not Rated'; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Rooms:</span>
                        <span class="review-item-value"><?php echo count($wizardData['rooms'] ?? []); ?> types</span>
                    </div>
                </div>
            </div>
            
            <!-- Location Review -->
            <div class="review-section">
                <h3>Location</h3>
                <div class="review-grid">
                    <div class="review-item">
                        <span class="review-item-label">Address:</span>
                        <span class="review-item-value"><?php echo $wizardData['address'] ?? ''; ?>, <?php echo $wizardData['city'] ?? ''; ?></span>
                    </div>
                    <div class="review-item">
                        <span class="review-item-label">Contact:</span>
                        <span class="review-item-value"><?php echo $wizardData['phone'] ?? ''; ?> | <?php echo $wizardData['email'] ?? ''; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Amenities Review -->
            <?php if (!empty($wizardData['amenities'])): ?>
            <div class="review-section">
                <h3>Amenities</h3>
                <div class="review-amenities">
                    <?php foreach ($wizardData['amenities'] as $amenity): ?>
                    <span class="review-amenity-tag">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        <?php echo $commonAmenities[$amenity] ?? $amenity; ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Rooms Review -->
            <?php if (!empty($wizardData['rooms'])): ?>
            <div class="review-section">
                <h3>Rooms</h3>
                <?php foreach ($wizardData['rooms'] as $index => $room): ?>
                <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--booking-border);">
                    <div style="font-weight: 700; margin-bottom: 5px;"><?php echo $room['name']; ?></div>
                    <div style="font-size: 0.8125rem; color: var(--booking-text-light);">
                        <?php echo $room['max_guests']; ?> guests • <?php echo $room['num_rooms']; ?> rooms • 
                        <?php echo $room['size']; ?> m² • <?php echo formatPrice($room['price']); ?>/night
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Photos Review -->
            <?php if (!empty($wizardData['photos'])): ?>
            <div class="review-section">
                <h3>Photos (<?php echo count($wizardData['photos']); ?>)</h3>
                <div class="photo-grid" style="grid-template-columns: repeat(4, 1fr);">
                    <?php foreach (array_slice($wizardData['photos'], 0, 4) as $photo): ?>
                    <div class="photo-item">
                        <img src="/gorwanda-plus/assets/images/stays/temp/<?php echo $photo; ?>" alt="Property photo">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="wizard-actions">
                <button type="submit" name="prev_step" value="5" class="btn-wizard-prev">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
                <button type="submit" name="submit_property" class="btn-wizard-next" style="background: var(--booking-success);">
                    <i class="bi bi-check-lg"></i> Submit Property
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
let roomIndex = <?php echo count($wizardData['rooms'] ?? [1]); ?>;

function addRoom() {
    const container = document.getElementById('rooms-container');
    const newRoom = document.createElement('div');
    newRoom.className = 'room-card';
    newRoom.id = `room_${roomIndex}`;
    
    newRoom.innerHTML = `
        <div class="room-card-header">
            <h4 class="room-card-title">Room ${roomIndex + 1}</h4>
            <button type="button" class="room-card-remove" onclick="removeRoom(${roomIndex})">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        
        <div class="room-grid">
            <div class="form-group">
                <label class="form-label">Room Name</label>
                <input type="text" name="room_name_${roomIndex}" class="form-control" placeholder="e.g., Deluxe Room">
            </div>
            
            <div class="form-group">
                <label class="form-label">Max Guests</label>
                <input type="number" name="room_max_guests_${roomIndex}" class="form-control" value="2" min="1">
            </div>
            
            <div class="form-group">
                <label class="form-label">Quantity Available</label>
                <input type="number" name="room_quantity_${roomIndex}" class="form-control" value="1" min="1">
            </div>
            
            <div class="form-group">
                <label class="form-label">Room Size (m²)</label>
                <input type="number" name="room_size_${roomIndex}" class="form-control" value="0" min="0">
            </div>
            
            <div class="form-group">
                <label class="form-label">Bed Configuration</label>
                <input type="text" name="room_bed_${roomIndex}" class="form-control" placeholder="e.g., 1 King Bed">
            </div>
            
            <div class="form-group">
                <label class="form-label">Price per Night (RWF)</label>
                <input type="number" name="room_price_${roomIndex}" class="form-control" value="0" min="0" step="1000">
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">Description</label>
                <textarea name="room_description_${roomIndex}" class="form-control" rows="2"></textarea>
            </div>
            
            <div class="form-group full-width">
                <label class="form-label">Room Amenities</label>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                    <?php foreach ($roomAmenitiesList as $key => $label): ?>
                    <label class="amenity-checkbox" style="background: white;">
                        <input type="checkbox" name="room_amenities_${roomIndex}[]" value="<?php echo $key; ?>">
                        <?php echo $label; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newRoom);
    document.getElementById('room_count').value = roomIndex + 1;
    roomIndex++;
}

function removeRoom(index) {
    const room = document.getElementById(`room_${index}`);
    if (room) {
        room.remove();
        // Renumber remaining rooms
        const rooms = document.querySelectorAll('.room-card');
        rooms.forEach((room, idx) => {
            room.querySelector('.room-card-title').textContent = `Room ${idx + 1}`;
            room.id = `room_${idx}`;
            
            // Update input names
            room.querySelectorAll('input, select, textarea').forEach(input => {
                const name = input.getAttribute('name');
                if (name) {
                    input.setAttribute('name', name.replace(/_\d+_/, `_${idx}_`));
                }
            });
        });
        document.getElementById('room_count').value = rooms.length;
    }
}

// Auto-submit photo upload when files are selected
document.getElementById('photoInput')?.addEventListener('change', function() {
    document.getElementById('photoForm').submit();
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>