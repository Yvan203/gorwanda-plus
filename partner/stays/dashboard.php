<?php
$pageTitle = 'Dashboard';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

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
         WHERE sr.stay_id = s.stay_id AND b.status = 'confirmed') as confirmed_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.check_in_date = CURDATE()) as checkins_today,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id 
         WHERE sr.stay_id = s.stay_id AND b.check_out_date = CURDATE()) as checkouts_today,
        (SELECT COALESCE(AVG(r.overall_rating), 0) FROM reviews r WHERE r.stay_id = s.stay_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.stay_id = s.stay_id) as review_count,
        (SELECT COUNT(*) FROM reviews r WHERE r.stay_id = s.stay_id AND DATE(r.created_at) = CURDATE()) as new_reviews_today,
        (SELECT COUNT(*) FROM restaurants WHERE stay_id = s.stay_id) as restaurant_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.owner_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Calculate comprehensive stats across all properties
$totalProperties = count($properties);
$totalRooms = 0;
$activeRooms = 0;
$pendingBookings = 0;
$confirmedBookings = 0;
$totalBookings = 0;
$totalRevenue = 0;
$todayCheckins = 0;
$todayCheckouts = 0;
$totalReviews = 0;
$avgRating = 0;
$propertiesWithRestaurants = 0;
$occupancyRate = 0;
$totalNights = 0;

foreach ($properties as $property) {
    $totalRooms += $property['total_rooms'];
    $activeRooms += $property['active_rooms'];
    $pendingBookings += $property['pending_bookings'];
    $confirmedBookings += $property['confirmed_bookings'];
    $todayCheckins += $property['checkins_today'];
    $todayCheckouts += $property['checkouts_today'];
    $totalReviews += $property['review_count'];
    $avgRating += $property['avg_rating'];
    if ($property['restaurant_count'] > 0) $propertiesWithRestaurants++;
    
    // Get detailed booking stats for this property
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(b.total_amount), 0) as revenue,
            COUNT(*) as bookings,
            COALESCE(SUM(b.num_nights), 0) as total_nights
        FROM bookings b
        JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        WHERE sr.stay_id = ? AND b.status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$property['stay_id']]);
    $stats = $stmt->fetch();
    $totalRevenue += $stats['revenue'];
    $totalBookings += $stats['bookings'];
    $totalNights += $stats['total_nights'];
}

$avgRating = $totalProperties > 0 ? $avgRating / $totalProperties : 0;

// Calculate occupancy rate (last 30 days)
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT b.booking_id) as occupied_days,
        SUM(b.num_nights) as total_nights_booked
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ? 
    AND b.status IN ('confirmed', 'completed')
    AND b.check_in_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute([$userId]);
$occupancy = $stmt->fetch();
$occupancyRate = $activeRooms > 0 ? min(100, round(($occupancy['total_nights_booked'] / ($activeRooms * 30)) * 100)) : 0;

// Get recent bookings with more details
$stmt = $db->prepare("
    SELECT 
        b.*, 
        s.stay_name, 
        sr.room_name, 
        u.first_name, 
        u.last_name,
        u.email,
        u.phone,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until_checkin,
        DATEDIFF(CURDATE(), b.created_at) as days_ago
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 15
");
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll();

// Get upcoming check-ins with countdown
$stmt = $db->prepare("
    SELECT 
        b.*, 
        s.stay_name, 
        sr.room_name, 
        u.first_name, 
        u.last_name,
        u.phone,
        DATEDIFF(b.check_in_date, CURDATE()) as days_until
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ? 
    AND b.check_in_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    AND b.status IN ('confirmed')
    ORDER BY b.check_in_date ASC
    LIMIT 8
");
$stmt->execute([$userId]);
$upcomingCheckins = $stmt->fetchAll();

// Get recent reviews with responses
$stmt = $db->prepare("
    SELECT 
        r.*, 
        s.stay_name, 
        u.first_name, 
        u.last_name,
        u.profile_image,
        DATEDIFF(CURDATE(), r.created_at) as days_ago,
        (SELECT COUNT(*) FROM reviews WHERE stay_id = r.stay_id) as property_total_reviews
    FROM reviews r
    JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 8
");
$stmt->execute([$userId]);
$recentReviews = $stmt->fetchAll();

// Get monthly revenue for chart (last 12 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%Y-%m') as month_key,
        DATE_FORMAT(b.created_at, '%b %Y') as month_label,
        SUM(b.total_amount) as revenue,
        COUNT(*) as booking_count,
        AVG(b.total_amount) as avg_booking_value
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

// Get booking status distribution
$stmt = $db->prepare("
    SELECT 
        b.status,
        COUNT(*) as count,
        SUM(b.total_amount) as total_amount
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE s.owner_id = ?
    GROUP BY b.status
");
$stmt->execute([$userId]);
$bookingStatus = $stmt->fetchAll();

$statusCounts = [
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'completed' => 0,
    'no_show' => 0
];
$statusAmounts = [
    'confirmed' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'completed' => 0,
    'no_show' => 0
];

foreach ($bookingStatus as $status) {
    $statusCounts[$status['status']] = $status['count'];
    $statusAmounts[$status['status']] = $status['total_amount'];
}

// Get recent activities (combined feed)
$stmt = $db->prepare("
    (SELECT 
        'booking' as type,
        b.created_at as date,
        CONCAT('New booking from ', u.first_name, ' ', u.last_name) as description,
        b.total_amount as amount,
        b.status,
        s.stay_name as reference
    FROM bookings b
    JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 5)
    
    UNION ALL
    
    (SELECT 
        'review' as type,
        r.created_at as date,
        CONCAT('New ', r.overall_rating, '-star review from ', u.first_name, ' ', u.last_name) as description,
        NULL as amount,
        NULL as status,
        s.stay_name as reference
    FROM reviews r
    JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE s.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5)
    
    ORDER BY date DESC
    LIMIT 10
");
$stmt->execute([$userId, $userId]);
$recentActivities = $stmt->fetchAll();

// Get low inventory alerts
$stmt = $db->prepare("
    SELECT 
        s.stay_name,
        s.stay_id,  -- Add this line!
        sr.room_name,
        sr.room_id,
        sr.num_rooms_available as total_rooms,
        (
            SELECT COUNT(*) 
            FROM bookings b 
            WHERE b.stay_room_id = sr.room_id 
            AND b.status IN ('confirmed', 'pending')
            AND b.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND b.check_out_date >= CURDATE()
        ) as booked_next_7_days,
        sr.num_rooms_available - (
            SELECT COUNT(*) 
            FROM bookings b 
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

// Get today's tasks
$todayTasks = [];

if ($todayCheckins > 0) {
    $todayTasks[] = [
        'icon' => 'bi-box-arrow-in-right',
        'title' => 'Check-ins Today',
        'count' => $todayCheckins,
        'color' => 'success',
        'link' => 'bookings.php?date=' . date('Y-m-d') . '&type=checkin'
    ];
}

if ($todayCheckouts > 0) {
    $todayTasks[] = [
        'icon' => 'bi-box-arrow-left',
        'title' => 'Check-outs Today',
        'count' => $todayCheckouts,
        'color' => 'warning',
        'link' => 'bookings.php?date=' . date('Y-m-d') . '&type=checkout'
    ];
}

if ($pendingBookings > 0) {
    $todayTasks[] = [
        'icon' => 'bi-clock-history',
        'title' => 'Pending Bookings',
        'count' => $pendingBookings,
        'color' => 'warning',
        'link' => 'bookings.php?status=pending'
    ];
}

if (count($lowInventory) > 0) {
    $todayTasks[] = [
        'icon' => 'bi-exclamation-triangle',
        'title' => 'Low Inventory',
        'count' => count($lowInventory),
        'color' => 'danger',
        'link' => 'rooms.php?alert=low_inventory'
    ];
}
?>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="bi bi-speedometer2 me-2" style="color: var(--booking-blue);"></i>Dashboard</h1>
        <p>Welcome back, <?php echo sanitize($user['first_name']); ?>! Here's what's happening with your properties.</p>
    </div>
    <div class="top-actions">
        <button class="btn-secondary" onclick="window.location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <a href="property-add.php" class="btn-primary">
            <i class="bi bi-plus-lg"></i> Add Property
        </a>
    </div>
</div>

<!-- Today's Tasks (if any) -->
<?php if (!empty($todayTasks)): ?>
<div style="margin-bottom: 20px;">
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <?php foreach ($todayTasks as $task): ?>
        <a href="<?php echo $task['link']; ?>" class="card" style="flex: 1; min-width: 180px; text-decoration: none; color: inherit; transition: all 0.2s;">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--booking-<?php echo $task['color']; ?>)20; color: var(--booking-<?php echo $task['color']; ?>); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                    <i class="bi <?php echo $task['icon']; ?>"></i>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light);"><?php echo $task['title']; ?></div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--booking-<?php echo $task['color']; ?>);"><?php echo $task['count']; ?></div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon blue">
                <i class="bi bi-building"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                <?php echo $totalProperties; ?>
            </span>
        </div>
        <div class="stat-value"><?php echo $totalProperties; ?></div>
        <div class="stat-label">Properties</div>
        <div class="stat-footer">
            <span>Total Rooms</span>
            <span><?php echo $totalRooms; ?> (<?php echo $activeRooms; ?> active)</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon green">
                <i class="bi bi-calendar-check"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                <?php echo $totalBookings; ?>
            </span>
        </div>
        <div class="stat-value"><?php echo $totalBookings; ?></div>
        <div class="stat-label">Total Bookings</div>
        <div class="stat-footer">
            <span>Confirmed</span>
            <span><?php echo $statusCounts['confirmed'] ?? 0; ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon orange">
                <i class="bi bi-cash-stack"></i>
            </div>
            <span class="stat-trend">
                <i class="bi bi-arrow-up-short"></i>
                +<?php echo round(($totalRevenue > 0 ? ($monthlyRevenue[count($monthlyRevenue)-1]['revenue'] ?? 0) / ($totalRevenue / 12) * 100 : 0)); ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo formatPrice($totalRevenue); ?></div>
        <div class="stat-label">Total Revenue</div>
        <div class="stat-footer">
            <span>Avg. Booking</span>
            <span><?php echo $totalBookings > 0 ? formatPrice($totalRevenue / $totalBookings) : formatPrice(0); ?></span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-icon purple">
                <i class="bi bi-graph-up"></i>
            </div>
            <span class="stat-trend <?php echo $occupancyRate > 50 ? '' : 'down'; ?>">
                <i class="bi bi-<?php echo $occupancyRate > 50 ? 'arrow-up-short' : 'arrow-down-short'; ?>"></i>
                <?php echo $occupancyRate; ?>%
            </span>
        </div>
        <div class="stat-value"><?php echo $occupancyRate; ?>%</div>
        <div class="stat-label">Occupancy Rate</div>
        <div class="stat-footer">
            <span>Today</span>
            <span><?php echo $todayCheckins; ?> in / <?php echo $todayCheckouts; ?> out</span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
    <a href="bookings.php?status=pending" class="btn-outline" style="display: flex; align-items: center; gap: 6px;">
        <i class="bi bi-clock-history"></i>
        Pending (<?php echo $pendingBookings; ?>)
    </a>
    <a href="calendar.php" class="btn-outline" style="display: flex; align-items: center; gap: 6px;">
        <i class="bi bi-calendar-week"></i>
        View Calendar
    </a>
    <a href="rates.php" class="btn-outline" style="display: flex; align-items: center; gap: 6px;">
        <i class="bi bi-tags"></i>
        Update Rates
    </a>
    <?php if ($propertiesWithRestaurants > 0): ?>
    <a href="restaurant-management.php" class="btn-outline" style="display: flex; align-items: center; gap: 6px;">
        <i class="bi bi-shop"></i>
        Manage Restaurants
    </a>
    <?php endif; ?>
</div>

<!-- Charts Row -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px;">
    <!-- Revenue Chart -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-graph-up-arrow me-2" style="color: var(--booking-blue);"></i>Revenue Overview</h3>
            <select class="form-control" style="width: auto; padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--booking-border); border-radius: var(--radius-sm);" id="revenueRange">
                <option value="6">Last 6 months</option>
                <option value="12" selected>Last 12 months</option>
            </select>
        </div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
            <?php if (empty($monthlyRevenue)): ?>
            <div class="chart-empty">
                <i class="bi bi-inbox me-2"></i>No revenue data available
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking Status -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-pie-chart me-2" style="color: var(--booking-blue);"></i>Booking Status</h3>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 200px;">
                <canvas id="statusChart"></canvas>
            </div>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 20px;">
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">Confirmed</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--booking-success);"><?php echo $statusCounts['confirmed'] ?? 0; ?></div>
                    <div style="font-size: 0.6875rem;"><?php echo formatPrice($statusAmounts['confirmed'] ?? 0); ?></div>
                </div>
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">Pending</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--booking-warning);"><?php echo $statusCounts['pending'] ?? 0; ?></div>
                    <div style="font-size: 0.6875rem;"><?php echo formatPrice($statusAmounts['pending'] ?? 0); ?></div>
                </div>
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">Completed</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--booking-blue);"><?php echo $statusCounts['completed'] ?? 0; ?></div>
                    <div style="font-size: 0.6875rem;"><?php echo formatPrice($statusAmounts['completed'] ?? 0); ?></div>
                </div>
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">Cancelled</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--booking-danger);"><?php echo $statusCounts['cancelled'] ?? 0; ?></div>
                    <div style="font-size: 0.6875rem;"><?php echo formatPrice($statusAmounts['cancelled'] ?? 0); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Properties Section -->
<div class="card">
    <div class="card-header">
        <h3><i class="bi bi-building me-2" style="color: var(--booking-blue);"></i>Your Properties</h3>
        <div style="display: flex; gap: 12px;">
            <a href="properties.php" class="text-decoration-none" style="font-size: 0.75rem; color: var(--booking-blue); display: flex; align-items: center; gap: 4px;">
                <span>View All</span> <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($properties)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-building" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
            <h4 style="font-size: 1rem; margin-top: 16px; color: var(--booking-text-light);">No properties yet</h4>
            <p style="font-size: 0.875rem; color: var(--booking-text-lighter); margin-bottom: 20px;">Add your first property to start receiving bookings</p>
            <a href="property-add.php" class="btn-primary">Add Your First Property</a>
        </div>
        <?php else: ?>
        <div class="property-grid">
            <?php foreach ($properties as $property): 
                $completion = 0;
                $steps = 0;
                if ($property['main_image']) $steps++;
                if ($property['active_rooms'] > 0) $steps++;
                if ($property['priced_rooms'] > 0) $steps++;
                if ($property['description']) $steps++;
                if ($property['amenities']) $steps++;
                $completion = round(($steps / 5) * 100);
            ?>
            <div class="property-card">
                <div class="property-image" style="background-image: url('<?php echo getImageUrl($property['main_image'] ?? '', 'stay'); ?>');">
                    <span class="property-status <?php echo $property['is_verified'] ? 'verified' : 'pending'; ?>">
                        <?php echo $property['is_verified'] ? '✓ Verified' : '⏳ Pending'; ?>
                    </span>
                    <?php if ($completion < 100): ?>
                    <div style="position: absolute; bottom: 0; left: 0; right: 0; height: 4px; background: rgba(0,0,0,0.1);">
                        <div style="width: <?php echo $completion; ?>%; height: 100%; background: var(--booking-blue);"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="property-details">
                    <h4 class="property-name"><?php echo sanitize($property['stay_name']); ?></h4>
                    <div class="property-location">
                        <i class="bi bi-geo-alt"></i>
                        <?php echo sanitize($property['location_name'] ?? $property['city'] ?? 'Rwanda'); ?>
                    </div>
                    <div class="property-meta">
                        <span>
                            <i class="bi bi-door-open"></i> 
                            <?php echo $property['active_rooms']; ?>/<?php echo $property['total_rooms']; ?> rooms
                            <?php if ($property['restaurant_count'] > 0): ?>
                            · <i class="bi bi-shop"></i> Restaurant
                            <?php endif; ?>
                        </span>
                        <span class="property-rating">
                            <?php if ($property['review_count'] > 0): ?>
                            <i class="bi bi-star-fill"></i> <?php echo number_format($property['avg_rating'], 1); ?>
                            (<?php echo $property['review_count']; ?>)
                            <?php else: ?>
                            <span style="color: var(--booking-text-lighter);">No reviews</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 16px;">
                        <a href="property-edit.php?id=<?php echo $property['stay_id']; ?>" class="btn-outline" style="flex: 1; text-align: center; padding: 6px;">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <a href="rooms.php?property=<?php echo $property['stay_id']; ?>" class="btn-outline" style="flex: 1; text-align: center; padding: 6px;">
                            <i class="bi bi-door-open"></i> Rooms
                        </a>
                        <a href="calendar.php?property=<?php echo $property['stay_id']; ?>" class="btn-outline" style="flex: 1; text-align: center; padding: 6px;">
                            <i class="bi bi-calendar"></i> Calendar
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Activity Grid -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
    <!-- Recent Bookings -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-calendar-check me-2" style="color: var(--booking-blue);"></i>Recent Bookings</h3>
            <a href="bookings.php" class="text-decoration-none" style="font-size: 0.75rem; color: var(--booking-blue); display: flex; align-items: center; gap: 4px;">
                <span>View All</span> <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="card-body p-0">
            <table class="table">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Property</th>
                        <th>Dates</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentBookings)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--booking-text-lighter);">
                            <i class="bi bi-inbox" style="font-size: 1.5rem; display: block; margin-bottom: 8px;"></i>
                            No bookings yet
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($recentBookings as $booking): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600;"><?php echo sanitize($booking['first_name'] ?? 'Guest'); ?></div>
                                <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                                    <?php echo $booking['num_guests']; ?> guest(s)
                                    <?php if ($booking['days_ago'] == 0): ?>
                                    <span style="color: var(--booking-success);">· Today</span>
                                    <?php elseif ($booking['days_ago'] == 1): ?>
                                    <span style="color: var(--booking-blue);">· Yesterday</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 500;"><?php echo sanitize($booking['stay_name']); ?></div>
                                <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                                    <?php echo sanitize($booking['room_name']); ?>
                                </div>
                            </td>
                            <td>
                                <div><?php echo date('d M', strtotime($booking['check_in_date'])); ?> - <?php echo date('d M', strtotime($booking['check_out_date'])); ?></div>
                                <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                                    <?php echo $booking['num_nights']; ?> nights
                                </div>
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

    <!-- Recent Reviews -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-star me-2" style="color: var(--booking-blue);"></i>Recent Reviews</h3>
            <a href="reviews.php" class="text-decoration-none" style="font-size: 0.75rem; color: var(--booking-blue); display: flex; align-items: center; gap: 4px;">
                <span>View All</span> <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentReviews)): ?>
            <div style="text-align: center; padding: 40px;">
                <i class="bi bi-star" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 12px; color: var(--booking-text-light);">No reviews yet</p>
            </div>
            <?php else: ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($recentReviews as $review): ?>
                    <div style="padding: 16px; border-bottom: 1px solid var(--booking-border);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($review['profile_image']): ?>
                                <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $review['profile_image']; ?>" 
                                     style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--booking-blue); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600;">
                                    <?php echo strtoupper(substr($review['first_name'] ?? 'G', 0, 1)); ?>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600; font-size: 0.875rem;">
                                        <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'] ?? '', 0, 1) . '.'); ?>
                                    </div>
                                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                                        <?php echo $review['stay_name']; ?> · 
                                        <?php if ($review['days_ago'] == 0): ?>
                                        <span style="color: var(--booking-success);">Today</span>
                                        <?php elseif ($review['days_ago'] == 1): ?>
                                        Yesterday
                                        <?php else: ?>
                                        <?php echo $review['days_ago']; ?> days ago
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 4px; background: var(--booking-blue); color: white; padding: 4px 8px; border-radius: 4px; font-weight: 700; font-size: 0.875rem;">
                                <i class="bi bi-star-fill" style="font-size: 0.75rem;"></i>
                                <?php echo number_format($review['overall_rating'], 1); ?>
                            </div>
                        </div>
                        <?php if ($review['comment']): ?>
                        <p style="font-size: 0.8125rem; color: var(--booking-text); margin: 8px 0; line-height: 1.5;">
                            <?php echo substr(sanitize($review['comment']), 0, 150); ?>...
                        </p>
                        <?php endif; ?>
                        <div style="display: flex; gap: 12px; margin-top: 8px;">
                            <button class="btn-outline" style="padding: 4px 12px; font-size: 0.6875rem;" onclick="respondToReview(<?php echo $review['review_id']; ?>)">
                                <i class="bi bi-reply"></i> Reply
                            </button>
                            <span style="font-size: 0.6875rem; color: var(--booking-text-light);">
                                <i class="bi bi-people"></i> <?php echo $review['property_total_reviews']; ?> reviews total
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Upcoming Check-ins and Low Inventory -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
    <!-- Upcoming Check-ins -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-calendar-check me-2" style="color: var(--booking-blue);"></i>Upcoming Check-ins (Next 14 Days)</h3>
            <a href="calendar.php" class="text-decoration-none" style="font-size: 0.75rem; color: var(--booking-blue); display: flex; align-items: center; gap: 4px;">
                <span>View Calendar</span> <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($upcomingCheckins)): ?>
            <div style="text-align: center; padding: 30px;">
                <i class="bi bi-calendar-check" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 12px; color: var(--booking-text-light);">No upcoming check-ins</p>
            </div>
            <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                <?php foreach ($upcomingCheckins as $checkin): ?>
                <div style="border: 1px solid var(--booking-border); border-radius: var(--radius-md); padding: 16px; text-align: center; background: <?php echo $checkin['days_until'] == 0 ? 'var(--booking-light-blue)' : 'white'; ?>;">
                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--booking-blue);">
                        <?php echo date('d', strtotime($checkin['check_in_date'])); ?>
                    </div>
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light); text-transform: uppercase; margin-bottom: 12px;">
                        <?php echo date('M', strtotime($checkin['check_in_date'])); ?>
                    </div>
                    <div style="font-weight: 600; font-size: 0.875rem; margin-bottom: 4px;">
                        <?php echo sanitize($checkin['first_name'] ?? 'Guest'); ?>
                    </div>
                    <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                        <?php echo sanitize($checkin['stay_name']); ?>
                    </div>
                    <div style="margin-top: 12px;">
                        <?php if ($checkin['days_until'] == 0): ?>
                        <span class="status-badge status-confirmed" style="background: var(--booking-success); color: white;">
                            <i class="bi bi-hourglass"></i> Today
                        </span>
                        <?php elseif ($checkin['days_until'] == 1): ?>
                        <span class="status-badge status-confirmed">
                            <i class="bi bi-clock"></i> Tomorrow
                        </span>
                        <?php else: ?>
                        <span class="status-badge status-confirmed">
                            <i class="bi bi-calendar"></i> In <?php echo $checkin['days_until']; ?> days
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Low Inventory Alerts -->
    <?php if (!empty($lowInventory)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-exclamation-triangle me-2" style="color: var(--booking-warning);"></i>Low Inventory Alerts</h3>
        </div>
        <div class="card-body">
            <?php foreach ($lowInventory as $alert): ?>
            <div style="background: #fff4e6; border: 1px solid #ffe0b2; border-radius: var(--radius-md); padding: 12px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <i class="bi bi-exclamation-triangle-fill" style="color: var(--booking-warning);"></i>
                    <span style="font-weight: 600;"><?php echo sanitize($alert['stay_name']); ?></span>
                </div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light); margin-bottom: 8px;">
                    <?php echo sanitize($alert['room_name']); ?>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem;">Available next 7 days:</span>
                    <span style="font-weight: 700; font-size: 1rem; color: <?php echo $alert['available_next_7_days'] <= 0 ? 'var(--booking-danger)' : 'var(--booking-warning)'; ?>;">
                        <?php echo $alert['available_next_7_days']; ?> rooms
                    </span>
                </div>
<a href="rooms.php?property=<?php echo $alert['stay_id'] ?? ''; ?>" style="display: block; text-align: center; margin-top: 12px; font-size: 0.75rem; color: var(--booking-blue);">
    Manage Rooms →
</a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Activity Feed -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3><i class="bi bi-activity me-2" style="color: var(--booking-blue);"></i>Recent Activity</h3>
    </div>
    <div class="card-body p-0">
        <div style="max-height: 300px; overflow-y: auto;">
            <?php if (empty($recentActivities)): ?>
            <div style="text-align: center; padding: 40px;">
                <i class="bi bi-activity" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 12px; color: var(--booking-text-light);">No recent activity</p>
            </div>
            <?php else: ?>
                <?php foreach ($recentActivities as $activity): ?>
                <div style="display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid var(--booking-border);">
                    <?php if ($activity['type'] == 'booking'): ?>
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--booking-light-blue); color: var(--booking-blue); display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    <?php else: ?>
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: #fff4e6; color: var(--booking-warning); display: flex; align-items: center; justify-content: center;">
                            <i class="bi bi-star"></i>
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <div style="font-weight: 500; font-size: 0.8125rem;"><?php echo $activity['description']; ?></div>
                        <div style="font-size: 0.6875rem; color: var(--booking-text-light);">
                            <?php echo $activity['reference']; ?> · <?php echo timeAgo($activity['date']); ?>
                            <?php if ($activity['type'] == 'booking' && $activity['amount']): ?>
                            · <?php echo formatPrice($activity['amount']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($activity['type'] == 'booking'): ?>
                        <span class="status-badge status-<?php echo $activity['status']; ?>" style="font-size: 0.625rem;">
                            <?php echo ucfirst($activity['status']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.chart-container {
    position: relative;
    height: 250px;
    width: 100%;
}

.chart-container canvas {
    display: block;
    width: 100% !important;
    height: 100% !important;
}

.chart-empty {
    height: 250px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--booking-text-lighter);
    font-size: 0.875rem;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

/* Animation for new items */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.stat-card, .property-card, .card {
    animation: fadeIn 0.3s ease;
}

/* Hover effects */
.property-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.property-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-outline, .btn-primary, .btn-secondary {
    transition: all 0.2s;
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: var(--booking-gray);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: var(--booking-text-lighter);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--booking-text-light);
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Chart data preparation
const monthlyLabels = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'month_label') : []); ?>;
const monthlyData = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'revenue') : []); ?>;
const monthlyBookings = <?php echo json_encode(!empty($monthlyRevenue) ? array_column($monthlyRevenue, 'booking_count') : []); ?>;

// Status chart data
const confirmedCount = <?php echo $statusCounts['confirmed'] ?? 0; ?>;
const pendingCount = <?php echo $statusCounts['pending'] ?? 0; ?>;
const cancelledCount = <?php echo $statusCounts['cancelled'] ?? 0; ?>;
const completedCount = <?php echo $statusCounts['completed'] ?? 0; ?>;

// Safely initialize charts
document.addEventListener('DOMContentLoaded', function() {
    
    // Revenue Chart
    const ctx1 = document.getElementById('revenueChart')?.getContext('2d');
    if (ctx1) {
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: monthlyLabels.length ? monthlyLabels : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue',
                    data: monthlyData.length ? monthlyData : [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                    borderColor: '#003b95',
                    backgroundColor: 'rgba(0, 59, 149, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#003b95',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
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
                                const value = context.parsed.y;
                                const monthIndex = context.dataIndex;
                                const bookings = monthlyBookings[monthIndex] || 0;
                                return [
                                    'Revenue: ' + formatCurrency(value),
                                    'Bookings: ' + bookings
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#f0f0f0'
                        },
                        ticks: {
                            callback: function(value) {
                                if (value === 0) return 'RWF 0';
                                return 'RWF ' + (value / 1000) + 'K';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // Status Chart
    const ctx2 = document.getElementById('statusChart')?.getContext('2d');
    if (ctx2) {
        const hasBookingData = confirmedCount + pendingCount + cancelledCount + completedCount > 0;
        
        if (!hasBookingData) {
            ctx2.canvas.style.display = 'none';
            const parent = ctx2.canvas.parentNode;
            const emptyMsg = document.createElement('div');
            emptyMsg.style.cssText = 'height: 200px; display: flex; align-items: center; justify-content: center; color: var(--booking-text-lighter); font-size: 0.875rem;';
            emptyMsg.innerHTML = '<i class="bi bi-inbox me-2"></i>No booking data available';
            parent.appendChild(emptyMsg);
        } else {
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: ['Confirmed', 'Pending', 'Cancelled', 'Completed'],
                    datasets: [{
                        data: [confirmedCount, pendingCount, cancelledCount, completedCount],
                        backgroundColor: ['#008009', '#ff8c00', '#e21111', '#003b95'],
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
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
    }
});

// Helper function for currency formatting
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

// Sidebar toggle for mobile
function toggleSidebar() {
    document.getElementById('partnerSidebar').classList.toggle('open');
}

// Respond to review function
function respondToReview(reviewId) {
    window.location.href = 'reviews.php?respond=' + reviewId;
}

// Refresh data
function refreshDashboard() {
    location.reload();
}

// Change revenue chart range
document.getElementById('revenueRange')?.addEventListener('change', function() {
    // This would reload with different date range
    // For now, just reload the page with a parameter
    window.location.href = 'dashboard.php?range=' + this.value;
});

// Show mobile menu toggle on small screens
if (window.innerWidth <= 768) {
    document.getElementById('menuToggle').style.display = 'block';
}

window.addEventListener('resize', function() {
    if (window.innerWidth <= 768) {
        document.getElementById('menuToggle').style.display = 'block';
    } else {
        document.getElementById('menuToggle').style.display = 'none';
        document.getElementById('partnerSidebar').classList.remove('open');
    }
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>