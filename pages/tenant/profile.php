<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Profile & Security – EstatePro Tenant';
$pageHeading = 'Profile & Security';
$db = db();
$method = request_method();

$me = current_user();
$noTenancy = ($tenant === null);

if ($me && $method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    if ($action === 'save_profile') {
        $firstName = trim((string)post_param('first_name', ''));
        $lastName = trim((string)post_param('last_name', ''));
        $phone = trim((string)post_param('phone', ''));
        $removeAvatar = !empty($_POST['avatar_remove']);

        if ($firstName === '' || $lastName === '') {
            flash_set('error', 'First name and last name are required.');
        } else {
            try {
                $avatarFilename = null;
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatarFilename = handle_avatar_upload((int)$me['id']);
                    if ($avatarFilename === null && !empty(flash_get())) {
                        redirect('profile.php');
                        exit;
                    }
                } elseif ($removeAvatar) {
                    $oldAvatar = $db->fetchOne('SELECT avatar FROM users WHERE id = ?', [(int)$me['id']]);
                    if ($oldAvatar && !empty($oldAvatar['avatar'])) {
                        $oldPath = get_avatar_path($oldAvatar['avatar']);
                        if ($oldPath && file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                    $avatarFilename = ''; // Empty string to clear
                }

                if ($avatarFilename !== null) {
                    $db->execute(
                        'UPDATE users SET first_name = ?, last_name = ?, phone = ?, avatar = NULLIF(?, \'\') WHERE id = ?',
                        [$firstName, $lastName, $phone !== '' ? $phone : null, $avatarFilename, (int)$me['id']]
                    );
                } else {
                    $db->execute(
                        'UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?',
                        [$firstName, $lastName, $phone !== '' ? $phone : null, (int)$me['id']]
                    );
                }

                flash_set('success', 'Profile updated.');
            } catch (Throwable $e) {
                flash_set('error', 'Could not update profile.');
            }
        }
        redirect('profile.php');
    }
    if ($action === 'change_password') {
        $currentPassword = (string)post_param('current_password', '');
        $newPassword = (string)post_param('new_password', '');
        $confirmPassword = (string)post_param('confirm_password', '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            flash_set('error', 'All password fields are required.');
        } elseif (strlen($newPassword) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
        } elseif ($newPassword !== $confirmPassword) {
            flash_set('error', 'New password and confirmation do not match.');
        } elseif (!password_verify($currentPassword, (string)($me['password'] ?? ''))) {
            flash_set('error', 'Current password is incorrect.');
        } else {
            try {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $db->execute('UPDATE users SET password = ? WHERE id = ?', [$hash, (int)$me['id']]);
                flash_set('success', 'Password changed.');
            } catch (Throwable $e) {
                flash_set('error', 'Could not change password.');
            }
        }
        redirect('profile.php');
    }
}

$me = current_user();

require __DIR__ . '/partials/top.php';
?>

<?php if (!$me): ?>
<div class="alert alert-danger">You must be logged in to view this page.</div>
<?php else: ?>

<div class="row g-5">
    <div class="col-lg-6">
        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title">Profile</h3>
            </div>
            <div class="card-body">
                <form method="post" action="profile.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_profile">
                    
                    <div class="mb-5 text-center">
                        <div class="image-input image-input-outline" data-kt-image-input="true" style="background-image: url('<?= e(get_avatar_url($me['avatar'] ?? null)) ?>')">
                            <div class="image-input-wrapper w-125px h-125px" style="background-image: url('<?= e(get_avatar_url($me['avatar'] ?? null)) ?>')"></div>
                            <label class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="change" data-bs-toggle="tooltip" title="Change avatar">
                                <i class="ki-duotone ki-pencil fs-7"><span class="path1"></span><span class="path2"></span></i>
                                <input type="file" name="avatar" accept="image/*">
                                <input type="hidden" name="avatar_remove">
                            </label>
                            <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="cancel" data-bs-toggle="tooltip" title="Cancel avatar">
                                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
                            </span>
                            <span class="btn btn-icon btn-circle btn-active-color-primary w-25px h-25px bg-body shadow" data-kt-image-input-action="remove" data-bs-toggle="tooltip" title="Remove avatar">
                                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
                            </span>
                        </div>
                        <div class="form-text">Allowed file types: png, jpg, jpeg, gif, webp. Max size: 5MB</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="text" class="form-control" value="<?= e($me['email'] ?? '') ?>" readonly disabled>
                        <div class="form-text">Email cannot be changed here. Contact your estate manager if needed.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">First name <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" class="form-control" required maxlength="100" value="<?= e($me['first_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last name <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" class="form-control" required maxlength="100" value="<?= e($me['last_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" maxlength="20" value="<?= e($me['phone'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Save profile</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title">Change password</h3>
            </div>
            <div class="card-body">
                <form method="post" action="profile.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!$noTenancy): ?>
<div class="card card-flush mt-5">
    <div class="card-header">
        <h3 class="card-title">Tenancy</h3>
    </div>
    <div class="card-body">
        <p class="mb-1"><strong><?= e($tenant['estate_name'] ?? '') ?></strong></p>
        <p class="text-gray-600 mb-0">Unit <?= e($tenant['unit_number'] ?? '') ?><?php if (!empty($tenant['property_name'])): ?> — <?= e($tenant['property_name']) ?><?php endif; ?></p>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Dashboard</a>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
