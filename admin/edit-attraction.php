<?php
$attractionId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$attractionId) {
    header('Location: attractions.php');
    exit;
}

$pageTitle = 'Edit Experience';
require_once 'includes/admin_header.php';

$db = getDB();

// Get attraction details
$stmt = $db->prepare("SELECT * FROM attractions WHERE attraction_id = ?");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: attractions.php');
    exit;
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $attraction_name = sanitize($_POST['attraction_name'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $description = sanitize($_POST['description'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 0);
    $address = sanitize($_POST['address'] ?? '');
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    $duration_minutes = intval($_POST['duration_minutes'] ?? 0);
    $difficulty_level = sanitize($_POST['difficulty_level'] ?? 'moderate');
    $physical_intensity = sanitize($_POST['physical_intensity'] ?? 'moderate');
    $min_age = !empty($_POST['min_age']) ? intval($_POST['min_age']) : null;
    $max_group_size = !empty($_POST['max_group_size']) ? intval($_POST['max_group_size']) : null;
    $meeting_point = sanitize($_POST['meeting_point'] ?? '');
    $cancellation_policy = sanitize($_POST['cancellation_policy'] ?? '');
    $free_cancellation = isset($_POST['free_cancellation']) ? 1 : 0;
    $instant_confirmation = isset($_POST['instant_confirmation']) ? 1 : 0;
    $commission_rate = floatval($_POST['commission_rate'] ?? 15.00);
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // JSON fields
    $guide_languages = isset($_POST['guide_languages']) ? json_encode($_POST['guide_languages']) : '[]';
    $included_items = isset($_POST['included_items']) ? json_encode(array_filter($_POST['included_items'])) : '[]';
    $excluded_items = isset($_POST['excluded_items']) ? json_encode(array_filter($_POST['excluded_items'])) : '[]';
    $what_to_bring = isset($_POST['what_to_bring']) ? json_encode(array_filter($_POST['what_to_bring'])) : '[]';
    $start_times = isset($_POST['start_times']) ? json_encode(array_filter($_POST['start_times'])) : '[]';
    
    // Validation
    if (empty($attraction_name)) {
        $errors[] = "Experience name is required";
    }
    if ($category_id <= 0) {
        $errors[] = "Category is required";
    }
    if ($location_id <= 0) {
        $errors[] = "Location is required";
    }
    if (empty($address)) {
        $errors[] = "Address is required";
    }
    
    // Handle main image upload
    $main_image = $attraction['main_image'];
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION);
        $filename = 'exp_' . $attractionId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $upload_path)) {
            // Delete old main image if exists
            if ($main_image && file_exists($upload_dir . $main_image)) {
                unlink($upload_dir . $main_image);
            }
            $main_image = $filename;
        } else {
            $errors[] = "Failed to upload main image";
        }
    }
    
    // Handle gallery images upload
    $existing_images = $attraction['gallery_images'] ? json_decode($attraction['gallery_images'], true) : [];
    $gallery_images = $existing_images;
    
    if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['tmp_name'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/gallery/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['gallery_images']['name'][$key], PATHINFO_EXTENSION);
                $filename = 'exp_' . $attractionId . '_gallery_' . time() . '_' . $key . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $gallery_images[] = $filename;
                }
            }
        }
    }
    
    // Remove images marked for deletion
    if (isset($_POST['delete_gallery_images']) && is_array($_POST['delete_gallery_images'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/gallery/';
        foreach ($_POST['delete_gallery_images'] as $image_to_delete) {
            $key = array_search($image_to_delete, $gallery_images);
            if ($key !== false) {
                $file_path = $upload_dir . $image_to_delete;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                unset($gallery_images[$key]);
            }
        }
        $gallery_images = array_values($gallery_images);
    }
    
    $gallery_images_json = !empty($gallery_images) ? json_encode($gallery_images) : null;
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE attractions SET
                attraction_name = ?,
                category_id = ?,
                description = ?,
                location_id = ?,
                address = ?,
                latitude = ?,
                longitude = ?,
                duration_minutes = ?,
                difficulty_level = ?,
                physical_intensity = ?,
                min_age = ?,
                max_group_size = ?,
                guide_languages = ?,
                included_items = ?,
                excluded_items = ?,
                what_to_bring = ?,
                meeting_point = ?,
                start_times = ?,
                cancellation_policy = ?,
                free_cancellation = ?,
                instant_confirmation = ?,
                main_image = ?,
                gallery_images = ?,
                commission_rate = ?,
                is_verified = ?,
                is_active = ?
            WHERE attraction_id = ?
        ");
        
        $result = $stmt->execute([
            $attraction_name,
            $category_id,
            $description,
            $location_id,
            $address,
            $latitude,
            $longitude,
            $duration_minutes,
            $difficulty_level,
            $physical_intensity,
            $min_age,
            $max_group_size,
            $guide_languages,
            $included_items,
            $excluded_items,
            $what_to_bring,
            $meeting_point,
            $start_times,
            $cancellation_policy,
            $free_cancellation,
            $instant_confirmation,
            $main_image,
            $gallery_images_json,
            $commission_rate,
            $is_verified,
            $is_active,
            $attractionId
        ]);
        
        if ($result) {
            $success = true;
            $_SESSION['success'] = "Experience updated successfully";
            // Refresh attraction data
            $stmt = $db->prepare("SELECT * FROM attractions WHERE attraction_id = ?");
            $stmt->execute([$attractionId]);
            $attraction = $stmt->fetch();
        } else {
            $errors[] = "Failed to update experience";
        }
    }
}

// Get categories
$stmt = $db->query("SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY display_order, name");
$categories = $stmt->fetchAll();

// Get locations
$stmt = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name");
$locations = $stmt->fetchAll();

// Get current values for multi-select fields
$currentGuideLanguages = $attraction['guide_languages'] ? json_decode($attraction['guide_languages'], true) : [];
$currentIncludedItems = $attraction['included_items'] ? json_decode($attraction['included_items'], true) : [];
$currentExcludedItems = $attraction['excluded_items'] ? json_decode($attraction['excluded_items'], true) : [];
$currentWhatToBring = $attraction['what_to_bring'] ? json_decode($attraction['what_to_bring'], true) : [];
$currentStartTimes = $attraction['start_times'] ? json_decode($attraction['start_times'], true) : [];
$currentGalleryImages = $attraction['gallery_images'] ? json_decode($attraction['gallery_images'], true) : [];

// Available options
$languages = ['en', 'fr', 'rw', 'sw', 'de', 'es', 'it', 'zh'];
$commonItems = [
    'guide' => 'Professional Guide',
    'park_fees' => 'Park Entrance Fees',
    'transport' => 'Transportation',
    'lunch' => 'Lunch',
    'water' => 'Bottled Water',
    'equipment' => 'Equipment',
    'snacks' => 'Snacks',
    'drinks' => 'Drinks',
    'insurance' => 'Insurance',
    'photos' => 'Free Photos',
    'souvenir' => 'Souvenir',
    'pickup' => 'Hotel Pickup',
    'dropoff' => 'Hotel Dropoff'
];
$difficultyLevels = ['easy', 'moderate', 'challenging'];
$intensityLevels = ['light', 'moderate', 'intense'];
?>

<style>
/* Edit Attraction Styles */
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
    min-height: 100px;
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

/* Dynamic List Styles */
.dynamic-list {
    margin-bottom: 12px;
}

.list-item {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
}

.list-item select,
.list-item input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.list-item button {
    padding: 8px 12px;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.list-item button:hover {
    background: var(--booking-danger);
    color: white;
    border-color: var(--booking-danger);
}

.add-btn {
    margin-top: 8px;
    padding: 8px 16px;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--transition-fast);
}

.add-btn:hover {
    background: var(--booking-blue);
    color: white;
    border-color: var(--booking-blue);
}

/* Image Upload */
.image-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: var(--radius-md);
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    margin-bottom: 20px;
}

.image-upload-area:hover {
    border-color: var(--booking-blue);
    background: rgba(0,102,255,0.02);
}

.image-upload-area i {
    font-size: 2rem;
    color: var(--booking-text-light);
    margin-bottom: 8px;
}

.image-upload-area p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    margin: 0;
}

.image-preview {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.preview-item {
    position: relative;
    aspect-ratio: 4/3;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--booking-border);
}

.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-item .delete-image {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(226,17,17,0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--transition-fast);
}

.preview-item .delete-image:hover {
    background: var(--booking-danger);
    transform: scale(1.1);
}

.preview-item .main-badge {
    position: absolute;
    bottom: 8px;
    left: 8px;
    background: rgba(0,102,255,0.9);
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.625rem;
    font-weight: 600;
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
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="detail-header">
    <a href="attraction-detail.php?id=<?php echo $attractionId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Experience Details
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        Experience updated successfully!
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

<form method="POST" action="edit-attraction.php?id=<?php echo $attractionId; ?>" enctype="multipart/form-data" class="edit-form">
    <!-- Basic Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-info-circle"></i>
            Basic Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Experience Name</label>
                <input type="text" name="attraction_name" class="form-control" value="<?php echo htmlspecialchars($attraction['attraction_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="required">Category</label>
                <select name="category_id" class="form-control" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['category_id']; ?>" <?php echo $attraction['category_id'] == $cat['category_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-control" rows="5"><?php echo htmlspecialchars($attraction['description']); ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Location</label>
                <select name="location_id" class="form-control" required>
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['location_id']; ?>" <?php echo $attraction['location_id'] == $loc['location_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="required">Address</label>
                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($attraction['address']); ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Latitude</label>
                <input type="text" name="latitude" class="form-control" value="<?php echo $attraction['latitude']; ?>" placeholder="e.g., -1.9441">
            </div>
            <div class="form-group">
                <label>Longitude</label>
                <input type="text" name="longitude" class="form-control" value="<?php echo $attraction['longitude']; ?>" placeholder="e.g., 30.0619">
            </div>
        </div>
    </div>
    
    <!-- Experience Details -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-activity"></i>
            Experience Details
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Duration (minutes)</label>
                <input type="number" name="duration_minutes" class="form-control" value="<?php echo $attraction['duration_minutes']; ?>" min="0" step="15">
            </div>
            <div class="form-group">
                <label>Difficulty Level</label>
                <select name="difficulty_level" class="form-control">
                    <?php foreach ($difficultyLevels as $level): ?>
                    <option value="<?php echo $level; ?>" <?php echo $attraction['difficulty_level'] == $level ? 'selected' : ''; ?>>
                        <?php echo ucfirst($level); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Physical Intensity</label>
                <select name="physical_intensity" class="form-control">
                    <?php foreach ($intensityLevels as $intensity): ?>
                    <option value="<?php echo $intensity; ?>" <?php echo $attraction['physical_intensity'] == $intensity ? 'selected' : ''; ?>>
                        <?php echo ucfirst($intensity); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Minimum Age</label>
                <input type="number" name="min_age" class="form-control" value="<?php echo $attraction['min_age']; ?>" min="0">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Maximum Group Size</label>
                <input type="number" name="max_group_size" class="form-control" value="<?php echo $attraction['max_group_size']; ?>" min="1">
            </div>
            <div class="form-group">
                <label>Meeting Point</label>
                <input type="text" name="meeting_point" class="form-control" value="<?php echo htmlspecialchars($attraction['meeting_point']); ?>" placeholder="Where to meet">
            </div>
        </div>
    </div>
    
    <!-- Guide Languages -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-chat-dots"></i>
            Guide Languages
        </h3>
        
        <div class="dynamic-list" id="languagesList">
            <?php if (empty($currentGuideLanguages)): ?>
            <div class="list-item">
                <select name="guide_languages[]" class="form-control">
                    <option value="">Select Language</option>
                    <?php foreach ($languages as $lang): ?>
                    <option value="<?php echo $lang; ?>"><?php echo strtoupper($lang); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php else: ?>
            <?php foreach ($currentGuideLanguages as $lang): ?>
            <div class="list-item">
                <select name="guide_languages[]" class="form-control">
                    <option value="">Select Language</option>
                    <?php foreach ($languages as $l): ?>
                    <option value="<?php echo $l; ?>" <?php echo $lang == $l ? 'selected' : ''; ?>><?php echo strtoupper($l); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="add-btn" onclick="addLanguage()">
            <i class="bi bi-plus-lg"></i> Add Language
        </button>
    </div>
    
    <!-- Start Times -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-clock"></i>
            Start Times
        </h3>
        
        <div class="dynamic-list" id="startTimesList">
            <?php if (empty($currentStartTimes)): ?>
            <div class="list-item">
                <input type="time" name="start_times[]" class="form-control" placeholder="HH:MM">
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php else: ?>
            <?php foreach ($currentStartTimes as $time): ?>
            <div class="list-item">
                <input type="time" name="start_times[]" class="form-control" value="<?php echo $time; ?>">
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="add-btn" onclick="addStartTime()">
            <i class="bi bi-plus-lg"></i> Add Start Time
        </button>
    </div>
    
    <!-- Included Items -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-check-circle-fill"></i>
            Included Items
        </h3>
        
        <div class="dynamic-list" id="includedItemsList">
            <?php if (empty($currentIncludedItems)): ?>
            <div class="list-item">
                <select name="included_items[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php else: ?>
            <?php foreach ($currentIncludedItems as $item): ?>
            <div class="list-item">
                <select name="included_items[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $item == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="add-btn" onclick="addIncludedItem()">
            <i class="bi bi-plus-lg"></i> Add Included Item
        </button>
    </div>
    
    <!-- Excluded Items -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-x-circle-fill"></i>
            Excluded Items
        </h3>
        
        <div class="dynamic-list" id="excludedItemsList">
            <?php if (empty($currentExcludedItems)): ?>
            <div class="list-item">
                <select name="excluded_items[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php else: ?>
            <?php foreach ($currentExcludedItems as $item): ?>
            <div class="list-item">
                <select name="excluded_items[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $item == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="add-btn" onclick="addExcludedItem()">
            <i class="bi bi-plus-lg"></i> Add Excluded Item
        </button>
    </div>
    
    <!-- What to Bring -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-bag"></i>
            What to Bring
        </h3>
        
        <div class="dynamic-list" id="whatToBringList">
            <?php if (empty($currentWhatToBring)): ?>
            <div class="list-item">
                <select name="what_to_bring[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php else: ?>
            <?php foreach ($currentWhatToBring as $item): ?>
            <div class="list-item">
                <select name="what_to_bring[]" class="form-control">
                    <option value="">Select Item</option>
                    <?php foreach ($commonItems as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $item == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" onclick="this.parentElement.remove()">✕</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="add-btn" onclick="addWhatToBring()">
            <i class="bi bi-plus-lg"></i> Add Item
        </button>
    </div>
    
    <!-- Policies -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-file-text"></i>
            Policies & Settings
        </h3>
        
        <div class="form-group">
            <label>Cancellation Policy</label>
            <textarea name="cancellation_policy" class="form-control" rows="3"><?php echo htmlspecialchars($attraction['cancellation_policy']); ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Commission Rate (%)</label>
                <input type="number" name="commission_rate" class="form-control" step="0.01" min="0" max="100" value="<?php echo $attraction['commission_rate']; ?>">
            </div>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="free_cancellation" id="free_cancellation" <?php echo $attraction['free_cancellation'] ? 'checked' : ''; ?>>
            <label for="free_cancellation">Free Cancellation (up to 24 hours before experience)</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="instant_confirmation" id="instant_confirmation" <?php echo $attraction['instant_confirmation'] ? 'checked' : ''; ?>>
            <label for="instant_confirmation">Instant Confirmation</label>
        </div>
    </div>
    
    <!-- Images -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-images"></i>
            Images
        </h3>
        
        <div class="form-group">
            <label>Main Image</label>
            <div class="image-upload-area" onclick="document.getElementById('main_image_input').click()">
                <i class="bi bi-cloud-upload"></i>
                <p>Click to upload main image</p>
                <p style="font-size: 0.625rem;">Recommended size: 1200x800px</p>
                <input type="file" id="main_image_input" name="main_image" accept="image/*" style="display: none;" onchange="previewMainImage(this)">
            </div>
            <div id="main_image_preview" class="image-preview" style="display: <?php echo $attraction['main_image'] ? 'grid' : 'none'; ?>">
                <div class="preview-item">
                    <img src="<?php echo getImageUrl($attraction['main_image'], 'attraction'); ?>" alt="Main image">
                    <span class="main-badge">Main Image</span>
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Gallery Images</label>
            <div class="image-upload-area" onclick="document.getElementById('gallery_images_input').click()">
                <i class="bi bi-images"></i>
                <p>Click to upload gallery images</p>
                <p style="font-size: 0.625rem;">You can select multiple images</p>
                <input type="file" id="gallery_images_input" name="gallery_images[]" accept="image/*" multiple style="display: none;" onchange="previewGalleryImages(this)">
            </div>
            
            <?php if (!empty($currentGalleryImages)): ?>
            <div id="gallery_preview" class="image-preview">
                <?php foreach ($currentGalleryImages as $index => $image): ?>
                <div class="preview-item" data-image="<?php echo $image; ?>">
                    <img src="<?php echo getImageUrl($image, 'attraction'); ?>" alt="Gallery image">
                    <button type="button" class="delete-image" onclick="deleteGalleryImage(this, '<?php echo $image; ?>')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div id="deleted_gallery_container"></div>
    </div>
    
    <!-- Status -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-toggle-on"></i>
            Status
        </h3>
        
        <div class="form-check">
            <input type="checkbox" name="is_verified" id="is_verified" <?php echo $attraction['is_verified'] ? 'checked' : ''; ?>>
            <label for="is_verified">Verified (Approved for booking)</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo $attraction['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">Active (Visible on platform)</label>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="form-actions">
        <a href="attraction-detail.php?id=<?php echo $attractionId; ?>" class="btn btn-secondary">
            <i class="bi bi-x-lg"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</form>

<script>
// Dynamic list functions
function addLanguage() {
    const container = document.getElementById('languagesList');
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML = `
        <select name="guide_languages[]" class="form-control">
            <option value="">Select Language</option>
            <?php foreach ($languages as $lang): ?>
            <option value="<?php echo $lang; ?>"><?php echo strtoupper($lang); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

function addStartTime() {
    const container = document.getElementById('startTimesList');
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML = `
        <input type="time" name="start_times[]" class="form-control" placeholder="HH:MM">
        <button type="button" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

function addIncludedItem() {
    const container = document.getElementById('includedItemsList');
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML = `
        <select name="included_items[]" class="form-control">
            <option value="">Select Item</option>
            <?php foreach ($commonItems as $key => $label): ?>
            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

function addExcludedItem() {
    const container = document.getElementById('excludedItemsList');
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML = `
        <select name="excluded_items[]" class="form-control">
            <option value="">Select Item</option>
            <?php foreach ($commonItems as $key => $label): ?>
            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

function addWhatToBring() {
    const container = document.getElementById('whatToBringList');
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML = `
        <select name="what_to_bring[]" class="form-control">
            <option value="">Select Item</option>
            <?php foreach ($commonItems as $key => $label): ?>
            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

// Image preview functions
function previewMainImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('main_image_preview');
            preview.innerHTML = `
                <div class="preview-item">
                    <img src="${e.target.result}" alt="Main image preview">
                    <span class="main-badge">New Main Image</span>
                </div>
            `;
            preview.style.display = 'grid';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function previewGalleryImages(input) {
    const preview = document.getElementById('gallery_preview');
    if (!preview) {
        const container = document.querySelector('#gallery_images_input').parentElement;
        const newPreview = document.createElement('div');
        newPreview.id = 'gallery_preview';
        newPreview.className = 'image-preview';
        container.appendChild(newPreview);
    }
    
    const galleryPreview = document.getElementById('gallery_preview');
    
    for (let i = 0; i < input.files.length; i++) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewItem = document.createElement('div');
            previewItem.className = 'preview-item';
            previewItem.innerHTML = `
                <img src="${e.target.result}" alt="Gallery preview">
                <button type="button" class="delete-image" onclick="this.parentElement.remove()">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;
            galleryPreview.appendChild(previewItem);
        }
        reader.readAsDataURL(input.files[i]);
    }
}

function deleteGalleryImage(button, imageName) {
    if (confirm('Remove this image?')) {
        const container = document.getElementById('deleted_gallery_container');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_gallery_images[]';
        input.value = imageName;
        container.appendChild(input);
        button.parentElement.remove();
    }
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>