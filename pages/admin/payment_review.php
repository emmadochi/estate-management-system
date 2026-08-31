<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Review Payment – EstatePro';
$db = db();
$method = request_method();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estates = estates_for_current_user();
if (!$estates) {
    if (is_super_admin()) {
        flash_set('warning', 'Create an estate first.');
        redirect('estates.php');
    }
    http_response_code(403);
    echo 'No estate access assigned to your account. Please contact an administrator.';
    exit;
}
$estateId = normalize_estate_id($requestedEstateId);

$paymentId = (int)(get_param('payment_id', 0) ?? 0);
if ($paymentId <= 0) {
    flash_set('error', 'Invalid payment selected.');
    redirect('payments.php?estate_id=' . $estateId);
}

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    $reason = trim((string)post_param('reason', ''));

    try {
        $db->beginTransaction();

        $payment = $db->fetchOne(
            "SELECT * FROM payments WHERE id = ? AND estate_id = ? FOR UPDATE",
            [$paymentId, $estateId]
        );
        if (!$payment) {
            throw new RuntimeException('Payment not found.');
        }

        $invoice = $db->fetchOne(
            "SELECT * FROM invoices WHERE id = ? AND estate_id = ? FOR UPDATE",
            [(int)$payment['invoice_id'], $estateId]
        );
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }
        if (($invoice['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Cannot apply payment to a cancelled invoice.');
        }

        // Tenant user (for notifications and email)
        $tenantUser = $db->fetchOne(
            "SELECT u.id, u.email, u.first_name, u.last_name
             FROM tenants t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.id = ?
             LIMIT 1",
            [(int)$payment['tenant_id']]
        );

        if ($action === 'approve') {
            if (($payment['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Only pending payments can be approved.');
            }

            // Mark payment completed.
            $db->execute(
                "UPDATE payments SET status = 'completed' WHERE id = ? AND estate_id = ?",
                [$paymentId, $estateId]
            );

            // Apply payment to invoice.
            $newPaid = (float)$invoice['paid_amount'] + (float)$payment['amount'];
            $newStatus = 'partial';
            if ($newPaid >= (float)$invoice['amount']) {
                $newStatus = 'paid';
                $newPaid = (float)$invoice['amount'];
            } elseif (date('Y-m-d') > (string)$invoice['due_date']) {
                $newStatus = 'overdue';
            }

            $db->execute(
                "UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ? AND estate_id = ?",
                [$newPaid, $newStatus, (int)$invoice['id'], $estateId]
            );

            $db->commit();

            // Notify tenant (in-app)
            if ($tenantUser) {
                $tenantUserId = (int)$tenantUser['id'];
                $tenantEmail = (string)($tenantUser['email'] ?? '');
                $tenantName = trim((string)(($tenantUser['first_name'] ?? '') . ' ' . ($tenantUser['last_name'] ?? '')));

                $title = 'Payment approved for ' . (string)$invoice['invoice_number'];
                $bodyLines = [
                    'Hi ' . ($tenantName !== '' ? $tenantName : 'there') . ',',
                    '',
                    'Your payment request has been approved.',
                    'Invoice: ' . (string)$invoice['invoice_number'],
                    'Amount: ' . number_format((float)$payment['amount'], 2),
                    'Status: approved',
                ];
                $bodyText = implode("\n", $bodyLines);
                $link = app_url('pages/tenant/payments.php');

                notify_user($tenantUserId, 'payment_approved', $title, $bodyText, $link);

                // Email notification
                if ($tenantEmail !== '') {
                    send_basic_email($tenantEmail, $title, $bodyText);
                }
            }

            flash_set('success', 'Payment approved and invoice updated.');
            audit_log(
                'approve',
                'payment',
                $paymentId,
                ['invoice_id' => (int)$invoice['id'], 'amount' => (float)$payment['amount'], 'invoice_status_to' => $newStatus],
                $estateId
            );
            redirect('payments.php?estate_id=' . $estateId . '&invoice_id=' . (int)$invoice['id']);
        }

        if ($action === 'reject') {
            if (($payment['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Only pending payments can be rejected.');
            }
            if ($reason === '') {
                throw new RuntimeException('Rejection reason is required.');
            }

            $existingNotes = trim((string)($payment['notes'] ?? ''));
            $suffix = "Rejected: " . $reason;
            $newNotes = $existingNotes !== '' ? ($existingNotes . "\n" . $suffix) : $suffix;

            $db->execute(
                "UPDATE payments SET status = 'failed', notes = ? WHERE id = ? AND estate_id = ?",
                [$newNotes, $paymentId, $estateId]
            );

            $db->commit();

            // Notify tenant (in-app)
            if ($tenantUser) {
                $tenantUserId = (int)$tenantUser['id'];
                $tenantEmail = (string)($tenantUser['email'] ?? '');
                $tenantName = trim((string)(($tenantUser['first_name'] ?? '') . ' ' . ($tenantUser['last_name'] ?? '')));

                $title = 'Payment rejected for ' . (string)$invoice['invoice_number'];
                $bodyLines = [
                    'Hi ' . ($tenantName !== '' ? $tenantName : 'there') . ',',
                    '',
                    'Your payment request has been rejected.',
                    'Invoice: ' . (string)$invoice['invoice_number'],
                    'Amount: ' . number_format((float)$payment['amount'], 2),
                    'Reason: ' . $reason,
                ];
                $bodyText = implode("\n", $bodyLines);
                $link = app_url('pages/tenant/payments.php');

                notify_user($tenantUserId, 'payment_rejected', $title, $bodyText, $link);

                // Email notification
                if ($tenantEmail !== '') {
                    send_basic_email($tenantEmail, $title, $bodyText);
                }
            }

            flash_set('success', 'Payment rejected.');
            audit_log(
                'reject',
                'payment',
                $paymentId,
                ['invoice_id' => (int)$invoice['id'], 'amount' => (float)$payment['amount'], 'reason' => $reason],
                $estateId
            );
            redirect('payments.php?estate_id=' . $estateId . '&invoice_id=' . (int)$invoice['id']);
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        $db->rollback();
        flash_set('error', $e->getMessage());
        redirect('payment_review.php?estate_id=' . $estateId . '&payment_id=' . $paymentId);
    }
}

$row = $db->fetchOne(
    "SELECT
        p.*,
        i.invoice_number, i.amount AS invoice_amount, i.paid_amount AS invoice_paid_amount, i.status AS invoice_status, i.due_date,
        u.first_name, u.last_name, u.email
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     INNER JOIN tenants t ON t.id = p.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     WHERE p.id = ? AND p.estate_id = ?
     LIMIT 1",
    [$paymentId, $estateId]
);
if (!$row) {
    flash_set('error', 'Payment not found.');
    redirect('payments.php?estate_id=' . $estateId);
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Review payment</h1>
    <div class="text-gray-600">Verify proof and approve/reject pending payments.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-light" href="payments.php?estate_id=<?= $estateId ?>&invoice_id=<?= (int)$row['invoice_id'] ?>">Back</a>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-7">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Payment details</div>
      </div>
      <div class="card-body">
        <div class="fw-bold text-gray-900 mb-1"><?= e($row['payment_reference']) ?></div>
        <div class="text-gray-700 mb-6"><?= e($row['first_name'] . ' ' . $row['last_name']) ?> (<?= e($row['email']) ?>)</div>

        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Invoice</div>
          <div class="fw-bold"><?= e($row['invoice_number']) ?> (<?= e($row['invoice_status']) ?>)</div>
        </div>
        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Amount submitted</div>
          <div class="fw-bold"><?= number_format((float)$row['amount'], 2) ?></div>
        </div>
        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Method</div>
          <div class="fw-bold"><?= e($row['payment_method']) ?></div>
        </div>
        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Payment date</div>
          <div class="fw-bold"><?= e($row['payment_date']) ?></div>
        </div>
        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Status</div>
          <div class="fw-bold"><?= e($row['status']) ?></div>
        </div>

        <div class="separator my-5"></div>

        <div class="mb-4">
          <div class="text-gray-600 mb-1">Proof</div>
          <?php $proofUrl = get_receipt_url($row['receipt_file'] ?? null); ?>
          <?php if ($proofUrl): ?>
            <a class="btn btn-sm btn-light-primary" href="<?= e($proofUrl) ?>" target="_blank" rel="noopener">Open proof file</a>
          <?php else: ?>
            <div class="text-gray-700">No proof uploaded.</div>
          <?php endif; ?>
        </div>

        <div class="mb-0">
          <div class="text-gray-600 mb-1">Notes</div>
          <div class="text-gray-800" style="white-space: pre-wrap;"><?= e((string)($row['notes'] ?? '')) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-5">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Decision</div>
      </div>
      <div class="card-body">
        <?php if (($row['status'] ?? '') !== 'pending'): ?>
          <div class="alert alert-info mb-0">This payment is not pending and cannot be changed here.</div>
        <?php else: ?>
          <form method="post" action="payment_review.php?estate_id=<?= $estateId ?>&payment_id=<?= (int)$row['id'] ?>" class="mb-4" onsubmit="return confirm('Approve this payment? This will update the invoice balance.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-success w-100" type="submit">Approve payment</button>
          </form>

          <form method="post" action="payment_review.php?estate_id=<?= $estateId ?>&payment_id=<?= (int)$row['id'] ?>" onsubmit="return confirm('Reject this payment?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <div class="mb-3">
              <label class="form-label required">Rejection reason</label>
              <textarea class="form-control" name="reason" rows="3" required placeholder="Reason for rejection (visible in notes)"></textarea>
            </div>
            <button class="btn btn-light-danger w-100" type="submit">Reject payment</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

