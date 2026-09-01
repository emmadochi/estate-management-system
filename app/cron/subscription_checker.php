<?php
/**
 * Estate Subscription Expiry and Renewal Monitor Cron
 * 
 * Runs daily to:
 * 1. Check estate subscriptions reaching renewal within 7 days.
 * 2. Transition lapsed/overdue subscriptions to 'expired' or 'grace_period'.
 * 3. Log notification alerts for super administrators.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function check_estate_subscriptions(): array {
    $db = db();
    $today = date('Y-m-d');
    $log = [];

    // 1. Mark overdue active subscriptions past next_billing_date as expired
    try {
        $expiredSubs = $db->fetchAll(
            "SELECT es.id, es.estate_id, es.next_billing_date, est.name AS estate_name
             FROM estate_subscriptions es
             INNER JOIN estates est ON est.id = es.estate_id
             WHERE es.status = 'active'
               AND es.next_billing_date < ?
               AND es.auto_renew = 0",
            [$today]
        ) ?: [];

        foreach ($expiredSubs as $sub) {
            $db->execute("UPDATE estate_subscriptions SET status = 'expired' WHERE id = ?", [(int)$sub['id']]);
            $log[] = "Marked subscription #{$sub['id']} for '{$sub['estate_name']}' as expired (Due: {$sub['next_billing_date']}).";
        }

        // 2. Alert for subscriptions expiring in the next 7 days
        $in7Days = date('Y-m-d', strtotime('+7 days'));
        $expiringSoon = $db->fetchAll(
            "SELECT es.id, es.estate_id, es.next_billing_date, es.amount, est.name AS estate_name
             FROM estate_subscriptions es
             INNER JOIN estates est ON est.id = es.estate_id
             WHERE es.status = 'active'
               AND es.next_billing_date BETWEEN ? AND ?",
            [$today, $in7Days]
        ) ?: [];

        foreach ($expiringSoon as $es) {
            $log[] = "Notice: Subscription for '{$es['estate_name']}' is due for renewal on {$es['next_billing_date']} (₦" . number_format((float)$es['amount'], 2) . ").";
        }
    } catch (Throwable $e) {
        $log[] = "Subscription Checker Error: " . $e->getMessage();
    }

    return $log;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "========================================\n";
    echo "Estate Subscription Checker Run: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    $results = check_estate_subscriptions();
    if (empty($results)) {
        echo "✓ All estate subscriptions are active and up to date.\n";
    } else {
        foreach ($results as $r) {
            echo "• {$r}\n";
        }
    }
}
