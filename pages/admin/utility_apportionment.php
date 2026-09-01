<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/messaging_service.php';

require_login(['super_admin', 'estate_admin', 'accountant', 'property_manager']);

$pageTitle = 'Utility & Diesel Cost Apportionment – EstatePro';
$pageHeading = 'Utility Cost Apportionment';
$db = db();
$method = request_method();
$userId = (int)current_user_id();

$isSuper = is_super_admin();
$estates = estates_for_current_user();
if (!$estates) {
    if ($isSuper) {
        flash_set('warning', 'Create an estate first.');
        redirect('estates.php');
    }
    http_response_code(403);
    echo 'No estate access assigned to your account.';
    exit;
}

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estateId = normalize_estate_id($requestedEstateId);
$month = (string)(get_param('month', date('Y-m')) ?? date('Y-m'));

// Handle Post: Calculate and Generate Invoices
if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');
    $estateIdPost = (int)(post_param('estate_id', $estateId) ?? $estateId);
    assert_can_access_estate($estateIdPost);
    $billingMonth = (string)post_param('billing_month', date('Y-m'));
    $dieselCost = (float)(post_param('total_diesel_cost', 0) ?? 0);
    $gridCost = (float)(post_param('total_grid_cost', 0) ?? 0);
    $totalBillable = $dieselCost + $gridCost;
    $methodType = (string)post_param('apportionment_method', 'equal_split_by_unit');
    $dueDate = (string)post_param('due_date', date('Y-m-d', strtotime('+14 days')));
    $sendSms = (int)(post_param('send_sms', 1) ?? 1);

    if ($totalBillable <= 0) {
        flash_set('error', 'Total power bill must be greater than zero.');
        redirect('utility_apportionment.php?estate_id=' . $estateIdPost . '&month=' . $billingMonth);
    }

    // Fetch active tenants in this estate
    $tenants = $db->fetchAll(
        "SELECT t.id AS tenant_id, t.user_id, t.unit_id, u.first_name, u.last_name, u.phone, u.email, un.unit_number, un.bedrooms, est.name AS estate_name
         FROM tenants t
         INNER JOIN users u ON u.id = t.user_id
         INNER JOIN units un ON un.id = t.unit_id
         INNER JOIN estates est ON est.id = t.estate_id
         WHERE t.estate_id = ? AND t.status = 'active'",
        [$estateIdPost]
    ) ?: [];

    if (empty($tenants)) {
        flash_set('error', 'No active tenants found in this estate to apportion bills.');
        redirect('utility_apportionment.php?estate_id=' . $estateIdPost . '&month=' . $billingMonth);
    }

    $totalUnits = count($tenants);
    $conn = $db->getConnection();

    try {
        $conn->beginTransaction();

        // 1. Record Apportionment Record
        $db->execute(
            "INSERT INTO utility_apportionments 
             (estate_id, billing_month, total_diesel_cost, total_grid_cost, total_billable_amount, total_units_billed, apportionment_method, status, created_by, invoiced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'invoiced', ?, NOW())
             ON DUPLICATE KEY UPDATE 
                total_diesel_cost = VALUES(total_diesel_cost),
                total_grid_cost = VALUES(total_grid_cost),
                total_billable_amount = VALUES(total_billable_amount),
                total_units_billed = VALUES(total_units_billed),
                apportionment_method = VALUES(apportionment_method),
                status = 'invoiced',
                invoiced_at = NOW()",
            [$estateIdPost, $billingMonth, $dieselCost, $gridCost, $totalBillable, $totalUnits, $methodType, $userId]
        );

        // 2. Compute Total Weight for weighted split
        $totalBedrooms = 0;
        foreach ($tenants as $t) {
            $totalBedrooms += max(1, (int)$t['bedrooms']);
        }

        // 3. Generate Invoices
        $invoicesCreated = 0;
        foreach ($tenants as $t) {
            $tenantId = (int)$t['tenant_id'];
            $unitNum = (string)$t['unit_number'];
            $beds = max(1, (int)$t['bedrooms']);

            if ($methodType === 'weighted_by_bedrooms' && $totalBedrooms > 0) {
                $unitAmount = round(($totalBillable * $beds) / $totalBedrooms, 2);
            } else {
                $unitAmount = round($totalBillable / $totalUnits, 2);
            }

            $invNumber = 'PWR-' . $estateIdPost . '-' . str_replace('-', '', $billingMonth) . '-' . str_pad((string)$tenantId, 4, '0', STR_PAD_LEFT);
            $desc = "Power & Diesel Apportionment for {$billingMonth} (Unit {$unitNum}, {$beds} Bed)";

            $db->execute(
                "INSERT INTO invoices 
                 (estate_id, tenant_id, invoice_number, type, amount, paid_amount, due_date, status, description)
                 VALUES (?, ?, ?, 'utility', ?, 0.00, ?, 'pending', ?)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_date = VALUES(due_date), description = VALUES(description)",
                [$estateIdPost, $tenantId, $invNumber, $unitAmount, $dueDate, $desc]
            );

            $invoicesCreated++;

            // Dispatch SMS / WhatsApp
            if ($sendSms && !empty($t['phone'])) {
                $smsMsg = "EstatePro: Your power & diesel bill for {$billingMonth} (Unit {$unitNum}) is ₦" . number_format($unitAmount, 2) . ". Due: {$dueDate}. Kindly pay via your tenant portal.";
                messaging()->sendSms((string)$t['phone'], $smsMsg);
            }
        }

        $conn->commit();
        flash_set('success', "Success! Generated and dispatched {$invoicesCreated} utility invoices for Month {$billingMonth}.");
        redirect('utility_apportionment.php?estate_id=' . $estateIdPost . '&month=' . $billingMonth);

    } catch (Throwable $e) {
        $conn->rollBack();
        flash_set('error', 'Apportionment Error: ' . $e->getMessage());
        redirect('utility_apportionment.php?estate_id=' . $estateIdPost . '&month=' . $billingMonth);
    }
}

// Aggregate Month's Diesel Purchases & Litres Consumed
$dieselStats = $db->fetchOne(
    "SELECT 
        COALESCE(SUM(litres), 0) AS total_litres_purchased,
        COALESCE(SUM(total_amount), 0) AS total_diesel_spent
     FROM diesel_purchases
     WHERE estate_id = ? AND DATE_FORMAT(purchase_date, '%Y-%m') = ?",
    [$estateId, $month]
);

$logStats = $db->fetchOne(
    "SELECT 
        COALESCE(SUM(duration_hours), 0) AS total_runtime_hours,
        COALESCE(SUM(fuel_consumed_litres), 0) AS total_fuel_used
     FROM generator_logs
     WHERE estate_id = ? AND DATE_FORMAT(start_time, '%Y-%m') = ?",
    [$estateId, $month]
);

// Fetch Active Tenants Count & Breakdown
$activeTenants = $db->fetchAll(
    "SELECT t.id, un.unit_number, un.bedrooms, u.first_name, u.last_name
     FROM tenants t
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = t.unit_id
     WHERE t.estate_id = ? AND t.status = 'active'
     ORDER BY un.unit_number ASC",
    [$estateId]
) ?: [];

// Check if already invoiced
$existingApportionment = $db->fetchOne(
    "SELECT * FROM utility_apportionments WHERE estate_id = ? AND billing_month = ?",
    [$estateId, $month]
);

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Header & Filter Bar -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-success">
                                <i class="ki-duotone ki-calculator fs-2x text-success"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Utility & Diesel Cost Apportionment</h1>
                                <span class="text-muted fs-7">Automatically split monthly diesel and electricity bills across residents and dispatch invoices</span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3">
                            <form method="get" action="utility_apportionment.php" class="d-flex align-items-center gap-2">
                                <select name="estate_id" class="form-select form-select-sm w-180px">
                                    <?php foreach ($estates as $est): ?>
                                        <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                            <?= e($est['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="month" name="month" class="form-control form-control-sm w-150px" value="<?= e($month) ?>">
                                <button type="submit" class="btn btn-sm btn-primary">Load</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Month's Aggregated Metrics -->
            <div class="row g-5 g-xl-8 mb-6">
                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Month's Diesel Cost</span>
                            <div class="fs-2hx fw-bolder text-primary mb-1">₦<?= number_format((float)($dieselStats['total_diesel_spent'] ?? 0), 2) ?></div>
                            <span class="text-muted fs-8">From <?= number_format((float)($dieselStats['total_litres_purchased'] ?? 0), 0) ?> Litres Purchased</span>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Generator Hours Run</span>
                            <div class="fs-2hx fw-bolder text-dark mb-1"><?= number_format((float)($logStats['total_runtime_hours'] ?? 0), 1) ?> <span class="fs-6 text-muted">Hrs</span></div>
                            <span class="text-muted fs-8">Fuel Used: <?= number_format((float)($logStats['total_fuel_used'] ?? 0), 0) ?> Litres</span>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Active Billable Units</span>
                            <div class="fs-2hx fw-bolder text-success mb-1"><?= count($activeTenants) ?> <span class="fs-6 text-muted">Units</span></div>
                            <span class="text-muted fs-8">Occupied apartments in this estate</span>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Status for <?= $month ?></span>
                            <?php if ($existingApportionment && $existingApportionment['status'] === 'invoiced'): ?>
                                <div class="fs-2hx fw-bolder text-success mb-1">INVOICED</div>
                                <span class="text-muted fs-8">Invoiced on <?= date('M j, Y', strtotime($existingApportionment['invoiced_at'])) ?></span>
                            <?php else: ?>
                                <div class="fs-2hx fw-bolder text-warning mb-1">DRAFT</div>
                                <span class="text-muted fs-8">Ready for cost apportionment</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Apportionment Setup Form -->
            <div class="row g-5 g-xl-8 mb-6">
                <div class="col-12 col-xl-7">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-light">
                            <h3 class="card-title fw-bolder text-dark">1. Compute & Apportion Power Cost</h3>
                        </div>
                        <div class="card-body p-6">
                            <form method="post" action="utility_apportionment.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                                <input type="hidden" name="billing_month" value="<?= e($month) ?>">

                                <div class="row g-3 mb-4">
                                    <div class="col-6">
                                        <label class="form-label required">Total Diesel Cost (₦)</label>
                                        <input type="number" step="0.01" name="total_diesel_cost" id="app_diesel_cost" class="form-control" value="<?= (float)($dieselStats['total_diesel_spent'] ?? 0) ?>" required oninput="recalcApportionment()">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Grid Electricity Bill (₦)</label>
                                        <input type="number" step="0.01" name="total_grid_cost" id="app_grid_cost" class="form-control" placeholder="e.g. 450000" value="0" oninput="recalcApportionment()">
                                    </div>
                                </div>

                                <div class="mb-4 bg-light-primary rounded p-4">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-dark fs-6">Total Billable Power Cost:</span>
                                        <strong id="app_total_display" class="text-primary fs-3">₦<?= number_format((float)($dieselStats['total_diesel_spent'] ?? 0), 2) ?></strong>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-6">
                                        <label class="form-label required">Apportionment Method</label>
                                        <select name="apportionment_method" class="form-select" id="app_method" onchange="recalcApportionment()">
                                            <option value="equal_split_by_unit">Equal Split (per Unit)</option>
                                            <option value="weighted_by_bedrooms">Weighted by Bedroom Count</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label required">Invoice Due Date</label>
                                        <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+14 days')) ?>" required>
                                    </div>
                                </div>

                                <div class="form-check form-switch mb-5">
                                    <input class="form-check-input" type="checkbox" name="send_sms" value="1" checked id="checkSms">
                                    <label class="form-check-label fw-bold" for="checkSms">Dispatch instant SMS & WhatsApp notifications to residents</label>
                                </div>

                                <button type="submit" class="btn btn-success w-100 py-3 fs-6 fw-bold">
                                    <i class="ki-duotone ki-send fs-4 me-1"><span class="path1"></span><span class="path2"></span></i>
                                    Generate & Dispatch Power Invoices to <?= count($activeTenants) ?> Tenants
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Live Unit Apportionment Preview Table -->
                <div class="col-12 col-xl-5">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-light">
                            <h3 class="card-title fw-bolder text-dark">Resident Allocation Preview</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($activeTenants)): ?>
                                <p class="text-muted p-5 mb-0">No active tenants to preview.</p>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                                    <table class="table table-row-bordered table-row-gray-200 align-middle gs-5 gy-3 mb-0 fs-7">
                                        <thead class="bg-light text-muted fw-bold">
                                            <tr>
                                                <th>Unit & Tenant</th>
                                                <th>Beds</th>
                                                <th class="text-end">Estimated Share</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activeTenants as $t): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= e($t['unit_number']) ?></strong>
                                                        <div class="text-muted fs-8"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></div>
                                                    </td>
                                                    <td><span class="badge badge-light"><?= (int)$t['bedrooms'] ?> Bed</span></td>
                                                    <td class="text-end fw-bold text-primary unit-share-cell" data-beds="<?= max(1, (int)$t['bedrooms']) ?>">
                                                        —
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
    </div>
</div>

<script>
function recalcApportionment() {
    var diesel = parseFloat(document.getElementById('app_diesel_cost').value) || 0;
    var grid = parseFloat(document.getElementById('app_grid_cost').value) || 0;
    var total = diesel + grid;
    document.getElementById('app_total_display').textContent = '₦' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    var method = document.getElementById('app_method').value;
    var cells = document.querySelectorAll('.unit-share-cell');
    var totalUnits = cells.length;
    if (totalUnits === 0) return;

    var totalBeds = 0;
    cells.forEach(function(c) {
        totalBeds += parseInt(c.getAttribute('data-beds'), 10) || 1;
    });

    cells.forEach(function(c) {
        var beds = parseInt(c.getAttribute('data-beds'), 10) || 1;
        var share = (method === 'weighted_by_bedrooms' && totalBeds > 0)
            ? (total * beds) / totalBeds
            : total / totalUnits;
        c.textContent = '₦' + share.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });
}

document.addEventListener('DOMContentLoaded', recalcApportionment);
</script>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
