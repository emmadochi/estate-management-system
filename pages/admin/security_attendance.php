<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['super_admin', 'estate_admin', 'property_manager']);

$pageTitle = 'Security Personnel Attendance – EstatePro';
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

// Handle attendance actions
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');

    if ($action === 'clock_in') {
        $securityPersonnelId = (int)post_param('security_personnel_id', 0);
        $shiftType = (string)post_param('shift_type', 'morning');
        $location = (string)post_param('location', '');
        
        if ($securityPersonnelId <= 0) {
            flash_set('error', 'Security personnel ID is required.');
            redirect('security_attendance.php?estate_id=' . $estateId);
        }
        
        try {
            $today = date('Y-m-d');
            $currentTime = date('Y-m-d H:i:s');
            
            // Check if already clocked in today
            $existingAttendance = $db->fetchOne(
                "SELECT id, clock_in_time FROM security_attendance 
                 WHERE security_personnel_id = ? AND date = ?",
                [$securityPersonnelId, $today]
            );
            
            if ($existingAttendance) {
                // Update existing record
                $db->execute(
                    "UPDATE security_attendance 
                     SET clock_in_time = ?, clock_in_location = ?, status = ?
                     WHERE id = ?",
                    [$currentTime, $location, 'present', $existingAttendance['id']]
                );
            } else {
                // Create new attendance record
                $db->insert(
                    "INSERT INTO security_attendance 
                     (security_personnel_id, estate_id, date, shift_type, clock_in_time, clock_in_location, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$securityPersonnelId, $estateId, $today, $shiftType, $currentTime, $location, 'present']
                );
            }
            
            flash_set('success', 'Clock in recorded successfully.');
        } catch (Throwable $e) {
            flash_set('error', 'Failed to record clock in: ' . $e->getMessage());
        }
        
        redirect('security_attendance.php?estate_id=' . $estateId);
    }
    
    if ($action === 'clock_out') {
        $attendanceId = (int)post_param('attendance_id', 0);
        $location = (string)post_param('location', '');
        
        if ($attendanceId <= 0) {
            flash_set('error', 'Attendance record ID is required.');
            redirect('security_attendance.php?estate_id=' . $estateId);
        }
        
        try {
            $currentTime = date('Y-m-d H:i:s');
            
            // Update the clock out time
            $db->execute(
                "UPDATE security_attendance 
                 SET clock_out_time = ?, clock_out_location = ?
                 WHERE id = ?",
                [$currentTime, $location, $attendanceId]
            );
            
            flash_set('success', 'Clock out recorded successfully.');
        } catch (Throwable $e) {
            flash_set('error', 'Failed to record clock out: ' . $e->getMessage());
        }
        
        redirect('security_attendance.php?estate_id=' . $estateId);
    }
}

// Get security personnel for the estate
$securityPersonnel = [];
if (is_super_admin()) {
    $securityPersonnel = $db->fetchAll("
        SELECT sp.*, u.first_name, u.last_name, u.email, u.phone
        FROM security_personnel sp
        LEFT JOIN users u ON sp.user_id = u.id
        ORDER BY u.first_name, u.last_name
    ");
} else {
    $estateIds = allowed_estate_ids();
    if ($estateIds) {
        $placeholders = str_repeat('?,', count($estateIds) - 1) . '?';
        $securityPersonnel = $db->fetchAll("
            SELECT sp.*, u.first_name, u.last_name, u.email, u.phone
            FROM security_personnel sp
            LEFT JOIN users u ON sp.user_id = u.id
            WHERE sp.estate_id IN ($placeholders)
            ORDER BY u.first_name, u.last_name
        ", $estateIds);
    }
}

// Get today's attendance records
$today = date('Y-m-d');
$todayAttendance = $db->fetchAll("
    SELECT sa.*, sp.badge_number, u.first_name, u.last_name
    FROM security_attendance sa
    LEFT JOIN security_personnel sp ON sa.security_personnel_id = sp.id
    LEFT JOIN users u ON sp.user_id = u.id
    WHERE sa.date = ? AND sa.estate_id = ?
    ORDER BY sa.created_at DESC
", [$today, $estateId]);

// Get attendance for date range for reporting
$reportDateFrom = get_param('date_from', date('Y-m-01'));
$reportDateTo = get_param('date_to', date('Y-m-d'));

$reportAttendance = $db->fetchAll("
    SELECT sa.*, sp.badge_number, u.first_name, u.last_name
    FROM security_attendance sa
    LEFT JOIN security_personnel sp ON sa.security_personnel_id = sp.id
    LEFT JOIN users u ON sp.user_id = u.id
    WHERE sa.estate_id = ? AND sa.date BETWEEN ? AND ?
    ORDER BY sa.date DESC, sa.created_at DESC
", [$estateId, $reportDateFrom, $reportDateTo]);

require __DIR__ . '/partials/top.php';
?>

<!--begin::Container-->
<div id="kt_app_content" class="app-content flex-column-fluid">
    <div id="kt_app_content_container" class="app-container container-fluid">
        <!--begin::Row-->
        <div class="row g-6 g-xl-9">
            <!--begin::Col-->
            <div class="col-12 mb-5">
                <!--begin::Card-->
                <div class="card">
                    <!--begin::Card header-->
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            <h3 class="fw-bold m-0">Security Personnel Attendance</h3>
                        </div>
                        <div class="card-toolbar">
                            <div class="d-flex gap-3">
                                <form method="GET" class="d-flex gap-2">
                                    <input type="hidden" name="estate_id" value="<?= e($estateId) ?>">
                                    <input type="date" class="form-control form-control-sm w-auto" name="date_from" value="<?= e($reportDateFrom) ?>">
                                    <span class="align-self-center">to</span>
                                    <input type="date" class="form-control form-control-sm w-auto" name="date_to" value="<?= e($reportDateTo) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!--end::Card header-->
                    <!--begin::Card body-->
                    <div class="card-body py-4">
                        <?php 
                        $flash = flash_get();
                        if ($flash): ?>
                            <?php
                                $type = $flash['type'] ?? 'info';
                                $message = $flash['message'] ?? '';
                                $alert = 'alert-info';
                                if ($type === 'success') $alert = 'alert-success';
                                if ($type === 'error') $alert = 'alert-danger';
                                if ($type === 'warning') $alert = 'alert-warning';
                            ?>
                            <div class="alert <?= e($alert) ?> d-flex align-items-center mb-5" role="alert">
                                <i class="ki-duotone ki-information fs-2 me-3"></i>
                                <div><?= e($message) ?></div>
                            </div>
                        <?php endif; ?>

                        <!--begin::Attendance Stats-->
                        <div class="row g-6 mb-8">
                            <?php
                            $presentToday = 0;
                            $absentToday = 0;
                            $lateToday = 0;
                            $onDuty = 0;
                            
                            foreach ($todayAttendance as $attendance) {
                                if ($attendance['status'] === 'present') {
                                    $presentToday++;
                                    if ($attendance['clock_out_time'] === null) {
                                        $onDuty++;
                                    }
                                }
                            }
                            
                            $totalScheduled = count($securityPersonnel);
                            $absentToday = $totalScheduled - $presentToday;
                            ?>
                            
                            <div class="col-sm-3">
                                <div class="card bg-light-primary border border-primary h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="fs-2qx fw-bold"><?= $totalScheduled ?></div>
                                        <div class="fs-6 fw-semibold text-gray-700">Total Security Personnel</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-sm-3">
                                <div class="card bg-light-success border border-success h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="fs-2qx fw-bold"><?= $presentToday ?></div>
                                        <div class="fs-6 fw-semibold text-gray-700">Present Today</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-sm-3">
                                <div class="card bg-light-danger border border-danger h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="fs-2qx fw-bold"><?= $onDuty ?></div>
                                        <div class="fs-6 fw-semibold text-gray-700">Currently On Duty</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-sm-3">
                                <div class="card bg-light-warning border border-warning h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="fs-2qx fw-bold"><?= $absentToday ?></div>
                                        <div class="fs-6 fw-semibold text-gray-700">Absent Today</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!--end::Attendance Stats-->

                        <!--begin::Quick Actions-->
                        <div class="card mb-8">
                            <div class="card-header border-0">
                                <div class="card-title">
                                    <h4>Quick Actions</h4>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row g-6">
                                    <?php foreach ($securityPersonnel as $person): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card border hover-elevate-up">
                                                <div class="card-body">
                                                    <div class="d-flex align-items-center">
                                                        <div class="symbol symbol-40px me-3">
                                                            <div class="symbol-label fs-3 bg-light-primary text-primary">
                                                                <?= e(substr($person['first_name'], 0, 1) . substr($person['last_name'], 0, 1)) ?>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="fw-bold"><?= e($person['first_name'] . ' ' . $person['last_name']) ?></div>
                                                            <div class="text-muted fs-7"><?= e($person['badge_number']) ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="mt-3">
                                                        <?php 
                                                        $personAttendance = null;
                                                        foreach ($todayAttendance as $att) {
                                                            if ($att['security_personnel_id'] == $person['id']) {
                                                                $personAttendance = $att;
                                                                break;
                                                            }
                                                        }
                                                        
                                                        if ($personAttendance && $personAttendance['clock_in_time']) {
                                                            if ($personAttendance['clock_out_time']) {
                                                                echo '<span class="badge badge-light-success">Clocked Out</span>';
                                                                echo '<div class="text-muted fs-7 mt-1">';
                                                                echo 'In: ' . date('h:i A', strtotime($personAttendance['clock_in_time']));
                                                                echo ' | Out: ' . date('h:i A', strtotime($personAttendance['clock_out_time']));
                                                                echo '</div>';
                                                            } else {
                                                                echo '<span class="badge badge-light-info">On Duty</span>';
                                                                echo '<div class="text-muted fs-7 mt-1">';
                                                                echo 'Clocked in at: ' . date('h:i A', strtotime($personAttendance['clock_in_time']));
                                                                echo '</div>';
                                                            }
                                                        } else {
                                                            echo '<span class="badge badge-light-danger">Not Clocked In</span>';
                                                        }
                                                        ?>
                                                    </div>
                                                    <?php if (!$personAttendance || !$personAttendance['clock_in_time']): ?>
                                                        <form method="POST" class="mt-3">
                                                            <input type="hidden" name="action" value="clock_in">
                                                            <input type="hidden" name="security_personnel_id" value="<?= e($person['id']) ?>">
                                                            <input type="hidden" name="shift_type" value="<?= e($person['shift_schedule']) ?>">
                                                            <?= csrf_field() ?>
                                                            <div class="row g-2">
                                                                <div class="col-8">
                                                                    <input type="text" class="form-control form-control-sm" name="location" placeholder="Location" required>
                                                                </div>
                                                                <div class="col-4">
                                                                    <button type="submit" class="btn btn-sm btn-success w-100">Clock In</button>
                                                                </div>
                                                            </div>
                                                        </form>
                                                    <?php elseif ($personAttendance && !$personAttendance['clock_out_time']): ?>
                                                        <form method="POST" class="mt-3" onsubmit="return confirm('Are you sure this security personnel is clocking out?');">
                                                            <input type="hidden" name="action" value="clock_out">
                                                            <input type="hidden" name="attendance_id" value="<?= e($personAttendance['id']) ?>">
                                                            <?= csrf_field() ?>
                                                            <div class="row g-2">
                                                                <div class="col-8">
                                                                    <input type="text" class="form-control form-control-sm" name="location" placeholder="Location" required>
                                                                </div>
                                                                <div class="col-4">
                                                                    <button type="submit" class="btn btn-sm btn-warning w-100">Clock Out</button>
                                                                </div>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <!--end::Quick Actions-->

                        <!--begin::Attendance Records Table-->
                        <div class="card">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    <h4>Attendance Records (<?= e(date('M j, Y', strtotime($reportDateFrom))) ?> - <?= e(date('M j, Y', strtotime($reportDateTo))) ?>)</h4>
                                </div>
                            </div>
                            <div class="card-body py-0">
                                <?php if (empty($reportAttendance)): ?>
                                    <div class="text-center py-10">
                                        <i class="ki-duotone ki-abstract-41 fs-8x text-gray-400 mb-5">
                                            <span class="path1"></span>
                                            <span class="path2"></span>
                                        </i>
                                        <div class="fs-4 fw-bold text-gray-400">No attendance records found</div>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                                            <thead>
                                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                                    <th class="min-w-125px">Name</th>
                                                    <th class="min-w-100px">Badge #</th>
                                                    <th class="min-w-100px">Date</th>
                                                    <th class="min-w-100px">Shift</th>
                                                    <th class="min-w-125px">Clock In</th>
                                                    <th class="min-w-125px">Clock Out</th>
                                                    <th class="min-w-100px">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="text-gray-600 fw-semibold">
                                                <?php foreach ($reportAttendance as $attendance): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="me-3">
                                                                    <div class="symbol symbol-circle symbol-40px overflow-hidden me-3">
                                                                        <div class="symbol-label fs-3 bg-light-primary text-primary">
                                                                            <?= e(substr($attendance['first_name'], 0, 1) . substr($attendance['last_name'], 0, 1)) ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="d-flex flex-column">
                                                                    <a href="#" class="text-gray-800 text-hover-primary mb-1"><?= e($attendance['first_name'] . ' ' . $attendance['last_name']) ?></a>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td><?= e($attendance['badge_number'] ?? 'N/A') ?></td>
                                                        <td><?= e(date('M j, Y', strtotime($attendance['date']))) ?></td>
                                                        <td>
                                                            <div class="badge badge-light-info"><?= e($attendance['shift_type']) ?></div>
                                                        </td>
                                                        <td>
                                                            <?php if ($attendance['clock_in_time']): ?>
                                                                <div><?= date('h:i A', strtotime($attendance['clock_in_time'])) ?></div>
                                                                <div class="text-muted fs-7"><?= e($attendance['clock_in_location'] ?? 'N/A') ?></div>
                                                            <?php else: ?>
                                                                <span class="text-muted">Not Clocked In</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($attendance['clock_out_time']): ?>
                                                                <div><?= date('h:i A', strtotime($attendance['clock_out_time'])) ?></div>
                                                                <div class="text-muted fs-7"><?= e($attendance['clock_out_location'] ?? 'N/A') ?></div>
                                                            <?php else: ?>
                                                                <span class="text-warning">Still on Duty</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="badge 
                                                                <?php if ($attendance['status'] === 'present'): ?>
                                                                    badge-light-success
                                                                <?php elseif ($attendance['status'] === 'absent'): ?>
                                                                    badge-light-danger
                                                                <?php elseif ($attendance['status'] === 'late'): ?>
                                                                    badge-light-warning
                                                                <?php else: ?>
                                                                    badge-light
                                                                <?php endif; ?>
                                                            ">
                                                                <?= e(ucfirst(str_replace('_', ' ', $attendance['status']))) ?>
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
                        <!--end::Attendance Records Table-->
                    </div>
                    <!--end::Card body-->
                </div>
                <!--end::Card-->
            </div>
            <!--end::Col-->
        </div>
        <!--end::Row-->
    </div>
    <!--end::Container-->
</div>


    <!--end::Container-->
</div>
</div>

<script src="../../assets/plugins/global/plugins.bundle.js"></script>
<script src="../../assets/js/scripts.bundle.js"></script>
<script src="../../assets/plugins/custom/datatables/datatables.bundle.js"></script>
</body>
</html>