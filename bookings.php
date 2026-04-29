<?php
require_once 'includes/functions.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];

// Get user's bookings with proper data for each type
$stmt = $db->prepare("
    SELECT 
        b.*,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.stay_name
            WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
            WHEN b.booking_type = 'attraction' THEN a.attraction_name
            ELSE 'Unknown'
        END as item_name,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.main_image
            WHEN b.booking_type = 'car_rental' THEN (SELECT images FROM car_fleet WHERE car_id = b.car_id LIMIT 1)
            WHEN b.booking_type = 'attraction' THEN a.main_image
            ELSE NULL
        END as item_image,
        CASE 
            WHEN b.booking_type = 'stay' THEN sr.room_name
            WHEN b.booking_type = 'car_rental' THEN cr.company_name
            WHEN b.booking_type = 'attraction' THEN t.tier_name
            ELSE NULL
        END as item_detail,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.address
            WHEN b.booking_type = 'car_rental' THEN cr.address
            WHEN b.booking_type = 'attraction' THEN a.address
            ELSE NULL
        END as item_location,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.phone
            WHEN b.booking_type = 'car_rental' THEN cr.phone
            WHEN b.booking_type = 'attraction' THEN NULL
            ELSE NULL
        END as item_phone,
        CASE 
            WHEN b.booking_type = 'stay' THEN s.star_rating
            ELSE NULL
        END as item_rating
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
    LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

// Process images for car rentals (JSON array)
foreach ($bookings as &$booking) {
    if ($booking['booking_type'] == 'car_rental' && $booking['item_image']) {
        $images = json_decode($booking['item_image'], true);
        $booking['item_image'] = is_array($images) ? ($images[0] ?? null) : null;
    }
}

// Group bookings by status
$upcoming = array_filter($bookings, function ($b) {
    $date = $b['check_in_date'] ?? $b['pickup_date'] ?? $b['experience_date'];
    return $b['status'] !== 'cancelled' && $b['status'] !== 'completed' && strtotime($date) >= strtotime('today');
});
$past = array_filter($bookings, function ($b) {
    $date = $b['check_in_date'] ?? $b['pickup_date'] ?? $b['experience_date'];
    return $b['status'] === 'completed' || $b['status'] === 'cancelled' || strtotime($date) < strtotime('today');
});

$pageTitle = 'My Bookings';
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
    /* Bookings Page Styles */
    .bookings-page {
        background: #f5f7fa;
        min-height: calc(100vh - 64px);
        padding: 40px 0;
    }

    .page-header {
        margin-bottom: 32px;
    }

    .page-title {
        font-size: 28px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 8px;
    }

    .page-subtitle {
        color: #6b6b6b;
        font-size: 14px;
    }

    /* Tabs */
    .booking-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 28px;
        border-bottom: 2px solid #e7e7e7;
    }

    .tab-btn {
        padding: 12px 24px;
        background: none;
        border: none;
        font-size: 15px;
        font-weight: 600;
        color: #6b6b6b;
        cursor: pointer;
        position: relative;
        transition: all 0.2s;
    }

    .tab-btn.active {
        color: #0066ff;
    }

    .tab-btn.active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        right: 0;
        height: 2px;
        background: #0066ff;
    }

    .tab-badge {
        background: #e7e7e7;
        color: #6b6b6b;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 12px;
        margin-left: 8px;
    }

    .tab-btn.active .tab-badge {
        background: #0066ff;
        color: white;
    }

    /* Booking Cards */
    .booking-list {
        display: grid;
        gap: 20px;
    }

    .booking-card {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: box-shadow 0.2s;
    }

    .booking-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
    }

    .booking-header {
        display: flex;
        gap: 24px;
        padding: 24px;
        flex-wrap: wrap;
    }

    @media (min-width: 768px) {
        .booking-header {
            flex-wrap: nowrap;
        }
    }

    .booking-image {
        width: 160px;
        height: 120px;
        border-radius: 12px;
        overflow: hidden;
        background: #f5f5f5;
        flex-shrink: 0;
    }

    .booking-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .booking-info {
        flex: 1;
        min-width: 200px;
    }

    .booking-type {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 12px;
        background: #f0f4ff;
        color: #0066ff;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        margin-bottom: 12px;
    }

    .booking-title {
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 6px;
        color: #1a1a1a;
    }

    .booking-location {
        font-size: 13px;
        color: #6b6b6b;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .booking-dates {
        display: flex;
        gap: 20px;
        font-size: 13px;
        color: #6b6b6b;
        flex-wrap: wrap;
    }

    .booking-dates i {
        color: #0066ff;
        width: 16px;
    }

    .booking-ref {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 12px;
    }

    .booking-status {
        text-align: right;
        flex-shrink: 0;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
    }

    .status-confirmed {
        background: #d1fae5;
        color: #059669;
    }

    .status-pending {
        background: #fef3c7;
        color: #d97706;
    }

    .status-completed {
        background: #e5e7eb;
        color: #6b7280;
    }

    .status-cancelled {
        background: #fee2e2;
        color: #dc2626;
    }

    .price-amount {
        font-size: 20px;
        font-weight: 700;
        color: #1a1a1a;
        margin-top: 12px;
    }

    .price-label {
        font-size: 11px;
        color: #9ca3af;
        text-transform: uppercase;
    }

    /* Rating Stars */
    .rating-stars {
        display: inline-flex;
        gap: 2px;
        margin-left: 8px;
    }

    .rating-stars i {
        font-size: 11px;
        color: #febb02;
    }

    /* Booking Actions */
    .booking-actions {
        display: flex;
        gap: 12px;
        padding: 16px 24px;
        background: #f8f9fa;
        border-top: 1px solid #e7e7e7;
        flex-wrap: wrap;
    }

    .btn-action {
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
    }

    .btn-primary {
        background: #0066ff;
        color: white;
    }

    .btn-primary:hover {
        background: #0052cc;
    }

    .btn-secondary {
        background: white;
        color: #1a1a1a;
        border: 1px solid #e7e7e7;
    }

    .btn-secondary:hover {
        border-color: #0066ff;
        color: #0066ff;
    }

    .btn-danger {
        background: #fee2e2;
        color: #dc2626;
    }

    .btn-danger:hover {
        background: #fecaca;
    }

    /* Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-content {
        background: white;
        border-radius: 20px;
        width: 100%;
        max-width: 700px;
        max-height: 90vh;
        overflow-y: auto;
        animation: modalSlideUp 0.3s ease;
    }

    @keyframes modalSlideUp {
        from {
            opacity: 0;
            transform: translateY(40px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        padding: 24px;
        border-bottom: 1px solid #e7e7e7;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: white;
        z-index: 1;
    }

    .modal-title {
        font-size: 20px;
        font-weight: 700;
    }

    .modal-close {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: #f3f4f6;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .modal-close:hover {
        background: #e5e7eb;
    }

    .modal-body {
        padding: 24px;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }

    .detail-item {
        padding: 12px;
        background: #f8f9fa;
        border-radius: 12px;
    }

    .detail-label {
        font-size: 11px;
        text-transform: uppercase;
        color: #9ca3af;
        margin-bottom: 4px;
    }

    .detail-value {
        font-weight: 600;
        font-size: 14px;
        color: #1a1a1a;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        background: white;
        border-radius: 16px;
    }

    .empty-icon {
        width: 80px;
        height: 80px;
        background: #f3f4f6;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
        font-size: 32px;
        color: #9ca3af;
    }

    .empty-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .empty-text {
        color: #6b6b6b;
        margin-bottom: 24px;
    }

    /* Responsive */
    @media (max-width: 640px) {
        .booking-header {
            flex-direction: column;
        }

        .booking-image {
            width: 100%;
            height: 160px;
        }

        .booking-status {
            text-align: left;
            margin-top: 12px;
        }

        .booking-actions {
            flex-direction: column;
        }

        .btn-action {
            justify-content: center;
            width: 100%;
        }

        .detail-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="bookings-page">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">My Bookings</h1>
            <p class="page-subtitle">View and manage all your reservations</p>
        </div>

        <!-- Tabs -->
        <div class="booking-tabs">
            <button class="tab-btn active" onclick="switchTab('upcoming')">
                Upcoming <span class="tab-badge"><?php echo count($upcoming); ?></span>
            </button>
            <button class="tab-btn" onclick="switchTab('past')">
                History <span class="tab-badge"><?php echo count($past); ?></span>
            </button>
        </div>

        <!-- Upcoming Bookings -->
        <div id="upcoming-tab" class="booking-list">
            <?php if (empty($upcoming)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <h3 class="empty-title">No upcoming trips</h3>
                    <p class="empty-text">Ready for an adventure? Book your next stay or experience in Rwanda!</p>
                    <a href="/gorwanda-plus/search.php?type=stays" class="btn-action btn-primary">Explore Now</a>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming as $booking):
                    $typeIcons = [
                        'stay' => '🏨',
                        'car_rental' => '🚗',
                        'attraction' => '🎟️'
                    ];
                    $typeLabels = [
                        'stay' => 'Accommodation',
                        'car_rental' => 'Car Rental',
                        'attraction' => 'Experience'
                    ];
                    $statusColors = [
                        'confirmed' => 'status-confirmed',
                        'pending' => 'status-pending',
                        'completed' => 'status-completed',
                        'cancelled' => 'status-cancelled'
                    ];
                    $date = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
                    $endDate = $booking['check_out_date'] ?? $booking['return_date'] ?? null;
                ?>
                    <div class="booking-card" data-booking='<?php echo htmlspecialchars(json_encode($booking)); ?>'>
                        <div class="booking-header">
                            <div class="booking-image">
                                <img src="<?php echo getImageUrl($booking['item_image'], $booking['booking_type']); ?>"
                                    alt="<?php echo sanitize($booking['item_name']); ?>"
                                    onerror="this.src='https://placehold.co/400x300?text=No+Image'">
                            </div>

                            <div class="booking-info">
                                <div class="booking-type">
                                    <?php echo $typeIcons[$booking['booking_type']] ?? '📋'; ?>
                                    <?php echo $typeLabels[$booking['booking_type']] ?? 'Booking'; ?>
                                </div>
                                <h3 class="booking-title"><?php echo sanitize($booking['item_name']); ?></h3>
                                <?php if ($booking['item_location']): ?>
                                    <div class="booking-location">
                                        <i class="bi bi-geo-alt"></i> <?php echo sanitize($booking['item_location']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="booking-dates">
                                    <span><i class="bi bi-calendar3"></i> <?php echo date('D, M d, Y', strtotime($date)); ?></span>
                                    <?php if ($endDate && $endDate !== $date): ?>
                                        <span><i class="bi bi-arrow-right"></i> <?php echo date('D, M d, Y', strtotime($endDate)); ?></span>
                                    <?php endif; ?>
                                    <span><i class="bi bi-people"></i> <?php echo $booking['num_guests']; ?> guest<?php echo $booking['num_guests'] > 1 ? 's' : ''; ?></span>
                                </div>
                                <?php if ($booking['item_detail']): ?>
                                    <div class="booking-dates" style="margin-top: 8px;">
                                        <span><i class="bi bi-tag"></i> <?php echo sanitize($booking['item_detail']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="booking-ref">
                                    Ref: <?php echo $booking['booking_reference']; ?> • Booked on <?php echo date('M d, Y', strtotime($booking['created_at'])); ?>
                                </div>
                            </div>

                            <div class="booking-status">
                                <span class="status-badge <?php echo $statusColors[$booking['status']] ?? 'status-pending'; ?>">
                                    <i class="bi bi-<?php echo $booking['status'] === 'confirmed' ? 'check-circle-fill' : ($booking['status'] === 'pending' ? 'clock-fill' : 'x-circle-fill'); ?>"></i>
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                                <div class="price-amount">
                                    <?php echo formatPrice($booking['total_amount']); ?>
                                </div>
                                <div class="price-label">Total paid</div>
                            </div>
                        </div>

                        <div class="booking-actions">
                            <button class="btn-action btn-primary" onclick="viewDetails('<?php echo $booking['booking_reference']; ?>')">
                                <i class="bi bi-eye"></i> View Details
                            </button>
                            <button class="btn-action btn-secondary" onclick="downloadInvoice('<?php echo $booking['booking_reference']; ?>')">
                                <i class="bi bi-download"></i> Invoice
                            </button>
                            <?php if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'completed'): ?>
                                <button class="btn-action btn-danger" onclick="cancelBooking('<?php echo $booking['booking_reference']; ?>')">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Past Bookings -->
        <div id="past-tab" class="booking-list" style="display: none;">
            <?php if (empty($past)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <h3 class="empty-title">No past bookings</h3>
                    <p class="empty-text">Your completed trips will appear here</p>
                </div>
            <?php else: ?>
                <?php foreach ($past as $booking):
                    $typeIcons = [
                        'stay' => '🏨',
                        'car_rental' => '🚗',
                        'attraction' => '🎟️'
                    ];
                    $typeLabels = [
                        'stay' => 'Accommodation',
                        'car_rental' => 'Car Rental',
                        'attraction' => 'Experience'
                    ];
                    $date = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
                ?>
                    <div class="booking-card" style="opacity: 0.85;">
                        <div class="booking-header">
                            <div class="booking-image">
                                <img src="<?php echo getImageUrl($booking['item_image'], $booking['booking_type']); ?>"
                                    alt="<?php echo sanitize($booking['item_name']); ?>"
                                    onerror="this.src='https://placehold.co/400x300?text=No+Image'">
                            </div>

                            <div class="booking-info">
                                <div class="booking-type">
                                    <?php echo $typeIcons[$booking['booking_type']] ?? '📋'; ?>
                                    <?php echo $typeLabels[$booking['booking_type']] ?? 'Booking'; ?>
                                </div>
                                <h3 class="booking-title"><?php echo sanitize($booking['item_name']); ?></h3>
                                <div class="booking-dates">
                                    <span><i class="bi bi-calendar3"></i> <?php echo date('D, M d, Y', strtotime($date)); ?></span>
                                    <span><i class="bi bi-people"></i> <?php echo $booking['num_guests']; ?> guest<?php echo $booking['num_guests'] > 1 ? 's' : ''; ?></span>
                                </div>
                                <div class="booking-ref">
                                    Ref: <?php echo $booking['booking_reference']; ?>
                                </div>
                            </div>

                            <div class="booking-status">
                                <span class="status-badge <?php echo $booking['status'] === 'completed' ? 'status-completed' : 'status-cancelled'; ?>">
                                    <?php echo $booking['status'] === 'completed' ? '✓ Completed' : '✗ Cancelled'; ?>
                                </span>
                                <div class="price-amount">
                                    <?php echo formatPrice($booking['total_amount']); ?>
                                </div>
                            </div>
                        </div>

                        <div class="booking-actions">
                            <button class="btn-action btn-secondary" onclick="viewDetails('<?php echo $booking['booking_reference']; ?>')">
                                <i class="bi bi-eye"></i> View Details
                            </button>
                            <button class="btn-action btn-secondary" onclick="downloadInvoice('<?php echo $booking['booking_reference']; ?>')">
                                <i class="bi bi-download"></i> Invoice
                            </button>
                            <?php if ($booking['status'] === 'completed'): ?>
                                <button class="btn-action btn-secondary" onclick="writeReview('<?php echo $booking['booking_reference']; ?>')">
                                    <i class="bi bi-star"></i> Write Review
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Booking Details Modal -->
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Booking Details</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Dynamic content -->
        </div>
    </div>
</div>

<script>
    const bookingsData = <?php echo json_encode($bookings); ?>;

    function switchTab(tab) {
        const upcomingTab = document.getElementById('upcoming-tab');
        const pastTab = document.getElementById('past-tab');
        const buttons = document.querySelectorAll('.tab-btn');

        if (tab === 'upcoming') {
            upcomingTab.style.display = 'grid';
            pastTab.style.display = 'none';
            buttons[0].classList.add('active');
            buttons[1].classList.remove('active');
        } else {
            upcomingTab.style.display = 'none';
            pastTab.style.display = 'grid';
            buttons[1].classList.add('active');
            buttons[0].classList.remove('active');
        }
    }

    function viewDetails(bookingRef) {
        const booking = bookingsData.find(b => b.booking_reference === bookingRef);
        if (!booking) return;

        const typeLabels = {
            'stay': 'Accommodation',
            'car_rental': 'Car Rental',
            'attraction': 'Experience'
        };

        const dateLabel = booking.booking_type === 'stay' ? 'Check-in' : (booking.booking_type === 'car_rental' ? 'Pickup' : 'Date');
        const endDateLabel = booking.booking_type === 'stay' ? 'Check-out' : (booking.booking_type === 'car_rental' ? 'Return' : null);

        let detailsHtml = `
        <div style="background: #f0f4ff; border-radius: 16px; padding: 20px; margin-bottom: 24px;">
            <div style="font-size: 18px; font-weight: 700; margin-bottom: 4px;">${booking.item_name}</div>
            <div style="color: #0066ff; font-size: 14px;">${typeLabels[booking.booking_type] || 'Booking'}</div>
            ${booking.item_detail ? `<div style="color: #6b6b6b; font-size: 13px; margin-top: 8px;">${booking.item_detail}</div>` : ''}
        </div>
        
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Booking Reference</div>
                <div class="detail-value" style="font-family: monospace;">${booking.booking_reference}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Status</div>
                <div class="detail-value" style="color: ${booking.status === 'confirmed' ? '#059669' : (booking.status === 'pending' ? '#d97706' : '#6b7280')}">
                    ${booking.status.charAt(0).toUpperCase() + booking.status.slice(1)}
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">${dateLabel}</div>
                <div class="detail-value">${formatDate(booking.check_in_date || booking.pickup_date || booking.experience_date)}</div>
            </div>
            ${endDateLabel && (booking.check_out_date || booking.return_date) ? `
            <div class="detail-item">
                <div class="detail-label">${endDateLabel}</div>
                <div class="detail-value">${formatDate(booking.check_out_date || booking.return_date)}</div>
            </div>
            ` : ''}
            <div class="detail-item">
                <div class="detail-label">Guests / Participants</div>
                <div class="detail-value">${booking.num_guests} person${booking.num_guests > 1 ? 's' : ''}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Guest Name</div>
                <div class="detail-value">${booking.guest_first_name || booking.first_name || 'N/A'} ${booking.guest_last_name || booking.last_name || ''}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Contact Email</div>
                <div class="detail-value">${booking.guest_email || booking.email || 'N/A'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Contact Phone</div>
                <div class="detail-value">${booking.guest_phone || booking.phone || 'N/A'}</div>
            </div>
            ${booking.pickup_location ? `
            <div class="detail-item">
                <div class="detail-label">Pickup Location</div>
                <div class="detail-value">${booking.pickup_location}</div>
            </div>
            ` : ''}
            <div class="detail-item">
                <div class="detail-label">Payment Method</div>
                <div class="detail-value">${booking.payment_method ? booking.payment_method.toUpperCase() : 'Pending'}</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Payment Status</div>
                <div class="detail-value" style="color: ${booking.payment_status === 'paid' ? '#059669' : '#d97706'}">
                    ${booking.payment_status === 'paid' ? '✓ Paid' : '⏳ Pending'}
                </div>
            </div>
            ${booking.special_requests ? `
            <div class="detail-item" style="grid-column: span 2;">
                <div class="detail-label">Special Requests</div>
                <div class="detail-value">${booking.special_requests}</div>
            </div>
            ` : ''}
        </div>
        
        <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e7e7e7;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                <div>
                    <div class="detail-label">Total Amount</div>
                    <div style="font-size: 24px; font-weight: 700; color: #0066ff;">${formatCurrency(booking.total_amount)}</div>
                </div>
                <div class="detail-label">Includes 18% VAT</div>
            </div>
        </div>
        
        <div style="display: flex; gap: 12px; margin-top: 24px;">
            <button class="btn-action btn-primary" style="flex: 1;" onclick="downloadInvoice('${booking.booking_reference}')">
                <i class="bi bi-download"></i> Download Invoice
            </button>
            <button class="btn-action btn-secondary" style="flex: 1;" onclick="closeModal()">
                Close
            </button>
        </div>
    `;

        document.getElementById('modalBody').innerHTML = detailsHtml;
        document.getElementById('detailsModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('detailsModal').classList.remove('active');
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        return new Date(dateStr).toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function formatCurrency(amount) {
        return 'FRw ' + parseInt(amount).toLocaleString();
    }

    function downloadInvoice(bookingRef) {
        const booking = bookingsData.find(b => b.booking_reference === bookingRef);
        if (!booking) return;

        // Open PDF invoice
        window.open(`/gorwanda-plus/download-invoice.php?booking_id=${booking.booking_id}`, '_blank');
    }

    function generateInvoiceHTML(booking) {
        const typeLabels = {
            'stay': 'Accommodation',
            'car_rental': 'Car Rental',
            'attraction': 'Experience'
        };

        const date = booking.check_in_date || booking.pickup_date || booking.experience_date;
        const endDate = booking.check_out_date || booking.return_date;

        return `
<!DOCTYPE html>
<html>
<head>
    <title>Invoice ${booking.booking_reference}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
        .invoice { max-width: 800px; margin: 0 auto; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: #003580; color: white; padding: 30px; text-align: center; }
        .logo { font-size: 24px; font-weight: 700; margin-bottom: 5px; }
        .title { font-size: 28px; font-weight: 700; margin: 20px 0 10px; }
        .content { padding: 30px; }
        .meta { display: flex; justify-content: space-between; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #e7e7e7; }
        .meta-box h4 { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 8px; }
        .meta-box p { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { text-align: left; padding: 12px; background: #f5f5f5; font-size: 12px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #e7e7e7; }
        .text-right { text-align: right; }
        .total { text-align: right; margin-top: 20px; padding-top: 20px; border-top: 2px solid #e7e7e7; }
        .grand-total { font-size: 20px; font-weight: 700; color: #003580; }
        .footer { text-align: center; padding: 20px; background: #f5f5f5; font-size: 12px; color: #666; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="header">
            <div class="logo">GoRwanda+</div>
            <div class="title">INVOICE</div>
            <div>Booking Reference: ${booking.booking_reference}</div>
            <div>Date: ${new Date().toLocaleDateString()}</div>
        </div>
        
        <div class="content">
            <div class="meta">
                <div class="meta-box">
                    <h4>Billed To</h4>
                    <p><strong>${booking.guest_first_name || booking.first_name} ${booking.guest_last_name || booking.last_name}</strong></p>
                    <p>${booking.guest_email || booking.email}</p>
                    <p>${booking.guest_phone || booking.phone || ''}</p>
                </div>
                <div class="meta-box">
                    <h4>From</h4>
                    <p><strong>GoRwanda+ Ltd</strong></p>
                    <p>KG 7 Ave, Kigali, Rwanda</p>
                    <p>support@gorwanda.rw</p>
                    <p>+250 788 123 456</p>
                </div>
            </div>
            
            <h3 style="margin-bottom: 15px;">Booking Details</h3>
            <div style="background: #f5f5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <p><strong>${booking.item_name}</strong><br>
                ${typeLabels[booking.booking_type] || 'Booking'}${booking.item_detail ? ' • ' + booking.item_detail : ''}</p>
            </div>
            
            <table>
                <thead>
                    <tr><th>Item</th><th>Date</th><th class="text-right">Guests</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>${booking.booking_type === 'stay' ? 'Accommodation' : (booking.booking_type === 'car_rental' ? 'Car Rental' : 'Experience')}</td>
                        <td>${formatDate(date)}${endDate ? ' to ' + formatDate(endDate) : ''}</td>
                        <td class="text-right">${booking.num_guests}</td>
                        <td class="text-right">${formatCurrency(booking.total_amount - (booking.tax_amount || 0))}</td>
                    </tr>
                </tbody>
             </table>
            
            <div class="total">
                <div style="margin-bottom: 10px;">VAT (18%): ${formatCurrency(booking.tax_amount || 0)}</div>
                <div class="grand-total">Total: ${formatCurrency(booking.total_amount)}</div>
            </div>
            
            <div style="margin-top: 30px; padding: 15px; background: #e6f4ea; border-radius: 8px; text-align: center;">
                <p style="margin: 0; color: #008009; font-weight: 600;">✓ Payment Confirmed</p>
                <p style="margin: 5px 0 0; font-size: 12px;">Thank you for booking with GoRwanda+</p>
            </div>
        </div>
        
        <div class="footer">
            <p>This is a computer-generated invoice. No signature is required.</p>
            <p>For any questions, contact our support team at support@gorwanda.rw</p>
        </div>
    </div>
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 24px; background: #003580; color: white; border: none; border-radius: 8px; cursor: pointer;">Print Invoice</button>
    </div>
</body>
</html>
    `;
    }

    function cancelBooking(bookingRef) {
        if (confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
            // Here you would make an AJAX call to cancel the booking
            alert('Cancellation requested for ' + bookingRef);
        }
    }

function writeReview(bookingRef) {
    const booking = bookingsData.find(b => b.booking_reference === bookingRef);
    if (booking && booking.booking_id) {
        window.location.href = `/gorwanda-plus/review-form.php?booking_id=${booking.booking_id}`;
    } else {
        alert('Unable to load review form. Please try again.');
    }
}

    // Close modal on outside click
    document.getElementById('detailsModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Escape key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });
</script>

<?php require_once 'includes/footer.php'; ?>