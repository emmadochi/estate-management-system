<?php
/**
 * Migration runner for maintenance ticket quote/payment fields and updates table
 * Run once via browser or CLI: php database/run_ticket_updates_migration.php
 */

require_once __DIR__ . '/db_connection.php';

$migrationFile = __DIR__ . '/migrations/2026_02_19_add_ticket_quote_and_updates.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

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

    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $conn->exec($statement);
    }

    // Re-enable foreign key checks
    $conn->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "Migration completed successfully!\n";
    echo "Ticket quote/payment fields and maintenance_ticket_updates table applied.\n";

} catch (PDOException $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}

