<?php
/**
 * Quality Assurance System Migration
 * Run this script to implement the QA system
 */
require_once __DIR__ . '/../app/bootstrap.php';

$migrationFile = __DIR__ . '/migrations/2026_02_20_quality_assurance_system.sql';

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
    $db = db();
    $conn = $db->getConnection();
    
    // Start transaction
    $conn->beginTransaction();
    
    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        echo "Executing: " . substr($statement, 0, 50) . "...\n";
        $conn->exec($statement);
    }
    
    // Commit transaction
    $conn->commit();
    
    echo "✅ Quality Assurance system migration completed successfully!\n";
    echo "Added features:\n";
    echo "  - QA checklist system\n";
    echo "  - Quality issue tracking\n";
    echo "  - Vendor performance metrics\n";
    echo "  - Automated quality scoring\n";
    
} catch (PDOException $e) {
    // Rollback on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "❌ Error running QA migration: " . $e->getMessage() . "\n";
    exit(1);
}