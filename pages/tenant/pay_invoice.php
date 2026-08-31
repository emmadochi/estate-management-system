<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Pay Invoice – EstatePro Tenant';
$pageHeading = 'Pay Invoice';
$db = db();

if ($tenant === null) {
    flash_set('error', 'No active tenancy linked to your account.');
    redirect('dashboard.php');
}

$invoiceId = (int)(get_param('invoice_id', 0) ?? 0);
if ($invoiceId <= 0) {
    flash_set('error', 'Invalid invoice selected.');
    redirect('invoices.php');
}

$invoice = $db->fetchOne(
    "SELECT id, invoice_number, amount, paid_amount, due_date, status, tenant_id, estate_id
     FROM invoices
     WHERE id = ? AND tenant_id = ?
     LIMIT 1",
    [$invoiceId, (int)$tenant['id']]
);
if (!$invoice) {
    flash_set('error', 'Invoice not found.');
    redirect('invoices.php');
}
if (($invoice['status'] ?? '') === 'cancelled') {
    flash_set('error', 'This invoice was cancelled and cannot be paid.');
    redirect('invoices.php');
}

$balance = (float)$invoice['amount'] - (float)($invoice['paid_amount'] ?? 0);
if ($balance <= 0) {
    flash_set('warning', 'This invoice is already fully paid.');
    redirect('invoices.php');
}

$method = request_method();
if ($method === 'POST') {
    verify_csrf();

    $amount = (float)(post_param('amount', 0) ?? 0);
    $methodVal = (string)post_param('payment_method', 'bank_transfer');
    $paidTo = trim((string)post_param('paid_to_account', ''));
    $transactionRef = trim((string)post_param('transaction_reference', ''));
    $paymentDate = (string)post_param('payment_date', '');
    $notes = trim((string)post_param('notes', ''));

    if ($amount <= 0) {
        flash_set('error', 'Amount is required.');
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }
    if ($amount > $balance) {
        flash_set('error', 'Amount cannot exceed the invoice balance.');
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }

    if ($paymentDate === '') {
        flash_set('error', 'Payment date is required.');
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }

    // For bank transfers, require "paid to" + transaction reference + proof.
    $needsProof = in_array($methodVal, ['bank_transfer'], true);
    if ($needsProof && $paidTo === '') {
        flash_set('error', 'Please specify the account you paid into.');
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }
    if ($needsProof && $transactionRef === '') {
        flash_set('error', 'Please enter your bank transfer reference / narration.');
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }

    // Upload proof (optional for some methods, required for bank transfers).
    $receiptFile = handle_receipt_upload('proof', 'inv_' . (int)$invoice['id']);
    if ($needsProof && !$receiptFile) {
        // handle_receipt_upload sets flash on validation failure; still need a friendly message when no file was selected.
        if (!isset($_FILES['proof']) || ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Please upload proof of payment (image or PDF).');
        }
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }

    try {
        $db->beginTransaction();

        // Re-check invoice within transaction to avoid paying a cancelled invoice mid-flight.
        $fresh = $db->fetchOne(
            "SELECT id, amount, paid_amount, status, due_date
             FROM invoices
             WHERE id = ? AND tenant_id = ?
             FOR UPDATE",
            [$invoiceId, (int)$tenant['id']]
        );
        if (!$fresh) {
            throw new RuntimeException('Invoice not found.');
        }
        if (($fresh['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Cannot pay a cancelled invoice.');
        }
        $freshBalance = (float)$fresh['amount'] - (float)($fresh['paid_amount'] ?? 0);
        if ($freshBalance <= 0) {
            throw new RuntimeException('Invoice is already fully paid.');
        }
        if ($amount > $freshBalance) {
            throw new RuntimeException('Amount cannot exceed the invoice balance.');
        }

        $reference = 'PAYREQ-' . date('YmdHis') . '-' . random_int(100, 999);
        $createdBy = (int)(current_user_id() ?? 0);
        if ($createdBy <= 0) {
            throw new RuntimeException('Authentication error. Please sign in again.');
        }

        // Store extra info in notes (schema has no dedicated columns yet).
        $combinedNotesParts = [];
        if ($paidTo !== '') $combinedNotesParts[] = 'Paid to: ' . $paidTo;
        if ($transactionRef !== '') $combinedNotesParts[] = 'Txn ref: ' . $transactionRef;
        if ($notes !== '') $combinedNotesParts[] = $notes;
        $combinedNotes = trim(implode("\n", $combinedNotesParts));

        $paymentId = (int)$db->insert(
            "INSERT INTO payments
             (payment_reference, invoice_id, tenant_id, estate_id, amount, payment_method, payment_provider, transaction_id, status, payment_date, receipt_file, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 'pending', ?, ?, NULLIF(?, ''), ?)",
            [
                $reference,
                (int)$fresh['id'],
                (int)$tenant['id'],
                (int)$invoice['estate_id'],
                $amount,
                $methodVal,
                $paymentDate . ' 00:00:00',
                $receiptFile,
                $combinedNotes,
                $createdBy,
            ]
        );

        // Also store in documents for centralized file management (optional but useful).
        if ($receiptFile) {
            $db->insert(
                "INSERT INTO documents (estate_id, related_type, related_id, name, file_path, file_type, category, uploaded_by, created_at)
                 VALUES (?, 'payment', ?, ?, ?, ?, 'receipt', ?, NOW())",
                [
                    (int)$invoice['estate_id'],
                    $paymentId,
                    'Payment proof - ' . (string)$invoice['invoice_number'],
                    'uploads/receipts/' . basename($receiptFile),
                    pathinfo($receiptFile, PATHINFO_EXTENSION),
                    $createdBy,
                ]
            );
        }

        $db->commit();

        // Notify estate admins / managers that there is a new payment request to review.
        $title = 'New payment request for ' . (string)$invoice['invoice_number'];
        $bodyLines = [
            'Tenant: ' . trim((string)($tenant['full_name'] ?? (($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? '')))),
            'Amount: ' . number_format($amount, 2),
            'Status: pending review',
        ];
        $bodyText = implode("\n", array_filter($bodyLines));
        $link = app_url('pages/admin/payment_review.php?estate_id=' . (int)$invoice['estate_id'] . '&payment_id=' . $paymentId);
        notify_estate_admins((int)$invoice['estate_id'], 'payment_pending', $title, $bodyText, $link);

        flash_set('success', 'Payment submitted for verification. You will be notified once it is approved.');
        redirect('payments.php');
    } catch (Throwable $e) {
        $db->rollback();
        flash_set('error', $e->getMessage());
        redirect('pay_invoice.php?invoice_id=' . $invoiceId);
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Submit payment</h3>
        <a href="invoices.php" class="btn btn-sm btn-light-primary">Back to invoices</a>
    </div>
    <div class="card-body">
        <div class="mb-6">
            <div class="fw-bold text-gray-900"><?= e($invoice['invoice_number']) ?></div>
            <div class="text-gray-700">Balance: <?= e(number_format($balance, 2)) ?> • Due: <?= e(date('M j, Y', strtotime($invoice['due_date']))) ?></div>
        </div>

        <form method="post" action="pay_invoice.php?invoice_id=<?= (int)$invoice['id'] ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="row g-4">
                <div class="col-12 col-md-6">
                    <label class="form-label required">Amount paid</label>
                    <input class="form-control" type="number" step="0.01" min="0" max="<?= e((string)$balance) ?>" name="amount" required>
                    <div class="form-text">You can submit a partial payment.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label required">Payment date</label>
                    <input class="form-control" type="date" name="payment_date" required>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label required">Method</label>
                    <select class="form-select" name="payment_method" required>
                        <option value="bank_transfer">bank transfer</option>
                        <option value="cash">cash</option>
                        <option value="card">card</option>
                        <option value="other">other</option>
                    </select>
                    <div class="form-text">For bank transfers, proof upload is required.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Account paid to (for bank transfer)</label>
                    <input class="form-control" type="text" name="paid_to_account" placeholder="e.g., EstatePro LTD - GTBank 0123456789">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Transaction reference / narration (for bank transfer)</label>
                    <input class="form-control" type="text" name="transaction_reference" placeholder="e.g., FT1234567890">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Proof of payment (image or PDF)</label>
                    <input class="form-control" type="file" name="proof" accept="image/*,application/pdf">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes (optional)</label>
                    <textarea class="form-control" name="notes" rows="3" placeholder="Any extra details to help verification"></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-6">
                <button class="btn btn-primary" type="submit">Submit for approval</button>
                <a class="btn btn-light" href="invoices.php">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

