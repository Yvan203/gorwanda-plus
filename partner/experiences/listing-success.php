<?php
$pageTitle = 'Experience Added Successfully';
require_once 'includes/experiences_header.php';

$experienceId = intval($_GET['id'] ?? 0);
$db = getDB();

// Get experience details
$stmt = $db->prepare("SELECT attraction_name FROM attractions WHERE attraction_id = ? AND owner_id = ?");
$stmt->execute([$experienceId, $_SESSION['user_id']]);
$experience = $stmt->fetch();

if (!$experience) {
    header('Location: listings.php');
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
    border-radius: var(--radius-lg);
    border: 1px solid var(--exp-border);
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--exp-success);
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
    color: var(--exp-text);
    margin-bottom: 10px;
}

.success-message {
    font-size: 1rem;
    color: var(--exp-text-light);
    margin-bottom: 30px;
}

.success-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-success {
    background: var(--exp-purple);
    color: white;
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-success:hover {
    background: var(--exp-dark-purple);
}

.btn-outline-success {
    background: white;
    color: var(--exp-purple);
    border: 1px solid var(--exp-purple);
    padding: 12px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-outline-success:hover {
    background: var(--exp-light-purple);
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
    
    <h1 class="success-title">Experience Added Successfully!</h1>
    <p class="success-message">
        <strong><?php echo sanitize($experience['attraction_name']); ?></strong> has been created and is pending verification.
    </p>
    
    <div class="success-actions">
        <a href="listings.php" class="btn-success">
            <i class="bi bi-list"></i> View All Experiences
        </a>
        <a href="tiers.php?experience=<?php echo $experienceId; ?>" class="btn-outline-success">
            <i class="bi bi-layers"></i> Manage Pricing Tiers
        </a>
        <a href="photos.php?experience=<?php echo $experienceId; ?>" class="btn-outline-success">
            <i class="bi bi-images"></i> Manage Photos
        </a>
        <a href="dashboard.php" class="btn-outline-success">
            <i class="bi bi-speedometer2"></i> Go to Dashboard
        </a>
    </div>
</div>

<?php require_once 'includes/experiences_footer.php'; ?>