<?php
$pageTitle = 'Reports & Exports';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$reportType = isset($_GET['report_type']) ? sanitize($_GET['report_type']) : 'financial';
$dateRange = isset($_GET['date_range']) ? sanitize($_GET['date_range']) : 'custom';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? sanitize($_GET['format']) : 'preview';

// ============================================
// GET PROPERTIES
// ============================================
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one or 'all'
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Build property filter for queries
$propertyFilter = "";
$propertyParams = [];
if ($propertyId > 0) {
    $propertyFilter = "AND s.stay_id = ?";
    $propertyParams[] = $propertyId;
}

// ============================================
// GENERATE REPORT DATA BASED ON TYPE
// ============================================

$reportData = [];
$reportTitle = '';
$reportHeaders = [];
$reportSummary = [];

switch ($reportType) {
    // ============================================
    // FINANCIAL REPORT
    // ============================================
    case 'financial':
        $reportTitle = 'Financial Report';
        $reportHeaders = ['Date', 'Booking Ref', 'Property', 'Room', 'Guest', 'Nights', 'Guests', 'Amount', 'Commission', 'Tax', 'Total', 'Status'];
        
        $stmt = $db->prepare("
            SELECT 
                DATE(b.created_at) as date,
                b.booking_reference,
                s.stay_name as property,
                sr.room_name,
                CONCAT(b.guest_first_name, ' ', b.guest_last_name) as guest,
                b.num_nights,
                b.num_guests,
                b.unit_price as room_rate,
                b.commission_amount,
                b.tax_amount,
                b.total_amount,
                b.status,
                b.payment_status,
                b.payment_method
            FROM bookings b
            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
            JOIN stays s ON sr.stay_id = s.stay_id
            WHERE s.owner_id = ?
            AND DATE(b.created_at) BETWEEN ? AND ?
            $propertyFilter
            ORDER BY b.created_at DESC
        ");
        $params = array_merge([$userId, $startDate, $endDate], $propertyParams);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        // Calculate summary
        $totalRevenue = 0;
        $totalCommission = 0;
        $totalTax = 0;
        $totalBookings = count($reportData);
        $totalNights = 0;
        $totalGuests = 0;
        
        foreach ($reportData as $row) {
            $totalRevenue += $row['total_amount'];
            $totalCommission += $row['commission_amount'];
            $totalTax += $row['tax_amount'];
            $totalNights += $row['num_nights'];
            $totalGuests += $row['num_guests'];
        }
        
        $reportSummary = [
            'Total Bookings' => $totalBookings,
            'Total Revenue' => formatPrice($totalRevenue),
            'Total Commission' => formatPrice($totalCommission),
            'Total Tax' => formatPrice($totalTax),
            'Average Booking' => $totalBookings > 0 ? formatPrice($totalRevenue / $totalBookings) : 'RWF 0',
            'Total Nights' => $totalNights,
            'Total Guests' => $totalGuests,
            'Avg Nightly Rate' => $totalNights > 0 ? formatPrice($totalRevenue / $totalNights) : 'RWF 0'
        ];
        break;
    
    // ============================================
    // BOOKINGS REPORT
    // ============================================
    case 'bookings':
        $reportTitle = 'Bookings Report';
        $reportHeaders = ['Booking Ref', 'Property', 'Room', 'Guest', 'Check In', 'Check Out', 'Nights', 'Guests', 'Status', 'Payment', 'Amount', 'Created'];
        
        $stmt = $db->prepare("
            SELECT 
                b.booking_reference,
                s.stay_name as property,
                sr.room_name,
                CONCAT(b.guest_first_name, ' ', b.guest_last_name) as guest,
                b.guest_email,
                b.guest_phone,
                b.check_in_date,
                b.check_out_date,
                b.num_nights,
                b.num_guests,
                b.status,
                b.payment_status,
                b.payment_method,
                b.total_amount,
                b.created_at
            FROM bookings b
            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
            JOIN stays s ON sr.stay_id = s.stay_id
            WHERE s.owner_id = ?
            AND DATE(b.created_at) BETWEEN ? AND ?
            $propertyFilter
            ORDER BY b.created_at DESC
        ");
        $params = array_merge([$userId, $startDate, $endDate], $propertyParams);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        // Calculate summary
        $totalBookings = count($reportData);
        $confirmedBookings = 0;
        $pendingBookings = 0;
        $cancelledBookings = 0;
        $completedBookings = 0;
        $totalRevenue = 0;
        
        foreach ($reportData as $row) {
            $totalRevenue += $row['total_amount'];
            switch ($row['status']) {
                case 'confirmed': $confirmedBookings++; break;
                case 'pending': $pendingBookings++; break;
                case 'cancelled': $cancelledBookings++; break;
                case 'completed': $completedBookings++; break;
            }
        }
        
        $reportSummary = [
            'Total Bookings' => $totalBookings,
            'Confirmed' => $confirmedBookings,
            'Pending' => $pendingBookings,
            'Cancelled' => $cancelledBookings,
            'Completed' => $completedBookings,
            'Total Revenue' => formatPrice($totalRevenue),
            'Avg per Booking' => $totalBookings > 0 ? formatPrice($totalRevenue / $totalBookings) : 'RWF 0'
        ];
        break;
    
    // ============================================
    // OCCUPANCY REPORT
    // ============================================
    case 'occupancy':
        $reportTitle = 'Occupancy Report';
        $reportHeaders = ['Date', 'Property', 'Total Rooms', 'Booked Rooms', 'Occupancy %', 'Revenue', 'Guests', 'Nights'];
        
        // Generate daily occupancy for date range
        $occupancyData = [];
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            
            $stmt = $db->prepare("
                SELECT 
                    s.stay_id,
                    s.stay_name,
                    COUNT(DISTINCT sr.room_id) as total_rooms,
                    COUNT(DISTINCT b.stay_room_id) as booked_rooms,
                    COALESCE(SUM(b.total_amount), 0) as revenue,
                    COALESCE(SUM(b.num_guests), 0) as guests,
                    COUNT(DISTINCT b.booking_id) as bookings
                FROM stays s
                LEFT JOIN stay_rooms sr ON s.stay_id = sr.stay_id
                LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
                    AND b.status IN ('confirmed', 'checked_in')
                    AND ? BETWEEN b.check_in_date AND DATE_SUB(b.check_out_date, INTERVAL 1 DAY)
                WHERE s.owner_id = ?
                $propertyFilter
                GROUP BY s.stay_id
            ");
            $params = array_merge([$dateStr, $userId], $propertyParams);
            $stmt->execute($params);
            $dayData = $stmt->fetchAll();
            
            foreach ($dayData as $data) {
                $occupancyRate = $data['total_rooms'] > 0 ? round(($data['booked_rooms'] / $data['total_rooms']) * 100, 1) : 0;
                $occupancyData[] = [
                    'date' => $dateStr,
                    'property' => $data['stay_name'],
                    'total_rooms' => $data['total_rooms'],
                    'booked_rooms' => $data['booked_rooms'],
                    'occupancy' => $occupancyRate,
                    'revenue' => $data['revenue'],
                    'guests' => $data['guests'],
                    'bookings' => $data['bookings']
                ];
            }
            
            $current->modify('+1 day');
        }
        
        $reportData = $occupancyData;
        
        // Calculate summary
        $avgOccupancy = 0;
        $totalRevenue = 0;
        $totalGuests = 0;
        $totalBookings = 0;
        $daysCount = count(array_unique(array_column($occupancyData, 'date')));
        
        foreach ($occupancyData as $row) {
            $avgOccupancy += $row['occupancy'];
            $totalRevenue += $row['revenue'];
            $totalGuests += $row['guests'];
            $totalBookings += $row['bookings'];
        }
        
        $daysCount = $daysCount ?: 1;
        $avgOccupancy = $daysCount > 0 ? round($avgOccupancy / $daysCount, 1) : 0;
        
        $reportSummary = [
            'Date Range' => date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)),
            'Avg Occupancy' => $avgOccupancy . '%',
            'Total Revenue' => formatPrice($totalRevenue),
            'Total Guests' => $totalGuests,
            'Total Bookings' => $totalBookings,
            'Days Analyzed' => $daysCount
        ];
        break;
    
    // ============================================
    // ROOM PERFORMANCE REPORT
    // ============================================
    case 'rooms':
        $reportTitle = 'Room Performance Report';
        $reportHeaders = ['Property', 'Room', 'Type', 'Base Price', 'Bookings', 'Nights', 'Revenue', 'Avg Rate', 'Occupancy %', 'Guests'];
        
        $stmt = $db->prepare("
            SELECT 
                s.stay_name as property,
                sr.room_name,
                sr.bed_configuration as room_type,
                sr.base_price,
                COUNT(DISTINCT b.booking_id) as bookings,
                COALESCE(SUM(b.num_nights), 0) as nights,
                COALESCE(SUM(b.total_amount), 0) as revenue,
                COALESCE(AVG(b.total_amount / b.num_nights), 0) as avg_rate,
                COUNT(DISTINCT b.user_id) as unique_guests,
                (SELECT COUNT(*) FROM stay_availability sa WHERE sa.room_id = sr.room_id AND sa.is_blocked = 1 AND sa.date BETWEEN ? AND ?) as blocked_days
            FROM stay_rooms sr
            JOIN stays s ON sr.stay_id = s.stay_id
            LEFT JOIN bookings b ON sr.room_id = b.stay_room_id 
                AND b.status IN ('confirmed', 'completed', 'checked_in')
                AND b.check_in_date BETWEEN ? AND ?
            WHERE s.owner_id = ?
            $propertyFilter
            GROUP BY sr.room_id
            ORDER BY revenue DESC
        ");
        $params = array_merge([$startDate, $endDate, $startDate, $endDate, $userId], $propertyParams);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        // Calculate summary
        $totalRooms = count($reportData);
        $totalBookings = 0;
        $totalRevenue = 0;
        $totalNights = 0;
        $topRoom = '';
        $topRevenue = 0;
        
        foreach ($reportData as $row) {
            $totalBookings += $row['bookings'];
            $totalRevenue += $row['revenue'];
            $totalNights += $row['nights'];
            if ($row['revenue'] > $topRevenue) {
                $topRevenue = $row['revenue'];
                $topRoom = $row['room_name'];
            }
        }
        
        $reportSummary = [
            'Total Rooms' => $totalRooms,
            'Total Bookings' => $totalBookings,
            'Total Revenue' => formatPrice($totalRevenue),
            'Total Nights' => $totalNights,
            'Avg per Room' => $totalRooms > 0 ? formatPrice($totalRevenue / $totalRooms) : 'RWF 0',
            'Top Performer' => $topRoom ?: 'N/A'
        ];
        break;
    
    // ============================================
    // CUSTOMER REPORT
    // ============================================
    case 'customers':
        $reportTitle = 'Customer Report';
        $reportHeaders = ['Customer', 'Email', 'Phone', 'Bookings', 'Total Spent', 'Avg Booking', 'Last Stay', 'First Stay'];
        
        $stmt = $db->prepare("
            SELECT 
                CONCAT(b.guest_first_name, ' ', b.guest_last_name) as customer_name,
                b.guest_email,
                b.guest_phone,
                COUNT(DISTINCT b.booking_id) as booking_count,
                SUM(b.total_amount) as total_spent,
                AVG(b.total_amount) as avg_booking,
                MAX(b.check_in_date) as last_stay,
                MIN(b.check_in_date) as first_stay,
                GROUP_CONCAT(DISTINCT s.stay_name SEPARATOR ', ') as properties_visited
            FROM bookings b
            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
            JOIN stays s ON sr.stay_id = s.stay_id
            WHERE s.owner_id = ?
            AND DATE(b.created_at) BETWEEN ? AND ?
            $propertyFilter
            GROUP BY b.guest_email
            ORDER BY total_spent DESC
        ");
        $params = array_merge([$userId, $startDate, $endDate], $propertyParams);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        // Calculate summary
        $totalCustomers = count($reportData);
        $totalRevenue = 0;
        $totalBookings = 0;
        $repeatCustomers = 0;
        
        foreach ($reportData as $row) {
            $totalRevenue += $row['total_spent'];
            $totalBookings += $row['booking_count'];
            if ($row['booking_count'] > 1) {
                $repeatCustomers++;
            }
        }
        
        $reportSummary = [
            'Total Customers' => $totalCustomers,
            'Total Revenue' => formatPrice($totalRevenue),
            'Total Bookings' => $totalBookings,
            'Repeat Customers' => $repeatCustomers,
            'Repeat Rate' => $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 1) . '%' : '0%',
            'Avg per Customer' => $totalCustomers > 0 ? formatPrice($totalRevenue / $totalCustomers) : 'RWF 0'
        ];
        break;
    
    // ============================================
    // TAX REPORT
    // ============================================
    case 'tax':
        $reportTitle = 'Tax Report';
        $reportHeaders = ['Date', 'Booking Ref', 'Property', 'Guest', 'Amount', 'Tax Rate', 'Tax Amount', 'Total'];
        
        $stmt = $db->prepare("
            SELECT 
                DATE(b.created_at) as date,
                b.booking_reference,
                s.stay_name as property,
                CONCAT(b.guest_first_name, ' ', b.guest_last_name) as guest,
                (b.total_amount - b.tax_amount - b.commission_amount) as subtotal,
                b.commission_amount,
                b.tax_amount,
                b.total_amount,
                ROUND((b.tax_amount / (b.total_amount - b.tax_amount)) * 100, 1) as effective_tax_rate
            FROM bookings b
            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
            JOIN stays s ON sr.stay_id = s.stay_id
            WHERE s.owner_id = ?
            AND DATE(b.created_at) BETWEEN ? AND ?
            $propertyFilter
            ORDER BY b.created_at DESC
        ");
        $params = array_merge([$userId, $startDate, $endDate], $propertyParams);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        // Calculate summary
        $totalRevenue = 0;
        $totalTax = 0;
        $totalCommission = 0;
        $totalSubtotal = 0;
        
        foreach ($reportData as $row) {
            $totalRevenue += $row['total_amount'];
            $totalTax += $row['tax_amount'];
            $totalCommission += $row['commission_amount'];
            $totalSubtotal += $row['subtotal'];
        }
        
        $reportSummary = [
            'Total Revenue' => formatPrice($totalRevenue),
            'Net Revenue' => formatPrice($totalSubtotal),
            'Total Tax' => formatPrice($totalTax),
            'Total Commission' => formatPrice($totalCommission),
            'Avg Tax Rate' => $totalSubtotal > 0 ? round(($totalTax / $totalSubtotal) * 100, 1) . '%' : '0%',
            'Net after Tax' => formatPrice($totalSubtotal - $totalTax)
        ];
        break;
}

// ============================================
// HANDLE EXPORT
// ============================================
if ($format === 'csv' && !empty($reportData)) {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $reportTitle)) . '_' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, $reportHeaders);
    
    // Add data rows
    foreach ($reportData as $row) {
        $csvRow = [];
        foreach ($reportHeaders as $header) {
            $key = strtolower(str_replace(' ', '_', $header));
            $key = str_replace(['%', '/'], '', $key);
            $key = preg_replace('/[^a-z_]/', '', $key);
            
            // Handle special cases
            if (isset($row[$key])) {
                $value = $row[$key];
            } elseif ($header == 'Date' && isset($row['date'])) {
                $value = $row['date'];
            } elseif ($header == 'Property' && isset($row['property'])) {
                $value = $row['property'];
            } elseif ($header == 'Room' && isset($row['room_name'])) {
                $value = $row['room_name'];
            } elseif ($header == 'Guest' && isset($row['guest'])) {
                $value = $row['guest'];
            } elseif ($header == 'Amount' && isset($row['total_amount'])) {
                $value = $row['total_amount'];
            } elseif ($header == 'Status' && isset($row['status'])) {
                $value = $row['status'];
            } else {
                // Try to find matching key
                $found = false;
                foreach ($row as $rowKey => $rowValue) {
                    if (strpos($header, $rowKey) !== false || strpos($rowKey, $header) !== false) {
                        $value = $rowValue;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $value = '';
                }
            }
            
            // Format currency if needed
            if (strpos($header, 'Price') !== false || strpos($header, 'Revenue') !== false || 
                strpos($header, 'Amount') !== false || strpos($header, 'Spent') !== false ||
                strpos($header, 'Rate') !== false) {
                if (is_numeric($value)) {
                    $value = number_format($value, 0);
                }
            }
            
            $csvRow[] = $value;
        }
        fputcsv($output, $csvRow);
    }
    
    // Add summary section
    fputcsv($output, []); // Empty row
    fputcsv($output, ['SUMMARY']);
    foreach ($reportSummary as $key => $value) {
        fputcsv($output, [$key, $value]);
    }
    
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    // In a real application, you would use a PDF library like DOMPDF, TCPDF, etc.
    // For now, we'll just show a message
    $pdfMessage = "PDF export would be generated here. In production, integrate with a PDF library.";
}
?>

<style>
/* Reports Specific Styles */
.reports-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.reports-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.reports-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
    margin-bottom: 24px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
}

.filter-group .filter-value {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--booking-text);
    padding: 8px 12px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
    border: 1px solid var(--booking-border);
}

.filter-select, .filter-input {
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

.filter-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    justify-content: flex-end;
}

/* Report Type Cards */
.report-types {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.report-type-card {
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.report-type-card:hover {
    border-color: var(--booking-blue);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.report-type-card.active {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
}

.report-type-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    margin: 0 auto 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.report-type-icon.financial { background: #e6f4ea; color: var(--booking-success); }
.report-type-icon.bookings { background: var(--booking-light-blue); color: var(--booking-blue); }
.report-type-icon.occupancy { background: #fff4e6; color: var(--booking-warning); }
.report-type-icon.rooms { background: #f3e8ff; color: #9333ea; }
.report-type-icon.customers { background: #fee2e2; color: var(--booking-danger); }
.report-type-icon.tax { background: #e0e7ff; color: #4f46e5; }

.report-type-name {
    font-weight: 600;
    font-size: 0.9375rem;
    margin-bottom: 4px;
}

.report-type-desc {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.summary-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 16px;
    border: 1px solid var(--booking-border);
    display: flex;
    align-items: center;
    gap: 12px;
}

.summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.summary-icon.blue { background: var(--booking-light-blue); color: var(--booking-blue); }
.summary-icon.green { background: #e6f4ea; color: var(--booking-success); }
.summary-icon.orange { background: #fff4e6; color: var(--booking-warning); }
.summary-icon.purple { background: #f3e8ff; color: #9333ea; }

.summary-content h4 {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-bottom: 2px;
}

.summary-content .value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

/* Report Table */
.report-table-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    margin-bottom: 24px;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}

.report-table th {
    text-align: left;
    padding: 14px 16px;
    background: var(--booking-gray);
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    font-size: 0.6875rem;
    border-bottom: 1px solid var(--booking-border);
    white-space: nowrap;
}

.report-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    white-space: nowrap;
}

.report-table tr:hover td {
    background: var(--booking-light-blue);
}

.report-table .amount {
    font-weight: 600;
    color: var(--booking-success);
}

.report-table .status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.report-table-container .table-wrapper {
    overflow-x: auto;
    max-width: 100%;
}

/* Export Options */
.export-options {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}

.export-btn {
    padding: 10px 20px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.export-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.export-btn.csv:hover {
    background: #e6f4ea;
    border-color: var(--booking-success);
    color: var(--booking-success);
}

.export-btn.pdf:hover {
    background: #fce8e8;
    border-color: var(--booking-danger);
    color: var(--booking-danger);
}

.export-btn.print:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

/* Date Range Quick Picks */
.date-quick-picks {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.quick-pick {
    padding: 6px 12px;
    border: 1px solid var(--booking-border);
    border-radius: 100px;
    background: white;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}

.quick-pick:hover {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
    color: var(--booking-blue);
}

/* Responsive */
@media (max-width: 1200px) {
    .filter-grid,
    .report-types,
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .filter-grid,
    .report-types,
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        flex-direction: column;
    }
    
    .filter-actions button {
        width: 100%;
    }
    
    .export-options {
        flex-direction: column;
    }
    
    .export-btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="reports-header">
    <div class="reports-title">
        <h1>Reports & Exports</h1>
        <p>Generate and download detailed business reports</p>
    </div>
</div>

<!-- Report Type Cards -->
<div class="report-types">
    <div class="report-type-card <?php echo $reportType == 'financial' ? 'active' : ''; ?>" onclick="setReportType('financial')">
        <div class="report-type-icon financial">
            <i class="bi bi-cash-stack"></i>
        </div>
        <div class="report-type-name">Financial Report</div>
        <div class="report-type-desc">Revenue, commissions, taxes</div>
    </div>
    
    <div class="report-type-card <?php echo $reportType == 'bookings' ? 'active' : ''; ?>" onclick="setReportType('bookings')">
        <div class="report-type-icon bookings">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="report-type-name">Bookings Report</div>
        <div class="report-type-desc">All bookings with details</div>
    </div>
    
    <div class="report-type-card <?php echo $reportType == 'occupancy' ? 'active' : ''; ?>" onclick="setReportType('occupancy')">
        <div class="report-type-icon occupancy">
            <i class="bi bi-door-open"></i>
        </div>
        <div class="report-type-name">Occupancy Report</div>
        <div class="report-type-desc">Daily occupancy rates</div>
    </div>
    
    <div class="report-type-card <?php echo $reportType == 'rooms' ? 'active' : ''; ?>" onclick="setReportType('rooms')">
        <div class="report-type-icon rooms">
            <i class="bi bi-grid"></i>
        </div>
        <div class="report-type-name">Room Performance</div>
        <div class="report-type-desc">Room-wise analytics</div>
    </div>
    
    <div class="report-type-card <?php echo $reportType == 'customers' ? 'active' : ''; ?>" onclick="setReportType('customers')">
        <div class="report-type-icon customers">
            <i class="bi bi-people"></i>
        </div>
        <div class="report-type-name">Customer Report</div>
        <div class="report-type-desc">Guest insights</div>
    </div>
    
    <div class="report-type-card <?php echo $reportType == 'tax' ? 'active' : ''; ?>" onclick="setReportType('tax')">
        <div class="report-type-icon tax">
            <i class="bi bi-receipt"></i>
        </div>
        <div class="report-type-name">Tax Report</div>
        <div class="report-type-desc">Tax summary</div>
    </div>
</div>

<!-- Filter Card -->
<form method="GET" action="reports.php" id="reportForm">
    <div class="filter-card">
        <div class="filter-grid">
            <input type="hidden" name="report_type" id="report_type" value="<?php echo $reportType; ?>">
            <input type="hidden" name="format" id="format" value="preview">
            
            <div class="filter-group">
                <label>Property</label>
                <select name="property" class="filter-select" onchange="this.form.submit()">
                    <option value="0">All Properties</option>
                    <?php foreach ($properties as $prop): ?>
                    <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
                        <?php echo sanitize($prop['stay_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Date Range</label>
                <select name="date_range" class="filter-select" onchange="toggleCustomDates(this.value)">
                    <option value="custom" <?php echo $dateRange == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    <option value="today" <?php echo $dateRange == 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="yesterday" <?php echo $dateRange == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                    <option value="this_week" <?php echo $dateRange == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="last_week" <?php echo $dateRange == 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                    <option value="this_month" <?php echo $dateRange == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="last_month" <?php echo $dateRange == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                    <option value="this_quarter" <?php echo $dateRange == 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                    <option value="last_quarter" <?php echo $dateRange == 'last_quarter' ? 'selected' : ''; ?>>Last Quarter</option>
                    <option value="this_year" <?php echo $dateRange == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="last_year" <?php echo $dateRange == 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                </select>
            </div>
            
            <div class="filter-group" id="start_date_group">
                <label>Start Date</label>
                <input type="date" name="start_date" class="filter-input" value="<?php echo $startDate; ?>">
            </div>
            
            <div class="filter-group" id="end_date_group">
                <label>End Date</label>
                <input type="date" name="end_date" class="filter-input" value="<?php echo $endDate; ?>">
            </div>
        </div>
        
        <!-- Quick Date Picks -->
        <div class="date-quick-picks">
            <span class="quick-pick" onclick="setDateRange('today')">Today</span>
            <span class="quick-pick" onclick="setDateRange('yesterday')">Yesterday</span>
            <span class="quick-pick" onclick="setDateRange('this_week')">This Week</span>
            <span class="quick-pick" onclick="setDateRange('last_week')">Last Week</span>
            <span class="quick-pick" onclick="setDateRange('this_month')">This Month</span>
            <span class="quick-pick" onclick="setDateRange('last_month')">Last Month</span>
            <span class="quick-pick" onclick="setDateRange('this_quarter')">This Quarter</span>
            <span class="quick-pick" onclick="setDateRange('last_quarter')">Last Quarter</span>
            <span class="quick-pick" onclick="setDateRange('this_year')">This Year</span>
            <span class="quick-pick" onclick="setDateRange('last_year')">Last Year</span>
        </div>
        
        <div class="filter-actions">
            <button type="submit" class="btn-primary">
                <i class="bi bi-search"></i> Generate Report
            </button>
            <button type="button" class="btn-secondary" onclick="resetFilters()">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
        </div>
    </div>
</form>

<?php if (!empty($reportData)): ?>

<!-- Summary Cards -->
<div class="summary-grid">
    <?php foreach ($reportSummary as $key => $value): 
        $icon = 'bi-file-text';
        $color = 'blue';
        if (strpos($key, 'Revenue') !== false) { $icon = 'bi-cash-stack'; $color = 'green'; }
        elseif (strpos($key, 'Booking') !== false) { $icon = 'bi-calendar-check'; $color = 'blue'; }
        elseif (strpos($key, 'Occupancy') !== false) { $icon = 'bi-door-open'; $color = 'orange'; }
        elseif (strpos($key, 'Customer') !== false) { $icon = 'bi-people'; $color = 'purple'; }
        elseif (strpos($key, 'Tax') !== false) { $icon = 'bi-receipt'; $color = 'orange'; }
        elseif (strpos($key, 'Rate') !== false) { $icon = 'bi-percent'; $color = 'purple'; }
    ?>
    <div class="summary-card">
        <div class="summary-icon <?php echo $color; ?>">
            <i class="bi <?php echo $icon; ?>"></i>
        </div>
        <div class="summary-content">
            <h4><?php echo $key; ?></h4>
            <div class="value"><?php echo $value; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Report Table -->
<div class="report-table-container">
    <div class="table-wrapper">
        <table class="report-table">
            <thead>
                <tr>
                    <?php foreach ($reportHeaders as $header): ?>
                    <th><?php echo $header; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <?php foreach ($reportHeaders as $header): 
                        $key = strtolower(str_replace(' ', '_', $header));
                        $key = str_replace(['%', '/'], '', $key);
                        $key = preg_replace('/[^a-z_]/', '', $key);
                        
                        // Handle special cases
                        if (isset($row[$key])) {
                            $value = $row[$key];
                        } elseif ($header == 'Date' && isset($row['date'])) {
                            $value = $row['date'];
                        } elseif ($header == 'Property' && isset($row['property'])) {
                            $value = $row['property'];
                        } elseif ($header == 'Room' && isset($row['room_name'])) {
                            $value = $row['room_name'];
                        } elseif ($header == 'Guest' && isset($row['guest'])) {
                            $value = $row['guest'];
                        } elseif ($header == 'Amount' && isset($row['total_amount'])) {
                            $value = $row['total_amount'];
                        } elseif ($header == 'Status' && isset($row['status'])) {
                            $value = $row['status'];
                        } else {
                            // Try to find matching key
                            $found = false;
                            foreach ($row as $rowKey => $rowValue) {
                                if (strpos($header, $rowKey) !== false || strpos($rowKey, $header) !== false) {
                                    $value = $rowValue;
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $value = '-';
                            }
                        }
                        
                        // Format values
                        if (strpos($header, 'Date') !== false && $value != '-' && $value) {
                            if (strlen($value) == 10) {
                                $value = date('M d, Y', strtotime($value));
                            }
                        }
                        
                        if (strpos($header, 'Price') !== false || strpos($header, 'Revenue') !== false || 
                            strpos($header, 'Amount') !== false || strpos($header, 'Spent') !== false ||
                            strpos($header, 'Rate') !== false) {
                            if (is_numeric($value)) {
                                $value = 'RWF ' . number_format($value, 0);
                                echo '<td class="amount">' . $value . '</td>';
                            } else {
                                echo '<td>' . $value . '</td>';
                            }
                        } elseif ($header == 'Status' || $header == 'Payment Status') {
                            $statusClass = '';
                            if ($value == 'confirmed' || $value == 'paid') $statusClass = 'status-confirmed';
                            elseif ($value == 'pending') $statusClass = 'status-pending';
                            elseif ($value == 'cancelled') $statusClass = 'status-cancelled';
                            elseif ($value == 'completed') $statusClass = 'status-completed';
                            echo '<td><span class="status-badge ' . $statusClass . '">' . ucfirst($value) . '</span></td>';
                        } elseif ($header == 'Occupancy %' && is_numeric($value)) {
                            echo '<td>' . $value . '%</td>';
                        } else {
                            echo '<td>' . $value . '</td>';
                        }
                    endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Export Options -->
<div class="export-options">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'csv'])); ?>" class="export-btn csv">
        <i class="bi bi-file-earmark-spreadsheet"></i> Export as CSV
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['format' => 'pdf'])); ?>" class="export-btn pdf">
        <i class="bi bi-file-earmark-pdf"></i> Export as PDF
    </a>
    <button class="export-btn print" onclick="window.print()">
        <i class="bi bi-printer"></i> Print Report
    </button>
</div>

<?php elseif ($format == 'preview'): ?>
<div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--booking-border);">
    <i class="bi bi-file-earmark-text" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
    <h3 style="margin-top: 16px; font-size: 1.125rem;">No data available</h3>
    <p style="color: var(--booking-text-light); margin-top: 8px;">Try adjusting your filters or date range.</p>
</div>
<?php endif; ?>

<?php if (isset($pdfMessage)): ?>
<div style="margin-top: 20px; padding: 16px; background: #e6f4ea; border-radius: var(--radius-sm); color: var(--booking-success);">
    <i class="bi bi-info-circle"></i> <?php echo $pdfMessage; ?>
</div>
<?php endif; ?>

<script>
// ============================================
// REPORT TYPE FUNCTIONS
// ============================================
function setReportType(type) {
    document.getElementById('report_type').value = type;
    document.getElementById('reportForm').submit();
}

// ============================================
// DATE RANGE FUNCTIONS
// ============================================
function toggleCustomDates(range) {
    const startGroup = document.getElementById('start_date_group');
    const endGroup = document.getElementById('end_date_group');
    
    if (range === 'custom') {
        startGroup.style.display = 'block';
        endGroup.style.display = 'block';
    } else {
        startGroup.style.display = 'none';
        endGroup.style.display = 'none';
    }
}

function setDateRange(range) {
    const dateRangeSelect = document.querySelector('select[name="date_range"]');
    dateRangeSelect.value = range;
    toggleCustomDates(range);
    
    // Calculate dates based on range
    const today = new Date();
    let startDate, endDate;
    
    switch(range) {
        case 'today':
            startDate = formatDate(today);
            endDate = formatDate(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            startDate = formatDate(yesterday);
            endDate = formatDate(yesterday);
            break;
        case 'this_week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay());
            startDate = formatDate(weekStart);
            endDate = formatDate(today);
            break;
        case 'last_week':
            const lastWeekStart = new Date(today);
            lastWeekStart.setDate(today.getDate() - today.getDay() - 7);
            const lastWeekEnd = new Date(lastWeekStart);
            lastWeekEnd.setDate(lastWeekStart.getDate() + 6);
            startDate = formatDate(lastWeekStart);
            endDate = formatDate(lastWeekEnd);
            break;
        case 'this_month':
            startDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            endDate = formatDate(today);
            break;
        case 'last_month':
            startDate = formatDate(new Date(today.getFullYear(), today.getMonth() - 1, 1));
            endDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 0));
            break;
        case 'this_quarter':
            const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
            startDate = formatDate(new Date(today.getFullYear(), quarterStartMonth, 1));
            endDate = formatDate(today);
            break;
        case 'last_quarter':
            const lastQuarterStartMonth = Math.floor(today.getMonth() / 3) * 3 - 3;
            startDate = formatDate(new Date(today.getFullYear(), lastQuarterStartMonth, 1));
            endDate = formatDate(new Date(today.getFullYear(), lastQuarterStartMonth + 3, 0));
            break;
        case 'this_year':
            startDate = formatDate(new Date(today.getFullYear(), 0, 1));
            endDate = formatDate(today);
            break;
        case 'last_year':
            startDate = formatDate(new Date(today.getFullYear() - 1, 0, 1));
            endDate = formatDate(new Date(today.getFullYear() - 1, 11, 31));
            break;
    }
    
    if (startDate && endDate) {
        document.querySelector('input[name="start_date"]').value = startDate;
        document.querySelector('input[name="end_date"]').value = endDate;
    }
    
    document.getElementById('reportForm').submit();
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function resetFilters() {
    window.location.href = 'reports.php';
}

// Initialize date fields visibility
document.addEventListener('DOMContentLoaded', function() {
    const dateRange = document.querySelector('select[name="date_range"]').value;
    toggleCustomDates(dateRange);
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>