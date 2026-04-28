<?php
require_once '../includes/functions.php';

$pageTitle = 'Car Rentals in Rwanda - Best Prices Guaranteed - GoRwanda+';
$currentPage = 'cars';

// Get search parameters
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$pickupDate = isset($_GET['pickup_date']) ? $_GET['pickup_date'] : '';
$returnDate = isset($_GET['return_date']) ? $_GET['return_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Set default dates if not provided
if (!$pickupDate) {
    $pickupDate = date('Y-m-d', strtotime('+1 day'));
}
if (!$returnDate) {
    $returnDate = date('Y-m-d', strtotime('+4 days'));
}

// Filters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$carTypes = isset($_GET['car_type']) ? (array)$_GET['car_type'] : [];
$transmission = isset($_GET['transmission']) ? (array)$_GET['transmission'] : [];
$fuelType = isset($_GET['fuel_type']) ? (array)$_GET['fuel_type'] : [];
$seats = isset($_GET['seats']) ? intval($_GET['seats']) : 0;

$db = getDB();

// Build query
$where = ["cf.is_active = 1", "cr.is_active = 1", "cr.is_verified = 1"];
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

// Check availability for selected dates
if ($pickupDate && $returnDate) {
    $where[] = "cf.car_id NOT IN (
        SELECT ca.car_id FROM car_availability ca
        WHERE ca.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
        AND (ca.quantity_available < 1 OR ca.is_blocked = 1)
        UNION
        SELECT b.car_id FROM bookings b
        WHERE b.car_id = cf.car_id
        AND b.status IN ('confirmed', 'pending')
        AND b.pickup_date <= ? AND b.return_date >= ?
    )";
    $params = array_merge($params, [$pickupDate, $returnDate, $returnDate, $pickupDate]);
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

// Sorting
switch ($sort) {
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
        GROUP BY cf.car_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cars = $stmt->fetchAll();

// Get filter options
$carTypesList = $db->query("
    SELECT car_type, COUNT(*) as count 
    FROM car_fleet 
    WHERE is_active = 1 
    GROUP BY car_type 
    ORDER BY count DESC
")->fetchAll();

$transmissionTypes = $db->query("
    SELECT DISTINCT transmission, COUNT(*) as count
    FROM car_fleet
    WHERE is_active = 1
    GROUP BY transmission
")->fetchAll();

$fuelTypes = $db->query("
    SELECT DISTINCT fuel_type, COUNT(*) as count
    FROM car_fleet
    WHERE is_active = 1
    GROUP BY fuel_type
")->fetchAll();

$popularLocations = $db->query("
    SELECT l.location_id, l.name, COUNT(cr.rental_id) as rental_count
    FROM locations l
    JOIN car_rentals cr ON l.location_id = cr.location_id
    WHERE l.is_active = 1 AND cr.is_active = 1 AND cr.is_verified = 1
    GROUP BY l.location_id
    ORDER BY rental_count DESC
    LIMIT 6
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
    /* Cars Listing Page Styles - Booking.com Inspired */
    .cars-hero {
        background: linear-gradient(135deg, #003580 0%, #001b4f 100%);
        padding: 32px 0;
        margin-bottom: 24px;
    }

    .cars-hero h1 {
        color: white;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .cars-hero p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 14px;
    }

    /* Quick Date Search - Enhanced Booking.com Style */
    .quick-search {
        background: white;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 32px;
        border: 1px solid #e7e7e7;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .quick-search-title {
        font-size: 18px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .quick-search-title i {
        color: #0071c2;
        font-size: 20px;
    }

    .quick-search-form {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }

    .quick-search-field {
        flex: 1;
        min-width: 180px;
    }

    .quick-search-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #6b6b6b;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .quick-search-input {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e7e7e7;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        background: white;
    }

    .quick-search-input:focus {
        outline: none;
        border-color: #0071c2;
        box-shadow: 0 0 0 3px rgba(0, 113, 194, 0.1);
    }

    .quick-search-input::placeholder {
        color: #a5a5a5;
    }

    /* Date input specific styling */
    input[type="date"].quick-search-input {
        position: relative;
        cursor: pointer;
    }

    input[type="date"].quick-search-input::-webkit-calendar-picker-indicator {
        cursor: pointer;
        opacity: 0.6;
        padding: 4px;
    }

    .quick-search-btn {
        padding: 0 32px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 24px;
        height: 48px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .quick-search-btn:hover {
        background: #003580;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 113, 194, 0.3);
    }

    .quick-search-btn i {
        font-size: 16px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .quick-search {
            padding: 20px;
        }

        .quick-search-form {
            flex-direction: column;
            gap: 12px;
        }

        .quick-search-field {
            width: 100%;
        }

        .quick-search-btn {
            margin-top: 8px;
            justify-content: center;
        }
    }

    /* Filter Sidebar */
    .filter-sidebar {
        background: white;
        border-radius: 8px;
        padding: 20px;
        border: 1px solid #e7e7e7;
        position: sticky;
        top: 20px;
    }

    .filter-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e7e7e7;
    }

    .filter-section {
        margin-bottom: 24px;
        padding-bottom: 24px;
        border-bottom: 1px solid #e7e7e7;
    }

    .filter-subtitle {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 12px;
    }

    .price-range {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .price-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #e7e7e7;
        border-radius: 6px;
        font-size: 13px;
    }

    .filter-option {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
        font-size: 13px;
        cursor: pointer;
    }

    .filter-option input {
        margin-right: 10px;
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .filter-option .count {
        margin-left: auto;
        color: #6b6b6b;
        font-size: 11px;
        background: #f5f5f5;
        padding: 2px 6px;
        border-radius: 20px;
    }

    /* Results Header */
    .results-header {
        background: white;
        padding: 16px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #e7e7e7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .results-count {
        font-size: 14px;
        color: #6b6b6b;
    }

    .results-count strong {
        color: #1a1a1a;
        font-size: 16px;
        font-weight: 700;
    }

    .sort-select {
        padding: 8px 30px 8px 12px;
        border: 1px solid #e7e7e7;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    /* Cars Grid */
    .cars-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 40px;
    }

    .car-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.2s;
        text-decoration: none;
        color: #1a1a1a;
        display: block;
    }

    .car-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        border-color: #0071c2;
    }

    .card-image {
        position: relative;
        height: 160px;
        overflow: hidden;
        background: #f5f5f5;
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
        background: #0071c2;
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

    .rating-badge {
        position: absolute;
        bottom: 12px;
        left: 12px;
        background: #003580;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 13px;
        z-index: 2;
    }

    .card-content {
        padding: 16px;
    }

    .car-company {
        font-size: 12px;
        color: #6b6b6b;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .car-name {
        font-size: 16px;
        font-weight: 700;
        color: #0071c2;
        margin-bottom: 8px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .car-specs {
        display: flex;
        gap: 12px;
        font-size: 12px;
        color: #6b6b6b;
        margin-bottom: 8px;
    }

    .car-specs i {
        color: #0071c2;
    }

    .car-location {
        font-size: 12px;
        color: #6b6b6b;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .car-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        border-top: 1px solid #e7e7e7;
        padding-top: 12px;
        margin-top: 8px;
    }

    .car-rating {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .rating-score {
        background: #003580;
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
    }

    .car-price {
        text-align: right;
    }

    .price-from {
        font-size: 11px;
        color: #6b6b6b;
    }

    .price-value {
        font-size: 20px;
        font-weight: 700;
        color: #1a1a1a;
        line-height: 1.2;
    }

    .price-unit {
        font-size: 11px;
        color: #6b6b6b;
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
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        text-decoration: none;
        color: #1a1a1a;
        transition: all 0.2s;
    }

    .location-card:hover {
        border-color: #0071c2;
        transform: translateY(-2px);
    }

    .location-icon {
        font-size: 24px;
        color: #0071c2;
        margin-bottom: 8px;
    }

    .location-name {
        font-weight: 600;
        font-size: 13px;
    }

    .location-count {
        font-size: 11px;
        color: #6b6b6b;
    }

    /* Buttons */
    .apply-filters {
        width: 100%;
        padding: 12px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 12px;
    }

    .clear-filters {
        display: block;
        text-align: center;
        margin-top: 12px;
        color: #0071c2;
        font-size: 13px;
        text-decoration: none;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 8px;
        border: 1px solid #e7e7e7;
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
        border: 1px solid #e7e7e7;
        border-radius: 6px;
        color: #1a1a1a;
        text-decoration: none;
        font-size: 14px;
    }

    .page-link:hover,
    .page-link.active {
        background: #0071c2;
        color: white;
        border-color: #0071c2;
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
    }
</style>

<!-- Hero Section -->
<section class="cars-hero">
    <div class="container">
        <h1>Car rentals in Rwanda</h1>
        <p><?php echo number_format($totalCount); ?> vehicles available for your selected dates</p>
    </div>
</section>

<div class="container">


    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
        <div class="locations-section">
            <div class="locations-grid">
                <?php foreach ($popularLocations as $loc): ?>
                    <a href="?location=<?php echo urlencode($loc['name']); ?>&pickup_date=<?php echo $pickupDate; ?>&return_date=<?php echo $returnDate; ?>" class="location-card">
                        <div class="location-icon"><i class="bi bi-geo-alt-fill"></i></div>
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
            <div class="filter-sidebar">
                <h3 class="filter-title">Filter by:</h3>

                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="location" value="<?php echo sanitize($location); ?>">
                    <input type="hidden" name="pickup_date" value="<?php echo $pickupDate; ?>">
                    <input type="hidden" name="return_date" value="<?php echo $returnDate; ?>">

                    <!-- Price Range -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle">Price per day</h4>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input"
                                placeholder="Min RWF" value="<?php echo $minPrice ?: ''; ?>">
                            <span>—</span>
                            <input type="number" name="max_price" class="price-input"
                                placeholder="Max RWF" value="<?php echo $maxPrice ?: ''; ?>">
                        </div>
                    </div>

                    <!-- Car Type -->
                    <?php if (!empty($carTypesList)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle">Car type</h4>
                            <?php foreach ($carTypesList as $ct): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="car_type[]" value="<?php echo $ct['car_type']; ?>"
                                        <?php echo in_array($ct['car_type'], $carTypes) ? 'checked' : ''; ?>>
                                    <span><?php echo ucfirst($ct['car_type']); ?></span>
                                    <span class="count"><?php echo $ct['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Transmission -->
                    <?php if (!empty($transmissionTypes)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle">Transmission</h4>
                            <?php foreach ($transmissionTypes as $t): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="transmission[]" value="<?php echo $t['transmission']; ?>"
                                        <?php echo in_array($t['transmission'], $transmission) ? 'checked' : ''; ?>>
                                    <span><?php echo ucfirst($t['transmission']); ?></span>
                                    <span class="count"><?php echo $t['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Fuel Type -->
                    <?php if (!empty($fuelTypes)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle">Fuel type</h4>
                            <?php foreach ($fuelTypes as $f): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="fuel_type[]" value="<?php echo $f['fuel_type']; ?>"
                                        <?php echo in_array($f['fuel_type'], $fuelType) ? 'checked' : ''; ?>>
                                    <span><?php echo ucfirst($f['fuel_type']); ?></span>
                                    <span class="count"><?php echo $f['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Seats -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle">Seats</h4>
                        <?php $seatOptions = [4, 5, 7, 8]; ?>
                        <?php foreach ($seatOptions as $s): ?>
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
                        <h4 class="filter-subtitle">Company rating</h4>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <label class="filter-option">
                                <input type="radio" name="rating" value="<?php echo $i; ?>"
                                    <?php echo $rating == $i ? 'checked' : ''; ?>>
                                <span class="star-rating">
                                    <?php for ($j = 1; $j <= 5; $j++): ?>
                                        <i class="bi bi-star-fill<?php echo $j <= $i ? '' : '-empty'; ?>" style="color: #febb02;"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="count"><?php echo $i; ?>+ stars</span>
                            </label>
                        <?php endfor; ?>
                        <label class="filter-option">
                            <input type="radio" name="rating" value="0" <?php echo $rating == 0 ? 'checked' : ''; ?>>
                            <span>Any</span>
                        </label>
                    </div>

                    <button type="submit" class="apply-filters">Apply filters</button>

                    <?php if ($minPrice || $maxPrice || $rating || !empty($carTypes) || !empty($transmission) || !empty($fuelType) || $seats > 0): ?>
                        <a href="?<?php echo http_build_query(['location' => $location, 'pickup_date' => $pickupDate, 'return_date' => $returnDate]); ?>" class="clear-filters">Clear filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            <div class="results-header">
                <div class="results-count">
                    <strong><?php echo number_format($totalCount); ?></strong> vehicles found
                    <?php if ($location): ?> in <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>

                <div>
                    <label style="margin-right: 8px;">Sort by:</label>
                    <select class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Company name</option>
                    </select>
                </div>
            </div>

            <?php if (empty($cars)): ?>
                <div class="empty-state">
                    <i class="bi bi-car-front" style="font-size: 48px; color: #6b6b6b;"></i>
                    <h3 style="margin-top: 16px;">No vehicles found</h3>
                    <p>Try adjusting your filters or search criteria</p>
                    <a href="?" class="apply-filters" style="width: auto; margin-top: 16px; display: inline-block; padding: 10px 24px;">Clear all filters</a>
                </div>
            <?php else: ?>
                <div class="cars-grid">
                    <?php foreach ($cars as $car):
                        $avgRating = $car['avg_rating'] ?: 0;
                        $priceWithTax = displayCustomerPrice($car['daily_rate']);
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating) : ['New', 'bg-secondary'];

                        $images = json_decode($car['images'] ?? '[]', true);
                        $image = $images[0] ?? '';
                    ?>
                        <a href="detail.php?id=<?php echo $car['car_id']; ?>&pickup_date=<?php echo $pickupDate; ?>&return_date=<?php echo $returnDate; ?>" class="car-card">
                            <div class="card-image">
                                <img src="<?php echo getImageUrl($image, 'car'); ?>"
                                    alt="<?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>"
                                    loading="lazy"
                                    onerror="this.src='https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&q=60'">
                                <?php if ($car['is_verified']): ?>
                                    <span class="card-badge verified">Verified</span>
                                <?php endif; ?>
                                <?php if ($avgRating > 0): ?>
                                    <span class="rating-badge"><?php echo number_format($avgRating, 1); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="card-content">
                                <div class="car-company"><?php echo sanitize($car['company_name']); ?></div>
                                <div class="car-name"><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></div>

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
                                                <div class="rating-count">No reviews</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="car-price">
                                        <div class="price-from">from</div>
                                        <div class="price-value"><?php echo $priceWithTax; ?></div>
                                        <div class="price-unit">per day (tax incl.)</div>
                                    </div>
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

    // Date validation
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        const pickupInput = document.querySelector('input[name="pickup_date"]');
        const returnInput = document.querySelector('input[name="return_date"]');

        if (pickupInput) pickupInput.min = today;
        if (returnInput) returnInput.min = today;
    });
</script>

<?php require_once '../includes/footer.php'; ?>