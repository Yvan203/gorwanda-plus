<?php
$stayId = isset($_GET['stay_id']) ? intval($_GET['stay_id']) : 0;

if (!$stayId) {
    header('Location: stays.php');
    exit;
}

$pageTitle = 'Rooms Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Get stay details
$stmt = $db->prepare("SELECT stay_name, stay_id FROM stays WHERE stay_id = ?");
$stmt->execute([$stayId]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: stays.php');
    exit;
}

// Handle room actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Add/Edit Room
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add_room' || $action === 'edit_room')) {
    $roomId = isset($_POST['room_id']) ? intval($_POST['room_id']) : 0;
    $room_name = sanitize($_POST['room_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $max_guests = intval($_POST['max_guests'] ?? 2);
    $max_children = intval($_POST['max_children'] ?? 0);
    $num_rooms_available = intval($_POST['num_rooms_available'] ?? 1);
    $base_price = floatval($_POST['base_price'] ?? 0);
    $size_sqm = intval($_POST['size_sqm'] ?? 0);
    $bed_configuration = sanitize($_POST['bed_configuration'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $room_amenities = isset($_POST['room_amenities']) ? json_encode($_POST['room_amenities']) : '[]';
    
    if ($action === 'add_room') {
        $stmt = $db->prepare("
            INSERT INTO stay_rooms (stay_id, room_name, description, max_guests, max_children, 
            num_rooms_available, base_price, size_sqm, bed_configuration, room_amenities, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $result = $stmt->execute([$stayId, $room_name, $description, $max_guests, $max_children, 
            $num_rooms_available, $base_price, $size_sqm, $bed_configuration, $room_amenities, $is_active]);
        
        if ($result) {
            $_SESSION['success'] = "Room added successfully";
        }
    } elseif ($action === 'edit_room' && $roomId > 0) {
        $stmt = $db->prepare("
            UPDATE stay_rooms SET 
                room_name = ?, description = ?, max_guests = ?, max_children = ?,
                num_rooms_available = ?, base_price = ?, size_sqm = ?, bed_configuration = ?,
                room_amenities = ?, is_active = ?, updated_at = NOW()
            WHERE room_id = ? AND stay_id = ?
        ");
        $result = $stmt->execute([$room_name, $description, $max_guests, $max_children,
            $num_rooms_available, $base_price, $size_sqm, $bed_configuration,
            $room_amenities, $is_active, $roomId, $stayId]);
        
        if ($result) {
            $_SESSION['success'] = "Room updated successfully";
        }
    }
    
    header("Location: rooms.php?stay_id=$stayId");
    exit;
}

// Delete Room
if ($action === 'delete_room' && isset($_GET['room_id'])) {
    $roomId = intval($_GET['room_id']);
    
    // Check if room has bookings
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE stay_room_id = ? AND status IN ('confirmed', 'completed')");
    $stmt->execute([$roomId]);
    $hasBookings = $stmt->fetchColumn() > 0;
    
    if ($hasBookings) {
        $_SESSION['error'] = "Cannot delete room with existing bookings";
    } else {
        $stmt = $db->prepare("DELETE FROM stay_rooms WHERE room_id = ? AND stay_id = ?");
        $stmt->execute([$roomId, $stayId]);
        $_SESSION['success'] = "Room deleted successfully";
    }
    
    header("Location: rooms.php?stay_id=$stayId");
    exit;
}

// Update availability
if ($action === 'update_availability' && isset($_POST['availability'])) {
    $availabilityData = $_POST['availability'];
    $updated = 0;
    
    foreach ($availabilityData as $roomId => $dates) {
        foreach ($dates as $date => $data) {
            $rooms_available = intval($data['rooms_available'] ?? 0);
            $price_override = !empty($data['price_override']) ? floatval($data['price_override']) : null;
            $is_blocked = isset($data['is_blocked']) ? 1 : 0;
            $notes = sanitize($data['notes'] ?? '');
            
            $stmt = $db->prepare("
                INSERT INTO stay_availability (room_id, date, rooms_available, price_override, is_blocked, notes)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    rooms_available = VALUES(rooms_available),
                    price_override = VALUES(price_override),
                    is_blocked = VALUES(is_blocked),
                    notes = VALUES(notes)
            ");
            if ($stmt->execute([$roomId, $date, $rooms_available, $price_override, $is_blocked, $notes])) {
                $updated++;
            }
        }
    }
    
    $_SESSION['success'] = "$updated availability records updated";
    header("Location: rooms.php?stay_id=$stayId");
    exit;
}

// Get all rooms
$stmt = $db->prepare("
    SELECT r.*,
        (SELECT COUNT(*) FROM bookings WHERE stay_room_id = r.room_id AND status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE stay_room_id = r.room_id AND status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT COUNT(*) FROM stay_availability WHERE room_id = r.room_id AND date >= CURDATE() AND rooms_available = 0) as blocked_days
    FROM stay_rooms r
    WHERE r.stay_id = ?
    ORDER BY r.room_name
");
$stmt->execute([$stayId]);
$rooms = $stmt->fetchAll();

// Get all room amenities
$stmt = $db->query("SELECT amenity_key, amenity_name, amenity_icon FROM amenities WHERE category = 'room' AND is_active = 1 ORDER BY amenity_name");
$allRoomAmenities = $stmt->fetchAll();

// Get availability for next 30 days (for quick view)
$availabilityData = [];
if (!empty($rooms)) {
    $roomIds = array_column($rooms, 'room_id');
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $stmt = $db->prepare("
        SELECT room_id, date, rooms_available, price_override, is_blocked, notes
        FROM stay_availability
        WHERE room_id IN ($placeholders) AND date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY date ASC
    ");
    $stmt->execute($roomIds);
    $availabilities = $stmt->fetchAll();
    
    foreach ($availabilities as $avail) {
        $availabilityData[$avail['room_id']][$avail['date']] = $avail;
    }
}
?>

<style>
/* Rooms Management Styles */
.rooms-header {
    margin-bottom: 24px;
}

.stay-info-bar {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.stay-info h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.stay-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Cards */
.stats-grid-mini {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-mini {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px 16px;
    text-align: center;
}

.stat-mini-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-mini-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 4px;
}

/* Rooms Grid */
.rooms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.room-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
}

.room-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.room-card-header {
    padding: 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.room-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.room-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.room-status.active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.room-status.inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

.room-card-body {
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
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.detail-item i {
    font-size: 0.875rem;
    color: var(--booking-blue);
    width: 20px;
}

.detail-item strong {
    color: var(--booking-text);
    font-weight: 600;
}

.room-amenities {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.amenity-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: var(--booking-gray-light);
    border-radius: 12px;
    font-size: 0.5625rem;
}

.room-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
}

.stat {
    text-align: center;
}

.stat-number {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-text {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.room-actions {
    display: flex;
    gap: 8px;
}

.btn-sm {
    flex: 1;
    padding: 8px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.btn-sm.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.btn-sm.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.btn-sm.danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
}

.btn-sm:hover {
    transform: translateY(-1px);
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: var(--booking-white);
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--booking-text-light);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    position: sticky;
    bottom: 0;
    background: var(--booking-white);
}

/* Availability Calendar */
.calendar-container {
    overflow-x: auto;
}

.availability-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.6875rem;
}

.availability-table th {
    padding: 10px;
    text-align: center;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    font-weight: 600;
}

.availability-table td {
    padding: 8px;
    text-align: center;
    border: 1px solid var(--booking-border);
}

.availability-input {
    width: 60px;
    padding: 4px;
    text-align: center;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
}

.availability-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.blocked-cell {
    background: #fce8e8;
}

/* Responsive */
@media (max-width: 768px) {
    .rooms-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid-mini {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .modal-container {
        width: 95%;
        margin: 10px;
    }
}
</style>

<div class="rooms-header">
    <a href="stay-detail.php?id=<?php echo $stayId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Stay Details
    </a>
</div>

<div class="stay-info-bar">
    <div class="stay-info">
        <h2><?php echo sanitize($stay['stay_name']); ?></h2>
        <p><i class="bi bi-door-open"></i> Rooms & Suites Management</p>
    </div>
    <button class="btn-sm primary" onclick="openRoomModal()" style="padding: 10px 20px;">
        <i class="bi bi-plus-lg"></i> Add New Room
    </button>
</div>

<!-- Stats -->
<div class="stats-grid-mini">
    <div class="stat-mini">
        <div class="stat-mini-value"><?php echo count($rooms); ?></div>
        <div class="stat-mini-label">Total Rooms</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-value">
            <?php 
            $activeRooms = count(array_filter($rooms, function($r) { return $r['is_active']; }));
            echo $activeRooms;
            ?>
        </div>
        <div class="stat-mini-label">Active Rooms</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-value">
            <?php 
            $totalCapacity = array_sum(array_column($rooms, 'max_guests'));
            echo $totalCapacity;
            ?>
        </div>
        <div class="stat-mini-label">Total Capacity</div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-value">
            <?php 
            $totalBookings = array_sum(array_column($rooms, 'total_bookings'));
            echo number_format($totalBookings);
            ?>
        </div>
        <div class="stat-mini-label">Total Bookings</div>
    </div>
</div>

<!-- Rooms Grid -->
<div class="rooms-grid">
    <?php foreach ($rooms as $room): ?>
    <div class="room-card">
        <div class="room-card-header">
            <span class="room-name"><?php echo sanitize($room['room_name']); ?></span>
            <span class="room-status <?php echo $room['is_active'] ? 'active' : 'inactive'; ?>">
                <i class="bi bi-<?php echo $room['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                <?php echo $room['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        
        <div class="room-card-body">
            <div class="room-details">
                <div class="detail-item">
                    <i class="bi bi-people"></i>
                    <span><strong><?php echo $room['max_guests']; ?></strong> guests max</span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-cash-stack"></i>
                    <span><strong><?php echo formatPrice($room['base_price']); ?></strong> / night</span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-door-closed"></i>
                    <span><strong><?php echo $room['num_rooms_available']; ?></strong> available</span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-rulers"></i>
                    <span><strong><?php echo $room['size_sqm'] ?: '—'; ?></strong> m²</span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-bed"></i>
                    <span><?php echo sanitize($room['bed_configuration'] ?: 'No bed info'); ?></span>
                </div>
                <div class="detail-item">
                    <i class="bi bi-calendar-check"></i>
                    <span><strong><?php echo number_format($room['total_bookings']); ?></strong> bookings</span>
                </div>
            </div>
            
            <?php if ($room['description']): ?>
            <div class="detail-item" style="margin-bottom: 12px;">
                <i class="bi bi-info-circle"></i>
                <span style="font-size: 0.625rem;"><?php echo sanitize(substr($room['description'], 0, 80)); ?></span>
            </div>
            <?php endif; ?>
            
            <?php 
            $roomAmenities = $room['room_amenities'] ? json_decode($room['room_amenities'], true) : [];
            if (!empty($roomAmenities)):
            ?>
            <div class="room-amenities">
                <?php foreach ($roomAmenities as $amenityKey): ?>
                <?php 
                $amenity = array_filter($allRoomAmenities, function($a) use ($amenityKey) {
                    return $a['amenity_key'] == $amenityKey;
                });
                $amenity = reset($amenity);
                if ($amenity):
                ?>
                <span class="amenity-tag">
                    <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                    <?php echo $amenity['amenity_name']; ?>
                </span>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="room-stats">
                <div class="stat">
                    <div class="stat-number"><?php echo formatPrice($room['total_revenue']); ?></div>
                    <div class="stat-text">Total Revenue</div>
                </div>
                <div class="stat">
                    <div class="stat-number"><?php echo $room['blocked_days']; ?></div>
                    <div class="stat-text">Blocked Days</div>
                </div>
            </div>
            
            <div class="room-actions">
                <button class="btn-sm secondary" onclick="openAvailabilityModal(<?php echo $room['room_id']; ?>, '<?php echo addslashes($room['room_name']); ?>')">
                    <i class="bi bi-calendar3"></i> Availability
                </button>
                <button class="btn-sm primary" onclick="openEditRoomModal(<?php echo htmlspecialchars(json_encode($room)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn-sm danger" onclick="deleteRoom(<?php echo $room['room_id']; ?>, '<?php echo addslashes($room['room_name']); ?>')">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add/Edit Room Modal -->
<div id="roomModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="rooms.php?stay_id=<?php echo $stayId; ?>" id="roomForm">
            <input type="hidden" name="action" id="roomAction" value="add_room">
            <input type="hidden" name="room_id" id="roomId" value="0">
            
            <div class="modal-header">
                <h3 id="modalTitle">Add New Room</h3>
                <button type="button" class="modal-close" onclick="closeRoomModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Room Name</label>
                    <input type="text" name="room_name" id="room_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Guests</label>
                        <input type="number" name="max_guests" id="max_guests" class="form-control" value="2" min="1">
                    </div>
                    <div class="form-group">
                        <label>Max Children</label>
                        <input type="number" name="max_children" id="max_children" class="form-control" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Number of Rooms Available</label>
                        <input type="number" name="num_rooms_available" id="num_rooms_available" class="form-control" value="1" min="0">
                    </div>
                    <div class="form-group">
                        <label>Base Price (RWF)</label>
                        <input type="number" name="base_price" id="base_price" class="form-control" value="0" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Room Size (m²)</label>
                        <input type="number" name="size_sqm" id="size_sqm" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Bed Configuration</label>
                        <input type="text" name="bed_configuration" id="bed_configuration" class="form-control" placeholder="e.g., 1 Queen Bed">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Room Amenities</label>
                    <div class="amenities-grid" style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($allRoomAmenities as $amenity): ?>
                        <label class="amenity-checkbox">
                            <input type="checkbox" name="room_amenities[]" value="<?php echo $amenity['amenity_key']; ?>" class="room-amenity-checkbox">
                            <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                            <span><?php echo htmlspecialchars($amenity['amenity_name']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                    <label for="is_active">Active (Available for booking)</label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeRoomModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Room</button>
            </div>
        </form>
    </div>
</div>

<!-- Availability Modal -->
<div id="availabilityModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 90%; width: auto;">
        <form method="POST" action="rooms.php?stay_id=<?php echo $stayId; ?>">
            <input type="hidden" name="action" value="update_availability">
            <input type="hidden" name="room_id" id="availRoomId" value="">
            
            <div class="modal-header">
                <h3 id="availModalTitle">Availability Calendar</h3>
                <button type="button" class="modal-close" onclick="closeAvailabilityModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="calendar-container">
                    <table class="availability-table" id="availabilityTable">
                        <thead>
                            <tr id="calendarHeaders"></tr>
                        </thead>
                        <tbody id="calendarBody"></tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeAvailabilityModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Availability</button>
            </div>
        </form>
    </div>
</div>

<script>
// Room Modal
function openRoomModal() {
    document.getElementById('modalTitle').innerText = 'Add New Room';
    document.getElementById('roomAction').value = 'add_room';
    document.getElementById('roomId').value = '0';
    document.getElementById('room_name').value = '';
    document.getElementById('description').value = '';
    document.getElementById('max_guests').value = '2';
    document.getElementById('max_children').value = '0';
    document.getElementById('num_rooms_available').value = '1';
    document.getElementById('base_price').value = '';
    document.getElementById('size_sqm').value = '';
    document.getElementById('bed_configuration').value = '';
    document.querySelectorAll('.room-amenity-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('is_active').checked = true;
    document.getElementById('roomModal').style.display = 'flex';
}

function openEditRoomModal(room) {
    document.getElementById('modalTitle').innerText = 'Edit Room';
    document.getElementById('roomAction').value = 'edit_room';
    document.getElementById('roomId').value = room.room_id;
    document.getElementById('room_name').value = room.room_name;
    document.getElementById('description').value = room.description || '';
    document.getElementById('max_guests').value = room.max_guests;
    document.getElementById('max_children').value = room.max_children;
    document.getElementById('num_rooms_available').value = room.num_rooms_available;
    document.getElementById('base_price').value = room.base_price;
    document.getElementById('size_sqm').value = room.size_sqm || '';
    document.getElementById('bed_configuration').value = room.bed_configuration || '';
    
    // Set amenities
    const amenities = room.room_amenities ? JSON.parse(room.room_amenities) : [];
    document.querySelectorAll('.room-amenity-checkbox').forEach(cb => {
        cb.checked = amenities.includes(cb.value);
    });
    
    document.getElementById('is_active').checked = room.is_active == 1;
    document.getElementById('roomModal').style.display = 'flex';
}

function closeRoomModal() {
    document.getElementById('roomModal').style.display = 'none';
}

// Availability Modal
function openAvailabilityModal(roomId, roomName) {
    document.getElementById('availRoomId').value = roomId;
    document.getElementById('availModalTitle').innerText = `Availability - ${roomName}`;
    
    // Generate next 30 days calendar
    const headers = document.getElementById('calendarHeaders');
    const body = document.getElementById('calendarBody');
    
    const dates = [];
    const today = new Date();
    for (let i = 0; i < 30; i++) {
        const date = new Date(today);
        date.setDate(today.getDate() + i);
        dates.push(date);
    }
    
    // Create headers
    headers.innerHTML = '<th>Date</th><th>Day</th><th>Available Rooms</th><th>Price Override (RWF)</th><th>Blocked</th><th>Notes</th>';
    
    // Get existing availability data
    const availabilityData = <?php echo json_encode($availabilityData); ?>;
    const roomAvail = availabilityData[roomId] || {};
    
    // Create rows
    body.innerHTML = '';
    dates.forEach(date => {
        const dateStr = date.toISOString().split('T')[0];
        const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const dayName = dayNames[date.getDay()];
        const existing = roomAvail[dateStr] || {};
        
        const row = body.insertRow();
        row.innerHTML = `
            <td>${dateStr}</td>
            <td>${dayName}</td>
            <td>
                <input type="number" name="availability[${roomId}][${dateStr}][rooms_available]" 
                       class="availability-input" value="${existing.rooms_available || 1}" min="0">
            </td>
            <td>
                <input type="number" name="availability[${roomId}][${dateStr}][price_override]" 
                       class="availability-input" value="${existing.price_override || ''}" step="1000">
            </td>
            <td style="text-align: center;">
                <input type="checkbox" name="availability[${roomId}][${dateStr}][is_blocked]" 
                       class="availability-checkbox" value="1" ${existing.is_blocked ? 'checked' : ''}>
            </td>
            <td>
                <input type="text" name="availability[${roomId}][${dateStr}][notes]" 
                       style="width: 100%; padding: 4px; font-size: 0.625rem;" value="${existing.notes || ''}">
            </td>
        `;
        
        if (existing.is_blocked) {
            row.classList.add('blocked-cell');
        }
        
        // Add event listener for blocked checkbox
        const blockedCheckbox = row.querySelector('.availability-checkbox');
        blockedCheckbox.addEventListener('change', function() {
            if (this.checked) {
                row.classList.add('blocked-cell');
            } else {
                row.classList.remove('blocked-cell');
            }
        });
    });
    
    document.getElementById('availabilityModal').style.display = 'flex';
}

function closeAvailabilityModal() {
    document.getElementById('availabilityModal').style.display = 'none';
}

// Delete Room
function deleteRoom(roomId, roomName) {
    if (confirm(`Are you sure you want to delete "${roomName}"? This action cannot be undone.`)) {
        window.location.href = `rooms.php?stay_id=<?php echo $stayId; ?>&action=delete_room&room_id=${roomId}`;
    }
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRoomModal();
        closeAvailabilityModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const roomModal = document.getElementById('roomModal');
    const availModal = document.getElementById('availabilityModal');
    if (e.target === roomModal) closeRoomModal();
    if (e.target === availModal) closeAvailabilityModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>