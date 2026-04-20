<?php
$rentalId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$rentalId) {
    header('Location: cars.php');
    exit;
}

$pageTitle = 'Edit Car Rental';
require_once 'includes/admin_header.php';

$db = getDB();

// Get rental company details
$stmt = $db->prepare("SELECT * FROM car_rentals WHERE rental_id = ?");
$stmt->execute([$rentalId]);
$rental = $stmt->fetch();

if (!$rental) {
    header('Location: cars.php');
    exit;
}

// Handle form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $company_name = sanitize($_POST['company_name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $location_id = intval($_POST['location_id'] ?? 0);
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $operating_hours = sanitize($_POST['operating_hours'] ?? '');
    $free_cancellation = isset($_POST['free_cancellation']) ? 1 : 0;
    $instant_confirmation = isset($_POST['instant_confirmation']) ? 1 : 0;
    $commission_rate = floatval($_POST['commission_rate'] ?? 12.00);
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Handle pickup/dropoff locations (JSON arrays)
    $pickup_locations = isset($_POST['pickup_locations']) ? array_filter($_POST['pickup_locations'], 'trim') : [];
    $dropoff_locations = isset($_POST['dropoff_locations']) ? array_filter($_POST['dropoff_locations'], 'trim') : [];
    
    // If no specific dropoff locations, use pickup locations
    if (empty($dropoff_locations)) {
        $dropoff_locations = $pickup_locations;
    }
    
    $pickup_locations_json = !empty($pickup_locations) ? json_encode(array_values($pickup_locations)) : null;
    $dropoff_locations_json = !empty($dropoff_locations) ? json_encode(array_values($dropoff_locations)) : null;
    
    // Validation
    if (empty($company_name)) {
        $errors[] = "Company name is required";
    }
    if (empty($address)) {
        $errors[] = "Address is required";
    }
    if ($location_id <= 0) {
        $errors[] = "Location is required";
    }
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }
    if (empty($email)) {
        $errors[] = "Email address is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address";
    }
    
    // Handle logo upload
    $logo = $rental['logo'];
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/assets/images/cars/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'company_' . $rentalId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;
        
        // Delete old logo if exists
        if ($logo && file_exists($upload_dir . $logo)) {
            unlink($upload_dir . $logo);
        }
        
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
            $logo = $filename;
        } else {
            $errors[] = "Failed to upload logo";
        }
    }
    
    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE car_rentals SET
                company_name = ?,
                description = ?,
                address = ?,
                location_id = ?,
                phone = ?,
                email = ?,
                operating_hours = ?,
                pickup_locations = ?,
                dropoff_locations = ?,
                free_cancellation = ?,
                instant_confirmation = ?,
                commission_rate = ?,
                logo = ?,
                is_verified = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE rental_id = ?
        ");
        
        $result = $stmt->execute([
            $company_name,
            $description,
            $address,
            $location_id,
            $phone,
            $email,
            $operating_hours,
            $pickup_locations_json,
            $dropoff_locations_json,
            $free_cancellation,
            $instant_confirmation,
            $commission_rate,
            $logo,
            $is_verified,
            $is_active,
            $rentalId
        ]);
        
        if ($result) {
            $success = true;
            $_SESSION['success'] = "Car rental company updated successfully";
            // Refresh rental data
            $stmt = $db->prepare("SELECT * FROM car_rentals WHERE rental_id = ?");
            $stmt->execute([$rentalId]);
            $rental = $stmt->fetch();
        } else {
            $errors[] = "Failed to update company";
        }
    }
}

// Get all locations
$stmt = $db->query("SELECT location_id, name FROM locations WHERE is_active = 1 ORDER BY name");
$locations = $stmt->fetchAll();

// Get current pickup/dropoff locations
$pickupLocations = $rental['pickup_locations'] ? json_decode($rental['pickup_locations'], true) : [];
$dropoffLocations = $rental['dropoff_locations'] ? json_decode($rental['dropoff_locations'], true) : [];

// If no dropoff locations, default to pickup locations
if (empty($dropoffLocations) && !empty($pickupLocations)) {
    $dropoffLocations = $pickupLocations;
}

// Get fleet summary
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
        MIN(daily_rate) as min_rate,
        MAX(daily_rate) as max_rate,
        AVG(daily_rate) as avg_rate
    FROM car_fleet
    WHERE rental_id = ?
");
$stmt->execute([$rentalId]);
$fleetSummary = $stmt->fetch();
?>

<style>
/* Edit Car Styles */
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

/* Location Inputs */
.location-input-group {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    align-items: center;
}

.location-input-group input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.location-input-group button {
    padding: 8px 12px;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.location-input-group button:hover {
    background: var(--booking-danger);
    color: white;
    border-color: var(--booking-danger);
}

.add-location-btn {
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

.add-location-btn:hover {
    background: var(--booking-blue);
    color: white;
    border-color: var(--booking-blue);
}

/* Fleet Summary */
.fleet-summary {
    background: var(--booking-gray-light);
    border-radius: var(--radius-md);
    padding: 16px;
    margin-bottom: 20px;
}

.fleet-stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.fleet-stat {
    text-align: center;
}

.fleet-stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--booking-text);
}

.fleet-stat-label {
    font-size: 0.625rem;
    color: var(--booking-text-light);
}

.fleet-action {
    text-align: center;
    padding-top: 16px;
    border-top: 1px solid var(--booking-border);
}

/* Image Upload */
.image-upload-area {
    border: 2px dashed var(--booking-border);
    border-radius: var(--radius-md);
    padding: 24px;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    margin-bottom: 16px;
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

.logo-preview {
    margin-top: 16px;
    text-align: center;
}

.logo-preview img {
    max-width: 150px;
    max-height: 150px;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 8px;
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
    
    .fleet-stats-grid {
        grid-template-columns: repeat(2, 1fr);
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
    <a href="car-detail.php?id=<?php echo $rentalId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Company Details
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        Company updated successfully!
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

<form method="POST" action="edit-car.php?id=<?php echo $rentalId; ?>" enctype="multipart/form-data" class="edit-form">
    <!-- Basic Information -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-info-circle"></i>
            Basic Information
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Company Name</label>
                <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($rental['company_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="required">Location/Region</label>
                <select name="location_id" class="form-control" required>
                    <option value="">Select Location</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['location_id']; ?>" <?php echo $rental['location_id'] == $loc['location_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" class="form-control" rows="4" placeholder="Describe your car rental company, fleet, services, etc."><?php echo htmlspecialchars($rental['description']); ?></textarea>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="required">Address</label>
                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($rental['address']); ?>" required>
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
                <label class="required">Phone Number</label>
                <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($rental['phone']); ?>" required>
            </div>
            
            <div class="form-group">
                <label class="required">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($rental['email']); ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Operating Hours</label>
            <input type="text" name="operating_hours" class="form-control" placeholder="e.g., Mon-Sun: 06:00-20:00" value="<?php echo htmlspecialchars($rental['operating_hours']); ?>">
        </div>
    </div>
    
    <!-- Pickup & Dropoff Locations -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-geo-alt"></i>
            Pickup & Dropoff Locations
        </h3>
        
        <div class="form-group">
            <label>Pickup Locations</label>
            <div id="pickupLocationsContainer">
                <?php if (empty($pickupLocations)): ?>
                <div class="location-input-group">
                    <input type="text" name="pickup_locations[]" class="form-control" placeholder="e.g., Kigali International Airport">
                    <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php else: ?>
                <?php foreach ($pickupLocations as $location): ?>
                <div class="location-input-group">
                    <input type="text" name="pickup_locations[]" class="form-control" value="<?php echo htmlspecialchars($location); ?>">
                    <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="add-location-btn" onclick="addPickupLocation()">
                <i class="bi bi-plus-lg"></i> Add Pickup Location
            </button>
        </div>
        
        <div class="form-group">
            <label>Dropoff Locations (leave empty to use pickup locations)</label>
            <div id="dropoffLocationsContainer">
                <?php if (empty($dropoffLocations) || $dropoffLocations === $pickupLocations): ?>
                <div class="location-input-group">
                    <input type="text" name="dropoff_locations[]" class="form-control" placeholder="Same as pickup locations">
                    <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php else: ?>
                <?php foreach ($dropoffLocations as $location): ?>
                <div class="location-input-group">
                    <input type="text" name="dropoff_locations[]" class="form-control" value="<?php echo htmlspecialchars($location); ?>">
                    <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="add-location-btn" onclick="addDropoffLocation()">
                <i class="bi bi-plus-lg"></i> Add Dropoff Location
            </button>
        </div>
    </div>
    
    <!-- Fleet Summary -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-car-front"></i>
            Fleet Overview
        </h3>
        
        <div class="fleet-summary">
            <div class="fleet-stats-grid">
                <div class="fleet-stat">
                    <div class="fleet-stat-value"><?php echo $fleetSummary['total']; ?></div>
                    <div class="fleet-stat-label">Total Vehicles</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-value"><?php echo $fleetSummary['active']; ?></div>
                    <div class="fleet-stat-label">Active</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-value"><?php echo $fleetSummary['available']; ?></div>
                    <div class="fleet-stat-label">Available Now</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-value"><?php echo formatPrice($fleetSummary['min_rate'] ?? 0); ?></div>
                    <div class="fleet-stat-label">Min Daily Rate</div>
                </div>
                <div class="fleet-stat">
                    <div class="fleet-stat-value"><?php echo formatPrice($fleetSummary['max_rate'] ?? 0); ?></div>
                    <div class="fleet-stat-label">Max Daily Rate</div>
                </div>
            </div>
            <div class="fleet-action">
                <a href="fleet.php?rental_id=<?php echo $rentalId; ?>" class="btn btn-secondary" style="display: inline-flex;">
                    <i class="bi bi-car-front"></i> Manage Fleet Vehicles
                </a>
            </div>
        </div>
    </div>
    
    <!-- Policies & Settings -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-file-text"></i>
            Policies & Settings
        </h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Commission Rate (%)</label>
                <input type="number" name="commission_rate" class="form-control" step="0.01" min="0" max="100" value="<?php echo $rental['commission_rate']; ?>">
            </div>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="free_cancellation" id="free_cancellation" <?php echo $rental['free_cancellation'] ? 'checked' : ''; ?>>
            <label for="free_cancellation">Free Cancellation (up to 24 hours before pickup)</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="instant_confirmation" id="instant_confirmation" <?php echo $rental['instant_confirmation'] ? 'checked' : ''; ?>>
            <label for="instant_confirmation">Instant Confirmation (bookings are confirmed automatically)</label>
        </div>
    </div>
    
    <!-- Branding -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-image"></i>
            Company Logo
        </h3>
        
        <div class="form-group">
            <div class="image-upload-area" onclick="document.getElementById('logo_input').click()">
                <i class="bi bi-cloud-upload"></i>
                <p>Click to upload company logo</p>
                <p style="font-size: 0.625rem;">Recommended size: 200x200px (PNG, JPG)</p>
                <input type="file" id="logo_input" name="logo" accept="image/*" style="display: none;" onchange="previewLogo(this)">
            </div>
            
            <?php if ($rental['logo']): ?>
            <div id="logo_preview" class="logo-preview">
                <img src="<?php echo getImageUrl($rental['logo'], 'car'); ?>" alt="Current logo">
                <p style="font-size: 0.625rem; margin-top: 8px;">Current logo</p>
            </div>
            <?php else: ?>
            <div id="logo_preview" class="logo-preview" style="display: none;"></div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Status -->
    <div class="form-section">
        <h3 class="form-section-title">
            <i class="bi bi-toggle-on"></i>
            Status
        </h3>
        
        <div class="form-check">
            <input type="checkbox" name="is_verified" id="is_verified" <?php echo $rental['is_verified'] ? 'checked' : ''; ?>>
            <label for="is_verified">Verified (Approved for booking)</label>
        </div>
        
        <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo $rental['is_active'] ? 'checked' : ''; ?>>
            <label for="is_active">Active (Visible on platform)</label>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="form-actions">
        <a href="car-detail.php?id=<?php echo $rentalId; ?>" class="btn btn-secondary">
            <i class="bi bi-x-lg"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Save Changes
        </button>
    </div>
</form>

<script>
// Add pickup location
function addPickupLocation() {
    const container = document.getElementById('pickupLocationsContainer');
    const div = document.createElement('div');
    div.className = 'location-input-group';
    div.innerHTML = `
        <input type="text" name="pickup_locations[]" class="form-control" placeholder="e.g., Kigali International Airport">
        <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

// Add dropoff location
function addDropoffLocation() {
    const container = document.getElementById('dropoffLocationsContainer');
    const div = document.createElement('div');
    div.className = 'location-input-group';
    div.innerHTML = `
        <input type="text" name="dropoff_locations[]" class="form-control" placeholder="e.g., Kigali City Center">
        <button type="button" class="remove-location" onclick="this.parentElement.remove()">✕</button>
    `;
    container.appendChild(div);
}

// Preview logo
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('logo_preview');
            preview.innerHTML = `
                <img src="${e.target.result}" alt="Logo preview">
                <p style="font-size: 0.625rem; margin-top: 8px;">New logo (will replace existing)</p>
            `;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Remove empty location inputs on form submit
document.querySelector('form').addEventListener('submit', function(e) {
    // Remove empty pickup locations
    document.querySelectorAll('[name="pickup_locations[]"]').forEach(input => {
        if (input.value.trim() === '') {
            input.parentElement.remove();
        }
    });
    
    // Remove empty dropoff locations
    document.querySelectorAll('[name="dropoff_locations[]"]').forEach(input => {
        if (input.value.trim() === '') {
            input.parentElement.remove();
        }
    });
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>