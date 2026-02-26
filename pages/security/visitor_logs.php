<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['security']);

$pageTitle = 'Visitor Logs – EstatePro';
$pageHeading = 'Visitor Logs';
$db = db();
$me = current_user();
// Get security personnel information to determine their assigned estate
$securityPersonnel = $db->fetchOne(
    "SELECT * FROM security_personnel WHERE user_id = ?",
    [$me['id']]
);

// Determine the estate ID - prioritize security personnel assignment over allowed_estate_ids
$estateId = 0;
if ($securityPersonnel && isset($securityPersonnel['estate_id']) && $securityPersonnel['estate_id']) {
    $estateId = (int)$securityPersonnel['estate_id'];
} else {
    // Fallback to allowed_estate_ids if no direct security assignment
    $estateIds = allowed_estate_ids();
    if (!empty($estateIds)) {
        $estateId = (int)$estateIds[0];
    }
}

// Show warning if no estate is assigned to the security personnel
if (!$estateId) {
    flash_set('warning', 'No estate assigned to your security profile. Please contact the system administrator to assign an estate to your account.');
}

// Get units and tenants for the estate
$units = [];
$tenants = [];
if ($estateId) {
    $units = $db->fetchAll(
        "SELECT id, unit_number FROM units WHERE estate_id = ? ORDER BY unit_number",
        [$estateId]
    );
    
    $tenants = $db->fetchAll(
        "SELECT t.id, t.emergency_contact_name, u.id as unit_id, u.unit_number 
         FROM tenants t 
         LEFT JOIN units u ON t.unit_id = u.id 
         WHERE t.estate_id = ? 
         ORDER BY t.emergency_contact_name",
        [$estateId]
    );
}

$method = request_method();
if ($method === 'POST') {
    verify_csrf();
    $action = (string) post_param('action', '');
    
    // Handle visitor registration
    if ($action === 'register') {
        $errors = [];
        
        $visitorName = trim((string) post_param('visitor_name', ''));
        $visitorPhone = trim((string) post_param('visitor_phone', ''));
        $unitId = (int) post_param('unit_id', 0);
        $tenantId = (int) post_param('tenant_id', 0);
        $purpose = trim((string) post_param('purpose', ''));
        $vehicleRegistration = trim((string) post_param('vehicle_registration', ''));
        $driverLicense = trim((string) post_param('driver_license', ''));
        $hostName = trim((string) post_param('host_name', ''));
        $hostPhone = trim((string) post_param('host_phone', ''));
        $specialInstructions = trim((string) post_param('special_instructions', ''));
        $emergencyContactVisitor = trim((string) post_param('emergency_contact_visitor', ''));
        $emergencyContactPhoneVisitor = trim((string) post_param('emergency_contact_phone_visitor', ''));
        
        // Validation
        if (empty($visitorName)) {
            $errors[] = 'Visitor name is required';
        }
        if (empty($unitId)) {
            $errors[] = 'Unit is required';
        }
        if (empty($tenantId)) {
            $errors[] = 'Tenant is required';
        }
        if (empty($purpose)) {
            $errors[] = 'Purpose of visit is required';
        }
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();
                
                // Generate gate pass number
                $gatePassNumber = 'GP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                
                // Generate QR code for the visitor
                $qrCode = 'VISITOR_' . $gatePassNumber . '_' . time();
                
                // Insert visitor log
                $db->insert(
                    "INSERT INTO visitor_logs (
                        estate_id, unit_id, tenant_id, visitor_name, visitor_phone, purpose,
                        entry_time, gate_pass_number, vehicle_registration, driver_license, 
                        host_name, host_phone, special_instructions, emergency_contact_visitor,
                        emergency_contact_phone_visitor, status, logged_by, qr_code
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'checked_in', ?, ?)",
                    [
                        $estateId, $unitId, $tenantId, $visitorName, $visitorPhone, $purpose,
                        $gatePassNumber, $vehicleRegistration, $driverLicense, $hostName,
                        $hostPhone, $specialInstructions, $emergencyContactVisitor,
                        $emergencyContactPhoneVisitor, $me['id'], $qrCode
                    ]
                );
                
                $db->commit();
                flash_set('success', 'Visitor registered and checked in successfully. Gate pass: ' . $gatePassNumber);
                redirect('visitor_logs.php');
                
            } catch (Throwable $e) {
                $db->rollBack();
                flash_set('error', 'Failed to register visitor: ' . $e->getMessage());
            }
        } else {
            $errorMessages = implode('<br>', $errors);
            flash_set('error', $errorMessages);
        }
    }
    
    // Handle visitor checkout
    if ($action === 'checkout') {
        $visitorId = (int) post_param('visitor_id', 0);
        if ($visitorId && $estateId) {
            try {
                $db->execute(
                    "UPDATE visitor_logs SET exit_time = NOW(), status = 'checked_out' WHERE id = ? AND estate_id = ? AND status = 'checked_in'",
                    [$visitorId, $estateId]
                );
                flash_set('success', 'Visitor checked out successfully.');
            } catch (Throwable $e) {
                flash_set('error', 'Failed to check out: ' . $e->getMessage());
            }
        }
        redirect('visitor_logs.php');
    }
}

$visitorLogs = [];
if ($estateId) {
    $visitorLogs = $db->fetchAll(
        "SELECT vl.*, u.unit_number, t.emergency_contact_name AS tenant_name
         FROM visitor_logs vl
         LEFT JOIN units u ON vl.unit_id = u.id
         LEFT JOIN tenants t ON vl.tenant_id = t.id
         WHERE vl.estate_id = ?
         ORDER BY vl.entry_time DESC
         LIMIT 100",
        [$estateId]
    );
}

$toolbarActions = '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qrScannerModal"><i class="ki-duotone ki-qr-code fs-2"></i>Scan QR</button> <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#registerVisitorModal"><i class="ki-duotone ki-plus fs-2"></i>Register Visitor</button>';

require __DIR__ . '/partials/top.php';
?>

<div class="row g-6 g-xl-9">
    <!-- QR Code Scanner Modal -->
    <div class="modal fade" id="qrScannerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Scan QR Code</h3>
                    <div class="btn btn-sm btn-icon btn-active-color-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                </div>
                <div class="modal-body">
                    <div id="qr-reader" style="width: 100%; height: 300px;"></div>
                    <div id="qr-reader-results"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Registration Modal -->
    <div class="modal fade" id="registerVisitorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Register New Visitor</h3>
                    <div class="btn btn-sm btn-icon btn-active-color-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                </div>
                <form method="post" id="visitorRegistrationForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="register">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="required form-label">Visitor Full Name</label>
                                    <input type="text" class="form-control" name="visitor_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Visitor Phone</label>
                                    <input type="tel" class="form-control" name="visitor_phone">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="required form-label">Unit Visiting</label>
                                    <select class="form-select" name="unit_id" id="unitSelect" required>
                                        <option value="">Select Unit</option>
                                        <?php foreach ($units as $unit): ?>
                                            <option value="<?= (int)$unit['id'] ?>"><?= e($unit['unit_number']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="required form-label">Tenant/Host</label>
                                    <select class="form-select" name="tenant_id" id="tenantSelect" required>
                                        <option value="">Select Tenant</option>
                                        <?php foreach ($tenants as $tenant): ?>
                                            <option value="<?= (int)$tenant['id'] ?>" data-unit="<?= e($tenant['unit_number'] ?? '') ?>" data-unit-id="<?= (int)$tenant['unit_id'] ?? 0 ?>"><?= e($tenant['emergency_contact_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-5">
                            <label class="required form-label">Purpose of Visit</label>
                            <select class="form-select" name="purpose" id="purposeSelect" required>
                                <option value="">Select Purpose</option>
                                <option value="Personal Visit">Personal Visit</option>
                                <option value="Business Meeting">Business Meeting</option>
                                <option value="Delivery">Delivery</option>
                                <option value="Service/Repair">Service/Repair</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Vendor Visit">Vendor Visit</option>
                                <option value="Contractor">Contractor</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Vehicle Registration</label>
                                    <input type="text" class="form-control" name="vehicle_registration" placeholder="e.g. ABC-123-DE">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Driver License</label>
                                    <input type="text" class="form-control" name="driver_license">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Host Name</label>
                                    <input type="text" class="form-control" name="host_name">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Host Phone</label>
                                    <input type="tel" class="form-control" name="host_phone">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Emergency Contact (Visitor)</label>
                                    <input type="text" class="form-control" name="emergency_contact_visitor">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-5">
                                    <label class="form-label">Emergency Contact Phone</label>
                                    <input type="tel" class="form-control" name="emergency_contact_phone_visitor">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-5">
                            <label class="form-label">Special Instructions</label>
                            <textarea class="form-control" name="special_instructions" rows="2" placeholder="Any special instructions or notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <span class="indicator-label">Register & Check In</span>
                            <span class="indicator-progress">Please wait...<span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-12 mb-5">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Visitor Logs</h3>
                </div>
                <div class="card-toolbar">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#qrScannerModal"><i class="ki-duotone ki-qr-code fs-2"></i>Scan QR</button>
                </div>
                <?php if ($securityPersonnel): ?>
                <div class="card-toolbar">
                    <span class="badge badge-light-primary fs-5"><?= e($securityPersonnel['badge_number']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body pt-0">
                <!-- Quick Registration Buttons -->
                <div class="mb-6">
                    <div class="d-flex flex-wrap gap-3">
                        <button type="button" class="btn btn-light-primary btn-sm" onclick="quickRegister('Delivery')">
                            <i class="ki-duotone ki-delivery fs-2"><span class="path1"></span><span class="path2"></span></i>
                            Delivery
                        </button>
                        <button type="button" class="btn btn-light-success btn-sm" onclick="quickRegister('Service/Repair')">
                            <i class="ki-duotone ki-setting fs-2"><span class="path1"></span><span class="path2"></span></i>
                            Service
                        </button>
                        <button type="button" class="btn btn-light-warning btn-sm" onclick="quickRegister('Maintenance')">
                            <i class="ki-duotone ki-tools fs-2"><span class="path1"></span><span class="path2"></span></i>
                            Maintenance
                        </button>
                        <button type="button" class="btn btn-light-info btn-sm" onclick="quickRegister('Personal Visit')">
                            <i class="ki-duotone ki-profile-user fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                            Personal Visit
                        </button>
                        <button type="button" class="btn btn-light-danger btn-sm" onclick="quickRegister('Inspection')">
                            <i class="ki-duotone ki-eye fs-2"><span class="path1"></span><span class="path2"></span></i>
                            Inspection
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Search input -->
                <div class="mb-4">
                    <div class="position-relative">
                        <i class="ki-duotone ki-magnifier fs-2 text-muted position-absolute top-50 translate-middle-y ms-3"><span class="path1"></span><span class="path2"></span></i>
                        <input type="text" id="searchInput" class="form-control form-control-solid ps-10" placeholder="Search visitors...">
                    </div>
                </div>
            </div>
            <div class="card-body py-0">
                <?php if (empty($visitorLogs)): ?>
                    <?php $iconClass = 'ki-profile-user'; $message = 'No visitor logs found.'; require __DIR__ . '/partials/empty_state.php'; ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th class="min-w-120px">Visitor</th>
                                    <th class="min-w-80px">Unit</th>
                                    <th class="min-w-100px">Host / Tenant</th>
                                    <th class="min-w-100px">Entry</th>
                                    <th class="min-w-100px">Exit</th>
                                    <th class="min-w-90px">Gate pass</th>
                                    <th class="min-w-70px">QR</th>
                                    <th class="min-w-90px">Status</th>
                                    <th class="min-w-100px text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold" id="visitorLogsTableBody">
                                <?php foreach ($visitorLogs as $vl): 
                                    $status = $vl['status'] ?? 'pending';
                                ?>
                                <tr data-search="<?= e(strtolower($vl['visitor_name'] . ' ' . $vl['visitor_phone'] . ' ' . $vl['unit_number'] . ' ' . $vl['tenant_name'] . ' ' . $vl['host_name'] . ' ' . $vl['gate_pass_number'])) ?>">
                                    <td>
                                        <div><?= e($vl['visitor_name'] ?? '—') ?></div>
                                        <?php if (!empty($vl['visitor_phone'])): ?>
                                        <div class="text-muted fs-7"><?= e($vl['visitor_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($vl['unit_number'] ?? '—') ?></td>
                                    <td><?= e($vl['tenant_name'] ?? $vl['host_name'] ?? '—') ?></td>
                                    <td><?= $vl['entry_time'] ? date('M j, H:i', strtotime($vl['entry_time'])) : '—' ?></td>
                                    <td><?= !empty($vl['exit_time']) ? date('M j, H:i', strtotime($vl['exit_time'])) : '—' ?></td>
                                    <td><?= e($vl['gate_pass_number'] ?? '—') ?></td>
                                                                        <td><?php if (!empty($vl['qr_code'])): ?><i class="ki-duotone ki-qr-code fs-3 cursor-pointer text-primary" onclick="showQRCode('<?= e(addslashes($vl['qr_code'])) ?>')"></i><?php else: ?>—<?php endif; ?></td>
                                    <td>
                                        <?php
                                        $badge = 'badge-light';
                                        if ($status === 'checked_in') $badge = 'badge-light-success';
                                        elseif ($status === 'checked_out') $badge = 'badge-light-dark';
                                        elseif ($status === 'pending') $badge = 'badge-light-warning';
                                        ?>
                                        <span class="badge <?= $badge ?>"><?= e(ucfirst(str_replace('_', ' ', $status))) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($status === 'checked_in'): ?>
                                        <form method="post" class="d-inline visitor-checkout-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="checkout">
                                            <input type="hidden" name="visitor_id" value="<?= (int)$vl['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light-warning" onclick="return confirm('Check out this visitor?');">Check out</button>
                                        </form>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
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

<script>
// Auto-filter tenants based on selected unit
document.getElementById('unitSelect').addEventListener('change', function() {
    const unitId = this.value;
    const tenantSelect = document.getElementById('tenantSelect');
    const tenantOptions = tenantSelect.querySelectorAll('option[data-unit]');
    
    // Reset tenant selection
    tenantSelect.value = '';
    
    // Show/hide tenants based on unit
    tenantOptions.forEach(option => {
        if (unitId === '' || option.dataset.unit === unitId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
});

// Form submission with loading state
document.getElementById('visitorRegistrationForm').addEventListener('submit', function() {
    const submitButton = this.querySelector('button[type="submit"]');
    const indicatorLabel = submitButton.querySelector('.indicator-label');
    const indicatorProgress = submitButton.querySelector('.indicator-progress');
    
    // Show loading state
    indicatorLabel.classList.add('d-none');
    indicatorProgress.classList.remove('d-none');
    submitButton.disabled = true;
});

// Reset form when modal is closed
document.getElementById('registerVisitorModal').addEventListener('hidden.bs.modal', function() {
    const form = document.getElementById('visitorRegistrationForm');
    form.reset();
    
    // Reset tenant dropdown
    const tenantOptions = document.getElementById('tenantSelect').querySelectorAll('option[data-unit]');
    tenantOptions.forEach(option => {
        option.style.display = 'block';
    });
    
    // Reset button state
    const submitButton = form.querySelector('button[type="submit"]');
    const indicatorLabel = submitButton.querySelector('.indicator-label');
    const indicatorProgress = submitButton.querySelector('.indicator-progress');
    
    indicatorLabel.classList.remove('d-none');
    indicatorProgress.classList.add('d-none');
    submitButton.disabled = false;
});

// Quick registration for frequent visitor types
function quickRegister(purpose) {
    const purposeSelect = document.querySelector('select[name="purpose"]');
    purposeSelect.value = purpose;
    
    // Trigger change event to apply any logic
    const event = new Event('change', { bubbles: true });
    purposeSelect.dispatchEvent(event);
    
    // Focus on visitor name field
    document.querySelector('input[name="visitor_name"]').focus();
}

// Auto-populate fields based on purpose selection
const purposeSelect = document.getElementById('purposeSelect');
purposeSelect.addEventListener('change', function() {
    const purpose = this.value;
    
    // Auto-fill host name based on common purposes
    if (purpose.includes('Delivery') || purpose.includes('Service')) {
        const hostNameField = document.querySelector('input[name="host_name"]');
        if (hostNameField.value.trim() === '') {
            hostNameField.focus();
        }
    }
    
    // Auto-fill special instructions based on purpose
    if (purpose.includes('Delivery') && document.querySelector('textarea[name="special_instructions"]').value.trim() === '') {
        document.querySelector('textarea[name="special_instructions"]').value = 'Package delivery for resident';
    } else if (purpose.includes('Maintenance') && document.querySelector('textarea[name="special_instructions"]').value.trim() === '') {
        document.querySelector('textarea[name="special_instructions"]').value = 'Maintenance work authorized by estate management';
    } else if (purpose.includes('Service') && document.querySelector('textarea[name="special_instructions"]').value.trim() === '') {
        document.querySelector('textarea[name="special_instructions"]').value = 'Service appointment - please verify credentials';
    }
});

// Auto-select tenant based on unit selection
const unitSelect = document.getElementById('unitSelect');
unitSelect.addEventListener('change', function() {
    const unitId = this.value;
    const tenantSelect = document.getElementById('tenantSelect');
    
    if (unitId) {
        // Reset tenant selection
        tenantSelect.value = '';
        
        // Show all options first
        Array.from(tenantSelect.options).forEach(option => {
            option.style.display = 'block';
        });
        
        // Find all tenants associated with the selected unit
        const matchingTenants = Array.from(tenantSelect.options).filter(option => {
            if (option.value === '') return false; // Skip the placeholder
            
            // Get the unit ID for this tenant from the data attribute
            const optionUnitId = option.getAttribute('data-unit-id');
            return optionUnitId == unitId; // Use == to handle string/number comparison
        });
        
        // Hide non-matching tenants
        Array.from(tenantSelect.options).forEach(option => {
            if (option.value !== '' && option.getAttribute('data-unit-id') != unitId) {
                option.style.display = 'none';
            }
        });
        
        // Auto-select the tenant if there's only one matching
        if (matchingTenants.length === 1) {
            tenantSelect.value = matchingTenants[0].value;
        }
    } else {
        // Show all tenants when no unit is selected
        Array.from(tenantSelect.options).forEach(option => {
            option.style.display = 'block';
        });
    }
});

// AJAX Search functionality for visitor logs
document.getElementById('searchInput').addEventListener('keyup', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#visitorLogsTableBody tr');
    
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        
        if (searchTerm === '') {
            // If search is empty, show all rows
            row.style.display = '';
        } else {
            // Check if search term is in the data-search attribute
            if (searchData.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
});

// Prevent form submission from interfering with search
const checkoutForms = document.querySelectorAll('.visitor-checkout-form');
checkoutForms.forEach(form => {
    form.addEventListener('submit', function(e) {
        // Add loading indicator
        const button = this.querySelector('button');
        button.innerHTML = 'Checking out...';
        button.disabled = true;
    });
});


// QR Code Scanning functionality
let html5QrcodeScanner = null;

// Function to initialize QR scanner
function initQRScanner() {
    const qrReaderContainer = document.getElementById('qr-reader');
    if (!qrReaderContainer) {
        console.error('QR reader container not found');
        return;
    }
    
    // Clear any previous scanner
    if (html5QrcodeScanner) {
        html5QrcodeScanner.clear().catch(error => {
            console.error('Failed to clear previous scanner: ', error);
        });
    }
    
    // Initialize new scanner
    html5QrcodeScanner = new Html5Qrcode('qr-reader');
    
    const qrCodeSuccessCallback = (decodedText, decodedResult) => {
        // Handle successful QR code scan
        console.log('QR Code scanned:', decodedText);
        document.getElementById('qr-reader-results').innerHTML = '<div class="alert alert-success mt-3">QR Code scanned successfully: ' + decodedText + '</div>';
        
        // Try to parse the QR code data (assuming it's JSON with visitor info)
        try {
            const visitorData = JSON.parse(decodedText);
            populateVisitorForm(visitorData);
        } catch (e) {
            // If not JSON, treat as a simple identifier
            // You could implement additional logic here to look up visitor data
            console.log('QR code is not JSON, treating as identifier:', decodedText);
        }
        
        // Optionally stop scanning after successful read
        setTimeout(() => {
            html5QrcodeScanner.stop().catch(error => {
                console.error('Failed to stop scanner: ', error);
            });
        }, 1000);
    };
    
    const config = {
        fps: 10,
        qrbox: { width: 250, height: 250 }
    };
    
    // Start the camera
    html5QrcodeScanner.start({ facingMode: "environment" }, config, qrCodeSuccessCallback)
        .catch(err => {
            console.error('Camera start error: ', err);
            document.getElementById('qr-reader-results').innerHTML = '<div class="alert alert-danger mt-3">Error accessing camera: ' + err + '</div>';
        });
}

// Function to show QR code for a visitor
function showQRCode(qrCode) {
    // Create a modal to display the QR code
    const modalHtml = `
        <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-sm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Visitor QR Code</h3>
                        <div class="btn btn-sm btn-icon btn-active-color-primary" data-bs-dismiss="modal">
                            <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                    </div>
                    <div class="modal-body text-center">
                        <div id="qr-code-container" class="p-3"></div>
                        <p class="mt-2">QR Code: ${qrCode}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove any existing QR code modal
    const existingModal = document.getElementById('qrCodeModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add the modal to the page
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Generate the QR code inside the modal
    const qrContainer = document.getElementById('qr-code-container');
    
    // Create a simple visual representation of the QR code
    // In a real implementation, you would use a proper QR code generation library
    qrContainer.innerHTML = `
        <div class="border p-2" style="display:inline-block;background-color:#000;padding:10px;">
            <div style="background-color:#fff;width:150px;height:150px;display:flex;align-items:center;justify-content:center;font-size:12px;text-align:center;color:#000">
                [QR CODE]<br>${qrCode.substring(0, 10)}...
            </div>
        </div>
    `;
    
    // Show the modal
    const qrModal = new bootstrap.Modal(document.getElementById('qrCodeModal'));
    qrModal.show();
    
    // Clean up the modal when it's hidden
    document.getElementById('qrCodeModal').addEventListener('hidden.bs.modal', function () {
        this.remove();
    });
}

// Function to populate visitor form with QR code data
function populateVisitorForm(visitorData) {
    // Close the scanner modal
    bootstrap.Modal.getInstance(document.getElementById('qrScannerModal')).hide();
    
    // Open the registration modal
    bootstrap.Modal.getOrCreateInstance(document.getElementById('registerVisitorModal')).show();
    
    // Populate the form fields with data from QR code
    if (visitorData.visitorName) {
        document.querySelector('input[name="visitor_name"]').value = visitorData.visitorName;
    }
    if (visitorData.visitorPhone) {
        document.querySelector('input[name="visitor_phone"]').value = visitorData.visitorPhone;
    }
    if (visitorData.purpose) {
        document.querySelector('select[name="purpose"]').value = visitorData.purpose;
        // Trigger change event to apply any logic
        const event = new Event('change', { bubbles: true });
        document.querySelector('select[name="purpose"]').dispatchEvent(event);
    }
    if (visitorData.vehicleRegistration) {
        document.querySelector('input[name="vehicle_registration"]').value = visitorData.vehicleRegistration;
    }
    if (visitorData.driverLicense) {
        document.querySelector('input[name="driver_license"]').value = visitorData.driverLicense;
    }
    if (visitorData.hostName) {
        document.querySelector('input[name="host_name"]').value = visitorData.hostName;
    }
    if (visitorData.hostPhone) {
        document.querySelector('input[name="host_phone"]').value = visitorData.hostPhone;
    }
    if (visitorData.specialInstructions) {
        document.querySelector('textarea[name="special_instructions"]').value = visitorData.specialInstructions;
    }
    
    // Show success message
    document.getElementById('qr-reader-results').innerHTML = '<div class="alert alert-success mt-3">Visitor data populated from QR code!</div>';
}

// Initialize QR scanner when modal is shown
const qrScannerModalEl = document.getElementById('qrScannerModal');
qrScannerModalEl.addEventListener('shown.bs.modal', function () {
    // Wait a bit for the modal to be fully rendered
    setTimeout(initQRScanner, 500);
});

// Stop QR scanner when modal is hidden
qrScannerModalEl.addEventListener('hidden.bs.modal', function () {
    if (html5QrcodeScanner) {
        html5QrcodeScanner.stop().catch(error => {
            console.error('Failed to stop scanner on modal close: ', error);
        });
    }
});

</script>
<script src="../../assets/plugins/custom/html5-qrcode/html5-qrcode.min.js"></script>

<?php require __DIR__ . '/partials/bottom.php'; ?>
