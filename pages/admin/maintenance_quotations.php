<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Maintenance Management – EstatePro';
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

// Get filter parameters
$statusFilter = (string)(get_param('status', '') ?? '');
$vendorFilter = (int)(get_param('vendor_id', 0) ?? 0);
$editId = (int)(get_param('edit_id', 0) ?? 0);
$action = (string)(get_param('action', '') ?? '');

// Get vendors for dropdown
$vendors = $db->fetchAll(
    "SELECT id, name, specialization
     FROM vendors
     WHERE estate_id = ? OR estate_id IS NULL
     ORDER BY name ASC",
    [$estateId]
);

// Get tenants for dropdown
$tenants = $db->fetchAll(
    "SELECT t.id, u.first_name, u.last_name, un.unit_number, p.name AS property_name
     FROM tenants t
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = t.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     WHERE t.estate_id = ?
     ORDER BY u.first_name ASC, u.last_name ASC",
    [$estateId]
);

// Get staff users for assignment
$staffUsers = $db->fetchAll(
    "SELECT id, first_name, last_name, role
     FROM users
     WHERE role IN ('staff','security','estate_admin','property_manager')
     ORDER BY first_name ASC, last_name ASC",
    []
);

// Build query for maintenance tickets with quotations
$where = ['mt.estate_id = ?'];
$params = [$estateId];

if ($statusFilter !== '' && in_array($statusFilter, ['submitted', 'approved', 'rejected', 'draft'], true)) {
    $where[] = 'mt.quote_status = ?';
    $params[] = $statusFilter;
}

if ($vendorFilter > 0) {
    $where[] = 'mt.vendor_id = ?';
    $params[] = $vendorFilter;
}

// Only show tickets with quotations
$where[] = '(mt.has_detailed_quotation = TRUE OR mt.quoted_cost > 0)';

$tickets = $db->fetchAll(
    "SELECT
        mt.id, mt.ticket_number, mt.title, mt.quote_status, mt.quoted_cost, 
        mt.quoted_at, mt.approved_at, mt.approved_by, mt.invoice_id,
        mt.has_detailed_quotation, mt.quotation_submitted_at,
        un.unit_number, p.name AS property_name,
        v.name AS vendor_name, v.id AS vendor_id,
        u.first_name AS approved_by_first, u.last_name AS approved_by_last
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     LEFT JOIN users u ON u.id = mt.approved_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY mt.quoted_at DESC, mt.created_at DESC
     LIMIT 200",
    $params
);

// Handle form submissions
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'approve_quotation') {
        $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
        $approvalNotes = trim((string)post_param('approval_notes', ''));
        $createInvoice = (bool)(post_param('create_invoice', false) ?? false);
        $dueDays = (int)(post_param('due_days', 14) ?? 14);

        if ($ticketId > 0) {
            try {
                $ticket = $db->fetchOne(
                    "SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?",
                    [$ticketId, $estateId]
                );
                
                if ($ticket) {
                    // Update ticket status
                    $db->execute(
                        "UPDATE maintenance_tickets 
                         SET quote_status = 'approved', 
                             approved_at = NOW(), 
                             approved_by = ?
                         WHERE id = ?",
                        [current_user_id(), $ticketId]
                    );
                    
                    // Create audit log
                    audit_log('approve', 'maintenance_quotation', $ticketId, [
                        'ticket_id' => $ticketId,
                        'quote_status' => 'approved',
                        'approval_notes' => $approvalNotes
                    ], $estateId);
                    
                    // Create invoice if requested
                    if ($createInvoice && $ticket['vendor_id'] > 0) {
                        $invoiceAmount = $ticket['has_detailed_quotation'] ? 
                            $db->fetchOne(
                                "SELECT SUM(total_price) as total FROM maintenance_quotation_items WHERE ticket_id = ?",
                                [$ticketId]
                            )['total'] ?? $ticket['quoted_cost'] :
                            $ticket['quoted_cost'];
                        
                        $invoiceNumber = 'MAINT-' . date('YmdHis') . '-' . random_int(100, 999);
                        $dueDate = date('Y-m-d', strtotime("+$dueDays days"));
                        
                        $invoiceId = $db->insert(
                            "INSERT INTO maintenance_invoices 
                             (invoice_number, ticket_id, vendor_id, estate_id, amount, description, due_date, approved_by, approved_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                            [
                                $invoiceNumber,
                                $ticketId,
                                (int)$ticket['vendor_id'],
                                $estateId,
                                $invoiceAmount,
                                'Maintenance work approval - ' . $ticket['title'],
                                $dueDate,
                                current_user_id()
                            ]
                        );
                        
                        // Link invoice to ticket
                        $db->execute(
                            "UPDATE maintenance_tickets SET invoice_id = ? WHERE id = ?",
                            [$invoiceId, $ticketId]
                        );
                    }
                    
                    flash_set('success', 'Quotation approved successfully.');
                }
            } catch (Throwable $e) {
                flash_set('error', 'Error approving quotation: ' . $e->getMessage());
            }
        }
        redirect('maintenance_quotations.php?estate_id=' . $estateId);
    }

    if ($action === 'reject_quotation') {
        $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
        $rejectionReason = trim((string)post_param('rejection_reason', ''));

        if ($ticketId > 0 && $rejectionReason !== '') {
            try {
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET quote_status = 'rejected', 
                         approved_at = NULL,
                         approved_by = NULL
                     WHERE id = ? AND estate_id = ?",
                    [$ticketId, $estateId]
                );
                
                // Add timeline update
                $db->insert(
                    "INSERT INTO maintenance_ticket_updates (ticket_id, updated_by, note, to_status)
                     VALUES (?, ?, ?, ?)",
                    [
                        $ticketId,
                        current_user_id(),
                        "Quotation rejected: $rejectionReason",
                        'open'
                    ]
                );
                
                // Create audit log
                audit_log('reject', 'maintenance_quotation', $ticketId, [
                    'ticket_id' => $ticketId,
                    'quote_status' => 'rejected',
                    'rejection_reason' => $rejectionReason
                ], $estateId);
                
                flash_set('success', 'Quotation rejected.');
            } catch (Throwable $e) {
                flash_set('error', 'Error rejecting quotation: ' . $e->getMessage());
            }
        }
        redirect('maintenance_quotations.php?estate_id=' . $estateId);
    }
}

// Handle ticket creation/editing
if ($method === 'POST' && in_array($action, ['create_ticket', 'update_ticket'])) {
    verify_csrf();
    
    $tenantId = (int)(post_param('tenant_id', 0) ?? 0);
    $category = (string)post_param('category', 'other');
    $priority = (string)post_param('priority', 'medium');
    $title = trim((string)post_param('title', ''));
    $description = trim((string)post_param('description', ''));
    $status = (string)post_param('status', 'open');
    $assignedTo = (int)(post_param('assigned_to', 0) ?? 0);
    $vendorId = (int)(post_param('vendor_id', 0) ?? 0);
    $cost = (float)(post_param('cost', 0) ?? 0);
    $quotedCost = (float)(post_param('quoted_cost', 0) ?? 0);
    $quoteStatus = (string)post_param('quote_status', 'none');
    $paidStatus = (string)post_param('paid_status', 'unpaid');
    $resolutionNotes = trim((string)post_param('resolution_notes', ''));
    
    if ($title === '' || $description === '') {
        flash_set('error', 'Title and description are required.');
        redirect('maintenance_quotations.php?estate_id=' . $estateId . ($editId ? ('&edit_id=' . $editId) : '&action=create'));
    }
    
    try {
        $allowedQuoteStatus = ['none','draft','submitted','approved','rejected'];
        $allowedPaidStatus = ['unpaid','paid'];
        if (!in_array($quoteStatus, $allowedQuoteStatus, true)) {
            $quoteStatus = 'none';
        }
        if (!in_array($paidStatus, $allowedPaidStatus, true)) {
            $paidStatus = 'unpaid';
        }
        
        if ($action === 'update_ticket' && $editId > 0) {
            // Update existing ticket
            $db->execute(
                "UPDATE maintenance_tickets
                 SET category = ?, priority = ?, title = ?, description = ?, status = ?,
                     assigned_to = NULLIF(?, 0), vendor_id = NULLIF(?, 0),
                     cost = ?, quoted_cost = ?, quote_status = ?,
                     paid_status = ?, resolution_notes = NULLIF(?, '')
                 WHERE id = ? AND estate_id = ?",
                [$category, $priority, $title, $description, $status, 
                 $assignedTo, $vendorId, $cost, $quotedCost, $quoteStatus,
                 $paidStatus, $resolutionNotes, $editId, $estateId]
            );
            
            flash_set('success', 'Ticket updated successfully.');
            
        } else {
            // Create new ticket
            if ($tenantId <= 0) {
                throw new RuntimeException('Tenant is required to create a ticket.');
            }
            
            $tenant = $db->fetchOne('SELECT id, unit_id FROM tenants WHERE id = ? AND estate_id = ?', [$tenantId, $estateId]);
            if (!$tenant) {
                throw new RuntimeException('Tenant not found.');
            }
            
            $ticketNumber = 'TCK-' . date('YmdHis') . '-' . random_int(100, 999);
            $newId = (int)$db->insert(
                "INSERT INTO maintenance_tickets
                 (ticket_number, tenant_id, unit_id, estate_id, category, priority, title, description, status, assigned_to, vendor_id, cost, quoted_cost, quote_status, paid_status, resolution_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', NULL, NULL, 0, 0, 'none', 'unpaid', NULL)",
                [$ticketNumber, $tenantId, (int)$tenant['unit_id'], $estateId, $category, $priority, $title, $description]
            );
            
            flash_set('success', 'Ticket created successfully.');
        }
        
    } catch (Throwable $e) {
        flash_set('error', 'Error saving ticket: ' . $e->getMessage());
    }
    
    redirect('maintenance_quotations.php?estate_id=' . $estateId);
}

// Handle ticket deletion
if ($method === 'POST' && $action === 'delete_ticket') {
    verify_csrf();
    $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
    
    if ($ticketId > 0) {
        try {
            $db->execute(
                "DELETE FROM maintenance_tickets WHERE id = ? AND estate_id = ?",
                [$ticketId, $estateId]
            );
            flash_set('success', 'Ticket deleted successfully.');
        } catch (Throwable $e) {
            flash_set('error', 'Error deleting ticket: ' . $e->getMessage());
        }
    }
    redirect('maintenance_quotations.php?estate_id=' . $estateId);
}

// Get ticket data for editing
$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne(
        "SELECT mt.*, un.unit_number, p.name AS property_name, u.first_name, u.last_name
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         INNER JOIN tenants t ON t.id = mt.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE mt.id = ? AND mt.estate_id = ?",
        [$editId, $estateId]
    );
    
    if (!$editing) {
        flash_set('error', 'Ticket not found.');
        redirect('maintenance_quotations.php?estate_id=' . $estateId);
    }
}

require __DIR__ . '/partials/top.php'; ?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Management</h1>
    <div class="text-gray-600">Manage tickets and quotations for maintenance work.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-sm btn-primary" href="maintenance_quotations.php?estate_id=<?= $estateId ?>&action=create">
      <i class="ki-duotone ki-plus fs-2"><span class="path1"></span><span class="path2"></span></i>
      New Ticket
    </a>
    <a class="btn btn-sm btn-light" href="vendors.php?estateId=<?= $estateId ?>">Vendors</a>
    <a class="btn btn-sm btn-light" href="maintenance_reports.php?estateId=<?= $estateId ?>">Reports</a>
  </div>
</div>

<?php if ($action === 'create' || $editing): ?>
<div class="card mb-6">
  <div class="card-header bg-primary text-white">
    <div class="card-title fw-bold">
      <i class="ki-duotone ki-ticket fs-2 me-2"><span class="path1"></span><span class="path2"></span></i>
      <?= $editing ? 'Edit Ticket' : 'Create New Ticket' ?>
    </div>
  </div>
  <div class="card-body">
    <form method="post" action="maintenance_quotations.php?estate_id=<?= $estateId ?><?= $editing ? '&edit_id=' . $editing['id'] : '' ?>">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="<?= $editing ? 'update_ticket' : 'create_ticket' ?>">
      
      <div class="row g-4 mb-4">
        <?php if (!$editing): ?>
        <div class="col-md-6">
          <label class="form-label required fw-bold">Tenant</label>
          <select class="form-select" name="tenant_id" required>
            <option value="">Select a tenant</option>
            <?php foreach ($tenants as $t): ?>
              <option value="<?= $t['id'] ?>" <?= (isset($_POST['tenant_id']) && $_POST['tenant_id'] == $t['id']) ? 'selected' : '' ?>>
                <?= e($t['first_name'] . ' ' . $t['last_name']) ?> - <?= e($t['property_name']) ?> <?= e($t['unit_number']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <div class="col-md-6">
          <label class="form-label fw-bold">Tenant</label>
          <div class="form-control-plaintext"><?= e($editing['first_name'] . ' ' . $editing['last_name']) ?> - <?= e($editing['property_name']) ?> <?= e($editing['unit_number']) ?></div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-3">
          <label class="form-label required fw-bold">Category</label>
          <select class="form-select" name="category" required>
            <option value="plumbing" <?= ($editing && $editing['category'] === 'plumbing') ? 'selected' : '' ?>>Plumbing</option>
            <option value="electrical" <?= ($editing && $editing['category'] === 'electrical') ? 'selected' : '' ?>>Electrical</option>
            <option value="painting" <?= ($editing && $editing['category'] === 'painting') ? 'selected' : '' ?>>Painting</option>
            <option value="cleaning" <?= ($editing && $editing['category'] === 'cleaning') ? 'selected' : '' ?>>Cleaning</option>
            <option value="other" <?= (!$editing || $editing['category'] === 'other') ? 'selected' : '' ?>>Other</option>
          </select>
        </div>
        
        <div class="col-md-3">
          <label class="form-label required fw-bold">Priority</label>
          <select class="form-select" name="priority" required>
            <option value="low" <?= ($editing && $editing['priority'] === 'low') ? 'selected' : '' ?>>Low</option>
            <option value="medium" <?= (!$editing || $editing['priority'] === 'medium') ? 'selected' : '' ?>>Medium</option>
            <option value="high" <?= ($editing && $editing['priority'] === 'high') ? 'selected' : '' ?>>High</option>
            <option value="urgent" <?= ($editing && $editing['priority'] === 'urgent') ? 'selected' : '' ?>>Urgent</option>
          </select>
        </div>
      </div>
      
      <div class="row g-4 mb-4">
        <div class="col-md-6">
          <label class="form-label required fw-bold">Title</label>
          <input type="text" class="form-control" name="title" value="<?= e($editing['title'] ?? ($_POST['title'] ?? '')) ?>" placeholder="Brief description of the issue" required>
        </div>
        
        <div class="col-md-6">
          <label class="form-label required fw-bold">Status</label>
          <select class="form-select" name="status" required>
            <option value="open" <?= (!$editing || $editing['status'] === 'open') ? 'selected' : '' ?>>Open</option>
            <option value="in_progress" <?= ($editing && $editing['status'] === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
            <option value="on_hold" <?= ($editing && $editing['status'] === 'on_hold') ? 'selected' : '' ?>>On Hold</option>
            <option value="resolved" <?= ($editing && $editing['status'] === 'resolved') ? 'selected' : '' ?>>Resolved</option>
            <option value="closed" <?= ($editing && $editing['status'] === 'closed') ? 'selected' : '' ?>>Closed</option>
          </select>
        </div>
      </div>
      
      <div class="mb-4">
        <label class="form-label required fw-bold">Description</label>
        <textarea class="form-control" name="description" rows="4" placeholder="Detailed description of the maintenance issue..." required><?= e($editing['description'] ?? ($_POST['description'] ?? '')) ?></textarea>
      </div>
      
      <div class="row g-4 mb-4">
        <div class="col-md-4">
          <label class="form-label fw-bold">Assigned To</label>
          <select class="form-select" name="assigned_to">
            <option value="0">Not assigned</option>
            <?php foreach ($staffUsers as $user): ?>
              <option value="<?= $user['id'] ?>" <?= ($editing && $editing['assigned_to'] == $user['id']) ? 'selected' : '' ?>>
                <?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<?= e($user['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="col-md-4">
          <label class="form-label fw-bold">Vendor</label>
          <select class="form-select" name="vendor_id">
            <option value="0">No vendor assigned</option>
            <?php foreach ($vendors as $v): ?>
              <option value="<?= $v['id'] ?>" <?= ($editing && $editing['vendor_id'] == $v['id']) ? 'selected' : '' ?>>
                <?= e($v['name']) ?> <?php if (!empty($v['specialization'])): ?> - <?= e($v['specialization']) ?><?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="col-md-4">
          <label class="form-label fw-bold">Quotation Status</label>
          <select class="form-select" name="quote_status">
            <option value="none" <?= (!$editing || $editing['quote_status'] === 'none') ? 'selected' : '' ?>>No Quotation</option>
            <option value="draft" <?= ($editing && $editing['quote_status'] === 'draft') ? 'selected' : '' ?>>Draft</option>
            <option value="submitted" <?= ($editing && $editing['quote_status'] === 'submitted') ? 'selected' : '' ?>>Submitted</option>
            <option value="approved" <?= ($editing && $editing['quote_status'] === 'approved') ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= ($editing && $editing['quote_status'] === 'rejected') ? 'selected' : '' ?>>Rejected</option>
          </select>
        </div>
      </div>
      
      <div class="row g-4 mb-4">
        <div class="col-md-3">
          <label class="form-label fw-bold">Estimated Cost (₦)</label>
          <input type="number" step="0.01" class="form-control" name="cost" value="<?= $editing['cost'] ?? 0 ?>">
        </div>
        
        <div class="col-md-3">
          <label class="form-label fw-bold">Quoted Cost (₦)</label>
          <input type="number" step="0.01" class="form-control" name="quoted_cost" value="<?= $editing['quoted_cost'] ?? 0 ?>">
        </div>
        
        <div class="col-md-3">
          <label class="form-label fw-bold">Payment Status</label>
          <select class="form-select" name="paid_status">
            <option value="unpaid" <?= (!$editing || $editing['paid_status'] === 'unpaid') ? 'selected' : '' ?>>Unpaid</option>
            <option value="paid" <?= ($editing && $editing['paid_status'] === 'paid') ? 'selected' : '' ?>>Paid</option>
          </select>
        </div>
        
        <div class="col-md-3">
          <label class="form-label fw-bold">Ticket Number</label>
          <input type="text" class="form-control-plaintext" value="<?= e($editing['ticket_number'] ?? 'Will be generated') ?>" readonly>
        </div>
      </div>
      
      <div class="mb-4">
        <label class="form-label fw-bold">Resolution Notes</label>
        <textarea class="form-control" name="resolution_notes" rows="3" placeholder="Notes about the resolution..."><?= e($editing['resolution_notes'] ?? '') ?></textarea>
      </div>
      
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
          <i class="ki-duotone ki-save fs-2 me-2"><span class="path1"></span><span class="path2"></span></i>
          <?= $editing ? 'Update Ticket' : 'Create Ticket' ?>
        </button>
        <a href="maintenance_quotations.php?estate_id=<?= $estateId ?>" class="btn btn-light">Cancel</a>
        <?php if ($editing): ?>
        <button type="button" class="btn btn-danger ms-auto" data-bs-toggle="modal" data-bs-target="#deleteModal">
          <i class="ki-duotone ki-trash fs-2 me-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
          Delete Ticket
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_quotations.php?estate_id=<?= $estateId ?>">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete_ticket">
        <input type="hidden" name="ticket_id" value="<?= $editing['id'] ?? 0 ?>">
        <div class="modal-header">
          <h5 class="modal-title">Delete Ticket</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to delete this ticket? This action cannot be undone.</p>
          <p class="fw-bold">Ticket: <?= e($editing['ticket_number'] ?? '') ?> - <?= e($editing['title'] ?? '') ?></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <div class="text-gray-600">Review, approve, or reject artisan quotations and manage invoices.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="maintenance_quotations.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Estate</label>
        <select class="form-select" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Statuses</option>
          <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
          <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
          <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Vendor</label>
        <select class="form-select" name="vendor_id" onchange="this.form.submit()">
          <option value="0" <?= $vendorFilter === 0 ? 'selected' : '' ?>>All Vendors</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $vendorFilter ? 'selected' : '' ?>>
              <?= e($v['name']) ?> (<?= e($v['specialization']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title fw-bold">Quotation Requests</div>
  </div>
  <div class="card-body">
    <?php if (!$tickets): ?>
      <div class="text-center py-10">
        <div class="symbol symbol-100px mx-auto mb-5">
          <i class="fas fa-file-invoice text-muted fs-1"></i>
        </div>
        <h4 class="text-gray-700">No quotations found</h4>
        <p class="text-gray-500">There are no maintenance quotations matching your current filters.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle gs-0 gy-4">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th>Ticket</th>
              <th>Vendor</th>
              <th>Unit</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center">
                    <div class="symbol symbol-40px symbol-circle me-3">
                      <i class="fas fa-ticket-alt text-primary"></i>
                    </div>
                    <div>
                      <div class="fw-bold text-gray-900"><?= e($t['ticket_number']) ?></div>
                      <div class="text-gray-600 fs-7"><?= e($t['title']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="text-gray-700"><?= e($t['vendor_name'] ?? 'Unassigned') ?></div>
                </td>
                <td>
                  <div class="text-gray-700"><?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?></div>
                </td>
                <td>
                  <div class="fw-bold text-gray-900">₦<?= number_format((float)$t['quoted_cost'], 2) ?></div>
                  <?php if (!empty($t['has_detailed_quotation'])): ?>
                    <div class="text-muted fs-8">Detailed</div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge badge-<?= 
                    $t['quote_status'] === 'approved' ? 'success' : 
                    ($t['quote_status'] === 'rejected' ? 'danger' : 
                    ($t['quote_status'] === 'submitted' ? 'warning' : 'info')) 
                  ?>">
                    <?= e($t['quote_status']) ?>
                  </span>
                  <?php if (!empty($t['approved_at'])): ?>
                    <div class="text-muted fs-8">Approved by <?= e($t['approved_by_first'] . ' ' . $t['approved_by_last']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="text-gray-600"><?= e($t['quoted_at'] ? date('M j, Y H:i', strtotime($t['quoted_at'])) : 'N/A') ?></div>
                </td>
                <td>
                  <div class="d-flex gap-2">
                    <a href="maintenance_quotation_detail.php?id=<?= (int)$t['id'] ?>&estate_id=<?= $estateId ?>" 
                       class="btn btn-sm btn-light-primary">
                      <i class="fas fa-eye me-1"></i>View
                    </a>
                    <?php if ($t['quote_status'] === 'submitted'): ?>
                      <button type="button" class="btn btn-sm btn-light-success" 
                              onclick="showApproveModal(<?= (int)$t['id'] ?>, '<?= e($t['ticket_number']) ?>', <?= (float)$t['quoted_cost'] ?>)">
                        <i class="fas fa-check me-1"></i>Approve
                      </button>
                      <button type="button" class="btn btn-sm btn-light-danger" 
                              onclick="showRejectModal(<?= (int)$t['id'] ?>, '<?= e($t['ticket_number']) ?>')">
                        <i class="fas fa-times me-1"></i>Reject
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
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