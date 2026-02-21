<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'staff', 'security']);

$pageTitle = 'Maintenance Tickets – EstatePro';
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

$statusFilter = (string)(get_param('status', '') ?? '');
$editId = (int)(get_param('edit_id', 0) ?? 0);

$vendors = $db->fetchAll(
    "SELECT id, name, specialization
     FROM vendors
     WHERE estate_id = ? OR estate_id IS NULL
     ORDER BY name ASC",
    [$estateId]
);

$staffUsers = $db->fetchAll(
    "SELECT id, first_name, last_name, role
     FROM users
     WHERE role IN ('staff','security','estate_admin','property_manager')
     ORDER BY first_name ASC, last_name ASC",
    []
);

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

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
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
            redirect('maintenance.php?estate_id=' . $estateId . ($id ? ('&edit_id=' . $id) : ''));
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

            if ($id > 0) {
                $before = $db->fetchOne('SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?', [$id, $estateId]);
                $prevQuoteStatus = (string)($before['quote_status'] ?? 'none');
                $prevPaidStatus = (string)($before['paid_status'] ?? 'unpaid');
                $prevStatus = (string)($before['status'] ?? 'open');

                $approvedAt = null;
                $approvedBy = null;
                if ($quoteStatus === 'approved' && $prevQuoteStatus !== 'approved') {
                    $approvedAt = date('Y-m-d H:i:s');
                    $approvedBy = current_user_id();
                }

                $paidAt = null;
                if ($paidStatus === 'paid' && $prevPaidStatus !== 'paid') {
                    $paidAt = date('Y-m-d H:i:s');
                }

                // Ensure uploads directory exists
                $uploadDir = __DIR__ . '/../../uploads/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Handle file uploads
                $beforePhoto = null;
                $afterPhoto = null;

                if (isset($_FILES['before_photo']) && $_FILES['before_photo']['error'] == UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = mime_content_type($_FILES['before_photo']['tmp_name']);
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileName = 'ticket_' . $id . '_before_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                        if (move_uploaded_file($_FILES['before_photo']['tmp_name'], $uploadDir . $fileName)) {
                            $beforePhoto = $fileName;
                        }
                    }
                }

                if (isset($_FILES['after_photo']) && $_FILES['after_photo']['error'] == UPLOAD_ERR_OK) {
                    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    $fileType = mime_content_type($_FILES['after_photo']['tmp_name']);
                    
                    if (in_array($fileType, $allowedTypes)) {
                        $fileName = 'ticket_' . $id . '_after_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                        if (move_uploaded_file($_FILES['after_photo']['tmp_name'], $uploadDir . $fileName)) {
                            $afterPhoto = $fileName;
                        }
                    }
                }

                // Check if detailed quotation items were submitted
                $detailedQuotationSubmitted = false;
                $quotationItems = post_param('items', []);
                
                if (is_array($quotationItems) && !empty($quotationItems)) {
                    $detailedQuotationSubmitted = true;
                    
                    // Calculate total from detailed items
                    $detailedTotal = 0;
                    foreach ($quotationItems as $item) {
                        if (isset($item['quantity']) && isset($item['unit_price'])) {
                            $quantity = (int)$item['quantity'];
                            $unitPrice = (float)$item['unit_price'];
                            $detailedTotal += $quantity * $unitPrice;
                        }
                    }
                    
                    // Use detailed total if it's higher than simple quote
                    if ($detailedTotal > $quotedCost) {
                        $quotedCost = $detailedTotal;
                    }
                }

                $sql = "UPDATE maintenance_tickets
                         SET category = ?, priority = ?, title = ?, description = ?, status = ?,
                             assigned_to = NULLIF(?, 0), vendor_id = NULLIF(?, 0),
                             cost = ?, quoted_cost = ?, quote_status = ?,
                             quoted_at = CASE WHEN ? = 'submitted' AND (quoted_at IS NULL OR quote_status <> 'submitted') THEN NOW() ELSE quoted_at END,
                             approved_at = COALESCE(?, approved_at),
                             approved_by = COALESCE(?, approved_by),
                             paid_status = ?, paid_at = COALESCE(?, paid_at),
                             resolution_notes = NULLIF(?, '')";
                $params = [
                    $category,
                    $priority,
                    $title,
                    $description,
                    $status,
                    $assignedTo,
                    $vendorId,
                    $cost,
                    $quotedCost,
                    $quoteStatus,
                    $quoteStatus,
                    $approvedAt,
                    $approvedBy,
                    $paidStatus,
                    $paidAt,
                    $resolutionNotes
                ];

                // Add photo updates to the query if they exist
                if ($beforePhoto) {
                    $sql .= ", before_photo = ?";
                    $params[] = $beforePhoto;
                }
                if ($afterPhoto) {
                    $sql .= ", after_photo = ?";
                    $params[] = $afterPhoto;
                }

                // Handle detailed quotation updates
                if ($detailedQuotationSubmitted) {
                    $sql .= ", has_detailed_quotation = TRUE";
                }

                $sql .= " WHERE id = ? AND estate_id = ?";
                $params[] = $id;
                $params[] = $estateId;

                $db->execute($sql, $params);

                // Handle detailed quotation items if submitted
                if ($detailedQuotationSubmitted) {
                    // Delete existing items first
                    $db->execute("DELETE FROM maintenance_quotation_items WHERE ticket_id = ?", [$id]);
                    
                    // Insert new items
                    foreach ($quotationItems as $item) {
                        if (isset($item['description']) && isset($item['quantity']) && isset($item['unit_price'])) {
                            $description = trim($item['description']);
                            $quantity = (int)$item['quantity'];
                            $unitPrice = (float)$item['unit_price'];
                            $itemType = $item['item_type'] ?? 'material';
                            
                            if ($description && $quantity > 0 && $unitPrice >= 0) {
                                $totalPrice = $quantity * $unitPrice;
                                
                                $db->insert(
                                    "INSERT INTO maintenance_quotation_items (ticket_id, item_description, quantity, unit_price, total_price, item_type) 
                                     VALUES (?, ?, ?, ?, ?, ?)",
                                    [$id, $description, $quantity, $unitPrice, $totalPrice, $itemType]
                                );
                            }
                        }
                    }
                }

                flash_set('success', 'Ticket updated.');
                $after = $db->fetchOne('SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?', [$id, $estateId]);
                if ($before && $after) {
                    $diff = audit_diff($before, $after, ['category','priority','title','description','status','assigned_to','vendor_id','cost','quoted_cost','quote_status','paid_status','resolution_notes']);
                    audit_log('update', 'maintenance_ticket', (int)$id, ['diff' => $diff], $estateId);
                }

                // Timeline entry (best-effort).
                try {
                    $fromStatus = $prevStatus !== '' ? $prevStatus : null;
                    $toStatus = (string)($after['status'] ?? $status);
                    $noteParts = [];
                    if ($fromStatus !== $toStatus) {
                        $noteParts[] = "Status: {$fromStatus} → {$toStatus}";
                    }
                    if ((string)($before['quote_status'] ?? 'none') !== (string)($after['quote_status'] ?? $quoteStatus)) {
                        $noteParts[] = "Quote: " . (string)($before['quote_status'] ?? 'none') . " → " . (string)($after['quote_status'] ?? $quoteStatus);
                    }
                    if ((string)($before['paid_status'] ?? 'unpaid') !== (string)($after['paid_status'] ?? $paidStatus)) {
                        $noteParts[] = "Payment: " . (string)($before['paid_status'] ?? 'unpaid') . " → " . (string)($after['paid_status'] ?? $paidStatus);
                    }
                    if ((float)($before['quoted_cost'] ?? 0) !== (float)($after['quoted_cost'] ?? $quotedCost)) {
                        $noteParts[] = "Quoted: " . number_format((float)($before['quoted_cost'] ?? 0), 2) . " → " . number_format((float)($after['quoted_cost'] ?? $quotedCost), 2);
                    }
                    if ((float)($before['cost'] ?? 0) !== (float)($after['cost'] ?? $cost)) {
                        $noteParts[] = "Actual: " . number_format((float)($before['cost'] ?? 0), 2) . " → " . number_format((float)($after['cost'] ?? $cost), 2);
                    }
                    $note = $noteParts ? implode(' | ', $noteParts) : 'Ticket updated';
                    $db->insert(
                        "INSERT INTO maintenance_ticket_updates (ticket_id, updated_by, from_status, to_status, note, quoted_cost, actual_cost)
                         VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, 0))",
                        [
                            $id,
                            (int)(current_user_id() ?? 0),
                            $fromStatus,
                            $toStatus !== '' ? $toStatus : null,
                            $note,
                            (float)($after['quoted_cost'] ?? $quotedCost),
                            (float)($after['cost'] ?? $cost),
                        ]
                    );
                } catch (Throwable $e) {
                    // best-effort
                }
            } else {
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
                flash_set('success', 'Ticket created.');
                audit_log('create', 'maintenance_ticket', $newId, ['ticket_number' => $ticketNumber, 'tenant_id' => $tenantId, 'unit_id' => (int)$tenant['unit_id'], 'category' => $category, 'priority' => $priority], $estateId);

                // Create timeline entry (best-effort).
                try {
                    $db->insert(
                        "INSERT INTO maintenance_ticket_updates (ticket_id, updated_by, from_status, to_status, note)
                         VALUES (?, ?, NULL, 'open', 'Ticket created')",
                        [$newId, (int)(current_user_id() ?? 0)]
                    );
                } catch (Throwable $e) {
                    // best-effort
                }
            }
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        redirect('maintenance.php?estate_id=' . $estateId);
    }

    if ($action === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, ticket_number, title, status FROM maintenance_tickets WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            $db->execute('DELETE FROM maintenance_tickets WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            flash_set('success', 'Ticket deleted.');
            if ($before) {
                audit_log('delete', 'maintenance_ticket', (int)$before['id'], ['ticket_number' => $before['ticket_number'] ?? null, 'title' => $before['title'] ?? null, 'status' => $before['status'] ?? null], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete ticket.');
        }
        redirect('maintenance.php?estate_id=' . $estateId);
    }
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?', [$editId, $estateId]);
    if (!$editing) {
        flash_set('warning', 'Ticket not found.');
        redirect('maintenance.php?estate_id=' . $estateId);
    }
}

$where = ['mt.estate_id = ?'];
$params = [$estateId];
if ($statusFilter !== '' && in_array($statusFilter, ['open','assigned','in_progress','resolved','closed','cancelled'], true)) {
    $where[] = 'mt.status = ?';
    $params[] = $statusFilter;
}

$tickets = $db->fetchAll(
    "SELECT
        mt.*,
        e.name AS estate_name,
        u.first_name, u.last_name,
        un.unit_number,
        p.name AS property_name,
        v.name AS vendor_name
     FROM maintenance_tickets mt
     INNER JOIN estates e ON e.id = mt.estate_id
     INNER JOIN tenants t ON t.id = mt.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY mt.created_at DESC
     LIMIT 300",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Tickets</h1>
    <div class="text-gray-600">Track issues, assign staff/vendors, and close tickets.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="maintenance.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label">Estate</label>
        <select class="form-select" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <?php foreach (['open','assigned','in_progress','resolved','closed','cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
      <div class="col-12">
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-sm btn-light" href="vendors.php?estate_id=<?= $estateId ?>">Vendors</a>
          <a class="btn btn-sm btn-light" href="maintenance_reports.php?estate_id=<?= $estateId ?>">Reports</a>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12">
    <div class="card">
      <div class="card-header bg-primary text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-ticket-alt me-2"></i>
          <?= $editing ? 'Edit Ticket' : 'Create Ticket' ?>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$tenants && !$editing): ?>
          <div class="text-gray-600">No tenants found. Create tenants first.</div>
        <?php else: ?>
          <form method="post" action="maintenance.php?estate_id=<?= $estateId ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

            <div class="row g-4 mb-4">
              <?php if (!$editing): ?>
              <div class="col-md-6">
                <label class="form-label required fw-bold">Tenant</label>
                <select class="form-select form-select-lg" name="tenant_id" required>
                  <option value="">Select tenant</option>
                  <?php foreach ($tenants as $t): ?>
                    <option value="<?= (int)$t['id'] ?>">
                      <?= e($t['first_name'] . ' ' . $t['last_name']) ?> — <?= e($t['property_name']) ?> <?= e($t['unit_number']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Category</label>
                <?php $cat = (string)($editing['category'] ?? 'other'); ?>
                <select class="form-select" name="category">
                  <?php foreach (['electrical','plumbing','water','security','gate','environmental','safety','other'] as $c): ?>
                    <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Priority</label>
                <?php $pr = (string)($editing['priority'] ?? 'medium'); ?>
                <select class="form-select" name="priority">
                  <?php foreach (['low','medium','high','urgent'] as $p): ?>
                    <option value="<?= e($p) ?>" <?= $pr === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="row g-4 mb-4">
              <div class="col-md-6">
                <label class="form-label required fw-bold">Title</label>
                <input class="form-control form-control-lg" name="title" required value="<?= e($editing['title'] ?? '') ?>">
              </div>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Status</label>
                <?php $st = (string)($editing['status'] ?? 'open'); ?>
                <select class="form-select" name="status">
                  <?php foreach (['open','assigned','in_progress','resolved','closed','cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $st === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-md-3">
                <label class="form-label fw-bold">Actual cost</label>
                <div class="input-group">
                  <span class="input-group-text">₦</span>
                  <input class="form-control" type="number" step="0.01" min="0" name="cost" value="<?= e($editing['cost'] ?? 0) ?>">
                </div>
              </div>
            </div>

            <div class="row mb-4">
              <div class="col-12">
                <label class="form-label required fw-bold">Description</label>
                <textarea class="form-control" name="description" rows="3" required><?= e($editing['description'] ?? '') ?></textarea>
              </div>
            </div>

            <div class="row g-4 mb-4">
              <div class="col-md-4">
                <label class="form-label fw-bold">Assign to (staff/security)</label>
                <?php $as = (int)($editing['assigned_to'] ?? 0); ?>
                <select class="form-select" name="assigned_to">
                  <option value="0">Unassigned</option>
                  <?php foreach ($staffUsers as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $as ? 'selected' : '' ?>>
                      <?= e($s['first_name'] . ' ' . $s['last_name']) ?> (<?= e($s['role']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-md-4">
                <label class="form-label fw-bold">Vendor</label>
                <?php $vid = (int)($editing['vendor_id'] ?? 0); ?>
                <select class="form-select" name="vendor_id">
                  <option value="0">None</option>
                  <?php foreach ($vendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" <?= (int)$v['id'] === $vid ? 'selected' : '' ?>>
                      <?= e($v['name']) ?> (<?= e($v['specialization']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              
              <div class="col-md-4">
                <label class="form-label fw-bold">Simple Quote Amount</label>
                <div class="input-group">
                  <span class="input-group-text">₦</span>
                  <input class="form-control" type="number" step="0.01" min="0" name="quoted_cost" value="<?= e($editing['quoted_cost'] ?? 0) ?>">
                </div>
                <div class="text-muted fs-8 mt-1">Simple quote (use detailed below for full breakdown)</div>
              </div>
            </div>

            <div class="row g-4 mb-4">
              <div class="col-md-4">
                <label class="form-label fw-bold">Quote status</label>
                <?php $qs = (string)($editing['quote_status'] ?? 'none'); ?>
                <select class="form-select" name="quote_status">
                  <?php foreach (['none','draft','submitted','approved','rejected'] as $q): ?>
                    <option value="<?= e($q) ?>" <?= $qs === $q ? 'selected' : '' ?>><?= e($q) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($editing['approved_at']) || !empty($editing['approved_by'])): ?>
                  <div class="text-gray-600 fs-8 mt-1">
                    Approved: <?= e($editing['approved_at'] ?? '') ?>
                  </div>
                <?php endif; ?>
              </div>
              
              <div class="col-md-4">
                <label class="form-label fw-bold">Paid status</label>
                <?php $ps = (string)($editing['paid_status'] ?? 'unpaid'); ?>
                <select class="form-select" name="paid_status">
                  <option value="unpaid" <?= $ps === 'unpaid' ? 'selected' : '' ?>>unpaid</option>
                  <option value="paid" <?= $ps === 'paid' ? 'selected' : '' ?>>paid</option>
                </select>
                <?php if (!empty($editing['paid_at'])): ?>
                  <div class="text-gray-600 fs-8 mt-1">Paid at: <?= e($editing['paid_at']) ?></div>
                <?php endif; ?>
              </div>
              
              <div class="col-md-4">
                <label class="form-label fw-bold">Quote submitted at</label>
                <input class="form-control" value="<?= e($editing['quoted_at'] ?? '') ?>" disabled>
              </div>
            </div>

            <!-- Detailed Quotation Section - Full Width -->
            <div class="card mb-4 border-2 border-primary shadow-sm">
              <div class="card-header bg-primary text-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                  <div class="card-title fw-bold mb-0 d-flex align-items-center">
                    <i class="fas fa-receipt me-2"></i>
                    Detailed Quotation
                  </div>
                  <div class="d-flex align-items-center">
                    <span class="me-3">Total: ₦<span id="total-quotation-amount-display" class="fw-bold">0.00</span></span>
                    <button type="button" class="btn btn-light btn-sm" id="add-quotation-item">
                      <i class="fas fa-plus me-1"></i> Add Item
                    </button>
                  </div>
                </div>
              </div>
              <div class="card-body p-4">
                <div class="row mb-4">
                  <div class="col-12">
                    <label class="form-label fw-bold">Quotation Notes</label>
                    <textarea class="form-control" name="quotation_notes" rows="2" placeholder="Additional notes about the quotation..."><?= e($editing['quotation_notes'] ?? '') ?></textarea>
                  </div>
                </div>
                
                <h6 class="mb-3 text-primary"><i class="fas fa-list me-2"></i>Quotation Items</h6>
                <div id="quotation-items-container" class="mb-3">
                  <!-- Dynamic items will be added here -->
                  <?php
                  // Load existing quotation items if any
                  $existingItems = $db->fetchAll(
                    "SELECT * FROM maintenance_quotation_items WHERE ticket_id = ? ORDER BY created_at ASC",
                    [(int)$editing['id']]
                  );
                  
                  if (!empty($existingItems)):
                    foreach ($existingItems as $index => $item):
                  ?>
                    <div class="quotation-item mb-3 p-3 border rounded bg-light">
                      <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                          <label class="form-label text-muted small">Item Description</label>
                          <input type="text" class="form-control form-control-sm" name="items[<?= $index ?>][description]" 
                                 placeholder="Item description" value="<?= e($item['item_description']) ?>" required>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Quantity</label>
                          <input type="number" class="form-control form-control-sm item-quantity" name="items[<?= $index ?>][quantity]" 
                                 placeholder="Qty" value="<?= (int)$item['quantity'] ?>" min="1" required>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Unit Price</label>
                          <div class="input-group input-group-sm">
                            <span class="input-group-text">₦</span>
                            <input type="number" step="0.01" class="form-control form-control-sm item-unit-price" name="items[<?= $index ?>][unit_price]" 
                                   placeholder="Price" value="<?= e($item['unit_price']) ?>" min="0" required>
                          </div>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Total</label>
                          <div class="input-group input-group-sm">
                            <span class="input-group-text">₦</span>
                            <input type="number" step="0.01" class="form-control form-control-sm item-total-price" name="items[<?= $index ?>][total_price]" 
                                   placeholder="Total" value="<?= e($item['total_price']) ?>" readonly>
                          </div>
                        </div>
                        <div class="col-md-1">
                          <label class="form-label text-muted small">Type</label>
                          <select class="form-select form-select-sm item-type" name="items[<?= $index ?>][item_type]">
                            <option value="material" <?= $item['item_type'] === 'material' ? 'selected' : '' ?>>Mat</option>
                            <option value="labor" <?= $item['item_type'] === 'labor' ? 'selected' : '' ?>>Lab</option>
                            <option value="equipment" <?= $item['item_type'] === 'equipment' ? 'selected' : '' ?>>Eqp</option>
                            <option value="other" <?= $item['item_type'] === 'other' ? 'selected' : '' ?>>Oth</option>
                          </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                          <button type="button" class="btn btn-danger btn-sm w-100 remove-item">
                            <i class="fas fa-trash"></i>
                          </button>
                        </div>
                      </div>
                    </div>
                  <?php 
                    endforeach;
                  else:
                    // Default empty row
                  ?>
                    <div class="quotation-item mb-3 p-3 border rounded bg-light">
                      <div class="row g-2 align-items-center">
                        <div class="col-md-4">
                          <label class="form-label text-muted small">Item Description</label>
                          <input type="text" class="form-control form-control-sm item-description" name="items[0][description]" 
                                 placeholder="Item description" required>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Quantity</label>
                          <input type="number" class="form-control form-control-sm item-quantity" name="items[0][quantity]" 
                                 placeholder="Qty" min="1" value="1" required>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Unit Price</label>
                          <div class="input-group input-group-sm">
                            <span class="input-group-text">₦</span>
                            <input type="number" step="0.01" class="form-control form-control-sm item-unit-price" name="items[0][unit_price]" 
                                   placeholder="Price" min="0" value="0.00" required>
                          </div>
                        </div>
                        <div class="col-md-2">
                          <label class="form-label text-muted small">Total</label>
                          <div class="input-group input-group-sm">
                            <span class="input-group-text">₦</span>
                            <input type="number" step="0.01" class="form-control form-control-sm item-total-price" name="items[0][total_price]" 
                                   placeholder="Total" readonly value="0.00">
                          </div>
                        </div>
                        <div class="col-md-1">
                          <label class="form-label text-muted small">Type</label>
                          <select class="form-select form-select-sm item-type" name="items[0][item_type]">
                            <option value="material">Mat</option>
                            <option value="labor">Lab</option>
                            <option value="equipment">Eqp</option>
                            <option value="other">Oth</option>
                          </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                          <button type="button" class="btn btn-secondary btn-sm w-100 remove-item" disabled>
                            <i class="fas fa-trash"></i>
                          </button>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                
                <div class="row mt-4">
                  <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                      <h5 class="text-primary mb-0">Total Quotation Amount:</h5>
                      <h3 class="text-success mb-0">₦<span id="total-quotation-amount">0.00</span></h3>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Photos Section -->
            <div class="row g-4 mb-4">
              <div class="col-md-6">
                <div class="card h-100 border-dashed">
                  <div class="card-body text-center p-4">
                    <div class="mb-3">
                      <i class="fas fa-camera text-muted" style="font-size: 2rem;"></i>
                    </div>
                    <h6 class="card-title text-muted">Before Photo</h6>
                    <input class="form-control" type="file" name="before_photo" accept="image/*">
                    <?php if (!empty($editing['before_photo'])): ?>
                      <div class="mt-3">
                        <img src="../../uploads/<?= e($editing['before_photo']) ?>" alt="Before Photo" class="img-thumbnail" style="max-width: 200px;">
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card h-100 border-dashed">
                  <div class="card-body text-center p-4">
                    <div class="mb-3">
                      <i class="fas fa-check-circle text-muted" style="font-size: 2rem;"></i>
                    </div>
                    <h6 class="card-title text-muted">After Photo</h6>
                    <input class="form-control" type="file" name="after_photo" accept="image/*">
                    <?php if (!empty($editing['after_photo'])): ?>
                      <div class="mt-3">
                        <img src="../../uploads/<?= e($editing['after_photo']) ?>" alt="After Photo" class="img-thumbnail" style="max-width: 200px;">
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="row g-4 mb-6">
              <div class="col-md-8">
                <label class="form-label fw-bold">Resolution notes</label>
                <textarea class="form-control" name="resolution_notes" rows="3"><?= e($editing['resolution_notes'] ?? '') ?></textarea>
              </div>
              <div class="col-md-4">
                <div class="d-flex flex-column h-100">
                  <div class="mt-auto">
                    <button class="btn btn-primary btn-lg w-100 py-3" type="submit">
                      <i class="fas fa-save me-2"></i><?= $editing ? 'Save Changes' : 'Create Ticket' ?>
                    </button>
                    <?php if ($editing): ?>
                      <a class="btn btn-outline-secondary w-100 mt-2" href="maintenance.php?estate_id=<?= $estateId ?>">
                        <i class="fas fa-arrow-left me-2"></i>Cancel
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($editing && !empty($editing['has_detailed_quotation'])): ?>
<div class="row g-6">
  <div class="col-12">
    <div class="card">
      <div class="card-header bg-info text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-file-invoice-dollar me-2"></i>
          Existing Detailed Quotation
        </div>
      </div>
      <div class="card-body">
        <?php if (!empty($editing['quotation_notes'])): ?>
          <div class="mb-3 p-3 bg-light rounded">
            <strong class="text-primary">Notes:</strong>
            <div class="text-gray-700"><?= nl2br(e($editing['quotation_notes'])) ?></div>
          </div>
        <?php endif; ?>
        
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
              $detailedItems = $db->fetchAll(
                "SELECT * FROM maintenance_quotation_items WHERE ticket_id = ? ORDER BY created_at ASC",
                [(int)$editing['id']]
              );
              $detailedTotal = 0;
              foreach ($detailedItems as $item):
                $detailedTotal += $item['total_price'];
              ?>
              <tr>
                <td><?= e($item['item_description']) ?></td>
                <td class="text-center"><span class="badge badge-<?= $item['item_type'] === 'material' ? 'primary' : ($item['item_type'] === 'labor' ? 'success' : ($item['item_type'] === 'equipment' ? 'warning' : 'secondary')) ?>"><?= e($item['item_type']) ?></span></td>
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
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-6 mt-6">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Ticket list</div>
      </div>
      <div class="card-body">
        <?php if (!$tickets): ?>
          <div class="text-gray-600">No tickets found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Ticket</th>
                  <th>Tenant</th>
                  <th>Unit</th>
                  <th>Status</th>
                  <th>Photos</th>
                  <th>Vendor</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($tickets as $t): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($t['ticket_number']) ?> — <?= e($t['title']) ?></td>
                  <td class="text-gray-700"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></td>
                  <td class="text-gray-700"><?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?></td>
                  <td><span class="badge badge-light"><?= e($t['status']) ?></span></td>
                  <td class="text-gray-700">
                    <?php if (!empty($t['before_photo']) || !empty($t['after_photo'])): ?>
                      <span class="badge badge-light-primary">Yes</span>
                    <?php else: ?>
                      <span class="badge badge-light">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-gray-700"><?= e($t['vendor_name'] ?? '') ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary" href="maintenance.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$t['id'] ?>">Edit</a>
                      <form method="post" action="maintenance.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Delete this ticket?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-light-danger" type="submit">Delete</button>
                      </form>
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
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  let itemCount = <?php echo count($existingItems ?? []); ?>;
  
  // Calculate total price when quantity or unit price changes
  document.addEventListener('input', function(e) {
    if (e.target.classList.contains('item-quantity') || e.target.classList.contains('item-unit-price')) {
      const row = e.target.closest('.quotation-item');
      const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
      const unitPrice = parseFloat(row.querySelector('.item-unit-price').value) || 0;
      const totalPrice = quantity * unitPrice;
      row.querySelector('.item-total-price').value = totalPrice.toFixed(2);
      calculateTotalAmount();
    }
  });
  
  // Add new quotation item
  document.getElementById('add-quotation-item').addEventListener('click', function() {
    itemCount++;
    const container = document.getElementById('quotation-items-container');
    const newItem = document.createElement('div');
    newItem.className = 'quotation-item mb-3 p-3 border rounded bg-light';
    newItem.innerHTML = `<div class="row g-2 align-items-center">
  <div class="col-md-4">
    <label class="form-label text-muted small">Item Description</label>
    <input type="text" class="form-control form-control-sm item-description" name="items[` + itemCount + `][description]" 
           placeholder="Item description" required>
  </div>
  <div class="col-md-2">
    <label class="form-label text-muted small">Quantity</label>
    <input type="number" class="form-control form-control-sm item-quantity" name="items[` + itemCount + `][quantity]" 
           placeholder="Qty" min="1" value="1" required>
  </div>
  <div class="col-md-2">
    <label class="form-label text-muted small">Unit Price</label>
    <div class="input-group input-group-sm">
      <span class="input-group-text">₦</span>
      <input type="number" step="0.01" class="form-control form-control-sm item-unit-price" name="items[` + itemCount + `][unit_price]" 
             placeholder="Price" min="0" value="0" required>
    </div>
  </div>
  <div class="col-md-2">
    <label class="form-label text-muted small">Total</label>
    <div class="input-group input-group-sm">
      <span class="input-group-text">₦</span>
      <input type="number" step="0.01" class="form-control form-control-sm item-total-price" name="items[` + itemCount + `][total_price]" 
             placeholder="Total" readonly value="0">
    </div>
  </div>
  <div class="col-md-1">
    <label class="form-label text-muted small">Type</label>
    <select class="form-select form-select-sm item-type" name="items[` + itemCount + `][item_type]">
      <option value="material">Mat</option>
      <option value="labor">Lab</option>
      <option value="equipment">Eqp</option>
      <option value="other">Oth</option>
    </select>
  </div>
  <div class="col-md-1 d-flex align-items-end">
    <button type="button" class="btn btn-danger btn-sm w-100 remove-item">
      <i class="fas fa-trash"></i>
    </button>
  </div>
</div>`;
    container.appendChild(newItem);
    
    // Add event listener to remove button
    newItem.querySelector('.remove-item').addEventListener('click', function() {
      newItem.remove();
      calculateTotalAmount();
    });
  });
  
  // Remove item
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-item')) {
      e.target.closest('.quotation-item').remove();
      calculateTotalAmount();
    }
  });
  
  // Calculate total amount
  function calculateTotalAmount() {
    let total = 0;
    document.querySelectorAll('.item-total-price').forEach(input => {
      total += parseFloat(input.value) || 0;
    });
    document.getElementById('total-quotation-amount').textContent = total.toFixed(2);
    document.getElementById('total-quotation-amount-display').textContent = total.toFixed(2);
  }
  
  calculateTotalAmount();
});
</script>