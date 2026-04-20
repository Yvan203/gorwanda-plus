<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/functions.php';

$currentPage = 'restaurants';

// Require login to make reservation
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    setFlash('warning', 'Please sign in to make a reservation');
    header('Location: /gorwanda-plus/login.php');
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $restaurant_id = isset($_POST['restaurant_id']) ? intval($_POST['restaurant_id']) : 0;
    $date = isset($_POST['date']) ? $_POST['date'] : '';
    $time = isset($_POST['time']) ? $_POST['time'] : '';
    $guests = isset($_POST['guests']) ? intval($_POST['guests']) : 2;
    $name = isset($_POST['name']) ? sanitize($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize($_POST['phone']) : '';
    $special_requests = isset($_POST['special_requests']) ? sanitize($_POST['special_requests']) : '';
    $table_preference = isset($_POST['table_preference']) ? sanitize($_POST['table_preference']) : '';
    
    // Validation
    $errors = [];
    
    if (!$restaurant_id) {
        $errors[] = 'Invalid restaurant';
    }
    
    if (!$date) {
        $errors[] = 'Please select a date';
    } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
        $errors[] = 'Date cannot be in the past';
    }
    
    if (!$time) {
        $errors[] = 'Please select a time';
    }
    
    if ($guests < 1 || $guests > 20) {
        $errors[] = 'Invalid number of guests';
    }
    
    if (!$name) {
        $errors[] = 'Please enter your name';
    }
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address';
    }
    
    // If no errors, create reservation
    if (empty($errors)) {
        // Get restaurant details for confirmation
        $stmt = $db->prepare("
            SELECT r.*, s.stay_name as hotel_name 
            FROM restaurants r
            LEFT JOIN stays s ON r.stay_id = s.stay_id
            WHERE r.restaurant_id = ? AND r.is_active = 1
        ");
        $stmt->execute([$restaurant_id]);
        $restaurant = $stmt->fetch();
        
        if (!$restaurant) {
            $errors[] = 'Restaurant not found';
        } else {
            // Generate unique confirmation code
            $confirmation_code = 'REST-' . strtoupper(substr(uniqid(), -6)) . '-' . date('ymd');
            
            // Check if table is available (optional - you can implement availability logic)
            // For now, we'll assume it's available
            
            // Insert reservation
            $stmt = $db->prepare("
                INSERT INTO table_reservations 
                (restaurant_id, user_id, reservation_date, reservation_time, guest_count, 
                 special_requests, table_preference, status, confirmation_code, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, NOW())
            ");
            
            $success = $stmt->execute([
                $restaurant_id,
                $_SESSION['user_id'],
                $date,
                $time,
                $guests,
                $special_requests,
                $table_preference,
                $confirmation_code
            ]);
            
            if ($success) {
                $reservation_id = $db->lastInsertId();
                
                // Send confirmation email using the function from functions.php
                sendReservationConfirmation(
                    $email,
                    $name,
                    $restaurant,
                    $confirmation_code,
                    $date,
                    $time,
                    $guests
                );
                
                // Set success message and redirect
                $_SESSION['reservation_success'] = [
                    'code' => $confirmation_code,
                    'restaurant' => $restaurant['restaurant_name'],
                    'date' => $date,
                    'time' => $time,
                    'guests' => $guests
                ];
                
                setFlash('success', 'Reservation confirmed! Your confirmation code is: ' . $confirmation_code);
                header('Location: reservation-success.php?id=' . $reservation_id);
                exit;
            } else {
                $errors[] = 'Failed to create reservation. Please try again.';
            }
        }
    }
    
    // If there are errors, store them in session and redirect back
    if (!empty($errors)) {
        $_SESSION['reservation_errors'] = $errors;
        $_SESSION['reservation_data'] = $_POST;
        header('Location: detail.php?id=' . $restaurant_id);
        exit;
    }
}

// If not POST, redirect to restaurants page
header('Location: index.php');
exit;
?>