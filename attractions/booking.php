<?php
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /gorwanda-plus/login.php');
    exit;
}

// Get booking parameters
$attractionId = intval($_GET['id'] ?? 0);
$tierId = intval($_GET['tier'] ?? 0);
$experienceDate = $_GET['date'] ?? '';
$participants = intval($_GET['participants'] ?? 2);

// Validate required parameters
if (!$attractionId || !$tierId || !$experienceDate) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

// Get attraction details
// Update this query (around line 30-40)
$stmt = $db->prepare("
    SELECT a.*, l.name as location_name, u.phone as owner_phone
    FROM attractions a
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users u ON a.owner_id = u.user_id
    WHERE a.attraction_id = ? AND a.is_active = 1 AND a.is_verified = 1
");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: /gorwanda-plus/?type=attractions');
    exit;
}

// Get tier details
$stmt = $db->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM bookings b 
            WHERE b.attraction_tier_id = t.tier_id 
            AND b.status IN ('confirmed', 'pending')
            AND b.experience_date = ?) as bookings_count
    FROM attraction_tiers t
    WHERE t.tier_id = ? AND t.attraction_id = ? AND t.is_active = 1
");
$stmt->execute([$experienceDate, $tierId, $attractionId]);
$tier = $stmt->fetch();

if (!$tier) {
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Validate date
$selectedDate = new DateTime($experienceDate);
$today = new DateTime();

if ($selectedDate < $today) {
    $_SESSION['error'] = "Experience date cannot be in the past";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Check availability for selected date
$stmt = $db->prepare("
    SELECT aa.max_bookings, aa.bookings_made, aa.is_blocked, aa.price_override
    FROM attraction_availability aa
    WHERE aa.tier_id = ? AND aa.date = ?
");
$stmt->execute([$tierId, $experienceDate]);
$availability = $stmt->fetch();

$maxBookings = $availability['max_bookings'] ?? $tier['max_participants'] ?? 20;
$bookingsMade = $availability['bookings_made'] ?? 0;
$isBlocked = $availability['is_blocked'] ?? false;
$availableSpots = $maxBookings - $bookingsMade;

if ($isBlocked || $availableSpots < $participants) {
    $_SESSION['error'] = "Not enough spots available for selected date. Please choose another date.";
    header('Location: /gorwanda-plus/attractions/detail.php?id=' . $attractionId);
    exit;
}

// Calculate prices with tax
$taxRate = getTaxRate();
$basePrice = $availability['price_override'] ?? $tier['base_price'];
$pricePerPerson = $basePrice;
$subtotal = $basePrice * $participants;
$taxAmount = $subtotal * ($taxRate / 100);
$totalAmount = $subtotal + $taxAmount;

// Get included items for this tier
$inclusions = $tier['inclusions'] ? json_decode($tier['inclusions'], true) : [];

// Format duration
$durationHours = floor($attraction['duration_minutes'] / 60);
$durationMinutes = $attraction['duration_minutes'] % 60;
$durationText = '';
if ($durationHours > 0) $durationText .= $durationHours . 'h';
if ($durationMinutes > 0) $durationText .= ($durationHours > 0 ? ' ' : '') . $durationMinutes . 'm';
if (!$durationText) $durationText = '—';

$pageTitle = "Complete Your Booking - " . $attraction['attraction_name'];
$hideSearch = true;
require_once '../includes/header.php';
?>

<style>
    /* Booking Page Styles */
    .booking-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 20px;
    }

    /* Breadcrumb */
    .breadcrumb {
        margin-bottom: 24px;
        font-size: 12px;
    }

    .breadcrumb a {
        color: #0071c2;
        text-decoration: none;
    }

    /* Progress Steps */
    .progress-steps {
        display: flex;
        justify-content: center;
        margin-bottom: 32px;
    }

    .step {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 16px;
    }

    .step.active .step-number {
        background: #0071c2;
        color: white;
        border-color: #0071c2;
    }

    .step.completed .step-number {
        background: #008009;
        color: white;
        border-color: #008009;
    }

    .step-number {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 2px solid #e7e7e7;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 12px;
    }

    .step-label {
        font-size: 12px;
        font-weight: 500;
    }

    /* Layout */
    .booking-layout {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 24px;
    }

    /* Main Content */
    .booking-main {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        overflow: hidden;
    }

    .booking-section {
        padding: 20px;
        border-bottom: 1px solid #e7e7e7;
    }

    .booking-section:last-child {
        border-bottom: none;
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .section-title i {
        color: #0071c2;
    }

    /* Form Styles */
    .form-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 16px;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 4px;
        text-transform: uppercase;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e7e7e7;
        border-radius: 6px;
        font-size: 13px;
        transition: all 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: #0071c2;
        box-shadow: 0 0 0 2px rgba(0, 113, 194, 0.1);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 80px;
    }

    /* Experience Summary */
    .experience-summary {
        display: flex;
        gap: 16px;
        padding: 16px;
        background: #f5f5f5;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .exp-image {
        width: 100px;
        height: 100px;
        border-radius: 6px;
        object-fit: cover;
        flex-shrink: 0;
    }

    .exp-details h3 {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .exp-details p {
        font-size: 11px;
        color: #6b6b6b;
        margin-bottom: 4px;
    }

    /* Booking Details */
    .booking-details {
        background: #f5f5f5;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 20px;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #e7e7e7;
        font-size: 12px;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        color: #6b6b6b;
    }

    .detail-value {
        font-weight: 600;
    }

    /* Selected Tier Info */
    .tier-info {
        background: #f5f5f5;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 20px;
        border-left: 3px solid #0071c2;
    }

    .tier-name {
        font-weight: 700;
        font-size: 14px;
        margin-bottom: 6px;
    }

    .tier-inclusions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .tier-inclusion {
        font-size: 10px;
        padding: 2px 8px;
        background: white;
        border-radius: 4px;
        color: #6b6b6b;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Sidebar */
    .booking-sidebar {
        position: sticky;
        top: 20px;
    }

    .price-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    }

    .price-breakdown {
        margin: 16px 0;
    }

    .price-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 12px;
    }

    .price-row.total {
        border-top: 1px solid #e7e7e7;
        margin-top: 8px;
        padding-top: 12px;
        font-weight: 700;
        font-size: 15px;
    }

    .price-row.tax {
        font-size: 10px;
        color: #6b6b6b;
    }

    .confirm-btn {
        width: 100%;
        padding: 12px;
        background: #0071c2;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.2s;
        margin-top: 16px;
    }

    .confirm-btn:hover {
        background: #003580;
    }

    .cancel-btn {
        width: 100%;
        padding: 10px;
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 12px;
        text-decoration: none;
        display: block;
        text-align: center;
        color: #6b6b6b;
    }

    .cancel-btn:hover {
        border-color: #c41c1c;
        color: #c41c1c;
    }

    .security-note {
        text-align: center;
        font-size: 10px;
        color: #6b6b6b;
        margin-top: 12px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .booking-layout {
            grid-template-columns: 1fr;
        }

        .booking-sidebar {
            position: static;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .experience-summary {
            flex-direction: column;
        }

        .exp-image {
            width: 100%;
            height: 140px;
        }

        .progress-steps {
            display: none;
        }
    }
</style>

<div class="booking-container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/gorwanda-plus/"><?php echo tr('home', 'Home'); ?></a> &gt;
        <a href="/gorwanda-plus/?type=attractions"><?php echo tr('experiences', 'Experiences'); ?></a> &gt;
        <a href="/gorwanda-plus/attractions/detail.php?id=<?php echo $attractionId; ?>"><?php echo sanitize($attraction['attraction_name']); ?></a> &gt;
        <span><?php echo tr('complete_booking', 'Complete Booking'); ?></span>
    </div>

    <!-- Progress Steps -->
    <div class="progress-steps">
        <div class="step completed">
            <div class="step-number"><i class="bi bi-check-lg"></i></div>
            <span class="step-label"><?php echo tr('select_experience', 'Select Experience'); ?></span>
        </div>
        <div class="step active">
            <div class="step-number">2</div>
            <span class="step-label"><?php echo tr('enter_details', 'Enter Details'); ?></span>
        </div>
        <div class="step">
            <div class="step-number">3</div>
            <span class="step-label"><?php echo tr('payment', 'Payment'); ?></span>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="booking-layout">
        <!-- Left Column -->
        <div class="booking-main">
            <!-- Experience Summary -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-ticket-perforated"></i>
                    <?php echo tr('your_experience', 'Your Experience'); ?>
                </h2>

                <?php
                $images = json_decode($attraction['gallery_images'] ?? '[]', true);
                $mainImage = $attraction['main_image'] ?: ($images[0] ?? '');
                ?>
                <div class="experience-summary">
                    <img src="<?php echo getImageUrl($mainImage, 'attraction'); ?>"
                        alt="<?php echo sanitize($attraction['attraction_name']); ?>"
                        class="exp-image"
                        onerror="this.src='https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=200&q=60'">
                    <div class="exp-details">
                        <h3><?php echo sanitize($attraction['attraction_name']); ?></h3>
                        <p><i class="bi bi-geo-alt"></i> <?php echo sanitize($attraction['location_name'] ?: 'Rwanda'); ?></p>
                        <p><i class="bi bi-clock"></i> <?php echo $durationText; ?> • <?php echo ucfirst($attraction['difficulty_level']); ?></p>
                    </div>
                </div>

                <div class="booking-details">
                    <div class="detail-row">
                        <span class="detail-label"><?php echo tr('date', 'Date'); ?></span>
                        <span class="detail-value"><?php echo date('l, F j, Y', strtotime($experienceDate)); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><?php echo tr('participants', 'Participants'); ?></span>
                        <span class="detail-value"><?php echo $participants; ?> <?php echo tr($participants > 1 ? 'persons' : 'person', $participants > 1 ? 'persons' : 'person'); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label"><?php echo tr('available_spots', 'Available spots'); ?></span>
                        <span class="detail-value"><?php echo $availableSpots; ?> <?php echo tr('left', 'left'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Selected Tier Information -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-tags"></i>
                    <?php echo tr('selected_package', 'Selected Package'); ?>
                </h2>

                <div class="tier-info">
                    <div class="tier-name"><?php echo sanitize($tier['tier_name']); ?></div>
                    <?php if ($tier['description']): ?>
                        <div style="font-size: 11px; color: #6b6b6b; margin-bottom: 8px;"><?php echo sanitize($tier['description']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($inclusions)): ?>
                        <div class="tier-inclusions">
                            <?php foreach (array_slice($inclusions, 0, 5) as $item): ?>
                                <span class="tier-inclusion"><i class="bi bi-check-lg" style="color: #008009;"></i> <?php echo ucfirst(str_replace('_', ' ', $item)); ?></span>
                            <?php endforeach; ?>
                            <?php if (count($inclusions) > 5): ?>
                                <span class="tier-inclusion">+<?php echo count($inclusions) - 5; ?> more</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Guest Information Form -->
            <div class="booking-section">
                <h2 class="section-title">
                    <i class="bi bi-person"></i>
                    <?php echo tr('participant_information', 'Participant Information'); ?>
                </h2>

                <form method="POST" action="process-booking.php" id="bookingForm">
                    <input type="hidden" name="attraction_id" value="<?php echo $attractionId; ?>">
                    <input type="hidden" name="tier_id" value="<?php echo $tierId; ?>">
                    <input type="hidden" name="experience_date" value="<?php echo $experienceDate; ?>">
                    <input type="hidden" name="participants" value="<?php echo $participants; ?>">
                    <input type="hidden" name="base_price" value="<?php echo $basePrice; ?>">
                    <input type="hidden" name="subtotal" value="<?php echo $subtotal; ?>">
                    <input type="hidden" name="tax_amount" value="<?php echo $taxAmount; ?>">
                    <input type="hidden" name="total_amount" value="<?php echo $totalAmount; ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label><?php echo tr('first_name', 'First name'); ?> *</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($currentUser['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?php echo tr('last_name', 'Last name'); ?> *</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($currentUser['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><?php echo tr('email', 'Email'); ?> *</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($currentUser['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><?php echo tr('phone', 'Phone'); ?> *</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($currentUser['phone']); ?>" required>
                        </div>
                    </div>

                    <!-- Participant Names (for each participant) -->
                    <?php if ($participants > 1): ?>
                        <div class="form-group">
                            <label><?php echo tr('participant_names', 'Participant Names'); ?></label>
                            <textarea name="participant_names" class="form-control" rows="3" placeholder="<?php echo tr('participant_names_placeholder', 'Enter names of all participants (one per line)'); ?>"></textarea>
                            <small style="font-size: 10px; color: #6b6b6b;"><?php echo tr('participant_names_note', 'Please provide names for all participants for check-in purposes'); ?></small>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label><?php echo tr('special_requests', 'Special Requests'); ?> (<?php echo tr('optional', 'optional'); ?>)</label>
                        <textarea name="special_requests" class="form-control" rows="3" placeholder="<?php echo tr('special_requests_placeholder', 'Dietary restrictions, accessibility needs, etc.'); ?>"></textarea>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column - Price Summary -->
        <div class="booking-sidebar">
            <div class="price-card">
                <h3 style="font-size: 15px; margin-bottom: 12px;"><?php echo tr('price_breakdown', 'Price breakdown'); ?></h3>

                <div class="price-breakdown">
                    <div class="price-row">
                        <span><?php echo sanitize($tier['tier_name']); ?> (<?php echo $participants; ?> <?php echo tr('participants', 'participants'); ?>)</span>
                        <span><?php echo formatPrice($subtotal); ?></span>
                    </div>
                    <div class="price-row tax">
                        <span><?php echo tr('vat', 'VAT'); ?> (<?php echo $taxRate; ?>%)</span>
                        <span><?php echo formatPrice($taxAmount); ?></span>
                    </div>
                    <div class="price-row total">
                        <span><?php echo tr('total_you_pay', 'Total you pay'); ?></span>
                        <span><?php echo formatPrice($totalAmount); ?></span>
                    </div>
                </div>

                <div style="background: #f5f5f5; border-radius: 6px; padding: 10px; margin: 12px 0;">
                    <div style="font-size: 10px; color: #6b6b6b;">
                        <i class="bi bi-check-circle-fill" style="color: #008009;"></i>
                        <?php echo tr('free_cancellation_note', 'Free cancellation up to 24 hours before experience'); ?>
                    </div>
                </div>

                <button type="submit" form="bookingForm" class="confirm-btn" onclick="return confirmBooking()">
                    <?php echo tr('proceed_to_payment', 'Proceed to Payment'); ?>
                </button>

                <a href="/gorwanda-plus/attractions/detail.php?id=<?php echo $attractionId; ?>" class="cancel-btn">
                    <?php echo tr('cancel_go_back', 'Cancel and go back'); ?>
                </a>

                <div class="security-note">
                    <i class="bi bi-shield-lock"></i> <?php echo tr('secure_payment_note', 'Your payment is secure • No fees charged yet'); ?>
                </div>
            </div>

            <!-- Help Card -->
            <div class="price-card" style="background: #f5f5f5;">
                <h3 style="font-size: 13px; margin-bottom: 8px;"><?php echo tr('need_help', 'Need help?'); ?></h3>
                <p style="font-size: 11px; color: #6b6b6b; margin-bottom: 12px;">
                    <?php echo tr('contact_provider_note', 'Contact the experience provider directly.'); ?>
                </p>
                <?php if ($attraction['owner_phone']): ?>
                    <div style="font-size: 12px;">
                        <i class="bi bi-telephone-fill"></i> <a href="tel:<?php echo $attraction['owner_phone']; ?>" style="color: #0071c2; text-decoration: none;"><?php echo $attraction['owner_phone']; ?></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmBooking() {
        const firstName = document.querySelector('input[name="first_name"]').value;
        const lastName = document.querySelector('input[name="last_name"]').value;
        const email = document.querySelector('input[name="email"]').value;
        const phone = document.querySelector('input[name="phone"]').value;

        if (!firstName || !lastName || !email || !phone) {
            alert('<?php echo tr('fill_required_fields', 'Please fill in all required fields'); ?>');
            return false;
        }

        if (!email.includes('@')) {
            alert('<?php echo tr('valid_email', 'Please enter a valid email address'); ?>');
            return false;
        }

        return confirm('<?php echo tr('confirm_booking_message', 'Please verify your booking details before proceeding to payment.'); ?>');
    }
</script>

<?php require_once '../includes/footer.php'; ?>