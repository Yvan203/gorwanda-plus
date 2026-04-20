<?php
$pageTitle = 'Property Policies';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$propertyId = isset($_GET['property']) ? intval($_GET['property']) : 0;

// ============================================
// HANDLE POLICY ACTIONS
// ============================================

// Save all policies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policies'])) {
    $stayId = intval($_POST['stay_id']);
    
    // Verify ownership
    $stmt = $db->prepare("SELECT stay_id FROM stays WHERE stay_id = ? AND owner_id = ?");
    $stmt->execute([$stayId, $userId]);
    if (!$stmt->fetch()) {
        $error = "You don't have permission to modify this property";
    } else {
        // Basic Policies
        $checkInFrom = $_POST['check_in_from'] ?? '14:00';
        $checkInUntil = $_POST['check_in_until'] ?? '23:00';
        $checkOutBefore = $_POST['check_out_before'] ?? '11:00';
        $minimumStay = intval($_POST['minimum_stay'] ?? 1);
        $maximumStay = intval($_POST['maximum_stay'] ?? 30);
        
        // Update stay with basic policies
        $stmt = $db->prepare("
            UPDATE stays SET 
                check_in_time = ?,
                check_out_time = ?,
                min_stay = ?,
                max_stay = ?
            WHERE stay_id = ? AND owner_id = ?
        ");
        $stmt->execute([$checkInFrom, $checkOutBefore, $minimumStay, $maximumStay, $stayId, $userId]);
        
        // Collect all policies in a JSON object
        $policies = [
            'check_in' => [
                'from' => $checkInFrom,
                'until' => $checkInUntil,
                'instructions' => sanitize($_POST['check_in_instructions'] ?? '')
            ],
            'check_out' => [
                'before' => $checkOutBefore,
                'instructions' => sanitize($_POST['check_out_instructions'] ?? '')
            ],
            'children' => [
                'allowed' => isset($_POST['children_allowed']),
                'policy' => sanitize($_POST['children_policy'] ?? ''),
                'extra_bed_price' => floatval($_POST['extra_bed_price'] ?? 0),
                'crib_price' => floatval($_POST['crib_price'] ?? 0),
                'max_children' => intval($_POST['max_children'] ?? 3)
            ],
            'pets' => [
                'allowed' => isset($_POST['pets_allowed']),
                'policy' => sanitize($_POST['pets_policy'] ?? ''),
                'fee' => floatval($_POST['pet_fee'] ?? 0),
                'weight_limit' => intval($_POST['pet_weight_limit'] ?? 0),
                'max_pets' => intval($_POST['max_pets'] ?? 1),
                'breed_restrictions' => sanitize($_POST['breed_restrictions'] ?? '')
            ],
            'smoking' => [
                'allowed' => isset($_POST['smoking_allowed']),
                'policy' => sanitize($_POST['smoking_policy'] ?? ''),
                'fine' => floatval($_POST['smoking_fine'] ?? 0)
            ],
            'parties' => [
                'allowed' => isset($_POST['parties_allowed']),
                'policy' => sanitize($_POST['parties_policy'] ?? ''),
                'noise_curfew' => $_POST['noise_curfew'] ?? '22:00'
            ],
            'cancellation' => [
                'type' => $_POST['cancellation_type'] ?? 'moderate',
                'free_cancellation_days' => intval($_POST['free_cancellation_days'] ?? 1),
                'free_cancellation_hours' => intval($_POST['free_cancellation_hours'] ?? 24),
                'penalty_percent' => intval($_POST['penalty_percent'] ?? 100),
                'no_show_penalty' => intval($_POST['no_show_penalty'] ?? 100),
                'special_conditions' => sanitize($_POST['cancellation_conditions'] ?? '')
            ],
            'payment' => [
                'accepted_methods' => $_POST['payment_methods'] ?? ['momo', 'card'],
                'deposit_required' => isset($_POST['deposit_required']),
                'deposit_amount' => floatval($_POST['deposit_amount'] ?? 0),
                'deposit_type' => $_POST['deposit_type'] ?? 'percentage',
                'prepayment_policy' => sanitize($_POST['prepayment_policy'] ?? '')
            ],
            'house_rules' => [
                'quiet_hours_from' => $_POST['quiet_hours_from'] ?? '22:00',
                'quiet_hours_to' => $_POST['quiet_hours_to'] ?? '08:00',
                'age_restriction' => intval($_POST['age_restriction'] ?? 0),
                'additional_rules' => sanitize($_POST['additional_rules'] ?? '')
            ],
            'damage' => [
                'deposit_required' => isset($_POST['damage_deposit_required']),
                'deposit_amount' => floatval($_POST['damage_deposit_amount'] ?? 0),
                'deposit_refund_timeline' => intval($_POST['deposit_refund_days'] ?? 7),
                'damage_policy' => sanitize($_POST['damage_policy'] ?? '')
            ],
            'accessibility' => [
                'wheelchair_accessible' => isset($_POST['wheelchair_accessible']),
                'accessible_rooms' => intval($_POST['accessible_rooms'] ?? 0),
                'accessibility_features' => $_POST['accessibility_features'] ?? []
            ]
        ];
        
        // Update policies JSON
        $policiesJson = json_encode($policies);
        $stmt = $db->prepare("
            UPDATE stays SET policies = ? WHERE stay_id = ? AND owner_id = ?
        ");
        $stmt->execute([$policiesJson, $stayId, $userId]);
        
        $success = "Policies updated successfully!";
    }
}

// ============================================
// GET PROPERTIES AND POLICIES
// ============================================

// Get all properties
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city, check_in_time, check_out_time, policies
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

// Get selected property details and policies
$currentProperty = null;
$policies = [
    'check_in' => ['from' => '14:00', 'until' => '23:00', 'instructions' => ''],
    'check_out' => ['before' => '11:00', 'instructions' => ''],
    'children' => ['allowed' => true, 'policy' => 'Children of all ages are welcome.', 'extra_bed_price' => 0, 'crib_price' => 0, 'max_children' => 3],
    'pets' => ['allowed' => false, 'policy' => 'Pets are not allowed.', 'fee' => 0, 'weight_limit' => 0, 'max_pets' => 0, 'breed_restrictions' => ''],
    'smoking' => ['allowed' => false, 'policy' => 'Smoking is not allowed in any indoor areas.', 'fine' => 50000],
    'parties' => ['allowed' => false, 'policy' => 'Parties and events are not allowed.', 'noise_curfew' => '22:00'],
    'cancellation' => ['type' => 'moderate', 'free_cancellation_days' => 1, 'free_cancellation_hours' => 24, 'penalty_percent' => 100, 'no_show_penalty' => 100, 'special_conditions' => ''],
    'payment' => ['accepted_methods' => ['momo', 'card'], 'deposit_required' => false, 'deposit_amount' => 0, 'deposit_type' => 'percentage', 'prepayment_policy' => ''],
    'house_rules' => ['quiet_hours_from' => '22:00', 'quiet_hours_to' => '08:00', 'age_restriction' => 0, 'additional_rules' => ''],
    'damage' => ['deposit_required' => false, 'deposit_amount' => 0, 'deposit_refund_timeline' => 7, 'damage_policy' => ''],
    'accessibility' => ['wheelchair_accessible' => false, 'accessible_rooms' => 0, 'accessibility_features' => []]
];

if ($propertyId > 0) {
    foreach ($properties as $prop) {
        if ($prop['stay_id'] == $propertyId) {
            $currentProperty = $prop;
            
            // Decode policies if they exist
            if (!empty($prop['policies'])) {
                $decoded = json_decode($prop['policies'], true);
                if ($decoded) {
                    $policies = array_merge($policies, $decoded);
                }
            }
            break;
        }
    }
}

// Cancellation policy types
$cancellationTypes = [
    'flexible' => 'Flexible - Free cancellation up to 24 hours before check-in',
    'moderate' => 'Moderate - Free cancellation up to 5 days before check-in',
    'strict' => 'Strict - Non-refundable after booking',
    'custom' => 'Custom Policy'
];

// Payment methods
$paymentMethods = [
    'momo' => 'MTN Mobile Money',
    'card' => 'Credit/Debit Card',
    'bank' => 'Bank Transfer',
    'cash' => 'Cash on Arrival'
];

// Accessibility features
$accessibilityFeatures = [
    'wheelchair_ramp' => 'Wheelchair ramp',
    'elevator' => 'Elevator access',
    'wide_doors' => 'Wide doorways (32" or wider)',
    'roll_in_shower' => 'Roll-in shower',
    'grab_bars' => 'Grab bars in bathroom',
    'visual_aids' => 'Visual alarms for hearing impaired',
    'braille_signs' => 'Braille signage',
    'service_animals' => 'Service animals allowed'
];
?>

<style>
/* Policies Management Specific Styles */
.policies-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.policies-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.policies-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Property Selector */
.property-selector {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
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
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    background: white;
}

/* Policy Sections */
.policy-section {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    margin-bottom: 24px;
    overflow: hidden;
}

.policy-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, var(--booking-light-blue), white);
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.policy-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.policy-title i {
    font-size: 1.25rem;
    color: var(--booking-blue);
    width: 32px;
}

.policy-title h3 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0;
}

.policy-status {
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.policy-status.allowed {
    background: #e6f4ea;
    color: var(--booking-success);
}

.policy-status.not-allowed {
    background: #fce8e8;
    color: var(--booking-danger);
}

.policy-toggle {
    color: var(--booking-text-light);
    transition: transform 0.3s;
}

.policy-toggle.open {
    transform: rotate(180deg);
}

.policy-content {
    padding: 20px;
    display: none;
}

.policy-content.show {
    display: block;
}

/* Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
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
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

/* Checkbox Group */
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    padding: 8px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--booking-blue);
}

/* Radio Group */
.radio-group {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    padding: 12px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Time Input */
.time-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.time-input-group input[type="time"] {
    flex: 1;
}

/* Price Input */
.price-input {
    position: relative;
}

.price-input:before {
    content: 'RWF';
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--booking-text-light);
    font-size: 0.875rem;
}

.price-input input {
    padding-left: 60px;
}

/* Cancellation Cards */
.cancellation-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.cancellation-card {
    border: 2px solid var(--booking-border);
    border-radius: var(--radius-md);
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.cancellation-card:hover {
    border-color: var(--booking-blue);
}

.cancellation-card.selected {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
}

.cancellation-card input[type="radio"] {
    display: none;
}

.cancellation-name {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 8px;
    color: var(--booking-text);
}

.cancellation-desc {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin-bottom: 12px;
}

.cancellation-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.75rem;
    font-weight: 600;
}

.cancellation-badge.flexible { background: #e6f4ea; color: var(--booking-success); }
.cancellation-badge.moderate { background: #fff4e6; color: var(--booking-warning); }
.cancellation-badge.strict { background: #fce8e8; color: var(--booking-danger); }

/* Payment Methods Grid */
.payment-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.payment-checkbox {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.2s;
}

.payment-checkbox:hover {
    border-color: var(--booking-blue);
    background: var(--booking-light-blue);
}

.payment-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--booking-blue);
}

.payment-checkbox i {
    font-size: 1.25rem;
    color: var(--booking-blue);
}

/* Accessibility Features Grid */
.features-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.feature-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: var(--booking-gray);
    border-radius: var(--radius-sm);
}

/* Action Buttons */
.policy-actions {
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--booking-border);
}

.btn-save {
    padding: 12px 32px;
    background: var(--booking-blue);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-save:hover {
    background: var(--booking-dark-blue);
}

.btn-preview {
    padding: 12px 32px;
    background: white;
    color: var(--booking-text);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-preview:hover {
    background: var(--booking-gray);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

.empty-state i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.875rem;
    color: var(--booking-text-light);
}

/* Responsive */
@media (max-width: 992px) {
    .form-grid,
    .cancellation-cards,
    .payment-grid,
    .features-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .property-selector select {
        min-width: 100%;
    }
    
    .policy-actions {
        flex-direction: column;
    }
    
    .btn-save,
    .btn-preview {
        width: 100%;
    }
}
</style>

<div class="policies-header">
    <div class="policies-title">
        <h1>Property Policies</h1>
        <p>Set rules, requirements, and policies for your properties</p>
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

<?php if ($propertyId > 0 && $currentProperty): ?>

<!-- Message Display -->
<?php if (isset($success)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill"></i>
    <?php echo $success; ?>
    <button onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; color: inherit;"><i class="bi bi-x-lg"></i></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<form method="POST" id="policiesForm">
    <input type="hidden" name="stay_id" value="<?php echo $propertyId; ?>">
    
    <!-- ============================================ -->
    <!-- CHECK-IN/OUT POLICIES -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('checkin')">
            <div class="policy-title">
                <i class="bi bi-clock"></i>
                <h3>Check-in / Check-out</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-checkin"></i>
        </div>
        <div class="policy-content" id="section-checkin">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Check-in from</label>
                    <input type="time" name="check_in_from" class="form-control" 
                           value="<?php echo $policies['check_in']['from'] ?? '14:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Check-in until</label>
                    <input type="time" name="check_in_until" class="form-control" 
                           value="<?php echo $policies['check_in']['until'] ?? '23:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Check-out before</label>
                    <input type="time" name="check_out_before" class="form-control" 
                           value="<?php echo $policies['check_out']['before'] ?? '11:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Minimum stay (nights)</label>
                    <input type="number" name="minimum_stay" class="form-control" 
                           value="<?php echo $currentProperty['min_stay'] ?? 1; ?>" min="1">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum stay (nights)</label>
                    <input type="number" name="maximum_stay" class="form-control" 
                           value="<?php echo $currentProperty['max_stay'] ?? 30; ?>" min="1">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Check-in Instructions</label>
                    <textarea name="check_in_instructions" class="form-control" rows="3" 
                              placeholder="Special instructions for late check-in, door codes, key pickup, etc."><?php echo $policies['check_in']['instructions'] ?? ''; ?></textarea>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Check-out Instructions</label>
                    <textarea name="check_out_instructions" class="form-control" rows="3" 
                              placeholder="Where to leave keys, luggage storage options, etc."><?php echo $policies['check_out']['instructions'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- CHILDREN & EXTRA BEDS -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('children')">
            <div class="policy-title">
                <i class="bi bi-person-hearts"></i>
                <h3>Children & Extra Beds</h3>
            </div>
            <span class="policy-status <?php echo ($policies['children']['allowed'] ?? true) ? 'allowed' : 'not-allowed'; ?>">
                <?php echo ($policies['children']['allowed'] ?? true) ? 'Allowed' : 'Not Allowed'; ?>
            </span>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-children"></i>
        </div>
        <div class="policy-content" id="section-children">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="children_allowed" id="children_allowed" value="1"
                               <?php echo ($policies['children']['allowed'] ?? true) ? 'checked' : ''; ?>>
                        <label for="children_allowed">Children are allowed</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum children allowed</label>
                    <input type="number" name="max_children" class="form-control" 
                           value="<?php echo $policies['children']['max_children'] ?? 3; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Extra bed price (per night)</label>
                    <div class="price-input">
                        <input type="number" name="extra_bed_price" class="form-control" 
                               value="<?php echo $policies['children']['extra_bed_price'] ?? 0; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Crib/Infant bed price</label>
                    <div class="price-input">
                        <input type="number" name="crib_price" class="form-control" 
                               value="<?php echo $policies['children']['crib_price'] ?? 0; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Children Policy</label>
                    <textarea name="children_policy" class="form-control" rows="2" 
                              placeholder="e.g., Children 18 and above will be charged as adults"><?php echo $policies['children']['policy'] ?? 'Children of all ages are welcome.'; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- PETS -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('pets')">
            <div class="policy-title">
                <i class="bi bi-heart"></i>
                <h3>Pets</h3>
            </div>
            <span class="policy-status <?php echo ($policies['pets']['allowed'] ?? false) ? 'allowed' : 'not-allowed'; ?>">
                <?php echo ($policies['pets']['allowed'] ?? false) ? 'Allowed' : 'Not Allowed'; ?>
            </span>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-pets"></i>
        </div>
        <div class="policy-content" id="section-pets">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="pets_allowed" id="pets_allowed" value="1"
                               <?php echo ($policies['pets']['allowed'] ?? false) ? 'checked' : ''; ?>>
                        <label for="pets_allowed">Pets are allowed</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Pet fee (per stay)</label>
                    <div class="price-input">
                        <input type="number" name="pet_fee" class="form-control" 
                               value="<?php echo $policies['pets']['fee'] ?? 0; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum pets</label>
                    <input type="number" name="max_pets" class="form-control" 
                           value="<?php echo $policies['pets']['max_pets'] ?? 1; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Weight limit (kg)</label>
                    <input type="number" name="pet_weight_limit" class="form-control" 
                           value="<?php echo $policies['pets']['weight_limit'] ?? 0; ?>" min="0">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Breed restrictions</label>
                    <input type="text" name="breed_restrictions" class="form-control" 
                           value="<?php echo $policies['pets']['breed_restrictions'] ?? ''; ?>" 
                           placeholder="e.g., No aggressive breeds">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Pet Policy</label>
                    <textarea name="pets_policy" class="form-control" rows="2" 
                              placeholder="Additional rules for pets"><?php echo $policies['pets']['policy'] ?? 'Pets are not allowed.'; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- SMOKING -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('smoking')">
            <div class="policy-title">
                <i class="bi bi-fire"></i>
                <h3>Smoking</h3>
            </div>
            <span class="policy-status <?php echo ($policies['smoking']['allowed'] ?? false) ? 'allowed' : 'not-allowed'; ?>">
                <?php echo ($policies['smoking']['allowed'] ?? false) ? 'Allowed' : 'Not Allowed'; ?>
            </span>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-smoking"></i>
        </div>
        <div class="policy-content" id="section-smoking">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="smoking_allowed" id="smoking_allowed" value="1"
                               <?php echo ($policies['smoking']['allowed'] ?? false) ? 'checked' : ''; ?>>
                        <label for="smoking_allowed">Smoking allowed</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Smoking fine (if violated)</label>
                    <div class="price-input">
                        <input type="number" name="smoking_fine" class="form-control" 
                               value="<?php echo $policies['smoking']['fine'] ?? 50000; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Smoking Policy</label>
                    <textarea name="smoking_policy" class="form-control" rows="2" 
                              placeholder="Designated smoking areas, etc."><?php echo $policies['smoking']['policy'] ?? 'Smoking is not allowed in any indoor areas.'; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- PARTIES & EVENTS -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('parties')">
            <div class="policy-title">
                <i class="bi bi-music-note-beamed"></i>
                <h3>Parties & Events</h3>
            </div>
            <span class="policy-status <?php echo ($policies['parties']['allowed'] ?? false) ? 'allowed' : 'not-allowed'; ?>">
                <?php echo ($policies['parties']['allowed'] ?? false) ? 'Allowed' : 'Not Allowed'; ?>
            </span>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-parties"></i>
        </div>
        <div class="policy-content" id="section-parties">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="parties_allowed" id="parties_allowed" value="1"
                               <?php echo ($policies['parties']['allowed'] ?? false) ? 'checked' : ''; ?>>
                        <label for="parties_allowed">Parties/events allowed</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Noise curfew</label>
                    <input type="time" name="noise_curfew" class="form-control" 
                           value="<?php echo $policies['parties']['noise_curfew'] ?? '22:00'; ?>">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Party Policy</label>
                    <textarea name="parties_policy" class="form-control" rows="2" 
                              placeholder="Rules for events, noise levels, etc."><?php echo $policies['parties']['policy'] ?? 'Parties and events are not allowed.'; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- CANCELLATION POLICY -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('cancellation')">
            <div class="policy-title">
                <i class="bi bi-calendar-x"></i>
                <h3>Cancellation Policy</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-cancellation"></i>
        </div>
        <div class="policy-content" id="section-cancellation">
            <div class="cancellation-cards">
                <?php 
                $currentCancellation = $policies['cancellation']['type'] ?? 'moderate';
                foreach ($cancellationTypes as $key => $label): 
                    $shortLabel = explode(' - ', $label)[0];
                ?>
                <label class="cancellation-card <?php echo $currentCancellation == $key ? 'selected' : ''; ?>">
                    <input type="radio" name="cancellation_type" value="<?php echo $key; ?>" 
                           <?php echo $currentCancellation == $key ? 'checked' : ''; ?>>
                    <div class="cancellation-name"><?php echo $shortLabel; ?></div>
                    <div class="cancellation-desc"><?php echo explode(' - ', $label)[1] ?? ''; ?></div>
                    <span class="cancellation-badge <?php echo $key; ?>"><?php echo $shortLabel; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Free cancellation up to (days before)</label>
                    <input type="number" name="free_cancellation_days" class="form-control" 
                           value="<?php echo $policies['cancellation']['free_cancellation_days'] ?? 1; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Or hours before</label>
                    <input type="number" name="free_cancellation_hours" class="form-control" 
                           value="<?php echo $policies['cancellation']['free_cancellation_hours'] ?? 24; ?>" min="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Penalty after deadline (%)</label>
                    <input type="number" name="penalty_percent" class="form-control" 
                           value="<?php echo $policies['cancellation']['penalty_percent'] ?? 100; ?>" min="0" max="100">
                </div>
                
                <div class="form-group">
                    <label class="form-label">No-show penalty (%)</label>
                    <input type="number" name="no_show_penalty" class="form-control" 
                           value="<?php echo $policies['cancellation']['no_show_penalty'] ?? 100; ?>" min="0" max="100">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Special Conditions</label>
                    <textarea name="cancellation_conditions" class="form-control" rows="2" 
                              placeholder="Any exceptions or special conditions"><?php echo $policies['cancellation']['special_conditions'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- PAYMENT POLICIES -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('payment')">
            <div class="policy-title">
                <i class="bi bi-credit-card"></i>
                <h3>Payment Policies</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-payment"></i>
        </div>
        <div class="policy-content" id="section-payment">
            <div class="form-group">
                <label class="form-label">Accepted Payment Methods</label>
                <div class="payment-grid">
                    <?php foreach ($paymentMethods as $key => $label): ?>
                    <label class="payment-checkbox">
                        <input type="checkbox" name="payment_methods[]" value="<?php echo $key; ?>"
                               <?php echo in_array($key, $policies['payment']['accepted_methods'] ?? ['momo', 'card']) ? 'checked' : ''; ?>>
                        <i class="bi bi-<?php echo $key == 'momo' ? 'phone' : ($key == 'card' ? 'credit-card' : ($key == 'bank' ? 'bank' : 'cash')); ?>"></i>
                        <span><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="deposit_required" id="deposit_required" value="1"
                           <?php echo ($policies['payment']['deposit_required'] ?? false) ? 'checked' : ''; ?>>
                    <label for="deposit_required">Deposit required before arrival</label>
                </div>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Deposit amount</label>
                    <div class="price-input">
                        <input type="number" name="deposit_amount" class="form-control" 
                               value="<?php echo $policies['payment']['deposit_amount'] ?? 0; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Deposit type</label>
                    <select name="deposit_type" class="form-control">
                        <option value="percentage" <?php echo ($policies['payment']['deposit_type'] ?? 'percentage') == 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option>
                        <option value="fixed" <?php echo ($policies['payment']['deposit_type'] ?? '') == 'fixed' ? 'selected' : ''; ?>>Fixed amount (RWF)</option>
                        <option value="first_night" <?php echo ($policies['payment']['deposit_type'] ?? '') == 'first_night' ? 'selected' : ''; ?>>First night</option>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Prepayment Policy</label>
                    <textarea name="prepayment_policy" class="form-control" rows="2" 
                              placeholder="When and how prepayment is collected"><?php echo $policies['payment']['prepayment_policy'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- HOUSE RULES -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('house')">
            <div class="policy-title">
                <i class="bi bi-house-heart"></i>
                <h3>House Rules</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-house"></i>
        </div>
        <div class="policy-content" id="section-house">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Quiet hours from</label>
                    <input type="time" name="quiet_hours_from" class="form-control" 
                           value="<?php echo $policies['house_rules']['quiet_hours_from'] ?? '22:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quiet hours to</label>
                    <input type="time" name="quiet_hours_to" class="form-control" 
                           value="<?php echo $policies['house_rules']['quiet_hours_to'] ?? '08:00'; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Age restriction (minimum age)</label>
                    <input type="number" name="age_restriction" class="form-control" 
                           value="<?php echo $policies['house_rules']['age_restriction'] ?? 0; ?>" min="0">
                    <small class="form-text">0 = no restriction</small>
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Additional House Rules</label>
                    <textarea name="additional_rules" class="form-control" rows="4" 
                              placeholder="List any additional rules guests should know"><?php echo $policies['house_rules']['additional_rules'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- DAMAGE & SECURITY -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('damage')">
            <div class="policy-title">
                <i class="bi bi-shield-shaded"></i>
                <h3>Damage & Security Deposit</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-damage"></i>
        </div>
        <div class="policy-content" id="section-damage">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="damage_deposit_required" id="damage_deposit_required" value="1"
                               <?php echo ($policies['damage']['deposit_required'] ?? false) ? 'checked' : ''; ?>>
                        <label for="damage_deposit_required">Security deposit required</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Deposit amount</label>
                    <div class="price-input">
                        <input type="number" name="damage_deposit_amount" class="form-control" 
                               value="<?php echo $policies['damage']['deposit_amount'] ?? 0; ?>" min="0" step="1000">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Refund timeline (days after check-out)</label>
                    <input type="number" name="deposit_refund_days" class="form-control" 
                           value="<?php echo $policies['damage']['deposit_refund_timeline'] ?? 7; ?>" min="1">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Damage Policy</label>
                    <textarea name="damage_policy" class="form-control" rows="2" 
                              placeholder="Explain how damages are handled"><?php echo $policies['damage']['damage_policy'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- ACCESSIBILITY -->
    <!-- ============================================ -->
    <div class="policy-section">
        <div class="policy-header" onclick="toggleSection('accessibility')">
            <div class="policy-title">
                <i class="bi bi-universal-access"></i>
                <h3>Accessibility</h3>
            </div>
            <i class="bi bi-chevron-down policy-toggle" id="toggle-accessibility"></i>
        </div>
        <div class="policy-content" id="section-accessibility">
            <div class="form-grid">
                <div class="form-group full-width">
                    <div class="checkbox-group">
                        <input type="checkbox" name="wheelchair_accessible" id="wheelchair_accessible" value="1"
                               <?php echo ($policies['accessibility']['wheelchair_accessible'] ?? false) ? 'checked' : ''; ?>>
                        <label for="wheelchair_accessible">Wheelchair accessible</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Number of accessible rooms</label>
                    <input type="number" name="accessible_rooms" class="form-control" 
                           value="<?php echo $policies['accessibility']['accessible_rooms'] ?? 0; ?>" min="0">
                </div>
                
                <div class="form-group full-width">
                    <label class="form-label">Accessibility Features</label>
                    <div class="features-grid">
                        <?php foreach ($accessibilityFeatures as $key => $label): ?>
                        <label class="feature-checkbox">
                            <input type="checkbox" name="accessibility_features[]" value="<?php echo $key; ?>"
                                   <?php echo in_array($key, $policies['accessibility']['accessibility_features'] ?? []) ? 'checked' : ''; ?>>
                            <?php echo $label; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="policy-actions">
        <button type="button" class="btn-preview" onclick="previewPolicies()">
            <i class="bi bi-eye"></i> Preview as Guest
        </button>
        <button type="submit" name="save_policies" class="btn-save">
            <i class="bi bi-check-lg"></i> Save All Policies
        </button>
    </div>
</form>

<?php else: ?>
<div class="empty-state">
    <i class="bi bi-building"></i>
    <h3>Select a property</h3>
    <p>Please select a property from the dropdown above to manage its policies.</p>
</div>
<?php endif; ?>

<!-- Preview Modal -->
<div class="modal" id="previewModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Property Policies Preview</h3>
            <button class="modal-close" onclick="closeModal('previewModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="previewContent">
            <!-- Content will be loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal('previewModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ============================================
// PROPERTY SELECTION
// ============================================
function changeProperty(propertyId) {
    if (propertyId) {
        window.location.href = 'policies.php?property=' + propertyId;
    }
}

// ============================================
// TOGGLE SECTIONS
// ============================================
function toggleSection(section) {
    const content = document.getElementById(`section-${section}`);
    const toggle = document.getElementById(`toggle-${section}`);
    
    if (content.classList.contains('show')) {
        content.classList.remove('show');
        toggle.classList.remove('open');
    } else {
        content.classList.add('show');
        toggle.classList.add('open');
    }
}

// Open first section by default
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        toggleSection('checkin');
    }, 100);
});

// ============================================
// PREVIEW POLICIES
// ============================================
function previewPolicies() {
    // Collect form data
    const formData = new FormData(document.getElementById('policiesForm'));
    
    // Build preview HTML
    let html = `
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--booking-blue); margin-bottom: 16px;">Check-in/Check-out</h4>
            <p><strong>Check-in:</strong> ${formData.get('check_in_from')} - ${formData.get('check_in_until')}</p>
            <p><strong>Check-out:</strong> Before ${formData.get('check_out_before')}</p>
            <p><strong>Minimum Stay:</strong> ${formData.get('minimum_stay')} nights</p>
            <p><strong>Maximum Stay:</strong> ${formData.get('maximum_stay')} nights</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--booking-blue); margin-bottom: 16px;">Children & Beds</h4>
            <p>${formData.get('children_allowed') ? 'Children allowed' : 'Children not allowed'}</p>
            <p>Extra bed: RWF ${parseInt(formData.get('extra_bed_price')).toLocaleString()}/night</p>
            <p>Crib: RWF ${parseInt(formData.get('crib_price')).toLocaleString()}/night</p>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--booking-blue); margin-bottom: 16px;">Pets</h4>
            <p>${formData.get('pets_allowed') ? 'Pets allowed' : 'Pets not allowed'}</p>
            ${formData.get('pets_allowed') ? `
                <p>Pet fee: RWF ${parseInt(formData.get('pet_fee')).toLocaleString()}</p>
                <p>Max pets: ${formData.get('max_pets')}</p>
            ` : ''}
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--booking-blue); margin-bottom: 16px;">Smoking</h4>
            <p>${formData.get('smoking_allowed') ? 'Smoking allowed' : 'Smoking not allowed'}</p>
            ${!formData.get('smoking_allowed') ? `<p>Fine for smoking: RWF ${parseInt(formData.get('smoking_fine')).toLocaleString()}</p>` : ''}
        </div>
        
        <div style="margin-bottom: 20px;">
            <h4 style="color: var(--booking-blue); margin-bottom: 16px;">Cancellation Policy</h4>
            <p><strong>Type:</strong> ${formData.get('cancellation_type')}</p>
            <p>Free cancellation up to ${formData.get('free_cancellation_days')} days before check-in</p>
        </div>
    `;
    
    document.getElementById('previewContent').innerHTML = html;
    openModal('previewModal');
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
</script>

<?php require_once 'includes/stays_footer.php'; ?>