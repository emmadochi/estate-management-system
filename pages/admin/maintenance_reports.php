<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/maintenance_notifications.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Maintenance Reports – EstatePro';
$db = db();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
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

$status = (string)(get_param('status', '') ?? '');
$quoteStatus = (string)(get_param('quote_status', '') ?? '');
$paidStatus = (string)(get_param('paid_status', '') ?? '');
$vendorId = (int)(get_param('vendor_id', 0) ?? 0);
$from = (string)(get_param('from', '') ?? '');
$to = (string)(get_param('to', '') ?? '');
$priority = (string)(get_param('priority', '') ?? '');

$allowedStatus = ['open','assigned','in_progress','resolved','closed','cancelled'];
$allowedQuote = ['none','submitted','approved','rejected'];
$allowedPaid = ['unpaid','paid'];
$allowedPriority = ['low','medium','high','urgent'];

if ($status !== '' && !in_array($status, $allowedStatus, true)) $status = '';
if ($quoteStatus !== '' && !in_array($quoteStatus, $allowedQuote, true)) $quoteStatus = '';
if ($paidStatus !== '' && !in_array($paidStatus, $allowedPaid, true)) $paidStatus = '';
if ($priority !== '' && !in_array($priority, $allowedPriority, true)) $priority = '';

// Simple date validation (YYYY-MM-DD).
$fromDate = null;
$toDate = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $fromDate = $from;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $toDate = $to;

$vendors = [];
try {
    $vendors = $db->fetchAll(
        "SELECT v.id, v.name, v.specialization, u.email AS artisan_email
         FROM vendors v
         LEFT JOIN users u ON u.id = v.user_id
         WHERE v.estate_id = ? OR v.estate_id IS NULL
         ORDER BY v.name ASC",
        [$estateId]
    );
} catch (Throwable $e) {
    $vendors = [];
}

$where = ['mt.estate_id = ?'];
$params = [$estateId];

if ($status !== '') {
    $where[] = 'mt.status = ?';
    $params[] = $status;
}
if ($quoteStatus !== '') {
    $where[] = 'mt.quote_status = ?';
    $params[] = $quoteStatus;
}
if ($paidStatus !== '') {
    $where[] = 'mt.paid_status = ?';
    $params[] = $paidStatus;
}
if ($vendorId > 0) {
    $where[] = 'mt.vendor_id = ?';
    $params[] = $vendorId;
}
if ($priority !== '') {
    $where[] = 'mt.priority = ?';
    $params[] = $priority;
}
if ($fromDate) {
    $where[] = 'DATE(mt.created_at) >= ?';
    $params[] = $fromDate;
}
if ($toDate) {
    $where[] = 'DATE(mt.created_at) <= ?';
    $params[] = $toDate;
}

$kpis = [
    'tickets' => 0,
    'total_quoted' => 0.0,
    'total_actual' => 0.0,
    'total_paid' => 0.0,
    'avg_resolution_hours' => null,
    'open_tickets' => 0,
    'urgent_tickets' => 0,
];

try {
    $row = $db->fetchOne(
        "SELECT
            COUNT(*) AS tickets,
            COUNT(CASE WHEN status = 'open' THEN 1 END) AS open_tickets,
            COUNT(CASE WHEN priority = 'urgent' THEN 1 END) AS urgent_tickets,
            COALESCE(SUM(mt.quoted_cost), 0) AS total_quoted,
            COALESCE(SUM(mt.cost), 0) AS total_actual,
            COALESCE(SUM(CASE WHEN mt.paid_status = 'paid' THEN mt.cost ELSE 0 END), 0) AS total_paid,
            AVG(CASE WHEN mt.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, mt.created_at, mt.resolved_at) ELSE NULL END) AS avg_res_hours
         FROM maintenance_tickets mt
         WHERE " . implode(' AND ', $where),
        $params
    );
    if ($row) {
        $kpis['tickets'] = (int)($row['tickets'] ?? 0);
        $kpis['open_tickets'] = (int)($row['open_tickets'] ?? 0);
        $kpis['urgent_tickets'] = (int)($row['urgent_tickets'] ?? 0);
        $kpis['total_quoted'] = (float)($row['total_quoted'] ?? 0);
        $kpis['total_actual'] = (float)($row['total_actual'] ?? 0);
        $kpis['total_paid'] = (float)($row['total_paid'] ?? 0);
        $kpis['avg_resolution_hours'] = $row['avg_res_hours'] !== null ? (float)$row['avg_res_hours'] : null;
    }
} catch (Throwable $e) {
    // ignore
}

// Handle assignment actions
$method = request_method();
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    
    if ($action === 'assign_vendor') {
        $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
        $vendorId = (int)(post_param('vendor_id', 0) ?? 0);
        
        if ($ticketId > 0 && $vendorId > 0) {
            try {
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET vendor_id = ?, status = 'assigned'
                     WHERE id = ? AND estate_id = ?",
                    [$vendorId, $ticketId, $estateId]
                );
                
                // Send notification to artisan
                notify_artisan_maintenance(
                    $ticketId,
                    'assignment',
                    'New Maintenance Assignment',
                    'You have been assigned a new maintenance ticket.'
                );
                
                flash_set('success', 'Ticket assigned to artisan successfully.');
            } catch (Throwable $e) {
                flash_set('error', 'Failed to assign ticket: ' . $e->getMessage());
            }
        }
        redirect('maintenance_reports.php?estate_id=' . $estateId);
    }
    
    if ($action === 'bulk_assign') {
        $ticketIds = post_param('ticket_ids', []);
        $vendorId = (int)(post_param('bulk_vendor_id', 0) ?? 0);
        
        if (is_array($ticketIds) && !empty($ticketIds) && $vendorId > 0) {
            $assignedCount = 0;
            foreach ($ticketIds as $ticketId) {
                $ticketId = (int)$ticketId;
                if ($ticketId > 0) {
                    try {
                        $db->execute(
                            "UPDATE maintenance_tickets 
                             SET vendor_id = ?, status = 'assigned'
                             WHERE id = ? AND estate_id = ?",
                            [$vendorId, $ticketId, $estateId]
                        );
                        $assignedCount++;
                        
                        // Send notification to artisan
                        notify_artisan_maintenance(
                            $ticketId,
                            'assignment',
                            'New Maintenance Assignment',
                            'You have been assigned a new maintenance ticket.'
                        );
                    } catch (Throwable $e) {
                        // Continue with other assignments
                    }
                }
            }
            flash_set('success', "Successfully assigned {$assignedCount} tickets to artisan.");
        }
        redirect('maintenance_reports.php?estate_id=' . $estateId);
    }
}

$tickets = [];
try {
    $tickets = $db->fetchAll(
        "SELECT
            mt.id, mt.ticket_number, mt.title, mt.status, mt.priority,
            mt.quoted_cost, mt.quote_status, mt.cost, mt.paid_status,
            mt.created_at, mt.resolved_at, mt.expected_completion_date,
            un.unit_number, p.name AS property_name,
            v.name AS vendor_name, v.id AS vendor_id,
            u.email AS artisan_email,
            t.first_name AS tenant_first, t.last_name AS tenant_last,
            -- Progress tracking
            CASE 
                WHEN mt.status = 'open' THEN 10
                WHEN mt.status = 'assigned' THEN 25
                WHEN mt.status = 'accepted' THEN 35
                WHEN mt.status = 'in_progress' THEN 50
                WHEN mt.status = 'work_completed' THEN 75
                WHEN mt.status = 'tenant_review' THEN 80
                WHEN mt.status = 'admin_review' THEN 85
                WHEN mt.status = 'payment_pending' THEN 90
                WHEN mt.status = 'paid' THEN 95
                WHEN mt.status = 'closed' THEN 100
                ELSE 0
            END AS progress_percentage,
            -- Days since creation
            DATEDIFF(NOW(), mt.created_at) AS days_since_creation,
            -- Overdue status
            CASE 
                WHEN mt.expected_completion_date IS NOT NULL 
                     AND mt.expected_completion_date < NOW() 
                     AND mt.status NOT IN ('closed', 'cancelled') 
                THEN 1 
                ELSE 0 
            END AS is_overdue
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         INNER JOIN tenants tn ON tn.id = mt.tenant_id
         INNER JOIN users t ON t.id = tn.user_id
         LEFT JOIN vendors v ON v.id = mt.vendor_id
         LEFT JOIN users u ON u.id = v.user_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY 
            CASE mt.priority 
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            mt.created_at DESC
         LIMIT 500",
        $params
    );
} catch (Throwable $e) {
    $tickets = [];
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Reports</h1>
    <div class="text-gray-600">Track work progress and amounts (quoted vs actual) across tickets.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-primary" href="maintenance.php?estate_id=<?= (int)$estateId ?>">
      <i class="fas fa-plus me-2"></i>Create Ticket
    </a>
    <a class="btn btn-light" href="maintenance_progress_dashboard.php?estate_id=<?= (int)$estateId ?>">
      <i class="fas fa-tasks me-2"></i>Progress Dashboard
    </a>
    <a class="btn btn-success" href="maintenance_work_completion_review.php?estate_id=<?= (int)$estateId ?>">
      <i class="fas fa-clipboard-check me-2"></i>Work Completion
    </a>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-6 mb-6">
  <?php
  // Get summary statistics
  $summary = [
      'total_tickets' => 0,
      'open_tickets' => 0,
      'in_progress' => 0,
      'completed' => 0,
      'overdue' => 0,
      'total_quoted' => 0.0,
      'total_actual' => 0.0
  ];
  
  try {
      $summaryRow = $db->fetchOne(
          "SELECT 
              COUNT(*) as total_tickets,
              SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_tickets,
              SUM(CASE WHEN status IN ('in_progress', 'accepted') THEN 1 ELSE 0 END) as in_progress,
              SUM(CASE WHEN status IN ('closed', 'paid') THEN 1 ELSE 0 END) as completed,
              SUM(CASE WHEN expected_completion_date IS NOT NULL AND expected_completion_date < NOW() AND status NOT IN ('closed', 'cancelled') THEN 1 ELSE 0 END) as overdue,
              COALESCE(SUM(quoted_cost), 0) as total_quoted,
              COALESCE(SUM(cost), 0) as total_actual
           FROM maintenance_tickets mt
           WHERE mt.estate_id = ?",
          [$estateId]
      );
      
      if ($summaryRow) {
          $summary = array_map('floatval', $summaryRow);
      }
  } catch (Throwable $e) {
      // Ignore errors
  }
  ?>
  
  <div class="col-12 col-md-3">
    <div class="card bg-light-primary">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="symbol symbol-40px symbol-circle bg-primary me-3">
            <i class="fas fa-ticket-alt text-white"></i>
          </div>
          <div>
            <div class="text-gray-700 fs-7">Total Tickets</div>
            <div class="fs-2 fw-bold text-primary"><?= (int)$summary['total_tickets'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-12 col-md-3">
    <div class="card bg-light-warning">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="symbol symbol-40px symbol-circle bg-warning me-3">
            <i class="fas fa-tools text-white"></i>
          </div>
          <div>
            <div class="text-gray-700 fs-7">In Progress</div>
            <div class="fs-2 fw-bold text-warning"><?= (int)$summary['in_progress'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-12 col-md-3">
    <div class="card bg-light-success">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="symbol symbol-40px symbol-circle bg-success me-3">
            <i class="fas fa-check-circle text-white"></i>
          </div>
          <div>
            <div class="text-gray-700 fs-7">Completed</div>
            <div class="fs-2 fw-bold text-success"><?= (int)$summary['completed'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-12 col-md-3">
    <div class="card bg-light-danger">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="symbol symbol-40px symbol-circle bg-danger me-3">
            <i class="fas fa-exclamation-triangle text-white"></i>
          </div>
          <div>
            <div class="text-gray-700 fs-7">Overdue</div>
            <div class="fs-2 fw-bold text-danger"><?= (int)$summary['overdue'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="maintenance_reports.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Estate</label>
        <select class="form-select" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Vendor / Artisan</label>
        <select class="form-select" name="vendor_id">
          <option value="0">All</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?= (int)$v['id'] ?>" <?= (int)$vendorId === (int)$v['id'] ? 'selected' : '' ?>>
              <?= e($v['name']) ?><?= !empty($v['artisan_email']) ? (' — ' . e($v['artisan_email'])) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($allowedStatus as $s): ?>
            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">Priority</label>
        <select class="form-select" name="priority">
          <option value="" <?= $priority === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($allowedPriority as $p): ?>
            <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Quote status</label>
        <select class="form-select" name="quote_status">
          <option value="" <?= $quoteStatus === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($allowedQuote as $q): ?>
            <option value="<?= e($q) ?>" <?= $quoteStatus === $q ? 'selected' : '' ?>><?= e($q) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label">Paid status</label>
        <select class="form-select" name="paid_status">
          <option value="" <?= $paidStatus === '' ? 'selected' : '' ?>>All</option>
          <?php foreach ($allowedPaid as $p): ?>
            <option value="<?= e($p) ?>" <?= $paidStatus === $p ? 'selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">From</label>
        <input class="form-control" type="date" name="from" value="<?= e($fromDate ?? '') ?>">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">To</label>
        <input class="form-control" type="date" name="to" value="<?= e($toDate ?? '') ?>">
      </div>

      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-primary" type="submit">Apply</button>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <a class="btn btn-light" href="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-6 mb-6">
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-primary">
      <div class="card-body">
        <div class="text-gray-600">Total Tickets</div>
        <div class="fs-2 fw-bold text-primary"><?= (int)$kpis['tickets'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-warning">
      <div class="card-body">
        <div class="text-gray-600">Open Tickets</div>
        <div class="fs-2 fw-bold text-warning"><?= (int)$kpis['open_tickets'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-danger">
      <div class="card-body">
        <div class="text-gray-600">Urgent Tickets</div>
        <div class="fs-2 fw-bold text-danger"><?= (int)$kpis['urgent_tickets'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-info">
      <div class="card-body">
        <div class="text-gray-600">Total quoted</div>
        <div class="fs-2 fw-bold text-info"><?= number_format((float)$kpis['total_quoted'], 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-success">
      <div class="card-body">
        <div class="text-gray-600">Total actual</div>
        <div class="fs-2 fw-bold text-success"><?= number_format((float)$kpis['total_actual'], 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-2">
    <div class="card h-100 border-start border-4 border-secondary">
      <div class="card-body">
        <div class="text-gray-600">Total paid</div>
        <div class="fs-2 fw-bold text-secondary"><?= number_format((float)$kpis['total_paid'], 2) ?></div>
        <div class="text-gray-600 fs-8 mt-1">
          Avg: <?= $kpis['avg_resolution_hours'] === null ? '—' : e((string)round((float)$kpis['avg_resolution_hours'], 1) . 'h') ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title fw-bold">Tickets</div>
    <div class="card-toolbar">
      <?php if (!empty($tickets)): ?>
        <button class="btn btn-sm btn-light-primary me-2" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
          <i class="fas fa-users me-1"></i>Bulk Assign
        </button>
      <?php endif; ?>
      <a class="btn btn-sm btn-light" href="maintenance.php?estate_id=<?= (int)$estateId ?>">Go to Maintenance</a>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$tickets): ?>
      <div class="text-gray-600">No tickets match your filters.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th width="30">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="select-all">
                </div>
              </th>
              <th>Ticket</th>
              <th>Tenant</th>
              <th>Unit</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Progress</th>
              <th>Vendor/Artisan</th>
              <th class="text-end">Quoted</th>
              <th class="text-end">Actual</th>
              <th class="text-end">Paid</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td>
                  <div class="form-check">
                    <input class="form-check-input ticket-checkbox" type="checkbox" name="ticket_ids[]" value="<?= (int)$t['id'] ?>">
                  </div>
                </td>
                <td class="fw-bold text-gray-900">
                  <?= e($t['ticket_number']) ?> — <?= e($t['title']) ?>
                  <div class="fs-8 text-gray-600 mt-1">
                    <?= date('M j, Y', strtotime($t['created_at'])) ?>
                  </div>
                </td>
                <td class="text-gray-700">
                  <?= e($t['tenant_first'] . ' ' . $t['tenant_last']) ?>
                </td>
                <td class="text-gray-700">
                  <?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?>
                </td>
                <td>
                  <?php 
                  $priorityClass = [
                      'urgent' => 'badge-danger',
                      'high' => 'badge-warning',
                      'medium' => 'badge-primary',
                      'low' => 'badge-light'
                  ][$t['priority']] ?? 'badge-light';
                  ?>
                  <span class="badge <?= $priorityClass ?>"><?= e($t['priority']) ?></span>
                </td>
                <td>
                  <?php 
                  $statusClass = [
                      'open' => 'badge-light',
                      'assigned' => 'badge-info',
                      'in_progress' => 'badge-primary',
                      'work_completed' => 'badge-warning',
                      'tenant_review' => 'badge-warning',
                      'admin_review' => 'badge-warning',
                      'payment_pending' => 'badge-info',
                      'paid' => 'badge-success',
                      'resolved' => 'badge-success',
                      'closed' => 'badge-dark',
                      'cancelled' => 'badge-danger'
                  ][$t['status']] ?? 'badge-light';
                  ?>
                  <span class="badge <?= $statusClass ?>"><?= e($t['status']) ?></span>
                  <?php if ($t['is_overdue']): ?>
                    <span class="badge badge-danger ms-1">Overdue</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="d-flex align-items-center">
                    <div class="flex-grow-1 me-2">
                      <div class="progress h-8px" style="min-width: 100px;">
                        <div class="progress-bar bg-<?= $t['progress_percentage'] >= 75 ? 'success' : ($t['progress_percentage'] >= 50 ? 'warning' : 'primary') ?>" 
                             role="progressbar" 
                             style="width: <?= (int)$t['progress_percentage'] ?>%" 
                             aria-valuenow="<?= (int)$t['progress_percentage'] ?>" 
                             aria-valuemin="0" 
                             aria-valuemax="100"></div>
                      </div>
                    </div>
                    <span class="fs-8 text-gray-700 fw-bold"><?= (int)$t['progress_percentage'] ?>%</span>
                  </div>
                  <?php if ($t['expected_completion_date']): ?>
                    <div class="fs-8 text-gray-600 mt-1">
                      Due: <?= date('M j', strtotime($t['expected_completion_date'])) ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($t['days_since_creation'] > 7): ?>
                    <div class="fs-8 text-warning mt-1">
                      <?= (int)$t['days_since_creation'] ?> days old
                    </div>
                  <?php endif; ?>
                </td>
                <td class="text-gray-700">
                  <?php if (!empty($t['vendor_name'])): ?>
                    <div><?= e($t['vendor_name']) ?></div>
                    <?php if (!empty($t['artisan_email'])): ?>
                      <div class="fs-8 text-gray-600"><?= e($t['artisan_email']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge badge-light">Unassigned</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?= number_format((float)($t['quoted_cost'] ?? 0), 2) ?>
                  <div class="fs-8 text-gray-600"><?= e($t['quote_status'] ?? 'none') ?></div>
                </td>
                <td class="text-end"><?= number_format((float)($t['cost'] ?? 0), 2) ?></td>
                <td class="text-end">
                  <span class="badge badge-light"><?= e($t['paid_status'] ?? 'unpaid') ?></span>
                </td>
                <td class="text-end">
                  <?php if (empty($t['vendor_id'])): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal" 
                            data-ticket-id="<?= (int)$t['id'] ?>" data-ticket-title="<?= e($t['ticket_number'] . ' - ' . $t['title']) ?>">
                      <i class="fas fa-user-plus me-1"></i>Assign
                    </button>
                  <?php else: ?>
                    <a class="btn btn-sm btn-light" href="maintenance_ticket_review.php?estate_id=<?= (int)$estateId ?>&ticket_id=<?= (int)$t['id'] ?>">
                      <i class="fas fa-eye me-1"></i>Review
                    </a>
                  <?php endif; ?>
                  
                  <?php if (in_array($t['status'], ['work_completed', 'tenant_review', 'admin_review'])): ?>
                    <a class="btn btn-sm btn-success ms-1" href="maintenance_work_completion_review.php?estate_id=<?= (int)$estateId ?>&ticket_id=<?= (int)$t['id'] ?>">
                      <i class="fas fa-clipboard-check me-1"></i>Confirm
                    </a>
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

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_vendor">
        <input type="hidden" name="ticket_id" id="assign_ticket_id">
        <div class="modal-header">
          <h5 class="modal-title">Assign Ticket to Artisan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Ticket</label>
            <input type="text" class="form-control" id="assign_ticket_title" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label required">Select Artisan/Vendor</label>
            <select class="form-select" name="vendor_id" required>
              <option value="">Choose artisan...</option>
              <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= e($v['name']) ?><?= !empty($v['artisan_email']) ? (' — ' . e($v['artisan_email'])) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Assigning this ticket will:
            <ul class="mb-0 mt-2">
              <li>Set status to "assigned"</li>
              <li>Notify the selected artisan</li>
              <li>Make the ticket visible in their dashboard</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Assign Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bulk Assign Modal -->
<div class="modal fade" id="bulkAssignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_assign">
        <div class="modal-header">
          <h5 class="modal-title">Bulk Assign Tickets</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label required">Select Artisan/Vendor</label>
            <select class="form-select" name="bulk_vendor_id" required>
              <option value="">Choose artisan...</option>
              <?php foreach ($vendors as $v): ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= e($v['name']) ?><?= !empty($v['artisan_email']) ? (' — ' . e($v['artisan_email'])) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            This will assign all selected tickets to the chosen artisan and send notifications to them.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Bulk Assign</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Assign modal
    const assignModal = document.getElementById('assignModal');
    assignModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const ticketId = button.getAttribute('data-ticket-id');
        const ticketTitle = button.getAttribute('data-ticket-title');
        
        document.getElementById('assign_ticket_id').value = ticketId;
        document.getElementById('assign_ticket_title').value = ticketTitle;
    });
    
    // Select all checkboxes
    document.getElementById('select-all').addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.ticket-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    
    // Bulk assign validation
    const bulkAssignForm = document.querySelector('#bulkAssignModal form');
    if (bulkAssignForm) {
        bulkAssignForm.addEventListener('submit', function(e) {
            const selectedTickets = document.querySelectorAll('.ticket-checkbox:checked');
            if (selectedTickets.length === 0) {
                e.preventDefault();
                alert('Please select at least one ticket to assign.');
                return false;
            }
            
            const vendorSelect = document.querySelector('[name="bulk_vendor_id"]');
            if (!vendorSelect.value) {
                e.preventDefault();
                alert('Please select an artisan/vendor.');
                return false;
            }
            
            if (!confirm(`Assign ${selectedTickets.length} tickets to the selected artisan?`)) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>
