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

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_DOCTOR]);

$currentUser = getCurrentUser();
$doctorId = (int)($currentUser['id'] ?? 1);
$doctorName = $currentUser['full_name'] ?? 'Attending Doctor';
$doctorTitle = $currentUser['professional_title'] ?? 'General Practice';

// Retrieve real active queue (waiting, in_consultation, in_lab, lab_completed)
$waitingQueue = PatientOperation::getQueue([
    'status'    => 'active',
    'doctor_id' => ($currentUser['role'] === 'doctor') ? $doctorId : null,
]);

$stats = ConsultationOperation::getDoctorDashboardStats($doctorId);

$pageTitle = 'Doctor Dashboard - MedCore Systems';
$headerTitle = 'MedCore Management - Clinical View';
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
            <?php if (!empty($waitingQueue)): ?>
                <a href="consultation_michael_chen.php?id=<?php echo (int)$waitingQueue[0]['patient_id']; ?>&queue_id=<?php echo (int)$waitingQueue[0]['id']; ?>" class="flex-1 sm:flex-none flex items-center justify-center gap-xs px-md py-sm bg-primary text-on-primary font-label-md text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold">
                    <span class="material-symbols-outlined text-[18px]">play_arrow</span>
                    Call Next Patient (<?php echo e($waitingQueue[0]['token_number']); ?>)
                </a>
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
                <span class="font-body-sm text-xs text-on-surface-variant mb-1">In waiting lobby</span>
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
                <span class="font-body-sm text-xs text-on-surface-variant mb-1">Active encounter</span>
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
                <span class="font-body-sm text-xs text-secondary mb-1 font-semibold">100% Documented</span>
            </div>
        </div>
    </div>

    <!-- Active Consultation Queue Table -->
    <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
        <div class="p-md border-b border-outline-variant flex justify-between items-center bg-surface-bright">
            <h3 class="font-headline-sm text-base sm:text-headline-sm text-on-surface font-bold flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">patient_list</span>
                Active Patient Waiting Queue
            </h3>
            <a href="queue_management.php" class="font-label-md text-xs sm:text-label-md text-primary hover:underline font-semibold">View All Departments</a>
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
                                <p class="font-semibold text-on-surface">No patients currently waiting in your consultation queue.</p>
                                <p class="text-xs text-on-surface-variant mt-1">When reception check-in assigns patients to you, they will appear here live.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($waitingQueue as $q): ?>
                            <?php
                                $isUrgent = in_array($q['priority'], ['urgent', 'emergency'], true);
                                $hasVitals = !empty($q['systolic']);
                                $isLabReady = ($q['status'] === 'lab_completed');
                                $isInLab = ($q['status'] === 'in_lab');
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors group">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-code-md font-bold text-sm text-primary bg-primary-fixed/40 px-2 py-0.5 rounded">
                                            <?php echo e($q['token_number']); ?>
                                        </span>
                                        <?php if ($isUrgent): ?>
                                            <span class="text-xs uppercase font-bold text-error bg-error-container/60 px-2 py-0.5 rounded-full">
                                                <?php echo e($q['priority']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($isLabReady): ?>
                                            <span class="text-[10px] uppercase font-bold text-secondary bg-secondary-fixed/50 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                                <span class="material-symbols-outlined text-[13px]">verified</span> Lab Results Ready
                                            </span>
                                        <?php elseif ($isInLab): ?>
                                            <span class="text-[10px] uppercase font-bold text-primary bg-primary-fixed/40 px-2 py-0.5 rounded-full">
                                                In Lab
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
                                    <a href="consultation_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>&queue_id=<?php echo (int)$q['id']; ?>" class="inline-flex items-center gap-1 px-3.5 py-1.5 <?php echo $isLabReady ? 'bg-secondary hover:bg-on-secondary-container text-on-secondary' : 'bg-primary hover:bg-primary-container text-on-primary'; ?> font-bold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                        <span class="material-symbols-outlined text-[16px]"><?php echo $isLabReady ? 'assignment_turned_in' : 'stethoscope'; ?></span>
                                        <?php echo $isLabReady ? 'Review Lab & Prescribe' : ($isInLab ? 'View Chart' : 'Start Consult'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'doctor_queue',
                targetSelector: '#doctor-queue-tbody',
                params: {
                    <?php if (($currentUser['role'] ?? '') === 'doctor'): ?>
                    doctor_id: '<?php echo (int)$doctorId; ?>'
                    <?php endif; ?>
                },
                intervalMs: 3000,
                notifyOnNew: true
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
