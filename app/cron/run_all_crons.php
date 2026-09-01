<?php
/**
 * EstatePro Master Automation Cron Runner
 * 
 * Scheduled in cPanel / Linux Crontab as a single daily cron job:
 * 0 2 * * * php /path/to/public_html/app/cron/run_all_crons.php >> /path/to/cron.log 2>&1
 */

declare(strict_types=1);

define('INVOICE_AUTOMATION_ADMIN_MODE', true);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/invoice_and_reminders.php';
require_once __DIR__ . '/subscription_checker.php';
require_once __DIR__ . '/gate_pass_cleanup.php';

$startTime = microtime(true);
$runDate = date('Y-m-d H:i:s');

echo "==========================================================\n";
echo "EstatePro Master Cron Automation Execution: {$runDate}\n";
echo "==========================================================\n\n";

// 1. Invoices & Rent Reminders
echo "▶ [1/3] Running Invoice Generation & Payment Reminders...\n";
$invLogs = [];
$invErrors = [];
$db = db();
generate_invoices_for_leases($db, $invLogs, $invErrors);
create_invoice_reminders($db, $invLogs, $invErrors);
echo "  • Completed: " . count($invLogs) . " invoice/reminder tasks processed.\n";
if (!empty($invErrors)) {
    echo "  ⚠ Errors encountered: " . count($invErrors) . "\n";
}

// 2. Subscription Expiry Checks
echo "\n▶ [2/3] Running SaaS Subscription Monitoring...\n";
$subLogs = check_estate_subscriptions();
foreach ($subLogs as $sl) {
    echo "  • {$sl}\n";
}

// 3. Gate Pass & Emergency Alert Cleanup
echo "\n▶ [3/3] Running Gate Pass & Alert Cleanup...\n";
$gpLogs = cleanup_gate_passes_and_alerts();
foreach ($gpLogs as $gl) {
    echo "  • {$gl}\n";
}

$elapsed = round(microtime(true) - $startTime, 3);
echo "\n==========================================================\n";
echo "✓ Master Cron Completed Successfully in {$elapsed}s\n";
echo "==========================================================\n";
