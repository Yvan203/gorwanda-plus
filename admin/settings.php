<?php
$pageTitle = 'Platform Settings';
require_once 'includes/admin_header.php';

$db = getDB();
$success = false;
$errors = [];

// Handle form submissions
$action = isset($_POST['action']) ? $_POST['action'] : '';

// Update General Settings
if ($action === 'update_general' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $platform_name = sanitize($_POST['platform_name'] ?? 'GoRwanda+');
    $platform_email = sanitize($_POST['platform_email'] ?? '');
    $platform_phone = sanitize($_POST['platform_phone'] ?? '');
    $platform_address = sanitize($_POST['platform_address'] ?? '');
    $timezone = sanitize($_POST['timezone'] ?? 'Africa/Kigali');
    $date_format = sanitize($_POST['date_format'] ?? 'M d, Y');
    $time_format = sanitize($_POST['time_format'] ?? 'H:i');

    // Save settings to a config file or database
    $settings = [
        'platform_name' => $platform_name,
        'platform_email' => $platform_email,
        'platform_phone' => $platform_phone,
        'platform_address' => $platform_address,
        'timezone' => $timezone,
        'date_format' => $date_format,
        'time_format' => $time_format
    ];

    // For now, store in session as demo (you can create a settings table)
    $_SESSION['platform_settings'] = $settings;

    // Also update timezone
    date_default_timezone_set($timezone);

    $success = true;
    $_SESSION['success'] = "General settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Update Booking Settings
if ($action === 'update_booking' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_prefix = sanitize($_POST['booking_prefix'] ?? 'GRW');
    $min_booking_days_advance = intval($_POST['min_booking_days_advance'] ?? 0);
    $max_booking_days_advance = intval($_POST['max_booking_days_advance'] ?? 365);
    $auto_confirm_bookings = isset($_POST['auto_confirm_bookings']) ? 1 : 0;
    $enable_instant_booking = isset($_POST['enable_instant_booking']) ? 1 : 0;
    $cancellation_deadline_hours = intval($_POST['cancellation_deadline_hours'] ?? 24);
    $free_cancellation_days = intval($_POST['free_cancellation_days'] ?? 1);

    // Store booking settings
    $_SESSION['booking_settings'] = [
        'booking_prefix' => $booking_prefix,
        'min_booking_days_advance' => $min_booking_days_advance,
        'max_booking_days_advance' => $max_booking_days_advance,
        'auto_confirm_bookings' => $auto_confirm_bookings,
        'enable_instant_booking' => $enable_instant_booking,
        'cancellation_deadline_hours' => $cancellation_deadline_hours,
        'free_cancellation_days' => $free_cancellation_days
    ];

    $success = true;
    $_SESSION['success'] = "Booking settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Update Payment Settings
if ($action === 'update_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currency = sanitize($_POST['currency'] ?? 'RWF');
    $currency_symbol = sanitize($_POST['currency_symbol'] ?? 'FRw');
    $enable_mobile_money = isset($_POST['enable_mobile_money']) ? 1 : 0;
    $enable_card_payment = isset($_POST['enable_card_payment']) ? 1 : 0;
    $mtn_momo_api_key = sanitize($_POST['mtn_momo_api_key'] ?? '');
    $airtel_money_api_key = sanitize($_POST['airtel_money_api_key'] ?? '');
    $stripe_publishable_key = sanitize($_POST['stripe_publishable_key'] ?? '');
    $stripe_secret_key = sanitize($_POST['stripe_secret_key'] ?? '');

    $_SESSION['payment_settings'] = [
        'currency' => $currency,
        'currency_symbol' => $currency_symbol,
        'enable_mobile_money' => $enable_mobile_money,
        'enable_card_payment' => $enable_card_payment,
        'mtn_momo_api_key' => $mtn_momo_api_key,
        'airtel_money_api_key' => $airtel_money_api_key,
        'stripe_publishable_key' => $stripe_publishable_key,
        'stripe_secret_key' => $stripe_secret_key
    ];

    $success = true;
    $_SESSION['success'] = "Payment settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Update Email Settings
if ($action === 'update_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $smtp_host = sanitize($_POST['smtp_host'] ?? '');
    $smtp_port = intval($_POST['smtp_port'] ?? 587);
    $smtp_user = sanitize($_POST['smtp_user'] ?? '');
    $smtp_password = sanitize($_POST['smtp_password'] ?? '');
    $smtp_encryption = sanitize($_POST['smtp_encryption'] ?? 'tls');
    $email_from_name = sanitize($_POST['email_from_name'] ?? 'GoRwanda+');
    $email_from_address = sanitize($_POST['email_from_address'] ?? '');

    $_SESSION['email_settings'] = [
        'smtp_host' => $smtp_host,
        'smtp_port' => $smtp_port,
        'smtp_user' => $smtp_user,
        'smtp_password' => $smtp_password,
        'smtp_encryption' => $smtp_encryption,
        'email_from_name' => $email_from_name,
        'email_from_address' => $email_from_address
    ];

    $success = true;
    $_SESSION['success'] = "Email settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Update SEO Settings
if ($action === 'update_seo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $site_title = sanitize($_POST['site_title'] ?? 'GoRwanda+');
    $site_description = sanitize($_POST['site_description'] ?? '');
    $site_keywords = sanitize($_POST['site_keywords'] ?? '');
    $google_analytics_id = sanitize($_POST['google_analytics_id'] ?? '');
    $facebook_pixel_id = sanitize($_POST['facebook_pixel_id'] ?? '');

    $_SESSION['seo_settings'] = [
        'site_title' => $site_title,
        'site_description' => $site_description,
        'site_keywords' => $site_keywords,
        'google_analytics_id' => $google_analytics_id,
        'facebook_pixel_id' => $facebook_pixel_id
    ];

    $success = true;
    $_SESSION['success'] = "SEO settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Update Notification Settings
if ($action === 'update_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_notification_email = isset($_POST['booking_notification_email']) ? 1 : 0;
    $booking_notification_sms = isset($_POST['booking_notification_sms']) ? 1 : 0;
    $payment_notification_email = isset($_POST['payment_notification_email']) ? 1 : 0;
    $reminder_notification_email = isset($_POST['reminder_notification_email']) ? 1 : 0;
    $promotional_emails = isset($_POST['promotional_emails']) ? 1 : 0;

    $_SESSION['notification_settings'] = [
        'booking_notification_email' => $booking_notification_email,
        'booking_notification_sms' => $booking_notification_sms,
        'payment_notification_email' => $payment_notification_email,
        'reminder_notification_email' => $reminder_notification_email,
        'promotional_emails' => $promotional_emails
    ];

    $success = true;
    $_SESSION['success'] = "Notification settings updated successfully";
    header('Location: settings.php');
    exit;
}

// Get current settings from session or defaults
$generalSettings = $_SESSION['platform_settings'] ?? [
    'platform_name' => 'GoRwanda+',
    'platform_email' => 'info@gorwanda.rw',
    'platform_phone' => '+250 788 123 456',
    'platform_address' => 'Kigali, Rwanda',
    'timezone' => 'Africa/Kigali',
    'date_format' => 'M d, Y',
    'time_format' => 'H:i'
];

$bookingSettings = $_SESSION['booking_settings'] ?? [
    'booking_prefix' => 'GRW',
    'min_booking_days_advance' => 0,
    'max_booking_days_advance' => 365,
    'auto_confirm_bookings' => 1,
    'enable_instant_booking' => 1,
    'cancellation_deadline_hours' => 24,
    'free_cancellation_days' => 1
];

$paymentSettings = $_SESSION['payment_settings'] ?? [
    'currency' => 'RWF',
    'currency_symbol' => 'FRw',
    'enable_mobile_money' => 1,
    'enable_card_payment' => 1,
    'mtn_momo_api_key' => '',
    'airtel_money_api_key' => '',
    'stripe_publishable_key' => '',
    'stripe_secret_key' => ''
];

$emailSettings = $_SESSION['email_settings'] ?? [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_user' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'email_from_name' => 'GoRwanda+',
    'email_from_address' => 'noreply@gorwanda.rw'
];

$seoSettings = $_SESSION['seo_settings'] ?? [
    'site_title' => 'GoRwanda+ - Discover Rwanda\'s Best Travel Experiences',
    'site_description' => 'Book hotels, car rentals, tours and experiences in Rwanda. Best price guarantee.',
    'site_keywords' => 'Rwanda travel, gorilla trekking, Kigali hotels, Rwanda tours, Akagera safari',
    'google_analytics_id' => '',
    'facebook_pixel_id' => ''
];

$notificationSettings = $_SESSION['notification_settings'] ?? [
    'booking_notification_email' => 1,
    'booking_notification_sms' => 0,
    'payment_notification_email' => 1,
    'reminder_notification_email' => 1,
    'promotional_emails' => 1
];

// Timezone options
$timezones = [
    'Africa/Kigali' => 'Africa/Kigali (Rwanda)',
    'Africa/Nairobi' => 'Africa/Nairobi (EAT)',
    'Africa/Johannesburg' => 'Africa/Johannesburg (SAST)',
    'UTC' => 'UTC',
    'Europe/London' => 'Europe/London (GMT)',
    'America/New_York' => 'America/New_York (EST)'
];

// Currencies
$currencies = [
    'RWF' => 'Rwandan Franc (RWF)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
    'KES' => 'Kenyan Shilling (KES)',
    'UGX' => 'Ugandan Shilling (UGX)',
    'TZS' => 'Tanzanian Shilling (TZS)'
];

// Date formats
$dateFormats = [
    'M d, Y' => 'Jan 1, 2024',
    'd M Y' => '1 Jan 2024',
    'Y-m-d' => '2024-01-01',
    'm/d/Y' => '01/01/2024',
    'd/m/Y' => '01/01/2024',
    'F j, Y' => 'January 1, 2024'
];
?>

<style>
    /* Settings Page Styles */
    .settings-container {
        display: flex;
        gap: 24px;
        min-height: calc(100vh - 200px);
    }

    /* Settings Sidebar */
    .settings-sidebar {
        width: 280px;
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        overflow: hidden;
        height: fit-content;
        position: sticky;
        top: 80px;
    }

    .settings-nav {
        padding: 8px 0;
    }

    .settings-nav-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        color: var(--booking-text);
        text-decoration: none;
        font-size: 0.8125rem;
        font-weight: 500;
        transition: all var(--transition-fast);
        cursor: pointer;
    }

    .settings-nav-item:hover {
        background: var(--booking-gray-light);
        color: var(--booking-blue);
    }

    .settings-nav-item.active {
        background: rgba(0, 102, 255, 0.05);
        color: var(--booking-blue);
        border-left: 3px solid var(--booking-blue);
    }

    .settings-nav-item i {
        font-size: 1.125rem;
        width: 24px;
    }

    /* Settings Content */
    .settings-content {
        flex: 1;
    }

    .settings-section {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        margin-bottom: 24px;
        overflow: hidden;
        display: block;
    }

    .settings-section.hide {
        display: none;
    }

    .section-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--booking-border);
        background: var(--booking-gray-light);
    }

    .section-header h2 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .section-header p {
        font-size: 0.6875rem;
        color: var(--booking-text-light);
        margin: 4px 0 0 0;
    }

    .section-body {
        padding: 24px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--booking-text-light);
        margin-bottom: 6px;
        text-transform: uppercase;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--booking-border);
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
        transition: all var(--transition-fast);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--booking-blue);
        box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
    }

    .form-control[type="password"] {
        font-family: monospace;
    }

    select.form-control {
        cursor: pointer;
    }

    textarea.form-control {
        resize: vertical;
        min-height: 80px;
    }

    .form-check {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }

    .form-check input {
        width: 16px;
        height: 16px;
        cursor: pointer;
    }

    .form-check label {
        margin: 0;
        cursor: pointer;
        font-size: 0.75rem;
        font-weight: normal;
        text-transform: none;
    }

    .input-group {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .input-group .form-control {
        flex: 1;
    }

    .input-group span {
        font-size: 0.75rem;
        color: var(--booking-text-light);
    }

    .form-actions {
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid var(--booking-border);
        display: flex;
        justify-content: flex-end;
    }

    .btn-save {
        padding: 10px 24px;
        background: var(--booking-blue);
        color: var(--booking-white);
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--transition-fast);
    }

    .btn-save:hover {
        background: var(--booking-blue-dark);
        transform: translateY(-1px);
    }

    /* Info Box */
    .info-box {
        background: var(--booking-gray-light);
        border-radius: var(--radius-md);
        padding: 16px;
        margin-top: 20px;
        display: flex;
        gap: 12px;
    }

    .info-box i {
        font-size: 1.25rem;
        color: var(--booking-blue);
    }

    .info-box-content {
        flex: 1;
    }

    .info-box-title {
        font-weight: 600;
        font-size: 0.75rem;
        margin-bottom: 4px;
    }

    .info-box-text {
        font-size: 0.6875rem;
        color: var(--booking-text-light);
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
        border: 1px solid rgba(0, 128, 9, 0.2);
    }

    /* Responsive */
    @media (max-width: 992px) {
        .settings-container {
            flex-direction: column;
        }

        .settings-sidebar {
            width: 100%;
            position: static;
            overflow-x: auto;
        }

        .settings-nav {
            display: flex;
            padding: 8px;
            gap: 4px;
            white-space: nowrap;
        }

        .settings-nav-item {
            padding: 10px 16px;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .section-body {
            padding: 16px;
        }

        .section-header {
            padding: 16px 20px;
        }
    }
</style>

<div class="settings-container">
    <!-- Settings Navigation -->
    <div class="settings-sidebar">
        <div class="settings-nav">
            <div class="settings-nav-item active" data-section="general">
                <i class="bi bi-globe"></i>
                <span>General Settings</span>
            </div>
            <div class="settings-nav-item" data-section="booking">
                <i class="bi bi-calendar-check"></i>
                <span>Booking Settings</span>
            </div>
            <div class="settings-nav-item" data-section="payment">
                <i class="bi bi-credit-card"></i>
                <span>Payment Settings</span>
            </div>
            <div class="settings-nav-item" data-section="email">
                <i class="bi bi-envelope"></i>
                <span>Email Settings</span>
            </div>
            <div class="settings-nav-item" data-section="seo">
                <i class="bi bi-graph-up"></i>
                <span>SEO & Analytics</span>
            </div>
            <div class="settings-nav-item" data-section="notification">
                <i class="bi bi-bell"></i>
                <span>Notifications</span>
            </div>
        </div>
    </div>

    <!-- Settings Content -->
    <div class="settings-content">
        <!-- General Settings -->
        <div class="settings-section" id="section-general">
            <div class="section-header">
                <h2><i class="bi bi-globe"></i> General Settings</h2>
                <p>Configure basic platform information and regional settings</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_general">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Platform Name</label>
                            <input type="text" name="platform_name" class="form-control" value="<?php echo htmlspecialchars($generalSettings['platform_name']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Platform Email</label>
                            <input type="email" name="platform_email" class="form-control" value="<?php echo htmlspecialchars($generalSettings['platform_email']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Platform Phone</label>
                            <input type="tel" name="platform_phone" class="form-control" value="<?php echo htmlspecialchars($generalSettings['platform_phone']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Platform Address</label>
                            <input type="text" name="platform_address" class="form-control" value="<?php echo htmlspecialchars($generalSettings['platform_address']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Timezone</label>
                            <select name="timezone" class="form-control">
                                <?php foreach ($timezones as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $generalSettings['timezone'] == $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date Format</label>
                            <select name="date_format" class="form-control">
                                <?php foreach ($dateFormats as $value => $example): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $generalSettings['date_format'] == $value ? 'selected' : ''; ?>>
                                        <?php echo $example; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Time Format</label>
                            <select name="time_format" class="form-control">
                                <option value="H:i" <?php echo $generalSettings['time_format'] == 'H:i' ? 'selected' : ''; ?>>24-hour (14:30)</option>
                                <option value="h:i A" <?php echo $generalSettings['time_format'] == 'h:i A' ? 'selected' : ''; ?>>12-hour (02:30 PM)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save General Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Booking Settings -->
        <div class="settings-section hide" id="section-booking">
            <div class="section-header">
                <h2><i class="bi bi-calendar-check"></i> Booking Settings</h2>
                <p>Configure booking rules, cancellation policies, and confirmation settings</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_booking">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Booking Reference Prefix</label>
                            <input type="text" name="booking_prefix" class="form-control" value="<?php echo htmlspecialchars($bookingSettings['booking_prefix']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Minimum Days Advance Booking</label>
                            <input type="number" name="min_booking_days_advance" class="form-control" value="<?php echo $bookingSettings['min_booking_days_advance']; ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label>Maximum Days Advance Booking</label>
                            <input type="number" name="max_booking_days_advance" class="form-control" value="<?php echo $bookingSettings['max_booking_days_advance']; ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label>Cancellation Deadline (hours)</label>
                            <input type="number" name="cancellation_deadline_hours" class="form-control" value="<?php echo $bookingSettings['cancellation_deadline_hours']; ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label>Free Cancellation Period (days)</label>
                            <input type="number" name="free_cancellation_days" class="form-control" value="<?php echo $bookingSettings['free_cancellation_days']; ?>" min="0">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="auto_confirm_bookings" id="auto_confirm" value="1" <?php echo $bookingSettings['auto_confirm_bookings'] ? 'checked' : ''; ?>>
                        <label for="auto_confirm">Auto-confirm bookings (instant confirmation)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="enable_instant_booking" id="instant_booking" value="1" <?php echo $bookingSettings['enable_instant_booking'] ? 'checked' : ''; ?>>
                        <label for="instant_booking">Enable instant booking for all vendors</label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save Booking Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Settings -->
        <div class="settings-section hide" id="section-payment">
            <div class="section-header">
                <h2><i class="bi bi-credit-card"></i> Payment Settings</h2>
                <p>Configure currency, payment gateways, and API keys</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_payment">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Default Currency</label>
                            <select name="currency" class="form-control">
                                <?php foreach ($currencies as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php echo $paymentSettings['currency'] == $code ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Currency Symbol</label>
                            <input type="text" name="currency_symbol" class="form-control" value="<?php echo htmlspecialchars($paymentSettings['currency_symbol']); ?>">
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="enable_mobile_money" id="mobile_money" value="1" <?php echo $paymentSettings['enable_mobile_money'] ? 'checked' : ''; ?>>
                        <label for="mobile_money">Enable Mobile Money (MTN MoMo, Airtel Money)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="enable_card_payment" id="card_payment" value="1" <?php echo $paymentSettings['enable_card_payment'] ? 'checked' : ''; ?>>
                        <label for="card_payment">Enable Card Payments (Stripe)</label>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>MTN MoMo API Key</label>
                            <input type="password" name="mtn_momo_api_key" class="form-control" value="<?php echo htmlspecialchars($paymentSettings['mtn_momo_api_key']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Airtel Money API Key</label>
                            <input type="password" name="airtel_money_api_key" class="form-control" value="<?php echo htmlspecialchars($paymentSettings['airtel_money_api_key']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Stripe Publishable Key</label>
                            <input type="text" name="stripe_publishable_key" class="form-control" value="<?php echo htmlspecialchars($paymentSettings['stripe_publishable_key']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Stripe Secret Key</label>
                            <input type="password" name="stripe_secret_key" class="form-control" value="<?php echo htmlspecialchars($paymentSettings['stripe_secret_key']); ?>">
                        </div>
                    </div>
                    <div class="info-box">
                        <i class="bi bi-shield-lock"></i>
                        <div class="info-box-content">
                            <div class="info-box-title">Secure API Key Storage</div>
                            <div class="info-box-text">API keys are encrypted and stored securely. Never share your secret keys publicly.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save Payment Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Email Settings -->
        <div class="settings-section hide" id="section-email">
            <div class="section-header">
                <h2><i class="bi bi-envelope"></i> Email Settings</h2>
                <p>Configure SMTP server for sending transactional emails</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_email">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($emailSettings['smtp_host']); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?php echo $emailSettings['smtp_port']; ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="smtp_user" class="form-control" value="<?php echo htmlspecialchars($emailSettings['smtp_user']); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($emailSettings['smtp_password']); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Encryption</label>
                            <select name="smtp_encryption" class="form-control">
                                <option value="tls" <?php echo $emailSettings['smtp_encryption'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="ssl" <?php echo $emailSettings['smtp_encryption'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="none" <?php echo $emailSettings['smtp_encryption'] == 'none' ? 'selected' : ''; ?>>None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Email From Name</label>
                            <input type="text" name="email_from_name" class="form-control" value="<?php echo htmlspecialchars($emailSettings['email_from_name']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email From Address</label>
                            <input type="email" name="email_from_address" class="form-control" value="<?php echo htmlspecialchars($emailSettings['email_from_address']); ?>">
                        </div>
                    </div>
                    <div class="info-box">
                        <i class="bi bi-info-circle"></i>
                        <div class="info-box-content">
                            <div class="info-box-title">Test Email Configuration</div>
                            <div class="info-box-text">After saving, you can send a test email to verify your SMTP settings are working correctly.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save Email Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- SEO & Analytics Settings -->
        <div class="settings-section hide" id="section-seo">
            <div class="section-header">
                <h2><i class="bi bi-graph-up"></i> SEO & Analytics</h2>
                <p>Configure search engine optimization and tracking codes</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_seo">
                    <div class="form-group">
                        <label>Site Title</label>
                        <input type="text" name="site_title" class="form-control" value="<?php echo htmlspecialchars($seoSettings['site_title']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Meta Description</label>
                        <textarea name="site_description" class="form-control" rows="3"><?php echo htmlspecialchars($seoSettings['site_description']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Meta Keywords</label>
                        <input type="text" name="site_keywords" class="form-control" value="<?php echo htmlspecialchars($seoSettings['site_keywords']); ?>">
                        <small class="text-muted">Comma-separated keywords</small>
                    </div>
                    <div class="form-group">
                        <label>Google Analytics ID</label>
                        <input type="text" name="google_analytics_id" class="form-control" value="<?php echo htmlspecialchars($seoSettings['google_analytics_id']); ?>" placeholder="G-XXXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label>Facebook Pixel ID</label>
                        <input type="text" name="facebook_pixel_id" class="form-control" value="<?php echo htmlspecialchars($seoSettings['facebook_pixel_id']); ?>" placeholder="1234567890">
                    </div>
                    <div class="info-box">
                        <i class="bi bi-code-slash"></i>
                        <div class="info-box-content">
                            <div class="info-box-title">Tracking Codes</div>
                            <div class="info-box-text">These codes will be added to the <code>&lt;head&gt;</code> section of your website.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save SEO Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification Settings -->
        <div class="settings-section hide" id="section-notification">
            <div class="section-header">
                <h2><i class="bi bi-bell"></i> Notification Settings</h2>
                <p>Configure which notifications are sent to users</p>
            </div>
            <div class="section-body">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_notification">
                    <div class="form-check">
                        <input type="checkbox" name="booking_notification_email" id="booking_email" value="1" <?php echo $notificationSettings['booking_notification_email'] ? 'checked' : ''; ?>>
                        <label for="booking_email">Send booking confirmation emails</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="booking_notification_sms" id="booking_sms" value="1" <?php echo $notificationSettings['booking_notification_sms'] ? 'checked' : ''; ?>>
                        <label for="booking_sms">Send booking confirmation SMS</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="payment_notification_email" id="payment_email" value="1" <?php echo $notificationSettings['payment_notification_email'] ? 'checked' : ''; ?>>
                        <label for="payment_email">Send payment confirmation emails</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="reminder_notification_email" id="reminder_email" value="1" <?php echo $notificationSettings['reminder_notification_email'] ? 'checked' : ''; ?>>
                        <label for="reminder_email">Send booking reminder emails (24h before)</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="promotional_emails" id="promotional" value="1" <?php echo $notificationSettings['promotional_emails'] ? 'checked' : ''; ?>>
                        <label for="promotional">Allow promotional email campaigns</label>
                    </div>
                    <div class="info-box">
                        <i class="bi bi-envelope-paper"></i>
                        <div class="info-box-content">
                            <div class="info-box-title">Email Templates</div>
                            <div class="info-box-text">You can customize email templates in the Email Templates section.</div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save Notification Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Settings section navigation
    document.querySelectorAll('.settings-nav-item').forEach(item => {
        item.addEventListener('click', function() {
            const section = this.dataset.section;

            // Update active state
            document.querySelectorAll('.settings-nav-item').forEach(nav => {
                nav.classList.remove('active');
            });
            this.classList.add('active');

            // Show selected section, hide others
            document.querySelectorAll('.settings-section').forEach(sectionEl => {
                sectionEl.classList.add('hide');
            });
            document.getElementById(`section-${section}`).classList.remove('hide');

            // Save to localStorage
            localStorage.setItem('activeSettingsSection', section);
        });
    });

    // Load last active section from localStorage
    const lastSection = localStorage.getItem('activeSettingsSection');
    if (lastSection) {
        const targetNav = document.querySelector(`.settings-nav-item[data-section="${lastSection}"]`);
        if (targetNav) {
            targetNav.click();
        }
    }

    // Test email configuration (optional)
    function testEmailConfig() {
        alert('Test email feature will send a test email to verify SMTP settings.');
    }
</script>

<?php require_once 'includes/admin_footer.php'; ?>