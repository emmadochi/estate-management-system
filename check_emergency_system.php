<?php
require_once __DIR__ . '/app/bootstrap.php';

echo "=== Emergency Alert System Check ===\n\n";

$db = db();

// 1. Check if emergency_alerts table exists and has data
echo "1. Emergency Alerts Table Check:\n";
try {
    $tableExists = $db->fetchOne("SHOW TABLES LIKE 'emergency_alerts'");
    if ($tableExists) {
        echo "   ✓ emergency_alerts table exists\n";
        
        // Check if there are any alerts
        $alertCount = $db->fetchOne("SELECT COUNT(*) as count FROM emergency_alerts");
        echo "   - Total alerts in system: " . $alertCount['count'] . "\n";
        
        // Show recent alerts
        $recentAlerts = $db->fetchAll(
            "SELECT ea.*, t.emergency_contact_name, e.name as estate_name, u.unit_number, p.name as property_name
             FROM emergency_alerts ea
             JOIN tenants t ON ea.tenant_id = t.id
             JOIN estates e ON ea.estate_id = e.id
             JOIN units u ON ea.unit_id = u.id
             JOIN properties p ON u.property_id = p.id
             ORDER BY ea.reported_at DESC
             LIMIT 5"
        );
        
        if (!empty($recentAlerts)) {
            echo "   - Recent alerts:\n";
            foreach ($recentAlerts as $alert) {
                echo "     * [" . $alert['status'] . "] " . $alert['alert_number'] . " - " . 
                     ucfirst(str_replace('_', ' ', $alert['alert_type'])) . 
                     " from " . $alert['estate_name'] . " - " . 
                     $alert['property_name'] . " " . $alert['unit_number'] . " - " . 
                     date('M j, g:i A', strtotime($alert['reported_at'])) . "\n";
            }
        } else {
            echo "   - No alerts found in the system\n";
        }
    } else {
        echo "   ❌ emergency_alerts table NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ Error checking table: " . $e->getMessage() . "\n";
}

echo "\n2. Estate and Security Setup Check:\n";
// Check estates
$estates = $db->fetchAll("SELECT id, name, status FROM estates ORDER BY name");
if (!empty($estates)) {
    echo "   ✓ Found " . count($estates) . " estates\n";
    foreach ($estates as $estate) {
        echo "   - " . $estate['name'] . " (ID: " . $estate['id'] . ", Status: " . $estate['status'] . ")\n";
        
        // Check security personnel for this estate
        $securityCount = $db->fetchOne(
            "SELECT COUNT(*) as count FROM security_personnel WHERE estate_id = ? AND status = 'active'",
            [$estate['id']]
        );
        echo "     * Active security personnel: " . $securityCount['count'] . "\n";
        
        // Check alerts for this estate
        $alertCount = $db->fetchOne(
            "SELECT COUNT(*) as count FROM emergency_alerts WHERE estate_id = ?",
            [$estate['id']]
        );
        echo "     * Alerts for this estate: " . $alertCount['count'] . "\n";
    }
} else {
    echo "   ❌ No estates found\n";
}

echo "\n3. Users with Security Role Check:\n";
$securityUsers = $db->fetchAll(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.role, 
            COUNT(sp.id) as assigned_estates
     FROM users u
     LEFT JOIN security_personnel sp ON u.id = sp.user_id AND sp.status = 'active'
     WHERE u.role = 'security'
     GROUP BY u.id
     ORDER BY u.last_name, u.first_name"
);

if (!empty($securityUsers)) {
    echo "   ✓ Found " . count($securityUsers) . " users with security role\n";
    foreach ($securityUsers as $user) {
        echo "   - " . $user['first_name'] . " " . $user['last_name'] . " (" . $user['email'] . ")\n";
        echo "     * Assigned to " . $user['assigned_estates'] . " estates\n";
    }
} else {
    echo "   ❌ No users with security role found\n";
}

echo "\n4. Security Personnel Assignments:\n";
$assignments = $db->fetchAll(
    "SELECT sp.*, u.first_name, u.last_name, u.email, e.name as estate_name
     FROM security_personnel sp
     JOIN users u ON sp.user_id = u.id
     JOIN estates e ON sp.estate_id = e.id
     WHERE sp.status = 'active'
     ORDER BY e.name, u.last_name"
);

if (!empty($assignments)) {
    echo "   ✓ Found " . count($assignments) . " active security assignments\n";
    foreach ($assignments as $assignment) {
        echo "   - " . $assignment['first_name'] . " " . $assignment['last_name'] . 
             " assigned to " . $assignment['estate_name'] . " (Badge: " . $assignment['badge_number'] . ")\n";
    }
} else {
    echo "   ❌ No active security personnel assignments found\n";
}

echo "\n=== Check Complete ===\n";

// Test the specific query used in emergency_response.php
echo "\n5. Testing Emergency Response Query:\n";
$testEstateId = 1; // Test with estate ID 1
try {
    $testQuery = $db->fetchAll(
        "SELECT ea.*, 
                t.emergency_contact_name as tenant_name,
                u.unit_number, p.name as property_name,
                ack_user.first_name as ack_first, ack_user.last_name as ack_last,
                resp_user.first_name as resp_first, resp_user.last_name as resp_last
         FROM emergency_alerts ea
         JOIN tenants t ON ea.tenant_id = t.id
         JOIN units u ON ea.unit_id = u.id
         JOIN properties p ON u.property_id = p.id
         LEFT JOIN users ack_user ON ea.acknowledged_by = ack_user.id
         LEFT JOIN users resp_user ON ea.responded_by = resp_user.id
         WHERE ea.estate_id = ? 
         AND ea.status IN ('reported', 'acknowledged', 'responding')
         ORDER BY ea.severity_level DESC, ea.reported_at DESC",
        [$testEstateId]
    );
    
    echo "   ✓ Query executed successfully for estate ID " . $testEstateId . "\n";
    echo "   - Found " . count($testQuery) . " active emergencies\n";
    
    if (!empty($testQuery)) {
        foreach ($testQuery as $alert) {
            echo "     * " . $alert['alert_number'] . " - " . $alert['tenant_name'] . " - " . 
                 $alert['property_name'] . " " . $alert['unit_number'] . " - Status: " . $alert['status'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "   ❌ Query failed: " . $e->getMessage() . "\n";
}