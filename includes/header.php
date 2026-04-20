<?php
// START SESSION FIRST - before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FORCE language from session or cookie
if (isset($_SESSION['language'])) {
    $currentLang = $_SESSION['language'];
} elseif (isset($_COOKIE['user_language'])) {
    $currentLang = $_COOKIE['user_language'];
    $_SESSION['language'] = $currentLang;
} else {
    $currentLang = 'en';
    $_SESSION['language'] = 'en';
}

// FORCE currency from session or cookie
if (isset($_SESSION['currency'])) {
    $currentCurrency = $_SESSION['currency'];
} elseif (isset($_COOKIE['user_currency'])) {
    $currentCurrency = $_COOKIE['user_currency'];
    $_SESSION['currency'] = $currentCurrency;
} else {
    $currentCurrency = 'RWF';
    $_SESSION['currency'] = 'RWF';
}

// Debug - check if language is set
error_log("Header.php - Current language: " . $currentLang . " - Session ID: " . session_id());

require_once __DIR__ . '/functions.php';



// Language and Currency from session or default
$currentLang = $_SESSION['language'] ?? 'en';
$currentCurrency = $_SESSION['currency'] ?? 'RWF';

// Fix current page detection
$currentScript = basename($_SERVER['PHP_SELF']);
$currentPath = dirname($_SERVER['PHP_SELF']);

// Determine current page based on URL path
if (strpos($currentPath, '/stays') !== false) {
    $currentPage = 'stays';
} elseif (strpos($currentPath, '/cars') !== false) {
    $currentPage = 'cars';
} elseif (strpos($currentPath, '/attractions') !== false) {
    $currentPage = 'attractions';
} elseif (strpos($currentPath, '/restaurants') !== false) {
    $currentPage = 'restaurants';
} elseif ($currentScript === 'index.php' || $currentScript === '') {
    $currentPage = 'home';
} else {
    $currentPage = basename($currentScript, '.php');
}

// Debug - remove after fixing
error_log("Current Path: " . $currentPath . " - Current Page: " . $currentPage);

$searchType = $_GET['type'] ?? 'stays';
$searchType = $_GET['type'] ?? 'stays';
$currentUser = getCurrentUser();

// Language options
$languages = [
    'en' => ['name' => 'English', 'flag' => 'gb', 'short' => 'EN'],
    'fr' => ['name' => 'Français', 'flag' => 'fr', 'short' => 'FR'],
    'rw' => ['name' => 'Kinyarwanda', 'flag' => 'rw', 'short' => 'RW'],
    'sw' => ['name' => 'Kiswahili', 'flag' => 'ke', 'short' => 'SW']
];

// Currency options
$currencies = [
    'RWF' => ['name' => 'Rwandan Franc', 'symbol' => 'FRw', 'rate' => 1],
    'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'rate' => 1300],
    'EUR' => ['name' => 'Euro', 'symbol' => '€', 'rate' => 1400],
    'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'rate' => 1600],
    'KES' => ['name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'rate' => 10],
    'UGX' => ['name' => 'Ugandan Shilling', 'symbol' => 'USh', 'rate' => 0.35],
    'TZS' => ['name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'rate' => 0.5]
];

// Page title logic
$pageTitle = isset($pageTitle) ? $pageTitle . ' - GoRwanda+' : 'GoRwanda+ - Discover Rwanda\'s Best Stays, Cars & Experiences';
?>
<!DOCTYPE html>
<html lang="<?php echo $currentLang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
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
    
    <!-- Preconnect for faster loading -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Google Fonts - Inter for clean, small text -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Flag Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flag-icons@6.6.6/css/flag-icons.min.css">
    
    <style>
        :root {
            /* Booking.com Official Colors - Exact matches */
            --bkg-blue-dark: #003580;
            --bkg-blue-primary: #0071c2;
            --bkg-blue-light: #ebf3ff;
            --bkg-yellow: #feba02;
            --bkg-yellow-hover: #e6a800;
            --bkg-green: #008009;
            --bkg-red: #c41c1c;
            --bkg-gray-100: #f2f6fa;
            --bkg-gray-200: #e7e7e7;
            --bkg-gray-500: #6b6b6b;
            --bkg-gray-700: #262626;
            --bkg-white: #ffffff;
            
            /* Rwanda Touch - subtle additions */
            --rwanda-green: #00a651;
            --rwanda-yellow: #fcd116;
            --rwanda-blue: #00a1de;
            
            --header-height: 56px; /* Booking.com header height */
            --radius-sm: 2px;
            --radius-md: 4px;
            --radius-lg: 8px;
            --shadow-sm: 0 1px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 2px 8px rgba(0,0,0,0.1);
            --shadow-lg: 0 4px 16px rgba(0,0,0,0.15);
            --transition: all 0.2s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            font-size: 14px; /* Base 14px for smaller text */
            scroll-behavior: smooth;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--bkg-gray-700);
            background-color: var(--bkg-white);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* ===== EXACT BOOKING.COM HEADER ===== */
        .bkg-header {
            background-color: var(--bkg-blue-dark);
            height: var(--header-height);
            position: sticky;
            top: 0;
            z-index: 1030;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .bkg-navbar {
            display: flex;
            align-items: center;
            height: 100%;
            justify-content: space-between;
        }
        
        .bkg-nav-left {
            display: flex;
            align-items: center;
            gap: 24px;
            height: 100%;
        }
        
        .bkg-logo {
            display: flex;
            align-items: center;
        }
        
        .bkg-logo img {
            height: 100px; /* Booking.com logo size */
            width: 100px;
        }
        
        .bkg-logo-text {
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
            letter-spacing: -0.3px;
            line-height: 1;
        }
        

        /* Main Navigation - Exactly like Booking.com */
        .bkg-nav-items {
            display: flex;
            align-items: center;
            height: 100%;
            gap: 4px;
        }
        
        .bkg-nav-item {
            height: 100%;
            display: flex;
            align-items: center;
            position: relative;
        }
        
        .bkg-nav-link {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 0 16px;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 0;
            transition: background-color 0.2s;
            position: relative;
            white-space: nowrap;
        }
        
        .bkg-nav-link i {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .bkg-nav-link:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .bkg-nav-link.active {
            background-color: rgba(255,255,255,0.15);
            position: relative;
        }
        
        .bkg-nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 16px;
            right: 16px;
            height: 3px;
            background-color: white;
            border-radius: 1px 1px 0 0;
        }
        
        /* Right side actions */
        .bkg-nav-right {
            display: flex;
            align-items: center;
            gap: 8px;
            height: 100%;
        }
        
        /* Currency/Language selector - Booking.com style */
        .bkg-selector {
            position: relative;
            height: 100%;
            display: flex;
            align-items: center;
        }
        
        .bkg-selector-btn {
            background: transparent;
            border: none;
            color: white;
            font-size: 14px;
            font-weight: 500;
            padding: 0 12px;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-radius: 0;
            white-space: nowrap;
        }
        
        .bkg-selector-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .bkg-selector-btn i {
            font-size: 16px;
            opacity: 0.8;
        }
        
        .bkg-flag {
            width: 20px;
            height: 20px;
            border-radius: 2px;
            object-fit: cover;
        }
        
        .fi {
            border-radius: 2px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            font-size: 16px;
        }
        
        /* Help button */
        .bkg-help {
            height: 100%;
            display: flex;
            align-items: center;
        }
        
        .bkg-help-btn {
            background: transparent;
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
            font-size: 18px;
            cursor: pointer;
        }
        
        .bkg-help-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        /* Property listing button */
        .bkg-list-property {
            height: 100%;
            display: flex;
            align-items: center;
            margin-left: 4px;
        }
        
        .bkg-list-property-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .bkg-list-property-btn:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.5);
        }
        
        .bkg-list-property-btn i {
            font-size: 16px;
        }
        
        /* User menu */
        .bkg-user {
            height: 100%;
            display: flex;
            align-items: center;
        }
        
        .bkg-user-btn {
            background: transparent;
            border: none;
            color: white;
            padding: 0 8px;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-radius: 0;
        }
        
        .bkg-user-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .bkg-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: var(--bkg-blue-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .bkg-avatar-img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .bkg-user-name {
            font-size: 14px;
            font-weight: 500;
            max-width: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Auth buttons */
        .bkg-auth {
            display: flex;
            align-items: center;
            gap: 8px;
            height: 100%;
        }
        
        .bkg-auth-btn {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 16px;
            border-radius: 2px;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .bkg-auth-btn:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.5);
        }
        
        .bkg-auth-btn-primary {
            background: white;
            border-color: white;
            color: var(--bkg-blue-dark);
        }
        
        .bkg-auth-btn-primary:hover {
            background: rgba(255,255,255,0.9);
            border-color: white;
            color: var(--bkg-blue-dark);
        }
        
        /* Dropdown menus - Booking.com style */
        .bkg-dropdown {
            position: absolute;
            top: calc(100% - 4px);
            right: 0;
            background: white;
            border-radius: 4px;
            box-shadow: var(--shadow-lg);
            min-width: 280px;
            padding: 8px 0;
            display: none;
            z-index: 1050;
            border: 1px solid var(--bkg-gray-200);
        }
        
        .bkg-dropdown.show {
            display: block;
        }
        
        .bkg-dropdown-item {
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--bkg-gray-700);
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.2s;
            cursor: pointer;
        }
        
        .bkg-dropdown-item:hover {
            background-color: var(--bkg-blue-light);
        }
        
        .bkg-dropdown-item i {
            width: 20px;
            color: var(--bkg-blue-primary);
            font-size: 16px;
        }
        
        .bkg-dropdown-divider {
            height: 1px;
            background-color: var(--bkg-gray-200);
            margin: 8px 0;
        }
        
        .bkg-dropdown-header {
            padding: 12px 16px;
            font-weight: 600;
            font-size: 14px;
            color: var(--bkg-gray-700);
            border-bottom: 1px solid var(--bkg-gray-200);
            margin-bottom: 8px;
        }
        
        /* Currency/Language grid */
        .bkg-grid-dropdown {
            min-width: 340px;
            padding: 16px;
        }
        
        .bkg-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            margin-top: 12px;
        }
        
        .bkg-grid-option {
            padding: 12px 8px;
            border: 1px solid var(--bkg-gray-200);
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--bkg-gray-700);
        }
        
        .bkg-grid-option:hover {
            border-color: var(--bkg-blue-primary);
            background-color: var(--bkg-blue-light);
        }
        
        .bkg-grid-option.active {
            border-color: var(--bkg-blue-primary);
            background-color: var(--bkg-blue-light);
            font-weight: 600;
        }
        
        .bkg-currency-symbol {
            font-size: 18px;
            font-weight: 700;
            display: block;
            color: var(--bkg-blue-dark);
        }
        
        .bkg-currency-name {
            font-size: 11px;
            color: var(--bkg-gray-500);
        }
        
        /* Search section - Integrated below header like Booking.com */
        .bkg-search-section {
            background-color: var(--bkg-blue-dark);
            padding: 16px 0 24px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .bkg-search-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            padding-bottom: 8px;
        }
        
        .bkg-search-tab {
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 16px;
            border-radius: 2px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
            opacity: 0.8;
        }
        
        .bkg-search-tab:hover {
            background-color: rgba(255,255,255,0.1);
            opacity: 1;
        }
        
        .bkg-search-tab.active {
            background-color: rgba(255,255,255,0.2);
            opacity: 1;
            font-weight: 600;
        }
        
        .bkg-search-tab i {
            font-size: 16px;
        }
        
        .bkg-search-box {
            background: var(--bkg-yellow);
            border-radius: 8px;
            padding: 4px;
            display: flex;
            gap: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .bkg-search-field {
            flex: 1;
            min-width: 0;
            background: white;
            border-radius: 4px;
            position: relative;
        }
        
        .bkg-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--bkg-gray-500);
            font-size: 16px;
            pointer-events: none;
        }
        
        .bkg-search-input {
            width: 100%;
            height: 44px;
            border: none;
            padding: 0 12px 0 40px;
            font-size: 14px;
            border-radius: 4px;
            background: transparent;
            color: var(--bkg-gray-700);
        }
        
        .bkg-search-input:focus {
            outline: none;
            box-shadow: inset 0 0 0 2px var(--bkg-blue-primary);
            border-radius: 4px;
        }
        
        .bkg-search-input::placeholder {
            color: var(--bkg-gray-500);
            font-size: 14px;
        }
        
        .bkg-search-select {
            width: 100%;
            height: 44px;
            border: none;
            padding: 0 28px 0 40px;
            font-size: 14px;
            border-radius: 4px;
            background: transparent;
            color: var(--bkg-gray-700);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            cursor: pointer;
        }
        
        .bkg-search-select:focus {
            outline: none;
            box-shadow: inset 0 0 0 2px var(--bkg-blue-primary);
        }
        
        .bkg-search-btn {
            background: var(--bkg-blue-primary);
            color: white;
            border: none;
            padding: 0 28px;
            font-weight: 600;
            font-size: 14px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            height: 44px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        
        .bkg-search-btn:hover {
            background-color: #005fa3;
        }
        
        /* Mobile menu */
        .bkg-mobile-toggle {
            display: none;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        /* Main content */
        main {
            flex: 1;
            background: white;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .bkg-nav-items,
            .bkg-list-property,
            .bkg-selector.currency,
            .bkg-selector.language {
                display: none;
            }
            
            .bkg-mobile-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .bkg-search-box {
                flex-direction: column;
            }
            
            .bkg-search-field {
                width: 100%;
            }
            
            .bkg-search-btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 768px) {
            .bkg-user-name {
                display: none;
            }
            
            .bkg-auth-btn span {
                display: none;
            }
            
            .bkg-auth-btn i {
                margin: 0;
            }
            
            .bkg-auth-btn {
                padding: 6px 10px;
            }
        }
        
        /* Small text utility */
        .text-xs {
            font-size: 11px;
        }
        
        .text-sm {
            font-size: 12px;
        }
        
        .text-base {
            font-size: 14px;
        }
        
        .text-lg {
            font-size: 16px;
        }
        
        /* Rwanda touch - subtle flag colors on hover */
        .rwanda-touch:hover {
            border-bottom-color: var(--rwanda-green) !important;
        }
    </style>
    
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>
<body>

<!-- ===== EXACT BOOKING.COM HEADER ===== -->
<header class="bkg-header">
    <div class="container h-100">
        <div class="bkg-navbar">
            <!-- Left side - Logo and Navigation -->
            <div class="bkg-nav-left">
                <!-- Logo -->
                <a href="/gorwanda-plus/" class="bkg-logo text-decoration-none">
                    <img src="/gorwanda-plus/assets/images/go.png" 
                         alt="GoRwanda+" 
                         class="d-block"
                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='block';">
                    
                </a>
                
<!-- Main Navigation - Exactly like Booking.com -->
<div class="bkg-nav-items">
    <div class="bkg-nav-item">
        <a href="/gorwanda-plus/stays/" class="bkg-nav-link <?php echo $currentPage === 'stays' ? 'active' : ''; ?>">
            <i class="bi bi-building"></i>
            <span>Stays</span>
        </a>
    </div>
    <div class="bkg-nav-item">
        <a href="/gorwanda-plus/cars/" class="bkg-nav-link <?php echo $currentPage === 'cars' ? 'active' : ''; ?>">
            <i class="bi bi-car-front"></i>
            <span>Cars</span>
        </a>
    </div>
    <div class="bkg-nav-item">
        <a href="/gorwanda-plus/attractions/" class="bkg-nav-link <?php echo $currentPage === 'attractions' ? 'active' : ''; ?>">
            <i class="bi bi-ticket-perforated"></i>
            <span>Experiences</span>
        </a>
    </div>
    <div class="bkg-nav-item">
        <a href="/gorwanda-plus/restaurants/" class="bkg-nav-link <?php echo $currentPage === 'restaurants' ? 'active' : ''; ?>">
            <i class="bi bi-shop"></i>
            <span>Restaurants</span>
        </a>
    </div>
</div>
            </div>
            
            <!-- Right side - Actions -->
            <div class="bkg-nav-right">
    <!-- Currency Selector - YOUR ORIGINAL DESIGN INTACT -->
    <div class="bkg-selector currency" id="currencySelector">
        <button class="bkg-selector-btn" onclick="toggleDropdown('currencyDropdown')">
            <span><?php echo $currentCurrency; ?></span>
            <i class="bi bi-chevron-down"></i>
        </button>
        <div class="bkg-dropdown bkg-grid-dropdown" id="currencyDropdown">
            <div class="bkg-dropdown-header">Select your currency</div>
            <div class="bkg-grid">
                <?php foreach ($currencies as $code => $currency): ?>
                <a href="/gorwanda-plus/set-currency.php?currency=<?php echo $code; ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" 
                   class="bkg-grid-option <?php echo $currentCurrency === $code ? 'active' : ''; ?>">
                    <span class="bkg-currency-symbol"><?php echo $currency['symbol']; ?></span>
                    <span class="bkg-currency-name"><?php echo $currency['name']; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Language Selector - YOUR ORIGINAL DESIGN INTACT -->
    <div class="bkg-selector language" id="languageSelector">
        <button class="bkg-selector-btn" onclick="toggleDropdown('languageDropdown')">
            <span class="fi fi-<?php echo $languages[$currentLang]['flag']; ?>"></span>
            <span><?php echo $languages[$currentLang]['short']; ?></span>
            <i class="bi bi-chevron-down"></i>
        </button>
        <div class="bkg-dropdown bkg-grid-dropdown" id="languageDropdown">
            <div class="bkg-dropdown-header">Select your language</div>
            <div class="bkg-grid">
                <?php foreach ($languages as $code => $lang): ?>
                <a href="/gorwanda-plus/set-language.php?lang=<?php echo $code; ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" 
                   class="bkg-grid-option <?php echo $currentLang === $code ? 'active' : ''; ?>">
                    <span class="fi fi-<?php echo $lang['flag']; ?> fis fs-4 mb-1 d-block"></span>
                    <span class="bkg-currency-name"><?php echo $lang['name']; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
                
                <!-- Help Center -->
                <div class="bkg-help">
                    <a href="/gorwanda-plus/help" class="bkg-help-btn" title="Help Center">
                        <i class="bi bi-question-circle"></i>
                    </a>
                </div>
                
                <!-- List Property Button -->
                <div class="bkg-list-property">
                    <a href="/gorwanda-plus/partner/onboarding.php" class="bkg-list-property-btn">
                        <i class="bi bi-plus-circle"></i>
                        <span>List property</span>
                    </a>
                </div>
                
                <!-- User Menu / Auth -->
                <?php if (isLoggedIn()): ?>
                <div class="bkg-user" id="userMenu">
                    <button class="bkg-user-btn" onclick="toggleDropdown('userDropdown')">
                        <?php if (!empty($currentUser['profile_image'])): ?>
                            <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $currentUser['profile_image']; ?>" 
                                 alt="" class="bkg-avatar-img">
                        <?php else: ?>
                            <div class="bkg-avatar">
                                <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <span class="bkg-user-name"><?php echo sanitize($currentUser['first_name']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="bkg-dropdown" id="userDropdown">
                        <div class="bkg-dropdown-header">
                            <div class="fw-bold"><?php echo sanitize($currentUser['first_name'] . ' ' . $currentUser['last_name']); ?></div>
                            <div class="text-xs text-muted"><?php echo sanitize($currentUser['email']); ?></div>
                        </div>
                        <a href="/gorwanda-plus/profile.php" class="bkg-dropdown-item">
                            <i class="bi bi-person"></i>
                            <span>Profile</span>
                        </a>
                        <a href="/gorwanda-plus/bookings.php" class="bkg-dropdown-item">
                            <i class="bi bi-calendar-check"></i>
                            <span>Bookings</span>
                        </a>
                        <a href="/gorwanda-plus/wishlist.php" class="bkg-dropdown-item">
                            <i class="bi bi-heart"></i>
                            <span>Wishlist</span>
                        </a>
                        
                        <?php if (isBusinessOwner() || isAdmin()): ?>
                        <div class="bkg-dropdown-divider"></div>
                        <a href="<?php echo getDashboardUrl(getCurrentUser()); ?>" class="bkg-dropdown-item">
                            <i class="bi bi-speedometer2"></i>
                            <span>Partner dashboard</span>
                        </a>
                        <?php endif; ?>
                        
                        <div class="bkg-dropdown-divider"></div>
                        <a href="/gorwanda-plus/logout.php" class="bkg-dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Sign out</span>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="bkg-auth">
                    <a href="/gorwanda-plus/register.php" class="bkg-auth-btn">
                        <i class="bi bi-person-plus"></i>
                        <span>Register</span>
                    </a>
                    <a href="/gorwanda-plus/login.php" class="bkg-auth-btn bkg-auth-btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Sign in</span>
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Mobile Toggle -->
                <button class="bkg-mobile-toggle" onclick="toggleMobileMenu()">
                    <i class="bi bi-list fs-5"></i>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Search Section (only on pages that need it) -->
<?php if (!isset($hideSearch)): ?>
<section class="bkg-search-section">
    <div class="container">
        <!-- Search Tabs - Integrated below header -->
        <div class="bkg-search-tabs">
            <a href="/gorwanda-plus/stays/" class="bkg-search-tab <?php echo $currentPage === 'stays' ? 'active' : ''; ?>">
                <i class="bi bi-building"></i>
                <span>Stays</span>
            </a>
            <a href="/gorwanda-plus/cars/" class="bkg-search-tab <?php echo $currentPage === 'cars' ? 'active' : ''; ?>">
                <i class="bi bi-car-front"></i>
                <span>Cars</span>
            </a>
            <a href="/gorwanda-plus/attractions/" class="bkg-search-tab <?php echo $currentPage === 'attractions' ? 'active' : ''; ?>">
                <i class="bi bi-ticket-perforated"></i>
                <span>Experiences</span>
            </a>
            <a href="/gorwanda-plus/restaurants/" class="bkg-search-tab <?php echo $currentPage === 'restaurants' ? 'active' : ''; ?>">
                <i class="bi bi-shop"></i>
                <span>Restaurants</span>
            </a>
        </div>
        
        <!-- Search Form - Booking.com Yellow Box -->
        <form action="/gorwanda-plus/search.php" method="GET">
            <input type="hidden" name="type" value="<?php echo $searchType; ?>">
            <div class="bkg-search-box">
                <?php if ($searchType === 'stays'): ?>
                    <!-- Stays Search Fields -->
                    <div class="bkg-search-field" style="flex: 2;">
                        <i class="bi bi-geo-alt bkg-search-icon"></i>
                        <input type="text" name="location" class="bkg-search-input" 
                               placeholder="Where are you going?" 
                               value="<?php echo sanitize($_GET['location'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="checkin" class="bkg-search-input" 
                               placeholder="Check-in" 
                               value="<?php echo sanitize($_GET['checkin'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="checkout" class="bkg-search-input" 
                               placeholder="Check-out" 
                               value="<?php echo sanitize($_GET['checkout'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-people bkg-search-icon"></i>
                        <select name="guests" class="bkg-search-select">
                            <?php for($i=1; $i<=8; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($_GET['guests'] ?? 2) == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> adult<?php echo $i > 1 ? 's' : ''; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php elseif ($searchType === 'cars'): ?>
                    <!-- Cars Search Fields -->
                    <div class="bkg-search-field" style="flex: 2;">
                        <i class="bi bi-geo-alt bkg-search-icon"></i>
                        <input type="text" name="location" class="bkg-search-input" 
                               placeholder="Pick-up location" 
                               value="<?php echo sanitize($_GET['location'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="pickup_date" class="bkg-search-input" 
                               placeholder="Pick-up date" 
                               value="<?php echo sanitize($_GET['pickup_date'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="return_date" class="bkg-search-input" 
                               placeholder="Return date" 
                               value="<?php echo sanitize($_GET['return_date'] ?? ''); ?>">
                    </div>
                <?php elseif ($searchType === 'attractions'): ?>
                    <!-- Experiences Search Fields -->
                    <div class="bkg-search-field" style="flex: 2;">
                        <i class="bi bi-geo-alt bkg-search-icon"></i>
                        <input type="text" name="location" class="bkg-search-input" 
                               placeholder="Where are you going?" 
                               value="<?php echo sanitize($_GET['location'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="date" class="bkg-search-input" 
                               placeholder="Date" 
                               value="<?php echo sanitize($_GET['date'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-people bkg-search-icon"></i>
                        <select name="guests" class="bkg-search-select">
                            <?php for($i=1; $i<=10; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($_GET['guests'] ?? 2) == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> person<?php echo $i > 1 ? 's' : ''; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <!-- Restaurants Search Fields (simpler) -->
                    <div class="bkg-search-field" style="flex: 2;">
                        <i class="bi bi-geo-alt bkg-search-icon"></i>
                        <input type="text" name="location" class="bkg-search-input" 
                               placeholder="Restaurant name or location" 
                               value="<?php echo sanitize($_GET['location'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-calendar3 bkg-search-icon"></i>
                        <input type="date" name="date" class="bkg-search-input" 
                               placeholder="Date" 
                               value="<?php echo sanitize($_GET['date'] ?? ''); ?>">
                    </div>
                    <div class="bkg-search-field">
                        <i class="bi bi-people bkg-search-icon"></i>
                        <select name="guests" class="bkg-search-select">
                            <?php for($i=1; $i<=10; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo ($_GET['guests'] ?? 2) == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> person<?php echo $i > 1 ? 's' : ''; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="bkg-search-btn">
                    <i class="bi bi-search"></i>
                    <span>Search</span>
                </button>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- Main Content -->
<main>
    <div class="container mt-3">
        <?php showFlash(); ?>
    </div>

    <script>
// Toggle dropdowns
function toggleDropdown(id) {
    // Close all dropdowns first
    document.querySelectorAll('.bkg-dropdown').forEach(dropdown => {
        if (dropdown.id !== id) {
            dropdown.classList.remove('show');
        }
    });
    
    // Toggle the clicked dropdown
    const dropdown = document.getElementById(id);
    if (dropdown) {
        dropdown.classList.toggle('show');
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.bkg-selector') && !event.target.closest('.bkg-user')) {
        document.querySelectorAll('.bkg-dropdown').forEach(dropdown => {
            dropdown.classList.remove('show');
        });
    }
});

// Mobile menu (simplified - you can enhance this)
function toggleMobileMenu() {
    alert('Mobile menu will open - implement as needed');
}

// Set min dates for date inputs
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    document.querySelectorAll('input[type="date"]').forEach(input => {
        input.min = today;
    });
});
</script>