<?php
$pageTitle = 'Search Results - GoRwanda+';
require_once 'includes/header.php';

// Get search parameters
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'stays';
$location = sanitize($_GET['location'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Filters
$sort = $_GET['sort'] ?? 'recommended';
$minPrice = floatval($_GET['min_price'] ?? 0);
$maxPrice = floatval($_GET['max_price'] ?? 0);
$rating = intval($_GET['rating'] ?? 0);
$propertyType = $_GET['property_type'] ?? '';

$db = getDB();
$taxRate = getTaxRate();

// Initialize results
$results = [];
$totalCount = 0;

// ============================================
// STAYS SEARCH
// ============================================
if ($type === 'stays') {
    $checkin = $_GET['checkin'] ?? '';
    $checkout = $_GET['checkout'] ?? '';
    $guests = intval($_GET['guests'] ?? 2);

    $where = ["s.is_active = 1", "s.is_verified = 1"];
    $params = [];

    if ($location) {
        $where[] = "(s.stay_name LIKE ? OR l.name LIKE ? OR s.address LIKE ? OR s.city LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    if (!empty($propertyType)) {
        $where[] = "s.stay_type = ?";
        $params[] = $propertyType;
    }

    if ($rating > 0) {
        $where[] = "COALESCE(s.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id)) >= ?";
        $params[] = $rating;
    }

    if ($minPrice > 0) {
        $where[] = "(SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $where[] = "(SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) <= ?";
        $params[] = $maxPrice;
    }

    // Count query
    $countSql = "SELECT COUNT(DISTINCT s.stay_id) FROM stays s LEFT JOIN locations l ON s.location_id = l.location_id WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();

    // Main query
    if ($sort === 'price_low') {
        $orderBy = 'min_price ASC';
    } elseif ($sort === 'price_high') {
        $orderBy = 'min_price DESC';
    } else {
        $orderBy = 'avg_rating DESC, review_count DESC';
    }

    $sql = "SELECT 
                s.*, 
                l.name as location_name,
                'stay' as result_type,
                s.stay_id as item_id,
                s.stay_name as item_name,
                s.main_image as item_image,
                (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
                (SELECT MAX(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as max_price,
                (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as room_count,
                (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
                COALESCE(s.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id)) as avg_rating,
                s.stay_type as sub_type,
                s.address as location_detail
            FROM stays s
            LEFT JOIN locations l ON s.location_id = l.location_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY s.stay_id
            ORDER BY {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}


// ============================================
// RESTAURANTS SEARCH
// ============================================
elseif ($type === 'restaurants') {
    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    $guests = intval($_GET['guests'] ?? 2);

    $where = ["r.is_active = 1"];
    $params = [];

    if ($location) {
        $where[] = "(r.restaurant_name LIKE ? OR l.name LIKE ? OR s.stay_name LIKE ? OR s.city LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    if ($rating > 0) {
        $where[] = "(SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) >= ?";
        $params[] = $rating;
    }

    if ($minPrice > 0) {
        $where[] = "EXISTS (SELECT 1 FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id AND mi.price >= ?)";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $where[] = "EXISTS (SELECT 1 FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id AND mi.price <= ?)";
        $params[] = $maxPrice;
    }

    $countSql = "SELECT COUNT(DISTINCT r.restaurant_id) 
                 FROM restaurants r
                 LEFT JOIN stays s ON r.stay_id = s.stay_id
                 LEFT JOIN locations l ON s.location_id = l.location_id
                 WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();

    $sql = "SELECT r.*, s.stay_name as hotel_name, s.city, l.name as location_name,
            (SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) as avg_rating,
            (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.restaurant_id) as review_count,
            (SELECT AVG(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as avg_price,
            (SELECT MIN(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as min_price,
            (SELECT MAX(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as max_price,
            (SELECT image_path FROM restaurant_images WHERE restaurant_id = r.restaurant_id AND is_main = 1 LIMIT 1) as main_image
            FROM restaurants r
            LEFT JOIN stays s ON r.stay_id = s.stay_id
            LEFT JOIN locations l ON s.location_id = l.location_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY r.restaurant_id
            ORDER BY avg_rating DESC
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}


// ============================================
// CARS SEARCH
// ============================================
elseif ($type === 'cars') {
    $pickupDate = $_GET['pickup_date'] ?? '';
    $returnDate = $_GET['return_date'] ?? '';

    $where = ["cf.is_active = 1", "cr.is_active = 1", "cr.is_verified = 1"];
    $params = [];

    if ($location) {
        $where[] = "(cr.company_name LIKE ? OR l.name LIKE ? OR cr.address LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $like]);
    }

    if ($rating > 0) {
        $where[] = "cr.avg_rating >= ?";
        $params[] = $rating;
    }

    if ($minPrice > 0) {
        $where[] = "cf.daily_rate >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $where[] = "cf.daily_rate <= ?";
        $params[] = $maxPrice;
    }

    $countSql = "SELECT COUNT(*) FROM car_fleet cf 
                 JOIN car_rentals cr ON cf.rental_id = cr.rental_id 
                 LEFT JOIN locations l ON cr.location_id = l.location_id 
                 WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();

    if ($sort === 'price_low') {
        $orderBy = 'cf.daily_rate ASC';
    } elseif ($sort === 'price_high') {
        $orderBy = 'cf.daily_rate DESC';
    } else {
        $orderBy = 'cr.avg_rating DESC, cr.review_count DESC';
    }

    $sql = "SELECT 
                cf.*, 
                cr.company_name, 
                cr.pickup_locations, 
                cr.avg_rating, 
                cr.review_count, 
                l.name as location_name,
                'car' as result_type,
                cf.car_id as item_id,
                CONCAT(cf.brand, ' ', cf.model) as item_name,
                cf.images as item_image,
                cf.daily_rate as min_price,
                (SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id) as total_reviews
            FROM car_fleet cf
            JOIN car_rentals cr ON cf.rental_id = cr.rental_id
            LEFT JOIN locations l ON cr.location_id = l.location_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY cf.car_id
            ORDER BY {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Process images for cars
    foreach ($results as &$car) {
        $images = json_decode($car['item_image'] ?? '[]', true);
        $car['item_image'] = !empty($images) ? $images[0] : null;
    }
}

// ============================================
// ATTRACTIONS SEARCH
// ============================================
elseif ($type === 'attractions') {
    $date = $_GET['date'] ?? '';
    $guests = intval($_GET['guests'] ?? 2);

    $where = ["a.is_active = 1", "a.is_verified = 1"];
    $params = [];

    if ($location) {
        $where[] = "(a.attraction_name LIKE ? OR l.name LIKE ? OR a.description LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $like]);
    }

    if ($rating > 0) {
        $where[] = "COALESCE(a.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id)) >= ?";
        $params[] = $rating;
    }

    if ($minPrice > 0) {
        $where[] = "(SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) >= ?";
        $params[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $where[] = "(SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) <= ?";
        $params[] = $maxPrice;
    }

    $countSql = "SELECT COUNT(DISTINCT a.attraction_id) FROM attractions a LEFT JOIN locations l ON a.location_id = l.location_id WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();

    if ($sort === 'price_low') {
        $orderBy = 'min_price ASC';
    } elseif ($sort === 'price_high') {
        $orderBy = 'min_price DESC';
    } else {
        $orderBy = 'avg_rating DESC, review_count DESC';
    }

    $sql = "SELECT 
                a.*, 
                c.name as category_name, 
                c.icon as category_icon, 
                l.name as location_name,
                'attraction' as result_type,
                a.attraction_id as item_id,
                a.attraction_name as item_name,
                a.main_image as item_image,
                (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price,
                (SELECT MAX(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as max_price,
                (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count,
                COALESCE(a.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id)) as avg_rating,
                a.duration_minutes,
                a.difficulty_level
            FROM attractions a
            LEFT JOIN categories c ON a.category_id = c.category_id
            LEFT JOIN locations l ON a.location_id = l.location_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY a.attraction_id
            ORDER BY {$orderBy}
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}

$totalPages = ceil($totalCount / $perPage);

// Get filter options for sidebar (only for stays since they have property types)
$propertyTypes = [];
if ($type === 'stays') {
    $stmt = $db->query("SELECT stay_type, COUNT(*) as count FROM stays WHERE is_active = 1 AND is_verified = 1 GROUP BY stay_type ORDER BY count DESC");
    $propertyTypes = $stmt->fetchAll();
}

// Helper function to get type label
function getTypeLabel($type)
{
    switch ($type) {
        case 'stay':
            return 'Accommodation';
        case 'car':
            return 'Car Rental';
        case 'attraction':
            return 'Experience';
        default:
            return 'Property';
    }
}

function getTypeIcon($type)
{
    switch ($type) {
        case 'stay':
            return '🏨';
        case 'car':
            return '🚗';
        case 'attraction':
            return '🎟️';
        default:
            return '📍';
    }
}
?>

<style>
    /* ===== BOOKING.COM STYLE SEARCH PAGE ===== */
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
        --bkg-white: #ffffff;
        --shadow-sm: 0 1px 4px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.2s ease;
    }

    .bkg-search-page {
        background: #f5f5f5;
        padding: 24px 0;
        min-height: calc(100vh - 56px);
    }

    /* Loading Spinner */
    .bkg-spinner {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 9999;
        background: white;
        padding: 20px;
        border-radius: 50%;
        box-shadow: var(--shadow-lg);
        display: none;
    }

    .bkg-spinner.active {
        display: block;
    }

    .bkg-spinner-inner {
        width: 40px;
        height: 40px;
        border: 3px solid #e7e7e7;
        border-top-color: var(--bkg-blue-primary);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Results Header */
    .bkg-results-header {
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

    .bkg-results-count {
        font-size: 14px;
        color: var(--bkg-gray-500);
    }

    .bkg-results-count strong {
        color: var(--bkg-gray-700);
        font-size: 16px;
    }

    .bkg-sort {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .bkg-sort label {
        font-size: 13px;
        color: var(--bkg-gray-500);
    }

    .bkg-sort-select {
        padding: 8px 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 13px;
        background: white;
        cursor: pointer;
        transition: var(--transition);
    }

    /* Filter Sidebar */
    .bkg-filter-sidebar {
        background: white;
        border-radius: 8px;
        padding: 24px;
        border: 1px solid var(--bkg-gray-200);
        position: sticky;
        top: 80px;
    }

    .bkg-filter-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-filter-title i {
        color: var(--bkg-blue-primary);
    }

    .bkg-filter-section {
        margin-bottom: 24px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .bkg-filter-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .bkg-filter-subtitle {
        font-size: 14px;
        font-weight: 600;
        color: var(--bkg-gray-700);
        margin-bottom: 16px;
    }

    .bkg-price-range {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .bkg-price-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        font-size: 13px;
        transition: var(--transition);
    }

    .bkg-price-input:focus {
        outline: none;
        border-color: var(--bkg-blue-primary);
        box-shadow: 0 0 0 3px rgba(0, 113, 194, 0.1);
    }

    .bkg-price-sep {
        color: var(--bkg-gray-500);
        font-size: 14px;
    }

    .bkg-filter-option {
        display: flex;
        align-items: center;
        margin-bottom: 12px;
        font-size: 14px;
        color: var(--bkg-gray-700);
        cursor: pointer;
    }

    .bkg-filter-option input {
        margin-right: 10px;
        width: 16px;
        height: 16px;
        accent-color: var(--bkg-blue-primary);
        cursor: pointer;
    }

    .bkg-filter-option .count {
        margin-left: auto;
        color: var(--bkg-gray-500);
        font-size: 12px;
        background: var(--bkg-gray-100);
        padding: 2px 8px;
        border-radius: 12px;
    }

    .bkg-star-rating {
        display: inline-flex;
        gap: 2px;
        margin-right: 8px;
    }

    .bkg-star {
        color: #febb02;
        font-size: 14px;
    }

    .bkg-star.muted {
        color: #ddd;
    }

    /* Result Cards */
    .bkg-result-card {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 16px;
        display: flex;
        transition: var(--transition);
        border: 1px solid var(--bkg-gray-200);
        animation: fadeInUp 0.4s ease;
        cursor: pointer;
    }

    .bkg-result-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--bkg-blue-primary);
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .bkg-card-image {
        width: 250px;
        position: relative;
        overflow: hidden;
        background: #f5f5f5;
        flex-shrink: 0;
    }

    .bkg-card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .bkg-result-card:hover .bkg-card-image img {
        transform: scale(1.05);
    }

    .bkg-card-badge {
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

    .bkg-card-badge.verified {
        background: #008009;
    }

    .bkg-type-badge {
        position: absolute;
        bottom: 12px;
        left: 12px;
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
        z-index: 2;
    }

    .bkg-card-content {
        flex: 1;
        padding: 16px 20px;
        display: flex;
        gap: 20px;
    }

    .bkg-card-main {
        flex: 1;
    }

    .bkg-card-side {
        width: 200px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: flex-end;
    }

    .bkg-property-type {
        font-size: 12px;
        color: var(--bkg-gray-500);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .bkg-property-name {
        font-size: 16px;
        font-weight: 700;
        color: var(--bkg-blue-primary);
        margin-bottom: 6px;
    }

    .bkg-property-name:hover {
        text-decoration: underline;
    }

    .bkg-location {
        font-size: 13px;
        color: var(--bkg-gray-500);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .bkg-location i {
        color: var(--bkg-blue-primary);
        font-size: 12px;
    }

    .bkg-tags {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .bkg-tag {
        font-size: 11px;
        padding: 4px 10px;
        background: var(--bkg-gray-100);
        border-radius: 20px;
        color: var(--bkg-gray-500);
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .bkg-tag i {
        color: #008009;
        font-size: 11px;
    }

    .bkg-rating {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .bkg-rating-score {
        background: var(--bkg-blue-dark);
        color: white;
        padding: 6px 10px;
        border-radius: 4px 4px 4px 0;
        font-weight: 700;
        font-size: 16px;
        line-height: 1;
    }

    .bkg-rating-text {
        text-align: right;
    }

    .bkg-rating-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--bkg-gray-700);
    }

    .bkg-rating-count {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .bkg-price-block {
        text-align: right;
        margin-bottom: 16px;
    }

    .bkg-price-value {
        font-size: 20px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        line-height: 1.2;
    }

    .bkg-price-info {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .bkg-price-info strong {
        color: #008009;
    }

    .bkg-view-btn {
        background: var(--bkg-blue-primary);
        color: white;
        border: none;
        padding: 10px 20px;
        font-weight: 600;
        font-size: 13px;
        border-radius: 4px;
        width: 100%;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .bkg-view-btn:hover {
        background: #005fa3;
    }

    /* Apply Filters Button */
    .bkg-apply-filters {
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

    .bkg-apply-filters:hover {
        background: #005fa3;
    }

    .bkg-clear-filters {
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
        text-align: center;
        margin-top: 8px;
    }

    /* Empty State */
    .bkg-empty {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
        border: 1px solid var(--bkg-gray-200);
    }

    .bkg-empty-icon {
        font-size: 48px;
        color: var(--bkg-gray-500);
        margin-bottom: 16px;
    }

    .bkg-empty-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 8px;
    }

    .bkg-empty-text {
        color: var(--bkg-gray-500);
        font-size: 14px;
        margin-bottom: 24px;
    }

    .bkg-empty-btn {
        background: var(--bkg-blue-primary);
        color: white;
        padding: 12px 32px;
        border-radius: 4px;
        text-decoration: none;
        display: inline-block;
    }

    /* Pagination */
    .bkg-pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 32px;
    }

    .bkg-page-link {
        min-width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 4px;
        color: var(--bkg-gray-700);
        text-decoration: none;
        font-size: 13px;
        background: white;
        transition: var(--transition);
    }

    .bkg-page-link:hover {
        border-color: var(--bkg-blue-primary);
        background: var(--bkg-blue-light);
        color: var(--bkg-blue-primary);
    }

    .bkg-page-link.active {
        background: var(--bkg-blue-primary);
        color: white;
        border-color: var(--bkg-blue-primary);
    }

    .bkg-page-dots {
        display: flex;
        align-items: center;
        padding: 0 8px;
        color: var(--bkg-gray-500);
    }

    /* Responsive */
    @media (max-width: 992px) {
        .bkg-card-image {
            width: 200px;
        }

        .bkg-result-card {
            flex-direction: column;
        }

        .bkg-card-image {
            width: 100%;
            height: 180px;
        }

        .bkg-card-content {
            flex-direction: column;
        }

        .bkg-card-side {
            width: 100%;
            align-items: flex-start;
            border-top: 1px solid var(--bkg-gray-200);
            padding-top: 16px;
            margin-top: 16px;
        }

        .bkg-rating {
            justify-content: flex-start;
        }

        .bkg-rating-text {
            text-align: left;
        }

        .bkg-price-block {
            text-align: left;
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .bkg-filter-sidebar {
            position: static;
            margin-bottom: 20px;
        }

        .bkg-results-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .bkg-sort {
            width: 100%;
        }

        .bkg-sort-select {
            flex: 1;
        }
    }
</style>

<!-- Loading Spinner -->
<div class="bkg-spinner" id="loadingSpinner">
    <div class="bkg-spinner-inner"></div>
</div>

<div class="bkg-search-page">
    <div class="container">
        <div class="row">
            <!-- Sidebar Filters -->
            <div class="col-lg-3 mb-4">
                <div class="bkg-filter-sidebar">
                    <h5 class="bkg-filter-title">
                        <i class="bi bi-funnel"></i>
                        Filters
                    </h5>

                    <form method="GET" action="search.php" id="filterForm">
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                        <input type="hidden" name="location" value="<?php echo htmlspecialchars($location); ?>">

                        <?php if ($type === 'stays'): ?>
                            <input type="hidden" name="checkin" value="<?php echo htmlspecialchars($_GET['checkin'] ?? ''); ?>">
                            <input type="hidden" name="checkout" value="<?php echo htmlspecialchars($_GET['checkout'] ?? ''); ?>">
                            <input type="hidden" name="guests" value="<?php echo intval($_GET['guests'] ?? 2); ?>">
                        <?php elseif ($type === 'cars'): ?>
                            <input type="hidden" name="pickup_date" value="<?php echo htmlspecialchars($_GET['pickup_date'] ?? ''); ?>">
                            <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($_GET['return_date'] ?? ''); ?>">
                        <?php elseif ($type === 'attractions'): ?>
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>">
                            <input type="hidden" name="guests" value="<?php echo intval($_GET['guests'] ?? 2); ?>">
                        <?php endif; ?>

                        <!-- Price Range -->
                        <div class="bkg-filter-section">
                            <h6 class="bkg-filter-subtitle">Price range</h6>
                            <div class="bkg-price-range">
                                <input type="number" name="min_price" class="bkg-price-input"
                                    placeholder="Min" value="<?php echo $minPrice ?: ''; ?>" min="0" step="1000">
                                <span class="bkg-price-sep">—</span>
                                <input type="number" name="max_price" class="bkg-price-input"
                                    placeholder="Max" value="<?php echo $maxPrice ?: ''; ?>" min="0" step="1000">
                            </div>
                            <small class="text-muted" style="font-size: 11px;">
                                <?php echo $type === 'stays' ? 'per night' : ($type === 'cars' ? 'per day' : 'per person'); ?>
                            </small>
                        </div>

                        <!-- Property Type (only for stays) -->
                        <?php if ($type === 'stays' && !empty($propertyTypes)): ?>
                            <div class="bkg-filter-section">
                                <h6 class="bkg-filter-subtitle">Property type</h6>
                                <?php foreach ($propertyTypes as $pt): ?>
                                    <label class="bkg-filter-option">
                                        <input type="radio" name="property_type" value="<?php echo $pt['stay_type']; ?>"
                                            <?php echo $propertyType == $pt['stay_type'] ? 'checked' : ''; ?>>
                                        <span><?php echo ucfirst($pt['stay_type']); ?></span>
                                        <span class="count"><?php echo $pt['count']; ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="bkg-filter-option">
                                    <input type="radio" name="property_type" value="" <?php echo empty($propertyType) ? 'checked' : ''; ?>>
                                    <span>All types</span>
                                </label>
                            </div>
                        <?php endif; ?>

                        <!-- Rating -->
                        <div class="bkg-filter-section">
                            <h6 class="bkg-filter-subtitle">Guest rating</h6>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <label class="bkg-filter-option">
                                    <input type="radio" name="rating" value="<?php echo $i * 2; ?>"
                                        <?php echo $rating == $i * 2 ? 'checked' : ''; ?>>
                                    <span class="bkg-star-rating">
                                        <?php for ($j = 1; $j <= 5; $j++): ?>
                                            <i class="bi bi-star-fill <?php echo $j <= $i ? 'bkg-star' : 'bkg-star muted'; ?>"></i>
                                        <?php endfor; ?>
                                    </span>
                                    <span class="count"><?php echo $i; ?>+ stars</span>
                                </label>
                            <?php endfor; ?>
                            <label class="bkg-filter-option">
                                <input type="radio" name="rating" value="0" <?php echo $rating == 0 ? 'checked' : ''; ?>>
                                <span>Any rating</span>
                            </label>
                        </div>

                        <button type="submit" class="bkg-apply-filters">Apply filters</button>

                        <?php if ($minPrice || $maxPrice || $rating || !empty($propertyType)): ?>
                            <a href="?type=<?php echo $type; ?>&location=<?php echo urlencode($location); ?>" class="bkg-clear-filters">
                                Clear filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Results -->
            <div class="col-lg-9">
                <!-- Results Header -->
                <div class="bkg-results-header">
                    <div class="bkg-results-count">
                        <strong><?php echo number_format($totalCount); ?></strong>
                        <?php echo $type === 'stays' ? 'properties' : ($type === 'cars' ? 'vehicles' : 'experiences'); ?> found
                        <?php if ($location): ?>
                            in <strong><?php echo htmlspecialchars($location); ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="bkg-sort">
                        <label for="sortSelect">Sort by:</label>
                        <select id="sortSelect" class="bkg-sort-select" onchange="applySort(this.value)">
                            <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                            <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                            <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                            <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        </select>
                    </div>
                </div>

                <!-- Results List -->
                <?php if (empty($results)): ?>
                    <div class="bkg-empty">
                        <div class="bkg-empty-icon">
                            <i class="bi bi-search"></i>
                        </div>
                        <h3 class="bkg-empty-title">No results found</h3>
                        <p class="bkg-empty-text">Try adjusting your filters or search in a different location</p>
                        <a href="index.php" class="bkg-empty-btn">Back to home</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($results as $index => $item):
                        $itemId = $item['item_id'];
                        $name = $item['item_name'];
                        $locationName = $item['location_name'] ?? ($item['address'] ?? 'Rwanda');
                        $ratingVal = $item['avg_rating'] ?? 0;
                        $reviews = $item['review_count'] ?? ($item['total_reviews'] ?? 0);
                        $minPrice = $item['min_price'] ?? 0;
                        $priceWithTax = displayCustomerPrice($minPrice);
                        $reviewLabel = $ratingVal ? getReviewLabel($ratingVal) : ['New', 'bg-secondary'];

                        if ($type === 'stays') {
                            $link = "stays/detail.php?id={$itemId}";
                            $typeLabel = ucfirst($item['sub_type'] ?? 'Accommodation');
                            $priceLabel = 'night';
                            $amenities = json_decode($item['amenities'] ?? '[]', true);
                            $typeIcon = '🏨';
                            // CORRECTED: Get image for stays
                            $imageUrl = getImageUrl($item['item_image'], 'stay');

                            // Calculate total for stays with dates
                            $totalPriceWithTax = $priceWithTax;
                            if (!empty($_GET['checkin']) && !empty($_GET['checkout'])) {
                                $nights = max(1, (strtotime($_GET['checkout']) - strtotime($_GET['checkin'])) / 86400);
                                $totalPriceWithTax = displayCustomerPrice($minPrice * $nights);
                            }
                        } elseif ($type === 'cars') {
                            $link = "cars/detail.php?id={$itemId}";
                            $typeLabel = ucfirst($item['car_type'] ?? 'Car');
                            $priceLabel = 'day';
                            $priceWithTax = displayCustomerPrice($minPrice);
                            $totalPriceWithTax = $priceWithTax;
                            $typeIcon = '🚗';
                            // CORRECTED: Parse car images from JSON
                            $carImages = json_decode($item['item_image'] ?? '[]', true);
                            $carImage = !empty($carImages) ? $carImages[0] : null;
                            $imageUrl = getImageUrl($carImage, 'car');
                            // CORRECTED: Get features for cars
                            $amenities = json_decode($item['features'] ?? '[]', true);
                        } else {
                            $link = "attractions/detail.php?id={$itemId}";
                            $typeLabel = $item['category_name'] ?? 'Experience';
                            $priceLabel = 'person';
                            $typeIcon = '🎟️';
                            // CORRECTED: Get image for attractions
                            $imageUrl = getImageUrl($item['item_image'], 'attraction');
                            $amenities = json_decode($item['included_items'] ?? '[]', true);
                            $totalPriceWithTax = $priceWithTax;
                        }
                    ?>
                        <div class="bkg-result-card" onclick="window.location.href='<?php echo $link; ?>'" style="animation-delay: <?php echo $index * 0.05; ?>s">
                            <div class="bkg-card-image">
                                <img src="<?php echo $imageUrl; ?>"
                                    alt="<?php echo htmlspecialchars($name); ?>"
                                    loading="lazy"
                                    onerror="this.src='https://placehold.co/400x300?text=No+Image'">

                                <div class="bkg-type-badge">
                                    <?php echo $typeIcon; ?> <?php echo getTypeLabel($type === 'stays' ? 'stay' : ($type === 'cars' ? 'car' : 'attraction')); ?>
                                </div>
                            </div>

                            <div class="bkg-card-content">
                                <div class="bkg-card-main">
                                    <div class="bkg-property-type"><?php echo htmlspecialchars($typeLabel); ?></div>
                                    <div class="bkg-property-name"><?php echo htmlspecialchars($name); ?></div>

                                    <div class="bkg-location">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        <?php echo htmlspecialchars($locationName); ?>
                                    </div>

                                    <?php if (!empty($amenities)): ?>
                                        <div class="bkg-tags">
                                            <?php foreach (array_slice($amenities, 0, 3) as $amenity): ?>
                                                <span class="bkg-tag">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($amenity))); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($type === 'cars' && isset($item['seats'])): ?>
                                        <div class="bkg-tags">
                                            <span class="bkg-tag"><i class="bi bi-people"></i> <?php echo $item['seats']; ?> seats</span>
                                            <span class="bkg-tag"><i class="bi bi-gear"></i> <?php echo ucfirst($item['transmission']); ?></span>
                                            <span class="bkg-tag"><i class="bi bi-fuel-pump"></i> <?php echo ucfirst($item['fuel_type']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($type === 'attractions' && isset($item['duration_minutes']) && $item['duration_minutes']): ?>
                                        <div class="bkg-tags">
                                            <span class="bkg-tag"><i class="bi bi-clock"></i> <?php echo floor($item['duration_minutes'] / 60); ?>h <?php echo $item['duration_minutes'] % 60; ?>m</span>
                                            <?php if (isset($item['difficulty_level']) && $item['difficulty_level']): ?>
                                                <span class="bkg-tag"><i class="bi bi-activity"></i> <?php echo ucfirst($item['difficulty_level']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="bkg-card-side">
                                    <?php if ($reviews > 0): ?>
                                        <div class="bkg-rating">
                                            <div class="bkg-rating-text">
                                                <div class="bkg-rating-label"><?php echo $reviewLabel[0]; ?></div>
                                                <div class="bkg-rating-count"><?php echo number_format($reviews); ?> reviews</div>
                                            </div>
                                            <div class="bkg-rating-score"><?php echo number_format($ratingVal, 1); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bkg-price-block">
                                        <div class="bkg-price-value"><?php echo $priceWithTax; ?></div>
                                        <div class="bkg-price-info">
                                            per <?php echo $priceLabel; ?>
                                            <?php if ($type === 'stays' && isset($totalPriceWithTax) && $totalPriceWithTax != $priceWithTax): ?>
                                                <br><strong><?php echo $totalPriceWithTax; ?></strong> total
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <button class="bkg-view-btn" onclick="event.stopPropagation(); window.location.href='<?php echo $link; ?>'">
                                        View details
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="bkg-pagination">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="bkg-page-link" onclick="showLoading()">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);

                            if ($start > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="bkg-page-link" onclick="showLoading()">1</a>
                                <?php if ($start > 2): ?>
                                    <span class="bkg-page-dots">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="bkg-page-link <?php echo $i === $page ? 'active' : ''; ?>"
                                    onclick="showLoading()"><?php echo $i; ?></a>
                            <?php endfor; ?>

                            <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?>
                                    <span class="bkg-page-dots">...</span>
                                <?php endif; ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="bkg-page-link" onclick="showLoading()"><?php echo $totalPages; ?></a>
                            <?php endif; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="bkg-page-link" onclick="showLoading()">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Show loading spinner
    function showLoading() {
        document.getElementById('loadingSpinner')?.classList.add('active');
    }

    function applySort(value) {
        showLoading();
        const url = new URL(window.location.href);
        url.searchParams.set('sort', value);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    // Add loading spinner to filter form
    document.getElementById('filterForm')?.addEventListener('submit', function() {
        showLoading();
    });

    // Hide spinner when page loads
    window.addEventListener('load', function() {
        setTimeout(() => {
            document.getElementById('loadingSpinner')?.classList.remove('active');
        }, 300);
    });
</script>

<?php require_once 'includes/footer.php'; ?>