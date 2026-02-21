<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Maintenance Progress Dashboard – EstatePro Admin';
$db = db();

$requestedEstateId = (int)(get_param('estate_id', 0) ?? 0);
$estates = estates_for_current_user();

if (!$estates) {
    if (is_super_admin()) {
        flash_set('warning', 'Create an estate first.');
        redirect('estates.php');
    }
    http_response_code(403);
    echo 'No estate access assigned to your account. Please contact an administrator.';
    exit;
}

$estateId = normalize_estate_id($requestedEstateId);

// Get dashboard statistics
$stats = $db->fetchOne(
    "SELECT 
        COUNT(CASE WHEN status = 'requested' THEN 1 END) as requested_count,
        COUNT(CASE WHEN status = 'assigned' THEN 1 END) as assigned_count,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
        COUNT(CASE WHEN status = 'work_completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN status = 'tenant_review' THEN 1 END) as review_count,
        COUNT(CASE WHEN status = 'admin_review' THEN 1 END) as admin_review_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_count,
        COUNT(*) as total_count,
        COALESCE(AVG(work_quality_rating), 0) as avg_quality_rating
     FROM maintenance_tickets 
     WHERE estate_id = ?",
    [$estateId]
);

// Get recent progress updates
$recentUpdates = $db->fetchAll(
    "SELECT 
        mpu.*, mt.ticket_number, mt.title,
        u.first_name, u.last_name
     FROM maintenance_progress_updates mpu
     INNER JOIN maintenance_tickets mt ON mt.id = mpu.ticket_id
     INNER JOIN users u ON u.id = mpu.updated_by
     WHERE mt.estate_id = ?
     ORDER BY mpu.created_at DESC
     LIMIT 10",
    [$estateId]
);

// Get tickets needing admin review
$reviewTickets = $db->fetchAll(
    "SELECT 
        mt.id, mt.ticket_number, mt.title, mt.status,
        mt.tenant_feedback, mt.work_quality_rating,
        mt.actual_completion_date,
        t.first_name as tenant_first, t.last_name as tenant_last,
        v.name as vendor_name,
        tc.confirmation_status, tc.quality_rating, tc.feedback
     FROM maintenance_tickets mt
     INNER JOIN tenants tn ON tn.id = mt.tenant_id
     INNER JOIN users t ON t.id = tn.user_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     LEFT JOIN tenant_confirmations tc ON tc.ticket_id = mt.id AND tc.tenant_id = tn.id
     WHERE mt.estate_id = ? 
     AND mt.status = 'admin_review'
     ORDER BY mt.updated_at DESC
     LIMIT 10",
    [$estateId]
);

// Get overdue tickets
$overdueTickets = $db->fetchAll(
    "SELECT 
        mt.id, mt.ticket_number, mt.title, mt.status,
        mt.priority, mt.created_at,
        mt.expected_completion_date,
        t.first_name as tenant_first, t.last_name as tenant_last,
        v.name as vendor_name
     FROM maintenance_tickets mt
     INNER JOIN tenants tn ON tn.id = mt.tenant_id
     INNER JOIN users t ON t.id = tn.user_id
     LEFT JOIN vendors v ON v.id = mt.vendor_id
     WHERE mt.estate_id = ? 
     AND mt.status IN ('requested', 'assigned', 'in_progress', 'work_completed', 'tenant_review')
     AND (
         (mt.expected_completion_date IS NOT NULL AND mt.expected_completion_date < CURDATE())
         OR 
         (mt.expected_completion_date IS NULL AND mt.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
     )
     ORDER BY mt.priority DESC, mt.created_at ASC
     LIMIT 10",
    [$estateId]
);

$method = request_method();
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    $ticketId = (int)(post_param('ticket_id', 0) ?? 0);
    
    if ($ticketId > 0 && $action === 'approve_payment') {
        try {
            // Get ticket details
            $ticket = $db->fetchOne(
                "SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?",
                [$ticketId, $estateId]
            );
            
            if ($ticket) {
                // Update ticket status to paid
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET status = 'paid', 
                         updated_at = NOW()
                     WHERE id = ?",
                    [$ticketId]
                );
                
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes)
                     VALUES (?, ?, ?, 'paid', 'Admin approved payment and closed ticket')",
                    [$ticketId, current_user_id(), $ticket['status']]
                );
                
                // Create audit log
                audit_log('approve', 'maintenance_payment', $ticketId, [
                    'ticket_id' => $ticketId,
                    'status' => 'paid'
                ], $estateId);
                
                flash_set('success', 'Payment approved and ticket closed successfully.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Error approving payment: ' . $e->getMessage());
        }
        redirect('maintenance_progress_dashboard.php?estate_id=' . $estateId);
    }
    
    if ($ticketId > 0 && $action === 'request_revision') {
        $revisionNotes = trim((string)post_param('revision_notes', ''));
        
        try {
            $ticket = $db->fetchOne(
                "SELECT * FROM maintenance_tickets WHERE id = ? AND estate_id = ?",
                [$ticketId, $estateId]
            );
            
            if ($ticket) {
                // Update ticket status back to in_progress
                $db->execute(
                    "UPDATE maintenance_tickets 
                     SET status = 'in_progress',
                         updated_at = NOW()
                     WHERE id = ?",
                    [$ticketId]
                );
                
                // Create progress update
                $db->insert(
                    "INSERT INTO maintenance_progress_updates 
                     (ticket_id, updated_by, status_from, status_to, notes)
                     VALUES (?, ?, ?, 'in_progress', ?)",
                    [$ticketId, current_user_id(), $ticket['status'], 'Admin requested revision: ' . $revisionNotes]
                );
                
                flash_set('success', 'Revision requested. Ticket sent back to artisan.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Error requesting revision: ' . $e->getMessage());
        }
        redirect('maintenance_progress_dashboard.php?estate_id=' . $estateId);
    }
}

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Maintenance Progress Dashboard</h1>
    <div class="text-gray-600">Monitor and manage maintenance workflow progress</div>
  </div>
  <div class="d-flex gap-2 mt-4 mt-md-0">
    <select class="form-select" onchange="location.href='maintenance_progress_dashboard.php?estate_id=' + this.value">
      <?php foreach ($estates as $e): ?>
        <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $estateId ? 'selected' : '' ?>>
          <?= e($e['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <a class="btn btn-sm btn-primary" href="maintenance.php?estate_id=<?= $estateId ?>">
      <i class="fas fa-tasks me-2"></i>All Tickets
    </a>
  </div>
</div>

<!-- Statistics Cards -->
<div class="row g-6 mb-6">
  <div class="col-md-3">
    <div class="card bg-light-primary hover-elevate-up">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-gray-600 fs-7">Requested</div>
            <div class="fs-2hx fw-bold text-primary"><?= (int)($stats['requested_count'] ?? 0) ?></div>
          </div>
          <i class="fas fa-file-alt text-primary fs-2x"></i>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-md-3">
    <div class="card bg-light-warning hover-elevate-up">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-gray-600 fs-7">In Progress</div>
            <div class="fs-2hx fw-bold text-warning"><?= (int)($stats['in_progress_count'] ?? 0) ?></div>
          </div>
          <i class="fas fa-tools text-warning fs-2x"></i>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-md-3">
    <div class="card bg-light-info hover-elevate-up">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-gray-600 fs-7">Awaiting Review</div>
            <div class="fs-2hx fw-bold text-info"><?= (int)($stats['review_count'] ?? 0) + (int)($stats['admin_review_count'] ?? 0) ?></div>
          </div>
          <i class="fas fa-clipboard-check text-info fs-2x"></i>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-md-3">
    <div class="card bg-light-success hover-elevate-up">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-gray-600 fs-7">Avg Quality Rating</div>
            <div class="fs-2hx fw-bold text-success">
              <?= number_format((float)($stats['avg_quality_rating'] ?? 0), 1) ?>/5
            </div>
          </div>
          <i class="fas fa-star text-success fs-2x"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-6">
  <!-- Admin Review Queue -->
  <div class="col-12 col-xl-6">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-user-check me-2 text-primary"></i>
          Awaiting Admin Review
          <span class="badge badge-primary ms-2"><?= count($reviewTickets) ?></span>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$reviewTickets): ?>
          <div class="text-center py-5">
            <i class="fas fa-check-circle text-success fs-2x mb-3"></i>
            <div class="text-gray-600">No tickets awaiting review</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-4">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Ticket</th>
                  <th>Tenant</th>
                  <th>Artisan</th>
                  <th>Rating</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($reviewTickets as $ticket): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center">
                        <div class="symbol symbol-40px symbol-circle me-3">
                          <i class="fas fa-ticket-alt text-primary"></i>
                        </div>
                        <div>
                          <div class="fw-bold text-gray-900"><?= e($ticket['ticket_number']) ?></div>
                          <div class="text-gray-600 fs-7"><?= e($ticket['title']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="text-gray-700"><?= e($ticket['tenant_first'] . ' ' . $ticket['tenant_last']) ?></div>
                    </td>
                    <td>
                      <div class="text-gray-700"><?= e($ticket['vendor_name'] ?? 'Unassigned') ?></div>
                    </td>
                    <td>
                      <?php if (!empty($ticket['quality_rating'])): ?>
                        <div class="text-warning">
                          <?= str_repeat('★', (int)$ticket['quality_rating']) ?>
                        </div>
                      <?php else: ?>
                        <div class="text-muted">-</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-light-primary" 
                                onclick="showReviewModal(<?= (int)$ticket['id'] ?>, '<?= e($ticket['ticket_number']) ?>')">
                          <i class="fas fa-check me-1"></i>Review
                        </button>
                        <a href="maintenance.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$ticket['id'] ?>" 
                           class="btn btn-sm btn-light">
                          <i class="fas fa-edit"></i>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <!-- Overdue Tickets -->
  <div class="col-12 col-xl-6">
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-exclamation-triangle me-2 text-danger"></i>
          Overdue Tickets
          <span class="badge badge-danger ms-2"><?= count($overdueTickets) ?></span>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$overdueTickets): ?>
          <div class="text-center py-5">
            <i class="fas fa-clock text-success fs-2x mb-3"></i>
            <div class="text-gray-600">No overdue tickets</div>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle gs-0 gy-4">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Ticket</th>
                  <th>Priority</th>
                  <th>Days Overdue</th>
                  <th>Artisan</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($overdueTickets as $ticket): ?>
                  <tr>
                    <td>
                      <div>
                        <div class="fw-bold text-gray-900"><?= e($ticket['ticket_number']) ?></div>
                        <div class="text-gray-600 fs-7"><?= e($ticket['title']) ?></div>
                      </div>
                    </td>
                    <td>
                      <span class="badge badge-<?= 
                        $ticket['priority'] === 'urgent' ? 'danger' : 
                        ($ticket['priority'] === 'high' ? 'warning' : 'info') 
                      ?>"><?= e($ticket['priority']) ?></span>
                    </td>
                    <td>
                      <?php 
                      $daysOverdue = 0;
                      if (!empty($ticket['expected_completion_date'])) {
                          $daysOverdue = max(0, (strtotime('now') - strtotime($ticket['expected_completion_date'])) / (60 * 60 * 24));
                      } else {
                          $daysOverdue = max(0, (strtotime('now') - strtotime($ticket['created_at'])) / (60 * 60 * 24) - 7);
                      }
                      ?>
                      <div class="fw-bold text-danger"><?= number_format($daysOverdue, 0) ?> days</div>
                    </td>
                    <td>
                      <div class="text-gray-700"><?= e($ticket['vendor_name'] ?? 'Unassigned') ?></div>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <a href="maintenance.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$ticket['id'] ?>" 
                           class="btn btn-sm btn-light-primary">
                          <i class="fas fa-edit me-1"></i>Manage
                        </a>
                        <button type="button" class="btn btn-sm btn-light-warning" 
                                onclick="showReminderModal(<?= (int)$ticket['id'] ?>, '<?= e($ticket['ticket_number']) ?>')">
                          <i class="fas fa-bell"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Recent Activity -->
<div class="row g-6">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold d-flex align-items-center">
          <i class="fas fa-history me-2 text-muted"></i>
          Recent Activity
        </div>
      </div>
      <div class="card-body">
        <?php if (!$recentUpdates): ?>
          <div class="text-center py-5">
            <i class="fas fa-history text-muted fs-2x mb-3"></i>
            <div class="text-gray-600">No recent activity</div>
          </div>
        <?php else: ?>
          <div class="timeline timeline-border-dashed">
            <?php foreach ($recentUpdates as $update): ?>
              <div class="timeline-item">
                <div class="timeline-line"></div>
                <div class="timeline-icon">
                  <i class="fas fa-circle text-primary"></i>
                </div>
                <div class="timeline-content mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-bold text-gray-900">
                      <?= e($update['first_name'] . ' ' . $update['last_name']) ?>
                    </div>
                    <div class="text-gray-600 fs-8"><?= e(date('M j, Y H:i', strtotime($update['created_at']))) ?></div>
                  </div>
                  <div class="text-gray-700">
                    <div class="mb-1">
                      <span class="badge badge-light"><?= e($update['ticket_number']) ?></span>
                      <span class="mx-2">→</span>
                      <span class="badge badge-light-primary"><?= e(str_replace('_', ' ', $update['status_to'])) ?></span>
                    </div>
                    <?php if (!empty($update['notes'])): ?>
                      <div><?= e($update['notes']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="maintenance_progress_dashboard.php?estate_id=<?= $estateId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve_payment">
        <input type="hidden" name="ticket_id" id="review_ticket_id">
        
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Admin Review - <span id="review_ticket_number"></span></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-4">
            <p>Review the tenant's feedback and work quality before approving payment.</p>
          </div>
          
          <div class="row g-4">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label fw-bold">Tenant Feedback</label>
                <div class="bg-light p-3 rounded" id="tenant_feedback_display"></div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label fw-bold">Quality Rating</label>
                <div class="fs-2 text-warning" id="quality_rating_display"></div>
              </div>
            </div>
          </div>
          
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Approving payment will:</strong>
            <ul class="mb-0 mt-2">
              <li>Mark the ticket as paid</li>
              <li>Close the maintenance request</li>
              <li>Trigger payment to the artisan</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Approve Payment</button>
          <button type="button" class="btn btn-warning" onclick="requestRevision()">
            <i class="fas fa-tools me-1"></i>Request Revision
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showReviewModal(ticketId, ticketNumber) {
    // In a real implementation, you would fetch ticket details via AJAX
    document.getElementById('review_ticket_id').value = ticketId;
    document.getElementById('review_ticket_number').textContent = ticketNumber;
    // Set placeholder content - in real app, populate with actual data
    document.getElementById('tenant_feedback_display').textContent = 'Loading feedback...';
    document.getElementById('quality_rating_display').textContent = '★★★★☆';
    new bootstrap.Modal(document.getElementById('reviewModal')).show();
}

function requestRevision() {
    const ticketId = document.getElementById('review_ticket_id').value;
    const ticketNumber = document.getElementById('review_ticket_number').textContent;
    
    // Hide review modal and show revision modal
    bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
    
    // Show revision modal (you would create this)
    alert('Revision request functionality would open a modal to add revision notes for ticket: ' + ticketNumber);
}
</script>

<?php require __DIR__ . '/partials/bottom.php'; ?>