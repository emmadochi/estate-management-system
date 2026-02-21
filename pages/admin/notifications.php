<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager', 'staff', 'security']);

$me = current_user();
$pageTitle = 'Notifications – EstatePro';
$pageHeading = 'Notifications';
$db = db();

$markAll = isset($_GET['mark_all']) && $_GET['mark_all'] === '1';
$nid = (int)(get_param('nid', 0) ?? 0);

if ($me) {
    if ($markAll) {
        $db->execute("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [(int)$me['id']]);
        flash_set('success', 'All notifications marked as read.');
        redirect('notifications.php');
    }
    if ($nid > 0) {
        $db->execute("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?", [$nid, (int)$me['id']]);
        $n = $db->fetchOne("SELECT link FROM notifications WHERE id = ? AND user_id = ?", [$nid, (int)$me['id']]);
        if ($n && !empty($n['link'])) {
            redirect($n['link']);
        }
    }
}

$notifications = [];
if ($me) {
    $notifications = get_notifications_for_current_user(50);
}

require __DIR__ . '/partials/top.php';
?>

<?php if (empty($notifications)): ?>
<div class="card card-flush">
    <div class="card-body text-center py-12">
        <i class="ki-duotone ki-notification-on fs-3x text-gray-400 mb-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
        <p class="text-gray-600 mb-0">No notifications yet.</p>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-4">Back to Dashboard</a>
    </div>
</div>
<?php else: ?>
<div class="card card-flush">
    <div class="card-header">
        <h3 class="card-title">All notifications</h3>
        <a href="notifications.php?mark_all=1" class="btn btn-sm btn-light">Mark all as read</a>
    </div>
    <div class="card-body pt-0">
        <div class="scroll-y">
            <?php foreach ($notifications as $n): ?>
            <a href="<?php
                $u = !empty($n['link']) ? $n['link'] : 'notifications.php';
                echo e($u . (strpos($u, '?') !== false ? '&' : '?') . 'nid=' . (int)$n['id']);
            ?>" class="d-flex flex-column border-bottom border-gray-200 py-4 px-4 text-gray-800 text-hover-primary <?= empty($n['read_at']) ? 'bg-light-primary' : '' ?>">
                <span class="fw-semibold"><?= e($n['title']) ?></span>
                <?php if (!empty($n['body'])): ?><span class="fs-7 text-muted mt-1"><?= nl2br(e($n['body'])) ?></span><?php endif; ?>
                <span class="fs-8 text-gray-500 mt-2"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-light-primary mt-4">Back to Dashboard</a>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
