<?php
$pageTitle = 'Restaurant Management';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;
$restaurantId = isset($_GET['restaurant']) ? intval($_GET['restaurant']) : 0;
$view = isset($_GET['view']) ? sanitize($_GET['view']) : 'restaurants';
$activeTab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'overview';

// Define upload directories
$restaurantUploadDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/restaurants/';
$menuUploadDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/menu/';
$restaurantUploadUrl = '/gorwanda-plus/assets/images/restaurants/';
$menuUploadUrl = '/gorwanda-plus/assets/images/menu/';

// ============================================
// HANDLE RESTAURANT ACTIONS
// ============================================

// Add/Edit Restaurant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_restaurant'])) {
    $restaurantId = intval($_POST['restaurant_id'] ?? 0);
    $stayId = intval($_POST['stay_id']);
    $restaurantName = sanitize($_POST['restaurant_name']);
    $cuisineType = sanitize($_POST['cuisine_type']);
    $description = sanitize($_POST['description']);
    $seatingCapacity = intval($_POST['seating_capacity'] ?? 0);
    $dressCode = sanitize($_POST['dress_code'] ?? '');
    $phoneExtension = sanitize($_POST['phone_extension'] ?? '');
    $hasOutdoorSeating = isset($_POST['has_outdoor_seating']) ? 1 : 0;
    $hasPrivateDining = isset($_POST['has_private_dining']) ? 1 : 0;
    $acceptsReservations = isset($_POST['accepts_reservations']) ? 1 : 0;
    
    // Opening hours as JSON
    $openingHours = json_encode([
        'breakfast' => $_POST['breakfast_hours'] ?? null,
        'lunch' => $_POST['lunch_hours'] ?? null,
        'dinner' => $_POST['dinner_hours'] ?? null,
        'continuous' => $_POST['continuous_hours'] ?? null
    ]);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this property";
    } else {
        if ($restaurantId > 0) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE restaurants SET
                    restaurant_name = ?, cuisine_type = ?, description = ?,
                    opening_hours = ?, dress_code = ?, seating_capacity = ?,
                    has_outdoor_seating = ?, has_private_dining = ?,
                    accepts_reservations = ?, phone_extension = ?
                WHERE restaurant_id = ? AND stay_id = ?
            ");
            $stmt->execute([
                $restaurantName, $cuisineType, $description, $openingHours,
                $dressCode, $seatingCapacity, $hasOutdoorSeating, $hasPrivateDining,
                $acceptsReservations, $phoneExtension, $restaurantId, $stayId
            ]);
            $success = "Restaurant updated successfully!";
        } else {
            // Insert new
            $stmt = $db->prepare("
                INSERT INTO restaurants (
                    stay_id, restaurant_name, cuisine_type, description,
                    opening_hours, dress_code, seating_capacity,
                    has_outdoor_seating, has_private_dining,
                    accepts_reservations, phone_extension, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $stayId, $restaurantName, $cuisineType, $description,
                $openingHours, $dressCode, $seatingCapacity,
                $hasOutdoorSeating, $hasPrivateDining,
                $acceptsReservations, $phoneExtension
            ]);
            $restaurantId = $db->lastInsertId();
            $success = "Restaurant added successfully!";
        }
    }
}

// ============================================
// IMAGE UPLOAD - Using your proven photos.php logic
// ============================================

// Upload Restaurant Image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_restaurant_image'])) {
    $restaurantId = intval($_POST['restaurant_id']);
    $isMain = isset($_POST['is_main']) ? 1 : 0;
    $caption = sanitize($_POST['caption'] ?? '');
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT r.restaurant_id FROM restaurants r
        JOIN stays s ON r.stay_id = s.stay_id
        WHERE r.restaurant_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$restaurantId, $userId]);
    
    if ($stmt->fetch() && isset($_FILES['restaurant_image']) && $_FILES['restaurant_image']['error'] === UPLOAD_ERR_OK) {
        // Create directory if not exists
        if (!file_exists($restaurantUploadDir)) {
            mkdir($restaurantUploadDir, 0777, true);
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['restaurant_image']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $error = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
        } else {
            $fileExt = strtolower(pathinfo($_FILES['restaurant_image']['name'], PATHINFO_EXTENSION));
            $fileName = 'rest_' . $restaurantId . '_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $restaurantUploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['restaurant_image']['tmp_name'], $uploadPath)) {
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
                
                // If this is set as main, unset previous main
                if ($isMain) {
                    $stmt = $db->prepare("
                        UPDATE restaurant_images SET is_main = 0 
                        WHERE restaurant_id = ?
                    ");
                    $stmt->execute([$restaurantId]);
                }
                
                // Insert image record
                $stmt = $db->prepare("
                    INSERT INTO restaurant_images (restaurant_id, image_path, caption, is_main)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$restaurantId, $fileName, $caption, $isMain]);
                $success = "Image uploaded successfully!";
            } else {
                $error = "Failed to upload image. Check directory permissions.";
            }
        }
    }
}

// Upload Menu Item Image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_menu_image'])) {
    $itemId = intval($_POST['item_id']);
    
    // Verify ownership
    $stmt = $db->prepare("
        SELECT mi.item_id FROM menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.category_id
        JOIN restaurants r ON mc.restaurant_id = r.restaurant_id
        JOIN stays s ON r.stay_id = s.stay_id
        WHERE mi.item_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$itemId, $userId]);
    
    if ($stmt->fetch() && isset($_FILES['menu_image']) && $_FILES['menu_image']['error'] === UPLOAD_ERR_OK) {
        // Create directory if not exists
        if (!file_exists($menuUploadDir)) {
            mkdir($menuUploadDir, 0777, true);
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['menu_image']['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            $error = "Invalid file type. Only JPG, PNG, and WEBP are allowed.";
        } else {
            $fileExt = strtolower(pathinfo($_FILES['menu_image']['name'], PATHINFO_EXTENSION));
            $fileName = 'menu_' . $itemId . '_' . time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $menuUploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['menu_image']['tmp_name'], $uploadPath)) {
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
                        imagepng($image, $uploadPath, 8);
                        imagedestroy($image);
                    }
                }
                
                // Update menu item with new image
                $stmt = $db->prepare("UPDATE menu_items SET image = ? WHERE item_id = ?");
                $stmt->execute([$fileName, $itemId]);
                
                $success = "Menu item image uploaded successfully!";
            } else {
                $error = "Failed to upload image. Check directory permissions.";
            }
        }
    }
}

// Delete Restaurant Image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_image'])) {
    $imageId = intval($_POST['image_id']);
    
    // Get image path and verify ownership
    $stmt = $db->prepare("
        SELECT ri.image_path FROM restaurant_images ri
        JOIN restaurants r ON ri.restaurant_id = r.restaurant_id
        JOIN stays s ON r.stay_id = s.stay_id
        WHERE ri.image_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$imageId, $userId]);
    $image = $stmt->fetch();
    
    if ($image) {
        // Delete file
        $filePath = $restaurantUploadDir . $image['image_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete record
        $stmt = $db->prepare("DELETE FROM restaurant_images WHERE image_id = ?");
        $stmt->execute([$imageId]);
        $success = "Image deleted successfully!";
    }
}

// ============================================
// MENU MANAGEMENT
// ============================================

// Add Menu Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $restaurantId = intval($_POST['restaurant_id']);
    $categoryName = sanitize($_POST['category_name']);
    $description = sanitize($_POST['category_description'] ?? '');
    $displayOrder = intval($_POST['display_order'] ?? 0);
    
    $stmt = $db->prepare("
        INSERT INTO menu_categories (restaurant_id, category_name, description, display_order, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    $stmt->execute([$restaurantId, $categoryName, $description, $displayOrder]);
    $success = "Menu category added successfully!";
}

// Add Menu Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_menu_item'])) {
    $categoryId = intval($_POST['category_id']);
    $itemName = sanitize($_POST['item_name']);
    $description = sanitize($_POST['item_description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $preparationTime = intval($_POST['preparation_time'] ?? 0);
    $calories = intval($_POST['calories'] ?? 0);
    $isSpicy = isset($_POST['is_spicy']) ? 1 : 0;
    $isVegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
    $isVegan = isset($_POST['is_vegan']) ? 1 : 0;
    $isGlutenFree = isset($_POST['is_gluten_free']) ? 1 : 0;
    $isSignature = isset($_POST['is_signature']) ? 1 : 0;
    $displayOrder = intval($_POST['display_order'] ?? 0);
    
    // Handle image upload
    $imageName = '';
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
        if (!file_exists($menuUploadDir)) {
            mkdir($menuUploadDir, 0777, true);
        }
        
        $fileExt = strtolower(pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION));
        $imageName = 'menu_' . time() . '_' . uniqid() . '.' . $fileExt;
        $uploadPath = $menuUploadDir . $imageName;
        move_uploaded_file($_FILES['item_image']['tmp_name'], $uploadPath);
    }
    
    $stmt = $db->prepare("
        INSERT INTO menu_items (
            category_id, item_name, description, price, preparation_time,
            calories, is_spicy, is_vegetarian, is_vegan, is_gluten_free,
            is_signature, image, display_order, is_available
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $categoryId, $itemName, $description, $price, $preparationTime,
        $calories, $isSpicy, $isVegetarian, $isVegan, $isGlutenFree,
        $isSignature, $imageName, $displayOrder
    ]);
    $itemId = $db->lastInsertId();
    
    // Handle options if any
    if (isset($_POST['options']) && is_array($_POST['options'])) {
        foreach ($_POST['options'] as $option) {
            if (!empty($option['name']) && !empty($option['values'])) {
                foreach ($option['values'] as $value) {
                    if (!empty($value['value'])) {
                        $stmt = $db->prepare("
                            INSERT INTO menu_item_options (
                                item_id, option_name, option_value,
                                price_adjustment, is_default, display_order
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $itemId,
                            $option['name'],
                            $value['value'],
                            floatval($value['price_adjustment'] ?? 0),
                            isset($value['is_default']) ? 1 : 0,
                            intval($value['display_order'] ?? 0)
                        ]);
                    }
                }
            }
        }
    }
    
    $success = "Menu item added successfully!";
}

// Toggle menu item availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_item'])) {
    $itemId = intval($_POST['item_id']);
    $currentStatus = intval($_POST['current_status']);
    $newStatus = $currentStatus ? 0 : 1;
    
    $stmt = $db->prepare("
        UPDATE menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.category_id
        JOIN restaurants r ON mc.restaurant_id = r.restaurant_id
        JOIN stays s ON r.stay_id = s.stay_id
        SET mi.is_available = ?
        WHERE mi.item_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$newStatus, $itemId, $userId]);
    
    $success = "Menu item availability updated!";
}

// Delete menu item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $itemId = intval($_POST['item_id']);
    
    // Get image to delete
    $stmt = $db->prepare("
        SELECT mi.image FROM menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.category_id
        JOIN restaurants r ON mc.restaurant_id = r.restaurant_id
        JOIN stays s ON r.stay_id = s.stay_id
        WHERE mi.item_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch();
    
    if ($item && $item['image']) {
        $filePath = $menuUploadDir . $item['image'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // Delete item (options will cascade)
    $stmt = $db->prepare("
        DELETE mi FROM menu_items mi
        USING menu_items mi
        JOIN menu_categories mc ON mi.category_id = mc.category_id
        JOIN restaurants r ON mc.restaurant_id = r.restaurant_id
        WHERE mi.item_id = ? AND r.restaurant_id IN (
            SELECT restaurant_id FROM restaurants r2
            JOIN stays s ON r2.stay_id = s.stay_id
            WHERE s.owner_id = ?
        )
    ");
    $stmt->execute([$itemId, $userId]);
    $success = "Menu item deleted successfully!";
}

// ============================================
// RESERVATION MANAGEMENT
// ============================================

// Update reservation status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reservation'])) {
    $reservationId = intval($_POST['reservation_id']);
    $newStatus = sanitize($_POST['status']);
    
    $stmt = $db->prepare("
        UPDATE table_reservations tr
        JOIN restaurants r ON tr.restaurant_id = r.restaurant_id
        JOIN stays s ON r.stay_id = s.stay_id
        SET tr.status = ?
        WHERE tr.reservation_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$newStatus, $reservationId, $userId]);
    $success = "Reservation status updated!";
}

// ============================================
// GET DATA
// ============================================

// Get all properties
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city 
    FROM stays 
    WHERE owner_id = ? 
    ORDER BY stay_name
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();

// If no property selected, use the first one
if ($propertyId === 0 && !empty($properties)) {
    $propertyId = $properties[0]['stay_id'];
}

// Get restaurants for selected property
$restaurants = [];
if ($propertyId > 0) {
    $stmt = $db->prepare("
        SELECT r.*, 
               (SELECT COUNT(*) FROM restaurant_images WHERE restaurant_id = r.restaurant_id) as image_count,
               (SELECT COUNT(*) FROM menu_categories WHERE restaurant_id = r.restaurant_id AND is_active = 1) as category_count
        FROM restaurants r 
        WHERE r.stay_id = ? 
        ORDER BY r.restaurant_name
    ");
    $stmt->execute([$propertyId]);
    $restaurants = $stmt->fetchAll();
    
    // If no restaurant selected, use the first one
    if ($restaurantId === 0 && !empty($restaurants)) {
        $restaurantId = $restaurants[0]['restaurant_id'];
    }
}

// Get selected restaurant details
$currentRestaurant = null;
$menuCategories = [];
$restaurantImages = [];
$recentReservations = [];
$menuStats = [
    'total_categories' => 0,
    'total_items' => 0,
    'available_items' => 0
];

if ($restaurantId > 0) {
    // Restaurant details
    $stmt = $db->prepare("
        SELECT r.*, s.stay_name, s.city
        FROM restaurants r
        JOIN stays s ON r.stay_id = s.stay_id
        WHERE r.restaurant_id = ? AND s.owner_id = ?
    ");
    $stmt->execute([$restaurantId, $userId]);
    $currentRestaurant = $stmt->fetch();
    
    // Get menu categories with items
    $stmt = $db->prepare("
        SELECT * FROM menu_categories 
        WHERE restaurant_id = ? 
        ORDER BY display_order
    ");
    $stmt->execute([$restaurantId]);
    $categories = $stmt->fetchAll();
    
    foreach ($categories as $category) {
        $stmt = $db->prepare("
            SELECT * FROM menu_items 
            WHERE category_id = ? 
            ORDER BY display_order, item_name
        ");
        $stmt->execute([$category['category_id']]);
        $items = $stmt->fetchAll();
        
        // Get options for each item
        foreach ($items as &$item) {
            $stmt = $db->prepare("
                SELECT * FROM menu_item_options 
                WHERE item_id = ? 
                ORDER BY display_order
            ");
            $stmt->execute([$item['item_id']]);
            $item['options'] = $stmt->fetchAll();
        }
        
        $category['items'] = $items;
        $menuCategories[] = $category;
        
        $menuStats['total_categories']++;
        $menuStats['total_items'] += count($items);
        foreach ($items as $item) {
            if ($item['is_available']) {
                $menuStats['available_items']++;
            }
        }
    }
    
    // Get restaurant images
    $stmt = $db->prepare("
        SELECT * FROM restaurant_images 
        WHERE restaurant_id = ? 
        ORDER BY is_main DESC, sort_order
    ");
    $stmt->execute([$restaurantId]);
    $restaurantImages = $stmt->fetchAll();
    
    // Get recent reservations
    $stmt = $db->prepare("
        SELECT tr.*, u.first_name, u.last_name, u.email
        FROM table_reservations tr
        LEFT JOIN users u ON tr.user_id = u.user_id
        WHERE tr.restaurant_id = ?
        ORDER BY tr.reservation_date DESC, tr.reservation_time DESC
        LIMIT 20
    ");
    $stmt->execute([$restaurantId]);
    $recentReservations = $stmt->fetchAll();
}

// Get reservation stats
$reservationStats = [
    'today' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0
];

$today = date('Y-m-d');
foreach ($recentReservations as $res) {
    if ($res['reservation_date'] == $today) {
        $reservationStats['today']++;
    }
    if ($res['status'] == 'pending') {
        $reservationStats['pending']++;
    } elseif ($res['status'] == 'confirmed') {
        $reservationStats['confirmed']++;
    } elseif ($res['status'] == 'completed') {
        $reservationStats['completed']++;
    } elseif ($res['status'] == 'cancelled') {
        $reservationStats['cancelled']++;
    }
}

// Cuisine types for dropdown
$cuisineTypes = [
    'Rwandan', 'International', 'Italian', 'Chinese', 'Indian',
    'French', 'Japanese', 'Mexican', 'Thai', 'African',
    'Mediterranean', 'American', 'Fusion', 'Seafood', 'Grill',
    'Vegetarian', 'Vegan', 'Buffet', 'Fine Dining', 'Casual Dining'
];

// Dress codes
$dressCodes = ['Casual', 'Smart Casual', 'Business Casual', 'Formal', 'Traditional'];
?>

<style>
/* Restaurant Management Styles */
:root {
    --primary: #003b95;
    --primary-light: #ebf3ff;
    --success: #008009;
    --warning: #ff8c00;
    --danger: #e21111;
    --gray-bg: #f5f5f5;
    --border: #e7e7e7;
}

.restaurant-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.restaurant-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.restaurant-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Property Selector */
.property-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.property-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--booking-text);
}

.property-selector select {
    min-width: 300px;
    padding: 10px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
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
    border: 1px solid var(--border);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* Restaurant Tabs */
.restaurant-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}

.restaurant-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.restaurant-tab:hover {
    color: var(--primary);
}

.restaurant-tab.active {
    color: var(--primary);
}

.restaurant-tab.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--primary);
}

/* Restaurant Cards */
.restaurant-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.restaurant-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    transition: all 0.2s;
}

.restaurant-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.restaurant-card-header {
    padding: 16px;
    background: linear-gradient(135deg, var(--primary-light), white);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.restaurant-card-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--primary);
}

.restaurant-card-badge {
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
    background: white;
    color: var(--success);
    border: 1px solid var(--success);
}

.restaurant-card-body {
    padding: 16px;
}

.restaurant-card-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}

.restaurant-card-info-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8125rem;
    color: var(--booking-text);
}

.restaurant-card-info-item i {
    color: var(--primary);
    width: 20px;
}

.restaurant-card-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.restaurant-card-btn {
    flex: 1;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}

.restaurant-card-btn:hover {
    background: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
}

/* Menu Section */
.menu-section {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
    padding: 24px;
    margin-bottom: 24px;
}

.menu-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.menu-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.category-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    overflow: hidden;
}

.category-header {
    padding: 16px 20px;
    background: var(--gray-bg);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}

.category-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--primary);
}

.category-items {
    padding: 16px;
}

.item-row {
    display: flex;
    align-items: center;
    padding: 12px;
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
    flex-wrap: wrap;
    gap: 10px;
}

.item-row:last-child {
    border-bottom: none;
}

.item-row:hover {
    background: var(--primary-light);
}

.item-image {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-sm);
    object-fit: cover;
}

.item-info {
    flex: 2;
    min-width: 200px;
}

.item-name {
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.item-badge {
    font-size: 0.625rem;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
}

.item-price {
    font-weight: 700;
    color: var(--success);
    font-size: 1.125rem;
    min-width: 100px;
}

.item-status {
    min-width: 100px;
    text-align: center;
}

.item-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    min-width: 120px;
}

/* Image Gallery */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
}

.gallery-item {
    position: relative;
    aspect-ratio: 1;
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--border);
}

.gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.gallery-item-main {
    position: absolute;
    top: 8px;
    left: 8px;
    background: var(--success);
    color: white;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.625rem;
    font-weight: 600;
}

.gallery-item-remove {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    opacity: 0;
}

.gallery-item:hover .gallery-item-remove {
    opacity: 1;
}

.gallery-item-remove:hover {
    background: var(--danger);
}

/* Upload Area */
.upload-area {
    border: 2px dashed var(--border);
    border-radius: var(--radius-md);
    padding: 30px;
    text-align: center;
    background: var(--gray-bg);
    cursor: pointer;
    transition: all 0.3s;
    margin-bottom: 20px;
}

.upload-area:hover {
    border-color: var(--primary);
    background: var(--primary-light);
}

.upload-icon {
    font-size: 2.5rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
}

/* Reservations Table */
.reservations-table {
    width: 100%;
    border-collapse: collapse;
}

.reservations-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--booking-text-light);
    text-transform: uppercase;
    background: var(--gray-bg);
    border-bottom: 1px solid var(--border);
}

.reservations-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 0.8125rem;
}

.reservations-table tr:hover td {
    background: var(--primary-light);
}

.reservation-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.status-pending { background: #fff4e6; color: var(--warning); }
.status-confirmed { background: #e6f4ea; color: var(--success); }
.status-completed { background: var(--gray-bg); color: var(--booking-text-light); }
.status-cancelled { background: #fce8e8; color: var(--danger); }

/* Modal Styles */
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
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
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
    color: var(--booking-text);
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--gray-bg);
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-close:hover {
    background: var(--danger);
    color: white;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--gray-bg);
}

/* Form Styles */
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
    margin-bottom: 6px;
    color: var(--booking-text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

/* Options Builder */
.options-builder {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 16px;
}

.option-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.option-row input {
    flex: 1;
    min-width: 150px;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid,
    .form-grid,
    .gallery-grid {
        grid-template-columns: 1fr;
    }
    
    .property-selector select {
        min-width: 100%;
    }
    
    .restaurant-tabs {
        flex-direction: column;
    }
    
    .restaurant-tab {
        width: 100%;
        text-align: left;
    }
    
    .item-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .item-actions {
        width: 100%;
        justify-content: flex-start;
    }
}
</style>

<div class="restaurant-header">
    <div class="restaurant-title">
        <h1><i class="bi bi-shop me-2" style="color: var(--primary);"></i>Restaurant Management</h1>
        <p>Manage your restaurants, menus, and table reservations</p>
    </div>
    <div>
        <button class="btn-primary" onclick="openAddRestaurantModal()">
            <i class="bi bi-plus-lg"></i> Add Restaurant
        </button>
    </div>
</div>

<!-- Property Selector -->
<div class="property-selector">
    <label for="propertySelect"><i class="bi bi-building"></i> Select Property:</label>
    <select id="propertySelect" onchange="changeProperty(this.value)">
        <option value="">Choose a property</option>
        <?php foreach ($properties as $prop): ?>
        <option value="<?php echo $prop['stay_id']; ?>" <?php echo $prop['stay_id'] == $propertyId ? 'selected' : ''; ?>>
            <?php echo sanitize($prop['stay_name']); ?> (<?php echo sanitize($prop['city'] ?? 'Rwanda'); ?>)
        </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($propertyId > 0): ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($restaurants); ?></div>
        <div class="stat-label">Restaurants</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $menuStats['total_items']; ?></div>
        <div class="stat-label">Menu Items</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $reservationStats['today']; ?></div>
        <div class="stat-label">Today's Reservations</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?php echo $reservationStats['pending']; ?></div>
        <div class="stat-label">Pending</div>
    </div>
</div>

<!-- Restaurant Tabs (if multiple restaurants) -->
<?php if (count($restaurants) > 1): ?>
<div class="restaurant-tabs">
    <?php foreach ($restaurants as $index => $rest): ?>
    <button class="restaurant-tab <?php echo $rest['restaurant_id'] == $restaurantId ? 'active' : ''; ?>" 
            onclick="switchRestaurant(<?php echo $rest['restaurant_id']; ?>)">
        <i class="bi bi-shop"></i>
        <?php echo sanitize($rest['restaurant_name']); ?>
        <?php if ($rest['image_count'] > 0): ?>
        <span class="badge" style="background: var(--primary); color: white;"><?php echo $rest['image_count']; ?></span>
        <?php endif; ?>
    </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- View Tabs -->
<div style="margin-bottom: 24px;">
    <div style="display: flex; gap: 8px; border-bottom: 1px solid var(--border); padding-bottom: 0;">
        <button class="restaurant-tab <?php echo $view == 'restaurants' ? 'active' : ''; ?>" onclick="switchView('restaurants')">
            <i class="bi bi-shop"></i> Restaurant Info
        </button>
        <button class="restaurant-tab <?php echo $view == 'menu' ? 'active' : ''; ?>" onclick="switchView('menu')">
            <i class="bi bi-menu-up"></i> Menu Management
        </button>
        <button class="restaurant-tab <?php echo $view == 'gallery' ? 'active' : ''; ?>" onclick="switchView('gallery')">
            <i class="bi bi-images"></i> Photo Gallery (<?php echo count($restaurantImages); ?>)
        </button>
        <button class="restaurant-tab <?php echo $view == 'reservations' ? 'active' : ''; ?>" onclick="switchView('reservations')">
            <i class="bi bi-calendar-check"></i> Reservations
            <?php if ($reservationStats['pending'] > 0): ?>
            <span class="badge" style="background: var(--warning); color: white;"><?php echo $reservationStats['pending']; ?></span>
        <?php endif; ?>
        </button>
    </div>
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

<!-- VIEW: RESTAURANTS -->
<div id="view-restaurants" style="display: <?php echo $view == 'restaurants' ? 'block' : 'none'; ?>;">
    <?php if (empty($restaurants)): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--border);">
        <i class="bi bi-shop" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px;">No restaurants yet</h3>
        <p style="color: var(--booking-text-light);">Add your first restaurant to this property.</p>
        <button class="btn-primary" onclick="openAddRestaurantModal()" style="margin-top: 16px;">
            <i class="bi bi-plus-lg"></i> Add Restaurant
        </button>
    </div>
    <?php else: ?>
    <div class="restaurant-grid">
        <?php foreach ($restaurants as $rest): 
            $hours = json_decode($rest['opening_hours'] ?? '{}', true);
        ?>
        <div class="restaurant-card">
            <div class="restaurant-card-header">
                <span class="restaurant-card-name"><?php echo sanitize($rest['restaurant_name']); ?></span>
                <span class="restaurant-card-badge"><?php echo $rest['is_active'] ? 'Active' : 'Inactive'; ?></span>
            </div>
            <div class="restaurant-card-body">
                <div class="restaurant-card-info">
                    <div class="restaurant-card-info-item">
                        <i class="bi bi-shop"></i>
                        <span><?php echo sanitize($rest['cuisine_type']); ?></span>
                    </div>
                    <div class="restaurant-card-info-item">
                        <i class="bi bi-people"></i>
                        <span><?php echo $rest['seating_capacity'] ?: 'N/A'; ?> seats</span>
                    </div>
                    <div class="restaurant-card-info-item">
                        <i class="bi bi-door-open"></i>
                        <span><?php echo $rest['has_outdoor_seating'] ? 'Outdoor' : 'Indoor only'; ?></span>
                    </div>
                    <div class="restaurant-card-info-item">
                        <i class="bi bi-person-badge"></i>
                        <span><?php echo $rest['dress_code'] ?? 'Casual'; ?></span>
                    </div>
                </div>
                
                <div class="restaurant-card-actions">
                    <button class="restaurant-card-btn" onclick="editRestaurant(<?php echo htmlspecialchars(json_encode($rest)); ?>)">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="restaurant-card-btn" onclick="switchRestaurantAndView(<?php echo $rest['restaurant_id']; ?>, 'menu')">
                        <i class="bi bi-menu-up"></i> Menu
                    </button>
                    <button class="restaurant-card-btn" onclick="switchRestaurantAndView(<?php echo $rest['restaurant_id']; ?>, 'gallery')">
                        <i class="bi bi-images"></i> Photos
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- VIEW: MENU MANAGEMENT -->
<div id="view-menu" style="display: <?php echo $view == 'menu' && $currentRestaurant ? 'block' : 'none'; ?>;">
    <?php if (!$currentRestaurant): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--border);">
        <i class="bi bi-shop" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px;">Select a restaurant</h3>
        <p style="color: var(--booking-text-light);">Please select a restaurant from the tabs above to manage its menu.</p>
    </div>
    <?php else: ?>
    
    <div class="menu-section">
        <div class="menu-header">
            <h3 class="menu-title"><i class="bi bi-menu-up"></i> Menu for <?php echo sanitize($currentRestaurant['restaurant_name']); ?></h3>
            <button class="btn-primary" onclick="openAddCategoryModal(<?php echo $restaurantId; ?>)">
                <i class="bi bi-plus-lg"></i> Add Category
            </button>
        </div>
        
        <?php if (empty($menuCategories)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-menu-up" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 16px;">No menu categories yet. Create your first category.</p>
        </div>
        <?php else: ?>
            <?php foreach ($menuCategories as $category): ?>
            <div class="category-card">
                <div class="category-header" onclick="toggleCategory(<?php echo $category['category_id']; ?>)">
                    <span class="category-name"><?php echo sanitize($category['category_name']); ?></span>
                    <div>
                        <span style="font-size: 0.75rem; color: var(--booking-text-light); margin-right: 16px;">
                            <?php echo count($category['items']); ?> items
                        </span>
                        <button class="btn-outline btn-sm" onclick="event.stopPropagation(); openAddItemModal(<?php echo $category['category_id']; ?>)">
                            <i class="bi bi-plus-lg"></i> Add Item
                        </button>
                    </div>
                </div>
                
                <div class="category-items" id="category-<?php echo $category['category_id']; ?>" style="display: none;">
                    <?php if (empty($category['items'])): ?>
                    <p style="text-align: center; padding: 20px; color: var(--booking-text-light);">No items in this category</p>
                    <?php else: ?>
                        <?php foreach ($category['items'] as $item): ?>
                        <div class="item-row">
                            <?php if ($item['image']): ?>
                            <img src="<?php echo $menuUploadUrl . $item['image']; ?>" class="item-image">
                            <?php endif; ?>
                            
                            <div class="item-info">
                                <div class="item-name">
                                    <?php echo sanitize($item['item_name']); ?>
                                    <?php if ($item['is_signature']): ?>
                                    <span class="item-badge" style="background: var(--primary-light); color: var(--primary);">Signature</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_spicy']): ?>
                                    <span class="item-badge" style="background: #fee2e2; color: #dc2626;">🌶️ Spicy</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_vegetarian']): ?>
                                    <span class="item-badge" style="background: #e6f4ea; color: var(--success);">Vegetarian</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_vegan']): ?>
                                    <span class="item-badge" style="background: #d1fae5; color: #065f46;">Vegan</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_gluten_free']): ?>
                                    <span class="item-badge" style="background: #fef3c7; color: #92400e;">Gluten Free</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--booking-text-light);">
                                    <?php echo substr(sanitize($item['description']), 0, 100); ?>
                                </div>
                                <?php if (!empty($item['options'])): ?>
                                <div style="font-size: 0.7rem; color: var(--booking-text-light); margin-top: 4px;">
                                    <i class="bi bi-gear"></i> Customizable
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="item-price"><?php echo formatPrice($item['price']); ?></div>
                            
                            <div class="item-status">
                                <span class="status-badge <?php echo $item['is_available'] ? 'status-confirmed' : 'status-cancelled'; ?>" style="font-size: 0.625rem;">
                                    <?php echo $item['is_available'] ? 'Available' : 'Unavailable'; ?>
                                </span>
                            </div>
                            
                            <div class="item-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $item['is_available']; ?>">
                                    <button type="submit" name="toggle_item" class="btn-outline btn-sm" title="Toggle availability">
                                        <i class="bi bi-<?php echo $item['is_available'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                                    </button>
                                </form>
                                <button class="btn-outline btn-sm" onclick="uploadMenuItemImage(<?php echo $item['item_id']; ?>)">
                                    <i class="bi bi-image"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this menu item?')">
                                    <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                    <button type="submit" name="delete_item" class="btn-outline btn-sm" style="color: var(--danger);">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- VIEW: GALLERY -->
<div id="view-gallery" style="display: <?php echo $view == 'gallery' && $currentRestaurant ? 'block' : 'none'; ?>;">
    <?php if (!$currentRestaurant): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--border);">
        <i class="bi bi-shop" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px;">Select a restaurant</h3>
        <p style="color: var(--booking-text-light);">Please select a restaurant from the tabs above to manage its photos.</p>
    </div>
    <?php else: ?>
    
    <div class="menu-section">
        <div class="menu-header">
            <h3 class="menu-title"><i class="bi bi-images"></i> Photo Gallery - <?php echo sanitize($currentRestaurant['restaurant_name']); ?></h3>
            <button class="btn-primary" onclick="openUploadImageModal(<?php echo $restaurantId; ?>)">
                <i class="bi bi-cloud-upload"></i> Upload Image
            </button>
        </div>
        
        <!-- Upload Area -->
        <div class="upload-area" onclick="document.getElementById('restaurantImageInput').click()">
            <form method="POST" enctype="multipart/form-data" style="display: none;">
                <input type="hidden" name="restaurant_id" value="<?php echo $restaurantId; ?>">
                <input type="file" name="restaurant_image" id="restaurantImageInput" accept="image/*" onchange="this.form.submit()">
                <input type="hidden" name="upload_restaurant_image" value="1">
            </form>
            <div class="upload-icon"><i class="bi bi-cloud-arrow-up"></i></div>
            <h4>Click to upload image</h4>
            <p style="color: var(--booking-text-light);">JPG, PNG, GIF, WEBP (max 10MB)</p>
        </div>
        
        <?php if (empty($restaurantImages)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-images" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 16px;">No images yet. Upload photos of your restaurant.</p>
        </div>
        <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($restaurantImages as $image): ?>
            <div class="gallery-item">
                <img src="<?php echo $restaurantUploadUrl . $image['image_path']; ?>" 
                     alt="<?php echo sanitize($image['caption']); ?>">
                <?php if ($image['is_main']): ?>
                <span class="gallery-item-main">Main</span>
                <?php endif; ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="image_id" value="<?php echo $image['image_id']; ?>">
                    <button type="submit" name="delete_image" class="gallery-item-remove" onclick="return confirm('Delete this image?')">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- VIEW: RESERVATIONS -->
<div id="view-reservations" style="display: <?php echo $view == 'reservations' && $currentRestaurant ? 'block' : 'none'; ?>;">
    <?php if (!$currentRestaurant): ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--border);">
        <i class="bi bi-shop" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <h3 style="margin-top: 16px;">Select a restaurant</h3>
        <p style="color: var(--booking-text-light);">Please select a restaurant from the tabs above to view reservations.</p>
    </div>
    <?php else: ?>
    
    <div class="menu-section">
        <div class="menu-header">
            <h3 class="menu-title"><i class="bi bi-calendar-check"></i> Table Reservations - <?php echo sanitize($currentRestaurant['restaurant_name']); ?></h3>
        </div>
        
        <?php if (empty($recentReservations)): ?>
        <div style="text-align: center; padding: 40px;">
            <i class="bi bi-calendar-check" style="font-size: 2rem; color: var(--booking-text-lighter);"></i>
            <p style="margin-top: 16px;">No reservations yet.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="reservations-table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Guest</th>
                        <th>Guests</th>
                        <th>Status</th>
                        <th>Requests</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentReservations as $res): ?>
                    <tr>
                        <td>
                            <div><?php echo date('M d, Y', strtotime($res['reservation_date'])); ?></div>
                            <div style="font-size: 0.7rem; color: var(--booking-text-light);">
                                <?php echo date('h:i A', strtotime($res['reservation_time'])); ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-weight: 600;"><?php echo sanitize($res['first_name'] . ' ' . $res['last_name']); ?></div>
                            <div style="font-size: 0.7rem; color: var(--booking-text-light);"><?php echo sanitize($res['email']); ?></div>
                        </td>
                        <td><?php echo $res['guest_count']; ?></td>
                        <td>
                            <span class="reservation-status status-<?php echo $res['status']; ?>">
                                <?php echo ucfirst($res['status']); ?>
                            </span>
                        </td>
                        <td style="max-width: 200px;">
                            <?php echo substr(sanitize($res['special_requests']), 0, 50); ?>
                        </td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="reservation_id" value="<?php echo $res['reservation_id']; ?>">
                                <select name="status" onchange="this.form.submit()" style="font-size: 0.75rem; padding: 4px;">
                                    <option value="pending" <?php echo $res['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $res['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirm</option>
                                    <option value="completed" <?php echo $res['status'] == 'completed' ? 'selected' : ''; ?>>Complete</option>
                                    <option value="cancelled" <?php echo $res['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancel</option>
                                </select>
                                <input type="hidden" name="update_reservation" value="1">
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px; background: white; border-radius: var(--radius-md); border: 1px solid var(--border);">
    <i class="bi bi-building" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
    <h3 style="margin-top: 16px;">Select a property</h3>
    <p style="color: var(--booking-text-light);">Please select a property from the dropdown above to manage its restaurants.</p>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Add/Edit Restaurant Modal -->
<div class="modal" id="restaurantModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="restaurantModalTitle">Add Restaurant</h3>
            <button class="modal-close" onclick="closeModal('restaurantModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="restaurant_id" id="restaurant_id" value="0">
                <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Restaurant Name</label>
                        <input type="text" name="restaurant_name" id="restaurant_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cuisine Type</label>
                        <select name="cuisine_type" id="cuisine_type" class="form-control" required>
                            <option value="">Select Cuisine</option>
                            <?php foreach ($cuisineTypes as $type): ?>
                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Seating Capacity</label>
                        <input type="number" name="seating_capacity" id="seating_capacity" class="form-control" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Dress Code</label>
                        <select name="dress_code" id="dress_code" class="form-control">
                            <option value="">Select Dress Code</option>
                            <?php foreach ($dressCodes as $code): ?>
                            <option value="<?php echo $code; ?>"><?php echo $code; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone Extension</label>
                        <input type="text" name="phone_extension" id="phone_extension" class="form-control" placeholder="e.g., 123">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Opening Hours</label>
                        <div style="display: grid; gap: 10px;">
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <span style="min-width: 80px;">Breakfast:</span>
                                <input type="text" name="breakfast_hours" id="breakfast_hours" class="form-control" placeholder="e.g., 06:30-10:30">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <span style="min-width: 80px;">Lunch:</span>
                                <input type="text" name="lunch_hours" id="lunch_hours" class="form-control" placeholder="e.g., 12:00-15:00">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <span style="min-width: 80px;">Dinner:</span>
                                <input type="text" name="dinner_hours" id="dinner_hours" class="form-control" placeholder="e.g., 18:00-22:00">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="has_outdoor_seating" id="has_outdoor_seating" value="1">
                            <label for="has_outdoor_seating">Outdoor Seating</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="has_private_dining" id="has_private_dining" value="1">
                            <label for="has_private_dining">Private Dining</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="accepts_reservations" id="accepts_reservations" value="1" checked>
                            <label for="accepts_reservations">Accepts Reservations</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('restaurantModal')">Cancel</button>
                <button type="submit" name="save_restaurant" class="btn-primary">Save Restaurant</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal" id="categoryModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Add Menu Category</h3>
            <button class="modal-close" onclick="closeModal('categoryModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="restaurant_id" id="category_restaurant_id" value="<?php echo $restaurantId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="category_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="category_description" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Display Order</label>
                    <input type="number" name="display_order" class="form-control" value="0" min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('categoryModal')">Cancel</button>
                <button type="submit" name="add_category" class="btn-primary">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Menu Item Modal -->
<div class="modal" id="itemModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Menu Item</h3>
            <button class="modal-close" onclick="closeModal('itemModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="itemForm">
            <div class="modal-body">
                <input type="hidden" name="category_id" id="item_category_id" value="">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Item Name</label>
                        <input type="text" name="item_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="item_description" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Price (RWF)</label>
                        <input type="number" name="price" class="form-control" min="0" step="100" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prep Time (min)</label>
                        <input type="number" name="preparation_time" class="form-control" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Calories</label>
                        <input type="number" name="calories" class="form-control" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Display Order</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Item Image</label>
                        <input type="file" name="item_image" accept="image/*" class="form-control">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Dietary Options</label>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_spicy" id="is_spicy" value="1">
                                <label for="is_spicy">🌶️ Spicy</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_vegetarian" id="is_vegetarian" value="1">
                                <label for="is_vegetarian">🥬 Vegetarian</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_vegan" id="is_vegan" value="1">
                                <label for="is_vegan">🌱 Vegan</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_gluten_free" id="is_gluten_free" value="1">
                                <label for="is_gluten_free">🌾 Gluten Free</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" name="is_signature" id="is_signature" value="1">
                                <label for="is_signature">⭐ Signature</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Options (e.g., Size, Add-ons)</label>
                        <div id="options-container">
                            <!-- Options will be added here dynamically -->
                        </div>
                        <button type="button" class="btn-outline btn-sm" onclick="addOptionGroup()">
                            <i class="bi bi-plus-lg"></i> Add Option Group
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('itemModal')">Cancel</button>
                <button type="submit" name="add_menu_item" class="btn-primary">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Image Modal -->
<div class="modal" id="uploadModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Upload Restaurant Image</h3>
            <button class="modal-close" onclick="closeModal('uploadModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="restaurant_id" value="<?php echo $restaurantId; ?>">
                
                <div class="form-group">
                    <label class="form-label">Select Image</label>
                    <input type="file" name="restaurant_image" accept="image/*" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Caption</label>
                    <input type="text" name="caption" class="form-control" placeholder="e.g., Main dining area">
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="is_main" id="is_main" value="1">
                    <label for="is_main">Set as main image</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
                <button type="submit" name="upload_restaurant_image" class="btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Menu Item Image Upload Modal -->
<div class="modal" id="menuImageModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Upload Menu Item Image</h3>
            <button class="modal-close" onclick="closeModal('menuImageModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="item_id" id="menu_image_item_id" value="">
                
                <div class="form-group">
                    <label class="form-label">Select Image</label>
                    <input type="file" name="menu_image" accept="image/*" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('menuImageModal')">Cancel</button>
                <button type="submit" name="upload_menu_image" class="btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// VIEW SWITCHING
// ============================================
function switchView(view) {
    window.location.href = 'restaurant-management.php?property=<?php echo $propertyId; ?>&restaurant=<?php echo $restaurantId; ?>&view=' + view;
}

function switchRestaurant(restaurantId) {
    window.location.href = 'restaurant-management.php?property=<?php echo $propertyId; ?>&restaurant=' + restaurantId + '&view=<?php echo $view; ?>';
}

function switchRestaurantAndView(restaurantId, view) {
    window.location.href = 'restaurant-management.php?property=<?php echo $propertyId; ?>&restaurant=' + restaurantId + '&view=' + view;
}

function changeProperty(propertyId) {
    if (propertyId) {
        window.location.href = 'restaurant-management.php?property=' + propertyId;
    } else {
        window.location.href = 'restaurant-management.php';
    }
}

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

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// RESTAURANT FUNCTIONS
// ============================================
function openAddRestaurantModal() {
    document.getElementById('restaurantModalTitle').textContent = 'Add Restaurant';
    document.getElementById('restaurant_id').value = '0';
    document.getElementById('restaurant_name').value = '';
    document.getElementById('cuisine_type').value = '';
    document.getElementById('seating_capacity').value = '';
    document.getElementById('dress_code').value = '';
    document.getElementById('phone_extension').value = '';
    document.getElementById('description').value = '';
    document.getElementById('breakfast_hours').value = '';
    document.getElementById('lunch_hours').value = '';
    document.getElementById('dinner_hours').value = '';
    document.getElementById('has_outdoor_seating').checked = false;
    document.getElementById('has_private_dining').checked = false;
    document.getElementById('accepts_reservations').checked = true;
    openModal('restaurantModal');
}

function editRestaurant(restaurant) {
    document.getElementById('restaurantModalTitle').textContent = 'Edit Restaurant';
    document.getElementById('restaurant_id').value = restaurant.restaurant_id;
    document.getElementById('restaurant_name').value = restaurant.restaurant_name;
    document.getElementById('cuisine_type').value = restaurant.cuisine_type;
    document.getElementById('seating_capacity').value = restaurant.seating_capacity;
    document.getElementById('dress_code').value = restaurant.dress_code || '';
    document.getElementById('phone_extension').value = restaurant.phone_extension || '';
    document.getElementById('description').value = restaurant.description || '';
    
    // Parse opening hours
    if (restaurant.opening_hours) {
        try {
            const hours = JSON.parse(restaurant.opening_hours);
            document.getElementById('breakfast_hours').value = hours.breakfast || '';
            document.getElementById('lunch_hours').value = hours.lunch || '';
            document.getElementById('dinner_hours').value = hours.dinner || '';
        } catch(e) {
            console.log('Error parsing hours');
        }
    }
    
    document.getElementById('has_outdoor_seating').checked = restaurant.has_outdoor_seating == 1;
    document.getElementById('has_private_dining').checked = restaurant.has_private_dining == 1;
    document.getElementById('accepts_reservations').checked = restaurant.accepts_reservations == 1;
    openModal('restaurantModal');
}

// ============================================
// CATEGORY FUNCTIONS
// ============================================
function openAddCategoryModal(restaurantId) {
    document.getElementById('category_restaurant_id').value = restaurantId;
    openModal('categoryModal');
}

function toggleCategory(categoryId) {
    const items = document.getElementById(`category-${categoryId}`);
    if (items.style.display === 'none' || items.style.display === '') {
        items.style.display = 'block';
    } else {
        items.style.display = 'none';
    }
}

// ============================================
// MENU ITEM FUNCTIONS
// ============================================
let optionGroupCount = 0;

function openAddItemModal(categoryId) {
    document.getElementById('item_category_id').value = categoryId;
    document.getElementById('itemForm').reset();
    document.getElementById('options-container').innerHTML = '';
    optionGroupCount = 0;
    openModal('itemModal');
}

function uploadMenuItemImage(itemId) {
    document.getElementById('menu_image_item_id').value = itemId;
    openModal('menuImageModal');
}

function addOptionGroup() {
    const container = document.getElementById('options-container');
    const groupDiv = document.createElement('div');
    groupDiv.className = 'options-builder';
    groupDiv.innerHTML = `
        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
            <input type="text" name="options[${optionGroupCount}][name]" placeholder="Option name (e.g., Size)" class="form-control" style="width: 70%;">
            <button type="button" class="btn-outline btn-sm" onclick="this.closest('.options-builder').remove()">
                <i class="bi bi-trash"></i> Remove
            </button>
        </div>
        <div id="option-values-${optionGroupCount}">
            <div class="option-row">
                <input type="text" name="options[${optionGroupCount}][values][0][value]" placeholder="Value (e.g., Large)" class="form-control">
                <input type="number" name="options[${optionGroupCount}][values][0][price_adjustment]" placeholder="Price adj." class="form-control" style="width: 100px;" step="100">
                <label style="display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" name="options[${optionGroupCount}][values][0][is_default]" value="1"> Default
                </label>
            </div>
        </div>
        <button type="button" class="btn-outline btn-sm" onclick="addOptionValue(${optionGroupCount})">
            <i class="bi bi-plus-lg"></i> Add Value
        </button>
    `;
    container.appendChild(groupDiv);
    optionGroupCount++;
}

function addOptionValue(groupIndex) {
    const container = document.getElementById(`option-values-${groupIndex}`);
    const valueCount = container.children.length;
    const valueDiv = document.createElement('div');
    valueDiv.className = 'option-row';
    valueDiv.innerHTML = `
        <input type="text" name="options[${groupIndex}][values][${valueCount}][value]" placeholder="Value" class="form-control">
        <input type="number" name="options[${groupIndex}][values][${valueCount}][price_adjustment]" placeholder="Price adj." class="form-control" style="width: 100px;" step="100">
        <label style="display: flex; align-items: center; gap: 4px;">
            <input type="checkbox" name="options[${groupIndex}][values][${valueCount}][is_default]" value="1"> Default
        </label>
        <button type="button" class="btn-outline btn-sm" onclick="this.parentElement.remove()">
            <i class="bi bi-x"></i>
        </button>
    `;
    container.appendChild(valueDiv);
}

// ============================================
// GALLERY FUNCTIONS
// ============================================
function openUploadImageModal(restaurantId) {
    openModal('uploadModal');
}

// Initialize category toggles
document.addEventListener('DOMContentLoaded', function() {
    // Set first category to visible by default
    const firstCategory = document.querySelector('.category-items');
    if (firstCategory) {
        firstCategory.style.display = 'block';
    }
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>