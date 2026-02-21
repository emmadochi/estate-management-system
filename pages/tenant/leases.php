<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'My Lease – EstatePro Tenant';
$pageHeading = 'My Lease';
$db = db();

$noTenancy = ($tenant === null);
$leases = [];

if (!$noTenancy) {
    $leases = $db->fetchAll(
        "SELECT id, lease_number, start_date, end_date, rent_amount, service_charge, deposit,
                payment_frequency, status, created_at
         FROM leases
         WHERE tenant_id = ?
         ORDER BY start_date DESC",
        [(int)$tenant['id']]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php elseif (empty($leases)): ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Lease history</h3>
        <a href="lease_requests.php" class="btn btn-sm btn-light-primary">Request lease / renewal</a>
    </div>
    <div class="card-body">
        <p class="text-gray-600 mb-0">No lease records found for your account.</p>
        <a href="dashboard.php" class="btn btn-sm btn-light-secondary mt-3">Back to Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Lease history</h3>
        <a href="lease_requests.php" class="btn btn-sm btn-light-primary">Request lease / renewal</a>
    </div>
    <div class="card-body pt-0">
        <div class="table-responsive">
            <table class="table table-row-bordered align-middle gs-0 gy-3">
                <thead>
                    <tr>
                        <th>Lease number</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Rent</th>
                        <th>Frequency</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leases as $l): ?>
                    <tr>
                        <td><?= e($l['lease_number'] ?? '—') ?></td>
                        <td><?= e(date('M j, Y', strtotime($l['start_date']))) ?></td>
                        <td><?= e(date('M j, Y', strtotime($l['end_date']))) ?></td>
                        <td><?= e(number_format((float)$l['rent_amount'], 2)) ?></td>
                        <td><?= e($l['payment_frequency'] ?? '') ?></td>
                        <td><span class="badge badge-light-<?= ($l['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= e($l['status'] ?? '') ?></span></td>
                    </tr>
                    <?php if (!empty($l['service_charge']) && (float)$l['service_charge'] > 0): ?>
                    <tr class="table-light">
                        <td colspan="4" class="text-muted small">Service charge: <?= e(number_format((float)$l['service_charge'], 2)) ?></td>
                        <td colspan="2"></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Back to Dashboard</a>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
