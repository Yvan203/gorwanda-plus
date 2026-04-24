<?php
$pageTitle = 'Notifications';
require_once 'includes/admin_header.php';
require_once '../includes/notifications.php';

$nm = new NotificationManager();
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 30;
$offset = ($page - 1) * $perPage;

$notifications = $nm->getNotifications($userId, $perPage, $offset);
$totalNotifications = $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId")->fetchColumn();
$totalPages = ceil($totalNotifications / $perPage);
?>

<style>
    .notifications-page {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        overflow: hidden;
    }

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
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .notifications-list {
        min-height: 400px;
    }

    .notification-item-page {
        padding: 16px 24px;
        border-bottom: 1px solid var(--booking-border);
        display: flex;
        gap: 16px;
        transition: background var(--transition-fast);
    }

    .notification-item-page:hover {
        background: var(--booking-gray-light);
    }

    .notification-item-page.unread {
        background: rgba(0, 102, 255, 0.03);
    }

    .notification-icon-large {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .notification-content-page {
        flex: 1;
    }

    .notification-title-page {
        font-size: 0.875rem;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .notification-message-page {
        font-size: 0.75rem;
        color: var(--booking-text-light);
        margin-bottom: 8px;
    }

    .notification-data {
        font-size: 0.6875rem;
        color: var(--booking-text-lighter);
        background: var(--booking-gray-light);
        padding: 8px;
        border-radius: var(--radius-sm);
        margin-top: 8px;
        font-family: monospace;
    }

    .notification-meta {
        display: flex;
        gap: 16px;
        font-size: 0.625rem;
        color: var(--booking-text-lighter);
    }

    .pagination {
        padding: 20px 24px;
        border-top: 1px solid var(--booking-border);
    }
</style>

<div class="notifications-page">
    <div class="notifications-header">
        <h1><i class="bi bi-bell"></i> All Notifications</h1>
        <button class="filter-btn" onclick="markAllAsReadPage()" style="padding: 6px 16px;">
            <i class="bi bi-check2-all"></i> Mark all as read
        </button>
    </div>

    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <div style="text-align: center; padding: 60px;">
                <i class="bi bi-bell-slash" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
                <p style="margin-top: 16px;">No notifications yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item-page <?php echo $notif['is_read'] ? 'read' : 'unread'; ?>"
                    data-id="<?php echo $notif['notification_id']; ?>">
                    <div class="notification-icon-large">
                        <i class="bi bi-<?php echo getNotificationIcon($notif['type']); ?>"></i>
                    </div>
                    <div class="notification-content-page">
                        <div class="notification-title-page"><?php echo htmlspecialchars($notif['title']); ?></div>
                        <div class="notification-message-page"><?php echo htmlspecialchars($notif['message']); ?></div>
                        <?php if ($notif['data']):
                            $data = json_decode($notif['data'], true);
                        ?>
                            <div class="notification-data">
                                <pre style="margin: 0; font-size: 0.625rem;"><?php echo print_r($data, true); ?></pre>
                            </div>
                        <?php endif; ?>
                        <div class="notification-meta">
                            <span><i class="bi bi-clock"></i> <?php echo timeAgo($notif['created_at']); ?></span>
                            <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="notification-actions-page">
                        <?php if (!$notif['is_read']): ?>
                            <button class="backup-action-btn" onclick="markAsReadPage(<?php echo $notif['notification_id']; ?>)">
                                <i class="bi bi-check"></i> Mark read
                            </button>
                        <?php endif; ?>
                        <button class="backup-action-btn danger" onclick="deleteNotificationPage(<?php echo $notif['notification_id']; ?>)">
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
                <a href="?page=<?php echo $page - 1; ?>" class="page-link">Previous</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>" class="page-link">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
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
            const item = document.querySelector(`.notification-item-page[data-id="${notificationId}"]`);
            if (item) {
                item.classList.remove('unread');
                item.classList.add('read');
            }
        });
    }

    function markAllAsReadPage() {
        fetch('/gorwanda-plus/admin/ajax/mark-all-read.php', {
                method: 'POST'
            })
            .then(() => {
                document.querySelectorAll('.notification-item-page.unread').forEach(item => {
                    item.classList.remove('unread');
                    item.classList.add('read');
                });
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
                const item = document.querySelector(`.notification-item-page[data-id="${notificationId}"]`);
                if (item) item.remove();
            });
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
</script>

<?php require_once 'includes/admin_footer.php'; ?>