<?php
require_once '../includes/functions.php';

$pageTitle = 'Tours & Experiences in Rwanda - Unforgettable Adventures - GoRwanda+';
$currentPage = 'attractions';

// Get search parameters safely
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$date = isset($_GET['date']) ? $_GET['date'] : '';
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15; // Booking.com shows 15 per page
$offset = ($page - 1) * $perPage;

// Filters with safe checks
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$categories = isset($_GET['category']) ? (array)$_GET['category'] : [];
$duration = isset($_GET['duration']) ? (array)$_GET['duration'] : [];
$difficulty = isset($_GET['difficulty']) ? (array)$_GET['difficulty'] : [];

$db = getDB();

// Build query for attractions
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

// Duration filters (in minutes)
if (!empty($duration)) {
    $durationConditions = [];
    foreach ($duration as $d) {
        switch($d) {
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
    $where[] = "a.avg_rating >= ?";
    $params[] = $rating;
}

// Price filters using attraction_tiers
if ($minPrice > 0 || $maxPrice > 0) {
    $priceSubquery = "SELECT attraction_id FROM attraction_tiers WHERE is_active = 1";
    $priceParams = [];
    
    if ($minPrice > 0) {
        $priceSubquery .= " AND base_price >= ?";
        $priceParams[] = $minPrice;
    }
    if ($maxPrice > 0) {
        $priceSubquery .= " AND base_price <= ?";
        $priceParams[] = $maxPrice;
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

// Sorting logic
switch($sort) {
    case 'price_low':
        $orderBy = 'min_price ASC';
        break;
    case 'price_high':
        $orderBy = 'min_price DESC';
        break;
    case 'rating':
        $orderBy = 'a.avg_rating DESC, a.review_count DESC';
        break;
    case 'duration':
        $orderBy = 'a.duration_minutes ASC';
        break;
    case 'recommended':
    default:
        $orderBy = 'a.avg_rating DESC, a.review_count DESC, a.attraction_id DESC';
        break;
}

// Main query
$sql = "SELECT a.*, c.name as category_name, c.icon as category_icon, l.name as location_name,
        (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price,
        (SELECT MAX(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as max_price,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as tier_count,
        (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count,
        (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating_calc
        FROM attractions a
        LEFT JOIN categories c ON a.category_id = c.category_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        GROUP BY a.attraction_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$attractions = $stmt->fetchAll();

// Get categories for filter
$categoriesList = $db->query("
    SELECT c.*, COUNT(a.attraction_id) as count
    FROM categories c
    LEFT JOIN attractions a ON c.category_id = a.category_id AND a.is_active = 1 AND a.is_verified = 1
    WHERE c.is_active = 1
    GROUP BY c.category_id
    HAVING count > 0
    ORDER BY count DESC
")->fetchAll();

// Get popular locations for attractions
$popularLocations = $db->query("
    SELECT l.location_id, l.name, l.type, COUNT(a.attraction_id) as attraction_count
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

// Get min and max duration for filter hints
$durationRange = $db->query("
    SELECT MIN(duration_minutes) as min_duration, MAX(duration_minutes) as max_duration
    FROM attractions
    WHERE is_active = 1 AND is_verified = 1 AND duration_minutes IS NOT NULL
")->fetch();

require_once '../includes/header.php';


// DEBUG: Show what images are in the database
echo "<!-- DEBUG: Found " . count($attractions) . " attractions -->";
foreach ($attractions as $index => $att) {
    $img = $att['main_image'] ?? 'none';
    echo "<!-- Attraction {$index}: {$att['attraction_name']} - Image: {$img} -->";
}

?>

<style>
/* ===== ATTRACTIONS LANDING PAGE - EXACT BOOKING.COM STYLE ===== */
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
    --shadow-sm: 0 1px 4px rgba(0,0,0,0.05);
    --shadow-md: 0 2px 8px rgba(0,0,0,0.1);
    --shadow-lg: 0 4px 16px rgba(0,0,0,0.15);
    --transition: all 0.2s ease;
}

/* Hero Banner */
.attractions-hero {
    background: var(--bkg-blue-dark);
    padding: 24px 0 16px;
    margin-bottom: 24px;
}

.attractions-hero h1 {
    color: white;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 4px;
}

.attractions-hero p {
    color: rgba(255,255,255,0.9);
    font-size: 14px;
    margin-bottom: 0;
}

/* Quick Search */
.quick-search {
    background: white;
    border-radius: 4px;
    padding: 16px;
    margin-bottom: 24px;
    border: 1px solid var(--bkg-gray-200);
}

.quick-search-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 12px;
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
    padding: 10px 12px;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    font-size: 13px;
}

.quick-search-input:focus {
    outline: none;
    border-color: var(--bkg-blue-primary);
    box-shadow: 0 0 0 2px rgba(0,113,194,0.1);
}

.quick-search-select {
    width: 100%;
    padding: 10px 28px 10px 12px;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    font-size: 13px;
    background: white;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}

.quick-search-btn {
    background: var(--bkg-blue-primary);
    color: white;
    border: none;
    padding: 10px 24px;
    font-weight: 600;
    font-size: 13px;
    border-radius: 4px;
    cursor: pointer;
    white-space: nowrap;
}

.quick-search-btn:hover {
    background: #005fa3;
}

/* Filter Sidebar */
.attractions-filter-sidebar {
    background: white;
    border-radius: 4px;
    padding: 20px;
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
    padding: 8px 10px;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    font-size: 13px;
}

.price-input:focus {
    outline: none;
    border-color: var(--bkg-blue-primary);
    box-shadow: 0 0 0 2px rgba(0,113,194,0.1);
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

/* Difficulty badges */
.difficulty-badge {
    display: inline-block;
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

/* Results Header */
.results-header {
    background: white;
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 16px;
    border: 1px solid var(--bkg-gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.results-count {
    font-size: 13px;
    color: var(--bkg-gray-500);
}

.results-count strong {
    color: var(--bkg-gray-700);
    font-size: 15px;
    font-weight: 700;
}

.sort-section {
    display: flex;
    align-items: center;
    gap: 8px;
}

.sort-section label {
    font-size: 12px;
    color: var(--bkg-gray-500);
}

.sort-select {
    padding: 6px 24px 6px 10px;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    font-size: 12px;
    background: white;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
}

.sort-select:hover {
    border-color: var(--bkg-blue-primary);
}

/* Attractions Grid */
.attractions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

.attraction-card {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    overflow: hidden;
    transition: var(--transition);
    cursor: pointer;
    text-decoration: none;
    color: var(--bkg-gray-700);
    display: block;
    position: relative;
}

.attraction-card:hover {
    box-shadow: var(--shadow-lg);
    border-color: var(--bkg-blue-primary);
}

.card-image {
    position: relative;
    height: 160px;
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
    padding: 4px 8px;
    border-radius: 4px 4px 4px 0;
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
    border-radius: 4px 4px 4px 0;
    font-weight: 700;
    font-size: 14px;
    z-index: 2;
}

.duration-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    z-index: 2;
    backdrop-filter: blur(4px);
}

.card-content {
    padding: 12px;
}

.attraction-category {
    font-size: 11px;
    color: var(--bkg-gray-500);
    text-transform: uppercase;
    margin-bottom: 2px;
}

.attraction-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--bkg-blue-primary);
    margin-bottom: 4px;
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
    font-size: 12px;
    color: var(--bkg-gray-500);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.attraction-location i {
    color: var(--bkg-blue-primary);
    font-size: 12px;
}

.attraction-meta {
    display: flex;
    gap: 12px;
    font-size: 11px;
    color: var(--bkg-gray-500);
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.attraction-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.attraction-meta i {
    color: var(--bkg-blue-primary);
    font-size: 11px;
}

.attraction-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-top: 1px solid var(--bkg-gray-200);
    padding-top: 10px;
    margin-top: 6px;
}

.attraction-rating {
    display: flex;
    align-items: center;
    gap: 6px;
}

.rating-score {
    background: var(--bkg-blue-dark);
    color: white;
    padding: 4px 6px;
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
    color: var(--bkg-gray-700);
    line-height: 1.2;
}

.price-unit {
    font-size: 11px;
    color: var(--bkg-gray-500);
}

/* Popular Locations */
.locations-section {
    margin: 24px 0 32px;
}

.locations-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 16px;
}

.locations-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
}

.location-card {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    padding: 16px 8px;
    text-align: center;
    transition: var(--transition);
    text-decoration: none;
    color: var(--bkg-gray-700);
    display: block;
}

.location-card:hover {
    border-color: var(--bkg-blue-primary);
    box-shadow: var(--shadow-sm);
}

.location-icon {
    width: 40px;
    height: 40px;
    background: var(--bkg-blue-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    color: var(--bkg-blue-primary);
    font-size: 18px;
}

.location-card:hover .location-icon {
    background: var(--bkg-blue-primary);
    color: white;
}

.location-name {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 2px;
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
    gap: 4px;
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    padding: 4px;
}

.page-link {
    min-width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 8px;
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
    padding: 10px;
    font-weight: 600;
    font-size: 13px;
    border-radius: 4px;
    width: 100%;
    cursor: pointer;
    transition: background-color 0.2s;
    margin-top: 12px;
}

.apply-filters:hover {
    background: #005fa3;
}

.clear-filters {
    background: transparent;
    color: var(--bkg-blue-primary);
    border: 1px solid var(--bkg-blue-primary);
    padding: 10px;
    font-weight: 600;
    font-size: 13px;
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
    border-radius: 4px;
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
    font-size: 13px;
    margin-bottom: 20px;
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
        <h1>Experiences in Rwanda</h1>
        <p><?php echo number_format($totalCount); ?> tours & activities available</p>
    </div>
</section>

<div class="container">
    <!-- Quick Search -->
    <div class="quick-search">
        <h2 class="quick-search-title">Find your experience</h2>
        <form action="" method="GET" class="quick-search-form">
            <div class="quick-search-field">
                <input type="text" name="location" class="quick-search-input" 
                       placeholder="Destination" value="<?php echo sanitize($location); ?>">
            </div>
            <div class="quick-search-field">
                <input type="date" name="date" class="quick-search-input" 
                       value="<?php echo $date; ?>" min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="quick-search-field">
                <select name="guests" class="quick-search-select">
                    <?php for($i=1; $i<=10; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $guests == $i ? 'selected' : ''; ?>>
                        <?php echo $i; ?> person<?php echo $i > 1 ? 's' : ''; ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="quick-search-btn">Search</button>
        </form>
    </div>

    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
    <div class="locations-section">
        <h2 class="locations-title">Popular destinations</h2>
        <div class="locations-grid">
            <?php foreach ($popularLocations as $loc): ?>
            <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                <div class="location-icon">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                <div class="location-count"><?php echo $loc['attraction_count']; ?> experiences</div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="attractions-filter-sidebar">
                <h5 class="filter-title">Filter by:</h5>
                
                <form method="GET" action="" id="filterForm">
                    <!-- Preserve search parameters -->
                    <?php if ($location): ?>
                    <input type="hidden" name="location" value="<?php echo sanitize($location); ?>">
                    <?php endif; ?>
                    <?php if ($date): ?>
                    <input type="hidden" name="date" value="<?php echo $date; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="guests" value="<?php echo $guests; ?>">
                    
                    <!-- Price Range -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Price per person</h6>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input" 
                                   placeholder="Min" value="<?php echo $minPrice ?: ''; ?>" min="0">
                            <span class="price-sep">—</span>
                            <input type="number" name="max_price" class="price-input" 
                                   placeholder="Max" value="<?php echo $maxPrice ?: ''; ?>" min="0">
                        </div>
                    </div>
                    
                    <!-- Categories -->
                    <?php if (!empty($categoriesList)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Category</h6>
                        <?php foreach ($categoriesList as $cat): ?>
                        <label class="filter-option">
                            <input type="checkbox" name="category[]" value="<?php echo $cat['category_id']; ?>" 
                                <?php echo in_array($cat['category_id'], $categories) ? 'checked' : ''; ?>>
                            <span><?php echo sanitize($cat['name']); ?></span>
                            <span class="count"><?php echo $cat['count']; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Duration -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Duration</h6>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="1" 
                                <?php echo in_array('1', $duration) ? 'checked' : ''; ?>>
                            <span>Up to 1 hour</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="2-3" 
                                <?php echo in_array('2-3', $duration) ? 'checked' : ''; ?>>
                            <span>2-3 hours</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="4-6" 
                                <?php echo in_array('4-6', $duration) ? 'checked' : ''; ?>>
                            <span>4-6 hours</span>
                        </label>
                        <label class="filter-option">
                            <input type="checkbox" name="duration[]" value="7+" 
                                <?php echo in_array('7+', $duration) ? 'checked' : ''; ?>>
                            <span>7+ hours</span>
                        </label>
                    </div>
                    
                    <!-- Difficulty -->
                    <?php if (!empty($difficultyLevels)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Difficulty</h6>
                        <?php foreach ($difficultyLevels as $diff): 
                            $val = $diff['difficulty_level'];
                            $label = ucfirst($val);
                            $count = $diff['count'];
                        ?>
                        <label class="filter-option">
                            <input type="checkbox" name="difficulty[]" value="<?php echo $val; ?>" 
                                <?php echo in_array($val, $difficulty) ? 'checked' : ''; ?>>
                            <span><?php echo $label; ?></span>
                            <span class="count"><?php echo $count; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Rating -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Rating</h6>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <label class="filter-option">
                            <input type="radio" name="rating" value="<?php echo $i * 2; ?>" 
                                <?php echo $rating == $i * 2 ? 'checked' : ''; ?>>
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
                    
                    <?php if ($minPrice || $maxPrice || $rating || !empty($categories) || !empty($duration) || !empty($difficulty)): ?>
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
                    <strong><?php echo number_format($totalCount); ?></strong> experiences
                    <?php if ($location): ?> in <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>
                
                <div class="sort-section">
                    <label for="sortSelect">Sort by:</label>
                    <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        <option value="duration" <?php echo $sort === 'duration' ? 'selected' : ''; ?>>Duration (shortest first)</option>
                    </select>
                </div>
            </div>
            
            <!-- Attractions Grid -->
            <?php if (empty($attractions)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <h3 class="empty-title">No experiences found</h3>
                    <p class="empty-text">Try adjusting your filters or search in a different location</p>
                    <a href="?" class="apply-filters" style="width: auto; padding: 10px 24px;">Clear all filters</a>
                </div>
            <?php else: ?>
                <div class="attractions-grid">
                  <?php foreach ($attractions as $attraction): 
    // Use avg_rating from attractions table or calculate from reviews
    $avgRating = $attraction['avg_rating'] ?: ($attraction['avg_rating_calc'] ?: 0);
    $reviewLabel = $avgRating ? getReviewLabel($avgRating) : ['New', 'bg-secondary'];
    
    // Get image name
    $imageName = $attraction['main_image'] ?? '';
    
    // If no main image, try to get first from gallery
    if (empty($imageName) && !empty($attraction['gallery_images'])) {
        $galleryImages = json_decode($attraction['gallery_images'], true);
        if (is_array($galleryImages) && !empty($galleryImages)) {
            $imageName = $galleryImages[0];
        }
    }
    
    // Format duration
    $durationHours = floor($attraction['duration_minutes'] / 60);
    $durationMinutes = $attraction['duration_minutes'] % 60;
    $durationText = '';
    if ($durationHours > 0) {
        $durationText .= $durationHours . 'h';
    }
    if ($durationMinutes > 0) {
        $durationText .= ($durationHours > 0 ? ' ' : '') . $durationMinutes . 'm';
    }
    
    // Difficulty class
    $difficultyClass = '';
    if ($attraction['difficulty_level'] == 'easy') {
        $difficultyClass = 'difficulty-easy';
    } elseif ($attraction['difficulty_level'] == 'moderate') {
        $difficultyClass = 'difficulty-moderate';
    } elseif ($attraction['difficulty_level'] == 'challenging') {
        $difficultyClass = 'difficulty-challenging';
    }
?>
<a href="detail.php?id=<?php echo $attraction['attraction_id']; ?>" class="attraction-card">
    <div class="card-image">
        <?php 
        // Use the existing getImageUrl function
        $imageUrl = getImageUrl($imageName, 'attraction', 'medium');
        ?>
        <img src="<?php echo $imageUrl; ?>" 
             alt="<?php echo sanitize($attraction['attraction_name']); ?>"
             loading="lazy">
        
        <?php if ($attraction['is_verified']): ?>
        <span class="card-badge verified">Verified</span>
        <?php endif; ?>
        
        <?php if ($attraction['instant_confirmation']): ?>
        <span class="card-badge">Instant</span>
        <?php endif; ?>
        
        <?php if ($avgRating > 0): ?>
        <span class="rating-badge"><?php echo number_format($avgRating, 1); ?></span>
        <?php endif; ?>
        
        <?php if ($attraction['duration_minutes']): ?>
        <span class="duration-badge">
            <i class="bi bi-clock"></i> <?php echo $durationText; ?>
        </span>
        <?php endif; ?>
    </div>
    
    <div class="card-content">
        <div class="attraction-category"><?php echo sanitize($attraction['category_name'] ?: 'Experience'); ?></div>
        <h3 class="attraction-name"><?php echo sanitize($attraction['attraction_name']); ?></h3>
        
        <div class="attraction-location">
            <i class="bi bi-geo-alt"></i>
            <?php echo sanitize($attraction['location_name'] ?: 'Rwanda'); ?>
        </div>
        
        <div class="attraction-meta">
            <?php if ($attraction['difficulty_level']): ?>
            <span>
                <span class="difficulty-badge <?php echo $difficultyClass; ?>">
                    <?php echo ucfirst($attraction['difficulty_level']); ?>
                </span>
            </span>
            <?php endif; ?>
            
            <?php if ($attraction['min_age']): ?>
            <span><i class="bi bi-person"></i> Min age: <?php echo $attraction['min_age']; ?>+</span>
            <?php endif; ?>
            
            <?php if ($attraction['max_group_size']): ?>
            <span><i class="bi bi-people"></i> Max <?php echo $attraction['max_group_size']; ?></span>
            <?php endif; ?>
        </div>
        
        <div class="attraction-footer">
            <div class="attraction-rating">
                <?php if ($attraction['review_count'] > 0): ?>
                    <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                    <div class="rating-text">
                        <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                        <div class="rating-count"><?php echo number_format($attraction['review_count']); ?> reviews</div>
                    </div>
                <?php else: ?>
                    <span class="rating-score" style="background: #6c757d;">New</span>
                    <div class="rating-text">
                        <div class="rating-label">New</div>
                        <div class="rating-count">0 reviews</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($attraction['min_price'] > 0): ?>
            <div class="attraction-price">
                <div class="price-from">from</div>
                <div class="price-value"><?php echo formatPrice($attraction['min_price']); ?></div>
                <div class="price-unit">per person</div>
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