<?php
$pageTitle = 'Backup Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Define backup directory
$backupDir = $_SERVER['DOCUMENT_ROOT'] . '/gorwanda-plus/backups/';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Handle backup actions
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$backupFile = isset($_GET['file']) ? sanitize($_GET['file']) : '';

// Create database backup
if ($action === 'create_backup') {
    $backupType = isset($_GET['type']) ? $_GET['type'] : 'full';
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;

    try {
        // Get all tables
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $output = "-- GoRwanda+ Database Backup\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Backup Type: " . strtoupper($backupType) . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Skip certain tables if not full backup
            if ($backupType === 'structure' && in_array($table, ['activity_logs', 'sessions'])) {
                continue;
            }

            // Get create table syntax
            $stmt = $db->prepare("SHOW CREATE TABLE `$table`");
            $stmt->execute();
            $createTable = $stmt->fetch(PDO::FETCH_ASSOC);
            $output .= "\n-- Table structure for table `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $createTable['Create Table'] . ";\n\n";

            // If full backup, get data
            if ($backupType === 'full' || ($backupType === 'data' && !in_array($table, ['activity_logs', 'sessions']))) {
                $stmt = $db->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    $output .= "-- Dumping data for table `$table`\n";

                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $values = array_map(function ($value) use ($db) {
                            if ($value === null) return 'NULL';
                            return $db->quote($value);
                        }, array_values($row));

                        $output .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $output .= "\n";
                }
            }
        }

        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Write to file
        file_put_contents($filepath, $output);

        // Compress if requested
        if (isset($_GET['compress']) && $_GET['compress'] === 'yes') {
            $gzFilepath = $filepath . '.gz';
            $gzData = gzencode($output, 9);
            file_put_contents($gzFilepath, $gzData);
            unlink($filepath);
            $filename .= '.gz';
            $filepath = $gzFilepath;
        }

        $_SESSION['success'] = "Backup created successfully: " . $filename;
        header('Location: backup.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = "Backup failed: " . $e->getMessage();
        header('Location: backup.php');
        exit;
    }
}

// Download backup
if ($action === 'download' && $backupFile) {
    $filepath = $backupDir . $backupFile;
    if (file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backupFile . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $_SESSION['error'] = "Backup file not found";
        header('Location: backup.php');
        exit;
    }
}

// Restore backup
if ($action === 'restore' && $backupFile) {
    $filepath = $backupDir . $backupFile;
    if (file_exists($filepath)) {
        // Handle compressed files
        if (pathinfo($filepath, PATHINFO_EXTENSION) === 'gz') {
            $content = gzdecode(file_get_contents($filepath));
        } else {
            $content = file_get_contents($filepath);
        }

        try {
            // Split queries
            $queries = explode(";\n", $content);
            $db->exec("SET FOREIGN_KEY_CHECKS=0");

            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query) && !preg_match('/^--/', $query)) {
                    try {
                        $db->exec($query);
                    } catch (PDOException $e) {
                        // Log error but continue
                        error_log("Restore error: " . $e->getMessage());
                    }
                }
            }
            $db->exec("SET FOREIGN_KEY_CHECKS=1");

            $_SESSION['success'] = "Database restored successfully from: " . $backupFile;
        } catch (Exception $e) {
            $_SESSION['error'] = "Restore failed: " . $e->getMessage();
        }
        header('Location: backup.php');
        exit;
    } else {
        $_SESSION['error'] = "Backup file not found";
        header('Location: backup.php');
        exit;
    }
}

// Delete backup
if ($action === 'delete' && $backupFile) {
    $filepath = $backupDir . $backupFile;
    if (file_exists($filepath) && unlink($filepath)) {
        $_SESSION['success'] = "Backup file deleted: " . $backupFile;
    } else {
        $_SESSION['error'] = "Failed to delete backup file";
    }
    header('Location: backup.php');
    exit;
}

// Get all backup files
$backups = [];
$files = glob($backupDir . '*.{sql,sql.gz}', GLOB_BRACE);
foreach ($files as $file) {
    $filename = basename($file);
    $stat = stat($file);
    $backups[] = [
        'name' => $filename,
        'size' => $stat['size'],
        'modified' => date('Y-m-d H:i:s', $stat['mtime']),
        'type' => pathinfo($filename, PATHINFO_EXTENSION) === 'gz' ? 'Compressed' : 'SQL',
        'is_compressed' => pathinfo($filename, PATHINFO_EXTENSION) === 'gz'
    ];
}

// Sort by modified date (newest first)
usort($backups, function ($a, $b) {
    return strtotime($b['modified']) - strtotime($a['modified']);
});

// Get all tables directly from the database
$tables = [];
$stmt = $db->query("SHOW TABLES");
$tableNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tableNames as $tableName) {
    // Get row count
    $countStmt = $db->query("SELECT COUNT(*) FROM `$tableName`");
    $rowCount = $countStmt->fetchColumn();

    $tables[] = [
        'name' => $tableName,
        'rows' => $rowCount,
        'size' => 'N/A' // MySQL doesn't provide easy size info without information_schema
    ];
}

// Get total table count
$totalTables = count($tables);
$totalRows = array_sum(array_column($tables, 'rows'));
?>

<style>
    /* Backup Page Styles */
    .backup-stats {
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
        font-size: 1.5rem;
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
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 24px;
    }

    .action-card {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        padding: 20px;
        text-align: center;
    }

    .action-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(0, 102, 255, 0.1);
        color: var(--booking-blue);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        margin-bottom: 16px;
    }

    .action-card h3 {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .action-card p {
        font-size: 0.75rem;
        color: var(--booking-text-light);
        margin-bottom: 20px;
    }

    .backup-options {
        display: flex;
        gap: 8px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .backup-btn {
        padding: 8px 16px;
        background: var(--booking-blue);
        color: var(--booking-white);
        border: none;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .backup-btn:hover {
        background: var(--booking-blue-dark);
    }

    .backup-btn.secondary {
        background: var(--booking-gray-light);
        color: var(--booking-text);
    }

    /* Tables Section */
    .tables-section {
        background: var(--booking-white);
        border-radius: var(--radius-md);
        border: 1px solid var(--booking-border);
        margin-bottom: 24px;
        overflow: hidden;
    }

    .section-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--booking-border);
        background: var(--booking-gray-light);
    }

    .section-header h3 {
        font-size: 0.875rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tables-table {
        width: 100%;
        border-collapse: collapse;
    }

    .tables-table th {
        text-align: left;
        padding: 12px 16px;
        font-size: 0.6875rem;
        font-weight: 600;
        color: var(--booking-text-light);
        background: var(--booking-gray-light);
        border-bottom: 1px solid var(--booking-border);
    }

    .tables-table td {
        padding: 10px 16px;
        border-bottom: 1px solid var(--booking-border);
        font-size: 0.75rem;
    }

    /* Backups Table */
    .backups-table {
        width: 100%;
        border-collapse: collapse;
    }

    .backups-table th {
        text-align: left;
        padding: 12px 16px;
        font-size: 0.6875rem;
        font-weight: 600;
        color: var(--booking-text-light);
        background: var(--booking-gray-light);
        border-bottom: 1px solid var(--booking-border);
    }

    .backups-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--booking-border);
        font-size: 0.75rem;
        vertical-align: middle;
    }

    .backup-size {
        font-family: monospace;
        font-size: 0.75rem;
    }

    .backup-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .backup-action-btn {
        padding: 4px 8px;
        border-radius: var(--radius-sm);
        font-size: 0.625rem;
        cursor: pointer;
        transition: all var(--transition-fast);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: var(--booking-white);
        border: 1px solid var(--booking-border);
        color: var(--booking-text);
    }

    .backup-action-btn:hover {
        background: var(--booking-gray-light);
    }

    .backup-action-btn.danger {
        color: var(--booking-danger);
        border-color: rgba(226, 17, 17, 0.3);
    }

    .backup-action-btn.danger:hover {
        background: rgba(226, 17, 17, 0.1);
    }

    .backup-action-btn.warning {
        color: var(--booking-warning);
        border-color: rgba(255, 140, 0, 0.3);
    }

    .backup-action-btn.warning:hover {
        background: rgba(255, 140, 0, 0.1);
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: var(--booking-text-light);
    }

    /* Modal */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        justify-content: center;
        align-items: center;
    }

    .modal-container {
        background: var(--booking-white);
        border-radius: var(--radius-lg);
        width: 90%;
        max-width: 500px;
    }

    .modal-header {
        padding: 20px;
        border-bottom: 1px solid var(--booking-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        font-size: 1rem;
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
    @media (max-width: 1024px) {
        .backup-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .action-cards {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .backup-stats {
            grid-template-columns: 1fr;
        }

        .backups-table {
            min-width: 600px;
        }
    }
</style>

<div class="backup-header">
    <div class="page-title">
        <h1></h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <div>
            <i class="bi bi-check-circle-fill"></i>
            <?php echo $_SESSION['success'];
            unset($_SESSION['success']); ?>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
        <div>
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo $_SESSION['error'];
            unset($_SESSION['error']); ?>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
    </div>
<?php endif; ?>

<!-- Statistics -->
<div class="backup-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($totalTables); ?></div>
        <div class="stat-label">Database Tables</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($totalRows); ?></div>
        <div class="stat-label">Total Records</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo count($backups); ?></div>
        <div class="stat-label">Backup Files</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo round(array_sum(array_column($backups, 'size')) / 1024 / 1024, 2); ?> MB</div>
        <div class="stat-label">Backup Storage</div>
    </div>
</div>

<!-- Action Cards -->
<div class="action-cards">
    <div class="action-card">
        <div class="action-icon">
            <i class="bi bi-database"></i>
        </div>
        <h3>Full Backup</h3>
        <p>Backup entire database including structure and all data</p>
        <div class="backup-options">
            <a href="?action=create_backup&type=full" class="backup-btn">Create Full Backup</a>
            <a href="?action=create_backup&type=full&compress=yes" class="backup-btn secondary">Compressed</a>
        </div>
    </div>
    <div class="action-card">
        <div class="action-icon">
            <i class="bi bi-table"></i>
        </div>
        <h3>Structure Only</h3>
        <p>Backup only table structures without data</p>
        <div class="backup-options">
            <a href="?action=create_backup&type=structure" class="backup-btn">Structure Only</a>
        </div>
    </div>
    <div class="action-card">
        <div class="action-icon">
            <i class="bi bi-file-text"></i>
        </div>
        <h3>Data Only</h3>
        <p>Backup only data without table structures</p>
        <div class="backup-options">
            <a href="?action=create_backup&type=data" class="backup-btn">Data Only</a>
        </div>
    </div>
</div>

<!-- Table Information -->
<div class="tables-section">
    <div class="section-header">
        <h3><i class="bi bi-grid-3x3-gap-fill"></i> Database Tables</h3>
    </div>
    <div style="overflow-x: auto;">
        <table class="tables-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Table Name</th>
                    <th>Records</th>
    </div>
    </thead>
    <tbody>
        <?php if (empty($tables)): ?>
            <tr>
                <td colspan="3" style="text-align: center; padding: 40px;">No tables found
</div>
</div>
<?php else: ?>
    <?php $counter = 1;
            foreach ($tables as $table): ?>
        <tr>
            <td><?php echo $counter++; ?></div>
            <td><code><?php echo htmlspecialchars($table['name']); ?></code></div>
            <td><?php echo number_format($table['rows']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </div>
        </div>
        </div>

        <!-- Backups List -->
        <div class="tables-section">
            <div class="section-header">
                <h3><i class="bi bi-archive"></i> Backup Files</h3>
            </div>
            <div style="overflow-x: auto;">
                <?php if (empty($backups)): ?>
                    <div class="empty-state">
                        <i class="bi bi-archive" style="font-size: 2rem;"></i>
                        <p style="margin-top: 12px;">No backup files found</p>
                        <p style="font-size: 0.6875rem;">Click one of the buttons above to create your first backup</p>
                    </div>
                <?php else: ?>
                    <table class="backups-table">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Date Created</th>
                                <th>Actions</th>
            </div>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($backup['name']); ?></code>
        </div>
            <td><?php echo $backup['type']; ?></div>
            <td class="backup-size"><?php echo round($backup['size'] / 1024 / 1024, 2); ?> MB</div>
            <td><?php echo date('M d, Y H:i:s', strtotime($backup['modified'])); ?></div>
            <td>
                <div class="backup-actions">
                    <a href="?action=download&file=<?php echo urlencode($backup['name']); ?>" class="backup-action-btn" title="Download">
                        <i class="bi bi-download"></i>
                    </a>
                    <a href="javascript:void(0)" onclick="confirmRestore('<?php echo addslashes($backup['name']); ?>')" class="backup-action-btn warning" title="Restore">
                        <i class="bi bi-arrow-repeat"></i>
                    </a>
                    <a href="javascript:void(0)" onclick="confirmDelete('<?php echo addslashes($backup['name']); ?>')" class="backup-action-btn danger" title="Delete">
                        <i class="bi bi-trash"></i>
                    </a>
                </div>
                </div>
                </div>
            <?php endforeach; ?>
            </tbody>
            </div>
        <?php endif; ?>
        </div>
        </div>

        <!-- Restore Confirmation Modal -->
        <div id="restoreModal" class="modal-overlay">
            <div class="modal-container">
                <div class="modal-header">
                    <h3>Restore Database</h3>
                    <button type="button" class="modal-close" onclick="closeRestoreModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Warning!</strong> Restoring a backup will overwrite your current database.</p>
                    <p style="margin-top: 12px; color: var(--booking-danger);"><i class="bi bi-exclamation-triangle-fill"></i> This action cannot be undone. It is recommended to create a backup of your current database before restoring.</p>
                    <p style="margin-top: 12px;" id="restoreFileName"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="backup-action-btn" onclick="closeRestoreModal()">Cancel</button>
                    <a href="#" id="restoreLink" class="backup-action-btn warning">Restore Backup</a>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="modal-overlay">
            <div class="modal-container">
                <div class="modal-header">
                    <h3>Delete Backup</h3>
                    <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this backup file?</p>
                    <p style="margin-top: 12px;" id="deleteFileName"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="backup-action-btn" onclick="closeDeleteModal()">Cancel</button>
                    <a href="#" id="deleteLink" class="backup-action-btn danger">Delete</a>
                </div>
            </div>
        </div>

        <script>
            // Restore confirmation
            function confirmRestore(filename) {
                document.getElementById('restoreFileName').innerHTML = '<strong>File:</strong> ' + filename;
                document.getElementById('restoreLink').href = '?action=restore&file=' + encodeURIComponent(filename);
                document.getElementById('restoreModal').style.display = 'flex';
            }

            function closeRestoreModal() {
                document.getElementById('restoreModal').style.display = 'none';
            }

            // Delete confirmation
            function confirmDelete(filename) {
                document.getElementById('deleteFileName').innerHTML = '<strong>File:</strong> ' + filename;
                document.getElementById('deleteLink').href = '?action=delete&file=' + encodeURIComponent(filename);
                document.getElementById('deleteModal').style.display = 'flex';
            }

            function closeDeleteModal() {
                document.getElementById('deleteModal').style.display = 'none';
            }

            // Close modals on escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeRestoreModal();
                    closeDeleteModal();
                }
            });

            // Close modals when clicking outside
            window.onclick = function(e) {
                const restoreModal = document.getElementById('restoreModal');
                const deleteModal = document.getElementById('deleteModal');
                if (e.target === restoreModal) closeRestoreModal();
                if (e.target === deleteModal) closeDeleteModal();
            }
        </script>

        <?php require_once 'includes/admin_footer.php'; ?>