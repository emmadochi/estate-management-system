<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$vendor = require_artisan();
$db = db();
$method = request_method();

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/../../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$vendorId = (int)($vendor['id'] ?? 0);
$ticketId = (int)(get_param('id', 0) ?? 0);
if ($ticketId <= 0) {
    flash_set('error', 'Ticket not found.');
    redirect('tickets.php');
}

$ticket = $db->fetchOne(
    "SELECT
        mt.*,
        un.unit_number,
        p.name AS property_name,
        e.name AS estate_name
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     INNER JOIN estates e ON e.id = mt.estate_id
     WHERE mt.id = ? AND mt.vendor_id = ?
     LIMIT 1",
    [$ticketId, $vendorId]
);

if (!$ticket) {
    flash_set('error', 'You do not have access to this ticket.');
    redirect('tickets.php');
}

$allowedStatus = ['open','assigned','in_progress','resolved','closed','cancelled'];

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'update') {
        $newStatus = (string)post_param('status', (string)($ticket['status'] ?? 'assigned'));
        $note = trim((string)post_param('note', ''));
        $quotedCost = (float)(post_param('quoted_cost', (float)($ticket['quoted_cost'] ?? 0)) ?? 0);
        $actualCost = (float)(post_param('cost', (float)($ticket['cost'] ?? 0)) ?? 0);
        $quotationNotes = trim((string)post_param('quotation_notes', ''));
        
        if (!in_array($newStatus, $allowedStatus, true)) {
            $newStatus = (string)($ticket['status'] ?? 'assigned');
        }

        $fromStatus = (string)($ticket['status'] ?? '');
        $prevQuoted = (float)($ticket['quoted_cost'] ?? 0);
        $prevActual = (float)($ticket['cost'] ?? 0);
        $prevQuoteStatus = (string)($ticket['quote_status'] ?? 'none');

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

        // Determine quote status based on whether detailed quotation was submitted
        $nextQuoteStatus = $prevQuoteStatus;
        $setQuotedAt = false;
        if ($detailedQuotationSubmitted && $prevQuoteStatus !== 'approved') {
            $nextQuoteStatus = 'submitted';
            $setQuotedAt = true;
        } elseif ($quotedCost !== $prevQuoted && $prevQuoteStatus !== 'approved' && !$detailedQuotationSubmitted) {
            $nextQuoteStatus = 'submitted';
            $setQuotedAt = true;
        }

        // Handle file uploads
        $beforePhoto = null;
        $afterPhoto = null;

        if (isset($_FILES['before_photo']) && $_FILES['before_photo']['error'] == UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['before_photo']['tmp_name']);
            
            if (in_array($fileType, $allowedTypes)) {
                $fileName = 'ticket_' . $ticketId . '_before_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                if (move_uploaded_file($_FILES['before_photo']['tmp_name'], $uploadDir . $fileName)) {
                    $beforePhoto = $fileName;
                }
            }
        }

        if (isset($_FILES['after_photo']) && $_FILES['after_photo']['error'] == UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['after_photo']['tmp_name']);
            
            if (in_array($fileType, $allowedTypes)) {
                $fileName = 'ticket_' . $ticketId . '_after_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                if (move_uploaded_file($_FILES['after_photo']['tmp_name'], $uploadDir . $fileName)) {
                    $afterPhoto = $fileName;
                }
            }
        }

        try {
            $sql = "UPDATE maintenance_tickets SET status = ?, cost = ?, quoted_cost = ?, quote_status = ?, ";
            $params = [$newStatus, $actualCost, $quotedCost, $nextQuoteStatus];
            
            if ($setQuotedAt) {
                $sql .= "quoted_at = NOW(), ";
            }
            
            if ($quotationNotes !== '') {
                $sql .= "quotation_notes = ?, ";
                $params[] = $quotationNotes;
            }
            
            if ($detailedQuotationSubmitted) {
                $sql .= "has_detailed_quotation = TRUE, ";
                if ($setQuotedAt) {
                    $sql .= "quotation_submitted_at = NOW(), ";
                }
            }
            
            // Update photo fields if new photos were uploaded
            if ($beforePhoto !== null) {
                $sql .= "before_photo = ?, ";
                $params[] = $beforePhoto;
            }
            
            if ($afterPhoto !== null) {
                $sql .= "after_photo = ?, ";
                $params[] = $afterPhoto;
            }
            
            $sql .= "updated_at = NOW() WHERE id = ? AND vendor_id = ?";
            $params[] = $ticketId;
            $params[] = $vendorId;

            $db->execute($sql, $params);

            // Handle detailed quotation items if submitted
            if ($detailedQuotationSubmitted) {
                // Delete existing items first
                $db->execute("DELETE FROM maintenance_quotation_items WHERE ticket_id = ?", [$ticketId]);
                
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
                                [$ticketId, $description, $quantity, $unitPrice, $totalPrice, $itemType]
                            );
                        }
                    }
                }
            }

            try {
                $db->insert(
                    "INSERT INTO maintenance_ticket_updates (ticket_id, updated_by, from_status, to_status, note, quoted_cost, actual_cost)
                     VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, 0), NULLIF(?, 0))",
                    [
                        $ticketId,
                        (int)(current_user_id() ?? 0),
                        $fromStatus !== '' ? $fromStatus : null,
                        $newStatus,
                        $note !== '' ? $note : 'Updated by artisan',
                        $quotedCost,
                        $actualCost,
                    ]
                );
            } catch (Throwable $e) {
                // best-effort
            }

            flash_set('success', 'Ticket updated.');
        } catch (Throwable $e) {
            flash_set('error', 'Update failed.');
        }

        redirect('ticket_view.php?id=' . $ticketId);
    }
}

$updates = [];
try {
    $updates = $db->fetchAll(
        "SELECT u.*, usr.first_name, usr.last_name, usr.role
         FROM maintenance_ticket_updates u
         INNER JOIN users usr ON usr.id = u.updated_by
         WHERE u.ticket_id = ?
         ORDER BY u.created_at DESC
         LIMIT 200",
        [$ticketId]
    );
} catch (Throwable $e) {
    $updates = [];
}

$pageTitle = 'Ticket – Artisan Area';
$pageHeading = 'Ticket ' . (string)($ticket['ticket_number'] ?? '');

require __DIR__ . '/partials/top.php';
?>

<div class="row g-6">
  <div class="col-12">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Ticket Details</div>
      </div>
      <div class="card-body">
        <div class="row g-4">
          <div class="col-md-6 col-lg-4">
            <div class="mb-2 text-gray-600">Estate</div>
            <div class="fw-bold fs-5"><?= e($ticket['estate_name'] ?? '') ?></div>
          </div>
          <div class="col-md-6 col-lg-4">
            <div class="mb-2 text-gray-600">Unit</div>
            <div class="fw-bold fs-5"><?= e($ticket['property_name'] ?? '') ?> — <?= e($ticket['unit_number'] ?? '') ?></div>
          </div>
          <div class="col-md-6 col-lg-4">
            <div class="mb-2 text-gray-600">Priority</div>
            <div class="fw-bold fs-5"><span class="badge badge-<?= $ticket['priority'] === 'urgent' ? 'danger' : ($ticket['priority'] === 'high' ? 'warning' : 'info') ?>"><?= e($ticket['priority'] ?? '') ?></span></div>
          </div>
        </div>
        <div class="row g-4 mt-2">
          <div class="col-md-6">
            <div class="mb-2 text-gray-600">Title</div>
            <div class="fw-bold fs-5"><?= e($ticket['title'] ?? '') ?></div>
          </div>
          <div class="col-md-6">
            <div class="mb-2 text-gray-600">Status</div>
            <div class="fw-bold fs-5"><span class="badge badge-<?= $ticket['status'] === 'open' ? 'primary' : ($ticket['status'] === 'in_progress' ? 'warning' : ($ticket['status'] === 'resolved' ? 'success' : 'secondary')) ?>"><?= e($ticket['status'] ?? '') ?></span></div>
          </div>
        </div>
        <div class="row g-4 mt-3">
          <div class="col-md-6">
            <div class="mb-2 text-gray-600">Description</div>
            <div class="text-gray-800"><?= nl2br(e($ticket['description'] ?? '')) ?></div>
          </div>
          <div class="col-md-6">
            <div class="row">
              <div class="col-6">
                <div class="text-gray-600">Quoted</div>
                <div class="fw-bold fs-5">₦<?= number_format((float)($ticket['quoted_cost'] ?? 0), 2) ?></div>
                <div class="text-gray-600 fs-8"><?= e($ticket['quote_status'] ?? 'none') ?></div>
              </div>
              <div class="col-6">
                <div class="text-gray-600">Actual</div>
                <div class="fw-bold fs-5">₦<?= number_format((float)($ticket['cost'] ?? 0), 2) ?></div>
                <div class="text-gray-600 fs-8"><?= e($ticket['paid_status'] ?? 'unpaid') ?></div>
              </div>
            </div>
          </div>
        </div>

        <?php if (!empty($ticket['before_photo']) || !empty($ticket['after_photo'])): ?>
        <div class="separator my-4"></div>
        <div class="row g-4">
          <div class="col-md-6">
            <div class="text-gray-600 mb-2">Photos</div>
            <div class="d-flex gap-3">
              <?php if (!empty($ticket['before_photo'])): ?>
              <div>
                <div class="text-gray-600 fs-8 mb-1">Before</div>
                <img src="../../uploads/<?= e($ticket['before_photo']) ?>" alt="Before Photo" class="img-thumbnail" style="max-width: 150px; max-height: 120px;">
              </div>
              <?php endif; ?>
              <?php if (!empty($ticket['after_photo'])): ?>
              <div>
                <div class="text-gray-600 fs-8 mb-1">After</div>
                <img src="../../uploads/<?= e($ticket['after_photo']) ?>" alt="After Photo" class="img-thumbnail" style="max-width: 150px; max-height: 120px;">
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($ticket['has_detailed_quotation'])): ?>
        <div class="separator my-4"></div>
        <div class="card mb-4">
            <div class="card-header bg-light">
                <div class="card-title fw-bold d-flex align-items-center">
                  <i class="fas fa-file-invoice-dollar me-2"></i>
                  Detailed Quotation
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($ticket['quotation_notes'])): ?>
                    <div class="mb-3 p-3 bg-light rounded">
                        <strong class="text-primary">Notes:</strong>
                        <div class="text-gray-700"><?= nl2br(e($ticket['quotation_notes'])) ?></div>
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
                                [$ticketId]
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
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Update ticket section with expanded width -->
  <div class="col-12">
    <div class="card">
      <div class="card-header bg-primary text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-edit me-2"></i>
          Update Ticket
        </div>
      </div>
      <div class="card-body">
        <form method="post" action="ticket_view.php?id=<?= (int)$ticketId ?>" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">

          <div class="row g-4 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-bold">Status</label>
              <select class="form-select form-select-lg" name="status">
                <?php foreach ($allowedStatus as $s): ?>
                  <option value="<?= e($s) ?>" <?= (string)($ticket['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(e($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Simple Quote Amount</label>
              <div class="input-group">
                <span class="input-group-text">₦</span>
                <input class="form-control form-control-lg" type="number" step="0.01" min="0" name="quoted_cost" value="<?= e($ticket['quoted_cost'] ?? 0) ?>">
              </div>
              <div class="text-muted fs-8 mt-1">Simple quote (use detailed below for full breakdown)</div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Actual Cost</label>
              <div class="input-group">
                <span class="input-group-text">₦</span>
                <input class="form-control form-control-lg" type="number" step="0.01" min="0" name="cost" value="<?= e($ticket['cost'] ?? 0) ?>">
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Work Note</label>
              <textarea class="form-control" name="note" rows="1" placeholder="Quick note..."><?= e($ticket['note'] ?? '') ?></textarea>
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
                  <textarea class="form-control" name="quotation_notes" rows="2" placeholder="Additional notes about the quotation..."><?= e($ticket['quotation_notes'] ?? '') ?></textarea>
                </div>
              </div>
              
              <h6 class="mb-3 text-primary"><i class="fas fa-list me-2"></i>Quotation Items</h6>
              <div id="quotation-items-container" class="mb-3">
                <!-- Dynamic items will be added here -->
                <?php
                // Load existing quotation items if any
                $existingItems = $db->fetchAll(
                  "SELECT * FROM maintenance_quotation_items WHERE ticket_id = ? ORDER BY created_at ASC",
                  [(int)$ticketId]
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
                  <?php if (!empty($ticket['before_photo'])): ?>
                    <div class="mt-3">
                      <img src="../../uploads/<?= e($ticket['before_photo']) ?>" alt="Before Photo" class="img-thumbnail" style="max-width: 200px;">
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
                  <?php if (!empty($ticket['after_photo'])): ?>
                    <div class="mt-3">
                      <img src="../../uploads/<?= e($ticket['after_photo']) ?>" alt="After Photo" class="img-thumbnail" style="max-width: 200px;">
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-4">
            <div class="col-md-8">
              <label class="form-label fw-bold">Work Description</label>
              <textarea class="form-control" name="note" rows="4" placeholder="Describe what was done, parts used, any follow-up required..."><?= e($ticket['note'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <div class="d-flex flex-column h-100">
                <div class="mt-auto">
                  <?php if (in_array($ticket['status'], ['in_progress', 'accepted'])): ?>
                    <a class="btn btn-success btn-lg w-100 py-3 mb-2" href="work_completion.php?ticket_id=<?= (int)$ticket['id'] ?>">
                      <i class="fas fa-check-circle me-2"></i>Mark Job as Completed
                    </a>
                  <?php elseif ($ticket['status'] === 'work_completed'): ?>
                    <div class="alert alert-success text-center mb-3">
                      <i class="fas fa-check-circle me-2"></i>
                      <strong>Job Marked as Completed</strong>
                      <div class="small">Awaiting tenant confirmation</div>
                    </div>
                  <?php endif; ?>
                  <button class="btn btn-primary btn-lg w-100 py-3" type="submit">
                    <i class="fas fa-save me-2"></i>Save Update
                  </button>
                  <a class="btn btn-outline-secondary w-100 mt-2" href="tickets.php">
                    <i class="fas fa-arrow-left me-2"></i>Back to Tickets
                  </a>
                </div>
              </div>
            </div>
          </div>
        </form>
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