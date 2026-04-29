<?php
$pageTitle = 'Dashboard';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// FETCH ALL DATA
// ============================================

// Get all properties with comprehensive stats
$stmt = $db->prepare("
    SELECT 
        s.*,
        l.name as location_name,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as total_rooms,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as active_rooms,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1 AND base_price > 0) as priced_rooms,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.status = 'confirmed' AND b.check_in_date >= CURDATE()) as upcoming_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.check_in_date = CURDATE() AND b.status = 'confirmed') as checkins_today,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.check_out_date = CURDATE() AND b.status = 'confirmed') as checkouts_today,
        (SELECT COALESCE(AVG(r.overall_rating), 0) FROM reviews r WHERE r.stay_id = s.stay_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.stay_id = s.stay_id) as review_count,
        (SELECT COUNT(*) FROM restaurants WHERE stay_id = s.stay_id) as restaurant_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.owner_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Calculate aggregate stats
$totalProperties = count($properties);
$totalRooms = 0;
$activeRooms = 0;
$pendingBookings = 0;
$upcomingBookings = 0;
$todayCheckins = 0;
$todayCheckouts = 0;
$totalReviews = 0;
$avgRating = 0;
$propertiesWithRestaurants = 0;

foreach ($properties as $p) {
    $totalRooms += $p['total_rooms'];
    $activeRooms += $p['active_rooms'];
    $pendingBookings += $p['pending_bookings'];
    $upcomingBookings += $p['upcoming_bookings'];
    $todayCheckins += $p['checkins_today'];
    $todayCheckouts += $p['checkouts_today'];
    $totalReviews += $p['review_count'];
    $avgRating += $p['avg_rating'];
    if ($p['restaurant_count'] > 0) $propertiesWithRestaurants++;
}
$avgRating = $totalProperties > 0 ? $avgRating / $totalProperties : 0;

// Get overall revenue and booking stats
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(b.total_amount), 0) as total_revenue,
        COUNT(*) as total_bookings,
        COALESCE(SUM(b.num_nights), 0) as total_nights,
        COALESCE(AVG(b.total_amount), 0) as avg_booking_value,
        COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) as confirmed_revenue,
        COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.total_amount ELSE 0 END), 0) as completed_revenue,
        COUNT(CASE WHEN b.status = 'confirmed' THEN 1 END) as confirmed_count,
        COUNT(CASE WHEN b.status = 'completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN b.status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN b.status = 'cancelled' THEN 1 END) as cancelled_count
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
");
$stmt->execute([$userId]);
$overallStats = $stmt->fetch();

$totalRevenue = $overallStats['total_revenue'];
$totalBookings = $overallStats['total_bookings'];
$totalNights = $overallStats['total_nights'];
$avgBookingValue = $overallStats['avg_booking_value'];

// Occupancy rate (last 30 days)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(b.num_nights), 0) as nights_booked
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? 
    AND b.status IN ('confirmed', 'completed')
    AND b.check_in_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute([$userId]);
$occData = $stmt->fetch();
$occupancyRate = $activeRooms > 0 ? min(100, round(($occData['nights_booked'] / ($activeRooms * 30)) * 100)) : 0;

// Revenue trend (last 6 months for sparkline)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%Y-%m') as month,
        DATE_FORMAT(b.created_at, '%b') as month_label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? 
    AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY b.created_at ASC
");
$stmt->execute([$userId]);
$revenueTrend = $stmt->fetchAll();

// Monthly revenue for 12 months (full chart)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%Y-%m') as month_key,
        DATE_FORMAT(b.created_at, '%b %Y') as month_label,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as booking_count,
        COALESCE(AVG(b.total_amount), 0) as avg_value
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY b.created_at ASC
");
$stmt->execute([$userId]);
$monthlyRevenue = $stmt->fetchAll();

// Booking status distribution
$statusCounts = [
    'confirmed' => (int)$overallStats['confirmed_count'],
    'pending' => (int)$overallStats['pending_count'],
    'completed' => (int)$overallStats['completed_count'],
    'cancelled' => (int)$overallStats['cancelled_count'],
];

// Revenue by property (for bar chart)
$stmt = $db->prepare("
    SELECT 
        s.stay_name,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(b.booking_id) as bookings
    FROM stays s
    LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
    LEFT JOIN bookings b ON sr.room_id = b.stay_room_id AND b.status IN ('confirmed', 'completed')
    WHERE s.owner_id = ?
    GROUP BY s.stay_id
    ORDER BY revenue DESC
    LIMIT 8
");
$stmt->execute([$userId]);
$revenueByProperty = $stmt->fetchAll();

// Occupancy by day of week (last 90 days)
$stmt = $db->prepare("
    SELECT 
        DAYNAME(b.check_in_date) as day_name,
        WEEKDAY(b.check_in_date) as day_num,
        COUNT(*) as checkins
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? 
    AND b.status IN ('confirmed', 'completed')
    AND b.check_in_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY DAYNAME(b.check_in_date), WEEKDAY(b.check_in_date)
    ORDER BY WEEKDAY(b.check_in_date)
");
$stmt->execute([$userId]);
$occupancyByDay = $stmt->fetchAll();

// Recent bookings
$stmt = $db->prepare("
    SELECT 
        b.*, s.stay_name, sr.room_name, 
        u.first_name, u.last_name, u.email,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until,
        DATEDIFF(CURDATE(), b.created_at) as days_ago
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll();

// Upcoming check-ins (next 14 days)
$stmt = $db->prepare("
    SELECT 
        b.*, s.stay_name, sr.room_name,
        u.first_name, u.last_name, u.phone,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ? 
    AND b.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    AND b.status = 'confirmed'
    ORDER BY b.check_in_date ASC
    LIMIT 8
");
$stmt->execute([$userId]);
$upcomingCheckins = $stmt->fetchAll();

// Recent reviews
$stmt = $db->prepare("
    SELECT r.*, s.stay_name, u.first_name, u.last_name, u.profile_image,
           DATEDIFF(CURDATE(), r.created_at) as days_ago
    FROM reviews r
    JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 6
");
$stmt->execute([$userId]);
$recentReviews = $stmt->fetchAll();

// Low inventory alerts
$stmt = $db->prepare("
    SELECT 
        s.stay_name, s.stay_id,
        sr.room_name, sr.room_id,
        sr.num_rooms_available as total_rooms,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.stay_room_id = sr.room_id 
         AND b.status IN ('confirmed', 'pending')
         AND b.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         AND b.check_out_date >= CURDATE()
        ) as booked_next_7_days,
        sr.num_rooms_available - (SELECT COUNT(*) FROM bookings b 
         WHERE b.stay_room_id = sr.room_id 
         AND b.status IN ('confirmed', 'pending')
         AND b.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
         AND b.check_out_date >= CURDATE()
        ) as available_next_7_days
    FROM stay_rooms sr
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? AND sr.is_active = 1
    HAVING available_next_7_days <= 2
    ORDER BY available_next_7_days ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$lowInventory = $stmt->fetchAll();

// Today's tasks
$todayTasks = [];
if ($todayCheckins > 0) {
    $todayTasks[] = ['icon' => 'bi-box-arrow-in-right', 'title' => 'Check-ins', 'count' => $todayCheckins, 'color' => 'success', 'link' => 'bookings.php?status=confirmed'];
}
if ($todayCheckouts > 0) {
    $todayTasks[] = ['icon' => 'bi-box-arrow-left', 'title' => 'Check-outs', 'count' => $todayCheckouts, 'color' => 'warning', 'link' => 'bookings.php?status=confirmed'];
}
if ($pendingBookings > 0) {
    $todayTasks[] = ['icon' => 'bi-clock-history', 'title' => 'Pending', 'count' => $pendingBookings, 'color' => 'danger', 'link' => 'bookings.php?status=pending'];
}
if (count($lowInventory) > 0) {
    $todayTasks[] = ['icon' => 'bi-exclamation-triangle', 'title' => 'Low Stock', 'count' => count($lowInventory), 'color' => 'orange', 'link' => 'rooms.php'];
}

// Revenue growth calculation
$revenueGrowth = 0;
if (count($revenueTrend) >= 2) {
    $currentMonth = end($revenueTrend);
    $prevMonth = prev($revenueTrend);
    $revenueGrowth = $prevMonth['revenue'] > 0 ? (($currentMonth['revenue'] - $prevMonth['revenue']) / $prevMonth['revenue']) * 100 : 0;
}
?>



<!-- ============================================ -->
<!-- TODAY'S TASKS (IF ANY) -->
<!-- ============================================ -->
<?php if (!empty($todayTasks)): ?>
    <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
        <?php foreach ($todayTasks as $task):
            $colorMap = ['success' => '#008009', 'warning' => '#e67e22', 'danger' => '#e21111', 'orange' => '#ff8c00'];
            $bgMap = ['success' => '#e6f4ea', 'warning' => '#fff4e6', 'danger' => '#fce8e8', 'orange' => '#fff4e6'];
            $c = $task['color'];
        ?>
            <a href="<?php echo $task['link']; ?>" style="flex: 1; min-width: 180px; background: white; border: 1px solid var(--booking-border); border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; text-decoration: none; color: inherit; transition: all 0.2s; cursor: pointer;">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: <?php echo $bgMap[$c] ?? '#f0f4ff'; ?>; color: <?php echo $colorMap[$c] ?? '#003b95'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                    <i class="bi <?php echo $task['icon']; ?>"></i>
                </div>
                <div>
                    <div style="font-size: 1.5rem; font-weight: 700; line-height: 1.2;"><?php echo $task['count']; ?></div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);"><?php echo $task['title']; ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ============================================ -->
<!-- KPI CARDS -->
<!-- ============================================ -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
    <!-- Revenue Card -->
    <div style="background: white; border-radius: 16px; padding: 24px; border: 1px solid var(--booking-border); position: relative; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px;">Total Revenue</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--booking-text);"><?php echo formatPrice($totalRevenue); ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 12px; background: #e6f4ea; color: #008009; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="bi bi-cash-stack"></i>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <?php if ($revenueGrowth != 0): ?>
                <span style="font-size: 0.75rem; font-weight: 600; color: <?php echo $revenueGrowth > 0 ? '#008009' : '#e21111'; ?>; background: <?php echo $revenueGrowth > 0 ? '#e6f4ea' : '#fce8e8'; ?>; padding: 3px 8px; border-radius: 100px;">
                    <i class="bi bi-arrow-<?php echo $revenueGrowth > 0 ? 'up' : 'down'; ?>-short"></i> <?php echo abs(round($revenueGrowth)); ?>%
                </span>
            <?php endif; ?>
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);">vs last month</span>
        </div>
    </div>

    <!-- Bookings Card -->
    <div style="background: white; border-radius: 16px; padding: 24px; border: 1px solid var(--booking-border);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px;">Total Bookings</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--booking-text);"><?php echo number_format($totalBookings); ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 12px; background: #f0f4ff; color: #003b95; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="bi bi-calendar-check"></i>
            </div>
        </div>
        <div style="display: flex; gap: 12px;">
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);"><?php echo number_format($totalNights); ?> nights total</span>
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);">·</span>
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);">Avg <?php echo formatPrice($avgBookingValue); ?></span>
        </div>
    </div>

    <!-- Occupancy Card -->
    <div style="background: white; border-radius: 16px; padding: 24px; border: 1px solid var(--booking-border);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px;">Occupancy Rate</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--booking-text);"><?php echo $occupancyRate; ?>%</div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 12px; background: #f3e8ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="bi bi-graph-up"></i>
            </div>
        </div>
        <div style="position: relative; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden;">
            <div style="position: absolute; left: 0; top: 0; height: 100%; width: <?php echo $occupancyRate; ?>%; background: linear-gradient(90deg, #9333ea, #7c3aed); border-radius: 4px; transition: width 1s ease;"></div>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 8px;">
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);">Last 30 days</span>
            <span style="font-size: 0.6875rem; color: var(--booking-text-light);"><?php echo $activeRooms; ?> active rooms</span>
        </div>
    </div>

    <!-- Rating Card -->
    <div style="background: white; border-radius: 16px; padding: 24px; border: 1px solid var(--booking-border);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px;">Guest Rating</div>
                <div style="font-size: 1.75rem; font-weight: 700; color: var(--booking-text);"><?php echo number_format($avgRating, 1); ?></div>
            </div>
            <div style="width: 40px; height: 40px; border-radius: 12px; background: #fff4e6; color: #febb02; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="bi bi-star-fill"></i>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 2px; margin-bottom: 4px;">
            <?php
            $fullStars = floor($avgRating);
            $halfStar = ($avgRating - $fullStars) >= 0.5;
            for ($i = 0; $i < 5; $i++):
                if ($i < $fullStars): ?>
                    <i class="bi bi-star-fill" style="color: #febb02; font-size: 0.875rem;"></i>
                <?php elseif ($i == $fullStars && $halfStar): ?>
                    <i class="bi bi-star-half" style="color: #febb02; font-size: 0.875rem;"></i>
                <?php else: ?>
                    <i class="bi bi-star" style="color: #ddd; font-size: 0.875rem;"></i>
            <?php endif;
            endfor; ?>
        </div>
        <div style="font-size: 0.6875rem; color: var(--booking-text-light);"><?php echo $totalReviews; ?> reviews</div>
    </div>
</div>

<!-- ============================================ -->
<!-- MAIN CHARTS ROW -->
<!-- ============================================ -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
    <!-- Revenue Chart -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Revenue Overview</h3>
                <p style="font-size: 0.75rem; color: var(--booking-text-light); margin: 2px 0 0 0;">Monthly revenue for the past 12 months</p>
            </div>
            <div style="display: flex; gap: 4px;">
                <button class="chart-period-btn active" data-period="12" style="padding: 6px 12px; border: 1px solid var(--booking-border); background: #003b95; color: white; border-radius: 6px 0 0 6px; font-size: 0.75rem; cursor: pointer; font-weight: 500;">12M</button>
                <button class="chart-period-btn" data-period="6" style="padding: 6px 12px; border: 1px solid var(--booking-border); background: white; color: var(--booking-text); border-radius: 0; font-size: 0.75rem; cursor: pointer; font-weight: 500;">6M</button>
                <button class="chart-period-btn" data-period="3" style="padding: 6px 12px; border: 1px solid var(--booking-border); background: white; color: var(--booking-text); border-radius: 0 6px 6px 0; font-size: 0.75rem; cursor: pointer; font-weight: 500;">3M</button>
            </div>
        </div>
        <div style="padding: 24px;">
            <div style="position: relative; height: 300px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Booking Status Donut -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border);">
            <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Booking Status</h3>
            <p style="font-size: 0.75rem; color: var(--booking-text-light); margin: 2px 0 0 0;">Distribution overview</p>
        </div>
        <div style="padding: 20px 24px;">
            <div style="position: relative; height: 200px;">
                <canvas id="statusChart"></canvas>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 16px;">
                <?php
                $statusColors = ['confirmed' => '#008009', 'pending' => '#ff8c00', 'completed' => '#003b95', 'cancelled' => '#e21111'];
                foreach ($statusCounts as $status => $count):
                ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 10px; height: 10px; border-radius: 3px; background: <?php echo $statusColors[$status]; ?>;"></div>
                        <span style="font-size: 0.75rem; color: var(--booking-text-light); text-transform: capitalize;"><?php echo $status; ?></span>
                        <span style="font-size: 0.75rem; font-weight: 600; margin-left: auto;"><?php echo $count; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- PROPERTY REVENUE & OCCUPANCY BY DAY -->
<!-- ============================================ -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
    <!-- Revenue by Property -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border);">
            <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Revenue by Property</h3>
        </div>
        <div style="padding: 24px;">
            <div style="position: relative; height: 280px;">
                <canvas id="propertyRevenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Check-ins by Day of Week -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border);">
            <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Check-ins by Day</h3>
            <p style="font-size: 0.75rem; color: var(--booking-text-light); margin: 2px 0 0 0;">Last 90 days</p>
        </div>
        <div style="padding: 24px;">
            <div style="position: relative; height: 280px;">
                <canvas id="dayOfWeekChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- UPCOMING CHECK-INS -->
<!-- ============================================ -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
    <!-- Upcoming Check-ins -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Upcoming Check-ins</h3>
                <p style="font-size: 0.75rem; color: var(--booking-text-light); margin: 2px 0 0 0;">Next 14 days</p>
            </div>
            <a href="calendar.php" style="font-size: 0.75rem; color: #003b95; text-decoration: none; font-weight: 500;">Full Calendar →</a>
        </div>
        <div style="padding: 20px 24px;">
            <?php if (empty($upcomingCheckins)): ?>
                <div style="text-align: center; padding: 30px; color: var(--booking-text-light);">
                    <i class="bi bi-calendar-check" style="font-size: 2.5rem; color: #ddd; display: block; margin-bottom: 12px;"></i>
                    <p>No upcoming check-ins</p>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                    <?php foreach ($upcomingCheckins as $checkin):
                        $daysUntil = (int)$checkin['days_until'];
                        $isToday = $daysUntil === 0;
                        $isTomorrow = $daysUntil === 1;
                    ?>
                        <div style="border: 1px solid <?php echo $isToday ? '#003b95' : 'var(--booking-border)'; ?>; border-radius: 12px; padding: 16px 12px; text-align: center; background: <?php echo $isToday ? '#f0f4ff' : 'white'; ?>;">
                            <div style="font-size: 0.625rem; color: var(--booking-text-light); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">
                                <?php echo date('M', strtotime($checkin['check_in_date'])); ?>
                            </div>
                            <div style="font-size: 1.75rem; font-weight: 700; color: <?php echo $isToday ? '#003b95' : 'var(--booking-text)'; ?>; line-height: 1.2;">
                                <?php echo date('d', strtotime($checkin['check_in_date'])); ?>
                            </div>
                            <div style="font-weight: 600; font-size: 0.8125rem; margin: 8px 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo sanitize($checkin['first_name'] ?? 'Guest'); ?>
                            </div>
                            <div style="font-size: 0.6875rem; color: var(--booking-text-light); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo sanitize($checkin['stay_name']); ?>
                            </div>
                            <div style="margin-top: 8px;">
                                <?php if ($isToday): ?>
                                    <span style="font-size: 0.625rem; font-weight: 600; background: #003b95; color: white; padding: 3px 10px; border-radius: 100px;">Today</span>
                                <?php elseif ($isTomorrow): ?>
                                    <span style="font-size: 0.625rem; font-weight: 600; background: #f0f4ff; color: #003b95; padding: 3px 10px; border-radius: 100px;">Tomorrow</span>
                                <?php else: ?>
                                    <span style="font-size: 0.625rem; color: var(--booking-text-light);">In <?php echo $daysUntil; ?> days</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Reviews -->
    <div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Recent Reviews</h3>
            <a href="reviews.php" style="font-size: 0.75rem; color: #003b95; text-decoration: none; font-weight: 500;">All Reviews →</a>
        </div>
        <div style="padding: 8px 0; max-height: 400px; overflow-y: auto;">
            <?php if (empty($recentReviews)): ?>
                <div style="text-align: center; padding: 30px; color: var(--booking-text-light);">
                    <i class="bi bi-star" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 8px;"></i>
                    <p style="font-size: 0.8125rem;">No reviews yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentReviews as $review): ?>
                    <div style="padding: 16px 24px; border-bottom: 1px solid var(--booking-border); display: flex; gap: 14px; align-items: flex-start;">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: <?php echo $review['profile_image'] ? 'transparent' : '#f0f4ff'; ?>; overflow: hidden; flex-shrink: 0;">
                            <?php if ($review['profile_image']): ?>
                                <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $review['profile_image']; ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #003b95; font-size: 0.875rem;">
                                    <?php echo strtoupper(substr($review['first_name'] ?? 'G', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <span style="font-weight: 600; font-size: 0.8125rem;"><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'] ?? '', 0, 1) . '.'); ?></span>
                                <span style="background: #003b95; color: white; padding: 2px 8px; border-radius: 100px; font-size: 0.6875rem; font-weight: 600;">
                                    <?php echo number_format($review['overall_rating'], 1); ?> ★
                                </span>
                            </div>
                            <div style="font-size: 0.6875rem; color: var(--booking-text-light); margin-bottom: 4px;">
                                <?php echo sanitize($review['stay_name']); ?> · <?php echo $review['days_ago'] == 0 ? 'Today' : ($review['days_ago'] == 1 ? 'Yesterday' : $review['days_ago'] . ' days ago'); ?>
                            </div>
                            <?php if ($review['comment']): ?>
                                <p style="font-size: 0.75rem; color: var(--booking-text); margin: 4px 0 0; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                    <?php echo htmlspecialchars($review['comment']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- RECENT BOOKINGS TABLE -->
<!-- ============================================ -->
<div style="background: white; border-radius: 16px; border: 1px solid var(--booking-border); overflow: hidden; margin-bottom: 24px;">
    <div style="padding: 20px 24px; border-bottom: 1px solid var(--booking-border); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="font-size: 1rem; font-weight: 700; margin: 0;">Recent Bookings</h3>
            <p style="font-size: 0.75rem; color: var(--booking-text-light); margin: 2px 0 0 0;">Latest reservations</p>
        </div>
        <a href="bookings.php" style="font-size: 0.75rem; color: #003b95; text-decoration: none; font-weight: 500;">View All →</a>
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="text-align: left; padding: 12px 24px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Guest</th>
                    <th style="text-align: left; padding: 12px 16px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Property</th>
                    <th style="text-align: left; padding: 12px 16px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Check-in</th>
                    <th style="text-align: left; padding: 12px 16px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Check-out</th>
                    <th style="text-align: center; padding: 12px 16px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Nights</th>
                    <th style="text-align: center; padding: 12px 16px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Status</th>
                    <th style="text-align: right; padding: 12px 24px; font-size: 0.6875rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; letter-spacing: 0.5px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentBookings)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 60px; color: var(--booking-text-light);">
                            <i class="bi bi-inbox" style="font-size: 2rem; color: #ddd; display: block; margin-bottom: 12px;"></i>
                            No bookings yet
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentBookings as $booking):
                        $statusColors = ['confirmed' => '#008009', 'pending' => '#ff8c00', 'completed' => '#003b95', 'cancelled' => '#e21111', 'no_show' => '#666'];
                        $statusBgColors = ['confirmed' => '#e6f4ea', 'pending' => '#fff4e6', 'completed' => '#f0f4ff', 'cancelled' => '#fce8e8', 'no_show' => '#f3f4f6'];
                        $sc = $statusColors[$booking['status']] ?? '#666';
                        $sbg = $statusBgColors[$booking['status']] ?? '#f3f4f6';
                    ?>
                        <tr style="border-bottom: 1px solid var(--booking-border); transition: background 0.15s;" onmouseover="this.style.background='#f8faff'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 14px 24px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #f0f4ff; color: #003b95; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8125rem;">
                                        <?php echo strtoupper(substr($booking['first_name'] ?? 'G', 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.8125rem;"><?php echo sanitize(($booking['first_name'] ?? 'Guest') . ' ' . ($booking['last_name'] ?? '')); ?></div>
                                        <div style="font-size: 0.6875rem; color: var(--booking-text-light);"><?php echo $booking['num_guests']; ?> guest(s)</div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 14px 16px;">
                                <div style="font-weight: 500; font-size: 0.8125rem;"><?php echo sanitize($booking['stay_name']); ?></div>
                                <div style="font-size: 0.6875rem; color: var(--booking-text-light);"><?php echo sanitize($booking['room_name']); ?></div>
                            </td>
                            <td style="padding: 14px 16px;">
                                <div style="font-size: 0.8125rem; font-weight: 500;"><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></div>
                                <?php if (isset($booking['days_until']) && $booking['days_until'] !== null): ?>
                                    <div style="font-size: 0.6875rem; color: <?php echo $booking['days_until'] <= 1 ? '#008009' : 'var(--booking-text-light)'; ?>;">
                                        <?php echo $booking['days_until'] == 0 ? 'Today' : ($booking['days_until'] == 1 ? 'Tomorrow' : 'In ' . $booking['days_until'] . ' days'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 14px 16px; font-size: 0.8125rem;"><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                            <td style="padding: 14px 16px; text-align: center; font-size: 0.8125rem; font-weight: 500;"><?php echo $booking['num_nights']; ?></td>
                            <td style="padding: 14px 16px; text-align: center;">
                                <span style="display: inline-block; padding: 4px 12px; border-radius: 100px; font-size: 0.6875rem; font-weight: 600; background: <?php echo $sbg; ?>; color: <?php echo $sc; ?>;">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 14px 24px; text-align: right; font-weight: 600; font-size: 0.8125rem;"><?php echo formatPrice($booking['total_amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============================================ -->
<!-- INLINE STYLES -->
<!-- ============================================ -->
<style>
    /* Chart.js canvas responsiveness */
    canvas {
        max-width: 100% !important;
    }

    /* Period button active state */
    .chart-period-btn.active {
        background: #003b95 !important;
        color: white !important;
        border-color: #003b95 !important;
    }

    /* Smooth animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(16px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .stat-card,
    .card,
    [style*="background: white"] {
        animation: fadeInUp 0.4s ease forwards;
    }

    /* Hover effects */
    [style*="border-radius: 12px"]:hover,
    [style*="border-radius: 16px"]:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        transition: box-shadow 0.2s;
    }
</style>

<!-- ============================================ -->
<!-- SCRIPTS -->
<!-- ============================================ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Register Chart.js plugins for better visuals
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.tooltip.backgroundColor = '#1a1a1a';
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.titleFont = {
        weight: 'bold',
        size: 13
    };
    Chart.defaults.plugins.tooltip.bodyFont = {
        size: 12
    };

    const monthlyLabels = <?php echo json_encode(array_column($monthlyRevenue, 'month_label')); ?>;
    const monthlyData = <?php echo json_encode(array_map('floatval', array_column($monthlyRevenue, 'revenue'))); ?>;
    const monthlyBookingsData = <?php echo json_encode(array_map('intval', array_column($monthlyRevenue, 'booking_count'))); ?>;

    const propertyNames = <?php echo json_encode(array_column($revenueByProperty, 'stay_name')); ?>;
    const propertyRevenues = <?php echo json_encode(array_map('floatval', array_column($revenueByProperty, 'revenue'))); ?>;
    const propertyBookings = <?php echo json_encode(array_map('intval', array_column($revenueByProperty, 'bookings'))); ?>;

    const dayNames = <?php echo json_encode(array_column($occupancyByDay, 'day_name')); ?>;
    const dayCheckins = <?php echo json_encode(array_map('intval', array_column($occupancyByDay, 'checkins'))); ?>;

    const statusData = [<?php echo implode(',', $statusCounts); ?>];

    // Chart instances
    let revenueChartInstance, statusChartInstance, propertyChartInstance, dayChartInstance;

    // ============================================
    // GRADIENT HELPERS
    // ============================================
    function createGradient(ctx, color1, color2) {
        const gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, color1);
        gradient.addColorStop(1, color2);
        return gradient;
    }

    // ============================================
    // REVENUE CHART
    // ============================================
    function createRevenueChart(dataLabels, dataValues, bookingsValues) {
        const ctx = document.getElementById('revenueChart')?.getContext('2d');
        if (!ctx) return;

        if (revenueChartInstance) revenueChartInstance.destroy();

        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(0, 59, 149, 0.25)');
        gradient.addColorStop(0.5, 'rgba(0, 59, 149, 0.08)');
        gradient.addColorStop(1, 'rgba(0, 59, 149, 0.01)');

        revenueChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dataLabels,
                datasets: [{
                    label: 'Revenue',
                    data: dataValues,
                    borderColor: '#003b95',
                    backgroundColor: gradient,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#003b95',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointHoverBackgroundColor: '#003b95',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.parsed.y;
                                const idx = context.dataIndex;
                                const bCount = bookingsValues[idx] || 0;
                                return [`Revenue: ${formatCurrency(value)}`, `Bookings: ${bCount}`];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f0f0f0',
                            drawBorder: false
                        },
                        ticks: {
                            callback: v => v === 0 ? '0' : (v >= 1000000 ? (v / 1000000).toFixed(1) + 'M' : (v / 1000).toFixed(0) + 'K'),
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // STATUS DONUT CHART
    // ============================================
    function createStatusChart() {
        const ctx = document.getElementById('statusChart')?.getContext('2d');
        if (!ctx) return;

        if (statusChartInstance) statusChartInstance.destroy();

        const total = statusData.reduce((a, b) => a + b, 0);

        statusChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Confirmed', 'Pending', 'Completed', 'Cancelled'],
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#008009', '#ff8c00', '#003b95', '#e21111'],
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverBorderWidth: 4,
                    hoverBorderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return `${ctx.label}: ${ctx.parsed} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // PROPERTY REVENUE BAR CHART
    // ============================================
    function createPropertyRevenueChart() {
        const ctx = document.getElementById('propertyRevenueChart')?.getContext('2d');
        if (!ctx) return;

        if (propertyChartInstance) propertyChartInstance.destroy();

        propertyChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: propertyNames,
                datasets: [{
                    label: 'Revenue',
                    data: propertyRevenues,
                    backgroundColor: propertyRevenues.map((_, i) => {
                        const colors = ['#003b95', '#0066ff', '#4d94ff', '#80b3ff', '#b3d1ff', '#1a56db', '#2563eb', '#3b82f6'];
                        return colors[i % colors.length];
                    }),
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 50,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const idx = ctx.dataIndex;
                                const bCount = propertyBookings[idx] || 0;
                                return [`Revenue: ${formatCurrency(ctx.parsed.x)}`, `Bookings: ${bCount}`];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: {
                            color: '#f0f0f0',
                            drawBorder: false
                        },
                        ticks: {
                            callback: v => v >= 1000000 ? (v / 1000000).toFixed(1) + 'M' : (v / 1000).toFixed(0) + 'K',
                            font: {
                                size: 10
                            }
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11,
                                weight: '500'
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // DAY OF WEEK CHART
    // ============================================
    function createDayOfWeekChart() {
        const ctx = document.getElementById('dayOfWeekChart')?.getContext('2d');
        if (!ctx) return;

        if (dayChartInstance) dayChartInstance.destroy();

        const maxVal = Math.max(...dayCheckins, 1);

        dayChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: dayNames,
                datasets: [{
                    label: 'Check-ins',
                    data: dayCheckins,
                    backgroundColor: dayCheckins.map(v => v === maxVal ? '#003b95' : '#b3d1ff'),
                    borderRadius: 8,
                    borderSkipped: false,
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
                        callbacks: {
                            label: function(ctx) {
                                return `Check-ins: ${ctx.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f0f0f0',
                            drawBorder: false
                        },
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // INITIALIZE ALL CHARTS
    // ============================================
    function initAllCharts() {
        createRevenueChart(monthlyLabels, monthlyData, monthlyBookingsData);
        createStatusChart();
        createPropertyRevenueChart();
        createDayOfWeekChart();
    }

    document.addEventListener('DOMContentLoaded', initAllCharts);

    // ============================================
    // PERIOD BUTTON HANDLERS
    // ============================================
    document.querySelectorAll('.chart-period-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.chart-period-btn').forEach(b => {
                b.classList.remove('active');
                b.style.background = 'white';
                b.style.color = 'var(--booking-text)';
                b.style.borderColor = 'var(--booking-border)';
            });
            this.classList.add('active');
            this.style.background = '#003b95';
            this.style.color = 'white';
            this.style.borderColor = '#003b95';

            const months = parseInt(this.dataset.period);
            const slicedLabels = monthlyLabels.slice(-months);
            const slicedData = monthlyData.slice(-months);
            const slicedBookings = monthlyBookingsData.slice(-months);
            createRevenueChart(slicedLabels, slicedData, slicedBookings);
        });
    });

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================
    function formatCurrency(amount) {
        const num = Math.round(amount);
        if (num >= 1000000) return 'RWF ' + (num / 1000000).toFixed(1) + 'M';
        if (num >= 1000) return 'RWF ' + (num / 1000).toFixed(0) + 'K';
        return 'RWF ' + num.toLocaleString();
    }

    function respondToReview(reviewId) {
        window.location.href = 'reviews.php?respond=' + reviewId;
    }

    // ============================================
    // RESPONSIVE HANDLER
    // ============================================
    window.addEventListener('resize', function() {
        if (revenueChartInstance) revenueChartInstance.resize();
        if (statusChartInstance) statusChartInstance.resize();
        if (propertyChartInstance) propertyChartInstance.resize();
        if (dayChartInstance) dayChartInstance.resize();
    });
</script>

<?php require_once 'includes/stays_footer.php'; ?>