<?php
/**
 * MedCore Systems - Dynamic Patient Clinical Chart & Medical Profile
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

$currentUser = getCurrentUser();
$isDoctor = (($currentUser['role'] ?? '') === ROLE_DOCTOR);

// Auto-seed default patients if table is fresh
try {
    PatientOperation::seedDefaultPatientsIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS PATIENT PROFILE SEED ERROR] ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions (Vitals / Queue Routing)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_vitals') {
        $result = PatientController::handleRecordVitals($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'queue_patient') {
        $result = PatientController::handleQueuePatient($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Determine Patient to Display
$patientId = (int)($_GET['id'] ?? 0);
$patientMRN = sanitizeString($_GET['mrn'] ?? '');

$patient = null;
if ($patientId > 0) {
    $patient = PatientOperation::getPatientById($patientId);
} elseif (!empty($patientMRN)) {
    $patient = PatientOperation::getPatientByMRN($patientMRN);
}

// Fallback to Michael Chen / First patient in directory
if (!$patient) {
    $all = PatientOperation::searchPatients('', 1);
    if (!empty($all)) {
        $patient = $all[0];
        $patientId = (int)$patient['id'];
    }
}

if (!$patient) {
    safeRedirect('patient_registration.php');
    exit;
}

$patientId = (int)$patient['id'];
$latestVitals = PatientOperation::getLatestVitals($patientId);
$doctors = PatientOperation::getDoctorsList();

// Fetch Prescriptions for this Patient
$pdo = getDBConnection();
$stmtRx = $pdo->prepare("
    SELECT p.*, COUNT(pi.id) as item_count, GROUP_CONCAT(m.name SEPARATOR ', ') as med_names
    FROM prescriptions p
    LEFT JOIN prescription_items pi ON p.id = pi.prescription_id
    LEFT JOIN medications m ON pi.medication_id = m.id
    WHERE p.patient_mrn = :mrn OR p.patient_name = :name
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmtRx->execute([
    ':mrn'  => $patient['mrn'],
    ':name' => $patient['full_name'],
]);
$patientPrescriptions = $stmtRx->fetchAll();

$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$hasAllergy = (!empty($patient['allergies']) && strtolower($patient['allergies']) !== 'none known');

$pageTitle = "Patient Chart - {$patient['full_name']} - MedCore Systems";
$headerTitle = 'MedCore Management - Patient Chart';
$activePage = 'patients';

include __DIR__ . '/../components/header.php';
?>

<!-- Page Content Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background flex flex-col gap-md sm:gap-lg custom-scrollbar">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-xs font-body-sm text-xs sm:text-body-sm text-on-surface-variant">
        <a class="hover:text-primary transition-colors" href="patient_registration.php">Patients</a>
        <span class="material-symbols-outlined text-[16px]">chevron_right</span>
        <span class="text-on-surface font-medium"><?php echo e($patient['full_name']); ?></span>
    </nav>

    <!-- Alert Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <p><?php echo e($errorMessage); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <p><?php echo e($successMessage); ?></p>
        </div>
    <?php endif; ?>

    <!-- Patient Header Card -->
    <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg flex flex-col md:flex-row gap-md sm:gap-lg justify-between items-start shadow-sm">
        <!-- Patient Identity & Demographics -->
        <div class="flex flex-col sm:flex-row items-start gap-md sm:gap-lg w-full md:w-auto">
            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-display-lg text-xl sm:text-display-lg border-2 border-surface shadow-sm shrink-0 font-bold">
                <?php echo e($initials); ?>
            </div>
            <div class="flex flex-col gap-xs w-full">
                <div class="flex flex-wrap items-center gap-2 sm:gap-md">
                    <h1 class="font-headline-lg text-lg sm:text-headline-lg text-on-surface m-0 font-bold"><?php echo e($patient['full_name']); ?></h1>
                    <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-xs px-2.5 py-0.5 rounded-full font-bold">
                        Active Clinical File
                    </span>
                    <?php if ($hasAllergy): ?>
                        <span class="bg-error-container text-on-error-container font-label-md text-xs px-2.5 py-0.5 rounded-full font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">warning</span>
                            Allergy: <?php echo e($patient['allergies']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-sm gap-x-md sm:gap-x-xl mt-sm sm:mt-md">
                    <div>
                        <p class="font-label-md text-[11px] sm:text-xs text-on-surface-variant font-semibold">MRN Number</p>
                        <p class="font-code-md text-xs sm:text-code-md text-primary mt-0.5 font-bold"><?php echo e($patient['mrn']); ?></p>
                    </div>
                    <div>
                        <p class="font-label-md text-[11px] sm:text-xs text-on-surface-variant font-semibold">Age / Gender</p>
                        <p class="font-body-md text-xs sm:text-body-md text-on-surface mt-0.5 font-medium"><?php echo (int)$patient['age']; ?> yrs • <span class="capitalize"><?php echo e($patient['gender']); ?></span></p>
                    </div>
                    <div>
                        <p class="font-label-md text-[11px] sm:text-xs text-on-surface-variant font-semibold">Contact Phone</p>
                        <p class="font-body-md text-xs sm:text-body-md text-on-surface mt-0.5 font-medium font-mono"><?php echo e($patient['phone']); ?></p>
                    </div>
                    <div>
                        <p class="font-label-md text-[11px] sm:text-xs text-on-surface-variant font-semibold">Blood Group</p>
                        <p class="font-body-md text-xs sm:text-body-md text-error mt-0.5 font-bold"><?php echo e($patient['blood_group'] ?: 'Not recorded'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Primary Actions -->
        <div class="flex flex-wrap sm:flex-nowrap gap-sm w-full md:w-auto shrink-0 mt-md md:mt-0">
            <button type="button" onclick="openVitalsModal()" class="flex-1 md:flex-none flex items-center justify-center gap-xs px-md py-2 border border-outline-variant rounded-lg font-label-md text-xs sm:text-label-md text-on-surface bg-surface hover:bg-surface-container-low transition-colors font-medium cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">vital_signs</span>
                Record Vitals
            </button>
            <?php if (!$isDoctor): ?>
            <button type="button" onclick="openQueueModal()" class="flex-1 md:flex-none flex items-center justify-center gap-xs px-md py-2 rounded-lg font-label-md text-xs sm:text-label-md text-on-primary bg-primary hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">queue</span>
                Route to Doctor
            </button>
            <?php endif; ?>
        </div>
    </section>

    <!-- Clinical Details Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-lg">
        <!-- Left: Vitals & Medical History (4 cols on desktop) -->
        <div class="lg:col-span-4 flex flex-col gap-md">
            <!-- Latest Vitals Card -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm space-y-3">
                <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
                    <h3 class="font-headline-sm text-sm text-on-surface font-bold flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">vital_signs</span>
                        Latest Triage Vitals
                    </h3>
                    <?php if ($latestVitals): ?>
                        <span class="text-[10px] text-on-surface-variant font-medium"><?php echo date('M d, g:i A', strtotime($latestVitals['recorded_at'])); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$latestVitals): ?>
                    <p class="text-xs text-on-surface-variant py-4 text-center">No vitals recorded yet.</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant">
                            <p class="text-[10px] text-on-surface-variant font-semibold">Blood Pressure</p>
                            <p class="font-bold text-sm text-on-surface mt-0.5"><?php echo e($latestVitals['systolic'] ?? '--'); ?> / <?php echo e($latestVitals['diastolic'] ?? '--'); ?> <span class="text-[10px] font-normal text-on-surface-variant">mmHg</span></p>
                        </div>
                        <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant">
                            <p class="text-[10px] text-on-surface-variant font-semibold">Heart Rate</p>
                            <p class="font-bold text-sm text-on-surface mt-0.5"><?php echo e($latestVitals['heart_rate'] ?? '--'); ?> <span class="text-[10px] font-normal text-on-surface-variant">bpm</span></p>
                        </div>
                        <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant">
                            <p class="text-[10px] text-on-surface-variant font-semibold">Temperature</p>
                            <p class="font-bold text-sm text-on-surface mt-0.5"><?php echo e($latestVitals['temperature'] ?? '--'); ?> <span class="text-[10px] font-normal text-on-surface-variant">°C</span></p>
                        </div>
                        <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant">
                            <p class="text-[10px] text-on-surface-variant font-semibold">Oxygen (SpO2)</p>
                            <p class="font-bold text-sm text-secondary mt-0.5"><?php echo e($latestVitals['spo2_oxygen'] ?? '--'); ?>%</p>
                        </div>
                    </div>

                    <?php if (!empty($latestVitals['chief_complaint'])): ?>
                        <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant">
                            <p class="text-[10px] text-on-surface-variant font-semibold">Chief Complaint / Triage Note</p>
                            <p class="text-xs text-on-surface mt-0.5 font-medium"><?php echo e($latestVitals['chief_complaint']); ?></p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Background & Emergency Contact -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm space-y-3">
                <h3 class="font-headline-sm text-sm text-on-surface font-bold pb-2 border-b border-outline-variant">Medical Background</h3>
                <div class="text-xs space-y-2">
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Documented Allergies:</p>
                        <p class="font-bold <?php echo $hasAllergy ? 'text-error' : 'text-on-surface'; ?> mt-0.5"><?php echo e($patient['allergies'] ?: 'None known'); ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Chronic Conditions &amp; History:</p>
                        <p class="text-on-surface mt-0.5"><?php echo e($patient['medical_history'] ?: 'No chronic history documented.'); ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Emergency Contact:</p>
                        <p class="text-on-surface mt-0.5 font-medium"><?php echo e($patient['emergency_contact_name'] ?: 'N/A'); ?> (<?php echo e($patient['emergency_contact_phone'] ?: 'No phone'); ?>)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Prescriptions & Encounters (8 cols on desktop) -->
        <div class="lg:col-span-8 flex flex-col gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex flex-col">
                <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-3">
                    <h3 class="font-headline-sm text-sm text-on-surface font-bold flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">prescriptions</span>
                        Prescription History (Farmashiyaha)
                    </h3>
                    <span class="text-xs text-on-surface-variant font-semibold"><?php echo count($patientPrescriptions); ?> Orders</span>
                </div>

                <?php if (empty($patientPrescriptions)): ?>
                    <p class="text-xs text-on-surface-variant py-6 text-center">No prescription history found for this patient.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($patientPrescriptions as $rx): ?>
                            <div class="p-3.5 rounded-xl bg-surface-container-lowest border border-outline-variant/80 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-code-md font-bold text-primary text-xs"><?php echo e($rx['rx_number']); ?></span>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full capitalize <?php echo $rx['status'] === 'dispensed' ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-primary-container text-on-primary-container'; ?>">
                                            <?php echo str_replace('_', ' ', $rx['status']); ?>
                                        </span>
                                    </div>
                                    <p class="font-bold text-xs text-on-surface mt-1"><?php echo e($rx['med_names'] ?: 'Prescription Items'); ?></p>
                                    <p class="text-[11px] text-on-surface-variant">Prescribed by <?php echo e($rx['doctor_name']); ?> • <?php echo date('M d, Y', strtotime($rx['created_at'])); ?></p>
                                </div>
                                <a href="pharmacy_dispensing_prescription.php?rx_id=<?php echo (int)$rx['id']; ?>" class="px-3 py-1.5 bg-surface border border-outline-variant hover:bg-surface-container rounded-lg text-xs font-semibold text-primary flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[15px]">medication</span>
                                    View in Pharmacy
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- MODAL 1: Record Vitals -->
<div id="vitals-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">vital_signs</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Record Triage Vitals</h3>
            </div>
            <button type="button" onclick="closeVitalsModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="patient_profile_michael_chen.php?id=<?php echo $patientId; ?>" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_vitals">
            <input type="hidden" name="patient_id" value="<?php echo $patientId; ?>">

            <div class="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">BP Systolic</label>
                    <input name="systolic" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="120" type="number">
                </div>
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">BP Diastolic</label>
                    <input name="diastolic" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="80" type="number">
                </div>
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">Pulse (BPM)</label>
                    <input name="heart_rate" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="72" type="number">
                </div>
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">Temp (°C)</label>
                    <input name="temperature" step="0.1" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="36.8" type="number">
                </div>
            </div>

            <div>
                <label class="block text-[11px] text-on-surface-variant mb-0.5">Chief Complaint / Symptoms</label>
                <input name="chief_complaint" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs text-on-surface" placeholder="e.g. Headache and fever" type="text">
            </div>

            <div>
                <label class="block text-[11px] text-on-surface-variant mb-0.5">Triage Level</label>
                <select name="triage_level" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs">
                    <option value="routine">Routine</option>
                    <option value="urgent">Urgent</option>
                    <option value="emergency">Emergency</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeVitalsModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-1.5 rounded bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Save Vitals</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$isDoctor): ?>
<!-- MODAL 2: Route Patient to Doctor Queue -->
<div id="queue-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">queue</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Route to Doctor Queue</h3>
            </div>
            <button type="button" onclick="closeQueueModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="patient_profile_michael_chen.php?id=<?php echo $patientId; ?>" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="queue_patient">
            <input type="hidden" name="patient_id" value="<?php echo $patientId; ?>">

            <!-- Doctor Assignment -->
            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Assign Doctor *</label>
                <select name="doctor_id" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
                    <option value="">-- Choose Doctor --</option>
                    <option disabled>──────────────────────────────────────────────────</option>
                    <?php foreach ($doctors as $i => $doc): ?>
                        <?php if ($i > 0): ?>
                            <option disabled>──────────────────────────────────────────────────</option>
                        <?php endif; ?>
                        <option value="<?php echo (int)$doc['id']; ?>">
                            <?php echo e($doc['full_name']); ?>  |  <?php echo e($doc['professional_title'] ?: 'General Practice'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Line Separator: Department & Priority -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Department</label>
                        <select name="department" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                            <option value="General OPD">General OPD</option>
                            <option value="Cardiology OPD">Cardiology OPD</option>
                            <option value="Neurology OPD">Neurology OPD</option>
                            <option value="Endocrinology OPD">Endocrinology OPD</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Priority</label>
                        <select name="priority" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs font-semibold">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="emergency">Emergency Priority</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeQueueModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Send to Queue</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    function openVitalsModal() {
        document.getElementById('vitals-modal').classList.remove('hidden');
    }
    function closeVitalsModal() {
        document.getElementById('vitals-modal').classList.add('hidden');
    }
    function openQueueModal() {
        document.getElementById('queue-modal').classList.remove('hidden');
    }
    function closeQueueModal() {
        document.getElementById('queue-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
