<?php
/**
 * Professional Emergency Alert System
 * Enhanced version with audible alerts, escalation protocols, and enterprise features
 */

class EmergencyAlertSystem {
    private $db;
    
    public function __construct() {
        $this->db = db();
    }
    
    /**
     * Create professional emergency alert with escalation
     */
    public function createEmergencyAlert(array $data): array {
        try {
            // Validate required fields
            $required = ['tenant_id', 'estate_id', 'unit_id', 'alert_type', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // Generate professional alert number
            $alertNumber = $this->generateAlertNumber();
            
            // Determine severity level
            $severity = $this->calculateSeverity($data['alert_type'], $data['description']);
            
            // Auto-detect tenant location
            $location = $this->getTenantLocation($data['unit_id']);
            
            // Insert emergency alert
            $alertId = $this->db->insert(
                "INSERT INTO emergency_alerts 
                 (alert_number, tenant_id, estate_id, unit_id, alert_type, severity_level, 
                  description, location, is_silent, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported', NOW())",
                [
                    $alertNumber,
                    $data['tenant_id'],
                    $data['estate_id'],
                    $data['unit_id'],
                    $data['alert_type'],
                    $severity,
                    $data['description'],
                    $location,
                    $data['is_silent'] ?? 0
                ]
            );
            
            if (!$alertId) {
                throw new Exception("Failed to create emergency alert");
            }
            
            // Professional response: Notify all channels
            $this->triggerProfessionalAlertResponse($alertId, $data);
            
            // Log the alert creation
            $this->logEmergencyActivity($alertId, 'created', $data['tenant_id']);
            
            return [
                'success' => true,
                'alert_id' => $alertId,
                'alert_number' => $alertNumber,
                'severity' => $severity,
                'message' => 'Emergency alert submitted successfully'
            ];
            
        } catch (Exception $e) {
            error_log('Emergency Alert Creation Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Professional alert notification system
     */
    private function triggerProfessionalAlertResponse(int $alertId, array $alertData): void {
        // Get alert details
        $alert = $this->getAlertDetails($alertId);
        
        // Multi-channel notification system
        $this->notifySecurityPersonnel($alert);
        $this->notifyEstateAdmins($alert);
        $this->sendSmsAlerts($alert);
        $this->sendEmailAlerts($alert);
        $this->triggerAudibleAlerts($alert);
        $this->initiateEscalationProtocol($alert);
    }
    
    /**
     * Notify all security personnel with priority levels
     */
    private function notifySecurityPersonnel(array $alert): void {
        $securityPersonnel = $this->db->fetchAll(
            "SELECT sp.user_id, u.first_name, u.last_name, u.email, u.phone, sp.role_level
             FROM security_personnel sp
             JOIN users u ON sp.user_id = u.id
             WHERE sp.estate_id = ? AND sp.status = 'active'
             ORDER BY sp.role_level DESC, sp.on_duty DESC",
            [$alert['estate_id']]
        );
        
        foreach ($securityPersonnel as $personnel) {
            // Create priority-based notification
            $priority = $this->determineNotificationPriority($alert, $personnel);
            
            // In-app notification
            $this->createPriorityNotification(
                $personnel['user_id'],
                'emergency_alert',
                $this->generateAlertTitle($alert),
                $this->generateAlertMessage($alert),
                '../security/emergency_response.php?alert_id=' . $alert['id'],
                $priority
            );
        }
    }
    
    /**
     * Audible alert system for immediate attention
     */
    private function triggerAudibleAlerts(array $alert): void {
        if ($alert['is_silent']) {
            return; // Skip audible alerts for silent emergencies
        }
        
        // Store audible alert data for frontend playback
        $audibleAlert = [
            'alert_id' => $alert['id'],
            'alert_number' => $alert['alert_number'],
            'severity' => $alert['severity_level'],
            'type' => $alert['alert_type'],
            'timestamp' => time(),
            'estate_id' => $alert['estate_id']
        ];
        
        // Save to database for real-time polling
        $this->db->insert(
            "INSERT INTO emergency_audible_alerts (alert_id, alert_data, estate_id, created_at)
             VALUES (?, ?, ?, NOW())",
            [$alert['id'], json_encode($audibleAlert), $alert['estate_id']]
        );
    }
    
    /**
     * Escalation protocol for unresponsive emergencies
     */
    private function initiateEscalationProtocol(array $alert): void {
        // Set escalation timer based on severity
        $escalationTime = $this->getEscalationTime($alert['severity_level']);
        
        $this->db->insert(
            "INSERT INTO emergency_escalations 
             (alert_id, escalation_level, trigger_time, created_at)
             VALUES (?, 'level_1', DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())",
            [$alert['id'], $escalationTime]
        );
    }
    
    /**
     * Utility functions
     */
    private function generateAlertNumber(): string {
        return 'EMER-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
    
    private function calculateSeverity(string $alertType, string $description): string {
        $criticalTypes = ['medical', 'fire', 'security_breach'];
        $highTypes = ['theft', 'assault'];
        
        if (in_array($alertType, $criticalTypes)) {
            return 'critical';
        } elseif (in_array($alertType, $highTypes)) {
            return 'high';
        } elseif (stripos($description, 'urgent') !== false || stripos($description, 'immediate') !== false) {
            return 'high';
        }
        
        return 'medium';
    }
    
    private function getTenantLocation(int $unitId): string {
        $unit = $this->db->fetchOne(
            "SELECT u.unit_number, p.name as property_name, e.name as estate_name
             FROM units u
             JOIN properties p ON u.property_id = p.id
             JOIN estates e ON u.estate_id = e.id
             WHERE u.id = ?",
            [$unitId]
        );
        
        return $unit ? 
            $unit['property_name'] . ' - ' . $unit['unit_number'] . ' (' . $unit['estate_name'] . ')' : 
            'Unknown Location';
    }
    
    private function getAlertDetails(int $alertId): array {
        return $this->db->fetchOne(
            "SELECT ea.*, t.emergency_contact_name, t.emergency_contact_phone as tenant_phone
             FROM emergency_alerts ea
             JOIN tenants t ON ea.tenant_id = t.id
             WHERE ea.id = ?",
            [$alertId]
        );
    }
    
    private function getEscalationTime(string $severity): int {
        $times = [
            'critical' => 120,  // 2 minutes
            'high' => 300,      // 5 minutes
            'medium' => 600,    // 10 minutes
            'low' => 1800       // 30 minutes
        ];
        return $times[$severity] ?? 600;
    }
    
    private function determineNotificationPriority(array $alert, array $personnel): string {
        if ($alert['severity_level'] === 'critical') return 'urgent';
        if ($personnel['role_level'] === 'supervisor') return 'high';
        return 'normal';
    }
    
    private function generateAlertTitle(array $alert): string {
        $type = ucfirst(str_replace('_', ' ', $alert['alert_type']));
        $severity = ucfirst($alert['severity_level']);
        return "🚨 $severity EMERGENCY: $type - {$alert['alert_number']}";
    }
    
    private function generateAlertMessage(array $alert): string {
        return "Emergency reported by {$alert['emergency_contact_name']} at {$alert['location']}: {$alert['description']}";
    }
    
    private function createPriorityNotification(int $userId, string $type, string $title, string $message, string $link, string $priority): void {
        $this->db->insert(
            "INSERT INTO notifications (user_id, type, title, body, link, priority, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $type, $title, $message, $link, $priority]
        );
    }
    
    private function notifyEstateAdmins(array $alert): void {
        // Implementation for admin notifications
        $admins = $this->db->fetchAll(
            "SELECT u.id, u.first_name, u.last_name
             FROM users u
             JOIN user_estates ue ON u.id = ue.user_id
             WHERE ue.estate_id = ? AND u.role IN ('estate_admin', 'property_manager', 'super_admin')",
            [$alert['estate_id']]
        );
        
        foreach ($admins as $admin) {
            $this->createPriorityNotification(
                $admin['id'],
                'emergency_alert',
                $this->generateAlertTitle($alert),
                $this->generateAlertMessage($alert),
                '../admin/emergency_incidents.php',
                'high'
            );
        }
    }
    
    private function sendSmsAlerts(array $alert): void {
        // SMS integration placeholder
        // In production: integrate with SMS gateway like Twilio
        error_log("SMS Alert: " . $this->generateAlertMessage($alert));
    }
    
    private function sendEmailAlerts(array $alert): void {
        // Email integration placeholder
        // In production: integrate with email service
        error_log("Email Alert: " . $this->generateAlertMessage($alert));
    }
    
    private function logEmergencyActivity(int $alertId, string $activity, int $userId): void {
        $this->db->insert(
            "INSERT INTO emergency_activity_log (alert_id, activity, user_id, created_at)
             VALUES (?, ?, ?, NOW())",
            [$alertId, $activity, $userId]
        );
    }
}

// Initialize the professional emergency system
$emergencySystem = new EmergencyAlertSystem();