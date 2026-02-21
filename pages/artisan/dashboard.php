<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$vendor = require_artisan();
$db = db();

$pageTitle = 'Dashboard – Artisan Area';
$pageHeading = 'Dashboard';

$vendorId = (int)($vendor['id'] ?? 0);

$counts = [
    'open' => 0,
    'assigned' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'closed' => 0,
    'cancelled' => 0,
];

try {
    $rows = $db->fetchAll(
        "SELECT status, COUNT(*) AS c
         FROM maintenance_tickets
         WHERE vendor_id = ?
         GROUP BY status",
        [$vendorId]
    );
    foreach ($rows as $r) {
        $s = (string)($r['status'] ?? '');
        if (isset($counts[$s])) {
            $counts[$s] = (int)($r['c'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // ignore
}

$totals = [
    'quoted' => 0.0,
    'actual' => 0.0,
    'paid_actual' => 0.0,
];

try {
    $row = $db->fetchOne(
        "SELECT
            COALESCE(SUM(quoted_cost), 0) AS total_quoted,
            COALESCE(SUM(cost), 0) AS total_actual,
            COALESCE(SUM(CASE WHEN paid_status = 'paid' THEN cost ELSE 0 END), 0) AS total_paid_actual
         FROM maintenance_tickets
         WHERE vendor_id = ?",
        [$vendorId]
    );
    if ($row) {
        $totals['quoted'] = (float)($row['total_quoted'] ?? 0);
        $totals['actual'] = (float)($row['total_actual'] ?? 0);
        $totals['paid_actual'] = (float)($row['total_paid_actual'] ?? 0);
    }
} catch (Throwable $e) {
    // ignore
}

$recent = [];
try {
    $recent = $db->fetchAll(
        "SELECT mt.id, mt.ticket_number, mt.title, mt.status, mt.priority, mt.created_at,
                un.unit_number, p.name AS property_name
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         WHERE mt.vendor_id = ?
         ORDER BY mt.created_at DESC
         LIMIT 10",
        [$vendorId]
    );
} catch (Throwable $e) {
    // ignore
}

// Get tickets that need completion
$completionTickets = [];
try {
    $completionTickets = $db->fetchAll(
        "SELECT mt.id, mt.ticket_number, mt.title, mt.status,
                un.unit_number, p.name AS property_name
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         WHERE mt.vendor_id = ? 
         AND mt.status IN ('in_progress', 'accepted')
         ORDER BY mt.created_at ASC
         LIMIT 5",
        [$vendorId]
    );
} catch (Throwable $e) {
    // ignore
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Artisan Dashboard</h1>
    <div class="text-gray-600">Welcome back, <?= e($vendor['name'] ?? '') ?>! Here's your maintenance work summary.</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-light-primary" href="tickets.php">
      <i class="fas fa-tasks me-2"></i>My Tickets
    </a>
    <a class="btn btn-success" href="work_completion.php">
      <i class="fas fa-check-circle me-2"></i>Complete Work
    </a>
    <a class="btn btn-light-success" href="profile.php">
      <i class="fas fa-user me-2"></i>Profile
    </a>
  </div>
</div>

<div class="row g-6 mb-6">
  <div class="col-12 col-xl-4">
    <div class="card h-100 bg-gradient-primary text-white overflow-hidden">
      <div class="card-body position-relative">
        <div class="position-absolute top-0 start-0 opacity-25">
          <i class="fas fa-tools fa-10x"></i>
        </div>
        <div class="position-relative">
          <div class="d-flex align-items-center mb-4">
            <div class="symbol symbol-50px bg-white bg-opacity-25 rounded me-4">
              <i class="fas fa-hard-hat text-white fs-1"></i>
            </div>
            <div>
              <div class="fs-5 text-white opacity-75">Work Summary</div>
              <div class="fs-1 fw-bold"><?= (int)($counts['open'] + $counts['assigned'] + $counts['in_progress']) ?></div>
            </div>
          </div>
          
          <div class="d-flex flex-column gap-3">
            <div class="d-flex justify-content-between">
              <div>Open</div>
              <div class="fw-bold"><?= (int)$counts['open'] ?></div>
            </div>
            <div class="d-flex justify-content-between">
              <div>Assigned</div>
              <div class="fw-bold"><?= (int)$counts['assigned'] ?></div>
            </div>
            <div class="d-flex justify-content-between">
              <div>In Progress</div>
              <div class="fw-bold"><?= (int)$counts['in_progress'] ?></div>
            </div>
            <div class="d-flex justify-content-between">
              <div>Resolved</div>
              <div class="fw-bold"><?= (int)$counts['resolved'] ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="row g-6">
      <div class="col-12 col-md-4">
        <div class="card h-100">
          <div class="card-body text-center p-5">
            <div class="symbol symbol-60px symbol-circle mx-auto mb-4 bg-light-primary">
              <i class="fas fa-file-invoice text-primary fs-1"></i>
            </div>
            <div class="fs-5 text-gray-600 mb-1">Total Quoted</div>
            <div class="fs-2hx fw-bold text-gray-900">₦<?= number_format($totals['quoted'], 2) ?></div>
            <div class="text-gray-500 fs-7 mt-2">Based on assigned tickets</div>
          </div>
        </div>
      </div>
      
      <div class="col-12 col-md-4">
        <div class="card h-100">
          <div class="card-body text-center p-5">
            <div class="symbol symbol-60px symbol-circle mx-auto mb-4 bg-light-success">
              <i class="fas fa-receipt text-success fs-1"></i>
            </div>
            <div class="fs-5 text-gray-600 mb-1">Total Actual</div>
            <div class="fs-2hx fw-bold text-gray-900">₦<?= number_format($totals['actual'], 2) ?></div>
            <div class="text-gray-500 fs-7 mt-2">Actual costs incurred</div>
          </div>
        </div>
      </div>
      
      <div class="col-12 col-md-4">
        <div class="card h-100">
          <div class="card-body text-center p-5">
            <div class="symbol symbol-60px symbol-circle mx-auto mb-4 bg-light-info">
              <i class="fas fa-money-bill-wave text-info fs-1"></i>
            </div>
            <div class="fs-5 text-gray-600 mb-1">Paid Amount</div>
            <div class="fs-2hx fw-bold text-gray-900">₦<?= number_format($totals['paid_actual'], 2) ?></div>
            <div class="text-gray-500 fs-7 mt-2">Amount received</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header border-0 pt-5">
        <div class="card-title fw-bold fs-3">
          <i class="fas fa-list me-2 text-primary"></i>Recent Assigned Tickets
        </div>
        <div class="card-toolbar">
          <a href="tickets.php" class="btn btn-sm btn-light-primary">
            View All
          </a>
        </div>
      </div>
      <div class="card-body py-3">
        <?php if (!$recent): ?>
          <div class="text-center py-10">
            <div class="symbol symbol-100px mx-auto mb-5">
              <i class="fas fa-clipboard-list text-muted fs-1"></i>
            </div>
            <h4 class="text-gray-700">No tickets assigned yet</h4>
            <p class="text-gray-500">You don't have any tickets assigned to you at the moment.</p>
            <a class="btn btn-primary" href="tickets.php">Browse Available Tickets</a>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-4 my-0">
              <thead>
                <tr class="fw-bold text-gray-600 border-bottom border-gray-200">
                  <th class="min-w-200px">Ticket & Title</th>
                  <th class="min-w-150px">Unit</th>
                  <th class="min-w-100px">Priority</th>
                  <th class="min-w-100px">Status</th>
                  <th class="min-w-100px text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $t): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="symbol symbol-40px symbol-circle me-3">
                          <i class="fas fa-ticket-alt text-primary"></i>
                        </div>
                        <div>
                          <div class="fw-bold text-gray-900"><?= e($t['ticket_number']) ?></div>
                          <div class="text-gray-600 fs-7"><?= e($t['title']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="text-gray-700"><?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?></div>
                    </td>
                    <td>
                      <span class="badge badge-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : 'info') ?>"><?= e($t['priority']) ?></span>
                    </td>
                    <td>
                      <span class="badge badge-<?= $t['status'] === 'open' ? 'primary' : ($t['status'] === 'in_progress' ? 'warning' : ($t['status'] === 'resolved' ? 'success' : 'secondary')) ?>"><?= e($t['status']) ?></span>
                    </td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-light-primary" href="ticket_view.php?id=<?= (int)$t['id'] ?>">
                        <i class="fas fa-eye me-1"></i>View
                      </a>
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
  
  <div class="col-12 col-xxl-4">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-chart-pie me-2 text-primary"></i>
          Work Distribution
        </div>
      </div>
      <div class="card-body py-3">
        <div class="d-flex flex-center">
          <div id="work-distribution-chart" style="height: 250px; width: 100%;"></div>
        </div>
      </div>
    </div>
    
    <?php if (!empty($completionTickets)): ?>
    <div class="card border-success border-2 mb-6">
      <div class="card-header bg-success text-white">
        <div class="card-title fw-bold mb-0">
          <i class="fas fa-clipboard-check me-2"></i>
          Work Ready for Completion (<?= count($completionTickets) ?>)
        </div>
      </div>
      <div class="card-body">
        <?php foreach ($completionTickets as $t): ?>
          <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
            <div>
              <div class="fw-bold"><?= e($t['ticket_number']) ?></div>
              <div class="text-muted"><?= e($t['title']) ?></div>
              <div class="small"><?= e($t['property_name']) ?> - <?= e($t['unit_number']) ?></div>
            </div>
            <a href="work_completion.php?ticket_id=<?= (int)$t['id'] ?>" 
               class="btn btn-success btn-sm">
              <i class="fas fa-check-circle me-1"></i>Complete Job
            </a>
          </div>
        <?php endforeach; ?>
        <a href="work_completion.php" class="btn btn-outline-success w-100">
          <i class="fas fa-tasks me-2"></i>View All Completion Tasks
        </a>
      </div>
    </div>
    <?php endif; ?>
    
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-bullhorn me-2 text-success"></i>
          Quick Stats
        </div>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-4">
          <div class="text-gray-600">Pending Approval</div>
          <div class="fw-bold text-warning"><?= (int)($counts['in_progress'] + $counts['resolved']) ?></div>
        </div>
        <div class="d-flex justify-content-between mb-4">
          <div class="text-gray-600">Completed</div>
          <div class="fw-bold text-success"><?= (int)$counts['closed'] ?></div>
        </div>
        <div class="d-flex justify-content-between">
          <div class="text-gray-600">Cancelled</div>
          <div class="fw-bold text-danger"><?= (int)$counts['cancelled'] ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Prepare data for the chart
  const statusCounts = [
    <?= (int)$counts['open'] ?>,
    <?= (int)$counts['assigned'] ?>,
    <?= (int)$counts['in_progress'] ?>,
    <?= (int)$counts['resolved'] ?>,
    <?= (int)$counts['closed'] ?>,
    <?= (int)$counts['cancelled'] ?>
  ];
  
  const statusLabels = [
    'Open', 'Assigned', 'In Progress', 'Resolved', 'Closed', 'Cancelled'
  ];
  
  // Filter out zero values for cleaner chart
  const filteredData = [];
  const filteredLabels = [];
  for (let i = 0; i < statusCounts.length; i++) {
    if (statusCounts[i] > 0) {
      filteredData.push(statusCounts[i]);
      filteredLabels.push(statusLabels[i]);
    }
  }
  
  // Create pie chart
  const ctx = document.getElementById('work-distribution-chart').getContext('2d');
  const workChart = new Chart(ctx, {
    type: 'pie',
    data: {
      labels: filteredLabels,
      datasets: [{
        data: filteredData,
        backgroundColor: [
          '#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1', '#6c757d'
        ],
        borderWidth: 2,
        borderColor: '#ffffff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              const label = context.label || '';
              const value = context.raw || 0;
              const total = context.chart.getDatasetMeta(0).total || 1;
              const percentage = Math.round((value / total) * 100);
              return `${label}: ${value} (${percentage}%)`;
            }
          }
        }
      }
    }
  });
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>