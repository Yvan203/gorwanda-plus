<?php
$pageTitle = 'Discounts & Promotions';
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
// HANDLE DISCOUNT ACTIONS
// ============================================

// Create new discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_discount'])) {
    $discountName = sanitize($_POST['discount_name']);
    $discountType = sanitize($_POST['discount_type']);
    $discountValue = floatval($_POST['discount_value']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $minDays = intval($_POST['min_days'] ?? 1);
    $minRentalAmount = floatval($_POST['min_rental_amount'] ?? 0);
    $daysOfWeek = isset($_POST['days_of_week']) ? json_encode($_POST['days_of_week']) : null;
    $applicableVehicles = isset($_POST['applicable_vehicles']) ? json_encode($_POST['applicable_vehicles']) : null;
    $promoCode = isset($_POST['promo_code']) ? sanitize($_POST['promo_code']) : null;
    $usageLimit = intval($_POST['usage_limit'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    
    // Verify ownership for selected vehicles if any
    if ($applicableVehicles && $applicableVehicles !== 'null') {
        $vehicleIds = json_decode($applicableVehicles, true);
        if (is_array($vehicleIds) && !empty($vehicleIds)) {  // FIX: Check if array
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            $params = array_merge([$userId], $vehicleIds);
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM car_fleet cf
                JOIN car_rentals cr ON cf.rental_id = cr.rental_id
                WHERE cr.owner_id = ? AND cf.car_id IN ($placeholders)
            ");
            $stmt->execute($params);
            $count = $stmt->fetchColumn();
            if ($count != count($vehicleIds)) {
                $error = "Invalid vehicle selection";
                // Don't return here, show error instead
            }
        }
    }
    
    if (!isset($error)) {
        $stmt = $db->prepare("
            INSERT INTO offers (
                vendor_id, offer_name, offer_type, discount_value,
                min_nights, min_rental_amount, start_date, end_date,
                days_of_week, applicable_to, promo_code, usage_limit,
                used_count, description, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1, NOW())
        ");

        $stmt->execute([
            $vendorId, $discountName, $discountType, $discountValue,
            $minDays, $minRentalAmount, $startDate, $endDate,
            $daysOfWeek, $applicableVehicles, $promoCode, $usageLimit,
            $description
        ]);
        $success = "Discount created successfully!";
    }
}

// Update discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_discount'])) {
    $offerId = intval($_POST['offer_id']);
    $discountName = sanitize($_POST['discount_name']);
    $discountType = sanitize($_POST['discount_type']);
    $discountValue = floatval($_POST['discount_value']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $minDays = intval($_POST['min_days'] ?? 1);
    $minRentalAmount = floatval($_POST['min_rental_amount'] ?? 0);
    $daysOfWeek = isset($_POST['days_of_week']) ? json_encode($_POST['days_of_week']) : null;
    $applicableVehicles = isset($_POST['applicable_vehicles']) ? json_encode($_POST['applicable_vehicles']) : null;
    $promoCode = isset($_POST['promo_code']) ? sanitize($_POST['promo_code']) : null;
    $usageLimit = intval($_POST['usage_limit'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $db->prepare("
        UPDATE offers 
        SET offer_name = ?,
            offer_type = ?,
            discount_value = ?,
            min_nights = ?,
            min_rental_amount = ?,
            start_date = ?,
            end_date = ?,
            days_of_week = ?,
            applicable_to = ?,
            promo_code = ?,
            usage_limit = ?,
            description = ?,
            is_active = ?,
            updated_at = NOW()
        WHERE offer_id = ? AND vendor_id = ?
    ");

    $stmt->execute([
        $discountName, $discountType, $discountValue,
        $minDays, $minRentalAmount, $startDate, $endDate,
        $daysOfWeek, $applicableVehicles, $promoCode, $usageLimit,
        $description, $isActive, $offerId, $vendorId
    ]);
    
    $success = "Discount updated successfully!";
}

// Toggle discount status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_discount'])) {
    $offerId = intval($_POST['offer_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE offers 
        SET is_active = ? 
        WHERE offer_id = ? AND vendor_id = ?
    ");
    $stmt->execute([$newStatus, $offerId, $vendorId]);
    
    $success = "Discount status updated!";
}

// Delete discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_discount'])) {
    $offerId = intval($_POST['offer_id']);
    
    $stmt = $db->prepare("DELETE FROM offers WHERE offer_id = ? AND vendor_id = ?");
    $stmt->execute([$offerId, $vendorId]);
    
    $success = "Discount deleted successfully!";
}

// Duplicate discount
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['duplicate_discount'])) {
    $offerId = intval($_POST['offer_id']);
    
    // Get original discount
    $stmt = $db->prepare("SELECT * FROM offers WHERE offer_id = ? AND vendor_id = ?");
    $stmt->execute([$offerId, $vendorId]);
    $original = $stmt->fetch();
    
    if ($original) {
        $stmt = $db->prepare("
            INSERT INTO offers (
                vendor_id, offer_name, offer_type, discount_value,
                min_nights, min_rental_amount, start_date, end_date,
                days_of_week, applicable_to, promo_code, usage_limit,
                used_count, description, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, NOW())
        ");
        
        $stmt->execute([
            $vendorId,
            $original['offer_name'] . ' (Copy)',
            $original['offer_type'],
            $original['discount_value'],
            $original['min_nights'],
            $original['min_rental_amount'],
            date('Y-m-d'), // Start today
            date('Y-m-d', strtotime('+30 days')), // End in 30 days
            $original['days_of_week'],
            $original['applicable_to'],
            $original['promo_code'] ? $original['promo_code'] . '_COPY' : null,
            $original['usage_limit'],
            $original['description']
        ]);
        
        $success = "Discount duplicated successfully!";
    }
}

// ============================================
// GET FILTERS
// ============================================
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Build query conditions
$conditions = ["o.vendor_id = ?"];  // FIX: Use table alias
$params = [$vendorId];  // FIX: Use $vendorId instead of $userId

if ($status !== 'all') {
    $conditions[] = "o.is_active = ?";
    $params[] = ($status === 'active') ? 1 : 0;
}

if ($type !== 'all') {
    $conditions[] = "o.offer_type = ?";
    $params[] = $type;
}

if ($search) {
    $conditions[] = "(o.offer_name LIKE ? OR o.promo_code LIKE ? OR o.description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET DISCOUNTS DATA
// ============================================

// FIX: Use proper table alias and parameter binding
$stmt = $db->prepare("
    SELECT * FROM offers o
    WHERE o.vendor_id = ?
    ORDER BY 
        CASE 
            WHEN o.is_active = 1 AND o.end_date >= CURDATE() THEN 1
            WHEN o.is_active = 1 AND o.end_date < CURDATE() THEN 2
            ELSE 3
        END,
        o.created_at DESC
");
$stmt->execute([$vendorId]);  // FIX: Use $vendorId
$discounts = $stmt->fetchAll();

// Ensure $discounts is always an array
if (!is_array($discounts)) {
    $discounts = [];
}

// Get statistics
$stats = [
    'total' => count($discounts),
    'active' => 0,
    'expired' => 0,
    'scheduled' => 0,
    'percentage' => 0,
    'fixed' => 0,
    'free_day' => 0,
    'upgrade' => 0
];

foreach ($discounts as $d) {
    if (!is_array($d)) continue;  // FIX: Skip if not array
    
    if ($d['is_active']) {
        if (strtotime($d['end_date']) < time()) {
            $stats['expired']++;
        } elseif (strtotime($d['start_date']) > time()) {
            $stats['scheduled']++;
        } else {
            $stats['active']++;
        }
    }
    
    if (isset($stats[$d['offer_type']])) {
        $stats[$d['offer_type']]++;
    }
}

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

// Ensure $vehicles is always an array
if (!is_array($vehicles)) {
    $vehicles = [];
}

// Discount types
$discountTypes = [
    'percentage' => 'Percentage Discount',
    'fixed' => 'Fixed Amount',
    'free_day' => 'Free Day',
    'upgrade' => 'Free Upgrade'
];

// Days of week
$daysOfWeek = [
    'monday' => 'Mon',
    'tuesday' => 'Tue',
    'wednesday' => 'Wed',
    'thursday' => 'Thu',
    'friday' => 'Fri',
    'saturday' => 'Sat',
    'sunday' => 'Sun'
];

// Status colors and labels
$statusInfo = [
    'active' => ['label' => 'Active', 'class' => 'success'],
    'scheduled' => ['label' => 'Scheduled', 'class' => 'info'],
    'expired' => ['label' => 'Expired', 'class' => 'secondary'],
    'inactive' => ['label' => 'Inactive', 'class' => 'danger']
];
?>

<style>
/* [Keep all your existing styles exactly as they are] */
/* Discounts Specific Styles */
.discounts-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.discounts-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.discounts-title p {
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

/* Discounts Grid */
.discounts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.discount-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.discount-card:hover {
    box-shadow: var(--shadow-md);
}

.discount-card.active {
    border-left: 4px solid var(--cars-success);
}

.discount-card.scheduled {
    border-left: 4px solid var(--cars-primary);
}

.discount-card.expired {
    border-left: 4px solid var(--text-light);
    opacity: 0.8;
}

.discount-card.inactive {
    border-left: 4px solid var(--cars-danger);
    opacity: 0.7;
}

.discount-header {
    padding: 16px;
    background: linear-gradient(to right, var(--bg-gray), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.discount-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.discount-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.badge-success { background: #e6f4ea; color: var(--cars-success); }
.badge-info { background: #e1f5fe; color: #0288d1; }
.badge-secondary { background: var(--bg-gray); color: var(--text-light); }
.badge-danger { background: #fce8e8; color: var(--cars-danger); }

.discount-body {
    padding: 16px;
}

.discount-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--cars-danger);
    margin-bottom: 8px;
    line-height: 1.2;
}

.discount-value small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--text-light);
}

.discount-code {
    display: inline-block;
    padding: 4px 8px;
    background: var(--bg-gray);
    border: 1px dashed var(--cars-primary);
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.8125rem;
    margin-bottom: 12px;
}

.discount-dates {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    font-size: 0.75rem;
    color: var(--text-light);
}

.discount-dates i {
    color: var(--cars-primary);
}

.discount-conditions {
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 12px;
    font-size: 0.75rem;
}

.condition-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
}

.condition-row:last-child {
    margin-bottom: 0;
}

.condition-label {
    color: var(--text-light);
}

.condition-value {
    font-weight: 600;
}

.discount-days {
    display: flex;
    gap: 4px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.day-tag {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-gray);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--text-light);
}

.day-tag.active {
    background: var(--cars-primary);
    color: white;
}

.discount-vehicles {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 12px;
    padding: 8px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    max-height: 60px;
    overflow-y: auto;
}

.discount-usage {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    font-size: 0.75rem;
}

.usage-bar {
    flex: 1;
    height: 6px;
    background: var(--bg-gray);
    border-radius: 3px;
    margin: 0 10px;
    overflow: hidden;
}

.usage-fill {
    height: 100%;
    background: var(--cars-primary);
    border-radius: 3px;
    transition: width 0.3s;
}

.discount-actions {
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

/* Days of week grid */
.days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    margin: 10px 0;
}

.day-checkbox {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.day-checkbox:hover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
}

.day-checkbox input {
    margin-bottom: 4px;
    accent-color: var(--cars-primary);
}

.day-checkbox span {
    font-size: 0.6875rem;
    font-weight: 600;
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
    .discounts-grid,
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
    
    .days-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>

<div class="discounts-header">
    <div class="discounts-title">
        <h1>Discounts & Promotions</h1>
        <p>Manage special offers, promo codes, and seasonal discounts</p>
    </div>
    <button class="btn-primary" onclick="openDiscountModal()">
        <i class="bi bi-plus-lg"></i> Create Discount
    </button>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['active']; ?></div>
        <div class="stat-label">Active Now</div>
        <div class="stat-footer">
            <span>Running</span>
            <span><?php echo $stats['scheduled']; ?> scheduled</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['percentage']; ?></div>
        <div class="stat-label">Percentage Off</div>
        <div class="stat-footer">
            <span><?php echo $stats['fixed']; ?> fixed</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['free_day']; ?></div>
        <div class="stat-label">Free Days</div>
        <div class="stat-footer">
            <span><?php echo $stats['upgrade']; ?> upgrades</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['expired']; ?></div>
        <div class="stat-label">Expired</div>
        <div class="stat-footer">
            <span><?php echo $stats['total'] - $stats['active'] - $stats['scheduled'] - $stats['expired']; ?> inactive</span>
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
        <label>Type</label>
        <select class="filter-select" onchange="filterByType(this.value)">
            <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
            <?php foreach ($discountTypes as $key => $label): ?>
            <option value="<?php echo $key; ?>" <?php echo $type == $key ? 'selected' : ''; ?>>
                <?php echo $label; ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search discounts..." value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <?php if ($status != 'all' || $type != 'all' || $search): ?>
    <a href="discounts.php" class="btn-secondary btn-sm">Clear Filters</a>
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

<!-- Discounts Grid -->
<?php if (empty($discounts)): ?>
<div class="empty-state">
    <i class="bi bi-tags"></i>
    <h3>No discounts found</h3>
    <p>Create your first discount to start promoting your vehicles.</p>
    <button class="btn-primary" onclick="openDiscountModal()">
        <i class="bi bi-plus-lg"></i> Create Discount
    </button>
</div>
<?php else: ?>
<div class="discounts-grid">
    <?php foreach ($discounts as $discount): 
        // FIX: Ensure $discount is an array
        if (!is_array($discount)) continue;
        
        // Determine status
        $now = time();
        $start = strtotime($discount['start_date'] ?? 'now');
        $end = strtotime($discount['end_date'] ?? 'now');
        
        if (!$discount['is_active']) {
            $statusClass = 'inactive';
            $statusLabel = 'Inactive';
        } elseif ($end < $now) {
            $statusClass = 'expired';
            $statusLabel = 'Expired';
        } elseif ($start > $now) {
            $statusClass = 'scheduled';
            $statusLabel = 'Scheduled';
        } else {
            $statusClass = 'active';
            $statusLabel = 'Active';
        }
        
        // FIX: Safely decode JSON with proper null checks
        $days = [];
        if (!empty($discount['days_of_week'])) {
            $decoded = json_decode($discount['days_of_week'], true);
            $days = is_array($decoded) ? $decoded : [];
        }
        
        $applicableVehicles = [];
        if (!empty($discount['applicable_to'])) {
            $decoded = json_decode($discount['applicable_to'], true);
            $applicableVehicles = is_array($decoded) ? $decoded : [];
        }
        
        $usagePercent = ($discount['usage_limit'] ?? 0) > 0 
            ? round((($discount['used_count'] ?? 0) / $discount['usage_limit']) * 100) 
            : 0;
    ?>
    <div class="discount-card <?php echo $statusClass; ?>">
        <div class="discount-header">
            <h3 class="discount-name"><?php echo sanitize($discount['offer_name'] ?? 'Unnamed'); ?></h3>
            <span class="discount-badge badge-<?php echo $statusClass; ?>">
                <?php echo $statusLabel; ?>
            </span>
        </div>
        
        <div class="discount-body">
            <div class="discount-value">
                <?php 
                $offerType = $discount['offer_type'] ?? 'percentage';
                $discountValue = $discount['discount_value'] ?? 0;
                
                if ($offerType == 'percentage') {
                    echo $discountValue . '%';
                } elseif ($offerType == 'fixed') {
                    echo formatPrice($discountValue);
                } elseif ($offerType == 'free_day') {
                    echo $discountValue . ' day' . ($discountValue > 1 ? 's' : '');
                } else {
                    echo 'Upgrade';
                }
                ?>
                <small>OFF</small>
            </div>
            
            <?php if (!empty($discount['promo_code'])): ?>
            <div class="discount-code">
                <i class="bi bi-ticket"></i> <?php echo sanitize($discount['promo_code']); ?>
            </div>
            <?php endif; ?>
            
            <div class="discount-dates">
                <span><i class="bi bi-calendar-check"></i> <?php echo date('M d, Y', $start); ?></span>
                <i class="bi bi-arrow-right"></i>
                <span><i class="bi bi-calendar-x"></i> <?php echo date('M d, Y', $end); ?></span>
            </div>
            
            <div class="discount-conditions">
                <?php if (($discount['min_nights'] ?? 1) > 1): ?>
                <div class="condition-row">
                    <span class="condition-label">Minimum days:</span>
                    <span class="condition-value"><?php echo $discount['min_nights']; ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (($discount['min_rental_amount'] ?? 0) > 0): ?>
                <div class="condition-row">
                    <span class="condition-label">Minimum spend:</span>
                    <span class="condition-value"><?php echo formatPrice($discount['min_rental_amount']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($days)): ?>
            <div class="discount-days">
                <?php foreach ($daysOfWeek as $key => $label): ?>
                <div class="day-tag <?php echo in_array($key, $days) ? 'active' : ''; ?>">
                    <?php echo $label; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($applicableVehicles)): ?>
            <div class="discount-vehicles">
                <i class="bi bi-car-front"></i>
                Applies to <?php echo count($applicableVehicles); ?> vehicle(s)
                <?php if (count($applicableVehicles) <= 3): ?>
                <div style="margin-top: 4px; font-size: 0.6875rem;">
                    <?php 
                    $vehicleNames = [];
                    foreach ($applicableVehicles as $vid) {
                        foreach ($vehicles as $v) {
                            if (is_array($v) && ($v['car_id'] ?? null) == $vid) {
                                $vehicleNames[] = ($v['brand'] ?? '') . ' ' . ($v['model'] ?? '');
                                break;
                            }
                        }
                    }
                    echo implode(', ', $vehicleNames);
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (($discount['usage_limit'] ?? 0) > 0): ?>
            <div class="discount-usage">
                <span>Used: <?php echo $discount['used_count'] ?? 0; ?>/<?php echo $discount['usage_limit']; ?></span>
                <div class="usage-bar">
                    <div class="usage-fill" style="width: <?php echo $usagePercent; ?>%;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($discount['description'])): ?>
            <div style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 16px;">
                <?php echo sanitize($discount['description']); ?>
            </div>
            <?php endif; ?>
            
            <div class="discount-actions">
                <button class="action-btn" onclick='editDiscount(<?php echo json_encode($discount, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                    <i class="bi bi-pencil"></i> Edit
                </button>
                
                <?php if ($discount['is_active']): ?>
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="offer_id" value="<?php echo $discount['offer_id']; ?>">
                    <input type="hidden" name="current_status" value="1">
                    <button type="submit" name="toggle_discount" class="action-btn warning">
                        <i class="bi bi-pause-circle"></i> Deactivate
                    </button>
                </form>
                <?php else: ?>
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="offer_id" value="<?php echo $discount['offer_id']; ?>">
                    <input type="hidden" name="current_status" value="0">
                    <button type="submit" name="toggle_discount" class="action-btn success">
                        <i class="bi bi-play-circle"></i> Activate
                    </button>
                </form>
                <?php endif; ?>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="offer_id" value="<?php echo $discount['offer_id']; ?>">
                    <button type="submit" name="duplicate_discount" class="action-btn">
                        <i class="bi bi-files"></i> Copy
                    </button>
                </form>
                
                <form method="POST" style="display: inline; flex: 1;">
                    <input type="hidden" name="offer_id" value="<?php echo $discount['offer_id']; ?>">
                    <button type="submit" name="delete_discount" class="action-btn danger" onclick="return confirm('Delete this discount?')">
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

<!-- Create/Edit Discount Modal -->
<div class="modal" id="discountModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Create Discount</h3>
            <button class="modal-close" onclick="closeModal('discountModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="discountForm">
            <div class="modal-body">
                <input type="hidden" name="offer_id" id="offer_id" value="0">
                
                <div class="form-group full-width">
                    <label class="form-label">Discount Name <span class="required">*</span></label>
                    <input type="text" name="discount_name" id="discount_name" class="form-control" placeholder="e.g., Summer Special, Early Bird" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Discount Type</label>
                        <select name="discount_type" id="discount_type" class="form-control" onchange="toggleDiscountFields()">
                            <?php foreach ($discountTypes as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" id="value_label">Value</label>
                        <input type="number" name="discount_value" id="discount_value" class="form-control" step="1" min="0" required>
                    </div>
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
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Minimum Days</label>
                        <input type="number" name="min_days" id="min_days" class="form-control" value="1" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Minimum Rental (RWF)</label>
                        <input type="number" name="min_rental_amount" id="min_rental_amount" class="form-control" value="0" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Promo Code (Optional)</label>
                    <input type="text" name="promo_code" id="promo_code" class="form-control" placeholder="e.g., SUMMER10">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Usage Limit (0 = unlimited)</label>
                    <input type="number" name="usage_limit" id="usage_limit" class="form-control" value="0" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Valid on these days</label>
                    <div class="days-grid">
                        <?php foreach ($daysOfWeek as $key => $label): ?>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="<?php echo $key; ?>">
                            <span><?php echo $label; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific vehicles (leave empty for all)</label>
                    <div class="vehicle-selector">
                        <?php foreach ($vehicles as $v): 
                            if (!is_array($v)) continue;  // FIX: Skip if not array
                        ?>
                        <label>
                            <input type="checkbox" name="applicable_vehicles[]" value="<?php echo $v['car_id'] ?? ''; ?>">
                            <?php echo sanitize(($v['brand'] ?? '') . ' ' . ($v['model'] ?? '')); ?> (<?php echo ucfirst($v['car_type'] ?? 'Unknown'); ?>)
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="2" placeholder="Brief description of this offer..."></textarea>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                        <span style="font-size: 0.8125rem;">Active immediately</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('discountModal')">Cancel</button>
                <button type="submit" name="create_discount" id="submitBtn" class="btn-primary">Create Discount</button>
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

function filterByType(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('type', type);
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
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
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
// DISCOUNT FUNCTIONS
// ============================================
function openDiscountModal() {
    document.getElementById('modalTitle').textContent = 'Create Discount';
    document.getElementById('discountForm').reset();
    document.getElementById('offer_id').value = '0';
    document.getElementById('submitBtn').name = 'create_discount';
    document.getElementById('submitBtn').textContent = 'Create Discount';
    
    // Reset checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.getElementById('is_active').checked = true;
    
    // Set default dates
    const today = new Date().toISOString().split('T')[0];
    const nextMonth = new Date();
    nextMonth.setDate(nextMonth.getDate() + 30);
    
    document.getElementById('start_date').value = today;
    document.getElementById('end_date').value = nextMonth.toISOString().split('T')[0];
    document.getElementById('end_date').min = today;
    
    openModal('discountModal');
}

function editDiscount(discount) {
    // FIX: Ensure discount is an object
    if (typeof discount !== 'object' || discount === null) {
        console.error('Invalid discount data:', discount);
        alert('Error loading discount data');
        return;
    }
    
    document.getElementById('modalTitle').textContent = 'Edit Discount';
    document.getElementById('offer_id').value = discount.offer_id || '0';
    document.getElementById('discount_name').value = discount.offer_name || '';
    document.getElementById('discount_type').value = discount.offer_type || 'percentage';
    document.getElementById('discount_value').value = discount.discount_value || 0;
    document.getElementById('start_date').value = discount.start_date || '';
    document.getElementById('end_date').value = discount.end_date || '';
    document.getElementById('min_days').value = discount.min_nights || 1;
    document.getElementById('min_rental_amount').value = discount.min_rental_amount || 0;
    document.getElementById('promo_code').value = discount.promo_code || '';
    document.getElementById('usage_limit').value = discount.usage_limit || 0;
    document.getElementById('description').value = discount.description || '';
    document.getElementById('is_active').checked = discount.is_active == 1;
    
    // Check days of week - handle both string and array
    if (discount.days_of_week) {
        try {
            const days = typeof discount.days_of_week === 'string' 
                ? JSON.parse(discount.days_of_week) 
                : discount.days_of_week;
            
            if (Array.isArray(days)) {
                document.querySelectorAll('input[name="days_of_week[]"]').forEach(cb => {
                    cb.checked = days.includes(cb.value);
                });
            }
        } catch (e) {
            console.log('Error parsing days_of_week:', e);
        }
    }
    
    // Check applicable vehicles - handle both string and array
    if (discount.applicable_to) {
        try {
            const vehicles = typeof discount.applicable_to === 'string' 
                ? JSON.parse(discount.applicable_to) 
                : discount.applicable_to;
            
            if (Array.isArray(vehicles)) {
                document.querySelectorAll('input[name="applicable_vehicles[]"]').forEach(cb => {
                    cb.checked = vehicles.includes(parseInt(cb.value)) || vehicles.includes(cb.value);
                });
            }
        } catch (e) {
            console.log('Error parsing applicable_to:', e);
        }
    }
    
    document.getElementById('submitBtn').name = 'update_discount';
    document.getElementById('submitBtn').textContent = 'Update Discount';
    
    toggleDiscountFields();
    openModal('discountModal');
}

function toggleDiscountFields() {
    const type = document.getElementById('discount_type').value;
    const label = document.getElementById('value_label');
    const input = document.getElementById('discount_value');
    
    // Reset input type first
    input.type = 'number';
    
    if (type === 'percentage') {
        label.textContent = 'Percentage (%)';
        input.step = '1';
        input.max = '100';
        input.placeholder = '';
    } else if (type === 'fixed') {
        label.textContent = 'Amount (RWF)';
        input.step = '1000';
        input.max = '';
        input.placeholder = '';
    } else if (type === 'free_day') {
        label.textContent = 'Number of Days';
        input.step = '1';
        input.max = '7';
        input.placeholder = '';
    } else if (type === 'upgrade') {
        label.textContent = 'Upgrade to';
        input.type = 'text';
        input.placeholder = 'e.g., SUV, Luxury';
        input.max = '';
        input.step = '';
    }
}

// Set min date for end date based on start date
document.getElementById('start_date')?.addEventListener('change', function() {
    document.getElementById('end_date').min = this.value;
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set min date for end date initially
    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');
    if (startDate && endDate && startDate.value) {
        endDate.min = startDate.value;
    }
});
</script>

<?php require_once 'includes/cars_footer.php'; ?>