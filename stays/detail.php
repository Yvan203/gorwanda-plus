<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

$db = getDB();

// Get stay details
$stmt = $db->prepare("
    SELECT s.*, l.name as location_name, l.latitude, l.longitude,
           u.first_name as owner_name, u.phone as owner_phone,
           (SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count,
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as total_rooms
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    LEFT JOIN users u ON s.owner_id = u.user_id
    WHERE s.stay_id = ? AND s.is_active = 1 AND s.is_verified = 1
");
$stmt->execute([$id]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: /gorwanda-plus/search.php?type=stays');
    exit;
}

// Get all rooms
$stmt = $db->prepare("
    SELECT sr.*
    FROM stay_rooms sr
    WHERE sr.stay_id = ? AND sr.is_active = 1
    ORDER BY sr.base_price ASC
");
$stmt->execute([$id]);
$allRooms = $stmt->fetchAll();

// Get amenities
$amenities = [];
if ($stay['amenities']) {
    $amenityKeys = json_decode($stay['amenities'], true);
    if (is_array($amenityKeys) && !empty($amenityKeys)) {
        $placeholders = implode(',', array_fill(0, count($amenityKeys), '?'));
        $stmt = $db->prepare("SELECT amenity_name, amenity_icon FROM amenities WHERE amenity_key IN ($placeholders)");
        $stmt->execute($amenityKeys);
        $amenities = $stmt->fetchAll();
    }
}

// Get images
$images = [];
if ($stay['main_image']) {
    $images[] = $stay['main_image'];
}
if ($stay['images']) {
    $galleryImages = json_decode($stay['images'], true);
    if (is_array($galleryImages)) {
        $images = array_merge($images, $galleryImages);
    }
}
$images = array_unique($images);
if (empty($images)) $images = [''];

// Get reviews
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           DATE_FORMAT(r.created_at, '%M %Y') as month_year
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.stay_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

// Calculate review stats
$reviewStats = [
    'cleanliness' => 0,
    'service' => 0,
    'location' => 0,
    'value' => 0,
    'comfort' => 0,
    'facilities' => 0
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

// Get room types for filter
$roomTypes = array_unique(array_column($allRooms, 'room_name'));
$maxGuests = max(array_column($allRooms, 'max_guests'));

$pageTitle = $stay['stay_name'];
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* ===== BOOKING.COM PROPERTY DETAIL PAGE ===== */
    :root {
        --booking-blue: #003580;
        --booking-blue-light: #0071c2;
        --booking-yellow: #febb02;
        --booking-gray-100: #f5f5f5;
        --booking-gray-200: #e7e7e7;
        --booking-gray-500: #6b6b6b;
        --booking-gray-700: #1a1a1a;
        --booking-success: #008009;
        --booking-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        --booking-shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.12);
    }

    /* Hero Section */
    .property-hero {
        background: linear-gradient(135deg, var(--booking-blue) 0%, #001b4f 100%);
        color: white;
        padding: 32px 0;
        margin-bottom: 24px;
    }

    .property-hero h1 {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .property-hero .location {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 16px;
    }

    .hero-badges {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .hero-badge {
        background: rgba(255, 255, 255, 0.15);
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Gallery */
    .gallery-section {
        margin-bottom: 32px;
    }

    .gallery-main {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 4px;
        border-radius: 12px;
        overflow: hidden;
    }

    .gallery-main-item {
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }

    .gallery-main-item.main {
        grid-row: span 2;
    }

    .gallery-main-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .gallery-main-item:hover img {
        transform: scale(1.05);
    }

    .gallery-main-item .overlay {
        position: absolute;
        bottom: 16px;
        right: 16px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    /* Layout */
    .detail-layout {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 32px;
    }

    /* Info Cards */
    .info-card {
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .info-card:last-child {
        margin-bottom: 0;
    }

    .card-title {
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-title i {
        color: var(--booking-blue-light);
        font-size: 24px;
    }

    /* Highlights Grid */
    .highlights-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }

    .highlight-card {
        background: var(--booking-gray-100);
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        transition: transform 0.2s;
    }

    .highlight-card:hover {
        transform: translateY(-2px);
    }

    .highlight-icon {
        width: 48px;
        height: 48px;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        color: var(--booking-blue-light);
        font-size: 24px;
    }

    .highlight-label {
        font-size: 13px;
        color: var(--booking-gray-500);
        margin-bottom: 4px;
    }

    .highlight-value {
        font-weight: 700;
        font-size: 16px;
    }

    /* Amenities Grid */
    .amenities-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }

    .amenity-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
        border-radius: 8px;
        transition: background 0.2s;
    }

    .amenity-item:hover {
        background: var(--booking-gray-100);
    }

    .amenity-item i {
        width: 24px;
        color: var(--booking-success);
        font-size: 18px;
    }

    /* Room Filter Bar */
    .room-filter-bar {
        background: var(--booking-gray-100);
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
    }

    .filter-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .filter-btn {
        padding: 8px 16px;
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 30px;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .filter-btn:hover,
    .filter-btn.active {
        background: var(--booking-blue-light);
        border-color: var(--booking-blue-light);
        color: white;
    }

    .room-count {
        font-size: 13px;
        color: var(--booking-gray-500);
    }

    /* Rooms Grid */
    .rooms-grid {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .room-card {
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.2s;
        cursor: pointer;
    }

    .room-card:hover {
        box-shadow: var(--booking-shadow-lg);
        border-color: var(--booking-blue-light);
    }

    .room-card.selected {
        border: 2px solid var(--booking-blue-light);
        background: rgba(0, 113, 194, 0.02);
    }

    .room-content {
        display: flex;
        padding: 24px;
        gap: 24px;
        flex-wrap: wrap;
    }

    .room-info {
        flex: 2;
    }

    .room-name {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .room-description {
        font-size: 14px;
        color: var(--booking-gray-500);
        margin-bottom: 12px;
        line-height: 1.5;
    }

    .room-features {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 12px;
    }

    .room-feature {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: var(--booking-gray-500);
    }

    .room-feature i {
        color: var(--booking-success);
    }

    .room-amenities {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .room-amenity-tag {
        padding: 4px 12px;
        background: var(--booking-gray-100);
        border-radius: 20px;
        font-size: 12px;
        color: var(--booking-gray-500);
    }

    .room-price-box {
        flex: 1;
        min-width: 180px;
        text-align: right;
        border-left: 1px solid var(--booking-gray-200);
        padding-left: 24px;
    }

    .room-price-label {
        font-size: 12px;
        color: var(--booking-gray-500);
        margin-bottom: 4px;
    }

    .room-price-value {
        font-size: 28px;
        font-weight: 700;
        color: var(--booking-success);
        line-height: 1;
    }

    .room-price-unit {
        font-size: 12px;
        color: var(--booking-gray-500);
    }

    .room-select-indicator {
        margin-top: 16px;
        padding: 10px;
        background: var(--booking-blue-light);
        color: white;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        text-align: center;
    }

    /* Reviews Section */
    .reviews-summary {
        display: flex;
        gap: 32px;
        background: var(--booking-gray-100);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .score-circle {
        text-align: center;
    }

    .score-number {
        font-size: 48px;
        font-weight: 800;
        color: var(--booking-blue);
        line-height: 1;
    }

    .score-label {
        font-weight: 700;
        margin: 4px 0;
    }

    .score-count {
        font-size: 12px;
        color: var(--booking-gray-500);
    }

    .review-categories-grid {
        flex: 1;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .review-cat-item {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
    }

    .review-card {
        padding: 20px 0;
        border-bottom: 1px solid var(--booking-gray-200);
    }

    .review-card:last-child {
        border-bottom: none;
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .reviewer {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .reviewer-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-light));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 18px;
    }

    .reviewer-name {
        font-weight: 700;
        margin-bottom: 2px;
    }

    .reviewer-date {
        font-size: 12px;
        color: var(--booking-gray-500);
    }

    .review-rating {
        display: flex;
        gap: 2px;
    }

    .review-title {
        font-weight: 700;
        font-size: 16px;
        margin-bottom: 8px;
    }

    .review-text {
        font-size: 14px;
        color: var(--booking-gray-500);
        line-height: 1.5;
    }

    /* Booking Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .booking-card {
        background: white;
        border: 1px solid var(--booking-gray-200);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: var(--booking-shadow-lg);
    }

    .booking-card h3 {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
    }

    .date-selector {
        background: var(--booking-gray-100);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 12px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .date-selector:hover {
        background: #e8e8e8;
    }

    .date-label {
        font-size: 11px;
        color: var(--booking-gray-500);
        text-transform: uppercase;
    }

    .date-value {
        font-weight: 600;
        font-size: 14px;
        margin-top: 4px;
    }

    .guest-selector {
        background: var(--booking-gray-100);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 20px;
        cursor: pointer;
    }

    .selected-room-preview {
        background: var(--booking-gray-100);
        border-radius: 8px;
        padding: 16px;
        margin: 20px 0;
        border-left: 3px solid var(--booking-blue-light);
    }

    .reserve-btn {
        width: 100%;
        padding: 14px;
        background: var(--booking-blue-light);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
    }

    .reserve-btn:hover:not(:disabled) {
        background: var(--booking-blue);
    }

    .reserve-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .security-note {
        text-align: center;
        font-size: 12px;
        color: var(--booking-gray-500);
        margin-top: 16px;
    }

    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .modal-content {
        max-width: 90%;
        max-height: 90%;
    }

    .modal-content img {
        max-width: 100%;
        max-height: 90vh;
        object-fit: contain;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .detail-layout {
            grid-template-columns: 1fr;
        }

        .booking-sidebar {
            position: static;
            order: -1;
        }

        .gallery-main {
            grid-template-columns: 1fr;
        }

        .gallery-main-item:not(.main) {
            display: none;
        }

        .highlights-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .amenities-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .property-hero h1 {
            font-size: 24px;
        }

        .highlights-grid,
        .amenities-grid {
            grid-template-columns: 1fr;
        }

        .room-content {
            flex-direction: column;
        }

        .room-price-box {
            text-align: left;
            border-left: none;
            padding-left: 0;
        }

        .reviews-summary {
            flex-direction: column;
        }

        .review-categories-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Hero Section -->
<div class="property-hero">
    <div class="container">
        <h1><?php echo sanitize($stay['stay_name']); ?></h1>
        <div class="location">
            <i class="bi bi-geo-alt-fill"></i>
            <span><?php echo sanitize($stay['address']); ?>, <?php echo sanitize($stay['city'] ?? $stay['location_name'] ?? 'Rwanda'); ?></span>
        </div>
        <div class="hero-badges">
            <?php if ($stay['star_rating'] > 0): ?>
                <div class="hero-badge">
                    <i class="bi bi-star-fill"></i>
                    <span><?php echo $stay['star_rating']; ?>-star property</span>
                </div>
            <?php endif; ?>
            <?php if ($stay['avg_rating'] > 0): ?>
                <div class="hero-badge">
                    <i class="bi bi-chat-text-fill"></i>
                    <span><?php echo number_format($stay['avg_rating'], 1); ?>/10 · <?php echo $stay['review_count']; ?> reviews</span>
                </div>
            <?php endif; ?>
            <div class="hero-badge">
                <i class="bi bi-building"></i>
                <span><?php echo count($allRooms); ?> room types</span>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <!-- Gallery -->
    <div class="gallery-section">
        <div class="gallery-main">
            <?php foreach ($images as $index => $img): ?>
                <?php if ($index < 5): ?>
                    <div class="gallery-main-item <?php echo $index === 0 ? 'main' : ''; ?>" style="height: <?php echo $index === 0 ? '400px' : '198px'; ?>;" onclick="openGallery(<?php echo $index; ?>)">
                        <img src="<?php echo getImageUrl($img, 'stay'); ?>" alt="<?php echo sanitize($stay['stay_name']); ?>">
                        <?php if ($index === 0 && count($images) > 5): ?>
                            <div class="overlay">
                                <i class="bi bi-images"></i>
                                <span>+<?php echo count($images) - 5; ?> photos</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="detail-layout">
        <!-- Left Column -->
        <div class="main-content">
            <!-- Highlights -->
            <div class="info-card">
                <h2 class="card-title">
                    <i class="bi bi-stars"></i>
                    Property highlights
                </h2>
                <div class="highlights-grid">
                    <div class="highlight-card">
                        <div class="highlight-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="highlight-label">Check-in</div>
                        <div class="highlight-value"><?php echo date('h:i A', strtotime($stay['check_in_time'])); ?></div>
                    </div>
                    <div class="highlight-card">
                        <div class="highlight-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="highlight-label">Check-out</div>
                        <div class="highlight-value"><?php echo date('h:i A', strtotime($stay['check_out_time'])); ?></div>
                    </div>
                    <div class="highlight-card">
                        <div class="highlight-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="highlight-label">Max guests</div>
                        <div class="highlight-value">Up to <?php echo $maxGuests; ?> guests</div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="info-card">
                <h2 class="card-title">
                    <i class="bi bi-info-circle"></i>
                    About this property
                </h2>
                <p style="line-height: 1.6; color: var(--booking-gray-500);">
                    <?php echo nl2br(sanitize($stay['description'])); ?>
                </p>
            </div>

            <!-- Amenities -->
            <?php if (!empty($amenities)): ?>
                <div class="info-card">
                    <h2 class="card-title">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        Popular amenities
                    </h2>
                    <div class="amenities-grid">
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="amenity-item">
                                <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                                <span><?php echo sanitize($amenity['amenity_name']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Rooms Section with Filter -->
            <div class="info-card">
                <h2 class="card-title">
                    <i class="bi bi-door-open"></i>
                    Available rooms
                </h2>

                <!-- Room Filter Bar -->
                <div class="room-filter-bar">
                    <div class="filter-buttons">
                        <button class="filter-btn active" data-filter="all">All rooms</button>
                        <?php foreach (array_slice($roomTypes, 0, 5) as $type): ?>
                            <button class="filter-btn" data-filter="<?php echo strtolower(str_replace(' ', '-', $type)); ?>">
                                <?php echo sanitize($type); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="room-count" id="roomCount"><?php echo count($allRooms); ?> rooms available</div>
                </div>

                <!-- Rooms Grid -->
                <div class="rooms-grid" id="roomsGrid">
                    <?php foreach ($allRooms as $index => $room):
                        $roomAmenities = json_decode($room['room_amenities'] ?? '[]', true);
                    ?>
                        <div class="room-card" data-room-id="<?php echo $room['room_id']; ?>" data-room-type="<?php echo strtolower(str_replace(' ', '-', $room['room_name'])); ?>">
                            <div class="room-content">
                                <div class="room-info">
                                    <div class="room-name"><?php echo sanitize($room['room_name']); ?></div>
                                    <?php if ($room['description']): ?>
                                        <div class="room-description"><?php echo sanitize(substr($room['description'], 0, 120)); ?></div>
                                    <?php endif; ?>
                                    <div class="room-features">
                                        <?php if ($room['size_sqm']): ?>
                                            <span class="room-feature"><i class="bi bi-rulers"></i> <?php echo $room['size_sqm']; ?> m²</span>
                                        <?php endif; ?>
                                        <span class="room-feature"><i class="bi bi-bed"></i> <?php echo $room['bed_configuration'] ?: 'Queen bed'; ?></span>
                                        <span class="room-feature"><i class="bi bi-people"></i> Max <?php echo $room['max_guests']; ?> guests</span>
                                        <span class="room-feature"><i class="bi bi-door-closed"></i> <?php echo $room['num_rooms_available']; ?> left</span>
                                    </div>
                                    <?php if (!empty($roomAmenities)): ?>
                                        <div class="room-amenities">
                                            <?php foreach (array_slice($roomAmenities, 0, 4) as $amen): ?>
                                                <span class="room-amenity-tag">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                    <?php echo ucfirst(str_replace('_', ' ', $amen)); ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($roomAmenities) > 4): ?>
                                                <span class="room-amenity-tag">+<?php echo count($roomAmenities) - 4; ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="room-price-box">
                                    <div class="room-price-label">Price per night</div>
                                    <div class="room-price-value"><?php echo formatPrice($room['base_price']); ?></div>
                                    <div class="room-price-unit">includes taxes & fees</div>
                                    <div class="room-select-indicator" onclick="selectRoom(<?php echo $room['room_id']; ?>, '<?php echo addslashes($room['room_name']); ?>', <?php echo $room['base_price']; ?>, event)">
                                        Select room
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Reviews -->
            <?php if (!empty($reviews)): ?>
                <div class="info-card">
                    <h2 class="card-title">
                        <i class="bi bi-star-fill" style="color: #febb02;"></i>
                        Guest reviews
                    </h2>

                    <?php if ($stay['avg_rating'] > 0): ?>
                        <div class="reviews-summary">
                            <div class="score-circle">
                                <div class="score-number"><?php echo number_format($stay['avg_rating'], 1); ?></div>
                                <div class="score-label"><?php echo getReviewLabel($stay['avg_rating'])[0]; ?></div>
                                <div class="score-count"><?php echo $stay['review_count']; ?> reviews</div>
                            </div>
                            <div class="review-categories-grid">
                                <?php foreach ($reviewStats as $key => $value): ?>
                                    <?php if ($value > 0): ?>
                                        <div class="review-cat-item">
                                            <span><?php echo ucfirst($key); ?></span>
                                            <span style="font-weight: 700;"><?php echo $value; ?>/10</span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach (array_slice($reviews, 0, 5) as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="reviewer">
                                    <div class="reviewer-avatar">
                                        <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="reviewer-name"><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?></div>
                                        <div class="reviewer-date"><?php echo $review['month_year']; ?></div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>" style="font-size: 11px; color: #febb02;"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php if ($review['title']): ?>
                                <div class="review-title"><?php echo sanitize($review['title']); ?></div>
                            <?php endif; ?>
                            <div class="review-text"><?php echo sanitize($review['comment']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column - Booking Sidebar -->
        <div class="booking-sidebar">
            <div class="booking-card">
                <h3>Book your stay</h3>

                <!-- Date Selection -->
                <div class="date-selector" onclick="this.querySelector('input').showPicker()">
                    <div class="date-label">Check-in</div>
                    <input type="date" id="checkinDate" style="border: none; background: transparent; font-weight: 600; width: 100%;" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="date-selector" onclick="this.querySelector('input').showPicker()">
                    <div class="date-label">Check-out</div>
                    <input type="date" id="checkoutDate" style="border: none; background: transparent; font-weight: 600; width: 100%;" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                </div>

                <!-- Guest Selection -->
                <div class="guest-selector" onclick="toggleGuestDropdown()">
                    <div class="date-label">Guests</div>
                    <div class="date-value" id="guestDisplay">2 adults</div>
                    <div id="guestDropdown" style="display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--booking-gray-200);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <span>Adults</span>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <button onclick="changeGuests(-1)" style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--booking-gray-200); background: white; cursor: pointer;">-</button>
                                <span id="adultCount">2</span>
                                <button onclick="changeGuests(1)" style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--booking-gray-200); background: white; cursor: pointer;">+</button>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Children</span>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <button onclick="changeChildren(-1)" style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--booking-gray-200); background: white; cursor: pointer;">-</button>
                                <span id="childCount">0</span>
                                <button onclick="changeChildren(1)" style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--booking-gray-200); background: white; cursor: pointer;">+</button>
                            </div>
                        </div>
                        <button onclick="closeGuestDropdown()" style="width: 100%; margin-top: 12px; padding: 8px; background: var(--booking-blue-light); color: white; border: none; border-radius: 4px; cursor: pointer;">Done</button>
                    </div>
                </div>

                <!-- Selected Room Preview -->
                <div id="selectedRoomPreview" style="display: none;" class="selected-room-preview">
                    <div style="font-size: 12px; color: var(--booking-gray-500);">Selected room</div>
                    <div style="font-weight: 700; font-size: 16px;" id="previewRoomName"></div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--booking-success); margin-top: 8px;" id="previewRoomPrice"></div>
                </div>

                <!-- Reserve Button -->
                <button class="reserve-btn" id="reserveBtn" onclick="proceedToBooking()" disabled>
                    Select a room to continue
                </button>

                <div class="security-note">
                    <i class="bi bi-shield-check"></i> You won't be charged yet
                </div>
            </div>

            <!-- Contact Card -->
            <div class="booking-card" style="background: var(--booking-gray-100);">
                <h3 style="font-size: 18px;">Have questions?</h3>
                <p style="font-size: 13px; color: var(--booking-gray-500); margin-bottom: 16px;">
                    Contact the property directly.
                </p>
                <?php if ($stay['phone']): ?>
                    <a href="tel:<?php echo $stay['phone']; ?>" style="display: flex; align-items: center; gap: 8px; text-decoration: none; color: var(--booking-blue-light); font-weight: 600;">
                        <i class="bi bi-telephone-fill"></i>
                        <?php echo sanitize($stay['phone']); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Gallery Modal -->
<div id="galleryModal" class="modal-overlay" onclick="closeGallery()">
    <div class="modal-content">
        <img id="modalImage" src="">
    </div>
</div>

<script>
    // Room selection
    let selectedRoomId = null;
    let selectedRoomPrice = 0;
    let selectedRoomName = '';
    let selectedRoomTotal = 0;

    function selectRoom(roomId, roomName, price, event) {
        event.stopPropagation();

        // Reset all room cards
        document.querySelectorAll('.room-card').forEach(card => {
            card.classList.remove('selected');
            const indicator = card.querySelector('.room-select-indicator');
            if (indicator) {
                indicator.textContent = 'Select room';
                indicator.style.background = '#0071c2';
            }
        });

        // Select current room
        const roomCard = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
        roomCard.classList.add('selected');
        const indicator = roomCard.querySelector('.room-select-indicator');
        indicator.textContent = 'Selected ✓';
        indicator.style.background = '#008009';

        selectedRoomId = roomId;
        selectedRoomPrice = price;
        selectedRoomName = roomName;

        // Update preview
        const nights = calculateNights();
        selectedRoomTotal = price * nights;

        document.getElementById('previewRoomName').textContent = roomName;
        document.getElementById('previewRoomPrice').textContent = 'RWF ' + selectedRoomTotal.toLocaleString();
        document.getElementById('selectedRoomPreview').style.display = 'block';

        // Enable reserve button
        const reserveBtn = document.getElementById('reserveBtn');
        reserveBtn.disabled = false;
        reserveBtn.textContent = 'Reserve • RWF ' + selectedRoomTotal.toLocaleString();
    }

    function calculateNights() {
        const checkin = document.getElementById('checkinDate').value;
        const checkout = document.getElementById('checkoutDate').value;
        const diffTime = Math.abs(new Date(checkout) - new Date(checkin));
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }

    function proceedToBooking() {
        if (!selectedRoomId) {
            alert('Please select a room first');
            return;
        }

        const checkin = document.getElementById('checkinDate').value;
        const checkout = document.getElementById('checkoutDate').value;
        const adults = document.getElementById('adultCount').textContent;
        const children = document.getElementById('childCount').textContent;
        const guests = parseInt(adults) + parseInt(children);

        window.location.href = 'booking.php?id=<?php echo $id; ?>&room=' + selectedRoomId +
            '&checkin=' + checkin + '&checkout=' + checkout + '&guests=' + guests;
    }

    // Room filtering
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.filter;

            // Update active state
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Filter rooms
            const rooms = document.querySelectorAll('.room-card');
            let visibleCount = 0;

            rooms.forEach(room => {
                if (filter === 'all') {
                    room.style.display = 'block';
                    visibleCount++;
                } else {
                    const roomType = room.dataset.roomType;
                    if (roomType === filter) {
                        room.style.display = 'block';
                        visibleCount++;
                    } else {
                        room.style.display = 'none';
                    }
                }
            });

            document.getElementById('roomCount').textContent = visibleCount + ' rooms available';
        });
    });

    // Guest selection
    let adultCount = 2;
    let childCount = 0;

    function toggleGuestDropdown() {
        const dropdown = document.getElementById('guestDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    }

    function closeGuestDropdown() {
        document.getElementById('guestDropdown').style.display = 'none';
    }

    function changeGuests(delta) {
        adultCount = Math.max(1, Math.min(8, adultCount + delta));
        document.getElementById('adultCount').textContent = adultCount;
        document.getElementById('guestDisplay').textContent = adultCount + ' adult' + (adultCount > 1 ? 's' : '') + (childCount > 0 ? ', ' + childCount + ' child' + (childCount > 1 ? 'ren' : '') : '');
    }

    function changeChildren(delta) {
        childCount = Math.max(0, Math.min(6, childCount + delta));
        document.getElementById('childCount').textContent = childCount;
        document.getElementById('guestDisplay').textContent = adultCount + ' adult' + (adultCount > 1 ? 's' : '') + (childCount > 0 ? ', ' + childCount + ' child' + (childCount > 1 ? 'ren' : '') : '');
    }

    // Date calculations
    document.getElementById('checkinDate').addEventListener('change', function() {
        const checkout = document.getElementById('checkoutDate');
        if (new Date(checkout.value) <= new Date(this.value)) {
            const nextDay = new Date(this.value);
            nextDay.setDate(nextDay.getDate() + 1);
            checkout.value = nextDay.toISOString().split('T')[0];
        }
        updateSelectedRoomPrice();
    });

    document.getElementById('checkoutDate').addEventListener('change', function() {
        updateSelectedRoomPrice();
    });

    function updateSelectedRoomPrice() {
        if (selectedRoomId) {
            const nights = calculateNights();
            selectedRoomTotal = selectedRoomPrice * nights;
            document.getElementById('previewRoomPrice').textContent = 'RWF ' + selectedRoomTotal.toLocaleString();
            const reserveBtn = document.getElementById('reserveBtn');
            reserveBtn.textContent = 'Reserve • RWF ' + selectedRoomTotal.toLocaleString();
        }
    }

    // Gallery
    function openGallery(index) {
        const images = <?php echo json_encode(array_map(function ($img) {
                            return getImageUrl($img, 'stay');
                        }, $images)); ?>;
        document.getElementById('modalImage').src = images[index];
        document.getElementById('galleryModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeGallery() {
        document.getElementById('galleryModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const guestSelector = document.querySelector('.guest-selector');
        const dropdown = document.getElementById('guestDropdown');
        if (guestSelector && !guestSelector.contains(event.target)) {
            dropdown.style.display = 'none';
        }
    });

    // Set min dates for date inputs
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('checkinDate').min = today;
    document.getElementById('checkoutDate').min = today;
</script>

<?php require_once '../includes/footer.php'; ?>