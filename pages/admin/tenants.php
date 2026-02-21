<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Tenants – EstatePro';

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

$editId = (int)(get_param('edit_id', 0) ?? 0);

// Units for selected estate (prefer vacant/reserved)
$availableUnits = $db->fetchAll(
    "SELECT u.id, u.unit_number, u.status, p.name AS property_name
     FROM units u
     INNER JOIN properties p ON p.id = u.property_id
     WHERE u.estate_id = ?
     ORDER BY p.name ASC, u.unit_number ASC",
    [$estateId]
);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $db->beginTransaction();

            $tenant = $db->fetchOne('SELECT id, unit_id, user_id, estate_id FROM tenants WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);
            if (!$tenant) {
                throw new RuntimeException('Tenant not found.');
            }

            // Set unit back to vacant if it belongs to this estate
            $db->execute("UPDATE units SET status = 'vacant' WHERE id = ? AND estate_id = ?", [(int)$tenant['unit_id'], $estateId]);
            $db->execute('DELETE FROM tenants WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);

            $db->commit();
            flash_set('success', 'Tenant removed (unit marked vacant).');
            audit_log(
                'delete',
                'tenant',
                (int)$tenant['id'],
                ['user_id' => (int)$tenant['user_id'], 'unit_id' => (int)$tenant['unit_id']],
                $estateId
            );
        } catch (Throwable $e) {
            $db->rollback();
            flash_set('error', 'Could not remove tenant.');
        }

        redirect('tenants.php?estate_id=' . $estateId);
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
    assert_can_access_estate($estateIdPost);

    // User fields
    $email = strtolower(trim((string)post_param('email', '')));
    $firstName = trim((string)post_param('first_name', ''));
    $lastName = trim((string)post_param('last_name', ''));
    $phone = trim((string)post_param('phone', ''));

    // Tenant fields
    $unitId = (int)(post_param('unit_id', 0) ?? 0);
    $status = (string)post_param('status', 'active');
    $movedIn = (string)post_param('moved_in_date', '');
    $movedOut = (string)post_param('moved_out_date', '');
    $emergencyName = trim((string)post_param('emergency_contact_name', ''));
    $emergencyPhone = trim((string)post_param('emergency_contact_phone', ''));

    if ($estateIdPost <= 0) {
        flash_set('error', 'Please select an estate.');
        redirect('tenants.php');
    }

    if ($firstName === '' || $lastName === '') {
        flash_set('error', 'First name and last name are required.');
        redirect('tenants.php?estate_id=' . $estateIdPost . ($id ? ('&edit_id=' . $id) : ''));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Valid email is required.');
        redirect('tenants.php?estate_id=' . $estateIdPost . ($id ? ('&edit_id=' . $id) : ''));
    }

    try {
        $db->beginTransaction();

        if ($id > 0) {
            // Update existing tenant
            $tenant = $db->fetchOne(
                "SELECT t.*, u.email
                 FROM tenants t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.id = ? AND t.estate_id = ?",
                [$id, $estateIdPost]
            );
            if (!$tenant) {
                throw new RuntimeException('Tenant not found.');
            }
            $beforeTenant = $tenant;

            $db->execute(
                "UPDATE users SET first_name = ?, last_name = ?, phone = NULLIF(?, '') WHERE id = ?",
                [$firstName, $lastName, $phone, (int)$tenant['user_id']]
            );

            $db->execute(
                "UPDATE tenants
                 SET emergency_contact_name = NULLIF(?, ''), emergency_contact_phone = NULLIF(?, ''),
                     status = ?, moved_in_date = NULLIF(?, ''), moved_out_date = NULLIF(?, '')
                 WHERE id = ? AND estate_id = ?",
                [$emergencyName, $emergencyPhone, $status, $movedIn, $movedOut, $id, $estateIdPost]
            );

            // If moved out, free the unit
            if ($status === 'moved_out' || $movedOut !== '') {
                $db->execute("UPDATE units SET status = 'vacant' WHERE id = ? AND estate_id = ?", [(int)$tenant['unit_id'], $estateIdPost]);
            }

            $db->commit();
            flash_set('success', 'Tenant updated.');
            $afterTenant = $db->fetchOne(
                "SELECT t.*
                 FROM tenants t
                 WHERE t.id = ? AND t.estate_id = ?",
                [$id, $estateIdPost]
            );
            if ($afterTenant) {
                $diff = audit_diff($beforeTenant, $afterTenant, ['status','moved_in_date','moved_out_date','emergency_contact_name','emergency_contact_phone']);
                audit_log('update', 'tenant', (int)$id, ['diff' => $diff, 'email' => $email], $estateIdPost);
            }
            redirect('tenants.php?estate_id=' . $estateIdPost);
        }

        // Create new tenant (creates user if needed)
        if ($unitId <= 0) {
            throw new RuntimeException('Please select a unit.');
        }

        $unit = $db->fetchOne('SELECT id, status FROM units WHERE id = ? AND estate_id = ?', [$unitId, $estateIdPost]);
        if (!$unit) {
            throw new RuntimeException('Unit not found in selected estate.');
        }
        if ((string)$unit['status'] === 'occupied') {
            throw new RuntimeException('This unit is already occupied.');
        }

        $user = $db->fetchOne('SELECT id, role FROM users WHERE email = ?', [$email]);
        if ($user) {
            if ((string)$user['role'] !== 'tenant') {
                throw new RuntimeException('That email exists but is not a tenant user.');
            }
            $userId = (int)$user['id'];
            $db->execute('UPDATE users SET first_name = ?, last_name = ?, phone = NULLIF(?, \'\') WHERE id = ?', [$firstName, $lastName, $phone, $userId]);
        } else {
            $defaultPassword = 'tenant123';
            $hash = password_hash($defaultPassword, PASSWORD_BCRYPT);
            $userId = (int)$db->insert(
                "INSERT INTO users (email, password, first_name, last_name, phone, role, status, email_verified_at)
                 VALUES (?, ?, ?, ?, NULLIF(?, ''), 'tenant', 'active', NOW())",
                [$email, $hash, $firstName, $lastName, $phone]
            );
        }

        $tenantId = (int)$db->insert(
            "INSERT INTO tenants
             (user_id, estate_id, unit_id, emergency_contact_name, emergency_contact_phone, status, moved_in_date, moved_out_date)
             VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''))",
            [$userId, $estateIdPost, $unitId, $emergencyName, $emergencyPhone, $status, $movedIn, $movedOut]
        );

        // Link user to estate (multi-estate support)
        $db->execute(
            "INSERT INTO user_estates (user_id, estate_id, role)
             VALUES (?, ?, 'tenant')
             ON DUPLICATE KEY UPDATE role = VALUES(role)",
            [$userId, $estateIdPost]
        );

        // Mark unit occupied if tenant is active
        if ($status === 'active') {
            $db->execute("UPDATE units SET status = 'occupied' WHERE id = ? AND estate_id = ?", [$unitId, $estateIdPost]);
        }

        $db->commit();
        flash_set('success', 'Tenant created. Default password (if new user): tenant123');
        audit_log(
            'create',
            'tenant',
            $tenantId,
            ['user_id' => $userId, 'unit_id' => $unitId, 'status' => $status, 'email' => $email],
            $estateIdPost
        );
    } catch (Throwable $e) {
        $db->rollback();
        flash_set('error', $e->getMessage());
    }

    redirect('tenants.php?estate_id=' . $estateIdPost);
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne(
        "SELECT
            t.*,
            u.email, u.first_name, u.last_name, u.phone,
            un.unit_number,
            p.name AS property_name
         FROM tenants t
         INNER JOIN users u ON u.id = t.user_id
         INNER JOIN units un ON un.id = t.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         WHERE t.id = ? AND t.estate_id = ?",
        [$editId, $estateId]
    );
    if (!$editing) {
        flash_set('warning', 'Tenant not found.');
        redirect('tenants.php?estate_id=' . $estateId);
    }
}

$tenants = $db->fetchAll(
    "SELECT
        t.*,
        u.email, u.first_name, u.last_name, u.phone,
        un.unit_number, un.status AS unit_status,
        p.name AS property_name
     FROM tenants t
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = t.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     WHERE t.estate_id = ?
     ORDER BY t.created_at DESC",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Tenants</h1>
    <div class="text-gray-600">Create tenant accounts and assign them to units.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="fw-bold text-gray-800">Estate:</div>
    <form method="get" action="tenants.php" class="d-flex align-items-center gap-2">
      <select class="form-select form-select-sm" name="estate_id" onchange="this.form.submit()">
        <?php foreach ($estates as $eRow): ?>
          <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
            <?= e($eRow['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm btn-light" type="submit">Go</button></noscript>
    </form>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= $estateId ?>">Units</a>
      <a class="btn btn-sm btn-light" href="properties.php?estate_id=<?= $estateId ?>">Properties</a>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Tenant' : 'Add Tenant' ?></div>
      </div>
      <div class="card-body">
        <?php if (!$availableUnits && !$editing): ?>
          <div class="text-gray-600">No units found. Create properties/units first.</div>
        <?php else: ?>
          <form method="post" action="tenants.php?estate_id=<?= $estateId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <input type="hidden" name="estate_id" value="<?= $estateId ?>">

            <div class="mb-4">
              <label class="form-label required">Email</label>
              <input class="form-control" name="email" required value="<?= e($editing['email'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?>>
              <?php if (!$editing): ?>
                <div class="form-text">If email is new, we create a tenant user with default password: <span class="fw-bold">tenant123</span></div>
              <?php endif; ?>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label required">First name</label>
                <input class="form-control" name="first_name" required value="<?= e($editing['first_name'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label required">Last name</label>
                <input class="form-control" name="last_name" required value="<?= e($editing['last_name'] ?? '') ?>">
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" value="<?= e($editing['phone'] ?? '') ?>">
            </div>

            <div class="mb-4">
              <label class="form-label <?= $editing ? '' : 'required' ?>">Unit</label>
              <?php if ($editing): ?>
                <div class="form-control form-control-solid">
                  <?= e($editing['property_name']) ?> — <?= e($editing['unit_number']) ?>
                </div>
                <div class="form-text">Unit reassignment isn’t enabled yet (keeps leases/invoices consistent).</div>
              <?php else: ?>
                <select class="form-select" name="unit_id" required>
                  <option value="">Select a unit</option>
                  <?php foreach ($availableUnits as $u): ?>
                    <?php if ((string)$u['status'] === 'occupied') continue; ?>
                    <option value="<?= (int)$u['id'] ?>">
                      <?= e($u['property_name']) ?> — <?= e($u['unit_number']) ?> (<?= e($u['status']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Moved in</label>
                <input class="form-control" type="date" name="moved_in_date" value="<?= e($editing['moved_in_date'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Moved out</label>
                <input class="form-control" type="date" name="moved_out_date" value="<?= e($editing['moved_out_date'] ?? '') ?>">
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Emergency contact name</label>
                <input class="form-control" name="emergency_contact_name" value="<?= e($editing['emergency_contact_name'] ?? '') ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Emergency contact phone</label>
                <input class="form-control" name="emergency_contact_phone" value="<?= e($editing['emergency_contact_phone'] ?? '') ?>">
              </div>
            </div>

            <div class="mb-6">
              <label class="form-label">Status</label>
              <?php $st = (string)($editing['status'] ?? 'active'); ?>
              <select class="form-select" name="status">
                <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $st === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="moved_out" <?= $st === 'moved_out' ? 'selected' : '' ?>>Moved out</option>
              </select>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Tenant' ?></button>
              <?php if ($editing): ?>
                <a class="btn btn-light" href="tenants.php?estate_id=<?= $estateId ?>">Cancel</a>
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
        <div class="card-title fw-bold">Tenant list</div>
      </div>
      <div class="card-body">
        <?php if (!$tenants): ?>
          <div class="text-gray-600">No tenants yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Name</th>
                  <th>Email</th>
                  <th>Unit</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($tenants as $t): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></td>
                  <td class="text-gray-700"><?= e($t['email']) ?></td>
                  <td class="text-gray-700"><?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?></td>
                  <td>
                    <span class="badge badge-light-<?= $t['status'] === 'active' ? 'success' : ($t['status'] === 'moved_out' ? 'danger' : 'warning') ?>">
                      <?= e($t['status']) ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="leases.php?estate_id=<?= $estateId ?>&tenant_id=<?= (int)$t['id'] ?>">Leases</a>
                      <a class="btn btn-sm btn-light-primary" href="tenants.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$t['id'] ?>">Edit</a>
                      <form method="post" action="tenants.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Remove this tenant? Unit will be marked vacant.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-light-danger" type="submit">Remove</button>
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

