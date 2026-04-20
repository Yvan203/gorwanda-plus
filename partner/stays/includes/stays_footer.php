        </main>
    </div>

    <script>
    // Sidebar Toggle
    function toggleSidebar() {
        document.getElementById('partnerSidebar').classList.toggle('open');
    }

    // Check screen size for mobile
    function checkScreenSize() {
        if (window.innerWidth <= 768) {
            document.getElementById('menuToggle').style.display = 'block';
        } else {
            document.getElementById('menuToggle').style.display = 'none';
            document.getElementById('partnerSidebar').classList.remove('open');
        }
    }

    window.addEventListener('resize', checkScreenSize);
    checkScreenSize();

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('partnerSidebar');
        const toggle = document.getElementById('menuToggle');
        
        if (window.innerWidth <= 768 && 
            !sidebar.contains(event.target) && 
            !toggle.contains(event.target) &&
            sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

    // Format currency
    function formatCurrency(amount) {
        return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
    }

    // Show notification
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            padding: 12px 20px;
            background: ${type === 'success' ? '#e6f4ea' : '#fce8e8'};
            color: ${type === 'success' ? 'var(--booking-success)' : 'var(--booking-danger)'};
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            font-size: 0.875rem;
            animation: slideIn 0.3s ease;
        `;
        notification.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
            ${message}
        `;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Add animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
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