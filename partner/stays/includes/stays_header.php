<?php
// Start output buffering
ob_start();

require_once dirname(__DIR__, 3) . '/includes/functions.php';

// Check if user is logged in and is a business owner with stay access
if (!isLoggedIn() || !isBusinessOwner()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Verify user has stay business type
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

if (!in_array('stay', $businessTypes)) {
    // Redirect to appropriate dashboard
    if (!empty($businessTypes)) {
        $firstType = $businessTypes[0];
        switch($firstType) {
            case 'car_rental':
                header('Location: /gorwanda-plus/partner/cars/dashboard.php');
                exit;
            case 'attraction':
                header('Location: /gorwanda-plus/partner/experiences/dashboard.php');
                exit;
        }
    }
    header('Location: /gorwanda-plus/partner/dashboard.php');
    exit;
}

// Get user details
$stmt = $db->prepare("SELECT first_name, last_name, email, profile_image FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get notification counts
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
         JOIN stays s ON sr.stay_id = s.stay_id
         WHERE s.owner_id = ? AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
         JOIN stays s ON sr.stay_id = s.stay_id
         WHERE s.owner_id = ? AND b.check_in_date = CURDATE() AND b.status = 'confirmed') as checkins_today,
        (SELECT COUNT(*) FROM reviews r
         JOIN stays s ON r.stay_id = s.stay_id
         WHERE s.owner_id = ? AND DATE(r.created_at) = CURDATE()) as new_reviews
");
$stmt->execute([$userId, $userId, $userId]);
$notifications = $stmt->fetch();

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+ Partner' : 'GoRwanda+ Partner';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <!-- RealFaviconGenerator generated favicon -->
<link rel="apple-touch-icon" sizes="180x180" href="/gorwanda-plus/assets/images/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/gorwanda-plus/assets/images/favicon-96x96.png">
<link rel="icon" type="image/png" sizes="16x16" href="/gorwanda-plus/assets/images/favicon-16x16.png">
<link rel="manifest" href="/gorwanda-plus/web-app-manifest-192x192.png">
<link rel="manifest" href="/gorwanda-plus/web-app-manifest-512x512.png">
<link rel="mask-icon" href="/gorwanda-plus/assets/images/safari-pinned-tab.svg" color="#003b95">
<meta name="msapplication-TileColor" content="#003b95">
<meta name="theme-color" content="#003b95">


    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Google Fonts - Inter (Booking.com uses similar font) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    
    <style>
        /* Booking.com Exact Styling */
        :root {
            --booking-blue: #003b95;
            --booking-dark-blue: #00224f;
            --booking-light-blue: #f0f4ff;
            --booking-yellow: #febb02;
            --booking-gray: #f5f5f5;
            --booking-border: #e7e7e7;
            --booking-text: #1a1a1a;
            --booking-text-light: #6b6b6b;
            --booking-text-lighter: #9ca3af;
            --booking-success: #008009;
            --booking-warning: #ff8c00;
            --booking-danger: #e21111;
            --booking-white: #ffffff;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        html {
            font-size: 14px; /* Booking.com uses 14px base */
        }

        body {
            background: var(--booking-gray);
            color: var(--booking-text);
            margin: 0;
            padding: 0;
        }

        /* Partner Wrapper */
        .partner-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Booking.com Style */
        .partner-sidebar {
            width: 260px;
            background: var(--booking-white);
            border-right: 1px solid var(--booking-border);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-white);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--booking-blue);
            text-decoration: none;
        }

        .sidebar-brand i {
            font-size: 1.5rem;
        }

        .sidebar-brand span {
            color: var(--booking-dark-blue);
        }

        .property-badge {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 12px;
            background: var(--booking-light-blue);
            color: var(--booking-blue);
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .sidebar-user {
            padding: 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-gray);
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
            background: var(--booking-blue);
            color: var(--booking-white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.9375rem;
            color: var(--booking-text);
            margin-bottom: 2px;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--booking-text-light);
        }

        .sidebar-nav {
            padding: 20px 12px;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            font-size: 0.6875rem;
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
            padding: 10px 12px;
            color: var(--booking-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: var(--booking-light-blue);
            color: var(--booking-blue);
        }

        .nav-link.active {
            background: var(--booking-light-blue);
            color: var(--booking-blue);
            font-weight: 600;
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
            font-size: 0.6875rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .nav-badge.success {
            background: var(--booking-success);
        }

        .nav-badge.warning {
            background: var(--booking-warning);
        }

        /* Main Content */
        .partner-main {
            flex: 1;
            margin-left: 260px;
            padding: 24px 32px;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: var(--booking-white);
            border-radius: var(--radius-md);
            border: 1px solid var(--booking-border);
            box-shadow: var(--shadow-sm);
        }

        .page-title h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--booking-text);
            margin: 0 0 4px 0;
        }

        .page-title p {
            font-size: 0.8125rem;
            color: var(--booking-text-light);
            margin: 0;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Buttons - Booking.com Style */
        .btn-primary {
            background: var(--booking-blue);
            color: var(--booking-white);
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary:hover {
            background: var(--booking-dark-blue);
        }

        .btn-secondary {
            background: var(--booking-white);
            color: var(--booking-text);
            border: 1px solid var(--booking-border);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-secondary:hover {
            background: var(--booking-gray);
            border-color: var(--booking-text-light);
        }

        .btn-outline {
            background: transparent;
            color: var(--booking-blue);
            border: 1px solid var(--booking-blue);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-outline:hover {
            background: var(--booking-light-blue);
        }

        /* Cards */
        .card {
            background: var(--booking-white);
            border-radius: var(--radius-md);
            border: 1px solid var(--booking-border);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--booking-text);
            margin: 0;
        }

        .card-body {
            padding: 20px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--booking-white);
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--booking-border);
            transition: all 0.2s;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.blue { background: var(--booking-light-blue); color: var(--booking-blue); }
        .stat-icon.green { background: #e6f4ea; color: var(--booking-success); }
        .stat-icon.orange { background: #fff4e6; color: var(--booking-warning); }
        .stat-icon.purple { background: #f3e8ff; color: #9333ea; }

        .stat-trend {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 100px;
            background: #e6f4ea;
            color: var(--booking-success);
        }

        .stat-trend.down {
            background: #fce8e8;
            color: var(--booking-danger);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--booking-text);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--booking-text-light);
            font-weight: 500;
        }

        .stat-footer {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--booking-border);
            font-size: 0.75rem;
            color: var(--booking-text-light);
            display: flex;
            justify-content: space-between;
        }

        /* Tables */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }

        .table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--booking-text-light);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background: var(--booking-gray);
            border-bottom: 1px solid var(--booking-border);
        }

        .table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--booking-border);
            vertical-align: middle;
        }

        .table tr:hover td {
            background: var(--booking-light-blue);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 100px;
            font-size: 0.6875rem;
            font-weight: 600;
        }

        .status-confirmed {
            background: #e6f4ea;
            color: var(--booking-success);
        }

        .status-pending {
            background: #fff4e6;
            color: var(--booking-warning);
        }

        .status-cancelled {
            background: #fce8e8;
            color: var(--booking-danger);
        }

        .status-completed {
            background: var(--booking-light-blue);
            color: var(--booking-blue);
        }

        /* Property Cards */
        .property-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .property-card {
            border: 1px solid var(--booking-border);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: all 0.2s;
            background: var(--booking-white);
        }

        .property-card:hover {
            box-shadow: var(--shadow-md);
        }

        .property-image {
            height: 140px;
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .property-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 8px;
            border-radius: 100px;
            font-size: 0.6875rem;
            font-weight: 600;
        }

        .property-status.verified {
            background: var(--booking-success);
            color: var(--booking-white);
        }

        .property-status.pending {
            background: var(--booking-warning);
            color: var(--booking-white);
        }

        .property-details {
            padding: 16px;
        }

        .property-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--booking-text);
            margin-bottom: 4px;
        }

        .property-location {
            font-size: 0.75rem;
            color: var(--booking-text-light);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .property-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: var(--booking-text-light);
            border-top: 1px solid var(--booking-border);
            padding-top: 12px;
        }

        .property-rating {
            color: var(--booking-warning);
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .property-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .partner-sidebar {
                transform: translateX(-100%);
            }
            .partner-sidebar.open {
                transform: translateX(0);
            }
            .partner-main {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }
    </style>
    
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>
<body>
    <div class="partner-wrapper">
        <!-- Sidebar -->
        <aside class="partner-sidebar" id="partnerSidebar">
<div class="sidebar-header">
    <a href="dashboard.php" class="sidebar-brand">
        <img src="/gorwanda-plus/assets/images/go.png" 
             alt="GoRwanda+" 
             style="height: 120px; width: 150px;"
             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">

    </a>
    <div class="property-badge">
        <i class="bi bi-house-door"></i> Stays Partner
    </div>
</div>
            
            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['first_name'] ?? 'P', 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo sanitize($user['first_name'] ?? 'Partner') . ' ' . sanitize(substr($user['last_name'] ?? '', 0, 1) . '.'); ?></div>
                        <div class="user-email"><?php echo sanitize($user['email'] ?? ''); ?></div>
                    </div>
                </div>
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
                    </ul>
                </div>

                <!-- Properties -->
                <div class="nav-section">
                    <div class="nav-section-title">Properties</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="properties.php" class="nav-link <?php echo $currentPage == 'properties.php' ? 'active' : ''; ?>">
                                <i class="bi bi-building"></i>
                                All Properties
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="property-add.php" class="nav-link <?php echo $currentPage == 'property-add.php' ? 'active' : ''; ?>">
                                <i class="bi bi-plus-circle"></i>
                                Add Property
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="rooms.php" class="nav-link <?php echo $currentPage == 'rooms.php' ? 'active' : ''; ?>">
                                <i class="bi bi-door-open"></i>
                                Room Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="amenities.php" class="nav-link <?php echo $currentPage == 'amenities.php' ? 'active' : ''; ?>">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                Amenities
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="photos.php" class="nav-link <?php echo $currentPage == 'photos.php' ? 'active' : ''; ?>">
                                <i class="bi bi-images"></i>
                                Photo Gallery
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
                                <?php if (($notifications['pending_bookings'] ?? 0) > 0): ?>
                                <span class="nav-badge"><?php echo $notifications['pending_bookings']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="calendar.php" class="nav-link <?php echo $currentPage == 'calendar.php' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-week"></i>
                                Availability Calendar
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="rates.php" class="nav-link <?php echo $currentPage == 'rates.php' ? 'active' : ''; ?>">
                                <i class="bi bi-cash-stack"></i>
                                Rates & Pricing
                            </a>
                        </li>
<li class="nav-item">
    <a href="restaurant-management.php" class="nav-link <?php echo $currentPage == 'restaurant-management.php' ? 'active' : ''; ?>">
        <i class="bi bi-shop"></i>
        Restaurant Management
    </a>
</li>
                    </ul>
                </div>

                <!-- Guest Experience -->
                <div class="nav-section">
                    <div class="nav-section-title">Guest Experience</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="reviews.php" class="nav-link <?php echo $currentPage == 'reviews.php' ? 'active' : ''; ?>">
                                <i class="bi bi-star"></i>
                                Reviews
                                <?php if (($notifications['new_reviews'] ?? 0) > 0): ?>
                                <span class="nav-badge success"><?php echo $notifications['new_reviews']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="messages.php" class="nav-link <?php echo $currentPage == 'messages.php' ? 'active' : ''; ?>">
                                <i class="bi bi-chat-dots"></i>
                                Guest Messages
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="policies.php" class="nav-link <?php echo $currentPage == 'policies.php' ? 'active' : ''; ?>">
                                <i class="bi bi-file-text"></i>
                                House Rules
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Analytics -->
                <div class="nav-section">
                    <div class="nav-section-title">Analytics</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="analytics.php" class="nav-link <?php echo $currentPage == 'analytics.php' ? 'active' : ''; ?>">
                                <i class="bi bi-graph-up-arrow"></i>
                                Performance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="reports.php" class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>">
                                <i class="bi bi-file-earmark-bar-graph"></i>
                                Reports
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Settings -->
                <div class="nav-section">
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="settings.php" class="nav-link <?php echo $currentPage == 'settings.php' ? 'active' : ''; ?>">
                                <i class="bi bi-gear"></i>
                                Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/gorwanda-plus/" class="nav-link">
                                <i class="bi bi-house"></i>
                                View Site
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/gorwanda-plus/logout.php" class="nav-link" style="color: var(--booking-danger);">
                                <i class="bi bi-box-arrow-right"></i>
                                Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="partner-main">
            <!-- Mobile Menu Toggle -->
            <button class="btn-secondary" style="margin-bottom: 20px; display: none;" id="menuToggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i> Menu
            </button>