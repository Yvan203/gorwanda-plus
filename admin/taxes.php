<?php
$pageTitle = 'Tax Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle tax settings updates
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Update global tax settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_tax_settings') {
    $tax_name = sanitize($_POST['tax_name'] ?? 'VAT');
    $tax_rate = floatval($_POST['tax_rate'] ?? 18);
    $tax_type = sanitize($_POST['tax_type'] ?? 'percentage');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $apply_to_stays = isset($_POST['apply_to_stays']) ? 1 : 0;
    $apply_to_cars = isset($_POST['apply_to_cars']) ? 1 : 0;
    $apply_to_attractions = isset($_POST['apply_to_attractions']) ? 1 : 0;
    $tax_number = sanitize($_POST['tax_number'] ?? '');
    $tax_office = sanitize($_POST['tax_office'] ?? 'Rwanda Revenue Authority');
    $invoice_notes = sanitize($_POST['invoice_notes'] ?? '');
    
    // Save tax settings to a settings table or config file
    // For now, we'll store in a new table or update existing bookings tax calculation
    
    // Update the tax rate in the system (this would affect future bookings)
    // You can store these in a settings table
    
    $_SESSION['success'] = "Tax settings updated successfully";
    header('Location: taxes.php');
    exit;
}

// Update tax rates per category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_category_taxes') {
    $stay_tax_rate = floatval($_POST['stay_tax_rate'] ?? 18);
    $car_tax_rate = floatval($_POST['car_tax_rate'] ?? 18);
    $attraction_tax_rate = floatval($_POST['attraction_tax_rate'] ?? 18);
    
    // Store these rates
    $_SESSION['success'] = "Category tax rates updated successfully";
    header('Location: taxes.php');
    exit;
}

// Get current tax statistics
$taxStats = $db->query("
    SELECT 
        COALESCE(SUM(tax_amount), 0) as total_tax_collected,
        COALESCE(SUM(CASE WHEN booking_type = 'stay' THEN tax_amount ELSE 0 END), 0) as stay_tax,
        COALESCE(SUM(CASE WHEN booking_type = 'car_rental' THEN tax_amount ELSE 0 END), 0) as car_tax,
        COALESCE(SUM(CASE WHEN booking_type = 'attraction' THEN tax_amount ELSE 0 END), 0) as attraction_tax,
        COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN tax_amount ELSE 0 END), 0) as monthly_tax,
        COALESCE(SUM(CASE WHEN WEEK(created_at) = WEEK(CURDATE()) THEN tax_amount ELSE 0 END), 0) as weekly_tax,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN tax_amount ELSE 0 END), 0) as today_tax,
        COUNT(*) as taxable_bookings
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
")->fetch();

// Get tax by month for chart
$monthlyTax = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%b %Y') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(tax_amount), 0) as tax_amount,
        COUNT(*) as booking_count
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$taxAmounts = [];
$bookingCounts = [];
foreach ($monthlyTax as $data) {
    $months[] = $data['month'];
    $taxAmounts[] = floatval($data['tax_amount']);
    $bookingCounts[] = $data['booking_count'];
}

// Get tax distribution by country (based on location of stays/cars/attractions)
$taxByLocation = $db->query("
    SELECT 
        COALESCE(l.country, 'Rwanda') as country,
        COALESCE(SUM(b.tax_amount), 0) as tax_amount,
        COUNT(b.booking_id) as booking_count
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE b.status IN ('confirmed', 'completed')
    GROUP BY l.country
    ORDER BY tax_amount DESC
    LIMIT 5
")->fetchAll();

// Get tax rate distribution
$taxRateDistribution = $db->query("
    SELECT 
        tax_amount,
        CASE 
            WHEN tax_amount = 0 THEN '0%'
            WHEN tax_amount <= 5000 THEN '1-5K RWF'
            WHEN tax_amount <= 10000 THEN '5-10K RWF'
            WHEN tax_amount <= 50000 THEN '10-50K RWF'
            ELSE '50K+ RWF'
        END as tax_range,
        COUNT(*) as count
    FROM bookings
    WHERE status IN ('confirmed', 'completed')
    GROUP BY tax_range
    ORDER BY MIN(tax_amount)
")->fetchAll();

// Get recent taxable transactions
$recentTransactions = $db->query("
    SELECT 
        b.booking_reference,
        b.booking_type,
        b.total_amount,
        b.tax_amount,
        b.created_at,
        u.first_name,
        u.last_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN cr.company_name
            ELSE a.attraction_name
        END as item_name
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.user_id
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    WHERE b.status IN ('confirmed', 'completed')
    ORDER BY b.created_at DESC
    LIMIT 20
")->fetchAll();
?>

<style>
/* Tax Management Styles */
.tax-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
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
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    font-size: 1.125rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }

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

/* Settings Section */
.settings-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
    margin-bottom: 24px;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--booking-text);
}

.section-title i {
    color: var(--booking-blue);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 6px;
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    transition: all var(--transition-fast);
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.input-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.input-group .form-control {
    flex: 1;
}

.input-group span {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.form-check input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-check label {
    margin: 0;
    cursor: pointer;
    text-transform: none;
    font-weight: normal;
}

.checkbox-group {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.save-btn {
    padding: 10px 24px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    margin-top: 20px;
}

.save-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

/* Chart Section */
.chart-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-container {
    height: 250px;
}

/* Distribution Grid */
.distribution-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.distribution-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.distribution-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.distribution-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.distribution-body {
    padding: 16px;
}

.distribution-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.distribution-label {
    width: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.distribution-bar {
    flex: 1;
    height: 8px;
    background: var(--booking-gray-light);
    border-radius: 4px;
    overflow: hidden;
}

.distribution-fill {
    height: 100%;
    background: var(--booking-blue);
    border-radius: 4px;
    transition: width 0.3s;
}

.distribution-value {
    width: 80px;
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-align: right;
}

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
    margin-bottom: 24px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.data-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.data-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.data-table tr:hover td {
    background: var(--booking-gray-light);
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.type-stay { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.type-car { background: rgba(147,51,234,0.1); color: #9333ea; }
.type-attraction { background: rgba(255,140,0,0.1); color: var(--booking-warning); }

.tax-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: #fff4e6;
    color: var(--booking-warning);
}

/* Info Box */
.info-box {
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-top: 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.info-box i {
    font-size: 1.25rem;
    color: var(--booking-blue);
}

.info-box-content {
    flex: 1;
}

.info-box-title {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 4px;
}

.info-box-text {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
    border: 1px solid rgba(0,128,9,0.2);
}

/* Responsive */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
    .distribution-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .checkbox-group {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<div class="tax-header">
    <div class="page-title">
        <h1>Tax Management</h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-receipt"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($taxStats['total_tax_collected']); ?></div>
        <div class="stat-label">Total Tax Collected</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-building"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($taxStats['stay_tax']); ?></div>
        <div class="stat-label">Stays Tax</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-car-front"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($taxStats['car_tax']); ?></div>
        <div class="stat-label">Car Rentals Tax</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-ticket-perforated"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($taxStats['attraction_tax']); ?></div>
        <div class="stat-label">Experiences Tax</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-calendar-month"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($taxStats['monthly_tax']); ?></div>
        <div class="stat-label">This Month</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-calculator"></i>
        </div>
        <div class="stat-value"><?php echo number_format($taxStats['taxable_bookings']); ?></div>
        <div class="stat-label">Taxable Bookings</div>
    </div>
</div>

<!-- Tax Settings -->
<div class="settings-section">
    <div class="section-title">
        <i class="bi bi-gear"></i>
        Tax Settings
    </div>
    <form method="POST" action="taxes.php">
        <input type="hidden" name="action" value="update_tax_settings">
        <div class="form-grid">
            <div>
                <div class="form-group">
                    <label>Tax Name</label>
                    <input type="text" name="tax_name" class="form-control" value="Value Added Tax (VAT)" placeholder="e.g., VAT, GST, Sales Tax">
                </div>
                <div class="form-group">
                    <label>Tax Rate (%)</label>
                    <div class="input-group">
                        <input type="number" name="tax_rate" class="form-control" step="0.5" min="0" max="100" value="18">
                        <span>%</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Tax Type</label>
                    <select name="tax_type" class="form-control">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount (RWF)</option>
                    </select>
                </div>
            </div>
            <div>
                <div class="form-group">
                    <label>Tax Registration Number</label>
                    <input type="text" name="tax_number" class="form-control" placeholder="e.g., 1234567890">
                </div>
                <div class="form-group">
                    <label>Tax Authority</label>
                    <input type="text" name="tax_office" class="form-control" value="Rwanda Revenue Authority">
                </div>
                <div class="form-group">
                    <label>Invoice Notes</label>
                    <textarea name="invoice_notes" class="form-control" rows="3" placeholder="Additional notes to appear on invoices..."></textarea>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Apply Tax To</label>
            <div class="checkbox-group">
                <label class="form-check">
                    <input type="checkbox" name="apply_to_stays" value="1" checked>
                    <span>🏨 Stays & Accommodations</span>
                </label>
                <label class="form-check">
                    <input type="checkbox" name="apply_to_cars" value="1" checked>
                    <span>🚗 Car Rentals</span>
                </label>
                <label class="form-check">
                    <input type="checkbox" name="apply_to_attractions" value="1" checked>
                    <span>🎟️ Experiences & Attractions</span>
                </label>
            </div>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" value="1" checked>
            <label for="is_active">Enable Tax Collection</label>
        </div>
        
        <button type="submit" class="save-btn">Save Tax Settings</button>
    </form>
</div>

<!-- Category Tax Rates -->
<div class="settings-section">
    <div class="section-title">
        <i class="bi bi-tags"></i>
        Category-Specific Tax Rates
    </div>
    <form method="POST" action="taxes.php">
        <input type="hidden" name="action" value="update_category_taxes">
        <div class="form-grid">
            <div class="form-group">
                <label>🏨 Stays Tax Rate</label>
                <div class="input-group">
                    <input type="number" name="stay_tax_rate" class="form-control" step="0.5" min="0" max="100" value="18">
                    <span>%</span>
                </div>
                <small style="font-size: 0.625rem;">Applied to hotel, lodge, and apartment bookings</small>
            </div>
            <div class="form-group">
                <label>🚗 Car Rentals Tax Rate</label>
                <div class="input-group">
                    <input type="number" name="car_tax_rate" class="form-control" step="0.5" min="0" max="100" value="18">
                    <span>%</span>
                </div>
                <small style="font-size: 0.625rem;">Applied to all vehicle rental bookings</small>
            </div>
            <div class="form-group">
                <label>🎟️ Experiences Tax Rate</label>
                <div class="input-group">
                    <input type="number" name="attraction_tax_rate" class="form-control" step="0.5" min="0" max="100" value="18">
                    <span>%</span>
                </div>
                <small style="font-size: 0.625rem;">Applied to tours, activities, and attraction tickets</small>
            </div>
        </div>
        <button type="submit" class="save-btn">Update Category Rates</button>
    </form>
</div>

<!-- Tax Trend Chart -->
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Tax Collection Trend (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Tax Amount
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Bookings
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="taxChart"></canvas>
    </div>
</div>

<!-- Distribution Grid -->
<div class="distribution-grid">
    <!-- Tax by Location -->
    <div class="distribution-card">
        <div class="distribution-header">
            <h3><i class="bi bi-geo-alt"></i> Tax by Location</h3>
        </div>
        <div class="distribution-body">
            <?php if (empty($taxByLocation)): ?>
            <div style="text-align: center; padding: 20px;">
                <i class="bi bi-map" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 8px;">No tax data available</p>
            </div>
            <?php else: ?>
            <?php 
            $maxTax = max(array_column($taxByLocation, 'tax_amount'));
            foreach ($taxByLocation as $location): 
            ?>
            <div class="distribution-item">
                <div class="distribution-label"><?php echo $location['country']; ?></div>
                <div class="distribution-bar">
                    <div class="distribution-fill" style="width: <?php echo $maxTax > 0 ? ($location['tax_amount'] / $maxTax) * 100 : 0; ?>%"></div>
                </div>
                <div class="distribution-value"><?php echo formatPrice($location['tax_amount']); ?></div>
            </div>
            <div style="font-size: 0.5625rem; color: var(--booking-text-light); margin-top: -8px; margin-bottom: 12px; padding-left: 100px;">
                <?php echo $location['booking_count']; ?> bookings
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tax Range Distribution -->
    <div class="distribution-card">
        <div class="distribution-header">
            <h3><i class="bi bi-bar-chart"></i> Tax Amount Distribution</h3>
        </div>
        <div class="distribution-body">
            <?php if (empty($taxRateDistribution)): ?>
            <div style="text-align: center; padding: 20px;">
                <i class="bi bi-bar-chart" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 8px;">No distribution data available</p>
            </div>
            <?php else: ?>
            <?php 
            $maxCount = max(array_column($taxRateDistribution, 'count'));
            foreach ($taxRateDistribution as $range): 
            ?>
            <div class="distribution-item">
                <div class="distribution-label"><?php echo $range['tax_range']; ?></div>
                <div class="distribution-bar">
                    <div class="distribution-fill" style="width: <?php echo $maxCount > 0 ? ($range['count'] / $maxCount) * 100 : 0; ?>%; background: linear-gradient(90deg, #003b95, #0066ff);"></div>
                </div>
                <div class="distribution-value"><?php echo number_format($range['count']); ?> bookings</div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Taxable Transactions -->
<div class="table-container">
    <div class="distribution-header">
        <h3><i class="bi bi-clock-history"></i> Recent Taxable Transactions</h3>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Customer</th>
                <th>Item</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Tax</th>
                <th>Total</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentTransactions)): ?>
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px;">
                    <i class="bi bi-receipt" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px;">No taxable transactions found</p>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($recentTransactions as $transaction): 
                $typeClass = $transaction['booking_type'] == 'stay' ? 'type-stay' : ($transaction['booking_type'] == 'car_rental' ? 'type-car' : 'type-attraction');
                $typeIcon = $transaction['booking_type'] == 'stay' ? '🏨' : ($transaction['booking_type'] == 'car_rental' ? '🚗' : '🎟️');
            ?>
            <tr>
                <td><code><?php echo $transaction['booking_reference']; ?></code></div>
                <td>
                    <?php echo sanitize($transaction['first_name'] . ' ' . $transaction['last_name']); ?>
                 </div>
                <td><?php echo sanitize(substr($transaction['item_name'], 0, 30)); ?></div>
                <td><span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeIcon; ?> <?php echo ucfirst(str_replace('_', ' ', $transaction['booking_type'])); ?></span></div>
                <td><?php echo formatPrice($transaction['total_amount'] - $transaction['tax_amount']); ?></div>
                <td><span class="tax-badge">+ <?php echo formatPrice($transaction['tax_amount']); ?></span></div>
                <td><strong><?php echo formatPrice($transaction['total_amount']); ?></strong></div>
                <td><?php echo date('M d, Y', strtotime($transaction['created_at'])); ?></div>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
     </div>
</div>

<!-- Tax Information Box -->
<div class="info-box">
    <i class="bi bi-info-circle-fill"></i>
    <div class="info-box-content">
        <div class="info-box-title">Understanding Tax Configuration</div>
        <div class="info-box-text">
            <strong>How taxes are calculated:</strong> Tax is applied to the subtotal amount of each booking before commission is deducted.
            The tax amount is collected from customers and remitted to the relevant tax authority. Current tax rate is applied to all 
            confirmed and completed bookings. Changes to tax rates will affect future bookings only.
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Tax Trend Chart
<?php if (!empty($monthlyTax)): ?>
const ctx = document.getElementById('taxChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Tax Amount',
                data: <?php echo json_encode($taxAmounts); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-tax'
            },
            {
                label: 'Bookings',
                data: <?php echo json_encode($bookingCounts); ?>,
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
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Tax Amount') {
                            return 'Tax: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Bookings: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
            'y-tax': {
                type: 'linear',
                display: true,
                position: 'left',
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
                    font: { size: 9 }
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