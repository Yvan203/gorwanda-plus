<?php
$restaurantId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$restaurantId) {
    header('Location: restaurants.php');
    exit;
}

$pageTitle = 'Edit Restaurant';
require_once 'includes/admin_header.php';

$db = getDB();

// Get restaurant details
$stmt = $db->prepare("SELECT * FROM restaurants WHERE restaurant_id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    header('Location: restaurants.php');
    exit;
}

// Get hotels for dropdown
$stmt = $db->query("SELECT stay_id, stay_name FROM stays WHERE is_active = 1 ORDER BY stay_name");
$hotels = $stmt->fetchAll();

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $restaurant_name = sanitize($_POST['restaurant_name'] ?? '');
    $stay_id = intval($_POST['stay_id'] ?? 0);
    $cuisine_type = sanitize($_POST['cuisine_type'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $dress_code = sanitize($_POST['dress_code'] ?? '');
    $seating_capacity = !empty($_POST['seating_capacity']) ? intval($_POST['seating_capacity']) : null;
    $has_outdoor_seating = isset($_POST['has_outdoor_seating']) ? 1 : 0;
    $has_private_dining = isset($_POST['has_private_dining']) ? 1 : 0;
    $accepts_reservations = isset($_POST['accepts_reservations']) ? 1 : 0;
    $phone_extension = sanitize($_POST['phone_extension'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Opening hours (JSON)
    $opening_hours = [];
    if (isset($_POST['breakfast_hours']) && !empty($_POST['breakfast_hours'])) {
        $opening_hours['breakfast'] = $_POST['breakfast_hours'];
    }
    if (isset($_POST['lunch_hours']) && !empty($_POST['lunch_hours'])) {
        $opening_hours['lunch'] = $_POST['lunch_hours'];
    }
    if (isset($_POST['dinner_hours']) && !empty($_POST['dinner_hours'])) {
        $opening_hours['dinner'] = $_POST['dinner_hours'];
    }
    if (isset($_POST['continuous_hours']) && !empty($_POST['continuous_hours'])) {
        $opening_hours['continuous'] = $_POST['continuous_hours'];
    }
    $opening_hours_json = !empty($opening_hours) ? json_encode($opening_hours) : null;
    
    // Validation
    if (empty($restaurant_name)) {
        $errors[] = "Restaurant name is required";
    }
    if ($stay_id <= 0) {
        $errors[] = "Hotel selection is required";
    }
    
    // Handle main image upload
    $main_image = $restaurant['main_image'];
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/restaurants/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION);
        $filename = 'rest_' . $restaurantId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        // Delete old main image if exists
        if ($main_image && file_exists($upload_dir . $main_image)) {
            unlink($upload_dir . $main_image);
        }
        
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $upload_path)) {
            $main_image = $filename;
        } else {
            $errors[] = "Failed to upload main image";
        }
    }
    
    // Handle gallery images
    $existing_images = [];
    $stmt = $db->prepare("SELECT image_id, image_path FROM restaurant_images WHERE restaurant_id = ?");
    $stmt->execute([$restaurantId]);
    $existing = $stmt->fetchAll();
    foreach ($existing as $img) {
        $existing_images[$img['image_id']] = $img['image_path'];
    }
    
    // Handle new gallery images upload
    if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['tmp_name'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/restaurants/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['gallery_images']['name'][$key], PATHINFO_EXTENSION);
                $filename = 'rest_' . $restaurantId . '_gallery_' . time() . '_' . $key . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $stmt = $db->prepare("INSERT INTO restaurant_images (restaurant_id, image_path, sort_order) VALUES (?, ?, ?)");
                    $stmt->execute([$restaurantId, $filename, 0]);
                }
            }
        }
    }
    
    // Handle image deletions
    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/restaurants/';
        foreach ($_POST['delete_images'] as $image_id => $image_path) {
            $file_path = $upload_dir . $image_path;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $stmt = $db->prepare("DELETE FROM restaurant_images WHERE image_id = ? AND restaurant_id = ?");
            $stmt->execute([$image_id, $restaurantId]);
        }
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE restaurants SET
                restaurant_name = ?,
                stay_id = ?,
                cuisine_type = ?,
                description = ?,
                opening_hours = ?,
                dress_code = ?,
                seating_capacity = ?,
                has_outdoor_seating = ?,
                has_private_dining = ?,
                accepts_reservations = ?,
                phone_extension = ?,
                main_image = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE restaurant_id = ?
        ");
        
        $result = $stmt->execute([
            $restaurant_name,
            $stay_id,
            $cuisine_type,
            $description,
            $opening_hours_json,
            $dress_code,
            $seating_capacity,
            $has_outdoor_seating,
            $has_private_dining,
            $accepts_reservations,
            $phone_extension,
            $main_image,
            $is_active,
            $restaurantId
        ]);
        
        if ($result) {
            $success = true;
            $_SESSION['success'] = "Restaurant updated successfully";
            // Refresh restaurant data
            $stmt = $db->prepare("SELECT * FROM restaurants WHERE restaurant_id = ?");
            $stmt->execute([$restaurantId]);
            $restaurant = $stmt->fetch();
        } else {
            $errors[] = "Failed to update restaurant";
        }
    }
}

// Get existing gallery images
$stmt = $db->prepare("SELECT image_id, image_path, caption, is_main, sort_order FROM restaurant_images WHERE restaurant_id = ? ORDER BY sort_order");
$stmt->execute([$restaurantId]);
$galleryImages = $stmt->fetchAll();

// Parse opening hours
$openingHours = $restaurant['opening_hours'] ? json_decode($restaurant['opening_hours'], true) : [];
?>

<style>
/* Edit Restaurant Styles */
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

/* Opening Hours Grid */
.hours-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.hours-input {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
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
    
    .hours-grid {
        grid-template-columns: 1fr;
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
    <a href="restaurant-detail.php?id=<?php echo $restaurantId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Restaurant Details
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        Restaurant updated successfully!
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

<form method="POST" action="edit-restaurant.php?id=<?php echo $restaurantId; ?>" enctype="multipart/form-data" class="edit-form">
    <!-- Basic Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-info-circle"></i>
            Basic Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Restaurant Name</label>
                <input type="text" name="restaurant_name" class="form-control" value="<?php echo htmlspecialchars($restaurant['restaurant_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="required">Hotel</label>
                <select name="stay_id" class="form-control" required>
                    <option value="">Select Hotel</option>
                    <?php foreach ($hotels as $hotel): ?>
                    <option value="<?php echo $hotel['stay_id']; ?>" <?php echo $restaurant['stay_id'] == $hotel['stay_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($hotel['stay_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Cuisine Type</label>
                <input type="text" name="cuisine_type" class="form-control" value="<?php echo htmlspecialchars($restaurant['cuisine_type']); ?>" placeholder="e.g., Italian, Asian, African, International">
            </div>
            
            <div class="form-group">
                <label>Dress Code</label>
                <input type="text" name="dress_code" class="form-control" value="<?php echo htmlspecialchars($restaurant['dress_code']); ?>" placeholder="e.g., Casual, Smart Casual, Formal">
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-control" rows="4" placeholder="Describe the restaurant, ambiance, specialties..."><?php echo htmlspecialchars($restaurant['description']); ?></textarea>
        </div>
    </div>
    
    <!-- Opening Hours -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-clock"></i>
            Opening Hours
        </h3>
        
        <div class="hours-grid">
            <div class="form-group">
                <label>Breakfast Hours</label>
                <input type="text" name="breakfast_hours" class="hours-input" style="width: 100%;" 
                       value="<?php echo htmlspecialchars($openingHours['breakfast'] ?? ''); ?>" 
                       placeholder="e.g., 06:30 - 10:30">
            </div>
            
            <div class="form-group">
                <label>Lunch Hours</label>
                <input type="text" name="lunch_hours" class="hours-input" style="width: 100%;" 
                       value="<?php echo htmlspecialchars($openingHours['lunch'] ?? ''); ?>" 
                       placeholder="e.g., 12:00 - 15:00">
            </div>
            
            <div class="form-group">
                <label>Dinner Hours</label>
                <input type="text" name="dinner_hours" class="hours-input" style="width: 100%;" 
                       value="<?php echo htmlspecialchars($openingHours['dinner'] ?? ''); ?>" 
                       placeholder="e.g., 18:00 - 22:00">
            </div>
            
            <div class="form-group">
                <label>Continuous Service</label>
                <input type="text" name="continuous_hours" class="hours-input" style="width: 100%;" 
                       value="<?php echo htmlspecialchars($openingHours['continuous'] ?? ''); ?>" 
                       placeholder="e.g., 12:00 - 22:00 (if open all day)">
            </div>
        </div>
    </div>
    
    <!-- Facilities & Capacity -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-building"></i>
            Facilities & Capacity
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Seating Capacity</label>
                <input type="number" name="seating_capacity" class="form-control" value="<?php echo $restaurant['seating_capacity']; ?>" min="0">
            </div>
            
            <div class="form-group">
                <label>Phone Extension</label>
                <input type="text" name="phone_extension" class="form-control" value="<?php echo htmlspecialchars($restaurant['phone_extension']); ?>" placeholder="e.g., 1234">
            </div>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="has_outdoor_seating" id="has_outdoor_seating" <?php echo $restaurant['has_outdoor_seating'] ? 'checked' : ''; ?>>
            <label for="has_outdoor_seating">Outdoor Seating Available</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="has_private_dining" id="has_private_dining" <?php echo $restaurant['has_private_dining'] ? 'checked' : ''; ?>>
            <label for="has_private_dining">Private Dining Available</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="accepts_reservations" id="accepts_reservations" <?php echo $restaurant['accepts_reservations'] ? 'checked' : ''; ?>>
            <label for="accepts_reservations">Accepts Reservations</label>
        </div>
    </div>
    
    <!-- Images -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-image"></i>
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
            <div id="main_image_preview" class="image-preview" style="display: <?php echo $restaurant['main_image'] ? 'grid' : 'none'; ?>">
                <div class="preview-item">
                    <img src="<?php echo getImageUrl($restaurant['main_image'], 'restaurant'); ?>" alt="Main image">
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
            
            <?php if (!empty($galleryImages)): ?>
            <div id="gallery_preview" class="image-preview">
                <?php foreach ($galleryImages as $image): ?>
                <div class="preview-item" data-image-id="<?php echo $image['image_id']; ?>" data-image-path="<?php echo $image['image_path']; ?>">
                    <img src="<?php echo getImageUrl($image['image_path'], 'restaurant'); ?>" alt="Gallery image">
                    <button type="button" class="delete-image" onclick="deleteGalleryImage(this, <?php echo $image['image_id']; ?>, '<?php echo $image['image_path']; ?>')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div id="deleted_images_container"></div>
    </div>
    
    <!-- Status -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-toggle-on"></i>
            Status
        </h3>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo $restaurant['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">Active (Visible on platform and available for reservations)</label>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="form-actions">
        <a href="restaurant-detail.php?id=<?php echo $restaurantId; ?>" class="btn btn-secondary">
            <i class="bi bi-x-lg"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</form>

<script>
// Preview main image
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

// Preview gallery images
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

// Delete gallery image
function deleteGalleryImage(button, imageId, imagePath) {
    if (confirm('Remove this image?')) {
        const container = document.getElementById('deleted_images_container');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `delete_images[${imageId}]`;
        input.value = imagePath;
        container.appendChild(input);
        button.parentElement.remove();
    }
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>