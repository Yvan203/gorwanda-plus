<?php
$attractionId = isset($_GET['attraction_id']) ? intval($_GET['attraction_id']) : 0;

if (!$attractionId) {
    header('Location: attractions.php');
    exit;
}

$pageTitle = 'Pricing Tiers Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Get attraction details
$stmt = $db->prepare("SELECT attraction_name, slug FROM attractions WHERE attraction_id = ?");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: attractions.php');
    exit;
}

// Handle tier actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$tierId = isset($_POST['tier_id']) ? intval($_POST['tier_id']) : (isset($_GET['tier_id']) ? intval($_GET['tier_id']) : 0);

// Add/Edit Tier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'add_tier' || $action === 'edit_tier')) {
    $tier_name = sanitize($_POST['tier_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
    $base_price = floatval($_POST['base_price'] ?? 0);
    $price_type = sanitize($_POST['price_type'] ?? 'per_person');
    $inclusions = isset($_POST['inclusions']) ? json_encode($_POST['inclusions']) : '[]';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validation
    $errors = [];
    if (empty($tier_name)) {
        $errors[] = "Tier name is required";
    }
    if ($base_price <= 0) {
        $errors[] = "Base price must be greater than 0";
    }
    
    if (empty($errors)) {
        if ($action === 'add_tier') {
            $stmt = $db->prepare("
                INSERT INTO attraction_tiers (
                    attraction_id, tier_name, description, max_participants,
                    base_price, price_type, inclusions, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $attractionId, $tier_name, $description, $max_participants,
                $base_price, $price_type, $inclusions, $is_active
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Pricing tier added successfully";
            }
        } elseif ($action === 'edit_tier' && $tierId > 0) {
            $stmt = $db->prepare("
                UPDATE attraction_tiers SET
                    tier_name = ?,
                    description = ?,
                    max_participants = ?,
                    base_price = ?,
                    price_type = ?,
                    inclusions = ?,
                    is_active = ?
                WHERE tier_id = ? AND attraction_id = ?
            ");
            $result = $stmt->execute([
                $tier_name, $description, $max_participants,
                $base_price, $price_type, $inclusions, $is_active,
                $tierId, $attractionId
            ]);
            
            if ($result) {
                $_SESSION['success'] = "Pricing tier updated successfully";
            }
        }
        
        header("Location: tiers.php?attraction_id=$attractionId");
        exit;
    }
}

// Delete Tier
if ($action === 'delete_tier' && $tierId > 0) {
    // Check if tier has bookings
    $stmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE attraction_tier_id = ? AND status IN ('confirmed', 'completed')");
    $stmt->execute([$tierId]);
    $hasBookings = $stmt->fetchColumn() > 0;
    
    if ($hasBookings) {
        $_SESSION['error'] = "Cannot delete tier with existing bookings";
    } else {
        // Delete associated availability records first
        $stmt = $db->prepare("DELETE FROM attraction_availability WHERE tier_id = ?");
        $stmt->execute([$tierId]);
        
        // Delete tier
        $stmt = $db->prepare("DELETE FROM attraction_tiers WHERE tier_id = ? AND attraction_id = ?");
        $stmt->execute([$tierId, $attractionId]);
        $_SESSION['success'] = "Pricing tier deleted successfully";
    }
    
    header("Location: tiers.php?attraction_id=$attractionId");
    exit;
}

// Update availability
if ($action === 'update_availability' && isset($_POST['availability'])) {
    $availabilityData = $_POST['availability'];
    $updated = 0;
    
    foreach ($availabilityData as $tierId => $dates) {
        foreach ($dates as $date => $data) {
            $max_bookings = intval($data['max_bookings'] ?? 10);
            $price_override = !empty($data['price_override']) ? floatval($data['price_override']) : null;
            $is_blocked = isset($data['is_blocked']) ? 1 : 0;
            $notes = sanitize($data['notes'] ?? '');
            
            $stmt = $db->prepare("
                INSERT INTO attraction_availability (tier_id, date, max_bookings, price_override, is_blocked, notes)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    max_bookings = VALUES(max_bookings),
                    price_override = VALUES(price_override),
                    is_blocked = VALUES(is_blocked),
                    notes = VALUES(notes)
            ");
            if ($stmt->execute([$tierId, $date, $max_bookings, $price_override, $is_blocked, $notes])) {
                $updated++;
            }
        }
    }
    
    $_SESSION['success'] = "$updated availability records updated";
    header("Location: tiers.php?attraction_id=$attractionId");
    exit;
}

// Get all tiers
$stmt = $db->prepare("
    SELECT 
        t.*,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.attraction_tier_id = t.tier_id AND b.status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         WHERE b.attraction_tier_id = t.tier_id AND b.status IN ('confirmed', 'completed')) as tier_revenue
    FROM attraction_tiers t
    WHERE t.attraction_id = ?
    ORDER BY t.base_price ASC
");
$stmt->execute([$attractionId]);
$tiers = $stmt->fetchAll();

// Get availability for next 30 days for all tiers
$availabilityData = [];
if (!empty($tiers)) {
    $tierIds = array_column($tiers, 'tier_id');
    $placeholders = implode(',', array_fill(0, count($tierIds), '?'));
    $stmt = $db->prepare("
        SELECT tier_id, date, max_bookings, bookings_made, price_override, is_blocked, notes
        FROM attraction_availability
        WHERE tier_id IN ($placeholders) AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY date ASC
    ");
    $stmt->execute($tierIds);
    $availabilities = $stmt->fetchAll();
    
    foreach ($availabilities as $avail) {
        $availabilityData[$avail['tier_id']][$avail['date']] = $avail;
    }
}

// Price types with labels
$priceTypes = [
    'per_person' => 'Per Person',
    'per_group' => 'Per Group (up to max participants)',
    'per_couple' => 'Per Couple'
];

// Common inclusion items
$inclusionItems = [
    'guide' => 'Professional Guide',
    'park_fees' => 'Park Entrance Fees',
    'transport' => 'Transportation',
    'lunch' => 'Lunch',
    'water' => 'Bottled Water',
    'equipment' => 'Equipment',
    'snacks' => 'Snacks',
    'drinks' => 'Drinks',
    'insurance' => 'Insurance',
    'photos' => 'Free Photos',
    'souvenir' => 'Souvenir',
    'pickup' => 'Hotel Pickup',
    'dropoff' => 'Hotel Dropoff'
];

// Get tier statistics
$totalTiers = count($tiers);
$activeTiers = count(array_filter($tiers, function($t) { return $t['is_active']; }));
$totalBookings = array_sum(array_column($tiers, 'booking_count'));
$totalRevenue = array_sum(array_column($tiers, 'tier_revenue'));
$minPrice = !empty($tiers) ? min(array_column($tiers, 'base_price')) : 0;
$maxPrice = !empty($tiers) ? max(array_column($tiers, 'base_price')) : 0;
?>

<style>
/* Tiers Management Styles */
.tiers-header {
    margin-bottom: 24px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--booking-blue);
    text-decoration: none;
    font-size: 0.75rem;
    margin-bottom: 16px;
}

.back-link:hover {
    text-decoration: underline;
}

.attraction-info-bar {
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

.attraction-info h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.attraction-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Cards */
.tiers-stats {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    text-align: center;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 4px;
}

/* Tiers Grid */
.tiers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.tier-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
}

.tier-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.tier-card-header {
    padding: 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tier-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.tier-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.tier-status.active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.tier-status.inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

.tier-card-body {
    padding: 16px;
}

.price-info {
    text-align: center;
    padding: 16px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
}

.price-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-success);
}

.price-type {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.tier-details {
    margin-bottom: 16px;
}

.detail-row {
    display: flex;
    padding: 8px 0;
    border-bottom: 1px solid var(--booking-border);
}

.detail-label {
    width: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
}

.detail-value {
    flex: 1;
    font-size: 0.6875rem;
    color: var(--booking-text);
}

.inclusions-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}

.inclusion-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    background: var(--booking-gray-light);
    border-radius: 12px;
    font-size: 0.5625rem;
}

.performance-stats {
    display: flex;
    justify-content: space-between;
    padding: 12px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 16px;
}

.performance-item {
    text-align: center;
}

.performance-value {
    font-weight: 700;
    font-size: 0.75rem;
}

.performance-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.tier-actions {
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

.modal-close:hover {
    color: var(--booking-danger);
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
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    background: var(--booking-white);
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.form-check input {
    width: 16px;
    height: 16px;
    cursor: pointer;
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
    width: 70px;
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

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid rgba(0,128,9,0.2);
}

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    grid-column: 1 / -1;
}

/* Responsive */
@media (max-width: 1200px) {
    .tiers-stats {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .tiers-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .tiers-grid {
        grid-template-columns: 1fr;
    }
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="tiers-header">
    <a href="attraction-detail.php?id=<?php echo $attractionId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Experience Details
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <div>
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<div class="attraction-info-bar">
    <div class="attraction-info">
        <h2><?php echo sanitize($attraction['attraction_name']); ?></h2>
        <p><i class="bi bi-layers"></i> Pricing Tiers Management</p>
    </div>
    <button class="btn-sm primary" onclick="openTierModal()" style="padding: 10px 20px;">
        <i class="bi bi-plus-lg"></i> Add Pricing Tier
    </button>
</div>

<!-- Statistics -->
<div class="tiers-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalTiers; ?></div>
        <div class="stat-label">Total Tiers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $activeTiers; ?></div>
        <div class="stat-label">Active Tiers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($totalBookings); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($totalRevenue); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($minPrice); ?></div>
        <div class="stat-label">Min Price</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($maxPrice); ?></div>
        <div class="stat-label">Max Price</div>
    </div>
</div>

<!-- Tiers Grid -->
<div class="tiers-grid">
    <?php if (empty($tiers)): ?>
    <div class="empty-state">
        <i class="bi bi-layers" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No pricing tiers added yet</p>
        <button class="btn-sm primary" onclick="openTierModal()" style="margin-top: 16px;">
            <i class="bi bi-plus-lg"></i> Add First Pricing Tier
        </button>
    </div>
    <?php else: ?>
    <?php foreach ($tiers as $tier): 
        $tierInclusions = $tier['inclusions'] ? json_decode($tier['inclusions'], true) : [];
    ?>
    <div class="tier-card">
        <div class="tier-card-header">
            <span class="tier-name"><?php echo sanitize($tier['tier_name']); ?></span>
            <span class="tier-status <?php echo $tier['is_active'] ? 'active' : 'inactive'; ?>">
                <i class="bi bi-<?php echo $tier['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                <?php echo $tier['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        
        <div class="tier-card-body">
            <div class="price-info">
                <div class="price-amount"><?php echo formatPrice($tier['base_price']); ?></div>
                <div class="price-type"><?php echo $priceTypes[$tier['price_type']]; ?></div>
            </div>
            
            <div class="tier-details">
                <?php if ($tier['max_participants']): ?>
                <div class="detail-row">
                    <div class="detail-label">Max Participants</div>
                    <div class="detail-value"><?php echo $tier['max_participants']; ?> people</div>
                </div>
                <?php endif; ?>
                
                <?php if ($tier['description']): ?>
                <div class="detail-row">
                    <div class="detail-label">Description</div>
                    <div class="detail-value"><?php echo sanitize(substr($tier['description'], 0, 100)); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($tierInclusions)): ?>
                <div class="detail-row">
                    <div class="detail-label">Inclusions</div>
                    <div class="detail-value">
                        <div class="inclusions-list">
                            <?php foreach ($tierInclusions as $item): ?>
                            <span class="inclusion-badge">
                                <i class="bi bi-check-lg"></i>
                                <?php echo $inclusionItems[$item] ?? ucfirst(str_replace('_', ' ', $item)); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="performance-stats">
                <div class="performance-item">
                    <div class="performance-value"><?php echo number_format($tier['booking_count']); ?></div>
                    <div class="performance-label">Bookings</div>
                </div>
                <div class="performance-item">
                    <div class="performance-value"><?php echo formatPrice($tier['tier_revenue']); ?></div>
                    <div class="performance-label">Revenue</div>
                </div>
                <div class="performance-item">
                    <div class="performance-value"><?php echo $tier['booking_count'] > 0 ? number_format($tier['tier_revenue'] / $tier['booking_count'], 0) : 0; ?> RWF</div>
                    <div class="performance-label">Avg. Booking</div>
                </div>
            </div>
            
            <div class="tier-actions">
                <button class="btn-sm secondary" onclick="openAvailabilityModal(<?php echo $tier['tier_id']; ?>, '<?php echo addslashes($tier['tier_name']); ?>')">
                    <i class="bi bi-calendar3"></i> Availability
                </button>
                <button class="btn-sm primary" onclick="editTier(<?php echo htmlspecialchars(json_encode($tier)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn-sm danger" onclick="deleteTier(<?php echo $tier['tier_id']; ?>, '<?php echo addslashes($tier['tier_name']); ?>')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add/Edit Tier Modal -->
<div id="tierModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="tiers.php?attraction_id=<?php echo $attractionId; ?>" id="tierForm">
            <input type="hidden" name="action" id="tierAction" value="add_tier">
            <input type="hidden" name="tier_id" id="tierId" value="0">
            
            <div class="modal-header">
                <h3 id="modalTitle">Add Pricing Tier</h3>
                <button type="button" class="modal-close" onclick="closeTierModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Tier Name</label>
                    <input type="text" name="tier_name" id="tier_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Base Price (RWF)</label>
                        <input type="number" name="base_price" id="base_price" class="form-control" step="1000" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Price Type</label>
                        <select name="price_type" id="price_type" class="form-control">
                            <?php foreach ($priceTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Max Participants</label>
                        <input type="number" name="max_participants" id="max_participants" class="form-control" min="1" placeholder="Unlimited if empty">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Inclusions</label>
                    <div class="inclusions-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-top: 8px;">
                        <?php foreach ($inclusionItems as $key => $label): ?>
                        <label class="inclusion-checkbox" style="display: flex; align-items: center; gap: 6px; font-size: 0.6875rem;">
                            <input type="checkbox" name="inclusions[]" value="<?php echo $key; ?>" class="inclusion-checkbox-input">
                            <span><?php echo $label; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="tier_is_active" value="1" checked>
                    <label for="tier_is_active">Active (Available for booking)</label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeTierModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Tier</button>
            </div>
        </form>
    </div>
</div>

<!-- Availability Modal -->
<div id="availabilityModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 90%; width: auto;">
        <form method="POST" action="tiers.php?attraction_id=<?php echo $attractionId; ?>">
            <input type="hidden" name="action" value="update_availability">
            <input type="hidden" name="tier_id" id="availTierId" value="">
            
            <div class="modal-header">
                <h3 id="availModalTitle">Availability Calendar</h3>
                <button type="button" class="modal-close" onclick="closeAvailabilityModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="calendar-container">
                    <table class="availability-table" id="availabilityTable">
                        <thead>
                            <tr id="calendarHeaders"> </tr>
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
// Tier Modal
function openTierModal() {
    document.getElementById('modalTitle').innerText = 'Add Pricing Tier';
    document.getElementById('tierAction').value = 'add_tier';
    document.getElementById('tierId').value = '0';
    document.getElementById('tierForm').reset();
    document.querySelectorAll('.inclusion-checkbox-input').forEach(cb => cb.checked = false);
    document.getElementById('tier_is_active').checked = true;
    document.getElementById('tierModal').style.display = 'flex';
}

function editTier(tier) {
    document.getElementById('modalTitle').innerText = 'Edit Pricing Tier';
    document.getElementById('tierAction').value = 'edit_tier';
    document.getElementById('tierId').value = tier.tier_id;
    document.getElementById('tier_name').value = tier.tier_name;
    document.getElementById('description').value = tier.description || '';
    document.getElementById('base_price').value = tier.base_price;
    document.getElementById('price_type').value = tier.price_type;
    document.getElementById('max_participants').value = tier.max_participants || '';
    
    // Set inclusions
    const inclusions = tier.inclusions ? JSON.parse(tier.inclusions) : [];
    document.querySelectorAll('.inclusion-checkbox-input').forEach(cb => {
        cb.checked = inclusions.includes(cb.value);
    });
    
    document.getElementById('tier_is_active').checked = tier.is_active == 1;
    document.getElementById('tierModal').style.display = 'flex';
}

function closeTierModal() {
    document.getElementById('tierModal').style.display = 'none';
}

function deleteTier(tierId, tierName) {
    if (confirm(`Are you sure you want to delete "${tierName}"? This will also remove all availability settings for this tier.`)) {
        window.location.href = `tiers.php?attraction_id=<?php echo $attractionId; ?>&action=delete_tier&tier_id=${tierId}`;
    }
}

// Availability Modal
function openAvailabilityModal(tierId, tierName) {
    document.getElementById('availTierId').value = tierId;
    document.getElementById('availModalTitle').innerText = `Availability - ${tierName}`;
    
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
    headers.innerHTML = '<th>Date</th><th>Day</th><th>Max Bookings</th><th>Price Override (RWF)</th><th>Blocked</th><th>Notes</th>';
    
    // Get existing availability data
    const availabilityData = <?php echo json_encode($availabilityData); ?>;
    const tierAvail = availabilityData[tierId] || {};
    
    // Create rows
    body.innerHTML = '';
    dates.forEach(date => {
        const dateStr = date.toISOString().split('T')[0];
        const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const dayName = dayNames[date.getDay()];
        const existing = tierAvail[dateStr] || {};
        
        const row = body.insertRow();
        row.innerHTML = `
            <td style="padding: 8px;">${dateStr}</td>
            <td style="padding: 8px; text-align: center;">${dayName}</td>
            <td style="padding: 8px;">
                <input type="number" name="availability[${tierId}][${dateStr}][max_bookings]" 
                       class="availability-input" value="${existing.max_bookings || 10}" min="0">
                ${existing.bookings_made ? `<div style="font-size: 0.5625rem; margin-top: 2px;">Booked: ${existing.bookings_made}</div>` : ''}
            </td>
            <td style="padding: 8px;">
                <input type="number" name="availability[${tierId}][${dateStr}][price_override]" 
                       class="availability-input" value="${existing.price_override || ''}" step="1000" placeholder="Default">
            </td>
            <td style="padding: 8px; text-align: center;">
                <input type="checkbox" name="availability[${tierId}][${dateStr}][is_blocked]" 
                       class="availability-checkbox" value="1" ${existing.is_blocked ? 'checked' : ''}>
            </td>
            <td style="padding: 8px;">
                <input type="text" name="availability[${tierId}][${dateStr}][notes]" 
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

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTierModal();
        closeAvailabilityModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const tierModal = document.getElementById('tierModal');
    const availModal = document.getElementById('availabilityModal');
    if (e.target === tierModal) closeTierModal();
    if (e.target === availModal) closeAvailabilityModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>