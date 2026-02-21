<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Announcements – EstatePro';
$db = db();
$method = request_method();

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

$editId = (int)(get_param('edit_id', 0) ?? 0);
$statusFilter = (string)(get_param('status', '') ?? '');

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    $sendAnnouncementNotifications = function (int $announcementId, int $estateId, string $audience, string $title, string $content, string $type, string $priority): void {
        if (!function_exists('notify_estate_audience')) {
            return;
        }
        $body = trim(mb_strimwidth($content, 0, 180, '…'));
        $notifType = 'announcement';
        if ($type !== '') {
            $notifType = 'announcement_' . $type;
        }
        if ($priority === 'urgent') {
            $notifType = 'announcement_urgent';
        }

        $tenantLink = '/ESTATEMANAGEMENT/pages/tenant/announcements.php#ann-' . $announcementId;
        $adminLink = '/ESTATEMANAGEMENT/pages/admin/announcements.php?estate_id=' . $estateId . '&edit_id=' . $announcementId;

        if ($audience === 'all') {
            notify_estate_audience($estateId, 'tenants', $notifType, $title, $body, $tenantLink);
            notify_estate_audience($estateId, 'staff', $notifType, $title, $body, $adminLink);
            return;
        }
        if ($audience === 'tenants') {
            notify_estate_audience($estateId, 'tenants', $notifType, $title, $body, $tenantLink);
            return;
        }
        if ($audience === 'staff') {
            notify_estate_audience($estateId, 'staff', $notifType, $title, $body, $adminLink);
            return;
        }

        // Fallback for unimplemented audiences (e.g. specific_units): notify staff who manage the estate.
        notify_estate_audience($estateId, 'staff', $notifType, $title, $body, $adminLink);
    };

    if ($action === 'save') {
        $id = (int)(post_param('id', 0) ?? 0);
        $title = trim((string)post_param('title', ''));
        $content = trim((string)post_param('content', ''));
        $type = (string)post_param('type', 'general');
        $priority = (string)post_param('priority', 'normal');
        $target = (string)post_param('target_audience', 'all');
        $status = (string)post_param('status', 'draft');

        if ($title === '' || $content === '') {
            flash_set('error', 'Title and content are required.');
            redirect('announcements.php?estate_id=' . $estateId . ($id ? ('&edit_id=' . $id) : ''));
        }

        try {
            $me = current_user();
            $createdBy = $me ? (int)$me['id'] : 1;

            $publishedAt = null;
            if ($status === 'published') {
                $publishedAt = date('Y-m-d H:i:s');
            }

            if ($id > 0) {
                $before = $db->fetchOne('SELECT * FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
                $db->execute(
                    "UPDATE announcements
                     SET title = ?, content = ?, type = ?, priority = ?, target_audience = ?, status = ?,
                         published_at = CASE WHEN ? = 'published' THEN COALESCE(published_at, NOW()) ELSE published_at END
                     WHERE id = ? AND estate_id = ?",
                    [$title, $content, $type, $priority, $target, $status, $status, $id, $estateId]
                );
                flash_set('success', 'Announcement updated.');
                $after = $db->fetchOne('SELECT * FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
                if ($before && $after) {
                    $diff = audit_diff($before, $after, ['title','content','type','priority','target_audience','status','published_at']);
                    audit_log('update', 'announcement', (int)$id, ['diff' => $diff], $estateId);
                }
                $beforeStatus = (string)($before['status'] ?? '');
                if ($status === 'published' && $beforeStatus !== 'published') {
                    $sendAnnouncementNotifications($id, $estateId, $target, $title, $content, $type, $priority);
                }
            } else {
                $newId = (int)$db->insert(
                    "INSERT INTO announcements
                     (estate_id, title, content, type, priority, target_audience, status, published_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$estateId, $title, $content, $type, $priority, $target, $status, $publishedAt, $createdBy]
                );
                flash_set('success', 'Announcement created.');
                audit_log('create', 'announcement', $newId, ['title' => $title, 'type' => $type, 'priority' => $priority, 'status' => $status], $estateId);
                if ($status === 'published') {
                    $sendAnnouncementNotifications($newId, $estateId, $target, $title, $content, $type, $priority);
                }
            }
        } catch (Throwable $e) {
            flash_set('error', 'Save failed.');
        }

        redirect('announcements.php?estate_id=' . $estateId);
    }

    if ($action === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, title, status FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            $db->execute('DELETE FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            flash_set('success', 'Announcement deleted.');
            if ($before) {
                audit_log('delete', 'announcement', (int)$before['id'], ['title' => $before['title'] ?? null, 'status' => $before['status'] ?? null], $estateId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete announcement.');
        }
        redirect('announcements.php?estate_id=' . $estateId);
    }

    if ($action === 'publish') {
        $id = (int)(post_param('id', 0) ?? 0);
        try {
            $before = $db->fetchOne('SELECT id, title, content, type, priority, target_audience, status FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            $db->execute(
                "UPDATE announcements SET status = 'published', published_at = COALESCE(published_at, NOW())
                 WHERE id = ? AND estate_id = ?",
                [$id, $estateId]
            );
            flash_set('success', 'Announcement published.');
            $row = $db->fetchOne('SELECT id, title FROM announcements WHERE id = ? AND estate_id = ?', [$id, $estateId]);
            audit_log('publish', 'announcement', $id, ['title' => $row['title'] ?? null], $estateId);
            if ($before && (string)($before['status'] ?? '') !== 'published') {
                $sendAnnouncementNotifications(
                    (int)($before['id'] ?? $id),
                    $estateId,
                    (string)($before['target_audience'] ?? 'all'),
                    (string)($before['title'] ?? 'New announcement'),
                    (string)($before['content'] ?? ''),
                    (string)($before['type'] ?? 'general'),
                    (string)($before['priority'] ?? 'normal')
                );
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not publish announcement.');
        }
        redirect('announcements.php?estate_id=' . $estateId);
    }
}

$editing = null;
if ($editId > 0) {
    $editing = $db->fetchOne('SELECT * FROM announcements WHERE id = ? AND estate_id = ?', [$editId, $estateId]);
    if (!$editing) {
        flash_set('warning', 'Announcement not found.');
        redirect('announcements.php?estate_id=' . $estateId);
    }
}

$where = ['a.estate_id = ?'];
$params = [$estateId];
if ($statusFilter !== '' && in_array($statusFilter, ['draft','published','archived'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}

$rows = $db->fetchAll(
    "SELECT a.*, u.first_name, u.last_name
     FROM announcements a
     INNER JOIN users u ON u.id = a.created_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.created_at DESC
     LIMIT 300",
    $params
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Announcements</h1>
    <div class="text-gray-600">Draft and publish estate-wide messages.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body">
    <form method="get" action="announcements.php" class="row g-3 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label">Estate</label>
        <select class="form-select" name="estate_id" onchange="this.form.submit()">
          <?php foreach ($estates as $eRow): ?>
            <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
              <?= e($eRow['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="status" onchange="this.form.submit()">
          <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
          <?php foreach (['draft','published','archived'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-light" type="submit">Go</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold"><?= $editing ? 'Edit Announcement' : 'Create Announcement' ?></div>
      </div>
      <div class="card-body">
        <form method="post" action="announcements.php?estate_id=<?= $estateId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">

          <div class="mb-4">
            <label class="form-label required">Title</label>
            <input class="form-control" name="title" required value="<?= e($editing['title'] ?? '') ?>">
          </div>

          <div class="mb-4">
            <label class="form-label required">Content</label>
            <textarea class="form-control" name="content" rows="5" required><?= e($editing['content'] ?? '') ?></textarea>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label">Type</label>
              <?php $type = (string)($editing['type'] ?? 'general'); ?>
              <select class="form-select" name="type">
                <?php foreach (['general','maintenance','payment','security','emergency','event'] as $t): ?>
                  <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Priority</label>
              <?php $prio = (string)($editing['priority'] ?? 'normal'); ?>
              <select class="form-select" name="priority">
                <?php foreach (['low','normal','high','urgent'] as $p): ?>
                  <option value="<?= e($p) ?>" <?= $prio === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-6">
            <div class="col-12 col-md-6">
              <label class="form-label">Audience</label>
              <?php $aud = (string)($editing['target_audience'] ?? 'all'); ?>
              <select class="form-select" name="target_audience">
                <option value="all" <?= $aud === 'all' ? 'selected' : '' ?>>all</option>
                <option value="tenants" <?= $aud === 'tenants' ? 'selected' : '' ?>>tenants</option>
                <option value="staff" <?= $aud === 'staff' ? 'selected' : '' ?>>staff</option>
                <option value="specific_units" <?= $aud === 'specific_units' ? 'selected' : '' ?>>specific_units</option>
              </select>
              <div class="form-text">specific_units targeting can be added next (uses JSON list).</div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Status</label>
              <?php $st = (string)($editing['status'] ?? 'draft'); ?>
              <select class="form-select" name="status">
                <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>draft</option>
                <option value="published" <?= $st === 'published' ? 'selected' : '' ?>>published</option>
                <option value="archived" <?= $st === 'archived' ? 'selected' : '' ?>>archived</option>
              </select>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create' ?></button>
            <?php if ($editing): ?>
              <a class="btn btn-light" href="announcements.php?estate_id=<?= $estateId ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Announcement list</div>
      </div>
      <div class="card-body">
        <?php if (!$rows): ?>
          <div class="text-gray-600">No announcements found.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Title</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Created by</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $a): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($a['title']) ?></td>
                  <td><span class="badge badge-light"><?= e($a['type']) ?></span></td>
                  <td><span class="badge badge-light-<?= $a['status'] === 'published' ? 'success' : 'warning' ?>"><?= e($a['status']) ?></span></td>
                  <td class="text-gray-700"><?= e(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?></td>
                  <td class="text-end">
                    <div class="d-flex justify-content-end gap-2">
                      <a class="btn btn-sm btn-light-primary" href="announcements.php?estate_id=<?= $estateId ?>&edit_id=<?= (int)$a['id'] ?>">Edit</a>
                      <?php if ($a['status'] !== 'published'): ?>
                        <form method="post" action="announcements.php?estate_id=<?= $estateId ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="publish">
                          <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                          <button class="btn btn-sm btn-light-success" type="submit">Publish</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="announcements.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Delete this announcement?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-light-danger" type="submit">Delete</button>
                      </form>
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

<?php require __DIR__ . '/partials/bottom.php'; ?>

