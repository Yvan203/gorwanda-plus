<?php
$pageTitle = 'Dashboard';
require_once 'includes/admin_header.php';

$db = getDB();

// ============================================
// KEY METRICS
// ============================================

// Today's stats
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount END), 0) as today_revenue,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_bookings,
        COUNT(DISTINCT CASE WHEN DATE(created_at) = CURDATE() THEN user_id END) as today_customers
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
");
$stmt->execute();
$todayStats = $stmt->fetch();

// Weekly stats
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN total_amount END), 0) as week_revenue,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_bookings
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
");
$stmt->execute();
$weekStats = $stmt->fetch();

// Monthly stats
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_amount END), 0) as month_revenue,
        COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_bookings,
        COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN user_id END) as month_customers
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
");
$stmt->execute();
$monthStats = $stmt->fetch();

// Total stats
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COUNT(*) as total_bookings,
        COUNT(DISTINCT user_id) as total_customers,
        COUNT(DISTINCT stay_room_id) as total_rooms_booked
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
");
$stmt->execute();
$totalStats = $stmt->fetch();

// Platform counts
$totalStays = $db->query("SELECT COUNT(*) FROM stays WHERE is_active = 1")->fetchColumn();
$totalCars = $db->query("SELECT COUNT(*) FROM car_rentals WHERE is_active = 1")->fetchColumn();
$totalAttractions = $db->query("SELECT COUNT(*) FROM attractions WHERE is_active = 1")->fetchColumn();
$totalRestaurants = $db->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM users WHERE user_type = 'tourist' AND is_active = 1")->fetchColumn();
$totalPartners = $db->query("SELECT COUNT(*) FROM users WHERE user_type = 'business_owner' AND is_active = 1")->fetchColumn();

// Pending verifications
$pendingStays = $db->query("SELECT COUNT(*) FROM stays WHERE is_verified = 0 AND is_active = 1")->fetchColumn();
$pendingCars = $db->query("SELECT COUNT(*) FROM car_rentals WHERE is_verified = 0 AND is_active = 1")->fetchColumn();
$pendingAttractions = $db->query("SELECT COUNT(*) FROM attractions WHERE is_verified = 0 AND is_active = 1")->fetchColumn();

// Recent bookings (last 5)
$stmt = $db->prepare("
    SELECT b.*, 
           CASE 
               WHEN b.booking_type = 'stay' THEN s.stay_name
               WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
               ELSE a.attraction_name
           END as item_name,
           u.first_name, u.last_name
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    LEFT JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    ORDER BY b.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recentBookings = $stmt->fetchAll();

// Get monthly revenue for chart (last 12 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%b') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(total_amount), 0) as revenue,
        COUNT(*) as bookings
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month_key ASC
");
$stmt->execute();
$monthlyData = $stmt->fetchAll();

// Fill in missing months
$months = [];
$revenues = [];
$bookings = [];

for ($i = 11; $i >= 0; $i--) {
    $month = date('M', strtotime("-$i months"));
    $months[] = $month;
    $found = false;
    foreach ($monthlyData as $data) {
        if ($data['month'] == $month) {
            $revenues[] = $data['revenue'];
            $bookings[] = $data['bookings'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $revenues[] = 0;
        $bookings[] = 0;
    }
}

// Get booking status distribution
$stmt = $db->prepare("
    SELECT status, COUNT(*) as count
    FROM bookings
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY status
");
$stmt->execute();
$statusData = $stmt->fetchAll();

$statusCounts = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'checked_in' => 0
];
foreach ($statusData as $s) {
    $statusCounts[$s['status']] = $s['count'];
}

// Top performing properties
$stmt = $db->prepare("
    SELECT s.stay_name, s.main_image, COUNT(b.booking_id) as booking_count, COALESCE(SUM(b.total_amount), 0) as revenue
    FROM stays s
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id AND b.status IN ('confirmed', 'completed')
    GROUP BY s.stay_id
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute();
$topProperties = $stmt->fetchAll();

// Recent activity
$stmt = $db->prepare("
    (SELECT 'booking' as type, b.created_at, CONCAT(u.first_name, ' ', u.last_name) as user_name, 
            b.total_amount as amount, b.status, 'booking' as action
     FROM bookings b
     LEFT JOIN users u ON b.user_id = u.user_id
     ORDER BY b.created_at DESC
     LIMIT 5)
    UNION ALL
    (SELECT 'review' as type, r.created_at, CONCAT(u.first_name, ' ', u.last_name) as user_name,
            r.overall_rating as amount, NULL as status, 'review' as action
     FROM reviews r
     LEFT JOIN users u ON r.user_id = u.user_id
     ORDER BY r.created_at DESC
     LIMIT 5)
    ORDER BY created_at DESC
    LIMIT 8
");
$stmt->execute();
$recentActivity = $stmt->fetchAll();
?>

<style>
/* Dashboard Specific Styles */
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--booking-border);
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    line-height: 1.2;
    margin-bottom: 2px;
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-trend {
    font-size: 0.625rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 100px;
    background: #e6f4ea;
    color: var(--booking-success);
}

.stat-trend.down {
    background: #fce8e8;
    color: var(--booking-danger);
}

.stat-footer {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--booking-border);
    font-size: 0.625rem;
    color: var(--booking-text-light);
    display: flex;
    justify-content: space-between;
}

/* Charts Grid */
.charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.chart-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--booking-border);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.chart-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.chart-container {
    height: 250px;
    position: relative;
}

/* Quick Actions */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.quick-action-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--booking-border);
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all var(--transition-fast);
    cursor: pointer;
}

.quick-action-card:hover {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
    transform: translateY(-2px);
}

.quick-action-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    color: var(--booking-blue);
}

.quick-action-info h4 {
    font-size: 0.8125rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.quick-action-info p {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.content-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.card-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--booking-gray-light);
}

.card-header h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0;
}

.card-header a {
    font-size: 0.625rem;
    color: var(--booking-blue);
    text-decoration: none;
}

.table-mini {
    width: 100%;
    border-collapse: collapse;
}

.table-mini th {
    text-align: left;
    padding: 10px 12px;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.table-mini td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.6875rem;
    vertical-align: middle;
}

.table-mini tr:hover td {
    background: var(--booking-gray-light);
}

.status-badge {
    display: inline-flex;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.status-confirmed {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-cancelled {
    background: #fce8e8;
    color: var(--booking-danger);
}

.activity-list {
    padding: 8px 0;
}

.activity-item {
    display: flex;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    transition: background var(--transition-fast);
}

.activity-item:hover {
    background: var(--booking-gray-light);
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
}

.activity-icon.booking { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.activity-icon.review { background: rgba(255,140,0,0.1); color: var(--booking-warning); }

.activity-content {
    flex: 1;
}

.activity-text {
    font-size: 0.6875rem;
    color: var(--booking-text);
    margin-bottom: 2px;
}

.activity-text strong {
    font-weight: 600;
}

.activity-meta {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.top-properties-list {
    padding: 8px 0;
}

.property-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
}

.property-rank {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.6875rem;
}

.property-rank.top-1 { background: gold; color: #1a1a1a; }
.property-rank.top-2 { background: silver; color: #1a1a1a; }
.property-rank.top-3 { background: #cd7f32; color: white; }

.property-image {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--booking-gray-light);
}

.property-info {
    flex: 1;
}

.property-name {
    font-weight: 600;
    font-size: 0.6875rem;
    margin-bottom: 2px;
}

.property-stats {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.property-revenue {
    font-weight: 700;
    color: var(--booking-success);
    font-size: 0.6875rem;
}

/* Responsive */
@media (max-width: 1200px) {
    .dashboard-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .charts-grid {
        grid-template-columns: 1fr;
    }
    .content-grid {
        grid-template-columns: 1fr;
    }
    .quick-actions {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .dashboard-stats {
        grid-template-columns: 1fr;
    }
    .quick-actions {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Stats Grid -->
<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">
                <i class="bi bi-cash-stack"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i> <?php echo $monthStats['month_revenue'] > 0 ? round(($weekStats['week_revenue'] / $monthStats['month_revenue']) * 100) : 0; ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo formatPrice($totalStats['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-footer">
            <span>Today: <?php echo formatPrice($todayStats['today_revenue']); ?></span>
            <span>This week: <?php echo formatPrice($weekStats['week_revenue']); ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">
                <i class="bi bi-calendar-check"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i> <?php echo $monthStats['month_bookings'] > 0 ? round(($weekStats['week_bookings'] / $monthStats['month_bookings']) * 100) : 0; ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo number_format($totalStats['total_bookings']); ?></div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-footer">
            <span>Today: <?php echo $todayStats['today_bookings']; ?></span>
            <span>This week: <?php echo $weekStats['week_bookings']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">
                <i class="bi bi-people"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i> +<?php echo $monthStats['month_customers']; ?>
            </span>
        </div>
        <div class="stat-value"><?php echo number_format($totalStats['total_customers']); ?></div>
        <div class="stat-label">Total Customers</div>
        <div class="stat-footer">
            <span>New this month: <?php echo $monthStats['month_customers']; ?></span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">
                <i class="bi bi-building"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-check-circle"></i> Active
            </span>
        </div>
        <div class="stat-value"><?php echo number_format($totalStays + $totalCars + $totalAttractions + $totalRestaurants); ?></div>
        <div class="stat-label">Total Listings</div>
        <div class="stat-footer">
            <span>Stays: <?php echo $totalStays; ?></span>
            <span>Cars: <?php echo $totalCars; ?></span>
            <span>Experiences: <?php echo $totalAttractions; ?></span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <div class="quick-action-card" onclick="window.location.href='verifications.php'">
        <div class="quick-action-icon">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="quick-action-info">
            <h4>Pending Verifications</h4>
            <p><?php echo $pendingStays + $pendingCars + $pendingAttractions; ?> items waiting</p>
        </div>
    </div>
    <div class="quick-action-card" onclick="window.location.href='payouts.php'">
        <div class="quick-action-icon">
            <i class="bi bi-wallet2"></i>
        </div>
        <div class="quick-action-info">
            <h4>Pending Payouts</h4>
            <p>Process partner payments</p>
        </div>
    </div>
    <div class="quick-action-card" onclick="window.location.href='reports.php'">
        <div class="quick-action-icon">
            <i class="bi bi-file-text"></i>
        </div>
        <div class="quick-action-info">
            <h4>Generate Reports</h4>
            <p>Monthly/Yearly analysis</p>
        </div>
    </div>
    <div class="quick-action-card" onclick="window.location.href='settings.php'">
        <div class="quick-action-icon">
            <i class="bi bi-gear"></i>
        </div>
        <div class="quick-action-info">
            <h4>Platform Settings</h4>
            <p>Configure system</p>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Revenue & Bookings Trend</h3>
            <div class="chart-legend">
                <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                    <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Revenue
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
    
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Booking Status (Last 30 Days)</h3>
        </div>
        <div class="chart-container" style="height: 200px;">
            <canvas id="statusChart"></canvas>
        </div>
        <div class="d-flex justify-content-center gap-3 mt-2">
            <span class="status-badge status-confirmed">Confirmed: <?php echo $statusCounts['confirmed']; ?></span>
            <span class="status-badge status-pending">Pending: <?php echo $statusCounts['pending']; ?></span>
            <span class="status-badge status-cancelled">Cancelled: <?php echo $statusCounts['cancelled']; ?></span>
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
        <table class="table-mini">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Guest</th>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBookings as $booking): ?>
                <tr>
                    <td><span style="font-family: monospace;">#<?php echo $booking['booking_reference']; ?></span></td>
                    <td><?php echo sanitize($booking['first_name'] ?? 'Guest') . ' ' . substr($booking['last_name'] ?? '', 0, 1) . '.'; ?></td>
                    <td><?php echo substr(sanitize($booking['item_name'] ?? 'N/A'), 0, 20); ?></td>
                    <td><?php echo formatPrice($booking['total_amount']); ?></td>
                    <td><span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst($booking['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Activity -->
    <div class="content-card">
        <div class="card-header">
            <h3>Recent Activity</h3>
        </div>
        <div class="activity-list">
            <?php foreach ($recentActivity as $activity): ?>
            <div class="activity-item">
                <div class="activity-icon <?php echo $activity['type']; ?>">
                    <i class="bi bi-<?php echo $activity['type'] == 'booking' ? 'calendar-check' : 'star'; ?>"></i>
                </div>
                <div class="activity-content">
                    <div class="activity-text">
                        <?php if ($activity['type'] == 'booking'): ?>
                            <strong><?php echo sanitize($activity['user_name']); ?></strong> made a booking
                            <?php if ($activity['amount']): ?>for <?php echo formatPrice($activity['amount']); ?><?php endif; ?>
                        <?php else: ?>
                            <strong><?php echo sanitize($activity['user_name']); ?></strong> left a <?php echo $activity['amount']; ?>-star review
                        <?php endif; ?>
                    </div>
                    <div class="activity-meta"><?php echo timeAgo($activity['created_at']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Top Properties -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3>Top Performing Properties</h3>
        <a href="stays.php">View All →</a>
    </div>
    <div class="top-properties-list">
        <?php foreach ($topProperties as $index => $property): ?>
        <div class="property-item">
            <div class="property-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                <?php echo $index + 1; ?>
            </div>
            <img src="<?php echo getImageUrl($property['main_image'] ?? '', 'stay'); ?>" class="property-image">
            <div class="property-info">
                <div class="property-name"><?php echo sanitize($property['stay_name']); ?></div>
                <div class="property-stats"><?php echo $property['booking_count']; ?> bookings</div>
            </div>
            <div class="property-revenue"><?php echo formatPrice($property['revenue']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Revenue & Bookings Chart
const ctx1 = document.getElementById('trendChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Revenue',
                data: <?php echo json_encode($revenues); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-revenue'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode($bookings); ?>,
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
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 10,
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Revenue') {
                            return 'Revenue: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 9 } } },
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'left',
                grid: { color: '#f0f0f0' },
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
                    callback: function(value) {
                        return value + ' bookings';
                    },
                    font: { size: 9 }
                }
            }
        }
    }
});

// Status Chart
const ctx2 = document.getElementById('statusChart').getContext('2d');
new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: ['Confirmed', 'Pending', 'Cancelled', 'Completed', 'Checked In'],
        datasets: [{
            data: [
                <?php echo $statusCounts['confirmed']; ?>,
                <?php echo $statusCounts['pending']; ?>,
                <?php echo $statusCounts['cancelled']; ?>,
                <?php echo $statusCounts['completed']; ?>,
                <?php echo $statusCounts['checked_in']; ?>
            ],
            backgroundColor: ['#008009', '#ff8c00', '#e21111', '#003b95', '#17a2b8'],
            borderWidth: 0,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 10,
                cornerRadius: 4
            }
        }
    }
});

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>