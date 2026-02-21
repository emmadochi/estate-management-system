<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'User ↔ Estate Assignments – EstatePro';
$db = db();
$method = request_method();

$userId = (int)(get_param('user_id', 0) ?? 0);
$estateId = (int)(get_param('estate_id', 0) ?? 0);

$users = $db->fetchAll("SELECT id, email, first_name, last_name, role FROM users ORDER BY created_at DESC LIMIT 300");
$estates = $db->fetchAll("SELECT id, name FROM estates ORDER BY name ASC");

if ($userId <= 0 && $users) {
    $userId = (int)$users[0]['id'];
}
if ($estateId <= 0 && $estates) {
    $estateId = (int)$estates[0]['id'];
}

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'assign') {
        $userIdPost = (int)(post_param('user_id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        $role = (string)post_param('role', 'estate_admin');

        if ($userIdPost <= 0 || $estateIdPost <= 0) {
            flash_set('error', 'Select a user and an estate.');
            redirect('assignments.php');
        }

        if (!in_array($role, ['estate_admin', 'property_manager', 'tenant', 'staff', 'security'], true)) {
            flash_set('error', 'Invalid assignment role.');
            redirect('assignments.php?user_id=' . $userIdPost);
        }

        try {
            $before = $db->fetchOne('SELECT id, user_id, estate_id, role FROM user_estates WHERE user_id = ? AND estate_id = ? LIMIT 1', [$userIdPost, $estateIdPost]);
            $db->execute(
                "INSERT INTO user_estates (user_id, estate_id, role)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)",
                [$userIdPost, $estateIdPost, $role]
            );
            flash_set('success', 'Assignment saved.');
            $after = $db->fetchOne('SELECT id, user_id, estate_id, role FROM user_estates WHERE user_id = ? AND estate_id = ? LIMIT 1', [$userIdPost, $estateIdPost]);
            if ($after) {
                if ($before) {
                    $diff = audit_diff($before, $after, ['role']);
                    audit_log('update', 'user_estate', (int)$after['id'], ['user_id' => $userIdPost, 'estate_id' => $estateIdPost, 'diff' => $diff], $estateIdPost);
                } else {
                    audit_log('create', 'user_estate', (int)$after['id'], ['user_id' => $userIdPost, 'estate_id' => $estateIdPost, 'role' => $role], $estateIdPost);
                }
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not save assignment.');
        }

        redirect('assignments.php?user_id=' . $userIdPost);
    }

    if ($action === 'remove') {
        $id = (int)(post_param('id', 0) ?? 0);
        $userIdPost = (int)(post_param('user_id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, user_id, estate_id, role FROM user_estates WHERE id = ?', [$id]);
            $db->execute('DELETE FROM user_estates WHERE id = ?', [$id]);
            flash_set('success', 'Assignment removed.');
            if ($before) {
                audit_log('delete', 'user_estate', (int)$before['id'], ['user_id' => (int)$before['user_id'], 'estate_id' => (int)$before['estate_id'], 'role' => $before['role'] ?? null], (int)$before['estate_id']);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not remove assignment.');
        }
        redirect('assignments.php?user_id=' . $userIdPost);
    }
}

$selectedUser = $userId > 0 ? $db->fetchOne('SELECT id, email, first_name, last_name, role FROM users WHERE id = ?', [$userId]) : null;

$assignments = [];
if ($userId > 0) {
    $assignments = $db->fetchAll(
        "SELECT ue.*, e.name AS estate_name
         FROM user_estates ue
         INNER JOIN estates e ON e.id = ue.estate_id
         WHERE ue.user_id = ?
         ORDER BY e.name ASC",
        [$userId]
    );
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">User ↔ Estate Assignments</h1>
    <div class="text-gray-600">Link users to estates for multi-estate access control.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="assignments.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label">User</label>
        <select class="form-select" name="user_id" onchange="this.form.submit()">
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $userId ? 'selected' : '' ?>>
              <?= e(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?> (<?= e($u['email'] ?? '') ?>) — <?= e($u['role'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label">Quick links</label>
        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-sm btn-light" href="users.php">Users</a>
          <?php if ($userId): ?>
            <a class="btn btn-sm btn-light-primary" href="users.php?edit_id=<?= $userId ?>">Edit selected user</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Assign estate access</div>
      </div>
      <div class="card-body">
        <?php if (!$selectedUser): ?>
          <div class="text-gray-600">Select a user.</div>
        <?php elseif (!$estates): ?>
          <div class="text-gray-600">Create an estate first.</div>
        <?php else: ?>
          <form method="post" action="assignments.php?user_id=<?= $userId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="user_id" value="<?= $userId ?>">

            <div class="mb-4">
              <label class="form-label">Estate</label>
              <select class="form-select" name="estate_id">
                <?php foreach ($estates as $eRow): ?>
                  <option value="<?= (int)$eRow['id'] ?>"><?= e($eRow['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-6">
              <label class="form-label">Role within estate</label>
              <select class="form-select" name="role">
                <option value="estate_admin">estate_admin</option>
                <option value="property_manager">property_manager</option>
                <option value="tenant">tenant</option>
                <option value="staff">staff</option>
                <option value="security">security</option>
              </select>
              <div class="form-text">This is the “scoped” role for that estate.</div>
            </div>

            <button class="btn btn-primary" type="submit">Save assignment</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Current assignments</div>
      </div>
      <div class="card-body">
        <?php if (!$userId): ?>
          <div class="text-gray-600">Select a user.</div>
        <?php elseif (!$assignments): ?>
          <div class="text-gray-600">No assignments yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Estate</th>
                  <th>Role</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($assignments as $a): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($a['estate_name']) ?></td>
                  <td><span class="badge badge-light"><?= e($a['role']) ?></span></td>
                  <td class="text-end">
                    <form method="post" action="assignments.php?user_id=<?= $userId ?>" onsubmit="return confirm('Remove this assignment?');" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <input type="hidden" name="user_id" value="<?= $userId ?>">
                      <button class="btn btn-sm btn-light-danger" type="submit">Remove</button>
                    </form>
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

