<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Rent & Bills – EstatePro Tenant';
$pageHeading = 'Rent & Bills';
$db = db();

$noTenancy = ($tenant === null);
$invoices = [];

if (!$noTenancy) {
    $invoices = $db->fetchAll(
        "SELECT id, invoice_number, type, amount, paid_amount, due_date, status, description, created_at
         FROM invoices
         WHERE tenant_id = ?
         ORDER BY due_date DESC, created_at DESC",
        [(int)$tenant['id']]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php elseif (empty($invoices)): ?>
<div class="card card-flush">
    <div class="card-body">
        <p class="text-gray-600 mb-0">No invoices found.</p>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Back to Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Invoices</h3>
        <a href="payments.php" class="btn btn-sm btn-light-primary">Payment History</a>
    </div>
    <div class="card-body pt-0">
        <div class="table-responsive">
            <table class="table table-row-bordered align-middle gs-0 gy-3">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Due date</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $i): ?>
                    <tr>
                        <td><?= e($i['invoice_number']) ?></td>
                        <td><?= e(ucfirst((string)$i['type'])) ?></td>
                        <td><?= e(number_format((float)$i['amount'], 2)) ?></td>
                        <td><?= e(number_format((float)($i['paid_amount'] ?? 0), 2)) ?></td>
                        <td><?= e(number_format((float)$i['amount'] - (float)($i['paid_amount'] ?? 0), 2)) ?></td>
                        <td><?= e(date('M j, Y', strtotime($i['due_date']))) ?></td>
                        <td>
                            <?php
                            $st = $i['status'] ?? '';
                            $badge = 'secondary';
                            if ($st === 'paid') $badge = 'success';
                            elseif ($st === 'overdue' || $st === 'partial') $badge = 'warning';
                            elseif ($st === 'pending') $badge = 'primary';
                            elseif ($st === 'cancelled') $badge = 'dark';
                            ?>
                            <span class="badge badge-light-<?= $badge ?>"><?= e($st) ?></span>
                        </td>
                        <td class="text-end">
                            <?php
                            $balance = (float)$i['amount'] - (float)($i['paid_amount'] ?? 0);
                            $canPay = $balance > 0 && ($st !== 'cancelled');
                            ?>
                            <?php if ($canPay): ?>
                                <a class="btn btn-sm btn-primary" href="pay_invoice.php?invoice_id=<?= (int)$i['id'] ?>">Pay now</a>
                            <?php else: ?>
                                <span class="text-gray-500">—</span>
                            <?php endif; ?>
                        </td>
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
