<?php
// Start output buffering
ob_start();

require_once dirname(__DIR__, 3) . '/includes/functions.php';

// Check if user is logged in and is a business owner with experience access
if (!isLoggedIn() || !isBusinessOwner()) {
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Verify user has experience business type
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

if (!in_array('attraction', $businessTypes)) {
    // Redirect to appropriate dashboard
    if (!empty($businessTypes)) {
        $firstType = $businessTypes[0];
        switch($firstType) {
            case 'stay':
                header('Location: /gorwanda-plus/partner/stays/dashboard.php');
                exit;
            case 'car_rental':
                header('Location: /gorwanda-plus/partner/cars/dashboard.php');
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
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
         JOIN attractions a ON at.attraction_id = a.attraction_id
         WHERE a.owner_id = ? AND b.status = 'pending') as pending_bookings,
        (SELECT COUNT(*) FROM bookings b 
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
         JOIN attractions a ON at.attraction_id = a.attraction_id
         WHERE a.owner_id = ? AND DATE(b.experience_date) = CURDATE() AND b.status = 'confirmed') as experiences_today,
        (SELECT COUNT(*) FROM reviews r
         JOIN attractions a ON r.attraction_id = a.attraction_id
         WHERE a.owner_id = ? AND DATE(r.created_at) = CURDATE()) as new_reviews
");
$stmt->execute([$userId, $userId, $userId]);
$notifications = $stmt->fetch();

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+ Experiences Partner' : 'GoRwanda+ Experiences Partner';
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
            --exp-purple: #9333ea;
            --exp-dark-purple: #7e22ce;
            --exp-light-purple: #f3e8ff;
            --exp-success: #10b981;
            --exp-warning: #f59e0b;
            --exp-danger: #ef4444;
            --exp-gray: #f3f4f6;
            --exp-border: #e5e7eb;
            --exp-text: #111827;
            --exp-text-light: #6b7280;
            --exp-white: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
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
            background: var(--exp-gray);
            color: var(--exp-text);
            margin: 0;
            padding: 0;
        }

        /* Partner Wrapper */
        .partner-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Experience Purple Theme */
        .partner-sidebar {
            width: 260px;
            background: var(--exp-white);
            border-right: 1px solid var(--exp-border);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid var(--exp-border);
            background: var(--exp-white);
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
            color: var(--exp-purple);
            text-decoration: none;
        }

        .sidebar-brand i {
            font-size: 1.5rem;
        }

        .sidebar-brand span {
            color: var(--exp-dark-purple);
        }

        .industry-badge {
            display: inline-block;
            padding: 4px 12px;
            background: var(--exp-light-purple);
            color: var(--exp-purple);
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .sidebar-user {
            padding: 20px;
            border-bottom: 1px solid var(--exp-border);
            background: var(--exp-light-purple);
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
            background: var(--exp-purple);
            color: var(--exp-white);
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
            color: var(--exp-text);
            margin-bottom: 2px;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--exp-text-light);
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
            color: var(--exp-text-light);
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
            color: var(--exp-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
        }

        .nav-link:hover {
            background: var(--exp-light-purple);
            color: var(--exp-purple);
        }

        .nav-link.active {
            background: var(--exp-light-purple);
            color: var(--exp-purple);
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
            background: var(--exp-purple);
            border-radius: 0 4px 4px 0;
        }

        .nav-link i {
            font-size: 1.125rem;
            width: 24px;
            text-align: center;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--exp-danger);
            color: var(--exp-white);
            padding: 2px 6px;
            border-radius: 100px;
            font-size: 0.6875rem;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .nav-badge.success {
            background: var(--exp-success);
        }

        .nav-badge.warning {
            background: var(--exp-warning);
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
            background: var(--exp-white);
            border-radius: var(--radius-md);
            border: 1px solid var(--exp-border);
            box-shadow: var(--shadow-sm);
        }

        .page-title h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--exp-text);
            margin: 0 0 4px 0;
        }

        .page-title p {
            font-size: 0.8125rem;
            color: var(--exp-text-light);
            margin: 0;
        }

        .top-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Buttons */
        .btn-primary {
            background: var(--exp-purple);
            color: var(--exp-white);
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
            background: var(--exp-dark-purple);
        }

        .btn-secondary {
            background: var(--exp-white);
            color: var(--exp-text);
            border: 1px solid var(--exp-border);
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
            background: var(--exp-gray);
            border-color: var(--exp-text-light);
        }

        .btn-outline {
            background: transparent;
            color: var(--exp-purple);
            border: 1px solid var(--exp-purple);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-outline:hover {
            background: var(--exp-light-purple);
        }

        /* Cards */
        .card {
            background: var(--exp-white);
            border-radius: var(--radius-md);
            border: 1px solid var(--exp-border);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--exp-border);
            background: var(--exp-white);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--exp-text);
            margin: 0;
        }

        .card-body {
            padding: 20px;
        }

        /* Responsive */
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
                    <i class="bi bi-ticket-perforated"></i> Experiences Partner
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

<!-- Experiences -->
<div class="nav-section">
    <div class="nav-section-title">Experiences</div>
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="listings.php" class="nav-link <?php echo $currentPage == 'listings.php' ? 'active' : ''; ?>">
                <i class="bi bi-ticket-perforated"></i>
                All Experiences
            </a>
        </li>
        <li class="nav-item">
            <a href="add-listing.php" class="nav-link <?php echo $currentPage == 'add-listing.php' ? 'active' : ''; ?>">
                <i class="bi bi-plus-circle"></i>
                Add Experience
            </a>
        </li>
        <li class="nav-item">
            <a href="tiers.php" class="nav-link <?php echo $currentPage == 'tiers.php' ? 'active' : ''; ?>">
                <i class="bi bi-layers"></i>
                Pricing Tiers
            </a>
        </li>
        <li class="nav-item">
            <a href="schedule.php" class="nav-link <?php echo $currentPage == 'schedule.php' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-week"></i>
                Schedule
            </a>
        </li>
        <!-- NEW: Photos Section -->
        <li class="nav-item">
            <a href="photos.php" class="nav-link <?php echo $currentPage == 'photos.php' ? 'active' : ''; ?>">
                <i class="bi bi-images"></i>
                Photo Gallery
                <?php 
                // Get count of experiences with images
                $stmt = $db->prepare("
                    SELECT COUNT(*) as total 
                    FROM attractions 
                    WHERE owner_id = ? 
                    AND (main_image IS NOT NULL OR gallery_images IS NOT NULL)
                ");
                $stmt->execute([$userId]);
                $imageCount = $stmt->fetchColumn();
                if ($imageCount > 0): 
                ?>
                <span class="nav-badge success"><?php echo $imageCount; ?></span>
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
            <a href="messages.php" class="nav-link <?php echo $currentPage == 'messages.php' ? 'active' : ''; ?>">
                <i class="bi bi-chat-dots"></i>
                Messages
                <?php 
                // Get unread message count
                $db = getDB();
                $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
                $stmt->execute([$_SESSION['user_id']]);
                $unreadCount = $stmt->fetchColumn();
                if ($unreadCount > 0): 
                ?>
                <span class="nav-badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="availability.php" class="nav-link <?php echo $currentPage == 'availability.php' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-week"></i>
                Availability
            </a>
        </li>
    </ul>
</div>

                <!-- Reviews -->
                <div class="nav-section">
                    <div class="nav-section-title">Reviews</div>
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="reviews.php" class="nav-link <?php echo $currentPage == 'reviews.php' ? 'active' : ''; ?>">
                                <i class="bi bi-star"></i>
                                Guest Reviews
                                <?php if (($notifications['new_reviews'] ?? 0) > 0): ?>
                                <span class="nav-badge success"><?php echo $notifications['new_reviews']; ?></span>
                                <?php endif; ?>
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
                            <a href="/gorwanda-plus/logout.php" class="nav-link" style="color: var(--exp-danger);">
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