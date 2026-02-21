<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'staff', 'security']);

$pageTitle = 'Units – EstatePro';

$db = db();
$method = request_method();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$propertyId = (int)(get_param('property_id', 0) ?? 0);
$statusFilter = (string)(get_param('status', '') ?? '');

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

$properties = $db->fetchAll('SELECT id, name FROM properties WHERE estate_id = ? ORDER BY name ASC', [$estateId]);
if ($propertyId > 0) {
    $ok = false;
    foreach ($properties as $p) {
        if ((int)$p['id'] === $propertyId) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $propertyId = 0;
    }
}

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, estate_id, property_id, unit_number, status FROM units WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);
            $db->execute('DELETE FROM units WHERE id = ? AND estate_id = ?', [$deleteId, $estateId]);
            flash_set('success', 'Unit deleted.');
            if ($before) {
                audit_log('delete', 'unit', (int)$before['id'], ['unit_number' => $before['unit_number'] ?? null, 'property_id' => $before['property_id'] ?? null, 'status' => $before['status'] ?? null], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete unit.');
        }
        $qs = 'estate_id=' . $estateId . ($propertyId ? ('&property_id=' . $propertyId) : '');
        redirect('units.php?' . $qs);
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
    assert_can_access_estate($estateIdPost);
    $propertyIdPost = (int)(post_param('property_id', 0) ?? 0);
    $unitNumber = trim((string)post_param('unit_number', ''));
    $unitType = (string)post_param('unit_type', 'apartment');
    $bedrooms = (int)(post_param('bedrooms', 0) ?? 0);
    $bathrooms = (int)(post_param('bathrooms', 0) ?? 0);
    $rentAmount = (float)(post_param('rent_amount', 0) ?? 0);
    $serviceCharge = (float)(post_param('service_charge', 0) ?? 0);
    $status = (string)post_param('status', 'vacant');
    $ownerType = (string)post_param('owner_type', 'tenant');
    $notes = trim((string)post_param('notes', ''));

    if ($estateIdPost <= 0) {
        flash_set('error', 'Please select an estate.');
        redirect('units.php');
    }
    if ($propertyIdPost <= 0) {
        flash_set('error', 'Please select a property.');
        redirect('units.php?estate_id=' . $estateIdPost);
    }
    if ($unitNumber === '') {
        flash_set('error', 'Unit number is required.');
        redirect('units.php?estate_id=' . $estateIdPost . '&property_id=' . $propertyIdPost . ($id ? ('&edit_id=' . $id) : ''));
    }

    try {
        if ($id > 0) {
            $before = $db->fetchOne(
                'SELECT id, estate_id, property_id, unit_number, unit_type, bedrooms, bathrooms, rent_amount, service_charge, status, owner_type, notes
                 FROM units WHERE id = ? AND estate_id = ?',
                [$id, $estateIdPost]
            );
            $db->execute(
                "UPDATE units
                 SET estate_id = ?, property_id = ?, unit_number = ?, unit_type = ?, bedrooms = ?, bathrooms = ?,
                     rent_amount = ?, service_charge = ?, status = ?, owner_type = ?, notes = NULLIF(?, '')
                 WHERE id = ? AND estate_id = ?",
                [
                    $estateIdPost, $propertyIdPost, $unitNumber, $unitType, $bedrooms, $bathrooms,
                    $rentAmount, $serviceCharge, $status, $ownerType, $notes,
                    $id, $estateIdPost
                ]
            );
            flash_set('success', 'Unit updated.');
            $after = $db->fetchOne(
                'SELECT id, estate_id, property_id, unit_number, unit_type, bedrooms, bathrooms, rent_amount, service_charge, status, owner_type, notes
                 FROM units WHERE id = ? AND estate_id = ?',
                [$id, $estateIdPost]
            );
            if ($before && $after) {
                $diff = audit_diff($before, $after, ['property_id','unit_number','unit_type','bedrooms','bathrooms','rent_amount','service_charge','status','owner_type','notes']);
                audit_log('update', 'unit', (int)$id, ['diff' => $diff], $estateIdPost);
            }
        } else {
            $newId = (int)$db->insert(
                "INSERT INTO units
                 (estate_id, property_id, unit_number, unit_type, bedrooms, bathrooms, rent_amount, service_charge, status, owner_type, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''))",
                [
                    $estateIdPost, $propertyIdPost, $unitNumber, $unitType, $bedrooms, $bathrooms,
                    $rentAmount, $serviceCharge, $status, $ownerType, $notes
                ]
            );
            flash_set('success', 'Unit created.');
            audit_log('create', 'unit', $newId, ['unit_number' => $unitNumber, 'property_id' => $propertyIdPost, 'status' => $status], $estateIdPost);
        }
    } catch (Throwable $e) {
        flash_set('error', 'Save failed. (Tip: unit number must be unique within the same estate + property.)');
    }

    $qs = 'estate_id=' . $estateIdPost . '&property_id=' . $propertyIdPost;
    redirect('units.php?' . $qs);
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM units WHERE id = ? AND estate_id = ?', [$editId, $estateId]);
    if (!$editing) {
        flash_set('warning', 'Unit not found for selected estate.');
        redirect('units.php?estate_id=' . $estateId);
    }
    $propertyId = $propertyId ?: (int)$editing['property_id'];
}

$where = ['u.estate_id = ?'];
$params = [$estateId];

if ($propertyId > 0) {
    $where[] = 'u.property_id = ?';
    $params[] = $propertyId;
}
if ($statusFilter !== '' && in_array($statusFilter, ['vacant', 'occupied', 'reserved', 'under_maintenance'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $statusFilter;
}

$units = $db->fetchAll(
    "SELECT
        u.*,
        p.name AS property_name
     FROM units u
     INNER JOIN properties p ON p.id = u.property_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY p.name ASC, u.unit_number ASC",
    $params
);

$stats = $db->fetchAll(
    "SELECT u.status, COUNT(*) AS c
     FROM units u
     WHERE u.estate_id = ? " . ($propertyId > 0 ? "AND u.property_id = ?" : "") . "
     GROUP BY u.status",
    $propertyId > 0 ? [$estateId, $propertyId] : [$estateId]
);
$statMap = ['vacant' => 0, 'occupied' => 0, 'reserved' => 0, 'under_maintenance' => 0];
foreach ($stats as $row) {
    $k = (string)$row['status'];
    if (array_key_exists($k, $statMap)) {
        $statMap[$k] = (int)$row['c'];
    }
}

$occupancyRows = $db->fetchAll(
    "SELECT
        p.id AS property_id,
        p.name AS property_name,
        SUM(CASE WHEN u.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_count,
        SUM(CASE WHEN u.status = 'vacant' THEN 1 ELSE 0 END) AS vacant_count,
        COUNT(u.id) AS total_count
     FROM properties p
     LEFT JOIN units u ON u.property_id = p.id
     WHERE p.estate_id = ?
     GROUP BY p.id, p.name
     ORDER BY p.name ASC",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Units</h1>
    <div class="text-gray-600">Track vacancy/occupancy and unit pricing.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="units.php" class="row g-3 align-items-end">
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
      <div class="col-12 col-md-4">
        <label class="form-label">Property (optional)</label>
        <select class="form-select" name="property_id" onchange="this.form.submit()">
          <option value="0" <?= $propertyId <= 0 ? 'selected' : '' ?>>All properties</option>
          <?php foreach ($properties as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $propertyId ? 'selected' : '' ?>>
              <?= e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <option value="vacant" <?= $statusFilter === 'vacant' ? 'selected' : '' ?>>Vacant</option>
          <option value="occupied" <?= $statusFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
          <option value="reserved" <?= $statusFilter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
          <option value="under_maintenance" <?= $statusFilter === 'under_maintenance' ? 'selected' : '' ?>>Under maintenance</option>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
    </form>
    <div class="mt-4 d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-light" href="properties.php?estate_id=<?= $estateId ?>">Back to properties</a>
      <a class="btn btn-sm btn-light" href="estates.php">Back to estates</a>
    </div>
  </div>
</div>

<div class="row g-6 mb-6">
  <div class="col-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="text-gray-600">Vacant</div>
        <div class="fs-2 fw-bold text-gray-900"><?= $statMap['vacant'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="text-gray-600">Occupied</div>
        <div class="fs-2 fw-bold text-gray-900"><?= $statMap['occupied'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="text-gray-600">Reserved</div>
        <div class="fs-2 fw-bold text-gray-900"><?= $statMap['reserved'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="card">
      <div class="card-body">
        <div class="text-gray-600">Under maintenance</div>
        <div class="fs-2 fw-bold text-gray-900"><?= $statMap['under_maintenance'] ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Unit' : 'Add Unit' ?></div>
      </div>
      <div class="card-body">
        <?php if (!$properties): ?>
          <div class="text-gray-600">
            No properties in this estate yet.
            <a href="properties.php?estate_id=<?= $estateId ?>">Create a property</a>.
          </div>
        <?php else: ?>
          <form method="post" action="units.php?estate_id=<?= $estateId ?><?= $propertyId ? '&property_id=' . $propertyId : '' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
            <input type="hidden" name="estate_id" value="<?= $estateId ?>">

            <div class="mb-4">
              <label class="form-label required">Property</label>
              <?php $propVal = (int)($editing['property_id'] ?? ($propertyId ?: (int)($properties[0]['id'] ?? 0))); ?>
              <select class="form-select" name="property_id" required>
                <?php foreach ($properties as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $propVal ? 'selected' : '' ?>>
                    <?= e($p['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-4">
              <label class="form-label required">Unit number</label>
              <input class="form-control" name="unit_number" required value="<?= e($editing['unit_number'] ?? '') ?>" placeholder="e.g. A-12">
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Unit type</label>
                <?php $typeVal = (string)($editing['unit_type'] ?? 'apartment'); ?>
                <select class="form-select" name="unit_type">
                  <option value="apartment" <?= $typeVal === 'apartment' ? 'selected' : '' ?>>Apartment</option>
                  <option value="flat" <?= $typeVal === 'flat' ? 'selected' : '' ?>>Flat</option>
                  <option value="duplex" <?= $typeVal === 'duplex' ? 'selected' : '' ?>>Duplex</option>
                  <option value="penthouse" <?= $typeVal === 'penthouse' ? 'selected' : '' ?>>Penthouse</option>
                  <option value="shop" <?= $typeVal === 'shop' ? 'selected' : '' ?>>Shop</option>
                  <option value="office" <?= $typeVal === 'office' ? 'selected' : '' ?>>Office</option>
                  <option value="warehouse" <?= $typeVal === 'warehouse' ? 'selected' : '' ?>>Warehouse</option>
                  <option value="other" <?= $typeVal === 'other' ? 'selected' : '' ?>>Other</option>
                </select>
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label">Bedrooms</label>
                <input class="form-control" type="number" min="0" name="bedrooms" value="<?= e($editing['bedrooms'] ?? 0) ?>">
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label">Bathrooms</label>
                <input class="form-control" type="number" min="0" name="bathrooms" value="<?= e($editing['bathrooms'] ?? 0) ?>">
              </div>
            </div>

            <div class="row g-3 mb-4">
              <div class="col-12 col-md-6">
                <label class="form-label">Rent amount</label>
                <input class="form-control" type="number" step="0.01" min="0" name="rent_amount" value="<?= e($editing['rent_amount'] ?? 0) ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Service charge</label>
                <input class="form-control" type="number" step="0.01" min="0" name="service_charge" value="<?= e($editing['service_charge'] ?? 0) ?>">
              </div>
            </div>

            <div class="row g-3 mb-6">
              <div class="col-12 col-md-6">
                <label class="form-label">Status</label>
                <?php $stVal = (string)($editing['status'] ?? 'vacant'); ?>
                <select class="form-select" name="status">
                  <option value="vacant" <?= $stVal === 'vacant' ? 'selected' : '' ?>>Vacant</option>
                  <option value="occupied" <?= $stVal === 'occupied' ? 'selected' : '' ?>>Occupied</option>
                  <option value="reserved" <?= $stVal === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                  <option value="under_maintenance" <?= $stVal === 'under_maintenance' ? 'selected' : '' ?>>Under maintenance</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label">Owner type</label>
                <?php $ownVal = (string)($editing['owner_type'] ?? 'tenant'); ?>
                <select class="form-select" name="owner_type">
                  <option value="tenant" <?= $ownVal === 'tenant' ? 'selected' : '' ?>>Tenant</option>
                  <option value="owner" <?= $ownVal === 'owner' ? 'selected' : '' ?>>Owner</option>
                </select>
              </div>
            </div>

            <div class="mb-6">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="notes" rows="3"><?= e($editing['notes'] ?? '') ?></textarea>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Unit' ?></button>
              <?php if ($editing): ?>
                <a class="btn btn-light" href="units.php?estate_id=<?= $estateId ?><?= $propertyId ? '&property_id=' . $propertyId : '' ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card-header">
        <div class="card-title fw-bold">Occupancy by property</div>
      </div>
      <div class="card-body">
        <?php if (!$occupancyRows): ?>
          <div class="text-gray-600">No properties yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Property</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Occupied</th>
                  <th class="text-end">Vacant</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($occupancyRows as $r): ?>
                  <tr>
                    <td class="fw-bold text-gray-900"><?= e($r['property_name']) ?></td>
                    <td class="text-end"><?= (int)$r['total_count'] ?></td>
                    <td class="text-end"><?= (int)$r['occupied_count'] ?></td>
                    <td class="text-end"><?= (int)$r['vacant_count'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Units list</div>
      </div>
      <div class="card-body">
        <?php if (!$units): ?>
          <div class="text-gray-600">No units found for this filter.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Property</th>
                  <th>Unit</th>
                  <th>Status</th>
                  <th class="text-end">Rent</th>
                  <th class="text-end">Service</th>
                  <th class="text-end">Beds</th>
                  <th class="text-end">Baths</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($units as $u): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($u['property_name']) ?></td>
                  <td class="text-gray-800"><?= e($u['unit_number']) ?></td>
                  <td>
                    <?php
                      $badge = 'badge-light';
                      if ($u['status'] === 'occupied') $badge = 'badge-light-success';
                      if ($u['status'] === 'vacant') $badge = 'badge-light-primary';
                      if ($u['status'] === 'reserved') $badge = 'badge-light-warning';
                      if ($u['status'] === 'under_maintenance') $badge = 'badge-light-danger';
                    ?>
                    <span class="badge <?= e($badge) ?>"><?= e($u['status']) ?></span>
                  </td>
                  <td class="text-end"><?= number_format((float)$u['rent_amount'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$u['service_charge'], 2) ?></td>
                  <td class="text-end"><?= (int)$u['bedrooms'] ?></td>
                  <td class="text-end"><?= (int)$u['bathrooms'] ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary"
                         href="units.php?estate_id=<?= $estateId ?><?= $propertyId ? '&property_id=' . $propertyId : '' ?>&edit_id=<?= (int)$u['id'] ?>">
                        Edit
                      </a>
                      <form method="post" action="units.php?estate_id=<?= $estateId ?><?= $propertyId ? '&property_id=' . $propertyId : '' ?>" onsubmit="return confirm('Delete this unit?');">
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

