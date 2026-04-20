<?php
$pageTitle = 'Search';
require_once 'includes/admin_header.php';

$db = getDB();

// Get search parameters
$query = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : 'all';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build search conditions
$searchTerm = "%$query%";
$results = [];
$totalResults = 0;
$searchStats = [
    'bookings' => 0,
    'users' => 0,
    'stays' => 0,
    'cars' => 0,
    'attractions' => 0,
    'restaurants' => 0,
    'reviews' => 0
];

// ============================================
// SEARCH BOOKINGS
// ============================================
if ($type === 'all' || $type === 'bookings') {
    $sql = "
        SELECT 
            b.*,
            'booking' as result_type,
            b.booking_reference as title,
            CONCAT(u.first_name, ' ', u.last_name) as subtitle,
            b.total_amount as amount,
            b.created_at as date,
            CASE 
                WHEN b.booking_type = 'stay' THEN s.stay_name
                WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
                ELSE a.attraction_name
            END as item_name,
            u.email as user_email,
            b.status,
            b.user_id
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.user_id
        LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        LEFT JOIN stays s ON sr.stay_id = s.stay_id
        LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
        LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        LEFT JOIN attractions a ON at.attraction_id = a.attraction_id
        WHERE (b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    
    if ($status !== 'all') {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    if ($dateFrom) {
        $sql .= " AND DATE(b.created_at) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND DATE(b.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " ORDER BY b.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
    
    // Get total count for pagination
    $countSql = "
        SELECT COUNT(*) FROM bookings b
        LEFT JOIN users u ON b.user_id = u.user_id
        WHERE (b.booking_reference LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    if ($status !== 'all') {
        $countSql .= " AND b.status = ?";
        $paramsCount[] = $status;
    }
    if ($dateFrom) {
        $countSql .= " AND DATE(b.created_at) >= ?";
        $paramsCount[] = $dateFrom;
    }
    if ($dateTo) {
        $countSql .= " AND DATE(b.created_at) <= ?";
        $paramsCount[] = $dateTo;
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['bookings'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $bookings);
    $totalResults += $searchStats['bookings'];
}

// ============================================
// SEARCH USERS
// ============================================
if ($type === 'all' || $type === 'users') {
    $sql = "
        SELECT 
            u.*,
            'user' as result_type,
            CONCAT(u.first_name, ' ', u.last_name) as title,
            u.email as subtitle,
            u.created_at as date,
            u.user_type,
            u.is_active,
            (SELECT COUNT(*) FROM bookings WHERE user_id = u.user_id) as booking_count
        FROM users u
        WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm];
    
    if ($status === 'active') {
        $sql .= " AND u.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND u.is_active = 0";
    } elseif ($status === 'verified') {
        $sql .= " AND u.is_verified = 1";
    } elseif ($status === 'pending') {
        $sql .= " AND u.is_verified = 0 AND u.is_active = 1";
    } elseif ($status === 'business_owner') {
        $sql .= " AND u.user_type = 'business_owner'";
    } elseif ($status === 'tourist') {
        $sql .= " AND u.user_type = 'tourist'";
    }
    
    $sql .= " ORDER BY u.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM users u
        WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm];
    if ($status === 'active') {
        $countSql .= " AND u.is_active = 1";
    } elseif ($status === 'inactive') {
        $countSql .= " AND u.is_active = 0";
    } elseif ($status === 'verified') {
        $countSql .= " AND u.is_verified = 1";
    } elseif ($status === 'pending') {
        $countSql .= " AND u.is_verified = 0 AND u.is_active = 1";
    } elseif ($status === 'business_owner') {
        $countSql .= " AND u.user_type = 'business_owner'";
    } elseif ($status === 'tourist') {
        $countSql .= " AND u.user_type = 'tourist'";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['users'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $users);
    $totalResults += $searchStats['users'];
}

// ============================================
// SEARCH STAYS
// ============================================
if ($type === 'all' || $type === 'stays') {
    $sql = "
        SELECT 
            s.*,
            'stay' as result_type,
            s.stay_name as title,
            CONCAT(s.address, ', ', s.city) as subtitle,
            s.created_at as date,
            s.star_rating,
            s.is_verified,
            s.is_active,
            l.name as location_name,
            u.first_name as owner_first, 
            u.last_name as owner_last,
            u.user_id as owner_id
        FROM stays s
        LEFT JOIN locations l ON s.location_id = l.location_id
        LEFT JOIN users u ON s.owner_id = u.user_id
        WHERE (s.stay_name LIKE ? OR s.address LIKE ? OR s.city LIKE ? OR l.name LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    
    if ($status === 'verified') {
        $sql .= " AND s.is_verified = 1";
    } elseif ($status === 'pending') {
        $sql .= " AND s.is_verified = 0 AND s.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND s.is_active = 0";
    }
    
    $sql .= " ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stays = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM stays s
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE (s.stay_name LIKE ? OR s.address LIKE ? OR s.city LIKE ? OR l.name LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    if ($status === 'verified') {
        $countSql .= " AND s.is_verified = 1";
    } elseif ($status === 'pending') {
        $countSql .= " AND s.is_verified = 0 AND s.is_active = 1";
    } elseif ($status === 'inactive') {
        $countSql .= " AND s.is_active = 0";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['stays'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $stays);
    $totalResults += $searchStats['stays'];
}

// ============================================
// SEARCH CARS
// ============================================
if ($type === 'all' || $type === 'cars') {
    $sql = "
        SELECT 
            cr.*,
            'car' as result_type,
            cr.company_name as title,
            cr.address as subtitle,
            cr.created_at as date,
            cr.is_verified,
            cr.is_active,
            l.name as location_name,
            u.first_name as owner_first, 
            u.last_name as owner_last,
            u.user_id as owner_id,
            (SELECT COUNT(*) FROM car_fleet WHERE rental_id = cr.rental_id) as fleet_count
        FROM car_rentals cr
        LEFT JOIN locations l ON cr.location_id = l.location_id
        LEFT JOIN users u ON cr.owner_id = u.user_id
        WHERE (cr.company_name LIKE ? OR cr.address LIKE ? OR l.name LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm];
    
    if ($status === 'verified') {
        $sql .= " AND cr.is_verified = 1";
    } elseif ($status === 'pending') {
        $sql .= " AND cr.is_verified = 0 AND cr.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND cr.is_active = 0";
    }
    
    $sql .= " ORDER BY cr.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $cars = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM car_rentals cr
        LEFT JOIN locations l ON cr.location_id = l.location_id
        WHERE (cr.company_name LIKE ? OR cr.address LIKE ? OR l.name LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm];
    if ($status === 'verified') {
        $countSql .= " AND cr.is_verified = 1";
    } elseif ($status === 'pending') {
        $countSql .= " AND cr.is_verified = 0 AND cr.is_active = 1";
    } elseif ($status === 'inactive') {
        $countSql .= " AND cr.is_active = 0";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['cars'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $cars);
    $totalResults += $searchStats['cars'];
}

// ============================================
// SEARCH ATTRACTIONS
// ============================================
if ($type === 'all' || $type === 'attractions') {
    $sql = "
        SELECT 
            a.*,
            'attraction' as result_type,
            a.attraction_name as title,
            a.address as subtitle,
            a.created_at as date,
            a.is_verified,
            a.is_active,
            l.name as location_name,
            c.name as category_name,
            u.first_name as owner_first, 
            u.last_name as owner_last,
            u.user_id as owner_id
        FROM attractions a
        LEFT JOIN locations l ON a.location_id = l.location_id
        LEFT JOIN categories c ON a.category_id = c.category_id
        LEFT JOIN users u ON a.owner_id = u.user_id
        WHERE (a.attraction_name LIKE ? OR a.address LIKE ? OR l.name LIKE ? OR c.name LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    
    if ($status === 'verified') {
        $sql .= " AND a.is_verified = 1";
    } elseif ($status === 'pending') {
        $sql .= " AND a.is_verified = 0 AND a.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND a.is_active = 0";
    }
    
    $sql .= " ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $attractions = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM attractions a
        LEFT JOIN locations l ON a.location_id = l.location_id
        LEFT JOIN categories c ON a.category_id = c.category_id
        WHERE (a.attraction_name LIKE ? OR a.address LIKE ? OR l.name LIKE ? OR c.name LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    if ($status === 'verified') {
        $countSql .= " AND a.is_verified = 1";
    } elseif ($status === 'pending') {
        $countSql .= " AND a.is_verified = 0 AND a.is_active = 1";
    } elseif ($status === 'inactive') {
        $countSql .= " AND a.is_active = 0";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['attractions'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $attractions);
    $totalResults += $searchStats['attractions'];
}

// ============================================
// SEARCH RESTAURANTS
// ============================================
if ($type === 'all' || $type === 'restaurants') {
    $sql = "
        SELECT 
            r.*,
            'restaurant' as result_type,
            r.restaurant_name as title,
            r.cuisine_type as subtitle,
            r.created_at as date,
            r.is_active,
            l.name as location_name,
            s.stay_name as hotel_name,
            s.stay_id
        FROM restaurants r
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE (r.restaurant_name LIKE ? OR r.cuisine_type LIKE ? OR l.name LIKE ? OR s.stay_name LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    
    if ($status === 'active') {
        $sql .= " AND r.is_active = 1";
    } elseif ($status === 'inactive') {
        $sql .= " AND r.is_active = 0";
    }
    
    $sql .= " ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $restaurants = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM restaurants r
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN locations l ON s.location_id = l.location_id
        WHERE (r.restaurant_name LIKE ? OR r.cuisine_type LIKE ? OR l.name LIKE ? OR s.stay_name LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    if ($status === 'active') {
        $countSql .= " AND r.is_active = 1";
    } elseif ($status === 'inactive') {
        $countSql .= " AND r.is_active = 0";
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['restaurants'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $restaurants);
    $totalResults += $searchStats['restaurants'];
}

// ============================================
// SEARCH REVIEWS
// ============================================
if ($type === 'all' || $type === 'reviews') {
    $sql = "
        SELECT 
            r.*,
            'review' as result_type,
            COALESCE(r.title, 'Review') as title,
            LEFT(r.comment, 100) as subtitle,
            r.created_at as date,
            r.overall_rating as rating,
            u.first_name as user_first, 
            u.last_name as user_last,
            u.user_id as user_id,
            CASE 
                WHEN r.review_type = 'stay' THEN s.stay_name
                WHEN r.review_type = 'car_rental' THEN cr.company_name
                ELSE a.attraction_name
            END as item_name,
            CASE 
                WHEN r.review_type = 'stay' THEN s.stay_id
                WHEN r.review_type = 'car_rental' THEN cr.rental_id
                ELSE a.attraction_id
            END as item_id,
            r.review_type
        FROM reviews r
        LEFT JOIN users u ON r.user_id = u.user_id
        LEFT JOIN stays s ON r.stay_id = s.stay_id
        LEFT JOIN car_rentals cr ON r.rental_id = cr.rental_id
        LEFT JOIN attractions a ON r.attraction_id = a.attraction_id
        WHERE (r.title LIKE ? OR r.comment LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
    ";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    
    $sql .= " ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) FROM reviews r
        LEFT JOIN users u ON r.user_id = u.user_id
        WHERE (r.title LIKE ? OR r.comment LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)
    ";
    $paramsCount = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    $stmt = $db->prepare($countSql);
    $stmt->execute($paramsCount);
    $searchStats['reviews'] = $stmt->fetchColumn();
    
    $results = array_merge($results, $reviews);
    $totalResults += $searchStats['reviews'];
}

// Sort results by date (newest first)
usort($results, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$totalPages = ceil($totalResults / $perPage);
?>

<style>
/* Search Page Styles */
.search-header {
    margin-bottom: 24px;
}

.search-title h1 {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.search-title p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

/* Search Bar */
.search-bar-large {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 20px;
    margin-bottom: 24px;
}

.search-input-large {
    position: relative;
    margin-bottom: 16px;
}

.search-input-large i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--booking-text-light);
    font-size: 1rem;
}

.search-input-large input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    font-size: 0.875rem;
    transition: all var(--transition-fast);
}

.search-input-large input:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.search-filters {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    background: var(--booking-white);
    min-width: 140px;
}

.filter-input {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.search-btn {
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    padding: 8px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.search-btn:hover {
    background: var(--booking-blue-dark);
}

/* Stats Cards */
.search-stats {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.stat-pill {
    background: var(--booking-white);
    border-radius: 100px;
    padding: 6px 16px;
    border: 1px solid var(--booking-border);
    font-size: 0.6875rem;
    color: var(--booking-text);
    transition: all var(--transition-fast);
    cursor: pointer;
}

.stat-pill:hover {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.05);
}

.stat-pill.active {
    background: var(--booking-blue);
    color: var(--booking-white);
    border-color: var(--booking-blue);
}

.stat-pill .count {
    font-weight: 700;
    margin-left: 4px;
}

/* Results */
.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.results-count {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.results-count strong {
    color: var(--booking-text);
    font-weight: 700;
}

/* Result Cards */
.result-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    margin-bottom: 12px;
    transition: all var(--transition-fast);
}

.result-card:hover {
    box-shadow: var(--shadow-md);
    border-color: var(--booking-blue);
}

.result-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.result-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.5625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.result-badge.booking { background: #e1f5fe; color: #0288d1; }
.result-badge.user { background: #e8f5e9; color: #2e7d32; }
.result-badge.stay { background: #fff3e0; color: #ef6c00; }
.result-badge.car { background: #f3e5f5; color: #7b1fa2; }
.result-badge.attraction { background: #e0f2f1; color: #00695c; }
.result-badge.restaurant { background: #fce4ec; color: #c2185b; }
.result-badge.review { background: #fff8e1; color: #ff8f00; }

.result-title {
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 4px;
}

.result-title a {
    color: var(--booking-text);
    text-decoration: none;
}

.result-title a:hover {
    color: var(--booking-blue);
    text-decoration: underline;
}

.result-subtitle {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
}

.result-meta {
    display: flex;
    gap: 16px;
    font-size: 0.625rem;
    color: var(--booking-text-light);
    flex-wrap: wrap;
}

.result-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.result-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.status-active { background: #e6f4ea; color: var(--booking-success); }
.status-inactive { background: #fce8e8; color: var(--booking-danger); }
.status-verified { background: #e6f4ea; color: var(--booking-success); }
.status-pending { background: #fff4e6; color: var(--booking-warning); }
.status-confirmed { background: #e6f4ea; color: var(--booking-success); }
.status-completed { background: #e0f2f1; color: #00695c; }
.status-cancelled { background: #fce8e8; color: var(--booking-danger); }

.result-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--booking-border);
}

.action-link {
    font-size: 0.625rem;
    color: var(--booking-blue);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.action-link:hover {
    text-decoration: underline;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
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
    width: 32px;
    height: 32px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    color: var(--booking-text);
    text-decoration: none;
    font-size: 0.75rem;
    transition: all var(--transition-fast);
}

.page-link:hover,
.page-link.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
}

/* Rating Stars */
.rating-stars {
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.rating-stars i {
    font-size: 0.625rem;
    color: #ffc107;
}

.rating-stars i.empty {
    color: #e0e0e0;
}

@media (max-width: 768px) {
    .search-filters {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-wrap: wrap;
    }
    
    .filter-select,
    .filter-input {
        flex: 1;
    }
    
    .result-header {
        flex-direction: column;
        gap: 8px;
    }
}
</style>

<div class="search-header">
    <div class="search-title">
        <h1>Global Search</h1>
        <p>Search across all platform data - bookings, users, properties, and more</p>
    </div>
</div>

<!-- Search Form -->
<form method="GET" action="search.php" class="search-bar-large">
    <div class="search-input-large">
        <i class="bi bi-search"></i>
        <input type="text" name="q" placeholder="Search by name, email, reference, address..." 
               value="<?php echo htmlspecialchars($query); ?>" autocomplete="off">
    </div>
    
    <div class="search-filters">
        <div class="filter-group">
            <label>Type</label>
            <select name="type" class="filter-select">
                <option value="all" <?php echo $type == 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="bookings" <?php echo $type == 'bookings' ? 'selected' : ''; ?>>Bookings</option>
                <option value="users" <?php echo $type == 'users' ? 'selected' : ''; ?>>Users</option>
                <option value="stays" <?php echo $type == 'stays' ? 'selected' : ''; ?>>Stays</option>
                <option value="cars" <?php echo $type == 'cars' ? 'selected' : ''; ?>>Car Rentals</option>
                <option value="attractions" <?php echo $type == 'attractions' ? 'selected' : ''; ?>>Experiences</option>
                <option value="restaurants" <?php echo $type == 'restaurants' ? 'selected' : ''; ?>>Restaurants</option>
                <option value="reviews" <?php echo $type == 'reviews' ? 'selected' : ''; ?>>Reviews</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>Status</label>
            <select name="status" class="filter-select">
                <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="verified" <?php echo $status == 'verified' ? 'selected' : ''; ?>>Verified</option>
                <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="confirmed" <?php echo $status == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="filter-input" value="<?php echo $dateFrom; ?>">
        </div>
        
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="filter-input" value="<?php echo $dateTo; ?>">
        </div>
        
        <button type="submit" class="search-btn">Search</button>
        
        <?php if ($query || $type != 'all' || $status != 'all' || $dateFrom || $dateTo): ?>
        <a href="search.php" class="action-link" style="font-size: 0.6875rem;">
            <i class="bi bi-x-lg"></i> Clear Filters
        </a>
        <?php endif; ?>
    </div>
</form>

<?php if ($query): ?>
<!-- Search Stats -->
<div class="search-stats">
    <div class="stat-pill <?php echo $type == 'all' ? 'active' : ''; ?>" onclick="filterType('all')">
        All <span class="count">(<?php echo $totalResults; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'bookings' ? 'active' : ''; ?>" onclick="filterType('bookings')">
        Bookings <span class="count">(<?php echo $searchStats['bookings']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'users' ? 'active' : ''; ?>" onclick="filterType('users')">
        Users <span class="count">(<?php echo $searchStats['users']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'stays' ? 'active' : ''; ?>" onclick="filterType('stays')">
        Stays <span class="count">(<?php echo $searchStats['stays']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'cars' ? 'active' : ''; ?>" onclick="filterType('cars')">
        Cars <span class="count">(<?php echo $searchStats['cars']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'attractions' ? 'active' : ''; ?>" onclick="filterType('attractions')">
        Experiences <span class="count">(<?php echo $searchStats['attractions']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'restaurants' ? 'active' : ''; ?>" onclick="filterType('restaurants')">
        Restaurants <span class="count">(<?php echo $searchStats['restaurants']; ?>)</span>
    </div>
    <div class="stat-pill <?php echo $type == 'reviews' ? 'active' : ''; ?>" onclick="filterType('reviews')">
        Reviews <span class="count">(<?php echo $searchStats['reviews']; ?>)</span>
    </div>
</div>

<!-- Results -->
<div class="results-header">
    <div class="results-count">
        Found <strong><?php echo $totalResults; ?></strong> result<?php echo $totalResults != 1 ? 's' : ''; ?> 
        for "<strong><?php echo htmlspecialchars($query); ?></strong>"
    </div>
</div>

<?php if (empty($results)): ?>
<div class="empty-state">
    <i class="bi bi-search"></i>
    <h3>No results found</h3>
    <p>Try adjusting your search terms or filters</p>
</div>
<?php else: ?>
    <?php foreach ($results as $result): ?>
        <?php if ($result['result_type'] == 'booking'): ?>
        <!-- Booking Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge booking">
                    <i class="bi bi-calendar-check"></i> Booking
                </div>
                <div class="result-status status-<?php echo $result['status']; ?>">
                    <i class="bi bi-<?php echo $result['status'] == 'confirmed' ? 'check-circle' : ($result['status'] == 'pending' ? 'clock' : 'x-circle'); ?>"></i>
                    <?php echo ucfirst($result['status']); ?>
                </div>
            </div>
            <div class="result-title">
                <a href="bookings.php?view=<?php echo $result['booking_id']; ?>">
                    #<?php echo $result['booking_reference']; ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['item_name'] ?? 'N/A'); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-person"></i> <?php echo sanitize($result['subtitle']); ?></span>
                <span><i class="bi bi-envelope"></i> <?php echo sanitize($result['user_email']); ?></span>
                <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($result['date'])); ?></span>
                <span><i class="bi bi-cash-stack"></i> <?php echo formatPrice($result['total_amount']); ?></span>
            </div>
            <div class="result-actions">
                <a href="bookings.php?view=<?php echo $result['booking_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <a href="users.php?view=<?php echo $result['user_id']; ?>" class="action-link">
                    <i class="bi bi-person"></i> View Guest
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'user'): ?>
        <!-- User Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge user">
                    <i class="bi bi-person"></i> <?php echo $result['user_type'] == 'business_owner' ? 'Partner' : 'Guest'; ?>
                </div>
                <div class="result-status <?php echo $result['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                    <i class="bi bi-<?php echo $result['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                    <?php echo $result['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="users.php?view=<?php echo $result['user_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['subtitle']); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-calendar3"></i> Joined <?php echo date('M d, Y', strtotime($result['date'])); ?></span>
                <span><i class="bi bi-bookmark"></i> <?php echo $result['booking_count']; ?> bookings</span>
            </div>
            <div class="result-actions">
                <a href="users.php?view=<?php echo $result['user_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Profile
                </a>
                <a href="bookings.php?user=<?php echo $result['user_id']; ?>" class="action-link">
                    <i class="bi bi-calendar-check"></i> View Bookings
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'stay'): ?>
        <!-- Stay Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge stay">
                    <i class="bi bi-building"></i> Stay
                </div>
                <div>
                    <?php if ($result['is_verified']): ?>
                    <span class="result-status status-verified">
                        <i class="bi bi-shield-check"></i> Verified
                    </span>
                    <?php else: ?>
                    <span class="result-status status-pending">
                        <i class="bi bi-clock"></i> Pending
                    </span>
                    <?php endif; ?>
                    <?php if (!$result['is_active']): ?>
                    <span class="result-status status-inactive">
                        <i class="bi bi-x-circle"></i> Inactive
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="stays.php?view=<?php echo $result['stay_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
                <?php if ($result['star_rating'] > 0): ?>
                <span class="rating-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $result['star_rating'] ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['subtitle']); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($result['location_name'] ?? 'N/A'); ?></span>
                <span><i class="bi bi-person-badge"></i> <?php echo sanitize($result['owner_first'] . ' ' . $result['owner_last']); ?></span>
                <span><i class="bi bi-calendar3"></i> Added <?php echo date('M d, Y', strtotime($result['date'])); ?></span>
            </div>
            <div class="result-actions">
                <a href="stays.php?view=<?php echo $result['stay_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <a href="stays.php?edit=<?php echo $result['stay_id']; ?>" class="action-link">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <a href="users.php?view=<?php echo $result['owner_id']; ?>" class="action-link">
                    <i class="bi bi-person"></i> View Owner
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'car'): ?>
        <!-- Car Rental Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge car">
                    <i class="bi bi-car-front"></i> Car Rental
                </div>
                <div>
                    <?php if ($result['is_verified']): ?>
                    <span class="result-status status-verified">
                        <i class="bi bi-shield-check"></i> Verified
                    </span>
                    <?php else: ?>
                    <span class="result-status status-pending">
                        <i class="bi bi-clock"></i> Pending
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="cars.php?view=<?php echo $result['rental_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['subtitle']); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($result['location_name'] ?? 'N/A'); ?></span>
                <span><i class="bi bi-car-front"></i> <?php echo $result['fleet_count']; ?> vehicles</span>
                <span><i class="bi bi-person-badge"></i> <?php echo sanitize($result['owner_first'] . ' ' . $result['owner_last']); ?></span>
            </div>
            <div class="result-actions">
                <a href="cars.php?view=<?php echo $result['rental_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <a href="cars.php?edit=<?php echo $result['rental_id']; ?>" class="action-link">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <a href="users.php?view=<?php echo $result['owner_id']; ?>" class="action-link">
                    <i class="bi bi-person"></i> View Owner
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'attraction'): ?>
        <!-- Attraction Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge attraction">
                    <i class="bi bi-ticket-perforated"></i> Experience
                </div>
                <div>
                    <?php if ($result['is_verified']): ?>
                    <span class="result-status status-verified">
                        <i class="bi bi-shield-check"></i> Verified
                    </span>
                    <?php else: ?>
                    <span class="result-status status-pending">
                        <i class="bi bi-clock"></i> Pending
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="attractions.php?view=<?php echo $result['attraction_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['subtitle']); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-tag"></i> <?php echo sanitize($result['category_name'] ?? 'Uncategorized'); ?></span>
                <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($result['location_name'] ?? 'N/A'); ?></span>
                <span><i class="bi bi-star"></i> <?php echo $result['avg_rating']; ?> / 5</span>
            </div>
            <div class="result-actions">
                <a href="attractions.php?view=<?php echo $result['attraction_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <a href="attractions.php?edit=<?php echo $result['attraction_id']; ?>" class="action-link">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <a href="users.php?view=<?php echo $result['owner_id']; ?>" class="action-link">
                    <i class="bi bi-person"></i> View Owner
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'restaurant'): ?>
        <!-- Restaurant Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge restaurant">
                    <i class="bi bi-shop"></i> Restaurant
                </div>
                <div class="result-status <?php echo $result['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                    <i class="bi bi-<?php echo $result['is_active'] ? 'check-circle' : 'x-circle'; ?>"></i>
                    <?php echo $result['is_active'] ? 'Active' : 'Inactive'; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="restaurants.php?view=<?php echo $result['restaurant_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize($result['subtitle']); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-building"></i> <?php echo sanitize($result['hotel_name']); ?></span>
                <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($result['location_name'] ?? 'N/A'); ?></span>
                <span><i class="bi bi-calendar3"></i> Added <?php echo date('M d, Y', strtotime($result['date'])); ?></span>
            </div>
            <div class="result-actions">
                <a href="restaurants.php?view=<?php echo $result['restaurant_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <a href="stays.php?view=<?php echo $result['stay_id']; ?>" class="action-link">
                    <i class="bi bi-building"></i> View Hotel
                </a>
            </div>
        </div>
        
        <?php elseif ($result['result_type'] == 'review'): ?>
        <!-- Review Result -->
        <div class="result-card">
            <div class="result-header">
                <div class="result-badge review">
                    <i class="bi bi-star"></i> Review
                </div>
                <div class="rating-stars">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star-fill <?php echo $i <= $result['rating'] ? '' : 'empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="result-title">
                <a href="reviews.php?view=<?php echo $result['review_id']; ?>">
                    <?php echo sanitize($result['title']); ?>
                </a>
            </div>
            <div class="result-subtitle">
                <?php echo sanitize(substr($result['subtitle'], 0, 100)) . (strlen($result['subtitle']) > 100 ? '...' : ''); ?>
            </div>
            <div class="result-meta">
                <span><i class="bi bi-person"></i> <?php echo sanitize($result['user_first'] . ' ' . $result['user_last']); ?></span>
                <span><i class="bi bi-tag"></i> <?php echo ucfirst($result['review_type']); ?></span>
                <span><i class="bi bi-building"></i> <?php echo sanitize($result['item_name']); ?></span>
                <span><i class="bi bi-calendar3"></i> <?php echo date('M d, Y', strtotime($result['date'])); ?></span>
            </div>
            <div class="result-actions">
                <a href="reviews.php?view=<?php echo $result['review_id']; ?>" class="action-link">
                    <i class="bi bi-eye"></i> View Details
                </a>
                <?php if ($result['review_type'] == 'stay' && $result['item_id']): ?>
                <a href="stays.php?view=<?php echo $result['item_id']; ?>" class="action-link">
                    <i class="bi bi-building"></i> View Item
                </a>
                <?php elseif ($result['review_type'] == 'car_rental' && $result['item_id']): ?>
                <a href="cars.php?view=<?php echo $result['item_id']; ?>" class="action-link">
                    <i class="bi bi-car-front"></i> View Item
                </a>
                <?php elseif ($result['review_type'] == 'attraction' && $result['item_id']): ?>
                <a href="attractions.php?view=<?php echo $result['item_id']; ?>" class="action-link">
                    <i class="bi bi-ticket-perforated"></i> View Item
                </a>
                <?php endif; ?>
                <a href="users.php?view=<?php echo $result['user_id']; ?>" class="action-link">
                    <i class="bi bi-person"></i> View User
                </a>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&page=<?php echo $page - 1; ?>" class="page-link">
            <i class="bi bi-chevron-left"></i>
        </a>
        <?php endif; ?>
        
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
        <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&page=<?php echo $i; ?>" 
           class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?q=<?php echo urlencode($query); ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&page=<?php echo $page + 1; ?>" class="page-link">
            <i class="bi bi-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
<?php endif; ?>
<?php endif; ?>

<script>
function filterType(type) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('type', type);
    urlParams.set('page', '1');
    window.location.search = urlParams.toString();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>