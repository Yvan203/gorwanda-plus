<?php
$pageTitle = 'Property Added Successfully';
require_once 'includes/stays_header.php';

$propertyId = intval($_GET['id'] ?? 0);
$db = getDB();

// Get property details
$stmt = $db->prepare("SELECT stay_name FROM stays WHERE stay_id = ? AND owner_id = ?");
$stmt->execute([$propertyId, $_SESSION['user_id']]);
$property = $stmt->fetch();

if (!$property) {
    header('Location: properties.php');
    exit;
}
?>

<style>
.success-container {
    max-width: 600px;
    margin: 50px auto;
    text-align: center;
    padding: 40px;
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--booking-success);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 20px;
}

.success-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 10px;
}

.success-message {
    font-size: 1rem;
    color: var(--booking-text-light);
    margin-bottom: 30px;
}

.success-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
}

.btn-success {
    background: var(--booking-blue);
    color: white;
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-success:hover {
    background: var(--booking-dark-blue);
}

.btn-outline-success {
    background: white;
    color: var(--booking-blue);
    border: 1px solid var(--booking-blue);
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-outline-success:hover {
    background: var(--booking-light-blue);
}

@media (max-width: 768px) {
    .success-actions {
        flex-direction: column;
    }
}
</style>

<div class="success-container">
    <div class="success-icon">
        <i class="bi bi-check-lg"></i>
    </div>
    
    <h1 class="success-title">Property Added Successfully!</h1>
    <p class="success-message">
        <strong><?php echo sanitize($property['stay_name']); ?></strong> has been submitted for review.
        Our team will verify your property within 24-48 hours.
    </p>
    
    <div class="success-actions">
        <a href="properties.php" class="btn-success">
            <i class="bi bi-building"></i> View My Properties
        </a>
        <a href="rooms.php?property=<?php echo $propertyId; ?>" class="btn-outline-success">
            <i class="bi bi-door-open"></i> Manage Rooms
        </a>
        <a href="dashboard.php" class="btn-outline-success">
            <i class="bi bi-speedometer2"></i> Go to Dashboard
        </a>
    </div>
</div>

<?php require_once 'includes/stays_footer.php'; ?>