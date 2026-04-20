<?php
require_once '../includes/functions.php';

$pageTitle = 'Car Rentals in Rwanda - Best Prices Guaranteed - GoRwanda+';
$currentPage = 'cars';

// Get search parameters safely
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$pickupDate = isset($_GET['pickup_date']) ? $_GET['pickup_date'] : '';
$returnDate = isset($_GET['return_date']) ? $_GET['return_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15; // Booking.com shows 15 per page
$offset = ($page - 1) * $perPage;

// Filters with safe checks
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$carTypes = isset($_GET['car_type']) ? (array)$_GET['car_type'] : [];
$transmission = isset($_GET['transmission']) ? (array)$_GET['transmission'] : [];
$fuelType = isset($_GET['fuel_type']) ? (array)$_GET['fuel_type'] : [];
$seats = isset($_GET['seats']) ? intval($_GET['seats']) : 0;

$db = getDB();

// Build query for car rentals and fleet
$where = ["cr.is_active = 1", "cr.is_verified = 1", "cf.is_active = 1"];
$params = [];

if ($location) {
    $where[] = "(cr.company_name LIKE ? OR l.name LIKE ? OR JSON_CONTAINS(cr.pickup_locations, JSON_QUOTE(?)))";
    $like = "%{$location}%";
    $params = array_merge($params, [$like, $like, $location]);
}

if (!empty($carTypes)) {
    $placeholders = implode(',', array_fill(0, count($carTypes), '?'));
    $where[] = "cf.car_type IN ({$placeholders})";
    $params = array_merge($params, $carTypes);
}

if (!empty($transmission)) {
    $placeholders = implode(',', array_fill(0, count($transmission), '?'));
    $where[] = "cf.transmission IN ({$placeholders})";
    $params = array_merge($params, $transmission);
}

if (!empty($fuelType)) {
    $placeholders = implode(',', array_fill(0, count($fuelType), '?'));
    $where[] = "cf.fuel_type IN ({$placeholders})";
    $params = array_merge($params, $fuelType);
}

if ($seats > 0) {
    $where[] = "cf.seats >= ?";
    $params[] = $seats;
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

// Check availability if dates provided
if ($pickupDate && $returnDate) {
    $where[] = "cf.car_id NOT IN (
        SELECT ca.car_id FROM car_availability ca
        WHERE ca.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
        AND (ca.quantity_available < 1 OR ca.is_blocked = 1)
    )";
    $params = array_merge($params, [$pickupDate, $returnDate]);
}

// Count total vehicles
$countSql = "SELECT COUNT(DISTINCT cf.car_id) 
             FROM car_fleet cf
             JOIN car_rentals cr ON cf.rental_id = cr.rental_id
             LEFT JOIN locations l ON cr.location_id = l.location_id
             WHERE " . implode(" AND ", $where);
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalCount = $stmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Sorting logic
switch($sort) {
    case 'price_low':
        $orderBy = 'cf.daily_rate ASC';
        break;
    case 'price_high':
        $orderBy = 'cf.daily_rate DESC';
        break;
    case 'rating':
        $orderBy = 'cr.avg_rating DESC, cr.review_count DESC';
        break;
    case 'name':
        $orderBy = 'cr.company_name ASC, cf.model ASC';
        break;
    case 'recommended':
    default:
        $orderBy = 'cr.avg_rating DESC, cr.review_count DESC, cf.daily_rate ASC';
        break;
}

// Main query
$sql = "SELECT cf.*, cr.company_name, cr.avg_rating, cr.review_count, cr.pickup_locations,
        l.name as location_name,
        (SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id) as total_reviews
        FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN locations l ON cr.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cars = $stmt->fetchAll();

// Get car types for filter
$carTypesList = $db->query("
    SELECT car_type, COUNT(*) as count 
    FROM car_fleet 
    WHERE is_active = 1 
    GROUP BY car_type 
    ORDER BY count DESC
")->fetchAll();

// Get popular locations for cars
$popularLocations = $db->query("
    SELECT l.location_id, l.name, l.type, COUNT(cr.rental_id) as rental_count
    FROM locations l
    JOIN car_rentals cr ON l.location_id = cr.location_id
    WHERE l.is_active = 1 AND cr.is_active = 1 AND cr.is_verified = 1
    GROUP BY l.location_id
    HAVING rental_count > 0
    ORDER BY rental_count DESC
    LIMIT 6
")->fetchAll();

// Get unique transmission types
$transmissionTypes = $db->query("
    SELECT DISTINCT transmission, COUNT(*) as count
    FROM car_fleet
    WHERE is_active = 1
    GROUP BY transmission
    ORDER BY count DESC
")->fetchAll();

// Get unique fuel types
$fuelTypes = $db->query("
    SELECT DISTINCT fuel_type, COUNT(*) as count
    FROM car_fleet
    WHERE is_active = 1
    GROUP BY fuel_type
    ORDER BY count DESC
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
/* ===== CARS LANDING PAGE - EXACT BOOKING.COM STYLE ===== */
:root {
    --bkg-blue-dark: #003580;
    --bkg-blue-primary: #0071c2;
    --bkg-blue-light: #ebf3ff;
    --bkg-yellow: #feba02;
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
.cars-hero {
    background: var(--bkg-blue-dark);
    padding: 24px 0 16px;
    margin-bottom: 24px;
}

.cars-hero h1 {
    color: white;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 4px;
}

.cars-hero p {
    color: rgba(255,255,255,0.9);
    font-size: 14px;
    margin-bottom: 0;
}

/* Quick date search */
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
.cars-filter-sidebar {
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

/* Cars Grid */
.cars-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

.car-card {
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

.car-card:hover {
    box-shadow: var(--shadow-lg);
    border-color: var(--bkg-blue-primary);
}

.card-image {
    position: relative;
    height: 140px;
    overflow: hidden;
    background: var(--bkg-gray-100);
}

.card-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.car-card:hover .card-image img {
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

.card-badge.special {
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

.card-content {
    padding: 12px;
}

.car-company {
    font-size: 11px;
    color: var(--bkg-gray-500);
    text-transform: uppercase;
    margin-bottom: 2px;
}

.car-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--bkg-blue-primary);
    margin-bottom: 4px;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.car-name:hover {
    text-decoration: underline;
}

.car-specs {
    display: flex;
    gap: 12px;
    font-size: 11px;
    color: var(--bkg-gray-500);
    margin-bottom: 8px;
}

.car-specs span {
    display: flex;
    align-items: center;
    gap: 2px;
}

.car-specs i {
    color: var(--bkg-blue-primary);
    font-size: 11px;
}

.car-location {
    font-size: 12px;
    color: var(--bkg-gray-500);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.car-location i {
    color: var(--bkg-blue-primary);
    font-size: 12px;
}

.car-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-top: 1px solid var(--bkg-gray-200);
    padding-top: 10px;
    margin-top: 6px;
}

.car-rating {
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

.car-price {
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
    .cars-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .locations-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .cars-grid {
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
<section class="cars-hero">
    <div class="container">
        <h1>Car rentals in Rwanda</h1>
        <p><?php echo number_format($totalCount); ?> vehicles available</p>
    </div>
</section>

<div class="container">
    <!-- Quick Date Search -->
    <div class="quick-search">
        <h2 class="quick-search-title">Check availability</h2>
        <form action="" method="GET" class="quick-search-form">
            <div class="quick-search-field">
                <input type="text" name="location" class="quick-search-input" 
                       placeholder="Pick-up location" value="<?php echo sanitize($location); ?>">
            </div>
            <div class="quick-search-field">
                <input type="date" name="pickup_date" class="quick-search-input" 
                       value="<?php echo $pickupDate; ?>" min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="quick-search-field">
                <input type="date" name="return_date" class="quick-search-input" 
                       value="<?php echo $returnDate; ?>" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
            </div>
            <button type="submit" class="quick-search-btn">Check</button>
        </form>
    </div>

    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
    <div class="locations-section">
        <h2 class="locations-title">Popular pick-up locations</h2>
        <div class="locations-grid">
            <?php foreach ($popularLocations as $loc): ?>
            <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                <div class="location-icon">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                <div class="location-count"><?php echo $loc['rental_count']; ?> companies</div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="cars-filter-sidebar">
                <h5 class="filter-title">Filter by:</h5>
                
                <form method="GET" action="" id="filterForm">
                    <!-- Preserve search parameters -->
                    <?php if ($location): ?>
                    <input type="hidden" name="location" value="<?php echo sanitize($location); ?>">
                    <?php endif; ?>
                    <?php if ($pickupDate): ?>
                    <input type="hidden" name="pickup_date" value="<?php echo $pickupDate; ?>">
                    <?php endif; ?>
                    <?php if ($returnDate): ?>
                    <input type="hidden" name="return_date" value="<?php echo $returnDate; ?>">
                    <?php endif; ?>
                    
                    <!-- Price Range -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Price per day</h6>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input" 
                                   placeholder="Min" value="<?php echo $minPrice ?: ''; ?>" min="0">
                            <span class="price-sep">—</span>
                            <input type="number" name="max_price" class="price-input" 
                                   placeholder="Max" value="<?php echo $maxPrice ?: ''; ?>" min="0">
                        </div>
                    </div>
                    
                    <!-- Car Type -->
                    <?php if (!empty($carTypesList)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Car type</h6>
                        <?php foreach ($carTypesList as $ct): 
                            $val = $ct['car_type'];
                            $label = ucfirst($val);
                            $count = $ct['count'];
                        ?>
                        <label class="filter-option">
                            <input type="checkbox" name="car_type[]" value="<?php echo $val; ?>" 
                                <?php echo in_array($val, $carTypes) ? 'checked' : ''; ?>>
                            <span><?php echo $label; ?></span>
                            <span class="count"><?php echo $count; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Transmission -->
                    <?php if (!empty($transmissionTypes)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Transmission</h6>
                        <?php foreach ($transmissionTypes as $t): 
                            $val = $t['transmission'];
                            $label = ucfirst($val);
                            $count = $t['count'];
                        ?>
                        <label class="filter-option">
                            <input type="checkbox" name="transmission[]" value="<?php echo $val; ?>" 
                                <?php echo in_array($val, $transmission) ? 'checked' : ''; ?>>
                            <span><?php echo $label; ?></span>
                            <span class="count"><?php echo $count; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Fuel Type -->
                    <?php if (!empty($fuelTypes)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Fuel type</h6>
                        <?php foreach ($fuelTypes as $f): 
                            $val = $f['fuel_type'];
                            $label = ucfirst($val);
                            $count = $f['count'];
                        ?>
                        <label class="filter-option">
                            <input type="checkbox" name="fuel_type[]" value="<?php echo $val; ?>" 
                                <?php echo in_array($val, $fuelType) ? 'checked' : ''; ?>>
                            <span><?php echo $label; ?></span>
                            <span class="count"><?php echo $count; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Seats -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Number of seats</h6>
                        <?php 
                        $seatOptions = [2,4,5,7,8];
                        foreach ($seatOptions as $s): 
                        ?>
                        <label class="filter-option">
                            <input type="radio" name="seats" value="<?php echo $s; ?>" 
                                <?php echo $seats == $s ? 'checked' : ''; ?>>
                            <span><?php echo $s; ?>+ seats</span>
                        </label>
                        <?php endforeach; ?>
                        <label class="filter-option">
                            <input type="radio" name="seats" value="0" <?php echo $seats == 0 ? 'checked' : ''; ?>>
                            <span>Any</span>
                        </label>
                    </div>
                    
                    <!-- Rating -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Rental company rating</h6>
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
                    
                    <?php if ($minPrice || $maxPrice || $rating || !empty($carTypes) || !empty($transmission) || !empty($fuelType) || $seats > 0): ?>
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
                    <strong><?php echo number_format($totalCount); ?></strong> vehicles
                    <?php if ($location): ?> in <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>
                
                <div class="sort-section">
                    <label for="sortSelect">Sort by:</label>
                    <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Company name</option>
                    </select>
                </div>
            </div>
            
            <!-- Cars Grid -->
            <?php if (empty($cars)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-car-front"></i>
                    </div>
                    <h3 class="empty-title">No vehicles found</h3>
                    <p class="empty-text">Try adjusting your filters or search in a different location</p>
                    <a href="?" class="apply-filters" style="width: auto; padding: 10px 24px;">Clear all filters</a>
                </div>
            <?php else: ?>
                <div class="cars-grid">
                    <?php foreach ($cars as $car): 
                        // Get company rating
                        $avgRating = $car['avg_rating'] ?: 0;
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating) : ['New', 'bg-secondary'];
                        
                        // Get image - from images JSON
                        $image = null;
                        if (!empty($car['images'])) {
                            $images = json_decode($car['images'], true);
                            $image = is_array($images) ? $images[0] : null;
                        }
                        
                        // Format car name
                        $carName = $car['brand'] . ' ' . $car['model'];
                        if (!empty($car['year'])) {
                            $carName .= ' ' . $car['year'];
                        }
                    ?>
                    <a href="detail.php?id=<?php echo $car['car_id']; ?>" class="car-card">
                        <div class="card-image">
                            <img src="<?php echo getImageUrl($image, 'car'); ?>" 
                                 alt="<?php echo sanitize($carName); ?>"
                                 loading="lazy"
                                 onerror="this.src='https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&q=60'">
                            
                            <?php if ($car['status'] === 'available'): ?>
                            <span class="card-badge verified">Available</span>
                            <?php endif; ?>
                            
                            <?php if ($avgRating > 0): ?>
                            <span class="rating-badge"><?php echo number_format($avgRating, 1); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-content">
                            <div class="car-company"><?php echo sanitize($car['company_name']); ?></div>
                            <h3 class="car-name"><?php echo sanitize($carName); ?></h3>
                            
                            <div class="car-specs">
                                <span><i class="bi bi-people"></i> <?php echo $car['seats']; ?> seats</span>
                                <span><i class="bi bi-gear"></i> <?php echo ucfirst($car['transmission']); ?></span>
                                <span><i class="bi bi-fuel-pump"></i> <?php echo ucfirst($car['fuel_type']); ?></span>
                            </div>
                            
                            <div class="car-location">
                                <i class="bi bi-geo-alt"></i>
                                <?php echo sanitize($car['location_name'] ?: 'Multiple locations'); ?>
                            </div>
                            
                            <div class="car-footer">
                                <div class="car-rating">
                                    <?php if ($car['review_count'] > 0): ?>
                                        <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                                        <div class="rating-text">
                                            <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                                            <div class="rating-count"><?php echo number_format($car['review_count']); ?> reviews</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="rating-score" style="background: #6c757d;">New</span>
                                        <div class="rating-text">
                                            <div class="rating-label">New</div>
                                            <div class="rating-count">0 reviews</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="car-price">
                                    <div class="price-from">from</div>
                                    <div class="price-value"><?php echo formatPrice($car['daily_rate']); ?></div>
                                    <div class="price-unit">per day</div>
                                </div>
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

// Set min dates for date inputs
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date().toISOString().split('T')[0];
    const pickupInput = document.querySelector('input[name="pickup_date"]');
    const returnInput = document.querySelector('input[name="return_date"]');
    
    if (pickupInput) {
        pickupInput.min = today;
        pickupInput.addEventListener('change', function() {
            if (returnInput) {
                returnInput.min = this.value;
                if (returnInput.value && returnInput.value < this.value) {
                    returnInput.value = this.value;
                }
            }
        });
    }
    
    if (returnInput) {
        returnInput.min = today;
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