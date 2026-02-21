<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin']);

$pageTitle = 'Audit Logs – EstatePro';
$db = db();

$isSuper = is_super_admin();
$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estates = [];
$estateId = 0;

$where = [];
$params = [];

if (!$isSuper) {
    $estates = estates_for_current_user();
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

$logs = [];
$auditError = null;
try {
    $logs = $db->fetchAll(
        "SELECT al.*, u.email, e.name AS estate_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         LEFT JOIN estates e ON e.id = al.estate_id
         " . ($where ? ('WHERE ' . implode(' AND ', $where)) : '') . "
         ORDER BY al.created_at DESC
         LIMIT 300",
        $params
    );
} catch (Throwable $e) {
    // If the DB schema hasn't been migrated (missing audit_logs.estate_id), show guidance.
    $auditError = 'Audit log schema is not migrated yet. Please run the audit migration to add audit_logs.estate_id.';
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Audit Logs</h1>
    <div class="text-gray-600">Recent system actions (scoped by estate access).</div>
  </div>
</div>

<?php if (!$isSuper): ?>
  <div class="card mb-6">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <div class="fw-bold text-gray-800">Estate:</div>
      <form method="get" action="audit.php" class="d-flex align-items-center gap-2">
        <select class="form-select form-select-sm" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm btn-light" type="submit">Go</button></noscript>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <div class="card-title fw-bold">Latest events</div>
  </div>
  <div class="card-body">
    <?php if ($auditError): ?>
      <div class="alert alert-warning" role="alert">
        <?= e($auditError) ?>
      </div>
    <?php endif; ?>
    <?php if (!$logs): ?>
      <div class="text-gray-600">No logs yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th>When</th>
              <th>Estate</th>
              <th>User</th>
              <th>Action</th>
              <th>Model</th>
              <th class="text-end">Model ID</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
            <tr>
              <td class="text-gray-700"><?= e($l['created_at']) ?></td>
              <td class="text-gray-700"><?= e($l['estate_name'] ?? '') ?></td>
              <td class="text-gray-700"><?= e($l['email'] ?? 'System') ?></td>
              <td class="fw-bold text-gray-900"><?= e($l['action']) ?></td>
              <td class="text-gray-700"><?= e($l['model']) ?></td>
              <td class="text-end text-gray-700"><?= e($l['model_id'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>

