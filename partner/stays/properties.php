<?php
$pageTitle = 'Manage Properties';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Handle form submissions via AJAX or POST
$message = '';
$error = '';

// ============================================
// HANDLE PROPERTY ACTIONS
// ============================================

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
    
// Handle image upload
$mainImage = '';
if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = dirname(__DIR__, 3) . '/assets/images/stays/';
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileExt = strtolower(pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($fileExt, $allowed)) {
        $fileName = time() . '_' . uniqid() . '.' . $fileExt;
        $uploadPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $uploadPath)) {
            $mainImage = $fileName;
        } else {
            error_log("Failed to move uploaded file to: " . $uploadPath);
        }
    } else {
        error_log("Invalid file type: " . $fileExt);
    }
}
    
    if ($propertyId > 0) {
        // Update existing
        if ($mainImage) {
            $stmt = $db->prepare("UPDATE stays SET stay_name=?, stay_type=?, description=?, star_rating=?, address=?, city=?, phone=?, email=?, check_in_time=?, check_out_time=?, amenities=?, main_image=? WHERE stay_id=? AND owner_id=?");
            $stmt->execute([$propertyName, $propertyType, $description, $starRating, $address, $city, $phone, $email, $checkInTime, $checkOutTime, $amenities, $mainImage, $propertyId, $userId]);
        } else {
            $stmt = $db->prepare("UPDATE stays SET stay_name=?, stay_type=?, description=?, star_rating=?, address=?, city=?, phone=?, email=?, check_in_time=?, check_out_time=?, amenities=? WHERE stay_id=? AND owner_id=?");
            $stmt->execute([$propertyName, $propertyType, $description, $starRating, $address, $city, $phone, $email, $checkInTime, $checkOutTime, $amenities, $propertyId, $userId]);
        }
        $message = "Property updated successfully!";
    } else {
        // Insert new
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($propertyName));
        $slug = trim($slug, '-') . '-' . time();
        
        $stmt = $db->prepare("INSERT INTO stays (owner_id, stay_name, slug, stay_type, description, star_rating, address, city, phone, email, check_in_time, check_out_time, amenities, main_image, is_active, is_verified, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())");
        $stmt->execute([$userId, $propertyName, $slug, $propertyType, $description, $starRating, $address, $city, $phone, $email, $checkInTime, $checkOutTime, $amenities, $mainImage]);
        $message = "Property added successfully! It will be verified soon.";
    }
}

// Delete Property
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_property'])) {
    $propertyId = intval($_POST['property_id']);
    
    // First delete rooms
    $stmt = $db->prepare("DELETE FROM stay_rooms WHERE stay_id = ?");
    $stmt->execute([$propertyId]);
    
    // Then delete property
    $stmt = $db->prepare("DELETE FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$propertyId, $userId]);
    
    $message = "Property deleted successfully!";
}

// Toggle Property Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $propertyId = intval($_POST['property_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("UPDATE stays SET is_active = ? WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$newStatus, $propertyId, $userId]);
    
    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    exit;
}

// ============================================
// GET ALL PROPERTIES
// ============================================

$stmt = $db->prepare("
    SELECT s.*, 
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as total_rooms,
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as active_rooms,
           (SELECT COUNT(*) FROM bookings b 
            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
            WHERE sr.stay_id = s.stay_id AND b.status = 'pending') as pending_bookings,
           (SELECT COALESCE(AVG(r.overall_rating), 0) FROM reviews r WHERE r.stay_id = s.stay_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.stay_id = s.stay_id) as review_count
    FROM stays s
    WHERE s.owner_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Get locations for dropdown
$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();

// Common amenities list
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
    'business_center' => 'Business Center'
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
?>

<style>
/* Additional styles for properties management */
.properties-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.properties-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.properties-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Filter Bar */
.filter-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    background: var(--booking-white);
    padding: 16px 20px;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    flex-wrap: wrap;
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
    color: var(--booking-text-light);
    font-size: 0.875rem;
}

.filter-search input {
    width: 100%;
    padding: 10px 16px 10px 38px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.filter-select {
    min-width: 150px;
    padding: 10px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: var(--booking-white);
}

/* Property Grid */
.properties-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
}

.property-card {
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.property-card:hover {
    box-shadow: var(--shadow-md);
}

.property-image {
    height: 180px;
    position: relative;
    background-size: cover;
    background-position: center;
}

.property-status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
    z-index: 2;
}

.property-status-badge.verified {
    background: var(--booking-success);
    color: white;
}

.property-status-badge.pending {
    background: var(--booking-warning);
    color: white;
}

.property-status-badge.inactive {
    background: var(--booking-text-light);
    color: white;
}

.property-actions {
    position: absolute;
    top: 12px;
    left: 12px;
    display: flex;
    gap: 6px;
    z-index: 2;
    opacity: 0;
    transition: opacity 0.2s;
}

.property-card:hover .property-actions {
    opacity: 1;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: white;
    border: 1px solid var(--booking-border);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
}

.action-btn:hover {
    background: var(--booking-blue);
    color: white;
    border-color: var(--booking-blue);
}

.action-btn.delete:hover {
    background: var(--booking-danger);
    border-color: var(--booking-danger);
}

.action-btn.toggle:hover {
    background: var(--booking-warning);
    border-color: var(--booking-warning);
    color: white;
}

.property-content {
    padding: 16px;
}

.property-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.property-type {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.property-location {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.property-stats {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    padding: 12px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.stat-item {
    flex: 1;
    text-align: center;
}

.stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-blue);
    margin-bottom: 2px;
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.property-footer {
    display: flex;
    gap: 8px;
}

.footer-btn {
    flex: 1;
    padding: 8px;
    background: var(--booking-gray);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text);
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.footer-btn:hover {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    border-color: var(--booking-blue);
}

.footer-btn.primary {
    background: var(--booking-blue);
    color: white;
    border-color: var(--booking-blue);
}

.footer-btn.primary:hover {
    background: var(--booking-dark-blue);
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
    padding: 20px 24px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--booking-gray);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--booking-danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--booking-gray);
    position: sticky;
    bottom: 0;
}

/* Form Styles */
.form-group {
    margin-bottom: 20px;
}

.form-group.half {
    width: 48%;
    display: inline-block;
    margin-right: 2%;
}

.form-group.half:last-child {
    margin-right: 0;
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
    padding: 10px 14px;
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

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Amenities Grid */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    max-height: 250px;
    overflow-y: auto;
}

.amenity-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    cursor: pointer;
    padding: 6px 8px;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
}

.amenity-checkbox:hover {
    background: white;
}

.amenity-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--booking-blue);
}

/* Image Upload */
.image-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: var(--radius-sm);
    padding: 30px;
    text-align: center;
    background: var(--booking-gray);
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 16px;
}

.image-upload-area:hover {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
}

.image-upload-area i {
    font-size: 2rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
}

.image-upload-area p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

.image-preview {
    max-width: 200px;
    max-height: 150px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--booking-border);
    margin-top: 12px;
}

/* Alert Messages */
.alert {
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid #a7f3d0;
}

.alert-danger {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid #fecaca;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--booking-text-light);
    margin-bottom: 20px;
}

/* Responsive */
@media (max-width: 768px) {
    .filter-bar {
        flex-direction: column;
    }
    .filter-search, .filter-select {
        width: 100%;
    }
    .properties-grid {
        grid-template-columns: 1fr;
    }
    .form-group.half {
        width: 100%;
        margin-right: 0;
    }
}
</style>

<div class="properties-header">
    <div class="properties-title">
        <h1>Manage Properties</h1>
        <p>Add, edit, and manage your properties</p>
    </div>
<button class="btn-primary" onclick="window.location.href='property-add.php'">
    <i class="bi bi-plus-lg"></i> Add New Property
</button>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-search">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search properties..." onkeyup="filterProperties()">
    </div>
    <select class="filter-select" id="statusFilter" onchange="filterProperties()">
        <option value="all">All Status</option>
        <option value="active">Active Only</option>
        <option value="inactive">Inactive Only</option>
        <option value="pending">Pending Verification</option>
    </select>
    <select class="filter-select" id="typeFilter" onchange="filterProperties()">
        <option value="all">All Types</option>
        <?php foreach ($propertyTypes as $key => $label): ?>
        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Message Display -->
<?php if ($message): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $message; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Properties Grid -->
<?php if (empty($properties)): ?>
<div class="empty-state">
    <i class="bi bi-building"></i>
    <h3>No properties yet</h3>
    <p>Get started by adding your first property</p>
    <button class="btn-primary" onclick="openModal('addPropertyModal')">
        <i class="bi bi-plus-lg"></i> Add Property
    </button>
</div>
<?php else: ?>
<div class="properties-grid" id="propertiesGrid">
    <?php foreach ($properties as $property): 
        $status = $property['is_verified'] ? 'verified' : ($property['is_active'] ? 'pending' : 'inactive');
        $statusText = $property['is_verified'] ? 'Verified' : ($property['is_active'] ? 'Pending' : 'Inactive');
    ?>
    <div class="property-card" 
         data-name="<?php echo strtolower($property['stay_name']); ?>"
         data-status="<?php echo $property['is_active'] ? ($property['is_verified'] ? 'active' : 'pending') : 'inactive'; ?>"
         data-type="<?php echo $property['stay_type']; ?>">
        
        <div class="property-image" style="background-image: url('<?php echo getImageUrl($property['main_image'] ?? '', 'stay'); ?>');">
            <div class="property-status-badge <?php echo $status; ?>">
                <?php echo $statusText; ?>
            </div>
            <div class="property-actions">
                <button class="action-btn" onclick="editProperty(<?php echo htmlspecialchars(json_encode($property)); ?>)" title="Edit">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="action-btn toggle" onclick="togglePropertyStatus(<?php echo $property['stay_id']; ?>, <?php echo $property['is_active']; ?>)" title="<?php echo $property['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                    <i class="bi bi-<?php echo $property['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                </button>
                <button class="action-btn delete" onclick="deleteProperty(<?php echo $property['stay_id']; ?>, '<?php echo sanitize($property['stay_name']); ?>')" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        
        <div class="property-content">
            <h3 class="property-name"><?php echo sanitize($property['stay_name']); ?></h3>
            <div class="property-type">
                <i class="bi bi-building"></i>
                <?php echo $propertyTypes[$property['stay_type']] ?? ucfirst($property['stay_type']); ?>
            </div>
            <div class="property-location">
                <i class="bi bi-geo-alt"></i>
                <?php echo sanitize($property['city'] ?? 'Rwanda'); ?>
            </div>
            
            <div class="property-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $property['active_rooms']; ?>/<?php echo $property['total_rooms']; ?></div>
                    <div class="stat-label">Rooms</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $property['pending_bookings']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $property['review_count'] > 0 ? number_format($property['avg_rating'], 1) : '0'; ?></div>
                    <div class="stat-label">Rating</div>
                </div>
            </div>
            
            <div class="property-footer">
                <a href="rooms.php?property=<?php echo $property['stay_id']; ?>" class="footer-btn">
                    <i class="bi bi-door-open"></i> Rooms
                </a>
                <a href="calendar.php?property=<?php echo $property['stay_id']; ?>" class="footer-btn">
                    <i class="bi bi-calendar-week"></i> Calendar
                </a>
                <a href="bookings.php?property=<?php echo $property['stay_id']; ?>" class="footer-btn primary">
                    <i class="bi bi-calendar-check"></i> Bookings
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

<!-- Add/Edit Property Modal -->
<div class="modal" id="propertyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="propertyModalTitle">Add New Property</h3>
            <button class="modal-close" onclick="closeModal('propertyModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="propertyForm">
            <div class="modal-body">
                <input type="hidden" name="property_id" id="property_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Property Name <span class="required">*</span></label>
                    <input type="text" name="property_name" id="property_name" class="form-control" required>
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Property Type <span class="required">*</span></label>
                    <select name="property_type" id="property_type" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($propertyTypes as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Star Rating</label>
                    <select name="star_rating" id="star_rating" class="form-control">
                        <option value="0">Not Rated</option>
                        <option value="1">1 Star</option>
                        <option value="2">2 Stars</option>
                        <option value="3">3 Stars</option>
                        <option value="4">4 Stars</option>
                        <option value="5">5 Stars</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address <span class="required">*</span></label>
                    <input type="text" name="address" id="address" class="form-control" required>
                </div>
                
                <div class="form-group half">
                    <label class="form-label">City <span class="required">*</span></label>
                    <input type="text" name="city" id="city" class="form-control" required>
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Location</label>
                    <select name="location_id" id="location_id" class="form-control">
                        <option value="">Select Location</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['location_id']; ?>"><?php echo sanitize($loc['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control">
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Check-in Time</label>
                    <input type="time" name="check_in_time" id="check_in_time" class="form-control" value="14:00">
                </div>
                
                <div class="form-group half">
                    <label class="form-label">Check-out Time</label>
                    <input type="time" name="check_out_time" id="check_out_time" class="form-control" value="11:00">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" rows="4" class="form-control"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amenities</label>
                    <div class="amenities-grid" id="amenitiesContainer">
                        <?php foreach ($commonAmenities as $key => $label): ?>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="amenities[]" value="<?php echo $key; ?>">
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Main Image</label>
                    <div class="image-upload-area" onclick="document.getElementById('main_image').click()">
                        <i class="bi bi-cloud-upload"></i>
                        <p>Click to upload or drag and drop</p>
                        <input type="file" name="main_image" id="main_image" accept="image/*" style="display: none;" onchange="previewImage(this)">
                    </div>
                    <div id="imagePreview"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('propertyModal')">Cancel</button>
                <button type="submit" name="save_property" class="btn-primary">Save Property</button>
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
            <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--booking-danger); margin-bottom: 16px;"></i>
            <p id="deleteMessage" style="font-size: 1rem; margin-bottom: 8px;">Are you sure you want to delete this property?</p>
            <p style="font-size: 0.8125rem; color: var(--booking-text-light);">This action cannot be undone. All rooms and bookings will be deleted.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="property_id" id="delete_property_id" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_property" class="btn-primary" style="background: var(--booking-danger);">Delete Property</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="property_id" id="toggle_property_id">
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
    
    // Reset form if it's the property modal
    if (modalId === 'propertyModal') {
        document.getElementById('propertyForm').reset();
        document.getElementById('property_id').value = 0;
        document.getElementById('imagePreview').innerHTML = '';
        document.getElementById('propertyModalTitle').textContent = 'Add New Property';
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// PROPERTY FUNCTIONS
// ============================================
function editProperty(property) {
    document.getElementById('propertyModalTitle').textContent = 'Edit Property';
    document.getElementById('property_id').value = property.stay_id;
    document.getElementById('property_name').value = property.stay_name;
    document.getElementById('property_type').value = property.stay_type;
    document.getElementById('star_rating').value = property.star_rating;
    document.getElementById('address').value = property.address;
    document.getElementById('city').value = property.city || '';
    document.getElementById('location_id').value = property.location_id || '';
    document.getElementById('phone').value = property.phone || '';
    document.getElementById('email').value = property.email || '';
    document.getElementById('check_in_time').value = property.check_in_time ? property.check_in_time.substring(0,5) : '14:00';
    document.getElementById('check_out_time').value = property.check_out_time ? property.check_out_time.substring(0,5) : '11:00';
    document.getElementById('description').value = property.description || '';
    
    // Check amenities
    let amenities = [];
    try {
        amenities = JSON.parse(property.amenities || '[]');
    } catch(e) {
        amenities = [];
    }
    
    document.querySelectorAll('#amenitiesContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = amenities.includes(cb.value);
    });
    
    // Show image preview if exists
    document.getElementById('imagePreview').innerHTML = '';
    if (property.main_image) {
        document.getElementById('imagePreview').innerHTML = '<img src="/gorwanda-plus/assets/images/stays/' + property.main_image + '" class="image-preview">';
    }
    
    openModal('propertyModal');
}

function deleteProperty(id, name) {
    document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>"' + name + '"</strong>?';
    document.getElementById('delete_property_id').value = id;
    openModal('deleteModal');
}

function togglePropertyStatus(id, currentStatus) {
    if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this property?')) {
        document.getElementById('toggle_property_id').value = id;
        document.getElementById('toggle_current_status').value = currentStatus;
        document.getElementById('toggleForm').submit();
    }
}

// ============================================
// IMAGE PREVIEW
// ============================================
function previewImage(input) {
    if (input.files && input.files[0]) {
        let reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').innerHTML = '<img src="' + e.target.result + '" class="image-preview">';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// ============================================
// FILTER PROPERTIES
// ============================================
function filterProperties() {
    let searchTerm = document.getElementById('searchInput').value.toLowerCase();
    let statusFilter = document.getElementById('statusFilter').value;
    let typeFilter = document.getElementById('typeFilter').value;
    let properties = document.querySelectorAll('.property-card');
    let visibleCount = 0;
    
    properties.forEach(property => {
        let name = property.getAttribute('data-name') || '';
        let status = property.getAttribute('data-status') || '';
        let type = property.getAttribute('data-type') || '';
        
        let matchesSearch = name.includes(searchTerm);
        let matchesStatus = statusFilter === 'all' || status === statusFilter;
        let matchesType = typeFilter === 'all' || type === typeFilter;
        
        if (matchesSearch && matchesStatus && matchesType) {
            property.style.display = 'block';
            visibleCount++;
        } else {
            property.style.display = 'none';
        }
    });
    
    // Show/hide empty message
    let emptyMessage = document.getElementById('emptyResults');
    if (visibleCount === 0 && properties.length > 0) {
        if (!emptyMessage) {
            emptyMessage = document.createElement('div');
            emptyMessage.id = 'emptyResults';
            emptyMessage.className = 'empty-state';
            emptyMessage.innerHTML = `
                <i class="bi bi-search"></i>
                <h3>No matching properties</h3>
                <p>Try adjusting your filters</p>
                <button class="btn-secondary" onclick="resetFilters()">Clear Filters</button>
            `;
            document.getElementById('propertiesGrid').appendChild(emptyMessage);
        }
    } else if (emptyMessage) {
        emptyMessage.remove();
    }
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('typeFilter').value = 'all';
    filterProperties();
}

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Add search input event listener
    document.getElementById('searchInput').addEventListener('keyup', filterProperties);
});
function openAddPropertyModal() {
    // Reset form
    document.getElementById('propertyForm').reset();
    document.getElementById('property_id').value = 0;
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('propertyModalTitle').textContent = 'Add New Property';
    
    // Clear all checkboxes
    document.querySelectorAll('#amenitiesContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    
    openModal('propertyModal');
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>