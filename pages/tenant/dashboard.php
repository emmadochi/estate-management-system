<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Dashboard – EstatePro Tenant';
$pageHeading = 'Dashboard';
$db = db();
$me = current_user();

$noTenancy = ($tenant === null);
$lease = null;
$nextInvoice = null;
$openTickets = [];
$recentAnnouncements = [];

if (!$noTenancy) {
    $tid = (int)$tenant['id'];
    $eid = (int)$tenant['estate_id'];
    $uid = (int)$tenant['unit_id'];

    $lease = $db->fetchOne(
        "SELECT id, lease_number, start_date, end_date, rent_amount, service_charge, payment_frequency, status
         FROM leases
         WHERE tenant_id = ? AND status = 'active'
         ORDER BY end_date DESC
         LIMIT 1",
        [$tid]
    );

    $nextInvoice = $db->fetchOne(
        "SELECT id, invoice_number, type, amount, due_date, status
         FROM invoices
         WHERE tenant_id = ? AND status IN ('pending','overdue','partial')
         ORDER BY due_date ASC
         LIMIT 1",
        [$tid]
    );

    $openTickets = $db->fetchAll(
        "SELECT id, ticket_number, title, status, priority, created_at
         FROM maintenance_tickets
         WHERE tenant_id = ? AND status IN ('open','assigned','in_progress')
         ORDER BY created_at DESC
         LIMIT 5",
        [$tid]
    );

    $recentAnnouncements = $db->fetchAll(
        "SELECT id, title, type, priority, published_at
         FROM announcements
         WHERE estate_id = ? AND status = 'published' AND published_at IS NOT NULL
           AND target_audience IN ('all','tenants')
         ORDER BY published_at DESC
         LIMIT 5",
        [$eid]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="card card-flush">
    <div class="card-body text-center py-12">
        <i class="ki-duotone ki-information-5 fs-3x text-warning mb-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
        <h3 class="text-gray-900 fw-bold mb-2">No active tenancy</h3>
        <p class="text-gray-600 mb-6">Your account is not linked to an active tenancy. Please contact your estate manager.</p>
        <a href="profile.php" class="btn btn-primary">Profile & Security</a>
    </div>
</div>
<?php else: ?>

<!-- Welcome Section -->
<div class="card card-flush mb-6" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="card-body p-9">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h1 class="text-white fw-bold mb-2">Welcome back, <?= e($me['first_name'] ?? 'Tenant') ?>!</h1>
                <div class="text-white opacity-75 fs-5">
                    <i class="ki-duotone ki-home-2 fs-4 me-2"><span class="path1"></span><span class="path2"></span></i>
                    <?= e($tenant['estate_name'] ?? '') ?> — Unit <?= e($tenant['unit_number'] ?? '') ?>
                    <?php if (!empty($tenant['property_name'])): ?> (<?= e($tenant['property_name']) ?>)<?php endif; ?>
                </div>
            </div>
            <div class="d-none d-md-block">
                <i class="ki-duotone ki-home-1 fs-2x text-white opacity-50"><span class="path1"></span><span class="path2"></span></i>
            </div>
        </div>
    </div>
</div>

<!-- Stats Cards Row -->
<div class="row g-5 g-xl-8 mb-6">
    <?php if ($lease): ?>
    <div class="col-md-6 col-xl-3">
        <div class="card card-flush h-xl-100 hover-elevate-up">
            <div class="card-body d-flex flex-column p-6">
                <div class="d-flex align-items-center mb-4">
                    <div class="symbol symbol-50px me-4">
                        <div class="symbol-label bg-light-primary">
                            <i class="ki-duotone ki-document fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-gray-600 fw-semibold fs-7 mb-1">Active Lease</div>
                        <div class="text-gray-900 fw-bold fs-4"><?= e($lease['lease_number'] ?? 'N/A') ?></div>
                    </div>
                </div>
                <div class="separator separator-dashed my-4"></div>
                <div class="d-flex flex-column">
                    <div class="text-gray-600 fs-7 mb-2">
                        <i class="ki-duotone ki-calendar fs-6 me-1"><span class="path1"></span><span class="path2"></span></i>
                        <?= e(date('M j, Y', strtotime($lease['start_date']))) ?> – <?= e(date('M j, Y', strtotime($lease['end_date']))) ?>
                    </div>
                    <div class="text-gray-900 fw-bold fs-5 mb-3">
                        <?= e(number_format((float)($lease['rent_amount'] ?? 0), 2)) ?> <span class="text-gray-600 fs-7"><?= e($lease['payment_frequency'] ?? '') ?></span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="leases.php" class="btn btn-sm btn-light-primary flex-grow-1">View lease</a>
                        <a href="lease_requests.php" class="btn btn-sm btn-light flex-grow-1">Request</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($nextInvoice): ?>
    <?php
    $daysUntilDue = (int)((strtotime($nextInvoice['due_date']) - time()) / 86400);
    $isOverdue = $daysUntilDue < 0;
    $isDueSoon = $daysUntilDue >= 0 && $daysUntilDue <= 7;
    $invoiceStatus = $nextInvoice['status'] ?? 'pending';
    $cardColor = $isOverdue ? 'danger' : ($isDueSoon ? 'warning' : 'success');
    ?>
    <div class="col-md-6 col-xl-3">
        <div class="card card-flush h-xl-100 hover-elevate-up border border-<?= $cardColor ?> border-dashed">
            <div class="card-body d-flex flex-column p-6">
                <div class="d-flex align-items-center mb-4">
                    <div class="symbol symbol-50px me-4">
                        <div class="symbol-label bg-light-<?= $cardColor ?>">
                            <i class="ki-duotone ki-bill fs-2x text-<?= $cardColor ?>"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-gray-600 fw-semibold fs-7 mb-1">Next Due</div>
                        <div class="text-gray-900 fw-bold fs-6"><?= e($nextInvoice['invoice_number']) ?></div>
                    </div>
                </div>
                <div class="separator separator-dashed my-4"></div>
                <div class="d-flex flex-column">
                    <div class="text-gray-600 fs-7 mb-2">
                        <i class="ki-duotone ki-calendar fs-6 me-1"><span class="path1"></span><span class="path2"></span></i>
                        Due: <?= e(date('M j, Y', strtotime($nextInvoice['due_date']))) ?>
                        <?php if ($isOverdue): ?>
                            <span class="badge badge-light-danger ms-2">Overdue</span>
                        <?php elseif ($isDueSoon): ?>
                            <span class="badge badge-light-warning ms-2">Due soon</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-gray-900 fw-bold fs-3 mb-3">
                        <?= e(number_format((float)$nextInvoice['amount'], 2)) ?>
                    </div>
                    <a href="invoices.php" class="btn btn-sm btn-<?= $cardColor ?> flex-grow-1">Pay now</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-6 col-xl-3">
        <a href="maintenance.php" class="card card-flush h-xl-100 hover-elevate-up text-gray-800 text-hover-primary">
            <div class="card-body d-flex flex-column p-6">
                <div class="d-flex align-items-center mb-4">
                    <div class="symbol symbol-50px me-4">
                        <div class="symbol-label bg-light-info">
                            <i class="ki-duotone ki-setting-2 fs-2x text-info"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-gray-600 fw-semibold fs-7 mb-1">Open Tickets</div>
                        <div class="text-gray-900 fw-bold fs-2x"><?= count($openTickets) ?></div>
                    </div>
                </div>
                <div class="separator separator-dashed my-4"></div>
                <div class="text-gray-600 fs-7">
                    <?php if (empty($openTickets)): ?>
                        No open tickets
                    <?php else: ?>
                        Latest: <?= e(mb_strimwidth($openTickets[0]['title'] ?? '', 0, 30, '…')) ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>

    <div class="col-md-6 col-xl-3">
        <a href="announcements.php" class="card card-flush h-xl-100 hover-elevate-up text-gray-800 text-hover-primary">
            <div class="card-body d-flex flex-column p-6">
                <div class="d-flex align-items-center mb-4">
                    <div class="symbol symbol-50px me-4">
                        <div class="symbol-label bg-light-success">
                            <i class="ki-duotone ki-notification-on fs-2x text-success"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-gray-600 fw-semibold fs-7 mb-1">Announcements</div>
                        <div class="text-gray-900 fw-bold fs-2x"><?= count($recentAnnouncements) ?></div>
                    </div>
                </div>
                <div class="separator separator-dashed my-4"></div>
                <div class="text-gray-600 fs-7">
                    <?php if (empty($recentAnnouncements)): ?>
                        No announcements
                    <?php else: ?>
                        Latest: <?= e(mb_strimwidth($recentAnnouncements[0]['title'] ?? '', 0, 30, '…')) ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Quick Actions -->
<div class="row g-5 mb-6">
    <div class="col-12">
        <div class="card card-flush">
            <div class="card-header border-0 pt-6">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Quick Actions</span>
                    <span class="text-gray-500 mt-1 fw-semibold fs-7">Access your most important features</span>
                </h3>
            </div>
            <div class="card-body pt-0">
                <div class="row g-3">
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="leases.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-primary bg-hover-light-primary text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-document fs-2x text-primary mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">My Lease</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="invoices.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-warning bg-hover-light-warning text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-bill fs-2x text-warning mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">Rent & Bills</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="payments.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-success bg-hover-light-success text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-credit-cart fs-2x text-success mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">Payments</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="maintenance.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-info bg-hover-light-info text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-setting-2 fs-2x text-info mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">Maintenance</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="announcements.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-danger bg-hover-light-danger text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-notification-on fs-2x text-danger mb-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                            <span class="fw-semibold fs-7">Announcements</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="lease_requests.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-dark bg-hover-light-dark text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-chart-simple fs-2x text-dark mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">Request Lease</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <a href="emergency_alert_pro.php" class="d-flex flex-column align-items-center text-center p-4 rounded bg-light-danger bg-hover-light-danger text-gray-800 text-hover-primary">
                            <i class="ki-duotone ki-siren fs-2x text-danger mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <span class="fw-semibold fs-7">Emergency</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-5">
    <?php if (!empty($openTickets)): ?>
    <div class="col-12 col-xl-6">
        <div class="card card-flush h-xl-100">
            <div class="card-header border-0 pt-6">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Open Maintenance Tickets</span>
                    <span class="text-gray-500 mt-1 fw-semibold fs-7">Track your service requests</span>
                </h3>
                <div class="card-toolbar">
                    <a href="maintenance.php" class="btn btn-sm btn-light-primary">View all</a>
                </div>
            </div>
            <div class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table table-row-dashed align-middle gs-3">
                        <thead>
                            <tr class="text-gray-600 fw-bold fs-7 text-uppercase">
                                <th class="min-w-100px">Ticket</th>
                                <th class="min-w-150px">Title</th>
                                <th class="min-w-80px">Priority</th>
                                <th class="min-w-100px text-end">Date</th>
                            </tr>
                        </thead>
                        <tbody class="fw-semibold text-gray-700">
                            <?php foreach ($openTickets as $t): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-light-primary"><?= e($t['ticket_number']) ?></span>
                                </td>
                                <td>
                                    <a href="maintenance.php" class="text-gray-800 text-hover-primary"><?= e($t['title']) ?></a>
                                </td>
                                <td>
                                    <?php
                                    $pri = (string)($t['priority'] ?? 'medium');
                                    $priBadge = 'primary';
                                    if ($pri === 'urgent') $priBadge = 'danger';
                                    elseif ($pri === 'high') $priBadge = 'warning';
                                    elseif ($pri === 'low') $priBadge = 'secondary';
                                    ?>
                                    <span class="badge badge-light-<?= $priBadge ?>"><?= e(ucfirst($pri)) ?></span>
                                </td>
                                <td class="text-end text-gray-600 fs-7"><?= e(date('M j, Y', strtotime($t['created_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recentAnnouncements)): ?>
    <div class="col-12 col-xl-<?= !empty($openTickets) ? '6' : '12' ?>">
        <div class="card card-flush h-xl-100">
            <div class="card-header border-0 pt-6">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Recent Announcements</span>
                    <span class="text-gray-500 mt-1 fw-semibold fs-7">Stay updated with estate news</span>
                </h3>
                <div class="card-toolbar">
                    <a href="announcements.php" class="btn btn-sm btn-light-primary">View all</a>
                </div>
            </div>
            <div class="card-body pt-0">
                <div class="scroll-y mh-400px">
                    <?php foreach ($recentAnnouncements as $a): ?>
                    <div class="d-flex flex-stack py-4 border-bottom border-gray-200">
                        <div class="d-flex align-items-center flex-grow-1">
                            <div class="symbol symbol-40px me-3">
                                <?php
                                $typeIcon = 'notification-on';
                                $typeColor = 'primary';
                                if ($a['type'] === 'emergency') { $typeIcon = 'warning-2'; $typeColor = 'danger'; }
                                elseif ($a['type'] === 'maintenance') { $typeIcon = 'setting-2'; $typeColor = 'warning'; }
                                elseif ($a['type'] === 'payment') { $typeIcon = 'bill'; $typeColor = 'success'; }
                                elseif ($a['type'] === 'event') { $typeIcon = 'calendar'; $typeColor = 'info'; }
                                ?>
                                <div class="symbol-label bg-light-<?= $typeColor ?>">
                                    <i class="ki-duotone ki-<?= $typeIcon ?> fs-5 text-<?= $typeColor ?>"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <a href="announcements.php#ann-<?= (int)$a['id'] ?>" class="text-gray-800 text-hover-primary fw-bold fs-6 mb-1 d-block"><?= e($a['title']) ?></a>
                                <div class="text-gray-600 fs-7">
                                    <span class="badge badge-light-<?= $typeColor ?> badge-sm me-2"><?= e(ucfirst((string)$a['type'])) ?></span>
                                    <?= e(date('M j, Y g:i A', strtotime($a['published_at']))) ?>
                                </div>
                            </div>
                        </div>
                        <a href="announcements.php#ann-<?= (int)$a['id'] ?>" class="btn btn-sm btn-icon btn-light">
                            <i class="ki-duotone ki-right fs-2"><span class="path1"></span><span class="path2"></span></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
