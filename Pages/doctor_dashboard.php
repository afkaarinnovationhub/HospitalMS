<?php
/**
 * MedCore Systems - Doctor's Clinical Dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../OPERATIONS/DoctorOperation.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/../CONTROLS/PatientController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_DOCTOR]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'call_patient') {
        PatientController::handleCallPatient($_POST);
    } elseif ($action === 'update_status') {
        PatientController::handleUpdateQueueStatus($_POST);
    }
}

$currentUser = getCurrentUser();
$doctorId = (int)($currentUser['id'] ?? 1);
$doctorName = $currentUser['full_name'] ?? 'Attending Doctor';
$doctorTitle = $currentUser['professional_title'] ?? 'General Practice';

$searchQuery = sanitizeString($_GET['search'] ?? '');

// Retrieve real active queue (waiting, in_consultation, in_lab, lab_completed)
$waitingQueue = PatientOperation::getQueue([
    'status'    => 'active',
    'doctor_id' => ($currentUser['role'] === 'doctor') ? $doctorId : null,
    'search'    => $searchQuery ?: null,
]);

$stats = ConsultationOperation::getDoctorDashboardStats($doctorId);

$pageTitle = 'Doctor Dashboard - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Clinical View';
$activePage = 'doctor_dashboard';

include __DIR__ . '/../components/header.php';
?>

<!-- Scrollable Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-md mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Doctor's Clinical Dashboard</h2>
            <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($doctorName); ?></strong> • <?php echo e($doctorTitle); ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-sm w-full sm:w-auto">
            <a href="queue_management.php" class="flex-1 sm:flex-none flex items-center justify-center gap-xs px-md py-sm border border-primary text-primary font-label-md text-label-md rounded-lg hover:bg-surface-container-low transition-colors font-medium">
                <span class="material-symbols-outlined text-[18px]">group</span>
                Full Queue
            </a>
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
                <form method="POST" action="doctor_dashboard.php" class="flex-1 sm:flex-none">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="call_patient">
                    <input type="hidden" name="queue_id" value="<?php echo (int)$firstCallable['id']; ?>">
                    <button type="submit" class="w-full flex items-center justify-center gap-xs px-md py-sm bg-primary text-on-primary font-label-md text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                        <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                        Call Next Patient (<?php echo e($firstCallable['token_number']); ?>)
                    </button>
                </form>
            <?php else: ?>
                <a href="consultation_michael_chen.php" class="flex-1 sm:flex-none flex items-center justify-center gap-xs px-md py-sm bg-primary text-on-primary font-label-md text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold">
                    <span class="material-symbols-outlined text-[18px]">stethoscope</span>
                    Open Consultation Room
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Metrics Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md mb-lg sm:mb-xl">
        <!-- Metric 1: Today's Patients Assigned -->
        <div class="bg-surface border border-outline-variant rounded-xl p-md flex flex-col gap-sm shadow-sm">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Today's Assigned</span>
                <span class="material-symbols-outlined text-primary bg-primary-fixed p-xs rounded-full">person</span>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-surface font-bold"><?php echo (int)$stats['total_today']; ?></span>
                <span class="font-body-sm text-xs text-secondary mb-1 flex items-center font-semibold">
                    <span class="material-symbols-outlined text-[16px]">check_circle</span> <?php echo (int)$stats['completed_today']; ?> Done
                </span>
            </div>
        </div>

        <!-- Metric 2: Waiting in Queue -->
        <div class="bg-surface border border-outline-variant rounded-xl p-md flex flex-col gap-sm shadow-sm">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Waiting in Queue</span>
                <span class="material-symbols-outlined text-secondary bg-secondary-fixed p-xs rounded-full">hourglass_top</span>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-surface font-bold"><?php echo (int)$stats['waiting_patients']; ?></span>
            </div>
        </div>

        <!-- Metric 3: In Consultation -->
        <div class="bg-surface border border-outline-variant rounded-xl p-md flex flex-col gap-sm shadow-sm">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">In Consultation</span>
                <span class="material-symbols-outlined text-tertiary bg-tertiary-fixed p-xs rounded-full">stethoscope</span>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-surface font-bold"><?php echo (int)$stats['in_consultation']; ?></span>
            </div>
        </div>

        <!-- Metric 4: Completed Consultations -->
        <div class="bg-surface border border-outline-variant rounded-xl p-md flex flex-col gap-sm shadow-sm">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Completed Today</span>
                <span class="material-symbols-outlined text-secondary bg-secondary-fixed p-xs rounded-full">task_alt</span>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-display-lg text-secondary font-bold"><?php echo (int)$stats['completed_today']; ?></span>
            </div>
        </div>
    </div>

    <!-- Active Consultation Queue Table -->
    <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
        <div class="p-md border-b border-outline-variant flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 bg-surface-bright">
            <div class="flex items-center gap-2">
                <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">patient_list</span>
                    Active Patient Waiting Queue
                </h3>
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-between sm:justify-end">
                <!-- Instant Search Bar -->
                <div class="relative flex-1 sm:w-60 max-w-xs">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[17px] pointer-events-none">search</span>
                    <input type="text" 
                           id="doc-queue-search-input" 
                           name="search" 
                           value="<?php echo e($searchQuery); ?>" 
                           placeholder="Search token, name, MRN..." 
                           autocomplete="off"
                           class="w-full bg-surface border border-outline-variant rounded-lg pl-8 pr-7 py-1 text-xs text-on-surface focus:border-primary outline-none transition-colors">
                    <button type="button" 
                            id="doc-queue-search-clear" 
                            onclick="clearDocQueueSearch()" 
                            class="<?php echo empty($searchQuery) ? 'hidden ' : ''; ?>absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface p-0.5 cursor-pointer" 
                            title="Clear search">
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </button>
                </div>
                <a href="queue_management.php" class="font-label-md text-xs text-primary hover:underline font-semibold shrink-0">Full Queue</a>
            </div>
        </div>
        <div class="flex-1 overflow-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[650px]">
                <thead class="bg-surface-container-low sticky top-0 font-label-md text-xs text-on-surface-variant border-b border-outline-variant z-10">
                    <tr>
                        <th class="py-3 px-4 font-semibold">Token / Priority</th>
                        <th class="py-3 px-4 font-semibold">Patient Name &amp; MRN</th>
                        <th class="py-3 px-4 font-semibold">Age / Gender</th>
                        <th class="py-3 px-4 font-semibold">Chief Complaint / Triage Vitals</th>
                        <th class="py-3 px-4 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody id="doctor-queue-tbody" class="font-body-sm text-xs sm:text-body-sm divide-y divide-outline-variant">
                    <?php if (empty($waitingQueue)): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl mb-1 text-secondary">check_circle</span>
                                <p class="font-semibold text-on-surface">No patients currently waiting in queue.</p>
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
                            <tr class="hover:bg-surface-container-low transition-colors group <?php echo $isInConsult ? 'bg-emerald-500/5' : ($isOnHold ? 'bg-amber-500/5' : ''); ?>">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-code-md font-bold text-sm px-2 py-0.5 rounded <?php echo $isInConsult ? 'bg-emerald-500/20 text-emerald-800 dark:text-emerald-300' : 'bg-primary-fixed/40 text-primary'; ?>">
                                            <?php echo e($q['token_number']); ?>
                                        </span>
                                        <?php if ($isUrgent): ?>
                                            <span class="text-xs uppercase font-bold text-error bg-error-container/60 px-2 py-0.5 rounded-full">
                                                <?php echo e($q['priority']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($isInConsult): ?>
                                            <span class="text-[10px] uppercase font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[13px]">stethoscope</span> In Room
                                            </span>
                                        <?php elseif ($isOnHold): ?>
                                            <span class="text-[10px] uppercase font-bold text-amber-800 dark:text-amber-300 bg-amber-500/15 border border-amber-500/30 px-2 py-0.5 rounded-full flex items-center gap-0.5" title="Patient was called but was temporarily absent">
                                                <span class="material-symbols-outlined text-[13px]">pause_circle</span> On Hold
                                            </span>
                                        <?php elseif ($isLabReady): ?>
                                            <span class="text-[10px] uppercase font-bold text-secondary bg-secondary-fixed/50 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[13px]">verified</span> Lab Ready
                                            </span>
                                        <?php elseif ($isInLab): ?>
                                            <span class="text-[10px] uppercase font-bold text-primary bg-primary-fixed/40 px-2 py-0.5 rounded-full">
                                                In Lab
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] uppercase font-semibold text-blue-700 dark:text-blue-300 bg-blue-500/10 px-2 py-0.5 rounded-full">
                                                Waiting
                                            </span>
                                        <?php endif; ?>
                                        <?php 
                                            $isBillingPaid = ($q['billing_status'] === 'paid' || ($q['invoice_status'] ?? '') === 'paid' || ($q['invoice_status'] ?? '') === 'partial' || (float)($q['current_doctor_fee'] ?? 10) <= 0.0);
                                        ?>
                                        <?php if ($isBillingPaid): ?>
                                            <span class="text-[10px] uppercase font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded-full" title="Consultation fee cleared at cashier">
                                                ✓ Paid
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] uppercase font-bold text-amber-800 dark:text-amber-300 bg-amber-500/15 border border-amber-500/30 px-1.5 py-0.5 rounded-full flex items-center gap-0.5" title="Consultation fee unpaid at cashier">
                                                <span class="material-symbols-outlined text-[12px]">lock</span> Unpaid
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($q['invoice_due']) && (float)$q['invoice_due'] > 0 && ($q['invoice_status'] ?? '') === 'partial'): ?>
                                            <span class="text-[10px] font-bold text-amber-800 dark:text-amber-300 bg-amber-500/15 border border-amber-500/30 px-1.5 py-0.5 rounded-full" title="Consultation fee balance due: $<?php echo number_format((float)$q['invoice_due'], 2); ?>">
                                                Due: $<?php echo number_format((float)$q['invoice_due'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-bold text-on-surface"><?php echo e($q['patient_name']); ?></div>
                                    <div class="font-code-md text-[11px] text-on-surface-variant"><?php echo e($q['mrn']); ?></div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="font-medium"><?php echo (int)$q['age']; ?> yrs</span>
                                    <span class="text-on-surface-variant capitalize text-[11px]"> • <?php echo e($q['gender']); ?></span>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if ($hasVitals): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant text-[11px] font-semibold">
                                            BP <?php echo (int)$q['systolic']; ?>/<?php echo (int)$q['diastolic']; ?> • <?php echo (float)$q['temperature']; ?>°C
                                        </span>
                                    <?php else: ?>
                                        <span class="text-on-surface-variant text-xs"><?php echo e($q['chief_complaint'] ?: 'Routine OPD visit'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <?php if ($isInConsult): ?>
                                            <a href="consultation_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>&queue_id=<?php echo (int)$q['id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-[15px]">stethoscope</span>
                                                Open Chart
                                            </a>
                                            <button type="button" 
                                                    onclick="openHoldPatientModal(<?php echo (int)$q['id']; ?>, '<?php echo e(addslashes($q['patient_name'])); ?>', 'doctor_dashboard.php')" 
                                                    class="p-1.5 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/40 text-amber-800 dark:text-amber-300 font-bold text-xs rounded-lg transition-colors cursor-pointer" 
                                                    title="Put On Hold if absent">
                                                <span class="material-symbols-outlined text-[16px]">pause_circle</span>
                                            </button>
                                        <?php elseif ($isOnHold): ?>
                                            <form method="POST" action="doctor_dashboard.php" class="inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="call_patient">
                                                <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                    <span class="material-symbols-outlined text-[15px]">replay</span>
                                                    Recall
                                                </button>
                                            </form>
                                        <?php elseif ($isLabReady): ?>
                                            <form method="POST" action="doctor_dashboard.php" class="inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="call_patient">
                                                <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-secondary hover:bg-on-secondary-container text-on-secondary font-bold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                    <span class="material-symbols-outlined text-[15px]">assignment_turned_in</span>
                                                    Review Lab
                                                </button>
                                            </form>
                                        <?php elseif ($isInLab): ?>
                                            <a href="consultation_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>&queue_id=<?php echo (int)$q['id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold rounded-lg text-xs transition-colors">
                                                <span class="material-symbols-outlined text-[15px]">visibility</span>
                                                View Order
                                            </a>
                                        <?php else: ?>
                                            <?php if ($isBillingPaid): ?>
                                                <form method="POST" action="doctor_dashboard.php" class="inline">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="call_patient">
                                                    <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                                    <button type="submit" class="inline-flex items-center gap-1 px-3.5 py-1.5 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                        <span class="material-symbols-outlined text-[15px]">play_arrow</span>
                                                        Call In
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" disabled class="inline-flex items-center gap-1 px-3 py-1.5 bg-surface-container text-on-surface-variant/50 border border-outline-variant font-bold rounded-lg text-xs cursor-not-allowed" title="Bukaankan lacagtiisa consultation-ka weli lama bixin. Fadlan bukaanka u dir Cashier-ka.">
                                                    <span class="material-symbols-outlined text-[15px]">lock</span>
                                                    Locked
                                                </button>
                                            <?php endif; ?>
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
</main>

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

        <form id="hold-patient-form" method="POST" action="doctor_dashboard.php" class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" id="hold_queue_id" name="queue_id" value="">
            <input type="hidden" name="status" value="on_hold">
            <input type="hidden" id="hold_redirect" name="redirect" value="doctor_dashboard.php">

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

    function applyDocQueueFilter() {
        const input = document.getElementById('doc-queue-search-input');
        const clearBtn = document.getElementById('doc-queue-search-clear');
        if (!input) return;
        const query = input.value.trim().toLowerCase();
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', query.length === 0);
        }
        const tbody = document.getElementById('doctor-queue-tbody');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr');
        let matched = 0;
        let noMatchTr = document.getElementById('doc-queue-no-match');

        rows.forEach(r => {
            if (r.id === 'doc-queue-no-match') return;
            const text = r.textContent.toLowerCase();
            const show = !query || text.includes(query);
            r.style.display = show ? '' : 'none';
            if (show) matched++;
        });

        const badge = document.getElementById('doc-queue-badge');
        if (badge) {
            badge.textContent = `${matched} Patient${matched === 1 ? '' : 's'}`;
        }

        if (matched === 0 && query.length > 0) {
            if (!noMatchTr) {
                noMatchTr = document.createElement('tr');
                noMatchTr.id = 'doc-queue-no-match';
                noMatchTr.innerHTML = `
                    <td colspan="5" class="py-8 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-3xl mb-1 text-outline">person_search</span>
                        <p class="font-semibold text-on-surface">Wax bukaan ah oo ku habboon baaritaankaaga lama helin.</p>
                        <p class="text-xs text-on-surface-variant mt-0.5">No patients in your consultation queue matching "${query.replace(/</g, '&lt;')}".</p>
                    </td>
                `;
                tbody.appendChild(noMatchTr);
            } else {
                noMatchTr.style.display = '';
            }
        } else if (noMatchTr) {
            noMatchTr.style.display = 'none';
        }
    }

    function clearDocQueueSearch() {
        const input = document.getElementById('doc-queue-search-input');
        if (!input) return;
        input.value = '';
        if (window.location.search.includes('search=')) {
            window.location.href = 'doctor_dashboard.php';
        } else {
            applyDocQueueFilter();
            input.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const docSearchInput = document.getElementById('doc-queue-search-input');
        if (docSearchInput) {
            docSearchInput.addEventListener('input', applyDocQueueFilter);
        }

        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'doctor_queue',
                targetSelector: '#doctor-queue-tbody',
                params: {
                    <?php if (($currentUser['role'] ?? '') === 'doctor'): ?>
                    doctor_id: '<?php echo (int)$doctorId; ?>',
                    <?php endif; ?>
                    search: '<?php echo e($searchQuery); ?>'
                },
                intervalMs: 3000,
                notifyOnNew: true,
                onUpdate: function() {
                    applyDocQueueFilter();
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
