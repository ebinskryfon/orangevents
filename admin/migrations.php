<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';

$db = get_db_connection();
$message = '';
$error = '';
$logs = [];

// 1. Initialize migrations table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `migration` VARCHAR(255) NOT NULL UNIQUE,
        `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    $error = "Failed to initialize migrations table: " . $e->getMessage();
}

// 2. Scan migrations directory
$migrations_dir = __DIR__ . '/../database/migrations';
$migration_files = [];
if (is_dir($migrations_dir)) {
    $files = glob($migrations_dir . '/*.php');
    if ($files) {
        sort($files);
        foreach ($files as $file) {
            $migration_files[basename($file)] = $file;
        }
    }
}

// Helper to extract description from file
function get_migration_description($filepath) {
    $content = @file_get_contents($filepath);
    if (!$content) return 'No description available.';
    
    // Find the first docblock (/** ... */)
    if (preg_match('~/\*\*(.*?)\*/~s', $content, $match)) {
        $lines = explode("\n", $match[1]);
        $desc_lines = [];
        foreach ($lines as $line) {
            $line = trim($line, " \t\r\n*");
            if ($line !== '' && !str_starts_with(strtolower($line), '@')) {
                $desc_lines[] = $line;
            }
        }
        return implode(' ', $desc_lines);
    }
    return 'No description available.';
}

// Helper to run a direct execution script in an isolated scope
function execute_direct_migration($filepath) {
    ob_start();
    try {
        // Isolation wrapper
        include $filepath;
    } catch (Exception $e) {
        ob_end_clean();
        throw $e;
    }
    return ob_get_clean();
}

// Helper function to run a single migration
function run_migration_logic($db, $filename, $filepath, &$logs) {
    // Check database again to be safe
    $stmt = $db->prepare("SELECT COUNT(*) FROM migrations WHERE migration = :migration");
    $stmt->execute(['migration' => $filename]);
    if ($stmt->fetchColumn() > 0) {
        $logs[] = [
            'migration' => $filename,
            'status' => 'skipped',
            'message' => 'Already executed'
        ];
        return true;
    }

    $content = file_get_contents($filepath);
    $is_return_format = str_contains($content, 'return [') || str_contains($content, 'return array(');

    try {
        if ($is_return_format) {
            $migration = require $filepath;
            if (isset($migration['up'])) {
                if (is_callable($migration['up'])) {
                    $migration['up']($db);
                } else {
                    $db->exec($migration['up']);
                }
            }
            $output = "Migration completed (array structure).";
        } else {
            $output = execute_direct_migration($filepath);
            if (empty($output)) {
                $output = "Migration completed (direct execution).";
            }
        }

        // Record in database
        $log_stmt = $db->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
        $log_stmt->execute(['migration' => $filename]);

        $logs[] = [
            'migration' => $filename,
            'status' => 'success',
            'output' => $output
        ];
        return true;
    } catch (Exception $e) {
        $logs[] = [
            'migration' => $filename,
            'status' => 'error',
            'output' => $e->getMessage()
        ];
        return false;
    }
}

// Handle Migration Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'run_all') {
            // Fetch already executed migrations
            try {
                $stmt = $db->query("SELECT migration FROM migrations");
                $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $executed = [];
            }

            $success_count = 0;
            $failed = false;

            foreach ($migration_files as $filename => $filepath) {
                if (in_array($filename, $executed)) {
                    continue;
                }

                if (run_migration_logic($db, $filename, $filepath, $logs)) {
                    $success_count++;
                } else {
                    $failed = true;
                    break; // Stop running migrations if one fails
                }
            }

            if ($failed) {
                $error = "Migration runner stopped due to an error. Check logs below.";
            } elseif ($success_count === 0) {
                $message = "Database is already up to date! No pending migrations found.";
            } else {
                $message = "Successfully ran $success_count pending migrations!";
            }

        } elseif ($_POST['action'] === 'run_single' && isset($_POST['filename'])) {
            $target_filename = $_POST['filename'];
            if (isset($migration_files[$target_filename])) {
                if (run_migration_logic($db, $target_filename, $migration_files[$target_filename], $logs)) {
                    $message = "Successfully ran migration: $target_filename";
                } else {
                    $error = "Failed to run migration: $target_filename. Check logs below.";
                }
            } else {
                $error = "Migration file not found.";
            }
        }
    }
}

// Fetch current status of migrations
try {
    $stmt = $db->query("SELECT migration, executed_at FROM migrations ORDER BY executed_at DESC");
    $executed_migrations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $executed_migrations = [];
}

$pending_count = 0;
foreach ($migration_files as $filename => $path) {
    if (!isset($executed_migrations[$filename])) {
        $pending_count++;
    }
}
?>

<div class="content-header" style="margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary);">Database Migrations</h1>
        <p style="color: var(--text-secondary); margin-top: 0.25rem;">Manage database updates, tables, structures, and schema updates</p>
    </div>
    <div>
        <?php if ($pending_count > 0): ?>
            <form action="" method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="run_all">
                <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-play"></i>
                    Run All Pending Migrations (<?= $pending_count ?>)
                </button>
            </form>
        <?php else: ?>
            <button class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: not-allowed;" disabled>
                <i class="fa-solid fa-check-double" style="color: var(--success);"></i>
                Database Up to Date
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div style="background-color: rgba(46, 213, 115, 0.1); border: 1px solid var(--success); color: var(--success); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i>
        <span><?= h($message) ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background-color: rgba(255, 71, 87, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem;"></i>
        <span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="card stat-card">
        <div class="stat-icon">
            <i class="fa-solid fa-file-code"></i>
        </div>
        <div class="stat-info">
            <h3><?= count($migration_files) ?></h3>
            <p>Total Migration Files</p>
        </div>
    </div>
    <div class="card stat-card green">
        <div class="stat-icon">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-info">
            <h3><?= count($executed_migrations) ?></h3>
            <p>Executed Migrations</p>
        </div>
    </div>
    <div class="card stat-card <?= $pending_count > 0 ? 'purple' : 'blue' ?>">
        <div class="stat-icon">
            <i class="fa-solid <?= $pending_count > 0 ? 'fa-triangle-exclamation' : 'fa-check' ?>"></i>
        </div>
        <div class="stat-info">
            <h3><?= $pending_count ?></h3>
            <p>Pending Migrations</p>
        </div>
    </div>
</div>

<!-- Logs Console Output (if any ran) -->
<?php if (!empty($logs)): ?>
    <div class="card" style="margin-bottom: 2.5rem; background-color: #0d1117; border-color: #30363d; overflow: hidden; padding: 0;">
        <div style="background-color: #161b22; padding: 0.75rem 1.25rem; border-bottom: 1px solid #30363d; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-family: monospace; font-weight: 700; color: #8b949e; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-terminal" style="color: var(--accent-color);"></i>
                Execution Logs
            </span>
            <span style="font-size: 0.75rem; color: #8b949e; font-family: monospace;">
                PHP Runner
            </span>
        </div>
        <div style="padding: 1.25rem; font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; line-height: 1.5; max-height: 350px; overflow-y: auto; color: #c9d1d9;">
            <?php foreach ($logs as $log): ?>
                <div style="margin-bottom: 1rem; border-bottom: 1px dashed #21262d; padding-bottom: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <strong style="color: #58a6ff;"><?= h($log['migration']) ?></strong>
                        <?php if ($log['status'] === 'success'): ?>
                            <span style="color: #2ea043; font-weight: bold;">[SUCCESS]</span>
                        <?php elseif ($log['status'] === 'skipped'): ?>
                            <span style="color: #8b949e; font-weight: bold;">[SKIPPED]</span>
                        <?php else: ?>
                            <span style="color: #f85149; font-weight: bold;">[FAILED]</span>
                        <?php endif; ?>
                    </div>
                    <?php if (isset($log['output'])): ?>
                        <pre style="white-space: pre-wrap; margin: 0.25rem 0 0 0; background: #161b22; padding: 0.5rem; border-radius: 4px; font-size: 0.85rem; color: #8b949e; border: 1px solid #21262d;"><?= h(strip_tags($log['output'])) ?></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Migrations Catalog -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);">Migration History & Sequence</h3>
    </div>
    
    <div class="table-responsive">
        <table class="table" style="margin-top: 0;">
            <thead>
                <tr>
                    <th style="width: 80px; text-align: center;">Order</th>
                    <th>Migration File</th>
                    <th>Description / Purpose</th>
                    <th style="width: 150px; text-align: center;">Status</th>
                    <th style="width: 200px;">Executed At</th>
                    <th style="width: 120px; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($migration_files)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                            <i class="fa-regular fa-folder-open" style="font-size: 3rem; display: block; margin-bottom: 1rem;"></i>
                            No migration files found in <code>database/migrations/</code>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $order = 1;
                    foreach ($migration_files as $filename => $filepath): 
                        $is_executed = isset($executed_migrations[$filename]);
                        $executed_time = $is_executed ? $executed_migrations[$filename] : '-';
                        $description = get_migration_description($filepath);
                        
                        // Parse format
                        $content = file_get_contents($filepath);
                        $is_return_format = str_contains($content, 'return [') || str_contains($content, 'return array(');
                        $format_badge = $is_return_format ? 'array style' : 'script style';
                    ?>
                        <tr>
                            <td style="text-align: center; font-weight: bold; color: var(--text-muted);">
                                #<?= str_pad($order++, 2, '0', STR_PAD_LEFT) ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--text-primary); font-family: monospace;">
                                    <?= h($filename) ?>
                                </div>
                                <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; background: var(--bg-control); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--border-color);">
                                    <?= $format_badge ?>
                                </span>
                            </td>
                            <td>
                                <div style="color: var(--text-secondary); font-size: 0.9rem; max-width: 450px; line-height: 1.4;">
                                    <?= h($description) ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($is_executed): ?>
                                    <span class="badge badge-confirmed" style="display: inline-flex; align-items: center; gap: 0.25rem;">
                                        <i class="fa-solid fa-circle-check"></i>
                                        Executed
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-draft" style="background-color: rgba(255, 165, 0, 0.15); color: var(--warning); display: inline-flex; align-items: center; gap: 0.25rem;">
                                        <i class="fa-solid fa-clock"></i>
                                        Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-secondary); font-size: 0.9rem;">
                                <?php if ($is_executed): ?>
                                    <i class="fa-regular fa-calendar-days" style="margin-right: 0.25rem; font-size: 0.85rem;"></i>
                                    <?= date('d M Y - h:i A', strtotime($executed_time)) ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style: italic;">Not executed yet</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($is_executed): ?>
                                    <button class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem; opacity: 0.5; cursor: not-allowed;" title="Migration already executed" disabled>
                                        <i class="fa-solid fa-ban"></i> Run
                                    </button>
                                <?php else: ?>
                                    <form action="" method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="run_single">
                                        <input type="hidden" name="filename" value="<?= h($filename) ?>">
                                        <button type="submit" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem; box-shadow: none;">
                                            <i class="fa-solid fa-play"></i> Run
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
