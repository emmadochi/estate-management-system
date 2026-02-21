<?php
require_once __DIR__ . '/../../app/bootstrap.php';

$tenant = require_tenant();
$pageTitle = 'Announcements – EstatePro Tenant';
$pageHeading = 'Announcements';
$db = db();

$noTenancy = ($tenant === null);
$announcements = [];

if (!$noTenancy) {
    $eid = (int)$tenant['estate_id'];

    $announcements = $db->fetchAll(
        "SELECT id, title, content, type, priority, published_at
         FROM announcements
         WHERE estate_id = ? AND status = 'published' AND published_at IS NOT NULL
           AND target_audience IN ('all','tenants')
         ORDER BY published_at DESC
         LIMIT 100",
        [$eid]
    );
}

require __DIR__ . '/partials/top.php';
?>

<?php if ($noTenancy): ?>
<div class="alert alert-warning">No active tenancy linked to your account. Please contact your estate manager.</div>
<?php elseif (empty($announcements)): ?>
<div class="card card-flush">
    <div class="card-body">
        <p class="text-gray-600 mb-0">No announcements at the moment.</p>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-3">Back to Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">Estate announcements</h3>
    </div>
    <div class="card-body pt-0">
        <?php foreach ($announcements as $a): ?>
        <div id="ann-<?= (int)$a['id'] ?>" class="border-bottom border-gray-200 pb-4 mb-4">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <h4 class="text-gray-900 fw-bold mb-0"><?= e($a['title']) ?></h4>
                <span class="badge badge-light-<?= ($a['priority'] ?? '') === 'urgent' ? 'danger' : 'secondary' ?>"><?= e($a['type'] ?? 'general') ?></span>
            </div>
            <div class="text-muted small mb-2"><?= e(date('F j, Y \a\t g:i A', strtotime($a['published_at']))) ?></div>
            <div class="text-gray-700"><?= nl2br(e($a['content'] ?? '')) ?></div>
        </div>
        <?php endforeach; ?>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary">Back to Dashboard</a>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
