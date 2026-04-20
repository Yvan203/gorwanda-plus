<?php
$pageTitle = 'Partner Hub';
require_once 'includes/partner_header.php';

$user = getCurrentUser();

// Check if partner needs to complete onboarding
if (!isPartnerProfileComplete($user['user_id'])) {
    header('Location: /gorwanda-plus/partner/onboarding.php');
    exit;
}

// Get user's business types
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

// Get quick stats for each business type
$stats = [];

if (in_array('stays', $businessTypes)) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_properties,
            COALESCE(SUM(CASE WHEN is_verified = 1 THEN 1 END), 0) as verified,
            COALESCE(SUM(CASE WHEN is_verified = 0 AND is_active = 1 THEN 1 END), 0) as pending,
            (SELECT COUNT(*) FROM stay_rooms sr JOIN stays s ON sr.stay_id = s.stay_id WHERE s.owner_id = ?) as total_rooms
        FROM stays
        WHERE owner_id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $stats['stays'] = $stmt->fetch();
}

if (in_array('car_rental', $businessTypes)) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_fleet,
            COALESCE(SUM(cf.quantity_available), 0) as total_cars,
            (SELECT COUNT(*) FROM bookings b JOIN car_fleet cf ON b.car_id = cf.car_id WHERE cf.rental_id IN (SELECT rental_id FROM car_rentals WHERE owner_id = ?)) as total_bookings
        FROM car_rentals cr
        LEFT JOIN car_fleet cf ON cr.rental_id = cf.rental_id
        WHERE cr.owner_id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $stats['cars'] = $stmt->fetch();
}

if (in_array('attraction', $businessTypes)) {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_experiences,
            (SELECT COUNT(*) FROM attraction_tiers at JOIN attractions a ON at.attraction_id = a.attraction_id WHERE a.owner_id = ?) as total_tiers,
            (SELECT COUNT(*) FROM bookings b JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id WHERE at.attraction_id IN (SELECT attraction_id FROM attractions WHERE owner_id = ?)) as total_bookings
        FROM attractions
        WHERE owner_id = ?
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $stats['experiences'] = $stmt->fetch();
}
?>

<style>
.industry-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 24px;
    margin-top: 32px;
}

.industry-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    overflow: hidden;
    transition: all 0.3s;
    position: relative;
}

.industry-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.industry-card.stays { border-top: 4px solid var(--success-green); }
.industry-card.cars { border-top: 4px solid var(--warning-orange); }
.industry-card.experiences { border-top: 4px solid #9333ea; }

.industry-header {
    padding: 24px;
    background: linear-gradient(to right, rgba(0,102,255,0.02), transparent);
    display: flex;
    align-items: center;
    gap: 16px;
}

.industry-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
}

.industry-icon.stays { background: #e6f4ea; color: var(--success-green); }
.industry-icon.cars { background: #fff4e6; color: var(--warning-orange); }
.industry-icon.experiences { background: #f3e8ff; color: #9333ea; }

.industry-title h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.industry-title p {
    color: var(--text-gray);
    margin: 0;
    font-size: 0.875rem;
}

.industry-stats {
    padding: 20px 24px;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    border-bottom: 1px solid var(--border-gray);
}

.stat-block {
    text-align: center;
}

.stat-block .value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
}

.stat-block .label {
    font-size: 0.75rem;
    color: var(--text-gray);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.industry-actions {
    padding: 20px 24px;
    display: flex;
    gap: 12px;
}

.btn-industry {
    flex: 1;
    padding: 12px;
    border-radius: var(--radius-md);
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-industry.stays {
    background: var(--success-green);
    color: white;
}

.btn-industry.cars {
    background: var(--warning-orange);
    color: white;
}

.btn-industry.experiences {
    background: #9333ea;
    color: white;
}

.btn-industry:hover {
    opacity: 0.9;
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.btn-outline-industry {
    flex: 1;
    padding: 12px;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    background: white;
    color: var(--text-dark);
    text-align: center;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-outline-industry:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

.welcome-section {
    background: linear-gradient(135deg, var(--primary-blue), var(--primary-dark));
    color: white;
    padding: 40px;
    border-radius: var(--radius-lg);
    margin-bottom: 32px;
}

.welcome-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.welcome-text {
    font-size: 1rem;
    opacity: 0.9;
    margin-bottom: 24px;
}

.quick-tips {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
}

.tip {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9375rem;
    background: rgba(255,255,255,0.1);
    padding: 8px 16px;
    border-radius: 100px;
}

@media (max-width: 768px) {
    .industry-grid {
        grid-template-columns: 1fr;
    }
    .quick-tips {
        flex-direction: column;
        gap: 12px;
    }
}
</style>

<div class="top-bar">
    <div class="page-title">
        <h1>Partner Dashboard</h1>
        <p>Manage all your businesses from one place</p>
    </div>
    <div class="top-actions">
        <a href="/gorwanda-plus/" class="btn-secondary">
            <i class="bi bi-eye"></i> View Site
        </a>
    </div>
</div>

<!-- Welcome Section -->
<div class="welcome-section">
    <h2 class="welcome-title">Welcome back, <?php echo sanitize($_SESSION['user_name'] ?? 'Partner'); ?>! 👋</h2>
    <p class="welcome-text">Here's what's happening with your businesses today.</p>
    
    <div class="quick-tips">
        <div class="tip">
            <i class="bi bi-lightbulb"></i>
            <?php 
            $totalPending = 0;
            foreach ($businessTypes as $type) {
                if ($type === 'stays') $totalPending += $stats['stays']['pending'] ?? 0;
            }
            echo $totalPending > 0 ? "$totalPending listings pending approval" : "All listings are approved";
            ?>
        </div>
        <div class="tip">
            <i class="bi bi-calendar-check"></i>
            Check your bookings for today
        </div>
        <div class="tip">
            <i class="bi bi-star"></i>
            Respond to new reviews
        </div>
    </div>
</div>

<!-- Industry Grid -->
<div class="industry-grid">
    <?php if (in_array('stays', $businessTypes)): ?>
    <div class="industry-card stays">
        <div class="industry-header">
            <div class="industry-icon stays">
                <i class="bi bi-building"></i>
            </div>
            <div class="industry-title">
                <h3>Stays</h3>
                <p>Hotels, Apartments, Lodges</p>
            </div>
        </div>
        
        <div class="industry-stats">
            <div class="stat-block">
                <div class="value"><?php echo $stats['stays']['total_properties'] ?? 0; ?></div>
                <div class="label">Properties</div>
            </div>
            <div class="stat-block">
                <div class="value"><?php echo $stats['stays']['total_rooms'] ?? 0; ?></div>
                <div class="label">Rooms</div>
            </div>
            <div class="stat-block">
                <div class="value" style="color: var(--success-green);"><?php echo $stats['stays']['verified'] ?? 0; ?></div>
                <div class="label">Verified</div>
            </div>
            <div class="stat-block">
                <div class="value" style="color: var(--warning-orange);"><?php echo $stats['stays']['pending'] ?? 0; ?></div>
                <div class="label">Pending</div>
            </div>
        </div>
        
        <div class="industry-actions">
            <a href="stays/dashboard.php" class="btn-industry stays">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="stays/properties.php" class="btn-outline-industry">
                <i class="bi bi-building"></i> Manage
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (in_array('car_rental', $businessTypes)): ?>
    <div class="industry-card cars">
        <div class="industry-header">
            <div class="industry-icon cars">
                <i class="bi bi-car-front"></i>
            </div>
            <div class="industry-title">
                <h3>Car Rentals</h3>
                <p>Fleet Management</p>
            </div>
        </div>
        
        <div class="industry-stats">
            <div class="stat-block">
                <div class="value"><?php echo $stats['cars']['total_fleet'] ?? 0; ?></div>
                <div class="label">Models</div>
            </div>
            <div class="stat-block">
                <div class="value"><?php echo $stats['cars']['total_cars'] ?? 0; ?></div>
                <div class="label">Total Cars</div>
            </div>
            <div class="stat-block">
                <div class="value"><?php echo $stats['cars']['total_bookings'] ?? 0; ?></div>
                <div class="label">Bookings</div>
            </div>
            <div class="stat-block">
                <div class="value">-</div>
                <div class="label">Utilization</div>
            </div>
        </div>
        
        <div class="industry-actions">
            <a href="cars/dashboard.php" class="btn-industry cars">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="cars/fleet.php" class="btn-outline-industry">
                <i class="bi bi-car-front"></i> Manage
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (in_array('attraction', $businessTypes)): ?>
    <div class="industry-card experiences">
        <div class="industry-header">
            <div class="industry-icon experiences">
                <i class="bi bi-ticket-perforated"></i>
            </div>
            <div class="industry-title">
                <h3>Experiences</h3>
                <p>Tours & Activities</p>
            </div>
        </div>
        
        <div class="industry-stats">
            <div class="stat-block">
                <div class="value"><?php echo $stats['experiences']['total_experiences'] ?? 0; ?></div>
                <div class="label">Experiences</div>
            </div>
            <div class="stat-block">
                <div class="value"><?php echo $stats['experiences']['total_tiers'] ?? 0; ?></div>
                <div class="label">Pricing Tiers</div>
            </div>
            <div class="stat-block">
                <div class="value"><?php echo $stats['experiences']['total_bookings'] ?? 0; ?></div>
                <div class="label">Bookings</div>
            </div>
            <div class="stat-block">
                <div class="value">-</div>
                <div class="label">Rating</div>
            </div>
        </div>
        
        <div class="industry-actions">
            <a href="experiences/dashboard.php" class="btn-industry experiences">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="experiences/listings.php" class="btn-outline-industry">
                <i class="bi bi-ticket-perforated"></i> Manage
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Activity Across All Businesses -->
<div class="card" style="margin-top: 32px;">
    <div class="card-header">
        <h3 class="card-title">Recent Activity Across All Businesses</h3>
    </div>
    <div class="card-body">
        <?php
        // Get recent bookings across all user's businesses
        $activities = [];
        
        if (in_array('stays', $businessTypes)) {
            $stmt = $db->prepare("
                SELECT 'stay' as type, b.created_at, b.booking_reference, b.total_amount, b.status,
                       s.stay_name as item_name, CONCAT(u.first_name, ' ', u.last_name) as guest_name
                FROM bookings b
                JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
                JOIN stays s ON sr.stay_id = s.stay_id
                LEFT JOIN users u ON b.user_id = u.user_id
                WHERE s.owner_id = ?
                ORDER BY b.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $activities = array_merge($activities, $stmt->fetchAll());
        }
        
        if (in_array('car_rental', $businessTypes)) {
            $stmt = $db->prepare("
                SELECT 'car' as type, b.created_at, b.booking_reference, b.total_amount, b.status,
                       CONCAT(cf.brand, ' ', cf.model) as item_name, CONCAT(u.first_name, ' ', u.last_name) as guest_name
                FROM bookings b
                JOIN car_fleet cf ON b.car_id = cf.car_id
                JOIN car_rentals cr ON cf.rental_id = cr.rental_id
                LEFT JOIN users u ON b.user_id = u.user_id
                WHERE cr.owner_id = ?
                ORDER BY b.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $activities = array_merge($activities, $stmt->fetchAll());
        }
        
        if (in_array('attraction', $businessTypes)) {
            $stmt = $db->prepare("
                SELECT 'experience' as type, b.created_at, b.booking_reference, b.total_amount, b.status,
                       a.attraction_name as item_name, CONCAT(u.first_name, ' ', u.last_name) as guest_name
                FROM bookings b
                JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
                JOIN attractions a ON at.attraction_id = a.attraction_id
                LEFT JOIN users u ON b.user_id = u.user_id
                WHERE a.owner_id = ?
                ORDER BY b.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$userId]);
            $activities = array_merge($activities, $stmt->fetchAll());
        }
        
        // Sort by date
        usort($activities, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        $activities = array_slice($activities, 0, 10);
        ?>
        
        <?php if (empty($activities)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-gray);">
            <i class="bi bi-clock-history" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
            <p>No recent activity</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Reference</th>
                    <th>Item</th>
                    <th>Guest</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                <tr>
                    <td>
                        <span class="badge" style="background: <?php 
                            echo $activity['type'] === 'stay' ? '#e6f4ea' : 
                                ($activity['type'] === 'car' ? '#fff4e6' : '#f3e8ff'); 
                        ?>; color: <?php
                            echo $activity['type'] === 'stay' ? 'var(--success-green)' : 
                                ($activity['type'] === 'car' ? 'var(--warning-orange)' : '#9333ea');
                        ?>;">
                            <i class="bi bi-<?php 
                                echo $activity['type'] === 'stay' ? 'building' : 
                                    ($activity['type'] === 'car' ? 'car-front' : 'ticket-perforated'); 
                            ?> me-1"></i>
                            <?php echo ucfirst($activity['type']); ?>
                        </span>
                    </td>
                    <td><span style="font-family: monospace;"><?php echo $activity['booking_reference']; ?></span></td>
                    <td><?php echo sanitize($activity['item_name']); ?></td>
                    <td><?php echo sanitize($activity['guest_name'] ?? 'Guest'); ?></td>
                    <td><?php echo timeAgo($activity['created_at']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $activity['status']; ?>">
                            <?php echo ucfirst($activity['status']); ?>
                        </span>
                    </td>
                    <td><strong><?php echo formatPrice($activity['total_amount']); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/partner_footer.php'; ?>