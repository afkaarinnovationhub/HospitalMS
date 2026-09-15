<?php
/**
 * MedCore Systems - Real Doctor Clinical Consultation Workspace
 * Supports Doctor-led patient baseline intake (Age, Blood group, Drug allergies, Medical history),
 * SOAP clinical documentation, vital examination, ICD-10 diagnosis, direct E-Prescribing, and Lab ordering.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/../OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/../CONTROLS/ConsultationController.php';
require_once __DIR__ . '/../CONTROLS/LaboratoryController.php';
require_once __DIR__ . '/../CONTROLS/PatientController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_DOCTOR]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_consultation') {
        $result = ConsultationController::handleSaveConsultation($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'call_patient') {
        $result = PatientController::handleCallPatient($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'update_status') {
        $result = PatientController::handleUpdateQueueStatus($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'order_lab_tests') {
        $result = LaboratoryController::handleOrderLabTests($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'add_lab_test') {
        $result = LaboratoryController::handleCreateLabTest($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Determine Patient & Queue
$currentUser = getCurrentUser();
$currentDoctorId = ($currentUser['role'] === 'doctor') ? (int)$currentUser['id'] : null;

$patientId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$queueId   = !empty($_GET['queue_id']) ? (int)$_GET['queue_id'] : null;

$patient = null;
if ($patientId > 0) {
    $patient = PatientOperation::getPatientById($patientId);
}

// If specific queue_id is passed, verify doctor assignment security and billing clearance
if ($queueId) {
    $pdo = getDBConnection();
    $stmtQBill = $pdo->prepare("
        SELECT pq.*, inv.payment_status as invoice_status 
        FROM patient_queues pq 
        LEFT JOIN invoices inv ON inv.queue_id = pq.id AND inv.bill_type = 'consultation'
        WHERE pq.id = ?
    ");
    $stmtQBill->execute([$queueId]);
    $queueRow = $stmtQBill->fetch();
    if ($queueRow) {
        if ($currentDoctorId && $queueRow['doctor_id'] !== null && (int)$queueRow['doctor_id'] !== $currentDoctorId) {
            setFlashMessage('error', 'Access denied. This patient encounter is assigned to another doctor.');
            safeRedirect('doctor_dashboard.php');
        }

        $isBillingPaid = ($queueRow['billing_status'] === 'paid' || ($queueRow['invoice_status'] ?? '') === 'paid' || ($queueRow['invoice_status'] ?? '') === 'partial' || $queueRow['billing_status'] === 'exempt' || (float)($queueRow['current_doctor_fee'] ?? 0) <= 0.0);
        
        if (!$isBillingPaid) {
            setFlashMessage('error', 'Bukaankan lacagtiisa consultation-ka weli lama bixin. Fadlan bukaanka u dir Cashier-ka ka hor inta aan la bilaabin consultation-ka.');
            safeRedirect('doctor_dashboard.php');
        }
    }
}

// If accessing patient consultation directly without queue_id, enforce doctor follow-up ownership
if ($patientId > 0 && !$queueId && $currentDoctorId && (($currentUser['role'] ?? '') === ROLE_DOCTOR)) {
    $pdo = getDBConnection();
    $stmtFuCheck = $pdo->prepare("
        SELECT c.doctor_id, u.full_name as doctor_name 
        FROM consultations c
        JOIN users u ON c.doctor_id = u.id
        WHERE c.patient_id = :pat_id 
          AND c.follow_up_date = CURDATE()
          AND (c.follow_up_status IS NULL OR c.follow_up_status = 'pending')
        LIMIT 1
    ");
    $stmtFuCheck->execute([':pat_id' => $patientId]);
    $activeFu = $stmtFuCheck->fetch();
    if ($activeFu && (int)$activeFu['doctor_id'] !== $currentDoctorId) {
        setFlashMessage('error', 'Access denied. Bukaankan wuxuu maanta ballan dib-u-eegis ah (Follow-up) la leeyahay Dr. ' . $activeFu['doctor_name'] . '. Maadama aadan ahayn dhaqtarka ballanta leh, ma samayn kartid consultation.');
        safeRedirect('doctor_dashboard.php');
    }
}

// If no active patient is selected, render clean "Consultation Station / Waiting Queue"
if (!$patient) {
    $pageTitle = 'Consultation Station - ' . HOSPITAL_NAME;
    $headerTitle = HOSPITAL_NAME . ' - Clinical Consultation Station';
    $activePage = 'consultations';

    $waitingQueue = PatientOperation::getQueue([
        'status'    => 'active',
        'doctor_id' => $currentDoctorId,
    ]);

    include __DIR__ . '/../components/header.php';
    ?>
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
        <?php if (!empty($errorMessage)): ?>
            <div class="max-w-5xl mx-auto mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
                <div>
                    <p class="font-bold">Consultation Notice</p>
                    <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="max-w-5xl mx-auto mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
                <div class="flex-1">
                    <p class="font-bold">Clinical Notice</p>
                    <p class="mt-0.5"><?php echo e($successMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Station Header -->
        <div class="max-w-5xl mx-auto mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-surface border border-outline-variant p-4 sm:p-5 rounded-2xl shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[28px]">stethoscope</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="font-headline-sm text-lg sm:text-xl font-bold text-on-surface">Consultation Room Station</h2>
                    </div>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        Attending: <strong>Dr. <?php echo e($currentUser['full_name']); ?></strong> (<?php echo e($currentUser['professional_title'] ?? 'General Practitioner'); ?>)
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                <?php
                    $firstCallable = null;
                    foreach ($waitingQueue as $cand) {
                        if (in_array($cand['status'], ['waiting', 'on_hold', 'lab_completed'], true)) {
                            $firstCallable = $cand;
                            break;
                        }
                    }
                ?>
                <?php if ($firstCallable): ?>
                    <form method="POST" action="consultation_michael_chen.php" class="w-full sm:w-auto">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="call_patient">
                        <input type="hidden" name="queue_id" value="<?php echo (int)$firstCallable['id']; ?>">
                        <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold text-xs rounded-xl shadow-sm flex items-center justify-center gap-1.5 transition-all cursor-pointer">
                            <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                            Call Next Patient (<?php echo e($firstCallable['token_number']); ?> - <?php echo e($firstCallable['patient_name']); ?>)
                        </button>
                    </form>
                <?php endif; ?>
                <a href="doctor_dashboard.php" class="px-3 py-2 border border-outline-variant hover:bg-surface-container rounded-xl text-xs font-semibold text-on-surface flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">dashboard</span>
                    Dashboard
                </a>
            </div>
        </div>

        <!-- Waiting Queue Container -->
        <div class="max-w-5xl mx-auto">
            <div class="bg-surface border border-outline-variant rounded-2xl overflow-hidden shadow-sm">
                <div class="p-4 sm:p-5 border-b border-outline-variant flex justify-between items-center bg-surface-container-low/50">
                    <div>
                        <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[20px]">queue</span>
                            Active Consultation Queue
                        </h3>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-primary/10 text-primary">
                        <?php echo count($waitingQueue); ?> Patients
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-outline-variant bg-surface-container-low/30 text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">
                                <th class="py-3 px-4">Token / Priority</th>
                                <th class="py-3 px-4">Patient Information</th>
                                <th class="py-3 px-4">Age / Gender</th>
                                <th class="py-3 px-4">Clinical Status &amp; Vitals</th>
                                <th class="py-3 px-4 text-right">Consultation Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant text-xs text-on-surface">
                            <?php if (empty($waitingQueue)): ?>
                                <tr>
                                    <td colspan="5" class="py-12 text-center text-on-surface-variant">
                                        <div class="w-16 h-16 rounded-full bg-surface-container flex items-center justify-center mx-auto mb-3 text-on-surface-variant">
                                            <span class="material-symbols-outlined text-[32px]">check_circle</span>
                                        </div>
                                        <p class="font-bold text-sm text-on-surface">No patients currently waiting in your consultation queue.</p>
                                        <p class="text-xs text-on-surface-variant mt-1 max-w-sm mx-auto">
                                            When reception check-in assigns patients to you, they will appear here live.
                                        </p>
                                        <div class="mt-4 flex justify-center gap-2">
                                            <a href="doctor_dashboard.php" class="px-3 py-1.5 bg-primary text-on-primary rounded-lg font-semibold text-xs">Doctor Dashboard</a>
                                            <a href="queue_management.php" class="px-3 py-1.5 border border-outline-variant text-on-surface rounded-lg font-semibold text-xs">Live Queue Board</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($waitingQueue as $q): ?>
                                    <?php
                                        $isUrgent = in_array($q['priority'], ['urgent', 'emergency'], true);
                                        $hasVitals = !empty($q['systolic']);
                                        $isInConsult = ($q['status'] === 'in_consultation');
                                        $isOnHold = ($q['status'] === 'on_hold');
                                        $isLabReady = ($q['status'] === 'lab_completed');
                                        $isInLab = ($q['status'] === 'in_lab');
                                    ?>
                                    <tr class="hover:bg-surface-container-low transition-colors <?php echo $isInConsult ? 'bg-emerald-500/5' : ($isOnHold ? 'bg-amber-500/5' : ''); ?>">
                                        <td class="py-3 px-4">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono font-bold text-xs px-2 py-0.5 rounded <?php echo $isInConsult ? 'bg-emerald-500/20 text-emerald-800 dark:text-emerald-300' : 'bg-primary-fixed/40 text-primary'; ?>">
                                                    <?php echo e($q['token_number']); ?>
                                                </span>
                                                <?php if ($isUrgent): ?>
                                                    <span class="text-[10px] uppercase font-bold text-error bg-error-container/60 px-2 py-0.5 rounded-full">
                                                        <?php echo e($q['priority']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo date('g:i A', strtotime($q['queued_at'])); ?></p>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="font-bold text-on-surface"><?php echo e($q['patient_name']); ?></div>
                                            <div class="font-mono text-[11px] text-on-surface-variant"><?php echo e($q['mrn']); ?></div>
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="font-semibold"><?php echo (int)$q['age']; ?> yrs</span>
                                            <span class="text-on-surface-variant capitalize text-[11px]"> • <?php echo e($q['gender']); ?></span>
                                        </td>
                                        <td class="py-3 px-4">
                                            <div class="flex flex-wrap items-center gap-1.5 mb-1">
                                                <?php if ($isInConsult): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border border-emerald-500/30">
                                                        <span class="material-symbols-outlined text-[13px]">stethoscope</span> In Room
                                                    </span>
                                                <?php elseif ($isOnHold): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-800 dark:text-amber-300 border border-amber-500/30" title="Patient was called but was temporarily absent">
                                                        <span class="material-symbols-outlined text-[13px]">pause_circle</span> On Hold / Skipped
                                                    </span>
                                                <?php elseif ($isLabReady): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/15 text-purple-800 dark:text-purple-300 border border-purple-500/30">
                                                        <span class="material-symbols-outlined text-[13px]">verified</span> Lab Results Ready
                                                    </span>
                                                <?php elseif ($isInLab): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/15 text-cyan-800 dark:text-cyan-300 border border-cyan-500/30">
                                                        <span class="material-symbols-outlined text-[13px]">biotech</span> In Lab
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-500/15 text-blue-800 dark:text-blue-300 border border-blue-500/30">
                                                        Waiting in Lobby
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($hasVitals): ?>
                                                <span class="text-[11px] text-on-surface-variant font-medium">
                                                    BP <?php echo (int)$q['systolic']; ?>/<?php echo (int)$q['diastolic']; ?> • <?php echo (float)$q['temperature']; ?>°C
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] text-on-surface-variant"><?php echo e($q['chief_complaint'] ?: 'Routine OPD visit'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <?php if ($isInConsult): ?>
                                                    <a href="consultation_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>&queue_id=<?php echo (int)$q['id']; ?>" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-lg shadow-xs flex items-center gap-1 transition-colors cursor-pointer">
                                                        <span class="material-symbols-outlined text-[16px]">stethoscope</span>
                                                        Open Chart
                                                    </a>
                                                    <button type="button" 
                                                            onclick="openHoldPatientModal(<?php echo (int)$q['id']; ?>, '<?php echo e(addslashes($q['patient_name'])); ?>', 'consultation_michael_chen.php')" 
                                                            class="px-2 py-1.5 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 text-amber-800 dark:text-amber-300 font-bold text-xs rounded-lg transition-colors cursor-pointer" 
                                                            title="Put On Hold if absent">
                                                        <span class="material-symbols-outlined text-[16px]">pause_circle</span>
                                                        Hold
                                                    </button>
                                                <?php elseif ($isOnHold): ?>
                                                    <form method="POST" action="consultation_michael_chen.php" class="inline">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                        <button type="submit" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-lg shadow-xs flex items-center gap-1 transition-colors cursor-pointer">
                                                            <span class="material-symbols-outlined text-[16px]">replay</span>
                                                            Recall Patient
                                                        </button>
                                                    </form>
                                                <?php elseif ($isLabReady): ?>
                                                    <form method="POST" action="consultation_michael_chen.php" class="inline">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                        <button type="submit" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-lg shadow-xs flex items-center gap-1 transition-colors cursor-pointer">
                                                            <span class="material-symbols-outlined text-[16px]">assignment_turned_in</span>
                                                            Review &amp; Prescribe
                                                        </button>
                                                    </form>
                                                <?php elseif ($isInLab): ?>
                                                    <a href="consultation_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>&queue_id=<?php echo (int)$q['id']; ?>" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold text-xs rounded-lg flex items-center gap-1 transition-colors">
                                                        <span class="material-symbols-outlined text-[16px]">visibility</span>
                                                        View Order
                                                    </a>
                                                <?php else: ?>
                                                    <form method="POST" action="consultation_michael_chen.php" class="inline">
                                                        <?php echo csrfField(); ?>
                                                        <input type="hidden" name="action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                        <button type="submit" class="px-3 py-1.5 bg-primary hover:bg-primary-container text-on-primary font-bold text-xs rounded-lg shadow-xs flex items-center gap-1 transition-colors cursor-pointer">
                                                            <span class="material-symbols-outlined text-[16px]">play_arrow</span>
                                                            Call In
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    <?php
    include __DIR__ . '/../components/footer.php';
    exit;
}

// When a patient is actively opened into the consultation room, mark them as 'in_consultation'
// This puts any other active consultation for this doctor on hold
if ($queueId) {
    PatientOperation::callPatientForDoctor($queueId, $currentDoctorId);
}

// Retrieve Medications Catalog for prescribing
$medications = InventoryOperation::getAllMedications();

// Retrieve Previous Consultations for Patient
$pastConsultations = ConsultationOperation::getConsultationsByPatient($patientId);

// Retrieve Diagnostic Lab Catalog & Completed Lab Results for Patient
$labCatalog = LaboratoryOperation::getLabTestCatalog();
$patientLabResults = LaboratoryOperation::getPatientLabResults($patientId);

// Retrieve Active Draft Consultation (if lab was ordered or draft was previously recorded)
$draftConsultation = ConsultationOperation::getActiveDraftConsultation($patientId, $queueId);

$subjectiveVal    = $draftConsultation['subjective_notes'] ?? '';
$objectiveVal     = $draftConsultation['objective_findings'] ?? '';
$assessmentVal    = $draftConsultation['assessment_diagnosis'] ?? '';
$secondaryVal     = $draftConsultation['secondary_diagnosis'] ?? '';
$treatmentPlanVal = $draftConsultation['treatment_plan'] ?? '';
$followUpDateVal  = !empty($draftConsultation['follow_up_date']) ? $draftConsultation['follow_up_date'] : '';

$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$hasAge = !empty($patient['dob']) && !empty($patient['age']);
$hasBlood = !empty($patient['blood_group']);
$hasAllergyInfo = !empty($patient['allergies']);
$isAllergic = $hasAllergyInfo && strtolower($patient['allergies']) !== 'none known' && strtolower($patient['allergies']) !== 'none';

$pageTitle = 'Consultation Workspace - ' . e($patient['full_name']) . ' - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Consultation';
$activePage = 'consultations';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Workspace -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Alert Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Consultation Notice</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Clinical Notice</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Patient Context Bar -->
    <div class="bg-surface border border-outline-variant rounded-xl p-3 sm:p-md mb-md sm:mb-lg flex flex-col md:flex-row justify-between items-start md:items-center gap-md shadow-sm">
        <div class="flex items-center gap-sm sm:gap-md">
            <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-headline-md font-bold text-base sm:text-headline-md shrink-0">
                <?php echo e($initials); ?>
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-surface"><?php echo e($patient['full_name']); ?></h2>
                    <span class="bg-secondary-container text-on-secondary-container font-label-md text-[11px] px-2.5 py-0.5 rounded-full font-bold">Active Encounter</span>
                    <?php if (!empty($patientLabResults)): ?>
                        <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-[11px] px-2.5 py-0.5 rounded-full font-bold flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">verified</span> Lab Results Ready
                        </span>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant mt-1">
                    <span>MRN: <strong class="text-on-surface font-mono"><?php echo e($patient['mrn']); ?></strong></span>
                    <span>•</span>
                    <span class="capitalize"><?php echo ucfirst(e($patient['gender'])); ?></span>
                    <span>•</span>
                    <?php if ($hasAge): ?>
                        <span><strong class="text-on-surface"><?php echo (int)$patient['age']; ?> yrs</strong></span>
                    <?php else: ?>
                        <span class="text-amber-600 bg-amber-500/10 px-2 py-0.5 rounded font-semibold text-[11px]">Age: Pending Doctor Input</span>
                    <?php endif; ?>
                    <span>•</span>
                    <?php if ($hasBlood): ?>
                        <span>Blood: <strong class="text-primary font-bold"><?php echo e($patient['blood_group']); ?></strong></span>
                    <?php else: ?>
                        <span class="text-amber-600 bg-amber-500/10 px-2 py-0.5 rounded font-semibold text-[11px]">Blood: Not Recorded</span>
                    <?php endif; ?>
                    <span>•</span>
                    <span>Phone: <?php echo e($patient['phone']); ?></span>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <?php if ($isAllergic): ?>
                <span class="inline-flex items-center gap-1 bg-error-container text-on-error-container font-label-md text-xs px-3 py-1 rounded-full font-bold border border-error/30 shadow-xs">
                    <span class="material-symbols-outlined text-[16px] text-error">warning</span> Allergy: <?php echo e($patient['allergies']); ?>
                </span>
            <?php elseif ($hasAllergyInfo): ?>
                <span class="inline-flex items-center gap-1 bg-surface-container text-on-surface-variant text-xs px-2.5 py-1 rounded-full font-medium">
                    No Known Drug Allergies
                </span>
            <?php else: ?>
                <span class="inline-flex items-center gap-1 bg-amber-500/10 text-amber-600 border border-amber-500/30 text-xs px-2.5 py-1 rounded-full font-medium">
                    Allergies: Pending Assessment
                </span>
            <?php endif; ?>
            <?php if ($queueId): ?>
                <button type="button" 
                        onclick="openHoldPatientModal(<?php echo (int)$queueId; ?>, '<?php echo e(addslashes($patient['first_name'] . ' ' . $patient['last_name'])); ?>', 'consultation_michael_chen.php')" 
                        class="px-3 py-1.5 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 text-amber-800 dark:text-amber-300 rounded-lg font-label-md text-xs transition-colors font-bold flex items-center gap-1 cursor-pointer" 
                        title="Put patient on hold if absent, and call next patient">
                    <span class="material-symbols-outlined text-[16px]">pause_circle</span>
                    Put On Hold
                </button>
            <?php endif; ?>
            <a href="consultation_michael_chen.php" class="px-3 py-1.5 border border-outline-variant hover:bg-surface-container rounded-lg font-label-md text-xs transition-colors font-semibold flex items-center gap-1" title="View assigned queue / call other patients">
                <span class="material-symbols-outlined text-[16px]">queue</span>
                Doctor Queue
            </a>
            <a href="patient_profile_michael_chen.php?id=<?php echo (int)$patient['id']; ?>" class="px-3 py-1.5 border border-outline-variant hover:bg-surface-container rounded-lg font-label-md text-xs transition-colors font-semibold flex items-center gap-1">
                <span class="material-symbols-outlined text-[15px]">folder_shared</span>
                Full Medical Chart
            </a>
        </div>
    </div>

    <!-- Verified Completed Laboratory Findings Banner (If available) -->
    <?php if (!empty($patientLabResults)): ?>
        <div class="mb-md p-4 sm:p-5 rounded-2xl bg-secondary-fixed/20 border-2 border-secondary/40 shadow-sm">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 border-b border-secondary/30 mb-3 gap-2">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-secondary text-[26px]">verified</span>
                    <div>
                        <h3 class="font-headline-sm text-base font-bold text-on-surface">Verified Laboratory Results &amp; Diagnostic Findings</h3>
                        <p class="text-xs text-on-surface-variant">Pathologist verified diagnostic findings to guide your clinical diagnosis and drug prescribing.</p>
                    </div>
                </div>
                <span class="text-xs bg-secondary text-on-secondary px-3 py-1 rounded-full font-bold shadow-xs">
                    <?php echo count($patientLabResults); ?> Test(s) Verified
                </span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <?php foreach ($patientLabResults as $lres): ?>
                    <div class="p-3.5 bg-surface rounded-xl border border-outline-variant shadow-xs space-y-1.5">
                        <div class="flex justify-between items-start">
                            <span class="font-bold text-xs text-primary"><?php echo e($lres['test_name']); ?></span>
                            <span class="text-[10px] text-on-surface-variant font-mono"><?php echo date('M d, g:i A', strtotime($lres['completed_at'])); ?></span>
                        </div>
                        <p class="text-[11px] text-on-surface-variant">Verified by: <strong class="text-on-surface"><?php echo e($lres['technician_name'] ?: 'Clinical Pathologist'); ?></strong> (<?php echo e($lres['category'] ?: 'Laboratory'); ?>)</p>
                        
                        <div class="p-2.5 bg-surface-container-lowest rounded-lg border border-outline-variant text-xs text-on-surface font-semibold">
                            <span class="text-[10px] text-secondary uppercase font-bold block mb-0.5">Findings &amp; Diagnosis:</span>
                            <?php echo nl2br(e($lres['result_summary'])); ?>
                        </div>

                        <?php if (!empty($lres['result_values'])): ?>
                            <div class="p-2 bg-surface-container-lowest rounded-lg border border-outline-variant text-[11px] font-mono text-on-surface-variant">
                                <span class="text-[9px] uppercase font-bold text-on-surface-variant block mb-0.5">Numerical Markers / Values:</span>
                                <?php echo nl2br(e($lres['result_values'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Consultation Workspace Form -->
    <form method="POST" action="consultation_michael_chen.php?id=<?php echo (int)$patient['id']; ?><?php echo $queueId ? '&queue_id=' . (int)$queueId : ''; ?>" class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-lg">
        <?php echo csrfField(); ?>
        <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
        <input type="hidden" name="queue_id" value="<?php echo $queueId ? (int)$queueId : ''; ?>">

        <!-- Left: Clinical Documentation (8 cols on desktop) -->
        <div class="lg:col-span-8 flex flex-col gap-4 sm:gap-lg">
            <!-- Section A: Patient Clinical Intake by Doctor (Baseline & Medical History) -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm space-y-md">
                <div class="flex justify-between items-center border-b border-outline-variant pb-sm">
                    <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">person_search</span>
                        Patient Baseline Info &amp; Medical History (Xogta Bukaanka ee Dhakhtarka)
                    </h3>
                    <span class="text-[11px] text-on-surface-variant">Permanent Medical Record</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Patient Age in Years (Da'da Bukaanka)</label>
                        <input name="age_years" type="number" min="0" max="130" placeholder="e.g. 34" value="<?php echo !empty($patient['age']) ? (int)$patient['age'] : ''; ?>" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Blood Group (Nooca Dhiigga)</label>
                        <select name="blood_group" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                            <option value="">-- Select Blood Group --</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $bg): ?>
                                <option value="<?php echo $bg; ?>" <?php echo ($patient['blood_group'] === $bg) ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-error mb-0.5">Drug Allergies (Xasaasiyadda Daawooyinka)</label>
                        <input name="allergies" type="text" placeholder="e.g. Penicillin, Amoxicillin, Sulfa (or None)" value="<?php echo e($patient['allergies'] ?? ''); ?>" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-error outline-none font-semibold text-error">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Chronic Medical History (Xanuunnada Guud)</label>
                        <input name="medical_history" type="text" placeholder="e.g. Type 2 Diabetes, Hypertension, Asthma" value="<?php echo e($patient['medical_history'] ?? ''); ?>" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    </div>
                </div>
            </div>

            <!-- Section B: Clinical Examination Vitals (Triaged / Examined) -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm space-y-md">
                <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold border-b border-outline-variant pb-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">vital_signs</span>
                    Clinical Physical Examination &amp; Vitals
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Blood Pressure</label>
                        <div class="flex items-center gap-1">
                            <input name="systolic" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="120" type="number" value="<?php echo !empty($patient['vitals']['systolic']) ? (int)$patient['vitals']['systolic'] : ''; ?>">
                            <span class="text-on-surface-variant text-xs">/</span>
                            <input name="diastolic" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="80" type="number" value="<?php echo !empty($patient['vitals']['diastolic']) ? (int)$patient['vitals']['diastolic'] : ''; ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Heart Rate (BPM)</label>
                        <input name="heart_rate" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="72" type="number" value="<?php echo !empty($patient['vitals']['heart_rate']) ? (int)$patient['vitals']['heart_rate'] : ''; ?>">
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Temperature (°C)</label>
                        <input name="temperature" step="0.1" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="37.0" type="number" value="<?php echo !empty($patient['vitals']['temperature']) ? (float)$patient['vitals']['temperature'] : ''; ?>">
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">SpO2 Oxygen (%)</label>
                        <input name="spo2_oxygen" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="98" type="number" value="<?php echo !empty($patient['vitals']['spo2_oxygen']) ? (int)$patient['vitals']['spo2_oxygen'] : ''; ?>">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Respiratory Rate</label>
                        <input name="respiratory_rate" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface" placeholder="18 breaths/min" type="number" value="<?php echo !empty($patient['vitals']['respiratory_rate']) ? (int)$patient['vitals']['respiratory_rate'] : ''; ?>">
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Weight (kg)</label>
                        <input name="weight_kg" step="0.1" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface" placeholder="70.5" type="number" value="<?php echo !empty($patient['vitals']['weight_kg']) ? (float)$patient['vitals']['weight_kg'] : ''; ?>">
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs">Height (cm)</label>
                        <input name="height_cm" step="0.1" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface" placeholder="175" type="number" value="<?php echo !empty($patient['vitals']['height_cm']) ? (float)$patient['vitals']['height_cm'] : ''; ?>">
                    </div>
                </div>
            </div>

            <!-- Section C: SOAP Clinical Documentation -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm space-y-md">
                <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold border-b border-outline-variant pb-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">clinical_notes</span>
                    SOAP Clinical Encounter Notes
                </h3>

                <div>
                    <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Subjective: Chief Complaint &amp; History of Present Illness (HPI)</label>
                    <textarea name="subjective_notes" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none resize-none" rows="3" placeholder="Patient reports fever, chills, body aches, and persistent cough for 4 days..."><?php echo e($subjectiveVal); ?></textarea>
                </div>

                <div>
                    <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Objective: Physical Findings &amp; Clinical Observations</label>
                    <textarea name="objective_findings" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none resize-none" rows="2" placeholder="Chest clear to auscultation bilaterally, abdomen soft non-tender, throat slightly hyperemic..."><?php echo e($objectiveVal); ?></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Primary Assessment / Diagnosis *</label>
                        <input name="assessment_diagnosis" required value="<?php echo e($assessmentVal); ?>" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold text-primary" placeholder="e.g. Acute Uncomplicated Malaria / Bronchitis" type="text">
                    </div>
                    <div>
                        <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Secondary / Differential Diagnosis</label>
                        <input name="secondary_diagnosis" value="<?php echo e($secondaryVal); ?>" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Typhoid Fever / Upper RTI" type="text">
                    </div>
                </div>
            </div>

            <!-- Section D: E-Prescribing & Medication Management -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm space-y-md">
                <div class="flex justify-between items-center border-b border-outline-variant pb-sm">
                    <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">prescriptions</span>
                        E-Prescribing (Daawooyinka loo qorayo Bukaanka)
                    </h3>
                    <button type="button" onclick="addMedicationRow()" class="px-3 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-xs font-semibold rounded-lg flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px] text-primary">add_circle</span>
                        Add Drug
                    </button>
                </div>

                <div id="medication-rows-container" class="space-y-3">
                    <div class="med-row p-3 bg-surface-container-lowest border border-outline-variant rounded-xl grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                        <div class="sm:col-span-5">
                            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Select Drug</label>
                            <select name="med_ids[]" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface">
                                <option value="">-- No Medication / Select Drug --</option>
                                <?php foreach ($medications as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>">
                                        <?php echo e($m['name']); ?> (Stock: <?php echo (int)$m['current_stock']; ?>) - $<?php echo number_format((float)$m['unit_price'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Quantity</label>
                            <input name="med_qtys[]" type="number" min="1" value="1" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface font-bold">
                        </div>
                        <div class="sm:col-span-4">
                            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Dosage / Instructions</label>
                            <input name="med_dosages[]" type="text" placeholder="e.g. 1 tab 3x daily after meals x 5 days" value="" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface">
                        </div>
                        <div class="sm:col-span-1 text-right pt-4 sm:pt-0">
                            <button type="button" onclick="removeMedRow(this)" class="text-on-surface-variant hover:text-error p-1 rounded cursor-pointer" title="Remove">
                                <span class="material-symbols-outlined text-[20px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Lab Orders, Care Plan & Actions (4 cols on desktop) -->
        <div class="lg:col-span-4 flex flex-col gap-4 sm:gap-lg">
            <!-- Laboratory Diagnostic Orders Section -->
            <div class="bg-surface border-2 border-secondary/30 rounded-2xl p-4 sm:p-lg shadow-sm space-y-md">
                <div class="flex items-center justify-between border-b border-outline-variant pb-sm">
                    <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary">biotech</span>
                        Order Laboratory Diagnostics
                    </h3>
                    <span class="text-[10px] bg-secondary-fixed text-on-secondary-fixed px-2 py-0.5 rounded-full font-bold">Test Catalog</span>
                </div>
                <p class="text-xs text-on-surface-variant">Select diagnostic tests to send patient to Lab &amp; Billing before final prescription:</p>

                <div class="space-y-1.5 text-xs max-h-60 overflow-y-auto custom-scrollbar pr-1">
                    <?php foreach ($labCatalog as $lt): ?>
                        <label class="flex items-center justify-between p-2 rounded-lg bg-surface-container-lowest border border-outline-variant hover:bg-surface-container-low cursor-pointer transition-colors">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="lab_tests[]" value="<?php echo e($lt['test_name']); ?>" class="w-4 h-4 rounded text-secondary border-outline-variant">
                                <div>
                                    <span class="font-semibold text-on-surface block"><?php echo e($lt['test_name']); ?></span>
                                    <span class="text-[10px] text-on-surface-variant"><?php echo e($lt['category']); ?> • <?php echo e($lt['specimen_type']); ?></span>
                                </div>
                            </div>
                            <span class="font-mono font-bold text-secondary text-xs">$<?php echo number_format((float)$lt['price'], 2); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Priority</label>
                    <select name="priority" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-semibold">
                        <option value="routine">Routine</option>
                        <option value="urgent">Urgent Priority</option>
                        <option value="stat">STAT / Emergency Priority</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Clinical Instructions / Notes for Lab Tech</label>
                    <textarea name="clinical_notes" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none" rows="2" placeholder="e.g. Suspected malaria & typhoid fever. Patient has high grade fever for 4 days."></textarea>
                </div>

                <button type="submit" name="action" value="order_lab_tests" class="w-full py-3 px-4 bg-secondary hover:bg-on-secondary-container text-on-secondary font-bold rounded-xl text-xs shadow-md flex items-center justify-center gap-2 cursor-pointer transition-all">
                    <span class="material-symbols-outlined text-[18px]">biotech</span>
                    Order Lab Tests &amp; Send to Billing / Lab
                </button>
            </div>

            <!-- Treatment Plan & Follow-up -->
            <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm space-y-md">
                <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold border-b border-outline-variant pb-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">event_available</span>
                    Care Plan &amp; Follow-up
                </h3>
                <div>
                    <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Doctor Clinical Advice / Plan</label>
                    <textarea name="treatment_plan" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none" rows="2" placeholder="Drink plenty of fluids, rest for 3 days, return if fever exceeds 39°C..."><?php echo e($treatmentPlanVal); ?></textarea>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-xs">
                        <label class="block font-label-md text-xs text-on-surface-variant font-semibold">
                            Follow-up Appointment Date <span class="text-on-surface-variant/70 font-normal">(Optional / Ikhtiyaari)</span>
                        </label>
                        <button type="button" onclick="document.getElementById('follow_up_date_input').value = ''" class="text-[11px] text-error hover:underline flex items-center gap-0.5 cursor-pointer" title="Ka noqo ama ha u qabanin wax ballan ah">
                            <span class="material-symbols-outlined text-[14px]">event_busy</span>
                            Clear / No Follow-up
                        </button>
                    </div>
                    <input id="follow_up_date_input" name="follow_up_date" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none" type="date" value="<?php echo e($followUpDateVal); ?>" min="<?php echo date('Y-m-d'); ?>">
                    <div class="flex items-center gap-1.5 mt-2">
                        <span class="text-[10px] text-on-surface-variant font-medium">Quick Presets:</span>
                        <button type="button" onclick="setFollowUpDays(3)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container border border-outline-variant hover:bg-primary hover:text-on-primary transition-colors cursor-pointer">+3 Days</button>
                        <button type="button" onclick="setFollowUpDays(7)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container border border-outline-variant hover:bg-primary hover:text-on-primary transition-colors cursor-pointer">+1 Week</button>
                        <button type="button" onclick="setFollowUpDays(14)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container border border-outline-variant hover:bg-primary hover:text-on-primary transition-colors cursor-pointer">+2 Weeks</button>
                        <button type="button" onclick="setFollowUpDays(30)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container border border-outline-variant hover:bg-primary hover:text-on-primary transition-colors cursor-pointer">+1 Month</button>
                    </div>
                    <p class="text-[11px] text-on-surface-variant/80 mt-1.5 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px] text-primary">info</span>
                        Kaliya buuxi haddii bukaanku u baahan yahay ballan dib-u-eegis ah. Haddii aadan taariikh dooran, wax ballan ah lama diiwaangelinayo.
                    </p>
                </div>
            </div>

            <!-- Action: Complete Consultation & Prescribe Medications -->
            <div class="bg-surface-container-lowest border border-outline-variant rounded-xl p-4 shadow-sm space-y-3">
                <button type="submit" name="action" value="save_consultation" class="w-full py-3.5 px-4 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-sm shadow-md flex items-center justify-center gap-2 cursor-pointer transition-all">
                    <span class="material-symbols-outlined text-[20px]">task_alt</span>
                    Complete Consultation &amp; Prescribe Drugs
                </button>
                <a href="doctor_dashboard.php" class="w-full py-2 px-4 bg-surface border border-outline-variant text-on-surface hover:bg-surface-container text-xs font-semibold rounded-lg flex items-center justify-center transition-colors">
                    Cancel / Return to Dashboard
                </a>
            </div>

            <!-- Past Consultations Summary -->
            <?php if (!empty($pastConsultations)): ?>
                <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm space-y-2">
                    <p class="font-bold text-xs text-primary flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">history</span>
                        Past Encounters (<?php echo count($pastConsultations); ?>)
                    </p>
                    <div class="space-y-2 max-h-48 overflow-y-auto custom-scrollbar">
                        <?php foreach ($pastConsultations as $cns): ?>
                            <div class="p-2 bg-surface-container-lowest rounded-lg border border-outline-variant text-xs">
                                <div class="flex justify-between font-bold text-on-surface">
                                    <span><?php echo e($cns['consultation_number']); ?></span>
                                    <span class="text-on-surface-variant font-mono"><?php echo date('M d, Y', strtotime($cns['created_at'])); ?></span>
                                </div>
                                <p class="text-primary font-semibold mt-0.5"><?php echo e($cns['assessment_diagnosis']); ?></p>
                                <p class="text-[11px] text-on-surface-variant">Attending: <?php echo e($cns['doctor_name']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </form>
</main>

<template id="med-row-template">
    <div class="med-row p-3 bg-surface-container-lowest border border-outline-variant rounded-xl grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
        <div class="sm:col-span-5">
            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Select Drug</label>
            <select name="med_ids[]" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface">
                <option value="">-- Select Medication --</option>
                <?php foreach ($medications as $m): ?>
                    <option value="<?php echo (int)$m['id']; ?>">
                        <?php echo e($m['name']); ?> (Stock: <?php echo (int)$m['current_stock']; ?>) - $<?php echo number_format((float)$m['unit_price'], 2); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Quantity</label>
            <input name="med_qtys[]" type="number" min="1" value="10" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface font-bold">
        </div>
        <div class="sm:col-span-4">
            <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Dosage / Instructions</label>
            <input name="med_dosages[]" type="text" placeholder="e.g. 1 tab 3x daily after meals" value="" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface">
        </div>
        <div class="sm:col-span-1 text-right pt-4 sm:pt-0">
            <button type="button" onclick="removeMedRow(this)" class="text-on-surface-variant hover:text-error p-1 rounded cursor-pointer" title="Remove">
                <span class="material-symbols-outlined text-[20px]">delete</span>
            </button>
        </div>
    </div>
</template>

<!-- MODAL: Put Patient On Hold Confirmation -->
<div id="hold-patient-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 text-amber-600 dark:text-amber-400">
            <div class="w-12 h-12 rounded-xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[28px]">pause_circle</span>
            </div>
            <div>
                <h3 class="font-bold text-base text-on-surface">Bukaanka Dib Ma U Dhigtaa?</h3>
                <p class="text-xs text-on-surface-variant">Xaqiijinta gelinta bukaanka xaaladda On Hold</p>
            </div>
        </div>

        <div class="p-3.5 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface space-y-1.5">
            <p><strong class="text-on-surface">Bukaanka:</strong> <span id="hold_patient_name" class="font-bold text-primary"></span></p>
            <p class="text-on-surface-variant leading-relaxed">
                Bukaankan waxaa si ku meel-gaar ah loogu wareejinayaa safka <strong>On Hold</strong> safkana lagama saari doono. Waxaad awood u leedahay inaad wacato bukaan kale, bukaankanna dib ugu yeerto (Recall) marka uu yimaado.
            </p>
        </div>

        <form id="hold-patient-form" method="POST" action="consultation_michael_chen.php" class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" id="hold_queue_id" name="queue_id" value="">
            <input type="hidden" name="status" value="on_hold">
            <input type="hidden" id="hold_redirect" name="redirect" value="consultation_michael_chen.php">

            <button type="button" onclick="closeHoldPatientModal()" class="px-3.5 py-2 rounded-xl border border-outline-variant text-xs font-semibold text-on-surface hover:bg-surface-container-low cursor-pointer">
                Ka Noqo (Cancel)
            </button>
            <button type="submit" class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold shadow-xs cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">pause_circle</span>
                Haa, Dib U Dhig (On Hold)
            </button>
        </form>
    </div>
</div>

<script>
    function openHoldPatientModal(queueId, patientName, redirectUrl = '') {
        const modal = document.getElementById('hold-patient-modal');
        if (!modal) return;
        document.getElementById('hold_queue_id').value = queueId;
        document.getElementById('hold_patient_name').textContent = patientName || 'Bukaanka';
        if (redirectUrl) {
            const redirElem = document.getElementById('hold_redirect');
            if (redirElem) redirElem.value = redirectUrl;
        }
        modal.classList.remove('hidden');
    }

    function closeHoldPatientModal() {
        const modal = document.getElementById('hold-patient-modal');
        if (modal) modal.classList.add('hidden');
    }

    function addMedicationRow() {
        const template = document.getElementById('med-row-template');
        const container = document.getElementById('medication-rows-container');
        const clone = template.content.cloneNode(true);
        container.appendChild(clone);
    }

    function removeMedRow(btn) {
        const rows = document.querySelectorAll('.med-row');
        if (rows.length > 1) {
            btn.closest('.med-row').remove();
        } else {
            alert('At least one medication row must remain. You can select "None" if no drugs are prescribed.');
        }
    }

    function setFollowUpDays(days) {
        const d = new Date();
        d.setDate(d.getDate() + days);
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const input = document.getElementById('follow_up_date_input');
        if (input) {
            input.value = `${yyyy}-${mm}-${dd}`;
        }
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
