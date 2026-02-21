<?php
// Script to run the security tables migration

require_once __DIR__ . '/../app/bootstrap.php';

try {
    // Read the migration SQL file
    $migrationSql = file_get_contents(__DIR__ . '/migrations/2026_02_20_create_security_tables.sql');
    
    if ($migrationSql === false) {
        throw new Exception('Could not read migration file');
    }
    
    // Split the SQL into individual statements
    $statements = array_filter(
        array_map('trim', preg_split("/;[\r\n]+/", $migrationSql)),
        function($statement) {
            return !empty($statement);
        }
    );
    
    $db = db();
    $executed = 0;
    
    // Execute each statement separately
    foreach ($statements as $statement) {
        if (trim($statement) !== '') {
            $db->execute($statement);
            $executed++;
        }
    }
    
    echo "Security tables migration completed successfully!\n";
    echo "Total statements executed: $executed\n";
    echo "Tables created: security_personnel, gate_passes, emergency_incidents, incident_reports, patrol_routes, patrol_logs\n";
    
} catch (Exception $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}