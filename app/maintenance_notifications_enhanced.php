<?php
/**
 * Enhanced Maintenance Notification System
 * Professional notification handling for estate administrators
 */

/**
 * Notify admin about new quotation
 * 
 * @param int $ticketId
 * @param int $estateId
 * @param string $vendorName
 * @return void
 */
function notify_admin_new_quotation(int $ticketId, int $estateId, string $vendorName): void {
    try {
        $db = db();
        
        // Get ticket details
        $ticket = $db->fetchOne(
            "SELECT ticket_number, title FROM maintenance_tickets WHERE id = ?",
            [$ticketId]
        );
        
        if (!$ticket) return;
        
        $title = "New Quotation Submitted";
        $message = "Vendor {$vendorName} has submitted a quotation for ticket {$ticket['ticket_number']} - {$ticket['title']}";
        
        // Get estate admins
        $admins = $db->fetchAll(
            "SELECT u.id FROM users u 
             INNER JOIN user_estate_access uea ON u.id = uea.user_id 
             WHERE uea.estate_id = ? AND u.role IN ('estate_admin', 'property_manager')",
            [$estateId]
        );
        
        foreach ($admins as $admin) {
            create_maintenance_notification(
                $ticketId,
                (int)$admin['id'],
                'quotation_submitted',
                $title,
                $message
            );
        }
        
    } catch (Throwable $e) {
        error_log('Failed to notify admin about new quotation: ' . $e->getMessage());
    }
}

/**
 * Notify admin about quotation approval/rejection
 * 
 * @param int $ticketId
 * @param int $estateId
 * @param string $status
 * @param string $approverName
 * @return void
 */
function notify_admin_quotation_decision(int $ticketId, int $estateId, string $status, string $approverName): void {
    try {
        $db = db();
        
        $ticket = $db->fetchOne(
            "SELECT ticket_number, title FROM maintenance_tickets WHERE id = ?",
            [$ticketId]
        );
        
        if (!$ticket) return;
        
        $title = "Quotation " . ucfirst($status);
        $message = "{$approverName} has {$status} the quotation for ticket {$ticket['ticket_number']} - {$ticket['title']}";
        
        // Get estate admins
        $admins = $db->fetchAll(
            "SELECT u.id FROM users u 
             INNER JOIN user_estate_access uea ON u.id = uea.user_id 
             WHERE uea.estate_id = ? AND u.role IN ('estate_admin', 'property_manager')",
            [$estateId]
        );
        
        foreach ($admins as $admin) {
            create_maintenance_notification(
                $ticketId,
                (int)$admin['id'],
                'quotation_' . $status,
                $title,
                $message
            );
        }
        
    } catch (Throwable $e) {
        error_log('Failed to notify admin about quotation decision: ' . $e->getMessage());
    }
}

/**
 * Notify admin about work completion
 * 
 * @param int $ticketId
 * @param int $estateId
 * @param string $vendorName
 * @return void
 */
function notify_admin_work_completion(int $ticketId, int $estateId, string $vendorName): void {
    try {
        $db = db();
        
        $ticket = $db->fetchOne(
            "SELECT ticket_number, title FROM maintenance_tickets WHERE id = ?",
            [$ticketId]
        );
        
        if (!$ticket) return;
        
        $title = "Work Completion Notification";
        $message = "Vendor {$vendorName} has marked ticket {$ticket['ticket_number']} - {$ticket['title']} as completed";
        
        // Get estate admins
        $admins = $db->fetchAll(
            "SELECT u.id FROM users u 
             INNER JOIN user_estate_access uea ON u.id = uea.user_id 
             WHERE uea.estate_id = ? AND u.role IN ('estate_admin', 'property_manager')",
            [$estateId]
        );
        
        foreach ($admins as $admin) {
            create_maintenance_notification(
                $ticketId,
                (int)$admin['id'],
                'work_completed',
                $title,
                $message
            );
        }
        
    } catch (Throwable $e) {
        error_log('Failed to notify admin about work completion: ' . $e->getMessage());
    }
}

/**
 * Notify admin about overdue tickets
 * 
 * @param int $estateId
 * @return void
 */
function notify_admin_overdue_tickets(int $estateId): void {
    try {
        $db = db();
        
        $overdueTickets = $db->fetchAll(
            "SELECT id, ticket_number, title, expected_completion_date 
             FROM maintenance_tickets 
             WHERE estate_id = ? 
             AND expected_completion_date < NOW() 
             AND status NOT IN ('closed', 'cancelled') 
             AND notified_overdue = 0 
             LIMIT 10",
            [$estateId]
        );
        
        if (empty($overdueTickets)) return;
        
        $title = "Overdue Maintenance Tickets";
        $message = "You have " . count($overdueTickets) . " overdue maintenance tickets requiring attention";
        
        // Get estate admins
        $admins = $db->fetchAll(
            "SELECT u.id FROM users u 
             INNER JOIN user_estate_access uea ON u.id = uea.user_id 
             WHERE uea.estate_id = ? AND u.role IN ('estate_admin', 'property_manager')",
            [$estateId]
        );
        
        foreach ($admins as $admin) {
            create_maintenance_notification(
                0, // No specific ticket
                (int)$admin['id'],
                'overdue_alert',
                $title,
                $message
            );
        }
        
        // Mark tickets as notified
        $ticketIds = array_column($overdueTickets, 'id');
        if (!empty($ticketIds)) {
            $placeholders = str_repeat('?,', count($ticketIds) - 1) . '?';
            $db->execute(
                "UPDATE maintenance_tickets SET notified_overdue = 1 WHERE id IN ($placeholders)",
                $ticketIds
            );
        }
        
    } catch (Throwable $e) {
        error_log('Failed to notify admin about overdue tickets: ' . $e->getMessage());
    }
}

/**
 * Get maintenance notifications for admin dashboard
 * 
 * @param int $userId
 * @param int $limit
 * @return array
 */
function get_admin_maintenance_notifications(int $userId, int $limit = 10): array {
    try {
        $db = db();
        return $db->fetchAll(
            "SELECT mn.*, mt.ticket_number, mt.title, mt.status
             FROM maintenance_notifications mn
             LEFT JOIN maintenance_tickets mt ON mn.ticket_id = mt.id
             WHERE mn.user_id = ?
             AND mn.notification_type IN ('quotation_submitted', 'quotation_approved', 'quotation_rejected', 'work_completed', 'overdue_alert', 'admin_review')
             ORDER BY mn.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    } catch (Throwable $e) {
        error_log('Failed to get admin maintenance notifications: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get unread maintenance notification count for admin
 * 
 * @param int $userId
 * @return int
 */
function get_admin_maintenance_notification_count(int $userId): int {
    try {
        $db = db();
        $result = $db->fetchOne(
            "SELECT COUNT(*) as count 
             FROM maintenance_notifications 
             WHERE user_id = ? 
             AND is_read = FALSE
             AND notification_type IN ('quotation_submitted', 'quotation_approved', 'quotation_rejected', 'work_completed', 'overdue_alert', 'admin_review')",
            [$userId]
        );
        return (int)($result['count'] ?? 0);
    } catch (Throwable $e) {
        error_log('Failed to get admin maintenance notification count: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Enhanced notification display for admin dashboard
 * 
 * @param array $notifications
 * @return string HTML formatted notifications
 */
function format_admin_maintenance_notifications(array $notifications): string {
    if (empty($notifications)) {
        return '<div class="text-gray-500 text-center py-6">No maintenance notifications yet.</div>';
    }
    
    $html = '';
    foreach ($notifications as $n) {
        $icon = match($n['notification_type']) {
            'quotation_submitted' => 'fas fa-file-invoice text-primary',
            'quotation_approved' => 'fas fa-check-circle text-success',
            'quotation_rejected' => 'fas fa-times-circle text-danger',
            'work_completed' => 'fas fa-clipboard-check text-warning',
            'overdue_alert' => 'fas fa-exclamation-triangle text-danger',
            'admin_review' => 'fas fa-eye text-info',
            default => 'fas fa-bell text-muted'
        };
        
        $bgClass = empty($n['read_at']) ? 'bg-light-primary' : '';
        $ticketInfo = !empty($n['ticket_number']) ? " ({$n['ticket_number']})" : '';
        
        $html .= "
        <a href='maintenance_work_completion_review.php?estate_id=1&ticket_id=" . (int)($n['ticket_id'] ?? 0) . "' 
           class='d-flex flex-column mb-5 {$bgClass} rounded p-4 text-gray-800 text-hover-primary'>
            <div class='d-flex align-items-center mb-1'>
                <div class='symbol symbol-30px symbol-circle me-3'>
                    <div class='symbol-label bg-light'>
                        <i class='{$icon}'></i>
                    </div>
                </div>
                <span class='fw-semibold'>{$n['title']}{$ticketInfo}</span>
            </div>
            <span class='fs-7 text-muted'>{$n['message']}</span>
            <span class='fs-8 text-gray-500 mt-1'>" . date('M j, g:i A', strtotime($n['created_at'])) . "</span>
        </a>";
    }
    
    return $html;
}