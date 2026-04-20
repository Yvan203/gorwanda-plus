<?php
$pageTitle = 'Settings';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Helper function for sanitization (if not already defined)
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Get vendor profile
$stmt = $db->prepare("SELECT * FROM vendor_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$vendorProfile = $stmt->fetch();
$vendorId = $vendorProfile ? $vendorProfile['vendor_id'] : 0;

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get rental company details (car_rentals table)
$stmt = $db->prepare("SELECT * FROM car_rentals WHERE owner_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$companies = $stmt->fetchAll();

// Get active company (first one or selected)
$activeCompanyId = isset($_GET['company']) ? intval($_GET['company']) : (isset($companies[0]) ? $companies[0]['rental_id'] : 0);
$activeCompany = null;
foreach ($companies as $company) {
    if ($company['rental_id'] == $activeCompanyId) {
        $activeCompany = $company;
        break;
    }
}

// ============================================
// HANDLE SETTINGS ACTIONS
// ============================================

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'company';
$success = null;
$error = null;

// File upload configuration
$uploadConfig = [
    'max_size' => 2 * 1024 * 1024, // 2MB
    'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp']
];

// Helper function to handle image upload - UPDATED PATH to assets/images/cars
// Helper function to handle image upload - FIXED PATH
function handleImageUpload($file, $filePrefix, $config) {
    // FIXED: Correct path from partner/cars/ to assets/images/cars/
    $uploadDir = dirname(__DIR__, 2) . '/assets/images/cars/';
    
    // For debugging - you can remove this after confirming it works
    error_log("Upload directory: " . $uploadDir);
    
    // Check upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'filename' => null];
        }
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum size limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        return ['success' => false, 'error' => $errorMessages[$file['error']] ?? 'Unknown upload error'];
    }
    
    // Validate file size
    if ($file['size'] > $config['max_size']) {
        return ['success' => false, 'error' => 'File size exceeds 2MB limit'];
    }
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $config['allowed_types'])) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP allowed'];
    }
    
    // Validate extension
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $config['allowed_extensions'])) {
        return ['success' => false, 'error' => 'Invalid file extension'];
    }
    
    // Create directory if doesn't exist
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory: ' . $uploadDir];
        }
    }
    
    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        return ['success' => false, 'error' => 'Upload directory is not writable: ' . $uploadDir];
    }
    
    // Generate unique filename
    $fileName = $filePrefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
    $uploadPath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => false, 'error' => 'Failed to move uploaded file to: ' . $uploadPath];
    }
    
    // Set proper permissions
    chmod($uploadPath, 0644);
    
    return ['success' => true, 'filename' => $fileName];
}

// Update company profile - CORRECTED TO USE ACTUAL DB COLUMNS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    $companyId = intval($_POST['company_id']);
    $companyName = sanitize($_POST['company_name']);
    $description = sanitize($_POST['description']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address']);
    $operatingHours = sanitize($_POST['operating_hours']);
    
    // Handle logo upload - goes to assets/images/cars/
    $logoPath = null;
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = handleImageUpload($_FILES['company_logo'], 'company_' . $companyId, $uploadConfig);
        
        if ($uploadResult['success'] && $uploadResult['filename']) {
            $logoPath = $uploadResult['filename'];
            
            // Delete old logo if exists
            if (!empty($activeCompany['logo'])) {
                $oldLogoPath = __DIR__ . '/../../../assets/images/cars/' . $activeCompany['logo'];
                if (file_exists($oldLogoPath)) {
                    unlink($oldLogoPath);
                }
            }
        } else if (!$uploadResult['success']) {
            $error = $uploadResult['error'];
        }
    }
    
    if ($companyId > 0 && !$error) {
        try {
            // CORRECTED: Only use columns that exist in car_rentals table
            if ($logoPath) {
                $stmt = $db->prepare("
                    UPDATE car_rentals 
                    SET company_name = ?, description = ?, phone = ?, email = ?, 
                        address = ?, operating_hours = ?, logo = ?
                    WHERE rental_id = ? AND owner_id = ?
                ");
                $stmt->execute([
                    $companyName, $description, $phone, $email,
                    $address, $operatingHours, $logoPath, $companyId, $userId
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE car_rentals 
                    SET company_name = ?, description = ?, phone = ?, email = ?, 
                        address = ?, operating_hours = ?
                    WHERE rental_id = ? AND owner_id = ?
                ");
                $stmt->execute([
                    $companyName, $description, $phone, $email,
                    $address, $operatingHours, $companyId, $userId
                ]);
            }
            $success = "Company profile updated successfully!";
            
            // Refresh company data
            $stmt = $db->prepare("SELECT * FROM car_rentals WHERE rental_id = ?");
            $stmt->execute([$companyId]);
            $activeCompany = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Update vendor profile (separate from car_rentals - uses vendor_profiles table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vendor_profile'])) {
    $businessName = sanitize($_POST['business_name']);
    $businessRegistration = sanitize($_POST['business_registration']);
    $taxNumber = sanitize($_POST['tax_number']);
    $website = sanitize($_POST['website']);
    $yearEstablished = intval($_POST['year_established'] ?? date('Y'));
    $employeeCount = intval($_POST['employee_count'] ?? 0);
    $businessAddress = sanitize($_POST['business_address']);
    $businessPhone = sanitize($_POST['business_phone']);
    $businessEmail = sanitize($_POST['business_email']);
    
    try {
        if ($vendorProfile) {
            // Update existing vendor profile
            $stmt = $db->prepare("
                UPDATE vendor_profiles 
                SET business_name = ?, business_registration = ?, tax_number = ?, 
                    website = ?, year_established = ?, employee_count = ?,
                    business_address = ?, business_phone = ?, business_email = ?,
                    updated_at = NOW()
                WHERE vendor_id = ?
            ");
            $stmt->execute([
                $businessName, $businessRegistration, $taxNumber,
                $website, $yearEstablished, $employeeCount,
                $businessAddress, $businessPhone, $businessEmail,
                $vendorId
            ]);
        } else {
            // Create new vendor profile
            $stmt = $db->prepare("
                INSERT INTO vendor_profiles (
                    user_id, business_name, business_registration, tax_number,
                    website, year_established, employee_count,
                    business_address, business_phone, business_email, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId, $businessName, $businessRegistration, $taxNumber,
                $website, $yearEstablished, $employeeCount,
                $businessAddress, $businessPhone, $businessEmail
            ]);
            $vendorId = $db->lastInsertId();
        }
        
        $success = "Business profile updated successfully!";
        
        // Refresh vendor profile
        $stmt = $db->prepare("SELECT * FROM vendor_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $vendorProfile = $stmt->fetch();
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Update user profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = sanitize($_POST['first_name']);
    $lastName = sanitize($_POST['last_name']);
    $phone = sanitize($_POST['phone']);
    
    // Handle profile image upload - goes to assets/images/cars/
    $profileImage = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = handleImageUpload($_FILES['profile_image'], 'user_' . $userId, $uploadConfig);
        
        if ($uploadResult['success'] && $uploadResult['filename']) {
            $profileImage = $uploadResult['filename'];
            
            // Delete old image if exists
            if (!empty($user['profile_image'])) {
                $oldImagePath = __DIR__ . '/../../../assets/images/cars/' . $user['profile_image'];
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }
        } else if (!$uploadResult['success']) {
            $error = $uploadResult['error'];
        }
    }
    
    if (!$error) {
        try {
            if ($profileImage) {
                $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, profile_image = ? WHERE user_id = ?");
                $stmt->execute([$firstName, $lastName, $phone, $profileImage, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE user_id = ?");
                $stmt->execute([$firstName, $lastName, $phone, $userId]);
            }
            
            $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            $success = "Profile updated successfully!";
            
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password
    if (password_verify($currentPassword, $user['password_hash'])) {
        if (strlen($newPassword) >= 6) {
            if ($newPassword === $confirmPassword) {
                try {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                    $stmt->execute([$newHash, $userId]);
                    $success = "Password changed successfully!";
                } catch (PDOException $e) {
                    $error = "Failed to update password: " . $e->getMessage();
                }
            } else {
                $error = "New passwords do not match";
            }
        } else {
            $error = "Password must be at least 6 characters long";
        }
    } else {
        $error = "Current password is incorrect";
    }
}

// Update payment settings (stored in vendor_profiles.payment_info JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $bankName = sanitize($_POST['bank_name'] ?? '');
    $accountName = sanitize($_POST['account_name'] ?? '');
    $accountNumber = sanitize($_POST['account_number'] ?? '');
    $bankCode = sanitize($_POST['bank_code'] ?? '');
    $swiftCode = sanitize($_POST['swift_code'] ?? '');
    $momoNumber = sanitize($_POST['momo_number'] ?? '');
    $momoProvider = sanitize($_POST['momo_provider'] ?? 'MTN');
    
    $paymentInfo = json_encode([
        'bank' => [
            'bank_name' => $bankName,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'swift_code' => $swiftCode
        ],
        'mobile_money' => [
            'provider' => $momoProvider,
            'number' => $momoNumber
        ]
    ]);
    
    try {
        if ($vendorProfile) {
            $stmt = $db->prepare("UPDATE vendor_profiles SET payment_info = ? WHERE vendor_id = ?");
            $stmt->execute([$paymentInfo, $vendorId]);
        } else {
            // Create minimal vendor profile first
            $stmt = $db->prepare("INSERT INTO vendor_profiles (user_id, payment_info, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$userId, $paymentInfo]);
            $vendorId = $db->lastInsertId();
        }
        $success = "Payment settings updated successfully!";
        
        // Refresh vendor profile
        $stmt = $db->prepare("SELECT * FROM vendor_profiles WHERE vendor_id = ?");
        $stmt->execute([$vendorId]);
        $vendorProfile = $stmt->fetch();
        
    } catch (PDOException $e) {
        $error = "Failed to update payment settings: " . $e->getMessage();
    }
}

// Update notification settings (stored in session - you may want a separate table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    $emailBookings = isset($_POST['email_bookings']) ? 1 : 0;
    $emailReminders = isset($_POST['email_reminders']) ? 1 : 0;
    $emailPromotions = isset($_POST['email_promotions']) ? 1 : 0;
    $smsBookings = isset($_POST['sms_bookings']) ? 1 : 0;
    $smsReminders = isset($_POST['sms_reminders']) ? 1 : 0;
    $whatsappEnabled = isset($_POST['whatsapp_enabled']) ? 1 : 0;
    
    $notificationSettings = json_encode([
        'email' => [
            'bookings' => $emailBookings,
            'reminders' => $emailReminders,
            'promotions' => $emailPromotions
        ],
        'sms' => [
            'bookings' => $smsBookings,
            'reminders' => $smsReminders
        ],
        'whatsapp' => $whatsappEnabled
    ]);
    
    // Store in session (create a user_settings table for persistence if needed)
    $_SESSION['notification_settings'] = $notificationSettings;
    $success = "Notification settings updated successfully!";
}

// Add new company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $companyName = sanitize($_POST['company_name']);
    $description = sanitize($_POST['description'] ?? '');
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address'] ?? '');
    
    // Create slug
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($companyName));
    $slug = trim($slug, '-') . '-' . time();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO car_rentals (
                owner_id, company_name, slug, description, phone, email, 
                address, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        
        $stmt->execute([
            $userId, $companyName, $slug, $description, $phone, $email,
            $address
        ]);
        
        $newCompanyId = $db->lastInsertId();
        $success = "New company added successfully!";
        
        // Redirect to new company
        header('Location: settings.php?tab=company&company=' . $newCompanyId);
        exit;
        
    } catch (PDOException $e) {
        $error = "Failed to add company: " . $e->getMessage();
    }
}

// Get payment info from vendor_profiles
$paymentInfo = [];
if ($vendorProfile && $vendorProfile['payment_info']) {
    $paymentInfo = json_decode($vendorProfile['payment_info'], true) ?: [];
}

// Countries list (for reference, not stored in car_rentals)
$countries = ['Rwanda', 'Uganda', 'Kenya', 'Tanzania', 'Burundi', 'DR Congo'];

// Years for dropdown
$currentYear = date('Y');
$years = range($currentYear - 50, $currentYear);

// REMOVED: getImageUrl() function - it's already defined in functions.php
// Use the function from includes/functions.php instead
?>

<style>
/* Settings Specific Styles */
.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.settings-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.settings-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
}

/* Settings Container */
.settings-container {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 24px;
}

/* Settings Sidebar */
.settings-sidebar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    overflow: hidden;
    height: fit-content;
}

.settings-nav {
    list-style: none;
    padding: 0;
    margin: 0;
}

.settings-nav-item {
    border-bottom: 1px solid var(--border-gray);
}

.settings-nav-item:last-child {
    border-bottom: none;
}

.settings-nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    color: var(--text-dark);
    text-decoration: none;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s;
}

.settings-nav-link:hover {
    background: var(--cars-light);
    color: var(--cars-primary);
}

.settings-nav-link.active {
    background: var(--cars-light);
    color: var(--cars-primary);
    font-weight: 600;
    border-left: 3px solid var(--cars-primary);
}

.settings-nav-link i {
    font-size: 1.125rem;
    width: 24px;
    text-align: center;
}

/* Settings Content */
.settings-content {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 30px;
}

.settings-section {
    margin-bottom: 30px;
}

.settings-section-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border-gray);
}

/* Company Selector */
.company-selector {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
    padding: 16px;
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
}

.company-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-dark);
}

.company-selector select {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-dark);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label .required {
    color: var(--cars-danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--cars-primary);
    box-shadow: 0 0 0 3px rgba(255,140,0,0.1);
}

.form-control[readonly] {
    background: var(--bg-gray);
    cursor: not-allowed;
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.form-text {
    display: block;
    margin-top: 6px;
    font-size: 0.75rem;
    color: var(--text-light);
}

/* Image Upload */
.image-upload {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 10px;
}

.image-preview {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: var(--bg-gray);
    border: 2px solid var(--border-gray);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-preview i {
    font-size: 2rem;
    color: var(--text-light);
}

.image-upload-btn {
    flex: 1;
}

/* Company Cards */
.company-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.company-card {
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-md);
    padding: 16px;
    transition: all 0.2s;
    cursor: pointer;
}

.company-card:hover {
    border-color: var(--cars-primary);
    box-shadow: var(--shadow-sm);
}

.company-card.active {
    border: 2px solid var(--cars-primary);
    background: var(--cars-light);
}

.company-logo {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--bg-gray);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--cars-primary);
}

.company-logo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.company-name {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.company-location {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Toggle Switch */
.toggle-switch {
    display: flex;
    align-items: center;
    gap: 10px;
}

.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: var(--cars-primary);
}

input:checked + .slider:before {
    transform: translateX(26px);
}

/* Payment Info Cards */
.payment-card {
    background: var(--bg-gray);
    border-radius: var(--radius-sm);
    padding: 16px;
    margin-bottom: 16px;
}

.payment-card-title {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--cars-primary);
}

.payment-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 0.8125rem;
}

.payment-label {
    color: var(--text-light);
}

.payment-value {
    font-weight: 600;
}

/* Action Buttons */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--border-gray);
}

.btn-save {
    background: var(--cars-primary);
    color: white;
    border: none;
    padding: 12px 32px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-save:hover {
    background: var(--cars-dark);
}

.btn-cancel {
    background: white;
    color: var(--text-dark);
    border: 1px solid var(--border-gray);
    padding: 12px 32px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-cancel:hover {
    background: var(--bg-gray);
}

/* Alert Messages */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.875rem;
}

.alert-success {
    background: #e6f4ea;
    color: var(--cars-success);
    border: 1px solid var(--cars-success);
}

.alert-danger {
    background: #fce8e8;
    color: var(--cars-danger);
    border: 1px solid var(--cars-danger);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-light);
}

.empty-state i {
    font-size: 3rem;
    color: var(--border-gray);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.25rem;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.empty-state p {
    margin-bottom: 20px;
}

/* Tab content separation */
.tab-divider {
    margin: 30px 0;
    border: none;
    border-top: 1px solid var(--border-gray);
}

/* MODAL STYLES */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideUp 0.3s ease;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-gray);
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-dark);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: var(--text-light);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--bg-gray);
    color: var(--cars-danger);
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--border-gray);
    background: var(--bg-gray);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { 
        opacity: 0;
        transform: translateY(20px);
    }
    to { 
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive */
@media (max-width: 1200px) {
    .settings-container {
        grid-template-columns: 200px 1fr;
    }
}

@media (max-width: 768px) {
    .settings-container {
        grid-template-columns: 1fr;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full-width {
        grid-column: span 1;
    }
    
    .company-selector {
        flex-direction: column;
        align-items: stretch;
    }
    
    .image-upload {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .modal-content {
        width: 95%;
        margin: 20px;
    }
}
</style>

<div class="settings-header">
    <div class="settings-title">
        <h1>Settings</h1>
        <p>Manage your account, company profile, and preferences</p>
    </div>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
<?php endif; ?>

<div class="settings-container">
    <!-- Settings Navigation -->
    <div class="settings-sidebar">
        <ul class="settings-nav">
            <li class="settings-nav-item">
                <a href="?tab=company" class="settings-nav-link <?php echo $activeTab == 'company' ? 'active' : ''; ?>">
                    <i class="bi bi-building"></i>
                    Company Profile
                </a>
            </li>
            <li class="settings-nav-item">
                <a href="?tab=business" class="settings-nav-link <?php echo $activeTab == 'business' ? 'active' : ''; ?>">
                    <i class="bi bi-briefcase"></i>
                    Business Details
                </a>
            </li>
            <li class="settings-nav-item">
                <a href="?tab=profile" class="settings-nav-link <?php echo $activeTab == 'profile' ? 'active' : ''; ?>">
                    <i class="bi bi-person"></i>
                    Personal Profile
                </a>
            </li>
            <li class="settings-nav-item">
                <a href="?tab=payment" class="settings-nav-link <?php echo $activeTab == 'payment' ? 'active' : ''; ?>">
                    <i class="bi bi-credit-card"></i>
                    Payment Settings
                </a>
            </li>
            <li class="settings-nav-item">
                <a href="?tab=notifications" class="settings-nav-link <?php echo $activeTab == 'notifications' ? 'active' : ''; ?>">
                    <i class="bi bi-bell"></i>
                    Notifications
                </a>
            </li>
            <li class="settings-nav-item">
                <a href="?tab=security" class="settings-nav-link <?php echo $activeTab == 'security' ? 'active' : ''; ?>">
                    <i class="bi bi-shield-lock"></i>
                    Security
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Settings Content -->
    <div class="settings-content">
        <!-- COMPANY PROFILE TAB (car_rentals table) -->
        <?php if ($activeTab == 'company'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Company Profile</h2>
            
            <!-- Company Selector -->
            <?php if (count($companies) > 1): ?>
            <div class="company-selector">
                <label>Select Company:</label>
                <select onchange="changeCompany(this.value)">
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?php echo $comp['rental_id']; ?>" <?php echo $comp['rental_id'] == $activeCompanyId ? 'selected' : ''; ?>>
                        <?php echo sanitize($comp['company_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-secondary btn-sm" onclick="openAddCompanyModal()">
                    <i class="bi bi-plus-lg"></i> New Company
                </button>
            </div>
            <?php endif; ?>
            
            <?php if ($activeCompany): ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="company_id" value="<?php echo $activeCompanyId; ?>">
                
                <div class="form-grid">
<div class="form-group full-width">
    <label class="form-label">Company Logo</label>
    <div class="image-upload">
        <div class="image-preview">
            <?php 
            // Use the same pattern as the main index page
            if (!empty($activeCompany['logo'])): 
                $logoUrl = getImageUrl($activeCompany['logo'], 'car');
            ?>
                <img src="<?php echo $logoUrl; ?>" 
                     alt="<?php echo sanitize($activeCompany['company_name']); ?>"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <i class="bi bi-building" style="display: none;"></i>
            <?php else: ?>
                <i class="bi bi-building"></i>
            <?php endif; ?>
        </div>
        <div class="image-upload-btn">
            <input type="file" name="company_logo" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
            <small class="form-text">Recommended: Square image, at least 200x200px. Max 2MB. Formats: JPG, PNG, GIF, WebP</small>
            <?php if (!empty($activeCompany['logo'])): ?>
            <small class="form-text text-success mt-2">
                <i class="bi bi-check-circle-fill"></i> Current logo: <?php echo $activeCompany['logo']; ?>
                <br>
                <a href="<?php echo getImageUrl($activeCompany['logo'], 'car'); ?>" target="_blank" class="text-primary">
                    <i class="bi bi-eye"></i> View logo
                </a>
            </small>
            <?php endif; ?>
        </div>
    </div>
</div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Company Name <span class="required">*</span></label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo sanitize($activeCompany['company_name']); ?>" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo sanitize($activeCompany['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone <span class="required">*</span></label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo sanitize($activeCompany['phone']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?php echo sanitize($activeCompany['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Operating Hours</label>
                        <input type="text" name="operating_hours" class="form-control" value="<?php echo sanitize($activeCompany['operating_hours'] ?? ''); ?>" placeholder="Mon-Sun: 08:00-18:00">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="<?php echo sanitize($activeCompany['address'] ?? ''); ?>" placeholder="Street address">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_company" class="btn-save">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="empty-state">
                <i class="bi bi-building"></i>
                <h3>No company profile</h3>
                <p>Add your first company to get started.</p>
                <button class="btn-primary" onclick="openAddCompanyModal()">
                    <i class="bi bi-plus-lg"></i> Add Company
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- BUSINESS DETAILS TAB (vendor_profiles table) -->
        <?php if ($activeTab == 'business'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Business Details</h2>
            <p style="color: var(--text-light); margin-bottom: 20px; font-size: 0.875rem;">
                These details are stored in your vendor profile and used for legal and tax purposes.
            </p>
            
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Business Name</label>
                        <input type="text" name="business_name" class="form-control" value="<?php echo sanitize($vendorProfile['business_name'] ?? ''); ?>" placeholder="Registered business name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Business Registration Number</label>
                        <input type="text" name="business_registration" class="form-control" value="<?php echo sanitize($vendorProfile['business_registration'] ?? ''); ?>" placeholder="RDB registration number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tax Number (TIN)</label>
                        <input type="text" name="tax_number" class="form-control" value="<?php echo sanitize($vendorProfile['tax_number'] ?? ''); ?>" placeholder="Tax identification number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Website</label>
                        <input type="url" name="website" class="form-control" value="<?php echo sanitize($vendorProfile['website'] ?? ''); ?>" placeholder="https://example.com">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Year Established</label>
                        <select name="year_established" class="form-control">
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo ($vendorProfile['year_established'] ?? $currentYear) == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Number of Employees</label>
                        <input type="number" name="employee_count" class="form-control" value="<?php echo $vendorProfile['employee_count'] ?? 0; ?>" min="0">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Business Address</label>
                        <textarea name="business_address" class="form-control" rows="2"><?php echo sanitize($vendorProfile['business_address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Business Phone</label>
                        <input type="tel" name="business_phone" class="form-control" value="<?php echo sanitize($vendorProfile['business_phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Business Email</label>
                        <input type="email" name="business_email" class="form-control" value="<?php echo sanitize($vendorProfile['business_email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_vendor_profile" class="btn-save">
                        <i class="bi bi-check-lg"></i> Save Business Details
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- PERSONAL PROFILE TAB (users table) -->
        <?php if ($activeTab == 'profile'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Personal Profile</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-grid">
<div class="form-group full-width">
    <label class="form-label">Profile Photo</label>
    <div class="image-upload">
        <div class="image-preview">
            <?php 
            // Use the same pattern for profile images
            if (!empty($user['profile_image'])): 
                $profileUrl = getImageUrl($user['profile_image'], 'profile');
            ?>
                <img src="<?php echo $profileUrl; ?>" 
                     alt="Profile"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <i class="bi bi-person" style="display: none;"></i>
            <?php else: ?>
                <i class="bi bi-person"></i>
            <?php endif; ?>
        </div>
        <div class="image-upload-btn">
            <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control">
            <small class="form-text">Max 2MB. Formats: JPG, PNG, GIF, WebP</small>
            <?php if (!empty($user['profile_image'])): ?>
            <small class="form-text text-success mt-2">
                <i class="bi bi-check-circle-fill"></i> Current photo: <?php echo $user['profile_image']; ?>
            </small>
            <?php endif; ?>
        </div>
    </div>
</div>
                    
                    <div class="form-group">
                        <label class="form-label">First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo sanitize($user['first_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo sanitize($user['last_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" readonly>
                        <small class="form-text">Email cannot be changed</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo sanitize($user['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Member Since</label>
                        <input type="text" class="form-control" value="<?php echo date('F d, Y', strtotime($user['created_at'])); ?>" readonly>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn-save">
                        <i class="bi bi-check-lg"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- PAYMENT SETTINGS TAB (vendor_profiles.payment_info JSON) -->
        <?php if ($activeTab == 'payment'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Payment Settings</h2>
            
            <form method="POST">
                <div class="payment-card">
                    <h3 class="payment-card-title"><i class="bi bi-bank"></i> Bank Account Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" value="<?php echo sanitize($paymentInfo['bank']['bank_name'] ?? ''); ?>" placeholder="e.g., Bank of Kigali">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Account Name</label>
                            <input type="text" name="account_name" class="form-control" value="<?php echo sanitize($paymentInfo['bank']['account_name'] ?? ''); ?>" placeholder="Account holder name">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control" value="<?php echo sanitize($paymentInfo['bank']['account_number'] ?? ''); ?>" placeholder="Account number">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bank Code</label>
                            <input type="text" name="bank_code" class="form-control" value="<?php echo sanitize($paymentInfo['bank']['bank_code'] ?? ''); ?>" placeholder="Branch code">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">SWIFT/BIC Code</label>
                            <input type="text" name="swift_code" class="form-control" value="<?php echo sanitize($paymentInfo['bank']['swift_code'] ?? ''); ?>" placeholder="International transfer code">
                        </div>
                    </div>
                </div>
                
                <div class="payment-card">
                    <h3 class="payment-card-title"><i class="bi bi-phone"></i> Mobile Money</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Provider</label>
                            <select name="momo_provider" class="form-control">
                                <option value="MTN" <?php echo ($paymentInfo['mobile_money']['provider'] ?? 'MTN') == 'MTN' ? 'selected' : ''; ?>>MTN Mobile Money</option>
                                <option value="Airtel" <?php echo ($paymentInfo['mobile_money']['provider'] ?? '') == 'Airtel' ? 'selected' : ''; ?>>Airtel Money</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Mobile Money Number</label>
                            <input type="tel" name="momo_number" class="form-control" value="<?php echo sanitize($paymentInfo['mobile_money']['number'] ?? ''); ?>" placeholder="078XXXXXXX">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_payment" class="btn-save">
                        <i class="bi bi-check-lg"></i> Save Payment Settings
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- NOTIFICATIONS TAB -->
        <?php if ($activeTab == 'notifications'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Notification Preferences</h2>
            
            <?php
            $notifSettings = json_decode($_SESSION['notification_settings'] ?? '{}', true);
            ?>
            
            <form method="POST">
                <div class="payment-card">
                    <h3 class="payment-card-title"><i class="bi bi-envelope"></i> Email Notifications</h3>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="email_bookings" <?php echo ($notifSettings['email']['bookings'] ?? 1) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>New bookings and reservations</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="email_reminders" <?php echo ($notifSettings['email']['reminders'] ?? 1) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>Pickup and return reminders</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="email_promotions" <?php echo ($notifSettings['email']['promotions'] ?? 0) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>Promotional offers and platform updates</span>
                        </div>
                    </div>
                </div>
                
                <div class="payment-card">
                    <h3 class="payment-card-title"><i class="bi bi-chat-dots"></i> SMS Notifications</h3>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="sms_bookings" <?php echo ($notifSettings['sms']['bookings'] ?? 0) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>SMS alerts for new bookings</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="sms_reminders" <?php echo ($notifSettings['sms']['reminders'] ?? 0) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>SMS reminders for pickups and returns</span>
                        </div>
                    </div>
                </div>
                
                <div class="payment-card">
                    <h3 class="payment-card-title"><i class="bi bi-whatsapp"></i> WhatsApp</h3>
                    
                    <div class="form-group">
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" name="whatsapp_enabled" <?php echo ($notifSettings['whatsapp'] ?? 0) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <span>Enable WhatsApp communication with customers</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_notifications" class="btn-save">
                        <i class="bi bi-check-lg"></i> Save Preferences
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- SECURITY TAB -->
        <?php if ($activeTab == 'security'): ?>
        <div class="settings-section">
            <h2 class="settings-section-title">Security Settings</h2>
            
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required placeholder="Minimum 6 characters" minlength="6">
                        <small class="form-text">Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required placeholder="Re-enter new password">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn-save">
                        <i class="bi bi-shield-check"></i> Change Password
                    </button>
                </div>
            </form>
            
            <hr style="margin: 40px 0; border-color: var(--border-gray);">
            
            <h3 class="payment-card-title" style="color: var(--cars-danger);"><i class="bi bi-exclamation-triangle"></i> Danger Zone</h3>
            
            <div style="background: #fce8e8; padding: 20px; border-radius: var(--radius-sm); border: 1px solid var(--cars-danger);">
                <p style="color: var(--cars-danger); margin-bottom: 16px; font-size: 0.875rem;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Once you delete your account, there is no going back. All your data, including company information, vehicle listings, and booking history will be permanently removed.
                </p>
                <button class="btn-secondary" style="border-color: var(--cars-danger); color: var(--cars-danger);" onclick="confirmDelete()">
                    <i class="bi bi-trash"></i> Delete Account
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Company Modal -->
<div class="modal" id="addCompanyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="bi bi-building-add"></i> Add New Company</h3>
            <button class="modal-close" onclick="closeModal('addCompanyModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Company Name <span class="required">*</span></label>
                    <input type="text" name="company_name" class="form-control" required placeholder="Enter company name">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <input type="tel" name="phone" class="form-control" required placeholder="+250 7XX XXX XXX">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" required placeholder="company@example.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Street address">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Brief description of your company"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addCompanyModal')">Cancel</button>
                <button type="submit" name="add_company" class="btn-save">
                    <i class="bi bi-plus-lg"></i> Add Company
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// SETTINGS FUNCTIONS
// ============================================
function changeCompany(companyId) {
    window.location.href = 'settings.php?tab=company&company=' + companyId;
}

function openAddCompanyModal() {
    openModal('addCompanyModal');
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

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(function(modal) {
            modal.classList.remove('active');
        });
        document.body.style.overflow = 'auto';
    }
});

// ============================================
// DANGER ZONE
// ============================================
function confirmDelete() {
    if (confirm('⚠️ WARNING: Are you absolutely sure you want to delete your account?\\n\\nThis action CANNOT be undone. All your data will be permanently deleted.')) {
        const confirmation = prompt('To confirm deletion, type "DELETE MY ACCOUNT" in the box below:');
        if (confirmation === 'DELETE MY ACCOUNT') {
            alert('Account deletion request submitted. An administrator will process your request within 30 days.');
        } else {
            alert('Account deletion cancelled. The confirmation phrase did not match.');
        }
    }
}

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(function() {
                alert.remove();
            }, 500);
        });
    }, 5000);
});
</script>

<?php require_once 'includes/cars_footer.php'; ?>