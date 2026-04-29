<?php
$pageTitle = 'Property Added Successfully';
require_once 'includes/stays_header.php';

$propertyId = intval($_GET['id'] ?? 0);
$db = getDB();

// Get property details with stats
$stmt = $db->prepare("
    SELECT s.*, 
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id) as total_rooms,
           (SELECT COUNT(*) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as active_rooms,
           l.name as location_name
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.stay_id = ? AND s.owner_id = ?
");
$stmt->execute([$propertyId, $_SESSION['user_id']]);
$property = $stmt->fetch();

if (!$property) {
    header('Location: properties.php');
    exit;
}

// Get room details
$stmt = $db->prepare("
    SELECT room_name, base_price, max_guests, num_rooms_available 
    FROM stay_rooms 
    WHERE stay_id = ? AND is_active = 1
    ORDER BY base_price ASC
");
$stmt->execute([$propertyId]);
$rooms = $stmt->fetchAll();

// Calculate next steps
$hasRooms = count($rooms) > 0;
$hasPrices = false;
$minPrice = 0;

foreach ($rooms as $room) {
    if ($room['base_price'] > 0) {
        $hasPrices = true;
        if ($minPrice == 0 || $room['base_price'] < $minPrice) {
            $minPrice = $room['base_price'];
        }
    }
}

$completionScore = 0;
$completionSteps = [];

// Check completion
if ($property['main_image']) {
    $completionScore += 20;
    $completionSteps['photos'] = true;
} else {
    $completionSteps['photos'] = false;
}

if ($hasRooms) {
    $completionScore += 20;
    $completionSteps['rooms'] = true;
} else {
    $completionSteps['rooms'] = false;
}

if ($hasPrices) {
    $completionScore += 20;
    $completionSteps['prices'] = true;
} else {
    $completionSteps['prices'] = false;
}

if ($property['description']) {
    $completionScore += 15;
    $completionSteps['description'] = true;
} else {
    $completionSteps['description'] = false;
}

if ($property['amenities']) {
    $amenitiesList = json_decode($property['amenities'], true) ?: [];
    if (count($amenitiesList) >= 3) {
        $completionScore += 15;
        $completionSteps['amenities'] = true;
    } else {
        $completionSteps['amenities'] = false;
    }
} else {
    $completionSteps['amenities'] = false;
}

if ($property['phone'] && $property['email']) {
    $completionScore += 10;
    $completionSteps['contact'] = true;
} else {
    $completionSteps['contact'] = false;
}
?>

<style>
    /* ============================================ */
    /* SUCCESS PAGE - CELEBRATION DESIGN */
    /* ============================================ */

    .success-wrapper {
        max-width: 780px;
        margin: 0 auto;
    }

    /* Celebration Card */
    .celebration-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
        margin-bottom: 24px;
    }

    /* Hero Section with Confetti Effect */
    .celebration-hero {
        background: linear-gradient(135deg, #003b95 0%, #0055cc 30%, #0066ff 60%, #003b95 100%);
        padding: 48px 32px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .celebration-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background:
            radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
            radial-gradient(circle at 80% 70%, rgba(255, 255, 255, 0.08) 0%, transparent 50%),
            radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.05) 0%, transparent 70%);
        animation: heroShimmer 4s ease-in-out infinite;
    }

    @keyframes heroShimmer {

        0%,
        100% {
            transform: rotate(0deg);
        }

        50% {
            transform: rotate(180deg);
        }
    }

    /* Confetti Particles */
    .confetti-container {
        position: absolute;
        inset: 0;
        pointer-events: none;
        overflow: hidden;
    }

    .confetti-piece {
        position: absolute;
        width: 10px;
        height: 10px;
        border-radius: 2px;
        animation: confettiFall 3s ease-in-out infinite;
    }

    .confetti-piece:nth-child(1) {
        left: 10%;
        animation-delay: 0s;
        background: #febb02;
        width: 8px;
        height: 8px;
    }

    .confetti-piece:nth-child(2) {
        left: 20%;
        animation-delay: 0.5s;
        background: #008009;
        width: 6px;
        height: 12px;
    }

    .confetti-piece:nth-child(3) {
        left: 30%;
        animation-delay: 1s;
        background: #e21111;
        width: 10px;
        height: 6px;
    }

    .confetti-piece:nth-child(4) {
        left: 40%;
        animation-delay: 0.3s;
        background: #ff8c00;
        width: 8px;
        height: 8px;
    }

    .confetti-piece:nth-child(5) {
        left: 50%;
        animation-delay: 0.8s;
        background: #7c3aed;
        width: 6px;
        height: 10px;
    }

    .confetti-piece:nth-child(6) {
        left: 60%;
        animation-delay: 0.2s;
        background: #febb02;
        width: 12px;
        height: 6px;
    }

    .confetti-piece:nth-child(7) {
        left: 70%;
        animation-delay: 1.2s;
        background: #008009;
        width: 8px;
        height: 8px;
    }

    .confetti-piece:nth-child(8) {
        left: 80%;
        animation-delay: 0.6s;
        background: #e21111;
        width: 6px;
        height: 10px;
    }

    .confetti-piece:nth-child(9) {
        left: 90%;
        animation-delay: 0.4s;
        background: #ff8c00;
        width: 10px;
        height: 6px;
    }

    .confetti-piece:nth-child(10) {
        left: 15%;
        animation-delay: 1.5s;
        background: #7c3aed;
        width: 8px;
        height: 8px;
    }

    .confetti-piece:nth-child(11) {
        left: 35%;
        animation-delay: 0.7s;
        background: #febb02;
        width: 6px;
        height: 12px;
    }

    .confetti-piece:nth-child(12) {
        left: 55%;
        animation-delay: 0.9s;
        background: #008009;
        width: 10px;
        height: 6px;
    }

    .confetti-piece:nth-child(13) {
        left: 75%;
        animation-delay: 1.1s;
        background: #e21111;
        width: 8px;
        height: 8px;
    }

    .confetti-piece:nth-child(14) {
        left: 85%;
        animation-delay: 1.4s;
        background: #ff8c00;
        width: 6px;
        height: 10px;
    }

    .confetti-piece:nth-child(15) {
        left: 25%;
        animation-delay: 0.1s;
        background: #7c3aed;
        width: 10px;
        height: 8px;
    }

    @keyframes confettiFall {
        0% {
            transform: translateY(-100px) rotate(0deg);
            opacity: 1;
        }

        100% {
            transform: translateY(400px) rotate(720deg);
            opacity: 0;
        }
    }

    .celebration-hero-content {
        position: relative;
        z-index: 1;
    }

    .success-icon-circle {
        width: 88px;
        height: 88px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(8px);
        border: 3px solid rgba(255, 255, 255, 0.4);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        animation: iconPopIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .success-icon-circle i {
        font-size: 2.5rem;
        color: white;
    }

    @keyframes iconPopIn {
        0% {
            transform: scale(0);
            opacity: 0;
        }

        60% {
            transform: scale(1.2);
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    .celebration-hero h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: white;
        margin: 0 0 8px 0;
        animation: fadeInUp 0.6s ease 0.2s both;
    }

    .celebration-hero .hero-subtitle {
        font-size: 1rem;
        color: rgba(255, 255, 255, 0.9);
        margin: 0;
        animation: fadeInUp 0.6s ease 0.4s both;
    }

    .celebration-hero .property-name-badge {
        display: inline-block;
        margin-top: 16px;
        padding: 8px 20px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 100px;
        color: white;
        font-weight: 600;
        font-size: 1rem;
        animation: fadeInUp 0.6s ease 0.6s both;
    }

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

    /* Status Card */
    .status-card {
        padding: 28px 32px;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .status-indicator {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #fff4e6;
        color: #ff8c00;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
        animation: pulse-ring 2s infinite;
    }

    @keyframes pulse-ring {
        0% {
            box-shadow: 0 0 0 0 rgba(255, 140, 0, 0.3);
        }

        70% {
            box-shadow: 0 0 0 12px rgba(255, 140, 0, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(255, 140, 0, 0);
        }
    }

    .status-info h3 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0 0 4px 0;
        color: #1a1a1a;
    }

    .status-info p {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0;
        line-height: 1.5;
    }

    /* Property Summary */
    .property-summary {
        padding: 24px 32px;
        border-bottom: 1px solid #e7e7e7;
    }

    .property-summary h3 {
        font-size: 0.875rem;
        font-weight: 700;
        margin: 0 0 16px 0;
        color: #003b95;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }

    .summary-item {
        text-align: center;
        padding: 16px 12px;
        background: #f9fafb;
        border-radius: 12px;
        border: 1px solid #e7e7e7;
    }

    .summary-item .summary-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 8px;
        font-size: 1.125rem;
    }

    .summary-item .summary-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1a1a1a;
    }

    .summary-item .summary-label {
        font-size: 0.6875rem;
        color: #6b6b6b;
        margin-top: 2px;
    }

    /* Completion Score */
    .completion-section {
        padding: 24px 32px;
        border-bottom: 1px solid #e7e7e7;
    }

    .completion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .completion-header h3 {
        font-size: 0.875rem;
        font-weight: 700;
        margin: 0;
        color: #003b95;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .completion-score {
        font-size: 1.5rem;
        font-weight: 700;
        color: <?php echo $completionScore >= 70 ? '#008009' : ($completionScore >= 40 ? '#ff8c00' : '#e21111'); ?>;
    }

    .completion-bar {
        height: 8px;
        background: #f3f4f6;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 16px;
    }

    .completion-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, <?php echo $completionScore >= 70 ? '#008009, #00b341' : ($completionScore >= 40 ? '#ff8c00, #ffa940' : '#e21111, #ff4444'); ?>);
        border-radius: 4px;
        width: <?php echo $completionScore; ?>%;
        transition: width 1.5s ease;
    }

    .completion-checklist {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .checklist-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.8125rem;
        padding: 8px 12px;
        border-radius: 8px;
        background: <?php echo ($completionSteps[$key] ?? false) ? '#e6f4ea' : '#f9fafb'; ?>;
    }

    .checklist-item .check-icon {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        flex-shrink: 0;
    }

    .check-icon.done {
        background: #008009;
        color: white;
    }

    .check-icon.pending {
        background: #e5e7eb;
        color: #9ca3af;
    }

    /* Room Preview */
    .room-preview {
        padding: 24px 32px;
        border-bottom: 1px solid #e7e7e7;
    }

    .room-preview h3 {
        font-size: 0.875rem;
        font-weight: 700;
        margin: 0 0 16px 0;
        color: #003b95;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .room-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .room-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: #f9fafb;
        border-radius: 10px;
        border: 1px solid #e7e7e7;
    }

    .room-item-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .room-item-info i {
        font-size: 1.25rem;
        color: #003b95;
    }

    .room-item-name {
        font-weight: 600;
        font-size: 0.875rem;
    }

    .room-item-meta {
        font-size: 0.75rem;
        color: #6b6b6b;
    }

    .room-item-price {
        font-weight: 700;
        color: #008009;
        font-size: 0.9375rem;
    }

    .no-rooms-message {
        text-align: center;
        padding: 24px;
        color: #6b6b6b;
        font-size: 0.875rem;
    }

    .no-rooms-message i {
        font-size: 2rem;
        color: #d1d5db;
        display: block;
        margin-bottom: 12px;
    }

    /* Action Buttons */
    .actions-section {
        padding: 28px 32px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .btn-action {
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.875rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-primary-action {
        background: #003b95;
        color: white;
        border: none;
    }

    .btn-primary-action:hover {
        background: #002d73;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 59, 149, 0.3);
    }

    .btn-secondary-action {
        background: white;
        color: #1a1a1a;
        border: 1px solid #e7e7e7;
    }

    .btn-secondary-action:hover {
        background: #f9fafb;
        border-color: #003b95;
        color: #003b95;
    }

    .btn-outline-action {
        background: white;
        color: #003b95;
        border: 1px solid #003b95;
    }

    .btn-outline-action:hover {
        background: #f0f4ff;
    }

    /* What's Next Card */
    .next-steps-card {
        background: white;
        border: 1px solid #e7e7e7;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
    }

    .next-steps-header {
        padding: 20px 32px;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .next-steps-header i {
        font-size: 1.25rem;
        color: #003b95;
    }

    .next-steps-header h3 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .next-steps-body {
        padding: 0;
    }

    .next-step {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        padding: 20px 32px;
        border-bottom: 1px solid #f3f4f6;
        transition: background 0.2s;
    }

    .next-step:last-child {
        border-bottom: none;
    }

    .next-step:hover {
        background: #f8faff;
    }

    .next-step-number {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #f0f4ff;
        color: #003b95;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9375rem;
        flex-shrink: 0;
    }

    .next-step-content h4 {
        font-size: 0.9375rem;
        font-weight: 600;
        margin: 0 0 4px 0;
    }

    .next-step-content p {
        font-size: 0.8125rem;
        color: #6b6b6b;
        margin: 0 0 12px 0;
    }

    .next-step-link {
        font-size: 0.8125rem;
        color: #003b95;
        text-decoration: none;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .next-step-link:hover {
        text-decoration: underline;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .celebration-hero {
            padding: 36px 20px;
        }

        .celebration-hero h1 {
            font-size: 1.5rem;
        }

        .status-card {
            flex-direction: column;
            text-align: center;
            padding: 20px;
        }

        .summary-grid {
            grid-template-columns: 1fr;
        }

        .completion-checklist {
            grid-template-columns: 1fr;
        }

        .actions-section {
            flex-direction: column;
            padding: 20px;
        }

        .btn-action {
            justify-content: center;
        }

        .next-step {
            padding: 16px 20px;
        }
    }
</style>

<div class="success-wrapper">
    <!-- Celebration Card -->
    <div class="celebration-card">
        <!-- Hero with Confetti -->
        <div class="celebration-hero">
            <div class="confetti-container">
                <?php for ($i = 1; $i <= 15; $i++): ?>
                    <div class="confetti-piece"></div>
                <?php endfor; ?>
            </div>
            <div class="celebration-hero-content">
                <div class="success-icon-circle">
                    <i class="bi bi-check-lg"></i>
                </div>
                <h1>Property Submitted Successfully! 🎉</h1>
                <p class="hero-subtitle">Your property is now under review</p>
                <div class="property-name-badge">
                    <i class="bi bi-building me-2"></i>
                    <?php echo sanitize($property['stay_name']); ?>
                </div>
            </div>
        </div>

        <!-- Verification Status -->
        <div class="status-card">
            <div class="status-indicator">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="status-info">
                <h3>Pending Verification</h3>
                <p>Our team reviews all new properties to ensure quality standards. This typically takes <strong>24-48 hours</strong>. You'll be notified once your property is live and ready to receive bookings.</p>
            </div>
        </div>

        <!-- Property Summary -->
        <div class="property-summary">
            <h3>Property Overview</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-icon" style="background: #f0f4ff; color: #003b95;">
                        <i class="bi bi-geo-alt"></i>
                    </div>
                    <div class="summary-value"><?php echo sanitize($property['city'] ?? $property['location_name'] ?? 'Rwanda'); ?></div>
                    <div class="summary-label">Location</div>
                </div>
                <div class="summary-item">
                    <div class="summary-icon" style="background: #e6f4ea; color: #008009;">
                        <i class="bi bi-door-open"></i>
                    </div>
                    <div class="summary-value"><?php echo $property['active_rooms']; ?> / <?php echo $property['total_rooms']; ?></div>
                    <div class="summary-label">Rooms</div>
                </div>
                <div class="summary-item">
                    <div class="summary-icon" style="background: #fff4e6; color: #febb02;">
                        <?php if ($property['star_rating'] > 0): ?>
                            <i class="bi bi-star-fill"></i>
                        <?php else: ?>
                            <i class="bi bi-star"></i>
                        <?php endif; ?>
                    </div>
                    <div class="summary-value"><?php echo $property['star_rating'] ? $property['star_rating'] . ' ★' : 'Not Rated'; ?></div>
                    <div class="summary-label">Rating</div>
                </div>
            </div>
        </div>

        <!-- Completion Score -->
        <div class="completion-section">
            <div class="completion-header">
                <h3>Listing Completeness</h3>
                <span class="completion-score"><?php echo $completionScore; ?>%</span>
            </div>
            <div class="completion-bar">
                <div class="completion-bar-fill" style="width: <?php echo $completionScore; ?>%;"></div>
            </div>
            <div class="completion-checklist">
                <?php
                $checklist = [
                    'photos' => ['icon' => 'bi-camera', 'label' => 'Photos uploaded'],
                    'rooms' => ['icon' => 'bi-door-open', 'label' => 'Rooms added'],
                    'prices' => ['icon' => 'bi-cash-stack', 'label' => 'Prices set'],
                    'description' => ['icon' => 'bi-file-text', 'label' => 'Description written'],
                    'amenities' => ['icon' => 'bi-grid-3x3-gap', 'label' => 'Amenities selected'],
                    'contact' => ['icon' => 'bi-telephone', 'label' => 'Contact info added'],
                ];
                foreach ($checklist as $key => $item):
                    $isDone = $completionSteps[$key] ?? false;
                ?>
                    <div class="checklist-item" style="background: <?php echo $isDone ? '#e6f4ea' : '#f9fafb'; ?>;">
                        <span class="check-icon <?php echo $isDone ? 'done' : 'pending'; ?>">
                            <i class="bi bi-<?php echo $isDone ? 'check-lg' : 'dash-lg'; ?>"></i>
                        </span>
                        <span style="<?php echo !$isDone ? 'color: #9ca3af;' : ''; ?>">
                            <i class="bi <?php echo $item['icon']; ?> me-1"></i>
                            <?php echo $item['label']; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Room Preview -->
        <div class="room-preview">
            <h3>Room Types (<?php echo count($rooms); ?>)</h3>
            <?php if (!empty($rooms)): ?>
                <div class="room-list">
                    <?php foreach (array_slice($rooms, 0, 3) as $room): ?>
                        <div class="room-item">
                            <div class="room-item-info">
                                <i class="bi bi-door-open"></i>
                                <div>
                                    <div class="room-item-name"><?php echo sanitize($room['room_name']); ?></div>
                                    <div class="room-item-meta">
                                        <?php echo $room['max_guests']; ?> guests · <?php echo $room['num_rooms_available']; ?> available
                                    </div>
                                </div>
                            </div>
                            <div class="room-item-price">
                                <?php echo $room['base_price'] > 0 ? formatPrice($room['base_price']) : 'Price not set'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($rooms) > 3): ?>
                        <div style="text-align: center; padding: 12px; color: #003b95; font-weight: 600; font-size: 0.8125rem;">
                            +<?php echo count($rooms) - 3; ?> more room types
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-rooms-message">
                    <i class="bi bi-door-open"></i>
                    <p>No rooms added yet. Add rooms to start accepting bookings.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="actions-section">
            <a href="rooms.php?property=<?php echo $propertyId; ?>" class="btn-primary-action">
                <i class="bi bi-door-open"></i> Manage Rooms
            </a>
            <a href="property-add.php?id=<?php echo $propertyId; ?>" class="btn-secondary-action">
                <i class="bi bi-pencil"></i> Edit Property
            </a>
            <a href="photos.php?property=<?php echo $propertyId; ?>" class="btn-secondary-action">
                <i class="bi bi-images"></i> Add Photos
            </a>
            <a href="dashboard.php" class="btn-outline-action">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Next Steps Card -->
    <div class="next-steps-card">
        <div class="next-steps-header">
            <i class="bi bi-list-check"></i>
            <h3>What's Next?</h3>
        </div>
        <div class="next-steps-body">
            <?php if (!$completionSteps['rooms']): ?>
                <div class="next-step">
                    <div class="next-step-number">1</div>
                    <div class="next-step-content">
                        <h4>Add Room Types</h4>
                        <p>Define your room types with names, descriptions, capacities, and pricing to start receiving bookings.</p>
                        <a href="rooms.php?property=<?php echo $propertyId; ?>" class="next-step-link">
                            Go to Room Management <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$completionSteps['photos']): ?>
                <div class="next-step">
                    <div class="next-step-number"><?php echo !$completionSteps['rooms'] ? '2' : '1'; ?></div>
                    <div class="next-step-content">
                        <h4>Upload More Photos</h4>
                        <p>Properties with high-quality photos get 3x more bookings. Add photos of rooms, facilities, and views.</p>
                        <a href="photos.php?property=<?php echo $propertyId; ?>" class="next-step-link">
                            Upload Photos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$completionSteps['prices'] && $completionSteps['rooms']): ?>
                <div class="next-step">
                    <div class="next-step-number">3</div>
                    <div class="next-step-content">
                        <h4>Set Competitive Pricing</h4>
                        <p>Rooms without prices won't appear in search results. Set your nightly rates to go live.</p>
                        <a href="rates.php?property=<?php echo $propertyId; ?>" class="next-step-link">
                            Set Pricing <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="next-step">
                <div class="next-step-number">
                    <i class="bi bi-clock"></i>
                </div>
                <div class="next-step-content">
                    <h4>Wait for Verification</h4>
                    <p>Our team reviews all properties within 24-48 hours. You'll receive an email notification once approved.</p>
                    <a href="properties.php" class="next-step-link">
                        View Property Status <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Animate completion bar on load
    document.addEventListener('DOMContentLoaded', function() {
        const bar = document.querySelector('.completion-bar-fill');
        if (bar) {
            const targetWidth = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = targetWidth;
            }, 500);
        }
    });
</script>

<?php require_once 'includes/stays_footer.php'; ?>