<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Bank & Payment Reconciliation – EstatePro';
$pageHeading = 'Bank Reconciliation';
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
$action = (string)(get_param('action', '') ?? '');

// Handle POST actions: add_bank_account, record_reconciliation, reconcile_item
if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');

    if ($postAction === 'save_bank_account') {
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        assert_can_access_estate($estateIdPost);

        $bankName = trim((string)post_param('bank_name', ''));
        $accountNumber = trim((string)post_param('account_number', ''));
        $accountName = trim((string)post_param('account_name', ''));
        $currency = trim((string)post_param('currency', 'NGN'));
        $openingBalance = (float)(post_param('opening_balance', 0) ?? 0);

        if ($bankName === '' || $accountNumber === '' || $accountName === '') {
            flash_set('error', 'Bank name, account number and account name are required.');
            redirect('reconciliation.php?estate_id=' . $estateIdPost);
        }

        $db->execute(
            "INSERT INTO bank_accounts (estate_id, bank_name, account_number, account_name, currency, opening_balance, current_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$estateIdPost, $bankName, $accountNumber, $accountName, $currency, $openingBalance, $openingBalance]
        );

        flash_set('success', 'Estate bank account registered successfully.');
        redirect('reconciliation.php?estate_id=' . $estateIdPost);
    }

    if ($postAction === 'reconcile_item') {
        $id = (int)(post_param('id', 0) ?? 0);
        $status = (string)post_param('status', 'reconciled');
        $notes = trim((string)post_param('notes', ''));

        $rec = $db->fetchOne('SELECT * FROM bank_reconciliations WHERE id = ?', [$id]);
        if ($rec) {
            assert_can_access_estate((int)$rec['estate_id']);
            $db->execute(
                "UPDATE bank_reconciliations 
                 SET status = ?, notes = ?, reconciled_by = ?, reconciled_at = NOW() 
                 WHERE id = ?",
                [$status, $notes, current_user_id(), $id]
            );
            flash_set('success', 'Reconciliation entry updated.');
        }
        redirect('reconciliation.php?estate_id=' . $estateId);
    }
}

// Fetch Estate Bank Accounts
$bankAccounts = $db->fetchAll(
    "SELECT ba.*, est.name AS estate_name 
     FROM bank_accounts ba
     INNER JOIN estates est ON est.id = ba.estate_id
     WHERE 1=1 " . ($estateId > 0 ? " AND ba.estate_id = {$estateId}" : "") . "
     ORDER BY ba.created_at DESC"
) ?: [];

// Fetch Recent Completed Payments for reconciliation matching
$recentPayments = $db->fetchAll(
    "SELECT p.id, p.payment_reference, p.amount, p.payment_date, p.payment_method, p.status,
            i.invoice_number, u.first_name, u.last_name, est.name AS estate_name
     FROM payments p
     INNER JOIN invoices i ON i.id = p.invoice_id
     INNER JOIN tenants t ON t.id = p.tenant_id
     INNER JOIN users u ON u.id = t.user_id
     INNER JOIN estates est ON est.id = p.estate_id
     WHERE p.status = 'completed' " . ($estateId > 0 ? " AND p.estate_id = {$estateId}" : "") . "
     ORDER BY p.payment_date DESC LIMIT 30"
) ?: [];

// Fetch Recent Paid Expenses for reconciliation matching
$recentPaidExpenses = $db->fetchAll(
    "SELECT e.id, e.expense_number, e.title, e.total_amount, e.payment_method, e.expense_date,
            ec.name AS category_name, est.name AS estate_name
     FROM expenses e
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     INNER JOIN estates est ON est.id = e.estate_id
     WHERE e.payment_status = 'paid' " . ($estateId > 0 ? " AND e.estate_id = {$estateId}" : "") . "
     ORDER BY e.expense_date DESC LIMIT 30"
) ?: [];

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Header & Filter -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-arrows-circle fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Bank & Settlement Reconciliation</h1>
                                <span class="text-muted fs-7">Audit estate bank statements against ledger payments and outflows</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <form method="get" action="reconciliation.php">
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

                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal_add_bank">
                                <i class="ki-duotone ki-plus fs-4 me-1"></i> Add Bank Account
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bank Accounts Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <?php if (empty($bankAccounts)): ?>
                    <div class="col-12">
                        <div class="card bg-light border-0 py-8 text-center">
                            <i class="ki-duotone ki-bank fs-3x text-muted mb-2"><span class="path1"></span><span class="path2"></span></i>
                            <div class="fs-6 fw-bold text-gray-800">No estate bank accounts added yet</div>
                            <div class="fs-7 text-muted">Register your operating, service charge escrow, or reserve bank accounts to start reconciliation.</div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($bankAccounts as $ba): ?>
                        <div class="col-12 col-md-4">
                            <div class="card card-flush shadow-sm border-0 bg-white">
                                <div class="card-body p-5">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <span class="badge badge-light-primary fw-bold text-uppercase fs-8"><?= e($ba['bank_name']) ?></span>
                                        <span class="badge badge-light-success fs-9"><?= e($ba['status']) ?></span>
                                    </div>
                                    <div class="fs-4 fw-bolder text-gray-900 mb-1"><?= e($ba['account_name']) ?></div>
                                    <div class="text-muted fs-7 mb-3">Acc: <strong><?= e($ba['account_number']) ?></strong> (<?= e($ba['estate_name']) ?>)</div>
                                    <div class="p-3 bg-light rounded d-flex justify-content-between align-items-center">
                                        <span class="text-muted fs-8">Opening Balance:</span>
                                        <span class="fw-bolder text-gray-900"><?= format_money((float)$ba['opening_balance']) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Reconciliation Workstations (Tabs for Credits vs Debits) -->
            <div class="card shadow-sm border-0">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bolder fs-4 text-dark">Ledger Settlement Audit Tray</span>
                        <span class="text-muted mt-1 fw-semibold fs-7">Audit completed tenant receipts (Credits) and vendor disbursements (Debits)</span>
                    </h3>
                </div>
                <div class="card-body pt-2">
                    <ul class="nav nav-tabs nav-line-tabs mb-5 fs-6">
                        <li class="nav-item">
                            <a class="nav-link active fw-bold" data-bs-toggle="tab" href="#kt_tab_credits">
                                <i class="ki-duotone ki-arrow-up fs-4 text-success me-1"></i> Tenant Collections (Inflows)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link fw-bold" data-bs-toggle="tab" href="#kt_tab_debits">
                                <i class="ki-duotone ki-arrow-down fs-4 text-danger me-1"></i> Paid Expense Vouchers (Outflows)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" id="myTabContent">
                        <!-- CREDITS TAB -->
                        <div class="tab-pane fade show active" id="kt_tab_credits" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-row-dashed align-middle gs-0 gy-3">
                                    <thead>
                                        <tr class="fs-7 fw-bolder text-gray-500 text-uppercase">
                                            <th>Reference</th>
                                            <th>Tenant / Estate</th>
                                            <th>Method</th>
                                            <th>Date</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-center">Audit Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="fs-6 fw-semibold text-gray-700">
                                        <?php foreach ($recentPayments as $rp): ?>
                                            <tr>
                                                <td class="fw-bold text-gray-900"><?= e($rp['payment_reference']) ?></td>
                                                <td>
                                                    <div class="text-gray-900"><?= e($rp['first_name'] . ' ' . $rp['last_name']) ?></div>
                                                    <div class="text-muted fs-8"><?= e($rp['estate_name']) ?> (<?= e($rp['invoice_number']) ?>)</div>
                                                </td>
                                                <td><span class="badge badge-light fs-8 text-uppercase"><?= e(str_replace('_', ' ', $rp['payment_method'])) ?></span></td>
                                                <td><?= date('M d, Y', strtotime($rp['payment_date'])) ?></td>
                                                <td class="text-end fw-bolder text-success"><?= format_money((float)$rp['amount']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-light-success fs-8">Settled & Verified</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- DEBITS TAB -->
                        <div class="tab-pane fade" id="kt_tab_debits" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-row-dashed align-middle gs-0 gy-3">
                                    <thead>
                                        <tr class="fs-7 fw-bolder text-gray-500 text-uppercase">
                                            <th>Voucher #</th>
                                            <th>Expense Title</th>
                                            <th>Category</th>
                                            <th>Date Disbursed</th>
                                            <th class="text-end">Amount Paid</th>
                                            <th class="text-center">Audit Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="fs-6 fw-semibold text-gray-700">
                                        <?php foreach ($recentPaidExpenses as $rpe): ?>
                                            <tr>
                                                <td class="fw-bold text-gray-900"><?= e($rpe['expense_number']) ?></td>
                                                <td>
                                                    <div class="text-gray-900"><?= e($rpe['title']) ?></div>
                                                    <div class="text-muted fs-8"><?= e($rpe['estate_name']) ?></div>
                                                </td>
                                                <td><span class="badge badge-light fs-8"><?= e($rpe['category_name']) ?></span></td>
                                                <td><?= date('M d, Y', strtotime($rpe['expense_date'])) ?></td>
                                                <td class="text-end fw-bolder text-danger"><?= format_money((float)$rpe['total_amount']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-light-success fs-8">Disbursed</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal: Add Bank Account -->
<div class="modal fade" id="modal_add_bank" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-600px">
        <div class="modal-content">
            <form method="post" action="reconciliation.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_bank_account">

                <div class="modal-header">
                    <h3 class="fw-bolder">Register Estate Bank Account</h3>
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

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label required">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" required placeholder="e.g. Zenith Bank PLC">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Account Number</label>
                            <input type="text" name="account_number" class="form-control" required placeholder="10-digit NUBAN">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label required">Account Name</label>
                        <input type="text" name="account_name" class="form-control" required placeholder="e.g. Estate Operations / Service Charge Trust">
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-control" value="NGN">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Opening Balance (₦)</label>
                            <input type="number" step="0.01" name="opening_balance" class="form-control" value="0.00">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Bank Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
