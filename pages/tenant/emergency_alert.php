<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$me = current_user();
$pageTitle = 'Emergency Alert – EstatePro Tenant';
$pageHeading = 'Emergency Alert';
$db = db();

// Check if tenant has active tenancy
if (!$tenant) {
    flash_set('error', 'No active tenancy found. Please contact estate management.');
    redirect('dashboard.php');
}

$tid = (int)$tenant['id'];
$eid = (int)$tenant['estate_id'];
$uid = (int)$tenant['unit_id'];

// Get tenant's unit information
$unit = $db->fetchOne(
    "SELECT u.unit_number, p.name as property_name, p.type as property_type, e.name as estate_name 
     FROM units u 
     JOIN properties p ON u.property_id = p.id
     JOIN estates e ON u.estate_id = e.id 
     WHERE u.id = ?",
    [$uid]
);

// Handle emergency alert submission
if ($_POST && isset($_POST['emergency_alert'])) {
    verify_csrf();
    
    $alertType = trim($_POST['alert_type'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $severity = trim($_POST['severity'] ?? 'high');
    $isSilent = isset($_POST['is_silent']) ? 1 : 0;
    
    $errors = [];
    
    if (empty($alertType)) {
        $errors[] = 'Please select an emergency type.';
    }
    
    if (empty($description)) {
        $errors[] = 'Please provide a brief description of the emergency.';
    }
    
    if (empty($errors)) {
        try {
            // Generate unique alert number
            $alertNumber = 'EMER-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Insert emergency alert
            $alertId = $db->insert(
                "INSERT INTO emergency_alerts 
                 (alert_number, tenant_id, estate_id, unit_id, alert_type, severity_level, 
                  description, location, is_silent, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported')",
                [
                    $alertNumber, $tid, $eid, $uid, $alertType, $severity,
                    $description, 
                    ($unit ? $unit['property_name'] . ' - ' . $unit['unit_number'] : 'Unit #' . $uid),
                    $isSilent
                ]
            );
            
            if ($alertId) {
                // Use the new emergency notification function
                notify_security_of_emergency(
                    $alertId,
                    $eid,
                    $alertType,
                    $description,
                    ($unit ? $unit['property_name'] . ' - ' . $unit['unit_number'] : 'Unit #' . $uid),
                    ($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? '')
                );
                
                // Also notify estate admins
                $admins = $db->fetchAll(
                    "SELECT u.id, u.first_name, u.last_name, u.email
                     FROM users u
                     JOIN user_estates ue ON u.id = ue.user_id
                     WHERE ue.estate_id = ? AND u.role IN ('estate_admin', 'property_manager', 'super_admin')",
                    [$eid]
                );
                
                $adminAlertTitle = '🚨 EMERGENCY ALERT: ' . ucfirst(str_replace('_', ' ', $alertType));
                $adminAlertMessage = "Tenant " . ($tenant['first_name'] ?? '') . " " . ($tenant['last_name'] ?? '') . 
                    " from " . ($unit['property_name'] ?? '') . " " . ($unit['unit_number'] ?? '') . 
                    " reported: " . $description;
                
                foreach ($admins as $admin) {
                    notify_user(
                        (int)$admin['id'],
                        'emergency_alert',
                        $adminAlertTitle,
                        $adminAlertMessage,
                        '../admin/emergency_incidents.php'
                    );
                }
                
                // Set success message
                $severityClass = $severity === 'critical' ? 'danger' : ($severity === 'high' ? 'warning' : 'info');
                flash_set('success', '🚨 Emergency alert submitted successfully! Security has been notified. Alert ID: ' . $alertNumber);
                redirect('emergency_alert.php');
            }
            
        } catch (Exception $e) {
            $errors[] = 'Failed to submit emergency alert. Please try again.';
            error_log('Emergency alert submission error: ' . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        flash_set('error', implode('<br>', $errors));
    }
}

// Get recent emergency alerts for this tenant
$recentAlerts = $db->fetchAll(
    "SELECT id, alert_number, alert_type, severity_level, description, status, reported_at, resolved_at
     FROM emergency_alerts
     WHERE tenant_id = ?
     ORDER BY reported_at DESC
     LIMIT 10",
    [$tid]
);

require __DIR__ . '/partials/top.php';
?>

<div class="row">
    <div class="col-12">
        <!-- Emergency Alert Button - Prominent and Attention-Grabbing -->
        <div class="card bg-danger mb-8">
            <div class="card-body text-center py-15">
                <i class="ki-duotone ki-shield-tick fs-5x text-white mb-8">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
                <h2 class="text-white mb-4">🚨 EMERGENCY ALERT SYSTEM</h2>
                <p class="text-white fs-4 mb-8">Press the button below for immediate assistance</p>
                <button type="button" class="btn btn-light-danger btn-lg px-15 py-5" data-bs-toggle="modal" data-bs-target="#emergencyModal">
                    <i class="ki-duotone ki-siren fs-2x me-3">
                        <span class="path1"></span>
                        <span class="path2"></span>
                        <span class="path3"></span>
                    </i>
                    REPORT EMERGENCY
                </button>
                <div class="mt-6">
                    <span class="badge badge-light-danger fs-7">24/7 Security Response</span>
                    <span class="badge badge-light-danger fs-7 ms-2">Immediate Notification</span>
                </div>
            </div>
        </div>
        
        <!-- Quick Emergency Types -->
        <div class="card card-flush mb-8">
            <div class="card-header">
                <h3 class="card-title">Quick Emergency Types</h3>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="border border-danger border-2 rounded p-4 text-center bg-light-danger bg-opacity-10">
                            <i class="ki-duotone ki-heart-circle text-danger fs-3x mb-3">
                                <span class="path1"></span>
                                <span class="path2"></span>
                            </i>
                            <h4 class="text-danger">Medical Emergency</h4>
                            <p class="text-muted">Heart attack, accident, serious injury</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border border-warning border-2 rounded p-4 text-center bg-light-warning bg-opacity-10">
                            <i class="ki-duotone ki-security-user text-warning fs-3x mb-3">
                                <span class="path1"></span>
                                <span class="path2"></span>
                            </i>
                            <h4 class="text-warning">Security Threat</h4>
                            <p class="text-muted">Break-in, assault, suspicious activity</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border border-primary border-2 rounded p-4 text-center bg-light-primary bg-opacity-10">
                            <i class="ki-duotone ki-fire text-primary fs-3x mb-3">
                                <span class="path1"></span>
                                <span class="path2"></span>
                            </i>
                            <h4 class="text-primary">Fire Emergency</h4>
                            <p class="text-muted">Fire, smoke, gas leak</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Alerts -->
        <?php if (!empty($recentAlerts)): ?>
        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title">Your Recent Emergency Alerts</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                        <thead>
                            <tr class="fw-bold text-muted">
                                <th>Alert ID</th>
                                <th>Type</th>
                                <th>Severity</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Reported</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAlerts as $alert): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-light-primary"><?= e($alert['alert_number']) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-light-<?= 
                                        $alert['alert_type'] === 'medical' ? 'danger' : 
                                        ($alert['alert_type'] === 'fire' ? 'warning' : 'info') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $alert['alert_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= 
                                        $alert['severity_level'] === 'critical' ? 'danger' : 
                                        ($alert['severity_level'] === 'high' ? 'warning' : 'secondary') ?>">
                                        <?= ucfirst($alert['severity_level']) ?>
                                    </span>
                                </td>
                                <td><?= e(substr($alert['description'], 0, 50)) ?><?= strlen($alert['description']) > 50 ? '...' : '' ?></td>
                                <td>
                                    <?php
                                    $statusClass = '';
                                    switch ($alert['status']) {
                                        case 'reported': $statusClass = 'warning'; break;
                                        case 'acknowledged': $statusClass = 'primary'; break;
                                        case 'responding': $statusClass = 'info'; break;
                                        case 'resolved': $statusClass = 'success'; break;
                                        case 'closed': $statusClass = 'secondary'; break;
                                        case 'escalated': $statusClass = 'danger'; break;
                                    }
                                    ?>
                                    <span class="badge badge-light-<?= $statusClass ?>"><?= ucfirst($alert['status']) ?></span>
                                </td>
                                <td><?= date('M j, Y g:i A', strtotime($alert['reported_at'])) ?></td>
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

<!-- Emergency Modal -->
<div class="modal fade" id="emergencyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger">
                <h2 class="modal-title text-white">
                    <i class="ki-duotone ki-siren fs-1 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    EMERGENCY ALERT
                </h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="emergency_alert" value="1">
                <div class="modal-body">
                    <div class="alert alert-danger border border-danger">
                        <i class="ki-duotone ki-information fs-2x text-danger me-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                            <span class="path3"></span>
                        </i>
                        <div class="d-flex flex-column">
                            <h4 class="mb-1 text-danger">🚨 IMMEDIATE RESPONSE REQUIRED</h4>
                            <span>This alert will be sent to security personnel immediately. Please provide accurate information.</span>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="form-label fw-bold required">Emergency Type</label>
                        <select name="alert_type" class="form-select form-select-lg" required>
                            <option value="">Select Emergency Type</option>
                            <option value="medical">🏥 Medical Emergency</option>
                            <option value="fire">🔥 Fire/Smoke Emergency</option>
                            <option value="security_breach">👮 Security Breach/Intruder</option>
                            <option value="theft">💰 Theft/Burglary</option>
                            <option value="assault">👊 Assault/Physical Threat</option>
                            <option value="other">❓ Other Emergency</option>
                        </select>
                    </div>
                    
                    <div class="mb-6">
                        <label class="form-label fw-bold required">Severity Level</label>
                        <select name="severity" class="form-select" required>
                            <option value="high" selected>High - Requires immediate attention</option>
                            <option value="critical">Critical - Life-threatening situation</option>
                            <option value="medium">Medium - Serious but not immediate</option>
                        </select>
                    </div>
                    
                    <div class="mb-6">
                        <label class="form-label fw-bold required">Description</label>
                        <textarea name="description" class="form-control form-control-lg" rows="4" 
                                  placeholder="Describe the emergency situation in detail..." required></textarea>
                        <div class="form-text">Include location details, number of people involved, current status</div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input" type="checkbox" name="is_silent" value="1"/>
                            <span class="form-check-label">Silent Alert (No audible alarm)</span>
                        </label>
                        <div class="form-text">Use this for situations where you cannot make noise</div>
                    </div>
                    
                    <div class="border border-dashed border-gray-300 rounded p-4">
                        <h5 class="mb-3">📍 Your Location Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label text-muted fs-7">Estate</label>
                                    <div class="fw-bold"><?= e($unit['estate_name'] ?? 'Unknown') ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label text-muted fs-7">Unit</label>
                                    <div class="fw-bold"><?= e(($unit['property_name'] ?? '') . ' ' . ($unit['unit_number'] ?? 'Unknown')) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="ki-duotone ki-send fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        SEND EMERGENCY ALERT
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-focus on emergency type when modal opens
document.getElementById('emergencyModal').addEventListener('shown.bs.modal', function () {
    this.querySelector('select[name="alert_type"]').focus();
});

// Add visual feedback for emergency button
document.addEventListener('DOMContentLoaded', function() {
    const emergencyBtn = document.querySelector('[data-bs-target="#emergencyModal"]');
    if (emergencyBtn) {
        // Add pulsing animation to emergency button
        emergencyBtn.classList.add('pulse');
        emergencyBtn.style.animation = 'pulse 2s infinite';
    }
});

// CSS for pulse animation
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    .pulse {
        animation: pulse 2s infinite;
    }
`;
document.head.appendChild(style);
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>