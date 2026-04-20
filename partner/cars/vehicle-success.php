<?php
$pageTitle = 'Vehicle Added Successfully';
require_once 'includes/cars_header.php';

$vehicleId = intval($_GET['id'] ?? 0);
$db = getDB();

// Get vehicle details
$stmt = $db->prepare("
    SELECT cf.brand, cf.model, cr.company_name
    FROM car_fleet cf
    JOIN car_rentals cr ON cf.rental_id = cr.rental_id
    WHERE cf.car_id = ? AND cr.owner_id = ?
");
$stmt->execute([$vehicleId, $_SESSION['user_id']]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    header('Location: fleet.php');
    exit;
}
?>

<style>
.success-container {
    max-width: 600px;
    margin: 40px auto;
    text-align: center;
    padding: 40px;
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-gray);
    box-shadow: var(--shadow-md);
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--cars-success);
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
    color: var(--text-dark);
    margin-bottom: 10px;
}

.success-message {
    font-size: 1rem;
    color: var(--text-light);
    margin-bottom: 30px;
}

.success-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-success {
    background: var(--cars-primary);
    color: white;
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-success:hover {
    background: var(--cars-dark);
}

.btn-outline-success {
    background: white;
    color: var(--cars-primary);
    border: 1px solid var(--cars-primary);
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-outline-success:hover {
    background: var(--cars-light);
}

@media (max-width: 768px) {
    .success-actions {
        flex-direction: column;
    }
    
    .btn-success,
    .btn-outline-success {
        width: 100%;
    }
}
</style>

<div class="success-container">
    <div class="success-icon">
        <i class="bi bi-check-lg"></i>
    </div>
    
    <h1 class="success-title">Vehicle Added Successfully!</h1>
    <p class="success-message">
        <strong><?php echo sanitize($vehicle['brand'] . ' ' . $vehicle['model']); ?></strong> 
        has been added to your fleet at <?php echo sanitize($vehicle['company_name']); ?>.
    </p>
    
    <div class="success-actions">
        <a href="fleet.php" class="btn-success">
            <i class="bi bi-car-front"></i> View My Fleet
        </a>
        <a href="photos.php?vehicle=<?php echo $vehicleId; ?>" class="btn-outline-success">
            <i class="bi bi-images"></i> Manage Photos
        </a>
        <a href="add-vehicle.php" class="btn-outline-success">
            <i class="bi bi-plus-lg"></i> Add Another
        </a>
    </div>
</div>

<?php require_once 'includes/cars_footer.php'; ?>