<?php
/**
 * Migration runner for EstatePro Accounting & Financial Management System
 * Run via CLI: php database/run_accounting_migration.php
 */

require_once __DIR__ . '/db_connection.php';

$migrationFile = __DIR__ . '/migrations/2026_09_01_create_accounting_and_finance_system.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

$sql = file_get_contents($migrationFile);
if ($sql === false) {
    die("Could not read migration file.\n");
}

// Remove comments and split by semicolons
$sql = preg_replace('/--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

if (empty($statements)) {
    die("No SQL statements found in migration file.\n");
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
    
    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $conn->exec($statement);
    }
    
    $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    
    echo "========================================\n";
    echo "Accounting Migration Completed Successfully!\n";
    echo "========================================\n";
    
    $tables = ['chart_of_accounts', 'expense_categories', 'expenses', 'budgets', 'bank_accounts', 'bank_reconciliations'];
    foreach ($tables as $t) {
        $check = $db->fetchOne("SHOW TABLES LIKE '{$t}'");
        if ($check) {
            echo "✓ Table '{$t}' is present.\n";
        } else {
            echo "⚠ Warning: Table '{$t}' was not found.\n";
        }
    }
    
    $catsCount = $db->fetchOne("SELECT COUNT(*) AS c FROM expense_categories")['c'] ?? 0;
    $accountsCount = $db->fetchOne("SELECT COUNT(*) AS c FROM chart_of_accounts")['c'] ?? 0;
    echo "✓ Seeded expense categories: {$catsCount}\n";
    echo "✓ Seeded chart of accounts: {$accountsCount}\n";
    
} catch (PDOException $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}
