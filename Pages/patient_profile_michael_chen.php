<?php
/**
 * MedCore Systems - Dynamic Patient Clinical Chart & Electronic Medical Record (EMR)
 * Provides a unified patient journey: Consultations, Lab Diagnostic Results, Prescriptions,
 * Follow-up Appointments Tracking, and Vitals History.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/../OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/../CONTROLS/PatientController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_DOCTOR, ROLE_PHARMACY, ROLE_RECEPTION_CASHIER]);

$currentUser = getCurrentUser();
$isDoctor = (($currentUser['role'] ?? '') === ROLE_DOCTOR);
$currentDoctorId = $isDoctor ? (int)($currentUser['id'] ?? 0) : 0;

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

// Fallback to first patient in directory if not specified
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

// Comprehensive Clinical Datasets
$clinicalSummary = PatientOperation::getPatientClinicalSummary($patientId);
$consultations   = ConsultationOperation::getConsultationsByPatient($patientId);
$labOrders       = LaboratoryOperation::getPatientLabOrders($patientId);
$followUps       = PatientOperation::getPatientFollowUps($patientId);
$vitalsHistory   = PatientOperation::getPatientVitalsHistory($patientId, 30);

// Fetch Prescriptions for this Patient with med items
$pdo = getDBConnection();
$stmtRx = $pdo->prepare("
    SELECT p.*, COUNT(pi.id) as item_count, 
           GROUP_CONCAT(CONCAT(m.name, ' (Qty: ', pi.quantity, IF(pi.dosage_instructions IS NOT NULL AND pi.dosage_instructions != '', CONCAT(' - ', pi.dosage_instructions), ''), ')') SEPARATOR ' • ') as med_names
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
$prescriptions = $stmtRx->fetchAll();

$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$hasAllergy = (!empty($patient['allergies']) && strtolower($patient['allergies']) !== 'none known' && strtolower($patient['allergies']) !== 'none');

$nextFu = $clinicalSummary['next_follow_up'] ?? null;
$activeTab = sanitizeString($_GET['tab'] ?? 'timeline');
if (!in_array($activeTab, ['timeline', 'consultations', 'lab', 'prescriptions', 'followups', 'vitals'], true)) {
    $activeTab = 'timeline';
}

$pageTitle = "Patient EMR Chart - {$patient['full_name']} - " . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Patient Clinical Chart';
$activePage = 'patients';

include __DIR__ . '/../components/header.php';
?>

<style>
@media print {
    aside, header, nav, #vitals-modal, #queue-modal, .no-print {
        display: none !important;
    }
    main {
        padding: 0 !important;
        background: #fff !important;
    }
    .print-only {
        display: block !important;
    }
    .tab-content {
        display: block !important;
        margin-bottom: 2rem !important;
    }
}
.tab-btn.active {
    background-color: var(--color-primary, #0052cc);
    color: #ffffff;
    border-color: var(--color-primary, #0052cc);
}
</style>

<!-- Main Page Content Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-10 bg-background flex flex-col gap-md sm:gap-lg custom-scrollbar">
    
    <!-- Top Action Bar & Breadcrumb -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 no-print">
        <nav class="flex items-center gap-xs font-body-sm text-xs sm:text-body-sm text-on-surface-variant">
            <a class="hover:text-primary transition-colors flex items-center gap-1" href="patient_registration.php">
                <span class="material-symbols-outlined text-[16px]">group</span>
                Patients Directory
            </a>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
            <span class="text-on-surface font-semibold"><?php echo e($patient['full_name']); ?> (<?php echo e($patient['mrn']); ?>)</span>
        </nav>
        
        <div class="flex items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3 py-1.5 bg-surface border border-outline-variant hover:bg-surface-container rounded-lg text-xs font-semibold text-on-surface flex items-center gap-1.5 shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Medical Chart
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs no-print">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <p><?php echo e($errorMessage); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs no-print">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <p><?php echo e($successMessage); ?></p>
        </div>
    <?php endif; ?>

    <!-- Active Follow-up Banner (If patient has a follow-up today or upcoming) -->
    <?php if ($nextFu): ?>
        <?php 
            $isToday = ((int)$nextFu['days_diff'] === 0);
            $bannerBg = $isToday ? 'bg-emerald-500/15 border-emerald-500/40 text-emerald-950 dark:text-emerald-100' : 'bg-primary-fixed/25 border-primary/40 text-on-primary-fixed-variant';
        ?>
        <div class="p-3.5 sm:p-4 rounded-2xl border <?php echo $bannerBg; ?> flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 shadow-xs">
            <div class="flex items-start sm:items-center gap-3">
                <div class="w-10 h-10 rounded-xl <?php echo $isToday ? 'bg-emerald-600 text-white animate-pulse' : 'bg-primary text-on-primary'; ?> flex items-center justify-center shrink-0 shadow-xs">
                    <span class="material-symbols-outlined text-[22px]">event_available</span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="font-bold text-xs sm:text-sm">
                            <?php if ($isToday): ?>
                                🔔 BALLAN MAANTA AH (FOLLOW-UP APPOINTMENT TODAY)
                            <?php else: ?>
                                📅 Ballan Soo Socota (Upcoming Follow-up in <?php echo (int)$nextFu['days_diff']; ?> days)
                            <?php endif; ?>
                        </h4>
                        <span class="text-[10px] uppercase font-mono font-bold px-2 py-0.5 rounded-full <?php echo $isToday ? 'bg-emerald-700 text-white' : 'bg-primary text-on-primary'; ?>">
                            <?php echo date('M d, Y', strtotime($nextFu['follow_up_date'])); ?>
                        </span>
                    </div>
                    <p class="text-xs mt-0.5 opacity-90">
                        Attending Doctor: <strong><?php echo e($nextFu['doctor_name']); ?></strong> • Diagnosis: <em><?php echo e($nextFu['assessment_diagnosis']); ?></em>
                    </p>
                </div>
            </div>
            <?php if (!$isDoctor): ?>
                <button type="button" onclick="openQueueModal()" class="w-full sm:w-auto px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs shadow-xs flex items-center justify-center gap-1.5 cursor-pointer no-print">
                    <span class="material-symbols-outlined text-[16px]">queue</span>
                    Check-in / Route to Doctor
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Patient Header Card -->
    <section class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-6 flex flex-col lg:flex-row gap-4 sm:gap-6 justify-between items-start shadow-sm">
        <!-- Patient Demographics -->
        <div class="flex flex-col sm:flex-row items-start gap-4 sm:gap-5 w-full lg:w-auto">
            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-primary-container text-on-primary-container flex items-center justify-center font-display-lg text-2xl sm:text-3xl border border-primary/30 shadow-sm shrink-0 font-bold">
                <?php echo e($initials); ?>
            </div>
            <div class="flex flex-col gap-1 w-full">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="font-headline-lg text-lg sm:text-2xl text-on-surface m-0 font-bold"><?php echo e($patient['full_name']); ?></h1>
                    <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-xs px-2.5 py-0.5 rounded-full font-bold">
                        Active Clinical File
                    </span>
                    <?php if ($hasAllergy): ?>
                        <span class="bg-error-container text-on-error-container font-label-md text-xs px-2.5 py-0.5 rounded-full font-bold flex items-center gap-1 border border-error/30">
                            <span class="material-symbols-outlined text-[14px]">warning</span>
                            Allergy: <?php echo e($patient['allergies']); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-2 gap-x-4 sm:gap-x-8 mt-2 text-xs">
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">MRN Number</p>
                        <p class="font-mono text-xs sm:text-sm text-primary mt-0.5 font-bold"><?php echo e($patient['mrn']); ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Age / Gender</p>
                        <p class="text-on-surface mt-0.5 font-medium"><?php echo (int)$patient['age']; ?> yrs • <span class="capitalize"><?php echo e($patient['gender']); ?></span></p>
                    </div>
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Contact Phone</p>
                        <p class="text-on-surface mt-0.5 font-medium font-mono"><?php echo e($patient['phone']); ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] text-on-surface-variant font-semibold">Blood Group</p>
                        <p class="text-error mt-0.5 font-bold"><?php echo e($patient['blood_group'] ?: 'Not recorded'); ?></p>
                    </div>
                </div>

                <?php if (!empty($patient['medical_history'])): ?>
                    <div class="mt-2 pt-2 border-t border-outline-variant/60 text-xs">
                        <span class="text-on-surface-variant font-semibold">Chronic Conditions / Baseline History: </span>
                        <span class="text-on-surface font-medium"><?php echo e($patient['medical_history']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Primary Action Buttons -->
        <div class="flex flex-wrap sm:flex-nowrap gap-2 w-full lg:w-auto shrink-0 no-print">
            <button type="button" onclick="openVitalsModal()" class="flex-1 lg:flex-none flex items-center justify-center gap-1.5 px-3.5 py-2 border border-outline-variant rounded-xl text-xs font-semibold text-on-surface bg-surface hover:bg-surface-container transition-colors shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[18px] text-primary">vital_signs</span>
                Record Vitals
            </button>
            <?php if (!$isDoctor): ?>
                <button type="button" onclick="openQueueModal()" class="flex-1 lg:flex-none flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-on-primary bg-primary hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">queue</span>
                    Route to Doctor
                </button>
            <?php else: ?>
                <a href="consultation_michael_chen.php?id=<?php echo $patientId; ?>" class="flex-1 lg:flex-none flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-on-primary bg-primary hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">stethoscope</span>
                    Open Consultation
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- 4 Clinical KPI Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- KPI 1: Doctor Consultations -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs text-on-surface-variant uppercase font-semibold">Doctor Visits</p>
                <h3 class="text-2xl font-bold text-on-surface mt-1 font-mono"><?php echo $clinicalSummary['total_consultations']; ?></h3>
                <p class="text-[11px] text-primary mt-0.5 font-medium">Completed encounters</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-primary-container text-on-primary-container flex items-center justify-center">
                <span class="material-symbols-outlined text-[24px]">stethoscope</span>
            </div>
        </div>

        <!-- KPI 2: Laboratory Orders -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs text-on-surface-variant uppercase font-semibold">Lab Diagnostics</p>
                <h3 class="text-2xl font-bold text-on-surface mt-1 font-mono"><?php echo $clinicalSummary['total_lab_orders']; ?></h3>
                <p class="text-[11px] text-secondary mt-0.5 font-medium"><?php echo $clinicalSummary['completed_lab_orders']; ?> tests verified</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                <span class="material-symbols-outlined text-[24px]">biotech</span>
            </div>
        </div>

        <!-- KPI 3: Prescriptions -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs text-on-surface-variant uppercase font-semibold">Prescriptions (Rx)</p>
                <h3 class="text-2xl font-bold text-on-surface mt-1 font-mono"><?php echo $clinicalSummary['total_prescriptions']; ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-0.5 font-medium"><?php echo $clinicalSummary['dispensed_rx']; ?> dispensed</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-surface-container-high text-on-surface flex items-center justify-center">
                <span class="material-symbols-outlined text-[24px]">medication</span>
            </div>
        </div>

        <!-- KPI 4: Follow-up Status -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-xs text-on-surface-variant uppercase font-semibold">Next Follow-up</p>
                <?php if ($nextFu): ?>
                    <h3 class="text-base font-bold text-primary mt-1 font-mono">
                        <?php echo date('M d, Y', strtotime($nextFu['follow_up_date'])); ?>
                    </h3>
                    <p class="text-[11px] <?php echo ((int)$nextFu['days_diff'] === 0) ? 'text-emerald-600 font-bold' : 'text-on-surface-variant'; ?> mt-0.5">
                        <?php echo ((int)$nextFu['days_diff'] === 0) ? 'Due Today!' : "In {$nextFu['days_diff']} days"; ?>
                    </p>
                <?php else: ?>
                    <h3 class="text-base font-bold text-on-surface-variant mt-1">None Scheduled</h3>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">No active follow-up</p>
                <?php endif; ?>
            </div>
            <div class="w-12 h-12 rounded-xl bg-surface-container-high text-primary flex items-center justify-center">
                <span class="material-symbols-outlined text-[24px]">event_note</span>
            </div>
        </div>
    </div>

    <!-- Clinical Chart Navigation Tabs -->
    <div class="border-b border-outline-variant flex flex-wrap gap-2 no-print">
        <button type="button" onclick="switchTab('timeline')" id="btn-tab-timeline" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'timeline') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">history_edu</span>
            Clinical Timeline
        </button>
        <button type="button" onclick="switchTab('consultations')" id="btn-tab-consultations" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'consultations') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">stethoscope</span>
            Doctor Consultations (<?php echo count($consultations); ?>)
        </button>
        <button type="button" onclick="switchTab('lab')" id="btn-tab-lab" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'lab') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">biotech</span>
            Lab Diagnostics (<?php echo count($labOrders); ?>)
        </button>
        <button type="button" onclick="switchTab('prescriptions')" id="btn-tab-prescriptions" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'prescriptions') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">prescriptions</span>
            Prescriptions (<?php echo count($prescriptions); ?>)
        </button>
        <button type="button" onclick="switchTab('followups')" id="btn-tab-followups" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'followups') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">calendar_month</span>
            Follow-up Appointments (<?php echo count($followUps); ?>)
        </button>
        <button type="button" onclick="switchTab('vitals')" id="btn-tab-vitals" class="tab-btn px-4 py-2.5 rounded-t-xl text-xs font-bold transition-all flex items-center gap-2 border-b-2 border-transparent <?php echo ($activeTab === 'vitals') ? 'active' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container'; ?> cursor-pointer">
            <span class="material-symbols-outlined text-[18px]">vital_signs</span>
            Vitals Progression (<?php echo count($vitalsHistory); ?>)
        </button>
    </div>

    <!-- ==================== TAB 1: CLINICAL TIMELINE ==================== -->
    <div id="tab-content-timeline" class="tab-content <?php echo ($activeTab === 'timeline') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">timeline</span>
                        Complete Patient Journey (Safarka Bukaanka ee Isbitaalka)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Chronological medical log of all doctor encounters, lab tests, prescriptions, and follow-ups.</p>
                </div>
            </div>

            <?php
            // Build unified chronological events array
            $events = [];
            foreach ($consultations as $c) {
                $events[] = [
                    'type'     => 'consultation',
                    'date'     => $c['created_at'],
                    'title'    => 'Doctor Consultation (' . $c['consultation_number'] . ')',
                    'subtitle' => 'Attending: ' . $c['doctor_name'] . ' (' . ($c['doctor_title'] ?: 'Doctor') . ')',
                    'details'  => $c['assessment_diagnosis'],
                    'notes'    => $c['treatment_plan'],
                    'extra'    => !empty($c['follow_up_date']) ? 'Follow-up Date: ' . date('M d, Y', strtotime($c['follow_up_date'])) : null,
                    'icon'     => 'stethoscope',
                    'color'    => 'text-primary bg-primary/10 border-primary/30',
                ];
            }
            foreach ($labOrders as $l) {
                $events[] = [
                    'type'     => 'lab',
                    'date'     => $l['completed_at'] ?: $l['ordered_at'],
                    'title'    => 'Laboratory Order (' . $l['order_number'] . ') - ' . $l['test_name'],
                    'subtitle' => 'Ordered by: ' . ($l['ordered_by_name'] ?: 'Doctor') . ' • Status: ' . strtoupper($l['status']),
                    'details'  => $l['results'] ?: 'Results pending specimen analysis',
                    'notes'    => $l['lab_notes'],
                    'extra'    => null,
                    'icon'     => 'biotech',
                    'color'    => 'text-secondary bg-secondary/10 border-secondary/30',
                ];
            }
            foreach ($prescriptions as $r) {
                $events[] = [
                    'type'     => 'prescription',
                    'date'     => $r['created_at'],
                    'title'    => 'Prescription Order (' . $r['rx_number'] . ')',
                    'subtitle' => 'Prescribed by: ' . $r['doctor_name'] . ' • Status: ' . strtoupper(str_replace('_', ' ', $r['status'])),
                    'details'  => $r['med_names'] ?: 'Prescribed pharmaceutical drugs',
                    'notes'    => $r['pharmacist_notes'],
                    'extra'    => null,
                    'icon'     => 'medication',
                    'color'    => 'text-amber-700 dark:text-amber-400 bg-amber-500/10 border-amber-500/30',
                ];
            }
            foreach ($vitalsHistory as $v) {
                $bp = ($v['systolic'] && $v['diastolic']) ? "{$v['systolic']}/{$v['diastolic']} mmHg" : 'N/A';
                $events[] = [
                    'type'     => 'vitals',
                    'date'     => $v['recorded_at'],
                    'title'    => 'Triage & Physical Vitals Check',
                    'subtitle' => 'Recorded by: ' . ($v['recorded_by_name'] ?: 'Clinical Nurse') . ' • Triage: ' . ucfirst($v['triage_level']),
                    'details'  => "BP: {$bp} • Pulse: " . ($v['heart_rate'] ? "{$v['heart_rate']} bpm" : 'N/A') . " • Temp: " . ($v['temperature'] ? "{$v['temperature']}°C" : 'N/A') . " • SpO2: " . ($v['spo2_oxygen'] ? "{$v['spo2_oxygen']}%" : 'N/A'),
                    'notes'    => $v['chief_complaint'],
                    'extra'    => null,
                    'icon'     => 'vital_signs',
                    'color'    => 'text-emerald-700 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/30',
                ];
            }

            // Sort descending by date
            usort($events, function ($a, $b) {
                return strtotime($b['date']) <=> strtotime($a['date']);
            });
            ?>

            <?php if (empty($events)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">history_toggle_off</span>
                    <p>No historical clinical encounters recorded yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="relative pl-6 sm:pl-8 border-l-2 border-outline-variant space-y-6 ml-3 sm:ml-4 py-2">
                    <?php foreach ($events as $ev): ?>
                        <div class="relative">
                            <!-- Icon node -->
                            <div class="absolute -left-[35px] sm:-left-[43px] top-0 w-8 h-8 rounded-full border <?php echo $ev['color']; ?> flex items-center justify-center shadow-xs bg-surface">
                                <span class="material-symbols-outlined text-[16px]"><?php echo $ev['icon']; ?></span>
                            </div>

                            <!-- Card content -->
                            <div class="p-3.5 sm:p-4 rounded-xl bg-surface-container-lowest border border-outline-variant shadow-xs">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-1 mb-1">
                                    <h4 class="font-bold text-xs text-on-surface"><?php echo e($ev['title']); ?></h4>
                                    <span class="text-[11px] font-mono text-on-surface-variant font-medium"><?php echo date('M d, Y • g:i A', strtotime($ev['date'])); ?></span>
                                </div>
                                <p class="text-[11px] text-on-surface-variant font-medium mb-1.5"><?php echo e($ev['subtitle']); ?></p>

                                <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface font-semibold">
                                    <?php echo nl2br(e($ev['details'])); ?>
                                </div>

                                <?php if (!empty($ev['notes'])): ?>
                                    <p class="text-[11px] text-on-surface-variant mt-1.5 italic">
                                        Note / Instructions: <?php echo e($ev['notes']); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($ev['extra'])): ?>
                                    <div class="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-primary-fixed/30 border border-primary/30 text-xs text-on-primary-fixed-variant font-bold">
                                        <span class="material-symbols-outlined text-[14px]">event</span>
                                        <?php echo e($ev['extra']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 2: DOCTOR CONSULTATIONS ==================== -->
    <div id="tab-content-consultations" class="tab-content <?php echo ($activeTab === 'consultations') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">stethoscope</span>
                        Doctor Consultations &amp; SOAP Documentation (<?php echo count($consultations); ?>)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Full clinical diagnoses, physical exam notes, treatment plans, and follow-up schedules.</p>
                </div>
            </div>

            <?php if (empty($consultations)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">clinical_notes</span>
                    <p>No physician consultations recorded yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($consultations as $cns): ?>
                        <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant shadow-xs space-y-3">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-2.5 border-b border-outline-variant">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-primary"><?php echo e($cns['consultation_number']); ?></span>
                                        <span class="bg-primary/10 text-primary text-[10px] font-bold px-2 py-0.5 rounded-full uppercase">
                                            <?php echo e($cns['status']); ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-on-surface font-semibold mt-0.5">
                                        Attending Doctor: <strong><?php echo e($cns['doctor_name']); ?></strong> (<?php echo e($cns['doctor_title'] ?: 'Physician'); ?>)
                                    </p>
                                </div>
                                <span class="text-xs font-mono text-on-surface-variant">
                                    <?php echo date('F d, Y • g:i A', strtotime($cns['created_at'])); ?>
                                </span>
                            </div>

                            <!-- Diagnoses -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                <div class="p-2.5 rounded-lg bg-surface border border-outline-variant">
                                    <span class="text-[10px] text-on-surface-variant uppercase font-bold block">Primary Diagnosis:</span>
                                    <p class="font-bold text-sm text-primary mt-0.5"><?php echo e($cns['assessment_diagnosis']); ?></p>
                                </div>
                                <div class="p-2.5 rounded-lg bg-surface border border-outline-variant">
                                    <span class="text-[10px] text-on-surface-variant uppercase font-bold block">Secondary / Differential Diagnosis:</span>
                                    <p class="font-semibold text-xs text-on-surface mt-0.5"><?php echo e($cns['secondary_diagnosis'] ?: 'None documented'); ?></p>
                                </div>
                            </div>

                            <!-- Clinical SOAP Notes -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                <?php if (!empty($cns['subjective_notes'])): ?>
                                    <div class="p-3 rounded-lg bg-surface border border-outline-variant">
                                        <span class="text-[10px] text-on-surface-variant uppercase font-bold block mb-1">Subjective (Symptoms &amp; Complaint):</span>
                                        <p class="text-on-surface"><?php echo nl2br(e($cns['subjective_notes'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($cns['objective_findings'])): ?>
                                    <div class="p-3 rounded-lg bg-surface border border-outline-variant">
                                        <span class="text-[10px] text-on-surface-variant uppercase font-bold block mb-1">Objective (Exam &amp; Findings):</span>
                                        <p class="text-on-surface"><?php echo nl2br(e($cns['objective_findings'])); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Treatment Plan & Follow-up -->
                            <div class="p-3 rounded-lg bg-surface border border-outline-variant text-xs">
                                <span class="text-[10px] text-secondary uppercase font-bold block mb-1">Care &amp; Treatment Plan:</span>
                                <p class="text-on-surface font-medium"><?php echo nl2br(e($cns['treatment_plan'] ?: 'Routine supportive medical care.')); ?></p>

                                <?php if (!empty($cns['follow_up_date'])): ?>
                                    <?php 
                                        $fuTimestamp = strtotime($cns['follow_up_date']);
                                        $daysDiff = (int)round(($fuTimestamp - strtotime(date('Y-m-d'))) / 86400);
                                    ?>
                                    <div class="mt-3 pt-2 border-t border-outline-variant flex items-center justify-between">
                                        <div class="flex items-center gap-1.5 font-bold text-xs text-primary">
                                            <span class="material-symbols-outlined text-[16px]">calendar_clock</span>
                                            Scheduled Follow-up: <?php echo date('M d, Y', $fuTimestamp); ?>
                                        </div>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo ($daysDiff === 0) ? 'bg-emerald-600 text-white animate-pulse' : (($daysDiff > 0) ? 'bg-primary-fixed text-on-primary-fixed' : 'bg-surface-container text-on-surface-variant'); ?>">
                                            <?php echo ($daysDiff === 0) ? 'Due Today' : (($daysDiff > 0) ? "In {$daysDiff} days" : 'Past Appointment'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 3: LABORATORY & DIAGNOSTICS ==================== -->
    <div id="tab-content-lab" class="tab-content <?php echo ($activeTab === 'lab') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary text-[20px]">biotech</span>
                        Laboratory Orders &amp; Verified Results (<?php echo count($labOrders); ?>)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Pathology test history, specimen tracking, diagnostic findings, and verified laboratory notes.</p>
                </div>
            </div>

            <?php if (empty($labOrders)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">science</span>
                    <p>No laboratory investigations recorded yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($labOrders as $ord): ?>
                        <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant shadow-xs space-y-3">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-2 border-b border-outline-variant">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-primary"><?php echo e($ord['order_number']); ?></span>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase <?php echo ($ord['status'] === 'completed') ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-amber-500/15 text-amber-700 dark:text-amber-300'; ?>">
                                            <?php echo str_replace('_', ' ', $ord['status']); ?>
                                        </span>
                                        <?php if (!empty($ord['priority']) && $ord['priority'] !== 'routine'): ?>
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-error-container text-on-error-container uppercase">
                                                <?php echo e($ord['priority']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 class="font-bold text-sm text-on-surface mt-1"><?php echo e($ord['test_name']); ?></h4>
                                    <p class="text-[11px] text-on-surface-variant">
                                        Ordered by: <?php echo e($ord['ordered_by_name'] ?: 'Attending Physician'); ?> • Specimen: <?php echo e($ord['specimen_type'] ?: 'Standard Specimen'); ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs font-mono text-on-surface font-bold">$<?php echo number_format((float)$ord['test_price'], 2); ?></p>
                                    <p class="text-[10px] text-on-surface-variant font-mono"><?php echo date('M d, Y', strtotime($ord['ordered_at'])); ?></p>
                                </div>
                            </div>

                            <!-- Verified Findings -->
                            <?php if ($ord['status'] === 'completed'): ?>
                                <div class="p-3 rounded-lg bg-secondary-fixed/20 border border-secondary/30 text-xs space-y-1.5">
                                    <div class="flex justify-between items-center font-bold text-secondary text-[11px]">
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[16px]">verified</span>
                                            Verified Findings &amp; Pathologist Interpretation:
                                        </span>
                                        <?php if (!empty($ord['completed_at'])): ?>
                                            <span class="font-mono text-[10px] text-on-surface-variant"><?php echo date('M d, Y • g:i A', strtotime($ord['completed_at'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-on-surface font-medium leading-relaxed"><?php echo nl2br(e($ord['results'])); ?></p>

                                    <?php if (!empty($ord['lab_notes'])): ?>
                                        <div class="mt-2 p-2 rounded bg-surface border border-outline-variant font-mono text-[11px] text-on-surface-variant">
                                            <span class="block text-[9px] uppercase font-bold text-on-surface-variant mb-0.5">Numerical Markers / Values:</span>
                                            <?php echo nl2br(e($ord['lab_notes'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface-variant flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[16px] text-amber-500">pending</span>
                                    <span>Sample processing in progress or awaiting cashier payment confirmation.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 4: PHARMACY PRESCRIPTIONS ==================== -->
    <div id="tab-content-prescriptions" class="tab-content <?php echo ($activeTab === 'prescriptions') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">prescriptions</span>
                        Prescription History &amp; Medications Dispensed (<?php echo count($prescriptions); ?>)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Electronic prescriptions, drug dosages, and retail pharmacy dispensing records.</p>
                </div>
            </div>

            <?php if (empty($prescriptions)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">medication</span>
                    <p>No prescription history recorded yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($prescriptions as $rx): ?>
                        <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant shadow-xs space-y-2.5">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-2 border-b border-outline-variant">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-primary"><?php echo e($rx['rx_number']); ?></span>
                                        <?php 
                                            $rxStatus = $rx['status'];
                                            if ($rxStatus === 'external_purchase') {
                                                $statusBadge = 'bg-amber-500/15 text-amber-900 dark:text-amber-200 border border-amber-500/40';
                                                $statusText = '🛒 Bannaanka Ayuu Ka Gatay (External Purchase)';
                                            } elseif ($rxStatus === 'dispensed') {
                                                $statusBadge = 'bg-emerald-600 text-white';
                                                $statusText = '✓ Dispensed (Hospital Pharmacy)';
                                            } elseif ($rxStatus === 'partially_dispensed') {
                                                $statusBadge = 'bg-tertiary-fixed text-on-tertiary-fixed font-bold';
                                                $statusText = 'Partial Dispense';
                                            } else {
                                                $statusBadge = 'bg-primary-container text-on-primary-container';
                                                $statusText = 'Pending Dispense';
                                            }
                                        ?>
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $statusBadge; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant mt-0.5">
                                        Prescribed by: <strong class="text-on-surface"><?php echo e($rx['doctor_name']); ?></strong> • <?php echo date('M d, Y • g:i A', strtotime($rx['created_at'])); ?>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="pharmacy_dispensing_prescription.php?rx_id=<?php echo (int)$rx['id']; ?>" class="px-3 py-1.5 bg-surface border border-outline-variant hover:bg-surface-container rounded-lg text-xs font-semibold text-primary flex items-center gap-1 shadow-xs no-print">
                                        <span class="material-symbols-outlined text-[15px]"><?php echo ($rxStatus === 'external_purchase') ? 'description' : 'point_of_sale'; ?></span>
                                        <?php echo ($rxStatus === 'external_purchase') ? 'Print Prescription Slip' : 'Open in Pharmacy'; ?>
                                    </a>
                                </div>
                            </div>

                            <div class="p-3 rounded-lg bg-surface border border-outline-variant text-xs">
                                <span class="text-[10px] text-on-surface-variant uppercase font-bold block mb-1">Prescribed Drug Regimen:</span>
                                <p class="text-xs text-on-surface font-semibold leading-relaxed"><?php echo e($rx['med_names'] ?: 'Prescription Line Items'); ?></p>
                            </div>

                            <?php if (!empty($rx['pharmacist_notes'])): ?>
                                <p class="text-[11px] <?php echo ($rxStatus === 'external_purchase') ? 'text-amber-800 dark:text-amber-300 font-semibold' : 'text-on-surface-variant italic'; ?> flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[14px]"><?php echo ($rxStatus === 'external_purchase') ? 'storefront' : 'info'; ?></span>
                                    Pharmacist Notes: <?php echo e($rx['pharmacist_notes']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 5: FOLLOW-UP APPOINTMENTS ==================== -->
    <div id="tab-content-followups" class="tab-content <?php echo ($activeTab === 'followups') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">calendar_month</span>
                        Follow-up Appointments Schedule (Ballamaha Dib-u-eegista)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Scheduled return visits, clinician assignments, and appointment completion logs.</p>
                </div>
            </div>

            <?php if (empty($followUps)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">event_busy</span>
                    <p>No follow-up appointments scheduled yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($followUps as $fu): ?>
                        <?php 
                            $status = $fu['follow_up_status'];
                            $isCompleted = ($status === 'completed');
                            $isDueToday  = ($status === 'due_today');
                            $isUpcoming  = ($status === 'upcoming');
                            
                            $cardBorder = $isCompleted ? 'border-emerald-500/30 bg-emerald-500/5' : ($isDueToday ? 'border-emerald-500/50 bg-emerald-500/10' : ($isUpcoming ? 'border-primary/40 bg-primary-fixed/20' : 'border-outline-variant bg-surface-container-lowest'));
                            $badgeClass = $isCompleted ? 'bg-emerald-700 text-white' : ($isDueToday ? 'bg-emerald-600 text-white animate-pulse' : ($isUpcoming ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant'));

                            $isAssignedDoctor = ($currentDoctorId > 0 && (int)($fu['doctor_id'] ?? 0) === $currentDoctorId);
                            $canDoctorConsult = $isAssignedDoctor || (($currentUser['role'] ?? '') === ROLE_SUPERADMIN_ICT);
                        ?>
                        <div class="p-4 rounded-xl border <?php echo $cardBorder; ?> shadow-xs flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-bold text-on-surface font-mono"><?php echo date('l, F d, Y', strtotime($fu['follow_up_date'])); ?></span>
                                    <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full uppercase <?php echo $badgeClass; ?>">
                                        <?php if ($isCompleted): ?>
                                            ✅ COMPLETED (LA FULIYAY)
                                        <?php elseif ($isDueToday): ?>
                                            🔔 DUE TODAY (MAANTA)
                                        <?php elseif ($isUpcoming): ?>
                                            UPCOMING (In <?php echo (int)$fu['days_diff']; ?> days)
                                        <?php else: ?>
                                            PAST APPOINTMENT
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <p class="text-xs text-on-surface font-semibold">
                                    Physician: <strong class="text-primary"><?php echo e($fu['doctor_name']); ?></strong> (<?php echo e($fu['doctor_title'] ?: 'Specialist'); ?>)
                                </p>
                                <p class="text-xs text-on-surface-variant">
                                    Encounter: <span class="font-mono font-bold"><?php echo e($fu['consultation_number']); ?></span> • Diagnosis: <em><?php echo e($fu['assessment_diagnosis']); ?></em>
                                </p>
                                <?php if (!empty($fu['treatment_plan'])): ?>
                                    <p class="text-[11px] text-on-surface-variant italic">
                                        Plan: <?php echo e($fu['treatment_plan']); ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($isCompleted && !empty($fu['follow_up_completed_at'])): ?>
                                    <p class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[13px]">check_circle</span>
                                        Waa la fuliyay: <?php echo date('M d, Y h:i A', strtotime($fu['follow_up_completed_at'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <?php if ($isCompleted): ?>
                                <div class="w-full sm:w-auto px-3 py-1.5 bg-emerald-500/15 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 font-bold text-xs rounded-lg flex items-center justify-center gap-1.5 shrink-0 select-none">
                                    <span class="material-symbols-outlined text-[16px]">task_alt</span>
                                    <span>Follow-up Completed</span>
                                </div>
                            <?php elseif ($isDueToday || $isUpcoming): ?>
                                <?php if (!$isDoctor): ?>
                                    <button type="button" onclick="openQueueModal()" class="w-full sm:w-auto px-3 py-1.5 bg-primary text-on-primary font-bold text-xs rounded-lg shadow-xs hover:bg-primary-container flex items-center justify-center gap-1 cursor-pointer no-print shrink-0">
                                        <span class="material-symbols-outlined text-[15px]">queue</span>
                                        Check-in Patient
                                    </button>
                                <?php else: ?>
                                    <?php if ($canDoctorConsult): ?>
                                        <a href="consultation_michael_chen.php?id=<?php echo $patientId; ?>" class="w-full sm:w-auto px-3 py-1.5 bg-primary text-on-primary font-bold text-xs rounded-lg shadow-xs hover:bg-primary-container flex items-center justify-center gap-1 cursor-pointer no-print shrink-0">
                                            <span class="material-symbols-outlined text-[15px]">stethoscope</span>
                                            Consult Patient
                                        </a>
                                    <?php else: ?>
                                        <?php 
                                            $docDisplayName = trim($fu['doctor_name']);
                                            if (!str_starts_with(strtolower($docDisplayName), 'dr.') && !str_starts_with(strtolower($docDisplayName), 'dr ')) {
                                                $docDisplayName = 'Dr. ' . $docDisplayName;
                                            }
                                        ?>
                                        <div class="w-full sm:w-auto px-3 py-1.5 bg-surface-container border border-outline-variant/60 text-on-surface-variant text-xs rounded-lg flex items-center justify-center gap-1.5 select-none shrink-0" title="Kaliya <?php echo e($docDisplayName); ?> ayaa xaq u leh inuu la kulmo bukaankan maadaama uu isagu qabtay ballanta dib-u-eegista.">
                                            <span class="material-symbols-outlined text-[16px] text-amber-600 dark:text-amber-400">lock</span>
                                            <span class="font-medium">Ballanta: <strong><?php echo e($docDisplayName); ?></strong></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==================== TAB 6: VITALS PROGRESSION ==================== -->
    <div id="tab-content-vitals" class="tab-content <?php echo ($activeTab === 'vitals') ? '' : 'hidden'; ?> space-y-4">
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-sm">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-3 border-b border-outline-variant mb-4">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">vital_signs</span>
                        Vitals History &amp; Physiological Trends (<?php echo count($vitalsHistory); ?>)
                    </h3>
                    <p class="text-xs text-on-surface-variant">Historical triage vitals, blood pressure trends, pulse rate, oxygen saturation, and BMI progression.</p>
                </div>
                <button type="button" onclick="openVitalsModal()" class="px-3 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container flex items-center gap-1 shadow-xs cursor-pointer no-print">
                    <span class="material-symbols-outlined text-[16px]">add</span>
                    + Record Vitals
                </button>
            </div>

            <?php if (empty($vitalsHistory)): ?>
                <div class="py-12 text-center text-on-surface-variant text-xs">
                    <span class="material-symbols-outlined text-4xl text-outline mb-2">vital_signs</span>
                    <p>No triage vitals recorded yet for this patient.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-surface-container border-b border-outline-variant text-[11px] uppercase font-bold text-on-surface-variant">
                                <th class="py-2.5 px-3">Date &amp; Time</th>
                                <th class="py-2.5 px-3">Blood Pressure</th>
                                <th class="py-2.5 px-3">Heart Rate</th>
                                <th class="py-2.5 px-3">Temp (°C)</th>
                                <th class="py-2.5 px-3">SpO2</th>
                                <th class="py-2.5 px-3">Weight / BMI</th>
                                <th class="py-2.5 px-3">Triage Level</th>
                                <th class="py-2.5 px-3">Chief Complaint</th>
                                <th class="py-2.5 px-3">Recorded By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant">
                            <?php foreach ($vitalsHistory as $v): ?>
                                <?php
                                    $isHighBp = (!empty($v['systolic']) && (int)$v['systolic'] >= 140);
                                    $isFever = (!empty($v['temperature']) && (float)$v['temperature'] >= 37.8);
                                    $bpText = ($v['systolic'] && $v['diastolic']) ? "{$v['systolic']} / {$v['diastolic']}" : '--';
                                ?>
                                <tr class="hover:bg-surface-container/50 transition-colors font-medium">
                                    <td class="py-2.5 px-3 font-mono text-on-surface text-[11px] whitespace-nowrap">
                                        <?php echo date('M d, Y • g:i A', strtotime($v['recorded_at'])); ?>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono font-bold <?php echo $isHighBp ? 'text-error font-extrabold' : 'text-on-surface'; ?>">
                                        <?php echo $bpText; ?> <span class="text-[9px] font-normal text-on-surface-variant">mmHg</span>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono">
                                        <?php echo $v['heart_rate'] ? "{$v['heart_rate']} bpm" : '--'; ?>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono <?php echo $isFever ? 'text-error font-bold' : ''; ?>">
                                        <?php echo $v['temperature'] ? "{$v['temperature']} °C" : '--'; ?>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono text-secondary font-bold">
                                        <?php echo $v['spo2_oxygen'] ? "{$v['spo2_oxygen']}%" : '--'; ?>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono">
                                        <?php echo $v['weight_kg'] ? "{$v['weight_kg']} kg" : '--'; ?>
                                        <?php if (!empty($v['bmi'])): ?>
                                            <span class="text-[10px] text-on-surface-variant">(BMI: <?php echo $v['bmi']; ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full capitalize <?php echo ($v['triage_level'] === 'emergency') ? 'bg-error text-white' : (($v['triage_level'] === 'urgent') ? 'bg-amber-500/15 text-amber-700' : 'bg-surface-container text-on-surface-variant'); ?>">
                                            <?php echo e($v['triage_level']); ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 max-w-[200px] truncate text-on-surface" title="<?php echo e($v['chief_complaint']); ?>">
                                        <?php echo e($v['chief_complaint'] ?: '--'); ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-[11px] text-on-surface-variant">
                                        <?php echo e($v['recorded_by_name'] ?: 'Clinical Nurse'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- MODAL 1: Record Vitals -->
<div id="vitals-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4 no-print">
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
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">Oxygen (SpO2 %)</label>
                    <input name="spo2_oxygen" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="98" type="number">
                </div>
                <div>
                    <label class="block text-[11px] text-on-surface-variant mb-0.5">Weight (kg)</label>
                    <input name="weight_kg" step="0.5" class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="70" type="number">
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
                    <option value="emergency">Emergency Priority</option>
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
<div id="queue-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4 no-print">
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
                        <option value="<?php echo (int)$doc['id']; ?>" <?php echo ($nextFu && $nextFu['doctor_name'] === $doc['full_name']) ? 'selected' : ''; ?>>
                            <?php echo e($doc['full_name']); ?>  |  <?php echo e($doc['professional_title'] ?: 'General Practice'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Department & Priority -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Department</label>
                        <select name="department" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                            <option value="General OPD">General OPD</option>
                            <option value="Cardiology OPD">Cardiology OPD</option>
                            <option value="Neurology OPD">Neurology OPD</option>
                            <option value="Endocrinology OPD">Endocrinology OPD</option>
                            <option value="Pediatrics OPD">Pediatrics OPD</option>
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
    function switchTab(tabId) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        // Deactivate all buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.classList.add('text-on-surface-variant');
        });

        // Show selected tab
        const target = document.getElementById('tab-content-' + tabId);
        if (target) {
            target.classList.remove('hidden');
        }

        // Activate button
        const btn = document.getElementById('btn-tab-timeline' === 'btn-tab-' + tabId ? 'btn-tab-timeline' : 'btn-tab-' + tabId);
        if (btn) {
            btn.classList.add('active');
            btn.classList.remove('text-on-surface-variant');
        }

        // Update URL hash without reload
        if (history.replaceState) {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            history.replaceState(null, '', url);
        }
    }

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
