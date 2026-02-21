<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Invoice Automation – EstatePro';
$db = db();
$method = request_method();

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

$runResult = null;
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    
    if ($action === 'run_automation') {
        // Define constant to prevent auto-execution
        define('INVOICE_AUTOMATION_ADMIN_MODE', true);
        
        // Include the cron script to get the function
        require_once __DIR__ . '/../../app/cron/invoice_and_reminders.php';
        
        // Call the function directly
        $runResult = run_invoice_automation();
    }
}

// Get statistics
$stats = [
    'active_leases' => 0,
    'pending_invoices' => 0,
    'overdue_invoices' => 0,
    'invoices_this_month' => 0,
];

try {
    $stats['active_leases'] = (int)$db->fetchOne(
        "SELECT COUNT(*) AS c FROM leases l
         INNER JOIN tenants t ON t.id = l.tenant_id
         WHERE l.status = 'active' AND t.estate_id = ?",
        [$estateId]
    )['c'] ?? 0;
    
    $stats['pending_invoices'] = (int)$db->fetchOne(
        "SELECT COUNT(*) AS c FROM invoices WHERE estate_id = ? AND status IN ('pending', 'partial')",
        [$estateId]
    )['c'] ?? 0;
    
    $stats['overdue_invoices'] = (int)$db->fetchOne(
        "SELECT COUNT(*) AS c FROM invoices 
         WHERE estate_id = ? AND status IN ('pending', 'partial', 'overdue') 
           AND due_date < CURDATE()",
        [$estateId]
    )['c'] ?? 0;
    
    $stats['invoices_this_month'] = (int)$db->fetchOne(
        "SELECT COUNT(*) AS c FROM invoices 
         WHERE estate_id = ? AND MONTH(created_at) = MONTH(CURDATE()) 
           AND YEAR(created_at) = YEAR(CURDATE())",
        [$estateId]
    )['c'] ?? 0;
} catch (Throwable $e) {
    // Ignore errors
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Invoice Automation</h1>
    <div class="text-gray-600">Automatic invoice generation and payment reminders.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="invoice_automation.php" class="row g-3 align-items-end">
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
    </form>
  </div>
</div>

<div class="row g-6 mb-6">
  <div class="col-md-3">
    <div class="card card-flush">
      <div class="card-body">
        <div class="text-gray-600 fw-semibold fs-7 mb-1">Active Leases</div>
        <div class="text-gray-900 fw-bold fs-2x"><?= $stats['active_leases'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-flush">
      <div class="card-body">
        <div class="text-gray-600 fw-semibold fs-7 mb-1">Pending Invoices</div>
        <div class="text-gray-900 fw-bold fs-2x"><?= $stats['pending_invoices'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-flush">
      <div class="card-body">
        <div class="text-gray-600 fw-semibold fs-7 mb-1">Overdue Invoices</div>
        <div class="text-gray-900 fw-bold fs-2x text-danger"><?= $stats['overdue_invoices'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-flush">
      <div class="card-body">
        <div class="text-gray-600 fw-semibold fs-7 mb-1">Invoices This Month</div>
        <div class="text-gray-900 fw-bold fs-2x"><?= $stats['invoices_this_month'] ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-6">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Run Automation Manually</div>
      </div>
      <div class="card-body">
        <p class="text-gray-600 mb-4">
          This will generate invoices for active leases and send reminder notifications.
          The automation runs automatically daily via cron, but you can trigger it manually here.
        </p>
        
        <form method="post" action="invoice_automation.php?estate_id=<?= $estateId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="run_automation">
          <button class="btn btn-primary" type="submit">Run Invoice Generation & Reminders</button>
        </form>
        
        <?php if ($runResult): ?>
          <div class="mt-6">
            <?php if ($runResult['success'] ?? false): ?>
              <div class="alert alert-success">
                <h4 class="alert-heading">Success!</h4>
                <?php if (!empty($runResult['logs'])): ?>
                  <p><strong>Actions taken:</strong></p>
                  <ul class="mb-0">
                    <?php foreach ($runResult['logs'] as $log): ?>
                      <li><?= e($log) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p class="mb-0">No actions needed. All invoices are up to date.</p>
                <?php endif; ?>
                
                <?php if (!empty($runResult['errors'])): ?>
                  <hr>
                  <p><strong>Errors:</strong></p>
                  <ul class="mb-0">
                    <?php foreach ($runResult['errors'] as $err): ?>
                      <li class="text-danger"><?= e($err) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                
                <?php if (isset($runResult['duration'])): ?>
                  <p class="mt-3 mb-0"><small>Execution time: <?= e($runResult['duration']) ?>s</small></p>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="alert alert-danger">
                <h4 class="alert-heading">Error</h4>
                <p class="mb-0"><?= e($runResult['error'] ?? 'Unknown error occurred') ?></p>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <div class="col-12 col-xxl-6">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">How It Works</div>
      </div>
      <div class="card-body">
        <h5 class="fw-bold mb-3">Automatic Invoice Generation</h5>
        <ul class="mb-4">
          <li><strong>Monthly leases:</strong> Invoice generated on the 1st of each month for next month's rent</li>
          <li><strong>Quarterly leases:</strong> Invoice generated at start of quarter for next quarter</li>
          <li><strong>Yearly leases:</strong> Invoice generated at start of year for next year</li>
          <li>Separate invoices created for rent and service charge (if applicable)</li>
          <li>Only generates for active leases within their valid date range</li>
          <li>Prevents duplicate invoices for the same period</li>
        </ul>
        
        <h5 class="fw-bold mb-3">Reminder Notifications</h5>
        <ul class="mb-4">
          <li><strong>7 days before due:</strong> First reminder sent</li>
          <li><strong>3 days before due:</strong> Second reminder sent</li>
          <li><strong>Due date:</strong> Final reminder sent</li>
          <li><strong>Overdue:</strong> Weekly reminders sent (every 7 days)</li>
        </ul>
        
        <h5 class="fw-bold mb-3">Setting Up Automatic Execution</h5>
        <p class="text-gray-600 mb-2">
          To run automatically daily, set up a cron job (Linux) or Task Scheduler (Windows):
        </p>
        <div class="bg-light p-3 rounded">
          <code class="text-dark">
            php C:\xampp\htdocs\ESTATEMANAGEMENT\app\cron\invoice_and_reminders.php
          </code>
        </div>
        <p class="text-gray-600 mt-3 mb-0">
          <small>Or access via web: <code>http://yourdomain.com/app/cron/invoice_and_reminders.php?key=YOUR_SECRET_KEY</code></small>
        </p>
      </div>
    </div>
  </div>
</div>

<div class="card mt-6">
  <div class="card-header">
    <div class="card-title fw-bold">Quick Links</div>
  </div>
  <div class="card-body">
    <div class="d-flex gap-2 flex-wrap">
      <a href="invoices.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-primary">View Invoices</a>
      <a href="payments.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-primary">View Payments</a>
      <a href="leases.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-primary">View Leases</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
