<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

$currentLang = getCurrentLanguage();
$currentCurrency = getCurrentCurrency();

$db = getDB();

// Get dates from URL
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('+1 day'));
$guests = isset($_GET['guests']) ? intval($_GET['guests']) : 2;

// Get attraction details
$stmt = $db->prepare("
    SELECT a.*, c.name as category_name, c.icon as category_icon, l.name as location_name,
           u.first_name as owner_name, u.phone as owner_phone,
           (SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count
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

// Get pricing tiers
$stmt = $db->prepare("
    SELECT t.*,
           (SELECT COUNT(*) FROM bookings b 
            WHERE b.attraction_tier_id = t.tier_id 
            AND b.status IN ('confirmed', 'completed')
            AND b.experience_date >= CURDATE()) as upcoming_bookings
    FROM attraction_tiers t
    WHERE t.attraction_id = ? AND t.is_active = 1
    ORDER BY t.base_price ASC
");
$stmt->execute([$id]);
$tiers = $stmt->fetchAll();

// Get images
$images = [];
if ($attraction['main_image']) {
    $images[] = $attraction['main_image'];
}
if ($attraction['gallery_images']) {
    $galleryImages = json_decode($attraction['gallery_images'], true);
    if (is_array($galleryImages)) {
        $images = array_merge($images, $galleryImages);
    }
}
$images = array_unique($images);
if (empty($images)) $images = [''];

// Get included/excluded items
$includedItems = $attraction['included_items'] ? json_decode($attraction['included_items'], true) : [];
$excludedItems = $attraction['excluded_items'] ? json_decode($attraction['excluded_items'], true) : [];
$whatToBring = $attraction['what_to_bring'] ? json_decode($attraction['what_to_bring'], true) : [];
$startTimes = $attraction['start_times'] ? json_decode($attraction['start_times'], true) : [];
$guideLanguages = $attraction['guide_languages'] ? json_decode($attraction['guide_languages'], true) : [];

// Get reviews
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image,
           DATE_FORMAT(r.created_at, '%M %Y') as month_year
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE r.attraction_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->execute([$id]);
$reviews = $stmt->fetchAll();

// Calculate review stats
$reviewStats = [];
if (!empty($reviews)) {
    $stats = ['excellent' => 0, 'good' => 0, 'average' => 0, 'poor' => 0];
    foreach ($reviews as $review) {
        if ($review['overall_rating'] >= 8) $stats['excellent']++;
        elseif ($review['overall_rating'] >= 6) $stats['good']++;
        elseif ($review['overall_rating'] >= 4) $stats['average']++;
        else $stats['poor']++;
    }
    $reviewStats = $stats;
}

// Duration formatting
$durationHours = floor($attraction['duration_minutes'] / 60);
$durationMinutes = $attraction['duration_minutes'] % 60;
$durationText = '';
if ($durationHours > 0) $durationText .= $durationHours . 'h';
if ($durationMinutes > 0) $durationText .= ($durationHours > 0 ? ' ' : '') . $durationMinutes . 'm';
if (!$durationText) $durationText = '—';

// Difficulty level
$difficultyColors = [
    'easy' => ['bg' => '#d4edda', 'color' => '#155724', 'icon' => 'bi-emoji-smile'],
    'moderate' => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => 'bi-arrow-left-right'],
    'challenging' => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => 'bi-chevron-double-up']
];
$difficultyInfo = $difficultyColors[$attraction['difficulty_level']] ?? $difficultyColors['moderate'];

// Calculate tax rate
$taxRate = getTaxRate();

$pageTitle = $attraction['attraction_name'] . ' - Experience';
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* Attraction Detail Page - Booking.com Style */
    :root {
        --bkg-blue: #003580;
        --bkg-blue-light: #0071c2;
        --bkg-yellow: #febb02;
        --bkg-gray-100: #f5f5f5;
        --bkg-gray-200: #e7e7e7;
        --bkg-gray-500: #6b6b6b;
        --bkg-gray-700: #1a1a1a;
        --bkg-success: #008009;
        --bkg-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        --bkg-shadow-lg: 0 4px 20px rgba(0, 0, 0, 0.12);
    }

    .attraction-detail {
        background: #f5f7fa;
        min-height: 100vh;
        padding: 24px 0;
    }

    /* Breadcrumb */
    .breadcrumb-bar {
        margin-bottom: 20px;
        font-size: 12px;
        color: var(--bkg-gray-500);
    }

    .breadcrumb-bar a {
        color: var(--bkg-blue-light);
        text-decoration: none;
    }

    .breadcrumb-bar a:hover {
        text-decoration: underline;
    }

    /* Header Section */
    .attraction-header {
        background: white;
        border-radius: 8px;
        padding: 20px 24px;
        margin-bottom: 20px;
        border: 1px solid var(--bkg-gray-200);
        box-shadow: var(--bkg-shadow);
    }

    .attraction-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 6px;
    }

    .attraction-location {
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .attraction-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 8px;
    }

    .badge-sm {
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 20px;
        background: var(--bkg-gray-100);
        color: var(--bkg-gray-500);
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .badge-sm i {
        font-size: 11px;
    }

    /* Gallery */
    .gallery-section {
        margin-bottom: 20px;
    }

    .gallery-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 4px;
        border-radius: 8px;
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
        transition: transform 0.3s;
    }

    .gallery-item:hover img {
        transform: scale(1.03);
    }

    .gallery-overlay {
        position: absolute;
        bottom: 12px;
        right: 12px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }

    /* Main Content Layout */
    .detail-layout {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 24px;
    }

    /* Main Content Cards */
    .info-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .info-card:last-child {
        margin-bottom: 0;
    }

    .card-title {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--bkg-gray-700);
    }

    .card-title i {
        color: var(--bkg-blue-light);
        font-size: 16px;
    }

    /* Highlights Grid */
    .highlights-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .highlight-item {
        text-align: center;
        padding: 12px;
        background: var(--bkg-gray-100);
        border-radius: 6px;
    }

    .highlight-icon {
        font-size: 18px;
        color: var(--bkg-blue-light);
        margin-bottom: 4px;
    }

    .highlight-value {
        font-size: 13px;
        font-weight: 700;
        color: var(--bkg-gray-700);
    }

    .highlight-label {
        font-size: 9px;
        color: var(--bkg-gray-500);
        text-transform: uppercase;
    }

    /* Description */
    .description-text {
        font-size: 12px;
        line-height: 1.6;
        color: var(--bkg-gray-500);
    }

    /* Tiers Section */
    .tiers-container {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .tier-card {
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 16px;
        transition: all 0.2s;
        cursor: pointer;
    }

    .tier-card:hover {
        border-color: var(--bkg-blue-light);
        box-shadow: var(--bkg-shadow);
    }

    .tier-card.selected {
        border: 2px solid var(--bkg-blue-light);
        background: rgba(0, 113, 194, 0.02);
    }

    .tier-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .tier-name {
        font-size: 15px;
        font-weight: 700;
        color: var(--bkg-gray-700);
    }

    .tier-price {
        text-align: right;
    }

    .tier-price-value {
        font-size: 18px;
        font-weight: 700;
        color: var(--bkg-success);
        line-height: 1.2;
    }

    .tier-price-unit {
        font-size: 10px;
        color: var(--bkg-gray-500);
    }

    .tier-description {
        font-size: 11px;
        color: var(--bkg-gray-500);
        margin-bottom: 8px;
    }

    .tier-features {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .tier-feature {
        font-size: 10px;
        padding: 2px 6px;
        background: var(--bkg-gray-100);
        border-radius: 4px;
        color: var(--bkg-gray-500);
    }

    .tier-select-btn {
        margin-top: 12px;
        padding: 8px;
        width: 100%;
        background: var(--bkg-blue-light);
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }

    .tier-select-btn:hover {
        background: #005fa3;
    }

    .tier-card.selected .tier-select-btn {
        background: var(--bkg-success);
    }

    /* Included/Excluded Lists */
    .items-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .item-tag {
        font-size: 11px;
        padding: 4px 10px;
        background: var(--bkg-gray-100);
        border-radius: 20px;
        color: var(--bkg-gray-500);
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .item-tag.included i {
        color: var(--bkg-success);
    }

    .item-tag.excluded i {
        color: #c41c1c;
    }

    /* Start Times */
    .times-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .time-tag {
        font-size: 12px;
        padding: 4px 12px;
        background: var(--bkg-gray-100);
        border-radius: 20px;
        color: var(--bkg-gray-500);
    }

    /* Languages */
    .lang-tag {
        font-size: 11px;
        padding: 4px 10px;
        background: var(--bkg-gray-100);
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Reviews */
    .reviews-summary {
        display: flex;
        gap: 20px;
        padding: 16px;
        background: var(--bkg-gray-100);
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .rating-circle {
        text-align: center;
        min-width: 80px;
    }

    .rating-number {
        font-size: 28px;
        font-weight: 800;
        color: var(--bkg-blue);
        line-height: 1;
    }

    .rating-label {
        font-size: 12px;
        font-weight: 600;
    }

    .rating-bars {
        flex: 1;
    }

    .rating-bar-item {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 10px;
    }

    .rating-bar-label {
        width: 50px;
        color: var(--bkg-gray-500);
    }

    .rating-bar {
        flex: 1;
        height: 4px;
        background: var(--bkg-gray-200);
        border-radius: 2px;
        overflow: hidden;
    }

    .rating-bar-fill {
        height: 100%;
        background: var(--bkg-blue-light);
        border-radius: 2px;
    }

    .rating-bar-count {
        width: 30px;
        text-align: right;
        color: var(--bkg-gray-500);
    }

    .review-card {
        padding: 14px 0;
        border-bottom: 1px solid var(--bkg-gray-200);
    }

    .review-card:last-child {
        border-bottom: none;
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .reviewer {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .reviewer-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--bkg-blue);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 600;
    }

    .reviewer-name {
        font-size: 12px;
        font-weight: 600;
    }

    .review-date {
        font-size: 9px;
        color: var(--bkg-gray-500);
    }

    .review-rating {
        display: flex;
        gap: 2px;
    }

    .review-rating i {
        font-size: 10px;
        color: #febb02;
    }

    .review-title {
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .review-text {
        font-size: 11px;
        color: var(--bkg-gray-500);
        line-height: 1.5;
    }

    /* Booking Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .booking-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: var(--bkg-shadow-lg);
    }

    .booking-card h3 {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 16px;
    }

    /* Date/Guests Selectors */
    .date-selector {
        background: var(--bkg-gray-100);
        border-radius: 6px;
        padding: 10px 12px;
        margin-bottom: 12px;
        cursor: pointer;
    }

    .date-label {
        font-size: 10px;
        color: var(--bkg-gray-500);
        text-transform: uppercase;
    }

    .date-value {
        font-size: 13px;
        font-weight: 600;
        margin-top: 2px;
    }

    .guest-selector {
        background: var(--bkg-gray-100);
        border-radius: 6px;
        padding: 10px 12px;
        margin-bottom: 16px;
        cursor: pointer;
        position: relative;
    }

    .guest-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 6px;
        padding: 12px;
        margin-top: 4px;
        box-shadow: var(--bkg-shadow-lg);
        z-index: 100;
        display: none;
    }

    .guest-dropdown.active {
        display: block;
    }

    .guest-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .guest-counter {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .guest-counter button {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 1px solid var(--bkg-gray-200);
        background: white;
        cursor: pointer;
        font-weight: 700;
    }

    .guest-counter button:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    /* Selected Tier Preview */
    .selected-tier-preview {
        background: var(--bkg-gray-100);
        border-radius: 6px;
        padding: 12px;
        margin: 16px 0;
        border-left: 3px solid var(--bkg-blue-light);
    }

    /* Price Breakdown */
    .price-breakdown {
        margin: 16px 0;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        padding: 6px 0;
    }

    .price-row.total {
        border-top: 1px solid var(--bkg-gray-200);
        margin-top: 6px;
        padding-top: 10px;
        font-weight: 700;
        font-size: 14px;
    }

    .price-row.tax {
        font-size: 10px;
        color: var(--bkg-gray-500);
    }

    .btn-reserve {
        width: 100%;
        padding: 12px;
        background: var(--bkg-blue-light);
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
        margin-top: 16px;
    }

    .btn-reserve:hover:not(:disabled) {
        background: #005fa3;
    }

    .btn-reserve:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .security-note {
        text-align: center;
        font-size: 10px;
        color: var(--bkg-gray-500);
        margin-top: 12px;
    }

    /* Cancel Policy */
    .cancel-policy {
        font-size: 10px;
        color: var(--bkg-gray-500);
        margin-top: 12px;
        padding: 10px;
        background: var(--bkg-gray-100);
        border-radius: 6px;
        text-align: center;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .detail-layout {
            grid-template-columns: 1fr;
        }

        .booking-sidebar {
            position: static;
            margin-top: 20px;
        }

        .gallery-grid {
            grid-template-columns: 1fr;
        }

        .gallery-item:not(.main) {
            display: none;
        }

        .highlights-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .attraction-title {
            font-size: 18px;
        }

        .highlights-grid {
            grid-template-columns: 1fr;
        }

        .tier-header {
            flex-direction: column;
            gap: 8px;
        }

        .tier-price {
            text-align: left;
        }
    }
</style>

<div class="attraction-detail">
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb-bar">
            <a href="/gorwanda-plus/"><?php echo tr('home', 'Home'); ?></a> &gt;
            <a href="/gorwanda-plus/?type=attractions"><?php echo tr('experiences', 'Experiences'); ?></a> &gt;
            <span><?php echo sanitize($attraction['attraction_name']); ?></span>
        </div>

        <!-- Header -->
        <div class="attraction-header">
            <h1 class="attraction-title"><?php echo sanitize($attraction['attraction_name']); ?></h1>
            <div class="attraction-location">
                <i class="bi bi-geo-alt"></i>
                <?php echo sanitize($attraction['location_name'] ?: 'Rwanda'); ?>
                <?php if ($attraction['address']): ?> • <?php echo sanitize($attraction['address']); ?><?php endif; ?>
            </div>
            <div class="attraction-badges">
                <?php if ($attraction['is_verified']): ?>
                    <span class="badge-sm"><i class="bi bi-patch-check-fill"></i> <?php echo tr('verified', 'Verified'); ?></span>
                <?php endif; ?>
                <?php if ($attraction['instant_confirmation']): ?>
                    <span class="badge-sm"><i class="bi bi-lightning-charge"></i> <?php echo tr('instant', 'Instant'); ?></span>
                <?php endif; ?>
                <?php if ($attraction['free_cancellation']): ?>
                    <span class="badge-sm"><i class="bi bi-x-circle"></i> <?php echo tr('free_cancellation', 'Free cancellation'); ?></span>
                <?php endif; ?>
                <?php if ($attraction['avg_rating'] > 0): ?>
                    <span class="badge-sm"><i class="bi bi-star-fill" style="color: #febb02;"></i> <?php echo number_format($attraction['avg_rating'], 1); ?> (<?php echo $attraction['review_count']; ?> <?php echo tr('reviews', 'reviews'); ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Gallery -->
        <div class="gallery-section">
            <div class="gallery-grid">
                <?php foreach ($images as $index => $img): ?>
                    <?php if ($index < 5): ?>
                        <div class="gallery-item <?php echo $index === 0 ? 'main' : ''; ?>" style="height: <?php echo $index === 0 ? '360px' : '178px'; ?>;">
                            <img src="<?php echo getImageUrl($img, 'attraction'); ?>"
                                alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                                loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">
                            <?php if ($index === 0 && count($images) > 1): ?>
                                <div class="gallery-overlay">
                                    <i class="bi bi-images"></i>
                                    <span>+<?php echo count($images) - 1; ?> <?php echo tr('photos', 'photos'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main Layout -->
        <div class="detail-layout">
            <!-- Left Column - Main Content -->
            <div class="main-content">
                <!-- Highlights -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-stars"></i> <?php echo tr('key_details', 'Key details'); ?></h2>
                    <div class="highlights-grid">
                        <div class="highlight-item">
                            <div class="highlight-icon"><i class="bi bi-clock"></i></div>
                            <div class="highlight-value"><?php echo $durationText; ?></div>
                            <div class="highlight-label"><?php echo tr('duration', 'Duration'); ?></div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon"><i class="bi bi-people"></i></div>
                            <div class="highlight-value"><?php echo $attraction['max_group_size'] ?: '—'; ?></div>
                            <div class="highlight-label"><?php echo tr('max_group', 'Max group'); ?></div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon"><i class="bi bi-activity"></i></div>
                            <div class="highlight-value" style="color: <?php echo $difficultyInfo['color']; ?>;">
                                <i class="bi <?php echo $difficultyInfo['icon']; ?>"></i>
                                <?php echo tr($attraction['difficulty_level'], ucfirst($attraction['difficulty_level'])); ?>
                            </div>
                            <div class="highlight-label"><?php echo tr('difficulty', 'Difficulty'); ?></div>
                        </div>
                        <div class="highlight-item">
                            <div class="highlight-icon"><i class="bi bi-person"></i></div>
                            <div class="highlight-value"><?php echo $attraction['min_age'] ? $attraction['min_age'] . '+' : '—'; ?></div>
                            <div class="highlight-label"><?php echo tr('min_age', 'Min age'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-info-circle"></i> <?php echo tr('about', 'About this experience'); ?></h2>
                    <div class="description-text">
                        <?php echo nl2br(sanitize($attraction['description'])); ?>
                    </div>
                </div>

                <!-- Pricing Tiers -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-tags"></i> <?php echo tr('pricing_options', 'Pricing options'); ?></h2>
                    <div class="tiers-container" id="tiersContainer">
                        <?php foreach ($tiers as $index => $tier):
                            $priceWithTax = $tier['base_price'] * (1 + $taxRate / 100);
                            $inclusions = $tier['inclusions'] ? json_decode($tier['inclusions'], true) : [];
                        ?>
                            <div class="tier-card" data-tier-id="<?php echo $tier['tier_id']; ?>" data-tier-price="<?php echo $tier['base_price']; ?>" data-tier-price-with-tax="<?php echo $priceWithTax; ?>">
                                <div class="tier-header">
                                    <div class="tier-name"><?php echo sanitize($tier['tier_name']); ?></div>
                                    <div class="tier-price">
                                        <div class="tier-price-value"><?php echo formatPrice($priceWithTax); ?></div>
                                        <div class="tier-price-unit"><?php echo tr('per_person', 'per person'); ?> (<?php echo tr('tax_included', 'tax incl.'); ?>)</div>
                                    </div>
                                </div>
                                <?php if ($tier['description']): ?>
                                    <div class="tier-description"><?php echo sanitize($tier['description']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($inclusions)): ?>
                                    <div class="tier-features">
                                        <?php foreach ($inclusions as $item): ?>
                                            <span class="tier-feature">✓ <?php echo ucfirst(str_replace('_', ' ', $item)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <button class="tier-select-btn" onclick="selectTier(<?php echo $tier['tier_id']; ?>, '<?php echo addslashes($tier['tier_name']); ?>', <?php echo $tier['base_price']; ?>, <?php echo $priceWithTax; ?>, event)">
                                    <?php echo tr('select', 'Select'); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Included & Excluded -->
                <?php if (!empty($includedItems) || !empty($excludedItems)): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-check-circle"></i> <?php echo tr('included_excluded', 'Included & excluded'); ?></h2>
                        <div style="margin-bottom: 16px;">
                            <div style="font-weight: 600; font-size: 12px; margin-bottom: 8px;"><?php echo tr('included', 'Included'); ?></div>
                            <div class="items-list">
                                <?php foreach ($includedItems as $item): ?>
                                    <span class="item-tag included"><i class="bi bi-check-lg"></i> <?php echo ucfirst(str_replace('_', ' ', $item)); ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($includedItems)): ?>
                                    <span class="item-tag">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-weight: 600; font-size: 12px; margin-bottom: 8px;"><?php echo tr('excluded', 'Excluded'); ?></div>
                            <div class="items-list">
                                <?php foreach ($excludedItems as $item): ?>
                                    <span class="item-tag excluded"><i class="bi bi-x-lg"></i> <?php echo ucfirst(str_replace('_', ' ', $item)); ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($excludedItems)): ?>
                                    <span class="item-tag">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- What to Bring -->
                <?php if (!empty($whatToBring)): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-bag"></i> <?php echo tr('what_to_bring', 'What to bring'); ?></h2>
                        <div class="items-list">
                            <?php foreach ($whatToBring as $item): ?>
                                <span class="item-tag"><i class="bi bi-check-lg"></i> <?php echo ucfirst(str_replace('_', ' ', $item)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Start Times & Languages -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-clock-history"></i> <?php echo tr('schedule', 'Schedule'); ?></h2>
                    <div style="margin-bottom: 16px;">
                        <div style="font-weight: 600; font-size: 12px; margin-bottom: 6px;"><?php echo tr('start_times', 'Start times'); ?></div>
                        <div class="times-list">
                            <?php foreach ($startTimes as $time): ?>
                                <span class="time-tag"><?php echo date('g:i A', strtotime($time)); ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($startTimes)): ?>
                                <span class="time-tag"><?php echo tr('flexible', 'Flexible - contact provider'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-weight: 600; font-size: 12px; margin-bottom: 6px;"><?php echo tr('guide_languages', 'Guide languages'); ?></div>
                        <div class="items-list">
                            <?php foreach ($guideLanguages as $lang): ?>
                                <span class="lang-tag"><i class="bi bi-chat-dots"></i> <?php echo strtoupper($lang); ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($guideLanguages)): ?>
                                <span class="lang-tag"><?php echo tr('english', 'English'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Meeting Point -->
                <?php if ($attraction['meeting_point']): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-pin-map"></i> <?php echo tr('meeting_point', 'Meeting point'); ?></h2>
                        <div class="description-text"><?php echo nl2br(sanitize($attraction['meeting_point'])); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Reviews -->
                <?php if (!empty($reviews)): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-star-fill" style="color: #febb02;"></i> <?php echo tr('guest_reviews', 'Guest reviews'); ?></h2>

                        <?php if ($attraction['avg_rating'] > 0 && !empty($reviewStats)): ?>
                            <div class="reviews-summary">
                                <div class="rating-circle">
                                    <div class="rating-number"><?php echo number_format($attraction['avg_rating'], 1); ?></div>
                                    <div class="rating-label"><?php echo getReviewLabel($attraction['avg_rating'])[0]; ?></div>
                                    <div style="font-size: 10px; color: var(--bkg-gray-500);"><?php echo $attraction['review_count']; ?> <?php echo tr('reviews', 'reviews'); ?></div>
                                </div>
                                <div class="rating-bars">
                                    <div class="rating-bar-item">
                                        <span class="rating-bar-label"><?php echo tr('excellent', 'Excellent'); ?></span>
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill" style="width: <?php echo $attraction['review_count'] > 0 ? ($reviewStats['excellent'] / $attraction['review_count']) * 100 : 0; ?>%"></div>
                                        </div>
                                        <span class="rating-bar-count"><?php echo $reviewStats['excellent']; ?></span>
                                    </div>
                                    <div class="rating-bar-item">
                                        <span class="rating-bar-label"><?php echo tr('good', 'Good'); ?></span>
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill" style="width: <?php echo $attraction['review_count'] > 0 ? ($reviewStats['good'] / $attraction['review_count']) * 100 : 0; ?>%"></div>
                                        </div>
                                        <span class="rating-bar-count"><?php echo $reviewStats['good']; ?></span>
                                    </div>
                                    <div class="rating-bar-item">
                                        <span class="rating-bar-label"><?php echo tr('average', 'Average'); ?></span>
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill" style="width: <?php echo $attraction['review_count'] > 0 ? ($reviewStats['average'] / $attraction['review_count']) * 100 : 0; ?>%"></div>
                                        </div>
                                        <span class="rating-bar-count"><?php echo $reviewStats['average']; ?></span>
                                    </div>
                                    <div class="rating-bar-item">
                                        <span class="rating-bar-label"><?php echo tr('poor', 'Poor'); ?></span>
                                        <div class="rating-bar">
                                            <div class="rating-bar-fill" style="width: <?php echo $attraction['review_count'] > 0 ? ($reviewStats['poor'] / $attraction['review_count']) * 100 : 0; ?>%"></div>
                                        </div>
                                        <span class="rating-bar-count"><?php echo $reviewStats['poor']; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach (array_slice($reviews, 0, 3) as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <div class="reviewer">
                                        <div class="reviewer-avatar">
                                            <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="reviewer-name"><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?></div>
                                            <div class="review-date"><?php echo $review['month_year']; ?></div>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>"></i>
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
                    <h3><?php echo tr('book_your_spot', 'Book your spot'); ?></h3>

                    <!-- Date Selection -->
                    <div class="date-selector">
                        <div class="date-label"><?php echo tr('select_date', 'Select date'); ?></div>
                        <input type="date" id="experienceDate" class="date-value" style="border: none; background: transparent; width: 100%; font-weight: 600;" value="<?php echo $selectedDate; ?>" min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <!-- Guest Selection -->
                    <div class="guest-selector" id="guestSelector">
                        <div class="date-label"><?php echo tr('participants', 'Participants'); ?></div>
                        <div class="date-value" id="guestDisplay"><?php echo $guests; ?> <?php echo tr($guests > 1 ? 'persons' : 'person', $guests > 1 ? 'persons' : 'person'); ?></div>
                        <div class="guest-dropdown" id="guestDropdown">
                            <div class="guest-row">
                                <span><?php echo tr('adults', 'Adults'); ?></span>
                                <div class="guest-counter">
                                    <button onclick="changeGuests(-1)">-</button>
                                    <span id="adultCount"><?php echo $guests; ?></span>
                                    <button onclick="changeGuests(1)">+</button>
                                </div>
                            </div>
                            <button class="guest-done-btn" style="width: 100%; margin-top: 12px; padding: 6px; background: var(--bkg-blue-light); color: white; border: none; border-radius: 4px; cursor: pointer;" onclick="closeGuestDropdown()"><?php echo tr('done', 'Done'); ?></button>
                        </div>
                    </div>

                    <!-- Selected Tier Preview -->
                    <div id="selectedTierPreview" style="display: none;" class="selected-tier-preview">
                        <div style="font-size: 10px; color: var(--bkg-gray-500);"><?php echo tr('selected_tier', 'Selected tier'); ?></div>
                        <div style="font-weight: 700; font-size: 14px;" id="previewTierName"></div>
                        <div style="font-size: 13px; font-weight: 700; color: var(--bkg-success); margin-top: 4px;" id="previewTierPrice"></div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="price-breakdown" id="priceBreakdown" style="display: none;">
                        <div class="price-row">
                            <span><?php echo tr('price_per_person', 'Price per person'); ?></span>
                            <span id="pricePerPerson">-</span>
                        </div>
                        <div class="price-row">
                            <span><?php echo tr('participants', 'Participants'); ?></span>
                            <span id="participantCount"><?php echo $guests; ?></span>
                        </div>
                        <div class="price-row total">
                            <span><?php echo tr('total', 'Total'); ?></span>
                            <span id="totalPrice">-</span>
                        </div>
                        <div class="price-row tax">
                            <span><?php echo tr('includes_tax', 'Includes 18% VAT'); ?></span>
                        </div>
                    </div>

                    <!-- Check Availability Notice -->
                    <div id="availabilityNotice" style="display: none; padding: 10px; background: #fff3cd; border-radius: 6px; font-size: 11px; color: #856404; margin: 12px 0;">
                        <i class="bi bi-info-circle"></i> <?php echo tr('check_availability', 'Please select a tier to continue'); ?>
                    </div>

                    <!-- Reserve Button -->
                    <button class="btn-reserve" id="reserveBtn" onclick="proceedToBooking()" disabled>
                        <?php echo tr('continue_to_book', 'Continue to book'); ?>
                    </button>

                    <div class="security-note">
                        <i class="bi bi-shield-check"></i> <?php echo tr('secure_booking', 'Secure booking • No fees charged yet'); ?>
                    </div>

                    <?php if ($attraction['free_cancellation']): ?>
                        <div class="cancel-policy">
                            <i class="bi bi-x-circle"></i> <?php echo tr('free_cancel', 'Free cancellation up to 24 hours before'); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Contact Card -->
                <div class="booking-card" style="background: var(--bkg-gray-100);">
                    <h3 style="font-size: 14px;"><?php echo tr('have_questions', 'Have questions?'); ?></h3>
                    <p style="font-size: 11px; color: var(--bkg-gray-500); margin-bottom: 12px;">
                        <?php echo tr('contact_provider', 'Contact the experience provider directly.'); ?>
                    </p>
                    <?php if ($attraction['owner_phone']): ?>
                        <div style="font-size: 12px;">
                            <i class="bi bi-telephone-fill"></i> <a href="tel:<?php echo $attraction['owner_phone']; ?>" style="color: var(--bkg-blue-light); text-decoration: none;"><?php echo $attraction['owner_phone']; ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Variables
    let selectedTierId = null;
    let selectedTierPrice = 0;
    let selectedTierPriceWithTax = 0;
    let selectedTierName = '';
    let participantCount = <?php echo $guests; ?>;

    // Select Tier
    function selectTier(tierId, tierName, basePrice, priceWithTax, event) {
        event.stopPropagation();

        // Reset all tier cards
        document.querySelectorAll('.tier-card').forEach(card => {
            card.classList.remove('selected');
            const btn = card.querySelector('.tier-select-btn');
            if (btn) {
                btn.textContent = '<?php echo tr('select', 'Select'); ?>';
                btn.style.background = '#0071c2';
            }
        });

        // Select current tier
        const tierCard = document.querySelector(`.tier-card[data-tier-id="${tierId}"]`);
        tierCard.classList.add('selected');
        const selectBtn = tierCard.querySelector('.tier-select-btn');
        selectBtn.textContent = '✓ <?php echo tr('selected', 'Selected'); ?>';
        selectBtn.style.background = '#008009';

        selectedTierId = tierId;
        selectedTierPrice = basePrice;
        selectedTierPriceWithTax = priceWithTax;
        selectedTierName = tierName;

        // Update preview
        document.getElementById('previewTierName').textContent = tierName;
        updatePriceDisplay();
        document.getElementById('selectedTierPreview').style.display = 'block';
        document.getElementById('priceBreakdown').style.display = 'block';
        document.getElementById('availabilityNotice').style.display = 'none';

        // Enable reserve button
        document.getElementById('reserveBtn').disabled = false;
    }

    function updatePriceDisplay() {
        const total = selectedTierPriceWithTax * participantCount;
        document.getElementById('pricePerPerson').textContent = formatCurrency(selectedTierPriceWithTax);
        document.getElementById('participantCount').textContent = participantCount;
        document.getElementById('previewTierPrice').textContent = formatCurrency(total);
        document.getElementById('totalPrice').textContent = formatCurrency(total);
    }

    function formatCurrency(amount) {
        return 'RWF ' + Math.round(amount).toLocaleString();
    }

    // Guest selection
    function changeGuests(delta) {
        let newCount = participantCount + delta;
        if (newCount >= 1 && newCount <= 20) {
            participantCount = newCount;
            document.getElementById('adultCount').textContent = participantCount;
            document.getElementById('guestDisplay').textContent = participantCount + ' ' + (participantCount > 1 ? 'persons' : 'person');

            if (selectedTierId) {
                updatePriceDisplay();
            }
        }
    }

    function closeGuestDropdown() {
        document.getElementById('guestDropdown').classList.remove('active');
    }

    // Toggle guest dropdown
    document.getElementById('guestSelector').addEventListener('click', function(e) {
        e.stopPropagation();
        document.getElementById('guestDropdown').classList.toggle('active');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('guestDropdown');
        const selector = document.getElementById('guestSelector');
        if (dropdown && selector && !selector.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });

    // Set min date for date input
    const dateInput = document.getElementById('experienceDate');
    const today = new Date().toISOString().split('T')[0];
    dateInput.min = today;

    // Update dates when changed
    dateInput.addEventListener('change', function() {
        if (selectedTierId) {
            // Optionally check availability for new date
            updatePriceDisplay();
        }
    });

    // Proceed to booking
    function proceedToBooking() {
        if (!selectedTierId) {
            document.getElementById('availabilityNotice').style.display = 'block';
            return;
        }

        const experienceDate = document.getElementById('experienceDate').value;
        const participants = participantCount;

        window.location.href = `booking.php?id=<?php echo $id; ?>&tier=${selectedTierId}&date=${experienceDate}&participants=${participants}`;
    }
</script>

<?php require_once '../includes/footer.php'; ?>