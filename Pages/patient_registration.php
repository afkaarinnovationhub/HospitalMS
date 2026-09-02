<?php
/**
 * MedCore Systems - Real Patients Directory & Registration
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../CONTROLS/PatientController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_DOCTOR, ROLE_PHARMACY, ROLE_RECEPTION_CASHIER]);

// Auto-seed default patients once (never resurrects if user deletes them)
try {
    PatientOperation::seedDefaultPatientsIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS PATIENT SEED ERROR] ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register_patient') {
        $result = PatientController::handleRegister($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_patient') {
        $result = PatientController::handleEditPatient($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_patient') {
        $result = PatientController::handleDeletePatient($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'bulk_delete_patients') {
        $result = PatientController::handleBulkDeletePatients($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search & Filter (Role-Scoped)
$searchQuery    = sanitizeString($_GET['search'] ?? '');
$genderFilter   = sanitizeString($_GET['gender'] ?? '');
$currentUser    = getCurrentUser();
$isDoctorRole   = (($currentUser['role'] ?? '') === ROLE_DOCTOR);
$scopedDoctorId = $isDoctorRole ? (int)$currentUser['id'] : null;

$kpis     = PatientOperation::getPatientSummaryKPIs($scopedDoctorId);
$patients = PatientOperation::searchPatients($searchQuery, 100, $scopedDoctorId);
$doctors  = PatientOperation::getDoctorsList();

// Filter by gender if selected
if (!empty($genderFilter)) {
    $patients = array_filter($patients, function ($p) use ($genderFilter) {
        return strtolower($p['gender'] ?? '') === strtolower($genderFilter);
    });
}

$nextMRN = PatientOperation::generateUniqueMRN();

$pageTitle = 'Patients Directory & Registration - MedCore Systems';
$headerTitle = 'MedCore Management - Patients';
$activePage = 'patients';

include __DIR__ . '/../components/header.php';
?>

<!-- Patients Directory Main View -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Patients Directory</h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                    <?php if ($isDoctorRole): ?>
                        Viewing clinical patient files and medical encounters assigned to your care.
                    <?php else: ?>
                        Register new patients, record clinical triage vitals, and manage hospital medical files.
                    <?php endif; ?>
                </p>
            </div>
            <!-- Quick Link to Reception Intake -->
            <?php if (!$isDoctorRole): ?>
                <a href="reception.php" class="w-full sm:w-auto flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">desk</span>
                    New Intake at Reception
                </a>
            <?php endif; ?>
        </div>

        <!-- Alert Notifications -->
        <?php if (!empty($errorMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
                <div>
                    <p class="font-bold">Registration Alert</p>
                    <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
                <div>
                    <p class="font-bold">Operation Successful</p>
                    <p class="mt-0.5"><?php echo e($successMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Metric Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 sm:gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Total Patients</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1"><?php echo number_format($kpis['total_patients']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">groups</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Registered Today</p>
                    <p class="font-display-lg text-2xl font-bold text-secondary mt-1"><?php echo number_format($kpis['today_registered']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">how_to_reg</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Waiting in Queue</p>
                    <p class="font-display-lg text-2xl font-bold text-primary mt-1"><?php echo number_format($kpis['waiting_in_queue']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-fixed text-on-primary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">queue</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">In Consultation</p>
                    <p class="font-display-lg text-2xl font-bold text-tertiary mt-1"><?php echo number_format($kpis['in_consultation']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">stethoscope</span>
                </div>
            </div>
        </div>

        <!-- Patients Directory Table Card -->
        <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
            <!-- Table Toolbar / Filters -->
            <form method="GET" action="patient_registration.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto flex-1 max-w-xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input name="search" value="<?php echo e($searchQuery); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none" placeholder="Search by name, MRN, or phone..." type="text">
                    </div>
                    <select name="gender" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg px-3 py-1.5 text-xs text-on-surface outline-none">
                        <option value="">All Genders</option>
                        <option value="male" <?php echo $genderFilter === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo $genderFilter === 'female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div class="flex items-center gap-sm">
                    <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                        Filter
                    </button>
                    <a href="patient_registration.php" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant transition-colors flex items-center justify-center cursor-pointer" title="Reset Filters">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                </div>
            </form>

            <!-- Bulk Actions Form & Bar -->
            <form id="bulk-patients-form" method="POST" action="patient_registration.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="bulk_delete_patients">

                <!-- Dynamic Bulk Action Bar -->
                <div id="bulk-action-bar" class="hidden p-3 bg-error-container/40 border-b border-error/30 flex items-center justify-between px-4 transition-all">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-error text-[20px]">checklist</span>
                        <span id="bulk-selected-count" class="font-bold text-xs text-on-error-container">0 patients selected</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="clearPatientSelection()" class="px-3 py-1 rounded-lg border border-outline-variant text-xs font-semibold bg-surface hover:bg-surface-container cursor-pointer">
                            Cancel Selection
                        </button>
                        <button type="submit" onclick="return confirm('Are you sure you want to permanently delete all selected patients?');" class="px-3.5 py-1 bg-error hover:bg-error/90 text-on-error rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer shadow-xs">
                            <span class="material-symbols-outlined text-[16px]">delete_sweep</span>
                            Delete Selected
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse min-w-[750px]">
                        <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant sticky top-0">
                            <tr>
                                <th class="py-3 px-4 w-10 text-center">
                                    <input type="checkbox" id="select-all-patients" onchange="toggleSelectAllPatients(this)" class="w-4 h-4 rounded text-primary border-outline-variant cursor-pointer" title="Select All">
                                </th>
                                <th class="py-3 px-4 font-semibold">Patient Name &amp; MRN</th>
                                <th class="py-3 px-4 font-semibold">Age / Gender</th>
                                <th class="py-3 px-4 font-semibold">Contact Phone</th>
                                <th class="py-3 px-4 font-semibold">Blood Group</th>
                                <th class="py-3 px-4 font-semibold">Allergies</th>
                                <th class="py-3 px-4 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="font-body-sm text-xs sm:text-body-sm text-on-surface divide-y divide-outline-variant">
                            <?php if (empty($patients)): ?>
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                        <span class="material-symbols-outlined text-3xl mb-1 text-outline">person_search</span>
                                        <p class="font-semibold">No patient records found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($patients as $p): ?>
                                    <?php
                                        $initials = strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1));
                                        $hasAllergy = (!empty($p['allergies']) && strtolower($p['allergies']) !== 'none known');
                                    ?>
                                    <tr class="hover:bg-surface-container-low transition-colors group">
                                        <td class="py-3 px-4 text-center">
                                            <input type="checkbox" name="patient_ids[]" value="<?php echo (int)$p['id']; ?>" onchange="updateBulkBar()" class="patient-checkbox w-4 h-4 rounded text-primary border-outline-variant cursor-pointer">
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="flex items-center gap-sm">
                                                <div class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0">
                                                    <?php echo e($initials); ?>
                                                </div>
                                                <div>
                                                    <a href="patient_profile_michael_chen.php?id=<?php echo (int)$p['id']; ?>" class="font-bold text-on-surface hover:text-primary hover:underline flex items-center gap-1">
                                                        <?php echo e($p['full_name']); ?>
                                                        <span class="material-symbols-outlined text-[14px] text-primary">open_in_new</span>
                                                    </a>
                                                    <span class="font-code-md text-[11px] text-on-surface-variant"><?php echo e($p['mrn']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="font-medium"><?php echo (int)$p['age']; ?> yrs</span>
                                            <span class="text-on-surface-variant capitalize text-[11px]"> • <?php echo e($p['gender']); ?></span>
                                        </td>
                                        <td class="py-3 px-4 font-mono text-xs"><?php echo e($p['phone']); ?></td>
                                        <td class="py-3 px-4">
                                            <span class="bg-surface-container px-2 py-0.5 rounded font-bold text-xs"><?php echo e($p['blood_group'] ?: 'N/A'); ?></span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <?php if ($hasAllergy): ?>
                                                <span class="inline-flex items-center gap-1 text-error font-bold text-xs bg-error-container/50 px-2 py-0.5 rounded-full">
                                                    <span class="material-symbols-outlined text-[14px]">warning</span>
                                                    <?php echo e($p['allergies']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-on-surface-variant text-xs">None known</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="patient_profile_michael_chen.php?id=<?php echo (int)$p['id']; ?>" class="px-2 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high rounded text-xs font-semibold text-on-surface flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[15px]">medical_services</span>
                                                    Chart
                                                </a>
                                                <button type="button" 
                                                        onclick="openEditPatientModal(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                        class="p-1 text-on-surface-variant hover:text-primary hover:bg-surface-container rounded transition-colors cursor-pointer" 
                                                        title="Edit Patient Details">
                                                    <span class="material-symbols-outlined text-[17px]">edit</span>
                                                </button>
                                                <button type="button" 
                                                        onclick="confirmSingleDelete(<?php echo (int)$p['id']; ?>, '<?php echo e(addslashes($p['full_name'])); ?>')" 
                                                        class="p-1 text-on-surface-variant hover:text-error hover:bg-error-container/30 rounded transition-colors cursor-pointer" 
                                                        title="Delete Patient Record">
                                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Hidden Single Delete Form -->
<form id="single-delete-form" method="POST" action="patient_registration.php" class="hidden">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_patient">
    <input type="hidden" id="single_delete_patient_id" name="patient_id" value="">
</form>

<!-- EDIT PATIENT MODAL -->
<div id="edit-patient-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-xl w-full max-h-[90vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Patient Details</h3>
            </div>
            <button type="button" onclick="closeEditPatientModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="patient_registration.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_patient">
            <input type="hidden" id="edit_pat_id" name="patient_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">First Name *</label>
                    <input id="edit_pat_first_name" name="first_name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Last Name *</label>
                    <input id="edit_pat_last_name" name="last_name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Gender *</label>
                    <select id="edit_pat_gender" name="gender" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Date of Birth</label>
                    <input id="edit_pat_dob" name="dob" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface" type="date">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Blood Group</label>
                    <select id="edit_pat_blood_group" name="blood_group" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                        <option value="">Unknown</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number *</label>
                    <input id="edit_pat_phone" name="phone" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email</label>
                    <input id="edit_pat_email" name="email" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface" type="email">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5 text-error">Allergies</label>
                <input id="edit_pat_allergies" name="allergies" class="w-full bg-surface-container-low border border-error/40 rounded p-2 text-xs text-on-surface" type="text">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Medical History</label>
                <input id="edit_pat_history" name="medical_history" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface" type="text">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Emergency Contact Name</label>
                    <input id="edit_pat_emergency_name" name="emergency_contact_name" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface" type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Emergency Phone</label>
                    <input id="edit_pat_emergency_phone" name="emergency_contact_phone" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface" type="text">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeEditPatientModal()" class="px-4 py-2 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditPatientModal(p) {
        document.getElementById('edit_pat_id').value = p.id;
        document.getElementById('edit_pat_first_name').value = p.first_name || '';
        document.getElementById('edit_pat_last_name').value = p.last_name || '';
        document.getElementById('edit_pat_gender').value = p.gender || 'male';
        document.getElementById('edit_pat_dob').value = p.dob || '';
        document.getElementById('edit_pat_blood_group').value = p.blood_group || '';
        document.getElementById('edit_pat_phone').value = p.phone || '';
        document.getElementById('edit_pat_email').value = p.email || '';
        document.getElementById('edit_pat_allergies').value = p.allergies || '';
        document.getElementById('edit_pat_history').value = p.medical_history || '';
        document.getElementById('edit_pat_emergency_name').value = p.emergency_contact_name || '';
        document.getElementById('edit_pat_emergency_phone').value = p.emergency_contact_phone || '';
        document.getElementById('edit-patient-modal').classList.remove('hidden');
    }
    function closeEditPatientModal() {
        document.getElementById('edit-patient-modal').classList.add('hidden');
    }
    function confirmSingleDelete(patId, patName) {
        if (confirm(`Are you sure you want to permanently delete patient "${patName}"?`)) {
            document.getElementById('single_delete_patient_id').value = patId;
            document.getElementById('single-delete-form').submit();
        }
    }
    function toggleSelectAllPatients(master) {
        const checkboxes = document.querySelectorAll('.patient-checkbox');
        checkboxes.forEach(cb => cb.checked = master.checked);
        updateBulkBar();
    }
    function updateBulkBar() {
        const checked = document.querySelectorAll('.patient-checkbox:checked');
        const bar = document.getElementById('bulk-action-bar');
        const countEl = document.getElementById('bulk-selected-count');
        const master = document.getElementById('select-all-patients');
        const allCbs = document.querySelectorAll('.patient-checkbox');

        if (checked.length > 0) {
            bar.classList.remove('hidden');
            countEl.textContent = `${checked.length} ${checked.length === 1 ? 'patient' : 'patients'} selected`;
        } else {
            bar.classList.add('hidden');
        }
        if (master) {
            master.checked = allCbs.length > 0 && checked.length === allCbs.length;
        }
    }
    function clearPatientSelection() {
        document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);
        const master = document.getElementById('select-all-patients');
        if (master) master.checked = false;
        updateBulkBar();
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
