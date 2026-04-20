<?php
$stayId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$stayId) {
    header('Location: stays.php');
    exit;
}

$pageTitle = 'Edit Stay';
require_once 'includes/admin_header.php';

$db = getDB();

// Get stay details
$stmt = $db->prepare("
    SELECT s.*, l.name as location_name, l.location_id as location_id
    FROM stays s
    LEFT JOIN locations l ON s.location_id = l.location_id
    WHERE s.stay_id = ?
");
$stmt->execute([$stayId]);
$stay = $stmt->fetch();

if (!$stay) {
    header('Location: stays.php');
    exit;
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $stay_name = sanitize($_POST['stay_name'] ?? '');
    $stay_type = sanitize($_POST['stay_type'] ?? 'hotel');
    $description = sanitize($_POST['description'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 0);
    $star_rating = floatval($_POST['star_rating'] ?? 0);
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $check_in_time = sanitize($_POST['check_in_time'] ?? '14:00:00');
    $check_out_time = sanitize($_POST['check_out_time'] ?? '11:00:00');
    $check_in_instructions = sanitize($_POST['check_in_instructions'] ?? '');
    $house_rules = sanitize($_POST['house_rules'] ?? '');
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $amenities = isset($_POST['amenities']) ? json_encode($_POST['amenities']) : '[]';
    
    // Validation
    if (empty($stay_name)) {
        $errors[] = "Stay name is required";
    }
    if (empty($address)) {
        $errors[] = "Address is required";
    }
    if ($location_id <= 0) {
        $errors[] = "Location is required";
    }
    
    // Handle main image upload
    $main_image = $stay['main_image'];
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/stays/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION);
        $filename = 'stay_' . $stayId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $upload_path)) {
            $main_image = $filename;
        }
    }
    
    // Handle gallery images upload
    $existing_images = $stay['images'] ? json_decode($stay['images'], true) : [];
    $gallery_images = $existing_images;
    
    if (isset($_FILES['gallery_images']) && is_array($_FILES['gallery_images']['tmp_name'])) {
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['gallery_images']['name'][$key], PATHINFO_EXTENSION);
                $filename = 'stay_' . $stayId . '_gallery_' . time() . '_' . $key . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($tmp_name, $upload_path)) {
                    $gallery_images[] = $filename;
                }
            }
        }
    }
    
    // Remove images marked for deletion
    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
        foreach ($_POST['delete_images'] as $image_to_delete) {
            $key = array_search($image_to_delete, $gallery_images);
            if ($key !== false) {
                // Delete file from server
                $file_path = $upload_dir . $image_to_delete;
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                unset($gallery_images[$key]);
            }
        }
        $gallery_images = array_values($gallery_images);
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE stays SET
                stay_name = ?,
                stay_type = ?,
                description = ?,
                address = ?,
                city = ?,
                location_id = ?,
                star_rating = ?,
                phone = ?,
                email = ?,
                check_in_time = ?,
                check_out_time = ?,
                check_in_instructions = ?,
                house_rules = ?,
                amenities = ?,
                main_image = ?,
                images = ?,
                is_verified = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE stay_id = ?
        ");
        
        $result = $stmt->execute([
            $stay_name,
            $stay_type,
            $description,
            $address,
            $city,
            $location_id,
            $star_rating,
            $phone,
            $email,
            $check_in_time,
            $check_out_time,
            $check_in_instructions,
            $house_rules,
            $amenities,
            $main_image,
            json_encode($gallery_images),
            $is_verified,
            $is_active,
            $stayId
        ]);
        
        if ($result) {
            $success = true;
            $_SESSION['success'] = "Stay updated successfully";
            // Refresh stay data
            $stmt = $db->prepare("SELECT * FROM stays WHERE stay_id = ?");
            $stmt->execute([$stayId]);
            $stay = $stmt->fetch();
        } else {
            $errors[] = "Failed to update stay";
        }
    }
}

// Get all amenities
$stmt = $db->query("SELECT amenity_id, amenity_key, amenity_name, amenity_icon, category FROM amenities WHERE category = 'property' AND is_active = 1 ORDER BY amenity_name");
$allAmenities = $stmt->fetchAll();

// Get current amenities
$currentAmenities = $stay['amenities'] ? json_decode($stay['amenities'], true) : [];

// Get all locations
$stmt = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name");
$locations = $stmt->fetchAll();

// Get stay types from database enum
$stayTypes = $db->query("SHOW COLUMNS FROM stays WHERE Field = 'stay_type'")->fetch();
preg_match("/^enum\((.*)\)$/", $stayTypes['Type'], $matches);
$stayTypes = array_map(function($value) {
    return trim($value, "'");
}, explode(',', $matches[1]));

// Get current gallery images
$galleryImages = $stay['images'] ? json_decode($stay['images'], true) : [];
?>

<style>
/* Edit Stay Styles */
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

/* Amenities Grid */
.amenities-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.amenity-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.amenity-checkbox:hover {
    background: var(--booking-gray-light);
    border-color: var(--booking-blue);
}

.amenity-checkbox input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.amenity-checkbox label {
    margin: 0;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: normal;
    text-transform: none;
}

.amenity-checkbox i {
    font-size: 1rem;
    color: var(--booking-blue);
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

.btn-danger {
    background: rgba(226,17,17,0.1);
    color: var(--booking-danger);
}

.btn-danger:hover {
    background: rgba(226,17,17,0.2);
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

.alert i {
    font-size: 1rem;
    margin-right: 8px;
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
    
    .amenities-grid {
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
    <a href="stay-detail.php?id=<?php echo $stayId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Stay Details
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        Stay updated successfully!
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

<form method="POST" action="edit-stay.php?id=<?php echo $stayId; ?>" enctype="multipart/form-data" class="edit-form">
    <!-- Basic Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-info-circle"></i>
            Basic Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Stay Name</label>
                <input type="text" name="stay_name" class="form-control" value="<?php echo htmlspecialchars($stay['stay_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Stay Type</label>
                <select name="stay_type" class="form-control">
                    <?php foreach ($stayTypes as $type): ?>
                    <option value="<?php echo $type; ?>" <?php echo $stay['stay_type'] == $type ? 'selected' : ''; ?>>
                        <?php echo ucfirst($type); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($stay['description']); ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Address</label>
                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($stay['address']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>City/District</label>
                <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($stay['city']); ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Location/Region</label>
                <select name="location_id" class="form-control" required>
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['location_id']; ?>" <?php echo $stay['location_id'] == $loc['location_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Star Rating</label>
                <select name="star_rating" class="form-control">
                    <option value="0" <?php echo $stay['star_rating'] == 0 ? 'selected' : ''; ?>>Not Rated</option>
                    <option value="1" <?php echo $stay['star_rating'] == 1 ? 'selected' : ''; ?>>1 Star</option>
                    <option value="2" <?php echo $stay['star_rating'] == 2 ? 'selected' : ''; ?>>2 Stars</option>
                    <option value="3" <?php echo $stay['star_rating'] == 3 ? 'selected' : ''; ?>>3 Stars</option>
                    <option value="4" <?php echo $stay['star_rating'] == 4 ? 'selected' : ''; ?>>4 Stars</option>
                    <option value="5" <?php echo $stay['star_rating'] == 5 ? 'selected' : ''; ?>>5 Stars</option>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Contact Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-telephone"></i>
            Contact Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($stay['phone']); ?>">
            </div>
            
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($stay['email']); ?>">
            </div>
        </div>
    </div>
    
    <!-- Policies & Rules -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-file-text"></i>
            Policies & Rules
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Check-in Time</label>
                <input type="time" name="check_in_time" class="form-control" value="<?php echo $stay['check_in_time']; ?>">
            </div>
            
            <div class="form-group">
                <label>Check-out Time</label>
                <input type="time" name="check_out_time" class="form-control" value="<?php echo $stay['check_out_time']; ?>">
            </div>
        </div>
        
<div class="form-group">
    <label>Check-in Instructions</label>
    <textarea name="check_in_instructions" class="form-control" rows="3" placeholder="Instructions for guests on how to check in..."><?php echo htmlspecialchars($stay['check_in_instructions'] ?? ''); ?></textarea>
</div>

<div class="form-group">
    <label>House Rules</label>
    <textarea name="house_rules" class="form-control" rows="3" placeholder="List any house rules for guests..."><?php echo htmlspecialchars($stay['house_rules'] ?? ''); ?></textarea>
</div>
    </div>
    
    <!-- Amenities -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            Amenities & Features
        </h3>
        
        <div class="amenities-grid">
            <?php foreach ($allAmenities as $amenity): ?>
            <label class="amenity-checkbox">
                <input type="checkbox" name="amenities[]" value="<?php echo $amenity['amenity_key']; ?>" 
                    <?php echo in_array($amenity['amenity_key'], $currentAmenities) ? 'checked' : ''; ?>>
                <i class="bi <?php echo $amenity['amenity_icon']; ?>"></i>
                <span><?php echo htmlspecialchars($amenity['amenity_name']); ?></span>
            </label>
            <?php endforeach; ?>
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
            <div id="main_image_preview" class="image-preview" style="display: <?php echo $stay['main_image'] ? 'grid' : 'none'; ?>">
                <div class="preview-item">
                    <img src="<?php echo getImageUrl($stay['main_image'], 'stay'); ?>" alt="Main image">
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
                <?php foreach ($galleryImages as $index => $image): ?>
                <div class="preview-item" data-image="<?php echo $image; ?>">
                    <img src="<?php echo getImageUrl($image, 'stay'); ?>" alt="Gallery image">
                    <button type="button" class="delete-image" onclick="deleteGalleryImage(this, '<?php echo $image; ?>')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Hidden field for deleted images -->
        <div id="deleted_images_container"></div>
    </div>
    
    <!-- Status -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-toggle-on"></i>
            Status
        </h3>
        
        <div class="form-check">
            <input type="checkbox" name="is_verified" id="is_verified" <?php echo $stay['is_verified'] ? 'checked' : ''; ?>>
            <label for="is_verified">Verified (Approved for booking)</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo $stay['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">Active (Visible on platform)</label>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="form-actions">
        <a href="stay-detail.php?id=<?php echo $stayId; ?>" class="btn btn-secondary">
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
function deleteGalleryImage(button, imageName) {
    if (confirm('Remove this image?')) {
        // Add to deleted images list
        const container = document.getElementById('deleted_images_container');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'delete_images[]';
        input.value = imageName;
        container.appendChild(input);
        
        // Remove preview
        button.parentElement.remove();
    }
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>