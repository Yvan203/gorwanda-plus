<?php
ob_start();
require_once 'includes/functions.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'personal';

// Handle profile image upload
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $filename = $_FILES['profile_image']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (in_array($ext, $allowed)) {
        $newName = 'user_' . $user['user_id'] . '_' . time() . '.' . $ext;
        $uploadPath = __DIR__ . '/assets/uploads/profiles/';
        
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath . $newName)) {
            // Delete old image if exists
            if ($user['profile_image'] && file_exists(__DIR__ . '/assets/uploads/profiles/' . $user['profile_image'])) {
                unlink(__DIR__ . '/assets/uploads/profiles/' . $user['profile_image']);
            }
            
            $stmt = $db->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
            $stmt->execute([$newName, $user['user_id']]);
            $success = 'Profile photo updated successfully';
            
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            $user = $stmt->fetch();
        } else {
            $error = 'Failed to upload image';
        }
    } else {
        $error = 'Invalid file type. Only JPG, PNG, GIF allowed.';
    }
}

// Handle personal info update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeTab === 'personal') {
    $firstName = sanitize($_POST['first_name'] ?? '');
    $lastName = sanitize($_POST['last_name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    
    if (empty($firstName) || empty($lastName)) {
        $error = 'First and last name are required';
    } else {
        $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, updated_at = NOW() WHERE user_id = ?");
        try {
            $stmt->execute([$firstName, $lastName, $phone, $user['user_id']]);
            $success = 'Profile updated successfully';
            $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            $user['first_name'] = $firstName;
            $user['last_name'] = $lastName;
            $user['phone'] = $phone;
        } catch (PDOException $e) {
            $error = 'Update failed. Please try again.';
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeTab === 'security') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
        $error = 'All password fields are required';
    } elseif (!password_verify($currentPass, $user['password_hash'])) {
        $error = 'Current password is incorrect';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New passwords do not match';
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
        $stmt->execute([$newHash, $user['user_id']]);
        $success = 'Password changed successfully';
    }
}

// Get saved properties (favorites)
$stmt = $db->prepare("
    SELECT f.*, s.stay_name, s.main_image, s.city, s.avg_rating, s.review_count,
    (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
    l.name as location_name
    FROM favorites f
    JOIN stays s ON f.stay_id = s.stay_id
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE f.user_id = ? AND f.favorite_type = 'stay'
    ORDER BY f.created_at DESC
");
$stmt->execute([$user['user_id']]);
$savedProperties = $stmt->fetchAll();

$pageTitle = 'My Profile';
$hideSearch = true;
ob_end_flush();
require_once 'includes/header.php';
?>

<style>
.profile-page {
    background: var(--bg-gray);
    min-height: calc(100vh - 64px);
    padding: 32px 0;
}

.profile-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 32px;
}

/* Sidebar */
.profile-sidebar {
    position: sticky;
    top: 20px;
    height: fit-content;
}

.profile-card {
    background: white;
    border-radius: 16px;
    padding: 24px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.profile-avatar-wrapper {
    position: relative;
    width: 120px;
    height: 120px;
    margin: 0 auto 16px;
}

.profile-avatar {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #f3f4f6;
}

.profile-avatar-placeholder {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: linear-gradient(135deg, #0066ff, #003b95);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
    font-weight: 700;
    border: 4px solid #f3f4f6;
}

.profile-upload-btn {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 36px;
    height: 36px;
    background: white;
    border: 2px solid #e7e7e7;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--text-secondary);
}

.profile-upload-btn:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
    transform: scale(1.1);
}

.profile-name {
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 4px;
}

.profile-email {
    color: var(--text-secondary);
    font-size: 0.875rem;
    word-break: break-all;
}

.profile-type {
    display: inline-block;
    margin-top: 12px;
    padding: 6px 16px;
    background: #f3f4f6;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

/* Menu */
.profile-menu {
    margin-top: 16px;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    color: var(--text-primary);
    text-decoration: none;
    font-size: 0.9375rem;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.menu-item:hover, .menu-item.active {
    background: #f8f9fa;
    border-left-color: var(--primary-blue);
    color: var(--primary-blue);
}

.menu-item i {
    font-size: 1.125rem;
    width: 24px;
}

/* Main Content */
.profile-main {
    background: white;
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.section-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e7e7e7;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #e7e7e7;
    border-radius: 8px;
    font-size: 0.9375rem;
    transition: all 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: var(--primary-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.form-input:disabled {
    background: #f5f5f5;
    cursor: not-allowed;
}

.btn-save {
    padding: 14px 32px;
    background: linear-gradient(135deg, #0066ff, #003b95);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,102,255,0.3);
}

/* Saved Properties Grid */
.saved-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.saved-card {
    border: 1px solid #e7e7e7;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.saved-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.saved-image {
    height: 160px;
    position: relative;
}

.saved-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.saved-remove {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 32px;
    height: 32px;
    background: white;
    border-radius: 50%;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    color: var(--text-secondary);
    transition: all 0.2s;
}

.saved-remove:hover {
    color: #dc2626;
    transform: scale(1.1);
}

.saved-content {
    padding: 16px;
}

.saved-title {
    font-weight: 700;
    margin-bottom: 4px;
    font-size: 1rem;
}

.saved-location {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: 12px;
}

.saved-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.saved-rating {
    background: var(--primary-dark);
    color: white;
    padding: 4px 8px;
    border-radius: 4px 4px 4px 0;
    font-weight: 700;
    font-size: 0.875rem;
}

.saved-price {
    text-align: right;
}

.saved-price-value {
    font-weight: 700;
    font-size: 1.125rem;
}

.saved-price-unit {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* Security Section */
.security-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 0;
    border-bottom: 1px solid #e7e7e7;
}

.security-item:last-child {
    border-bottom: none;
}

.security-info h4 {
    font-weight: 600;
    margin-bottom: 4px;
}

.security-info p {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.btn-change {
    padding: 10px 20px;
    border: 1px solid #e7e7e7;
    background: white;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-change:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

/* Alerts */
.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 24px;
}

.alert-success {
    background: #d1fae5;
    color: #059669;
    border: 1px solid #a7f3d0;
}

.alert-danger {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

/* Empty State */
.empty-saved {
    text-align: center;
    padding: 60px 20px;
}

.empty-saved i {
    font-size: 3rem;
    color: #d1d5db;
    margin-bottom: 16px;
}

.empty-saved h3 {
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-saved p {
    color: var(--text-secondary);
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-sidebar {
        position: static;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .saved-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="profile-page">
    <div class="container">
        <div class="profile-grid">
            <!-- Sidebar -->
            <div class="profile-sidebar">
                <div class="profile-card">
                    <div class="profile-avatar-wrapper">
                        <?php if ($user['profile_image']): ?>
                            <img src="/gorwanda-plus/assets/uploads/profiles/<?php echo $user['profile_image']; ?>" 
                                 alt="Profile" class="profile-avatar"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="profile-avatar-placeholder" style="display: none;">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                            </div>
                        <?php else: ?>
                            <div class="profile-avatar-placeholder">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" id="uploadForm" style="display: none;">
                            <input type="file" name="profile_image" id="profileInput" accept="image/*" onchange="document.getElementById('uploadForm').submit();">
                        </form>
                        
                        <label for="profileInput" class="profile-upload-btn" title="Change photo">
                            <i class="bi bi-camera-fill"></i>
                        </label>
                    </div>
                    
                    <div class="profile-name"><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></div>
                    <div class="profile-email"><?php echo sanitize($user['email']); ?></div>
                    <div class="profile-type"><?php echo ucfirst(str_replace('_', ' ', $user['user_type'])); ?></div>
                </div>
                
                <div class="profile-menu">
                    <a href="?tab=personal" class="menu-item <?php echo $activeTab === 'personal' ? 'active' : ''; ?>">
                        <i class="bi bi-person"></i> Personal Info
                    </a>
                    <a href="?tab=saved" class="menu-item <?php echo $activeTab === 'saved' ? 'active' : ''; ?>">
                        <i class="bi bi-heart"></i> Saved Properties
                        <?php if (count($savedProperties) > 0): ?>
                            <span style="margin-left: auto; background: var(--primary-blue); color: white; padding: 2px 8px; border-radius: 100px; font-size: 0.75rem;"><?php echo count($savedProperties); ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?tab=security" class="menu-item <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                        <i class="bi bi-shield-lock"></i> Security
                    </a>
                    <a href="bookings.php" class="menu-item">
                        <i class="bi bi-calendar-check"></i> My Bookings
                    </a>
                    <a href="logout.php" class="menu-item" style="color: #dc2626;">
                        <i class="bi bi-box-arrow-right"></i> Sign Out
                    </a>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="profile-main">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($activeTab === 'personal'): ?>
                    <!-- Personal Info -->
                    <h2 class="section-title">Personal Information</h2>
                    
                    <form method="POST" action="?tab=personal">
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-input" value="<?php echo sanitize($user['first_name']); ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-input" value="<?php echo sanitize($user['last_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-input" value="<?php echo sanitize($user['email']); ?>" disabled>
                            <small style="color: var(--text-secondary); font-size: 0.75rem;">Email cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" value="<?php echo sanitize($user['phone'] ?? ''); ?>" placeholder="078xxxxxxx">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-input" value="<?php echo formatDate($user['created_at'], 'F d, Y'); ?>" disabled>
                        </div>
                        
                        <button type="submit" class="btn-save">Save Changes</button>
                    </form>
                    
                <?php elseif ($activeTab === 'saved'): ?>
                    <!-- Saved Properties -->
                    <h2 class="section-title">Saved Properties</h2>
                    
                    <?php if (empty($savedProperties)): ?>
                        <div class="empty-saved">
                            <i class="bi bi-heart"></i>
                            <h3>No saved properties</h3>
                            <p>Properties you save will appear here for quick access</p>
                            <a href="/gorwanda-plus/search.php?type=stays" class="btn-save" style="display: inline-block; text-decoration: none;">Explore Properties</a>
                        </div>
                    <?php else: ?>
                        <div class="saved-grid">
                            <?php foreach ($savedProperties as $saved): ?>
                            <div class="saved-card">
                                <div class="saved-image">
                                    <img src="<?php echo getImageUrl($saved['main_image'], 'stay'); ?>" 
                                         alt="<?php echo sanitize($saved['stay_name']); ?>"
                                         onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22%3E%3Crect fill=%22%23f5f5f5%22 width=%22400%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                                    <button class="saved-remove" onclick="removeSaved(<?php echo $saved['favorite_id']; ?>)" title="Remove from saved">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <div class="saved-content">
                                    <div class="saved-title"><?php echo sanitize($saved['stay_name']); ?></div>
                                    <div class="saved-location">
                                        <i class="bi bi-geo-alt"></i> <?php echo sanitize($saved['city'] ?? $saved['location_name'] ?? 'Rwanda'); ?>
                                    </div>
                                    <div class="saved-footer">
                                        <span class="saved-rating"><?php echo number_format($saved['avg_rating'], 1); ?></span>
                                        <div class="saved-price">
                                            <div class="saved-price-value"><?php echo formatPrice($saved['min_price']); ?></div>
                                            <div class="saved-price-unit">per night</div>
                                        </div>
                                    </div>
                                    <a href="/gorwanda-plus/stays/detail.php?id=<?php echo $saved['stay_id']; ?>" class="btn-save" style="display: block; text-align: center; margin-top: 12px; text-decoration: none; padding: 10px;">
                                        View Property
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php elseif ($activeTab === 'security'): ?>
                    <!-- Security -->
                    <h2 class="section-title">Security Settings</h2>
                    
                    <div class="security-item">
                        <div class="security-info">
                            <h4>Password</h4>
                            <p>Last changed <?php echo formatDate($user['updated_at'] ?? $user['created_at'], 'F Y'); ?></p>
                        </div>
                        <button class="btn-change" onclick="togglePasswordForm()">Change</button>
                    </div>
                    
                    <form method="POST" action="?tab=security" id="passwordForm" style="display: none; margin-top: 20px; padding: 24px; background: #f9fafb; border-radius: 12px;">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-input" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-input" required>
                        </div>
                        <div style="display: flex; gap: 12px;">
                            <button type="submit" class="btn-save">Update Password</button>
                            <button type="button" class="btn-change" onclick="togglePasswordForm()">Cancel</button>
                        </div>
                    </form>
                    
                    <div class="security-item">
                        <div class="security-info">
                            <h4>Two-Factor Authentication</h4>
                            <p>Add an extra layer of security to your account</p>
                        </div>
                        <button class="btn-change" onclick="alert('2FA setup coming soon')">Enable</button>
                    </div>
                    
                    <div class="security-item">
                        <div class="security-info">
                            <h4>Active Sessions</h4>
                            <p>Manage devices where you're logged in</p>
                        </div>
                        <button class="btn-change" onclick="alert('Session management coming soon')">Manage</button>
                    </div>
                    
                    <div style="margin-top: 40px; padding: 24px; background: #fee2e2; border-radius: 12px;">
                        <h4 style="color: #dc2626; margin-bottom: 8px;"><i class="bi bi-exclamation-triangle me-2"></i>Delete Account</h4>
                        <p style="color: #991b1b; font-size: 0.875rem; margin-bottom: 16px;">Once deleted, your account cannot be recovered. All your data will be permanently removed.</p>
                        <button class="btn-change" style="border-color: #dc2626; color: #dc2626;" onclick="confirmDelete()">Delete Account</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordForm() {
    const form = document.getElementById('passwordForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function confirmDelete() {
    if (confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
        // Submit form to delete account
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?tab=security&action=delete_account';
        document.body.appendChild(form);
        form.submit();
    }
}

function removeSaved(favoriteId) {
    if (confirm('Remove this property from your saved list?')) {
        fetch('ajax/remove_favorite.php?id=' + favoriteId, {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Failed to remove. Please try again.');
            }
        });
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>