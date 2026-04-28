<?php
require_once '../includes/functions.php';

$pageTitle = 'Restaurants in Rwanda - Dining & Cuisine - GoRwanda+';
$currentPage = 'restaurants';

// Get search parameters safely
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$date = isset($_GET['date']) ? $_GET['date'] : '';
$time = isset($_GET['time']) ? $_GET['time'] : '';
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15; // Booking.com shows 15 per page
$offset = ($page - 1) * $perPage;

// Filters with safe checks
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$cuisines = isset($_GET['cuisine']) ? (array)$_GET['cuisine'] : [];
$features = isset($_GET['features']) ? (array)$_GET['features'] : [];

$db = getDB();

// Build query for restaurants
$where = ["r.is_active = 1"];
$params = [];

if ($location) {
    $where[] = "(r.restaurant_name LIKE ? OR l.name LIKE ? OR s.stay_name LIKE ? OR s.city LIKE ?)";
    $like = "%{$location}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if (!empty($cuisines)) {
    $cuisineConditions = [];
    foreach ($cuisines as $cuisine) {
        $cuisineConditions[] = "r.cuisine_type LIKE ?";
        $params[] = "%{$cuisine}%";
    }
    if (!empty($cuisineConditions)) {
        $where[] = "(" . implode(" OR ", $cuisineConditions) . ")";
    }
}

if (!empty($features)) {
    $featureConditions = [];
    foreach ($features as $feature) {
        switch ($feature) {
            case 'outdoor':
                $featureConditions[] = "r.has_outdoor_seating = 1";
                break;
            case 'private':
                $featureConditions[] = "r.has_private_dining = 1";
                break;
            case 'reservations':
                $featureConditions[] = "r.accepts_reservations = 1";
                break;
            case 'parking':
                // This would need to join with stays amenities
                $featureConditions[] = "EXISTS (SELECT 1 FROM stays s2 WHERE s2.stay_id = r.stay_id AND JSON_CONTAINS(s2.amenities, '\"parking\"'))";
                break;
            case 'wifi':
                $featureConditions[] = "EXISTS (SELECT 1 FROM stays s2 WHERE s2.stay_id = r.stay_id AND JSON_CONTAINS(s2.amenities, '\"wifi\"'))";
                break;
        }
    }
    if (!empty($featureConditions)) {
        $where[] = "(" . implode(" OR ", $featureConditions) . ")";
    }
}

if ($rating > 0) {
    $where[] = "(SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) >= ?";
    $params[] = $rating;
}

// Price filter (average menu item price)
if ($minPrice > 0 || $maxPrice > 0) {
    if ($minPrice > 0) {
        $where[] = "EXISTS (SELECT 1 FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id AND mi.price >= ?)";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $where[] = "EXISTS (SELECT 1 FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id AND mi.price <= ?)";
        $params[] = $maxPrice;
    }
}

// Count total restaurants
$countSql = "SELECT COUNT(DISTINCT r.restaurant_id) 
             FROM restaurants r
             LEFT JOIN stays s ON r.stay_id = s.stay_id
             LEFT JOIN locations l ON s.location_id = l.location_id
             WHERE " . implode(" AND ", $where);
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalCount = $stmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Sorting logic
switch ($sort) {
    case 'price_low':
        $orderBy = 'avg_price ASC';
        break;
    case 'price_high':
        $orderBy = 'avg_price DESC';
        break;
    case 'rating':
        $orderBy = 'avg_rating DESC, review_count DESC';
        break;
    case 'name':
        $orderBy = 'r.restaurant_name ASC';
        break;
    case 'recommended':
    default:
        $orderBy = 'avg_rating DESC, review_count DESC, r.restaurant_id DESC';
        break;
}

// Main query
$sql = "SELECT r.*, s.stay_name as hotel_name, s.city, l.name as location_name,
        (SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) as avg_rating,
        (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.restaurant_id) as review_count,
        (SELECT AVG(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as avg_price,
        (SELECT MIN(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as min_price,
        (SELECT MAX(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as max_price,
        (SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as menu_count,
        (SELECT image_path FROM restaurant_images WHERE restaurant_id = r.restaurant_id AND is_main = 1 LIMIT 1) as main_image
        FROM restaurants r
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        GROUP BY r.restaurant_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$restaurants = $stmt->fetchAll();

// Get cuisine types for filter
$cuisinesList = $db->query("
    SELECT DISTINCT TRIM(cuisine_type) as cuisine, COUNT(*) as count
    FROM restaurants
    WHERE is_active = 1 AND cuisine_type IS NOT NULL AND cuisine_type != ''
    GROUP BY TRIM(cuisine_type)
    ORDER BY count DESC
    LIMIT 15
")->fetchAll();

// Get popular locations for restaurants
$popularLocations = $db->query("
    SELECT l.location_id, l.name, l.type, COUNT(r.restaurant_id) as restaurant_count
    FROM locations l
    JOIN stays s ON l.location_id = s.location_id
    JOIN restaurants r ON s.stay_id = r.stay_id
    WHERE l.is_active = 1 AND r.is_active = 1
    GROUP BY l.location_id
    HAVING restaurant_count > 0
    ORDER BY restaurant_count DESC
    LIMIT 6
")->fetchAll();

// Get featured restaurants (top rated)
$featuredRestaurants = $db->query("
    SELECT r.*, s.stay_name as hotel_name, l.name as location_name,
           (SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.restaurant_id) as review_count,
           (SELECT image_path FROM restaurant_images WHERE restaurant_id = r.restaurant_id AND is_main = 1 LIMIT 1) as main_image
    FROM restaurants r
    JOIN stays s ON r.stay_id = s.stay_id
    JOIN locations l ON s.location_id = l.location_id
    WHERE r.is_active = 1
    HAVING avg_rating >= 4.5 OR review_count > 0
    ORDER BY avg_rating DESC, review_count DESC
    LIMIT 6
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
    /* ===== RESTAURANTS LANDING PAGE - EXACT BOOKING.COM STYLE ===== */
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
    .restaurants-hero {
        background: linear-gradient(135deg, var(--bkg-blue-dark), #001b4f);
        padding: 40px 0;
        margin-bottom: 32px;
    }

    .restaurants-hero h1 {
        color: white;
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .restaurants-hero p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 16px;
        margin-bottom: 0;
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
        font-size: 18px;
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
        min-width: 180px;
    }

    .quick-search-input {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 14px;
        transition: var(--transition);
    }

    .quick-search-input:focus {
        outline: none;
        border-color: var(--bkg-blue-primary);
        box-shadow: 0 0 0 3px rgba(0, 113, 194, 0.1);
    }

    .quick-search-select {
        width: 100%;
        padding: 12px 32px 12px 14px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 14px;
        background: white;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    .quick-search-btn {
        background: var(--bkg-blue-primary);
        color: white;
        border: none;
        padding: 12px 28px;
        font-weight: 600;
        font-size: 14px;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s;
        white-space: nowrap;
    }

    .quick-search-btn:hover {
        background: #005fa3;
    }

    /* Featured Restaurants */
    .featured-section {
        margin-bottom: 40px;
    }

    .featured-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 20px;
    }

    .featured-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }

    .featured-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        overflow: hidden;
        transition: var(--transition);
        cursor: pointer;
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: block;
    }

    .featured-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--bkg-blue-primary);
        transform: translateY(-4px);
    }

    .featured-image {
        height: 160px;
        position: relative;
        overflow: hidden;
    }

    .featured-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .featured-card:hover .featured-image img {
        transform: scale(1.08);
    }

    .featured-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--bkg-yellow);
        color: var(--bkg-gray-700);
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        z-index: 2;
    }

    .featured-content {
        padding: 16px;
    }

    .featured-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--bkg-blue-primary);
        margin-bottom: 4px;
    }

    .featured-cuisine {
        font-size: 13px;
        color: var(--bkg-gray-500);
        margin-bottom: 8px;
    }

    .featured-location {
        font-size: 12px;
        color: var(--bkg-gray-500);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .featured-location i {
        color: var(--bkg-blue-primary);
    }

    .featured-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--bkg-gray-200);
    }

    .featured-rating {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .featured-score {
        background: var(--bkg-blue-dark);
        color: white;
        padding: 4px 8px;
        border-radius: 4px 4px 4px 0;
        font-weight: 700;
        font-size: 14px;
    }

    /* Filter Sidebar */
    .restaurants-filter-sidebar {
        background: white;
        border-radius: 8px;
        padding: 24px;
        border: 1px solid var(--bkg-gray-200);
        position: sticky;
        top: 80px;
    }

    .filter-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .filter-section {
        margin-bottom: 24px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .filter-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .filter-subtitle {
        font-size: 14px;
        font-weight: 600;
        color: var(--bkg-gray-700);
        margin-bottom: 16px;
    }

    .price-range {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .price-input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 13px;
    }

    .price-input:focus {
        outline: none;
        border-color: var(--bkg-blue-primary);
        box-shadow: 0 0 0 2px rgba(0, 113, 194, 0.1);
    }

    .price-sep {
        color: var(--bkg-gray-500);
        font-size: 12px;
    }

    .filter-option {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
        font-size: 13px;
        color: var(--bkg-gray-700);
        cursor: pointer;
    }

    .filter-option input[type="checkbox"],
    .filter-option input[type="radio"] {
        margin-right: 8px;
        width: 16px;
        height: 16px;
        accent-color: var(--bkg-blue-primary);
        cursor: pointer;
    }

    .filter-option .count {
        margin-left: auto;
        color: var(--bkg-gray-500);
        font-size: 11px;
        background: var(--bkg-gray-100);
        padding: 2px 6px;
        border-radius: 12px;
    }

    /* Star Rating */
    .star-rating {
        display: inline-flex;
        gap: 2px;
        margin-right: 6px;
    }

    .star {
        color: #febb02;
        font-size: 12px;
    }

    .star.muted {
        color: #ddd;
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
        gap: 16px;
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

    .sort-section {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sort-section label {
        font-size: 13px;
        color: var(--bkg-gray-500);
    }

    .sort-select {
        padding: 8px 28px 8px 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 13px;
        background: white;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
    }

    .sort-select:hover {
        border-color: var(--bkg-blue-primary);
    }

    /* Restaurants Grid */
    .restaurants-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 40px;
    }

    .restaurant-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        overflow: hidden;
        transition: var(--transition);
        cursor: pointer;
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: flex;
        height: 220px;
    }

    .restaurant-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--bkg-blue-primary);
    }

    .card-image {
        width: 40%;
        position: relative;
        overflow: hidden;
        background: var(--bkg-gray-100);
    }

    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .restaurant-card:hover .card-image img {
        transform: scale(1.08);
    }

    .card-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--bkg-yellow);
        color: var(--bkg-gray-700);
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        z-index: 2;
    }

    .card-content {
        width: 60%;
        padding: 16px;
        display: flex;
        flex-direction: column;
    }

    .restaurant-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--bkg-blue-primary);
        margin-bottom: 4px;
        line-height: 1.3;
    }

    .restaurant-name:hover {
        text-decoration: underline;
    }

    .restaurant-cuisine {
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .restaurant-location {
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .restaurant-location i {
        color: var(--bkg-blue-primary);
        font-size: 12px;
    }

    .restaurant-features {
        display: flex;
        gap: 12px;
        font-size: 11px;
        color: var(--bkg-gray-500);
        margin-bottom: 10px;
        flex-wrap: wrap;
    }

    .restaurant-features span {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .restaurant-features i {
        color: var(--bkg-green);
        font-size: 11px;
    }

    .restaurant-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: auto;
        border-top: 1px solid var(--bkg-gray-200);
        padding-top: 10px;
    }

    .restaurant-rating {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .rating-score {
        background: var(--bkg-blue-dark);
        color: white;
        padding: 4px 8px;
        border-radius: 4px 4px 4px 0;
        font-weight: 700;
        font-size: 14px;
        line-height: 1;
    }

    .rating-text {
        font-size: 11px;
        line-height: 1.2;
    }

    .rating-label {
        font-weight: 600;
        color: var(--bkg-gray-700);
    }

    .rating-count {
        color: var(--bkg-gray-500);
    }

    .restaurant-price {
        text-align: right;
    }

    .price-label {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .price-range-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--bkg-gray-700);
    }

    /* Popular Locations */
    .locations-section {
        margin: 40px 0;
    }

    .locations-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 20px;
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
        padding: 20px 8px;
        text-align: center;
        transition: var(--transition);
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: block;
    }

    .location-card:hover {
        border-color: var(--bkg-blue-primary);
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .location-icon {
        width: 48px;
        height: 48px;
        background: var(--bkg-blue-light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        color: var(--bkg-blue-primary);
        font-size: 20px;
    }

    .location-card:hover .location-icon {
        background: var(--bkg-blue-primary);
        color: white;
    }

    .location-name {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .location-count {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    /* Pagination */
    .pagination-section {
        margin-top: 32px;
        text-align: center;
    }

    .pagination {
        display: inline-flex;
        gap: 6px;
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 6px;
    }

    .page-link {
        min-width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 10px;
        border: 1px solid transparent;
        border-radius: 4px;
        color: var(--bkg-gray-700);
        text-decoration: none;
        font-size: 13px;
        background: white;
        transition: var(--transition);
    }

    .page-link:hover {
        border-color: var(--bkg-blue-primary);
        background: var(--bkg-blue-light);
        color: var(--bkg-blue-primary);
    }

    .page-link.active {
        background: var(--bkg-blue-primary);
        color: white;
        border-color: var(--bkg-blue-primary);
    }

    .page-dots {
        display: flex;
        align-items: center;
        padding: 0 8px;
        color: var(--bkg-gray-500);
    }

    /* Buttons */
    .apply-filters {
        background: var(--bkg-blue-primary);
        color: white;
        border: none;
        padding: 12px;
        font-weight: 600;
        font-size: 14px;
        border-radius: 4px;
        width: 100%;
        cursor: pointer;
        transition: background-color 0.2s;
        margin-top: 16px;
    }

    .apply-filters:hover {
        background: #005fa3;
    }

    .clear-filters {
        background: transparent;
        color: var(--bkg-blue-primary);
        border: 1px solid var(--bkg-blue-primary);
        padding: 12px;
        font-weight: 600;
        font-size: 14px;
        border-radius: 4px;
        width: 100%;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
        text-align: center;
        margin-top: 8px;
    }

    .clear-filters:hover {
        background: var(--bkg-blue-light);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
        border: 1px solid var(--bkg-gray-200);
    }

    .empty-icon {
        font-size: 48px;
        color: var(--bkg-gray-500);
        margin-bottom: 16px;
    }

    .empty-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 8px;
    }

    .empty-text {
        color: var(--bkg-gray-500);
        font-size: 14px;
        margin-bottom: 24px;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .featured-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .restaurants-grid {
            grid-template-columns: 1fr;
        }

        .locations-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 768px) {
        .restaurants-hero h1 {
            font-size: 24px;
        }

        .featured-grid {
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

        .restaurant-card {
            flex-direction: column;
            height: auto;
        }

        .card-image {
            width: 100%;
            height: 160px;
        }

        .card-content {
            width: 100%;
        }
    }
</style>

<!-- Hero Banner -->
<section class="restaurants-hero">
    <div class="container">
        <h1>Restaurants in Rwanda</h1>
        <p>Discover <?php echo number_format($totalCount); ?> dining experiences across the country</p>
    </div>
</section>

<div class="container">


    <!-- Featured Restaurants -->
    <?php if (!empty($featuredRestaurants)): ?>
        <div class="featured-section">
            <h2 class="featured-title">Popular restaurants</h2>
            <div class="featured-grid">
                <?php foreach ($featuredRestaurants as $featured):
                    $avgRating = $featured['avg_rating'] ?: 0;
                    $image = $featured['main_image'];
                ?>
                    <a href="detail.php?id=<?php echo $featured['restaurant_id']; ?>" class="featured-card">
                        <div class="featured-image">
                            <?php
                            $imagePath = $featured['main_image']
                                ? '/gorwanda-plus/assets/images/restaurants/' . $featured['main_image']
                                : 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=60';
                            ?>
                            <img src="<?php echo $imagePath; ?>"
                                alt="<?php echo sanitize($featured['restaurant_name']); ?>"
                                loading="lazy"
                                onerror="this.src='https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=60'">
                            <span class="featured-badge">Popular</span>
                        </div>
                        <div class="featured-content">
                            <h3 class="featured-name"><?php echo sanitize($featured['restaurant_name']); ?></h3>
                            <div class="featured-cuisine"><?php echo sanitize($featured['cuisine_type'] ?: 'Various'); ?></div>
                            <div class="featured-location">
                                <i class="bi bi-geo-alt"></i>
                                <?php echo sanitize($featured['location_name'] ?: $featured['city'] ?: 'Rwanda'); ?>
                            </div>
                            <div class="featured-footer">
                                <div class="featured-rating">
                                    <?php if ($avgRating > 0): ?>
                                        <span class="featured-score"><?php echo number_format($avgRating, 1); ?></span>
                                        <span class="rating-count"><?php echo number_format($featured['review_count']); ?> reviews</span>
                                    <?php else: ?>
                                        <span class="featured-score" style="background: #6c757d;">New</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($featured['accepts_reservations']): ?>
                                    <i class="bi bi-calendar-check text-success" title="Accepts reservations"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
        <div class="locations-section">
            <h2 class="locations-title">Popular dining destinations</h2>
            <div class="locations-grid">
                <?php foreach ($popularLocations as $loc): ?>
                    <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                        <div class="location-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                        <div class="location-count"><?php echo $loc['restaurant_count']; ?> restaurants</div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="restaurants-filter-sidebar">
                <h5 class="filter-title">Filter by:</h5>

                <form method="GET" action="" id="filterForm">
                    <!-- Preserve search parameters -->
                    <?php if ($location): ?>
                        <input type="hidden" name="location" value="<?php echo sanitize($location); ?>">
                    <?php endif; ?>
                    <?php if ($date): ?>
                        <input type="hidden" name="date" value="<?php echo $date; ?>">
                    <?php endif; ?>
                    <?php if ($time): ?>
                        <input type="hidden" name="time" value="<?php echo $time; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="guests" value="<?php echo $guests; ?>">

                    <!-- Price Range (Average meal price) -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Average meal price</h6>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input"
                                placeholder="Min RWF" value="<?php echo $minPrice ?: ''; ?>" min="0">
                            <span class="price-sep">—</span>
                            <input type="number" name="max_price" class="price-input"
                                placeholder="Max RWF" value="<?php echo $maxPrice ?: ''; ?>" min="0">
                        </div>
                    </div>

                    <!-- Cuisine Type -->
                    <?php if (!empty($cuisinesList)): ?>
                        <div class="filter-section">
                            <h6 class="filter-subtitle">Cuisine</h6>
                            <?php foreach ($cuisinesList as $c): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="cuisine[]" value="<?php echo sanitize($c['cuisine']); ?>"
                                        <?php echo in_array($c['cuisine'], $cuisines) ? 'checked' : ''; ?>>
                                    <span><?php echo sanitize($c['cuisine']); ?></span>
                                    <span class="count"><?php echo $c['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Features -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Features</h6>
                        <label class="filter-option">
                            <input type="checkbox" name="features[]" value="outdoor"
                                <?php echo in_array('outdoor', $features) ? 'checked' : ''; ?>>
                            <span>Outdoor seating</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="features[]" value="private"
                                <?php echo in_array('private', $features) ? 'checked' : ''; ?>>
                            <span>Private dining</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="features[]" value="reservations"
                                <?php echo in_array('reservations', $features) ? 'checked' : ''; ?>>
                            <span>Accepts reservations</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="features[]" value="parking"
                                <?php echo in_array('parking', $features) ? 'checked' : ''; ?>>
                            <span>Parking available</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="features[]" value="wifi"
                                <?php echo in_array('wifi', $features) ? 'checked' : ''; ?>>
                            <span>Free WiFi</span>
                        </label>
                    </div>

                    <!-- Rating -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Guest rating</h6>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <label class="filter-option">
                                <input type="radio" name="rating" value="<?php echo $i; ?>"
                                    <?php echo $rating == $i ? 'checked' : ''; ?>>
                                <span class="star-rating">
                                    <?php for ($j = 1; $j <= 5; $j++): ?>
                                        <i class="bi bi-star-fill <?php echo $j <= $i ? 'star' : 'star muted'; ?>"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="count"><?php echo $i; ?>+ stars</span>
                            </label>
                        <?php endfor; ?>
                        <label class="filter-option">
                            <input type="radio" name="rating" value="0" <?php echo $rating == 0 ? 'checked' : ''; ?>>
                            <span>Any rating</span>
                        </label>
                    </div>

                    <button type="submit" class="apply-filters">Apply filters</button>

                    <?php if ($minPrice || $maxPrice || $rating || !empty($cuisines) || !empty($features)): ?>
                        <a href="?" class="clear-filters">Clear filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            <!-- Results Header -->
            <div class="results-header">
                <div class="results-count">
                    <strong><?php echo number_format($totalCount); ?></strong> restaurants
                    <?php if ($location): ?> matching <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>

                <div class="sort-section">
                    <label for="sortSelect">Sort by:</label>
                    <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name</option>
                    </select>
                </div>
            </div>

            <!-- Restaurants Grid -->
            <?php if (empty($restaurants)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-shop"></i>
                    </div>
                    <h3 class="empty-title">No restaurants found</h3>
                    <p class="empty-text">Try adjusting your filters or search in a different location</p>
                    <a href="?" class="apply-filters" style="width: auto; padding: 12px 32px;">Clear all filters</a>
                </div>
            <?php else: ?>
                <div class="restaurants-grid">
                    <?php foreach ($restaurants as $restaurant):
                        $avgRating = $restaurant['avg_rating'] ?: 0;
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating * 2) : ['New', 'bg-secondary']; // Convert 5-star to 10-point scale
                        $image = $restaurant['main_image'];

                        // Format price range
                        $priceRange = '';
                        if ($restaurant['min_price'] > 0 && $restaurant['max_price'] > 0) {
                            $priceRange = formatPrice($restaurant['min_price']) . ' - ' . formatPrice($restaurant['max_price']);
                        } elseif ($restaurant['avg_price'] > 0) {
                            $priceRange = formatPrice($restaurant['avg_price']);
                        }

                        // Check features
                        $hasOutdoor = $restaurant['has_outdoor_seating'];
                        $hasPrivate = $restaurant['has_private_dining'];
                        $acceptsReservations = $restaurant['accepts_reservations'];
                    ?>
                        <a href="detail.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="restaurant-card">
                            <div class="card-image">
                                <?php
                                $imagePath = $restaurant['main_image']
                                    ? '/gorwanda-plus/assets/images/restaurants/' . $restaurant['main_image']
                                    : 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=60';
                                ?>
                                <img src="<?php echo $imagePath; ?>"
                                    alt="<?php echo sanitize($restaurant['restaurant_name']); ?>"
                                    loading="lazy"
                                    onerror="this.src='https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=60'">

                                <?php if ($acceptsReservations): ?>
                                    <span class="card-badge">Reservations</span>
                                <?php endif; ?>
                            </div>

                            <div class="card-content">
                                <h3 class="restaurant-name"><?php echo sanitize($restaurant['restaurant_name']); ?></h3>
                                <div class="restaurant-cuisine"><?php echo sanitize($restaurant['cuisine_type'] ?: 'Various Cuisines'); ?></div>

                                <div class="restaurant-location">
                                    <i class="bi bi-geo-alt"></i>
                                    <?php echo sanitize($restaurant['location_name'] ?: $restaurant['city'] ?: 'Rwanda'); ?>
                                    <?php if ($restaurant['hotel_name']): ?>
                                        <span class="text-muted"> · in <?php echo sanitize($restaurant['hotel_name']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="restaurant-features">
                                    <?php if ($hasOutdoor): ?>
                                        <span><i class="bi bi-sun"></i> Outdoor</span>
                                    <?php endif; ?>
                                    <?php if ($hasPrivate): ?>
                                        <span><i class="bi bi-door-closed"></i> Private</span>
                                    <?php endif; ?>
                                    <?php if ($restaurant['seating_capacity']): ?>
                                        <span><i class="bi bi-people"></i> <?php echo $restaurant['seating_capacity']; ?> seats</span>
                                    <?php endif; ?>
                                </div>

                                <div class="restaurant-footer">
                                    <div class="restaurant-rating">
                                        <?php if ($restaurant['review_count'] > 0): ?>
                                            <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                                            <div class="rating-text">
                                                <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                                                <div class="rating-count"><?php echo number_format($restaurant['review_count']); ?> reviews</div>
                                            </div>
                                        <?php else: ?>
                                            <span class="rating-score" style="background: #6c757d;">New</span>
                                            <div class="rating-text">
                                                <div class="rating-label">New</div>
                                                <div class="rating-count">0 reviews</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($priceRange): ?>
                                        <div class="restaurant-price">
                                            <div class="price-label">avg. meal</div>
                                            <div class="price-range-value"><?php echo $priceRange; ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-section">
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link" aria-label="Previous">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);

                            if ($start > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">1</a>
                                <?php if ($start > 2): ?>
                                    <span class="page-dots">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>

                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?>
                                    <span class="page-dots">...</span>
                                <?php endif; ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-link"><?php echo $totalPages; ?></a>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link" aria-label="Next">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
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
        if (dateInput) {
            dateInput.min = today;
        }
    });

    // Ensure price inputs are numbers only
    document.querySelectorAll('.price-input').forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (isNaN(String.fromCharCode(e.keyCode)) && e.keyCode !== 8) {
                e.preventDefault();
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>