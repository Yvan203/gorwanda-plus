<?php
$pageTitle = 'Users Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle user actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Activate/Deactivate User
if ($action === 'activate' && $userId > 0) {
    $stmt = $db->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "User activated successfully";
    header('Location: users.php');
    exit;
}

if ($action === 'deactivate' && $userId > 0) {
    $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "User deactivated successfully";
    header('Location: users.php');
    exit;
}

// Verify User
if ($action === 'verify' && $userId > 0) {
    $stmt = $db->prepare("UPDATE users SET is_verified = 1, updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "User verified successfully";
    header('Location: users.php');
    exit;
}

// Make Admin
if ($action === 'make_admin' && $userId > 0) {
    $stmt = $db->prepare("UPDATE users SET user_type = 'admin', updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "User promoted to admin successfully";
    header('Location: users.php');
    exit;
}

// Remove Admin
if ($action === 'remove_admin' && $userId > 0) {
    $stmt = $db->prepare("UPDATE users SET user_type = 'tourist', updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$userId]);
    $_SESSION['success'] = "Admin privileges removed";
    header('Location: users.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_users']) && is_array($_POST['selected_users'])) {
    $selectedIds = array_map('intval', $_POST['selected_users']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'activate') {
        $stmt = $db->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE user_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " users activated successfully";
    } elseif ($bulkAction === 'deactivate') {
        $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE user_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " users deactivated successfully";
    } elseif ($bulkAction === 'verify') {
        $stmt = $db->prepare("UPDATE users SET is_verified = 1, updated_at = NOW() WHERE user_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " users verified successfully";
    }
    header('Location: users.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$userType = isset($_GET['user_type']) ? sanitize($_GET['user_type']) : 'all';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$verification = isset($_GET['verification']) ? sanitize($_GET['verification']) : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        u.*,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id AND status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE user_id = u.user_id AND status IN ('confirmed', 'completed')) as total_spent,
        (SELECT COUNT(*) FROM reviews WHERE user_id = u.user_id) as review_count,
        (SELECT AVG(overall_rating) FROM reviews WHERE user_id = u.user_id) as avg_rating
    FROM users u
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($userType !== 'all') {
    $sql .= " AND u.user_type = ?";
    $params[] = $userType;
}

if ($status === 'active') {
    $sql .= " AND u.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND u.is_active = 0";
}

if ($verification === 'verified') {
    $sql .= " AND u.is_verified = 1";
} elseif ($verification === 'unverified') {
    $sql .= " AND u.is_verified = 0";
}

if ($dateFrom) {
    $sql .= " AND DATE(u.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(u.created_at) <= ?";
    $params[] = $dateTo;
}

// Sorting
switch ($sort) {
    case 'name_asc':
        $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";
        break;
    case 'name_desc':
        $sql .= " ORDER BY u.first_name DESC, u.last_name DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY u.created_at ASC";
        break;
    case 'bookings_desc':
        $sql .= " ORDER BY total_bookings DESC";
        break;
    case 'spent_desc':
        $sql .= " ORDER BY total_spent DESC";
        break;
    case 'rating_desc':
        $sql .= " ORDER BY avg_rating DESC";
        break;
    default:
        $sql .= " ORDER BY u.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM users u
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($userType !== 'all') {
    $countSql .= " AND u.user_type = ?";
    $countParams[] = $userType;
}
if ($status === 'active') {
    $countSql .= " AND u.is_active = 1";
} elseif ($status === 'inactive') {
    $countSql .= " AND u.is_active = 0";
}
if ($verification === 'verified') {
    $countSql .= " AND u.is_verified = 1";
} elseif ($verification === 'unverified') {
    $countSql .= " AND u.is_verified = 0";
}
if ($dateFrom) {
    $countSql .= " AND DATE(u.created_at) >= ?";
    $countParams[] = $dateFrom;
}
if ($dateTo) {
    $countSql .= " AND DATE(u.created_at) <= ?";
    $countParams[] = $dateTo;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalUsers = $stmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Get statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN user_type = 'tourist' THEN 1 ELSE 0 END) as tourists,
        SUM(CASE WHEN user_type = 'business_owner' THEN 1 ELSE 0 END) as business_owners,
        SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified,
        SUM(CASE WHEN DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_this_month,
        (SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed', 'completed')) as total_bookings,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE status IN ('confirmed', 'completed')) as total_revenue
    FROM users
")->fetch();

// Get recent activity
$stmt = $db->prepare("
    (SELECT 'booking' as type, b.created_at, CONCAT(u.first_name, ' ', u.last_name) as user_name, 
            b.total_amount as amount, b.status
     FROM bookings b
     LEFT JOIN users u ON b.user_id = u.user_id
     ORDER BY b.created_at DESC
     LIMIT 5)
    UNION ALL
    (SELECT 'review' as type, r.created_at, CONCAT(u.first_name, ' ', u.last_name) as user_name,
            r.overall_rating as amount, NULL as status
     FROM reviews r
     LEFT JOIN users u ON r.user_id = u.user_id
     ORDER BY r.created_at DESC
     LIMIT 5)
    ORDER BY created_at DESC
    LIMIT 8
");
$stmt->execute();
$recentActivity = $stmt->fetchAll();
?>

<style>


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
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 2px;
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
    background: var(--booking-white);
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
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

/* Users Grid */
.users-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.user-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
    position: relative;
}

.user-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.user-card-header {
    padding: 20px;
    background: linear-gradient(135deg, var(--booking-gray-light) 0%, var(--booking-white) 100%);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    align-items: center;
    gap: 16px;
}

.user-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    flex-shrink: 0;
}

.user-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.user-info {
    flex: 1;
}

.user-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.user-email {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-bottom: 4px;
    word-break: break-all;
}

.user-phone {
    font-size: 0.625rem;
    color: var(--booking-text-lighter);
}

.user-card-body {
    padding: 16px;
}

.user-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--booking-border);
}

.user-stat {
    text-align: center;
}

.user-stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.user-stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.user-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}

.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.badge-tourist {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.badge-business {
    background: rgba(147,51,234,0.1);
    color: #9333ea;
}

.badge-admin {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.badge-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.badge-inactive {
    background: #fce8e8;
    color: var(--booking-danger);
}

.badge-verified {
    background: #e6f4ea;
    color: var(--booking-success);
}

.badge-unverified {
    background: #fff4e6;
    color: var(--booking-warning);
}

.user-meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.user-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.action-icon {
    flex: 1;
    padding: 6px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    background: var(--booking-gray-light);
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
}

.action-icon:hover {
    transform: translateY(-1px);
}

.action-icon.view:hover {
    background: rgba(0,102,255,0.1);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.action-icon.edit:hover {
    background: rgba(255,140,0,0.1);
    border-color: var(--booking-warning);
    color: var(--booking-warning);
}

.action-icon.activate:hover {
    background: rgba(0,128,9,0.1);
    border-color: var(--booking-success);
    color: var(--booking-success);
}

.action-icon.deactivate:hover {
    background: rgba(226,17,17,0.1);
    border-color: var(--booking-danger);
    color: var(--booking-danger);
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
}

.bulk-select {
    display: flex;
    align-items: center;
    gap: 8px;
}

.bulk-select input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.bulk-select span {
    font-size: 0.75rem;
    font-weight: 500;
}

.bulk-action-select {
    padding: 6px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
}

.bulk-apply-btn {
    padding: 6px 16px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    cursor: pointer;
}

/* Activity Sidebar */
.activity-sidebar {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    margin-bottom: 24px;
    overflow: hidden;
}

.activity-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.activity-header h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.activity-list {
    max-height: 300px;
    overflow-y: auto;
}

.activity-item {
    display: flex;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    transition: background var(--transition-fast);
}

.activity-item:hover {
    background: var(--booking-gray-light);
}

.activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
}

.activity-icon.booking {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

.activity-icon.review {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.activity-content {
    flex: 1;
}

.activity-text {
    font-size: 0.6875rem;
    color: var(--booking-text);
    margin-bottom: 2px;
}

.activity-text strong {
    font-weight: 600;
}

.activity-meta {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
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

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    grid-column: 1 / -1;
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
    .users-grid {
        grid-template-columns: 1fr;
    }
    .filter-row {
        flex-direction: column;
    }
    .filter-group {
        width: 100%;
    }
    .filter-actions {
        justify-content: flex-end;
    }
    .bulk-actions {
        flex-wrap: wrap;
    }
}
</style>


<!-- Display success/error messages -->
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
            <i class="bi bi-people"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
        <div class="stat-label">Total Users</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-person"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['tourists']); ?></div>
        <div class="stat-label">Tourists</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-building"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['business_owners']); ?></div>
        <div class="stat-label">Partners</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-shield"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['admins']); ?></div>
        <div class="stat-label">Admins</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['verified']); ?></div>
        <div class="stat-label">Verified</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-calendar-plus"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['new_this_month']); ?></div>
        <div class="stat-label">New (30d)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="stat-value"><?php echo formatPrice($stats['total_revenue']); ?></div>
        <div class="stat-label">Total Spent</div>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="users.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>User Type</label>
                <select name="user_type">
                    <option value="all" <?php echo $userType == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="tourist" <?php echo $userType == 'tourist' ? 'selected' : ''; ?>>Tourists</option>
                    <option value="business_owner" <?php echo $userType == 'business_owner' ? 'selected' : ''; ?>>Partners</option>
                    <option value="admin" <?php echo $userType == 'admin' ? 'selected' : ''; ?>>Admins</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Verification</label>
                <select name="verification">
                    <option value="all" <?php echo $verification == 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="verified" <?php echo $verification == 'verified' ? 'selected' : ''; ?>>Verified</option>
                    <option value="unverified" <?php echo $verification == 'unverified' ? 'selected' : ''; ?>>Unverified</option>
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
                    <option value="name_asc" <?php echo $sort == 'name_asc' ? 'selected' : ''; ?>>Name A-Z</option>
                    <option value="name_desc" <?php echo $sort == 'name_desc' ? 'selected' : ''; ?>>Name Z-A</option>
                    <option value="bookings_desc" <?php echo $sort == 'bookings_desc' ? 'selected' : ''; ?>>Most Bookings</option>
                    <option value="spent_desc" <?php echo $sort == 'spent_desc' ? 'selected' : ''; ?>>Highest Spent</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="users.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions Bar -->
<form method="POST" action="users.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_action">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_action_type" class="bulk-action-select">
            <option value="">Bulk Action</option>
            <option value="activate">Activate Selected</option>
            <option value="deactivate">Deactivate Selected</option>
            <option value="verify">Verify Selected</option>
        </select>
        <button type="submit" class="bulk-apply-btn" onclick="return confirm('Are you sure you want to perform this bulk action?')">Apply</button>
        <button type="button" class="bulk-apply-btn" onclick="clearSelection()" style="background: var(--booking-text-light);">Clear</button>
    </div>
</form>

<!-- Users Grid -->
<div class="users-grid">
    <?php if (empty($users)): ?>
    <div class="empty-state">
        <i class="bi bi-people" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No users found matching your criteria</p>
        <a href="users.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <?php foreach ($users as $user): 
        $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
        $userTypeClass = $user['user_type'] == 'tourist' ? 'badge-tourist' : ($user['user_type'] == 'business_owner' ? 'badge-business' : 'badge-admin');
        $userTypeLabel = $user['user_type'] == 'tourist' ? 'Guest' : ($user['user_type'] == 'business_owner' ? 'Partner' : 'Admin');
    ?>
    <div class="user-card" data-user-id="<?php echo $user['user_id']; ?>">
        <div class="user-card-header">
            <div class="user-avatar">
                <?php if ($user['profile_image']): ?>
                <img src="<?php echo getImageUrl($user['profile_image'], 'profile'); ?>" alt="<?php echo sanitize($user['first_name']); ?>">
                <?php else: ?>
                <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></div>
                <div class="user-email">
                    <i class="bi bi-envelope"></i> <?php echo sanitize($user['email']); ?>
                </div>
                <?php if ($user['phone']): ?>
                <div class="user-phone">
                    <i class="bi bi-telephone"></i> <?php echo sanitize($user['phone']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="user-card-body">
            <div class="user-stats">
                <div class="user-stat">
                    <div class="user-stat-value"><?php echo number_format($user['total_bookings']); ?></div>
                    <div class="user-stat-label">Bookings</div>
                </div>
                <div class="user-stat">
                    <div class="user-stat-value"><?php echo formatPrice($user['total_spent']); ?></div>
                    <div class="user-stat-label">Spent</div>
                </div>
                <div class="user-stat">
                    <div class="user-stat-value"><?php echo number_format($user['review_count']); ?></div>
                    <div class="user-stat-label">Reviews</div>
                </div>
            </div>
            
            <div class="user-badges">
                <span class="user-badge <?php echo $userTypeClass; ?>">
                    <i class="bi bi-<?php echo $user['user_type'] == 'tourist' ? 'person' : ($user['user_type'] == 'business_owner' ? 'building' : 'shield'); ?>"></i>
                    <?php echo $userTypeLabel; ?>
                </span>
                
                <span class="user-badge <?php echo $user['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                    <i class="bi bi-<?php echo $user['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                </span>
                
                <span class="user-badge <?php echo $user['is_verified'] ? 'badge-verified' : 'badge-unverified'; ?>">
                    <i class="bi bi-<?php echo $user['is_verified'] ? 'shield-check' : 'clock'; ?>"></i>
                    <?php echo $user['is_verified'] ? 'Verified' : 'Unverified'; ?>
                </span>
                
                <?php if ($user['avg_rating'] > 0): ?>
                <span class="user-badge" style="background: rgba(255,193,7,0.1); color: #ffc107;">
                    <i class="bi bi-star-fill"></i>
                    <?php echo number_format($user['avg_rating'], 1); ?> avg
                </span>
                <?php endif; ?>
            </div>
            
            <div class="user-meta">
                <span><i class="bi bi-calendar3"></i> Joined: <?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                <span><i class="bi bi-clock"></i> <?php echo timeAgo($user['created_at']); ?></span>
            </div>
            
            <div class="user-actions">
                <a href="user-detail.php?id=<?php echo $user['user_id']; ?>" class="action-icon view" title="View Details">
                    <i class="bi bi-eye"></i> View
                </a>
                <a href="edit-user.php?id=<?php echo $user['user_id']; ?>" class="action-icon edit" title="Edit">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php if ($user['is_active']): ?>
                <a href="?action=deactivate&id=<?php echo $user['user_id']; ?>" class="action-icon deactivate" title="Deactivate" onclick="return confirm('Deactivate this user?')">
                    <i class="bi bi-eye-slash"></i> Deactivate
                </a>
                <?php else: ?>
                <a href="?action=activate&id=<?php echo $user['user_id']; ?>" class="action-icon activate" title="Activate" onclick="return confirm('Activate this user?')">
                    <i class="bi bi-eye"></i> Activate
                </a>
                <?php endif; ?>
                <input type="checkbox" class="user-checkbox" value="<?php echo $user['user_id']; ?>" style="margin-left: auto;">
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Recent Activity Sidebar -->
<div class="activity-sidebar">
    <div class="activity-header">
        <h3><i class="bi bi-clock-history"></i> Recent Activity</h3>
    </div>
    <div class="activity-list">
        <?php foreach ($recentActivity as $activity): ?>
        <div class="activity-item">
            <div class="activity-icon <?php echo $activity['type']; ?>">
                <i class="bi bi-<?php echo $activity['type'] == 'booking' ? 'calendar-check' : 'star'; ?>"></i>
            </div>
            <div class="activity-content">
                <div class="activity-text">
                    <?php if ($activity['type'] == 'booking'): ?>
                        <strong><?php echo sanitize($activity['user_name']); ?></strong> made a booking
                        <?php if ($activity['amount']): ?>for <?php echo formatPrice($activity['amount']); ?><?php endif; ?>
                    <?php else: ?>
                        <strong><?php echo sanitize($activity['user_name']); ?></strong> left a <?php echo $activity['amount']; ?>-star review
                    <?php endif; ?>
                </div>
                <div class="activity-meta"><?php echo timeAgo($activity['created_at']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
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

<script>
// Bulk selection
let selectedUsers = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.user-checkbox:checked');
    selectedUsers.clear();
    checkboxes.forEach(cb => selectedUsers.add(cb.value));
    
    const count = selectedUsers.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all
    const allCheckboxes = document.querySelectorAll('.user-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

// Add event listeners
document.querySelectorAll('.user-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

// Handle bulk form submission
document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.user-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one user');
        return;
    }
    
    const action = document.querySelector('[name="bulk_action_type"]').value;
    if (!action) {
        e.preventDefault();
        alert('Please select a bulk action');
        return;
    }
    
    // Add selected IDs to form
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_users[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

// Initialize
updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>