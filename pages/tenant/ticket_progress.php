<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Ticket Progress – EstatePro Tenant';
$pageHeading = 'Maintenance Ticket Progress';
$db = db();

// Add custom CSS
$GLOBALS['extra_css'] = '<link rel="stylesheet" href="../assets/css/tenant_progress.css">';

// Add additional CSS for image enhancements
$extraImageCSS = '
<style>
.img-zoomable {
    transition: all 0.2s ease;
    border: 2px solid #e1e5ea;
}

.img-zoomable:hover {
    transform: scale(1.05);
    border-color: #009ef7;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.img-thumbnail {
    background-color: #f8f9fa;
}

.modal-content {
    border: none;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.modal-header {
    border-bottom: 1px solid #e1e5ea;
    background-color: #f8f9fa;
}

.modal-footer {
    border-top: 1px solid #e1e5ea;
    background-color: #f8f9fa;
}

@media (max-width: 768px) {
    .modal-xl {
        max-width: 95%;
        margin: 10px auto;
    }
}
</style>
';

$GLOBALS['extra_css'] .= $extraImageCSS;

$ticketId = (int)(get_param('id', 0) ?? 0);
if ($ticketId <= 0) {
    flash_set('error', 'Invalid ticket ID.');
    redirect('maintenance.php');
}

// Verify ticket belongs to tenant
$ticket = $db->fetchOne(
    "SELECT 
        mt.*,
        un.unit_number, p.name as property_name,
        e.name as estate_name,
        v.name as vendor_name, v.specialization,
        tc.confirmation_status, tc.quality_rating, tc.feedback,
        tc.confirmed_at, tc.confirmation_notes
     FROM maintenance_tickets mt
     INNER JOIN units un ON un.id = mt.unit_id
     INNER JOIN properties p ON p.id = un.property_id
     INNER JOIN estates e ON e.id = mt.estate_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     LEFT JOIN tenant_confirmations tc ON tc.ticket_id = mt.id AND tc.tenant_id = ?
     WHERE mt.id = ? AND mt.tenant_id = ?
     LIMIT 1",
    [(int)$tenant['id'], $ticketId, (int)$tenant['id']]
);

if (!$ticket) {
    flash_set('error', 'Ticket not found or access denied.');
    redirect('maintenance.php');
}

// Get progress updates
$progressUpdates = $db->fetchAll(
    "SELECT mpu.*, u.first_name, u.last_name, u.role
     FROM maintenance_progress_updates mpu
     INNER JOIN users u ON u.id = mpu.updated_by
     WHERE mpu.ticket_id = ?
     ORDER BY mpu.created_at DESC
     LIMIT 20",
    [$ticketId]
);

// Get timeline events
$timelineEvents = [];
$timelineEvents[] = [
    'title' => 'Ticket Created',
    'description' => 'Your maintenance request has been submitted',
    'status' => 'completed',
    'date' => $ticket['created_at'],
    'icon' => 'fas fa-plus-circle',
    'color' => 'primary'
];

if ($ticket['vendor_id']) {
    $timelineEvents[] = [
        'title' => 'Assigned to Artisan',
        'description' => 'Ticket assigned to ' . ($ticket['vendor_name'] ?? 'artisan'),
        'status' => in_array($ticket['status'], ['assigned', 'accepted', 'in_progress', 'work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed']) ? 'completed' : 'pending',
        'date' => null,
        'icon' => 'fas fa-user-check',
        'color' => 'info'
    ];
}

if (in_array($ticket['status'], ['accepted', 'in_progress', 'work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Work Started',
        'description' => 'Artisan has accepted and started working on your request',
        'status' => 'completed',
        'date' => null,
        'icon' => 'fas fa-tools',
        'color' => 'warning'
    ];
}

if (in_array($ticket['status'], ['work_completed', 'tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Work Completed',
        'description' => 'Artisan has marked the work as completed',
        'status' => 'completed',
        'date' => null,
        'icon' => 'fas fa-check-circle',
        'color' => 'success'
    ];
}

if (in_array($ticket['status'], ['tenant_review', 'admin_review', 'payment_pending', 'paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Your Review Needed',
        'description' => 'Please review and confirm the completed work',
        'status' => $ticket['confirmation_status'] ? 'completed' : 'pending',
        'date' => $ticket['confirmed_at'],
        'icon' => 'fas fa-clipboard-check',
        'color' => $ticket['confirmation_status'] ? 'success' : 'warning'
    ];
}

if (in_array($ticket['status'], ['admin_review', 'payment_pending', 'paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Admin Review',
        'description' => 'Administrator is reviewing your feedback',
        'status' => 'completed',
        'date' => null,
        'icon' => 'fas fa-search',
        'color' => 'info'
    ];
}

if (in_array($ticket['status'], ['payment_pending', 'paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Payment Processing',
        'description' => 'Processing payment for completed work',
        'status' => 'completed',
        'date' => null,
        'icon' => 'fas fa-money-bill-wave',
        'color' => 'success'
    ];
}

if (in_array($ticket['status'], ['paid', 'closed'])) {
    $timelineEvents[] = [
        'title' => 'Ticket Closed',
        'description' => 'Maintenance request has been completed and closed',
        'status' => 'completed',
        'date' => $ticket['resolved_at'],
        'icon' => 'fas fa-flag-checkered',
        'color' => 'dark'
    ];
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Ticket Progress</h1>
    <div class="text-gray-600">Track your maintenance request #<?= e($ticket['ticket_number']) ?></div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light" href="maintenance.php">
      <i class="fas fa-arrow-left me-2"></i>Back to Tickets
    </a>
  </div>
</div>

<div class="row g-6">
  <!-- Ticket Summary -->
  <div class="col-12 col-xl-4">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Ticket Summary</div>
      </div>
      <div class="card-body">
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Ticket Number</div>
          <div class="fs-4 fw-bold"><?= e($ticket['ticket_number']) ?></div>
        </div>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Title</div>
          <div class="fs-6"><?= e($ticket['title']) ?></div>
        </div>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Description</div>
          <div class="text-gray-800"><?= nl2br(e($ticket['description'])) ?></div>
        </div>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Location</div>
          <div><?= e($ticket['estate_name']) ?> - <?= e($ticket['property_name']) ?> <?= e($ticket['unit_number']) ?></div>
        </div>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Status</div>
          <span class="badge badge-light-info fs-6"><?= e($ticket['status']) ?></span>
        </div>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Priority</div>
          <span class="badge badge-light-<?= ($ticket['priority'] ?? '') === 'urgent' ? 'danger' : 'primary' ?>">
            <?= e($ticket['priority'] ?? '') ?>
          </span>
        </div>
        
        <?php if ($ticket['vendor_name']): ?>
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Assigned Artisan</div>
          <div><?= e($ticket['vendor_name']) ?></div>
          <?php if ($ticket['specialization']): ?>
            <div class="text-gray-600 fs-8"><?= e($ticket['specialization']) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Created</div>
          <div><?= e(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?></div>
        </div>
        
        <?php if ($ticket['resolved_at']): ?>
        <div class="mb-4">
          <div class="text-gray-600 fs-7">Resolved</div>
          <div><?= e(date('M j, Y g:i A', strtotime($ticket['resolved_at']))) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Photos Section -->
    <?php if (!empty($ticket['before_photo']) || !empty($ticket['after_photo']) || !empty($ticket['completion_photo'])): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Photos</div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <?php if (!empty($ticket['before_photo'])): ?>
          <div class="col-6">
            <div class="text-gray-600 fs-8 mb-1">Before</div>
            <img src="<?= app_url('uploads/' . e($ticket['before_photo'])) ?>" alt="Before" class="img-thumbnail img-zoomable" style="width: 100%; height: 100px; object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-src="<?= app_url('uploads/' . e($ticket['before_photo'])) ?>" data-image-title="Before Photo">
          </div>
          <?php endif; ?>
          
          <?php if (!empty($ticket['after_photo'])): ?>
          <div class="col-6">
            <div class="text-gray-600 fs-8 mb-1">After</div>
            <img src="<?= app_url('uploads/' . e($ticket['after_photo'])) ?>" alt="After" class="img-thumbnail img-zoomable" style="width: 100%; height: 100px; object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-src="<?= app_url('uploads/' . e($ticket['after_photo'])) ?>" data-image-title="After Photo">
          </div>
          <?php endif; ?>
          
          <?php if (!empty($ticket['completion_photo'])): ?>
          <div class="col-6">
            <div class="text-gray-600 fs-8 mb-1">Completion</div>
            <img src="<?= app_url('uploads/' . e($ticket['completion_photo'])) ?>" alt="Completion" class="img-thumbnail img-zoomable" style="width: 100%; height: 100px; object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-src="<?= app_url('uploads/' . e($ticket['completion_photo'])) ?>" data-image-title="Completion Photo">
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  
  <!-- Progress Timeline -->
  <div class="col-12 col-xl-8">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Progress Timeline</div>
      </div>
      <div class="card-body">
        <div class="timeline">
          <?php foreach ($timelineEvents as $index => $event): ?>
          <div class="timeline-item">
            <div class="timeline-line"></div>
            <div class="timeline-icon">
              <i class="<?= $event['icon'] ?> text-<?= $event['color'] ?>"></i>
            </div>
            <div class="timeline-content">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="mb-1"><?= e($event['title']) ?></h6>
                <?php if ($event['status'] === 'completed' && $event['date']): ?>
                  <small class="text-muted"><?= e(date('M j, g:i A', strtotime($event['date']))) ?></small>
                <?php elseif ($event['status'] === 'completed'): ?>
                  <span class="badge badge-light-success">Completed</span>
                <?php else: ?>
                  <span class="badge badge-light-warning">Pending</span>
                <?php endif; ?>
              </div>
              <p class="text-gray-700 mb-0"><?= e($event['description']) ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    
    <!-- Progress Updates -->
    <?php if (!empty($progressUpdates)): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Recent Updates</div>
      </div>
      <div class="card-body">
        <?php foreach ($progressUpdates as $update): ?>
        <div class="border-start border-3 border-primary ps-4 mb-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <strong><?= e($update['first_name'] . ' ' . $update['last_name']) ?></strong>
              <span class="text-gray-600 ms-2">(<?= e($update['role']) ?>)</span>
            </div>
            <small class="text-muted"><?= e(date('M j, g:i A', strtotime($update['created_at']))) ?></small>
          </div>
          
          <?php if ($update['status_from'] && $update['status_to']): ?>
          <div class="mb-2">
            <span class="badge badge-light"><?= e($update['status_from']) ?></span>
            <i class="fas fa-arrow-right text-gray-500 mx-2"></i>
            <span class="badge badge-light-primary"><?= e($update['status_to']) ?></span>
          </div>
          <?php endif; ?>
          
          <?php if ($update['notes']): ?>
          <div class="text-gray-800"><?= nl2br(e($update['notes'])) ?></div>
          <?php endif; ?>
          
          <?php if ($update['photos']): ?>
          <?php 
          $photos = json_decode($update['photos'], true);
          if (is_array($photos) && !empty($photos)):
          ?>
          <div class="row g-2 mt-2">
            <?php foreach ($photos as $photo): ?>
            <div class="col-3">
              <img src="<?= app_url('uploads/' . e($photo)) ?>" alt="Update photo" class="img-thumbnail img-zoomable" style="width: 100%; height: 80px; object-fit: cover; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#imageModal" data-image-src="<?= app_url('uploads/' . e($photo)) ?>" data-image-title="Progress Update Photo">
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    
    <!-- Action Buttons -->
    <div class="card mt-6">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3">
          <?php if (in_array($ticket['status'], ['work_completed', 'tenant_review'])): ?>
            <a href="maintenance_confirmation.php?ticket_id=<?= (int)$ticket['id'] ?>" class="btn btn-success">
              <i class="fas fa-clipboard-check me-2"></i>Confirm Work Completion
            </a>
          <?php endif; ?>
          
          <a href="maintenance.php" class="btn btn-light">
            <i class="fas fa-list me-2"></i>View All Tickets
          </a>
          
          <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print me-2"></i>Print Progress
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Image Zoom Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="imageModalTitle">Image Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
    
    // Handle modal show event for better image loading
    imageModal.addEventListener('show.bs.modal', function() {
        // Ensure image is loaded before showing
        modalImage.onload = function() {
            // Image loaded successfully
        };
        
        modalImage.onerror = function() {
            modalImage.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"><rect width="400" height="300" fill="%23f8f9fa"/><text x="200" y="150" font-family="Arial" font-size="20" text-anchor="middle" fill="%236c757d">Image not found</text></svg>';
            imageModalTitle.textContent = 'Image Unavailable';
        };
    });
    
    // Handle ESC key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && imageModal.classList.contains('show')) {
            const modal = bootstrap.Modal.getInstance(imageModal);
            if (modal) {
                modal.hide();
            }
        }
    });
});
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>