<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Register Security Personnel – EstatePro';
$db = db();
$method = request_method();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estates = estates_for_current_user();
if (!$estates) {
    if (is_super_admin()) {
        flash_set('warning', 'Create an estate first.');
        redirect('estates.php');
    }
    http_response_code(403);
    echo 'No estate access assigned to your account. Please contact an administrator.';
    exit;
}
$estateId = normalize_estate_id($requestedEstateId);

$editId = (int)(get_param('edit_id', 0) ?? 0);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        if ($estateIdPost > 0) {
            assert_can_access_estate($estateIdPost);
        }
        if (!is_super_admin() && $estateIdPost <= 0) {
            flash_set('error', 'Only Super Admin can create global security personnel.');
            redirect('security_register.php?estate_id=' . $estateId);
        }
        
        $firstName = trim((string)post_param('first_name', ''));
        $lastName = trim((string)post_param('last_name', ''));
        $phone = trim((string)post_param('phone', ''));
        $email = trim((string)post_param('email', ''));
        $badgeNumber = trim((string)post_param('badge_number', ''));
        $shiftSchedule = trim((string)post_param('shift_schedule', 'morning'));
        $postAssigned = trim((string)post_param('post_assigned', ''));
        $licenseNumber = trim((string)post_param('license_number', ''));
        $certifications = trim((string)post_param('certifications', ''));
        $emergencyContactName = trim((string)post_param('emergency_contact_name', ''));
        $emergencyContactPhone = trim((string)post_param('emergency_contact_phone', ''));
        $dateHired = trim((string)post_param('date_hired', ''));
        $status = trim((string)post_param('status', 'active'));
        
        // Security personnel login fields (required)
        $securityEmail = strtolower(trim((string)post_param('security_email', '')));
        $securityPassword = (string)post_param('security_password', '');
        $securityPasswordConfirm = (string)post_param('security_password_confirmation', '');

        if ($firstName === '' || $lastName === '') {
            flash_set('error', 'Security personnel name is required.');
            redirect('security_register.php?estate_id=' . $estateId);
        }
        
        if ($badgeNumber === '') {
            flash_set('error', 'Badge number is required.');
            redirect('security_register.php?estate_id=' . $estateId);
        }
        
        if ($securityEmail === '') {
            flash_set('error', 'Security personnel email is required.');
            redirect('security_register.php?estate_id=' . $estateId);
        }

        $securityUserId = null;

        try {
            if ($id > 0) {
                // Updating existing security personnel
                $before = $db->fetchOne('SELECT * FROM security_personnel WHERE id = ?', [$id]);
                $existingSecurityUserId = $before ? (int)($before['user_id'] ?? 0) : 0;

                // Handle security personnel account linkage/update
                if ($securityEmail !== '') {
                    $existingUser = $db->fetchOne('SELECT id, role FROM users WHERE email = ? LIMIT 1', [$securityEmail]);
                    if ($existingUser) {
                        $existingUserId = (int)($existingUser['id'] ?? 0);
                        if ($existingSecurityUserId && $existingUserId !== $existingSecurityUserId) {
                            flash_set('error', 'Security email is already used by another account.');
                            redirect('security_register.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        if (!in_array($existingUser['role'] ?? '', ['security'], true)) {
                            flash_set('error', 'Existing account with this email is not a security personnel.');
                            redirect('security_register.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        // Update password if provided
                        if ($securityPassword !== '') {
                            if ($securityPassword !== $securityPasswordConfirm) {
                                flash_set('error', 'Security passwords do not match.');
                                redirect('security_register.php?estate_id=' . $estateId . '&edit_id=' . $id);
                            }
                            $hash = password_hash($securityPassword, PASSWORD_DEFAULT);
                            $db->execute('UPDATE users SET password = ? WHERE id = ?', [$hash, $existingUserId]);
                        }
                        $securityUserId = $existingUserId;
                    } else {
                        if ($securityPassword === '' || $securityPassword !== $securityPasswordConfirm) {
                            flash_set('error', 'Enter matching passwords to create a new security login.');
                            redirect('security_register.php?estate_id=' . $estateId . '&edit_id=' . $id);
                        }
                        
                        $hash = password_hash($securityPassword, PASSWORD_DEFAULT);
                        $securityUserId = (int)$db->insert(
                            "INSERT INTO users (email, password, first_name, last_name, phone, role, status)
                             VALUES (?, ?, ?, ?, NULLIF(?, ''), 'security', ?)",
                            [$securityEmail, $hash, $firstName, $lastName, $phone, $status]
                        );
                    }
                } else {
                    // No security email submitted; keep existing linkage as-is.
                    $securityUserId = $existingSecurityUserId ?: null;
                }

                $db->execute(
                    "UPDATE security_personnel
                     SET estate_id = ?, user_id = ?, badge_number = ?, shift_schedule = ?, post_assigned = NULLIF(?, ''),
                         license_number = NULLIF(?, ''), certifications = NULLIF(?, ''), emergency_contact_name = NULLIF(?, ''),
                         emergency_contact_phone = NULLIF(?, ''), date_hired = NULLIF(?, ''), status = ?
                     WHERE id = ?",
                    [
                        $estateIdPost ?: null,
                        $securityUserId,
                        $badgeNumber,
                        $shiftSchedule,
                        $postAssigned,
                        $licenseNumber,
                        $certifications,
                        $emergencyContactName,
                        $emergencyContactPhone,
                        $dateHired,
                        $status,
                        $id,
                    ]
                );
                flash_set('success', 'Security personnel updated.');
                $after = $db->fetchOne('SELECT * FROM security_personnel WHERE id = ?', [$id]);
                if ($before && $after) {
                    $diff = audit_diff($before, $after, ['estate_id','user_id','badge_number','shift_schedule','post_assigned','license_number','certifications','emergency_contact_name','emergency_contact_phone','date_hired','status']);
                    audit_log('update', 'security_personnel', (int)$id, ['diff' => $diff], $estateIdPost > 0 ? $estateIdPost : null);
                }
            } else {
                // New security personnel: create user account and security personnel record
                $existingUser = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$securityEmail]);
                if ($existingUser) {
                    flash_set('error', 'Security email is already used by another account.');
                    redirect('security_register.php?estate_id=' . $estateId);
                }
                
                if ($securityPassword === '' || $securityPassword !== $securityPasswordConfirm) {
                    flash_set('error', 'Enter matching passwords to create a new security login.');
                    redirect('security_register.php?estate_id=' . $estateId);
                }
                
                $hash = password_hash($securityPassword, PASSWORD_DEFAULT);
                $securityUserId = (int)$db->insert(
                    "INSERT INTO users (email, password, first_name, last_name, phone, role, status)
                     VALUES (?, ?, ?, ?, NULLIF(?, ''), 'security', ?)",
                    [$securityEmail, $hash, $firstName, $lastName, $phone, $status]
                );

                $newId = (int)$db->insert(
                    "INSERT INTO security_personnel (estate_id, user_id, badge_number, shift_schedule, post_assigned, 
                     license_number, certifications, emergency_contact_name, emergency_contact_phone, date_hired, status)
                     VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?)",
                    [
                        $estateIdPost ?: null,
                        $securityUserId,
                        $badgeNumber,
                        $shiftSchedule,
                        $postAssigned,
                        $licenseNumber,
                        $certifications,
                        $emergencyContactName,
                        $emergencyContactPhone,
                        $dateHired,
                        $status,
                    ]
                );
                flash_set('success', 'Security personnel created.');
                audit_log('create', 'security_personnel', $newId, ['badge_number' => $badgeNumber, 'shift_schedule' => $shiftSchedule, 'status' => $status], $estateIdPost > 0 ? $estateIdPost : null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Save failed: ' . $e->getMessage());
        }

        redirect('security_register.php?estate_id=' . $estateId);
    }

    if ($action === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT sp.id, sp.estate_id, sp.badge_number, sp.status, u.first_name, u.last_name 
                                     FROM security_personnel sp 
                                     LEFT JOIN users u ON sp.user_id = u.id 
                                     WHERE sp.id = ?', [$id]);
            $db->execute('DELETE FROM security_personnel WHERE id = ?', [$id]);
            
            // Optionally delete the user account too if it's only used for security
            if ($before && isset($before['user_id']) && $before['user_id']) {
                // Check if this user is used elsewhere
                $userUsage = $db->fetchOne("SELECT COUNT(*) as count FROM leases WHERE tenant_id = ? 
                                           UNION ALL 
                                           SELECT COUNT(*) as count FROM maintenance_tickets WHERE reported_by = ?
                                           UNION ALL
                                           SELECT COUNT(*) as count FROM maintenance_quotes WHERE vendor_id = ?
                                           UNION ALL
                                           SELECT COUNT(*) as count FROM vendors WHERE user_id = ?", 
                                           [$before['user_id'], $before['user_id'], $before['user_id'], $before['user_id']]);
                
                // If user is only used for security, we could delete it
                // For now, let's just update the role back to inactive
                $db->execute('UPDATE users SET role = ?, status = ? WHERE id = ?', ['tenant', 'inactive', $before['user_id']]);
            }
            
            flash_set('success', 'Security personnel deleted.');
            if ($before) {
                audit_log('delete', 'security_personnel', (int)$before['id'], ['badge_number' => $before['badge_number'] ?? null, 'status' => $before['status'] ?? null], (int)($before['estate_id'] ?? 0) ?: null);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete security personnel (it may be linked to other records).');
        }
        redirect('security_register.php?estate_id=' . $estateId);
    }
}

$editing = null;
$editingSecurityEmail = '';

if ($editId > 0) {
    $editing = $db->fetchOne(
        "SELECT sp.*, u.first_name, u.last_name, u.phone, u.email as user_email, u.status as user_status
         FROM security_personnel sp
         LEFT JOIN users u ON sp.user_id = u.id
         WHERE sp.id = ?",
        [$editId]
    );
    if ($editing) {
        $editingSecurityEmail = $editing['user_email'] ?? '';
    }
}

$securityPersonnel = [];
if (is_super_admin()) {
    $securityPersonnel = $db->fetchAll("
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
        $securityPersonnel = $db->fetchAll("
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
                            <h3 class="fw-bold m-0"><?=( $editing ? 'Edit Security Personnel' : 'Register Security Personnel' )?></h3>
                        </div>
                    </div>
                    <!--end::Card header-->
                    <!--begin::Card body-->
                    <div class="card-body border-top pt-5">
                        <?php 
                        $flash = flash_get();
                        if ($flash): ?>
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
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
                            <?= csrf_field() ?>
                            
                            <div class="row g-6">
                                <div class="col-12">
                                    <label for="estate_id" class="required form-label">Estate</label>
                                    <select class="form-select" id="estate_id" name="estate_id" required>
                                        <option value="">Select Estate</option>
                                        <?php foreach ($estates as $estate): ?>
                                            <option value="<?= (int)$estate['id'] ?>" 
                                                <?= ((isset($_GET['estate_id']) && (int)$_GET['estate_id'] == $estate['id']) || 
                                                     (isset($_POST['estate_id']) && (int)$_POST['estate_id'] == $estate['id']) ||
                                                     ($editing && $editing['estate_id'] == $estate['id'])) ? 'selected' : '' ?>>
                                                <?= e($estate['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="first_name" class="required form-label">First Name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                        value="<?= e($_POST['first_name'] ?? ($editing['first_name'] ?? '')) ?>" placeholder="Enter first name" required>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="last_name" class="required form-label">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                        value="<?= e($_POST['last_name'] ?? ($editing['last_name'] ?? '')) ?>" placeholder="Enter last name" required>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                        value="<?= e($_POST['phone'] ?? ($editing['phone'] ?? '')) ?>" placeholder="Enter phone number">
                                </div>
                                
                                <div class="col-12">
                                    <label for="email" class="form-label">Email (Optional)</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                        value="<?= e($_POST['email'] ?? ($editing['email'] ?? '')) ?>" placeholder="Enter optional email">
                                    <div class="text-muted fs-7">Additional contact email (not for login)</div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="badge_number" class="required form-label">Badge Number</label>
                                    <input type="text" class="form-control" id="badge_number" name="badge_number" 
                                        value="<?= e($_POST['badge_number'] ?? ($editing['badge_number'] ?? '')) ?>" placeholder="Enter badge number" required>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="shift_schedule" class="form-label">Shift Schedule</label>
                                    <select class="form-select" id="shift_schedule" name="shift_schedule">
                                        <option value="morning" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'morning') || 
                                               ($editing && $editing['shift_schedule'] === 'morning') ? 'selected' : '' ?>>
                                            Morning (6AM - 2PM)
                                        </option>
                                        <option value="afternoon" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'afternoon') || 
                                               ($editing && $editing['shift_schedule'] === 'afternoon') ? 'selected' : '' ?>>
                                            Afternoon (2PM - 10PM)
                                        </option>
                                        <option value="night" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'night') || 
                                               ($editing && $editing['shift_schedule'] === 'night') ? 'selected' : '' ?>>
                                            Night (10PM - 6AM)
                                        </option>
                                        <option value="rotating" 
                                            <?= (isset($_POST['shift_schedule']) && $_POST['shift_schedule'] === 'rotating') || 
                                               ($editing && $editing['shift_schedule'] === 'rotating') ? 'selected' : '' ?>>
                                            Rotating
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <label for="post_assigned" class="form-label">Post Assigned</label>
                                    <input type="text" class="form-control" id="post_assigned" name="post_assigned" 
                                        value="<?= e($_POST['post_assigned'] ?? ($editing['post_assigned'] ?? '')) ?>" placeholder="e.g., Main Gate, Front Desk">
                                    <div class="text-muted fs-7">Specify the location or duty post assigned to this personnel</div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="license_number" class="form-label">License Number</label>
                                    <input type="text" class="form-control" id="license_number" name="license_number" 
                                        value="<?= e($_POST['license_number'] ?? ($editing['license_number'] ?? '')) ?>" placeholder="Enter license number">
                                </div>
                                
                                <div class="col-12">
                                    <label for="certifications" class="form-label">Certifications</label>
                                    <textarea class="form-control" id="certifications" name="certifications" rows="3" 
                                        placeholder="Comma separated list of certifications"><?= e($_POST['certifications'] ?? ($editing['certifications'] ?? '')) ?></textarea>
                                    <div class="text-muted fs-7">Enter certifications separated by commas</div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                                    <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" 
                                        value="<?= e($_POST['emergency_contact_name'] ?? ($editing['emergency_contact_name'] ?? '')) ?>" placeholder="Enter emergency contact name">
                                </div>
                                
                                <div class="col-12">
                                    <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                                    <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" 
                                        value="<?= e($_POST['emergency_contact_phone'] ?? ($editing['emergency_contact_phone'] ?? '')) ?>" placeholder="Enter emergency contact phone">
                                </div>
                                
                                <div class="col-12">
                                    <label for="date_hired" class="form-label">Date Hired</label>
                                    <input type="date" class="form-control" id="date_hired" name="date_hired" 
                                        value="<?= e($_POST['date_hired'] ?? ($editing['date_hired'] ?? '')) ?>">
                                </div>
                                
                                <div class="col-12">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'active') || 
                                               ($editing && $editing['status'] === 'active') ? 'selected' : '' ?>>
                                            Active
                                        </option>
                                        <option value="inactive" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'inactive') || 
                                               ($editing && $editing['status'] === 'inactive') ? 'selected' : '' ?>>
                                            Inactive
                                        </option>
                                        <option value="suspended" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'suspended') || 
                                               ($editing && $editing['status'] === 'suspended') ? 'selected' : '' ?>>
                                            Suspended
                                        </option>
                                        <option value="terminated" 
                                            <?= (isset($_POST['status']) && $_POST['status'] === 'terminated') || 
                                               ($editing && $editing['status'] === 'terminated') ? 'selected' : '' ?>>
                                            Terminated
                                        </option>
                                    </select>
                                </div>
                                
                                <div class="separator my-10"></div>
                                
                                <div class="col-12">
                                    <h4>Login Credentials</h4>
                                    <p class="text-muted">These credentials will allow the security personnel to log into the system</p>
                                </div>
                                
                                <div class="col-12">
                                    <label for="security_email" class="required form-label">Login Email</label>
                                    <input type="email" class="form-control" id="security_email" name="security_email" 
                                        value="<?= e($_POST['security_email'] ?? ($editingSecurityEmail ?? '')) ?>" placeholder="Enter login email" required>
                                    <div class="fv-plugins-message-container fv-help-block"></div>
                                </div>
                                
                                <div class="col-12">
                                    <label for="security_password" class="required form-label">Password</label>
                                    <input type="password" class="form-control" id="security_password" name="security_password" 
                                        placeholder="<?= $editing ? 'Leave blank to keep current password' : 'Enter password' ?>" <?= $editing ? '' : 'required' ?>>
                                    <?php if ($editing): ?>
                                        <div class="text-muted fs-7">Leave blank to keep the current password</div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="col-12">
                                    <label for="security_password_confirmation" class="required form-label">Confirm Password</label>
                                    <input type="password" class="form-control" id="security_password_confirmation" name="security_password_confirmation" 
                                        placeholder="<?= $editing ? 'Leave blank to keep current password' : 'Confirm password' ?>" <?= $editing ? '' : 'required' ?>>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end pt-8">
                                <?php if ($editing): ?>
                                    <a href="security_register.php?estate_id=<?= e($estateId) ?>" class="btn btn-light me-3">Cancel</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary">
                                    <span class="indicator-label"><?= $editing ? 'Update Personnel' : 'Register Personnel' ?></span>
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
                                                        <a href="?edit_id=<?= e($person['id']) ?>&estate_id=<?= e($person['estate_id'] ?? $estateId) ?>" class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1" data-bs-toggle="tooltip" title="Edit">
                                                            <i class="ki-duotone ki-pencil fs-3">
                                                                <span class="path1"></span>
                                                                <span class="path2"></span>
                                                            </i>
                                                        </a>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this security personnel?')">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?= e($person['id']) ?>">
                                                            <?= csrf_field() ?>
                                                            <button type="submit" class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm" data-bs-toggle="tooltip" title="Delete">
                                                                <i class="ki-duotone ki-trash fs-3">
                                                                    <span class="path1"></span>
                                                                    <span class="path2"></span>
                                                                </i>
                                                            </button>
                                                        </form>
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