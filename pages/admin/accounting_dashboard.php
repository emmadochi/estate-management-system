<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Financial Overview & Accounting – EstatePro';
$pageHeading = 'Financial Dashboard';
$db = db();

$isSuper = is_super_admin();
$estates = estates_for_current_user();
if (!$estates) {
    if ($isSuper) {
        flash_set('warning', 'Create an estate first.');
        redirect('estates.php');
    }
    http_response_code(403);
    echo 'No estate access assigned to your account. Please contact an administrator.';
    exit;
}

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estateId = normalize_estate_id($requestedEstateId);
$timeframe = (string)(get_param('timeframe', 'month') ?? 'month');

// Date range calculation
$currentYear = (int)date('Y');
$currentMonth = (int)date('m');

if ($timeframe === 'year') {
    $startDate = date('Y-01-01');
    $endDate = date('Y-12-31');
    $periodLabel = 'Year ' . $currentYear;
} elseif ($timeframe === 'quarter') {
    $currentQuarter = ceil($currentMonth / 3);
    $startMonth = str_pad((string)(($currentQuarter - 1) * 3 + 1), 2, '0', STR_PAD_LEFT);
    $startDate = date("Y-{$startMonth}-01");
    $endDate = date('Y-m-t', strtotime($startDate . ' + 2 months'));
    $periodLabel = "Q{$currentQuarter} " . $currentYear;
} else { // default month
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-t');
    $periodLabel = date('F Y');
}

// Scope conditions
$estateWherePayment = '';
$estateWhereExpense = '';
$estateWhereInvoice = '';
$paramsPayment = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
$paramsExpense = [$startDate, $endDate];
$paramsInvoice = [];

if ($estateId > 0) {
    $estateWherePayment = ' AND p.estate_id = ?';
    $paramsPayment[] = $estateId;
    
    $estateWhereExpense = ' AND e.estate_id = ?';
    $paramsExpense[] = $estateId;

    $estateWhereInvoice = ' WHERE estate_id = ?';
    $paramsInvoice[] = $estateId;
}

// 1. Inflows / Collections
$paymentSql = "SELECT COALESCE(SUM(p.amount), 0) AS total_collected,
                      COUNT(p.id) AS total_txns
               FROM payments p
               WHERE p.status = 'completed'
                 AND p.payment_date BETWEEN ? AND ?" . $estateWherePayment;
$paymentData = $db->fetchOne($paymentSql, $paramsPayment) ?: ['total_collected' => 0, 'total_txns' => 0];
$totalCollections = (float)($paymentData['total_collected'] ?? 0);

// 2. Outflows / Expenses
$expenseSql = "SELECT COALESCE(SUM(e.total_amount), 0) AS total_expenses,
                      COUNT(e.id) AS total_expense_count
               FROM expenses e
               WHERE e.payment_status IN ('approved', 'paid')
                 AND e.expense_date BETWEEN ? AND ?" . $estateWhereExpense;
$expenseData = $db->fetchOne($expenseSql, $paramsExpense) ?: ['total_expenses' => 0, 'total_expense_count' => 0];
$totalExpenses = (float)($expenseData['total_expenses'] ?? 0);

// 3. Net Operating Income (NOI)
$netOperatingIncome = $totalCollections - $totalExpenses;

// 4. Receivables & Aging Overdue
$invoiceScopeSql = $estateId > 0 ? " WHERE estate_id = {$estateId}" : "";
$receivablesData = $db->fetchOne("
    SELECT 
        COALESCE(SUM(amount - paid_amount), 0) AS total_overdue,
        COALESCE(SUM(CASE WHEN due_date >= CURDATE() THEN (amount - paid_amount) ELSE 0 END), 0) AS current_due,
        COALESCE(SUM(CASE WHEN due_date < CURDATE() AND due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN (amount - paid_amount) ELSE 0 END), 0) AS overdue_1_30,
        COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN (amount - paid_amount) ELSE 0 END), 0) AS overdue_31_60,
        COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND due_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN (amount - paid_amount) ELSE 0 END), 0) AS overdue_61_90,
        COALESCE(SUM(CASE WHEN due_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN (amount - paid_amount) ELSE 0 END), 0) AS overdue_90_plus
    FROM invoices
    WHERE status IN ('pending', 'overdue', 'partial')" . ($estateId > 0 ? " AND estate_id = {$estateId}" : "")
) ?: [
    'total_overdue' => 0, 'current_due' => 0, 'overdue_1_30' => 0,
    'overdue_31_60' => 0, 'overdue_61_90' => 0, 'overdue_90_plus' => 0
];
$totalReceivables = (float)($receivablesData['total_overdue'] ?? 0);

// 5. Invoiced this period for Collection Efficiency %
$invoicedPeriodSql = "SELECT COALESCE(SUM(amount), 0) AS total_invoiced 
                      FROM invoices 
                      WHERE created_at BETWEEN ? AND ?" . ($estateId > 0 ? " AND estate_id = {$estateId}" : "");
$invoicedData = $db->fetchOne($invoicedPeriodSql, [$startDate . ' 00:00:00', $endDate . ' 23:59:59']) ?: ['total_invoiced' => 0];
$totalInvoiced = (float)($invoicedData['total_invoiced'] ?? 0);
$collectionEfficiency = $totalInvoiced > 0 ? min(100, round(($totalCollections / $totalInvoiced) * 100, 1)) : ($totalCollections > 0 ? 100 : 0);

// 6. Expense Breakdown by Category
$catBreakdownSql = "
    SELECT ec.name, ec.type, COALESCE(SUM(e.total_amount), 0) AS category_total
    FROM expense_categories ec
    LEFT JOIN expenses e ON e.category_id = ec.id 
        AND e.payment_status IN ('approved', 'paid') 
        AND e.expense_date BETWEEN '{$startDate}' AND '{$endDate}'
        " . ($estateId > 0 ? " AND e.estate_id = {$estateId}" : "") . "
    GROUP BY ec.id, ec.name, ec.type
    HAVING category_total > 0
    ORDER BY category_total DESC";
$categoryExpenses = $db->fetchAll($catBreakdownSql) ?: [];

// 7. Recent Disbursements
$recentExpensesSql = "
    SELECT e.*, ec.name AS category_name, est.name AS estate_name, v.name AS vendor_name,
           u.first_name, u.last_name
    FROM expenses e
    INNER JOIN expense_categories ec ON ec.id = e.category_id
    INNER JOIN estates est ON est.id = e.estate_id
    LEFT JOIN vendors v ON v.id = e.vendor_id
    LEFT JOIN users u ON u.id = e.recorded_by
    WHERE 1=1 " . ($estateId > 0 ? " AND e.estate_id = {$estateId}" : "") . "
    ORDER BY e.created_at DESC
    LIMIT 6";
$recentExpenses = $db->fetchAll($recentExpensesSql) ?: [];

// 8. Pending Approvals count
$pendingApprovalsSql = "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS total_val 
                        FROM expenses 
                        WHERE payment_status = 'pending_approval'" . ($estateId > 0 ? " AND estate_id = {$estateId}" : "");
$pendingApprovals = $db->fetchOne($pendingApprovalsSql) ?: ['c' => 0, 'total_val' => 0];

// 9. Monthly Cash Flow (Past 6 Months)
$cashflowMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-{$i} months"));
    $mEnd = date('Y-m-t', strtotime("-{$i} months"));
    $mLabel = date('M Y', strtotime("-{$i} months"));

    $mInflow = (float)($db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) AS s FROM payments WHERE status = 'completed' AND payment_date BETWEEN ? AND ?" . ($estateId > 0 ? " AND estate_id = {$estateId}" : ""),
        [$mStart . ' 00:00:00', $mEnd . ' 23:59:59']
    )['s'] ?? 0);

    $mOutflow = (float)($db->fetchOne(
        "SELECT COALESCE(SUM(total_amount), 0) AS s FROM expenses WHERE payment_status IN ('approved', 'paid') AND expense_date BETWEEN ? AND ?" . ($estateId > 0 ? " AND estate_id = {$estateId}" : ""),
        [$mStart, $mEnd]
    )['s'] ?? 0);

    $cashflowMonths[] = [
        'label' => $mLabel,
        'inflow' => $mInflow,
        'outflow' => $mOutflow,
        'net' => $mInflow - $mOutflow
    ];
}

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Filter & Action Bar -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-chart-pie-simple fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Financial & Accounting Hub</h1>
                                <span class="text-muted fs-7">Real-time revenue, operational expenses, cash flow & aging analytics</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <!-- Estate Filter -->
                            <form method="get" action="accounting_dashboard.php" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="timeframe" value="<?= e($timeframe) ?>">
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
                            </form>

                            <!-- Timeframe Buttons -->
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="accounting_dashboard.php?estate_id=<?= $estateId ?>&timeframe=month" class="btn <?= $timeframe === 'month' ? 'btn-primary' : 'btn-light' ?>">Month</a>
                                <a href="accounting_dashboard.php?estate_id=<?= $estateId ?>&timeframe=quarter" class="btn <?= $timeframe === 'quarter' ? 'btn-primary' : 'btn-light' ?>">Quarter</a>
                                <a href="accounting_dashboard.php?estate_id=<?= $estateId ?>&timeframe=year" class="btn <?= $timeframe === 'year' ? 'btn-primary' : 'btn-light' ?>">Year</a>
                            </div>

                            <!-- Quick Action Buttons -->
                            <a href="expenses.php?action=new&estate_id=<?= $estateId ?>" class="btn btn-sm btn-danger d-flex align-items-center">
                                <i class="ki-duotone ki-plus fs-4 me-1"></i> Log Expense
                            </a>
                            <a href="financial_reports.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-primary d-flex align-items-center">
                                <i class="ki-duotone ki-document fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <!-- Gross Collections -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card card-xl-stretch mb-xl-8 bg-light-success border-0">
                        <div class="card-body py-5">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-success fw-bold fs-7 text-uppercase">Gross Collections</span>
                                <span class="badge badge-light-success fs-8"><?= e($periodLabel) ?></span>
                            </div>
                            <div class="fs-2hx fw-bolder text-gray-900 mb-1"><?= format_money($totalCollections) ?></div>
                            <div class="d-flex align-items-center justify-content-between text-muted fs-7">
                                <span><?= number_format((int)$paymentData['total_txns']) ?> Transactions</span>
                                <a href="payments.php?estate_id=<?= $estateId ?>" class="text-success fw-semibold text-hover-underline">View receipts &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Total OpEx / Outflows -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card card-xl-stretch mb-xl-8 bg-light-danger border-0">
                        <div class="card-body py-5">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-danger fw-bold fs-7 text-uppercase">Total Expenses (OpEx)</span>
                                <span class="badge badge-light-danger fs-8"><?= e($periodLabel) ?></span>
                            </div>
                            <div class="fs-2hx fw-bolder text-gray-900 mb-1"><?= format_money($totalExpenses) ?></div>
                            <div class="d-flex align-items-center justify-content-between text-muted fs-7">
                                <span><?= number_format((int)$expenseData['total_expense_count']) ?> Approved Vouchers</span>
                                <a href="expenses.php?estate_id=<?= $estateId ?>" class="text-danger fw-semibold text-hover-underline">View expenses &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Net Operating Income (NOI) -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card card-xl-stretch mb-xl-8 <?= $netOperatingIncome >= 0 ? 'bg-light-primary' : 'bg-light-warning' ?> border-0">
                        <div class="card-body py-5">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="<?= $netOperatingIncome >= 0 ? 'text-primary' : 'text-warning' ?> fw-bold fs-7 text-uppercase">Net Operating Income</span>
                                <span class="badge <?= $netOperatingIncome >= 0 ? 'badge-light-primary' : 'badge-light-warning' ?> fs-8">Surplus / Margin</span>
                            </div>
                            <div class="fs-2hx fw-bolder <?= $netOperatingIncome >= 0 ? 'text-primary' : 'text-warning' ?> mb-1">
                                <?= format_money($netOperatingIncome) ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-between text-muted fs-7">
                                <span>Revenue minus OpEx</span>
                                <a href="financial_reports.php?tab=pnl&estate_id=<?= $estateId ?>" class="fw-semibold text-hover-underline">P&L Breakdown &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Receivables -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card card-xl-stretch mb-xl-8 bg-light-info border-0">
                        <div class="card-body py-5">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-info fw-bold fs-7 text-uppercase">Total Receivables</span>
                                <span class="badge badge-light-info fs-8">Efficiency: <?= $collectionEfficiency ?>%</span>
                            </div>
                            <div class="fs-2hx fw-bolder text-gray-900 mb-1"><?= format_money($totalReceivables) ?></div>
                            <div class="d-flex align-items-center justify-content-between text-muted fs-7">
                                <span>Overdue tenant balances</span>
                                <a href="financial_reports.php?tab=aging&estate_id=<?= $estateId ?>" class="text-info fw-semibold text-hover-underline">Aging Report &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts: Pending Approvals -->
            <?php if ((int)$pendingApprovals['c'] > 0): ?>
                <div class="alert alert-dismissible bg-light-warning border border-warning d-flex flex-column flex-sm-row p-5 mb-6">
                    <i class="ki-duotone ki-notification-bing fs-2hx text-warning me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                    <div class="d-flex flex-column pe-0 pe-sm-10 justify-content-center">
                        <h4 class="fw-bold text-gray-900 mb-1">Pending Expense Vouchers Requiring Authorization</h4>
                        <span class="fs-7 text-gray-700">There are <strong><?= (int)$pendingApprovals['c'] ?> expense claims</strong> totaling <strong><?= format_money((float)$pendingApprovals['total_val']) ?></strong> awaiting accounting review and sign-off.</span>
                    </div>
                    <a href="expenses.php?status=pending_approval&estate_id=<?= $estateId ?>" class="btn btn-sm btn-warning align-self-center ms-sm-auto mt-3 mt-sm-0 text-nowrap">
                        Review Vouchers
                    </a>
                </div>
            <?php endif; ?>

            <!-- Middle Section: Cash Flow Graph & Expense Distribution -->
            <div class="row g-5 g-xl-8 mb-6">
                <!-- 6-Month Cash Flow Trend Table / Chart -->
                <div class="col-12 col-xl-8">
                    <div class="card card-xl-stretch mb-5 mb-xl-8 border-0 shadow-sm">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder fs-4 text-dark">6-Month Cash Flow Summary</span>
                                <span class="text-muted mt-1 fw-semibold fs-7">Historical monthly comparison between collections and facility expenditures</span>
                            </h3>
                            <div class="card-toolbar">
                                <a href="financial_reports.php?tab=cashflow&estate_id=<?= $estateId ?>" class="btn btn-sm btn-light">Detailed Cash Flow</a>
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <div class="table-responsive">
                                <table class="table table-row-dashed align-middle gs-0 gy-4">
                                    <thead>
                                        <tr class="fs-7 fw-bolder text-gray-500 text-uppercase">
                                            <th>Month</th>
                                            <th class="text-end">Collections (Inflow)</th>
                                            <th class="text-end">Expenses (Outflow)</th>
                                            <th class="text-end">Net Cash Flow</th>
                                            <th class="text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="fs-6 fw-semibold text-gray-700">
                                        <?php foreach ($cashflowMonths as $cf): ?>
                                            <tr>
                                                <td class="fw-bold text-gray-900"><?= e($cf['label']) ?></td>
                                                <td class="text-end text-success fw-bold"><?= format_money($cf['inflow']) ?></td>
                                                <td class="text-end text-danger fw-bold"><?= format_money($cf['outflow']) ?></td>
                                                <td class="text-end fw-bolder <?= $cf['net'] >= 0 ? 'text-primary' : 'text-danger' ?>">
                                                    <?= ($cf['net'] >= 0 ? '+' : '') . format_money($cf['net']) ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($cf['net'] >= 0): ?>
                                                        <span class="badge badge-light-success fs-8">Surplus</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-light-danger fs-8">Deficit</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expense Distribution by Category -->
                <div class="col-12 col-xl-4">
                    <div class="card card-xl-stretch mb-5 mb-xl-8 border-0 shadow-sm">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder fs-4 text-dark">OpEx by Category</span>
                                <span class="text-muted mt-1 fw-semibold fs-7"><?= e($periodLabel) ?> expenditure share</span>
                            </h3>
                            <div class="card-toolbar">
                                <a href="expenses.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light">Manage</a>
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <?php if (empty($categoryExpenses)): ?>
                                <div class="text-center py-10">
                                    <i class="ki-duotone ki-wallet fs-3x text-muted mb-3"><span class="path1"></span><span class="path2"></span></i>
                                    <div class="text-gray-500 fs-7">No operational expenses recorded for <?= e($periodLabel) ?>.</div>
                                    <a href="expenses.php?action=new&estate_id=<?= $estateId ?>" class="btn btn-sm btn-light-primary mt-3">Log First Expense</a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($categoryExpenses as $ce): 
                                    $pct = $totalExpenses > 0 ? round(((float)$ce['category_total'] / $totalExpenses) * 100, 1) : 0;
                                ?>
                                    <div class="d-flex flex-stack mb-4">
                                        <div class="d-flex align-items-center me-2">
                                            <div class="symbol symbol-35px symbol-circle bg-light-danger me-3">
                                                <i class="ki-duotone ki-abstract-26 fs-4 text-danger"><span class="path1"></span><span class="path2"></span></i>
                                            </div>
                                            <div>
                                                <div class="fs-6 fw-bolder text-gray-900"><?= e($ce['name']) ?></div>
                                                <span class="text-muted fs-8 text-uppercase"><?= e($ce['type']) ?></span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fs-6 fw-bold text-gray-900"><?= format_money((float)$ce['category_total']) ?></div>
                                            <span class="badge badge-light-danger fs-8"><?= $pct ?>%</span>
                                        </div>
                                    </div>
                                    <div class="progress h-4px w-100 bg-light-danger mb-4">
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $pct ?>%"></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section: Accounts Receivable Aging & Recent Disbursements -->
            <div class="row g-5 g-xl-8">
                <!-- AR Aging Snapshot -->
                <div class="col-12 col-xl-5">
                    <div class="card card-xl-stretch mb-5 mb-xl-8 border-0 shadow-sm">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder fs-4 text-dark">Receivables Aging Analysis</span>
                                <span class="text-muted mt-1 fw-semibold fs-7">Outstanding rent and service charge overdue profile</span>
                            </h3>
                            <div class="card-toolbar">
                                <a href="financial_reports.php?tab=aging&estate_id=<?= $estateId ?>" class="btn btn-sm btn-light">Full Aging</a>
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <div class="d-flex flex-column gap-3">
                                <div class="d-flex align-items-center justify-content-between p-3 bg-light-primary rounded">
                                    <div class="d-flex align-items-center">
                                        <span class="bullet bullet-vertical h-30px bg-primary me-3"></span>
                                        <div>
                                            <div class="fs-6 fw-bold text-gray-900">Current (Not Yet Due)</div>
                                            <span class="text-muted fs-8">Within invoice payment grace window</span>
                                        </div>
                                    </div>
                                    <span class="fs-6 fw-bolder text-gray-900"><?= format_money((float)$receivablesData['current_due']) ?></span>
                                </div>

                                <div class="d-flex align-items-center justify-content-between p-3 bg-light-warning rounded">
                                    <div class="d-flex align-items-center">
                                        <span class="bullet bullet-vertical h-30px bg-warning me-3"></span>
                                        <div>
                                            <div class="fs-6 fw-bold text-gray-900">1 – 30 Days Overdue</div>
                                            <span class="text-muted fs-8">First follow-up reminder stage</span>
                                        </div>
                                    </div>
                                    <span class="fs-6 fw-bolder text-warning"><?= format_money((float)$receivablesData['overdue_1_30']) ?></span>
                                </div>

                                <div class="d-flex align-items-center justify-content-between p-3 bg-light-danger rounded">
                                    <div class="d-flex align-items-center">
                                        <span class="bullet bullet-vertical h-30px bg-danger me-3"></span>
                                        <div>
                                            <div class="fs-6 fw-bold text-gray-900">31 – 60 Days Overdue</div>
                                            <span class="text-muted fs-8">Secondary default warning</span>
                                        </div>
                                    </div>
                                    <span class="fs-6 fw-bolder text-danger"><?= format_money((float)$receivablesData['overdue_31_60']) ?></span>
                                </div>

                                <div class="d-flex align-items-center justify-content-between p-3 bg-light-danger rounded border border-danger">
                                    <div class="d-flex align-items-center">
                                        <span class="bullet bullet-vertical h-30px bg-danger me-3"></span>
                                        <div>
                                            <div class="fs-6 fw-bold text-danger">61+ Days (High Risk Default)</div>
                                            <span class="text-muted fs-8">Critical delinquency action required</span>
                                        </div>
                                    </div>
                                    <span class="fs-6 fw-bolder text-danger">
                                        <?= format_money((float)$receivablesData['overdue_61_90'] + (float)$receivablesData['overdue_90_plus']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Disbursements Table -->
                <div class="col-12 col-xl-7">
                    <div class="card card-xl-stretch mb-5 mb-xl-8 border-0 shadow-sm">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title align-items-start flex-column">
                                <span class="card-label fw-bolder fs-4 text-dark">Recent Expense Disbursements</span>
                                <span class="text-muted mt-1 fw-semibold fs-7">Latest operational vouchers & contractor payouts</span>
                            </h3>
                            <div class="card-toolbar">
                                <a href="expenses.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light">View All</a>
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <?php if (empty($recentExpenses)): ?>
                                <div class="text-center py-10 text-muted fs-7">No expense records found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-row-dashed align-middle gs-0 gy-3">
                                        <thead>
                                            <tr class="fs-7 fw-bolder text-gray-500 text-uppercase">
                                                <th>Voucher #</th>
                                                <th>Expense / Category</th>
                                                <th>Vendor</th>
                                                <th>Status</th>
                                                <th class="text-end">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody class="fs-6 fw-semibold text-gray-700">
                                            <?php foreach ($recentExpenses as $rx): ?>
                                                <tr>
                                                    <td>
                                                        <a href="expenses.php?edit_id=<?= (int)$rx['id'] ?>&estate_id=<?= (int)$rx['estate_id'] ?>" class="text-primary fw-bold text-hover-underline">
                                                            <?= e($rx['expense_number']) ?>
                                                        </a>
                                                        <div class="text-muted fs-8"><?= date('M d, Y', strtotime($rx['expense_date'])) ?></div>
                                                    </td>
                                                    <td>
                                                        <div class="text-gray-900 fw-bold"><?= e($rx['title']) ?></div>
                                                        <span class="badge badge-light fs-8"><?= e($rx['category_name']) ?></span>
                                                    </td>
                                                    <td>
                                                        <div class="text-gray-800"><?= e($rx['vendor_name'] ?: 'Internal / Direct') ?></div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $stClass = 'badge-light-warning';
                                                        if ($rx['payment_status'] === 'paid') $stClass = 'badge-light-success';
                                                        elseif ($rx['payment_status'] === 'approved') $stClass = 'badge-light-primary';
                                                        elseif ($rx['payment_status'] === 'rejected') $stClass = 'badge-light-danger';
                                                        ?>
                                                        <span class="badge <?= $stClass ?> text-uppercase fs-8"><?= e(str_replace('_', ' ', $rx['payment_status'])) ?></span>
                                                    </td>
                                                    <td class="text-end fw-bolder text-gray-900">
                                                        <?= format_money((float)$rx['total_amount']) ?>
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

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
