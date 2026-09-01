<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Payments – EstatePro';
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

$invoiceId = (int)(get_param('invoice_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'record') {
        $invoiceIdPost = (int)(post_param('invoice_id', 0) ?? 0);
        $amount = (float)(post_param('amount', 0) ?? 0);
        $methodVal = (string)post_param('payment_method', 'cash');
        $note = trim((string)post_param('notes', ''));

        if ($invoiceIdPost <= 0 || $amount <= 0) {
            flash_set('error', 'Invoice and amount are required.');
            redirect('payments.php?estate_id=' . $estateId);
        }

        try {
            $db->beginTransaction();

            $invoice = $db->fetchOne('SELECT * FROM invoices WHERE id = ? AND estate_id = ?', [$invoiceIdPost, $estateId]);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }
            if (($invoice['status'] ?? '') === 'cancelled') {
                throw new RuntimeException('Cannot pay a cancelled invoice.');
            }

            $reference = 'PAY-' . date('YmdHis') . '-' . random_int(100, 999);
            $paymentId = (int)$db->insert(
                "INSERT INTO payments
                 (payment_reference, invoice_id, tenant_id, estate_id, amount, payment_method, payment_provider, transaction_id, status, payment_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 'completed', NOW(), NULLIF(?, ''))",
                [$reference, (int)$invoice['id'], (int)$invoice['tenant_id'], (int)$invoice['estate_id'], $amount, $methodVal, $note]
            );

            $newPaid = (float)$invoice['paid_amount'] + $amount;
            $newStatus = 'partial';
            if ($newPaid >= (float)$invoice['amount']) {
                $newStatus = 'paid';
                $newPaid = (float)$invoice['amount'];
            } elseif (date('Y-m-d') > (string)$invoice['due_date']) {
                $newStatus = 'overdue';
            }

            $db->execute(
                "UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?",
                [$newPaid, $newStatus, (int)$invoice['id']]
            );

            $db->commit();
            flash_set('success', 'Payment recorded.');
            audit_log(
                'create',
                'payment',
                $paymentId,
                ['payment_reference' => $reference, 'invoice_id' => (int)$invoice['id'], 'amount' => $amount, 'method' => $methodVal, 'invoice_status_to' => $newStatus],
                (int)$invoice['estate_id']
            );
        } catch (Throwable $e) {
            $db->rollback();
            flash_set('error', $e->getMessage());
        }

        redirect('payments.php?estate_id=' . $estateId . '&invoice_id=' . $invoiceIdPost);
    }
}

$invoice = null;
if ($invoiceId > 0) {
    $invoice = $db->fetchOne(
        "SELECT
            i.*,
            u.first_name, u.last_name, u.email
         FROM invoices i
         INNER JOIN tenants t ON t.id = i.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE i.id = ? AND i.estate_id = ?",
        [$invoiceId, $estateId]
    );
}

$payments = $db->fetchAll(
    "SELECT p.*, i.invoice_number
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     WHERE p.estate_id = ? " . ($invoiceId > 0 ? "AND p.invoice_id = ?" : "") . "
     ORDER BY p.payment_date DESC
     LIMIT 300",
    $invoiceId > 0 ? [$estateId, $invoiceId] : [$estateId]
);

$invoices = $db->fetchAll(
    "SELECT id, invoice_number, amount, paid_amount, status
     FROM invoices
     WHERE estate_id = ?
     ORDER BY created_at DESC
     LIMIT 200",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Payments</h1>
    <div class="text-gray-600">Record payments and automatically update invoice balances.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="payments.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-5">
        <label class="form-label">Estate</label>
        <select class="form-select" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label">Invoice (optional)</label>
        <select class="form-select" name="invoice_id" onchange="this.form.submit()">
          <option value="0" <?= $invoiceId <= 0 ? 'selected' : '' ?>>All invoices</option>
          <?php foreach ($invoices as $inv): ?>
            <option value="<?= (int)$inv['id'] ?>" <?= (int)$inv['id'] === $invoiceId ? 'selected' : '' ?>>
              <?= e($inv['invoice_number']) ?> — <?= e($inv['status']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
      <div class="col-12">
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-sm btn-light" href="invoices.php?estate_id=<?= $estateId ?>">Invoices</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Record payment</div>
      </div>
      <div class="card-body">
        <?php if (!$invoices): ?>
          <div class="text-gray-600">No invoices found. Create invoices first.</div>
        <?php else: ?>
          <form method="post" action="payments.php?estate_id=<?= $estateId ?><?= $invoiceId ? '&invoice_id=' . $invoiceId : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record">

            <div class="mb-4">
              <label class="form-label required">Invoice</label>
              <select class="form-select" name="invoice_id" required>
                <?php foreach ($invoices as $inv): ?>
                  <option value="<?= (int)$inv['id'] ?>" <?= (int)$inv['id'] === $invoiceId ? 'selected' : '' ?>>
                    <?= e($inv['invoice_number']) ?> (<?= e($inv['status']) ?>) — due: <?= number_format((float)$inv['amount'] - (float)$inv['paid_amount'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label required">Amount</label>
                <input class="form-control" type="number" step="0.01" min="0" name="amount" required>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Method</label>
                <select class="form-select" name="payment_method">
                  <option value="cash">cash</option>
                  <option value="bank_transfer">bank_transfer</option>
                  <option value="card">card</option>
                  <option value="paystack">paystack</option>
                  <option value="flutterwave">flutterwave</option>
                  <option value="wallet">wallet</option>
                  <option value="other">other</option>
                </select>
              </div>
            </div>

            <div class="mb-6">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="3"></textarea>
            </div>

            <button class="btn btn-primary" type="submit">Record payment</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($invoice): ?>
      <div class="card mt-6">
        <div class="card-header">
          <div class="card-title fw-bold">Selected invoice</div>
        </div>
        <div class="card-body">
          <div class="fw-bold text-gray-900"><?= e($invoice['invoice_number']) ?></div>
          <div class="text-gray-700"><?= e($invoice['first_name'] . ' ' . $invoice['last_name']) ?> (<?= e($invoice['email']) ?>)</div>
          <div class="separator my-4"></div>
          <div class="d-flex flex-stack">
            <div class="text-gray-600">Amount</div>
            <div class="fw-bold"><?= number_format((float)$invoice['amount'], 2) ?></div>
          </div>
          <div class="d-flex flex-stack mt-2">
            <div class="text-gray-600">Paid</div>
            <div class="fw-bold"><?= number_format((float)$invoice['paid_amount'], 2) ?></div>
          </div>
          <div class="d-flex flex-stack mt-2">
            <div class="text-gray-600">Balance</div>
            <div class="fw-bold"><?= number_format((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2) ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Payments list</div>
      </div>
      <div class="card-body">
        <?php if (!$payments): ?>
          <div class="text-gray-600">No payments found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Reference</th>
                  <th>Invoice</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th>Proof</th>
                  <th class="text-end">Amount</th>
                  <th class="text-end">Date</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($payments as $p): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($p['payment_reference']) ?></td>
                  <td class="text-gray-700"><?= e($p['invoice_number']) ?></td>
                  <td><span class="badge badge-light"><?= e($p['payment_method']) ?></span></td>
                  <td><span class="badge badge-light"><?= e($p['status']) ?></span></td>
                  <td>
                    <?php $proofUrl = get_receipt_url($p['receipt_file'] ?? null); ?>
                    <?php if ($proofUrl): ?>
                      <a href="<?= e($proofUrl) ?>" target="_blank" rel="noopener">View</a>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= number_format((float)$p['amount'], 2) ?></td>
                  <td class="text-end text-gray-700"><?= e($p['payment_date']) ?></td>
                  <td class="text-end">
                    <?php if (($p['status'] ?? '') === 'pending'): ?>
                      <a class="btn btn-sm btn-light-primary" href="payment_review.php?estate_id=<?= $estateId ?>&payment_id=<?= (int)$p['id'] ?>">Review</a>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

