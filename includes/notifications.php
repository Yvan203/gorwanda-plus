<?php

/**
 * Notification System - Manages all platform notifications
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class NotificationManager
{
    private $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    /**
     * Create a new notification
     */
    public function create($userId, $type, $title, $message, $data = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, type, title, message, data, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $dataJson = $data ? json_encode($data) : null;
            $stmt->execute([$userId, $type, $title, $message, $dataJson]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log("Notification creation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get unread count for a user
     */
    public function getUnreadCount($userId)
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = ? AND is_read = 0 AND is_archived = 0
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }

    /**
     * Get notifications for a user
     */
    public function getNotifications($userId, $limit = 20, $offset = 0)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? AND is_archived = 0
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId)
    {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE notification_id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead($userId)
    {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$userId]);
    }

    /**
     * Archive notification
     */
    public function archive($notificationId, $userId)
    {
        $stmt = $this->db->prepare("
            UPDATE notifications 
            SET is_archived = 1 
            WHERE notification_id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }

    /**
     * Delete notification
     */
    public function delete($notificationId, $userId)
    {
        $stmt = $this->db->prepare("
            DELETE FROM notifications 
            WHERE notification_id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    // ============================================
    // SPECIFIC NOTIFICATION TRIGGERS
    // ============================================

    /**
     * New booking notification
     */
    public function newBooking($bookingId, $userId, $bookingData)
    {
        return $this->create(
            $userId,
            'new_booking',
            'New Booking Received',
            "New booking #{$bookingData['reference']} from {$bookingData['guest_name']} for {$bookingData['item_name']}",
            [
                'booking_id' => $bookingId,
                'amount' => $bookingData['amount'],
                'reference' => $bookingData['reference']
            ]
        );
    }

    /**
     * Booking cancelled notification
     */
    public function bookingCancelled($bookingId, $userId, $bookingData)
    {
        return $this->create(
            $userId,
            'booking_cancelled',
            'Booking Cancelled',
            "Booking #{$bookingData['reference']} has been cancelled by {$bookingData['guest_name']}",
            [
                'booking_id' => $bookingId,
                'reference' => $bookingData['reference'],
                'cancelled_by' => $bookingData['cancelled_by'] ?? 'guest'
            ]
        );
    }

    /**
     * Payment received notification
     */
    public function paymentReceived($bookingId, $userId, $paymentData)
    {
        return $this->create(
            $userId,
            'payment_received',
            'Payment Received',
            "Payment of {$paymentData['amount']} received for booking #{$paymentData['reference']}",
            [
                'booking_id' => $bookingId,
                'amount' => $paymentData['amount'],
                'method' => $paymentData['method'] ?? 'unknown'
            ]
        );
    }

    /**
     * New vendor registration
     */
    public function newVendorRegistration($vendorId, $adminId, $vendorData)
    {
        return $this->create(
            $adminId,
            'vendor_registration',
            'New Partner Registration',
            "{$vendorData['business_name']} has registered as a new partner",
            [
                'vendor_id' => $vendorId,
                'business_name' => $vendorData['business_name'],
                'email' => $vendorData['email']
            ]
        );
    }

    /**
     * Vendor verification pending
     */
    public function vendorVerificationPending($vendorId, $adminId, $vendorData)
    {
        return $this->create(
            $adminId,
            'verification_pending',
            'Verification Required',
            "{$vendorData['business_name']} requires verification before going live",
            [
                'vendor_id' => $vendorId,
                'type' => $vendorData['type'],
                'business_name' => $vendorData['business_name']
            ]
        );
    }

    /**
     * Low inventory alert
     */
    public function lowInventory($stayId, $adminId, $inventoryData)
    {
        return $this->create(
            $adminId,
            'low_inventory',
            'Low Inventory Alert',
            "{$inventoryData['property_name']} has only {$inventoryData['rooms_left']} rooms left",
            [
                'stay_id' => $stayId,
                'property_name' => $inventoryData['property_name'],
                'rooms_left' => $inventoryData['rooms_left']
            ]
        );
    }

    /**
     * New review notification
     */
    public function newReview($reviewId, $ownerId, $reviewData)
    {
        return $this->create(
            $ownerId,
            'new_review',
            'New Review Posted',
            "{$reviewData['guest_name']} left a {$reviewData['rating']}-star review for {$reviewData['item_name']}",
            [
                'review_id' => $reviewId,
                'rating' => $reviewData['rating'],
                'item_name' => $reviewData['item_name']
            ]
        );
    }

    /**
     * System alert (maintenance, backup, etc.)
     */
    public function systemAlert($adminId, $title, $message, $data = null)
    {
        return $this->create($adminId, 'system_alert', $title, $message, $data);
    }

    /**
     * Daily summary report
     */
    public function dailySummary($adminId, $summaryData)
    {
        return $this->create(
            $adminId,
            'daily_summary',
            'Daily Summary Report',
            "Today: {$summaryData['bookings']} bookings, {$summaryData['revenue']} revenue, {$summaryData['new_users']} new users",
            $summaryData
        );
    }

    /**
     * Payout processed notification
     */
    public function payoutProcessed($payoutId, $adminId, $payoutData)
    {
        return $this->create(
            $adminId,
            'payout_processed',
            'Payout Processed',
            "Payout of {$payoutData['amount']} processed for {$payoutData['vendor_name']}",
            [
                'payout_id' => $payoutId,
                'amount' => $payoutData['amount'],
                'vendor_name' => $payoutData['vendor_name']
            ]
        );
    }
}

// Global function shortcuts
function createNotification($userId, $type, $title, $message, $data = null)
{
    $nm = new NotificationManager();
    return $nm->create($userId, $type, $title, $message, $data);
}

function getUnreadNotificationsCount($userId)
{
    $nm = new NotificationManager();
    return $nm->getUnreadCount($userId);
}
