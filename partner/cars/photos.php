<?php
$pageTitle = 'Photo Gallery';
require_once 'includes/cars_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameter
$vehicleId = isset($_GET['vehicle']) ? intval($_GET['vehicle']) : 0;

// Define upload directory - ADJUST THIS PATH TO MATCH YOUR SERVER
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
$uploadUrlPath = '/gorwanda-plus/assets/images/cars/';

// ============================================
// HANDLE PHOTO ACTIONS
// ============================================

// Upload photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photos'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.car_id FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this vehicle";
    } else {
        // Get existing images
        $stmt = $db->prepare("SELECT images FROM car_fleet WHERE car_id = ?");
        $stmt->execute([$vehicleId]);
        $vehicle = $stmt->fetch();
        
        $existingImages = [];
        if ($vehicle && $vehicle['images']) {
            $existingImages = json_decode($vehicle['images'], true) ?: [];
        }
        
        // Handle new uploads
        if (!empty($_FILES['photos']['name'][0])) {
            // Create directory if not exists
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            // Check if directory is writable
            if (!is_writable($uploadDir)) {
                $error = "Upload directory is not writable. Please check permissions.";
            } else {
                $files = $_FILES['photos'];
                $uploaded = 0;
                $failed = 0;
                $failedReasons = [];
                
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        // Validate file type
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                        finfo_close($finfo);
                        
                        if (!in_array($mimeType, $allowedTypes)) {
                            $failed++;
                            $failedReasons[] = "Invalid file type: " . $files['name'][$i];
                            continue;
                        }
                        
                        // Generate unique filename
                        $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                        $fileName = 'car_' . $vehicleId . '_' . time() . '_' . uniqid() . '.' . $fileExt;
                        $uploadPath = $uploadDir . $fileName;
                        
                        // Move uploaded file first
                        if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                            // Optimize image after successful move
                            if ($mimeType === 'image/jpeg') {
                                $image = @imagecreatefromjpeg($uploadPath);
                                if ($image) {
                                    imagejpeg($image, $uploadPath, 85);
                                    imagedestroy($image);
                                }
                            } elseif ($mimeType === 'image/png') {
                                $image = @imagecreatefrompng($uploadPath);
                                if ($image) {
                                    // Fix PNG transparency issue
                                    imagealphablending($image, false);
                                    imagesavealpha($image, true);
                                    imagepng($image, $uploadPath, 8);
                                    imagedestroy($image);
                                }
                            }
                            
                            $existingImages[] = $fileName;
                            $uploaded++;
                        } else {
                            $failed++;
                            $failedReasons[] = "Failed to move: " . $files['name'][$i];
                        }
                    } else {
                        $failed++;
                        $errorMsg = "Upload error code: " . $files['error'][$i];
                        switch ($files['error'][$i]) {
                            case UPLOAD_ERR_INI_SIZE: $errorMsg = "File too large (server limit)"; break;
                            case UPLOAD_ERR_FORM_SIZE: $errorMsg = "File too large (form limit)"; break;
                            case UPLOAD_ERR_PARTIAL: $errorMsg = "Partial upload"; break;
                            case UPLOAD_ERR_NO_FILE: $errorMsg = "No file uploaded"; break;
                            case UPLOAD_ERR_NO_TMP_DIR: $errorMsg = "Missing temp folder"; break;
                            case UPLOAD_ERR_CANT_WRITE: $errorMsg = "Failed to write file"; break;
                        }
                        $failedReasons[] = $errorMsg;
                    }
                }
                
                // Update database
                $imagesJson = json_encode(array_values($existingImages));
                $stmt = $db->prepare("UPDATE car_fleet SET images = ? WHERE car_id = ?");
                $stmt->execute([$imagesJson, $vehicleId]);
                
                if ($uploaded > 0) {
                    $success = "$uploaded photo(s) uploaded successfully";
                    if ($failed > 0) {
                        $success .= ", $failed failed";
                        // Uncomment to see detailed errors: $error = implode("; ", $failedReasons);
                    }
                } else {
                    $error = "No photos were uploaded. " . implode("; ", $failedReasons);
                }
            }
        }
    }
}

// Delete photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $imageName = sanitize($_POST['image_name']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.images FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        $images = json_decode($vehicle['images'] ?? '[]', true) ?: [];
        
        // Remove from array
        $key = array_search($imageName, $images);
        if ($key !== false) {
            unset($images[$key]);
            $images = array_values($images);
            
            // Update database
            $imagesJson = !empty($images) ? json_encode($images) : null;
            $stmt = $db->prepare("UPDATE car_fleet SET images = ? WHERE car_id = ?");
            $stmt->execute([$imagesJson, $vehicleId]);
            
            // Delete physical file
            $filePath = $uploadDir . $imageName;
            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    $success = "Photo deleted successfully";
                } else {
                    $error = "Photo removed from database but file deletion failed";
                }
            } else {
                $success = "Photo removed from database (file already deleted)";
            }
        } else {
            $error = "Photo not found in gallery";
        }
    }
}

// Bulk delete photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $vehicleId = intval($_POST['vehicle_id']);
    $photosToDelete = json_decode($_POST['photos_to_delete'], true) ?: [];
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT cf.images FROM car_fleet cf
        JOIN car_rentals cr ON cf.rental_id = cr.rental_id
        WHERE cf.car_id = ? AND cr.owner_id = ?
    ");
    $stmt->execute([$vehicleId, $userId]);
    $vehicle = $stmt->fetch();
    
    if ($vehicle && !empty($photosToDelete)) {
        $images = json_decode($vehicle['images'] ?? '[]', true) ?: [];
        $deletedCount = 0;
        
        foreach ($photosToDelete as $imageName) {
            $key = array_search($imageName, $images);
            if ($key !== false) {
                unset($images[$key]);
                
                // Delete physical file
                $filePath = $uploadDir . $imageName;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                $deletedCount++;
            }
        }
        
        $images = array_values($images);
        
        // Update database
        $imagesJson = !empty($images) ? json_encode($images) : null;
        $stmt = $db->prepare("UPDATE car_fleet SET images = ? WHERE car_id = ?");
        $stmt->execute([$imagesJson, $vehicleId]);
        
        $success = "$deletedCount photo(s) deleted successfully";
    }
}

// ============================================
// GET VEHICLES AND PHOTOS
// ============================================

// Get all vehicles for this partner
$stmt = $db->prepare("
    SELECT cf.car_id, cf.brand, cf.model, cf.images, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cr.owner_id = ? 
    ORDER BY cf.brand, cf.model
");
$stmt->execute([$userId]);
$vehicles = $stmt->fetchAll();

// If no vehicle selected, use the first one
if ($vehicleId === 0 && !empty($vehicles)) {
    $vehicleId = $vehicles[0]['car_id'];
}

// Get photos for selected vehicle
$currentVehicle = null;
$photos = [];
if ($vehicleId > 0) {
    foreach ($vehicles as $v) {
        if ($v['car_id'] == $vehicleId) {
            $currentVehicle = $v;
            break;
        }
    }
    
    if ($currentVehicle && $currentVehicle['images']) {
        $photos = json_decode($currentVehicle['images'], true) ?: [];
    }
}

// Get photo statistics
$totalPhotos = 0;
$vehiclesWithPhotos = 0;
foreach ($vehicles as $v) {
    $vPhotos = json_decode($v['images'] ?? '[]', true) ?: [];
    $count = count($vPhotos);
    $totalPhotos += $count;
    if ($count > 0) {
        $vehiclesWithPhotos++;
    }
}
?>

<style>
/* Photos Management Specific Styles - Copied from stays */
.photos-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.photos-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 4px 0;
}

.photos-title p {
    font-size: 0.8125rem;
    color: var(--text-light);
    margin: 0;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: white;
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid var(--border-gray);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--cars-primary);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-gray);
}

/* Vehicle Selector */
.vehicle-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.vehicle-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-dark);
}

.vehicle-selector select {
    min-width: 350px;
    padding: 10px 16px;
    border: 1px solid var(--border-gray);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Upload Area */
.upload-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 30px;
    margin-bottom: 30px;
    text-align: center;
}

.upload-area {
    border: 2px dashed var(--border-gray);
    border-radius: var(--radius-md);
    padding: 40px;
    background: var(--bg-gray);
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
}

.upload-area:hover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
}

.upload-area.dragover {
    border-color: var(--cars-primary);
    background: var(--cars-light);
    transform: scale(1.02);
}

.upload-icon {
    font-size: 3rem;
    color: var(--text-light);
    margin-bottom: 16px;
}

.upload-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.upload-subtitle {
    font-size: 0.875rem;
    color: var(--text-light);
    margin-bottom: 16px;
}

.upload-hint {
    font-size: 0.75rem;
    color: var(--text-lighter);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
}

.upload-hint span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.file-input {
    display: none;
}

/* Action Bar */
.action-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.selected-count {
    background: var(--cars-light);
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--cars-primary);
}

.bulk-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

/* Gallery Grid */
.gallery-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-gray);
    padding: 24px;
}

.gallery-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.gallery-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-dark);
}

.gallery-stats {
    font-size: 0.8125rem;
    color: var(--text-light);
}

.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.photo-card {
    position: relative;
    border-radius: var(--radius-md);
    overflow: hidden;
    aspect-ratio: 4/3;
    transition: all 0.2s;
    border: 2px solid transparent;
    background: var(--bg-gray);
}

.photo-card.selected {
    border-color: var(--cars-primary);
    box-shadow: 0 0 0 2px var(--cars-primary);
}

.photo-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-card .error-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
    color: #999;
    font-size: 0.75rem;
    text-align: center;
    padding: 10px;
}

.photo-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    display: flex;
    align-items: center;
    gap: 4px;
    z-index: 2;
}

.photo-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(to top, rgba(0,0,0,0.7), transparent);
    opacity: 0;
    transition: opacity 0.2s;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 12px;
}

.photo-card:hover .photo-overlay {
    opacity: 1;
}

.photo-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
}

.photo-action-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--text-dark);
    font-size: 0.875rem;
}

.photo-action-btn:hover {
    transform: scale(1.1);
}

.photo-action-btn.delete:hover {
    background: var(--cars-danger);
    color: white;
}

.photo-checkbox {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: white;
    border: 2px solid var(--border-gray);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 2;
    transition: all 0.2s;
}

.photo-checkbox:hover {
    border-color: var(--cars-primary);
}

.photo-checkbox.selected {
    background: var(--cars-primary);
    border-color: var(--cars-primary);
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-gray);
    border-radius: var(--radius-md);
}

.empty-state i {
    font-size: 3rem;
    color: var(--text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--text-light);
    margin-bottom: 20px;
}

/* Loading Spinner */
.spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 3px solid var(--border-gray);
    border-top-color: var(--cars-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Progress Bar */
.upload-progress {
    margin-top: 20px;
    display: none;
}

.progress-bar {
    height: 6px;
    background: var(--border-gray);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--cars-primary);
    width: 0%;
    transition: width 0.3s;
}

.progress-text {
    font-size: 0.75rem;
    color: var(--text-light);
    margin-top: 8px;
    text-align: center;
}

/* Debug Info */
.debug-info {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: var(--radius-sm);
    padding: 12px;
    margin-bottom: 20px;
    font-size: 0.8125rem;
    color: #856404;
}

.debug-info code {
    background: rgba(0,0,0,0.05);
    padding: 2px 4px;
    border-radius: 3px;
    font-family: monospace;
}

/* Responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .vehicle-selector select {
        min-width: 100%;
    }
    
    .gallery-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .bulk-actions {
        justify-content: space-between;
    }
}

@media (max-width: 480px) {
    .gallery-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="photos-header">
    <div class="photos-title">
        <h1>Photo Gallery</h1>
        <p>Manage photos for your vehicles</p>
    </div>
</div>

<!-- Debug Info (Remove in production) -->
<?php if (!empty($error) || isset($_GET['debug'])): ?>
<div class="debug-info">
    <strong>Debug Info:</strong><br>
    Upload Directory: <code><?php echo htmlspecialchars($uploadDir); ?></code><br>
    Directory Exists: <code><?php echo file_exists($uploadDir) ? 'YES' : 'NO'; ?></code><br>
    Is Writable: <code><?php echo is_writable($uploadDir) ? 'YES' : 'NO'; ?></code><br>
    URL Path: <code><?php echo htmlspecialchars($uploadUrlPath); ?></code>
    <?php if (!empty($error)): ?>
    <br>Error: <code><?php echo htmlspecialchars($error); ?></code>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($vehicles); ?></div>
        <div class="stat-label">Total Vehicles</div>
        <div class="stat-footer"><?php echo $vehiclesWithPhotos; ?> with photos</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalPhotos; ?></div>
        <div class="stat-label">Total Photos</div>
        <div class="stat-footer">Across all vehicles</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalPhotos > 0 ? round($totalPhotos / max(1, count($vehicles)), 1) : 0; ?></div>
        <div class="stat-label">Avg per Vehicle</div>
        <div class="stat-footer">Recommended: 5+ photos</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $vehicleId > 0 && $currentVehicle ? count($photos) : 0; ?></div>
        <div class="stat-label">Current Vehicle</div>
        <div class="stat-footer"><?php echo $currentVehicle ? sanitize($currentVehicle['brand'] . ' ' . $currentVehicle['model']) : 'Select vehicle'; ?></div>
    </div>
</div>

<!-- Vehicle Selector -->
<div class="vehicle-selector">
    <label for="vehicleSelect"><i class="bi bi-car-front"></i> Select Vehicle:</label>
    <select id="vehicleSelect" onchange="changeVehicle(this.value)">
        <option value="">Choose a vehicle</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?php echo $v['car_id']; ?>" <?php echo $v['car_id'] == $vehicleId ? 'selected' : ''; ?>>
            <?php echo sanitize($v['brand'] . ' ' . $v['model']); ?> (<?php echo sanitize($v['company_name']); ?>)
            <?php 
            $vPhotos = json_decode($v['images'] ?? '[]', true) ?: [];
            echo ' - ' . count($vPhotos) . ' photos';
            ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($vehicleId > 0 && $currentVehicle): ?>

<!-- Upload Area -->
<div class="upload-container">
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
        <input type="hidden" name="upload_photos" value="1">
        
        <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
            <div class="upload-icon">
                <i class="bi bi-cloud-arrow-up"></i>
            </div>
            <div class="upload-title">Drag & drop photos here</div>
            <div class="upload-subtitle">or click to browse</div>
            <div class="upload-hint">
                <span><i class="bi bi-check-circle-fill text-success"></i> JPG, PNG, GIF, WEBP</span>
                <span><i class="bi bi-check-circle-fill text-success"></i> Max 10MB each</span>
                <span><i class="bi bi-check-circle-fill text-success"></i> At least 1000px wide</span>
            </div>
            <input type="file" name="photos[]" id="fileInput" class="file-input" multiple accept="image/*" onchange="uploadFiles(this.files)">
        </div>
        
        <!-- Upload Progress -->
        <div class="upload-progress" id="uploadProgress">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Uploading 0 files...</div>
        </div>
    </form>
</div>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #e6f4ea; color: #1e7e34; border-radius: 8px; margin-bottom: 20px;">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fce8e8; color: #c82333; border-radius: 8px; margin-bottom: 20px;">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<!-- Gallery Section -->
<div class="gallery-container">
    <div class="gallery-header">
        <div class="gallery-title">
            <i class="bi bi-images"></i> Vehicle Photos
        </div>
        <div class="gallery-stats">
            <span id="photoCount"><?php echo count($photos); ?></span> photos
            <?php if (count($photos) > 0): ?>
            • <span id="selectedCount">0</span> selected
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (empty($photos)): ?>
    <div class="empty-state">
        <i class="bi bi-images"></i>
        <h3>No photos yet</h3>
        <p>Upload photos to showcase your vehicle to potential renters</p>
        <button class="btn-primary" onclick="document.getElementById('fileInput').click()" style="padding: 10px 20px; background: var(--cars-primary); color: white; border: none; border-radius: 6px; cursor: pointer;">
            <i class="bi bi-cloud-arrow-up"></i> Upload Photos
        </button>
    </div>
    <?php else: ?>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div class="selected-count" id="selectedDisplay">0 photos selected</div>
        <div class="bulk-actions">
            <button class="btn-secondary" onclick="selectAll()" id="selectAllBtn" style="padding: 8px 16px; border: 1px solid var(--border-gray); background: white; border-radius: 6px; cursor: pointer;">
                <i class="bi bi-check-all"></i> Select All
            </button>
            <button class="btn-secondary" onclick="deselectAll()" id="deselectAllBtn" style="display: none; padding: 8px 16px; border: 1px solid var(--border-gray); background: white; border-radius: 6px; cursor: pointer;">
                <i class="bi bi-x"></i> Deselect All
            </button>
            <button class="btn-secondary" onclick="deleteSelected()" id="deleteSelectedBtn" style="display: none; padding: 8px 16px; border: 1px solid var(--cars-danger); background: white; color: var(--cars-danger); border-radius: 6px; cursor: pointer;">
                <i class="bi bi-trash"></i> Delete Selected
            </button>
        </div>
    </div>
    
    <!-- Photo Grid -->
    <div class="gallery-grid" id="galleryGrid">
        <?php foreach ($photos as $index => $photo): 
            $photoUrl = $uploadUrlPath . urlencode($photo);
            $photoPath = $uploadDir . $photo;
            $fileExists = file_exists($photoPath);
        ?>
        <div class="photo-card" data-id="<?php echo htmlspecialchars($photo); ?>" data-index="<?php echo $index; ?>">
            
            <?php if ($fileExists): ?>
                <img src="<?php echo $photoUrl; ?>" 
                     alt="Vehicle photo" 
                     loading="lazy"
                     onerror="this.parentElement.innerHTML='<div class=\'error-placeholder\'><i class=\'bi bi-image\' style=\'font-size:2rem;display:block;margin-bottom:8px;\'></i>Image not found</div>'">
            <?php else: ?>
                <div class="error-placeholder">
                    <i class="bi bi-image" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                    File missing<br>
                    <small><?php echo htmlspecialchars($photo); ?></small>
                </div>
            <?php endif; ?>
            
            <div class="photo-badge">
                <i class="bi bi-image"></i>
                Photo <?php echo $index + 1; ?>
            </div>
            
            <div class="photo-overlay">
                <div class="photo-actions">
                    <button class="photo-action-btn delete" onclick="deletePhoto('<?php echo htmlspecialchars($photo); ?>')" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            
            <div class="photo-checkbox" onclick="toggleSelect(this, '<?php echo htmlspecialchars($photo); ?>')">
                <i class="bi bi-check"></i>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="empty-state">
    <i class="bi bi-car-front"></i>
    <h3>Select a vehicle</h3>
    <p>Please select a vehicle from the dropdown above to manage its photos</p>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; border-radius: 12px; max-width: 400px; width: 90%; overflow: hidden;">
        <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--border-gray); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.125rem;">Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="padding: 30px 20px; text-align: center;">
            <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--cars-danger); margin-bottom: 16px; display: block;"></i>
            <p id="deleteMessage" style="font-size: 1rem; margin-bottom: 8px; color: var(--text-dark);">Are you sure you want to delete this photo?</p>
            <p style="font-size: 0.8125rem; color: var(--text-light);">This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm" style="padding: 0 20px 20px;">
            <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
            <input type="hidden" name="image_name" id="delete_image_name" value="">
            <input type="hidden" name="delete_photo" value="1">
            <div class="modal-footer" style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')" style="padding: 10px 20px; border: 1px solid var(--border-gray); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 20px; background: var(--cars-danger); color: white; border: none; border-radius: 6px; cursor: pointer;">Delete Photo</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm" style="display: none;">
    <input type="hidden" name="vehicle_id" value="<?php echo $vehicleId; ?>">
    <input type="hidden" name="photos_to_delete" id="bulk_delete_photos" value="">
    <input type="hidden" name="bulk_delete" value="1">
</form>

<script>
// ============================================
// VEHICLE SELECTION
// ============================================
function changeVehicle(vehicleId) {
    if (vehicleId) {
        window.location.href = 'photos.php?vehicle=' + vehicleId;
    } else {
        window.location.href = 'photos.php';
    }
}

// ============================================
// FILE UPLOAD
// ============================================
let selectedFiles = [];

function uploadFiles(files) {
    if (files.length === 0) return;
    
    selectedFiles = files;
    document.getElementById('uploadProgress').style.display = 'block';
    
    // Simulate progress then submit
    let progress = 0;
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    
    const interval = setInterval(() => {
        progress += 5;
        progressFill.style.width = progress + '%';
        progressText.innerHTML = `Preparing ${files.length} file${files.length > 1 ? 's' : ''}... ${progress}%`;
        
        if (progress >= 100) {
            clearInterval(interval);
            progressText.innerHTML = 'Uploading to server...';
            // Submit the form
            document.getElementById('uploadForm').submit();
        }
    }, 50);
}

// Drag and drop handlers
const uploadArea = document.getElementById('uploadArea');
if (uploadArea) {
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('fileInput').files = files;
            uploadFiles(files);
        }
    });
}

// ============================================
// SELECTION FUNCTIONS
// ============================================
let selectedPhotos = new Set();

function toggleSelect(element, photoId) {
    const card = element.closest('.photo-card');
    if (selectedPhotos.has(photoId)) {
        selectedPhotos.delete(photoId);
        element.classList.remove('selected');
        card.classList.remove('selected');
    } else {
        selectedPhotos.add(photoId);
        element.classList.add('selected');
        card.classList.add('selected');
    }
    updateSelectionUI();
}

function selectAll() {
    document.querySelectorAll('.photo-card').forEach(card => {
        const photoId = card.dataset.id;
        const checkbox = card.querySelector('.photo-checkbox');
        selectedPhotos.add(photoId);
        checkbox.classList.add('selected');
        card.classList.add('selected');
    });
    updateSelectionUI();
}

function deselectAll() {
    selectedPhotos.clear();
    document.querySelectorAll('.photo-card').forEach(card => {
        const checkbox = card.querySelector('.photo-checkbox');
        checkbox.classList.remove('selected');
        card.classList.remove('selected');
    });
    updateSelectionUI();
}

function updateSelectionUI() {
    const count = selectedPhotos.size;
    const totalCards = document.querySelectorAll('.photo-card').length;
    
    const selectedCountEl = document.getElementById('selectedCount');
    const selectedDisplayEl = document.getElementById('selectedDisplay');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    
    if (selectedCountEl) selectedCountEl.textContent = count;
    if (selectedDisplayEl) selectedDisplayEl.innerHTML = count + ' photo' + (count !== 1 ? 's' : '') + ' selected';
    
    if (selectAllBtn) selectAllBtn.style.display = count === totalCards && totalCards > 0 ? 'none' : 'inline-block';
    if (deselectAllBtn) deselectAllBtn.style.display = count > 0 ? 'inline-block' : 'none';
    if (deleteSelectedBtn) deleteSelectedBtn.style.display = count > 0 ? 'inline-block' : 'none';
}

function deleteSelected() {
    if (selectedPhotos.size === 0) return;
    
    const count = selectedPhotos.size;
    if (confirm(`Are you sure you want to delete ${count} photo${count !== 1 ? 's' : ''}? This action cannot be undone.`)) {
        const photos = Array.from(selectedPhotos);
        document.getElementById('bulk_delete_photos').value = JSON.stringify(photos);
        document.getElementById('bulkDeleteForm').submit();
    }
}

// ============================================
// PHOTO ACTIONS
// ============================================
function deletePhoto(photoId) {
    document.getElementById('delete_image_name').value = photoId;
    openModal('deleteModal');
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
        document.body.style.overflow = 'auto';
    }
});
</script>

<?php require_once 'includes/cars_footer.php'; ?>