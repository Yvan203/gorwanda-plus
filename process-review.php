<?php
require_once 'includes/functions.php';
require_once 'includes/notifications.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bookings.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Get form data
$bookingId = intval($_POST['booking_id'] ?? 0);
$itemId = intval($_POST['item_id'] ?? 0);
$reviewType = sanitize($_POST['review_type'] ?? 'stay');
$overallRating = intval($_POST['overall_rating'] ?? 0);
$title = sanitize($_POST['title'] ?? '');
$comment = sanitize($_POST['comment'] ?? '');
$positivePoints = sanitize($_POST['positive_points'] ?? '');
$negativePoints = sanitize($_POST['negative_points'] ?? '');
$categories = isset($_POST['categories']) ? $_POST['categories'] : [];

// Validation
$errors = [];

if ($overallRating < 1 || $overallRating > 10) {
    $errors[] = "Please select a rating between 1 and 10";
}

if (empty($title)) {
    $errors[] = "Review title is required";
}

if (empty($comment)) {
    $errors[] = "Review comment is required";
}

if (strlen($title) > 100) {
    $errors[] = "Title cannot exceed 100 characters";
}

if (strlen($comment) > 1000) {
    $errors[] = "Comment cannot exceed 1000 characters";
}

// Verify booking belongs to user and is completed
$stmt = $db->prepare("
    SELECT b.*, 
           CASE 
               WHEN b.booking_type = 'stay' THEN s.stay_id
               WHEN b.booking_type = 'car_rental' THEN cr.rental_id
               WHEN b.booking_type = 'attraction' THEN a.attraction_id
           END as item_id
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
    $_SESSION['error'] = "Invalid booking or you cannot review this booking";
    header('Location: bookings.php');
    exit;
}

// Check if already reviewed
$stmt = $db->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND user_id = ?");
$stmt->execute([$bookingId, $userId]);
if ($stmt->fetch()) {
    $_SESSION['error'] = "You have already reviewed this booking";
    header('Location: bookings.php');
    exit;
}

if (empty($errors)) {
    try {
        // Start transaction
        $db->beginTransaction();

        // Insert review
        $stmt = $db->prepare("
            INSERT INTO reviews (
                booking_id, user_id, review_type, stay_id, rental_id, attraction_id,
                overall_rating, title, comment, positive_points, negative_points,
                categories, is_active, is_verified, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())
        ");

        $stayId = ($reviewType == 'stay') ? $itemId : null;
        $rentalId = ($reviewType == 'car_rental') ? $itemId : null;
        $attractionId = ($reviewType == 'attraction') ? $itemId : null;

        $stmt->execute([
            $bookingId,
            $userId,
            $reviewType,
            $stayId,
            $rentalId,
            $attractionId,
            $overallRating,
            $title,
            $comment,
            $positivePoints,
            $negativePoints,
            json_encode($categories)
        ]);

        $reviewId = $db->lastInsertId();

        // Update average rating for the item
        if ($reviewType == 'stay') {
            $stmt = $db->prepare("
                UPDATE stays s 
                SET avg_rating = (
                    SELECT AVG(overall_rating) FROM reviews WHERE stay_id = s.stay_id AND is_active = 1
                ),
                review_count = (
                    SELECT COUNT(*) FROM reviews WHERE stay_id = s.stay_id AND is_active = 1
                )
                WHERE stay_id = ?
            ");
            $stmt->execute([$stayId]);
        } elseif ($reviewType == 'car_rental') {
            $stmt = $db->prepare("
                UPDATE car_rentals cr 
                SET avg_rating = (
                    SELECT AVG(overall_rating) FROM reviews WHERE rental_id = cr.rental_id AND is_active = 1
                ),
                review_count = (
                    SELECT COUNT(*) FROM reviews WHERE rental_id = cr.rental_id AND is_active = 1
                )
                WHERE rental_id = ?
            ");
            $stmt->execute([$rentalId]);
        } elseif ($reviewType == 'attraction') {
            $stmt = $db->prepare("
                UPDATE attractions a 
                SET avg_rating = (
                    SELECT AVG(overall_rating) FROM reviews WHERE attraction_id = a.attraction_id AND is_active = 1
                ),
                review_count = (
                    SELECT COUNT(*) FROM reviews WHERE attraction_id = a.attraction_id AND is_active = 1
                )
                WHERE attraction_id = ?
            ");
            $stmt->execute([$attractionId]);
        }

        // Update booking to mark as reviewed (optional)
        // You can add a `reviewed` column to bookings if needed

        $db->commit();

        // Send notifications
        $notificationManager = new NotificationManager();

        // Notify admin
        $notificationManager->create(
            1,
            'new_review',
            'New Review Posted',
            "User {$booking['email']} left a {$overallRating}/10 review for {$booking['item_name']}",
            ['review_id' => $reviewId, 'rating' => $overallRating]
        );

        // Get property owner ID and notify them
        if ($reviewType == 'stay') {
            $stmt = $db->prepare("SELECT owner_id FROM stays WHERE stay_id = ?");
            $stmt->execute([$stayId]);
            $ownerId = $stmt->fetchColumn();
        } elseif ($reviewType == 'car_rental') {
            $stmt = $db->prepare("SELECT owner_id FROM car_rentals WHERE rental_id = ?");
            $stmt->execute([$rentalId]);
            $ownerId = $stmt->fetchColumn();
        } elseif ($reviewType == 'attraction') {
            $stmt = $db->prepare("SELECT owner_id FROM attractions WHERE attraction_id = ?");
            $stmt->execute([$attractionId]);
            $ownerId = $stmt->fetchColumn();
        }

        if ($ownerId) {
            $notificationManager->create(
                $ownerId,
                'new_review',
                'New Review for Your Property',
                "A guest left a {$overallRating}/10 review for your property",
                ['review_id' => $reviewId, 'rating' => $overallRating]
            );
        }

        $_SESSION['success'] = "Thank you for your review! It has been published successfully.";
        header('Location: review-success.php?booking_id=' . $bookingId);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Failed to submit review: " . $e->getMessage();
        header('Location: review-form.php?booking_id=' . $bookingId);
        exit;
    }
} else {
    $_SESSION['errors'] = $errors;
    header('Location: review-form.php?booking_id=' . $bookingId);
    exit;
}
