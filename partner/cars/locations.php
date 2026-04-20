<?php
$pageTitle = 'Locations Management';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get vendor profile ID
$stmt = $db->prepare("SELECT vendor_id FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$vendor = $stmt->fetch();
$vendorId = $vendor ? $vendor['vendor_id'] : 0;

// ============================================
// HANDLE LOCATION ACTIONS
// ============================================

// Add new location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
    $locationName = sanitize($_POST['location_name']);
    $locationType = sanitize($_POST['location_type']);
    $address = sanitize($_POST['address']);
    $city = sanitize($_POST['city']);
    $country = sanitize($_POST['country'] ?? 'Rwanda');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $contactPhone = sanitize($_POST['contact_phone'] ?? '');
    $contactEmail = sanitize($_POST['contact_email'] ?? '');
    $openingHours = sanitize($_POST['opening_hours'] ?? '');
    $isHeadquarters = isset($_POST['is_headquarters']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $notes = sanitize($_POST['notes'] ?? '');
    
    // If this is headquarters, unset any existing headquarters
    if ($isHeadquarters) {
        $stmt = $db->prepare("UPDATE car_locations SET is_headquarters = 0 WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
    }
    
    $stmt = $db->prepare("
        INSERT INTO car_locations (
            vendor_id, location_name, location_type, address, city, country,
            latitude, longitude, contact_phone, contact_email, opening_hours,
            is_headquarters, is_active, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $vendorId, $locationName, $locationType, $address, $city, $country,
        $latitude, $longitude, $contactPhone, $contactEmail, $openingHours,
        $isHeadquarters, $isActive, $notes
    ]);
    
    $success = "Location added successfully!";
}

// Update location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_location'])) {
    $locationId = intval($_POST['location_id']);
    $locationName = sanitize($_POST['location_name']);
    $locationType = sanitize($_POST['location_type']);
    $address = sanitize($_POST['address']);
    $city = sanitize($_POST['city']);
    $country = sanitize($_POST['country'] ?? 'Rwanda');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $contactPhone = sanitize($_POST['contact_phone'] ?? '');
    $contactEmail = sanitize($_POST['contact_email'] ?? '');
    $openingHours = sanitize($_POST['opening_hours'] ?? '');
    $isHeadquarters = isset($_POST['is_headquarters']) ? 1 : 0;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $notes = sanitize($_POST['notes'] ?? '');
    
    // If this is headquarters, unset any existing headquarters
    if ($isHeadquarters) {
        $stmt = $db->prepare("UPDATE car_locations SET is_headquarters = 0 WHERE vendor_id = ? AND location_id != ?");
        $stmt->execute([$vendorId, $locationId]);
    }
    
    $stmt = $db->prepare("
        UPDATE car_locations 
        SET location_name = ?,
            location_type = ?,
            address = ?,
            city = ?,
            country = ?,
            latitude = ?,
            longitude = ?,
            contact_phone = ?,
            contact_email = ?,
            opening_hours = ?,
            is_headquarters = ?,
            is_active = ?,
            notes = ?,
            updated_at = NOW()
        WHERE location_id = ? AND vendor_id = ?
    ");
    
    $stmt->execute([
        $locationName, $locationType, $address, $city, $country,
        $latitude, $longitude, $contactPhone, $contactEmail, $openingHours,
        $isHeadquarters, $isActive, $notes, $locationId, $vendorId
    ]);
    
    $success = "Location updated successfully!";
}

// Delete location
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_location'])) {
    $locationId = intval($_POST['location_id']);
    
    // Check if location has any associated bookings
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM bookings 
        WHERE pickup_location = ? OR return_location = ?
    ");
    $stmt->execute([$locationId, $locationId]);
    $usage = $stmt->fetch();
    
    if ($usage['count'] > 0) {
        $error = "Cannot delete location - it has $usage[count] associated bookings. Consider deactivating it instead.";
    } else {
        $stmt = $db->prepare("DELETE FROM car_locations WHERE location_id = ? AND vendor_id = ?");
        $stmt->execute([$locationId, $vendorId]);
        $success = "Location deleted successfully!";
    }
}

// Toggle location status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $locationId = intval($_POST['location_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE car_locations 
        SET is_active = ? 
        WHERE location_id = ? AND vendor_id = ?
    ");
    $stmt->execute([$newStatus, $locationId, $vendorId]);
    
    $success = "Location status updated!";
}

// Set as headquarters
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_headquarters'])) {
    $locationId = intval($_POST['location_id']);
    
    // Unset current headquarters
    $stmt = $db->prepare("UPDATE car_locations SET is_headquarters = 0 WHERE vendor_id = ?");
    $stmt->execute([$vendorId]);
    
    // Set new headquarters
    $stmt = $db->prepare("UPDATE car_locations SET is_headquarters = 1 WHERE location_id = ? AND vendor_id = ?");
    $stmt->execute([$locationId, $vendorId]);
    
    $success = "Headquarters location updated!";
}

// ============================================
// GET LOCATIONS DATA
// ============================================

// Get all locations for this vendor
$stmt = $db->prepare("
    SELECT * FROM car_locations 
    WHERE vendor_id = ? 
    ORDER BY is_headquarters DESC, is_active DESC, location_name ASC
");
$stmt->execute([$vendorId]);
$locations = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($locations),
    'active' => 0,
    'inactive' => 0,
    'headquarters' => 0,
    'airport' => 0,
    'city_center' => 0,
    'branch' => 0,
    'other' => 0
];

foreach ($locations as $loc) {
    if ($loc['is_active']) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
    
    if ($loc['is_headquarters']) {
        $stats['headquarters']++;
    }
    
    switch($loc['location_type']) {
        case 'airport':
            $stats['airport']++;
            break;
        case 'city_center':
            $stats['city_center']++;
            break;
        case 'branch':
            $stats['branch']++;
            break;
        default:
            $stats['other']++;
    }
}

// Get location usage statistics
$stmt = $db->prepare("
    SELECT 
        pickup_location,
        COUNT(*) as pickup_count,
        SUM(total_amount) as pickup_revenue
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    GROUP BY pickup_location
");
$stmt->execute([$userId]);
$pickupStats = $stmt->fetchAll();

$pickupStatsMap = [];
foreach ($pickupStats as $stat) {
    $pickupStatsMap[$stat['pickup_location']] = [
        'count' => $stat['pickup_count'],
        'revenue' => $stat['pickup_revenue']
    ];
}

$stmt = $db->prepare("
    SELECT 
        return_location,
        COUNT(*) as return_count
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    GROUP BY return_location
");
$stmt->execute([$userId]);
$returnStats = $stmt->fetchAll();

$returnStatsMap = [];
foreach ($returnStats as $stat) {
    $returnStatsMap[$stat['return_location']] = $stat['return_count'];
}

// Location types
$locationTypes = [
    'airport' => 'Airport',
    'city_center' => 'City Center',
    'branch' => 'Branch Office',
    'hotel' => 'Hotel Partner',
    'mall' => 'Shopping Mall',
    'other' => 'Other'
];

// Countries list (common for East Africa)
$countries = ['Rwanda', 'Uganda', 'Kenya', 'Tanzania', 'Burundi', 'DR Congo'];
?>

<style>
/* Locations Specific Styles */
.locations-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.locations-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.locations-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
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
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
}

/* Map Preview */
.map-preview {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 20px;
    margin-bottom: 24px;
}

.map-container {
    height: 300px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    position: relative;
    overflow: hidden;
}

.map-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-light);
    background: linear-gradient(145deg, #f8f9fa 0%, #e9ecef 100%);
}

.map-placeholder i {
    font-size: 3rem;
    margin-bottom: 16px;
    color: var(--cars-primary);
}

/* Locations Grid */
.locations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.location-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.location-card:hover {
    box-shadow: var(--shadow-md);
}

.location-card.headquarters {
    border: 2px solid var(--cars-primary);
    position: relative;
}

.location-card.headquarters::before {
    content: '🏢 HQ';
    position: absolute;
    top: 12px;
    right: 12px;
    background: var(--cars-primary);
    color: white;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    z-index: 2;
}

.location-card.inactive {
    opacity: 0.7;
    background: var(--bg-gray);
}

.location-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.location-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.location-type {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    background: var(--cars-light);
    color: var(--cars-primary);
    text-transform: uppercase;
}

.location-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.badge-active {
    background: #e6f4ea;
    color: var(--cars-success);
}

.badge-inactive {
    background: var(--bg-gray);
    color: var(--text-light);
}

.location-body {
    padding: 16px;
}

.location-address {
    margin-bottom: 12px;
    font-size: 0.875rem;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}

.location-address i {
    color: var(--cars-primary);
    margin-top: 3px;
}

.location-contact {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
}

.contact-item i {
    color: var(--cars-primary);
    width: 16px;
}

.location-hours {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    padding: 8px 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.location-hours i {
    color: var(--cars-primary);
}

.location-notes {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
    padding: 8px 12px;
    background: #fff4e6;
    border-radius: var(--radius-sm);
    border-left: 3px solid var(--cars-warning);
}

.location-stats {
    display: flex;
    justify-content: space-between;
    margin-bottom: 16px;
    padding: 12px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.stat-block {
    text-align: center;
    flex: 1;
}

.stat-number {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--cars-primary);
}

.stat-text {
    font-size: 0.625rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.location-coordinates {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    font-size: 0.6875rem;
    color: var(--text-light);
    background: var(--bg-gray);
    padding: 6px 8px;
    border-radius: var(--radius-sm);
}

.location-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-gray);
}

.action-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--text-dark);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--cars-light);
    border-color: var(--cars-primary);
    color: var(--cars-primary);
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--cars-warning);
    color: var(--cars-warning);
}

.action-btn.success:hover {
    background: #e6f4ea;
    border-color: var(--cars-success);
    color: var(--cars-success);
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--cars-danger);
    color: var(--cars-danger);
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

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

/* Coordinates input */
.coordinates-group {
    display: flex;
    gap: 8px;
}

.coordinates-group input {
    flex: 1;
}

/* Checkbox group */
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.checkbox-group input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--cars-primary);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .locations-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .location-contact {
        grid-template-columns: 1fr;
    }
    
    .coordinates-group {
        flex-direction: column;
    }
}
</style>

<div class="locations-header">
    <div class="locations-title">
        <h1>Locations Management</h1>
        <p>Manage your pickup and dropoff locations, branches, and service areas</p>
    </div>
    <button class="btn-primary" onclick="openAddLocationModal()">
        <i class="bi bi-plus-lg"></i> Add Location
    </button>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Locations</div>
        <div class="stat-footer">
            <span>Active: <?php echo $stats['active']; ?></span>
            <span>Inactive: <?php echo $stats['inactive']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['airport']; ?></div>
        <div class="stat-label">Airport Locations</div>
        <div class="stat-footer">Convenient for travelers</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['city_center']; ?></div>
        <div class="stat-label">City Centers</div>
        <div class="stat-footer">Downtown access</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['branch']; ?></div>
        <div class="stat-label">Branch Offices</div>
        <div class="stat-footer"><?php echo $stats['headquarters']; ?> headquarters</div>
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

<!-- Locations Grid -->
<?php if (empty($locations)): ?>
<div class="empty-state">
    <i class="bi bi-geo-alt"></i>
    <h3>No locations added yet</h3>
    <p>Add your first pickup location to start serving customers.</p>
    <button class="btn-primary" onclick="openAddLocationModal()">
        <i class="bi bi-plus-lg"></i> Add Location
    </button>
</div>
<?php else: ?>
<div class="locations-grid">
    <?php foreach ($locations as $location): 
        $pickupCount = $pickupStatsMap[$location['location_id']]['count'] ?? 0;
        $pickupRevenue = $pickupStatsMap[$location['location_id']]['revenue'] ?? 0;
        $returnCount = $returnStatsMap[$location['location_id']] ?? 0;
    ?>
    <div class="location-card <?php echo $location['is_headquarters'] ? 'headquarters' : ''; ?> <?php echo $location['is_active'] ? '' : 'inactive'; ?>">
        <div class="location-header">
            <div class="location-name">
                <?php echo sanitize($location['location_name']); ?>
                <span class="location-type"><?php echo $locationTypes[$location['location_type']] ?? $location['location_type']; ?></span>
            </div>
            <span class="location-badge <?php echo $location['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $location['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        
        <div class="location-body">
            <div class="location-address">
                <i class="bi bi-geo-alt-fill"></i>
                <div>
                    <?php echo nl2br(sanitize($location['address'])); ?><br>
                    <?php echo sanitize($location['city']) . ', ' . sanitize($location['country']); ?>
                </div>
            </div>
            
            <div class="location-contact">
                <?php if ($location['contact_phone']): ?>
                <div class="contact-item">
                    <i class="bi bi-telephone"></i>
                    <span><?php echo sanitize($location['contact_phone']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($location['contact_email']): ?>
                <div class="contact-item">
                    <i class="bi bi-envelope"></i>
                    <span><?php echo sanitize($location['contact_email']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($location['opening_hours']): ?>
            <div class="location-hours">
                <i class="bi bi-clock"></i>
                <span><?php echo sanitize($location['opening_hours']); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($location['notes']): ?>
            <div class="location-notes">
                <i class="bi bi-sticky"></i>
                <?php echo sanitize($location['notes']); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($pickupCount > 0 || $returnCount > 0): ?>
            <div class="location-stats">
                <div class="stat-block">
                    <div class="stat-number"><?php echo $pickupCount; ?></div>
                    <div class="stat-text">Pickups</div>
                </div>
                <div class="stat-block">
                    <div class="stat-number"><?php echo $returnCount; ?></div>
                    <div class="stat-text">Returns</div>
                </div>
                <div class="stat-block">
                    <div class="stat-number"><?php echo formatPrice($pickupRevenue); ?></div>
                    <div class="stat-text">Revenue</div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($location['latitude'] && $location['longitude']): ?>
            <div class="location-coordinates">
                <span><i class="bi bi-crosshair"></i> <?php echo $location['latitude']; ?>, <?php echo $location['longitude']; ?></span>
            </div>
            <?php endif; ?>
            
            <div class="location-actions">
                <button class="action-btn" onclick="editLocation(<?php echo htmlspecialchars(json_encode($location)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                
                <?php if (!$location['is_headquarters']): ?>
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="location_id" value="<?php echo $location['location_id']; ?>">
                    <button type="submit" name="set_headquarters" class="action-btn success" onclick="return confirm('Set this as headquarters?')">
                        <i class="bi bi-building"></i> Set HQ
                    </button>
                </form>
                <?php endif; ?>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="location_id" value="<?php echo $location['location_id']; ?>">
                    <input type="hidden" name="current_status" value="<?php echo $location['is_active']; ?>">
                    <button type="submit" name="toggle_status" class="action-btn warning">
                        <i class="bi bi-<?php echo $location['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                        <?php echo $location['is_active'] ? 'Deactivate' : 'Activate'; ?>
                    </button>
                </form>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="location_id" value="<?php echo $location['location_id']; ?>">
                    <button type="submit" name="delete_location" class="action-btn danger" onclick="return confirm('Delete this location?')">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add/Edit Location Modal -->
<div class="modal" id="locationModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add Location</h3>
            <button class="modal-close" onclick="closeModal('locationModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="locationForm">
            <div class="modal-body">
                <input type="hidden" name="location_id" id="location_id" value="0">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Location Name <span class="required">*</span></label>
                        <input type="text" name="location_name" id="location_name" class="form-control" placeholder="e.g., Kigali International Airport" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location Type <span class="required">*</span></label>
                        <select name="location_type" id="location_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <?php foreach ($locationTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <select name="country" id="country" class="form-control">
                            <?php foreach ($countries as $c): ?>
                            <option value="<?php echo $c; ?>" <?php echo $c == 'Rwanda' ? 'selected' : ''; ?>><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Address <span class="required">*</span></label>
                        <textarea name="address" id="address" class="form-control" rows="2" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">City <span class="required">*</span></label>
                        <input type="text" name="city" id="city" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Phone</label>
                        <input type="tel" name="contact_phone" id="contact_phone" class="form-control" placeholder="+250 788 123 456">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Email</label>
                        <input type="email" name="contact_email" id="contact_email" class="form-control" placeholder="branch@company.com">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Opening Hours</label>
                        <input type="text" name="opening_hours" id="opening_hours" class="form-control" placeholder="e.g., Mon-Fri: 8am-6pm, Sat: 9am-4pm">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Latitude</label>
                        <input type="number" name="latitude" id="latitude" class="form-control" step="any" placeholder="-1.9441">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Longitude</label>
                        <input type="number" name="longitude" id="longitude" class="form-control" step="any" placeholder="30.0619">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Additional information about this location..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_headquarters" id="is_headquarters" value="1">
                            <label for="is_headquarters">Set as Headquarters</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                            <label for="is_active">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('locationModal')">Cancel</button>
                <button type="submit" name="add_location" id="submitBtn" class="btn-primary">Add Location</button>
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
// LOCATION FUNCTIONS
// ============================================
function openAddLocationModal() {
    document.getElementById('modalTitle').textContent = 'Add Location';
    document.getElementById('locationForm').reset();
    document.getElementById('location_id').value = '0';
    document.getElementById('is_active').checked = true;
    document.getElementById('submitBtn').name = 'add_location';
    document.getElementById('submitBtn').textContent = 'Add Location';
    openModal('locationModal');
}

function editLocation(location) {
    document.getElementById('modalTitle').textContent = 'Edit Location';
    document.getElementById('location_id').value = location.location_id;
    document.getElementById('location_name').value = location.location_name;
    document.getElementById('location_type').value = location.location_type;
    document.getElementById('country').value = location.country;
    document.getElementById('address').value = location.address;
    document.getElementById('city').value = location.city;
    document.getElementById('contact_phone').value = location.contact_phone || '';
    document.getElementById('contact_email').value = location.contact_email || '';
    document.getElementById('opening_hours').value = location.opening_hours || '';
    document.getElementById('latitude').value = location.latitude || '';
    document.getElementById('longitude').value = location.longitude || '';
    document.getElementById('notes').value = location.notes || '';
    document.getElementById('is_headquarters').checked = location.is_headquarters == 1;
    document.getElementById('is_active').checked = location.is_active == 1;
    
    document.getElementById('submitBtn').name = 'update_location';
    document.getElementById('submitBtn').textContent = 'Update Location';
    openModal('locationModal');
}

// ============================================
// MAP FUNCTIONS (placeholder for future integration)
// ============================================
function initMap() {
    // This would initialize Google Maps or OpenStreetMap
    console.log('Map would be initialized here');
}

function geocodeAddress() {
    const address = document.getElementById('address').value;
    const city = document.getElementById('city').value;
    const country = document.getElementById('country').value;
    
    if (address && city) {
        // In a real implementation, you would call a geocoding API
        // For now, we'll show a message
        alert('Geocoding would fetch coordinates for: ' + address + ', ' + city + ', ' + country);
    }
}

// Add geocode button
document.addEventListener('DOMContentLoaded', function() {
    const addressField = document.getElementById('address');
    if (addressField) {
        const geocodeBtn = document.createElement('button');
        geocodeBtn.type = 'button';
        geocodeBtn.className = 'btn-secondary btn-sm';
        geocodeBtn.innerHTML = '<i class="bi bi-crosshair"></i> Get Coordinates';
        geocodeBtn.style.marginTop = '8px';
        geocodeBtn.onclick = geocodeAddress;
        addressField.parentNode.appendChild(geocodeBtn);
    }
});
</script>

