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

// Get security personnel for the estate
$securityPersonnel = [];
if (is_super_admin()) {
    $securityPersonnel = db()->fetchAll("SELECT sp.id, u.first_name, u.last_name, sp.badge_number FROM security_personnel sp JOIN users u ON sp.user_id = u.id WHERE sp.status = 'active' ORDER BY u.first_name, u.last_name");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $securityPersonnel = db()->fetchAll(
            "SELECT sp.id, u.first_name, u.last_name, sp.badge_number 
             FROM security_personnel sp 
             JOIN users u ON sp.user_id = u.id 
             WHERE sp.estate_id IN ($placeholders) AND sp.status = 'active' 
             ORDER BY u.first_name, u.last_name",
            $estateIds
        );
    }
}

// Handle form submission for adding a new emergency incident
if ($_POST && isset($_POST['add_emergency'])) {
    verify_csrf();
    
    $incidentType = trim($_POST['incident_type'] ?? 'other');
    $severityLevel = trim($_POST['severity_level'] ?? 'medium');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $affectedUnits = trim($_POST['affected_units'] ?? '');
    $evacuationRequired = isset($_POST['evacuation_required']) ? 1 : 0;
    $policeReportFiled = isset($_POST['police_report_filed']) ? 1 : 0;
    $policeReportNumber = trim($_POST['police_report_number'] ?? '');
    $fireDepartmentNotified = isset($_POST['fire_department_notified']) ? 1 : 0;
    $ambulanceCalled = isset($_POST['ambulance_called']) ? 1 : 0;
    $securityOfficerId = (int)($_POST['security_officer_id'] ?? 0) ?: null;
    
    // Validate required fields
    $errors = [];
    if (empty($location)) {
        $errors[] = 'Location is required';
    }
    if (empty($description)) {
        $errors[] = 'Description is required';
    }
    
    if (empty($errors)) {
        try {
            $userId = current_user_id();
            $reportedAt = date('Y-m-d H:i:s');
            
            db()->insert(
                "INSERT INTO emergency_incidents (
                    estate_id, incident_type, severity_level, location, reported_by,
                    security_officer_id, description, reported_at, affected_units,
                    evacuation_required, police_report_filed, police_report_number,
                    fire_department_notified, ambulance_called, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported')",
                [
                    $estateId, $incidentType, $severityLevel, $location, $userId,
                    $securityOfficerId, $description, $reportedAt,
                    $affectedUnits ? json_encode(explode(',', $affectedUnits)) : null,
                    $evacuationRequired, $policeReportFiled, $policeReportNumber,
                    $fireDepartmentNotified, $ambulanceCalled
                ]
            );
            
            flash_set('success', 'Emergency incident reported successfully');
            redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle updating emergency status
if (isset($_POST['update_status'])) {
    verify_csrf();
    $incidentId = (int)($_POST['incident_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');
    $resolutionDetails = trim($_POST['resolution_details'] ?? '');
    
    if ($incidentId && $newStatus) {
        try {
            $updateFields = ['status' => $newStatus];
            
            if ($newStatus === 'resolved' || $newStatus === 'closed') {
                $updateFields['resolved_at'] = date('Y-m-d H:i:s');
            }
            
            if ($newStatus === 'in_progress') {
                $updateFields['response_started_at'] = date('Y-m-d H:i:s');
            }
            
            if ($resolutionDetails) {
                $updateFields['resolution_details'] = $resolutionDetails;
            }
            
            $setClause = [];
            $values = [];
            foreach ($updateFields as $field => $value) {
                $setClause[] = "$field = ?";
                $values[] = $value;
            }
            
            $values[] = $incidentId;
            $sql = "UPDATE emergency_incidents SET " . implode(', ', $setClause) . " WHERE id = ?";
            
            db()->execute($sql, $values);
            flash_set('success', 'Emergency status updated successfully');
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

// Get emergency incidents for the estate
$emergencies = [];
if (is_super_admin()) {
    $emergencies = db()->fetchAll("
        SELECT ei.*, e.name as estate_name, 
               reporter.first_name as reporter_first, reporter.last_name as reporter_last,
               officer.first_name as officer_first, officer.last_name as officer_last,
               sp.badge_number
        FROM emergency_incidents ei
        LEFT JOIN estates e ON ei.estate_id = e.id
        LEFT JOIN users reporter ON ei.reported_by = reporter.id
        LEFT JOIN security_personnel sp ON ei.security_officer_id = sp.id
        LEFT JOIN users officer ON sp.user_id = officer.id
        ORDER BY ei.reported_at DESC
        LIMIT 100
    ");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $emergencies = db()->fetchAll("
            SELECT ei.*, e.name as estate_name, 
                   reporter.first_name as reporter_first, reporter.last_name as reporter_last,
                   officer.first_name as officer_first, officer.last_name as officer_last,
                   sp.badge_number
            FROM emergency_incidents ei
            LEFT JOIN estates e ON ei.estate_id = e.id
            LEFT JOIN users reporter ON ei.reported_by = reporter.id
            LEFT JOIN security_personnel sp ON ei.security_officer_id = sp.id
            LEFT JOIN users officer ON sp.user_id = officer.id
            WHERE ei.estate_id IN ($placeholders)
            ORDER BY ei.reported_at DESC
            LIMIT 100
        ", $estateIds);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Emergency Incidents Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../assets/css/style.bundle.css">
    <link rel="stylesheet" href="../../assets/plugins/custom/datatables/datatables.bundle.css">
    <style>
        .critical-emergency { background-color: #ffebee; border-left: 4px solid #f44336; }
        .high-emergency { background-color: #fff3e0; border-left: 4px solid #ff9800; }
        .medium-emergency { background-color: #fff8e1; border-left: 4px solid #ffc107; }
        .low-emergency { background-color: #e8f5e8; border-left: 4px solid #4caf50; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/partials/top.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include __DIR__ . '/partials/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Emergency Incidents Management</h1>
                </div>

                <!-- Emergency Reporting Form -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Report Emergency Incident</h5>
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
                                    <input type="hidden" name="add_emergency" value="1">
                                    
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="incident_type" class="form-label">Incident Type</label>
                                                <select class="form-select" id="incident_type" name="incident_type" required>
                                                    <option value="fire" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'fire') ? 'selected' : '' ?>>Fire</option>
                                                    <option value="medical" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'medical') ? 'selected' : '' ?>>Medical Emergency</option>
                                                    <option value="break_in" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'break_in') ? 'selected' : '' ?>>Break-in/Theft</option>
                                                    <option value="disturbance" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'disturbance') ? 'selected' : '' ?>>Disturbance/Fight</option>
                                                    <option value="accident" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'accident') ? 'selected' : '' ?>>Accident</option>
                                                    <option value="natural_disaster" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'natural_disaster') ? 'selected' : '' ?>>Natural Disaster</option>
                                                    <option value="other" <?= (isset($_POST['incident_type']) && $_POST['incident_type'] === 'other') ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="severity_level" class="form-label">Severity Level</label>
                                                <select class="form-select" id="severity_level" name="severity_level" required>
                                                    <option value="low" <?= (isset($_POST['severity_level']) && $_POST['severity_level'] === 'low') ? 'selected' : '' ?>>Low</option>
                                                    <option value="medium" <?= (isset($_POST['severity_level']) && $_POST['severity_level'] === 'medium') ? 'selected' : '' ?>>Medium</option>
                                                    <option value="high" <?= (isset($_POST['severity_level']) && $_POST['severity_level'] === 'high') ? 'selected' : '' ?>>High</option>
                                                    <option value="critical" <?= (isset($_POST['severity_level']) && $_POST['severity_level'] === 'critical') ? 'selected' : '' ?>>Critical</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label for="location" class="form-label">Location</label>
                                                <input type="text" class="form-control" id="location" name="location" value="<?= e($_POST['location'] ?? '') ?>" required>
                                                <div class="form-text">Specific location of the incident</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="description" class="form-label">Description</label>
                                                <textarea class="form-control" id="description" name="description" rows="4" required><?= e($_POST['description'] ?? '') ?></textarea>
                                                <div class="form-text">Detailed description of the incident</div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="security_officer_id" class="form-label">Assigned Security Officer</label>
                                                <select class="form-select" id="security_officer_id" name="security_officer_id">
                                                    <option value="">Select Officer (Optional)</option>
                                                    <?php foreach ($securityPersonnel as $officer): ?>
                                                        <option value="<?= (int)$officer['id'] ?>" <?= (isset($_POST['security_officer_id']) && (int)$_POST['security_officer_id'] === (int)$officer['id']) ? 'selected' : '' ?>>
                                                            <?= e($officer['first_name'] . ' ' . $officer['last_name']) ?> (Badge: <?= e($officer['badge_number']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="affected_units" class="form-label">Affected Units</label>
                                                <input type="text" class="form-control" id="affected_units" name="affected_units" value="<?= e($_POST['affected_units'] ?? '') ?>">
                                                <div class="form-text">Comma-separated list of affected unit numbers</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="row">
                                                <div class="col-md-3">
                                                    <div class="form-check mb-3">
                                                        <input class="form-check-input" type="checkbox" id="evacuation_required" name="evacuation_required" <?= (isset($_POST['evacuation_required']) || $_POST === []) ? '' : 'checked' ?>>
                                                        <label class="form-check-label" for="evacuation_required">
                                                            Evacuation Required
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-check mb-3">
                                                        <input class="form-check-input" type="checkbox" id="police_report_filed" name="police_report_filed" <?= (isset($_POST['police_report_filed'])) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="police_report_filed">
                                                            Police Report Filed
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-check mb-3">
                                                        <input class="form-check-input" type="checkbox" id="fire_department_notified" name="fire_department_notified" <?= (isset($_POST['fire_department_notified'])) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="fire_department_notified">
                                                            Fire Department Notified
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="form-check mb-3">
                                                        <input class="form-check-input" type="checkbox" id="ambulance_called" name="ambulance_called" <?= (isset($_POST['ambulance_called'])) ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="ambulance_called">
                                                            Ambulance Called
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="police_report_number" class="form-label">Police Report Number</label>
                                        <input type="text" class="form-control" id="police_report_number" name="police_report_number" value="<?= e($_POST['police_report_number'] ?? '') ?>">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-danger">Report Emergency</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Emergency Incidents Table -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Emergency Incidents</h5>
                                <div class="d-flex gap-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Search incidents..." id="searchInput">
                                    <button class="btn btn-sm btn-outline-secondary">Export</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($emergencies)): ?>
                                    <p class="text-muted">No emergency incidents reported.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="emergencyTable">
                                            <thead>
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Location</th>
                                                    <th>Severity</th>
                                                    <th>Reported By</th>
                                                    <th>Reported At</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($emergencies as $incident): ?>
                                                    <tr class="<?php 
                                                        if ($incident['severity_level'] === 'critical') echo 'critical-emergency';
                                                        elseif ($incident['severity_level'] === 'high') echo 'high-emergency';
                                                        elseif ($incident['severity_level'] === 'medium') echo 'medium-emergency';
                                                        else echo 'low-emergency';
                                                    ?>">
                                                        <td>
                                                            <div>
                                                                <strong><?= e(ucfirst(str_replace('_', ' ', $incident['incident_type']))) ?></strong>
                                                                <?php if ($incident['police_report_filed']): ?>
                                                                    <span class="badge bg-danger ms-2">Police Report</span>
                                                                <?php endif; ?>
                                                                <?php if ($incident['evacuation_required']): ?>
                                                                    <span class="badge bg-warning ms-2">Evacuation</span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="text-muted small">ID: <?= e($incident['id']) ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?= e($incident['location']) ?></div>
                                                            <?php if ($incident['affected_units']): ?>
                                                                <div class="text-muted small">Units: <?= e($incident['affected_units']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge 
                                                                <?php 
                                                                if ($incident['severity_level'] === 'critical') echo 'bg-danger';
                                                                elseif ($incident['severity_level'] === 'high') echo 'bg-warning';
                                                                elseif ($incident['severity_level'] === 'medium') echo 'bg-info';
                                                                else echo 'bg-secondary';
                                                                ?>
                                                            ">
                                                                <?= e(ucfirst($incident['severity_level'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div><?= e($incident['reporter_first'] . ' ' . $incident['reporter_last']) ?></div>
                                                            <?php if ($incident['officer_first']): ?>
                                                                <div class="text-muted small">Officer: <?= e($incident['officer_first'] . ' ' . $incident['officer_last']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div><?= date('M j, Y g:i A', strtotime($incident['reported_at'])) ?></div>
                                                            <?php if ($incident['response_started_at']): ?>
                                                                <div class="text-muted small">Response: <?= date('g:i A', strtotime($incident['response_started_at'])) ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($incident['resolved_at']): ?>
                                                                <div class="text-muted small">Resolved: <?= date('g:i A', strtotime($incident['resolved_at'])) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge 
                                                                <?php 
                                                                if ($incident['status'] === 'reported') echo 'bg-warning';
                                                                elseif ($incident['status'] === 'in_progress') echo 'bg-primary';
                                                                elseif ($incident['status'] === 'resolved') echo 'bg-success';
                                                                else echo 'bg-secondary';
                                                                ?>
                                                            ">
                                                                <?= e(ucfirst($incident['status'])) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#detailsModal" data-incident='<?= json_encode($incident) ?>'>View</button>
                                                                <div class="btn-group btn-group-sm">
                                                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Update</button>
                                                                    <ul class="dropdown-menu">
                                                                        <li>
                                                                            <form method="POST" class="dropdown-item" onsubmit="return confirm('Confirm status update to In Progress?');">
                                                                                <?= csrf_field() ?>
                                                                                <input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                                                                                <input type="hidden" name="new_status" value="in_progress">
                                                                                <input type="hidden" name="update_status" value="1">
                                                                                <button type="submit" class="dropdown-item">Mark In Progress</button>
                                                                            </form>
                                                                        </li>
                                                                        <li>
                                                                            <form method="POST" class="dropdown-item" onsubmit="return confirm('Confirm resolution of this incident?');">
                                                                                <?= csrf_field() ?>
                                                                                <input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                                                                                <input type="hidden" name="new_status" value="resolved">
                                                                                <input type="hidden" name="update_status" value="1">
                                                                                <button type="submit" class="dropdown-item">Mark Resolved</button>
                                                                            </form>
                                                                        </li>
                                                                        <li>
                                                                            <form method="POST" class="dropdown-item" onsubmit="return confirm('Confirm closure of this incident?');">
                                                                                <?= csrf_field() ?>
                                                                                <input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                                                                                <input type="hidden" name="new_status" value="closed">
                                                                                <input type="hidden" name="update_status" value="1">
                                                                                <button type="submit" class="dropdown-item">Close</button>
                                                                            </form>
                                                                        </li>
                                                                    </ul>
                                                                </div>
                                                            </div>
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

    <!-- Modal for incident details -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Incident Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="incidentDetails">
                    <!-- Details will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/plugins/global/plugins.bundle.js"></script>
    <script src="../../assets/js/scripts.bundle.js"></script>
    <script src="../../assets/plugins/custom/datatables/datatables.bundle.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#emergencyTable').DataTable({
                "pageLength": 25,
                "order": [[4, "desc"]],
                "columnDefs": [
                    { "orderable": false, "targets": [6] }
                ]
            });
        });
        
        // Handle modal population
        var detailsModal = document.getElementById('detailsModal');
        detailsModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var incident = button.getAttribute('data-incident');
            var incidentData = JSON.parse(incident);
            
            var modalBody = detailsModal.querySelector('.modal-body');
            
            var html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Basic Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>ID:</strong></td><td>${incidentData.id}</td></tr>
                            <tr><td><strong>Type:</strong></td><td>${incidentData.incident_type.replace(/_/g, ' ')}</td></tr>
                            <tr><td><strong>Severity:</strong></td><td>${incidentData.severity_level}</td></tr>
                            <tr><td><strong>Location:</strong></td><td>${incidentData.location}</td></tr>
                            <tr><td><strong>Estate:</strong></td><td>${incidentData.estate_name}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Status Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Reported At:</strong></td><td>${new Date(incidentData.reported_at).toLocaleString()}</td></tr>
                            <tr><td><strong>Response Started:</strong></td><td>${incidentData.response_started_at ? new Date(incidentData.response_started_at).toLocaleString() : 'N/A'}</td></tr>
                            <tr><td><strong>Resolved At:</strong></td><td>${incidentData.resolved_at ? new Date(incidentData.resolved_at).toLocaleString() : 'N/A'}</td></tr>
                            <tr><td><strong>Status:</strong></td><td>${incidentData.status}</td></tr>
                        </table>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-12">
                        <h6>Description</h6>
                        <p>${incidentData.description}</p>
                        
                        <h6>Additional Information</h6>
                        <ul class="list-unstyled">
                            <li><strong>Reported by:</strong> ${incidentData.reporter_first} ${incidentData.reporter_last}</li>
                            <li><strong>Assigned Officer:</strong> ${incidentData.officer_first ? incidentData.officer_first + ' ' + incidentData.officer_last + ' (Badge: ' + incidentData.badge_number + ')' : 'None'}</li>
                            <li><strong>Affected Units:</strong> ${incidentData.affected_units || 'None'}</li>
                            <li><strong>Evacuation Required:</strong> ${incidentData.evacuation_required ? 'Yes' : 'No'}</li>
                            <li><strong>Police Report Filed:</strong> ${incidentData.police_report_filed ? 'Yes (Report #: ' + incidentData.police_report_number + ')' : 'No'}</li>
                            <li><strong>Fire Department Notified:</strong> ${incidentData.fire_department_notified ? 'Yes' : 'No'}</li>
                            <li><strong>Ambulance Called:</strong> ${incidentData.ambulance_called ? 'Yes' : 'No'}</li>
                        </ul>
                        
                        ${incidentData.resolution_details ? `<h6>Resolution Details</h6><p>${incidentData.resolution_details}</p>` : ''}
                    </div>
                </div>
            `;
            
            modalBody.innerHTML = html;
        });
    </script>
</body>
</html>