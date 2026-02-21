<?php
require_once __DIR__ . '/../app/bootstrap.php';

try {
    $db = db();
    
    // Read the SQL file
    $sql = file_get_contents(__DIR__ . '/migrations/2026_02_20_security_attendance_tracking.sql');
    
    if ($sql === false) {
        throw new Exception('Could not read migration file');
    }
    
    // Execute the SQL
    $statements = array_filter(
        array_map('trim', preg_split("/;[\r\n]+/", $sql)),
        function($statement) { return !empty($statement); }
    );
    
    foreach ($statements as $statement) {
        $db->execute($statement);
    }
    
    echo "Security attendance tracking migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
    exit(1);
}