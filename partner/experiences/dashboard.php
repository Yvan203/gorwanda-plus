<?php
$pageTitle = 'Dashboard';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get all experiences with stats
$stmt = $db->prepare("
    SELECT 
        a.*,
        c.name as category_name,
        l.name as location_name,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id) as tier_count,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as active_tiers,
        (SELECT COUNT(*) FROM bookings b 
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND DATE(b.experience_date) = CURDATE()) as today_bookings,
        (SELECT COALESCE(AVG(r.overall_rating), 0) FROM reviews r WHERE r.attraction_id = a.attraction_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.attraction_id = a.attraction_id) as review_count
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.owner_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// Get total stats across all experiences
$totalExperiences = count($experiences);
$totalTiers = 0;
$totalBookings = 0;
$totalRevenue = 0;
$pendingCount = 0;
$todayCount = 0;

foreach ($experiences as $exp) {
    $totalTiers += $exp['tier_count'];
    $pendingCount += $exp['pending_bookings'];
    $todayCount += $exp['today_bookings'];
    
    // Get revenue for this experience
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(b.total_amount), 0) as revenue, COUNT(*) as bookings
        FROM bookings b
        JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        WHERE at.attraction_id = ? AND b.status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$exp['attraction_id']]);
    $stats = $stmt->fetch();
    $totalRevenue += $stats['revenue'];
    $totalBookings += $stats['bookings'];
}

// Get recent bookings
$stmt = $db->prepare("
    SELECT b.*, a.attraction_name, at.tier_name, u.first_name, u.last_name
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE a.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 5
");
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll();

// Get upcoming experiences (next 7 days)
$stmt = $db->prepare("
    SELECT b.*, a.attraction_name, at.tier_name, u.first_name, u.last_name
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    LEFT JOIN users u ON b.user_id = u.user_id
    WHERE a.owner_id = ? 
    AND b.experience_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND b.status IN ('confirmed')
    ORDER BY b.experience_date ASC
    LIMIT 5
");
$stmt->execute([$userId]);
$upcomingExperiences = $stmt->fetchAll();

// Get recent reviews
$stmt = $db->prepare("
    SELECT r.*, a.attraction_name, u.first_name, u.last_name
    FROM reviews r
    JOIN attractions a ON r.attraction_id = a.attraction_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE a.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 3
");
$stmt->execute([$userId]);
$recentReviews = $stmt->fetchAll();

// Get monthly revenue for chart (last 6 months)
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%b') as month,
        SUM(b.total_amount) as revenue,
        COUNT(*) as bookings
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    WHERE a.owner_id = ? AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
    ORDER BY b.created_at ASC
");
$stmt->execute([$userId]);
$monthlyData = $stmt->fetchAll();

// Fill in missing months
$monthlyRevenue = [];
$monthlyLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('M', strtotime("-$i months"));
    $monthlyLabels[] = $month;
    $found = false;
    foreach ($monthlyData as $data) {
        if ($data['month'] == $month) {
            $monthlyRevenue[] = $data['revenue'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $monthlyRevenue[] = 0;
    }
}

// Get booking status distribution
$stmt = $db->prepare("
    SELECT 
        b.status,
        COUNT(*) as count
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    WHERE a.owner_id = ?
    GROUP BY b.status
");
$stmt->execute([$userId]);
$bookingStatus = $stmt->fetchAll();

$statusCounts = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'no_show' => 0
];
foreach ($bookingStatus as $status) {
    $statusCounts[$status['status']] = $status['count'];
}

// Get category distribution
$stmt = $db->prepare("
    SELECT 
        c.name as category,
        COUNT(a.attraction_id) as count
    FROM attractions a
    JOIN categories c ON a.category_id = c.category_id
    WHERE a.owner_id = ?
    GROUP BY c.category_id
");
$stmt->execute([$userId]);
$categoryData = $stmt->fetchAll();

// Get today's date info
$today = date('l, F j, Y');
?>

<style>
/* Dashboard Specific Styles - Optimized for no horizontal scroll */
.dashboard-container {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

/* Welcome Card - Full Width */
.welcome-card {
    background: linear-gradient(135deg, var(--exp-purple), var(--exp-dark-purple));
    color: white;
    padding: 24px 28px;
    border-radius: var(--radius-lg);
    margin-bottom: 24px;
    width: 100%;
}

.welcome-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 4px;
    letter-spacing: -0.3px;
}

.welcome-date {
    font-size: 0.8125rem;
    opacity: 0.9;
    margin-bottom: 16px;
}

.quick-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.quick-action-btn {
    background: rgba(255,255,255,0.15);
    color: white;
    border: 1px solid rgba(255,255,255,0.2);
    padding: 8px 16px;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.8125rem;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.quick-action-btn:hover {
    background: rgba(255,255,255,0.25);
}

/* Stats Grid - 4 cards in one row */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
    width: 100%;
}

.stat-card {
    background: var(--exp-white);
    border-radius: var(--radius-md);
    padding: 18px 16px;
    border: 1px solid var(--exp-border);
    transition: all 0.2s;
    width: 100%;
}

.stat-card:hover {
    box-shadow: var(--shadow-md);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.stat-icon {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.stat-icon.purple { background: var(--exp-light-purple); color: var(--exp-purple); }
.stat-icon.green { background: #e6f4ea; color: var(--exp-success); }
.stat-icon.orange { background: #fff4e6; color: var(--exp-warning); }
.stat-icon.blue { background: #e1f5fe; color: #0288d1; }

.stat-trend {
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 100px;
    background: #e6f4ea;
    color: var(--exp-success);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    line-height: 1.2;
    margin-bottom: 2px;
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--exp-border);
    font-size: 0.6875rem;
    display: flex;
    justify-content: space-between;
    color: var(--exp-text-light);
}

.stat-footer span {
    font-weight: 600;
    color: var(--exp-text);
}

/* Charts Grid - 2 columns */
.charts-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
    width: 100%;
}

.chart-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--exp-border);
    width: 100%;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.chart-title {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0;
}

.chart-container {
    height: 180px;
    position: relative;
    width: 100%;
}

/* Content Grid - 2 columns */
.content-grid {
    display: grid;
    grid-template-columns: 1.6fr 1fr;
    gap: 16px;
    width: 100%;
}

.content-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    width: 100%;
}

.card-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--exp-gray);
}

.card-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0;
}

.card-header a {
    color: var(--exp-purple);
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
}

.card-body {
    padding: 12px;
}

/* Table Styles - Compact */
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}

.table th {
    text-align: left;
    padding: 10px 12px;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--exp-text-light);
    text-transform: uppercase;
    background: var(--exp-gray);
    border-bottom: 1px solid var(--exp-border);
}

.table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--exp-border);
    vertical-align: middle;
}

.table tr:last-child td {
    border-bottom: none;
}

/* Status Badges - Smaller */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    white-space: nowrap;
}

.status-confirmed {
    background: #e6f4ea;
    color: var(--exp-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--exp-warning);
}

.status-cancelled {
    background: #fce8e8;
    color: var(--exp-danger);
}

.status-completed {
    background: var(--exp-light-purple);
    color: var(--exp-purple);
}

/* Experience Items - Compact */
.experience-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.experience-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-md);
    transition: all 0.2s;
}

.experience-item:hover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
}

.experience-image {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--exp-gray);
    flex-shrink: 0;
}

.experience-info {
    flex: 1;
    min-width: 0; /* Prevents overflow */
}

.experience-name {
    font-weight: 600;
    font-size: 0.8125rem;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.experience-meta {
    font-size: 0.625rem;
    color: var(--exp-text-light);
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.experience-badge {
    padding: 2px 6px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
    white-space: nowrap;
}

.experience-badge.active {
    background: #e6f4ea;
    color: var(--exp-success);
}

.experience-badge.inactive {
    background: var(--exp-gray);
    color: var(--exp-text-light);
}

/* Upcoming Grid - 5 columns */
.upcoming-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}

.upcoming-card {
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    padding: 10px 6px;
    text-align: center;
    background: white;
}

.upcoming-date {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-purple);
    line-height: 1.2;
}

.upcoming-month {
    font-size: 0.5625rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.upcoming-name {
    font-size: 0.625rem;
    font-weight: 600;
    margin: 6px 0 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.upcoming-time {
    font-size: 0.5625rem;
    color: var(--exp-text-light);
}

/* Review Items - Compact */
.review-item {
    padding: 10px 0;
    border-bottom: 1px solid var(--exp-border);
}

.review-item:last-child {
    border-bottom: none;
}

.review-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
}

.reviewer-name {
    font-weight: 600;
    font-size: 0.75rem;
}

.review-rating {
    color: var(--exp-warning);
    font-size: 0.625rem;
}

.review-meta {
    font-size: 0.5625rem;
    color: var(--exp-text-light);
    margin-bottom: 4px;
}

.review-text {
    font-size: 0.6875rem;
    color: var(--exp-text);
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

/* Responsive Breakpoints */
@media (max-width: 1400px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .upcoming-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 1200px) {
    .charts-grid,
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .upcoming-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .quick-actions {
        flex-direction: column;
    }
    .quick-action-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="dashboard-container">
    <!-- Welcome Card -->
    <div class="welcome-card">
        <h1 class="welcome-title">Good <?php echo date('a') == 'am' ? 'morning' : 'afternoon'; ?>, <?php echo sanitize($_SESSION['user_name']); ?>!</h1>
        <div class="welcome-date"><?php echo $today; ?></div>
        
        <div class="quick-actions">
            <a href="add-listing.php" class="quick-action-btn">
                <i class="bi bi-plus-circle"></i> New experience
            </a>
            <a href="schedule.php" class="quick-action-btn">
                <i class="bi bi-calendar-week"></i> Schedule
            </a>
            <a href="bookings.php?status=pending" class="quick-action-btn">
                <i class="bi bi-clock-history"></i> Pending (<?php echo $pendingCount; ?>)
            </a>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon purple">
                    <i class="bi bi-ticket-perforated"></i>
                </div>
                <span class="stat-trend"><?php echo $totalExperiences; ?></span>
            </div>
            <div class="stat-value"><?php echo $totalExperiences; ?></div>
            <div class="stat-label">Total Experiences</div>
            <div class="stat-footer">
                <span><?php echo $totalTiers; ?> pricing tiers</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon green">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <span class="stat-trend"><?php echo $totalBookings; ?></span>
            </div>
            <div class="stat-value"><?php echo $totalBookings; ?></div>
            <div class="stat-label">Total Bookings</div>
            <div class="stat-footer">
                <span>Pending: <?php echo $pendingCount; ?></span>
                <span>Today: <?php echo $todayCount; ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon orange">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <span class="stat-trend">RWF</span>
            </div>
            <div class="stat-value"><?php echo number_format($totalRevenue / 1000, 0); ?>K</div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-footer">
                <span>Avg: <?php echo $totalBookings > 0 ? number_format(($totalRevenue / $totalBookings) / 1000, 0) : 0; ?>K</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon blue">
                    <i class="bi bi-star"></i>
                </div>
                <span class="stat-trend">
                    <?php 
                    $avgRating = 0;
                    $ratingCount = 0;
                    foreach ($experiences as $exp) {
                        if ($exp['avg_rating'] > 0) {
                            $avgRating += $exp['avg_rating'];
                            $ratingCount++;
                        }
                    }
                    echo $ratingCount > 0 ? round($avgRating / $ratingCount, 1) : '0';
                    ?>
                </span>
            </div>
            <div class="stat-value"><?php echo array_sum(array_column($experiences, 'review_count')); ?></div>
            <div class="stat-label">Reviews</div>
            <div class="stat-footer">
                <span><?php echo $ratingCount; ?> rated</span>
            </div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="charts-grid">
        <!-- Revenue Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Revenue trend</h3>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Booking Status -->
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">Booking status</h3>
            </div>
            <div class="chart-container" style="height: 160px;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Experiences List -->
    <div class="content-card" style="margin-bottom: 16px;">
        <div class="card-header">
            <h3>Your experiences</h3>
            <a href="listings.php">Manage →</a>
        </div>
        <div class="card-body">
            <?php if (empty($experiences)): ?>
            <div style="text-align: center; padding: 24px;">
                <i class="bi bi-ticket-perforated" style="font-size: 1.5rem; color: var(--exp-text-light);"></i>
                <p style="margin-top: 8px; font-size: 0.8125rem; color: var(--exp-text-light);">No experiences yet</p>
                <a href="add-listing.php" class="btn-primary btn-sm" style="display: inline-block; margin-top: 8px;">
                    Add experience
                </a>
            </div>
            <?php else: ?>
            <div class="experience-list">
                <?php foreach (array_slice($experiences, 0, 3) as $exp): ?>
                <div class="experience-item">
                    <img src="<?php echo getImageUrl($exp['main_image'] ?? '', 'attraction'); ?>" class="experience-image">
                    <div class="experience-info">
                        <div class="experience-name"><?php echo sanitize($exp['attraction_name']); ?></div>
                        <div class="experience-meta">
                            <span><i class="bi bi-tag"></i> <?php echo sanitize($exp['category_name'] ?? 'Other'); ?></span>
                            <span><i class="bi bi-layers"></i> <?php echo $exp['tier_count']; ?> tiers</span>
                        </div>
                    </div>
                    <span class="experience-badge <?php echo $exp['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $exp['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <!-- Recent Bookings -->
        <div class="content-card">
            <div class="card-header">
                <h3>Recent bookings</h3>
                <a href="bookings.php">View all →</a>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Experience</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentBookings)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 24px; color: var(--exp-text-light);">
                                No bookings yet
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($recentBookings as $booking): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 500; font-size: 0.75rem;">
                                        <?php echo sanitize($booking['first_name'] ?? 'Guest'); ?>
                                    </div>
                                    <div style="font-size: 0.5625rem; color: var(--exp-text-light);">
                                        <?php echo $booking['num_participants']; ?> pax
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 500; font-size: 0.75rem; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo sanitize($booking['attraction_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 0.7rem;"><?php echo date('d M', strtotime($booking['experience_date'])); ?></div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $booking['status']; ?>">
                                        <?php echo $booking['status'] == 'confirmed' ? 'OK' : substr($booking['status'], 0, 3); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Column -->
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <!-- Upcoming -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Upcoming (7 days)</h3>
                    <a href="schedule.php">Calendar →</a>
                </div>
                <div class="card-body">
                    <?php if (empty($upcomingExperiences)): ?>
                    <div style="text-align: center; padding: 16px; color: var(--exp-text-light);">
                        <p style="font-size: 0.75rem;">No upcoming</p>
                    </div>
                    <?php else: ?>
                    <div class="upcoming-grid">
                        <?php foreach ($upcomingExperiences as $exp): ?>
                        <div class="upcoming-card">
                            <div class="upcoming-date">
                                <?php echo date('d', strtotime($exp['experience_date'])); ?>
                            </div>
                            <div class="upcoming-month">
                                <?php echo date('M', strtotime($exp['experience_date'])); ?>
                            </div>
                            <div class="upcoming-name" title="<?php echo sanitize($exp['attraction_name']); ?>">
                                <?php echo substr(sanitize($exp['attraction_name']), 0, 8); ?>..
                            </div>
                            <div class="upcoming-time">
                                <?php echo date('H:i', strtotime($exp['start_time'] ?? '09:00')); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Reviews -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent reviews</h3>
                    <a href="reviews.php">View →</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentReviews)): ?>
                    <div style="text-align: center; padding: 16px; color: var(--exp-text-light);">
                        <p style="font-size: 0.75rem;">No reviews yet</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($recentReviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <span class="reviewer-name"><?php echo sanitize($review['first_name']); ?></span>
                                <span class="review-rating">
                                    <?php echo round($review['overall_rating'] / 2, 1); ?> ★
                                </span>
                            </div>
                            <div class="review-meta">
                                <?php echo sanitize($review['attraction_name']); ?> • <?php echo timeAgo($review['created_at']); ?>
                            </div>
                            <div class="review-text">
                                "<?php echo substr(sanitize($review['comment'] ?? ''), 0, 60); ?>..."
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
const ctx1 = document.getElementById('revenueChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($monthlyLabels); ?>,
        datasets: [{
            data: <?php echo json_encode($monthlyRevenue); ?>,
            borderColor: '#9333ea',
            backgroundColor: 'rgba(147, 51, 234, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#9333ea',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 8,
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        return 'RWF ' + (context.parsed.y / 1000) + 'K';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: '#f0f0f0' },
                ticks: {
                    callback: function(value) {
                        return (value / 1000) + 'K';
                    },
                    font: { size: 9 }
                }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: 9 } }
            }
        }
    }
});

// Status Chart
const ctx2 = document.getElementById('statusChart').getContext('2d');
new Chart(ctx2, {
    type: 'doughnut',
    data: {
        labels: ['Confirmed', 'Pending', 'Completed'],
        datasets: [{
            data: [
                <?php echo $statusCounts['confirmed']; ?>,
                <?php echo $statusCounts['pending']; ?>,
                <?php echo $statusCounts['completed']; ?>
            ],
            backgroundColor: ['#10b981', '#f59e0b', '#9333ea'],
            borderWidth: 0,
            hoverOffset: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 8,
                cornerRadius: 4
            }
        }
    }
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>