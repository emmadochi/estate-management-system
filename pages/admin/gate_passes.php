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

// Get all users for the estate
$users = [];
if (is_super_admin()) {
    $users = db()->fetchAll("SELECT id, first_name, last_name, email FROM users WHERE status = 'active' ORDER BY first_name, last_name");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $users = db()->fetchAll(
            "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email 
             FROM users u 
             INNER JOIN user_estates ue ON u.id = ue.user_id 
             WHERE u.status = 'active' AND ue.estate_id IN ($placeholders)
             ORDER BY u.first_name, u.last_name",
            $estateIds
        );
    }
}

// Handle form submission for adding a new gate pass
if ($_POST && isset($_POST['add_gate_pass'])) {
    verify_csrf();
    
    $passType = trim($_POST['pass_type'] ?? 'single_use');
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $recipientPhone = trim($_POST['recipient_phone'] ?? '');
    $recipientEmail = trim($_POST['recipient_email'] ?? '');
    $vehicleRegistration = trim($_POST['vehicle_registration'] ?? '');
    $driverLicense = trim($_POST['driver_license'] ?? '');
    $purposeOfVisit = trim($_POST['purpose_of_visit'] ?? '');
    $validFrom = trim($_POST['valid_from'] ?? date('Y-m-d H:i:s'));
    $validUntil = trim($_POST['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+1 day')));
    $maxUses = (int)($_POST['max_uses'] ?? 1);
    $accessAreas = trim($_POST['access_areas'] ?? '');
    $issuedToUserId = (int)($_POST['issued_to_user'] ?? 0) ?: null;
    
    // Validate required fields
    $errors = [];
    if (empty($recipientName)) {
        $errors[] = 'Recipient name is required';
    }
    if (empty($validFrom)) {
        $errors[] = 'Valid from date is required';
    }
    if (empty($validUntil)) {
        $errors[] = 'Valid until date is required';
    }
    
    if (empty($errors)) {
        try {
            // Generate unique pass number
            $passNumber = 'GP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
            
            // Generate QR code (simplified - in real app, you'd generate actual QR)
            $qrCode = 'QR_' . $passNumber . '_' . time();
            
            // Insert the gate pass
            $userId = current_user_id();
            
            db()->insert(
                "INSERT INTO gate_passes (
                    estate_id, pass_type, pass_number, qr_code, valid_from, valid_until,
                    recipient_name, recipient_phone, recipient_email, vehicle_registration,
                    driver_license, purpose_of_visit, issued_by, issued_to, max_uses,
                    access_areas, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
                [
                    $estateId, $passType, $passNumber, $qrCode, $validFrom, $validUntil,
                    $recipientName, $recipientPhone, $recipientEmail, $vehicleRegistration,
                    $driverLicense, $purposeOfVisit, $userId, $issuedToUserId, $max_uses,
                    $accessAreas ? json_encode(explode(',', $accessAreas)) : null
                ]
            );
            
            flash_set('success', 'Gate pass created successfully');
            redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle revoking a gate pass
if (isset($_POST['revoke_pass'])) {
    verify_csrf();
    $passId = (int)($_POST['pass_id'] ?? 0);
    
    if ($passId) {
        try {
            db()->execute(
                "UPDATE gate_passes SET status = 'revoked' WHERE id = ?",
                [$passId]
            );
            flash_set('success', 'Gate pass revoked successfully');
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

// Get gate passes for the estate
$gatePasses = [];
if (is_super_admin()) {
    $gatePasses = db()->fetchAll("
        SELECT gp.*, u.first_name, u.last_name, issuer.first_name as issuer_first, issuer.last_name as issuer_last
        FROM gate_passes gp
        LEFT JOIN users u ON gp.issued_to = u.id
        LEFT JOIN users issuer ON gp.issued_by = issuer.id
        ORDER BY gp.created_at DESC
        LIMIT 100
    ");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $gatePasses = db()->fetchAll("
            SELECT gp.*, u.first_name, u.last_name, issuer.first_name as issuer_first, issuer.last_name as issuer_last
            FROM gate_passes gp
            LEFT JOIN users u ON gp.issued_to = u.id
            LEFT JOIN users issuer ON gp.issued_by = issuer.id
            WHERE gp.estate_id IN ($placeholders)
            ORDER BY gp.created_at DESC
            LIMIT 100
        ", $estateIds);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Gate Passes Management</title>
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
                    <h1 class="h2">Gate Passes Management</h1>
                </div>

                <!-- Gate Pass Creation Form -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Create New Gate Pass</h5>
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
                                    <input type="hidden" name="add_gate_pass" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="pass_type" class="form-label">Pass Type</label>
                                                <select class="form-select" id="pass_type" name="pass_type" required>
                                                    <option value="single_use" <?= (isset($_POST['pass_type']) && $_POST['pass_type'] === 'single_use') ? 'selected' : '' ?>>Single Use</option>
                                                    <option value="daily" <?= (isset($_POST['pass_type']) && $_POST['pass_type'] === 'daily') ? 'selected' : '' ?>>Daily (24 hours)</option>
                                                    <option value="weekly" <?= (isset($_POST['pass_type']) && $_POST['pass_type'] === 'weekly') ? 'selected' : '' ?>>Weekly (7 days)</option>
                                                    <option value="monthly" <?= (isset($_POST['pass_type']) && $_POST['pass_type'] === 'monthly') ? 'selected' : '' ?>>Monthly (30 days)</option>
                                                    <option value="permanent" <?= (isset($_POST['pass_type']) && $_POST['pass_type'] === 'permanent') ? 'selected' : '' ?>>Permanent</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="recipient_name" class="form-label">Recipient Name</label>
                                                <input type="text" class="form-control" id="recipient_name" name="recipient_name" value="<?= e($_POST['recipient_name'] ?? '') ?>" required>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="recipient_phone" class="form-label">Recipient Phone</label>
                                                <input type="tel" class="form-control" id="recipient_phone" name="recipient_phone" value="<?= e($_POST['recipient_phone'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="recipient_email" class="form-label">Recipient Email</label>
                                                <input type="email" class="form-control" id="recipient_email" name="recipient_email" value="<?= e($_POST['recipient_email'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="vehicle_registration" class="form-label">Vehicle Registration</label>
                                                <input type="text" class="form-control" id="vehicle_registration" name="vehicle_registration" value="<?= e($_POST['vehicle_registration'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="driver_license" class="form-label">Driver License</label>
                                                <input type="text" class="form-control" id="driver_license" name="driver_license" value="<?= e($_POST['driver_license'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="purpose_of_visit" class="form-label">Purpose of Visit</label>
                                                <input type="text" class="form-control" id="purpose_of_visit" name="purpose_of_visit" value="<?= e($_POST['purpose_of_visit'] ?? '') ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="valid_from" class="form-label">Valid From</label>
                                                <input type="datetime-local" class="form-control" id="valid_from" name="valid_from" value="<?= e(date('Y-m-d\TH:i', strtotime($_POST['valid_from'] ?? date('Y-m-d H:i:s')))) ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="valid_until" class="form-label">Valid Until</label>
                                                <input type="datetime-local" class="form-control" id="valid_until" name="valid_until" value="<?= e(date('Y-m-d\TH:i', strtotime($_POST['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+1 day'))))) ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="max_uses" class="form-label">Max Uses</label>
                                                <input type="number" class="form-control" id="max_uses" name="max_uses" min="1" value="<?= (int)($_POST['max_uses'] ?? 1) ?>">
                                                <div class="form-text">Number of times this pass can be used</div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="issued_to_user" class="form-label">Issued To User</label>
                                                <select class="form-select" id="issued_to_user" name="issued_to_user">
                                                    <option value="">Not assigned to specific user</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?= (int)$user['id'] ?>" <?= (isset($_POST['issued_to_user']) && (int)$_POST['issued_to_user'] === (int)$user['id']) ? 'selected' : '' ?>>
                                                            <?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<?= e($user['email']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-5">
                                            <div class="mb-3">
                                                <label for="access_areas" class="form-label">Access Areas</label>
                                                <input type="text" class="form-control" id="access_areas" name="access_areas" value="<?= e($_POST['access_areas'] ?? '') ?>">
                                                <div class="form-text">Comma-separated list of areas (e.g., main gate, pool, gym)</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Create Gate Pass</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gate Passes Table -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Gate Passes</h5>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Search passes..." id="searchInput">
                                    <button class="btn btn-sm btn-outline-secondary">Export</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($gatePasses)): ?>
                                    <p class="text-muted">No gate passes found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="gatePassesTable">
                                            <thead>
                                                <tr>
                                                    <th>Pass Number</th>
                                                    <th>Recipient</th>
                                                    <th>Type</th>
                                                    <th>Validity</th>
                                                    <th>Uses</th>
                                                    <th>Status</th>
                                                    <th>Created By</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($gatePasses as $pass): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= e($pass['pass_number']) ?></strong>
                                                            <div class="text-muted small">QR: <?= e(substr($pass['qr_code'], 0, 10)) ?>...</div>
                                                        </td>
                                                        <td>
                                                            <div><?= e($pass['recipient_name']) ?></div>
                                                            <?php if ($pass['recipient_phone']): ?>
                                                                <div class="text-muted small"><i class="fas fa-phone"></i> <?= e($pass['recipient_phone']) ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($pass['recipient_email']): ?>
                                                                <div class="text-muted small"><?= e($pass['recipient_email']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?= e(ucfirst(str_replace('_', ' ', $pass['pass_type']))) ?></span>
                                                            <?php if ($pass['vehicle_registration']): ?>
                                                                <div class="text-muted small">Vehicle: <?= e($pass['vehicle_registration']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div><?= date('M j, Y g:i A', strtotime($pass['valid_from'])) ?></div>
                                                            <div class="text-muted small">to <?= date('M j, Y g:i A', strtotime($pass['valid_until'])) ?></div>
                                                        </td>
                                                        <td>
                                                            <div>Used: <?= (int)$pass['used_count'] ?>/<?= (int)$pass['max_uses'] ?></div>
                                                            <?php if ($pass['purpose_of_visit']): ?>
                                                                <div class="text-muted small">Purpose: <?= e($pass['purpose_of_visit']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $pass['status'] === 'active' ? 'success' : ($pass['status'] === 'used' ? 'secondary' : ($pass['status'] === 'expired' ? 'danger' : 'warning')) ?>">
                                                                <?= e(ucfirst($pass['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div><?= e($pass['issuer_first'] . ' ' . $pass['issuer_last']) ?></div>
                                                        </td>
                                                        <td>
                                                            <?php if ($pass['status'] === 'active'): ?>
                                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to revoke this gate pass?');">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="pass_id" value="<?= (int)$pass['id'] ?>">
                                                                    <input type="hidden" name="revoke_pass" value="1">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                            <button class="btn btn-sm btn-outline-primary" onclick="printPass(<?= (int)$pass['id'] ?>)">Print</button>
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
            $('#gatePassesTable').DataTable({
                "pageLength": 25,
                "order": [[3, "desc"]],
                "columnDefs": [
                    { "orderable": false, "targets": [7] }
                ]
            });
        });
        
        function printPass(passId) {
            // In a real implementation, this would open a print dialog for the specific pass
            alert('Print functionality would be implemented here for pass ID: ' + passId);
        }
    </script>
</body>
</html>