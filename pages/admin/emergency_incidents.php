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
    $securityPersonnel = db()->fetchAll("SELECT sp.id, u.first_name, u.last_name, sp.badge_number, sp.role_level FROM security_personnel sp JOIN users u ON sp.user_id = u.id WHERE sp.status = 'active' ORDER BY sp.role_level DESC, u.first_name, u.last_name");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $securityPersonnel = db()->fetchAll(
            "SELECT sp.id, u.first_name, u.last_name, sp.badge_number, sp.role_level 
             FROM security_personnel sp 
             JOIN users u ON sp.user_id = u.id 
             WHERE sp.estate_id IN ($placeholders) AND sp.status = 'active' 
             ORDER BY sp.role_level DESC, u.first_name, u.last_name",
            $estateIds
        );
    }
}

// Get emergency contact groups
$contactGroups = [];
if ($estateId) {
    $contactGroups = db()->fetchAll(
        "SELECT * FROM emergency_contact_groups WHERE estate_id = ? AND is_active = TRUE ORDER BY group_type",
        [$estateId]
    );
}

// Handle AJAX requests for real-time updates
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard_data') {
    header('Content-Type: application/json');
    
    $dashboardData = getEmergencyDashboardData($estateId);
    echo json_encode($dashboardData);
    exit;
}

// Handle form submissions
handleFormSubmissions();

// Get dashboard statistics
$stats = getDashboardStatistics($estateId);

// Get emergencies for display
$emergencies = getEmergencyDashboardData($estateId);

/**
 * Get dashboard statistics (both incidents and tenant alerts)
 */
function getDashboardStatistics($estateId) {
    $db = db();
    
    if (is_super_admin()) {
        // Get statistics from both emergency incidents and tenant alerts
        $incidentsStats = $db->fetchOne("SELECT 
            COUNT(CASE WHEN status = 'reported' THEN 1 END) as active_emergencies,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
            COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
            COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_count,
            COUNT(CASE WHEN severity_level = 'high' THEN 1 END) as high_count,
            AVG(CASE WHEN resolution_time IS NOT NULL THEN resolution_time END) as avg_resolution_time,
            COUNT(*) as total_emergencies
        FROM emergency_dashboard_view");
        
        $alertsStats = $db->fetchOne("SELECT 
            COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_emergencies,
            COUNT(CASE WHEN status = 'responding' THEN 1 END) as in_progress,
            COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
            COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_count,
            COUNT(CASE WHEN severity_level = 'high' THEN 1 END) as high_count,
            AVG(CASE WHEN resolution_time_seconds IS NOT NULL THEN resolution_time_seconds END) as avg_resolution_time,
            COUNT(*) as total_emergencies
        FROM emergency_alerts");
        
        // Combine statistics
        return [
            'active_emergencies' => ($incidentsStats['active_emergencies'] ?? 0) + ($alertsStats['active_emergencies'] ?? 0),
            'in_progress' => ($incidentsStats['in_progress'] ?? 0) + ($alertsStats['in_progress'] ?? 0),
            'resolved_today' => ($incidentsStats['resolved_today'] ?? 0) + ($alertsStats['resolved_today'] ?? 0),
            'critical_count' => ($incidentsStats['critical_count'] ?? 0) + ($alertsStats['critical_count'] ?? 0),
            'high_count' => ($incidentsStats['high_count'] ?? 0) + ($alertsStats['high_count'] ?? 0),
            'avg_resolution_time' => calculateCombinedAverage([
                $incidentsStats['avg_resolution_time'] ?? 0,
                $alertsStats['avg_resolution_time'] ?? 0
            ]),
            'total_emergencies' => ($incidentsStats['total_emergencies'] ?? 0) + ($alertsStats['total_emergencies'] ?? 0)
        ];
        
    } else {
        $estateIds = allowed_estate_ids();
        if ($estateIds) {
            $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
            
            // Get incidents statistics for allowed estates
            $incidentsStats = $db->fetchOne("SELECT 
                COUNT(CASE WHEN status = 'reported' THEN 1 END) as active_emergencies,
                COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
                COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_count,
                COUNT(CASE WHEN severity_level = 'high' THEN 1 END) as high_count,
                AVG(CASE WHEN resolution_time IS NOT NULL THEN resolution_time END) as avg_resolution_time,
                COUNT(*) as total_emergencies
            FROM emergency_dashboard_view WHERE estate_id IN ($placeholders)", $estateIds);
            
            // Get alerts statistics for allowed estates
            $alertsStats = $db->fetchOne("SELECT 
                COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_emergencies,
                COUNT(CASE WHEN status = 'responding' THEN 1 END) as in_progress,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_today,
                COUNT(CASE WHEN severity_level = 'critical' THEN 1 END) as critical_count,
                COUNT(CASE WHEN severity_level = 'high' THEN 1 END) as high_count,
                AVG(CASE WHEN resolution_time_seconds IS NOT NULL THEN resolution_time_seconds END) as avg_resolution_time,
                COUNT(*) as total_emergencies
            FROM emergency_alerts WHERE estate_id IN ($placeholders)", $estateIds);
            
            // Combine statistics
            return [
                'active_emergencies' => ($incidentsStats['active_emergencies'] ?? 0) + ($alertsStats['active_emergencies'] ?? 0),
                'in_progress' => ($incidentsStats['in_progress'] ?? 0) + ($alertsStats['in_progress'] ?? 0),
                'resolved_today' => ($incidentsStats['resolved_today'] ?? 0) + ($alertsStats['resolved_today'] ?? 0),
                'critical_count' => ($incidentsStats['critical_count'] ?? 0) + ($alertsStats['critical_count'] ?? 0),
                'high_count' => ($incidentsStats['high_count'] ?? 0) + ($alertsStats['high_count'] ?? 0),
                'avg_resolution_time' => calculateCombinedAverage([
                    $incidentsStats['avg_resolution_time'] ?? 0,
                    $alertsStats['avg_resolution_time'] ?? 0
                ]),
                'total_emergencies' => ($incidentsStats['total_emergencies'] ?? 0) + ($alertsStats['total_emergencies'] ?? 0)
            ];
        }
    }
    return [];
}

/**
 * Calculate combined average from multiple values
 */
function calculateCombinedAverage($values) {
    $validValues = array_filter($values, function($value) { return $value !== null && $value > 0; });
    if (empty($validValues)) return 0;
    return array_sum($validValues) / count($validValues);
}

/**
 * Get emergency data for dashboard display (both incidents and tenant alerts)
 */
function getEmergencyDashboardData($estateId) {
    $db = db();
    
    if (is_super_admin()) {
        // Get both emergency incidents and tenant alerts
        $incidents = $db->fetchAll("SELECT * FROM emergency_dashboard_view ORDER BY priority_score DESC, reported_at DESC LIMIT 50");
        $alerts = $db->fetchAll("SELECT 
            ea.id,
            ea.alert_type as incident_type,
            ea.severity_level,
            0 as priority_score,  -- emergency_alerts table doesn't have this column
            CONCAT(p.name, ' - Unit ', u.unit_number) as location,
            ea.description,
            ea.status,
            ea.reported_at,
            ea.acknowledged_at as response_started_at,
            ea.resolved_at,
            ea.estate_id,
            e.name as estate_name,
            t.emergency_contact_name as reporter_first,
            '' as reporter_last,
            ack_user.first_name as officer_first,
            ack_user.last_name as officer_last,
            sp.badge_number,
            NULL as first_response_time,
            ea.response_time_seconds as response_time,
            ea.resolution_time_seconds as resolution_time,
            0 as assignments_count,
            0 as contact_logs_count,
            NULL as affected_units,  -- emergency_alerts table doesn't have this column
            0 as evacuation_required,
            0 as police_report_filed,
            '' as police_report_number,
            0 as fire_department_notified,
            0 as ambulance_called,
            ea.resolution_notes as resolution_details,
            ea.acknowledged_by as last_updated_by,
            'tenant_alert' as source_type
        FROM emergency_alerts ea
        JOIN tenants t ON ea.tenant_id = t.id
        JOIN units u ON ea.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        JOIN estates e ON ea.estate_id = e.id
        LEFT JOIN users ack_user ON ea.acknowledged_by = ack_user.id
        LEFT JOIN security_personnel sp ON ack_user.id = sp.user_id
        ORDER BY ea.severity_level DESC, ea.reported_at DESC
        LIMIT 50");
        
        // Combine and sort both datasets
        $allEmergencies = array_merge($incidents, $alerts);
        usort($allEmergencies, function($a, $b) {
            // Sort by priority score (higher first) then by reported time (newer first)
            if ($a['priority_score'] != $b['priority_score']) {
                return $b['priority_score'] - $a['priority_score'];
            }
            return strtotime($b['reported_at']) - strtotime($a['reported_at']);
        });
        
        return array_slice($allEmergencies, 0, 50);
        
    } else {
        $estateIds = allowed_estate_ids();
        if ($estateIds) {
            $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
            
            // Get incidents for allowed estates
            $incidents = $db->fetchAll("SELECT * FROM emergency_dashboard_view WHERE estate_id IN ($placeholders) ORDER BY priority_score DESC, reported_at DESC LIMIT 50", $estateIds);
            
            // Get tenant alerts for allowed estates
            $alerts = $db->fetchAll("SELECT 
                ea.id,
                ea.alert_type as incident_type,
                ea.severity_level,
                0 as priority_score,  -- emergency_alerts table doesn't have this column
                CONCAT(p.name, ' - Unit ', u.unit_number) as location,
                ea.description,
                ea.status,
                ea.reported_at,
                ea.acknowledged_at as response_started_at,
                ea.resolved_at,
                ea.estate_id,
                e.name as estate_name,
                t.emergency_contact_name as reporter_first,
                '' as reporter_last,
                ack_user.first_name as officer_first,
                ack_user.last_name as officer_last,
                sp.badge_number,
                NULL as first_response_time,
                ea.response_time_seconds as response_time,
                ea.resolution_time_seconds as resolution_time,
                0 as assignments_count,
                0 as contact_logs_count,
                NULL as affected_units,  -- emergency_alerts table doesn't have this column
                0 as evacuation_required,
                0 as police_report_filed,
                '' as police_report_number,
                0 as fire_department_notified,
                0 as ambulance_called,
                ea.resolution_notes as resolution_details,
                ea.acknowledged_by as last_updated_by,
                'tenant_alert' as source_type
            FROM emergency_alerts ea
            JOIN tenants t ON ea.tenant_id = t.id
            JOIN units u ON ea.unit_id = u.id
            JOIN properties p ON u.property_id = p.id
            JOIN estates e ON ea.estate_id = e.id
            LEFT JOIN users ack_user ON ea.acknowledged_by = ack_user.id
            LEFT JOIN security_personnel sp ON ack_user.id = sp.user_id
            WHERE ea.estate_id IN ($placeholders)
            ORDER BY ea.severity_level DESC, ea.reported_at DESC
            LIMIT 50", $estateIds);
            
            // Combine and sort both datasets
            $allEmergencies = array_merge($incidents, $alerts);
            usort($allEmergencies, function($a, $b) {
                if ($a['priority_score'] != $b['priority_score']) {
                    return $b['priority_score'] - $a['priority_score'];
                }
                return strtotime($b['reported_at']) - strtotime($a['reported_at']);
            });
            
            return array_slice($allEmergencies, 0, 50);
        }
    }
    return [];
}

/**
 * Handle all form submissions
 */
function handleFormSubmissions() {
    if (!$_POST) return;
    
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_emergency':
            handleAddEmergency();
            break;
        case 'update_status':
            handleStatusUpdate();
            break;
        case 'assign_personnel':
            handlePersonnelAssignment();
            break;
        case 'add_progress_update':
            handleProgressUpdate();
            break;
        case 'contact_external_service':
            handleExternalContact();
            break;
        case 'quick_assign':
            handleQuickAssignment();
            break;
        case 'update_alert_status':
            handleAlertStatusUpdate();
            break;
    }
}

function handleAddEmergency() {
    // Existing add emergency logic
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
    
    $errors = [];
    if (empty($location)) $errors[] = 'Location is required';
    if (empty($description)) $errors[] = 'Description is required';
    
    if (empty($errors)) {
        try {
            $userId = current_user_id();
            $reportedAt = date('Y-m-d H:i:s');
            $estateId = $_POST['estate_id'] ?? 0;
            
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
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
    }
    
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

function handleStatusUpdate() {
    $incidentId = (int)($_POST['incident_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');
    $resolutionDetails = trim($_POST['resolution_details'] ?? '');
    
    if ($incidentId && $newStatus) {
        try {
            $updateFields = ['status' => $newStatus, 'last_updated_by' => current_user_id()];
            
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
            flash_set('error', 'Database error: ' . $e->getMessage());
        }
    }
    
    $estateId = $_POST['estate_id'] ?? 0;
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

function handlePersonnelAssignment() {
    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $personnelId = (int)($_POST['personnel_id'] ?? 0);
    $assignmentType = trim($_POST['assignment_type'] ?? 'primary');
    $notes = trim($_POST['assignment_notes'] ?? '');
    
    if ($emergencyId && $personnelId) {
        try {
            db()->insert(
                "INSERT INTO emergency_assignments (emergency_id, personnel_id, assigned_by, assignment_type, assignment_notes, status) 
                 VALUES (?, ?, ?, ?, ?, 'assigned')",
                [$emergencyId, $personnelId, current_user_id(), $assignmentType, $notes]
            );
            
            // Update emergency incident with assigned officer
            db()->execute(
                "UPDATE emergency_incidents SET security_officer_id = ?, last_updated_by = ? WHERE id = ?",
                [$personnelId, current_user_id(), $emergencyId]
            );
            
            flash_set('success', 'Security personnel assigned successfully');
        } catch (Exception $e) {
            flash_set('error', 'Assignment failed: ' . $e->getMessage());
        }
    }
    
    $estateId = $_POST['estate_id'] ?? 0;
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

function handleProgressUpdate() {
    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $updateText = trim($_POST['update_text'] ?? '');
    $updateType = trim($_POST['update_type'] ?? 'progress_note');
    
    if ($emergencyId && $updateText) {
        try {
            $userRole = $_SESSION['user_role'] ?? 'admin';
            db()->insert(
                "INSERT INTO emergency_progress_updates (emergency_id, user_id, user_role, status_update, update_type) 
                 VALUES (?, ?, ?, ?, ?)",
                [$emergencyId, current_user_id(), $userRole, $updateText, $updateType]
            );
            
            // Update last updated by
            db()->execute(
                "UPDATE emergency_incidents SET last_updated_by = ? WHERE id = ?",
                [current_user_id(), $emergencyId]
            );
            
            flash_set('success', 'Progress update added successfully');
        } catch (Exception $e) {
            flash_set('error', 'Update failed: ' . $e->getMessage());
        }
    }
    
    $estateId = $_POST['estate_id'] ?? 0;
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

function handleExternalContact() {
    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $contactType = trim($_POST['contact_type'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $notes = trim($_POST['contact_notes'] ?? '');
    
    if ($emergencyId && $contactType) {
        try {
            // Check if this is a tenant alert or an incident
            $db = db();
            
            // First, try to see if this ID exists in emergency_alerts
            $alertCheck = $db->fetchOne("SELECT id FROM emergency_alerts WHERE id = ?", [$emergencyId]);
            
            if ($alertCheck) {
                // This is a tenant alert, log with source_type 'alert'
                $db->insert(
                    "INSERT INTO emergency_contact_logs (emergency_id, source_type, contact_type, contacted_by, contact_number, contact_person, notes) 
                     VALUES (?, 'alert', ?, ?, ?, ?, ?)",
                    [$emergencyId, $contactType, current_user_id(), $contactNumber, $contactPerson, $notes]
                );
                
                flash_set('success', ucfirst($contactType) . ' contact logged for tenant alert');
            } else {
                // This is an emergency incident, log with source_type 'incident'
                $db->insert(
                    "INSERT INTO emergency_contact_logs (emergency_id, source_type, contact_type, contacted_by, contact_number, contact_person, notes) 
                     VALUES (?, 'incident', ?, ?, ?, ?, ?)",
                    [$emergencyId, $contactType, current_user_id(), $contactNumber, $contactPerson, $notes]
                );
                
                flash_set('success', ucfirst($contactType) . ' contact logged successfully');
            }
        } catch (Exception $e) {
            flash_set('error', 'Contact logging failed: ' . $e->getMessage());
        }
    }
    
    $estateId = $_POST['estate_id'] ?? 0;
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}

function handleQuickAssignment() {
    $emergencyId = (int)($_POST['emergency_id'] ?? 0);
    $personnelId = (int)($_POST['personnel_id'] ?? 0);
    
    if ($emergencyId && $personnelId) {
        try {
            // Check if already assigned
            $existing = db()->fetchOne(
                "SELECT id FROM emergency_assignments WHERE emergency_id = ? AND personnel_id = ? AND status IN ('assigned', 'accepted', 'in_progress')",
                [$emergencyId, $personnelId]
            );
            
            if (!$existing) {
                db()->insert(
                    "INSERT INTO emergency_assignments (emergency_id, personnel_id, assigned_by, assignment_type, status) 
                     VALUES (?, ?, ?, 'primary', 'assigned')",
                    [$emergencyId, $personnelId, current_user_id()]
                );
                
                // Update emergency incident
                db()->execute(
                    "UPDATE emergency_incidents SET security_officer_id = ?, last_updated_by = ? WHERE id = ?",
                    [$personnelId, current_user_id(), $emergencyId]
                );
            }
            
            echo json_encode(['success' => true, 'message' => 'Assigned successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

function handleAlertStatusUpdate() {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');
    
    if ($alertId && $newStatus) {
        try {
            $currentTime = date('Y-m-d H:i:s');
            $updateFields = ['status' => $newStatus];
            
            switch ($newStatus) {
                case 'acknowledged':
                    $updateFields['acknowledged_at'] = $currentTime;
                    $updateFields['acknowledged_by'] = current_user_id();
                    break;
                case 'responding':
                    $updateFields['acknowledged_at'] = $updateFields['acknowledged_at'] ?? $currentTime;
                    $updateFields['acknowledged_by'] = $updateFields['acknowledged_by'] ?? current_user_id();
                    $updateFields['responded_at'] = $currentTime;
                    $updateFields['responded_by'] = current_user_id();
                    break;
                case 'resolved':
                    $updateFields['resolved_at'] = $currentTime;
                    $updateFields['resolution_notes'] = 'Resolved by admin';
                    break;
            }
            
            $setClause = [];
            $values = [];
            foreach ($updateFields as $field => $value) {
            $setClause[] = "$field = ?";
            $values[] = $value;
            }
            
            $values[] = $alertId;
            $sql = "UPDATE emergency_alerts SET " . implode(', ', $setClause) . " WHERE id = ?";
            
            db()->execute($sql, $values);
            flash_set('success', 'Tenant alert status updated successfully');
        } catch (Exception $e) {
            flash_set('error', 'Status update failed: ' . $e->getMessage());
        }
    }
    
    $estateId = $_POST['estate_id'] ?? 0;
    redirect($_SERVER['PHP_SELF'] . ($estateId ? '?estate_id=' . $estateId : ''));
}




?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Emergency Command Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="../../assets/css/style.bundle.css">
    <link rel="stylesheet" href="../../assets/plugins/custom/datatables/datatables.bundle.css">
    <style>
        :root {
            --critical-color: #dc3545;
            --high-color: #fd7e14;
            --medium-color: #ffc107;
            --low-color: #28a745;
        }
        
        .emergency-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
            margin-bottom: 1rem;
        }
        
        .priority-critical {
            background-color: #fff5f5;
            border-left-color: var(--critical-color);
            box-shadow: 0 0 15px rgba(220, 53, 69, 0.2);
        }
        
        .priority-high {
            background-color: #fff9f5;
            border-left-color: var(--high-color);
            box-shadow: 0 0 10px rgba(253, 126, 20, 0.2);
        }
        
        .priority-medium {
            background-color: #fffdf5;
            border-left-color: var(--medium-color);
        }
        
        .priority-low {
            background-color: #f5fff5;
            border-left-color: var(--low-color);
        }
        
        .emergency-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .priority-badge {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.4em 0.8em;
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 0.3em 0.6em;
        }
        
        .quick-action-btn {
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            transform: scale(1.05);
        }
        
        .stats-card {
            border-radius: 12px;
            overflow: hidden;
        }
        
        .progress-update {
            border-left: 3px solid #007bff;
            padding-left: 15px;
            margin: 10px 0;
        }
        
        .external-service-btn {
            transition: all 0.3s;
        }
        
        .external-service-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .assignment-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #007bff;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/partials/top.php'; ?>

    <div class="container-fluid">
        <!-- Dashboard Header -->
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h2 class="mb-2">
                    <i class="ki-duotone ki-siren text-danger fs-1 me-2">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    Emergency Command Dashboard
                </h2>
                <p class="text-muted mb-0">Real-time emergency monitoring and management system</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-light-primary" id="refreshDashboard">
                    <i class="ki-duotone ki-refresh fs-2"></i>
                    Refresh
                </button>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newEmergencyModal">
                    <i class="ki-duotone ki-plus fs-2"></i>
                    New Emergency
                </button>
            </div>
        </div>

        <!-- Statistics Overview -->
        <div class="row mb-8">
            <div class="col-md-3">
                <div class="card stats-card bg-light-danger border-start border-4 border-danger">
                    <div class="card-body text-center py-4">
                        <div class="fs-2hx fw-bold text-danger"><?= $stats['active_emergencies'] ?? 0 ?></div>
                        <div class="text-muted fs-6">Active Emergencies</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card bg-light-warning border-start border-4 border-warning">
                    <div class="card-body text-center py-4">
                        <div class="fs-2hx fw-bold text-warning"><?= $stats['in_progress'] ?? 0 ?></div>
                        <div class="text-muted fs-6">In Progress</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card bg-light-success border-start border-4 border-success">
                    <div class="card-body text-center py-4">
                        <div class="fs-2hx fw-bold text-success"><?= $stats['resolved_today'] ?? 0 ?></div>
                        <div class="text-muted fs-6">Resolved Today</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card bg-light-info border-start border-4 border-info">
                    <div class="card-body text-center py-4">
                        <div class="fs-2hx fw-bold text-info">
                            <?php if ($stats['avg_resolution_time'] ?? 0): ?>
                                <?= gmdate('H:i:s', (int)($stats['avg_resolution_time'] / 1000)) ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </div>
                        <div class="text-muted fs-6">Avg Response Time</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- Emergency List as Dashboard Cards -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">Active Emergencies</h3>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm w-150px" id="priorityFilter">
                                <option value="">All Priorities</option>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>
                            <select class="form-select form-select-sm w-150px" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="reported">Reported</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($emergencies)): ?>
                            <div class="text-center py-10">
                                <i class="ki-duotone ki-shield-tick fs-5x text-success mb-4">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                                <h4 class="text-success">No Active Emergencies</h4>
                                <p class="text-muted">All systems are currently stable</p>
                            </div>
                        <?php else: ?>
                            <div class="row" id="emergencyCardsContainer">
                                <?php foreach ($emergencies as $emergency): ?>
                                    <div class="col-12 mb-4 emergency-card priority-<?= e($emergency['severity_level']) ?>" 
                                         data-priority="<?= e($emergency['severity_level']) ?>" 
                                         data-status="<?= e($emergency['status']) ?>">
                                        <div class="card h-100 position-relative">
                                            <?php if ($emergency['assignments_count'] > 0): ?>
                                                <div class="assignment-badge">
                                                    <?= (int)$emergency['assignments_count'] ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div>
                                                        <h5 class="mb-1">
                                                            <?= e(ucfirst(str_replace('_', ' ', $emergency['incident_type']))) ?>
                                                            <?php if (isset($emergency['source_type']) && $emergency['source_type'] === 'tenant_alert'): ?>
                                                                <span class="badge bg-primary ms-2">TENANT ALERT</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary ms-2">INCIDENT</span>
                                                            <?php endif; ?>
                                                            <span class="badge priority-badge bg-
                                                                <?php 
                                                                if ($emergency['severity_level'] === 'critical') echo 'danger';
                                                                elseif ($emergency['severity_level'] === 'high') echo 'warning';
                                                                elseif ($emergency['severity_level'] === 'medium') echo 'info';
                                                                else echo 'success';
                                                                ?>
                                                            ">
                                                                <?= e(ucfirst($emergency['severity_level'])) ?>
                                                            </span>
                                                        </h5>
                                                        <div class="text-muted small">
                                                            <i class="ki-duotone ki-geolocation text-danger me-1">
                                                                <span class="path1"></span>
                                                                <span class="path2"></span>
                                                            </i>
                                                            <?= e($emergency['location']) ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <span class="badge status-badge bg-
                                                            <?php 
                                                            if ($emergency['status'] === 'reported') echo 'warning';
                                                            elseif ($emergency['status'] === 'in_progress') echo 'primary';
                                                            elseif ($emergency['status'] === 'resolved') echo 'success';
                                                            else echo 'secondary';
                                                            ?>
                                                        ">
                                                            <?= e(ucfirst(str_replace('_', ' ', $emergency['status']))) ?>
                                                        </span>
                                                        <div class="text-muted small mt-1">
                                                            <?= date('M j, g:i A', strtotime($emergency['reported_at'])) ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <p class="mb-3"><?= e($emergency['description']) ?></p>

                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <div class="d-flex gap-2">
                                                        <?php if ($emergency['officer_first']): ?>
                                                            <span class="badge bg-primary">
                                                                <?= e($emergency['officer_first'] . ' ' . $emergency['officer_last']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($emergency['evacuation_required']): ?>
                                                            <span class="badge bg-danger">Evacuation</span>
                                                        <?php endif; ?>
                                                        <?php if ($emergency['police_report_filed']): ?>
                                                            <span class="badge bg-dark">Police Report</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-muted small">
                                                        Priority Score: <?= (int)$emergency['priority_score'] ?>
                                                    </div>
                                                </div>

                                                <!-- Quick Actions -->
                                                <div class="d-flex flex-wrap gap-2 mb-3">
                                                    <button class="btn btn-sm btn-light-primary quick-action-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#detailsModal" 
                                                            data-emergency='<?= json_encode($emergency) ?>'>
                                                        <i class="ki-duotone ki-eye fs-2"></i>
                                                        View Details
                                                    </button>
                                                    
                                                    <?php if (isset($emergency['source_type']) && $emergency['source_type'] === 'tenant_alert'): ?>
                                                        <!-- Tenant Alert Actions -->
                                                        <?php if (in_array($emergency['status'], ['reported', 'acknowledged'])): ?>
                                                            <button class="btn btn-sm btn-warning quick-action-btn" 
                                                                    onclick="updateAlertStatus(<?= (int)$emergency['id'] ?>, 'responding')">
                                                                <i class="ki-duotone ki-location fs-2"></i>
                                                                Mark Responding
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($emergency['status'] === 'responding'): ?>
                                                            <button class="btn btn-sm btn-success quick-action-btn" 
                                                                    onclick="updateAlertStatus(<?= (int)$emergency['id'] ?>, 'resolved')">
                                                                <i class="ki-duotone ki-check-circle fs-2"></i>
                                                                Mark Resolved
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <!-- Incident Actions -->
                                                        <?php if ($emergency['status'] === 'reported'): ?>
                                                            <button class="btn btn-sm btn-warning quick-action-btn" 
                                                                    onclick="updateStatus(<?= (int)$emergency['id'] ?>, 'in_progress')">
                                                                <i class="ki-duotone ki-location fs-2"></i>
                                                                Mark In Progress
                                                            </button>
                                                        <?php elseif ($emergency['status'] === 'in_progress'): ?>
                                                            <button class="btn btn-sm btn-success quick-action-btn" 
                                                                    onclick="updateStatus(<?= (int)$emergency['id'] ?>, 'resolved')">
                                                                <i class="ki-duotone ki-check-circle fs-2"></i>
                                                                Mark Resolved
                                                            </button>
                                                        <?php endif; ?>

                                                        <?php if (!$emergency['officer_first'] && !empty($securityPersonnel)): ?>
                                                            <div class="dropdown">
                                                                <button class="btn btn-sm btn-light-info dropdown-toggle quick-action-btn" 
                                                                        type="button" 
                                                                        data-bs-toggle="dropdown">
                                                                    <i class="ki-duotone ki-user fs-2"></i>
                                                                    Assign Officer
                                                                </button>
                                                                <ul class="dropdown-menu">
                                                                    <?php foreach ($securityPersonnel as $officer): ?>
                                                                        <li>
                                                                            <a class="dropdown-item" 
                                                                               href="#" 
                                                                               onclick="assignPersonnel(<?= (int)$emergency['id'] ?>, <?= (int)$officer['id'] ?>)">
                                                                                <?= e($officer['first_name'] . ' ' . $officer['last_name']) ?>
                                                                                <span class="badge bg-secondary ms-2">
                                                                                    <?= e($officer['badge_number']) ?>
                                                                                </span>
                                                                            </a>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <button class="btn btn-sm btn-light-success quick-action-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#progressUpdateModal" 
                                                            data-emergency-id="<?= (int)$emergency['id'] ?>">
                                                        <i class="ki-duotone ki-message-text fs-2"></i>
                                                        Add Update
                                                    </button>
                                                </div>

                                                <!-- External Services Quick Access -->
                                                <div class="d-flex gap-2 flex-wrap">
                                                    <button class="btn btn-sm btn-light-danger external-service-btn" 
                                                            onclick="callEmergencyService(<?= (int)$emergency['id'] ?>, 'police')">
                                                        <i class="ki-duotone ki-call fs-2"></i>
                                                        Police
                                                    </button>
                                                    <button class="btn btn-sm btn-light-warning external-service-btn" 
                                                            onclick="callEmergencyService(<?= (int)$emergency['id'] ?>, 'fire_department')">
                                                        <i class="ki-duotone ki-fire fs-2"></i>
                                                        Fire Dept
                                                    </button>
                                                    <button class="btn btn-sm btn-light-primary external-service-btn" 
                                                            onclick="callEmergencyService(<?= (int)$emergency['id'] ?>, 'ambulance')">
                                                        <i class="ki-duotone ki-medical fs-2"></i>
                                                        Ambulance
                                                    </button>
                                                    <button class="btn btn-sm btn-light-info external-service-btn" 
                                                            onclick="callEmergencyService(<?= (int)$emergency['id'] ?>, 'hospital')">
                                                        <i class="ki-duotone ki-hospital fs-2"></i>
                                                        Hospital
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
            <div class="col-lg-4">
                <!-- Quick Assignment Panel -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Quick Assignment</h4>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label">Available Security Personnel</label>
                            <div class="list-group">
                                <?php foreach ($securityPersonnel as $personnel): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= e($personnel['first_name'] . ' ' . $personnel['last_name']) ?></strong>
                                            <div class="text-muted small">Badge: <?= e($personnel['badge_number']) ?></div>
                                            <div class="text-muted small">Role: <?= e(ucfirst($personnel['role_level'])) ?></div>
                                        </div>
                                        <span class="badge bg-success">Available</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact Groups -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Emergency Contacts</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($contactGroups)): ?>
                            <?php foreach ($contactGroups as $group): ?>
                                <div class="mb-3 p-3 border rounded">
                                    <h6 class="mb-2"><?= e($group['group_name']) ?></h6>
                                    <div class="text-muted small mb-2">Type: <?= e(ucfirst(str_replace('_', ' ', $group['group_type']))) ?></div>
                                    <?php 
                                    $numbers = json_decode($group['contact_numbers'], true);
                                    if (!empty($numbers)): 
                                    ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($numbers as $number): ?>
                                                <a href="tel:<?= e($number) ?>" class="btn btn-sm btn-light-primary">
                                                    <i class="ki-duotone ki-call fs-2"></i>
                                                    <?= e($number) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No emergency contact groups configured</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Recent Activity</h4>
                    </div>
                    <div class="card-body">
                        <div class="timeline timeline-border-dashed">
                            <div class="timeline-item">
                                <div class="timeline-line"></div>
                                <div class="timeline-icon">
                                    <i class="ki-duotone ki-siren text-danger fs-2"></i>
                                </div>
                                <div class="timeline-content mb-10">
                                    <div class="fw-bold">System Initialized</div>
                                    <div class="text-muted">Emergency dashboard is now active</div>
                                    <div class="text-muted small">Just now</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Emergency Modal -->
    <div class="modal fade" id="newEmergencyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_emergency">
                    <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Report New Emergency</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Incident Type *</label>
                                    <select class="form-select" name="incident_type" required>
                                        <option value="fire">Fire</option>
                                        <option value="medical">Medical Emergency</option>
                                        <option value="break_in">Break-in/Theft</option>
                                        <option value="disturbance">Disturbance/Fight</option>
                                        <option value="accident">Accident</option>
                                        <option value="natural_disaster">Natural Disaster</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Severity Level *</label>
                                    <select class="form-select" name="severity_level" required>
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" class="form-control" name="location" required placeholder="Enter specific location">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea class="form-control" name="description" rows="4" required placeholder="Detailed description of the incident"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Assign Security Officer</label>
                                    <select class="form-select" name="security_officer_id">
                                        <option value="">Select Officer (Optional)</option>
                                        <?php foreach ($securityPersonnel as $officer): ?>
                                            <option value="<?= (int)$officer['id'] ?>">
                                                <?= e($officer['first_name'] . ' ' . $officer['last_name']) ?> (<?= e($officer['badge_number']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Affected Units</label>
                                    <input type="text" class="form-control" name="affected_units" placeholder="Unit numbers, comma separated">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="evacuation_required" id="evacuation_required">
                                    <label class="form-check-label" for="evacuation_required">Evacuation Required</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="police_report_filed" id="police_report_filed">
                                    <label class="form-check-label" for="police_report_filed">Police Report Filed</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="fire_department_notified" id="fire_department_notified">
                                    <label class="form-check-label" for="fire_department_notified">Fire Department Notified</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="ambulance_called" id="ambulance_called">
                                    <label class="form-check-label" for="ambulance_called">Ambulance Called</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Police Report Number</label>
                            <input type="text" class="form-control" name="police_report_number" placeholder="If police report was filed">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="ki-duotone ki-siren fs-2 me-2"></i>
                            Report Emergency
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Emergency Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="emergencyDetailsContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Update Modal -->
    <div class="modal fade" id="progressUpdateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_progress_update">
                    <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                    <input type="hidden" name="emergency_id" id="progressEmergencyId">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">Add Progress Update</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Update Type</label>
                            <select class="form-select" name="update_type">
                                <option value="progress_note">Progress Note</option>
                                <option value="status_change">Status Change</option>
                                <option value="resolution_update">Resolution Update</option>
                                <option value="external_contact">External Contact</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Update Message *</label>
                            <textarea class="form-control" name="update_text" rows="4" required placeholder="Enter progress update details"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../../assets/plugins/global/plugins.bundle.js"></script>
    <script src="../../assets/js/scripts.bundle.js"></script>
    <script>
        // Auto-refresh dashboard every 30 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                fetch('?ajax=dashboard_data&estate_id=<?= $estateId ?>')
                    .then(response => response.json())
                    .then(data => {
                        // Update dashboard with new data
                        console.log('Dashboard refreshed');
                    })
                    .catch(error => console.error('Refresh error:', error));
            }
        }, 30000);

        // Handle refresh button
        document.getElementById('refreshDashboard').addEventListener('click', function() {
            location.reload();
        });

        // Filter functionality
        document.getElementById('priorityFilter').addEventListener('change', filterEmergencies);
        document.getElementById('statusFilter').addEventListener('change', filterEmergencies);

        function filterEmergencies() {
            const priorityFilter = document.getElementById('priorityFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const cards = document.querySelectorAll('.emergency-card');
            
            cards.forEach(card => {
                const cardPriority = card.dataset.priority;
                const cardStatus = card.dataset.status;
                
                const priorityMatch = !priorityFilter || cardPriority === priorityFilter;
                const statusMatch = !statusFilter || cardStatus === statusFilter;
                
                card.style.display = priorityMatch && statusMatch ? 'block' : 'none';
            });
        }

        // Handle details modal
        const detailsModal = document.getElementById('detailsModal');
        detailsModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const emergency = JSON.parse(button.getAttribute('data-emergency'));
            const modalBody = document.getElementById('emergencyDetailsContent');
            
            modalBody.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Emergency Information</h6>
                        <table class="table table-sm">
                            <tr><td><strong>ID:</strong></td><td>${emergency.id}</td></tr>
                            <tr><td><strong>Type:</strong></td><td>${emergency.incident_type.replace(/_/g, ' ')}</td></tr>
                            <tr><td><strong>Severity:</strong></td><td><span class="badge bg-${emergency.severity_level === 'critical' ? 'danger' : emergency.severity_level === 'high' ? 'warning' : emergency.severity_level === 'medium' ? 'info' : 'success'}">${emergency.severity_level}</span></td></tr>
                            <tr><td><strong>Location:</strong></td><td>${emergency.location}</td></tr>
                            <tr><td><strong>Estate:</strong></td><td>${emergency.estate_name}</td></tr>
                            <tr><td><strong>Priority Score:</strong></td><td>${emergency.priority_score}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Status & Timeline</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Status:</strong></td><td><span class="badge bg-${emergency.status === 'reported' ? 'warning' : emergency.status === 'in_progress' ? 'primary' : 'success'}">${emergency.status.replace(/_/g, ' ')}</span></td></tr>
                            <tr><td><strong>Reported:</strong></td><td>${new Date(emergency.reported_at).toLocaleString()}</td></tr>
                            <tr><td><strong>Response Started:</strong></td><td>${emergency.response_started_at ? new Date(emergency.response_started_at).toLocaleString() : 'Not started'}</td></tr>
                            <tr><td><strong>Resolved:</strong></td><td>${emergency.resolved_at ? new Date(emergency.resolved_at).toLocaleString() : 'Not resolved'}</td></tr>
                            <tr><td><strong>Assignments:</strong></td><td>${emergency.assignments_count} personnel</td></tr>
                            <tr><td><strong>External Contacts:</strong></td><td>${emergency.contact_logs_count} contacts</td></tr>
                        </table>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-12">
                        <h6>Description</h6>
                        <p class="bg-light p-3 rounded">${emergency.description}</p>
                        
                        <h6>Additional Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <ul class="list-unstyled">
                                    <li><strong>Reported by:</strong> ${emergency.reporter_first} ${emergency.reporter_last}</li>
                                    <li><strong>Assigned Officer:</strong> ${emergency.officer_first ? emergency.officer_first + ' ' + emergency.officer_last + ' (Badge: ' + emergency.badge_number + ')' : 'None'}</li>
                                    <li><strong>Affected Units:</strong> ${emergency.affected_units || 'None'}</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul class="list-unstyled">
                                    <li><strong>Evacuation Required:</strong> ${emergency.evacuation_required ? 'Yes' : 'No'}</li>
                                    <li><strong>Police Report:</strong> ${emergency.police_report_filed ? 'Yes (#' + emergency.police_report_number + ')' : 'No'}</li>
                                    <li><strong>Fire Department:</strong> ${emergency.fire_department_notified ? 'Notified' : 'Not contacted'}</li>
                                    <li><strong>Ambulance:</strong> ${emergency.ambulance_called ? 'Called' : 'Not called'}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });

        // Handle progress update modal
        const progressModal = document.getElementById('progressUpdateModal');
        progressModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const emergencyId = button.getAttribute('data-emergency-id');
            document.getElementById('progressEmergencyId').value = emergencyId;
        });

        // Quick status update for incidents
        function updateStatus(emergencyId, newStatus) {
            if (confirm(`Are you sure you want to mark this emergency as ${newStatus.replace(/_/g, ' ')}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                    <input type="hidden" name="incident_id" value="${emergencyId}">
                    <input type="hidden" name="new_status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Quick status update for tenant alerts
        function updateAlertStatus(alertId, newStatus) {
            if (confirm(`Are you sure you want to mark this tenant alert as ${newStatus.replace(/_/g, ' ')}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_alert_status">
                    <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                    <input type="hidden" name="alert_id" value="${alertId}">
                    <input type="hidden" name="new_status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Quick personnel assignment
        function assignPersonnel(emergencyId, personnelId) {
            if (confirm('Assign this security personnel to the emergency?')) {
                const formData = new FormData();
                formData.append('action', 'quick_assign');
                formData.append('emergency_id', emergencyId);
                formData.append('personnel_id', personnelId);
                formData.append('csrf_token', '<?= csrf_token() ?>');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Assignment failed: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Assignment failed');
                });
            }
        }

        // Call emergency services
        function callEmergencyService(emergencyId, serviceType) {
            let serviceNames = {
                'police': 'Police',
                'fire_department': 'Fire Department',
                'ambulance': 'Ambulance',
                'hospital': 'Hospital'
            };
            
            if (confirm(`Log contact with ${serviceNames[serviceType]} for this emergency?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="contact_external_service">
                    <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                    <input type="hidden" name="emergency_id" value="${emergencyId}">
                    <input type="hidden" name="contact_type" value="${serviceType}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>

<?php require __DIR__ . '/partials/bottom.php'; ?>