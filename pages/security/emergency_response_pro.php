<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/emergency_system.php';

require_login(['security']);
$pageTitle = 'Professional Emergency Response Center – EstatePro';
$pageHeading = 'Emergency Response Command Center';
$db = db();
$me = current_user();
$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? (int)$estateIds[0] : 0;

// Get security personnel info
$securityPersonnel = null;
if ($estateId) {
    $securityPersonnel = $db->fetchOne(
        "SELECT * FROM security_personnel WHERE user_id = ? AND estate_id = ?",
        [$me['id'], $estateId]
    );
}

// Handle alert actions with professional workflow
if ($_POST && isset($_POST['action'])) {
    verify_csrf();
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $action = $_POST['action'];
    
    if ($alertId && $securityPersonnel) {
        try {
            $currentTime = date('Y-m-d H:i:s');
            
            switch ($action) {
                case 'acknowledge':
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'acknowledged', acknowledged_at = ?, acknowledged_by = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $me['id'], $alertId, $estateId]
                    );
                    // Log activity
                    $emergencySystem->logEmergencyActivity($alertId, 'acknowledged', $me['id']);
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', '🚨 Emergency acknowledged. Proceeding to respond immediately.');
                    break;
                    
                case 'responding':
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'responding', responded_at = ?, responded_by = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $me['id'], $alertId, $estateId]
                    );
                    // Log activity
                    $emergencySystem->logEmergencyActivity($alertId, 'responding', $me['id']);
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', '🏃 Response in progress. Proceed to location immediately.');
                    break;
                    
                case 'resolve':
                    $notes = trim($_POST['resolution_notes'] ?? '');
                    $equipmentUsed = $_POST['equipment_used'] ?? [];
                    
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'resolved', resolved_at = ?, resolution_notes = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $notes, $alertId, $estateId]
                    );
                    
                    // Log resolution details
                    $emergencySystem->logEmergencyActivity($alertId, 'resolved', $me['id'], [
                        'resolution_notes' => $notes,
                        'equipment_used' => $equipmentUsed
                    ]);
                    
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', '✅ Emergency successfully resolved and closed.');
                    break;
                    
                case 'escalate':
                    // Professional escalation protocol
                    $escalationLevel = $_POST['escalation_level'] ?? 'level_2';
                    $reason = trim($_POST['escalation_reason'] ?? '');
                    
                    $db->execute(
                        "INSERT INTO emergency_escalations 
                         (alert_id, escalation_level, trigger_time, escalated_at, reason)
                         VALUES (?, ?, NOW(), NOW(), ?)",
                        [$alertId, $escalationLevel, $reason]
                    );
                    
                    // Update alert status
                    $db->execute(
                        "UPDATE emergency_alerts SET status = 'escalated' WHERE id = ?",
                        [$alertId]
                    );
                    
                    $emergencySystem->logEmergencyActivity($alertId, 'escalated', $me['id'], [
                        'escalation_level' => $escalationLevel,
                        'reason' => $reason
                    ]);
                    
                    flash_set('warning', '⚠️ Emergency escalated to ' . strtoupper($escalationLevel) . ' protocol.');
                    break;
            }
        } catch (Exception $e) {
            flash_set('error', '❌ Failed to process emergency action: ' . $e->getMessage());
            error_log('Emergency response error: ' . $e->getMessage());
        }
    }
    redirect('emergency_response_pro.php');
}

// Get active emergencies with professional details
$activeEmergencies = $db->fetchAll(
    "SELECT ea.*, 
            t.emergency_contact_name as tenant_name, t.emergency_contact_phone as tenant_phone,
            u.unit_number, p.name as property_name,
            ack_user.first_name as ack_first, ack_user.last_name as ack_last,
            resp_user.first_name as resp_first, resp_user.last_name as resp_last,
            ert.response_procedure, ert.equipment_needed, ert.estimated_response_time
     FROM emergency_alerts ea
     JOIN tenants t ON ea.tenant_id = t.id
     JOIN units u ON ea.unit_id = u.id
     JOIN properties p ON u.property_id = p.id
     LEFT JOIN users ack_user ON ea.acknowledged_by = ack_user.id
     LEFT JOIN users resp_user ON ea.responded_by = resp_user.id
     LEFT JOIN emergency_response_templates ert ON ea.alert_type = ert.alert_type AND ea.severity_level = ert.severity_level
     WHERE ea.estate_id = ? 
     AND ea.status IN ('reported', 'acknowledged', 'responding')
     ORDER BY 
         CASE ea.severity_level 
             WHEN 'critical' THEN 1
             WHEN 'high' THEN 2
             WHEN 'medium' THEN 3
             WHEN 'low' THEN 4
         END,
         ea.reported_at DESC",
    [$estateId]
);

// Get emergency statistics
$stats = $db->fetchOne(
    "SELECT 
        COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_alerts,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
        COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_alerts,
        COUNT(CASE WHEN severity_level = 'high' THEN 1 END) as high_alerts,
        AVG(CASE WHEN response_time_seconds IS NOT NULL THEN response_time_seconds END) as avg_response_time,
        AVG(CASE WHEN resolution_time_seconds IS NOT NULL THEN resolution_time_seconds END) as avg_resolution_time
     FROM emergency_alerts 
     WHERE estate_id = ? AND DATE(reported_at) = CURDATE()",
    [$estateId]
);

// Get audible alerts for real-time sound notifications
$audibleAlerts = $db->fetchAll(
    "SELECT id, alert_data, created_at
     FROM emergency_audible_alerts
     WHERE estate_id = ? AND (played_for IS NULL OR JSON_CONTAINS(played_for, ?) = 0)
     ORDER BY created_at DESC
     LIMIT 5",
    [$estateId, json_encode($me['id'])]
);

require __DIR__ . '/partials/top.php';
?>

<!-- Professional Emergency Response Dashboard -->
<div class="row mb-8">
    <!-- Professional Stats Cards -->
    <div class="col-md-2">
        <div class="card bg-danger bg-opacity-25 border border-danger border-3">
            <div class="card-body text-center">
                <i class="ki-duotone ki-siren text-danger fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-danger"><?= $stats['active_alerts'] ?? 0 ?></div>
                <div class="fs-7 text-muted">ACTIVE</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success bg-opacity-25 border border-success">
            <div class="card-body text-center">
                <i class="ki-duotone ki-check-circle text-success fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-success"><?= $stats['resolved_today'] ?? 0 ?></div>
                <div class="fs-7 text-muted">RESOLVED</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning bg-opacity-25 border border-warning">
            <div class="card-body text-center">
                <i class="ki-duotone ki-shield-cross text-warning fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-warning"><?= $stats['critical_alerts'] ?? 0 ?></div>
                <div class="fs-7 text-muted">CRITICAL</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-primary bg-opacity-25 border border-primary">
            <div class="card-body text-center">
                <i class="ki-duotone ki-shield-tick text-primary fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-primary"><?= $stats['high_alerts'] ?? 0 ?></div>
                <div class="fs-7 text-muted">HIGH</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info bg-opacity-25 border border-info">
            <div class="card-body text-center">
                <i class="ki-duotone ki-timer text-info fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-info">
                    <?= $stats['avg_response_time'] ? round($stats['avg_response_time'] / 60, 1) : '0' ?>m
                </div>
                <div class="fs-7 text-muted">AVG RESPONSE</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary bg-opacity-25 border border-secondary">
            <div class="card-body text-center">
                <i class="ki-duotone ki-time text-secondary fs-2x mb-3">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <div class="fs-1 fw-bold text-secondary">
                    <?= $stats['avg_resolution_time'] ? round($stats['avg_resolution_time'] / 60, 1) : '0' ?>m
                </div>
                <div class="fs-7 text-muted">AVG RESOLUTION</div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <!-- Active Emergencies - Professional Command Center -->
        <?php if (!empty($activeEmergencies)): ?>
        <div class="card card-flush mb-8">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ki-duotone ki-siren text-danger fs-2 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    EMERGENCY COMMAND CENTER - ACTIVE ALERTS
                </h3>
                <div class="card-toolbar">
                    <button class="btn btn-sm btn-light-primary me-2" onclick="location.reload()">
                        <i class="ki-duotone ki-refresh fs-2"></i>
                        Refresh
                    </button>
                    <button class="btn btn-sm btn-light-info" onclick="toggleSoundAlerts()">
                        <i class="ki-duotone ki-volume-up fs-2"></i>
                        Sound Alerts
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-6">
                    <?php foreach ($activeEmergencies as $emergency): ?>
                    <div class="col-12">
                        <div class="card border border-<?= 
                            $emergency['severity_level'] === 'critical' ? 'danger border-4' : 
                            ($emergency['severity_level'] === 'high' ? 'warning border-3' : 'primary border-2') ?>">
                            <div class="card-header">
                                <div class="card-title">
                                    <span class="badge badge-<?= 
                                        $emergency['severity_level'] === 'critical' ? 'danger fs-6' : 
                                        ($emergency['severity_level'] === 'high' ? 'warning fs-6' : 'primary fs-6') ?> me-2">
                                        <?= strtoupper($emergency['severity_level']) ?>
                                    </span>
                                    <span class="badge badge-light-<?= 
                                        $emergency['alert_type'] === 'medical' ? 'danger' : 
                                        ($emergency['alert_type'] === 'fire' ? 'warning' : 'info') ?> fs-7">
                                        <?= strtoupper(str_replace('_', ' ', $emergency['alert_type'])) ?>
                                    </span>
                                    <span class="ms-3 fw-bold fs-5"><?= e($emergency['alert_number']) ?></span>
                                    <?php if ($emergency['is_silent']): ?>
                                        <span class="badge badge-light-dark fs-8 ms-2">SILENT</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-toolbar">
                                    <?php if ($emergency['status'] === 'reported'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                                            <input type="hidden" name="action" value="acknowledge">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="ki-duotone ki-check fs-2"></i>
                                                ACKNOWLEDGE
                                            </button>
                                        </form>
                                    <?php elseif ($emergency['status'] === 'acknowledged'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                                            <input type="hidden" name="action" value="responding">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="ki-duotone ki-location fs-2"></i>
                                                RESPONDING
                                            </button>
                                        </form>
                                    <?php elseif ($emergency['status'] === 'responding'): ?>
                                        <button type="button" class="btn btn-sm btn-success me-2" data-bs-toggle="modal" data-bs-target="#resolveModal<?= (int)$emergency['id'] ?>">
                                            <i class="ki-duotone ki-check-circle fs-2"></i>
                                            RESOLVE
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#escalateModal<?= (int)$emergency['id'] ?>">
                                            <i class="ki-duotone ki-arrow-up fs-2"></i>
                                            ESCALATE
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5 class="mb-3">
                                            <i class="ki-duotone ki-geolocation text-danger fs-2 me-2">
                                                <span class="path1"></span>
                                                <span class="path2"></span>
                                            </i>
                                            LOCATION: <?= e($emergency['location']) ?>
                                        </h5>
                                        <p class="mb-2">
                                            <strong>Tenant:</strong> 
                                            <?= e($emergency['tenant_name']) ?>
                                            <?php if ($emergency['tenant_phone']): ?>
                                                <span class="badge badge-light-primary ms-2">
                                                    <i class="ki-duotone ki-call fs-6 me-1">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    <?= e($emergency['tenant_phone']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-3">
                                            <strong>Property:</strong> <?= e($emergency['property_name'] ?? 'Unknown') ?> - Unit <?= e($emergency['unit_number'] ?? 'Unknown') ?>
                                        </p>
                                        <p class="mb-3"><strong>Description:</strong> <?= e($emergency['description']) ?></p>
                                        
                                        <?php if ($emergency['response_procedure']): ?>
                                        <div class="bg-light-warning rounded p-3 mb-3">
                                            <h6 class="text-warning">
                                                <i class="ki-duotone ki-information fs-2 me-2">
                                                    <span class="path1"></span>
                                                    <span class="path2"></span>
                                                </i>
                                                RESPONSE PROCEDURE
                                            </h6>
                                            <p class="mb-0 fs-7"><?= nl2br(e($emergency['response_procedure'])) ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="bg-light rounded p-4">
                                            <h6 class="mb-3">TIMELINE</h6>
                                            <div class="mb-3">
                                                <small class="text-muted">Reported:</small>
                                                <div class="fw-bold"><?= date('M j, g:i:s A', strtotime($emergency['reported_at'])) ?></div>
                                            </div>
                                            <?php if ($emergency['acknowledged_at']): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">Acknowledged:</small>
                                                <div class="fw-bold"><?= date('M j, g:i:s A', strtotime($emergency['acknowledged_at'])) ?></div>
                                                <small>by <?= e($emergency['ack_first'] . ' ' . $emergency['ack_last']) ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($emergency['responded_at']): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">Responding:</small>
                                                <div class="fw-bold"><?= date('M j, g:i:s A', strtotime($emergency['responded_at'])) ?></div>
                                                <small>by <?= e($emergency['resp_first'] . ' ' . $emergency['resp_last']) ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($emergency['estimated_response_time']): ?>
                                            <div class="mt-3">
                                                <small class="text-muted">Target Response:</small>
                                                <div class="fw-bold text-<?= $emergency['severity_level'] === 'critical' ? 'danger' : 'warning' ?>">
                                                    <?= round($emergency['estimated_response_time'] / 60, 1) ?> minutes
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="bg-light-info rounded p-4">
                                            <h6 class="mb-3">ACTION STATUS</h6>
                                            <div class="d-flex flex-column gap-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="ki-duotone ki-<?= $emergency['status'] === 'reported' ? 'notification' : 'check' ?> fs-3 me-2 text-<?= $emergency['status'] === 'reported' ? 'warning' : 'success' ?>">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    <span>Reported</span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <i class="ki-duotone ki-<?= $emergency['status'] === 'acknowledged' || $emergency['status'] === 'responding' || $emergency['status'] === 'resolved' ? 'check' : 'notification' ?> fs-3 me-2 text-<?= $emergency['status'] === 'acknowledged' || $emergency['status'] === 'responding' || $emergency['status'] === 'resolved' ? 'success' : 'secondary' ?>">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    <span>Acknowledged</span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <i class="ki-duotone ki-<?= $emergency['status'] === 'responding' || $emergency['status'] === 'resolved' ? 'check' : 'notification' ?> fs-3 me-2 text-<?= $emergency['status'] === 'responding' || $emergency['status'] === 'resolved' ? 'success' : 'secondary' ?>">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    <span>Responding</span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <i class="ki-duotone ki-<?= $emergency['status'] === 'resolved' ? 'check' : 'notification' ?> fs-3 me-2 text-<?= $emergency['status'] === 'resolved' ? 'success' : 'secondary' ?>">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    <span>Resolved</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card card-flush">
            <div class="card-body text-center py-15">
                <i class="ki-duotone ki-shield-tick fs-5x text-success mb-5">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <h3 class="text-success">All Clear - No Active Emergencies</h3>
                <p class="text-muted">Emergency response system is operational and ready.</p>
                <div class="mt-5">
                    <button class="btn btn-light-success me-3" onclick="location.reload()">
                        <i class="ki-duotone ki-refresh fs-2 me-2"></i>
                        Check for Updates
                    </button>
                    <button class="btn btn-light-info" onclick="testEmergencySystem()">
                        <i class="ki-duotone ki-test fs-2 me-2"></i>
                        Test System
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Resolution and Escalation Modals -->
<?php foreach ($activeEmergencies as $emergency): ?>
<!-- Resolution Modal -->
<div class="modal fade" id="resolveModal<?= (int)$emergency['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                <input type="hidden" name="action" value="resolve">
                <div class="modal-header bg-success">
                    <h5 class="modal-title text-white">Resolve Emergency Alert</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <label class="form-label fw-bold">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" rows="4" 
                                  placeholder="Document how the emergency was resolved, actions taken, and final outcome..." required></textarea>
                    </div>
                    
                    <?php if ($emergency['equipment_needed']): ?>
                    <div class="mb-4">
                        <label class="form-label fw-bold">Equipment Used</label>
                        <div class="row">
                            <?php foreach (json_decode($emergency['equipment_needed'], true) as $equipment): ?>
                            <div class="col-md-4">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="equipment_used[]" value="<?= e($equipment) ?>">
                                    <span class="form-check-label"><?= e(ucwords(str_replace('_', ' ', $equipment))) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info">
                        <i class="ki-duotone ki-information fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        This will mark the emergency as resolved and close the alert. All actions will be logged for audit purposes.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="ki-duotone ki-check-circle fs-2 me-2"></i>
                        Mark as Resolved
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Escalation Modal -->
<div class="modal fade" id="escalateModal<?= (int)$emergency['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                <input type="hidden" name="action" value="escalate">
                <div class="modal-header bg-danger">
                    <h5 class="modal-title text-white">Escalate Emergency</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Escalation Level</label>
                        <select name="escalation_level" class="form-select" required>
                            <option value="level_2">Level 2 - Management Team</option>
                            <option value="level_3">Level 3 - External Authorities</option>
                            <option value="external">External - Police/Fire/Medical</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason for Escalation</label>
                        <textarea name="escalation_reason" class="form-control" rows="3" 
                                  placeholder="Explain why this emergency requires escalation..." required></textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="ki-duotone ki-warning fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        Escalation will notify higher authorities and external emergency services.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="ki-duotone ki-arrow-up fs-2 me-2"></i>
                        Escalate Emergency
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Audible Alert Audio Player -->
<audio id="emergencySiren" preload="auto">
    <source src="../assets/media/sounds/emergency-siren.mp3" type="audio/mpeg">
    <source src="../assets/media/sounds/emergency-siren.wav" type="audio/wav">
    Your browser does not support the audio element.
</audio>

<script>
// Professional Emergency Response System JavaScript

// Auto-refresh active emergencies every 15 seconds (faster for emergencies)
setInterval(function() {
    if (document.querySelector('.card-title i.ki-siren')) {
        location.reload();
    }
}, 15000);

// Audible alert system
function playEmergencySound() {
    const siren = document.getElementById('emergencySiren');
    if (siren) {
        siren.play().catch(e => console.log('Audio play failed:', e));
    }
}

// Handle audible alerts from server
function checkForAudibleAlerts() {
    fetch('check_audible_alerts.php')
        .then(response => response.json())
        .then(data => {
            if (data.hasAlerts) {
                playEmergencySound();
                // Mark alerts as played
                fetch('mark_audible_alerts_played.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({alertIds: data.alertIds})
                });
            }
        })
        .catch(error => console.error('Error checking audible alerts:', error));
}

// Toggle sound alerts
function toggleSoundAlerts() {
    const soundEnabled = localStorage.getItem('emergencySoundEnabled') === 'true';
    localStorage.setItem('emergencySoundEnabled', !soundEnabled);
    const btn = event.target.closest('button');
    btn.innerHTML = !soundEnabled ? 
        '<i class="ki-duotone ki-volume-mute fs-2"></i> Sound OFF' :
        '<i class="ki-duotone ki-volume-up fs-2"></i> Sound ON';
}

// Test emergency system
function testEmergencySystem() {
    if (confirm('This will create a test emergency alert. Continue?')) {
        fetch('test_emergency_alert.php', {method: 'POST'})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Test emergency alert created successfully!');
                    location.reload();
                } else {
                    alert('Failed to create test alert: ' + data.error);
                }
            });
    }
}

// Initialize professional system
document.addEventListener('DOMContentLoaded', function() {
    // Check for audible alerts on page load
    checkForAudibleAlerts();
    
    // Set up periodic audible alert checking
    setInterval(checkForAudibleAlerts, 5000);
    
    // Set initial sound button state
    const soundEnabled = localStorage.getItem('emergencySoundEnabled') === 'true';
    const soundBtn = document.querySelector('button[onclick="toggleSoundAlerts()"]');
    if (soundBtn) {
        soundBtn.innerHTML = soundEnabled ? 
            '<i class="ki-duotone ki-volume-up fs-2"></i> Sound ON' :
            '<i class="ki-duotone ki-volume-mute fs-2"></i> Sound OFF';
    }
    
    // Add professional styling
    document.body.classList.add('emergency-command-center');
});

// Professional CSS styling
const style = document.createElement('style');
style.textContent = `
    .emergency-command-center {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    }
    .card.border-danger.border-4 {
        box-shadow: 0 0 20px rgba(220, 38, 38, 0.3);
        animation: pulse-border 2s infinite;
    }
    @keyframes pulse-border {
        0% { box-shadow: 0 0 20px rgba(220, 38, 38, 0.3); }
        50% { box-shadow: 0 0 30px rgba(220, 38, 38, 0.6); }
        100% { box-shadow: 0 0 20px rgba(220, 38, 38, 0.3); }
    }
    .btn-warning, .btn-primary, .btn-success, .btn-danger {
        font-weight: 600;
        letter-spacing: 0.5px;
    }
`;
document.head.appendChild(style);
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>