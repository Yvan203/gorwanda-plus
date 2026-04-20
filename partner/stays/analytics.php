<?php
$pageTitle = 'Analytics & Insights';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$dateRange = isset($_GET['date_range']) ? sanitize($_GET['date_range']) : '30';
$compareWith = isset($_GET['compare']) ? sanitize($_GET['compare']) : 'previous';
$chartType = isset($_GET['chart']) ? sanitize($_GET['chart']) : 'revenue';

// Calculate date ranges
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-$dateRange days"));
$previousStartDate = date('Y-m-d', strtotime("-" . ($dateRange * 2) . " days"));
$previousEndDate = date('Y-m-d', strtotime("-$dateRange days"));

// Year to date
$ytdStart = date('Y-01-01');
$ytdEnd = date('Y-m-d');

// Month to date
$mtdStart = date('Y-m-01');
$mtdEnd = date('Y-m-d');

// ============================================
// GET PROPERTIES
// ============================================
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one or 'all'
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Build property filter for queries
$propertyFilter = "";
$propertyParams = [];
if ($propertyId > 0) {
    $propertyFilter = "AND s.stay_id = ?";
    $propertyParams[] = $propertyId;
}

// ============================================
// REVENUE ANALYTICS
// ============================================

// Current period revenue
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COALESCE(SUM(b.commission_amount), 0) as total_commission,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COUNT(DISTINCT b.booking_id) as total_bookings,
        COUNT(DISTINCT b.user_id) as unique_customers,
        SUM(b.num_nights) as total_nights,
        COALESCE(SUM(b.total_amount) / NULLIF(SUM(b.num_nights), 0), 0) as avg_nightly_rate
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$currentPeriod = $stmt->fetch();

// Previous period revenue
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COUNT(DISTINCT b.booking_id) as total_bookings
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$previousStartDate, $previousEndDate, $userId], $propertyParams);
$stmt->execute($params);
$previousPeriod = $stmt->fetch();

// Calculate growth
$revenueGrowth = $previousPeriod['total_revenue'] > 0 
    ? round((($currentPeriod['total_revenue'] - $previousPeriod['total_revenue']) / $previousPeriod['total_revenue']) * 100, 1)
    : 100;
$bookingGrowth = $previousPeriod['total_bookings'] > 0
    ? round((($currentPeriod['total_bookings'] - $previousPeriod['total_bookings']) / $previousPeriod['total_bookings']) * 100, 1)
    : 100;

// ============================================
// DAILY REVENUE TREND
// ============================================
$stmt = $db->prepare("
    SELECT 
        DATE(b.created_at) as date,
        DATE_FORMAT(b.created_at, '%a, %b %e') as label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings,
        COALESCE(SUM(b.commission_amount), 0) as commission,
        COALESCE(AVG(b.total_amount), 0) as avg_booking
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
    GROUP BY DATE(b.created_at)
    ORDER BY date ASC
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$dailyTrend = $stmt->fetchAll();

// Fill in missing dates
$dailyData = [];
$current = new DateTime($startDate);
$end = new DateTime($endDate);
while ($current <= $end) {
    $dateStr = $current->format('Y-m-d');
    $found = false;
    foreach ($dailyTrend as $d) {
        if ($d['date'] == $dateStr) {
            $dailyData[] = $d;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $dailyData[] = [
            'date' => $dateStr,
            'label' => $current->format('D, M j'),
            'revenue' => 0,
            'bookings' => 0,
            'commission' => 0,
            'avg_booking' => 0
        ];
    }
    $current->modify('+1 day');
}

// ============================================
// MONTHLY TREND (Last 12 months)
// ============================================
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%Y-%m') as month,
        DATE_FORMAT(b.created_at, '%b %Y') as label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings,
        COALESCE(SUM(b.commission_amount), 0) as commission,
        COALESCE(AVG(b.total_amount), 0) as avg_booking,
        COUNT(DISTINCT b.user_id) as customers
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    AND s.owner_id = ?
    $propertyFilter
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY month ASC
");
$params = array_merge([$userId], $propertyParams);
$stmt->execute($params);
$monthlyTrend = $stmt->fetchAll();

// ============================================
// BOOKINGS BY STATUS
// ============================================
$stmt = $db->prepare("
    SELECT 
        b.status,
        COUNT(*) as count,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
    GROUP BY b.status
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$bookingStatus = $stmt->fetchAll();

$statusData = [
    'pending' => 0,
    'confirmed' => 0,
    'checked_in' => 0,
    'completed' => 0,
    'cancelled' => 0
];
foreach ($bookingStatus as $status) {
    $statusData[$status['status']] = $status['count'];
}

// ============================================
// ROOM PERFORMANCE
// ============================================
$stmt = $db->prepare("
    SELECT 
        sr.room_id,
        sr.room_name,
        sr.base_price,
        COUNT(b.booking_id) as bookings_count,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COALESCE(SUM(b.num_nights), 0) as total_nights,
        COALESCE(SUM(b.total_amount) / NULLIF(SUM(b.num_nights), 0), sr.base_price) as effective_rate,
        COUNT(DISTINCT b.user_id) as unique_guests,
        RANK() OVER (ORDER BY SUM(b.total_amount) DESC) as revenue_rank
    FROM stay_rooms sr
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
        AND b.status IN ('confirmed', 'completed')
        AND b.created_at BETWEEN ? AND ?
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
    $propertyFilter
    GROUP BY sr.room_id
    ORDER BY revenue DESC
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$roomPerformance = $stmt->fetchAll();

// ============================================
// OCCUPANCY RATES
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT sr.room_id) as total_rooms,
        SUM(sr.num_rooms_available) as total_capacity,
        COUNT(DISTINCT b.booking_id) as booked_rooms,
        COALESCE(SUM(b.num_nights), 0) as booked_nights,
        (COUNT(DISTINCT b.booking_id) * 30) as potential_nights
    FROM stay_rooms sr
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
        AND b.status IN ('confirmed', 'checked_in', 'completed')
        AND b.check_in_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$userId], $propertyParams);
$stmt->execute($params);
$occupancy = $stmt->fetch();

$occupancyRate = $occupancy['potential_nights'] > 0 
    ? round(($occupancy['booked_nights'] / $occupancy['potential_nights']) * 100, 1)
    : 0;

// ============================================
// CUSTOMER INSIGHTS
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT b.user_id) as total_customers,
        COUNT(DISTINCT CASE WHEN b.created_at >= ? THEN b.user_id END) as new_customers,
        COUNT(DISTINCT CASE WHEN b.user_id IN (
            SELECT user_id FROM bookings b2
            JOIN stay_rooms sr2 ON b2.stay_room_id = sr2.room_id
            JOIN stays s2 ON sr2.stay_id = s2.stay_id
            WHERE s2.owner_id = ? AND b2.created_at < ?
        ) THEN b.user_id END) as returning_customers,
        COALESCE(AVG(b.total_amount), 0) as avg_customer_spend,
        COUNT(DISTINCT b.booking_id) / NULLIF(COUNT(DISTINCT b.user_id), 0) as avg_bookings_per_customer
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$startDate, $userId, $startDate, $startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$customerInsights = $stmt->fetch();

// ============================================
// PEAK BOOKING TIMES
// ============================================
$stmt = $db->prepare("
    SELECT 
        HOUR(b.created_at) as hour,
        COUNT(*) as bookings,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
    GROUP BY HOUR(b.created_at)
    ORDER BY hour ASC
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$hourlyData = $stmt->fetchAll();

$hourlyBookings = array_fill(0, 24, 0);
foreach ($hourlyData as $h) {
    $hourlyBookings[$h['hour']] = $h['bookings'];
}

// ============================================
// WEEKLY PATTERN
// ============================================
$stmt = $db->prepare("
    SELECT 
        DAYOFWEEK(b.created_at) as day_of_week,
        COUNT(*) as bookings,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
    GROUP BY DAYOFWEEK(b.created_at)
    ORDER BY day_of_week ASC
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$weeklyData = $stmt->fetchAll();

$dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$weeklyBookings = array_fill(1, 7, 0);
foreach ($weeklyData as $w) {
    $weeklyBookings[$w['day_of_week']] = $w['bookings'];
}

// ============================================
// LEAD TIME ANALYSIS
// ============================================
$stmt = $db->prepare("
    SELECT 
        AVG(DATEDIFF(b.check_in_date, b.created_at)) as avg_lead_time,
        MIN(DATEDIFF(b.check_in_date, b.created_at)) as min_lead_time,
        MAX(DATEDIFF(b.check_in_date, b.created_at)) as max_lead_time,
        COUNT(CASE WHEN DATEDIFF(b.check_in_date, b.created_at) <= 7 THEN 1 END) as last_minute,
        COUNT(CASE WHEN DATEDIFF(b.check_in_date, b.created_at) BETWEEN 8 AND 30 THEN 1 END) as medium,
        COUNT(CASE WHEN DATEDIFF(b.check_in_date, b.created_at) > 30 THEN 1 END) as advance
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed', 'completed')
    AND b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$leadTime = $stmt->fetch();

// ============================================
// CANCELLATION ANALYSIS
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(CASE WHEN b.status = 'cancelled' THEN 1 END) as cancelled_count,
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN b.total_amount END), 0) as cancelled_revenue,
        AVG(CASE WHEN b.status = 'cancelled' THEN DATEDIFF(b.check_in_date, b.cancellation_date) END) as avg_cancellation_lead
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.created_at BETWEEN ? AND ?
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$startDate, $endDate, $userId], $propertyParams);
$stmt->execute($params);
$cancellationData = $stmt->fetch();

$cancellationRate = $cancellationData['total_count'] > 0 
    ? round(($cancellationData['cancelled_count'] / $cancellationData['total_count']) * 100, 1)
    : 0;

// ============================================
// FORECAST (Next 30 days)
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as upcoming_bookings,
        COALESCE(SUM(b.total_amount), 0) as expected_revenue,
        COUNT(DISTINCT b.user_id) as expected_guests,
        SUM(b.num_nights) as expected_nights
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE b.status IN ('confirmed')
    AND b.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND s.owner_id = ?
    $propertyFilter
");
$params = array_merge([$userId], $propertyParams);
$stmt->execute($params);
$forecast = $stmt->fetch();

// Month names for charts
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!-- Include Chart.js and Moment.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment@1.0.0/dist/chartjs-adapter-moment.min.js"></script>

<style>
/* Analytics Specific Styles */
.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.analytics-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.analytics-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
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
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.kpi-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--booking-border);
    position: relative;
    overflow: hidden;
}

.kpi-card.revenue { border-left: 4px solid var(--booking-blue); }
.kpi-card.bookings { border-left: 4px solid var(--booking-success); }
.kpi-card.occupancy { border-left: 4px solid var(--booking-warning); }
.kpi-card.customers { border-left: 4px solid #9333ea; }

.kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.kpi-trend {
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 100px;
}

.trend-up {
    background: #e6f4ea;
    color: var(--booking-success);
}

.trend-down {
    background: #fce8e8;
    color: var(--booking-danger);
}

.kpi-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--booking-text);
    line-height: 1.2;
    margin-bottom: 4px;
}

.kpi-subtitle {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.kpi-footer {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
    font-size: 0.75rem;
    display: flex;
    justify-content: space-between;
    color: var(--booking-text-light);
}

.kpi-footer strong {
    color: var(--booking-text);
}

/* Chart Grid */
.chart-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.chart-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.chart-legend {
    display: flex;
    gap: 16px;
    font-size: 0.75rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 3px;
}

.chart-container {
    height: 300px;
    position: relative;
}

/* Mini Stats */
.mini-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.mini-stat {
    background: white;
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--booking-border);
    display: flex;
    align-items: center;
    gap: 12px;
}

.mini-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.mini-stat-icon.blue { background: var(--booking-light-blue); color: var(--booking-blue); }
.mini-stat-icon.green { background: #e6f4ea; color: var(--booking-success); }
.mini-stat-icon.orange { background: #fff4e6; color: var(--booking-warning); }
.mini-stat-icon.purple { background: #f3e8ff; color: #9333ea; }

.mini-stat-info h4 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.mini-stat-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Table Styles */
.analytics-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}

.analytics-table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--booking-gray);
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    font-size: 0.6875rem;
    border-bottom: 1px solid var(--booking-border);
}

.analytics-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
}

.analytics-table tr:hover td {
    background: var(--booking-light-blue);
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    font-weight: 700;
    font-size: 0.75rem;
}

.rank-1 { background: gold; color: #1a1a1a; }
.rank-2 { background: silver; color: #1a1a1a; }
.rank-3 { background: #cd7f32; color: white; }

/* Progress Bar */
.progress-bar {
    width: 100%;
    height: 8px;
    background: var(--booking-gray);
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--booking-blue);
    border-radius: 4px;
    transition: width 0.3s;
}

/* Tooltip */
.analytics-tooltip {
    position: absolute;
    background: #1a1a1a;
    color: white;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    pointer-events: none;
    z-index: 1000;
    display: none;
}

/* Responsive */
@media (max-width: 1200px) {
    .kpi-grid,
    .chart-grid,
    .mini-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .kpi-grid,
    .chart-grid,
    .mini-stats {
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
    
    .filter-select {
        width: 100%;
    }
}
</style>

<div class="analytics-header">
    <div class="analytics-title">
        <h1>Analytics & Insights</h1>
        <p>Deep dive into your business performance</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportAnalytics()">
            <i class="bi bi-download"></i> Export Report
        </button>
        <button class="btn-secondary" onclick="refreshData()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-group">
        <label>Property</label>
        <select class="filter-select" onchange="changeProperty(this.value)">
            <option value="0">All Properties</option>
            <?php foreach ($properties as $prop): ?>
            <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
                <?php echo sanitize($prop['stay_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Date Range</label>
        <select class="filter-select" onchange="changeDateRange(this.value)">
            <option value="7" <?php echo $dateRange == '7' ? 'selected' : ''; ?>>Last 7 days</option>
            <option value="30" <?php echo $dateRange == '30' ? 'selected' : ''; ?>>Last 30 days</option>
            <option value="90" <?php echo $dateRange == '90' ? 'selected' : ''; ?>>Last 90 days</option>
            <option value="365" <?php echo $dateRange == '365' ? 'selected' : ''; ?>>Last 12 months</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Compare</label>
        <select class="filter-select" onchange="changeCompare(this.value)">
            <option value="previous" <?php echo $compareWith == 'previous' ? 'selected' : ''; ?>>Previous period</option>
            <option value="ytd" <?php echo $compareWith == 'ytd' ? 'selected' : ''; ?>>Year to date</option>
            <option value="mtd" <?php echo $compareWith == 'mtd' ? 'selected' : ''; ?>>Month to date</option>
        </select>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card revenue">
        <div class="kpi-header">
            <span class="kpi-title">Total Revenue</span>
            <span class="kpi-trend <?php echo $revenueGrowth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                <i class="bi bi-arrow-<?php echo $revenueGrowth >= 0 ? 'up' : 'down'; ?>-short"></i>
                <?php echo abs($revenueGrowth); ?>%
            </span>
        </div>
        <div class="kpi-value"><?php echo formatPrice($currentPeriod['total_revenue']); ?></div>
        <div class="kpi-subtitle">vs <?php echo formatPrice($previousPeriod['total_revenue']); ?> previous</div>
        <div class="kpi-footer">
            <span>Avg. booking: <?php echo formatPrice($currentPeriod['avg_booking_value']); ?></span>
            <span>Commission: <?php echo formatPrice($currentPeriod['total_commission']); ?></span>
        </div>
    </div>
    
    <div class="kpi-card bookings">
        <div class="kpi-header">
            <span class="kpi-title">Total Bookings</span>
            <span class="kpi-trend <?php echo $bookingGrowth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                <i class="bi bi-arrow-<?php echo $bookingGrowth >= 0 ? 'up' : 'down'; ?>-short"></i>
                <?php echo abs($bookingGrowth); ?>%
            </span>
        </div>
        <div class="kpi-value"><?php echo number_format($currentPeriod['total_bookings']); ?></div>
        <div class="kpi-subtitle"><?php echo $currentPeriod['unique_customers']; ?> unique customers</div>
        <div class="kpi-footer">
            <span>Nights: <?php echo $currentPeriod['total_nights']; ?></span>
            <span>Avg rate: <?php echo formatPrice($currentPeriod['avg_nightly_rate']); ?></span>
        </div>
    </div>
    
    <div class="kpi-card occupancy">
        <div class="kpi-header">
            <span class="kpi-title">Occupancy Rate</span>
        </div>
        <div class="kpi-value"><?php echo $occupancyRate; ?>%</div>
        <div class="kpi-subtitle">Last 30 days</div>
        <div class="kpi-footer">
            <span>Capacity: <?php echo $occupancy['total_capacity'] ?: 0; ?> rooms</span>
            <span>Booked: <?php echo $occupancy['booked_nights'] ?: 0; ?> nights</span>
        </div>
    </div>
    
    <div class="kpi-card customers">
        <div class="kpi-header">
            <span class="kpi-title">Customer Insights</span>
        </div>
        <div class="kpi-value"><?php echo $customerInsights['total_customers']; ?></div>
        <div class="kpi-subtitle">total customers</div>
        <div class="kpi-footer">
            <span>New: <?php echo $customerInsights['new_customers']; ?></span>
            <span>Returning: <?php echo $customerInsights['returning_customers']; ?></span>
        </div>
    </div>
</div>

<!-- Main Charts Grid -->
<div class="chart-grid">
    <!-- Revenue Trend Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Revenue Trend</h3>
            <div class="chart-legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--booking-blue);"></div>
                    <span>Revenue</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--booking-success);"></div>
                    <span>Bookings</span>
                </div>
            </div>
        </div>
        <div class="chart-container">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
    
    <!-- Booking Status Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Booking Status Distribution</h3>
        </div>
        <div class="chart-container">
            <canvas id="statusChart"></canvas>
        </div>
        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 16px; flex-wrap: wrap;">
            <div class="legend-item">
                <div class="legend-color" style="background: var(--booking-warning);"></div>
                <span>Pending: <?php echo $statusData['pending']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--booking-success);"></div>
                <span>Confirmed: <?php echo $statusData['confirmed']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--booking-blue);"></div>
                <span>Checked In: <?php echo $statusData['checked_in']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #9333ea;"></div>
                <span>Completed: <?php echo $statusData['completed']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--booking-danger);"></div>
                <span>Cancelled: <?php echo $statusData['cancelled']; ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Charts Grid -->
<div class="chart-grid">
    <!-- Daily Bookings Pattern -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Booking Time of Day</h3>
        </div>
        <div class="chart-container">
            <canvas id="hourlyChart"></canvas>
        </div>
    </div>
    
    <!-- Weekly Pattern -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Bookings by Day of Week</h3>
        </div>
        <div class="chart-container">
            <canvas id="weeklyChart"></canvas>
        </div>
    </div>
</div>

<!-- Mini Stats -->
<div class="mini-stats">
    <div class="mini-stat">
        <div class="mini-stat-icon blue">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo $forecast['upcoming_bookings'] ?: 0; ?></h4>
            <p>Upcoming bookings</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon green">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo formatPrice($forecast['expected_revenue']); ?></h4>
            <p>Expected revenue</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon orange">
            <i class="bi bi-people"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo $forecast['expected_guests'] ?: 0; ?></h4>
            <p>Expected guests</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon purple">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo round($leadTime['avg_lead_time'] ?: 0); ?> days</h4>
            <p>Avg. lead time</p>
        </div>
    </div>
</div>

<!-- Room Performance Table -->
<div class="chart-card" style="grid-column: span 2; margin-bottom: 24px;">
    <div class="chart-header">
        <h3 class="chart-title">Room Performance</h3>
        <span class="kpi-subtitle">Ranked by revenue</span>
    </div>
    <table class="analytics-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Room</th>
                <th>Base Price</th>
                <th>Bookings</th>
                <th>Nights</th>
                <th>Revenue</th>
                <th>Avg. Value</th>
                <th>Effective Rate</th>
                <th>Unique Guests</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($roomPerformance)): ?>
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--booking-text-light);">
                    No data available for this period
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($roomPerformance as $room): ?>
                <tr>
                    <td>
                        <span class="rank-badge rank-<?php echo $room['revenue_rank'] <= 3 ? $room['revenue_rank'] : ''; ?>" 
                              style="<?php echo $room['revenue_rank'] > 3 ? 'background: var(--booking-gray); color: var(--booking-text);' : ''; ?>">
                            <?php echo $room['revenue_rank']; ?>
                        </span>
                    </td>
                    <td><strong><?php echo sanitize($room['room_name']); ?></strong></td>
                    <td><?php echo formatPrice($room['base_price']); ?></td>
                    <td><?php echo $room['bookings_count']; ?></td>
                    <td><?php echo $room['total_nights']; ?></td>
                    <td><strong><?php echo formatPrice($room['revenue']); ?></strong></td>
                    <td><?php echo formatPrice($room['avg_booking_value']); ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span><?php echo formatPrice($room['effective_rate']); ?></span>
                            <?php 
                            $rateDiff = $room['effective_rate'] - $room['base_price'];
                            $percentDiff = $room['base_price'] > 0 ? round(($rateDiff / $room['base_price']) * 100, 1) : 0;
                            if (abs($percentDiff) > 5): 
                            ?>
                            <span style="font-size: 0.625rem; color: <?php echo $percentDiff > 0 ? 'var(--booking-success)' : 'var(--booking-danger)'; ?>;">
                                (<?php echo $percentDiff > 0 ? '+' : ''; ?><?php echo $percentDiff; ?>%)
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo $room['unique_guests']; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Insights Row -->
<div class="chart-grid">
    <!-- Lead Time Analysis -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Booking Lead Time</h3>
        </div>
        <div style="padding: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--booking-blue);">
                        <?php echo round($leadTime['avg_lead_time'] ?: 0); ?> days
                    </div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);">Average</div>
                </div>
                <div>
                    <div style="font-size: 1rem; font-weight: 600;"><?php echo $leadTime['min_lead_time'] ?: 0; ?> - <?php echo $leadTime['max_lead_time'] ?: 0; ?></div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);">Range</div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.75rem;">Last minute (≤7 days)</span>
                    <span style="font-weight: 600;"><?php echo $leadTime['last_minute'] ?: 0; ?> bookings</span>
                </div>
                <div class="progress-bar" style="margin-bottom: 12px;">
                    <?php $lastMinutePercent = $currentPeriod['total_bookings'] > 0 ? round(($leadTime['last_minute'] / $currentPeriod['total_bookings']) * 100) : 0; ?>
                    <div class="progress-fill" style="width: <?php echo $lastMinutePercent; ?>%; background: var(--booking-warning);"></div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.75rem;">Medium (8-30 days)</span>
                    <span style="font-weight: 600;"><?php echo $leadTime['medium'] ?: 0; ?> bookings</span>
                </div>
                <div class="progress-bar" style="margin-bottom: 12px;">
                    <?php $mediumPercent = $currentPeriod['total_bookings'] > 0 ? round(($leadTime['medium'] / $currentPeriod['total_bookings']) * 100) : 0; ?>
                    <div class="progress-fill" style="width: <?php echo $mediumPercent; ?>%; background: var(--booking-blue);"></div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.75rem;">Advance (>30 days)</span>
                    <span style="font-weight: 600;"><?php echo $leadTime['advance'] ?: 0; ?> bookings</span>
                </div>
                <div class="progress-bar">
                    <?php $advancePercent = $currentPeriod['total_bookings'] > 0 ? round(($leadTime['advance'] / $currentPeriod['total_bookings']) * 100) : 0; ?>
                    <div class="progress-fill" style="width: <?php echo $advancePercent; ?>%; background: var(--booking-success);"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancellation Analysis -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Cancellation Analysis</h3>
        </div>
        <div style="padding: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                <div>
                    <div style="font-size: 2rem; font-weight: 700; color: var(--booking-danger);">
                        <?php echo $cancellationRate; ?>%
                    </div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);">Cancellation rate</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.25rem; font-weight: 600;"><?php echo $cancellationData['cancelled_count'] ?: 0; ?></div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);">of <?php echo $cancellationData['total_count'] ?: 0; ?> bookings</div>
                </div>
            </div>
            
            <?php if ($cancellationData['cancelled_count'] > 0): ?>
            <div style="background: #fce8e8; padding: 16px; border-radius: var(--radius-sm);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 0.875rem; color: var(--booking-danger);">Lost revenue</span>
                    <span style="font-weight: 700; color: var(--booking-danger);"><?php echo formatPrice($cancellationData['cancelled_revenue']); ?></span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-size: 0.875rem; color: var(--booking-danger);">Avg. cancellation</span>
                    <span style="font-weight: 600; color: var(--booking-danger);"><?php echo round($cancellationData['avg_cancellation_lead'] ?: 0); ?> days before</span>
                </div>
            </div>
            <?php else: ?>
            <div style="background: #e6f4ea; padding: 20px; border-radius: var(--radius-sm); text-align: center;">
                <i class="bi bi-check-circle-fill" style="color: var(--booking-success); font-size: 2rem; display: block; margin-bottom: 8px;"></i>
                <p style="color: var(--booking-success); font-weight: 600;">No cancellations this period</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ============================================
// CHART INITIALIZATION
// ============================================

// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($dailyData, 'label')); ?>,
        datasets: [
            {
                label: 'Revenue',
                data: <?php echo json_encode(array_column($dailyData, 'revenue')); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-revenue'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode(array_column($dailyData, 'bookings')); ?>,
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
        interaction: {
            mode: 'index',
            intersect: false
        },
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
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    maxRotation: 45,
                    minRotation: 45,
                    maxTicksLimit: 10
                }
            },
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'left',
                grid: {
                    color: '#f0f0f0'
                },
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    }
                }
            },
            'y-bookings': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: {
                    drawOnChartArea: false
                },
                ticks: {
                    stepSize: 1,
                    callback: function(value) {
                        return value + ' bookings';
                    }
                }
            }
        }
    }
});

// Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Pending', 'Confirmed', 'Checked In', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?php echo $statusData['pending']; ?>,
                <?php echo $statusData['confirmed']; ?>,
                <?php echo $statusData['checked_in']; ?>,
                <?php echo $statusData['completed']; ?>,
                <?php echo $statusData['cancelled']; ?>
            ],
            backgroundColor: [
                '#ff8c00',
                '#008009',
                '#003b95',
                '#9333ea',
                '#e21111'
            ],
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
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                        return `${label}: ${value} (${percentage}%)`;
                    }
                }
            }
        }
    }
});

// Hourly Chart
const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: ['12am', '1am', '2am', '3am', '4am', '5am', '6am', '7am', '8am', '9am', '10am', '11am',
                 '12pm', '1pm', '2pm', '3pm', '4pm', '5pm', '6pm', '7pm', '8pm', '9pm', '10pm', '11pm'],
        datasets: [{
            label: 'Bookings',
            data: <?php echo json_encode(array_values($hourlyBookings)); ?>,
            backgroundColor: 'rgba(0, 59, 149, 0.7)',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// Weekly Chart
const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
new Chart(weeklyCtx, {
    type: 'bar',
    data: {
        labels: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        datasets: [{
            label: 'Bookings',
            data: [
                <?php echo $weeklyBookings[1]; ?>,
                <?php echo $weeklyBookings[2]; ?>,
                <?php echo $weeklyBookings[3]; ?>,
                <?php echo $weeklyBookings[4]; ?>,
                <?php echo $weeklyBookings[5]; ?>,
                <?php echo $weeklyBookings[6]; ?>,
                <?php echo $weeklyBookings[7]; ?>
            ],
            backgroundColor: function(context) {
                const value = context.dataset.data[context.dataIndex];
                const max = Math.max(...context.dataset.data);
                const opacity = 0.4 + (value / max) * 0.6;
                return `rgba(0, 59, 149, ${opacity})`;
            },
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// ============================================
// UTILITY FUNCTIONS
// ============================================
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function changeProperty(propertyId) {
    const url = new URL(window.location.href);
    url.searchParams.set('property', propertyId);
    window.location.href = url.toString();
}

function changeDateRange(range) {
    const url = new URL(window.location.href);
    url.searchParams.set('date_range', range);
    window.location.href = url.toString();
}

function changeCompare(compare) {
    const url = new URL(window.location.href);
    url.searchParams.set('compare', compare);
    window.location.href = url.toString();
}

function refreshData() {
    window.location.reload();
}

function exportAnalytics() {
    // Create CSV content
    let csv = "Date,Revenue,Bookings,Commission,Avg Booking\n";
    <?php foreach ($dailyData as $day): ?>
    csv += "<?php echo $day['date']; ?>,<?php echo $day['revenue']; ?>,<?php echo $day['bookings']; ?>,<?php echo $day['commission']; ?>,<?php echo $day['avg_booking']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'analytics_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Resize charts on window resize
window.addEventListener('resize', function() {
    // Charts will auto-resize due to responsive: true
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>