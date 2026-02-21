<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Estates – EstatePro';

$db = db();
$method = request_method();

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, name, code, status FROM estates WHERE id = ?', [$deleteId]);
            $db->execute('DELETE FROM estates WHERE id = ?', [$deleteId]);
            flash_set('success', 'Estate deleted.');
            if ($before) {
                audit_log('delete', 'estate', (int)$before['id'], ['name' => $before['name'] ?? null, 'code' => $before['code'] ?? null, 'status' => $before['status'] ?? null], (int)$before['id']);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete estate. Make sure it has no properties/units.');
        }
        redirect('estates.php');
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $name = trim((string)post_param('name', ''));
    $code = trim((string)post_param('code', ''));
    $address = trim((string)post_param('address', ''));
    $city = trim((string)post_param('city', ''));
    $state = trim((string)post_param('state', ''));
    $country = trim((string)post_param('country', 'Nigeria'));
    $phone = trim((string)post_param('phone', ''));
    $email = trim((string)post_param('email', ''));
    $status = (string)post_param('status', 'active');

    if ($name === '') {
        flash_set('error', 'Estate name is required.');
        redirect($id > 0 ? ('estates.php?edit_id=' . $id) : 'estates.php');
    }

    try {
        if ($id > 0) {
            $before = $db->fetchOne('SELECT id, name, code, address, city, state, country, phone, email, status FROM estates WHERE id = ?', [$id]);
            $db->execute(
                'UPDATE estates
                 SET name = ?, code = NULLIF(?, \'\'), address = NULLIF(?, \'\'), city = NULLIF(?, \'\'), state = NULLIF(?, \'\'),
                     country = NULLIF(?, \'\'), phone = NULLIF(?, \'\'), email = NULLIF(?, \'\'), status = ?
                 WHERE id = ?',
                [$name, $code, $address, $city, $state, $country, $phone, $email, $status, $id]
            );
            flash_set('success', 'Estate updated.');
            $after = $db->fetchOne('SELECT id, name, code, address, city, state, country, phone, email, status FROM estates WHERE id = ?', [$id]);
            if ($before && $after) {
                $diff = audit_diff($before, $after, ['name','code','address','city','state','country','phone','email','status']);
                audit_log('update', 'estate', (int)$id, ['diff' => $diff], (int)$id);
            }
        } else {
            $newId = (int)$db->insert(
                'INSERT INTO estates (name, code, address, city, state, country, phone, email, status)
                 VALUES (?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), ?)',
                [$name, $code, $address, $city, $state, $country, $phone, $email, $status]
            );
            flash_set('success', 'Estate created.');
            audit_log('create', 'estate', $newId, ['name' => $name, 'code' => $code, 'status' => $status], $newId);
        }
    } catch (Throwable $e) {
        flash_set('error', 'Save failed. (Tip: estate code must be unique if provided.)');
    }

    redirect('estates.php');
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM estates WHERE id = ?', [$editId]);
    if (!$editing) {
        flash_set('warning', 'Estate not found.');
        redirect('estates.php');
    }
}

$estates = $db->fetchAll(
    "SELECT
        e.*,
        (SELECT COUNT(*) FROM properties p WHERE p.estate_id = e.id) AS properties_count,
        (SELECT COUNT(*) FROM units u WHERE u.estate_id = e.id) AS units_count,
        (SELECT COUNT(*) FROM units u WHERE u.estate_id = e.id AND u.status = 'occupied') AS occupied_units_count,
        (SELECT COUNT(*) FROM units u WHERE u.estate_id = e.id AND u.status = 'vacant') AS vacant_units_count
     FROM estates e
     ORDER BY e.created_at DESC"
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Estates</h1>
    <div class="text-gray-600">Create estates first, then add properties and units.</div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Estate' : 'Add Estate' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="estates.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-4">
            <label class="form-label required">Name</label>
            <input class="form-control" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="e.g. Sunview Gardens">
          </div>

          <div class="mb-4">
            <label class="form-label">Code (unique)</label>
            <input class="form-control" name="code" value="<?= e($editing['code'] ?? '') ?>" placeholder="e.g. SVG-001">
          </div>

          <div class="mb-4">
            <label class="form-label">Status</label>
            <?php $statusVal = (string)($editing['status'] ?? 'active'); ?>
            <select class="form-select" name="status">
              <option value="active" <?= $statusVal === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $statusVal === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              <option value="under_construction" <?= $statusVal === 'under_construction' ? 'selected' : '' ?>>Under construction</option>
            </select>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">City</label>
              <input class="form-control" name="city" value="<?= e($editing['city'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">State</label>
              <input class="form-control" name="state" value="<?= e($editing['state'] ?? '') ?>">
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Country</label>
            <input class="form-control" name="country" value="<?= e($editing['country'] ?? 'Nigeria') ?>">
          </div>

          <div class="mb-4">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="address" rows="3"><?= e($editing['address'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-6">
            <div class="col-12 col-md-6">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" value="<?= e($editing['phone'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control" name="email" value="<?= e($editing['email'] ?? '') ?>">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Estate' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="estates.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">All Estates</div>
      </div>
      <div class="card-body">
        <?php if (!$estates): ?>
          <div class="text-gray-600">No estates yet. Create one on the left.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Name</th>
                  <th>Code</th>
                  <th>Status</th>
                  <th class="text-end">Properties</th>
                  <th class="text-end">Units</th>
                  <th class="text-end">Occupied</th>
                  <th class="text-end">Vacant</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($estates as $estate): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($estate['name']) ?></td>
                  <td class="text-gray-700"><?= e($estate['code'] ?? '') ?></td>
                  <td>
                    <span class="badge badge-light-<?= $estate['status'] === 'active' ? 'success' : ($estate['status'] === 'inactive' ? 'danger' : 'warning') ?>">
                      <?= e($estate['status']) ?>
                    </span>
                  </td>
                  <td class="text-end"><?= (int)$estate['properties_count'] ?></td>
                  <td class="text-end"><?= (int)$estate['units_count'] ?></td>
                  <td class="text-end"><?= (int)$estate['occupied_units_count'] ?></td>
                  <td class="text-end"><?= (int)$estate['vacant_units_count'] ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="properties.php?estate_id=<?= (int)$estate['id'] ?>">Properties</a>
                      <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= (int)$estate['id'] ?>">Units</a>
                      <a class="btn btn-sm btn-light-primary" href="estates.php?edit_id=<?= (int)$estate['id'] ?>">Edit</a>
                      <form method="post" action="estates.php" onsubmit="return confirm('Delete this estate? This will fail if it has related records.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$estate['id'] ?>">
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

