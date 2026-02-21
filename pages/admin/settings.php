<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin']);

$pageTitle = 'Settings – EstatePro';
$db = db();
$method = request_method();

$estateId = (int)(get_param('estate_id', 0) ?? 0);
$estates = $db->fetchAll('SELECT id, name FROM estates ORDER BY name ASC');
if ($estateId <= 0) {
    $estateId = 0; // global settings
}

if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'save') {
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        $key = trim((string)post_param('key', ''));
        $value = trim((string)post_param('value', ''));
        $type = (string)post_param('type', 'string');
        $description = trim((string)post_param('description', ''));

        if ($key === '') {
            flash_set('error', 'Key is required.');
            redirect('settings.php?estate_id=' . $estateIdPost);
        }
        if (!in_array($type, ['string','number','boolean','json'], true)) {
            $type = 'string';
        }

        try {
            $db->execute(
                "INSERT INTO settings (estate_id, `key`, `value`, `type`, `description`)
                 VALUES (?, ?, ?, ?, NULLIF(?, ''))
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`), `description` = VALUES(`description`)",
                [$estateIdPost ?: null, $key, $value, $type, $description]
            );
            flash_set('success', 'Setting saved.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not save setting.');
        }

        redirect('settings.php?estate_id=' . $estateIdPost);
    }

    if ($action === 'delete') {
        $id = (int)(post_param('id', 0) ?? 0);
        $estateIdPost = (int)(post_param('estate_id', 0) ?? 0);
        try {
            $db->execute('DELETE FROM settings WHERE id = ?', [$id]);
            flash_set('success', 'Setting deleted.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not delete setting.');
        }
        redirect('settings.php?estate_id=' . $estateIdPost);
    }
}

$rows = $db->fetchAll(
    "SELECT * FROM settings
     WHERE " . ($estateId === 0 ? 'estate_id IS NULL' : 'estate_id = ?') . "
     ORDER BY `key` ASC",
    $estateId === 0 ? [] : [$estateId]
);

require __DIR__ . '/partials/top.php';
?>

<div class="d-flex flex-wrap flex-stack mb-6">
  <div class="d-flex flex-column">
    <h1 class="text-gray-900 fw-bold mb-1">Settings</h1>
    <div class="text-gray-600">Global and per-estate key/value configuration.</div>
  </div>
</div>

<div class="card mb-6">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <div class="fw-bold text-gray-800">Scope:</div>
    <form method="get" action="settings.php" class="d-flex align-items-center gap-2">
      <select class="form-select form-select-sm" name="estate_id" onchange="this.form.submit()">
        <option value="0" <?= $estateId === 0 ? 'selected' : '' ?>>Global</option>
        <?php foreach ($estates as $eRow): ?>
          <option value="<?= (int)$eRow['id'] ?>" <?= (int)$eRow['id'] === $estateId ? 'selected' : '' ?>>
            <?= e($eRow['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm btn-light" type="submit">Go</button></noscript>
    </form>
  </div>
</div>

<div class="row g-6">
  <div class="col-12 col-xxl-4">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Add / Update setting</div>
      </div>
      <div class="card-body">
        <form method="post" action="settings.php?estate_id=<?= $estateId ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="estate_id" value="<?= $estateId ?>">

          <div class="mb-4">
            <label class="form-label required">Key</label>
            <input class="form-control" name="key" required placeholder="e.g. payments.paystack.public_key">
          </div>

          <div class="mb-4">
            <label class="form-label">Type</label>
            <select class="form-select" name="type">
              <option value="string">string</option>
              <option value="number">number</option>
              <option value="boolean">boolean</option>
              <option value="json">json</option>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label required">Value</label>
            <textarea class="form-control" name="value" rows="3" required></textarea>
          </div>

          <div class="mb-6">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="description" rows="2"></textarea>
          </div>

          <button class="btn btn-primary" type="submit">Save</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xxl-8">
    <div class="card">
      <div class="card-header">
        <div class="card-title fw-bold">Current settings</div>
      </div>
      <div class="card-body">
        <?php if (!$rows): ?>
          <div class="text-gray-600">No settings in this scope.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-row-dashed align-middle">
              <thead>
                <tr class="fw-bold text-gray-600">
                  <th>Key</th>
                  <th>Type</th>
                  <th>Value</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="fw-bold text-gray-900"><?= e($r['key']) ?></td>
                  <td><span class="badge badge-light"><?= e($r['type']) ?></span></td>
                  <td class="text-gray-700"><code><?= e((string)$r['value']) ?></code></td>
                  <td class="text-end">
                    <form method="post" action="settings.php?estate_id=<?= $estateId ?>" onsubmit="return confirm('Delete this setting?');" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="estate_id" value="<?= $estateId ?>">
                      <button class="btn btn-sm btn-light-danger" type="submit">Delete</button>
                    </form>
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

