<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function generatePDFInvoice($bookingId)
{
    $db = getDB();

    // Get booking details
    $stmt = $db->prepare("
        SELECT 
            b.*,
            CASE 
                WHEN b.booking_type = 'stay' THEN s.stay_name
                WHEN b.booking_type = 'car_rental' THEN CONCAT(cf.brand, ' ', cf.model)
                WHEN b.booking_type = 'attraction' THEN a.attraction_name
                ELSE 'Unknown'
            END as item_name,
            CASE 
                WHEN b.booking_type = 'stay' THEN sr.room_name
                WHEN b.booking_type = 'car_rental' THEN cr.company_name
                WHEN b.booking_type = 'attraction' THEN t.tier_name
                ELSE NULL
            END as item_detail,
            u.first_name,
            u.last_name,
            u.email,
            u.phone
        FROM bookings b
        LEFT JOIN users u ON b.user_id = u.user_id
        LEFT JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
        LEFT JOIN stays s ON sr.stay_id = s.stay_id
        LEFT JOIN car_fleet cf ON b.car_id = cf.car_id
        LEFT JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        LEFT JOIN attraction_tiers t ON b.attraction_tier_id = t.tier_id
        LEFT JOIN attractions a ON t.attraction_id = a.attraction_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        return false;
    }

    // Get tax rate
    $taxRate = getTaxRate();
    $subtotal = $booking['total_amount'] - $booking['tax_amount'];

    // Generate HTML for PDF
    $html = generateInvoiceHTML($booking, $subtotal, $taxRate);

    // Configure dompdf
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF
    $dompdf->stream("Invoice_{$booking['booking_reference']}.pdf", array("Attachment" => false));
    exit;
}

function generateInvoiceHTML($booking, $subtotal, $taxRate)
{
    $typeLabels = [
        'stay' => 'Accommodation',
        'car_rental' => 'Car Rental',
        'attraction' => 'Experience'
    ];

    $date = $booking['check_in_date'] ?? $booking['pickup_date'] ?? $booking['experience_date'];
    $endDate = $booking['check_out_date'] ?? $booking['return_date'] ?? null;
    $dateFormatted = date('F j, Y', strtotime($date));
    $endDateFormatted = $endDate ? date('F j, Y', strtotime($endDate)) : null;

    $typeIcon = $booking['booking_type'] == 'stay' ? '🏨' : ($booking['booking_type'] == 'car_rental' ? '🚗' : '🎟️');

    $subtotalFormatted = formatPrice($subtotal);
    $taxFormatted = formatPrice($booking['tax_amount']);
    $totalFormatted = formatPrice($booking['total_amount']);

    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Invoice ' . $booking['booking_reference'] . '</title>
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Inter", Arial, sans-serif;
                background: #f5f7fa;
                padding: 40px;
            }
            
            .invoice-container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .invoice-header {
                background: linear-gradient(135deg, #003580 0%, #0066ff 100%);
                color: white;
                padding: 40px;
                text-align: center;
            }
            
            .logo {
                font-size: 28px;
                font-weight: 800;
                letter-spacing: -0.5px;
                margin-bottom: 10px;
            }
            
            .logo span {
                color: #febb02;
            }
            
            .invoice-title {
                font-size: 32px;
                font-weight: 700;
                margin: 20px 0 10px;
            }
            
            .invoice-subtitle {
                font-size: 14px;
                opacity: 0.9;
            }
            
            .invoice-content {
                padding: 40px;
            }
            
            .meta-section {
                display: flex;
                justify-content: space-between;
                margin-bottom: 40px;
                padding-bottom: 20px;
                border-bottom: 1px solid #e7e7e7;
            }
            
            .meta-box {
                flex: 1;
            }
            
            .meta-box h4 {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #6b6b6b;
                margin-bottom: 12px;
            }
            
            .meta-box p {
                margin: 4px 0;
                font-size: 13px;
                color: #1a1a1a;
            }
            
            .meta-box strong {
                font-weight: 700;
                color: #003580;
            }
            
            .summary-card {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 30px;
            }
            
            .summary-title {
                font-size: 16px;
                font-weight: 700;
                margin-bottom: 16px;
                display: flex;
                align-items: center;
                gap: 10px;
                color: #1a1a1a;
            }
            
            .booking-details {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            
            .booking-detail-item {
                flex: 1;
                min-width: 150px;
            }
            
            .detail-label {
                font-size: 11px;
                text-transform: uppercase;
                color: #6b6b6b;
                margin-bottom: 4px;
            }
            
            .detail-value {
                font-size: 14px;
                font-weight: 600;
                color: #1a1a1a;
            }
            
            .invoice-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
            }
            
            .invoice-table th {
                text-align: left;
                padding: 12px;
                background: #f8f9fa;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #6b6b6b;
                border-bottom: 1px solid #e7e7e7;
            }
            
            .invoice-table td {
                padding: 16px 12px;
                border-bottom: 1px solid #e7e7e7;
                font-size: 14px;
            }
            
            .text-right {
                text-align: right;
            }
            
            .totals-section {
                text-align: right;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 2px solid #e7e7e7;
            }
            
            .total-row {
                display: flex;
                justify-content: flex-end;
                gap: 40px;
                margin-bottom: 8px;
                font-size: 14px;
            }
            
            .grand-total {
                display: flex;
                justify-content: flex-end;
                gap: 40px;
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #e7e7e7;
                font-size: 18px;
                font-weight: 800;
                color: #003580;
            }
            
            .payment-status {
                margin: 30px 0;
                padding: 20px;
                background: #e6f4ea;
                border-radius: 12px;
                text-align: center;
            }
            
            .payment-status.success {
                background: #e6f4ea;
                color: #008009;
            }
            
            .invoice-footer {
                background: #f8f9fa;
                padding: 30px;
                text-align: center;
                font-size: 11px;
                color: #6b6b6b;
                border-top: 1px solid #e7e7e7;
            }
            
            hr {
                margin: 20px 0;
                border: none;
                border-top: 1px solid #e7e7e7;
            }
            
            @media print {
                body {
                    background: white;
                    padding: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <div class="invoice-header">
                <div class="logo">GoRwanda<span>+</span></div>
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-subtitle">Official Booking Invoice</div>
            </div>
            
            <div class="invoice-content">
                <div class="meta-section">
                    <div class="meta-box">
                        <h4>Billed To</h4>
                        <p><strong>' . htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) . '</strong></p>
                        <p>' . htmlspecialchars($booking['email']) . '</p>
                        <p>' . htmlspecialchars($booking['phone']) . '</p>
                    </div>
                    <div class="meta-box">
                        <h4>Invoice Details</h4>
                        <p>Invoice #: <strong>' . $booking['booking_reference'] . '</strong></p>
                        <p>Date: <strong>' . date('F j, Y') . '</strong></p>
                        <p>Booking Date: <strong>' . date('F j, Y', strtotime($booking['created_at'])) . '</strong></p>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-title">
                        <span>' . $typeIcon . '</span>
                        <span>Booking Summary</span>
                    </div>
                    <div class="booking-details">
                        <div class="booking-detail-item">
                            <div class="detail-label">Item</div>
                            <div class="detail-value">' . htmlspecialchars($booking['item_name']) . '</div>
                            <div class="detail-label" style="margin-top: 12px;">Type</div>
                            <div class="detail-value">' . $typeLabels[$booking['booking_type']] . '</div>
                        </div>
                        <div class="booking-detail-item">
                            <div class="detail-label">Dates</div>
                            <div class="detail-value">' . $dateFormatted . ($endDateFormatted ? " - " . $endDateFormatted : '') . '</div>
                            <div class="detail-label" style="margin-top: 12px;">Guests</div>
                            <div class="detail-value">' . $booking['num_guests'] . ' guest(s)</div>
                        </div>
                        <div class="booking-detail-item">
                            <div class="detail-label">Booking Reference</div>
                            <div class="detail-value" style="font-family: monospace;">' . $booking['booking_reference'] . '</div>
                            <div class="detail-label" style="margin-top: 12px;">Status</div>
                            <div class="detail-value" style="color: ' . ($booking['status'] == 'confirmed' ? '#008009' : '#d97706') . ';">
                                ' . ucfirst($booking['status']) . '
                            </div>
                        </div>
                    </div>
                </div>
                
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-right">Quantity</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>' . htmlspecialchars($booking['item_name']) . '</strong><br>
                                <span style="font-size: 12px; color: #6b6b6b;">' . htmlspecialchars($booking['item_detail'] ?? '') . '</span>
                            </td>
                            <td class="text-right">1</td>
                            <td class="text-right">' . $subtotalFormatted . '</td>
                            <td class="text-right">' . $subtotalFormatted . '</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="totals-section">
                    <div class="total-row">
                        <span>Subtotal</span>
                        <span>' . $subtotalFormatted . '</span>
                    </div>
                    <div class="total-row">
                        <span>VAT (' . $taxRate . '%)</span>
                        <span>' . $taxFormatted . '</span>
                    </div>
                    <div class="grand-total">
                        <span>Total Amount</span>
                        <span>' . $totalFormatted . '</span>
                    </div>
                </div>
                
                <div class="payment-status success">
                    <div style="font-weight: 700; margin-top: 8px;">Payment Confirmed</div>
                    <div style="font-size: 12px; margin-top: 4px;">Thank you for booking with GoRwanda+</div>
                </div>
            </div>
            
            <div class="invoice-footer">
                <p>This is a computer-generated invoice. No signature is required.</p>
                <p>For questions or support, contact us at support@gorwanda.rw or call +250 788 123 456</p>
                <hr>
                <p>GoRwanda+ Ltd - KG 7 Ave, Kigali, Rwanda</p>
            </div>
        </div>
    </body>
    </html>';

    return $html;
}
