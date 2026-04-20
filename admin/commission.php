<?php
$pageTitle = 'Commission Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle commission settings updates
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Update global commission settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_global') {
    $default_commission = floatval($_POST['default_commission'] ?? 15);
    $stay_commission = floatval($_POST['stay_commission'] ?? 10);
    $car_commission = floatval($_POST['car_commission'] ?? 12);
    $attraction_commission = floatval($_POST['attraction_commission'] ?? 15);
    
    // Update stays commission
    $stmt = $db->prepare("UPDATE stays SET commission_rate = ? WHERE commission_rate IS NOT NULL");
    $stmt->execute([$stay_commission]);
    
    // Update car rentals commission
    $stmt = $db->prepare("UPDATE car_rentals SET commission_rate = ? WHERE commission_rate IS NOT NULL");
    $stmt->execute([$car_commission]);
    
    // Update attractions commission
    $stmt = $db->prepare("UPDATE attractions SET commission_rate = ? WHERE commission_rate IS NOT NULL");
    $stmt->execute([$attraction_commission]);
    
    $_SESSION['success'] = "Commission rates updated successfully";
    header('Location: commission.php');
    exit;
}

// Update individual vendor commission
if ($action === 'update_vendor' && isset($_POST['vendor_id'])) {
    $vendorId = intval($_POST['vendor_id']);
    $vendorType = sanitize($_POST['vendor_type']);
    $commissionRate = floatval($_POST['commission_rate']);
    
    if ($vendorType === 'stay') {
        $stmt = $db->prepare("UPDATE stays SET commission_rate = ? WHERE stay_id = ?");
        $stmt->execute([$commissionRate, $vendorId]);
    } elseif ($vendorType === 'car') {
        $stmt = $db->prepare("UPDATE car_rentals SET commission_rate = ? WHERE rental_id = ?");
        $stmt->execute([$commissionRate, $vendorId]);
    } elseif ($vendorType === 'attraction') {
        $stmt = $db->prepare("UPDATE attractions SET commission_rate = ? WHERE attraction_id = ?");
        $stmt->execute([$commissionRate, $vendorId]);
    }
    
    $_SESSION['success'] = "Vendor commission rate updated successfully";
    header('Location: commission.php');
    exit;
}

// Get current global commission rates
$globalRates = [
    'stay' => $db->query("SELECT AVG(commission_rate) as avg_rate FROM stays")->fetch()['avg_rate'] ?? 10,
    'car' => $db->query("SELECT AVG(commission_rate) as avg_rate FROM car_rentals")->fetch()['avg_rate'] ?? 12,
    'attraction' => $db->query("SELECT AVG(commission_rate) as avg_rate FROM attractions")->fetch()['avg_rate'] ?? 15
];

// Get commission statistics
$stats = $db->query("
    SELECT 
        (SELECT COALESCE(SUM(commission_amount), 0) FROM bookings WHERE status IN ('confirmed', 'completed')) as total_commission_earned,
        (SELECT COALESCE(SUM(commission_amount), 0) FROM bookings WHERE status IN ('confirmed', 'completed') AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) as monthly_commission,
        (SELECT COALESCE(SUM(commission_amount), 0) FROM bookings WHERE status IN ('confirmed', 'completed') AND WEEK(created_at) = WEEK(CURDATE())) as weekly_commission,
        (SELECT COALESCE(SUM(commission_amount), 0) FROM bookings WHERE status IN ('confirmed', 'completed') AND DATE(created_at) = CURDATE()) as today_commission,
        (SELECT COUNT(*) FROM stays WHERE commission_rate < 10) as low_commission_stays,
        (SELECT COUNT(*) FROM stays WHERE commission_rate > 15) as high_commission_stays,
        (SELECT AVG(commission_rate) FROM stays) as avg_stay_commission,
        (SELECT AVG(commission_rate) FROM car_rentals) as avg_car_commission,
        (SELECT AVG(commission_rate) FROM attractions) as avg_attraction_commission
")->fetch();

// Get commission distribution by booking type
$commissionByType = $db->query("
    SELECT 
        booking_type,
        COUNT(*) as booking_count,
        COALESCE(SUM(commission_amount), 0) as total_commission,
        COALESCE(AVG(commission_amount), 0) as avg_commission
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    GROUP BY booking_type
")->fetchAll();

// Get top earning vendors by commission
$topVendors = $db->query("
    SELECT 
        'stay' as type,
        s.stay_id as id,
        s.stay_name as name,
        s.commission_rate,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.commission_amount), 0) as total_commission
    FROM stays s
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id AND b.status IN ('confirmed', 'completed')
    GROUP BY s.stay_id
    HAVING total_commission > 0
    ORDER BY total_commission DESC
    LIMIT 5
")->fetchAll();

$topCarVendors = $db->query("
    SELECT 
        'car' as type,
        cr.rental_id as id,
        cr.company_name as name,
        cr.commission_rate,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.commission_amount), 0) as total_commission
    FROM car_rentals cr
    LEFT JOIN car_fleet cf ON cr.rental_id = cf.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id AND b.status IN ('confirmed', 'completed')
    GROUP BY cr.rental_id
    HAVING total_commission > 0
    ORDER BY total_commission DESC
    LIMIT 5
")->fetchAll();

$topAttractionVendors = $db->query("
    SELECT 
        'attraction' as type,
        a.attraction_id as id,
        a.attraction_name as name,
        a.commission_rate,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.commission_amount), 0) as total_commission
    FROM attractions a
    LEFT JOIN attraction_tiers at ON a.attraction_id = at.attraction_id
    LEFT JOIN bookings b ON at.tier_id = b.attraction_tier_id AND b.status IN ('confirmed', 'completed')
    GROUP BY a.attraction_id
    HAVING total_commission > 0
    ORDER BY total_commission DESC
    LIMIT 5
")->fetchAll();

$topCommissionVendors = array_merge($topVendors, $topCarVendors, $topAttractionVendors);
usort($topCommissionVendors, function($a, $b) {
    return $b['total_commission'] - $a['total_commission'];
});
$topCommissionVendors = array_slice($topCommissionVendors, 0, 10);

// Get monthly commission trend
$monthlyTrend = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%b %Y') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(commission_amount), 0) as commission,
        COUNT(*) as booking_count
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$commissions = [];
$bookingCounts = [];
foreach ($monthlyTrend as $data) {
    $months[] = $data['month'];
    $commissions[] = floatval($data['commission']);
    $bookingCounts[] = $data['booking_count'];
}

// Get commission rate distribution
$rateDistribution = $db->query("
    SELECT 
        CASE 
            WHEN commission_rate <= 5 THEN '0-5%'
            WHEN commission_rate <= 10 THEN '6-10%'
            WHEN commission_rate <= 15 THEN '11-15%'
            WHEN commission_rate <= 20 THEN '16-20%'
            ELSE '20%+'
        END as rate_range,
        COUNT(*) as count,
        'stay' as type
    FROM stays
    GROUP BY rate_range
    UNION ALL
    SELECT 
        CASE 
            WHEN commission_rate <= 5 THEN '0-5%'
            WHEN commission_rate <= 10 THEN '6-10%'
            WHEN commission_rate <= 15 THEN '11-15%'
            WHEN commission_rate <= 20 THEN '16-20%'
            ELSE '20%+'
        END as rate_range,
        COUNT(*) as count,
        'car' as type
    FROM car_rentals
    GROUP BY rate_range
    UNION ALL
    SELECT 
        CASE 
            WHEN commission_rate <= 5 THEN '0-5%'
            WHEN commission_rate <= 10 THEN '6-10%'
            WHEN commission_rate <= 15 THEN '11-15%'
            WHEN commission_rate <= 20 THEN '16-20%'
            ELSE '20%+'
        END as rate_range,
        COUNT(*) as count,
        'attraction' as type
    FROM attractions
    GROUP BY rate_range
")->fetchAll();

// Get vendors with custom commission rates (different from global)
$customRateVendors = $db->query("
    SELECT 'stay' as type, stay_id as id, stay_name as name, commission_rate, 
           (SELECT AVG(commission_rate) FROM stays) as global_rate
    FROM stays 
    WHERE commission_rate != (SELECT AVG(commission_rate) FROM stays)
    UNION ALL
    SELECT 'car' as type, rental_id as id, company_name as name, commission_rate,
           (SELECT AVG(commission_rate) FROM car_rentals) as global_rate
    FROM car_rentals 
    WHERE commission_rate != (SELECT AVG(commission_rate) FROM car_rentals)
    UNION ALL
    SELECT 'attraction' as type, attraction_id as id, attraction_name as name, commission_rate,
           (SELECT AVG(commission_rate) FROM attractions) as global_rate
    FROM attractions 
    WHERE commission_rate != (SELECT AVG(commission_rate) FROM attractions)
    LIMIT 20
")->fetchAll();
?>

<style>
/* Commission Management Styles */
.commission-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
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
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    font-size: 1.125rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }

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

/* Global Settings Section */
.settings-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
    margin-bottom: 24px;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--booking-text);
}

.section-title i {
    color: var(--booking-blue);
}

.global-form {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 6px;
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.input-group {
    display: flex;
    align-items: center;
    gap: 4px;
}

.input-group .form-control {
    flex: 1;
}

.input-group span {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.save-btn {
    padding: 10px 24px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    align-self: flex-end;
}

.save-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

/* Chart Section */
.chart-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-container {
    height: 300px;
}

/* Distribution Grid */
.distribution-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.distribution-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.distribution-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.distribution-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.distribution-body {
    padding: 16px;
}

.distribution-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.distribution-label {
    width: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.distribution-bar {
    flex: 1;
    height: 8px;
    background: var(--booking-gray-light);
    border-radius: 4px;
    overflow: hidden;
}

.distribution-fill {
    height: 100%;
    background: var(--booking-blue);
    border-radius: 4px;
    transition: width 0.3s;
}

.distribution-value {
    width: 80px;
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-align: right;
}

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
    margin-bottom: 24px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.data-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.data-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.data-table tr:hover td {
    background: var(--booking-gray-light);
}

.vendor-type {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.type-stay { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.type-car { background: rgba(147,51,234,0.1); color: #9333ea; }
.type-attraction { background: rgba(255,140,0,0.1); color: var(--booking-warning); }

.commission-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.commission-low {
    background: #e6f4ea;
    color: var(--booking-success);
}

.commission-medium {
    background: #fff4e6;
    color: var(--booking-warning);
}

.commission-high {
    background: #fce8e8;
    color: var(--booking-danger);
}

.edit-commission-form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.edit-commission-input {
    width: 70px;
    padding: 4px 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    text-align: center;
}

.edit-commission-btn {
    padding: 4px 8px;
    background: var(--booking-blue);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.5625rem;
    cursor: pointer;
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

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .global-form {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .global-form {
        grid-template-columns: 1fr;
    }
    .distribution-grid {
        grid-template-columns: 1fr;
    }
    .save-btn {
        width: 100%;
    }
}
</style>

<div class="commission-header">
    <div class="page-title">
        <h1></h1>
    </div>
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

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_commission_earned'] ?? 0); ?></div>
        <div class="stat-label">Total Commission Earned</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-calendar-month"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['monthly_commission'] ?? 0); ?></div>
        <div class="stat-label">This Month</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-calendar-week"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['weekly_commission'] ?? 0); ?></div>
        <div class="stat-label">This Week</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-calendar-day"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['today_commission'] ?? 0); ?></div>
        <div class="stat-label">Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-building"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['low_commission_stays'] ?? 0); ?></div>
        <div class="stat-label">Low Commission (&lt;10%)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-building"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['high_commission_stays'] ?? 0); ?></div>
        <div class="stat-label">High Commission (&gt;15%)</div>
    </div>
</div>

<!-- Global Commission Settings -->
<div class="settings-section">
    <div class="section-title">
        <i class="bi bi-gear"></i>
        Global Commission Settings
    </div>
    <form method="POST" action="commission.php" class="global-form">
        <input type="hidden" name="action" value="update_global">
        <div class="form-group">
            <label>Default Commission Rate</label>
            <div class="input-group">
                <input type="number" name="default_commission" class="form-control" step="0.5" min="0" max="100" value="15">
                <span>%</span>
            </div>
            <small style="font-size: 0.625rem; color: var(--booking-text-light);">Default for new vendors</small>
        </div>
        <div class="form-group">
            <label>Stays / Hotels Commission</label>
            <div class="input-group">
                <input type="number" name="stay_commission" class="form-control" step="0.5" min="0" max="100" value="<?php echo round($globalRates['stay'], 1); ?>">
                <span>%</span>
            </div>
            <small style="font-size: 0.625rem; color: var(--booking-text-light);">Current avg: <?php echo round($stats['avg_stay_commission'], 1); ?>%</small>
        </div>
        <div class="form-group">
            <label>Car Rentals Commission</label>
            <div class="input-group">
                <input type="number" name="car_commission" class="form-control" step="0.5" min="0" max="100" value="<?php echo round($globalRates['car'], 1); ?>">
                <span>%</span>
            </div>
            <small style="font-size: 0.625rem; color: var(--booking-text-light);">Current avg: <?php echo round($stats['avg_car_commission'], 1); ?>%</small>
        </div>
        <div class="form-group">
            <label>Experiences Commission</label>
            <div class="input-group">
                <input type="number" name="attraction_commission" class="form-control" step="0.5" min="0" max="100" value="<?php echo round($globalRates['attraction'], 1); ?>">
                <span>%</span>
            </div>
            <small style="font-size: 0.625rem; color: var(--booking-text-light);">Current avg: <?php echo round($stats['avg_attraction_commission'], 1); ?>%</small>
        </div>
        <button type="submit" class="save-btn">Apply to All</button>
    </form>
    <div style="margin-top: 16px; padding: 12px; background: var(--booking-gray-light); border-radius: var(--radius-sm);">
        <i class="bi bi-info-circle"></i>
        <span style="font-size: 0.6875rem;">Note: This will update ALL existing vendors to these commission rates. Vendors with custom rates will be overwritten.</span>
    </div>
</div>

<!-- Commission Trend Chart -->
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Commission Trend (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Commission Earned
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Bookings
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<!-- Commission Distribution -->
<div class="distribution-grid">
    <div class="distribution-card">
        <div class="distribution-header">
            <h3><i class="bi bi-pie-chart"></i> Commission by Booking Type</h3>
        </div>
        <div class="distribution-body">
            <?php foreach ($commissionByType as $type): 
                $typeLabel = $type['booking_type'] == 'stay' ? 'Stays' : ($type['booking_type'] == 'car_rental' ? 'Car Rentals' : 'Experiences');
                $typeIcon = $type['booking_type'] == 'stay' ? '🏨' : ($type['booking_type'] == 'car_rental' ? '🚗' : '🎟️');
            ?>
            <div class="distribution-item">
                <div class="distribution-label"><?php echo $typeIcon; ?> <?php echo $typeLabel; ?></div>
                <div class="distribution-bar">
                    <div class="distribution-fill" style="width: <?php echo $stats['total_commission_earned'] > 0 ? ($type['total_commission'] / $stats['total_commission_earned']) * 100 : 0; ?>%"></div>
                </div>
                <div class="distribution-value"><?php echo formatPrice($type['total_commission']); ?></div>
            </div>
            <div style="font-size: 0.625rem; color: var(--booking-text-light); margin-top: -8px; margin-bottom: 12px; padding-left: 100px;">
                <?php echo number_format($type['booking_count']); ?> bookings • Avg <?php echo formatPrice($type['avg_commission']); ?> per booking
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="distribution-card">
        <div class="distribution-header">
            <h3><i class="bi bi-bar-chart"></i> Commission Rate Distribution</h3>
        </div>
        <div class="distribution-body">
            <?php
            $rateGroups = [
                '0-5%' => ['stay' => 0, 'car' => 0, 'attraction' => 0],
                '6-10%' => ['stay' => 0, 'car' => 0, 'attraction' => 0],
                '11-15%' => ['stay' => 0, 'car' => 0, 'attraction' => 0],
                '16-20%' => ['stay' => 0, 'car' => 0, 'attraction' => 0],
                '20%+' => ['stay' => 0, 'car' => 0, 'attraction' => 0]
            ];
            foreach ($rateDistribution as $rate) {
                if (isset($rateGroups[$rate['rate_range']])) {
                    $rateGroups[$rate['rate_range']][$rate['type']] = $rate['count'];
                }
            }
            ?>
            <?php foreach ($rateGroups as $range => $counts): ?>
            <div class="distribution-item">
                <div class="distribution-label"><?php echo $range; ?></div>
                <div class="distribution-bar">
                    <?php 
                    $total = array_sum($counts);
                    $maxTotal = max(array_map('array_sum', $rateGroups));
                    $width = $maxTotal > 0 ? ($total / $maxTotal) * 100 : 0;
                    ?>
                    <div class="distribution-fill" style="width: <?php echo $width; ?>%; background: linear-gradient(90deg, #003b95, #0066ff);"></div>
                </div>
                <div class="distribution-value"><?php echo $total; ?> vendors</div>
            </div>
            <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: -8px; margin-bottom: 12px; padding-left: 100px;">
                🏨 <?php echo $counts['stay']; ?> • 🚗 <?php echo $counts['car']; ?> • 🎟️ <?php echo $counts['attraction']; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Top Commission Contributors -->
<div class="table-container">
    <div class="distribution-header">
        <h3><i class="bi bi-trophy"></i> Top Commission Contributors</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Vendor</th>
                <th>Type</th>
                <th>Commission Rate</th>
                <th>Bookings</th>
                <th>Total Commission</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($topCommissionVendors)): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 40px;">
                    <i class="bi bi-trophy" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px;">No commission data available yet</p>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($topCommissionVendors as $index => $vendor): 
                $typeClass = $vendor['type'] == 'stay' ? 'type-stay' : ($vendor['type'] == 'car' ? 'type-car' : 'type-attraction');
                $typeIcon = $vendor['type'] == 'stay' ? '🏨' : ($vendor['type'] == 'car' ? '🚗' : '🎟️');
                $commissionClass = $vendor['commission_rate'] <= 10 ? 'commission-low' : ($vendor['commission_rate'] <= 15 ? 'commission-medium' : 'commission-high');
            ?>
            <tr>
                <td>
                    <?php if ($index == 0): ?>🥇
                    <?php elseif ($index == 1): ?>🥈
                    <?php elseif ($index == 2): ?>🥉
                    <?php else: echo $index + 1; endif; ?>
                </td>
                <td><strong><?php echo sanitize($vendor['name']); ?></strong></td>
                <td><span class="vendor-type <?php echo $typeClass; ?>"><?php echo $typeIcon; ?> <?php echo ucfirst($vendor['type']); ?></span></td>
                <td><span class="commission-badge <?php echo $commissionClass; ?>"><?php echo $vendor['commission_rate']; ?>%</span></td>
                <td><?php echo number_format($vendor['booking_count']); ?></td>
                <td style="font-weight: 700; color: var(--booking-success);"><?php echo formatPrice($vendor['total_commission']); ?></td>
                <td>
                    <button class="edit-commission-btn" onclick="editCommission(<?php echo $vendor['id']; ?>, '<?php echo $vendor['type']; ?>', <?php echo $vendor['commission_rate']; ?>, '<?php echo addslashes($vendor['name']); ?>')">
                        Edit Rate
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Vendors with Custom Commission Rates -->
<?php if (!empty($customRateVendors)): ?>
<div class="table-container">
    <div class="distribution-header">
        <h3><i class="bi bi-star"></i> Vendors with Custom Commission Rates</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Vendor</th>
                <th>Type</th>
                <th>Current Rate</th>
                <th>Global Average</th>
                <th>Difference</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customRateVendors as $vendor): 
                $typeClass = $vendor['type'] == 'stay' ? 'type-stay' : ($vendor['type'] == 'car' ? 'type-car' : 'type-attraction');
                $typeIcon = $vendor['type'] == 'stay' ? '🏨' : ($vendor['type'] == 'car' ? '🚗' : '🎟️');
                $diff = $vendor['commission_rate'] - $vendor['global_rate'];
                $diffClass = $diff > 0 ? 'commission-high' : 'commission-low';
                $diffIcon = $diff > 0 ? '▲' : '▼';
            ?>
            <tr>
                <td><strong><?php echo sanitize($vendor['name']); ?></strong></td>
                <td><span class="vendor-type <?php echo $typeClass; ?>"><?php echo $typeIcon; ?> <?php echo ucfirst($vendor['type']); ?></span></td>
                <td><span class="commission-badge <?php echo $vendor['commission_rate'] <= 10 ? 'commission-low' : ($vendor['commission_rate'] <= 15 ? 'commission-medium' : 'commission-high'); ?>"><?php echo $vendor['commission_rate']; ?>%</span></td>
                <td><?php echo round($vendor['global_rate'], 1); ?>%</td>
                <td><span class="commission-badge <?php echo $diffClass; ?>"><?php echo $diffIcon; ?> <?php echo abs($diff); ?>%</span></td>
                <td>
                    <button class="edit-commission-btn" onclick="editCommission(<?php echo $vendor['id']; ?>, '<?php echo $vendor['type']; ?>', <?php echo $vendor['commission_rate']; ?>, '<?php echo addslashes($vendor['name']); ?>')">
                        Edit Rate
                    </button>
                 </div>
                </tr>
            <?php endforeach; ?>
        </tbody>
     </div>
</div>
<?php endif; ?>

<!-- Edit Commission Modal -->
<div id="editCommissionModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
    <div class="modal-container" style="background: var(--booking-white); border-radius: var(--radius-lg); width: 90%; max-width: 400px;">
        <form method="POST" action="commission.php">
            <input type="hidden" name="action" value="update_vendor">
            <input type="hidden" name="vendor_id" id="editVendorId">
            <input type="hidden" name="vendor_type" id="editVendorType">
            <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--booking-border); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Edit Commission Rate</h3>
                <button type="button" class="modal-close" onclick="closeEditModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); margin-bottom: 4px;">Vendor</label>
                    <input type="text" id="editVendorName" class="form-control" disabled style="background: var(--booking-gray-light);">
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); margin-bottom: 4px;">Commission Rate (%)</label>
                    <div class="input-group" style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="commission_rate" id="editCommissionRate" class="form-control" step="0.5" min="0" max="100" required style="flex: 1;">
                        <span>%</span>
                    </div>
                </div>
                <div style="padding: 10px; background: var(--booking-gray-light); border-radius: var(--radius-sm); margin-top: 12px;">
                    <i class="bi bi-info-circle"></i>
                    <span style="font-size: 0.625rem;">This rate will apply to all future bookings from this vendor.</span>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid var(--booking-border); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="chat-action-btn" onclick="closeEditModal()" style="padding: 8px 16px;">Cancel</button>
                <button type="submit" class="send-btn" style="padding: 8px 24px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Commission Trend Chart
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Commission Earned',
                data: <?php echo json_encode($commissions); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-commission'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode($bookingCounts); ?>,
                borderColor: '#008009',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                tension: 0.4,
                yAxisID: 'y-bookings'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Commission Earned') {
                            return 'Commission: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
            'y-commission': {
                type: 'linear',
                display: true,
                position: 'left',
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    },
                    font: { size: 9 }
                }
            },
            'y-bookings': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: {
                    stepSize: 1,
                    font: { size: 9 }
                }
            }
        }
    }
});

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

// Edit Commission Modal
function editCommission(id, type, currentRate, name) {
    document.getElementById('editVendorId').value = id;
    document.getElementById('editVendorType').value = type;
    document.getElementById('editVendorName').value = name;
    document.getElementById('editCommissionRate').value = currentRate;
    document.getElementById('editCommissionModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editCommissionModal').style.display = 'none';
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('editCommissionModal');
    if (e.target === modal) {
        closeEditModal();
    }
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>