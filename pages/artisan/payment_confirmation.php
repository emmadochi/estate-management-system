<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$vendor = require_artisan();
$db = db();
$method = request_method();

$pageTitle = 'Payment Confirmation – Artisan Area';
$pageHeading = 'Payment Confirmation';

$vendorId = (int)($vendor['id'] ?? 0);

// Get invoices for this vendor
$invoices = $db->fetchAll(
    "SELECT 
        mi.*,
        mt.ticket_number, mt.title as ticket_title,
        e.name as estate_name
     FROM maintenance_invoices mi
     INNER JOIN maintenance_tickets mt ON mt.id = mi.ticket_id
     INNER JOIN estates e ON e.id = mi.estate_id
     WHERE mi.vendor_id = ? AND mi.status IN ('paid', 'partial')
     ORDER BY mi.created_at DESC
     LIMIT 50",
    [$vendorId]
);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    
    if ($action === 'confirm_payment') {
        $paymentId = (int)(post_param('payment_id', 0) ?? 0);
        $confirmationNotes = trim((string)post_param('confirmation_notes', ''));
        
        if ($paymentId > 0) {
            try {
                // Verify this payment belongs to vendor's invoice
                $payment = $db->fetchOne(
                    "SELECT mp.*, mi.vendor_id 
                     FROM maintenance_payments mp
                     INNER JOIN maintenance_invoices mi ON mi.id = mp.invoice_id
                     WHERE mp.id = ? AND mi.vendor_id = ?",
                    [$paymentId, $vendorId]
                );
                
                if ($payment) {
                    $db->execute(
                        "UPDATE maintenance_payments 
                         SET confirmed_by_vendor = TRUE, 
                             vendor_confirmation_date = NOW(),
                             notes = CONCAT(COALESCE(notes, ''), '\nVendor confirmed receipt: ', ?)
                         WHERE id = ?",
                        [$confirmationNotes, $paymentId]
                    );
                    
                    flash_set('success', 'Payment confirmation recorded successfully.');
                } else {
                    flash_set('error', 'Payment not found or not accessible.');
                }
            } catch (Throwable $e) {
                flash_set('error', 'Error confirming payment: ' . $e->getMessage());
            }
        }
        redirect('payment_confirmation.php');
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Payment Confirmation</h1>
    <div class="text-gray-600">Confirm receipt of payments for completed work</div>
  </div>
</div>

<?php if (!$invoices): ?>
  <div class="card">
    <div class="card-body text-center py-10">
      <div class="symbol symbol-100px mx-auto mb-5">
        <i class="fas fa-money-bill-wave text-muted fs-1"></i>
      </div>
      <h4 class="text-gray-700">No payments to confirm</h4>
      <p class="text-gray-500">You don't have any paid invoices that require confirmation.</p>
    </div>
  </div>
<?php else: ?>
  <div class="row g-6">
    <?php foreach ($invoices as $invoice): ?>
      <div class="col-12">
        <div class="card mb-6">
          <div class="card-header <?= $invoice['status'] === 'paid' ? 'bg-success' : 'bg-warning' ?> text-white">
            <div class="card-title fw-bold d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center">
                <i class="fas fa-file-invoice me-2"></i>
                <?= e($invoice['invoice_number']) ?> - <?= e($invoice['ticket_title']) ?>
              </div>
              <div>
                <span class="badge badge-light"><?= e($invoice['status']) ?></span>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-4 mb-4">
              <div class="col-md-3">
                <div class="text-gray-600 fs-7">Estate</div>
                <div class="fw-bold"><?= e($invoice['estate_name']) ?></div>
              </div>
              <div class="col-md-3">
                <div class="text-gray-600 fs-7">Ticket</div>
                <div class="fw-bold"><?= e($invoice['ticket_number']) ?></div>
              </div>
              <div class="col-md-3">
                <div class="text-gray-600 fs-7">Total Amount</div>
                <div class="fw-bold text-primary">₦<?= number_format((float)$invoice['amount'], 2) ?></div>
              </div>
              <div class="col-md-3">
                <div class="text-gray-600 fs-7">Paid Amount</div>
                <div class="fw-bold text-success">₦<?= number_format((float)$invoice['paid_amount'], 2) ?></div>
              </div>
            </div>
            
            <?php if ((float)$invoice['amount'] > (float)$invoice['paid_amount']): ?>
              <div class="alert alert-warning mb-4">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Partial Payment:</strong> This invoice has a remaining balance of 
                <strong>₦<?= number_format((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2) ?></strong>
              </div>
            <?php endif; ?>
            
            <!-- Payment History -->
            <?php 
            $payments = $db->fetchAll(
                "SELECT mp.*, u.first_name, u.last_name
                 FROM maintenance_payments mp
                 LEFT JOIN users u ON u.id = mp.paid_by
                 WHERE mp.invoice_id = ?
                 ORDER BY mp.created_at DESC",
                [(int)$invoice['id']]
            );
            ?>
            
            <?php if ($payments): ?>
              <div class="mb-4">
                <h6 class="mb-3 text-primary"><i class="fas fa-receipt me-2"></i>Payment History</h6>
                <div class="table-responsive">
                  <table class="table table-bordered">
                    <thead class="table-light">
                      <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Transaction ID</th>
                        <th>Receipt</th>
                        <th>Status</th>
                        <th>Confirmed</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($payments as $p): ?>
                        <tr>
                          <td><?= e(date('M j, Y', strtotime((string)($p['payment_date'] ?? 'now')))) ?></td>
                          <td class="fw-bold">₦<?= number_format((float)$p['amount'], 2) ?></td>
                          <td><span class="badge badge-light"><?= e($p['payment_method']) ?></span></td>
                          <td><?= e($p['transaction_id'] ?? 'N/A') ?></td>
                          <td><?= e($p['receipt_number'] ?? 'N/A') ?></td>
                          <td>
                            <span class="badge badge-<?= $p['status'] === 'completed' ? 'success' : 'warning' ?>">
                              <?= e($p['status']) ?>
                            </span>
                          </td>
                          <td>
                            <?php if (!empty($p['confirmed_by_vendor'])): ?>
                              <span class="badge badge-success">
                                <i class="fas fa-check me-1"></i>Confirmed
                              </span>
                              <div class="text-muted fs-8">
                                <?= e($p['vendor_confirmation_date'] ? date('M j, Y H:i', strtotime($p['vendor_confirmation_date'])) : '') ?>
                              </div>
                            <?php else: ?>
                              <span class="badge badge-light">Pending</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if (empty($p['confirmed_by_vendor'])): ?>
                              <button type="button" class="btn btn-sm btn-light-primary" 
                                      onclick="showConfirmModal(<?= (int)$p['id'] ?>, '<?= e($invoice['invoice_number']) ?>', <?= (float)$p['amount'] ?>)">
                                <i class="fas fa-check me-1"></i>Confirm
                              </button>
                            <?php else: ?>
                              <span class="text-muted">Confirmed</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Confirm Payment Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="payment_confirmation.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm_payment">
        <input type="hidden" name="payment_id" id="confirm_payment_id">
        
        <div class="modal-header">
          <h5 class="modal-title">Confirm Payment Receipt</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <p>Confirming receipt of payment for invoice <strong id="confirm_invoice_number"></strong></p>
            <p>Amount: <strong id="confirm_amount"></strong></p>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Confirmation Notes (Optional)</label>
            <textarea class="form-control" name="confirmation_notes" rows="3" 
                      placeholder="Add any notes about the payment confirmation..."></textarea>
          </div>
          
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Note:</strong> Once confirmed, this payment will be marked as received and 
            the confirmation cannot be undone.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Confirm Receipt</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showConfirmModal(paymentId, invoiceNumber, amount) {
    document.getElementById('confirm_payment_id').value = paymentId;
    document.getElementById('confirm_invoice_number').textContent = invoiceNumber;
    document.getElementById('confirm_amount').textContent = '₦' + parseFloat(amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>