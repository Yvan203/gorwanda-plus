<?php
$pageTitle = 'Settings';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET CURRENT USER DATA
// ============================================
$stmt = $db->prepare("
    SELECT user_id, first_name, last_name, email, phone, profile_image, 
           preferred_language, preferred_currency, created_at
    FROM users 
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get business types
$stmt = $db->prepare("SELECT business_type FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userData = $stmt->fetch();
$businessTypes = json_decode($userData['business_type'] ?? '[]', true);

// Get statistics for the sidebar
$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM attractions WHERE owner_id = ?) as total_experiences,
        (SELECT COUNT(*) FROM attractions WHERE owner_id = ? AND is_active = 1) as active_experiences,
        (SELECT COUNT(*) FROM attraction_tiers at 
         JOIN attractions a ON at.attraction_id = a.attraction_id 
         WHERE a.owner_id = ?) as total_tiers,
        (SELECT COUNT(*) FROM bookings b 
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
         JOIN attractions a ON at.attraction_id = a.attraction_id
         WHERE a.owner_id = ? AND b.status = 'pending') as pending_bookings
");
$stmt->execute([$userId, $userId, $userId, $userId]);
$stats = $stmt->fetch();

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================
$message = '';
$error = '';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = sanitize($_POST['first_name']);
    $lastName = sanitize($_POST['last_name']);
    $phone = sanitize($_POST['phone']);
    $preferredLanguage = sanitize($_POST['preferred_language']);
    $preferredCurrency = sanitize($_POST['preferred_currency']);
    
    if (empty($firstName) || empty($lastName)) {
        $error = 'First name and last name are required';
    } else {
        $stmt = $db->prepare("
            UPDATE users SET 
                first_name = ?, 
                last_name = ?, 
                phone = ?,
                preferred_language = ?,
                preferred_currency = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([$firstName, $lastName, $phone, $preferredLanguage, $preferredCurrency, $userId]);
        
        // Update session name
        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
        
        $message = 'Profile updated successfully';
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

// Update Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Verify current password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
    
    if (!password_verify($currentPassword, $userData['password_hash'])) {
        $error = 'Current password is incorrect';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->execute([$newHash, $userId]);
        $message = 'Password changed successfully';
    }
}

// Upload Profile Image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = dirname(__DIR__, 3) . '/assets/uploads/profiles/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['profile_image']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $error = 'Invalid file type. Please upload JPG, PNG, GIF, or WEBP.';
        } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
            $error = 'File too large. Maximum size is 2MB.';
        } else {
            // Delete old image if exists
            if (!empty($user['profile_image'])) {
                $oldFile = $uploadDir . $user['profile_image'];
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
            
            $fileExt = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $fileName = 'user_' . $userId . '_' . time() . '.' . $fileExt;
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                $stmt->execute([$fileName, $userId]);
                $message = 'Profile image updated successfully';
                
                // Refresh user data
                $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            } else {
                $error = 'Failed to upload image';
            }
        }
    }
}

// Delete Profile Image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image'])) {
    if (!empty($user['profile_image'])) {
        $uploadDir = dirname(__DIR__, 3) . '/assets/uploads/profiles/';
        $filePath = $uploadDir . $user['profile_image'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        $stmt = $db->prepare("UPDATE users SET profile_image = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
        $message = 'Profile image removed';
        
        // Refresh user data
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

// Notification Settings (stored in session or you might want a separate table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    $emailBookings = isset($_POST['email_bookings']) ? 1 : 0;
    $emailReviews = isset($_POST['email_reviews']) ? 1 : 0;
    $emailPromotions = isset($_POST['email_promotions']) ? 1 : 0;
    $smsBookings = isset($_POST['sms_bookings']) ? 1 : 0;
    
    // Store in session for now (you may want to create a notifications table)
    $_SESSION['notification_settings'] = [
        'email_bookings' => $emailBookings,
        'email_reviews' => $emailReviews,
        'email_promotions' => $emailPromotions,
        'sms_bookings' => $smsBookings
    ];
    
    $message = 'Notification preferences updated';
}

// Business Information (using existing user table fields)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_business'])) {
    $businessName = sanitize($_POST['business_name']);
    $businessPhone = sanitize($_POST['business_phone']);
    $businessEmail = sanitize($_POST['business_email']);
    $businessAddress = sanitize($_POST['business_address']);
    
    // Store in session or you might want to create a business_profiles table
    $_SESSION['business_info'] = [
        'name' => $businessName,
        'phone' => $businessPhone,
        'email' => $businessEmail,
        'address' => $businessAddress
    ];
    
    $message = 'Business information updated';
}

// Default notification settings
$notificationSettings = $_SESSION['notification_settings'] ?? [
    'email_bookings' => 1,
    'email_reviews' => 1,
    'email_promotions' => 0,
    'sms_bookings' => 0
];

// Default business info
$businessInfo = $_SESSION['business_info'] ?? [
    'name' => $user['first_name'] . ' ' . $user['last_name'] . ' Experiences',
    'phone' => $user['phone'] ?? '',
    'email' => $user['email'] ?? '',
    'address' => ''
];

// Language options
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'rw' => 'Kinyarwanda',
    'sw' => 'Swahili'
];

// Currency options
$currencies = [
    'RWF' => 'Rwandan Franc (RWF)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)'
];
?>

<style>
/* Settings Specific Styles */
.settings-container {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 24px;
    background: var(--exp-gray);
    min-height: calc(100vh - 64px);
}

/* Sidebar */
.settings-sidebar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    position: sticky;
    top: 20px;
    height: fit-content;
}

.sidebar-header {
    padding: 24px 20px;
    background: linear-gradient(135deg, var(--exp-purple), var(--exp-dark-purple));
    color: white;
    text-align: center;
}

.sidebar-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin: 0 auto 16px;
    position: relative;
    cursor: pointer;
    overflow: hidden;
    border: 3px solid white;
}

.sidebar-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sidebar-avatar-placeholder {
    width: 100%;
    height: 100%;
    background: white;
    color: var(--exp-purple);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
}

.sidebar-name {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.sidebar-email {
    font-size: 0.75rem;
    opacity: 0.9;
}

.sidebar-stats {
    display: flex;
    justify-content: space-around;
    padding: 16px;
    border-bottom: 1px solid var(--exp-border);
    background: var(--exp-gray);
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-purple);
    line-height: 1.2;
}

.stat-label {
    font-size: 0.625rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.sidebar-menu {
    padding: 16px 0;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: var(--exp-text);
    text-decoration: none;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.menu-item:hover {
    background: var(--exp-light-purple);
    color: var(--exp-purple);
}

.menu-item.active {
    background: var(--exp-light-purple);
    color: var(--exp-purple);
    border-left-color: var(--exp-purple);
    font-weight: 600;
}

.menu-item i {
    font-size: 1.125rem;
    width: 24px;
    text-align: center;
}

.menu-item.danger {
    color: var(--exp-danger);
}

.menu-item.danger:hover {
    background: #fce8e8;
    color: var(--exp-danger);
}

/* Main Content */
.settings-main {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 30px;
}

.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--exp-border);
}

.settings-header h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0;
}

.settings-header p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 4px 0 0 0;
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
    color: var(--exp-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-label .required {
    color: var(--exp-danger);
    margin-left: 2px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

.form-control[readonly] {
    background: var(--exp-gray);
    cursor: not-allowed;
}

.form-text {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 4px;
}

/* Image Upload */
.image-upload-area {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: var(--exp-gray);
    border-radius: var(--radius-md);
    border: 1px dashed var(--exp-border);
}

.image-preview {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    overflow: hidden;
    border: 3px solid white;
    box-shadow: var(--shadow-sm);
}

.image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-preview-placeholder {
    width: 100%;
    height: 100%;
    background: var(--exp-light-purple);
    color: var(--exp-purple);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 700;
}

.image-upload-actions {
    flex: 1;
}

.image-upload-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 8px;
}

/* Checkbox Group */
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: var(--exp-gray);
    border-radius: var(--radius-sm);
    border: 1px solid var(--exp-border);
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--exp-purple);
}

.checkbox-group label {
    flex: 1;
    font-size: 0.875rem;
    cursor: pointer;
}

.checkbox-group small {
    color: var(--exp-text-light);
    font-size: 0.6875rem;
}

/* Danger Zone */
.danger-zone {
    margin-top: 40px;
    padding: 20px;
    background: #fce8e8;
    border-radius: var(--radius-md);
    border: 1px solid #fecaca;
}

.danger-zone h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--exp-danger);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.danger-zone p {
    font-size: 0.8125rem;
    color: #7f1d1d;
    margin-bottom: 16px;
}

.btn-danger {
    background: var(--exp-danger);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.8125rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Buttons */
.btn-primary {
    background: var(--exp-purple);
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.8125rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary:hover {
    background: var(--exp-dark-purple);
}

.btn-secondary {
    background: white;
    color: var(--exp-text);
    border: 1px solid var(--exp-border);
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.8125rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-secondary:hover {
    background: var(--exp-gray);
}

.btn-outline {
    background: transparent;
    color: var(--exp-purple);
    border: 1px solid var(--exp-purple);
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-outline:hover {
    background: var(--exp-light-purple);
}

/* Alerts */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-success {
    background: #e6f4ea;
    color: #10b981;
    border: 1px solid #a7f3d0;
}

.alert-danger {
    background: #fce8e8;
    color: #ef4444;
    border: 1px solid #fecaca;
}

/* Modal */
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
    max-width: 400px;
    padding: 24px;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    text-align: center;
    margin-bottom: 20px;
}

.modal-header i {
    font-size: 3rem;
    color: var(--exp-danger);
    margin-bottom: 12px;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.modal-header p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
}

.modal-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
}

/* Responsive */
@media (max-width: 992px) {
    .settings-container {
        grid-template-columns: 1fr;
    }
    
    .settings-sidebar {
        position: static;
    }
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full-width {
        grid-column: span 1;
    }
    
    .image-upload-area {
        flex-direction: column;
        text-align: center;
    }
    
    .image-upload-buttons {
        justify-content: center;
    }
}
</style>

<div class="settings-container">
    <!-- Sidebar -->
    <div class="settings-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-avatar" onclick="document.getElementById('imageInput').click()">
                <?php if (!empty($user['profile_image'])): ?>
                    <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $user['profile_image']; ?>" alt="Profile">
                <?php else: ?>
                    <div class="sidebar-avatar-placeholder">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="sidebar-name"><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></div>
            <div class="sidebar-email"><?php echo sanitize($user['email']); ?></div>
        </div>
        
        <div class="sidebar-stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['total_experiences']; ?></div>
                <div class="stat-label">Experiences</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['total_tiers']; ?></div>
                <div class="stat-label">Tiers</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['pending_bookings']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <a href="?tab=profile" class="menu-item <?php echo $activeTab == 'profile' ? 'active' : ''; ?>">
                <i class="bi bi-person"></i>
                Profile Information
            </a>
            <a href="?tab=password" class="menu-item <?php echo $activeTab == 'password' ? 'active' : ''; ?>">
                <i class="bi bi-shield-lock"></i>
                Password & Security
            </a>
            <a href="?tab=business" class="menu-item <?php echo $activeTab == 'business' ? 'active' : ''; ?>">
                <i class="bi bi-building"></i>
                Business Information
            </a>
            <a href="?tab=notifications" class="menu-item <?php echo $activeTab == 'notifications' ? 'active' : ''; ?>">
                <i class="bi bi-bell"></i>
                Notifications
            </a>
            <a href="?tab=preferences" class="menu-item <?php echo $activeTab == 'preferences' ? 'active' : ''; ?>">
                <i class="bi bi-sliders2"></i>
                Preferences
            </a>
            <hr style="margin: 16px 20px; border-color: var(--exp-border);">
            <a href="#" class="menu-item danger" onclick="openDeleteModal()">
                <i class="bi bi-exclamation-triangle"></i>
                Delete Account
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="settings-main">
        <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <!-- PROFILE TAB -->
        <?php if ($activeTab == 'profile'): ?>
        <div class="settings-header">
            <div>
                <h2>Profile Information</h2>
                <p>Update your personal information and profile picture</p>
            </div>
        </div>
        
        <!-- Profile Image Upload -->
        <form method="POST" enctype="multipart/form-data" style="margin-bottom: 30px;">
            <div class="image-upload-area">
                <div class="image-preview">
                    <?php if (!empty($user['profile_image'])): ?>
                        <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $user['profile_image']; ?>" alt="Profile">
                    <?php else: ?>
                        <div class="image-preview-placeholder">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="image-upload-actions">
                    <div class="image-upload-buttons">
                        <button type="button" class="btn-outline" onclick="document.getElementById('imageInput').click()">
                            <i class="bi bi-cloud-upload"></i> Upload New
                        </button>
                        <?php if (!empty($user['profile_image'])): ?>
                        <button type="submit" name="delete_image" class="btn-outline" style="color: var(--exp-danger); border-color: var(--exp-danger);" onclick="return confirm('Remove profile picture?')">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                        <?php endif; ?>
                    </div>
                    <p class="form-text">JPG, PNG, GIF, WEBP. Max 2MB.</p>
                    <input type="file" name="profile_image" id="imageInput" accept="image/*" style="display: none;" onchange="this.form.submit()">
                    <input type="hidden" name="upload_image" value="1">
                </div>
            </div>
        </form>
        
        <!-- Profile Form -->
        <form method="POST">
            <div class="form-grid">
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
                    <p class="form-text">Email cannot be changed</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo sanitize($user['phone'] ?? ''); ?>" placeholder="+250 788 123 456">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Preferred Language</label>
                    <select name="preferred_language" class="form-control">
                        <?php foreach ($languages as $code => $lang): ?>
                        <option value="<?php echo $code; ?>" <?php echo ($user['preferred_language'] ?? 'en') == $code ? 'selected' : ''; ?>>
                            <?php echo $lang; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Preferred Currency</label>
                    <select name="preferred_currency" class="form-control">
                        <?php foreach ($currencies as $code => $curr): ?>
                        <option value="<?php echo $code; ?>" <?php echo ($user['preferred_currency'] ?? 'RWF') == $code ? 'selected' : ''; ?>>
                            <?php echo $curr; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Member Since</label>
                    <input type="text" class="form-control" value="<?php echo date('F j, Y', strtotime($user['created_at'])); ?>" readonly>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" name="update_profile" class="btn-primary">
                    <i class="bi bi-check-lg"></i> Save Changes
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- PASSWORD TAB -->
        <?php if ($activeTab == 'password'): ?>
        <div class="settings-header">
            <div>
                <h2>Password & Security</h2>
                <p>Change your password and manage security settings</p>
            </div>
        </div>
        
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                    <p class="form-text">Minimum 6 characters</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" name="update_password" class="btn-primary">
                    <i class="bi bi-shield-lock"></i> Update Password
                </button>
            </div>
        </form>
        
        <div style="margin-top: 40px; padding: 20px; background: var(--exp-gray); border-radius: var(--radius-md);">
            <h4 style="font-size: 1rem; font-weight: 700; margin-bottom: 12px;">Security Tips</h4>
            <ul style="font-size: 0.8125rem; color: var(--exp-text-light); line-height: 1.6; padding-left: 20px;">
                <li>Use a strong password with letters, numbers, and symbols</li>
                <li>Never share your password with anyone</li>
                <li>Change your password regularly</li>
                <li>Enable two-factor authentication for extra security (coming soon)</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- BUSINESS TAB -->
        <?php if ($activeTab == 'business'): ?>
        <div class="settings-header">
            <div>
                <h2>Business Information</h2>
                <p>Manage your business details</p>
            </div>
        </div>
        
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label">Business Name</label>
                    <input type="text" name="business_name" class="form-control" value="<?php echo sanitize($businessInfo['name']); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Business Phone</label>
                    <input type="tel" name="business_phone" class="form-control" value="<?php echo sanitize($businessInfo['phone']); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Business Email</label>
                    <input type="email" name="business_email" class="form-control" value="<?php echo sanitize($businessInfo['email']); ?>">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Business Address</label>
                    <textarea name="business_address" class="form-control" rows="3"><?php echo sanitize($businessInfo['address']); ?></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Business Types</label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php foreach ($businessTypes as $type): ?>
                        <span class="badge" style="background: var(--exp-light-purple); color: var(--exp-purple); padding: 6px 12px; border-radius: 100px; font-size: 0.75rem;">
                            <i class="bi bi-<?php echo $type == 'stay' ? 'building' : ($type == 'car_rental' ? 'car-front' : 'ticket-perforated'); ?>"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <button type="submit" name="update_business" class="btn-primary">
                    <i class="bi bi-building"></i> Update Business Info
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- NOTIFICATIONS TAB -->
        <?php if ($activeTab == 'notifications'): ?>
        <div class="settings-header">
            <div>
                <h2>Notification Preferences</h2>
                <p>Choose how you want to be notified</p>
            </div>
        </div>
        
        <form method="POST">
            <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 30px;">
                <div class="checkbox-group">
                    <input type="checkbox" name="email_bookings" id="email_bookings" value="1" <?php echo $notificationSettings['email_bookings'] ? 'checked' : ''; ?>>
                    <label for="email_bookings">
                        <strong>Email - New Bookings</strong>
                        <br>
                        <small>Receive an email when you get a new booking</small>
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="email_reviews" id="email_reviews" value="1" <?php echo $notificationSettings['email_reviews'] ? 'checked' : ''; ?>>
                    <label for="email_reviews">
                        <strong>Email - New Reviews</strong>
                        <br>
                        <small>Receive an email when guests leave reviews</small>
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="email_promotions" id="email_promotions" value="1" <?php echo $notificationSettings['email_promotions'] ? 'checked' : ''; ?>>
                    <label for="email_promotions">
                        <strong>Email - Promotions & Updates</strong>
                        <br>
                        <small>Receive marketing emails and platform updates</small>
                    </label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="sms_bookings" id="sms_bookings" value="1" <?php echo $notificationSettings['sms_bookings'] ? 'checked' : ''; ?>>
                    <label for="sms_bookings">
                        <strong>SMS - Urgent Bookings</strong>
                        <br>
                        <small>Get text messages for same-day bookings</small>
                    </label>
                </div>
            </div>
            
            <div>
                <button type="submit" name="update_notifications" class="btn-primary">
                    <i class="bi bi-bell"></i> Save Preferences
                </button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- PREFERENCES TAB -->
        <?php if ($activeTab == 'preferences'): ?>
        <div class="settings-header">
            <div>
                <h2>Preferences</h2>
                <p>Customize your dashboard experience</p>
            </div>
        </div>
        
        <div style="padding: 20px; background: var(--exp-gray); border-radius: var(--radius-md);">
            <p style="font-size: 0.9375rem; color: var(--exp-text-light); margin-bottom: 16px;">
                <i class="bi bi-info-circle"></i> More preference options coming soon!
            </p>
            <ul style="font-size: 0.8125rem; color: var(--exp-text-light); line-height: 2;">
                <li>⚡ Dashboard layout customization</li>
                <li>📊 Default date ranges for reports</li>
                <li>🌙 Dark mode toggle</li>
                <li>📱 Mobile app preferences</li>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- DANGER ZONE (visible in all tabs) -->
        <div class="danger-zone">
            <h3>
                <i class="bi bi-exclamation-triangle-fill"></i>
                Danger Zone
            </h3>
            <p>Once you delete your account, there is no going back. Please be certain.</p>
            <button class="btn-danger" onclick="openDeleteModal()">
                <i class="bi bi-trash"></i> Delete Account
            </button>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <h3>Delete Account</h3>
            <p>This action cannot be undone. All your data will be permanently removed.</p>
        </div>
        <form method="POST" action="/gorwanda-plus/delete-account.php" style="text-align: center;">
            <p style="font-size: 0.875rem; margin-bottom: 20px; padding: 12px; background: var(--exp-gray); border-radius: var(--radius-sm);">
                This will delete:
            </p>
            <ul style="text-align: left; font-size: 0.8125rem; color: var(--exp-text-light); margin-bottom: 20px; padding-left: 20px;">
                <li>Your profile information</li>
                <li>All your experiences and tiers</li>
                <li>Booking history and reviews</li>
                <li>All associated data</li>
            </ul>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="confirm_delete" class="btn-danger">Permanently Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// MODAL FUNCTIONS
// ============================================
function openDeleteModal() {
    document.getElementById('deleteModal').classList.add('active');
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

// Close on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeModal('deleteModal');
    }
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>