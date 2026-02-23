<?php
require_once __DIR__ . '/../../app/bootstrap.php';

// Debug version - no login required for testing
// require_login(['security']);

echo "<h2>Emergency Response Debug Page</h2>\n";

$db = db();

// Show all estates
echo "<h3>Estates in System:</h3>\n";
$estates = $db->fetchAll("SELECT id, name, status FROM estates ORDER BY name");
echo "<ul>\n";
foreach ($estates as $estate) {
    echo "<li>" . $estate['name'] . " (ID: " . $estate['id'] . ", Status: " . $estate['status'] . ")</li>\n";
}
echo "</ul>\n";

// Show security personnel assignments
echo "<h3>Security Personnel Assignments:</h3>\n";
$assignments = $db->fetchAll(
    "SELECT sp.*, u.first_name, u.last_name, u.email, e.name as estate_name
     FROM security_personnel sp
     JOIN users u ON sp.user_id = u.id
     JOIN estates e ON sp.estate_id = e.id
     WHERE sp.status = 'active'
     ORDER BY e.name, u.last_name"
);
echo "<ul>\n";
foreach ($assignments as $assignment) {
    echo "<li>" . $assignment['first_name'] . " " . $assignment['last_name'] . 
         " (" . $assignment['email'] . ") assigned to " . $assignment['estate_name'] . 
         " (Badge: " . $assignment['badge_number'] . ")</li>\n";
}
echo "</ul>\n";

// Test query for each estate
echo "<h3>Emergency Alerts by Estate:</h3>\n";
foreach ($estates as $estate) {
    echo "<h4>" . $estate['name'] . " (ID: " . $estate['id'] . "):</h4>\n";
    
    $alerts = $db->fetchAll(
        "SELECT ea.*, t.emergency_contact_name, u.unit_number, p.name as property_name
         FROM emergency_alerts ea
         JOIN tenants t ON ea.tenant_id = t.id
         JOIN units u ON ea.unit_id = u.id
         JOIN properties p ON u.property_id = p.id
         WHERE ea.estate_id = ?
         ORDER BY ea.reported_at DESC",
        [$estate['id']]
    );
    
    if (!empty($alerts)) {
        echo "<ul>\n";
        foreach ($alerts as $alert) {
            echo "<li>[" . $alert['status'] . "] " . $alert['alert_number'] . " - " . 
                 ucfirst(str_replace('_', ' ', $alert['alert_type'])) . 
                 " - " . $alert['property_name'] . " " . $alert['unit_number'] . 
                 " - " . $alert['emergency_contact_name'] . 
                 " - " . date('M j, g:i A', strtotime($alert['reported_at'])) . "</li>\n";
        }
        echo "</ul>\n";
    } else {
        echo "<p>No alerts for this estate</p>\n";
    }
}

// Test specific query that emergency_response.php uses
echo "<h3>Active Emergencies Query Test (Estate ID 1 - SUIT Estate):</h3>\n";
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
    [1] // SUIT Estate ID
);

echo "<p>Found " . count($activeEmergencies) . " active emergencies for SUIT Estate:</p>\n";
echo "<ul>\n";
foreach ($activeEmergencies as $emergency) {
    echo "<li>" . $emergency['alert_number'] . " (" . $emergency['status'] . ") - " . 
         ucfirst(str_replace('_', ' ', $emergency['alert_type'])) . 
         " - " . $emergency['tenant_name'] . 
         " at " . $emergency['property_name'] . " " . $emergency['unit_number'] . 
         " - Reported: " . date('M j, g:i A', strtotime($emergency['reported_at'])) . "</li>\n";
}
echo "</ul>\n";

echo "<hr>\n";
echo "<p><strong>To access the real emergency response page:</strong></p>\n";
echo "<p>1. Log in as security personnel (security1@gmail.com)</p>\n";
echo "<p>2. Go to: <a href='emergency_response.php'>emergency_response.php</a></p>\n";
echo "<p>3. You should see " . count($activeEmergencies) . " active emergencies</p>\n";