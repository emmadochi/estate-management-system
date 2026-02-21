<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'My Profile – EstatePro Security';
$pageHeading = 'Security Profile';

$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? $estateIds[0] : 0;

$securityPersonnel = null;
$estateName = '';
if ($estateId) {
    $securityPersonnel = db()->fetchOne(
        "SELECT sp.*, u.first_name, u.last_name, u.email, u.phone, u.avatar, u.created_at as user_created_at,
          e.name as estate_name
         FROM security_personnel sp
         JOIN users u ON sp.user_id = u.id
         LEFT JOIN estates e ON sp.estate_id = e.id
         WHERE sp.user_id = ? AND sp.estate_id = ?",
        [current_user_id(), $estateId]
    );
    if ($securityPersonnel) {
        $estateName = $securityPersonnel['estate_name'] ?? '';
    }
}

// Optional: update profile (limited fields if your app allows)
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact'])) {
    verify_csrf();
    $emergencyContactName = trim((string)($_POST['emergency_contact_name'] ?? ''));
    $emergencyContactPhone = trim((string)($_POST['emergency_contact_phone'] ?? ''));
    if ($securityPersonnel && $estateId) {
        try {
            db()->execute(
                "UPDATE security_personnel SET emergency_contact_name = ?, emergency_contact_phone = ? WHERE id = ?",
                [$emergencyContactName, $emergencyContactPhone, $securityPersonnel['id']]
            );
            flash_set('success', 'Emergency contact updated.');
            redirect('security_profile.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (!$securityPersonnel && $estateId) {
    flash_set('warning', 'Security personnel record not found for this estate. Contact admin.');
    redirect('index.php');
}

$toolbarActions = '';

require __DIR__ . '/partials/top.php';
?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger mb-4">
    <?php foreach ($errors as $err): ?>
      <div><?= e($err) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($securityPersonnel): ?>
                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="card">
                                <div class="card-body text-center">
                                    <?php if (!empty($securityPersonnel['avatar'])): ?>
                                        <img src="<?= e($securityPersonnel['avatar']) ?>" alt="Avatar" class="rounded-circle mb-3" style="width: 100px; height: 100px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width: 100px; height: 100px;">
                                            <span class="fs-1 text-muted"><?= e(mb_substr($securityPersonnel['first_name'], 0, 1)) ?><?= e(mb_substr($securityPersonnel['last_name'], 0, 1)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <h4 class="mb-1"><?= e($securityPersonnel['first_name'] . ' ' . $securityPersonnel['last_name']) ?></h4>
                                    <p class="text-muted mb-0"><?= e($securityPersonnel['badge_number'] ?? 'N/A') ?> · <?= e(ucfirst($securityPersonnel['status'] ?? 'active')) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title mb-0">Profile Details</h3>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless">
                                        <tr>
                                            <th class="text-muted" style="width: 180px;">Badge Number</th>
                                            <td><?= e($securityPersonnel['badge_number'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Post Assigned</th>
                                            <td><?= e($securityPersonnel['post_assigned'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Shift Schedule</th>
                                            <td><?= e(ucfirst($securityPersonnel['shift_schedule'] ?? '—')) ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Estate</th>
                                            <td><?= e($estateName ?: '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Email</th>
                                            <td><?= e($securityPersonnel['email'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Phone</th>
                                            <td><?= e($securityPersonnel['phone'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">License Number</th>
                                            <td><?= e($securityPersonnel['license_number'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Certifications</th>
                                            <td><?= e($securityPersonnel['certifications'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date Hired</th>
                                            <td><?= $securityPersonnel['date_hired'] ? date('M j, Y', strtotime($securityPersonnel['date_hired'])) : '—' ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Emergency Contact</th>
                                            <td><?= e($securityPersonnel['emergency_contact_name'] ?? '—') ?> <?= $securityPersonnel['emergency_contact_phone'] ? ' · ' . e($securityPersonnel['emergency_contact_phone']) : '' ?></td>
                                        </tr>
                                    </table>

                                    <hr>
                                    <h5 class="mb-3">Update Emergency Contact</h5>
                                    <form method="post" class="row g-3">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="update_contact" value="1">
                                        <div class="col-md-6">
                                            <label class="form-label">Emergency Contact Name</label>
                                            <input type="text" name="emergency_contact_name" class="form-control" value="<?= e($securityPersonnel['emergency_contact_name'] ?? '') ?>" placeholder="Full name">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Emergency Contact Phone</label>
                                            <input type="text" name="emergency_contact_phone" class="form-control" value="<?= e($securityPersonnel['emergency_contact_phone'] ?? '') ?>" placeholder="Phone number">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">Save Emergency Contact</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
