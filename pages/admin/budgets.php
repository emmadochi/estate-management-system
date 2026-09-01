<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Budgets & Variance Monitoring – EstatePro';
$pageHeading = 'Budgets & Variance';
$db = db();
$method = request_method();

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
$fiscalYear = (int)(get_param('year', date('Y')) ?? date('Y'));
$categories = $db->fetchAll("SELECT id, name, type FROM expense_categories ORDER BY name ASC") ?: [];

// POST action: save_budget, delete_budget
if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');

    if ($postAction === 'save_budget') {
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        assert_can_access_estate($estateIdPost);

        $categoryId = (int)(post_param('category_id', 0) ?? 0);
        $year = (int)(post_param('fiscal_year', date('Y')) ?? date('Y'));
        $month = post_param('fiscal_month') !== '' && post_param('fiscal_month') !== null ? (int)post_param('fiscal_month') : null;
        $budgetedAmount = (float)(post_param('budgeted_amount', 0) ?? 0);
        $notes = trim((string)post_param('notes', ''));

        if ($estateIdPost <= 0 || $categoryId <= 0 || $budgetedAmount <= 0) {
            flash_set('error', 'Estate, category and a positive budget amount are required.');
            redirect('budgets.php?estate_id=' . $estateIdPost . '&year=' . $year);
        }

        try {
            $db->execute(
                "INSERT INTO budgets (estate_id, category_id, fiscal_year, fiscal_month, budgeted_amount, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE budgeted_amount = VALUES(budgeted_amount), notes = VALUES(notes)",
                [$estateIdPost, $categoryId, $year, $month, $budgetedAmount, $notes, current_user_id()]
            );
            flash_set('success', 'Budget limit saved successfully.');
        } catch (Throwable $e) {
            flash_set('error', 'Error: ' . $e->getMessage());
        }
        redirect('budgets.php?estate_id=' . $estateIdPost . '&year=' . $year);
    }

    if ($postAction === 'delete_budget') {
        $id = (int)(post_param('id', 0) ?? 0);
        $b = $db->fetchOne('SELECT estate_id FROM budgets WHERE id = ?', [$id]);
        if ($b) {
            assert_can_access_estate((int)$b['estate_id']);
            $db->execute('DELETE FROM budgets WHERE id = ?', [$id]);
            flash_set('success', 'Budget allocation removed.');
        }
        redirect('budgets.php?estate_id=' . $estateId . '&year=' . $fiscalYear);
    }
}

// Fetch Budgets & Actual Spending for the Fiscal Year
$budgets = $db->fetchAll(
    "SELECT b.*, ec.name AS category_name, ec.type AS category_type, est.name AS estate_name
     FROM budgets b
     INNER JOIN expense_categories ec ON ec.id = b.category_id
     INNER JOIN estates est ON est.id = b.estate_id
     WHERE b.fiscal_year = ? " . ($estateId > 0 ? " AND b.estate_id = {$estateId}" : "") . "
     ORDER BY b.created_at DESC",
    [$fiscalYear]
) ?: [];

// Calculate actuals for each budget item
$budgetItems = [];
$totalBudgeted = 0;
$totalActualSpent = 0;

foreach ($budgets as $b) {
    $catId = (int)$b['category_id'];
    $bEstId = (int)$b['estate_id'];
    $bMonth = $b['fiscal_month'];

    if ($bMonth) {
        $mStart = sprintf('%04d-%02d-01', $fiscalYear, $bMonth);
        $mEnd = date('Y-m-t', strtotime($mStart));
    } else {
        $mStart = "{$fiscalYear}-01-01";
        $mEnd = "{$fiscalYear}-12-31";
    }

    $actualSpent = (float)($db->fetchOne(
        "SELECT COALESCE(SUM(total_amount), 0) AS s 
         FROM expenses 
         WHERE category_id = ? AND estate_id = ? AND payment_status IN ('approved', 'paid') 
           AND expense_date BETWEEN ? AND ?",
        [$catId, $bEstId, $mStart, $mEnd]
    )['s'] ?? 0);

    $budgeted = (float)$b['budgeted_amount'];
    $variance = $budgeted - $actualSpent;
    $pctUsed = $budgeted > 0 ? min(200, round(($actualSpent / $budgeted) * 100, 1)) : 0;

    $totalBudgeted += $budgeted;
    $totalActualSpent += $actualSpent;

    $b['actual_spent'] = $actualSpent;
    $b['variance'] = $variance;
    $b['pct_used'] = $pctUsed;
    $budgetItems[] = $b;
}

$totalVariance = $totalBudgeted - $totalActualSpent;

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Filter Bar -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-chart-simple-3 fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Budget Allocation & Variance Analysis</h1>
                                <span class="text-muted fs-7">Control operational spending and prevent budget overruns</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <form method="get" action="budgets.php" class="d-flex align-items-center gap-2">
                                <select name="estate_id" class="form-select form-select-sm w-180px" onchange="this.form.submit()">
                                    <?php if ($isSuper): ?>
                                        <option value="0" <?= $estateId === 0 ? 'selected' : '' ?>>All Estates (Consolidated)</option>
                                    <?php endif; ?>
                                    <?php foreach ($estates as $est): ?>
                                        <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                            <?= e($est['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="year" class="form-select form-select-sm w-120px" onchange="this.form.submit()">
                                    <?php for ($y = date('Y') + 1; $y >= date('Y') - 2; $y--): ?>
                                        <option value="<?= $y ?>" <?= $fiscalYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </form>

                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal_add_budget">
                                <i class="ki-duotone ki-plus fs-4 me-1"></i> Allocate Budget
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary KPI Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <div class="col-12 col-md-4">
                    <div class="card card-flush bg-light-primary border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold text-primary text-uppercase">Total Budgeted (<?= $fiscalYear ?>)</span>
                            <div class="fs-2x fw-bolder text-gray-900"><?= format_money($totalBudgeted) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-flush bg-light-danger border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold text-danger text-uppercase">Actual Spent (<?= $fiscalYear ?>)</span>
                            <div class="fs-2x fw-bolder text-gray-900"><?= format_money($totalActualSpent) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-flush <?= $totalVariance >= 0 ? 'bg-light-success' : 'bg-light-warning' ?> border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold <?= $totalVariance >= 0 ? 'text-success' : 'text-warning' ?> text-uppercase">
                                <?= $totalVariance >= 0 ? 'Remaining Budget Buffer' : 'Budget Deficit Overrun' ?>
                            </span>
                            <div class="fs-2x fw-bolder <?= $totalVariance >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= format_money($totalVariance) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budgets Table -->
            <div class="card shadow-sm border-0">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bolder fs-4 text-dark">Category Budget Allocations</span>
                        <span class="text-muted mt-1 fw-semibold fs-7">Real-time tracking of budget vs actual expenditures</span>
                    </h3>
                </div>
                <div class="card-body py-4">
                    <?php if (empty($budgetItems)): ?>
                        <div class="text-center py-15 text-gray-500">
                            <i class="ki-duotone ki-chart-simple fs-3x text-muted mb-3"><span class="path1"></span><span class="path2"></span></i>
                            <div class="fs-5 fw-bold">No budgets configured for <?= $fiscalYear ?></div>
                            <div class="fs-7 text-muted mt-1">Click "Allocate Budget" to set category limits.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-row-dashed align-middle gs-0 gy-4">
                                <thead>
                                    <tr class="fs-7 fw-bolder text-gray-500 text-uppercase border-bottom border-gray-200">
                                        <th>Category & Estate</th>
                                        <th>Period</th>
                                        <th class="text-end">Budgeted Limit</th>
                                        <th class="text-end">Actual Spent</th>
                                        <th class="text-end">Remaining Variance</th>
                                        <th class="w-200px">Utilization %</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="fs-6 fw-semibold text-gray-700">
                                    <?php foreach ($budgetItems as $bi): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-gray-900"><?= e($bi['category_name']) ?></div>
                                                <div class="text-muted fs-8"><?= e($bi['estate_name']) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($bi['fiscal_month']): ?>
                                                    <span class="badge badge-light fs-8"><?= date('F', mktime(0, 0, 0, (int)$bi['fiscal_month'], 1)) ?> <?= $fiscalYear ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-light-primary fs-8">Annual <?= $fiscalYear ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold text-gray-900"><?= format_money((float)$bi['budgeted_amount']) ?></td>
                                            <td class="text-end fw-bold text-danger"><?= format_money((float)$bi['actual_spent']) ?></td>
                                            <td class="text-end fw-bolder <?= $bi['variance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= format_money((float)$bi['variance']) ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2 fs-7 fw-bold <?= $bi['pct_used'] > 100 ? 'text-danger' : 'text-gray-800' ?>"><?= $bi['pct_used'] ?>%</span>
                                                    <div class="progress h-6px w-100 bg-light">
                                                        <div class="progress-bar <?= $bi['pct_used'] > 100 ? 'bg-danger' : ($bi['pct_used'] > 80 ? 'bg-warning' : 'bg-success') ?>"
                                                             role="progressbar" style="width: <?= min(100, $bi['pct_used']) ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <form method="post" action="budgets.php" onsubmit="return confirm('Remove this budget allocation?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_budget">
                                                    <input type="hidden" name="id" value="<?= (int)$bi['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-icon btn-light-danger">
                                                        <i class="ki-duotone ki-trash fs-5"></i>
                                                    </button>
                                                </form>
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

<!-- Modal: Allocate Budget -->
<div class="modal fade" id="modal_add_budget" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-600px">
        <div class="modal-content">
            <form method="post" action="budgets.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_budget">

                <div class="modal-header">
                    <h3 class="fw-bolder">Set Category Expenditure Budget</h3>
                    <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                </div>

                <div class="modal-body py-5 px-lg-8">
                    <div class="mb-4">
                        <label class="form-label required">Estate</label>
                        <select name="estate_id" class="form-select" required>
                            <?php foreach ($estates as $est): ?>
                                <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                    <?= e($est['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label required">Expense Category</label>
                        <select name="category_id" class="form-select" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?> (<?= e(ucfirst($cat['type'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label required">Fiscal Year</label>
                            <input type="number" name="fiscal_year" class="form-control" value="<?= $fiscalYear ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Month (Optional)</label>
                            <select name="fiscal_month" class="form-select">
                                <option value="">Annual (Full Year)</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>"><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label required">Budgeted Amount (₦)</label>
                        <input type="number" step="0.01" name="budgeted_amount" class="form-control" required placeholder="e.g. 1500000.00">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Notes & Allocation Justification</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Approved diesel allocation based on 2026 tariff schedule..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Budget</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
