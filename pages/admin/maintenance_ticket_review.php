<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/maintenance_notifications.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'staff']);

$pageTitle = 'Ticket Review – EstatePro';
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

// Get ticket details
$ticket = null;
if ($ticketId > 0) {
    try {
        $ticket = $db->fetchOne(
            "SELECT 
                mt.*, 
                un.unit_number, p.name AS property_name,
                v.name AS vendor_name, u.email AS artisan_email,
                t.emergency_contact_name AS tenant_name,
                -- Progress tracking
                CASE 
                    WHEN mt.status = 'open' THEN 10
                    WHEN mt.status = 'assigned' THEN 25
                    WHEN mt.status = 'accepted' THEN 35
                    WHEN mt.status = 'in_progress' THEN 50
                    WHEN mt.status = 'work_completed' THEN 75
                    WHEN mt.status = 'tenant_review' THEN 80
                    WHEN mt.status = 'admin_review' THEN 85
                    WHEN mt.status = 'payment_pending' THEN 90
                    WHEN mt.status = 'paid' THEN 95
                    WHEN mt.status = 'closed' THEN 100
                    ELSE 0
                END AS progress_percentage
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

if (!$ticket) {
    flash_set('error', 'Ticket not found.');
    redirect('maintenance_reports.php?estate_id=' . $estateId);
}

// Get progress updates
$progressUpdates = [];
try {
    $progressUpdates = $db->fetchAll(
        "SELECT mpu.*, u.first_name, u.last_name, u.role
         FROM maintenance_progress_updates mpu
         INNER JOIN users u ON u.id = mpu.updated_by
         WHERE mpu.ticket_id = ?
         ORDER BY mpu.created_at DESC
         LIMIT 10",
        [$ticketId]
    );
} catch (Throwable $e) {
    // Ignore errors
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Ticket Review</h1>
    <div class="text-gray-600">Review ticket details and progress.</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light" href="maintenance_reports.php?estate_id=<?= (int)$estateId ?>">
      <i class="fas fa-arrow-left me-2"></i>Back to Reports
    </a>
    <?php if (in_array($ticket['status'], ['work_completed', 'tenant_review', 'admin_review'])): ?>
    <a class="btn btn-success" href="maintenance_work_completion_review.php?estate_id=<?= (int)$estateId ?>&ticket_id=<?= (int)$ticket['id'] ?>">
      <i class="fas fa-clipboard-check me-2"></i>Confirm Work
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-6">
  <!-- Ticket Details -->
  <div class="col-12 col-xl-8">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">
          <?= e($ticket['ticket_number']) ?> - <?= e($ticket['title']) ?>
        </div>
        <div class="card-toolbar">
          <span class="badge badge-light-<?= match($ticket['status']) {
              'open' => 'primary',
              'assigned' => 'info',
              'in_progress' => 'primary',
              'work_completed' => 'warning',
              'tenant_review' => 'warning',
              'admin_review' => 'warning',
              'payment_pending' => 'info',
              'paid' => 'success',
              'closed' => 'dark',
              'cancelled' => 'danger',
              default => 'secondary'
          } ?> me-2"><?= e($ticket['status']) ?></span>
          <span class="badge badge-light-<?= $ticket['priority'] === 'urgent' ? 'danger' : 'primary' ?>">
            <?= e($ticket['priority']) ?>
          </span>
        </div>
      </div>
      <div class="card-body">
        <div class="row mb-6">
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Tenant</div>
            <div class="fw-bold"><?= e($ticket['tenant_first'] . ' ' . $ticket['tenant_last']) ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Location</div>
            <div class="fw-bold"><?= e($ticket['property_name']) ?> - <?= e($ticket['unit_number']) ?></div>
          </div>
        </div>
        
        <div class="row mb-6">
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Artisan</div>
            <div class="fw-bold"><?= e($ticket['vendor_name'] ?? 'Unassigned') ?></div>
            <?php if (!empty($ticket['artisan_email'])): ?>
              <div class="text-gray-600 fs-8"><?= e($ticket['artisan_email']) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Cost Information</div>
            <div class="fw-bold">Quoted: ₦<?= number_format((float)($ticket['quoted_cost'] ?? 0), 2) ?></div>
            <div class="text-gray-600">Actual: ₦<?= number_format((float)($ticket['cost'] ?? 0), 2) ?></div>
            <div class="text-gray-600">Paid Status: <?= e($ticket['paid_status'] ?? 'unpaid') ?></div>
          </div>
        </div>
        
        <div class="row mb-6">
          <div class="col-md-6">
            <div class="text-gray-600 fs-7 mb-1">Created</div>
            <div class="fw-bold"><?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?></div>
          </div>
          <div class="col-md-6">
            <?php if ($ticket['resolved_at']): ?>
            <div class="text-gray-600 fs-7 mb-1">Resolved</div>
            <div class="fw-bold"><?= date('M j, Y g:i A', strtotime($ticket['resolved_at'])) ?></div>
            <?php endif; ?>
            <?php if ($ticket['expected_completion_date']): ?>
            <div class="text-gray-600 fs-7 mb-1">Expected Completion</div>
            <div class="fw-bold"><?= date('M j, Y', strtotime($ticket['expected_completion_date'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-2">Description</div>
          <div class="text-gray-800 bg-light p-4 rounded"><?= nl2br(e($ticket['description'])) ?></div>
        </div>
        
        <?php if (!empty($ticket['note'])): ?>
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-2">Work Notes</div>
          <div class="text-gray-800 bg-light p-4 rounded"><?= nl2br(e($ticket['note'])) ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Photos Section -->
        <?php if (!empty($ticket['before_photo']) || !empty($ticket['after_photo']) || !empty($ticket['completion_photo'])): ?>
        <div class="mb-6">
          <div class="text-gray-600 fs-7 mb-3">Photos</div>
          <div class="row g-3">
            <?php if (!empty($ticket['before_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">Before</div>
              <img src="<?= app_url('uploads/' . e($ticket['before_photo'])) ?>" 
                   alt="Before" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($ticket['before_photo'])) ?>" 
                   data-image-title="Before Photo">
            </div>
            <?php endif; ?>
            
            <?php if (!empty($ticket['after_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">After</div>
              <img src="<?= app_url('uploads/' . e($ticket['after_photo'])) ?>" 
                   alt="After" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($ticket['after_photo'])) ?>" 
                   data-image-title="After Photo">
            </div>
            <?php endif; ?>
            
            <?php if (!empty($ticket['completion_photo'])): ?>
            <div class="col-4">
              <div class="text-gray-600 fs-8 mb-1">Completion</div>
              <img src="<?= app_url('uploads/' . e($ticket['completion_photo'])) ?>" 
                   alt="Completion" class="img-thumbnail img-zoomable" 
                   style="width: 100%; height: 120px; object-fit: cover; cursor: pointer;"
                   data-bs-toggle="modal" data-bs-target="#imageModal" 
                   data-image-src="<?= app_url('uploads/' . e($ticket['completion_photo'])) ?>" 
                   data-image-title="Completion Photo">
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Sidebar -->
  <div class="col-12 col-xl-4">
    <!-- Progress Tracker -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Progress Tracker</div>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-center mb-4">
          <div class="flex-grow-1 me-3">
            <div class="progress h-10px">
              <div class="progress-bar bg-<?= $ticket['progress_percentage'] >= 75 ? 'success' : ($ticket['progress_percentage'] >= 50 ? 'warning' : 'primary') ?>" 
                   role="progressbar" 
                   style="width: <?= (int)$ticket['progress_percentage'] ?>%"></div>
            </div>
          </div>
          <span class="fw-bold fs-6"><?= (int)$ticket['progress_percentage'] ?>%</span>
        </div>
        
        <?php if ($ticket['expected_completion_date']): ?>
        <div class="text-center">
          <div class="text-gray-600 fs-7">Expected Completion</div>
          <div class="fw-bold"><?= date('M j, Y', strtotime($ticket['expected_completion_date'])) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Status Timeline -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold">Status Timeline</div>
      </div>
      <div class="card-body">
        <?php
        $timelineEvents = [
            ['status' => 'open', 'title' => 'Ticket Created', 'date' => $ticket['created_at'], 'icon' => 'fas fa-plus-circle', 'color' => 'primary'],
            ['status' => 'assigned', 'title' => 'Artisan Assigned', 'date' => null, 'icon' => 'fas fa-user-plus', 'color' => 'info'],
            ['status' => 'in_progress', 'title' => 'Work Started', 'date' => null, 'icon' => 'fas fa-tools', 'color' => 'primary'],
            ['status' => 'work_completed', 'title' => 'Work Completed', 'date' => $ticket['resolved_at'], 'icon' => 'fas fa-check-circle', 'color' => 'success'],
        ];
        
        foreach ($timelineEvents as $event):
            $isActive = $ticket['status'] === $event['status'];
            $isCompleted = strtotime($ticket['status']) >= strtotime($event['status']) || ($event['date'] && strtotime($event['date']) > 0);
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
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

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