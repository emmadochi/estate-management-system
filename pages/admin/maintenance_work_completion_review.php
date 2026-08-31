<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/maintenance_notifications.php';
require_once __DIR__ . '/../../app/maintenance_notifications_enhanced.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Work Completion Review – EstatePro';
$db = db();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$ticketId = (int)(get_param('ticket_id', 0) ?? 0);

$estates = estates_for_current_user();
if (!$estates) {
    http_response_code(403);
    echo 'No estate access assigned to your account.';
    exit;
}
$estateId = normalize_estate_id($requestedEstateId);

// Get tickets that need admin review
$tickets = [];
try {
    $tickets = $db->fetchAll(
        "SELECT 
            mt.id, mt.ticket_number, mt.title, mt.status, mt.priority,
            mt.description, mt.before_photo, mt.after_photo, mt.completion_photo,
            mt.note, mt.cost, mt.quoted_cost,
            mt.created_at, mt.resolved_at, mt.expected_completion_date,
            mt.tenant_confirmation_status,
            un.unit_number, p.name AS property_name,
            v.name AS vendor_name, u.email AS artisan_email,
            tn.emergency_contact_name AS tenant_name
         FROM maintenance_tickets mt
         INNER JOIN units un ON un.id = mt.unit_id
         INNER JOIN properties p ON p.id = un.property_id
         INNER JOIN tenants tn ON tn.id = mt.tenant_id
         INNER JOIN users t ON t.id = tn.user_id
         LEFT JOIN vendors v ON v.id = mt.vendor_id
         LEFT JOIN users u ON u.id = v.user_id
         WHERE mt.estate_id = ? 
         AND mt.status IN ('work_completed', 'tenant_review', 'admin_review')
         ORDER BY mt.created_at DESC
         LIMIT 100",
        [$estateId]
    );
} catch (Throwable $e) {
    $tickets = [];
}

// Get specific ticket details if requested
$selectedTicket = null;
if ($ticketId > 0) {
    try {
        $selectedTicket = $db->fetchOne(
            "SELECT 
                mt.*, 
                un.unit_number, p.name AS property_name,
                v.name AS vendor_name, u.email AS artisan_email,
                tn.emergency_contact_name AS tenant_name
             FROM maintenance_tickets mt
             INNER JOIN units un ON un.id = mt.unit_id
             INNER JOIN properties p ON p.id = un.property_id
             INNER JOIN tenants tn ON tn.id = mt.tenant_id
             INNER JOIN users t ON t.id = tn.user_id
             LEFT JOIN vendors v ON v.id = mt.vendor_id
             LEFT JOIN users u ON u.id = v.user_id
             WHERE mt.id = ? AND mt.estate_id = ?",
            [$ticketId, $estateId]
        );
    } catch (Throwable $e) {
        // Ticket not found
    }
}

$method = request_method();
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
    
    if ($ticketId > 0 && in_array($action, ['approve_completion', 'request_revision'])) {
        try {
            $ticket = $db->fetchOne(
                "SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?",
                [$ticketId, $estateId]
            );
            
            if ($ticket) {
                if ($action === 'approve_completion') {
                    // Approve work completion and move to payment pending
                    $db->execute(
                        "UPDATE maintenance_tickets 
                         SET status = 'payment_pending', 
                             resolved_at = NOW(),
                             updated_at = NOW()
                         WHERE id = ?",
                        [$ticketId]
                    );
                    
                    // Create notification for artisan
                    create_maintenance_notification(
                        $ticketId,
                        (int)($ticket['vendor_id'] ?? 0),
                        'admin_approval',
                        'Work Completion Approved',
                        'Your work on ticket ' . $ticket['ticket_number'] . ' has been approved by the estate administrator.'
                    );
                    
                    flash_set('success', 'Work completion approved successfully.');
                } elseif ($action === 'request_revision') {
                    $revisionNotes = trim((string)post_param('revision_notes', ''));
                    
                    // Request revision and move back to in_progress
                    $db->execute(
                        "UPDATE maintenance_tickets 
                         SET status = 'in_progress',
                             note = CONCAT(note, '\n\nRevision requested: ', ?),
                             updated_at = NOW()
                         WHERE id = ?",
                        [$revisionNotes, $ticketId]
                    );
                    
                    // Create notification for artisan
                    create_maintenance_notification(
                        $ticketId,
                        (int)($ticket['vendor_id'] ?? 0),
                        'revision_requested',
                        'Work Revision Requested',
                        'The estate administrator has requested revisions for ticket ' . $ticket['ticket_number'] . '. Please review the notes and make necessary adjustments.'
                    );
                    
                    flash_set('success', 'Revision requested successfully.');
                }
                
                redirect('maintenance_work_completion_review.php?estate_id=' . $estateId . '&ticket_id=' . $ticketId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Error processing request: ' . $e->getMessage());
            redirect('maintenance_work_completion_review.php?estate_id=' . $estateId);
        }
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Work Completion Review</h1>
    <div class="text-gray-600">Review and approve completed maintenance work.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light" href="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">
      <i class="fas fa-arrow-left me-2"></i>Back to Reports
    </a>
  </div>
</div>

<?php if ($selectedTicket): ?>
<!-- Ticket Detail View -->
<div class="row g-6">
  <div class="col-12 col-xl-8">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">
          <?= e($selectedTicket['ticket_number']) ?> - <?= e($selectedTicket['title']) ?>
        </div>
        <div class="card-toolbar">
          <span class="badge badge-light-info me-2"><?= e($selectedTicket['status']) ?></span>
          <span class="badge badge-light-<?= $selectedTicket['priority'] === 'urgent' ? 'danger' : 'primary' ?>">
            <?= e($selectedTicket['priority']) ?>
          </span>
        </div>
      </div>
      <div class="card-body">
        <div class="row mb-6">
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Tenant</div>
            <div class="fw-bold"><?= e($selectedTicket['tenant_name']) ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Location</div>
            <div class="fw-bold"><?= e($selectedTicket['property_name']) ?> - <?= e($selectedTicket['unit_number']) ?></div>
          </div>
        </div>
        
        <div class="row mb-6">
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Artisan</div>
            <div class="fw-bold"><?= e($selectedTicket['vendor_name'] ?? 'Unassigned') ?></div>
            <?php if (!empty($selectedTicket['artisan_email'])): ?>
              <div class="text-gray-600 fs-8"><?= e($selectedTicket['artisan_email']) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Cost</div>
            <div class="fw-bold">Quoted: ₦<?= number_format((float)($selectedTicket['quoted_cost'] ?? 0), 2) ?></div>
            <div class="text-gray-600">Actual: ₦<?= number_format((float)($selectedTicket['cost'] ?? 0), 2) ?></div>
          </div>
        </div>
        
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-2">Description</div>
          <div class="text-gray-800"><?= nl2br(e($selectedTicket['description'])) ?></div>
        </div>
        
        <?php if (!empty($selectedTicket['note'])): ?>
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-2">Work Notes</div>
          <div class="text-gray-800 bg-light p-4 rounded"><?= nl2br(e($selectedTicket['note'])) ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Photos Section -->
        <?php if (!empty($selectedTicket['before_photo']) || !empty($selectedTicket['after_photo']) || !empty($selectedTicket['completion_photo'])): ?>
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-3">Photos</div>
          <div class="row g-3">
            <?php if (!empty($selectedTicket['before_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">Before</div>
              <img src="<?= app_url('uploads/' . e($selectedTicket['before_photo'])) ?>" 
                   alt="Before" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($selectedTicket['before_photo'])) ?>" 
                   data-image-title="Before Photo">
            </div>
            <?php endif; ?>
            
            <?php if (!empty($selectedTicket['after_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">After</div>
              <img src="<?= app_url('uploads/' . e($selectedTicket['after_photo'])) ?>" 
                   alt="After" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($selectedTicket['after_photo'])) ?>" 
                   data-image-title="After Photo">
            </div>
            <?php endif; ?>
            
            <?php if (!empty($selectedTicket['completion_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">Completion</div>
              <img src="<?= app_url('uploads/' . e($selectedTicket['completion_photo'])) ?>" 
                   alt="Completion" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($selectedTicket['completion_photo'])) ?>" 
                   data-image-title="Completion Photo">
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        
        <!-- Tenant Confirmation Status -->
        <?php if (!empty($selectedTicket['tenant_confirmation_status'])): ?>
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-2">Tenant Confirmation</div>
          <span class="badge badge-light-<?= $selectedTicket['tenant_confirmation_status'] === 'confirmed' ? 'success' : 'warning' ?>">
            <?= e($selectedTicket['tenant_confirmation_status']) ?>
          </span>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-3">
          <form method="post" class="flex-grow-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_completion">
            <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
            <button type="submit" class="btn btn-success btn-lg w-100">
              <i class="fas fa-check-circle me-2"></i>Approve Completion
            </button>
          </form>
          
          <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#revisionModal">
            <i class="fas fa-undo me-2"></i>Request Revision
          </button>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-12 col-xl-4">
    <!-- Timeline -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Status Timeline</div>
      </div>
      <div class="card-body">
        <?php
        $timelineEvents = [
            ['status' => 'open', 'title' => 'Ticket Created', 'date' => $selectedTicket['created_at'], 'icon' => 'fas fa-plus-circle', 'color' => 'primary'],
            ['status' => 'assigned', 'title' => 'Artisan Assigned', 'date' => null, 'icon' => 'fas fa-user-plus', 'color' => 'info'],
            ['status' => 'in_progress', 'title' => 'Work Started', 'date' => null, 'icon' => 'fas fa-tools', 'color' => 'primary'],
            ['status' => 'work_completed', 'title' => 'Work Completed', 'date' => $selectedTicket['resolved_at'], 'icon' => 'fas fa-check-circle', 'color' => 'success'],
        ];
        
        foreach ($timelineEvents as $event):
            $isActive = $selectedTicket['status'] === $event['status'];
            $isCompleted = strtotime($selectedTicket['status']) >= strtotime($event['status']) || ($event['date'] && strtotime($event['date']) > 0);
        ?>
        <div class="d-flex align-items-center mb-4">
          <div class="symbol symbol-40px symbol-circle me-3">
            <div class="symbol-label bg-<?= $isCompleted ? 'success' : ($isActive ? 'primary' : 'light') ?>">
              <i class="<?= $event['icon'] ?> text-<?= $isCompleted ? 'white' : ($isActive ? 'white' : 'gray-500') ?>"></i>
            </div>
          </div>
          <div class="flex-grow-1">
            <div class="fw-bold <?= $isActive ? 'text-primary' : '' ?>"><?= e($event['title']) ?></div>
            <?php if ($event['date']): ?>
              <div class="text-gray-600 fs-8"><?= date('M j, Y g:i A', strtotime($event['date'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Quick Actions</div>
      </div>
      <div class="card-body">
        <div class="d-grid gap-2">
          <a href="maintenance_ticket_review.php?estate_id=<?= (int)$estateId ?>&ticket_id=<?= (int)$selectedTicket['id'] ?>" 
             class="btn btn-light">
            <i class="fas fa-file-alt me-2"></i>View Full Details
          </a>
          <a href="maintenance.php?estate_id=<?= (int)$estateId ?>&edit_id=<?= (int)$selectedTicket['id'] ?>" 
             class="btn btn-light">
            <i class="fas fa-edit me-2"></i>Edit Ticket
          </a>
          <a href="maintenance_reports.php?estate_id=<?= (int)$estateId ?>" 
             class="btn btn-light">
            <i class="fas fa-list me-2"></i>All Reports
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Revision Request Modal -->
<div class="modal fade" id="revisionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Request Revision</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="request_revision">
          <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
          
          <div class="mb-3">
            <label class="form-label">Revision Notes</label>
            <textarea class="form-control" name="revision_notes" rows="4" 
                      placeholder="Please specify what needs to be revised..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Request Revision</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Ticket List View -->
<div class="card">
  <div class="card-header">
    <div class="card-title fw-bold">Tickets Awaiting Review</div>
  </div>
  <div class="card-body">
    <?php if (empty($tickets)): ?>
      <div class="text-center py-10">
        <i class="fas fa-clipboard-check text-muted fs-1 mb-4"></i>
        <h4 class="text-gray-700">No tickets awaiting review</h4>
        <p class="text-gray-500">All maintenance work is up to date.</p>
        <a class="btn btn-primary" href="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">
          <i class="fas fa-arrow-left me-2"></i>Back to Reports
        </a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-row-dashed align-middle">
          <thead>
            <tr class="fw-bold text-gray-600">
              <th>Ticket</th>
              <th>Tenant</th>
              <th>Unit</th>
              <th>Artisan</th>
              <th>Status</th>
              <th>Cost</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td class="fw-bold text-gray-900">
                  <?= e($t['ticket_number']) ?> — <?= e($t['title']) ?>
                  <div class="fs-8 text-gray-600"><?= date('M j, Y', strtotime($t['created_at'])) ?></div>
                </td>
                <td class="text-gray-700">
                  <?= e($t['tenant_name']) ?>
                </td>
                <td class="text-gray-700">
                  <?= e($t['property_name']) ?> — <?= e($t['unit_number']) ?>
                </td>
                <td class="text-gray-700">
                  <?= e($t['vendor_name'] ?? 'Unassigned') ?>
                </td>
                <td>
                  <span class="badge badge-light-warning"><?= e($t['status']) ?></span>
                  <?php if (!empty($t['tenant_confirmation_status'])): ?>
                    <span class="badge badge-light-<?= $t['tenant_confirmation_status'] === 'confirmed' ? 'success' : 'warning' ?> ms-1">
                      <?= e($t['tenant_confirmation_status']) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <div>Quoted: ₦<?= number_format((float)($t['quoted_cost'] ?? 0), 2) ?></div>
                  <div class="fs-8 text-gray-600">Actual: ₦<?= number_format((float)($t['cost'] ?? 0), 2) ?></div>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-primary" href="maintenance_work_completion_review.php?estate_id=<?= (int)$estateId ?>&ticket_id=<?= (int)$t['id'] ?>">
                    <i class="fas fa-eye me-1"></i>Review
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Image Zoom Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalTitle">Image Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-0">
        <img id="modalImage" src="" alt="Full size image" class="img-fluid" style="max-height: 80vh; width: auto;">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <a href="#" id="downloadImage" class="btn btn-primary" download>
          <i class="fas fa-download me-2"></i>Download
        </a>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle image zoom functionality
    const imageModal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    const imageModalTitle = document.getElementById('imageModalTitle');
    const downloadImage = document.getElementById('downloadImage');
    
    // Handle click on zoomable images
    document.querySelectorAll('.img-zoomable').forEach(img => {
        img.addEventListener('click', function() {
            const imageSrc = this.getAttribute('data-image-src');
            const imageTitle = this.getAttribute('data-image-title');
            
            modalImage.src = imageSrc;
            imageModalTitle.textContent = imageTitle;
            downloadImage.href = imageSrc;
            downloadImage.setAttribute('download', imageTitle.replace(/\s+/g, '_') + '.jpg');
        });
    });
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>