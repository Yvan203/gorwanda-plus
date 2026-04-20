<?php
$pageTitle = 'Amenities Management';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// HANDLE AMENITY ACTIONS
// ============================================

// Add custom amenity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_amenity'])) {
    $amenityKey = sanitize($_POST['amenity_key']);
    $amenityName = sanitize($_POST['amenity_name']);
    $amenityIcon = sanitize($_POST['amenity_icon'] ?? 'bi-check-circle');
    $category = sanitize($_POST['category']);
    $description = sanitize($_POST['description'] ?? '');
    
    // Validate
    $errors = [];
    if (empty($amenityKey)) $errors[] = 'Amenity key is required';
    if (empty($amenityName)) $errors[] = 'Amenity name is required';
    if (empty($category)) $errors[] = 'Category is required';
    
    // Check if key already exists
    $stmt = $db->prepare("SELECT amenity_id FROM amenities WHERE amenity_key = ?");
    $stmt->execute([$amenityKey]);
    if ($stmt->fetch()) {
        $errors[] = 'Amenity key already exists';
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO amenities (amenity_key, amenity_name, amenity_icon, category, description, is_custom, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
        ");
        $stmt->execute([$amenityKey, $amenityName, $amenityIcon, $category, $description, $userId]);
        $success = "Amenity added successfully!";
    } else {
        $error = implode('<br>', $errors);
    }
}

// Edit amenity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_amenity'])) {
    $amenityId = intval($_POST['amenity_id']);
    $amenityName = sanitize($_POST['amenity_name']);
    $amenityIcon = sanitize($_POST['amenity_icon'] ?? 'bi-check-circle');
    $description = sanitize($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $db->prepare("
        UPDATE amenities 
        SET amenity_name = ?, amenity_icon = ?, description = ?, is_active = ? 
        WHERE amenity_id = ?
    ");
    $stmt->execute([$amenityName, $amenityIcon, $description, $isActive, $amenityId]);
    $success = "Amenity updated successfully!";
}

// Delete custom amenity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_amenity'])) {
    $amenityId = intval($_POST['amenity_id']);
    
    // Check if amenity is being used
    $key = $_POST['amenity_key'];
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM stays WHERE JSON_CONTAINS(amenities, ?)
    ");
    $stmt->execute(['"' . $key . '"']);
    $propertyUsage = $stmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM stay_rooms WHERE JSON_CONTAINS(room_amenities, ?)
    ");
    $stmt->execute(['"' . $key . '"']);
    $roomUsage = $stmt->fetchColumn();
    
    $totalUsage = $propertyUsage + $roomUsage;
    
    if ($totalUsage > 0) {
        $error = "This amenity is being used by {$totalUsage} items and cannot be deleted. Consider deactivating it instead.";
    } else {
        $stmt = $db->prepare("DELETE FROM amenities WHERE amenity_id = ?");
        $stmt->execute([$amenityId]);
        $success = "Amenity deleted successfully!";
    }
}

// Bulk enable/disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $category = $_POST['bulk_category'] ?? '';
    
    if ($category) {
        if ($action === 'enable_all') {
            $stmt = $db->prepare("UPDATE amenities SET is_active = 1 WHERE category = ?");
            $stmt->execute([$category]);
            $success = "All amenities in category enabled successfully!";
        } elseif ($action === 'disable_all') {
            $stmt = $db->prepare("UPDATE amenities SET is_active = 0 WHERE category = ?");
            $stmt->execute([$category]);
            $success = "All amenities in category disabled successfully!";
        }
    }
}

// ============================================
// GET AMENITIES DATA
// ============================================

// Get all amenities
$stmt = $db->query("
    SELECT a.*, 
           (SELECT COUNT(*) FROM stays WHERE JSON_CONTAINS(amenities, CONCAT('\"', a.amenity_key, '\"'))) as property_usage,
           (SELECT COUNT(*) FROM stay_rooms WHERE JSON_CONTAINS(room_amenities, CONCAT('\"', a.amenity_key, '\"'))) as room_usage
    FROM amenities a
    ORDER BY a.category, a.amenity_name
");
$allAmenities = $stmt->fetchAll();

// Group by category
$amenitiesByCategory = [
    'property' => [],
    'room' => [],
    'car' => [],
    'experience' => []
];

foreach ($allAmenities as $amenity) {
    $amenitiesByCategory[$amenity['category']][] = $amenity;
}

// Get usage statistics
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM stays WHERE owner_id = $userId) as total_properties,
        (SELECT COUNT(*) FROM stay_rooms sr JOIN stays s ON sr.stay_id = s.stay_id WHERE s.owner_id = $userId) as total_rooms,
        (SELECT COUNT(*) FROM amenities) as total_amenities,
        (SELECT COUNT(*) FROM amenities WHERE is_custom = 1) as custom_amenities,
        (SELECT COUNT(*) FROM amenities WHERE is_active = 1) as active_amenities
");
$stats = $stmt->fetch();

// Get popular amenities (most used)
$stmt = $db->query("
    SELECT a.amenity_key, a.amenity_name, a.amenity_icon, a.category,
           (SELECT COUNT(*) FROM stays WHERE JSON_CONTAINS(amenities, CONCAT('\"', a.amenity_key, '\"'))) +
           (SELECT COUNT(*) FROM stay_rooms WHERE JSON_CONTAINS(room_amenities, CONCAT('\"', a.amenity_key, '\"'))) as total_usage
    FROM amenities a
    ORDER BY total_usage DESC
    LIMIT 10
");
$popularAmenities = $stmt->fetchAll();

// Available icons
$availableIcons = [
    'bi-wifi', 'bi-water', 'bi-p-circle', 'bi-shop', 'bi-droplet', 'bi-bicycle',
    'bi-cup-straw', 'bi-bell', 'bi-snow', 'bi-egg-fried', 'bi-bus-front', 'bi-basket',
    'bi-heart', 'bi-people', 'bi-ban', 'bi-person-badge', 'bi-briefcase', 'bi-people-fill',
    'bi-clock', 'bi-bag', 'bi-currency-exchange', 'bi-credit-card', 'bi-bag-heart',
    'bi-tv', 'bi-door-open', 'bi-shield-lock', 'bi-laptop', 'bi-wind', 'bi-grid',
    'bi-tree', 'bi-flower1', 'bi-cup', 'bi-thermometer', 'bi-fan', 'bi-droplet-half',
    'bi-compass', 'bi-bluetooth', 'bi-truck', 'bi-box-seam', 'bi-person-wheelchair',
    'bi-gear', 'bi-fuel-pump', 'bi-camera', 'bi-ticket', 'bi-shield-check'
];

$categoryLabels = [
    'property' => 'Property Amenities',
    'room' => 'Room Amenities',
    'car' => 'Car Features',
    'experience' => 'Experience Inclusions'
];
?>

<style>
/* Amenities Management Specific Styles */
.amenities-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.amenities-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.amenities-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Grid */
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
    border: 1px solid var(--booking-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-blue);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--booking-border);
}

/* Category Tabs */
.category-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    border-bottom: 1px solid var(--booking-border);
    padding-bottom: 0;
}

.category-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.category-tab:hover {
    color: var(--booking-blue);
}

.category-tab.active {
    color: var(--booking-blue);
}

.category-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--booking-blue);
}

.category-tab .count {
    background: var(--booking-gray);
    color: var(--booking-text);
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    margin-left: 8px;
}

.category-tab.active .count {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
}

/* Amenities Grid */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.amenity-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 20px;
    transition: all 0.2s;
    position: relative;
}

.amenity-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--booking-blue);
}

.amenity-card.inactive {
    opacity: 0.6;
    background: var(--booking-gray);
}

.amenity-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.amenity-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.amenity-badge {
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.amenity-badge.custom {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
}

.amenity-badge.system {
    background: var(--booking-gray);
    color: var(--booking-text-light);
}

.amenity-badge.active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.amenity-badge.inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

.amenity-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.amenity-key {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    font-family: monospace;
    margin-bottom: 8px;
}

.amenity-description {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
    line-height: 1.5;
}

.amenity-usage {
    display: flex;
    gap: 16px;
    margin-bottom: 16px;
    padding: 8px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.usage-item {
    flex: 1;
    text-align: center;
}

.usage-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-blue);
    margin-bottom: 2px;
}

.usage-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.amenity-actions {
    display: flex;
    gap: 8px;
}

.amenity-action-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.amenity-action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.amenity-action-btn.delete:hover {
    background: #fce8e8;
    border-color: var(--booking-danger);
    color: var(--booking-danger);
}

/* Popular Amenities */
.popular-section {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 30px;
}

.popular-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.popular-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 12px;
}

.popular-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    border: 1px solid var(--booking-border);
}

.popular-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: white;
    color: var(--booking-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.popular-info {
    flex: 1;
}

.popular-name {
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 2px;
}

.popular-usage {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

/* Bulk Actions Bar */
.bulk-actions-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.bulk-select {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.bulk-select select {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    min-width: 200px;
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
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: var(--booking-gray);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 1.125rem;
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

/* Icon Selector */
.icon-selector {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
    padding: 16px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--booking-border);
}

.icon-option {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--booking-border);
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 1.125rem;
}

.icon-option:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.icon-option.selected {
    background: var(--booking-blue);
    color: white;
    border-color: var(--booking-blue);
}

/* Checkbox Group */
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--booking-blue);
}

/* Alerts */
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

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .amenities-grid,
    .form-grid,
    .popular-grid,
    .icon-selector {
        grid-template-columns: 1fr;
    }
    
    .category-tabs {
        flex-direction: column;
    }
    
    .category-tab {
        width: 100%;
        text-align: left;
    }
    
    .bulk-actions-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .bulk-select {
        flex-direction: column;
        align-items: stretch;
    }
    
    .bulk-select select {
        width: 100%;
    }
}
</style>

<div class="amenities-header">
    <div class="amenities-title">
        <h1>Amenities Management</h1>
        <p>Manage property and room amenities across your portfolio</p>
    </div>
    <button class="btn-primary" onclick="openModal('addAmenityModal')">
        <i class="bi bi-plus-lg"></i> Add Custom Amenity
    </button>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_amenities'] ?? 0; ?></div>
        <div class="stat-label">Total Amenities</div>
        <div class="stat-footer"><?php echo $stats['custom_amenities'] ?? 0; ?> custom</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['active_amenities'] ?? 0; ?></div>
        <div class="stat-label">Active</div>
        <div class="stat-footer"><?php echo ($stats['total_amenities'] ?? 0) - ($stats['active_amenities'] ?? 0); ?> inactive</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo count($amenitiesByCategory['property']); ?></div>
        <div class="stat-label">Property Amenities</div>
        <div class="stat-footer"><?php echo count($amenitiesByCategory['room']); ?> room amenities</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_properties'] ?? 0; ?></div>
        <div class="stat-label">Your Properties</div>
        <div class="stat-footer"><?php echo $stats['total_rooms'] ?? 0; ?> rooms</div>
    </div>
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

<!-- Popular Amenities -->
<?php if (!empty($popularAmenities)): ?>
<div class="popular-section">
    <div class="popular-title">
        <i class="bi bi-star-fill text-warning"></i>
        Most Popular Amenities
    </div>
    <div class="popular-grid">
        <?php foreach ($popularAmenities as $popular): ?>
        <div class="popular-item">
            <div class="popular-icon">
                <i class="bi <?php echo $popular['amenity_icon']; ?>"></i>
            </div>
            <div class="popular-info">
                <div class="popular-name"><?php echo $popular['amenity_name']; ?></div>
                <div class="popular-usage">Used in <?php echo $popular['total_usage']; ?> places</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Actions Bar -->
<div class="bulk-actions-bar">
    <div class="bulk-select">
        <span style="font-size: 0.875rem; font-weight: 600;">Bulk Actions:</span>
        <select id="bulkCategory" class="form-control">
            <option value="">Select Category</option>
            <option value="property">Property Amenities</option>
            <option value="room">Room Amenities</option>
            <option value="car">Car Features</option>
            <option value="experience">Experience Inclusions</option>
        </select>
        <button class="btn-secondary" onclick="bulkAction('enable_all')">
            <i class="bi bi-check-circle"></i> Enable All
        </button>
        <button class="btn-secondary" onclick="bulkAction('disable_all')">
            <i class="bi bi-pause-circle"></i> Disable All
        </button>
    </div>
    
    <div>
        <button class="btn-outline" onclick="exportAmenities()">
            <i class="bi bi-download"></i> Export List
        </button>
    </div>
</div>

<!-- Category Tabs -->
<div class="category-tabs">
    <button class="category-tab active" onclick="switchCategory('all', this)">
        All Amenities <span class="count"><?php echo $stats['total_amenities'] ?? 0; ?></span>
    </button>
    <button class="category-tab" onclick="switchCategory('property', this)">
        Property <span class="count"><?php echo count($amenitiesByCategory['property']); ?></span>
    </button>
    <button class="category-tab" onclick="switchCategory('room', this)">
        Room <span class="count"><?php echo count($amenitiesByCategory['room']); ?></span>
    </button>
    <button class="category-tab" onclick="switchCategory('car', this)">
        Car <span class="count"><?php echo count($amenitiesByCategory['car']); ?></span>
    </button>
    <button class="category-tab" onclick="switchCategory('experience', this)">
        Experience <span class="count"><?php echo count($amenitiesByCategory['experience']); ?></span>
    </button>
</div>

<!-- Amenities Grid - All -->
<div id="category-all" class="amenities-grid">
    <?php foreach ($allAmenities as $amenity): ?>
    <div class="amenity-card <?php echo $amenity['is_active'] ? '' : 'inactive'; ?>" data-category="<?php echo $amenity['category']; ?>">
        <div class="amenity-header">
            <div class="amenity-icon">
                <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
            </div>
            <div>
                <span class="amenity-badge <?php echo $amenity['is_custom'] ? 'custom' : 'system'; ?>">
                    <?php echo $amenity['is_custom'] ? 'Custom' : 'System'; ?>
                </span>
                <span class="amenity-badge <?php echo $amenity['is_active'] ? 'active' : 'inactive'; ?>" style="margin-left: 4px;">
                    <?php echo $amenity['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
        </div>
        
        <div class="amenity-name"><?php echo $amenity['amenity_name']; ?></div>
        <div class="amenity-key"><?php echo $amenity['amenity_key']; ?></div>
        
        <?php if (!empty($amenity['description'])): ?>
        <div class="amenity-description"><?php echo $amenity['description']; ?></div>
        <?php endif; ?>
        
        <div class="amenity-usage">
            <div class="usage-item">
                <div class="usage-value"><?php echo $amenity['property_usage']; ?></div>
                <div class="usage-label">Properties</div>
            </div>
            <div class="usage-item">
                <div class="usage-value"><?php echo $amenity['room_usage']; ?></div>
                <div class="usage-label">Rooms</div>
            </div>
        </div>
        
        <div class="amenity-actions">
            <button class="amenity-action-btn" onclick='editAmenity(<?php echo json_encode($amenity); ?>)'>
                <i class="bi bi-pencil"></i> Edit
            </button>
            <?php if ($amenity['is_custom']): ?>
            <button class="amenity-action-btn delete" onclick="deleteAmenity(<?php echo $amenity['amenity_id']; ?>, '<?php echo $amenity['amenity_key']; ?>', '<?php echo addslashes($amenity['amenity_name']); ?>')">
                <i class="bi bi-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Category-specific grids (hidden by default) -->
<div id="category-property" class="amenities-grid" style="display: none;"></div>
<div id="category-room" class="amenities-grid" style="display: none;"></div>
<div id="category-car" class="amenities-grid" style="display: none;"></div>
<div id="category-experience" class="amenities-grid" style="display: none;"></div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add Amenity Modal -->
<div class="modal" id="addAmenityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Custom Amenity</h3>
            <button class="modal-close" onclick="closeModal('addAmenityModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Category <span class="required">*</span></label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <option value="property">Property Amenity</option>
                            <option value="room">Room Amenity</option>
                            <option value="car">Car Feature</option>
                            <option value="experience">Experience Inclusion</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Amenity Key <span class="required">*</span></label>
                        <input type="text" name="amenity_key" class="form-control" 
                               placeholder="e.g., sauna, jacuzzi" required pattern="[a-z_]+" 
                               title="Lowercase letters and underscores only">
                        <div class="form-text">Unique identifier (lowercase, underscores)</div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Amenity Name <span class="required">*</span></label>
                        <input type="text" name="amenity_name" class="form-control" 
                               placeholder="e.g., Sauna, Jacuzzi" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" 
                                  placeholder="Brief description of this amenity"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Select Icon</label>
                        <div class="icon-selector" id="iconSelector">
                            <?php foreach ($availableIcons as $icon): ?>
                            <div class="icon-option" data-icon="<?php echo $icon; ?>" onclick="selectIcon(this)">
                                <i class="bi <?php echo $icon; ?>"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="amenity_icon" id="selectedIcon" value="bi-check-circle">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('addAmenityModal')">Cancel</button>
                <button type="submit" name="add_amenity" class="btn-primary">Add Amenity</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Amenity Modal -->
<div class="modal" id="editAmenityModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Amenity</h3>
            <button class="modal-close" onclick="closeModal('editAmenityModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="amenity_id" id="edit_amenity_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Amenity Name</label>
                    <input type="text" name="amenity_name" id="edit_amenity_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Icon</label>
                    <select name="amenity_icon" id="edit_amenity_icon" class="form-control">
                        <?php foreach ($availableIcons as $icon): ?>
                        <option value="<?php echo $icon; ?>"><?php echo $icon; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                    <label for="edit_is_active">Active (available for selection)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('editAmenityModal')">Cancel</button>
                <button type="submit" name="edit_amenity" class="btn-primary">Save Changes</button>
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
            <p id="deleteMessage" style="font-size: 1rem; margin-bottom: 8px;">Are you sure you want to delete this amenity?</p>
            <p style="font-size: 0.8125rem; color: var(--booking-text-light);">This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="amenity_id" id="delete_amenity_id" value="0">
            <input type="hidden" name="amenity_key" id="delete_amenity_key" value="">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_amenity" class="btn-primary" style="background: var(--booking-danger);">Delete Amenity</button>
            </div>
        </form>
    </div>
</div>

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
// CATEGORY SWITCHING
// ============================================
function switchCategory(category, element) {
    // Update tabs
    document.querySelectorAll('.category-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    element.classList.add('active');
    
    // Hide all grids
    document.getElementById('category-all').style.display = 'none';
    document.getElementById('category-property').style.display = 'none';
    document.getElementById('category-room').style.display = 'none';
    document.getElementById('category-car').style.display = 'none';
    document.getElementById('category-experience').style.display = 'none';
    
    if (category === 'all') {
        document.getElementById('category-all').style.display = 'grid';
    } else {
        // Show category grid and populate it
        const targetGrid = document.getElementById('category-' + category);
        targetGrid.style.display = 'grid';
        targetGrid.innerHTML = '';
        
        // Filter amenities by category
        const allAmenities = document.querySelectorAll('#category-all .amenity-card');
        allAmenities.forEach(amenity => {
            if (amenity.dataset.category === category) {
                targetGrid.appendChild(amenity.cloneNode(true));
            }
        });
    }
}

// ============================================
// ICON SELECTOR
// ============================================
function selectIcon(element) {
    document.querySelectorAll('.icon-option').forEach(icon => {
        icon.classList.remove('selected');
    });
    element.classList.add('selected');
    document.getElementById('selectedIcon').value = element.dataset.icon;
}

// ============================================
// AMENITY FUNCTIONS
// ============================================
function editAmenity(amenity) {
    document.getElementById('edit_amenity_id').value = amenity.amenity_id;
    document.getElementById('edit_amenity_name').value = amenity.amenity_name;
    document.getElementById('edit_description').value = amenity.description || '';
    document.getElementById('edit_amenity_icon').value = amenity.amenity_icon;
    document.getElementById('edit_is_active').checked = amenity.is_active == 1;
    openModal('editAmenityModal');
}

function deleteAmenity(id, key, name) {
    document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>"' + name + '"</strong>?';
    document.getElementById('delete_amenity_id').value = id;
    document.getElementById('delete_amenity_key').value = key;
    openModal('deleteModal');
}

// ============================================
// BULK ACTIONS
// ============================================
function bulkAction(action) {
    const category = document.getElementById('bulkCategory').value;
    if (!category) {
        alert('Please select a category');
        return;
    }
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    form.innerHTML = `
        <input type="hidden" name="bulk_action" value="${action}">
        <input type="hidden" name="bulk_category" value="${category}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ============================================
// EXPORT
// ============================================
function exportAmenities() {
    // Create CSV content
    let csv = "Category,Key,Name,Icon,Description,Type,Status,Property Usage,Room Usage\n";
    
    <?php foreach ($allAmenities as $amenity): ?>
    csv += "<?php echo $amenity['category']; ?>,<?php echo $amenity['amenity_key']; ?>,<?php echo str_replace(',', ' ', $amenity['amenity_name']); ?>,<?php echo $amenity['amenity_icon']; ?>,<?php echo str_replace(',', ' ', $amenity['description'] ?? ''); ?>,<?php echo $amenity['is_custom'] ? 'Custom' : 'System'; ?>,<?php echo $amenity['is_active'] ? 'Active' : 'Inactive'; ?>,<?php echo $amenity['property_usage']; ?>,<?php echo $amenity['room_usage']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'amenities_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Initialize - set first icon as selected
document.addEventListener('DOMContentLoaded', function() {
    const firstIcon = document.querySelector('.icon-option');
    if (firstIcon) {
        firstIcon.classList.add('selected');
    }
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>