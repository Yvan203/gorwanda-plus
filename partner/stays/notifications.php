<?php
$pageTitle = 'Notifications';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Handle mark all read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    header('Location: notifications.php');
    exit;
}

// Handle delete single
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notification'])) {
    $notifId = intval($_POST['notification_id']);
    $stmt = $db->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$notifId, $userId]);
    header('Location: notifications.php');
    exit;
}

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
    $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->execute([$userId]);
    header('Location: notifications.php');
    exit;
}

// Handle mark single as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notifId = intval($_POST['notification_id']);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$notifId, $userId]);
    header('Location: notifications.php');
    exit;
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query
$whereConditions = ["n.user_id = ?"];
$params = [$userId];

if ($filter === 'unread') {
    $whereConditions[] = "n.is_read = 0";
} elseif ($filter === 'read') {
    $whereConditions[] = "n.is_read = 1";
} elseif ($filter === 'bookings') {
    $whereConditions[] = "n.type IN ('new_booking', 'booking_confirmed', 'booking_cancelled', 'payment_received')";
} elseif ($filter === 'reviews') {
    $whereConditions[] = "n.type = 'new_review'";
} elseif ($filter === 'alerts') {
    $whereConditions[] = "n.type IN ('low_inventory', 'checkin_reminder', 'checkout_reminder', 'system_alert')";
}

$whereClause = implode(' AND ', $whereConditions);

// Get notifications
$stmt = $db->prepare("
    SELECT n.*, TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
    FROM notifications n
    WHERE $whereClause
    ORDER BY n.created_at DESC
    LIMIT 50
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Get counts for filters
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count,
        SUM(CASE WHEN type IN ('new_booking', 'booking_confirmed', 'booking_cancelled', 'payment_received') THEN 1 ELSE 0 END) as booking_count,
        SUM(CASE WHEN type = 'new_review' THEN 1 ELSE 0 END) as review_count,
        SUM(CASE WHEN type IN ('low_inventory', 'checkin_reminder', 'checkout_reminder', 'system_alert') THEN 1 ELSE 0 END) as alert_count
    FROM notifications n
    WHERE n.user_id = ?
");
$stmt->execute([$userId]);
$counts = $stmt->fetch();

// Group notifications by date
$groupedNotifications = [];
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

foreach ($notifications as $n) {
    $date = date('Y-m-d', strtotime($n['created_at']));

    if ($date === $today) {
        $group = 'Today';
    } elseif ($date === $yesterday) {
        $group = 'Yesterday';
    } elseif ($date >= date('Y-m-d', strtotime('-7 days'))) {
        $group = 'This Week';
    } elseif ($date >= date('Y-m-d', strtotime('-30 days'))) {
        $group = 'This Month';
    } else {
        $group = 'Earlier';
    }

    $groupedNotifications[$group][] = $n;
}

// Type icons and colors
$typeConfig = [
    'new_booking' => [
        'icon' => 'bi-calendar-check',
        'color' => '#003b95',
        'bg' => '#f0f4ff',
        'label' => 'New Booking'
    ],
    'booking_confirmed' => [
        'icon' => 'bi-check-circle',
        'color' => '#008009',
        'bg' => '#e6f4ea',
        'label' => 'Booking Confirmed'
    ],
    'booking_cancelled' => [
        'icon' => 'bi-calendar-x',
        'color' => '#e21111',
        'bg' => '#fce8e8',
        'label' => 'Booking Cancelled'
    ],
    'payment_received' => [
        'icon' => 'bi-credit-card',
        'color' => '#008009',
        'bg' => '#e6f4ea',
        'label' => 'Payment Received'
    ],
    'new_review' => [
        'icon' => 'bi-star',
        'color' => '#febb02',
        'bg' => '#fff8e6',
        'label' => 'New Review'
    ],
    'checkin_reminder' => [
        'icon' => 'bi-box-arrow-in-right',
        'color' => '#7c3aed',
        'bg' => '#f3e8ff',
        'label' => 'Check-in Reminder'
    ],
    'checkout_reminder' => [
        'icon' => 'bi-box-arrow-left',
        'color' => '#e67e22',
        'bg' => '#fff4e6',
        'label' => 'Check-out Reminder'
    ],
    'low_inventory' => [
        'icon' => 'bi-exclamation-triangle',
        'color' => '#ff8c00',
        'bg' => '#fff4e6',
        'label' => 'Low Inventory'
    ],
    'system_alert' => [
        'icon' => 'bi-gear',
        'color' => '#6b6b6b',
        'bg' => '#f3f4f6',
        'label' => 'System Alert'
    ],
];

// Format time ago
function formatTimeAgo($seconds)
{
    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ago';
    if ($seconds < 86400) return floor($seconds / 3600) . ' hours ago';
    if ($seconds < 604800) return floor($seconds / 86400) . ' days ago';
    return date('M d, Y', time() - $seconds);
}
?>

<style>
    /* Notifications Page Specific Styles */
    .notifications-container {
        max-width: 900px;
        margin: 0 auto;
    }

    /* Page Header */
    .notif-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .notif-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 4px 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .notif-header h1 .icon-circle {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: #f0f4ff;
        color: #003b95;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }

    .notif-subtitle {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0;
    }

    /* Filter Tabs */
    .filter-tabs {
        display: flex;
        gap: 4px;
        background: #f3f4f6;
        padding: 4px;
        border-radius: 12px;
        margin-bottom: 24px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .filter-tab {
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 500;
        color: #6b6b6b;
        text-decoration: none;
        white-space: nowrap;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 6px;
        border: none;
        background: transparent;
        cursor: pointer;
    }

    .filter-tab:hover {
        color: #1a1a1a;
        background: rgba(255, 255, 255, 0.5);
    }

    .filter-tab.active {
        background: white;
        color: #003b95;
        font-weight: 600;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    .filter-count {
        font-size: 0.6875rem;
        padding: 2px 8px;
        border-radius: 100px;
        background: #e5e7eb;
        color: #6b6b6b;
        font-weight: 600;
    }

    .filter-tab.active .filter-count {
        background: #003b95;
        color: white;
    }

    /* Action Bar */
    .action-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .action-btn {
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.8125rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #e7e7e7;
        background: white;
        color: #1a1a1a;
        text-decoration: none;
    }

    .action-btn:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    .action-btn.primary {
        background: #003b95;
        color: white;
        border-color: #003b95;
    }

    .action-btn.primary:hover {
        background: #002d73;
    }

    .action-btn.danger {
        color: #e21111;
        border-color: #fce8e8;
    }

    .action-btn.danger:hover {
        background: #fce8e8;
    }

    /* Notification List */
    .notif-list {
        background: white;
        border-radius: 16px;
        border: 1px solid #e7e7e7;
        overflow: hidden;
    }

    /* Date Group */
    .date-group {
        padding: 12px 24px;
        background: #f9fafb;
        border-bottom: 1px solid #e7e7e7;
        font-size: 0.75rem;
        font-weight: 600;
        color: #6b6b6b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: sticky;
        top: 0;
        z-index: 5;
    }

    /* Notification Item */
    .notif-item {
        display: flex;
        gap: 16px;
        padding: 16px 24px;
        border-bottom: 1px solid #f3f4f6;
        transition: all 0.2s;
        position: relative;
        align-items: flex-start;
        cursor: pointer;
    }

    .notif-item:last-child {
        border-bottom: none;
    }

    .notif-item:hover {
        background: #f8faff;
    }

    .notif-item.unread {
        background: #fafbff;
        border-left: 3px solid #003b95;
    }

    .notif-item.unread:hover {
        background: #f0f4ff;
    }

    /* Notification Icon */
    .notif-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
        position: relative;
    }

    .notif-item.unread .notif-icon::after {
        content: '';
        width: 10px;
        height: 10px;
        background: #003b95;
        border: 2px solid white;
        border-radius: 50%;
        position: absolute;
        top: -2px;
        right: -2px;
    }

    /* Notification Content */
    .notif-content {
        flex: 1;
        min-width: 0;
    }

    .notif-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 4px;
    }

    .notif-type-label {
        font-size: 0.6875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 3px 8px;
        border-radius: 100px;
        white-space: nowrap;
    }

    .notif-title {
        font-size: 0.9375rem;
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 4px;
        line-height: 1.3;
    }

    .notif-item.unread .notif-title {
        font-weight: 700;
    }

    .notif-message {
        font-size: 0.8125rem;
        color: #6b6b6b;
        line-height: 1.5;
        margin-bottom: 8px;
    }

    .notif-meta {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .notif-time {
        font-size: 0.75rem;
        color: #9ca3af;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .notif-amount {
        font-size: 0.75rem;
        font-weight: 600;
        color: #008009;
        background: #e6f4ea;
        padding: 2px 8px;
        border-radius: 100px;
    }

    /* Notification Actions */
    .notif-actions {
        display: flex;
        gap: 4px;
        flex-shrink: 0;
        opacity: 0;
        transition: opacity 0.2s;
    }

    .notif-item:hover .notif-actions {
        opacity: 1;
    }

    .notif-action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        background: transparent;
        color: #9ca3af;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        font-size: 0.875rem;
    }

    .notif-action-btn:hover {
        background: #f3f4f6;
        color: #1a1a1a;
    }

    .notif-action-btn.delete:hover {
        background: #fce8e8;
        color: #e21111;
    }

    .notif-action-btn.read:hover {
        background: #e6f4ea;
        color: #008009;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
    }

    .empty-state-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: #f3f4f6;
        color: #9ca3af;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        margin: 0 auto 20px;
    }

    .empty-state h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 8px 0;
    }

    .empty-state p {
        font-size: 0.875rem;
        color: #6b6b6b;
        margin: 0 0 24px 0;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .notif-header {
            flex-direction: column;
        }

        .notif-item {
            padding: 14px 16px;
            gap: 12px;
        }

        .notif-icon {
            width: 40px;
            height: 40px;
            font-size: 1.125rem;
            border-radius: 10px;
        }

        .notif-actions {
            opacity: 1;
        }

        .filter-tabs {
            gap: 2px;
        }

        .filter-tab {
            padding: 6px 12px;
            font-size: 0.75rem;
        }
    }
</style>

<div class="notifications-container">
    <!-- Page Header -->
    <div class="notif-header">
        <div>
            <h1>
                <span class="icon-circle">
                    <i class="bi bi-bell"></i>
                </span>
                Notifications
            </h1>
            <p class="notif-subtitle">
                <?php if ($counts['unread'] > 0): ?>
                    You have <strong style="color: #003b95;"><?php echo $counts['unread']; ?> unread</strong> notifications
                <?php else: ?>
                    You're all caught up! No unread notifications
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <?php if ($counts['unread'] > 0): ?>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="mark_all_read" class="action-btn primary">
                        <i class="bi bi-check-all"></i> Mark All Read
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($counts['total'] > 0): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete ALL notifications? This cannot be undone.')">
                    <button type="submit" name="clear_all" class="action-btn danger">
                        <i class="bi bi-trash"></i> Clear All
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="notifications.php?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
            <i class="bi bi-inbox"></i> All
            <span class="filter-count"><?php echo $counts['total']; ?></span>
        </a>
        <a href="notifications.php?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
            <i class="bi bi-envelope"></i> Unread
            <span class="filter-count"><?php echo $counts['unread']; ?></span>
        </a>
        <a href="notifications.php?filter=read" class="filter-tab <?php echo $filter === 'read' ? 'active' : ''; ?>">
            <i class="bi bi-envelope-open"></i> Read
            <span class="filter-count"><?php echo $counts['read_count']; ?></span>
        </a>
        <a href="notifications.php?filter=bookings" class="filter-tab <?php echo $filter === 'bookings' ? 'active' : ''; ?>">
            <i class="bi bi-calendar-check"></i> Bookings
            <span class="filter-count"><?php echo $counts['booking_count']; ?></span>
        </a>
        <a href="notifications.php?filter=reviews" class="filter-tab <?php echo $filter === 'reviews' ? 'active' : ''; ?>">
            <i class="bi bi-star"></i> Reviews
            <span class="filter-count"><?php echo $counts['review_count']; ?></span>
        </a>
        <a href="notifications.php?filter=alerts" class="filter-tab <?php echo $filter === 'alerts' ? 'active' : ''; ?>">
            <i class="bi bi-exclamation-triangle"></i> Alerts
            <span class="filter-count"><?php echo $counts['alert_count']; ?></span>
        </a>
    </div>

    <!-- Notification List -->
    <div class="notif-list">
        <?php if (empty($groupedNotifications)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-state-icon">
                    <?php if ($filter === 'unread'): ?>
                        <i class="bi bi-envelope-open"></i>
                    <?php elseif ($filter === 'bookings'): ?>
                        <i class="bi bi-calendar-check"></i>
                    <?php elseif ($filter === 'reviews'): ?>
                        <i class="bi bi-star"></i>
                    <?php else: ?>
                        <i class="bi bi-bell-slash"></i>
                    <?php endif; ?>
                </div>
                <h3>
                    <?php if ($filter !== 'all'): ?>
                        No <?php echo ucfirst($filter); ?> Notifications
                    <?php else: ?>
                        No Notifications Yet
                    <?php endif; ?>
                </h3>
                <p>
                    <?php if ($filter !== 'all'): ?>
                        There are no <?php echo strtolower($filter); ?> notifications to display.
                        <a href="notifications.php?filter=all" style="color: #003b95; text-decoration: none; font-weight: 500;">View all notifications</a>
                    <?php else: ?>
                        You'll see notifications here when you receive bookings, payments, and reviews from guests.
                    <?php endif; ?>
                </p>
                <?php if ($filter !== 'all'): ?>
                    <a href="notifications.php?filter=all" class="action-btn primary">
                        <i class="bi bi-arrow-left"></i> View All Notifications
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($groupedNotifications as $group => $groupNotifs): ?>
                <!-- Date Group Header -->
                <div class="date-group">
                    <i class="bi bi-calendar3 me-2"></i> <?php echo $group; ?>
                </div>

                <?php foreach ($groupNotifs as $n):
                    $config = $typeConfig[$n['type']] ?? [
                        'icon' => 'bi-bell',
                        'color' => '#6b6b6b',
                        'bg' => '#f3f4f6',
                        'label' => 'Notification'
                    ];
                    $data = json_decode($n['data'] ?? '{}', true);
                ?>
                    <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>"
                        onclick="window.location.href='<?php echo getNotificationLink($n['type'], $data); ?>'">

                        <!-- Icon -->
                        <div class="notif-icon" style="background: <?php echo $config['bg']; ?>; color: <?php echo $config['color']; ?>;">
                            <i class="bi <?php echo $config['icon']; ?>"></i>
                        </div>

                        <!-- Content -->
                        <div class="notif-content">
                            <div class="notif-top">
                                <span class="notif-type-label" style="background: <?php echo $config['bg']; ?>; color: <?php echo $config['color']; ?>;">
                                    <?php echo $config['label']; ?>
                                </span>
                            </div>
                            <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                            <div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
                            <div class="notif-meta">
                                <span class="notif-time">
                                    <i class="bi bi-clock"></i> <?php echo formatTimeAgo($n['seconds_ago']); ?>
                                </span>
                                <?php if (isset($data['amount'])): ?>
                                    <span class="notif-amount">
                                        <i class="bi bi-cash"></i> <?php echo htmlspecialchars($data['amount']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($data['reference'])): ?>
                                    <span style="font-size: 0.6875rem; color: #9ca3af; font-family: monospace;">
                                        <?php echo htmlspecialchars($data['reference']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="notif-actions">
                            <?php if (!$n['is_read']): ?>
                                <form method="POST" style="display: inline;" onclick="event.stopPropagation();">
                                    <input type="hidden" name="notification_id" value="<?php echo $n['notification_id']; ?>">
                                    <button type="submit" name="mark_read" class="notif-action-btn read" title="Mark as read">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display: inline;" onclick="event.stopPropagation();" onsubmit="return confirm('Delete this notification?')">
                                <input type="hidden" name="notification_id" value="<?php echo $n['notification_id']; ?>">
                                <button type="submit" name="delete_notification" class="notif-action-btn delete" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Helper function to get notification link
function getNotificationLink($type, $data)
{
    $routes = [
        'new_booking' => 'bookings.php',
        'booking_confirmed' => 'bookings.php',
        'booking_cancelled' => 'bookings.php',
        'payment_received' => 'bookings.php',
        'new_review' => 'reviews.php',
        'checkin_reminder' => 'bookings.php?status=confirmed',
        'checkout_reminder' => 'bookings.php?status=confirmed',
        'low_inventory' => 'rooms.php',
        'system_alert' => 'dashboard.php',
    ];
    return $routes[$type] ?? 'dashboard.php';
}
?>

<?php require_once 'includes/stays_footer.php'; ?>