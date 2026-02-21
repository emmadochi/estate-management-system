<?php
/**
 * Maintenance Notification System
 * Professional notification handling for maintenance workflow
 */

/**
 * Create a maintenance notification
 * 
 * @param int $ticketId
 * @param int $userId
 * @param string $type
 * @param string $title
 * @param string $message
 * @return int|null Notification ID or null on failure
 */
function create_maintenance_notification(int $ticketId, int $userId, string $type, string $title, string $message): ?int {
    try {
        $db = db();
        
        // Check if user wants notifications
        $user = $db->fetchOne('SELECT maintenance_notifications FROM users WHERE id = ?', [$userId]);
        if (!$user || !$user['maintenance_notifications']) {
            return null;
        }
        
        $notificationId = $db->insert(
            "INSERT INTO maintenance_notifications 
             (ticket_id, user_id, notification_type, title, message)
             VALUES (?, ?, ?, ?, ?)",
            [$ticketId, $userId, $type, $title, $message]
        );
        
        // In a real implementation, you would also send email/SMS here
        // send_email_notification($userId, $title, $message);
        // send_sms_notification($userId, $message);
        
        return $notificationId;
        
    } catch (Throwable $e) {
        error_log('Failed to create maintenance notification: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send notification to tenant for a maintenance ticket
 * 
 * @param int $ticketId
 * @param string $type
 * @param string $title
 * @param string $message
 * @return int|null Notification ID or null
 */
function notify_tenant_maintenance(int $ticketId, string $type, string $title, string $message): ?int {
    try {
        $db = db();
        
        // Get tenant ID for this ticket
        $ticket = $db->fetchOne(
            "SELECT tenant_id FROM maintenance_tickets WHERE id = ?",
            [$ticketId]
        );
        
        if (!$ticket) {
            return null;
        }
        
        // Get user ID for tenant
        $tenantUser = $db->fetchOne(
            "SELECT user_id FROM tenants WHERE id = ?",
            [(int)$ticket['tenant_id']]
        );
        
        if (!$tenantUser) {
            return null;
        }
        
        return create_maintenance_notification(
            $ticketId,
            (int)$tenantUser['user_id'],
            $type,
            $title,
            $message
        );
        
    } catch (Throwable $e) {
        error_log('Failed to notify tenant: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send notification to artisan for a maintenance ticket
 * 
 * @param int $ticketId
 * @param string $type
 * @param string $title
 * @param string $message
 * @return int|null Notification ID or null
 */
function notify_artisan_maintenance(int $ticketId, string $type, string $title, string $message): ?int {
    try {
        $db = db();
        
        // Get vendor ID for this ticket
        $ticket = $db->fetchOne(
            "SELECT vendor_id FROM maintenance_tickets WHERE id = ?",
            [$ticketId]
        );
        
        if (!$ticket || !$ticket['vendor_id']) {
            return null;
        }
        
        // Get user ID for vendor
        $vendorUser = $db->fetchOne(
            "SELECT user_id FROM vendors WHERE id = ?",
            [(int)$ticket['vendor_id']]
        );
        
        if (!$vendorUser || !$vendorUser['user_id']) {
            return null;
        }
        
        return create_maintenance_notification(
            $ticketId,
            (int)$vendorUser['user_id'],
            $type,
            $title,
            $message
        );
        
    } catch (Throwable $e) {
        error_log('Failed to notify artisan: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send notification to admin for a maintenance ticket
 * 
 * @param int $ticketId
 * @param int $estateId
 * @param string $type
 * @param string $title
 * @param string $message
 * @return int|null Notification ID or null
 */
function notify_admin_maintenance(int $ticketId, int $estateId, string $type, string $title, string $message): ?int {
    try {
        $db = db();
        
        // Get estate admins for this estate
        $admins = $db->fetchAll(
            "SELECT u.id FROM users u 
             INNER JOIN user_estates ue ON ue.user_id = u.id
             WHERE ue.estate_id = ? AND u.role IN ('estate_admin', 'property_manager', 'super_admin')",
            [$estateId]
        );
        
        $notificationIds = [];
        foreach ($admins as $admin) {
            $notificationId = create_maintenance_notification(
                $ticketId,
                (int)$admin['id'],
                $type,
                $title,
                $message
            );
            
            if ($notificationId) {
                $notificationIds[] = $notificationId;
            }
        }
        
        return !empty($notificationIds) ? $notificationIds[0] : null;
        
    } catch (Throwable $e) {
        error_log('Failed to notify admin: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get unread notifications for user
 * 
 * @param int $userId
 * @param int $limit
 * @return array
 */
function get_user_maintenance_notifications(int $userId, int $limit = 10): array {
    try {
        $db = db();
        return $db->fetchAll(
            "SELECT mn.*, mt.ticket_number
             FROM maintenance_notifications mn
             LEFT JOIN maintenance_tickets mt ON mt.id = mn.ticket_id
             WHERE mn.user_id = ?
             ORDER BY mn.sent_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    } catch (Throwable $e) {
        error_log('Failed to get user notifications: ' . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 * 
 * @param int $notificationId
 * @param int $userId
 * @return bool
 */
function mark_notification_read(int $notificationId, int $userId): bool {
    try {
        $db = db();
        $result = $db->execute(
            "UPDATE maintenance_notifications 
             SET is_read = TRUE, read_at = NOW()
             WHERE id = ? AND user_id = ?",
            [$notificationId, $userId]
        );
        return $result > 0;
    } catch (Throwable $e) {
        error_log('Failed to mark notification as read: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get notification count for user
 * 
 * @param int $userId
 * @return int
 */
function get_maintenance_notification_count(int $userId): int {
    try {
        $db = db();
        $result = $db->fetchOne(
            "SELECT COUNT(*) as count 
             FROM maintenance_notifications 
             WHERE user_id = ? AND is_read = FALSE",
            [$userId]
        );
        return (int)($result['count'] ?? 0);
    } catch (Throwable $e) {
        error_log('Failed to get notification count: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Create notification for status change
 * 
 * @param int $ticketId
 * @param string $oldStatus
 * @param string $newStatus
 * @param int $updatedBy
 * @return void
 */
function notify_status_change(int $ticketId, string $oldStatus, string $newStatus, int $updatedBy): void {
    try {
        $db = db();
        
        // Get ticket details
        $ticket = $db->fetchOne(
            "SELECT mt.*, e.name as estate_name
             FROM maintenance_tickets mt
             INNER JOIN estates e ON e.id = mt.estate_id
             WHERE mt.id = ?",
            [$ticketId]
        );
        
        if (!$ticket) {
            return;
        }
        
        $title = "Ticket Status Updated";
        $message = "Ticket {$ticket['ticket_number']} status changed from " . 
                  str_replace('_', ' ', $oldStatus) . " to " . 
                  str_replace('_', ' ', $newStatus) . 
                  " in {$ticket['estate_name']}";
        
        // Notify tenant
        if (in_array($newStatus, ['work_completed', 'tenant_review', 'admin_review', 'paid', 'closed'])) {
            notify_tenant_maintenance($ticketId, 'status_change', $title, $message);
        }
        
        // Notify artisan
        if (in_array($newStatus, ['assigned', 'in_progress', 'work_completed'])) {
            notify_artisan_maintenance($ticketId, 'status_change', $title, $message);
        }
        
        // Notify admin for important status changes
        if (in_array($newStatus, ['tenant_review', 'admin_review', 'overdue'])) {
            notify_admin_maintenance($ticketId, (int)$ticket['estate_id'], 'status_change', $title, $message);
        }
        
    } catch (Throwable $e) {
        error_log('Failed to send status change notification: ' . $e->getMessage());
    }
}