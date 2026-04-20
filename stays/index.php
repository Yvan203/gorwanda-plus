<?php
require_once '../includes/functions.php';

$pageTitle = 'Stays in Rwanda - Hotels, Lodges & Apartments - GoRwanda+';
$currentPage = 'stays';

// Get search parameters safely
$location = isset($_GET['location']) ? sanitize($_GET['location']) : '';
$checkin = isset($_GET['checkin']) ? $_GET['checkin'] : '';
$checkout = isset($_GET['checkout']) ? $_GET['checkout'] : '';
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 15; // Booking.com shows 15-20 per page
$offset = ($page - 1) * $perPage;

// Filters with safe checks
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recommended';
$minPrice = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$maxPrice = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$propertyTypes = isset($_GET['property_type']) ? (array)$_GET['property_type'] : [];
$selectedAmenities = isset($_GET['amenities']) ? (array)$_GET['amenities'] : [];

$db = getDB();

// Get all active stays with their details
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
    $where[] = "s.avg_rating >= ?";
    $params[] = $rating;
}

// Price filters using subquery
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

// Sorting logic - exactly like Booking.com
switch($sort) {
    case 'price_low':
        $orderBy = 'min_price ASC';
        break;
    case 'price_high':
        $orderBy = 'min_price DESC';
        break;
    case 'rating':
        $orderBy = 's.avg_rating DESC, s.review_count DESC';
        break;
    case 'name':
        $orderBy = 's.stay_name ASC';
        break;
    case 'recommended':
    default:
        $orderBy = 's.avg_rating DESC, s.review_count DESC, s.stay_id DESC';
        break;
}

// Main query
$sql = "SELECT s.*, l.name as location_name,
        (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
        (SELECT MAX(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as max_price,
        (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as room_count,
        (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
        (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id) as avg_rating_calc
        FROM stays s
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE " . implode(" AND ", $where) . "
        GROUP BY s.stay_id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$stays = $stmt->fetchAll();

// Get property types for filter with counts
$propertyTypesList = $db->query("
    SELECT stay_type, COUNT(*) as count 
    FROM stays 
    WHERE is_active = 1 AND is_verified = 1
    GROUP BY stay_type 
    ORDER BY count DESC
")->fetchAll();

// Get popular locations with counts
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

// Get all amenities for filter
$amenitiesList = $db->query("
    SELECT amenity_key, amenity_name 
    FROM amenities 
    WHERE category IN ('property', 'room') AND is_active = 1
    ORDER BY amenity_name
")->fetchAll();

require_once '../includes/header.php';
?>

<style>
/* ===== STAYS LANDING PAGE - EXACT BOOKING.COM STYLE ===== */
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

/* Hero Banner - Exactly like Booking.com */
.stays-hero {
    background: var(--bkg-blue-dark);
    padding: 24px 0 16px;
    margin-bottom: 24px;
}

.stays-hero h1 {
    color: white;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 4px;
}

.stays-hero p {
    color: rgba(255,255,255,0.9);
    font-size: 14px;
    margin-bottom: 0;
}

/* Filter Sidebar - Booking.com exact */
.stays-filter-sidebar {
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
    transition: var(--transition);
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

/* Results Header - Booking.com exact */
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

.sort-select:focus {
    outline: none;
    border-color: var(--bkg-blue-primary);
}

/* Property Cards Grid - Booking.com exact */
.stays-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

.stay-card {
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

.stay-card:hover {
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

.stay-card:hover .card-image img {
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

.property-type {
    font-size: 11px;
    color: var(--bkg-gray-500);
    text-transform: uppercase;
    margin-bottom: 2px;
}

.property-name {
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

.property-name:hover {
    text-decoration: underline;
}

.property-location {
    font-size: 12px;
    color: var(--bkg-gray-500);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.property-location i {
    color: var(--bkg-blue-primary);
    font-size: 12px;
}

.property-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-top: 1px solid var(--bkg-gray-200);
    padding-top: 10px;
    margin-top: 6px;
}

.property-rating {
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

.property-price {
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

/* Popular Locations - Booking.com style */
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

/* Pagination - Booking.com exact */
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
        align-items: flex-start;
    }
    
    .sort-section {
        width: 100%;
    }
    
    .sort-select {
        flex: 1;
    }
}
</style>

<!-- Hero Banner - Exactly like Booking.com -->
<section class="stays-hero">
    <div class="container">
        <h1>Stays in Rwanda</h1>
        <p><?php echo number_format($totalCount); ?> properties found</p>
    </div>
</section>

<div class="container">
    <!-- Popular Locations Section - Exactly like Booking.com -->
    <?php if (!empty($popularLocations)): ?>
    <div class="locations-section">
        <h2 class="locations-title">Popular destinations in Rwanda</h2>
        <div class="locations-grid">
            <?php foreach ($popularLocations as $loc): ?>
            <a href="?location=<?php echo urlencode($loc['name']); ?>" class="location-card">
                <div class="location-icon">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="location-name"><?php echo sanitize($loc['name']); ?></div>
                <div class="location-count"><?php echo $loc['stay_count']; ?> stays</div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Filters Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="stays-filter-sidebar">
                <h5 class="filter-title">Filter by:</h5>
                
                <form method="GET" action="" id="filterForm">
                    <!-- Price Range -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Price range (per night)</h6>
                        <div class="price-range">
                            <input type="number" name="min_price" class="price-input" 
                                   placeholder="Min RWF" value="<?php echo $minPrice ?: ''; ?>" min="0">
                            <span class="price-sep">—</span>
                            <input type="number" name="max_price" class="price-input" 
                                   placeholder="Max RWF" value="<?php echo $maxPrice ?: ''; ?>" min="0">
                        </div>
                    </div>
                    
                    <!-- Property Type -->
                    <?php if (!empty($propertyTypesList)): ?>
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Property type</h6>
                        <?php foreach ($propertyTypesList as $pt): 
                            $val = $pt['stay_type'];
                            $label = ucfirst($val);
                            $count = $pt['count'];
                        ?>
                        <label class="filter-option">
                            <input type="checkbox" name="property_type[]" value="<?php echo $val; ?>" 
                                <?php echo in_array($val, $propertyTypes) ? 'checked' : ''; ?>>
                            <span><?php echo $label; ?></span>
                            <span class="count"><?php echo $count; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Rating -->
                    <div class="filter-section">
                        <h6 class="filter-subtitle">Guest rating</h6>
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
                    
                    <?php if ($minPrice || $maxPrice || $rating || !empty($propertyTypes)): ?>
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
                    <strong><?php echo number_format($totalCount); ?></strong> properties
                    <?php if ($location): ?> in <strong><?php echo sanitize($location); ?></strong><?php endif; ?>
                </div>
                
                <div class="sort-section">
                    <label for="sortSelect">Sort by:</label>
                    <select id="sortSelect" class="sort-select" onchange="applySort(this.value)">
                        <option value="recommended" <?php echo $sort === 'recommended' ? 'selected' : ''; ?>>Recommended</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price (lowest first)</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price (highest first)</option>
                        <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Top rated</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Property name</option>
                    </select>
                </div>
            </div>
            
            <!-- Stays Grid -->
            <?php if (empty($stays)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-building"></i>
                    </div>
                    <h3 class="empty-title">No properties found</h3>
                    <p class="empty-text">Try adjusting your filters or search in a different location</p>
                    <a href="?" class="apply-filters" style="width: auto; padding: 10px 24px;">Clear all filters</a>
                </div>
            <?php else: ?>
                <div class="stays-grid">
                    <?php foreach ($stays as $stay): 
                        // Use avg_rating from stays table or calculate from reviews
                        $avgRating = $stay['avg_rating'] ?: ($stay['avg_rating_calc'] ?: 0);
                        $reviewLabel = $avgRating ? getReviewLabel($avgRating) : ['New', 'bg-secondary'];
                        
                        // Get image - use main_image or first from images array
                        $image = $stay['main_image'];
                        if (!$image && !empty($stay['images'])) {
                            $images = json_decode($stay['images'], true);
                            $image = is_array($images) ? $images[0] : null;
                        }
                        
                        // Format location name
                        $locationName = $stay['location_name'] ?: $stay['city'] ?: 'Rwanda';
                    ?>
                    <a href="detail.php?id=<?php echo $stay['stay_id']; ?>" class="stay-card">
                        <div class="card-image">
                            <img src="<?php echo getImageUrl($image, 'stay'); ?>" 
                                 alt="<?php echo sanitize($stay['stay_name']); ?>"
                                 loading="lazy"
                                 onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60'">
                            
                            <?php if ($stay['is_verified']): ?>
                            <span class="card-badge verified">Verified</span>
                            <?php endif; ?>
                            
                            <?php if ($avgRating > 0): ?>
                            <span class="rating-badge"><?php echo number_format($avgRating, 1); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-content">
                            <div class="property-type"><?php echo ucfirst($stay['stay_type']); ?></div>
                            <h3 class="property-name"><?php echo sanitize($stay['stay_name']); ?></h3>
                            <div class="property-location">
                                <i class="bi bi-geo-alt"></i>
                                <?php echo sanitize($locationName); ?>
                            </div>
                            
                            <div class="property-footer">
                                <div class="property-rating">
                                    <?php if ($stay['review_count'] > 0): ?>
                                        <span class="rating-score"><?php echo number_format($avgRating, 1); ?></span>
                                        <div class="rating-text">
                                            <div class="rating-label"><?php echo $reviewLabel[0]; ?></div>
                                            <div class="rating-count"><?php echo number_format($stay['review_count']); ?> reviews</div>
                                        </div>
                                    <?php else: ?>
                                        <span class="rating-score" style="background: #6c757d;">New</span>
                                        <div class="rating-text">
                                            <div class="rating-label">New</div>
                                            <div class="rating-count">0 reviews</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($stay['min_price'] > 0): ?>
                                <div class="property-price">
                                    <div class="price-from">from</div>
                                    <div class="price-value"><?php echo formatPrice($stay['min_price']); ?></div>
                                    <div class="price-unit">per night</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination - Exactly like Booking.com -->
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
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}

// Ensure price inputs are numbers only
document.querySelectorAll('.price-input').forEach(input => {
    input.addEventListener('keypress', function(e) {
        if (isNaN(String.fromCharCode(e.keyCode)) && e.keyCode !== 8) {
            e.preventDefault();
        }
    });
});

// Smooth hover effects
document.querySelectorAll('.stay-card, .location-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.transition = 'all 0.2s ease';
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>