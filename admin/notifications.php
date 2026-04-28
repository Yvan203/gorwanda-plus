<?php
$pageTitle = 'Notifications';
require_once 'includes/admin_header.php';
require_once '../includes/notifications.php';

$nm = new NotificationManager();
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$notifications = $nm->getNotifications($userId, $perPage, $offset);
$totalNotifications = $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId")->fetchColumn();
$totalPages = ceil($totalNotifications / $perPage);

// Get notification statistics
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN WEEK(created_at) = WEEK(CURDATE()) THEN 1 ELSE 0 END) as this_week,
        SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) as this_month
    FROM notifications 
    WHERE user_id = $userId
")->fetch();
?>

<style>
    /* Notifications Page Styles */
    .notifications-page {
        background: var(--booking-white);
        border-radius: var(--radius-lg);
        border: 1px solid var(--booking-border);
        overflow: hidden;
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
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

    .stat-icon.blue {
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
    }

    .stat-icon.green {
        background: rgba(0, 128, 9, 0.1);
        color: var(--booking-success);
    }

    .stat-icon.orange {
        background: rgba(255, 140, 0, 0.1);
        color: var(--booking-warning);
    }

    .stat-icon.purple {
        background: rgba(147, 51, 234, 0.1);
        color: #9333ea;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--booking-text);
        line-height: 1.2;
    }

    .stat-label {
        font-size: 0.6875rem;
        color: var(--booking-text-light);
        text-transform: uppercase;
        margin-top: 4px;
    }

    /* Notifications Header */
    .notifications-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--booking-border);
        background: var(--booking-gray-light);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .notifications-header h1 {
        font-size: 1.25rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .filter-tabs {
        display: flex;
        gap: 8px;
    }

    .filter-tab {
        padding: 6px 16px;
        background: white;
        border: 1px solid var(--booking-border);
        border-radius: 20px;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all var(--transition-fast);
    }

    .filter-tab:hover {
        border-color: var(--booking-blue);
        color: var(--booking-blue);
    }

    .filter-tab.active {
        background: var(--booking-blue);
        border-color: var(--booking-blue);
        color: white;
    }

    /* Notifications List */
    .notifications-list {
        min-height: 500px;
        max-height: 600px;
        overflow-y: auto;
    }

    .notification-item {
        padding: 20px 24px;
        border-bottom: 1px solid var(--booking-border);
        display: flex;
        gap: 16px;
        transition: all var(--transition-fast);
        cursor: pointer;
        position: relative;
    }

    .notification-item:hover {
        background: var(--booking-gray-light);
    }

    .notification-item.unread {
        background: linear-gradient(90deg, rgba(0, 102, 255, 0.02) 0%, transparent 100%);
        border-left: 3px solid var(--booking-blue);
    }

    .notification-item.unread .notification-title {
        font-weight: 700;
    }

    .notification-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .notification-icon.booking {
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
    }

    .notification-icon.payment {
        background: rgba(0, 128, 9, 0.1);
        color: var(--booking-success);
    }

    .notification-icon.vendor {
        background: rgba(147, 51, 234, 0.1);
        color: #9333ea;
    }

    .notification-icon.review {
        background: rgba(255, 140, 0, 0.1);
        color: var(--booking-warning);
    }

    .notification-icon.alert {
        background: rgba(226, 17, 17, 0.1);
        color: var(--booking-danger);
    }

    .notification-content {
        flex: 1;
    }

    .notification-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 6px;
    }

    .notification-title {
        font-size: 0.9375rem;
        font-weight: 600;
        color: var(--booking-text);
    }

    .notification-badge {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.625rem;
        font-weight: 600;
    }

    .notification-badge.new {
        background: var(--booking-danger);
        color: white;
    }

    .notification-badge.booking {
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
    }

    .notification-message {
        font-size: 0.8125rem;
        color: var(--booking-text-light);
        margin-bottom: 8px;
        line-height: 1.5;
    }

    .notification-details {
        background: var(--booking-gray-light);
        border-radius: var(--radius-sm);
        padding: 12px;
        margin-top: 8px;
        display: inline-block;
        font-size: 0.6875rem;
    }

    .notification-details span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-right: 16px;
    }

    .notification-details i {
        color: var(--booking-blue);
    }

    .notification-meta {
        display: flex;
        gap: 16px;
        font-size: 0.625rem;
        color: var(--booking-text-lighter);
        margin-top: 8px;
    }

    .notification-actions {
        display: flex;
        gap: 8px;
        align-items: flex-start;
    }

    .action-btn-small {
        padding: 6px 12px;
        border-radius: var(--radius-sm);
        font-size: 0.625rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--transition-fast);
        border: none;
        background: var(--booking-white);
        border: 1px solid var(--booking-border);
        color: var(--booking-text);
    }

    .action-btn-small:hover {
        background: var(--booking-gray-light);
    }

    .action-btn-small.primary {
        background: var(--booking-blue);
        border-color: var(--booking-blue);
        color: white;
    }

    .action-btn-small.danger {
        color: var(--booking-danger);
        border-color: rgba(226, 17, 17, 0.3);
    }

    .action-btn-small.danger:hover {
        background: rgba(226, 17, 17, 0.1);
    }

    /* Pagination */
    .pagination {
        padding: 20px 24px;
        border-top: 1px solid var(--booking-border);
        display: flex;
        justify-content: center;
        gap: 8px;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
    }

    .empty-icon {
        font-size: 3rem;
        color: var(--booking-text-lighter);
        margin-bottom: 16px;
    }

    .empty-title {
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .empty-text {
        font-size: 0.75rem;
        color: var(--booking-text-light);
    }

    /* Responsive */
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .notification-item {
            flex-direction: column;
        }

        .notification-actions {
            justify-content: flex-end;
        }

        .notifications-header {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<div class="container-fluid px-4">
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="bi bi-bell"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            <div class="stat-label">Total</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">
                <i class="bi bi-envelope"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['unread']); ?></div>
            <div class="stat-label">Unread</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">
                <i class="bi bi-calendar-day"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['today']); ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">
                <i class="bi bi-calendar-week"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['this_week']); ?></div>
            <div class="stat-label">This Week</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">
                <i class="bi bi-calendar-month"></i>
            </div>
            <div class="stat-value"><?php echo number_format($stats['this_month']); ?></div>
            <div class="stat-label">This Month</div>
        </div>
    </div>

    <div class="notifications-page">
        <div class="notifications-header">
            <h1>
                <i class="bi bi-bell-fill"></i>
                Notifications
                <?php if ($stats['unread'] > 0): ?>
                    <span class="notification-badge new" style="background: var(--booking-danger); color: white; padding: 2px 8px;">
                        <?php echo $stats['unread']; ?> new
                    </span>
                <?php endif; ?>
            </h1>
            <div class="filter-tabs">
                <button class="filter-tab active" onclick="filterNotifications('all')">All</button>
                <button class="filter-tab" onclick="filterNotifications('unread')">Unread</button>
                <button class="filter-tab" onclick="markAllAsReadPage()">
                    <i class="bi bi-check2-all"></i> Mark all read
                </button>
            </div>
        </div>

        <div class="notifications-list" id="notificationsList">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-bell-slash"></i>
                    </div>
                    <div class="empty-title">No notifications yet</div>
                    <div class="empty-text">When you receive notifications, they will appear here</div>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif):
                    $typeClass = match ($notif['type']) {
                        'new_booking', 'booking_cancelled' => 'booking',
                        'payment_received' => 'payment',
                        'vendor_registration', 'verification_pending' => 'vendor',
                        'new_review' => 'review',
                        'low_inventory', 'system_alert' => 'alert',
                        default => 'booking'
                    };

                    $data = $notif['data'] ? json_decode($notif['data'], true) : [];
                ?>
                    <div class="notification-item <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>"
                        data-id="<?php echo $notif['notification_id']; ?>"
                        data-type="<?php echo $notif['type']; ?>"
                        onclick="markAsReadPage(<?php echo $notif['notification_id']; ?>)">

                        <div class="notification-icon <?php echo $typeClass; ?>">
                            <i class="bi bi-<?php echo getNotificationIcon($notif['type']); ?>"></i>
                        </div>

                        <div class="notification-content">
                            <div class="notification-header-row">
                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                <?php if (!$notif['is_read']): ?>
                                    <span class="notification-badge new">New</span>
                                <?php endif; ?>
                            </div>

                            <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>

                            <?php if (!empty($data)): ?>
                                <div class="notification-details">
                                    <?php if (isset($data['amount'])): ?>
                                        <span><i class="bi bi-cash-stack"></i> <?php echo $data['amount']; ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($data['method'])): ?>
                                        <span><i class="bi bi-credit-card"></i> <?php echo ucfirst($data['method']); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($data['booking_id'])): ?>
                                        <span><i class="bi bi-receipt"></i> Booking #<?php echo $data['booking_id']; ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($data['nights'])): ?>
                                        <span><i class="bi bi-moon"></i> <?php echo $data['nights']; ?> nights</span>
                                    <?php endif; ?>
                                    <?php if (isset($data['room_id'])): ?>
                                        <span><i class="bi bi-door-open"></i> Room #<?php echo $data['room_id']; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="notification-meta">
                                <span><i class="bi bi-clock"></i> <?php echo timeAgo($notif['created_at']); ?></span>
                                <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                            </div>
                        </div>

                        <div class="notification-actions" onclick="event.stopPropagation()">
                            <?php if (!$notif['is_read']): ?>
                                <button class="action-btn-small" onclick="markAsReadPage(<?php echo $notif['notification_id']; ?>)">
                                    <i class="bi bi-check"></i> Mark read
                                </button>
                            <?php endif; ?>
                            <button class="action-btn-small danger" onclick="deleteNotificationPage(<?php echo $notif['notification_id']; ?>)">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="page-link"><i class="bi bi-chevron-left"></i></a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);

                if ($start > 1): ?>
                    <a href="?page=1" class="page-link">1</a>
                    <?php if ($start > 2): ?><span class="page-dots">...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="page-dots">...</span><?php endif; ?>
                    <a href="?page=<?php echo $totalPages; ?>" class="page-link"><?php echo $totalPages; ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="page-link"><i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function markAsReadPage(notificationId) {
        fetch('/gorwanda-plus/admin/ajax/mark-notification-read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                notification_id: notificationId
            })
        }).then(() => {
            const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
            if (item) {
                item.classList.remove('unread');
                item.classList.add('read');
                updateUnreadCount();
            }
        });
    }

    function markAllAsReadPage() {
        fetch('/gorwanda-plus/admin/ajax/mark-all-read.php', {
                method: 'POST'
            })
            .then(() => {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                    item.classList.add('read');
                });
                updateUnreadCount();
            });
    }

    function deleteNotificationPage(notificationId) {
        if (confirm('Delete this notification?')) {
            fetch('/gorwanda-plus/admin/ajax/delete-notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    notification_id: notificationId
                })
            }).then(() => {
                const item = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                if (item) item.remove();
                updateUnreadCount();
            });
        }
    }

    function filterNotifications(type) {
        const items = document.querySelectorAll('.notification-item');
        let visibleCount = 0;

        items.forEach(item => {
            if (type === 'unread') {
                if (item.classList.contains('unread')) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            } else {
                item.style.display = 'flex';
                visibleCount++;
            }
        });

        // Update active tab
        document.querySelectorAll('.filter-tab').forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.trim().toLowerCase() === type ||
                (type === 'all' && btn.textContent.trim() === 'All')) {
                btn.classList.add('active');
            }
        });

        // Show empty state if no visible items
        const list = document.getElementById('notificationsList');
        const emptyState = list.querySelector('.empty-state');
        if (visibleCount === 0 && !emptyState) {
            list.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                <div class="empty-title">No ${type} notifications</div>
                <div class="empty-text">You have no ${type} notifications at this time</div>
            </div>
        `;
        } else if (visibleCount > 0 && emptyState) {
            // Reload the page to restore items (simple solution)
            location.reload();
        }
    }

    function updateUnreadCount() {
        const unreadCount = document.querySelectorAll('.notification-item.unread').length;
        const badge = document.querySelector('.stat-card .stat-value');
        const headerBadge = document.querySelector('.notifications-header h1 .notification-badge');

        if (headerBadge) {
            if (unreadCount > 0) {
                headerBadge.textContent = unreadCount + ' new';
                headerBadge.style.display = 'inline-block';
            } else {
                headerBadge.style.display = 'none';
            }
        }

        // Update stats card
        const statValues = document.querySelectorAll('.stat-value');
        if (statValues[1]) {
            statValues[1].textContent = unreadCount;
        }
    }

    function getNotificationIcon(type) {
        const icons = {
            'new_booking': 'calendar-check',
            'booking_cancelled': 'calendar-x',
            'payment_received': 'credit-card',
            'vendor_registration': 'building',
            'verification_pending': 'shield-check',
            'low_inventory': 'exclamation-triangle',
            'new_review': 'star',
            'system_alert': 'gear',
            'daily_summary': 'graph-up',
            'payout_processed': 'wallet2'
        };
        return icons[type] || 'bell';
    }

    // Prevent click propagation on notification details links
    document.querySelectorAll('.notification-details, .notification-meta, .notification-title, .notification-message').forEach(el => {
        el.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
</script>

<?php require_once 'includes/admin_footer.php'; ?>