<?php
require_once '../includes/functions.php';

// Get current language
$currentLang = getCurrentLanguage();
$currentCurrency = getCurrentCurrency();

$pageTitle = tr('stays_page_title', 'Stays in Rwanda - Hotels, Lodges & Apartments');
$currentPage = 'stays';

// Get search parameters safely
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$checkin = isset($_GET['checkin']) ? $_GET['checkin'] : '';
$checkout = isset($_GET['checkout']) ? $_GET['checkout'] : '';
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Filters
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$propertyTypes = isset($_GET['property_type']) ? (array)$_GET['property_type'] : [];

$db = getDB();

// Get active stays with filters
$where = ["s.is_active = 1", "s.is_verified = 1"];
$params = [];

if ($location) {
    $where[] = "(s.stay_name LIKE ? OR l.name LIKE ? OR s.city LIKE ? OR s.address LIKE ?)";
    $like = "%{$location}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if (!empty($propertyTypes)) {
    $placeholders = implode(',', array_fill(0, count($propertyTypes), '?'));
    $where[] = "s.stay_type IN ({$placeholders})";
    $params = array_merge($params, $propertyTypes);
}

if ($rating > 0) {
    $where[] = "COALESCE(s.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id)) >= ?";
    $params[] = $rating;
}

// Price filters
if ($minPrice > 0) {
    $where[] = "EXISTS (SELECT 1 FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1 AND base_price >= ?)";
    $params[] = $minPrice;
}
if ($maxPrice > 0) {
    $where[] = "EXISTS (SELECT 1 FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1 AND base_price <= ?)";
    $params[] = $maxPrice;
}

// Count total properties
$countSql = "SELECT COUNT(DISTINCT s.stay_id) FROM stays s 
             LEFT JOIN locations l ON s.location_id = l.location_id 
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
    case 'name':
        $orderBy = 's.stay_name ASC';
        break;
    default:
        $orderBy = 'avg_rating DESC, review_count DESC, s.stay_id DESC';
        break;
}

// Main query
$sql = "SELECT s.*, l.name as location_name,
        (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
        (SELECT MAX(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as max_price,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as room_count,
        (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
        COALESCE(s.avg_rating, (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id)) as avg_rating
        FROM stays s
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        GROUP BY s.stay_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$stays = $stmt->fetchAll();

// Get property types for filter
$propertyTypesList = $db->query("
    SELECT stay_type, COUNT(*) as count 
    FROM stays 
    WHERE is_active = 1 AND is_verified = 1
    GROUP BY stay_type 
    ORDER BY count DESC
")->fetchAll();

// Get popular locations
$popularLocations = $db->query("
    SELECT l.location_id, l.name, l.type, COUNT(s.stay_id) as stay_count
    FROM locations l
    LEFT JOIN stays s ON l.location_id = s.location_id AND s.is_active = 1 AND s.is_verified = 1
    WHERE l.is_active = 1 AND l.type IN ('city', 'region', 'landmark')
    GROUP BY l.location_id
    HAVING stay_count > 0
    ORDER BY stay_count DESC
    LIMIT 6
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
    /* ===== STAYS LISTING PAGE - BOOKING.COM STYLE ===== */
    .stays-hero {
        background: #003580;
        padding: 24px 0;
        margin-bottom: 24px;
    }

    .stays-hero h1 {
        color: white;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .stays-hero p {
        color: rgba(255, 255, 255, 0.9);
        font-size: 14px;
    }

    /* Filter Sidebar */
    .filter-sidebar {
        background: white;
        border-radius: 8px;
        padding: 20px;
        border: 1px solid #e7e7e7;
        position: sticky;
        top: 80px;
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

    .filter-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .filter-subtitle {
        font-size: 15px;
        font-weight: 600;
        margin-bottom: 16px;
    }

    .price-range {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .price-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #e7e7e7;
        border-radius: 4px;
        font-size: 14px;
    }

    .price-input:focus {
        outline: none;
        border-color: #0071c2;
    }

    .filter-option {
        display: flex;
        align-items: center;
        margin-bottom: 12px;
        font-size: 14px;
        cursor: pointer;
    }

    .filter-option input {
        margin-right: 10px;
        width: 18px;
        height: 18px;
        cursor: pointer;
        accent-color: #0071c2;
    }

    .filter-option .count {
        margin-left: auto;
        color: #6b6b6b;
        font-size: 12px;
        background: #f2f6fa;
        padding: 2px 8px;
        border-radius: 20px;
    }

    /* Star Rating */
    .star-rating {
        display: inline-flex;
        gap: 3px;
        margin-right: 8px;
    }

    .star {
        color: #febb02;
        font-size: 14px;
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
        border: 1px solid #e7e7e7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
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
        border-radius: 4px;
        font-size: 13px;
        background: white;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b6b6b' d='M6 8L2 4h8l-4 4z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    .sort-select:hover {
        border-color: #0071c2;
    }

    /* Property Cards Grid */
    .stays-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 40px;
    }

    .stay-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        overflow: hidden;
        transition: all 0.2s ease;
        text-decoration: none;
        color: #1a1a1a;
        display: block;
    }

    .stay-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
        border-color: #0071c2;
    }

    .card-image {
        position: relative;
        height: 180px;
        overflow: hidden;
        background: #f5f5f5;
    }

    .card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .stay-card:hover .card-image img {
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
        font-size: 14px;
        z-index: 2;
    }

    .card-content {
        padding: 16px;
    }

    .property-type {
        font-size: 12px;
        color: #6b6b6b;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .property-name {
        font-size: 16px;
        font-weight: 700;
        color: #0071c2;
        margin-bottom: 6px;
        line-height: 1.3;
    }

    .property-name:hover {
        text-decoration: underline;
    }

    .property-location {
        font-size: 13px;
        color: #6b6b6b;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .property-location i {
        color: #0071c2;
        font-size: 12px;
    }

    .amenities {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 12px;
    }

    .amenity-tag {
        font-size: 10px;
        padding: 3px 8px;
        background: #f2f6fa;
        border-radius: 4px;
        color: #6b6b6b;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .property-footer {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        border-top: 1px solid #e7e7e7;
        padding-top: 12px;
        margin-top: 8px;
    }

    .rating-info {
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
        color: #1a1a1a;
    }

    .rating-count {
        color: #6b6b6b;
    }

    .price-info {
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
        margin-bottom: 40px;
    }

    .locations-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .locations-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 15px;
    }

    .location-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        padding: 20px 12px;
        text-align: center;
        transition: all 0.2s;
        text-decoration: none;
        color: #1a1a1a;
        display: block;
    }

    .location-card:hover {
        border-color: #0071c2;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .location-icon {
        width: 48px;
        height: 48px;
        background: #ebf3ff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        color: #0071c2;
        font-size: 22px;
    }

    .location-card:hover .location-icon {
        background: #0071c2;
        color: white;
    }

    .location-name {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .location-count {
        font-size: 12px;
        color: #6b6b6b;
    }

    /* Buttons */
    .apply-filters {
        width: 100%;
        padding: 12px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        margin-top: 20px;
    }

    .apply-filters:hover {
        background: #005fa3;
    }

    .clear-filters {
        display: block;
        text-align: center;
        margin-top: 12px;
        color: #0071c2;
        font-size: 13px;
        text-decoration: none;
    }

    .clear-filters:hover {
        text-decoration: underline;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 5px;
        margin-top: 20px;
    }

    .page-link {
        min-width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 12px;
        border: 1px solid #e7e7e7;
        border-radius: 4px;
        color: #1a1a1a;
        text-decoration: none;
        font-size: 14px;
        background: white;
    }

    .page-link:hover {
        border-color: #0071c2;
        background: #ebf3ff;
    }

    .page-link.active {
        background: #0071c2;
        color: white;
        border-color: #0071c2;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        background: white;
        border-radius: 8px;
        border: 1px solid #e7e7e7;
    }

    .empty-icon {
        font-size: 64px;
        color: #6b6b6b;
        margin-bottom: 20px;
    }

    .empty-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .empty-text {
        color: #6b6b6b;
        margin-bottom: 20px;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .stays-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .locations-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 768px) {
        .stays-grid {
            grid-template-columns: 1fr;
        }

        .locations-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .results-header {
            flex-direction: column;
            align-items: stretch;
        }

        .sort-select {
            width: 100%;
        }
    }
</style>

<!-- Hero Section -->
<section class="stays-hero">
    <div class="container">
        <h1><?php echo tr('stays_in_rwanda', 'Stays in Rwanda'); ?></h1>
        <p><?php echo number_format($totalCount); ?> <?php echo tr('properties_found', 'properties found'); ?></p>
    </div>
</section>

<div class="container">
    <!-- Popular Locations -->
    <?php if (!empty($popularLocations)): ?>
        <div class="locations-section">
            <h2 class="locations-title"><?php echo tr('popular_destinations', 'Popular destinations in Rwanda'); ?></h2>
            <div class="locations-grid">
                <?php foreach ($popularLocations as $loc): ?>
                    <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                        <div class="location-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                        <div class="location-count"><?php echo $loc['stay_count']; ?> <?php echo tr('stays', 'stays'); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="filter-sidebar">
                <h3 class="filter-title"><?php echo tr('filter_by', 'Filter by:'); ?></h3>

                <form method="GET" action="" id="filterForm">
                    <!-- Price Range -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle"><?php echo tr('price_range', 'Price range (per night)'); ?></h4>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input"
                                placeholder="<?php echo tr('min_rwf', 'Min RWF'); ?>"
                                value="<?php echo $minPrice ?: ''; ?>">
                            <span>—</span>
                            <input type="number" name="max_price" class="price-input"
                                placeholder="<?php echo tr('max_rwf', 'Max RWF'); ?>"
                                value="<?php echo $maxPrice ?: ''; ?>">
                        </div>
                    </div>

                    <!-- Property Type -->
                    <?php if (!empty($propertyTypesList)): ?>
                        <div class="filter-section">
                            <h4 class="filter-subtitle"><?php echo tr('property_type', 'Property type'); ?></h4>
                            <?php foreach ($propertyTypesList as $pt): ?>
                                <label class="filter-option">
                                    <input type="checkbox" name="property_type[]" value="<?php echo $pt['stay_type']; ?>"
                                        <?php echo in_array($pt['stay_type'], $propertyTypes) ? 'checked' : ''; ?>>
                                    <span><?php echo tr($pt['stay_type'], ucfirst($pt['stay_type'])); ?></span>
                                    <span class="count"><?php echo $pt['count']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Rating Filter -->
                    <div class="filter-section">
                        <h4 class="filter-subtitle"><?php echo tr('guest_rating', 'Guest rating'); ?></h4>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <label class="filter-option">
                                <input type="radio" name="rating" value="<?php echo $i; ?>"
                                    <?php echo $rating == $i ? 'checked' : ''; ?>>
                                <span class="star-rating">
                                    <?php for ($j = 1; $j <= 5; $j++): ?>
                                        <i class="bi bi-star-fill <?php echo $j <= $i ? 'star' : 'star muted'; ?>"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="count"><?php echo $i; ?>+ <?php echo tr('stars', 'stars'); ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>

                    <button type="submit" class="apply-filters"><?php echo tr('apply_filters', 'Apply filters'); ?></button>

                    <?php if ($minPrice || $maxPrice || $rating || !empty($propertyTypes) || $location): ?>
                        <a href="?" class="clear-filters"><?php echo tr('clear_filters', 'Clear all filters'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            <!-- Results Header -->
            <div class="results-header">
                <div class="results-count">
                    <strong><?php echo number_format($totalCount); ?></strong> <?php echo tr('properties', 'properties'); ?>
                    <?php if ($location): ?>
                        <?php echo tr('in', 'in'); ?> <strong><?php echo sanitize($location); ?></strong>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="sortSelect" style="margin-right: 8px;"><?php echo tr('sort_by', 'Sort by:'); ?></label>
                    <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>><?php echo tr('recommended', 'Recommended'); ?></option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>><?php echo tr('price_lowest', 'Price (lowest first)'); ?></option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>><?php echo tr('price_highest', 'Price (highest first)'); ?></option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>><?php echo tr('top_rated', 'Top rated'); ?></option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>><?php echo tr('property_name', 'Property name'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Stays Grid -->
            <?php if (empty($stays)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-building"></i>
                    </div>
                    <h3 class="empty-title"><?php echo tr('no_properties', 'No properties found'); ?></h3>
                    <p class="empty-text"><?php echo tr('try_adjusting_filters', 'Try adjusting your filters or search in a different location'); ?></p>
                    <a href="?" class="apply-filters" style="width: auto; padding: 10px 24px; display: inline-block;"><?php echo tr('clear_filters', 'Clear all filters'); ?></a>
                </div>
            <?php else: ?>
                <div class="stays-grid">
                    <?php foreach ($stays as $stay):
                        $avgRating = $stay['avg_rating'] ?: 0;
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating) : [tr('new', 'New'), 'bg-secondary'];

                        // Get amenities for this stay
                        $amenities = [];
                        if ($stay['amenities']) {
                            $amenityKeys = json_decode($stay['amenities'], true);
                            if (is_array($amenityKeys) && !empty($amenityKeys)) {
                                $placeholders = implode(',', array_fill(0, count($amenityKeys), '?'));
                                $stmtA = $db->prepare("SELECT amenity_name, amenity_icon FROM amenities WHERE amenity_key IN ($placeholders) LIMIT 3");
                                $stmtA->execute($amenityKeys);
                                $amenities = $stmtA->fetchAll();
                            }
                        }

                        $image = $stay['main_image'];
                        if (!$image && !empty($stay['images'])) {
                            $images = json_decode($stay['images'], true);
                            $image = is_array($images) ? $images[0] : null;
                        }

                        $locationName = $stay['location_name'] ?: $stay['city'] ?: 'Rwanda';
                    ?>
                        <a href="detail.php?id=<?php echo $stay['stay_id']; ?>" class="stay-card">
                            <div class="card-image">
                                <img src="<?php echo getImageUrl($image, 'stay'); ?>"
                                    alt="<?php echo sanitize($stay['stay_name']); ?>"
                                    loading="lazy"
                                    onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60'">

                                <?php if ($stay['is_verified']): ?>
                                    <span class="card-badge verified"><?php echo tr('verified', 'Verified'); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="card-content">
                                <div class="property-type"><?php echo tr($stay['stay_type'], ucfirst($stay['stay_type'])); ?></div>
                                <h3 class="property-name"><?php echo sanitize($stay['stay_name']); ?></h3>
                                <div class="property-location">
                                    <i class="bi bi-geo-alt"></i>
                                    <?php echo sanitize($locationName); ?>
                                </div>

                                <?php if (!empty($amenities)): ?>
                                    <div class="amenities">
                                        <?php foreach ($amenities as $amenity): ?>
                                            <span class="amenity-tag">
                                                <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                                                <?php echo sanitize($amenity['amenity_name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="property-footer">
                                    <div class="rating-info">
                                        <?php if ($stay['review_count'] > 0): ?>
                                            <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                                            <div class="rating-text">
                                                <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                                                <div class="rating-count"><?php echo number_format($stay['review_count']); ?> <?php echo tr('reviews', 'reviews'); ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span class="rating-score" style="background: #6c757d;"><?php echo tr('new', 'New'); ?></span>
                                            <div class="rating-text">
                                                <div class="rating-label"><?php echo tr('new', 'New'); ?></div>
                                                <div class="rating-count">0 <?php echo tr('reviews', 'reviews'); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($stay['min_price'] && $stay['min_price'] > 0): ?>
                                        <div class="price-info">
                                            <div class="price-from"><?php echo tr('from', 'from'); ?></div>
                                            <div class="price-value"><?php echo formatPrice($stay['min_price'], $currentCurrency); ?></div>
                                            <div class="price-unit"><?php echo tr('per_night', 'per night'); ?></div>
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
                            <?php if ($start > 2): ?>
                                <span class="page-link" style="border: none;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                class="page-link <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end < $totalPages): ?>
                            <?php if ($end < $totalPages - 1): ?>
                                <span class="page-link" style="border: none;">...</span>
                            <?php endif; ?>
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

    // Prevent negative prices
    document.querySelectorAll('.price-input').forEach(input => {
        input.addEventListener('change', function() {
            if (this.value < 0) this.value = 0;
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>