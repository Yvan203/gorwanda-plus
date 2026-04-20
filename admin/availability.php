<?php
$attractionId = isset($_GET['attraction_id']) ? intval($_GET['attraction_id']) : 0;

if (!$attractionId) {
    header('Location: attractions.php');
    exit;
}

$pageTitle = 'Availability Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Get attraction details
$stmt = $db->prepare("
    SELECT a.attraction_name, a.slug, 
           COUNT(DISTINCT t.tier_id) as tier_count
    FROM attractions a
    LEFT JOIN attraction_tiers t ON a.attraction_id = t.attraction_id
    WHERE a.attraction_id = ?
    GROUP BY a.attraction_id
");
$stmt->execute([$attractionId]);
$attraction = $stmt->fetch();

if (!$attraction) {
    header('Location: attractions.php');
    exit;
}

// Get all tiers for this attraction
$stmt = $db->prepare("
    SELECT tier_id, tier_name, base_price, max_participants, is_active
    FROM attraction_tiers
    WHERE attraction_id = ? AND is_active = 1
    ORDER BY base_price ASC
");
$stmt->execute([$attractionId]);
$tiers = $stmt->fetchAll();

// Handle bulk availability update
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_availability') {
    $availabilityData = $_POST['availability'];
    $updated = 0;
    $deleted = 0;
    
    foreach ($availabilityData as $tierId => $dates) {
        foreach ($dates as $date => $data) {
            // Check if this record should be deleted (empty data)
            $max_bookings = isset($data['max_bookings']) ? intval($data['max_bookings']) : null;
            $price_override = !empty($data['price_override']) ? floatval($data['price_override']) : null;
            $is_blocked = isset($data['is_blocked']) ? 1 : 0;
            $notes = sanitize($data['notes'] ?? '');
            
            // If max_bookings is empty and no other special settings, delete the record
            if (empty($max_bookings) && empty($price_override) && !$is_blocked && empty($notes)) {
                $stmt = $db->prepare("DELETE FROM attraction_availability WHERE tier_id = ? AND date = ?");
                if ($stmt->execute([$tierId, $date])) {
                    $deleted++;
                }
            } else {
                // Insert or update
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, max_bookings, price_override, is_blocked, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        max_bookings = VALUES(max_bookings),
                        price_override = VALUES(price_override),
                        is_blocked = VALUES(is_blocked),
                        notes = VALUES(notes)
                ");
                if ($stmt->execute([$tierId, $date, $max_bookings, $price_override, $is_blocked, $notes])) {
                    $updated++;
                }
            }
        }
    }
    
    $_SESSION['success'] = "$updated records updated, $deleted records removed";
    header("Location: availability.php?attraction_id=$attractionId");
    exit;
}

// Handle date range block
if ($action === 'block_range' && isset($_POST['block_range'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $tier_ids = isset($_POST['tier_ids']) ? $_POST['tier_ids'] : [];
    $block_reason = sanitize($_POST['block_reason'] ?? 'Blocked');
    
    if (!empty($tier_ids) && $start_date && $end_date) {
        $currentDate = new DateTime($start_date);
        $endDateObj = new DateTime($end_date);
        $updated = 0;
        
        while ($currentDate <= $endDateObj) {
            $date = $currentDate->format('Y-m-d');
            foreach ($tier_ids as $tierId) {
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, is_blocked, notes)
                    VALUES (?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        is_blocked = 1,
                        notes = CONCAT(notes, ' | ', VALUES(notes))
                ");
                if ($stmt->execute([$tierId, $date, $block_reason])) {
                    $updated++;
                }
            }
            $currentDate->modify('+1 day');
        }
        
        $_SESSION['success'] = "Blocked $updated availability records";
        header("Location: availability.php?attraction_id=$attractionId");
        exit;
    }
}

// Handle bulk price override
if ($action === 'price_override_range' && isset($_POST['price_override_range'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $tier_ids = isset($_POST['tier_ids']) ? $_POST['tier_ids'] : [];
    $price_override = floatval($_POST['price_override'] ?? 0);
    
    if (!empty($tier_ids) && $start_date && $end_date && $price_override > 0) {
        $currentDate = new DateTime($start_date);
        $endDateObj = new DateTime($end_date);
        $updated = 0;
        
        while ($currentDate <= $endDateObj) {
            $date = $currentDate->format('Y-m-d');
            foreach ($tier_ids as $tierId) {
                $stmt = $db->prepare("
                    INSERT INTO attraction_availability (tier_id, date, price_override)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        price_override = VALUES(price_override)
                ");
                if ($stmt->execute([$tierId, $date, $price_override])) {
                    $updated++;
                }
            }
            $currentDate->modify('+1 day');
        }
        
        $_SESSION['success'] = "Price override applied to $updated records";
        header("Location: availability.php?attraction_id=$attractionId");
        exit;
    }
}

// Get availability data for next 90 days
$availabilityData = [];
$bookingsData = [];
$currentDate = new DateTime();
$endDate = clone $currentDate;
$endDate->modify('+90 days');

if (!empty($tiers)) {
    $tierIds = array_column($tiers, 'tier_id');
    $placeholders = implode(',', array_fill(0, count($tierIds), '?'));
    
    // Get availability
    $stmt = $db->prepare("
        SELECT tier_id, date, max_bookings, bookings_made, price_override, is_blocked, notes
        FROM attraction_availability
        WHERE tier_id IN ($placeholders) AND date >= CURDATE() AND date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ORDER BY date ASC
    ");
    $stmt->execute($tierIds);
    $availabilities = $stmt->fetchAll();
    
    foreach ($availabilities as $avail) {
        $availabilityData[$avail['tier_id']][$avail['date']] = $avail;
    }
    
    // Get bookings count per date per tier
    $stmt = $db->prepare("
        SELECT at.tier_id, b.experience_date as date, COUNT(*) as booked
        FROM bookings b
        LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
        WHERE at.attraction_id = ? AND b.booking_type = 'attraction' 
        AND b.status IN ('confirmed', 'completed')
        AND b.experience_date >= CURDATE() AND b.experience_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        GROUP BY at.tier_id, b.experience_date
    ");
    $stmt->execute([$attractionId]);
    $bookings = $stmt->fetchAll();
    
    foreach ($bookings as $booking) {
        $bookingsData[$booking['tier_id']][$booking['date']] = $booking['booked'];
    }
}

// Generate calendar dates
$dates = [];
$current = clone $currentDate;
for ($i = 0; $i < 90; $i++) {
    $dates[] = clone $current;
    $current->modify('+1 day');
}

// Get months for grouping
$months = [];
foreach ($dates as $date) {
    $monthKey = $date->format('Y-m');
    if (!isset($months[$monthKey])) {
        $months[$monthKey] = [
            'name' => $date->format('F Y'),
            'start_index' => count($months) * 31,
            'days' => []
        ];
    }
}

// Get summary statistics
$totalDays = 90;
$blockedDays = 0;
$fullyBookedDays = 0;
$specialPrices = 0;

foreach ($availabilityData as $tierId => $tierAvail) {
    foreach ($tierAvail as $date => $avail) {
        if ($avail['is_blocked']) $blockedDays++;
        if ($avail['max_bookings'] && $avail['bookings_made'] >= $avail['max_bookings']) $fullyBookedDays++;
        if ($avail['price_override']) $specialPrices++;
    }
}
?>

<style>
/* Availability Management Styles */
.availability-header {
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

.attraction-info-bar {
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

.attraction-info h2 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.attraction-info p {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Stats Cards */
.availability-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
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

/* Action Cards */
.action-cards {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

.action-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 16px;
}

.action-card h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.action-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}

.action-group {
    flex: 1;
    min-width: 120px;
}

.action-group label {
    display: block;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.action-group select,
.action-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.action-btn {
    padding: 8px 16px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    cursor: pointer;
}

/* Calendar Container */
.calendar-container {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    overflow-x: auto;
    margin-bottom: 24px;
}

.calendar-toolbar {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.calendar-title {
    font-size: 0.875rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.tier-selector {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.tier-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.6875rem;
}

/* Calendar Table */
.calendar-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.6875rem;
    min-width: 800px;
}

.calendar-table th {
    padding: 12px 8px;
    text-align: center;
    background: var(--booking-gray-light);
    border: 1px solid var(--booking-border);
    font-weight: 600;
    position: sticky;
    top: 0;
}

.calendar-table td {
    padding: 8px;
    text-align: center;
    border: 1px solid var(--booking-border);
    vertical-align: top;
    min-width: 100px;
}

.date-cell {
    background: var(--booking-gray-light);
    font-weight: 600;
    width: 80px;
}

.weekend {
    background: rgba(255,140,0,0.05);
}

.tier-cell {
    transition: all var(--transition-fast);
}

.tier-cell:hover {
    background: var(--booking-gray-light);
}

.availability-input {
    width: 60px;
    padding: 4px;
    text-align: center;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    margin-bottom: 4px;
}

.price-input {
    width: 80px;
    padding: 4px;
    text-align: center;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    margin-bottom: 4px;
}

.notes-input {
    width: 100%;
    padding: 4px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.5625rem;
    margin-top: 4px;
}

.blocked-checkbox {
    width: 16px;
    height: 16px;
    cursor: pointer;
    margin-top: 4px;
}

.blocked-cell {
    background: #fce8e8;
}

.fully-booked {
    background: #fff4e6;
}

.booked-count {
    font-size: 0.5625rem;
    color: var(--booking-warning);
    margin-top: 2px;
}

.price-override {
    font-size: 0.5625rem;
    color: var(--booking-success);
    margin-top: 2px;
}

/* Form Actions */
.form-actions {
    padding: 16px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.save-btn {
    padding: 10px 24px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
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

/* Responsive */
@media (max-width: 1024px) {
    .availability-stats {
        grid-template-columns: repeat(3, 1fr);
    }
    .action-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .availability-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    .action-form {
        flex-direction: column;
    }
    .action-group {
        width: 100%;
    }
}
</style>

<div class="availability-header">
    <a href="attraction-detail.php?id=<?php echo $attractionId; ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Experience Details
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<div class="attraction-info-bar">
    <div class="attraction-info">
        <h2><?php echo sanitize($attraction['attraction_name']); ?></h2>
        <p><i class="bi bi-calendar3"></i> Availability Management - Next 90 Days</p>
    </div>
</div>

<!-- Statistics -->
<div class="availability-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo $attraction['tier_count']; ?></div>
        <div class="stat-label">Active Tiers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $totalDays; ?></div>
        <div class="stat-label">Days Shown</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $blockedDays; ?></div>
        <div class="stat-label">Blocked Days</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $fullyBookedDays; ?></div>
        <div class="stat-label">Fully Booked</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $specialPrices; ?></div>
        <div class="stat-label">Special Prices</div>
    </div>
</div>

<!-- Bulk Actions -->
<div class="action-cards">
    <!-- Block Date Range -->
    <div class="action-card">
        <h3><i class="bi bi-ban"></i> Block Date Range</h3>
        <form method="POST" action="availability.php?attraction_id=<?php echo $attractionId; ?>" class="action-form">
            <input type="hidden" name="action" value="block_range">
            <div class="action-group">
                <label>Start Date</label>
                <input type="date" name="start_date" required>
            </div>
            <div class="action-group">
                <label>End Date</label>
                <input type="date" name="end_date" required>
            </div>
            <div class="action-group">
                <label>Block Reason</label>
                <input type="text" name="block_reason" placeholder="e.g., Holiday closure">
            </div>
            <div class="action-group">
                <label>Tiers</label>
                <select name="tier_ids[]" multiple size="3" style="height: auto;">
                    <?php foreach ($tiers as $tier): ?>
                    <option value="<?php echo $tier['tier_id']; ?>"><?php echo sanitize($tier['tier_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="font-size: 0.5625rem;">Hold Ctrl to select multiple</small>
            </div>
            <button type="submit" class="action-btn">Block Selected Range</button>
        </form>
    </div>
    
    <!-- Price Override Range -->
    <div class="action-card">
        <h3><i class="bi bi-cash-stack"></i> Price Override Range</h3>
        <form method="POST" action="availability.php?attraction_id=<?php echo $attractionId; ?>" class="action-form">
            <input type="hidden" name="action" value="price_override_range">
            <div class="action-group">
                <label>Start Date</label>
                <input type="date" name="start_date" required>
            </div>
            <div class="action-group">
                <label>End Date</label>
                <input type="date" name="end_date" required>
            </div>
            <div class="action-group">
                <label>Price Override (RWF)</label>
                <input type="number" name="price_override" step="1000" min="0" required>
            </div>
            <div class="action-group">
                <label>Tiers</label>
                <select name="tier_ids[]" multiple size="3" style="height: auto;">
                    <?php foreach ($tiers as $tier): ?>
                    <option value="<?php echo $tier['tier_id']; ?>"><?php echo sanitize($tier['tier_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="font-size: 0.5625rem;">Hold Ctrl to select multiple</small>
            </div>
            <button type="submit" class="action-btn">Apply Price Override</button>
        </form>
    </div>
</div>

<!-- Calendar -->
<form method="POST" action="availability.php?attraction_id=<?php echo $attractionId; ?>" id="availabilityForm">
    <input type="hidden" name="action" value="update_availability">
    
    <div class="calendar-container">
        <div class="calendar-toolbar">
            <div class="calendar-title">
                <i class="bi bi-calendar3"></i>
                Availability Calendar (Next 90 Days)
            </div>
            <div class="tier-selector">
                <label class="tier-checkbox">
                    <input type="checkbox" id="selectAllTiers" checked> Select All
                </label>
                <?php foreach ($tiers as $index => $tier): ?>
                <label class="tier-checkbox">
                    <input type="checkbox" class="tier-check" data-tier-id="<?php echo $tier['tier_id']; ?>" data-tier-index="<?php echo $index; ?>" checked>
                    <?php echo sanitize($tier['tier_name']); ?> (<?php echo formatPrice($tier['base_price']); ?>)
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table class="calendar-table" id="availabilityCalendar">
                <thead>
                    <tr>
                        <th class="date-cell">Date</th>
                        <?php foreach ($tiers as $tier): ?>
                        <th>
                            <?php echo sanitize($tier['tier_name']); ?><br>
                            <small style="font-weight: normal;">Max: <?php echo $tier['max_participants'] ?: '∞'; ?></small>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dates as $date): 
                        $dateStr = $date->format('Y-m-d');
                        $dayOfWeek = $date->format('w');
                        $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                    ?>
                    <tr class="<?php echo $isWeekend ? 'weekend' : ''; ?>">
                        <td class="date-cell">
                            <strong><?php echo $date->format('d M'); ?></strong><br>
                            <small><?php echo $date->format('D'); ?></small>
                        </td>
                        <?php foreach ($tiers as $tier): 
                            $avail = $availabilityData[$tier['tier_id']][$dateStr] ?? null;
                            $maxBookings = $avail['max_bookings'] ?? $tier['max_participants'];
                            $bookingsMade = $bookingsData[$tier['tier_id']][$dateStr] ?? 0;
                            $priceOverride = $avail['price_override'] ?? null;
                            $isBlocked = $avail['is_blocked'] ?? false;
                            $notes = $avail['notes'] ?? '';
                            $remainingSpots = $maxBookings ? $maxBookings - $bookingsMade : null;
                            $isFullyBooked = $maxBookings && $bookingsMade >= $maxBookings;
                        ?>
                        <td class="tier-cell <?php echo $isBlocked ? 'blocked-cell' : ($isFullyBooked ? 'fully-booked' : ''); ?>" 
                            data-tier-id="<?php echo $tier['tier_id']; ?>" 
                            data-date="<?php echo $dateStr; ?>">
                            <input type="number" 
                                   name="availability[<?php echo $tier['tier_id']; ?>][<?php echo $dateStr; ?>][max_bookings]" 
                                   class="availability-input" 
                                   value="<?php echo $maxBookings; ?>" 
                                   min="0"
                                   placeholder="Max"
                                   <?php echo $isBlocked ? 'disabled' : ''; ?>>
                            
                            <input type="number" 
                                   name="availability[<?php echo $tier['tier_id']; ?>][<?php echo $dateStr; ?>][price_override]" 
                                   class="price-input" 
                                   value="<?php echo $priceOverride; ?>" 
                                   step="1000"
                                   placeholder="Price"
                                   <?php echo $isBlocked ? 'disabled' : ''; ?>>
                            
                            <div class="booked-count">
                                <?php if ($bookingsMade > 0): ?>
                                <i class="bi bi-people"></i> <?php echo $bookingsMade; ?> booked
                                <?php endif; ?>
                                <?php if ($remainingSpots !== null && $remainingSpots <= 3 && $remainingSpots > 0): ?>
                                <span style="color: var(--booking-warning);">(<?php echo $remainingSpots; ?> left)</span>
                                <?php endif; ?>
                            </div>
                            
                            <label style="display: flex; align-items: center; justify-content: center; gap: 4px; margin-top: 4px;">
                                <input type="checkbox" 
                                       name="availability[<?php echo $tier['tier_id']; ?>][<?php echo $dateStr; ?>][is_blocked]" 
                                       class="blocked-checkbox" 
                                       value="1"
                                       <?php echo $isBlocked ? 'checked' : ''; ?>
                                       onchange="toggleBlocked(this)">
                                <span style="font-size: 0.5625rem;">Blocked</span>
                            </label>
                            
                            <input type="text" 
                                   name="availability[<?php echo $tier['tier_id']; ?>][<?php echo $dateStr; ?>][notes]" 
                                   class="notes-input" 
                                   value="<?php echo htmlspecialchars($notes); ?>" 
                                   placeholder="Notes"
                                   <?php echo $isBlocked ? 'disabled' : ''; ?>>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="save-btn">
                <i class="bi bi-save"></i> Save All Changes
            </button>
        </div>
    </div>
</form>

<script>
// Toggle blocked state for inputs
function toggleBlocked(checkbox) {
    const cell = checkbox.closest('.tier-cell');
    const inputs = cell.querySelectorAll('input:not(.blocked-checkbox)');
    inputs.forEach(input => {
        input.disabled = checkbox.checked;
    });
    
    if (checkbox.checked) {
        cell.classList.add('blocked-cell');
    } else {
        cell.classList.remove('blocked-cell');
    }
}

// Tier visibility toggle
document.querySelectorAll('.tier-check').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const tierId = this.dataset.tierId;
        const tierIndex = this.dataset.tierIndex;
        const columns = document.querySelectorAll(`.calendar-table th:nth-child(${parseInt(tierIndex) + 2})`);
        const rows = document.querySelectorAll(`.calendar-table td.tier-cell[data-tier-id="${tierId}"]`);
        
        columns.forEach(col => {
            col.style.display = this.checked ? '' : 'none';
        });
        
        rows.forEach(row => {
            row.style.display = this.checked ? '' : 'none';
        });
    });
});

// Select all tiers
document.getElementById('selectAllTiers')?.addEventListener('change', function() {
    const allTiers = document.querySelectorAll('.tier-check');
    allTiers.forEach(checkbox => {
        checkbox.checked = this.checked;
        checkbox.dispatchEvent(new Event('change'));
    });
});

// Initialize - make sure all tiers are visible
document.querySelectorAll('.tier-check').forEach(checkbox => {
    if (checkbox.checked) {
        checkbox.dispatchEvent(new Event('change'));
    }
});

// Auto-save indicator
let hasChanges = false;
document.querySelectorAll('#availabilityForm input, #availabilityForm select, #availabilityForm textarea').forEach(input => {
    input.addEventListener('change', function() {
        hasChanges = true;
    });
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return e.returnValue;
    }
});

// Reset hasChanges after form submit
document.getElementById('availabilityForm')?.addEventListener('submit', function() {
    hasChanges = false;
});
</script>

<?php require_once 'includes/admin_footer.php'; ?>