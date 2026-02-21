<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Invoice Details – EstatePro';
$db = db();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$invoiceId = (int)(get_param('id', 0) ?? 0);

$estates = estates_for_current_user();
if (!$estates) {
    http_response_code(403);
    echo 'No estate access assigned to your account.';
    exit;
}
$estateId = normalize_estate_id($requestedEstateId);

// Get invoice details
$invoice = $db->fetchOne(
    "SELECT
        mi.*,
        mt.ticket_number, mt.title as ticket_title,
        v.name as vendor_name, v.id as vendor_id,
        u.first_name as approved_by_first, u.last_name as approved_by_last
     FROM maintenance_invoices mi
     INNER JOIN maintenance_tickets mt ON mt.id = mi.ticket_id
     INNER JOIN vendors v ON v.id = mi.vendor_id
     LEFT JOIN users u ON u.id = mi.approved_by
     WHERE mi.id = ? AND mi.estate_id = ?
     LIMIT 1",
    [$invoiceId, $estateId]
);

if (!$invoice) {
    flash_set('error', 'Invoice not found.');
    redirect('maintenance_quotations.php?estate_id=' . $estateId);
}

// Get payments for this invoice
$payments = $db->fetchAll(
    "SELECT mp.*, u.first_name, u.last_name
     FROM maintenance_payments mp
     LEFT JOIN users u ON u.id = mp.paid_by
     WHERE mp.invoice_id = ?
     ORDER BY mp.created_at DESC",
    [$invoiceId]
);

// Get ticket details for additional context
$ticket = $db->fetchOne(
    "SELECT mt.*, un.unit_number, p.name as property_name
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     WHERE mt.id = ?",
    [(int)$invoice['ticket_id']]
);

// Get detailed quotation items if available
$detailedItems = [];
if ($ticket && !empty($ticket['has_detailed_quotation'])) {
    $detailedItems = $db->fetchAll(
        "SELECT * FROM maintenance_quotation_items WHERE ticket_id = ? ORDER BY created_at ASC",
        [(int)$ticket['id']]
    );
}

$method = request_method();
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'record_payment') {
        $amount = (float)(post_param('amount', 0) ?? 0);
        $paymentMethod = (string)post_param('payment_method', '');
        $transactionId = trim((string)post_param('transaction_id', ''));
        $receiptNumber = trim((string)post_param('receipt_number', ''));
        $notes = trim((string)post_param('notes', ''));
        $paymentDate = trim((string)post_param('payment_date', ''));

        if ($amount > 0 && $paymentMethod !== '' && $paymentDate !== '') {
            try {
                $paymentReference = 'MPAY-' . date('YmdHis') . '-' . random_int(100, 999);
                
                $paymentId = $db->insert(
                    "INSERT INTO maintenance_payments 
                     (payment_reference, invoice_id, vendor_id, estate_id, amount, payment_method, 
                      transaction_id, payment_date, receipt_number, notes, paid_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $paymentReference,
                        $invoiceId,
                        (int)$invoice['vendor_id'],
                        $estateId,
                        $amount,
                        $paymentMethod,
                        $transactionId,
                        $paymentDate,
                        $receiptNumber,
                        $notes,
                        current_user_id()
                    ]
                );

                // Update invoice paid amount
                $newPaidAmount = (float)$invoice['paid_amount'] + $amount;
                $newStatus = $newPaidAmount >= (float)$invoice['amount'] ? 'paid' : 'partial';
                
                $db->execute(
                    "UPDATE maintenance_invoices 
                     SET paid_amount = ?, status = ?
                     WHERE id = ?",
                    [$newPaidAmount, $newStatus, $invoiceId]
                );

                // Update ticket paid status
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET paid_status = 'paid', paid_at = NOW()
                     WHERE id = ?",
                    [(int)$invoice['ticket_id']]
                );

                flash_set('success', 'Payment recorded successfully.');
            } catch (Throwable $e) {
                flash_set('error', 'Error recording payment: ' . $e->getMessage());
            }
        }
        redirect('maintenance_invoice_detail.php?id=' . $invoiceId . '&estate_id=' . $estateId);
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Invoice Details</h1>
    <div class="text-gray-600"><?= e($invoice['invoice_number']) ?> - <?= e($invoice['ticket_title']) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="maintenance_quotations.php?estate_id=<?= $estateId ?>" class="btn btn-light">
      <i class="fas fa-arrow-left me-2"></i>Back to Quotations
    </a>
    <?php if ($invoice['status'] !== 'paid'): ?>
      <button type="button" class="btn btn-primary" onclick="showPaymentModal()">
        <i class="fas fa-plus me-2"></i>Record Payment
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-8">
    <div class="card mb-6">
      <div class="card-header bg-primary text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-file-invoice me-2"></i>
          Invoice Information
        </div>
      </div>
      <div class="card-body">
        <div class="row g-4">
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Invoice Number</div>
              <div class="fw-bold fs-4"><?= e($invoice['invoice_number']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Ticket</div>
              <div class="fw-bold">
                <a href="maintenance_quotation_detail.php?id=<?= (int)$invoice['ticket_id'] ?>&estate_id=<?= $estateId ?>" 
                   class="text-primary">
                  <?= e($invoice['ticket_number']) ?> - <?= e($invoice['ticket_title']) ?>
                </a>
              </div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Vendor</div>
              <div class="fw-bold"><?= e($invoice['vendor_name']) ?></div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Total Amount</div>
              <div class="fw-bold fs-2hx text-primary">₦<?= number_format((float)$invoice['amount'], 2) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Paid Amount</div>
              <div class="fw-bold fs-3 text-success">₦<?= number_format((float)$invoice['paid_amount'], 2) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Balance</div>
              <div class="fw-bold fs-4 text-<?= ((float)$invoice['amount'] - (float)$invoice['paid_amount']) > 0 ? 'warning' : 'success' ?>">
                ₦<?= number_format((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2) ?>
              </div>
            </div>
          </div>
        </div>
        
        <div class="row g-4 mt-2 pt-4 border-top">
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Status</div>
            <div class="fw-bold">
              <span class="badge badge-<?= 
                $invoice['status'] === 'paid' ? 'success' : 
                ($invoice['status'] === 'overdue' ? 'danger' : 'warning') 
              ?>"><?= e($invoice['status']) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Due Date</div>
            <div class="fw-bold"><?= e(date('M j, Y', strtotime($invoice['due_date']))) ?></div>
            <?php if (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid'): ?>
              <div class="text-danger fs-8">Overdue</div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Approved By</div>
            <div class="fw-bold"><?= e($invoice['approved_by_first'] . ' ' . $invoice['approved_by_last']) ?></div>
            <div class="text-gray-600 fs-8"><?= e($invoice['approved_at'] ? date('M j, Y H:i', strtotime($invoice['approved_at'])) : '') ?></div>
          </div>
        </div>
        
        <?php if (!empty($invoice['description'])): ?>
          <div class="mt-4 pt-4 border-top">
            <div class="text-gray-600 fs-7 mb-2">Description</div>
            <div class="text-gray-800"><?= nl2br(e($invoice['description'])) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Work Details -->
    <?php if ($ticket): ?>
      <div class="card mb-6">
        <div class="card-header bg-light">
          <div class="card-title fw-bold d-flex align-items-center">
            <i class="fas fa-tools me-2 text-muted"></i>
            Work Details
          </div>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Unit</div>
                <div class="fw-bold"><?= e($ticket['property_name']) ?> — <?= e($ticket['unit_number']) ?></div>
              </div>
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Status</div>
                <div class="fw-bold">
                  <span class="badge badge-<?= 
                    $ticket['status'] === 'open' ? 'primary' : 
                    ($ticket['status'] === 'in_progress' ? 'warning' : 
                    ($ticket['status'] === 'resolved' ? 'success' : 'secondary')) 
                  ?>"><?= e($ticket['status']) ?></span>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Priority</div>
                <div class="fw-bold">
                  <span class="badge badge-<?= 
                    $ticket['priority'] === 'urgent' ? 'danger' : 
                    ($ticket['priority'] === 'high' ? 'warning' : 'info') 
                  ?>"><?= e($ticket['priority']) ?></span>
                </div>
              </div>
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Category</div>
                <div class="fw-bold text-capitalize"><?= e($ticket['category']) ?></div>
              </div>
            </div>
          </div>
          
          <?php if (!empty($detailedItems)): ?>
            <div class="mt-4 pt-4 border-top">
              <h6 class="mb-3 text-primary"><i class="fas fa-list me-2"></i>Detailed Work Items</h6>
              <div class="table-responsive">
                <table class="table table-hover table-bordered">
                  <thead class="table-light">
                    <tr>
                      <th>Description</th>
                      <th>Type</th>
                      <th class="text-center">Qty</th>
                      <th class="text-center">Unit Price</th>
                      <th class="text-center">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php 
                    $detailedTotal = 0;
                    foreach ($detailedItems as $item):
                      $detailedTotal += $item['total_price'];
                    ?>
                    <tr>
                      <td><?= e($item['item_description']) ?></td>
                      <td class="text-center">
                        <span class="badge badge-<?= 
                          $item['item_type'] === 'material' ? 'primary' : 
                          ($item['item_type'] === 'labor' ? 'success' : 
                          ($item['item_type'] === 'equipment' ? 'warning' : 'secondary')) 
                        ?>"><?= e($item['item_type']) ?></span>
                      </td>
                      <td class="text-center fw-bold"><?= (int)$item['quantity'] ?></td>
                      <td class="text-center">₦<?= number_format($item['unit_price'], 2) ?></td>
                      <td class="text-center fw-bold text-success">₦<?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-primary fw-bold">
                      <td colspan="4" class="text-end">TOTAL:</td>
                      <td class="text-center text-primary fs-5">₦<?= number_format($detailedTotal, 2) ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-xxl-4">
    <!-- Payment History -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-money-bill-wave me-2 text-success"></i>
          Payment History
        </div>
      </div>
      <div class="card-body">
        <?php if (!$payments): ?>
          <div class="text-center py-5">
            <i class="fas fa-receipt fs-2x text-muted mb-3"></i>
            <div class="text-gray-600">No payments recorded yet</div>
          </div>
        <?php else: ?>
          <div class="timeline timeline-border-dashed">
            <?php foreach ($payments as $p): ?>
              <div class="timeline-item">
                <div class="timeline-line"></div>
                <div class="timeline-icon">
                  <i class="fas fa-circle text-success"></i>
                </div>
                <div class="timeline-content mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-bold text-gray-900">
                      ₦<?= number_format((float)$p['amount'], 2) ?>
                    </div>
                    <div class="text-gray-600 fs-8"><?= e(date('M j, Y', strtotime((string)($p['payment_date'] ?? 'now')))) ?></div>
                  </div>
                  <div class="text-gray-700">
                    <div class="mb-1">
                      <span class="badge badge-light"><?= e($p['payment_method']) ?></span>
                      <?php if (!empty($p['transaction_id'])): ?>
                        <span class="badge badge-light ms-1"><?= e($p['transaction_id']) ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($p['receipt_number'])): ?>
                      <div class="text-muted fs-8">Receipt: <?= e($p['receipt_number']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['notes'])): ?>
                      <div class="mt-1"><?= nl2br(e($p['notes'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($p['first_name']) || !empty($p['last_name'])): ?>
                      <div class="text-muted fs-8 mt-1">Recorded by: <?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Payment Summary -->
    <div class="card">
      <div class="card-header bg-success text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-calculator me-2"></i>
          Payment Summary
        </div>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-3">
          <div class="text-gray-600">Total Invoice</div>
          <div class="fw-bold">₦<?= number_format((float)$invoice['amount'], 2) ?></div>
        </div>
        <div class="d-flex justify-content-between mb-3">
          <div class="text-gray-600">Total Paid</div>
          <div class="fw-bold text-success">₦<?= number_format((float)$invoice['paid_amount'], 2) ?></div>
        </div>
        <div class="d-flex justify-content-between mb-4">
          <div class="text-gray-600">Balance</div>
          <div class="fw-bold text-<?= ((float)$invoice['amount'] - (float)$invoice['paid_amount']) > 0 ? 'warning' : 'success' ?>">
            ₦<?= number_format((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2) ?>
          </div>
        </div>
        <div class="progress h-8px">
          <div class="progress-bar bg-<?= $invoice['status'] === 'paid' ? 'success' : 'warning' ?>" 
               role="progressbar" 
               style="width: <?= min(100, ($invoice['paid_amount'] / max(1, $invoice['amount'])) * 100) ?>%"></div>
        </div>
        <div class="text-center mt-2">
          <span class="text-muted fs-8">
            <?= round(min(100, ($invoice['paid_amount'] / max(1, $invoice['amount'])) * 100)) ?>% paid
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_invoice_detail.php?id=<?= $invoiceId ?>&estate_id=<?= $estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_payment">
        
        <div class="modal-header">
          <h5 class="modal-title">Record Payment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <p>Recording payment for invoice <strong><?= e($invoice['invoice_number']) ?></strong></p>
            <p>Balance: <strong>₦<?= number_format((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2) ?></strong></p>
          </div>
          
          <div class="row g-3 mb-4">
            <div class="col-12">
              <label class="form-label required">Amount</label>
              <div class="input-group">
                <span class="input-group-text">₦</span>
                <input type="number" class="form-control" name="amount" 
                       step="0.01" min="0.01" max="<?= (float)$invoice['amount'] - (float)$invoice['paid_amount'] ?>"
                       value="<?= (float)$invoice['amount'] - (float)$invoice['paid_amount'] ?>" required>
              </div>
            </div>
            
            <div class="col-12">
              <label class="form-label required">Payment Method</label>
              <select class="form-select" name="payment_method" required>
                <option value="">Select payment method</option>
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="card">Card</option>
                <option value="other">Other</option>
              </select>
            </div>
            
            <div class="col-12">
              <label class="form-label">Transaction ID (Optional)</label>
              <input type="text" class="form-control" name="transaction_id" placeholder="Transaction reference number">
            </div>
            
            <div class="col-12">
              <label class="form-label">Receipt Number (Optional)</label>
              <input type="text" class="form-control" name="receipt_number" placeholder="Receipt number">
            </div>
            
            <div class="col-12">
              <label class="form-label required">Payment Date</label>
              <input type="date" class="form-control" name="payment_date" 
                     value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <div class="col-12">
              <label class="form-label">Notes (Optional)</label>
              <textarea class="form-control" name="notes" rows="3" placeholder="Additional payment notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showPaymentModal() {
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>