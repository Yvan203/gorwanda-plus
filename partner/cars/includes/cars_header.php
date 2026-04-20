<?php
// Start output buffering
ob_start();

require_once dirname(__DIR__, 3) . '/includes/functions.php';

// Check if user is logged in and is a business owner with car access
if (!isLoggedIn() || !isBusinessOwner()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Verify user has car rental business type
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

if (!in_array('car_rental', $businessTypes)) {
    // Redirect to appropriate dashboard
    if (!empty($businessTypes)) {
        $firstType = $businessTypes[0];
        switch($firstType) {
            case 'stay':
                header('Location: /gorwanda-plus/partner/stays/dashboard.php');
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
         JOIN car_fleet cf ON b.car_id = cf.car_id
         JOIN car_rentals cr ON cf.rental_id = cr.rental_id
         WHERE cr.owner_id = ? AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN car_fleet cf ON b.car_id = cf.car_id
         JOIN car_rentals cr ON cf.rental_id = cr.rental_id
         WHERE cr.owner_id = ? AND b.pickup_date = CURDATE() AND b.status = 'confirmed') as pickups_today,
        (SELECT COUNT(*) FROM car_fleet cf
         JOIN car_rentals cr ON cf.rental_id = cr.rental_id
         WHERE cr.owner_id = ? AND cf.quantity_available < 3) as low_stock
");
$stmt->execute([$userId, $userId, $userId]);
$notifications = $stmt->fetch();

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+ Cars' : 'GoRwanda+ Cars';
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
    
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    
    <style>
        :root {
            --cars-primary: #ff8c00;
            --cars-dark: #cc7000;
            --cars-light: #fff4e6;
            --cars-success: #008009;
            --cars-warning: #ff8c00;
            --cars-danger: #e21111;
            --bg-gray: #f8f9fa;
            --border-gray: #e7e7e7;
            --text-dark: #1a1a1a;
            --text-light: #6b6b6b;
            --white: #ffffff;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.1);
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        html {
            font-size: 14px;
        }

        body {
            background: var(--bg-gray);
            color: var(--text-dark);
            margin: 0;
            padding: 0;
        }

        /* Partner Wrapper */
        .partner-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Cars Theme */
        .partner-sidebar {
            width: 260px;
            background: var(--white);
            border-right: 1px solid var(--border-gray);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid var(--border-gray);
            background: var(--white);
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
            color: var(--cars-primary);
            text-decoration: none;
        }

        .sidebar-brand i {
            font-size: 1.5rem;
        }

        .sidebar-brand span {
            color: var(--cars-dark);
        }

        .industry-badge {
            display: inline-block;
            padding: 4px 12px;
            background: var(--cars-light);
            color: var(--cars-primary);
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .sidebar-user {
            padding: 20px;
            border-bottom: 1px solid var(--border-gray);
            background: var(--cars-light);
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
            background: var(--cars-primary);
            color: var(--white);
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
            color: var(--text-dark);
            margin-bottom: 2px;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--text-light);
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
            color: var(--text-light);
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
            color: var(--text-dark);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: var(--cars-light);
            color: var(--cars-primary);
        }

        .nav-link.active {
            background: var(--cars-light);
            color: var(--cars-primary);
            font-weight: 600;
        }

        .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 20px;
            background: var(--cars-primary);
            border-radius: 0 4px 4px 0;
        }

        .nav-link i {
            font-size: 1.125rem;
            width: 24px;
            text-align: center;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--cars-danger);
            color: var(--white);
            padding: 2px 6px;
            border-radius: 100px;
            font-size: 0.6875rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .nav-badge.warning {
            background: var(--cars-warning);
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
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-gray);
            box-shadow: var(--shadow-sm);
        }

        .page-title h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 4px 0;
        }

        .page-title p {
            font-size: 0.8125rem;
            color: var(--text-light);
            margin: 0;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Buttons - Cars Theme */
        .btn-primary {
            background: var(--cars-primary);
            color: var(--white);
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
            background: var(--cars-dark);
        }

        .btn-secondary {
            background: var(--white);
            color: var(--text-dark);
            border: 1px solid var(--border-gray);
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
            background: var(--bg-gray);
            border-color: var(--text-light);
        }

        .btn-outline {
            background: transparent;
            color: var(--cars-primary);
            border: 1px solid var(--cars-primary);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-outline:hover {
            background: var(--cars-light);
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-gray);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-gray);
            background: var(--white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--text-dark);
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
            background: var(--white);
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--border-gray);
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

        .stat-icon.orange { background: var(--cars-light); color: var(--cars-primary); }
        .stat-icon.green { background: #e6f4ea; color: var(--cars-success); }
        .stat-icon.blue { background: #e1f5fe; color: #0288d1; }
        .stat-icon.purple { background: #f3e8ff; color: #9333ea; }

        .stat-trend {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 100px;
            background: #e6f4ea;
            color: var(--cars-success);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .stat-footer {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-gray);
            font-size: 0.75rem;
            color: var(--text-light);
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
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            background: var(--bg-gray);
            border-bottom: 1px solid var(--border-gray);
        }

        .table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-gray);
            vertical-align: middle;
        }

        .table tr:hover td {
            background: var(--cars-light);
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

        .status-available {
            background: #e6f4ea;
            color: var(--cars-success);
        }

        .status-rented {
            background: var(--cars-light);
            color: var(--cars-primary);
        }

        .status-maintenance {
            background: #fce8e8;
            color: var(--cars-danger);
        }

        .status-pending {
            background: #fff4e6;
            color: var(--cars-warning);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                <div class="industry-badge">
                    <i class="bi bi-car-front"></i> Cars Partner
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

 <!-- Fleet Management -->
<div class="nav-section">
    <div class="nav-section-title">Fleet</div>
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="fleet.php" class="nav-link <?php echo $currentPage == 'fleet.php' ? 'active' : ''; ?>">
                <i class="bi bi-car-front"></i>
                All Vehicles
            </a>
        </li>
        <li class="nav-item">
            <a href="add-vehicle.php" class="nav-link <?php echo $currentPage == 'add-vehicle.php' ? 'active' : ''; ?>">
                <i class="bi bi-plus-circle"></i>
                Add Vehicle
            </a>
        </li>
        <!-- NEW: Photo Gallery Link -->
        <li class="nav-item">
            <a href="photos.php" class="nav-link <?php echo $currentPage == 'photos.php' ? 'active' : ''; ?>">
                <i class="bi bi-images"></i>
                Photo Gallery
            </a>
        </li>
        <li class="nav-item">
            <a href="maintenance.php" class="nav-link <?php echo $currentPage == 'maintenance.php' ? 'active' : ''; ?>">
                <i class="bi bi-tools"></i>
                Maintenance
                <?php if (($notifications['low_stock'] ?? 0) > 0): ?>
                <span class="nav-badge warning"><?php echo $notifications['low_stock']; ?></span>
                <?php endif; ?>
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
                                Availability
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="pickups.php" class="nav-link <?php echo $currentPage == 'pickups.php' ? 'active' : ''; ?>">
                                <i class="bi bi-arrow-up-circle"></i>
                                Today's Pickups
                                <?php if (($notifications['pickups_today'] ?? 0) > 0): ?>
                                <span class="nav-badge success"><?php echo $notifications['pickups_today']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="returns.php" class="nav-link <?php echo $currentPage == 'returns.php' ? 'active' : ''; ?>">
                                <i class="bi bi-arrow-down-circle"></i>
                                Returns
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Pricing -->
                <div class="nav-section">
                    <div class="nav-section-title">Pricing</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="rates.php" class="nav-link <?php echo $currentPage == 'rates.php' ? 'active' : ''; ?>">
                                <i class="bi bi-cash-stack"></i>
                                Rate Plans
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="discounts.php" class="nav-link <?php echo $currentPage == 'discounts.php' ? 'active' : ''; ?>">
                                <i class="bi bi-tag"></i>
                                Discounts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="seasons.php" class="nav-link <?php echo $currentPage == 'seasons.php' ? 'active' : ''; ?>">
                                <i class="bi bi-flower1"></i>
                                Seasonal Pricing
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
                            <a href="locations.php" class="nav-link <?php echo $currentPage == 'locations.php' ? 'active' : ''; ?>">
                                <i class="bi bi-geo-alt"></i>
                                Pickup Locations
                            </a>
                        </li>
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
                            <a href="/gorwanda-plus/logout.php" class="nav-link" style="color: var(--cars-danger);">
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