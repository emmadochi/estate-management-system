<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin']);

$pageTitle = 'Subscription Monitoring – EstatePro';
$db = db();
$isSuper = is_super_admin();

$method = request_method();

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    
    if ($action === 'change_status' && $isSuper) {
        $subscriptionId = (int)(post_param('subscription_id', 0) ?? 0);
        $newStatus = (string)post_param('status', '');
        $notes = trim((string)post_param('notes', ''));
        
        if (in_array($newStatus, ['active', 'suspended', 'cancelled', 'expired'])) {
            try {
                $db->execute(
                    'UPDATE estate_subscriptions SET status = ?, notes = NULLIF(?, "") WHERE id = ?',
                    [$newStatus, $notes, $subscriptionId]
                );
                flash_set('success', 'Subscription status updated to ' . $newStatus);
            } catch (Throwable $e) {
                flash_set('error', 'Failed to update subscription status.');
            }
        }
        redirect('subscription_monitoring.php');
    }
}

// Get subscription statistics
$stats = [
    'total_estates' => (int)($db->fetchOne('SELECT COUNT(*) AS c FROM estates')['c'] ?? 0),
    'active_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'active'")['c'] ?? 0),
    'pending_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'pending'")['c'] ?? 0),
    'suspended_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'suspended'")['c'] ?? 0),
    'cancelled_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'cancelled'")['c'] ?? 0),
    'expiring_soon' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'active' AND next_billing_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)")['c'] ?? 0),
    'overdue_subscriptions' => (int)($db->fetchOne("SELECT COUNT(*) AS c FROM estate_subscriptions WHERE status = 'active' AND next_billing_date < CURDATE()")['c'] ?? 0),
    'monthly_revenue' => (float)($db->fetchOne("SELECT SUM(amount) AS total FROM estate_subscriptions WHERE status = 'active' AND billing_cycle = 'monthly'")['total'] ?? 0),
    'annual_revenue' => (float)($db->fetchOne("SELECT SUM(amount) AS total FROM estate_subscriptions WHERE status = 'active' AND billing_cycle = 'annual'")['total'] ?? 0),
];

// Get all subscriptions with estate and plan details
$subscriptions = $db->fetchAll(
    "SELECT 
        es.id,
        es.subscription_number,
        es.status,
        es.start_date,
        es.end_date,
        es.next_billing_date,
        es.amount,
        es.billing_cycle,
        es.auto_renew,
        es.created_at,
        e.id as estate_id,
        e.name as estate_name,
        e.total_units,
        e.occupied_units,
        sp.name as plan_name,
        sp.code as plan_code,
        sp.max_units,
        sp.max_users,
        u.first_name as created_by_first,
        u.last_name as created_by_last,
        (SELECT COUNT(*) FROM subscription_payments spay WHERE spay.subscription_id = es.id AND spay.status = 'completed') as payment_count,
        (SELECT SUM(amount) FROM subscription_payments spay WHERE spay.subscription_id = es.id AND spay.status = 'completed') as total_paid
     FROM estate_subscriptions es
     JOIN estates e ON e.id = es.estate_id
     JOIN subscription_plans sp ON sp.id = es.plan_id
     LEFT JOIN users u ON u.id = es.created_by
     ORDER BY es.created_at DESC
     LIMIT 100"
);

// Get subscription alerts
$alerts = $db->fetchAll(
    "SELECT 
        sa.id,
        sa.alert_type,
        sa.message,
        sa.status,
        sa.severity,
        sa.trigger_date,
        sa.created_at,
        e.name as estate_name,
        e.id as estate_id
     FROM subscription_alerts sa
     JOIN estates e ON e.id = sa.estate_id
     WHERE sa.status = 'pending'
     ORDER BY sa.created_at DESC
     LIMIT 20"
);

// Get revenue by plan
$revenueByPlan = $db->fetchAll(
    "SELECT 
        sp.name as plan_name,
        sp.code as plan_code,
        COUNT(es.id) as subscription_count,
        SUM(es.amount) as monthly_revenue,
        (SELECT COUNT(*) FROM estate_subscriptions es2 WHERE es2.plan_id = sp.id AND es2.status = 'active') as active_count
     FROM subscription_plans sp
     LEFT JOIN estate_subscriptions es ON es.plan_id = sp.id AND es.status = 'active'
     WHERE sp.status = 'active'
     GROUP BY sp.id, sp.name, sp.code
     ORDER BY monthly_revenue DESC"
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Subscription Monitoring</h1>
    <div class="text-gray-600">Track estate subscriptions, revenue, and billing status.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <?php if ($isSuper): ?>
      <a class="btn btn-light-primary" href="subscription_plans.php">
        <i class="ki-duotone ki-setting fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
        Manage Plans
      </a>
    <?php endif; ?>
    <a class="btn btn-light" href="estates.php">
      <i class="ki-duotone ki-home fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Estates
    </a>
  </div>
</div>

<!-- Stats Overview -->
<div class="row g-6 mb-6">
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Total Estates</div>
        <div class="fs-2 fw-bold text-gray-900"><?= (int)$stats['total_estates'] ?></div>
        <div class="mt-2">
          <span class="badge badge-light">Managed</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Active Subscriptions</div>
        <div class="fs-2 fw-bold text-success"><?= (int)$stats['active_subscriptions'] ?></div>
        <div class="mt-2">
          <span class="text-success fs-8">
            <i class="ki-duotone ki-arrow-up fs-7 me-1"><span class="path1"></span><span class="path2"></span></i>
            Revenue Generating
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Monthly Revenue</div>
        <div class="fs-2 fw-bold text-primary">₦<?= number_format((float)$stats['monthly_revenue'], 0) ?></div>
        <div class="mt-2">
          <span class="badge badge-light">Ongoing</span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-gray-600 fs-7">Expiring Soon</div>
        <div class="fs-2 fw-bold text-warning"><?= (int)$stats['expiring_soon'] ?></div>
        <div class="mt-2">
          <span class="badge badge-light-warning">
            <i class="ki-duotone ki-time fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
            Next 7 days
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ((int)$stats['pending_subscriptions'] > 0): ?>
  <div class="notice notice-info mb-6 rounded">
    <i class="ki-duotone ki-information fs-2 text-info me-2"></i>
    <div class="text-gray-700 fs-6">
      You have <strong><?= (int)$stats['pending_subscriptions'] ?></strong> pending subscription requests that need approval.
      <a href="#pending-subscriptions" class="text-primary fw-bold">View pending subscriptions</a>
    </div>
  </div>
<?php endif; ?>

<?php if ((int)$stats['overdue_subscriptions'] > 0): ?>
  <div class="notice notice-danger mb-6 rounded">
    <i class="ki-duotone ki-exclamation fs-2 text-danger me-2"></i>
    <div class="text-gray-700 fs-6">
      <strong><?= (int)$stats['overdue_subscriptions'] ?></strong> subscriptions are overdue for payment.
      <a href="#overdue-subscriptions" class="text-danger fw-bold">View overdue subscriptions</a>
    </div>
  </div>
<?php endif; ?>

<div class="row g-6">
  <!-- Active Subscriptions -->
  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Active Subscriptions</div>
        <div class="card-toolbar">
          <span class="badge badge-light-success"><?= (int)$stats['active_subscriptions'] ?> active</span>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$subscriptions): ?>
          <div class="text-gray-600 text-center py-8">
            <i class="ki-duotone ki-home fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
            <div>No subscriptions found. Create estates and assign subscription plans.</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Estate</th>
                  <th>Plan</th>
                  <th>Status</th>
                  <th>Amount</th>
                  <th>Next Billing</th>
                  <th>Auto-Renew</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($subscriptions as $sub): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="symbol symbol-40px me-3">
                        <div class="symbol-label bg-light-primary">
                          <i class="ki-duotone ki-home fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                      </div>
                      <div class="d-flex flex-column">
                        <a href="estates.php?edit_id=<?= (int)$sub['estate_id'] ?>" class="text-gray-900 fw-bold text-hover-primary">
                          <?= e($sub['estate_name']) ?>
                        </a>
                        <div class="text-gray-600 fs-7">
                          <?= (int)$sub['occupied_units'] ?>/<?= (int)$sub['total_units'] ?> units occupied
                        </div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="fw-bold text-gray-900"><?= e($sub['plan_name']) ?></div>
                    <div class="text-gray-600 fs-7"><?= e($sub['plan_code']) ?></div>
                  </td>
                  <td>
                    <?php
                    $statusClass = match($sub['status']) {
                        'active' => 'badge-light-success',
                        'pending' => 'badge-light-warning',
                        'suspended' => 'badge-light-danger',
                        'cancelled' => 'badge-light-secondary',
                        'expired' => 'badge-light-dark',
                        default => 'badge-light'
                    };
                    ?>
                    <span class="badge <?= $statusClass ?>"><?= e($sub['status']) ?></span>
                  </td>
                  <td>
                    <div class="fw-bold">₦<?= number_format((float)$sub['amount'], 2) ?></div>
                    <div class="text-gray-600 fs-7"><?= e($sub['billing_cycle']) ?></div>
                  </td>
                  <td>
                    <?php if ($sub['next_billing_date']): ?>
                      <div class="fw-bold <?= strtotime($sub['next_billing_date']) < time() ? 'text-danger' : 'text-gray-900' ?>">
                        <?= date('M j, Y', strtotime($sub['next_billing_date'])) ?>
                      </div>
                      <?php if (strtotime($sub['next_billing_date']) < time()): ?>
                        <div class="text-danger fs-7">Overdue</div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-light-<?= $sub['auto_renew'] ? 'success' : 'secondary' ?>">
                      <?= $sub['auto_renew'] ? 'Yes' : 'No' ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light" href="estates.php?edit_id=<?= (int)$sub['estate_id'] ?>">View Estate</a>
                      <?php if ($isSuper): ?>
                        <div class="dropdown">
                          <button class="btn btn-sm btn-light-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Actions
                          </button>
                          <div class="dropdown-menu">
                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#statusModal<?= (int)$sub['id'] ?>">
                              Change Status
                            </a>
                            <a class="dropdown-item" href="subscription_payments.php?subscription_id=<?= (int)$sub['id'] ?>">
                              View Payments
                            </a>
                          </div>
                        </div>
                        
                        <!-- Status Change Modal -->
                        <div class="modal fade" id="statusModal<?= (int)$sub['id'] ?>" tabindex="-1">
                          <div class="modal-dialog">
                            <div class="modal-content">
                              <form method="post" action="subscription_monitoring.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="change_status">
                                <input type="hidden" name="subscription_id" value="<?= (int)$sub['id'] ?>">
                                <div class="modal-header">
                                  <h5 class="modal-title">Change Subscription Status</h5>
                                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                  <div class="mb-4">
                                    <label class="form-label">Current Status</label>
                                    <div class="badge <?= $statusClass ?>"><?= e($sub['status']) ?></div>
                                  </div>
                                  <div class="mb-4">
                                    <label class="form-label required">New Status</label>
                                    <select class="form-select" name="status" required>
                                      <option value="active" <?= $sub['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                      <option value="suspended" <?= $sub['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                      <option value="cancelled" <?= $sub['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                      <option value="expired" <?= $sub['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                                    </select>
                                  </div>
                                  <div class="mb-0">
                                    <label class="form-label">Notes (optional)</label>
                                    <textarea class="form-control" name="notes" rows="3" placeholder="Reason for status change..."></textarea>
                                  </div>
                                </div>
                                <div class="modal-footer">
                                  <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                  <button type="submit" class="btn btn-primary">Update Status</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      <?php endif; ?>
                    </div>
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

  <!-- Sidebar -->
  <div class="col-12 col-xxl-4">
    <!-- Revenue by Plan -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Revenue by Plan</div>
      </div>
      <div class="card-body">
        <?php if (!$revenueByPlan): ?>
          <div class="text-gray-600 text-center py-4">No revenue data</div>
        <?php else: ?>
          <?php foreach ($revenueByPlan as $plan): ?>
            <div class="d-flex align-items-center mb-4">
              <div class="symbol symbol-40px me-3">
                <div class="symbol-label bg-light-primary">
                  <i class="ki-duotone ki-chart-line fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
                </div>
              </div>
              <div class="flex-grow-1">
                <div class="fw-bold text-gray-900"><?= e($plan['plan_name']) ?></div>
                <div class="text-gray-600 fs-7"><?= (int)$plan['active_count'] ?> active</div>
              </div>
              <div class="text-end">
                <div class="fw-bold text-success">₦<?= number_format((float)$plan['monthly_revenue'], 0) ?></div>
                <div class="text-gray-600 fs-7">/month</div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Subscription Alerts -->
    <?php if ($alerts): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title fw-bold">Alerts</div>
          <div class="card-toolbar">
            <span class="badge badge-light-danger"><?= count($alerts) ?></span>
          </div>
        </div>
        <div class="card-body">
          <div class="scroll-y" style="max-height: 300px;">
            <?php foreach ($alerts as $alert): ?>
              <div class="d-flex align-items-start mb-4">
                <div class="me-3 mt-1">
                  <?php
                  $alertIcon = match($alert['severity']) {
                      'danger' => 'ki-duotone ki-exclamation-circle text-danger fs-3',
                      'warning' => 'ki-duotone ki-information text-warning fs-3',
                      default => 'ki-duotone ki-information text-info fs-3'
                  };
                  ?>
                  <i class="<?= $alertIcon ?>"><span class="path1"></span><span class="path2"></span></i>
                </div>
                <div class="flex-grow-1">
                  <div class="fw-bold text-gray-900 mb-1"><?= e($alert['message']) ?></div>
                  <div class="text-gray-600 fs-7 mb-1">
                    <?= e($alert['estate_name']) ?>
                  </div>
                  <div class="text-gray-500 fs-8">
                    <?= date('M j, Y', strtotime($alert['created_at'])) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>