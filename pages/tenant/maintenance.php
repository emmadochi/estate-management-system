<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Maintenance Tickets – EstatePro Tenant';
$pageHeading = 'Maintenance Tickets';
$db = db();
$method = request_method();

// Add custom CSS for progress bars
$GLOBALS['extra_css'] = '<style>
.progress { border-radius: 4px; background-color: #f5f8fa; }
.progress-bar { transition: width 0.6s ease; }
.badge-light-urgent { background-color: #fee2e2; color: #991b1b; }
.badge-light-high { background-color: #fef3c7; color: #92400e; }
.badge-light-medium { background-color: #dbeafe; color: #1e40af; }
.badge-light-low { background-color: #dcfce7; color: #166534; }
</style>';

/**
 * Get progress percentage based on ticket status
 * @param string $status
 * @return int
 */
function get_ticket_progress_percentage($status) {
    $progressMap = [
        'requested' => 10,
        'assigned' => 25,
        'accepted' => 35,
        'in_progress' => 60,
        'work_completed' => 80,
        'tenant_review' => 85,
        'admin_review' => 90,
        'payment_pending' => 95,
        'paid' => 100,
        'closed' => 100
    ];
    return $progressMap[$status] ?? 0;
}

/**
 * Get progress bar color based on ticket status
 * @param string $status
 * @return string
 */
function get_progress_color($status) {
    $colorMap = [
        'requested' => 'secondary',
        'assigned' => 'info',
        'accepted' => 'primary',
        'in_progress' => 'warning',
        'work_completed' => 'success',
        'tenant_review' => 'success',
        'admin_review' => 'success',
        'payment_pending' => 'success',
        'paid' => 'success',
        'closed' => 'dark'
    ];
    return $colorMap[$status] ?? 'secondary';
}

$noTenancy = ($tenant === null);
$tickets = [];

if (!$noTenancy) {
    $tid = (int)$tenant['id'];

    if ($method === 'POST') {
        verify_csrf();
        $action = (string)post_param('action', '');
        if ($action === 'create') {
            $category = (string)post_param('category', 'other');
            $priority = (string)post_param('priority', 'medium');
            $title = trim((string)post_param('title', ''));
            $description = trim((string)post_param('description', ''));

            $allowedCat = ['electrical','plumbing','water','security','gate','environmental','safety','other'];
            $allowedPri = ['low','medium','high','urgent'];
            if (!in_array($category, $allowedCat, true)) $category = 'other';
            if (!in_array($priority, $allowedPri, true)) $priority = 'medium';

            if ($title === '' || $description === '') {
                flash_set('error', 'Title and description are required.');
            } else {
                try {
                    $ticketNumber = 'TCK-' . date('YmdHis') . '-' . random_int(100, 999);
                    $db->insert(
                        "INSERT INTO maintenance_tickets
                         (ticket_number, tenant_id, unit_id, estate_id, category, priority, title, description, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open')",
                        [$ticketNumber, $tid, (int)$tenant['unit_id'], (int)$tenant['estate_id'], $category, $priority, $title, $description]
                    );
                    flash_set('success', 'Ticket submitted. We will get back to you shortly.');
                } catch (Throwable $e) {
                    flash_set('error', 'Could not submit ticket. Please try again.');
                }
            }
            redirect('maintenance.php');
        }
    }

    $tickets = $db->fetchAll(
        "SELECT id, ticket_number, category, priority, title, status, created_at, resolved_at, before_photo, after_photo
         FROM maintenance_tickets
         WHERE tenant_id = ?
         ORDER BY created_at DESC",
        [$tid]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php else: ?>

<div class="card card-flush mb-5">
    <div class="card-header">
        <h3 class="card-title">Submit a ticket</h3>
    </div>
    <div class="card-body">
        <form method="post" action="maintenance.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select" required>
                    <option value="electrical">Electrical</option>
                    <option value="plumbing">Plumbing</option>
                    <option value="water">Water</option>
                    <option value="security">Security</option>
                    <option value="gate">Gate</option>
                    <option value="environmental">Environmental</option>
                    <option value="safety">Safety</option>
                    <option value="other" selected>Other</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required maxlength="255" placeholder="Brief summary">
            </div>
            <div class="mb-3">
                <label class="form-label">Description <span class="text-danger">*</span></label>
                <textarea name="description" class="form-control" rows="4" required placeholder="Describe the issue in detail"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Submit ticket</button>
        </form>
    </div>
</div>

<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">My tickets</h3>
    </div>
    <div class="card-body pt-0">
        <?php if (empty($tickets)): ?>
        <p class="text-gray-600 mb-0">You have not submitted any tickets yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-row-bordered align-middle gs-0 gy-3">
                <thead>
                    <tr>
                        <th width="120">Ticket</th>
                        <th>Category</th>
                        <th>Title</th>
                        <th width="80">Priority</th>
                        <th width="100">Status</th>
                        <th width="70">Photos</th>
                        <th width="120">Created</th>
                        <th width="120">Progress</th>
                        <th width="180">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><?= e($t['ticket_number']) ?></td>
                        <td><?= e(ucfirst((string)$t['category'])) ?></td>
                        <td><?= e($t['title']) ?></td>
                        <td><span class="badge badge-light-<?= ($t['priority'] ?? '') === 'urgent' ? 'danger' : 'primary' ?>"><?= e($t['priority'] ?? '') ?></span></td>
                        <td><span class="badge badge-light-info"><?= e($t['status'] ?? '') ?></span></td>
                        <td>
                          <?php if (!empty($t['before_photo']) || !empty($t['after_photo'])): ?>
                            <span class="badge badge-light-primary">Yes</span>
                          <?php else: ?>
                            <span class="badge badge-light">-</span>
                          <?php endif; ?>
                        </td>
                        <td><?= e(date('M j, Y H:i', strtotime($t['created_at']))) ?></td>
                        <td>
                            <?php 
                            $progressPercentage = get_ticket_progress_percentage($t['status']);
                            $progressColor = get_progress_color($t['status']);
                            ?>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-<?= $progressColor ?>" 
                                     style="width: <?= $progressPercentage ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $progressPercentage ?>% Complete</small>
                        </td>
                        <td>
                            <a href="ticket_progress.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-chart-line me-1"></i>Track
                            </a>
                            <?php if (in_array($t['status'], ['work_completed', 'tenant_review'])): ?>
                                <a href="maintenance_confirmation.php?ticket_id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-success ms-1">
                                    <i class="fas fa-clipboard-check me-1"></i>Confirm
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Back to Dashboard</a>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
