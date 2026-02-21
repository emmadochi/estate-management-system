<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Attendance – EstatePro';
$pageHeading = 'Attendance';
$db = db();
$me = current_user();
$securityPersonnel = db()->fetchOne("SELECT * FROM security_personnel WHERE user_id = ?", [$me['id']]);

if (!$securityPersonnel) {
    flash_set('error', 'Security personnel profile not found.');
    redirect('index.php');
}

$method = request_method();

// Handle attendance actions
if ($method === 'POST') {
    verify_csrf();
    $action = (string)post_param('action', '');
    
    if ($action === 'clock_in') {
        $shiftType = (string)post_param('shift_type', 'morning');
        $location = (string)post_param('location', '');
        
        try {
            $today = date('Y-m-d');
            $currentTime = date('Y-m-d H:i:s');
            
            // Check if already clocked in today
            $existingAttendance = $db->fetchOne(
                "SELECT id, clock_in_time FROM security_attendance 
                 WHERE security_personnel_id = ? AND date = ?",
                [$securityPersonnel['id'], $today]
            );
            
            if ($existingAttendance) {
                // Check if already clocked out
                if ($existingAttendance['clock_out_time']) {
                    flash_set('error', 'You have already clocked out for today. Contact admin for assistance.');
                    redirect('attendance.php');
                } else {
                    flash_set('error', 'You are already clocked in for today.');
                    redirect('attendance.php');
                }
            } else {
                // Create new attendance record
                $db->insert(
                    "INSERT INTO security_attendance 
                     (security_personnel_id, estate_id, date, shift_type, clock_in_time, clock_in_location, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$securityPersonnel['id'], $securityPersonnel['estate_id'], $today, $shiftType, $currentTime, $location, 'present']
                );
                
                flash_set('success', 'Clock in recorded successfully at ' . date('h:i A'));
            }
        } catch (Throwable $e) {
            flash_set('error', 'Failed to record clock in: ' . $e->getMessage());
        }
        
        redirect('attendance.php');
    }
    
    if ($action === 'clock_out') {
        $location = (string)post_param('location', '');
        
        try {
            $today = date('Y-m-d');
            $currentTime = date('Y-m-d H:i:s');
            
            // Find today's attendance record that hasn't been clocked out yet
            $attendanceRecord = $db->fetchOne(
                "SELECT id FROM security_attendance 
                 WHERE security_personnel_id = ? AND date = ? AND clock_out_time IS NULL",
                [$securityPersonnel['id'], $today]
            );
            
            if (!$attendanceRecord) {
                flash_set('error', 'No active clock-in record found for today.');
            } else {
                // Update the clock out time
                $db->execute(
                    "UPDATE security_attendance 
                     SET clock_out_time = ?, clock_out_location = ?
                     WHERE id = ?",
                    [$currentTime, $location, $attendanceRecord['id']]
                );
                
                flash_set('success', 'Clock out recorded successfully at ' . date('h:i A'));
            }
        } catch (Throwable $e) {
            flash_set('error', 'Failed to record clock out: ' . $e->getMessage());
        }
        
        redirect('attendance.php');
    }
}

// Get today's attendance for this security personnel
$today = date('Y-m-d');
$todayAttendance = $db->fetchOne("
    SELECT * FROM security_attendance 
    WHERE security_personnel_id = ? AND date = ?
    ORDER BY created_at DESC LIMIT 1
", [$securityPersonnel['id'], $today]);

// Get recent attendance records
$recentAttendance = $db->fetchAll("
    SELECT * FROM security_attendance 
    WHERE security_personnel_id = ? 
    ORDER BY date DESC, created_at DESC 
    LIMIT 10
", [$securityPersonnel['id']]);

$toolbarActions = '';

require __DIR__ . '/partials/top.php';
?>
<div class="row g-6 g-xl-9">
            <!--begin::Col-->
            <div class="col-12 mb-5">
                <!--begin::Card-->
                <div class="card">
                    <!--begin::Card header-->
                    <div class="card-header border-0 pt-6">
                        <div class="card-title">
                            <h3 class="fw-bold m-0">Attendance Tracking</h3>
                        </div>
                        <div class="card-toolbar">
                            <span class="badge badge-light-primary fs-5"><?= e($securityPersonnel['badge_number']) ?></span>
                        </div>
                    </div>
                    <!--end::Card header-->
                    <!--begin::Card body-->
                    <div class="card-body py-4">
                        <!--begin::Attendance Status Card-->
                        <div class="card mb-8">
                            <div class="card-body text-center py-10">
                                <div class="mb-5">
                                    <i class="ki-duotone ki-calendar fs-8x text-primary">
                                        <span class="path1"></span>
                                        <span class="path2"></span>
                                    </i>
                                </div>
                                <h4 class="card-title mb-2">Today's Attendance</h4>
                                <p class="text-muted mb-6"><?= e(date('l, F j, Y')) ?></p>
                                
                                <?php if ($todayAttendance): ?>
                                    <?php if ($todayAttendance['clock_in_time']): ?>
                                        <div class="mb-4">
                                            <div class="badge badge-light-success fs-2 px-8 py-4 mb-3">
                                                Clocked In
                                            </div>
                                            <p class="mb-2">Clocked in at: <strong><?= date('h:i A', strtotime($todayAttendance['clock_in_time'])) ?></strong></p>
                                            <p class="text-muted">Location: <?= e($todayAttendance['clock_in_location'] ?? 'N/A') ?></p>
                                        </div>
                                        
                                        <?php if (!$todayAttendance['clock_out_time']): ?>
                                            <!-- Clock Out Button -->
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to clock out?');">
                                                <input type="hidden" name="action" value="clock_out">
                                                <?= csrf_field() ?>
                                                <div class="row g-2 justify-content-center">
                                                    <div class="col-lg-6">
                                                        <input type="text" class="form-control form-control-lg" name="location" placeholder="Current Location" required>
                                                    </div>
                                                </div>
                                                <button type="submit" class="btn btn-warning btn-lg mt-3 px-8">
                                                    <i class="ki-duotone ki-exit-right fs-2">
                                                        <span class="path1"></span>
                                                        <span class="path2"></span>
                                                    </i>
                                                    Clock Out
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <div class="mt-4">
                                                <div class="badge badge-light-dark fs-3 px-6 py-3">
                                                    Shift Completed
                                                </div>
                                                <p class="mb-2">Clocked out at: <strong><?= date('h:i A', strtotime($todayAttendance['clock_out_time'])) ?></strong></p>
                                                <p class="text-muted">Location: <?= e($todayAttendance['clock_out_location'] ?? 'N/A') ?></p>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Clock In Button -->
                                        <form method="POST">
                                            <input type="hidden" name="action" value="clock_in">
                                            <input type="hidden" name="shift_type" value="<?= e($securityPersonnel['shift_schedule']) ?>">
                                            <?= csrf_field() ?>
                                            <div class="row g-2 justify-content-center mb-4">
                                                <div class="col-lg-6">
                                                    <input type="text" class="form-control form-control-lg" name="location" placeholder="Current Location" required>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-success btn-lg px-8">
                                                <i class="ki-duotone ki-enter-left fs-2">
                                                    <span class="path1"></span>
                                                    <span class="path2"></span>
                                                </i>
                                                Clock In
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Clock In Button -->
                                    <form method="POST">
                                        <input type="hidden" name="action" value="clock_in">
                                        <input type="hidden" name="shift_type" value="<?= e($securityPersonnel['shift_schedule']) ?>">
                                        <?= csrf_field() ?>
                                        <div class="row g-2 justify-content-center mb-4">
                                            <div class="col-lg-6">
                                                <input type="text" class="form-control form-control-lg" name="location" placeholder="Current Location" required>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-success btn-lg px-8">
                                            <i class="ki-duotone ki-enter-left fs-2">
                                                <span class="path1"></span>
                                                <span class="path2"></span>
                                            </i>
                                            Clock In
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!--end::Attendance Status Card-->

                        <!--begin::Recent Attendance History-->
                        <div class="card">
                            <div class="card-header border-0 pt-6">
                                <div class="card-title">
                                    <h4>Recent Attendance History</h4>
                                </div>
                            </div>
                            <div class="card-body py-0">
                                <?php if (empty($recentAttendance)): ?>
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
                                                    <th class="min-w-100px">Date</th>
                                                    <th class="min-w-100px">Shift</th>
                                                    <th class="min-w-125px">Clock In</th>
                                                    <th class="min-w-125px">Clock Out</th>
                                                    <th class="min-w-100px">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="text-gray-600 fw-semibold">
                                                <?php foreach ($recentAttendance as $attendance): ?>
                                                    <tr>
                                                        <td><?= e(date('M j, Y', strtotime($attendance['date']))) ?></td>
                                                        <td>
                                                            <div class="badge badge-light-info"><?= e($attendance['shift_type']) ?></div>
                                                        </td>
                                                        <td>
                                                            <?php if ($attendance['clock_in_time']): ?>
                                                                <div><?= date('h:i A', strtotime($attendance['clock_in_time'])) ?></div>
                                                                <div class="text-muted fs-7"><?= e($attendance['clock_in_location'] ?? 'N/A') ?></div>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($attendance['clock_out_time']): ?>
                                                                <div><?= date('h:i A', strtotime($attendance['clock_out_time'])) ?></div>
                                                                <div class="text-muted fs-7"><?= e($attendance['clock_out_location'] ?? 'N/A') ?></div>
                                                            <?php else: ?>
                                                                <span class="text-warning">On Duty</span>
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
                        <!--end::Recent Attendance History-->
                    </div>
                    <!--end::Card body-->
                </div>
                <!--end::Card-->
            </div>
        </div>

<?php require __DIR__ . '/partials/bottom.php'; ?>