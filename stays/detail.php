<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

$db = getDB();

// Get stay details with all related data from partner dashboard
$stmt = $db->prepare("
    SELECT s.*, l.name as location_name, l.latitude as loc_lat, l.longitude as loc_lng,
           u.first_name as owner_name, u.phone as owner_phone,
           (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
           (SELECT COUNT(*) FROM restaurants WHERE stay_id = s.stay_id AND is_active = 1) as restaurant_count,
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as total_rooms,
           (SELECT COUNT(*) FROM offers WHERE vendor_id = (SELECT vendor_id FROM vendor_profiles WHERE user_id = s.owner_id) 
            AND is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()) as active_offers
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ? AND s.is_active = 1
");
$stmt->execute([$id]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}



// Handle message submission from detail page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message_to_owner'])) {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /gorwanda-plus/login.php');
        exit;
    }
    
    $currentUser = getCurrentUser();
    $message = sanitize($_POST['message']);
    $subject = sanitize($_POST['subject'] ?? 'Question about your property');
    $propertyId = $id;
    
    if (!empty($message)) {
        // Get owner's user_id from stays table
        $ownerId = $stay['owner_id'];
        
        // Generate conversation ID (sort IDs to ensure consistency)
        $participants = [$currentUser['user_id'], $ownerId];
        sort($participants);
        $conversationId = 'conv_' . implode('_', $participants) . '_' . time();
        
        // Insert message
        $stmt = $db->prepare("
            INSERT INTO messages (conversation_id, sender_id, receiver_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$conversationId, $currentUser['user_id'], $ownerId, $subject, $message]);
        
        // Add to conversation participants
        $stmt = $db->prepare("
            INSERT IGNORE INTO conversation_participants (conversation_id, user_id, last_read_at)
            VALUES (?, ?, NOW()), (?, ?, NOW())
        ");
        $stmt->execute([$conversationId, $currentUser['user_id'], $conversationId, $ownerId]);
        
        $messageSuccess = "Your message has been sent to the property owner. They will respond shortly.";
    }
}

// Get previous conversation between this user and property owner (if logged in)
$existingConversation = null;
$previousMessages = [];

if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    
    // Find existing conversation
    $participants = [$currentUser['user_id'], $stay['owner_id']];
    sort($participants);
    $searchPattern = 'conv_' . implode('_', $participants) . '_%';
    
    $stmt = $db->prepare("
        SELECT DISTINCT conversation_id 
        FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$currentUser['user_id'], $stay['owner_id'], $stay['owner_id'], $currentUser['user_id']]);
    $existingConversation = $stmt->fetchColumn();
    
    if ($existingConversation) {
        // Get last few messages from this conversation
        $stmt = $db->prepare("
            SELECT m.*, u.first_name, u.last_name, u.profile_image,
                   DATE_FORMAT(m.created_at, '%M %d, %Y at %h:%i %p') as formatted_date
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at DESC
            LIMIT 3
        ");
        $stmt->execute([$existingConversation]);
        $previousMessages = $stmt->fetchAll();
    }
}


// Get dates from URL or defaults
$checkin = $_GET['checkin'] ?? date('Y-m-d', strtotime('+1 day'));
$checkout = $_GET['checkout'] ?? date('Y-m-d', strtotime('+2 days'));
$guests = intval($_GET['guests'] ?? 2);

// Validate dates
if ($checkout <= $checkin) {
    $checkout = date('Y-m-d', strtotime($checkin . ' +1 day'));
}

// Calculate nights
$nights = max(1, (strtotime($checkout) - strtotime($checkin)) / 86400);

// Get rooms with availability, special pricing, and seasonal rates
$stmt = $db->prepare("
    SELECT sr.*,
           (SELECT COUNT(*) FROM stay_availability sa 
            WHERE sa.room_id = sr.room_id 
            AND sa.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
            AND sa.is_blocked = 1) as blocked_days,
           (SELECT MIN(price_override) FROM stay_availability sa 
            WHERE sa.room_id = sr.room_id 
            AND sa.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
            AND sa.price_override IS NOT NULL) as min_special_price,
           (SELECT price_multiplier FROM seasons s 
            WHERE s.vendor_id = (SELECT vendor_id FROM vendor_profiles WHERE user_id = s.owner_id)
            AND s.start_date <= ? AND s.end_date >= ?) as season_multiplier
    FROM stay_rooms sr
    JOIN stays s ON sr.stay_id = s.stay_id
    WHERE sr.stay_id = ? AND sr.is_active = 1
    ORDER BY sr.base_price ASC
");
$stmt->execute([$checkin, $checkout, $checkin, $checkout, $checkin, $checkin, $id]);
$rooms = $stmt->fetchAll();

// Calculate effective prices with seasonal multipliers and special offers
foreach ($rooms as &$room) {
    $basePrice = $room['base_price'];
    
    // Apply seasonal multiplier if exists
    if ($room['season_multiplier']) {
        $basePrice = $basePrice * $room['season_multiplier'];
    }
    
    // Apply special price override if exists
    if ($room['min_special_price']) {
        $room['effective_price'] = $room['min_special_price'];
        $room['has_discount'] = true;
        $room['original_price'] = $basePrice;
    } else {
        $room['effective_price'] = $basePrice;
        $room['has_discount'] = false;
    }
    
    $room['total_price'] = $room['effective_price'] * $nights;
}
unset($room);

// Get active special offers for this property
$stmt = $db->prepare("
    SELECT * FROM offers 
    WHERE vendor_id = (SELECT vendor_id FROM vendor_profiles WHERE user_id = ?)
    AND is_active = 1 
    AND start_date <= CURDATE() 
    AND end_date >= CURDATE()
    AND (applicable_to IS NULL OR JSON_CONTAINS(applicable_to, JSON_QUOTE(CAST(? AS CHAR)), '$'))
    ORDER BY discount_value DESC
");
$stmt->execute([$stay['owner_id'], $id]);
$specialOffers = $stmt->fetchAll();

// Get reviews with user details and response data
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           DATEDIFF(r.created_at, u.created_at) as days_as_member,
           (SELECT COUNT(*) FROM reviews r2 WHERE r2.user_id = r.user_id) as user_review_count
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.stay_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC LIMIT 10
");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

// Calculate detailed review statistics
$reviewStats = [
    'cleanliness' => 0,
    'service' => 0,
    'location' => 0,
    'value' => 0,
    'comfort' => 0,
    'facilities' => 0,
    'staff' => 0
];

foreach ($reviews as $review) {
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

// Get restaurants with menu previews
$stmt = $db->prepare("
    SELECT r.*, 
           (SELECT COUNT(*) FROM menu_categories WHERE restaurant_id = r.restaurant_id) as category_count,
           (SELECT COUNT(*) FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.category_id WHERE mc.restaurant_id = r.restaurant_id) as menu_items,
           (SELECT image_path FROM restaurant_images WHERE restaurant_id = r.restaurant_id AND is_main = 1 LIMIT 1) as main_image
    FROM restaurants r
    WHERE r.stay_id = ? AND r.is_active = 1 
    ORDER BY r.restaurant_name
");
$stmt->execute([$id]);
$restaurants = $stmt->fetchAll();

// Get featured menu items from restaurants
$menuItems = [];
if (!empty($restaurants)) {
    $restaurantIds = array_column($restaurants, 'restaurant_id');
    $placeholders = implode(',', array_fill(0, count($restaurantIds), '?'));
    
    $stmt = $db->prepare("
        SELECT mi.*, mc.category_name, r.restaurant_name
        FROM menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.category_id
        JOIN restaurants r ON mc.restaurant_id = r.restaurant_id
        WHERE mc.restaurant_id IN ($placeholders) AND mi.is_available = 1
        ORDER BY mi.is_signature DESC, mi.price ASC
        LIMIT 6
    ");
    $stmt->execute($restaurantIds);
    $menuItems = $stmt->fetchAll();
}

// Get nearby attractions with real distances
$stmt = $db->prepare("
    SELECT a.*, c.name as category_name, c.icon as category_icon,
           (6371 * acos(cos(radians(?)) * cos(radians(a.latitude)) * 
           cos(radians(a.longitude) - radians(?)) + sin(radians(?)) * 
           sin(radians(a.latitude)))) AS distance
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    WHERE a.is_active = 1 AND a.is_verified = 1
    HAVING distance < 20
    ORDER BY distance
    LIMIT 5
");
$stmt->execute([$stay['latitude'] ?? -1.9441, $stay['longitude'] ?? 30.0619, $stay['latitude'] ?? -1.9441]);
$nearbyAttractions = $stmt->fetchAll();

// Get nearby restaurants (other than on-site)
$stmt = $db->prepare("
    SELECT r.*, 
           (6371 * acos(cos(radians(?)) * cos(radians(s.latitude)) * 
           cos(radians(s.longitude) - radians(?)) + sin(radians(?)) * 
           sin(radians(s.latitude)))) AS distance
    FROM restaurants r
    JOIN stays s ON r.stay_id = s.stay_id
    WHERE r.is_active = 1 AND r.stay_id != ?
    HAVING distance < 5
    ORDER BY distance
    LIMIT 3
");
$stmt->execute([$stay['latitude'] ?? -1.9441, $stay['longitude'] ?? 30.0619, $stay['latitude'] ?? -1.9441, $id]);
$nearbyRestaurants = $stmt->fetchAll();

// Get similar properties with enhanced matching
$stmt = $db->prepare("
    SELECT s.*, l.name as location_name,
    (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
    (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id) as avg_rating,
    (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
    (SELECT COUNT(*) FROM restaurants WHERE stay_id = s.stay_id AND is_active = 1) as restaurant_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.stay_id != ? AND s.is_active = 1 AND s.is_verified = 1
    AND (s.location_id = ? OR s.city = ? OR s.star_rating BETWEEN ? - 0.5 AND ? + 0.5)
    ORDER BY s.avg_rating DESC LIMIT 3
");
$stmt->execute([$id, $stay['location_id'], $stay['city'] ?? '', $stay['star_rating'], $stay['star_rating']]);
$similarStays = $stmt->fetchAll();

// Get house rules with detailed policies
$policies = json_decode($stay['policies'] ?? '{}', true);
$houseRules = [
    'check_in' => $stay['check_in_time'] ?? '14:00',
    'check_out' => $stay['check_out_time'] ?? '11:00',
    'children' => $policies['children'] ?? true,
    'pets' => $policies['pets'] ?? false,
    'smoking' => $policies['smoking'] ?? false,
    'parties' => $policies['parties'] ?? false,
    'quiet_hours' => $policies['quiet_hours'] ?? '22:00 - 08:00',
    'extra_beds' => $policies['extra_beds'] ?? 'Available on request'
];

$pageTitle = $stay['stay_name'];
$hideSearch = true;
require_once '../includes/header.php';

$amenities = json_decode($stay['amenities'] ?? '[]', true);
$images = array_filter([$stay['main_image']] + (json_decode($stay['images'] ?? '[]', true) ?: []));
if (empty($images)) $images = [''];
?>

<style>
/* Modern, Light Detail Page CSS */
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
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Breadcrumb */
.breadcrumb-bar {
    padding: 16px 0;
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

/* Header Section */
.detail-header {
    margin-bottom: 24px;
}

.detail-title {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    line-height: 1.2;
}

.detail-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}

.rating-large {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--primary-dark);
    color: white;
    padding: 8px 12px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 1rem;
}

.star-rating {
    color: var(--accent);
    font-size: 1rem;
}

.location-badge {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--text-secondary);
    font-size: 0.875rem;
}

.location-badge i {
    color: var(--primary);
}

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
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Gallery */
.gallery-section {
    margin-bottom: 32px;
}

.main-gallery {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    grid-template-rows: 200px 200px;
    gap: 8px;
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.gallery-item {
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.gallery-item.main {
    grid-row: span 2;
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s;
}

.gallery-item:hover img {
    transform: scale(1.05);
}

.gallery-overlay {
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

.gallery-overlay:hover {
    background: rgba(0,0,0,0.9);
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 32px;
    margin-bottom: 48px;
}

/* Main Content */
.main-content {
    min-width: 0;
}

/* Info Cards */
.info-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    margin-bottom: 24px;
}

.info-card:last-child {
    margin-bottom: 0;
}

.card-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-title i {
    color: var(--primary);
    font-size: 1.25rem;
}

/* Highlights */
.highlights-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-top: 16px;
}

.highlight-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.highlight-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.highlight-text {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text);
}

/* Amenities */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.amenity-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: var(--transition);
}

.amenity-item:hover {
    background: var(--primary-light);
}

.amenity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-secondary);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.amenity-text {
    font-size: 0.875rem;
    color: var(--text);
}

/* Rooms */
.room-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    margin-bottom: 16px;
    transition: var(--transition);
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.room-card:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.room-card.selected {
    border-color: var(--primary);
    background: var(--primary-light);
}

.room-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.room-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text);
}

.room-price {
    text-align: right;
}

.room-price-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--success);
    line-height: 1.2;
}

.room-price-unit {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.room-features {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin: 12px 0;
    font-size: 0.8125rem;
    color: var(--text-secondary);
}

.room-features span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.room-features i {
    color: var(--success);
    font-size: 1rem;
}

.room-amenities {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0;
    padding: 12px 0;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.room-amenity-tag {
    padding: 4px 10px;
    background: var(--bg-secondary);
    border-radius: 20px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.room-amenity-tag i {
    color: var(--success);
    font-size: 0.75rem;
}

.room-select-btn {
    width: 100%;
    padding: 12px;
    background: white;
    border: 1px solid var(--primary);
    color: var(--primary);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.room-select-btn:hover {
    background: var(--primary);
    color: white;
}

.room-select-btn.selected {
    background: var(--primary);
    color: white;
}

/* Restaurant Section */
.restaurant-preview {
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
}

.restaurant-preview h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.restaurant-preview h3 i {
    color: var(--warning);
}

.restaurant-mini-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 12px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: 12px;
    text-decoration: none;
    color: var(--text);
    transition: var(--transition);
}

.restaurant-mini-card:hover {
    background: var(--primary-light);
    transform: translateX(4px);
}

.restaurant-mini-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    color: var(--warning);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.restaurant-mini-info h4 {
    font-size: 0.9375rem;
    font-weight: 600;
    margin-bottom: 2px;
}

.restaurant-mini-info p {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Reviews */
.reviews-summary {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 32px;
    margin-bottom: 32px;
    padding: 24px;
    background: var(--bg-secondary);
    border-radius: var(--radius-lg);
}

.review-score-large {
    text-align: center;
    min-width: 120px;
}

.score-number {
    font-size: 3rem;
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
}

.reviewer-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.reviewer-meta {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.review-rating {
    display: flex;
    gap: 2px;
    color: var(--accent);
}

.review-title {
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 1rem;
}

.review-text {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin-bottom: 12px;
}

.review-date {
    font-size: 0.75rem;
    color: var(--text-muted);
}

/* Sidebar */
.detail-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.booking-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 24px;
    box-shadow: var(--shadow-lg);
    margin-bottom: 20px;
}

.booking-card h3 {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 20px;
}

.price-summary {
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
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
    font-size: 1.125rem;
    font-weight: 700;
}

.price-label {
    color: var(--text-secondary);
}

.price-value {
    font-weight: 600;
}

.price-value.total {
    color: var(--primary);
    font-size: 1.25rem;
}

.date-box {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px;
    margin-bottom: 12px;
    cursor: pointer;
    transition: var(--transition);
}

.date-box:hover {
    border-color: var(--primary);
}

.date-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.date-value {
    font-weight: 600;
    font-size: 0.9375rem;
}

.date-input {
    width: 100%;
    border: none;
    background: transparent;
    font-weight: 600;
    font-size: 0.9375rem;
    padding: 0;
}

.date-input:focus {
    outline: none;
}

.guests-box {
    position: relative;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px;
    cursor: pointer;
    margin-bottom: 20px;
}

.guests-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-top: 4px;
    box-shadow: var(--shadow-lg);
    z-index: 100;
    display: none;
}

.guests-dropdown.active {
    display: block;
    animation: slideDown 0.2s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.guest-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.guest-counter {
    display: flex;
    align-items: center;
    gap: 12px;
}

.guest-counter button {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 1px solid var(--primary);
    background: white;
    color: var(--primary);
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
}

.guest-counter button:hover:not(:disabled) {
    background: var(--primary);
    color: white;
}

.guest-counter button:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.guest-counter span {
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}

.btn-done {
    width: 100%;
    padding: 10px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-done:hover {
    background: var(--primary-dark);
}

.selected-room-info {
    background: var(--primary-light);
    border-radius: var(--radius-md);
    padding: 16px;
    margin: 20px 0;
    border-left: 4px solid var(--primary);
}

.selected-room-name {
    font-weight: 700;
    margin-bottom: 4px;
}

.selected-room-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}

.reserve-btn {
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
    margin-bottom: 12px;
}

.reserve-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.reserve-btn:disabled {
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

/* Nearby Section */
.nearby-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-top: 16px;
}

.nearby-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.nearby-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
}

.nearby-info h4 {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 2px;
}

.nearby-info p {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Similar Properties */
.similar-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-top: 20px;
}

.similar-card {
    text-decoration: none;
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: var(--transition);
}

.similar-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary);
}

.similar-image {
    height: 140px;
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
    font-size: 0.875rem;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.similar-location {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
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
    font-size: 0.75rem;
}

.similar-price {
    text-align: right;
}

.similar-price-value {
    font-weight: 700;
    font-size: 0.875rem;
    color: var(--success);
}

.similar-price-unit {
    font-size: 0.625rem;
    color: var(--text-secondary);
}

/* Responsive */
@media (max-width: 992px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    
    .detail-sidebar {
        position: static;
        order: -1;
        margin-bottom: 32px;
    }
    
    .main-gallery {
        grid-template-columns: 1fr;
        grid-template-rows: 300px;
    }
    
    .gallery-item:not(.main) {
        display: none;
    }
    
    .reviews-summary {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .similar-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .detail-title {
        font-size: 1.5rem;
    }
    
    .detail-meta {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .action-buttons {
        margin-left: 0;
        width: 100%;
    }
    
    .action-btn {
        flex: 1;
        justify-content: center;
    }
    
    .amenities-grid,
    .highlights-grid,
    .review-categories,
    .nearby-grid {
        grid-template-columns: 1fr;
    }
    
    .similar-grid {
        grid-template-columns: 1fr;
    }
    
    .room-header {
        flex-direction: column;
        gap: 12px;
    }
    
    .room-price {
        text-align: left;
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

/* Loading Spinner */
.loading-spinner .spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border);
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Old price styling */
.old-price {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-decoration: line-through;
}
</style>

<div class="container">
    <!-- Breadcrumb -->
    <nav class="breadcrumb-bar">
        <a href="/gorwanda-plus/" class="breadcrumb-link">Home</a>
        <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
        <a href="/gorwanda-plus/search.php?type=stays" class="breadcrumb-link">Stays</a>
        <i class="bi bi-chevron-right mx-2" style="font-size: 0.75rem;"></i>
        <span class="text-secondary"><?php echo sanitize($stay['stay_name']); ?></span>
    </nav>
    
    <!-- Header Section with Special Offer Badge -->
    <div class="detail-header">
        <div class="d-flex flex-wrap align-items-start justify-content-between">
            <div>
                <h1 class="detail-title"><?php echo sanitize($stay['stay_name']); ?></h1>
                <div class="detail-meta">
                    <?php if ($stay['star_rating']): ?>
                    <div class="star-rating">
                        <?php for($i=1; $i<=5; $i++): ?>
                            <i class="bi bi-star-fill<?php echo $i > $stay['star_rating'] ? '-empty' : ''; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($stay['avg_rating']): ?>
                    <div class="rating-large">
                        <span><?php echo number_format($stay['avg_rating'], 1); ?></span>
                        <span style="font-weight: 400; opacity: 0.9;">/10</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="location-badge">
                        <i class="bi bi-geo-alt-fill"></i>
                        <span><?php echo sanitize($stay['address']); ?>, <?php echo sanitize($stay['city'] ?? $stay['location_name'] ?? 'Rwanda'); ?></span>
                    </div>
                    
                    <?php if ($stay['review_count'] > 0): ?>
                    <a href="#reviews" class="text-decoration-none" style="color: var(--text-secondary); font-size: 0.875rem;">
                        <i class="bi bi-chat-text"></i> <?php echo $stay['review_count']; ?> reviews
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($stay['active_offers'] > 0): ?>
                    <span class="badge" style="background: var(--accent); color: var(--text); padding: 4px 8px; border-radius: var(--radius-sm); font-size: 0.75rem;">
                        <i class="bi bi-tag-fill"></i> Special offers available
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="action-buttons">
                <button class="action-btn" onclick="shareProperty()">
                    <i class="bi bi-share"></i>
                    <span class="d-none d-sm-inline">Share</span>
                </button>
                <button class="action-btn" onclick="toggleSave()" id="saveBtn">
                    <i class="bi bi-heart"></i>
                    <span class="d-none d-sm-inline">Save</span>
                </button>
                <button class="action-btn filled" onclick="document.getElementById('rooms').scrollIntoView({behavior: 'smooth'})">
                    <i class="bi bi-calendar-check"></i>
                    <span class="d-none d-sm-inline">Reserve</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Gallery Section -->
    <div class="gallery-section">
        <div class="main-gallery">
            <?php foreach ($images as $index => $img): ?>
                <?php if ($index < 5): ?>
                <div class="gallery-item <?php echo $index === 0 ? 'main' : ''; ?>" onclick="openGallery(<?php echo $index; ?>)">
                    <img src="<?php echo getImageUrl($img, 'stay'); ?>" 
                         alt="<?php echo sanitize($stay['stay_name']); ?> - Image <?php echo $index + 1; ?>"
                         loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=600&q=80'">
                    
                    <?php if ($index === 0 && count($images) > 5): ?>
                    <div class="gallery-overlay" onclick="openFullGallery()">
                        <i class="bi bi-images"></i>
                        <span>+<?php echo count($images) - 5; ?> photos</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Main Content Grid -->
    <div class="content-grid">
        <!-- Left Column - Main Content -->
        <div class="main-content">
            <!-- Property Highlights with Enhanced Stats -->
            <div class="info-card fadeInUp">
                <h2 class="card-title">
                    <i class="bi bi-stars"></i>
                    Property highlights
                </h2>
                <div class="highlights-grid">
                    <?php
                    $highlights = [
                        ['icon' => 'bi-clock', 'text' => 'Check-in: ' . $houseRules['check_in']],
                        ['icon' => 'bi-clock', 'text' => 'Check-out: ' . $houseRules['check_out']],
                        ['icon' => 'bi-people', 'text' => 'Up to ' . (max(array_column($rooms, 'max_guests')) ?: 2) . ' guests'],
                        ['icon' => 'bi-door-open', 'text' => count($rooms) . ' room types'],
                        ['icon' => 'bi-shop', 'text' => $stay['restaurant_count'] . ' on-site restaurant' . ($stay['restaurant_count'] != 1 ? 's' : '')],
                        ['icon' => 'bi-building', 'text' => 'Est. ' . date('Y', strtotime($stay['created_at']))]
                    ];
                    foreach ($highlights as $highlight):
                    ?>
                    <div class="highlight-item">
                        <div class="highlight-icon">
                            <i class="bi <?php echo $highlight['icon']; ?>"></i>
                        </div>
                        <span class="highlight-text"><?php echo $highlight['text']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Special Offers Section (if any) -->
            <?php if (!empty($specialOffers)): ?>
            <div class="info-card fadeInUp delay-1" style="background: linear-gradient(135deg, #fff4e6, white); border-left: 4px solid var(--accent);">
                <h2 class="card-title" style="color: var(--accent);">
                    <i class="bi bi-tag-fill"></i>
                    Special offers
                </h2>
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <?php foreach ($specialOffers as $offer): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: white; border-radius: var(--radius-md);">
                        <div>
                            <div style="font-weight: 700;"><?php echo sanitize($offer['offer_name']); ?></div>
                            <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                                <?php if ($offer['offer_type'] == 'percentage'): ?>
                                    Save <?php echo $offer['discount_value']; ?>% on your stay
                                <?php elseif ($offer['offer_type'] == 'fixed'): ?>
                                    Get <?php echo formatPrice($offer['discount_value']); ?> off
                                <?php elseif ($offer['offer_type'] == 'free_night'): ?>
                                    Free night when you book <?php echo $offer['min_nights']; ?> nights
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--accent); font-weight: 600;">
                            <?php echo date('M d', strtotime($offer['end_date'])); ?> deadline
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Description -->
            <div class="info-card fadeInUp delay-1">
                <h2 class="card-title">
                    <i class="bi bi-info-circle"></i>
                    About this property
                </h2>
                <p style="font-size: 0.9375rem; line-height: 1.7; color: var(--text-secondary);">
                    <?php echo nl2br(sanitize($stay['description'])); ?>
                </p>
            </div>
            
            <!-- Amenities -->
            <?php if (!empty($amenities)): ?>
            <div class="info-card fadeInUp delay-1">
                <h2 class="card-title">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    Popular amenities
                </h2>
                <div class="amenities-grid">
                    <?php
                    $amenityIcons = [
                        'wifi' => 'bi-wifi',
                        'pool' => 'bi-water',
                        'parking' => 'bi-p-circle',
                        'restaurant' => 'bi-shop',
                        'spa' => 'bi-droplet',
                        'gym' => 'bi-bicycle',
                        'bar' => 'bi-cup-straw',
                        'room_service' => 'bi-bell',
                        'ac' => 'bi-snow',
                        'breakfast' => 'bi-egg-fried',
                        'airport_shuttle' => 'bi-bus-front',
                        'laundry' => 'bi-basket',
                        'pets' => 'bi-heart',
                        'family_rooms' => 'bi-people',
                        'non_smoking' => 'bi-ban',
                        'business_center' => 'bi-briefcase'
                    ];
                    
                    foreach ($amenities as $amenity):
                        $icon = $amenityIcons[$amenity] ?? 'bi-check-circle';
                    ?>
                    <div class="amenity-item">
                        <div class="amenity-icon">
                            <i class="bi <?php echo $icon; ?>"></i>
                        </div>
                        <span class="amenity-text">
                            <?php echo ucfirst(str_replace('_', ' ', $amenity)); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Rooms Section with Enhanced Pricing -->
            <div class="info-card" id="rooms">
                <h2 class="card-title">
                    <i class="bi bi-door-open"></i>
                    Available rooms
                </h2>
                
                <div class="room-selection-info mb-3" style="font-size: 0.875rem; color: var(--text-secondary);">
                    <i class="bi bi-calendar-check text-success me-1"></i>
                    <?php echo $nights; ?> night<?php echo $nights > 1 ? 's' : ''; ?> • 
                    <?php echo formatDate($checkin, 'M d'); ?> - <?php echo formatDate($checkout, 'M d'); ?> • 
                    <?php echo $guests; ?> guest<?php echo $guests > 1 ? 's' : ''; ?>
                </div>
                
                <?php if (empty($rooms)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    No rooms available for selected dates. Please try different dates.
                </div>
                <?php else: ?>
                    <?php foreach ($rooms as $room): 
                        $roomAmenities = json_decode($room['room_amenities'] ?? '[]', true);
                    ?>
                    <div class="room-card" data-room-id="<?php echo $room['room_id']; ?>" data-room-price="<?php echo $room['total_price']; ?>">
                        <div class="room-header">
                            <div>
                                <h3 class="room-name"><?php echo sanitize($room['room_name']); ?></h3>
                                <div class="room-features">
                                    <?php if ($room['size_sqm']): ?>
                                    <span><i class="bi bi-rulers"></i> <?php echo $room['size_sqm']; ?> m²</span>
                                    <?php endif; ?>
                                    <span><i class="bi bi-bed"></i> <?php echo $room['bed_configuration'] ?? '1 Queen Bed'; ?></span>
                                    <span><i class="bi bi-people"></i> Max <?php echo $room['max_guests']; ?> guests</span>
                                    <span><i class="bi bi-door-open"></i> <?php echo $room['num_rooms_available']; ?> left</span>
                                </div>
                            </div>
                            <div class="room-price">
                                <?php if ($room['has_discount']): ?>
                                <div class="old-price">
                                    <?php echo formatPrice($room['original_price']); ?>
                                </div>
                                <?php endif; ?>
                                <div class="room-price-value">
                                    <?php echo formatPrice($room['effective_price']); ?>
                                </div>
                                <div class="room-price-unit">per night</div>
                            </div>
                        </div>
                        
                        <?php if (!empty($roomAmenities)): ?>
                        <div class="room-amenities">
                            <?php foreach (array_slice($roomAmenities, 0, 4) as $amenity): ?>
                            <span class="room-amenity-tag">
                                <i class="bi bi-check-circle-fill"></i>
                                <?php echo ucfirst(str_replace('_', ' ', $amenity)); ?>
                            </span>
                            <?php endforeach; ?>
                            <?php if (count($roomAmenities) > 4): ?>
                            <span class="room-amenity-tag">+<?php echo count($roomAmenities) - 4; ?> more</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($room['blocked_days'] > 0): ?>
                        <div style="font-size: 0.75rem; color: var(--warning); margin: 8px 0;">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Limited availability - only <?php echo $room['num_rooms_available'] - $room['blocked_days']; ?> rooms left for selected dates
                        </div>
                        <?php endif; ?>
                        
                        <button class="room-select-btn" onclick="selectRoom(this, <?php echo $room['room_id']; ?>, <?php echo $room['total_price']; ?>)">
                            Select room
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- On-site Dining Section with Menu Previews -->
            <?php if (!empty($restaurants)): ?>
            <div class="info-card fadeInUp delay-2">
                <h2 class="card-title">
                    <i class="bi bi-shop"></i>
                    On-site dining
                </h2>
                
                <div style="margin-bottom: 20px;">
                    <?php foreach (array_slice($restaurants, 0, 2) as $restaurant): 
                        $hours = json_decode($restaurant['opening_hours'] ?? '{}', true);
                    ?>
                    <a href="/gorwanda-plus/restaurant-detail.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="restaurant-mini-card" style="margin-bottom: 12px;">
                        <div class="restaurant-mini-icon">
                            <i class="bi bi-shop"></i>
                        </div>
                        <div class="restaurant-mini-info">
                            <h4><?php echo sanitize($restaurant['restaurant_name']); ?></h4>
                            <p>
                                <?php echo sanitize($restaurant['cuisine_type']); ?> • 
                                <?php echo $restaurant['menu_items']; ?> dishes • 
                                <?php echo isset($hours['lunch']) ? 'Lunch: ' . $hours['lunch'] : 'Open now'; ?>
                            </p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (count($restaurants) > 2): ?>
                    <a href="/gorwanda-plus/restaurants.php?hotel=<?php echo $id; ?>" class="text-decoration-none" style="font-size: 0.875rem; color: var(--primary);">
                        View all <?php echo count($restaurants); ?> restaurants →
                    </a>
                    <?php endif; ?>
                </div>
                
                <!-- Featured Menu Items -->
                <?php if (!empty($menuItems)): ?>
                <h3 style="font-size: 1rem; font-weight: 600; margin: 20px 0 12px;">Popular dishes</h3>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                    <?php foreach (array_slice($menuItems, 0, 3) as $item): ?>
                    <div style="background: var(--bg-secondary); border-radius: var(--radius-md); padding: 12px;">
                        <div style="font-weight: 600; font-size: 0.875rem;"><?php echo sanitize($item['item_name']); ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);"><?php echo sanitize($item['restaurant_name']); ?></div>
                        <div style="font-weight: 700; color: var(--success); margin-top: 8px;"><?php echo formatPrice($item['price']); ?></div>
                        <?php if ($item['is_signature']): ?>
                        <span style="font-size: 0.6rem; background: var(--primary-light); color: var(--primary); padding: 2px 4px; border-radius: 4px;">Chef's special</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Reviews Section with Detailed Analytics -->
            <?php if (!empty($reviews)): ?>
            <div class="info-card" id="reviews">
                <h2 class="card-title">
                    <i class="bi bi-star-fill text-warning"></i>
                    Guest reviews
                </h2>
                
                <div class="reviews-summary">
                    <div class="review-score-large">
                        <div class="score-number"><?php echo number_format($stay['avg_rating'], 1); ?></div>
                        <div class="score-label"><?php echo getReviewLabel($stay['avg_rating'])[0]; ?></div>
                        <div class="score-count"><?php echo $stay['review_count']; ?> reviews</div>
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
                                <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="reviewer-name">
                                    <?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?>
                                </div>
                                <div class="reviewer-meta">
                                    <?php echo $review['user_review_count']; ?> review<?php echo $review['user_review_count'] > 1 ? 's' : ''; ?> • 
                                    <?php echo $review['days_as_member'] > 365 ? 'Member since ' . date('Y', strtotime($review['created_at'])) : 'New member'; ?>
                                </div>
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
                    
                    <div class="review-date">
                        <?php echo timeAgo($review['created_at']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if ($stay['review_count'] > 10): ?>
                <div class="text-center mt-3">
                    <a href="#" class="btn btn-outline-primary">View all <?php echo $stay['review_count']; ?> reviews</a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Nearby Attractions & Restaurants -->
            <?php if (!empty($nearbyAttractions) || !empty($nearbyRestaurants)): ?>
            <div class="info-card fadeInUp delay-3">
                <h2 class="card-title">
                    <i class="bi bi-geo-alt"></i>
                    What's nearby
                </h2>
                
                <?php if (!empty($nearbyAttractions)): ?>
                <div style="margin-bottom: 20px;">
                    <h3 style="font-size: 0.9375rem; font-weight: 600; margin-bottom: 12px;">Attractions</h3>
                    <div class="nearby-grid">
                        <?php foreach ($nearbyAttractions as $attraction): ?>
                        <div class="nearby-item">
                            <div class="nearby-icon">
                                <i class="bi bi-<?php echo $attraction['category_name'] == 'Wildlife' ? 'tree' : 'building'; ?>"></i>
                            </div>
                            <div class="nearby-info">
                                <h4><?php echo sanitize($attraction['attraction_name']); ?></h4>
                                <p><?php echo round($attraction['distance']); ?> km away</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($nearbyRestaurants)): ?>
                <div>
                    <h3 style="font-size: 0.9375rem; font-weight: 600; margin-bottom: 12px;">Nearby restaurants</h3>
                    <div class="nearby-grid">
                        <?php foreach ($nearbyRestaurants as $restaurant): ?>
                        <a href="/gorwanda-plus/restaurant-detail.php?id=<?php echo $restaurant['restaurant_id']; ?>" class="nearby-item text-decoration-none" style="color: var(--text);">
                            <div class="nearby-icon">
                                <i class="bi bi-shop"></i>
                            </div>
                            <div class="nearby-info">
                                <h4><?php echo sanitize($restaurant['restaurant_name']); ?></h4>
                                <p><?php echo round($restaurant['distance']); ?> km away</p>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- House Rules with Detailed Policies -->
            <div class="info-card">
                <h2 class="card-title">
                    <i class="bi bi-file-text"></i>
                    House rules
                </h2>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Check-in</div>
                        <div style="font-weight: 600;">From <?php echo $houseRules['check_in']; ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">Check-out</div>
                        <div style="font-weight: 600;">Until <?php echo $houseRules['check_out']; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Children</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['children'] ? 'Allowed' : 'Not allowed'; ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">Pets</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['pets'] ? 'Allowed' : 'Not allowed'; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Smoking</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['smoking'] ? 'Allowed' : 'Not allowed'; ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">Parties/events</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['parties'] ? 'Allowed' : 'Not allowed'; ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 4px;">Quiet hours</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['quiet_hours']; ?></div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px;">Extra beds</div>
                        <div style="font-weight: 600;"><?php echo $houseRules['extra_beds']; ?></div>
                    </div>
                </div>
                
                <?php if (!empty($stay['cancellation_policy'])): ?>
                <div style="margin-top: 20px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius-md);">
                    <div style="font-weight: 600; margin-bottom: 4px;">Cancellation policy</div>
                    <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                        <?php echo nl2br(sanitize($stay['cancellation_policy'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Column - Sticky Booking Sidebar (Enhanced) -->
        <div class="detail-sidebar">
            <div class="booking-card">
                <h3>Complete your booking</h3>
                
                <!-- Price Summary with Dynamic Updates -->
                <div class="price-summary" id="priceSummary">
                    <div class="price-row">
                        <span class="price-label">Base price</span>
                        <span class="price-value" id="basePriceDisplay"><?php echo formatPrice($rooms[0]['effective_price'] ?? 0); ?> × <?php echo $nights; ?> nights</span>
                    </div>
                    
                    <?php if (!empty($specialOffers)): ?>
                    <div class="price-row" style="color: var(--success);">
                        <span class="price-label"><i class="bi bi-tag-fill"></i> Special offer</span>
                        <span class="price-value">-<?php echo $specialOffers[0]['discount_value']; ?>%</span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="price-row total">
                        <span class="price-label">Total</span>
                        <span class="price-value total" id="totalPrice"><?php echo formatPrice(($rooms[0]['effective_price'] ?? 0) * $nights); ?></span>
                    </div>
                </div>
                
                <!-- Date Selection -->
                <form id="searchForm" method="GET" action="detail.php">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    
                    <div class="date-box" onclick="this.querySelector('input').showPicker()">
                        <div class="date-label">Check-in</div>
                        <input type="date" name="checkin" class="date-input" value="<?php echo $checkin; ?>" 
                               min="<?php echo date('Y-m-d'); ?>" onchange="updateDates()">
                    </div>
                    
                    <div class="date-box" onclick="this.querySelector('input').showPicker()">
                        <div class="date-label">Check-out</div>
                        <input type="date" name="checkout" class="date-input" value="<?php echo $checkout; ?>" 
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" onchange="updateDates()">
                    </div>
                    
                    <!-- Guests Selector -->
                    <div class="guests-box" onclick="toggleGuestsDropdown()">
                        <div class="date-label">Guests</div>
                        <div class="date-value" id="guestsDisplay"><?php echo $guests; ?> adult<?php echo $guests > 1 ? 's' : ''; ?></div>
                        
                        <div class="guests-dropdown" id="guestsDropdown">
                            <div class="guest-row">
                                <div>
                                    <div style="font-weight: 600;">Adults</div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">Ages 13+</div>
                                </div>
                                <div class="guest-counter">
                                    <button type="button" onclick="changeGuests(-1)" <?php echo $guests <= 1 ? 'disabled' : ''; ?>>-</button>
                                    <span id="guestCount"><?php echo $guests; ?></span>
                                    <button type="button" onclick="changeGuests(1)" <?php echo $guests >= 8 ? 'disabled' : ''; ?>>+</button>
                                </div>
                            </div>
                            <input type="hidden" name="guests" id="guestsInput" value="<?php echo $guests; ?>">
                            <button type="button" class="btn-done" onclick="closeGuestsDropdown()">Done</button>
                        </div>
                    </div>
                </form>
                
                <!-- Selected Room Info -->
                <div class="selected-room-info" id="selectedRoomInfo" style="display: none;">
                    <div class="selected-room-name" id="selectedRoomName"></div>
                    <div class="selected-room-price" id="selectedRoomTotal"></div>
                    <div style="font-size: 0.75rem; color: var(--text-secondary);" id="selectedRoomNights"></div>
                </div>
                
                <!-- Reserve Button -->
                <button class="reserve-btn" id="reserveBtn" onclick="proceedToBooking()" disabled>
                    Select a room to continue
                </button>
                
                <div class="security-note">
                    <i class="bi bi-shield-check"></i>
                    <span>Your payment is secure • Free cancellation within 24h</span>
                </div>
                
                <?php if (!empty($specialOffers)): ?>
                <div style="margin-top: 16px; padding: 12px; background: var(--accent); border-radius: var(--radius-sm); color: var(--text); font-size: 0.75rem;">
                    <i class="bi bi-lightning-charge-fill"></i>
                    Limited time offer - Book before <?php echo date('M d', strtotime($specialOffers[0]['end_date'])); ?>
                </div>
                <?php endif; ?>
            </div>
            
<!-- Contact Card with Working Messaging -->
<div class="booking-card" style="background: var(--bg-secondary);">
    <h3 style="font-size: 1rem;">Have a question?</h3>
    <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 16px;">
        Contact the property directly for special requests or inquiries.
    </p>
    
    <?php if (isLoggedIn()): ?>
        
        <!-- Show previous messages if any -->
        <?php if (!empty($previousMessages)): ?>
        <div style="margin-bottom: 16px;">
            <h4 style="font-size: 0.8125rem; font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 4px;">
                <i class="bi bi-chat-dots"></i> Recent conversation
            </h4>
            <div style="max-height: 200px; overflow-y: auto; padding-right: 4px;">
                <?php foreach (array_reverse($previousMessages) as $msg): ?>
                <div style="padding: 10px; background: <?php echo $msg['sender_id'] == $currentUser['user_id'] ? 'var(--primary-light)' : 'white'; ?>; border-radius: var(--radius-md); margin-bottom: 8px; border: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <span style="font-weight: 600; font-size: 0.75rem;">
                            <?php echo $msg['sender_id'] == $currentUser['user_id'] ? 'You' : sanitize($msg['first_name']); ?>
                            <?php if ($msg['sender_id'] == $stay['owner_id']): ?>
                            <span style="background: var(--primary); color: white; font-size: 0.6rem; padding: 2px 6px; border-radius: 12px; margin-left: 6px;">Host</span>
                            <?php endif; ?>
                        </span>
                        <span style="font-size: 0.6rem; color: var(--text-secondary);"><?php echo $msg['formatted_date']; ?></span>
                    </div>
                    <div style="font-size: 0.8125rem;"><?php echo sanitize($msg['message']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($existingConversation): ?>
            <div style="text-align: right; margin-bottom: 12px;">
                <a href="/gorwanda-plus/partner/stays/messages.php?conversation=<?php echo urlencode($existingConversation); ?>" 
                   class="text-decoration-none" style="font-size: 0.75rem; color: var(--primary);">
                    View full conversation <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Message Form -->
        <form method="POST" id="messageForm">
            <div style="margin-bottom: 12px;">
                <select name="subject" class="form-control" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 0.8125rem; background: white;">
                    <option value="Question about your property">📝 General question</option>
                    <option value="Booking inquiry">📅 Booking inquiry</option>
                    <option value="Special request">🎁 Special request</option>
                    <option value="Group booking">👥 Group booking</option>
                    <option value="Cancellation">❌ Cancellation</option>
                    <option value="Other">⋯ Other</option>
                </select>
            </div>
            <div style="margin-bottom: 12px;">
                <textarea name="message" rows="3" class="form-control" 
                          placeholder="Type your message here..." 
                          style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 0.8125rem; resize: vertical; background: white;"
                          required></textarea>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" name="send_message_to_owner" class="action-btn" style="flex: 1; justify-content: center; background: var(--primary); color: white; border: none; padding: 12px;">
                    <i class="bi bi-send"></i>
                    Send Message
                </button>
                <?php if ($stay['phone']): ?>
                <a href="tel:<?php echo $stay['phone']; ?>" class="action-btn" style="flex: 1; justify-content: center; padding: 12px;">
                    <i class="bi bi-telephone"></i>
                    Call
                </a>
                <?php endif; ?>
            </div>
        </form>
        
        <?php if (isset($messageSuccess)): ?>
        <div style="margin-top: 16px; padding: 12px; background: #e6f4ea; color: var(--success); border-radius: var(--radius-md); font-size: 0.8125rem; display: flex; align-items: center; gap: 8px; border: 1px solid #a7f3d0;">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $messageSuccess; ?>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- Not logged in - show login prompt -->
        <div style="text-align: center; padding: 16px; background: white; border-radius: var(--radius-md); margin-bottom: 16px; border: 1px dashed var(--border);">
            <p style="font-size: 0.8125rem; color: var(--text-secondary); margin-bottom: 12px;">
                <i class="bi bi-info-circle"></i>
                Please sign in to message the host
            </p>
            <div style="display: flex; gap: 8px;">
                <a href="/gorwanda-plus/login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="action-btn" style="flex: 1; justify-content: center; background: var(--primary); color: white; border: none; padding: 12px;">
                    Sign In
                </a>
                <a href="/gorwanda-plus/register.php" class="action-btn" style="flex: 1; justify-content: center; padding: 12px;">
                    Register
                </a>
            </div>
        </div>
        <?php if ($stay['phone']): ?>
        <a href="tel:<?php echo $stay['phone']; ?>" class="action-btn" style="width: 100%; justify-content: center; padding: 12px;">
            <i class="bi bi-telephone"></i>
            Call <?php echo sanitize($stay['phone']); ?>
        </a>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if ($stay['phone'] && isLoggedIn()): ?>
    <div style="margin-top: 12px; font-size: 0.75rem; color: var(--text-secondary); text-align: center; padding-top: 12px; border-top: 1px solid var(--border);">
        <i class="bi bi-telephone"></i> 
        <a href="tel:<?php echo $stay['phone']; ?>" style="color: var(--primary); text-decoration: none; font-weight: 500;"><?php echo sanitize($stay['phone']); ?></a>
        <span style="color: var(--text-secondary); margin-left: 8px;">• 24/7 support</span>
    </div>
    <?php endif; ?>
</div>
            
            <!-- Quick Stats Card -->
            <div class="booking-card" style="padding: 16px;">
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                    <div style="text-align: center;">
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);"><?php echo $stay['total_rooms']; ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-secondary);">Total rooms</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);"><?php echo $stay['restaurant_count']; ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-secondary);">Restaurants</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);"><?php echo date('Y', strtotime($stay['created_at'])); ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-secondary);">Established</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);"><?php echo $stay['review_count']; ?></div>
                        <div style="font-size: 0.7rem; color: var(--text-secondary);">Reviews</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Similar Properties -->
    <?php if (!empty($similarStays)): ?>
    <div style="margin-top: 48px;">
        <h2 class="card-title">Similar properties you might like</h2>
        <div class="similar-grid">
            <?php foreach ($similarStays as $similar): ?>
            <a href="detail.php?id=<?php echo $similar['stay_id']; ?>" class="similar-card">
                <div class="similar-image">
                    <img src="<?php echo getImageUrl($similar['main_image'] ?? '', 'stay'); ?>" 
                         alt="<?php echo sanitize($similar['stay_name']); ?>"
                         loading="lazy"
                         onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60'">
                </div>
                <div class="similar-content">
                    <div class="similar-title"><?php echo sanitize($similar['stay_name']); ?></div>
                    <div class="similar-location">
                        <i class="bi bi-geo-alt"></i>
                        <?php echo sanitize($similar['location_name'] ?? $similar['city'] ?? 'Rwanda'); ?>
                    </div>
                    <div class="similar-footer">
                        <?php if ($similar['avg_rating']): ?>
                        <span class="similar-rating"><?php echo number_format($similar['avg_rating'], 1); ?></span>
                        <?php endif; ?>
                        <div class="similar-price">
                            <span class="similar-price-value"><?php echo formatPrice($similar['min_price']); ?></span>
                            <span class="similar-price-unit">/night</span>
                        </div>
                    </div>
                    <?php if ($similar['restaurant_count'] > 0): ?>
                    <div style="font-size: 0.7rem; color: var(--text-secondary); margin-top: 4px;">
                        <i class="bi bi-shop"></i> <?php echo $similar['restaurant_count']; ?> restaurant<?php echo $similar['restaurant_count'] > 1 ? 's' : ''; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ============================================
// Enhanced Booking Logic with AJAX
// ============================================
let selectedRoomId = null;
let selectedRoomPrice = 0;
let selectedRoomName = '';
let dateUpdateTimeout;

function selectRoom(btn, roomId, price) {
    // Reset all buttons
    document.querySelectorAll('.room-select-btn').forEach(b => {
        b.textContent = 'Select room';
        b.classList.remove('selected');
    });
    
    // Select this room
    btn.textContent = 'Selected ✓';
    btn.classList.add('selected');
    
    // Get room name
    selectedRoomName = btn.closest('.room-card').querySelector('.room-name').textContent;
    selectedRoomId = roomId;
    selectedRoomPrice = price;
    
    // Update sidebar
    document.getElementById('selectedRoomName').textContent = selectedRoomName;
    document.getElementById('selectedRoomTotal').textContent = formatCurrency(selectedRoomPrice);
    document.getElementById('selectedRoomNights').textContent = 
        document.querySelector('input[name="checkin"]').value + ' to ' + 
        document.querySelector('input[name="checkout"]').value;
    document.getElementById('selectedRoomInfo').style.display = 'block';
    
    // Enable reserve button
    const reserveBtn = document.getElementById('reserveBtn');
    reserveBtn.disabled = false;
    reserveBtn.textContent = 'Reserve • ' + formatCurrency(selectedRoomPrice);
}

function proceedToBooking() {
    if (!selectedRoomId) {
        alert('Please select a room first');
        return;
    }
    
    const checkin = document.querySelector('input[name="checkin"]').value;
    const checkout = document.querySelector('input[name="checkout"]').value;
    const guests = document.getElementById('guestsInput').value;
    
    <?php if (!isLoggedIn()): ?>
        window.location.href = '/gorwanda-plus/login.php?redirect=' + encodeURIComponent(
            '/gorwanda-plus/stays/booking.php?id=<?php echo $id; ?>&room=' + selectedRoomId + 
            '&checkin=' + checkin + '&checkout=' + checkout + '&guests=' + guests
        );
    <?php else: ?>
        window.location.href = '/gorwanda-plus/stays/booking.php?id=<?php echo $id; ?>&room=' + selectedRoomId + 
            '&checkin=' + checkin + '&checkout=' + checkout + '&guests=' + guests;
    <?php endif; ?>
}

// ============================================
// Date and Guest Functions with AJAX Updates
// ============================================
function updateDates() {
    if (dateUpdateTimeout) {
        clearTimeout(dateUpdateTimeout);
    }
    
    dateUpdateTimeout = setTimeout(() => {
        const checkin = document.querySelector('input[name="checkin"]').value;
        const checkout = document.querySelector('input[name="checkout"]').value;
        
        if (checkin && checkout) {
            if (new Date(checkout) <= new Date(checkin)) {
                const nextDay = new Date(checkin);
                nextDay.setDate(nextDay.getDate() + 1);
                document.querySelector('input[name="checkout"]').value = nextDay.toISOString().split('T')[0];
            }
            
            updateRoomPrices();
        }
    }, 1000);
}

function updateRoomPrices() {
    const checkin = document.querySelector('input[name="checkin"]').value;
    const checkout = document.querySelector('input[name="checkout"]').value;
    const guests = document.getElementById('guestsInput').value;
    
    showLoadingIndicator();
    
    fetch(`/gorwanda-plus/api/get-room-prices.php?id=<?php echo $id; ?>&checkin=${checkin}&checkout=${checkout}&guests=${guests}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateRoomCards(data.rooms);
                updatePriceSummary(data.minPrice, data.nights);
            }
            hideLoadingIndicator();
        })
        .catch(error => {
            console.error('Error updating prices:', error);
            hideLoadingIndicator();
        });
}

function updateRoomCards(rooms) {
    rooms.forEach(room => {
        const roomCard = document.querySelector(`.room-card[data-room-id="${room.id}"]`);
        if (roomCard) {
            const priceElement = roomCard.querySelector('.room-price-value');
            const oldPriceElement = roomCard.querySelector('.old-price');
            
            if (room.has_discount) {
                if (!oldPriceElement) {
                    const priceDiv = priceElement.parentNode;
                    const oldDiv = document.createElement('div');
                    oldDiv.className = 'old-price';
                    oldDiv.textContent = formatCurrency(room.original_price);
                    priceDiv.insertBefore(oldDiv, priceElement);
                }
                priceElement.textContent = formatCurrency(room.discounted_price);
            } else {
                if (oldPriceElement) oldPriceElement.remove();
                priceElement.textContent = formatCurrency(room.price);
            }
            
            roomCard.dataset.roomPrice = room.total_price;
        }
    });
}

function updatePriceSummary(minPrice, nights) {
    const basePriceDisplay = document.getElementById('basePriceDisplay');
    const totalElement = document.getElementById('totalPrice');
    
    if (basePriceDisplay) {
        basePriceDisplay.textContent = formatCurrency(minPrice) + ' × ' + nights + ' nights';
    }
    if (totalElement) {
        totalElement.textContent = formatCurrency(minPrice * nights);
    }
}

function showLoadingIndicator() {
    document.querySelectorAll('.date-box').forEach(box => {
        box.style.position = 'relative';
        if (!box.querySelector('.loading-spinner')) {
            const spinner = document.createElement('div');
            spinner.className = 'loading-spinner';
            spinner.innerHTML = '<div class="spinner"></div>';
            spinner.style.cssText = 'position: absolute; right: 12px; top: 50%; transform: translateY(-50%);';
            box.appendChild(spinner);
        }
    });
}

function hideLoadingIndicator() {
    document.querySelectorAll('.loading-spinner').forEach(spinner => spinner.remove());
}

function toggleGuestsDropdown() {
    document.getElementById('guestsDropdown').classList.toggle('active');
}

function closeGuestsDropdown() {
    document.getElementById('guestsDropdown').classList.remove('active');
}

function changeGuests(delta) {
    const currentGuests = parseInt(document.getElementById('guestCount').textContent);
    const newGuests = Math.max(1, Math.min(8, currentGuests + delta));
    
    document.getElementById('guestCount').textContent = newGuests;
    document.getElementById('guestsInput').value = newGuests;
    document.getElementById('guestsDisplay').textContent = newGuests + ' adult' + (newGuests > 1 ? 's' : '');
    
    document.querySelector('.guest-counter button:first-child').disabled = newGuests <= 1;
    document.querySelector('.guest-counter button:last-child').disabled = newGuests >= 8;
    
    updateRoomPrices();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('guestsDropdown');
    const guestsBox = document.querySelector('.guests-box');
    
    if (guestsBox && !guestsBox.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

// ============================================
// Utility Functions
// ============================================
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}

function shareProperty() {
    if (navigator.share) {
        navigator.share({
            title: '<?php echo addslashes($stay['stay_name']); ?>',
            text: 'Check out this property on GoRwanda+',
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
}

function contactHost() {
    alert('Contact form would open here');
}

function openGallery(index) {
    alert('Gallery would open at image ' + (index + 1));
}

function openFullGallery() {
    alert('Full gallery would open');
}

// Set checkout min date based on checkin
document.querySelector('input[name="checkin"]')?.addEventListener('change', function() {
    const checkout = document.querySelector('input[name="checkout"]');
    const minCheckout = new Date(this.value);
    minCheckout.setDate(minCheckout.getDate() + 1);
    checkout.min = minCheckout.toISOString().split('T')[0];
    
    if (new Date(checkout.value) <= new Date(this.value)) {
        checkout.value = minCheckout.toISOString().split('T')[0];
    }
});

// Lazy loading for images
document.addEventListener('DOMContentLoaded', function() {
    const images = document.querySelectorAll('img[loading="lazy"]');
    
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.classList.add('loaded');
                    observer.unobserve(img);
                }
            });
        }, {
            rootMargin: '50px 0px'
        });
        
        images.forEach(img => imageObserver.observe(img));
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>