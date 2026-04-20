<?php
$pageTitle = 'Seasonal Pricing';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get the vendor profile ID for this user
$stmt = $db->prepare("SELECT vendor_id FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$vendor = $stmt->fetch();

if (!$vendor) {
    // Create a vendor profile if it doesn't exist
    $stmt = $db->prepare("
        INSERT INTO vendor_profiles (user_id, business_name, created_at) 
        VALUES (?, 'Car Rental Business', NOW())
    ");
    $stmt->execute([$userId]);
    $vendorId = $db->lastInsertId();
} else {
    $vendorId = $vendor['vendor_id'];
}

// ============================================
// HANDLE SEASON ACTIONS
// ============================================

// Create new season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_season'])) {
    $seasonName = sanitize($_POST['season_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $priceMultiplier = floatval($_POST['price_multiplier']);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $applicableTo = isset($_POST['applicable_vehicles']) ? json_encode($_POST['applicable_vehicles']) : null;
    $description = sanitize($_POST['description'] ?? '');
    
    // Validate dates
    if (strtotime($endDate) < strtotime($startDate)) {
        $error = "End date cannot be before start date";
    } else {
$stmt = $db->prepare("
    INSERT INTO seasons (
        vendor_id, season_name, start_date, end_date,
        price_multiplier, is_recurring, applicable_to,
        description, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
        
        $stmt->execute([
            $vendorId, $seasonName, $startDate, $endDate,
            $priceMultiplier, $isRecurring, $applicableTo,
            $description
        ]);
        
        $success = "Season created successfully!";
    }
}

// Update season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_season'])) {
    $seasonId = intval($_POST['season_id']);
    $seasonName = sanitize($_POST['season_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $priceMultiplier = floatval($_POST['price_multiplier']);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $applicableTo = isset($_POST['applicable_vehicles']) ? json_encode($_POST['applicable_vehicles']) : null;
    $description = sanitize($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate dates
    if (strtotime($endDate) < strtotime($startDate)) {
        $error = "End date cannot be before start date";
    } else {
$stmt = $db->prepare("
    UPDATE seasons 
    SET season_name = ?,
        start_date = ?,
        end_date = ?,
        price_multiplier = ?,
        is_recurring = ?,
        applicable_to = ?,
        description = ?,
        updated_at = NOW()
    WHERE season_id = ? AND vendor_id = ?
");
        
        $stmt->execute([
            $seasonName, $startDate, $endDate, $priceMultiplier,
            $isRecurring, $applicableTo, $description, $isActive,
            $seasonId, $vendorId
        ]);
        
        $success = "Season updated successfully!";
    }
}

/* Toggle season status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_season'])) {
    $seasonId = intval($_POST['season_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE seasons 
        SET is_active = ? 
        WHERE season_id = ? AND vendor_id = ?
    ");
    $stmt->execute([$newStatus, $seasonId, $vendorId]);
    
    $success = "Season status updated!";
}
*/
// Delete season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_season'])) {
    $seasonId = intval($_POST['season_id']);
    
    $stmt = $db->prepare("DELETE FROM seasons WHERE season_id = ? AND vendor_id = ?");
    $stmt->execute([$seasonId, $vendorId]);
    
    $success = "Season deleted successfully!";
}

// Duplicate season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['duplicate_season'])) {
    $seasonId = intval($_POST['season_id']);
    
    // Get original season
    $stmt = $db->prepare("SELECT * FROM seasons WHERE season_id = ? AND vendor_id = ?");
    $stmt->execute([$seasonId, $vendorId]);
    $original = $stmt->fetch();
    
    if ($original) {
$stmt = $db->prepare("
    INSERT INTO seasons (
        vendor_id, season_name, start_date, end_date,
        price_multiplier, is_recurring, applicable_to,
        description, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
        
        // Set new dates for the copy (next year for same period)
        $startDate = date('Y-m-d', strtotime($original['start_date'] . ' +1 year'));
        $endDate = date('Y-m-d', strtotime($original['end_date'] . ' +1 year'));
        
        $stmt->execute([
            $vendorId,
            $original['season_name'] . ' (Copy)',
            $startDate,
            $endDate,
            $original['price_multiplier'],
            $original['is_recurring'],
            $original['applicable_to'],
            $original['description']
        ]);
        
        $success = "Season duplicated successfully!";
    }
}

// ============================================
// GET FILTERS
// ============================================
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Build query conditions
$conditions = ["vendor_id = ?"];
$params = [$vendorId];

if ($status !== 'all') {
    $conditions[] = "is_active = ?";
    $params[] = ($status === 'active') ? 1 : 0;
}

if ($year > 0) {
    if ($month > 0) {
        // Filter by specific month
        $conditions[] = "YEAR(start_date) = ? AND MONTH(start_date) = ?";
        $params[] = $year;
        $params[] = $month;
    } else {
        // Filter by year
        $conditions[] = "(YEAR(start_date) = ? OR YEAR(end_date) = ?)";
        $params[] = $year;
        $params[] = $year;
    }
}

if ($search) {
    $conditions[] = "(season_name LIKE ? OR description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET SEASONS DATA
// ============================================

// Get all seasons
$stmt = $db->prepare("
    SELECT * FROM seasons
    WHERE $whereClause
    ORDER BY 
        CASE 
            WHEN end_date >= CURDATE() THEN 1
            ELSE 2
        END,
        start_date ASC
");
$stmt->execute($params);
$seasons = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($seasons),
    'active' => 0,
    'upcoming' => 0,
    'expired' => 0,
    'recurring' => 0,
    'avg_multiplier' => 0,
    'min_multiplier' => 0,
    'max_multiplier' => 0
];

$totalMultiplier = 0;
$multiplierCount = 0;

foreach ($seasons as $s) {
    if (strtotime($s['end_date']) < time()) {
        $stats['expired']++;
    } elseif (strtotime($s['start_date']) > time()) {
        $stats['upcoming']++;
    } else {
        $stats['active']++;
    }
    
    if ($s['is_recurring']) {
        $stats['recurring']++;
    }
    
    $totalMultiplier += $s['price_multiplier'];
    $multiplierCount++;
    
    if ($stats['min_multiplier'] == 0 || $s['price_multiplier'] < $stats['min_multiplier']) {
        $stats['min_multiplier'] = $s['price_multiplier'];
    }
    if ($s['price_multiplier'] > $stats['max_multiplier']) {
        $stats['max_multiplier'] = $s['price_multiplier'];
    }
}

$stats['avg_multiplier'] = $multiplierCount > 0 ? round($totalMultiplier / $multiplierCount, 2) : 0;

// Get all vehicles for selection
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// Get available years for filter
$years = [];
foreach ($seasons as $s) {
    $startYear = date('Y', strtotime($s['start_date']));
    $endYear = date('Y', strtotime($s['end_date']));
    if (!in_array($startYear, $years)) $years[] = $startYear;
    if (!in_array($endYear, $years)) $years[] = $endYear;
}
sort($years);

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Status colors and labels
$statusInfo = [
    'active' => ['label' => 'Active', 'class' => 'success'],
    'upcoming' => ['label' => 'Upcoming', 'class' => 'info'],
    'expired' => ['label' => 'Expired', 'class' => 'secondary'],
    'inactive' => ['label' => 'Inactive', 'class' => 'danger']
];
?>

<style>
/* Seasons Specific Styles */
.seasons-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.seasons-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.seasons-title p {
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

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    white-space: nowrap;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

.search-box {
    position: relative;
    flex: 1;
    min-width: 250px;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-light);
    font-size: 0.875rem;
}

.search-box input {
    width: 100%;
    padding: 8px 16px 8px 38px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

/* Seasons Grid */
.seasons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.season-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.season-card:hover {
    box-shadow: var(--shadow-md);
}

.season-card.active {
    border-left: 4px solid var(--cars-success);
}

.season-card.upcoming {
    border-left: 4px solid var(--cars-primary);
}

.season-card.expired {
    border-left: 4px solid var(--text-light);
    opacity: 0.8;
}

.season-card.inactive {
    border-left: 4px solid var(--cars-danger);
    opacity: 0.7;
}

.season-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.season-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
}

.season-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.badge-success { background: #e6f4ea; color: var(--cars-success); }
.badge-info { background: #e1f5fe; color: #0288d1; }
.badge-secondary { background: var(--bg-gray); color: var(--text-light); }
.badge-danger { background: #fce8e8; color: var(--cars-danger); }

.season-body {
    padding: 16px;
}

.season-dates {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    font-size: 0.875rem;
}

.date-range {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-gray);
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    flex: 1;
}

.date-range i {
    color: var(--cars-primary);
}

.recurring-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    background: #e6f4ea;
    color: var(--cars-success);
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.season-multiplier {
    text-align: center;
    margin-bottom: 16px;
}

.multiplier-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--cars-primary);
    line-height: 1.2;
}

.multiplier-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.multiplier-impact {
    margin-top: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.impact-positive {
    color: var(--cars-success);
}

.impact-negative {
    color: var(--cars-danger);
}

.season-vehicles {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
    padding: 8px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    max-height: 60px;
    overflow-y: auto;
}

.season-description {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
    padding: 8px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    font-style: italic;
}

.season-actions {
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

/* Timeline View */
.timeline-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 20px;
    margin-top: 30px;
    overflow-x: auto;
}

.timeline-header {
    display: flex;
    margin-bottom: 20px;
}

.timeline-months {
    display: flex;
    flex: 1;
}

.timeline-month {
    flex: 1;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    padding: 8px 0;
    border-left: 1px solid var(--border-gray);
}

.timeline-year {
    width: 200px;
    font-size: 0.875rem;
    font-weight: 700;
    padding: 8px 16px;
}

.timeline-rows {
    position: relative;
}

.timeline-row {
    display: flex;
    align-items: center;
    margin-bottom: 8px;
    height: 40px;
}

.timeline-label {
    width: 200px;
    font-size: 0.8125rem;
    font-weight: 600;
    padding: 0 16px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.timeline-bars {
    flex: 1;
    position: relative;
    height: 30px;
    background: var(--bg-gray);
    border-radius: 4px;
}

.timeline-bar {
    position: absolute;
    height: 30px;
    background: var(--cars-primary);
    border-radius: 4px;
    opacity: 0.7;
    transition: all 0.2s;
}

.timeline-bar:hover {
    opacity: 1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.timeline-bar.active {
    background: var(--cars-success);
}

.timeline-bar.upcoming {
    background: var(--cars-primary);
}

.timeline-bar.expired {
    background: var(--text-light);
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

.multiplier-hint {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 4px;
    display: flex;
    gap: 16px;
}

.multiplier-hint span {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Vehicle selector */
.vehicle-selector {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    padding: 12px;
}

.vehicle-selector label {
    display: block;
    padding: 6px 8px;
    cursor: pointer;
    font-size: 0.8125rem;
    border-radius: var(--radius-sm);
    transition: background 0.2s;
}

.vehicle-selector label:hover {
    background: var(--cars-light);
}

.vehicle-selector input {
    margin-right: 8px;
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
    .seasons-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-select,
    .search-box {
        width: 100%;
    }
    
    .timeline-label {
        width: 120px;
        font-size: 0.6875rem;
    }
}
</style>

<div class="seasons-header">
    <div class="seasons-title">
        <h1>Seasonal Pricing</h1>
        <p>Manage pricing seasons, holidays, and special periods</p>
    </div>
    <button class="btn-primary" onclick="openSeasonModal()">
        <i class="bi bi-plus-lg"></i> Create Season
    </button>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['active']; ?></div>
        <div class="stat-label">Active Now</div>
        <div class="stat-footer">
            <span><?php echo $stats['upcoming']; ?> upcoming</span>
            <span><?php echo $stats['expired']; ?> expired</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Seasons</div>
        <div class="stat-footer">
            <span><?php echo $stats['recurring']; ?> recurring</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['avg_multiplier']; ?>x</div>
        <div class="stat-label">Avg Multiplier</div>
        <div class="stat-footer">
            <span>Min: <?php echo $stats['min_multiplier']; ?>x</span>
            <span>Max: <?php echo $stats['max_multiplier']; ?>x</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo count($vehicles); ?></div>
        <div class="stat-label">Total Vehicles</div>
        <div class="stat-footer">
            <span>Available for seasons</span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-group">
        <label>Status</label>
        <select class="filter-select" onchange="filterByStatus(this.value)">
            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All</option>
            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Year</label>
        <select class="filter-select" onchange="filterByYear(this.value)">
            <option value="0">All Years</option>
            <?php foreach ($years as $y): ?>
            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endforeach; ?>
            <option value="<?php echo date('Y'); ?>" <?php echo $year == date('Y') ? 'selected' : ''; ?>><?php echo date('Y'); ?></option>
        </select>
    </div>
    
    <?php if ($year > 0): ?>
    <div class="filter-group">
        <label>Month</label>
        <select class="filter-select" onchange="filterByMonth(this.value)">
            <option value="0">All Months</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                <?php echo $monthNames[$m]; ?>
            </option>
            <?php endfor; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search seasons..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <?php if ($status != 'all' || $year > 0 || $month > 0 || $search): ?>
    <a href="seasons.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
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

<!-- Seasons Grid -->
<?php if (empty($seasons)): ?>
<div class="empty-state">
    <i class="bi bi-flower1"></i>
    <h3>No seasons found</h3>
    <p>Create your first seasonal pricing for holidays, peak periods, or special events.</p>
    <button class="btn-primary" onclick="openSeasonModal()">
        <i class="bi bi-plus-lg"></i> Create Season
    </button>
</div>
<?php else: ?>
<div class="seasons-grid">
<?php foreach ($seasons as $season): 
    // Determine status based on dates only (no is_active column)
    $now = time();
    $start = strtotime($season['start_date']);
    $end = strtotime($season['end_date']);
    
    if ($end < $now) {
        $statusClass = 'expired';
        $statusLabel = 'Expired';
    } elseif ($start > $now) {
        $statusClass = 'upcoming';
        $statusLabel = 'Upcoming';
    } else {
        $statusClass = 'active';
        $statusLabel = 'Active';
    }
    
    $applicableVehicles = [];
    if (!empty($season['applicable_to'])) {
        $applicableVehicles = json_decode($season['applicable_to'], true) ?: [];
    }
    
    $impactPercent = round(($season['price_multiplier'] - 1) * 100, 1);
?>
    <div class="season-card <?php echo $statusClass; ?>">
        <div class="season-header">
            <h3 class="season-name"><?php echo sanitize($season['season_name']); ?></h3>
            <span class="season-badge badge-<?php echo $statusClass; ?>">
                <?php echo $statusLabel; ?>
            </span>
        </div>
        
        <div class="season-body">
            <div class="season-dates">
                <div class="date-range">
                    <i class="bi bi-calendar-check"></i>
                    <span><?php echo date('M d, Y', $start); ?></span>
                    <i class="bi bi-arrow-right"></i>
                    <span><?php echo date('M d, Y', $end); ?></span>
                </div>
                
                <?php if ($season['is_recurring']): ?>
                <span class="recurring-badge">
                    <i class="bi bi-arrow-repeat"></i> Recurring Yearly
                </span>
                <?php endif; ?>
            </div>
            
            <div class="season-multiplier">
                <div class="multiplier-value"><?php echo $season['price_multiplier']; ?>x</div>
                <div class="multiplier-label">Price Multiplier</div>
                <div class="multiplier-impact <?php echo $impactPercent >= 0 ? 'impact-positive' : 'impact-negative'; ?>">
                    <?php echo $impactPercent >= 0 ? '+' : ''; ?><?php echo $impactPercent; ?>%
                </div>
            </div>
            
            <?php if (!empty($applicableVehicles)): ?>
            <div class="season-vehicles">
                <i class="bi bi-car-front"></i>
                Applies to <?php echo count($applicableVehicles); ?> vehicle(s)
                <?php if (count($applicableVehicles) <= 3): ?>
                <div style="margin-top: 4px; font-size: 0.6875rem;">
                    <?php 
                    $vehicleNames = [];
                    foreach ($applicableVehicles as $vid) {
                        foreach ($vehicles as $v) {
                            if ($v['car_id'] == $vid) {
                                $vehicleNames[] = $v['brand'] . ' ' . $v['model'];
                                break;
                            }
                        }
                    }
                    echo implode(', ', $vehicleNames);
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="season-vehicles">
                <i class="bi bi-car-front"></i> Applies to all vehicles
            </div>
            <?php endif; ?>
            
            <?php if ($season['description']): ?>
            <div class="season-description">
                <i class="bi bi-chat-text"></i> <?php echo sanitize($season['description']); ?>
            </div>
            <?php endif; ?>
            
            <div class="season-actions">
                <button class="action-btn" onclick="editSeason(<?php echo htmlspecialchars(json_encode($season)); ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                
<button class="action-btn" onclick="editSeason(<?php echo htmlspecialchars(json_encode($season)); ?>)">
    <i class="bi bi-pencil"></i> Edit
</button>

<form method="POST" style="display: inline; flex: 1;">
    <input type="hidden" name="season_id" value="<?php echo $season['season_id']; ?>">
    <button type="submit" name="duplicate_season" class="action-btn">
        <i class="bi bi-files"></i> Copy
    </button>
</form>

<form method="POST" style="display: inline; flex: 1;">
    <input type="hidden" name="season_id" value="<?php echo $season['season_id']; ?>">
    <button type="submit" name="delete_season" class="action-btn danger" onclick="return confirm('Delete this season?')">
        <i class="bi bi-trash"></i> Delete
    </button>
</form>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="season_id" value="<?php echo $season['season_id']; ?>">
                    <button type="submit" name="duplicate_season" class="action-btn">
                        <i class="bi bi-files"></i> Copy
                    </button>
                </form>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="season_id" value="<?php echo $season['season_id']; ?>">
                    <button type="submit" name="delete_season" class="action-btn danger" onclick="return confirm('Delete this season?')">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Timeline View -->
<div class="timeline-container">
    <div class="timeline-header">
        <div class="timeline-year">Season</div>
        <div class="timeline-months">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <div class="timeline-month"><?php echo substr($monthNames[$m], 0, 3); ?></div>
            <?php endfor; ?>
        </div>
    </div>
    
    <div class="timeline-rows">
        <?php foreach (array_slice($seasons, 0, 10) as $season): 
            $startMonth = date('n', strtotime($season['start_date']));
            $endMonth = date('n', strtotime($season['end_date']));
            $startDay = date('j', strtotime($season['start_date']));
            $endDay = date('j', strtotime($season['end_date']));
            
            // Calculate position and width (simplified - assumes same year)
            $left = (($startMonth - 1) * (100/12)) + (($startDay - 1) / 30 * (100/12));
            $width = (($endMonth - $startMonth) * (100/12)) + (($endDay - $startDay + 1) / 30 * (100/12));
            
            // Determine status class for timeline
            $now = time();
            $start = strtotime($season['start_date']);
            $end = strtotime($season['end_date']);
            
            if (!$season['is_active']) {
                $timelineClass = 'expired';
            } elseif ($end < $now) {
                $timelineClass = 'expired';
            } elseif ($start > $now) {
                $timelineClass = 'upcoming';
            } else {
                $timelineClass = 'active';
            }
        ?>
        <div class="timeline-row">
            <div class="timeline-label" title="<?php echo sanitize($season['season_name']); ?>">
                <?php echo sanitize(substr($season['season_name'], 0, 20)) . (strlen($season['season_name']) > 20 ? '...' : ''); ?>
            </div>
            <div class="timeline-bars">
                <div class="timeline-bar <?php echo $timelineClass; ?>" 
                     style="left: <?php echo $left; ?>%; width: <?php echo $width; ?>%;"
                     title="<?php echo sanitize($season['season_name']); ?>: <?php echo date('M d', $start); ?> - <?php echo date('M d', $end); ?> (<?php echo $season['price_multiplier']; ?>x)"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Create/Edit Season Modal -->
<div class="modal" id="seasonModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Create Season</h3>
            <button class="modal-close" onclick="closeModal('seasonModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="seasonForm">
            <div class="modal-body">
                <input type="hidden" name="season_id" id="season_id" value="0">
                
                <div class="form-group full-width">
                    <label class="form-label">Season Name <span class="required">*</span></label>
                    <input type="text" name="season_name" id="season_name" class="form-control" placeholder="e.g., High Season 2024, Christmas, Low Season" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Price Multiplier <span class="required">*</span></label>
                    <input type="number" name="price_multiplier" id="price_multiplier" class="form-control" value="1.2" min="0.5" max="3" step="0.1" required>
                    <div class="multiplier-hint">
                        <span><i class="bi bi-arrow-up text-success"></i> 1.2 = +20%</span>
                        <span><i class="bi bi-arrow-down text-danger"></i> 0.8 = -20%</span>
                        <span>1.0 = normal price</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_recurring" id="is_recurring" value="1">
                        <span style="font-size: 0.8125rem;">Recurring annually (same dates every year)</span>
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific vehicles (leave empty for all)</label>
                    <div class="vehicle-selector">
                        <?php foreach ($vehicles as $v): ?>
                        <label>
                            <input type="checkbox" name="applicable_vehicles[]" value="<?php echo $v['car_id']; ?>">
                            <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo ucfirst($v['car_type']); ?>)
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="2" placeholder="Optional description of this season..."></textarea>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                        <span style="font-size: 0.8125rem;">Active immediately</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('seasonModal')">Cancel</button>
                <button type="submit" name="create_season" id="submitBtn" class="btn-primary">Create Season</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    window.location.href = url.toString();
}

function filterByYear(year) {
    const url = new URL(window.location.href);
    url.searchParams.set('year', year);
    url.searchParams.delete('month');
    window.location.href = url.toString();
}

function filterByMonth(month) {
    const url = new URL(window.location.href);
    url.searchParams.set('month', month);
    window.location.href = url.toString();
}

// Real-time search
document.getElementById('searchInput')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        const url = new URL(window.location.href);
        url.searchParams.set('search', this.value);
        window.location.href = url.toString();
    }
});

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
// SEASON FUNCTIONS
// ============================================
function openSeasonModal() {
    document.getElementById('modalTitle').textContent = 'Create Season';
    document.getElementById('seasonForm').reset();
    document.getElementById('season_id').value = '0';
    document.getElementById('submitBtn').name = 'create_season';
    document.getElementById('submitBtn').textContent = 'Create Season';
    openModal('seasonModal');
}

function editSeason(season) {
    document.getElementById('modalTitle').textContent = 'Edit Season';
    document.getElementById('season_id').value = season.season_id;
    document.getElementById('season_name').value = season.season_name;
    document.getElementById('start_date').value = season.start_date;
    document.getElementById('end_date').value = season.end_date;
    document.getElementById('price_multiplier').value = season.price_multiplier;
    document.getElementById('is_recurring').checked = season.is_recurring == 1;
    document.getElementById('description').value = season.description || '';
    document.getElementById('is_active').checked = season.is_active == 1;
    
    // Check applicable vehicles
    if (season.applicable_to) {
        try {
            const vehicles = typeof season.applicable_to === 'string' 
                ? JSON.parse(season.applicable_to) 
                : season.applicable_to;
            
            if (Array.isArray(vehicles)) {
                document.querySelectorAll('input[name="applicable_vehicles[]"]').forEach(cb => {
                    cb.checked = vehicles.includes(parseInt(cb.value));
                });
            }
        } catch (e) {
            console.log('Error parsing applicable_to:', e);
        }
    }
    
    document.getElementById('submitBtn').name = 'update_season';
    document.getElementById('submitBtn').textContent = 'Update Season';
    openModal('seasonModal');
}

// Set min date for end date based on start date
document.getElementById('start_date')?.addEventListener('change', function() {
    document.getElementById('end_date').min = this.value;
});
</script>

<?php require_once 'includes/cars_footer.php'; ?>