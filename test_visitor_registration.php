<?php
require_once 'app/bootstrap.php';

try {
    $db = db();
    echo "✓ Database connection: OK\n";

    // Test if visitor_logs table exists
    $stmt = $db->prepare('SELECT 1 FROM visitor_logs LIMIT 1');
    $stmt->execute();
    echo "✓ Visitor logs table: OK\n";

    // Test if units table exists
    $stmt = $db->prepare('SELECT id, unit_number FROM units LIMIT 1');
    $stmt->execute();
    echo "✓ Units table: OK\n";

    // Test if tenants table exists
    $stmt = $db->prepare('SELECT id, emergency_contact_name FROM tenants LIMIT 1');
    $stmt->execute();
    echo "✓ Tenants table: OK\n";

    echo "\nAll database connections are working correctly!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Clean up after ourselves
unlink(__FILE__);