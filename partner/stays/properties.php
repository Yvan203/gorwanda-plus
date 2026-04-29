<?php
$pageTitle = 'Properties';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// HANDLE PROPERTY ACTIONS
// ============================================
$message = '';
$error = '';

// Add/Edit Property
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_property'])) {
    $propertyId = intval($_POST['property_id'] ?? 0);
    $propertyName = sanitize($_POST['property_name']);
    $propertyType = sanitize($_POST['property_type']);
    $description = sanitize($_POST['description']);
    $starRating = intval($_POST['star_rating'] ?? 0);
    $address = sanitize($_POST['address']);
    $city = sanitize($_POST['city']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $checkInTime = $_POST['check_in_time'] ?? '14:00';
    $checkOutTime = $_POST['check_out_time'] ?? '11:00';
    $amenities = json_encode($_POST['amenities'] ?? []);
    $locationId = intval($_POST['location_id'] ?? 0);

    // Handle image upload
    $mainImage = '';
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = dirname(__DIR__, 3) . '/assets/images/stays/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileExt = strtolower(pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($fileExt, $allowed)) {
            $fileName = time() . '_' . uniqid() . '.' . $fileExt;
            if (move_uploaded_file($_FILES['main_image']['tmp_name'], $uploadDir . $fileName)) {
                $mainImage = $fileName;
            }
        }
    }

    if ($propertyId > 0) {
        // Update existing property
        if ($mainImage) {
            $stmt = $db->prepare("UPDATE stays SET stay_name=?, stay_type=?, description=?, star_rating=?, address=?, city=?, location_id=?, phone=?, email=?, check_in_time=?, check_out_time=?, amenities=?, main_image=?, updated_at=NOW() WHERE stay_id=? AND owner_id=?");
            $stmt->execute([$propertyName, $propertyType, $description, $starRating, $address, $city, $locationId, $phone, $email, $checkInTime, $checkOutTime, $amenities, $mainImage, $propertyId, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE stays SET stay_name=?, stay_type=?, description=?, star_rating=?, address=?, city=?, location_id=?, phone=?, email=?, check_in_time=?, check_out_time=?, amenities=?, updated_at=NOW() WHERE stay_id=? AND owner_id=?");
            $stmt->execute([$propertyName, $propertyType, $description, $starRating, $address, $city, $locationId, $phone, $email, $checkInTime, $checkOutTime, $amenities, $propertyId, $userId]);
        }
        $message = "Property updated successfully!";
    } else {
        // Insert new property
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($propertyName));
        $slug = trim($slug, '-') . '-' . time();

        $stmt = $db->prepare("INSERT INTO stays (owner_id, stay_name, slug, stay_type, description, star_rating, address, city, location_id, phone, email, check_in_time, check_out_time, amenities, main_image, is_active, is_verified, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())");
        $stmt->execute([$userId, $propertyName, $slug, $propertyType, $description, $starRating, $address, $city, $locationId, $phone, $email, $checkInTime, $checkOutTime, $amenities, $mainImage]);

        // Notify admin
        $newPropertyId = $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, data, created_at) 
            SELECT user_id, 'vendor_registration', 'New Property Added', 
            CONCAT(?, ' added: ', ?), ?, NOW() 
            FROM users WHERE user_type = 'admin'");
        $stmt->execute([sanitize($user['first_name'] . ' ' . $user['last_name']), $propertyName, json_encode(['stay_id' => $newPropertyId, 'stay_name' => $propertyName])]);

        $message = "Property added successfully! It will be reviewed shortly.";
    }
}

// Delete Property
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_property'])) {
    $propertyId = intval($_POST['property_id']);

    $stmt = $db->prepare("SELECT stay_name FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$propertyId, $userId]);
    $propData = $stmt->fetch();

    if ($propData) {
        $stmt = $db->prepare("DELETE FROM stay_rooms WHERE stay_id = ?");
        $stmt->execute([$propertyId]);

        $stmt = $db->prepare("DELETE FROM stays WHERE stay_id = ? AND owner_id = ?");
        $stmt->execute([$propertyId, $userId]);

        $message = "Property deleted successfully!";
    }
}

// Toggle Property Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $propertyId = intval($_POST['property_id']);
    $newStatus = intval($_POST['new_status']);

    $stmt = $db->prepare("UPDATE stays SET is_active = ?, updated_at = NOW() WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$newStatus, $propertyId, $userId]);

    header('Location: properties.php?msg=status_updated');
    exit;
}

// Check for success message from redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'status_updated') {
    $message = "Property status updated successfully!";
}

// ============================================
// GET ALL PROPERTIES
// ============================================

$stmt = $db->prepare("
    SELECT 
        s.*,
        l.name as location_name,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as total_rooms,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as active_rooms,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status = 'confirmed' AND b.check_in_date >= CURDATE()) as upcoming_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT COALESCE(AVG(r.overall_rating), 0) FROM reviews r WHERE r.stay_id = s.stay_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.stay_id = s.stay_id) as review_count,
        (SELECT MIN(sr2.base_price) FROM stay_rooms sr2 WHERE sr2.stay_id = s.stay_id AND sr2.is_active = 1 AND sr2.base_price > 0) as min_price
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.owner_id = ?
    ORDER BY s.is_active DESC, s.is_verified DESC, s.created_at DESC
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Stats
$totalProperties = count($properties);
$activeProperties = 0;
$totalRooms = 0;
$totalRevenue = 0;
$totalPending = 0;

foreach ($properties as $p) {
    if ($p['is_active']) $activeProperties++;
    $totalRooms += $p['active_rooms'];
    $totalRevenue += $p['total_revenue'];
    $totalPending += $p['pending_bookings'];
}

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

$commonAmenities = [
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
    'laundry' => ['name' => 'Laundry Service', 'icon' => 'bi-laptop'],
    'pets' => ['name' => 'Pet Friendly', 'icon' => 'bi-heart'],
    'family_rooms' => ['name' => 'Family Rooms', 'icon' => 'bi-people'],
    'non_smoking' => ['name' => 'Non-smoking', 'icon' => 'bi-ban'],
    'business_center' => ['name' => 'Business Center', 'icon' => 'bi-briefcase']
];

$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<style>
    /* ============================================ */
    /* PROPERTIES PAGE - BOOKING.COM INSPIRED */
    /* ============================================ */

    /* Page Header */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 4px 0;
    }

    .page-subtitle {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0;
    }

    /* Quick Stats */
    .stats-overview {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 24px;
    }

    .overview-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        padding: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .overview-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .overview-info h4 {
        font-size: 1.25rem;
        font-weight: 700;
        margin: 0 0 2px 0;
        color: #1a1a1a;
    }

    .overview-info p {
        font-size: 0.75rem;
        color: #6b6b6b;
        margin: 0;
    }

    /* Filter Bar */
    .properties-filter {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }

    .filter-search-wrapper {
        flex: 1;
        min-width: 200px;
        position: relative;
    }

    .filter-search-wrapper i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 1rem;
    }

    .filter-search-wrapper input {
        width: 100%;
        padding: 10px 16px 10px 42px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 0.875rem;
        background: #f9fafb;
    }

    .filter-search-wrapper input:focus {
        outline: none;
        border-color: #003b95;
        background: white;
    }

    .filter-select {
        padding: 10px 36px 10px 14px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 0.8125rem;
        background: #f9fafb;
        color: #1a1a1a;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%236b6b6b' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        min-width: 140px;
    }

    .filter-count {
        font-size: 0.75rem;
        color: #6b6b6b;
        white-space: nowrap;
    }

    /* Properties Grid */
    .properties-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 20px;
    }

    /* Property Card */
    .property-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.2s;
    }

    .property-card:hover {
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .property-image {
        height: 200px;
        background-size: cover;
        background-position: center;
        position: relative;
    }

    .property-image-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, transparent 40%, rgba(0, 0, 0, 0.7) 100%);
    }

    .property-image-badges {
        position: absolute;
        top: 12px;
        left: 12px;
        right: 12px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        z-index: 2;
    }

    .property-status {
        padding: 5px 12px;
        border-radius: 100px;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    .status-verified {
        background: rgba(0, 128, 9, 0.9);
        color: white;
    }

    .status-pending {
        background: rgba(255, 140, 0, 0.9);
        color: white;
    }

    .status-inactive {
        background: rgba(107, 107, 107, 0.9);
        color: white;
    }

    .property-quick-actions {
        display: flex;
        gap: 6px;
    }

    .quick-action-btn {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.95);
        border: none;
        color: #1a1a1a;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.875rem;
        transition: all 0.15s;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .quick-action-btn:hover {
        transform: scale(1.1);
    }

    .quick-action-btn.edit:hover {
        background: #003b95;
        color: white;
    }

    .quick-action-btn.toggle:hover {
        background: #ff8c00;
        color: white;
    }

    .quick-action-btn.delete-btn:hover {
        background: #e21111;
        color: white;
    }

    .property-image-info {
        position: absolute;
        bottom: 12px;
        left: 12px;
        right: 60px;
        z-index: 2;
        color: white;
    }

    .property-image-name {
        font-size: 1.125rem;
        font-weight: 700;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    .property-image-location {
        font-size: 0.75rem;
        opacity: 0.9;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .property-image-rating {
        position: absolute;
        bottom: 12px;
        right: 12px;
        z-index: 2;
        background: #003b95;
        color: white;
        padding: 4px 10px;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 700;
    }

    /* Property Details */
    .property-details {
        padding: 16px 20px;
    }

    .property-meta {
        display: flex;
        gap: 20px;
        margin-bottom: 16px;
        padding-bottom: 16px;
        border-bottom: 1px solid #f3f4f6;
    }

    .meta-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
    }

    .meta-value {
        font-size: 1rem;
        font-weight: 700;
        color: #1a1a1a;
    }

    .meta-label {
        font-size: 0.6875rem;
        color: #6b6b6b;
        text-transform: uppercase;
    }

    .property-amenities {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
    }

    .amenity-tag {
        padding: 4px 10px;
        background: #f3f4f6;
        border-radius: 100px;
        font-size: 0.6875rem;
        color: #6b6b6b;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .property-actions-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 8px;
    }

    .property-action-btn {
        padding: 10px;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border: 1px solid #e7e7e7;
        background: white;
        color: #1a1a1a;
    }

    .property-action-btn:hover {
        background: #f9fafb;
        border-color: #003b95;
        color: #003b95;
    }

    .property-action-btn.primary {
        background: #003b95;
        color: white;
        border-color: #003b95;
    }

    .property-action-btn.primary:hover {
        background: #002d73;
    }

    /* Modal - FIXED */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        overflow-y: auto;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding-top: 30px;
        padding-bottom: 30px;
    }

    .modal-dialog {
        background: white;
        border-radius: 16px;
        width: 100%;
        max-width: 650px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        animation: modalSlideIn 0.3s ease;
        margin: auto;
        position: relative;
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: white;
        border-radius: 16px 16px 0 0;
        z-index: 2;
    }

    .modal-header h3 {
        font-size: 1.125rem;
        font-weight: 700;
        margin: 0;
    }

    .modal-close {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: #f3f4f6;
        color: #6b6b6b;
        cursor: pointer;
        font-size: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        flex-shrink: 0;
    }

    .modal-close:hover {
        background: #e21111;
        color: white;
    }

    .modal-body {
        padding: 24px;
    }

    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e7e7e7;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #f9fafb;
        border-radius: 0 0 16px 16px;
        position: sticky;
        bottom: 0;
        z-index: 2;
    }

    /* Form Styles */
    .form-section {
        margin-bottom: 24px;
    }

    .form-section-title {
        font-size: 0.875rem;
        font-weight: 700;
        margin-bottom: 16px;
        color: #003b95;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 6px;
        color: #1a1a1a;
    }

    .form-label .required {
        color: #e21111;
    }

    .form-input {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 0.875rem;
        transition: all 0.2s;
        background: #f9fafb;
    }

    .form-input:focus {
        outline: none;
        border-color: #003b95;
        background: white;
        box-shadow: 0 0 0 3px rgba(0, 59, 149, 0.1);
    }

    textarea.form-input {
        resize: vertical;
        min-height: 100px;
    }

    /* Amenities */
    .amenities-selector {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        max-height: 250px;
        overflow-y: auto;
        padding: 4px;
    }

    .amenity-option {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.8125rem;
        background: white;
        user-select: none;
    }

    .amenity-option:hover {
        border-color: #003b95;
        background: #f8faff;
    }

    .amenity-option.selected {
        border-color: #003b95;
        background: #f0f4ff;
        color: #003b95;
    }

    .amenity-option input[type="checkbox"] {
        display: none;
    }

    .amenity-option .check-icon {
        width: 20px;
        height: 20px;
        border: 2px solid #d1d5db;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        flex-shrink: 0;
        font-size: 0.625rem;
        color: transparent;
    }

    .amenity-option.selected .check-icon {
        background: #003b95;
        border-color: #003b95;
        color: white;
    }

    /* Star Rating */
    .star-rating-input {
        display: flex;
        gap: 4px;
        font-size: 1.5rem;
    }

    .star-rating-input i {
        cursor: pointer;
        color: #d1d5db;
        transition: color 0.15s;
    }

    .star-rating-input i.active {
        color: #febb02;
    }

    /* Toast */
    .alert-toast {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 20000;
        padding: 14px 20px;
        border-radius: 12px;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.3s ease;
    }

    .alert-success {
        background: #e6f4ea;
        color: #008009;
        border: 1px solid #a7f3d0;
    }

    .alert-error {
        background: #fce8e8;
        color: #e21111;
        border: 1px solid #fecaca;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: white;
        border-radius: 16px;
        border: 1px solid #e7e7e7;
        grid-column: 1 / -1;
    }

    .empty-state-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 2rem;
        color: #9ca3af;
    }

    /* Delete Modal */
    .delete-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: #fce8e8;
        color: #e21111;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin: 0 auto 16px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .stats-overview {
            grid-template-columns: repeat(2, 1fr);
        }

        .properties-grid {
            grid-template-columns: 1fr;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .amenities-selector {
            grid-template-columns: 1fr;
        }

        .property-actions-row {
            grid-template-columns: 1fr;
        }

        .properties-filter {
            flex-direction: column;
        }

        .modal-dialog {
            max-width: 95%;
        }
    }
</style>

<!-- ============================================ -->
<!-- PAGE CONTENT -->
<!-- ============================================ -->

<div>
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="bi bi-building me-2" style="color: #003b95;"></i>Properties</h1>
            <p class="page-subtitle">Manage your accommodations and track performance</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="btn-secondary" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
            <button class="btn-primary" onclick="openPropertyModal()">
                <i class="bi bi-plus-lg"></i> Add Property
            </button>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-overview">
        <div class="overview-card">
            <div class="overview-icon" style="background: #f0f4ff; color: #003b95;">
                <i class="bi bi-building"></i>
            </div>
            <div class="overview-info">
                <h4><?php echo $totalProperties; ?></h4>
                <p>Properties (<?php echo $activeProperties; ?> active)</p>
            </div>
        </div>
        <div class="overview-card">
            <div class="overview-icon" style="background: #e6f4ea; color: #008009;">
                <i class="bi bi-door-open"></i>
            </div>
            <div class="overview-info">
                <h4><?php echo $totalRooms; ?></h4>
                <p>Active Rooms</p>
            </div>
        </div>
        <div class="overview-card">
            <div class="overview-icon" style="background: #fff4e6; color: #ff8c00;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="overview-info">
                <h4><?php echo $totalPending; ?></h4>
                <p>Pending Bookings</p>
            </div>
        </div>
        <div class="overview-card">
            <div class="overview-icon" style="background: #e6f4ea; color: #008009;">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div class="overview-info">
                <h4><?php echo formatPrice($totalRevenue); ?></h4>
                <p>Total Revenue</p>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="properties-filter">
        <div class="filter-search-wrapper">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search by property name or city..." oninput="filterProperties()">
        </div>
        <select class="filter-select" id="statusFilter" onchange="filterProperties()">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="pending">Pending Verification</option>
            <option value="inactive">Inactive</option>
        </select>
        <select class="filter-select" id="typeFilter" onchange="filterProperties()">
            <option value="all">All Types</option>
            <?php foreach ($propertyTypes as $key => $label): ?>
                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <span class="filter-count" id="filterCount"><?php echo $totalProperties; ?> properties</span>
    </div>

    <!-- Message -->
    <?php if ($message): ?>
        <div class="alert-toast alert-success" id="alertToast">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $message; ?>
            <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; cursor: pointer; color: inherit; font-size: 1.25rem;">&times;</button>
        </div>
        <script>
            setTimeout(function() {
                var t = document.getElementById('alertToast');
                if (t) t.remove();
            }, 5000);
        </script>
    <?php endif; ?>

    <!-- Properties Grid -->
    <div class="properties-grid" id="propertiesGrid">
        <?php if (empty($properties)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-building-add"></i>
                </div>
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 8px;">No properties yet</h3>
                <p style="color: #6b6b6b; max-width: 400px; margin: 0 auto 24px;">Add your first property to start receiving bookings and managing your accommodation business.</p>
                <button class="btn-primary" onclick="openPropertyModal()">
                    <i class="bi bi-plus-lg"></i> Add Your First Property
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($properties as $property):
                $propertyAmenities = json_decode($property['amenities'] ?? '[]', true) ?: [];
                $statusClass = $property['is_verified'] ? 'status-verified' : ($property['is_active'] ? 'status-pending' : 'status-inactive');
                $statusText = $property['is_verified'] ? 'Verified' : ($property['is_active'] ? 'Pending Review' : 'Inactive');
                $imageUrl = getImageUrl($property['main_image'] ?? '', 'stay');
                $priceFrom = $property['min_price'] ? formatPrice($property['min_price']) : null;

                $propData = [
                    'stay_id' => $property['stay_id'],
                    'stay_name' => $property['stay_name'],
                    'stay_type' => $property['stay_type'],
                    'star_rating' => $property['star_rating'],
                    'address' => $property['address'],
                    'city' => $property['city'],
                    'location_id' => $property['location_id'],
                    'phone' => $property['phone'],
                    'email' => $property['email'],
                    'check_in_time' => $property['check_in_time'],
                    'check_out_time' => $property['check_out_time'],
                    'description' => $property['description'],
                    'amenities' => $property['amenities'],
                    'main_image' => $property['main_image']
                ];
            ?>
                <div class="property-card"
                    data-name="<?php echo strtolower($property['stay_name']); ?>"
                    data-status="<?php echo $property['is_active'] ? ($property['is_verified'] ? 'active' : 'pending') : 'inactive'; ?>"
                    data-type="<?php echo $property['stay_type']; ?>"
                    data-id="<?php echo $property['stay_id']; ?>"
                    data-property='<?php echo htmlspecialchars(json_encode($propData), ENT_QUOTES, 'UTF-8'); ?>'>

                    <div class="property-image" style="background-image: url('<?php echo $imageUrl; ?>');" onclick="window.location.href='rooms.php?property=<?php echo $property['stay_id']; ?>'">
                        <div class="property-image-overlay"></div>
                        <div class="property-image-badges">
                            <span class="property-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            <div class="property-quick-actions">
                                <button class="quick-action-btn edit" onclick="event.stopPropagation(); editProperty(<?php echo $property['stay_id']; ?>)" title="Edit property">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="quick-action-btn toggle" onclick="event.stopPropagation(); toggleProperty(<?php echo $property['stay_id']; ?>, <?php echo $property['is_active']; ?>)" title="<?php echo $property['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                    <i class="bi bi-<?php echo $property['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                                </button>
                                <button class="quick-action-btn delete-btn" onclick="event.stopPropagation(); confirmDelete(<?php echo $property['stay_id']; ?>, '<?php echo addslashes($property['stay_name']); ?>')" title="Delete property">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="property-image-info">
                            <div class="property-image-name"><?php echo sanitize($property['stay_name']); ?></div>
                            <div class="property-image-location">
                                <i class="bi bi-geo-alt-fill"></i>
                                <?php echo sanitize($property['city'] ?? $property['location_name'] ?? 'Rwanda'); ?>
                            </div>
                        </div>
                        <?php if ($property['review_count'] > 0): ?>
                            <div class="property-image-rating">
                                <i class="bi bi-star-fill" style="font-size: 0.625rem;"></i>
                                <?php echo number_format($property['avg_rating'], 1); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="property-details">
                        <div class="property-meta">
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $property['active_rooms']; ?>/<?php echo $property['total_rooms']; ?></div>
                                <div class="meta-label">Rooms</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $property['pending_bookings']; ?></div>
                                <div class="meta-label">Pending</div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-value"><?php echo $property['upcoming_bookings']; ?></div>
                                <div class="meta-label">Upcoming</div>
                            </div>
                            <?php if ($priceFrom): ?>
                                <div class="meta-item">
                                    <div class="meta-value" style="color: #008009;"><?php echo $priceFrom; ?></div>
                                    <div class="meta-label">From/night</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($propertyAmenities)): ?>
                            <div class="property-amenities">
                                <?php foreach (array_slice($propertyAmenities, 0, 4) as $amenity):
                                    $aData = $commonAmenities[$amenity] ?? null;
                                ?>
                                    <span class="amenity-tag">
                                        <?php if ($aData): ?>
                                            <i class="bi <?php echo $aData['icon']; ?>"></i> <?php echo $aData['name']; ?>
                                        <?php else: ?>
                                            <?php echo ucfirst(str_replace('_', ' ', $amenity)); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($propertyAmenities) > 4): ?>
                                    <span class="amenity-tag">+<?php echo count($propertyAmenities) - 4; ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="property-actions-row">
                            <a href="rooms.php?property=<?php echo $property['stay_id']; ?>" class="property-action-btn primary">
                                <i class="bi bi-door-open"></i> Manage Rooms
                            </a>
                            <a href="calendar.php?property=<?php echo $property['stay_id']; ?>" class="property-action-btn">
                                <i class="bi bi-calendar-week"></i> Calendar
                            </a>
                            <a href="bookings.php?property=<?php echo $property['stay_id']; ?>" class="property-action-btn">
                                <i class="bi bi-calendar-check"></i> Bookings
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================ -->
<!-- ADD/EDIT PROPERTY MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="propertyModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Property</h3>
            <button class="modal-close" onclick="closePropertyModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="propertyForm">
            <div class="modal-body">
                <input type="hidden" name="property_id" id="propId" value="0">

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-info-circle"></i> Basic Information</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Property Name <span class="required">*</span></label>
                            <input type="text" name="property_name" id="propName" class="form-input" required placeholder="e.g., Kigali Marriott Hotel">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Property Type <span class="required">*</span></label>
                            <select name="property_type" id="propType" class="form-input" required>
                                <option value="">Select type...</option>
                                <?php foreach ($propertyTypes as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Star Rating</label>
                        <div class="star-rating-input" id="starRating">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star-fill" data-value="<?php echo $i; ?>" onclick="setStarRating(<?php echo $i; ?>)"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="star_rating" id="starRatingValue" value="0">
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-geo-alt"></i> Location</div>
                    <div class="form-group">
                        <label class="form-label">Address <span class="required">*</span></label>
                        <input type="text" name="address" id="propAddress" class="form-input" required placeholder="Street address">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">City <span class="required">*</span></label>
                            <input type="text" name="city" id="propCity" class="form-input" required placeholder="e.g., Kigali">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location Area</label>
                            <select name="location_id" id="propLocation" class="form-input">
                                <option value="">Select area...</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo $loc['location_id']; ?>"><?php echo sanitize($loc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-telephone"></i> Contact & Schedule</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="propPhone" class="form-input" placeholder="+250...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="propEmail" class="form-input" placeholder="info@...">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Check-in Time</label>
                            <input type="time" name="check_in_time" id="propCheckin" class="form-input" value="14:00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Check-out Time</label>
                            <input type="time" name="check_out_time" id="propCheckout" class="form-input" value="11:00">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-file-text"></i> Description</div>
                    <div class="form-group">
                        <textarea name="description" id="propDescription" class="form-input" rows="4" placeholder="Describe your property, its unique features, and what guests can expect..."></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-grid-3x3-gap"></i> Amenities</div>
                    <div class="amenities-selector" id="amenitiesContainer">
                        <?php foreach ($commonAmenities as $key => $amenity): ?>
                            <label class="amenity-option" data-value="<?php echo $key; ?>">
                                <input type="checkbox" name="amenities[]" value="<?php echo $key; ?>">
                                <span class="check-icon"><i class="bi bi-check-lg"></i></span>
                                <i class="bi <?php echo $amenity['icon']; ?>"></i>
                                <?php echo $amenity['name']; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title"><i class="bi bi-image"></i> Main Image</div>
                    <div class="form-group">
                        <input type="file" name="main_image" id="mainImage" accept="image/*" style="display: none;" onchange="previewImage(this)">
                        <div onclick="document.getElementById('mainImage').click()" style="border: 2px dashed #e7e7e7; border-radius: 12px; padding: 32px; text-align: center; cursor: pointer; transition: all 0.2s; background: #f9fafb;" onmouseover="this.style.borderColor='#003b95'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e7e7e7'; this.style.background='#f9fafb'">
                            <i class="bi bi-cloud-upload" style="font-size: 2rem; color: #9ca3af; display: block; margin-bottom: 12px;"></i>
                            <p style="font-size: 0.875rem; font-weight: 500; margin: 0;">Click to upload image</p>
                            <p style="font-size: 0.75rem; color: #9ca3af; margin: 4px 0 0;">JPG, PNG or WebP</p>
                        </div>
                        <div id="imagePreviewContainer" style="margin-top: 12px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closePropertyModal()">Cancel</button>
                <button type="submit" name="save_property" class="btn-primary">Save Property</button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- ============================================ -->
<!-- DELETE CONFIRMATION MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-dialog" style="max-width: 420px;">
        <div class="modal-header">
            <h3>Delete Property</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center;">
            <div class="delete-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <p id="deleteMessage" style="font-size: 1rem; font-weight: 600; margin-bottom: 8px;">Are you sure?</p>
            <p style="font-size: 0.8125rem; color: #6b6b6b;">This will permanently delete the property, all rooms, and associated data. This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="property_id" id="deletePropId" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" name="delete_property" style="background: #e21111; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer;">Delete Property</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="property_id" id="togglePropId">
    <input type="hidden" name="new_status" id="toggleNewStatus">
    <input type="hidden" name="toggle_status" value="1">
</form>

<script>
    // ============================================
    // MODAL FUNCTIONS - FIXED
    // ============================================

    function openPropertyModal() {
        document.getElementById('modalTitle').textContent = 'Add New Property';
        document.getElementById('propId').value = 0;
        document.getElementById('propertyForm').reset();
        document.getElementById('imagePreviewContainer').innerHTML = '';
        document.getElementById('starRatingValue').value = 0;

        // Reset amenities
        document.querySelectorAll('#amenitiesContainer .amenity-option').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        document.querySelectorAll('#amenitiesContainer input[type="checkbox"]').forEach(function(cb) {
            cb.checked = false;
        });

        // Reset stars
        document.querySelectorAll('#starRating i').forEach(function(star) {
            star.classList.remove('active');
        });

        // Reset times
        document.getElementById('propCheckin').value = '14:00';
        document.getElementById('propCheckout').value = '11:00';

        document.getElementById('propertyModal').classList.add('active');
    }

    function closePropertyModal() {
        document.getElementById('propertyModal').classList.remove('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Close modal ONLY when clicking the overlay background (not the dialog)
    document.getElementById('propertyModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closePropertyModal();
        }
    });

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeDeleteModal();
        }
    });

    // Prevent modal close when clicking inside dialog
    document.querySelectorAll('.modal-dialog').forEach(function(dialog) {
        dialog.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePropertyModal();
            closeDeleteModal();
        }
    });

    // ============================================
    // EDIT PROPERTY - OPENS MODAL WITH DATA
    // ============================================
    function editProperty(propertyId) {
        var card = document.querySelector('.property-card[data-id="' + propertyId + '"]');
        if (!card) return;

        var propData = {};
        try {
            propData = JSON.parse(card.getAttribute('data-property') || '{}');
        } catch (e) {
            console.error('Failed to parse property data');
            return;
        }

        if (Object.keys(propData).length === 0) return;

        // Set modal title
        document.getElementById('modalTitle').textContent = 'Edit Property';

        // Fill form fields
        document.getElementById('propId').value = propData.stay_id || 0;
        document.getElementById('propName').value = propData.stay_name || '';
        document.getElementById('propType').value = propData.stay_type || '';
        document.getElementById('propAddress').value = propData.address || '';
        document.getElementById('propCity').value = propData.city || '';
        document.getElementById('propLocation').value = propData.location_id || '';
        document.getElementById('propPhone').value = propData.phone || '';
        document.getElementById('propEmail').value = propData.email || '';
        document.getElementById('propDescription').value = propData.description || '';

        // Check-in/out times
        var checkin = propData.check_in_time || '14:00:00';
        var checkout = propData.check_out_time || '11:00:00';
        document.getElementById('propCheckin').value = checkin.substring(0, 5);
        document.getElementById('propCheckout').value = checkout.substring(0, 5);

        // Star rating
        var stars = parseInt(propData.star_rating) || 0;
        document.getElementById('starRatingValue').value = stars;
        document.querySelectorAll('#starRating i').forEach(function(star, index) {
            star.classList.toggle('active', index < stars);
        });

        // Amenities
        var amenities = [];
        try {
            amenities = typeof propData.amenities === 'string' ? JSON.parse(propData.amenities) : (propData.amenities || []);
        } catch (e) {
            amenities = [];
        }

        document.querySelectorAll('#amenitiesContainer .amenity-option').forEach(function(option) {
            var checkbox = option.querySelector('input[type="checkbox"]');
            var isChecked = amenities.indexOf(checkbox.value) !== -1;
            checkbox.checked = isChecked;
            if (isChecked) {
                option.classList.add('selected');
            } else {
                option.classList.remove('selected');
            }
        });

        // Image preview
        var preview = document.getElementById('imagePreviewContainer');
        preview.innerHTML = '';
        if (propData.main_image) {
            preview.innerHTML = '<div style="position: relative; display: inline-block;">' +
                '<img src="/gorwanda-plus/assets/images/stays/' + propData.main_image + '" style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid #e7e7e7;">' +
                '<span style="position: absolute; top: -8px; left: -8px; background: #003b95; color: white; font-size: 0.625rem; padding: 2px 8px; border-radius: 100px;">Current</span>' +
                '</div>' +
                '<p style="font-size: 0.75rem; color: #6b6b6b; margin-top: 8px;">Upload a new image to replace</p>';
        }

        // Open modal
        document.getElementById('propertyModal').classList.add('active');
    }

    // ============================================
    // OTHER FUNCTIONS
    // ============================================
    function toggleProperty(propertyId, currentStatus) {
        var action = currentStatus ? 'deactivate' : 'activate';
        if (confirm('Are you sure you want to ' + action + ' this property?')) {
            document.getElementById('togglePropId').value = propertyId;
            document.getElementById('toggleNewStatus').value = currentStatus ? 0 : 1;
            document.getElementById('toggleForm').submit();
        }
    }

    function confirmDelete(propertyId, propertyName) {
        document.getElementById('deleteMessage').textContent = 'Delete "' + propertyName + '"?';
        document.getElementById('deletePropId').value = propertyId;
        document.getElementById('deleteModal').classList.add('active');
    }

    function previewImage(input) {
        var container = document.getElementById('imagePreviewContainer');
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                container.innerHTML = '<div style="position: relative; display: inline-block;">' +
                    '<img src="' + e.target.result + '" style="max-width: 200px; max-height: 150px; border-radius: 8px; border: 1px solid #008009;">' +
                    '<span style="position: absolute; top: -8px; left: -8px; background: #008009; color: white; font-size: 0.625rem; padding: 2px 8px; border-radius: 100px;">New</span>' +
                    '</div>';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function setStarRating(value) {
        document.getElementById('starRatingValue').value = value;
        document.querySelectorAll('#starRating i').forEach(function(star, index) {
            if (index < value) {
                star.classList.add('active');
            } else {
                star.classList.remove('active');
            }
        });
    }

    // Amenity click handler
    document.querySelectorAll('#amenitiesContainer .amenity-option').forEach(function(option) {
        option.addEventListener('click', function(e) {
            e.preventDefault();
            var checkbox = this.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            if (checkbox.checked) {
                this.classList.add('selected');
            } else {
                this.classList.remove('selected');
            }
        });
    });

    // Filter properties
    function filterProperties() {
        var searchTerm = document.getElementById('searchInput').value.toLowerCase();
        var statusFilter = document.getElementById('statusFilter').value;
        var typeFilter = document.getElementById('typeFilter').value;
        var cards = document.querySelectorAll('.property-card');
        var visible = 0;

        cards.forEach(function(card) {
            var name = card.getAttribute('data-name') || '';
            var status = card.getAttribute('data-status') || '';
            var type = card.getAttribute('data-type') || '';

            var matchesSearch = name.indexOf(searchTerm) !== -1;
            var matchesStatus = statusFilter === 'all' || status === statusFilter;
            var matchesType = typeFilter === 'all' || type === typeFilter;

            if (matchesSearch && matchesStatus && matchesType) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });

        document.getElementById('filterCount').textContent = visible + ' propert' + (visible === 1 ? 'y' : 'ies');
    }
</script>

<?php require_once 'includes/stays_footer.php'; ?>