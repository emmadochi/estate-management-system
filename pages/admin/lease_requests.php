<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Lease Requests – EstatePro';
$db = db();
$method = request_method();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
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

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled'];

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'update_status') {
        $id = (int)(post_param('id', 0) ?? 0);
        $newStatus = (string)post_param('status', 'pending');
        $adminNotes = trim((string)post_param('admin_notes', ''));

        if (!in_array($newStatus, $allowedStatuses, true)) {
            flash_set('error', 'Invalid status.');
            redirect('lease_requests.php?estate_id=' . $estateId . '&status=' . urlencode($statusFilter));
        }

        try {
            $before = $db->fetchOne(
                "SELECT * FROM lease_requests WHERE id = ? AND estate_id = ?",
                [$id, $estateId]
            );
            if (!$before) {
                throw new RuntimeException('Request not found.');
            }

            $me = current_user();
            $deciderId = $me ? (int)$me['id'] : null;

            $db->execute(
                "UPDATE lease_requests
                 SET status = ?, admin_notes = NULLIF(?, ''),
                     decided_by = NULLIF(?, 0),
                     decided_at = CASE
                         WHEN ? IN ('approved','rejected','cancelled') THEN COALESCE(decided_at, NOW())
                         ELSE decided_at
                     END
                 WHERE id = ? AND estate_id = ?",
                [$newStatus, $adminNotes, $deciderId, $newStatus, $id, $estateId]
            );

            $after = $db->fetchOne(
                "SELECT * FROM lease_requests WHERE id = ? AND estate_id = ?",
                [$id, $estateId]
            );

            flash_set('success', 'Request updated.');

            if ($before && $after) {
                $diff = audit_diff($before, $after, ['status', 'admin_notes', 'decided_by', 'decided_at']);
                audit_log('update', 'lease_request', (int)$id, ['diff' => $diff, 'estate_id' => $estateId], $estateId);
            }

            if (in_array($newStatus, ['approved', 'rejected'], true) && function_exists('notify_user')) {
                $reqUserId = (int)($before['user_id'] ?? 0);
                if ($reqUserId > 0) {
                    notify_user(
                        $reqUserId,
                        'lease_request_status',
                        'Your lease request was ' . $newStatus,
                        $adminNotes ?: 'No additional message.',
                        'lease_requests.php'
                    );
                }
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not update request.');
        }

        $qs = 'estate_id=' . $estateId;
        if ($statusFilter !== '') {
            $qs .= '&status=' . urlencode($statusFilter);
        }
        redirect('lease_requests.php?' . $qs);
    }
}

$where = ['lr.estate_id = ?'];
$params = [$estateId];

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'lr.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$requests = $db->fetchAll(
    "SELECT
        lr.*,
        u.first_name, u.last_name, u.email,
        t.status AS tenant_status,
        un.unit_number,
        p.name AS property_name
     FROM lease_requests lr
     INNER JOIN users u ON u.id = lr.user_id
     LEFT JOIN tenants t ON t.id = lr.tenant_id
     LEFT JOIN units un ON un.id = lr.unit_id
     LEFT JOIN properties p ON p.id = un.property_id
     WHERE $whereSql
     ORDER BY lr.created_at DESC
     LIMIT 200",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="card card-flush mb-5">
    <div class="card-header align-items-center gap-4">
        <div class="card-title">
            <h3 class="card-title">Lease requests</h3>
        </div>
        <div class="card-toolbar">
            <form method="get" action="lease_requests.php" class="d-flex align-items-center gap-3">
                <div>
                    <label class="form-label mb-0 me-2">Estate</label>
                    <select name="estate_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($estates as $e): ?>
                            <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $estateId ? 'selected' : '' ?>>
                                <?= e($e['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label mb-0 me-2">Status</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($allowedStatuses as $s): ?>
                            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                                <?= e(ucfirst($s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>
    <div class="card-body pt-0">
        <?php if (empty($requests)): ?>
            <p class="text-gray-600 mb-0">No lease requests found for the selected filters.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gs-0 gy-3">
                    <thead>
                        <tr>
                            <th>Submitted</th>
                            <th>Tenant</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Preferred start</th>
                            <th>Unit</th>
                            <th>Notes</th>
                            <th>Admin</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= e(date('M j, Y H:i', strtotime($r['created_at']))) ?></td>
                            <td>
                                <?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?><br>
                                <span class="text-muted small"><?= e($r['email'] ?? '') ?></span>
                            </td>
                            <td><?= e(ucfirst((string)$r['type'])) ?></td>
                            <td>
                                <?php
                                $status = (string)($r['status'] ?? 'pending');
                                $badge = 'secondary';
                                if ($status === 'pending') $badge = 'warning';
                                elseif ($status === 'approved') $badge = 'success';
                                elseif ($status === 'rejected') $badge = 'danger';
                                elseif ($status === 'cancelled') $badge = 'dark';
                                ?>
                                <span class="badge badge-light-<?= $badge ?>"><?= e($status) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($r['preferred_start_date'])): ?>
                                    <?= e(date('M j, Y', strtotime($r['preferred_start_date']))) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['unit_number'])): ?>
                                    <?= e($r['unit_number']) ?><?php if (!empty($r['property_name'])): ?> – <?= e($r['property_name']) ?><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?php if (!empty($r['notes'])): ?>
                                    <?= nl2br(e($r['notes'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?php if (!empty($r['admin_notes'])): ?>
                                    <?= nl2br(e($r['admin_notes'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="post" action="lease_requests.php" class="d-inline-flex flex-column align-items-end gap-2 w-250px">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <div class="d-flex gap-2 w-100">
                                        <select name="status" class="form-select form-select-sm">
                                            <?php foreach ($allowedStatuses as $s): ?>
                                                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <textarea name="admin_notes" class="form-control form-control-sm" rows="2" placeholder="Internal note (optional)"><?= e($r['admin_notes'] ?? '') ?></textarea>
                                    <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                </form>
                                <?php if (!empty($r['tenant_id'])): ?>
                                    <a href="leases.php?estate_id=<?= (int)$r['estate_id'] ?>&tenant_id=<?= (int)$r['tenant_id'] ?>" class="btn btn-sm btn-light mt-2">Create / view leases</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

