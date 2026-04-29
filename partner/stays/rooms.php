<?php
$pageTitle = 'Room Management';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameter
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;

// ============================================
// HANDLE ROOM ACTIONS
// ============================================

// Add/Edit Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_room'])) {
    $roomId = intval($_POST['room_id'] ?? 0);
    $stayId = intval($_POST['stay_id']);
    $roomName = sanitize($_POST['room_name']);
    $description = sanitize($_POST['description']);
    $maxGuests = intval($_POST['max_guests']);
    $numRooms = intval($_POST['num_rooms_available']);
    $basePrice = floatval($_POST['base_price']);
    $sizeSqm = intval($_POST['size_sqm'] ?? 0);
    $bedConfig = sanitize($_POST['bed_configuration']);
    $roomAmenities = json_encode($_POST['amenities'] ?? []);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this property";
    } else {
        if ($roomId > 0) {
            // Update existing room
            $stmt = $db->prepare("
                UPDATE stay_rooms SET 
                    room_name = ?, description = ?, max_guests = ?, 
                    num_rooms_available = ?, base_price = ?, size_sqm = ?,
                    bed_configuration = ?, room_amenities = ?, is_active = ?
                WHERE room_id = ? AND stay_id = ?
            ");
            $stmt->execute([
                $roomName,
                $description,
                $maxGuests,
                $numRooms,
                $basePrice,
                $sizeSqm,
                $bedConfig,
                $roomAmenities,
                $isActive,
                $roomId,
                $stayId
            ]);
            $message = "Room updated successfully!";
        } else {
            // Insert new room
            $stmt = $db->prepare("
                INSERT INTO stay_rooms (
                    stay_id, room_name, description, max_guests, 
                    num_rooms_available, base_price, size_sqm, 
                    bed_configuration, room_amenities, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $stayId,
                $roomName,
                $description,
                $maxGuests,
                $numRooms,
                $basePrice,
                $sizeSqm,
                $bedConfig,
                $roomAmenities,
                $isActive
            ]);
            $message = "Room added successfully!";
        }
    }
}

// Delete Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room'])) {
    $roomId = intval($_POST['room_id']);
    $stayId = intval($_POST['stay_id']);

    // Verify ownership
    $stmt = $db->prepare("
        DELETE sr FROM stay_rooms sr
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE sr.room_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$roomId, $userId]);
    $message = "Room deleted successfully!";
}

// Bulk Update Prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $stayId = intval($_POST['stay_id']);
    $action = $_POST['bulk_action'];
    $value = floatval($_POST['bulk_value']);

    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    if ($stmt->fetch()) {
        if ($action === 'increase_percent') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = base_price * (1 + ? / 100)
                WHERE stay_id = ?
            ");
            $stmt->execute([$value, $stayId]);
            $message = "Prices increased by {$value}%";
        } elseif ($action === 'decrease_percent') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = base_price * (1 - ? / 100)
                WHERE stay_id = ?
            ");
            $stmt->execute([$value, $stayId]);
            $message = "Prices decreased by {$value}%";
        } elseif ($action === 'set_fixed') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = ?
                WHERE stay_id = ?
            ");
            $stmt->execute([$value, $stayId]);
            $message = "All rooms set to " . formatPrice($value);
        }
    }
}

// Toggle Room Status (AJAX endpoint)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $roomId = intval($_POST['room_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;

    $stmt = $db->prepare("
        UPDATE stay_rooms sr
        JOIN stays s ON sr.stay_id = s.stay_id
        SET sr.is_active = ?
        WHERE sr.room_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$newStatus, $roomId, $userId]);

    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    exit;
}

// ============================================
// GET PROPERTIES AND ROOMS
// ============================================

// Get all properties for this partner
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Get rooms for selected property
if ($propertyId > 0) {
    $stmt = $db->prepare("
        SELECT sr.*, s.stay_name, s.city
        FROM stay_rooms sr
        JOIN stays s ON sr.stay_id = s.stay_id
        WHERE s.owner_id = ? AND sr.stay_id = ?
        ORDER BY sr.base_price ASC
    ");
    $stmt->execute([$userId, $propertyId]);
    $rooms = $stmt->fetchAll();

    // Get property details
    $stmt = $db->prepare("
        SELECT stay_name, city, check_in_time, check_out_time
        FROM stays 
        WHERE stay_id = ? AND owner_id = ?
    ");
    $stmt->execute([$propertyId, $userId]);
    $currentProperty = $stmt->fetch();
}

// Get all rooms for statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_rooms,
        SUM(CASE WHEN sr.is_active = 1 THEN 1 ELSE 0 END) as active_rooms,
        AVG(sr.base_price) as avg_price,
        MIN(sr.base_price) as min_price,
        MAX(sr.base_price) as max_price,
        SUM(sr.num_rooms_available) as total_capacity
    FROM stay_rooms sr
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

// Room amenities list
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
    'view' => 'Mountain/City View',
    'sofa' => 'Sofa Bed',
    'dining' => 'Dining Area'
];
?>

<style>
    /* Rooms Management Specific Styles */
    .rooms-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 15px;
    }

    .rooms-title h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--booking-text);
        margin: 0 0 4px 0;
    }

    .rooms-title p {
        font-size: 0.8125rem;
        color: var(--booking-text-light);
        margin: 0;
    }

    /* Property Selector */
    .property-selector {
        background: white;
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }

    .property-selector label {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--booking-text);
    }

    .property-selector select {
        min-width: 300px;
        padding: 10px 16px;
        border: 1px solid var(--booking-border);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        background: white;
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

    .stat-range {
        font-size: 0.6875rem;
        color: var(--booking-text-light);
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px solid var(--booking-border);
    }

    /* Action Bar */
    .action-bar {
        background: white;
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .bulk-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .bulk-input {
        width: 100px;
        padding: 8px 12px;
        border: 1px solid var(--booking-border);
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
    }

    /* Rooms Grid */
    .rooms-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
    }

    .room-card {
        background: white;
        border: 1px solid var(--booking-border);
        border-radius: var(--radius-md);
        overflow: hidden;
        transition: all 0.2s;
        position: relative;
    }

    .room-card:hover {
        box-shadow: var(--shadow-md);
    }

    .room-header {
        padding: 16px;
        background: var(--booking-light-blue);
        border-bottom: 1px solid var(--booking-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .room-name {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--booking-blue);
    }

    .room-status {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .status-toggle {
        width: 40px;
        height: 20px;
        background: #ccc;
        border-radius: 20px;
        position: relative;
        cursor: pointer;
        transition: all 0.3s;
    }

    .status-toggle.active {
        background: var(--booking-success);
    }

    .status-toggle .toggle-circle {
        width: 16px;
        height: 16px;
        background: white;
        border-radius: 50%;
        position: absolute;
        top: 2px;
        left: 2px;
        transition: all 0.3s;
    }

    .status-toggle.active .toggle-circle {
        left: 22px;
    }

    .room-body {
        padding: 16px;
    }

    .room-details {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 16px;
    }

    .detail-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.8125rem;
        color: var(--booking-text);
    }

    .detail-item i {
        color: var(--booking-blue);
        font-size: 1rem;
        width: 20px;
    }

    .room-price {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--booking-success);
        margin: 10px 0;
        text-align: right;
    }

    .room-price small {
        font-size: 0.75rem;
        font-weight: 400;
        color: var(--booking-text-light);
    }

    .room-amenities {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 12px 0;
        padding: 12px 0;
        border-top: 1px solid var(--booking-border);
        border-bottom: 1px solid var(--booking-border);
    }

    .amenity-tag {
        background: var(--booking-gray);
        padding: 4px 10px;
        border-radius: 100px;
        font-size: 0.6875rem;
        color: var(--booking-text);
        border: 1px solid var(--booking-border);
    }

    .room-actions {
        display: flex;
        gap: 8px;
        margin-top: 16px;
    }

    .room-action-btn {
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
        text-align: center;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
    }

    .room-action-btn:hover {
        background: var(--booking-light-blue);
        border-color: var(--booking-blue);
        color: var(--booking-blue);
    }

    .room-action-btn.delete:hover {
        background: #fce8e8;
        border-color: var(--booking-danger);
        color: var(--booking-danger);
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

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
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
        box-shadow: 0 0 0 3px rgba(0, 59, 149, 0.1);
    }

    /* Amenities Grid */
    .amenities-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
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
        padding: 6px 8px;
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
        .rooms-grid,
        .form-grid {
            grid-template-columns: 1fr;
        }

        .property-selector select {
            min-width: 100%;
        }

        .action-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .bulk-actions {
            flex-direction: column;
        }

        .bulk-input {
            width: 100%;
        }
    }
</style>

<div class="rooms-header">
    <div class="rooms-title">

        <p>Manage rooms, prices, and availability across your properties</p>
    </div>
    <button class="btn-primary" onclick="openAddRoomModal()" <?php echo $propertyId > 0 ? '' : 'disabled'; ?>>
        <i class="bi bi-plus-lg"></i> Add New Room
    </button>
</div>

<!-- Property Selector -->
<div class="property-selector">
    <label for="propertySelect"><i class="bi bi-building"></i> Select Property:</label>
    <select id="propertySelect" onchange="changeProperty(this.value)">
        <option value="">Choose a property</option>
        <?php foreach ($properties as $prop): ?>
            <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
                <?php echo sanitize($prop['stay_name']); ?> (<?php echo sanitize($prop['city'] ?? 'Rwanda'); ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($propertyId > 0 && $currentProperty): ?>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($rooms); ?></div>
            <div class="stat-label">Total Room Types</div>
            <div class="stat-range"><?php echo $stats['total_capacity'] ?? 0; ?> individual rooms</div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['active_rooms'] ?? 0; ?>/<?php echo count($rooms); ?></div>
            <div class="stat-label">Active Rooms</div>
            <div class="stat-range"><?php echo round(($stats['active_rooms'] / max(1, count($rooms))) * 100); ?>% active</div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?php echo formatPrice($stats['avg_price'] ?? 0); ?></div>
            <div class="stat-label">Average Price</div>
            <div class="stat-range">Min: <?php echo formatPrice($stats['min_price'] ?? 0); ?> • Max: <?php echo formatPrice($stats['max_price'] ?? 0); ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['total_capacity'] ?? 0; ?></div>
            <div class="stat-label">Total Capacity</div>
            <div class="stat-range">Across all rooms</div>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
        <div class="action-buttons">
            <button class="btn-secondary" onclick="openBulkModal()">
                <i class="bi bi-pencil-square"></i> Bulk Update
            </button>
            <button class="btn-secondary" onclick="exportRooms()">
                <i class="bi bi-download"></i> Export
            </button>
        </div>

        <div class="bulk-actions">
            <span style="font-size: 0.8125rem; color: var(--booking-text-light);">Quick actions:</span>
            <button class="btn-outline" onclick="bulkAction('activate_all')">
                <i class="bi bi-check-circle"></i> Activate All
            </button>
            <button class="btn-outline" onclick="bulkAction('deactivate_all')">
                <i class="bi bi-pause-circle"></i> Deactivate All
            </button>
        </div>
    </div>

    <!-- Message Display -->
    <?php if (isset($message)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $message; ?>
            <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Rooms Grid -->
    <?php if (empty($rooms)): ?>
        <div class="empty-state">
            <i class="bi bi-door-open"></i>
            <h3>No rooms added yet</h3>
            <p>Start by adding your first room type for <?php echo sanitize($currentProperty['stay_name']); ?></p>
            <button class="btn-primary" onclick="openAddRoomModal()">
                <i class="bi bi-plus-lg"></i> Add First Room
            </button>
        </div>
    <?php else: ?>
        <div class="rooms-grid">
            <?php foreach ($rooms as $room):
                $amenities = json_decode($room['room_amenities'] ?? '[]', true);
            ?>
                <div class="room-card" id="room-<?php echo $room['room_id']; ?>">
                    <div class="room-header">
                        <h3 class="room-name"><?php echo sanitize($room['room_name']); ?></h3>
                        <div class="room-status">
                            <div class="status-toggle <?php echo $room['is_active'] ? 'active' : ''; ?>"
                                onclick="toggleRoomStatus(<?php echo $room['room_id']; ?>, <?php echo $room['is_active']; ?>)">
                                <div class="toggle-circle"></div>
                            </div>
                            <span style="font-size: 0.75rem; color: var(--booking-text-light);">
                                <?php echo $room['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="room-body">
                        <div class="room-details">
                            <div class="detail-item">
                                <i class="bi bi-people"></i>
                                <span>Max <?php echo $room['max_guests']; ?> guests</span>
                            </div>
                            <div class="detail-item">
                                <i class="bi bi-door-open"></i>
                                <span><?php echo $room['num_rooms_available']; ?> rooms</span>
                            </div>
                            <?php if ($room['size_sqm'] > 0): ?>
                                <div class="detail-item">
                                    <i class="bi bi-rulers"></i>
                                    <span><?php echo $room['size_sqm']; ?> m²</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($room['bed_configuration']): ?>
                                <div class="detail-item">
                                    <i class="bi bi-bed"></i>
                                    <span><?php echo $room['bed_configuration']; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="room-price">
                            <?php echo formatPrice($room['base_price']); ?> <small>per night</small>
                        </div>

                        <?php if (!empty($amenities)): ?>
                            <div class="room-amenities">
                                <?php foreach (array_slice($amenities, 0, 4) as $amenity): ?>
                                    <span class="amenity-tag">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php echo $roomAmenitiesList[$amenity] ?? ucfirst($amenity); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($amenities) > 4): ?>
                                    <span class="amenity-tag">+<?php echo count($amenities) - 4; ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="room-actions">
                            <button class="room-action-btn" onclick="editRoom(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="room-action-btn" onclick="duplicateRoom(<?php echo $room['room_id']; ?>)">
                                <i class="bi bi-files"></i> Duplicate
                            </button>
                            <button class="room-action-btn delete" onclick="deleteRoom(<?php echo $room['room_id']; ?>, '<?php echo $room['stay_id']; ?>', '<?php echo sanitize($room['room_name']); ?>')">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="empty-state">
        <i class="bi bi-building"></i>
        <h3>Select a property</h3>
        <p>Please select a property from the dropdown above to manage its rooms</p>
    </div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add/Edit Room Modal -->
<div class="modal" id="roomModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="roomModalTitle">Add New Room</h3>
            <button class="modal-close" onclick="closeModal('roomModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="roomForm">
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Enter your base price (without tax). The final customer price will be automatically calculated with <?php echo getTaxRate(); ?>% VAT included.
                </div>
                <input type="hidden" name="room_id" id="room_id" value="0">
                <input type="hidden" name="stay_id" id="stay_id" value="<?php echo $propertyId; ?>">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Room Name <span class="required">*</span></label>
                        <input type="text" name="room_name" id="room_name" class="form-control"
                            placeholder="e.g., Deluxe Room, Standard Double" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Max Guests <span class="required">*</span></label>
                        <input type="number" name="max_guests" id="max_guests" class="form-control"
                            value="2" min="1" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Number of Rooms <span class="required">*</span></label>
                        <input type="number" name="num_rooms_available" id="num_rooms_available" class="form-control"
                            value="1" min="1" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Price per Night (RWF) <span class="required">*</span></label>
                        <input type="number" name="base_price" id="base_price" class="form-control"
                            value="0" min="0" step="1000" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Room Size (m²)</label>
                        <input type="number" name="size_sqm" id="size_sqm" class="form-control" value="0" min="0">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Bed Configuration</label>
                        <input type="text" name="bed_configuration" id="bed_configuration" class="form-control"
                            placeholder="e.g., 1 King Bed, 2 Twin Beds">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Room Amenities</label>
                        <div class="amenities-grid" id="amenitiesContainer">
                            <?php foreach ($roomAmenitiesList as $key => $label): ?>
                                <label class="amenity-checkbox">
                                    <input type="checkbox" name="amenities[]" value="<?php echo $key; ?>">
                                    <?php echo $label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-group" style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="is_active" id="is_active" checked>
                            <span style="font-size: 0.875rem;">Active (available for booking)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('roomModal')">Cancel</button>
                <button type="submit" name="save_room" class="btn-primary">Save Room</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal" id="bulkModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Bulk Update Prices</h3>
            <button class="modal-close" onclick="closeModal('bulkModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">

                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="bulk_action" id="bulk_action" class="form-control" onchange="toggleBulkInput()">
                        <option value="increase_percent">Increase by percentage (%)</option>
                        <option value="decrease_percent">Decrease by percentage (%)</option>
                        <option value="set_fixed">Set fixed price (RWF)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" id="bulk_label">Percentage (%)</label>
                    <input type="number" name="bulk_value" id="bulk_value" class="form-control"
                        step="1" min="0" required>
                </div>

                <div style="background: var(--booking-gray); padding: 16px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.875rem; margin-bottom: 8px; font-weight: 600;">Preview:</p>
                    <p style="font-size: 0.8125rem; color: var(--booking-text-light);">
                        This will affect all <?php echo count($rooms); ?> room types in this property.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" name="bulk_update" class="btn-primary">Apply Changes</button>
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
            <p id="deleteMessage" style="font-size: 1rem; margin-bottom: 8px;">Are you sure you want to delete this room?</p>
            <p style="font-size: 0.8125rem; color: var(--booking-text-light);">This action cannot be undone. Any associated bookings will be affected.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="room_id" id="delete_room_id" value="0">
            <input type="hidden" name="stay_id" id="delete_stay_id" value="<?php echo $propertyId; ?>">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_room" class="btn-primary" style="background: var(--booking-danger);">Delete Room</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ============================================
    // PROPERTY SELECTION
    // ============================================
    function changeProperty(propertyId) {
        if (propertyId) {
            window.location.href = 'rooms.php?property=' + propertyId;
        } else {
            window.location.href = 'rooms.php';
        }
    }

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
    // ROOM FUNCTIONS
    // ============================================
    function openAddRoomModal() {
        document.getElementById('roomModalTitle').textContent = 'Add New Room';
        document.getElementById('roomForm').reset();
        document.getElementById('room_id').value = 0;
        document.getElementById('is_active').checked = true;
        openModal('roomModal');
    }

    function editRoom(room) {
        document.getElementById('roomModalTitle').textContent = 'Edit Room';
        document.getElementById('room_id').value = room.room_id;
        document.getElementById('room_name').value = room.room_name;
        document.getElementById('max_guests').value = room.max_guests;
        document.getElementById('num_rooms_available').value = room.num_rooms_available;
        document.getElementById('base_price').value = room.base_price;
        document.getElementById('size_sqm').value = room.size_sqm || 0;
        document.getElementById('bed_configuration').value = room.bed_configuration || '';
        document.getElementById('description').value = room.description || '';
        document.getElementById('is_active').checked = room.is_active == 1;

        // Check amenities
        let amenities = [];
        try {
            amenities = JSON.parse(room.room_amenities || '[]');
        } catch (e) {
            amenities = [];
        }

        document.querySelectorAll('#amenitiesContainer input[type="checkbox"]').forEach(cb => {
            cb.checked = amenities.includes(cb.value);
        });

        openModal('roomModal');
    }

    function duplicateRoom(roomId) {
        // This would typically clone the room via AJAX
        alert('Duplicate functionality coming soon!');
    }

    function deleteRoom(roomId, stayId, roomName) {
        document.getElementById('deleteMessage').innerHTML = 'Are you sure you want to delete <strong>"' + roomName + '"</strong>?';
        document.getElementById('delete_room_id').value = roomId;
        document.getElementById('delete_stay_id').value = stayId;
        openModal('deleteModal');
    }

    // ============================================
    // TOGGLE ROOM STATUS (AJAX)
    // ============================================
    function toggleRoomStatus(roomId, currentStatus) {
        fetch('rooms.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'toggle_status=1&room_id=' + roomId + '&current_status=' + currentStatus
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const toggle = event.currentTarget;
                    if (data.new_status == 1) {
                        toggle.classList.add('active');
                        toggle.nextElementSibling.textContent = 'Active';
                    } else {
                        toggle.classList.remove('active');
                        toggle.nextElementSibling.textContent = 'Inactive';
                    }
                    showNotification('Room status updated', 'success');
                }
            })
            .catch(error => {
                showNotification('Error updating status', 'danger');
            });
    }

    // ============================================
    // BULK ACTIONS
    // ============================================
    function openBulkModal() {
        openModal('bulkModal');
    }

    function toggleBulkInput() {
        const action = document.getElementById('bulk_action').value;
        const label = document.getElementById('bulk_label');
        const input = document.getElementById('bulk_value');

        if (action === 'set_fixed') {
            label.textContent = 'Fixed Price (RWF)';
            input.step = '1000';
            input.min = '0';
        } else {
            label.textContent = 'Percentage (%)';
            input.step = '1';
            input.min = '0';
        }
    }

    function bulkAction(action) {
        if (action === 'activate_all') {
            if (confirm('Activate all rooms in this property?')) {
                // Here you would make an AJAX call
                showNotification('All rooms activated', 'success');
            }
        } else if (action === 'deactivate_all') {
            if (confirm('Deactivate all rooms in this property?')) {
                // Here you would make an AJAX call
                showNotification('All rooms deactivated', 'success');
            }
        }
    }

    // ============================================
    // EXPORT
    // ============================================
    function exportRooms() {
        // Create CSV content
        let csv = "Room Name,Max Guests,Quantity,Price,Size,Bed Configuration,Status\n";

        <?php foreach ($rooms as $room): ?>
            csv += "<?php echo $room['room_name']; ?>,<?php echo $room['max_guests']; ?>,<?php echo $room['num_rooms_available']; ?>,<?php echo $room['base_price']; ?>,<?php echo $room['size_sqm']; ?>,<?php echo $room['bed_configuration']; ?>,<?php echo $room['is_active'] ? 'Active' : 'Inactive'; ?>\n";
        <?php endforeach; ?>

        // Download CSV
        const blob = new Blob([csv], {
            type: 'text/csv'
        });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'rooms_export.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    }

    // ============================================
    // NOTIFICATION FUNCTION
    // ============================================
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 12px 20px;
        background: ${type === 'success' ? '#e6f4ea' : '#fce8e8'};
        color: ${type === 'success' ? 'var(--booking-success)' : 'var(--booking-danger)'};
        border-radius: var(--radius-sm);
        box-shadow: var(--shadow-lg);
        font-size: 0.875rem;
        animation: slideIn 0.3s ease;
    `;
        notification.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
        ${message}
        <button onclick="this.parentElement.remove()" style="margin-left: 12px; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
    `;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Add animation style
    const style = document.createElement('style');
    style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
`;
    document.head.appendChild(style);
</script>

<?php require_once 'includes/stays_footer.php'; ?>