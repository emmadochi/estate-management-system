<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);
$pageTitle = 'Emergency Response – EstatePro Security';
$pageHeading = 'Emergency Response Center';
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

// Handle alert actions
if ($_POST && isset($_POST['action'])) {
    verify_csrf();
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $action = $_POST['action'];
    
    if ($alertId && $securityPersonnel) {
        try {
            $currentTime = date('Y-m-d H:i:s');
            $securityId = (int)$securityPersonnel['id'];
            
            switch ($action) {
                case 'acknowledge':
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'acknowledged', acknowledged_at = ?, acknowledged_by = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $me['id'], $alertId, $estateId]
                    );
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', 'Emergency alert acknowledged. Proceeding to respond.');
                    break;
                    
                case 'responding':
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'responding', responded_at = ?, responded_by = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $me['id'], $alertId, $estateId]
                    );
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', 'Response in progress. Proceed to location immediately.');
                    break;
                    
                case 'resolve':
                    $notes = trim($_POST['resolution_notes'] ?? '');
                    $db->execute(
                        "UPDATE emergency_alerts 
                         SET status = 'resolved', resolved_at = ?, resolution_notes = ?
                         WHERE id = ? AND estate_id = ?",
                        [$currentTime, $notes, $alertId, $estateId]
                    );
                    // Update response times
                    update_emergency_response_times($alertId);
                    flash_set('success', 'Emergency resolved and closed.');
                    break;
            }
        } catch (Exception $e) {
            flash_set('error', 'Failed to update emergency status.');
            error_log('Emergency response error: ' . $e->getMessage());
        }
    }
    redirect('emergency_response.php');
}

// Get active emergencies for this estate
$activeEmergencies = $db->fetchAll(
    "SELECT ea.*, 
            t.emergency_contact_name as tenant_name,
            u.unit_number, p.name as property_name,
            ack_user.first_name as ack_first, ack_user.last_name as ack_last,
            resp_user.first_name as resp_first, resp_user.last_name as resp_last
     FROM emergency_alerts ea
     JOIN tenants t ON ea.tenant_id = t.id
     JOIN units u ON ea.unit_id = u.id
     JOIN properties p ON u.property_id = p.id
     LEFT JOIN users ack_user ON ea.acknowledged_by = ack_user.id
     LEFT JOIN users resp_user ON ea.responded_by = resp_user.id
     WHERE ea.estate_id = ? 
     AND ea.status IN ('reported', 'acknowledged', 'responding')
     ORDER BY ea.severity_level DESC, ea.reported_at DESC",
    [$estateId]
);

// Get recent resolved emergencies
$resolvedEmergencies = $db->fetchAll(
    "SELECT ea.*, 
            t.emergency_contact_name as tenant_name,
            u.unit_number,
            p.name as property_name,
            p.type as property_type
     FROM emergency_alerts ea
     JOIN tenants t ON ea.tenant_id = t.id
     JOIN units u ON ea.unit_id = u.id
     JOIN properties p ON u.property_id = p.id
     WHERE ea.estate_id = ? 
     AND ea.status IN ('resolved', 'closed')
     ORDER BY ea.resolved_at DESC
     LIMIT 10",
    [$estateId]
);

// Get emergency statistics
$stats = $db->fetchOne(
    "SELECT 
        COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_alerts,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
        COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_alerts,
        AVG(CASE WHEN response_time_seconds IS NOT NULL THEN response_time_seconds END) as avg_response_time
     FROM emergency_alerts 
     WHERE estate_id = ? AND DATE(reported_at) = CURDATE()",
    [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="row mb-8">
    <!-- Stats Cards -->
    <div class="col-md-3">
        <div class="card bg-danger bg-opacity-25 border border-danger">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="ki-duotone ki-siren text-danger fs-2x me-4">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    <div>
                        <div class="fs-2 fw-bold text-danger"><?= $stats['active_alerts'] ?? 0 ?></div>
                        <div class="fs-7 text-muted">Active Alerts</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success bg-opacity-25 border border-success">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="ki-duotone ki-check-circle text-success fs-2x me-4">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    <div>
                        <div class="fs-2 fw-bold text-success"><?= $stats['resolved_today'] ?? 0 ?></div>
                        <div class="fs-7 text-muted">Resolved Today</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning bg-opacity-25 border border-warning">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="ki-duotone ki-shield text-warning fs-2x me-4">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    <div>
                        <div class="fs-2 fw-bold text-warning"><?= $stats['critical_alerts'] ?? 0 ?></div>
                        <div class="fs-7 text-muted">Critical Alerts</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info bg-opacity-25 border border-info">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <i class="ki-duotone ki-timer text-info fs-2x me-4">
                        <span class="path1"></span>
                        <span class="path2"></span>
                        <span class="path3"></span>
                    </i>
                    <div>
                        <div class="fs-2 fw-bold text-info">
                            <?= $stats['avg_response_time'] ? round($stats['avg_response_time'] / 60, 1) : '0' ?>m
                        </div>
                        <div class="fs-7 text-muted">Avg Response</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <!-- Active Emergencies -->
        <?php if (!empty($activeEmergencies)): ?>
        <div class="card card-flush mb-8">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ki-duotone ki-siren text-danger fs-2 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    ACTIVE EMERGENCY ALERTS
                </h3>
                <div class="card-toolbar">
                    <button class="btn btn-sm btn-light-primary" onclick="location.reload()">
                        <i class="ki-duotone ki-refresh fs-2"></i>
                        Refresh
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-6">
                    <?php foreach ($activeEmergencies as $emergency): ?>
                    <div class="col-12">
                        <div class="card border border-<?= 
                            $emergency['severity_level'] === 'critical' ? 'danger' : 
                            ($emergency['severity_level'] === 'high' ? 'warning' : 'primary') ?> 
                            border-3">
                            <div class="card-header">
                                <div class="card-title">
                                    <span class="badge badge-<?= 
                                        $emergency['severity_level'] === 'critical' ? 'danger' : 
                                        ($emergency['severity_level'] === 'high' ? 'warning' : 'primary') ?> fs-7 me-2">
                                        <?= strtoupper($emergency['severity_level']) ?>
                                    </span>
                                    <span class="badge badge-light-<?= 
                                        $emergency['alert_type'] === 'medical' ? 'danger' : 
                                        ($emergency['alert_type'] === 'fire' ? 'warning' : 'info') ?> fs-7">
                                        <?= strtoupper(str_replace('_', ' ', $emergency['alert_type'])) ?>
                                    </span>
                                    <span class="ms-3 fw-bold"><?= e($emergency['alert_number']) ?></span>
                                </div>
                                <div class="card-toolbar">
                                    <?php if ($emergency['status'] === 'reported'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                                            <input type="hidden" name="action" value="acknowledge">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="ki-duotone ki-check fs-2"></i>
                                                Acknowledge
                                            </button>
                                        </form>
                                    <?php elseif ($emergency['status'] === 'acknowledged'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                                            <input type="hidden" name="action" value="responding">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="ki-duotone ki-location fs-2"></i>
                                                Responding
                                            </button>
                                        </form>
                                    <?php elseif ($emergency['status'] === 'responding'): ?>
                                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#resolveModal<?= (int)$emergency['id'] ?>">
                                            <i class="ki-duotone ki-check-circle fs-2"></i>
                                            Mark Resolved
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="mb-3">📍 Location: <?= e($emergency['location']) ?></h5>
                                        <p class="mb-3"><strong>Tenant:</strong> <?= e($emergency['tenant_name']) ?></p>
                                        <p class="mb-3"><strong>Property:</strong> <?= e($emergency['property_name'] ?? 'Unknown') ?> - Unit <?= e($emergency['unit_number'] ?? 'Unknown') ?></p>
                                        <p class="mb-3"><strong>Description:</strong> <?= e($emergency['description']) ?></p>
                                        <?php if ($emergency['is_silent']): ?>
                                            <span class="badge badge-light-dark">SILENT ALERT</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="bg-light rounded p-4">
                                            <h6 class="mb-3">Timeline</h6>
                                            <div class="mb-2">
                                                <small class="text-muted">Reported:</small>
                                                <div class="fw-bold"><?= date('M j, g:i A', strtotime($emergency['reported_at'])) ?></div>
                                            </div>
                                            <?php if ($emergency['acknowledged_at']): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Acknowledged:</small>
                                                <div class="fw-bold"><?= date('M j, g:i A', strtotime($emergency['acknowledged_at'])) ?></div>
                                                <small>by <?= e($emergency['ack_first'] . ' ' . $emergency['ack_last']) ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($emergency['responded_at']): ?>
                                            <div class="mb-2">
                                                <small class="text-muted">Responding:</small>
                                                <div class="fw-bold"><?= date('M j, g:i A', strtotime($emergency['responded_at'])) ?></div>
                                                <small>by <?= e($emergency['resp_first'] . ' ' . $emergency['resp_last']) ?></small>
                                            </div>
                                            <?php endif; ?>
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
                <h3 class="text-success">No Active Emergencies</h3>
                <p class="text-muted">All emergency alerts have been resolved. Great job!</p>
                <button class="btn btn-light-success" onclick="location.reload()">
                    <i class="ki-duotone ki-refresh fs-2 me-2"></i>
                    Check for Updates
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resolved Emergencies -->
        <?php if (!empty($resolvedEmergencies)): ?>
        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title">Recently Resolved Emergencies</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                        <thead>
                            <tr class="fw-bold text-muted">
                                <th>Alert ID</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Tenant</th>
                                <th>Reported</th>
                                <th>Resolved</th>
                                <th>Response Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resolvedEmergencies as $emergency): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-light-success"><?= e($emergency['alert_number']) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-light-<?= 
                                        $emergency['alert_type'] === 'medical' ? 'danger' : 
                                        ($emergency['alert_type'] === 'fire' ? 'warning' : 'info') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $emergency['alert_type'])) ?>
                                    </span>
                                </td>
                                <td><?= e($emergency['location']) ?></td>
                                <td><?= e($emergency['tenant_name']) ?></td>
                                <td><?= date('M j, g:i A', strtotime($emergency['reported_at'])) ?></td>
                                <td><?= date('M j, g:i A', strtotime($emergency['resolved_at'])) ?></td>
                                <td>
                                    <?php if ($emergency['response_time_seconds']): ?>
                                        <?= round($emergency['response_time_seconds'] / 60, 1) ?> minutes
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Resolution Modals -->
<?php foreach ($activeEmergencies as $emergency): ?>
<?php if ($emergency['status'] === 'responding'): ?>
<div class="modal fade" id="resolveModal<?= (int)$emergency['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="alert_id" value="<?= (int)$emergency['id'] ?>">
                <input type="hidden" name="action" value="resolve">
                <div class="modal-header">
                    <h5 class="modal-title">Resolve Emergency Alert</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-control" rows="3" 
                                  placeholder="Describe how the emergency was resolved..."></textarea>
                    </div>
                    <div class="alert alert-info">
                        <i class="ki-duotone ki-information fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        This will mark the emergency as resolved and close the alert.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark as Resolved</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
// Auto-refresh active emergencies every 30 seconds
setInterval(function() {
    if (document.querySelector('.card-title i.ki-siren')) {
        // Only refresh if there are active emergencies
        fetch(window.location.href, {method: 'GET'})
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.querySelector('.card-body .row');
                if (newContent) {
                    document.querySelector('.card-body .row').innerHTML = newContent.innerHTML;
                }
            });
    }
}, 30000);

// Add sound alert for new emergencies (simplified version)
function playAlertSound() {
    // In a real implementation, you'd play an actual sound file
    console.log('🚨 EMERGENCY ALERT RECEIVED!');
}

// Check for new emergencies on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($activeEmergencies)): ?>
    playAlertSound();
    <?php endif; ?>
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>