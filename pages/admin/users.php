<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Users – EstatePro';
$db = db();
$method = request_method();

$editId = (int)(get_param('edit_id', 0) ?? 0);
$action = (string)(get_param('action', '') ?? '');

if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');

    if ($postAction === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $email = strtolower(trim((string)post_param('email', '')));
        $firstName = trim((string)post_param('first_name', ''));
        $lastName = trim((string)post_param('last_name', ''));
        $phone = trim((string)post_param('phone', ''));
        $role = (string)post_param('role', 'tenant');
        $status = (string)post_param('status', 'active');
        $password = (string)post_param('password', '');

        $validRoles = ['super_admin', 'estate_admin', 'property_manager', 'tenant', 'staff', 'security'];
        $validStatuses = ['active', 'inactive', 'suspended'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Valid email is required.');
            redirect($id ? ('users.php?edit_id=' . $id) : 'users.php');
        }
        if ($firstName === '' || $lastName === '') {
            flash_set('error', 'First name and last name are required.');
            redirect($id ? ('users.php?edit_id=' . $id) : 'users.php');
        }
        if (!in_array($role, $validRoles, true)) {
            flash_set('error', 'Invalid role.');
            redirect($id ? ('users.php?edit_id=' . $id) : 'users.php');
        }
        if (!in_array($status, $validStatuses, true)) {
            flash_set('error', 'Invalid status.');
            redirect($id ? ('users.php?edit_id=' . $id) : 'users.php');
        }

        try {
            if ($id > 0) {
                // Do not allow editing self role away from super_admin from here
                $me = current_user();
                if ($me && (int)$me['id'] === $id && $role !== 'super_admin') {
                    flash_set('error', 'You cannot change your own role away from super_admin.');
                    redirect('users.php?edit_id=' . $id);
                }

                $before = $db->fetchOne('SELECT id, email, first_name, last_name, phone, role, status, avatar FROM users WHERE id = ?', [$id]);
                
                $avatarFilename = null;
                $removeAvatar = !empty($_POST['avatar_remove']);
                
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatarFilename = handle_avatar_upload($id);
                    if ($avatarFilename === null && !empty(flash_get())) {
                        redirect('users.php?edit_id=' . $id);
                        exit;
                    }
                } elseif ($removeAvatar) {
                    $oldAvatar = $before['avatar'] ?? null;
                    if ($oldAvatar) {
                        $oldPath = get_avatar_path($oldAvatar);
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $avatarFilename = ''; // Empty to clear
                }

                $params = [$email, $firstName, $lastName, $phone, $role, $status];
                $sql = "UPDATE users
                        SET email = ?, first_name = ?, last_name = ?, phone = NULLIF(?, ''), role = ?, status = ?";
                
                if ($avatarFilename !== null) {
                    $sql .= ", avatar = NULLIF(?, '')";
                    $params[] = $avatarFilename;
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $id;

                $db->execute($sql, $params);

                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->execute('UPDATE users SET password = ? WHERE id = ?', [$hash, $id]);
                }

                flash_set('success', 'User updated.');
                $after = $db->fetchOne('SELECT id, email, first_name, last_name, phone, role, status FROM users WHERE id = ?', [$id]);
                if ($before && $after) {
                    $diff = audit_diff($before, $after, ['email','first_name','last_name','phone','role','status']);
                    audit_log('update', 'user', (int)$id, ['diff' => $diff, 'password_changed' => ($password !== '')], null);
                }
            } else {
                if ($password === '') {
                    $password = 'password';
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                
                $newId = (int)$db->insert(
                    "INSERT INTO users (email, password, first_name, last_name, phone, role, status, email_verified_at)
                     VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, NOW())",
                    [$email, $hash, $firstName, $lastName, $phone, $role, $status]
                );
                
                // Handle avatar upload for new user (after insert so we have the user ID)
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $uploadedFilename = handle_avatar_upload($newId);
                    if ($uploadedFilename !== null) {
                        $db->execute('UPDATE users SET avatar = ? WHERE id = ?', [$uploadedFilename, $newId]);
                    }
                }
                
                flash_set('success', 'User created.');
                audit_log('create', 'user', $newId, ['email' => $email, 'role' => $role, 'status' => $status], null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Save failed. (Tip: email must be unique.)');
        }

        redirect('users.php');
    }

    if ($postAction === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $me = current_user();
            if ($me && (int)$me['id'] === $id) {
                throw new RuntimeException('You cannot delete your own user.');
            }
            $before = $db->fetchOne('SELECT id, email, role, status FROM users WHERE id = ?', [$id]);
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
            flash_set('success', 'User deleted.');
            if ($before) {
                audit_log('delete', 'user', (int)$before['id'], ['email' => $before['email'] ?? null, 'role' => $before['role'] ?? null], null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete user (it may have linked records).');
        }
        redirect('users.php');
    }
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$editId]);
    if (!$editing) {
        flash_set('warning', 'User not found.');
        redirect('users.php');
    }
}

$q = trim((string)get_param('q', ''));
$roleFilter = (string)get_param('role', '');
$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($roleFilter !== '' && in_array($roleFilter, ['super_admin', 'estate_admin', 'property_manager', 'tenant', 'staff', 'security'], true)) {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}

$users = $db->fetchAll(
    "SELECT * FROM users " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . " ORDER BY created_at DESC LIMIT 300",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Users</h1>
    <div class="text-gray-600">Create users, assign roles, and reset passwords.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="users.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-7">
        <label class="form-label">Search</label>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by email or name">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Role</label>
        <select class="form-select" name="role">
          <option value="">All</option>
          <?php foreach (['super_admin','estate_admin','property_manager','tenant','staff','security'] as $r): ?>
            <option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Filter</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit User' : 'Add User' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="users.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-5 text-center">
            <div class="image-input image-input-outline" data-kt-image-input="true" style="background-image: url('<?= e(get_avatar_url($editing['avatar'] ?? null)) ?>')">
              <div class="image-input-wrapper w-125px h-125px" style="background-image: url('<?= e(get_avatar_url($editing['avatar'] ?? null)) ?>')"></div>
              <label class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="change" data-bs-toggle="tooltip" title="Change avatar">
                <i class="ki-duotone ki-pencil fs-7"><span class="path1"></span><span class="path2"></span></i>
                <input type="file" name="avatar" accept="image/*">
                <input type="hidden" name="avatar_remove">
              </label>
              <?php if ($editing): ?>
              <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="cancel" data-bs-toggle="tooltip" title="Cancel avatar">
                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
              </span>
              <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="remove" data-bs-toggle="tooltip" title="Remove avatar">
                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
              </span>
              <?php endif; ?>
            </div>
            <div class="form-text">Allowed: png, jpg, jpeg, gif, webp. Max: 5MB</div>
          </div>

          <div class="mb-4">
            <label class="form-label required">Email</label>
            <input class="form-control" name="email" required value="<?= e($editing['email'] ?? '') ?>">
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

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Role</label>
              <?php $roleVal = (string)($editing['role'] ?? 'tenant'); ?>
              <select class="form-select" name="role">
                <?php foreach (['super_admin','estate_admin','property_manager','tenant','staff','security'] as $r): ?>
                  <option value="<?= e($r) ?>" <?= $roleVal === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Status</label>
              <?php $stVal = (string)($editing['status'] ?? 'active'); ?>
              <select class="form-select" name="status">
                <?php foreach (['active','inactive','suspended'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $stVal === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-6">
            <label class="form-label"><?= $editing ? 'New password (optional)' : 'Password (optional)' ?></label>
            <input class="form-control" type="password" name="password" value="" autocomplete="new-password">
            <div class="form-text"><?= $editing ? 'Leave blank to keep current password.' : 'If blank, default password will be "password".' ?></div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create User' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="users.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">User list</div>
      </div>
      <div class="card-body">
        <?php if (!$users): ?>
          <div class="text-gray-600">No users found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="symbol symbol-40px me-3">
                        <img src="<?= e(get_avatar_url($u['avatar'] ?? null)) ?>" alt="<?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?>" class="w-100">
                      </div>
                      <div class="fw-bold text-gray-900"><?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?></div>
                    </div>
                  </td>
                  <td class="text-gray-700"><?= e($u['email'] ?? '') ?></td>
                  <td><span class="badge badge-light"><?= e($u['role'] ?? '') ?></span></td>
                  <td>
                    <span class="badge badge-light-<?= ($u['status'] ?? '') === 'active' ? 'success' : (($u['status'] ?? '') === 'inactive' ? 'warning' : 'danger') ?>">
                      <?= e($u['status'] ?? '') ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="assignments.php?user_id=<?= (int)$u['id'] ?>">Assignments</a>
                      <a class="btn btn-sm btn-light-primary" href="users.php?edit_id=<?= (int)$u['id'] ?>">Edit</a>
                      <form method="post" action="users.php" onsubmit="return confirm('Delete this user?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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

