<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'accountant', 'property_manager']);

$pageTitle = 'Generator Diesel & Power Management – EstatePro';
$pageHeading = 'Power & Diesel Management';
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
$action = (string)(get_param('action', '') ?? '');

// Handle Actions
if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');
    $estateIdPost = (int)(post_param('estate_id', $estateId) ?? $estateId);
    assert_can_access_estate($estateIdPost);

    // 1. Add / Update Generator
    if ($postAction === 'save_generator') {
        $genId = (int)(post_param('generator_id', 0) ?? 0);
        $name = trim((string)post_param('name', ''));
        $capacity = (float)(post_param('capacity_kva', 0) ?? 0);
        $fuelType = (string)post_param('fuel_type', 'diesel');
        $avgConsumption = (float)(post_param('avg_consumption', 25) ?? 25);
        $currentHours = (float)(post_param('current_run_hours', 0) ?? 0);
        $serviceInterval = (float)(post_param('service_interval', 250) ?? 250);
        $tankCapacity = (float)(post_param('tank_capacity', 1000) ?? 1000);
        $currentFuel = (float)(post_param('current_fuel', 500) ?? 500);
        $status = (string)post_param('status', 'active');

        if ($name === '') {
            flash_set('error', 'Generator name is required.');
            redirect('generator_diesel.php?estate_id=' . $estateIdPost);
        }

        if ($genId > 0) {
            $db->execute(
                "UPDATE generators SET 
                 name = ?, capacity_kva = ?, fuel_type = ?, avg_consumption_litres_per_hour = ?,
                 current_run_hours = ?, service_interval_hours = ?, tank_capacity_litres = ?,
                 current_fuel_litres = ?, status = ?
                 WHERE id = ? AND estate_id = ?",
                [$name, $capacity, $fuelType, $avgConsumption, $currentHours, $serviceInterval, $tankCapacity, $currentFuel, $status, $genId, $estateIdPost]
            );
            flash_set('success', 'Generator updated successfully.');
        } else {
            $db->execute(
                "INSERT INTO generators 
                 (estate_id, name, capacity_kva, fuel_type, avg_consumption_litres_per_hour, current_run_hours, service_interval_hours, tank_capacity_litres, current_fuel_litres, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$estateIdPost, $name, $capacity, $fuelType, $avgConsumption, $currentHours, $serviceInterval, $tankCapacity, $currentFuel, $status]
            );
            flash_set('success', 'New generator registered successfully.');
        }
        redirect('generator_diesel.php?estate_id=' . $estateIdPost);
    }

    // 2. Log Generator Runtime
    if ($postAction === 'log_runtime') {
        $genId = (int)(post_param('generator_id', 0) ?? 0);
        $startTime = (string)post_param('start_time', '');
        $endTime = (string)post_param('end_time', '');
        $operator = trim((string)post_param('operator_name', ''));
        $notes = trim((string)post_param('notes', ''));

        if ($genId <= 0 || empty($startTime) || empty($endTime) || empty($operator)) {
            flash_set('error', 'Generator, start/end time, and duty operator name are required.');
            redirect('generator_diesel.php?estate_id=' . $estateIdPost);
        }

        $startTs = strtotime($startTime);
        $endTs = strtotime($endTime);
        if ($endTs <= $startTs) {
            flash_set('error', 'End time must be after start time.');
            redirect('generator_diesel.php?estate_id=' . $estateIdPost);
        }

        $durationHours = round(($endTs - $startTs) / 3600, 2);

        $gen = $db->fetchOne('SELECT current_run_hours, avg_consumption_litres_per_hour, current_fuel_litres FROM generators WHERE id = ?', [$genId]);
        $startHours = (float)($gen['current_run_hours'] ?? 0);
        $endHours = $startHours + $durationHours;
        $fuelUsed = round($durationHours * (float)($gen['avg_consumption_litres_per_hour'] ?? 25), 2);

        $db->execute(
            "INSERT INTO generator_logs 
             (estate_id, generator_id, start_time, end_time, duration_hours, run_hours_start, run_hours_end, fuel_consumed_litres, duty_operator_name, logged_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$estateIdPost, $genId, $startTime, $endTime, $durationHours, $startHours, $endHours, $fuelUsed, $operator, $userId, $notes]
        );

        // Update generator run hours and fuel level
        $newFuel = max(0, (float)($gen['current_fuel_litres'] ?? 0) - $fuelUsed);
        $db->execute('UPDATE generators SET current_run_hours = ?, current_fuel_litres = ? WHERE id = ?', [$endHours, $newFuel, $genId]);

        flash_set('success', "Runtime logged: {$durationHours} hrs. Estimated fuel consumed: {$fuelUsed} Litres.");
        redirect('generator_diesel.php?estate_id=' . $estateIdPost);
    }

    // 3. Record Diesel Purchase & Delivery (with Accounting Expense Sync)
    if ($postAction === 'receive_diesel') {
        $genId = (int)(post_param('generator_id', 0) ?? 0);
        $date = (string)post_param('purchase_date', date('Y-m-d'));
        $litres = (float)(post_param('litres', 0) ?? 0);
        $costPerLitre = (float)(post_param('cost_per_litre', 0) ?? 0);
        $supplier = trim((string)post_param('supplier_name', ''));
        $deliveryRef = trim((string)post_param('delivery_note_ref', ''));
        $syncExpense = (int)(post_param('sync_accounting', 1) ?? 1);
        $notes = trim((string)post_param('notes', ''));

        if ($litres <= 0 || $costPerLitre <= 0 || empty($supplier)) {
            flash_set('error', 'Litres, price per litre, and supplier name are required.');
            redirect('generator_diesel.php?estate_id=' . $estateIdPost);
        }

        $totalAmount = round($litres * $costPerLitre, 2);

        // Handle Receipt Upload
        $receiptPath = null;
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                $filename = 'diesel_' . $estateIdPost . '_' . time() . '.' . $ext;
                $target = __DIR__ . '/../../uploads/receipts/' . $filename;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $target)) {
                    $receiptPath = 'uploads/receipts/' . $filename;
                }
            }
        }

        // Optional: Auto-create Expense Voucher in Accounting Suite
        $expenseId = null;
        if ($syncExpense && function_exists('can_manage_finance')) {
            $cat = $db->fetchOne("SELECT id FROM expense_categories WHERE estate_id = ? AND code = 'EXP_DIESEL'", [$estateIdPost]);
            $catId = $cat ? (int)$cat['id'] : null;

            $voucherNo = 'VOUCH-' . date('Ymd') . '-' . random_int(100, 999);
            $db->execute(
                "INSERT INTO expenses 
                 (estate_id, voucher_number, category_id, vendor_name, description, amount, net_amount, payment_status, recorded_by, receipt_path, expense_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?)",
                [
                    $estateIdPost,
                    $voucherNo,
                    $catId,
                    $supplier,
                    "Diesel Purchase: {$litres} Litres @ ₦{$costPerLitre}/L (Ref: {$deliveryRef})",
                    $totalAmount,
                    $totalAmount,
                    $userId,
                    $receiptPath,
                    $date
                ]
            );
            $expenseId = (int)$db->getConnection()->lastInsertId();
        }

        $db->execute(
            "INSERT INTO diesel_purchases 
             (estate_id, generator_id, purchase_date, litres, cost_per_litre, total_amount, supplier_name, delivery_note_ref, receipt_path, recorded_by, expense_id, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received', ?)",
            [$estateIdPost, $genId > 0 ? $genId : null, $date, $litres, $costPerLitre, $totalAmount, $supplier, $deliveryRef, $receiptPath, $userId, $expenseId, $notes]
        );

        // If assigned to a generator, update its current fuel
        if ($genId > 0) {
            $db->execute('UPDATE generators SET current_fuel_litres = LEAST(tank_capacity_litres, current_fuel_litres + ?) WHERE id = ?', [$litres, $genId]);
        }

        flash_set('success', "Diesel delivery logged: {$litres} Litres (₦" . number_format($totalAmount, 2) . ") synced to Accounting!");
        redirect('generator_diesel.php?estate_id=' . $estateIdPost);
    }
}

// Fetch Estate Generators
$generators = $db->fetchAll(
    "SELECT g.*, 
     (g.current_run_hours - g.last_service_hours) AS hours_since_service,
     (g.service_interval_hours - (g.current_run_hours - g.last_service_hours)) AS hours_until_service
     FROM generators g 
     WHERE g.estate_id = ? 
     ORDER BY g.status = 'active' DESC, g.name ASC",
    [$estateId]
) ?: [];

// Fetch Recent Generator Runtime Logs
$runtimeLogs = $db->fetchAll(
    "SELECT gl.*, g.name AS generator_name, u.first_name, u.last_name
     FROM generator_logs gl
     INNER JOIN generators g ON g.id = gl.generator_id
     LEFT JOIN users u ON u.id = gl.logged_by
     WHERE gl.estate_id = ?
     ORDER BY gl.start_time DESC
     LIMIT 15",
    [$estateId]
) ?: [];

// Fetch Recent Diesel Purchases
$purchases = $db->fetchAll(
    "SELECT dp.*, g.name AS generator_name, u.first_name, u.last_name
     FROM diesel_purchases dp
     LEFT JOIN generators g ON g.id = dp.generator_id
     LEFT JOIN users u ON u.id = dp.recorded_by
     WHERE dp.estate_id = ?
     ORDER BY dp.purchase_date DESC
     LIMIT 15",
    [$estateId]
) ?: [];

// Summary KPIs
$totalStockLitres = 0;
$totalTankCapacity = 0;
foreach ($generators as $gen) {
    $totalStockLitres += (float)$gen['current_fuel_litres'];
    $totalTankCapacity += (float)$gen['tank_capacity_litres'];
}
$stockPercent = $totalTankCapacity > 0 ? round(($totalStockLitres / $totalTankCapacity) * 100, 1) : 0;

// Monthly Litres & Runtime (Current Month)
$curMonth = date('Y-m');
$monthlyStats = $db->fetchOne(
    "SELECT 
        COALESCE(SUM(duration_hours), 0) AS total_hours,
        COALESCE(SUM(fuel_consumed_litres), 0) AS total_fuel
     FROM generator_logs
     WHERE estate_id = ? AND DATE_FORMAT(start_time, '%Y-%m') = ?",
    [$estateId, $curMonth]
);

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Header & Estate Selector -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-electricity fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Generator Diesel & Power Hub</h1>
                                <span class="text-muted fs-7">Real-time fuel storage levels, generator run-hour tracking, and maintenance alerts</span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm w-200px" onchange="location.href='generator_diesel.php?estate_id=' + this.value">
                                <?php foreach ($estates as $est): ?>
                                    <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                        <?= e($est['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalLogRuntime">
                                <i class="ki-duotone ki-time fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> Log Runtime
                            </button>
                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalReceiveDiesel">
                                <i class="ki-duotone ki-drop fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> Receive Fuel
                            </button>
                            <a href="utility_apportionment.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-info">
                                <i class="ki-duotone ki-calculator fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> Bill Residents
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <!-- Fuel Stock Level -->
                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted fw-bold fs-7 text-uppercase">Fuel In Stock</span>
                                <span class="badge badge-light-<?= $stockPercent < 25 ? 'danger' : ($stockPercent < 50 ? 'warning' : 'success') ?>"><?= $stockPercent ?>% Full</span>
                            </div>
                            <div class="fs-2hx fw-bolder text-dark mb-1"><?= number_format($totalStockLitres, 0) ?> <span class="fs-6 text-muted">Litres</span></div>
                            <div class="progress h-6px bg-light-primary mb-2">
                                <div class="progress-bar bg-primary" style="width: <?= min(100, $stockPercent) ?>%"></div>
                            </div>
                            <span class="text-muted fs-8">Total Tank Capacity: <?= number_format($totalTankCapacity, 0) ?> L</span>
                        </div>
                    </div>
                </div>

                <!-- Monthly Run Hours -->
                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Runtime This Month</span>
                            <div class="fs-2hx fw-bolder text-dark mb-1"><?= number_format((float)($monthlyStats['total_hours'] ?? 0), 1) ?> <span class="fs-6 text-muted">Hrs</span></div>
                            <span class="text-muted fs-8">Month of <?= date('F Y') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Fuel Consumed MTD -->
                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fw-bold fs-7 text-uppercase">Fuel Consumed MTD</span>
                            <div class="fs-2hx fw-bolder text-danger mb-1"><?= number_format((float)($monthlyStats['total_fuel'] ?? 0), 0) ?> <span class="fs-6 text-muted">L</span></div>
                            <span class="text-muted fs-8">Calculated from operator logs</span>
                        </div>
                    </div>
                </div>

                <!-- Active Generator Fleet -->
                <div class="col-12 col-md-3">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-5">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="text-muted fw-bold fs-7 text-uppercase">Generators</span>
                                <button class="btn btn-xs btn-light-primary" data-bs-toggle="modal" data-bs-target="#modalAddGen">+ Add</button>
                            </div>
                            <div class="fs-2hx fw-bolder text-primary mb-1"><?= count($generators) ?> <span class="fs-6 text-muted">Units</span></div>
                            <span class="text-muted fs-8">Ready for automatic dispatch</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Generator Fleet Grid -->
            <div class="row g-5 g-xl-8 mb-6">
                <?php foreach ($generators as $gen): ?>
                    <?php
                    $hrsUntilService = (float)$gen['hours_until_service'];
                    $serviceStatusClass = $hrsUntilService < 20 ? 'danger' : ($hrsUntilService < 50 ? 'warning' : 'success');
                    $fuelPct = (float)$gen['tank_capacity_litres'] > 0 ? round(((float)$gen['current_fuel_litres'] / (float)$gen['tank_capacity_litres']) * 100) : 0;
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="card card-flush shadow-sm border-0">
                            <div class="card-header border-0 pt-5">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="symbol symbol-40px symbol-circle bg-light-primary">
                                        <i class="ki-duotone ki-switch fs-2 text-primary"><span class="path1"></span><span class="path2"></span></i>
                                    </div>
                                    <div>
                                        <h4 class="card-title fw-bolder text-dark mb-0"><?= e($gen['name']) ?></h4>
                                        <span class="text-muted fs-8"><?= (float)$gen['capacity_kva'] ?> kVA &bull; <?= ucfirst((string)$gen['fuel_type']) ?></span>
                                    </div>
                                </div>
                                <span class="badge badge-light-<?= $gen['status'] === 'active' ? 'success' : ($gen['status'] === 'standby' ? 'primary' : 'danger') ?>">
                                    <?= strtoupper((string)$gen['status']) ?>
                                </span>
                            </div>
                            <div class="card-body pt-2 pb-5">
                                <div class="row g-2 mb-4 bg-light rounded p-3">
                                    <div class="col-6">
                                        <span class="text-muted fs-8 d-block">Current Run Hours</span>
                                        <strong class="text-dark fs-6"><?= number_format((float)$gen['current_run_hours'], 1) ?> hrs</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted fs-8 d-block">Fuel Consumption</span>
                                        <strong class="text-dark fs-6"><?= (float)$gen['avg_consumption_litres_per_hour'] ?> L/hr</strong>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between fs-8 fw-bold mb-1">
                                        <span>Fuel Tank (<?= number_format((float)$gen['current_fuel_litres'], 0) ?> / <?= number_format((float)$gen['tank_capacity_litres'], 0) ?> L)</span>
                                        <span><?= $fuelPct ?>%</span>
                                    </div>
                                    <div class="progress h-6px">
                                        <div class="progress-bar bg-success" style="width: <?= $fuelPct ?>%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="d-flex justify-content-between fs-8 fw-bold mb-1">
                                        <span>Service Maintenance Due:</span>
                                        <span class="text-<?= $serviceStatusClass ?>"><?= $hrsUntilService > 0 ? number_format($hrsUntilService, 1) . ' hrs left' : 'OVERDUE' ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Two Column Tables: Runtime Logs & Diesel Purchases -->
            <div class="row g-5 g-xl-8">
                <!-- Runtime Logs -->
                <div class="col-12 col-xl-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header">
                            <h3 class="card-title fw-bolder text-dark">Recent Runtime Logs</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($runtimeLogs)): ?>
                                <p class="text-muted p-5 mb-0">No runtime logged yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-row-bordered table-row-gray-200 align-middle gs-5 gy-3 mb-0 fs-7">
                                        <thead class="bg-light text-muted fw-bold">
                                            <tr>
                                                <th>Generator</th>
                                                <th>Session Period</th>
                                                <th>Runtime</th>
                                                <th>Fuel Used</th>
                                                <th>Operator</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($runtimeLogs as $log): ?>
                                                <tr>
                                                    <td><strong><?= e($log['generator_name']) ?></strong></td>
                                                    <td>
                                                        <div><?= date('M j, Y', strtotime($log['start_time'])) ?></div>
                                                        <span class="text-muted fs-8"><?= date('h:i A', strtotime($log['start_time'])) ?> – <?= date('h:i A', strtotime($log['end_time'])) ?></span>
                                                    </td>
                                                    <td><span class="badge badge-light-primary"><?= (float)$log['duration_hours'] ?> hrs</span></td>
                                                    <td><strong class="text-danger">-<?= number_format((float)$log['fuel_consumed_litres'], 1) ?> L</strong></td>
                                                    <td><?= e($log['duty_operator_name']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Diesel Delivery Purchases -->
                <div class="col-12 col-xl-6">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header">
                            <h3 class="card-title fw-bolder text-dark">Fuel Delivery & Purchases</h3>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($purchases)): ?>
                                <p class="text-muted p-5 mb-0">No diesel purchases logged yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-row-bordered table-row-gray-200 align-middle gs-5 gy-3 mb-0 fs-7">
                                        <thead class="bg-light text-muted fw-bold">
                                            <tr>
                                                <th>Date</th>
                                                <th>Supplier / Note</th>
                                                <th>Litres</th>
                                                <th>Rate (₦/L)</th>
                                                <th>Total (₦)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($purchases as $p): ?>
                                                <tr>
                                                    <td><?= date('M j, Y', strtotime($p['purchase_date'])) ?></td>
                                                    <td>
                                                        <strong><?= e($p['supplier_name']) ?></strong>
                                                        <?php if (!empty($p['delivery_note_ref'])): ?>
                                                            <div class="text-muted fs-8">Ref: <?= e($p['delivery_note_ref']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge badge-light-success">+<?= number_format((float)$p['litres'], 0) ?> L</span></td>
                                                    <td>₦<?= number_format((float)$p['cost_per_litre'], 2) ?></td>
                                                    <td><strong>₦<?= number_format((float)$p['total_amount'], 2) ?></strong></td>
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

<!-- Modal 1: Log Generator Runtime -->
<div class="modal fade" id="modalLogRuntime" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="generator_diesel.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="log_runtime">
                <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Log Generator Runtime Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <div class="mb-4">
                        <label class="form-label required">Generator</label>
                        <select name="generator_id" class="form-select" required>
                            <?php foreach ($generators as $g): ?>
                                <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?> (<?= (float)$g['capacity_kva'] ?> kVA)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Start Time</label>
                            <input type="datetime-local" name="start_time" class="form-control" required value="<?= date('Y-m-d\TH:00', strtotime('-4 hours')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label required">End Time</label>
                            <input type="datetime-local" name="end_time" class="form-control" required value="<?= date('Y-m-d\TH:00') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label required">Duty Technician / Operator</label>
                        <input type="text" name="operator_name" class="form-control" placeholder="e.g. Ibrahim Musa" required>
                    </div>
                    <div>
                        <label class="form-label">Operational Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Ran during national grid outage. Generator ran smooth."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Runtime Log</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Receive Diesel Delivery -->
<div class="modal fade" id="modalReceiveDiesel" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="generator_diesel.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="receive_diesel">
                <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Receive Diesel Fuel Delivery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Assign To Generator</label>
                            <select name="generator_id" class="form-select">
                                <option value="0">Main Storage Tank</option>
                                <?php foreach ($generators as $g): ?>
                                    <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Litres Supplied</label>
                            <input type="number" step="0.01" name="litres" class="form-control" placeholder="e.g. 2000" required id="diesel_litres" oninput="calcDieselTotal()">
                        </div>
                        <div class="col-6">
                            <label class="form-label required">Rate Per Litre (₦)</label>
                            <input type="number" step="0.01" name="cost_per_litre" class="form-control" placeholder="e.g. 1250" required id="diesel_rate" oninput="calcDieselTotal()">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Total Amount: <strong id="diesel_total_display" class="text-primary fs-5">₦0.00</strong></label>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Supplier Name</label>
                            <input type="text" name="supplier_name" class="form-control" placeholder="e.g. TotalEnergies / MRS" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Waybill / Invoice Ref</label>
                            <input type="text" name="delivery_note_ref" class="form-control" placeholder="e.g. WB-9921">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Waybill / Receipt Scan</label>
                        <input type="file" name="receipt_image" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="sync_accounting" value="1" checked id="checkSync">
                        <label class="form-check-label fw-bold" for="checkSync">Automatically sync to Accounting Expense Vouchers</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Delivery</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: Add / Register Generator -->
<div class="modal fade" id="modalAddGen" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="generator_diesel.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_generator">
                <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Register Generator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-6">
                    <div class="mb-4">
                        <label class="form-label required">Generator Name / Model</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. 500kVA CAT Soundproof Generator" required>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Capacity (kVA)</label>
                            <input type="number" step="0.1" name="capacity_kva" class="form-control" value="250" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label required">Fuel Type</label>
                            <select name="fuel_type" class="form-select">
                                <option value="diesel">Diesel</option>
                                <option value="gas">Gas</option>
                                <option value="solar_hybrid">Solar Hybrid</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Avg Litres / Hour</label>
                            <input type="number" step="0.1" name="avg_consumption" class="form-control" value="25" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label required">Tank Capacity (L)</label>
                            <input type="number" step="1" name="tank_capacity" class="form-control" value="1000" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label required">Current Run Hours</label>
                            <input type="number" step="0.1" name="current_run_hours" class="form-control" value="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label required">Service Interval (Hrs)</label>
                            <input type="number" step="1" name="service_interval" class="form-control" value="250" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Register Generator</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function calcDieselTotal() {
    var litres = parseFloat(document.getElementById('diesel_litres').value) || 0;
    var rate = parseFloat(document.getElementById('diesel_rate').value) || 0;
    var total = litres * rate;
    document.getElementById('diesel_total_display').textContent = '₦' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
