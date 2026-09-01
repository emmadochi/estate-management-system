<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Bulk Data Onboarding Importer – EstatePro';
$pageHeading = 'Bulk Data Import';
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

// 1. Download CSV Sample Templates
if ($action === 'download_template') {
    $type = (string)get_param('type', 'units');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=estatepro_' . $type . '_template.csv');
    $out = fopen('php://output', 'w');

    if ($type === 'properties') {
        fputcsv($out, ['Property Name', 'Type (block/building/house/commercial)', 'Address', 'Total Units']);
        fputcsv($out, ['Block A - Emerald Tower', 'building', 'Plot 4 Palm Grove', '12']);
        fputcsv($out, ['Block B - Sapphire Court', 'building', 'Plot 6 Palm Grove', '16']);
    } elseif ($type === 'units') {
        fputcsv($out, ['Unit Number', 'Property Name', 'Unit Type (apartment/flat/duplex/shop/office)', 'Bedrooms', 'Bathrooms', 'Rent Amount', 'Service Charge']);
        fputcsv($out, ['A-101', 'Block A - Emerald Tower', 'apartment', '2', '2', '2500000.00', '350000.00']);
        fputcsv($out, ['A-102', 'Block A - Emerald Tower', 'apartment', '3', '3', '3500000.00', '450000.00']);
        fputcsv($out, ['B-201', 'Block B - Sapphire Court', 'flat', '2', '2', '2200000.00', '300000.00']);
    } elseif ($type === 'tenants') {
        fputcsv($out, ['First Name', 'Last Name', 'Email', 'Phone', 'Unit Number', 'Property Name', 'Rent Amount', 'Lease Start Date (YYYY-MM-DD)', 'Lease End Date (YYYY-MM-DD)']);
        fputcsv($out, ['Chinedu', 'Okonkwo', 'chinedu.o@example.com', '08031234567', 'A-101', 'Block A - Emerald Tower', '2500000.00', date('Y-01-01'), date('Y-12-31')]);
        fputcsv($out, ['Amina', 'Bello', 'amina.b@example.com', '08029876543', 'A-102', 'Block A - Emerald Tower', '3500000.00', date('Y-02-01'), date('Y-01-31', strtotime('+1 year'))]);
    }
    fclose($out);
    exit;
}

// 2. Handle CSV Upload and Processing
$importResults = null;

if ($method === 'POST') {
    verify_csrf();
    $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
    assert_can_access_estate($estateIdPost);
    $importType = (string)post_param('import_type', 'units');

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please upload a valid CSV file.');
        redirect('bulk_import.php?estate_id=' . $estateIdPost);
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if ($handle === false) {
        flash_set('error', 'Could not read uploaded CSV file.');
        redirect('bulk_import.php?estate_id=' . $estateIdPost);
    }

    // Skip header line
    $header = fgetcsv($handle);
    $rowsImported = 0;
    $errors = [];
    $conn = $db->getConnection();

    try {
        $conn->beginTransaction();

        $lineNum = 1;
        while (($row = fgetcsv($handle, 2000, ',')) !== false) {
            $lineNum++;
            if (empty(array_filter($row))) continue; // skip blank rows

            if ($importType === 'properties') {
                $pName = trim((string)($row[0] ?? ''));
                $pType = strtolower(trim((string)($row[1] ?? 'building')));
                $pAddress = trim((string)($row[2] ?? ''));
                $pUnits = (int)($row[3] ?? 0);

                if ($pName === '') {
                    $errors[] = "Line {$lineNum}: Property Name is required.";
                    continue;
                }

                $db->execute(
                    "INSERT INTO properties (estate_id, name, type, address, total_units, status)
                     VALUES (?, ?, ?, ?, ?, 'active')
                     ON DUPLICATE KEY UPDATE address = VALUES(address), total_units = VALUES(total_units)",
                    [$estateIdPost, $pName, in_array($pType, ['block', 'building', 'house', 'commercial', 'other']) ? $pType : 'building', $pAddress, $pUnits]
                );
                $rowsImported++;
            } elseif ($importType === 'units') {
                $uNum = trim((string)($row[0] ?? ''));
                $pName = trim((string)($row[1] ?? ''));
                $uType = strtolower(trim((string)($row[2] ?? 'apartment')));
                $beds = (int)($row[3] ?? 0);
                $baths = (int)($row[4] ?? 0);
                $rent = (float)($row[5] ?? 0);
                $serviceCharge = (float)($row[6] ?? 0);

                if ($uNum === '' || $pName === '') {
                    $errors[] = "Line {$lineNum}: Unit Number and Property Name are required.";
                    continue;
                }

                // Resolve property_id
                $prop = $db->fetchOne('SELECT id FROM properties WHERE estate_id = ? AND name = ?', [$estateIdPost, $pName]);
                if (!$prop) {
                    $db->execute("INSERT INTO properties (estate_id, name, type, status) VALUES (?, ?, 'building', 'active')", [$estateIdPost, $pName]);
                    $propId = (int)$conn->lastInsertId();
                } else {
                    $propId = (int)$prop['id'];
                }

                $db->execute(
                    "INSERT INTO units (estate_id, property_id, unit_number, unit_type, bedrooms, bathrooms, rent_amount, service_charge, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vacant')
                     ON DUPLICATE KEY UPDATE rent_amount = VALUES(rent_amount), service_charge = VALUES(service_charge)",
                    [$estateIdPost, $propId, $uNum, in_array($uType, ['apartment', 'flat', 'duplex', 'penthouse', 'shop', 'office', 'warehouse', 'other']) ? $uType : 'apartment', $beds, $baths, $rent, $serviceCharge]
                );
                $rowsImported++;
            } elseif ($importType === 'tenants') {
                $fName = trim((string)($row[0] ?? ''));
                $lName = trim((string)($row[1] ?? ''));
                $email = strtolower(trim((string)($row[2] ?? '')));
                $phone = trim((string)($row[3] ?? ''));
                $uNum = trim((string)($row[4] ?? ''));
                $pName = trim((string)($row[5] ?? ''));
                $rent = (float)($row[6] ?? 0);
                $start = trim((string)($row[7] ?? date('Y-01-01')));
                $end = trim((string)($row[8] ?? date('Y-12-31')));

                if ($fName === '' || $lName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $uNum === '') {
                    $errors[] = "Line {$lineNum}: Name, Valid Email and Unit Number are required.";
                    continue;
                }

                // 1. Create or Find User
                $usr = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
                if (!$usr) {
                    $pwd = password_hash('password', PASSWORD_BCRYPT);
                    $db->execute("INSERT INTO users (email, password, first_name, last_name, phone, role, status) VALUES (?, ?, ?, ?, ?, 'tenant', 'active')",
                        [$email, $pwd, $fName, $lName, $phone]
                    );
                    $userId = (int)$conn->lastInsertId();
                } else {
                    $userId = (int)$usr['id'];
                }

                // 2. Resolve Unit
                $unit = $db->fetchOne(
                    "SELECT u.id FROM units u
                     INNER JOIN properties p ON p.id = u.property_id
                     WHERE u.estate_id = ? AND u.unit_number = ? AND p.name = ?",
                    [$estateIdPost, $uNum, $pName]
                );

                if (!$unit) {
                    $errors[] = "Line {$lineNum}: Unit '{$uNum}' in property '{$pName}' was not found. Please import units first.";
                    continue;
                }
                $unitId = (int)$unit['id'];

                // 3. Create Tenant & Link Estate
                $tnt = $db->fetchOne('SELECT id FROM tenants WHERE user_id = ? AND estate_id = ?', [$userId, $estateIdPost]);
                if (!$tnt) {
                    $db->execute(
                        "INSERT INTO tenants (user_id, estate_id, unit_id, status, moved_in_date) VALUES (?, ?, ?, 'active', ?)",
                        [$userId, $estateIdPost, $unitId, $start]
                    );
                    $tenantId = (int)$conn->lastInsertId();
                } else {
                    $tenantId = (int)$tnt['id'];
                    $db->execute("UPDATE tenants SET unit_id = ?, status = 'active' WHERE id = ?", [$unitId, $tenantId]);
                }

                // Link user_estates
                $db->execute("INSERT IGNORE INTO user_estates (user_id, estate_id, role) VALUES (?, ?, 'tenant')", [$userId, $estateIdPost]);
                // Mark unit occupied
                $db->execute("UPDATE units SET status = 'occupied' WHERE id = ?", [$unitId]);

                // 4. Create Active Lease
                $leaseNum = 'LS-' . $estateIdPost . '-' . date('Y') . '-' . str_pad((string)$tenantId, 4, '0', STR_PAD_LEFT);
                $db->execute(
                    "INSERT INTO leases (tenant_id, unit_id, lease_number, start_date, end_date, rent_amount, payment_frequency, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'yearly', 'active')
                     ON DUPLICATE KEY UPDATE rent_amount = VALUES(rent_amount), start_date = VALUES(start_date), end_date = VALUES(end_date)",
                    [$tenantId, $unitId, $leaseNum, $start, $end, $rent]
                );

                $rowsImported++;
            }
        }

        $conn->commit();
        fclose($handle);

        $importResults = [
            'imported' => $rowsImported,
            'errors' => $errors,
            'type' => $importType
        ];
        flash_set('success', "Import completed: {$rowsImported} records processed successfully.");

    } catch (Throwable $e) {
        $conn->rollBack();
        fclose($handle);
        flash_set('error', 'Import Database Error: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-column flex-column-fluid">
    <div id="kt_app_content" class="app-content flex-column-fluid">
        <div id="kt_app_content_container" class="app-container container-fluid">

            <!-- Top Title Banner -->
            <div class="card mb-6 shadow-sm border-0">
                <div class="card-body p-5">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="symbol symbol-45px symbol-circle bg-light-primary">
                                <i class="ki-duotone ki-file-up fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <div>
                                <h1 class="text-dark fw-bolder fs-3 mb-0">Bulk Data Onboarding Wizard</h1>
                                <span class="text-muted fs-7">Instantly onboard 100+ properties, units, and tenant records using CSV templates</span>
                            </div>
                        </div>

                        <div>
                            <select class="form-select form-select-sm w-200px" onchange="location.href='bulk_import.php?estate_id=' + this.value">
                                <?php foreach ($estates as $est): ?>
                                    <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                        <?= e($est['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Import Step Cards -->
            <div class="row g-5 g-xl-8 mb-6">
                <!-- 1. Properties Template -->
                <div class="col-12 col-md-4">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-6 d-flex flex-column justify-content-between">
                            <div>
                                <div class="badge badge-light-primary mb-3">Step 1</div>
                                <h3 class="fs-4 fw-bolder text-dark mb-2">1. Properties & Blocks</h3>
                                <p class="text-muted fs-7 mb-4">Import building blocks, towers, and residential wings for your estate.</p>
                            </div>
                            <a href="bulk_import.php?action=download_template&type=properties" class="btn btn-sm btn-light-primary w-100">
                                <i class="ki-duotone ki-file-down fs-5 me-1"><span class="path1"></span><span class="path2"></span></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 2. Units Template -->
                <div class="col-12 col-md-4">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-6 d-flex flex-column justify-content-between">
                            <div>
                                <div class="badge badge-light-success mb-3">Step 2</div>
                                <h3 class="fs-4 fw-bolder text-dark mb-2">2. Units & Rent Tariffs</h3>
                                <p class="text-muted fs-7 mb-4">Import apartment numbers, bedroom counts, rent amounts and service charges.</p>
                            </div>
                            <a href="bulk_import.php?action=download_template&type=units" class="btn btn-sm btn-light-success w-100">
                                <i class="ki-duotone ki-file-down fs-5 me-1"><span class="path1"></span><span class="path2"></span></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 3. Tenants Template -->
                <div class="col-12 col-md-4">
                    <div class="card card-flush shadow-sm border-0 h-100">
                        <div class="card-body p-6 d-flex flex-column justify-content-between">
                            <div>
                                <div class="badge badge-light-warning mb-3">Step 3</div>
                                <h3 class="fs-4 fw-bolder text-dark mb-2">3. Tenants & Leases</h3>
                                <p class="text-muted fs-7 mb-4">Import tenant names, phone numbers, emails, assigned units, and active lease periods.</p>
                            </div>
                            <a href="bulk_import.php?action=download_template&type=tenants" class="btn btn-sm btn-light-warning w-100">
                                <i class="ki-duotone ki-file-down fs-5 me-1"><span class="path1"></span><span class="path2"></span></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Card -->
            <div class="card shadow-sm border-0 mb-6">
                <div class="card-header bg-light">
                    <h3 class="card-title fw-bolder text-dark">Upload & Commit CSV File</h3>
                </div>
                <div class="card-body p-6">
                    <form method="post" action="bulk_import.php" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <div class="row g-4 mb-4">
                            <div class="col-12 col-md-4">
                                <label class="form-label required">Target Estate</label>
                                <select name="estate_id" class="form-select" required>
                                    <?php foreach ($estates as $est): ?>
                                        <option value="<?= (int)$est['id'] ?>" <?= $estateId === (int)$est['id'] ? 'selected' : '' ?>>
                                            <?= e($est['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label required">Data Model Type</label>
                                <select name="import_type" class="form-select" required>
                                    <option value="properties">1. Properties / Blocks</option>
                                    <option value="units" selected>2. Units & Rent Rates</option>
                                    <option value="tenants">3. Tenants & Active Leases</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label required">Select CSV File</label>
                                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between pt-3">
                            <span class="text-muted fs-7">Note: The system automatically links properties, units and users in an atomic transaction.</span>
                            <button type="submit" class="btn btn-primary">
                                <i class="ki-duotone ki-file-up fs-4 me-1"></i> Start Bulk Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Import Logs / Results -->
            <?php if ($importResults !== null): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-header">
                        <h4 class="card-title fw-bold">Import Execution Results</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success d-flex align-items-center p-4 mb-4">
                            <i class="ki-duotone ki-check-circle fs-2hx text-success me-3"><span class="path1"></span><span class="path2"></span></i>
                            <div>
                                <h5 class="mb-1">Successfully Imported: <?= $importResults['imported'] ?> <?= e($importResults['type']) ?></h5>
                                <span class="fs-7 text-muted">All records are now committed to the database.</span>
                            </div>
                        </div>

                        <?php if (!empty($importResults['errors'])): ?>
                            <div class="alert alert-danger p-4">
                                <h5 class="mb-2">Errors / Skipped Rows (<?= count($importResults['errors']) ?>):</h5>
                                <ul class="mb-0 fs-7">
                                    <?php foreach ($importResults['errors'] as $err): ?>
                                        <li><?= e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/bottom.php'; ?>
