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
    if (!empty($businessTypes)) {
        $firstType = $businessTypes[0];
        switch ($firstType) {
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
        (SELECT COUNT(*) FROM bookings b 
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
         JOIN stays s ON sr.stay_id = s.stay_id
         WHERE s.owner_id = ? AND b.check_out_date = CURDATE() AND b.status = 'confirmed') as checkouts_today,
        (SELECT COUNT(*) FROM reviews r
         JOIN stays s ON r.stay_id = s.stay_id
         WHERE s.owner_id = ? AND DATE(r.created_at) = CURDATE()) as new_reviews,
        (SELECT COUNT(*) FROM messages m
         JOIN bookings b ON m.booking_id = b.booking_id
         JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
         JOIN stays s ON sr.stay_id = s.stay_id
         WHERE s.owner_id = ? AND m.receiver_id = ? AND m.is_read = 0) as unread_messages
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
$notifications = $stmt->fetch();

// Get recent notifications
$stmt = $db->prepare("
    SELECT 
        n.*,
        TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
    FROM notifications n
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$recentNotifications = $stmt->fetchAll();

$unreadNotificationCount = 0;
foreach ($recentNotifications as $n) {
    if (!$n['is_read']) $unreadNotificationCount++;
}

// Get user's properties
$stmt = $db->prepare("SELECT stay_id, stay_name FROM stays WHERE owner_id = ? ORDER BY stay_name");
$stmt->execute([$userId]);
$userProperties = $stmt->fetchAll();

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+ Partner' : 'GoRwanda+ Partner';
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

    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <style>
        :root {
            --booking-blue: #003b95;
            --booking-dark-blue: #00224f;
            --booking-light-blue: #f0f4ff;
            --booking-yellow: #febb02;
            --booking-gray: #f5f5f5;
            --booking-gray-light: #f8f9fa;
            --booking-border: #e7e7e7;
            --booking-text: #1a1a1a;
            --booking-text-light: #6b6b6b;
            --booking-text-lighter: #9ca3af;
            --booking-success: #008009;
            --booking-warning: #ff8c00;
            --booking-danger: #e21111;
            --booking-white: #ffffff;
            --sidebar-width: 260px;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
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
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--booking-text);
            background: var(--booking-gray);
            overflow-x: hidden;
        }

        /* Partner Wrapper */
        .partner-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .partner-sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            background: var(--booking-white);
            border-right: 1px solid var(--booking-border);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
            transition: transform var(--transition-base);
            box-shadow: var(--shadow-sm);
        }

        .partner-sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--booking-border);
            background: var(--booking-white);
            position: sticky;
            top: 0;
            z-index: 10;
            text-align: center;
        }

        .sidebar-header img {
            max-width: 120px;
            height: auto;
        }

        .property-badge {
            display: inline-block;
            margin-top: 10px;
            padding: 4px 12px;
            background: var(--booking-light-blue);
            color: var(--booking-blue);
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
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
            background: linear-gradient(135deg, var(--booking-blue), var(--booking-dark-blue));
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
            font-size: 0.9375rem;
            color: var(--booking-text);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-email {
            font-size: 0.75rem;
            color: var(--booking-text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-nav {
            padding: 16px 12px;
        }

        .nav-section {
            margin-bottom: 20px;
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
            gap: 10px;
            padding: 9px 12px;
            color: var(--booking-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all var(--transition-fast);
            position: relative;
            white-space: nowrap;
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
            flex-shrink: 0;
        }

        .nav-badge {
            margin-left: auto;
            background: var(--booking-danger);
            color: var(--booking-white);
            padding: 2px 8px;
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

        /* Sidebar Toggle Button */
        .sidebar-toggle {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1001;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--booking-white);
            border: 1px solid var(--booking-border);
            color: var(--booking-text);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: var(--shadow-md);
            transition: all var(--transition-base);
        }

        .sidebar-toggle:hover {
            background: var(--booking-light-blue);
            color: var(--booking-blue);
        }

        .partner-sidebar:not(.collapsed)~.sidebar-toggle {
            left: calc(var(--sidebar-width) - 50px);
            background: transparent;
            border: none;
            box-shadow: none;
            color: var(--booking-text-light);
        }

        .partner-sidebar:not(.collapsed)~.sidebar-toggle:hover {
            color: var(--booking-danger);
            background: rgba(226, 17, 17, 0.1);
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Main Content */
        .partner-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left var(--transition-base);
        }

        .partner-sidebar.collapsed~.partner-main {
            margin-left: 0;
        }

        .partner-sidebar.collapsed~.sidebar-toggle {
            left: 16px;
            background: var(--booking-white);
            border: 1px solid var(--booking-border);
            box-shadow: var(--shadow-md);
        }

        /* Top Bar */
        .top-bar {
            background: var(--booking-white);
            border-bottom: 1px solid var(--booking-border);
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 998;
            box-shadow: var(--shadow-sm);
        }

        .page-title h1 {
            font-size: 1.125rem;
            font-weight: 700;
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
            width: 220px;
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
            width: 260px;
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

        /* Notification Dropdown */
        .notification-dropdown {
            position: relative;
        }

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
            top: -2px;
            right: -2px;
            background: var(--booking-danger);
            color: var(--booking-white);
            font-size: 0.5625rem;
            font-weight: 600;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 16px;
            text-align: center;
        }

        .notification-panel {
            position: absolute;
            top: 100%;
            right: 0;
            width: 360px;
            background: var(--booking-white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--booking-border);
            z-index: 1000;
            display: none;
            margin-top: 8px;
            overflow: hidden;
        }

        .notification-panel.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notification-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--booking-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--booking-gray-light);
        }

        .notification-header h4 {
            font-size: 0.8125rem;
            font-weight: 700;
            margin: 0;
        }

        .notification-list {
            max-height: 350px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 14px 16px;
            border-bottom: 1px solid var(--booking-border);
            display: flex;
            gap: 12px;
            transition: background var(--transition-fast);
            cursor: pointer;
        }

        .notification-item:hover {
            background: var(--booking-gray-light);
        }

        .notification-item.unread {
            background: rgba(0, 59, 149, 0.03);
        }

        .notification-item.unread::before {
            content: '';
            width: 8px;
            height: 8px;
            background: var(--booking-blue);
            border-radius: 50%;
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
        }

        .notification-icon-type {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(0, 59, 149, 0.1);
            color: var(--booking-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .notification-message {
            font-size: 0.6875rem;
            color: var(--booking-text-light);
            margin-bottom: 4px;
        }

        .notification-time {
            font-size: 0.5625rem;
            color: var(--booking-text-lighter);
        }

        .notification-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--booking-text-light);
        }

        .notification-empty i {
            font-size: 2rem;
            margin-bottom: 12px;
            color: var(--booking-text-lighter);
        }

        .notification-footer {
            padding: 10px 16px;
            border-top: 1px solid var(--booking-border);
            text-align: center;
        }

        .notification-footer a {
            font-size: 0.6875rem;
            color: var(--booking-blue);
            text-decoration: none;
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
            background: linear-gradient(135deg, var(--booking-blue), var(--booking-dark-blue));
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
            min-width: 220px;
            padding: 8px;
            margin-top: 8px;
            z-index: 1000;
            display: none;
            border: 1px solid var(--booking-border);
        }

        .profile-dropdown:hover .dropdown-menu-custom,
        .dropdown-menu-custom.show {
            display: block;
            animation: fadeIn 0.2s ease;
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

        /* Buttons */
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
            transition: all var(--transition-fast);
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
            transition: all var(--transition-fast);
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
            transition: all var(--transition-fast);
            text-decoration: none;
        }

        .btn-outline:hover {
            background: var(--booking-light-blue);
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 0.6875rem;
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
            margin: 0;
        }

        .card-body {
            padding: 20px;
        }

        .p-0 {
            padding: 0 !important;
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
            transition: all var(--transition-fast);
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

        .stat-icon.blue {
            background: var(--booking-light-blue);
            color: var(--booking-blue);
        }

        .stat-icon.green {
            background: #e6f4ea;
            color: var(--booking-success);
        }

        .stat-icon.orange {
            background: #fff4e6;
            color: var(--booking-warning);
        }

        .stat-icon.purple {
            background: #f3e8ff;
            color: #9333ea;
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
            padding: 4px 10px;
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
            transition: all var(--transition-fast);
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

        /* Utilities */
        .text-decoration-none {
            text-decoration: none;
        }

        .me-2 {
            margin-right: 8px;
        }

        .mt-2 {
            margin-top: 8px;
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

            .partner-sidebar.mobile-open {
                transform: translateX(0);
            }

            .partner-sidebar.collapsed {
                transform: translateX(-100%);
            }

            .sidebar-toggle {
                left: 16px;
                background: var(--booking-white);
                border: 1px solid var(--booking-border);
                box-shadow: var(--shadow-md);
            }

            .partner-main {
                margin-left: 0;
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .top-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .top-actions {
                width: 100%;
                justify-content: space-between;
            }

            .search-bar input {
                width: 140px;
            }

            .search-bar input:focus {
                width: 160px;
            }

            .profile-name {
                display: none;
            }

            .notification-panel {
                width: 300px;
                right: -80px;
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
                <a href="dashboard.php">
                    <img src="/gorwanda-plus/assets/images/go.png" alt="GoRwanda+">
                </a>
                <div class="property-badge">
                    <i class="bi bi-house-door"></i> Stays Partner
                </div>
            </div>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($user['profile_image'])): ?>
                            <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $user['profile_image']; ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['first_name'] ?? 'P', 0, 1)); ?>
                        <?php endif; ?>
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
                                <i class="bi bi-speedometer2"></i> Dashboard
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
                                <i class="bi bi-building"></i> All Properties
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="property-add.php" class="nav-link <?php echo $currentPage == 'property-add.php' ? 'active' : ''; ?>">
                                <i class="bi bi-plus-circle"></i> Add Property
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="rooms.php" class="nav-link <?php echo $currentPage == 'rooms.php' ? 'active' : ''; ?>">
                                <i class="bi bi-door-open"></i> Room Management
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="amenities.php" class="nav-link <?php echo $currentPage == 'amenities.php' ? 'active' : ''; ?>">
                                <i class="bi bi-grid-3x3-gap-fill"></i> Amenities
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="photos.php" class="nav-link <?php echo $currentPage == 'photos.php' ? 'active' : ''; ?>">
                                <i class="bi bi-images"></i> Photo Gallery
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
                                <i class="bi bi-calendar-check"></i> Bookings
                                <?php if (($notifications['pending_bookings'] ?? 0) > 0): ?>
                                    <span class="nav-badge"><?php echo $notifications['pending_bookings']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="calendar.php" class="nav-link <?php echo $currentPage == 'calendar.php' ? 'active' : ''; ?>">
                                <i class="bi bi-calendar-week"></i> Availability Calendar
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="rates.php" class="nav-link <?php echo $currentPage == 'rates.php' ? 'active' : ''; ?>">
                                <i class="bi bi-cash-stack"></i> Rates & Pricing
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="restaurant-management.php" class="nav-link <?php echo $currentPage == 'restaurant-management.php' ? 'active' : ''; ?>">
                                <i class="bi bi-shop"></i> Restaurant Management
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
                                <i class="bi bi-star"></i> Reviews
                                <?php if (($notifications['new_reviews'] ?? 0) > 0): ?>
                                    <span class="nav-badge success"><?php echo $notifications['new_reviews']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="messages.php" class="nav-link <?php echo $currentPage == 'messages.php' ? 'active' : ''; ?>">
                                <i class="bi bi-chat-dots"></i> Guest Messages
                                <?php if (($notifications['unread_messages'] ?? 0) > 0): ?>
                                    <span class="nav-badge"><?php echo $notifications['unread_messages']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="policies.php" class="nav-link <?php echo $currentPage == 'policies.php' ? 'active' : ''; ?>">
                                <i class="bi bi-file-text"></i> House Rules
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
                                <i class="bi bi-graph-up-arrow"></i> Performance
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="reports.php" class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>">
                                <i class="bi bi-file-earmark-bar-graph"></i> Reports
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Settings -->
                <div class="nav-section">
                    <ul class="nav-menu">
                        <li class="nav-item">
                            <a href="settings.php" class="nav-link <?php echo $currentPage == 'settings.php' ? 'active' : ''; ?>">
                                <i class="bi bi-gear"></i> Settings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/gorwanda-plus/" class="nav-link">
                                <i class="bi bi-house"></i> View Site
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/gorwanda-plus/logout.php" class="nav-link" style="color: var(--booking-danger);">
                                <i class="bi bi-box-arrow-right"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <!-- Sidebar Toggle Button -->
        <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" title="Toggle Sidebar (Ctrl+B)">
            <i class="bi bi-list" id="toggleIcon"></i>
        </button>

        <!-- Overlay for mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Main Content -->
        <main class="partner-main" id="partnerMain">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="page-title">
                    <h1><?php echo isset($pageTitle) ? str_replace(' - GoRwanda+ Partner', '', $pageTitle) : 'Dashboard'; ?></h1>
                    <p><?php echo date('l, F j, Y'); ?></p>
                </div>

                <div class="top-actions">
                    <!-- Search Bar -->
                    <div class="search-bar">
                        <i class="bi bi-search"></i>
                        <input type="text" placeholder="Search bookings, guests..." id="globalSearch">
                    </div>

                    <!-- Notification Dropdown -->
                    <div class="notification-dropdown" id="notificationDropdown">
                        <div class="notification-icon" onclick="toggleNotificationPanel()">
                            <i class="bi bi-bell"></i>
                            <span class="notification-badge" id="notificationBadge" style="display: <?php echo $unreadNotificationCount > 0 ? 'inline-block' : 'none'; ?>;">
                                <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?>
                            </span>
                        </div>

                        <div class="notification-panel" id="notificationPanel">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <div style="display: flex; gap: 8px;">
                                    <button class="mark-all-read" id="markAllReadBtn" onclick="markAllNotificationsRead()" style="background: none; border: none; font-size: 0.6875rem; color: #003b95; cursor: pointer;">
                                        Mark all read
                                    </button>
                                </div>
                            </div>

                            <div class="notification-list" id="notificationList">
                                <div class="notification-empty">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status" style="width: 1.5rem; height: 1.5rem;">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p style="margin-top: 8px;">Loading notifications...</p>
                                </div>
                            </div>

                            <div class="notification-footer">
                                <a href="notifications.php">View all notifications</a>
                            </div>
                        </div>
                    </div>

                    <!-- Today's Summary -->
                    <?php if (($notifications['checkins_today'] ?? 0) > 0 || ($notifications['checkouts_today'] ?? 0) > 0): ?>
                        <div style="display: flex; gap: 8px; align-items: center; padding: 4px 12px; background: var(--booking-gray-light); border-radius: 20px; font-size: 0.75rem;">
                            <?php if (($notifications['checkins_today'] ?? 0) > 0): ?>
                                <span style="color: var(--booking-success);">
                                    <i class="bi bi-box-arrow-in-right"></i> <?php echo $notifications['checkins_today']; ?> in
                                </span>
                            <?php endif; ?>
                            <?php if (($notifications['checkouts_today'] ?? 0) > 0): ?>
                                <span style="color: var(--booking-warning);">
                                    <i class="bi bi-box-arrow-left"></i> <?php echo $notifications['checkouts_today']; ?> out
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Profile Dropdown -->
                    <div class="profile-dropdown">
                        <div class="profile-trigger">
                            <div class="profile-avatar-small">
                                <?php if (!empty($user['profile_image'])): ?>
                                    <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $user['profile_image']; ?>" alt="">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($user['first_name'] ?? 'P', 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <span class="profile-name"><?php echo sanitize($user['first_name'] ?? 'Partner'); ?></span>
                            <i class="bi bi-chevron-down" style="font-size: 0.75rem;"></i>
                        </div>
                        <div class="dropdown-menu-custom">
                            <a href="/gorwanda-plus/profile.php" class="dropdown-item-custom">
                                <i class="bi bi-person"></i> My Profile
                            </a>
                            <a href="settings.php" class="dropdown-item-custom">
                                <i class="bi bi-gear"></i> Settings
                            </a>
                            <div class="dropdown-divider-custom"></div>
                            <?php if (!empty($userProperties)): ?>
                                <div style="padding: 8px 12px; font-size: 0.625rem; color: var(--booking-text-light); text-transform: uppercase; font-weight: 600;">
                                    My Properties
                                </div>
                                <?php foreach (array_slice($userProperties, 0, 3) as $prop): ?>
                                    <a href="/gorwanda-plus/stays/detail.php?id=<?php echo $prop['stay_id']; ?>" class="dropdown-item-custom" target="_blank">
                                        <i class="bi bi-building"></i> <?php echo sanitize($prop['stay_name']); ?>
                                    </a>
                                <?php endforeach; ?>
                                <div class="dropdown-divider-custom"></div>
                            <?php endif; ?>
                            <a href="/gorwanda-plus/" class="dropdown-item-custom">
                                <i class="bi bi-house"></i> View Site
                            </a>
                            <a href="/gorwanda-plus/logout.php" class="dropdown-item-custom" style="color: var(--booking-danger);">
                                <i class="bi bi-box-arrow-right"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ===== CONTENT STARTS HERE ===== -->