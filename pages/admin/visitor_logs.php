<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'security']);

// Get estate ID for the current user
$estateId = isset($_GET['estate_id']) ? (int)$_GET['estate_id'] : 0;
if (!is_super_admin()) {
    $estateId = normalize_estate_id($estateId);
}

// Get estates for dropdown
$estates = estates_for_current_user();

// Get all units for the estate
$units = [];
if (is_super_admin()) {
    $units = db()->fetchAll("SELECT id, unit_number, property_id FROM units ORDER BY unit_number");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $units = db()->fetchAll("SELECT id, unit_number, property_id FROM units WHERE estate_id IN ($placeholders) ORDER BY unit_number", $estateIds);
    }
}

// Get all tenants for the estate
$tenants = [];
if (is_super_admin()) {
    $tenants = db()->fetchAll("SELECT id, emergency_contact_name FROM tenants ORDER BY emergency_contact_name");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $tenants = db()->fetchAll("SELECT id, emergency_contact_name FROM tenants WHERE estate_id IN ($placeholders) ORDER BY emergency_contact_name", $estateIds);
    }
}

// Handle form submission for adding a new visitor
if ($_POST && isset($_POST['add_visitor'])) {
    verify_csrf();
    
    $visitorName = trim($_POST['visitor_name'] ?? '');
    $visitorPhone = trim($_POST['visitor_phone'] ?? '');
    $visitorEmail = trim($_POST['visitor_email'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $entryTime = trim($_POST['entry_time'] ?? date('Y-m-d H:i:s'));
    $gatePassNumber = trim($_POST['gate_pass_number'] ?? '');
    $vehicleRegistration = trim($_POST['vehicle_registration'] ?? '');
    $driverLicense = trim($_POST['driver_license'] ?? '');
    $hostName = trim($_POST['host_name'] ?? '');
    $hostPhone = trim($_POST['host_phone'] ?? '');
    $specialInstructions = trim($_POST['special_instructions'] ?? '');
    $emergencyContactVisitor = trim($_POST['emergency_contact_visitor'] ?? '');
    $emergencyContactPhoneVisitor = trim($_POST['emergency_contact_phone_visitor'] ?? '');
    
    // Validate required fields
    $errors = [];
    if (empty($visitorName)) {
        $errors[] = 'Visitor name is required';
    }
    if (empty($unitId)) {
        $errors[] = 'Unit is required';
    }
    if (empty($tenantId)) {
        $errors[] = 'Tenant is required';
    }
    
    if (empty($errors)) {
        try {
            // Insert the visitor log
            $userId = current_user_id();
            $status = 'checked_in'; // Default to checked in
            
            db()->insert(
                "INSERT INTO visitor_logs (
                    estate_id, unit_id, tenant_id, visitor_name, visitor_phone, visitor_email, purpose,
                    entry_time, gate_pass_number, vehicle_registration, driver_license, host_name, 
                    host_phone, special_instructions, emergency_contact_visitor, 
                    emergency_contact_phone_visitor, status, logged_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $estateId, $unitId, $tenantId, $visitorName, $visitorPhone, $visitorEmail, $purpose,
                    $entryTime, $gatePassNumber, $vehicleRegistration, $driverLicense, $hostName,
                    $hostPhone, $specialInstructions, $emergencyContactVisitor,
                    $emergencyContactPhoneVisitor, $status, $userId
                ]
            );
            
            flash_set('success', 'Visitor registered successfully');
            redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle visitor checkout
if (isset($_POST['checkout_visitor'])) {
    verify_csrf();
    $visitorId = (int)($_POST['visitor_id'] ?? 0);
    
    if ($visitorId) {
        try {
            db()->execute(
                "UPDATE visitor_logs SET exit_time = NOW(), status = 'checked_out' WHERE id = ?",
                [$visitorId]
            );
            flash_set('success', 'Visitor checked out successfully');
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

// Get visitor logs for the estate
$visitorLogs = [];
if (is_super_admin()) {
    $visitorLogs = db()->fetchAll("
        SELECT vl.*, u.first_name, u.last_name, t.emergency_contact_name as tenant_name, un.unit_number
        FROM visitor_logs vl
        LEFT JOIN users u ON vl.logged_by = u.id
        LEFT JOIN tenants t ON vl.tenant_id = t.id
        LEFT JOIN units un ON vl.unit_id = un.id
        ORDER BY vl.entry_time DESC
        LIMIT 100
    ");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $visitorLogs = db()->fetchAll("
            SELECT vl.*, u.first_name, u.last_name, t.emergency_contact_name as tenant_name, un.unit_number
            FROM visitor_logs vl
            LEFT JOIN users u ON vl.logged_by = u.id
            LEFT JOIN tenants t ON vl.tenant_id = t.id
            LEFT JOIN units un ON vl.unit_id = un.id
            WHERE vl.estate_id IN ($placeholders)
            ORDER BY vl.entry_time DESC
            LIMIT 100
        ", $estateIds);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Visitor Logs Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../assets/css/style.bundle.css">
    <link rel="stylesheet" href="../../assets/plugins/custom/datatables/datatables.bundle.css">
</head>
<body>
    <?php include __DIR__ . '/partials/top.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include __DIR__ . '/partials/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Visitor Logs Management</h1>
                </div>

                <!-- Visitor Registration Form -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Register New Visitor</h5>
                            </div>
                            <div class="card-body">
                                <?php if (isset($errors) && !empty($errors)): ?>
                                    <div class="alert alert-danger">
                                        <ul class="mb-0">
                                            <?php foreach ($errors as $error): ?>
                                                <li><?= e($error) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="add_visitor" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="visitor_name" class="form-label">Visitor Full Name</label>
                                                <input type="text" class="form-control" id="visitor_name" name="visitor_name" value="<?= e($_POST['visitor_name'] ?? '') ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="visitor_phone" class="form-label">Visitor Phone</label>
                                                <input type="tel" class="form-control" id="visitor_phone" name="visitor_phone" value="<?= e($_POST['visitor_phone'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="visitor_email" class="form-label">Visitor Email</label>
                                                <input type="email" class="form-control" id="visitor_email" name="visitor_email" value="<?= e($_POST['visitor_email'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="unit_id" class="form-label">Unit Visiting</label>
                                                <select class="form-select" id="unit_id" name="unit_id" required>
                                                    <option value="">Select Unit</option>
                                                    <?php foreach ($units as $unit): ?>
                                                        <option value="<?= (int)$unit['id'] ?>" <?= (isset($_POST['unit_id']) && (int)$_POST['unit_id'] === (int)$unit['id']) ? 'selected' : '' ?>>
                                                            <?= e($unit['unit_number']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="tenant_id" class="form-label">Tenant</label>
                                                <select class="form-select" id="tenant_id" name="tenant_id" required>
                                                    <option value="">Select Tenant</option>
                                                    <?php foreach ($tenants as $tenant): ?>
                                                        <option value="<?= (int)$tenant['id'] ?>" <?= (isset($_POST['tenant_id']) && (int)$_POST['tenant_id'] === (int)$tenant['id']) ? 'selected' : '' ?>>
                                                            <?= e($tenant['emergency_contact_name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="purpose" class="form-label">Purpose of Visit</label>
                                                <input type="text" class="form-control" id="purpose" name="purpose" value="<?= e($_POST['purpose'] ?? '') ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="entry_time" class="form-label">Entry Time</label>
                                                <input type="datetime-local" class="form-control" id="entry_time" name="entry_time" value="<?= e(date('Y-m-d\TH:i', strtotime($_POST['entry_time'] ?? date('Y-m-d H:i:s')))) ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="gate_pass_number" class="form-label">Gate Pass Number</label>
                                                <input type="text" class="form-control" id="gate_pass_number" name="gate_pass_number" value="<?= e($_POST['gate_pass_number'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="vehicle_registration" class="form-label">Vehicle Registration</label>
                                                <input type="text" class="form-control" id="vehicle_registration" name="vehicle_registration" value="<?= e($_POST['vehicle_registration'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="driver_license" class="form-label">Driver License</label>
                                                <input type="text" class="form-control" id="driver_license" name="driver_license" value="<?= e($_POST['driver_license'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="host_name" class="form-label">Host Name</label>
                                                <input type="text" class="form-control" id="host_name" name="host_name" value="<?= e($_POST['host_name'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="host_phone" class="form-label">Host Phone</label>
                                                <input type="tel" class="form-control" id="host_phone" name="host_phone" value="<?= e($_POST['host_phone'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="emergency_contact_visitor" class="form-label">Visitor Emergency Contact</label>
                                                <input type="text" class="form-control" id="emergency_contact_visitor" name="emergency_contact_visitor" value="<?= e($_POST['emergency_contact_visitor'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="special_instructions" class="form-label">Special Instructions</label>
                                        <textarea class="form-control" id="special_instructions" name="special_instructions" rows="2"><?= e($_POST['special_instructions'] ?? '') ?></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Register Visitor</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visitor Logs Table -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Visitor Logs</h5>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Search visitors..." id="searchInput">
                                    <button class="btn btn-sm btn-outline-secondary">Export</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($visitorLogs)): ?>
                                    <p class="text-muted">No visitor logs found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="visitorLogsTable">
                                            <thead>
                                                <tr>
                                                    <th>Name</th>
                                                    <th>Contact</th>
                                                    <th>Unit/Tenant</th>
                                                    <th>Purpose</th>
                                                    <th>Entry Time</th>
                                                    <th>Exit Time</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($visitorLogs as $log): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= e($log['visitor_name']) ?></strong>
                                                            <?php if ($log['vehicle_registration']): ?>
                                                                <div class="text-muted small">Vehicle: <?= e($log['vehicle_registration']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($log['visitor_phone']): ?>
                                                                <div><i class="fas fa-phone"></i> <?= e($log['visitor_phone']) ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($log['visitor_email']): ?>
                                                                <div class="text-muted small"><?= e($log['visitor_email']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div><strong>Unit:</strong> <?= e($log['unit_number']) ?></div>
                                                            <div class="text-muted small">Tenant: <?= e($log['tenant_name']) ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?= e($log['purpose']) ?></div>
                                                            <?php if ($log['special_instructions']): ?>
                                                                <div class="text-muted small">Notes: <?= e($log['special_instructions']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= date('M j, Y g:i A', strtotime($log['entry_time'])) ?></td>
                                                        <td>
                                                            <?php if ($log['exit_time']): ?>
                                                                <?= date('M j, Y g:i A', strtotime($log['exit_time'])) ?>
                                                            <?php else: ?>
                                                                <span class="text-warning">Not checked out</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $log['status'] === 'checked_in' ? 'success' : ($log['status'] === 'pending' ? 'warning' : 'secondary') ?>">
                                                                <?= e(ucfirst(str_replace('_', ' ', $log['status']))) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($log['status'] === 'checked_in'): ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to check out this visitor?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="visitor_id" value="<?= (int)$log['id'] ?>">
                                                                    <input type="hidden" name="checkout_visitor" value="1">
                                                                    <button type="submit" class="btn btn-sm btn-outline-success">Check Out</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-muted">Checked Out</span>
                                                            <?php endif; ?>
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
            </main>
        </div>
    </div>

    <script src="../../assets/plugins/global/plugins.bundle.js"></script>
    <script src="../../assets/js/scripts.bundle.js"></script>
    <script src="../../assets/plugins/custom/datatables/datatables.bundle.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#visitorLogsTable').DataTable({
                "pageLength": 25,
                "order": [[4, "desc"]],
                "columnDefs": [
                    { "orderable": false, "targets": [7] }
                ]
            });
        });
    </script>
</body>
</html>