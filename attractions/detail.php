<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

$db = getDB();

// Get attraction details with all related data
$stmt = $db->prepare("
    SELECT a.*, c.name as category_name, c.icon as category_icon,
           l.name as location_name, l.latitude, l.longitude,
           u.user_id as owner_id, u.first_name as owner_name, u.last_name as owner_last, 
           u.phone as owner_phone, u.email as owner_email, u.profile_image as owner_avatar,
           (SELECT COALESCE(AVG(overall_rating), 0) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id AND overall_rating >= 8) as positive_reviews
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE a.attraction_id = ? AND a.is_active = 1 AND a.is_verified = 1
");
$stmt->execute([$id]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

// Get pricing tiers with availability info
$stmt = $db->prepare("
    SELECT at.*,
           (SELECT COUNT(*) FROM attraction_availability 
            WHERE tier_id = at.tier_id 
            AND date >= CURDATE() 
            AND (max_bookings - bookings_made) > 0 
            AND is_blocked = 0) as available_days,
           (SELECT MIN(price_override) FROM attraction_availability 
            WHERE tier_id = at.tier_id AND price_override IS NOT NULL AND date >= CURDATE()) as special_price
    FROM attraction_tiers at
    WHERE at.attraction_id = ? AND at.is_active = 1 
    ORDER BY at.base_price ASC
");
$stmt->execute([$id]);
$tiers = $stmt->fetchAll();

// Get availability for next 60 days with pricing
$stmt = $db->prepare("
    SELECT aa.*, at.tier_name, at.base_price,
           DATEDIFF(aa.date, CURDATE()) as days_from_now,
           DAYOFWEEK(aa.date) as day_of_week
    FROM attraction_availability aa
    JOIN attraction_tiers at ON aa.tier_id = at.tier_id
    WHERE at.attraction_id = ? 
    AND aa.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    AND aa.is_blocked = 0
    ORDER BY aa.date, at.base_price
");
$stmt->execute([$id]);
$availabilityData = $stmt->fetchAll();

// Group availability by date
$availableDates = [];
$availabilityByTier = [];
foreach ($availabilityData as $avail) {
    $date = $avail['date'];
    $tierId = $avail['tier_id'];
    
    if (!isset($availableDates[$date])) {
        $availableDates[$date] = [
            'date' => $date,
            'tiers' => [],
            'min_price' => PHP_FLOAT_MAX,
            'max_price' => 0,
            'available_count' => 0
        ];
    }
    
    $availableDates[$date]['tiers'][] = $tierId;
    $availableDates[$date]['available_count']++;
    
    $price = $avail['price_override'] ?? $avail['base_price'];
    $availableDates[$date]['min_price'] = min($availableDates[$date]['min_price'], $price);
    $availableDates[$date]['max_price'] = max($availableDates[$date]['max_price'], $price);
    
    if (!isset($availabilityByTier[$tierId])) {
        $availabilityByTier[$tierId] = [];
    }
    $availabilityByTier[$tierId][$date] = $avail;
}

// Get reviews with helpful count
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           (SELECT COUNT(*) FROM reviews WHERE user_id = r.user_id) as user_review_count
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.attraction_id = ? AND r.is_active = 1
    ORDER BY r.helpful_count DESC, r.created_at DESC
    LIMIT 10
");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

// Calculate review statistics
$ratingDistribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$reviewStats = [
    'value' => 0,
    'guide' => 0,
    'experience' => 0,
    'organization' => 0,
    'safety' => 0
];

foreach ($reviews as $review) {
    $rating = ceil($review['overall_rating'] / 2);
    $ratingDistribution[$rating]++;
    
    $cats = json_decode($review['categories'] ?? '{}', true);
    foreach ($reviewStats as $key => $value) {
        if (isset($cats[$key])) {
            $reviewStats[$key] += $cats[$key];
        }
    }
}

foreach ($reviewStats as $key => $value) {
    $reviewStats[$key] = count($reviews) > 0 ? round($value / count($reviews), 1) : 0;
}

// Get similar attractions
$stmt = $db->prepare("
    SELECT a.*, l.name as location_name,
           (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price,
           (SELECT COALESCE(AVG(overall_rating), 0) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count
    FROM attractions a
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.attraction_id != ? AND a.is_active = 1 AND a.is_verified = 1
    AND (a.category_id = ? OR a.location_id = ?)
    ORDER BY a.avg_rating DESC, a.review_count DESC
    LIMIT 4
");
$stmt->execute([$id, $attraction['category_id'], $attraction['location_id']]);
$similarAttractions = $stmt->fetchAll();

// Parse JSON data
$includedItems = json_decode($attraction['included_items'] ?? '[]', true);
$excludedItems = json_decode($attraction['excluded_items'] ?? '[]', true);
$whatToBring = json_decode($attraction['what_to_bring'] ?? '[]', true);
$startTimes = json_decode($attraction['start_times'] ?? '[]', true);
$galleryImages = json_decode($attraction['gallery_images'] ?? '[]', true);
$languages = json_decode($attraction['guide_languages'] ?? '["English", "French", "Kinyarwanda"]', true);

// Add main image to gallery if not already there
if ($attraction['main_image'] && !in_array($attraction['main_image'], $galleryImages)) {
    array_unshift($galleryImages, $attraction['main_image']);
}
$galleryImages = array_filter($galleryImages);

// Get selected parameters
$selectedDate = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
$selectedTier = intval($_GET['tier'] ?? ($tiers[0]['tier_id'] ?? 0));
$participants = intval($_GET['participants'] ?? 1);
$startTime = $_GET['time'] ?? ($startTimes[0] ?? '09:00');

// Validate selected date is available
if (!isset($availableDates[$selectedDate]) && !empty($availableDates)) {
    $selectedDate = array_key_first($availableDates);
}

$pageTitle = $attraction['attraction_name'] . ' - ' . ($attraction['category_name'] ?? 'Experience');
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
/* Modern Attraction Detail Page CSS - same as before but with enhancements */
:root {
    --primary: #0066ff;
    --primary-dark: #003b95;
    --primary-light: #f0f4ff;
    --accent: #ffb700;
    --bg: #ffffff;
    --bg-secondary: #f5f5f5;
    --text: #1a1a1a;
    --text-secondary: #595959;
    --text-muted: #a5a5a5;
    --border: #e7e7e7;
    --success: #008009;
    --warning: #ff8c00;
    --danger: #e21111;
    --purple: #9333ea;
    --teal: #14b8a6;
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.attraction-detail-page {
    background: var(--bg-secondary);
    min-height: calc(100vh - 64px);
    padding: 32px 0;
}

/* Breadcrumb */
.breadcrumb-bar {
    margin-bottom: 24px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.breadcrumb-link {
    color: var(--primary);
    text-decoration: none;
    transition: var(--transition);
}

.breadcrumb-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

/* Header Card */
.attraction-header-card {
    background: white;
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    animation: fadeInUp 0.5s ease;
}

.attraction-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    line-height: 1.2;
}

.attraction-badges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.attraction-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: var(--primary-light);
    color: var(--primary);
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.attraction-badge i {
    font-size: 0.875rem;
}

.category-badge {
    background: var(--purple);
    color: white;
}

.difficulty-badge {
    background: var(--warning);
    color: white;
}

.attraction-rating {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-dark);
    color: white;
    padding: 4px 10px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 0.875rem;
}

.attraction-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.attraction-meta i {
    color: var(--primary);
    margin-right: 4px;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    margin-left: auto;
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: var(--transition);
}

.action-btn:hover {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary);
    transform: translateY(-2px);
}

.action-btn.filled {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

.action-btn.filled:hover {
    background: var(--primary-dark);
}

/* Main Grid */
.attraction-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
}

/* Gallery Section */
.attraction-gallery {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 24px;
    animation: fadeInUp 0.5s ease 0.1s both;
}

.main-image {
    position: relative;
    height: 400px;
    overflow: hidden;
    cursor: pointer;
}

.main-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.main-image:hover img {
    transform: scale(1.02);
}

.image-badge {
    position: absolute;
    bottom: 16px;
    right: 16px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 8px 16px;
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    backdrop-filter: blur(4px);
    cursor: pointer;
    transition: var(--transition);
}

.image-badge:hover {
    background: rgba(0,0,0,0.9);
}

.thumbnail-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    padding: 4px;
}

.thumbnail-item {
    height: 80px;
    cursor: pointer;
    overflow: hidden;
    opacity: 0.7;
    transition: var(--transition);
    position: relative;
}

.thumbnail-item:hover,
.thumbnail-item.active {
    opacity: 1;
}

.thumbnail-item.active::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    border: 2px solid var(--primary);
    pointer-events: none;
}

.thumbnail-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Lightbox Modal */
.lightbox-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.9);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.lightbox-modal.active {
    display: flex;
}

.lightbox-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
}

.lightbox-content img {
    max-width: 100%;
    max-height: 90vh;
    object-fit: contain;
}

.lightbox-close {
    position: absolute;
    top: -40px;
    right: 0;
    color: white;
    font-size: 2rem;
    cursor: pointer;
    background: none;
    border: none;
}

.lightbox-nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    color: white;
    font-size: 2rem;
    cursor: pointer;
    background: rgba(0,0,0,0.5);
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.lightbox-nav:hover {
    background: rgba(0,0,0,0.8);
}

.lightbox-prev {
    left: -60px;
}

.lightbox-next {
    right: -60px;
}

/* Main Content Cards */
.attraction-content-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 24px;
    transition: var(--transition);
}

.attraction-content-card:last-child {
    margin-bottom: 0;
}

.card-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: var(--primary);
    font-size: 1.25rem;
}

/* Quick Info Grid */
.quick-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 16px;
    text-align: center;
    transition: var(--transition);
}

.info-card:hover {
    transform: translateY(-2px);
    background: var(--primary-light);
}

.info-icon {
    font-size: 1.5rem;
    color: var(--primary);
    margin-bottom: 8px;
}

.info-value {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 4px;
}

.info-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Description */
.attraction-description {
    font-size: 0.9375rem;
    line-height: 1.7;
    color: var(--text-secondary);
    margin-bottom: 24px;
}

/* What's Included */
.included-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.included-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: var(--transition);
}

.included-item:hover {
    background: var(--primary-light);
}

.included-item i {
    color: var(--success);
    font-size: 1.125rem;
}

.included-item span {
    font-size: 0.9375rem;
    color: var(--text);
}

.excluded-item i {
    color: var(--danger);
}

/* What to Bring */
.bring-list {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.bring-tag {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
}

.bring-tag:hover {
    background: var(--primary-light);
    color: var(--primary);
}

.bring-tag i {
    color: var(--warning);
}

/* Start Times */
.start-times {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.time-tag {
    background: var(--primary-light);
    color: var(--primary);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 0.875rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
    cursor: pointer;
}

.time-tag:hover,
.time-tag.selected {
    background: var(--primary);
    color: white;
}

.time-tag i {
    font-size: 1rem;
}

/* Meeting Point */
.meeting-point {
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 16px;
}

.meeting-point i {
    font-size: 2rem;
    color: var(--primary);
}

.meeting-point-content h4 {
    font-size: 0.9375rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.meeting-point-content p {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* Languages */
.language-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.language-tag {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.language-tag i {
    color: var(--primary);
}

/* Pricing Tiers */
.tiers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.tier-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: var(--transition);
    position: relative;
    cursor: pointer;
}

.tier-card:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.tier-card.selected {
    border-color: var(--primary);
    background: var(--primary-light);
}

.tier-name {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--text);
}

.tier-description {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: 16px;
    line-height: 1.5;
}

.tier-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--success);
    margin-bottom: 16px;
}

.tier-price small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--text-secondary);
}

.tier-availability {
    display: inline-block;
    padding: 4px 8px;
    background: #e6f4ea;
    color: var(--success);
    border-radius: 20px;
    font-size: 0.6875rem;
    font-weight: 600;
    margin-bottom: 12px;
}

.tier-availability i {
    margin-right: 4px;
}

.tier-special {
    position: absolute;
    top: -8px;
    right: 12px;
    background: var(--warning);
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.6875rem;
    font-weight: 600;
    box-shadow: var(--shadow-sm);
}

.tier-inclusions {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}

.tier-inclusion-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.tier-inclusion-item i {
    color: var(--success);
    font-size: 0.875rem;
}

/* Availability Calendar */
.availability-section {
    margin-top: 24px;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.calendar-nav {
    display: flex;
    gap: 8px;
}

.calendar-nav-btn {
    width: 36px;
    height: 36px;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}

.calendar-nav-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    text-align: center;
}

.calendar-weekday {
    padding: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
}

.calendar-day {
    padding: 12px 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    min-height: 70px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.calendar-day:hover {
    border-color: var(--primary);
    background: var(--primary-light);
    transform: translateY(-2px);
}

.calendar-day.available {
    background: #e6f4ea;
    border-color: var(--success);
}

.calendar-day.selected {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.calendar-day.selected .day-price {
    color: white;
}

.calendar-day.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: var(--bg-secondary);
}

.calendar-day.other-month {
    opacity: 0.4;
    background: var(--bg-secondary);
}

.day-number {
    font-weight: 700;
    margin-bottom: 4px;
}

.day-price {
    font-size: 0.7rem;
    color: var(--success);
    font-weight: 600;
    margin-bottom: 2px;
}

.day-tiers {
    font-size: 0.6rem;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 2px;
}

/* Tier Selector for Calendar */
.tier-selector {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.tier-filter-btn {
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: white;
    font-size: 0.75rem;
    cursor: pointer;
    transition: var(--transition);
}

.tier-filter-btn:hover,
.tier-filter-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* Sidebar */
.attraction-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.booking-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-lg);
}

.price-display {
    text-align: center;
    margin-bottom: 24px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--border);
}

.price-amount {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1;
    margin-bottom: 4px;
}

.price-period {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

/* Booking Form */
.booking-form .form-group {
    margin-bottom: 16px;
}

.booking-form label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    margin-bottom: 6px;
}

.booking-form select,
.booking-form input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: var(--transition);
}

.booking-form select:focus,
.booking-form input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

/* Tier Select Dropdown */
.tier-select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    background: white;
    cursor: pointer;
}

.tier-select option {
    padding: 8px;
}

/* Participant Counter */
.participant-counter {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
}

.counter-controls {
    display: flex;
    align-items: center;
    gap: 12px;
}

.counter-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--primary);
    background: white;
    color: var(--primary);
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.counter-btn:hover:not(:disabled) {
    background: var(--primary);
    color: white;
}

.counter-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.counter-value {
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}

/* Price Breakdown */
.price-breakdown {
    margin: 20px 0;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.price-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.875rem;
}

.price-row.total {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 2px solid var(--border);
    font-weight: 700;
    font-size: 1rem;
}

.price-label {
    color: var(--text-secondary);
}

.price-value {
    font-weight: 600;
}

.price-value.total {
    color: var(--primary);
}

/* Book Button */
.btn-book {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    margin: 20px 0 12px;
}

.btn-book:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-book:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.security-note {
    text-align: center;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.security-note i {
    color: var(--success);
}

/* Operator Card */
.operator-card {
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 20px;
    transition: var(--transition);
    margin-bottom: 16px;
}

.operator-card:hover {
    box-shadow: var(--shadow-md);
}

.operator-header {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}

.operator-avatar {
    width: 60px;
    height: 60px;
    background: var(--primary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--primary);
    overflow: hidden;
}

.operator-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.operator-info h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.operator-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.operator-rating i {
    color: var(--accent);
}

.verified-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--success);
    color: white;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 0.625rem;
    font-weight: 600;
    margin-left: 8px;
}

.operator-contact {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.operator-contact div {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.operator-contact i {
    color: var(--primary);
    width: 20px;
}

/* Message Button */
.btn-message {
    width: 100%;
    padding: 12px;
    background: white;
    color: var(--primary);
    border: 1px solid var(--primary);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 16px;
}

.btn-message:hover {
    background: var(--primary-light);
}

/* Reviews Section */
.reviews-section {
    margin-top: 24px;
}

.reviews-summary {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 32px;
    margin-bottom: 24px;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
}

.review-score-large {
    text-align: center;
    min-width: 100px;
}

.score-number {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--primary-dark);
    line-height: 1;
    margin-bottom: 4px;
}

.score-label {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.score-count {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.review-categories {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.category-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.875rem;
}

.category-name {
    color: var(--text-secondary);
}

.category-score {
    font-weight: 600;
    color: var(--text);
}

.review-card {
    padding: 20px 0;
    border-bottom: 1px solid var(--border);
}

.review-card:last-child {
    border-bottom: none;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.reviewer-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.reviewer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
    overflow: hidden;
}

.reviewer-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.reviewer-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.review-date {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.review-rating {
    display: flex;
    gap: 2px;
    color: var(--accent);
}

.review-text {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: 8px;
}

.review-title {
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 1rem;
}

.review-helpful {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-top: 12px;
}

.helpful-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
}

.helpful-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
}

.helpful-btn i {
    color: var(--success);
}

/* Similar Attractions */
.similar-section {
    margin-top: 48px;
}

.similar-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.similar-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    text-decoration: none;
    color: var(--text);
    transition: var(--transition);
}

.similar-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
}

.similar-image {
    height: 140px;
    background: var(--bg-secondary);
    overflow: hidden;
}

.similar-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.similar-card:hover .similar-image img {
    transform: scale(1.05);
}

.similar-content {
    padding: 12px;
}

.similar-title {
    font-weight: 700;
    font-size: 0.9375rem;
    margin-bottom: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.similar-category {
    font-size: 0.6875rem;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.similar-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.similar-rating {
    background: var(--primary-dark);
    color: white;
    padding: 2px 6px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 0.6875rem;
}

.similar-price {
    text-align: right;
}

.similar-price-value {
    font-weight: 700;
    color: var(--success);
    font-size: 0.875rem;
}

.similar-price-unit {
    font-size: 0.5625rem;
    color: var(--text-secondary);
}

/* Message Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    background: var(--bg-secondary);
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: var(--danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--bg-secondary);
}
/* Gallery Section - Copy from restaurant page */
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

/* Responsive */
@media (max-width: 768px) {
    .gallery-grid {
        grid-template-columns: 1fr;
        grid-template-rows: auto;
    }
    
    .gallery-main {
        grid-row: auto;
        height: 250px;
    }
}

/* Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fadeInUp {
    animation: fadeInUp 0.5s ease forwards;
}

.delay-1 { animation-delay: 0.1s; }
.delay-2 { animation-delay: 0.2s; }
.delay-3 { animation-delay: 0.3s; }

/* Responsive */
@media (max-width: 1200px) {
    .similar-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 992px) {
    .attraction-grid {
        grid-template-columns: 1fr;
    }
    
    .attraction-sidebar {
        position: static;
    }
    
    .quick-info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .similar-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .reviews-summary {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .lightbox-prev {
        left: 10px;
    }
    
    .lightbox-next {
        right: 10px;
    }
}

@media (max-width: 768px) {
    .attraction-title {
        font-size: 1.5rem;
    }
    
    .attraction-badges {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        margin-left: 0;
        width: 100%;
        justify-content: space-between;
    }
    
    .quick-info-grid,
    .included-grid,
    .similar-grid {
        grid-template-columns: 1fr;
    }
    
    .main-image {
        height: 300px;
    }
    
    .thumbnail-item {
        height: 60px;
    }
    
    .calendar-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .calendar-weekday {
        display: none;
    }
}
</style>

<div class="attraction-detail-page">
    <div class="container">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-bar">
            <a href="/gorwanda-plus/" class="breadcrumb-link">Home</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <a href="/gorwanda-plus/?type=attractions" class="breadcrumb-link">Experiences</a>
            <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
            <span class="text-secondary"><?php echo sanitize($attraction['attraction_name']); ?></span>
        </nav>

        <!-- Header Card -->
        <div class="attraction-header-card">
            <div class="d-flex flex-wrap align-items-start justify-content-between">
                <div>
                    <h1 class="attraction-title"><?php echo sanitize($attraction['attraction_name']); ?></h1>
                    
                    <div class="attraction-badges">
                        <span class="attraction-badge category-badge">
                            <i class="bi <?php echo str_replace(['fa-', 'fas ', 'far '], '', $attraction['category_icon'] ?? 'bi-star'); ?>"></i>
                            <?php echo sanitize($attraction['category_name'] ?? 'Experience'); ?>
                        </span>
                        
                        <?php if ($attraction['difficulty_level']): ?>
                        <span class="attraction-badge difficulty-badge">
                            <i class="bi bi-bar-chart-steps"></i>
                            <?php echo ucfirst($attraction['difficulty_level']); ?>
                        </span>
                        <?php endif; ?>
                        
                        <span class="attraction-badge">
                            <i class="bi bi-clock"></i>
                            <?php echo $attraction['duration_minutes'] ? floor($attraction['duration_minutes']/60) . 'h ' . ($attraction['duration_minutes'] % 60) . 'min' : 'Flexible'; ?>
                        </span>
                        
                        <span class="attraction-badge">
                            <i class="bi bi-people"></i>
                            Max <?php echo $attraction['max_group_size'] ?? '10'; ?> people
                        </span>
                        
                        <?php if ($attraction['min_age']): ?>
                        <span class="attraction-badge">
                            <i class="bi bi-person-badge"></i>
                            Min. age <?php echo $attraction['min_age']; ?>+
                        </span>
                        <?php endif; ?>
                        
                        <?php if ($attraction['avg_rating'] > 0): ?>
                        <span class="attraction-rating">
                            <i class="bi bi-star-fill"></i> <?php echo number_format($attraction['avg_rating'], 1); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="attraction-meta">
                        <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($attraction['location_name'] ?? $attraction['address'] ?? 'Rwanda'); ?></span>
                        <?php if ($attraction['review_count'] > 0): ?>
                        <span><i class="bi bi-chat-text"></i> <?php echo $attraction['review_count']; ?> reviews</span>
                        <?php endif; ?>
                        <span><i class="bi bi-check-circle-fill text-success"></i> Instant confirmation</span>
                        <span><i class="bi bi-arrow-repeat"></i> Free cancellation</span>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button class="action-btn" onclick="shareAttraction()">
                        <i class="bi bi-share"></i>
                        <span class="d-none d-sm-inline">Share</span>
                    </button>
                    <button class="action-btn" onclick="toggleSave()" id="saveBtn">
                        <i class="bi bi-heart"></i>
                        <span class="d-none d-sm-inline">Save</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="attraction-grid">
            <!-- Left Column - Main Content -->
            <div class="attraction-main-content">
                <!-- Gallery -->
<!-- Gallery Section - Using the same pattern as restaurants -->
<?php 
// Get gallery images - similar to how restaurants handle it
$galleryImages = [];

// Add main image if exists
if (!empty($attraction['main_image'])) {
    $galleryImages[] = $attraction['main_image'];
}

// Add gallery images from JSON
$jsonGallery = json_decode($attraction['gallery_images'] ?? '[]', true);
if (is_array($jsonGallery) && !empty($jsonGallery)) {
    foreach ($jsonGallery as $img) {
        if (!empty($img) && !in_array($img, $galleryImages)) {
            $galleryImages[] = $img;
        }
    }
}

// If still no images, use a placeholder image
if (empty($galleryImages)) {
    // Try to get images from attraction_tiers (some may have images)
    $stmt = $db->prepare("SELECT images FROM attraction_tiers WHERE attraction_id = ? AND images IS NOT NULL LIMIT 1");
    $stmt->execute([$id]);
    $tierImages = $stmt->fetchColumn();
    if (!empty($tierImages)) {
        $tierImgArray = json_decode($tierImages, true);
        if (is_array($tierImgArray)) {
            $galleryImages = array_merge($galleryImages, $tierImgArray);
        }
    }
}

// Final fallback - use unsplash placeholder
if (empty($galleryImages)) {
    $galleryImages = ['placeholder.jpg'];
}
?>

<!-- Image Gallery - Exact same pattern as restaurants -->
<div class="gallery-section">
    <div class="gallery-grid">
        <?php foreach ($galleryImages as $index => $imageName): ?>
            <?php if ($index == 0): ?>
            <div class="gallery-main gallery-item" onclick="openGallery(0)">
                <img src="<?php echo getImageUrl($imageName, 'attraction'); ?>" 
                     alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                     onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=800&q=80'">
            </div>
            <?php elseif ($index < 4): ?>
            <div class="gallery-item" onclick="openGallery(<?php echo $index; ?>)">
                <img src="<?php echo getImageUrl($imageName, 'attraction'); ?>" 
                     alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                     onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60'">
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <?php if (count($galleryImages) > 4): ?>
        <div class="gallery-item" onclick="openGallery(4)">
            <img src="<?php echo getImageUrl($galleryImages[4], 'attraction'); ?>" 
                 alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                 onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60'">
            <div class="gallery-overlay">
                <i class="bi bi-images"></i> +<?php echo count($galleryImages) - 4; ?> more
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Gallery Modal - Using Bootstrap Carousel like restaurants -->
<div class="modal fade" id="galleryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo sanitize($attraction['attraction_name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="galleryCarousel" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-indicators">
                        <?php foreach ($galleryImages as $index => $imageName): ?>
                        <button type="button" data-bs-target="#galleryCarousel" data-bs-slide-to="<?php echo $index; ?>" 
                                class="<?php echo $index === 0 ? 'active' : ''; ?>" 
                                aria-current="<?php echo $index === 0 ? 'true' : ''; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="carousel-inner">
                        <?php foreach ($galleryImages as $index => $imageName): ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <img src="<?php echo getImageUrl($imageName, 'attraction'); ?>" 
                                 class="d-block w-100" 
                                 alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                                 onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=800&q=80'">
                            <div class="carousel-caption d-none d-md-block">
                                <p><?php echo sanitize($attraction['attraction_name']); ?></p>
                            </div>
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

                <!-- Quick Info Cards -->
                <div class="attraction-content-card fadeInUp delay-1">
                    <h3 class="card-title">
                        <i class="bi bi-info-circle"></i>
                        Quick Overview
                    </h3>
                    
                    <div class="quick-info-grid">
                        <div class="info-card">
                            <div class="info-icon"><i class="bi bi-clock"></i></div>
                            <div class="info-value"><?php echo $attraction['duration_minutes'] ? floor($attraction['duration_minutes']/60) . 'h' : 'Flexible'; ?></div>
                            <div class="info-label">Duration</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon"><i class="bi bi-people"></i></div>
                            <div class="info-value"><?php echo $attraction['max_group_size'] ?? '10'; ?></div>
                            <div class="info-label">Max Group</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon"><i class="bi bi-person-badge"></i></div>
                            <div class="info-value"><?php echo $attraction['min_age'] ?? '0'; ?>+</div>
                            <div class="info-label">Min Age</div>
                        </div>
                        
                        <div class="info-card">
                            <div class="info-icon"><i class="bi bi-translate"></i></div>
                            <div class="info-value"><?php echo count($languages); ?></div>                            <div class="info-label">Languages</div>
                        </div>
                    </div>
                    
<!-- Add this validation code right before your language tags section -->
<?php 
// FIX: Ensure $languages is a clean array of strings
$cleanLanguages = [];
if (!empty($languages) && is_array($languages)) {
    foreach ($languages as $lang) {
        if (is_string($lang)) {
            $cleanLanguages[] = $lang;
        } elseif (is_array($lang)) {
            // If it's an array, take the first value or skip
            $firstValue = reset($lang);
            if (is_string($firstValue)) {
                $cleanLanguages[] = $firstValue;
            }
        }
    }
}
$languages = $cleanLanguages;
?>

<!-- Then your existing language tags -->
<div class="language-tags">
    <?php foreach ($languages as $lang): ?>
    <span class="language-tag"><i class="bi bi-check-circle-fill"></i> <?php echo $lang; ?></span>
    <?php endforeach; ?>
</div>
                </div>

                <!-- Description -->
                <div class="attraction-content-card fadeInUp delay-1">
                    <h3 class="card-title">
                        <i class="bi bi-file-text"></i>
                        About this experience
                    </h3>
                    
                    <div class="attraction-description">
                        <?php echo nl2br(sanitize($attraction['description'])); ?>
                    </div>
                </div>

                <!-- What's Included / Not Included -->
                <?php if (!empty($includedItems) || !empty($excludedItems)): ?>
                <div class="attraction-content-card fadeInUp delay-2">
                    <div class="row">
                        <?php if (!empty($includedItems)): ?>
                        <div class="col-md-6">
                            <h3 class="card-title" style="margin-bottom: 16px;">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                Included
                            </h3>
                            <div class="included-grid">
                                <?php foreach ($includedItems as $item): ?>
                                <div class="included-item">
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                    <span><?php echo sanitize($item); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($excludedItems)): ?>
                        <div class="col-md-6">
                            <h3 class="card-title" style="margin-bottom: 16px;">
                                <i class="bi bi-x-circle-fill text-danger"></i>
                                Not Included
                            </h3>
                            <div class="included-grid">
                                <?php foreach ($excludedItems as $item): ?>
                                <div class="included-item excluded-item">
                                    <i class="bi bi-x-circle-fill text-danger"></i>
                                    <span><?php echo sanitize($item); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- What to Bring -->
                <?php if (!empty($whatToBring)): ?>
                <div class="attraction-content-card fadeInUp delay-2">
                    <h3 class="card-title">
                        <i class="bi bi-backpack"></i>
                        What to bring
                    </h3>
                    
                    <div class="bring-list">
                        <?php foreach ($whatToBring as $item): ?>
                        <span class="bring-tag">
                            <i class="bi bi-check-lg"></i>
                            <?php echo sanitize($item); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Start Times -->
                <?php if (!empty($startTimes)): ?>
                <div class="attraction-content-card fadeInUp delay-2">
                    <h3 class="card-title">
                        <i class="bi bi-clock-history"></i>
                        Available start times
                    </h3>
                    
                    <div class="start-times">
                        <?php foreach ($startTimes as $time): ?>
                        <span class="time-tag <?php echo $time == $startTime ? 'selected' : ''; ?>" 
                              onclick="selectStartTime('<?php echo $time; ?>')">
                            <i class="bi bi-clock"></i>
                            <?php echo date('h:i A', strtotime($time)); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Meeting Point -->
                <?php if ($attraction['meeting_point']): ?>
                <div class="attraction-content-card fadeInUp delay-3">
                    <h3 class="card-title">
                        <i class="bi bi-geo-alt"></i>
                        Meeting point
                    </h3>
                    
                    <div class="meeting-point">
                        <i class="bi bi-pin-map-fill"></i>
                        <div class="meeting-point-content">
                            <h4>Meeting location</h4>
                            <p><?php echo nl2br(sanitize($attraction['meeting_point'])); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pricing Tiers -->
                <?php if (!empty($tiers)): ?>
                <div class="attraction-content-card" id="pricing">
                    <h3 class="card-title">
                        <i class="bi bi-tags"></i>
                        Pricing options
                    </h3>
                    
                    <div class="tiers-grid">
                        <?php foreach ($tiers as $tier): 
                            $tierInclusions = json_decode($tier['inclusions'] ?? '[]', true);
                            $hasSpecial = $tier['special_price'] && $tier['special_price'] < $tier['base_price'];
                        ?>
                        <div class="tier-card <?php echo $tier['tier_id'] == $selectedTier ? 'selected' : ''; ?>" 
                             onclick="selectTier(<?php echo $tier['tier_id']; ?>, <?php echo $tier['base_price']; ?>, this)">
                            <?php if ($hasSpecial): ?>
                            <div class="tier-special">Special Price</div>
                            <?php endif; ?>
                            
                            <div class="tier-name"><?php echo sanitize($tier['tier_name'] ?? 'Standard'); ?></div>
                            
                            <?php if ($tier['description']): ?>
                            <div class="tier-description"><?php echo sanitize($tier['description']); ?></div>
                            <?php endif; ?>
                            
                            <?php if ($tier['available_days'] > 0): ?>
                            <div class="tier-availability">
                                <i class="bi bi-calendar-check"></i> Available next <?php echo $tier['available_days']; ?> days
                            </div>
                            <?php endif; ?>
                            
                            <div class="tier-price">
                                <?php if ($hasSpecial): ?>
                                <small><s><?php echo formatPrice($tier['base_price']); ?></s></small><br>
                                <?php echo formatPrice($tier['special_price']); ?> 
                                <?php else: ?>
                                <?php echo formatPrice($tier['base_price']); ?>
                                <?php endif; ?>
                                <small>per <?php echo $tier['price_type'] == 'per_group' ? 'group' : 'person'; ?></small>
                            </div>
                            
                            <?php if (!empty($tierInclusions)): ?>
                            <div class="tier-inclusions">
                                <?php foreach ($tierInclusions as $inclusion): ?>
                                <div class="tier-inclusion-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <?php echo sanitize($inclusion); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Availability Calendar -->
                <?php if (!empty($availableDates)): ?>
                <div class="attraction-content-card">
                    <h3 class="card-title">
                        <i class="bi bi-calendar-check"></i>
                        Availability calendar
                    </h3>
                    
                    <!-- Tier Filter -->
                    <div class="tier-selector">
                        <button class="tier-filter-btn active" onclick="filterCalendar('all')">All Tiers</button>
                        <?php foreach ($tiers as $tier): ?>
                        <button class="tier-filter-btn" onclick="filterCalendar(<?php echo $tier['tier_id']; ?>)">
                            <?php echo sanitize($tier['tier_name']); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="availability-section">
                        <div class="calendar-header">
                            <span class="fw-bold">Next 60 days</span>
                            <div class="calendar-nav">
                                <button class="calendar-nav-btn" onclick="scrollCalendar(-1)">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <button class="calendar-nav-btn" onclick="scrollCalendar(1)">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="calendar-grid" id="calendarGrid">
                            <?php
                            $today = new DateTime();
                            $startDay = clone $today;
                            
                            // Add weekday headers
                            $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            foreach ($weekdays as $day):
                            ?>
                            <div class="calendar-weekday"><?php echo $day; ?></div>
                            <?php endforeach; ?>
                            
                            <?php
                            // Add empty cells for days before start of month
                            $firstDayOfMonth = new DateTime($today->format('Y-m-01'));
                            $startOffset = ($firstDayOfMonth->format('N') - 1) % 7;
                            for ($i = 0; $i < $startOffset; $i++):
                            ?>
                            <div class="calendar-day other-month"></div>
                            <?php endfor; ?>
                            
                            <?php
                            for ($i = 0; $i < 60; $i++):
                                $date = clone $today;
                                $date->modify("+$i days");
                                $dateStr = $date->format('Y-m-d');
                                $dayNum = $date->format('j');
                                $isAvailable = isset($availableDates[$dateStr]);
                                $minPrice = $isAvailable ? $availableDates[$dateStr]['min_price'] : null;
                                $tierCount = $isAvailable ? $availableDates[$dateStr]['available_count'] : 0;
                                
                                $class = 'calendar-day';
                                if (!$isAvailable) $class .= ' disabled';
                                else $class .= ' available';
                                if ($dateStr == $selectedDate) $class .= ' selected';
                            ?>
                            <div class="<?php echo $class; ?>" 
                                 data-date="<?php echo $dateStr; ?>"
                                 data-available="<?php echo $isAvailable ? 'true' : 'false'; ?>"
                                 data-tiers="<?php echo $tierCount; ?>"
                                 onclick="<?php echo $isAvailable ? "selectCalendarDate('$dateStr')" : ''; ?>">
                                <div class="day-number"><?php echo $dayNum; ?></div>
                                <?php if ($isAvailable && $minPrice): ?>
                                <div class="day-price"><?php echo formatPrice($minPrice); ?></div>
                                <div class="day-tiers">
                                    <i class="bi bi-tag"></i> <?php echo $tierCount; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Reviews -->
                <?php if (!empty($reviews)): ?>
                <div class="attraction-content-card" id="reviews">
                    <h3 class="card-title">
                        <i class="bi bi-star-fill text-warning"></i>
                        Guest reviews
                    </h3>
                    
                    <div class="reviews-summary">
                        <div class="review-score-large">
                            <div class="score-number"><?php echo number_format($attraction['avg_rating'], 1); ?></div>
                            <div class="score-label"><?php echo getReviewLabel($attraction['avg_rating'])[0]; ?></div>
                            <div class="score-count"><?php echo $attraction['review_count']; ?> reviews</div>
                        </div>
                        
                        <div class="review-categories">
                            <?php foreach ($reviewStats as $key => $value): ?>
                            <div class="category-item">
                                <span class="category-name"><?php echo ucfirst($key); ?></span>
                                <span class="category-score"><?php echo $value; ?>/10</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <?php foreach ($reviews as $review): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div class="reviewer-info">
                                <div class="reviewer-avatar">
                                    <?php if ($review['profile_image']): ?>
                                    <img src="<?php echo getImageUrl($review['profile_image'], 'profile'); ?>" alt="">
                                    <?php else: ?>
                                    <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="reviewer-name">
                                        <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?>
                                    </div>
                                    <div class="review-date"><?php echo timeAgo($review['created_at']); ?></div>
                                </div>
                            </div>
                            <div class="review-rating">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>" style="font-size: 0.75rem;"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <?php if ($review['title']): ?>
                        <div class="review-title"><?php echo sanitize($review['title']); ?></div>
                        <?php endif; ?>
                        
                        <div class="review-text">
                            <?php echo sanitize($review['comment']); ?>
                        </div>
                        
                        <div class="review-helpful">
                            <button class="helpful-btn" onclick="markHelpful(<?php echo $review['review_id']; ?>)">
                                <i class="bi bi-hand-thumbs-up"></i> Helpful (<?php echo $review['helpful_count']; ?>)
                            </button>
                            <span class="text-secondary" style="font-size: 0.75rem;">
                                <?php echo $review['user_review_count']; ?> reviews
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Sticky Booking Sidebar -->
            <div class="attraction-sidebar">
                <div class="booking-card">
                    <div class="price-display">
                        <div class="price-amount" id="sidebarPrice">
                            <?php 
                            $defaultPrice = $tiers[0]['base_price'] ?? 0;
                            echo formatPrice($defaultPrice);
                            ?>
                        </div>
                        <div class="price-period">per person</div>
                    </div>

                    <!-- FIXED BOOKING FORM - Using only select for tier_id, no duplicate hidden input -->
                    <form class="booking-form" id="bookingForm" action="booking.php" method="GET">
                        <input type="hidden" name="attraction_id" value="<?php echo $attraction['attraction_id']; ?>">
                        <input type="hidden" name="start_time" id="selectedStartTime" value="<?php echo $startTime; ?>">
                        
                        <div class="form-group">
                            <label>Select Date</label>
                            <input type="date" name="date" id="selectedDate" value="<?php echo $selectedDate; ?>" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <?php if (!empty($tiers)): ?>
                        <div class="form-group">
                            <label>Select Tier</label>
                            <select name="tier_id" id="tierSelect" class="tier-select" onchange="updatePriceFromSelect(this)">
                                <?php foreach ($tiers as $tier): ?>
                                <option value="<?php echo $tier['tier_id']; ?>" 
                                        data-price="<?php echo $tier['base_price']; ?>"
                                        <?php echo $tier['tier_id'] == $selectedTier ? 'selected' : ''; ?>>
                                    <?php echo sanitize($tier['tier_name']); ?> - <?php echo formatPrice($tier['base_price']); ?>/<?php echo $tier['price_type'] == 'per_group' ? 'group' : 'person'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label>Number of Participants</label>
                            <div class="participant-counter">
                                <span>Participants</span>
                                <div class="counter-controls">
                                    <button type="button" class="counter-btn" onclick="changeParticipants(-1)" id="decreaseParticipants" <?php echo $participants <= 1 ? 'disabled' : ''; ?>>-</button>
                                    <span class="counter-value" id="participantCount"><?php echo $participants; ?></span>
                                    <button type="button" class="counter-btn" onclick="changeParticipants(1)" id="increaseParticipants">+</button>
                                </div>
                            </div>
                            <input type="hidden" name="participants" id="participantsInput" value="<?php echo $participants; ?>">
                        </div>

                        <!-- Price Breakdown -->
                        <div class="price-breakdown" id="priceBreakdown">
                            <div class="price-row">
                                <span class="price-label">Price per person</span>
                                <span class="price-value" id="pricePerPerson"><?php echo formatPrice($defaultPrice); ?></span>
                            </div>
                            <div class="price-row">
                                <span class="price-label">Participants</span>
                                <span class="price-value" id="participantCountDisplay"><?php echo $participants; ?></span>
                            </div>
                            <div class="price-row total">
                                <span class="price-label">Total</span>
                                <span class="price-value total" id="totalPrice"><?php echo formatPrice($defaultPrice * $participants); ?></span>
                            </div>
                        </div>

                        <button type="submit" class="btn-book" id="bookButton" <?php echo empty($tiers) ? 'disabled' : ''; ?>>
                            Book Now
                        </button>
                        
                        <div class="security-note">
                            <i class="bi bi-shield-check"></i>
                            <span>Free cancellation • Secure payment • No booking fees</span>
                        </div>
                    </form>
                </div>

                <!-- Operator Card with Message Button -->
                <div class="operator-card">
                    <div class="operator-header">
                        <div class="operator-avatar">
                            <?php if ($attraction['owner_avatar']): ?>
                            <img src="<?php echo getImageUrl($attraction['owner_avatar'], 'profile'); ?>" alt="">
                            <?php else: ?>
                            <i class="bi bi-person-circle"></i>
                            <?php endif; ?>
                        </div>
                        <div class="operator-info">
                            <h4><?php echo sanitize($attraction['owner_name'] ?? 'Experience Provider'); ?></h4>
                            <div class="operator-rating">
                                <i class="bi bi-star-fill"></i>
                                <span>4.8 (128 reviews)</span>
                                <span class="verified-badge"><i class="bi bi-patch-check-fill"></i> Verified</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="operator-contact">
                        <div><i class="bi bi-telephone"></i> <?php echo $attraction['owner_phone'] ?? '+250 788 123 456'; ?></div>
                        <div><i class="bi bi-envelope"></i> <?php echo $attraction['owner_email'] ?? 'info@provider.com'; ?></div>
                        <div><i class="bi bi-chat-text"></i> Response within 1 hour</div>
                    </div>
                    
                    <button class="btn-message" onclick="openMessageModal(<?php echo $attraction['owner_id']; ?>)">
                        <i class="bi bi-chat-dots"></i>
                        Message Provider
                    </button>
                </div>

                <!-- Cancellation Policy -->
                <div class="operator-card" style="margin-top: 16px;">
                    <h4 style="font-size: 0.9375rem; font-weight: 700; margin-bottom: 12px;">
                        <i class="bi bi-calendar-x me-2" style="color: var(--success);"></i>
                        Cancellation policy
                    </h4>
                    <p style="font-size: 0.8125rem; color: var(--text-secondary); line-height: 1.6;">
                        <?php echo $attraction['cancellation_policy'] ?? 'Free cancellation up to 24 hours before the experience starts. Cancel within 24 hours for a 50% refund.'; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Similar Attractions -->
        <?php if (!empty($similarAttractions)): ?>
        <div class="similar-section">
                      <h2 class="card-title" style="font-size: 1.25rem;">You might also like</h2>
            <div class="similar-grid">
                <?php foreach ($similarAttractions as $similar): ?>
                <a href="detail.php?id=<?php echo $similar['attraction_id']; ?>" class="similar-card">
                    <div class="similar-image">
                        <img src="<?php echo getImageUrl($similar['main_image'] ?? '', 'attraction'); ?>" 
                             alt="<?php echo sanitize($similar['attraction_name']); ?>"
                             loading="lazy"
                             onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60'">
                    </div>
                    <div class="similar-content">
                        <div class="similar-title"><?php echo sanitize($similar['attraction_name']); ?></div>
                        <div class="similar-category"><?php echo sanitize($similar['location_name'] ?? 'Rwanda'); ?></div>
                        <div class="similar-footer">
                            <?php if ($similar['avg_rating'] > 0): ?>
                            <span class="similar-rating"><?php echo number_format($similar['avg_rating'], 1); ?></span>
                            <?php endif; ?>
                            <div class="similar-price">
                                <span class="similar-price-value"><?php echo formatPrice($similar['min_price']); ?></span>
                                <span class="similar-price-unit">/person</span>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lightbox Modal -->
<div class="lightbox-modal" id="lightboxModal">
    <div class="lightbox-content">
        <button class="lightbox-close" onclick="closeLightbox()"><i class="bi bi-x-lg"></i></button>
        <button class="lightbox-nav lightbox-prev" onclick="changeLightboxImage(-1)"><i class="bi bi-chevron-left"></i></button>
        <img src="" alt="Gallery image" id="lightboxImage">
        <button class="lightbox-nav lightbox-next" onclick="changeLightboxImage(1)"><i class="bi bi-chevron-right"></i></button>
    </div>
</div>

<!-- Message Modal -->
<div class="modal" id="messageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Message Provider</h3>
            <button class="modal-close" onclick="closeMessageModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="messageForm" onsubmit="sendMessage(event)">
            <div class="modal-body">
                <input type="hidden" name="receiver_id" id="messageReceiverId" value="">
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" 
                           value="Question about <?php echo addslashes($attraction['attraction_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="5" required
                              placeholder="Write your message here..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-item">
                        <input type="checkbox" name="send_copy" value="1" checked>
                        <span>Send me a copy via email</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeMessageModal()">Cancel</button>
                <button type="submit" class="btn-primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// Gallery Functions
// ============================================

// Open gallery modal - same as restaurants
function openGallery(startIndex) {
    const modal = new bootstrap.Modal(document.getElementById('galleryModal'));
    const carousel = document.getElementById('galleryCarousel');
    
    // Navigate to specific slide
    const carouselInstance = bootstrap.Carousel.getInstance(carousel) || new bootstrap.Carousel(carousel);
    carouselInstance.to(startIndex);
    
    modal.show();
}

// Make sure Bootstrap is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Ensure Bootstrap is available
    if (typeof bootstrap === 'undefined') {
        console.log('Bootstrap not loaded, loading it...');
        const bootstrapLink = document.createElement('link');
        bootstrapLink.rel = 'stylesheet';
        bootstrapLink.href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        document.head.appendChild(bootstrapLink);
        
        const bootstrapScript = document.createElement('script');
        bootstrapScript.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        document.body.appendChild(bootstrapScript);
    }
});


// ============================================
// FIXED Tier Selection - No more duplicate tier_id fields
// ============================================
let selectedPrice = <?php echo $defaultPrice ?? 0; ?>;
let participants = <?php echo $participants; ?>;
let selectedStartTime = '<?php echo $startTime; ?>';

// Called when clicking on tier cards - updates the select dropdown
function selectTier(tierId, price, element) {
    // Update the select dropdown to match clicked tier
    const select = document.getElementById('tierSelect');
    if (select) {
        select.value = tierId;
    }
    
    selectedPrice = price;
    document.getElementById('sidebarPrice').textContent = formatCurrency(price);
    document.getElementById('pricePerPerson').textContent = formatCurrency(price);
    
    // Update UI - remove selected from all, add to clicked
    document.querySelectorAll('.tier-card').forEach(card => {
        card.classList.remove('selected');
    });
    if (element) {
        element.classList.add('selected');
    }
    
    updateTotal();
}

// Called when changing select dropdown - updates price display
function updatePriceFromSelect(select) {
    const selectedOption = select.options[select.selectedIndex];
    const price = parseFloat(selectedOption.dataset.price);
    const tierId = selectedOption.value;
    
    selectedPrice = price;
    document.getElementById('sidebarPrice').textContent = formatCurrency(price);
    document.getElementById('pricePerPerson').textContent = formatCurrency(price);
    
    // Update tier card UI to match select
    document.querySelectorAll('.tier-card').forEach(card => {
        card.classList.remove('selected');
        // Check if this card matches the selected tier
        if (card.getAttribute('onclick') && card.getAttribute('onclick').includes(`selectTier(${tierId},`)) {
            card.classList.add('selected');
        }
    });
    
    updateTotal();
}

function selectStartTime(time) {
    selectedStartTime = time;
    document.getElementById('selectedStartTime').value = time;
    
    document.querySelectorAll('.time-tag').forEach(tag => {
        tag.classList.remove('selected');
        if (tag.textContent.trim().includes(time)) {
            tag.classList.add('selected');
        }
    });
}

// ============================================
// Participant Counter
// ============================================
function changeParticipants(delta) {
    const newCount = participants + delta;
    const maxGroup = <?php echo $attraction['max_group_size'] ?? 20; ?>;
    
    if (newCount >= 1 && newCount <= maxGroup) {
        participants = newCount;
        document.getElementById('participantCount').textContent = participants;
        document.getElementById('participantsInput').value = participants;
        document.getElementById('participantCountDisplay').textContent = participants;
        
        document.getElementById('decreaseParticipants').disabled = participants <= 1;
        document.getElementById('increaseParticipants').disabled = participants >= maxGroup;
        
        updateTotal();
    }
}

function updateTotal() {
    const total = selectedPrice * participants;
    document.getElementById('totalPrice').textContent = formatCurrency(total);
}

// ============================================
// Date Selection
// ============================================
function selectCalendarDate(date) {
    document.getElementById('selectedDate').value = date;
    
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.classList.remove('selected');
        if (day.dataset.date === date) {
            day.classList.add('selected');
        }
    });
}

function filterCalendar(tierId) {
    document.querySelectorAll('.tier-filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.currentTarget.classList.add('active');
    
    // Here you would filter calendar days based on selected tier
    console.log('Filtering by tier:', tierId);
}

function scrollCalendar(direction) {
    const grid = document.getElementById('calendarGrid');
    const scrollAmount = direction * 200;
    grid.scrollBy({ left: scrollAmount, behavior: 'smooth' });
}

// ============================================
// Message Functions
// ============================================
function openMessageModal(ownerId) {
    document.getElementById('messageReceiverId').value = ownerId;
    document.getElementById('messageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMessageModal() {
    document.getElementById('messageModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function sendMessage(event) {
    event.preventDefault();
    
    const form = document.getElementById('messageForm');
    const formData = new FormData(form);
    formData.append('ajax_action', 'send_message');
    
    fetch('/gorwanda-plus/api/send-message.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Message sent successfully! The provider will respond soon.');
            closeMessageModal();
        } else {
            alert('Failed to send message. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
    
    return false;
}

// ============================================
// Review Functions
// ============================================
function markHelpful(reviewId) {
    fetch('/gorwanda-plus/api/mark-helpful.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'review_id=' + reviewId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// ============================================
// Utility Functions
// ============================================
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function shareAttraction() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo addslashes($attraction['attraction_name']); ?>',
            text: 'Check out this experience on GoRwanda+',
            url: window.location.href
        });
    } else {
        navigator.clipboard.writeText(window.location.href);
        alert('Link copied to clipboard!');
    }
}

function toggleSave() {
    const btn = document.getElementById('saveBtn');
    const isSaved = btn.classList.contains('saved');
    
    if (isSaved) {
        btn.classList.remove('saved');
        btn.innerHTML = '<i class="bi bi-heart"></i><span class="d-none d-sm-inline">Save</span>';
    } else {
        btn.classList.add('saved');
        btn.innerHTML = '<i class="bi bi-heart-fill"></i><span class="d-none d-sm-inline">Saved</span>';
    }
    
    // Here you would make an AJAX call to save to favorites
}

// Keyboard navigation for lightbox
document.addEventListener('keydown', function(e) {
    if (document.getElementById('lightboxModal').classList.contains('active')) {
        if (e.key === 'ArrowLeft') {
            changeLightboxImage(-1);
        } else if (e.key === 'ArrowRight') {
            changeLightboxImage(1);
        } else if (e.key === 'Escape') {
            closeLightbox();
        }
    }
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateTotal();
    
    // Set min date for date input
    const dateInput = document.getElementById('selectedDate');
    if (dateInput) {
        const today = new Date().toISOString().split('T')[0];
        dateInput.min = today;
    }
    
    // Ensure tier cards match initial select value
    const initialTierId = document.getElementById('tierSelect')?.value;
    if (initialTierId) {
        document.querySelectorAll('.tier-card').forEach(card => {
            if (card.getAttribute('onclick') && card.getAttribute('onclick').includes(`selectTier(${initialTierId},`)) {
                card.classList.add('selected');
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>