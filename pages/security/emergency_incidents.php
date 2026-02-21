<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Emergency Incidents – EstatePro';
$pageHeading = 'Emergency Incidents';
$db = db();
$me = current_user();
$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? (int)$estateIds[0] : 0;

$securityPersonnel = null;
if ($estateId) {
    $securityPersonnel = $db->fetchOne(
        "SELECT * FROM security_personnel WHERE user_id = ? AND estate_id = ?",
        [$me['id'], $estateId]
    );
}

$method = request_method();
$errors = [];

if ($method === 'POST') {
    verify_csrf();
    $action = (string) post_param('action', '');
    if ($action === 'report' && $estateId) {
        $location = trim((string) post_param('location', ''));
        $description = trim((string) post_param('description', ''));
        $incidentType = trim((string) post_param('incident_type', 'other'));
        $severity = in_array(post_param('severity_level', 'medium'), ['low', 'medium', 'high', 'critical'], true)
            ? post_param('severity_level') : 'medium';
        if (!$location || !$description) {
            $errors[] = 'Location and description are required.';
        } else {
            try {
                $reportedAt = date('Y-m-d H:i:s');
                $userId = current_user_id();
                $officerId = $securityPersonnel ? (int)$securityPersonnel['id'] : null;
                $db->insert(
                    "INSERT INTO emergency_incidents (estate_id, incident_type, severity_level, location, description, reported_by, security_officer_id, reported_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reported')",
                    [$estateId, $incidentType, $severity, $location, $description, $userId, $officerId ?: null, $reportedAt]
                );
                flash_set('success', 'Emergency incident reported successfully.');
                redirect('emergency_incidents.php');
            } catch (Throwable $e) {
                $errors[] = 'Failed to save: ' . $e->getMessage();
            }
        }
    }
}

$incidents = [];
if ($estateId) {
    $incidents = $db->fetchAll(
        "SELECT ei.*, u.first_name AS reporter_first, u.last_name AS reporter_last
         FROM emergency_incidents ei
         LEFT JOIN users u ON ei.reported_by = u.id
         WHERE ei.estate_id = ?
         ORDER BY ei.created_at DESC
         LIMIT 100",
        [$estateId]
    );
}

function incident_type_display(array $row): string {
    return e(ucfirst(str_replace('_', ' ', $row['incident_type'] ?? $row['type'] ?? 'other')));
}

function incident_reported_at(array $row): string {
    $t = $row['reported_at'] ?? $row['created_at'] ?? null;
    return $t ? date('M j, Y H:i', strtotime($t)) : '—';
}

$toolbarActions = $estateId ? '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportModal">Report Incident</button>' : '';

require __DIR__ . '/partials/top.php';
?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger d-flex align-items-center mb-4">
    <div class="flex-grow-1"><?= e(implode(' ', $errors)) ?></div>
</div>
<?php endif; ?>

<div class="row g-6 g-xl-9">
    <div class="col-12 mb-5">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Emergency Incidents</h3>
                </div>
                <?php if ($securityPersonnel): ?>
                <div class="card-toolbar">
                    <span class="badge badge-light-primary fs-5"><?= e($securityPersonnel['badge_number']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body py-0">
                <?php if (empty($incidents)): ?>
                    <?php $iconClass = 'ki-information'; $message = 'No emergency incidents recorded.'; require __DIR__ . '/partials/empty_state.php'; ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th class="min-w-80px">#</th>
                                    <th class="min-w-100px">Type</th>
                                    <th class="min-w-80px">Severity</th>
                                    <th class="min-w-120px">Location</th>
                                    <th class="min-w-120px">Reported</th>
                                    <th class="min-w-100px">Reporter</th>
                                    <th class="min-w-100px">Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold">
                                <?php foreach ($incidents as $ei): 
                                    $severity = $ei['severity_level'] ?? 'medium';
                                    $status = $ei['status'] ?? 'reported';
                                ?>
                                <tr>
                                    <td><span class="fw-bold">#<?= (int)($ei['id']) ?></span></td>
                                    <td><?= incident_type_display($ei) ?></td>
                                    <td>
                                        <?php
                                        $sBadge = 'badge-light';
                                        if ($severity === 'critical') $sBadge = 'badge-light-danger';
                                        elseif ($severity === 'high') $sBadge = 'badge-light-warning';
                                        elseif ($severity === 'medium') $sBadge = 'badge-light-info';
                                        ?>
                                        <span class="badge <?= $sBadge ?>"><?= e(ucfirst($severity)) ?></span>
                                    </td>
                                    <td><?= e($ei['location'] ?? '—') ?></td>
                                    <td><?= incident_reported_at($ei) ?></td>
                                    <td><?= e(trim(($ei['reporter_first'] ?? '') . ' ' . ($ei['reporter_last'] ?? '')) ?: '—') ?></td>
                                    <td>
                                        <?php
                                        $stBadge = 'badge-light';
                                        if ($status === 'reported' || $status === 'in_progress') $stBadge = 'badge-light-warning';
                                        elseif ($status === 'resolved' || $status === 'closed') $stBadge = 'badge-light-success';
                                        ?>
                                        <span class="badge <?= $stBadge ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span>
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
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="report">
                <div class="modal-header">
                    <h5 class="modal-title">Report Emergency Incident</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Location <span class="text-danger">*</span></label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Block A, Main Gate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Incident type</label>
                        <select name="incident_type" class="form-select">
                            <option value="fire">Fire</option>
                            <option value="medical">Medical</option>
                            <option value="security_breach">Security breach</option>
                            <option value="theft">Theft</option>
                            <option value="vandalism">Vandalism</option>
                            <option value="natural_disaster">Natural disaster</option>
                            <option value="other" selected>Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Severity</label>
                        <select name="severity_level" class="form-select">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Report</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
