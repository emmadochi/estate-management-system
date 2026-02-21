<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Incident Reports – EstatePro Security';
$pageHeading = 'Incident Reports';

$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? $estateIds[0] : 0;

$securityPersonnel = null;
if ($estateId) {
    $securityPersonnel = db()->fetchOne(
        "SELECT sp.*, u.first_name, u.last_name, u.email, u.phone
         FROM security_personnel sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.user_id = ? AND sp.estate_id = ?",
        [current_user_id(), $estateId]
    );
}

$errors = [];
$reports = [];

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create' && $securityPersonnel && $estateId) {
        $incidentId = !empty($_POST['incident_id']) ? (int)$_POST['incident_id'] : null;
        $incidentDate = trim((string)($_POST['incident_date'] ?? ''));
        $incidentTime = trim((string)($_POST['incident_time'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $incidentType = trim((string)($_POST['incident_type'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $witnesses = trim((string)($_POST['witnesses'] ?? ''));
        $actionTaken = trim((string)($_POST['action_taken'] ?? ''));
        $recommendations = trim((string)($_POST['recommendations'] ?? ''));
        $followUpRequired = isset($_POST['follow_up_required']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['draft', 'submitted']) ? $_POST['status'] : 'draft';

        if (!$incidentDate || !$location || !$incidentType || !$description) {
            $errors[] = 'Incident date, location, type and description are required.';
        } else {
            try {
                $today = date('Ymd');
                $seq = db()->fetchOne(
                    "SELECT COUNT(*) AS c FROM security_incident_reports WHERE estate_id = ? AND DATE(created_at) = CURDATE()",
                    [$estateId]
                );
                $num = (int)($seq['c'] ?? 0) + 1;
                $reportNumber = 'INC-' . $today . '-' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);

                $witnessesJson = $witnesses !== '' ? json_encode(array_map('trim', array_filter(explode("\n", $witnesses)))) : null;

                db()->insert(
                    "INSERT INTO security_incident_reports (
                        estate_id, report_number, incident_id, reported_by, security_officer_id,
                        incident_date, incident_time, location, incident_type, description,
                        witnesses, action_taken, recommendations, follow_up_required, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $estateId, $reportNumber, $incidentId, current_user_id(), $securityPersonnel['id'],
                        $incidentDate, $incidentTime ?: null, $location, $incidentType, $description,
                        $witnessesJson, $actionTaken ?: null, $recommendations ?: null, $followUpRequired, $status
                    ]
                );
                flash_set('success', 'Incident report saved successfully.');
                redirect('incident_reports.php');
            } catch (Exception $e) {
                $errors[] = 'Failed to save report: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'submit_draft' && $securityPersonnel && $estateId) {
        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId) {
            try {
                db()->execute(
                    "UPDATE security_incident_reports SET status = 'submitted' WHERE id = ? AND estate_id = ? AND security_officer_id = ? AND status = 'draft'",
                    [$reportId, $estateId, $securityPersonnel['id']]
                );
                flash_set('success', 'Report submitted.');
                redirect('incident_reports.php');
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Load reports
if ($estateId) {
    $reports = db()->fetchAll(
        "SELECT sir.*, ei.incident_type as emergency_type, ei.severity_level,
          u.first_name as reporter_first, u.last_name as reporter_last
         FROM security_incident_reports sir
         LEFT JOIN emergency_incidents ei ON sir.incident_id = ei.id
         LEFT JOIN users u ON sir.reported_by = u.id
         WHERE sir.estate_id = ?
         ORDER BY sir.incident_date DESC, sir.incident_time DESC, sir.created_at DESC
         LIMIT 100",
        [$estateId]
    );
}

$emergencyIncidents = [];
if ($estateId) {
    $emergencyIncidents = db()->fetchAll(
        "SELECT id, incident_type, severity_level, location, reported_at, status
         FROM emergency_incidents WHERE estate_id = ? AND status IN ('reported', 'in_progress', 'resolved', 'closed')
         ORDER BY reported_at DESC LIMIT 50",
        [$estateId]
    );
}

$toolbarActions = '';
if ($securityPersonnel) {
    $toolbarActions = '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReportModal">New Incident Report</button>';
}

require __DIR__ . '/partials/top.php';
?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger mb-4">
    <?php foreach ($errors as $err): ?>
      <div><?= e($err) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <h3 class="card-title mb-0">Incident Reports</h3>
  </div>
  <div class="card-body">
    <?php if (empty($reports)): ?>
      <p class="text-muted mb-0">No incident reports yet.</p>
      <?php if ($securityPersonnel): ?>
        <p class="mt-2"><button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addReportModal">Create first report</button></p>
      <?php endif; ?>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped table-hover" id="incidentReportsTable">
          <thead>
            <tr>
              <th>Report #</th>
              <th>Date / Time</th>
              <th>Location</th>
              <th>Type</th>
              <th>Status</th>
              <th>Follow-up</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reports as $r): ?>
              <tr>
                <td><span class="fw-bold"><?= e($r['report_number']) ?></span></td>
                <td>
                  <?= date('M j, Y', strtotime($r['incident_date'])) ?>
                  <?php if ($r['incident_time']): ?>
                    <br><small class="text-muted"><?= date('g:i A', strtotime($r['incident_time'])) ?></small>
                  <?php endif; ?>
                </td>
                <td><?= e($r['location']) ?></td>
                <td><?= e($r['incident_type']) ?></td>
                <td>
                  <span class="badge bg-<?= $r['status'] === 'submitted' ? 'primary' : ($r['status'] === 'reviewed' ? 'info' : ($r['status'] === 'closed' ? 'success' : 'secondary')) ?>">
                    <?= e(ucfirst($r['status'])) ?>
                  </span>
                </td>
                <td><?= !empty($r['follow_up_required']) ? '<span class="badge bg-warning">Yes</span>' : '—' ?></td>
                <td>
                  <button type="button" class="btn btn-sm btn-light-primary" data-bs-toggle="modal" data-bs-target="#viewReportModal" data-report-id="<?= (int)$r['id'] ?>" data-report-number="<?= e($r['report_number']) ?>" data-description="<?= e($r['description']) ?>" data-action-taken="<?= e($r['action_taken'] ?? '') ?>" data-recommendations="<?= e($r['recommendations'] ?? '') ?>">View</button>
                  <?php if ($securityPersonnel && $r['security_officer_id'] == $securityPersonnel['id'] && $r['status'] === 'draft'): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="submit_draft">
                      <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Submit this report?');">Submit</button>
                    </form>
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

<?php if ($securityPersonnel): ?>
<div class="modal fade" id="addReportModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title">New Incident Report</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Link to Emergency Incident (optional)</label>
              <select name="incident_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($emergencyIncidents as $ei): ?>
                  <option value="<?= (int)$ei['id'] ?>">#<?= $ei['id'] ?> <?= e($ei['incident_type']) ?> – <?= e($ei['location']) ?> (<?= date('M j', strtotime($ei['reported_at'])) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Incident Date <span class="text-danger">*</span></label>
              <input type="date" name="incident_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Incident Time</label>
              <input type="time" name="incident_time" class="form-control" value="<?= date('H:i') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Location <span class="text-danger">*</span></label>
              <input type="text" name="location" class="form-control" placeholder="e.g. Gate A, Block B" required>
            </div>
            <div class="col-12">
              <label class="form-label">Incident Type <span class="text-danger">*</span></label>
              <input type="text" name="incident_type" class="form-control" placeholder="e.g. Theft, Trespass, Disturbance" required>
            </div>
            <div class="col-12">
              <label class="form-label">Description <span class="text-danger">*</span></label>
              <textarea name="description" class="form-control" rows="4" required></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Witnesses (one per line)</label>
              <textarea name="witnesses" class="form-control" rows="2" placeholder="Name, contact"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Action Taken</label>
              <textarea name="action_taken" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Recommendations</label>
              <textarea name="recommendations" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" name="follow_up_required" value="1" class="form-check-input" id="followUp">
                <label class="form-check-label" for="followUp">Follow-up required</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Save as</label>
              <select name="status" class="form-select">
                <option value="draft">Draft</option>
                <option value="submitted">Submit now</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Report</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="viewReportModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewReportTitle">Report</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Description</strong></p>
        <p id="viewReportDescription" class="text-muted"></p>
        <p><strong>Action Taken</strong></p>
        <p id="viewReportActionTaken" class="text-muted"></p>
        <p><strong>Recommendations</strong></p>
        <p id="viewReportRecommendations" class="text-muted"></p>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  var viewModal = document.getElementById('viewReportModal');
  if (viewModal) {
    viewModal.addEventListener('show.bs.modal', function(e) {
      var btn = e.relatedTarget;
      if (btn && btn.dataset.reportNumber) {
        document.getElementById('viewReportTitle').textContent = 'Report ' + (btn.dataset.reportNumber || '');
        document.getElementById('viewReportDescription').textContent = btn.dataset.description || '—';
        document.getElementById('viewReportActionTaken').textContent = btn.dataset.actionTaken || '—';
        document.getElementById('viewReportRecommendations').textContent = btn.dataset.recommendations || '—';
      }
    });
  }
  var table = document.getElementById('incidentReportsTable');
  if (table && typeof $ !== 'undefined' && $.fn.DataTable) {
    $(table).DataTable({ order: [[1, 'desc']], pageLength: 25 });
  }
})();
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>
