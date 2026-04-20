<?php
$restaurantId = isset($_GET['restaurant_id']) ? intval($_GET['restaurant_id']) : 0;

if (!$restaurantId) {
    header('Location: restaurants.php');
    exit;
}

$pageTitle = 'Menu Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Get restaurant details
$stmt = $db->prepare("SELECT restaurant_name, stay_id FROM restaurants WHERE restaurant_id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    header('Location: restaurants.php');
    exit;
}

// Handle actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : (isset($_GET['category_id']) ? intval($_GET['category_id']) : 0);
$itemId = isset($_POST['item_id']) ? intval($_POST['item_id']) : (isset($_GET['item_id']) ? intval($_GET['item_id']) : 0);

// ============================================
// CATEGORY MANAGEMENT
// ============================================

// Add Category
if ($action === 'add_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name = sanitize($_POST['category_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($category_name)) {
        $_SESSION['error'] = "Category name is required";
    } else {
        $stmt = $db->prepare("
            INSERT INTO menu_categories (restaurant_id, category_name, description, display_order, is_active)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$restaurantId, $category_name, $description, $display_order, $is_active]);
        $_SESSION['success'] = "Category added successfully";
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Edit Category
if ($action === 'edit_category' && $_SERVER['REQUEST_METHOD'] === 'POST' && $categoryId > 0) {
    $category_name = sanitize($_POST['category_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($category_name)) {
        $_SESSION['error'] = "Category name is required";
    } else {
        $stmt = $db->prepare("
            UPDATE menu_categories SET
                category_name = ?,
                description = ?,
                display_order = ?,
                is_active = ?
            WHERE category_id = ? AND restaurant_id = ?
        ");
        $stmt->execute([$category_name, $description, $display_order, $is_active, $categoryId, $restaurantId]);
        $_SESSION['success'] = "Category updated successfully";
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Delete Category
if ($action === 'delete_category' && $categoryId > 0) {
    // Check if category has items
    $stmt = $db->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    $itemCount = $stmt->fetchColumn();
    
    if ($itemCount > 0) {
        $_SESSION['error'] = "Cannot delete category with existing menu items. Move or delete items first.";
    } else {
        $stmt = $db->prepare("DELETE FROM menu_categories WHERE category_id = ? AND restaurant_id = ?");
        $stmt->execute([$categoryId, $restaurantId]);
        $_SESSION['success'] = "Category deleted successfully";
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// ============================================
// MENU ITEM MANAGEMENT
// ============================================

// Add Menu Item
if ($action === 'add_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id = intval($_POST['category_id'] ?? 0);
    $item_name = sanitize($_POST['item_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $currency = sanitize($_POST['currency'] ?? 'RWF');
    $is_spicy = isset($_POST['is_spicy']) ? 1 : 0;
    $is_vegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
    $is_vegan = isset($_POST['is_vegan']) ? 1 : 0;
    $is_gluten_free = isset($_POST['is_gluten_free']) ? 1 : 0;
    $is_signature = isset($_POST['is_signature']) ? 1 : 0;
    $preparation_time = !empty($_POST['preparation_time']) ? intval($_POST['preparation_time']) : null;
    $calories = !empty($_POST['calories']) ? intval($_POST['calories']) : null;
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    
    $errors = [];
    if ($category_id <= 0) $errors[] = "Category selection is required";
    if (empty($item_name)) $errors[] = "Item name is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    // Handle image upload
    $image = null;
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/menu/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION);
        $filename = 'menu_' . time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['item_image']['tmp_name'], $upload_path)) {
            $image = $filename;
        }
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO menu_items (
                category_id, item_name, description, price, currency, is_spicy, is_vegetarian,
                is_vegan, is_gluten_free, is_signature, preparation_time, calories, image, 
                display_order, is_available
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $category_id, $item_name, $description, $price, $currency, $is_spicy, $is_vegetarian,
            $is_vegan, $is_gluten_free, $is_signature, $preparation_time, $calories, $image,
            $display_order, $is_available
        ]);
        $_SESSION['success'] = "Menu item added successfully";
    } else {
        $_SESSION['error'] = implode(", ", $errors);
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Edit Menu Item
if ($action === 'edit_item' && $_SERVER['REQUEST_METHOD'] === 'POST' && $itemId > 0) {
    $category_id = intval($_POST['category_id'] ?? 0);
    $item_name = sanitize($_POST['item_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $currency = sanitize($_POST['currency'] ?? 'RWF');
    $is_spicy = isset($_POST['is_spicy']) ? 1 : 0;
    $is_vegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
    $is_vegan = isset($_POST['is_vegan']) ? 1 : 0;
    $is_gluten_free = isset($_POST['is_gluten_free']) ? 1 : 0;
    $is_signature = isset($_POST['is_signature']) ? 1 : 0;
    $preparation_time = !empty($_POST['preparation_time']) ? intval($_POST['preparation_time']) : null;
    $calories = !empty($_POST['calories']) ? intval($_POST['calories']) : null;
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    
    // Get existing image
    $stmt = $db->prepare("SELECT image FROM menu_items WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $existing = $stmt->fetch();
    $image = $existing['image'];
    
    // Handle new image upload
    if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/menu/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Delete old image
        if ($image && file_exists($upload_dir . $image)) {
            unlink($upload_dir . $image);
        }
        
        $file_extension = pathinfo($_FILES['item_image']['name'], PATHINFO_EXTENSION);
        $filename = 'menu_' . time() . '_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        if (move_uploaded_file($_FILES['item_image']['tmp_name'], $upload_path)) {
            $image = $filename;
        }
    }
    
    // Handle image deletion
    if (isset($_POST['delete_image']) && $_POST['delete_image'] == '1') {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/menu/';
        if ($image && file_exists($upload_dir . $image)) {
            unlink($upload_dir . $image);
        }
        $image = null;
    }
    
    $errors = [];
    if ($category_id <= 0) $errors[] = "Category selection is required";
    if (empty($item_name)) $errors[] = "Item name is required";
    if ($price <= 0) $errors[] = "Price must be greater than 0";
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE menu_items SET
                category_id = ?,
                item_name = ?,
                description = ?,
                price = ?,
                currency = ?,
                is_spicy = ?,
                is_vegetarian = ?,
                is_vegan = ?,
                is_gluten_free = ?,
                is_signature = ?,
                preparation_time = ?,
                calories = ?,
                image = ?,
                display_order = ?,
                is_available = ?
            WHERE item_id = ?
        ");
        $stmt->execute([
            $category_id, $item_name, $description, $price, $currency, $is_spicy, $is_vegetarian,
            $is_vegan, $is_gluten_free, $is_signature, $preparation_time, $calories, $image,
            $display_order, $is_available, $itemId
        ]);
        $_SESSION['success'] = "Menu item updated successfully";
    } else {
        $_SESSION['error'] = implode(", ", $errors);
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Delete Menu Item
if ($action === 'delete_item' && $itemId > 0) {
    // Check if item has options
    $stmt = $db->prepare("SELECT COUNT(*) FROM menu_item_options WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $optionCount = $stmt->fetchColumn();
    
    if ($optionCount > 0) {
        $_SESSION['error'] = "Cannot delete item with existing options. Delete options first.";
    } else {
        // Delete image
        $stmt = $db->prepare("SELECT image FROM menu_items WHERE item_id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item && $item['image']) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/menu/';
            if (file_exists($upload_dir . $item['image'])) {
                unlink($upload_dir . $item['image']);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM menu_items WHERE item_id = ?");
        $stmt->execute([$itemId]);
        $_SESSION['success'] = "Menu item deleted successfully";
    }
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Toggle item availability
if ($action === 'toggle_availability' && $itemId > 0) {
    $stmt = $db->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $_SESSION['success'] = "Item availability toggled";
    header("Location: menu.php?restaurant_id=$restaurantId");
    exit;
}

// Get all categories
$stmt = $db->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM menu_items WHERE category_id = c.category_id) as item_count
    FROM menu_categories c
    WHERE c.restaurant_id = ?
    ORDER BY c.display_order, c.category_name
");
$stmt->execute([$restaurantId]);
$categories = $stmt->fetchAll();

// Get all menu items grouped by category
$menuItems = [];
foreach ($categories as $cat) {
    $stmt = $db->prepare("
        SELECT * FROM menu_items
        WHERE category_id = ?
        ORDER BY display_order, item_name
    ");
    $stmt->execute([$cat['category_id']]);
    $menuItems[$cat['category_id']] = $stmt->fetchAll();
}

// Get cuisine types for filter
$stmt = $db->query("SELECT DISTINCT cuisine_type FROM restaurants WHERE cuisine_type IS NOT NULL");
$cuisineTypes = $stmt->fetchAll();
?>

<style>
/* Menu Management Styles */
.menu-header {
    margin-bottom: 24px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--booking-blue);
    text-decoration: none;
    font-size: 0.75rem;
    margin-bottom: 16px;
}

.back-link:hover {
    text-decoration: underline;
}

.restaurant-info-bar {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.restaurant-info h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.restaurant-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Cards */
.menu-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
    text-align: center;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 4px;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
}

.btn-sm {
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-sm.primary {
    background: var(--booking-blue);
    color: var(--booking-white);
}

.btn-sm.secondary {
    background: var(--booking-gray-light);
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
}

.btn-sm.primary:hover,
.btn-sm.secondary:hover {
    transform: translateY(-1px);
}

/* Categories & Items Grid */
.menu-grid {
    display: grid;
    gap: 24px;
}

.category-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow: hidden;
}

.category-header {
    padding: 16px 20px;
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.category-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.category-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--booking-text);
}

.category-badge {
    background: var(--booking-blue);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.625rem;
}

.category-actions {
    display: flex;
    gap: 8px;
}

.icon-btn {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    color: var(--booking-text);
}

.icon-btn:hover {
    transform: translateY(-2px);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.category-description {
    padding: 12px 20px;
    background: var(--booking-gray-light);
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    border-bottom: 1px solid var(--booking-border);
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    background: var(--booking-gray-light);
    border-bottom: 1px solid var(--booking-border);
}

.items-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    font-size: 0.75rem;
    vertical-align: middle;
}

.items-table tr:hover td {
    background: var(--booking-gray-light);
}

.item-image {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--booking-gray-light);
}

.item-name {
    font-weight: 600;
    margin-bottom: 4px;
}

.item-description {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.item-price {
    font-weight: 700;
    color: var(--booking-success);
}

.item-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.tag {
    font-size: 0.5625rem;
    padding: 2px 6px;
    border-radius: 10px;
    background: var(--booking-gray-light);
}

.tag.signature {
    background: #ffc10720;
    color: #ff8c00;
}

.tag.vegetarian {
    background: #4caf5020;
    color: #4caf50;
}

.tag.vegan {
    background: #8bc34a20;
    color: #689f38;
}

.tag.gluten-free {
    background: #ff980020;
    color: #f57c00;
}

.tag.spicy {
    background: #f4433620;
    color: #f44336;
}

.item-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.status-available {
    background: #e6f4ea;
    color: var(--booking-success);
}

.status-unavailable {
    background: #fce8e8;
    color: var(--booking-danger);
}

.item-actions {
    display: flex;
    gap: 6px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

/* Form Styles */
.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.form-check input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-check label {
    margin: 0;
    cursor: pointer;
    font-weight: normal;
    text-transform: none;
}

.tags-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    margin-top: 8px;
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
}

.alert-error {
    background: #fce8e8;
    color: var(--booking-danger);
}

/* Responsive */
@media (max-width: 768px) {
    .menu-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .category-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .items-table {
        min-width: 600px;
    }
    .form-row {
        grid-template-columns: 1fr;
    }
    .tags-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="menu-header">
    <a href="restaurant-detail.php?id=<?php echo $restaurantId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Restaurant Details
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div><i class="bi bi-check-circle-fill"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-error">
    <div><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<div class="restaurant-info-bar">
    <div class="restaurant-info">
        <h2><?php echo sanitize($restaurant['restaurant_name']); ?></h2>
        <p><i class="bi bi-list-ul"></i> Menu Management - <?php echo count($categories); ?> categories, <?php echo array_sum(array_map('count', $menuItems)); ?> items</p>
    </div>
    <div class="action-buttons">
        <button class="btn-sm primary" onclick="openCategoryModal()">
            <i class="bi bi-folder-plus"></i> Add Category
        </button>
        <button class="btn-sm secondary" onclick="openItemModal()">
            <i class="bi bi-plus-lg"></i> Add Menu Item
        </button>
    </div>
</div>

<!-- Statistics -->
<div class="menu-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($categories); ?></div>
        <div class="stat-label">Categories</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo array_sum(array_map('count', $menuItems)); ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">
            <?php 
            $available = 0;
            foreach ($menuItems as $items) {
                $available += count(array_filter($items, function($i) { return $i['is_available']; }));
            }
            echo $available;
            ?>
        </div>
        <div class="stat-label">Available</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">
            <?php 
            $signature = 0;
            foreach ($menuItems as $items) {
                $signature += count(array_filter($items, function($i) { return $i['is_signature']; }));
            }
            echo $signature;
            ?>
        </div>
        <div class="stat-label">Signature Dishes</div>
    </div>
</div>

<!-- Menu Grid -->
<div class="menu-grid">
    <?php if (empty($categories)): ?>
    <div class="empty-state">
        <i class="bi bi-folder" style="font-size: 3rem; color: var(--booking-text-lighter);"></i>
        <p style="margin-top: 16px;">No menu categories yet</p>
        <button class="btn-sm primary" onclick="openCategoryModal()" style="margin-top: 16px;">
            <i class="bi bi-folder-plus"></i> Create First Category
        </button>
    </div>
    <?php else: ?>
    <?php foreach ($categories as $category): 
        $items = $menuItems[$category['category_id']] ?? [];
    ?>
    <div class="category-card">
        <div class="category-header">
            <div class="category-title">
                <span class="category-name"><?php echo sanitize($category['category_name']); ?></span>
                <span class="category-badge"><?php echo count($items); ?> items</span>
                <?php if (!$category['is_active']): ?>
                <span class="tag" style="background: #fce8e8; color: #e21111;">Inactive</span>
                <?php endif; ?>
            </div>
            <div class="category-actions">
                <button class="icon-btn" onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)" title="Edit Category">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="icon-btn" onclick="deleteCategory(<?php echo $category['category_id']; ?>, '<?php echo addslashes($category['category_name']); ?>')" title="Delete Category">
                    <i class="bi bi-trash"></i>
                </button>
                <button class="icon-btn" onclick="openItemModalWithCategory(<?php echo $category['category_id']; ?>)" title="Add Item to this Category">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
        </div>
        
        <?php if ($category['description']): ?>
        <div class="category-description">
            <?php echo sanitize($category['description']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (empty($items)): ?>
        <div style="text-align: center; padding: 40px; color: var(--booking-text-light);">
            <i class="bi bi-egg-fried" style="font-size: 1.5rem;"></i>
            <p style="margin-top: 8px; font-size: 0.6875rem;">No items in this category</p>
            <button class="btn-sm secondary" onclick="openItemModalWithCategory(<?php echo $category['category_id']; ?>)" style="margin-top: 8px;">
                <i class="bi bi-plus-lg"></i> Add First Item
            </button>
        </div>
        <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Image</th>
                        <th>Item</th>
                        <th>Price</th>
                        <th>Tags</th>
                        <th>Status</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?php if ($item['image']): ?>
                            <img src="<?php echo getImageUrl($item['image'], 'menu'); ?>" class="item-image">
                            <?php else: ?>
                            <div class="item-image" style="background: var(--booking-gray-light); display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-egg-fried" style="font-size: 1.25rem;"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="item-name"><?php echo sanitize($item['item_name']); ?></div>
                            <?php if ($item['description']): ?>
                            <div class="item-description"><?php echo sanitize(substr($item['description'], 0, 60)); ?></div>
                            <?php endif; ?>
                            <?php if ($item['preparation_time']): ?>
                            <div class="item-description"><i class="bi bi-clock"></i> <?php echo $item['preparation_time']; ?> min</div>
                            <?php endif; ?>
                        </td>
                        <td class="item-price"><?php echo formatPrice($item['price']); ?></td>
                        <td>
                            <div class="item-tags">
                                <?php if ($item['is_signature']): ?>
                                <span class="tag signature">⭐ Signature</span>
                                <?php endif; ?>
                                <?php if ($item['is_vegetarian']): ?>
                                <span class="tag vegetarian">🌱 Vegetarian</span>
                                <?php endif; ?>
                                <?php if ($item['is_vegan']): ?>
                                <span class="tag vegan">🌿 Vegan</span>
                                <?php endif; ?>
                                <?php if ($item['is_gluten_free']): ?>
                                <span class="tag gluten-free">🚫 Gluten-Free</span>
                                <?php endif; ?>
                                <?php if ($item['is_spicy']): ?>
                                <span class="tag spicy">🌶️ Spicy</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="item-status <?php echo $item['is_available'] ? 'status-available' : 'status-unavailable'; ?>">
                                <i class="bi bi-<?php echo $item['is_available'] ? 'check-circle' : 'x-circle'; ?>"></i>
                                <?php echo $item['is_available'] ? 'Available' : 'Unavailable'; ?>
                            </span>
                        </td>
                        <td class="item-actions">
                            <button class="icon-btn" onclick="editItem(<?php echo htmlspecialchars(json_encode($item)); ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="?action=toggle_availability&item_id=<?php echo $item['item_id']; ?>&restaurant_id=<?php echo $restaurantId; ?>" class="icon-btn" title="Toggle Availability">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                            <button class="icon-btn" onclick="deleteItem(<?php echo $item['item_id']; ?>, '<?php echo addslashes($item['item_name']); ?>')" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="menu.php?restaurant_id=<?php echo $restaurantId; ?>">
            <input type="hidden" name="action" id="categoryAction" value="add_category">
            <input type="hidden" name="category_id" id="categoryId" value="0">
            
            <div class="modal-header">
                <h3 id="categoryModalTitle">Add Category</h3>
                <button type="button" class="modal-close" onclick="closeCategoryModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Category Name</label>
                    <input type="text" name="category_name" id="category_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="category_description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="category_display_order" class="form-control" value="0">
                    </div>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="category_is_active" value="1" checked>
                    <label for="category_is_active">Active (Visible in menu)</label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeCategoryModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Menu Item Modal -->
<div id="itemModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="menu.php?restaurant_id=<?php echo $restaurantId; ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" id="itemAction" value="add_item">
            <input type="hidden" name="item_id" id="itemId" value="0">
            
            <div class="modal-header">
                <h3 id="itemModalTitle">Add Menu Item</h3>
                <button type="button" class="modal-close" onclick="closeItemModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Category</label>
                    <select name="category_id" id="item_category_id" class="form-control" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['category_id']; ?>"><?php echo sanitize($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="required">Item Name</label>
                    <input type="text" name="item_name" id="item_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="item_description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Price (RWF)</label>
                        <input type="number" name="price" id="item_price" class="form-control" step="100" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Preparation Time (minutes)</label>
                        <input type="number" name="preparation_time" id="item_preparation_time" class="form-control" min="0">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Calories</label>
                        <input type="number" name="calories" id="item_calories" class="form-control" min="0">
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="item_display_order" class="form-control" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Item Image</label>
                    <input type="file" name="item_image" id="item_image" class="form-control" accept="image/*">
                    <div id="current_image_preview" style="margin-top: 8px; display: none;">
                        <div style="position: relative; display: inline-block;">
                            <img id="current_image_img" src="" style="max-width: 100px; border-radius: var(--radius-sm);">
                            <button type="button" id="remove_image_btn" class="icon-btn" style="position: absolute; top: -8px; right: -8px; background: var(--booking-danger); color: white; width: 24px; height: 24px;">×</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Dietary Tags</label>
                    <div class="tags-grid">
                        <label class="form-check">
                            <input type="checkbox" name="is_signature" id="item_is_signature" value="1">
                            <span>⭐ Signature Dish</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="is_vegetarian" id="item_is_vegetarian" value="1">
                            <span>🌱 Vegetarian</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="is_vegan" id="item_is_vegan" value="1">
                            <span>🌿 Vegan</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="is_gluten_free" id="item_is_gluten_free" value="1">
                            <span>🚫 Gluten-Free</span>
                        </label>
                        <label class="form-check">
                            <input type="checkbox" name="is_spicy" id="item_is_spicy" value="1">
                            <span>🌶️ Spicy</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" name="is_available" id="item_is_available" value="1" checked>
                    <label for="item_is_available">Available for ordering</label>
                </div>
                
                <input type="hidden" name="delete_image" id="delete_image" value="0">
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-sm secondary" onclick="closeItemModal()">Cancel</button>
                <button type="submit" class="btn-sm primary">Save Item</button>
            </div>
        </form>
    </div>
</div>

<script>
// Category Modal
function openCategoryModal() {
    document.getElementById('categoryModalTitle').innerText = 'Add Category';
    document.getElementById('categoryAction').value = 'add_category';
    document.getElementById('categoryId').value = '0';
    document.getElementById('category_name').value = '';
    document.getElementById('category_description').value = '';
    document.getElementById('category_display_order').value = '0';
    document.getElementById('category_is_active').checked = true;
    document.getElementById('categoryModal').style.display = 'flex';
}

function editCategory(category) {
    document.getElementById('categoryModalTitle').innerText = 'Edit Category';
    document.getElementById('categoryAction').value = 'edit_category';
    document.getElementById('categoryId').value = category.category_id;
    document.getElementById('category_name').value = category.category_name;
    document.getElementById('category_description').value = category.description || '';
    document.getElementById('category_display_order').value = category.display_order;
    document.getElementById('category_is_active').checked = category.is_active == 1;
    document.getElementById('categoryModal').style.display = 'flex';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

function deleteCategory(categoryId, categoryName) {
    if (confirm(`Delete category "${categoryName}"? This will also delete all items in this category.`)) {
        window.location.href = `menu.php?restaurant_id=<?php echo $restaurantId; ?>&action=delete_category&category_id=${categoryId}`;
    }
}

// Item Modal
function openItemModal() {
    document.getElementById('itemModalTitle').innerText = 'Add Menu Item';
    document.getElementById('itemAction').value = 'add_item';
    document.getElementById('itemId').value = '0';
    document.getElementById('item_category_id').value = '';
    document.getElementById('item_name').value = '';
    document.getElementById('item_description').value = '';
    document.getElementById('item_price').value = '';
    document.getElementById('item_preparation_time').value = '';
    document.getElementById('item_calories').value = '';
    document.getElementById('item_display_order').value = '0';
    document.getElementById('item_is_signature').checked = false;
    document.getElementById('item_is_vegetarian').checked = false;
    document.getElementById('item_is_vegan').checked = false;
    document.getElementById('item_is_gluten_free').checked = false;
    document.getElementById('item_is_spicy').checked = false;
    document.getElementById('item_is_available').checked = true;
    document.getElementById('item_image').value = '';
    document.getElementById('current_image_preview').style.display = 'none';
    document.getElementById('delete_image').value = '0';
    document.getElementById('itemModal').style.display = 'flex';
}

function openItemModalWithCategory(categoryId) {
    openItemModal();
    document.getElementById('item_category_id').value = categoryId;
}

function editItem(item) {
    document.getElementById('itemModalTitle').innerText = 'Edit Menu Item';
    document.getElementById('itemAction').value = 'edit_item';
    document.getElementById('itemId').value = item.item_id;
    document.getElementById('item_category_id').value = item.category_id;
    document.getElementById('item_name').value = item.item_name;
    document.getElementById('item_description').value = item.description || '';
    document.getElementById('item_price').value = item.price;
    document.getElementById('item_preparation_time').value = item.preparation_time || '';
    document.getElementById('item_calories').value = item.calories || '';
    document.getElementById('item_display_order').value = item.display_order;
    document.getElementById('item_is_signature').checked = item.is_signature == 1;
    document.getElementById('item_is_vegetarian').checked = item.is_vegetarian == 1;
    document.getElementById('item_is_vegan').checked = item.is_vegan == 1;
    document.getElementById('item_is_gluten_free').checked = item.is_gluten_free == 1;
    document.getElementById('item_is_spicy').checked = item.is_spicy == 1;
    document.getElementById('item_is_available').checked = item.is_available == 1;
    document.getElementById('delete_image').value = '0';
    
    if (item.image) {
        document.getElementById('current_image_img').src = '<?php echo getImageUrl('', 'menu'); ?>' + item.image;
        document.getElementById('current_image_preview').style.display = 'block';
    } else {
        document.getElementById('current_image_preview').style.display = 'none';
    }
    
    document.getElementById('itemModal').style.display = 'flex';
}

function closeItemModal() {
    document.getElementById('itemModal').style.display = 'none';
}

function deleteItem(itemId, itemName) {
    if (confirm(`Delete menu item "${itemName}"?`)) {
        window.location.href = `menu.php?restaurant_id=<?php echo $restaurantId; ?>&action=delete_item&item_id=${itemId}`;
    }
}

// Remove image button
document.getElementById('remove_image_btn')?.addEventListener('click', function() {
    document.getElementById('delete_image').value = '1';
    document.getElementById('current_image_preview').style.display = 'none';
});

// Close modals on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCategoryModal();
        closeItemModal();
    }
});

// Close modals when clicking outside
window.onclick = function(e) {
    const catModal = document.getElementById('categoryModal');
    const itemModal = document.getElementById('itemModal');
    if (e.target === catModal) closeCategoryModal();
    if (e.target === itemModal) closeItemModal();
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>