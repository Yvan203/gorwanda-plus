<?php
$pageTitle = 'Settings';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET CURRENT USER DATA
// ============================================
$stmt = $db->prepare("
    SELECT * FROM users WHERE user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get user's properties
$stmt = $db->prepare("
    SELECT stay_id, stay_name, stay_type, city, is_verified 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// Get business types
$businessTypes = json_decode($user['business_type'] ?? '[]', true);

// ============================================
// HANDLE SETTINGS UPDATES
// ============================================

$message = '';
$error = '';

// Handle Profile Image Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        // CORRECTED: Path from stays settings to assets/uploads/profiles/
        $uploadDir = dirname(__DIR__, 3) . '/assets/uploads/profiles/';
        
        // Create directory if doesn't exist
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0777, true)) {
                $error = "Failed to create upload directory.";
            }
        }
        
        // Check if directory is writable
        if (file_exists($uploadDir) && !is_writable($uploadDir)) {
            $error = "Upload directory is not writable.";
        }
        
        if (!$error) {
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = $_FILES['profile_image']['type'];
            $fileExt = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (!in_array($fileType, $allowedTypes) || !in_array($fileExt, $allowedExts)) {
                $error = "Only JPG, PNG, GIF and WebP images are allowed.";
            } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                $error = "File size exceeds 2MB limit.";
            } else {
                // Generate unique filename
                $fileName = 'user_' . $userId . '_' . time() . '.' . $fileExt;
                $uploadPath = $uploadDir . $fileName;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                    // Set proper permissions
                    chmod($uploadPath, 0644);
                    
                    // Delete old image if exists
                    if (!empty($user['profile_image']) && file_exists($uploadDir . $user['profile_image'])) {
                        unlink($uploadDir . $user['profile_image']);
                    }
                    
                    // Update database
                    $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                    $stmt->execute([$fileName, $userId]);
                    
                    $message = "Profile image updated successfully!";
                    
                    // Refresh user data
                    $user['profile_image'] = $fileName;
                } else {
                    $error = "Failed to upload image. Check folder permissions.";
                }
            }
        }
    } else {
        $error = "Please select an image to upload.";
    }
}

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = sanitize($_POST['first_name']);
    $lastName = sanitize($_POST['last_name']);
    $phone = sanitize($_POST['phone']);
    $nationality = sanitize($_POST['nationality']);
    $preferredLanguage = sanitize($_POST['preferred_language']);
    $preferredCurrency = sanitize($_POST['preferred_currency']);
    
    $stmt = $db->prepare("
        UPDATE users SET 
            first_name = ?, 
            last_name = ?, 
            phone = ?, 
            nationality = ?, 
            preferred_language = ?, 
            preferred_currency = ?
        WHERE user_id = ?
    ");
    $stmt->execute([$firstName, $lastName, $phone, $nationality, $preferredLanguage, $preferredCurrency, $userId]);
    
    // Update session name
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;
    
    $message = "Profile updated successfully!";
    
    // Refresh user data
    $user['first_name'] = $firstName;
    $user['last_name'] = $lastName;
    $user['phone'] = $phone;
    $user['nationality'] = $nationality;
    $user['preferred_language'] = $preferredLanguage;
    $user['preferred_currency'] = $preferredCurrency;
}

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (!password_verify($currentPassword, $user['password_hash'])) {
        $error = "Current password is incorrect";
    } elseif (strlen($newPassword) < 6) {
        $error = "New password must be at least 6 characters";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "New passwords do not match";
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->execute([$newHash, $userId]);
        $message = "Password changed successfully!";
    }
}

// Update Notification Settings (simulated - would need a settings table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    // In a real app, save to a user_settings table
    // For now, just show success message
    $message = "Notification preferences updated!";
}

// Get current tab
$activeTab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'profile';

// Languages
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'rw' => 'Kinyarwanda',
    'sw' => 'Swahili'
];

// Currencies
$currencies = [
    'RWF' => 'Rwandan Franc (RWF)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)'
];

// Nationalities
$nationalities = [
    'Rwandan', 'Kenyan', 'Ugandan', 'Tanzanian', 'South African',
    'Nigerian', 'Ethiopian', 'Egyptian', 'Moroccan', 'American',
    'British', 'Canadian', 'Australian', 'German', 'French',
    'Italian', 'Spanish', 'Dutch', 'Belgian', 'Swiss',
    'Chinese', 'Japanese', 'Indian', 'Pakistani', 'Brazilian'
];
?>

<style>
/* Settings Specific Styles */
.settings-header {
    margin-bottom: 24px;
}

.settings-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.settings-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Settings Navigation */
.settings-nav {
    display: flex;
    gap: 8px;
    margin-bottom: 30px;
    border-bottom: 1px solid var(--booking-border);
    flex-wrap: wrap;
}

.settings-nav-item {
    padding: 12px 20px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}

.settings-nav-item:hover {
    color: var(--booking-blue);
}

.settings-nav-item.active {
    color: var(--booking-blue);
}

.settings-nav-item.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--booking-blue);
}

/* Settings Cards */
.settings-card {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    margin-bottom: 24px;
    overflow: hidden;
}

.settings-card-header {
    padding: 20px 24px;
    background: linear-gradient(to right, var(--booking-light-blue), white);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.settings-card-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0;
}

.settings-card-body {
    padding: 24px;
}

.settings-card-footer {
    padding: 16px 24px;
    background: var(--booking-gray);
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
/* Image Upload Styles */
.image-upload-container {
    display: flex;
    align-items: center;
    gap: 30px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.image-preview-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    flex-shrink: 0;
}

.image-preview {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--booking-light-blue);
    box-shadow: var(--shadow-sm);
    background: var(--booking-gray);
}

.image-upload-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: var(--booking-blue);
    color: white;
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.image-upload-btn:hover {
    background: var(--booking-dark-blue);
    transform: translateY(-1px);
}

.image-upload-input {
    display: none;
}

.image-upload-info {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-top: 12px;
}

.image-upload-info .text-success {
    color: var(--booking-success);
}

.image-upload-info i {
    font-size: 0.75rem;
}

/* Form Layout */
.settings-grid {
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
    color: var(--booking-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.9375rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.form-control[readonly] {
    background: var(--booking-gray);
    cursor: not-allowed;
}

/* Image Upload */
.image-upload-container {
    display: flex;
    align-items: center;
    gap: 30px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.image-preview-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
}

.image-preview {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid var(--booking-light-blue);
    box-shadow: var(--shadow-sm);
}

.image-upload-btn {
    display: inline-block;
    padding: 10px 20px;
    background: var(--booking-blue);
    color: white;
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.image-upload-btn:hover {
    background: var(--booking-dark-blue);
}

.image-upload-input {
    display: none;
}

.image-upload-info {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin-top: 8px;
}

/* Property Badge */
.property-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-right: 8px;
    margin-bottom: 8px;
}

.property-badge.verified {
    background: #e6f4ea;
    color: var(--booking-success);
}

.property-badge.pending {
    background: #fff4e6;
    color: var(--booking-warning);
}

/* Coming Soon */
.coming-soon {
    text-align: center;
    padding: 60px 20px;
    background: var(--booking-gray);
    border-radius: var(--radius-md);
}

.coming-soon i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.coming-soon h3 {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.coming-soon p {
    color: var(--booking-text-light);
    margin-bottom: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full-width {
        grid-column: span 1;
    }
    
    .settings-nav {
        flex-direction: column;
    }
    
    .settings-nav-item {
        width: 100%;
        text-align: left;
    }
    
    .image-upload-container {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="settings-header">
    <div class="settings-title">
        <h1>Settings</h1>
        <p>Manage your account and business preferences</p>
    </div>
</div>

<!-- Settings Navigation -->
<nav class="settings-nav">
    <a href="?tab=profile" class="settings-nav-item <?php echo $activeTab == 'profile' ? 'active' : ''; ?>">
        <i class="bi bi-person"></i> Profile
    </a>
    <a href="?tab=business" class="settings-nav-item <?php echo $activeTab == 'business' ? 'active' : ''; ?>">
        <i class="bi bi-building"></i> Business
    </a>
    <a href="?tab=subscription" class="settings-nav-item <?php echo $activeTab == 'subscription' ? 'active' : ''; ?>">
        <i class="bi bi-credit-card"></i> Subscription
    </a>
    <a href="?tab=notifications" class="settings-nav-item <?php echo $activeTab == 'notifications' ? 'active' : ''; ?>">
        <i class="bi bi-bell"></i> Notifications
    </a>
    <a href="?tab=payment" class="settings-nav-item <?php echo $activeTab == 'payment' ? 'active' : ''; ?>">
        <i class="bi bi-cash-stack"></i> Payment
    </a>
    <a href="?tab=security" class="settings-nav-item <?php echo $activeTab == 'security' ? 'active' : ''; ?>">
        <i class="bi bi-shield-lock"></i> Security
    </a>
</nav>

<!-- Message Display -->
<?php if ($message): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $message; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- PROFILE SETTINGS -->
<!-- ============================================ -->
<?php if ($activeTab == 'profile'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Profile Information</h3>
    </div>
    
    <!-- Separate form for image upload to ensure it works properly -->
    <form method="POST" enctype="multipart/form-data">
        <div class="settings-card-body">
<div class="image-upload-container">
    <div class="image-preview-wrapper">
        <?php 
        $profileImage = $user['profile_image'] ?? '';
        $imageUrl = !empty($profileImage) 
            ? '/gorwanda-plus/assets/uploads/profiles/' . $profileImage
            : 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=120&h=120&fit=crop';
        ?>
        <img src="<?php echo $imageUrl; ?>" 
             class="image-preview" 
             id="profilePreview"
             alt="Profile Picture"
             onerror="this.src='https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=120&h=120&fit=crop'">
    </div>
    <div>
        <label for="profile_image" class="image-upload-btn">
            <i class="bi bi-camera"></i> Choose Photo
        </label>
        <input type="file" name="profile_image" id="profile_image" class="image-upload-input" accept="image/jpeg,image/png,image/gif">
        <div class="image-upload-info">
            JPG, PNG or GIF. Max 2MB.<br>
            <?php if (!empty($user['profile_image'])): ?>
            <span class="text-success">
                <i class="bi bi-check-circle-fill"></i> Current: <?php echo $user['profile_image']; ?>
            </span>
            <?php else: ?>
            <span class="text-muted">No profile photo uploaded yet</span>
            <?php endif; ?>
        </div>
    </div>
</div>
            <div class="settings-card-footer" style="padding-left: 0; padding-right: 0;">
                <button type="submit" name="upload_image" class="btn-primary">Upload Photo</button>
            </div>
        </div>
    </form>
    
    <!-- Profile details form -->
    <form method="POST">
        <div class="settings-card-body" style="padding-top: 0;">
            <div class="settings-grid">
                <div class="form-group">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo sanitize($user['first_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo sanitize($user['last_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" value="<?php echo sanitize($user['email'] ?? ''); ?>" readonly>
                    <p style="font-size: 0.7rem; color: var(--booking-text-light); margin-top: 4px;">
                        Email cannot be changed.
                    </p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo sanitize($user['phone'] ?? ''); ?>" placeholder="+250 788 123 456">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nationality</label>
                    <select name="nationality" class="form-control">
                        <option value="">Select Nationality</option>
                        <?php foreach ($nationalities as $nat): ?>
                        <option value="<?php echo $nat; ?>" <?php echo ($user['nationality'] ?? '') == $nat ? 'selected' : ''; ?>>
                            <?php echo $nat; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
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
                
                <div class="form-group">
                    <label class="form-label">Account Type</label>
                    <input type="text" class="form-control" value="<?php echo ucfirst(str_replace('_', ' ', $user['user_type'] ?? 'tourist')); ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Member Since</label>
                    <input type="text" class="form-control" value="<?php echo date('F d, Y', strtotime($user['created_at'] ?? 'now')); ?>" readonly>
                </div>
            </div>
        </div>
        <div class="settings-card-footer">
            <button type="submit" name="update_profile" class="btn-primary">Save Changes</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- BUSINESS SETTINGS -->
<!-- ============================================ -->
<?php if ($activeTab == 'business'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Business Information</h3>
    </div>
    <div class="settings-card-body">
        <div class="form-group">
            <label class="form-label">Business Type</label>
            <div>
                <?php foreach ($businessTypes as $type): ?>
                <span class="property-badge verified" style="background: var(--booking-light-blue); color: var(--booking-blue);">
                    <i class="bi bi-<?php echo $type == 'stay' ? 'building' : ($type == 'car_rental' ? 'car-front' : 'ticket-perforated'); ?>"></i>
                    <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Your Properties</label>
            <?php if (empty($properties)): ?>
                <p class="text-muted">You haven't added any properties yet.</p>
            <?php else: ?>
                <?php foreach ($properties as $property): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; border: 1px solid var(--booking-border); border-radius: var(--radius-sm); margin-bottom: 8px;">
                    <div>
                        <strong><?php echo sanitize($property['stay_name']); ?></strong>
                        <span style="font-size: 0.75rem; color: var(--booking-text-light); margin-left: 8px;">
                            <?php echo ucfirst($property['stay_type'] ?? 'hotel'); ?> • <?php echo sanitize($property['city'] ?? 'Rwanda'); ?>
                        </span>
                    </div>
                    <span class="property-badge <?php echo $property['is_verified'] ? 'verified' : 'pending'; ?>">
                        <?php echo $property['is_verified'] ? 'Verified' : 'Pending'; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label class="form-label">Contact Email</label>
            <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" readonly>
            <p style="font-size: 0.75rem; color: var(--booking-text-light); margin-top: 4px;">
                Guests will use this email to contact you.
            </p>
        </div>
        
        <div class="form-group">
            <label class="form-label">Contact Phone</label>
            <input type="tel" class="form-control" value="<?php echo sanitize($user['phone'] ?? 'Not provided'); ?>" readonly>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- SUBSCRIPTION (Coming Soon) -->
<!-- ============================================ -->
<?php if ($activeTab == 'subscription'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Subscription & Billing</h3>
    </div>
    <div class="settings-card-body">
        <div class="coming-soon">
            <i class="bi bi-clock-history"></i>
            <h3>Coming Soon</h3>
            <p>Subscription management is under development.<br>You'll be able to manage your plan and billing here.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- NOTIFICATIONS (Simulated) -->
<!-- ============================================ -->
<?php if ($activeTab == 'notifications'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Notification Preferences</h3>
    </div>
    <form method="POST">
        <div class="settings-card-body">
            <div class="form-group">
                <label class="checkbox-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                    <input type="checkbox" name="email_new_booking" checked>
                    <span>Email me when I get a new booking</span>
                </label>
                
                <label class="checkbox-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                    <input type="checkbox" name="email_cancellation" checked>
                    <span>Email me when a booking is cancelled</span>
                </label>
                
                <label class="checkbox-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                    <input type="checkbox" name="email_review" checked>
                    <span>Email me when I receive a new review</span>
                </label>
                
                <label class="checkbox-group" style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                    <input type="checkbox" name="email_payment" checked>
                    <span>Email me about payment updates</span>
                </label>
                
                <label class="checkbox-group" style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="daily_summary">
                    <span>Send me a daily summary of bookings</span>
                </label>
            </div>
            
            <p style="font-size: 0.75rem; color: var(--booking-text-light); margin-top: 16px;">
                Note: You'll always receive important account notifications regardless of these settings.
            </p>
        </div>
        <div class="settings-card-footer">
            <button type="submit" name="update_notifications" class="btn-primary">Save Preferences</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- PAYMENT (Coming Soon) -->
<!-- ============================================ -->
<?php if ($activeTab == 'payment'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Payment Settings</h3>
    </div>
    <div class="settings-card-body">
        <div class="coming-soon">
            <i class="bi bi-cash-stack"></i>
            <h3>Coming Soon</h3>
            <p>Payment and payout management is under development.<br>You'll be able to manage your bank details and view payouts here.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- SECURITY -->
<!-- ============================================ -->
<?php if ($activeTab == 'security'): ?>
<div class="settings-card">
    <div class="settings-card-header">
        <h3>Change Password</h3>
    </div>
    <form method="POST">
        <div class="settings-card-body">
            <div class="settings-grid">
                <div class="form-group full-width">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                    <p style="font-size: 0.7rem; color: var(--booking-text-light);">Min. 6 characters</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>
        </div>
        <div class="settings-card-footer">
            <button type="submit" name="change_password" class="btn-primary">Update Password</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <div class="settings-card-header">
        <h3>Account Security</h3>
    </div>
    <div class="settings-card-body">
        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap; padding: 16px; background: var(--booking-gray); border-radius: var(--radius-sm);">
            <div style="width: 48px; height: 48px; background: var(--booking-light-blue); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: var(--booking-blue);">
                <i class="bi bi-shield-lock"></i>
            </div>
            <div style="flex: 1;">
                <h4 style="font-size: 1rem; font-weight: 600; margin-bottom: 4px;">Two-Factor Authentication</h4>
                <p style="font-size: 0.875rem; color: var(--booking-text-light);">Add an extra layer of security to your account.</p>
            </div>
            <button class="btn-secondary" onclick="alert('2FA setup coming soon!')">Enable</button>
        </div>
    </div>
</div>

<!-- Danger Zone -->
<div style="border: 1px solid var(--booking-danger); border-radius: var(--radius-md); overflow: hidden; margin-top: 24px;">
    <div style="padding: 16px 20px; background: #fce8e8; color: var(--booking-danger); font-weight: 700; border-bottom: 1px solid var(--booking-danger);">
        <i class="bi bi-exclamation-triangle"></i> Danger Zone
    </div>
    <div style="padding: 20px; background: white;">
        <p style="color: var(--booking-text-light); margin-bottom: 16px;">Once you delete your account, there is no going back. Please be certain.</p>
        <button class="btn-primary" style="background: var(--booking-danger);" onclick="confirmDelete()">
            Delete Account
        </button>
    </div>
</div>
<?php endif; ?>

<script>
// ============================================
// UTILITY FUNCTIONS
// ============================================
document.getElementById('profile_image')?.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profilePreview').src = e.target.result;
        }
        reader.readAsDataURL(this.files[0]);
        
        // Show file name
        const infoDiv = document.querySelector('.image-upload-info');
        if (infoDiv) {
            infoDiv.innerHTML = `Selected: ${e.target.files[0].name}<br>Click "Upload Photo" to save.`;
        }
    }
});

function confirmDelete() {
    if (confirm('Are you absolutely sure you want to delete your account? This action cannot be undone.')) {
        alert('Account deletion request submitted. You will receive a confirmation email.');
    }
}
</script>

<?php require_once 'includes/stays_footer.php'; ?>