<?php
$pageTitle = 'Reviews Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle review actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$reviewId = isset($_POST['review_id']) ? intval($_POST['review_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Approve review
if ($action === 'approve' && $reviewId > 0) {
    $stmt = $db->prepare("UPDATE reviews SET is_active = 1, is_verified = 1 WHERE review_id = ?");
    $stmt->execute([$reviewId]);
    $_SESSION['success'] = "Review approved and published successfully";
    header('Location: reviews.php');
    exit;
}

// Hide review
if ($action === 'hide' && $reviewId > 0) {
    $stmt = $db->prepare("UPDATE reviews SET is_active = 0 WHERE review_id = ?");
    $stmt->execute([$reviewId]);
    $_SESSION['success'] = "Review hidden from public view";
    header('Location: reviews.php');
    exit;
}

// Delete review
if ($action === 'delete' && $reviewId > 0) {
    $stmt = $db->prepare("DELETE FROM reviews WHERE review_id = ?");
    $stmt->execute([$reviewId]);
    $_SESSION['success'] = "Review deleted permanently";
    header('Location: reviews.php');
    exit;
}

// Mark as helpful
if ($action === 'helpful' && $reviewId > 0) {
    $stmt = $db->prepare("UPDATE reviews SET helpful_count = helpful_count + 1 WHERE review_id = ?");
    $stmt->execute([$reviewId]);
    $_SESSION['success'] = "Review marked as helpful";
    header('Location: reviews.php');
    exit;
}

// Bulk actions
if ($action === 'bulk_action' && isset($_POST['selected_reviews']) && is_array($_POST['selected_reviews'])) {
    $selectedIds = array_map('intval', $_POST['selected_reviews']);
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $bulkAction = $_POST['bulk_action_type'];
    
    if ($bulkAction === 'approve') {
        $stmt = $db->prepare("UPDATE reviews SET is_active = 1, is_verified = 1 WHERE review_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " reviews approved successfully";
    } elseif ($bulkAction === 'hide') {
        $stmt = $db->prepare("UPDATE reviews SET is_active = 0 WHERE review_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " reviews hidden successfully";
    } elseif ($bulkAction === 'delete') {
        $stmt = $db->prepare("DELETE FROM reviews WHERE review_id IN ($placeholders)");
        $stmt->execute($selectedIds);
        $_SESSION['success'] = count($selectedIds) . " reviews deleted successfully";
    }
    header('Location: reviews.php');
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'created_desc';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$sql = "
    SELECT 
        r.*,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image,
        CASE 
            WHEN r.review_type = 'stay' THEN s.stay_name
            WHEN r.review_type = 'car_rental' THEN cr.company_name
            ELSE a.attraction_name
        END as item_name,
        CASE 
            WHEN r.review_type = 'stay' THEN '🏨'
            WHEN r.review_type = 'car_rental' THEN '🚗'
            ELSE '🎟️'
        END as item_icon,
        CASE 
            WHEN r.review_type = 'stay' THEN s.stay_id
            WHEN r.review_type = 'car_rental' THEN cr.rental_id
            ELSE a.attraction_id
        END as item_id,
        CASE 
            WHEN r.review_type = 'stay' THEN s.main_image
            WHEN r.review_type = 'car_rental' THEN cr.logo
            ELSE a.main_image
        END as item_image,
        COALESCE(b.booking_reference, 'N/A') as booking_reference
    FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
    LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
    LEFT JOIN bookings b ON r.booking_id = b.booking_id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (r.title LIKE ? OR r.comment LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($rating > 0) {
    $sql .= " AND r.overall_rating = ?";
    $params[] = $rating;
}

if ($type !== 'all') {
    $sql .= " AND r.review_type = ?";
    $params[] = $type;
}

if ($status === 'active') {
    $sql .= " AND r.is_active = 1";
} elseif ($status === 'hidden') {
    $sql .= " AND r.is_active = 0";
} elseif ($status === 'pending') {
    $sql .= " AND r.is_verified = 0";
}

if ($dateFrom) {
    $sql .= " AND DATE(r.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $sql .= " AND DATE(r.created_at) <= ?";
    $params[] = $dateTo;
}

// Sorting
switch ($sort) {
    case 'rating_desc':
        $sql .= " ORDER BY r.overall_rating DESC";
        break;
    case 'rating_asc':
        $sql .= " ORDER BY r.overall_rating ASC";
        break;
    case 'helpful_desc':
        $sql .= " ORDER BY r.helpful_count DESC";
        break;
    case 'created_asc':
        $sql .= " ORDER BY r.created_at ASC";
        break;
    default:
        $sql .= " ORDER BY r.created_at DESC";
}

$sql .= " LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) FROM reviews r
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE 1=1
";
$countParams = [];

if ($search) {
    $countSql .= " AND (r.title LIKE ? OR r.comment LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}
if ($rating > 0) {
    $countSql .= " AND r.overall_rating = ?";
    $countParams[] = $rating;
}
if ($type !== 'all') {
    $countSql .= " AND r.review_type = ?";
    $countParams[] = $type;
}
if ($status === 'active') {
    $countSql .= " AND r.is_active = 1";
} elseif ($status === 'hidden') {
    $countSql .= " AND r.is_active = 0";
} elseif ($status === 'pending') {
    $countSql .= " AND r.is_verified = 0";
}
if ($dateFrom) {
    $countSql .= " AND DATE(r.created_at) >= ?";
    $countParams[] = $dateFrom;
}
if ($dateTo) {
    $countSql .= " AND DATE(r.created_at) <= ?";
    $countParams[] = $dateTo;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalReviews = $stmt->fetchColumn() ?: 0;
$totalPages = $totalReviews > 0 ? ceil($totalReviews / $perPage) : 1;

// Get statistics
$stats = $db->query("
    SELECT 
        COALESCE(COUNT(*), 0) as total_reviews,
        COALESCE(AVG(overall_rating), 0) as avg_rating,
        COALESCE(SUM(CASE WHEN overall_rating >= 4 THEN 1 ELSE 0 END), 0) as positive_reviews,
        COALESCE(SUM(CASE WHEN overall_rating = 3 THEN 1 ELSE 0 END), 0) as neutral_reviews,
        COALESCE(SUM(CASE WHEN overall_rating <= 2 THEN 1 ELSE 0 END), 0) as negative_reviews,
        COALESCE(SUM(CASE WHEN review_type = 'stay' THEN 1 ELSE 0 END), 0) as stay_reviews,
        COALESCE(SUM(CASE WHEN review_type = 'car_rental' THEN 1 ELSE 0 END), 0) as car_reviews,
        COALESCE(SUM(CASE WHEN review_type = 'attraction' THEN 1 ELSE 0 END), 0) as attraction_reviews,
        COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as published,
        COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) as hidden,
        COALESCE(SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END), 0) as pending_verification,
        COALESCE(SUM(helpful_count), 0) as total_helpful,
        COALESCE(SUM(CASE WHEN DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) as this_week,
        COALESCE(SUM(CASE WHEN DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) as this_month
    FROM reviews
")->fetch();

// Get rating distribution
$ratingDistribution = $db->query("
    SELECT 
        overall_rating,
        COUNT(*) as count,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM reviews), 1) as percentage
    FROM reviews
    GROUP BY overall_rating
    ORDER BY overall_rating DESC
")->fetchAll();

// Get top reviewed items
$topItems = $db->query("
    SELECT 
        'stay' as type,
        s.stay_id as item_id,
        s.stay_name as item_name,
        COUNT(r.review_id) as review_count,
        AVG(r.overall_rating) as avg_rating
    FROM reviews r
    LEFT JOIN stays s ON r.stay_id = s.stay_id
    WHERE r.review_type = 'stay' AND s.stay_id IS NOT NULL
    GROUP BY s.stay_id
    HAVING review_count >= 2
    ORDER BY review_count DESC
    LIMIT 5
")->fetchAll();

$topCarItems = $db->query("
    SELECT 
        'car' as type,
        cr.rental_id as item_id,
        cr.company_name as item_name,
        COUNT(r.review_id) as review_count,
        AVG(r.overall_rating) as avg_rating
    FROM reviews r
    LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
    WHERE r.review_type = 'car_rental' AND cr.rental_id IS NOT NULL
    GROUP BY cr.rental_id
    HAVING review_count >= 2
    ORDER BY review_count DESC
    LIMIT 5
")->fetchAll();

$topAttractionItems = $db->query("
    SELECT 
        'attraction' as type,
        a.attraction_id as item_id,
        a.attraction_name as item_name,
        COUNT(r.review_id) as review_count,
        AVG(r.overall_rating) as avg_rating
    FROM reviews r
    LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE r.review_type = 'attraction' AND a.attraction_id IS NOT NULL
    GROUP BY a.attraction_id
    HAVING review_count >= 2
    ORDER BY review_count DESC
    LIMIT 5
")->fetchAll();

$topReviewedItems = array_merge($topItems, $topCarItems, $topAttractionItems);
usort($topReviewedItems, function($a, $b) {
    return $b['review_count'] - $a['review_count'];
});
$topReviewedItems = array_slice($topReviewedItems, 0, 5);

// Monthly review trend
$monthlyTrend = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%b %Y') as month,
        DATE_FORMAT(created_at, '%Y-%m') as month_key,
        COUNT(*) as review_count,
        AVG(overall_rating) as avg_rating
    FROM reviews
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
")->fetchAll();

$months = [];
$reviewCounts = [];
$avgRatings = [];
foreach ($monthlyTrend as $data) {
    $months[] = $data['month'];
    $reviewCounts[] = $data['review_count'];
    $avgRatings[] = round($data['avg_rating'], 1);
}
?>

<style>
/* Reviews Management Styles */
.reviews-header {
    margin-bottom: 24px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px;
    text-align: center;
    transition: all var(--transition-fast);
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.stat-card.active {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
}

.stat-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    font-size: 0.875rem;
}

.stat-icon.blue { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.stat-icon.green { background: rgba(0,128,9,0.1); color: var(--booking-success); }
.stat-icon.orange { background: rgba(255,140,0,0.1); color: var(--booking-warning); }
.stat-icon.red { background: rgba(226,17,17,0.1); color: var(--booking-danger); }
.stat-icon.purple { background: rgba(147,51,234,0.1); color: #9333ea; }
.stat-icon.cyan { background: rgba(23,162,184,0.1); color: #17a2b8; }

.stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 2px;
}

/* Rating Distribution */
.rating-distribution {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 24px;
}

.distribution-header {
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.distribution-bars {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.distribution-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.distribution-label {
    width: 30px;
    font-size: 0.6875rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}

.distribution-bar {
    flex: 1;
    height: 8px;
    background: var(--booking-gray-light);
    border-radius: 4px;
    overflow: hidden;
}

.distribution-fill {
    height: 100%;
    background: var(--booking-warning);
    border-radius: 4px;
    transition: width 0.3s;
}

.distribution-count {
    width: 50px;
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-align: right;
}

/* Chart Section */
.chart-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-container {
    height: 250px;
}

/* Top Items */
.top-items-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 24px;
}

.top-items-header {
    font-size: 0.75rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.top-items-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.top-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px;
    border-radius: var(--radius-sm);
    transition: background var(--transition-fast);
}

.top-item:hover {
    background: var(--booking-gray-light);
}

.top-item-rank {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--booking-gray-light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.75rem;
}

.top-item-rank.rank-1 { background: gold; color: #1a1a1a; }
.top-item-rank.rank-2 { background: silver; color: #1a1a1a; }
.top-item-rank.rank-3 { background: #cd7f32; color: white; }

.top-item-info {
    flex: 1;
}

.top-item-name {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.top-item-stats {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.top-item-rating {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Filter Section */
.filter-section {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 120px;
}

.filter-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-bottom: 4px;
}

.filter-group select,
.filter-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-btn {
    padding: 8px 20px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
}

.reset-btn {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

/* Reviews Grid */
.reviews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.review-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    transition: all var(--transition-fast);
}

.review-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.review-header {
    padding: 16px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.review-type {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.type-stay { background: rgba(0,102,255,0.1); color: var(--booking-blue); }
.type-car { background: rgba(147,51,234,0.1); color: #9333ea; }
.type-attraction { background: rgba(255,140,0,0.1); color: var(--booking-warning); }

.review-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.status-active {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-hidden {
    background: #fce8e8;
    color: var(--booking-danger);
}

.status-pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

.review-body {
    padding: 16px;
}

.reviewer-info {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.reviewer-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.reviewer-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.reviewer-details {
    flex: 1;
}

.reviewer-name {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 2px;
}

.review-date {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.review-rating {
    display: flex;
    gap: 2px;
    margin-bottom: 8px;
}

.review-rating i {
    font-size: 0.75rem;
    color: #ffc107;
}

.review-rating i.empty {
    color: #e0e0e0;
}

.review-title {
    font-weight: 700;
    font-size: 0.8125rem;
    margin-bottom: 8px;
}

.review-comment {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    line-height: 1.5;
    margin-bottom: 12px;
}

.review-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-sm);
    margin-bottom: 12px;
}

.review-item-icon {
    font-size: 1rem;
}

.review-item-name {
    font-size: 0.6875rem;
    font-weight: 500;
    flex: 1;
}

.review-helpful {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.helpful-count {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.review-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.action-btn-sm {
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    font-size: 0.5625rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.action-btn-sm.approve {
    background: #e6f4ea;
    color: var(--booking-success);
}

.action-btn-sm.hide {
    background: #fff4e6;
    color: var(--booking-warning);
}

.action-btn-sm.delete {
    background: #fce8e8;
    color: var(--booking-danger);
}

.action-btn-sm.helpful {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.action-btn-sm:hover {
    transform: translateY(-1px);
}

/* Bulk Actions */
.bulk-actions {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
    padding: 12px 16px;
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    display: none;
}

.bulk-actions.show {
    display: flex;
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    color: var(--booking-text);
    text-decoration: none;
    font-size: 0.75rem;
}

.page-link:hover,
.page-link.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
}

/* Responsive */
@media (max-width: 1400px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .reviews-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .filter-row {
        flex-direction: column;
    }
    .filter-group {
        width: 100%;
    }
}
</style>

<div class="reviews-header">
    <div class="page-title">
        <h1></h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card" onclick="filterByStatus('all')">
        <div class="stat-icon blue">
            <i class="bi bi-star"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['total_reviews']); ?></div>
        <div class="stat-label">Total Reviews</div>
    </div>
    <div class="stat-card" onclick="filterByRating(4)">
        <div class="stat-icon green">
            <i class="bi bi-emoji-smile"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['positive_reviews']); ?></div>
        <div class="stat-label">Positive (4-5)</div>
    </div>
    <div class="stat-card" onclick="filterByRating(3)">
        <div class="stat-icon orange">
            <i class="bi bi-emoji-neutral"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['neutral_reviews']); ?></div>
        <div class="stat-label">Neutral (3)</div>
    </div>
    <div class="stat-card" onclick="filterByRating(2)">
        <div class="stat-icon red">
            <i class="bi bi-emoji-frown"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['negative_reviews']); ?></div>
        <div class="stat-label">Negative (1-2)</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('published')">
        <div class="stat-icon green">
            <i class="bi bi-check-circle"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['published']); ?></div>
        <div class="stat-label">Published</div>
    </div>
    <div class="stat-card" onclick="filterByStatus('pending')">
        <div class="stat-icon orange">
            <i class="bi bi-clock"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['pending_verification']); ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" onclick="filterByType('stay')">
        <div class="stat-icon purple">
            <i class="bi bi-building"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['stay_reviews']); ?></div>
        <div class="stat-label">Stays</div>
    </div>
    <div class="stat-card" onclick="filterByType('car_rental')">
        <div class="stat-icon cyan">
            <i class="bi bi-car-front"></i>
        </div>
        <div class="stat-value"><?php echo number_format($stats['car_reviews']); ?></div>
        <div class="stat-label">Cars</div>
    </div>
</div>

<!-- Rating Distribution -->
<div class="rating-distribution">
    <div class="distribution-header">
        <i class="bi bi-graph-up"></i> Rating Distribution
        <span style="font-size: 0.625rem; color: var(--booking-text-light);">Average Rating: <?php echo number_format($stats['avg_rating'], 1); ?> / 5.0</span>
    </div>
    <div class="distribution-bars">
        <?php for ($i = 5; $i >= 1; $i--): 
            $ratingData = array_filter($ratingDistribution, function($r) use ($i) {
                return $r['overall_rating'] == $i;
            });
            $ratingData = reset($ratingData);
            $count = $ratingData['count'] ?? 0;
            $percentage = $ratingData['percentage'] ?? 0;
        ?>
        <div class="distribution-item">
            <div class="distribution-label">
                <?php echo $i; ?> <i class="bi bi-star-fill" style="color: #ffc107; font-size: 0.5625rem;"></i>
            </div>
            <div class="distribution-bar">
                <div class="distribution-fill" style="width: <?php echo $percentage; ?>%"></div>
            </div>
            <div class="distribution-count"><?php echo number_format($count); ?> (<?php echo $percentage; ?>%)</div>
        </div>
        <?php endfor; ?>
    </div>
</div>

<!-- Review Trend Chart -->
<div class="chart-section">
    <div class="chart-header">
        <h3><i class="bi bi-graph-up"></i> Review Trends (Last 12 Months)</h3>
        <div class="chart-legend">
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem;">
                <span style="width: 10px; height: 10px; background: var(--booking-blue); border-radius: 2px;"></span> Reviews
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.625rem; margin-left: 12px;">
                <span style="width: 10px; height: 10px; background: var(--booking-success); border-radius: 2px;"></span> Avg Rating
            </span>
        </div>
    </div>
    <div class="chart-container">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<!-- Top Reviewed Items -->
<?php if (!empty($topReviewedItems)): ?>
<div class="top-items-section">
    <div class="top-items-header">
        <i class="bi bi-trophy"></i> Most Reviewed Items
    </div>
    <div class="top-items-list">
        <?php foreach ($topReviewedItems as $index => $item): ?>
        <div class="top-item">
            <div class="top-item-rank <?php echo 'rank-' . ($index + 1); ?>">
                <?php echo $index + 1; ?>
            </div>
            <div class="top-item-info">
                <div class="top-item-name">
                    <?php echo $item['type'] == 'stay' ? '🏨' : ($item['type'] == 'car' ? '🚗' : '🎟️'); ?>
                    <?php echo sanitize($item['item_name']); ?>
                </div>
                <div class="top-item-stats"><?php echo $item['review_count']; ?> reviews</div>
            </div>
            <div class="top-item-rating">
                <div class="rating-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star-fill <?php echo $i <= round($item['avg_rating']) ? '' : 'empty'; ?>" style="<?php echo $i <= round($item['avg_rating']) ? 'color: #ffc107;' : 'color: #e0e0e0;'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <span style="font-size: 0.625rem;"><?php echo number_format($item['avg_rating'], 1); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filter Section -->
<div class="filter-section">
    <form method="GET" action="reviews.php">
        <div class="filter-row">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" placeholder="Title, comment, reviewer..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label>Rating</label>
                <select name="rating">
                    <option value="0" <?php echo $rating == 0 ? 'selected' : ''; ?>>All Ratings</option>
                    <option value="5" <?php echo $rating == 5 ? 'selected' : ''; ?>>5 Stars</option>
                    <option value="4" <?php echo $rating == 4 ? 'selected' : ''; ?>>4 Stars</option>
                    <option value="3" <?php echo $rating == 3 ? 'selected' : ''; ?>>3 Stars</option>
                    <option value="2" <?php echo $rating == 2 ? 'selected' : ''; ?>>2 Stars</option>
                    <option value="1" <?php echo $rating == 1 ? 'selected' : ''; ?>>1 Star</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Type</label>
                <select name="type">
                    <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="stay" <?php echo $type == 'stay' ? 'selected' : ''; ?>>Stays</option>
                    <option value="car_rental" <?php echo $type == 'car_rental' ? 'selected' : ''; ?>>Car Rentals</option>
                    <option value="attraction" <?php echo $type == 'attraction' ? 'selected' : ''; ?>>Experiences</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Published</option>
                    <option value="hidden" <?php echo $status == 'hidden' ? 'selected' : ''; ?>>Hidden</option>
                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                </select>
            </div>
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
            </div>
            <div class="filter-group">
                <label>Sort By</label>
                <select name="sort">
                    <option value="created_desc" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="created_asc" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="rating_desc" <?php echo $sort == 'rating_desc' ? 'selected' : ''; ?>>Highest Rated</option>
                    <option value="rating_asc" <?php echo $sort == 'rating_asc' ? 'selected' : ''; ?>>Lowest Rated</option>
                    <option value="helpful_desc" <?php echo $sort == 'helpful_desc' ? 'selected' : ''; ?>>Most Helpful</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn">Apply Filters</button>
                <a href="reviews.php" class="filter-btn reset-btn">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Bulk Actions -->
<form method="POST" action="reviews.php" id="bulkForm">
    <input type="hidden" name="action" value="bulk_action">
    <div class="bulk-actions" id="bulkActions">
        <div class="bulk-select">
            <input type="checkbox" id="selectAll">
            <span id="selectedCount">0</span> <span>selected</span>
        </div>
        <select name="bulk_action_type" class="bulk-action-select">
            <option value="">Bulk Action</option>
            <option value="approve">Approve Selected</option>
            <option value="hide">Hide Selected</option>
            <option value="delete">Delete Selected</option>
        </select>
        <button type="submit" class="filter-btn" onclick="return confirm('Are you sure?')">Apply</button>
        <button type="button" class="filter-btn reset-btn" onclick="clearSelection()">Clear</button>
    </div>
</form>

<!-- Reviews Grid -->
<div class="reviews-grid">
    <?php if (empty($reviews)): ?>
    <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: var(--booking-white); border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
        <i class="bi bi-star" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No reviews found matching your criteria</p>
        <a href="reviews.php" class="filter-btn" style="margin-top: 16px; display: inline-block;">Clear Filters</a>
    </div>
    <?php else: ?>
    <?php foreach ($reviews as $review): 
        $initials = strtoupper(substr($review['first_name'], 0, 1) . substr($review['last_name'], 0, 1));
        $typeClass = $review['review_type'] == 'stay' ? 'type-stay' : ($review['review_type'] == 'car_rental' ? 'type-car' : 'type-attraction');
        $typeLabel = $review['review_type'] == 'stay' ? 'Stay' : ($review['review_type'] == 'car_rental' ? 'Car Rental' : 'Experience');
        
        if ($review['is_active'] && $review['is_verified']) {
            $statusClass = 'status-active';
            $statusLabel = 'Published';
        } elseif (!$review['is_active']) {
            $statusClass = 'status-hidden';
            $statusLabel = 'Hidden';
        } else {
            $statusClass = 'status-pending';
            $statusLabel = 'Pending';
        }
    ?>
    <div class="review-card">
        <div class="review-header">
            <span class="review-type <?php echo $typeClass; ?>">
                <?php echo $review['item_icon']; ?> <?php echo $typeLabel; ?>
            </span>
            <span class="review-status <?php echo $statusClass; ?>">
                <i class="bi bi-<?php echo $statusClass == 'status-active' ? 'check-circle' : ($statusClass == 'status-hidden' ? 'eye-slash' : 'clock'); ?>"></i>
                <?php echo $statusLabel; ?>
            </span>
        </div>
        
        <div class="review-body">
            <div class="reviewer-info">
                <div class="reviewer-avatar">
                    <?php if ($review['profile_image']): ?>
                    <img src="<?php echo getImageUrl($review['profile_image'], 'profile'); ?>" alt="">
                    <?php else: ?>
                    <?php echo $initials; ?>
                    <?php endif; ?>
                </div>
                <div class="reviewer-details">
                    <div class="reviewer-name"><?php echo sanitize($review['first_name'] . ' ' . $review['last_name']); ?></div>
                    <div class="review-date">
                        <i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                        <?php if ($review['booking_reference'] != 'N/A'): ?>
                        • Booking: #<?php echo $review['booking_reference']; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="review-rating">
                <?php for($i = 1; $i <= 5; $i++): ?>
                <i class="bi bi-star-fill <?php echo $i <= $review['overall_rating'] ? '' : 'empty'; ?>"></i>
                <?php endfor; ?>
            </div>
            
            <?php if ($review['title']): ?>
            <div class="review-title"><?php echo sanitize($review['title']); ?></div>
            <?php endif; ?>
            
            <?php if ($review['comment']): ?>
            <div class="review-comment">
                <?php echo nl2br(sanitize(substr($review['comment'], 0, 300))); ?>
                <?php if (strlen($review['comment']) > 300): ?>...<?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="review-item">
                <div class="review-item-icon"><?php echo $review['item_icon']; ?></div>
                <div class="review-item-name"><?php echo sanitize($review['item_name']); ?></div>
                <a href="<?php echo $review['review_type']; ?>-detail.php?id=<?php echo $review['item_id']; ?>" class="action-btn-sm helpful" style="padding: 2px 8px;">View Item</a>
            </div>
            
            <?php if ($review['positive_points'] || $review['negative_points']): ?>
            <div style="margin-bottom: 12px;">
                <?php if ($review['positive_points']): ?>
                <div style="font-size: 0.625rem; color: var(--booking-success); margin-bottom: 4px;">
                    <i class="bi bi-check-circle-fill"></i> Pros: <?php echo sanitize(substr($review['positive_points'], 0, 100)); ?>
                </div>
                <?php endif; ?>
                <?php if ($review['negative_points']): ?>
                <div style="font-size: 0.625rem; color: var(--booking-danger);">
                    <i class="bi bi-x-circle-fill"></i> Cons: <?php echo sanitize(substr($review['negative_points'], 0, 100)); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="review-helpful">
                <div class="helpful-count">
                    <i class="bi bi-hand-thumbs-up"></i> <?php echo number_format($review['helpful_count']); ?> found this helpful
                </div>
                <div class="review-actions">
                    <a href="?action=helpful&id=<?php echo $review['review_id']; ?>" class="action-btn-sm helpful" onclick="return confirm('Mark this review as helpful?')">
                        <i class="bi bi-hand-thumbs-up"></i> Helpful
                    </a>
                    <?php if (!$review['is_active'] || !$review['is_verified']): ?>
                    <a href="?action=approve&id=<?php echo $review['review_id']; ?>" class="action-btn-sm approve" onclick="return confirm('Approve and publish this review?')">
                        <i class="bi bi-check-circle"></i> Approve
                    </a>
                    <?php endif; ?>
                    <?php if ($review['is_active']): ?>
                    <a href="?action=hide&id=<?php echo $review['review_id']; ?>" class="action-btn-sm hide" onclick="return confirm('Hide this review from public view?')">
                        <i class="bi bi-eye-slash"></i> Hide
                    </a>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?php echo $review['review_id']; ?>" class="action-btn-sm delete" onclick="return confirm('Permanently delete this review? This action cannot be undone.')">
                        <i class="bi bi-trash"></i> Delete
                    </a>
                </div>
            </div>
            <div style="margin-top: 8px;">
                <input type="checkbox" class="review-checkbox" value="<?php echo $review['review_id']; ?>">
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
        <i class="bi bi-chevron-left"></i>
    </a>
    <?php endif; ?>
    
    <?php
    $startPage = max(1, $page - 2);
    $endPage = min($totalPages, $page + 2);
    for ($i = $startPage; $i <= $endPage; $i++):
    ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
       class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Review Trend Chart
<?php if (!empty($monthlyTrend)): ?>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [
            {
                label: 'Reviews',
                data: <?php echo json_encode($reviewCounts); ?>,
                borderColor: '#003b95',
                backgroundColor: 'rgba(0, 59, 149, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                yAxisID: 'y-reviews'
            },
            {
                label: 'Average Rating',
                data: <?php echo json_encode($avgRatings); ?>,
                borderColor: '#ff8c00',
                backgroundColor: 'transparent',
                borderWidth: 2,
                tension: 0.4,
                yAxisID: 'y-rating'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        if (context.dataset.label === 'Reviews') {
                            return 'Reviews: ' + context.parsed.y;
                        }
                        return 'Avg Rating: ' + context.parsed.y.toFixed(1) + ' ★';
                    }
                }
            }
        },
        scales: {
            x: { ticks: { font: { size: 9 }, maxRotation: 45, minRotation: 45 } },
            'y-reviews': {
                type: 'linear',
                display: true,
                position: 'left',
                ticks: { stepSize: 1, font: { size: 9 } }
            },
            'y-rating': {
                type: 'linear',
                display: true,
                position: 'right',
                min: 0,
                max: 5,
                grid: { drawOnChartArea: false },
                ticks: { stepSize: 1, font: { size: 9 } }
            }
        }
    }
});
<?php endif; ?>

// Filter functions
function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', status);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

function filterByRating(rating) {
    const url = new URL(window.location.href);
    url.searchParams.set('rating', rating);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

function filterByType(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('type', type);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Bulk selection
let selectedReviews = new Set();

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.review-checkbox:checked');
    selectedReviews.clear();
    checkboxes.forEach(cb => selectedReviews.add(cb.value));
    
    const count = selectedReviews.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCountSpan.textContent = count;
    } else {
        bulkActions.classList.remove('show');
    }
    
    const allCheckboxes = document.querySelectorAll('.review-checkbox');
    const selectAll = document.getElementById('selectAll');
    
    if (allCheckboxes.length === checkboxes.length && allCheckboxes.length > 0) {
        if (selectAll) selectAll.checked = true;
    } else {
        if (selectAll) selectAll.checked = false;
    }
}

function clearSelection() {
    document.querySelectorAll('.review-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

document.querySelectorAll('.review-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActions);
});

const selectAll = document.getElementById('selectAll');
if (selectAll) {
    selectAll.addEventListener('change', function(e) {
        document.querySelectorAll('.review-checkbox').forEach(cb => cb.checked = e.target.checked);
        updateBulkActions();
    });
}

document.getElementById('bulkForm')?.addEventListener('submit', function(e) {
    const selected = document.querySelectorAll('.review-checkbox:checked');
    if (selected.length === 0) {
        e.preventDefault();
        alert('Please select at least one review');
        return;
    }
    
    const action = document.querySelector('[name="bulk_action_type"]').value;
    if (!action) {
        e.preventDefault();
        alert('Please select a bulk action');
        return;
    }
    
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_reviews[]';
        input.value = cb.value;
        this.appendChild(input);
    });
});

updateBulkActions();
</script>

<?php require_once 'includes/admin_footer.php'; ?>