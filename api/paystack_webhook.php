<?php
/**
 * EstatePro Paystack Webhook Receiver
 * Handles real-time payment events with cryptographic signature verification and idempotency lock
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/messaging_service.php';

// Only accept POST requests
if (request_method() !== 'POST') {
    http_response_code(405);
    exit;
}

$input = file_get_contents('php://input');
$paystackSignature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$secretKey = getenv('PAYSTACK_SECRET_KEY') ?: '';

// Verify webhook cryptographic signature if secret key configured
if (!empty($secretKey)) {
    $computedSignature = hash_hmac('sha512', $input, $secretKey);
    if (!hash_equals($computedSignature, $paystackSignature)) {
        http_response_code(400);
        die('Invalid signature');
    }
}

$event = json_decode($input, true);
if (!$event || empty($event['event'])) {
    http_response_code(400);
    die('Invalid payload');
}

$db = db();
$conn = $db->getConnection();

// Handle Successful Charge
if ($event['event'] === 'charge.success') {
    $data = $event['data'] ?? [];
    $reference = (string)($data['reference'] ?? '');
    $amountKobo = (int)($data['amount'] ?? 0);
    $amountNaira = (float)($amountKobo / 100);
    $transactionId = (string)($data['id'] ?? '');

    if ($reference === '') {
        http_response_code(200);
        exit;
    }

    try {
        $conn->beginTransaction();

        // 1. Check for existing payment by reference (Idempotency Check)
        $payment = $db->fetchOne('SELECT * FROM payments WHERE payment_reference = ? LIMIT 1', [$reference]);

        if ($payment) {
            // Already settled - exit cleanly (idempotent response)
            if (($payment['status'] ?? '') === 'completed') {
                $conn->commit();
                http_response_code(200);
                echo 'Payment already processed.';
                exit;
            }

            // Update to completed
            $db->execute(
                "UPDATE payments 
                 SET status = 'completed', transaction_id = ?, payment_date = NOW() 
                 WHERE id = ?",
                [$transactionId, (int)$payment['id']]
            );
            $invoiceId = (int)$payment['invoice_id'];
        } else {
            // Check metadata to link invoice
            $invoiceId = (int)($data['metadata']['invoice_id'] ?? 0);
            $tenantId = (int)($data['metadata']['tenant_id'] ?? 0);
            $estateId = (int)($data['metadata']['estate_id'] ?? 0);

            if ($invoiceId > 0 && $tenantId > 0 && $estateId > 0) {
                $db->execute(
                    "INSERT INTO payments 
                     (payment_reference, invoice_id, tenant_id, estate_id, amount, payment_method, payment_provider, transaction_id, status, payment_date)
                     VALUES (?, ?, ?, ?, ?, 'paystack', 'paystack', ?, 'completed', NOW())",
                    [$reference, $invoiceId, $tenantId, $estateId, $amountNaira, $transactionId]
                );
            }
        }

        // 2. Update Invoice Status
        if (!empty($invoiceId)) {
            $inv = $db->fetchOne('SELECT id, amount, paid_amount FROM invoices WHERE id = ?', [$invoiceId]);
            if ($inv) {
                $newPaid = min((float)$inv['amount'], (float)$inv['paid_amount'] + $amountNaira);
                $newStatus = ($newPaid >= (float)$inv['amount']) ? 'paid' : 'partial';
                $db->execute('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?', [$newPaid, $newStatus, $invoiceId]);
            }
        }

        $conn->commit();

        // 3. Dispatch confirmation SMS / WhatsApp
        $tenantUser = $db->fetchOne(
            "SELECT u.phone, u.first_name, est.name AS estate_name, i.invoice_number
             FROM invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             INNER JOIN users u ON u.id = t.user_id
             INNER JOIN estates est ON est.id = i.estate_id
             WHERE i.id = ?",
            [$invoiceId]
        );

        if ($tenantUser && !empty($tenantUser['phone'])) {
            $msg = "EstatePro Receipt: Payment of ₦" . number_format($amountNaira, 2) . " for Invoice {$tenantUser['invoice_number']} ({$tenantUser['estate_name']}) was received and verified. Thank you!";
            messaging()->sendSms((string)$tenantUser['phone'], $msg);
            messaging()->sendWhatsApp((string)$tenantUser['phone'], $msg);
        }

        audit_log('webhook_charge_success', 'payment', (int)($payment['id'] ?? 0), [
            'reference' => $reference,
            'amount' => $amountNaira,
            'gateway' => 'paystack'
        ]);

        http_response_code(200);
        echo 'Webhook processed successfully.';
        exit;

    } catch (Throwable $e) {
        $conn->rollBack();
        http_response_code(500);
        echo 'Server Error: ' . $e->getMessage();
        exit;
    }
}

// Acknowledge other events
http_response_code(200);
echo 'Event received.';
