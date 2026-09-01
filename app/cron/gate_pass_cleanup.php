<?php
/**
 * Gate Pass & Emergency Alert Cleanup Cron
 * 
 * Runs daily to:
 * 1. Mark expired visitor gate passes past their 'valid_until' timestamp as 'expired'.
 * 2. Archive resolved security alerts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function cleanup_gate_passes_and_alerts(): array {
    $db = db();
    $log = [];

    try {
        // 1. Expire outdated gate passes
        $expiredCount = $db->execute(
            "UPDATE gate_passes 
             SET status = 'expired' 
             WHERE status = 'active' AND valid_until < NOW()"
        );
        $log[] = "Updated {$expiredCount} expired visitor gate passes.";

        // 2. Archive resolved emergency alerts older than 30 days if table exists
        $hasEmergencyTable = $db->fetchOne("SHOW TABLES LIKE 'emergency_alerts'");
        if ($hasEmergencyTable) {
            $archivedAlerts = $db->execute(
                "UPDATE emergency_alerts 
                 SET status = 'archived' 
                 WHERE status = 'resolved' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            $log[] = "Archived {$archivedAlerts} old resolved emergency incidents.";
        }
    } catch (Throwable $e) {
        $log[] = "Gate Pass Cleanup Error: " . $e->getMessage();
    }

    return $log;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "========================================\n";
    echo "Gate Pass & Alert Cleanup Run: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    $results = cleanup_gate_passes_and_alerts();
    foreach ($results as $r) {
        echo "• {$r}\n";
    }
}
