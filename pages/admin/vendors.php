<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Vendors – EstatePro';
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

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        if ($estateIdPost > 0) {
            assert_can_access_estate($estateIdPost);
        }
        if (!is_super_admin() && $estateIdPost <= 0) {
            flash_set('error', 'Only Super Admin can create global vendors.');
            redirect('vendors.php?estate_id=' . $estateId);
        }
        $name = trim((string)post_param('name', ''));
        $company = trim((string)post_param('company', ''));
        $phone = trim((string)post_param('phone', ''));
        $email = trim((string)post_param('email', ''));
        $specialization = (string)post_param('specialization', 'other');
        $status = (string)post_param('status', 'active');
        $address = trim((string)post_param('address', ''));
        $notes = trim((string)post_param('notes', ''));

        // Artisan login fields (optional)
        $artisanEmail = strtolower(trim((string)post_param('artisan_email', '')));
        $artisanPassword = (string)post_param('artisan_password', '');
        $artisanPasswordConfirm = (string)post_param('artisan_password_confirmation', '');

        if ($name === '') {
            flash_set('error', 'Vendor name is required.');
            redirect('vendors.php?estate_id=' . $estateId);
        }

        $artisanUserId = null;

        try {
            if ($id > 0) {
                $before = $db->fetchOne('SELECT * FROM vendors WHERE id = ?', [$id]);
                $existingVendorUserId = $before ? (int)($before['user_id'] ?? 0) : 0;

                // Handle artisan account linkage/update
                if ($artisanEmail !== '') {
                    $existingUser = $db->fetchOne('SELECT id, role FROM users WHERE email = ? LIMIT 1', [$artisanEmail]);
                    if ($existingUser) {
                        $existingUserId = (int)($existingUser['id'] ?? 0);
                        if ($existingVendorUserId && $existingUserId !== $existingVendorUserId) {
                            flash_set('error', 'Artisan email is already used by another account.');
                            redirect('vendors.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        if (!in_array($existingUser['role'] ?? '', ['artisan'], true)) {
                            flash_set('error', 'Existing account with this email is not an artisan.');
                            redirect('vendors.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        // Update password if provided
                        if ($artisanPassword !== '') {
                            if ($artisanPassword !== $artisanPasswordConfirm) {
                                flash_set('error', 'Artisan passwords do not match.');
                                redirect('vendors.php?estate_id=' . $estateId . '&edit_id=' . $id);
                            }
                            $hash = password_hash($artisanPassword, PASSWORD_DEFAULT);
                            $db->execute('UPDATE users SET password = ? WHERE id = ?', [$hash, $existingUserId]);
                        }
                        $artisanUserId = $existingUserId;
                    } else {
                        if ($artisanPassword === '' || $artisanPassword !== $artisanPasswordConfirm) {
                            flash_set('error', 'Enter matching passwords to create a new artisan login.');
                            redirect('vendors.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        // Split vendor name into first/last for user
                        $parts = preg_split('/\s+/', $name, 2);
                        $firstName = $parts[0] ?? $name;
                        $lastName = $parts[1] ?? '';
                        $hash = password_hash($artisanPassword, PASSWORD_DEFAULT);
                        $artisanUserId = (int)$db->insert(
                            "INSERT INTO users (email, password, first_name, last_name, phone, role, status)
                             VALUES (?, ?, ?, ?, NULLIF(?, ''), 'artisan', 'active')",
                            [$artisanEmail, $hash, $firstName, $lastName, $phone]
                        );
                    }
                } else {
                    // No artisan email submitted; keep existing linkage as-is.
                    $artisanUserId = $existingVendorUserId ?: null;
                }

                $db->execute(
                    "UPDATE vendors
                     SET estate_id = ?, user_id = ?, name = ?, company = NULLIF(?, ''), phone = NULLIF(?, ''), email = NULLIF(?, ''),
                         specialization = ?, status = ?, address = NULLIF(?, ''), notes = NULLIF(?, '')
                     WHERE id = ?",
                    [
                        $estateIdPost ?: null,
                        $artisanUserId,
                        $name,
                        $company,
                        $phone,
                        $email,
                        $specialization,
                        $status,
                        $address,
                        $notes,
                        $id,
                    ]
                );
                flash_set('success', 'Vendor updated.');
                $after = $db->fetchOne('SELECT * FROM vendors WHERE id = ?', [$id]);
                if ($before && $after) {
                    $diff = audit_diff($before, $after, ['estate_id','user_id','name','company','phone','email','specialization','status','address','notes']);
                    audit_log('update', 'vendor', (int)$id, ['diff' => $diff], $estateIdPost > 0 ? $estateIdPost : null);
                }
            } else {
                // New vendor: optionally create artisan user
                if ($artisanEmail !== '') {
                    $existingUser = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$artisanEmail]);
                    if ($existingUser) {
                        flash_set('error', 'Artisan email is already used by another account.');
                        redirect('vendors.php?estate_id=' . $estateId);
                    }
                    if ($artisanPassword === '' || $artisanPassword !== $artisanPasswordConfirm) {
                        flash_set('error', 'Enter matching passwords to create a new artisan login.');
                        redirect('vendors.php?estate_id=' . $estateId);
                    }
                    $parts = preg_split('/\s+/', $name, 2);
                    $firstName = $parts[0] ?? $name;
                    $lastName = $parts[1] ?? '';
                    $hash = password_hash($artisanPassword, PASSWORD_DEFAULT);
                    $artisanUserId = (int)$db->insert(
                        "INSERT INTO users (email, password, first_name, last_name, phone, role, status)
                         VALUES (?, ?, ?, ?, NULLIF(?, ''), 'artisan', 'active')",
                        [$artisanEmail, $hash, $firstName, $lastName, $phone]
                    );
                }

                $newId = (int)$db->insert(
                    "INSERT INTO vendors (estate_id, user_id, name, company, phone, email, specialization, status, address, notes)
                     VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''))",
                    [
                        $estateIdPost ?: null,
                        $artisanUserId,
                        $name,
                        $company,
                        $phone,
                        $email,
                        $specialization,
                        $status,
                        $address,
                        $notes,
                    ]
                );
                flash_set('success', 'Vendor created.');
                audit_log('create', 'vendor', $newId, ['name' => $name, 'specialization' => $specialization, 'status' => $status], $estateIdPost > 0 ? $estateIdPost : null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Save failed.');
        }

        redirect('vendors.php?estate_id=' . $estateId);
    }

    if ($action === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, estate_id, name, specialization, status FROM vendors WHERE id = ?', [$id]);
            $db->execute('DELETE FROM vendors WHERE id = ?', [$id]);
            flash_set('success', 'Vendor deleted.');
            if ($before) {
                audit_log('delete', 'vendor', (int)$before['id'], ['name' => $before['name'] ?? null, 'specialization' => $before['specialization'] ?? null], (int)($before['estate_id'] ?? 0) ?: null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete vendor (it may be linked to tickets).');
        }
        redirect('vendors.php?estate_id=' . $estateId);
    }
}

$editing = null;
$editingArtisanEmail = '';
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM vendors WHERE id = ?', [$editId]);
    if (!$editing) {
        flash_set('warning', 'Vendor not found.');
        redirect('vendors.php?estate_id=' . $estateId);
    }
    $editingUserId = (int)($editing['user_id'] ?? 0);
    if ($editingUserId > 0) {
        $userRow = $db->fetchOne('SELECT email FROM users WHERE id = ?', [$editingUserId]);
        if ($userRow && !empty($userRow['email'])) {
            $editingArtisanEmail = (string)$userRow['email'];
        }
    }
}

$vendors = $db->fetchAll(
    "SELECT v.*, e.name AS estate_name, u.email AS artisan_email
     FROM vendors v
     LEFT JOIN estates e ON e.id = v.estate_id
     LEFT JOIN users u ON u.id = v.user_id
     WHERE (v.estate_id = ? OR v.estate_id IS NULL)
     ORDER BY v.created_at DESC
     LIMIT 300",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Vendors</h1>
    <div class="text-gray-600">Manage contractors (plumbers, electricians, etc.).</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="fw-bold text-gray-800">Estate:</div>
    <form method="get" action="vendors.php" class="d-flex align-items-center gap-2">
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
      <a class="btn btn-sm btn-light" href="maintenance.php?estate_id=<?= $estateId ?>">Tickets</a>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Vendor' : 'Add Vendor' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="vendors.php?estate_id=<?= $estateId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-4">
            <label class="form-label">Scope</label>
            <?php $scopeEstate = $editing ? (int)($editing['estate_id'] ?? 0) : $estateId; ?>
            <select class="form-select" name="estate_id">
              <option value="0">Global (all estates)</option>
              <?php foreach ($estates as $eRow): ?>
                <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $scopeEstate ? 'selected' : '' ?>>
                  <?= e($eRow['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label required">Name</label>
            <input class="form-control" name="name" required value="<?= e($editing['name'] ?? '') ?>">
          </div>

          <div class="mb-4">
            <label class="form-label">Company</label>
            <input class="form-control" name="company" value="<?= e($editing['company'] ?? '') ?>">
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" value="<?= e($editing['phone'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control" name="email" value="<?= e($editing['email'] ?? '') ?>">
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Specialization</label>
              <?php $spec = (string)($editing['specialization'] ?? 'other'); ?>
              <select class="form-select" name="specialization">
                <?php foreach (['plumbing','electrical','carpentry','painting','security','cleaning','landscaping','other'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $spec === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Status</label>
              <?php $st = (string)($editing['status'] ?? 'active'); ?>
              <select class="form-select" name="status">
                <option value="active" <?= $st === 'active' ? 'selected' : '' ?>>active</option>
                <option value="inactive" <?= $st === 'inactive' ? 'selected' : '' ?>>inactive</option>
              </select>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="address" rows="2"><?= e($editing['address'] ?? '') ?></textarea>
          </div>
          <div class="mb-4">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="2"><?= e($editing['notes'] ?? '') ?></textarea>
          </div>

          <div class="mb-6 border-top pt-4">
            <label class="form-label">Artisan Login (optional)</label>
            <div class="text-gray-600 fs-7 mb-2">
              Link this vendor to an artisan user account so they can log in to the Artisan Area and see assigned tickets.
            </div>
            <div class="mb-3">
              <input class="form-control" name="artisan_email" placeholder="Artisan email" value="<?= e($editingArtisanEmail) ?>">
            </div>
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <input class="form-control" type="password" name="artisan_password" placeholder="<?= $editingArtisanEmail ? 'New password (optional)' : 'Password' ?>">
              </div>
              <div class="col-12 col-md-6">
                <input class="form-control" type="password" name="artisan_password_confirmation" placeholder="<?= $editingArtisanEmail ? 'Confirm new password' : 'Confirm password' ?>">
              </div>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Vendor' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="vendors.php?estate_id=<?= $estateId ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Vendor list (estate + global)</div>
      </div>
      <div class="card-body">
        <?php if (!$vendors): ?>
          <div class="text-gray-600">No vendors found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Name</th>
                  <th>Specialization</th>
                  <th>Scope</th>
                  <th>Status</th>
                  <th>Artisan</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($vendors as $v): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($v['name']) ?></td>
                  <td class="text-gray-700"><?= e($v['specialization']) ?></td>
                  <td class="text-gray-700"><?= e($v['estate_name'] ?? 'Global') ?></td>
                  <td><span class="badge badge-light"><?= e($v['status']) ?></span></td>
                  <td class="text-gray-700">
                    <?php if (!empty($v['artisan_email'])): ?>
                      <span class="badge badge-light-primary">Linked</span>
                      <div class="fs-8 text-gray-600"><?= e($v['artisan_email']) ?></div>
                    <?php else: ?>
                      <span class="badge badge-light">None</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary" href="vendors.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$v['id'] ?>">Edit</a>
                      <form method="post" action="vendors.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Delete this vendor?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
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

