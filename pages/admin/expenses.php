<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Expenses & Disbursements – EstatePro';
$pageHeading = 'Expense Management';
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
    echo 'No estate access assigned to your account. Please contact an administrator.';
    exit;
}

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estateId = normalize_estate_id($requestedEstateId);
$statusFilter = (string)(get_param('status', '') ?? '');
$categoryFilter = (int)(get_param('category_id', 0) ?? 0);
$search = trim((string)(get_param('search', '') ?? ''));
$editId = (int)(get_param('edit_id', 0) ?? 0);
$action = (string)(get_param('action', '') ?? '');

// Handle CSV Export
if ($action === 'export_csv') {
    $where = [];
    $params = [];
    if ($estateId > 0) {
        $where[] = 'e.estate_id = ?';
        $params[] = $estateId;
    }
    if ($statusFilter !== '') {
        $where[] = 'e.payment_status = ?';
        $params[] = $statusFilter;
    }
    if ($categoryFilter > 0) {
        $where[] = 'e.category_id = ?';
        $params[] = $categoryFilter;
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    
    $rows = $db->fetchAll(
        "SELECT e.expense_number, est.name AS estate_name, ec.name AS category_name, e.title,
                v.name AS vendor_name, e.amount, e.tax_amount, e.withholding_tax, e.total_amount,
                e.payment_method, e.payment_status, e.expense_date, e.paid_date, e.invoice_reference
         FROM expenses e
         INNER JOIN estates est ON est.id = e.estate_id
         INNER JOIN expense_categories ec ON ec.id = e.category_id
         LEFT JOIN vendors v ON v.id = e.vendor_id
         {$whereSql}
         ORDER BY e.expense_date DESC",
        $params
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=estate_expenses_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Voucher #', 'Estate', 'Category', 'Title', 'Vendor', 'Base Amount', 'Tax', 'WHT', 'Total Amount', 'Method', 'Status', 'Expense Date', 'Paid Date', 'Invoice Ref']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['expense_number'],
            $r['estate_name'],
            $r['category_name'],
            $r['title'],
            $r['vendor_name'] ?: 'N/A',
            $r['amount'],
            $r['tax_amount'],
            $r['withholding_tax'],
            $r['total_amount'],
            $r['payment_method'],
            $r['payment_status'],
            $r['expense_date'],
            $r['paid_date'] ?: 'N/A',
            $r['invoice_reference'] ?: 'N/A'
        ]);
    }
    fclose($out);
    exit;
}

// POST actions: create, update, approve, reject, mark_paid, delete
if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');

    if ($postAction === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        assert_can_access_estate($estateIdPost);

        $propertyId = (int)(post_param('property_id', 0) ?? 0);
        $categoryId = (int)(post_param('category_id', 0) ?? 0);
        $accountId = (int)(post_param('account_id', 0) ?? 0);
        $vendorId = (int)(post_param('vendor_id', 0) ?? 0);
        $title = trim((string)post_param('title', ''));
        $description = trim((string)post_param('description', ''));
        $amount = (float)(post_param('amount', 0) ?? 0);
        $taxAmount = (float)(post_param('tax_amount', 0) ?? 0);
        $withholdingTax = (float)(post_param('withholding_tax', 0) ?? 0);
        $paymentMethod = (string)post_param('payment_method', 'bank_transfer');
        $paymentStatus = (string)post_param('payment_status', 'pending_approval');
        $expenseDate = (string)post_param('expense_date', date('Y-m-d'));
        $dueDate = (string)post_param('due_date', '');
        $invoiceRef = trim((string)post_param('invoice_reference', ''));
        $notes = trim((string)post_param('notes', ''));

        if ($estateIdPost <= 0 || $categoryId <= 0 || $title === '' || $amount <= 0 || $expenseDate === '') {
            flash_set('error', 'Please fill in all required fields: Estate, Category, Title, Expense Date, and positive Amount.');
            redirect('expenses.php?estate_id=' . $estateIdPost . ($id > 0 ? ('&edit_id=' . $id) : '&action=new'));
        }

        $totalAmount = max(0, $amount + $taxAmount - $withholdingTax);

        // Upload receipt file if attached
        $receiptFile = handle_expense_receipt_upload('receipt_file');

        try {
            $currentUserId = current_user_id();

            if ($id > 0) {
                // Update
                $before = $db->fetchOne('SELECT * FROM expenses WHERE id = ?', [$id]);
                if (!$before) {
                    throw new RuntimeException('Expense voucher not found.');
                }
                assert_can_access_estate((int)$before['estate_id']);

                $sql = "UPDATE expenses 
                        SET estate_id = ?, property_id = NULLIF(?, 0), category_id = ?, account_id = NULLIF(?, 0),
                            vendor_id = NULLIF(?, 0), title = ?, description = ?, amount = ?, tax_amount = ?,
                            withholding_tax = ?, total_amount = ?, payment_method = ?, expense_date = ?,
                            due_date = NULLIF(?, ''), invoice_reference = NULLIF(?, ''), notes = ?";
                $params = [
                    $estateIdPost, $propertyId, $categoryId, $accountId,
                    $vendorId, $title, $description, $amount, $taxAmount,
                    $withholdingTax, $totalAmount, $paymentMethod, $expenseDate,
                    $dueDate, $invoiceRef, $notes
                ];

                if ($receiptFile !== null) {
                    $sql .= ", receipt_file = ?";
                    $params[] = $receiptFile;
                }

                $sql .= " WHERE id = ?";
                $params[] = $id;

                $db->execute($sql, $params);
                flash_set('success', 'Expense voucher updated successfully.');
                redirect('expenses.php?estate_id=' . $estateIdPost);
            } else {
                // Create
                $expenseNumber = 'EXP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

                $sql = "INSERT INTO expenses 
                        (expense_number, estate_id, property_id, category_id, account_id, vendor_id,
                         title, description, amount, tax_amount, withholding_tax, total_amount,
                         payment_method, payment_status, expense_date, due_date, receipt_file,
                         invoice_reference, notes, recorded_by)
                        VALUES (?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), NULLIF(?, 0),
                                ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, NULLIF(?, ''), ?,
                                NULLIF(?, ''), ?, ?)";
                $db->execute($sql, [
                    $expenseNumber, $estateIdPost, $propertyId, $categoryId, $accountId, $vendorId,
                    $title, $description, $amount, $taxAmount, $withholdingTax, $totalAmount,
                    $paymentMethod, $paymentStatus, $expenseDate, $dueDate, $receiptFile,
                    $invoiceRef, $notes, $currentUserId
                ]);

                flash_set('success', "Expense voucher {$expenseNumber} recorded successfully.");
                redirect('expenses.php?estate_id=' . $estateIdPost);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Database Error: ' . $e->getMessage());
            redirect('expenses.php?estate_id=' . $estateIdPost);
        }
    }

    if ($postAction === 'approve') {
        $id = (int)(post_param('id', 0) ?? 0);
        $exp = $db->fetchOne('SELECT id, estate_id, expense_number FROM expenses WHERE id = ?', [$id]);
        if ($exp) {
            assert_can_access_estate((int)$exp['estate_id']);
            $db->execute(
                "UPDATE expenses SET payment_status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                [current_user_id(), $id]
            );
            flash_set('success', "Expense {$exp['expense_number']} approved.");
        }
        redirect('expenses.php?estate_id=' . $estateId);
    }

    if ($postAction === 'reject') {
        $id = (int)(post_param('id', 0) ?? 0);
        $exp = $db->fetchOne('SELECT id, estate_id, expense_number FROM expenses WHERE id = ?', [$id]);
        if ($exp) {
            assert_can_access_estate((int)$exp['estate_id']);
            $db->execute(
                "UPDATE expenses SET payment_status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?",
                [current_user_id(), $id]
            );
            flash_set('warning', "Expense {$exp['expense_number']} marked as rejected.");
        }
        redirect('expenses.php?estate_id=' . $estateId);
    }

    if ($postAction === 'mark_paid') {
        $id = (int)(post_param('id', 0) ?? 0);
        $paidMethod = (string)post_param('paid_method', 'bank_transfer');
        $exp = $db->fetchOne('SELECT id, estate_id, expense_number FROM expenses WHERE id = ?', [$id]);
        if ($exp) {
            assert_can_access_estate((int)$exp['estate_id']);
            $db->execute(
                "UPDATE expenses SET payment_status = 'paid', payment_method = ?, paid_date = NOW() WHERE id = ?",
                [$paidMethod, $id]
            );
            flash_set('success', "Expense {$exp['expense_number']} marked as Disbursed/Paid.");
        }
        redirect('expenses.php?estate_id=' . $estateId);
    }

    if ($postAction === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        $exp = $db->fetchOne('SELECT id, estate_id, expense_number, receipt_file FROM expenses WHERE id = ?', [$id]);
        if ($exp) {
            assert_can_access_estate((int)$exp['estate_id']);
            if (!empty($exp['receipt_file'])) {
                $dir = get_expense_upload_dir();
                @unlink($dir . '/' . basename($exp['receipt_file']));
            }
            $db->execute('DELETE FROM expenses WHERE id = ?', [$id]);
            flash_set('success', "Expense {$exp['expense_number']} deleted.");
        }
        redirect('expenses.php?estate_id=' . $estateId);
    }
}

// Fetch categories & chart of accounts for forms & filters
$categories = $db->fetchAll("SELECT id, name, type FROM expense_categories ORDER BY name ASC") ?: [];
$accounts = $db->fetchAll("SELECT id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC") ?: [];
$vendors = $db->fetchAll("SELECT id, name, company, specialization FROM vendors WHERE status = 'active' ORDER BY name ASC") ?: [];
$properties = $estateId > 0 ? ($db->fetchAll("SELECT id, name FROM properties WHERE estate_id = ? ORDER BY name ASC", [$estateId]) ?: []) : [];

// Editing record
$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM expenses WHERE id = ?', [$editId]);
    if ($editing) {
        assert_can_access_estate((int)$editing['estate_id']);
    }
}

// Build query for list
$where = [];
$params = [];
if ($estateId > 0) {
    $where[] = 'e.estate_id = ?';
    $params[] = $estateId;
}
if ($statusFilter !== '') {
    $where[] = 'e.payment_status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter > 0) {
    $where[] = 'e.category_id = ?';
    $params[] = $categoryFilter;
}
if ($search !== '') {
    $where[] = '(e.expense_number LIKE ? OR e.title LIKE ? OR e.invoice_reference LIKE ? OR v.name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$expenses = $db->fetchAll(
    "SELECT e.*, est.name AS estate_name, ec.name AS category_name, ec.type AS category_type,
            v.name AS vendor_name, coa.code AS account_code, coa.name AS account_name,
            u.first_name, u.last_name, app.first_name AS approver_fname, app.last_name AS approver_lname
     FROM expenses e
     INNER JOIN estates est ON est.id = e.estate_id
     INNER JOIN expense_categories ec ON ec.id = e.category_id
     LEFT JOIN chart_of_accounts coa ON coa.id = e.account_id
     LEFT JOIN vendors v ON v.id = e.vendor_id
     LEFT JOIN users u ON u.id = e.recorded_by
     LEFT JOIN users app ON app.id = e.approved_by
     {$whereSql}
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 200",
    $params
) ?: [];

// Summary Totals
$totalsSummary = $db->fetchOne(
    "SELECT 
        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN payment_status = 'approved' THEN total_amount ELSE 0 END), 0) AS total_approved,
        COALESCE(SUM(CASE WHEN payment_status = 'pending_approval' THEN total_amount ELSE 0 END), 0) AS total_pending,
        COUNT(*) AS total_count
     FROM expenses e
     {$whereSql}",
    $params
) ?: ['total_paid' => 0, 'total_approved' => 0, 'total_pending' => 0, 'total_count' => 0];

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Summary Top Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <div class="col-12 col-md-4">
                    <div class="card card-flush bg-light-success border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold text-success text-uppercase">Disbursed / Paid</span>
                            <div class="fs-2x fw-bolder text-gray-900"><?= format_money((float)$totalsSummary['total_paid']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-flush bg-light-primary border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold text-primary text-uppercase">Approved (Awaiting Payout)</span>
                            <div class="fs-2x fw-bolder text-gray-900"><?= format_money((float)$totalsSummary['total_approved']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card card-flush bg-light-warning border-0">
                        <div class="card-body py-4">
                            <span class="fs-7 fw-bold text-warning text-uppercase">Pending Authorization</span>
                            <div class="fs-2x fw-bolder text-gray-900"><?= format_money((float)$totalsSummary['total_pending']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create / Edit Form Card (if requested) -->
            <?php if ($action === 'new' || $editing): ?>
                <div class="card mb-6 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h3 class="card-title fw-bolder text-dark">
                            <?= $editing ? 'Edit Expense Voucher: ' . e($editing['expense_number']) : 'Record New Expense Voucher' ?>
                        </h3>
                        <div class="card-toolbar">
                            <a href="expenses.php?estate_id=<?= $estateId ?>" class="btn btn-sm btn-light">Cancel</a>
                        </div>
                    </div>
                    <div class="card-body p-6">
                        <form method="post" action="expenses.php" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                            <div class="row g-4 mb-4">
                                <div class="col-12 col-md-4">
                                    <label class="form-label required">Estate</label>
                                    <select name="estate_id" class="form-select" required>
                                        <?php foreach ($estates as $est): ?>
                                            <option value="<?= (int)$est['id'] ?>" <?= ((int)($editing['estate_id'] ?? $estateId) === (int)$est['id']) ? 'selected' : '' ?>>
                                                <?= e($est['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label required">Expense Category</label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int)$cat['id'] ?>" <?= ((int)($editing['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                                                <?= e($cat['name']) ?> (<?= e(ucfirst($cat['type'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label">General Ledger Account</label>
                                    <select name="account_id" class="form-select">
                                        <option value="">-- Select GL Account --</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= (int)$acc['id'] ?>" <?= ((int)($editing['account_id'] ?? 0) === (int)$acc['id']) ? 'selected' : '' ?>>
                                                <?= e($acc['code']) ?> - <?= e($acc['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-12 col-md-8">
                                    <label class="form-label required">Title / Description of Expense</label>
                                    <input type="text" name="title" class="form-control" required 
                                           placeholder="e.g. 1,000 Liters Generator Diesel Supply for Estate Central Plant"
                                           value="<?= e($editing['title'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">Vendor / Contractor</label>
                                    <select name="vendor_id" class="form-select">
                                        <option value="">-- Direct / Internal / Staff --</option>
                                        <?php foreach ($vendors as $v): ?>
                                            <option value="<?= (int)$v['id'] ?>" <?= ((int)($editing['vendor_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>>
                                                <?= e($v['name']) ?> <?= $v['company'] ? ('(' . e($v['company']) . ')') : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Financial Breakdown -->
                            <div class="row g-4 mb-4 bg-light p-4 rounded">
                                <div class="col-12 col-md-3">
                                    <label class="form-label required">Base Amount (₦)</label>
                                    <input type="number" step="0.01" name="amount" id="exp_amount" class="form-control" required
                                           value="<?= (float)($editing['amount'] ?? '') ?>" oninput="calculateTotalExpense()">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">VAT / Tax Amount (₦)</label>
                                    <input type="number" step="0.01" name="tax_amount" id="exp_tax" class="form-control"
                                           value="<?= (float)($editing['tax_amount'] ?? 0) ?>" oninput="calculateTotalExpense()">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Withholding Tax WHT (₦)</label>
                                    <input type="number" step="0.01" name="withholding_tax" id="exp_wht" class="form-control"
                                           value="<?= (float)($editing['withholding_tax'] ?? 0) ?>" oninput="calculateTotalExpense()">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label fw-bold text-dark">Net Payable Total (₦)</label>
                                    <input type="text" id="exp_total_display" class="form-control fw-bolder bg-white text-primary fs-5" readonly
                                           value="<?= format_money((float)($editing['total_amount'] ?? 0)) ?>">
                                </div>
                            </div>

                            <div class="row g-4 mb-4">
                                <div class="col-12 col-md-3">
                                    <label class="form-label required">Expense Date</label>
                                    <input type="date" name="expense_date" class="form-control" required 
                                           value="<?= e($editing['expense_date'] ?? date('Y-m-d')) ?>">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Payment Method</label>
                                    <select name="payment_method" class="form-select">
                                        <?php foreach (['bank_transfer' => 'Bank Transfer', 'cash' => 'Cash / Petty Cash', 'card' => 'Debit/Credit Card', 'cheque' => 'Cheque', 'other' => 'Other'] as $k => $lbl): ?>
                                            <option value="<?= $k ?>" <?= ($editing['payment_method'] ?? 'bank_transfer') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Invoice / Receipt Ref #</label>
                                    <input type="text" name="invoice_reference" class="form-control" placeholder="e.g. VEND-INV-8821"
                                           value="<?= e($editing['invoice_reference'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Receipt / Bill Attachment</label>
                                    <input type="file" name="receipt_file" class="form-control" accept="image/*,.pdf">
                                    <?php if (!empty($editing['receipt_file'])): ?>
                                        <div class="mt-1 fs-8">
                                            Current: <a href="<?= e(get_expense_receipt_url($editing['receipt_file'])) ?>" target="_blank" class="text-primary fw-bold">View Attached File</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Internal Accounting Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Additional audit notes, authorization details, or remarks..."><?= e($editing['notes'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary"><?= $editing ? 'Update Voucher' : 'Save & Submit Voucher' ?></button>
                                <a href="expenses.php?estate_id=<?= $estateId ?>" class="btn btn-light">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Table Card -->
            <div class="card shadow-sm border-0">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <!-- Search Form -->
                        <form method="get" action="expenses.php" class="d-flex align-items-center position-relative my-1">
                            <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                            <input type="hidden" name="category_id" value="<?= $categoryFilter ?>">
                            <i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
                            <input type="text" name="search" value="<?= e($search) ?>" class="form-control form-control-solid w-250px ps-12" placeholder="Search vouchers, vendors..." />
                        </form>
                    </div>

                    <div class="card-toolbar gap-3">
                        <!-- Category Filter -->
                        <select class="form-select form-select-solid form-select-sm w-150px" onchange="location.href='expenses.php?estate_id=<?= $estateId ?>&status=<?= e($statusFilter) ?>&search=<?= urlencode($search) ?>&category_id=' + this.value">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Status Filter -->
                        <select class="form-select form-select-solid form-select-sm w-150px" onchange="location.href='expenses.php?estate_id=<?= $estateId ?>&category_id=<?= $categoryFilter ?>&search=<?= urlencode($search) ?>&status=' + this.value">
                            <option value="">All Statuses</option>
                            <option value="pending_approval" <?= $statusFilter === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
                            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid / Disbursed</option>
                            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>

                        <!-- CSV Export -->
                        <a href="expenses.php?action=export_csv&estate_id=<?= $estateId ?>&status=<?= e($statusFilter) ?>&category_id=<?= $categoryFilter ?>" class="btn btn-sm btn-light-primary">
                            <i class="ki-duotone ki-file-down fs-4 me-1"><span class="path1"></span><span class="path2"></span></i> CSV
                        </a>

                        <!-- New Expense Button -->
                        <a href="expenses.php?action=new&estate_id=<?= $estateId ?>" class="btn btn-sm btn-primary">
                            <i class="ki-duotone ki-plus fs-4 me-1"></i> New Expense
                        </a>
                    </div>
                </div>

                <div class="card-body py-4">
                    <?php if (empty($expenses)): ?>
                        <div class="text-center py-15 text-gray-500">
                            <i class="ki-duotone ki-wallet fs-3x text-muted mb-3"><span class="path1"></span><span class="path2"></span></i>
                            <div class="fs-5 fw-bold">No expense records found</div>
                            <div class="fs-7 text-muted mt-1">Try adjusting your filters or record a new operational expense.</div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-row-dashed align-middle gs-0 gy-4">
                                <thead>
                                    <tr class="fs-7 fw-bolder text-gray-500 text-uppercase border-bottom border-gray-200">
                                        <th>Voucher #</th>
                                        <th>Expense Details</th>
                                        <th>Category & GL</th>
                                        <th>Vendor</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="fs-6 fw-semibold text-gray-700">
                                    <?php foreach ($expenses as $eItem): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-gray-900"><?= e($eItem['expense_number']) ?></div>
                                                <div class="text-muted fs-8"><?= date('M d, Y', strtotime($eItem['expense_date'])) ?></div>
                                                <?php if (!empty($eItem['invoice_reference'])): ?>
                                                    <span class="badge badge-light fs-9 text-muted">Ref: <?= e($eItem['invoice_reference']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="text-gray-900 fw-bold"><?= e($eItem['title']) ?></div>
                                                <div class="text-muted fs-8">Estate: <?= e($eItem['estate_name']) ?></div>
                                                <?php if (!empty($eItem['receipt_file'])): ?>
                                                    <a href="<?= e(get_expense_receipt_url($eItem['receipt_file'])) ?>" target="_blank" class="badge badge-light-primary fs-9 mt-1">
                                                        <i class="ki-duotone ki-paper-clip fs-8 me-1"></i> Receipt
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-light fs-8"><?= e($eItem['category_name']) ?></span>
                                                <?php if (!empty($eItem['account_code'])): ?>
                                                    <div class="text-muted fs-9 mt-1"><?= e($eItem['account_code']) ?> <?= e($eItem['account_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="text-gray-800"><?= e($eItem['vendor_name'] ?: 'Internal / Direct') ?></div>
                                                <div class="text-muted fs-8 text-uppercase"><?= str_replace('_', ' ', $eItem['payment_method']) ?></div>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bolder text-gray-900 fs-5"><?= format_money((float)$eItem['total_amount']) ?></div>
                                                <?php if ((float)$eItem['tax_amount'] > 0 || (float)$eItem['withholding_tax'] > 0): ?>
                                                    <div class="text-muted fs-9">Base: <?= format_money((float)$eItem['amount']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $stBadge = 'badge-light-warning';
                                                if ($eItem['payment_status'] === 'paid') $stBadge = 'badge-light-success';
                                                elseif ($eItem['payment_status'] === 'approved') $stBadge = 'badge-light-primary';
                                                elseif ($eItem['payment_status'] === 'rejected') $stBadge = 'badge-light-danger';
                                                ?>
                                                <span class="badge <?= $stBadge ?> text-uppercase fs-8"><?= e(str_replace('_', ' ', $eItem['payment_status'])) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light btn-active-light-primary" type="button" data-bs-toggle="dropdown">
                                                        Actions <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end p-2">
                                                        <?php if ($eItem['payment_status'] === 'pending_approval'): ?>
                                                            <li>
                                                                <form method="post" action="expenses.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="approve">
                                                                    <input type="hidden" name="id" value="<?= (int)$eItem['id'] ?>">
                                                                    <button type="submit" class="dropdown-item text-primary fw-bold">
                                                                        <i class="ki-duotone ki-check fs-5 me-2 text-primary"></i> Approve Voucher
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="post" action="expenses.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="reject">
                                                                    <input type="hidden" name="id" value="<?= (int)$eItem['id'] ?>">
                                                                    <button type="submit" class="dropdown-item text-danger">
                                                                        <i class="ki-duotone ki-cross fs-5 me-2 text-danger"></i> Reject Voucher
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($eItem['payment_status'] === 'approved'): ?>
                                                            <li>
                                                                <form method="post" action="expenses.php">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="mark_paid">
                                                                    <input type="hidden" name="id" value="<?= (int)$eItem['id'] ?>">
                                                                    <button type="submit" class="dropdown-item text-success fw-bold">
                                                                        <i class="ki-duotone ki-dollar fs-5 me-2 text-success"></i> Mark as Paid
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <a class="dropdown-item" href="expenses.php?edit_id=<?= (int)$eItem['id'] ?>&estate_id=<?= (int)$eItem['estate_id'] ?>">
                                                                <i class="ki-duotone ki-pencil fs-5 me-2"></i> Edit
                                                            </a>
                                                        </li>

                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="post" action="expenses.php" onsubmit="return confirm('Are you sure you want to delete this expense voucher?');">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?= (int)$eItem['id'] ?>">
                                                                <button type="submit" class="dropdown-item text-danger">
                                                                    <i class="ki-duotone ki-trash fs-5 me-2 text-danger"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
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
</div>

<script>
function calculateTotalExpense() {
    var amount = parseFloat(document.getElementById('exp_amount').value) || 0;
    var tax = parseFloat(document.getElementById('exp_tax').value) || 0;
    var wht = parseFloat(document.getElementById('exp_wht').value) || 0;
    var total = Math.max(0, amount + tax - wht);
    document.getElementById('exp_total_display').value = '₦' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
