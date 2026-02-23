<?php
/**
 * Professional Emergency Alert System Database Migration
 * Execute once: php database/run_professional_emergency_migration.php
 */

require_once __DIR__ . '/db_connection.php';

$files = [
    __DIR__ . '/migrations/2026_02_21_enhanced_emergency_system.sql'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        die("Migration file not found: $file\n");
    }
    
    echo "Running migration: " . basename($file) . "\n";
    
    $sql = file_get_contents($file);
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    try {
        $db = Database::getInstance();
        $conn = $db->getConnection();
        
        foreach ($statements as $s) {
            if ($s !== '') {
                $conn->exec($s);
            }
        }
        
        echo "✓ " . basename($file) . " completed successfully\n";
        
    } catch (PDOException $e) {
        echo "✗ Error in " . basename($file) . ": " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Professional Emergency Alert System Database Migration Complete ===\n";
echo "✓ Enhanced emergency_alerts table\n";
echo "✓ Audible alerts system\n";
echo "✓ Emergency escalation tracking\n";
echo "✓ Activity logging\n";
echo "✓ Response templates\n";
echo "✓ Contact groups\n";
echo "✓ Enhanced notifications\n";
echo "✓ Security personnel roles\n\n";

echo "Professional emergency system is now ready for production use!\n";