<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Security Dashboard – EstatePro';
$pageHeading = 'Security Dashboard';
$db = db();
$me = current_user();
$estateIds = allowed_estate_ids();
$estateId = !empty($estateIds) ? (int)$estateIds[0] : 0;

$securityPersonnel = null;
if ($estateId) {
    $securityPersonnel = $db->fetchOne(
        "SELECT * FROM security_personnel WHERE user_id = ? AND estate_id = ?",
        [$me['id'], $estateId]
    );
}

$today = date('Y-m-d');
$stats = [
    'attendance_today' => null,
    'active_passes'    => 0,
    'open_incidents'   => 0,
    'visitors_today'   => 0,
];

if ($estateId) {
    if ($securityPersonnel) {
        $att = $db->fetchOne(
            "SELECT id, clock_in_time, clock_out_time FROM security_attendance WHERE security_personnel_id = ? AND date = ?",
            [$securityPersonnel['id'], $today]
        );
        $stats['attendance_today'] = $att;
    }
    $row = $db->fetchOne("SELECT COUNT(*) AS c FROM gate_passes WHERE estate_id = ? AND status = 'active' AND valid_until >= NOW()", [$estateId]);
    $stats['active_passes'] = (int) ($row['c'] ?? 0);
    $incidentCount = $db->fetchOne("SELECT COUNT(*) AS c FROM emergency_incidents WHERE estate_id = ? AND status IN ('reported', 'in_progress')", [$estateId]);
    $alertCount = $db->fetchOne("SELECT COUNT(*) AS c FROM emergency_alerts WHERE estate_id = ? AND status IN ('reported', 'acknowledged', 'responding')", [$estateId]);
    $stats['open_incidents'] = (int) ($incidentCount['c'] ?? 0) + (int) ($alertCount['c'] ?? 0);
    $row = $db->fetchOne("SELECT COUNT(*) AS c FROM visitor_logs WHERE estate_id = ? AND DATE(entry_time) = ? AND status = 'checked_in'", [$estateId, $today]);
    $stats['visitors_today'] = (int) ($row['c'] ?? 0);
}

$toolbarActions = '';

require __DIR__ . '/partials/top.php';
?>

<div class="row g-6 g-xl-9">
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Overview</h3>
                </div>
                <?php if ($securityPersonnel): ?>
                <div class="card-toolbar">
                    <span class="badge badge-light-primary fs-5"><?= e($securityPersonnel['badge_number']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body py-4">
                <div class="row g-5 g-xl-10">
                    <div class="col-6 col-xl-3">
                        <a href="attendance.php" class="card card-flush bgi-no-repeat bgi-size-contain bgi-position-x-end h-xl-100 text-decoration-none">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1"><?= $stats['attendance_today'] ? ($stats['attendance_today']['clock_out_time'] ? 'Done' : 'On duty') : '—' ?></span>
                                    <span class="text-gray-500 fw-semibold pt-1">Today's attendance</span>
                                </div>
                            </div>
                            <div class="card-body d-flex align-items-end pt-0">
                                <span class="fs-6 fw-bolder text-primary">View attendance &rarr;</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-xl-3">
                        <a href="gate_passes.php" class="card card-flush bgi-no-repeat bgi-size-contain bgi-position-x-end h-xl-100 text-decoration-none">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1"><?= (int)$stats['active_passes'] ?></span>
                                    <span class="text-gray-500 fw-semibold pt-1">Active gate passes</span>
                                </div>
                            </div>
                            <div class="card-body d-flex align-items-end pt-0">
                                <span class="fs-6 fw-bolder text-primary">Manage passes &rarr;</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-xl-3">
                        <a href="emergency_response.php" class="card card-flush bgi-no-repeat bgi-size-contain bgi-position-x-end h-xl-100 text-decoration-none">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1"><?= (int)$stats['open_incidents'] ?></span>
                                    <span class="text-gray-500 fw-semibold pt-1">Open Incidents & Alerts</span>
                                </div>
                            </div>
                            <div class="card-body d-flex align-items-end pt-0">
                                <span class="fs-6 fw-bolder text-primary">View All &rarr;</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-xl-3">
                        <a href="visitor_logs.php" class="card card-flush bgi-no-repeat bgi-size-contain bgi-position-x-end h-xl-100 text-decoration-none">
                            <div class="card-header pt-5">
                                <div class="card-title d-flex flex-column">
                                    <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1"><?= (int)$stats['visitors_today'] ?></span>
                                    <span class="text-gray-500 fw-semibold pt-1">Visitors on site today</span>
                                </div>
                            </div>
                            <div class="card-body d-flex align-items-end pt-0">
                                <span class="fs-6 fw-bolder text-primary">Visitor logs &rarr;</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 mb-5">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Quick actions</h3>
                </div>
            </div>
            <div class="card-body py-4">
                <div class="d-flex flex-wrap gap-3">
                    <a href="attendance.php" class="btn btn-light-primary">
                        <i class="ki-duotone ki-calendar fs-2"><span class="path1"></span><span class="path2"></span></i>
                        Attendance
                    </a>
                    <a href="visitor_logs.php" class="btn btn-light-info">
                        <i class="ki-duotone ki-profile-user fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                        Visitor logs
                    </a>
                    <a href="gate_passes.php" class="btn btn-light-success">
                        <i class="ki-duotone ki-key fs-2"><span class="path1"></span><span class="path2"></span></i>
                        Gate passes
                    </a>
                    <a href="emergency_incidents.php" class="btn btn-light-danger">
                        <i class="ki-duotone ki-information fs-2"><span class="path1"></span><span class="path2"></span></i>
                        Emergency incidents
                    </a>
                    <a href="emergency_response.php" class="btn btn-light-danger">
                        <i class="ki-duotone ki-siren fs-2"><span class="path1"></span><span class="path2"></span></i>
                        Emergency Alerts
                    </a>
                    <a href="incident_reports.php" class="btn btn-light-warning">
                        <i class="ki-duotone ki-file fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                        Incident reports
                    </a>
                    <a href="patrol_logs.php" class="btn btn-light-dark">
                        <i class="ki-duotone ki-route fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                        Patrol logs
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
