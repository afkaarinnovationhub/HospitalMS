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

// If no patient specified in URL, check if there is an active waiting patient assigned to this doctor
if (!$patient) {
    $pdo = getDBConnection();
    if ($currentDoctorId) {
        $stmt = $pdo->prepare("
            SELECT patient_id, id 
            FROM patient_queues 
            WHERE status IN ('waiting', 'in_consultation') AND doctor_id = :doc_id 
            ORDER BY priority = 'emergency' DESC, priority = 'urgent' DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute([':doc_id' => $currentDoctorId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT patient_id, id 
            FROM patient_queues 
            WHERE status IN ('waiting', 'in_consultation') 
            ORDER BY priority = 'emergency' DESC, priority = 'urgent' DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute();
    }

    $activeQueue = $stmt->fetch();
    if ($activeQueue) {
        $patientId = (int)$activeQueue['patient_id'];
        $queueId   = (int)$activeQueue['id'];
        $patient   = PatientOperation::getPatientById($patientId);
    }
}

// If specific queue_id is passed, verify doctor assignment security
if ($currentDoctorId && $queueId) {
    $pdo = getDBConnection();
    $stmtQDoc = $pdo->prepare("SELECT doctor_id FROM patient_queues WHERE id = ?");
    $stmtQDoc->execute([$queueId]);
    $assignedDoc = $stmtQDoc->fetchColumn();
    if ($assignedDoc !== false && $assignedDoc !== null && (int)$assignedDoc !== $currentDoctorId) {
        setFlashMessage('error', 'Access denied. This patient encounter is assigned to another doctor.');
        safeRedirect('doctor_dashboard.php');
    }
}

// If still no active patient in queue, render clean "Consultation Room Ready" state
if (!$patient) {
    $pageTitle = 'Consultation Room - MedCore Systems';
    $headerTitle = 'MedCore Management - Clinical Consultation';
    $activePage = 'consultations';
    include __DIR__ . '/../components/header.php';
    ?>
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
        <?php if (!empty($successMessage)): ?>
            <div class="max-w-2xl mx-auto mb-6 p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-secondary text-[22px] shrink-0 mt-0.5">check_circle</span>
                <div class="flex-1">
                    <p class="font-bold">Consultation Finished</p>
                    <p class="mt-0.5"><?php echo e($successMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="max-w-2xl mx-auto mt-8 bg-surface border border-outline-variant rounded-2xl p-8 sm:p-10 text-center shadow-md">
            <div class="w-16 h-16 rounded-full bg-primary-fixed/40 text-primary flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-[36px]">stethoscope</span>
            </div>
            <h2 class="text-xl font-bold text-on-surface">Consultation Workspace Ready</h2>
            <p class="mt-2 text-xs sm:text-sm text-on-surface-variant max-w-md mx-auto">
                No active patients are currently waiting in your consultation queue. When a patient is checked in or called from the queue, their clinical chart will appear here.
            </p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="doctor_dashboard.php" class="px-4 py-2.5 bg-primary text-on-primary rounded-xl font-bold text-xs flex items-center gap-2 hover:bg-primary-container shadow-sm transition-all">
                    <span class="material-symbols-outlined text-[18px]">dashboard</span>
                    Doctor Dashboard
                </a>
                <a href="queue_management.php" class="px-4 py-2.5 bg-surface-container border border-outline-variant text-on-surface rounded-xl font-bold text-xs flex items-center gap-2 hover:bg-surface-container-high transition-all">
                    <span class="material-symbols-outlined text-[18px]">queue</span>
                    Live Queue Board
                </a>
                <?php if (!$currentDoctorId): ?>
                    <a href="reception.php" class="px-4 py-2.5 bg-secondary text-on-secondary rounded-xl font-bold text-xs flex items-center gap-2 hover:bg-on-secondary-container transition-all">
                        <span class="material-symbols-outlined text-[18px]">person_add</span>
                        Reception Intake
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php
    include __DIR__ . '/../components/footer.php';
    exit;
}

// Mark queue status as 'in_consultation' ONLY IF currently 'waiting'
if ($queueId) {
    $pdo = getDBConnection();
    $stmtQCheck = $pdo->prepare("SELECT status FROM patient_queues WHERE id = ?");
    $stmtQCheck->execute([$queueId]);
    $currStatus = $stmtQCheck->fetchColumn();
    if ($currStatus === 'waiting') {
        PatientOperation::updateQueueStatus($queueId, 'in_consultation');
    }
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
$followUpDateVal  = !empty($draftConsultation['follow_up_date']) ? $draftConsultation['follow_up_date'] : date('Y-m-d', strtotime('+7 days'));

$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$hasAge = !empty($patient['dob']) && !empty($patient['age']);
$hasBlood = !empty($patient['blood_group']);
$hasAllergyInfo = !empty($patient['allergies']);
$isAllergic = $hasAllergyInfo && strtolower($patient['allergies']) !== 'none known' && strtolower($patient['allergies']) !== 'none';

$pageTitle = 'Consultation Workspace - ' . e($patient['full_name']) . ' - MedCore Systems';
$headerTitle = 'MedCore Management - Consultation';
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
                    <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Follow-up Appointment Date</label>
                    <input name="follow_up_date" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface" type="date" value="<?php echo e($followUpDateVal); ?>">
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

<script>
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
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
