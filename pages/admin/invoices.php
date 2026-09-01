<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Invoices – EstatePro';
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

$tenantId = (int)(get_param('tenant_id', 0) ?? 0);
$statusFilter = (string)(get_param('status', '') ?? '');

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'create') {
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        assert_can_access_estate($estateIdPost);
        $tenantIdPost = (int)(post_param('tenant_id', 0) ?? 0);
        $leaseId = (int)(post_param('lease_id', 0) ?? 0);
        $type = (string)post_param('type', 'rent');
        $amount = (float)(post_param('amount', 0) ?? 0);
        $dueDate = (string)post_param('due_date', '');
        $description = trim((string)post_param('description', ''));

        if ($estateIdPost <= 0 || $tenantIdPost <= 0 || $leaseId <= 0) {
            flash_set('error', 'Estate, tenant and lease are required.');
            redirect('invoices.php?estate_id=' . $estateId);
        }
        if ($amount <= 0 || $dueDate === '') {
            flash_set('error', 'Amount and due date are required.');
            redirect('invoices.php?estate_id=' . $estateIdPost . '&tenant_id=' . $tenantIdPost);
        }

        try {
            $tenant = $db->fetchOne('SELECT id, unit_id FROM tenants WHERE id = ? AND estate_id = ?', [$tenantIdPost, $estateIdPost]);
            if (!$tenant) {
                throw new RuntimeException('Tenant not found in selected estate.');
            }

            $lease = $db->fetchOne('SELECT id FROM leases WHERE id = ? AND tenant_id = ?', [$leaseId, $tenantIdPost]);
            if (!$lease) {
                throw new RuntimeException('Lease not found for tenant.');
            }

            $invoiceNumber = 'INV-' . date('YmdHis') . '-' . random_int(100, 999);
            $newId = (int)$db->insert(
                "INSERT INTO invoices
                 (invoice_number, tenant_id, lease_id, unit_id, estate_id, type, amount, due_date, status, paid_amount, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NULLIF(?, ''))",
                [$invoiceNumber, $tenantIdPost, $leaseId, (int)$tenant['unit_id'], $estateIdPost, $type, $amount, $dueDate, $description]
            );
            flash_set('success', 'Invoice created.');
            audit_log(
                'create',
                'invoice',
                $newId,
                ['invoice_number' => $invoiceNumber, 'tenant_id' => $tenantIdPost, 'lease_id' => $leaseId, 'type' => $type, 'amount' => $amount, 'due_date' => $dueDate],
                $estateIdPost
            );
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        redirect('invoices.php?estate_id=' . $estateIdPost . '&tenant_id=' . $tenantIdPost);
    }

    if ($action === 'mark_cancelled') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, invoice_number, status FROM invoices WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            $db->execute(
                "UPDATE invoices SET status = 'cancelled' WHERE id = ? AND estate_id = ?",
                [$id, $estateId]
            );
            flash_set('success', 'Invoice cancelled.');
            if ($before) {
                audit_log('cancel', 'invoice', (int)$before['id'], ['invoice_number' => $before['invoice_number'] ?? null, 'from_status' => $before['status'] ?? null, 'to_status' => 'cancelled'], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not cancel invoice.');
        }
        redirect('invoices.php?estate_id=' . $estateId);
    }
}

$tenants = $db->fetchAll(
    "SELECT t.id, u.first_name, u.last_name, u.email
     FROM tenants t
     INNER JOIN users u ON u.id = t.user_id
     WHERE t.estate_id = ?
     ORDER BY u.first_name ASC, u.last_name ASC",
    [$estateId]
);

$leases = [];
if ($tenantId > 0) {
    $leases = $db->fetchAll(
        "SELECT id, lease_number, start_date, end_date, status, rent_amount, service_charge
         FROM leases
         WHERE tenant_id = ?
         ORDER BY created_at DESC",
        [$tenantId]
    );
}

$where = ['i.estate_id = ?'];
$params = [$estateId];
if ($tenantId > 0) {
    $where[] = 'i.tenant_id = ?';
    $params[] = $tenantId;
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending','paid','overdue','partial','cancelled'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}

$invoices = $db->fetchAll(
    "SELECT
        i.*,
        u.first_name, u.last_name, u.email,
        un.unit_number,
        p.name AS property_name
     FROM invoices i
     INNER JOIN tenants t ON t.id = i.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = i.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY i.created_at DESC
     LIMIT 300",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Invoices</h1>
    <div class="text-gray-600">Create rent/service-charge invoices and track payment status.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="invoices.php" class="row g-3 align-items-end">
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
      <div class="col-12 col-md-5">
        <label class="form-label">Tenant (optional)</label>
        <select class="form-select" name="tenant_id" onchange="this.form.submit()">
          <option value="0" <?= $tenantId <= 0 ? 'selected' : '' ?>>All tenants</option>
          <?php foreach ($tenants as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $tenantId ? 'selected' : '' ?>>
              <?= e($t['first_name'] . ' ' . $t['last_name']) ?> (<?= e($t['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <?php foreach (['pending','partial','overdue','paid','cancelled'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Create invoice</div>
      </div>
      <div class="card-body">
        <?php if (!$tenants): ?>
          <div class="text-gray-600">No tenants yet. Create tenants first.</div>
        <?php else: ?>
          <form method="post" action="invoices.php?estate_id=<?= $estateId ?><?= $tenantId ? '&tenant_id=' . $tenantId : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="estate_id" value="<?= $estateId ?>">

            <div class="mb-4">
              <label class="form-label required">Tenant</label>
              <select class="form-select" name="tenant_id" required onchange="window.location='invoices.php?estate_id=<?= $estateId ?>&tenant_id='+this.value;">
                <option value="">Select tenant</option>
                <?php foreach ($tenants as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $tenantId ? 'selected' : '' ?>>
                    <?= e($t['first_name'] . ' ' . $t['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Selecting a tenant loads their leases.</div>
            </div>

            <div class="mb-4">
              <label class="form-label required">Lease</label>
              <select class="form-select" name="lease_id" required <?= $tenantId ? '' : 'disabled' ?>>
                <option value=""><?= $tenantId ? 'Select lease' : 'Select tenant first' ?></option>
                <?php foreach ($leases as $l): ?>
                  <option value="<?= (int)$l['id'] ?>">
                    <?= e($l['lease_number']) ?> (<?= e($l['start_date']) ?> → <?= e($l['end_date']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                  <option value="rent">rent</option>
                  <option value="service_charge">service_charge</option>
                  <option value="deposit">deposit</option>
                  <option value="other">other</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label required">Due date</label>
                <input class="form-control" type="date" name="due_date" required>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label required">Amount</label>
              <input class="form-control" type="number" step="0.01" min="0" name="amount" required>
            </div>

            <div class="mb-6">
              <label class="form-label">Description</label>
              <textarea class="form-control" name="description" rows="3"></textarea>
            </div>

            <button class="btn btn-primary" type="submit" <?= $tenantId ? '' : 'disabled' ?>>Create invoice</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Invoice list</div>
      </div>
      <div class="card-body">
        <?php if (!$invoices): ?>
          <div class="text-gray-600">No invoices found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Invoice #</th>
                  <th>Tenant</th>
                  <th>Unit</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th class="text-end">Amount</th>
                  <th class="text-end">Paid</th>
                  <th class="text-end">Due</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($invoices as $i): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($i['invoice_number']) ?></td>
                  <td class="text-gray-700"><?= e($i['first_name'] . ' ' . $i['last_name']) ?></td>
                  <td class="text-gray-700"><?= e($i['property_name']) ?> — <?= e($i['unit_number']) ?></td>
                  <td><span class="badge badge-light"><?= e($i['type']) ?></span></td>
                  <td>
                    <span class="badge badge-light-<?= $i['status'] === 'paid' ? 'success' : ($i['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                      <?= e($i['status']) ?>
                    </span>
                  </td>
                  <td class="text-end"><?= number_format((float)$i['amount'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$i['paid_amount'], 2) ?></td>
                  <td class="text-end"><?= e($i['due_date']) ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="payments.php?estate_id=<?= $estateId ?>&invoice_id=<?= (int)$i['id'] ?>">Payments</a>
                      <?php if ($i['status'] !== 'cancelled'): ?>
                        <form method="post" action="invoices.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Cancel this invoice?');">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="mark_cancelled">
                          <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                          <button class="btn btn-sm btn-light-danger" type="submit">Cancel</button>
                        </form>
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
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

