<?php
// Start output buffering
ob_start();

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+ Admin' : 'GoRwanda+ Admin Dashboard';

// Get unread notifications count
$stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadNotifications = $stmt->fetchColumn();

// Get pending verifications
$stmt = $db->query("SELECT COUNT(*) FROM stays WHERE is_verified = 0 AND is_active = 1");
$pendingStays = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM car_rentals WHERE is_verified = 0 AND is_active = 1");
$pendingCars = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM attractions WHERE is_verified = 0 AND is_active = 1");
$pendingAttractions = $stmt->fetchColumn();

$totalPending = $pendingStays + $pendingCars + $pendingAttractions;

// Get pending payouts
$stmt = $db->prepare("SELECT COUNT(*) FROM payouts WHERE status = 'pending'");
$stmt->execute();
$pendingPayouts = $stmt->fetchColumn();

// Get today's revenue
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE DATE(created_at) = CURDATE() AND status IN ('confirmed', 'completed')");
$stmt->execute();
$todayRevenue = $stmt->fetchColumn();

// Get current admin user
$stmt = $db->prepare("SELECT first_name, last_name, email, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#003b95">
    <title><?php echo $pageTitle; ?></title>
    
    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="/gorwanda-plus/assets/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/gorwanda-plus/assets/images/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/gorwanda-plus/assets/images/favicon-16x16.png">
    <link rel="manifest" href="/gorwanda-plus/web-app-manifest-192x192.png">
    <link rel="manifest" href="/gorwanda-plus/web-app-manifest-512x512.png">
    <link rel="mask-icon" href="/gorwanda-plus/assets/images/safari-pinned-tab.svg" color="#003b95">
    <meta name="msapplication-TileColor" content="#003b95">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Google Fonts - Inter (Booking.com font) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    
    <style>
        /* ===== BOOKING.COM ADMIN STYLES ===== */
        :root {
            --booking-blue: #003b95;
            --booking-blue-light: #0066ff;
            --booking-blue-dark: #00224f;
            --booking-yellow: #febb02;
            --booking-gray: #f5f5f5;
            --booking-gray-light: #f8f9fa;
            --booking-gray-dark: #e7e7e7;
            --booking-text: #1a1a1a;
            --booking-text-light: #595959;
            --booking-text-lighter: #9ca3af;
            --booking-border: #e7e7e7;
            --booking-success: #008009;
            --booking-warning: #ff8c00;
            --booking-danger: #e21111;
            --booking-info: #17a2b8;
            --booking-white: #ffffff;
            --sidebar-width: 260px;
            --header-height: 60px;
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;
            --font-size-xs: 11px;
            --font-size-sm: 12px;
            --font-size-md: 13px;
            --font-size-base: 14px;
            --font-size-lg: 15px;
            --font-size-xl: 16px;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --transition-fast: 0.2s ease;
            --transition-base: 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            font-size: 14px;
            scroll-behavior: smooth;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 13px;
            line-height: 1.5;
            color: var(--booking-text);
            background: var(--booking-gray);
            overflow-x: hidden;
        }
        
        /* ===== ADMIN WRAPPER ===== */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* ===== SIDEBAR ===== */
        .admin-sidebar {
            width: var(--sidebar-width);
            background: var(--booking-white);
            border-right: 1px solid var(--booking-border);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform var(--transition-base);
            box-shadow: var(--shadow-sm);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-white);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .sidebar-brand {
            display: block;
            text-align: center;
            text-decoration: none;
        }
        
        .sidebar-brand img {
            max-width: 100%;
            height: auto;
            transition: transform var(--transition-fast);
        }
        
        .sidebar-brand:hover img {
            transform: scale(1.02);
        }
        
        .sidebar-user {
            padding: 16px 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-gray-light);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
            color: var(--booking-white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .user-details {
            flex: 1;
            min-width: 0;
        }
        
        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--booking-text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-role {
            font-size: 0.6875rem;
            color: var(--booking-text-light);
            background: rgba(0,102,255,0.1);
            padding: 2px 8px;
            border-radius: 100px;
            display: inline-block;
        }
        
        /* Sidebar Navigation */
        .sidebar-nav {
            padding: 16px 12px;
        }
        
        .nav-section {
            margin-bottom: 24px;
        }
        
        .nav-section-title {
            font-size: 0.625rem;
            font-weight: 600;
            color: var(--booking-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0 8px;
            margin-bottom: 8px;
        }
        
        .nav-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .nav-item {
            margin: 2px 0;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            color: var(--booking-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all var(--transition-fast);
            position: relative;
        }
        
        .nav-link:hover {
            background: var(--booking-gray-light);
            color: var(--booking-blue);
        }
        
        .nav-link.active {
            background: var(--booking-gray-light);
            color: var(--booking-blue);
            font-weight: 600;
        }
        
        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 20px;
            background: var(--booking-blue);
            border-radius: 0 2px 2px 0;
        }
        
        .nav-link i {
            font-size: 1.125rem;
            width: 24px;
            text-align: center;
        }
        
        .nav-badge {
            margin-left: auto;
            background: var(--booking-danger);
            color: var(--booking-white);
            padding: 2px 6px;
            border-radius: 100px;
            font-size: 0.5625rem;
            font-weight: 600;
            min-width: 18px;
            text-align: center;
        }
        
        .nav-badge.success {
            background: var(--booking-success);
        }
        
        .nav-badge.warning {
            background: var(--booking-warning);
        }
        
        .nav-badge.info {
            background: var(--booking-info);
        }
        
        /* AI Suggestion Card */
        .ai-suggestion-card {
            margin: 16px 12px;
            padding: 12px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f4ea 100%);
            border-radius: var(--radius-md);
            border: 1px solid rgba(0,102,255,0.2);
            position: relative;
            overflow: hidden;
            animation: glowPulse 2s infinite;
        }
        
        @keyframes glowPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(0,102,255,0.2); }
            50% { box-shadow: 0 0 0 4px rgba(0,102,255,0.1); }
        }
        
        .ai-suggestion-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .ai-suggestion-header i {
            font-size: 1.125rem;
            color: var(--booking-blue);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .ai-suggestion-header span {
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--booking-blue);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ai-suggestion-content {
            font-size: 0.6875rem;
            color: var(--booking-text);
            line-height: 1.4;
            margin-bottom: 8px;
        }
        
        .ai-suggestion-action {
            font-size: 0.625rem;
            color: var(--booking-blue);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 500;
        }
        
        .ai-suggestion-action:hover {
            text-decoration: underline;
        }
        
        /* ===== MAIN CONTENT ===== */
        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            background: var(--booking-gray);
        }
        
        /* ===== TOP BAR ===== */
        .top-bar {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-border);
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: var(--shadow-sm);
        }
        
        .page-title h1 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--booking-text);
            margin: 0;
        }
        
        .page-title p {
            font-size: 0.6875rem;
            color: var(--booking-text-light);
            margin: 2px 0 0 0;
        }
        
        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        /* Search Bar */
        .search-bar {
            position: relative;
        }
        
        .search-bar input {
            width: 240px;
            padding: 8px 12px 8px 32px;
            border: 1px solid var(--booking-border);
            border-radius: 20px;
            font-size: 0.75rem;
            transition: all var(--transition-fast);
            background: var(--booking-gray-light);
        }
        
        .search-bar input:focus {
            outline: none;
            border-color: var(--booking-blue);
            width: 280px;
            background: var(--booking-white);
        }
        
        .search-bar i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--booking-text-light);
            font-size: 0.75rem;
        }
        
        /* Notification Icon */
        .notification-icon {
            position: relative;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background var(--transition-fast);
        }
        
        .notification-icon:hover {
            background: var(--booking-gray-light);
        }
        
        .notification-icon i {
            font-size: 1.125rem;
            color: var(--booking-text-light);
        }
        
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: var(--booking-danger);
            color: var(--booking-white);
            font-size: 0.5625rem;
            font-weight: 600;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 16px;
            text-align: center;
        }
        
        /* Profile Dropdown */
        .profile-dropdown {
            position: relative;
            cursor: pointer;
        }
        
        .profile-trigger {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 8px;
            border-radius: 30px;
            transition: background var(--transition-fast);
        }
        
        .profile-trigger:hover {
            background: var(--booking-gray-light);
        }
        
        .profile-avatar-small {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--booking-white);
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .profile-avatar-small img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .profile-name {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--booking-text);
        }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 100%;
            right: 0;
            background: var(--booking-white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            min-width: 240px;
            padding: 8px;
            margin-top: 8px;
            z-index: 1000;
            display: none;
            border: 1px solid var(--booking-border);
        }
        
        .profile-dropdown:hover .dropdown-menu-custom {
            display: block;
            animation: fadeIn 0.2s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .dropdown-item-custom {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            color: var(--booking-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            transition: background var(--transition-fast);
        }
        
        .dropdown-item-custom:hover {
            background: var(--booking-gray-light);
        }
        
        .dropdown-item-custom i {
            font-size: 1rem;
            width: 20px;
            color: var(--booking-text-light);
        }
        
        .dropdown-divider-custom {
            height: 1px;
            background: var(--booking-border);
            margin: 8px 0;
        }
        
        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            padding: 8px;
            border-radius: var(--radius-sm);
            color: var(--booking-text);
        }
        
        .menu-toggle:hover {
            background: var(--booking-gray-light);
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            
            .admin-sidebar.open {
                transform: translateX(0);
            }
            
            .admin-main {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .search-bar input {
                width: 160px;
            }
            
            .search-bar input:focus {
                width: 200px;
            }
        }
        
        @media (max-width: 576px) {
            .top-bar {
                padding: 10px 16px;
            }
            
            .page-title h1 {
                font-size: 1rem;
            }
            
            .profile-name {
                display: none;
            }
            
            .search-bar input {
                width: 120px;
            }
            
            .search-bar input:focus {
                width: 160px;
            }
        }
        
        /* ===== UTILITIES ===== */
        .text-truncate-custom {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--booking-gray-light);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--booking-border);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--booking-text-light);
        }
    </style>
    
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <a href="dashboard.php" class="sidebar-brand">
                    <img src="/gorwanda-plus/assets/images/go.png" 
                         alt="GoRwanda+" 
                         style="max-width: 100%; height: auto;"
                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span style="display: none; font-size: 1.25rem; font-weight: 700; color: var(--booking-blue);">GoRwanda+</span>
                </a>
            </div>
            
            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($admin['profile_image'])): ?>
                            <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $admin['profile_image']; ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($admin['first_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo sanitize($admin['first_name'] . ' ' . $admin['last_name']); ?></div>
                        <div class="user-role">Super Administrator</div>
                    </div>
                </div>
            </div>
            
            <!-- AI Suggestion Card (Dynamic) -->
            <div class="ai-suggestion-card" id="aiSuggestionCard">
                <div class="ai-suggestion-header">
                    <i class="bi bi-stars"></i>
                    <span>AI Insight</span>
                </div>
                <div class="ai-suggestion-content" id="aiSuggestionContent">
                    Loading insights...
                </div>
                <a href="#" class="ai-suggestion-action" id="aiSuggestionAction">
                    Learn more <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            
            <nav class="sidebar-nav">
                <!-- Main -->
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">
                                <i class="bi bi-speedometer2"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="analytics.php" class="nav-link <?php echo $currentPage == 'analytics.php' ? 'active' : ''; ?>">
                                <i class="bi bi-graph-up-arrow"></i>
                                Analytics
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Management -->
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="stays.php" class="nav-link <?php echo $currentPage == 'stays.php' ? 'active' : ''; ?>">
                                <i class="bi bi-building"></i>
                                Stays
                                <?php if ($pendingStays > 0): ?>
                                <span class="nav-badge warning"><?php echo $pendingStays; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="cars.php" class="nav-link <?php echo $currentPage == 'cars.php' ? 'active' : ''; ?>">
                                <i class="bi bi-car-front"></i>
                                Car Rentals
                                <?php if ($pendingCars > 0): ?>
                                <span class="nav-badge warning"><?php echo $pendingCars; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="attractions.php" class="nav-link <?php echo $currentPage == 'attractions.php' ? 'active' : ''; ?>">
                                <i class="bi bi-ticket-perforated"></i>
                                Experiences
                                <?php if ($pendingAttractions > 0): ?>
                                <span class="nav-badge warning"><?php echo $pendingAttractions; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="restaurants.php" class="nav-link <?php echo $currentPage == 'restaurants.php' ? 'active' : ''; ?>">
                                <i class="bi bi-shop"></i>
                                Restaurants
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="users.php" class="nav-link <?php echo $currentPage == 'users.php' ? 'active' : ''; ?>">
                                <i class="bi bi-people"></i>
                                Users
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Operations -->
                <div class="nav-section">
                    <div class="nav-section-title">Operations</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="bookings.php" class="nav-link <?php echo $currentPage == 'bookings.php' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-check"></i>
                                Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="payments.php" class="nav-link <?php echo $currentPage == 'payments.php' ? 'active' : ''; ?>">
                                <i class="bi bi-cash-stack"></i>
                                Payments
                                <?php if ($pendingPayouts > 0): ?>
                                <span class="nav-badge"><?php echo $pendingPayouts; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="reviews.php" class="nav-link <?php echo $currentPage == 'reviews.php' ? 'active' : ''; ?>">
                                <i class="bi bi-star"></i>
                                Reviews
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="messages.php" class="nav-link <?php echo $currentPage == 'messages.php' ? 'active' : ''; ?>">
                                <i class="bi bi-chat-dots"></i>
                                Messages
                                <?php if ($unreadNotifications > 0): ?>
                                <span class="nav-badge"><?php echo $unreadNotifications; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Financial -->
                <div class="nav-section">
                    <div class="nav-section-title">Financial</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="commission.php" class="nav-link <?php echo $currentPage == 'commission.php' ? 'active' : ''; ?>">
                                <i class="bi bi-percent"></i>
                                Commission
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="payouts.php" class="nav-link <?php echo $currentPage == 'payouts.php' ? 'active' : ''; ?>">
                                <i class="bi bi-wallet2"></i>
                                Payouts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="taxes.php" class="nav-link <?php echo $currentPage == 'taxes.php' ? 'active' : ''; ?>">
                                <i class="bi bi-receipt"></i>
                                Taxes
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- System -->
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="settings.php" class="nav-link <?php echo $currentPage == 'settings.php' ? 'active' : ''; ?>">
                                <i class="bi bi-gear"></i>
                                Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="logs.php" class="nav-link <?php echo $currentPage == 'logs.php' ? 'active' : ''; ?>">
                                <i class="bi bi-file-text"></i>
                                Activity Logs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="backup.php" class="nav-link <?php echo $currentPage == 'backup.php' ? 'active' : ''; ?>">
                                <i class="bi bi-database"></i>
                                Backup
                            </a>
                        </li>
                    </ul>
                </div>
                
                <!-- Support -->
                <div class="nav-section">
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="/gorwanda-plus/" class="nav-link">
                                <i class="bi bi-house"></i>
                                View Site
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/gorwanda-plus/logout.php" class="nav-link" style="color: var(--booking-danger);">
                                <i class="bi bi-box-arrow-right"></i>
                                Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="admin-main">
            <!-- Top Bar -->
            <div class="top-bar">
                <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                
                <div class="page-title">
                    <h1><?php echo $pageTitle; ?></h1>
                    <p><?php echo date('l, F j, Y'); ?></p>
                </div>
                
                <div class="top-actions">
                    <div class="search-bar">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search..." id="globalSearch">
                    </div>
                    
                    <div class="notification-icon" onclick="toggleNotifications()">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-badge"><?php echo $unreadNotifications > 9 ? '9+' : $unreadNotifications; ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="profile-dropdown">
                        <div class="profile-trigger">
                            <div class="profile-avatar-small">
                                <?php if (!empty($admin['profile_image'])): ?>
                                    <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $admin['profile_image']; ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($admin['first_name'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <span class="profile-name"><?php echo sanitize($admin['first_name']); ?></span>
                            <i class="bi bi-chevron-down" style="font-size: 0.75rem;"></i>
                        </div>
                        <div class="dropdown-menu-custom">
                            <a href="profile.php" class="dropdown-item-custom">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                            <a href="settings.php" class="dropdown-item-custom">
                                <i class="bi bi-gear"></i> Settings
                            </a>
                            <div class="dropdown-divider-custom"></div>
                            <a href="/gorwanda-plus/" class="dropdown-item-custom">
                                <i class="bi bi-house"></i> View Site
                            </a>
                            <a href="/gorwanda-plus/logout.php" class="dropdown-item-custom">
                                <i class="bi bi-box-arrow-right"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </div>