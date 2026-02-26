<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Estate Subscriptions – EstatePro';
$db = db();
$method = request_method();

$estateId = (int)(get_param('estate_id', 0) ?? 0);
$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'assign');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $db->execute('DELETE FROM estate_subscriptions WHERE id = ?', [$deleteId]);
            flash_set('success', 'Subscription removed.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not remove subscription.');
        }
        redirect('estate_subscriptions.php' . ($estateId ? '?estate_id=' . $estateId : ''));
    }

    if ($action === 'assign' || $action === 'update') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        $planId = (int)(post_param('plan_id', 0) ?? 0);
        $startDate = (string)post_param('start_date', '');
        $billingCycle = (string)post_param('billing_cycle', 'monthly');
        $autoRenew = (int)(post_param('auto_renew', 1) ?? 1);
        $notes = trim((string)post_param('notes', ''));

        if ($estateIdPost <= 0 || $planId <= 0 || $startDate === '') {
            flash_set('error', 'Estate, plan, and start date are required.');
            redirect('estate_subscriptions.php?estate_id=' . $estateIdPost);
        }

        try {
            // Get plan details
            $plan = $db->fetchOne('SELECT monthly_price, annual_price, billing_cycle FROM subscription_plans WHERE id = ?', [$planId]);
            if (!$plan) {
                throw new RuntimeException('Plan not found.');
            }

            $amount = $billingCycle === 'annual' ? (float)$plan['annual_price'] : (float)$plan['monthly_price'];
            $subscriptionNumber = 'SUB-' . $estateIdPost . '-' . date('YmdHis') . '-' . random_int(100, 999);
            
            // Calculate next billing date
            $nextBillingDate = date('Y-m-d', strtotime($startDate . ' +1 ' . ($billingCycle === 'annual' ? 'year' : 'month')));

            if ($id > 0) {
                // Update existing subscription
                $db->execute(
                    'UPDATE estate_subscriptions 
                     SET plan_id = ?, start_date = ?, billing_cycle = ?, amount = ?, next_billing_date = ?, auto_renew = ?, notes = NULLIF(?, "")
                     WHERE id = ?',
                    [$planId, $startDate, $billingCycle, $amount, $nextBillingDate, $autoRenew, $notes, $id]
                );
                flash_set('success', 'Subscription updated.');
            } else {
                // Create new subscription
                $db->execute(
                    'INSERT INTO estate_subscriptions 
                     (estate_id, plan_id, subscription_number, status, start_date, billing_cycle, amount, next_billing_date, auto_renew, notes, created_by)
                     VALUES (?, ?, ?, "active", ?, ?, ?, ?, ?, NULLIF(?, ""), ?)',
                    [$estateIdPost, $planId, $subscriptionNumber, $startDate, $billingCycle, $amount, $nextBillingDate, $autoRenew, $notes, current_user_id()]
                );
                flash_set('success', 'Subscription assigned to estate.');
            }
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }

        redirect('estate_subscriptions.php?estate_id=' . $estateIdPost);
    }
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM estate_subscriptions WHERE id = ?', [$editId]);
    if (!$editing) {
        flash_set('warning', 'Subscription not found.');
        redirect('estate_subscriptions.php');
    }
    $estateId = (int)$editing['estate_id'];
}

$estates = $db->fetchAll('SELECT id, name, total_units, occupied_units FROM estates ORDER BY name ASC');
$plans = $db->fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY sort_order ASC, name ASC");

// Get subscriptions for specific estate or all
$whereClause = '';
$params = [];
if ($estateId > 0) {
    $whereClause = 'WHERE es.estate_id = ?';
    $params = [$estateId];
}

$subscriptions = $db->fetchAll(
    "SELECT 
        es.*,
        e.name as estate_name,
        e.total_units,
        e.occupied_units,
        sp.name as plan_name,
        sp.code as plan_code,
        sp.description as plan_description,
        sp.monthly_price,
        sp.annual_price,
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
     $whereClause
     ORDER BY es.created_at DESC",
    $params
);

$selectedEstate = null;
if ($estateId > 0) {
    $selectedEstate = $db->fetchOne('SELECT id, name, total_units, occupied_units FROM estates WHERE id = ?', [$estateId]);
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Estate Subscriptions</h1>
    <div class="text-gray-600">
      <?= $selectedEstate ? 'Manage subscriptions for ' . e($selectedEstate['name']) : 'Assign subscription plans to estates' ?>
    </div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light-primary" href="subscription_monitoring.php">
      <i class="ki-duotone ki-eye fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Monitor All
    </a>
    <a class="btn btn-light" href="subscription_plans.php">
      <i class="ki-duotone ki-setting fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Plans
    </a>
  </div>
</div>

<?php if ($selectedEstate): ?>
  <div class="card mb-6">
    <div class="card-body">
      <div class="d-flex align-items-center">
        <div class="symbol symbol-50px me-4">
          <div class="symbol-label bg-light-primary">
            <i class="ki-duotone ki-home fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
          </div>
        </div>
        <div class="flex-grow-1">
          <div class="d-flex flex-wrap align-items-center">
            <h3 class="text-gray-900 fw-bold mb-1 me-3"><?= e($selectedEstate['name']) ?></h3>
            <span class="badge badge-light-primary me-3">
              <?= (int)$selectedEstate['occupied_units'] ?>/<?= (int)$selectedEstate['total_units'] ?> units occupied
            </span>
          </div>
          <div class="text-gray-600">
            <?= count($subscriptions) ?> active subscription<?= count($subscriptions) !== 1 ? 's' : '' ?>
          </div>
        </div>
        <div>
          <a class="btn btn-sm btn-light" href="estates.php?edit_id=<?= (int)$selectedEstate['id'] ?>">View Estate</a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-6">
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Update Subscription' : 'Assign Subscription' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="estate_subscriptions.php<?= $estateId ? '?estate_id=' . $estateId : '' ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editing ? 'update' : 'assign' ?>">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <?php if (!$selectedEstate): ?>
            <div class="mb-4">
              <label class="form-label required">Estate</label>
              <select class="form-select" name="estate_id" required <?= $editing ? 'disabled' : '' ?>>
                <option value="">Select estate</option>
                <?php foreach ($estates as $estate): ?>
                  <option value="<?= (int)$estate['id'] ?>" <?= ((int)($editing['estate_id'] ?? $estateId) === (int)$estate['id']) ? 'selected' : '' ?>>
                    <?= e($estate['name']) ?> (<?= (int)$estate['occupied_units'] ?>/<?= (int)$estate['total_units'] ?> units)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <input type="hidden" name="estate_id" value="<?= (int)$selectedEstate['id'] ?>">
          <?php endif; ?>

          <div class="mb-4">
            <label class="form-label required">Subscription Plan</label>
            <select class="form-select" name="plan_id" required>
              <option value="">Select plan</option>
              <?php foreach ($plans as $plan): ?>
                <option value="<?= (int)$plan['id'] ?>" 
                        data-monthly="<?= (float)$plan['monthly_price'] ?>" 
                        data-annual="<?= (float)$plan['annual_price'] ?>"
                        data-max-units="<?= (int)$plan['max_units'] ?>"
                        <?= ((int)($editing['plan_id'] ?? 0) === (int)$plan['id']) ? 'selected' : '' ?>>
                  <?= e($plan['name']) ?> - 
                  <?php if ((float)$plan['monthly_price'] > 0): ?>
                    number_format((float)$plan['monthly_price'], 0) ?>/mo
                  <?php endif; ?>
                  <?php if ((float)$plan['annual_price'] > 0): ?>
                    or₦ numberumber_format((float)$plan['annual_price'], 0) ?>/yr
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="planDescription"></div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label required">Start Date</label>
              <input class="form-control" type="date" name="start_date" required value="<?= e($editing['start_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Billing Cycle</label>
              <?php $cycleVal = (string)($editing['billing_cycle'] ?? 'monthly'); ?>
              <select class="form-select" name="billing_cycle">
                <option value="monthly" <?= $cycleVal === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="annual" <?= $cycleVal === 'annual' ? 'selected' : '' ?>>Annual</option>
              </select>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Amount (₦)</label>
            <input class="form-control" type="number" step="0.01" name="amount" id="amountInput" 
                   value="<?= e($editing['amount'] ?? '') ?>" readonly>
            <div class="form-text">Automatically calculated based on plan and billing cycle</div>
          </div>

          <div class="mb-4">
            <label class="form-label">Auto-Renew</label>
            <div class="form-check form-switch form-check-custom form-check-solid">
              <input class="form-check-input" type="checkbox" name="auto_renew" value="1" 
                     id="autoRenewSwitch" <?= ($editing['auto_renew'] ?? 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="autoRenewSwitch">
                Automatically renew subscription
              </label>
            </div>
          </div>

          <div class="mb-6">
            <label class="form-label">Notes</label>
            <textarea class="form-control" name="notes" rows="3"><?= e($editing['notes'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Update Subscription' : 'Assign Subscription' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="estate_subscriptions.php<?= $estateId ? '?estate_id=' . $estateId : '' ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Estate Subscriptions</div>
      </div>
      <div class="card-body">
        <?php if (!$subscriptions): ?>
          <div class="text-gray-600 text-center py-8">
            <i class="ki-duotone ki-home fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
            <div>No subscriptions found.</div>
            <div class="text-gray-500 mt-1">Assign a subscription plan to an estate to get started.</div>
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
                      <div>
                        <a href="estate_subscriptions.php?estate_id=<?= (int)$sub['estate_id'] ?>" class="text-gray-900 fw-bold text-hover-primary">
                          <?= e($sub['estate_name']) ?>
                        </a>
                        <div class="text-gray-600 fs-7">
                          <?= (int)$sub['occupied_units'] ?>/<?= (int)$sub['total_units'] ?> units
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
                      <a class="btn btn-sm btn-light-primary" href="estate_subscriptions.php?edit_id=<?= (int)$sub['id'] ?><?= $estateId ? '&estate_id=' . $estateId : '' ?>">Edit</a>
                      <a class="btn btn-sm btn-light" href="subscription_payments.php?subscription_id=<?= (int)$sub['id'] ?>">Payments</a>
                      <form method="post" action="estate_subscriptions.php<?= $estateId ? '?estate_id=' . $estateId : '' ?>" onsubmit="return confirm('Remove this subscription?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <button class="btn btn-sm btn-light-danger" type="submit">Remove</button>
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
    const planSelect = document.querySelector('select[name="plan_id"]');
    const billingCycleSelect = document.querySelector('select[name="billing_cycle"]');
    const amountInput = document.getElementById('amountInput');
    const planDescription = document.getElementById('planDescription');
    
    function updateAmount() {
        const selectedOption = planSelect.options[planSelect.selectedIndex];
        if (!selectedOption.value) {
            amountInput.value = '';
            planDescription.textContent = '';
            return;
        }
        
        const monthlyPrice = parseFloat(selectedOption.dataset.monthly) || 0;
        const annualPrice = parseFloat(selectedOption.dataset.annual) || 0;
        const maxUnits = parseInt(selectedOption.dataset.maxUnits) || 0;
        const billingCycle = billingCycleSelect.value;
        
        const amount = billingCycle === 'annual' ? annualPrice : monthlyPrice;
        amountInput.value = amount.toFixed(2);
        
        // Update description
        let desc = '';
        if (selectedOption.textContent.includes(' - ')) {
            desc = selectedOption.textContent.split(' - ')[1];
        }
        if (maxUnits > 0) {
            desc += ` • Max ${maxUnits} units`;
        } else if (maxUnits === 0) {
            desc += ' • Unlimited units';
        }
        planDescription.textContent = desc;
    }
    
    planSelect.addEventListener('change', updateAmount);
    billingCycleSelect.addEventListener('change', updateAmount);
    
    // Initialize on load
    updateAmount();
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>