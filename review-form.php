<?php
require_once 'includes/functions.php';
requireLogin();

$bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$bookingId) {
    header('Location: bookings.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get booking details and verify ownership
$stmt = $db->prepare("
    SELECT 
        b.*,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            WHEN b.booking_type = 'attraction' THEN a.attraction_name
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_id
            WHEN b.booking_type = 'car_rental' THEN cr.rental_id
            WHEN b.booking_type = 'attraction' THEN a.attraction_id
        END as item_id,
        CASE 
            WHEN b.booking_type = 'stay' THEN 'stay'
            WHEN b.booking_type = 'car_rental' THEN 'car_rental'
            WHEN b.booking_type = 'attraction' THEN 'attraction'
        END as review_type,
        s.main_image as stay_image
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    WHERE b.booking_id = ? AND b.user_id = ? AND b.status = 'completed'
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    header('Location: bookings.php');
    exit;
}

// Check if user already reviewed this booking
$stmt = $db->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND user_id = ?");
$stmt->execute([$bookingId, $userId]);
if ($stmt->fetch()) {
    $_SESSION['error'] = "You have already reviewed this booking";
    header('Location: bookings.php');
    exit;
}

$pageTitle = 'Write a Review - ' . $booking['item_name'];
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
    .review-container {
        max-width: 800px;
        margin: 40px auto;
        padding: 0 20px;
    }

    .review-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    .review-header {
        background: linear-gradient(135deg, #003580 0%, #001b4f 100%);
        color: white;
        padding: 30px;
        text-align: center;
    }

    .review-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .review-header p {
        opacity: 0.9;
        font-size: 14px;
    }

    .booking-info {
        padding: 24px;
        background: #f8f9fa;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }

    .booking-image {
        width: 80px;
        height: 80px;
        border-radius: 12px;
        overflow: hidden;
        flex-shrink: 0;
    }

    .booking-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .booking-details h3 {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .booking-details p {
        font-size: 13px;
        color: #6b6b6b;
        margin: 0;
    }

    .booking-ref {
        font-family: monospace;
        font-size: 12px;
        color: #9ca3af;
        margin-top: 6px;
    }

    .review-form {
        padding: 32px;
    }

    .rating-section {
        text-align: center;
        margin-bottom: 32px;
        padding-bottom: 24px;
        border-bottom: 1px solid #e7e7e7;
    }

    .rating-label {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 16px;
        color: #1a1a1a;
    }

    .rating-stars {
        display: flex;
        justify-content: center;
        gap: 12px;
        margin-bottom: 12px;
    }

    .star-rating {
        font-size: 40px;
        cursor: pointer;
        transition: all 0.2s;
        color: #d1d5db;
    }

    .star-rating:hover,
    .star-rating.active {
        color: #febb02;
        transform: scale(1.1);
    }

    .rating-value {
        font-size: 14px;
        color: #6b6b6b;
    }

    .rating-value span {
        font-weight: 700;
        font-size: 18px;
        color: #febb02;
    }

    .form-group {
        margin-bottom: 24px;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #1a1a1a;
    }

    .form-label .required {
        color: #dc2626;
        margin-left: 4px;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e7e7e7;
        border-radius: 12px;
        font-size: 15px;
        transition: all 0.2s;
        font-family: inherit;
    }

    .form-control:focus {
        outline: none;
        border-color: #0066ff;
        box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 120px;
    }

    .char-counter {
        text-align: right;
        font-size: 12px;
        color: #9ca3af;
        margin-top: 6px;
    }

    .categories-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .category-item {
        background: #f8f9fa;
        border-radius: 12px;
        padding: 16px;
    }

    .category-label {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 12px;
        color: #1a1a1a;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .category-stars {
        display: flex;
        gap: 6px;
    }

    .category-star {
        font-size: 20px;
        cursor: pointer;
        color: #d1d5db;
        transition: all 0.2s;
    }

    .category-star:hover,
    .category-star.active {
        color: #febb02;
    }

    .category-value {
        font-size: 14px;
        font-weight: 600;
        color: #febb02;
    }

    .form-actions {
        display: flex;
        gap: 16px;
        margin-top: 32px;
        padding-top: 24px;
        border-top: 1px solid #e7e7e7;
    }

    .btn-submit {
        flex: 1;
        padding: 14px 24px;
        background: linear-gradient(135deg, #0066ff, #003b95);
        color: white;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 102, 255, 0.3);
    }

    .btn-cancel {
        flex: 1;
        padding: 14px 24px;
        background: white;
        color: #1a1a1a;
        border: 1.5px solid #e7e7e7;
        border-radius: 12px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        text-decoration: none;
        text-align: center;
        transition: all 0.2s;
    }

    .btn-cancel:hover {
        border-color: #dc2626;
        color: #dc2626;
    }

    @media (max-width: 640px) {
        .categories-grid {
            grid-template-columns: 1fr;
        }

        .form-actions {
            flex-direction: column;
        }

        .star-rating {
            font-size: 32px;
            gap: 8px;
        }
    }
</style>

<div class="review-container">
    <div class="review-card">
        <div class="review-header">
            <h1>Share Your Experience</h1>
            <p>Your feedback helps other travelers make better choices</p>
        </div>

        <div class="booking-info">
            <div class="booking-image">
                <img src="<?php echo getImageUrl($booking['stay_image'] ?? '', 'stay'); ?>"
                    alt="<?php echo sanitize($booking['item_name']); ?>"
                    onerror="this.src='https://placehold.co/400x300?text=No+Image'">
            </div>
            <div class="booking-details">
                <h3><?php echo sanitize($booking['item_name']); ?></h3>
                <p>
                    <?php
                    $date = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
                    echo date('F d, Y', strtotime($date));
                    ?>
                </p>
                <div class="booking-ref">Booking #<?php echo $booking['booking_reference']; ?></div>
            </div>
        </div>

        <form method="POST" action="process-review.php" class="review-form" id="reviewForm">
            <input type="hidden" name="booking_id" value="<?php echo $bookingId; ?>">
            <input type="hidden" name="item_id" value="<?php echo $booking['item_id']; ?>">
            <input type="hidden" name="review_type" value="<?php echo $booking['review_type']; ?>">
            <input type="hidden" name="overall_rating" id="overallRating" value="0">

            <!-- Overall Rating -->
            <div class="rating-section">
                <div class="rating-label">Overall Rating</div>
                <div class="rating-stars" id="ratingStars">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <i class="bi bi-star-fill star-rating" data-value="<?php echo $i; ?>"></i>
                    <?php endfor; ?>
                </div>
                <div class="rating-value" id="ratingValue">
                    Click to rate <span id="ratingDisplay">0</span>/10
                </div>
            </div>

            <!-- Category Ratings (for stays only) -->
            <?php if ($booking['review_type'] == 'stay'): ?>
                <div class="rating-label">Rate Specific Aspects</div>
                <div class="categories-grid" id="categoriesGrid">
                    <?php
                    $categories = [
                        'cleanliness' => 'Cleanliness',
                        'service' => 'Service',
                        'location' => 'Location',
                        'value' => 'Value for Money',
                        'comfort' => 'Comfort',
                        'facilities' => 'Facilities'
                    ];
                    foreach ($categories as $key => $label):
                    ?>
                        <div class="category-item">
                            <div class="category-label">
                                <?php echo $label; ?>
                                <span class="category-value" id="<?php echo $key; ?>Value">0/10</span>
                            </div>
                            <div class="category-stars" data-category="<?php echo $key; ?>">
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <i class="bi bi-star-fill category-star" data-value="<?php echo $i; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="categories[<?php echo $key; ?>]" id="<?php echo $key; ?>Input" value="0">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Review Title -->
            <div class="form-group">
                <label class="form-label">Review Title <span class="required">*</span></label>
                <input type="text" name="title" class="form-control"
                    placeholder="Summarize your experience" required maxlength="100">
                <div class="char-counter"><span id="titleCount">0</span>/100</div>
            </div>

            <!-- Review Comment -->
            <div class="form-group">
                <label class="form-label">Your Review <span class="required">*</span></label>
                <textarea name="comment" class="form-control"
                    placeholder="What did you like or dislike? Share details about your experience..."
                    required maxlength="1000" rows="5"></textarea>
                <div class="char-counter"><span id="commentCount">0</span>/1000</div>
            </div>

            <!-- Pros & Cons (optional) -->
            <div class="form-group">
                <label class="form-label">What did you like? (Optional)</label>
                <textarea name="positive_points" class="form-control"
                    placeholder="Examples: Great location, friendly staff, comfortable bed..."
                    rows="3" maxlength="500"></textarea>
                <div class="char-counter"><span id="positiveCount">0</span>/500</div>
            </div>

            <div class="form-group">
                <label class="form-label">What could be improved? (Optional)</label>
                <textarea name="negative_points" class="form-control"
                    placeholder="Examples: Noisy at night, slow Wi-Fi, limited parking..."
                    rows="3" maxlength="500"></textarea>
                <div class="char-counter"><span id="negativeCount">0</span>/500</div>
            </div>

            <!-- Anonymous option (future feature) -->
            <div class="form-group" style="display: none;">
                <label class="checkbox-wrapper">
                    <input type="checkbox" name="anonymous" value="1">
                    <span>Post as anonymous</span>
                </label>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="bookings.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit">Submit Review</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ============================================
    // OVERALL RATING STARS
    // ============================================
    const stars = document.querySelectorAll('#ratingStars .star-rating');
    const ratingDisplay = document.getElementById('ratingDisplay');
    const overallRatingInput = document.getElementById('overallRating');

    stars.forEach(star => {
        star.addEventListener('click', function() {
            const value = parseInt(this.dataset.value);
            overallRatingInput.value = value;
            ratingDisplay.textContent = value;

            stars.forEach((s, index) => {
                if (index < value) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        });

        star.addEventListener('mouseenter', function() {
            const value = parseInt(this.dataset.value);
            stars.forEach((s, index) => {
                if (index < value) {
                    s.style.color = '#febb02';
                } else {
                    s.style.color = '#d1d5db';
                }
            });
        });

        star.addEventListener('mouseleave', function() {
            const currentValue = parseInt(overallRatingInput.value);
            stars.forEach((s, index) => {
                if (index < currentValue) {
                    s.style.color = '#febb02';
                } else {
                    s.style.color = '#d1d5db';
                }
            });
        });
    });

    // ============================================
    // CATEGORY RATINGS (for stays)
    // ============================================
    document.querySelectorAll('.category-stars').forEach(container => {
        const category = container.dataset.category;
        const stars = container.querySelectorAll('.category-star');
        const valueDisplay = document.getElementById(`${category}Value`);
        const inputField = document.getElementById(`${category}Input`);

        stars.forEach(star => {
            star.addEventListener('click', function() {
                const value = parseInt(this.dataset.value);
                if (inputField) inputField.value = value;
                if (valueDisplay) valueDisplay.textContent = value;

                stars.forEach((s, index) => {
                    if (index < value) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });

            star.addEventListener('mouseenter', function() {
                const value = parseInt(this.dataset.value);
                stars.forEach((s, index) => {
                    if (index < value) {
                        s.style.color = '#febb02';
                    } else {
                        s.style.color = '#d1d5db';
                    }
                });
            });

            star.addEventListener('mouseleave', function() {
                const currentValue = parseInt(inputField?.value || 0);
                stars.forEach((s, index) => {
                    if (index < currentValue) {
                        s.style.color = '#febb02';
                    } else {
                        s.style.color = '#d1d5db';
                    }
                });
            });
        });
    });

    // ============================================
    // CHARACTER COUNTERS
    // ============================================
    function updateCounter(inputId, counterId, maxLength) {
        const input = document.getElementById(inputId);
        const counter = document.getElementById(counterId);
        if (input && counter) {
            const length = input.value.length;
            counter.textContent = length;
            if (length > maxLength) {
                counter.style.color = '#dc2626';
                input.value = input.value.substring(0, maxLength);
                counter.textContent = maxLength;
            } else {
                counter.style.color = '#9ca3af';
            }
        }
    }

    document.querySelector('input[name="title"]')?.addEventListener('input', () => updateCounter('title', 'titleCount', 100));
    document.querySelector('textarea[name="comment"]')?.addEventListener('input', () => updateCounter('comment', 'commentCount', 1000));
    document.querySelector('textarea[name="positive_points"]')?.addEventListener('input', () => updateCounter('positive', 'positiveCount', 500));
    document.querySelector('textarea[name="negative_points"]')?.addEventListener('input', () => updateCounter('negative', 'negativeCount', 500));

    // Initialize counters
    updateCounter('title', 'titleCount', 100);
    updateCounter('comment', 'commentCount', 1000);
    updateCounter('positive', 'positiveCount', 500);
    updateCounter('negative', 'negativeCount', 500);
</script>

<?php require_once 'includes/footer.php'; ?>