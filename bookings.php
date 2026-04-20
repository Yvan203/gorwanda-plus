<?php
require_once 'includes/functions.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];

// Get user's bookings
$stmt = $db->prepare("
    SELECT b.*, 
           CASE 
               WHEN b.booking_type = 'stay' THEN s.stay_name
               WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
               ELSE a.attraction_name
           END as item_name,
           CASE 
               WHEN b.booking_type = 'stay' THEN s.main_image
               WHEN b.booking_type = 'car_rental' THEN NULL
               ELSE a.main_image
           END as item_image,
           sr.room_name
    FROM bookings b
    LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
    LEFT JOIN stays s ON sr.stay_id = s.stay_id
    LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
    LEFT JOIN attractions a ON b.attraction_tier_id IN (
        SELECT tier_id FROM attraction_tiers WHERE attraction_id = a.attraction_id
    )
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

$pageTitle = 'My Bookings';
$hideSearch = true;
require_once 'includes/header.php';

// Group bookings by status
$upcoming = array_filter($bookings, function($b) {
    return $b['status'] !== 'cancelled' && $b['status'] !== 'completed' && strtotime($b['check_in_date'] ?? $b['pickup_date'] ?? $b['experience_date']) >= strtotime('today');
});
$past = array_filter($bookings, function($b) {
    return $b['status'] === 'completed' || $b['status'] === 'cancelled' || strtotime($b['check_in_date'] ?? $b['pickup_date'] ?? $b['experience_date']) < strtotime('today');
});
?>

<style>
.bookings-page {
    background: var(--bg-gray);
    min-height: calc(100vh - 64px);
    padding: 32px 0;
}

.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.page-subtitle {
    color: var(--text-secondary);
    font-size: 1rem;
}

/* Tabs */
.booking-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 2px solid #e7e7e7;
    padding-bottom: 0;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--text-secondary);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.tab-btn.active {
    color: var(--primary-blue);
}

.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--primary-blue);
}

.tab-badge {
    background: var(--bg-gray);
    color: var(--text-secondary);
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.75rem;
    margin-left: 8px;
}

.tab-btn.active .tab-badge {
    background: var(--primary-blue);
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
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s;
}

.booking-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.booking-header {
    display: grid;
    grid-template-columns: 200px 1fr auto;
    gap: 24px;
    padding: 24px;
}

.booking-image {
    height: 140px;
    border-radius: 12px;
    overflow: hidden;
    background: #f5f5f5;
}

.booking-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.booking-info {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.booking-type {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--primary-blue);
    margin-bottom: 8px;
}

.booking-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.booking-dates {
    display: flex;
    gap: 24px;
    font-size: 0.9375rem;
    color: var(--text-secondary);
    margin-top: 12px;
}

.booking-dates i {
    color: var(--primary-blue);
    margin-right: 6px;
}

.booking-status {
    text-align: right;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 100px;
    font-size: 0.875rem;
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

.booking-ref {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-top: 12px;
}

.booking-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #e7e7e7;
}

.btn-action {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary-action {
    background: var(--primary-blue);
    color: white;
    border: none;
}

.btn-primary-action:hover {
    background: #0052cc;
}

.btn-secondary-action {
    background: white;
    color: var(--text-primary);
    border: 1px solid #e7e7e7;
}

.btn-secondary-action:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
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
    font-size: 2rem;
    color: #9ca3af;
}

.empty-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-text {
    color: var(--text-secondary);
    margin-bottom: 24px;
}

/* Price Summary */
.price-summary {
    text-align: right;
}

.price-total {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.price-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
}

/* Responsive */
@media (max-width: 768px) {
    .booking-header {
        grid-template-columns: 1fr;
    }
    
    .booking-image {
        height: 200px;
    }
    
    .booking-status {
        text-align: left;
        margin-top: 16px;
    }
    
    .booking-actions {
        flex-direction: column;
    }
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
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
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
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
}

.modal-title {
    font-size: 1.25rem;
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

.detail-row-modal {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
}

.detail-row-modal:last-child {
    border-bottom: none;
}

.detail-label-modal {
    color: var(--text-secondary);
    font-size: 0.9375rem;
}

.detail-value-modal {
    font-weight: 600;
    text-align: right;
}

.status-timeline {
    display: flex;
    justify-content: space-between;
    margin: 24px 0;
    position: relative;
}

.status-timeline::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 10%;
    right: 10%;
    height: 2px;
    background: #e7e7e7;
    z-index: 0;
}

.timeline-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    z-index: 1;
}

.timeline-dot {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    border: 2px solid #e7e7e7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.timeline-step.active .timeline-dot {
    background: var(--success);
    border-color: var(--success);
    color: white;
}

.timeline-step.current .timeline-dot {
    background: var(--primary-blue);
    border-color: var(--primary-blue);
    color: white;
    animation: pulse 2s infinite;
}

.timeline-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

/* Invoice Styles */
.invoice-container {
    background: white;
    padding: 40px;
    max-width: 800px;
    margin: 0 auto;
}

.invoice-header {
    text-align: center;
    margin-bottom: 40px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e7e7e7;
}

.invoice-logo {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--primary-blue);
    margin-bottom: 8px;
}

.invoice-title {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 20px;
}

.invoice-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
}

.invoice-section h4 {
    font-size: 0.875rem;
    text-transform: uppercase;
    color: var(--text-secondary);
    margin-bottom: 12px;
    letter-spacing: 0.5px;
}

.invoice-section p {
    margin-bottom: 4px;
    font-size: 0.9375rem;
}

.invoice-table {
    width: 100%;
    margin-bottom: 40px;
}

.invoice-table th {
    text-align: left;
    padding: 12px;
    background: #f8f9fa;
    font-size: 0.8125rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.invoice-table td {
    padding: 16px 12px;
    border-bottom: 1px solid #e7e7e7;
}

.invoice-total {
    text-align: right;
    margin-top: 20px;
}

.invoice-total-row {
    display: flex;
    justify-content: flex-end;
    gap: 40px;
    margin-bottom: 8px;
    font-size: 0.9375rem;
}

.invoice-total-row.grand {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid #e7e7e7;
}

@media print {
    .no-print {
        display: none !important;
    }
    body {
        background: white;
    }
}
</style>

<div class="bookings-page">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">My Trips</h1>
            <p class="page-subtitle">Manage your bookings and reservations</p>
        </div>
        
        <!-- Tabs -->
        <div class="booking-tabs">
            <button class="tab-btn active" onclick="switchTab('upcoming')">
                Upcoming <span class="tab-badge"><?php echo count($upcoming); ?></span>
            </button>
            <button class="tab-btn" onclick="switchTab('past')">
                Past <span class="tab-badge"><?php echo count($past); ?></span>
            </button>
        </div>
        
        <!-- Upcoming Bookings -->
        <div id="upcoming-tab" class="booking-list">
            <?php if (empty($upcoming)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h3 class="empty-title">No upcoming trips</h3>
                    <p class="empty-text">Start exploring and book your next adventure in Rwanda!</p>
                    <a href="/gorwanda-plus/search.php?type=stays" class="btn-primary-action">Explore Stays</a>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming as $booking): 
                    $statusClass = match($booking['status']) {
                        'confirmed' => 'status-confirmed',
                        'pending' => 'status-pending',
                        'completed' => 'status-completed',
                        'cancelled' => 'status-cancelled',
                        default => 'status-pending'
                    };
                    $statusLabel = match($booking['status']) {
                        'confirmed' => 'Confirmed',
                        'pending' => 'Pending',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        default => 'Pending'
                    };
                    
                    $bookingDate = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
                    $bookingEnd = $booking['check_out_date'] ?? $booking['return_date'] ?? $booking['experience_date'];
                    $typeLabel = ucfirst($booking['booking_type']);
                ?>
                <div class="booking-card">
                    <div class="booking-header">
                        <div class="booking-image">
                            <img src="<?php echo getImageUrl($booking['item_image'], $booking['booking_type']); ?>" 
                                 alt="<?php echo sanitize($booking['item_name']); ?>"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22%3E%3Crect fill=%22%23f5f5f5%22 width=%22400%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                        </div>
                        
                        <div class="booking-info">
                            <div>
                                <div class="booking-type"><?php echo $typeLabel; ?></div>
                                <h3 class="booking-title"><?php echo sanitize($booking['item_name']); ?></h3>
                                <?php if ($booking['room_name']): ?>
                                    <div style="color: var(--text-secondary); font-size: 0.875rem; margin-top: 4px;">
                                        <?php echo sanitize($booking['room_name']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="booking-dates">
                                    <span><i class="bi bi-calendar3"></i> <?php echo formatDate($bookingDate, 'D, M d, Y'); ?></span>
                                    <?php if ($bookingEnd && $bookingEnd !== $bookingDate): ?>
                                        <span><i class="bi bi-arrow-right"></i> <?php echo formatDate($bookingEnd, 'D, M d, Y'); ?></span>
                                    <?php endif; ?>
                                    <span><i class="bi bi-people"></i> <?php echo $booking['num_guests']; ?> guest<?php echo $booking['num_guests'] > 1 ? 's' : ''; ?></span>
                                </div>
                            </div>
                            
                            <div class="booking-ref">
                                Ref: <?php echo $booking['booking_reference']; ?> • 
                                Booked on <?php echo formatDate($booking['created_at'], 'M d, Y'); ?>
                            </div>
                        </div>
                        
                        <div class="booking-status">
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <i class="bi bi-check-circle-fill"></i> <?php echo $statusLabel; ?>
                            </span>
                            
                            <div class="price-summary" style="margin-top: 16px;">
                                <div class="price-label">Total</div>
                                <div class="price-total"><?php echo formatPrice($booking['total_amount']); ?></div>
                            </div>
                        </div>
                    </div>
                    
<div class="booking-actions">
    <button class="btn-action btn-primary-action" onclick="viewDetails('<?php echo $booking['booking_reference']; ?>')">
        <i class="bi bi-eye"></i> View Details
    </button>
    <button class="btn-action btn-secondary-action" onclick="downloadInvoice('<?php echo $booking['booking_reference']; ?>')">
        <i class="bi bi-download"></i> Invoice
    </button>
    <?php if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'completed'): ?>
        <button class="btn-action btn-secondary-action" onclick="confirmCancel('<?php echo $booking['booking_reference']; ?>')">
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
                    <h3 class="empty-title">No past trips</h3>
                    <p class="empty-text">Your travel history will appear here</p>
                </div>
            <?php else: ?>
                <?php foreach ($past as $booking): 
                    $statusClass = $booking['status'] === 'cancelled' ? 'status-cancelled' : 'status-completed';
                    $statusLabel = $booking['status'] === 'cancelled' ? 'Cancelled' : 'Completed';
                    $bookingDate = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
                    $typeLabel = ucfirst($booking['booking_type']);
                ?>
                <div class="booking-card" style="opacity: 0.8;">
                    <div class="booking-header">
                        <div class="booking-image">
                            <img src="<?php echo getImageUrl($booking['item_image'], $booking['booking_type']); ?>" 
                                 alt="<?php echo sanitize($booking['item_name']); ?>"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22%3E%3Crect fill=%22%23f5f5f5%22 width=%22400%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                        </div>
                        
                        <div class="booking-info">
                            <div>
                                <div class="booking-type"><?php echo $typeLabel; ?></div>
                                <h3 class="booking-title"><?php echo sanitize($booking['item_name']); ?></h3>
                                
                                <div class="booking-dates">
                                    <span><i class="bi bi-calendar3"></i> <?php echo formatDate($bookingDate, 'D, M d, Y'); ?></span>
                                    <span><i class="bi bi-people"></i> <?php echo $booking['num_guests']; ?> guest<?php echo $booking['num_guests'] > 1 ? 's' : ''; ?></span>
                                </div>
                            </div>
                            
                            <div class="booking-ref">
                                Ref: <?php echo $booking['booking_reference']; ?>
                            </div>
                        </div>
                        
                        <div class="booking-status">
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $statusLabel; ?>
                            </span>
                            
                            <div class="price-summary" style="margin-top: 16px;">
                                <div class="price-total"><?php echo formatPrice($booking['total_amount']); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="booking-actions">
                        <a href="#" class="btn-action btn-secondary-action" onclick="alert('Book again coming soon')">
                            <i class="bi bi-arrow-repeat"></i> Book Again
                        </a>
                        <a href="#" class="btn-action btn-secondary-action" onclick="alert('Write review coming soon')">
                            <i class="bi bi-star"></i> Write Review
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.closest('.tab-btn').classList.add('active');
    
    document.getElementById('upcoming-tab').style.display = tab === 'upcoming' ? 'grid' : 'none';
    document.getElementById('past-tab').style.display = tab === 'past' ? 'grid' : 'none';
}

function confirmCancel(ref) {
    if (confirm('Are you sure you want to cancel booking ' + ref + '?')) {
        alert('Cancellation request submitted for ' + ref);
    }
}
</script>
<!-- Booking Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Booking Details</h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<script>
// Store booking data for modal
const bookingsData = <?php echo json_encode($bookings); ?>;

function viewDetails(bookingRef) {
    const booking = bookingsData.find(b => b.booking_reference === bookingRef);
    if (!booking) return;
    
    const modalBody = document.getElementById('modalBody');
    
    const statusSteps = [
        { label: 'Booked', icon: 'bi-check', active: true },
        { label: 'Confirmed', icon: 'bi-check-circle', active: booking.status !== 'pending', current: booking.status === 'confirmed' },
        { label: 'Completed', icon: 'bi-star', active: booking.status === 'completed' || booking.status === 'checked_out', current: false }
    ];
    
    modalBody.innerHTML = `
        <div class="status-timeline">
            ${statusSteps.map(step => `
                <div class="timeline-step ${step.active ? 'active' : ''} ${step.current ? 'current' : ''}">
                    <div class="timeline-dot"><i class="bi ${step.icon}"></i></div>
                    <div class="timeline-label">${step.label}</div>
                </div>
            `).join('')}
        </div>
        
        <div style="background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
            <div style="font-size: 1.125rem; font-weight: 700; margin-bottom: 4px;">${booking.item_name}</div>
            <div style="color: var(--text-secondary); font-size: 0.875rem;">
                ${booking.room_name ? booking.room_name + ' • ' : ''}
                ${booking.booking_type === 'stay' ? 'Accommodation' : booking.booking_type}
            </div>
        </div>
        
        <div class="detail-row-modal">
            <span class="detail-label-modal">Booking Reference</span>
            <span class="detail-value-modal" style="font-family: monospace;">${booking.booking_reference}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Check-in / Start Date</span>
            <span class="detail-value-modal">${formatDate(booking.check_in_date || booking.pickup_date || booking.experience_date)}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Check-out / End Date</span>
            <span class="detail-value-modal">${formatDate(booking.check_out_date || booking.return_date || booking.experience_date)}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Guests</span>
            <span class="detail-value-modal">${booking.num_guests} person${booking.num_guests > 1 ? 's' : ''}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Guest Name</span>
            <span class="detail-value-modal">${booking.guest_first_name} ${booking.guest_last_name}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Contact Email</span>
            <span class="detail-value-modal">${booking.guest_email}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Contact Phone</span>
            <span class="detail-value-modal">${booking.guest_phone || 'N/A'}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Special Requests</span>
            <span class="detail-value-modal">${booking.special_requests || 'None'}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Payment Method</span>
            <span class="detail-value-modal">${booking.payment_method ? booking.payment_method.toUpperCase() : 'Pending'}</span>
        </div>
        <div class="detail-row-modal">
            <span class="detail-label-modal">Payment Status</span>
            <span class="detail-value-modal" style="color: ${booking.payment_status === 'paid' ? '#059669' : '#d97706'}; font-weight: 700;">
                ${booking.payment_status === 'paid' ? '✓ Paid' : '⏳ Pending'}
            </span>
        </div>
        
        <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #e7e7e7;">
            <div class="detail-row-modal" style="font-size: 1.125rem;">
                <span class="detail-label-modal" style="font-weight: 700;">Total Amount</span>
                <span class="detail-value-modal" style="font-weight: 800; color: var(--primary-blue); font-size: 1.25rem;">
                    RWF ${parseInt(booking.total_amount).toLocaleString()}
                </span>
            </div>
        </div>
        
        <div style="display: flex; gap: 12px; margin-top: 24px;">
            <button class="btn-action btn-primary-action" style="flex: 1;" onclick="downloadInvoice('${booking.booking_reference}')">
                <i class="bi bi-download"></i> Download Invoice
            </button>
            <button class="btn-action btn-secondary-action" style="flex: 1;" onclick="closeModal()">
                Close
            </button>
        </div>
    `;
    
    document.getElementById('detailsModal').classList.add('active');
}

function closeModal() {
    document.getElementById('detailsModal').classList.remove('active');
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function downloadInvoice(bookingRef) {
    const booking = bookingsData.find(b => b.booking_reference === bookingRef);
    if (!booking) return;
    
    const invoiceWindow = window.open('', '_blank');
    const invoiceHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invoice ${booking.booking_reference}</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1a1a1a; }
                .invoice-container { max-width: 800px; margin: 40px auto; padding: 40px; }
                .invoice-header { text-align: center; border-bottom: 2px solid #e7e7e7; padding-bottom: 30px; margin-bottom: 40px; }
                .invoice-logo { font-size: 28px; font-weight: 800; color: #0066ff; margin-bottom: 10px; }
                .invoice-title { font-size: 24px; font-weight: 700; margin-top: 30px; color: #1a1a1a; }
                .invoice-meta { display: flex; justify-content: space-between; margin-bottom: 40px; }
                .invoice-section h4 { font-size: 12px; text-transform: uppercase; color: #6b7280; margin-bottom: 12px; letter-spacing: 0.5px; }
                .invoice-section p { margin: 4px 0; font-size: 14px; }
                table { width: 100%; border-collapse: collapse; margin: 30px 0; }
                th { text-align: left; padding: 12px; background: #f9fafb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; }
                td { padding: 16px 12px; border-bottom: 1px solid #e7e7e7; font-size: 14px; }
                .text-right { text-align: right; }
                .total-section { margin-top: 30px; border-top: 2px solid #e7e7e7; padding-top: 20px; }
                .total-row { display: flex; justify-content: flex-end; gap: 60px; margin-bottom: 8px; font-size: 14px; }
                .grand-total { font-size: 18px; font-weight: 700; margin-top: 15px; padding-top: 15px; border-top: 1px solid #e7e7e7; }
                .print-btn { display: block; width: 200px; margin: 40px auto 0; padding: 12px 24px; background: #0066ff; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
                @media print { .print-btn { display: none; } }
            </style>
        </head>
        <body>
            <div class="invoice-container">
                <div class="invoice-header">
                    <div class="invoice-logo">GoRwanda+</div>
                    <div style="color: #6b7280; font-size: 14px;">Official Booking Invoice</div>
                    <div class="invoice-title">INVOICE</div>
                    <div style="font-size: 14px; color: #6b7280; margin-top: 10px;">
                        Reference: <strong>${booking.booking_reference}</strong> • 
                        Date: <strong>${new Date().toLocaleDateString()}</strong>
                    </div>
                </div>
                
                <div class="invoice-meta">
                    <div class="invoice-section">
                        <h4>Billed To</h4>
                        <p><strong>${booking.guest_first_name} ${booking.guest_last_name}</strong></p>
                        <p>${booking.guest_email}</p>
                        <p>${booking.guest_phone || ''}</p>
                    </div>
                    <div class="invoice-section">
                        <h4>From</h4>
                        <p><strong>GoRwanda+ Ltd</strong></p>
                        <p>KG 7 Ave, Kigali, Rwanda</p>
                        <p>support@gorwanda.rw</p>
                        <p>+250 788 123 456</p>
                    </div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Dates</th>
                            <th class="text-right">Guests</th>
                            <th class="text-right">Amount (RWF)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>${booking.item_name}</strong><br>
                                <span style="color: #6b7280; font-size: 12px;">${booking.booking_type === 'stay' ? 'Accommodation' : booking.booking_type}</span>
                                ${booking.room_name ? '<br><span style="color: #6b7280; font-size: 12px;">' + booking.room_name + '</span>' : ''}
                            </td>
                            <td>
                                ${formatDate(booking.check_in_date || booking.pickup_date || booking.experience_date)}<br>
                                to<br>
                                ${formatDate(booking.check_out_date || booking.return_date || booking.experience_date)}
                            </td>
                            <td class="text-right">${booking.num_guests}</td>
                            <td class="text-right">${(parseInt(booking.total_amount) - parseInt(booking.tax_amount || 0) - parseInt(booking.commission_amount || 0)).toLocaleString()}</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="total-section">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>RWF ${(parseInt(booking.total_amount) - parseInt(booking.tax_amount || 0) - parseInt(booking.commission_amount || 0)).toLocaleString()}</span>
                    </div>
                    <div class="total-row">
                        <span>Service Fee (10%):</span>
                        <span>RWF ${parseInt(booking.commission_amount || 0).toLocaleString()}</span>
                    </div>
                    <div class="total-row">
                        <span>VAT (18%):</span>
                        <span>RWF ${parseInt(booking.tax_amount || 0).toLocaleString()}</span>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total Paid:</span>
                        <span>RWF ${parseInt(booking.total_amount).toLocaleString()}</span>
                    </div>
                </div>
                
                <div style="margin-top: 40px; padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center;">
                    <p style="margin: 0; color: #059669; font-weight: 600;">
                        <i class="bi bi-check-circle-fill"></i> Payment Confirmed
                    </p>
                    <p style="margin: 8px 0 0; font-size: 12px; color: #6b7280;">
                        Thank you for booking with GoRwanda+
                    </p>
                </div>
                
                <button class="print-btn" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Invoice
                </button>
            </div>
        </body>
        </html>
    `;
    
    invoiceWindow.document.write(invoiceHTML);
    invoiceWindow.document.close();
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