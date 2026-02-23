<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/emergency_system.php';

$tenant = require_tenant();
$me = current_user();
$pageTitle = 'Professional Emergency Alert System – EstatePro Tenant';
$pageHeading = 'Emergency Alert Command Center';
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
    "SELECT u.unit_number, p.name as property_name, e.name as estate_name 
     FROM units u 
     JOIN properties p ON u.property_id = p.id
     JOIN estates e ON u.estate_id = e.id 
     WHERE u.id = ?",
    [$uid]
);

// Handle emergency alert submission with professional system
if ($_POST && isset($_POST['emergency_alert'])) {
    verify_csrf();
    
    $alertData = [
        'tenant_id' => $tid,
        'estate_id' => $eid,
        'unit_id' => $uid,
        'alert_type' => trim($_POST['alert_type'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'is_silent' => isset($_POST['is_silent']) ? 1 : 0
    ];
    
    $errors = [];
    
    if (empty($alertData['alert_type'])) {
        $errors[] = 'Please select an emergency type.';
    }
    
    if (empty($alertData['description'])) {
        $errors[] = 'Please provide a detailed description of the emergency.';
    }
    
    if (strlen($alertData['description']) < 10) {
        $errors[] = 'Description must be at least 10 characters long.';
    }
    
    if (empty($errors)) {
        try {
            // Use professional emergency system
            $result = $emergencySystem->createEmergencyAlert($alertData);
            
            if ($result['success']) {
                flash_set('success', '🚨 EMERGENCY ALERT SUBMITTED SUCCESSFULLY!<br>Alert ID: ' . $result['alert_number'] . '<br>Severity: ' . strtoupper($result['severity']) . '<br>Security has been notified immediately.');
                redirect('emergency_alert_pro.php');
            } else {
                $errors[] = $result['error'];
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

// Get emergency statistics for tenant
$tenantStats = $db->fetchOne(
    "SELECT 
        COUNT(*) as total_alerts,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_alerts,
        COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_alerts,
        COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_alerts
     FROM emergency_alerts 
     WHERE tenant_id = ?",
    [$tid]
);

// Get recent emergency alerts for this tenant
$recentAlerts = $db->fetchAll(
    "SELECT id, alert_number, alert_type, severity_level, description, status, reported_at, resolved_at,
            acknowledged_at, responded_at, resolution_notes, response_time_seconds
     FROM emergency_alerts
     WHERE tenant_id = ?
     ORDER BY reported_at DESC
     LIMIT 15",
    [$tid]
);

// Get emergency response templates for guidance
$responseTemplates = $db->fetchAll(
    "SELECT alert_type, severity_level, response_procedure, estimated_response_time
     FROM emergency_response_templates
     WHERE is_active = TRUE
     ORDER BY alert_type, severity_level"
);

require __DIR__ . '/partials/top.php';
?>

<!-- Professional Emergency Alert System -->
<div class="row">
    <div class="col-12">
        <!-- Professional Emergency Command Center -->
        <div class="card bg-gradient-danger mb-8">
            <div class="card-body text-center py-15">
                <div class="position-relative">
                    <i class="ki-duotone ki-shield-tick fs-7x text-white mb-8 opacity-25">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    <div class="position-absolute top-50 start-50 translate-middle">
                        <i class="ki-duotone ki-siren fs-5x text-white mb-4">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                    </div>
                </div>
                <h1 class="text-white mb-4 display-3 fw-bolder">🚨 EMERGENCY ALERT SYSTEM</h1>
                <p class="text-white fs-2 mb-8 fw-light">Professional 24/7 Emergency Response Service</p>
                
                <div class="row justify-content-center mb-8">
                    <div class="col-md-8">
                        <div class="d-grid gap-3 d-md-flex justify-content-md-center">
                            <button type="button" class="btn btn-light-danger btn-lg px-8 py-4 me-md-3" 
                                    data-bs-toggle="modal" data-bs-target="#emergencyModal">
                                <i class="ki-duotone ki-siren fs-1 me-3">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <span class="fs-2 fw-bold">REPORT EMERGENCY</span>
                            </button>
                            <button type="button" class="btn btn-outline-light btn-lg px-6 py-4" 
                                    onclick="showEmergencyGuide()">
                                <i class="ki-duotone ki-information fs-1 me-2">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <span class="fs-4">EMERGENCY GUIDE</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <span class="badge badge-light-danger fs-4 py-3 px-4">
                        <i class="ki-duotone ki-shield fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        24/7 SECURITY RESPONSE
                    </span>
                    <span class="badge badge-light-danger fs-4 py-3 px-4">
                        <i class="ki-duotone ki-timer fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        IMMEDIATE NOTIFICATION
                    </span>
                    <span class="badge badge-light-danger fs-4 py-3 px-4">
                        <i class="ki-duotone ki-route fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        REAL-TIME TRACKING
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Emergency Statistics Dashboard -->
        <div class="row mb-8">
            <div class="col-md-3">
                <div class="card bg-light-danger border border-danger">
                    <div class="card-body text-center">
                        <i class="ki-duotone ki-siren text-danger fs-3x mb-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        <div class="fs-1 fw-bold text-danger"><?= $tenantStats['total_alerts'] ?? 0 ?></div>
                        <div class="fs-6 text-muted">TOTAL ALERTS</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-light-success border border-success">
                    <div class="card-body text-center">
                        <i class="ki-duotone ki-check-circle text-success fs-3x mb-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        <div class="fs-1 fw-bold text-success"><?= $tenantStats['resolved_alerts'] ?? 0 ?></div>
                        <div class="fs-6 text-muted">RESOLVED</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-light-warning border border-warning">
                    <div class="card-body text-center">
                        <i class="ki-duotone ki-shield-cross text-warning fs-3x mb-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        <div class="fs-1 fw-bold text-warning"><?= $tenantStats['active_alerts'] ?? 0 ?></div>
                        <div class="fs-6 text-muted">ACTIVE</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-light-primary border border-primary">
                    <div class="card-body text-center">
                        <i class="ki-duotone ki-shield-tick text-primary fs-3x mb-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        <div class="fs-1 fw-bold text-primary"><?= $tenantStats['critical_alerts'] ?? 0 ?></div>
                        <div class="fs-6 text-muted">CRITICAL</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Professional Emergency Types with Response Information -->
        <div class="card card-flush mb-8">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ki-duotone ki-information text-primary fs-2 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    EMERGENCY TYPES & RESPONSE TIMES
                </h3>
            </div>
            <div class="card-body">
                <div class="row g-6">
                    <?php foreach ($responseTemplates as $template): ?>
                    <div class="col-md-4">
                        <div class="border border-2 rounded p-5 h-100 <?= 
                            $template['alert_type'] === 'medical' ? 'border-danger bg-light-danger' :
                            ($template['alert_type'] === 'fire' ? 'border-warning bg-light-warning' : 'border-info bg-light-info') ?>">
                            <div class="d-flex align-items-center mb-4">
                                <i class="ki-duotone ki-<?= 
                                    $template['alert_type'] === 'medical' ? 'heart-circle text-danger' :
                                    ($template['alert_type'] === 'fire' ? 'fire text-warning' : 'security-user text-info') ?> fs-2x me-3">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <div>
                                    <h4 class="mb-1 <?= 
                                        $template['alert_type'] === 'medical' ? 'text-danger' :
                                        ($template['alert_type'] === 'fire' ? 'text-warning' : 'text-info') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $template['alert_type'])) ?>
                                    </h4>
                                    <span class="badge badge-<?= 
                                        $template['severity_level'] === 'critical' ? 'danger' :
                                        ($template['severity_level'] === 'high' ? 'warning' : 'primary') ?> fs-8">
                                        <?= strtoupper($template['severity_level']) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted">ESTIMATED RESPONSE:</small>
                                <div class="fw-bold fs-5 <?= 
                                    $template['severity_level'] === 'critical' ? 'text-danger' :
                                    ($template['severity_level'] === 'high' ? 'text-warning' : 'text-primary') ?>">
                                    <?= round($template['estimated_response_time'] / 60, 1) ?> minutes
                                </div>
                            </div>
                            <div class="small text-muted">
                                <?= e(substr($template['response_procedure'], 0, 100)) ?><?= strlen($template['response_procedure']) > 100 ? '...' : '' ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Recent Emergency Alerts History -->
        <?php if (!empty($recentAlerts)): ?>
        <div class="card card-flush">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ki-duotone ki-time text-info fs-2 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    YOUR EMERGENCY ALERT HISTORY
                </h3>
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
                                <th>Response Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAlerts as $alert): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-light-primary fs-7"><?= e($alert['alert_number']) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-light-<?= 
                                        $alert['alert_type'] === 'medical' ? 'danger' : 
                                        ($alert['alert_type'] === 'fire' ? 'warning' : 'info') ?> fs-7">
                                        <?= ucfirst(str_replace('_', ' ', $alert['alert_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= 
                                        $alert['severity_level'] === 'critical' ? 'danger' : 
                                        ($alert['severity_level'] === 'high' ? 'warning' : 'secondary') ?> fs-7">
                                        <?= ucfirst($alert['severity_level']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-light" onclick="showAlertDetails(<?= (int)$alert['id'] ?>)">
                                        <?= e(substr($alert['description'], 0, 50)) ?><?= strlen($alert['description']) > 50 ? '...' : '' ?>
                                    </button>
                                </td>
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
                                    <span class="badge badge-light-<?= $statusClass ?> fs-7">
                                        <?= ucfirst(str_replace('_', ' ', $alert['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap"><?= date('M j, Y g:i A', strtotime($alert['reported_at'])) ?></td>
                                <td>
                                    <?php if ($alert['response_time_seconds']): ?>
                                        <span class="badge badge-<?= 
                                            $alert['response_time_seconds'] <= 120 ? 'success' : 
                                            ($alert['response_time_seconds'] <= 300 ? 'warning' : 'danger') ?> fs-8">
                                            <?= round($alert['response_time_seconds'] / 60, 1) ?>m
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted fs-8">Pending</span>
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

<!-- Professional Emergency Modal -->
<div class="modal fade" id="emergencyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-danger">
                <h2 class="modal-title text-white">
                    <i class="ki-duotone ki-siren fs-1 me-3">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    PROFESSIONAL EMERGENCY ALERT
                </h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="emergency_alert" value="1">
                <div class="modal-body">
                    <div class="alert alert-danger border border-danger border-3">
                        <div class="d-flex">
                            <i class="ki-duotone ki-information fs-2x text-danger me-4">
                                <span class="path1"></span>
                                <span class="path2"></span>
                                <span class="path3"></span>
                            </i>
                            <div>
                                <h4 class="mb-2 text-danger">🚨 IMMEDIATE RESPONSE REQUIRED</h4>
                                <p class="mb-0">This alert will be sent to security personnel immediately with your exact location. Please provide accurate information.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-6">
                                <label class="form-label fw-bold required fs-4">Emergency Type</label>
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
                        </div>
                        <div class="col-md-6">
                            <div class="mb-6">
                                <label class="form-label fw-bold fs-4">Severity Level</label>
                                <div class="form-control bg-light">
                                    <span class="badge badge-light-secondary fs-5">Auto-Detected</span>
                                    <span class="text-muted ms-2">System will determine based on your description</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="form-label fw-bold required fs-4">Detailed Description</label>
                        <textarea name="description" class="form-control form-control-lg" rows="5" 
                                  placeholder="Please describe the emergency situation in detail. Include:
• What is happening
• Current location within your unit
• Number of people involved
• Any immediate dangers
• Current status" required></textarea>
                        <div class="form-text fs-6">Minimum 10 characters required. The more detail you provide, the faster the response.</div>
                    </div>
                    
                    <div class="row mb-6">
                        <div class="col-md-6">
                            <label class="form-check form-switch form-check-custom form-check-solid">
                                <input class="form-check-input" type="checkbox" name="is_silent" value="1"/>
                                <span class="form-check-label fs-5">Silent Alert Mode</span>
                            </label>
                            <div class="form-text">Use this when you cannot make noise (e.g., intruder present)</div>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="badge badge-light-danger fs-5 py-3 px-4">
                                <i class="ki-duotone ki-timer fs-2 me-2">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                RESPONSE TARGET: 2-5 MINUTES
                            </div>
                        </div>
                    </div>
                    
                    <div class="border border-dashed border-gray-300 rounded p-5 bg-light">
                        <h5 class="mb-4">
                            <i class="ki-duotone ki-geolocation text-primary fs-2 me-2">
                                <span class="path1"></span>
                                <span class="path2"></span>
                            </i>
                            YOUR LOCATION INFORMATION
                        </h5>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label text-muted fs-7">ESTATE</label>
                                    <div class="fw-bold fs-5"><?= e($unit['estate_name'] ?? 'Unknown') ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label text-muted fs-7">PROPERTY</label>
                                    <div class="fw-bold fs-5"><?= e($unit['property_name'] ?? 'Unknown') ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label text-muted fs-7">UNIT</label>
                                    <div class="fw-bold fs-5"><?= e($unit['unit_number'] ?? 'Unknown') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mb-0">
                            <i class="ki-duotone ki-information fs-2 me-2">
                                <span class="path1"></span>
                                <span class="path2"></span>
                            </i>
                            Your exact location has been automatically detected and will be sent with the alert.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light fs-4 py-3 px-6" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-arrow-left fs-2 me-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger fs-4 py-3 px-8">
                        <i class="ki-duotone ki-send fs-2 me-3">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        SEND EMERGENCY ALERT NOW
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Emergency Guide Modal -->
<div class="modal fade" id="emergencyGuideModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="ki-duotone ki-information text-primary fs-1 me-3">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    EMERGENCY RESPONSE GUIDE
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="text-danger mb-4">🚨 When to Use Emergency Alert:</h4>
                        <ul class="fs-5">
                            <li class="mb-2">Medical emergencies (heart attack, serious injury)</li>
                            <li class="mb-2">Fire or smoke in your unit or building</li>
                            <li class="mb-2">Security breach or intruder</li>
                            <li class="mb-2">Serious theft or criminal activity</li>
                            <li class="mb-2">Physical assault or threat</li>
                            <li class="mb-2">Any situation requiring immediate security response</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h4 class="text-success mb-4">✅ What Happens Next:</h4>
                        <div class="d-flex align-items-center mb-3">
                            <div class="symbol symbol-40px bg-primary me-3">
                                <span class="symbol-label fs-6 fw-bold text-white">1</span>
                            </div>
                            <div>
                                <strong>Immediate Notification</strong>
                                <div class="text-muted">All security personnel receive real-time alert</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <div class="symbol symbol-40px bg-warning me-3">
                                <span class="symbol-label fs-6 fw-bold text-white">2</span>
                            </div>
                            <div>
                                <strong>Rapid Response</strong>
                                <div class="text-muted">Security acknowledges within 2 minutes</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center mb-3">
                            <div class="symbol symbol-40px bg-info me-3">
                                <span class="symbol-label fs-6 fw-bold text-white">3</span>
                            </div>
                            <div>
                                <strong>On-Site Arrival</strong>
                                <div class="text-muted">Security arrives within 5-10 minutes</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="symbol symbol-40px bg-success me-3">
                                <span class="symbol-label fs-6 fw-bold text-white">4</span>
                            </div>
                            <div>
                                <strong>Resolution</strong>
                                <div class="text-muted">Emergency resolved and documented</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr class="my-8">
                
                <div class="text-center">
                    <h4 class="text-primary mb-4">📞 For Life-Threatening Emergencies</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="border border-2 border-danger rounded p-4">
                                <i class="ki-duotone ki-call text-danger fs-2x mb-3">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <h5 class="text-danger">Police Emergency</h5>
                                <div class="display-6 fw-bold">112</div>
                                <div class="text-muted">For immediate police response</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border border-2 border-warning rounded p-4">
                                <i class="ki-duotone ki-fire text-warning fs-2x mb-3">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <h5 class="text-warning">Fire Emergency</h5>
                                <div class="display-6 fw-bold">112</div>
                                <div class="text-muted">For fire department response</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border border-2 border-success rounded p-4">
                                <i class="ki-duotone ki-heart-circle text-success fs-2x mb-3">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <h5 class="text-success">Medical Emergency</h5>
                                <div class="display-6 fw-bold">112</div>
                                <div class="text-muted">For ambulance services</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close Guide</button>
            </div>
        </div>
    </div>
</div>

<script>
// Professional Emergency Alert System JavaScript

// Auto-focus on emergency type when modal opens
document.getElementById('emergencyModal').addEventListener('shown.bs.modal', function () {
    this.querySelector('select[name="alert_type"]').focus();
});

// Show emergency guide
function showEmergencyGuide() {
    new bootstrap.Modal(document.getElementById('emergencyGuideModal')).show();
}

// Show alert details
function showAlertDetails(alertId) {
    // In a real implementation, this would fetch and display detailed alert information
    alert('Alert details would be displayed here in a professional modal.');
}

// Add professional styling
document.addEventListener('DOMContentLoaded', function() {
    // Add pulse animation to emergency button
    const emergencyBtn = document.querySelector('[data-bs-target="#emergencyModal"]');
    if (emergencyBtn) {
        emergencyBtn.classList.add('pulse');
        emergencyBtn.style.animation = 'pulse 2s infinite';
    }
    
    // Add professional form validation
    const form = document.querySelector('#emergencyModal form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const description = this.querySelector('textarea[name="description"]').value;
            if (description.length < 10) {
                e.preventDefault();
                alert('Please provide a detailed description (minimum 10 characters).');
                return false;
            }
        });
    }
});

// Professional CSS animations
const style = document.createElement('style');
style.textContent = `
    .bg-gradient-danger {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
    }
    @keyframes pulse {
        0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
        70% { transform: scale(1.02); box-shadow: 0 0 0 15px rgba(220, 53, 69, 0); }
        100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }
    .pulse {
        animation: pulse 2s infinite;
    }
    .card.bg-gradient-danger {
        position: relative;
        overflow: hidden;
    }
    .card.bg-gradient-danger::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        animation: rotate 20s linear infinite;
    }
    @keyframes rotate {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
`;
document.head.appendChild(style);
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>