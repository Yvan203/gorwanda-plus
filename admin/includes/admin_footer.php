        </main>
        </div>

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
                    !sidebar.contains(event.target) &&
                    !toggle.contains(event.target) &&
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            });



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
                // This would open a notifications dropdown in production
                alert('Notifications panel would open here');
            }

            // ============================================
            // DARK MODE TOGGLE (Optional)
            // ============================================
            function toggleDarkMode() {
                document.body.classList.toggle('dark-mode');
                localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
            }

            // Check for saved dark mode preference
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
            // EXPORT DATA
            // ============================================
            function exportData(data, filename = 'export.csv') {
                const blob = new Blob([data], {
                    type: 'text/csv'
                });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                window.URL.revokeObjectURL(url);
            }

            // ============================================
            // SHOW NOTIFICATION
            // ============================================
            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.className = `notification-toast notification-${type}`;
                notification.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 10000;
            padding: 12px 20px;
            background: ${type === 'success' ? '#e6f4ea' : type === 'error' ? '#fce8e8' : '#fff4e6'};
            color: ${type === 'success' ? '#008009' : type === 'error' ? '#e21111' : '#ff8c00'};
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 0.8125rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid ${type === 'success' ? '#008009' : type === 'error' ? '#e21111' : '#ff8c00'};
        `;
                notification.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle-fill' : type === 'error' ? 'exclamation-triangle-fill' : 'info-circle-fill'}"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;"><i class="bi bi-x"></i></button>
        `;
                document.body.appendChild(notification);

                setTimeout(() => {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }, 5000);
            }

            // Add animations
            const style = document.createElement('style');
            style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* Dark Mode Styles */
        body.dark-mode {
            --booking-white: #1a1a1a;
            --booking-gray-light: #2d2d2d;
            --booking-gray: #333333;
            --booking-border: #404040;
            --booking-text: #e5e5e5;
            --booking-text-light: #a0a0a0;
            --booking-text-lighter: #808080;
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