<?php

/**
 * AI Insights Generator - Uses real database data to provide intelligent recommendations
 */

function getAIInsights($db)
{
    $insights = [];

    // ============================================
    // 1. REVENUE INSIGHTS
    // ============================================
    $stmt = $db->query("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END), 0) as today_revenue,
            COALESCE(SUM(CASE WHEN WEEK(created_at) = WEEK(CURDATE()) THEN total_amount ELSE 0 END), 0) as week_revenue,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) THEN total_amount ELSE 0 END), 0) as month_revenue,
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_bookings
        FROM bookings 
        WHERE status IN ('confirmed', 'completed')
    ");
    $revenue = $stmt->fetch();

    // Compare with last month
    $stmt = $db->query("
        SELECT COALESCE(SUM(total_amount), 0) as last_month_revenue
        FROM bookings 
        WHERE status IN ('confirmed', 'completed') 
        AND MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
    ");
    $lastMonth = $stmt->fetch();

    $revenueGrowth = $lastMonth['last_month_revenue'] > 0
        ? (($revenue['month_revenue'] - $lastMonth['last_month_revenue']) / $lastMonth['last_month_revenue']) * 100
        : 0;

    if ($revenueGrowth > 10) {
        $insights[] = [
            'type' => 'revenue',
            'icon' => 'graph-up',
            'title' => 'Revenue Surge!',
            'content' => "Revenue is up by " . round($revenueGrowth, 1) . "% this month compared to last month. Total revenue this month: " . formatPrice($revenue['month_revenue']),
            'action_text' => 'View Analytics',
            'action_link' => 'analytics.php',
            'priority' => 1
        ];
    } elseif ($revenue['today_revenue'] > 0) {
        $insights[] = [
            'type' => 'revenue',
            'icon' => 'cash-stack',
            'title' => 'Daily Revenue Update',
            'content' => "Today's revenue: " . formatPrice($revenue['today_revenue']) . " from " . $revenue['today_bookings'] . " bookings",
            'action_text' => 'View Details',
            'action_link' => 'bookings.php?date_from=' . date('Y-m-d'),
            'priority' => 2
        ];
    }

    // ============================================
    // 2. PENDING VERIFICATIONS
    // ============================================
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM stays WHERE is_verified = 0 AND is_active = 1) as pending_stays,
            (SELECT COUNT(*) FROM car_rentals WHERE is_verified = 0 AND is_active = 1) as pending_cars,
            (SELECT COUNT(*) FROM attractions WHERE is_verified = 0 AND is_active = 1) as pending_attractions
    ");
    $pending = $stmt->fetch();
    $totalPending = $pending['pending_stays'] + $pending['pending_cars'] + $pending['pending_attractions'];

    if ($totalPending > 0) {
        $insights[] = [
            'type' => 'verification',
            'icon' => 'shield-check',
            'title' => 'Pending Verifications',
            'content' => "$totalPending items need your attention: " .
                ($pending['pending_stays'] ? $pending['pending_stays'] . " stays" : "") .
                ($pending['pending_cars'] ? ", " . $pending['pending_cars'] . " cars" : "") .
                ($pending['pending_attractions'] ? ", " . $pending['pending_attractions'] . " experiences" : ""),
            'action_text' => 'Review Now',
            'action_link' => 'stays.php?status=pending',
            'priority' => 1
        ];
    }

    // ============================================
    // 3. TOP PERFORMING VENDORS
    // ============================================
    $stmt = $db->query("
        SELECT 
            s.stay_name as name,
            COUNT(b.booking_id) as bookings,
            COALESCE(SUM(b.total_amount), 0) as revenue
        FROM stays s
        LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
        LEFT JOIN bookings b ON sr.room_id = b.stay_room_id AND b.status IN ('confirmed', 'completed')
        GROUP BY s.stay_id
        ORDER BY revenue DESC
        LIMIT 1
    ");
    $topStay = $stmt->fetch();

    if ($topStay && $topStay['bookings'] > 0) {
        $insights[] = [
            'type' => 'performance',
            'icon' => 'trophy',
            'title' => 'Top Performer',
            'content' => "🏨 " . sanitize($topStay['name']) . " is leading with " . $topStay['bookings'] . " bookings and " . formatPrice($topStay['revenue']) . " in revenue",
            'action_text' => 'View Details',
            'action_link' => 'stay-detail.php?id=' . ($topStay['stay_id'] ?? 0),
            'priority' => 3
        ];
    }

    // ============================================
    // 4. LOW INVENTORY ALERT (Stays with few rooms)
    // ============================================
    $stmt = $db->query("
        SELECT s.stay_name, COUNT(sr.room_id) as room_count
        FROM stays s
        LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id AND sr.is_active = 1
        GROUP BY s.stay_id
        HAVING room_count <= 2
        LIMIT 3
    ");
    $lowInventory = $stmt->fetchAll();

    if (!empty($lowInventory)) {
        $names = array_column($lowInventory, 'stay_name');
        $insights[] = [
            'type' => 'inventory',
            'icon' => 'exclamation-triangle',
            'title' => 'Low Inventory Alert',
            'content' => count($lowInventory) . " properties have limited room availability: " . implode(", ", array_slice($names, 0, 2)) . (count($names) > 2 ? " and more" : ""),
            'action_text' => 'Manage Properties',
            'action_link' => 'stays.php',
            'priority' => 2
        ];
    }

    // ============================================
    // 5. BOOKING TREND (Week-over-week)
    // ============================================
    $stmt = $db->query("
        SELECT 
            COUNT(CASE WHEN WEEK(created_at) = WEEK(CURDATE()) THEN 1 END) as this_week,
            COUNT(CASE WHEN WEEK(created_at) = WEEK(DATE_SUB(NOW(), INTERVAL 1 WEEK)) THEN 1 END) as last_week
        FROM bookings 
        WHERE status IN ('confirmed', 'completed')
    ");
    $trend = $stmt->fetch();

    $bookingGrowth = $trend['last_week'] > 0
        ? (($trend['this_week'] - $trend['last_week']) / $trend['last_week']) * 100
        : 0;

    if ($bookingGrowth > 20) {
        $insights[] = [
            'type' => 'trend',
            'icon' => 'arrow-up-short',
            'title' => 'Booking Momentum',
            'content' => "Bookings increased by " . round($bookingGrowth, 1) . "% this week compared to last week",
            'action_text' => 'View Reports',
            'action_link' => 'analytics.php',
            'priority' => 2
        ];
    } elseif ($bookingGrowth < -20) {
        $insights[] = [
            'type' => 'trend',
            'icon' => 'arrow-down-short',
            'title' => 'Booking Alert',
            'content' => "Bookings decreased by " . abs(round($bookingGrowth, 1)) . "% this week. Consider running promotions.",
            'action_text' => 'View Analytics',
            'action_link' => 'analytics.php',
            'priority' => 2
        ];
    }

    // ============================================
    // 6. CANCELLATION RATE
    // ============================================
    $stmt = $db->query("
        SELECT 
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
            COUNT(*) as total
        FROM bookings 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $cancelStats = $stmt->fetch();
    $cancelRate = $cancelStats['total'] > 0 ? ($cancelStats['cancelled'] / $cancelStats['total']) * 100 : 0;

    if ($cancelRate > 15) {
        $insights[] = [
            'type' => 'warning',
            'icon' => 'exclamation-circle',
            'title' => 'High Cancellation Rate',
            'content' => "Cancellation rate is " . round($cancelRate, 1) . "% in the last 30 days. Review your cancellation policy.",
            'action_text' => 'Review Policy',
            'action_link' => 'settings.php',
            'priority' => 1
        ];
    }

    // ============================================
    // 7. UPCOMING PAYOUTS
    // ============================================
    $stmt = $db->query("
        SELECT COUNT(*) as pending_payouts, COALESCE(SUM(net_amount), 0) as pending_amount
        FROM payouts WHERE status = 'pending'
    ");
    $payouts = $stmt->fetch();

    if ($payouts['pending_payouts'] > 0) {
        $insights[] = [
            'type' => 'payout',
            'icon' => 'wallet2',
            'title' => 'Pending Payouts',
            'content' => $payouts['pending_payouts'] . " payouts pending totaling " . formatPrice($payouts['pending_amount']),
            'action_text' => 'Process Now',
            'action_link' => 'payouts.php?status=pending',
            'priority' => 2
        ];
    }

    // ============================================
    // 8. CUSTOMER ENGAGEMENT
    // ============================================
    $stmt = $db->query("
        SELECT COUNT(DISTINCT user_id) as active_users
        FROM bookings 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $engagement = $stmt->fetch();

    if ($engagement['active_users'] > 100) {
        $insights[] = [
            'type' => 'engagement',
            'icon' => 'people',
            'title' => 'Strong Engagement',
            'content' => $engagement['active_users'] . " active users in the last 30 days",
            'action_text' => 'View Users',
            'action_link' => 'users.php',
            'priority' => 3
        ];
    }

    // ============================================
    // 9. REVIEW INSIGHTS
    // ============================================
    $stmt = $db->query("
        SELECT 
            AVG(overall_rating) as avg_rating,
            COUNT(*) as review_count
        FROM reviews 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $reviews = $stmt->fetch();

    if ($reviews['review_count'] > 5) {
        $ratingText = $reviews['avg_rating'] >= 4 ? "Excellent" : ($reviews['avg_rating'] >= 3 ? "Good" : "Needs improvement");
        $insights[] = [
            'type' => 'reviews',
            'icon' => 'star',
            'title' => 'Review Summary',
            'content' => "$ratingText average rating of " . round($reviews['avg_rating'], 1) . " from " . $reviews['review_count'] . " recent reviews",
            'action_text' => 'Read Reviews',
            'action_link' => 'reviews.php',
            'priority' => 3
        ];
    }

    // ============================================
    // 10. QUICK WIN SUGGESTION
    // ============================================
    // Check for properties with high views but low bookings
    $stmt = $db->query("
        SELECT s.stay_name
        FROM stays s
        LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
        LEFT JOIN bookings b ON sr.room_id = b.stay_room_id AND b.status IN ('confirmed', 'completed')
        GROUP BY s.stay_id
        HAVING COUNT(b.booking_id) < 5
        LIMIT 1
    ");
    $underperformer = $stmt->fetch();

    if ($underperformer) {
        $insights[] = [
            'type' => 'suggestion',
            'icon' => 'lightbulb',
            'title' => 'Growth Opportunity',
            'content' => sanitize($underperformer['stay_name']) . " has potential for more bookings. Consider featuring it.",
            'action_text' => 'Promote Now',
            'action_link' => 'stays.php',
            'priority' => 3
        ];
    }

    // Sort by priority (1 = highest)
    usort($insights, function ($a, $b) {
        return $a['priority'] - $b['priority'];
    });

    return $insights;
}

// If called directly, return JSON
if (isset($_GET['ajax'])) {
    require_once dirname(__DIR__, 2) . '/includes/db.php';
    require_once dirname(__DIR__, 2) . '/includes/functions.php';
    $db = getDB();
    $insights = getAIInsights($db);
    header('Content-Type: application/json');
    echo json_encode($insights);
    exit;
}
