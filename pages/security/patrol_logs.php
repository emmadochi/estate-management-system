<?php
require_once __DIR__ . '/../../app/bootstrap.php';

// Require login with security role
require_login(['security']);

$pageTitle = 'Patrol Logs – EstatePro';
$pageHeading = 'Patrol Logs';

// Get estate ID for the current user
$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? $estateIds[0] : 0;

// Get security personnel details
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

// Handle form submissions
$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'start_patrol':
                verify_csrf();
                
                try {
                    $patrolRoute = trim(post_param('patrol_route', ''));
                    $startTime = date('Y-m-d H:i:s');
                    
                    // Create new patrol log entry
                    db()->insert(
                        "INSERT INTO security_patrol_logs (estate_id, security_officer_id, patrol_route, start_time, status) 
                         VALUES (?, ?, ?, ?, 'in_progress')",
                        [$estateId, $securityPersonnel['id'], $patrolRoute, $startTime]
                    );
                    
                    $message = "Patrol started successfully!";
                    $messageType = "success";
                } catch (Exception $e) {
                    $message = "Error starting patrol: " . $e->getMessage();
                    $messageType = "error";
                }
                break;
                
            case 'complete_patrol':
                verify_csrf();
                
                $logId = (int)post_param('log_id', 0);
                
                try {
                    // Update patrol log to completed
                    db()->execute(
                        "UPDATE security_patrol_logs 
                         SET status = 'completed', end_time = NOW(), notes = ?, location_checkpoints = ?
                         WHERE id = ? AND estate_id = ?",
                        [trim(post_param('notes', '')), json_encode(post_param('checkpoints', [])), $logId, $estateId]
                    );
                    
                    $message = "Patrol completed successfully!";
                    $messageType = "success";
                } catch (Exception $e) {
                    $message = "Error completing patrol: " . $e->getMessage();
                    $messageType = "error";
                }
                break;
                
            case 'update_patrol':
                verify_csrf();
                
                $logId = (int)post_param('log_id', 0);
                
                try {
                    // Update patrol log details
                    db()->execute(
                        "UPDATE security_patrol_logs 
                         SET location_checkpoints = ?, incidents_reported = ?, notes = ?
                         WHERE id = ? AND estate_id = ?",
                        [json_encode(post_param('checkpoints', [])), trim(post_param('incidents', '')), trim(post_param('notes', '')), $logId, $estateId]
                    );
                    
                    $message = "Patrol log updated successfully!";
                    $messageType = "success";
                } catch (Exception $e) {
                    $message = "Error updating patrol: " . $e->getMessage();
                    $messageType = "error";
                }
                break;
        }
    }
}

// Get patrol logs for the estate
$patrolLogs = [];
if ($estateId) {
    $patrolLogs = db()->fetchAll(
        "SELECT spl.*, sp.badge_number, u.first_name, u.last_name, u.phone
         FROM security_patrol_logs spl
         LEFT JOIN security_personnel sp ON spl.security_officer_id = sp.id
         LEFT JOIN users u ON sp.user_id = u.id
         WHERE spl.estate_id = ?
         ORDER BY spl.start_time DESC
         LIMIT 50",
        [$estateId]
    );
}

// Get patrol routes for the estate
$patrolRoutes = [];
if ($estateId) {
    // For now, we'll use predefined routes, but in a full implementation, these would be stored in a patrol_routes table
    $patrolRoutes = [
        'Main Gate to Admin Block',
        'Perimeter Check',
        'Residential Area A',
        'Residential Area B',
        'Commercial Area',
        'Parking Areas',
        'Recreation Facilities',
        'Emergency Exits'
    ];
}

$toolbarActions = '';

require __DIR__ . '/partials/top.php';
?>
<?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?> d-flex align-items-center" role="alert">
                            <div class="flex-grow-1"><?php echo e($message); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($flash): ?>
                        <?php
                            $type = $flash['type'] ?? 'info';
                            $flashMessage = $flash['message'] ?? '';
                            $alert = 'alert-info';
                            if ($type === 'success') $alert = 'alert-success';
                            if ($type === 'error') $alert = 'alert-danger';
                            if ($type === 'warning') $alert = 'alert-warning';
                        ?>
                        <div class="alert <?php echo e($alert); ?> d-flex align-items-center" role="alert">
                            <div class="flex-grow-1"><?php echo e($flashMessage); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Start New Patrol Form -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Start New Patrol</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="start_patrol">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="patrol_route" class="form-label">Patrol Route</label>
                                            <select class="form-select" id="patrol_route" name="patrol_route" required>
                                                <option value="">Select a patrol route</option>
                                                <?php foreach ($patrolRoutes as $route): ?>
                                                    <option value="<?php echo e($route); ?>"><?php echo e($route); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Start Patrol</button>
                            </form>
                        </div>
                    </div>

                    <!-- Active Patrols Section -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Active Patrols</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $activePatrols = array_filter($patrolLogs, function($log) {
                                return $log['status'] === 'in_progress';
                            });
                            ?>
                            
                            <?php if (empty($activePatrols)): ?>
                                <p class="text-muted">No active patrols at the moment.</p>
                            <?php else: ?>
                                <?php foreach ($activePatrols as $patrol): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <i class="fas fa-route me-1"></i>
                                                    <?php echo e($patrol['patrol_route'] ?? 'Unspecified Route'); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    Started: <?php echo e(date('M j, Y g:i A', strtotime($patrol['start_time']))); ?>
                                                </small><br>
                                                <small class="text-muted">
                                                    Officer: <?php echo e($patrol['first_name'] . ' ' . $patrol['last_name'] . ' (' . $patrol['badge_number'] . ')'); ?>
                                                </small>
                                            </div>
                                            <div>
                                                <span class="badge bg-warning"><?php echo e(ucfirst($patrol['status'])); ?></span>
                                            </div>
                                        </div>
                                        
                                        <!-- Update Patrol Form -->
                                        <form method="POST" class="mt-3">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="update_patrol">
                                            <input type="hidden" name="log_id" value="<?php echo (int)$patrol['id']; ?>">
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Checkpoints Completed</label>
                                                        <textarea class="form-control" name="checkpoints" placeholder="List checkpoints completed..."><?php echo e($patrol['location_checkpoints'] ? json_encode($patrol['location_checkpoints']) : ''); ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Incidents Reported</label>
                                                        <textarea class="form-control" name="incidents" placeholder="Report any incidents..."><?php echo e($patrol['incidents_reported'] ?? ''); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Notes</label>
                                                <textarea class="form-control" name="notes" placeholder="Additional notes..."><?php echo e($patrol['notes'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <div class="d-flex justify-content-end gap-2">
                                                <button type="submit" class="btn btn-sm btn-primary">Update Log</button>
                                                <form method="POST" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="complete_patrol">
                                                    <input type="hidden" name="log_id" value="<?php echo (int)$patrol['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">Complete Patrol</button>
                                                </form>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Patrol History -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Patrol History</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($patrolLogs)): ?>
                                <p class="text-muted">No patrol logs recorded yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Officer</th>
                                                <th>Route</th>
                                                <th>Status</th>
                                                <th>Duration</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($patrolLogs as $patrol): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold"><?php echo e(date('M j, Y', strtotime($patrol['start_time']))); ?></div>
                                                        <small class="text-muted"><?php echo e(date('g:i A', strtotime($patrol['start_time']))); ?></small>
                                                    </td>
                                                    <td>
                                                        <div><?php echo e($patrol['first_name'] . ' ' . $patrol['last_name']); ?></div>
                                                        <small class="text-muted">Badge: <?php echo e($patrol['badge_number']); ?></small>
                                                    </td>
                                                    <td><?php echo e($patrol['patrol_route'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $patrol['status'] === 'completed' ? 'success' : ($patrol['status'] === 'in_progress' ? 'primary' : 'warning'); ?>">
                                                            <?php echo e(ucfirst($patrol['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($patrol['end_time']): ?>
                                                            <?php
                                                            $start = new DateTime($patrol['start_time']);
                                                            $end = new DateTime($patrol['end_time']);
                                                            $interval = $start->diff($end);
                                                            echo e($interval->format('%hh %im'));
                                                            ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">In Progress</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#viewPatrolModal<?php echo (int)$patrol['id']; ?>">
                                                            <i class="fas fa-eye"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                                
                                                <!-- View Patrol Modal -->
                                                <div class="modal fade" id="viewPatrolModal<?php echo (int)$patrol['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Patrol Details</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <p><strong>Route:</strong> <?php echo e($patrol['patrol_route'] ?? 'N/A'); ?></p>
                                                                        <p><strong>Start Time:</strong> <?php echo e(date('M j, Y g:i A', strtotime($patrol['start_time']))); ?></p>
                                                                        <p><strong>End Time:</strong> <?php echo $patrol['end_time'] ? e(date('M j, Y g:i A', strtotime($patrol['end_time']))) : '<em>Pending</em>'; ?></p>
                                                                        <p><strong>Officer:</strong> <?php echo e($patrol['first_name'] . ' ' . $patrol['last_name'] . ' (' . $patrol['badge_number'] . ')'); ?></p>
                                                                        <p><strong>Status:</strong> <?php echo e(ucfirst($patrol['status'])); ?></p>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <p><strong>Checkpoints:</strong></p>
                                                                        <pre><?php echo e($patrol['location_checkpoints'] ? json_encode(json_decode($patrol['location_checkpoints'], true), JSON_PRETTY_PRINT) : 'None'); ?></pre>
                                                                        <p><strong>Incidents:</strong></p>
                                                                        <p><?php echo e($patrol['incidents_reported'] ?? 'None reported'); ?></p>
                                                                    </div>
                                                                </div>
                                                                
                                                                <?php if ($patrol['notes']): ?>
                                                                    <hr>
                                                                    <p><strong>Notes:</strong></p>
                                                                    <p><?php echo e($patrol['notes']); ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

<?php require __DIR__ . '/partials/bottom.php'; ?>