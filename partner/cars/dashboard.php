<?php
$pageTitle = 'Dashboard';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET DASHBOARD DATA
// ============================================

// Get rental company details
$stmt = $db->prepare("
    SELECT * FROM car_rentals 
    WHERE owner_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$companies = $stmt->fetchAll();

$companyId = null;
$companyName = '';
if (!empty($companies)) {
    $companyId = $companies[0]['rental_id'];
    $companyName = $companies[0]['company_name'];
}

// Get fleet statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_vehicles,
        SUM(cf.quantity_available) as total_cars,
        AVG(cf.daily_rate) as avg_daily_rate,
        MIN(cf.daily_rate) as min_rate,
        MAX(cf.daily_rate) as max_rate,
        SUM(CASE WHEN cf.is_active = 1 THEN 1 ELSE 0 END) as active_vehicles,
        SUM(cf.quantity_available) - COUNT(*) as available_now
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ? AND cr.is_active = 1
");
$stmt->execute([$userId]);
$fleetStats = $stmt->fetch();

// Get booking statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN DATE(b.created_at) = CURDATE() THEN b.total_amount END), 0) as today_revenue,
        COALESCE(SUM(CASE WHEN b.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN b.total_amount END), 0) as week_revenue,
        COALESCE(SUM(CASE WHEN b.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN b.total_amount END), 0) as month_revenue
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
");
$stmt->execute([$userId]);
$bookingStats = $stmt->fetch();

// Get today's pickups
$stmt = $db->prepare("
    SELECT b.*, cf.brand, cf.model, cf.car_type, u.first_name, u.last_name, u.phone
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE cr.owner_id = ? 
    AND DATE(b.pickup_date) = CURDATE()
    AND b.status IN ('confirmed')
    ORDER BY b.pickup_date ASC
");
$stmt->execute([$userId]);
$todayPickups = $stmt->fetchAll();

// Get today's returns
$stmt = $db->prepare("
    SELECT b.*, cf.brand, cf.model, cf.car_type, u.first_name, u.last_name
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE cr.owner_id = ? 
    AND DATE(b.return_date) = CURDATE()
    AND b.status IN ('confirmed', 'checked_out')
    ORDER BY b.return_date ASC
");
$stmt->execute([$userId]);
$todayReturns = $stmt->fetchAll();

// Get low stock alerts
$stmt = $db->prepare("
    SELECT cf.*, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ? AND cf.quantity_available < 3 AND cf.quantity_available > 0
    ORDER BY cf.quantity_available ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$lowStock = $stmt->fetchAll();

// Get recent bookings
$stmt = $db->prepare("
    SELECT b.*, cf.brand, cf.model, cf.car_type, u.first_name, u.last_name
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE cr.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll();

// Get fleet by type
$stmt = $db->prepare("
    SELECT 
        car_type,
        COUNT(*) as count,
        SUM(quantity_available) as total,
        AVG(daily_rate) as avg_rate
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ? AND cf.is_active = 1
    GROUP BY car_type
    ORDER BY count DESC
");
$stmt->execute([$userId]);
$fleetByType = $stmt->fetchAll();

// Get monthly revenue for chart
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%b') as month,
        SUM(b.total_amount) as revenue,
        COUNT(*) as bookings
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ? AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY b.created_at ASC
");
$stmt->execute([$userId]);
$monthlyRevenue = $stmt->fetchAll();

// Get upcoming check-ins (next 7 days)
$stmt = $db->prepare("
    SELECT b.*, cf.brand, cf.model, u.first_name, u.last_name
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE cr.owner_id = ? 
    AND b.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND b.status IN ('confirmed')
    ORDER BY b.pickup_date ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$upcomingPickups = $stmt->fetchAll();

// Get recent reviews (for car rentals)
$stmt = $db->prepare("
    SELECT r.*, cr.company_name, u.first_name, u.last_name
    FROM reviews r
    JOIN car_rentals cr ON r.rental_id = cr.rental_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE cr.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentReviews = $stmt->fetchAll();

// Utilization rate
$totalCars = $fleetStats['total_cars'] ?? 1;
$availableNow = $fleetStats['available_now'] ?? 0;
$utilizationRate = $totalCars > 0 ? round((($totalCars - $availableNow) / $totalCars) * 100, 1) : 0;

// Car types for icons
$carTypeIcons = [
    'economy' => 'bi-car-front',
    'compact' => 'bi-car-front',
    'mid_size' => 'bi-car-front',
    'full_size' => 'bi-car-front',
    'suv' => 'bi-truck',
    'luxury' => 'bi-stars',
    'van' => 'bi-bus-front',
    '4x4' => 'bi-globe'
];
?>

<style>
/* Dashboard Specific Styles - Matching Stays exactly */
.welcome-card {
    background: linear-gradient(135deg, var(--cars-primary), var(--cars-dark));
    color: white;
    padding: 30px;
    border-radius: var(--radius-lg);
    margin-bottom: 24px;
}

.welcome-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.welcome-text {
    font-size: 0.9375rem;
    opacity: 0.9;
    margin-bottom: 20px;
}

.quick-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.quick-action-btn {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    padding: 10px 20px;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.quick-action-btn:hover {
    background: rgba(255,255,255,0.25);
    transform: translateY(-1px);
}

/* Stats Grid - 4 cards per row */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 24px;
    border: 1px solid var(--border-gray);
    transition: all 0.2s;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stat-icon.orange { background: var(--cars-light); color: var(--cars-primary); }
.stat-icon.green { background: #e6f4ea; color: var(--cars-success); }
.stat-icon.blue { background: #e1f5fe; color: #0288d1; }
.stat-icon.purple { background: #f3e8ff; color: #9333ea; }

.stat-trend {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 100px;
    background: #e6f4ea;
    color: var(--cars-success);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--text-light);
    font-weight: 500;
}

.stat-footer {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-gray);
    font-size: 0.875rem;
    color: var(--text-light);
    display: flex;
    justify-content: space-between;
}

/* Charts Grid */
.charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.chart-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 24px;
    border: 1px solid var(--border-gray);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
}

.chart-container {
    height: 250px;
    position: relative;
}

/* Content Grid - 2 columns */
.content-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
}

.content-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    overflow: hidden;
}

.card-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-gray);
}

.card-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
}

.card-header a {
    color: var(--cars-primary);
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
}

.card-header a:hover {
    text-decoration: underline;
}

.card-body {
    padding: 20px;
}

/* Tables */
.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: var(--bg-gray);
    border-bottom: 1px solid var(--border-gray);
}

.table td {
    padding: 16px;
    border-bottom: 1px solid var(--border-gray);
    font-size: 0.875rem;
    vertical-align: middle;
}

.table tr:last-child td {
    border-bottom: none;
}

.table tr:hover td {
    background: var(--cars-light);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-confirmed {
    background: #e6f4ea;
    color: var(--cars-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--cars-warning);
}

.status-cancelled {
    background: #fce8e8;
    color: var(--cars-danger);
}

.status-completed {
    background: var(--cars-light);
    color: var(--cars-primary);
}

.status-available {
    background: #e6f4ea;
    color: var(--cars-success);
}

.status-rented {
    background: var(--cars-light);
    color: var(--cars-primary);
}

/* Pickup/Return Items */
.pickup-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.pickup-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: var(--bg-gray);
    border-radius: var(--radius-md);
    border-left: 4px solid transparent;
}

.pickup-item.pickup {
    border-left-color: var(--cars-primary);
}

.pickup-item.return {
    border-left-color: var(--cars-success);
}

.pickup-time {
    min-width: 70px;
    text-align: center;
    font-weight: 700;
    color: var(--cars-primary);
    font-size: 1rem;
}

.pickup-time .day {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.2;
}

.pickup-time .month {
    font-size: 0.7rem;
    color: var(--text-light);
    text-transform: uppercase;
}

.pickup-info {
    flex: 1;
}

.pickup-guest {
    font-weight: 600;
    font-size: 0.9375rem;
    margin-bottom: 4px;
}

.pickup-vehicle {
    font-size: 0.8125rem;
    color: var(--text-light);
    display: flex;
    align-items: center;
    gap: 4px;
}

.pickup-status {
    font-size: 0.6875rem;
    padding: 4px 8px;
    border-radius: 100px;
    background: var(--cars-light);
    color: var(--cars-primary);
    font-weight: 600;
}

/* Fleet Grid */
.fleet-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
}

.fleet-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    padding: 20px;
    transition: all 0.2s;
}

.fleet-card:hover {
    border-color: var(--cars-primary);
    box-shadow: var(--shadow-md);
}

.fleet-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.fleet-card-type {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--cars-primary);
    text-transform: uppercase;
}

.fleet-card-count {
    background: var(--cars-light);
    color: var(--cars-primary);
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.fleet-card-icon {
    font-size: 2rem;
    color: var(--cars-primary);
    margin-bottom: 16px;
    text-align: center;
}

.fleet-card-stats {
    display: flex;
    justify-content: space-between;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-gray);
}

.fleet-card-stat {
    text-align: center;
}

.fleet-card-stat-value {
    font-weight: 700;
    font-size: 1.125rem;
    color: var(--text-dark);
}

.fleet-card-stat-label {
    font-size: 0.6875rem;
    color: var(--text-light);
}

/* Alert */
.alert {
    padding: 14px 20px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-warning {
    background: #fff4e6;
    color: var(--cars-warning);
    border: 1px solid #fed7aa;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .charts-grid {
        grid-template-columns: 1fr;
    }
    .content-grid {
        grid-template-columns: 1fr;
    }
    .fleet-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .fleet-grid {
        grid-template-columns: 1fr;
    }
    .quick-actions {
        flex-direction: column;
    }
    .quick-action-btn {
        width: 100%;
        justify-content: center;
    }
    .welcome-card {
        padding: 20px;
    }
}
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1>Dashboard</h1>
        <p>Welcome back! Here's what's happening with your fleet.</p>
    </div>
    <div class="top-actions">
        <button class="btn-secondary" onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <a href="add-vehicle.php" class="btn-primary">
            <i class="bi bi-plus-lg"></i> Add Vehicle
        </a>
    </div>
</div>

<!-- Welcome Card -->
<div class="welcome-card">
    <h1 class="welcome-title">Welcome back, <?php echo sanitize($_SESSION['user_name']); ?>! 🚗</h1>
    <p class="welcome-text">Here's what's happening with your fleet today.</p>
    
    <div class="quick-actions">
        <a href="bookings.php?status=pending" class="quick-action-btn">
            <i class="bi bi-clock-history"></i> Pending Bookings
            <?php if (($bookingStats['pending_bookings'] ?? 0) > 0): ?>
            <span class="badge bg-warning"><?php echo $bookingStats['pending_bookings']; ?></span>
            <?php endif; ?>
        </a>
        <a href="pickups.php" class="quick-action-btn">
            <i class="bi bi-arrow-up-circle"></i> Today's Pickups
            <?php if (count($todayPickups) > 0): ?>
            <span class="badge bg-success"><?php echo count($todayPickups); ?></span>
            <?php endif; ?>
        </a>
        <a href="returns.php" class="quick-action-btn">
            <i class="bi bi-arrow-down-circle"></i> Today's Returns
            <?php if (count($todayReturns) > 0): ?>
            <span class="badge bg-info"><?php echo count($todayReturns); ?></span>
            <?php endif; ?>
        </a>
        <a href="analytics.php" class="quick-action-btn">
            <i class="bi bi-graph-up"></i> Analytics
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">
                <i class="bi bi-car-front"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                <?php echo $fleetStats['active_vehicles'] ?? 0; ?> active
            </span>
        </div>
        <div class="stat-value"><?php echo $fleetStats['total_cars'] ?? 0; ?></div>
        <div class="stat-label">Total Vehicles</div>
        <div class="stat-footer">
            <span>Models</span>
            <span><?php echo $fleetStats['total_vehicles'] ?? 0; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">
                <i class="bi bi-calendar-check"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                +<?php echo $bookingStats['confirmed_bookings'] ?? 0; ?>
            </span>
        </div>
        <div class="stat-value"><?php echo $bookingStats['total_bookings'] ?? 0; ?></div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-footer">
            <span>Completed</span>
            <span><?php echo $bookingStats['completed_bookings'] ?? 0; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">
                <i class="bi bi-percent"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-circle-fill"></i>
                <?php echo $utilizationRate; ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo $fleetStats['available_now'] ?? 0; ?></div>
        <div class="stat-label">Available Now</div>
        <div class="stat-footer">
            <span>Avg. Rate</span>
            <span><?php echo formatPrice($fleetStats['avg_daily_rate'] ?? 0); ?>/day</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">
                <i class="bi bi-cash-stack"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                +<?php echo $totalCars > 0 ? round(($bookingStats['week_revenue'] / max(1, $bookingStats['month_revenue'] / 4)) * 100) : 0; ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo formatPrice($bookingStats['total_revenue'] ?? 0); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-footer">
            <span>Today</span>
            <span><?php echo formatPrice($bookingStats['today_revenue'] ?? 0); ?></span>
        </div>
    </div>
</div>

<!-- Charts Grid -->
<div class="charts-grid">
    <!-- Revenue Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Revenue Overview</h3>
            <select class="form-control" style="width: auto; padding: 4px 8px; font-size: 0.75rem;">
                <option>Last 6 months</option>
                <option>Last 12 months</option>
            </select>
        </div>
        <div class="chart-container">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Fleet Composition -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Fleet Composition</h3>
        </div>
        <div class="chart-container">
            <canvas id="fleetChart"></canvas>
        </div>
    </div>
</div>

<!-- Content Grid -->
<div class="content-grid">
    <!-- Recent Bookings -->
    <div class="content-card">
        <div class="card-header">
            <h3>Recent Bookings</h3>
            <a href="bookings.php">View All →</a>
        </div>
        <div class="card-body p-0">
            <table class="table">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Vehicle</th>
                        <th>Pickup/Return</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentBookings)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-light);">
                            No bookings yet
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentBookings as $booking): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600;"><?php echo sanitize($booking['first_name'] ?? 'Guest') . ' ' . sanitize(substr($booking['last_name'] ?? '', 0, 1) . '.'); ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 500;"><?php echo sanitize($booking['brand'] . ' ' . $booking['model']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-light);"><?php echo ucfirst($booking['car_type']); ?></div>
                            </td>
                            <td>
                                <div><?php echo date('M d', strtotime($booking['pickup_date'])); ?> - <?php echo date('M d', strtotime($booking['return_date'])); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-light);"><?php echo $booking['num_nights'] ?? 1; ?> days</div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                            <td style="font-weight: 600;"><?php echo formatPrice($booking['total_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- Upcoming Pickups -->
        <div class="content-card">
            <div class="card-header">
                <h3>Upcoming Pickups</h3>
                <a href="calendar.php">View Calendar →</a>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingPickups)): ?>
                <div style="text-align: center; padding: 30px; color: var(--text-light);">
                    <i class="bi bi-calendar-check" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                    <p>No upcoming pickups</p>
                </div>
                <?php else: ?>
                    <div class="pickup-list">
                        <?php foreach ($upcomingPickups as $pickup): ?>
                        <div class="pickup-item pickup">
                            <div class="pickup-time">
                                <div class="day"><?php echo date('d', strtotime($pickup['pickup_date'])); ?></div>
                                <div class="month"><?php echo date('M', strtotime($pickup['pickup_date'])); ?></div>
                            </div>
                            <div class="pickup-info">
                                <div class="pickup-guest"><?php echo sanitize($pickup['first_name'] . ' ' . substr($pickup['last_name'] ?? '', 0, 1) . '.'); ?></div>
                                <div class="pickup-vehicle">
                                    <i class="bi bi-car-front"></i>
                                    <?php echo sanitize($pickup['brand'] . ' ' . $pickup['model']); ?>
                                </div>
                            </div>
                            <span class="pickup-status"><?php echo date('h:i A', strtotime($pickup['pickup_date'])); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Reviews -->
        <div class="content-card">
            <div class="card-header">
                <h3>Recent Reviews</h3>
                <a href="reviews.php">View All →</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentReviews)): ?>
                <div style="text-align: center; padding: 30px; color: var(--text-light);">
                    <i class="bi bi-star" style="font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                    <p>No reviews yet</p>
                </div>
                <?php else: ?>
                    <?php foreach ($recentReviews as $review): ?>
                    <div style="padding: 16px 0; border-bottom: 1px solid var(--border-gray);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div style="font-weight: 600; font-size: 0.875rem;">
                                <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'] ?? '', 0, 1) . '.'); ?>
                            </div>
                            <div style="color: var(--cars-warning); font-size: 0.75rem;">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 8px;">
                            <?php echo sanitize($review['company_name']); ?> • <?php echo timeAgo($review['created_at']); ?>
                        </div>
                        <div style="font-size: 0.8125rem; color: var(--text-dark);">
                            "<?php echo substr(sanitize($review['comment'] ?? ''), 0, 100); ?>..."
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Low Stock Alert -->
<?php if (!empty($lowStock)): ?>
<div class="alert alert-warning" style="margin-top: 20px;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div style="flex: 1;">
        <strong>Low Stock Alert:</strong> The following vehicles are running low:
        <?php 
        $vehicleNames = array_map(function($v) { 
            return $v['brand'] . ' ' . $v['model'] . ' (' . $v['quantity_available'] . ' left)'; 
        }, $lowStock);
        echo implode(', ', $vehicleNames);
        ?>
    </div>
    <a href="fleet.php" class="btn-outline btn-sm">Manage Fleet</a>
</div>
<?php endif; ?>

<!-- Fleet by Type -->
<?php if (!empty($fleetByType)): ?>
<div class="content-card" style="margin-top: 20px;">
    <div class="card-header">
        <h3>Fleet Overview by Type</h3>
    </div>
    <div class="card-body">
        <div class="fleet-grid">
            <?php foreach ($fleetByType as $type): ?>
            <div class="fleet-card">
                <div class="fleet-card-header">
                    <span class="fleet-card-type"><?php echo ucfirst($type['car_type']); ?></span>
                    <span class="fleet-card-count"><?php echo $type['total']; ?> vehicles</span>
                </div>
                <div class="fleet-card-icon">
                    <i class="bi <?php echo $carTypeIcons[$type['car_type']] ?? 'bi-car-front'; ?>"></i>
                </div>
                <div class="fleet-card-stats">
                    <div class="fleet-card-stat">
                        <div class="fleet-card-stat-value"><?php echo $type['count']; ?></div>
                        <div class="fleet-card-stat-label">Models</div>
                    </div>
                    <div class="fleet-card-stat">
                        <div class="fleet-card-stat-value"><?php echo formatPrice($type['avg_rate']); ?></div>
                        <div class="fleet-card-stat-label">Avg. Rate</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
const ctx1 = document.getElementById('revenueChart').getContext('2d');
const months = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'month') : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun']); ?>;
const revenues = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'revenue') : [0, 0, 0, 0, 0, 0]); ?>;
const bookings = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'bookings') : [0, 0, 0, 0, 0, 0]); ?>;

new Chart(ctx1, {
    type: 'line',
    data: {
        labels: months,
        datasets: [{
            label: 'Revenue',
            data: revenues,
            borderColor: '#ff8c00',
            backgroundColor: 'rgba(255, 140, 0, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            yAxisID: 'y-revenue'
        }, {
            label: 'Bookings',
            data: bookings,
            borderColor: '#008009',
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: [5, 5],
            tension: 0.4,
            yAxisID: 'y-bookings'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 12,
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label === 'Revenue') {
                            return label + ': ' + formatCurrency(context.parsed.y);
                        }
                        return label + ': ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f0f0f0' }
            }
        }
    }
});

// Fleet Chart
const ctx2 = document.getElementById('fleetChart').getContext('2d');
const types = <?php echo json_encode(!empty($fleetByType) ? array_column($fleetByType, 'car_type') : ['Economy', 'SUV', 'Luxury']); ?>;
const counts = <?php echo json_encode(!empty($fleetByType) ? array_column($fleetByType, 'count') : [1, 1, 1]); ?>;

new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: types.map(t => t.charAt(0).toUpperCase() + t.slice(1)),
        datasets: [{
            data: counts,
            backgroundColor: ['#ff8c00', '#008009', '#0288d1', '#9333ea', '#e21111'],
            borderWidth: 0,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 12,
                cornerRadius: 4
            }
        }
    }
});

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}
</script>

<?php require_once 'includes/cars_footer.php'; ?>