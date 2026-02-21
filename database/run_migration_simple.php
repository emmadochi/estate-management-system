<?php
/**
 * Generic migration runner
 * Usage: php database/run_migration_simple.php database/migrations/your_migration.sql
 */

if ($argc < 2) {
    die("Usage: php " . $argv[0] . " <migration_file_path>\n");
}

$migrationFile = $argv[1];

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

require_once __DIR__ . '/db_connection.php';

$sql = file_get_contents($migrationFile);
if ($sql === false) {
    die("Could not read migration file.\n");
}

// Remove comments and empty lines, split by semicolons
$sql = preg_replace('/--.*$/m', '', $sql);
$sql = preg_replace('/^\s*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

if (empty($statements)) {
    die("No SQL statements found in migration file.\n");
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Disable foreign key checks temporarily (in case of dependency issues)
    $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
    
    echo "Running migration: $migrationFile\n";
    echo "Found " . count($statements) . " statements\n\n";
    
    foreach ($statements as $index => $statement) {
        if (trim($statement) === '') {
            continue;
        }
        
        echo "Executing statement " . ($index + 1) . ": ";
        $firstLine = strtok($statement, "\n");
        echo (strlen($firstLine) > 50 ? substr($firstLine, 0, 50) . "..." : $firstLine) . "\n";
        
        $conn->exec($statement);
        echo "✓ Success\n\n";
    }
    
    // Re-enable foreign key checks
    $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}
?>