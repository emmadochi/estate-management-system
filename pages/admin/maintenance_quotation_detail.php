<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Quotation Details – EstatePro';
$db = db();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$ticketId = (int)(get_param('id', 0) ?? 0);

$estates = estates_for_current_user();
if (!$estates) {
    http_response_code(403);
    echo 'No estate access assigned to your account.';
    exit;
}
$estateId = normalize_estate_id($requestedEstateId);

// Get ticket details
$ticket = $db->fetchOne(
    "SELECT
        mt.*,
        un.unit_number, p.name AS property_name,
        v.name AS vendor_name, v.id AS vendor_id,
        u.first_name AS approved_by_first, u.last_name AS approved_by_last,
        tnt.first_name AS tenant_first, tnt.last_name AS tenant_last
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     LEFT JOIN users u ON u.id = mt.approved_by
     INNER JOIN tenants tn ON tn.id = mt.tenant_id
     INNER JOIN users tnt ON tnt.id = tn.user_id
     WHERE mt.id = ? AND mt.estate_id = ?
     LIMIT 1",
    [$ticketId, $estateId]
);

if (!$ticket) {
    flash_set('error', 'Ticket not found.');
    redirect('maintenance_quotations.php?estate_id=' . $estateId);
}

// Get quotation items if detailed quotation exists
$detailedItems = [];
if (!empty($ticket['has_detailed_quotation'])) {
    $detailedItems = $db->fetchAll(
        "SELECT * FROM maintenance_quotation_items WHERE ticket_id = ? ORDER BY created_at ASC",
        [$ticketId]
    );
}

// Get photos
$photos = [];
if (!empty($ticket['before_photo']) || !empty($ticket['after_photo'])) {
    if (!empty($ticket['before_photo'])) {
        $photos[] = ['type' => 'before', 'file' => $ticket['before_photo'], 'label' => 'Before Work'];
    }
    if (!empty($ticket['after_photo'])) {
        $photos[] = ['type' => 'after', 'file' => $ticket['after_photo'], 'label' => 'After Work'];
    }
}

// Get timeline updates
$updates = $db->fetchAll(
    "SELECT u.*, usr.first_name, usr.last_name, usr.role
     FROM maintenance_ticket_updates u
     INNER JOIN users usr ON usr.id = u.updated_by
     WHERE u.ticket_id = ?
     ORDER BY u.created_at DESC
     LIMIT 50",
    [$ticketId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Quotation Details</h1>
    <div class="text-gray-600">Review <?= e($ticket['ticket_number']) ?> - <?= e($ticket['title']) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="maintenance_quotations.php?estate_id=<?= $estateId ?>" class="btn btn-light">
      <i class="fas fa-arrow-left me-2"></i>Back to Quotations
    </a>
    <?php if ($ticket['quote_status'] === 'submitted'): ?>
      <button type="button" class="btn btn-success" 
              onclick="showApproveModal(<?= (int)$ticket['id'] ?>, '<?= e($ticket['ticket_number']) ?>', <?= (float)$ticket['quoted_cost'] ?>)">
        <i class="fas fa-check me-2"></i>Approve
      </button>
      <button type="button" class="btn btn-danger" 
              onclick="showRejectModal(<?= (int)$ticket['id'] ?>, '<?= e($ticket['ticket_number']) ?>')">
        <i class="fas fa-times me-2"></i>Reject
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-8">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-ticket-alt me-2 text-primary"></i>
          Ticket Information
        </div>
      </div>
      <div class="card-body">
        <div class="row g-4">
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Ticket Number</div>
              <div class="fw-bold fs-5"><?= e($ticket['ticket_number']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Title</div>
              <div class="fw-bold"><?= e($ticket['title']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Description</div>
              <div class="text-gray-800"><?= nl2br(e($ticket['description'])) ?></div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Tenant</div>
              <div class="fw-bold"><?= e($ticket['tenant_first'] . ' ' . $ticket['tenant_last']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Unit</div>
              <div class="fw-bold"><?= e($ticket['property_name']) ?> — <?= e($ticket['unit_number']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Vendor</div>
              <div class="fw-bold"><?= e($ticket['vendor_name'] ?? 'Not assigned') ?></div>
            </div>
          </div>
        </div>
        
        <div class="row g-4 mt-2 pt-4 border-top">
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Status</div>
            <div class="fw-bold">
              <span class="badge badge-<?= 
                $ticket['status'] === 'open' ? 'primary' : 
                ($ticket['status'] === 'in_progress' ? 'warning' : 
                ($ticket['status'] === 'resolved' ? 'success' : 'secondary')) 
              ?>"><?= e($ticket['status']) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Priority</div>
            <div class="fw-bold">
              <span class="badge badge-<?= 
                $ticket['priority'] === 'urgent' ? 'danger' : 
                ($ticket['priority'] === 'high' ? 'warning' : 'info') 
              ?>"><?= e($ticket['priority']) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Category</div>
            <div class="fw-bold text-capitalize"><?= e($ticket['category']) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Quotation Details -->
    <div class="card mb-6">
      <div class="card-header bg-light">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-file-invoice-dollar me-2 text-success"></i>
          Quotation Details
        </div>
      </div>
      <div class="card-body">
        <div class="row g-4 mb-4">
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Quotation Status</div>
            <div class="fw-bold">
              <span class="badge badge-<?= 
                $ticket['quote_status'] === 'approved' ? 'success' : 
                ($ticket['quote_status'] === 'rejected' ? 'danger' : 
                ($ticket['quote_status'] === 'submitted' ? 'warning' : 'info')) 
              ?>"><?= e($ticket['quote_status']) ?></span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Submitted At</div>
            <div class="fw-bold"><?= e($ticket['quoted_at'] ? date('M j, Y H:i', strtotime($ticket['quoted_at'])) : 'N/A') ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-gray-600 fs-7">Approved At</div>
            <div class="fw-bold"><?= e($ticket['approved_at'] ? date('M j, Y H:i', strtotime($ticket['approved_at'])) : 'N/A') ?></div>
            <?php if (!empty($ticket['approved_at'])): ?>
              <div class="text-gray-600 fs-8">by <?= e($ticket['approved_by_first'] . ' ' . $ticket['approved_by_last']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($ticket['quotation_notes'])): ?>
          <div class="mb-4 p-3 bg-light rounded">
            <div class="text-gray-600 fs-7 mb-1">Quotation Notes</div>
            <div class="text-gray-800"><?= nl2br(e($ticket['quotation_notes'])) ?></div>
          </div>
        <?php endif; ?>

        <!-- Detailed Quotation Items -->
        <?php if (!empty($detailedItems)): ?>
          <div class="mb-4">
            <h6 class="mb-3 text-primary"><i class="fas fa-list me-2"></i>Detailed Quotation Items</h6>
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
        <?php else: ?>
          <!-- Simple Quotation -->
          <div class="row g-4">
            <div class="col-md-6">
              <div class="p-4 rounded border bg-light">
                <div class="text-gray-600 fs-7 mb-1">Simple Quotation Amount</div>
                <div class="fs-2hx fw-bold text-primary">₦<?= number_format((float)$ticket['quoted_cost'], 2) ?></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-4 rounded border bg-light">
                <div class="text-gray-600 fs-7 mb-1">Actual Cost</div>
                <div class="fs-2hx fw-bold text-success">₦<?= number_format((float)$ticket['cost'], 2) ?></div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Photos -->
    <?php if (!empty($photos)): ?>
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title fw-bold d-flex align-items-center">
            <i class="fas fa-camera me-2 text-info"></i>
            Work Photos
          </div>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <?php foreach ($photos as $photo): ?>
              <div class="col-md-6">
                <div class="text-center">
                  <div class="text-gray-600 fs-7 mb-2"><?= e($photo['label']) ?></div>
                  <img src="../../uploads/<?= e($photo['file']) ?>" 
                       alt="<?= e($photo['label']) ?>" 
                       class="img-fluid rounded border"
                       style="max-height: 300px; object-fit: cover;">
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-xxl-4">
    <!-- Timeline -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-history me-2 text-muted"></i>
          Timeline
        </div>
      </div>
      <div class="card-body">
        <?php if (!$updates): ?>
          <div class="text-gray-600 text-center py-5">
            <i class="fas fa-history fs-2x text-muted mb-3"></i>
            <div>No timeline updates yet</div>
          </div>
        <?php else: ?>
          <div class="timeline timeline-border-dashed">
            <?php foreach ($updates as $u): ?>
              <div class="timeline-item">
                <div class="timeline-line"></div>
                <div class="timeline-icon">
                  <i class="fas fa-circle text-primary"></i>
                </div>
                <div class="timeline-content mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-bold text-gray-900">
                      <?= e(trim((string)(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')))) ?>
                      <span class="badge badge-light ms-2"><?= e($u['role'] ?? '') ?></span>
                    </div>
                    <div class="text-gray-600 fs-8"><?= e(date('M j, Y H:i', strtotime((string)($u['created_at'] ?? 'now')))) ?></div>
                  </div>
                  <div class="text-gray-700">
                    <?php if (!empty($u['from_status']) || !empty($u['to_status'])): ?>
                      <div class="mb-1">
                        <span class="badge badge-light"><?= e($u['from_status'] ?? '') ?></span>
                        <span class="mx-2">→</span>
                        <span class="badge badge-light-primary"><?= e($u['to_status'] ?? '') ?></span>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($u['note'])): ?>
                      <div><?= nl2br(e($u['note'])) ?></div>
                    <?php endif; ?>
                    <div class="text-gray-600 fs-8 mt-1">
                      <?php if (!empty($u['quoted_cost'])): ?>Quoted: ₦<?= number_format((float)$u['quoted_cost'], 2) ?><?php endif; ?>
                      <?php if (!empty($u['actual_cost'])): ?><?= !empty($u['quoted_cost']) ? ' • ' : '' ?>Actual: ₦<?= number_format((float)$u['actual_cost'], 2) ?><?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Invoice Status -->
    <?php if (!empty($ticket['invoice_id'])): ?>
      <?php 
      $invoice = $db->fetchOne(
        "SELECT * FROM maintenance_invoices WHERE id = ?",
        [(int)$ticket['invoice_id']]
      );
      ?>
      <?php if ($invoice): ?>
        <div class="card">
          <div class="card-header bg-info text-white">
            <div class="card-title fw-bold d-flex align-items-center">
              <i class="fas fa-file-invoice me-2"></i>
              Invoice Details
            </div>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Invoice Number</div>
              <div class="fw-bold"><?= e($invoice['invoice_number']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Amount</div>
              <div class="fw-bold fs-4 text-primary">₦<?= number_format((float)$invoice['amount'], 2) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Status</div>
              <div class="fw-bold">
                <span class="badge badge-<?= 
                  $invoice['status'] === 'paid' ? 'success' : 
                  ($invoice['status'] === 'overdue' ? 'danger' : 'warning') 
                ?>"><?= e($invoice['status']) ?></span>
              </div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Due Date</div>
              <div class="fw-bold"><?= e(date('M j, Y', strtotime($invoice['due_date']))) ?></div>
            </div>
            <?php if ($invoice['status'] === 'paid'): ?>
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Paid Amount</div>
                <div class="fw-bold text-success">₦<?= number_format((float)$invoice['paid_amount'], 2) ?></div>
              </div>
            <?php endif; ?>
            <a href="maintenance_invoice_detail.php?id=<?= (int)$invoice['id'] ?>&estate_id=<?= $estateId ?>" 
               class="btn btn-sm btn-light-primary w-100">
              <i class="fas fa-eye me-1"></i>View Invoice Details
            </a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_quotations.php?estate_id=<?= $estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve_quotation">
        <input type="hidden" name="ticket_id" id="approve_ticket_id">
        
        <div class="modal-header">
          <h5 class="modal-title">Approve Quotation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <p>Are you sure you want to approve quotation <strong id="approve_ticket_number"></strong>?</p>
            <p>Amount: <strong id="approve_amount"></strong></p>
          </div>
          
          <div class="mb-4">
            <label class="form-label">Approval Notes (Optional)</label>
            <textarea class="form-control" name="approval_notes" rows="3" placeholder="Add any notes about the approval..."></textarea>
          </div>
          
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="create_invoice" id="create_invoice" value="1" checked>
            <label class="form-check-label" for="create_invoice">
              Create maintenance invoice automatically
            </label>
          </div>
          
          <div class="mb-3" id="due_days_container">
            <label class="form-label">Payment Due (Days)</label>
            <input type="number" class="form-control" name="due_days" value="14" min="1" max="365">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Approve Quotation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_quotations.php?estate_id=<?= $estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_quotation">
        <input type="hidden" name="ticket_id" id="reject_ticket_id">
        
        <div class="modal-header">
          <h5 class="modal-title">Reject Quotation</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <p>Are you sure you want to reject quotation <strong id="reject_ticket_number"></strong>?</p>
          </div>
          
          <div class="mb-3">
            <label class="form-label required">Rejection Reason</label>
            <textarea class="form-control" name="rejection_reason" rows="4" placeholder="Please provide a reason for rejection..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Reject Quotation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showApproveModal(ticketId, ticketNumber, amount) {
    document.getElementById('approve_ticket_id').value = ticketId;
    document.getElementById('approve_ticket_number').textContent = ticketNumber;
    document.getElementById('approve_amount').textContent = '₦' + parseFloat(amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function showRejectModal(ticketId, ticketNumber) {
    document.getElementById('reject_ticket_id').value = ticketId;
    document.getElementById('reject_ticket_number').textContent = ticketNumber;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

// Toggle due days input based on invoice creation
document.getElementById('create_invoice').addEventListener('change', function() {
    const container = document.getElementById('due_days_container');
    container.style.display = this.checked ? 'block' : 'none';
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>