<?php
/**
 * Run Subscription Management System Migration
 * 
 * This script creates all subscription-related tables and seed data
 * for the estate management SaaS billing system.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// Only allow CLI or authenticated super admin access
if (php_sapi_name() !== 'cli') {
    require_login(['super_admin']);
    if (!is_super_admin()) {
        die('Access denied. Super admin required.');
    }
}

echo "Running Subscription Management System Migration...\n";
echo "==============================================\n\n";

$db = db();
$migrationFile = __DIR__ . '/migrations/2026_02_26_create_subscription_system.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

try {
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolon to execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($stmt) => strlen($stmt) > 5 && !str_starts_with($stmt, '--')
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        try {
            $db->execute($statement);
            $successCount++;
            echo "✓ Executed statement $successCount\n";
        } catch (Exception $e) {
            $errorCount++;
            echo "✗ Error in statement $successCount: " . $e->getMessage() . "\n";
            // Continue with other statements
        }
    }
    
    echo "\n==============================================\n";
    echo "Migration completed!\n";
    echo "Successful: $successCount statements\n";
    echo "Errors: $errorCount statements\n";
    
    if ($errorCount === 0) {
        echo "\n✓ Subscription management system is ready!\n";
        echo "✓ Created subscription plans, assignments, and payment tracking\n";
        echo "✓ Added monitoring and alerting capabilities\n";
        echo "\nNext steps:\n";
        echo "1. Access Subscription Plans: /pages/admin/subscription_plans.php\n";
        echo "2. Monitor Subscriptions: /pages/admin/subscription_monitoring.php\n";
        echo "3. Assign Subscriptions: /pages/admin/estate_subscriptions.php\n";
        echo "4. Track Payments: /pages/admin/subscription_payments.php\n";
    } else {
        echo "\n⚠ Some statements failed. Check the errors above.\n";
    }
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}