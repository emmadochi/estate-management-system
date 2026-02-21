<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'staff', 'security']);

$pageTitle = 'Dashboard – EstatePro';
$db = db();

$isSuper = is_super_admin();
$estateId = 0;
$estates = [];

if (!$isSuper) {
    $estates = estates_for_current_user();
    $estateId = normalize_estate_id((int)(get_param('estate_id', 0) ?? 0));
}

if ($isSuper) {
    $stats = [
        'estates' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM estates')['c'] ?? 0),
        'users' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0),
        'tenants' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM tenants')['c'] ?? 0),
        'properties' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM properties')['c'] ?? 0),
        'units' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM units')['c'] ?? 0),
        'units_vacant' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM units WHERE status = 'vacant'")['c'] ?? 0),
        'units_occupied' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM units WHERE status = 'occupied'")['c'] ?? 0),
        'tickets_open' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM maintenance_tickets WHERE status IN ('open','assigned','in_progress')")['c'] ?? 0),
        'invoices_pending' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE status IN ('pending','overdue','partial')")['c'] ?? 0),
        'payments_pending' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM payments WHERE status = 'pending'")['c'] ?? 0),
    ];

    $usersByRole = $db->fetchAll("SELECT role, COUNT(*) AS c FROM users GROUP BY role ORDER BY c DESC");
    $recentEstates = $db->fetchAll("SELECT id, name, status, created_at FROM estates ORDER BY created_at DESC LIMIT 5");
    $recentTickets = $db->fetchAll(
        "SELECT mt.id, mt.ticket_number, mt.title, mt.status, mt.priority, mt.created_at, e.name AS estate_name
         FROM maintenance_tickets mt
         INNER JOIN estates e ON e.id = mt.estate_id
         ORDER BY mt.created_at DESC
         LIMIT 5"
    );
    $recentPendingPayments = $db->fetchAll(
        "SELECT p.id, p.payment_reference, p.amount, p.payment_date, p.payment_method, p.status,
                i.invoice_number, i.estate_id,
                u.first_name, u.last_name
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN tenants t ON t.id = p.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE p.status = 'pending'
         ORDER BY p.created_at DESC
         LIMIT 5"
    );
} else {
    $estate = $db->fetchOne('SELECT id, name FROM estates WHERE id = ? LIMIT 1', [$estateId]);
    $stats = [
        'estates' => count($estates),
        'properties' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM properties WHERE estate_id = ?', [$estateId])['c'] ?? 0),
        'units' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM units WHERE estate_id = ?', [$estateId])['c'] ?? 0),
        'units_vacant' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM units WHERE estate_id = ? AND status = 'vacant'", [$estateId])['c'] ?? 0),
        'units_occupied' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM units WHERE estate_id = ? AND status = 'occupied'", [$estateId])['c'] ?? 0),
        'tenants' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM tenants WHERE estate_id = ?', [$estateId])['c'] ?? 0),
        'tickets_open' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM maintenance_tickets WHERE estate_id = ? AND status IN ('open','assigned','in_progress')", [$estateId])['c'] ?? 0),
        'invoices_pending' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM invoices WHERE estate_id = ? AND status IN ('pending','overdue','partial')", [$estateId])['c'] ?? 0),
        'users_assigned' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM user_estates WHERE estate_id = ?', [$estateId])['c'] ?? 0),
        'estate_name' => (string)($estate['name'] ?? ('Estate #' . $estateId)),
        'payments_pending' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM payments WHERE estate_id = ? AND status = 'pending'", [$estateId])['c'] ?? 0),
    ];

    $recentTickets = $db->fetchAll(
        "SELECT mt.id, mt.ticket_number, mt.title, mt.status, mt.priority, mt.created_at
         FROM maintenance_tickets mt
         WHERE mt.estate_id = ?
         ORDER BY mt.created_at DESC
         LIMIT 8",
        [$estateId]
    );

    $recentAnnouncements = $db->fetchAll(
        "SELECT id, title, status, created_at
         FROM announcements
         WHERE estate_id = ?
         ORDER BY created_at DESC
         LIMIT 8",
        [$estateId]
    );

    $recentPendingPayments = $db->fetchAll(
        "SELECT p.id, p.payment_reference, p.amount, p.payment_date, p.payment_method, p.status,
                i.invoice_number,
                u.first_name, u.last_name
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         INNER JOIN tenants t ON t.id = p.tenant_id
         INNER JOIN users u ON u.id = t.user_id
         WHERE p.estate_id = ? AND p.status = 'pending'
         ORDER BY p.created_at DESC
         LIMIT 5",
        [$estateId]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php
function badge_class_for_status(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['open', 'pending', 'overdue', 'unpaid', 'new'], true)) return 'badge-light-warning';
    if (in_array($s, ['assigned', 'in_progress', 'processing', 'partial'], true)) return 'badge-light-primary';
    if (in_array($s, ['resolved', 'closed', 'paid', 'completed', 'active'], true)) return 'badge-light-success';
    if (in_array($s, ['cancelled', 'canceled', 'rejected', 'failed', 'inactive'], true)) return 'badge-light-danger';
    return 'badge-light';
}

function badge_class_for_priority(string $priority): string {
    $p = strtolower(trim($priority));
    if (in_array($p, ['urgent', 'critical', 'high'], true)) return 'badge-light-danger';
    if (in_array($p, ['medium', 'normal'], true)) return 'badge-light-warning';
    if (in_array($p, ['low'], true)) return 'badge-light-success';
    return 'badge-light';
}

$dashTitle = $isSuper ? 'Super Admin Dashboard' : 'Estate Dashboard';
$dashSubtitle = $isSuper ? 'Global overview across all estates.' : ('Overview for ' . e($stats['estate_name'] ?? ''));
$dashDate = date('D, M j, Y');
$dashTime = date('g:i A');

$unitsTotal = max(1, (int)($stats['units'] ?? 0));
$unitsOccupied = max(0, (int)($stats['units_occupied'] ?? 0));
$unitsVacant = max(0, (int)($stats['units_vacant'] ?? 0));
$ticketsOpen = max(0, (int)($stats['tickets_open'] ?? 0));
$invoicesPending = max(0, (int)($stats['invoices_pending'] ?? 0));
$paymentsPending = max(0, (int)($stats['payments_pending'] ?? 0));

$occupancyRate = (int)round(($unitsOccupied / $unitsTotal) * 100);
$vacancyRate = max(0, 100 - $occupancyRate);

$occupancyBar = $occupancyRate >= 85 ? 'bg-success' : ($occupancyRate >= 70 ? 'bg-primary' : 'bg-warning');
$workloadBar = $ticketsOpen >= 10 ? 'bg-danger' : ($ticketsOpen >= 4 ? 'bg-warning' : 'bg-success');
$invoiceBar = $invoicesPending >= 10 ? 'bg-danger' : ($invoicesPending >= 4 ? 'bg-warning' : 'bg-primary');

$workloadLabel = $ticketsOpen >= 10 ? 'High' : ($ticketsOpen >= 4 ? 'Moderate' : 'Low');
$invoiceRiskLabel = $invoicesPending >= 10 ? 'High' : ($invoicesPending >= 4 ? 'Moderate' : ($invoicesPending > 0 ? 'Low' : 'None'));
?>

<style>
  .ep-hero {
    border: 1px solid var(--bs-border-color);
    background:
      radial-gradient(900px 300px at 10% 10%, rgba(62,151,255,.18), transparent 60%),
      radial-gradient(900px 300px at 90% 0%, rgba(80,205,137,.16), transparent 55%),
      var(--bs-body-bg);
  }
  [data-bs-theme="dark"] .ep-hero {
    background:
      radial-gradient(900px 300px at 10% 10%, rgba(62,151,255,.22), transparent 60%),
      radial-gradient(900px 300px at 90% 0%, rgba(80,205,137,.20), transparent 55%),
      rgba(255,255,255,.02);
  }
  .ep-stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,.06); }
  [data-bs-theme="dark"] .ep-stat-card:hover { box-shadow: 0 10px 25px rgba(0,0,0,.35); }
  .ep-kpi { letter-spacing: -0.02em; }
  .ep-quick-actions .btn { white-space: nowrap; }
  .ep-table td { vertical-align: middle; }
  .ep-table tbody tr:hover { background: rgba(62,151,255,.06); }
  [data-bs-theme="dark"] .ep-table tbody tr:hover { background: rgba(62,151,255,.10); }
  .ep-empty {
    border: 1px dashed var(--bs-border-color);
    background: rgba(0,0,0,.01);
  }
  [data-bs-theme="dark"] .ep-empty { background: rgba(255,255,255,.02); }
</style>

<div class="card ep-hero mb-6">
  <div class="card-body py-6">
    <div class="d-flex flex-wrap flex-stack gap-4">
      <div class="d-flex flex-column">
        <div class="d-flex align-items-center gap-2 mb-1">
          <h1 class="text-gray-900 fw-bold mb-0"><?= $dashTitle ?></h1>
          <span class="badge badge-light-primary"><?= $isSuper ? 'Platform' : 'Estate' ?></span>
        </div>
        <div class="text-gray-600 mb-2"><?= $dashSubtitle ?></div>
        <div class="d-flex flex-wrap gap-2 text-gray-600 fs-7">
          <span class="d-inline-flex align-items-center gap-1">
            <i class="ki-duotone ki-calendar fs-6"><span class="path1"></span><span class="path2"></span></i>
            <?= e($dashDate) ?>
          </span>
          <span class="d-inline-flex align-items-center gap-1">
            <i class="ki-duotone ki-time fs-6"><span class="path1"></span><span class="path2"></span></i>
            <?= e($dashTime) ?>
          </span>
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 ep-quick-actions">
        <a class="btn btn-sm btn-light-primary" href="maintenance.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
          <i class="ki-duotone ki-setting-2 fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
          Maintenance
        </a>
        <a class="btn btn-sm btn-light" href="maintenance_reports.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
          <i class="ki-duotone ki-chart-simple fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
          Maintenance Reports
        </a>
        <a class="btn btn-sm btn-light" href="invoices.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
          <i class="ki-duotone ki-bill fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
          Invoices
        </a>
        <a class="btn btn-sm btn-light" href="tenants.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
          <i class="ki-duotone ki-profile-user fs-5 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
          Tenants
        </a>
      </div>
    </div>
  </div>
</div>

<?php if (!$isSuper): ?>
  <div class="card mb-6">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="fw-bold text-gray-800">Estate:</div>
      <form method="get" action="dashboard.php" class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-light" type="submit">Go</button></noscript>
      </form>
      <div class="ms-auto text-gray-600">
        Estates assigned: <span class="fw-bold"><?= (int)$stats['estates'] ?></span>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-6 mb-2">
  <div class="col-6 col-xl-3">
    <a class="card card-flush ep-stat-card h-100" href="estates.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-gray-600"><?= $isSuper ? 'Estates' : 'Assigned estates' ?></div>
          <div class="fs-2 fw-bold text-gray-900 ep-kpi"><?= $stats['estates'] ?></div>
          <div class="text-gray-600 fs-7 mt-1">Tap to manage</div>
        </div>
        <div class="symbol symbol-45px">
          <div class="symbol-label bg-light-primary">
            <i class="ki-duotone ki-home fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php if ($isSuper): ?>
    <div class="col-6 col-xl-3">
      <a class="card card-flush ep-stat-card h-100" href="users.php">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-gray-600">Users</div>
            <div class="fs-2 fw-bold text-gray-900 ep-kpi"><?= $stats['users'] ?></div>
            <div class="text-gray-600 fs-7 mt-1">Accounts & roles</div>
          </div>
          <div class="symbol symbol-45px">
            <div class="symbol-label bg-light-info">
              <i class="ki-duotone ki-people fs-2 text-info"><span class="path1"></span><span class="path2"></span></i>
            </div>
          </div>
        </div>
      </a>
    </div>
  <?php else: ?>
    <div class="col-6 col-xl-3">
      <a class="card card-flush ep-stat-card h-100" href="tenants.php?estate_id=<?= $estateId ?>">
        <div class="card-body d-flex align-items-center justify-content-between">
          <div>
            <div class="text-gray-600">Tenants</div>
            <div class="fs-2 fw-bold text-gray-900 ep-kpi"><?= (int)$stats['tenants'] ?></div>
            <div class="text-gray-600 fs-7 mt-1">People in units</div>
          </div>
          <div class="symbol symbol-45px">
            <div class="symbol-label bg-light-success">
              <i class="ki-duotone ki-profile-user fs-2 text-success"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
            </div>
          </div>
        </div>
      </a>
    </div>
  <?php endif; ?>
  <div class="col-6 col-xl-3">
    <a class="card card-flush ep-stat-card h-100" href="units.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-gray-600">Units</div>
          <div class="fs-2 fw-bold text-gray-900 ep-kpi"><?= $stats['units'] ?></div>
          <div class="text-gray-600 fs-7 mt-1">Vacant: <span class="fw-semibold"><?= $stats['units_vacant'] ?></span> • Occupied: <span class="fw-semibold"><?= $stats['units_occupied'] ?></span></div>
        </div>
        <div class="symbol symbol-45px">
          <div class="symbol-label bg-light-warning">
            <i class="ki-duotone ki-element-11 fs-2 text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a class="card card-flush ep-stat-card h-100" href="maintenance.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">
      <div class="card-body d-flex align-items-center justify-content-between">
        <div>
          <div class="text-gray-600">Open tickets</div>
          <div class="fs-2 fw-bold text-gray-900 ep-kpi"><?= $stats['tickets_open'] ?></div>
          <div class="text-gray-600 fs-7 mt-1">Needs attention</div>
        </div>
        <div class="symbol symbol-45px">
          <div class="symbol-label bg-light-danger">
            <i class="ki-duotone ki-wrench fs-2 text-danger"><span class="path1"></span><span class="path2"></span></i>
          </div>
        </div>
      </div>
    </a>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Insights</div>
        <div class="card-toolbar">
          <span class="badge badge-light">Live</span>
        </div>
      </div>
      <div class="card-body">
        <div class="d-flex flex-stack mb-3">
          <div class="d-flex align-items-center gap-3">
            <div class="symbol symbol-40px">
              <div class="symbol-label bg-light-success">
                <i class="ki-duotone ki-chart-line fs-2 text-success"><span class="path1"></span><span class="path2"></span></i>
              </div>
            </div>
            <div>
              <div class="text-gray-600">Occupancy</div>
              <div class="fw-bold text-gray-900"><?= $occupancyRate ?>% <span class="text-gray-600 fw-semibold">• Vacant <?= $vacancyRate ?>%</span></div>
            </div>
          </div>
        </div>
        <div class="progress h-6px mb-4">
          <div class="progress-bar <?= e($occupancyBar) ?>" role="progressbar" style="width: <?= $occupancyRate ?>%;" aria-valuenow="<?= $occupancyRate ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Workload</div>
          <div class="fw-bold"><?= e($workloadLabel) ?> <span class="text-gray-600 fw-semibold">• <?= $ticketsOpen ?> open</span></div>
        </div>
        <div class="progress h-6px mb-4">
          <?php
            $workloadPct = min(100, (int)round(($ticketsOpen / 12) * 100));
          ?>
          <div class="progress-bar <?= e($workloadBar) ?>" role="progressbar" style="width: <?= $workloadPct ?>%;" aria-valuenow="<?= $workloadPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <div class="d-flex flex-stack mb-2">
          <div class="text-gray-600">Invoice risk</div>
          <div class="fw-bold"><?= e($invoiceRiskLabel) ?> <span class="text-gray-600 fw-semibold">• <?= $invoicesPending ?> pending</span></div>
        </div>
        <div class="progress h-6px">
          <?php
            $invoicePct = min(100, (int)round(($invoicesPending / 12) * 100));
          ?>
          <div class="progress-bar <?= e($invoiceBar) ?>" role="progressbar" style="width: <?= $invoicePct ?>%;" aria-valuenow="<?= $invoicePct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <div class="separator my-4"></div>
        <div class="d-flex flex-wrap gap-2 ep-quick-actions">
          <a class="btn btn-sm btn-light" href="units.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">View units</a>
          <a class="btn btn-sm btn-light" href="maintenance.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">View tickets</a>
          <a class="btn btn-sm btn-light" href="invoices.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">View invoices</a>
        </div>
      </div>
    </div>

    <?php if ($isSuper): ?>
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title fw-bold">Quick actions</div>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 ep-quick-actions">
            <a class="btn btn-sm btn-light-primary" href="estates.php"><i class="ki-duotone ki-home fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Estates</a>
            <a class="btn btn-sm btn-light" href="users.php"><i class="ki-duotone ki-people fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Users</a>
            <a class="btn btn-sm btn-light" href="maintenance.php"><i class="ki-duotone ki-wrench fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Maintenance</a>
            <a class="btn btn-sm btn-light" href="audit.php"><i class="ki-duotone ki-eye fs-5 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>Audit</a>
            <a class="btn btn-sm btn-light" href="settings.php"><i class="ki-duotone ki-gear fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Settings</a>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title fw-bold">Users by role</div>
        </div>
        <div class="card-body">
          <?php if (!$usersByRole): ?>
            <div class="text-gray-600">No users.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-row-dashed align-middle">
                <thead>
                  <tr class="fw-bold text-gray-600">
                    <th>Role</th>
                    <th class="text-end">Count</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($usersByRole as $r): ?>
                  <tr>
                    <td class="fw-bold text-gray-900"><?= e($r['role']) ?></td>
                    <td class="text-end"><?= (int)$r['c'] ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title fw-bold">Quick actions</div>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 mb-4 ep-quick-actions">
            <a class="btn btn-sm btn-light-primary" href="properties.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-office-bag fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Properties</a>
            <a class="btn btn-sm btn-light" href="units.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-element-11 fs-5 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>Units</a>
            <a class="btn btn-sm btn-light" href="tenants.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-profile-user fs-5 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>Tenants</a>
            <a class="btn btn-sm btn-light" href="leases.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-document fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Leases</a>
            <a class="btn btn-sm btn-light" href="invoices.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-bill fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Invoices</a>
            <a class="btn btn-sm btn-light" href="payments.php?estate_id=<?= $estateId ?>"><i class="ki-duotone ki-credit-cart fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>Payments</a>
          </div>

          <div class="notice d-flex bg-light-primary rounded border-primary border border-dashed p-4">
            <i class="ki-duotone ki-information-5 fs-2 text-primary me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div class="d-flex flex-stack flex-grow-1">
              <div class="fw-semibold">
                <div class="text-gray-900 fw-bold">Tip</div>
                <div class="fs-7 text-gray-700">Use the “Estate” selector above to switch context quickly and keep the numbers accurate.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mt-6">
      <div class="card-header">
        <div class="card-title fw-bold">Financials</div>
      </div>
      <div class="card-body">
        <div class="d-flex flex-stack">
          <div>
            <div class="text-gray-600">Pending/Overdue invoices</div>
            <div class="text-gray-600 fs-7 mt-1">Follow up to keep cashflow healthy</div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge badge-light-warning">Needs review</span>
            <div class="fw-bold fs-4"><?= $stats['invoices_pending'] ?></div>
          </div>
        </div>
        <div class="separator my-4"></div>
      <div class="d-flex flex-column gap-3">
        <div class="d-flex flex-stack">
          <div class="text-gray-600">Pending payment requests</div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge badge-light-primary"><?= $paymentsPending > 0 ? 'Action needed' : 'None' ?></span>
            <div class="fw-bold fs-6"><?= $paymentsPending ?></div>
          </div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-light" href="invoices.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">Invoices</a>
          <a class="btn btn-sm btn-light" href="payments.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">Payments</a>
        </div>
      </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <?php if ($isSuper): ?>
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title fw-bold">Recently created estates</div>
          <div class="card-toolbar">
            <a class="btn btn-sm btn-light" href="estates.php">View all</a>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$recentEstates): ?>
            <div class="ep-empty rounded p-6 text-center">
              <div class="mb-3">
                <i class="ki-duotone ki-home fs-3x text-primary"><span class="path1"></span><span class="path2"></span></i>
              </div>
              <div class="fw-bold text-gray-900 mb-1">No estates yet</div>
              <div class="text-gray-600 mb-4">Create your first estate to start onboarding properties, units and tenants.</div>
              <a class="btn btn-sm btn-light-primary" href="estates.php">Go to estates</a>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-row-dashed align-middle ep-table">
                <thead>
                  <tr class="fw-bold text-gray-600">
                    <th>Estate</th>
                    <th>Status</th>
                    <th class="text-end">Created</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($recentEstates as $eRow): ?>
                  <tr>
                    <td class="fw-bold text-gray-900"><?= e($eRow['name']) ?></td>
                    <td><span class="badge <?= badge_class_for_status((string)$eRow['status']) ?>"><?= e($eRow['status']) ?></span></td>
                    <td class="text-end text-gray-700"><?= e($eRow['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card mb-6">
        <div class="card-header">
          <div class="card-title fw-bold">Recent announcements</div>
          <div class="card-toolbar">
            <a class="btn btn-sm btn-light" href="announcements.php?estate_id=<?= $estateId ?>">View all</a>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$recentAnnouncements): ?>
            <div class="ep-empty rounded p-6 text-center">
              <div class="mb-3">
                <i class="ki-duotone ki-notification-on fs-3x text-primary"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
              </div>
              <div class="fw-bold text-gray-900 mb-1">No announcements yet</div>
              <div class="text-gray-600 mb-4">Keep tenants informed with maintenance updates, reminders and community notices.</div>
              <a class="btn btn-sm btn-light-primary" href="announcements.php?estate_id=<?= $estateId ?>">Create/view announcements</a>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-row-dashed align-middle ep-table">
                <thead>
                  <tr class="fw-bold text-gray-600">
                    <th>Title</th>
                    <th>Status</th>
                    <th class="text-end">Created</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($recentAnnouncements as $a): ?>
                  <tr>
                    <td class="fw-bold text-gray-900"><?= e($a['title']) ?></td>
                    <td><span class="badge <?= badge_class_for_status((string)$a['status']) ?>"><?= e($a['status']) ?></span></td>
                    <td class="text-end text-gray-700"><?= e($a['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mt-6">
      <div class="card-header">
        <div class="card-title fw-bold">Pending payment requests</div>
        <div class="card-toolbar">
          <a class="btn btn-sm btn-light" href="payments.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">View all</a>
        </div>
      </div>
      <div class="card-body">
        <?php if (empty($recentPendingPayments)): ?>
          <div class="ep-empty rounded p-6 text-center">
            <div class="mb-3">
              <i class="ki-duotone ki-credit-cart fs-3x text-primary"><span class="path1"></span><span class="path2"></span></i>
            </div>
            <div class="fw-bold text-gray-900 mb-1">No pending payment requests</div>
            <div class="text-gray-600 mb-2">When tenants submit bank transfer proofs, they will appear here for review.</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle ep-table">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Reference</th>
                  <th>Invoice</th>
                  <th>Tenant</th>
                  <th class="text-end">Amount</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($recentPendingPayments as $p): ?>
                <tr>
                  <td class="fw-bold text-gray-900">
                    <a href="payment_review.php?estate_id=<?= $isSuper ? (int)$p['estate_id'] : $estateId ?>&payment_id=<?= (int)$p['id'] ?>" class="text-gray-900 text-hover-primary">
                      <?= e($p['payment_reference']) ?>
                    </a>
                  </td>
                  <td class="text-gray-700"><?= e($p['invoice_number']) ?></td>
                  <td class="text-gray-700"><?= e(trim((string)(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')))) ?></td>
                  <td class="text-end"><?= number_format((float)$p['amount'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Recent maintenance tickets</div>
        <div class="card-toolbar">
          <a class="btn btn-sm btn-light" href="maintenance.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">View all</a>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$recentTickets): ?>
          <div class="ep-empty rounded p-6 text-center">
            <div class="mb-3">
              <i class="ki-duotone ki-wrench fs-3x text-primary"><span class="path1"></span><span class="path2"></span></i>
            </div>
            <div class="fw-bold text-gray-900 mb-1">No tickets yet</div>
            <div class="text-gray-600 mb-4">When residents report issues, they’ll show up here so your team can track progress.</div>
            <a class="btn btn-sm btn-light-primary" href="maintenance.php<?= $isSuper ? '' : ('?estate_id=' . $estateId) ?>">Go to maintenance</a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle ep-table">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Ticket</th>
                  <?php if ($isSuper): ?><th>Estate</th><?php endif; ?>
                  <th>Status</th>
                  <th>Priority</th>
                  <th class="text-end">Created</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($recentTickets as $t): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($t['ticket_number']) ?> — <?= e($t['title']) ?></td>
                  <?php if ($isSuper): ?><td class="text-gray-700"><?= e($t['estate_name']) ?></td><?php endif; ?>
                  <td><span class="badge <?= badge_class_for_status((string)$t['status']) ?>"><?= e($t['status']) ?></span></td>
                  <td><span class="badge <?= badge_class_for_priority((string)($t['priority'] ?? '')) ?>"><?= e($t['priority'] ?? '—') ?></span></td>
                  <td class="text-end text-gray-700"><?= e($t['created_at']) ?></td>
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

