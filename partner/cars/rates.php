<?php
$pageTitle = 'Rates & Pricing';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;
$view = isset($_GET['view']) ? sanitize($_GET['view']) : 'rates';

// ============================================
// HANDLE RATE ACTIONS
// ============================================

// Update base rates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rates'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $dailyRate = floatval($_POST['daily_rate']);
    $weeklyRate = floatval($_POST['weekly_rate'] ?? 0);
    $monthlyRate = floatval($_POST['monthly_rate'] ?? 0);
    $freeKmPerDay = intval($_POST['free_km_per_day'] ?? 100);
    $excessKmCharge = floatval($_POST['excess_km_charge'] ?? 0);
    $insuranceIncluded = isset($_POST['insurance_included']) ? 1 : 0;
    
    // Verify ownership
    $stmt = $db->prepare("
        UPDATE car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET cf.daily_rate = ?,
            cf.weekly_rate = ?,
            cf.monthly_rate = ?,
            cf.free_km_per_day = ?,
            cf.excess_km_charge = ?,
            cf.insurance_included = ?,
            cf.updated_at = NOW()
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([
        $dailyRate, $weeklyRate, $monthlyRate,
        $freeKmPerDay, $excessKmCharge, $insuranceIncluded,
        $vehicleId, $userId
    ]);
    
    $success = "Rates updated successfully!";
}

// Bulk update rates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_rates'])) {
    $action = sanitize($_POST['bulk_action']);
    $value = floatval($_POST['bulk_value']);
    $carType = isset($_POST['car_type']) ? sanitize($_POST['car_type']) : '';
    $selectedVehicles = isset($_POST['vehicle_ids']) ? $_POST['vehicle_ids'] : [];
    
    // Build query based on selection
    $query = "
        UPDATE car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        SET cf.daily_rate = 
    ";
    
    $params = [];
    
    switch($action) {
        case 'increase_percent':
            $query .= "cf.daily_rate * (1 + ? / 100)";
            $params[] = $value;
            break;
        case 'decrease_percent':
            $query .= "cf.daily_rate * (1 - ? / 100)";
            $params[] = $value;
            break;
        case 'set_fixed':
            $query .= "?";
            $params[] = $value;
            break;
    }
    
    $query .= ", cf.updated_at = NOW() WHERE cr.owner_id = ?";
    $params[] = $userId;
    
    if (!empty($carType) && $carType !== 'all') {
        $query .= " AND cf.car_type = ?";
        $params[] = $carType;
    }
    
    if (!empty($selectedVehicles)) {
        $placeholders = implode(',', array_fill(0, count($selectedVehicles), '?'));
        $query .= " AND cf.car_id IN ($placeholders)";
        $params = array_merge($params, $selectedVehicles);
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $success = "Bulk rate update completed successfully!";
}

// Create seasonal rate (using seasons table like stays)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_season'])) {
    $seasonName = sanitize($_POST['season_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $priceMultiplier = floatval($_POST['price_multiplier']);
    
    $stmt = $db->prepare("
        INSERT INTO seasons (
            vendor_id, season_name, start_date, end_date,
            price_multiplier, is_recurring, created_at
        ) VALUES (?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([
        $userId, $seasonName, $startDate, $endDate, $priceMultiplier
    ]);
    
    $success = "Seasonal rate created successfully!";
}

// Create special offer (using offers table like stays)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_offer'])) {
    $offerName = sanitize($_POST['offer_name']);
    $offerType = sanitize($_POST['offer_type']);
    $discountValue = floatval($_POST['discount_value']);
    $minNights = intval($_POST['min_days'] ?? 1);
    $startDate = $_POST['offer_start_date'];
    $endDate = $_POST['offer_end_date'];
    $daysOfWeek = isset($_POST['days_of_week']) ? json_encode($_POST['days_of_week']) : null;
    
    // For cars, we'll store vehicle IDs in the applicable_to field
    $applicableVehicles = isset($_POST['offer_vehicles']) ? json_encode($_POST['offer_vehicles']) : null;
    
    $stmt = $db->prepare("
        INSERT INTO offers (
            vendor_id, offer_name, offer_type, discount_value,
            min_nights, start_date, end_date, days_of_week,
            applicable_to, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([
        $userId, $offerName, $offerType, $discountValue,
        $minNights, $startDate, $endDate, $daysOfWeek,
        $applicableVehicles
    ]);
    
    $success = "Special offer created successfully!";
}

// Toggle offer status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_offer'])) {
    $offerId = intval($_POST['offer_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE offers 
        SET is_active = ? 
        WHERE offer_id = ? AND vendor_id = ?
    ");
    $stmt->execute([$newStatus, $offerId, $userId]);
    
    $success = "Offer status updated!";
}

// Delete offer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_offer'])) {
    $offerId = intval($_POST['offer_id']);
    
    $stmt = $db->prepare("DELETE FROM offers WHERE offer_id = ? AND vendor_id = ?");
    $stmt->execute([$offerId, $userId]);
    
    $success = "Offer deleted successfully!";
}

// Delete season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_season'])) {
    $seasonId = intval($_POST['season_id']);
    
    $stmt = $db->prepare("DELETE FROM seasons WHERE season_id = ? AND vendor_id = ?");
    $stmt->execute([$seasonId, $userId]);
    
    $success = "Seasonal rate deleted successfully!";
}

// ============================================
// GET DATA
// ============================================

// Get all vehicles for filter
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cf.daily_rate,
           cf.weekly_rate, cf.monthly_rate, cf.free_km_per_day,
           cf.excess_km_charge, cf.insurance_included,
           cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// If no vehicle selected, use the first one
if ($vehicleId === 0 && !empty($vehicles)) {
    $vehicleId = $vehicles[0]['car_id'];
}

// Get selected vehicle details
$currentVehicle = null;
foreach ($vehicles as $v) {
    if ($v['car_id'] == $vehicleId) {
        $currentVehicle = $v;
        break;
    }
}

// Get pricing statistics
$stats = [
    'avg_daily' => 0,
    'min_daily' => 0,
    'max_daily' => 0,
    'avg_weekly' => 0,
    'avg_monthly' => 0,
    'total_vehicles' => count($vehicles),
    'vehicles_with_weekly' => 0,
    'vehicles_with_monthly' => 0
];

$totalDaily = 0;
$dailyCount = 0;
$weeklyTotal = 0;
$weeklyCount = 0;
$monthlyTotal = 0;
$monthlyCount = 0;

foreach ($vehicles as $v) {
    $totalDaily += $v['daily_rate'];
    $dailyCount++;
    
    if ($stats['min_daily'] == 0 || $v['daily_rate'] < $stats['min_daily']) {
        $stats['min_daily'] = $v['daily_rate'];
    }
    if ($v['daily_rate'] > $stats['max_daily']) {
        $stats['max_daily'] = $v['daily_rate'];
    }
    
    if ($v['weekly_rate'] > 0) {
        $weeklyTotal += $v['weekly_rate'];
        $weeklyCount++;
        $stats['vehicles_with_weekly']++;
    }
    
    if ($v['monthly_rate'] > 0) {
        $monthlyTotal += $v['monthly_rate'];
        $monthlyCount++;
        $stats['vehicles_with_monthly']++;
    }
}

$stats['avg_daily'] = $dailyCount > 0 ? $totalDaily / $dailyCount : 0;
$stats['avg_weekly'] = $weeklyCount > 0 ? $weeklyTotal / $weeklyCount : 0;
$stats['avg_monthly'] = $monthlyCount > 0 ? $monthlyTotal / $monthlyCount : 0;

// Get seasonal rates from seasons table
$stmt = $db->prepare("
    SELECT * FROM seasons
    WHERE vendor_id = ?
    ORDER BY start_date DESC
");
$stmt->execute([$userId]);
$seasonalRates = $stmt->fetchAll();

// Get special offers from offers table
$stmt = $db->prepare("
    SELECT * FROM offers
    WHERE vendor_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$specialOffers = $stmt->fetchAll();

// Car types for filter
$carTypes = $db->prepare("
    SELECT DISTINCT car_type FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY car_type
");
$carTypes->execute([$userId]);
$carTypes = $carTypes->fetchAll(PDO::FETCH_COLUMN);

// Car type options
$carTypeOptions = [
    'economy' => 'Economy',
    'compact' => 'Compact',
    'mid_size' => 'Mid-size',
    'full_size' => 'Full-size',
    'suv' => 'SUV',
    'luxury' => 'Luxury',
    'van' => 'Van/Minibus',
    '4x4' => '4x4 / Off-road'
];

// Offer types
$offerTypes = [
    'percentage' => 'Percentage Discount',
    'fixed' => 'Fixed Amount Off',
    'free_night' => 'Free Day',
    'upgrade' => 'Free Upgrade'
];

// Days of week
$daysOfWeek = [
    'monday' => 'Monday',
    'tuesday' => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday' => 'Thursday',
    'friday' => 'Friday',
    'saturday' => 'Saturday',
    'sunday' => 'Sunday'
];
?>

<style>
/* Rates Management Specific Styles */
.rates-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.rates-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.rates-title p {
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
}

/* Vehicle Selector */
.vehicle-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.vehicle-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-dark);
}

.vehicle-selector select {
    min-width: 350px;
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Tab Navigation */
.rates-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border-gray);
    flex-wrap: wrap;
}

.rates-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.rates-tab:hover {
    color: var(--cars-primary);
}

.rates-tab.active {
    color: var(--cars-primary);
}

.rates-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--cars-primary);
}

/* Rate Cards */
.rate-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 24px;
    margin-bottom: 20px;
}

.rate-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.rate-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
}

.rate-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
    background: var(--cars-light);
    color: var(--cars-primary);
}

.rate-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.rate-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.rate-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
    margin-bottom: 8px;
}

.rate-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cars-success);
}

.rate-unit {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-top: 4px;
}

.rate-savings {
    font-size: 0.6875rem;
    color: var(--cars-primary);
    margin-top: 8px;
    padding: 4px 8px;
    background: white;
    border-radius: 100px;
}

.km-info {
    margin-top: 20px;
    padding: 16px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 16px;
}

.km-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.km-item i {
    color: var(--cars-primary);
}

/* Bulk Update Section */
.bulk-section {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 20px;
    margin-bottom: 30px;
}

.bulk-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.bulk-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 16px;
    align-items: flex-end;
}

/* Seasonal Cards */
.seasons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.season-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
}

.season-card:hover {
    box-shadow: var(--shadow-md);
}

.season-header {
    padding: 16px;
    background: linear-gradient(135deg, var(--cars-light), white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.season-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--cars-primary);
}

.season-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: white;
    color: var(--cars-success);
}

.season-body {
    padding: 16px;
}

.season-dates {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    font-size: 0.8125rem;
    color: var(--text-light);
}

.season-multiplier {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cars-primary);
    margin-bottom: 12px;
}

.season-multiplier small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--text-light);
}

.season-applicable {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
    padding: 8px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.season-actions {
    display: flex;
    gap: 8px;
}

/* Offer Cards */
.offers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.offer-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.offer-card.inactive {
    opacity: 0.7;
    background: var(--bg-gray);
}

.offer-header {
    padding: 16px;
    background: linear-gradient(135deg, #fff4e6, white);
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.offer-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--cars-warning);
}

.offer-status {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.offer-status.active {
    background: #e6f4ea;
    color: var(--cars-success);
}

.offer-status.inactive {
    background: var(--bg-gray);
    color: var(--text-light);
}

.offer-body {
    padding: 16px;
}

.offer-discount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cars-danger);
    margin-bottom: 12px;
}

.offer-discount small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--text-light);
}

.offer-dates {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    font-size: 0.75rem;
    color: var(--text-light);
}

.offer-days {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 12px;
}

.day-tag {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.5625rem;
    font-weight: 600;
    background: var(--bg-gray);
    color: var(--text-light);
}

.offer-code {
    display: inline-block;
    padding: 4px 8px;
    background: var(--bg-gray);
    border-radius: 4px;
    font-family: monospace;
    font-size: 0.75rem;
    margin-bottom: 12px;
}

.offer-usage {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 16px;
}

.offer-actions {
    display: flex;
    gap: 8px;
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

/* Days of week */
.days-of-week {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin: 10px 0;
}

.day-checkbox {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.75rem;
}

/* Vehicle selector */
.vehicle-selector-grid {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    padding: 12px;
}

.vehicle-selector-grid label {
    display: block;
    padding: 6px;
    cursor: pointer;
}

.vehicle-selector-grid label:hover {
    background: var(--cars-light);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .bulk-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .rate-grid,
    .seasons-grid,
    .offers-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .vehicle-selector select {
        min-width: 100%;
    }
    
    .rates-tabs {
        flex-direction: column;
    }
    
    .rates-tab {
        width: 100%;
        text-align: left;
    }
    
    .bulk-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="rates-header">
    <div class="rates-title">
        <h1>Rates & Pricing</h1>
        <p>Manage vehicle rates, seasonal pricing, and special offers</p>
    </div>
    <div>
        <button class="btn-primary" onclick="openSeasonModal()">
            <i class="bi bi-flower1"></i> Add Season
        </button>
        <button class="btn-primary" onclick="openOfferModal()">
            <i class="bi bi-tag"></i> Add Offer
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_daily']); ?></div>
        <div class="stat-label">Avg Daily Rate</div>
        <div class="stat-footer">Min: <?php echo formatPrice($stats['min_daily']); ?> • Max: <?php echo formatPrice($stats['max_daily']); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_weekly']); ?></div>
        <div class="stat-label">Avg Weekly Rate</div>
        <div class="stat-footer"><?php echo $stats['vehicles_with_weekly']; ?>/<?php echo $stats['total_vehicles']; ?> vehicles</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_monthly']); ?></div>
        <div class="stat-label">Avg Monthly Rate</div>
        <div class="stat-footer"><?php echo $stats['vehicles_with_monthly']; ?>/<?php echo $stats['total_vehicles']; ?> vehicles</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo count($seasonalRates); ?></div>
        <div class="stat-label">Active Seasons</div>
        <div class="stat-footer"><?php echo count($specialOffers); ?> special offers</div>
    </div>
</div>

<!-- Vehicle Selector -->
<div class="vehicle-selector">
    <label for="vehicleSelect"><i class="bi bi-car-front"></i> Select Vehicle:</label>
    <select id="vehicleSelect" onchange="changeVehicle(this.value)">
        <option value="0">All Vehicles (View Stats)</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?php echo $v['car_id']; ?>" <?php echo $v['car_id'] == $vehicleId ? 'selected' : ''; ?>>
            <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo sanitize($v['company_name']); ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Tabs -->
<div class="rates-tabs">
    <button class="rates-tab <?php echo $view == 'rates' ? 'active' : ''; ?>" onclick="switchView('rates')">
        Base Rates
    </button>
    <button class="rates-tab <?php echo $view == 'seasonal' ? 'active' : ''; ?>" onclick="switchView('seasonal')">
        Seasonal Pricing
    </button>
    <button class="rates-tab <?php echo $view == 'offers' ? 'active' : ''; ?>" onclick="switchView('offers')">
        Special Offers
    </button>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<!-- VIEW: BASE RATES -->
<div id="view-rates" style="display: <?php echo $view == 'rates' ? 'block' : 'none'; ?>;">
    
    <!-- Bulk Update Section -->
    <div class="bulk-section">
        <h3 class="bulk-title">
            <i class="bi bi-pencil-square" style="color: var(--cars-primary);"></i>
            Bulk Update Rates
        </h3>
        
        <form method="POST">
            <div class="bulk-grid">
                <div class="form-group">
                    <label class="form-label">Apply to</label>
                    <select name="car_type" class="form-control">
                        <option value="all">All Vehicle Types</option>
                        <?php foreach ($carTypes as $type): ?>
                        <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="bulk_action" class="form-control" onchange="toggleBulkValueLabel(this.value)">
                        <option value="increase_percent">Increase by %</option>
                        <option value="decrease_percent">Decrease by %</option>
                        <option value="set_fixed">Set Fixed Price</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" id="bulkValueLabel">Percentage (%)</label>
                    <input type="number" name="bulk_value" class="form-control" step="1" min="0" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="bulk_update_rates" class="btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
    
    <?php if ($vehicleId > 0 && $currentVehicle): ?>
    <!-- Individual Vehicle Rates -->
    <div class="rate-card">
        <div class="rate-header">
            <h3 class="rate-title"><?php echo sanitize($currentVehicle['brand'] . ' ' . $currentVehicle['model']); ?></h3>
            <span class="rate-badge"><?php echo ucfirst($currentVehicle['car_type']); ?></span>
        </div>
        
        <form method="POST">
            <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
            
            <div class="rate-grid">
                <div class="rate-item">
                    <span class="rate-label">Daily Rate</span>
                    <span class="rate-value">
                        <input type="number" name="daily_rate" class="form-control" value="<?php echo $currentVehicle['daily_rate']; ?>" min="0" step="1000" style="width: 120px; text-align: center;">
                    </span>
                    <span class="rate-unit">per day</span>
                </div>
                
                <div class="rate-item">
                    <span class="rate-label">Weekly Rate</span>
                    <span class="rate-value">
                        <input type="number" name="weekly_rate" class="form-control" value="<?php echo $currentVehicle['weekly_rate']; ?>" min="0" step="1000" style="width: 120px; text-align: center;">
                    </span>
                    <span class="rate-unit">per week</span>
                    <?php if ($currentVehicle['weekly_rate'] > 0): ?>
                    <?php $weeklySavings = (($currentVehicle['daily_rate'] * 7) - $currentVehicle['weekly_rate']) / ($currentVehicle['daily_rate'] * 7) * 100; ?>
                    <span class="rate-savings">Save <?php echo round($weeklySavings); ?>%</span>
                    <?php endif; ?>
                </div>
                
                <div class="rate-item">
                    <span class="rate-label">Monthly Rate</span>
                    <span class="rate-value">
                        <input type="number" name="monthly_rate" class="form-control" value="<?php echo $currentVehicle['monthly_rate']; ?>" min="0" step="1000" style="width: 120px; text-align: center;">
                    </span>
                    <span class="rate-unit">per month</span>
                </div>
            </div>
            
            <div class="km-info">
                <div class="km-item">
                    <i class="bi bi-speedometer2"></i>
                    <span>
                        <strong>Free Km:</strong>
                        <input type="number" name="free_km_per_day" class="form-control" value="<?php echo $currentVehicle['free_km_per_day']; ?>" min="0" step="10" style="width: 80px; display: inline-block;"> km/day
                    </span>
                </div>
                
                <div class="km-item">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span>
                        <strong>Excess Km Charge:</strong>
                        <input type="number" name="excess_km_charge" class="form-control" value="<?php echo $currentVehicle['excess_km_charge']; ?>" min="0" step="100" style="width: 100px; display: inline-block;"> RWF/km
                    </span>
                </div>
                
                <div class="km-item">
                    <i class="bi bi-shield-check"></i>
                    <span>
                        <label>
                            <input type="checkbox" name="insurance_included" <?php echo $currentVehicle['insurance_included'] ? 'checked' : ''; ?>>
                            Insurance Included
                        </label>
                    </span>
                </div>
            </div>
            
            <div style="text-align: right; margin-top: 20px;">
                <button type="submit" name="update_rates" class="btn-primary">Update Rates</button>
            </div>
        </form>
    </div>
    
    <?php else: ?>
    <!-- Show all vehicles summary -->
    <div class="rate-card">
        <h3 class="rate-title" style="margin-bottom: 20px;">All Vehicles - Rate Summary</h3>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th>Daily Rate</th>
                        <th>Weekly Rate</th>
                        <th>Monthly Rate</th>
                        <th>Free Km</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td><?php echo sanitize($v['brand'] . ' ' . $v['model']); ?></td>
                        <td><?php echo ucfirst($v['car_type']); ?></td>
                        <td><?php echo formatPrice($v['daily_rate']); ?></td>
                        <td><?php echo $v['weekly_rate'] ? formatPrice($v['weekly_rate']) : '-'; ?></td>
                        <td><?php echo $v['monthly_rate'] ? formatPrice($v['monthly_rate']) : '-'; ?></td>
                        <td><?php echo $v['free_km_per_day']; ?> km/day</td>
                        <td>
                            <a href="?vehicle=<?php echo $v['car_id']; ?>&view=rates" class="btn-outline btn-sm">Edit</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
</div>

<!-- VIEW: SEASONAL PRICING -->
<div id="view-seasonal" style="display: <?php echo $view == 'seasonal' ? 'block' : 'none'; ?>;">
    
    <div style="margin-bottom: 20px; text-align: right;">
        <button class="btn-primary" onclick="openSeasonModal()">
            <i class="bi bi-plus-lg"></i> Add Seasonal Rate
        </button>
    </div>
    
    <?php if (empty($seasonalRates)): ?>
    <div class="empty-state">
        <i class="bi bi-flower1"></i>
        <h3>No seasonal rates</h3>
        <p>Create seasonal pricing for high season, holidays, or special events.</p>
        <button class="btn-primary" onclick="openSeasonModal()">Add Seasonal Rate</button>
    </div>
    <?php else: ?>
    <div class="seasons-grid">
        <?php foreach ($seasonalRates as $season): 
            $applicable = json_decode($season['applicable_to'], true);
            $carTypeText = isset($applicable['car_type']) && $applicable['car_type'] !== 'all' 
                ? ucfirst($applicable['car_type']) 
                : 'All vehicle types';
        ?>
        <div class="season-card">
            <div class="season-header">
                <h4 class="season-name"><?php echo sanitize($season['season_name']); ?></h4>
                <span class="season-badge"><?php echo $season['is_active'] ? 'Active' : 'Inactive'; ?></span>
            </div>
            
            <div class="season-body">
                <div class="season-dates">
                    <i class="bi bi-calendar-range"></i>
                    <?php echo date('M d, Y', strtotime($season['start_date'])); ?> - 
                    <?php echo date('M d, Y', strtotime($season['end_date'])); ?>
                </div>
                
                <div class="season-multiplier">
                    <?php echo $season['price_multiplier']; ?>x <small>multiplier</small>
                </div>
                
                <div class="season-applicable">
                    <i class="bi bi-car-front"></i> Applies to: <?php echo $carTypeText; ?>
                </div>
                
                <div class="season-actions">
                    <form method="POST" style="display: inline; flex: 1;">
                        <input type="hidden" name="season_id" value="<?php echo $season['season_id']; ?>">
                        <button type="submit" name="delete_season" class="btn-outline btn-sm" style="width: 100%;" onclick="return confirm('Delete this seasonal rate?')">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
</div>

<!-- VIEW: SPECIAL OFFERS -->
<div id="view-offers" style="display: <?php echo $view == 'offers' ? 'block' : 'none'; ?>;">
    
    <div style="margin-bottom: 20px; text-align: right;">
        <button class="btn-primary" onclick="openOfferModal()">
            <i class="bi bi-plus-lg"></i> Add Special Offer
        </button>
    </div>
    
    <?php if (empty($specialOffers)): ?>
    <div class="empty-state">
        <i class="bi bi-tag"></i>
        <h3>No special offers</h3>
        <p>Create promotions to attract more customers.</p>
        <button class="btn-primary" onclick="openOfferModal()">Add Special Offer</button>
    </div>
    <?php else: ?>
    <div class="offers-grid">
        <?php foreach ($specialOffers as $offer): 
            $isActive = $offer['is_active'];
            $days = json_decode($offer['days_of_week'], true);
            $vehicles = json_decode($offer['applicable_vehicles'], true);
        ?>
        <div class="offer-card <?php echo $isActive ? '' : 'inactive'; ?>">
            <div class="offer-header">
                <h4 class="offer-name"><?php echo sanitize($offer['offer_name']); ?></h4>
                <span class="offer-status <?php echo $isActive ? 'active' : 'inactive'; ?>">
                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            
            <div class="offer-body">
                <div class="offer-discount">
                    <?php if ($offer['offer_type'] == 'percentage'): ?>
                        <?php echo $offer['discount_value']; ?>% OFF
                    <?php elseif ($offer['offer_type'] == 'fixed'): ?>
                        <?php echo formatPrice($offer['discount_value']); ?> OFF
                    <?php elseif ($offer['offer_type'] == 'free_day'): ?>
                        <?php echo $offer['discount_value']; ?> Free Day(s)
                    <?php else: ?>
                        Free Upgrade
                    <?php endif; ?>
                    <small>discount</small>
                </div>
                
                <div class="offer-dates">
                    <i class="bi bi-calendar3"></i>
                    <?php echo date('M d', strtotime($offer['start_date'])); ?> - 
                    <?php echo date('M d', strtotime($offer['end_date'])); ?>
                </div>
                
                <?php if (!empty($days)): ?>
                <div class="offer-days">
                    <?php foreach ($days as $day): ?>
                    <span class="day-tag"><?php echo ucfirst(substr($day, 0, 3)); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($offer['min_days'] > 1): ?>
                <div style="font-size: 0.75rem; margin-bottom: 8px;">
                    <i class="bi bi-clock"></i> Minimum <?php echo $offer['min_days']; ?> days
                </div>
                <?php endif; ?>
                
                <?php if ($offer['promo_code']): ?>
                <div class="offer-code">
                    <i class="bi bi-ticket"></i> Code: <?php echo $offer['promo_code']; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($offer['usage_limit'] > 0): ?>
                <div class="offer-usage">
                    Used: <?php echo $offer['used_count']; ?>/<?php echo $offer['usage_limit']; ?>
                </div>
                <?php endif; ?>
                
                <div class="offer-actions">
                    <form method="POST" style="display: inline; flex: 1;">
                        <input type="hidden" name="offer_id" value="<?php echo $offer['offer_id']; ?>">
                        <input type="hidden" name="current_status" value="<?php echo $offer['is_active']; ?>">
                        <button type="submit" name="toggle_offer" class="btn-outline btn-sm" style="width: 100%;">
                            <i class="bi bi-<?php echo $isActive ? 'pause-circle' : 'play-circle'; ?>"></i>
                            <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline; flex: 1;">
                        <input type="hidden" name="offer_id" value="<?php echo $offer['offer_id']; ?>">
                        <button type="submit" name="delete_offer" class="btn-outline btn-sm" style="width: 100%; color: var(--cars-danger);" onclick="return confirm('Delete this offer?')">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
</div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Seasonal Rate Modal -->
<div class="modal" id="seasonModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Seasonal Rate</h3>
            <button class="modal-close" onclick="closeModal('seasonModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Season Name</label>
                        <input type="text" name="season_name" class="form-control" placeholder="e.g., High Season 2024, Christmas, Low Season" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Price Multiplier</label>
                        <input type="number" name="price_multiplier" class="form-control" value="1.2" min="0.5" max="3" step="0.1" required>
                        <div class="form-text">1.0 = normal, 1.2 = +20%, 0.8 = -20%</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Apply to Vehicle Type</label>
                        <select name="car_type" class="form-control">
                            <option value="all">All Types</option>
                            <?php foreach ($carTypes as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('seasonModal')">Cancel</button>
                <button type="submit" name="create_season" class="btn-primary">Create Season</button>
            </div>
        </form>
    </div>
</div>

<!-- Special Offer Modal -->
<div class="modal" id="offerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Special Offer</h3>
            <button class="modal-close" onclick="closeModal('offerModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Offer Name</label>
                        <input type="text" name="offer_name" class="form-control" placeholder="e.g., Early Bird, Last Minute, Weekend Special" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Offer Type</label>
                        <select name="offer_type" class="form-control" onchange="toggleOfferType(this.value)">
                            <option value="percentage">Percentage Discount</option>
                            <option value="fixed">Fixed Amount Off</option>
                            <option value="free_day">Free Day</option>
                            <option value="upgrade">Free Upgrade</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" id="offerValueLabel">Discount Value (%)</label>
                        <input type="number" name="discount_value" id="offerValue" class="form-control" min="0" step="1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="offer_start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="offer_end_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Minimum Days</label>
                        <input type="number" name="min_days" class="form-control" value="1" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Promo Code (Optional)</label>
                        <input type="text" name="promo_code" class="form-control" placeholder="e.g., SUMMER10">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Usage Limit (0 = unlimited)</label>
                        <input type="number" name="usage_limit" class="form-control" value="0" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Valid on these days</label>
                    <div class="days-of-week">
                        <?php foreach ($daysOfWeek as $key => $day): ?>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="<?php echo $key; ?>">
                            <?php echo substr($day, 0, 3); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-text">Leave empty for all days</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific vehicles (optional)</label>
                    <div class="vehicle-selector-grid">
                        <?php foreach ($vehicles as $v): ?>
                        <label>
                            <input type="checkbox" name="offer_vehicles[]" value="<?php echo $v['car_id']; ?>">
                            <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('offerModal')">Cancel</button>
                <button type="submit" name="create_offer" class="btn-primary">Create Offer</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// VIEW SWITCHING
// ============================================
function switchView(view) {
    const url = new URL(window.location.href);
    url.searchParams.set('view', view);
    if (view !== 'rates') {
        url.searchParams.delete('vehicle');
    }
    window.location.href = url.toString();
}

// ============================================
// VEHICLE SELECTION
// ============================================
function changeVehicle(vehicleId) {
    const url = new URL(window.location.href);
    if (vehicleId > 0) {
        url.searchParams.set('vehicle', vehicleId);
    } else {
        url.searchParams.delete('vehicle');
    }
    url.searchParams.set('view', 'rates');
    window.location.href = url.toString();
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

function openSeasonModal() {
    openModal('seasonModal');
}

function openOfferModal() {
    openModal('offerModal');
}

// ============================================
// FORM HELPERS
// ============================================
function toggleBulkValueLabel(action) {
    const label = document.getElementById('bulkValueLabel');
    if (action === 'set_fixed') {
        label.textContent = 'Fixed Price (RWF)';
    } else {
        label.textContent = 'Percentage (%)';
    }
}

function toggleOfferType(type) {
    const label = document.getElementById('offerValueLabel');
    const input = document.getElementById('offerValue');
    
    if (type === 'percentage') {
        label.textContent = 'Discount Value (%)';
        input.step = '1';
        input.max = '100';
    } else if (type === 'fixed') {
        label.textContent = 'Discount Amount (RWF)';
        input.step = '1000';
        input.max = '';
    } else if (type === 'free_day') {
        label.textContent = 'Number of Free Days';
        input.step = '1';
        input.max = '7';
    } else if (type === 'upgrade') {
        label.textContent = 'Upgrade to';
        input.type = 'text';
        input.placeholder = 'e.g., SUV, Luxury';
    }
}
</script>

