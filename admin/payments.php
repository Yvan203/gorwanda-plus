<?php
$pageTitle = 'Payments Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle payment actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$payoutId = isset($_POST['payout_id']) ? intval($_POST['payout_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Process payout
if ($action === 'process_payout' && $payoutId > 0) {
    $payment_method = sanitize($_POST['payment_method'] ?? 'bank_transfer');
    $payment_reference = sanitize($_POST['payment_reference'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    
    $stmt = $db->prepare("
        UPDATE payouts SET 
            status = 'processed',
            payment_method = ?,
            payment_reference = ?,
            notes = ?,
            processed_at = NOW()
        WHERE payout_id = ?
    ");
    $stmt->execute([$payment_method, $payment_reference, $notes, $payoutId]);
    $_SESSION['success'] = "Payout processed successfully";
    header('Location: payments.php');
    exit;
}

// Mark payout as paid
if ($action === 'mark_paid' && $payoutId > 0) {
    $stmt = $db->prepare("
        UPDATE payouts SET 
            status = 'paid',
            processed_at = NOW()
        WHERE payout_id = ?
    ");
    $stmt->execute([$payoutId]);
    $_SESSION['success'] = "Payout marked as paid";
    header('Location: payments.php');
    exit;
}

// Generate payouts
if ($action === 'generate_payouts' && isset($_POST['generate'])) {
    $period_start = $_POST['period_start'];
    $period_end = $_POST['period_end'];
    
    // Get all vendors with confirmed/completed bookings in period
    // Join with vendor_profiles to get the correct vendor_id
    $stmt = $db->prepare("
        SELECT 
            vp.vendor_id,
            vp.user_id,
            COALESCE(SUM(b.total_amount), 0) as total_revenue,
            COALESCE(SUM(b.commission_amount), 0) as total_commission,
            COALESCE(SUM(b.total_amount - b.commission_amount), 0) as net_amount,
            COUNT(DISTINCT b.booking_id) as booking_count
        FROM bookings b
        LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        LEFT JOIN stays s ON sr.stay_id = s.stay_id
        LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
        LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
        LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
        INNER JOIN users u ON (s.owner_id = u.user_id OR cr.owner_id = u.user_id OR a.owner_id = u.user_id)
        INNER JOIN vendor_profiles vp ON u.user_id = vp.user_id
        WHERE b.status IN ('confirmed', 'completed')
        AND DATE(b.created_at) BETWEEN ? AND ?
        GROUP BY vp.vendor_id, vp.user_id
    ");
    $stmt->execute([$period_start, $period_end]);
    $vendors = $stmt->fetchAll();
    
    $generated = 0;
    $errors = [];
    
    foreach ($vendors as $vendor) {
        if ($vendor['vendor_id'] && $vendor['net_amount'] > 0) {
            // Check if payout already exists for this period and vendor
            $checkStmt = $db->prepare("
                SELECT COUNT(*) FROM payouts 
                WHERE vendor_id = ? AND period_start = ? AND period_end = ?
            ");
            $checkStmt->execute([$vendor['vendor_id'], $period_start, $period_end]);
            $exists = $checkStmt->fetchColumn();
            
            if (!$exists) {
                $stmt = $db->prepare("
                    INSERT INTO payouts (
                        vendor_id, 
                        amount, 
                        commission_amount, 
                        net_amount, 
                        period_start, 
                        period_end, 
                        status,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $result = $stmt->execute([
                    $vendor['vendor_id'],
                    $vendor['total_revenue'],
                    $vendor['total_commission'],
                    $vendor['net_amount'],
                    $period_start,
                    $period_end
                ]);
                
                if ($result) {
                    $generated++;
                } else {
                    $errors[] = "Failed to generate payout for vendor ID: " . $vendor['vendor_id'];
                }
            }
        }
    }
    
    if ($generated > 0) {
        $_SESSION['success'] = "$generated payouts generated for period $period_start to $period_end";
    } else {
        $_SESSION['error'] = "No new payouts generated. " . (!empty($errors) ? implode(", ", $errors) : "No eligible bookings found for the selected period.");
    }
    header('Location: payments.php');
    exit;
}

// Get filter parameters
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query for payouts
$sql = "
    SELECT 
        p.*,
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        vp.business_name,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN stays s ON b.stay_room_id = s.stay_id
         LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
         LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
         LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
         LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
         WHERE (s.owner_id = u.user_id OR cr.owner_id = u.user_id OR a.owner_id = u.user_id)
         AND b.status IN ('confirmed', 'completed')
         AND DATE(b.created_at) BETWEEN p.period_start AND p.period_end) as booking_count
    FROM payouts p
    LEFT JOIN vendor_profiles vp ON p.vendor_id = vp.vendor_id
    LEFT JOIN users u ON vp.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($status !== 'all') {
    $sql .= " AND p.status = ?";
    $params[] = $status;
}

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR vp.business_name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($dateFrom) {
    $sql .= " AND DATE(p.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(p.created_at) <= ?";
    $params[] = $dateTo;
}

// Sorting
switch ($sort) {
    case 'amount_desc':
        $sql .= " ORDER BY p.amount DESC";
        break;
    case 'amount_asc':
        $sql .= " ORDER BY p.amount ASC";
        break;
    case 'vendor_asc':
        $sql .= " ORDER BY vp.business_name ASC, u.first_name ASC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY p.created_at ASC";
        break;
    default:
        $sql .= " ORDER BY p.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payouts = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM payouts p
    LEFT JOIN vendor_profiles vp ON p.vendor_id = vp.vendor_id
    LEFT JOIN users u ON vp.user_id = u.user_id
    WHERE 1=1
";
$countParams = [];

if ($status !== 'all') {
    $countSql .= " AND p.status = ?";
    $countParams[] = $status;
}
if ($search) {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR vp.business_name LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($dateFrom) {
    $countSql .= " AND DATE(p.created_at) >= ?";
    $countParams[] = $dateFrom;
}
if ($dateTo) {
    $countSql .= " AND DATE(p.created_at) <= ?";
    $countParams[] = $dateTo;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalPayouts = $stmt->fetchColumn() ?: 0;
$totalPages = $totalPayouts > 0 ? ceil($totalPayouts / $perPage) : 1;

// Get statistics with null handling using COALESCE
$stats = $db->query("
    SELECT 
        COALESCE(COUNT(*), 0) as total_payouts,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending,
        COALESCE(SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END), 0) as processed,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed,
        COALESCE(SUM(amount), 0) as total_amount,
        COALESCE(SUM(commission_amount), 0) as total_commission,
        COALESCE(SUM(net_amount), 0) as total_net,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) as paid_amount
    FROM payouts
")->fetch();

// Get monthly revenue for chart
$monthlyRevenue = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%b %Y') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(amount), 0) as revenue,
        COALESCE(SUM(commission_amount), 0) as commission,
        COALESCE(SUM(net_amount), 0) as net
    FROM payouts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$revenues = [];
$commissions = [];
$nets = [];
foreach ($monthlyRevenue as $data) {
    $months[] = $data['month'];
    $revenues[] = floatval($data['revenue']);
    $commissions[] = floatval($data['commission']);
    $nets[] = floatval($data['net']);
}

// Status colors and icons
$statusConfig = [
    'pending' => ['bg' => '#fff4e6', 'color' => '#ff8c00', 'icon' => 'clock', 'label' => 'Pending'],
    'processed' => ['bg' => '#e1f5fe', 'color' => '#0288d1', 'icon' => 'arrow-repeat', 'label' => 'Processing'],
    'paid' => ['bg' => '#e6f4ea', 'color' => '#008009', 'icon' => 'check-circle', 'label' => 'Paid'],
    'failed' => ['bg' => '#fce8e8', 'color' => '#e21111', 'icon' => 'exclamation-triangle', 'label' => 'Failed']
];
?>

<style>
/* Payments Management Styles */
.payments-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px;
    text-align: center;
    transition: all var(--transition-fast);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    font-size: 0.875rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }
.stat-icon.cyan { background: rgba(23,162,184,0.1); color: #17a2b8; }

.stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 2px;
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
    height: 300px;
}

/* Generate Payout Section */
.generate-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.generate-form {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.generate-group {
    flex: 1;
    min-width: 150px;
}

.generate-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.generate-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.generate-btn {
    padding: 8px 24px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.generate-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

/* Filter Section */
.filter-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 130px;
}

.filter-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 20px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.filter-btn:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.reset-btn:hover {
    background: var(--booking-gray-dark);
}

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
}

.payments-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

.payments-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.payments-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.payments-table tr:hover td {
    background: var(--booking-gray-light);
}

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

/* Action Buttons */
.action-btn {
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.action-btn.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.action-btn.success {
    background: var(--booking-success);
    color: var(--booking-white);
}

.action-btn.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 500px;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--booking-text-light);
}

.modal-close:hover {
    color: var(--booking-danger);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    color: var(--booking-text);
    text-decoration: none;
    font-size: 0.75rem;
    transition: all var(--transition-fast);
}

.page-link:hover,
.page-link.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
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

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .filter-row {
        flex-direction: column;
    }
    .filter-group {
        width: 100%;
    }
    .generate-form {
        flex-direction: column;
    }
    .generate-group {
        width: 100%;
    }
}
</style>

<div class="payments-header">
    <div class="page-title">
        <h1></h1>
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

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <div>
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_payouts'] ?? 0); ?></div>
        <div class="stat-label">Total Payouts</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['pending'] ?? 0); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-arrow-repeat"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['processed'] ?? 0); ?></div>
        <div class="stat-label">Processing</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['paid'] ?? 0); ?></div>
        <div class="stat-label">Paid</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-cash"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_amount'] ?? 0); ?></div>
        <div class="stat-label">Total Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-percent"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_commission'] ?? 0); ?></div>
        <div class="stat-label">Commission</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-wallet2"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['pending_amount'] ?? 0); ?></div>
        <div class="stat-label">Pending Payout</div>
    </div>
</div>

<!-- Revenue Chart -->
<?php if (!empty($monthlyRevenue)): ?>
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Payout Trends (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Revenue
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Commission
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: #ff8c00; border-radius: 2px;"></span> Net Payout
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="payoutChart"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Generate Payouts Section -->
<div class="generate-section">
    <form method="POST" action="payments.php" class="generate-form" onsubmit="return confirm('Generate payouts for selected period?')">
        <input type="hidden" name="action" value="generate_payouts">
        <input type="hidden" name="generate" value="1">
        <div class="generate-group">
            <label>Period Start</label>
            <input type="date" name="period_start" required>
        </div>
        <div class="generate-group">
            <label>Period End</label>
            <input type="date" name="period_end" required>
        </div>
        <button type="submit" class="generate-btn">
            <i class="bi bi-plus-circle"></i> Generate Payouts
        </button>
    </form>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="payments.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Partner name, business, email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="processed" <?php echo $status == 'processed' ? 'selected' : ''; ?>>Processing</option>
                    <option value="paid" <?php echo $status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="failed" <?php echo $status == 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="amount_desc" <?php echo $sort == 'amount_desc' ? 'selected' : ''; ?>>Highest Amount</option>
                    <option value="amount_asc" <?php echo $sort == 'amount_asc' ? 'selected' : ''; ?>>Lowest Amount</option>
                    <option value="vendor_asc" <?php echo $sort == 'vendor_asc' ? 'selected' : ''; ?>>Vendor A-Z</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="payments.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Payouts Table -->
<div class="table-container">
    <table class="payments-table">
        <thead>
            <tr>
                <th>Payout ID</th>
                <th>Partner</th>
                <th>Period</th>
                <th>Bookings</th>
                <th>Total Revenue</th>
                <th>Commission</th>
                <th>Net Payout</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($payouts)): ?>
            <tr>
                <td colspan="10" style="text-align: center; padding: 60px;">
                    <i class="bi bi-wallet2" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px; color: var(--booking-text-light);">No payouts found</p>
                    <button class="generate-btn" onclick="document.querySelector('.generate-section').scrollIntoView({behavior: 'smooth'})" style="margin-top: 12px;">
                        Generate First Payout
                    </button>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($payouts as $payout): 
                $statusInfo = $statusConfig[$payout['status']] ?? $statusConfig['pending'];
                $bookingCount = $payout['booking_count'] ?? 0;
                $displayName = !empty($payout['business_name']) ? $payout['business_name'] : ($payout['first_name'] . ' ' . $payout['last_name']);
            ?>
            <tr>
                <td><code>#<?php echo str_pad($payout['payout_id'], 6, '0', STR_PAD_LEFT); ?></code></td>
                <td>
                    <strong><?php echo sanitize($displayName); ?></strong>
                    <?php if ($payout['email']): ?>
                    <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo sanitize($payout['email']); ?></div>
                    <?php endif; ?>
                 </div>
                </td>
                <td style="font-size: 0.6875rem;">
                    <?php echo date('M d', strtotime($payout['period_start'])); ?> - <?php echo date('M d, Y', strtotime($payout['period_end'])); ?>
                </div>
                </td>
                <td><?php echo number_format($bookingCount); ?></div>
                <tr>
                <td style="font-weight: 600;"><?php echo formatPrice($payout['amount'] ?? 0); ?></div>
                </td>
                <td style="color: var(--booking-text-light);"><?php echo formatPrice($payout['commission_amount'] ?? 0); ?></div>
                </td>
                <td style="font-weight: 700; color: var(--booking-success);"><?php echo formatPrice($payout['net_amount'] ?? 0); ?></div>
                </div>
                <td>
                    <span class="status-badge" style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>;">
                        <i class="bi bi-<?php echo $statusInfo['icon']; ?>"></i>
                        <?php echo $statusInfo['label']; ?>
                    </span>
                 </div>
                </td>
                <td style="font-size: 0.6875rem;"><?php echo date('M d, Y', strtotime($payout['created_at'])); ?></div>
                </td>
                <td>
                    <?php if ($payout['status'] == 'pending'): ?>
                    <button class="action-btn primary" onclick="openProcessModal(<?php echo $payout['payout_id']; ?>)">
                        <i class="bi bi-check-lg"></i> Process
                    </button>
                    <?php elseif ($payout['status'] == 'processed'): ?>
                    <button class="action-btn success" onclick="markAsPaid(<?php echo $payout['payout_id']; ?>)">
                        <i class="bi bi-cash-stack"></i> Mark Paid
                    </button>
                    <?php endif; ?>
                    <button class="action-btn secondary" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($payout)); ?>)">
                        <i class="bi bi-eye"></i> View
                    </button>
                 </div>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
     </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
        <i class="bi bi-chevron-left"></i>
    </a>
    <?php endif; ?>
    
    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++):
    ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Process Payout Modal -->
<div id="processModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="payments.php">
            <input type="hidden" name="action" value="process_payout">
            <input type="hidden" name="payout_id" id="processPayoutId" value="0">
            <div class="modal-header">
                <h3>Process Payout</h3>
                <button type="button" class="modal-close" onclick="closeProcessModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="mobile_money">Mobile Money</option>
                        <option value="paypal">PayPal</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Transaction Reference</label>
                    <input type="text" name="payment_reference" class="form-control" placeholder="e.g., TRX-123456">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn secondary" onclick="closeProcessModal()">Cancel</button>
                <button type="submit" class="action-btn primary">Process Payout</button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Payout Details</h3>
            <button type="button" class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- Dynamic content -->
        </div>
        <div class="modal-footer">
            <button type="button" class="action-btn secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Payout Chart
<?php if (!empty($monthlyRevenue)): ?>
const ctx = document.getElementById('payoutChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Revenue',
                data: <?php echo json_encode($revenues); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            },
            {
                label: 'Commission',
                data: <?php echo json_encode($commissions); ?>,
                borderColor: '#008009',
                backgroundColor: 'rgba(0, 128, 9, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            },
            {
                label: 'Net Payout',
                data: <?php echo json_encode($nets); ?>,
                borderColor: '#ff8c00',
                backgroundColor: 'rgba(255, 140, 0, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
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
                        return context.dataset.label + ': ' + formatCurrency(context.parsed.y);
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

// Process Modal
function openProcessModal(payoutId) {
    document.getElementById('processPayoutId').value = payoutId;
    document.getElementById('processModal').style.display = 'flex';
}

function closeProcessModal() {
    document.getElementById('processModal').style.display = 'none';
}

// Mark as Paid
function markAsPaid(payoutId) {
    if (confirm('Mark this payout as paid? This action cannot be undone.')) {
        window.location.href = `payments.php?action=mark_paid&id=${payoutId}`;
    }
}

// View Details
function viewDetails(payout) {
    const modalBody = document.getElementById('viewModalBody');
    modalBody.innerHTML = `
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Payout ID</div>
            <div><code>#${String(payout.payout_id).padStart(6, '0')}</code></div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Partner</div>
            <div><strong>${payout.business_name || payout.first_name + ' ' + payout.last_name || ''}</strong><br><small>${payout.email || ''}</small></div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Period</div>
            <div>${new Date(payout.period_start).toLocaleDateString()} - ${new Date(payout.period_end).toLocaleDateString()}</div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Bookings</div>
            <div>${payout.booking_count || 0} bookings</div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Total Revenue</div>
            <div>${formatCurrency(payout.amount || 0)}</div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Commission</div>
            <div>${formatCurrency(payout.commission_amount || 0)}</div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Net Payout</div>
            <div><strong style="color: #008009;">${formatCurrency(payout.net_amount || 0)}</strong></div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 120px; font-weight: 600;">Status</div>
            <div>${payout.status ? payout.status.charAt(0).toUpperCase() + payout.status.slice(1) : 'Unknown'}</div>
        </div>
        <div class="info-row" style="display: flex; padding: 8px 0;">
            <div style="width: 120px; font-weight: 600;">Created</div>
            <div>${new Date(payout.created_at).toLocaleString()}</div>
        </div>
        ${payout.processed_at ? `
        <div class="info-row" style="display: flex; padding: 8px 0; border-top: 1px solid #e7e7e7; margin-top: 8px; padding-top: 8px;">
            <div style="width: 120px; font-weight: 600;">Processed</div>
            <div>${new Date(payout.processed_at).toLocaleString()}</div>
        </div>
        ` : ''}
        ${payout.payment_method ? `
        <div class="info-row" style="display: flex; padding: 8px 0;">
            <div style="width: 120px; font-weight: 600;">Payment Method</div>
            <div>${payout.payment_method.replace('_', ' ').toUpperCase()}</div>
        </div>
        ` : ''}
        ${payout.payment_reference ? `
        <div class="info-row" style="display: flex; padding: 8px 0;">
            <div style="width: 120px; font-weight: 600;">Reference</div>
            <div><code>${payout.payment_reference}</code></div>
        </div>
        ` : ''}
        ${payout.notes ? `
        <div class="info-row" style="display: flex; padding: 8px 0;">
            <div style="width: 120px; font-weight: 600;">Notes</div>
            <div>${payout.notes}</div>
        </div>
        ` : ''}
    `;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProcessModal();
        closeViewModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const processModal = document.getElementById('processModal');
    const viewModal = document.getElementById('viewModal');
    if (e.target === processModal) closeProcessModal();
    if (e.target === viewModal) closeViewModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>