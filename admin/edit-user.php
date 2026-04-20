<?php
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$userId) {
    header('Location: users.php');
    exit;
}

$pageTitle = 'Edit User';
require_once 'includes/admin_header.php';

$db = getDB();

// Get user details
$stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $user_type = sanitize($_POST['user_type'] ?? 'tourist');
    $business_type = isset($_POST['business_type']) ? json_encode($_POST['business_type']) : '[]';
    $date_of_birth = !empty($_POST['date_of_birth']) ? sanitize($_POST['date_of_birth']) : null;
    $nationality = sanitize($_POST['nationality'] ?? '');
    $preferred_language = sanitize($_POST['preferred_language'] ?? 'en');
    $preferred_currency = sanitize($_POST['preferred_currency'] ?? 'RWF');
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Password change
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    if (empty($email)) {
        $errors[] = "Email address is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }
    
    // Check if email is taken by another user
    $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        $errors[] = "Email address is already in use by another user";
    }
    
    // Password validation
    if (!empty($new_password)) {
        if (strlen($new_password) < 6) {
            $errors[] = "Password must be at least 6 characters";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
    }
    
    // Handle profile image upload
    $profile_image = $user['profile_image'];
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/uploads/profiles/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . $userId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        // Delete old image if exists
        if ($profile_image && file_exists($upload_dir . $profile_image)) {
            unlink($upload_dir . $profile_image);
        }
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
            $profile_image = $filename;
        } else {
            $errors[] = "Failed to upload profile image";
        }
    }
    
    if (empty($errors)) {
        // Build update query
        $sql = "
            UPDATE users SET
                first_name = ?,
                last_name = ?,
                email = ?,
                phone = ?,
                user_type = ?,
                business_type = ?,
                date_of_birth = ?,
                nationality = ?,
                preferred_language = ?,
                preferred_currency = ?,
                profile_image = ?,
                is_verified = ?,
                is_active = ?,
                updated_at = NOW()
        ";
        $params = [
            $first_name, $last_name, $email, $phone, $user_type,
            $business_type, $date_of_birth, $nationality,
            $preferred_language, $preferred_currency, $profile_image,
            $is_verified, $is_active
        ];
        
        // Add password if changed
        if (!empty($new_password)) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password_hash = ?";
            $params[] = $password_hash;
        }
        
        $sql .= " WHERE user_id = ?";
        $params[] = $userId;
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            $success = true;
            $_SESSION['success'] = "User updated successfully";
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        } else {
            $errors[] = "Failed to update user";
        }
    }
}

// Get available languages and currencies
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'rw' => 'Kinyarwanda',
    'sw' => 'Swahili'
];

$currencies = [
    'RWF' => 'Rwandan Franc (RWF)',
    'USD' => 'US Dollar (USD)',
    'EUR' => 'Euro (EUR)',
    'GBP' => 'British Pound (GBP)',
    'KES' => 'Kenyan Shilling (KES)',
    'UGX' => 'Ugandan Shilling (UGX)',
    'TZS' => 'Tanzanian Shilling (TZS)'
];

$businessTypes = [
    'stay' => 'Accommodation / Stay',
    'car_rental' => 'Car Rental',
    'attraction' => 'Experience / Attraction'
];

$userTypes = [
    'tourist' => 'Tourist / Guest',
    'business_owner' => 'Business Partner',
    'admin' => 'Administrator'
];

$currentBusinessTypes = $user['business_type'] ? json_decode($user['business_type'], true) : [];

// Get user statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN status IN ('confirmed', 'completed') THEN 1 END) as confirmed_bookings,
        COALESCE(SUM(CASE WHEN status IN ('confirmed', 'completed') THEN total_amount END), 0) as total_spent,
        COUNT(DISTINCT CASE WHEN booking_type = 'stay' THEN 1 END) as stay_bookings,
        COUNT(DISTINCT CASE WHEN booking_type = 'car_rental' THEN 1 END) as car_bookings,
        COUNT(DISTINCT CASE WHEN booking_type = 'attraction' THEN 1 END) as attraction_bookings
    FROM bookings
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
?>

<style>
/* Edit User Styles */
.edit-form {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 24px;
}

.form-section {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--booking-border);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--booking-text);
}

.form-section-title i {
    color: var(--booking-blue);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
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

.form-group label.required::after {
    content: "*";
    color: var(--booking-danger);
    margin-left: 4px;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    transition: all var(--transition-fast);
    background: var(--booking-white);
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

select.form-control {
    cursor: pointer;
}

.form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}

.form-check input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-check label {
    margin: 0;
    cursor: pointer;
    text-transform: none;
    font-weight: normal;
}

/* Image Upload */
.profile-image-section {
    text-align: center;
    margin-bottom: 20px;
}

.current-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    margin: 0 auto 16px;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 700;
    color: white;
    overflow: hidden;
}

.current-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.image-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: var(--radius-md);
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    margin-top: 16px;
}

.image-upload-area:hover {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
}

.image-upload-area i {
    font-size: 1.5rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
}

.image-upload-area p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Card */
.stats-card {
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 24px;
}

.stats-grid-mini {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
}

.stat-mini {
    text-align: center;
}

.stat-mini-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-mini-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

/* Business Types */
.business-types-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 12px;
    margin-top: 8px;
}

.business-type-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.business-type-checkbox:hover {
    background: var(--booking-gray-light);
    border-color: var(--booking-blue);
}

.business-type-checkbox input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.business-type-checkbox label {
    margin: 0;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: normal;
    text-transform: none;
}

/* Action Buttons */
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--booking-border);
}

.btn {
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.btn-primary:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
}

.btn-secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
}

.btn-secondary:hover {
    background: var(--booking-gray-dark);
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
    border: 1px solid rgba(0,128,9,0.2);
}

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
    border: 1px solid rgba(226,17,17,0.2);
}

/* Responsive */
@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
    
    .edit-form {
        padding: 16px;
    }
    
    .stats-grid-mini {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .business-types-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="detail-header">
    <a href="user-detail.php?id=<?php echo $userId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to User Details
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        User updated successfully!
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <div>
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Please fix the following errors:</strong>
        <ul style="margin: 8px 0 0 20px;">
            <?php foreach ($errors as $error): ?>
            <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<form method="POST" action="edit-user.php?id=<?php echo $userId; ?>" enctype="multipart/form-data" class="edit-form">
    <!-- User Statistics -->
    <div class="stats-card">
        <div class="stats-grid-mini">
            <div class="stat-mini">
                <div class="stat-mini-value"><?php echo number_format($stats['total_bookings']); ?></div>
                <div class="stat-mini-label">Total Bookings</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-value"><?php echo number_format($stats['confirmed_bookings']); ?></div>
                <div class="stat-mini-label">Confirmed</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-value"><?php echo formatPrice($stats['total_spent']); ?></div>
                <div class="stat-mini-label">Total Spent</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-value"><?php echo number_format($stats['stay_bookings']); ?></div>
                <div class="stat-mini-label">Stays</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-value"><?php echo number_format($stats['car_bookings']); ?></div>
                <div class="stat-mini-label">Cars</div>
            </div>
        </div>
    </div>
    
    <!-- Profile Image -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-image"></i>
            Profile Picture
        </h3>
        
        <div class="profile-image-section">
            <div class="current-avatar">
                <?php if ($user['profile_image']): ?>
                <img src="<?php echo getImageUrl($user['profile_image'], 'profile'); ?>" alt="<?php echo sanitize($user['first_name']); ?>">
                <?php else: ?>
                <?php echo $initials; ?>
                <?php endif; ?>
            </div>
            
            <div class="image-upload-area" onclick="document.getElementById('profile_image_input').click()">
                <i class="bi bi-cloud-upload"></i>
                <p>Click to upload new profile picture</p>
                <p style="font-size: 0.625rem;">Recommended size: 400x400px (JPG, PNG)</p>
                <input type="file" id="profile_image_input" name="profile_image" accept="image/*" style="display: none;" onchange="previewProfileImage(this)">
            </div>
        </div>
    </div>
    
    <!-- Personal Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-person"></i>
            Personal Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">First Name</label>
                <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="required">Last Name</label>
                <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone']); ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" class="form-control" value="<?php echo $user['date_of_birth']; ?>">
            </div>
            
            <div class="form-group">
                <label>Nationality</label>
                <input type="text" name="nationality" class="form-control" value="<?php echo htmlspecialchars($user['nationality']); ?>" placeholder="e.g., Rwandan">
            </div>
        </div>
    </div>
    
    <!-- Account Settings -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-gear"></i>
            Account Settings
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>User Type</label>
                <select name="user_type" class="form-control">
                    <?php foreach ($userTypes as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo $user['user_type'] == $value ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Preferred Language</label>
                <select name="preferred_language" class="form-control">
                    <?php foreach ($languages as $code => $name): ?>
                    <option value="<?php echo $code; ?>" <?php echo $user['preferred_language'] == $code ? 'selected' : ''; ?>>
                        <?php echo $name; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Preferred Currency</label>
                <select name="preferred_currency" class="form-control">
                    <?php foreach ($currencies as $code => $name): ?>
                    <option value="<?php echo $code; ?>" <?php echo $user['preferred_currency'] == $code ? 'selected' : ''; ?>>
                        <?php echo $name; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Business Types (only for business owners) -->
        <div id="businessTypesSection" style="display: <?php echo $user['user_type'] == 'business_owner' ? 'block' : 'none'; ?>">
            <div class="form-group">
                <label>Business Types</label>
                <div class="business-types-grid">
                    <?php foreach ($businessTypes as $value => $label): ?>
                    <label class="business-type-checkbox">
                        <input type="checkbox" name="business_type[]" value="<?php echo $value; ?>"
                            <?php echo in_array($value, $currentBusinessTypes) ? 'checked' : ''; ?>>
                        <span><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small style="font-size: 0.625rem; color: var(--booking-text-light); display: block; margin-top: 8px;">
                    Select the types of businesses this partner operates
                </small>
            </div>
        </div>
    </div>
    
    <!-- Security -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-shield-lock"></i>
            Security
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current">
            </div>
            
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password">
            </div>
        </div>
        <small style="font-size: 0.625rem; color: var(--booking-text-light);">
            Password must be at least 6 characters
        </small>
    </div>
    
    <!-- Status -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-toggle-on"></i>
            Account Status
        </h3>
        
        <div class="form-check">
            <input type="checkbox" name="is_verified" id="is_verified" <?php echo $user['is_verified'] ? 'checked' : ''; ?>>
            <label for="is_verified">Verified Account</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo $user['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">Active Account</label>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="form-actions">
        <a href="user-detail.php?id=<?php echo $userId; ?>" class="btn btn-secondary">
            <i class="bi bi-x-lg"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</form>

<script>
// Preview profile image
function previewProfileImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const avatar = document.querySelector('.current-avatar');
            avatar.innerHTML = `<img src="${e.target.result}" alt="Profile preview">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Show/hide business types based on user type
document.querySelector('[name="user_type"]').addEventListener('change', function() {
    const businessSection = document.getElementById('businessTypesSection');
    if (this.value === 'business_owner') {
        businessSection.style.display = 'block';
    } else {
        businessSection.style.display = 'none';
        // Uncheck all business types when switching away from business owner
        document.querySelectorAll('[name="business_type[]"]').forEach(cb => cb.checked = false);
    }
});

// Password confirmation validation
const passwordInput = document.querySelector('[name="new_password"]');
const confirmInput = document.querySelector('[name="confirm_password"]');

function validatePasswordMatch() {
    if (passwordInput.value && confirmInput.value && passwordInput.value !== confirmInput.value) {
        confirmInput.setCustomValidity("Passwords do not match");
    } else {
        confirmInput.setCustomValidity("");
    }
}

passwordInput.addEventListener('change', validatePasswordMatch);
confirmInput.addEventListener('keyup', validatePasswordMatch);
</script>

<?php require_once 'includes/admin_footer.php'; ?>