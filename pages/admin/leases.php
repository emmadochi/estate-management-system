<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Leases – EstatePro';

$db = db();
$method = request_method();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$tenantIdFilter = (int)(get_param('tenant_id', 0) ?? 0);

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

$tenants = $db->fetchAll(
    "SELECT t.id, u.first_name, u.last_name, u.email
     FROM tenants t
     INNER JOIN users u ON u.id = t.user_id
     WHERE t.estate_id = ?
     ORDER BY u.first_name ASC, u.last_name ASC",
    [$estateId]
);

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne(
                "SELECT l.id, l.tenant_id, l.lease_number, l.status
                 FROM leases l
                 INNER JOIN tenants t ON t.id = l.tenant_id
                 WHERE l.id = ? AND t.estate_id = ?",
                [$deleteId, $estateId]
            );
            $db->execute(
                "DELETE l FROM leases l
                 INNER JOIN tenants t ON t.id = l.tenant_id
                 WHERE l.id = ? AND t.estate_id = ?",
                [$deleteId, $estateId]
            );
            flash_set('success', 'Lease deleted.');
            if ($before) {
                audit_log('delete', 'lease', (int)$before['id'], ['lease_number' => $before['lease_number'] ?? null, 'status' => $before['status'] ?? null, 'tenant_id' => (int)$before['tenant_id']], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete lease.');
        }

        $qs = 'estate_id=' . $estateId . ($tenantIdFilter ? ('&tenant_id=' . $tenantIdFilter) : '');
        redirect('leases.php?' . $qs);
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
    assert_can_access_estate($estateIdPost);
    $tenantId = (int)(post_param('tenant_id', 0) ?? 0);
    $startDate = (string)post_param('start_date', '');
    $endDate = (string)post_param('end_date', '');
    $rentAmount = (float)(post_param('rent_amount', 0) ?? 0);
    $serviceCharge = (float)(post_param('service_charge', 0) ?? 0);
    $deposit = (float)(post_param('deposit', 0) ?? 0);
    $paymentFrequency = (string)post_param('payment_frequency', 'yearly');
    $status = (string)post_param('status', 'active');
    $notes = trim((string)post_param('notes', ''));

    if ($estateIdPost <= 0) {
        flash_set('error', 'Please select an estate.');
        redirect('leases.php');
    }
    if ($tenantId <= 0) {
        flash_set('error', 'Please select a tenant.');
        redirect('leases.php?estate_id=' . $estateIdPost);
    }
    if ($startDate === '' || $endDate === '') {
        flash_set('error', 'Start and end dates are required.');
        redirect('leases.php?estate_id=' . $estateIdPost . '&tenant_id=' . $tenantId . ($id ? ('&edit_id=' . $id) : ''));
    }

    try {
        $db->beginTransaction();

        $tenant = $db->fetchOne(
            "SELECT t.id, t.unit_id, t.status AS tenant_status, un.rent_amount AS unit_rent, un.service_charge AS unit_service
             FROM tenants t
             INNER JOIN units un ON un.id = t.unit_id
             WHERE t.id = ? AND t.estate_id = ?",
            [$tenantId, $estateIdPost]
        );
        if (!$tenant) {
            throw new RuntimeException('Tenant not found in selected estate.');
        }

        $unitId = (int)$tenant['unit_id'];
        if ($rentAmount <= 0) {
            $rentAmount = (float)$tenant['unit_rent'];
        }
        if ($serviceCharge < 0) {
            $serviceCharge = 0;
        }

        if ($id > 0) {
            $before = $db->fetchOne('SELECT * FROM leases WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            $db->execute(
                "UPDATE leases
                 SET start_date = ?, end_date = ?, rent_amount = ?, service_charge = ?, deposit = ?,
                     payment_frequency = ?, status = ?, notes = NULLIF(?, '')
                 WHERE id = ? AND tenant_id = ?",
                [$startDate, $endDate, $rentAmount, $serviceCharge, $deposit, $paymentFrequency, $status, $notes, $id, $tenantId]
            );
            flash_set('success', 'Lease updated.');
            $after = $db->fetchOne('SELECT * FROM leases WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
            if ($before && $after) {
                $diff = audit_diff($before, $after, ['start_date','end_date','rent_amount','service_charge','deposit','payment_frequency','status','notes']);
                audit_log('update', 'lease', (int)$id, ['diff' => $diff, 'tenant_id' => $tenantId], $estateIdPost);
            }
        } else {
            $leaseNumber = 'LEASE-' . date('YmdHis') . '-' . random_int(100, 999);
            $newId = (int)$db->insert(
                "INSERT INTO leases
                 (tenant_id, unit_id, lease_number, start_date, end_date, rent_amount, service_charge, deposit, payment_frequency, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''))",
                [$tenantId, $unitId, $leaseNumber, $startDate, $endDate, $rentAmount, $serviceCharge, $deposit, $paymentFrequency, $status, $notes]
            );
            flash_set('success', 'Lease created.');
            audit_log('create', 'lease', $newId, ['lease_number' => $leaseNumber, 'tenant_id' => $tenantId, 'unit_id' => $unitId, 'status' => $status], $estateIdPost);
        }

        // If lease is active and tenant is active, ensure unit is occupied
        if ($status === 'active' && (string)$tenant['tenant_status'] === 'active') {
            $db->execute("UPDATE units SET status = 'occupied' WHERE id = ? AND estate_id = ?", [$unitId, $estateIdPost]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        flash_set('error', $e->getMessage());
    }

    $qs = 'estate_id=' . $estateIdPost . '&tenant_id=' . $tenantId;
    redirect('leases.php?' . $qs);
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne(
        "SELECT l.*
         FROM leases l
         INNER JOIN tenants t ON t.id = l.tenant_id
         WHERE l.id = ? AND t.estate_id = ?",
        [$editId, $estateId]
    );
    if (!$editing) {
        flash_set('warning', 'Lease not found.');
        redirect('leases.php?estate_id=' . $estateId);
    }
    $tenantIdFilter = $tenantIdFilter ?: (int)$editing['tenant_id'];
}

$where = ['t.estate_id = ?'];
$params = [$estateId];
if ($tenantIdFilter > 0) {
    $where[] = 't.id = ?';
    $params[] = $tenantIdFilter;
}

$leases = $db->fetchAll(
    "SELECT
        l.*,
        u.first_name, u.last_name, u.email,
        un.unit_number,
        p.name AS property_name
     FROM leases l
     INNER JOIN tenants t ON t.id = l.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = l.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY l.created_at DESC",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Leases</h1>
    <div class="text-gray-600">Create and manage lease agreements per tenant.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="leases.php" class="row g-3 align-items-end">
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
      <div class="col-12 col-md-6">
        <label class="form-label">Tenant (optional)</label>
        <select class="form-select" name="tenant_id" onchange="this.form.submit()">
          <option value="0" <?= $tenantIdFilter <= 0 ? 'selected' : '' ?>>All tenants</option>
          <?php foreach ($tenants as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $tenantIdFilter ? 'selected' : '' ?>>
              <?= e($t['first_name'] . ' ' . $t['last_name']) ?> (<?= e($t['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
    </form>
    <div class="mt-4 d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-light" href="tenants.php?estate_id=<?= $estateId ?>">Tenants</a>
      <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= $estateId ?>">Units</a>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Lease' : 'Add Lease' ?></div>
      </div>
      <div class="card-body">
        <?php if (!$tenants): ?>
          <div class="text-gray-600">No tenants yet. Create a tenant first.</div>
        <?php else: ?>
          <form method="post" action="leases.php?estate_id=<?= $estateId ?><?= $tenantIdFilter ? '&tenant_id=' . $tenantIdFilter : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <input type="hidden" name="estate_id" value="<?= $estateId ?>">

            <div class="mb-4">
              <label class="form-label required">Tenant</label>
              <?php $tenantVal = (int)($editing['tenant_id'] ?? ($tenantIdFilter ?: 0)); ?>
              <select class="form-select" name="tenant_id" required>
                <option value="">Select tenant</option>
                <?php foreach ($tenants as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $tenantVal ? 'selected' : '' ?>>
                    <?= e($t['first_name'] . ' ' . $t['last_name']) ?> (<?= e($t['email']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Unit is automatically the tenant’s assigned unit.</div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label required">Start date</label>
                <input class="form-control" type="date" name="start_date" required value="<?= e($editing['start_date'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label required">End date</label>
                <input class="form-control" type="date" name="end_date" required value="<?= e($editing['end_date'] ?? '') ?>">
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Rent amount</label>
                <input class="form-control" type="number" step="0.01" min="0" name="rent_amount" value="<?= e($editing['rent_amount'] ?? 0) ?>">
                <div class="form-text">If left as 0 on create, we use the unit’s rent amount.</div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Service charge</label>
                <input class="form-control" type="number" step="0.01" min="0" name="service_charge" value="<?= e($editing['service_charge'] ?? 0) ?>">
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Deposit</label>
                <input class="form-control" type="number" step="0.01" min="0" name="deposit" value="<?= e($editing['deposit'] ?? 0) ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Frequency</label>
                <?php $freq = (string)($editing['payment_frequency'] ?? 'yearly'); ?>
                <select class="form-select" name="payment_frequency">
                  <option value="monthly" <?= $freq === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                  <option value="quarterly" <?= $freq === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                  <option value="yearly" <?= $freq === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                  <option value="custom" <?= $freq === 'custom' ? 'selected' : '' ?>>Custom</option>
                </select>
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label">Status</label>
              <?php $st = (string)($editing['status'] ?? 'active'); ?>
              <select class="form-select" name="status">
                <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="expired" <?= $st === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="terminated" <?= $st === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                <option value="renewed" <?= $st === 'renewed' ? 'selected' : '' ?>>Renewed</option>
              </select>
            </div>

            <div class="mb-6">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="3"><?= e($editing['notes'] ?? '') ?></textarea>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Lease' ?></button>
              <?php if ($editing): ?>
                <a class="btn btn-light" href="leases.php?estate_id=<?= $estateId ?><?= $tenantIdFilter ? '&tenant_id=' . $tenantIdFilter : '' ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Lease list</div>
      </div>
      <div class="card-body">
        <?php if (!$leases): ?>
          <div class="text-gray-600">No leases yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Lease #</th>
                  <th>Tenant</th>
                  <th>Unit</th>
                  <th>Period</th>
                  <th>Status</th>
                  <th class="text-end">Rent</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($leases as $l): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($l['lease_number'] ?? '') ?></td>
                  <td class="text-gray-700"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></td>
                  <td class="text-gray-700"><?= e($l['property_name']) ?> — <?= e($l['unit_number']) ?></td>
                  <td class="text-gray-700"><?= e($l['start_date']) ?> → <?= e($l['end_date']) ?></td>
                  <td>
                    <span class="badge badge-light-<?= $l['status'] === 'active' ? 'success' : ($l['status'] === 'draft' ? 'primary' : 'warning') ?>">
                      <?= e($l['status']) ?>
                    </span>
                  </td>
                  <td class="text-end"><?= number_format((float)$l['rent_amount'], 2) ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary"
                         href="leases.php?estate_id=<?= $estateId ?><?= $tenantIdFilter ? '&tenant_id=' . $tenantIdFilter : '' ?>&edit_id=<?= (int)$l['id'] ?>">
                        Edit
                      </a>
                      <form method="post" action="leases.php?estate_id=<?= $estateId ?><?= $tenantIdFilter ? '&tenant_id=' . $tenantIdFilter : '' ?>" onsubmit="return confirm('Delete this lease?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
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

