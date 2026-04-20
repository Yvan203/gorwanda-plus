<?php
$rentalId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$rentalId) {
    header('Location: cars.php');
    exit;
}

$pageTitle = 'Car Rental Details';
require_once 'includes/admin_header.php';

$db = getDB();

// Get rental company details
$stmt = $db->prepare("
    SELECT 
        cr.*,
        l.name as location_name,
        l.latitude as location_lat,
        l.longitude as location_lng,
        u.user_id as owner_id,
        u.first_name as owner_first,
        u.last_name as owner_last,
        u.email as owner_email,
        u.phone as owner_phone,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id) as total_vehicles,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND is_active = 1) as active_vehicles,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND status = 'available') as available_vehicles,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND status = 'rented') as rented_vehicles,
        (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND status = 'maintenance') as maintenance_vehicles,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE cf.rental_id = cr.rental_id AND b.status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE cf.rental_id = cr.rental_id AND b.status IN ('confirmed', 'completed')) as total_revenue,
        (SELECT AVG(b.total_amount) FROM bookings b 
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id 
         WHERE cf.rental_id = cr.rental_id AND b.status IN ('confirmed', 'completed')) as avg_booking_value,
        (SELECT COUNT(*) FROM reviews r 
         WHERE r.rental_id = cr.rental_id AND r.review_type = 'car_rental') as review_count,
        (SELECT AVG(overall_rating) FROM reviews r 
         WHERE r.rental_id = cr.rental_id AND r.review_type = 'car_rental') as avg_rating
    FROM car_rentals cr
    LEFT JOIN locations l ON cr.location_id = l.location_id
    LEFT JOIN users u ON cr.owner_id = u.user_id
    WHERE cr.rental_id = ?
");
$stmt->execute([$rentalId]);
$rental = $stmt->fetch();

if (!$rental) {
    header('Location: cars.php');
    exit;
}

// Get all vehicles
$stmt = $db->prepare("
    SELECT 
        cf.*,
        (SELECT COUNT(*) FROM bookings b 
         WHERE b.car_id = cf.car_id AND b.status IN ('confirmed', 'completed')) as booking_count,
        (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b 
         WHERE b.car_id = cf.car_id AND b.status IN ('confirmed', 'completed')) as revenue,
        (SELECT COUNT(*) FROM car_maintenance cm 
         WHERE cm.car_id = cf.car_id AND cm.status IN ('scheduled', 'in_progress')) as pending_maintenance
    FROM car_fleet cf
    WHERE cf.rental_id = ?
    ORDER BY cf.is_active DESC, cf.car_type, cf.brand
");
$stmt->execute([$rentalId]);
$vehicles = $stmt->fetchAll();

// Get recent bookings
$stmt = $db->prepare("
    SELECT 
        b.*,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        cf.brand,
        cf.model,
        cf.license_plate
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    WHERE cf.rental_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$rentalId]);
$recentBookings = $stmt->fetchAll();

// Get maintenance records
$stmt = $db->prepare("
    SELECT 
        cm.*,
        cf.brand,
        cf.model,
        cf.license_plate
    FROM car_maintenance cm
    LEFT JOIN car_fleet cf ON cm.car_id = cf.car_id
    WHERE cf.rental_id = ?
    ORDER BY cm.scheduled_date DESC
    LIMIT 10
");
$stmt->execute([$rentalId]);
$maintenanceRecords = $stmt->fetchAll();

// Get reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.profile_image
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.rental_id = ? AND r.review_type = 'car_rental'
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$rentalId]);
$reviews = $stmt->fetchAll();

// Get vehicle type distribution
$stmt = $db->prepare("
    SELECT 
        car_type,
        COUNT(*) as count,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count
    FROM car_fleet
    WHERE rental_id = ?
    GROUP BY car_type
    ORDER BY count DESC
");
$stmt->execute([$rentalId]);
$vehicleTypes = $stmt->fetchAll();

// Get monthly revenue for chart
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(b.created_at, '%b %Y') as month,
        DATE_FORMAT(b.created_at, '%Y-%m') as month_key,
        COALESCE(SUM(b.total_amount), 0) as revenue,
        COUNT(*) as bookings
    FROM bookings b
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    WHERE cf.rental_id = ? AND b.booking_type = 'car_rental' AND b.status IN ('confirmed', 'completed')
    AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
");
$stmt->execute([$rentalId]);
$monthlyData = $stmt->fetchAll();

$months = [];
$revenues = [];
foreach ($monthlyData as $data) {
    $months[] = $data['month'];
    $revenues[] = $data['revenue'];
}

// Get pickup/dropoff locations
$pickupLocations = $rental['pickup_locations'] ? json_decode($rental['pickup_locations'], true) : [];
$dropoffLocations = $rental['dropoff_locations'] ? json_decode($rental['dropoff_locations'], true) : [];
?>

<style>
/* Car Detail Styles */
.detail-header {
    margin-bottom: 24px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--booking-blue);
    text-decoration: none;
    font-size: 0.75rem;
    margin-bottom: 16px;
}

.back-link:hover {
    text-decoration: underline;
}

/* Enhanced company title section styles */

.company-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
}

.company-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    background: linear-gradient(135deg, var(--booking-text) 0%, var(--booking-blue) 100%);
    background-clip: text;
    -webkit-background-clip: text;
    color: transparent;
    display: inline-block;
}

.company-location {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px;
    color: var(--booking-text-light);
    font-size: 0.875rem;
    margin-bottom: 16px;
}

.company-location i {
    margin-right: 4px;
    color: var(--booking-blue);
    font-size: 0.875rem;
}

.company-location .separator {
    color: var(--booking-border);
    margin: 0 4px;
}

/* Optional: Add a badge for new/featured companies */
.company-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    background: linear-gradient(135deg, #ff8c00, #ff6b00);
    color: white;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
    margin-left: 8px;
    vertical-align: middle;
}

/* Optional: Add a verified badge next to company name */
.verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #e6f4ea;
    color: var(--booking-success);
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
    margin-left: 8px;
    vertical-align: middle;
}

.verified-badge i {
    font-size: 0.625rem;
}

/* Stats Grid */
.detail-stats {
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
}

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

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.info-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.info-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-body {
    padding: 16px;
}

.info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid var(--booking-border);
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    width: 140px;
    font-weight: 600;
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.info-value {
    flex: 1;
    font-size: 0.75rem;
    color: var(--booking-text);
}

/* Vehicles Table */
.vehicles-table {
    width: 100%;
    border-collapse: collapse;
}

.vehicles-table th {
    text-align: left;
    padding: 12px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.vehicles-table td {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.vehicles-table tr:hover td {
    background: var(--booking-gray-light);
}

.vehicle-image {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-sm);
    object-fit: cover;
}

.feature-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.feature-tag {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 2px 6px;
    background: var(--booking-gray-light);
    border-radius: 4px;
    font-size: 0.5625rem;
}

/* Vehicle Status */
.vehicle-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.status-available {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-rented {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-maintenance {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Vehicle Types Grid */
.types-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
}

.type-card {
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    padding: 12px;
    text-align: center;
}

.type-name {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 4px;
    text-transform: capitalize;
}

.type-count {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-blue);
}

.type-active {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

/* Booking List */
.booking-list {
    max-height: 400px;
    overflow-y: auto;
}

.booking-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.booking-item:hover {
    background: var(--booking-gray-light);
}

.booking-info {
    flex: 1;
}

.booking-ref {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.booking-ref a {
    color: var(--booking-text);
    text-decoration: none;
}

.booking-ref a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.booking-guest {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.booking-vehicle {
    font-size: 0.625rem;
    color: var(--booking-text-lighter);
}

.booking-details {
    text-align: right;
}

.booking-date {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.booking-amount {
    font-weight: 700;
    font-size: 0.75rem;
    color: var(--booking-success);
}

/* Maintenance List */
.maintenance-list {
    max-height: 400px;
    overflow-y: auto;
}

.maintenance-item {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.maintenance-item:hover {
    background: var(--booking-gray-light);
}

.maintenance-title {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 4px;
}

.maintenance-details {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.maintenance-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    margin-top: 4px;
}

.status-scheduled {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-in_progress {
    background: #e1f5fe;
    color: #0288d1;
}

.status-completed {
    background: #e6f4ea;
    color: var(--booking-success);
}

/* Review List */
.review-list {
    max-height: 400px;
    overflow-y: auto;
}

.review-item {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.reviewer {
    display: flex;
    align-items: center;
    gap: 8px;
}

.reviewer-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.reviewer-name {
    font-weight: 600;
    font-size: 0.75rem;
}

.review-rating {
    display: flex;
    gap: 2px;
}

.review-rating i {
    font-size: 0.6875rem;
    color: #ffc107;
}

.review-comment {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    line-height: 1.4;
    margin-top: 4px;
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.status-verified {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.status-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--transition-fast);
}

.action-btn.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.action-btn.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* Chart */
.chart-container {
    margin-bottom: 24px;
}

.revenue-chart {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
}

.revenue-chart h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-canvas {
    height: 300px;
}

/* Responsive */
@media (max-width: 1024px) {
    .detail-stats {
        grid-template-columns: repeat(3, 1fr);
    }
    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .detail-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .company-title {
        flex-direction: column;
    }
    .vehicles-table {
        min-width: 800px;
    }
    .info-row {
        flex-direction: column;
        gap: 4px;
    }
    .info-label {
        width: auto;
    }
}
</style>

<div class="detail-header">
    <a href="cars.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Car Rentals
    </a>
    
<div class="company-title">
    <div>
        <h1>
            <?php echo sanitize($rental['company_name']); ?>
            <?php if ($rental['is_verified']): ?>
            <span class="verified-badge">
                <i class="bi bi-shield-check"></i> Verified
            </span>
            <?php endif; ?>
            <?php if (strtotime($rental['created_at']) > strtotime('-30 days')): ?>
            <span class="company-badge">
                <i class="bi bi-stars"></i> New
            </span>
            <?php endif; ?>
        </h1>
        <div class="company-location">
            <i class="bi bi-geo-alt-fill"></i> 
            <span><?php echo sanitize($rental['address'] ?? 'Address not provided'); ?></span>
            <?php if ($rental['location_name']): ?>
            <span class="separator">•</span>
            <span><?php echo sanitize($rental['location_name']); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="action-buttons">
        <a href="edit-car.php?id=<?php echo $rental['rental_id']; ?>" class="action-btn primary">
            <i class="bi bi-pencil"></i> Edit Company
        </a>
        <a href="fleet.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn secondary">
            <i class="bi bi-car-front"></i> Manage Fleet
        </a>
    </div>
</div>
    
    <div style="display: flex; gap: 12px; margin-top: 12px; flex-wrap: wrap;">
        <?php if ($rental['is_verified']): ?>
        <span class="status-badge status-verified">
            <i class="bi bi-shield-check"></i> Verified
        </span>
        <?php else: ?>
        <span class="status-badge status-pending">
            <i class="bi bi-clock"></i> Pending Verification
        </span>
        <?php endif; ?>
        
        <?php if ($rental['is_active']): ?>
        <span class="status-badge status-active">
            <i class="bi bi-check-circle"></i> Active
        </span>
        <?php else: ?>
        <span class="status-badge status-inactive">
            <i class="bi bi-x-circle"></i> Inactive
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Overview -->
<div class="detail-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $rental['total_vehicles']; ?></div>
        <div class="stat-label">Total Vehicles</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $rental['active_vehicles']; ?></div>
        <div class="stat-label">Active Fleet</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $rental['available_vehicles']; ?></div>
        <div class="stat-label">Available Now</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($rental['total_bookings']); ?></div>
        <div class="stat-label">Total Bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($rental['total_revenue']); ?></div>
        <div class="stat-label">Total Revenue</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $rental['avg_rating'] ? number_format($rental['avg_rating'], 1) : 'N/A'; ?></div>
        <div class="stat-label">Rating</div>
    </div>
</div>

<!-- Revenue Chart -->
<?php if (!empty($monthlyData)): ?>
<div class="chart-container">
    <div class="revenue-chart">
        <h3><i class="bi bi-graph-up"></i> Revenue Trend (Last 12 Months)</h3>
        <div class="chart-canvas">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Information Grid -->
<div class="info-grid">
    <!-- Company Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-info-circle"></i> Company Information</h3>
        </div>
        <div class="info-body">
            <?php if ($rental['description']): ?>
            <div class="info-row">
                <div class="info-label">Description</div>
                <div class="info-value"><?php echo nl2br(sanitize($rental['description'])); ?></div>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <div class="info-label">Contact Phone</div>
                <div class="info-value"><?php echo sanitize($rental['phone'] ?? 'Not provided'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Contact Email</div>
                <div class="info-value"><?php echo sanitize($rental['email'] ?? 'Not provided'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Operating Hours</div>
                <div class="info-value"><?php echo sanitize($rental['operating_hours'] ?? 'Not specified'); ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Commission Rate</div>
                <div class="info-value"><?php echo $rental['commission_rate']; ?>%</div>
            </div>
        </div>
    </div>
    
    <!-- Owner Information -->
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-person-badge"></i> Owner Information</h3>
        </div>
        <div class="info-body">
            <div class="info-row">
                <div class="info-label">Name</div>
                <div class="info-value">
                    <a href="users.php?view=<?php echo $rental['owner_id']; ?>" style="color: var(--booking-blue); text-decoration: none;">
                        <?php echo sanitize($rental['owner_first'] . ' ' . $rental['owner_last']); ?>
                    </a>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo sanitize($rental['owner_email']); ?></div>
            </div>
            <?php if ($rental['owner_phone']): ?>
            <div class="info-row">
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo sanitize($rental['owner_phone']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pickup & Dropoff Locations -->
<div class="info-grid">
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-geo-alt"></i> Pickup Locations</h3>
        </div>
        <div class="info-body">
            <?php if (empty($pickupLocations)): ?>
            <p class="text-muted" style="font-size: 0.75rem; color: var(--booking-text-light);">No pickup locations specified</p>
            <?php else: ?>
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($pickupLocations as $location): ?>
                <li style="font-size: 0.75rem; margin-bottom: 4px;"><?php echo sanitize($location); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="info-card">
        <div class="info-header">
            <h3><i class="bi bi-geo-alt"></i> Dropoff Locations</h3>
        </div>
        <div class="info-body">
            <?php if (empty($dropoffLocations)): ?>
            <p class="text-muted" style="font-size: 0.75rem; color: var(--booking-text-light);">Same as pickup locations</p>
            <?php else: ?>
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($dropoffLocations as $location): ?>
                <li style="font-size: 0.75rem; margin-bottom: 4px;"><?php echo sanitize($location); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Vehicle Types Distribution -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-pie-chart"></i> Fleet Distribution by Type</h3>
    </div>
    <div class="info-body">
        <div class="types-grid">
            <?php foreach ($vehicleTypes as $type): ?>
            <div class="type-card">
                <div class="type-name"><?php echo ucfirst($type['car_type']); ?></div>
                <div class="type-count"><?php echo $type['count']; ?></div>
                <div class="type-active"><?php echo $type['active_count']; ?> active</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Vehicles Section -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-car-front"></i> Vehicle Fleet</h3>
        <a href="fleet.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            <i class="bi bi-plus-lg"></i> Manage Fleet
        </a>
    </div>
    <div class="info-body" style="padding: 0; overflow-x: auto;">
        <?php if (empty($vehicles)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-car-front" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No vehicles added yet</p>
            <a href="fleet.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn primary" style="margin-top: 12px; display: inline-block;">
                Add First Vehicle
            </a>
        </div>
        <?php else: ?>
        <table class="vehicles-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>Transmission</th>
                    <th>Seats</th>
                    <th>Daily Rate</th>
                    <th>Features</th>
                    <th>Status</th>
                    <th>Bookings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vehicles as $vehicle): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <?php if ($vehicle['images']): 
                                $images = json_decode($vehicle['images'], true);
                                $firstImage = $images[0] ?? null;
                            ?>
                            <img src="<?php echo getImageUrl($firstImage, 'car'); ?>" class="vehicle-image">
                            <?php else: ?>
                            <div class="vehicle-image" style="background: var(--booking-gray-light); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-car-front" style="font-size: 1.5rem;"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <strong><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></strong>
                                <?php if ($vehicle['license_plate']): ?>
                                <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo $vehicle['license_plate']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td style="text-transform: capitalize;"><?php echo $vehicle['car_type']; ?></td>
                    <td style="text-transform: capitalize;"><?php echo $vehicle['transmission']; ?></td>
                    <td><?php echo $vehicle['seats']; ?> seats</td>
                    <td><strong><?php echo formatPrice($vehicle['daily_rate']); ?></strong><span style="font-size: 0.625rem;"> / day</span></td>
                    <td>
                        <?php if ($vehicle['features']): 
                            $features = json_decode($vehicle['features'], true);
                            if (is_array($features) && !empty($features)):
                        ?>
                        <div class="feature-list">
                            <?php foreach (array_slice($features, 0, 3) as $feature): ?>
                            <span class="feature-tag"><?php echo ucfirst($feature); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($features) > 3): ?>
                            <span class="feature-tag">+<?php echo count($features) - 3; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; endif; ?>
                    </td>
                    <td>
                        <?php
                        $statusClass = '';
                        $statusText = '';
                        switch ($vehicle['status']) {
                            case 'available':
                                $statusClass = 'status-available';
                                $statusText = 'Available';
                                break;
                            case 'rented':
                                $statusClass = 'status-rented';
                                $statusText = 'Rented';
                                break;
                            case 'maintenance':
                                $statusClass = 'status-maintenance';
                                $statusText = 'Maintenance';
                                break;
                            default:
                                $statusClass = 'status-available';
                                $statusText = ucfirst($vehicle['status']);
                        }
                        ?>
                        <span class="vehicle-status <?php echo $statusClass; ?>">
                            <i class="bi bi-<?php echo $vehicle['status'] == 'available' ? 'check-circle' : ($vehicle['status'] == 'rented' ? 'clock' : 'tools'); ?>"></i>
                            <?php echo $statusText; ?>
                        </span>
                        <?php if ($vehicle['pending_maintenance'] > 0): ?>
                        <div style="font-size: 0.5625rem; color: var(--booking-warning); margin-top: 4px;">
                            ⚠️ <?php echo $vehicle['pending_maintenance']; ?> pending maintenance
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format($vehicle['booking_count']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Bookings -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-calendar-check"></i> Recent Bookings</h3>
        <a href="bookings.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($recentBookings)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No bookings yet</p>
        </div>
        <?php else: ?>
        <div class="booking-list">
            <?php foreach ($recentBookings as $booking): ?>
            <div class="booking-item">
                <div class="booking-info">
                    <div class="booking-ref">
                        <a href="booking-detail.php?id=<?php echo $booking['booking_id']; ?>">
                            #<?php echo $booking['booking_reference']; ?>
                        </a>
                    </div>
                    <div class="booking-guest">
                        <i class="bi bi-person"></i> <?php echo sanitize($booking['first_name'] . ' ' . $booking['last_name']); ?>
                    </div>
                    <div class="booking-vehicle">
                        <i class="bi bi-car-front"></i> <?php echo sanitize($booking['brand'] . ' ' . $booking['model']); ?>
                        <?php if ($booking['license_plate']): ?> (<?php echo $booking['license_plate']; ?>)<?php endif; ?>
                    </div>
                </div>
                <div class="booking-details">
                    <div class="booking-date">
                        <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
                    </div>
                    <div class="booking-amount">
                        <?php echo formatPrice($booking['total_amount']); ?>
                    </div>
                    <div class="status-badge status-<?php echo $booking['status']; ?>" style="margin-top: 4px; padding: 2px 6px;">
                        <?php echo ucfirst($booking['status']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Maintenance Records -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-tools"></i> Recent Maintenance Records</h3>
        <a href="maintenance.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($maintenanceRecords)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-tools" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No maintenance records</p>
        </div>
        <?php else: ?>
        <div class="maintenance-list">
            <?php foreach ($maintenanceRecords as $record): ?>
            <div class="maintenance-item">
                <div class="maintenance-title">
                    <?php echo sanitize($record['brand'] . ' ' . $record['model']); ?>
                    <?php if ($record['license_plate']): ?> - <?php echo $record['license_plate']; ?><?php endif; ?>
                </div>
                <div class="maintenance-details">
                    <strong><?php echo ucfirst(str_replace('_', ' ', $record['maintenance_type'])); ?></strong>
                    <span>• Scheduled: <?php echo date('M d, Y', strtotime($record['scheduled_date'])); ?></span>
                    <?php if ($record['estimated_cost']): ?>
                    <span>• Est. Cost: <?php echo formatPrice($record['estimated_cost']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="maintenance-status status-<?php echo $record['status']; ?>">
                    <i class="bi bi-<?php echo $record['status'] == 'completed' ? 'check-circle' : ($record['status'] == 'in_progress' ? 'arrow-repeat' : 'clock'); ?>"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $record['status'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reviews -->
<div class="info-card" style="margin-bottom: 24px;">
    <div class="info-header">
        <h3><i class="bi bi-star"></i> Customer Reviews</h3>
        <a href="reviews.php?rental_id=<?php echo $rental['rental_id']; ?>" class="action-btn secondary" style="padding: 4px 12px; font-size: 0.6875rem;">
            View All →
        </a>
    </div>
    <div class="info-body" style="padding: 0;">
        <?php if (empty($reviews)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-star" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 12px; color: var(--booking-text-light);">No reviews yet</p>
        </div>
        <?php else: ?>
        <div class="review-list">
            <?php foreach ($reviews as $review): ?>
            <div class="review-item">
                <div class="review-header">
                    <div class="reviewer">
                        <div class="reviewer-avatar">
                            <?php if ($review['profile_image']): ?>
                            <img src="<?php echo getImageUrl($review['profile_image'], 'profile'); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                            <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <div class="reviewer-name">
                            <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?>
                        </div>
                    </div>
                    <div class="review-rating">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $review['overall_rating'] ? '' : 'empty'; ?>" style="<?php echo $i <= $review['overall_rating'] ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php if ($review['title']): ?>
                <div style="font-weight: 600; font-size: 0.75rem; margin-bottom: 4px;"><?php echo sanitize($review['title']); ?></div>
                <?php endif; ?>
                <?php if ($review['comment']): ?>
                <div class="review-comment">
                    <?php echo sanitize(substr($review['comment'], 0, 200)); ?>
                    <?php if (strlen($review['comment']) > 200): ?>...<?php endif; ?>
                </div>
                <?php endif; ?>
                <div style="font-size: 0.5625rem; color: var(--booking-text-lighter); margin-top: 8px;">
                    <?php echo timeAgo($review['created_at']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Revenue Chart
<?php if (!empty($monthlyData)): ?>
const ctx = document.getElementById('revenueChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [{
            label: 'Revenue',
            data: <?php echo json_encode($revenues); ?>,
            borderColor: '#003b95',
            backgroundColor: 'rgba(0, 59, 149, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Revenue: ' + formatCurrency(context.parsed.y);
                    }
                }
            }
        },
        scales: {
            y: {
                ticks: {
                    callback: function(value) {
                        return formatCurrency(value);
                    }
                }
            }
        }
    }
});
<?php endif; ?>

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>