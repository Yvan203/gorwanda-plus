<?php
$pageTitle = 'Manage Pricing Tiers';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameter
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;

// ============================================
// HANDLE TIER ACTIONS
// ============================================

// Add/Edit Tier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tier'])) {
    $tierId = intval($_POST['tier_id'] ?? 0);
    $attractionId = intval($_POST['attraction_id']);
    $tierName = sanitize($_POST['tier_name']);
    $description = sanitize($_POST['description'] ?? '');
    $basePrice = floatval($_POST['base_price']);
    $priceType = sanitize($_POST['price_type']);
    $maxParticipants = intval($_POST['max_participants'] ?? 0);
    $inclusions = isset($_POST['inclusions']) ? json_encode($_POST['inclusions']) : '[]';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT a.attraction_id FROM attractions a
        WHERE a.attraction_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$attractionId, $userId]);
    
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this experience";
    } else {
        if ($tierId > 0) {
            // Update existing tier
            $stmt = $db->prepare("
                UPDATE attraction_tiers SET
                    tier_name = ?, description = ?, base_price = ?,
                    price_type = ?, max_participants = ?, inclusions = ?,
                    is_active = ?
                WHERE tier_id = ? AND attraction_id = ?
            ");
            $stmt->execute([
                $tierName, $description, $basePrice, $priceType,
                $maxParticipants, $inclusions, $isActive, $tierId, $attractionId
            ]);
            $message = "Tier updated successfully!";
        } else {
            // Insert new tier
            $stmt = $db->prepare("
                INSERT INTO attraction_tiers (
                    attraction_id, tier_name, description, base_price,
                    price_type, max_participants, inclusions, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $attractionId, $tierName, $description, $basePrice,
                $priceType, $maxParticipants, $inclusions, $isActive
            ]);
            $message = "Tier added successfully!";
        }
    }
}

// Delete Tier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tier'])) {
    $tierId = intval($_POST['tier_id']);
    $attractionId = intval($_POST['attraction_id']);
    
    // Verify ownership
    $stmt = $db->prepare("
        DELETE at FROM attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$tierId, $userId]);
    
    $message = "Tier deleted successfully!";
}

// Toggle Tier Status (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $tierId = intval($_POST['tier_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE attraction_tiers at
        JOIN attractions a ON at.attraction_id = a.attraction_id
        SET at.is_active = ?
        WHERE at.tier_id = ? AND a.owner_id = ?
    ");
    $stmt->execute([$newStatus, $tierId, $userId]);
    
    echo json_encode(['success' => true, 'new_status' => $newStatus]);
    exit;
}

// Bulk Update Prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    $attractionId = intval($_POST['attraction_id']);
    $action = $_POST['bulk_action'];
    $value = floatval($_POST['bulk_value']);
    $tierIds = isset($_POST['tier_ids']) ? json_decode($_POST['tier_ids'], true) : [];
    
    // Verify ownership
    $stmt = $db->prepare("SELECT attraction_id FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$attractionId, $userId]);
    
    if ($stmt->fetch() && !empty($tierIds) && is_array($tierIds)) {
        $placeholders = implode(',', array_fill(0, count($tierIds), '?'));
        $params = $tierIds;
        
        if ($action === 'increase_percent') {
            $stmt = $db->prepare("
                UPDATE attraction_tiers 
                SET base_price = base_price * (1 + ? / 100)
                WHERE tier_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        } elseif ($action === 'decrease_percent') {
            $stmt = $db->prepare("
                UPDATE attraction_tiers 
                SET base_price = base_price * (1 - ? / 100)
                WHERE tier_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        } elseif ($action === 'set_fixed') {
            $stmt = $db->prepare("
                UPDATE attraction_tiers 
                SET base_price = ?
                WHERE tier_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        }
        
        $stmt->execute($params);
        $message = "Bulk price update completed successfully!";
    }
}

// ============================================
// GET DATA
// ============================================

// Get all experiences for this partner
$stmt = $db->prepare("
    SELECT attraction_id, attraction_name 
    FROM attractions 
    WHERE owner_id = ? 
    ORDER BY attraction_name
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// If no experience selected, use the first one
if ($experienceId === 0 && !empty($experiences)) {
    $experienceId = $experiences[0]['attraction_id'];
}

// Get current experience details
$currentExperience = null;
$tiers = [];
$stats = [
    'total_tiers' => 0,
    'active_tiers' => 0,
    'min_price' => 0,
    'max_price' => 0,
    'avg_price' => 0
];

if ($experienceId > 0) {
    // Get experience name
    foreach ($experiences as $exp) {
        if ($exp['attraction_id'] == $experienceId) {
            $currentExperience = $exp;
            break;
        }
    }
    
    // Get tiers for this experience
    $stmt = $db->prepare("
        SELECT * FROM attraction_tiers 
        WHERE attraction_id = ? 
        ORDER BY base_price ASC
    ");
    $stmt->execute([$experienceId]);
    $tiers = $stmt->fetchAll();

    // Calculate stats - FIXED: ensure we're working with arrays
    $stats['total_tiers'] = count($tiers);
    $prices = [];
    foreach ($tiers as $tier) {
        if ($tier['is_active']) {
            $stats['active_tiers']++;
        }
        $prices[] = $tier['base_price'];
    }

    if (!empty($prices)) {
        $stats['min_price'] = min($prices);
        $stats['max_price'] = max($prices);
        $stats['avg_price'] = array_sum($prices) / count($prices);
    } else {
        $stats['min_price'] = 0;
        $stats['max_price'] = 0;
        $stats['avg_price'] = 0;
    }
} // <-- THIS CLOSING BRACE WAS MISSING!

// Price types
$priceTypes = [
    'per_person' => 'Per Person',
    'per_group' => 'Per Group',
    'per_couple' => 'Per Couple'
];

// Common inclusions (same as in add-listing.php)
$commonInclusions = [
    'guide' => 'Professional Guide',
    'park_fees' => 'Park Entrance Fees',
    'transport' => 'Transportation',
    'lunch' => 'Lunch',
    'water' => 'Bottled Water',
    'equipment' => 'Equipment Rental',
    'snacks' => 'Snacks',
    'drinks' => 'Drinks',
    'insurance' => 'Insurance',
    'photos' => 'Photos',
    'souvenir' => 'Souvenir',
    'pickup' => 'Hotel Pickup'
];
?>

<style>
/* Tiers Management Specific Styles */
.tiers-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.tiers-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.tiers-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 0;
}

/* Experience Selector */
.experience-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.experience-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--exp-text);
}

.experience-selector select {
    min-width: 350px;
    padding: 10px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
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
    border: 1px solid var(--exp-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
}

/* Action Bar */
.action-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.selected-count {
    background: var(--exp-light-purple);
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--exp-purple);
}

.bulk-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

/* Tiers Grid */
.tiers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.tier-card {
    background: white;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.tier-card:hover {
    box-shadow: var(--shadow-md);
}

.tier-card.inactive {
    opacity: 0.7;
    background: var(--exp-gray);
}

.tier-header {
    padding: 16px;
    background: linear-gradient(135deg, var(--exp-light-purple), white);
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tier-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-purple);
}

.tier-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.tier-badge.active {
    background: #e6f4ea;
    color: #10b981;
}

.tier-badge.inactive {
    background: var(--exp-gray);
    color: var(--exp-text-light);
}

.tier-body {
    padding: 16px;
}

.tier-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-success);
    margin-bottom: 8px;
}

.tier-price small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--exp-text-light);
}

.tier-price-type {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    margin-bottom: 12px;
}

.tier-description {
    font-size: 0.8125rem;
    color: var(--exp-text);
    margin-bottom: 12px;
    line-height: 1.5;
}

.tier-meta {
    display: flex;
    gap: 16px;
    margin: 12px 0;
    padding: 8px 0;
    border-top: 1px solid var(--exp-border);
    border-bottom: 1px solid var(--exp-border);
    font-size: 0.75rem;
    color: var(--exp-text-light);
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.meta-item i {
    color: var(--exp-purple);
}

.tier-inclusions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 12px 0;
}

.inclusion-tag {
    background: var(--exp-light-purple);
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    color: var(--exp-purple);
    border: 1px solid var(--exp-purple);
}

.tier-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.tier-action-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--exp-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.tier-action-btn:hover {
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.tier-action-btn.delete:hover {
    background: #fce8e8;
    border-color: var(--exp-danger);
    color: var(--exp-danger);
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
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
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
    color: var(--exp-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--exp-gray);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--exp-danger);
    color: white;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--exp-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--exp-gray);
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
    margin-bottom: 4px;
    color: var(--exp-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

/* Checkbox Grid */
.checkbox-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    padding: 12px;
    background: var(--exp-gray);
    border-radius: var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8125rem;
}

.checkbox-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--exp-purple);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--exp-text-light);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    color: var(--exp-text-light);
    margin-bottom: 20px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .tiers-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .experience-selector select {
        min-width: 100%;
    }
    
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .bulk-actions {
        flex-direction: column;
    }
}
</style>

<div class="tiers-header">
    <div class="tiers-title">
        <h1>Pricing Tiers</h1>
        <p>Manage pricing options for your experiences</p>
    </div>
    <?php if ($experienceId > 0): ?>
    <button class="btn-primary" onclick="openAddTierModal(<?php echo $experienceId; ?>)">
        <i class="bi bi-plus-lg"></i> Add New Tier
    </button>
    <?php endif; ?>
</div>

<!-- Experience Selector -->
<div class="experience-selector">
    <label for="experienceSelect"><i class="bi bi-ticket-perforated"></i> Select Experience:</label>
    <select id="experienceSelect" onchange="changeExperience(this.value)">
        <option value="">Choose an experience</option>
        <?php foreach ($experiences as $exp): ?>
        <option value="<?php echo $exp['attraction_id']; ?>" <?php echo $exp['attraction_id'] == $experienceId ? 'selected' : ''; ?>>
            <?php echo sanitize($exp['attraction_name']); ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($experienceId > 0 && $currentExperience): ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total_tiers']; ?></div>
        <div class="stat-label">Total Tiers</div>
        <div class="stat-footer"><?php echo $stats['active_tiers']; ?> active</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['min_price']); ?></div>
        <div class="stat-label">Minimum Price</div>
        <div class="stat-footer">Lowest tier</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['max_price']); ?></div>
        <div class="stat-label">Maximum Price</div>
        <div class="stat-footer">Highest tier</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_price']); ?></div>
        <div class="stat-label">Average Price</div>
        <div class="stat-footer">Across all tiers</div>
    </div>
</div>

<!-- Action Bar -->
<?php if (!empty($tiers)): ?>
<div class="action-bar">
    <div class="selected-count" id="selectedDisplay">0 tiers selected</div>
    <div class="bulk-actions">
        <button class="btn-secondary" onclick="selectAll()" id="selectAllBtn">
            <i class="bi bi-check-all"></i> Select All
        </button>
        <button class="btn-secondary" onclick="deselectAll()" id="deselectAllBtn" style="display: none;">
            <i class="bi bi-x"></i> Deselect All
        </button>
        <button class="btn-secondary" onclick="openBulkModal()" id="bulkActionBtn">
            <i class="bi bi-pencil-square"></i> Bulk Update
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Message Display -->
<?php if (isset($message)): ?>
<div class="alert alert-success" style="padding: 12px 16px; background: #e6f4ea; color: #10b981; border-radius: var(--radius-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $message; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger" style="padding: 12px 16px; background: #fce8e8; color: #ef4444; border-radius: var(--radius-sm); margin-bottom: 20px;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Tiers Grid -->
<?php if (empty($tiers)): ?>
<div class="empty-state">
    <i class="bi bi-layers"></i>
    <h3>No pricing tiers yet</h3>
    <p>Add pricing tiers for <?php echo sanitize($currentExperience['attraction_name']); ?></p>
    <button class="btn-primary" onclick="openAddTierModal(<?php echo $experienceId; ?>)">
        <i class="bi bi-plus-lg"></i> Add First Tier
    </button>
</div>
<?php else: ?>
<div class="tiers-grid" id="tiersGrid">
    <?php foreach ($tiers as $tier): 
        $inclusions = json_decode($tier['inclusions'] ?? '[]', true);
    ?>
    <div class="tier-card <?php echo $tier['is_active'] ? '' : 'inactive'; ?>" data-tier-id="<?php echo $tier['tier_id']; ?>">
        <div class="tier-header">
            <h3 class="tier-name"><?php echo sanitize($tier['tier_name']); ?></h3>
            <span class="tier-badge <?php echo $tier['is_active'] ? 'active' : 'inactive'; ?>">
                <?php echo $tier['is_active'] ? 'Active' : 'Inactive'; ?>
            </span>
        </div>
        
        <div class="tier-body">
            <div class="tier-price">
                <?php echo formatPrice($tier['base_price']); ?>
                <small>/ <?php echo $priceTypes[$tier['price_type']] ?? $tier['price_type']; ?></small>
            </div>
            
            <?php if ($tier['max_participants'] > 0): ?>
            <div class="tier-price-type">
                Maximum <?php echo $tier['max_participants']; ?> participants
            </div>
            <?php endif; ?>
            
            <?php if ($tier['description']): ?>
            <div class="tier-description">
                <?php echo sanitize($tier['description']); ?>
            </div>
            <?php endif; ?>
            
            <div class="tier-meta">
                <div class="meta-item">
                    <i class="bi bi-people"></i>
                    <?php echo $tier['max_participants'] ?: 'Unlimited'; ?> max
                </div>
                <div class="meta-item">
                    <i class="bi bi-tag"></i>
                    <?php echo $priceTypes[$tier['price_type']] ?? $tier['price_type']; ?>
                </div>
            </div>
            
<?php if (!empty($tier['inclusions'])): 
    $inclusions = is_string($tier['inclusions']) ? json_decode($tier['inclusions'], true) : $tier['inclusions'];
    if (!empty($inclusions) && is_array($inclusions)):
?>
<div class="tier-inclusions">
    <?php foreach (array_slice($inclusions, 0, 3) as $inc): ?>
    <span class="inclusion-tag">
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $commonInclusions[$inc] ?? $inc; ?>
    </span>
    <?php endforeach; ?>
    <?php if (count($inclusions) > 3): ?>
    <span class="inclusion-tag">+<?php echo count($inclusions) - 3; ?> more</span>
    <?php endif; ?>
</div>
<?php endif; endif; ?>
            
            <div class="tier-actions">
                <button class="tier-action-btn" onclick="editTier(<?php echo htmlspecialchars(json_encode($tier)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="tier-action-btn" onclick="toggleTierStatus(<?php echo $tier['tier_id']; ?>, <?php echo $tier['is_active']; ?>)">
                    <i class="bi bi-<?php echo $tier['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                    <?php echo $tier['is_active'] ? 'Deactivate' : 'Activate'; ?>
                </button>
                <button class="tier-action-btn delete" onclick="deleteTier(<?php echo $tier['tier_id']; ?>, <?php echo $experienceId; ?>, '<?php echo sanitize($tier['tier_name']); ?>')">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
            
            <div class="tier-checkbox" style="position: absolute; top: 12px; right: 12px; display: none;">
                <input type="checkbox" class="tier-select" value="<?php echo $tier['tier_id']; ?>">
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty-state">
    <i class="bi bi-ticket-perforated"></i>
    <h3>Select an experience</h3>
    <p>Please select an experience from the dropdown above to manage its pricing tiers</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add/Edit Tier Modal -->
<div class="modal" id="tierModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="tierModalTitle">Add Pricing Tier</h3>
            <button class="modal-close" onclick="closeModal('tierModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="tierForm">
            <div class="modal-body">
                <input type="hidden" name="tier_id" id="tier_id" value="0">
                <input type="hidden" name="attraction_id" id="attraction_id" value="<?php echo $experienceId; ?>">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Tier Name <span class="required">*</span></label>
                        <input type="text" name="tier_name" id="tier_name" class="form-control" 
                               placeholder="e.g., Standard, Premium, VIP" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Price (RWF) <span class="required">*</span></label>
                        <input type="number" name="base_price" id="base_price" class="form-control" 
                               min="0" step="1000" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Price Type</label>
                        <select name="price_type" id="price_type" class="form-control">
                            <?php foreach ($priceTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Max Participants</label>
                        <input type="number" name="max_participants" id="max_participants" class="form-control" 
                               min="0" value="0" placeholder="0 = unlimited">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3" 
                                  placeholder="Describe what this tier offers"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Tier-Specific Inclusions</label>
                        <div class="checkbox-grid" id="inclusionsContainer">
                            <?php foreach ($commonInclusions as $key => $label): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="inclusions[]" value="<?php echo $key; ?>">
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-item" style="justify-content: flex-start;">
                            <input type="checkbox" name="is_active" id="is_active" checked>
                            <span>Active (available for booking)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('tierModal')">Cancel</button>
                <button type="submit" name="save_tier" class="btn-primary">Save Tier</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal" id="bulkModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Bulk Update Prices</h3>
            <button class="modal-close" onclick="closeModal('bulkModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="bulkForm">
            <div class="modal-body">
                <input type="hidden" name="attraction_id" value="<?php echo $experienceId; ?>">
                <input type="hidden" name="tier_ids" id="bulk_tier_ids" value="">
                
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
                    <input type="number" name="bulk_value" id="bulk_value" class="form-control" step="1" min="0" required>
                </div>
                
                <div style="background: var(--exp-gray); padding: 12px; border-radius: var(--radius-sm); margin-top: 16px;">
                    <p style="font-size: 0.75rem; margin-bottom: 4px; font-weight: 600;">Selected Tiers: <span id="selectedTiersCount">0</span></p>
                    <p style="font-size: 0.6875rem; color: var(--exp-text-light);">
                        This will update all selected tiers.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" name="bulk_update" class="btn-primary">Apply Update</button>
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
            <i class="bi bi-exclamation-triangle" style="font-size: 2.5rem; color: var(--exp-danger); margin-bottom: 12px;"></i>
            <p id="deleteMessage" style="font-size: 0.9375rem; margin-bottom: 8px;">Are you sure you want to delete this pricing tier?</p>
            <p style="font-size: 0.75rem; color: var(--exp-text-light);">This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="tier_id" id="delete_tier_id" value="0">
            <input type="hidden" name="attraction_id" id="delete_attraction_id" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_tier" class="btn-primary" style="background: var(--exp-danger);">Delete Tier</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// EXPERIENCE SELECTION
// ============================================
function changeExperience(expId) {
    if (expId) {
        window.location.href = 'tiers.php?experience=' + expId;
    } else {
        window.location.href = 'tiers.php';
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
// TIER FUNCTIONS
// ============================================
function openAddTierModal(expId) {
    document.getElementById('tierModalTitle').textContent = 'Add Pricing Tier';
    document.getElementById('tierForm').reset();
    document.getElementById('tier_id').value = '0';
    document.getElementById('attraction_id').value = expId;
    document.getElementById('is_active').checked = true;
    openModal('tierModal');
}

function editTier(tier) {
    document.getElementById('tierModalTitle').textContent = 'Edit Pricing Tier';
    document.getElementById('tier_id').value = tier.tier_id;
    document.getElementById('attraction_id').value = tier.attraction_id;
    document.getElementById('tier_name').value = tier.tier_name;
    document.getElementById('base_price').value = tier.base_price;
    document.getElementById('price_type').value = tier.price_type;
    document.getElementById('max_participants').value = tier.max_participants || 0;
    document.getElementById('description').value = tier.description || '';
    document.getElementById('is_active').checked = tier.is_active == 1;
    
    // Parse inclusions
    let inclusions = [];
    try {
        if (tier.inclusions) inclusions = JSON.parse(tier.inclusions);
    } catch(e) {}
    
    // Check inclusion checkboxes
    document.querySelectorAll('#inclusionsContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = inclusions.includes(cb.value);
    });
    
    openModal('tierModal');
}

function deleteTier(tierId, expId, tierName) {
    document.getElementById('deleteMessage').innerHTML = `Delete <strong>"${tierName}"</strong>?`;
    document.getElementById('delete_tier_id').value = tierId;
    document.getElementById('delete_attraction_id').value = expId;
    openModal('deleteModal');
}

function toggleTierStatus(tierId, currentStatus) {
    if (confirm(`Are you sure you want to ${currentStatus ? 'deactivate' : 'activate'} this tier?`)) {
        fetch('tiers.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'toggle_status=1&tier_id=' + tierId + '&current_status=' + currentStatus
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
    }
}

// ============================================
// BULK SELECTION
// ============================================
let selectedTiers = new Set();

function selectAll() {
    document.querySelectorAll('.tier-select').forEach(cb => {
        cb.checked = true;
        selectedTiers.add(cb.value);
    });
    updateSelectionUI();
}

function deselectAll() {
    document.querySelectorAll('.tier-select').forEach(cb => {
        cb.checked = false;
    });
    selectedTiers.clear();
    updateSelectionUI();
}

function updateSelectionUI() {
    const count = selectedTiers.size;
    const totalCards = document.querySelectorAll('.tier-select').length;
    
    document.getElementById('selectedDisplay').textContent = count + ' tier' + (count !== 1 ? 's' : '') + ' selected';
    document.getElementById('selectAllBtn').style.display = count === totalCards && totalCards > 0 ? 'none' : 'inline-block';
    document.getElementById('deselectAllBtn').style.display = count > 0 ? 'inline-block' : 'none';
}

function openBulkModal() {
    if (selectedTiers.size === 0) {
        alert('Please select at least one tier');
        return;
    }
    
    const tierIds = Array.from(selectedTiers);
    document.getElementById('bulk_tier_ids').value = JSON.stringify(tierIds);
    document.getElementById('selectedTiersCount').textContent = tierIds.length;
    openModal('bulkModal');
}

function toggleBulkInput() {
    const action = document.getElementById('bulk_action').value;
    const label = document.getElementById('bulk_label');
    
    if (action === 'set_fixed') {
        label.textContent = 'Fixed Price (RWF)';
    } else {
        label.textContent = 'Percentage (%)';
    }
}

// Initialize selection if checkboxes exist
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.tier-select')) {
        // Add checkboxes to tiers
        document.querySelectorAll('.tier-card').forEach(card => {
            const checkbox = card.querySelector('.tier-checkbox');
            if (checkbox) checkbox.style.display = 'block';
        });
    }
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>