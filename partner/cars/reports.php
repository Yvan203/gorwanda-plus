<?php
$pageTitle = 'Reports & Exports';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get vendor profile ID
$stmt = $db->prepare("SELECT vendor_id FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$vendor = $stmt->fetch();
$vendorId = $vendor ? $vendor['vendor_id'] : 0;

// ============================================
// REPORT GENERATION HANDLERS
// ============================================

$reportType = isset($_GET['report']) ? $_GET['report'] : 'financial';
$dateRange = isset($_GET['date_range']) ? $_GET['date_range'] : 'month';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'html';
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;

// Calculate date ranges based on selection
switch($dateRange) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'yesterday':
        $startDate = date('Y-m-d', strtotime('-1 day'));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'week':
        $startDate = date('Y-m-d', strtotime('-7 days'));
        $endDate = date('Y-m-d');
        break;
    case 'month':
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');
        break;
    case 'quarter':
        $startDate = date('Y-m-d', strtotime('-90 days'));
        $endDate = date('Y-m-d');
        break;
    case 'year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-m-d');
        break;
    case 'last_year':
        $startDate = date('Y-m-d', strtotime('first day of January last year'));
        $endDate = date('Y-m-d', strtotime('last day of December last year'));
        break;
    case 'custom':
        // Use provided start/end dates
        break;
}

// Build vehicle filter
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
    SELECT cf.car_id, cf.brand, cf.model, cf.car_type, cf.license_plate, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ?
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// ============================================
// FINANCIAL REPORT DATA
// ============================================
$financialData = [];
$financialSummary = [];

if ($reportType == 'financial' || $reportType == 'all') {
    // Revenue breakdown by day
    $stmt = $db->prepare("
        SELECT 
            DATE(b.pickup_date) as date,
            COUNT(*) as bookings,
            COALESCE(SUM(b.total_amount), 0) as gross_revenue,
            COALESCE(SUM(b.commission_amount), 0) as commission,
            COALESCE(SUM(b.total_amount - b.commission_amount), 0) as net_revenue,
            COALESCE(SUM(b.extra_km_charge), 0) as extra_km_revenue,
            COALESCE(SUM(b.additional_charges), 0) as additional_revenue,
            COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days,
            COALESCE(AVG(b.total_amount), 0) as avg_booking_value
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE b.status IN ('confirmed', 'completed', 'checked_out')
        AND DATE(b.pickup_date) BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
        GROUP BY DATE(b.pickup_date)
        ORDER BY date DESC
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $financialData = $stmt->fetchAll();
    
    // Financial summary
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            COALESCE(SUM(b.total_amount), 0) as gross_revenue,
            COALESCE(SUM(b.commission_amount), 0) as total_commission,
            COALESCE(SUM(b.total_amount - b.commission_amount), 0) as net_revenue,
            COALESCE(SUM(b.extra_km_charge), 0) as total_extra_km,
            COALESCE(SUM(b.additional_charges), 0) as total_additional,
            COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as total_rental_days,
            COALESCE(AVG(b.total_amount), 0) as avg_booking,
            COUNT(DISTINCT b.user_id) as unique_customers,
            COUNT(DISTINCT b.car_id) as vehicles_used
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE b.status IN ('confirmed', 'completed', 'checked_out')
        AND DATE(b.pickup_date) BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $financialSummary = $stmt->fetch();
}

// ============================================
// BOOKINGS REPORT DATA
// ============================================
$bookingsData = [];

if ($reportType == 'bookings' || $reportType == 'all') {
    $stmt = $db->prepare("
        SELECT 
            b.booking_id,
            b.booking_reference,
            b.pickup_date,
            b.return_date,
            b.status,
            b.total_amount,
            b.commission_amount,
            b.extra_km_charge,
            b.additional_charges,
            b.num_guests,
            DATEDIFF(b.return_date, b.pickup_date) as duration,
            cf.brand,
            cf.model,
            cf.car_type,
            cf.license_plate,
            cr.company_name,
            u.first_name,
            u.last_name,
            u.email,
            u.phone
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE b.pickup_date BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
        ORDER BY b.pickup_date DESC
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $bookingsData = $stmt->fetchAll();
}

// ============================================
// VEHICLE PERFORMANCE REPORT DATA
// ============================================
$vehicleReportData = [];

if ($reportType == 'vehicles' || $reportType == 'all') {
    $stmt = $db->prepare("
        SELECT 
            cf.car_id,
            cf.brand,
            cf.model,
            cf.car_type,
            cf.license_plate,
            cf.daily_rate,
            cf.free_km_per_day,
            COUNT(b.booking_id) as bookings_count,
            COALESCE(SUM(b.total_amount), 0) as revenue,
            COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as rental_days,
            COALESCE(AVG(DATEDIFF(b.return_date, b.pickup_date)), 0) as avg_duration,
            COALESCE(SUM(b.extra_km_charge), 0) as extra_km_revenue,
            COALESCE(SUM(b.additional_charges), 0) as additional_revenue,
            COALESCE(SUM(b.total_amount) / NULLIF(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0), cf.daily_rate) as effective_rate,
            COUNT(DISTINCT b.user_id) as unique_customers
        FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN bookings b ON cf.car_id = b.car_id 
            AND b.status IN ('confirmed', 'completed', 'checked_out')
            AND b.pickup_date BETWEEN ? AND ?
        WHERE cr.owner_id = ?
        $vehicleFilter
        GROUP BY cf.car_id
        ORDER BY revenue DESC
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $vehicleReportData = $stmt->fetchAll();
}

// ============================================
// CUSTOMER REPORT DATA
// ============================================
$customerReportData = [];

if ($reportType == 'customers' || $reportType == 'all') {
    $stmt = $db->prepare("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.phone,
            COUNT(b.booking_id) as booking_count,
            COALESCE(SUM(b.total_amount), 0) as total_spent,
            COALESCE(AVG(b.total_amount), 0) as avg_spent,
            COALESCE(SUM(DATEDIFF(b.return_date, b.pickup_date)), 0) as total_days,
            MIN(b.pickup_date) as first_rental,
            MAX(b.pickup_date) as last_rental,
            COUNT(DISTINCT b.car_id) as vehicles_rented
        FROM users u
        JOIN bookings b ON u.user_id = b.user_id
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE b.status IN ('confirmed', 'completed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
        GROUP BY u.user_id
        HAVING booking_count > 0
        ORDER BY total_spent DESC
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $customerReportData = $stmt->fetchAll();
}

// ============================================
// TAX REPORT DATA
// ============================================
$taxData = [];

if ($reportType == 'tax' || $reportType == 'all') {
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(b.pickup_date, '%Y-%m') as month,
            COUNT(*) as bookings,
            COALESCE(SUM(b.total_amount), 0) as gross_revenue,
            COALESCE(SUM(b.commission_amount), 0) as commission,
            COALESCE(SUM(b.total_amount * 0.18), 0) as estimated_vat,
            COALESCE(SUM(b.extra_km_charge), 0) as extra_km,
            COALESCE(SUM(b.additional_charges), 0) as additional
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE b.status IN ('confirmed', 'completed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
        GROUP BY DATE_FORMAT(b.pickup_date, '%Y-%m')
        ORDER BY month DESC
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $taxData = $stmt->fetchAll();
    
    // Tax summary
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(b.total_amount), 0) as total_revenue,
            COALESCE(SUM(b.total_amount * 0.18), 0) as total_vat,
            COALESCE(SUM(b.commission_amount), 0) as total_commission
        FROM bookings b
        JOIN car_fleet cf ON b.car_id = cf.car_id
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE b.status IN ('confirmed', 'completed', 'checked_out')
        AND b.pickup_date BETWEEN ? AND ?
        AND cr.owner_id = ?
        $vehicleFilter
    ");
    $params = array_merge([$startDate, $endDate, $userId], $vehicleParams);
    $stmt->execute($params);
    $taxSummary = $stmt->fetch();
}

// ============================================
// REPORT TOTALS
// ============================================
$totalBookings = 0;
$totalRevenue = 0;
$totalCommission = 0;
$totalExtraKm = 0;
$totalAdditional = 0;

if (!empty($financialData)) {
    foreach ($financialData as $row) {
        $totalBookings += $row['bookings'];
        $totalRevenue += $row['gross_revenue'];
        $totalCommission += $row['commission'];
        $totalExtraKm += $row['extra_km_revenue'];
        $totalAdditional += $row['additional_revenue'];
    }
}

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Report types
$reportTypes = [
    'financial' => 'Financial Report',
    'bookings' => 'Bookings Report',
    'vehicles' => 'Vehicle Performance',
    'customers' => 'Customer Report',
    'tax' => 'Tax Summary',
    'all' => 'Comprehensive Report'
];

// Date range options
$dateRanges = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'quarter' => 'Last 90 Days',
    'year' => 'Year to Date',
    'last_year' => 'Last Year',
    'custom' => 'Custom Range'
];
?>

<style>
/* Reports Specific Styles */
.reports-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.reports-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.reports-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
}

/* Report Controls */
.report-controls {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    padding: 24px;
    margin-bottom: 30px;
    box-shadow: var(--shadow-sm);
}

.controls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: flex-end;
}

.control-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.control-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.control-select, .control-input {
    padding: 10px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

.date-range-group {
    display: flex;
    gap: 8px;
    align-items: center;
}

.date-range-group input {
    flex: 1;
}

.export-buttons {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 20px;
}

.btn-export {
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
}

.btn-pdf {
    background: #dc2626;
    color: white;
}

.btn-pdf:hover {
    background: #b91c1c;
}

.btn-excel {
    background: #059669;
    color: white;
}

.btn-excel:hover {
    background: #047857;
}

.btn-csv {
    background: #0288d1;
    color: white;
}

.btn-csv:hover {
    background: #0277bd;
}

.btn-print {
    background: var(--bg-gray);
    color: var(--text-dark);
    border: 1px solid var(--border-gray);
}

.btn-print:hover {
    background: var(--cars-light);
}

/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--border-gray);
    box-shadow: var(--shadow-sm);
}

.summary-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
    margin-bottom: 8px;
}

.summary-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.summary-sub {
    font-size: 0.75rem;
    color: var(--text-light);
}

.summary-total {
    color: var(--analytics-primary);
}

/* Report Table */
.report-table-container {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    overflow: hidden;
    margin-bottom: 30px;
    box-shadow: var(--shadow-sm);
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.report-table th {
    text-align: left;
    padding: 16px 20px;
    background: var(--bg-gray);
    font-weight: 600;
    color: var(--text-dark);
    font-size: 0.8125rem;
    border-bottom: 2px solid var(--border-gray);
    white-space: nowrap;
}

.report-table td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border-gray);
    vertical-align: middle;
}

.report-table tbody tr:hover td {
    background: var(--cars-light);
}

.report-table tfoot td {
    padding: 16px 20px;
    background: var(--bg-gray);
    font-weight: 700;
    border-top: 2px solid var(--border-gray);
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.amount-positive {
    color: var(--analytics-success);
    font-weight: 600;
}

.amount-negative {
    color: var(--analytics-danger);
    font-weight: 600;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.status-confirmed { background: #e6f4ea; color: var(--analytics-success); }
.status-completed { background: #e6f4ea; color: var(--analytics-success); }
.status-pending { background: #fff4e6; color: var(--analytics-warning); }
.status-cancelled { background: #fce8e8; color: var(--analytics-danger); }
.status-checked_out { background: #e1f5fe; color: #0288d1; }

/* Print Styles */
@media print {
    body * {
        visibility: hidden;
    }
    .report-print-area, .report-print-area * {
        visibility: visible;
    }
    .report-print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
        padding: 20px;
    }
    .no-print {
        display: none !important;
    }
}

/* Responsive */
@media (max-width: 1200px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .summary-grid,
    .controls-grid {
        grid-template-columns: 1fr;
    }
    
    .date-range-group {
        flex-direction: column;
    }
    
    .export-buttons {
        flex-direction: column;
    }
    
    .report-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<div class="reports-header">
    <div class="reports-title">
        <h1>Reports & Exports</h1>
        <p>Generate detailed business reports and export data</p>
    </div>
</div>

<!-- Report Controls -->
<div class="report-controls no-print">
    <form method="GET" action="reports.php" id="reportForm">
        <div class="controls-grid">
            <div class="control-group">
                <label>Report Type</label>
                <select name="report" class="control-select" onchange="this.form.submit()">
                    <?php foreach ($reportTypes as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $reportType == $key ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="control-group">
                <label>Date Range</label>
                <select name="date_range" class="control-select" onchange="toggleCustomDate(this.value); this.form.submit()">
                    <?php foreach ($dateRanges as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $dateRange == $key ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="control-group" id="customDateGroup" style="display: <?php echo $dateRange == 'custom' ? 'flex' : 'none'; ?>; flex-direction: column; grid-column: span 2;">
                <label>Custom Date Range</label>
                <div class="date-range-group">
                    <input type="date" name="start_date" class="control-input" value="<?php echo $startDate; ?>">
                    <span>to</span>
                    <input type="date" name="end_date" class="control-input" value="<?php echo $endDate; ?>">
                </div>
            </div>
            
            <div class="control-group">
                <label>Vehicle (Optional)</label>
                <select name="vehicle" class="control-select">
                    <option value="0">All Vehicles</option>
                    <?php foreach ($vehicles as $v): ?>
                    <option value="<?php echo $v['car_id']; ?>" <?php echo $vehicleId == $v['car_id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="control-group" style="justify-content: flex-end; flex-direction: row; align-items: flex-end;">
                <button type="submit" class="btn-primary">Generate Report</button>
            </div>
        </div>
        
        <div class="export-buttons">
            <button type="button" class="btn-export btn-pdf" onclick="exportReport('pdf')">
                <i class="bi bi-file-pdf"></i> Export PDF
            </button>
            <button type="button" class="btn-export btn-excel" onclick="exportReport('excel')">
                <i class="bi bi-file-excel"></i> Export Excel
            </button>
            <button type="button" class="btn-export btn-csv" onclick="exportReport('csv')">
                <i class="bi bi-filetype-csv"></i> Export CSV
            </button>
            <button type="button" class="btn-export btn-print" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </form>
</div>

<!-- Report Content - Printable Area -->
<div class="report-print-area">
    <!-- Report Header -->
    <div style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid var(--border-gray);">
        <h2 style="font-size: 1.75rem; font-weight: 700; color: var(--cars-primary); margin-bottom: 8px;">
            <?php echo $reportTypes[$reportType]; ?>
        </h2>
        <p style="font-size: 0.9375rem; color: var(--text-light);">
            Period: <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
            <?php if ($vehicleId > 0): ?>
            <br>Vehicle: <?php 
                foreach ($vehicles as $v) {
                    if ($v['car_id'] == $vehicleId) {
                        echo sanitize($v['brand'] . ' ' . $v['model']);
                        break;
                    }
                }
            ?>
            <?php endif; ?>
        </p>
    </div>

    <!-- Summary Cards (for financial report) -->
    <?php if ($reportType == 'financial' && !empty($financialSummary)): ?>
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Gross Revenue</div>
            <div class="summary-value summary-total"><?php echo formatPrice($financialSummary['gross_revenue']); ?></div>
            <div class="summary-sub">Total bookings: <?php echo $financialSummary['total_bookings']; ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Net Revenue</div>
            <div class="summary-value"><?php echo formatPrice($financialSummary['net_revenue']); ?></div>
            <div class="summary-sub">After commission</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Extra Revenue</div>
            <div class="summary-value"><?php echo formatPrice($financialSummary['total_extra_km'] + $financialSummary['total_additional']); ?></div>
            <div class="summary-sub">KM: <?php echo formatPrice($financialSummary['total_extra_km']); ?> + Add: <?php echo formatPrice($financialSummary['total_additional']); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Rental Days</div>
            <div class="summary-value"><?php echo $financialSummary['total_rental_days']; ?></div>
            <div class="summary-sub">Avg: <?php echo round($financialSummary['total_rental_days'] / max(1, $financialSummary['total_bookings']), 1); ?> days</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Financial Report Table -->
    <?php if ($reportType == 'financial'): ?>
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Bookings</th>
                    <th>Rental Days</th>
                    <th class="text-right">Gross Revenue</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Net Revenue</th>
                    <th class="text-right">Extra KM</th>
                    <th class="text-right">Additional</th>
                    <th class="text-right">Avg Booking</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($financialData)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-light);">
                        No data available for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($financialData as $row): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                        <td><?php echo $row['bookings']; ?></td>
                        <td><?php echo $row['rental_days']; ?></td>
                        <td class="text-right amount-positive"><?php echo formatPrice($row['gross_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['commission']); ?></td>
                        <td class="text-right amount-positive"><?php echo formatPrice($row['net_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['extra_km_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['additional_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['avg_booking_value']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($financialData)): ?>
            <tfoot>
                <tr>
                    <td><strong>Totals</strong></td>
                    <td><strong><?php echo $totalBookings; ?></strong></td>
                    <td><strong><?php echo $financialSummary['total_rental_days']; ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalRevenue); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalCommission); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalRevenue - $totalCommission); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalExtraKm); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalAdditional); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice($totalRevenue / max(1, $totalBookings)); ?></strong></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- Bookings Report Table -->
    <?php if ($reportType == 'bookings'): ?>
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Extra KM</th>
                    <th class="text-right">Additional</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookingsData)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: var(--text-light);">
                        No bookings found for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($bookingsData as $booking): ?>
                    <tr>
                        <td><span style="font-family: monospace;">#<?php echo $booking['booking_reference']; ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($booking['pickup_date'])); ?></td>
                        <td>
                            <?php echo sanitize($booking['first_name'] . ' ' . substr($booking['last_name'] ?? '', 0, 1) . '.'); ?>
                            <br><small style="color: var(--text-light);"><?php echo sanitize($booking['email']); ?></small>
                        </td>
                        <td><?php echo sanitize($booking['brand'] . ' ' . $booking['model']); ?></td>
                        <td><?php echo $booking['duration']; ?> days</td>
                        <td>
                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td class="text-right"><?php echo formatPrice($booking['total_amount'] - $booking['extra_km_charge'] - $booking['additional_charges']); ?></td>
                        <td class="text-right"><?php echo formatPrice($booking['extra_km_charge']); ?></td>
                        <td class="text-right"><?php echo formatPrice($booking['additional_charges']); ?></td>
                        <td class="text-right amount-positive"><strong><?php echo formatPrice($booking['total_amount']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Vehicle Performance Report -->
    <?php if ($reportType == 'vehicles'): ?>
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>License Plate</th>
                    <th>Bookings</th>
                    <th>Rental Days</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Avg/Day</th>
                    <th class="text-right">Extra KM</th>
                    <th class="text-right">Additional</th>
                    <th>Customers</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vehicleReportData)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: var(--text-light);">
                        No vehicle data available for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($vehicleReportData as $vehicle): ?>
                    <tr>
                        <td><strong><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></strong></td>
                        <td><?php echo ucfirst($vehicle['car_type']); ?></td>
                        <td><?php echo $vehicle['license_plate'] ?: 'N/A'; ?></td>
                        <td><?php echo $vehicle['bookings_count']; ?></td>
                        <td><?php echo $vehicle['rental_days']; ?></td>
                        <td class="text-right amount-positive"><?php echo formatPrice($vehicle['revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($vehicle['effective_rate']); ?></td>
                        <td class="text-right"><?php echo formatPrice($vehicle['extra_km_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($vehicle['additional_revenue']); ?></td>
                        <td><?php echo $vehicle['unique_customers']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Customer Report -->
    <?php if ($reportType == 'customers'): ?>
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Bookings</th>
                    <th>Total Days</th>
                    <th class="text-right">Total Spent</th>
                    <th class="text-right">Avg/Booking</th>
                    <th>Vehicles</th>
                    <th>First Rental</th>
                    <th>Last Rental</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customerReportData)): ?>
                <tr>
                    <td colspan="10" style="text-align: center; padding: 40px; color: var(--text-light);">
                        No customer data available for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($customerReportData as $customer): ?>
                    <tr>
                        <td><strong><?php echo sanitize($customer['first_name'] . ' ' . $customer['last_name']); ?></strong></td>
                        <td><?php echo sanitize($customer['email']); ?></td>
                        <td><?php echo sanitize($customer['phone'] ?: 'N/A'); ?></td>
                        <td><?php echo $customer['booking_count']; ?></td>
                        <td><?php echo $customer['total_days']; ?></td>
                        <td class="text-right amount-positive"><?php echo formatPrice($customer['total_spent']); ?></td>
                        <td class="text-right"><?php echo formatPrice($customer['avg_spent']); ?></td>
                        <td><?php echo $customer['vehicles_rented']; ?></td>
                        <td><?php echo date('M d, Y', strtotime($customer['first_rental'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($customer['last_rental'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Tax Report -->
    <?php if ($reportType == 'tax'): ?>
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total Revenue</div>
            <div class="summary-value"><?php echo formatPrice($taxSummary['total_revenue']); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Estimated VAT (18%)</div>
            <div class="summary-value"><?php echo formatPrice($taxSummary['total_vat']); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Commission Paid</div>
            <div class="summary-value"><?php echo formatPrice($taxSummary['total_commission']); ?></div>
        </div>
        
        <div class="summary-card">
            <div class="summary-label">Net After VAT</div>
            <div class="summary-value"><?php echo formatPrice($taxSummary['total_revenue'] - $taxSummary['total_vat']); ?></div>
        </div>
    </div>
    
    <div class="report-table-container">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Bookings</th>
                    <th class="text-right">Gross Revenue</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Estimated VAT</th>
                    <th class="text-right">Extra KM</th>
                    <th class="text-right">Additional</th>
                    <th class="text-right">Net Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($taxData)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-light);">
                        No tax data available for the selected period
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($taxData as $row): ?>
                    <tr>
                        <td><strong><?php echo $row['month']; ?></strong></td>
                        <td><?php echo $row['bookings']; ?></td>
                        <td class="text-right"><?php echo formatPrice($row['gross_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['commission']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['estimated_vat']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['extra_km']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['additional']); ?></td>
                        <td class="text-right amount-positive"><?php echo formatPrice($row['gross_revenue'] - $row['commission'] - $row['estimated_vat']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Totals</strong></td>
                    <td><strong><?php echo array_sum(array_column($taxData, 'bookings')); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'gross_revenue'))); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'commission'))); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'estimated_vat'))); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'extra_km'))); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'additional'))); ?></strong></td>
                    <td class="text-right"><strong><?php echo formatPrice(array_sum(array_column($taxData, 'gross_revenue')) - array_sum(array_column($taxData, 'commission')) - array_sum(array_column($taxData, 'estimated_vat'))); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Comprehensive Report - All Sections -->
    <?php if ($reportType == 'all'): ?>
        <!-- Financial Summary -->
        <h3 style="font-size: 1.25rem; margin: 30px 0 20px;">Financial Summary</h3>
        <?php if (!empty($financialData)): ?>
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bookings</th>
                        <th>Days</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Commission</th>
                        <th class="text-right">Net</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($financialData, 0, 10) as $row): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                        <td><?php echo $row['bookings']; ?></td>
                        <td><?php echo $row['rental_days']; ?></td>
                        <td class="text-right"><?php echo formatPrice($row['gross_revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['commission']); ?></td>
                        <td class="text-right"><?php echo formatPrice($row['net_revenue']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Top Vehicles -->
        <h3 style="font-size: 1.25rem; margin: 30px 0 20px;">Top Performing Vehicles</h3>
        <?php if (!empty($vehicleReportData)): ?>
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Vehicle</th>
                        <th>Bookings</th>
                        <th>Days</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Avg/Day</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($vehicleReportData, 0, 5) as $vehicle): ?>
                    <tr>
                        <td><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></td>
                        <td><?php echo $vehicle['bookings_count']; ?></td>
                        <td><?php echo $vehicle['rental_days']; ?></td>
                        <td class="text-right"><?php echo formatPrice($vehicle['revenue']); ?></td>
                        <td class="text-right"><?php echo formatPrice($vehicle['effective_rate']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Top Customers -->
        <h3 style="font-size: 1.25rem; margin: 30px 0 20px;">Top Customers</h3>
        <?php if (!empty($customerReportData)): ?>
        <div class="report-table-container">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Bookings</th>
                        <th>Days</th>
                        <th class="text-right">Total Spent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($customerReportData, 0, 5) as $customer): ?>
                    <tr>
                        <td><?php echo sanitize($customer['first_name'] . ' ' . $customer['last_name']); ?></td>
                        <td><?php echo $customer['booking_count']; ?></td>
                        <td><?php echo $customer['total_days']; ?></td>
                        <td class="text-right"><?php echo formatPrice($customer['total_spent']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Report Footer -->
    <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border-gray); color: var(--text-light); font-size: 0.75rem;">
        <p>Generated on <?php echo date('F d, Y \a\t h:i A'); ?> | GoRwanda+ Car Rentals</p>
        <p>This report is for internal use only</p>
    </div>
</div>

<script>
// ============================================
// REPORT FUNCTIONS
// ============================================
function toggleCustomDate(selected) {
    const customGroup = document.getElementById('customDateGroup');
    if (customGroup) {
        customGroup.style.display = selected === 'custom' ? 'flex' : 'none';
    }
}

function exportReport(format) {
    const form = document.getElementById('reportForm');
    const action = form.action;
    
    // Add format parameter
    const url = new URL(action);
    url.searchParams.set('format', format);
    
    // Get all current form values
    url.searchParams.set('report', document.querySelector('select[name="report"]').value);
    url.searchParams.set('date_range', document.querySelector('select[name="date_range"]').value);
    url.searchParams.set('start_date', document.querySelector('input[name="start_date"]').value);
    url.searchParams.set('end_date', document.querySelector('input[name="end_date"]').value);
    url.searchParams.set('vehicle', document.querySelector('select[name="vehicle"]').value);
    
    // Redirect to export
    window.location.href = url.toString();
}

// Auto-submit when custom dates are entered
document.querySelectorAll('input[name="start_date"], input[name="end_date"]').forEach(input => {
    input.addEventListener('change', function() {
        if (document.querySelector('select[name="date_range"]').value === 'custom') {
            document.getElementById('reportForm').submit();
        }
    });
});
</script>

<?php
// ============================================
// HANDLE EXPORTS (CSV, Excel, PDF)
// ============================================
if (isset($_GET['format'])) {
    $format = $_GET['format'];
    $filename = 'report_' . $reportType . '_' . date('Ymd') . '.' . ($format == 'excel' ? 'xls' : $format);
    
    // Set headers for download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel
    if ($format == 'csv') {
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    }
    
    // Generate CSV based on report type
    switch ($reportType) {
        case 'financial':
            fputcsv($output, ['Date', 'Bookings', 'Rental Days', 'Gross Revenue', 'Commission', 'Net Revenue', 'Extra KM', 'Additional', 'Avg Booking']);
            foreach ($financialData as $row) {
                fputcsv($output, [
                    $row['date'],
                    $row['bookings'],
                    $row['rental_days'],
                    $row['gross_revenue'],
                    $row['commission'],
                    $row['net_revenue'],
                    $row['extra_km_revenue'],
                    $row['additional_revenue'],
                    $row['avg_booking_value']
                ]);
            }
            break;
            
        case 'bookings':
            fputcsv($output, ['Reference', 'Date', 'Customer', 'Email', 'Vehicle', 'Duration', 'Status', 'Base Amount', 'Extra KM', 'Additional', 'Total']);
            foreach ($bookingsData as $row) {
                fputcsv($output, [
                    $row['booking_reference'],
                    $row['pickup_date'],
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['email'],
                    $row['brand'] . ' ' . $row['model'],
                    $row['duration'],
                    $row['status'],
                    $row['total_amount'] - $row['extra_km_charge'] - $row['additional_charges'],
                    $row['extra_km_charge'],
                    $row['additional_charges'],
                    $row['total_amount']
                ]);
            }
            break;
            
        case 'vehicles':
            fputcsv($output, ['Vehicle', 'Type', 'License Plate', 'Bookings', 'Rental Days', 'Revenue', 'Avg/Day', 'Extra KM', 'Additional', 'Customers']);
            foreach ($vehicleReportData as $row) {
                fputcsv($output, [
                    $row['brand'] . ' ' . $row['model'],
                    $row['car_type'],
                    $row['license_plate'],
                    $row['bookings_count'],
                    $row['rental_days'],
                    $row['revenue'],
                    $row['effective_rate'],
                    $row['extra_km_revenue'],
                    $row['additional_revenue'],
                    $row['unique_customers']
                ]);
            }
            break;
            
        case 'customers':
            fputcsv($output, ['Customer', 'Email', 'Phone', 'Bookings', 'Total Days', 'Total Spent', 'Avg/Booking', 'Vehicles', 'First Rental', 'Last Rental']);
            foreach ($customerReportData as $row) {
                fputcsv($output, [
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['email'],
                    $row['phone'],
                    $row['booking_count'],
                    $row['total_days'],
                    $row['total_spent'],
                    $row['avg_spent'],
                    $row['vehicles_rented'],
                    $row['first_rental'],
                    $row['last_rental']
                ]);
            }
            break;
            
        case 'tax':
            fputcsv($output, ['Month', 'Bookings', 'Gross Revenue', 'Commission', 'Estimated VAT', 'Extra KM', 'Additional', 'Net Revenue']);
            foreach ($taxData as $row) {
                fputcsv($output, [
                    $row['month'],
                    $row['bookings'],
                    $row['gross_revenue'],
                    $row['commission'],
                    $row['estimated_vat'],
                    $row['extra_km'],
                    $row['additional'],
                    $row['gross_revenue'] - $row['commission'] - $row['estimated_vat']
                ]);
            }
            break;
    }
    
    fclose($output);
    exit;
}

require_once 'includes/cars_footer.php';
?>