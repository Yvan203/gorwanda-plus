<?php
$pageTitle = 'Analytics';
require_once 'includes/admin_header.php';

$db = getDB();

// Get date range parameters
$period = isset($_GET['period']) ? sanitize($_GET['period']) : 'month';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Set date range based on period
switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        break;
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'month':
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'quarter':
        $dateFrom = date('Y-m-d', strtotime('-90 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'year':
        $dateFrom = date('Y-m-d', strtotime('-365 days'));
        $dateTo = date('Y-m-d');
        break;
    case 'custom':
        // Use provided dates
        break;
}

// If no custom dates set, use month as default
if (empty($dateFrom) && $period !== 'custom') {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');
}

// ============================================
// OVERVIEW METRICS
// ============================================

// Revenue metrics
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COALESCE(SUM(commission_amount), 0) as total_commission,
        COALESCE(AVG(total_amount), 0) as avg_booking_value,
        COUNT(*) as total_bookings,
        COUNT(DISTINCT user_id) as unique_customers
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$dateFrom, $dateTo]);
$metrics = $stmt->fetch();

// Previous period metrics for comparison
$prevDateFrom = date('Y-m-d', strtotime($dateFrom . ' -' . (strtotime($dateTo) - strtotime($dateFrom)) . ' days'));
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) as total_revenue,
        COUNT(*) as total_bookings
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$prevDateFrom, $dateFrom]);
$prevMetrics = $stmt->fetch();

// Calculate growth percentages
$revenueGrowth = $prevMetrics['total_revenue'] > 0 
    ? (($metrics['total_revenue'] - $prevMetrics['total_revenue']) / $prevMetrics['total_revenue']) * 100 
    : ($metrics['total_revenue'] > 0 ? 100 : 0);
$bookingGrowth = $prevMetrics['total_bookings'] > 0 
    ? (($metrics['total_bookings'] - $prevMetrics['total_bookings']) / $prevMetrics['total_bookings']) * 100 
    : ($metrics['total_bookings'] > 0 ? 100 : 0);

// ============================================
// BOOKING TYPE BREAKDOWN
// ============================================
$stmt = $db->prepare("
    SELECT 
        booking_type,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as revenue,
        COALESCE(AVG(total_amount), 0) as avg_value
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY booking_type
");
$stmt->execute([$dateFrom, $dateTo]);
$bookingTypeBreakdown = $stmt->fetchAll();

// ============================================
// REVENUE BY DAY (for charts)
// ============================================
$stmt = $db->prepare("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as bookings,
        COALESCE(SUM(total_amount), 0) as revenue,
        COALESCE(SUM(commission_amount), 0) as commission
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$dateFrom, $dateTo]);
$dailyData = $stmt->fetchAll();

// Fill in missing dates
$dateRange = [];
$revenueData = [];
$bookingData = [];
$commissionData = [];

$currentDate = new DateTime($dateFrom);
$endDate = new DateTime($dateTo);
$endDate->modify('+1 day');

while ($currentDate < $endDate) {
    $dateStr = $currentDate->format('Y-m-d');
    $dateRange[] = $currentDate->format('M d');
    $found = false;
    
    foreach ($dailyData as $data) {
        if ($data['date'] == $dateStr) {
            $revenueData[] = $data['revenue'];
            $bookingData[] = $data['bookings'];
            $commissionData[] = $data['commission'];
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $revenueData[] = 0;
        $bookingData[] = 0;
        $commissionData[] = 0;
    }
    
    $currentDate->modify('+1 day');
}

// ============================================
// TOP PERFORMING CATEGORIES
// ============================================

// Top stays
$stmt = $db->prepare("
    SELECT 
        s.stay_name,
        s.main_image,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        AVG(b.total_amount) as avg_booking
    FROM stays s
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY s.stay_id
    HAVING booking_count > 0
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$dateFrom, $dateTo]);
$topStays = $stmt->fetchAll();

// Top car rentals
$stmt = $db->prepare("
    SELECT 
        cr.company_name,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM car_rentals cr
    LEFT JOIN car_fleet cf ON cr.rental_id = cf.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY cr.rental_id
    HAVING booking_count > 0
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$dateFrom, $dateTo]);
$topCars = $stmt->fetchAll();

// Top attractions
$stmt = $db->prepare("
    SELECT 
        a.attraction_name,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM attractions a
    LEFT JOIN attraction_tiers at ON a.attraction_id = at.attraction_id
    LEFT JOIN bookings b ON at.tier_id = b.attraction_tier_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY a.attraction_id
    HAVING booking_count > 0
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$dateFrom, $dateTo]);
$topAttractions = $stmt->fetchAll();

// ============================================
// USER DEMOGRAPHICS
// ============================================

// New users
$stmt = $db->prepare("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as new_users
    FROM users
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$stmt->execute([$dateFrom, $dateTo]);
$userSignups = $stmt->fetchAll();

$userSignupDates = [];
$userSignupCounts = [];
foreach ($userSignups as $signup) {
    $userSignupDates[] = date('M d', strtotime($signup['date']));
    $userSignupCounts[] = $signup['new_users'];
}

// User type distribution
$stmt = $db->prepare("
    SELECT 
        user_type,
        COUNT(*) as count
    FROM users
    WHERE created_at <= ?
    GROUP BY user_type
");
$stmt->execute([$dateTo]);
$userTypes = $stmt->fetchAll();

// Top customers by spending
$stmt = $db->prepare("
    SELECT 
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.total_amount), 0) as total_spent
    FROM users u
    LEFT JOIN bookings b ON u.user_id = b.user_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY u.user_id
    HAVING total_spent > 0
    ORDER BY total_spent DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$topCustomers = $stmt->fetchAll();

// ============================================
// PARTNER PERFORMANCE
// ============================================
$stmt = $db->prepare("
    SELECT 
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        COUNT(DISTINCT CASE WHEN b.booking_type = 'stay' THEN b.booking_id END) as stay_bookings,
        COUNT(DISTINCT CASE WHEN b.booking_type = 'car_rental' THEN b.booking_id END) as car_bookings,
        COUNT(DISTINCT CASE WHEN b.booking_type = 'attraction' THEN b.booking_id END) as attraction_bookings,
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COALESCE(SUM(b.commission_amount), 0) as total_commission
    FROM users u
    LEFT JOIN stays s ON u.user_id = s.owner_id
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    WHERE u.user_type = 'business_owner'
    GROUP BY u.user_id
    HAVING total_revenue > 0
    ORDER BY total_revenue DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$topPartners = $stmt->fetchAll();

// ============================================
// BOOKING STATUS DISTRIBUTION
// ============================================
$stmt = $db->prepare("
    SELECT 
        status,
        COUNT(*) as count,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM bookings
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY status
");
$stmt->execute([$dateFrom, $dateTo]);
$statusDistribution = $stmt->fetchAll();

// ============================================
// REVIEW METRICS
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_reviews,
        AVG(overall_rating) as avg_rating,
        COUNT(CASE WHEN overall_rating >= 4 THEN 1 END) as positive_reviews,
        COUNT(CASE WHEN overall_rating <= 2 THEN 1 END) as negative_reviews,
        review_type
    FROM reviews
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY review_type
");
$stmt->execute([$dateFrom, $dateTo]);
$reviewMetrics = $stmt->fetchAll();

$totalReviews = array_sum(array_column($reviewMetrics, 'total_reviews'));
$avgRating = count($reviewMetrics) > 0 
    ? array_sum(array_column($reviewMetrics, 'avg_rating')) / count($reviewMetrics) 
    : 0;

// ============================================
// LOCATION PERFORMANCE
// ============================================
$stmt = $db->prepare("
    SELECT 
        l.name as location_name,
        COUNT(b.booking_id) as booking_count,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM locations l
    LEFT JOIN stays s ON l.location_id = s.location_id
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
        AND b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY l.location_id
    HAVING booking_count > 0
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$dateFrom, $dateTo]);
$topLocations = $stmt->fetchAll();

// ============================================
// WEEKDAY ANALYSIS
// ============================================
$stmt = $db->prepare("
    SELECT 
        DAYOFWEEK(created_at) as day_of_week,
        COUNT(*) as booking_count,
        COALESCE(SUM(total_amount), 0) as revenue
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(created_at)
    ORDER BY day_of_week
");
$stmt->execute([$dateFrom, $dateTo]);
$weekdayData = $stmt->fetchAll();

$weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$weekdayBookings = array_fill(0, 7, 0);
$weekdayRevenue = array_fill(0, 7, 0);

foreach ($weekdayData as $data) {
    $index = $data['day_of_week'] - 1; // MySQL dayofweek: 1=Sun, 7=Sat
    $weekdayBookings[$index] = $data['booking_count'];
    $weekdayRevenue[$index] = $data['revenue'];
}
?>

<style>
/* Date Range Picker */
.date-range-bar {
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

.period-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.period-btn {
    padding: 6px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: var(--booking-white);
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--booking-text);
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    display: inline-block;
}

.period-btn:hover {
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.period-btn.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
}

.custom-date-range {
    display: flex;
    gap: 12px;
    align-items: center;
}

.custom-date-range input {
    padding: 6px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.custom-date-range button {
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    padding: 6px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    cursor: pointer;
}

/* Metric Cards */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.metric-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    transition: all var(--transition-fast);
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.metric-icon {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.metric-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.metric-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.metric-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.metric-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }

.metric-change {
    font-size: 0.625rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 100px;
}

.metric-change.positive {
    background: #e6f4ea;
    color: var(--booking-success);
}

.metric-change.negative {
    background: #fce8e8;
    color: var(--booking-danger);
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.metric-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
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
    border: 1px solid var(--booking-border);
    padding: 16px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.chart-container {
    height: 280px;
    position: relative;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.stats-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.stats-card-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.stats-card-header h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.stats-card-body {
    padding: 16px;
}

/* Ranking Tables */
.ranking-list {
    max-height: 300px;
    overflow-y: auto;
}

.ranking-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--booking-border);
}

.ranking-rank {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.75rem;
    flex-shrink: 0;
}

.ranking-rank.top-1 { background: gold; color: #1a1a1a; }
.ranking-rank.top-2 { background: silver; color: #1a1a1a; }
.ranking-rank.top-3 { background: #cd7f32; color: white; }

.ranking-info {
    flex: 1;
}

.ranking-name {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.ranking-stats {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.ranking-value {
    font-weight: 700;
    font-size: 0.75rem;
    color: var(--booking-success);
}

/* Status Distribution */
.status-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.status-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.status-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}

.status-info {
    flex: 1;
    font-size: 0.6875rem;
}

.status-count {
    font-weight: 600;
    font-size: 0.6875rem;
}

.status-percent {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

/* Review Stats */
.review-stats {
    text-align: center;
    padding: 20px;
}

.review-rating {
    font-size: 3rem;
    font-weight: 700;
    color: var(--booking-warning);
    margin-bottom: 8px;
}

.review-stars {
    margin-bottom: 16px;
}

.review-stars i {
    font-size: 1rem;
    color: #ffc107;
}

.review-count {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.review-breakdown {
    margin-top: 16px;
}

.review-breakdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.review-breakdown-label {
    font-size: 0.625rem;
    width: 30px;
}

.review-breakdown-bar {
    flex: 1;
    height: 6px;
    background: var(--booking-border);
    border-radius: 3px;
    overflow: hidden;
}

.review-breakdown-fill {
    height: 100%;
    background: var(--booking-warning);
    border-radius: 3px;
}

.review-breakdown-count {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    width: 40px;
    text-align: right;
}

/* Responsive */
@media (max-width: 1200px) {
    .metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .charts-grid {
        grid-template-columns: 1fr;
    }
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .metrics-grid {
        grid-template-columns: 1fr;
    }
    .date-range-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .period-buttons {
        justify-content: center;
    }
    .custom-date-range {
        justify-content: center;
    }
}
</style>


<!-- Date Range Picker -->
<div class="date-range-bar">
    <div class="period-buttons">
        <a href="?period=today" class="period-btn <?php echo $period == 'today' ? 'active' : ''; ?>">Today</a>
        <a href="?period=week" class="period-btn <?php echo $period == 'week' ? 'active' : ''; ?>">Last 7 Days</a>
        <a href="?period=month" class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">Last 30 Days</a>
        <a href="?period=quarter" class="period-btn <?php echo $period == 'quarter' ? 'active' : ''; ?>">Last 90 Days</a>
        <a href="?period=year" class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">Last Year</a>
        <a href="?period=custom" class="period-btn <?php echo $period == 'custom' ? 'active' : ''; ?>">Custom</a>
    </div>
    
    <form method="GET" action="analytics.php" class="custom-date-range" style="display: <?php echo $period == 'custom' ? 'flex' : 'none'; ?>">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" required>
        <span>to</span>
        <input type="date" name="date_to" value="<?php echo $dateTo; ?>" required>
        <button type="submit">Apply</button>
    </form>
</div>

<!-- Key Metrics -->
<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-header">
            <div class="metric-icon blue">
                <i class="bi bi-cash-stack"></i>
            </div>
            <span class="metric-change <?php echo $revenueGrowth >= 0 ? 'positive' : 'negative'; ?>">
                <i class="bi bi-arrow-<?php echo $revenueGrowth >= 0 ? 'up' : 'down'; ?>-short"></i>
                <?php echo number_format(abs($revenueGrowth), 1); ?>%
            </span>
        </div>
        <div class="metric-value"><?php echo formatPrice($metrics['total_revenue']); ?></div>
        <div class="metric-label">Total Revenue</div>
        <div class="metric-footer" style="margin-top: 8px; font-size: 0.5625rem; color: var(--booking-text-light);">
            vs previous period: <?php echo formatPrice($prevMetrics['total_revenue']); ?>
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-header">
            <div class="metric-icon green">
                <i class="bi bi-calendar-check"></i>
            </div>
            <span class="metric-change <?php echo $bookingGrowth >= 0 ? 'positive' : 'negative'; ?>">
                <i class="bi bi-arrow-<?php echo $bookingGrowth >= 0 ? 'up' : 'down'; ?>-short"></i>
                <?php echo number_format(abs($bookingGrowth), 1); ?>%
            </span>
        </div>
        <div class="metric-value"><?php echo number_format($metrics['total_bookings']); ?></div>
        <div class="metric-label">Total Bookings</div>
        <div class="metric-footer" style="margin-top: 8px; font-size: 0.5625rem; color: var(--booking-text-light);">
            vs previous period: <?php echo number_format($prevMetrics['total_bookings']); ?>
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-header">
            <div class="metric-icon orange">
                <i class="bi bi-people"></i>
            </div>
        </div>
        <div class="metric-value"><?php echo number_format($metrics['unique_customers']); ?></div>
        <div class="metric-label">Unique Customers</div>
        <div class="metric-footer" style="margin-top: 8px; font-size: 0.5625rem; color: var(--booking-text-light);">
            Avg. <?php echo number_format($metrics['avg_booking_value'], 0); ?> RWF per booking
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-header">
            <div class="metric-icon purple">
                <i class="bi bi-percent"></i>
            </div>
        </div>
        <div class="metric-value"><?php echo formatPrice($metrics['total_commission']); ?></div>
        <div class="metric-label">Commission Earned</div>
        <div class="metric-footer" style="margin-top: 8px; font-size: 0.5625rem; color: var(--booking-text-light);">
            <?php echo $metrics['total_revenue'] > 0 ? number_format(($metrics['total_commission'] / $metrics['total_revenue']) * 100, 1) : 0; ?>% of revenue
        </div>
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Revenue & Booking Trends</h3>
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
            <h3 class="chart-title">Booking Type Distribution</h3>
        </div>
        <div class="chart-container" style="height: 220px;">
            <canvas id="bookingTypeChart"></canvas>
        </div>
        <div style="margin-top: 16px;">
            <?php foreach ($bookingTypeBreakdown as $type): ?>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.6875rem;">
                <span>
                    <i class="bi bi-<?php echo $type['booking_type'] == 'stay' ? 'building' : ($type['booking_type'] == 'car_rental' ? 'car-front' : 'ticket-perforated'); ?>"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $type['booking_type'])); ?>
                </span>
                <span><strong><?php echo number_format($type['count']); ?></strong> bookings</span>
                <span><?php echo formatPrice($type['revenue']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Weekly Analysis and Status -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Weekday Performance Analysis</h3>
        </div>
        <div class="chart-container" style="height: 200px;">
            <canvas id="weekdayChart"></canvas>
        </div>
    </div>
    
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Booking Status Distribution</h3>
        </div>
        <div class="chart-container" style="height: 200px;">
            <canvas id="statusChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Performers Grid -->
<div class="stats-grid">
    <!-- Top Stays -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-building"></i> Top Performing Stays</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list">
                <?php if (empty($topStays)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topStays as $index => $stay): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($stay['stay_name']); ?></div>
                        <div class="ranking-stats"><?php echo $stay['booking_count']; ?> bookings</div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($stay['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Car Rentals -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-car-front"></i> Top Car Rentals</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list">
                <?php if (empty($topCars)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topCars as $index => $car): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($car['company_name']); ?></div>
                        <div class="ranking-stats"><?php echo $car['booking_count']; ?> bookings</div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($car['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Experiences -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-ticket-perforated"></i> Top Experiences</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list">
                <?php if (empty($topAttractions)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topAttractions as $index => $attraction): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($attraction['attraction_name']); ?></div>
                        <div class="ranking-stats"><?php echo $attraction['booking_count']; ?> bookings</div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($attraction['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Customer & Partner Analytics -->
<div class="stats-grid">
    <!-- Top Customers -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-star-fill"></i> Top Customers by Spending</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list" style="max-height: 350px;">
                <?php if (empty($topCustomers)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topCustomers as $index => $customer): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
                        <div class="ranking-stats"><?php echo $customer['booking_count']; ?> bookings</div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($customer['total_spent']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Top Partners -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-building"></i> Top Performing Partners</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list" style="max-height: 350px;">
                <?php if (empty($topPartners)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topPartners as $index => $partner): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($partner['first_name'] . ' ' . $partner['last_name']); ?></div>
                        <div class="ranking-stats">
                            <?php 
                            $types = [];
                            if ($partner['stay_bookings'] > 0) $types[] = $partner['stay_bookings'] . ' stays';
                            if ($partner['car_bookings'] > 0) $types[] = $partner['car_bookings'] . ' cars';
                            if ($partner['attraction_bookings'] > 0) $types[] = $partner['attraction_bookings'] . ' experiences';
                            echo implode(' • ', $types);
                            ?>
                        </div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($partner['total_revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Review & Location Stats -->
    <div class="stats-card">
        <div class="stats-card-header">
            <h3><i class="bi bi-geo-alt"></i> Top Locations by Revenue</h3>
        </div>
        <div class="stats-card-body">
            <div class="ranking-list">
                <?php if (empty($topLocations)): ?>
                <div style="text-align: center; padding: 20px; color: var(--booking-text-light);">
                    <i class="bi bi-inbox"></i>
                    <p style="font-size: 0.6875rem; margin-top: 8px;">No data available</p>
                </div>
                <?php else: ?>
                <?php foreach ($topLocations as $index => $location): ?>
                <div class="ranking-item">
                    <div class="ranking-rank <?php echo $index < 3 ? 'top-' . ($index + 1) : ''; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="ranking-info">
                        <div class="ranking-name"><?php echo sanitize($location['location_name']); ?></div>
                        <div class="ranking-stats"><?php echo $location['booking_count']; ?> bookings</div>
                    </div>
                    <div class="ranking-value"><?php echo formatPrice($location['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($totalReviews > 0): ?>
            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--booking-border);">
                <div class="review-stats" style="padding: 0;">
                    <div class="review-rating"><?php echo number_format($avgRating, 1); ?></div>
                    <div class="review-stars">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= round($avgRating) ? '' : 'empty'; ?>" style="<?php echo $i <= round($avgRating) ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="review-count">Based on <?php echo number_format($totalReviews); ?> reviews</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Revenue & Bookings Chart
const ctx1 = document.getElementById('trendChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dateRange); ?>,
        datasets: [
            {
                label: 'Revenue (RWF)',
                data: <?php echo json_encode($revenueData); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-revenue'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode($bookingData); ?>,
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
                        if (context.dataset.label === 'Revenue (RWF)') {
                            return 'Revenue: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
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
                        return value;
                    },
                    font: { size: 9 }
                }
            }
        }
    }
});

// Booking Type Distribution Chart
const ctx2 = document.getElementById('bookingTypeChart').getContext('2d');
new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_map(function($type) {
            return ucfirst(str_replace('_', ' ', $type['booking_type']));
        }, $bookingTypeBreakdown)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($bookingTypeBreakdown, 'revenue')); ?>,
            backgroundColor: ['#003b95', '#008009', '#ff8c00'],
            borderWidth: 0,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 10 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.raw / total) * 100).toFixed(1);
                        return `${context.label}: ${formatCurrency(context.raw)} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

// Weekday Analysis Chart
const ctx3 = document.getElementById('weekdayChart').getContext('2d');
new Chart(ctx3, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($weekdayNames); ?>,
        datasets: [
            {
                label: 'Bookings',
                data: <?php echo json_encode($weekdayBookings); ?>,
                backgroundColor: '#003b95',
                borderRadius: 4,
                yAxisID: 'y-bookings'
            },
            {
                label: 'Revenue (RWF)',
                data: <?php echo json_encode($weekdayRevenue); ?>,
                backgroundColor: '#008009',
                borderRadius: 4,
                yAxisID: 'y-revenue'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 10 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Revenue (RWF)') {
                            return 'Revenue: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 10 } } },
            'y-bookings': {
                type: 'linear',
                display: true,
                position: 'left',
                ticks: { stepSize: 1, font: { size: 9 } }
            },
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    },
                    font: { size: 9 }
                }
            }
        }
    }
});

// Status Distribution Chart
const ctx4 = document.getElementById('statusChart').getContext('2d');
const statusLabels = <?php echo json_encode(array_column($statusDistribution, 'status')); ?>;
const statusCounts = <?php echo json_encode(array_column($statusDistribution, 'count')); ?>;
const statusColors = {
    'confirmed': '#008009',
    'completed': '#003b95',
    'pending': '#ff8c00',
    'cancelled': '#e21111',
    'no_show': '#9ca3af'
};

new Chart(ctx4, {
    type: 'pie',
    data: {
        labels: statusLabels.map(s => s.charAt(0).toUpperCase() + s.slice(1)),
        datasets: [{
            data: statusCounts,
            backgroundColor: statusLabels.map(s => statusColors[s] || '#9ca3af'),
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { font: { size: 10 } } },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((context.raw / total) * 100).toFixed(1);
                        return `${context.label}: ${context.raw} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

// Show/hide custom date range
document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (this.getAttribute('href') === '?period=custom') {
            document.querySelector('.custom-date-range').style.display = 'flex';
        } else {
            document.querySelector('.custom-date-range').style.display = 'none';
        }
    });
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>