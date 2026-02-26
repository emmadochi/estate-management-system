<?php
require_once __DIR__ . '/../app/bootstrap.php';

$db = db();

// Test if tables exist
try {
    $plans = $db->fetchOne('SELECT COUNT(*) as c FROM subscription_plans')['c'];
    echo "✓ subscription_plans table: $plans records\n";
    
    $subscriptions = $db->fetchOne('SELECT COUNT(*) as c FROM estate_subscriptions')['c'];
    echo "✓ estate_subscriptions table: $subscriptions records\n";
    
    $payments = $db->fetchOne('SELECT COUNT(*) as c FROM subscription_payments')['c'];
    echo "✓ subscription_payments table: $payments records\n";
    
    echo "\n=== Tables are ready! ===\n";
    echo "The subscription system should now work properly.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}