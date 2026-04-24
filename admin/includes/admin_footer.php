</main>
</div>

<!-- ============================================ -->
<!-- ALL JAVASCRIPT MOVED HERE - AFTER CONTENT -->
<!-- ============================================ -->
<script>
    // ============================================
    // SIDEBAR TOGGLE
    // ============================================
    function toggleSidebar() {
        document.getElementById('adminSidebar').classList.toggle('open');
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('adminSidebar');
        const toggle = document.getElementById('menuToggle');

        if (window.innerWidth <= 992 &&
            sidebar && toggle &&
            !sidebar.contains(event.target) &&
            !toggle.contains(event.target) &&
            sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

    // ============================================
    // AI INSIGHTS - DYNAMIC FROM DATABASE
    // ============================================
    let currentInsights = [];
    let insightIndex = 0;
    let insightInterval;

    function loadAIInsights() {
        const contentDiv = document.getElementById('aiSuggestionContent');
        const actionLink = document.getElementById('aiSuggestionAction');
        const timestampDiv = document.getElementById('aiTimestamp');

        if (!contentDiv) return;

        contentDiv.innerHTML = '<div class="ai-loading"><i class="bi bi-hourglass-split"></i> Analyzing platform data...</div>';

        fetch('/gorwanda-plus/admin/includes/ai_insights.php?ajax=1')
            .then(response => response.json())
            .then(data => {
                currentInsights = data;
                insightIndex = 0;

                if (currentInsights.length === 0) {
                    contentDiv.innerHTML = '<div class="ai-loading"><i class="bi bi-check-circle"></i> All systems operational. No insights at this time.</div>';
                    if (actionLink) actionLink.style.display = 'none';
                    if (timestampDiv) timestampDiv.innerHTML = '';
                    return;
                }

                if (actionLink) actionLink.style.display = 'inline-flex';
                showNextInsight();

                if (insightInterval) clearInterval(insightInterval);
                insightInterval = setInterval(showNextInsight, 15000);

                if (timestampDiv) {
                    timestampDiv.innerHTML = '<i class="bi bi-clock"></i> Updated ' + new Date().toLocaleTimeString();
                }
            })
            .catch(error => {
                console.error('Error loading insights:', error);
                contentDiv.innerHTML = '<div class="ai-loading"><i class="bi bi-exclamation-triangle"></i> Unable to load insights. Please refresh.</div>';
                if (actionLink) actionLink.style.display = 'none';
            });
    }

    function showNextInsight() {
        if (currentInsights.length === 0) return;

        const insight = currentInsights[insightIndex % currentInsights.length];
        const contentDiv = document.getElementById('aiSuggestionContent');
        const actionLink = document.getElementById('aiSuggestionAction');

        if (!contentDiv) return;

        let priorityClass = '';
        if (insight.priority === 1) priorityClass = 'ai-insight-high';
        else if (insight.priority === 2) priorityClass = 'ai-insight-medium';
        else priorityClass = 'ai-insight-info';

        contentDiv.innerHTML = `<div class="${priorityClass}" style="padding-left: 8px;">${insight.content}</div>`;
        if (actionLink) {
            actionLink.href = insight.action_link;
            actionLink.innerHTML = `${insight.action_text} <i class="bi bi-arrow-right"></i>`;
        }

        const headerIcon = document.querySelector('.ai-suggestion-header i:first-child');
        if (headerIcon) {
            headerIcon.className = `bi bi-${insight.icon}`;
        }

        insightIndex++;
    }

    function refreshAIInsights() {
        loadAIInsights();
    }

    // ============================================
    // NOTIFICATION SYSTEM
    // ============================================
    let notificationInterval;

    function toggleNotificationPanel() {
        const panel = document.getElementById('notificationPanel');
        if (!panel) return;
        panel.classList.toggle('show');

        if (panel.classList.contains('show')) {
            markNotificationsAsSeen();
        }
    }

    function markNotificationsAsSeen() {
        fetch('/gorwanda-plus/admin/ajax/mark-notifications-seen.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            }
        }).catch(console.error);
    }

    function markAsRead(notificationId) {
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
            }
            updateUnreadCount();
        }).catch(console.error);
    }

    function markAllAsRead() {
        fetch('/gorwanda-plus/admin/ajax/mark-all-read.php', {
                method: 'POST'
            })
            .then(() => {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                    item.classList.add('read');
                });
                updateUnreadCount();
            }).catch(console.error);
    }

    function deleteNotification(notificationId) {
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
            }).catch(console.error);
        }
    }

    function updateUnreadCount() {
        const unreadCount = document.querySelectorAll('.notification-item.unread').length;
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            if (unreadCount > 0) {
                badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
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

    function startNotificationRefresh() {
        if (notificationInterval) clearInterval(notificationInterval);
        notificationInterval = setInterval(() => {
            fetch('/gorwanda-plus/admin/ajax/get-notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.unread_count > 0) {
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                            badge.style.display = 'inline-block';
                        }
                    }
                })
                .catch(console.error);
        }, 30000);
    }

    // ============================================
    // GLOBAL SEARCH
    // ============================================
    document.getElementById('globalSearch')?.addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            const searchTerm = this.value;
            if (searchTerm) {
                window.location.href = 'search.php?q=' + encodeURIComponent(searchTerm);
            }
        }
    });

    // ============================================
    // NOTIFICATIONS TOGGLE
    // ============================================
    function toggleNotifications() {
        toggleNotificationPanel();
    }

    // ============================================
    // DARK MODE TOGGLE
    // ============================================
    function toggleDarkMode() {
        document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
    }

    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }

    // ============================================
    // CHART FORMATTING
    // ============================================
    function formatCurrency(amount) {
        return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    // ============================================
    // TIME AGO
    // ============================================
    function timeAgo(timestamp) {
        const now = new Date();
        const past = new Date(timestamp);
        const seconds = Math.floor((now - past) / 1000);

        const intervals = {
            year: 31536000,
            month: 2592000,
            week: 604800,
            day: 86400,
            hour: 3600,
            minute: 60
        };

        for (const [unit, secondsInUnit] of Object.entries(intervals)) {
            const interval = Math.floor(seconds / secondsInUnit);
            if (interval >= 1) {
                return interval + ' ' + unit + (interval === 1 ? '' : 's') + ' ago';
            }
        }
        return 'just now';
    }

    // ============================================
    // INITIALIZE ALL ON PAGE LOAD
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        loadAIInsights();
        startNotificationRefresh();

        document.addEventListener('click', function(e) {
            const panel = document.getElementById('notificationPanel');
            const icon = document.querySelector('.notification-icon');
            if (panel && icon && !icon.contains(e.target) && !panel.contains(e.target)) {
                panel.classList.remove('show');
            }
        });
    });

    window.addEventListener('beforeunload', function() {
        if (insightInterval) clearInterval(insightInterval);
        if (notificationInterval) clearInterval(notificationInterval);
    });
</script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isset($extraJS)) echo $extraJS; ?>
</body>

</html>
<?php ob_end_flush(); ?>