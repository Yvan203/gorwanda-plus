<?php
require_once '../includes/functions.php';

$currentLang = getCurrentLanguage();
$currentCurrency = getCurrentCurrency();

$pageTitle = tr('experiences_page_title', 'Tours & Experiences in Rwanda - Unforgettable Adventures - GoRwanda+');
$currentPage = 'attractions';

// Get search parameters
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$date = isset($_GET['date']) ? $_GET['date'] : '';
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Filters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$categories = isset($_GET['category']) ? (array)$_GET['category'] : [];
$duration = isset($_GET['duration']) ? (array)$_GET['duration'] : [];
$difficulty = isset($_GET['difficulty']) ? (array)$_GET['difficulty'] : [];

$db = getDB();
$taxRate = getTaxRate();

// Build query
$where = ["a.is_active = 1", "a.is_verified = 1"];
$params = [];

if ($location) {
    $where[] = "(a.attraction_name LIKE ? OR l.name LIKE ? OR a.description LIKE ?)";
    $like = "%{$location}%";
    $params = array_merge($params, [$like, $like, $like]);
}

if (!empty($categories)) {
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $where[] = "a.category_id IN ({$placeholders})";
    $params = array_merge($params, $categories);
}

if (!empty($difficulty)) {
    $placeholders = implode(',', array_fill(0, count($difficulty), '?'));
    $where[] = "a.difficulty_level IN ({$placeholders})";
    $params = array_merge($params, $difficulty);
}

// Duration filters
if (!empty($duration)) {
    $durationConditions = [];
    foreach ($duration as $d) {
        switch ($d) {
            case '1':
                $durationConditions[] = "a.duration_minutes <= 60";
                break;
            case '2-3':
                $durationConditions[] = "a.duration_minutes BETWEEN 61 AND 180";
                break;
            case '4-6':
                $durationConditions[] = "a.duration_minutes BETWEEN 181 AND 360";
                break;
            case '7+':
                $durationConditions[] = "a.duration_minutes > 360";
                break;
        }
    }
    if (!empty($durationConditions)) {
        $where[] = "(" . implode(" OR ", $durationConditions) . ")";
    }
}

if ($rating > 0) {
    $where[] = "COALESCE(a.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id)) >= ?";
    $params[] = $rating;
}

// Price filters with tax included
if ($minPrice > 0 || $maxPrice > 0) {
    $priceSubquery = "SELECT attraction_id FROM attraction_tiers WHERE is_active = 1";
    $priceParams = [];

    if ($minPrice > 0) {
        $baseMinPrice = $minPrice / (1 + $taxRate / 100);
        $priceSubquery .= " AND base_price >= ?";
        $priceParams[] = $baseMinPrice;
    }
    if ($maxPrice > 0) {
        $baseMaxPrice = $maxPrice / (1 + $taxRate / 100);
        $priceSubquery .= " AND base_price <= ?";
        $priceParams[] = $baseMaxPrice;
    }

    $priceIds = $db->prepare($priceSubquery);
    $priceIds->execute($priceParams);
    $validIds = $priceIds->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($validIds)) {
        $where[] = "a.attraction_id IN (" . implode(',', $validIds) . ")";
    }
}

// Check availability if date provided
if ($date) {
    $where[] = "a.attraction_id IN (
        SELECT att.attraction_id FROM attraction_tiers att
        JOIN attraction_availability aa ON att.tier_id = aa.tier_id
        WHERE aa.date = ? AND (aa.max_bookings - aa.bookings_made) > 0 AND aa.is_blocked = 0
    )";
    $params[] = $date;
}

// Count total attractions
$countSql = "SELECT COUNT(DISTINCT a.attraction_id) 
             FROM attractions a
             LEFT JOIN locations l ON a.location_id = l.location_id
             LEFT JOIN categories c ON a.category_id = c.category_id
             WHERE " . implode(" AND ", $where);
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalCount = $stmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Sorting
switch ($sort) {
    case 'price_low':
        $orderBy = 'min_price ASC';
        break;
    case 'price_high':
        $orderBy = 'min_price DESC';
        break;
    case 'rating':
        $orderBy = 'avg_rating DESC, review_count DESC';
        break;
    case 'duration':
        $orderBy = 'a.duration_minutes ASC';
        break;
    default:
        $orderBy = 'avg_rating DESC, review_count DESC, a.attraction_id DESC';
        break;
}

// Main query with tax-inclusive price
$sql = "SELECT 
            a.*, 
            c.name as category_name, 
            c.icon as category_icon, 
            l.name as location_name,
            (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_base_price,
            (SELECT MIN(base_price) * (1 + ? / 100) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price_with_tax,
            (SELECT MAX(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as max_base_price,
            (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as tier_count,
            (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count,
            COALESCE(a.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id)) as avg_rating
        FROM attractions a
        LEFT JOIN categories c ON a.category_id = c.category_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        GROUP BY a.attraction_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$taxRate], $params));
$attractions = $stmt->fetchAll();

// Get categories for filter (with language support)
$categoriesList = $db->query("
    SELECT c.*, COUNT(a.attraction_id) as count
    FROM categories c
    LEFT JOIN attractions a ON c.category_id = a.category_id AND a.is_active = 1 AND a.is_verified = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id
    HAVING count > 0
    ORDER BY count DESC
")->fetchAll();

// Get popular locations
$popularLocations = $db->query("
    SELECT l.location_id, l.name, COUNT(a.attraction_id) as attraction_count
    FROM locations l
    JOIN attractions a ON l.location_id = a.location_id
    WHERE l.is_active = 1 AND a.is_active = 1 AND a.is_verified = 1
    GROUP BY l.location_id
    HAVING attraction_count > 0
    ORDER BY attraction_count DESC
    LIMIT 6
")->fetchAll();

// Get difficulty levels with counts
$difficultyLevels = $db->query("
    SELECT difficulty_level, COUNT(*) as count
    FROM attractions
    WHERE is_active = 1 AND is_verified = 1 AND difficulty_level IS NOT NULL
    GROUP BY difficulty_level
    ORDER BY FIELD(difficulty_level, 'easy', 'moderate', 'challenging')
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
    /* ===== ATTRACTIONS LANDING PAGE - BOOKING.COM STYLE ===== */
    :root {
        --bkg-blue-dark: #003580;
        --bkg-blue-primary: #0071c2;
        --bkg-blue-light: #ebf3ff;
        --bkg-yellow: #feba02;
        --bkg-green: #008009;
        --bkg-red: #c41c1c;
        --bkg-gray-100: #f2f6fa;
        --bkg-gray-200: #e7e7e7;
        --bkg-gray-500: #6b6b6b;
        --bkg-gray-700: #262626;
        --shadow-sm: 0 1px 4px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.2s ease;
    }

    /* Hero Banner */
    .attractions-hero {
        background: linear-gradient(135deg, var(--bkg-blue-dark) 0%, #001b4f 100%);
        padding: 32px 0;
        margin-bottom: 24px;
    }

    .attractions-hero h1 {
        color: white;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .attractions-hero p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 14px;
    }

    /* Quick Search */
    .quick-search {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 32px;
        border: 1px solid var(--bkg-gray-200);
        box-shadow: var(--shadow-sm);
    }

    .quick-search-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 16px;
    }

    .quick-search-form {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .quick-search-field {
        flex: 1;
        min-width: 150px;
    }

    .quick-search-input,
    .quick-search-select {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        font-size: 14px;
        background: white;
    }

    .quick-search-input:focus,
    .quick-search-select:focus {
        outline: none;
        border-color: var(--bkg-blue-primary);
        box-shadow: 0 0 0 2px rgba(0, 113, 194, 0.1);
    }

    .quick-search-btn {
        background: var(--bkg-blue-primary);
        color: white;
        border: none;
        padding: 12px 28px;
        font-weight: 600;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .quick-search-btn:hover {
        background: #005fa3;
        transform: translateY(-1px);
    }

    /* Filter Sidebar */
    .attractions-filter-sidebar {
        background: white;
        border-radius: 8px;
        padding: 20px;
        border: 1px solid var(--bkg-gray-200);
        position: sticky;
        top: 80px;
    }

    .filter-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .filter-section {
        margin-bottom: 24px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .filter-subtitle {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 16px;
        color: var(--bkg-gray-700);
    }

    .price-range {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .price-input {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 6px;
        font-size: 13px;
    }

    .filter-option {
        display: flex;
        align-items: center;
        margin-bottom: 12px;
        font-size: 13px;
        cursor: pointer;
    }

    .filter-option input {
        margin-right: 10px;
        width: 16px;
        height: 16px;
        cursor: pointer;
        accent-color: var(--bkg-blue-primary);
    }

    .filter-option .count {
        margin-left: auto;
        color: var(--bkg-gray-500);
        font-size: 11px;
        background: var(--bkg-gray-100);
        padding: 2px 6px;
        border-radius: 12px;
    }

    /* Results Header */
    .results-header {
        background: white;
        padding: 16px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid var(--bkg-gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .results-count {
        font-size: 14px;
        color: var(--bkg-gray-500);
    }

    .results-count strong {
        color: var(--bkg-gray-700);
        font-size: 16px;
        font-weight: 700;
    }

    .sort-select {
        padding: 8px 30px 8px 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    /* Attractions Grid */
    .attractions-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 40px;
    }

    .attraction-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.2s;
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: block;
    }

    .attraction-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: var(--bkg-blue-primary);
    }

    .card-image {
        position: relative;
        height: 180px;
        overflow: hidden;
        background: var(--bkg-gray-100);
    }

    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .attraction-card:hover .card-image img {
        transform: scale(1.05);
    }

    .card-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--bkg-blue-primary);
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        z-index: 2;
    }

    .card-badge.verified {
        background: #008009;
    }

    .card-badge.popular {
        background: #c41c1c;
    }

    .rating-badge {
        position: absolute;
        bottom: 12px;
        left: 12px;
        background: var(--bkg-blue-dark);
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 13px;
        z-index: 2;
    }

    .duration-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .card-content {
        padding: 16px;
    }

    .attraction-category {
        font-size: 12px;
        color: var(--bkg-gray-500);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
    }

    .attraction-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--bkg-blue-primary);
        margin-bottom: 8px;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .attraction-name:hover {
        text-decoration: underline;
    }

    .attraction-location {
        font-size: 13px;
        color: var(--bkg-gray-500);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .attraction-location i {
        color: var(--bkg-blue-primary);
    }

    .attraction-meta {
        display: flex;
        gap: 12px;
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 12px;
        flex-wrap: wrap;
    }

    .attraction-meta span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .attraction-meta i {
        color: var(--bkg-blue-primary);
    }

    .difficulty-badge {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .difficulty-easy {
        background: #d4edda;
        color: #155724;
    }

    .difficulty-moderate {
        background: #fff3cd;
        color: #856404;
    }

    .difficulty-challenging {
        background: #f8d7da;
        color: #721c24;
    }

    .attraction-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        border-top: 1px solid var(--bkg-gray-200);
        padding-top: 12px;
        margin-top: 8px;
    }

    .attraction-rating {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .rating-score {
        background: var(--bkg-blue-dark);
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 14px;
    }

    .rating-text {
        font-size: 11px;
        line-height: 1.3;
    }

    .rating-label {
        font-weight: 600;
        color: var(--bkg-gray-700);
    }

    .rating-count {
        color: var(--bkg-gray-500);
    }

    .attraction-price {
        text-align: right;
    }

    .price-from {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .price-value {
        font-size: 18px;
        font-weight: 700;
        color: var(--bkg-success);
        line-height: 1.2;
    }

    .price-unit {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .price-tax-note {
        font-size: 9px;
        color: var(--bkg-gray-500);
        margin-top: 2px;
    }

    /* Popular Locations */
    .locations-section {
        margin-bottom: 32px;
    }

    .locations-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 12px;
    }

    .location-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        text-decoration: none;
        color: var(--bkg-gray-700);
        transition: all 0.2s;
    }

    .location-card:hover {
        border-color: var(--bkg-blue-primary);
        transform: translateY(-2px);
        box-shadow: var(--shadow-sm);
    }

    .location-icon {
        font-size: 24px;
        color: var(--bkg-blue-primary);
        margin-bottom: 8px;
    }

    .location-name {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .location-count {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    /* Buttons */
    .apply-filters {
        width: 100%;
        padding: 12px;
        background: var(--bkg-blue-primary);
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 12px;
        transition: background 0.2s;
    }

    .apply-filters:hover {
        background: #005fa3;
    }

    .clear-filters {
        display: block;
        text-align: center;
        margin-top: 12px;
        color: var(--bkg-blue-primary);
        font-size: 13px;
        text-decoration: none;
    }

    .clear-filters:hover {
        text-decoration: underline;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 12px;
        border: 1px solid var(--bkg-gray-200);
    }

    .empty-icon {
        font-size: 48px;
        color: var(--bkg-gray-500);
        margin-bottom: 16px;
    }

    .empty-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .empty-text {
        color: var(--bkg-gray-500);
        margin-bottom: 20px;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
    }

    .page-link {
        min-width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        color: var(--bkg-gray-700);
        text-decoration: none;
        font-size: 14px;
        transition: all 0.2s;
    }

    .page-link:hover,
    .page-link.active {
        background: var(--bkg-blue-primary);
        color: white;
        border-color: var(--bkg-blue-primary);
    }

    /* Responsive */
    @media (max-width: 992px) {
        .attractions-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .locations-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 768px) {
        .attractions-grid {
            grid-template-columns: 1fr;
        }

        .locations-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .quick-search-form {
            flex-direction: column;
        }

        .quick-search-field {
            width: 100%;
        }
    }
</style>

<!-- Hero Banner -->
<section class="attractions-hero">
    <div class="container">
        <h1><?php echo tr('experiences_in_rwanda', 'Experiences in Rwanda'); ?></h1>
        <p><?php echo number_format($totalCount); ?> <?php echo tr('tours_activities_available', 'tours & activities available'); ?></p>
    </div>
</section>

<div class="container">
    <!-- Quick Search -->
    <div class="quick-search">
        <h2 class="quick-search-title"><?php echo tr('find_your_experience', 'Find your experience'); ?></h2>
        <form action="" method="GET" class="quick-search-form">
            <div class="quick-search-field">
                <input type="text" name="location" class="quick-search-input"
                    placeholder="<?php echo tr('destination_placeholder', 'Destination'); ?>" value="<?php echo sanitize($location); ?>">
            </div>
            <div class="quick-search-field">
                <input type="date" name="date" class="quick-search-input"
                    value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="quick-search-field">
                <select name="guests" class="quick-search-select">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $guests == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?> <?php echo tr($i > 1 ? 'persons' : 'person'); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="quick-search-btn"><?php echo tr('search', 'Search'); ?></button>
        </form>
    </div>

    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
        <div class="locations-section">
            <div class="locations-grid">
                <?php foreach ($popularLocations as $loc): ?>
                    <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                        <div class="location-icon"><i class="bi bi-geo-alt-fill"></i></div>
                        <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                        <div class="location-count"><?php echo $loc['attraction_count']; ?> <?php echo tr('experiences', 'experiences'); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="attractions-filter-sidebar">
                <h3 class="filter-title"><?php echo tr('filter_by', 'Filter by:'); ?></h3>

                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="location" value="<?php echo sanitize($location); ?>">
                    <input type="hidden" name="date" value="<?php echo $date; ?>">
                    <input type="hidden" name="guests" value="<?php echo $guests; ?>">

                    <!-- Price Range -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle"><?php echo tr('price_per_person', 'Price per person (tax incl.)'); ?></h4>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input"
                                placeholder="<?php echo tr('min_price', 'Min RWF'); ?>" value="<?php echo $minPrice ?: ''; ?>">
                            <span>—</span>
                            <input type="number" name="max_price" class="price-input"
                                placeholder="<?php echo tr('max_price', 'Max RWF'); ?>" value="<?php echo $maxPrice ?: ''; ?>">
                        </div>
                    </div>

                    <!-- Categories -->
                    <?php if (!empty($categoriesList)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle"><?php echo tr('category', 'Category'); ?></h4>
                            <?php foreach ($categoriesList as $cat): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="category[]" value="<?php echo $cat['category_id']; ?>"
                                        <?php echo in_array($cat['category_id'], $categories) ? 'checked' : ''; ?>>
                                    <span><?php echo tr($cat['name'], sanitize($cat['name'])); ?></span>
                                    <span class="count"><?php echo $cat['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Duration -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle"><?php echo tr('duration', 'Duration'); ?></h4>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="1"
                                <?php echo in_array('1', $duration) ? 'checked' : ''; ?>>
                            <span><?php echo tr('up_to_1_hour', 'Up to 1 hour'); ?></span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="2-3"
                                <?php echo in_array('2-3', $duration) ? 'checked' : ''; ?>>
                            <span><?php echo tr('2_3_hours', '2-3 hours'); ?></span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="4-6"
                                <?php echo in_array('4-6', $duration) ? 'checked' : ''; ?>>
                            <span><?php echo tr('4_6_hours', '4-6 hours'); ?></span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="7+"
                                <?php echo in_array('7+', $duration) ? 'checked' : ''; ?>>
                            <span><?php echo tr('7_plus_hours', '7+ hours'); ?></span>
                        </label>
                    </div>

                    <!-- Difficulty -->
                    <?php if (!empty($difficultyLevels)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle"><?php echo tr('difficulty', 'Difficulty'); ?></h4>
                            <?php foreach ($difficultyLevels as $diff): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="difficulty[]" value="<?php echo $diff['difficulty_level']; ?>"
                                        <?php echo in_array($diff['difficulty_level'], $difficulty) ? 'checked' : ''; ?>>
                                    <span><?php echo tr($diff['difficulty_level'], ucfirst($diff['difficulty_level'])); ?></span>
                                    <span class="count"><?php echo $diff['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Rating -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle"><?php echo tr('guest_rating', 'Guest rating'); ?></h4>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <label class="filter-option">
                                <input type="radio" name="rating" value="<?php echo $i; ?>"
                                    <?php echo $rating == $i ? 'checked' : ''; ?>>
                                <span class="star-rating">
                                    <?php for ($j = 1; $j <= 5; $j++): ?>
                                        <i class="bi bi-star-fill<?php echo $j <= $i ? '' : '-empty'; ?>" style="color: #febb02;"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="count"><?php echo $i; ?>+ <?php echo tr('stars', 'stars'); ?></span>
                            </label>
                        <?php endfor; ?>
                        <label class="filter-option">
                            <input type="radio" name="rating" value="0" <?php echo $rating == 0 ? 'checked' : ''; ?>>
                            <span><?php echo tr('any_rating', 'Any rating'); ?></span>
                        </label>
                    </div>

                    <button type="submit" class="apply-filters"><?php echo tr('apply_filters', 'Apply filters'); ?></button>

                    <?php if ($minPrice || $maxPrice || $rating || !empty($categories) || !empty($duration) || !empty($difficulty)): ?>
                        <a href="?location=<?php echo urlencode($location); ?>&date=<?php echo $date; ?>&guests=<?php echo $guests; ?>" class="clear-filters"><?php echo tr('clear_filters', 'Clear filters'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            <!-- Results Header -->
            <div class="results-header">
                <div class="results-count">
                    <strong><?php echo number_format($totalCount); ?></strong> <?php echo tr('experiences', 'experiences'); ?>
                    <?php if ($location): ?> <?php echo tr('in', 'in'); ?> <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>

                <div>
                    <label style="margin-right: 8px;"><?php echo tr('sort_by', 'Sort by:'); ?></label>
                    <select class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>><?php echo tr('recommended', 'Recommended'); ?></option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>><?php echo tr('price_lowest', 'Price (lowest first)'); ?></option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>><?php echo tr('price_highest', 'Price (highest first)'); ?></option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>><?php echo tr('top_rated', 'Top rated'); ?></option>
                        <option value="duration" <?php echo $sort === 'duration' ? 'selected' : ''; ?>><?php echo tr('shortest_first', 'Duration (shortest first)'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Attractions Grid -->
            <?php if (empty($attractions)): ?>
                <div class="empty-state">
                    <i class="bi bi-ticket-perforated" style="font-size: 48px; color: #6b6b6b;"></i>
                    <h3 class="empty-title"><?php echo tr('no_experiences_found', 'No experiences found'); ?></h3>
                    <p class="empty-text"><?php echo tr('try_adjusting_filters', 'Try adjusting your filters or search criteria'); ?></p>
                    <a href="?location=<?php echo urlencode($location); ?>&date=<?php echo $date; ?>&guests=<?php echo $guests; ?>" class="apply-filters" style="width: auto; display: inline-block; padding: 10px 24px;"><?php echo tr('clear_filters', 'Clear filters'); ?></a>
                </div>
            <?php else: ?>
                <div class="attractions-grid">
                    <?php foreach ($attractions as $attraction):
                        $avgRating = $attraction['avg_rating'] ?: 0;
                        $priceWithTax = $attraction['min_price_with_tax'] ?: displayCustomerPrice($attraction['min_base_price']);
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating) : [tr('new', 'New'), 'bg-secondary'];

                        $durationHours = floor($attraction['duration_minutes'] / 60);
                        $durationMinutes = $attraction['duration_minutes'] % 60;
                        $durationText = '';
                        if ($durationHours > 0) $durationText .= $durationHours . 'h';
                        if ($durationMinutes > 0) $durationText .= ($durationHours > 0 ? ' ' : '') . $durationMinutes . 'm';

                        $difficultyClass = '';
                        if ($attraction['difficulty_level'] == 'easy') $difficultyClass = 'difficulty-easy';
                        elseif ($attraction['difficulty_level'] == 'moderate') $difficultyClass = 'difficulty-moderate';
                        elseif ($attraction['difficulty_level'] == 'challenging') $difficultyClass = 'difficulty-challenging';

                        $imageName = $attraction['main_image'] ?? '';
                        if (empty($imageName) && !empty($attraction['gallery_images'])) {
                            $galleryImages = json_decode($attraction['gallery_images'], true);
                            $imageName = is_array($galleryImages) ? ($galleryImages[0] ?? '') : '';
                        }

                        $difficultyText = '';
                        if ($attraction['difficulty_level'] == 'easy') $difficultyText = tr('easy', 'Easy');
                        elseif ($attraction['difficulty_level'] == 'moderate') $difficultyText = tr('moderate', 'Moderate');
                        elseif ($attraction['difficulty_level'] == 'challenging') $difficultyText = tr('challenging', 'Challenging');
                    ?>
                        <a href="detail.php?id=<?php echo $attraction['attraction_id']; ?>&date=<?php echo $date; ?>&guests=<?php echo $guests; ?>" class="attraction-card">
                            <div class="card-image">
                                <img src="<?php echo getImageUrl($imageName, 'attraction'); ?>"
                                    alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                                    loading="lazy"
                                    onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60'">

                                <?php if ($attraction['is_verified']): ?>
                                    <span class="card-badge verified"><?php echo tr('verified', 'Verified'); ?></span>
                                <?php endif; ?>

                                <?php if ($attraction['instant_confirmation']): ?>
                                    <span class="card-badge"><?php echo tr('instant', 'Instant'); ?></span>
                                <?php endif; ?>

                                <?php if ($avgRating > 0): ?>
                                    <span class="rating-badge"><?php echo number_format($avgRating, 1); ?></span>
                                <?php endif; ?>

                                <?php if ($durationText): ?>
                                    <span class="duration-badge"><i class="bi bi-clock"></i> <?php echo $durationText; ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="card-content">
                                <div class="attraction-category"><?php echo tr($attraction['category_name'] ?? 'experience', sanitize($attraction['category_name'] ?: 'Experience')); ?></div>
                                <h3 class="attraction-name"><?php echo sanitize($attraction['attraction_name']); ?></h3>

                                <div class="attraction-location">
                                    <i class="bi bi-geo-alt"></i>
                                    <?php echo sanitize($attraction['location_name'] ?: 'Rwanda'); ?>
                                </div>

                                <div class="attraction-meta">
                                    <?php if ($attraction['difficulty_level']): ?>
                                        <span>
                                            <span class="difficulty-badge <?php echo $difficultyClass; ?>">
                                                <?php echo $difficultyText; ?>
                                            </span>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($attraction['duration_minutes']): ?>
                                        <span><i class="bi bi-hourglass-split"></i> <?php echo $durationText; ?></span>
                                    <?php endif; ?>

                                    <?php if ($attraction['max_group_size']): ?>
                                        <span><i class="bi bi-people"></i> <?php echo tr('max', 'Max'); ?> <?php echo $attraction['max_group_size']; ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="attraction-footer">
                                    <div class="attraction-rating">
                                        <?php if ($attraction['review_count'] > 0): ?>
                                            <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                                            <div class="rating-text">
                                                <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                                                <div class="rating-count"><?php echo number_format($attraction['review_count']); ?> <?php echo tr('reviews', 'reviews'); ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="rating-score" style="background: #6c757d;"><?php echo tr('new', 'New'); ?></span>
                                            <div class="rating-text">
                                                <div class="rating-label"><?php echo tr('new', 'New'); ?></div>
                                                <div class="rating-count">0 <?php echo tr('reviews', 'reviews'); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($priceWithTax > 0): ?>
                                        <div class="attraction-price">
                                            <div class="price-from"><?php echo tr('from', 'from'); ?></div>
                                            <div class="price-value"><?php echo formatPrice($priceWithTax); ?></div>
                                            <div class="price-unit"><?php echo tr('per_person', 'per person'); ?></div>
                                            <div class="price-tax-note"><?php echo tr('tax_included', 'tax included'); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">«</a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);

                        if ($start > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">1</a>
                            <?php if ($start > 2): ?><span class="page-link" style="border: none;">...</span><?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?><span class="page-link" style="border: none;">...</span><?php endif; ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-link"><?php echo $totalPages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">»</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function applySort(value) {
        const url = new URL(window.location.href);
        url.searchParams.set('sort', value);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    // Set min date for date input
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        const dateInput = document.querySelector('input[name="date"]');
        if (dateInput) dateInput.min = today;
    });
</script>

<?php require_once '../includes/footer.php'; ?>