<?php
$pageTitle = 'Guest Reviews';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;
$rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'newest';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// ============================================
// HANDLE REVIEW ACTIONS
// ============================================

// Reply to review - Since there's no owner_reply column, we'll create a separate table or handle differently
// For now, we'll just mark as acknowledged or skip this feature
// You may want to add an `owner_response` column to the reviews table if needed

// Mark as helpful
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_helpful'])) {
    $reviewId = intval($_POST['review_id']);
    
    $stmt = $db->prepare("
        UPDATE reviews SET helpful_count = helpful_count + 1 
        WHERE review_id = ?
    ");
    $stmt->execute([$reviewId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// BUILD QUERY CONDITIONS
// ============================================

$conditions = ["a.owner_id = ?", "r.review_type = 'attraction'"];
$params = [$userId];

if ($experienceId > 0) {
    $conditions[] = "r.attraction_id = ?";
    $params[] = $experienceId;
}

if ($rating > 0) {
    $conditions[] = "r.overall_rating >= ?";
    $params[] = $rating;
}

$whereClause = implode(' AND ', $conditions);

// ============================================
// GET REVIEWS DATA
// ============================================

// Get all experiences for filter
$stmt = $db->prepare("
    SELECT attraction_id, attraction_name 
    FROM attractions 
    WHERE owner_id = ? 
    ORDER BY attraction_name
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(*) as total
    FROM reviews r
    JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE $whereClause
";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalReviews = $stmt->fetchColumn();
$totalPages = ceil($totalReviews / $perPage);

// Build order by
$orderBy = match($sort) {
    'newest' => 'r.created_at DESC',
    'oldest' => 'r.created_at ASC',
    'highest' => 'r.overall_rating DESC',
    'lowest' => 'r.overall_rating ASC',
    'helpful' => 'r.helpful_count DESC',
    default => 'r.created_at DESC'
};

// Get reviews
$sql = "
    SELECT 
        r.*,
        a.attraction_id,
        a.attraction_name,
        a.main_image as attraction_image,
        u.user_id as guest_id,
        u.first_name as guest_first_name,
        u.last_name as guest_last_name,
        u.email as guest_email,
        u.profile_image as guest_avatar,
        (SELECT COUNT(*) FROM reviews WHERE user_id = r.user_id) as guest_review_count,
        DATEDIFF(NOW(), r.created_at) as days_ago
    FROM reviews r
    JOIN attractions a ON r.attraction_id = a.attraction_id
    LEFT JOIN users u ON r.user_id = u.user_id
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Get statistics - FIXED: removed non-existent columns
$stats = [
    'total' => 0,
    'average' => 0,
    '5star' => 0,
    '4star' => 0,
    '3star' => 0,
    '2star' => 0,
    '1star' => 0,
    'total_helpful' => 0
];

$statsSql = "
    SELECT 
        COUNT(*) as total,
        COALESCE(AVG(r.overall_rating), 0) as avg_rating,
        SUM(CASE WHEN r.overall_rating >= 9 THEN 1 ELSE 0 END) as star5,
        SUM(CASE WHEN r.overall_rating BETWEEN 7 AND 8 THEN 1 ELSE 0 END) as star4,
        SUM(CASE WHEN r.overall_rating BETWEEN 5 AND 6 THEN 1 ELSE 0 END) as star3,
        SUM(CASE WHEN r.overall_rating BETWEEN 3 AND 4 THEN 1 ELSE 0 END) as star2,
        SUM(CASE WHEN r.overall_rating <= 2 THEN 1 ELSE 0 END) as star1,
        COALESCE(SUM(r.helpful_count), 0) as total_helpful
    FROM reviews r
    JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE a.owner_id = ? AND r.review_type = 'attraction'
";

$stmt = $db->prepare($statsSql);
$stmt->execute([$userId]);
$statsData = $stmt->fetch();

if ($statsData) {
    $stats = [
        'total' => $statsData['total'],
        'average' => round($statsData['avg_rating'], 1),
        '5star' => $statsData['star5'],
        '4star' => $statsData['star4'],
        '3star' => $statsData['star3'],
        '2star' => $statsData['star2'],
        '1star' => $statsData['star1'],
        'total_helpful' => $statsData['total_helpful']
    ];
}

// Get recent rating trends (last 6 months)
$trendSql = "
    SELECT 
        DATE_FORMAT(r.created_at, '%b') as month,
        DATE_FORMAT(r.created_at, '%Y-%m') as month_key,
        COUNT(*) as count,
        COALESCE(AVG(r.overall_rating), 0) as avg_rating
    FROM reviews r
    JOIN attractions a ON r.attraction_id = a.attraction_id
    WHERE a.owner_id = ? AND r.review_type = 'attraction'
    AND r.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(r.created_at, '%Y-%m')
    ORDER BY month_key ASC
";
$stmt = $db->prepare($trendSql);
$stmt->execute([$userId]);
$trendData = $stmt->fetchAll();

// Fill in missing months
$monthlyTrend = [];
$monthlyLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('M', strtotime("-$i months"));
    $monthlyLabels[] = $month;
    $found = false;
    foreach ($trendData as $data) {
        if ($data['month'] == $month) {
            $monthlyTrend[] = round($data['avg_rating'], 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $monthlyTrend[] = 0;
    }
}

// Status labels
$ratingLabels = [
    10 => 'Exceptional',
    9 => 'Excellent',
    8 => 'Very Good',
    7 => 'Good',
    6 => 'Pleasant',
    5 => 'Average',
    4 => 'Below Average',
    3 => 'Poor',
    2 => 'Very Poor',
    1 => 'Terrible'
];
?>
<!-- Rest of the HTML/CSS remains the same as before until the stats grid -->

<style>
/* Reviews Management Specific Styles - same as before */
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
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.reviews-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
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
    border: 1px solid var(--exp-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
}

/* Rating Overview */
.rating-overview {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 24px;
    margin-bottom: 24px;
    display: flex;
    gap: 40px;
    flex-wrap: wrap;
    align-items: center;
}

.rating-large {
    text-align: center;
    min-width: 150px;
}

.rating-number {
    font-size: 3rem;
    font-weight: 800;
    color: var(--exp-purple);
    line-height: 1;
}

.rating-stars-large {
    color: var(--exp-warning);
    font-size: 1.25rem;
    margin: 8px 0;
}

.rating-count {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
}

.rating-bars {
    flex: 1;
    max-width: 400px;
}

.rating-bar-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.rating-bar-label {
    min-width: 60px;
    font-size: 0.75rem;
    color: var(--exp-text-light);
}

.rating-bar-bg {
    flex: 1;
    height: 8px;
    background: var(--exp-gray);
    border-radius: 4px;
    overflow: hidden;
}

.rating-bar-fill {
    height: 100%;
    background: var(--exp-purple);
    border-radius: 4px;
}

.rating-bar-value {
    min-width: 40px;
    font-size: 0.75rem;
    font-weight: 600;
    text-align: right;
}

/* Trend Chart */
.trend-chart {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 20px;
    margin-bottom: 24px;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.chart-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--exp-text);
}

.chart-container {
    height: 200px;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--exp-text-light);
    text-transform: uppercase;
    white-space: nowrap;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 150px;
}

/* Reviews Grid */
.reviews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(500px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.review-card {
    background: white;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
}

.review-card:hover {
    box-shadow: var(--shadow-md);
}

.review-header {
    padding: 16px;
    background: linear-gradient(135deg, var(--exp-light-purple), white);
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.reviewer-info {
    display: flex;
    gap: 12px;
}

.reviewer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
}

.reviewer-details h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.reviewer-meta {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    display: flex;
    gap: 12px;
}

.review-badges {
    display: flex;
    gap: 8px;
}

.review-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.badge-verified {
    background: var(--exp-success);
    color: white;
}

.review-body {
    padding: 16px;
}

.review-rating {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.rating-score {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--exp-purple);
}

.rating-stars {
    color: var(--exp-warning);
    font-size: 0.875rem;
}

.rating-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
}

.review-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--exp-text);
}

.review-content {
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--exp-text);
    margin-bottom: 16px;
}

.review-footer {
    padding: 12px 16px;
    border-top: 1px solid var(--exp-border);
    background: var(--exp-gray);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.review-meta {
    display: flex;
    gap: 16px;
    font-size: 0.6875rem;
    color: var(--exp-text-light);
}

.review-actions {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 6px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--exp-text);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.action-btn.success:hover {
    background: #e6f4ea;
    border-color: var(--exp-success);
    color: var(--exp-success);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    color: var(--exp-text);
    text-decoration: none;
    font-size: 0.8125rem;
    transition: all 0.2s;
}

.page-link:hover,
.page-link.active {
    background: var(--exp-purple);
    border-color: var(--exp-purple);
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
    overflow-y: auto;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 100%;
    max-width: 400px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
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
    color: var(--exp-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--exp-gray);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: var(--exp-danger);
    color: white;
}

.modal-body {
    padding: 20px;
    text-align: center;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--exp-border);
    display: flex;
    justify-content: center;
    gap: 12px;
    background: var(--exp-gray);
    position: sticky;
    bottom: 0;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .reviews-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .rating-overview {
        flex-direction: column;
        align-items: stretch;
        gap: 20px;
    }
    
    .review-footer {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .review-actions {
        width: 100%;
        justify-content: space-between;
    }
}
</style>

<div class="reviews-header">
    <div class="reviews-title">
        <h1>Guest Reviews</h1>
        <p>Manage and respond to guest feedback</p>
    </div>
    <div>
        <button class="btn-secondary" onclick="exportReviews()">
            <i class="bi bi-download"></i> Export
        </button>
        <button class="btn-secondary" onclick="refreshData()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['total']; ?></div>
        <div class="stat-label">Total Reviews</div>
        <div class="stat-footer">All time</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['average']; ?>/10</div>
        <div class="stat-label">Average Rating</div>
        <div class="stat-footer"><?php echo $stats['total_helpful']; ?> helpful votes</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['5star'] + $stats['4star']; ?></div>
        <div class="stat-label">Positive Reviews</div>
        <div class="stat-footer">7-10 rating</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $stats['1star'] + $stats['2star']; ?></div>
        <div class="stat-label">Critical Reviews</div>
        <div class="stat-footer">1-4 rating</div>
    </div>
</div>

<!-- Rating Overview -->
<div class="rating-overview">
    <div class="rating-large">
        <div class="rating-number"><?php echo $stats['average']; ?></div>
        <div class="rating-stars-large">
            <?php 
            $starRating = round($stats['average'] / 2, 1);
            for ($i = 1; $i <= 5; $i++): 
                if ($i <= floor($starRating)) {
                    echo '<i class="bi bi-star-fill"></i>';
                } elseif ($i - $starRating < 1 && $i - $starRating > 0) {
                    echo '<i class="bi bi-star-half"></i>';
                } else {
                    echo '<i class="bi bi-star"></i>';
                }
            endfor; 
            ?>
        </div>
        <div class="rating-count">Based on <?php echo $stats['total']; ?> reviews</div>
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
        foreach ($ratings as $stars => $count): 
            $percentage = $stats['total'] > 0 ? round(($count / $stats['total']) * 100) : 0;
        ?>
        <div class="rating-bar-item">
            <span class="rating-bar-label"><?php echo $stars; ?> ★</span>
            <div class="rating-bar-bg">
                <div class="rating-bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
            </div>
            <span class="rating-bar-value"><?php echo $percentage; ?>%</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Trend Chart -->
<div class="trend-chart">
    <div class="chart-header">
        <h3 class="chart-title">Rating Trend (Last 6 Months)</h3>
    </div>
    <div class="chart-container">
        <canvas id="trendChart"></canvas>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Experience</label>
        <select name="experience" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Experiences</option>
            <?php foreach ($experiences as $exp): ?>
            <option value="<?php echo $exp['attraction_id']; ?>" <?php echo $experienceId == $exp['attraction_id'] ? 'selected' : ''; ?>>
                <?php echo sanitize($exp['attraction_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Rating</label>
        <select name="rating" class="filter-select" onchange="this.form.submit()">
            <option value="0">All Ratings</option>
            <option value="9" <?php echo $rating == 9 ? 'selected' : ''; ?>>9+ (Exceptional)</option>
            <option value="8" <?php echo $rating == 8 ? 'selected' : ''; ?>>8+ (Excellent)</option>
            <option value="7" <?php echo $rating == 7 ? 'selected' : ''; ?>>7+ (Very Good)</option>
            <option value="6" <?php echo $rating == 6 ? 'selected' : ''; ?>>6+ (Good)</option>
            <option value="5" <?php echo $rating == 5 ? 'selected' : ''; ?>>5+ (Average)</option>
            <option value="4" <?php echo $rating == 4 ? 'selected' : ''; ?>>4+ (Below Average)</option>
            <option value="3" <?php echo $rating == 3 ? 'selected' : ''; ?>>3+ (Poor)</option>
            <option value="2" <?php echo $rating == 2 ? 'selected' : ''; ?>>2+ (Very Poor)</option>
            <option value="1" <?php echo $rating == 1 ? 'selected' : ''; ?>>1+ (Terrible)</option>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Sort By</label>
        <select name="sort" class="filter-select" onchange="this.form.submit()">
            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
            <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
            <option value="highest" <?php echo $sort == 'highest' ? 'selected' : ''; ?>>Highest Rated</option>
            <option value="lowest" <?php echo $sort == 'lowest' ? 'selected' : ''; ?>>Lowest Rated</option>
            <option value="helpful" <?php echo $sort == 'helpful' ? 'selected' : ''; ?>>Most Helpful</option>
        </select>
    </div>
    
    <?php if ($experienceId || $rating): ?>
    <a href="reviews.php" class="btn-secondary btn-sm">Clear Filters</a>
    <?php endif; ?>
</form>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success" style="padding: 12px 16px; background: #e6f4ea; color: #10b981; border-radius: var(--radius-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<!-- Results Info -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <p style="font-size: 0.8125rem; color: var(--exp-text-light);">
        Showing <strong><?php echo count($reviews); ?></strong> of <strong><?php echo $totalReviews; ?></strong> reviews
    </p>
    <p style="font-size: 0.8125rem; color: var(--exp-text-light);">
        Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?>
    </p>
</div>

<!-- Reviews Grid -->
<div class="reviews-grid">
    <?php if (empty($reviews)): ?>
    <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--exp-border);">
        <i class="bi bi-star" style="font-size: 3rem; color: var(--exp-text-light); margin-bottom: 16px;"></i>
        <h3 style="font-size: 1.125rem; margin-bottom: 8px;">No reviews found</h3>
        <p style="color: var(--exp-text-light);">Try adjusting your filters</p>
    </div>
    <?php else: ?>
        <?php foreach ($reviews as $review): 
            $rating = $review['overall_rating'];
            $ratingLabel = $ratingLabels[$rating] ?? 'Mixed';
            $guestName = trim(($review['guest_first_name'] ?? '') . ' ' . ($review['guest_last_name'] ?? ''));
            $guestInitial = strtoupper(substr($review['guest_first_name'] ?? 'G', 0, 1));
            $avatar = $review['guest_avatar'] ?? null;
        ?>
        <div class="review-card">
            <div class="review-header">
                <div class="reviewer-info">
                    <div class="reviewer-avatar">
                        <?php if ($avatar): ?>
                        <img src="<?php echo getImageUrl($avatar, 'profile'); ?>" alt="">
                        <?php else: ?>
                        <?php echo $guestInitial; ?>
                        <?php endif; ?>
                    </div>
                    <div class="reviewer-details">
                        <h3><?php echo sanitize($guestName ?: 'Anonymous Guest'); ?></h3>
                        <div class="reviewer-meta">
                            <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                            <span><i class="bi bi-chat"></i> <?php echo $review['guest_review_count']; ?> reviews</span>
                        </div>
                    </div>
                </div>
                <div class="review-badges">
                    <?php if ($review['is_verified']): ?>
                    <span class="review-badge badge-verified"><i class="bi bi-patch-check-fill"></i> Verified</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="review-body">
                <div class="review-rating">
                    <span class="rating-score"><?php echo $rating; ?>/10</span>
                    <span class="rating-stars">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                            <i class="bi bi-star-fill<?php echo $i <= $rating ? '' : '-empty'; ?>" style="color: <?php echo $i <= $rating ? 'var(--exp-warning)' : 'var(--exp-text-light)'; ?>;"></i>
                        <?php endfor; ?>
                    </span>
                    <span class="rating-label"><?php echo $ratingLabel; ?></span>
                </div>
                
                <?php if ($review['title']): ?>
                <h4 class="review-title"><?php echo sanitize($review['title']); ?></h4>
                <?php endif; ?>
                
                <div class="review-content">
                    <?php echo nl2br(sanitize($review['comment'])); ?>
                </div>
            </div>
            
            <div class="review-footer">
                <div class="review-meta">
                    <span><i class="bi bi-hand-thumbs-up"></i> <?php echo $review['helpful_count'] ?? 0; ?> found helpful</span>
                    <span><i class="bi bi-building"></i> <?php echo sanitize($review['attraction_name']); ?></span>
                </div>
                
                <div class="review-actions">
                    <button class="action-btn success" onclick="markHelpful(<?php echo $review['review_id']; ?>)">
                        <i class="bi bi-hand-thumbs-up"></i> Helpful
                    </button>
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
    
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
       class="page-link <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    
    <?php if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Mark Helpful Modal -->
<div class="modal" id="helpfulModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Thank You!</h3>
            <button class="modal-close" onclick="closeModal('helpfulModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <i class="bi bi-hand-thumbs-up-fill" style="font-size: 3rem; color: var(--exp-success); margin-bottom: 16px;"></i>
            <p style="font-size: 1rem; margin-bottom: 8px;">You marked this review as helpful.</p>
            <p style="font-size: 0.8125rem; color: var(--exp-text-light);">Thank you for your feedback!</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-primary" onclick="closeModal('helpfulModal')">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function refreshData() {
    window.location.reload();
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

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// REVIEW ACTIONS
// ============================================
function markHelpful(reviewId) {
    fetch('reviews.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'mark_helpful=1&review_id=' + reviewId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            openModal('helpfulModal');
            // Update the count in UI (optional)
            setTimeout(() => location.reload(), 1500);
        }
    });
}

// ============================================
// EXPORT
// ============================================
function exportReviews() {
    // Create CSV content
    let csv = "Date,Rating,Title,Review,Reviewer,Experience,Helpful\n";
    
    <?php foreach ($reviews as $review): 
        $guestName = trim(($review['guest_first_name'] ?? '') . ' ' . ($review['guest_last_name'] ?? ''));
    ?>
    csv += "<?php echo $review['created_at']; ?>,<?php echo $review['overall_rating']; ?>,<?php echo str_replace(',', ' ', $review['title'] ?? ''); ?>,<?php echo str_replace(',', ' ', substr($review['comment'] ?? '', 0, 100)); ?>...,<?php echo $guestName; ?>,<?php echo $review['attraction_name']; ?>,<?php echo $review['helpful_count']; ?>\n";
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

// ============================================
// TREND CHART
// ============================================
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($monthlyLabels); ?>,
        datasets: [{
            label: 'Average Rating',
            data: <?php echo json_encode($monthlyTrend); ?>,
            borderColor: '#9333ea',
            backgroundColor: 'rgba(147, 51, 234, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#9333ea',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1a1a1a',
                padding: 12,
                cornerRadius: 4,
                callbacks: {
                    label: function(context) {
                        return 'Rating: ' + context.parsed.y.toFixed(1) + '/10';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 10,
                grid: {
                    color: '#f0f0f0'
                },
                ticks: {
                    stepSize: 2,
                    callback: function(value) {
                        return value + '/10';
                    }
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>