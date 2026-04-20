<?php
require_once '../includes/functions.php';

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
$checkin = $_GET['checkin'] ?? '';
$checkout = $_GET['checkout'] ?? '';
$guests = intval($_GET['guests'] ?? 2);

if (!$id || !$checkin || !$checkout) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$db = getDB();

// Calculate nights
$nights = max(1, (strtotime($checkout) - strtotime($checkin)) / 86400);

// Get rooms with availability and pricing
$stmt = $db->prepare("
    SELECT sr.room_id as id, sr.room_name, sr.base_price as original_price,
           (SELECT MIN(price_override) FROM stay_availability sa 
            WHERE sa.room_id = sr.room_id 
            AND sa.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
            AND sa.price_override IS NOT NULL) as discounted_price,
           sr.max_guests
    FROM stay_rooms sr
    WHERE sr.stay_id = ? AND sr.is_active = 1
    ORDER BY sr.base_price ASC
");
$stmt->execute([$checkin, $checkout, $id]);
$rooms = $stmt->fetchAll();

$response = [
    'success' => true,
    'nights' => $nights,
    'minPrice' => 0,
    'rooms' => []
];

$minPrice = PHP_INT_MAX;

foreach ($rooms as $room) {
    $price = $room['discounted_price'] ?: $room['original_price'];
    $totalPrice = $price * $nights;
    
    if ($price < $minPrice) {
        $minPrice = $price;
    }
    
    $response['rooms'][] = [
        'id' => $room['id'],
        'price' => $price,
        'original_price' => $room['original_price'],
        'discounted_price' => $room['discounted_price'],
        'total_price' => $totalPrice,
        'has_discount' => $room['discounted_price'] && $room['discounted_price'] < $room['original_price']
    ];
}

$response['minPrice'] = $minPrice;

echo json_encode($response);
?>