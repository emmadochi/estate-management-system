<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/maintenance_notifications.php';

$vendor = require_artisan();
$db = db();
$method = request_method();

$pageTitle = 'Work Completion – Artisan Area';
$pageHeading = 'Complete Maintenance Work';

$vendorId = (int)($vendor['id'] ?? 0);

// Get tickets that are in progress and assigned to this artisan
$tickets = $db->fetchAll(
    "SELECT 
        mt.id, mt.ticket_number, mt.title, mt.description, mt.status,
        mt.before_photo, mt.after_photo,
        mt.expected_completion_date,
        un.unit_number, p.name as property_name,
        e.name as estate_name,
        t.first_name as tenant_first, t.last_name as tenant_last
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     INNER JOIN estates e ON e.id = mt.estate_id
     INNER JOIN tenants tn ON tn.id = mt.tenant_id
     INNER JOIN users t ON t.id = tn.user_id
     WHERE mt.vendor_id = ? 
     AND mt.status IN ('in_progress', 'accepted')
     ORDER BY mt.created_at DESC",
    [$vendorId]
);

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/../../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
    
    if ($ticketId > 0) {
        // Verify ticket belongs to artisan
        $ticket = $db->fetchOne(
            "SELECT id, status FROM maintenance_tickets WHERE id = ? AND vendor_id = ?",
            [$ticketId, $vendorId]
        );
        
        if (!$ticket) {
            flash_set('error', 'Ticket not found or not assigned to you.');
            redirect('work_completion.php');
        }
        
        if ($action === 'mark_completed') {
            $completionNotes = trim((string)post_param('completion_notes', ''));
            $actualCompletionDate = trim((string)post_param('actual_completion_date', ''));
            
            // Handle completion photo upload
            $completionPhoto = null;
            if (isset($_FILES['completion_photo']) && $_FILES['completion_photo']['error'] == UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                $fileType = mime_content_type($_FILES['completion_photo']['tmp_name']);
                
                if (in_array($fileType, $allowedTypes)) {
                    $fileName = 'ticket_' . $ticketId . '_completion_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
                    if (move_uploaded_file($_FILES['completion_photo']['tmp_name'], $uploadDir . $fileName)) {
                        $completionPhoto = $fileName;
                    }
                }
            }
            
            try {
                // Update ticket status to work_completed
                $sql = "UPDATE maintenance_tickets 
                        SET status = 'work_completed',
                            completion_notes = ?,
                            actual_completion_date = NOW(),";
                
                $params = [$completionNotes];
                
                if ($completionPhoto !== null) {
                    $sql .= " completion_photo = ?,";
                    $params[] = $completionPhoto;
                }
                
                $sql .= " updated_at = NOW() WHERE id = ?";
                $params[] = $ticketId;
                
                $db->execute($sql, $params);
                
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes, photos)
                     VALUES (?, ?, ?, 'work_completed', ?, ?)",
                    [
                        $ticketId, 
                        current_user_id(), 
                        $ticket['status'], 
                        $completionNotes,
                        $completionPhoto ? json_encode([$completionPhoto]) : null
                    ]
                );
                
                // Create tenant confirmation record
                $tenantId = $db->fetchOne(
                    "SELECT tenant_id FROM maintenance_tickets WHERE id = ?",
                    [$ticketId]
                );
                
                if ($tenantId) {
                    $db->insert(
                        "INSERT INTO tenant_confirmations 
                         (ticket_id, tenant_id, confirmation_status)
                         VALUES (?, ?, 'pending')",
                        [$ticketId, (int)$tenantId['tenant_id']]
                    );
                }
                
                // Send notification to tenant
                create_maintenance_notification(
                    $ticketId,
                    $tenantId['tenant_id'],
                    'completion',
                    'Maintenance Work Completed',
                    'The artisan has marked your maintenance request as completed. Please review the work and confirm completion.'
                );
                
                flash_set('success', 'Work marked as completed. Awaiting tenant confirmation.');
                redirect('work_completion.php');
                
            } catch (Throwable $e) {
                flash_set('error', 'Error marking work as completed: ' . $e->getMessage());
                redirect('work_completion.php?ticket_id=' . $ticketId);
            }
        }
        
        if ($action === 'update_progress') {
            $progressNotes = trim((string)post_param('progress_notes', ''));
            $progressPercentage = (int)(post_param('progress_percentage', 0) ?? 0);
            $estimatedCompletion = trim((string)post_param('estimated_completion', ''));
            
            try {
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes, progress_percentage, estimated_completion)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $ticketId,
                        current_user_id(),
                        'in_progress',
                        'in_progress',
                        $progressNotes,
                        min(100, max(0, $progressPercentage)),
                        $estimatedCompletion ?: null
                    ]
                );
                
                // Update ticket with estimated completion date if provided
                if ($estimatedCompletion) {
                    $db->execute(
                        "UPDATE maintenance_tickets 
                         SET expected_completion_date = ? 
                         WHERE id = ?",
                        [$estimatedCompletion, $ticketId]
                    );
                }
                
                flash_set('success', 'Progress update recorded successfully.');
                redirect('work_completion.php');
                
            } catch (Throwable $e) {
                flash_set('error', 'Error recording progress: ' . $e->getMessage());
                redirect('work_completion.php?ticket_id=' . $ticketId);
            }
        }
    }
}

$ticketId = (int)(get_param('ticket_id', 0) ?? 0);
$selectedTicket = null;

if ($ticketId > 0) {
    $selectedTicket = $db->fetchOne(
        "SELECT 
            mt.*, un.unit_number, p.name as property_name,
            e.name as estate_name, t.first_name as tenant_first, t.last_name as tenant_last
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         INNER JOIN estates e ON e.id = mt.estate_id
         INNER JOIN tenants tn ON tn.id = mt.tenant_id
         INNER JOIN users t ON t.id = tn.user_id
         WHERE mt.id = ? AND mt.vendor_id = ?",
        [$ticketId, $vendorId]
    );
    
    if (!$selectedTicket) {
        flash_set('error', 'Ticket not found.');
        redirect('work_completion.php');
    }
}

// Get recent progress updates for selected ticket
$progressUpdates = [];
if ($selectedTicket) {
    $progressUpdates = $db->fetchAll(
        "SELECT mpu.*, u.first_name, u.last_name
         FROM maintenance_progress_updates mpu
         INNER JOIN users u ON u.id = mpu.updated_by
         WHERE mpu.ticket_id = ?
         ORDER BY mpu.created_at DESC
         LIMIT 10",
        [$ticketId]
    );
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Work Completion</h1>
    <div class="text-gray-600">Mark maintenance work as completed and update progress</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-sm btn-light" href="tickets.php">
      <i class="fas fa-arrow-left me-2"></i>My Tickets
    </a>
  </div>
</div>

<?php if ($ticketId > 0 && $selectedTicket): ?>
<!-- Detailed Ticket View with Completion Options -->
<div class="row g-6">
  <div class="col-12">
    <div class="card mb-6">
      <div class="card-header bg-primary text-white">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-tools me-2"></i>
          Complete Work - <?= e($selectedTicket['ticket_number']) ?>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-4 mb-4">
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Title</div>
              <div class="fw-bold fs-5"><?= e($selectedTicket['title']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Description</div>
              <div class="text-gray-800"><?= nl2br(e($selectedTicket['description'])) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Location</div>
              <div class="fw-bold"><?= e($selectedTicket['estate_name']) ?> - <?= e($selectedTicket['property_name']) ?> <?= e($selectedTicket['unit_number']) ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Tenant</div>
              <div class="fw-bold"><?= e($selectedTicket['tenant_first'] . ' ' . $selectedTicket['tenant_last']) ?></div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Status</div>
              <div class="fw-bold">
                <span class="badge badge-<?= 
                  $selectedTicket['status'] === 'in_progress' ? 'warning' : 'info' 
                ?>"><?= e(str_replace('_', ' ', $selectedTicket['status'])) ?></span>
              </div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Expected Completion</div>
              <div class="fw-bold"><?= e($selectedTicket['expected_completion_date'] ? date('M j, Y', strtotime($selectedTicket['expected_completion_date'])) : 'Not set') ?></div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Priority</div>
              <div class="fw-bold">
                <span class="badge badge-<?= 
                  $selectedTicket['priority'] === 'urgent' ? 'danger' : 
                  ($selectedTicket['priority'] === 'high' ? 'warning' : 'info') 
                ?>"><?= e($selectedTicket['priority']) ?></span>
              </div>
            </div>
            <?php if (!empty($selectedTicket['quoted_cost']) && $selectedTicket['quoted_cost'] > 0): ?>
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Quoted Amount</div>
                <div class="fw-bold text-primary">₦<?= number_format((float)$selectedTicket['quoted_cost'], 2) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        
        <!-- Photo Gallery -->
        <?php if (!empty($selectedTicket['before_photo']) || !empty($selectedTicket['after_photo'])): ?>
          <div class="row g-4 mb-4">
            <div class="col-12">
              <div class="text-gray-600 fs-7 mb-2">Work Photos</div>
              <div class="d-flex gap-3">
                <?php if (!empty($selectedTicket['before_photo'])): ?>
                  <div>
                    <div class="text-gray-600 fs-8 mb-1">Before</div>
                    <img src="../../uploads/<?= e($selectedTicket['before_photo']) ?>" 
                         alt="Before" class="img-thumbnail" style="max-width: 150px; max-height: 120px;">
                  </div>
                <?php endif; ?>
                <?php if (!empty($selectedTicket['after_photo'])): ?>
                  <div>
                    <div class="text-gray-600 fs-8 mb-1">After</div>
                    <img src="../../uploads/<?= e($selectedTicket['after_photo']) ?>" 
                         alt="After" class="img-thumbnail" style="max-width: 150px; max-height: 120px;">
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
        
        <!-- Progress Updates -->
        <?php if ($progressUpdates): ?>
          <div class="mb-4">
            <h6 class="mb-3 text-primary"><i class="fas fa-history me-2"></i>Progress Updates</h6>
            <div class="timeline timeline-border-dashed">
              <?php foreach ($progressUpdates as $update): ?>
                <div class="timeline-item">
                  <div class="timeline-line"></div>
                  <div class="timeline-icon">
                    <i class="fas fa-circle text-primary"></i>
                  </div>
                  <div class="timeline-content mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <div class="fw-bold text-gray-900">
                        <?= e($update['first_name'] . ' ' . $update['last_name']) ?>
                      </div>
                      <div class="text-gray-600 fs-8"><?= e(date('M j, Y H:i', strtotime($update['created_at']))) ?></div>
                    </div>
                    <div class="text-gray-700">
                      <?php if (!empty($update['notes'])): ?>
                        <div class="mb-1"><?= e($update['notes']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($update['progress_percentage'])): ?>
                        <div class="mb-1">
                          <div class="d-flex align-items-center">
                            <span class="text-muted fs-8 me-2">Progress:</span>
                            <div class="w-100px bg-light rounded h-10px me-2">
                              <div class="bg-primary rounded h-10px" style="width: <?= (int)$update['progress_percentage'] ?>%"></div>
                            </div>
                            <span class="fs-8"><?= (int)$update['progress_percentage'] ?>%</span>
                          </div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($update['estimated_completion'])): ?>
                        <div class="text-muted fs-8">Estimated completion: <?= e(date('M j, Y', strtotime($update['estimated_completion']))) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        
        <!-- Progress Update Form -->
        <div class="card mb-4 bg-light">
          <div class="card-body">
            <h6 class="mb-3 text-primary"><i class="fas fa-sync me-2"></i>Update Progress</h6>
            <form method="post" action="work_completion.php?ticket_id=<?= (int)$selectedTicket['id'] ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_progress">
              <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
              
              <div class="row g-4">
                <div class="col-md-4">
                  <label class="form-label fw-bold">Progress Percentage</label>
                  <input type="number" class="form-control" name="progress_percentage" 
                         min="0" max="100" placeholder="0-100">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold">Estimated Completion</label>
                  <input type="date" class="form-control" name="estimated_completion">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold">Progress Notes</label>
                  <textarea class="form-control" name="progress_notes" rows="2" 
                            placeholder="Update on current progress..."></textarea>
                </div>
              </div>
              
              <div class="mt-3">
                <button type="submit" class="btn btn-info">
                  <i class="fas fa-sync me-2"></i>Update Progress
                </button>
              </div>
            </form>
          </div>
        </div>
        
        <!-- Mark as Completed Form -->
        <div class="card border-2 border-success">
          <div class="card-header bg-success text-white">
            <div class="card-title fw-bold mb-0">
              <i class="fas fa-check-circle me-2"></i>Mark Work as Completed
            </div>
          </div>
          <div class="card-body">
            <form method="post" action="work_completion.php?ticket_id=<?= (int)$selectedTicket['id'] ?>" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_completed">
              <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
              
              <div class="mb-4">
                <label class="form-label required fw-bold">Completion Notes</label>
                <textarea class="form-control" name="completion_notes" rows="4" 
                          placeholder="Describe what work was completed, materials used, any issues encountered..." required></textarea>
              </div>
              
              <div class="mb-4">
                <label class="form-label fw-bold">Completion Photo</label>
                <input class="form-control" type="file" name="completion_photo" accept="image/*">
                <div class="text-muted fs-8 mt-1">Upload a photo showing the completed work</div>
              </div>
              
              <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Important:</strong> Once marked as completed:
                <ul class="mb-0 mt-2">
                  <li>The tenant will be notified to review and confirm the work</li>
                  <li>You won't be able to make changes without admin approval</li>
                  <li>Payment will be processed after tenant confirmation</li>
                </ul>
              </div>
              
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-lg">
                  <i class="fas fa-check-circle me-2"></i>Mark as Completed
                </button>
                <a href="work_completion.php" class="btn btn-light btn-lg">
                  <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Ticket List View -->
<?php if (!$tickets): ?>
  <div class="card">
    <div class="card-body text-center py-10">
      <div class="symbol symbol-100px mx-auto mb-5">
        <i class="fas fa-tools text-muted fs-1"></i>
      </div>
      <h4 class="text-gray-700">No active work</h4>
      <p class="text-gray-500">You don't have any maintenance tickets assigned to you currently.</p>
      <a href="tickets.php" class="btn btn-primary">View All Tickets</a>
    </div>
  </div>
<?php else: ?>
  <div class="row g-6">
    <?php foreach ($tickets as $ticket): ?>
      <div class="col-12">
        <div class="card mb-4 border-<?= $ticket['status'] === 'in_progress' ? 'warning' : 'info' ?>">
          <div class="card-header <?= $ticket['status'] === 'in_progress' ? 'bg-warning' : 'bg-info' ?> text-white">
            <div class="card-title fw-bold d-flex justify-content-between align-items-center">
              <div>
                <i class="fas fa-<?= $ticket['status'] === 'in_progress' ? 'tools' : 'clock' ?> me-2"></i>
                <?= e($ticket['ticket_number']) ?> - <?= e($ticket['title']) ?>
              </div>
              <div>
                <span class="badge badge-light"><?= e(str_replace('_', ' ', $ticket['status'])) ?></span>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-4">
              <div class="col-md-8">
                <div class="mb-2">
                  <span class="text-gray-600">Location:</span>
                  <span class="fw-bold"><?= e($ticket['estate_name']) ?> - <?= e($ticket['property_name']) ?> <?= e($ticket['unit_number']) ?></span>
                </div>
                <div class="mb-2">
                  <span class="text-gray-600">Tenant:</span>
                  <span class="fw-bold"><?= e($ticket['tenant_first'] . ' ' . $ticket['tenant_last']) ?></span>
                </div>
                <div class="mb-3">
                  <span class="text-gray-600">Description:</span>
                  <div class="text-gray-800 mt-1"><?= nl2br(e(substr($ticket['description'], 0, 150))) ?><?= strlen($ticket['description']) > 150 ? '...' : '' ?></div>
                </div>
                
                <?php if (!empty($ticket['expected_completion_date'])): ?>
                  <div class="mb-2">
                    <span class="text-gray-600">Expected Completion:</span>
                    <span class="fw-bold <?= strtotime($ticket['expected_completion_date']) < time() ? 'text-danger' : 'text-success' ?>">
                      <?= e(date('M j, Y', strtotime($ticket['expected_completion_date']))) ?>
                    </span>
                  </div>
                <?php endif; ?>
              </div>
              
              <div class="col-md-4">
                <div class="d-flex flex-column h-100">
                  <?php if (!empty($ticket['after_photo'])): ?>
                    <div class="mb-3">
                      <div class="text-gray-600 fs-8 mb-1">Current Progress</div>
                      <img src="../../uploads/<?= e($ticket['after_photo']) ?>" 
                           alt="Progress" class="img-thumbnail" style="max-width: 150px; max-height: 120px;">
                    </div>
                  <?php endif; ?>
                  
                  <div class="mt-auto">
                    <a href="work_completion.php?ticket_id=<?= (int)$ticket['id'] ?>" 
                       class="btn btn-primary w-100 mb-2">
                      <i class="fas fa-clipboard-check me-2"></i>Complete Work
                    </a>
                    <a href="ticket_view.php?id=<?= (int)$ticket['id'] ?>" 
                       class="btn btn-light w-100">
                      <i class="fas fa-eye me-2"></i>View Details
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>