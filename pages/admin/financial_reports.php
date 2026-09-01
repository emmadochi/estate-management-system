<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Financial Statements & Reports – EstatePro';
$pageHeading = 'Financial Reports';
$db = db();

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
$activeTab = (string)(get_param('tab', 'pnl') ?? 'pnl');

// Date ranges
$from = (string)(get_param('from', date('Y-01-01')) ?? date('Y-01-01'));
$to = (string)(get_param('to', date('Y-m-d')) ?? date('Y-m-d'));

// Format validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');

// Scope conditions
$paymentEstateCond = $estateId > 0 ? " AND p.estate_id = {$estateId}" : "";
$expenseEstateCond = $estateId > 0 ? " AND e.estate_id = {$estateId}" : "";
$invoiceEstateCond = $estateId > 0 ? " AND i.estate_id = {$estateId}" : "";

// ==========================================
// 1. PROFIT & LOSS (INCOME STATEMENT) DATA
// ==========================================
// Revenue breakdown by invoice type from completed payments
$revenueRows = $db->fetchAll(
    "SELECT i.type, COALESCE(SUM(p.amount), 0) AS total_amount, COUNT(p.id) AS count_payments
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     WHERE p.status = 'completed'
       AND p.payment_date BETWEEN ? AND ?
       {$paymentEstateCond}
     GROUP BY i.type
     ORDER BY total_amount DESC",
    [$from . ' 00:00:00', $to . ' 23:59:59']
) ?: [];

$totalRevenue = 0;
foreach ($revenueRows as $rr) {
    $totalRevenue += (float)$rr['total_amount'];
}

// Expenses breakdown by category
$expenseRows = $db->fetchAll(
    "SELECT ec.name AS category_name, ec.type AS category_type,
            COALESCE(SUM(e.total_amount), 0) AS total_spent, COUNT(e.id) AS count_vouchers
     FROM expenses e
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     WHERE e.payment_status IN ('approved', 'paid')
       AND e.expense_date BETWEEN ? AND ?
       {$expenseEstateCond}
     GROUP BY ec.id, ec.name, ec.type
     ORDER BY ec.type ASC, total_spent DESC",
    [$from, $to]
) ?: [];

$totalOpEx = 0;
$totalCapEx = 0;
$totalAdminEx = 0;

foreach ($expenseRows as $er) {
    $amt = (float)$er['total_spent'];
    if ($er['category_type'] === 'capital') {
        $totalCapEx += $amt;
    } elseif ($er['category_type'] === 'administrative') {
        $totalAdminEx += $amt;
    } else {
        $totalOpEx += $amt;
    }
}

$totalAllExpenses = $totalOpEx + $totalCapEx + $totalAdminEx;
$netOperatingIncome = $totalRevenue - $totalAllExpenses;
$profitMargin = $totalRevenue > 0 ? round(($netOperatingIncome / $totalRevenue) * 100, 1) : 0;

// ==========================================
// 2. ACCOUNTS RECEIVABLE (AR) AGING DATA
// ==========================================
$agingRows = $db->fetchAll(
    "SELECT i.id, i.invoice_number, i.type, i.amount, i.paid_amount, (i.amount - i.paid_amount) AS balance_due,
            i.due_date, DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
            t.id AS tenant_id, u.first_name, u.last_name, u.phone, u.email,
            un.unit_number, est.name AS estate_name
     FROM invoices i
     INNER JOIN tenants t ON t.id = i.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN units un ON un.id = i.unit_id
     INNER JOIN estates est ON est.id = i.estate_id
     WHERE i.status IN ('pending', 'overdue', 'partial')
       AND (i.amount - i.paid_amount) > 0
       {$invoiceEstateCond}
     ORDER BY days_overdue DESC"
) ?: [];

$arSummary = [
    'current' => 0,
    'days_1_30' => 0,
    'days_31_60' => 0,
    'days_61_90' => 0,
    'days_90_plus' => 0,
    'total' => 0
];

foreach ($agingRows as $ar) {
    $bal = (float)$ar['balance_due'];
    $days = (int)$ar['days_overdue'];
    $arSummary['total'] += $bal;

    if ($days <= 0) {
        $arSummary['current'] += $bal;
    } elseif ($days <= 30) {
        $arSummary['days_1_30'] += $bal;
    } elseif ($days <= 60) {
        $arSummary['days_31_60'] += $bal;
    } elseif ($days <= 90) {
        $arSummary['days_61_90'] += $bal;
    } else {
        $arSummary['days_90_plus'] += $bal;
    }
}

// ==========================================
// 3. SERVICE CHARGE RECONCILIATION
// ==========================================
$scCollectionsSql = "SELECT COALESCE(SUM(p.amount), 0) AS total_sc_collected
                     FROM payments p
                     INNER JOIN invoices i ON i.id = p.invoice_id
                     WHERE p.status = 'completed'
                       AND i.type = 'service_charge'
                       AND p.payment_date BETWEEN ? AND ?
                       {$paymentEstateCond}";
$scCollected = (float)($db->fetchOne($scCollectionsSql, [$from . ' 00:00:00', $to . ' 23:59:59'])['total_sc_collected'] ?? 0);

$scExpenseSql = "SELECT ec.name, COALESCE(SUM(e.total_amount), 0) AS amount
                 FROM expenses e
                 INNER JOIN expense_categories ec ON ec.id = e.category_id
                 WHERE e.payment_status IN ('approved', 'paid')
                   AND ec.type IN ('operating', 'utility', 'maintenance')
                   AND e.expense_date BETWEEN ? AND ?
                   {$expenseEstateCond}
                 GROUP BY ec.id, ec.name
                 ORDER BY amount DESC";
$scExpenses = $db->fetchAll($scExpenseSql, [$from, $to]) ?: [];

$totalScExpense = 0;
foreach ($scExpenses as $se) {
    $totalScExpense += (float)$se['amount'];
}
$scSurplusDeficit = $scCollected - $totalScExpense;

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Filter and Export Bar -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <form method="get" action="financial_reports.php" class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                        <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-document fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Financial Statements & Audit Hub</h1>
                                <span class="text-muted fs-7">Audit-ready Income Statements, Aging Schedules & Service Charge Reconciliation</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <!-- Estate Filter -->
                            <select name="estate_id" class="form-select form-select-sm w-200px" onchange="this.form.submit()">
                                <?php if ($isSuper): ?>
                                    <option value="0" <?= $estateId === 0 ? 'selected' : '' ?>>All Estates (Consolidated)</option>
                                <?php endif; ?>
                                <?php foreach ($estates as $est): ?>
                                    <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                        <?= e($est['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Date Range Filters -->
                            <div class="d-flex align-items-center gap-2">
                                <input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm w-130px" title="From Date">
                                <span class="text-muted">to</span>
                                <input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm w-130px" title="To Date">
                                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                            </div>

                            <!-- Print / PDF Button -->
                            <button type="button" onclick="window.print()" class="btn btn-sm btn-light-dark">
                                <i class="ki-duotone ki-printer fs-4 me-1"></i> Print / PDF
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Report Navigation Tabs -->
            <div class="card shadow-sm border-0 mb-6">
                <div class="card-header card-header-stretch">
                    <div class="card-toolbar">
                        <ul class="nav nav-tabs nav-line-tabs nav-stretch fs-6 border-0 fw-bolder">
                            <li class="nav-item">
                                <a class="nav-link <?= $activeTab === 'pnl' ? 'active text-primary' : 'text-muted' ?>" 
                                   href="financial_reports.php?tab=pnl&estate_id=<?= $estateId ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">
                                    <i class="ki-duotone ki-bill fs-4 me-2"><span class="path1"></span><span class="path2"></span></i>
                                    Income Statement (Profit & Loss)
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $activeTab === 'aging' ? 'active text-primary' : 'text-muted' ?>" 
                                   href="financial_reports.php?tab=aging&estate_id=<?= $estateId ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">
                                    <i class="ki-duotone ki-time fs-4 me-2"><span class="path1"></span><span class="path2"></span></i>
                                    Accounts Receivable Aging
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= $activeTab === 'service_charge' ? 'active text-primary' : 'text-muted' ?>" 
                                   href="financial_reports.php?tab=service_charge&estate_id=<?= $estateId ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">
                                    <i class="ki-duotone ki-element-11 fs-4 me-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                                    Service Charge Reconciliation
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card-body p-6">
                    <!-- ============================================================ -->
                    <!-- TAB 1: PROFIT & LOSS / INCOME STATEMENT                      -->
                    <!-- ============================================================ -->
                    <?php if ($activeTab === 'pnl'): ?>
                        <div class="pnl-statement">
                            <div class="text-center mb-6">
                                <h2 class="fw-bolder text-gray-900 mb-1">Statement of Comprehensive Income (Profit & Loss)</h2>
                                <div class="text-muted fs-6">
                                    For the period from <strong><?= date('M d, Y', strtotime($from)) ?></strong> to <strong><?= date('M d, Y', strtotime($to)) ?></strong>
                                </div>
                            </div>

                            <!-- Summary KPIs -->
                            <div class="row g-4 mb-6">
                                <div class="col-12 col-md-4">
                                    <div class="p-4 bg-light-success rounded border border-success border-dashed">
                                        <div class="fs-7 text-success fw-bold text-uppercase">Total Collections Revenue</div>
                                        <div class="fs-2x fw-bolder text-gray-900"><?= format_money($totalRevenue) ?></div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-4 bg-light-danger rounded border border-danger border-dashed">
                                        <div class="fs-7 text-danger fw-bold text-uppercase">Total Operating & CapEx Outflows</div>
                                        <div class="fs-2x fw-bolder text-gray-900"><?= format_money($totalAllExpenses) ?></div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-4 <?= $netOperatingIncome >= 0 ? 'bg-light-primary border-primary' : 'bg-light-warning border-warning' ?> rounded border border-dashed">
                                        <div class="fs-7 <?= $netOperatingIncome >= 0 ? 'text-primary' : 'text-warning' ?> fw-bold text-uppercase">
                                            Net Operating Surplus / (Deficit)
                                        </div>
                                        <div class="fs-2x fw-bolder <?= $netOperatingIncome >= 0 ? 'text-primary' : 'text-warning' ?>">
                                            <?= format_money($netOperatingIncome) ?> <span class="fs-7 text-muted">(Margin: <?= $profitMargin ?>%)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Income Statement Table -->
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle gs-4 gy-3">
                                    <thead class="bg-dark text-white">
                                        <tr class="fw-bolder fs-6">
                                            <th>Account Description</th>
                                            <th class="text-center w-150px">Volume</th>
                                            <th class="text-end w-200px">Amount (₦)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="fs-6">
                                        <!-- REVENUE SECTION -->
                                        <tr class="bg-light-primary fw-bolder text-primary">
                                            <td colspan="3"><i class="ki-duotone ki-arrow-up fs-5 text-primary me-2"></i> 1. OPERATING REVENUE (INFLOWS)</td>
                                        </tr>
                                        <?php if (empty($revenueRows)): ?>
                                            <tr>
                                                <td colspan="3" class="text-muted ps-6">No completed payment transactions recorded in this period.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($revenueRows as $rev): ?>
                                                <tr>
                                                    <td class="ps-6">
                                                        <span class="fw-semibold text-gray-800"><?= e(ucwords(str_replace('_', ' ', $rev['type']))) ?> Revenue</span>
                                                    </td>
                                                    <td class="text-center text-muted"><?= number_format((int)$rev['count_payments']) ?> receipts</td>
                                                    <td class="text-end fw-bold text-success"><?= format_money((float)$rev['total_amount']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <tr class="fw-bolder bg-light">
                                            <td colspan="2" class="text-end text-uppercase">Total Operating Revenue</td>
                                            <td class="text-end text-success fs-5"><?= format_money($totalRevenue) ?></td>
                                        </tr>

                                        <!-- OPERATING EXPENSES (OPEX) -->
                                        <tr class="bg-light-danger fw-bolder text-danger">
                                            <td colspan="3"><i class="ki-duotone ki-arrow-down fs-5 text-danger me-2"></i> 2. OPERATIONAL EXPENDITURES (OPEX)</td>
                                        </tr>
                                        <?php if (empty($expenseRows)): ?>
                                            <tr>
                                                <td colspan="3" class="text-muted ps-6">No approved operational disbursements recorded in this period.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($expenseRows as $exp): ?>
                                                <tr>
                                                    <td class="ps-6">
                                                        <span class="fw-semibold text-gray-800"><?= e($exp['category_name']) ?></span>
                                                        <span class="badge badge-light fs-9 ms-2 text-uppercase"><?= e($exp['category_type']) ?></span>
                                                    </td>
                                                    <td class="text-center text-muted"><?= number_format((int)$exp['count_vouchers']) ?> vouchers</td>
                                                    <td class="text-end fw-bold text-danger"><?= format_money((float)$exp['total_spent']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <tr class="fw-bolder bg-light">
                                            <td colspan="2" class="text-end text-uppercase">Total Operational Expenses</td>
                                            <td class="text-end text-danger fs-5"><?= format_money($totalAllExpenses) ?></td>
                                        </tr>

                                        <!-- NET OPERATING INCOME -->
                                        <tr class="bg-light-dark fw-bolder fs-5 text-dark border-top border-3 border-dark">
                                            <td colspan="2" class="text-end text-uppercase">NET OPERATING INCOME / (DEFICIT)</td>
                                            <td class="text-end <?= $netOperatingIncome >= 0 ? 'text-primary' : 'text-danger' ?>">
                                                <?= format_money($netOperatingIncome) ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ============================================================ -->
                    <!-- TAB 2: ACCOUNTS RECEIVABLE AGING                             -->
                    <!-- ============================================================ -->
                    <?php if ($activeTab === 'aging'): ?>
                        <div class="ar-aging-report">
                            <div class="text-center mb-6">
                                <h2 class="fw-bolder text-gray-900 mb-1">Accounts Receivable (AR) Aging Schedule</h2>
                                <div class="text-muted fs-6">Classification of outstanding tenant invoices by delinquency duration</div>
                            </div>

                            <!-- Aging Bucket Summary Cards -->
                            <div class="row g-3 mb-6 text-center">
                                <div class="col">
                                    <div class="p-3 bg-light-primary rounded border border-primary">
                                        <div class="fs-8 fw-bold text-primary text-uppercase">Current</div>
                                        <div class="fs-6 fw-bolder text-gray-900"><?= format_money($arSummary['current']) ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-3 bg-light-warning rounded border border-warning">
                                        <div class="fs-8 fw-bold text-warning text-uppercase">1 – 30 Days</div>
                                        <div class="fs-6 fw-bolder text-warning"><?= format_money($arSummary['days_1_30']) ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-3 bg-light-danger rounded border border-danger">
                                        <div class="fs-8 fw-bold text-danger text-uppercase">31 – 60 Days</div>
                                        <div class="fs-6 fw-bolder text-danger"><?= format_money($arSummary['days_31_60']) ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-3 bg-light-danger rounded border border-danger">
                                        <div class="fs-8 fw-bold text-danger text-uppercase">61 – 90 Days</div>
                                        <div class="fs-6 fw-bolder text-danger"><?= format_money($arSummary['days_61_90']) ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-3 bg-danger text-white rounded">
                                        <div class="fs-8 fw-bold text-white text-uppercase">90+ Days</div>
                                        <div class="fs-6 fw-bolder text-white"><?= format_money($arSummary['days_90_plus']) ?></div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="p-3 bg-dark text-white rounded">
                                        <div class="fs-8 fw-bold text-uppercase">Total Due</div>
                                        <div class="fs-6 fw-bolder text-white"><?= format_money($arSummary['total']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if (empty($agingRows)): ?>
                                <div class="text-center py-15 text-gray-500">
                                    <i class="ki-duotone ki-check-circle fs-3x text-success mb-3"><span class="path1"></span><span class="path2"></span></i>
                                    <div class="fs-5 fw-bold text-gray-800">No overdue receivables!</div>
                                    <div class="fs-7 text-muted">All issued invoices are currently settled in full.</div>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-row-dashed align-middle gs-0 gy-3">
                                        <thead>
                                            <tr class="fs-7 fw-bolder text-gray-500 text-uppercase border-bottom border-gray-200">
                                                <th>Tenant & Unit</th>
                                                <th>Invoice & Type</th>
                                                <th>Due Date</th>
                                                <th class="text-center">Days Overdue</th>
                                                <th class="text-end">Total Invoiced</th>
                                                <th class="text-end">Paid Amount</th>
                                                <th class="text-end">Outstanding Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody class="fs-6 fw-semibold text-gray-700">
                                            <?php foreach ($agingRows as $item): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold text-gray-900"><?= e($item['first_name'] . ' ' . $item['last_name']) ?></div>
                                                        <div class="text-muted fs-8">Unit <?= e($item['unit_number']) ?> &bull; <?= e($item['estate_name']) ?></div>
                                                        <?php if (!empty($item['phone'])): ?>
                                                            <div class="text-muted fs-9"><?= e($item['phone']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="invoices.php?estate_id=<?= (int)$item['tenant_id'] ?>" class="text-primary fw-bold">
                                                            <?= e($item['invoice_number']) ?>
                                                        </a>
                                                        <span class="badge badge-light fs-8 ms-1"><?= e(ucfirst($item['type'])) ?></span>
                                                    </td>
                                                    <td><?= date('M d, Y', strtotime($item['due_date'])) ?></td>
                                                    <td class="text-center">
                                                        <?php
                                                        $d = (int)$item['days_overdue'];
                                                        if ($d <= 0): ?>
                                                            <span class="badge badge-light-primary fs-8">Current</span>
                                                        <?php elseif ($d <= 30): ?>
                                                            <span class="badge badge-light-warning fs-8"><?= $d ?> days</span>
                                                        <?php elseif ($d <= 60): ?>
                                                            <span class="badge badge-light-danger fs-8"><?= $d ?> days</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-danger fs-8"><?= $d ?> days</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end text-gray-800"><?= format_money((float)$item['amount']) ?></td>
                                                    <td class="text-end text-success"><?= format_money((float)$item['paid_amount']) ?></td>
                                                    <td class="text-end fw-bolder text-danger fs-5"><?= format_money((float)$item['balance_due']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ============================================================ -->
                    <!-- TAB 3: SERVICE CHARGE RECONCILIATION                         -->
                    <!-- ============================================================ -->
                    <?php if ($activeTab === 'service_charge'): ?>
                        <div class="sc-reconciliation">
                            <div class="text-center mb-6">
                                <h2 class="fw-bolder text-gray-900 mb-1">Estate Service Charge Reconciliation</h2>
                                <div class="text-muted fs-6">Audit comparison between estate dues collections and facility running costs</div>
                            </div>

                            <div class="row g-4 mb-6">
                                <div class="col-12 col-md-4">
                                    <div class="p-4 bg-light-success rounded border border-success border-dashed">
                                        <div class="fs-7 text-success fw-bold text-uppercase">Service Charges Collected</div>
                                        <div class="fs-2x fw-bolder text-gray-900"><?= format_money($scCollected) ?></div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-4 bg-light-danger rounded border border-danger border-dashed">
                                        <div class="fs-7 text-danger fw-bold text-uppercase">Direct Facility Running Costs</div>
                                        <div class="fs-2x fw-bolder text-gray-900"><?= format_money($totalScExpense) ?></div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-4 <?= $scSurplusDeficit >= 0 ? 'bg-light-primary border-primary' : 'bg-light-warning border-warning' ?> rounded border border-dashed">
                                        <div class="fs-7 <?= $scSurplusDeficit >= 0 ? 'text-primary' : 'text-warning' ?> fw-bold text-uppercase">
                                            Service Charge <?= $scSurplusDeficit >= 0 ? 'Reserve Surplus' : 'Funding Deficit' ?>
                                        </div>
                                        <div class="fs-2x fw-bolder <?= $scSurplusDeficit >= 0 ? 'text-primary' : 'text-warning' ?>">
                                            <?= format_money($scSurplusDeficit) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Breakdown of Facility Running Costs -->
                            <div class="card bg-light border-0">
                                <div class="card-header border-0 pt-4">
                                    <h4 class="card-title fw-bold text-gray-800">Facility Running Costs Funded by Service Charges</h4>
                                </div>
                                <div class="card-body pt-0">
                                    <?php if (empty($scExpenses)): ?>
                                        <div class="text-muted fs-7 py-4">No operational facility expenses recorded in this timeframe.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-row-dashed align-middle gs-0 gy-3">
                                                <thead>
                                                    <tr class="fs-7 fw-bolder text-gray-500 text-uppercase">
                                                        <th>Facility Service Component</th>
                                                        <th class="text-end">Incurred Cost</th>
                                                        <th class="text-end">% of Total Spend</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="fs-6 fw-semibold text-gray-700">
                                                    <?php foreach ($scExpenses as $scE): 
                                                        $p = $totalScExpense > 0 ? round(((float)$scE['amount'] / $totalScExpense) * 100, 1) : 0;
                                                    ?>
                                                        <tr>
                                                            <td class="text-gray-900 fw-bold"><?= e($scE['name']) ?></td>
                                                            <td class="text-end fw-bold text-danger"><?= format_money((float)$scE['amount']) ?></td>
                                                            <td class="text-end"><span class="badge badge-light-danger fs-8"><?= $p ?>%</span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
