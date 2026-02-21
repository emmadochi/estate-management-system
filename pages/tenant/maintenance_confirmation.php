<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$db = db();
$method = request_method();

$pageTitle = 'Maintenance Job Confirmation – EstatePro Tenant';
$pageHeading = 'Confirm Maintenance Work';

// Add navigation context
$ticketId = (int)(get_param('ticket_id', 0) ?? 0);

$tenantId = (int)$tenant['id'];

// Get tickets that need tenant confirmation
$tickets = $db->fetchAll(
    "SELECT 
        mt.id, mt.ticket_number, mt.title, mt.description, mt.status,
        mt.vendor_id, mt.completion_notes, mt.completion_photo,
        mt.work_quality_rating, mt.actual_completion_date,
        v.name as vendor_name,
        tc.confirmation_status, tc.quality_rating, tc.feedback,
        tc.confirmation_notes, tc.confirmation_photo,
        tc.confirmed_at
     FROM maintenance_tickets mt
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     LEFT JOIN tenant_confirmations tc ON tc.ticket_id = mt.id AND tc.tenant_id = ?
     WHERE mt.tenant_id = ? 
     AND mt.status IN ('work_completed', 'tenant_review', 'admin_review')
     ORDER BY mt.updated_at DESC",
    [$tenantId, $tenantId]
);

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
    
    if ($ticketId > 0) {
        // Verify ticket belongs to tenant
        $ticket = $db->fetchOne(
            "SELECT id, status FROM maintenance_tickets WHERE id = ? AND tenant_id = ?",
            [$ticketId, $tenantId]
        );
        
        if (!$ticket) {
            flash_set('error', 'Ticket not found.');
            redirect('maintenance_confirmation.php');
        }
        
        if ($action === 'confirm_completion') {
            $qualityRating = (int)(post_param('quality_rating', 0) ?? 0);
            $feedback = trim((string)post_param('feedback', ''));
            $confirmationNotes = trim((string)post_param('confirmation_notes', ''));
            
            if ($qualityRating < 1 || $qualityRating > 5) {
                flash_set('error', 'Please provide a quality rating between 1-5 stars.');
                redirect('maintenance_confirmation.php?ticket_id=' . $ticketId);
            }
            
            try {
                // Check if confirmation already exists
                $existing = $db->fetchOne(
                    "SELECT id FROM tenant_confirmations WHERE ticket_id = ? AND tenant_id = ?",
                    [$ticketId, $tenantId]
                );
                
                if ($existing) {
                    // Update existing confirmation
                    $db->execute(
                        "UPDATE tenant_confirmations 
                         SET confirmation_status = 'confirmed',
                             quality_rating = ?,
                             feedback = ?,
                             confirmation_notes = ?,
                             confirmed_by = ?,
                             confirmed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = ?",
                        [$qualityRating, $feedback, $confirmationNotes, current_user_id(), (int)$existing['id']]
                    );
                } else {
                    // Create new confirmation
                    $db->insert(
                        "INSERT INTO tenant_confirmations 
                         (ticket_id, tenant_id, confirmation_status, quality_rating, feedback, confirmation_notes, confirmed_by, confirmed_at)
                         VALUES (?, ?, 'confirmed', ?, ?, ?, ?, NOW())",
                        [$ticketId, $tenantId, $qualityRating, $feedback, $confirmationNotes, current_user_id()]
                    );
                }
                
                // Update ticket status
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET status = 'admin_review',
                         tenant_confirmed = TRUE,
                         tenant_confirmation_date = NOW(),
                         tenant_feedback = ?,
                         work_quality_rating = ?
                     WHERE id = ?",
                    [$feedback, $qualityRating, $ticketId]
                );
                
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes)
                     VALUES (?, ?, ?, 'admin_review', 'Tenant confirmed work completion')",
                    [$ticketId, current_user_id(), $ticket['status']]
                );
                
                flash_set('success', 'Work confirmation submitted successfully. Thank you for your feedback!');
                redirect('maintenance_confirmation.php');
                
            } catch (Throwable $e) {
                flash_set('error', 'Error submitting confirmation: ' . $e->getMessage());
                redirect('maintenance_confirmation.php?ticket_id=' . $ticketId);
            }
        }
        
        if ($action === 'request_revision') {
            $revisionReason = trim((string)post_param('revision_reason', ''));
            
            if ($revisionReason === '') {
                flash_set('error', 'Please provide a reason for requesting revision.');
                redirect('maintenance_confirmation.php?ticket_id=' . $ticketId);
            }
            
            try {
                // Check if confirmation already exists
                $existing = $db->fetchOne(
                    "SELECT id FROM tenant_confirmations WHERE ticket_id = ? AND tenant_id = ?",
                    [$ticketId, $tenantId]
                );
                
                if ($existing) {
                    $db->execute(
                        "UPDATE tenant_confirmations 
                         SET confirmation_status = 'revision_requested',
                             feedback = ?,
                             confirmed_by = ?,
                             confirmed_at = NOW(),
                             updated_at = NOW()
                         WHERE id = ?",
                        [$revisionReason, current_user_id(), (int)$existing['id']]
                    );
                } else {
                    $db->insert(
                        "INSERT INTO tenant_confirmations 
                         (ticket_id, tenant_id, confirmation_status, feedback, confirmed_by, confirmed_at)
                         VALUES (?, ?, 'revision_requested', ?, ?, NOW())",
                        [$ticketId, $tenantId, $revisionReason, current_user_id()]
                    );
                }
                
                // Update ticket status back to in_progress
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET status = 'in_progress'
                     WHERE id = ?",
                    [$ticketId]
                );
                
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes)
                     VALUES (?, ?, ?, 'in_progress', 'Tenant requested revision: ' || ?)",
                    [$ticketId, current_user_id(), $ticket['status'], $revisionReason]
                );
                
                flash_set('success', 'Revision request submitted. The artisan will address your concerns.');
                redirect('maintenance_confirmation.php');
                
            } catch (Throwable $e) {
                flash_set('error', 'Error submitting revision request: ' . $e->getMessage());
                redirect('maintenance_confirmation.php?ticket_id=' . $ticketId);
            }
        }
    }
}

$ticketId = (int)(get_param('ticket_id', 0) ?? 0);
$selectedTicket = null;

if ($ticketId > 0) {
    $selectedTicket = $db->fetchOne(
        "SELECT 
            mt.*, v.name as vendor_name, v.specialization,
            tc.confirmation_status, tc.quality_rating, tc.feedback
         FROM maintenance_tickets mt
         LEFT JOIN vendors v ON v.id = mt.vendor_id
         LEFT JOIN tenant_confirmations tc ON tc.ticket_id = mt.id AND tc.tenant_id = ?
         WHERE mt.id = ? AND mt.tenant_id = ?",
        [$tenantId, $ticketId, $tenantId]
    );
    
    if (!$selectedTicket) {
        flash_set('error', 'Ticket not found.');
        redirect('maintenance_confirmation.php');
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Work Confirmation</h1>
    <div class="text-gray-600">Review and confirm completed maintenance work</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <a class="btn btn-light" href="maintenance.php">
      <i class="fas fa-list me-2"></i>All Tickets
    </a>
    <?php if ($ticketId > 0): ?>
    <a class="btn btn-light-primary" href="ticket_progress.php?id=<?= $ticketId ?>">
      <i class="fas fa-chart-line me-2"></i>View Progress
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($ticketId > 0 && $selectedTicket): ?>
<!-- Detailed Confirmation Form -->
<div class="row g-6">
  <div class="col-12">
    <div class="card mb-6">
      <div class="card-header bg-primary text-white">
        <div class="card-title fw-bold">
          <i class="fas fa-clipboard-check me-2"></i>
          Confirm Work Completion - <?= e($selectedTicket['ticket_number']) ?>
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
              <div class="text-gray-600 fs-7">Artisan</div>
              <div class="fw-bold"><?= e($selectedTicket['vendor_name'] ?? 'Not assigned') ?></div>
              <?php if (!empty($selectedTicket['specialization'])): ?>
                <div class="text-gray-600 fs-8"><?= e($selectedTicket['specialization']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Status</div>
              <div class="fw-bold">
                <span class="badge badge-<?= 
                  $selectedTicket['status'] === 'work_completed' ? 'success' : 
                  ($selectedTicket['status'] === 'tenant_review' ? 'warning' : 'info') 
                ?>"><?= e(str_replace('_', ' ', $selectedTicket['status'])) ?></span>
              </div>
            </div>
            <div class="mb-3">
              <div class="text-gray-600 fs-7">Completion Date</div>
              <div class="fw-bold"><?= e($selectedTicket['actual_completion_date'] ? date('M j, Y H:i', strtotime($selectedTicket['actual_completion_date'])) : 'Not completed') ?></div>
            </div>
            <?php if (!empty($selectedTicket['completion_notes'])): ?>
              <div class="mb-3">
                <div class="text-gray-600 fs-7">Artisan Notes</div>
                <div class="text-gray-800 bg-light p-3 rounded"><?= nl2br(e($selectedTicket['completion_notes'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        
        <?php if (!empty($selectedTicket['completion_photo'])): ?>
          <div class="row g-4 mb-4">
            <div class="col-12">
              <div class="text-gray-600 fs-7 mb-2">Completion Photo</div>
              <img src="../../uploads/<?= e($selectedTicket['completion_photo']) ?>" 
                   alt="Completion Photo" 
                   class="img-fluid rounded border"
                   style="max-height: 300px; object-fit: cover;">
            </div>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($selectedTicket['confirmation_status']) && $selectedTicket['confirmation_status'] !== 'pending'): ?>
          <div class="alert alert-info mb-4">
            <h6 class="alert-heading">Previous Confirmation</h6>
            <p class="mb-1"><strong>Status:</strong> <?= e(str_replace('_', ' ', $selectedTicket['confirmation_status'])) ?></p>
            <?php if (!empty($selectedTicket['quality_rating'])): ?>
              <p class="mb-1"><strong>Rating:</strong> <?= str_repeat('★', (int)$selectedTicket['quality_rating']) ?><?= str_repeat('☆', 5 - (int)$selectedTicket['quality_rating']) ?></p>
            <?php endif; ?>
            <?php if (!empty($selectedTicket['feedback'])): ?>
              <p class="mb-0"><strong>Feedback:</strong> <?= e($selectedTicket['feedback']) ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        
        <form method="post" action="maintenance_confirmation.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="confirm_completion">
          <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
          
          <div class="row g-4 mb-4">
            <div class="col-md-6">
              <label class="form-label required fw-bold">Work Quality Rating</label>
              <div class="rating">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <label class="btn btn-light btn-sm me-1 <?= ((int)($selectedTicket['quality_rating'] ?? 0) >= $i) ? 'active btn-warning' : '' ?>">
                    <input type="radio" name="quality_rating" value="<?= $i ?>" <?= ((int)($selectedTicket['quality_rating'] ?? 0) === $i) ? 'checked' : '' ?> required>
                    <i class="fas fa-star"></i>
                  </label>
                <?php endfor; ?>
              </div>
              <div class="text-muted fs-8 mt-1">1 star = Poor, 5 stars = Excellent</div>
            </div>
            
            <div class="col-md-6">
              <label class="form-label fw-bold">Confirmation Notes</label>
              <textarea class="form-control" name="confirmation_notes" rows="3" 
                        placeholder="Any additional notes about the work completion..."><?= e($selectedTicket['confirmation_notes'] ?? '') ?></textarea>
            </div>
          </div>
          
          <div class="mb-4">
            <label class="form-label fw-bold">Detailed Feedback</label>
            <textarea class="form-control" name="feedback" rows="4" 
                      placeholder="Please provide detailed feedback about the work quality, professionalism, and any issues you noticed..." 
                      required><?= e($selectedTicket['feedback'] ?? '') ?></textarea>
          </div>
          
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success btn-lg">
              <i class="fas fa-check-circle me-2"></i>Confirm Completion
            </button>
            <button type="button" class="btn btn-warning btn-lg" data-bs-toggle="modal" data-bs-target="#revisionModal">
              <i class="fas fa-tools me-2"></i>Request Revision
            </button>
            <a href="maintenance_confirmation.php" class="btn btn-light btn-lg">
              <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Revision Request Modal -->
<div class="modal fade" id="revisionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="maintenance_confirmation.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_revision">
        <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
        
        <div class="modal-header bg-warning">
          <h5 class="modal-title text-dark">Request Work Revision</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <p class="text-dark">Requesting a revision will send the ticket back to the artisan for corrections.</p>
            <p class="fw-bold text-danger">This should only be used for significant issues with the work.</p>
          </div>
          
          <div class="mb-3">
            <label class="form-label required text-dark">Reason for Revision</label>
            <textarea class="form-control" name="revision_reason" rows="4" 
                      placeholder="Please explain specifically what needs to be corrected..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Request Revision</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Ticket List View -->
<?php if (!$tickets): ?>
  <div class="card">
    <div class="card-body text-center py-10">
      <div class="symbol symbol-100px mx-auto mb-5">
        <i class="fas fa-clipboard-check text-muted fs-1"></i>
      </div>
      <h4 class="text-gray-700">No work to confirm</h4>
      <p class="text-gray-500">There are no maintenance jobs requiring your confirmation at this time.</p>
      <a href="maintenance.php" class="btn btn-primary">View All Tickets</a>
    </div>
  </div>
<?php else: ?>
  <div class="row g-6">
    <?php foreach ($tickets as $ticket): ?>
      <div class="col-12">
        <div class="card mb-4 border-<?= 
          $ticket['confirmation_status'] === 'confirmed' ? 'success' : 
          ($ticket['confirmation_status'] === 'revision_requested' ? 'warning' : 'primary') ?>">
          <div class="card-header <?= 
            $ticket['confirmation_status'] === 'confirmed' ? 'bg-success' : 
            ($ticket['confirmation_status'] === 'revision_requested' ? 'bg-warning' : 'bg-primary') ?> text-white">
            <div class="card-title fw-bold d-flex justify-content-between align-items-center">
              <div>
                <i class="fas fa-<?= $ticket['confirmation_status'] === 'confirmed' ? 'check-circle' : ($ticket['confirmation_status'] === 'revision_requested' ? 'exclamation-triangle' : 'clock') ?> me-2"></i>
                <?= e($ticket['ticket_number']) ?> - <?= e($ticket['title']) ?>
              </div>
              <div>
                <?php if (!empty($ticket['confirmation_status'])): ?>
                  <span class="badge badge-light"><?= e(str_replace('_', ' ', $ticket['confirmation_status'])) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-4">
              <div class="col-md-8">
                <div class="mb-2">
                  <span class="text-gray-600">Status:</span>
                  <span class="fw-bold"><?= e(str_replace('_', ' ', $ticket['status'])) ?></span>
                </div>
                <div class="mb-2">
                  <span class="text-gray-600">Artisan:</span>
                  <span class="fw-bold"><?= e($ticket['vendor_name'] ?? 'Not assigned') ?></span>
                </div>
                <div class="mb-3">
                  <span class="text-gray-600">Description:</span>
                  <div class="text-gray-800 mt-1"><?= nl2br(e($ticket['description'])) ?></div>
                </div>
                
                <?php if (!empty($ticket['completion_notes'])): ?>
                  <div class="mb-3 p-3 bg-light rounded">
                    <div class="text-gray-600 fs-8 mb-1">Artisan Notes:</div>
                    <div class="text-gray-800"><?= nl2br(e($ticket['completion_notes'])) ?></div>
                  </div>
                <?php endif; ?>
                
                <?php if (!empty($ticket['feedback'])): ?>
                  <div class="mb-3 p-3 bg-info bg-opacity-10 rounded">
                    <div class="text-gray-600 fs-8 mb-1">Your Feedback:</div>
                    <div class="text-gray-800"><?= nl2br(e($ticket['feedback'])) ?></div>
                    <?php if (!empty($ticket['quality_rating'])): ?>
                      <div class="mt-2">
                        <?= str_repeat('★', (int)$ticket['quality_rating']) ?><?= str_repeat('☆', 5 - (int)$ticket['quality_rating']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              
              <div class="col-md-4">
                <div class="d-flex flex-column h-100">
                  <?php if (!empty($ticket['completion_photo'])): ?>
                    <div class="mb-3">
                      <div class="text-gray-600 fs-8 mb-1">Completion Photo</div>
                      <img src="../../uploads/<?= e($ticket['completion_photo']) ?>" 
                           alt="Completion" 
                           class="img-thumbnail" 
                           style="max-width: 150px; max-height: 120px;">
                    </div>
                  <?php endif; ?>
                  
                  <div class="mt-auto">
                    <?php if (empty($ticket['confirmation_status']) || $ticket['confirmation_status'] === 'pending'): ?>
                      <a href="maintenance_confirmation.php?ticket_id=<?= (int)$ticket['id'] ?>" 
                         class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-clipboard-check me-2"></i>Confirm Work
                      </a>
                    <?php elseif ($ticket['confirmation_status'] === 'revision_requested'): ?>
                      <a href="maintenance_confirmation.php?ticket_id=<?= (int)$ticket['id'] ?>" 
                         class="btn btn-warning w-100 mb-2">
                        <i class="fas fa-edit me-2"></i>Update Confirmation
                      </a>
                    <?php else: ?>
                      <div class="text-center text-success">
                        <i class="fas fa-check-circle fs-2 mb-2"></i>
                        <div class="fw-bold">Confirmed</div>
                        <div class="text-muted fs-8">
                          <?= e($ticket['confirmed_at'] ? date('M j, Y', strtotime($ticket['confirmed_at'])) : '') ?>
                        </div>
                      </div>
                    <?php endif; ?>
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