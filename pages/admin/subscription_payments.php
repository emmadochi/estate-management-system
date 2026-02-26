<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin']);

$pageTitle = 'Subscription Payments – EstatePro';
$db = db();
$method = request_method();

$isSuper = is_super_admin();
$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$subscriptionId = (int)(get_param('subscription_id', 0) ?? 0);
$paymentId = (int)(get_param('edit_id', 0) ?? 0);

// Get estates for access control
$estates = estates_for_current_user();
if (!$estates && !$isSuper) {
    http_response_code(403);
    echo 'No estate access assigned to your account.';
    exit;
}

$estateId = $isSuper ? $requestedEstateId : normalize_estate_id($requestedEstateId);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'record');

    if ($action === 'record') {
        $subscriptionIdPost = (int)(post_param('subscription_id', 0) ?? 0);
        $amount = (float)(post_param('amount', 0) ?? 0);
        $paymentMethod = (string)post_param('payment_method', 'bank_transfer');
        $paymentDate = (string)post_param('payment_date', '');
        $periodStart = (string)post_param('period_start', '');
        $periodEnd = (string)post_param('period_end', '');
        $notes = trim((string)post_param('notes', ''));

        if ($subscriptionIdPost <= 0 || $amount <= 0 || $paymentDate === '' || $periodStart === '' || $periodEnd === '') {
            flash_set('error', 'Subscription, amount, payment date, and period are required.');
            redirect('subscription_payments.php' . ($subscriptionId ? '?subscription_id=' . $subscriptionId : ''));
        }

        try {
            $subscription = $db->fetchOne(
                "SELECT es.id, es.estate_id, es.billing_cycle, es.amount 
                 FROM estate_subscriptions es
                 WHERE es.id = ? AND " . ($isSuper ? '1=1' : 'es.estate_id = ?'),
                $isSuper ? [$subscriptionIdPost] : [$subscriptionIdPost, $estateId]
            );
            
            if (!$subscription) {
                throw new RuntimeException('Subscription not found.');
            }
            
            assert_can_access_estate((int)$subscription['estate_id']);
            $reference = 'SUBPAY-' . date('YmdHis') . '-' . random_int(100, 999);

            $db->execute(
                "INSERT INTO subscription_payments 
                 (subscription_id, payment_reference, amount, payment_method, payment_date, period_start, period_end, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NULLIF(?, ''))",
                [$subscriptionIdPost, $reference, $amount, $paymentMethod, $paymentDate, $periodStart, $periodEnd, $notes]
            );
            
            flash_set('success', 'Subscription payment recorded successfully.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        redirect('subscription_payments.php?subscription_id=' . $subscriptionIdPost);
    }

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $payment = $db->fetchOne(
                "SELECT sp.id, sp.subscription_id, es.estate_id 
                 FROM subscription_payments sp
                 JOIN estate_subscriptions es ON es.id = sp.subscription_id
                 WHERE sp.id = ?",
                [$deleteId]
            );
            
            if ($payment) {
                assert_can_access_estate((int)$payment['estate_id']);
                $db->execute('DELETE FROM subscription_payments WHERE id = ?', [$deleteId]);
                flash_set('success', 'Payment record deleted.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete payment record.');
        }
        redirect('subscription_payments.php' . ($subscriptionId ? '?subscription_id=' . $subscriptionId : ''));
    }
}

$editing = null;
if ($paymentId > 0) {
    $editing = $db->fetchOne(
        "SELECT sp.*, es.estate_id 
         FROM subscription_payments sp
         JOIN estate_subscriptions es ON es.id = sp.subscription_id
         WHERE sp.id = ?",
        [$paymentId]
    );
    if ($editing) {
        assert_can_access_estate((int)$editing['estate_id']);
        $subscriptionId = (int)$editing['subscription_id'];
    }
}

// Get subscriptions for dropdown
$whereClause = '';
$params = [];
if (!$isSuper) {
    $estateIds = array_column($estates, 'id');
    if ($estateIds) {
        $whereClause = 'WHERE es.estate_id IN (' . str_repeat('?,', count($estateIds) - 1) . '?)';
        $params = $estateIds;
    } else {
        $whereClause = 'WHERE 1=0';
    }
} elseif ($estateId > 0) {
    $whereClause = 'WHERE es.estate_id = ?';
    $params = [$estateId];
}

$subscriptions = $db->fetchAll(
    "SELECT 
        es.id,
        es.subscription_number,
        es.status,
        es.billing_cycle,
        es.amount,
        e.name as estate_name,
        e.id as estate_id,
        sp.name as plan_name
     FROM estate_subscriptions es
     JOIN estates e ON e.id = es.estate_id
     JOIN subscription_plans sp ON sp.id = es.plan_id
     $whereClause
     ORDER BY e.name ASC, es.created_at DESC",
    $params
);

// Get payments
$paymentsWhere = '';
$paymentsParams = [];
if ($subscriptionId > 0) {
    $paymentsWhere = 'WHERE sp.subscription_id = ?';
    $paymentsParams = [$subscriptionId];
} elseif (!$isSuper && $estates) {
    $estateIds = array_column($estates, 'id');
    $paymentsWhere = 'WHERE es.estate_id IN (' . str_repeat('?,', count($estateIds) - 1) . '?)';
    $paymentsParams = $estateIds;
} elseif ($isSuper && $estateId > 0) {
    $paymentsWhere = 'WHERE es.estate_id = ?';
    $paymentsParams = [$estateId];
}

$payments = $db->fetchAll(
    "SELECT 
        sp.*,
        es.subscription_number,
        es.billing_cycle,
        es.amount as subscription_amount,
        e.name as estate_name,
        e.id as estate_id,
        sp2.name as plan_name
     FROM subscription_payments sp
     JOIN estate_subscriptions es ON es.id = sp.subscription_id
     JOIN estates e ON e.id = es.estate_id
     JOIN subscription_plans sp2 ON sp2.id = es.plan_id
     $paymentsWhere
     ORDER BY sp.payment_date DESC, sp.created_at DESC
     LIMIT 200",
    $paymentsParams
);

// Calculate totals
$totalPayments = array_sum(array_column($payments, 'amount'));
$completedPayments = array_filter($payments, fn($p) => $p['status'] === 'completed');
$totalCompleted = array_sum(array_column($completedPayments, 'amount'));

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Subscription Payments</h1>
    <div class="text-gray-600">
      <?= $subscriptionId ? 'Manage payments for specific subscription' : 'Track all subscription payments' ?>
    </div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light-primary" href="subscription_monitoring.php">
      <i class="ki-duotone ki-eye fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Monitor
    </a>
    <?php if ($isSuper): ?>
      <a class="btn btn-light" href="estate_subscriptions.php">
        <i class="ki-duotone ki-home fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
        Assignments
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($subscriptionId > 0): ?>
  <?php 
  $currentSubscription = null;
  if ($subscriptions) {
      $currentSubscription = array_filter($subscriptions, fn($s) => (int)$s['id'] === $subscriptionId);
      $currentSubscription = $currentSubscription ? reset($currentSubscription) : null;
  }
  ?>
  <?php if ($currentSubscription): ?>
    <div class="card mb-6">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="symbol symbol-50px me-4">
            <div class="symbol-label bg-light-primary">
              <i class="ki-duotone ki-credit-cart fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
            </div>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex flex-wrap align-items-center">
              <h3 class="text-gray-900 fw-bold mb-1 me-3"><?= e($currentSubscription['estate_name']) ?></h3>
              <span class="badge badge-light-primary me-3"><?= e($currentSubscription['plan_name']) ?></span>
              <span class="badge badge-light-<?= $currentSubscription['status'] === 'active' ? 'success' : 'secondary' ?>">
                <?= e($currentSubscription['status']) ?>
              </span>
            </div>
            <div class="text-gray-600">
              Subscription #<?= e($currentSubscription['subscription_number']) ?> • 
              number_format((float)$currentSubscription['amount'], 2) ?>/<?= e($currentSubscription['billing_cycle']) ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-6">
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Payment' : 'Record Payment' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="subscription_payments.php<?= $subscriptionId ? '?subscription_id=' . $subscriptionId : '' ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editing ? 'update' : 'record' ?>">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-4">
            <label class="form-label required">Subscription</label>
            <select class="form-select" name="subscription_id" required <?= $subscriptionId ? 'disabled' : '' ?>>
              <option value="">Select subscription</option>
              <?php foreach ($subscriptions as $sub): ?>
                <option value="<?= (int)$sub['id'] ?>" 
                        data-cycle="<?= e($sub['billing_cycle']) ?>" 
                        data-amount="<?= (float)$sub['amount'] ?>"
                        <?= ((int)($editing['subscription_id'] ?? $subscriptionId) === (int)$sub['id']) ? 'selected' : '' ?>>
                  <?= e($sub['estate_name']) ?> - <?= e($sub['plan_name']) ?> 
                  (<?= e($sub['subscription_number']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label required">Payment Date</label>
              <input class="form-control" type="date" name="payment_date" required 
                     value="<?= e($editing['payment_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label required">Amount (₦)</label>
              <input class="form-control" type="number" step="0.01" name="amount" required 
                     value="<?= e($editing['amount'] ?? '') ?>" id="amountInput">
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label required">Period Start</label>
              <input class="form-control" type="date" name="period_start" required 
                     value="<?= e($editing['period_start'] ?? date('Y-m-01')) ?>" id="periodStart">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label required">Period End</label>
              <input class="form-control" type="date" name="period_end" required 
                     value="<?= e($editing['period_end'] ?? date('Y-m-t')) ?>" id="periodEnd">
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Payment Method</label>
            <?php $methodVal = (string)($editing['payment_method'] ?? 'bank_transfer'); ?>
            <select class="form-select" name="payment_method">
              <option value="bank_transfer" <?= $methodVal === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
              <option value="card" <?= $methodVal === 'card' ? 'selected' : '' ?>>Card</option>
              <option value="paystack" <?= $methodVal === 'paystack' ? 'selected' : '' ?>>Paystack</option>
              <option value="flutterwave" <?= $methodVal === 'flutterwave' ? 'selected' : '' ?>>Flutterwave</option>
              <option value="wallet" <?= $methodVal === 'wallet' ? 'selected' : '' ?>>Wallet</option>
              <option value="other" <?= $methodVal === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
          </div>

          <div class="mb-6">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="3"><?= e($editing['notes'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Update Payment' : 'Record Payment' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="subscription_payments.php<?= $subscriptionId ? '?subscription_id=' . $subscriptionId : '' ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <!-- Summary Cards -->
    <div class="row g-6 mb-6">
      <div class="col-6 col-md-3">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-gray-600 fs-7">Total Payments</div>
            <div class="fs-2 fw-bold text-gray-900"><?= count($payments) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-gray-600 fs-7">Total Amount</div>
            <div class="fs-2 fw-bold text-primary">₦<?= number_format((float)$totalPayments, 0) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-gray-600 fs-7">Completed</div>
            <div class="fs-2 fw-bold text-success">₦<?= number_format((float)$totalCompleted, 0) ?></div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="card h-100">
          <div class="card-body">
            <div class="text-gray-600 fs-7">Pending</div>
            <div class="fs-2 fw-bold text-warning">₦<?= number_format((float)$totalPayments - (float)$totalCompleted, 0) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Payments Table -->
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Payment Records</div>
      </div>
      <div class="card-body">
        <?php if (!$payments): ?>
          <div class="text-gray-600 text-center py-8">
            <i class="ki-duotone ki-credit-cart fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
            <div>No payment records found.</div>
            <div class="text-gray-500 mt-1">Record subscription payments to track revenue.</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Reference</th>
                  <th>Estate</th>
                  <th>Period</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th class="text-end">Amount</th>
                  <th class="text-end">Date</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($payments as $payment): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($payment['payment_reference']) ?></td>
                  <td>
                    <div><?= e($payment['estate_name']) ?></div>
                    <div class="text-gray-600 fs-7"><?= e($payment['plan_name']) ?></div>
                  </td>
                  <td>
                    <div><?= date('M j, Y', strtotime($payment['period_start'])) ?></div>
                    <div class="text-gray-600 fs-7">to <?= date('M j, Y', strtotime($payment['period_end'])) ?></div>
                  </td>
                  <td><span class="badge badge-light"><?= e($payment['payment_method']) ?></span></td>
                  <td>
                    <span class="badge badge-light-<?= $payment['status'] === 'completed' ? 'success' : 'warning' ?>">
                      <?= e($payment['status']) ?>
                    </span>
                  </td>
                  <td class="text-end fw-bold">₦<?= number_format((float)$payment['amount'], 2) ?></td>
                  <td class="text-end text-gray-700"><?= date('M j, Y', strtotime($payment['payment_date'])) ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <?php if ($payment['status'] !== 'completed'): ?>
                        <button class="btn btn-sm btn-light-success">Mark Paid</button>
                      <?php endif; ?>
                      <form method="post" action="subscription_payments.php<?= $subscriptionId ? '?subscription_id=' . $subscriptionId : '' ?>" onsubmit="return confirm('Delete this payment record?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                        <button class="btn btn-sm btn-light-danger" type="submit">Delete</button>
                      </form>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const subscriptionSelect = document.querySelector('select[name="subscription_id"]');
    const amountInput = document.getElementById('amountInput');
    const periodStartInput = document.getElementById('periodStart');
    const periodEndInput = document.getElementById('periodEnd');
    
    function updatePaymentForm() {
        const selectedOption = subscriptionSelect.options[subscriptionSelect.selectedIndex];
        if (!selectedOption.value) return;
        
        const cycle = selectedOption.dataset.cycle;
        const amount = selectedOption.dataset.amount;
        
        // Set amount
        if (amount && !amountInput.value) {
            amountInput.value = amount;
        }
        
        // Set period dates based on cycle
        const today = new Date();
        if (cycle === 'monthly') {
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            if (!periodStartInput.value) periodStartInput.valueAsDate = firstDay;
            if (!periodEndInput.value) periodEndInput.valueAsDate = lastDay;
        } else if (cycle === 'annual') {
            const firstDay = new Date(today.getFullYear(), 0, 1);
            const lastDay = new Date(today.getFullYear(), 11, 31);
            if (!periodStartInput.value) periodStartInput.valueAsDate = firstDay;
            if (!periodEndInput.value) periodEndInput.valueAsDate = lastDay;
        }
    }
    
    subscriptionSelect.addEventListener('change', updatePaymentForm);
    
    // Initialize on load if editing
    if (<?= $editing ? 'true' : 'false' ?>) {
        updatePaymentForm();
    }
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>