<?php
require_once 'includes/functions.php';
requireLogin();

$bookingId = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

$pageTitle = 'Review Submitted';
$hideSearch = true;
require_once 'includes/header.php';
?>

<style>
    .success-container {
        max-width: 600px;
        margin: 60px auto;
        text-align: center;
        padding: 48px;
        background: white;
        border-radius: 24px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }

    .success-icon {
        width: 80px;
        height: 80px;
        background: #d1fae5;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
    }

    .success-icon i {
        font-size: 40px;
        color: #059669;
    }

    .success-title {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 12px;
        color: #1a1a1a;
    }

    .success-message {
        color: #6b6b6b;
        font-size: 16px;
        margin-bottom: 32px;
        line-height: 1.5;
    }

    .action-buttons {
        display: flex;
        gap: 16px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-primary,
    .btn-secondary {
        padding: 12px 28px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 14px;
        text-decoration: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #0066ff, #003b95);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 102, 255, 0.3);
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

    @media (max-width: 640px) {
        .success-container {
            margin: 20px;
            padding: 32px 24px;
        }

        .action-buttons {
            flex-direction: column;
        }

        .btn-primary,
        .btn-secondary {
            justify-content: center;
        }
    }
</style>

<div class="success-container">
    <div class="success-icon">
        <i class="bi bi-check-lg"></i>
    </div>

    <h1 class="success-title">Review Submitted!</h1>
    <p class="success-message">
        Thank you for sharing your experience. Your review helps other travelers make better choices.
    </p>

    <div class="action-buttons">
        <a href="bookings.php" class="btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Bookings
        </a>
        <a href="index.php" class="btn-primary">
            <i class="bi bi-house"></i> Explore More
        </a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>