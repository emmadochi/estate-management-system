<?php
require_once __DIR__ . '/app/bootstrap.php';

echo "=== Emergency Alert Diagnostic Tool ===\n\n";

// Check if user is logged in
$user = current_user();
if (!$user) {
    echo "❌ ERROR: No user logged in. Please log in first.\n";
    exit(1);
}

echo "1. Current User Information:\n";
echo "   - User ID: " . $user['id'] . "\n";
echo "   - Name: " . ($user['first_name'] ?? 'Unknown') . " " . ($user['last_name'] ?? 'Unknown') . "\n";
echo "   - Role: " . $user['role'] . "\n";
echo "   - Email: " . $user['email'] . "\n\n";

$db = db();

// Check if user is a tenant
if ($user['role'] !== 'tenant') {
    echo "⚠️  WARNING: You are not logged in as a tenant. Emergency alerts can only be submitted by tenants.\n";
    echo "   Your role is: " . $user['role'] . "\n\n";
}

// Check tenant information
$tenant = null;
if ($user['role'] === 'tenant') {
    $tenant = $db->fetchOne(
        "SELECT t.*, u.unit_number, p.name as property_name, e.name as estate_name
         FROM tenants t
         LEFT JOIN units u ON t.unit_id = u.id
         LEFT JOIN properties p ON u.property_id = p.id
         LEFT JOIN estates e ON t.estate_id = e.id
         WHERE t.user_id = ? AND t.status = 'active'",
        [$user['id']]
    );
    
    echo "2. Tenant Information:\n";
    if ($tenant) {
        echo "   ✓ Active tenancy found\n";
        echo "   - Tenant ID: " . $tenant['id'] . "\n";
        echo "   - Estate: " . ($tenant['estate_name'] ?? 'Unknown') . " (ID: " . $tenant['estate_id'] . ")\n";
        echo "   - Unit: " . ($tenant['property_name'] ?? 'Unknown') . " - " . ($tenant['unit_number'] ?? 'Unknown') . " (ID: " . $tenant['unit_id'] . ")\n";
        echo "   - Emergency Contact: " . ($tenant['emergency_contact_name'] ?? 'Not set') . "\n\n";
    } else {
        echo "   ❌ NO ACTIVE TENANCY FOUND\n";
        echo "   You must have an active tenancy to submit emergency alerts.\n\n";
    }
}

// Check security personnel for the estate
if ($tenant) {
    $securityPersonnel = $db->fetchAll(
        "SELECT sp.*, u.first_name, u.last_name, u.email
         FROM security_personnel sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.estate_id = ? AND sp.status = 'active'",
        [$tenant['estate_id']]
    );
    
    echo "3. Security Personnel for Estate ID " . $tenant['estate_id'] . ":\n";
    if (!empty($securityPersonnel)) {
        echo "   ✓ Found " . count($securityPersonnel) . " active security personnel\n";
        foreach ($securityPersonnel as $sp) {
            echo "   - " . $sp['first_name'] . " " . $sp['last_name'] . " (" . $sp['email'] . ")\n";
        }
        echo "\n";
    } else {
        echo "   ❌ NO ACTIVE SECURITY PERSONNEL FOUND\n";
        echo "   There are no active security personnel assigned to your estate.\n\n";
    }
}

// Check recent emergency alerts
echo "4. Recent Emergency Alerts:\n";
if ($tenant) {
    $recentAlerts = $db->fetchAll(
        "SELECT ea.*, t.emergency_contact_name
         FROM emergency_alerts ea
         JOIN tenants t ON ea.tenant_id = t.id
         WHERE ea.estate_id = ?
         ORDER BY ea.reported_at DESC
         LIMIT 10",
        [$tenant['estate_id']]
    );
    
    if (!empty($recentAlerts)) {
        echo "   ✓ Found " . count($recentAlerts) . " recent emergency alerts\n";
        foreach ($recentAlerts as $alert) {
            $isYours = ($alert['tenant_id'] == $tenant['id']) ? " (YOURS)" : "";
            echo "   - [" . $alert['status'] . "] " . $alert['alert_number'] . " - " . 
                 ucfirst(str_replace('_', ' ', $alert['alert_type'])) . 
                 " - " . date('M j, g:i A', strtotime($alert['reported_at'])) . $isYours . "\n";
        }
    } else {
        echo "   ℹ️  No emergency alerts found for your estate\n";
    }
} else {
    // Check all alerts if no tenant
    $allAlerts = $db->fetchAll(
        "SELECT ea.*, t.emergency_contact_name, e.name as estate_name
         FROM emergency_alerts ea
         JOIN tenants t ON ea.tenant_id = t.id
         JOIN estates e ON ea.estate_id = e.id
         ORDER BY ea.reported_at DESC
         LIMIT 10"
    );
    
    if (!empty($allAlerts)) {
        echo "   ✓ Found " . count($allAlerts) . " recent emergency alerts (all estates)\n";
        foreach ($allAlerts as $alert) {
            echo "   - [" . $alert['status'] . "] " . $alert['alert_number'] . " - " . 
                 ucfirst(str_replace('_', ' ', $alert['alert_type'])) . 
                 " at " . $alert['estate_name'] . " - " . 
                 date('M j, g:i A', strtotime($alert['reported_at'])) . "\n";
        }
    } else {
        echo "   ℹ️  No emergency alerts found in the system\n";
    }
}

echo "\n5. Database Connection Test:\n";
try {
    $db->fetchOne("SELECT 1");
    echo "   ✓ Database connection successful\n";
} catch (Exception $e) {
    echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
}

echo "\n=== Diagnostic Complete ===\n";

// Additional troubleshooting suggestions
echo "\nTroubleshooting Tips:\n";
echo "1. Make sure you're logged in as a tenant with active tenancy\n";
echo "2. Check that your estate has active security personnel assigned\n";
echo "3. Verify the emergency alert was submitted successfully (check for success message)\n";
echo "4. Try refreshing the emergency_response.php page\n";
echo "5. Check if you're viewing the correct estate (security personnel can only see alerts from their assigned estate)\n";