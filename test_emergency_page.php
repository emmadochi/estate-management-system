<?php
require_once __DIR__ . '/app/bootstrap.php';

// Simulate security login for testing
$_SESSION['user_id'] = 2; // Assuming security user ID is 2
$_SESSION['user_role'] = 'security';

require_login(['security']);
$me = current_user();
$db = db();

echo "=== Emergency Response Page Test ===\n\n";
echo "Current User: " . $me['first_name'] . " " . $me['last_name'] . " (" . $me['email'] . ")\n";
echo "Role: " . $me['role'] . "\n";

$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? (int)$estateIds[0] : 0;

echo "Allowed Estate IDs: " . implode(', ', $estateIds) . "\n";
echo "Active Estate ID: " . $estateId . "\n";

if ($estateId) {
    $securityPersonnel = $db->fetchOne(
        "SELECT * FROM security_personnel WHERE user_id = ? AND estate_id = ?",
        [$me['id'], $estateId]
    );
    
    if ($securityPersonnel) {
        echo "✓ Security personnel record found\n";
        echo "  - Badge Number: " . $securityPersonnel['badge_number'] . "\n";
        echo "  - Status: " . $securityPersonnel['status'] . "\n";
    } else {
        echo "❌ No security personnel record found for this user and estate\n";
    }
} else {
    echo "❌ No estate assigned to this security user\n";
}

// Test the main query
$activeEmergencies = $db->fetchAll(
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
    [$estateId]
);

echo "\nActive Emergencies Query Result:\n";
echo "Found " . count($activeEmergencies) . " active emergencies\n";

if (!empty($activeEmergencies)) {
    foreach ($activeEmergencies as $emergency) {
        echo "  - " . $emergency['alert_number'] . " (" . $emergency['status'] . ")\n";
        echo "    Type: " . ucfirst(str_replace('_', ' ', $emergency['alert_type'])) . "\n";
        echo "    Tenant: " . $emergency['tenant_name'] . "\n";
        echo "    Location: " . $emergency['property_name'] . " " . $emergency['unit_number'] . "\n";
        echo "    Reported: " . date('M j, g:i A', strtotime($emergency['reported_at'])) . "\n";
        echo "    ---\n";
    }
} else {
    echo "  No active emergencies found\n";
}