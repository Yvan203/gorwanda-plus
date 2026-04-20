<?php
$pageTitle = 'Manage Experiences';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// DEFINE UPLOAD PATHS (SAME AS photos.php)
// ============================================
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

// Check if directories are writable
$uploadMainWritable = is_writable($uploadMainDir);
$uploadGalleryWritable = is_writable($uploadGalleryDir);

// ============================================
// HANDLE AJAX REQUESTS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'toggle_status') {
        $experienceId = intval($_POST['experience_id']);
        $currentStatus = intval($_POST['current_status']);
        $newStatus = $currentStatus ? 0 : 1;
        
        $stmt = $db->prepare("UPDATE attractions SET is_active = ? WHERE attraction_id = ? AND owner_id = ?");
        $stmt->execute([$newStatus, $experienceId, $userId]);
        
        echo json_encode(['success' => true, 'new_status' => $newStatus]);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'delete_gallery_image') {
        $experienceId = intval($_POST['experience_id']);
        $imageName = sanitize($_POST['image_name']);
        
        $stmt = $db->prepare("SELECT gallery_images FROM attractions WHERE attraction_id = ? AND owner_id = ?");
        $stmt->execute([$experienceId, $userId]);
        $galleryJson = $stmt->fetchColumn();
        
        if ($galleryJson) {
            $gallery = json_decode($galleryJson, true);
            $gallery = array_diff($gallery, [$imageName]);
            
            // Delete file using the same path as photos.php
            $filePath = $uploadGalleryDir . $imageName;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            $newGallery = !empty($gallery) ? json_encode(array_values($gallery)) : null;
            $stmt = $db->prepare("UPDATE attractions SET gallery_images = ? WHERE attraction_id = ?");
            $stmt->execute([$newGallery, $experienceId]);
            
            echo json_encode(['success' => true]);
            exit;
        }
    }
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================
$message = '';
$error = '';

// Add/Edit Experience
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_experience'])) {
    $experienceId = intval($_POST['experience_id'] ?? 0);
    $attractionName = sanitize($_POST['attraction_name']);
    $categoryId = intval($_POST['category_id']);
    $description = sanitize($_POST['description']);
    $locationId = intval($_POST['location_id'] ?? 0);
    $address = sanitize($_POST['address'] ?? '');
    $durationMinutes = intval($_POST['duration_minutes']);
    $difficultyLevel = sanitize($_POST['difficulty_level']);
    $minAge = intval($_POST['min_age'] ?? 0);
    $maxGroupSize = intval($_POST['max_group_size']);
    $meetingPoint = sanitize($_POST['meeting_point'] ?? '');
    $cancellationPolicy = sanitize($_POST['cancellation_policy'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $includedItems = isset($_POST['included_items']) ? json_encode($_POST['included_items']) : '[]';
    $whatToBring = isset($_POST['what_to_bring']) ? json_encode($_POST['what_to_bring']) : '[]';
    $startTimes = isset($_POST['start_times']) ? json_encode(array_values(array_filter($_POST['start_times']))) : '[]';
    
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($attractionName));
    $slug = trim($slug, '-') . '-' . time();
    
    // ============================================
    // HANDLE MAIN IMAGE UPLOAD - USING SAME PATH AS photos.php
    // ============================================
    $mainImage = '';
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['main_image'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $error = "Invalid file type for main image. Allowed: JPG, PNG, GIF, WEBP";
        } elseif ($file['size'] > 10 * 1024 * 1024) { // 10MB max
            $error = "Main image too large. Max 10MB";
        } else {
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = 'exp_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $uploadMainDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
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
                $mainImage = $fileName;
            } else {
                $error = "Failed to upload main image. Please check directory permissions.";
            }
        }
    }
    
    // ============================================
    // HANDLE GALLERY IMAGES UPLOAD - USING SAME PATH AS photos.php
    // ============================================
    $galleryImages = [];
    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $files = $_FILES['gallery_images'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                // Validate file type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                
                if (!in_array($mimeType, $allowedTypes)) {
                    continue; // Skip invalid files
                }
                
                if ($files['size'][$i] > 10 * 1024 * 1024) {
                    continue; // Skip files too large
                }
                
                $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                $fileName = 'exp_gallery_' . time() . '_' . uniqid() . '.' . $fileExt;
                $uploadPath = $uploadGalleryDir . $fileName;
                
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
                    $galleryImages[] = $fileName;
                }
            }
        }
    }
    
    if ($experienceId > 0) {
        // Check ownership
        $stmt = $db->prepare("SELECT attraction_id, main_image, gallery_images FROM attractions WHERE attraction_id = ? AND owner_id = ?");
        $stmt->execute([$experienceId, $userId]);
        $existing = $stmt->fetch();
        
        if (!$existing) {
            $error = "You don't have permission to edit this experience";
        } else {
            // Update existing
            if ($mainImage) {
                // Delete old main image
                if ($existing['main_image']) {
                    $oldPath = $uploadMainDir . $existing['main_image'];
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                
                $stmt = $db->prepare("
                    UPDATE attractions SET 
                        attraction_name = ?, category_id = ?, description = ?,
                        location_id = ?, address = ?, duration_minutes = ?,
                        difficulty_level = ?, min_age = ?, max_group_size = ?,
                        meeting_point = ?, included_items = ?, what_to_bring = ?,
                        start_times = ?, cancellation_policy = ?, main_image = ?,
                        is_active = ?
                    WHERE attraction_id = ? AND owner_id = ?
                ");
                $stmt->execute([
                    $attractionName, $categoryId, $description, $locationId, $address,
                    $durationMinutes, $difficultyLevel, $minAge, $maxGroupSize,
                    $meetingPoint, $includedItems, $whatToBring, $startTimes,
                    $cancellationPolicy, $mainImage, $isActive, $experienceId, $userId
                ]);
            } else {
                $stmt = $db->prepare("
                    UPDATE attractions SET 
                        attraction_name = ?, category_id = ?, description = ?,
                        location_id = ?, address = ?, duration_minutes = ?,
                        difficulty_level = ?, min_age = ?, max_group_size = ?,
                        meeting_point = ?, included_items = ?, what_to_bring = ?,
                        start_times = ?, cancellation_policy = ?,
                        is_active = ?
                    WHERE attraction_id = ? AND owner_id = ?
                ");
                $stmt->execute([
                    $attractionName, $categoryId, $description, $locationId, $address,
                    $durationMinutes, $difficultyLevel, $minAge, $maxGroupSize,
                    $meetingPoint, $includedItems, $whatToBring, $startTimes,
                    $cancellationPolicy, $isActive, $experienceId, $userId
                ]);
            }
            
            // Handle gallery images
            if (!empty($galleryImages)) {
                $existingGallery = $existing['gallery_images'] ? json_decode($existing['gallery_images'], true) : [];
                $allImages = array_merge($existingGallery, $galleryImages);
                $stmt = $db->prepare("UPDATE attractions SET gallery_images = ? WHERE attraction_id = ?");
                $stmt->execute([json_encode($allImages), $experienceId]);
            }
            
            $message = "Experience updated successfully!";
        }
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO attractions (
                owner_id, attraction_name, slug, category_id, description,
                location_id, address, duration_minutes, difficulty_level,
                min_age, max_group_size, meeting_point, included_items,
                what_to_bring, start_times, cancellation_policy, main_image,
                gallery_images, is_active, is_verified, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
        ");
        
        $galleryJson = !empty($galleryImages) ? json_encode($galleryImages) : null;
        
        $stmt->execute([
            $userId, $attractionName, $slug, $categoryId, $description,
            $locationId, $address, $durationMinutes, $difficultyLevel,
            $minAge, $maxGroupSize, $meetingPoint, $includedItems,
            $whatToBring, $startTimes, $cancellationPolicy, $mainImage,
            $galleryJson, $isActive
        ]);
        
        $message = "Experience added successfully!";
    }
}

// Delete Experience
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_experience'])) {
    $experienceId = intval($_POST['experience_id']);
    
    $stmt = $db->prepare("SELECT main_image, gallery_images FROM attractions WHERE attraction_id = ? AND owner_id = ?");
    $stmt->execute([$experienceId, $userId]);
    $exp = $stmt->fetch();
    
    if ($exp) {
        // Delete main image
        if ($exp['main_image']) {
            $mainPath = $uploadMainDir . $exp['main_image'];
            if (file_exists($mainPath)) {
                unlink($mainPath);
            }
        }
        
        // Delete gallery images
        if ($exp['gallery_images']) {
            $gallery = json_decode($exp['gallery_images'], true);
            foreach ($gallery as $img) {
                $galleryPath = $uploadGalleryDir . $img;
                if (file_exists($galleryPath)) {
                    unlink($galleryPath);
                }
            }
        }
        
        $stmt = $db->prepare("DELETE FROM attractions WHERE attraction_id = ? AND owner_id = ?");
        $stmt->execute([$experienceId, $userId]);
        $message = "Experience deleted successfully!";
    }
}

// ============================================
// GET DATA FOR DISPLAY
// ============================================

$stmt = $db->prepare("
    SELECT 
        a.*,
        c.name as category_name,
        c.icon as category_icon,
        l.name as location_name,
        (SELECT COUNT(*) FROM attraction_tiers WHERE attraction_id = a.attraction_id) as tier_count,
        (SELECT COUNT(*) FROM bookings b 
         JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id 
         WHERE at.attraction_id = a.attraction_id AND b.status = 'pending') as pending_bookings
    FROM attractions a
    LEFT JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.owner_id = ?
    ORDER BY a.created_at DESC
");
$stmt->execute([$userId]);
$experiences = $stmt->fetchAll();

$categories = $db->query("SELECT category_id, name, icon FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$locations = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name")->fetchAll();

// Calculate stats
$totalExperiences = count($experiences);
$totalActive = 0;
$totalInactive = 0;
$totalPending = 0;
$totalTiers = 0;

foreach ($experiences as $exp) {
    if ($exp['is_active']) {
        if ($exp['is_verified']) {
            $totalActive++;
        } else {
            $totalPending++;
        }
    } else {
        $totalInactive++;
    }
    $totalTiers += $exp['tier_count'];
}

// Helper function for image URLs (SAME AS photos.php)
function getExpImageUrl($image, $type = 'main') {
    if (!$image || $image === 'null' || $image === '') {
        return '/gorwanda-plus/assets/images/placeholders/placeholder.svg';
    }
    
    if ($type === 'main') {
        return '/gorwanda-plus/assets/images/attractions/' . $image;
    } else {
        return '/gorwanda-plus/assets/images/attractions/gallery/' . $image;
    }
}

$commonIncludedItems = [
    'guide' => 'Professional Guide',
    'park_fees' => 'Park Entrance Fees',
    'transport' => 'Transportation',
    'lunch' => 'Lunch',
    'water' => 'Bottled Water',
    'equipment' => 'Equipment Rental',
    'snacks' => 'Snacks',
    'drinks' => 'Drinks',
    'insurance' => 'Insurance',
    'photos' => 'Photos',
    'souvenir' => 'Souvenir',
    'pickup' => 'Hotel Pickup'
];

$commonBringItems = [
    'hiking_boots' => 'Hiking Boots',
    'rain_jacket' => 'Rain Jacket',
    'camera' => 'Camera',
    'water' => 'Water Bottle',
    'sunscreen' => 'Sunscreen',
    'hat' => 'Hat',
    'sunglasses' => 'Sunglasses',
    'snacks' => 'Snacks',
    'backpack' => 'Backpack',
    'binoculars' => 'Binoculars',
    'swimsuit' => 'Swimsuit',
    'towel' => 'Towel'
];

$difficultyLevels = [
    'easy' => 'Easy',
    'moderate' => 'Moderate',
    'challenging' => 'Challenging'
];
?>

<!-- Add debug info at the top (remove after fixing) -->
<?php if (isset($_GET['debug'])): ?>
<div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border: 1px solid #ccc; font-size: 12px;">
    <strong>Debug Info:</strong><br>
    Main Upload Dir: <?php echo $uploadMainDir; ?> - Exists: <?php echo file_exists($uploadMainDir) ? 'YES' : 'NO'; ?> - Writable: <?php echo $uploadMainWritable ? 'YES' : 'NO'; ?><br>
    Gallery Upload Dir: <?php echo $uploadGalleryDir; ?> - Exists: <?php echo file_exists($uploadGalleryDir) ? 'YES' : 'NO'; ?> - Writable: <?php echo $uploadGalleryWritable ? 'YES' : 'NO'; ?><br>
    Document Root: <?php echo $_SERVER['DOCUMENT_ROOT']; ?>
</div>
<?php endif; ?>

<style>
/* Keep all your existing styles - they're perfect */
.listings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

.listings-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0 0 4px 0;
}

.listings-title p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--exp-white);
    border-radius: var(--radius-md);
    padding: 18px 16px;
    border: 1px solid var(--exp-border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--exp-purple);
    line-height: 1.2;
    margin-bottom: 2px;
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    text-transform: uppercase;
}

.stat-footer {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--exp-border);
    font-size: 0.6875rem;
    color: var(--exp-text-light);
}

.filter-bar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    padding: 12px 16px;
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
}

.filter-search {
    flex: 2;
    min-width: 250px;
    position: relative;
}

.filter-search i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--exp-text-light);
}

.filter-search input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
    background: white;
    min-width: 140px;
}

.experiences-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.experience-card {
    background: white;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
    position: relative;
}

.experience-card:hover {
    box-shadow: var(--shadow-md);
}

.experience-image {
    height: 160px;
    position: relative;
    background-size: cover;
    background-position: center;
    background-color: var(--exp-gray);
}

.experience-badges {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    gap: 6px;
    z-index: 2;
}

.experience-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: rgba(0,0,0,0.7);
    color: white;
    backdrop-filter: blur(4px);
}

.experience-badge.verified {
    background: #10b981;
}

.experience-badge.pending {
    background: #f59e0b;
}

.experience-actions {
    position: absolute;
    top: 12px;
    left: 12px;
    display: flex;
    gap: 6px;
    z-index: 2;
    opacity: 0;
    transition: opacity 0.2s;
}

.experience-card:hover .experience-actions {
    opacity: 1;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: white;
    border: 1px solid var(--exp-border);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
}

.action-btn:hover {
    background: var(--exp-purple);
    color: white;
    border-color: var(--exp-purple);
}

.action-btn.delete:hover {
    background: #ef4444;
    border-color: #ef4444;
}

.experience-content {
    padding: 16px;
}

.experience-category {
    font-size: 0.75rem;
    color: var(--exp-purple);
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.experience-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0 0 8px 0;
}

.experience-meta {
    display: flex;
    gap: 16px;
    margin: 8px 0;
    padding: 8px 0;
    border-top: 1px solid var(--exp-border);
    border-bottom: 1px solid var(--exp-border);
    font-size: 0.75rem;
    color: var(--exp-text-light);
}

.meta-item i {
    color: var(--exp-purple);
    margin-right: 4px;
}

.experience-footer {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.footer-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--exp-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    text-decoration: none;
    display: inline-block;
}

.footer-btn:hover {
    background: var(--exp-light-purple);
    border-color: var(--exp-purple);
    color: var(--exp-purple);
}

.footer-btn.primary {
    background: var(--exp-purple);
    color: white;
    border-color: var(--exp-purple);
}

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
    overflow-y: auto;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 100%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--exp-gray);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: #ef4444;
    color: white;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--exp-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--exp-gray);
    position: sticky;
    bottom: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group.full-width {
    grid-column: span 2;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--exp-text);
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

.image-upload-area {
    border: 2px dashed var(--exp-border);
    border-radius: var(--radius-md);
    padding: 20px;
    text-align: center;
    background: var(--exp-gray);
    cursor: pointer;
    margin-bottom: 12px;
}

.image-upload-area:hover {
    border-color: var(--exp-purple);
    background: var(--exp-light-purple);
}

.image-upload-area i {
    font-size: 2rem;
    color: var(--exp-text-light);
    margin-bottom: 8px;
}

.image-upload-area p {
    font-size: 0.8125rem;
    color: var(--exp-text-light);
    margin: 0;
}

.image-preview {
    max-width: 150px;
    max-height: 100px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--exp-border);
    margin-top: 8px;
}

.gallery-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 12px;
}

.gallery-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-sm);
    overflow: hidden;
    border: 1px solid var(--exp-border);
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.gallery-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s;
}

.gallery-item:hover .gallery-remove {
    opacity: 1;
}

.checkbox-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 12px;
    background: var(--exp-gray);
    border-radius: var(--radius-sm);
    max-height: 200px;
    overflow-y: auto;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.8125rem;
}

.checkbox-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--exp-purple);
}

.start-times-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.time-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.time-row input {
    flex: 1;
}

.time-remove {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1px solid var(--exp-border);
    background: white;
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

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
}

.alert-danger {
    background: #fce8e8;
    color: #ef4444;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--exp-text-light);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    color: var(--exp-text-light);
    margin-bottom: 20px;
}

@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .form-grid,
    .checkbox-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-bar {
        flex-direction: column;
    }
    
    .filter-search,
    .filter-select {
        width: 100%;
    }
}
</style>

<div class="listings-header">
    <div class="listings-title">
        <h1>Manage Experiences</h1>
        <p>Add, edit, and manage your tour and activity listings</p>
    </div>
    <button class="btn-primary" onclick="openAddModal()">
        <i class="bi bi-plus-lg"></i> New Experience
    </button>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalExperiences; ?></div>
        <div class="stat-label">Total Experiences</div>
        <div class="stat-footer"><?php echo $totalActive; ?> active</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalTiers; ?></div>
        <div class="stat-label">Pricing Tiers</div>
        <div class="stat-footer">Across all experiences</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalPending; ?></div>
        <div class="stat-label">Pending Verification</div>
        <div class="stat-footer">Awaiting review</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalInactive; ?></div>
        <div class="stat-label">Inactive</div>
        <div class="stat-footer">Hidden from guests</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-search">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search experiences..." onkeyup="filterExperiences()">
    </div>
    
    <select class="filter-select" id="categoryFilter" onchange="filterExperiences()">
        <option value="all">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?php echo $cat['category_id']; ?>"><?php echo sanitize($cat['name']); ?></option>
        <?php endforeach; ?>
    </select>
    
    <select class="filter-select" id="statusFilter" onchange="filterExperiences()">
        <option value="all">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="pending">Pending</option>
    </select>
</div>

<!-- Message Display -->
<?php if ($message): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $message; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x"></i></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Experiences Grid -->
<?php if (empty($experiences)): ?>
<div class="empty-state">
    <i class="bi bi-ticket-perforated"></i>
    <h3>No experiences yet</h3>
    <p>Create your first experience to start receiving bookings</p>
    <button class="btn-primary" onclick="openAddModal()">
        <i class="bi bi-plus-lg"></i> Add Experience
    </button>
</div>
<?php else: ?>
<div class="experiences-grid" id="experiencesGrid">
    <?php foreach ($experiences as $exp): 
        $gallery = $exp['gallery_images'] ? json_decode($exp['gallery_images'], true) : [];
        $imageCount = count($gallery);
    ?>
    <div class="experience-card" 
         data-name="<?php echo strtolower($exp['attraction_name']); ?>"
         data-category="<?php echo $exp['category_id']; ?>"
         data-status="<?php echo $exp['is_active'] ? ($exp['is_verified'] ? 'active' : 'pending') : 'inactive'; ?>">
        
        <div class="experience-image" style="background-image: url('<?php echo getExpImageUrl($exp['main_image'] ?? '', 'main'); ?>');">
            <div class="experience-badges">
                <?php if ($exp['is_verified']): ?>
                <span class="experience-badge verified">
                    <i class="bi bi-patch-check-fill"></i> Verified
                </span>
                <?php elseif ($exp['is_active']): ?>
                <span class="experience-badge pending">
                    <i class="bi bi-clock-history"></i> Pending
                </span>
                <?php endif; ?>
                <?php if (!$exp['is_active']): ?>
                <span class="experience-badge">Inactive</span>
                <?php endif; ?>
                <?php if ($imageCount > 0): ?>
                <span class="experience-badge">
                    <i class="bi bi-images"></i> <?php echo $imageCount; ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div class="experience-actions">
                <button class="action-btn" onclick="editExperience(<?php echo htmlspecialchars(json_encode($exp)); ?>)" title="Edit">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="action-btn" onclick="toggleStatus(<?php echo $exp['attraction_id']; ?>, <?php echo $exp['is_active']; ?>)" title="<?php echo $exp['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                    <i class="bi bi-<?php echo $exp['is_active'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                </button>
                <button class="action-btn delete" onclick="deleteExperience(<?php echo $exp['attraction_id']; ?>, '<?php echo sanitize($exp['attraction_name']); ?>')" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        
        <div class="experience-content">
            <div class="experience-category">
                <i class="bi <?php echo $exp['category_icon'] ?? 'bi-tag'; ?>"></i>
                <?php echo sanitize($exp['category_name'] ?? 'Uncategorized'); ?>
            </div>
            
            <h3 class="experience-name"><?php echo sanitize($exp['attraction_name']); ?></h3>
            
            <div class="experience-meta">
                <span><i class="bi bi-clock"></i> <?php echo $exp['duration_minutes']; ?> min</span>
                <span><i class="bi bi-people"></i> Max <?php echo $exp['max_group_size']; ?></span>
                <span><i class="bi bi-geo-alt"></i> <?php echo sanitize($exp['location_name'] ?? 'Rwanda'); ?></span>
            </div>
            
            <?php if ($exp['pending_bookings'] > 0): ?>
            <div style="margin-bottom: 12px;">
                <span style="background: #f59e0b; color: white; padding: 4px 8px; border-radius: 100px; font-size: 0.625rem;">
                    <i class="bi bi-clock-history"></i> <?php echo $exp['pending_bookings']; ?> pending
                </span>
            </div>
            <?php endif; ?>
            
            <div class="experience-footer">
                <a href="tiers.php?experience=<?php echo $exp['attraction_id']; ?>" class="footer-btn">
                    <i class="bi bi-layers"></i> Tiers
                </a>
                <a href="schedule.php?experience=<?php echo $exp['attraction_id']; ?>" class="footer-btn">
                    <i class="bi bi-calendar-week"></i> Schedule
                </a>
                <a href="bookings.php?experience=<?php echo $exp['attraction_id']; ?>" class="footer-btn primary">
                    <i class="bi bi-calendar-check"></i> Bookings
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Experience Modal -->
<div class="modal" id="experienceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add New Experience</h3>
            <button class="modal-close" onclick="closeModal('experienceModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="experienceForm">
            <div class="modal-body">
                <input type="hidden" name="experience_id" id="experience_id" value="0">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Experience Name <span class="text-danger">*</span></label>
                        <input type="text" name="attraction_name" id="attraction_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="category_id" class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_id']; ?>"><?php echo sanitize($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Difficulty</label>
                        <select name="difficulty_level" id="difficulty_level" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($difficultyLevels as $key => $label): ?>
                            <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Duration (min)</label>
                        <input type="number" name="duration_minutes" id="duration_minutes" class="form-control" min="0" step="15">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Min Age</label>
                        <input type="number" name="min_age" id="min_age" class="form-control" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Max Group</label>
                        <input type="number" name="max_group_size" id="max_group_size" class="form-control" min="1">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Location</label>
                        <select name="location_id" id="location_id" class="form-control">
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['location_id']; ?>"><?php echo sanitize($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Meeting Point</label>
                        <input type="text" name="meeting_point" id="meeting_point" class="form-control">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Cancellation Policy</label>
                        <textarea name="cancellation_policy" id="cancellation_policy" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Included Items</label>
                        <div class="checkbox-grid" id="includedContainer">
                            <?php foreach ($commonIncludedItems as $key => $label): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="included_items[]" value="<?php echo $key; ?>">
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">What to Bring</label>
                        <div class="checkbox-grid" id="bringContainer">
                            <?php foreach ($commonBringItems as $key => $label): ?>
                            <label class="checkbox-item">
                                <input type="checkbox" name="what_to_bring[]" value="<?php echo $key; ?>">
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Start Times</label>
                        <div class="start-times-container" id="startTimesContainer">
                            <div class="time-row">
                                <input type="time" name="start_times[]" class="form-control" value="09:00">
                                <button type="button" class="time-remove" onclick="removeTimeRow(this)">
                                    <i class="bi bi-dash"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-outline btn-sm" onclick="addTimeRow()" style="margin-top: 8px;">
                            <i class="bi bi-plus"></i> Add Time
                        </button>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Main Image</label>
                        <div class="image-upload-area" onclick="document.getElementById('main_image').click()">
                            <i class="bi bi-cloud-upload"></i>
                            <p>Click to upload</p>
                            <input type="file" name="main_image" id="main_image" accept="image/*" style="display: none;" onchange="previewMainImage(this)">
                        </div>
                        <div id="mainImagePreview"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Gallery Images</label>
                        <div class="image-upload-area" onclick="document.getElementById('gallery_images').click()">
                            <i class="bi bi-images"></i>
                            <p>Click to upload multiple</p>
                            <input type="file" name="gallery_images[]" id="gallery_images" accept="image/*" multiple style="display: none;" onchange="previewGalleryImages(this)">
                        </div>
                        <div class="gallery-grid" id="galleryPreview"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-item">
                            <input type="checkbox" name="is_active" id="is_active" checked>
                            <span>Active (visible to customers)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('experienceModal')">Cancel</button>
                <button type="submit" name="save_experience" class="btn-primary">Save Experience</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Confirm Delete</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" style="text-align: center;">
            <i class="bi bi-exclamation-triangle" style="font-size: 2.5rem; color: #ef4444; margin-bottom: 12px;"></i>
            <p id="deleteMessage" style="font-size: 0.9375rem;">Are you sure you want to delete this experience?</p>
        </div>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="experience_id" id="delete_experience_id" value="0">
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" name="delete_experience" class="btn-primary" style="background: #ef4444;">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
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

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// EXPERIENCE FUNCTIONS
// ============================================
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New Experience';
    document.getElementById('experienceForm').reset();
    document.getElementById('experience_id').value = '0';
    document.getElementById('mainImagePreview').innerHTML = '';
    document.getElementById('galleryPreview').innerHTML = '';
    document.getElementById('is_active').checked = true;
    
    document.getElementById('startTimesContainer').innerHTML = `
        <div class="time-row">
            <input type="time" name="start_times[]" class="form-control" value="09:00">
            <button type="button" class="time-remove" onclick="removeTimeRow(this)">
                <i class="bi bi-dash"></i>
            </button>
        </div>
    `;
    
    openModal('experienceModal');
}

function editExperience(exp) {
    document.getElementById('modalTitle').textContent = 'Edit Experience';
    document.getElementById('experience_id').value = exp.attraction_id;
    document.getElementById('attraction_name').value = exp.attraction_name || '';
    document.getElementById('category_id').value = exp.category_id || '';
    document.getElementById('difficulty_level').value = exp.difficulty_level || '';
    document.getElementById('duration_minutes').value = exp.duration_minutes || '';
    document.getElementById('min_age').value = exp.min_age || 0;
    document.getElementById('max_group_size').value = exp.max_group_size || '';
    document.getElementById('location_id').value = exp.location_id || '';
    document.getElementById('meeting_point').value = exp.meeting_point || '';
    document.getElementById('description').value = exp.description || '';
    document.getElementById('cancellation_policy').value = exp.cancellation_policy || '';
    document.getElementById('is_active').checked = exp.is_active == 1;
    
    let included = [];
    let bring = [];
    let startTimes = ['09:00'];
    
    try {
        if (exp.included_items) included = JSON.parse(exp.included_items);
        if (exp.what_to_bring) bring = JSON.parse(exp.what_to_bring);
        if (exp.start_times && exp.start_times != '[]') startTimes = JSON.parse(exp.start_times);
    } catch(e) {}
    
    document.querySelectorAll('#includedContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = included.includes(cb.value);
    });
    
    document.querySelectorAll('#bringContainer input[type="checkbox"]').forEach(cb => {
        cb.checked = bring.includes(cb.value);
    });
    
    const timesContainer = document.getElementById('startTimesContainer');
    timesContainer.innerHTML = '';
    startTimes.forEach((time, index) => {
        const timeRow = document.createElement('div');
        timeRow.className = 'time-row';
        timeRow.innerHTML = `
            <input type="time" name="start_times[]" class="form-control" value="${time}">
            ${index > 0 ? '<button type="button" class="time-remove" onclick="removeTimeRow(this)"><i class="bi bi-dash"></i></button>' : ''}
        `;
        timesContainer.appendChild(timeRow);
    });
    
    // Show main image preview
    if (exp.main_image) {
        document.getElementById('mainImagePreview').innerHTML = `
            <img src="/gorwanda-plus/assets/images/attractions/${exp.main_image}" class="image-preview">
        `;
    }
    
    // Show gallery preview
    if (exp.gallery_images) {
        try {
            const gallery = JSON.parse(exp.gallery_images);
            const preview = document.getElementById('galleryPreview');
            preview.innerHTML = '';
            gallery.forEach(img => {
                const div = document.createElement('div');
                div.className = 'gallery-item';
                div.innerHTML = `
                    <img src="/gorwanda-plus/assets/images/attractions/gallery/${img}">
                    <button type="button" class="gallery-remove" onclick="deleteGalleryImage('${exp.attraction_id}', '${img}')">
                        <i class="bi bi-x"></i>
                    </button>
                `;
                preview.appendChild(div);
            });
        } catch(e) {}
    }
    
    openModal('experienceModal');
}

function deleteExperience(id, name) {
    document.getElementById('deleteMessage').innerHTML = `Delete <strong>"${name}"</strong>?`;
    document.getElementById('delete_experience_id').value = id;
    openModal('deleteModal');
}

function toggleStatus(id, currentStatus) {
    if (confirm(`Are you sure you want to ${currentStatus ? 'deactivate' : 'activate'} this experience?`)) {
        fetch('listings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=toggle_status&experience_id=' + id + '&current_status=' + currentStatus
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
    }
}

// ============================================
// IMAGE FUNCTIONS
// ============================================
function previewMainImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('mainImagePreview').innerHTML = `
                <img src="${e.target.result}" class="image-preview">
            `;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewGalleryImages(input) {
    const preview = document.getElementById('galleryPreview');
    if (input.files) {
        for (let i = 0; i < input.files.length; i++) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const div = document.createElement('div');
                div.className = 'gallery-item';
                div.innerHTML = `<img src="${e.target.result}">`;
                preview.appendChild(div);
            };
            reader.readAsDataURL(input.files[i]);
        }
    }
}

function deleteGalleryImage(expId, imageName) {
    if (confirm('Remove this image?')) {
        fetch('listings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=delete_gallery_image&experience_id=' + expId + '&image_name=' + imageName
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
    }
}

// ============================================
// START TIMES
// ============================================
function addTimeRow() {
    const container = document.getElementById('startTimesContainer');
    const timeRow = document.createElement('div');
    timeRow.className = 'time-row';
    timeRow.innerHTML = `
        <input type="time" name="start_times[]" class="form-control" value="09:00">
        <button type="button" class="time-remove" onclick="removeTimeRow(this)">
            <i class="bi bi-dash"></i>
        </button>
    `;
    container.appendChild(timeRow);
}

function removeTimeRow(btn) {
    btn.closest('.time-row').remove();
}

// ============================================
// FILTER
// ============================================
function filterExperiences() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const category = document.getElementById('categoryFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    const cards = document.querySelectorAll('.experience-card');
    let visible = 0;
    
    cards.forEach(card => {
        const name = card.dataset.name || '';
        const cardCat = card.dataset.category || '';
        const cardStatus = card.dataset.status || '';
        
        const matchSearch = name.includes(search);
        const matchCategory = category === 'all' || cardCat === category;
        const matchStatus = status === 'all' || cardStatus === status;
        
        if (matchSearch && matchCategory && matchStatus) {
            card.style.display = 'block';
            visible++;
        } else {
            card.style.display = 'none';
        }
    });
    
    let emptyMsg = document.getElementById('emptyResults');
    if (visible === 0 && cards.length > 0) {
        if (!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.id = 'emptyResults';
            emptyMsg.className = 'empty-state';
            emptyMsg.innerHTML = `
                <i class="bi bi-search"></i>
                <h3>No matches</h3>
                <p>Try different filters</p>
                <button class="btn-secondary" onclick="resetFilters()">Clear</button>
            `;
            document.getElementById('experiencesGrid').appendChild(emptyMsg);
        }
    } else if (emptyMsg) {
        emptyMsg.remove();
    }
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = 'all';
    document.getElementById('statusFilter').value = 'all';
    filterExperiences();
}
</script>

<?php require_once 'includes/experiences_footer.php'; ?>