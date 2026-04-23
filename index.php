<?php
require_once 'includes/functions.php';

$db = getDB();
$searchType = $_GET['type'] ?? 'stays';

// Get current language and currency from session
$currentLang = $_SESSION['language'] ?? 'en';
$currentCurrency = $_SESSION['currency'] ?? 'RWF';

// Get real counts from database
$totalStays = $db->query("SELECT COUNT(*) FROM stays WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalCars = $db->query("SELECT COUNT(*) FROM car_rentals WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalExperiences = $db->query("SELECT COUNT(*) FROM attractions WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalRestaurants = $db->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn();

// Get featured stays
$featuredStays = $db->query("
    SELECT s.*, l.name as location_name,
    (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
    (SELECT MAX(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as max_price,
    (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.is_active = 1 AND s.is_verified = 1
    ORDER BY s.avg_rating DESC, s.review_count DESC 
    LIMIT 8
")->fetchAll();

// Get featured cars
$featuredCars = $db->query("
    SELECT cr.*, l.name as location_name,
    (SELECT MIN(daily_rate) FROM car_fleet WHERE rental_id = cr.rental_id AND is_active = 1) as min_price,
    (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id AND is_active = 1) as car_count,
    (SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id) as review_count
    FROM car_rentals cr
    LEFT JOIN locations l ON cr.location_id = l.location_id
    WHERE cr.is_active = 1 AND cr.is_verified = 1
    ORDER BY cr.avg_rating DESC 
    LIMIT 8
")->fetchAll();

// Get featured attractions
$featuredAttractions = $db->query("
    SELECT a.*, c.name as category_name, l.name as location_name,
    (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price,
    (SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id) as review_count
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.is_active = 1 AND a.is_verified = 1
    ORDER BY a.avg_rating DESC 
    LIMIT 8
")->fetchAll();



// Get popular destinations
$popularDestinations = $db->query("
    SELECT l.*, 
    (SELECT COUNT(*) FROM stays WHERE location_id = l.location_id AND is_active = 1) as stay_count,
    (SELECT COUNT(*) FROM attractions WHERE location_id = l.location_id AND is_active = 1) as attraction_count,
    (SELECT COUNT(*) FROM car_rentals WHERE location_id = l.location_id AND is_active = 1) as car_count
    FROM locations l 
    WHERE l.type IN ('city', 'landmark') AND l.is_active = 1
    ORDER BY l.search_count DESC 
    LIMIT 6
")->fetchAll();

// Get special offers (stays with discounts)
$specialOffers = $db->query("
    SELECT s.*, l.name as location_name,
    (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as original_price,
    (SELECT MIN(base_price) * 0.85 FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as discounted_price,
    (SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id) as review_count
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.is_active = 1 AND s.is_verified = 1
    AND s.stay_id IN (4, 5, 3) -- Selected properties for offers
    ORDER BY RAND()
    LIMIT 4
")->fetchAll();

$pageTitle = 'GoRwanda+ - Discover Rwanda\'s Best Stays, Cars & Experiences';
require_once 'includes/header.php';
?>

<style>
    /* ===== BOOKING.COM STYLE HOMEPAGE ===== */
    :root {
        --bkg-blue-dark: #003580;
        --bkg-blue-primary: #0071c2;
        --bkg-blue-light: #ebf3ff;
        --bkg-yellow: #feba02;
        --bkg-yellow-hover: #e6a800;
        --bkg-green: #008009;
        --bkg-red: #c41c1c;
        --bkg-gray-100: #f2f6fa;
        --bkg-gray-200: #e7e7e7;
        --bkg-gray-500: #6b6b6b;
        --bkg-gray-700: #262626;
        --bkg-white: #ffffff;

        --radius-sm: 2px;
        --radius-md: 4px;
        --radius-lg: 8px;
        --radius-xl: 12px;
        --shadow-sm: 0 1px 4px rgba(0, 0, 0, 0.05);
        --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
        --transition: all 0.2s ease;
    }

    /* Hero Section - Booking.com Style */
    .bkg-hero {
        background: linear-gradient(135deg, var(--bkg-blue-dark) 0%, #001b4f 100%);
        padding: 40px 0 60px;
        margin-bottom: 32px;
        position: relative;
        overflow: hidden;
    }

    .bkg-hero::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        background: url('https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1200&q=60') center/cover;
        opacity: 0.1;
        pointer-events: none;
    }

    .bkg-hero-content {
        position: relative;
        z-index: 2;
    }

    .bkg-hero-title {
        color: white;
        font-size: 36px;
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 16px;
        letter-spacing: -0.5px;
    }

    .bkg-hero-subtitle {
        color: rgba(255, 255, 255, 0.9);
        font-size: 16px;
        margin-bottom: 24px;
    }

    .bkg-hero-stats {
        display: flex;
        gap: 32px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }

    .bkg-hero-stat {
        color: white;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-hero-stat i {
        font-size: 18px;
        opacity: 0.9;
    }

    .bkg-hero-stat strong {
        font-weight: 700;
        font-size: 18px;
    }

    .bkg-hero-badges {
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
    }

    .bkg-hero-badge {
        color: white;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(255, 255, 255, 0.1);
        padding: 6px 12px;
        border-radius: 24px;
    }

    .bkg-hero-badge i {
        font-size: 14px;
    }

    /* Section Headers - Booking.com Style */
    .bkg-section-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 24px;
    }

    .bkg-section-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        margin-bottom: 4px;
        letter-spacing: -0.3px;
    }

    .bkg-section-subtitle {
        font-size: 14px;
        color: var(--bkg-gray-500);
    }

    .bkg-section-link {
        color: var(--bkg-blue-primary);
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 4px;
        transition: gap 0.2s;
    }

    .bkg-section-link:hover {
        gap: 8px;
        color: #005fa3;
    }

    /* Destination Cards - Booking.com Style */
    .bkg-destination-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 12px;
        margin-bottom: 40px;
    }

    .bkg-destination-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        padding: 20px 12px;
        text-align: center;
        transition: var(--transition);
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: block;
    }

    .bkg-destination-card:hover {
        border-color: var(--bkg-blue-primary);
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .bkg-destination-icon {
        width: 48px;
        height: 48px;
        background: var(--bkg-blue-light);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 12px;
        color: var(--bkg-blue-primary);
        font-size: 20px;
        transition: var(--transition);
    }

    .bkg-destination-card:hover .bkg-destination-icon {
        background: var(--bkg-blue-primary);
        color: white;
        transform: scale(1.1);
    }

    .bkg-destination-name {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .bkg-destination-count {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    /* Property Cards - Exact Booking.com Style */
    .bkg-card-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 40px;
    }

    .bkg-property-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        overflow: hidden;
        transition: var(--transition);
        cursor: pointer;
        text-decoration: none;
        color: var(--bkg-gray-700);
        display: block;
        position: relative;
    }

    .bkg-property-card:hover {
        box-shadow: var(--shadow-lg);
        border-color: var(--bkg-blue-primary);
        transform: translateY(-4px);
    }

    .bkg-card-image {
        position: relative;
        height: 160px;
        overflow: hidden;
        background: #f5f5f5;
    }

    .bkg-card-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .bkg-property-card:hover .bkg-card-image img {
        transform: scale(1.08);
    }

    .bkg-card-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        background: var(--bkg-blue-primary);
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        z-index: 2;
    }

    .bkg-card-badge.deal {
        background: #c41c1c;
    }

    .bkg-card-badge.verified {
        background: #008009;
    }

    .bkg-card-rating-badge {
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

    .bkg-card-content {
        padding: 16px;
    }

    .bkg-property-type {
        font-size: 11px;
        color: var(--bkg-gray-500);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
    }

    .bkg-property-name {
        font-size: 16px;
        font-weight: 600;
        color: var(--bkg-gray-700);
        margin-bottom: 6px;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .bkg-property-location {
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .bkg-property-location i {
        color: var(--bkg-blue-primary);
        font-size: 12px;
    }

    .bkg-property-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--bkg-gray-200);
        padding-top: 12px;
        margin-top: 8px;
    }

    .bkg-property-rating {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-rating-score {
        background: var(--bkg-blue-dark);
        color: white;
        padding: 4px 8px;
        border-radius: 4px 4px 4px 0;
        font-weight: 700;
        font-size: 14px;
    }

    .bkg-rating-text {
        font-size: 11px;
        line-height: 1.3;
    }

    .bkg-rating-label {
        font-weight: 600;
        color: var(--bkg-gray-700);
    }

    .bkg-rating-count {
        color: var(--bkg-gray-500);
    }

    .bkg-property-price {
        text-align: right;
    }

    .bkg-price-from {
        font-size: 11px;
        color: var(--bkg-gray-500);
    }

    .bkg-price-value {
        font-size: 18px;
        font-weight: 700;
        color: var(--bkg-gray-700);
        line-height: 1.2;
    }

    .bkg-price-unit {
        font-size: 11px;
        color: var(--bkg-gray-500);
        font-weight: 400;
    }

    .bkg-price-deal {
        font-size: 11px;
        color: #008009;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 2px;
        justify-content: flex-end;
    }

    /* Special Offers Section */
    .bkg-offers-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 40px;
    }

    .bkg-offer-card {
        background: white;
        border: 1px solid var(--bkg-gray-200);
        border-radius: 8px;
        overflow: hidden;
        transition: var(--transition);
        cursor: pointer;
        position: relative;
    }

    .bkg-offer-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-4px);
    }

    .bkg-offer-image {
        height: 140px;
        position: relative;
        overflow: hidden;
    }

    .bkg-offer-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s;
    }

    .bkg-offer-card:hover .bkg-offer-image img {
        transform: scale(1.08);
    }

    .bkg-offer-tag {
        position: absolute;
        top: 12px;
        left: 12px;
        background: #c41c1c;
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 700;
        z-index: 2;
    }

    .bkg-offer-content {
        padding: 16px;
    }

    .bkg-offer-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--bkg-gray-700);
        margin-bottom: 4px;
    }

    .bkg-offer-location {
        font-size: 12px;
        color: var(--bkg-gray-500);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .bkg-offer-price {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .bkg-offer-original {
        font-size: 12px;
        color: var(--bkg-gray-500);
        text-decoration: line-through;
    }

    .bkg-offer-current {
        font-size: 18px;
        font-weight: 700;
        color: #c41c1c;
    }

    /* Why Book Section */
    .bkg-features {
        background: var(--bkg-gray-100);
        border-radius: 12px;
        padding: 40px;
        margin: 40px 0;
    }

    .bkg-features-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 24px;
    }

    .bkg-feature {
        text-align: center;
    }

    .bkg-feature-icon {
        width: 64px;
        height: 64px;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        color: var(--bkg-blue-primary);
        font-size: 24px;
        box-shadow: var(--shadow-sm);
        transition: var(--transition);
    }

    .bkg-feature:hover .bkg-feature-icon {
        transform: scale(1.1);
        color: var(--bkg-blue-dark);
        box-shadow: var(--shadow-md);
    }

    .bkg-feature-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--bkg-gray-700);
        margin-bottom: 6px;
    }

    .bkg-feature-text {
        font-size: 12px;
        color: var(--bkg-gray-500);
        line-height: 1.5;
        max-width: 200px;
        margin: 0 auto;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .bkg-destination-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .bkg-card-grid,
        .bkg-offers-grid,
        .bkg-features-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .bkg-hero-title {
            font-size: 28px;
        }

        .bkg-section-title {
            font-size: 20px;
        }

        .bkg-destination-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .bkg-features {
            padding: 24px;
        }
    }

    @media (max-width: 576px) {

        .bkg-card-grid,
        .bkg-offers-grid,
        .bkg-features-grid {
            grid-template-columns: 1fr;
        }

        .bkg-hero-stats {
            gap: 16px;
        }

        .bkg-hero-badges {
            gap: 12px;
        }
    }
</style>

<!-- Hero Section -->
<section class="bkg-hero">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 bkg-hero-content">
                <h1 class="bkg-hero-title">
                    <?php echo sanitize(tr('hero_title')); ?>
                </h1>
                <p class="bkg-hero-subtitle">
                    <?php echo sanitize(tr('hero_subtitle_main')); ?>
                </p>

                <div class="bkg-hero-stats">
                    <div class="bkg-hero-stat">
                        <i class="bi bi-building"></i>
                        <span><strong><?php echo number_format($totalStays); ?></strong> <?php echo tr('stays'); ?></span>
                    </div>
                    <div class="bkg-hero-stat">
                        <i class="bi bi-car-front"></i>
                        <span><strong><?php echo number_format($totalCars); ?></strong> <?php echo tr('cars'); ?></span>
                    </div>
                    <div class="bkg-hero-stat">
                        <i class="bi bi-ticket-perforated"></i>
                        <span><strong><?php echo number_format($totalExperiences); ?></strong> <?php echo tr('experiences'); ?></span>
                    </div>
                    <div class="bkg-hero-stat">
                        <i class="bi bi-shop"></i>
                        <span><strong><?php echo number_format($totalRestaurants); ?></strong> <?php echo tr('restaurants'); ?></span>
                    </div>
                </div>

                <div class="bkg-hero-badges">
                    <div class="bkg-hero-badge">
                        <i class="bi bi-star-fill text-warning"></i>
                        <span>4.5+ <?php echo tr('avg_rating'); ?></span>
                    </div>
                    <div class="bkg-hero-badge">
                        <i class="bi bi-shield-check text-success"></i>
                        <span><?php echo tr('verified'); ?></span>
                    </div>
                    <div class="bkg-hero-badge">
                        <i class="bi bi-clock-history"></i>
                        <span><?php echo tr('247_support'); ?></span>
                    </div>
                    <div class="bkg-hero-badge">
                        <i class="bi bi-wifi"></i>
                        <span><?php echo tr('instant'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Popular Destinations -->
<section class="container mb-5">
    <div class="bkg-section-header">
        <div>
            <h2 class="bkg-section-title"><?php echo tr('popular_destinations_title'); ?></h2>
            <p class="bkg-section-subtitle"><?php echo tr('popular_destinations_sub'); ?></p>
        </div>
        <a href="search.php" class="bkg-section-link">
            <?php echo tr('see_all'); ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="bkg-destination-grid">
        <?php foreach ($popularDestinations as $dest):
            $count = $dest['stay_count'] + $dest['attraction_count'] + $dest['car_count'];
        ?>
            <a href="search.php?location=<?php echo urlencode($dest['name']); ?>" class="bkg-destination-card">
                <div class="bkg-destination-icon">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="bkg-destination-name"><?php echo sanitize($dest['name']); ?></div>
                <div class="bkg-destination-count"><?php echo $count; ?> <?php echo tr('listings_word'); ?></div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Featured Stays -->
<section class="container mb-5">
    <div class="bkg-section-header">
        <div>
            <h2 class="bkg-section-title"><?php echo tr('featured'); ?></h2>
            <p class="bkg-section-subtitle"><?php echo tr('featured_stays_sub'); ?></p>
        </div>
        <a href="search.php?type=stays" class="bkg-section-link">
            <?php echo tr('browse_all_stays'); ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <?php if (empty($featuredStays)): ?>
        <div class="text-center py-5 bg-light rounded">
            <p class="text-secondary"><?php echo tr('no_stays_available'); ?></p>
        </div>
    <?php else: ?>
        <div class="bkg-card-grid">
            <?php foreach ($featuredStays as $stay):
                $reviewLabel = $stay['avg_rating'] ? getReviewLabel($stay['avg_rating']) : [tr('new_label'), 'bg-secondary'];
                $image = $stay['main_image'] ?: 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60';
            ?>
                <a href="stays/detail.php?id=<?php echo $stay['stay_id']; ?>" class="bkg-property-card">
                    <div class="bkg-card-image">
                        <img src="<?php echo getImageUrl($image, 'stay'); ?>"
                            alt="<?php echo sanitize($stay['stay_name']); ?>"
                            loading="lazy"
                            onerror="this.src='https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60'">

                        <?php if ($stay['is_verified']): ?>
                            <span class="bkg-card-badge verified"><?php echo tr('verified'); ?></span>
                        <?php endif; ?>

                        <?php if ($stay['avg_rating'] > 0): ?>
                            <span class="bkg-card-rating-badge"><?php echo number_format($stay['avg_rating'], 1); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="bkg-card-content">
                        <div class="bkg-property-type"><?php echo ucfirst($stay['stay_type']); ?></div>
                        <h3 class="bkg-property-name"><?php echo sanitize($stay['stay_name']); ?></h3>
                        <div class="bkg-property-location">
                            <i class="bi bi-geo-alt"></i>
                            <?php echo sanitize($stay['location_name'] ?? $stay['address'] ?? 'Rwanda'); ?>
                        </div>

                        <div class="bkg-property-footer">
                            <div class="bkg-property-rating">
                                <?php if ($stay['review_count'] > 0): ?>
                                    <span class="bkg-rating-score"><?php echo number_format($stay['avg_rating'], 1); ?></span>
                                    <div class="bkg-rating-text">
                                        <div class="bkg-rating-label"><?php echo $reviewLabel[0]; ?></div>
                                        <div class="bkg-rating-count"><?php echo number_format($stay['review_count']); ?> <?php echo tr('reviews'); ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 11px;"><?php echo tr('new_label'); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($stay['min_price']): ?>
                                <div class="bkg-property-price">
                                    <div class="bkg-price-from"><?php echo tr('from'); ?></div>
                                    <div class="bkg-price-value"><?php echo formatPrice($stay['min_price']); ?></div>
                                    <div class="bkg-price-unit"><?php echo tr('per_night'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Featured Cars -->
<section class="container mb-5">
    <div class="bkg-section-header">
        <div>
            <h2 class="bkg-section-title"><?php echo tr('featured_cars_title'); ?></h2>
            <p class="bkg-section-subtitle"><?php echo tr('featured_cars_sub'); ?></p>
        </div>
        <a href="search.php?type=cars" class="bkg-section-link">
            <?php echo tr('browse_all_cars'); ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <?php if (empty($featuredCars)): ?>
        <div class="text-center py-5 bg-light rounded">
            <p class="text-secondary"><?php echo tr('no_cars_available'); ?></p>
        </div>
    <?php else: ?>
        <div class="bkg-card-grid">
            <?php foreach ($featuredCars as $car):
                $image = $car['logo'] ?: 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&q=60';
            ?>
                <a href="cars/detail.php?id=<?php echo $car['rental_id']; ?>" class="bkg-property-card">
                    <div class="bkg-card-image">
                        <img src="<?php echo getImageUrl($image, 'car'); ?>"
                            alt="<?php echo sanitize($car['company_name']); ?>"
                            loading="lazy"
                            onerror="this.src='https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&q=60'">

                        <?php if ($car['is_verified']): ?>
                            <span class="bkg-card-badge verified"><?php echo tr('verified'); ?></span>
                        <?php endif; ?>

                        <?php if ($car['avg_rating'] > 0): ?>
                            <span class="bkg-card-rating-badge"><?php echo number_format($car['avg_rating'], 1); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="bkg-card-content">
                        <div class="bkg-property-type"><?php echo tr('car_rental'); ?></div>
                        <h3 class="bkg-property-name"><?php echo sanitize($car['company_name']); ?></h3>
                        <div class="bkg-property-location">
                            <i class="bi bi-geo-alt"></i>
                            <?php echo sanitize($car['location_name'] ?? 'Kigali'); ?>
                        </div>

                        <div class="bkg-property-footer">
                            <div class="bkg-property-rating">
                                <?php if ($car['review_count'] > 0): ?>
                                    <span class="bkg-rating-score"><?php echo number_format($car['avg_rating'], 1); ?></span>
                                    <div class="bkg-rating-text">
                                        <div class="bkg-rating-label"><?php echo getReviewLabel($car['avg_rating'])[0]; ?></div>
                                        <div class="bkg-rating-count"><?php echo number_format($car['review_count']); ?> <?php echo tr('reviews'); ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 11px;"><?php echo tr('new_label'); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($car['min_price']): ?>
                                <div class="bkg-property-price">
                                    <div class="bkg-price-from"><?php echo tr('from'); ?></div>
                                    <div class="bkg-price-value"><?php echo formatPrice($car['min_price']); ?></div>
                                    <div class="bkg-price-unit"><?php echo tr('per_day'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Featured Experiences -->
<section class="container mb-5">
    <div class="bkg-section-header">
        <div>
            <h2 class="bkg-section-title"><?php echo tr('featured_experiences_title'); ?></h2>
            <p class="bkg-section-subtitle"><?php echo tr('featured_experiences_sub'); ?></p>
        </div>
        <a href="search.php?type=attractions" class="bkg-section-link">
            <?php echo tr('browse_all_experiences'); ?> <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <?php if (empty($featuredAttractions)): ?>
        <div class="text-center py-5 bg-light rounded">
            <p class="text-secondary"><?php echo tr('no_experiences_available'); ?></p>
        </div>
    <?php else: ?>
        <div class="bkg-card-grid">
            <?php foreach ($featuredAttractions as $attraction):
                $image = $attraction['main_image'] ?: 'https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60';
            ?>
                <a href="attractions/detail.php?id=<?php echo $attraction['attraction_id']; ?>" class="bkg-property-card">
                    <div class="bkg-card-image">
                        <img src="<?php echo getImageUrl($image, 'attraction'); ?>"
                            alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                            loading="lazy"
                            onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&q=60'">

                        <?php if ($attraction['is_verified']): ?>
                            <span class="bkg-card-badge verified"><?php echo tr('verified'); ?></span>
                        <?php endif; ?>

                        <?php if ($attraction['avg_rating'] > 0): ?>
                            <span class="bkg-card-rating-badge"><?php echo number_format($attraction['avg_rating'], 1); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="bkg-card-content">
                        <div class="bkg-property-type"><?php echo sanitize($attraction['category_name'] ?? tr('experiences')); ?></div>
                        <h3 class="bkg-property-name"><?php echo sanitize($attraction['attraction_name']); ?></h3>
                        <div class="bkg-property-location">
                            <i class="bi bi-geo-alt"></i>
                            <?php echo sanitize($attraction['location_name'] ?? 'Rwanda'); ?>
                        </div>

                        <div class="bkg-property-footer">
                            <div class="bkg-property-rating">
                                <?php if ($attraction['review_count'] > 0): ?>
                                    <span class="bkg-rating-score"><?php echo number_format($attraction['avg_rating'], 1); ?></span>
                                    <div class="bkg-rating-text">
                                        <div class="bkg-rating-label"><?php echo getReviewLabel($attraction['avg_rating'])[0]; ?></div>
                                        <div class="bkg-rating-count"><?php echo number_format($attraction['review_count']); ?> <?php echo tr('reviews'); ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 11px;"><?php echo tr('new_label'); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($attraction['min_price']): ?>
                                <div class="bkg-property-price">
                                    <div class="bkg-price-from"><?php echo tr('from'); ?></div>
                                    <div class="bkg-price-value"><?php echo formatPrice($attraction['min_price']); ?></div>
                                    <div class="bkg-price-unit"><?php echo tr('per_person'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Special Offers -->
<?php if (!empty($specialOffers)): ?>
    <section class="container mb-5">
        <div class="bkg-section-header">
            <div>
                <h2 class="bkg-section-title"><?php echo tr('special_offers'); ?></h2>
                <p class="bkg-section-subtitle"><?php echo tr('offers_stays_sub'); ?></p>
            </div>
            <a href="search.php?type=stays&offer=special" class="bkg-section-link">
                <?php echo tr('view_all_deals'); ?> <i class="bi bi-arrow-right"></i>
            </a>
        </div>

        <div class="bkg-offers-grid">
            <?php foreach ($specialOffers as $offer):
                $image = $offer['main_image'] ?: 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&q=60';
            ?>
                <div class="bkg-offer-card" onclick="window.location.href='stays/detail.php?id=<?php echo $offer['stay_id']; ?>'">
                    <div class="bkg-offer-image">
                        <img src="<?php echo getImageUrl($image, 'stay'); ?>"
                            alt="<?php echo sanitize($offer['stay_name']); ?>"
                            loading="lazy">
                        <span class="bkg-offer-tag">-15%</span>
                    </div>
                    <div class="bkg-offer-content">
                        <h4 class="bkg-offer-title"><?php echo sanitize($offer['stay_name']); ?></h4>
                        <div class="bkg-offer-location">
                            <i class="bi bi-geo-alt"></i>
                            <?php echo sanitize($offer['location_name'] ?? 'Rwanda'); ?>
                        </div>
                        <div class="bkg-offer-price">
                            <span class="bkg-offer-original"><?php echo formatPrice($offer['original_price']); ?></span>
                            <span class="bkg-offer-current"><?php echo formatPrice($offer['discounted_price']); ?></span>
                        </div>
                        <div class="bkg-price-unit"><?php echo tr('per_night'); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Why Book With Us -->
<section class="container mb-5">
    <div class="bkg-features">
        <div class="bkg-section-header text-center mb-4">
            <div>
                <h2 class="bkg-section-title"><?php echo tr('why_book'); ?></h2>
                <p class="bkg-section-subtitle"><?php echo tr('why_book_sub'); ?></p>
            </div>
        </div>

        <div class="bkg-features-grid">
            <div class="bkg-feature">
                <div class="bkg-feature-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <h4 class="bkg-feature-title"><?php echo tr('secure'); ?></h4>
                <p class="bkg-feature-text"><?php echo tr('secure_desc'); ?></p>
            </div>

            <div class="bkg-feature">
                <div class="bkg-feature-icon">
                    <i class="bi bi-tags"></i>
                </div>
                <h4 class="bkg-feature-title"><?php echo tr('best_price'); ?></h4>
                <p class="bkg-feature-text"><?php echo tr('best_price_desc'); ?></p>
            </div>

            <div class="bkg-feature">
                <div class="bkg-feature-icon">
                    <i class="bi bi-headset"></i>
                </div>
                <h4 class="bkg-feature-title"><?php echo tr('247_support'); ?></h4>
                <p class="bkg-feature-text"><?php echo tr('247_desc'); ?></p>
            </div>

            <div class="bkg-feature">
                <div class="bkg-feature-icon">
                    <i class="bi bi-star"></i>
                </div>
                <h4 class="bkg-feature-title"><?php echo tr('verified_reviews'); ?></h4>
                <p class="bkg-feature-text"><?php echo tr('verified_desc'); ?></p>
            </div>
        </div>
    </div>
</section>

<script>
    // Lazy loading images
    document.addEventListener('DOMContentLoaded', function() {
        const images = document.querySelectorAll('img[loading="lazy"]');

        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.src; // Trigger load
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));
        }
    });

    // Smooth hover animations
    document.querySelectorAll('.bkg-property-card, .bkg-destination-card, .bkg-offer-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transition = 'all 0.3s ease';
        });
    });
</script>

<?php require_once 'includes/footer.php'; ?>