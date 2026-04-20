<?php
require_once '../includes/functions.php';

$currentPage = 'restaurants';
// Get restaurant ID
$restaurant_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$restaurant_id) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Get restaurant details - add COALESCE for location_id
$stmt = $db->prepare("
    SELECT r.*, s.stay_name as hotel_name, s.stay_id, s.address, s.phone as hotel_phone, s.email as hotel_email,
           l.name as location_name, l.latitude, l.longitude, COALESCE(s.location_id, 0) as location_id,
           (SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.restaurant_id) as review_count,
           (SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as menu_count,
           (SELECT MIN(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as min_price,
           (SELECT MAX(mi.price) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as max_price
    FROM restaurants r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE r.restaurant_id = ? AND r.is_active = 1
");
$stmt->execute([$restaurant_id]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    header('Location: index.php');
    exit;
}

// Get restaurant images
$stmt = $db->prepare("
    SELECT * FROM restaurant_images 
    WHERE restaurant_id = ? 
    ORDER BY is_main DESC, sort_order ASC
");
$stmt->execute([$restaurant_id]);
$images = $stmt->fetchAll();

// Get menu categories with items
$stmt = $db->prepare("
    SELECT mc.*, 
           (SELECT COUNT(*) FROM menu_items WHERE category_id = mc.category_id AND is_available = 1) as item_count
    FROM menu_categories mc
    WHERE mc.restaurant_id = ? AND mc.is_active = 1
    ORDER BY mc.display_order ASC
");
$stmt->execute([$restaurant_id]);
$categories = $stmt->fetchAll();

// Get menu items for each category
$menu_items = [];
foreach ($categories as $category) {
    $stmt = $db->prepare("
        SELECT * FROM menu_items 
        WHERE category_id = ? AND is_available = 1
        ORDER BY display_order ASC, item_name ASC
    ");
    $stmt->execute([$category['category_id']]);
    $menu_items[$category['category_id']] = $stmt->fetchAll();
}

// Get reviews
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           DATE_FORMAT(r.created_at, '%M %Y') as review_month
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.restaurant_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$restaurant_id]);
$reviews = $stmt->fetchAll();

// Get rating breakdown
$rating_breakdown = $db->prepare("
    SELECT 
        COUNT(CASE WHEN overall_rating >= 9 THEN 1 END) as excellent,
        COUNT(CASE WHEN overall_rating BETWEEN 7 AND 8 THEN 1 END) as very_good,
        COUNT(CASE WHEN overall_rating BETWEEN 5 AND 6 THEN 1 END) as good,
        COUNT(CASE WHEN overall_rating BETWEEN 3 AND 4 THEN 1 END) as fair,
        COUNT(CASE WHEN overall_rating <= 2 THEN 1 END) as poor,
        AVG(overall_rating) as avg_rating,
        COUNT(*) as total
    FROM reviews
    WHERE restaurant_id = ? AND is_active = 1
");
$rating_breakdown->execute([$restaurant_id]);
$ratings = $rating_breakdown->fetch();

// Get similar restaurants (same cuisine or location)
$stmt = $db->prepare("
    SELECT r.*, s.stay_name as hotel_name, l.name as location_name,
           (SELECT AVG(overall_rating) FROM reviews WHERE restaurant_id = r.restaurant_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE restaurant_id = r.restaurant_id) as review_count,
           (SELECT image_path FROM restaurant_images WHERE restaurant_id = r.restaurant_id AND is_main = 1 LIMIT 1) as main_image
    FROM restaurants r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE r.restaurant_id != ? AND r.is_active = 1
    AND (r.cuisine_type LIKE ? OR (s.location_id IS NOT NULL AND s.location_id = ?))
    GROUP BY r.restaurant_id
    ORDER BY avg_rating DESC
    LIMIT 4
");
$similar_cuisine = '%' . $restaurant['cuisine_type'] . '%';
$location_id = $restaurant['location_id'] ?? 0; // Add null check here
$stmt->execute([$restaurant_id, $similar_cuisine, $location_id]);
$similar_restaurants = $stmt->fetchAll();

$pageTitle = sanitize($restaurant['restaurant_name']) . ' - ' . sanitize($restaurant['cuisine_type']) . ' Restaurant - GoRwanda+';
$currentPage = 'restaurants';

require_once '../includes/header.php';
?>

<style>
/* ===== RESTAURANT DETAIL PAGE - EXACT BOOKING.COM STYLE ===== */
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

/* Breadcrumb */
.bkg-breadcrumb {
    padding: 16px 0;
    background: white;
    border-bottom: 1px solid var(--bkg-gray-200);
    margin-bottom: 24px;
}

.breadcrumb-list {
    display: flex;
    align-items: center;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 12px;
}

.breadcrumb-item a {
    color: var(--bkg-gray-500);
    text-decoration: none;
}

.breadcrumb-item a:hover {
    color: var(--bkg-blue-primary);
    text-decoration: underline;
}

.breadcrumb-item.active {
    color: var(--bkg-gray-700);
    font-weight: 500;
}

.breadcrumb-separator {
    color: var(--bkg-gray-400);
    font-size: 10px;
}

/* Restaurant Header */
.restaurant-header {
    margin-bottom: 24px;
}

.restaurant-title {
    font-size: 28px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 8px;
}

.restaurant-meta {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.rating-large {
    display: flex;
    align-items: center;
    gap: 8px;
}

.rating-score-large {
    background: var(--bkg-blue-dark);
    color: white;
    padding: 8px 12px;
    border-radius: 6px 6px 6px 0;
    font-weight: 700;
    font-size: 20px;
    line-height: 1;
}

.rating-text-large {
    font-size: 14px;
}

.rating-label-large {
    font-weight: 600;
    color: var(--bkg-gray-700);
}

.rating-count-large {
    color: var(--bkg-gray-500);
}

.restaurant-cuisine {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--bkg-gray-500);
    font-size: 14px;
}

.restaurant-cuisine i {
    color: var(--bkg-blue-primary);
}

.restaurant-location-large {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--bkg-gray-500);
    font-size: 14px;
}

.restaurant-location-large i {
    color: var(--bkg-blue-primary);
}

/* Image Gallery */
.gallery-section {
    margin-bottom: 32px;
}

.gallery-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    grid-template-rows: 200px 200px;
    gap: 4px;
    border-radius: 8px;
    overflow: hidden;
}

.gallery-main {
    grid-row: span 2;
    height: 404px;
}

.gallery-item {
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.gallery-item:hover img {
    transform: scale(1.05);
}

.gallery-overlay {
    position: absolute;
    bottom: 0;
    right: 0;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 600;
    border-top-left-radius: 8px;
    cursor: pointer;
    backdrop-filter: blur(4px);
}

.gallery-overlay i {
    margin-right: 4px;
}

/* Quick Info Cards */
.quick-info {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

.info-card {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.info-icon {
    width: 48px;
    height: 48px;
    background: var(--bkg-blue-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bkg-blue-primary);
    font-size: 20px;
}

.info-content h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    margin-bottom: 4px;
}

.info-content p {
    font-size: 13px;
    color: var(--bkg-gray-500);
    margin-bottom: 0;
}

.info-content .price-range {
    font-size: 15px;
    font-weight: 600;
    color: var(--bkg-green);
}

/* Reservation Card */
.reservation-card {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 32px;
    box-shadow: var(--shadow-sm);
}

.reservation-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 20px;
}

.reservation-form {
    display: grid;
    grid-template-columns: repeat(4, 1fr) auto;
    gap: 12px;
    align-items: end;
}

.reservation-field {
    flex: 1;
}

.reservation-label {
    font-size: 12px;
    color: var(--bkg-gray-500);
    margin-bottom: 4px;
    display: block;
}

.reservation-input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 4px;
    font-size: 14px;
    transition: var(--transition);
}

.reservation-input:focus {
    outline: none;
    border-color: var(--bkg-blue-primary);
    box-shadow: 0 0 0 3px rgba(0,113,194,0.1);
}

.reservation-select {
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

.reservation-btn {
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
    height: 48px;
}

.reservation-btn:hover {
    background: #005fa3;
}

.reservation-note {
    font-size: 12px;
    color: var(--bkg-green);
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.reservation-note i {
    font-size: 14px;
}

/* Tabs Navigation */
.restaurant-tabs {
    border-bottom: 1px solid var(--bkg-gray-200);
    margin-bottom: 24px;
}

.tabs-nav {
    display: flex;
    gap: 4px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.tab-item {
    margin-bottom: -1px;
}

.tab-link {
    display: block;
    padding: 12px 20px;
    color: var(--bkg-gray-500);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    transition: var(--transition);
}

.tab-link:hover {
    color: var(--bkg-blue-primary);
    background: var(--bkg-blue-light);
}

.tab-link.active {
    color: var(--bkg-blue-primary);
    border-color: var(--bkg-gray-200);
    border-bottom-color: white;
    background: white;
    font-weight: 600;
}

.tab-link i {
    margin-right: 6px;
}

/* About Section */
.about-section {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 32px;
}

.about-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 16px;
}

.about-description {
    font-size: 14px;
    line-height: 1.6;
    color: var(--bkg-gray-700);
    margin-bottom: 24px;
}

.about-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.about-item {
    display: flex;
    gap: 12px;
}

.about-item i {
    width: 24px;
    color: var(--bkg-blue-primary);
    font-size: 18px;
}

.about-item h5 {
    font-size: 14px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    margin-bottom: 4px;
}

.about-item p {
    font-size: 13px;
    color: var(--bkg-gray-500);
    margin-bottom: 0;
}

/* Menu Section */
.menu-section {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 32px;
}

.menu-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 20px;
}

.menu-category {
    margin-bottom: 32px;
}

.menu-category:last-child {
    margin-bottom: 0;
}

.menu-category-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    padding-bottom: 12px;
    border-bottom: 2px solid var(--bkg-blue-light);
    margin-bottom: 16px;
}

.menu-items {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.menu-item {
    display: flex;
    gap: 16px;
    padding: 12px;
    border-radius: 8px;
    transition: var(--transition);
}

.menu-item:hover {
    background: var(--bkg-gray-100);
}

.menu-item-image {
    width: 80px;
    height: 80px;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
}

.menu-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.menu-item-content {
    flex: 1;
}

.menu-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.menu-item-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--bkg-blue-primary);
}

.menu-item-price {
    font-size: 16px;
    font-weight: 700;
    color: var(--bkg-green);
}

.menu-item-desc {
    font-size: 12px;
    color: var(--bkg-gray-500);
    margin-bottom: 6px;
    line-height: 1.5;
}

.menu-item-tags {
    display: flex;
    gap: 8px;
    font-size: 10px;
}

.menu-tag {
    padding: 2px 8px;
    border-radius: 12px;
    background: var(--bkg-gray-100);
    color: var(--bkg-gray-500);
}

.menu-tag.vegetarian {
    background: #d4edda;
    color: #155724;
}

.menu-tag.spicy {
    background: #f8d7da;
    color: #721c24;
}

.menu-tag.signature {
    background: var(--bkg-yellow);
    color: var(--bkg-gray-700);
}

/* Reviews Section */
.reviews-section {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 32px;
}

.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.reviews-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
}

.reviews-summary {
    display: flex;
    gap: 32px;
    margin-bottom: 32px;
}

.summary-score {
    text-align: center;
    min-width: 100px;
}

.summary-score .score {
    font-size: 48px;
    font-weight: 700;
    color: var(--bkg-blue-dark);
    line-height: 1;
}

.summary-score .label {
    font-size: 14px;
    color: var(--bkg-gray-500);
}

.summary-bars {
    flex: 1;
}

.rating-bar-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.rating-bar-label {
    width: 60px;
    font-size: 13px;
    color: var(--bkg-gray-500);
}

.rating-bar {
    flex: 1;
    height: 8px;
    background: var(--bkg-gray-200);
    border-radius: 4px;
    overflow: hidden;
}

.rating-bar-fill {
    height: 100%;
    background: var(--bkg-blue-primary);
    border-radius: 4px;
}

.rating-bar-count {
    width: 40px;
    font-size: 12px;
    color: var(--bkg-gray-500);
    text-align: right;
}

.review-card {
    border-bottom: 1px solid var(--bkg-gray-200);
    padding: 20px 0;
}

.review-card:last-child {
    border-bottom: none;
}

.reviewer-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.reviewer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    background: var(--bkg-blue-light);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bkg-blue-primary);
    font-weight: 600;
    font-size: 18px;
}

.reviewer-details h5 {
    font-size: 15px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    margin-bottom: 2px;
}

.reviewer-meta {
    font-size: 12px;
    color: var(--bkg-gray-500);
    display: flex;
    align-items: center;
    gap: 8px;
}

.review-rating {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.review-score-small {
    background: var(--bkg-blue-dark);
    color: white;
    padding: 4px 8px;
    border-radius: 4px 4px 4px 0;
    font-weight: 700;
    font-size: 16px;
}

.review-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--bkg-gray-700);
    margin-bottom: 8px;
}

.review-text {
    font-size: 14px;
    line-height: 1.6;
    color: var(--bkg-gray-700);
    margin-bottom: 12px;
}

.review-positive {
    background: #d4edda;
    color: #155724;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 8px;
}

.review-negative {
    background: #f8d7da;
    color: #721c24;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 8px;
}

.review-helpful {
    display: flex;
    align-items: center;
    gap: 16px;
}

.btn-helpful {
    background: transparent;
    border: 1px solid var(--bkg-gray-200);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    color: var(--bkg-gray-500);
    cursor: pointer;
    transition: var(--transition);
}

.btn-helpful:hover {
    border-color: var(--bkg-blue-primary);
    color: var(--bkg-blue-primary);
}

.btn-helpful i {
    margin-right: 4px;
}

/* Location Section */
.location-section {
    background: white;
    border: 1px solid var(--bkg-gray-200);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 32px;
}

.location-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 16px;
}

.location-map {
    height: 300px;
    background: var(--bkg-gray-100);
    border-radius: 8px;
    margin-bottom: 16px;
    position: relative;
    overflow: hidden;
}

.map-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bkg-gray-100);
    color: var(--bkg-gray-500);
    font-size: 14px;
}

.location-address {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--bkg-gray-100);
    border-radius: 8px;
}

.location-address i {
    color: var(--bkg-blue-primary);
    font-size: 20px;
}

.location-address p {
    margin: 0;
    font-size: 14px;
    color: var(--bkg-gray-700);
}

.location-address small {
    color: var(--bkg-gray-500);
    font-size: 12px;
}

/* Similar Restaurants */
.similar-section {
    margin-bottom: 40px;
}

.similar-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--bkg-gray-700);
    margin-bottom: 20px;
}

.similar-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.similar-card {
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

.similar-card:hover {
    box-shadow: var(--shadow-lg);
    border-color: var(--bkg-blue-primary);
    transform: translateY(-4px);
}

.similar-image {
    height: 140px;
    overflow: hidden;
}

.similar-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s;
}

.similar-card:hover .similar-image img {
    transform: scale(1.08);
}

.similar-content {
    padding: 12px;
}

.similar-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--bkg-blue-primary);
    margin-bottom: 2px;
}

.similar-cuisine {
    font-size: 11px;
    color: var(--bkg-gray-500);
    margin-bottom: 4px;
}

.similar-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
}

.similar-rating {
    display: flex;
    align-items: center;
    gap: 4px;
}

.similar-score {
    background: var(--bkg-blue-dark);
    color: white;
    padding: 2px 6px;
    border-radius: 4px 4px 4px 0;
    font-weight: 700;
    font-size: 12px;
}

/* Responsive */
@media (max-width: 992px) {
    .quick-info {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .reservation-form {
        grid-template-columns: 1fr;
    }
    
    .menu-items {
        grid-template-columns: 1fr;
    }
    
    .similar-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .reviews-summary {
        flex-direction: column;
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .gallery-grid {
        grid-template-columns: 1fr;
        grid-template-rows: auto;
    }
    
    .gallery-main {
        grid-row: auto;
        height: 250px;
    }
    
    .restaurant-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .quick-info {
        grid-template-columns: 1fr;
    }
    
    .about-grid {
        grid-template-columns: 1fr;
    }
    
    .similar-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Breadcrumb -->
<div class="bkg-breadcrumb">
    <div class="container">
        <ul class="breadcrumb-list">
            <li class="breadcrumb-item"><a href="/gorwanda-plus/">Home</a></li>
            <li class="breadcrumb-separator"><i class="bi bi-chevron-right"></i></li>
            <li class="breadcrumb-item"><a href="/gorwanda-plus/restaurants/">Restaurants</a></li>
            <li class="breadcrumb-separator"><i class="bi bi-chevron-right"></i></li>
            <li class="breadcrumb-item active"><?php echo sanitize($restaurant['restaurant_name']); ?></li>
        </ul>
    </div>
</div>

<div class="container">
    <!-- Restaurant Header -->
    <div class="restaurant-header">
        <h1 class="restaurant-title"><?php echo sanitize($restaurant['restaurant_name']); ?></h1>
        
        <div class="restaurant-meta">
            <?php if ($restaurant['avg_rating'] > 0): ?>
            <div class="rating-large">
                <span class="rating-score-large"><?php echo number_format($restaurant['avg_rating'], 1); ?></span>
                <div class="rating-text-large">
                    <div class="rating-label-large"><?php echo getReviewLabel($restaurant['avg_rating'] * 2)[0]; ?></div>
                    <div class="rating-count-large"><?php echo number_format($restaurant['review_count']); ?> reviews</div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="restaurant-cuisine">
                <i class="bi bi-shop"></i>
                <span><?php echo sanitize($restaurant['cuisine_type'] ?: 'Various Cuisines'); ?></span>
            </div>
            
            <div class="restaurant-location-large">
                <i class="bi bi-geo-alt"></i>
                <span><?php echo sanitize($restaurant['location_name'] ?: $restaurant['city'] ?: 'Rwanda'); ?></span>
                <?php if ($restaurant['hotel_name']): ?>
                <span class="text-muted">· in <?php echo sanitize($restaurant['hotel_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Image Gallery -->
    <?php if (!empty($images)): ?>
    <div class="gallery-section">
        <div class="gallery-grid">
            <?php foreach ($images as $index => $image): ?>
                <?php if ($index == 0): ?>
                <div class="gallery-main gallery-item" onclick="openGallery(0)">
                    <img src="/gorwanda-plus/assets/images/restaurants/<?php echo $image['image_path']; ?>" 
                         alt="<?php echo sanitize($restaurant['restaurant_name']); ?>">
                </div>
                <?php elseif ($index < 4): ?>
                <div class="gallery-item" onclick="openGallery(<?php echo $index; ?>)">
                    <img src="/gorwanda-plus/assets/images/restaurants/<?php echo $image['image_path']; ?>" 
                         alt="<?php echo sanitize($restaurant['restaurant_name']); ?>">
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <?php if (count($images) > 4): ?>
            <div class="gallery-item" onclick="openGallery(4)">
                <img src="/gorwanda-plus/assets/images/restaurants/<?php echo $images[4]['image_path']; ?>" 
                     alt="<?php echo sanitize($restaurant['restaurant_name']); ?>">
                <div class="gallery-overlay">
                    <i class="bi bi-images"></i> +<?php echo count($images) - 4; ?> more
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Quick Info Cards -->
    <div class="quick-info">
        <div class="info-card">
            <div class="info-icon">
                <i class="bi bi-clock"></i>
            </div>
            <div class="info-content">
                <h4>Opening hours</h4>
                <?php 
// Opening hours with null check
$hours = !empty($restaurant['opening_hours']) ? json_decode($restaurant['opening_hours'], true) : [];
if (!empty($hours)): 
    $today = strtolower(date('l'));
    $todayHours = $hours[$today] ?? 'Closed';
?>
<p><?php echo $todayHours; ?></p>
<?php else: ?>
<p>Daily 11:00 - 22:00</p>
<?php endif; ?>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-icon">
                <i class="bi bi-currency-dollar"></i>
            </div>
            <div class="info-content">
                <h4>Price range</h4>
                <?php if ($restaurant['min_price'] && $restaurant['max_price']): ?>
                <p class="price-range"><?php echo formatPrice($restaurant['min_price']); ?> - <?php echo formatPrice($restaurant['max_price']); ?></p>
                <?php else: ?>
                <p>Average meal</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-icon">
                <i class="bi bi-people"></i>
            </div>
            <div class="info-content">
                <h4>Seating</h4>
                <p><?php echo $restaurant['seating_capacity'] ?: '80'; ?> guests</p>
            </div>
        </div>
        
        <div class="info-card">
            <div class="info-icon">
                <i class="bi bi-check-circle"></i>
            </div>
            <div class="info-content">
                <h4>Features</h4>
                <p>
                    <?php 
                    $features = [];
                    if ($restaurant['has_outdoor_seating']) $features[] = 'Outdoor';
                    if ($restaurant['has_private_dining']) $features[] = 'Private';
                    if ($restaurant['accepts_reservations']) $features[] = 'Reservations';
                    echo !empty($features) ? implode(' · ', $features) : 'Standard';
                    ?>
                </p>
            </div>
        </div>
    </div>
    
<!-- Reservation Card -->
<?php if ($restaurant['accepts_reservations']): ?>
<div class="reservation-card">
    <h3 class="reservation-title">Make a reservation</h3>
    <form action="reservation.php" method="POST" class="reservation-form">
        <input type="hidden" name="restaurant_id" value="<?php echo $restaurant_id; ?>">
        
        <div class="reservation-field">
            <label class="reservation-label">Date</label>
            <input type="date" name="date" class="reservation-input" 
                   min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Time</label>
            <select name="time" class="reservation-select" required>
                <option value="12:00">12:00 PM</option>
                <option value="13:00">1:00 PM</option>
                <option value="18:00">6:00 PM</option>
                <option value="19:00">7:00 PM</option>
                <option value="20:00">8:00 PM</option>
            </select>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Guests</label>
            <select name="guests" class="reservation-select" required>
                <?php for($i=1; $i<=10; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?> person<?php echo $i > 1 ? 's' : ''; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Name</label>
            <input type="text" name="name" class="reservation-input" 
                   placeholder="Your name" value="<?php echo isLoggedIn() ? sanitize($currentUser['first_name'] . ' ' . $currentUser['last_name']) : ''; ?>" required>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Email</label>
            <input type="email" name="email" class="reservation-input" 
                   placeholder="Your email" value="<?php echo isLoggedIn() ? sanitize($currentUser['email']) : ''; ?>" required>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Phone (optional)</label>
            <input type="tel" name="phone" class="reservation-input" 
                   placeholder="Your phone number" value="<?php echo isLoggedIn() ? sanitize($currentUser['phone'] ?? '') : ''; ?>">
        </div>
        
        <div class="reservation-field" style="grid-column: span 2;">
            <label class="reservation-label">Special requests (optional)</label>
            <textarea name="special_requests" class="reservation-input" rows="2" placeholder="Any allergies, preferences, or special occasions?"></textarea>
        </div>
        
        <div class="reservation-field">
            <label class="reservation-label">Table preference</label>
            <select name="table_preference" class="reservation-select">
                <option value="">No preference</option>
                <option value="window">Window view</option>
                <option value="outdoor">Outdoor seating</option>
                <option value="quiet">Quiet area</option>
                <option value="private">Private dining</option>
            </select>
        </div>
        
        <button type="submit" class="reservation-btn">Reserve now</button>
    </form>
    <div class="reservation-note">
        <i class="bi bi-check-circle-fill"></i>
        <span>Free cancellation up to 2 hours before</span>
    </div>
</div>
<?php endif; ?>

    <!-- Tabs Navigation -->
    <div class="restaurant-tabs">
        <ul class="tabs-nav">
            <li class="tab-item">
                <a href="#about" class="tab-link active" onclick="switchTab(event, 'about')">
                    <i class="bi bi-info-circle"></i> About
                </a>
            </li>
            <?php if (!empty($categories)): ?>
            <li class="tab-item">
                <a href="#menu" class="tab-link" onclick="switchTab(event, 'menu')">
                    <i class="bi bi-menu-app"></i> Menu (<?php echo $restaurant['menu_count']; ?>)
                </a>
            </li>
            <?php endif; ?>
            <?php if (!empty($reviews)): ?>
            <li class="tab-item">
                <a href="#reviews" class="tab-link" onclick="switchTab(event, 'reviews')">
                    <i class="bi bi-star"></i> Reviews (<?php echo $restaurant['review_count']; ?>)
                </a>
            </li>
            <?php endif; ?>
            <li class="tab-item">
                <a href="#location" class="tab-link" onclick="switchTab(event, 'location')">
                    <i class="bi bi-geo-alt"></i> Location
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Tab Content -->
    <div id="about" class="tab-content active">
        <!-- About Section -->
        <div class="about-section">
            <h3 class="about-title">About <?php echo sanitize($restaurant['restaurant_name']); ?></h3>
            
            <?php if ($restaurant['description']): ?>
            <div class="about-description">
                <?php echo nl2br(sanitize($restaurant['description'])); ?>
            </div>
            <?php endif; ?>
            
            <div class="about-grid">
                <?php if ($restaurant['dress_code']): ?>
                <div class="about-item">
                    <i class="bi bi-person-standing-dress"></i>
                    <div>
                        <h5>Dress code</h5>
                        <p><?php echo sanitize($restaurant['dress_code']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($restaurant['cuisine_type']): ?>
                <div class="about-item">
                    <i class="bi bi-shop"></i>
                    <div>
                        <h5>Cuisine</h5>
                        <p><?php echo sanitize($restaurant['cuisine_type']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($restaurant['opening_hours']): 
                    $hours = json_decode($restaurant['opening_hours'], true);
                ?>
                <div class="about-item">
                    <i class="bi bi-clock-history"></i>
                    <div>
                        <h5>Opening hours</h5>
                        <?php if ($hours): ?>
                            <?php foreach ($hours as $day => $time): ?>
                                <?php if (!empty($time)): ?>
                                <p><strong><?php echo ucfirst($day); ?>:</strong> <?php echo $time; ?></p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <p>Monday - Sunday: 11:00 - 22:00</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($restaurant['phone_extension'] || $restaurant['hotel_phone']): ?>
                <div class="about-item">
                    <i class="bi bi-telephone"></i>
                    <div>
                        <h5>Contact</h5>
                        <p>
                            <?php if ($restaurant['phone_extension'] && $restaurant['hotel_phone']): ?>
                                <?php echo sanitize($restaurant['hotel_phone']); ?> ext. <?php echo sanitize($restaurant['phone_extension']); ?>
                            <?php elseif ($restaurant['hotel_phone']): ?>
                                <?php echo sanitize($restaurant['hotel_phone']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="about-item">
                    <i class="bi bi-door-open"></i>
                    <div>
                        <h5>Seating capacity</h5>
                        <p><?php echo $restaurant['seating_capacity'] ?: '80'; ?> guests</p>
                    </div>
                </div>
                
                <div class="about-item">
                    <i class="bi bi-emoji-smile"></i>
                    <div>
                        <h5>Features</h5>
                        <p>
                            <?php 
                            $allFeatures = [];
                            if ($restaurant['has_outdoor_seating']) $allFeatures[] = 'Outdoor seating';
                            if ($restaurant['has_private_dining']) $allFeatures[] = 'Private dining';
                            if ($restaurant['accepts_reservations']) $allFeatures[] = 'Accepts reservations';
                            echo !empty($allFeatures) ? implode(' · ', $allFeatures) : 'Standard dining';
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($categories)): ?>
    <div id="menu" class="tab-content" style="display: none;">
        <!-- Menu Section -->
        <div class="menu-section">
            <h3 class="menu-title">Menu</h3>
            
            <?php foreach ($categories as $category): ?>
                <?php if (!empty($menu_items[$category['category_id']])): ?>
                <div class="menu-category">
                    <h4 class="menu-category-title"><?php echo sanitize($category['category_name']); ?></h4>
                    <?php if ($category['description']): ?>
                    <p class="text-muted small mb-3"><?php echo sanitize($category['description']); ?></p>
                    <?php endif; ?>
                    
                    <div class="menu-items">
                        <?php foreach ($menu_items[$category['category_id']] as $item): ?>
                        <div class="menu-item">
                            <?php if ($item['image']): ?>
                            <div class="menu-item-image">
                                <img src="/gorwanda-plus/assets/images/menu/<?php echo $item['image']; ?>" 
                                     alt="<?php echo sanitize($item['item_name']); ?>">
                            </div>
                            <?php endif; ?>
                            
                            <div class="menu-item-content">
                                <div class="menu-item-header">
                                    <span class="menu-item-name"><?php echo sanitize($item['item_name']); ?></span>
                                    <span class="menu-item-price"><?php echo formatPrice($item['price']); ?></span>
                                </div>
                                
                                <?php if ($item['description']): ?>
                                <div class="menu-item-desc"><?php echo sanitize($item['description']); ?></div>
                                <?php endif; ?>
                                
                                <div class="menu-item-tags">
                                    <?php if ($item['is_vegetarian']): ?>
                                    <span class="menu-tag vegetarian">Vegetarian</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['is_vegan']): ?>
                                    <span class="menu-tag vegetarian">Vegan</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['is_spicy']): ?>
                                    <span class="menu-tag spicy">Spicy</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['is_gluten_free']): ?>
                                    <span class="menu-tag">Gluten-free</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($item['is_signature']): ?>
                                    <span class="menu-tag signature">Signature</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($item['preparation_time']): ?>
                                <div class="text-muted small mt-1">
                                    <i class="bi bi-clock"></i> <?php echo $item['preparation_time']; ?> min
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($reviews)): ?>
    <div id="reviews" class="tab-content" style="display: none;">
        <!-- Reviews Section -->
        <div class="reviews-section">
            <div class="reviews-header">
                <h3 class="reviews-title">Guest reviews</h3>
                <a href="reviews.php?id=<?php echo $restaurant_id; ?>" class="btn btn-outline-primary btn-sm">See all reviews</a>
            </div>
            
            <?php if ($ratings['total'] > 0): ?>
            <div class="reviews-summary">
                <div class="summary-score">
                    <div class="score"><?php echo number_format($ratings['avg_rating'], 1); ?></div>
                    <div class="label">out of 10</div>
                </div>
                
                <div class="summary-bars">
                    <div class="rating-bar-item">
                        <span class="rating-bar-label">Excellent</span>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo ($ratings['excellent'] / $ratings['total']) * 100; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $ratings['excellent']; ?></span>
                    </div>
                    
                    <div class="rating-bar-item">
                        <span class="rating-bar-label">Very good</span>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo ($ratings['very_good'] / $ratings['total']) * 100; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $ratings['very_good']; ?></span>
                    </div>
                    
                    <div class="rating-bar-item">
                        <span class="rating-bar-label">Good</span>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo ($ratings['good'] / $ratings['total']) * 100; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $ratings['good']; ?></span>
                    </div>
                    
                    <div class="rating-bar-item">
                        <span class="rating-bar-label">Fair</span>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo ($ratings['fair'] / $ratings['total']) * 100; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $ratings['fair']; ?></span>
                    </div>
                    
                    <div class="rating-bar-item">
                        <span class="rating-bar-label">Poor</span>
                        <div class="rating-bar">
                            <div class="rating-bar-fill" style="width: <?php echo ($ratings['poor'] / $ratings['total']) * 100; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $ratings['poor']; ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php foreach ($reviews as $review): ?>
            <div class="review-card">
                <div class="reviewer-info">
                    <?php if ($review['profile_image']): ?>
                    <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $review['profile_image']; ?>" 
                         alt="" class="reviewer-avatar">
                    <?php else: ?>
                    <div class="reviewer-avatar">
                        <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="reviewer-details">
                        <h5><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?></h5>
                        <div class="reviewer-meta">
                            <span><?php echo $review['review_month']; ?></span>
                            <?php if ($review['is_verified']): ?>
                            <span class="text-success"><i class="bi bi-check-circle-fill"></i> Verified</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="review-rating">
                    <span class="review-score-small"><?php echo number_format($review['overall_rating'], 1); ?></span>
                    <?php if ($review['title']): ?>
                    <span class="review-title"><?php echo sanitize($review['title']); ?></span>
                    <?php endif; ?>
                </div>
                
                <?php if ($review['comment']): ?>
                <div class="review-text"><?php echo nl2br(sanitize($review['comment'])); ?></div>
                <?php endif; ?>
                
                <?php if ($review['positive_points']): ?>
                <div class="review-positive">
                    <i class="bi bi-emoji-smile"></i> <?php echo sanitize($review['positive_points']); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($review['negative_points']): ?>
                <div class="review-negative">
                    <i class="bi bi-emoji-frown"></i> <?php echo sanitize($review['negative_points']); ?>
                </div>
                <?php endif; ?>
                
                <div class="review-helpful">
                    <button class="btn-helpful" onclick="markHelpful(<?php echo $review['review_id']; ?>)">
                        <i class="bi bi-hand-thumbs-up"></i> Helpful (<?php echo number_format($review['helpful_count'] ?: 0); ?>)
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div id="location" class="tab-content" style="display: none;">
        <!-- Location Section -->
        <div class="location-section">
            <h3 class="location-title">Location</h3>
            
            <div class="location-map">
                <div class="map-placeholder">
                    <div class="text-center">
                        <i class="bi bi-map fs-1 d-block mb-2"></i>
                        <p>Map integration would go here</p>
                        <small class="text-muted">Lat: <?php echo $restaurant['latitude'] ?: '-1.9441'; ?>, Lng: <?php echo $restaurant['longitude'] ?: '30.0619'; ?></small>
                    </div>
                </div>
            </div>
            
            <div class="location-address">
                <i class="bi bi-pin-map-fill"></i>
                <div>
                    <p>
                        <?php 
                        if ($restaurant['address']) {
                            echo sanitize($restaurant['address']);
                        } elseif ($restaurant['hotel_name']) {
                            echo sanitize($restaurant['hotel_name']) . ', ' . sanitize($restaurant['location_name'] ?: 'Rwanda');
                        } else {
                            echo sanitize($restaurant['location_name'] ?: 'Rwanda');
                        }
                        ?>
                    </p>
                    <?php if ($restaurant['hotel_name']): ?>
                    <small>Located inside <?php echo sanitize($restaurant['hotel_name']); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Similar Restaurants -->
    <?php if (!empty($similar_restaurants)): ?>
    <div class="similar-section">
        <h3 class="similar-title">You might also like</h3>
        
        <div class="similar-grid">
            <?php foreach ($similar_restaurants as $similar): 
                $similarRating = $similar['avg_rating'] ?: 0;
                $similarImage = $similar['main_image'];
            ?>
            <a href="detail.php?id=<?php echo $similar['restaurant_id']; ?>" class="similar-card">
                <div class="similar-image">
                    <img src="<?php echo getImageUrl($similarImage, 'restaurant'); ?>" 
                         alt="<?php echo sanitize($similar['restaurant_name']); ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&q=60'">
                </div>
                <div class="similar-content">
                    <h4 class="similar-name"><?php echo sanitize($similar['restaurant_name']); ?></h4>
                    <div class="similar-cuisine"><?php echo sanitize($similar['cuisine_type'] ?: 'Various'); ?></div>
                    <div class="similar-footer">
                        <div class="similar-rating">
                            <?php if ($similarRating > 0): ?>
                            <span class="similar-score"><?php echo number_format($similarRating, 1); ?></span>
                            <span class="rating-count small">(<?php echo number_format($similar['review_count']); ?>)</span>
                            <?php else: ?>
                            <span class="text-muted small">New</span>
                            <?php endif; ?>
                        </div>
                        <i class="bi bi-chevron-right text-primary"></i>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Gallery Modal -->
<div class="modal fade" id="galleryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo sanitize($restaurant['restaurant_name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="galleryCarousel" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-indicators">
                        <?php foreach ($images as $index => $image): ?>
                        <button type="button" data-bs-target="#galleryCarousel" data-bs-slide-to="<?php echo $index; ?>" 
                                class="<?php echo $index === 0 ? 'active' : ''; ?>" aria-current="<?php echo $index === 0 ? 'true' : ''; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="carousel-inner">
                        <?php foreach ($images as $index => $image): ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <img src="/gorwanda-plus/assets/images/restaurants/<?php echo $image['image_path']; ?>" 
                                 class="d-block w-100" alt="<?php echo sanitize($restaurant['restaurant_name']); ?>">
                            <?php if ($image['caption']): ?>
                            <div class="carousel-caption d-none d-md-block">
                                <p><?php echo sanitize($image['caption']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#galleryCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#galleryCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(event, tabId) {
    event.preventDefault();
    
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.style.display = 'none';
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabId).style.display = 'block';
    
    // Add active class to clicked tab
    event.currentTarget.classList.add('active');
}

// Open gallery modal
function openGallery(startIndex) {
    const modal = new bootstrap.Modal(document.getElementById('galleryModal'));
    const carousel = document.getElementById('galleryCarousel');
    
    // Navigate to specific slide
    const carouselInstance = bootstrap.Carousel.getInstance(carousel) || new bootstrap.Carousel(carousel);
    carouselInstance.to(startIndex);
    
    modal.show();
}

// Mark review as helpful
function markHelpful(reviewId) {
    if (!<?php echo isLoggedIn() ? 'true' : 'false'; ?>) {
        window.location.href = '/gorwanda-plus/login.php?redirect=' + encodeURIComponent(window.location.href);
        return;
    }
    
    // AJAX call would go here
    alert('Thank you for your feedback!');
}

// Set min date for reservation
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.querySelector('input[name="date"]');
    if (dateInput) {
        const today = new Date().toISOString().split('T')[0];
        dateInput.min = today;
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>