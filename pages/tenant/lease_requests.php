<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Lease Requests – EstatePro Tenant';
$pageHeading = 'Lease Requests';
$db = db();
$me = current_user();

$noTenancy = ($tenant === null);
$method = request_method();

if (!$noTenancy && $me && $method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'create') {
        $type = (string)post_param('type', 'renewal');
        $preferred = trim((string)post_param('preferred_start_date', ''));
        $notes = trim((string)post_param('notes', ''));

        $allowedTypes = ['new', 'renewal', 'transfer'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'renewal';
        }

        try {
            $db->insert(
                "INSERT INTO lease_requests
                 (user_id, tenant_id, estate_id, unit_id, type, preferred_start_date, notes)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))",
                [
                    (int)$me['id'],
                    (int)$tenant['id'],
                    (int)$tenant['estate_id'],
                    (int)$tenant['unit_id'],
                    $type,
                    $preferred,
                    $notes,
                ]
            );
            if (function_exists('notify_estate_admins')) {
                notify_estate_admins(
                    (int)$tenant['estate_id'],
                    'new_lease_request',
                    'New lease request',
                    'A tenant has submitted a lease request.',
                    'lease_requests.php?estate_id=' . (int)$tenant['estate_id']
                );
            }
            flash_set('success', 'Your request has been submitted. The estate team will review and get back to you.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not submit request. Please try again.');
        }

        redirect('lease_requests.php');
    }
}

$requests = [];
if (!$noTenancy && $me) {
    $requests = $db->fetchAll(
        "SELECT lr.*, u.first_name, u.last_name
         FROM lease_requests lr
         INNER JOIN users u ON u.id = lr.user_id
         WHERE lr.user_id = ?
         ORDER BY lr.created_at DESC
         LIMIT 50",
        [(int)$me['id']]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php else: ?>

<div class="row g-5 mb-5">
    <div class="col-lg-6">
        <div class="card card-flush h-lg-100">
            <div class="card-header">
                <h3 class="card-title">Request a lease action</h3>
            </div>
            <div class="card-body pt-0">
                <form method="post" action="lease_requests.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="mb-5">
                        <label class="form-label fw-semibold">Request type</label>
                        <div class="d-flex flex-wrap gap-4 mt-2">
                            <label class="form-check form-check-custom form-check-solid form-check-lg">
                                <input class="form-check-input" type="radio" name="type" value="renewal" checked>
                                <span class="form-check-label fw-semibold">Renewal</span>
                            </label>
                            <label class="form-check form-check-custom form-check-solid form-check-lg">
                                <input class="form-check-input" type="radio" name="type" value="transfer">
                                <span class="form-check-label fw-semibold">Transfer</span>
                            </label>
                            <label class="form-check form-check-custom form-check-solid form-check-lg">
                                <input class="form-check-input" type="radio" name="type" value="new">
                                <span class="form-check-label fw-semibold">New lease</span>
                            </label>
                        </div>
                        <div class="form-text text-muted mt-2">Choose what you want to do with your lease.</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label fw-semibold">Preferred start date</label>
                        <input type="date" name="preferred_start_date" class="form-control form-control-lg" value="">
                        <div class="form-text text-muted mt-2">Optional – the date you would like the new or renewed lease to start.</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label fw-semibold">Notes for estate manager</label>
                        <textarea name="notes" class="form-control form-control-lg" rows="4" placeholder="Provide any extra details (e.g. reason for transfer, preferred unit, special conditions)..."></textarea>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-lg px-6">Submit request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card card-flush h-lg-100">
            <div class="card-header">
                <h3 class="card-title">My recent requests</h3>
            </div>
            <div class="card-body pt-0">
                <?php if (empty($requests)): ?>
                    <p class="text-gray-600 mb-0">You have not submitted any lease requests yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-row-bordered align-middle gs-0 gy-3">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Preferred start</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $r): ?>
                                <tr>
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
                                    <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
                                </tr>
                                <?php if (!empty($r['notes'])): ?>
                                <tr class="table-light">
                                    <td colspan="4" class="text-muted small">Your note: <?= nl2br(e($r['notes'])) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (!empty($r['admin_notes'])): ?>
                                <tr class="table-light">
                                    <td colspan="4" class="text-muted small">Estate response: <?= nl2br(e($r['admin_notes'])) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>

