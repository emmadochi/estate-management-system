<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Subscription Plans – EstatePro';
$db = db();
$method = request_method();

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', 'save');

    if ($action === 'delete') {
        $deleteId = (int)(post_param('id', 0) ?? 0);
        try {
            $db->execute('DELETE FROM subscription_plans WHERE id = ?', [$deleteId]);
            flash_set('success', 'Subscription plan deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete plan. Make sure no estates are using this plan.');
        }
        redirect('subscription_plans.php');
    }

    $id = (int)(post_param('id', 0) ?? 0);
    $name = trim((string)post_param('name', ''));
    $code = trim((string)post_param('code', ''));
    $description = trim((string)post_param('description', ''));
    $monthlyPrice = (float)(post_param('monthly_price', 0) ?? 0);
    $annualPrice = (float)(post_param('annual_price', 0) ?? 0);
    $billingCycle = (string)post_param('billing_cycle', 'monthly');
    $maxUnits = (int)(post_param('max_units', 0) ?? 0);
    $maxUsers = (int)(post_param('max_users', 0) ?? 0);
    $status = (string)post_param('status', 'active');
    $sortOrder = (int)(post_param('sort_order', 0) ?? 0);

    // Features as JSON array
    $features = [];
    $coreFeatures = (array)(post_param('core_features', []) ?? []);
    $proFeatures = (array)(post_param('pro_features', []) ?? []);
    if ($coreFeatures) {
        $features['core_features'] = array_values($coreFeatures);
    }
    if ($proFeatures) {
        $features['pro_features'] = array_values($proFeatures);
    }
    $featuresJson = json_encode($features, JSON_UNESCAPED_UNICODE);

    if ($name === '' || $code === '') {
        flash_set('error', 'Name and code are required.');
        redirect($id > 0 ? ('subscription_plans.php?edit_id=' . $id) : 'subscription_plans.php');
    }

    try {
        if ($id > 0) {
            $db->execute(
                'UPDATE subscription_plans
                 SET name = ?, code = ?, description = ?, monthly_price = ?, annual_price = ?, 
                     billing_cycle = ?, max_units = ?, max_users = ?, features = ?, status = ?, sort_order = ?
                 WHERE id = ?',
                [$name, $code, $description, $monthlyPrice, $annualPrice, $billingCycle, $maxUnits, $maxUsers, $featuresJson, $status, $sortOrder, $id]
            );
            flash_set('success', 'Subscription plan updated.');
        } else {
            $db->execute(
                'INSERT INTO subscription_plans 
                 (name, code, description, monthly_price, annual_price, billing_cycle, max_units, max_users, features, status, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$name, $code, $description, $monthlyPrice, $annualPrice, $billingCycle, $maxUnits, $maxUsers, $featuresJson, $status, $sortOrder]
            );
            flash_set('success', 'Subscription plan created.');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Save failed. Plan code must be unique.');
    }

    redirect('subscription_plans.php');
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT *, JSON_EXTRACT(features, "$") AS features_array FROM subscription_plans WHERE id = ?', [$editId]);
    if ($editing && isset($editing['features_array'])) {
        $editing['features_data'] = json_decode($editing['features_array'], true);
        if (!$editing['features_data']) $editing['features_data'] = [];
    }
    if (!$editing) {
        flash_set('warning', 'Subscription plan not found.');
        redirect('subscription_plans.php');
    }
}

$plans = $db->fetchAll(
    "SELECT p.*, 
            (SELECT COUNT(*) FROM estate_subscriptions s WHERE s.plan_id = p.id AND s.status = 'active') as active_subscribers,
            (SELECT SUM(sp.amount) FROM subscription_payments sp 
             JOIN estate_subscriptions es ON sp.subscription_id = es.id 
             WHERE es.plan_id = p.id AND sp.status = 'completed') as revenue_generated
     FROM subscription_plans p
     ORDER BY p.sort_order ASC, p.created_at DESC"
);

// Get all estates for reference
$estates = $db->fetchAll('SELECT id, name FROM estates ORDER BY name ASC');

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Subscription Plans</h1>
    <div class="text-gray-600">Manage pricing plans and features for estate subscriptions.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light-primary" href="subscription_monitoring.php">
      <i class="ki-duotone ki-eye fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>
      Monitor Subscriptions
    </a>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Plan' : 'Add Plan' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="subscription_plans.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-4">
            <label class="form-label required">Plan Name</label>
            <input class="form-control" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="e.g. Growth Plan">
          </div>

          <div class="mb-4">
            <label class="form-label required">Code (unique)</label>
            <input class="form-control" name="code" required value="<?= e($editing['code'] ?? '') ?>" placeholder="e.g. GROWTH">
          </div>

          <div class="mb-4">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="3"><?= e($editing['description'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Monthly Price (₦)</label>
              <input class="form-control" type="number" step="0.01" name="monthly_price" value="<?= e($editing['monthly_price'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Annual Price (₦)</label>
              <input class="form-control" type="number" step="0.01" name="annual_price" value="<?= e($editing['annual_price'] ?? '') ?>">
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Max Units (0 = unlimited)</label>
              <input class="form-control" type="number" name="max_units" value="<?= e($editing['max_units'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Max Users (0 = unlimited)</label>
              <input class="form-control" type="number" name="max_users" value="<?= e($editing['max_users'] ?? '') ?>">
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Billing Cycle</label>
            <?php $cycleVal = (string)($editing['billing_cycle'] ?? 'monthly'); ?>
            <select class="form-select" name="billing_cycle">
              <option value="monthly" <?= $cycleVal === 'monthly' ? 'selected' : '' ?>>Monthly</option>
              <option value="annual" <?= $cycleVal === 'annual' ? 'selected' : '' ?>>Annual</option>
              <option value="custom" <?= $cycleVal === 'custom' ? 'selected' : '' ?>>Custom</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label">Status</label>
            <?php $statusVal = (string)($editing['status'] ?? 'active'); ?>
            <select class="form-select" name="status">
              <option value="active" <?= $statusVal === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $statusVal === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              <option value="archived" <?= $statusVal === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
          </div>

          <div class="mb-6">
            <label class="form-label">Sort Order</label>
            <input class="form-control" type="number" name="sort_order" value="<?= e($editing['sort_order'] ?? '') ?>">
            <div class="form-text">Lower numbers appear first</div>
          </div>

          <div class="mb-6">
            <label class="form-label">Core Features</label>
            <?php $coreFeatures = (array)($editing['features_data']['core_features'] ?? []); ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="tenant_portal" <?= in_array('tenant_portal', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Tenant Portal</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="rent_management" <?= in_array('rent_management', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Rent Management</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="maintenance_basic" <?= in_array('maintenance_basic', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Basic Maintenance</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="property_management" <?= in_array('property_management', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Property Management</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="tenant_management" <?= in_array('tenant_management', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Tenant Management</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="multi_estate" <?= in_array('multi_estate', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Multi-Estate Support</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="advanced_reporting" <?= in_array('advanced_reporting', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Advanced Reporting</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="communication_hub" <?= in_array('communication_hub', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Communication Hub</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="document_management" <?= in_array('document_management', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Document Management</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="core_features[]" value="analytics" <?= in_array('analytics', $coreFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Analytics</label>
            </div>
          </div>

          <div class="mb-6">
            <label class="form-label">Pro Features</label>
            <?php $proFeatures = (array)($editing['features_data']['pro_features'] ?? []); ?>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="priority_support" <?= in_array('priority_support', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Priority Support</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="custom_integrations" <?= in_array('custom_integrations', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Custom Integrations</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="api_access" <?= in_array('api_access', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">API Access</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="white_label" <?= in_array('white_label', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">White Label</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="dedicated_support" <?= in_array('dedicated_support', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Dedicated Support</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="enterprise_api" <?= in_array('enterprise_api', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">Enterprise API</label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="pro_features[]" value="sla_guarantee" <?= in_array('sla_guarantee', $proFeatures) ? 'checked' : '' ?>>
              <label class="form-check-label">SLA Guarantee</label>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Plan' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="subscription_plans.php">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Subscription Plans</div>
      </div>
      <div class="card-body">
        <?php if (!$plans): ?>
          <div class="text-gray-600">No subscription plans yet. Create one on the left.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-3">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Plan</th>
                  <th>Code</th>
                  <th>Price</th>
                  <th>Active Estates</th>
                  <th>Revenue Generated</th>
                  <th>Max Units</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($plans as $plan): ?>
                <tr>
                  <td class="fw-bold text-gray-900">
                    <div><?= e($plan['name']) ?></div>
                    <div class="text-gray-600 fs-7"><?= e($plan['description'] ?? '') ?></div>
                  </td>
                  <td class="fw-bold text-gray-800"><?= e($plan['code']) ?></td>
                  <td>
                    <div><?= $plan['monthly_price'] > 0 ? '₦' . number_format($plan['monthly_price'], 2) . '/mo' : '' ?></div>
                    <div class="text-gray-600 fs-7"><?= $plan['annual_price'] > 0 ? '₦' . number_format($plan['annual_price'], 2) . '/yr' : '' ?></div>
                  </td>
                  <td class="text-center">
                    <span class="badge badge-light-primary"><?= (int)$plan['active_subscribers'] ?></span>
                  </td>
                  <td class="text-center">
                    <?php if ($plan['revenue_generated'] > 0): ?>
                      <span class="text-success">₦<?= number_format((float)$plan['revenue_generated'], 2) ?></span>
                    <?php else: ?>
                      <span class="text-gray-500">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="badge badge-light">
                      <?= (int)$plan['max_units'] === 0 ? '∞' : (int)$plan['max_units'] ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge badge-light-<?= $plan['status'] === 'active' ? 'success' : ($plan['status'] === 'inactive' ? 'warning' : 'secondary') ?>">
                      <?= e($plan['status']) ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary" href="subscription_plans.php?edit_id=<?= (int)$plan['id'] ?>">Edit</a>
                      <form method="post" action="subscription_plans.php" onsubmit="return confirm('Delete this plan? This will fail if any estates are subscribed to it.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">
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

<?php require __DIR__ . '/partials/bottom.php'; ?>