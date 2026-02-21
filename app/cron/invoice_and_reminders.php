<?php
/**
 * Automatic Invoice Generation and Reminder System
 * 
 * This script should be run daily (e.g., via cron or Task Scheduler).
 * 
 * Features:
 * - Generates invoices automatically for active leases based on payment frequency
 * - Creates reminder notifications for upcoming invoices (7 days, 3 days, due date)
 * - Creates weekly reminders for overdue invoices
 * 
 * Usage:
 * - Via cron: php app/cron/invoice_and_reminders.php
 * - Via web: http://yourdomain.com/app/cron/invoice_and_reminders.php?key=YOUR_SECRET_KEY
 */

declare(strict_types=1);

// Security: Require a secret key when accessed via web
// Change this to a strong random string in production!
$secretKey = 'CHANGE_THIS_SECRET_KEY_IN_PRODUCTION_' . md5(__FILE__);
$providedKey = $_GET['key'] ?? '';
$isCli = php_sapi_name() === 'cli';
$isWebExecution = !$isCli;

// Allow execution from admin panel (when included)
$isAdminExecution = defined('INVOICE_AUTOMATION_ADMIN_MODE');

if ($isWebExecution && !$isAdminExecution && $providedKey !== $secretKey) {
    http_response_code(403);
    die('Access denied. Provide ?key=SECRET_KEY');
}

require_once __DIR__ . '/../bootstrap.php';

/**
 * Generate invoices for active leases based on payment frequency
 */
function generate_invoices_for_leases($db, &$log, &$errors): void {
    $today = date('Y-m-d');
    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    
    // Get all active leases
    $leases = $db->fetchAll(
        "SELECT l.*, t.user_id, t.estate_id, t.unit_id, u.first_name, u.last_name, u.email
         FROM leases l
         INNER JOIN tenants t ON t.id = l.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE l.status = 'active'
           AND l.start_date <= ?
           AND l.end_date >= ?
         ORDER BY l.id",
        [$today, $today]
    );
    
    foreach ($leases as $lease) {
        $leaseId = (int)$lease['id'];
        $tenantId = (int)$lease['tenant_id'];
        $estateId = (int)$lease['estate_id'];
        $unitId = (int)$lease['unit_id'];
        $userId = (int)$lease['user_id'];
        $frequency = (string)$lease['payment_frequency'];
        $rentAmount = (float)$lease['rent_amount'];
        $serviceCharge = (float)($lease['service_charge'] ?? 0);
        
        // Skip if lease doesn't have valid amounts
        if ($rentAmount <= 0 && $serviceCharge <= 0) {
            continue;
        }
        
        // Determine next invoice period based on frequency
        // Strategy: Generate invoice approximately 1 month before due date
        // For monthly: Generate on 1st of current month for next month (due 1st of next month)
        $nextDueDate = null;
        $periodDescription = '';
        
        switch ($frequency) {
            case 'monthly':
                // Generate invoice for next month on the 1st of current month
                // E.g., on Feb 1-28, generate invoice for March (due March 1)
                // This gives tenants ~1 month notice
                $nextMonth = $currentMonth + 1;
                $nextYear = $currentYear;
                if ($nextMonth > 12) {
                    $nextMonth = 1;
                    $nextYear++;
                }
                $nextDueDate = sprintf('%04d-%02d-01', $nextYear, $nextMonth);
                $periodDescription = date('F Y', strtotime($nextDueDate));
                break;
                
            case 'quarterly':
                // Generate invoice for next quarter
                // Quarters typically start on 1st of month
                $currentQuarter = (int)ceil($currentMonth / 3);
                $nextQuarter = $currentQuarter + 1;
                $nextYear = $currentYear;
                if ($nextQuarter > 4) {
                    $nextQuarter = 1;
                    $nextYear++;
                }
                $nextMonth = ($nextQuarter - 1) * 3 + 1;
                $nextDueDate = sprintf('%04d-%02d-01', $nextYear, $nextMonth);
                $periodDescription = 'Q' . $nextQuarter . ' ' . $nextYear;
                break;
                
            case 'yearly':
                // Generate invoice for next year
                // Use 1st of January as standard due date
                $nextYear = $currentYear + 1;
                $nextDueDate = sprintf('%04d-01-01', $nextYear);
                $periodDescription = $nextYear;
                break;
                
            case 'custom':
                // Skip custom frequency - requires manual handling
                continue 2;
                
            default:
                continue 2;
        }
        
        // Don't generate if due date is beyond lease end date
        if ($nextDueDate > $lease['end_date']) {
            continue;
        }
        
        // Check if invoice already exists for this lease and period
        // We use a simple check: invoice with same lease_id, type, and due_date
        $existingRent = null;
        $existingServiceCharge = null;
        
        if ($rentAmount > 0) {
            $existingRent = $db->fetchOne(
                "SELECT id FROM invoices 
                 WHERE lease_id = ? AND type = 'rent' AND due_date = ? AND status != 'cancelled'",
                [$leaseId, $nextDueDate]
            );
        }
        
        if ($serviceCharge > 0) {
            $existingServiceCharge = $db->fetchOne(
                "SELECT id FROM invoices 
                 WHERE lease_id = ? AND type = 'service_charge' AND due_date = ? AND status != 'cancelled'",
                [$leaseId, $nextDueDate]
            );
        }
        
        // Generate rent invoice if needed
        if ($rentAmount > 0 && !$existingRent) {
            try {
                $invoiceNumber = 'INV-' . date('YmdHis') . '-' . random_int(100, 999);
                $description = "Rent for {$periodDescription} - {$lease['lease_number']}";
                
                $invoiceId = (int)$db->insert(
                    "INSERT INTO invoices
                     (invoice_number, tenant_id, lease_id, unit_id, estate_id, type, amount, due_date, status, paid_amount, description)
                     VALUES (?, ?, ?, ?, ?, 'rent', ?, ?, 'pending', 0, ?)",
                    [$invoiceNumber, $tenantId, $leaseId, $unitId, $estateId, $rentAmount, $nextDueDate, $description]
                );
                
                $log[] = "Generated rent invoice {$invoiceNumber} for {$lease['first_name']} {$lease['last_name']} (due {$nextDueDate})";
                
                // Notify tenant
                notify_user(
                    $userId,
                    'invoice_created',
                    "New Invoice: Rent for {$periodDescription}",
                    "Invoice {$invoiceNumber} for ₦" . number_format($rentAmount, 2) . " is due on " . date('M j, Y', strtotime($nextDueDate)),
                    'invoices.php'
                );
                
            } catch (Throwable $e) {
                $errors[] = "Failed to generate rent invoice for lease {$lease['lease_number']}: " . $e->getMessage();
            }
        }
        
        // Generate service charge invoice if needed
        if ($serviceCharge > 0 && !$existingServiceCharge) {
            try {
                $invoiceNumber = 'INV-' . date('YmdHis') . '-' . random_int(100, 999);
                $description = "Service charge for {$periodDescription} - {$lease['lease_number']}";
                
                $invoiceId = (int)$db->insert(
                    "INSERT INTO invoices
                     (invoice_number, tenant_id, lease_id, unit_id, estate_id, type, amount, due_date, status, paid_amount, description)
                     VALUES (?, ?, ?, ?, ?, 'service_charge', ?, ?, 'pending', 0, ?)",
                    [$invoiceNumber, $tenantId, $leaseId, $unitId, $estateId, $serviceCharge, $nextDueDate, $description]
                );
                
                $log[] = "Generated service charge invoice {$invoiceNumber} for {$lease['first_name']} {$lease['last_name']} (due {$nextDueDate})";
                
                // Notify tenant
                notify_user(
                    $userId,
                    'invoice_created',
                    "New Invoice: Service Charge for {$periodDescription}",
                    "Invoice {$invoiceNumber} for ₦" . number_format($serviceCharge, 2) . " is due on " . date('M j, Y', strtotime($nextDueDate)),
                    'invoices.php'
                );
                
            } catch (Throwable $e) {
                $errors[] = "Failed to generate service charge invoice for lease {$lease['lease_number']}: " . $e->getMessage();
            }
        }
    }
}

/**
 * Create reminder notifications for upcoming invoices
 */
function create_invoice_reminders($db, &$log, &$errors): void {
    $today = date('Y-m-d');
    
    // Get pending invoices that are due soon or overdue
    $invoices = $db->fetchAll(
        "SELECT i.*, t.user_id, u.first_name, u.last_name, u.email
         FROM invoices i
         INNER JOIN tenants t ON t.id = i.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE i.status IN ('pending', 'partial')
           AND i.due_date >= DATE_SUB(?, INTERVAL 7 DAY)
           AND i.due_date <= DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY i.due_date ASC",
        [$today, $today]
    );
    
    foreach ($invoices as $invoice) {
        $invoiceId = (int)$invoice['id'];
        $userId = (int)$invoice['user_id'];
        $dueDate = strtotime($invoice['due_date']);
        $todayTimestamp = strtotime($today);
        $daysUntilDue = (int)(($dueDate - $todayTimestamp) / 86400);
        $amount = (float)$invoice['amount'];
        $paidAmount = (float)($invoice['paid_amount'] ?? 0);
        $balance = $amount - $paidAmount;
        
        // Skip if already paid
        if ($balance <= 0) {
            continue;
        }
        
        $invoiceNumber = $invoice['invoice_number'];
        $type = ucfirst(str_replace('_', ' ', (string)$invoice['type']));
        
        // Determine reminder type
        $reminderType = null;
        $title = '';
        $body = '';
        
        if ($daysUntilDue === 7) {
            $reminderType = 'invoice_reminder_7days';
            $title = "Reminder: {$type} Due in 7 Days";
            $body = "Invoice {$invoiceNumber} for ₦" . number_format($balance, 2) . " is due in 7 days (" . date('M j, Y', $dueDate) . ").";
        } elseif ($daysUntilDue === 3) {
            $reminderType = 'invoice_reminder_3days';
            $title = "Reminder: {$type} Due in 3 Days";
            $body = "Invoice {$invoiceNumber} for ₦" . number_format($balance, 2) . " is due in 3 days (" . date('M j, Y', $dueDate) . ").";
        } elseif ($daysUntilDue === 0) {
            $reminderType = 'invoice_reminder_due';
            $title = "Reminder: {$type} Due Today";
            $body = "Invoice {$invoiceNumber} for ₦" . number_format($balance, 2) . " is due today. Please make payment as soon as possible.";
        } elseif ($daysUntilDue < 0) {
            // Overdue - handled separately
            continue;
        }
        
        if ($reminderType) {
            // Check if we already sent this reminder today (avoid duplicates)
            $existingReminder = $db->fetchOne(
                "SELECT id FROM notifications 
                 WHERE user_id = ? AND type = ? AND link LIKE ? AND DATE(created_at) = ?",
                [$userId, $reminderType, '%invoices.php%', $today]
            );
            
            if (!$existingReminder) {
                try {
                    notify_user($userId, $reminderType, $title, $body, 'invoices.php');
                    $log[] = "Sent {$reminderType} reminder to {$invoice['first_name']} {$invoice['last_name']} for invoice {$invoiceNumber}";
                } catch (Throwable $e) {
                    $errors[] = "Failed to send reminder for invoice {$invoiceNumber}: " . $e->getMessage();
                }
            }
        }
    }
}

/**
 * Create weekly reminders for overdue invoices
 */
function create_overdue_reminders($db, &$log, &$errors): void {
    $today = date('Y-m-d');
    
    // Get overdue invoices (status pending/partial and due_date < today)
    $invoices = $db->fetchAll(
        "SELECT i.*, t.user_id, u.first_name, u.last_name, u.email,
                DATEDIFF(?, i.due_date) AS days_overdue
         FROM invoices i
         INNER JOIN tenants t ON t.id = i.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE i.status IN ('pending', 'partial')
           AND i.due_date < ?
           AND (i.amount - COALESCE(i.paid_amount, 0)) > 0
         ORDER BY i.due_date ASC",
        [$today, $today]
    );
    
    foreach ($invoices as $invoice) {
        $invoiceId = (int)$invoice['id'];
        $userId = (int)$invoice['user_id'];
        $daysOverdue = (int)$invoice['days_overdue'];
        $amount = (float)$invoice['amount'];
        $paidAmount = (float)($invoice['paid_amount'] ?? 0);
        $balance = $amount - $paidAmount;
        
        // Send reminder weekly (every 7 days overdue)
        // E.g., send on day 7, 14, 21, 28, etc.
        if ($daysOverdue % 7 !== 0) {
            continue;
        }
        
        $invoiceNumber = $invoice['invoice_number'];
        $type = ucfirst(str_replace('_', ' ', (string)$invoice['type']));
        $weeksOverdue = (int)($daysOverdue / 7);
        
        // Check if we already sent this reminder today (avoid duplicates)
        $existingReminder = $db->fetchOne(
            "SELECT id FROM notifications 
             WHERE user_id = ? AND type = 'invoice_overdue' AND link LIKE ? AND DATE(created_at) = ?",
            [$userId, '%invoices.php%', $today]
        );
        
        if (!$existingReminder) {
            try {
                $title = "Overdue: {$type} Payment Required";
                $body = "Invoice {$invoiceNumber} for ₦" . number_format($balance, 2) . " is {$daysOverdue} day(s) overdue ({$weeksOverdue} week" . ($weeksOverdue > 1 ? 's' : '') . "). Please make payment immediately.";
                
                notify_user($userId, 'invoice_overdue', $title, $body, 'invoices.php');
                
                // Also update invoice status to overdue if it's still pending
                if ($invoice['status'] === 'pending') {
                    $db->execute("UPDATE invoices SET status = 'overdue' WHERE id = ?", [$invoiceId]);
                }
                
                $log[] = "Sent overdue reminder to {$invoice['first_name']} {$invoice['last_name']} for invoice {$invoiceNumber} ({$daysOverdue} days overdue)";
            } catch (Throwable $e) {
                $errors[] = "Failed to send overdue reminder for invoice {$invoiceNumber}: " . $e->getMessage();
            }
        }
    }
}

/**
 * Main execution function - can be called from admin panel or run standalone
 */
function run_invoice_automation(): array {
    $db = db();
    $log = [];
    $errors = [];
    
    try {
        $startTime = microtime(true);
        
        generate_invoices_for_leases($db, $log, $errors);
        create_invoice_reminders($db, $log, $errors);
        create_overdue_reminders($db, $log, $errors);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        return [
            'success' => true,
            'duration' => $duration,
            'logs' => $log,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => "Fatal error: " . $e->getMessage(),
            'logs' => $log,
            'errors' => $errors,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

// Only execute automatically if NOT included from admin panel
if (!defined('INVOICE_AUTOMATION_ADMIN_MODE')) {
    $result = run_invoice_automation();
    
    // Output results
    if (php_sapi_name() === 'cli') {
        echo "=== Invoice Generation & Reminders ===\n";
        echo "Execution time: {$result['duration']}s\n\n";
        
        if (!empty($result['logs'])) {
            echo "SUCCESS:\n";
            foreach ($result['logs'] as $msg) {
                echo "  ✓ {$msg}\n";
            }
            echo "\n";
        }
        
        if (!empty($result['errors'])) {
            echo "ERRORS:\n";
            foreach ($result['errors'] as $err) {
                echo "  ✗ {$err}\n";
            }
            echo "\n";
        }
        
        if (empty($result['logs']) && empty($result['errors'])) {
            echo "No actions taken. All invoices are up to date.\n";
        }
        
        if (!$result['success']) {
            echo "ERROR: {$result['error']}\n";
            exit(1);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
    }
}
