<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Gate Passes – EstatePro';
$pageHeading = 'Gate Passes';
$db = db();
$me = current_user();
$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? (int)$estateIds[0] : 0;

$securityPersonnel = $db->fetchOne(
    "SELECT * FROM security_personnel WHERE user_id = ?",
    [$me['id']]
);
if (!$estateId && $securityPersonnel && !empty($securityPersonnel['estate_id'])) {
    $estateId = (int) $securityPersonnel['estate_id'];
}
if ($estateId && $securityPersonnel && (int)($securityPersonnel['estate_id'] ?? 0) !== $estateId) {
    $securityPersonnel = $db->fetchOne(
        "SELECT * FROM security_personnel WHERE user_id = ? AND estate_id = ?",
        [$me['id'], $estateId]
    );
}

$errors = [];
$method = request_method();

if ($method === 'POST' && $estateId) {
    verify_csrf();
    $action = (string) post_param('action', '');

    if ($action === 'create') {
        $passType = trim((string) post_param('pass_type', 'single_use'));
        $recipientName = trim((string) post_param('recipient_name', ''));
        $recipientPhone = trim((string) post_param('recipient_phone', ''));
        $recipientEmail = trim((string) post_param('recipient_email', ''));
        $vehicleRegistration = trim((string) post_param('vehicle_registration', ''));
        $driverLicense = trim((string) post_param('driver_license', ''));
        $purposeOfVisit = trim((string) post_param('purpose_of_visit', ''));
        $validFrom = trim((string) post_param('valid_from', date('Y-m-d H:i')));
        $validUntil = trim((string) post_param('valid_until', date('Y-m-d H:i', strtotime('+1 day'))));
        $maxUses = (int) post_param('max_uses', 1);
        if ($maxUses < 1) $maxUses = 1;

        if ($recipientName === '') {
            $errors[] = 'Recipient name is required.';
        }
        if ($validFrom === '' || $validUntil === '') {
            $errors[] = 'Valid from and valid until are required.';
        }
        if (strtotime($validUntil) <= strtotime($validFrom)) {
            $errors[] = 'Valid until must be after valid from.';
        }

        if (empty($errors)) {
            try {
                $passNumber = 'GP-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $qrCode = 'QR_' . $passNumber . '_' . time();
                $userId = current_user_id();
                $validFromDt = date('Y-m-d H:i:s', strtotime($validFrom));
                $validUntilDt = date('Y-m-d H:i:s', strtotime($validUntil));

                $db->insert(
                    "INSERT INTO gate_passes (
                        estate_id, pass_type, pass_number, qr_code, valid_from, valid_until,
                        recipient_name, recipient_phone, recipient_email, vehicle_registration,
                        driver_license, purpose_of_visit, issued_by, issued_to, max_uses,
                        access_areas, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, 'active')",
                    [
                        $estateId, $passType, $passNumber, $qrCode, $validFromDt, $validUntilDt,
                        $recipientName, $recipientPhone ?: null, $recipientEmail ?: null,
                        $vehicleRegistration ?: null, $driverLicense ?: null, $purposeOfVisit ?: null,
                        $userId, $maxUses
                    ]
                );
                flash_set('success', 'Gate pass created successfully. Pass number: ' . $passNumber);
                redirect('gate_passes.php');
            } catch (Throwable $e) {
                $errors[] = 'Failed to create gate pass: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'revoke') {
        $passId = (int) post_param('pass_id', 0);
        if ($passId > 0) {
            try {
                $db->execute(
                    "UPDATE gate_passes SET status = 'revoked' WHERE id = ? AND estate_id = ? AND status = 'active'",
                    [$passId, $estateId]
                );
                flash_set('success', 'Gate pass revoked successfully.');
                redirect('gate_passes.php');
            } catch (Throwable $e) {
                $errors[] = 'Failed to revoke: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Invalid gate pass.';
        }
    }
}

$gatePasses = [];
if ($estateId) {
    $gatePasses = $db->fetchAll(
        "SELECT gp.*, u.first_name AS issuer_first, u.last_name AS issuer_last
         FROM gate_passes gp
         LEFT JOIN users u ON gp.issued_by = u.id
         WHERE gp.estate_id = ?
         ORDER BY gp.valid_from DESC, gp.created_at DESC
         LIMIT 100",
        [$estateId]
    );
}

function gate_pass_visitor_name(array $row): string {
    return trim((string)($row['recipient_name'] ?? $row['visitor_name'] ?? ''));
}

$toolbarActions = $estateId ? '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPassModal"><i class="ki-duotone ki-plus fs-2"><span class="path1"></span><span class="path2"></span></i> New Gate Pass</button>' : '';

require __DIR__ . '/partials/top.php';
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
    <div class="flex-grow-1"><?= e(implode(' ', $errors)) ?></div>
</div>
<?php endif; ?>

<div class="row g-6 g-xl-9">
    <div class="col-12 mb-5">
        <div class="card">
            <div class="card-header border-0 pt-6 d-flex flex-wrap justify-content-between align-items-center">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Gate Passes</h3>
                </div>
                <div class="card-toolbar d-flex align-items-center gap-2">
                    <?php if ($estateId): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPassModal">
                        <i class="ki-duotone ki-plus fs-2"><span class="path1"></span><span class="path2"></span></i>
                        New Gate Pass
                    </button>
                    <?php endif; ?>
                    <?php if ($securityPersonnel): ?>
                    <span class="badge badge-light-primary fs-5"><?= e($securityPersonnel['badge_number']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body py-0">
                <?php if (empty($gatePasses)): ?>
                    <?php $iconClass = 'ki-key'; $message = 'No gate passes found.'; require __DIR__ . '/partials/empty_state.php'; ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th class="min-w-100px">Pass #</th>
                                    <th class="min-w-120px">Visitor / Recipient</th>
                                    <th class="min-w-80px">Type</th>
                                    <th class="min-w-100px">Valid from</th>
                                    <th class="min-w-100px">Valid until</th>
                                    <th class="min-w-100px">Issued by</th>
                                    <th class="min-w-90px">Status</th>
                                    <th class="min-w-100px text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold">
                                <?php foreach ($gatePasses as $gp): 
                                    $name = gate_pass_visitor_name($gp) ?: '—';
                                    $status = $gp['status'] ?? 'active';
                                ?>
                                <tr>
                                    <td><span class="fw-bold"><?= e($gp['pass_number'] ?? '—') ?></span></td>
                                    <td>
                                        <div><?= e($name) ?></div>
                                        <?php if (!empty($gp['visitor_phone'] ?? $gp['recipient_phone'] ?? '')): ?>
                                        <div class="text-muted fs-7"><?= e($gp['recipient_phone'] ?? $gp['visitor_phone'] ?? '') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-light-info"><?= e(ucfirst(str_replace('_', ' ', $gp['pass_type'] ?? '—'))) ?></span></td>
                                    <td><?= $gp['valid_from'] ? date('M j, Y H:i', strtotime($gp['valid_from'])) : '—' ?></td>
                                    <td><?= $gp['valid_until'] ? date('M j, Y H:i', strtotime($gp['valid_until'])) : '—' ?></td>
                                    <td><?= e(trim(($gp['issuer_first'] ?? '') . ' ' . ($gp['issuer_last'] ?? '')) ?: '—') ?></td>
                                    <td>
                                        <?php
                                        $badge = 'badge-light';
                                        if ($status === 'active') $badge = 'badge-light-success';
                                        elseif ($status === 'used' || $status === 'expired') $badge = 'badge-light-dark';
                                        elseif ($status === 'revoked') $badge = 'badge-light-danger';
                                        ?>
                                        <span class="badge <?= $badge ?>"><?= e(ucfirst($status)) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($status === 'active'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="pass_id" value="<?= (int)$gp['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light-danger" onclick="return confirm('Revoke this gate pass? The holder will no longer be allowed entry.');">Revoke</button>
                                        </form>
                                        <?php else: ?>
                                        —
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

<?php if ($estateId): ?>
<div class="modal fade" id="createPassModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Issue New Gate Pass</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Pass type</label>
                            <select name="pass_type" class="form-select">
                                <option value="single_use">Single use</option>
                                <option value="daily">Daily (24 hours)</option>
                                <option value="weekly">Weekly (7 days)</option>
                                <option value="monthly">Monthly (30 days)</option>
                                <option value="permanent">Permanent</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Recipient name <span class="text-danger">*</span></label>
                            <input type="text" name="recipient_name" class="form-control" required placeholder="Full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Recipient phone</label>
                            <input type="text" name="recipient_phone" class="form-control" placeholder="Phone number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Recipient email</label>
                            <input type="email" name="recipient_email" class="form-control" placeholder="Email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vehicle registration</label>
                            <input type="text" name="vehicle_registration" class="form-control" placeholder="If applicable">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Driver license</label>
                            <input type="text" name="driver_license" class="form-control" placeholder="If applicable">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Purpose of visit</label>
                            <input type="text" name="purpose_of_visit" class="form-control" placeholder="e.g. Delivery, meeting resident">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Valid from <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="valid_from" class="form-control" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Valid until <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="valid_until" class="form-control" value="<?= e(date('Y-m-d\TH:i', strtotime('+1 day'))) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Max uses</label>
                            <input type="number" name="max_uses" class="form-control" min="1" value="1">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Issue Gate Pass</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
