<?php
$pageTitle = 'Photo Gallery';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// Get filter parameter
$experienceId = isset($_GET['experience']) ? intval($_GET['experience']) : 0;

// Define upload directories - matching stays structure but for experiences
$uploadMainDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/';
$uploadGalleryDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/attractions/gallery/';
$uploadMainUrl = '/gorwanda-plus/assets/images/attractions/';
$uploadGalleryUrl = '/gorwanda-plus/assets/images/attractions/gallery/';

// Create directories if they don't exist
if (!file_exists($uploadMainDir)) {
    mkdir($uploadMainDir, 0777, true);
}
if (!file_exists($uploadGalleryDir)) {
    mkdir($uploadGalleryDir, 0777, true);
}

// ============================================
// HANDLE PHOTO ACTIONS
// ============================================

// Upload photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photos'])) {
    $experienceId = intval($_POST['experience_id']);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT attraction_id FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this experience";
    } else {
        // Get existing images
        $stmt = $db->prepare("SELECT main_image, gallery_images FROM attractions WHERE attraction_id = ?");
        $stmt->execute([$experienceId]);
        $experience = $stmt->fetch();
        
        $existingImages = [];
        if ($experience['gallery_images']) {
            $existingImages = json_decode($experience['gallery_images'], true) ?: [];
        }
        
        // Handle new uploads
        if (!empty($_FILES['photos']['name'][0])) {
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
                    $fileName = 'exp_' . $experienceId . '_' . time() . '_' . uniqid() . '.' . $fileExt;
                    $uploadPath = $uploadGalleryDir . $fileName;
                    
                    // Move uploaded file
                    if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
                        // Optimize image
                        if ($mimeType === 'image/jpeg') {
                            $image = @imagecreatefromjpeg($uploadPath);
                            if ($image) {
                                imagejpeg($image, $uploadPath, 85);
                                imagedestroy($image);
                            }
                        } elseif ($mimeType === 'image/png') {
                            $image = @imagecreatefrompng($uploadPath);
                            if ($image) {
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
            $stmt = $db->prepare("UPDATE attractions SET gallery_images = ? WHERE attraction_id = ?");
            $stmt->execute([$imagesJson, $experienceId]);
            
            if ($uploaded > 0) {
                $success = "$uploaded photo(s) uploaded successfully";
                if ($failed > 0) {
                    $success .= ", $failed failed";
                }
            } else {
                $error = "No photos were uploaded. " . implode("; ", $failedReasons);
            }
        }
    }
}

// Set as main image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_main'])) {
    $experienceId = intval($_POST['experience_id']);
    $imageName = sanitize($_POST['image_name']);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT attraction_id FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    if ($stmt->fetch()) {
        // Update main image
        $stmt = $db->prepare("UPDATE attractions SET main_image = ? WHERE attraction_id = ?");
        $stmt->execute([$imageName, $experienceId]);
        
        $success = "Main image updated successfully";
    }
}

// Delete photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    $experienceId = intval($_POST['experience_id']);
    $imageName = sanitize($_POST['image_name']);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT main_image, gallery_images FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    $experience = $stmt->fetch();
    
    if ($experience) {
        $images = json_decode($experience['gallery_images'] ?? '[]', true) ?: [];
        
        // Remove from array
        $key = array_search($imageName, $images);
        if ($key !== false) {
            unset($images[$key]);
            $images = array_values($images);
            
            // Update database
            $imagesJson = json_encode($images);
            $stmt = $db->prepare("UPDATE attractions SET gallery_images = ? WHERE attraction_id = ?");
            $stmt->execute([$imagesJson, $experienceId]);
            
            // If this was the main image, clear it
            if ($experience['main_image'] === $imageName) {
                $stmt = $db->prepare("UPDATE attractions SET main_image = NULL WHERE attraction_id = ?");
                $stmt->execute([$experienceId]);
            }
            
            // Delete physical file
            $filePath = $uploadGalleryDir . $imageName;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            $success = "Photo deleted successfully";
        }
    }
}

// Bulk delete photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $experienceId = intval($_POST['experience_id']);
    $photosToDelete = json_decode($_POST['photos_to_delete'], true) ?: [];
    
    // Verify ownership
    $stmt = $db->prepare("SELECT main_image, gallery_images FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    $experience = $stmt->fetch();
    
    if ($experience && !empty($photosToDelete)) {
        $images = json_decode($experience['gallery_images'] ?? '[]', true) ?: [];
        $deletedCount = 0;
        $newMainImage = $experience['main_image'];
        
        foreach ($photosToDelete as $imageName) {
            $key = array_search($imageName, $images);
            if ($key !== false) {
                unset($images[$key]);
                
                // Delete physical file
                $filePath = $uploadGalleryDir . $imageName;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                
                // Check if this was main image
                if ($newMainImage === $imageName) {
                    $newMainImage = null;
                }
                
                $deletedCount++;
            }
        }
        
        $images = array_values($images);
        
        // Update database
        $imagesJson = json_encode($images);
        $stmt = $db->prepare("UPDATE attractions SET main_image = ?, gallery_images = ? WHERE attraction_id = ?");
        $stmt->execute([$newMainImage, $imagesJson, $experienceId]);
        
        $success = "$deletedCount photo(s) deleted successfully";
    }
}

// Reorder photos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder_photos'])) {
    $experienceId = intval($_POST['experience_id']);
    $order = json_decode($_POST['photo_order'], true);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT attraction_id FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    if ($stmt->fetch()) {
        $imagesJson = json_encode($order);
        $stmt = $db->prepare("UPDATE attractions SET gallery_images = ? WHERE attraction_id = ?");
        $stmt->execute([$imagesJson, $experienceId]);
        
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

// ============================================
// GET EXPERIENCES AND PHOTOS
// ============================================

// ============================================
// GET EXPERIENCES AND PHOTOS
// ============================================

// Get all experiences for this partner - FIXED: removed city column
$stmt = $db->prepare("
    SELECT 
        a.attraction_id, 
        a.attraction_name, 
        a.main_image, 
        a.gallery_images,
        l.name as location_name
    FROM attractions a
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.owner_id = ? 
    ORDER BY a.attraction_name
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

// If no experience selected, use the first one
if ($experienceId === 0 && !empty($experiences)) {
    $experienceId = $experiences[0]['attraction_id'];
}

// Get photos for selected experience
$currentExperience = null;
$photos = [];
if ($experienceId > 0) {
    foreach ($experiences as $exp) {
        if ($exp['attraction_id'] == $experienceId) {
            $currentExperience = $exp;
            break;
        }
    }
    
    if ($currentExperience) {
        $photos = json_decode($currentExperience['gallery_images'] ?? '[]', true) ?: [];
        if ($currentExperience['main_image'] && !in_array($currentExperience['main_image'], $photos)) {
            array_unshift($photos, $currentExperience['main_image']);
        }
    }
}

// Get photo statistics
$totalPhotos = 0;
$experiencesWithPhotos = 0;
foreach ($experiences as $exp) {
    $expPhotos = json_decode($exp['gallery_images'] ?? '[]', true) ?: [];
    if ($exp['main_image']) {
        $expPhotos[] = $exp['main_image'];
    }
    $expPhotos = array_unique($expPhotos);
    $count = count($expPhotos);
    $totalPhotos += $count;
    if ($count > 0) {
        $experiencesWithPhotos++;
    }
}
?>

<style>
/* Photos Management Specific Styles - Using experience purple theme */
:root {
    --exp-purple: #9333ea;
    --exp-light-purple: #f3e8ff;
    --exp-success: #10b981;
    --exp-warning: #f59e0b;
    --exp-danger: #ef4444;
    --exp-border: #e5e7eb;
    --exp-text: #111827;
    --exp-text-light: #6b7280;
    --exp-text-lighter: #9ca3af;
    --exp-gray: #f9fafb;
}

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
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.photos-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
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
    border: 1px solid var(--exp-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.stat-footer {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
}

/* Experience Selector */
.experience-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.experience-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--exp-text);
}

.experience-selector select {
    min-width: 350px;
    padding: 10px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Upload Area */
.upload-container {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 30px;
    margin-bottom: 30px;
    text-align: center;
}

.upload-area {
    border: 2px dashed var(--exp-border);
    border-radius: var(--radius-md);
    padding: 40px;
    background: var(--exp-gray);
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
}

.upload-area:hover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
}

.upload-area.dragover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
    transform: scale(1.02);
}

.upload-icon {
    font-size: 3rem;
    color: var(--exp-text-light);
    margin-bottom: 16px;
}

.upload-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-text);
    margin-bottom: 8px;
}

.upload-subtitle {
    font-size: 0.875rem;
    color: var(--exp-text-light);
    margin-bottom: 16px;
}

.upload-hint {
    font-size: 0.75rem;
    color: var(--exp-text-lighter);
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
    border: 1px solid var(--exp-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.selected-count {
    background: var(--exp-light-purple);
    padding: 6px 12px;
    border-radius: 100px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--exp-purple);
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
    border: 1px solid var(--exp-border);
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
    color: var(--exp-text);
}

.gallery-stats {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
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
    cursor: move;
    cursor: grab;
    transition: all 0.2s;
    border: 2px solid transparent;
    background: var(--exp-gray);
}

.photo-card.dragging {
    opacity: 0.5;
    cursor: grabbing;
}

.photo-card.selected {
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 2px var(--exp-purple);
}

.photo-card.main {
    border-color: var(--exp-success);
    box-shadow: 0 0 0 2px var(--exp-success);
}

.photo-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    pointer-events: none;
    display: block;
}

.photo-card .error-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
    color: #999;
    font-size: 0.75rem;
    text-align: center;
    padding: 10px;
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

.photo-badge.main-badge {
    background: var(--exp-success);
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
    color: var(--exp-text);
    font-size: 0.875rem;
}

.photo-action-btn:hover {
    transform: scale(1.1);
}

.photo-action-btn.set-main:hover {
    background: var(--exp-success);
    color: white;
}

.photo-action-btn.delete:hover {
    background: var(--exp-danger);
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
    border: 2px solid var(--exp-border);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 2;
    transition: all 0.2s;
}

.photo-checkbox:hover {
    border-color: var(--exp-purple);
}

.photo-checkbox.selected {
    background: var(--exp-purple);
    border-color: var(--exp-purple);
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--exp-gray);
    border-radius: var(--radius-md);
}

.empty-state i {
    font-size: 3rem;
    color: var(--exp-text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-text);
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--exp-text-light);
    margin-bottom: 20px;
}

/* Loading Spinner */
.spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 3px solid var(--exp-border);
    border-top-color: var(--exp-purple);
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
    background: var(--exp-border);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--exp-purple);
    width: 0%;
    transition: width 0.3s;
}

.progress-text {
    font-size: 0.75rem;
    color: var(--exp-text-light);
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
    
    .experience-selector select {
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
        <p>Manage photos for your experiences</p>
    </div>
</div>

<!-- Debug Info (Remove in production) -->
<?php if (!empty($error) || isset($_GET['debug'])): ?>
<div class="debug-info">
    <strong>Debug Info:</strong><br>
    Main Upload Directory: <code><?php echo htmlspecialchars($uploadMainDir); ?></code><br>
    Gallery Upload Directory: <code><?php echo htmlspecialchars($uploadGalleryDir); ?></code><br>
    Main Directory Exists: <code><?php echo file_exists($uploadMainDir) ? 'YES' : 'NO'; ?></code><br>
    Gallery Directory Exists: <code><?php echo file_exists($uploadGalleryDir) ? 'YES' : 'NO'; ?></code><br>
    <?php if (!empty($error)): ?>
    <br>Error: <code><?php echo htmlspecialchars($error); ?></code>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($experiences); ?></div>
        <div class="stat-label">Total Experiences</div>
        <div class="stat-footer"><?php echo $experiencesWithPhotos; ?> with photos</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalPhotos; ?></div>
        <div class="stat-label">Total Photos</div>
        <div class="stat-footer">Across all experiences</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalPhotos > 0 ? round($totalPhotos / max(1, count($experiences)), 1) : 0; ?></div>
        <div class="stat-label">Avg per Experience</div>
        <div class="stat-footer">Recommended: 5+ photos</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $experienceId > 0 && $currentExperience ? count($photos) : 0; ?></div>
        <div class="stat-label">Current Experience</div>
        <div class="stat-footer"><?php echo $currentExperience ? sanitize($currentExperience['attraction_name']) : 'Select experience'; ?></div>
    </div>
</div>

<!-- Experience Selector -->
<div class="experience-selector">
    <label for="experienceSelect"><i class="bi bi-ticket-perforated"></i> Select Experience:</label>
    <select id="experienceSelect" onchange="changeExperience(this.value)">
        <option value="">Choose an experience</option>
        <?php foreach ($experiences as $exp): ?>
<option value="<?php echo $exp['attraction_id']; ?>" <?php echo $exp['attraction_id'] == $experienceId ? 'selected' : ''; ?>>
    <?php echo sanitize($exp['attraction_name']); ?> (<?php echo sanitize($exp['location_name'] ?? 'Rwanda'); ?>)
    <?php 
    $expPhotos = json_decode($exp['gallery_images'] ?? '[]', true) ?: [];
    if ($exp['main_image']) $expPhotos[] = $exp['main_image'];
    echo ' - ' . count(array_unique($expPhotos)) . ' photos';
    ?>
</option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($experienceId > 0 && $currentExperience): ?>

<!-- Upload Area -->
<div class="upload-container">
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="experience_id" value="<?php echo $experienceId; ?>">
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
                <span><i class="bi bi-check-circle-fill text-success"></i> At least 800px wide</span>
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
            <i class="bi bi-images"></i> Experience Photos
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
        <p>Upload photos to showcase your experience to potential guests</p>
        <button class="btn-primary" onclick="document.getElementById('fileInput').click()" style="padding: 10px 20px; background: var(--exp-purple); color: white; border: none; border-radius: 6px; cursor: pointer;">
            <i class="bi bi-cloud-arrow-up"></i> Upload Photos
        </button>
    </div>
    <?php else: ?>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div class="selected-count" id="selectedDisplay">0 photos selected</div>
        <div class="bulk-actions">
            <button class="btn-secondary" onclick="selectAll()" id="selectAllBtn" style="padding: 8px 16px; border: 1px solid var(--exp-border); background: white; border-radius: 6px; cursor: pointer;">
                <i class="bi bi-check-all"></i> Select All
            </button>
            <button class="btn-secondary" onclick="deselectAll()" id="deselectAllBtn" style="display: none; padding: 8px 16px; border: 1px solid var(--exp-border); background: white; border-radius: 6px; cursor: pointer;">
                <i class="bi bi-x"></i> Deselect All
            </button>
            <button class="btn-secondary" onclick="deleteSelected()" id="deleteSelectedBtn" style="display: none; padding: 8px 16px; border: 1px solid var(--exp-danger); background: white; color: var(--exp-danger); border-radius: 6px; cursor: pointer;">
                <i class="bi bi-trash"></i> Delete Selected
            </button>
            <button class="btn-outline" onclick="saveOrder()" id="saveOrderBtn" style="padding: 8px 16px; border: 1px solid var(--exp-purple); background: white; color: var(--exp-purple); border-radius: 6px; cursor: pointer;">
                <i class="bi bi-arrow-down-up"></i> Save Order
            </button>
        </div>
    </div>
    
    <!-- Photo Grid -->
    <div class="gallery-grid" id="galleryGrid">
        <?php foreach ($photos as $index => $photo): 
            $isMain = ($photo === $currentExperience['main_image']);
            $photoUrl = $uploadGalleryUrl . urlencode($photo);
            $photoPath = $uploadGalleryDir . $photo;
            $fileExists = file_exists($photoPath);
        ?>
        <div class="photo-card <?php echo $isMain ? 'main' : ''; ?>" 
             data-id="<?php echo htmlspecialchars($photo); ?>" 
             data-index="<?php echo $index; ?>" 
             draggable="true" 
             ondragstart="dragStart(event)" 
             ondragend="dragEnd(event)" 
             ondragover="dragOver(event)" 
             ondrop="drop(event)">
            
            <?php if ($fileExists): ?>
                <img src="<?php echo $photoUrl; ?>" 
                     alt="Experience photo" 
                     loading="lazy"
                     onerror="this.parentElement.innerHTML='<div class=\'error-placeholder\'><i class=\'bi bi-image\' style=\'font-size:2rem;display:block;margin-bottom:8px;\'></i>Image not found</div>'">
            <?php else: ?>
                <div class="error-placeholder">
                    <i class="bi bi-image" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                    File missing<br>
                    <small><?php echo htmlspecialchars($photo); ?></small>
                </div>
            <?php endif; ?>
            
            <div class="photo-badge <?php echo $isMain ? 'main-badge' : ''; ?>">
                <i class="bi bi-<?php echo $isMain ? 'star-fill' : 'image'; ?>"></i>
                <?php echo $isMain ? 'Main Photo' : 'Photo ' . ($index + 1); ?>
            </div>
            
            <div class="photo-overlay">
                <div class="photo-actions">
                    <?php if (!$isMain): ?>
                    <button class="photo-action-btn set-main" onclick="setAsMain('<?php echo htmlspecialchars($photo); ?>')" title="Set as main photo">
                        <i class="bi bi-star"></i>
                    </button>
                    <?php endif; ?>
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
    <i class="bi bi-ticket-perforated"></i>
    <h3>Select an experience</h3>
    <p>Please select an experience from the dropdown above to manage its photos</p>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; border-radius: 12px; max-width: 400px; width: 90%; overflow: hidden;">
        <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--exp-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.125rem;">Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="padding: 30px 20px; text-align: center;">
            <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--exp-danger); margin-bottom: 16px; display: block;"></i>
            <p id="deleteMessage" style="font-size: 1rem; margin-bottom: 8px; color: var(--exp-text);">Are you sure you want to delete this photo?</p>
            <p style="font-size: 0.8125rem; color: var(--exp-text-light);">This action cannot be undone.</p>
        </div>
        <form method="POST" id="deleteForm" style="padding: 0 20px 20px;">
            <input type="hidden" name="experience_id" value="<?php echo $experienceId; ?>">
            <input type="hidden" name="image_name" id="delete_image_name" value="">
            <input type="hidden" name="delete_photo" value="1">
            <div class="modal-footer" style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')" style="padding: 10px 20px; border: 1px solid var(--exp-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 20px; background: var(--exp-danger); color: white; border: none; border-radius: 6px; cursor: pointer;">Delete Photo</button>
            </div>
        </form>
    </div>
</div>

<!-- Set Main Confirmation Modal -->
<div class="modal" id="setMainModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; border-radius: 12px; max-width: 400px; width: 90%; overflow: hidden;">
        <div class="modal-header" style="padding: 20px; border-bottom: 1px solid var(--exp-border); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.125rem;">Set as Main Photo</h3>
            <button class="modal-close" onclick="closeModal('setMainModal')" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="padding: 30px 20px; text-align: center;">
            <i class="bi bi-star-fill" style="font-size: 3rem; color: var(--exp-warning); margin-bottom: 16px; display: block;"></i>
            <p id="setMainMessage" style="font-size: 1rem; margin-bottom: 8px; color: var(--exp-text);">Set this as the main photo for your experience?</p>
            <p style="font-size: 0.8125rem; color: var(--exp-text-light);">This photo will be shown first in search results.</p>
        </div>
        <form method="POST" id="setMainForm" style="padding: 0 20px 20px;">
            <input type="hidden" name="experience_id" value="<?php echo $experienceId; ?>">
            <input type="hidden" name="image_name" id="set_main_image_name" value="">
            <input type="hidden" name="set_main" value="1">
            <div class="modal-footer" style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('setMainModal')" style="padding: 10px 20px; border: 1px solid var(--exp-border); background: white; border-radius: 6px; cursor: pointer;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 20px; background: var(--exp-purple); color: white; border: none; border-radius: 6px; cursor: pointer;">Set as Main</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm" style="display: none;">
    <input type="hidden" name="experience_id" value="<?php echo $experienceId; ?>">
    <input type="hidden" name="photos_to_delete" id="bulk_delete_photos" value="">
    <input type="hidden" name="bulk_delete" value="1">
</form>

<script>
// ============================================
// EXPERIENCE SELECTION
// ============================================
function changeExperience(experienceId) {
    if (experienceId) {
        window.location.href = 'photos.php?experience=' + experienceId;
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

// ============================================
// DRAG AND DROP REORDER
// ============================================
let draggedItem = null;
let draggedIndex = null;

function dragStart(e) {
    draggedItem = e.target.closest('.photo-card');
    draggedIndex = parseInt(draggedItem.dataset.index);
    e.dataTransfer.setData('text/plain', draggedIndex);
    draggedItem.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
}

function dragEnd(e) {
    document.querySelectorAll('.photo-card').forEach(card => {
        card.classList.remove('dragging');
    });
    draggedItem = null;
    draggedIndex = null;
}

function dragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    return false;
}

function drop(e) {
    e.preventDefault();
    const target = e.target.closest('.photo-card');
    if (!target || target === draggedItem) return;
    
    const fromIndex = draggedIndex;
    const toIndex = parseInt(target.dataset.index);
    
    // Reorder DOM elements
    const grid = document.getElementById('galleryGrid');
    const cards = Array.from(grid.children);
    
    if (fromIndex < toIndex) {
        grid.insertBefore(draggedItem, cards[toIndex + 1]);
    } else {
        grid.insertBefore(draggedItem, cards[toIndex]);
    }
    
    // Update indices
    updateIndices();
    
    // Show save button hint
    const saveBtn = document.getElementById('saveOrderBtn');
    saveBtn.style.background = '#f3e8ff';
    saveBtn.style.color = '#9333ea';
    saveBtn.innerHTML = '<i class="bi bi-check-circle"></i> Save New Order';
}

function updateIndices() {
    const cards = document.querySelectorAll('.photo-card');
    cards.forEach((card, index) => {
        card.dataset.index = index;
        const badge = card.querySelector('.photo-badge');
        if (badge && !badge.classList.contains('main-badge')) {
            badge.innerHTML = `<i class="bi bi-image"></i> Photo ${index + 1}`;
        }
    });
}

// ============================================
// SAVE ORDER
// ============================================
function saveOrder() {
    const cards = document.querySelectorAll('.photo-card');
    const order = [];
    cards.forEach(card => {
        order.push(card.dataset.id);
    });
    
    const saveBtn = document.getElementById('saveOrderBtn');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    saveBtn.disabled = true;
    
    fetch('photos.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'reorder_photos=1&experience_id=<?php echo $experienceId; ?>&photo_order=' + encodeURIComponent(JSON.stringify(order))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Photo order saved successfully', 'success');
            saveBtn.innerHTML = '<i class="bi bi-check"></i> Order Saved';
            saveBtn.style.background = '';
            saveBtn.style.color = '';
            setTimeout(() => {
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            }, 2000);
        } else {
            showNotification(data.error || 'Failed to save order', 'error');
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        }
    })
    .catch(err => {
        showNotification('Network error. Please try again.', 'error');
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
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
    
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('selectedDisplay').innerHTML = count + ' photo' + (count !== 1 ? 's' : '') + ' selected';
    
    document.getElementById('selectAllBtn').style.display = count === totalCards && totalCards > 0 ? 'none' : 'inline-block';
    document.getElementById('deselectAllBtn').style.display = count > 0 ? 'inline-block' : 'none';
    document.getElementById('deleteSelectedBtn').style.display = count > 0 ? 'inline-block' : 'none';
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

function setAsMain(photoId) {
    document.getElementById('set_main_image_name').value = photoId;
    openModal('setMainModal');
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

// ============================================
// NOTIFICATION
// ============================================
function showNotification(message, type = 'success') {
    // Remove existing notifications
    document.querySelectorAll('.notification-toast').forEach(n => n.remove());
    
    const notification = document.createElement('div');
    notification.className = 'notification-toast';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 16px 20px;
        background: ${type === 'success' ? '#e6f4ea' : '#fce8e8'};
        color: ${type === 'success' ? '#1e7e34' : '#c82333'};
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        font-size: 0.875rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 300px;
        animation: slideIn 0.3s ease;
        border: 1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'};
    `;
    notification.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill" style="font-size: 1.25rem;"></i>
        <span style="flex: 1;">${message}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer; padding: 4px;"><i class="bi bi-x-lg"></i></button>
    `;
    document.body.appendChild(notification);
    
    // Auto remove after 4 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }
    }, 4000);
}

// Add animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once 'includes/experiences_footer.php'; ?>