<?php
require_once '../includes/functions.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

$db = getDB();

// Get dates from URL
$pickupDate = isset($_GET['pickup_date']) ? $_GET['pickup_date'] : date('Y-m-d', strtotime('+1 day'));
$returnDate = isset($_GET['return_date']) ? $_GET['return_date'] : date('Y-m-d', strtotime('+4 days'));
$days = max(1, (strtotime($returnDate) - strtotime($pickupDate)) / 86400);

// Get car details
$stmt = $db->prepare("
    SELECT cf.*, cr.company_name, cr.description as company_description, 
           cr.pickup_locations, cr.dropoff_locations, cr.operating_hours,
           cr.phone, cr.email, cr.logo, cr.avg_rating as company_rating,
           cr.review_count as company_reviews, cr.is_verified, cr.owner_id,
           l.name as location_name,
           (SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cr.rental_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id) as review_count
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN locations l ON cr.location_id = l.location_id
    WHERE cf.car_id = ? AND cf.is_active = 1 AND cr.is_active = 1
");
$stmt->execute([$id]);
$car = $stmt->fetch();

if (!$car) {
    header('Location: /gorwanda-plus/?type=cars');
    exit;
}

// Get pickup locations
$pickupLocs = json_decode($car['pickup_locations'] ?? '[]', true);
if (empty($pickupLocs)) {
    $pickupLocs = ['Kigali International Airport', 'Kigali City Center'];
}

// Get images
$images = json_decode($car['images'] ?? '[]', true);
if (empty($images)) $images = [''];

// Get features
$features = json_decode($car['features'] ?? '[]', true);

// Get reviews
$stmt = $db->prepare("
    SELECT r.*, u.first_name, u.last_name, u.profile_image
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    WHERE r.rental_id = ? AND r.is_active = 1
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->execute([$car['rental_id']]);
$reviews = $stmt->fetchAll();

// Get similar cars
$stmt = $db->prepare("
    SELECT cf.*, 
           (SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cf.rental_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews WHERE rental_id = cf.rental_id) as review_count
    FROM car_fleet cf
    WHERE cf.rental_id = ? AND cf.car_id != ? AND cf.is_active = 1
    ORDER BY cf.daily_rate ASC
    LIMIT 3
");
$stmt->execute([$car['rental_id'], $id]);
$similarCars = $stmt->fetchAll();

$dailyRate = $car['daily_rate'];
$priceWithTaxFormatted = displayCustomerPrice($dailyRate);
$totalPriceFormatted = displayCustomerPrice($dailyRate * $days);
$days = max(1, (strtotime($returnDate) - strtotime($pickupDate)) / 86400);

$pageTitle = $car['brand'] . ' ' . $car['model'] . ' - Car Rental';
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* Car Detail Page Styles */
    .car-detail-page {
        padding: 32px 0;
        background: #f5f5f5;
    }

    /* Breadcrumb */
    .breadcrumb-bar {
        margin-bottom: 24px;
        font-size: 13px;
    }

    /* Header Card */
    .car-header-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        border: 1px solid #e7e7e7;
    }

    .car-title {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .car-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
    }

    .car-badge {
        padding: 4px 12px;
        background: #f0f4ff;
        color: #0071c2;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
    }

    .car-rating {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #003580;
        color: white;
        padding: 4px 12px;
        border-radius: 4px;
        font-weight: 700;
    }

    .verified-badge {
        background: #008009;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: white;
    }

    .car-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        color: #6b6b6b;
        font-size: 14px;
        margin-top: 16px;
    }

    /* Gallery */
    .car-gallery {
        background: white;
        border-radius: 12px;
        border: 1px solid #e7e7e7;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .main-image {
        height: 400px;
        overflow: hidden;
        cursor: pointer;
    }

    .main-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
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
        transition: opacity 0.2s;
    }

    .thumbnail-item:hover,
    .thumbnail-item.active {
        opacity: 1;
    }

    .thumbnail-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    /* Info Cards */
    .info-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e7e7e7;
        padding: 24px;
        margin-bottom: 24px;
    }

    .card-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Features Grid */
    .features-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }

    .feature-card {
        text-align: center;
        padding: 16px;
        background: #f5f5f5;
        border-radius: 8px;
    }

    .feature-icon {
        font-size: 24px;
        color: #0071c2;
        margin-bottom: 8px;
    }

    .feature-value {
        font-weight: 700;
        font-size: 18px;
    }

    .feature-label {
        font-size: 12px;
        color: #6b6b6b;
    }

    .features-list {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .feature-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px;
    }

    .feature-item i {
        color: #008009;
        font-size: 18px;
    }

    /* Locations */
    .locations-container {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .location-tag {
        padding: 8px 16px;
        background: #f0f4ff;
        color: #0071c2;
        border-radius: 30px;
        font-size: 14px;
    }

    /* Reviews */
    .review-card {
        padding: 16px 0;
        border-bottom: 1px solid #e7e7e7;
    }

    .review-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .reviewer {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .reviewer-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #003580;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }

    /* Similar Cars */
    .similar-section {
        margin-top: 40px;
    }

    .similar-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-top: 20px;
    }

    .similar-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        overflow: hidden;
        text-decoration: none;
        color: #1a1a1a;
        transition: all 0.2s;
    }

    .similar-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    .similar-image {
        height: 140px;
        overflow: hidden;
    }

    .similar-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .similar-content {
        padding: 12px;
    }

    .similar-title {
        font-weight: 700;
        margin-bottom: 4px;
    }

    .similar-price {
        font-size: 18px;
        font-weight: 700;
        color: #008009;
    }

    /* Booking Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .booking-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e7e7e7;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    }

    .price-display {
        text-align: center;
        padding-bottom: 20px;
        margin-bottom: 20px;
        border-bottom: 1px solid #e7e7e7;
    }

    .price-amount {
        font-size: 36px;
        font-weight: 800;
        color: #008009;
    }

    .price-period {
        font-size: 14px;
        color: #6b6b6b;
    }

    .booking-form .form-group {
        margin-bottom: 16px;
    }

    .booking-form label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #6b6b6b;
        margin-bottom: 4px;
    }

    .booking-form select,
    .booking-form input {
        width: 100%;
        padding: 12px;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        font-size: 14px;
    }

    .date-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .total-price {
        background: #f0f4ff;
        padding: 16px;
        border-radius: 8px;
        margin: 20px 0;
        text-align: center;
    }

    .total-price-label {
        font-size: 14px;
        color: #6b6b6b;
    }

    .total-price-value {
        font-size: 28px;
        font-weight: 800;
        color: #0071c2;
    }

    .btn-reserve {
        width: 100%;
        padding: 14px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 16px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .btn-reserve:hover {
        background: #003580;
    }

    .security-note {
        text-align: center;
        font-size: 12px;
        color: #6b6b6b;
        margin-top: 16px;
    }

    /* Company Card */
    .company-card {
        background: white;
        border-radius: 12px;
        border: 1px solid #e7e7e7;
        padding: 20px;
    }

    .company-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
    }

    .company-logo {
        width: 60px;
        height: 60px;
        background: #f5f5f5;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }

    .company-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .company-contact {
        font-size: 13px;
        color: #6b6b6b;
    }

    .company-contact div {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }

    /* Responsive */
    @media (max-width: 992px) {
        .car-detail-page .row {
            flex-direction: column;
        }

        .booking-sidebar {
            position: static;
            margin-top: 24px;
        }

        .similar-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .car-title {
            font-size: 24px;
        }

        .main-image {
            height: 250px;
        }

        .features-grid,
        .features-list,
        .similar-grid {
            grid-template-columns: 1fr;
        }

        .date-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="car-detail-page">
    <div class="container">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-bar">
            <a href="/gorwanda-plus/" style="color: #0071c2; text-decoration: none;">Home</a> &gt;
            <a href="/gorwanda-plus/?type=cars" style="color: #0071c2; text-decoration: none;">Cars</a> &gt;
            <span><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?></span>
        </nav>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Header -->
                <div class="car-header-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h1 class="car-title"><?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>
                                <span style="font-size: 18px; color: #6b6b6b; font-weight: 400;"><?php echo $car['year']; ?></span>
                            </h1>
                            <div class="car-badges">
                                <span class="car-badge"><i class="bi bi-building"></i> <?php echo sanitize($car['company_name']); ?></span>
                                <span class="car-badge"><i class="bi bi-car-front"></i> <?php echo ucfirst($car['car_type']); ?></span>
                                <span class="car-badge"><i class="bi bi-gear"></i> <?php echo ucfirst($car['transmission']); ?></span>
                                <span class="car-badge"><i class="bi bi-fuel-pump"></i> <?php echo ucfirst($car['fuel_type']); ?></span>
                                <?php if ($car['company_rating']): ?>
                                    <span class="car-rating"><i class="bi bi-star-fill"></i> <?php echo number_format($car['company_rating'], 1); ?></span>
                                <?php endif; ?>
                                <?php if ($car['is_verified']): ?>
                                    <span class="verified-badge"><i class="bi bi-patch-check-fill"></i> Verified</span>
                                <?php endif; ?>
                            </div>
                            <div class="car-meta">
                                <span><i class="bi bi-people"></i> <?php echo $car['seats']; ?> seats</span>
                                <span><i class="bi bi-briefcase"></i> <?php echo $car['luggage_capacity']; ?> bags</span>
                                <?php if ($car['free_km_per_day']): ?>
                                    <span><i class="bi bi-speedometer2"></i> <?php echo $car['free_km_per_day']; ?> km/day free</span>
                                <?php endif; ?>
                                <?php if ($car['insurance_included']): ?>
                                    <span><i class="bi bi-shield-check"></i> Insurance included</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gallery -->
                <div class="car-gallery">
                    <div class="main-image">
                        <img id="mainImage" src="<?php echo getImageUrl($images[0], 'car'); ?>"
                            alt="<?php echo sanitize($car['brand'] . ' ' . $car['model']); ?>"
                            onerror="this.src='https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=800&q=80'">
                    </div>
                    <?php if (count($images) > 1): ?>
                        <div class="thumbnail-grid">
                            <?php foreach ($images as $index => $img): ?>
                                <div class="thumbnail-item <?php echo $index === 0 ? 'active' : ''; ?>"
                                    onclick="changeImage('<?php echo getImageUrl($img, 'car'); ?>', this)">
                                    <img src="<?php echo getImageUrl($img, 'car'); ?>" alt="Thumbnail">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Key Features -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-grid-3x3-gap-fill"></i> Key Specifications</h2>
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="feature-value"><?php echo $car['seats']; ?></div>
                            <div class="feature-label">Seats</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-briefcase-fill"></i></div>
                            <div class="feature-value"><?php echo $car['luggage_capacity']; ?></div>
                            <div class="feature-label">Luggage</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-gear-fill"></i></div>
                            <div class="feature-value"><?php echo ucfirst($car['transmission']); ?></div>
                            <div class="feature-label">Transmission</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-fuel-pump-fill"></i></div>
                            <div class="feature-value"><?php echo ucfirst($car['fuel_type']); ?></div>
                            <div class="feature-label">Fuel Type</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-speedometer2"></i></div>
                            <div class="feature-value"><?php echo $car['free_km_per_day']; ?> km</div>
                            <div class="feature-label">Free per day</div>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon"><i class="bi bi-shield-check"></i></div>
                            <div class="feature-value"><?php echo $car['insurance_included'] ? 'Yes' : 'Optional'; ?></div>
                            <div class="feature-label">Insurance</div>
                        </div>
                    </div>
                </div>

                <!-- Features List -->
                <?php if (!empty($features)): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-check-circle-fill text-success"></i> Features</h2>
                        <div class="features-list">
                            <?php foreach ($features as $feature): ?>
                                <div class="feature-item">
                                    <i class="bi bi-check-circle-fill"></i>
                                    <span><?php echo ucfirst(str_replace('_', ' ', $feature)); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Pickup Locations -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-geo-alt"></i> Pickup Locations</h2>
                    <div class="locations-container">
                        <?php foreach ($pickupLocs as $loc): ?>
                            <span class="location-tag"><i class="bi bi-geo-alt"></i> <?php echo sanitize($loc); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Rental Terms -->
                <div class="info-card">
                    <h2 class="card-title"><i class="bi bi-file-text"></i> Rental Terms</h2>
                    <ul style="list-style: none; padding: 0;">
                        <li style="margin-bottom: 12px;"><i class="bi bi-check-circle-fill text-success"></i> Free cancellation up to 24 hours before pickup</li>
                        <li style="margin-bottom: 12px;"><i class="bi bi-check-circle-fill text-success"></i> <?php echo $car['free_km_per_day']; ?> km included per day</li>
                        <?php if ($car['excess_km_charge']): ?>
                            <li style="margin-bottom: 12px;"><i class="bi bi-check-circle-fill text-success"></i> Excess mileage: <?php echo formatPrice($car['excess_km_charge']); ?>/km</li>
                        <?php endif; ?>
                        <li style="margin-bottom: 12px;"><i class="bi bi-check-circle-fill text-success"></i> Minimum driver age: 23 years</li>
                        <li style="margin-bottom: 12px;"><i class="bi bi-check-circle-fill text-success"></i> Operating hours: <?php echo $car['operating_hours'] ?? '24/7'; ?></li>
                    </ul>
                </div>

                <!-- Reviews -->
                <?php if (!empty($reviews)): ?>
                    <div class="info-card">
                        <h2 class="card-title"><i class="bi bi-star-fill text-warning"></i> Customer Reviews</h2>
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <div class="reviewer">
                                        <div class="reviewer-avatar">
                                            <?php echo strtoupper(substr($review['first_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'], 0, 1) . '.'); ?></strong>
                                            <div class="review-date"><?php echo timeAgo($review['created_at']); ?></div>
                                        </div>
                                    </div>
                                    <div>
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>" style="font-size: 11px; color: #febb02;"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <div><?php echo sanitize($review['comment']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Booking Sidebar -->
            <div class="col-lg-4">
                <div class="booking-sidebar">
                    <div class="booking-card">
                        <div class="price-display">
                            <div class="price-amount"><?php echo $priceWithTaxFormatted; ?></div>
                            <div class="price-period">per day (tax included)</div>
                        </div>

                        <form class="booking-form" method="GET" action="booking.php">
                            <input type="hidden" name="car_id" value="<?php echo $car['car_id']; ?>">

                            <div class="form-group">
                                <label>Pickup Location</label>
                                <select name="pickup_location" class="form-control" required>
                                    <option value="">Select location</option>
                                    <?php foreach ($pickupLocs as $loc): ?>
                                        <option value="<?php echo sanitize($loc); ?>"><?php echo sanitize($loc); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="date-grid">
                                <div class="form-group">
                                    <label>Pickup Date</label>
                                    <input type="date" name="pickup_date" class="form-control"
                                        value="<?php echo $pickupDate; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Return Date</label>
                                    <input type="date" name="return_date" class="form-control"
                                        value="<?php echo $returnDate; ?>" required>
                                </div>
                            </div>

                            <div class="total-price">
                                <div class="total-price-label">Total for <span id="daysCount"><?php echo $days; ?></span> day<?php echo $days > 1 ? 's' : ''; ?></div>
                                <div class="total-price-value"><?php echo $totalPriceFormatted; ?></div>
                            </div>

                            <button type="submit" class="btn-reserve">
                                Continue to Book
                            </button>

                            <div class="security-note">
                                <i class="bi bi-shield-check"></i> Free cancellation • No hidden fees
                            </div>
                        </form>
                    </div>

                    <!-- Company Card -->
                    <div class="company-card">
                        <div class="company-header">
                            <div class="company-logo">
                                <?php if (!empty($car['logo'])): ?>
                                    <img src="<?php echo getImageUrl($car['logo'], 'car'); ?>" alt="<?php echo sanitize($car['company_name']); ?>">
                                <?php else: ?>
                                    <i class="bi bi-building" style="font-size: 30px; color: #0071c2;"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4 style="margin-bottom: 4px;"><?php echo sanitize($car['company_name']); ?></h4>
                                <?php if ($car['company_rating']): ?>
                                    <div><i class="bi bi-star-fill" style="color: #febb02;"></i> <?php echo number_format($car['company_rating'], 1); ?> (<?php echo $car['company_reviews']; ?> reviews)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="company-contact">
                            <div><i class="bi bi-telephone"></i> <?php echo $car['phone'] ?? 'Contact for details'; ?></div>
                            <div><i class="bi bi-envelope"></i> <?php echo $car['email'] ?? 'info@company.com'; ?></div>
                            <div><i class="bi bi-clock"></i> <?php echo $car['operating_hours'] ?? '24/7 support'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Similar Cars -->
        <?php if (!empty($similarCars)): ?>
            <div class="similar-section">
                <h2 class="card-title">Similar cars from <?php echo sanitize($car['company_name']); ?></h2>
                <div class="similar-grid">
                    <?php foreach ($similarCars as $similar):
                        $simImages = json_decode($similar['images'] ?? '[]', true);
                        $simImage = $simImages[0] ?? '';
                    ?>
                        <a href="detail.php?id=<?php echo $similar['car_id']; ?>&pickup_date=<?php echo $pickupDate; ?>&return_date=<?php echo $returnDate; ?>" class="similar-card">
                            <div class="similar-image">
                                <img src="<?php echo getImageUrl($simImage, 'car'); ?>"
                                    alt="<?php echo sanitize($similar['brand'] . ' ' . $similar['model']); ?>"
                                    onerror="this.src='https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=400&q=60'">
                            </div>
                            <div class="similar-content">
                                <div class="similar-title"><?php echo sanitize($similar['brand'] . ' ' . $similar['model']); ?></div>
                                <div class="similar-price"><?php echo displayCustomerPrice($similar['daily_rate']); ?> <span style="font-size: 12px;">/day</span></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Gallery image change
    function changeImage(imageSrc, element) {
        document.getElementById('mainImage').src = imageSrc;
        document.querySelectorAll('.thumbnail-item').forEach(item => {
            item.classList.remove('active');
        });
        element.classList.add('active');
    }

    // Calculate days between two dates
    function calculateDays(startDate, endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const diffTime = Math.abs(end - start);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }

    // Update the booking sidebar dynamically
    function updateBookingSidebar() {
        const pickupDateInput = document.querySelector('input[name="pickup_date"]');
        const returnDateInput = document.querySelector('input[name="return_date"]');
        const pickupLocationSelect = document.querySelector('select[name="pickup_location"]');

        if (!pickupDateInput || !returnDateInput) return;

        const pickupDate = pickupDateInput.value;
        const returnDate = returnDateInput.value;
        const pickupLocation = pickupLocationSelect ? pickupLocationSelect.value : '';

        if (pickupDate && returnDate) {
            // Calculate days
            const days = calculateDays(pickupDate, returnDate);
            const dailyRate = <?php echo (float)$car['daily_rate']; ?>;
            const totalNumeric = dailyRate * days;

            // Update total price display
            const totalPriceElement = document.querySelector('.total-price-value');
            const daysElement = document.querySelector('.total-price-label');

            if (totalPriceElement) {
                totalPriceElement.textContent = formatCurrency(totalNumeric);
            }
            if (daysElement) {
                daysElement.textContent = `Total for ${days} day${days > 1 ? 's' : ''}`;
            }

            // Update the form action URL
            const form = document.querySelector('.booking-form');
            if (form) {
                let actionUrl = `booking.php?car_id=<?php echo $car['car_id']; ?>&pickup_date=${pickupDate}&return_date=${returnDate}`;
                if (pickupLocation) {
                    actionUrl += `&pickup_location=${encodeURIComponent(pickupLocation)}`;
                }
                form.action = actionUrl;
            }
        }
    }

    // Format currency
    function formatCurrency(amount) {
        return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
    }

    // Date validation and auto-update
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        const pickupInput = document.querySelector('input[name="pickup_date"]');
        const returnInput = document.querySelector('input[name="return_date"]');
        const locationSelect = document.querySelector('select[name="pickup_location"]');

        // Set min dates
        if (pickupInput) pickupInput.min = today;
        if (returnInput) returnInput.min = today;

        // Handle pickup date change
        pickupInput?.addEventListener('change', function() {
            const minReturn = new Date(this.value);
            minReturn.setDate(minReturn.getDate() + 1);
            returnInput.min = minReturn.toISOString().split('T')[0];

            if (new Date(returnInput.value) <= new Date(this.value)) {
                returnInput.value = minReturn.toISOString().split('T')[0];
            }

            updateBookingSidebar();
        });

        // Handle return date change
        returnInput?.addEventListener('change', function() {
            updateBookingSidebar();
        });

        // Handle location change
        locationSelect?.addEventListener('change', function() {
            updateBookingSidebar();
        });

        // Initial update
        updateBookingSidebar();
    });
</script>

<?php require_once '../includes/footer.php'; ?>