<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Payment History – EstatePro Tenant';
$pageHeading = 'Payment History';
$db = db();

$noTenancy = ($tenant === null);
$payments = [];

if (!$noTenancy) {
    $payments = $db->fetchAll(
        "SELECT p.id, p.payment_reference, p.amount, p.payment_method, p.status, p.payment_date, p.receipt_number,
                i.invoice_number
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE p.tenant_id = ?
         ORDER BY p.payment_date DESC
         LIMIT 200",
        [(int)$tenant['id']]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php elseif (empty($payments)): ?>
<div class="card card-flush">
    <div class="card-body">
        <p class="text-gray-600 mb-0">No payment history found.</p>
        <a href="invoices.php" class="btn btn-sm btn-light-primary mt-3">Rent & Bills</a>
        <a href="dashboard.php" class="btn btn-sm btn-light-secondary mt-3">Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Payment history</h3>
        <a href="invoices.php" class="btn btn-sm btn-light-primary">Rent & Bills</a>
    </div>
    <div class="card-body pt-0">
        <div class="table-responsive">
            <table class="table table-row-bordered align-middle gs-0 gy-3">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Invoice</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?= e($p['payment_reference']) ?></td>
                        <td><?= e($p['invoice_number'] ?? '') ?></td>
                        <td><?= e(number_format((float)$p['amount'], 2)) ?></td>
                        <td><?= e(str_replace('_', ' ', (string)($p['payment_method'] ?? ''))) ?></td>
                        <td><?= e(date('M j, Y H:i', strtotime($p['payment_date']))) ?></td>
                        <?php
                        $pst = (string)($p['status'] ?? '');
                        $pBadge = 'secondary';
                        if ($pst === 'completed') $pBadge = 'success';
                        elseif ($pst === 'pending') $pBadge = 'warning';
                        elseif ($pst === 'failed') $pBadge = 'danger';
                        ?>
                        <td><span class="badge badge-light-<?= $pBadge ?>"><?= e($pst) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Back to Dashboard</a>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
