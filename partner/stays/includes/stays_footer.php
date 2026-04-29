        </main>
        </div>

        <script>
            // ============================================
            // SIDEBAR TOGGLE
            // ============================================
            const sidebarState = localStorage.getItem('partnerSidebarCollapsed');
            let isSidebarCollapsed = sidebarState === 'true';

            function updateSidebarState() {
                const sidebar = document.getElementById('partnerSidebar');
                const toggleIcon = document.getElementById('toggleIcon');
                const overlay = document.getElementById('sidebarOverlay');
                const isMobile = window.innerWidth <= 768;

                if (isMobile) {
                    overlay.classList.remove('active');
                    sidebar.classList.add('collapsed');
                    toggleIcon.className = 'bi bi-list';
                } else {
                    overlay.classList.remove('active');
                    if (isSidebarCollapsed) {
                        sidebar.classList.add('collapsed');
                        toggleIcon.className = 'bi bi-list';
                    } else {
                        sidebar.classList.remove('collapsed');
                        toggleIcon.className = 'bi bi-chevron-left';
                    }
                }

                localStorage.setItem('partnerSidebarCollapsed', isSidebarCollapsed);
            }

            function toggleSidebar() {
                const sidebar = document.getElementById('partnerSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const isMobile = window.innerWidth <= 768;

                if (isMobile) {
                    if (sidebar.classList.contains('mobile-open')) {
                        sidebar.classList.remove('mobile-open');
                        sidebar.classList.add('collapsed');
                        overlay.classList.remove('active');
                    } else {
                        sidebar.classList.remove('collapsed');
                        sidebar.classList.add('mobile-open');
                        overlay.classList.add('active');
                    }
                } else {
                    isSidebarCollapsed = !isSidebarCollapsed;
                    updateSidebarState();
                }
            }

            function closeSidebar() {
                const sidebar = document.getElementById('partnerSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.remove('mobile-open');
                sidebar.classList.add('collapsed');
                overlay.classList.remove('active');
            }

            updateSidebarState();

            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(updateSidebarState, 150);
            });

            // Keyboard shortcut: Ctrl+B or Cmd+B
            document.addEventListener('keydown', function(event) {
                if ((event.ctrlKey || event.metaKey) && event.key === 'b') {
                    event.preventDefault();
                    toggleSidebar();
                }
            });
            // ============================================
            // NOTIFICATION SYSTEM
            // ============================================
            let notificationRefreshInterval;

            function toggleNotificationPanel() {
                const panel = document.getElementById('notificationPanel');
                if (!panel) return;

                if (panel.classList.contains('show')) {
                    panel.classList.remove('show');
                } else {
                    panel.classList.add('show');
                    fetchNotifications();
                }
            }

            function fetchNotifications() {
                const list = document.getElementById('notificationList');
                if (!list) return;

                // Show loading state
                list.innerHTML = `
        <div class="notification-empty">
            <div style="width: 24px; height: 24px; border: 3px solid #e7e7e7; border-top-color: #003b95; border-radius: 50%; animation: spin 0.6s linear infinite; margin: 0 auto 12px;"></div>
            <p style="font-size: 0.75rem;">Loading notifications...</p>
        </div>`;

                fetch('/gorwanda-plus/partner/stays/ajax/get-notifications.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        updateNotificationUI(data);
                    })
                    .catch(error => {
                        console.error('Error fetching notifications:', error);
                        if (list) {
                            list.innerHTML = `
                    <div class="notification-empty">
                        <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem; color: #e21111;"></i>
                        <p style="font-size: 0.75rem; margin-top: 8px;">Failed to load notifications</p>
                        <button onclick="fetchNotifications()" style="margin-top: 8px; padding: 4px 12px; border: 1px solid #e7e7e7; background: white; border-radius: 6px; cursor: pointer; font-size: 0.75rem;">
                            <i class="bi bi-arrow-clockwise"></i> Retry
                        </button>
                    </div>`;
                        }
                    });
            }

            function updateNotificationUI(data) {
                const list = document.getElementById('notificationList');
                const badge = document.getElementById('notificationBadge');
                const markAllBtn = document.getElementById('markAllReadBtn');

                // Update badge
                if (badge) {
                    if (data.unread_count > 0) {
                        badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }

                // Update mark all button
                if (markAllBtn) {
                    markAllBtn.style.display = data.unread_count > 0 ? 'inline-block' : 'none';
                }

                // Update notification list
                if (list) {
                    if (!data.notifications || data.notifications.length === 0) {
                        list.innerHTML = `
                <div class="notification-empty">
                    <i class="bi bi-bell-slash" style="font-size: 2rem;"></i>
                    <p>No notifications yet</p>
                </div>`;
                        return;
                    }

                    list.innerHTML = data.notifications.map(n => `
            <div class="notification-item ${n.is_read ? '' : 'unread'}" 
                 style="position: relative; cursor: pointer;"
                 onclick="handleNotificationClick(${n.id}, '${n.type}', '${encodeURIComponent(JSON.stringify(n.data || {}))}')">
                <div class="notification-icon-type">
                    <i class="bi bi-${n.icon}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${escapeHtml(n.title)}</div>
                    <div class="notification-message">${escapeHtml(n.message)}</div>
                    <div class="notification-time">${n.time_ago}</div>
                </div>
                <div class="notification-actions" onclick="event.stopPropagation();">
                    ${!n.is_read ? `
                    <button class="notif-action" onclick="markNotificationRead(${n.id})" title="Mark as read">
                        <i class="bi bi-check"></i>
                    </button>` : ''}
                    <button class="notif-action" onclick="deleteNotification(${n.id})" title="Delete">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </div>
        `).join('');
                }
            }

            function handleNotificationClick(id, type, encodedData) {
                // Mark as read
                markNotificationRead(id);

                // Navigate based on notification type
                const routes = {
                    'new_booking': 'bookings.php',
                    'payment_received': 'bookings.php',
                    'booking_cancelled': 'bookings.php',
                    'booking_confirmed': 'bookings.php',
                    'new_review': 'reviews.php',
                    'checkin_reminder': 'bookings.php?status=confirmed',
                    'checkout_reminder': 'bookings.php?status=confirmed',
                    'low_inventory': 'rooms.php',
                    'system_alert': 'dashboard.php',
                };

                const url = routes[type] || 'dashboard.php';
                window.location.href = url;
            }

            function markNotificationRead(notificationId) {
                fetch('/gorwanda-plus/partner/stays/ajax/mark-notification-read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            notification_id: notificationId
                        })
                    })
                    .then(response => response.json())
                    .then(() => {
                        fetchNotifications();
                    })
                    .catch(console.error);
            }

            function markAllNotificationsRead() {
                fetch('/gorwanda-plus/partner/stays/ajax/mark-all-read.php', {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(() => {
                        fetchNotifications();
                        showToast('All notifications marked as read', 'success');
                    })
                    .catch(console.error);
            }

            function deleteNotification(notificationId) {
                if (!confirm('Delete this notification?')) return;

                fetch('/gorwanda-plus/partner/stays/ajax/delete-notification.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            notification_id: notificationId
                        })
                    })
                    .then(response => response.json())
                    .then(() => {
                        fetchNotifications();
                    })
                    .catch(console.error);
            }

            function startNotificationPolling() {
                // Clear existing interval
                if (notificationRefreshInterval) clearInterval(notificationRefreshInterval);

                // Poll every 30 seconds (only update badge, not full list)
                notificationRefreshInterval = setInterval(() => {
                    fetch('/gorwanda-plus/partner/stays/ajax/get-notifications.php')
                        .then(response => response.json())
                        .then(data => {
                            const badge = document.getElementById('notificationBadge');
                            if (badge) {
                                if (data.unread_count > 0) {
                                    badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                                    badge.style.display = 'inline-block';
                                } else {
                                    badge.style.display = 'none';
                                }
                            }
                        })
                        .catch(() => {}); // Silent fail for polling
                }, 30000);
            }

            // Toast notification helper
            function showToast(message, type = 'success') {
                const toast = document.createElement('div');
                const colors = {
                    success: {
                        bg: '#e6f4ea',
                        color: '#008009',
                        icon: 'check-circle'
                    },
                    error: {
                        bg: '#fce8e8',
                        color: '#e21111',
                        icon: 'exclamation-triangle'
                    },
                    warning: {
                        bg: '#fff4e6',
                        color: '#e67e22',
                        icon: 'info-circle'
                    }
                };
                const c = colors[type] || colors.success;

                toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 12px 20px;
        background: ${c.bg};
        color: ${c.color};
        border-radius: 8px;
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        font-size: 0.875rem;
        font-family: 'Inter', sans-serif;
        animation: slideInRight 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    `;
                toast.innerHTML = `<i class="bi bi-${c.icon}-fill"></i> ${message}`;
                document.body.appendChild(toast);

                setTimeout(() => {
                    toast.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, 4000);
            }

            // Close notification panel when clicking outside
            document.addEventListener('click', function(event) {
                const panel = document.getElementById('notificationPanel');
                const icon = document.querySelector('.notification-icon');
                if (panel && icon && !icon.contains(event.target) && !panel.contains(event.target)) {
                    panel.classList.remove('show');
                }
            });

            // Escape HTML helper
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            // Initialize on page load
            document.addEventListener('DOMContentLoaded', function() {
                startNotificationPolling();
            });

            // Clean up polling on page unload
            window.addEventListener('beforeunload', function() {
                if (notificationRefreshInterval) clearInterval(notificationRefreshInterval);
            });

            // Add spinner animation
            const notifStyle = document.createElement('style');
            notifStyle.textContent = `
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
            document.head.appendChild(notifStyle);




            // ============================================
            // GLOBAL SEARCH
            // ============================================
            document.getElementById('globalSearch')?.addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    const searchTerm = this.value.trim();
                    if (searchTerm) {
                        window.location.href = 'bookings.php?search=' + encodeURIComponent(searchTerm);
                    }
                }
            });

            // ============================================
            // FORMAT CURRENCY
            // ============================================
            function formatCurrency(amount) {
                return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
            }

            // ============================================
            // SHOW NOTIFICATION TOAST
            // ============================================
            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                padding: 12px 20px;
                background: ${type === 'success' ? '#e6f4ea' : type === 'error' ? '#fce8e8' : '#fff4e6'};
                color: ${type === 'success' ? '#008009' : type === 'error' ? '#e21111' : '#e67e22'};
                border-radius: 8px;
                box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
                font-size: 0.875rem;
                font-family: 'Inter', sans-serif;
                animation: slideInRight 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
                max-width: 400px;
            `;
                notification.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'}-fill"></i>
                <span>${message}</span>
            `;
                document.body.appendChild(notification);

                setTimeout(() => {
                    notification.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }, 4000);
            }

            // ============================================
            // ANIMATIONS
            // ============================================
            const style = document.createElement('style');
            style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
        `;
            document.head.appendChild(style);
        </script>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

        <?php if (isset($extraJS)) echo $extraJS; ?>
        </body>

        </html>
        <?php ob_end_flush(); ?>