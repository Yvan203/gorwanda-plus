<?php
$pageTitle = 'Analytics & Insights';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get vendor profile ID
$stmt = $db->prepare("SELECT vendor_id FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$vendor = $stmt->fetch();
$vendorId = $vendor ? $vendor['vendor_id'] : 0;

// ============================================
// DATE RANGE FILTERS
// ============================================
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : '30';
$compareWith = isset($_GET['compare']) ? $_GET['compare'] : 'previous';
$chartType = isset($_GET['chart']) ? $_GET['chart'] : 'revenue';
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;

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

// Build vehicle filter and params arrays properly
$vehicleFilter = "";
$vehicleParams = [];
if ($vehicleId > 0) {
    $vehicleFilter = "AND b.car_id = ?";
    $vehicleParams[] = $vehicleId;
}

// ============================================
// GET ALL VEHICLES FOR FILTER
// ============================================
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// Ensure vehicles is array
if (!is_array($vehicles)) {
    $vehicles = [];
}

// ============================================
// KPI CARDS DATA
// ============================================

// Current period revenue - FIX: Proper parameter binding
$sql = "
    SELECT 
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COALESCE(SUM(b.commission_amount), 0) as total_commission,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COUNT(DISTINCT b.booking_id) as total_bookings,
        COUNT(DISTINCT b.user_id) as unique_customers,
        SUM(DATEDIFF(b.return_date, b.pickup_date)) as total_rental_days,
        COALESCE(SUM(b.total_amount) / NULLIF(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0), 0) as avg_daily_rate
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND DATE(b.pickup_date) BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
// FIX: Build params array correctly - start with base params, then add vehicle params if any
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$currentPeriod = $stmt->fetch();

// Ensure currentPeriod has all required keys with defaults
$currentPeriod = array_merge([
    'total_revenue' => 0,
    'total_commission' => 0,
    'avg_booking_value' => 0,
    'total_bookings' => 0,
    'unique_customers' => 0,
    'total_rental_days' => 0,
    'avg_daily_rate' => 0
], $currentPeriod ?: []);

// Previous period for comparison - FIX: Same parameter binding fix
$sql = "
    SELECT 
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COUNT(DISTINCT b.booking_id) as total_bookings,
        SUM(DATEDIFF(b.return_date, b.pickup_date)) as total_rental_days
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND DATE(b.pickup_date) BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
$params = [$previousStartDate, $previousEndDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$previousPeriod = $stmt->fetch();

// Ensure previousPeriod has defaults
$previousPeriod = array_merge([
    'total_revenue' => 0,
    'total_bookings' => 0,
    'total_rental_days' => 0
], $previousPeriod ?: []);

// Calculate growth percentages with null checks
$revenueGrowth = ($previousPeriod['total_revenue'] ?? 0) > 0 
    ? round(((($currentPeriod['total_revenue'] ?? 0) - ($previousPeriod['total_revenue'] ?? 0)) / ($previousPeriod['total_revenue'] ?? 1)) * 100, 1)
    : 100;
$bookingGrowth = ($previousPeriod['total_bookings'] ?? 0) > 0
    ? round(((($currentPeriod['total_bookings'] ?? 0) - ($previousPeriod['total_bookings'] ?? 0)) / ($previousPeriod['total_bookings'] ?? 1)) * 100, 1)
    : 100;
$daysGrowth = ($previousPeriod['total_rental_days'] ?? 0) > 0
    ? round(((($currentPeriod['total_rental_days'] ?? 0) - ($previousPeriod['total_rental_days'] ?? 0)) / ($previousPeriod['total_rental_days'] ?? 1)) * 100, 1)
    : 100;

// ============================================
// FLEET UTILIZATION
// ============================================
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT cf.car_id) as total_vehicles,
        SUM(cf.quantity_available) as total_cars,
        COUNT(DISTINCT b.car_id) as active_vehicles,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as booked_days,
        (SUM(cf.quantity_available) * ?) as potential_days
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id 
        AND b.status IN ('confirmed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
    WHERE cr.owner_id = ?
");
$stmt->execute([$dateRange, $startDate, $endDate, $userId]);
$utilization = $stmt->fetch();

// Ensure utilization has defaults
$utilization = array_merge([
    'total_vehicles' => 0,
    'total_cars' => 0,
    'active_vehicles' => 0,
    'booked_days' => 0,
    'potential_days' => 0
], $utilization ?: []);

$utilizationRate = ($utilization['potential_days'] ?? 0) > 0 
    ? round((($utilization['booked_days'] ?? 0) / ($utilization['potential_days'] ?? 1)) * 100, 1)
    : 0;

// ============================================
// DAILY REVENUE TREND
// ============================================
$sql = "
    SELECT 
        DATE(b.pickup_date) as date,
        DATE_FORMAT(b.pickup_date, '%a, %b %e') as label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings,
        COALESCE(SUM(b.commission_amount), 0) as commission,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days,
        COALESCE(AVG(b.total_amount), 0) as avg_booking
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND DATE(b.pickup_date) BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY DATE(b.pickup_date)
    ORDER BY date ASC
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$dailyTrend = $stmt->fetchAll();

// Ensure dailyTrend is array
if (!is_array($dailyTrend)) {
    $dailyTrend = [];
}

// Fill in missing dates
$dailyData = [];
$current = new DateTime($startDate);
$end = new DateTime($endDate);
while ($current <= $end) {
    $dateStr = $current->format('Y-m-d');
    $found = false;
    foreach ($dailyTrend as $d) {
        if (is_array($d) && ($d['date'] ?? '') == $dateStr) {
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
            'rental_days' => 0,
            'avg_booking' => 0
        ];
    }
    $current->modify('+1 day');
}

// ============================================
// MONTHLY TREND (Last 12 months)
// ============================================
$sql = "
    SELECT 
        DATE_FORMAT(b.pickup_date, '%Y-%m') as month,
        DATE_FORMAT(b.pickup_date, '%b %Y') as label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings,
        COALESCE(SUM(b.commission_amount), 0) as commission,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days,
        COALESCE(AVG(b.total_amount), 0) as avg_booking,
        COUNT(DISTINCT b.user_id) as customers,
        COUNT(DISTINCT b.car_id) as vehicles_used
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY DATE_FORMAT(b.pickup_date, '%Y-%m')
    ORDER BY month ASC
";

$stmt = $db->prepare($sql);
$params = [$userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$monthlyTrend = $stmt->fetchAll();

if (!is_array($monthlyTrend)) {
    $monthlyTrend = [];
}

// ============================================
// BOOKINGS BY STATUS
// ============================================
$sql = "
    SELECT 
        b.status,
        COUNT(*) as count,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY b.status
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$bookingStatus = $stmt->fetchAll();

if (!is_array($bookingStatus)) {
    $bookingStatus = [];
}

$statusData = [
    'pending' => ['count' => 0, 'revenue' => 0, 'days' => 0],
    'confirmed' => ['count' => 0, 'revenue' => 0, 'days' => 0],
    'checked_out' => ['count' => 0, 'revenue' => 0, 'days' => 0],
    'completed' => ['count' => 0, 'revenue' => 0, 'days' => 0],
    'cancelled' => ['count' => 0, 'revenue' => 0, 'days' => 0]
];

foreach ($bookingStatus as $status) {
    if (is_array($status) && isset($status['status'])) {
        $statusData[$status['status']] = [
            'count' => $status['count'] ?? 0,
            'revenue' => $status['revenue'] ?? 0,
            'days' => $status['rental_days'] ?? 0
        ];
    }
}

// ============================================
// VEHICLE PERFORMANCE
// ============================================
$sql = "
    SELECT 
        cf.car_id,
        cf.brand,
        cf.model,
        cf.car_type,
        cf.daily_rate as base_rate,
        COUNT(b.booking_id) as bookings_count,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as total_rental_days,
        COALESCE(AVG(DATEDIFF(b.return_date, b.pickup_date)), 0) as avg_rental_days,
        COALESCE(SUM(b.total_amount) / NULLIF(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0), cf.daily_rate) as effective_daily_rate,
        COUNT(DISTINCT b.user_id) as unique_customers,
        COALESCE(SUM(b.extra_km_charge + b.additional_charges), 0) as extra_revenue,
        RANK() OVER (ORDER BY SUM(b.total_amount) DESC) as revenue_rank
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id 
        AND b.status IN ('confirmed', 'completed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
    WHERE cr.owner_id = ?
    $vehicleFilter
    GROUP BY cf.car_id
    ORDER BY revenue DESC
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$vehiclePerformance = $stmt->fetchAll();

if (!is_array($vehiclePerformance)) {
    $vehiclePerformance = [];
}

// ============================================
// VEHICLE TYPE PERFORMANCE
// ============================================
$sql = "
    SELECT 
        cf.car_type,
        COUNT(DISTINCT cf.car_id) as vehicle_count,
        COUNT(b.booking_id) as bookings_count,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COALESCE(SUM(b.total_amount) / NULLIF(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0), 0) as avg_daily_rate
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id 
        AND b.status IN ('confirmed', 'completed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
    WHERE cr.owner_id = ?
    GROUP BY cf.car_type
    ORDER BY revenue DESC
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$typePerformance = $stmt->fetchAll();

if (!is_array($typePerformance)) {
    $typePerformance = [];
}

// ============================================
// CUSTOMER INSIGHTS
// ============================================
$sql = "
    SELECT 
        COUNT(DISTINCT b.user_id) as total_customers,
        COUNT(DISTINCT CASE WHEN b.pickup_date >= ? THEN b.user_id END) as new_customers,
        COUNT(DISTINCT CASE WHEN b.user_id IN (
            SELECT user_id FROM bookings b2
            JOIN car_fleet cf2 ON b2.car_id = cf2.car_id
            JOIN car_rentals cr2 ON cf2.rental_id = cr2.rental_id
            WHERE cr2.owner_id = ? AND b2.pickup_date < ?
        ) THEN b.user_id END) as returning_customers,
        COALESCE(AVG(b.total_amount), 0) as avg_customer_spend,
        COUNT(b.booking_id) / NULLIF(COUNT(DISTINCT b.user_id), 0) as avg_bookings_per_customer,
        COALESCE(AVG(DATEDIFF(b.return_date, b.pickup_date)), 0) as avg_rental_days_per_customer
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
// FIX: Correct parameter order for the subquery
$params = [$startDate, $userId, $startDate, $startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$customerInsights = $stmt->fetch();

// Ensure customerInsights has defaults
$customerInsights = array_merge([
    'total_customers' => 0,
    'new_customers' => 0,
    'returning_customers' => 0,
    'avg_customer_spend' => 0,
    'avg_bookings_per_customer' => 0,
    'avg_rental_days_per_customer' => 0
], $customerInsights ?: []);

// ============================================
// PEAK BOOKING TIMES (Hourly)
// ============================================
$sql = "
    SELECT 
        HOUR(b.pickup_date) as hour,
        COUNT(*) as bookings,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY HOUR(b.pickup_date)
    ORDER BY hour ASC
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$hourlyData = $stmt->fetchAll();

if (!is_array($hourlyData)) {
    $hourlyData = [];
}

$hourlyBookings = array_fill(0, 24, 0);
$hourlyRevenue = array_fill(0, 24, 0);
foreach ($hourlyData as $h) {
    if (is_array($h) && isset($h['hour'])) {
        $hourlyBookings[$h['hour']] = $h['bookings'] ?? 0;
        $hourlyRevenue[$h['hour']] = $h['revenue'] ?? 0;
    }
}

// ============================================
// DAY OF WEEK PATTERN
// ============================================
$sql = "
    SELECT 
        DAYOFWEEK(b.pickup_date) as day_of_week,
        COUNT(*) as bookings,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY DAYOFWEEK(b.pickup_date)
    ORDER BY day_of_week ASC
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$weeklyData = $stmt->fetchAll();

if (!is_array($weeklyData)) {
    $weeklyData = [];
}

$dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$weeklyBookings = array_fill(1, 7, 0);
$weeklyRevenue = array_fill(1, 7, 0);
foreach ($weeklyData as $w) {
    if (is_array($w) && isset($w['day_of_week'])) {
        $weeklyBookings[$w['day_of_week']] = $w['bookings'] ?? 0;
        $weeklyRevenue[$w['day_of_week']] = $w['revenue'] ?? 0;
    }
}

// ============================================
// LEAD TIME ANALYSIS
// ============================================
$sql = "
    SELECT 
        AVG(DATEDIFF(b.pickup_date, b.created_at)) as avg_lead_time,
        MIN(DATEDIFF(b.pickup_date, b.created_at)) as min_lead_time,
        MAX(DATEDIFF(b.pickup_date, b.created_at)) as max_lead_time,
        COUNT(CASE WHEN DATEDIFF(b.pickup_date, b.created_at) <= 2 THEN 1 END) as last_minute,
        COUNT(CASE WHEN DATEDIFF(b.pickup_date, b.created_at) BETWEEN 3 AND 7 THEN 1 END) as short_planning,
        COUNT(CASE WHEN DATEDIFF(b.pickup_date, b.created_at) BETWEEN 8 AND 30 THEN 1 END) as medium_planning,
        COUNT(CASE WHEN DATEDIFF(b.pickup_date, b.created_at) > 30 THEN 1 END) as advance_planning
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$leadTime = $stmt->fetch();

// Ensure leadTime has defaults
$leadTime = array_merge([
    'avg_lead_time' => 0,
    'min_lead_time' => 0,
    'max_lead_time' => 0,
    'last_minute' => 0,
    'short_planning' => 0,
    'medium_planning' => 0,
    'advance_planning' => 0
], $leadTime ?: []);

// ============================================
// RENTAL DURATION DISTRIBUTION
// ============================================
$sql = "
    SELECT 
        CASE 
            WHEN DATEDIFF(b.return_date, b.pickup_date) = 1 THEN '1 day'
            WHEN DATEDIFF(b.return_date, b.pickup_date) BETWEEN 2 AND 3 THEN '2-3 days'
            WHEN DATEDIFF(b.return_date, b.pickup_date) BETWEEN 4 AND 7 THEN '4-7 days'
            WHEN DATEDIFF(b.return_date, b.pickup_date) BETWEEN 8 AND 14 THEN '1-2 weeks'
            ELSE '2+ weeks'
        END as duration_range,
        COUNT(*) as count,
        COALESCE(SUM(b.total_amount), 0) as revenue
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY duration_range
    ORDER BY 
        CASE duration_range
            WHEN '1 day' THEN 1
            WHEN '2-3 days' THEN 2
            WHEN '4-7 days' THEN 3
            WHEN '1-2 weeks' THEN 4
            ELSE 5
        END
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$durationData = $stmt->fetchAll();

if (!is_array($durationData)) {
    $durationData = [];
}

// ============================================
// CANCELLATION ANALYSIS
// ============================================
$sql = "
    SELECT 
        COUNT(CASE WHEN b.status = 'cancelled' THEN 1 END) as cancelled_count,
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN b.total_amount END), 0) as cancelled_revenue,
        AVG(CASE WHEN b.status = 'cancelled' THEN DATEDIFF(b.pickup_date, b.cancellation_date) END) as avg_cancellation_lead,
        COUNT(CASE WHEN b.cancellation_reason IS NOT NULL THEN 1 END) as cancellations_with_reason,
        b.cancellation_reason,
        COUNT(*) as reason_count
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
    GROUP BY b.cancellation_reason
    ORDER BY reason_count DESC
    LIMIT 5
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$cancellationReasons = $stmt->fetchAll();

if (!is_array($cancellationReasons)) {
    $cancellationReasons = [];
}

$totalBookingsForCalc = ($currentPeriod['total_bookings'] ?? 0) + ($statusData['cancelled']['count'] ?? 0);
$cancellationRate = $totalBookingsForCalc > 0 
    ? round((($statusData['cancelled']['count'] ?? 0) / $totalBookingsForCalc) * 100, 1)
    : 0;

// ============================================
// EXTRA REVENUE ANALYSIS
// ============================================
$sql = "
    SELECT 
        COUNT(CASE WHEN b.extra_km_charge > 0 THEN 1 END) as bookings_with_extra_km,
        COALESCE(SUM(b.extra_km_charge), 0) as total_extra_km_charges,
        AVG(b.extra_km_charge) as avg_extra_km_charge,
        COUNT(CASE WHEN b.additional_charges > 0 THEN 1 END) as bookings_with_additional,
        COALESCE(SUM(b.additional_charges), 0) as total_additional_charges,
        AVG(b.additional_charges) as avg_additional_charge
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status IN ('confirmed', 'completed', 'checked_out')
    AND b.pickup_date BETWEEN ? AND ?
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
$params = [$startDate, $endDate, $userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$extraRevenue = $stmt->fetch();

// Ensure extraRevenue has defaults
$extraRevenue = array_merge([
    'bookings_with_extra_km' => 0,
    'total_extra_km_charges' => 0,
    'avg_extra_km_charge' => 0,
    'bookings_with_additional' => 0,
    'total_additional_charges' => 0,
    'avg_additional_charge' => 0
], $extraRevenue ?: []);

// ============================================
// FORECAST (Next 30 days)
// ============================================
$sql = "
    SELECT 
        COUNT(*) as upcoming_bookings,
        COALESCE(SUM(b.total_amount), 0) as expected_revenue,
        COUNT(DISTINCT b.user_id) as expected_customers,
        SUM(DATEDIFF(b.return_date, b.pickup_date)) as expected_days,
        AVG(DATEDIFF(b.return_date, b.pickup_date)) as avg_expected_days
    FROM bookings b
    JOIN car_fleet cf ON b.car_id = cf.car_id
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE b.status = 'confirmed'
    AND b.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    AND cr.owner_id = ?
    $vehicleFilter
";

$stmt = $db->prepare($sql);
$params = [$userId];
if (!empty($vehicleParams)) {
    $params = array_merge($params, $vehicleParams);
}
$stmt->execute($params);
$forecast = $stmt->fetch();

// Ensure forecast has defaults
$forecast = array_merge([
    'upcoming_bookings' => 0,
    'expected_revenue' => 0,
    'expected_customers' => 0,
    'expected_days' => 0,
    'avg_expected_days' => 0
], $forecast ?: []);

// ============================================
// FLEET AVAILABILITY FORECAST
// ============================================
$stmt = $db->prepare("
    SELECT 
        DATE(b.pickup_date) as date,
        COUNT(DISTINCT b.car_id) as cars_booked,
        COUNT(DISTINCT cf.car_id) as total_cars
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN bookings b ON cf.car_id = b.car_id 
        AND b.status = 'confirmed'
        AND b.pickup_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND b.return_date >= CURDATE()
    WHERE cr.owner_id = ?
    GROUP BY DATE(b.pickup_date)
    ORDER BY date ASC
    LIMIT 30
");
$stmt->execute([$userId]);
$availabilityForecast = $stmt->fetchAll();

if (!is_array($availabilityForecast)) {
    $availabilityForecast = [];
}

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
/* Analytics Specific Styles - Matching Booking.com */
:root {
    --analytics-primary: #ff8c00;
    --analytics-success: #008009;
    --analytics-warning: #ff8c00;
    --analytics-danger: #e21111;
    --analytics-info: #0288d1;
    --analytics-purple: #9333ea;
}

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
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.analytics-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
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
    text-transform: uppercase;
    white-space: nowrap;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 180px;
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
    border: 1px solid var(--border-gray);
    position: relative;
    overflow: hidden;
    transition: all 0.2s;
}

.kpi-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.kpi-card.revenue { border-left: 4px solid var(--analytics-primary); }
.kpi-card.bookings { border-left: 4px solid var(--analytics-success); }
.kpi-card.utilization { border-left: 4px solid var(--analytics-info); }
.kpi-card.customers { border-left: 4px solid var(--analytics-purple); }

.kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.kpi-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.kpi-trend {
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 100px;
}

.trend-up {
    background: #e6f4ea;
    color: var(--analytics-success);
}

.trend-down {
    background: #fce8e8;
    color: var(--analytics-danger);
}

.kpi-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.kpi-subtitle {
    font-size: 0.75rem;
    color: var(--text-light);
}

.kpi-footer {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border-gray);
    font-size: 0.75rem;
    display: flex;
    justify-content: space-between;
    color: var(--text-light);
}

.kpi-footer strong {
    color: var(--text-dark);
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
    border: 1px solid var(--border-gray);
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
    color: var(--text-dark);
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
    border: 1px solid var(--border-gray);
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
    flex-shrink: 0;
}

.mini-stat-icon.orange { background: #fff4e6; color: var(--analytics-primary); }
.mini-stat-icon.green { background: #e6f4ea; color: var(--analytics-success); }
.mini-stat-icon.blue { background: #e1f5fe; color: var(--analytics-info); }
.mini-stat-icon.purple { background: #f3e8ff; color: var(--analytics-purple); }

.mini-stat-info h4 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.mini-stat-info p {
    font-size: 0.6875rem;
    color: var(--text-light);
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
    background: var(--bg-gray);
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    font-size: 0.6875rem;
    border-bottom: 1px solid var(--border-gray);
}

.analytics-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-gray);
}

.analytics-table tr:hover td {
    background: var(--cars-light);
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
    background: var(--bg-gray);
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--analytics-primary);
    border-radius: 4px;
    transition: width 0.3s;
}

/* Insight Cards */
.insight-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 20px;
    margin-bottom: 20px;
}

.insight-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.insight-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.insight-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--analytics-primary);
    line-height: 1.2;
}

.insight-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
}

/* Responsive */
@media (max-width: 1200px) {
    .kpi-grid,
    .mini-stats,
    .chart-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .kpi-grid,
    .mini-stats,
    .chart-grid {
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
        <p>Deep dive into your rental business performance</p>
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
        <label>Vehicle</label>
        <select class="filter-select" onchange="changeVehicle(this.value)">
            <option value="0">All Vehicles</option>
            <?php foreach ($vehicles as $v): 
                if (!is_array($v)) continue;
            ?>
            <option value="<?php echo $v['car_id'] ?? 0; ?>" <?php echo $vehicleId == ($v['car_id'] ?? 0) ? 'selected' : ''; ?>>
                <?php echo sanitize(($v['brand'] ?? '') . ' ' . ($v['model'] ?? '')); ?>
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
        <div class="kpi-value"><?php echo formatPrice($currentPeriod['total_revenue'] ?? 0); ?></div>
        <div class="kpi-subtitle">vs <?php echo formatPrice($previousPeriod['total_revenue'] ?? 0); ?> previous</div>
        <div class="kpi-footer">
            <span>Commission: <?php echo formatPrice($currentPeriod['total_commission'] ?? 0); ?></span>
            <span>Avg: <?php echo formatPrice($currentPeriod['avg_booking_value'] ?? 0); ?></span>
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
        <div class="kpi-value"><?php echo number_format($currentPeriod['total_bookings'] ?? 0); ?></div>
        <div class="kpi-subtitle"><?php echo $currentPeriod['unique_customers'] ?? 0; ?> unique customers</div>
        <div class="kpi-footer">
            <span>Rental days: <?php echo $currentPeriod['total_rental_days'] ?? 0; ?></span>
            <span>Avg: <?php echo round(($currentPeriod['total_rental_days'] ?? 0) / max(1, ($currentPeriod['total_bookings'] ?? 0)), 1); ?> days</span>
        </div>
    </div>
    
    <div class="kpi-card utilization">
        <div class="kpi-header">
            <span class="kpi-title">Fleet Utilization</span>
            <span class="kpi-trend <?php echo $utilizationRate > 50 ? 'trend-up' : 'trend-down'; ?>">
                <i class="bi bi-circle-fill"></i>
                <?php echo $utilizationRate; ?>%
            </span>
        </div>
        <div class="kpi-value"><?php echo $utilization['total_vehicles'] ?? 0; ?> / <?php echo $utilization['total_cars'] ?? 0; ?></div>
        <div class="kpi-subtitle">vehicles / total units</div>
        <div class="kpi-footer">
            <span>Active: <?php echo $utilization['active_vehicles'] ?? 0; ?></span>
            <span>Booked days: <?php echo $utilization['booked_days'] ?? 0; ?></span>
        </div>
    </div>
    
    <div class="kpi-card customers">
        <div class="kpi-header">
            <span class="kpi-title">Customer Insights</span>
            <span class="kpi-trend trend-up">
                <i class="bi bi-arrow-up-short"></i>
                <?php echo $customerInsights['new_customers'] ?? 0; ?> new
            </span>
        </div>
        <div class="kpi-value"><?php echo $customerInsights['total_customers'] ?? 0; ?></div>
        <div class="kpi-subtitle">total customers</div>
        <div class="kpi-footer">
            <span>Returning: <?php echo $customerInsights['returning_customers'] ?? 0; ?></span>
            <span>Avg spend: <?php echo formatPrice($customerInsights['avg_customer_spend'] ?? 0); ?></span>
        </div>
    </div>
</div>

<!-- Main Charts Grid -->
<div class="chart-grid">
    <!-- Revenue Trend Chart -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Revenue & Bookings Trend</h3>
            <div class="chart-legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--analytics-primary);"></div>
                    <span>Revenue</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: var(--analytics-success);"></div>
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
        <div style="display: flex; justify-content: center; gap: 16px; margin-top: 16px; flex-wrap: wrap;">
            <div class="legend-item">
                <div class="legend-color" style="background: var(--analytics-warning);"></div>
                <span>Pending: <?php echo $statusData['pending']['count']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--analytics-success);"></div>
                <span>Confirmed: <?php echo $statusData['confirmed']['count']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--analytics-info);"></div>
                <span>On Rent: <?php echo $statusData['checked_out']['count']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #9333ea;"></div>
                <span>Completed: <?php echo $statusData['completed']['count']; ?></span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: var(--analytics-danger);"></div>
                <span>Cancelled: <?php echo $statusData['cancelled']['count']; ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Secondary Charts Grid -->
<div class="chart-grid">
    <!-- Hourly Booking Pattern -->
    <div class="chart-card">
        <div class="chart-header">
            <h3 class="chart-title">Peak Booking Times</h3>
        </div>
        <div class="chart-container">
            <canvas id="hourlyChart"></canvas>
        </div>
    </div>
    
    <!-- Day of Week Pattern -->
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
        <div class="mini-stat-icon orange">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo $forecast['upcoming_bookings'] ?? 0; ?></h4>
            <p>Upcoming bookings</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon green">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo formatPrice($forecast['expected_revenue'] ?? 0); ?></h4>
            <p>Expected revenue</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon blue">
            <i class="bi bi-people"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo $forecast['expected_customers'] ?? 0; ?></h4>
            <p>Expected customers</p>
        </div>
    </div>
    
    <div class="mini-stat">
        <div class="mini-stat-icon purple">
            <i class="bi bi-speedometer2"></i>
        </div>
        <div class="mini-stat-info">
            <h4><?php echo round($currentPeriod['avg_daily_rate'] ?? 0); ?> RWF</h4>
            <p>Avg daily rate</p>
        </div>
    </div>
</div>

<!-- Vehicle Performance Table -->
<div class="insight-card">
    <div class="insight-header">
        <h3 class="insight-title">Vehicle Performance</h3>
        <span class="insight-label">Ranked by revenue</span>
    </div>
    <table class="analytics-table">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Vehicle</th>
                <th>Type</th>
                <th>Bookings</th>
                <th>Rental Days</th>
                <th>Revenue</th>
                <th>Avg/Booking</th>
                <th>Utilization</th>
                <th>Extra Revenue</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vehiclePerformance)): ?>
            <tr>
                <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-light);">
                    No data available for this period
                </td>
            </tr>
            <?php else: ?>
                <?php foreach ($vehiclePerformance as $vehicle): 
                    if (!is_array($vehicle)) continue;
                    
                    // FIX: Calculate utilization safely - potential_days is an integer, not an array
                    $potentialDaysVal = ($utilization['potential_days'] ?? 0);
                    $vehicleRentalDays = ($vehicle['total_rental_days'] ?? 0);
                    $utilizationCalc = ($potentialDaysVal > 0) 
                        ? round(($vehicleRentalDays / $potentialDaysVal) * 100, 1) 
                        : 0;
                ?>
                <tr>
                    <td>
                        <span class="rank-badge rank-<?php echo ($vehicle['revenue_rank'] ?? 0) <= 3 ? ($vehicle['revenue_rank'] ?? 0) : ''; ?>" 
                              style="<?php echo ($vehicle['revenue_rank'] ?? 0) > 3 ? 'background: var(--bg-gray); color: var(--text-dark);' : ''; ?>">
                            <?php echo $vehicle['revenue_rank'] ?? '-'; ?>
                        </span>
                    </td>
                    <td><strong><?php echo sanitize(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? '')); ?></strong></td>
                    <td><?php echo ucfirst($vehicle['car_type'] ?? 'Unknown'); ?></td>
                    <td><?php echo $vehicle['bookings_count'] ?? 0; ?></td>
                    <td><?php echo $vehicle['total_rental_days'] ?? 0; ?></td>
                    <td><strong style="color: var(--analytics-success);"><?php echo formatPrice($vehicle['revenue'] ?? 0); ?></strong></td>
                    <td><?php echo formatPrice($vehicle['avg_booking_value'] ?? 0); ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span><?php echo $utilizationCalc; ?>%</span>
                            <div class="progress-bar" style="width: 60px;">
                                <div class="progress-fill" style="width: <?php echo $utilizationCalc; ?>%; background: var(--analytics-primary);"></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo formatPrice($vehicle['extra_revenue'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Insights Grid -->
<div class="chart-grid">
    <!-- Lead Time Analysis -->
    <div class="insight-card">
        <div class="insight-header">
            <h3 class="insight-title">Booking Lead Time</h3>
        </div>
        <div style="margin-bottom: 20px;">
            <div class="insight-value"><?php echo round($leadTime['avg_lead_time'] ?? 0); ?> days</div>
            <div class="insight-label">Average planning time</div>
        </div>
        
        <div style="margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 0.75rem;">Last minute (≤2 days)</span>
                <span style="font-weight: 600;"><?php echo $leadTime['last_minute'] ?? 0; ?> bookings</span>
            </div>
            <div class="progress-bar" style="margin-bottom: 12px;">
                <?php $lastMinutePercent = ($currentPeriod['total_bookings'] ?? 0) > 0 ? round((($leadTime['last_minute'] ?? 0) / ($currentPeriod['total_bookings'] ?? 1)) * 100) : 0; ?>
                <div class="progress-fill" style="width: <?php echo $lastMinutePercent; ?>%; background: var(--analytics-warning);"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 0.75rem;">Short planning (3-7 days)</span>
                <span style="font-weight: 600;"><?php echo $leadTime['short_planning'] ?? 0; ?> bookings</span>
            </div>
            <div class="progress-bar" style="margin-bottom: 12px;">
                <?php $shortPercent = ($currentPeriod['total_bookings'] ?? 0) > 0 ? round((($leadTime['short_planning'] ?? 0) / ($currentPeriod['total_bookings'] ?? 1)) * 100) : 0; ?>
                <div class="progress-fill" style="width: <?php echo $shortPercent; ?>%; background: var(--analytics-info);"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 0.75rem;">Medium planning (8-30 days)</span>
                <span style="font-weight: 600;"><?php echo $leadTime['medium_planning'] ?? 0; ?> bookings</span>
            </div>
            <div class="progress-bar" style="margin-bottom: 12px;">
                <?php $mediumPercent = ($currentPeriod['total_bookings'] ?? 0) > 0 ? round((($leadTime['medium_planning'] ?? 0) / ($currentPeriod['total_bookings'] ?? 1)) * 100) : 0; ?>
                <div class="progress-fill" style="width: <?php echo $mediumPercent; ?>%; background: var(--analytics-success);"></div>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 0.75rem;">Advance (>30 days)</span>
                <span style="font-weight: 600;"><?php echo $leadTime['advance_planning'] ?? 0; ?> bookings</span>
            </div>
            <div class="progress-bar">
                <?php $advancePercent = ($currentPeriod['total_bookings'] ?? 0) > 0 ? round((($leadTime['advance_planning'] ?? 0) / ($currentPeriod['total_bookings'] ?? 1)) * 100) : 0; ?>
                <div class="progress-fill" style="width: <?php echo $advancePercent; ?>%; background: var(--analytics-primary);"></div>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-gray);">
            <div style="display: flex; justify-content: space-between;">
                <span style="font-size: 0.75rem; color: var(--text-light);">Earliest:</span>
                <span style="font-weight: 600;"><?php echo $leadTime['min_lead_time'] ?? 0; ?> days</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                <span style="font-size: 0.75rem; color: var(--text-light);">Latest:</span>
                <span style="font-weight: 600;"><?php echo $leadTime['max_lead_time'] ?? 0; ?> days</span>
            </div>
        </div>
    </div>
    
    <!-- Rental Duration Distribution -->
    <div class="insight-card">
        <div class="insight-header">
            <h3 class="insight-title">Rental Duration</h3>
        </div>
        <div class="chart-container" style="height: 200px;">
            <canvas id="durationChart"></canvas>
        </div>
        <div style="margin-top: 16px;">
            <div class="insight-value" style="font-size: 1.5rem;"><?php echo round($customerInsights['avg_rental_days_per_customer'] ?? 0, 1); ?> days</div>
            <div class="insight-label">Average rental duration</div>
        </div>
    </div>
</div>

<!-- Extra Revenue & Cancellations -->
<div class="chart-grid">
    <!-- Extra Revenue Analysis -->
    <div class="insight-card">
        <div class="insight-header">
            <h3 class="insight-title">Extra Revenue Analysis</h3>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div style="text-align: center;">
                <div class="insight-value" style="color: var(--analytics-primary);"><?php echo formatPrice($extraRevenue['total_extra_km_charges'] ?? 0); ?></div>
                <div class="insight-label">Extra KM Charges</div>
                <div style="font-size: 0.75rem; margin-top: 4px;">
                    <?php echo $extraRevenue['bookings_with_extra_km'] ?? 0; ?> bookings
                </div>
            </div>
            <div style="text-align: center;">
                <div class="insight-value" style="color: var(--analytics-success);"><?php echo formatPrice($extraRevenue['total_additional_charges'] ?? 0); ?></div>
                <div class="insight-label">Additional Charges</div>
                <div style="font-size: 0.75rem; margin-top: 4px;">
                    <?php echo $extraRevenue['bookings_with_additional'] ?? 0; ?> bookings
                </div>
            </div>
        </div>
        <div style="margin-top: 20px; padding: 12px; background: var(--bg-gray); border-radius: var(--radius-sm);">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="font-size: 0.75rem;">Avg extra km charge:</span>
                <span style="font-weight: 600;"><?php echo formatPrice($extraRevenue['avg_extra_km_charge'] ?? 0); ?></span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="font-size: 0.75rem;">Avg additional charge:</span>
                <span style="font-weight: 600;"><?php echo formatPrice($extraRevenue['avg_additional_charge'] ?? 0); ?></span>
            </div>
        </div>
    </div>
    
    <!-- Cancellation Analysis -->
    <div class="insight-card">
        <div class="insight-header">
            <h3 class="insight-title">Cancellation Analysis</h3>
        </div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 16px;">
            <div>
                <div class="insight-value" style="color: var(--analytics-danger); font-size: 2rem;"><?php echo $cancellationRate; ?>%</div>
                <div class="insight-label">Cancellation rate</div>
            </div>
            <div style="text-align: right;">
                <div class="insight-value" style="font-size: 1.5rem;"><?php echo $statusData['cancelled']['count']; ?></div>
                <div class="insight-label">of <?php echo $totalBookingsForCalc; ?> bookings</div>
            </div>
        </div>
        
        <?php if (!empty($cancellationReasons)): ?>
        <div style="margin-top: 16px;">
            <h4 style="font-size: 0.875rem; margin-bottom: 12px;">Top Cancellation Reasons</h4>
            <?php foreach ($cancellationReasons as $reason): 
                if (!is_array($reason)) continue;
            ?>
            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 4px;">
                    <span><?php echo ($reason['cancellation_reason'] ?? 'Not specified'); ?></span>
                    <span><?php echo $reason['reason_count'] ?? 0; ?> (<?php echo ($statusData['cancelled']['count'] > 0) ? round((($reason['reason_count'] ?? 0) / $statusData['cancelled']['count']) * 100) : 0; ?>%)</span>
                </div>
                <div class="progress-bar">
                    <?php $reasonPercent = ($statusData['cancelled']['count'] > 0) ? round((($reason['reason_count'] ?? 0) / $statusData['cancelled']['count']) * 100) : 0; ?>
                    <div class="progress-fill" style="width: <?php echo $reasonPercent; ?>%; background: var(--analytics-danger);"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($statusData['cancelled']['count'] > 0): ?>
        <div style="margin-top: 16px; padding: 12px; background: #fce8e8; border-radius: var(--radius-sm);">
            <div style="display: flex; justify-content: space-between;">
                <span style="font-size: 0.75rem; color: var(--analytics-danger);">Lost revenue:</span>
                <span style="font-weight: 700; color: var(--analytics-danger);"><?php echo formatPrice($statusData['cancelled']['revenue']); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// CHART INITIALIZATION
// ============================================

// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
const dailyLabels = <?php echo json_encode(array_column($dailyData, 'label')); ?>;
const dailyRevenue = <?php echo json_encode(array_column($dailyData, 'revenue')); ?>;
const dailyBookings = <?php echo json_encode(array_column($dailyData, 'bookings')); ?>;

new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: dailyLabels,
        datasets: [
            {
                label: 'Revenue',
                data: dailyRevenue,
                borderColor: '#ff8c00',
                backgroundColor: 'rgba(255, 140, 0, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-revenue'
            },
            {
                label: 'Bookings',
                data: dailyBookings,
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
                grid: { display: false },
                ticks: { maxRotation: 45, minRotation: 45, maxTicksLimit: 10 }
            },
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'left',
                grid: { color: '#f0f0f0' },
                ticks: { callback: value => formatCurrency(value) }
            },
            'y-bookings': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { stepSize: 1, callback: value => value + ' bookings '                 }
            }
        }
    }
});

// Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Pending', 'Confirmed', 'On Rent', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?php echo $statusData['pending']['count']; ?>,
                <?php echo $statusData['confirmed']['count']; ?>,
                <?php echo $statusData['checked_out']['count']; ?>,
                <?php echo $statusData['completed']['count']; ?>,
                <?php echo $statusData['cancelled']['count']; ?>
            ],
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
            legend: { display: false },
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
const hourlyLabels = ['12am', '1am', '2am', '3am', '4am', '5am', '6am', '7am', '8am', '9am', '10am', '11am',
                     '12pm', '1pm', '2pm', '3pm', '4pm', '5pm', '6pm', '7pm', '8pm', '9pm', '10pm', '11pm'];
new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: hourlyLabels,
        datasets: [{
            label: 'Bookings',
            data: <?php echo json_encode(array_values($hourlyBookings)); ?>,
            backgroundColor: 'rgba(255, 140, 0, 0.7)',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// Weekly Chart
const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
new Chart(weeklyCtx, {
    type: 'bar',
    data: {
        labels: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
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
                return `rgba(255, 140, 0, ${opacity})`;
            },
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});

// Duration Chart
const durationCtx = document.getElementById('durationChart').getContext('2d');
const durationLabels = <?php echo json_encode(array_column($durationData, 'duration_range')); ?>;
const durationCounts = <?php echo json_encode(array_column($durationData, 'count')); ?>;

new Chart(durationCtx, {
    type: 'doughnut',
    data: {
        labels: durationLabels,
        datasets: [{
            data: durationCounts,
            backgroundColor: ['#ff8c00', '#008009', '#0288d1', '#9333ea', '#e21111'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 10 } } }
        }
    }
});

// ============================================
// UTILITY FUNCTIONS
// ============================================
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function changeVehicle(vehicleId) {
    const url = new URL(window.location.href);
    url.searchParams.set('vehicle', vehicleId);
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
    let csv = "Date,Revenue,Bookings,Rental Days,Commission,Avg Booking\n";
    <?php foreach ($dailyData as $day): ?>
    csv += "<?php echo $day['date']; ?>,<?php echo $day['revenue']; ?>,<?php echo $day['bookings']; ?>,<?php echo $day['rental_days']; ?>,<?php echo $day['commission']; ?>,<?php echo $day['avg_booking']; ?>\n";
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
</script>

<?php require_once 'includes/cars_footer.php'; ?>