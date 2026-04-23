<?php
$pageTitle = 'Activity Logs';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle log actions
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';

// Clear logs (admin only)
if ($action === 'clear' && isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    $stmt = $db->prepare("TRUNCATE TABLE activity_logs");
    $stmt->execute();
    $_SESSION['success'] = "All activity logs have been cleared";
    header('Location: logs.php');
    exit;
}

// Delete old logs
if ($action === 'delete_old' && isset($_GET['days'])) {
    $days = intval($_GET['days']);
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
    $stmt = $db->prepare("DELETE FROM activity_logs WHERE created_at < ?");
    $stmt->execute([$cutoff_date]);
    $_SESSION['success'] = "Logs older than $days days have been deleted";
    header('Location: logs.php');
    exit;
}

// Export logs
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

    $sql = "SELECT * FROM activity_logs l LEFT JOIN users u ON l.user_id = u.user_id WHERE 1=1";
    $params = [];

    if ($date_from) {
        $sql .= " AND DATE(l.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND DATE(l.created_at) <= ?";
        $params[] = $date_to;
    }
    $sql .= " ORDER BY l.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'User', 'Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address', 'User Agent', 'Date']);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log['log_id'],
                $log['first_name'] . ' ' . $log['last_name'],
                $log['action'],
                $log['entity_type'],
                $log['entity_id'],
                $log['details'],
                $log['ip_address'],
                $log['user_agent'],
                $log['created_at']
            ]);
        }
        fclose($output);
        exit;
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$action_type = isset($_GET['action_type']) ? sanitize($_GET['action_type']) : '';
$entity_type = isset($_GET['entity_type']) ? sanitize($_GET['entity_type']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT l.*, u.first_name, u.last_name, u.email, u.user_type
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR l.action LIKE ? OR l.entity_type LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($user_id > 0) {
    $sql .= " AND l.user_id = ?";
    $params[] = $user_id;
}

if ($action_type) {
    $sql .= " AND l.action = ?";
    $params[] = $action_type;
}

if ($entity_type) {
    $sql .= " AND l.entity_type = ?";
    $params[] = $entity_type;
}

if ($date_from) {
    $sql .= " AND DATE(l.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND DATE(l.created_at) <= ?";
    $params[] = $date_to;
}

// Sorting
switch ($sort) {
    case 'user_asc':
        $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";
        break;
    case 'user_desc':
        $sql .= " ORDER BY u.first_name DESC, u.last_name DESC";
        break;
    case 'action_asc':
        $sql .= " ORDER BY l.action ASC";
        break;
    case 'action_desc':
        $sql .= " ORDER BY l.action DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY l.created_at ASC";
        break;
    default:
        $sql .= " ORDER BY l.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR l.action LIKE ? OR l.entity_type LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($user_id > 0) {
    $countSql .= " AND l.user_id = ?";
    $countParams[] = $user_id;
}
if ($action_type) {
    $countSql .= " AND l.action = ?";
    $countParams[] = $action_type;
}
if ($entity_type) {
    $countSql .= " AND l.entity_type = ?";
    $countParams[] = $entity_type;
}
if ($date_from) {
    $countSql .= " AND DATE(l.created_at) >= ?";
    $countParams[] = $date_from;
}
if ($date_to) {
    $countSql .= " AND DATE(l.created_at) <= ?";
    $countParams[] = $date_to;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalLogs = $stmt->fetchColumn() ?: 0;
$totalPages = $totalLogs > 0 ? ceil($totalLogs / $perPage) : 1;

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        MIN(created_at) as oldest_log,
        MAX(created_at) as newest_log
    FROM activity_logs
")->fetch();

// Get top users by activity
$topUsers = $db->query("
    SELECT l.user_id, u.first_name, u.last_name, u.email, COUNT(*) as activity_count
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.user_id
    GROUP BY l.user_id
    ORDER BY activity_count DESC
    LIMIT 10
")->fetchAll();

// Get action type distribution
$actionStats = $db->query("
    SELECT action, COUNT(*) as count
    FROM activity_logs
    GROUP BY action
    ORDER BY count DESC
    LIMIT 15
")->fetchAll();

// Get entity type distribution
$entityStats = $db->query("
    SELECT entity_type, COUNT(*) as count
    FROM activity_logs
    WHERE entity_type IS NOT NULL
    GROUP BY entity_type
    ORDER BY count DESC
")->fetchAll();

// Get activity by hour (last 7 days)
$hourlyActivity = $db->query("
    SELECT 
        HOUR(created_at) as hour,
        COUNT(*) as count
    FROM activity_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY HOUR(created_at)
    ORDER BY hour ASC
")->fetchAll();

$hours = [];
$hourCounts = [];
for ($i = 0; $i < 24; $i++) {
    $hours[] = sprintf("%02d:00", $i);
    $found = false;
    foreach ($hourlyActivity as $ha) {
        if ($ha['hour'] == $i) {
            $hourCounts[] = $ha['count'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $hourCounts[] = 0;
    }
}

// Get daily activity (last 30 days)
$dailyActivity = $db->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM activity_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll();

$dates = [];
$dailyCounts = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[] = date('M d', strtotime($date));
    $found = false;
    foreach ($dailyActivity as $da) {
        if ($da['date'] == $date) {
            $dailyCounts[] = $da['count'];
            $found = true;
            break;
        }
    }
    if (!$found) {
        $dailyCounts[] = 0;
    }
}

// Get action icons mapping
$actionIcons = [
    'login' => 'box-arrow-in-right',
    'logout' => 'box-arrow-right',
    'booking_created' => 'calendar-plus',
    'booking_updated' => 'calendar-check',
    'booking_cancelled' => 'calendar-x',
    'payment_processed' => 'credit-card',
    'payout_generated' => 'wallet2',
    'payout_processed' => 'send',
    'payout_paid' => 'cash-stack',
    'commission_updated' => 'percent',
    'tax_updated' => 'receipt',
    'user_registered' => 'person-plus',
    'user_updated' => 'person-gear',
    'user_deleted' => 'person-x',
    'property_added' => 'building-add',
    'property_updated' => 'building-gear',
    'property_deleted' => 'building-x',
    'review_posted' => 'star',
    'review_approved' => 'check-circle',
    'review_deleted' => 'trash',
    'settings_updated' => 'gear',
    'backup_created' => 'database',
    'export_downloaded' => 'download'
];

// Get entity icons
$entityIcons = [
    'booking' => 'calendar-check',
    'user' => 'person',
    'stay' => 'building',
    'car_rental' => 'car-front',
    'attraction' => 'ticket-perforated',
    'restaurant' => 'shop',
    'payment' => 'credit-card',
    'payout' => 'wallet2',
    'commission' => 'percent',
    'review' => 'star',
    'settings' => 'gear'
];
?>

<style>
    /* Logs Page Styles */
    .logs-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
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
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--booking-text);
    }

    .stat-label {
        font-size: 0.6875rem;
        color: var(--booking-text-light);
        text-transform: uppercase;
        margin-top: 4px;
    }

    /* Chart Section */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .chart-card {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        padding: 16px;
    }

    .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .chart-header h3 {
        font-size: 0.8125rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .chart-container {
        height: 200px;
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
    }

    .reset-btn {
        background: var(--booking-gray-light);
        color: var(--booking-text);
    }

    /* Action Buttons Bar */
    .action-bar {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        padding: 16px 20px;
        margin-bottom: 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
    }

    .action-btn {
        padding: 6px 12px;
        border-radius: var(--radius-sm);
        font-size: 0.625rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--transition-fast);
        border: 1px solid var(--booking-border);
        background: var(--booking-white);
        text-decoration: none;
        color: var(--booking-text);
    }

    .action-btn:hover {
        background: var(--booking-gray-light);
    }

    .action-btn.danger {
        color: var(--booking-danger);
        border-color: rgba(226, 17, 17, 0.3);
    }

    .action-btn.danger:hover {
        background: rgba(226, 17, 17, 0.1);
    }

    /* Logs Table */
    .table-container {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        overflow-x: auto;
    }

    .logs-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 900px;
    }

    .logs-table th {
        text-align: left;
        padding: 12px 16px;
        font-size: 0.6875rem;
        font-weight: 600;
        color: var(--booking-text-light);
        background: var(--booking-gray-light);
        border-bottom: 1px solid var(--booking-border);
    }

    .logs-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--booking-border);
        font-size: 0.75rem;
        vertical-align: middle;
    }

    .logs-table tr:hover td {
        background: var(--booking-gray-light);
    }

    .log-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.875rem;
    }

    .log-icon.booking {
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
    }

    .log-icon.user {
        background: rgba(0, 128, 9, 0.1);
        color: var(--booking-success);
    }

    .log-icon.payment {
        background: rgba(147, 51, 234, 0.1);
        color: #9333ea;
    }

    .log-icon.property {
        background: rgba(255, 140, 0, 0.1);
        color: var(--booking-warning);
    }

    .log-icon.review {
        background: rgba(255, 193, 7, 0.1);
        color: #ffc107;
    }

    .log-icon.settings {
        background: rgba(108, 117, 125, 0.1);
        color: #6c757d;
    }

    .details-preview {
        font-size: 0.625rem;
        color: var(--booking-text-light);
        max-width: 200px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .user-badge-small {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 100px;
        font-size: 0.5625rem;
        font-weight: 600;
    }

    .user-type-admin {
        background: rgba(255, 140, 0, 0.1);
        color: var(--booking-warning);
    }

    .user-type-business {
        background: rgba(147, 51, 234, 0.1);
        color: #9333ea;
    }

    .user-type-tourist {
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
    }

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

    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
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

    /* Responsive */
    @media (max-width: 1024px) {
        .charts-grid {
            grid-template-columns: 1fr;
        }

        .logs-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .logs-stats {
            grid-template-columns: 1fr;
        }

        .filter-row {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }

        .action-bar {
            flex-direction: column;
            align-items: stretch;
        }

        .action-buttons {
            justify-content: center;
        }
    }
</style>

<div class="logs-header">
    <div class="page-title">
        <h1></h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <div>
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $_SESSION['success'];
            unset($_SESSION['success']); ?>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="logs-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_logs']); ?></div>
        <div class="stat-label">Total Activities</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['unique_users']); ?></div>
        <div class="stat-label">Active Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active_days']); ?></div>
        <div class="stat-label">Active Days</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['oldest_log'] ? date('M d, Y', strtotime($stats['oldest_log'])) : 'N/A'; ?></div>
        <div class="stat-label">First Log</div>
    </div>
</div>

<!-- Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="bi bi-graph-up"></i> Activity Trend (Last 30 Days)</h3>
        </div>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="bi bi-clock"></i> Activity by Hour (Last 7 Days)</h3>
        </div>
        <div class="chart-container">
            <canvas id="hourlyChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Users and Action Types -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="bi bi-people"></i> Top Active Users</h3>
        </div>
        <div class="chart-container" style="height: auto; min-height: 250px;">
            <?php if (empty($topUsers)): ?>
                <div style="text-align: center; padding: 40px;">No data available</div>
            <?php else: ?>
                <div style="overflow-y: auto; max-height: 250px;">
                    <?php foreach ($topUsers as $index => $user): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--booking-border);">
                            <div>
                                <strong><?php echo $index + 1; ?>.</strong>
                                <?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?>
                                <div style="font-size: 0.625rem; color: var(--booking-text-light);"><?php echo sanitize($user['email']); ?></div>
                            </div>
                            <div><span class="stat-value" style="font-size: 1rem;"><?php echo number_format($user['activity_count']); ?></span> actions</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="bi bi-tags"></i> Most Common Actions</h3>
        </div>
        <div class="chart-container" style="height: auto; min-height: 250px;">
            <?php if (empty($actionStats)): ?>
                <div style="text-align: center; padding: 40px;">No data available</div>
            <?php else: ?>
                <div style="overflow-y: auto; max-height: 250px;">
                    <?php foreach ($actionStats as $actionStat): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--booking-border);">
                            <span><?php echo str_replace('_', ' ', ucfirst($actionStat['action'])); ?></span>
                            <span class="stat-value" style="font-size: 0.875rem;"><?php echo number_format($actionStat['count']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="logs.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="User, action, entity..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>User ID</label>
                <input type="number" name="user_id" placeholder="User ID" value="<?php echo $user_id ?: ''; ?>">
            </div>
            <div class="filter-group">
                <label>Action Type</label>
                <select name="action_type">
                    <option value="">All Actions</option>
                    <option value="login" <?php echo $action_type == 'login' ? 'selected' : ''; ?>>Login</option>
                    <option value="logout" <?php echo $action_type == 'logout' ? 'selected' : ''; ?>>Logout</option>
                    <option value="booking_created" <?php echo $action_type == 'booking_created' ? 'selected' : ''; ?>>Booking Created</option>
                    <option value="booking_updated" <?php echo $action_type == 'booking_updated' ? 'selected' : ''; ?>>Booking Updated</option>
                    <option value="booking_cancelled" <?php echo $action_type == 'booking_cancelled' ? 'selected' : ''; ?>>Booking Cancelled</option>
                    <option value="payment_processed" <?php echo $action_type == 'payment_processed' ? 'selected' : ''; ?>>Payment Processed</option>
                    <option value="user_registered" <?php echo $action_type == 'user_registered' ? 'selected' : ''; ?>>User Registered</option>
                    <option value="user_updated" <?php echo $action_type == 'user_updated' ? 'selected' : ''; ?>>User Updated</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Entity Type</label>
                <select name="entity_type">
                    <option value="">All Entities</option>
                    <option value="booking" <?php echo $entity_type == 'booking' ? 'selected' : ''; ?>>Booking</option>
                    <option value="user" <?php echo $entity_type == 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="stay" <?php echo $entity_type == 'stay' ? 'selected' : ''; ?>>Stay</option>
                    <option value="car_rental" <?php echo $entity_type == 'car_rental' ? 'selected' : ''; ?>>Car Rental</option>
                    <option value="attraction" <?php echo $entity_type == 'attraction' ? 'selected' : ''; ?>>Attraction</option>
                </select>
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="user_asc" <?php echo $sort == 'user_asc' ? 'selected' : ''; ?>>User A-Z</option>
                    <option value="user_desc" <?php echo $sort == 'user_desc' ? 'selected' : ''; ?>>User Z-A</option>
                    <option value="action_asc" <?php echo $sort == 'action_asc' ? 'selected' : ''; ?>>Action A-Z</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="logs.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Action Bar -->
<div class="action-bar">
    <div class="action-buttons">
        <a href="?action=export&format=csv<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $date_from ? '&date_from=' . $date_from : ''; ?><?php echo $date_to ? '&date_to=' . $date_to : ''; ?>" class="action-btn">
            <i class="bi bi-download"></i> Export CSV
        </a>
        <button class="action-btn" onclick="openDeleteOldModal()">
            <i class="bi bi-trash"></i> Delete Old Logs
        </button>
        <button class="action-btn danger" onclick="openClearModal()">
            <i class="bi bi-exclamation-triangle"></i> Clear All Logs
        </button>
    </div>
    <div class="info-text">
        <i class="bi bi-info-circle"></i> Showing <?php echo count($logs); ?> of <?php echo number_format($totalLogs); ?> logs
    </div>
</div>

<!-- Logs Table -->
<div class="table-container">
    <table class="logs-table">
        <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 60px;">
                        <i class="bi bi-activity" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
                        <p style="margin-top: 12px;">No activity logs found</p>
</div>
</tr>
<?php else: ?>
    <?php foreach ($logs as $log):
                    $actionIcon = $actionIcons[$log['action']] ?? 'activity';
                    $entityIcon = $entityIcons[$log['entity_type']] ?? 'activity';
                    $userTypeClass = $log['user_type'] == 'admin' ? 'user-type-admin' : ($log['user_type'] == 'business_owner' ? 'user-type-business' : 'user-type-tourist');
                    $userTypeLabel = $log['user_type'] == 'admin' ? 'Admin' : ($log['user_type'] == 'business_owner' ? 'Partner' : 'Guest');
                    $details = $log['details'] ? json_decode($log['details'], true) : [];
    ?>
        <tr>
            <td style="white-space: nowrap;">
                <strong><?php echo date('M d, H:i', strtotime($log['created_at'])); ?></strong>
                <div style="font-size: 0.5625rem; color: var(--booking-text-light);"><?php echo date('Y-m-d', strtotime($log['created_at'])); ?></div>
            </td>
            <td>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="log-icon user">
                        <i class="bi bi-person"></i>
                    </div>
                    <div>
                        <strong><?php echo sanitize($log['first_name'] . ' ' . $log['last_name']); ?></strong>
                        <div class="user-badge-small <?php echo $userTypeClass; ?>"><?php echo $userTypeLabel; ?></div>
                    </div>
                </div>
            </td>
            <td>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="log-icon <?php echo strpos($log['action'], 'booking') !== false ? 'booking' : (strpos($log['action'], 'user') !== false ? 'user' : (strpos($log['action'], 'payment') !== false ? 'payment' : 'property')); ?>">
                        <i class="bi bi-<?php echo $actionIcon; ?>"></i>
                    </div>
                    <span><?php echo str_replace('_', ' ', ucfirst($log['action'])); ?></span>
                </div>
            </td>
            <td>
                <?php if ($log['entity_type']): ?>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <i class="bi bi-<?php echo $entityIcon; ?>"></i>
                        <span style="text-transform: capitalize;"><?php echo str_replace('_', ' ', $log['entity_type']); ?></span>
                        <?php if ($log['entity_id']): ?>
                            <span class="user-badge-small" style="background: var(--booking-gray-light);">ID: <?php echo $log['entity_id']; ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($details)): ?>
                    <div class="details-preview">
                        <?php
                        $detailParts = [];
                        if (isset($details['amount'])) $detailParts[] = 'Amount: ' . formatPrice($details['amount']);
                        if (isset($details['nights'])) $detailParts[] = 'Nights: ' . $details['nights'];
                        if (isset($details['room_id'])) $detailParts[] = 'Room #' . $details['room_id'];
                        if (isset($details['stay_id'])) $detailParts[] = 'Stay #' . $details['stay_id'];
                        if (isset($details['status'])) $detailParts[] = 'Status: ' . ucfirst($details['status']);
                        echo implode(' • ', $detailParts);
                        ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($log['ip_address']): ?>
                    <code style="font-size: 0.625rem;"><?php echo $log['ip_address']; ?></code>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
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

<!-- Clear Logs Modal -->
<div id="clearModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Clear All Activity Logs</h3>
            <button type="button" class="modal-close" onclick="closeClearModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete ALL activity logs? This action cannot be undone.</p>
            <p style="color: var(--booking-danger); margin-top: 12px;"><i class="bi bi-exclamation-triangle-fill"></i> This will permanently remove all log entries.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="action-btn" onclick="closeClearModal()">Cancel</button>
            <a href="?action=clear&confirm=yes" class="action-btn danger">Clear All Logs</a>
        </div>
    </div>
</div>

<!-- Delete Old Logs Modal -->
<div id="deleteOldModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Delete Old Logs</h3>
            <button type="button" class="modal-close" onclick="closeDeleteOldModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Delete logs older than:</p>
            <div style="margin: 16px 0;">
                <button class="action-btn" onclick="deleteOldLogs(30)">30 days</button>
                <button class="action-btn" onclick="deleteOldLogs(60)">60 days</button>
                <button class="action-btn" onclick="deleteOldLogs(90)">90 days</button>
                <button class="action-btn" onclick="deleteOldLogs(180)">180 days</button>
                <button class="action-btn" onclick="deleteOldLogs(365)">1 year</button>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="action-btn" onclick="closeDeleteOldModal()">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Trend Chart
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [{
                label: 'Activities',
                data: <?php echo json_encode($dailyCounts); ?>,
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
                            return 'Activities: ' + context.parsed.y;
                        }
                    }
                }
            }
        }
    });

    // Hourly Chart
    const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(hourlyCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($hours); ?>,
            datasets: [{
                label: 'Activities',
                data: <?php echo json_encode($hourCounts); ?>,
                backgroundColor: '#003b95',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    ticks: {
                        font: {
                            size: 9
                        },
                        maxRotation: 45
                    }
                },
                y: {
                    ticks: {
                        stepSize: 1,
                        font: {
                            size: 9
                        }
                    }
                }
            }
        }
    });

    // Modal functions
    function openClearModal() {
        document.getElementById('clearModal').style.display = 'flex';
    }

    function closeClearModal() {
        document.getElementById('clearModal').style.display = 'none';
    }

    function openDeleteOldModal() {
        document.getElementById('deleteOldModal').style.display = 'flex';
    }

    function closeDeleteOldModal() {
        document.getElementById('deleteOldModal').style.display = 'none';
    }

    function deleteOldLogs(days) {
        if (confirm(`Delete logs older than ${days} days?`)) {
            window.location.href = `?action=delete_old&days=${days}`;
        }
    }

    // Close modals on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeClearModal();
            closeDeleteOldModal();
        }
    });

    // Close modals when clicking outside
    window.onclick = function(e) {
        const clearModal = document.getElementById('clearModal');
        const deleteOldModal = document.getElementById('deleteOldModal');
        if (e.target === clearModal) closeClearModal();
        if (e.target === deleteOldModal) closeDeleteOldModal();
    }
</script>

<?php require_once 'includes/admin_footer.php'; ?>