<?php
$pageTitle = 'Review Management';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$dateFrom = isset($_GET['from']) ? $_GET['from'] : '';
$dateTo = isset($_GET['to']) ? $_GET['to'] : '';

// ============================================
// HANDLE REVIEW ACTIONS
// ============================================

// Submit response to review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $reviewId = intval($_POST['review_id']);
    $response = sanitize($_POST['response']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT r.review_id FROM reviews r
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
        LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
        WHERE r.review_id = ? 
        AND (s.owner_id = ? OR cr.owner_id = ? OR a.owner_id = ?)
    ");
    $stmt->execute([$reviewId, $userId, $userId, $userId]);
    
    if ($stmt->fetch()) {
        // In a real implementation, you'd have a responses table
        // For now, we'll simulate by updating the review with a response field
        // You'll need to add a `response` column to your reviews table
        $stmt = $db->prepare("UPDATE reviews SET response = ?, response_date = NOW() WHERE review_id = ?");
        $stmt->execute([$response, $reviewId]);
        $success = "Response submitted successfully!";
    }
}

// Report a review (to admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_review'])) {
    $reviewId = intval($_POST['review_id']);
    $reason = sanitize($_POST['report_reason']);
    
    // Log report for admin review
    $stmt = $db->prepare("
        INSERT INTO review_reports (review_id, reported_by, reason, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$reviewId, $userId, $reason]);
    $success = "Review reported to administrators. Thank you for your feedback.";
}

// Toggle review visibility (hide/show)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_visibility'])) {
    $reviewId = intval($_POST['review_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE reviews r
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
        LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
        SET r.is_active = ?
        WHERE r.review_id = ? 
        AND (s.owner_id = ? OR cr.owner_id = ? OR a.owner_id = ?)
    ");
    $stmt->execute([$newStatus, $reviewId, $userId, $userId, $userId]);
    
    $success = "Review visibility updated!";
}

// ============================================
// GET REVIEWS DATA
// ============================================

// Get all properties for filter
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Build query conditions
$conditions = [];
$params = [];

// Base condition: reviews belonging to user's properties
$conditions[] = "(s.owner_id = ? OR cr.owner_id = ? OR a.owner_id = ?)";
$params[] = $userId;
$params[] = $userId;
$params[] = $userId;

if ($propertyId > 0) {
    $conditions[] = "(s.stay_id = ? OR cr.rental_id IN (SELECT rental_id FROM car_rentals WHERE stay_id = ?) OR a.attraction_id IN (SELECT attraction_id FROM attractions WHERE stay_id = ?))";
    $params[] = $propertyId;
    $params[] = $propertyId;
    $params[] = $propertyId;
}

if ($rating > 0) {
    $conditions[] = "r.overall_rating >= ?";
    $params[] = $rating;
}

// Since we don't have response field, we'll handle these differently or remove them
if ($status === 'responded') {
    // You might want to implement this later with a responses table
    // For now, just return no results or handle differently
    $conditions[] = "1=0"; // No results for responded
} elseif ($status === 'pending') {
    // All reviews are considered "pending" for response
    $conditions[] = "1=1"; // All reviews
} elseif ($status === 'hidden') {
    $conditions[] = "r.is_active = 0";
} elseif ($status === 'visible') {
    $conditions[] = "r.is_active = 1";
}

if ($search) {
    $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR r.comment LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($dateFrom) {
    $conditions[] = "DATE(r.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $conditions[] = "DATE(r.created_at) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $conditions);

// Get reviews
$stmt = $db->prepare("
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image,
        CASE 
            WHEN r.stay_id IS NOT NULL THEN s.stay_name
            WHEN r.rental_id IS NOT NULL THEN cr.company_name
            WHEN r.attraction_id IS NOT NULL THEN a.attraction_name
        END as item_name,
        CASE 
            WHEN r.stay_id IS NOT NULL THEN 'stay'
            WHEN r.rental_id IS NOT NULL THEN 'car'
            WHEN r.attraction_id IS NOT NULL THEN 'attraction'
        END as item_type,
        s.stay_id,
        s.stay_name as property_name,
        s.city,
        DATEDIFF(NOW(), r.created_at) as days_ago
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
    LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE $whereClause
    ORDER BY r.created_at DESC
");

$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => count($reviews),
    'average' => 0,
    '5star' => 0,
    '4star' => 0,
    '3star' => 0,
    '2star' => 0,
    '1star' => 0,
    'responded' => 0,
    'pending' => 0,
    'with_comment' => 0,
    'recommend' => 0
];

$totalRating = 0;
foreach ($reviews as $review) {
    $totalRating += $review['overall_rating'];
    
    if ($review['overall_rating'] >= 9) $stats['5star']++;
    elseif ($review['overall_rating'] >= 7) $stats['4star']++;
    elseif ($review['overall_rating'] >= 5) $stats['3star']++;
    elseif ($review['overall_rating'] >= 3) $stats['2star']++;
    else $stats['1star']++;
    
// Since we don't have a response field, all reviews are considered "pending" in terms of response
// You can implement a separate responses table later if needed
$stats['responded'] = 0; // You can set this to 0 or implement a different logic
$stats['pending'] = $stats['total'];
    
    if (!empty($review['comment'])) $stats['with_comment']++;
    if ($review['would_recommend']) $stats['recommend']++;
}

$stats['average'] = $stats['total'] > 0 ? round($totalRating / $stats['total'], 1) : 0;

// Get recent response trends (last 30 days)
$stmt = $db->prepare("
    SELECT 
        DATE(r.created_at) as date,
        COUNT(*) as count,
        AVG(r.overall_rating) as avg_rating
    FROM reviews r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
    LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE (s.owner_id = ? OR cr.owner_id = ? OR a.owner_id = ?)
    AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(r.created_at)
    ORDER BY date
");
$stmt->execute([$userId, $userId, $userId]);
$trendData = $stmt->fetchAll();

// Get rating distribution by category
$categoryRatings = [
    'overall' => $stats['average'],
    'cleanliness' => 0,
    'service' => 0,
    'location' => 0,
    'value' => 0
];

if (!empty($reviews)) {
    $totalClean = 0;
    $totalService = 0;
    $totalLocation = 0;
    $totalValue = 0;
    $count = 0;
    
    foreach ($reviews as $review) {
        $cats = json_decode($review['categories'] ?? '{}', true);
        if (isset($cats['cleanliness'])) {
            $totalClean += $cats['cleanliness'];
            $totalService += $cats['service'] ?? 0;
            $totalLocation += $cats['location'] ?? 0;
            $totalValue += $cats['value'] ?? 0;
            $count++;
        }
    }
    
    if ($count > 0) {
        $categoryRatings['cleanliness'] = round($totalClean / $count, 1);
        $categoryRatings['service'] = round($totalService / $count, 1);
        $categoryRatings['location'] = round($totalLocation / $count, 1);
        $categoryRatings['value'] = round($totalValue / $count, 1);
    }
}

// Get properties with their average ratings
$propertyRatings = [];
$stmt = $db->prepare("
    SELECT 
        s.stay_id,
        s.stay_name,
        s.city,
        s.main_image,
        AVG(r.overall_rating) as avg_rating,
        COUNT(r.review_id) as review_count
    FROM stays s
    LEFT JOIN reviews r ON s.stay_id = r.stay_id
    WHERE s.owner_id = ?
    GROUP BY s.stay_id
    ORDER BY avg_rating DESC
");
$stmt->execute([$userId]);
$propertyRatings = $stmt->fetchAll();
?>

<style>
/* Reviews Management Specific Styles */
.reviews-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.reviews-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.reviews-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
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
    display: flex;
    justify-content: space-between;
}

/* Rating Distribution */
.rating-distribution {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.distribution-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.distribution-title {
    font-size: 1rem;
    font-weight: 700;
}

.overall-rating {
    display: flex;
    align-items: center;
    gap: 16px;
}

.overall-score {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--booking-blue);
}

.rating-bars {
    flex: 1;
}

.rating-bar-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
}

.rating-bar-label {
    min-width: 30px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.rating-bar-bg {
    flex: 1;
    height: 8px;
    background: var(--booking-gray);
    border-radius: 4px;
    overflow: hidden;
}

.rating-bar-fill {
    height: 100%;
    background: var(--booking-blue);
    border-radius: 4px;
}

.rating-bar-count {
    min-width: 30px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
    text-align: right;
}

.category-ratings {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--booking-border);
}

.category-item {
    text-align: center;
}

.category-label {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.category-score {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    min-width: 150px;
    flex: 1;
}

.filter-label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.filter-select, .filter-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.search-box {
    position: relative;
    flex: 2;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--booking-text-light);
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

/* Reviews List */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 30px;
}

.review-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
}

.review-card:hover {
    box-shadow: var(--shadow-md);
}

.review-card.hidden {
    opacity: 0.7;
    background: var(--booking-gray);
}

.review-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8f9fa, white);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.reviewer-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.reviewer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
}

.reviewer-details h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.reviewer-details p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin: 0;
}

.review-meta {
    display: flex;
    align-items: center;
    gap: 16px;
}

.review-rating {
    display: flex;
    align-items: center;
    gap: 8px;
}

.rating-badge {
    background: var(--booking-dark);
    color: white;
    padding: 6px 12px;
    border-radius: var(--radius-sm) var(--radius-sm) var(--radius-sm) 0;
    font-weight: 700;
    font-size: 1rem;
}

.rating-stars {
    color: var(--warning-orange);
    font-size: 0.875rem;
}

.review-property {
    font-size: 0.875rem;
    color: var(--booking-text-light);
}

.review-property i {
    color: var(--booking-blue);
    margin-right: 4px;
}

.review-body {
    padding: 20px;
}

.review-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--booking-text);
}

.review-text {
    font-size: 0.9375rem;
    line-height: 1.6;
    color: var(--booking-text);
    margin-bottom: 16px;
}

.review-date {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    display: flex;
    align-items: center;
    gap: 16px;
}

.review-date i {
    margin-right: 4px;
}

.review-categories {
    display: flex;
    gap: 20px;
    margin: 16px 0;
    padding: 12px 0;
    border-top: 1px solid var(--booking-border);
    border-bottom: 1px solid var(--booking-border);
}

.category-score-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8125rem;
}

.category-score-item i {
    color: var(--booking-blue);
}

.review-response {
    background: var(--booking-light-blue);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-top: 16px;
    position: relative;
}

.response-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-blue);
}

.response-text {
    font-size: 0.875rem;
    line-height: 1.6;
    margin-bottom: 8px;
}

.response-date {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.review-actions {
    padding: 16px 20px;
    background: var(--booking-gray);
    border-top: 1px solid var(--booking-border);
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 8px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.action-btn.respond {
    background: var(--booking-success);
    color: white;
    border-color: var(--booking-success);
}

.action-btn.respond:hover {
    background: #006600;
}

.action-btn.warning:hover {
    background: #fff4e6;
    border-color: var(--booking-warning);
    color: var(--booking-warning);
}

.action-btn.danger:hover {
    background: #fce8e8;
    border-color: var(--booking-danger);
    color: var(--booking-danger);
}

/* Property Ratings Grid */
.property-ratings {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 30px;
}

.property-rating-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.property-rating-image {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-md);
    object-fit: cover;
}

.property-rating-info {
    flex: 1;
}

.property-rating-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.property-rating-stats {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.property-rating-score {
    background: var(--booking-blue);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 700;
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
.form-group {
    margin-bottom: 16px;
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

textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

/* Export Button */
.btn-export {
    background: white;
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-export:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--booking-text-light);
    margin-bottom: 20px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .category-ratings {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .category-ratings,
    .property-ratings {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
    }
    
    .filter-group,
    .search-box {
        width: 100%;
    }
    
    .review-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .review-meta {
        width: 100%;
        justify-content: space-between;
    }
}
</style>

<div class="reviews-header">
    <div class="reviews-title">
        <h1>Review Management</h1>
        <p>Monitor and respond to guest reviews across all your properties</p>
    </div>
    <div>
        <button class="btn-export" onclick="exportReviews()">
            <i class="bi bi-download"></i> Export Reviews
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Reviews</div>
        <div class="stat-footer">
            <span>Avg: <?php echo $stats['average']; ?></span>
            <span>⭐ <?php echo $stats['5star']; ?> five-star</span>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['average']; ?></div>
        <div class="stat-label">Overall Rating</div>
        <div class="stat-footer">
            <span><?php echo $stats['recommend']; ?> would recommend</span>
        </div>
    </div>
    
<div class="stat-card">
    <div class="stat-value"><?php echo $stats['total']; ?></div>
    <div class="stat-label">Total Reviews</div>
    <div class="stat-footer">
        <span>Response feature coming soon</span>
    </div>
</div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['with_comment']; ?></div>
        <div class="stat-label">Reviews with Comments</div>
        <div class="stat-footer">
            <span><?php echo round(($stats['with_comment'] / max(1, $stats['total'])) * 100); ?>% of total</span>
        </div>
    </div>
</div>

<!-- Rating Distribution -->
<div class="rating-distribution">
    <div class="distribution-header">
        <h3 class="distribution-title">Rating Distribution</h3>
        <div class="overall-rating">
            <span class="overall-score"><?php echo $stats['average']; ?></span>
            <div>
                <div style="color: var(--warning-orange);">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill<?php echo $i * 2 <= $stats['average'] ? '' : '-empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <div style="font-size: 0.75rem; color: var(--booking-text-light);">out of 10</div>
            </div>
        </div>
    </div>
    
    <div class="rating-bars">
        <?php 
        $ratings = [
            '5' => $stats['5star'],
            '4' => $stats['4star'],
            '3' => $stats['3star'],
            '2' => $stats['2star'],
            '1' => $stats['1star']
        ];
        $maxCount = max($ratings) ?: 1;
        foreach ($ratings as $star => $count): 
            $percentage = $stats['total'] > 0 ? round(($count / $stats['total']) * 100) : 0;
        ?>
        <div class="rating-bar-item">
            <span class="rating-bar-label"><?php echo $star; ?>★</span>
            <div class="rating-bar-bg">
                <div class="rating-bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
            </div>
            <span class="rating-bar-count"><?php echo $count; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="category-ratings">
        <div class="category-item">
            <div class="category-label">Cleanliness</div>
            <div class="category-score"><?php echo $categoryRatings['cleanliness']; ?></div>
        </div>
        <div class="category-item">
            <div class="category-label">Service</div>
            <div class="category-score"><?php echo $categoryRatings['service']; ?></div>
        </div>
        <div class="category-item">
            <div class="category-label">Location</div>
            <div class="category-score"><?php echo $categoryRatings['location']; ?></div>
        </div>
        <div class="category-item">
            <div class="category-label">Value</div>
            <div class="category-score"><?php echo $categoryRatings['value']; ?></div>
        </div>
    </div>
</div>

<!-- Property Ratings -->
<?php if (!empty($propertyRatings)): ?>
<div style="margin-bottom: 24px;">
    <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 16px;">Property Ratings</h3>
    <div class="property-ratings">
        <?php foreach ($propertyRatings as $prop): ?>
        <div class="property-rating-card">
            <img src="<?php echo getImageUrl($prop['main_image'] ?? '', 'stay'); ?>" class="property-rating-image">
            <div class="property-rating-info">
                <div class="property-rating-name"><?php echo sanitize($prop['stay_name']); ?></div>
                <div class="property-rating-stats">
                    <span class="property-rating-score"><?php echo number_format($prop['avg_rating'] ?? 0, 1); ?></span>
                    <span><?php echo $prop['review_count']; ?> reviews</span>
                </div>
<div style="font-size: 0.7rem; color: var(--booking-text-light); margin-top: 4px;">
    <?php echo $prop['responded_count'] ?? 0; ?> responses
</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form class="filter-bar" method="GET" action="reviews.php">
    <div class="filter-group">
        <span class="filter-label">Property</span>
        <select name="property" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Properties</option>
            <?php foreach ($properties as $prop): ?>
            <option value="<?php echo $prop['stay_id']; ?>" <?php echo $propertyId == $prop['stay_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($prop['stay_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <span class="filter-label">Rating</span>
        <select name="rating" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Ratings</option>
            <option value="9" <?php echo $rating == 9 ? 'selected' : ''; ?>>9+ (Exceptional)</option>
            <option value="8" <?php echo $rating == 8 ? 'selected' : ''; ?>>8+ (Excellent)</option>
            <option value="7" <?php echo $rating == 7 ? 'selected' : ''; ?>>7+ (Very Good)</option>
            <option value="6" <?php echo $rating == 6 ? 'selected' : ''; ?>>6+ (Good)</option>
            <option value="5" <?php echo $rating == 5 ? 'selected' : ''; ?>>5+ (Pleasant)</option>
        </select>
    </div>
    
    <div class="filter-group">
        <span class="filter-label">Status</span>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all">All Reviews</option>
            <option value="responded" <?php echo $status == 'responded' ? 'selected' : ''; ?>>Responded</option>
            <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending Response</option>
            <option value="visible" <?php echo $status == 'visible' ? 'selected' : ''; ?>>Visible</option>
            <option value="hidden" <?php echo $status == 'hidden' ? 'selected' : ''; ?>>Hidden</option>
        </select>
    </div>
    
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" name="search" placeholder="Search reviews or guests..." value="<?php echo sanitize($search); ?>">
    </div>
    
    <?php if ($propertyId || $rating || $status != 'all' || $search): ?>
    <a href="reviews.php" class="action-btn">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<!-- Reviews List -->
<?php if (empty($reviews)): ?>
<div class="empty-state">
    <i class="bi bi-star"></i>
    <h3>No reviews found</h3>
    <p>There are no reviews matching your criteria.</p>
    <?php if ($propertyId || $rating || $status != 'all' || $search): ?>
    <a href="reviews.php" class="btn-primary">Clear Filters</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="reviews-list">
    <?php foreach ($reviews as $review): 
        $reviewClass = $review['is_active'] ? '' : 'hidden';
        $ratingLabel = getReviewLabel($review['overall_rating']);
        $categories = json_decode($review['categories'] ?? '{}', true);
    ?>
    <div class="review-card <?php echo $reviewClass; ?>" id="review-<?php echo $review['review_id']; ?>">
        <div class="review-header">
            <div class="reviewer-info">
                <div class="reviewer-avatar">
                    <?php echo strtoupper(substr($review['first_name'] ?? 'G', 0, 1)); ?>
                </div>
                <div class="reviewer-details">
                    <h4><?php echo sanitize($review['first_name'] . ' ' . substr($review['last_name'] ?? '', 0, 1) . '.'); ?></h4>
                    <p><?php echo sanitize($review['email']); ?></p>
                </div>
            </div>
            
            <div class="review-meta">
                <div class="review-rating">
                    <span class="rating-badge"><?php echo number_format($review['overall_rating'], 1); ?></span>
                    <span class="rating-stars">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <i class="bi bi-star-fill<?php echo $i <= $review['overall_rating'] ? '' : '-empty'; ?>"></i>
                        <?php endfor; ?>
                    </span>
                </div>
                
                <div class="review-property">
                    <i class="bi bi-<?php echo $review['item_type'] == 'stay' ? 'building' : ($review['item_type'] == 'car' ? 'car-front' : 'ticket'); ?>"></i>
                    <?php echo sanitize($review['item_name'] ?: $review['property_name']); ?>
                </div>
            </div>
        </div>
        
        <div class="review-body">
            <?php if ($review['title']): ?>
            <div class="review-title"><?php echo sanitize($review['title']); ?></div>
            <?php endif; ?>
            
            <div class="review-text"><?php echo nl2br(sanitize($review['comment'])); ?></div>
            
            <div class="review-date">
                <span><i class="bi bi-calendar3"></i> <?php echo date('F j, Y', strtotime($review['created_at'])); ?></span>
                <span><i class="bi bi-clock"></i> <?php echo $review['days_ago']; ?> days ago</span>
                <?php if ($review['would_recommend']): ?>
                <span style="color: var(--booking-success);"><i class="bi bi-hand-thumbs-up-fill"></i> Would recommend</span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($categories)): ?>
            <div class="review-categories">
                <?php if (isset($categories['cleanliness'])): ?>
                <span class="category-score-item">
                    <i class="bi bi-brush"></i> Cleanliness: <?php echo $categories['cleanliness']; ?>/10
                </span>
                <?php endif; ?>
                <?php if (isset($categories['service'])): ?>
                <span class="category-score-item">
                    <i class="bi bi-person"></i> Service: <?php echo $categories['service']; ?>/10
                </span>
                <?php endif; ?>
                <?php if (isset($categories['location'])): ?>
                <span class="category-score-item">
                    <i class="bi bi-geo-alt"></i> Location: <?php echo $categories['location']; ?>/10
                </span>
                <?php endif; ?>
                <?php if (isset($categories['value'])): ?>
                <span class="category-score-item">
                    <i class="bi bi-cash"></i> Value: <?php echo $categories['value']; ?>/10
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
<?php
// Since we don't have response field, we'll comment this out or remove it
// You can implement responses later
?>
        </div>
        
        <div class="review-actions">
<button class="action-btn respond" onclick="openResponseModal(<?php echo $review['review_id']; ?>, '<?php echo sanitize($review['first_name']); ?>')">
    <i class="bi bi-chat"></i> Respond
</button>
            
            <form method="POST" style="display: inline;">
                <input type="hidden" name="review_id" value="<?php echo $review['review_id']; ?>">
                <input type="hidden" name="current_status" value="<?php echo $review['is_active']; ?>">
                <button type="submit" name="toggle_visibility" class="action-btn warning" onclick="return confirm('Toggle visibility for this review?')">
                    <i class="bi bi-<?php echo $review['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                    <?php echo $review['is_active'] ? 'Hide' : 'Show'; ?>
                </button>
            </form>
            
            <button class="action-btn" onclick="openReportModal(<?php echo $review['review_id']; ?>)">
                <i class="bi bi-flag"></i> Report
            </button>
            
            <button class="action-btn" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($review)); ?>)">
                <i class="bi bi-info-circle"></i> Details
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Response Modal -->
<div class="modal" id="responseModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Respond to Review</h3>
            <button class="modal-close" onclick="closeModal('responseModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="review_id" id="response_review_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Guest</label>
                    <div id="response_guest_name" style="font-weight: 600; margin-bottom: 16px;"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Your Response</label>
                    <textarea name="response" id="response_text" class="form-control" rows="5" 
                              placeholder="Thank the guest for their feedback and address any concerns..." required></textarea>
                    <div style="font-size: 0.75rem; color: var(--booking-text-light); margin-top: 4px;">
                        <i class="bi bi-info-circle"></i> A thoughtful response shows you care about guest experience.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('responseModal')">Cancel</button>
                <button type="submit" name="submit_response" class="btn-primary">Submit Response</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Modal -->
<div class="modal" id="reportModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Report Review</h3>
            <button class="modal-close" onclick="closeModal('reportModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="review_id" id="report_review_id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Reason for reporting</label>
                    <select name="report_reason" class="form-control" required>
                        <option value="">Select reason</option>
                        <option value="inappropriate">Inappropriate content</option>
                        <option value="fake">Fake review</option>
                        <option value="offensive">Offensive language</option>
                        <option value="conflict">Conflict of interest</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div style="background: var(--booking-gray); padding: 12px; border-radius: var(--radius-sm);">
                    <i class="bi bi-shield-check"></i>
                    <small>This review will be reviewed by our administrators within 24 hours.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('reportModal')">Cancel</button>
                <button type="submit" name="report_review" class="btn-primary" style="background: var(--booking-warning);">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Details Modal -->
<div class="modal" id="detailsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Review Details</h3>
            <button class="modal-close" onclick="closeModal('detailsModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="detailsContent">
            <!-- Content loaded via JavaScript -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<script>
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
// RESPONSE MODAL
// ============================================
function openResponseModal(reviewId, guestName) {
    document.getElementById('response_review_id').value = reviewId;
    document.getElementById('response_guest_name').textContent = guestName;
    document.getElementById('response_text').value = '';
    openModal('responseModal');
}

// ============================================
// REPORT MODAL
// ============================================
function openReportModal(reviewId) {
    document.getElementById('report_review_id').value = reviewId;
    openModal('reportModal');
}

// ============================================
// DETAILS MODAL
// ============================================
function viewDetails(review) {
    const content = document.getElementById('detailsContent');
    
    let categoriesHtml = '';
    if (review.categories) {
        try {
            const cats = JSON.parse(review.categories);
            categoriesHtml = '<div style="margin: 16px 0;"><strong>Category Ratings:</strong><br>';
            if (cats.cleanliness) categoriesHtml += `Cleanliness: ${cats.cleanliness}/10<br>`;
            if (cats.service) categoriesHtml += `Service: ${cats.service}/10<br>`;
            if (cats.location) categoriesHtml += `Location: ${cats.location}/10<br>`;
            if (cats.value) categoriesHtml += `Value: ${cats.value}/10<br>`;
            categoriesHtml += '</div>';
        } catch(e) {}
    }
    
    content.innerHTML = `
        <div style="margin-bottom: 20px;">
            <div style="font-weight: 600; font-size: 1.125rem;">${review.title || 'Review'}</div>
            <div style="color: var(--booking-text-light); margin: 8px 0;">${review.comment}</div>
            ${categoriesHtml}
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--booking-border);">
                <div><strong>Guest:</strong> ${review.first_name} ${review.last_name}</div>
                <div><strong>Email:</strong> ${review.email}</div>
                <div><strong>Property:</strong> ${review.item_name || review.property_name}</div>
                <div><strong>Date:</strong> ${new Date(review.created_at).toLocaleDateString()}</div>
                <div><strong>Would recommend:</strong> ${review.would_recommend ? 'Yes' : 'No'}</div>
                <div><strong>Status:</strong> ${review.is_active ? 'Visible' : 'Hidden'}</div>
            </div>
        </div>
    `;
    
    openModal('detailsModal');
}

// ============================================
// EXPORT FUNCTION
// ============================================
function exportReviews() {
    // Create CSV content
    let csv = "Date,Reviewer,Rating,Title,Comment,Property,Would Recommend,Status\n";
    
    <?php foreach ($reviews as $review): ?>
    csv += "<?php echo date('Y-m-d', strtotime($review['created_at'])); ?>,<?php echo $review['first_name'] . ' ' . $review['last_name']; ?>,<?php echo $review['overall_rating']; ?>,<?php echo str_replace(',', ' ', $review['title'] ?? ''); ?>,<?php echo str_replace(',', ' ', $review['comment'] ?? ''); ?>,<?php echo $review['item_name'] ?: $review['property_name']; ?>,<?php echo $review['would_recommend'] ? 'Yes' : 'No'; ?>,<?php echo $review['is_active'] ? 'Visible' : 'Hidden'; ?>\n";
    <?php endforeach; ?>
    
    // Download CSV
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'reviews_export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>