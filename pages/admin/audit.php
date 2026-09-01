<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'accountant']);

$pageTitle = 'Audit Logs & Accountability Trail – EstatePro';
$pageHeading = 'Audit Logs';
$db = db();

$isSuper = is_super_admin();
$estates = estates_for_current_user();
$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$modelFilter = trim((string)(get_param('model', '') ?? ''));
$actionFilter = trim((string)(get_param('action_type', '') ?? ''));
$search = trim((string)(get_param('search', '') ?? ''));

$where = [];
$params = [];

if (!$isSuper) {
    $estateId = normalize_estate_id($requestedEstateId);
    assert_can_access_estate($estateId);
    $where[] = 'al.estate_id = ?';
    $params[] = $estateId;
} else {
    if ($requestedEstateId > 0) {
        $where[] = 'al.estate_id = ?';
        $params[] = $requestedEstateId;
    }
}

if ($modelFilter !== '') {
    $where[] = 'al.model = ?';
    $params[] = $modelFilter;
}

if ($actionFilter !== '') {
    $where[] = 'al.action LIKE ?';
    $params[] = '%' . $actionFilter . '%';
}

if ($search !== '') {
    $where[] = '(al.action LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR al.ip_address LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Handle CSV Export
if (get_param('export', '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'Estate', 'User Name', 'Email', 'Role', 'Action', 'Model', 'Model ID', 'IP Address', 'Changes']);

    $exportLogs = $db->fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, u.role, e.name AS estate_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN estates e ON e.id = al.estate_id
         " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . "
         ORDER BY al.created_at DESC
         LIMIT 2000",
        $params
    ) ?: [];

    foreach ($exportLogs as $l) {
        $userName = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
        fputcsv($out, [
            $l['id'],
            $l['created_at'],
            $l['estate_name'] ?? 'Global',
            $userName ?: 'System',
            $l['email'] ?? 'N/A',
            $l['role'] ?? 'System',
            $l['action'],
            $l['model'],
            $l['model_id'] ?? 'N/A',
            $l['ip_address'] ?? 'N/A',
            $l['changes'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

$logs = [];
$auditError = null;
try {
    $logs = $db->fetchAll(
        "SELECT al.*, u.first_name, u.last_name, u.email, u.role, e.name AS estate_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN estates e ON e.id = al.estate_id
         " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . "
         ORDER BY al.created_at DESC
         LIMIT 250",
        $params
    ) ?: [];
} catch (Throwable $e) {
    $auditError = 'Audit log query notice: ' . $e->getMessage();
}

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Header & Filter Bar -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-shield-search fs-2x text-primary"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Audit Logs & Financial Accountability Trail</h1>
                                <span class="text-muted fs-7">Tamper-evident logs of all financial disbursements, approvals, and system mutations</span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <a href="audit.php?export=csv<?= $requestedEstateId ? '&estate_id=' . $requestedEstateId : '' ?><?= $modelFilter ? '&model=' . urlencode($modelFilter) : '' ?>" class="btn btn-sm btn-light-success">
                                <i class="ki-duotone ki-file-down fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> Export CSV
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card shadow-sm border-0 mb-6">
                <div class="card-body p-4">
                    <form method="get" action="audit.php" class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label class="form-label fs-7 fw-bold">Estate</label>
                            <select name="estate_id" class="form-select form-select-sm">
                                <?php if ($isSuper): ?>
                                    <option value="0">All Estates</option>
                                <?php endif; ?>
                                <?php foreach ($estates as $eRow): ?>
                                    <option value="<?= (int)$eRow['id'] ?>" <?= $requestedEstateId === (int)$eRow['id'] ? 'selected' : '' ?>>
                                        <?= e($eRow['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fs-7 fw-bold">Model / Area</label>
                            <select name="model" class="form-select form-select-sm">
                                <option value="">All Models</option>
                                <option value="expense" <?= $modelFilter === 'expense' ? 'selected' : '' ?>>Expenses & Disbursements</option>
                                <option value="payment" <?= $modelFilter === 'payment' ? 'selected' : '' ?>>Payments & Receipts</option>
                                <option value="invoice" <?= $modelFilter === 'invoice' ? 'selected' : '' ?>>Invoices</option>
                                <option value="budget" <?= $modelFilter === 'budget' ? 'selected' : '' ?>>Budgets</option>
                                <option value="user" <?= $modelFilter === 'user' ? 'selected' : '' ?>>Users & Accounts</option>
                                <option value="lease" <?= $modelFilter === 'lease' ? 'selected' : '' ?>>Tenancies & Leases</option>
                                <option value="gate_pass" <?= $modelFilter === 'gate_pass' ? 'selected' : '' ?>>Gate Passes & Security</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fs-7 fw-bold">Search (Action, User, IP)</label>
                            <input type="text" name="search" class="form-control form-control-sm" placeholder="e.g. approve, 192.168, john@..." value="<?= e($search) ?>">
                        </div>

                        <div class="col-12 col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="ki-duotone ki-filter fs-5 me-1"></i> Filter
                            </button>
                            <a href="audit.php" class="btn btn-sm btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Audit Trail Table -->
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <?php if ($auditError): ?>
                        <div class="alert alert-warning m-5"><?= e($auditError) ?></div>
                    <?php endif; ?>

                    <?php if (empty($logs)): ?>
                        <div class="text-center py-10">
                            <p class="text-muted mb-0">No audit records match the selected filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-row-bordered table-row-gray-200 align-middle gs-6 gy-4 mb-0">
                                <thead class="bg-light">
                                    <tr class="text-muted fw-bold fs-7 text-uppercase gs-0">
                                        <th>Timestamp</th>
                                        <th>Estate</th>
                                        <th>Actor / User</th>
                                        <th>Action</th>
                                        <th>Model / Target</th>
                                        <th>IP & Device</th>
                                        <th>Changes / Snapshot</th>
                                    </tr>
                                </thead>
                                <tbody class="fw-semibold text-gray-800 fs-7">
                                    <?php foreach ($logs as $l): ?>
                                        <?php
                                        $uName = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                                        $actionBadge = 'badge-light-primary';
                                        if (str_contains($l['action'], 'delete') || str_contains($l['action'], 'reject')) {
                                            $actionBadge = 'badge-light-danger';
                                        } elseif (str_contains($l['action'], 'approve') || str_contains($l['action'], 'create') || str_contains($l['action'], 'success')) {
                                            $actionBadge = 'badge-light-success';
                                        } elseif (str_contains($l['action'], 'update') || str_contains($l['action'], 'edit')) {
                                            $actionBadge = 'badge-light-warning';
                                        }
                                        ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <div class="fw-bold text-dark"><?= date('M j, Y', strtotime($l['created_at'])) ?></div>
                                                <span class="text-muted fs-8"><?= date('h:i:s A', strtotime($l['created_at'])) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-light"><?= e($l['estate_name'] ?? 'Global System') ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($uName)): ?>
                                                    <div class="fw-bold"><?= e($uName) ?></div>
                                                    <span class="text-muted fs-8"><?= e($l['email'] ?? '') ?> (<?= e(ucfirst((string)($l['role'] ?? ''))) ?>)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-light-dark">System Cron / API</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $actionBadge ?>"><?= e(strtoupper(str_replace('_', ' ', $l['action']))) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= e(ucfirst((string)$l['model'])) ?></strong>
                                                <?php if (!empty($l['model_id'])): ?>
                                                    <span class="text-muted">#<?= (int)$l['model_id'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fs-8 text-muted"><?= e($l['ip_address'] ?? '127.0.0.1') ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($l['changes'])): ?>
                                                    <button type="button" class="btn btn-xs btn-light-info" onclick="viewAuditChanges(<?= (int)$l['id'] ?>, <?= htmlspecialchars(json_encode($l['changes']), ENT_QUOTES, 'UTF-8') ?>)">
                                                        View Diff
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted fs-8">—</span>
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

        </div>
    </div>
</div>

<!-- Modal for Viewing JSON Changes Diff -->
<div class="modal fade" id="auditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="auditModalTitle">Audit Record Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre id="auditModalJson" class="bg-light p-4 rounded text-dark fs-7" style="max-height: 400px; overflow-y: auto;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewAuditChanges(id, rawJson) {
    document.getElementById('auditModalTitle').textContent = 'Audit Record #' + id + ' Changes Snapshot';
    try {
        var parsed = typeof rawJson === 'string' ? JSON.parse(rawJson) : rawJson;
        document.getElementById('auditModalJson').textContent = JSON.stringify(parsed, null, 2);
    } catch(e) {
        document.getElementById('auditModalJson').textContent = rawJson;
    }
    var modal = new bootstrap.Modal(document.getElementById('auditModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
