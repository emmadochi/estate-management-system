<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$vendor = require_artisan();
$db = db();

$pageTitle = 'My Tickets – Artisan Area';
$pageHeading = 'My Tickets';

$vendorId = (int)($vendor['id'] ?? 0);
$statusFilter = (string)(get_param('status', '') ?? '');
$allowedStatus = ['open','assigned','in_progress','resolved','closed','cancelled'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = '';
}

$where = ['mt.vendor_id = ?'];
$params = [$vendorId];
if ($statusFilter !== '') {
    $where[] = 'mt.status = ?';
    $params[] = $statusFilter;
}

$tickets = [];
try {
    $tickets = $db->fetchAll(
        "SELECT mt.id, mt.ticket_number, mt.title, mt.status, mt.priority, mt.created_at,
                mt.quoted_cost, mt.quote_status, mt.cost, mt.paid_status,
                un.unit_number, p.name AS property_name
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY mt.created_at DESC
         LIMIT 300",
        $params
    );
} catch (Throwable $e) {
    $tickets = [];
}

require __DIR__ . '/partials/top.php';
?>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="tickets.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($allowedStatus as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title fw-bold">Assigned tickets</div>
  </div>
  <div class="card-body">
    <?php if (!$tickets): ?>
      <div class="text-gray-600">No tickets found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th>Ticket</th>
              <th>Unit</th>
              <th>Status</th>
              <th>Quote</th>
              <th class="text-end">Actual</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td class="fw-bold text-gray-900"><?= e($t['ticket_number']) ?> — <?= e($t['title']) ?></td>
                <td class="text-gray-700"><?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?></td>
                <td><span class="badge badge-light"><?= e($t['status']) ?></span></td>
                <td class="text-gray-700">
                  <?= number_format((float)($t['quoted_cost'] ?? 0), 2) ?>
                  <div class="fs-8 text-gray-600"><?= e($t['quote_status'] ?? 'none') ?></div>
                </td>
                <td class="text-end">
                  <?= number_format((float)($t['cost'] ?? 0), 2) ?>
                  <div class="fs-8 text-gray-600"><?= e($t['paid_status'] ?? 'unpaid') ?></div>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-light-primary me-1" href="ticket_view.php?id=<?= (int)$t['id'] ?>">View</a>
                  <?php if (in_array($t['status'], ['in_progress', 'accepted'])): ?>
                    <a class="btn btn-sm btn-success" href="work_completion.php?ticket_id=<?= (int)$t['id'] ?>">
                      <i class="fas fa-check-circle me-1"></i>Complete
                    </a>
                  <?php elseif ($t['status'] === 'work_completed'): ?>
                    <span class="badge badge-light-success">Completed</span>
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

