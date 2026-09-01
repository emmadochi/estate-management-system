<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../database/db_connection.php';

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 20) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function db(): Database {
    return Database::getInstance();
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function request_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function get_param(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

function post_param(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}

function csrf_token(): string {
    return (string)($_SESSION['csrf_token'] ?? '');
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = (string)post_param('csrf_token', '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        echo 'Session expired (CSRF). Please refresh and try again.';
        exit;
    }
}

function app_base_url(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (stripos($scriptName, '/ESTATEMANAGEMENT/') !== false) {
        return '/ESTATEMANAGEMENT';
    }
    return '';
}

function app_url(string $path = ''): string {
    $base = app_base_url();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function redirect(string $url): void {
    if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0 && strpos($url, '/') === 0) {
        $base = app_base_url();
        if ($base !== '' && strpos($url, $base . '/') !== 0 && $url !== $base) {
            $url = $base . $url;
        }
    }
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    static $cached;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $db = db();
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int)$_SESSION['user_id']]);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            unset($_SESSION['user_id'], $_SESSION['user_role']);
            return null;
        }
        $cached = $user;
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

function login_user(array $user): void {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_role'] = $user['role'] ?? null;
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(array $roles = []): void {
    $user = current_user();
    if (!$user) {
        $returnUrl = $_SERVER['REQUEST_URI'] ?? '/';
        $encoded = urlencode($returnUrl);
        redirect(app_url('pages/authentication/layouts/corporate/sign-in.php?return=' . $encoded));
    }

    if ($roles && !in_array($user['role'] ?? null, $roles, true)) {
        http_response_code(403);
        echo 'Forbidden: insufficient permissions.';
        exit;
    }
}

function is_super_admin(): bool {
    $u = current_user();
    return $u && (($u['role'] ?? null) === 'super_admin');
}

function is_accountant(): bool {
    $u = current_user();
    return $u && (($u['role'] ?? null) === 'accountant');
}

function can_manage_finance(): bool {
    $u = current_user();
    if (!$u) {
        return false;
    }
    $role = (string)($u['role'] ?? '');
    return in_array($role, ['super_admin', 'estate_admin', 'property_manager', 'accountant'], true);
}

function current_user_id(): ?int {
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

function sql_placeholders(int $count): string {
    if ($count <= 0) {
        return '';
    }
    return implode(',', array_fill(0, $count, '?'));
}

/**
 * Returns an estate_id => scoped_role map from user_estates.
 * For non-super admins, this is the authoritative list of estates they can access.
 */
function current_user_estate_roles(): array {
    if (is_super_admin()) {
        return [];
    }
    $uid = current_user_id();
    if (!$uid) {
        return [];
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $rows = db()->fetchAll('SELECT estate_id, role FROM user_estates WHERE user_id = ?', [$uid]);
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['estate_id']] = (string)$r['role'];
        }
        $cached = $map;
        return $map;
    } catch (Throwable $e) {
        $cached = [];
        return [];
    }
}

function allowed_estate_ids(): array {
    if (is_super_admin()) {
        return [];
    }
    return array_keys(current_user_estate_roles());
}

function estates_for_current_user(): array {
    $db = db();
    if (is_super_admin()) {
        return $db->fetchAll('SELECT id, name FROM estates ORDER BY name ASC');
    }
    $ids = allowed_estate_ids();
    if (!$ids) {
        return [];
    }
    $ph = sql_placeholders(count($ids));
    return $db->fetchAll("SELECT id, name FROM estates WHERE id IN ($ph) ORDER BY name ASC", array_values($ids));
}

/**
 * Picks a valid estate_id for the current user.
 * If the requested estate is not permitted, falls back to the first allowed estate.
 */
function normalize_estate_id(int $requestedEstateId = 0): int {
    $estates = estates_for_current_user();
    if (!$estates) {
        http_response_code(403);
        echo 'No estate access assigned to your account. Please contact an administrator.';
        exit;
    }

    $allowed = [];
    foreach ($estates as $eRow) {
        $allowed[] = (int)$eRow['id'];
    }

    if ($requestedEstateId > 0 && in_array($requestedEstateId, $allowed, true)) {
        return $requestedEstateId;
    }
    return (int)$allowed[0];
}

function assert_can_access_estate(int $estateId): void {
    if (is_super_admin()) {
        return;
    }
    $ids = allowed_estate_ids();
    if (!$ids || !in_array($estateId, $ids, true)) {
        http_response_code(403);
        echo 'Forbidden: no access to this estate.';
        exit;
    }
}

function client_ip(): string {
    // Basic IP extraction (safe behind typical local/XAMPP dev)
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function user_agent(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua !== '' ? $ua : 'unknown';
}

function audit_log(string $action, string $model, ?int $modelId = null, ?array $changes = null, ?int $estateId = null): void {
    // Best-effort logging; never block app flows.
    static $supportsEstateId = null;
    try {
        $db = db();
        $uid = current_user_id();

        $changesJson = null;
        if ($changes !== null) {
            $changesJson = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($changesJson === false) {
                $changesJson = null;
            }
        }

        if ($supportsEstateId === false) {
            $db->insert(
                "INSERT INTO audit_logs (user_id, action, model, model_id, changes, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$uid, $action, $model, $modelId, $changesJson, client_ip(), user_agent()]
            );
            return;
        }

        try {
            $db->insert(
                "INSERT INTO audit_logs (user_id, estate_id, action, model, model_id, changes, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$uid, $estateId, $action, $model, $modelId, $changesJson, client_ip(), user_agent()]
            );
            $supportsEstateId = true;
        } catch (Throwable $e) {
            // Likely missing column; fall back.
            $supportsEstateId = false;
            $db->insert(
                "INSERT INTO audit_logs (user_id, action, model, model_id, changes, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$uid, $action, $model, $modelId, $changesJson, client_ip(), user_agent()]
            );
        }
    } catch (Throwable $e) {
        // swallow
    }
}

function audit_diff(array $before, array $after, array $fields): array {
    $changes = [];
    foreach ($fields as $f) {
        $b = $before[$f] ?? null;
        $a = $after[$f] ?? null;
        if ((string)$b !== (string)$a) {
            $changes[$f] = ['from' => $b, 'to' => $a];
        }
    }
    return $changes;
}

function current_tenant(): ?array {
    $user = current_user();
    if (!$user || ($user['role'] ?? null) !== 'tenant') {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = db();
        $tenant = $db->fetchOne(
            "SELECT
                t.*,
                e.name AS estate_name,
                un.unit_number,
                p.name AS property_name
             FROM tenants t
             INNER JOIN estates e ON e.id = t.estate_id
             INNER JOIN units un ON un.id = t.unit_id
             INNER JOIN properties p ON p.id = un.property_id
             WHERE t.user_id = ? AND t.status = 'active'
             ORDER BY t.created_at DESC
             LIMIT 1",
            [(int)$user['id']]
        );
        $cached = $tenant ?: null;
        return $cached;
    } catch (Throwable $e) {
        return null;
    }
}

/** Require logged-in tenant; returns active tenancy or null (pages show friendly "No active tenancy" message). */
function require_tenant(): ?array {
    require_login(['tenant']);
    return current_tenant();
}

/** Current artisan's linked vendor (if any). */
function current_artisan_vendor(): ?array {
    $user = current_user();
    if (!$user || ($user['role'] ?? null) !== 'artisan') {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $db = db();
        $vendor = $db->fetchOne(
            "SELECT v.*
             FROM vendors v
             WHERE v.user_id = ?
             LIMIT 1",
            [(int)$user['id']]
        );
        $cached = $vendor ?: null;
        return $cached;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Require logged-in artisan with a linked vendor profile.
 * Returns the vendor row (or exits with a friendly error).
 */
function require_artisan(): array {
    require_login(['artisan']);
    $vendor = current_artisan_vendor();
    if (!$vendor) {
        http_response_code(403);
        echo 'No artisan profile is linked to this account. Please contact an administrator.';
        exit;
    }
    return $vendor;
}

/** Notifications: fetch recent for current user (tenant or admin). */
function get_notifications_for_current_user(int $limit = 15): array {
    $uid = current_user_id();
    if (!$uid) {
        return [];
    }
    try {
        return db()->fetchAll(
            "SELECT id, type, title, body, link, read_at, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$uid, $limit]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** If request has nid= and current user owns that notification, mark it read (call from layout). */
function mark_notification_read_if_requested(): void {
    $nid = (int)(get_param('nid', 0) ?? 0);
    if ($nid <= 0) {
        return;
    }
    $uid = current_user_id();
    if (!$uid) {
        return;
    }
    try {
        db()->execute("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL", [$nid, $uid]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** Unread notification count for current user. */
function get_unread_notification_count(): int {
    $uid = current_user_id();
    if (!$uid) {
        return 0;
    }
    try {
        $row = db()->fetchOne("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND read_at IS NULL", [$uid]);
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Create a notification for one user. */
function notify_user(int $userId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        db()->insert(
            "INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, NULLIF(?, ''))",
            [$userId, $type, $title, $body, $link]
        );
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Very simple email helper.
 * Note: This uses PHP's mail() function; in local/XAMPP environments you may need to configure mail sending separately.
 */
function send_basic_email(string $to, string $subject, string $body): void {
    $to = trim($to);
    if ($to === '') {
        return;
    }
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    // Suppress errors to avoid breaking the flow if mail is not configured.
    @mail($to, $subject, $body, $headers);
}

/**
 * Emergency Alert Functions
 */

/**
 * Create emergency alert notification for all security personnel
 */
function notify_security_of_emergency(int $alertId, int $estateId, string $alertType, string $description, string $location, string $tenantName): void {
    $db = db();
    
    // Get all active security personnel for this estate
    $securityPersonnel = $db->fetchAll(
        "SELECT sp.user_id, u.first_name, u.last_name, u.email, u.phone
         FROM security_personnel sp
         JOIN users u ON sp.user_id = u.id
         WHERE sp.estate_id = ? AND sp.status = 'active'",
        [$estateId]
    );
    
    $alertTitle = '🚨 EMERGENCY: ' . ucfirst(str_replace('_', ' ', $alertType));
    $alertMessage = "Emergency reported by $tenantName at $location: $description";
    
    foreach ($securityPersonnel as $security) {
        // Create in-app notification
        notify_user(
            (int)$security['user_id'],
            'emergency_alert',
            $alertTitle,
            $alertMessage,
            '../security/emergency_response.php?alert_id=' . $alertId
        );
        
        // In a real implementation, also send SMS and email
        // send_emergency_sms($security['phone'], $alertMessage);
        // send_emergency_email($security['email'], $alertTitle, $alertMessage);
    }
}

/**
 * Get emergency response time statistics
 */
function get_emergency_response_stats(int $estateId): array {
    $db = db();
    
    return $db->fetchOne(
        "SELECT 
            COUNT(*) as total_alerts,
            COUNT(CASE WHEN status IN ('resolved', 'closed') THEN 1 END) as resolved_alerts,
            COUNT(CASE WHEN status IN ('reported', 'acknowledged', 'responding') THEN 1 END) as active_alerts,
            AVG(CASE WHEN response_time_seconds IS NOT NULL THEN response_time_seconds END) as avg_response_time,
            AVG(CASE WHEN resolution_time_seconds IS NOT NULL THEN resolution_time_seconds END) as avg_resolution_time
         FROM emergency_alerts 
         WHERE estate_id = ? AND DATE(reported_at) = CURDATE()",
        [$estateId]
    ) ?: [
        'total_alerts' => 0,
        'resolved_alerts' => 0,
        'active_alerts' => 0,
        'avg_response_time' => 0,
        'avg_resolution_time' => 0
    ];
}

/**
 * Update emergency response times
 */
function update_emergency_response_times(int $alertId): void {
    $db = db();
    
    $alert = $db->fetchOne(
        "SELECT reported_at, acknowledged_at, responded_at, resolved_at FROM emergency_alerts WHERE id = ?",
        [$alertId]
    );
    
    if ($alert) {
        $updates = [];
        $params = [];
        
        // Calculate response time (acknowledged - reported)
        if ($alert['acknowledged_at']) {
            $responseTime = strtotime($alert['acknowledged_at']) - strtotime($alert['reported_at']);
            $updates[] = "response_time_seconds = ?";
            $params[] = $responseTime;
        }
        
        // Calculate resolution time (resolved - reported)
        if ($alert['resolved_at']) {
            $resolutionTime = strtotime($alert['resolved_at']) - strtotime($alert['reported_at']);
            $updates[] = "resolution_time_seconds = ?";
            $params[] = $resolutionTime;
        }
        
        if (!empty($updates)) {
            $params[] = $alertId;
            $db->execute(
                "UPDATE emergency_alerts SET " . implode(', ', $updates) . " WHERE id = ?",
                $params
            );
        }
    }
}

/** Notify all users who can access an estate (super admins + estate-assigned users). Used for new lease request etc. */
function notify_estate_admins(int $estateId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        $db = db();
        $userIds = $db->fetchAll(
            "SELECT id FROM users WHERE role = 'super_admin'
             UNION
             SELECT user_id AS id FROM user_estates WHERE estate_id = ?",
            [$estateId]
        );
        foreach ($userIds as $row) {
            $uid = (int)$row['id'];
            if ($uid > 0) {
                notify_user($uid, $type, $title, $body, $link);
            }
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Notify users in an estate by audience:
 * - tenants: active tenants (tenants.user_id)
 * - staff: estate assigned non-tenant roles + super admins
 * - all: both groups
 */
function notify_estate_audience(int $estateId, string $audience, string $type, string $title, string $body = '', string $link = ''): void {
    $audience = strtolower(trim($audience));
    if ($audience === '') {
        $audience = 'all';
    }

    try {
        $db = db();
        $userIdMap = [];

        // Always include super admins for estate-wide announcements.
        $rows = $db->fetchAll("SELECT id FROM users WHERE role = 'super_admin'");
        foreach ($rows as $r) {
            $uid = (int)($r['id'] ?? 0);
            if ($uid > 0) {
                $userIdMap[$uid] = true;
            }
        }

        if ($audience === 'tenants' || $audience === 'all') {
            $rows = $db->fetchAll(
                "SELECT DISTINCT user_id AS id
                 FROM tenants
                 WHERE estate_id = ? AND status = 'active' AND user_id IS NOT NULL",
                [$estateId]
            );
            foreach ($rows as $r) {
                $uid = (int)($r['id'] ?? 0);
                if ($uid > 0) {
                    $userIdMap[$uid] = true;
                }
            }
        }

        if ($audience === 'staff' || $audience === 'all') {
            $rows = $db->fetchAll(
                "SELECT DISTINCT ue.user_id AS id
                 FROM user_estates ue
                 INNER JOIN users u ON u.id = ue.user_id
                 WHERE ue.estate_id = ?
                   AND u.status = 'active'
                   AND u.role IN ('estate_admin','property_manager','staff','security')",
                [$estateId]
            );
            foreach ($rows as $r) {
                $uid = (int)($r['id'] ?? 0);
                if ($uid > 0) {
                    $userIdMap[$uid] = true;
                }
            }
        }

        foreach (array_keys($userIdMap) as $uid) {
            notify_user((int)$uid, $type, $title, $body, $link);
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

function redirect_after_login(string $role): void {
    // Adjust redirect per role to Keen dashboards
    switch ($role) {
        case 'super_admin':
        case 'estate_admin':
        case 'property_manager':
        case 'staff':
            $target = app_url('pages/admin/dashboard.php');
            break;
        case 'tenant':
            $target = app_url('pages/tenant/dashboard.php');
            break;
        case 'artisan':
            $target = app_url('pages/artisan/dashboard.php');
            break;
        case 'security':
            $target = app_url('pages/security/index.php');
            break;
        default:
            $target = app_url('pages/admin/dashboard.php');
            break;
    }
    redirect($target);
}

/** File upload helpers for avatars */
function get_upload_dir(): string {
    $dir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Avatar URL for use in pages under pages/tenant/ or pages/admin/.
 * Use ../ for assets (pages/assets), ../../ for uploads (project root uploads/).
 */
function get_avatar_url(?string $avatar): string {
    if (empty($avatar)) {
        return '../assets/media/svg/avatars/blank.svg';
    }
    if (strpos($avatar, 'http') === 0) {
        return $avatar; // External URL
    }
    return '../../uploads/avatars/' . basename($avatar);
}

/** Whether the user has an avatar image (so top bar can show icon when false). */
function user_has_avatar(?string $avatar): bool {
    return !empty(trim((string)$avatar));
}

function get_avatar_path(?string $avatar): ?string {
    if (empty($avatar)) {
        return null;
    }
    $file = get_upload_dir() . '/' . basename($avatar);
    return file_exists($file) ? $file : null;
}

/** Handle avatar upload - returns filename on success, null on failure. */
function handle_avatar_upload(?int $userId = null): ?string {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes, true)) {
        flash_set('error', 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
        return null;
    }

    if ($file['size'] > $maxSize) {
        flash_set('error', 'File too large. Maximum size is 5MB.');
        return null;
    }

    $ext = 'jpg'; // default
    if (in_array($file['type'], ['image/jpeg', 'image/jpg'], true)) {
        $ext = 'jpg';
    } elseif ($file['type'] === 'image/png') {
        $ext = 'png';
    } elseif ($file['type'] === 'image/gif') {
        $ext = 'gif';
    } elseif ($file['type'] === 'image/webp') {
        $ext = 'webp';
    }

    $uploadDir = get_upload_dir();
    $filename = ($userId ? 'user_' . $userId . '_' : '') . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        flash_set('error', 'Failed to upload file.');
        return null;
    }

    // Delete old avatar if exists and user is updating
    if ($userId) {
        $oldAvatar = db()->fetchOne('SELECT avatar FROM users WHERE id = ?', [$userId]);
        if ($oldAvatar && !empty($oldAvatar['avatar'])) {
            $oldPath = get_avatar_path($oldAvatar['avatar']);
            if ($oldPath && file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }
    }

    return $filename;
}

/** File upload helpers for payment receipts (proof of payment). */
function get_receipt_upload_dir(): string {
    $dir = __DIR__ . '/../uploads/receipts';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Receipt URL for use in pages under pages/tenant/ or pages/admin/.
 * Use ../../ for uploads (project root uploads/).
 */
function get_receipt_url(?string $receiptFile): ?string {
    $receiptFile = trim((string)$receiptFile);
    if ($receiptFile === '') {
        return null;
    }
    return '../../uploads/receipts/' . basename($receiptFile);
}

/**
 * Handle receipt upload. Returns filename on success, null when no file was uploaded.
 * Sets flash error and returns null on validation failure.
 */
function handle_receipt_upload(string $field = 'receipt', ?string $prefix = null): ?string {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        flash_set('error', 'Failed to upload proof of payment.');
        return null;
    }

    $file = $_FILES[$field];
    $allowedTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];
    $maxSize = 8 * 1024 * 1024; // 8MB

    if (!in_array((string)($file['type'] ?? ''), $allowedTypes, true)) {
        flash_set('error', 'Invalid proof file type. Upload an image (JPEG/PNG/GIF/WebP) or PDF.');
        return null;
    }
    if ((int)($file['size'] ?? 0) > $maxSize) {
        flash_set('error', 'Proof file too large. Maximum size is 8MB.');
        return null;
    }

    $ext = 'bin';
    $mime = (string)($file['type'] ?? '');
    if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } elseif ($mime === 'image/gif') {
        $ext = 'gif';
    } elseif ($mime === 'image/webp') {
        $ext = 'webp';
    } elseif ($mime === 'application/pdf') {
        $ext = 'pdf';
    }

    $uploadDir = get_receipt_upload_dir();
    $safePrefix = $prefix ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix) : 'receipt';
    $filename = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        flash_set('error', 'Failed to save uploaded proof file.');
        return null;
    }

    return $filename;
}

/** File upload helpers for expense receipts / invoices */
function get_expense_upload_dir(): string {
    $dir = __DIR__ . '/../uploads/expenses';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function get_expense_receipt_url(?string $file): ?string {
    $file = trim((string)$file);
    if ($file === '') {
        return null;
    }
    return '../../uploads/expenses/' . basename($file);
}

function handle_expense_receipt_upload(string $field = 'receipt_file', ?string $prefix = null): ?string {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        flash_set('error', 'Failed to upload expense receipt.');
        return null;
    }

    $file = $_FILES[$field];
    $allowedTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];
    $maxSize = 10 * 1024 * 1024; // 10MB

    if (!in_array((string)($file['type'] ?? ''), $allowedTypes, true)) {
        flash_set('error', 'Invalid file format. Upload an image (JPEG/PNG/WebP) or PDF.');
        return null;
    }
    if ((int)($file['size'] ?? 0) > $maxSize) {
        flash_set('error', 'Receipt file too large. Maximum size is 10MB.');
        return null;
    }

    $ext = 'bin';
    $mime = (string)($file['type'] ?? '');
    if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } elseif ($mime === 'image/gif') {
        $ext = 'gif';
    } elseif ($mime === 'image/webp') {
        $ext = 'webp';
    } elseif ($mime === 'application/pdf') {
        $ext = 'pdf';
    }

    $uploadDir = get_expense_upload_dir();
    $safePrefix = $prefix ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $prefix) : 'exp_receipt';
    $filename = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        flash_set('error', 'Failed to save uploaded expense file.');
        return null;
    }

    return $filename;
}

function format_money($amount, string $currency = '₦'): string {
    $num = (float)($amount ?? 0);
    return $currency . number_format($num, 2);
}


