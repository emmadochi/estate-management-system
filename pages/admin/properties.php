<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Properties – EstatePro';

$db = db();
$method = request_method();

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

$estateId = normalize_estate_id((int)(get_param('estate_id', 0) ?? 0));

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, estate_id, name, type, status FROM properties WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);
            $db->execute('DELETE FROM properties WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);
            flash_set('success', 'Property deleted.');
            if ($before) {
                audit_log('delete', 'property', (int)$before['id'], ['name' => $before['name'] ?? null, 'type' => $before['type'] ?? null, 'status' => $before['status'] ?? null], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete property. Make sure it has no units.');
        }
        redirect('properties.php?estate_id=' . $estateId);
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
    assert_can_access_estate($estateIdPost);
    $name = trim((string)post_param('name', ''));
    $type = (string)post_param('type', 'block');
    $address = trim((string)post_param('address', ''));
    $status = (string)post_param('status', 'active');

    if ($estateIdPost <= 0) {
        flash_set('error', 'Please select an estate.');
        redirect('properties.php');
    }
    if ($name === '') {
        flash_set('error', 'Property name is required.');
        redirect('properties.php?estate_id=' . $estateIdPost . ($id > 0 ? ('&edit_id=' . $id) : ''));
    }

    try {
        if ($id > 0) {
            $before = $db->fetchOne('SELECT id, estate_id, name, type, address, status FROM properties WHERE id = ? AND estate_id = ?', [$id, $estateIdPost]);
            $db->execute(
                'UPDATE properties SET name = ?, type = ?, address = NULLIF(?, \'\'), status = ? WHERE id = ? AND estate_id = ?',
                [$name, $type, $address, $status, $id, $estateIdPost]
            );
            flash_set('success', 'Property updated.');
            $after = $db->fetchOne('SELECT id, estate_id, name, type, address, status FROM properties WHERE id = ? AND estate_id = ?', [$id, $estateIdPost]);
            if ($before && $after) {
                $diff = audit_diff($before, $after, ['name','type','address','status']);
                audit_log('update', 'property', (int)$id, ['diff' => $diff], $estateIdPost);
            }
        } else {
            $newId = (int)$db->insert(
                'INSERT INTO properties (estate_id, name, type, address, status) VALUES (?, ?, ?, NULLIF(?, \'\'), ?)',
                [$estateIdPost, $name, $type, $address, $status]
            );
            flash_set('success', 'Property created.');
            audit_log('create', 'property', $newId, ['name' => $name, 'type' => $type, 'status' => $status], $estateIdPost);
        }
    } catch (Throwable $e) {
        flash_set('error', 'Save failed.');
    }

    redirect('properties.php?estate_id=' . $estateIdPost);
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM properties WHERE id = ? AND estate_id = ?', [$editId, $estateId]);
    if (!$editing) {
        flash_set('warning', 'Property not found for selected estate.');
        redirect('properties.php?estate_id=' . $estateId);
    }
}

$properties = $db->fetchAll(
    "SELECT
        p.*,
        (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id) AS units_count,
        (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'occupied') AS occupied_units_count,
        (SELECT COUNT(*) FROM units u WHERE u.property_id = p.id AND u.status = 'vacant') AS vacant_units_count
     FROM properties p
     WHERE p.estate_id = ?
     ORDER BY p.created_at DESC",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Properties</h1>
    <div class="text-gray-600">Manage blocks/buildings/houses within an estate.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="fw-bold text-gray-800">Estate:</div>
    <form method="get" action="properties.php" class="d-flex align-items-center gap-2">
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
      <a class="btn btn-sm btn-light" href="estates.php">Back to estates</a>
      <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= $estateId ?>">View units</a>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Property' : 'Add Property' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="properties.php?estate_id=<?= $estateId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
          <input type="hidden" name="estate_id" value="<?= $estateId ?>">

          <div class="mb-4">
            <label class="form-label required">Name</label>
            <input class="form-control" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="e.g. Block A">
          </div>

          <div class="mb-4">
            <label class="form-label">Type</label>
            <?php $typeVal = (string)($editing['type'] ?? 'block'); ?>
            <select class="form-select" name="type">
              <option value="block" <?= $typeVal === 'block' ? 'selected' : '' ?>>Block</option>
              <option value="building" <?= $typeVal === 'building' ? 'selected' : '' ?>>Building</option>
              <option value="house" <?= $typeVal === 'house' ? 'selected' : '' ?>>House</option>
              <option value="commercial" <?= $typeVal === 'commercial' ? 'selected' : '' ?>>Commercial</option>
              <option value="other" <?= $typeVal === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label">Status</label>
            <?php $statusVal = (string)($editing['status'] ?? 'active'); ?>
            <select class="form-select" name="status">
              <option value="active" <?= $statusVal === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $statusVal === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              <option value="under_maintenance" <?= $statusVal === 'under_maintenance' ? 'selected' : '' ?>>Under maintenance</option>
            </select>
          </div>

          <div class="mb-6">
            <label class="form-label">Address/Notes</label>
            <textarea class="form-control" name="address" rows="3"><?= e($editing['address'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Property' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="properties.php?estate_id=<?= $estateId ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">All Properties</div>
      </div>
      <div class="card-body">
        <?php if (!$properties): ?>
          <div class="text-gray-600">No properties yet for this estate.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Name</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th class="text-end">Units</th>
                  <th class="text-end">Occupied</th>
                  <th class="text-end">Vacant</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($properties as $p): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($p['name']) ?></td>
                  <td class="text-gray-700"><?= e($p['type']) ?></td>
                  <td>
                    <span class="badge badge-light-<?= $p['status'] === 'active' ? 'success' : ($p['status'] === 'inactive' ? 'danger' : 'warning') ?>">
                      <?= e($p['status']) ?>
                    </span>
                  </td>
                  <td class="text-end"><?= (int)$p['units_count'] ?></td>
                  <td class="text-end"><?= (int)$p['occupied_units_count'] ?></td>
                  <td class="text-end"><?= (int)$p['vacant_units_count'] ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= $estateId ?>&property_id=<?= (int)$p['id'] ?>">Units</a>
                      <a class="btn btn-sm btn-light-primary" href="properties.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$p['id'] ?>">Edit</a>
                      <form method="post" action="properties.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Delete this property? This will fail if it has units.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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

