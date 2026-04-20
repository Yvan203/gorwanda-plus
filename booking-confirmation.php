<?php
ob_start();
require_once 'includes/functions.php';

$bookingRef = sanitize($_GET['ref'] ?? '');
$pageTitle = 'Booking Confirmed';
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
.confirmation-page {
    min-height: calc(100vh - 64px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #e6f4ea 0%, #d1fae5 100%);
    padding: 40px 20px;
}

.confirmation-card {
    background: white;
    border-radius: 24px;
    padding: 48px;
    width: 100%;
    max-width: 600px;
    text-align: center;
    box-shadow: 0 25px 50px rgba(0,0,0,0.1);
    animation: slideUp 0.6s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.success-icon {
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    color: white;
    font-size: 3rem;
    animation: scaleIn 0.5s ease-out 0.2s both;
}

@keyframes scaleIn {
    from {
        transform: scale(0);
    }
    to {
        transform: scale(1);
    }
}

.confirmation-title {
    font-size: 2rem;
    font-weight: 800;
    color: #059669;
    margin-bottom: 12px;
}

.confirmation-subtitle {
    font-size: 1.125rem;
    color: var(--text-secondary);
    margin-bottom: 32px;
}

.booking-details {
    background: var(--bg-gray);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 32px;
    text-align: left;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 600;
    color: var(--text-secondary);
}

.detail-value {
    font-weight: 700;
    color: var(--text-primary);
}

.action-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
}

.btn-primary {
    padding: 14px 32px;
    background: var(--primary-blue);
    color: white;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,102,255,0.3);
}

.btn-outline {
    padding: 14px 32px;
    border: 2px solid var(--border-color);
    color: var(--text-primary);
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s;
}

.btn-outline:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

.email-notice {
    margin-top: 24px;
    padding: 16px;
    background: #dbeafe;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.875rem;
    color: #1e40af;
}
</style>

<div class="confirmation-page">
    <div class="confirmation-card">
        <div class="success-icon">
            <i class="bi bi-check-lg"></i>
        </div>
        
        <h1 class="confirmation-title">Booking Confirmed!</h1>
        <p class="confirmation-subtitle">
            Your reservation has been successfully processed
        </p>
        
        <div class="booking-details">
            <div class="detail-row">
                <span class="detail-label">Booking Reference</span>
                <span class="detail-value" style="font-family: monospace;"><?php echo $bookingRef; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Status</span>
                <span class="detail-value" style="color: #059669;">
                    <i class="bi bi-check-circle-fill me-1"></i>Confirmed
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment</span>
                <span class="detail-value" style="color: #059669;">Paid via MTN MoMo</span>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="/gorwanda-plus/bookings.php" class="btn-primary">View My Bookings</a>
            <a href="/gorwanda-plus/" class="btn-outline">Back to Home</a>
        </div>
        
        <div class="email-notice">
            <i class="bi bi-envelope-check-fill fs-4"></i>
            <span>A confirmation email has been sent to your inbox with all details</span>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>