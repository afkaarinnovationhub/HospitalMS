<?php
/**
 * MedCore Systems - Real-Time Live Sync & State Streaming API
 * Provides ultra-fast, lightweight background polling for instantaneous dashboard updates
 * without requiring manual browser page refreshes.
 */

declare(strict_types=1);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/../OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';

initSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['status' => 'unauthenticated', 'changed' => false]);
    if (!defined('HPMS_TESTING')) { exit; }
}

$currentUser = getCurrentUser();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserRole = $currentUser['role'] ?? 'Staff';

$module = sanitizeString($_GET['module'] ?? '');
$clientChecksum = sanitizeString($_GET['checksum'] ?? '');

$pdo = getDBConnection();

try {
    switch ($module) {
        // =========================================================================
        // 1. DOCTOR DASHBOARD & CLINICAL QUEUE
        // =========================================================================
        case 'doctor_queue':
            $doctorId = ($currentUserRole === ROLE_DOCTOR) ? $currentUserId : (!empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null);
            $searchQuery = sanitizeString($_GET['search'] ?? '');
            
            // Checksum query (accurately tracks membership changes, doctor reassignments, and statuses)
            if ($doctorId) {
                $stmtSum = $pdo->prepare("
                    SELECT COUNT(*) as cnt, 
                           COALESCE(SUM(id), 0) as sum_id, 
                           COALESCE(MAX(id), 0) as max_id, 
                           COALESCE(GROUP_CONCAT(CONCAT(id, ':', status) ORDER BY id), '') as state_str
                    FROM patient_queues 
                    WHERE (doctor_id = :doc_id OR doctor_id IS NULL) 
                      AND status IN ('waiting', 'in_consultation', 'on_hold', 'in_lab', 'lab_completed')
                ");
                $stmtSum->execute([':doc_id' => $doctorId]);
            } else {
                $stmtSum = $pdo->query("
                    SELECT COUNT(*) as cnt, 
                           COALESCE(SUM(id), 0) as sum_id, 
                           COALESCE(MAX(id), 0) as max_id, 
                           COALESCE(GROUP_CONCAT(CONCAT(id, ':', status) ORDER BY id), '') as state_str
                    FROM patient_queues 
                    WHERE status IN ('waiting', 'in_consultation', 'on_hold', 'in_lab', 'lab_completed')
                ");
            }
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('dq_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['sum_id'] ?? 0) . '_' . ($sumData['state_str'] ?? '') . '_' . ($doctorId ?? 0) . '_' . $searchQuery);

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            // State changed -> Fetch fresh queue
            $waitingQueue = PatientOperation::getQueue([
                'status'    => 'active',
                'doctor_id' => $doctorId,
                'search'    => $searchQuery ?: null,
            ]);

            // Render HTML partial for Doctor Queue (Exact 5 columns matching doctor_dashboard.php)
            ob_start();
            if (empty($waitingQueue)): ?>
                <tr>
                    <td colspan="5" class="py-8 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-3xl mb-1 text-secondary">check_circle</span>
                        <p class="font-semibold text-on-surface">No patients currently waiting in your consultation queue.</p>
                        <p class="text-xs text-on-surface-variant mt-1">When reception check-in assigns patients to you, they will appear here live.</p>
                    </td>
                </tr>
            <?php else:
                foreach ($waitingQueue as $q):
                    $isUrgent = in_array($q['priority'], ['urgent', 'emergency'], true);
                    $hasVitals = !empty($q['systolic']);
                    $isLabReady = ($q['status'] === 'lab_completed');
                    $isInLab = ($q['status'] === 'in_lab');
                    $isOnHold = ($q['status'] === 'on_hold');
                    $isInConsult = ($q['status'] === 'in_consultation');
            ?>
                <tr class="hover:bg-surface-container-low transition-colors group <?php echo $isInConsult ? 'bg-primary/5 dark:bg-primary/10' : ($isOnHold ? 'bg-amber-500/5' : ''); ?>">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <span class="font-code-md font-bold text-sm text-primary bg-primary-fixed/40 px-2 py-0.5 rounded">
                                <?php echo e($q['token_number']); ?>
                            </span>
                            <?php if ($isInConsult): ?>
                                <span class="text-[10px] uppercase font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/20 border border-emerald-500/40 px-2 py-0.5 rounded-full flex items-center gap-0.5 animate-pulse">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span> In Room
                                </span>
                            <?php elseif ($isOnHold): ?>
                                <span class="text-[10px] uppercase font-bold text-amber-800 dark:text-amber-300 bg-amber-500/20 border border-amber-500/40 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-[13px]">pause_circle</span> On Hold
                                </span>
                            <?php elseif ($isLabReady): ?>
                                <span class="text-[10px] uppercase font-bold text-secondary bg-secondary-fixed/50 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                    <span class="material-symbols-outlined text-[13px]">verified</span> Lab Results Ready
                                </span>
                            <?php elseif ($isInLab): ?>
                                <span class="text-[10px] uppercase font-bold text-primary bg-primary-fixed/40 px-2 py-0.5 rounded-full">
                                    In Lab
                                </span>
                            <?php elseif ($isUrgent): ?>
                                <span class="text-xs uppercase font-bold text-error bg-error-container/60 px-2 py-0.5 rounded-full">
                                    <?php echo e($q['priority']); ?>
                                </span>
                            <?php endif; ?>
                            <?php 
                                $isBillingPaid = ($q['billing_status'] === 'paid' || in_array(($q['invoice_status'] ?? ''), ['paid', 'partial']) || (float)($q['current_doctor_fee'] ?? 10) <= 0.0);
                            ?>
                            <?php if ($isBillingPaid): ?>
                                <span class="text-[10px] uppercase font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded-full" title="Consultation fee verified & paid">
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
                                    <button type="button" disabled class="inline-flex items-center gap-1 px-3 py-1.5 bg-surface-container text-on-surface-variant/50 border border-outline-variant font-bold rounded-lg text-xs cursor-not-allowed" title="Bukaankan lacagtiisa consultation-ka weli lama bixin. Fadlan bukaanka u dir Cashier-ka (Caddaan ama Deyn).">
                                        <span class="material-symbols-outlined text-[15px]">lock</span>
                                        Locked
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($waitingQueue),
                'html'      => $html,
                'first_patient' => !empty($waitingQueue[0]) ? [
                    'name'  => $waitingQueue[0]['patient_name'],
                    'token' => $waitingQueue[0]['token_number'],
                    'id'    => (int)$waitingQueue[0]['patient_id'],
                    'queue_id' => (int)$waitingQueue[0]['id'],
                ] : null,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 1B. RECEPTION DESK INTAKE QUEUE LIVE STREAM
        // =========================================================================
        case 'reception_queue':
            $stmtSum = $pdo->query("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(status) as max_status
                FROM patient_queues 
                WHERE DATE(queued_at) = CURDATE()
            ");
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('rq_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['max_status'] ?? ''));

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            $queue = PatientOperation::getQueue('all');

            ob_start();
            if (empty($queue)): ?>
                <tr>
                    <td colspan="6" class="py-8 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-3xl mb-1 text-outline">queue</span>
                        <p class="font-semibold">No patients in the reception queue right now.</p>
                    </td>
                </tr>
            <?php else:
                foreach ($queue as $q):
                    $prioBadge = 'bg-surface-container text-on-surface';
                    if ($q['priority'] === 'emergency') $prioBadge = 'bg-error text-on-error font-bold';
                    elseif ($q['priority'] === 'urgent') $prioBadge = 'bg-error-container text-on-error-container font-bold';

                    $statusBadge = 'bg-secondary-fixed text-on-secondary-fixed-variant';
                    if ($q['status'] === 'in_consultation') $statusBadge = 'bg-primary-container text-on-primary-container font-bold';
                    elseif ($q['status'] === 'completed') $statusBadge = 'bg-surface-container-high text-on-surface';
            ?>
                <tr class="hover:bg-surface-container-low transition-colors">
                    <td class="py-3 px-3">
                        <div class="flex items-center gap-1.5">
                            <span class="font-code-md font-bold text-primary text-xs bg-primary-container/30 px-2 py-0.5 rounded"><?php echo e($q['token_number']); ?></span>
                            <span class="text-[9px] uppercase px-1.5 py-0.2 rounded font-bold <?php echo $prioBadge; ?>"><?php echo e($q['priority']); ?></span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo date('g:i A', strtotime($q['queued_at'])); ?></p>
                    </td>
                    <td class="py-3 px-3">
                        <a href="patient_profile_michael_chen.php?id=<?php echo (int)$q['patient_id']; ?>" class="font-bold text-on-surface hover:text-primary hover:underline">
                            <?php echo e($q['patient_name']); ?>
                        </a>
                        <p class="text-[11px] text-on-surface-variant font-mono"><?php echo e($q['mrn']); ?> • <?php echo e($q['phone']); ?></p>
                        <?php if (!empty($q['account_credit']) && (float)$q['account_credit'] > 0.005): ?>
                            <div class="mt-0.5">
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded-md" title="Patient has active wallet credit">
                                    <span class="material-symbols-outlined text-[13px]">account_balance_wallet</span>
                                    Credit: $<?php echo number_format((float)$q['account_credit'], 2); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3">
                        <p class="font-semibold text-on-surface"><?php echo e($q['doctor_name'] ?: 'Next Available'); ?></p>
                    </td>
                    <td class="py-3 px-3 font-medium text-on-surface-variant"><?php echo e($q['department']); ?></td>
                    <td class="py-3 px-3">
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full capitalize <?php echo $statusBadge; ?>">
                            <?php echo str_replace('_', ' ', $q['status']); ?>
                        </span>
                    </td>
                    <td class="py-3 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" 
                                    onclick="printQueueTokenTicket(<?php echo htmlspecialchars(json_encode([
                                        'token'            => $q['token_number'],
                                        'name'             => $q['patient_name'],
                                        'mrn'              => $q['mrn'],
                                        'phone'            => $q['phone'],
                                        'doctor'           => $q['doctor_name'] ?: 'Next Available Doctor',
                                        'department'       => $q['department'],
                                        'priority'         => ucfirst($q['priority']),
                                        'consultation_fee' => 10.00,
                                        'date_time'        => date('M d, Y g:i A', strtotime($q['queued_at'])),
                                    ]), ENT_QUOTES, 'UTF-8'); ?>)" 
                                    class="p-1 text-on-surface-variant hover:text-secondary hover:bg-secondary-container/30 rounded transition-colors cursor-pointer" 
                                    title="Print Token Slip">
                                <span class="material-symbols-outlined text-[18px]">receipt_long</span>
                            </button>
                            <button type="button" 
                                    onclick="openEditQueueModal(<?php echo (int)$q['id']; ?>, '<?php echo e(addslashes($q['patient_name'])); ?>', <?php echo (int)($q['doctor_id'] ?? 0); ?>, '<?php echo e(addslashes($q['department'])); ?>', '<?php echo e($q['priority']); ?>', <?php echo (float)($q['invoice_paid'] ?? 0.00); ?>, <?php echo (float)($q['current_doctor_fee'] ?? 10.00); ?>, <?php echo (float)($q['account_credit'] ?? 0.00); ?>)" 
                                    class="p-1 text-on-surface-variant hover:text-primary hover:bg-surface-container rounded transition-colors cursor-pointer" 
                                    title="Edit Queue">
                                <span class="material-symbols-outlined text-[17px]">edit</span>
                            </button>
                            <form method="POST" action="reception.php" class="inline" onsubmit="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_queue">
                                <input type="hidden" name="queue_id" value="<?php echo (int)$q['id']; ?>">
                                <button type="submit" class="p-1 text-on-surface-variant hover:text-error hover:bg-error-container/30 rounded transition-colors cursor-pointer" title="Remove from Queue">
                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($queue),
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 1C. HOSPITAL GENERAL QUEUE MANAGEMENT LIVE STREAM
        // =========================================================================
        case 'hospital_queue':
            $deptFilter   = sanitizeString($_GET['department'] ?? 'all');
            $statusFilter = sanitizeString($_GET['status'] ?? 'all');
            $searchQuery  = sanitizeString($_GET['search'] ?? '');

            if ($currentUserRole === ROLE_DOCTOR) {
                $doctorFilter = $currentUserId;
            } else {
                $doctorFilter = !empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;
            }

            $sumWhere = [];
            $sumParams = [];
            if ($doctorFilter) {
                $sumWhere[] = "(doctor_id = :doc_id OR doctor_id IS NULL)";
                $sumParams[':doc_id'] = $doctorFilter;
            }
            if ($deptFilter && $deptFilter !== 'all') {
                $sumWhere[] = "department = :dept";
                $sumParams[':dept'] = $deptFilter;
            }
            $sumWhereSql = !empty($sumWhere) ? ('WHERE ' . implode(' AND ', $sumWhere)) : '';

            $stmtSum = $pdo->prepare("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(status) as max_status,
                       COALESCE(GROUP_CONCAT(CONCAT(id, ':', status) ORDER BY id), '') as state_str
                FROM patient_queues
                {$sumWhereSql}
            ");
            $stmtSum->execute($sumParams);
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('hq_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['state_str'] ?? '') . '_' . $deptFilter . '_' . ($doctorFilter ?? 0) . '_' . $statusFilter . '_' . $searchQuery);

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            $queue = PatientOperation::getQueue(
                ($statusFilter === 'all') ? null : $statusFilter,
                $doctorFilter,
                ($deptFilter === 'all') ? null : $deptFilter,
                $searchQuery ?: null
            );

            ob_start();
            if (empty($queue)): ?>
                <tr>
                    <td colspan="6" class="py-8 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-3xl mb-1 text-outline">queue</span>
                        <p class="font-semibold">No patients found in the active queue.</p>
                    </td>
                </tr>
            <?php else:
                foreach ($queue as $item):
                    $prioBadge = 'bg-surface-container text-on-surface';
                    if ($item['priority'] === 'emergency') $prioBadge = 'bg-error text-on-error font-bold';
                    elseif ($item['priority'] === 'urgent') $prioBadge = 'bg-error-container text-on-error-container font-bold';
            ?>
                <tr class="hover:bg-surface-container-low transition-colors">
                    <td class="py-3 px-3">
                        <div class="flex items-center gap-1.5">
                            <span class="font-code-md font-bold text-primary text-xs bg-primary-container/30 px-2 py-0.5 rounded"><?php echo e($item['token_number']); ?></span>
                            <span class="text-[9px] uppercase px-1.5 py-0.2 rounded font-bold <?php echo $prioBadge; ?>"><?php echo e($item['priority']); ?></span>
                        </div>
                        <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo date('g:i A', strtotime($item['queued_at'])); ?></p>
                    </td>
                    <td class="py-3 px-3">
                        <a href="patient_profile_michael_chen.php?id=<?php echo (int)$item['patient_id']; ?>" class="font-bold text-on-surface hover:text-primary hover:underline">
                            <?php echo e($item['patient_name']); ?>
                        </a>
                        <p class="text-[11px] text-on-surface-variant"><?php echo e($item['mrn']); ?> • <?php echo (int)$item['age']; ?> yrs (<?php echo e($item['gender']); ?>)</p>
                        <?php if (!empty($item['account_credit']) && (float)$item['account_credit'] > 0.005): ?>
                            <div class="mt-0.5">
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded-md" title="Patient has active wallet credit">
                                    <span class="material-symbols-outlined text-[13px]">account_balance_wallet</span>
                                    Credit: $<?php echo number_format((float)$item['account_credit'], 2); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 font-semibold text-on-surface"><?php echo e($item['doctor_name'] ?: 'Next Available'); ?></td>
                    <td class="py-3 px-3 text-on-surface-variant"><?php echo e($item['department']); ?></td>
                    <td class="py-3 px-3">
                        <?php if ($item['status'] === 'waiting'): ?>
                            <span class="bg-surface-variant text-on-surface-variant font-label-md text-[10px] px-2 py-0.5 rounded-full font-semibold">Waiting</span>
                        <?php elseif ($item['status'] === 'in_consultation'): ?>
                            <span class="bg-primary-container text-on-primary-container font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold animate-pulse">In Consultation</span>
                        <?php elseif ($item['status'] === 'on_hold'): ?>
                            <span class="bg-amber-500/15 border border-amber-500/30 text-amber-800 dark:text-amber-300 font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold">On Hold</span>
                        <?php elseif ($item['status'] === 'in_lab'): ?>
                            <span class="bg-purple-500/15 border border-purple-500/30 text-purple-800 dark:text-purple-300 font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold">In Lab</span>
                        <?php elseif ($item['status'] === 'lab_completed'): ?>
                            <span class="bg-teal-500/15 border border-teal-500/30 text-teal-800 dark:text-teal-300 font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold">Lab Ready</span>
                        <?php elseif ($item['status'] === 'completed'): ?>
                            <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold">Completed</span>
                        <?php else: ?>
                            <span class="bg-surface-container text-on-surface text-[10px] px-2 py-0.5 rounded-full font-medium capitalize"><?php echo e($item['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($item['status'] === 'waiting'): ?>
                                <form method="POST" action="queue_management.php" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                                    <input type="hidden" name="status" value="in_consultation">
                                    <button type="submit" class="px-2 py-1 bg-primary text-on-primary rounded text-[11px] font-bold hover:bg-primary-container transition-colors cursor-pointer" title="Call in to consultation">
                                        Call In
                                    </button>
                                </form>
                            <?php elseif ($item['status'] === 'in_consultation'): ?>
                                <a href="consultation_michael_chen.php?id=<?php echo (int)$item['patient_id']; ?>&queue_id=<?php echo (int)$item['id']; ?>" class="px-2 py-1 bg-primary text-on-primary rounded text-[11px] font-bold hover:bg-primary/90 transition-colors" title="Open patient consultation room">
                                    Consult
                                </a>
                                <button type="button" 
                                        onclick="openHoldPatientModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($item['patient_name'])); ?>', 'queue_management.php')" 
                                        class="px-2 py-1 bg-amber-500/15 border border-amber-500/30 text-amber-800 dark:text-amber-300 hover:bg-amber-500/25 rounded text-[11px] font-bold transition-colors cursor-pointer" 
                                        title="Put patient on hold if temporarily away">
                                    Hold
                                </button>
                                <form method="POST" action="queue_management.php" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="px-2 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high rounded text-[11px] font-bold text-on-surface cursor-pointer">
                                        Done
                                    </button>
                                </form>
                            <?php elseif ($item['status'] === 'on_hold' || $item['status'] === 'lab_completed'): ?>
                                <form method="POST" action="queue_management.php" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                                    <input type="hidden" name="status" value="in_consultation">
                                    <button type="submit" class="px-2 py-1 bg-primary text-on-primary rounded text-[11px] font-bold hover:bg-primary-container transition-colors cursor-pointer" title="Recall patient into consultation">
                                        Recall
                                    </button>
                                </form>
                                <form method="POST" action="queue_management.php" class="inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="px-2 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high rounded text-[11px] font-bold text-on-surface cursor-pointer">
                                        Done
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($currentUserRole !== ROLE_DOCTOR): ?>
                            <button type="button" 
                                    onclick="openEditQueueModal(<?php echo (int)$item['id']; ?>, '<?php echo e(addslashes($item['patient_name'])); ?>', <?php echo (int)($item['doctor_id'] ?? 0); ?>, '<?php echo e(addslashes($item['department'])); ?>', '<?php echo e($item['priority']); ?>', <?php echo (float)($item['invoice_paid'] ?? 0.00); ?>, <?php echo (float)($item['current_doctor_fee'] ?? 10.00); ?>, <?php echo (float)($item['account_credit'] ?? 0.00); ?>)" 
                                    class="p-1 text-on-surface-variant hover:text-primary hover:bg-surface-container rounded transition-colors cursor-pointer" 
                                    title="Edit Queue Assignment">
                                <span class="material-symbols-outlined text-[17px]">edit</span>
                            </button>
                            <form method="POST" action="queue_management.php" class="inline" onsubmit="return confirm('Are you sure you want to remove this patient from the queue?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_queue">
                                <input type="hidden" name="queue_id" value="<?php echo (int)$item['id']; ?>">
                                <button type="submit" class="p-1 text-on-surface-variant hover:text-error hover:bg-error-container/30 rounded transition-colors cursor-pointer" title="Remove from Queue">
                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($queue),
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 2. CONSULTATION WORKSPACE LAB FINDINGS & ORDERS LIVE STREAM
        // =========================================================================
        case 'consultation_lab_results':
            $patientId = (int)($_GET['patient_id'] ?? 0);
            $queueId   = (int)($_GET['queue_id'] ?? 0);

            if ($patientId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid patient']);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            // Checksum query
            $stmtSum = $pdo->prepare("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(status) as max_status
                FROM lab_orders
                WHERE patient_id = :pid
            ");
            $stmtSum->execute([':pid' => $patientId]);
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('clr_' . $patientId . '_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['max_status'] ?? ''));

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            // Fetch live patient lab orders
            $patientLabOrders = LaboratoryOperation::getPatientLabOrders($patientId);

            // Render HTML partial for consultation lab results
            ob_start();
            if (empty($patientLabOrders)): ?>
                <div class="p-4 bg-surface-container-low/40 rounded-xl border border-dashed border-outline-variant text-center">
                    <span class="material-symbols-outlined text-outline text-2xl mb-1">biotech</span>
                    <p class="text-xs text-on-surface-variant">No diagnostic lab tests ordered for this patient yet.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($patientLabOrders as $ord): 
                        $isCompleted = in_array($ord['status'], ['completed', 'verified'], true);
                        $statusBadge = match($ord['status']) {
                            'completed', 'verified' => 'bg-emerald-100 text-emerald-800 border-emerald-300 font-bold',
                            'in_progress'           => 'bg-amber-100 text-amber-800 border-amber-300 font-medium',
                            'cancelled'             => 'bg-error-container text-on-error-container',
                            default                 => 'bg-surface-container text-on-surface-variant',
                        };
                    ?>
                        <div class="p-3.5 bg-surface rounded-xl border <?php echo $isCompleted ? 'border-emerald-300 bg-emerald-50/30' : 'border-outline-variant'; ?> shadow-xs space-y-2">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-[16px] <?php echo $isCompleted ? 'text-emerald-600' : 'text-primary'; ?>">science</span>
                                        <?php echo e($ord['test_name']); ?>
                                        <span class="font-mono text-[10px] text-on-surface-variant font-normal">(<?php echo e($ord['test_code']); ?>)</span>
                                    </h4>
                                    <p class="text-[10px] text-on-surface-variant mt-0.5">
                                        Ordered: <?php echo date('M d, Y H:i', strtotime($ord['ordered_at'])); ?>
                                    </p>
                                </div>
                                <span class="text-[10px] px-2 py-0.5 rounded-full border capitalize <?php echo $statusBadge; ?>">
                                    <?php echo e($ord['status']); ?>
                                </span>
                            </div>

                            <?php if (!empty($ord['results'])): ?>
                                <div class="p-2.5 bg-surface-container-low rounded-lg border border-outline-variant/60 space-y-1">
                                    <p class="text-[10px] font-bold text-on-surface uppercase tracking-wide flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[14px] text-emerald-600">check_circle</span>
                                        Diagnostic Finding / Result:
                                    </p>
                                    <p class="text-xs font-mono text-on-surface font-semibold pl-4">
                                        <?php echo nl2br(e($ord['results'])); ?>
                                    </p>
                                    <?php if (!empty($ord['lab_notes'])): ?>
                                        <p class="text-[11px] text-on-surface-variant italic pl-4">
                                            Notes: <?php echo e($ord['lab_notes']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isCompleted): ?>
                                <p class="text-xs text-emerald-700 font-semibold italic">Test performed and completed. Awaiting detailed entry.</p>
                            <?php else: ?>
                                <p class="text-[11px] text-on-surface-variant italic">Test specimen pending laboratory processing...</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($patientLabOrders),
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 3. LABORATORY WORKLIST LIVE STREAM
        // =========================================================================
        case 'laboratory_worklist':
            $statusFilter = sanitizeString($_GET['status'] ?? 'all');
            $panelFilter  = sanitizeString($_GET['panel'] ?? 'all');
            $searchQuery  = sanitizeString($_GET['search'] ?? '');

            // Fast checksum
            $stmtSum = $pdo->query("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(status) as max_status
                FROM lab_orders
            ");
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('lw_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['max_status'] ?? '') . '_' . $statusFilter . '_' . $panelFilter);

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            $worklist = LaboratoryOperation::getLabWorklist($statusFilter, $panelFilter, $searchQuery);
            $kpis     = LaboratoryOperation::getLabSummaryKPIs();

            // Render HTML partial for Laboratory Table
            ob_start();
            if (empty($worklist)): ?>
                <tr>
                    <td colspan="7" class="py-12 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-4xl mb-2 text-outline">biotech</span>
                        <p class="font-semibold text-sm">No diagnostic laboratory orders found.</p>
                        <p class="text-xs mt-0.5">When doctors order lab investigations during patient consultations, they will appear here.</p>
                    </td>
                </tr>
            <?php else:
                foreach ($worklist as $order):
                    $isPaid = ($order['payment_status'] === 'paid' || $order['payment_status'] === 'partial' || (float)($order['billing_due'] ?? 0) <= 0.001);
                    $payBadge = $isPaid 
                        ? 'bg-secondary-fixed/40 text-on-secondary-fixed-variant border-secondary/30' 
                        : 'bg-amber-500/10 text-amber-700 border-amber-500/30';

                    $statusClass = match ($order['status']) {
                        'completed'        => 'bg-secondary-fixed text-on-secondary-fixed font-bold',
                        'sample_collected' => 'bg-primary-container text-on-primary-container font-bold',
                        'in_progress'      => 'bg-tertiary-fixed text-on-tertiary-fixed font-bold',
                        default            => 'bg-surface-container text-on-surface',
                    };
            ?>
                <tr class="hover:bg-surface-container-low transition-colors">
                    <td class="py-3 px-4">
                        <span class="font-mono font-bold text-primary"><?php echo e($order['order_number']); ?></span>
                        <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo date('M d, g:i A', strtotime($order['created_at'])); ?></p>
                    </td>
                    <td class="py-3 px-4">
                        <a href="patient_profile_michael_chen.php?id=<?php echo (int)$order['patient_id']; ?>" class="font-bold text-on-surface hover:text-primary hover:underline">
                            <?php echo e($order['patient_name']); ?>
                        </a>
                        <p class="text-[11px] text-on-surface-variant capitalize"><?php echo e($order['gender']); ?></p>
                    </td>
                    <td class="py-3 px-4">
                        <p class="font-bold text-on-surface"><?php echo e($order['test_name']); ?></p>
                        <p class="text-[11px] font-mono font-semibold text-secondary">$<?php echo number_format((float)$order['test_price'], 2); ?></p>
                        <?php if (!empty($order['clinical_notes'])): ?>
                            <p class="text-[11px] text-on-surface-variant italic mt-0.5 bg-surface-container-lowest p-1 rounded border border-outline-variant/60">
                                "<?php echo e($order['clinical_notes']); ?>"
                            </p>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4">
                        <p class="font-semibold text-on-surface"><?php echo e($order['doctor_name'] ?: 'Attending Doctor'); ?></p>
                        <p class="text-[10px] text-on-surface-variant"><?php echo e($order['doctor_title'] ?: 'Clinician'); ?></p>
                    </td>
                    <td class="py-3 px-4">
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold border capitalize <?php echo $payBadge; ?>">
                            <?php echo $isPaid ? 'Paid' : 'Pending Fee'; ?>
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="text-[10px] px-2.5 py-0.5 rounded-full capitalize <?php echo $statusClass; ?>">
                            <?php echo str_replace('_', ' ', $order['status']); ?>
                        </span>
                        <?php if ($order['status'] === 'completed'): ?>
                            <p class="text-[10px] text-secondary mt-0.5 font-semibold">
                                ✓ <?php echo date('M d, g:i A', strtotime($order['completed_at'])); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                            <!-- Doctor Clinical Handover View Button -->
                            <button type="button" 
                                    onclick='openDoctorHandoverModal(<?php echo json_encode([
                                        "patient_name"         => $order["patient_name"],
                                        "mrn"                  => $order["mrn"],
                                        "gender"               => ucfirst($order["gender"] ?? ""),
                                        "patient_age"          => $order["patient_age"] ?? "N/A",
                                        "blood_group"          => $order["blood_group"] ?: "Not Recorded",
                                        "allergies"            => $order["allergies"] ?: "None Recorded",
                                        "medical_history"      => $order["medical_history"] ?: "None Recorded",
                                        "doctor_name"          => $order["doctor_name"] ?: "Attending Doctor",
                                        "doctor_title"         => $order["doctor_title"] ?: "Clinician",
                                        "test_name"            => $order["test_name"],
                                        "clinical_notes"       => $order["clinical_notes"] ?: "Routine clinical investigation",
                                        "systolic"             => $order["systolic"] ?? null,
                                        "diastolic"            => $order["diastolic"] ?? null,
                                        "heart_rate"           => $order["heart_rate"] ?? null,
                                        "temperature"          => $order["temperature"] ?? null,
                                        "spo2_oxygen"          => $order["spo2_oxygen"] ?? null,
                                        "respiratory_rate"     => $order["respiratory_rate"] ?? null,
                                        "weight_kg"            => $order["weight_kg"] ?? null,
                                        "height_cm"            => $order["height_cm"] ?? null,
                                        "bmi"                  => $order["bmi"] ?? null,
                                        "subjective_notes"     => $order["subjective_notes"] ?: "None recorded",
                                        "objective_findings"   => $order["objective_findings"] ?: "None recorded",
                                        "assessment_diagnosis" => $order["assessment_diagnosis"] ?: "Under Investigation",
                                        "secondary_diagnosis"  => $order["secondary_diagnosis"] ?: "None",
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                    class="px-2 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold rounded-lg text-xs flex items-center gap-1 cursor-pointer transition-colors shadow-2xs"
                                    title="View Doctor Clinical Notes, Baseline & Vitals">
                                <span class="material-symbols-outlined text-[15px] text-primary">clinical_notes</span>
                                <span class="hidden sm:inline">Doctor Notes &amp; Vitals</span>
                                <span class="sm:hidden">Notes</span>
                            </button>

                            <?php if (!$isPaid): ?>
                                <a href="billing_payments.php<?php echo !empty($order['billing_invoice_id']) ? '?invoice_id=' . (int)$order['billing_invoice_id'] : ''; ?>" 
                                   class="px-2.5 py-1 bg-amber-500/20 text-amber-800 border border-amber-500/40 hover:bg-amber-500/30 font-bold rounded-lg text-xs flex items-center gap-1 shadow-2xs transition-colors" 
                                   title="Lab fee unpaid. Patient must pay at Cashier before specimen collection.">
                                    <span class="material-symbols-outlined text-[15px]">lock</span>
                                    <span>Pay at Cashier ($<?php echo number_format((float)$order['test_price'], 2); ?>)</span>
                                </a>
                            <?php else: ?>
                                <?php if ($order['status'] === 'pending'): ?>
                                    <form method="POST" action="laboratory_dashboard.php" class="inline">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="collect_specimen">
                                        <input type="hidden" name="order_id" value="<?php echo (int)$order['order_id']; ?>">
                                        <button type="submit" class="px-2.5 py-1 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-lg text-xs flex items-center gap-1 shadow-xs cursor-pointer transition-colors">
                                            <span class="material-symbols-outlined text-[15px]">bloodtype</span>
                                            Collect Sample
                                        </button>
                                    </form>
                                <?php elseif ($order['status'] === 'sample_collected' || $order['status'] === 'in_progress'): ?>
                                    <button type="button" 
                                            onclick="openResultModal(<?php echo (int)$order['order_id']; ?>, '<?php echo e(addslashes($order['patient_name'])); ?>', '<?php echo e(addslashes($order['test_name'])); ?>', '<?php echo e(addslashes($order['normal_range'] ?? '')); ?>', '<?php echo e(addslashes($order['clinical_notes'] ?? '')); ?>')"
                                            class="px-2.5 py-1 bg-secondary hover:bg-on-secondary-container text-on-secondary font-bold rounded-lg text-xs flex items-center gap-1 shadow-xs cursor-pointer transition-colors">
                                        <span class="material-symbols-outlined text-[15px]">assignment_turned_in</span>
                                        Enter Results
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($order['status'] === 'completed'): ?>
                                <button type="button" 
                                        onclick="viewResultModal('<?php echo e(addslashes($order['patient_name'])); ?>', '<?php echo e(addslashes($order['test_name'])); ?>', '<?php echo e(addslashes($order['result_summary'] ?? '')); ?>', '<?php echo e(addslashes($order['result_values'] ?? '')); ?>', '<?php echo e(addslashes($order['technician_name'] ?? 'Lab Tech')); ?>', '<?php echo date('M d, Y g:i A', strtotime($order['completed_at'])); ?>')"
                                        class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold rounded-lg text-xs flex items-center gap-1 cursor-pointer transition-colors">
                                    <span class="material-symbols-outlined text-[15px]">visibility</span>
                                    View Findings
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'kpis'      => $kpis,
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 4. PHARMACY PRESCRIPTIONS QUEUE LIVE STREAM
        // =========================================================================
        case 'pharmacy_queue':
            $selectedRxId = (int)($_GET['rx_id'] ?? 0);

            $stmtSum = $pdo->query("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(status) as max_status
                FROM prescriptions
                WHERE status IN ('pending', 'partially_dispensed')
            ");
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('pq_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['max_status'] ?? '') . '_' . $selectedRxId);

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            $queue = PharmacyOperation::getPendingPrescriptionsQueue();

            ob_start();
            if (empty($queue)): ?>
                <p class="text-xs text-on-surface-variant py-4 text-center">No orders in queue</p>
            <?php else:
                foreach ($queue as $q):
                    $isSelected = ($selectedRxId === (int)$q['id']); 
                    $isPartial = ($q['status'] === 'partially_dispensed');
            ?>
                <a href="?rx_id=<?php echo (int)$q['id']; ?>" class="block p-sm rounded border transition-colors <?php echo $isSelected ? 'bg-primary-fixed/20 border-primary/40 shadow-xs' : 'bg-surface-container-lowest border-outline-variant hover:border-primary/40'; ?>">
                    <div class="flex justify-between items-start">
                        <div class="flex items-center gap-1.5">
                            <span class="font-code-md text-xs font-bold <?php echo $isSelected ? 'text-primary' : 'text-on-surface'; ?>"><?php echo e($q['rx_number']); ?></span>
                            <?php if ($isPartial): ?>
                                <span class="bg-tertiary-fixed text-on-tertiary-fixed text-[9px] font-bold px-1.5 py-0.2 rounded">Partial</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-[10px] font-semibold text-primary"><?php echo date('g:i A', strtotime($q['created_at'])); ?></span>
                    </div>
                    <p class="font-body-sm text-xs font-semibold text-on-surface mt-1"><?php echo e($q['patient_name']); ?></p>
                    <p class="text-[11px] text-on-surface-variant truncate"><?php echo (int)$q['item_count']; ?> items • <?php echo e($q['medication_names'] ?: 'Prescription medications'); ?></p>
                </a>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($queue),
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;

        // =========================================================================
        // 5. BILLING & CASHIER QUEUE LIVE STREAM
        // =========================================================================
        case 'billing_queue':
            $billType = sanitizeString($_GET['type'] ?? 'all');
            $search   = sanitizeString($_GET['search'] ?? '');

            $stmtSum = $pdo->query("
                SELECT COUNT(*) as cnt, MAX(id) as max_id, MAX(payment_status) as max_status
                FROM invoices
                WHERE payment_status IN ('pending', 'partial')
            ");
            $sumData = $stmtSum->fetch();
            $serverChecksum = md5('bq_' . ($sumData['cnt'] ?? 0) . '_' . ($sumData['max_id'] ?? 0) . '_' . ($sumData['max_status'] ?? '') . '_' . $billType);

            if ($clientChecksum === $serverChecksum) {
                echo json_encode(['status' => 'ok', 'changed' => false, 'checksum' => $serverChecksum]);
                if (!defined('HPMS_TESTING')) { exit; }
                return;
            }

            $pendingQueue = BillingOperation::getPendingInvoicesQueue($billType, $search);

            ob_start();
            if (empty($pendingQueue)): ?>
                <div class="py-12 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[36px] text-on-surface-variant/40 block mb-1">done_all</span>
                    <p class="text-xs font-bold">No pending bills in queue!</p>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">All patient charges have been collected.</p>
                </div>
            <?php else:
                foreach ($pendingQueue as $inv):
                    $typeBadge = match ($inv['bill_type']) {
                        'consultation'      => 'bg-primary-fixed/40 text-primary border-primary/30',
                        'pharmacy'          => 'bg-purple-500/20 text-purple-700 border-purple-300 dark:border-purple-800',
                        'lab', 'laboratory' => 'bg-secondary-fixed/50 text-secondary border-secondary/40',
                        default             => 'bg-surface-container text-on-surface border-outline-variant',
                    };
            ?>
                <a href="billing_payments.php?invoice_id=<?php echo (int)$inv['id']; ?>&type=<?php echo e($billType); ?>" 
                   class="block p-3 rounded-xl border transition-all cursor-pointer bg-surface-container-lowest border-outline-variant hover:bg-surface-container-low">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="font-mono font-bold text-xs text-primary"><?php echo e($inv['invoice_number']); ?></span>
                                <?php if (!empty($inv['token_number'])): ?>
                                    <span class="bg-amber-500/20 text-amber-800 dark:text-amber-300 font-mono font-bold text-[10px] px-1.5 py-0.5 rounded">
                                        <?php echo e($inv['token_number']); ?>
                                    </span>
                                <?php endif; ?>

                            </div>
                            <h4 class="font-bold text-xs text-on-surface mt-0.5"><?php echo e($inv['customer_name']); ?></h4>
                            <p class="text-[10px] text-on-surface-variant"><?php echo e($inv['mrn'] ?: 'Outpatient'); ?> • <?php echo date('g:i A', strtotime($inv['created_at'])); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="font-mono font-bold text-sm text-error block">$<?php echo number_format((float)$inv['due_amount'], 2); ?></span>
                            <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded-full border <?php echo $typeBadge; ?>">
                                <?php echo e($inv['bill_type']); ?>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; endif;
            $html = ob_get_clean();

            echo json_encode([
                'status'    => 'ok',
                'changed'   => true,
                'checksum'  => $serverChecksum,
                'count'     => count($pendingQueue),
                'html'      => $html,
            ]);
            if (!defined('HPMS_TESTING')) { exit; }
            return;



        default:
            echo json_encode(['status' => 'error', 'message' => 'Unknown module']);
            if (!defined('HPMS_TESTING')) { exit; }
    }
} catch (Exception $e) {
    error_log('[HPMS LIVE SYNC ERROR] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}