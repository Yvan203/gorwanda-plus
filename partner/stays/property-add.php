<?php
$pageTitle = 'Add New Property';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Initialize wizard session
if (!isset($_SESSION['property_wizard'])) {
    $_SESSION['property_wizard'] = ['step' => 1, 'data' => []];
}

$currentStep = $_SESSION['property_wizard']['step'] ?? 1;
$wizardData = $_SESSION['property_wizard']['data'] ?? [];
$error = '';
$success = '';

// ============================================
// HANDLE ALL STEPS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // STEP 1: Basic Information
    if (isset($_POST['save_step1'])) {
        $step1Data = [
            'property_name' => sanitize($_POST['property_name'] ?? ''),
            'property_type' => sanitize($_POST['property_type'] ?? ''),
            'star_rating' => intval($_POST['star_rating'] ?? 0),
            'description' => sanitize($_POST['description'] ?? ''),
            'check_in_time' => $_POST['check_in_time'] ?? '14:00',
            'check_out_time' => $_POST['check_out_time'] ?? '11:00'
        ];

        $errors = [];
        if (empty($step1Data['property_name'])) $errors[] = 'Property name is required';
        if (empty($step1Data['property_type'])) $errors[] = 'Please select a property type';

        if (empty($errors)) {
            $_SESSION['property_wizard']['data'] = array_merge($wizardData, $step1Data);
            $_SESSION['property_wizard']['step'] = 2;
            header('Location: property-add.php');
            exit;
        } else {
            $error = implode('<br>', $errors);
        }
    }

    // STEP 2: Location & Contact
    if (isset($_POST['save_step2'])) {
        $step2Data = [
            'city' => sanitize($_POST['city'] ?? ''),
            'address' => sanitize($_POST['address'] ?? ''),
            'neighborhood' => sanitize($_POST['neighborhood'] ?? ''),
            'zip_code' => sanitize($_POST['zip_code'] ?? ''),
            'latitude' => floatval($_POST['latitude'] ?? 0),
            'longitude' => floatval($_POST['longitude'] ?? 0),
            'phone' => sanitize($_POST['phone'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
            'website' => sanitize($_POST['website'] ?? ''),
            'location_id' => intval($_POST['location_id'] ?? 0)
        ];

        $errors = [];
        if (empty($step2Data['city'])) $errors[] = 'City is required';
        if (empty($step2Data['address'])) $errors[] = 'Address is required';

        if (empty($errors)) {
            $_SESSION['property_wizard']['data'] = array_merge($wizardData, $step2Data);
            $_SESSION['property_wizard']['step'] = 3;
            header('Location: property-add.php');
            exit;
        } else {
            $error = implode('<br>', $errors);
        }
    }

    // STEP 3: Amenities & Policies
    if (isset($_POST['save_step3'])) {
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
        header('Location: property-add.php');
        exit;
    }

    // STEP 4: Rooms
    if (isset($_POST['save_step4'])) {
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
        header('Location: property-add.php');
        exit;
    }

    // STEP 5: Photos
    if (isset($_POST['save_step5'])) {
        $uploadedPhotos = $_SESSION['property_wizard']['data']['photos'] ?? [];

        if (!empty($_FILES['photos']['name'][0])) {
            $uploadDir = dirname(__DIR__, 3) . '/assets/images/stays/temp/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['photos']['name'][$i], PATHINFO_EXTENSION);
                    $fileName = time() . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($tmpName, $uploadDir . $fileName)) {
                        $uploadedPhotos[] = $fileName;
                    }
                }
            }
        }

        // Handle photo removal
        if (isset($_POST['remove_photo'])) {
            $toRemove = $_POST['remove_photo'];
            $uploadedPhotos = array_values(array_diff($uploadedPhotos, [$toRemove]));
            $filePath = dirname(__DIR__, 3) . '/assets/images/stays/temp/' . $toRemove;
            if (file_exists($filePath)) unlink($filePath);
        }

        $_SESSION['property_wizard']['data']['photos'] = $uploadedPhotos;
        header('Location: property-add.php');
        exit;
    }

    // Navigation between steps
    if (isset($_POST['go_to_step'])) {
        $step = intval($_POST['go_to_step']);
        if ($step >= 1 && $step <= 6) {
            $_SESSION['property_wizard']['step'] = $step;
            header('Location: property-add.php');
            exit;
        }
    }

    // FINAL SUBMIT
    if (isset($_POST['submit_property'])) {
        $data = $_SESSION['property_wizard']['data'];

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($data['property_name']));
        $slug = trim($slug, '-') . '-' . time();

        $amenitiesJson = json_encode($data['amenities'] ?? []);
        $policiesJson = json_encode($data['policies'] ?? []);
        $languagesJson = json_encode($data['languages'] ?? []);

        $mainImage = $data['photos'][0] ?? null;

        $stmt = $db->prepare("
            INSERT INTO stays (owner_id, stay_name, slug, stay_type, description, star_rating, 
                address, city, neighborhood, zip_code, latitude, longitude, location_id,
                phone, email, website, check_in_time, check_out_time, amenities, policies, 
                languages_spoken, main_image, is_active, is_verified, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())
        ");

        $stmt->execute([
            $userId,
            $data['property_name'],
            $slug,
            $data['property_type'],
            $data['description'],
            $data['star_rating'],
            $data['address'],
            $data['city'],
            $data['neighborhood'] ?? '',
            $data['zip_code'] ?? '',
            $data['latitude'] ?? 0,
            $data['longitude'] ?? 0,
            $data['location_id'] ?? null,
            $data['phone'] ?? '',
            $data['email'] ?? '',
            $data['website'] ?? '',
            '14:00:00',
            '11:00:00',
            $amenitiesJson,
            $policiesJson,
            $languagesJson,
            $mainImage
        ]);

        $stayId = $db->lastInsertId();

        // Insert rooms
        if (!empty($data['rooms'])) {
            $roomStmt = $db->prepare("
                INSERT INTO stay_rooms (stay_id, room_name, description, max_guests, 
                    num_rooms_available, base_price, size_sqm, bed_configuration, room_amenities, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
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

            if (!empty($imagePaths)) {
                $stmt = $db->prepare("UPDATE stays SET images = ? WHERE stay_id = ?");
                $stmt->execute([json_encode($imagePaths), $stayId]);
            }
        }

        // Notify admin
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            SELECT user_id, 'vendor_registration', 'New Property Submitted', 
            CONCAT(?, ' added: ', ?), ?, NOW() FROM users WHERE user_type = 'admin'");
        $stmt->execute([sanitize($user['first_name']), $data['property_name'], json_encode(['stay_id' => $stayId])]);

        unset($_SESSION['property_wizard']);
        header('Location: property-success.php?id=' . $stayId);
        exit;
    }
}

// Back button
if (isset($_POST['prev_step'])) {
    $_SESSION['property_wizard']['step'] = max(1, $currentStep - 1);
    header('Location: property-add.php');
    exit;
}

// Skip photos
if (isset($_POST['skip_photos'])) {
    $_SESSION['property_wizard']['step'] = 6;
    header('Location: property-add.php');
    exit;
}

// ============================================
// DATA FOR TEMPLATES
// ============================================

$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();

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

$propertyAmenities = [
    'wifi' => ['name' => 'Free WiFi', 'icon' => 'bi-wifi'],
    'pool' => ['name' => 'Swimming Pool', 'icon' => 'bi-water'],
    'parking' => ['name' => 'Free Parking', 'icon' => 'bi-p-circle'],
    'restaurant' => ['name' => 'Restaurant', 'icon' => 'bi-shop'],
    'spa' => ['name' => 'Spa & Wellness', 'icon' => 'bi-droplet'],
    'gym' => ['name' => 'Fitness Center', 'icon' => 'bi-bicycle'],
    'bar' => ['name' => 'Bar/Lounge', 'icon' => 'bi-cup-straw'],
    'room_service' => ['name' => 'Room Service', 'icon' => 'bi-bell'],
    'ac' => ['name' => 'Air Conditioning', 'icon' => 'bi-snow'],
    'breakfast' => ['name' => 'Breakfast Included', 'icon' => 'bi-egg-fried'],
    'airport_shuttle' => ['name' => 'Airport Shuttle', 'icon' => 'bi-bus-front'],
    'laundry' => ['name' => 'Laundry Service', 'icon' => 'bi-droplet-half'],
    'pets' => ['name' => 'Pet Friendly', 'icon' => 'bi-heart'],
    'family_rooms' => ['name' => 'Family Rooms', 'icon' => 'bi-people'],
    'non_smoking' => ['name' => 'Non-smoking', 'icon' => 'bi-ban'],
    'business_center' => ['name' => 'Business Center', 'icon' => 'bi-briefcase']
];

$roomAmenitiesList = [
    'ac' => 'Air Conditioning',
    'tv' => 'Flat-screen TV',
    'wifi' => 'Free WiFi',
    'minibar' => 'Minibar',
    'safe' => 'In-room Safe',
    'balcony' => 'Balcony',
    'bathtub' => 'Bathtub',
    'shower' => 'Shower',
    'coffee_maker' => 'Coffee Maker',
    'desk' => 'Work Desk',
    'hair_dryer' => 'Hair Dryer',
    'iron' => 'Ironing',
    'kitchen' => 'Kitchenette',
    'view' => 'Mountain/City View'
];

$languages = ['en' => 'English', 'fr' => 'French', 'rw' => 'Kinyarwanda', 'sw' => 'Swahili'];

$stepInfo = [
    1 => ['title' => 'Basic Information', 'desc' => 'Tell us about your property', 'icon' => 'bi-info-circle'],
    2 => ['title' => 'Location & Contact', 'desc' => 'Where is your property located?', 'icon' => 'bi-geo-alt'],
    3 => ['title' => 'Amenities & Policies', 'desc' => 'What do you offer guests?', 'icon' => 'bi-grid-3x3-gap'],
    4 => ['title' => 'Rooms & Pricing', 'desc' => 'Set up your room types', 'icon' => 'bi-door-open'],
    5 => ['title' => 'Photos', 'desc' => 'Showcase your property', 'icon' => 'bi-camera'],
    6 => ['title' => 'Review & Submit', 'desc' => 'Final check before publishing', 'icon' => 'bi-check-circle']
];
?>

<style>
    /* ============================================ */
    /* PROPERTY WIZARD - BOOKING.COM INSPIRED */
    /* ============================================ */

    .wizard-wrapper {
        max-width: 900px;
        margin: 0 auto;
    }

    /* Progress Steps */
    .wizard-progress {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 16px;
        padding: 28px 32px;
        margin-bottom: 24px;
        position: relative;
        overflow: hidden;
    }

    .wizard-progress::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #003b95 0%, #0066ff 100%);
    }

    .wizard-progress h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 0 4px 0;
    }

    .wizard-progress p {
        font-size: 0.875rem;
        color: #6b6b6b;
        margin: 0;
    }

    /* Steps Timeline */
    .steps-timeline {
        display: flex;
        align-items: center;
        gap: 0;
        margin-top: 24px;
        position: relative;
    }

    .step-dot {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 700;
        flex-shrink: 0;
        transition: all 0.3s;
        position: relative;
        z-index: 1;
    }

    .step-dot.completed {
        background: #008009;
        color: white;
    }

    .step-dot.current {
        background: #003b95;
        color: white;
        box-shadow: 0 0 0 6px rgba(0, 59, 149, 0.15);
    }

    .step-dot.upcoming {
        background: #e5e7eb;
        color: #9ca3af;
    }

    .step-line {
        flex: 1;
        height: 3px;
        background: #e5e7eb;
        margin: 0 4px;
        border-radius: 2px;
        transition: background 0.3s;
    }

    .step-line.completed {
        background: #008009;
    }

    .step-info-mini {
        display: flex;
        justify-content: space-between;
        margin-top: 12px;
        font-size: 0.6875rem;
        color: #9ca3af;
    }

    .step-info-mini span.current {
        color: #003b95;
        font-weight: 600;
    }

    .step-info-mini span.completed {
        color: #008009;
    }

    /* Step Content Card */
    .step-content {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 16px;
        overflow: hidden;
    }

    .step-header {
        padding: 24px 32px;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .step-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: #f0f4ff;
        color: #003b95;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }

    .step-header h2 {
        font-size: 1.125rem;
        font-weight: 700;
        margin: 0 0 2px 0;
    }

    .step-header p {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0;
    }

    .step-body {
        padding: 32px;
    }

    /* Form Elements */
    .form-section {
        margin-bottom: 28px;
    }

    .form-section:last-child {
        margin-bottom: 0;
    }

    .form-section-title {
        font-size: 0.8125rem;
        font-weight: 700;
        color: #003b95;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .form-row.single {
        grid-template-columns: 1fr;
    }

    .form-row.triple {
        grid-template-columns: 1fr 1fr 1fr;
    }

    .form-group {
        margin-bottom: 0;
    }

    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 6px;
    }

    .form-label .required {
        color: #e21111;
        margin-left: 2px;
    }

    .form-input {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e7e7e7;
        border-radius: 10px;
        font-size: 0.875rem;
        transition: all 0.2s;
        background: #f9fafb;
        font-family: 'Inter', sans-serif;
    }

    .form-input:focus {
        outline: none;
        border-color: #003b95;
        background: white;
        box-shadow: 0 0 0 4px rgba(0, 59, 149, 0.08);
    }

    .form-input.error {
        border-color: #e21111;
        background: #fff5f5;
    }

    .form-hint {
        font-size: 0.6875rem;
        color: #9ca3af;
        margin-top: 4px;
    }

    select.form-input {
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b6b6b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 14px center;
        padding-right: 40px;
    }

    textarea.form-input {
        resize: vertical;
        min-height: 100px;
    }

    /* Amenities Grid */
    .amenities-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    .amenity-card {
        position: relative;
        cursor: pointer;
    }

    .amenity-card input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .amenity-card-label {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border: 1px solid #e7e7e7;
        border-radius: 10px;
        font-size: 0.8125rem;
        transition: all 0.2s;
        background: white;
        user-select: none;
    }

    .amenity-card-label:hover {
        border-color: #003b95;
        background: #f8faff;
    }

    .amenity-card input:checked+.amenity-card-label {
        border-color: #003b95;
        background: #f0f4ff;
        color: #003b95;
        font-weight: 600;
    }

    .amenity-card-label i {
        font-size: 1rem;
        color: #003b95;
    }

    /* Policy Toggles */
    .policy-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .policy-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        border: 1px solid #e7e7e7;
        border-radius: 10px;
        background: white;
        cursor: pointer;
        transition: all 0.2s;
    }

    .policy-toggle:hover {
        border-color: #003b95;
        background: #f8faff;
    }

    .policy-toggle input {
        display: none;
    }

    .policy-toggle .toggle-switch {
        width: 48px;
        height: 26px;
        background: #d1d5db;
        border-radius: 13px;
        position: relative;
        transition: background 0.3s;
    }

    .policy-toggle .toggle-switch::after {
        content: '';
        position: absolute;
        width: 22px;
        height: 22px;
        background: white;
        border-radius: 50%;
        top: 2px;
        left: 2px;
        transition: transform 0.3s;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    .policy-toggle input:checked~.toggle-switch {
        background: #008009;
    }

    .policy-toggle input:checked~.toggle-switch::after {
        transform: translateX(22px);
    }

    .policy-info {
        font-size: 0.8125rem;
        font-weight: 500;
    }

    /* Room Card */
    .room-card {
        background: #f9fafb;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        margin-bottom: 16px;
        overflow: hidden;
    }

    .room-card-header {
        padding: 14px 20px;
        background: white;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .room-card-title {
        font-weight: 700;
        font-size: 0.9375rem;
    }

    .room-card-remove {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: #fce8e8;
        color: #e21111;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .room-card-remove:hover {
        background: #e21111;
        color: white;
    }

    .room-card-body {
        padding: 20px;
    }

    /* Photo Upload */
    .photo-upload-zone {
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 48px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        background: #f9fafb;
    }

    .photo-upload-zone:hover {
        border-color: #003b95;
        background: #f0f4ff;
    }

    .photo-upload-zone i {
        font-size: 3rem;
        color: #9ca3af;
        margin-bottom: 16px;
    }

    .photo-upload-zone h4 {
        font-size: 1rem;
        font-weight: 600;
        margin: 0 0 4px 0;
    }

    .photo-upload-zone p {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0;
    }

    .photo-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-top: 20px;
    }

    .photo-item {
        aspect-ratio: 1;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        border: 1px solid #e7e7e7;
    }

    .photo-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .photo-item-remove {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.6);
        color: white;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        opacity: 0;
        transition: opacity 0.2s;
    }

    .photo-item:hover .photo-item-remove {
        opacity: 1;
    }

    .photo-item-remove:hover {
        background: #e21111;
    }

    /* Review Section */
    .review-block {
        background: #f9fafb;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 16px;
    }

    .review-block h4 {
        font-size: 0.8125rem;
        font-weight: 700;
        color: #003b95;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 12px 0;
    }

    .review-row {
        display: flex;
        gap: 32px;
        flex-wrap: wrap;
    }

    .review-item {
        display: flex;
        gap: 8px;
        align-items: baseline;
    }

    .review-label {
        font-size: 0.75rem;
        color: #6b6b6b;
    }

    .review-value {
        font-size: 0.9375rem;
        font-weight: 600;
    }

    .amenity-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .amenity-tag {
        padding: 6px 12px;
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 100px;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Action Bar */
    .step-actions {
        padding: 20px 32px;
        border-top: 1px solid #e7e7e7;
        background: #f9fafb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 0 0 16px 16px;
    }

    .btn-back {
        padding: 10px 20px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        background: white;
        color: #1a1a1a;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .btn-back:hover {
        background: #f3f4f6;
    }

    .btn-next {
        padding: 12px 28px;
        border: none;
        border-radius: 8px;
        background: #003b95;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }

    .btn-next:hover {
        background: #002d73;
        transform: translateY(-1px);
    }

    .btn-next.success {
        background: #008009;
    }

    .btn-next.success:hover {
        background: #006b07;
    }

    .btn-skip {
        padding: 10px 20px;
        border: none;
        background: transparent;
        color: #6b6b6b;
        font-size: 0.8125rem;
        cursor: pointer;
        text-decoration: underline;
    }

    /* Alert */
    .alert {
        padding: 14px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .alert-danger {
        background: #fce8e8;
        color: #e21111;
        border: 1px solid #fecaca;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .wizard-progress {
            padding: 20px;
        }

        .step-body {
            padding: 20px;
        }

        .step-actions {
            padding: 16px 20px;
            flex-direction: column;
            gap: 12px;
        }

        .btn-back,
        .btn-next {
            width: 100%;
            justify-content: center;
        }

        .form-row {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .form-row.triple {
            grid-template-columns: 1fr;
        }

        .amenities-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .policy-grid {
            grid-template-columns: 1fr;
        }

        .photo-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .steps-timeline {
            gap: 0;
        }

        .step-dot {
            width: 32px;
            height: 32px;
            font-size: 0.8125rem;
        }
    }
</style>

<div class="wizard-wrapper">
    <!-- Progress Header -->
    <div class="wizard-progress">
        <h1>Add New Property</h1>
        <p>Step <?php echo $currentStep; ?> of 6 — <?php echo $stepInfo[$currentStep]['desc']; ?></p>

        <!-- Steps Timeline -->
        <div class="steps-timeline">
            <?php for ($i = 1; $i <= 6; $i++):
                $status = $i < $currentStep ? 'completed' : ($i == $currentStep ? 'current' : 'upcoming');
                $icons = ['', '1', '2', '3', '4', '5', '6'];
            ?>
                <div class="step-dot <?php echo $status; ?>">
                    <?php if ($i < $currentStep): ?>
                        <i class="bi bi-check-lg"></i>
                    <?php else: ?>
                        <?php echo $i; ?>
                    <?php endif; ?>
                </div>
                <?php if ($i < 6): ?>
                    <div class="step-line <?php echo $i < $currentStep ? 'completed' : ''; ?>"></div>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <div class="step-info-mini">
            <?php foreach ($stepInfo as $i => $info): ?>
                <span class="<?php echo $i < $currentStep ? 'completed' : ($i == $currentStep ? 'current' : ''); ?>">
                    <?php echo explode(' ', $info['title'])[0]; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Error Message -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Step Content -->
    <div class="step-content">
        <div class="step-header">
            <div class="step-icon">
                <i class="bi <?php echo $stepInfo[$currentStep]['icon']; ?>"></i>
            </div>
            <div>
                <h2><?php echo $stepInfo[$currentStep]['title']; ?></h2>
                <p><?php echo $stepInfo[$currentStep]['desc']; ?></p>
            </div>
        </div>

        <div class="step-body">
            <!-- ============================================ -->
            <!-- STEP 1: BASIC INFORMATION -->
            <!-- ============================================ -->
            <?php if ($currentStep == 1): ?>
                <form method="POST">
                    <div class="form-section">
                        <div class="form-section-title">Property Details</div>
                        <div class="form-row">
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Property Name <span class="required">*</span></label>
                                <input type="text" name="property_name" class="form-input <?php echo isset($errors) && empty($_POST['property_name']) ? 'error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($wizardData['property_name'] ?? ''); ?>"
                                    placeholder="e.g., Kigali Marriott Hotel" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Property Type <span class="required">*</span></label>
                                <select name="property_type" class="form-input" required>
                                    <option value="">Select type...</option>
                                    <?php foreach ($propertyTypes as $k => $v): ?>
                                        <option value="<?php echo $k; ?>" <?php echo ($wizardData['property_type'] ?? '') == $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Star Rating</label>
                                <select name="star_rating" class="form-input">
                                    <option value="0">Not rated</option>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($wizardData['star_rating'] ?? 0) == $i ? 'selected' : ''; ?>>
                                            <?php echo str_repeat('★', $i) . str_repeat('☆', 5 - $i); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Schedule</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Check-in Time</label>
                                <input type="time" name="check_in_time" class="form-input" value="<?php echo $wizardData['check_in_time'] ?? '14:00'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Check-out Time</label>
                                <input type="time" name="check_out_time" class="form-input" value="<?php echo $wizardData['check_out_time'] ?? '11:00'; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Description</div>
                        <div class="form-group">
                            <textarea name="description" class="form-input" rows="6"
                                placeholder="Describe what makes your property special. Highlight unique features, nearby attractions, and the experience guests can expect..."><?php echo htmlspecialchars($wizardData['description'] ?? ''); ?></textarea>
                            <div class="form-hint">A detailed description helps guests understand what to expect</div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <a href="properties.php" class="btn-back">
                            <i class="bi bi-x-lg"></i> Cancel
                        </a>
                        <button type="submit" name="save_step1" class="btn-next">
                            Continue to Location <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>

                <!-- ============================================ -->
                <!-- STEP 2: LOCATION & CONTACT -->
                <!-- ============================================ -->
            <?php elseif ($currentStep == 2): ?>
                <form method="POST">
                    <div class="form-section">
                        <div class="form-section-title">Address</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Country</label>
                                <input type="text" class="form-input" value="Rwanda" readonly style="background: #f3f4f6; cursor: not-allowed;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">City <span class="required">*</span></label>
                                <input type="text" name="city" class="form-input" value="<?php echo htmlspecialchars($wizardData['city'] ?? ''); ?>" placeholder="e.g., Kigali" required>
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Street Address <span class="required">*</span></label>
                                <input type="text" name="address" class="form-input" value="<?php echo htmlspecialchars($wizardData['address'] ?? ''); ?>" placeholder="Street name, building number" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Area / District</label>
                                <select name="location_id" class="form-input">
                                    <option value="">Select area...</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?php echo $loc['location_id']; ?>" <?php echo ($wizardData['location_id'] ?? '') == $loc['location_id'] ? 'selected' : ''; ?>>
                                            <?php echo sanitize($loc['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Neighborhood</label>
                                <input type="text" name="neighborhood" class="form-input" value="<?php echo htmlspecialchars($wizardData['neighborhood'] ?? ''); ?>" placeholder="e.g., Nyarugenge">
                            </div>
                            <div class="form-group">
                                <label class="form-label">ZIP Code</label>
                                <input type="text" name="zip_code" class="form-input" value="<?php echo htmlspecialchars($wizardData['zip_code'] ?? ''); ?>" placeholder="Optional">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Coordinates</label>
                                <div class="form-row" style="gap: 10px;">
                                    <input type="text" name="latitude" class="form-input" value="<?php echo $wizardData['latitude'] ?? ''; ?>" placeholder="Latitude">
                                    <input type="text" name="longitude" class="form-input" value="<?php echo $wizardData['longitude'] ?? ''; ?>" placeholder="Longitude">
                                </div>
                                <div class="form-hint">Optional — helps with map accuracy</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Contact Information</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" name="phone" class="form-input" value="<?php echo htmlspecialchars($wizardData['phone'] ?? ''); ?>" placeholder="+250 788 123 456">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($wizardData['email'] ?? ''); ?>" placeholder="info@property.rw">
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label class="form-label">Website</label>
                                <input type="url" name="website" class="form-input" value="<?php echo htmlspecialchars($wizardData['website'] ?? ''); ?>" placeholder="https://www.yourproperty.com">
                            </div>
                        </div>
                    </div>

                    <div class="step-actions">
                        <button type="submit" name="prev_step" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button type="submit" name="save_step2" class="btn-next">
                            Continue to Amenities <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>

                <!-- ============================================ -->
                <!-- STEP 3: AMENITIES & POLICIES -->
                <!-- ============================================ -->
            <?php elseif ($currentStep == 3): ?>
                <form method="POST">
                    <div class="form-section">
                        <div class="form-section-title">Property Amenities</div>
                        <div class="amenities-grid">
                            <?php foreach ($propertyAmenities as $key => $amenity): ?>
                                <label class="amenity-card">
                                    <input type="checkbox" name="amenities[]" value="<?php echo $key; ?>"
                                        <?php echo in_array($key, $wizardData['amenities'] ?? []) ? 'checked' : ''; ?>>
                                    <span class="amenity-card-label">
                                        <i class="bi <?php echo $amenity['icon']; ?>"></i>
                                        <?php echo $amenity['name']; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Languages Spoken</div>
                        <div class="amenities-grid" style="grid-template-columns: repeat(4, 1fr);">
                            <?php foreach ($languages as $key => $label): ?>
                                <label class="amenity-card">
                                    <input type="checkbox" name="languages[]" value="<?php echo $key; ?>"
                                        <?php echo in_array($key, $wizardData['languages'] ?? []) ? 'checked' : ''; ?>>
                                    <span class="amenity-card-label">
                                        <i class="bi bi-translate"></i>
                                        <?php echo $label; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Property Policies</div>
                        <div class="policy-grid">
                            <label class="policy-toggle">
                                <span class="policy-info">
                                    <i class="bi bi-people me-2"></i> Children Allowed
                                </span>
                                <input type="checkbox" name="children_allowed" <?php echo ($wizardData['policies']['children'] ?? false) ? 'checked' : ''; ?>>
                                <span class="toggle-switch"></span>
                            </label>
                            <label class="policy-toggle">
                                <span class="policy-info">
                                    <i class="bi bi-heart me-2"></i> Pets Allowed
                                </span>
                                <input type="checkbox" name="pets_allowed" <?php echo ($wizardData['policies']['pets'] ?? false) ? 'checked' : ''; ?>>
                                <span class="toggle-switch"></span>
                            </label>
                            <label class="policy-toggle">
                                <span class="policy-info">
                                    <i class="bi bi-ban me-2"></i> Smoking Allowed
                                </span>
                                <input type="checkbox" name="smoking_allowed" <?php echo ($wizardData['policies']['smoking'] ?? false) ? 'checked' : ''; ?>>
                                <span class="toggle-switch"></span>
                            </label>
                            <label class="policy-toggle">
                                <span class="policy-info">
                                    <i class="bi bi-music-note me-2"></i> Parties/Events
                                </span>
                                <input type="checkbox" name="parties_allowed" <?php echo ($wizardData['policies']['parties'] ?? false) ? 'checked' : ''; ?>>
                                <span class="toggle-switch"></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">Check-in Instructions</div>
                        <textarea name="check_in_instructions" class="form-input" rows="3"
                            placeholder="Special instructions for guests (door codes, key pickup, etc.)"><?php echo htmlspecialchars($wizardData['policies']['check_in_instructions'] ?? ''); ?></textarea>
                    </div>

                    <div class="step-actions">
                        <button type="submit" name="prev_step" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button type="submit" name="save_step3" class="btn-next">
                            Continue to Rooms <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>

                <!-- ============================================ -->
                <!-- STEP 4: ROOMS -->
                <!-- ============================================ -->
            <?php elseif ($currentStep == 4): ?>
                <form method="POST" id="roomsForm">
                    <input type="hidden" name="room_count" id="roomCount" value="<?php echo max(1, count($wizardData['rooms'] ?? [])); ?>">

                    <div id="roomsContainer">
                        <?php
                        $rooms = $wizardData['rooms'] ?? [['name' => '', 'description' => '', 'max_guests' => 2, 'num_rooms' => 1, 'size' => 0, 'bed_config' => '', 'price' => 0, 'amenities' => []]];
                        foreach ($rooms as $i => $room):
                        ?>
                            <div class="room-card" id="room_<?php echo $i; ?>">
                                <div class="room-card-header">
                                    <span class="room-card-title">
                                        <i class="bi bi-door-open me-2" style="color: #003b95;"></i>
                                        Room Type <?php echo $i + 1; ?>
                                    </span>
                                    <?php if ($i > 0): ?>
                                        <button type="button" class="room-card-remove" onclick="removeRoom(<?php echo $i; ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <div class="room-card-body">
                                    <div class="form-row">
                                        <div class="form-group" style="grid-column: span 2;">
                                            <label class="form-label">Room Name</label>
                                            <input type="text" name="room_name_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo htmlspecialchars($room['name']); ?>"
                                                placeholder="e.g., Deluxe Suite, Standard Room">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Max Guests</label>
                                            <input type="number" name="room_max_guests_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo $room['max_guests']; ?>" min="1" max="20">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Quantity Available</label>
                                            <input type="number" name="room_quantity_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo $room['num_rooms']; ?>" min="1">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Size (m²)</label>
                                            <input type="number" name="room_size_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo $room['size']; ?>" min="0">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Bed Configuration</label>
                                            <input type="text" name="room_bed_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo htmlspecialchars($room['bed_config']); ?>"
                                                placeholder="e.g., 1 King Bed">
                                        </div>
                                        <div class="form-group" style="grid-column: span 2;">
                                            <label class="form-label">Price per Night (RWF) <span class="required">*</span></label>
                                            <input type="number" name="room_price_<?php echo $i; ?>" class="form-input"
                                                value="<?php echo $room['price']; ?>" min="0" step="1000" placeholder="0">
                                        </div>
                                        <div class="form-group" style="grid-column: span 2;">
                                            <label class="form-label">Description</label>
                                            <textarea name="room_description_<?php echo $i; ?>" class="form-input" rows="2"
                                                placeholder="Describe this room type..."><?php echo htmlspecialchars($room['description']); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="form-section-title" style="margin-top: 20px;">Room Amenities</div>
                                    <div class="amenities-grid">
                                        <?php foreach ($roomAmenitiesList as $key => $label): ?>
                                            <label class="amenity-card">
                                                <input type="checkbox" name="room_amenities_<?php echo $i; ?>[]" value="<?php echo $key; ?>"
                                                    <?php echo in_array($key, $room['amenities'] ?? []) ? 'checked' : ''; ?>>
                                                <span class="amenity-card-label"><?php echo $label; ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" onclick="addRoom()" style="width: 100%; padding: 16px; border: 2px dashed #d1d5db; border-radius: 12px; background: transparent; color: #003b95; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px;">
                        <i class="bi bi-plus-lg"></i> Add Another Room Type
                    </button>

                    <div class="step-actions" style="margin-top: 24px;">
                        <button type="submit" name="prev_step" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button type="submit" name="save_step4" class="btn-next">
                            Continue to Photos <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>

                <!-- ============================================ -->
                <!-- STEP 5: PHOTOS -->
                <!-- ============================================ -->
            <?php elseif ($currentStep == 5): ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="photo-upload-zone" onclick="document.getElementById('photoInput').click()">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <h4>Upload Property Photos</h4>
                        <p>Click to browse or drag and drop images here</p>
                        <p style="font-size: 0.75rem; color: #9ca3af; margin-top: 4px;">JPG, PNG or WebP — Minimum 3 photos recommended</p>
                        <input type="file" name="photos[]" id="photoInput" multiple accept="image/*" style="display: none;" onchange="this.form.submit()">
                    </div>

                    <?php $photos = $wizardData['photos'] ?? []; ?>
                    <?php if (!empty($photos)): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0 12px;">
                            <span style="font-weight: 600; font-size: 0.875rem;"><?php echo count($photos); ?> photo(s) uploaded</span>
                            <span style="font-size: 0.75rem; color: #6b6b6b;">First photo will be the main image</span>
                        </div>
                        <div class="photo-grid">
                            <?php foreach ($photos as $photo): ?>
                                <div class="photo-item">
                                    <img src="/gorwanda-plus/assets/images/stays/temp/<?php echo $photo; ?>" alt="Property photo">
                                    <button type="submit" name="remove_photo" value="<?php echo $photo; ?>" class="photo-item-remove" title="Remove photo">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="step-actions">
                        <button type="submit" name="prev_step" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <div style="display: flex; gap: 12px;">
                            <button type="submit" name="skip_photos" class="btn-skip">Skip for now</button>
                            <button type="submit" name="go_to_step" value="6" class="btn-next success">
                                Continue to Review <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- ============================================ -->
                <!-- STEP 6: REVIEW & SUBMIT -->
                <!-- ============================================ -->
            <?php elseif ($currentStep == 6): ?>
                <form method="POST">
                    <!-- Basic Info -->
                    <div class="review-block">
                        <h4><i class="bi bi-info-circle me-2"></i> Basic Information</h4>
                        <div class="review-row">
                            <div class="review-item">
                                <span class="review-label">Name:</span>
                                <span class="review-value"><?php echo htmlspecialchars($wizardData['property_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="review-item">
                                <span class="review-label">Type:</span>
                                <span class="review-value"><?php echo $propertyTypes[$wizardData['property_type']] ?? 'N/A'; ?></span>
                            </div>
                            <div class="review-item">
                                <span class="review-label">Rating:</span>
                                <span class="review-value"><?php echo ($wizardData['star_rating'] ?? 0) ? $wizardData['star_rating'] . ' ★' : 'Not rated'; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Location -->
                    <div class="review-block">
                        <h4><i class="bi bi-geo-alt me-2"></i> Location</h4>
                        <div class="review-row">
                            <div class="review-item">
                                <span class="review-label">Address:</span>
                                <span class="review-value"><?php echo htmlspecialchars(($wizardData['address'] ?? '') . ', ' . ($wizardData['city'] ?? '')); ?></span>
                            </div>
                            <div class="review-item">
                                <span class="review-label">Contact:</span>
                                <span class="review-value"><?php echo htmlspecialchars(($wizardData['phone'] ?? 'N/A') . ' | ' . ($wizardData['email'] ?? 'N/A')); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Amenities -->
                    <?php if (!empty($wizardData['amenities'])): ?>
                        <div class="review-block">
                            <h4><i class="bi bi-grid-3x3-gap me-2"></i> Amenities</h4>
                            <div class="amenity-tags">
                                <?php foreach ($wizardData['amenities'] as $a):
                                    $aData = $propertyAmenities[$a] ?? null;
                                ?>
                                    <span class="amenity-tag">
                                        <?php if ($aData): ?>
                                            <i class="bi <?php echo $aData['icon']; ?>"></i> <?php echo $aData['name']; ?>
                                        <?php else: ?>
                                            <?php echo ucfirst(str_replace('_', ' ', $a)); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Rooms -->
                    <?php if (!empty($wizardData['rooms'])): ?>
                        <div class="review-block">
                            <h4><i class="bi bi-door-open me-2"></i> Rooms (<?php echo count($wizardData['rooms']); ?> types)</h4>
                            <?php foreach ($wizardData['rooms'] as $room): ?>
                                <div style="padding: 12px; background: white; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e7e7e7;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <strong><?php echo htmlspecialchars($room['name']); ?></strong>
                                            <span style="font-size: 0.75rem; color: #6b6b6b; margin-left: 8px;">
                                                <?php echo $room['max_guests']; ?> guests · <?php echo $room['num_rooms']; ?> available · <?php echo $room['size']; ?>m²
                                            </span>
                                        </div>
                                        <strong style="color: #008009;"><?php echo formatPrice($room['price']); ?>/night</strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Photos -->
                    <?php if (!empty($photos)): ?>
                        <div class="review-block">
                            <h4><i class="bi bi-camera me-2"></i> Photos (<?php echo count($photos); ?>)</h4>
                            <div style="display: flex; gap: 10px; overflow-x: auto; padding-bottom: 8px;">
                                <?php foreach (array_slice($photos, 0, 4) as $photo): ?>
                                    <img src="/gorwanda-plus/assets/images/stays/temp/<?php echo $photo; ?>"
                                        style="width: 120px; height: 90px; object-fit: cover; border-radius: 8px; flex-shrink: 0;">
                                <?php endforeach; ?>
                                <?php if (count($photos) > 4): ?>
                                    <div style="width: 120px; height: 90px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #6b6b6b; font-size: 0.875rem;">
                                        +<?php echo count($photos) - 4; ?> more
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="step-actions">
                        <button type="submit" name="prev_step" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back
                        </button>
                        <button type="submit" name="submit_property" class="btn-next success">
                            <i class="bi bi-check-lg"></i> Submit Property for Review
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    let roomIndex = <?php echo count($wizardData['rooms'] ?? [1]); ?>;

    function addRoom() {
        const container = document.getElementById('roomsContainer');
        const html = `
        <div class="room-card" id="room_${roomIndex}">
            <div class="room-card-header">
                <span class="room-card-title">
                    <i class="bi bi-door-open me-2" style="color: #003b95;"></i>
                    Room Type ${roomIndex + 1}
                </span>
                <button type="button" class="room-card-remove" onclick="removeRoom(${roomIndex})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div class="room-card-body">
                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Room Name</label>
                        <input type="text" name="room_name_${roomIndex}" class="form-input" placeholder="e.g., Deluxe Suite">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Guests</label>
                        <input type="number" name="room_max_guests_${roomIndex}" class="form-input" value="2" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Quantity Available</label>
                        <input type="number" name="room_quantity_${roomIndex}" class="form-input" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Size (m²)</label>
                        <input type="number" name="room_size_${roomIndex}" class="form-input" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Bed Configuration</label>
                        <input type="text" name="room_bed_${roomIndex}" class="form-input" placeholder="e.g., 1 King Bed">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Price per Night (RWF) <span class="required">*</span></label>
                        <input type="number" name="room_price_${roomIndex}" class="form-input" value="0" min="0" step="1000">
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Description</label>
                        <textarea name="room_description_${roomIndex}" class="form-input" rows="2" placeholder="Describe this room type..."></textarea>
                    </div>
                </div>
                <div class="form-section-title" style="margin-top: 20px;">Room Amenities</div>
                <div class="amenities-grid">
                    <?php foreach ($roomAmenitiesList as $key => $label): ?>
                    <label class="amenity-card">
                        <input type="checkbox" name="room_amenities_${roomIndex}[]" value="<?php echo $key; ?>">
                        <span class="amenity-card-label"><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>`;

        container.insertAdjacentHTML('beforeend', html);
        document.getElementById('roomCount').value = roomIndex + 1;
        roomIndex++;

        // Scroll to new room
        document.getElementById(`room_${roomIndex - 1}`).scrollIntoView({
            behavior: 'smooth'
        });
    }

    function removeRoom(index) {
        const room = document.getElementById(`room_${index}`);
        if (room) room.remove();

        // Update room count
        const remainingRooms = document.querySelectorAll('.room-card').length;
        document.getElementById('roomCount').value = Math.max(1, remainingRooms);
    }
</script>

<?php require_once 'includes/stays_footer.php'; ?>