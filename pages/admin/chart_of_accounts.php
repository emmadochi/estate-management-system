<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'accountant']);

$pageTitle = 'Chart of Accounts – EstatePro';
$pageHeading = 'Chart of Accounts';
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
$typeFilter = (string)(get_param('type', '') ?? '');
$editId = (int)(get_param('edit_id', 0) ?? 0);
$action = (string)(get_param('action', '') ?? '');

if ($method === 'POST') {
    verify_csrf();
    $postAction = (string)post_param('action', '');

    if ($postAction === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        if ($estateIdPost > 0) {
            assert_can_access_estate($estateIdPost);
        }

        $code = trim((string)post_param('code', ''));
        $name = trim((string)post_param('name', ''));
        $type = (string)post_param('type', 'expense');
        $description = trim((string)post_param('description', ''));
        $isActive = (int)post_param('is_active', 1);

        if ($code === '' || $name === '') {
            flash_set('error', 'Account code and name are required.');
            redirect('chart_of_accounts.php?estate_id=' . $estateIdPost);
        }

        try {
            if ($id > 0) {
                $db->execute(
                    "UPDATE chart_of_accounts 
                     SET estate_id = NULLIF(?, 0), code = ?, name = ?, type = ?, description = ?, is_active = ? 
                     WHERE id = ?",
                    [$estateIdPost, $code, $name, $type, $description, $isActive, $id]
                );
                flash_set('success', "Account {$code} updated successfully.");
            } else {
                $db->execute(
                    "INSERT INTO chart_of_accounts (estate_id, code, name, type, description, is_active)
                     VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?)",
                    [$estateIdPost, $code, $name, $type, $description, $isActive]
                );
                flash_set('success', "GL Account {$code} added successfully.");
            }
        } catch (Throwable $e) {
            flash_set('error', 'Error: ' . $e->getMessage());
        }
        redirect('chart_of_accounts.php?estate_id=' . $estateIdPost);
    }
}

// Fetch list of accounts
$where = [];
$params = [];
if ($estateId > 0) {
    $where[] = '(estate_id IS NULL OR estate_id = ?)';
    $params[] = $estateId;
}
if ($typeFilter !== '') {
    $where[] = 'type = ?';
    $params[] = $typeFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$accounts = $db->fetchAll(
    "SELECT * FROM chart_of_accounts {$whereSql} ORDER BY code ASC",
    $params
) ?: [];

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM chart_of_accounts WHERE id = ?', [$editId]);
}

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Filter & Action Header -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-category fs-2x text-primary"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">General Ledger Chart of Accounts</h1>
                                <span class="text-muted fs-7">Standardized real estate accounting categories and account codes</span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <select class="form-select form-select-sm w-150px" onchange="location.href='chart_of_accounts.php?estate_id=<?= $estateId ?>&type=' + this.value">
                                <option value="">All Account Types</option>
                                <?php foreach (['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expenses'] as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>

                            <a href="chart_of_accounts.php?action=new&estate_id=<?= $estateId ?>" class="btn btn-sm btn-primary">
                                <i class="ki-duotone ki-plus fs-4 me-1"></i> New Account
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form if new or edit -->
            <?php if ($action === 'new' || $editing): ?>
                <div class="card mb-6 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h3 class="card-title fw-bolder text-dark">
                            <?= $editing ? 'Edit GL Account: ' . e($editing['code']) : 'Add General Ledger Account' ?>
                        </h3>
                    </div>
                    <div class="card-body p-6">
                        <form method="post" action="chart_of_accounts.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

                            <div class="row g-4 mb-4">
                                <div class="col-12 col-md-3">
                                    <label class="form-label required">Account Code</label>
                                    <input type="text" name="code" class="form-control" required placeholder="e.g. 5200"
                                           value="<?= e($editing['code'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label required">Account Name</label>
                                    <input type="text" name="name" class="form-control" required placeholder="e.g. Security Guard Wages"
                                           value="<?= e($editing['name'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label required">Account Type</label>
                                    <select name="type" class="form-select" required>
                                        <?php foreach (['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'revenue' => 'Revenue', 'expense' => 'Expense'] as $k => $lbl): ?>
                                            <option value="<?= $k ?>" <?= ($editing['type'] ?? 'expense') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-2">
                                    <label class="form-label">Scope</label>
                                    <select name="estate_id" class="form-select">
                                        <option value="0">Global (All)</option>
                                        <?php foreach ($estates as $est): ?>
                                            <option value="<?= (int)$est['id'] ?>" <?= ((int)($editing['estate_id'] ?? 0) === (int)$est['id']) ? 'selected' : '' ?>>
                                                <?= e($est['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Description / Remarks</label>
                                <textarea name="description" class="form-control" rows="2"><?= e($editing['description'] ?? '') ?></textarea>
                            </div>

                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Account' ?></button>
                                <a href="chart_of_accounts.php?estate_id=<?= $estateId ?>" class="btn btn-light">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Table of Accounts -->
            <div class="card shadow-sm border-0">
                <div class="card-body p-6">
                    <div class="table-responsive">
                        <table class="table table-row-dashed align-middle gs-0 gy-4">
                            <thead>
                                <tr class="fs-7 fw-bolder text-gray-500 text-uppercase border-bottom border-gray-200">
                                    <th>Code</th>
                                    <th>Account Name</th>
                                    <th>Type</th>
                                    <th>Scope</th>
                                    <th>Description</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="fs-6 fw-semibold text-gray-700">
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td class="fw-bolder text-gray-900"><?= e($acc['code']) ?></td>
                                        <td class="fw-bold text-gray-800"><?= e($acc['name']) ?></td>
                                        <td>
                                            <?php
                                            $bClass = 'badge-light-primary';
                                            if ($acc['type'] === 'revenue') $bClass = 'badge-light-success';
                                            elseif ($acc['type'] === 'expense') $bClass = 'badge-light-danger';
                                            elseif ($acc['type'] === 'liability') $bClass = 'badge-light-warning';
                                            ?>
                                            <span class="badge <?= $bClass ?> text-uppercase fs-8"><?= e($acc['type']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-light fs-8"><?= $acc['estate_id'] ? 'Estate Specific' : 'Global' ?></span>
                                        </td>
                                        <td class="text-muted fs-7"><?= e($acc['description'] ?: '—') ?></td>
                                        <td class="text-end">
                                            <a href="chart_of_accounts.php?edit_id=<?= (int)$acc['id'] ?>&estate_id=<?= $estateId ?>" class="btn btn-sm btn-icon btn-light-primary">
                                                <i class="ki-duotone ki-pencil fs-5"></i>
                                            </a>
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

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
