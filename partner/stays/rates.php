<?php
$pageTitle = 'Rates & Pricing';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$view = isset($_GET['view']) ? sanitize($_GET['view']) : 'rooms';

// ============================================
// HANDLE RATE ACTIONS
// ============================================

// Update room base price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_base_price'])) {
    $roomId = intval($_POST['room_id']);
    $newPrice = floatval($_POST['base_price']);
    
    // Verify ownership
    $stmt = $db->prepare("
        UPDATE stay_rooms sr
        JOIN stays s ON sr.stay_id = s.stay_id
        SET sr.base_price = ?
        WHERE sr.room_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$newPrice, $roomId, $userId]);
    
    $success = "Base price updated successfully!";
}

// Bulk update prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_prices'])) {
    $stayId = intval($_POST['stay_id']);
    $action = $_POST['bulk_action'];
    $value = floatval($_POST['bulk_value']);
    $roomIds = isset($_POST['room_ids']) ? $_POST['room_ids'] : [];
    
    if (empty($roomIds)) {
        // Get all rooms for this property
        $stmt = $db->prepare("SELECT room_id FROM stay_rooms WHERE stay_id = ?");
        $stmt->execute([$stayId]);
        $roomIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if (!empty($roomIds)) {
        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $params = $roomIds;
        
        if ($action === 'increase_percent') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = base_price * (1 + ? / 100)
                WHERE room_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        } elseif ($action === 'decrease_percent') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = base_price * (1 - ? / 100)
                WHERE room_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        } elseif ($action === 'set_fixed') {
            $stmt = $db->prepare("
                UPDATE stay_rooms 
                SET base_price = ?
                WHERE room_id IN ($placeholders)
            ");
            array_unshift($params, $value);
        }
        
        $stmt->execute($params);
        $success = "Bulk price update completed successfully!";
    }
}

// Create season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_season'])) {
    $stayId = intval($_POST['stay_id']);
    $seasonName = sanitize($_POST['season_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $priceMultiplier = floatval($_POST['price_multiplier']);
    $applyToAll = isset($_POST['apply_to_all']) ? 1 : 0;
    
    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            INSERT INTO seasons (
                vendor_id, season_name, start_date, end_date, 
                price_multiplier, is_recurring, created_at
            ) VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $seasonName, $startDate, $endDate, $priceMultiplier]);
        $success = "Season created successfully!";
    }
}

// Create special offer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_offer'])) {
    $stayId = intval($_POST['stay_id']);
    $offerName = sanitize($_POST['offer_name']);
    $offerType = $_POST['offer_type'];
    $discountValue = floatval($_POST['discount_value']);
    $minNights = intval($_POST['min_nights'] ?? 1);
    $startDate = $_POST['offer_start_date'];
    $endDate = $_POST['offer_end_date'];
    $daysOfWeek = isset($_POST['days_of_week']) ? json_encode($_POST['days_of_week']) : null;
    $roomIds = isset($_POST['offer_rooms']) ? json_encode($_POST['offer_rooms']) : null;
    
    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            INSERT INTO offers (
                vendor_id, offer_name, offer_type, discount_value,
                min_nights, start_date, end_date, days_of_week,
                applicable_to, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $userId, $offerName, $offerType, $discountValue,
            $minNights, $startDate, $endDate, $daysOfWeek,
            $roomIds
        ]);
        $success = "Special offer created successfully!";
    }
}

// Toggle offer status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_offer'])) {
    $offerId = intval($_POST['offer_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE offers 
        SET is_active = ? 
        WHERE offer_id = ? AND vendor_id = ?
    ");
    $stmt->execute([$newStatus, $offerId, $userId]);
    
    $success = "Offer status updated!";
}

// Delete offer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_offer'])) {
    $offerId = intval($_POST['offer_id']);
    
    $stmt = $db->prepare("DELETE FROM offers WHERE offer_id = ? AND vendor_id = ?");
    $stmt->execute([$offerId, $userId]);
    
    $success = "Offer deleted successfully!";
}

// ============================================
// GET DATA
// ============================================

// Get all properties
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Get rooms for selected property
$rooms = [];
$propertyName = '';
if ($propertyId > 0) {
    $stmt = $db->prepare("
        SELECT 
            sr.*,
            (SELECT COUNT(*) FROM stay_availability sa 
             WHERE sa.room_id = sr.room_id 
             AND sa.price_override IS NOT NULL) as special_price_days
        FROM stay_rooms sr
        WHERE sr.stay_id = ? AND sr.is_active = 1
        ORDER BY sr.base_price
    ");
    $stmt->execute([$propertyId]);
    $rooms = $stmt->fetchAll();
    
    // Get property details
    $stmt = $db->prepare("SELECT stay_name FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$propertyId, $userId]);
    $propertyData = $stmt->fetch();
    $propertyName = $propertyData ? $propertyData['stay_name'] : '';
}

// Get pricing statistics
$stats = [
    'min_price' => 0,
    'max_price' => 0,
    'avg_price' => 0,
    'total_rooms' => count($rooms),
    'rooms_with_special' => 0,
    'price_ranges' => []
];

if (!empty($rooms)) {
    $prices = array_column($rooms, 'base_price');
    $stats['min_price'] = min($prices);
    $stats['max_price'] = max($prices);
    $stats['avg_price'] = array_sum($prices) / count($prices);
    
    $stats['rooms_with_special'] = count(array_filter($rooms, function($r) {
        return $r['special_price_days'] > 0;
    }));
    
    // Price ranges distribution
    $ranges = [
        '0-50000' => 0,
        '50001-100000' => 0,
        '100001-150000' => 0,
        '150001-200000' => 0,
        '200001+' => 0
    ];
    
    foreach ($prices as $price) {
        if ($price <= 50000) $ranges['0-50000']++;
        elseif ($price <= 100000) $ranges['50001-100000']++;
        elseif ($price <= 150000) $ranges['100001-150000']++;
        elseif ($price <= 200000) $ranges['150001-200000']++;
        else $ranges['200001+']++;
    }
    
    $stats['price_ranges'] = $ranges;
}

// Get seasons
$stmt = $db->prepare("
    SELECT * FROM seasons 
    WHERE vendor_id = ? 
    ORDER BY start_date DESC
");
$stmt->execute([$userId]);
$seasons = $stmt->fetchAll();

// Get special offers
$stmt = $db->prepare("
    SELECT o.*, s.stay_name 
    FROM offers o
    LEFT JOIN stays s ON JSON_CONTAINS(o.applicable_to, JSON_QUOTE(CAST(s.stay_id AS CHAR)), '$')
    WHERE o.vendor_id = ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$userId]);
$offers = $stmt->fetchAll();

// Get dynamic pricing suggestions
$suggestions = [];
if ($propertyId > 0 && !empty($rooms)) {
    // Calculate suggested prices based on occupancy
    $stmt = $db->prepare("
        SELECT 
            sr.room_id,
            sr.room_name,
            sr.base_price,
            COUNT(b.booking_id) as booking_count,
            AVG(b.total_amount / b.num_nights) as avg_selling_price,
            COUNT(DISTINCT DATE_FORMAT(b.created_at, '%Y-%m')) as months_active
        FROM stay_rooms sr
        LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
            AND b.status IN ('confirmed', 'completed')
            AND b.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        WHERE sr.stay_id = ?
        GROUP BY sr.room_id
    ");
    $stmt->execute([$propertyId]);
    $suggestions = $stmt->fetchAll();
}

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Offer types
$offerTypes = [
    'percentage' => 'Percentage Discount',
    'fixed' => 'Fixed Amount Off',
    'night_free' => 'Free Night'
];
?>

<style>
/* Rates Management Specific Styles */
.rates-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.rates-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.rates-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Property Selector */
.property-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.property-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--booking-text);
}

.property-selector select {
    min-width: 300px;
    padding: 10px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--booking-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-blue);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--booking-border);
}

/* Tab Navigation */
.rates-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--booking-border);
    padding-bottom: 0;
}

.rates-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.rates-tab:hover {
    color: var(--booking-blue);
}

.rates-tab.active {
    color: var(--booking-blue);
}

.rates-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--booking-blue);
}

/* Rooms Grid */
.rooms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.room-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
}

.room-card:hover {
    box-shadow: var(--shadow-md);
}

.room-header {
    padding: 16px;
    background: var(--booking-light-blue);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.room-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-blue);
}

.room-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: white;
    color: var(--booking-success);
    border: 1px solid var(--booking-success);
}

.room-body {
    padding: 16px;
}

.room-price-section {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--booking-border);
}

.current-price {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-success);
}

.current-price small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--booking-text-light);
}

.price-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.price-stat-item {
    text-align: center;
}

.price-stat-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.price-stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.room-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.room-action-btn {
    flex: 1;
    padding: 10px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    text-decoration: none;
}

.room-action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.room-action-btn.success:hover {
    background: #e6f4ea;
    border-color: var(--booking-success);
    color: var(--booking-success);
}

/* Seasons Grid */
.seasons-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.season-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    position: relative;
}

.season-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.season-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.season-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: var(--booking-light-blue);
    color: var(--booking-blue);
}

.season-dates {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.season-multiplier {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-success);
    margin-bottom: 12px;
}

.season-multiplier small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--booking-text-light);
}

/* Offers Grid */
.offers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.offer-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.offer-card.inactive {
    opacity: 0.7;
    background: var(--booking-gray);
}

.offer-header {
    padding: 16px;
    background: linear-gradient(135deg, #f5f7fa, white);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.offer-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.offer-status {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.offer-status.active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.offer-status.inactive {
    background: var(--booking-gray);
    color: var(--booking-text-light);
}

.offer-body {
    padding: 16px;
}

.offer-discount {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-danger);
    margin-bottom: 12px;
}

.offer-discount small {
    font-size: 0.75rem;
    font-weight: 400;
    color: var(--booking-text-light);
}

.offer-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin: 16px 0;
    padding: 12px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.offer-detail-item {
    text-align: center;
}

.offer-detail-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.offer-detail-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text);
}

.offer-properties {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 16px;
    padding: 8px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

.offer-actions {
    display: flex;
    gap: 8px;
}

.offer-action-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.offer-action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.offer-action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--booking-warning);
    color: var(--booking-warning);
}

.offer-action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--booking-danger);
    color: var(--booking-danger);
}

/* Price Distribution */
.price-distribution {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.distribution-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
}

.distribution-bars {
    display: flex;
    gap: 20px;
    align-items: flex-end;
    height: 200px;
}

.distribution-bar-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.distribution-bar {
    width: 100%;
    background: var(--booking-light-blue);
    border-radius: 4px 4px 0 0;
    transition: height 0.3s;
    min-height: 4px;
}

.distribution-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-align: center;
}

/* Suggestions Section */
.suggestions-section {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-top: 30px;
}

.suggestions-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.suggestion-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.suggestion-item:last-child {
    border-bottom: none;
}

.suggestion-room {
    flex: 2;
    font-weight: 600;
}

.suggestion-current {
    flex: 1;
    color: var(--booking-text-light);
}

.suggestion-suggested {
    flex: 1;
    font-weight: 700;
    color: var(--booking-success);
}

.suggestion-action {
    flex: 1;
    text-align: right;
}

.suggestion-btn {
    padding: 6px 12px;
    border: 1px solid var(--booking-blue);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-blue);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.suggestion-btn:hover {
    background: var(--booking-blue);
    color: white;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--booking-gray);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--booking-danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--booking-gray);
    position: sticky;
    bottom: 0;
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--booking-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.radio-group {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Days of week */
.days-of-week {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
    margin: 10px 0;
}

.day-checkbox {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.day-checkbox:hover {
    border-color: var(--booking-blue);
}

.day-checkbox input {
    margin-bottom: 4px;
}

/* Room selector */
.room-selector {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    padding: 12px;
}

.room-selector label {
    display: block;
    padding: 8px;
    cursor: pointer;
}

.room-selector label:hover {
    background: var(--booking-light-blue);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .rooms-grid,
    .seasons-grid,
    .offers-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .property-selector select {
        min-width: 100%;
    }
    
    .rates-tabs {
        flex-direction: column;
    }
    
    .rates-tab {
        width: 100%;
        text-align: left;
    }
    
    .distribution-bars {
        height: 150px;
    }
}
</style>

<div class="rates-header">
    <div class="rates-title">
        <h1>Rates & Pricing</h1>
        <p>Manage room rates, seasonal pricing, and special offers</p>
    </div>
    <div>
        <button class="btn-primary" onclick="openSeasonModal()">
            <i class="bi bi-flower1"></i> Add Season
        </button>
        <button class="btn-primary" onclick="openOfferModal()">
            <i class="bi bi-tag"></i> Add Offer
        </button>
    </div>
</div>

<!-- Property Selector -->
<div class="property-selector">
    <label for="propertySelect"><i class="bi bi-building"></i> Select Property:</label>
    <select id="propertySelect" onchange="changeProperty(this.value)">
        <option value="">Choose a property</option>
        <?php foreach ($properties as $prop): ?>
        <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
            <?php echo sanitize($prop['stay_name']); ?> (<?php echo sanitize($prop['city'] ?? 'Rwanda'); ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($propertyId > 0 && $propertyName): ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['min_price']); ?></div>
        <div class="stat-label">Minimum Rate</div>
        <div class="stat-footer"><?php echo formatPrice($stats['max_price']); ?> maximum</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo formatPrice($stats['avg_price']); ?></div>
        <div class="stat-label">Average Rate</div>
        <div class="stat-footer">Across <?php echo $stats['total_rooms']; ?> rooms</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['rooms_with_special']; ?></div>
        <div class="stat-label">Rooms with Specials</div>
        <div class="stat-footer"><?php echo count($seasons); ?> active seasons</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo count($offers); ?></div>
        <div class="stat-label">Active Offers</div>
        <div class="stat-footer"><?php echo count(array_filter($offers, fn($o) => $o['is_active'])); ?> currently active</div>
    </div>
</div>

<!-- Price Distribution -->
<div class="price-distribution">
    <h3 class="distribution-title">Price Distribution</h3>
    <div class="distribution-bars">
        <?php 
        $maxCount = max($stats['price_ranges']) ?: 1;
        foreach ($stats['price_ranges'] as $range => $count): 
            $height = ($count / $maxCount) * 180;
        ?>
        <div class="distribution-bar-container">
            <div class="distribution-bar" style="height: <?php echo $height; ?>px;"></div>
            <div class="distribution-label"><?php echo $range; ?></div>
            <div class="distribution-label" style="font-weight: 600;"><?php echo $count; ?> rooms</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tabs -->
<div class="rates-tabs">
    <button class="rates-tab <?php echo $view == 'rooms' ? 'active' : ''; ?>" onclick="switchView('rooms')">
        Room Rates
    </button>
    <button class="rates-tab <?php echo $view == 'seasons' ? 'active' : ''; ?>" onclick="switchView('seasons')">
        Seasonal Pricing
    </button>
    <button class="rates-tab <?php echo $view == 'offers' ? 'active' : ''; ?>" onclick="switchView('offers')">
        Special Offers
    </button>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<!-- ROOM RATES VIEW -->
<div id="view-rooms" style="display: <?php echo $view == 'rooms' ? 'block' : 'none'; ?>;">
    <!-- Bulk Actions Bar -->
    <div style="background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border); padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
        <span style="font-weight: 600; font-size: 0.875rem;">Bulk Actions:</span>
        <button class="btn-secondary btn-sm" onclick="openBulkModal()">
            <i class="bi bi-pencil-square"></i> Update Multiple Rooms
        </button>
        <button class="btn-secondary btn-sm" onclick="exportRates()">
            <i class="bi bi-download"></i> Export Rates
        </button>
    </div>
    
    <!-- Rooms Grid -->
    <?php if (empty($rooms)): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-door-open" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px; font-size: 1.125rem;">No rooms found</h3>
        <p style="color: var(--booking-text-light); margin-top: 8px;">Add rooms to this property to manage rates.</p>
    </div>
    <?php else: ?>
    <div class="rooms-grid">
        <?php foreach ($rooms as $room): ?>
        <div class="room-card">
            <div class="room-header">
                <span class="room-name"><?php echo sanitize($room['room_name']); ?></span>
                <?php if ($room['special_price_days'] > 0): ?>
                <span class="room-badge">Special rates</span>
                <?php endif; ?>
            </div>
            
            <div class="room-body">
                <div class="room-price-section">
                    <div>
                        <span class="current-price"><?php echo formatPrice($room['base_price']); ?></span>
                        <small>/night</small>
                    </div>
                    <button class="btn-outline btn-sm" onclick="editRoomPrice(<?php echo $room['room_id']; ?>, <?php echo $room['base_price']; ?>, '<?php echo $room['room_name']; ?>')">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                </div>
                
                <div class="price-stats">
                    <div class="price-stat-item">
                        <div class="price-stat-label">Max Guests</div>
                        <div class="price-stat-value"><?php echo $room['max_guests']; ?></div>
                    </div>
                    <div class="price-stat-item">
                        <div class="price-stat-label">Quantity</div>
                        <div class="price-stat-value"><?php echo $room['num_rooms_available']; ?></div>
                    </div>
                </div>
                
                <div class="room-actions">
                    <a href="calendar.php?property=<?php echo $propertyId; ?>" class="room-action-btn">
                        <i class="bi bi-calendar-week"></i> Calendar
                    </a>
                    <button class="room-action-btn success" onclick="setSpecialPrice(<?php echo $room['room_id']; ?>)">
                        <i class="bi bi-tag"></i> Special Price
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Dynamic Pricing Suggestions -->
    <?php if (!empty($suggestions)): ?>
    <div class="suggestions-section">
        <h3 class="suggestions-title">
            <i class="bi bi-lightbulb text-warning"></i>
            Dynamic Pricing Suggestions
        </h3>
        
        <?php foreach ($suggestions as $suggestion): 
            $suggestedPrice = $suggestion['avg_selling_price'] ?: $suggestion['base_price'] * 1.1;
            $diff = $suggestedPrice - $suggestion['base_price'];
            $percentChange = $suggestion['base_price'] > 0 ? round(($diff / $suggestion['base_price']) * 100, 1) : 0;
        ?>
        <div class="suggestion-item">
            <div class="suggestion-room"><?php echo sanitize($suggestion['room_name']); ?></div>
            <div class="suggestion-current">Current: <?php echo formatPrice($suggestion['base_price']); ?></div>
            <div class="suggestion-suggested">
                Suggested: <?php echo formatPrice($suggestedPrice); ?>
                <?php if ($diff != 0): ?>
                <span style="color: <?php echo $diff > 0 ? 'var(--booking-success)' : 'var(--booking-danger)'; ?>; font-size: 0.6875rem;">
                    (<?php echo $diff > 0 ? '+' : ''; ?><?php echo $percentChange; ?>%)
                </span>
                <?php endif; ?>
            </div>
            <div class="suggestion-action">
                <button class="suggestion-btn" onclick="applySuggestion(<?php echo $suggestion['room_id']; ?>, <?php echo $suggestedPrice; ?>)">
                    Apply
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- SEASONS VIEW -->
<div id="view-seasons" style="display: <?php echo $view == 'seasons' ? 'block' : 'none'; ?>;">
    <?php if (empty($seasons)): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-flower1" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px; font-size: 1.125rem;">No seasons defined</h3>
        <p style="color: var(--booking-text-light); margin-top: 8px;">Create seasonal pricing to automatically adjust rates.</p>
        <button class="btn-primary" onclick="openSeasonModal()" style="margin-top: 16px;">
            <i class="bi bi-plus-lg"></i> Add Season
        </button>
    </div>
    <?php else: ?>
    <div class="seasons-grid">
        <?php foreach ($seasons as $season): ?>
        <div class="season-card">
            <div class="season-header">
                <span class="season-name"><?php echo sanitize($season['season_name']); ?></span>
                <span class="season-badge"><?php echo $season['is_recurring'] ? 'Recurring' : 'One-time'; ?></span>
            </div>
            <div class="season-dates">
                <i class="bi bi-calendar3"></i>
                <?php echo date('M d, Y', strtotime($season['start_date'])); ?> - 
                <?php echo date('M d, Y', strtotime($season['end_date'])); ?>
            </div>
            <div class="season-multiplier">
                <?php echo $season['price_multiplier']; ?>x <small>multiplier</small>
            </div>
            <div style="font-size: 0.75rem; color: var(--booking-text-light);">
                Applies to: All rooms
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- OFFERS VIEW -->
<div id="view-offers" style="display: <?php echo $view == 'offers' ? 'block' : 'none'; ?>;">
    <?php if (empty($offers)): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-tag" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px; font-size: 1.125rem;">No special offers</h3>
        <p style="color: var(--booking-text-light); margin-top: 8px;">Create promotions to attract more bookings.</p>
        <button class="btn-primary" onclick="openOfferModal()" style="margin-top: 16px;">
            <i class="bi bi-plus-lg"></i> Add Offer
        </button>
    </div>
    <?php else: ?>
    <div class="offers-grid">
        <?php foreach ($offers as $offer): 
            $isActive = $offer['is_active'];
        ?>
        <div class="offer-card <?php echo $isActive ? '' : 'inactive'; ?>">
            <div class="offer-header">
                <span class="offer-name"><?php echo sanitize($offer['offer_name']); ?></span>
                <span class="offer-status <?php echo $isActive ? 'active' : 'inactive'; ?>">
                    <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                </span>
            </div>
            
            <div class="offer-body">
                <div class="offer-discount">
                    <?php if ($offer['offer_type'] == 'percentage'): ?>
                        <?php echo $offer['discount_value']; ?>% OFF
                    <?php elseif ($offer['offer_type'] == 'fixed'): ?>
                        <?php echo formatPrice($offer['discount_value']); ?> OFF
                    <?php else: ?>
                        Free Night
                    <?php endif; ?>
                    <small>discount</small>
                </div>
                
                <div class="offer-details">
                    <div class="offer-detail-item">
                        <div class="offer-detail-label">Valid</div>
                        <div class="offer-detail-value">
                            <?php echo date('M d', strtotime($offer['start_date'])); ?> - 
                            <?php echo date('M d', strtotime($offer['end_date'])); ?>
                        </div>
                    </div>
                    <div class="offer-detail-item">
                        <div class="offer-detail-label">Min Nights</div>
                        <div class="offer-detail-value"><?php echo $offer['min_nights']; ?></div>
                    </div>
                </div>
                
                <div class="offer-properties">
                    <i class="bi bi-building"></i>
                    Applies to: <?php echo sanitize($offer['stay_name'] ?? 'All properties'); ?>
                </div>
                
                <div class="offer-actions">
                    <form method="POST" style="display: inline; flex: 1;">
                        <input type="hidden" name="offer_id" value="<?php echo $offer['offer_id']; ?>">
                        <input type="hidden" name="current_status" value="<?php echo $offer['is_active']; ?>">
                        <button type="submit" name="toggle_offer" class="offer-action-btn warning">
                            <i class="bi bi-<?php echo $isActive ? 'pause-circle' : 'play-circle'; ?>"></i>
                            <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>
                    <form method="POST" style="display: inline; flex: 1;">
                        <input type="hidden" name="offer_id" value="<?php echo $offer['offer_id']; ?>">
                        <button type="submit" name="delete_offer" class="offer-action-btn danger" onclick="return confirm('Delete this offer?')">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
    <i class="bi bi-cash-stack" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
    <h3 style="margin-top: 16px; font-size: 1.125rem;">Select a property</h3>
    <p style="color: var(--booking-text-light); margin-top: 8px;">Please select a property from the dropdown above to manage its rates.</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Edit Room Price Modal -->
<div class="modal" id="priceModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Update Room Price</h3>
            <button class="modal-close" onclick="closeModal('priceModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="room_id" id="price_room_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Room</label>
                    <div id="price_room_name" style="font-weight: 600; margin-bottom: 16px;"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Base Price (RWF) <span class="required">*</span></label>
                    <input type="number" name="base_price" id="price_value" class="form-control" min="0" step="1000" required>
                </div>
                
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <i class="bi bi-info-circle"></i>
                    <small>This will update the base price for this room. Special prices in calendar will remain unchanged.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('priceModal')">Cancel</button>
                <button type="submit" name="update_base_price" class="btn-primary">Update Price</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Update Modal -->
<div class="modal" id="bulkModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bulk Update Room Rates</h3>
            <button class="modal-close" onclick="closeModal('bulkModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Select Rooms</label>
                    <div class="room-selector">
                        <?php foreach ($rooms as $room): ?>
                        <label>
                            <input type="checkbox" name="room_ids[]" value="<?php echo $room['room_id']; ?>" checked>
                            <?php echo sanitize($room['room_name']); ?> (<?php echo formatPrice($room['base_price']); ?>/night)
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top: 8px;">
                        <button type="button" class="btn-link" onclick="selectAllRooms(true)">Select All</button>
                        <button type="button" class="btn-link" onclick="selectAllRooms(false)">Deselect All</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Action</label>
                    <select name="bulk_action" class="form-control" onchange="toggleBulkPriceField(this.value)">
                        <option value="increase_percent">Increase by percentage (%)</option>
                        <option value="decrease_percent">Decrease by percentage (%)</option>
                        <option value="set_fixed">Set fixed price (RWF)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" id="bulk_label">Percentage (%)</label>
                    <input type="number" name="bulk_value" id="bulk_value" class="form-control" step="1" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" name="bulk_update_prices" class="btn-primary">Apply Updates</button>
            </div>
        </form>
    </div>
</div>

<!-- Season Modal -->
<div class="modal" id="seasonModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Seasonal Pricing</h3>
            <button class="modal-close" onclick="closeModal('seasonModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Season Name <span class="required">*</span></label>
                    <input type="text" name="season_name" class="form-control" placeholder="e.g., High Season, Christmas, Low Season" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Price Multiplier <span class="required">*</span></label>
                    <input type="number" name="price_multiplier" class="form-control" value="1.2" min="0.5" max="3" step="0.1" required>
                    <div class="form-text">1.0 = normal price, 1.2 = 20% increase, 0.8 = 20% discount</div>
                </div>
                
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="apply_to_all" checked>
                        Apply to all rooms
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('seasonModal')">Cancel</button>
                <button type="submit" name="create_season" class="btn-primary">Create Season</button>
            </div>
        </form>
    </div>
</div>

<!-- Offer Modal -->
<div class="modal" id="offerModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create Special Offer</h3>
            <button class="modal-close" onclick="closeModal('offerModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Offer Name <span class="required">*</span></label>
                    <input type="text" name="offer_name" class="form-control" placeholder="e.g., Early Bird, Last Minute, Summer Special" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Offer Type</label>
                        <select name="offer_type" class="form-control" onchange="toggleOfferType(this.value)">
                            <option value="percentage">Percentage Discount</option>
                            <option value="fixed">Fixed Amount Off</option>
                            <option value="night_free">Free Night</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" id="offer_value_label">Discount Value (%)</label>
                        <input type="number" name="discount_value" id="offer_value" class="form-control" min="0" step="1" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="offer_start_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="offer_end_date" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Minimum Nights</label>
                    <input type="number" name="min_nights" class="form-control" value="1" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to specific days</label>
                    <div class="days-of-week">
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="monday"> Mon
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="tuesday"> Tue
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="wednesday"> Wed
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="thursday"> Thu
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="friday"> Fri
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="saturday"> Sat
                        </label>
                        <label class="day-checkbox">
                            <input type="checkbox" name="days_of_week[]" value="sunday"> Sun
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Apply to rooms</label>
                    <div class="room-selector">
                        <?php foreach ($rooms as $room): ?>
                        <label>
                            <input type="checkbox" name="offer_rooms[]" value="<?php echo $room['room_id']; ?>" checked>
                            <?php echo sanitize($room['room_name']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('offerModal')">Cancel</button>
                <button type="submit" name="create_offer" class="btn-primary">Create Offer</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// VIEW SWITCHING
// ============================================
function switchView(view) {
    window.location.href = 'rates.php?property=<?php echo $propertyId; ?>&view=' + view;
}

// ============================================
// PROPERTY SELECTION
// ============================================
function changeProperty(propertyId) {
    if (propertyId) {
        window.location.href = 'rates.php?property=' + propertyId + '&view=<?php echo $view; ?>';
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// ROOM PRICE MODAL
// ============================================
function editRoomPrice(roomId, currentPrice, roomName) {
    document.getElementById('price_room_id').value = roomId;
    document.getElementById('price_value').value = currentPrice;
    document.getElementById('price_room_name').textContent = roomName;
    openModal('priceModal');
}

// ============================================
// BULK MODAL
// ============================================
function openBulkModal() {
    openModal('bulkModal');
}

function toggleBulkPriceField(action) {
    const label = document.getElementById('bulk_label');
    if (action === 'set_fixed') {
        label.textContent = 'Fixed Price (RWF)';
    } else {
        label.textContent = 'Percentage (%)';
    }
}

function selectAllRooms(select) {
    document.querySelectorAll('.room-selector input[type="checkbox"]').forEach(cb => {
        cb.checked = select;
    });
}

// ============================================
// SEASON MODAL
// ============================================
function openSeasonModal() {
    openModal('seasonModal');
}

// ============================================
// OFFER MODAL
// ============================================
function openOfferModal() {
    openModal('offerModal');
}

function toggleOfferType(type) {
    const label = document.getElementById('offer_value_label');
    const input = document.getElementById('offer_value');
    
    if (type === 'percentage') {
        label.textContent = 'Discount Value (%)';
        input.step = '1';
        input.max = '100';
    } else if (type === 'fixed') {
        label.textContent = 'Discount Amount (RWF)';
        input.step = '1000';
        input.max = '';
    } else if (type === 'night_free') {
        label.textContent = 'Number of Free Nights';
        input.step = '1';
        input.max = '5';
    }
}

// ============================================
// SPECIAL PRICE
// ============================================
function setSpecialPrice(roomId) {
    // This would open the calendar with this room selected
    window.location.href = 'calendar.php?property=<?php echo $propertyId; ?>';
}

// ============================================
// APPLY SUGGESTION
// ============================================
function applySuggestion(roomId, suggestedPrice) {
    if (confirm(`Apply suggested price of ${formatCurrency(suggestedPrice)}?`)) {
        document.getElementById('price_room_id').value = roomId;
        document.getElementById('price_value').value = suggestedPrice;
        document.getElementById('price_room_name').textContent = 'Updating...';
        
        // Submit the form
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="room_id" value="${roomId}">
            <input type="hidden" name="base_price" value="${suggestedPrice}">
            <input type="hidden" name="update_base_price" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// ============================================
// EXPORT
// ============================================
function exportRates() {
    // Create CSV content
    let csv = "Room Name,Base Price,Max Guests,Quantity,Size,Special Price Days\n";
    
    <?php foreach ($rooms as $room): ?>
    csv += "<?php echo $room['room_name']; ?>,<?php echo $room['base_price']; ?>,<?php echo $room['max_guests']; ?>,<?php echo $room['num_rooms_available']; ?>,<?php echo $room['size_sqm']; ?>,<?php echo $room['special_price_days']; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'rates_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Helper function for currency formatting
function formatCurrency(amount) {
    return 'RWF ' + new Intl.NumberFormat('rw-RW').format(Math.round(amount));
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>