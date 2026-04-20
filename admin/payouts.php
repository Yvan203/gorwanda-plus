<?php
$pageTitle = 'Payouts Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle payout actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$payoutId = isset($_POST['payout_id']) ? intval($_POST['payout_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Process payout (mark as processing)
if ($action === 'process' && $payoutId > 0) {
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
        WHERE payout_id = ? AND status = 'pending'
    ");
    $stmt->execute([$payment_method, $payment_reference, $notes, $payoutId]);
    $_SESSION['success'] = "Payout marked as processing";
    header('Location: payouts.php');
    exit;
}

// Mark payout as paid
if ($action === 'mark_paid' && $payoutId > 0) {
    $stmt = $db->prepare("
        UPDATE payouts SET 
            status = 'paid',
            paid_at = NOW()
        WHERE payout_id = ? AND status = 'processed'
    ");
    $stmt->execute([$payoutId]);
    $_SESSION['success'] = "Payout marked as paid";
    header('Location: payouts.php');
    exit;
}

// Mark payout as failed
if ($action === 'mark_failed' && $payoutId > 0) {
    $failure_reason = sanitize($_POST['failure_reason'] ?? 'Payment failed');
    $stmt = $db->prepare("
        UPDATE payouts SET 
            status = 'failed',
            notes = CONCAT(COALESCE(notes, ''), ' | Failed: ', ?)
        WHERE payout_id = ?
    ");
    $stmt->execute([$failure_reason, $payoutId]);
    $_SESSION['error'] = "Payout marked as failed";
    header('Location: payouts.php');
    exit;
}

// Generate payouts for a period
if ($action === 'generate' && isset($_POST['generate'])) {
    $period_start = $_POST['period_start'];
    $period_end = $_POST['period_end'];
    
    // Get all vendors with eligible bookings
    $stmt = $db->prepare("
        SELECT 
            vp.vendor_id,
            vp.user_id,
            u.first_name,
            u.last_name,
            u.email,
            vp.business_name,
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
        HAVING net_amount > 0
    ");
    $stmt->execute([$period_start, $period_end]);
    $vendors = $stmt->fetchAll();
    
    $generated = 0;
    $skipped = 0;
    
    foreach ($vendors as $vendor) {
        // Check if payout already exists for this period
        $checkStmt = $db->prepare("
            SELECT COUNT(*) FROM payouts 
            WHERE vendor_id = ? AND period_start = ? AND period_end = ?
        ");
        $checkStmt->execute([$vendor['vendor_id'], $period_start, $period_end]);
        $exists = $checkStmt->fetchColumn();
        
        if (!$exists) {
            $stmt = $db->prepare("
                INSERT INTO payouts (
                    vendor_id, amount, commission_amount, net_amount, 
                    period_start, period_end, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $vendor['vendor_id'],
                $vendor['total_revenue'],
                $vendor['total_commission'],
                $vendor['net_amount'],
                $period_start,
                $period_end
            ]);
            $generated++;
        } else {
            $skipped++;
        }
    }
    
    $_SESSION['success'] = "$generated payouts generated for period $period_start to $period_end" . ($skipped > 0 ? " ($skipped already existed)" : "");
    header('Location: payouts.php');
    exit;
}

// Bulk process payouts
if ($action === 'bulk_process' && isset($_POST['selected_payouts']) && is_array($_POST['selected_payouts'])) {
    $selectedIds = array_map('intval', $_POST['selected_payouts']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $payment_method = sanitize($_POST['bulk_payment_method'] ?? 'bank_transfer');
    
    $stmt = $db->prepare("
        UPDATE payouts SET 
            status = 'processed',
            payment_method = ?,
            processed_at = NOW()
        WHERE payout_id IN ($placeholders) AND status = 'pending'
    ");
    $stmt->execute(array_merge([$payment_method], $selectedIds));
    $_SESSION['success'] = count($selectedIds) . " payouts marked as processing";
    header('Location: payouts.php');
    exit;
}

// Get filter parameters
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$minAmount = isset($_GET['min_amount']) ? floatval($_GET['min_amount']) : 0;
$maxAmount = isset($_GET['max_amount']) ? floatval($_GET['max_amount']) : 0;
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
        vp.business_phone,
        vp.business_email,
        (SELECT COUNT(*) FROM bookings b 
         LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
         LEFT JOIN stays s ON sr.stay_id = s.stay_id
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

if ($minAmount > 0) {
    $sql .= " AND p.net_amount >= ?";
    $params[] = $minAmount;
}

if ($maxAmount > 0) {
    $sql .= " AND p.net_amount <= ?";
    $params[] = $maxAmount;
}

// Sorting
switch ($sort) {
    case 'amount_desc':
        $sql .= " ORDER BY p.net_amount DESC";
        break;
    case 'amount_asc':
        $sql .= " ORDER BY p.net_amount ASC";
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

// Get total count
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
if ($minAmount > 0) {
    $countSql .= " AND p.net_amount >= ?";
    $countParams[] = $minAmount;
}
if ($maxAmount > 0) {
    $countSql .= " AND p.net_amount <= ?";
    $countParams[] = $maxAmount;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalPayouts = $stmt->fetchColumn() ?: 0;
$totalPages = $totalPayouts > 0 ? ceil($totalPayouts / $perPage) : 1;

// Get statistics - removed booking_count from this query
$stats = $db->query("
    SELECT 
        COALESCE(COUNT(*), 0) as total_payouts,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending,
        COALESCE(SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END), 0) as processing,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) as failed,
        COALESCE(SUM(amount), 0) as total_amount,
        COALESCE(SUM(commission_amount), 0) as total_commission,
        COALESCE(SUM(net_amount), 0) as total_net,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN status = 'processed' THEN net_amount ELSE 0 END), 0) as processing_amount,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) as paid_amount,
        COALESCE(AVG(net_amount), 0) as avg_payout
    FROM payouts
")->fetch();

// Get monthly payout trend
$monthlyTrend = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%b %Y') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COALESCE(SUM(net_amount), 0) as payout_amount,
        COUNT(*) as payout_count
    FROM payouts
    WHERE status IN ('paid', 'processed')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$payoutAmounts = [];
$payoutCounts = [];
foreach ($monthlyTrend as $data) {
    $months[] = $data['month'];
    $payoutAmounts[] = floatval($data['payout_amount']);
    $payoutCounts[] = $data['payout_count'];
}

// Status colors and icons
$statusConfig = [
    'pending' => ['bg' => '#fff4e6', 'color' => '#ff8c00', 'icon' => 'clock', 'label' => 'Pending', 'badge' => 'warning'],
    'processed' => ['bg' => '#e1f5fe', 'color' => '#0288d1', 'icon' => 'arrow-repeat', 'label' => 'Processing', 'badge' => 'info'],
    'paid' => ['bg' => '#e6f4ea', 'color' => '#008009', 'icon' => 'check-circle', 'label' => 'Paid', 'badge' => 'success'],
    'failed' => ['bg' => '#fce8e8', 'color' => '#e21111', 'icon' => 'exclamation-triangle', 'label' => 'Failed', 'badge' => 'danger']
];
?>

<style>
/* Payouts Management Styles */
.payouts-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
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
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-card.active {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
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
.stat-icon.red { background: rgba(226,17,17,0.1); color: var(--booking-danger); }

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

/* Generate Section */
.generate-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.generate-title {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
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

.generate-group input,
.generate-group select {
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
    min-width: 120px;
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
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

/* Table Styles */
.table-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
}

.payouts-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}

.payouts-table th {
    text-align: left;
    padding: 14px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.payouts-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.payouts-table tr:hover td {
    background: var(--booking-gray-light);
}

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

.action-btn.danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
}

.action-btn.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn:hover {
    transform: translateY(-1px);
}

/* Bulk Actions */
.bulk-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
    padding: 12px 16px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    display: none;
}

.bulk-actions.show {
    display: flex;
    flex-wrap: wrap;
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
}

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Responsive */
@media (max-width: 1400px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
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
    .bulk-actions {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<div class="payouts-header">
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
    <div class="stat-card" onclick="filterByStatus('all')">
        <div class="stat-icon blue">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_payouts']); ?></div>
        <div class="stat-label">Total Payouts</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('pending')">
        <div class="stat-icon orange">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('processed')">
        <div class="stat-icon cyan">
            <i class="bi bi-arrow-repeat"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['processing']); ?></div>
        <div class="stat-label">Processing</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('paid')">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['paid']); ?></div>
        <div class="stat-label">Paid</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-wallet2"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['pending_amount']); ?></div>
        <div class="stat-label">Pending Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-cash"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['paid_amount']); ?></div>
        <div class="stat-label">Paid Amount</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-calculator"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['avg_payout']); ?></div>
        <div class="stat-label">Average Payout</div>
    </div>
</div>

<!-- Payout Trend Chart -->
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Payout Trends (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Payout Amount
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Number of Payouts
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<!-- Generate Payouts Section -->
<div class="generate-section">
    <div class="generate-title">
        <i class="bi bi-plus-circle"></i> Generate New Payouts
    </div>
    <form method="POST" action="payouts.php" class="generate-form">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="generate" value="1">
        <div class="generate-group">
            <label>Period Start</label>
            <input type="date" name="period_start" required>
        </div>
        <div class="generate-group">
            <label>Period End</label>
            <input type="date" name="period_end" required>
        </div>
        <button type="submit" class="generate-btn" onclick="return confirm('Generate payouts for selected period? This will create payout records for all eligible vendors.')">
            <i class="bi bi-plus-lg"></i> Generate Payouts
        </button>
    </form>
    <div style="margin-top: 12px; padding: 8px; background: var(--booking-gray-light); border-radius: var(--radius-sm);">
        <i class="bi bi-info-circle"></i>
        <span style="font-size: 0.625rem;">Payouts are calculated based on completed bookings. Net amount = Total Revenue - Platform Commission.</span>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="payouts.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Partner name, business..." value="<?php echo htmlspecialchars($search); ?>">
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
                <label>Min Amount</label>
                <input type="number" name="min_amount" placeholder="Min RWF" value="<?php echo $minAmount ?: ''; ?>" step="1000">
            </div>
            <div class="filter-group">
                <label>Max Amount</label>
                <input type="number" name="max_amount" placeholder="Max RWF" value="<?php echo $maxAmount ?: ''; ?>" step="1000">
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
                <a href="payouts.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions -->
<form method="POST" action="payouts.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_process">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_payment_method" class="bulk-action-select" style="padding: 6px 12px; border: 1px solid var(--booking-border); border-radius: var(--radius-sm);">
            <option value="bank_transfer">Bank Transfer</option>
            <option value="mobile_money">Mobile Money</option>
            <option value="paypal">PayPal</option>
        </select>
        <button type="submit" class="filter-btn" onclick="return confirm('Process selected payouts? This will mark them as processing.')">Process Selected</button>
        <button type="button" class="filter-btn reset-btn" onclick="clearSelection()">Clear</button>
    </div>
</form>

<!-- Payouts Table -->
<div class="table-container">
    <table class="payouts-table">
        <thead>
            <tr>
                <th style="width: 30px;"><input type="checkbox" id="selectAllHeader"></th>
                <th>ID</th>
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
                <td colspan="11" style="text-align: center; padding: 60px;">
                    <i class="bi bi-wallet2" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                    <p style="margin-top: 12px;">No payouts found</p>
                    <button class="generate-btn" onclick="document.querySelector('.generate-section').scrollIntoView({behavior: 'smooth'})" style="margin-top: 12px;">
                        Generate First Payout
                    </button>
                 </div>
                </tr>
            <?php else: ?>
            <?php foreach ($payouts as $payout): 
                $statusInfo = $statusConfig[$payout['status']] ?? $statusConfig['pending'];
                $displayName = !empty($payout['business_name']) ? $payout['business_name'] : ($payout['first_name'] . ' ' . $payout['last_name']);
            ?>
            <tr data-payout-id="<?php echo $payout['payout_id']; ?>">
                <td><input type="checkbox" class="payout-checkbox" value="<?php echo $payout['payout_id']; ?>" <?php echo $payout['status'] != 'pending' ? 'disabled' : ''; ?>></div>
                <td><code>#<?php echo str_pad($payout['payout_id'], 6, '0', STR_PAD_LEFT); ?></code></div>
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
                <td><?php echo number_format($payout['booking_count'] ?? 0); ?></div>
                <td><?php echo formatPrice($payout['amount'] ?? 0); ?></div>
                <td><?php echo formatPrice($payout['commission_amount'] ?? 0); ?></div>
                <td style="font-weight: 700; color: var(--booking-success);"><?php echo formatPrice($payout['net_amount'] ?? 0); ?></div>
                <td>
                    <span class="status-badge" style="background: <?php echo $statusInfo['bg']; ?>; color: <?php echo $statusInfo['color']; ?>;">
                        <i class="bi bi-<?php echo $statusInfo['icon']; ?>"></i>
                        <?php echo $statusInfo['label']; ?>
                    </span>
                 </div>
                <td><?php echo date('M d, Y', strtotime($payout['created_at'])); ?></div>
                <td>
                    <?php if ($payout['status'] == 'pending'): ?>
                    <button class="action-btn primary" onclick="openProcessModal(<?php echo $payout['payout_id']; ?>, '<?php echo addslashes($displayName); ?>', <?php echo $payout['net_amount']; ?>)">
                        <i class="bi bi-check-lg"></i> Process
                    </button>
                    <?php elseif ($payout['status'] == 'processed'): ?>
                    <button class="action-btn success" onclick="markAsPaid(<?php echo $payout['payout_id']; ?>)">
                        <i class="bi bi-cash-stack"></i> Mark Paid
                    </button>
                    <button class="action-btn danger" onclick="markAsFailed(<?php echo $payout['payout_id']; ?>, '<?php echo addslashes($displayName); ?>')">
                        <i class="bi bi-x-circle"></i> Failed
                    </button>
                    <?php endif; ?>
                    <button class="action-btn secondary" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($payout)); ?>)">
                        <i class="bi bi-eye"></i>
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
        <form method="POST" action="payouts.php">
            <input type="hidden" name="action" value="process">
            <input type="hidden" name="payout_id" id="processPayoutId" value="0">
            <div class="modal-header">
                <h3>Process Payout</h3>
                <button type="button" class="modal-close" onclick="closeProcessModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Partner</label>
                    <input type="text" id="processPartnerName" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Amount to Pay</label>
                    <input type="text" id="processAmount" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="mobile_money">Mobile Money (MTN, Airtel)</option>
                        <option value="paypal">PayPal</option>
                        <option value="cash">Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Transaction Reference</label>
                    <input type="text" name="payment_reference" class="form-control" placeholder="e.g., TRX-123456789">
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

<!-- Mark as Failed Modal -->
<div id="failedModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="payouts.php">
            <input type="hidden" name="action" value="mark_failed">
            <input type="hidden" name="payout_id" id="failedPayoutId" value="0">
            <div class="modal-header">
                <h3>Mark Payout as Failed</h3>
                <button type="button" class="modal-close" onclick="closeFailedModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Partner</label>
                    <input type="text" id="failedPartnerName" class="form-control" disabled>
                </div>
                <div class="form-group">
                    <label>Failure Reason</label>
                    <textarea name="failure_reason" class="form-control" rows="3" placeholder="Why did this payout fail?" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn secondary" onclick="closeFailedModal()">Cancel</button>
                <button type="submit" class="action-btn danger">Mark as Failed</button>
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
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button type="button" class="action-btn secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Payout Trend Chart
<?php if (!empty($monthlyTrend)): ?>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Payout Amount',
                data: <?php echo json_encode($payoutAmounts); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-amount'
            },
            {
                label: 'Number of Payouts',
                data: <?php echo json_encode($payoutCounts); ?>,
                borderColor: '#008009',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                tension: 0.4,
                yAxisID: 'y-count'
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
                        if (context.dataset.label === 'Payout Amount') {
                            return 'Amount: ' + formatCurrency(context.parsed.y);
                        }
                        return 'Payouts: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
            'y-amount': {
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
            'y-count': {
                type: 'linear',
                display: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { stepSize: 1, font: { size: 9 } }
            }
        }
    }
});
<?php endif; ?>

function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Process Modal
function openProcessModal(payoutId, partnerName, amount) {
    document.getElementById('processPayoutId').value = payoutId;
    document.getElementById('processPartnerName').value = partnerName;
    document.getElementById('processAmount').value = formatCurrency(amount);
    document.getElementById('processModal').style.display = 'flex';
}

function closeProcessModal() {
    document.getElementById('processModal').style.display = 'none';
}

// Failed Modal
function markAsFailed(payoutId, partnerName) {
    document.getElementById('failedPayoutId').value = payoutId;
    document.getElementById('failedPartnerName').value = partnerName;
    document.getElementById('failedModal').style.display = 'flex';
}

function closeFailedModal() {
    document.getElementById('failedModal').style.display = 'none';
}

// Mark as Paid
function markAsPaid(payoutId) {
    if (confirm('Mark this payout as paid? This action cannot be undone.')) {
        window.location.href = `payouts.php?action=mark_paid&id=${payoutId}`;
    }
}

// View Details
function viewDetails(payout) {
    const modalBody = document.getElementById('viewModalBody');
    const statusInfo = <?php echo json_encode($statusConfig); ?>[payout.status] || <?php echo json_encode($statusConfig['pending']); ?>;
    
    modalBody.innerHTML = `
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Payout ID</div>
            <div><code>#${String(payout.payout_id).padStart(6, '0')}</code></div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Partner</div>
            <div><strong>${payout.business_name || payout.first_name + ' ' + payout.last_name || ''}</strong><br><small>${payout.email || ''}</small></div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Period</div>
            <div>${new Date(payout.period_start).toLocaleDateString()} - ${new Date(payout.period_end).toLocaleDateString()}</div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Bookings</div>
            <div>${payout.booking_count || 0} bookings</div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Total Revenue</div>
            <div>${formatCurrency(payout.amount || 0)}</div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Platform Commission</div>
            <div>${formatCurrency(payout.commission_amount || 0)} (${payout.amount > 0 ? Math.round((payout.commission_amount / payout.amount) * 100) : 0}%)</div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Net Payout</div>
            <div><strong style="color: #008009;">${formatCurrency(payout.net_amount || 0)}</strong></div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Status</div>
            <div><span class="status-badge" style="background: ${statusInfo.bg}; color: ${statusInfo.color};"><i class="bi bi-${statusInfo.icon}"></i> ${statusInfo.label}</span></div>
        </div>
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Created</div>
            <div>${new Date(payout.created_at).toLocaleString()}</div>
        </div>
        ${payout.processed_at ? `
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Processed</div>
            <div>${new Date(payout.processed_at).toLocaleString()}</div>
        </div>
        ` : ''}
        ${payout.paid_at ? `
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Paid</div>
            <div>${new Date(payout.paid_at).toLocaleString()}</div>
        </div>
        ` : ''}
        ${payout.payment_method ? `
        <div class="detail-row" style="display: flex; padding: 8px 0; border-bottom: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Payment Method</div>
            <div>${payout.payment_method.replace('_', ' ').toUpperCase()}</div>
        </div>
        ` : ''}
        ${payout.payment_reference ? `
        <div class="detail-row" style="display: flex; padding: 8px 0;">
            <div style="width: 130px; font-weight: 600;">Reference</div>
            <div><code>${payout.payment_reference}</code></div>
        </div>
        ` : ''}
        ${payout.notes ? `
        <div class="detail-row" style="display: flex; padding: 8px 0; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e7e7e7;">
            <div style="width: 130px; font-weight: 600;">Notes</div>
            <div>${payout.notes}</div>
        </div>
        ` : ''}
    `;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

// Bulk selection
let selectedPayouts = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.payout-checkbox:checked');
    selectedPayouts.clear();
    checkboxes.forEach(cb => selectedPayouts.add(cb.value));
    
    const count = selectedPayouts.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    const allCheckboxes = document.querySelectorAll('.payout-checkbox:not([disabled])');
    const selectAll = document.getElementById('selectAll');
    const selectAllHeader = document.getElementById('selectAllHeader');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
        if (selectAllHeader) selectAllHeader.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
        if (selectAllHeader) selectAllHeader.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.payout-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

document.querySelectorAll('.payout-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.payout-checkbox:not([disabled])').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

const selectAllHeader = document.getElementById('selectAllHeader');
if (selectAllHeader) {
    selectAllHeader.addEventListener('change', function(e) {
        document.querySelectorAll('.payout-checkbox:not([disabled])').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.payout-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one payout');
        return;
    }
    
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_payouts[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

updateBulkActions();

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProcessModal();
        closeFailedModal();
        closeViewModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const processModal = document.getElementById('processModal');
    const failedModal = document.getElementById('failedModal');
    const viewModal = document.getElementById('viewModal');
    if (e.target === processModal) closeProcessModal();
    if (e.target === failedModal) closeFailedModal();
    if (e.target === viewModal) closeViewModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>