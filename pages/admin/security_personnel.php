<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

// Redirect to the new unified security registration page
redirect('security_register.php');

// Get all estates for the dropdown
$estates = estates_for_current_user();

// Get all users with security role to assign as supervisors
$securityUsers = [];
if (is_super_admin()) {
    $securityUsers = db()->fetchAll("SELECT u.id, u.first_name, u.last_name FROM users u WHERE u.role = 'security' AND u.status = 'active'");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $securityUsers = db()->fetchAll(
            "SELECT u.id, u.first_name, u.last_name 
             FROM users u 
             INNER JOIN user_estates ue ON u.id = ue.user_id 
             WHERE u.role = 'security' AND u.status = 'active' AND ue.estate_id IN ($placeholders)",
            $estateIds
        );
    }
}

// Handle form submission
if ($_POST) {
    verify_csrf();
    
    $userId = (int)($_POST['user_id'] ?? 0);
    $estateId = (int)($_POST['estate_id'] ?? 0);
    $badgeNumber = trim($_POST['badge_number'] ?? '');
    $shiftSchedule = trim($_POST['shift_schedule'] ?? 'morning');
    $postAssigned = trim($_POST['post_assigned'] ?? '');
    $supervisorId = (int)($_POST['supervisor_id'] ?? 0) ?: null;
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $certifications = trim($_POST['certifications'] ?? '');
    $emergencyContactName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyContactPhone = trim($_POST['emergency_contact_phone'] ?? '');
    $dateHired = trim($_POST['date_hired'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    
    // Validate required fields
    $errors = [];
    if (!$userId) {
        $errors[] = 'User is required';
    }
    if (!$estateId) {
        $errors[] = 'Estate is required';
    }
    if (empty($badgeNumber)) {
        $errors[] = 'Badge number is required';
    }
    
    if (empty($errors)) {
        try {
            // Check if badge number already exists
            $existing = db()->fetchOne("SELECT id FROM security_personnel WHERE badge_number = ?", [$badgeNumber]);
            if ($existing) {
                $errors[] = 'Badge number already exists';
            }
            
            if (empty($errors)) {
                // Insert the security personnel record
                db()->insert(
                    "INSERT INTO security_personnel (
                        user_id, estate_id, badge_number, shift_schedule, post_assigned,
                        supervisor_id, license_number, certifications, emergency_contact_name,
                        emergency_contact_phone, date_hired, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $userId, $estateId, $badgeNumber, $shiftSchedule, $postAssigned,
                        $supervisorId, $licenseNumber, $certifications, $emergencyContactName,
                        $emergencyContactPhone, $dateHired, $status
                    ]
                );
                
                // Update the user's role to security if not already set
                $currentUserRole = db()->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
                if ($currentUserRole['role'] !== 'security') {
                    db()->execute("UPDATE users SET role = 'security' WHERE id = ?", [$userId]);
                }
                
                flash_set('success', 'Security personnel registered successfully');
                redirect($_SERVER['PHP_SELF']);
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch existing security personnel for the current estate(s)
$securityPersonnel = [];
if (is_super_admin()) {
    $securityPersonnel = db()->fetchAll("
        SELECT sp.*, u.first_name, u.last_name, u.email, u.phone,
               CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name
        FROM security_personnel sp
        LEFT JOIN users u ON sp.user_id = u.id
        LEFT JOIN security_personnel sup_sp ON sp.supervisor_id = sup_sp.id
        LEFT JOIN users sup ON sup_sp.user_id = sup.id
        ORDER BY sp.created_at DESC
    ");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $securityPersonnel = db()->fetchAll("
            SELECT sp.*, u.first_name, u.last_name, u.email, u.phone,
                   CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name
            FROM security_personnel sp
            LEFT JOIN users u ON sp.user_id = u.id
            LEFT JOIN security_personnel sup_sp ON sp.supervisor_id = sup_sp.id
            LEFT JOIN users sup ON sup_sp.user_id = sup.id
            WHERE sp.estate_id IN ($placeholders)
            ORDER BY sp.created_at DESC
        ", $estateIds);
    }
}

$pageTitle = 'Security Personnel Management – EstatePro';
$me = current_user();
$isSuper = $me['role'] === 'super_admin';
$isEstateAdmin = $me['role'] === 'estate_admin';
$isPropertyManager = $me['role'] === 'property_manager';
$isStaff = $me['role'] === 'staff';
$isSecurity = $me['role'] === 'security';
$canManageCoreEstates = $isSuper || $isEstateAdmin || $isPropertyManager; // properties, units, tenants, leases
$canSeeUnits = $canManageCoreEstates || $isStaff || $isSecurity;          // units, maintenance
$canSeeFinance = $canManageCoreEstates;                                   // invoices, payments
$canSeeVendors = $canManageCoreEstates;                                   // vendors
$canSeeAnnouncements = $canManageCoreEstates;                             // announcements
$current = basename($_SERVER['SCRIPT_NAME']);
$flash = flash_get();

require __DIR__ . '/partials/top.php';
?>

<!--begin::Container-->
<div id="kt_app_content" class="app-content flex-column-fluid">
    <div id="kt_app_content_container" class="app-container container-fluid">
        <!--begin::Row-->
        <div class="row g-6 g-xl-9">
            <!--begin::Col-->
            <div class="col-12 col-xxl-6 mb-5">
                <!--begin::Card-->
                <div class="card">
                    <!--begin::Card header-->
                    <div class="card-header border-0">
                        <div class="card-title">
                            <h3 class="fw-bold m-0">Register Security Personnel</h3>
                        </div>
                    </div>
                    <!--end::Card header-->
                    <!--begin::Card body-->
                    <div class="card-body border-top pt-5">
                        <?php if (isset($errors) && !empty($errors)): ?>
                            <div class="alert alert-danger d-flex align-items-center" role="alert">
                                <i class="ki-duotone ki-information fs-2 me-3"></i>
                                <div>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= e($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($flash): ?>
                            <?php
                                $type = $flash['type'] ?? 'info';
                                $message = $flash['message'] ?? '';
                                $alert = 'alert-info';
                                if ($type === 'success') $alert = 'alert-success';
                                if ($type === 'error') $alert = 'alert-danger';
                                if ($type === 'warning') $alert = 'alert-warning';
                            ?>
                            <div class="alert <?= e($alert) ?> d-flex align-items-center" role="alert">
                                <i class="ki-duotone ki-information fs-2 me-3"></i>
                                <div><?= e($message) ?></div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="needs-validation" novalidate>
                            <?= csrf_field() ?>
                            
                            <div class="row g-6">
                                <div class="col-12">
                                    <label for="user_id" class="required form-label">User Account</label>
                                    <select class="form-select" id="user_id" name="user_id" required>
                                        <option value="">Select User</option>
                                        <?php 
                                        // Get all users that are not already security personnel
                                        $existingSecurityUserIds = array_column($securityPersonnel, 'user_id');
                                        $allUsers = db()->fetchAll("SELECT id, first_name, last_name, email, role FROM users WHERE status = 'active' AND id NOT IN (" . implode(',', $existingSecurityUserIds ?: [0]) . ")");
                                        foreach ($allUsers as $user): 
                                        ?>
                                            <option value="<?= (int)$user['id'] ?>"
                                                <?= (isset($_POST['user_id']) && (int)$_POST['user_id'] === (int)$user['id']) ? 'selected' : '' ?>>
                                                <?= e($user['first_name'] . ' ' . $user['last_name']) ?> (<?= e($user['email']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="estate_id" class="required form-label">Estate</label>
                                    <select class="form-select" id="estate_id" name="estate_id" required>
                                        <option value="">Select Estate</option>
                                        <?php foreach ($estates as $estate): ?>
                                            <option value="<?= (int)$estate['id'] ?>" 
                                                <?= (isset($_POST['estate_id']) && (int)$_POST['estate_id'] === (int)$estate['id']) ? 'selected' : '' ?>>
                                                <?= e($estate['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="badge_number" class="required form-label">Badge Number</label>
                                    <input type="text" class="form-control" id="badge_number" name="badge_number" 
                                        value="<?= e($_POST['badge_number'] ?? '') ?>" placeholder="Enter badge number" required>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="shift_schedule" class="form-label">Shift Schedule</label>
                                    <select class="form-select" id="shift_schedule" name="shift_schedule">
                                        <option value="morning" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'morning') ? 'selected' : '' ?>>
                                            Morning (6AM - 2PM)
                                        </option>
                                        <option value="afternoon" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'afternoon') ? 'selected' : '' ?>>
                                            Afternoon (2PM - 10PM)
                                        </option>
                                        <option value="night" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'night') ? 'selected' : '' ?>>
                                            Night (10PM - 6AM)
                                        </option>
                                        <option value="rotating" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'rotating') ? 'selected' : '' ?>>
                                            Rotating
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <label for="post_assigned" class="form-label">Post Assigned</label>
                                    <input type="text" class="form-control" id="post_assigned" name="post_assigned" 
                                        value="<?= e($_POST['post_assigned'] ?? '') ?>" placeholder="e.g., Main Gate, Front Desk">
                                    <div class="text-muted fs-7">Specify the location or duty post assigned to this personnel</div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="supervisor_id" class="form-label">Supervisor</label>
                                    <select class="form-select" id="supervisor_id" name="supervisor_id">
                                        <option value="">No Supervisor</option>
                                        <?php foreach ($securityUsers as $user): ?>
                                            <option value="<?= (int)$user['id'] ?>" 
                                                <?= (isset($_POST['supervisor_id']) && (int)$_POST['supervisor_id'] === (int)$user['id']) ? 'selected' : '' ?>>
                                                <?= e($user['first_name'] . ' ' . $user['last_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <label for="license_number" class="form-label">License Number</label>
                                    <input type="text" class="form-control" id="license_number" name="license_number" 
                                        value="<?= e($_POST['license_number'] ?? '') ?>" placeholder="Enter license number">
                                </div>
                                
                                <div class="col-12">
                                    <label for="certifications" class="form-label">Certifications</label>
                                    <textarea class="form-control" id="certifications" name="certifications" rows="3" 
                                        placeholder="Comma separated list of certifications"><?= e($_POST['certifications'] ?? '') ?></textarea>
                                    <div class="text-muted fs-7">Enter certifications separated by commas</div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                                    <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" 
                                        value="<?= e($_POST['emergency_contact_name'] ?? '') ?>" placeholder="Enter emergency contact name">
                                </div>
                                
                                <div class="col-12">
                                    <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                                    <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" 
                                        value="<?= e($_POST['emergency_contact_phone'] ?? '') ?>" placeholder="Enter emergency contact phone">
                                </div>
                                
                                <div class="col-12">
                                    <label for="date_hired" class="form-label">Date Hired</label>
                                    <input type="date" class="form-control" id="date_hired" name="date_hired" 
                                        value="<?= e($_POST['date_hired'] ?? '') ?>">
                                </div>
                                
                                <div class="col-12">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : '' ?>>
                                            Active
                                        </option>
                                        <option value="inactive" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : '' ?>>
                                            Inactive
                                        </option>
                                        <option value="suspended" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'suspended') ? 'selected' : '' ?>>
                                            Suspended
                                        </option>
                                        <option value="terminated" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'terminated') ? 'selected' : '' ?>>
                                            Terminated
                                        </option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end pt-8">
                                <button type="submit" class="btn btn-primary">
                                    <span class="indicator-label">Register Personnel</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    <!--end::Card body-->
                </div>
                <!--end::Card-->
            </div>
            <!--end::Col-->
            
            <!--begin::Col-->
            <div class="col-12 col-xxl-6 mb-5">
                <!--begin::Card-->
                <div class="card">
                    <!--begin::Card header-->
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            <h3 class="fw-bold m-0">Registered Security Personnel</h3>
                        </div>
                        <div class="card-toolbar">
                            <span class="badge badge-light-primary fs-5"><?= count($securityPersonnel) ?> personnel</span>
                        </div>
                    </div>
                    <!--end::Card header-->
                    <!--begin::Card body-->
                    <div class="card-body py-4">
                        <?php if (empty($securityPersonnel)): ?>
                            <div class="text-center py-10">
                                <i class="ki-duotone ki-abstract-41 fs-8x text-gray-400 mb-5">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <div class="fs-4 fw-bold text-gray-400">No security personnel registered yet</div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-5" id="kt_security_personnel_table">
                                    <thead>
                                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                            <th class="min-w-125px">Name</th>
                                            <th class="min-w-100px">Badge #</th>
                                            <th class="min-w-100px">Shift</th>
                                            <th class="min-w-100px">Status</th>
                                            <th class="min-w-100px">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-gray-600 fw-semibold">
                                        <?php foreach ($securityPersonnel as $person): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3">
                                                            <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
                                                                <div class="symbol-label fs-3 bg-light-primary text-primary">
                                                                    <?= e(substr($person['first_name'], 0, 1) . substr($person['last_name'], 0, 1)) ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex flex-column">
                                                            <a href="#" class="text-gray-800 text-hover-primary mb-1"><?= e($person['first_name'] . ' ' . $person['last_name']) ?></a>
                                                            <span class="text-muted"><?= e($person['email']) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= e($person['badge_number']) ?></td>
                                                <td>
                                                    <div class="badge badge-light-info"><?= e($person['shift_schedule']) ?></div>
                                                    <div class="text-muted"><?= e($person['post_assigned']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="badge <?= $person['status'] === 'active' ? 'badge-light-success' : 'badge-light' ?>">
                                                        <?= e(ucfirst($person['status'])) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-end flex-shrink-0">
                                                        <a href="#" class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1" data-bs-toggle="tooltip" title="View Details">
                                                            <i class="ki-duotone ki-eye fs-3">
                                                                <span class="path1"></span>
                                                                <span class="path2"></span>
                                                            </i>
                                                        </a>
                                                        <a href="#" class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1" data-bs-toggle="tooltip" title="Edit">
                                                            <i class="ki-duotone ki-pencil fs-3">
                                                                <span class="path1"></span>
                                                                <span class="path2"></span>
                                                            </i>
                                                        </a>
                                                        <a href="#" class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm" data-bs-toggle="tooltip" title="Deactivate">
                                                            <i class="ki-duotone ki-trash fs-3">
                                                                <span class="path1"></span>
                                                                <span class="path2"></span>
                                                            </i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!--end::Card body-->
                </div>
                <!--end::Card-->
            </div>
            <!--end::Col-->
        </div>
        <!--end::Row-->
    </div>
    <!--end::Container-->
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>