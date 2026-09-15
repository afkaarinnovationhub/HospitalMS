<?php
/**
 * MedCore Systems - Real Queue Management & Patient Flow
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

// Auto-seed default patients if table is fresh
try {
    PatientOperation::seedDefaultPatientsIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS QUEUE SEED ERROR] ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

$printTokenPayload = $_SESSION['hpms_print_token'] ?? null;
unset($_SESSION['hpms_print_token']);

$lastRefundVoucher = null;
if (!empty($_SESSION['hpms_last_refund_voucher'])) {
    $voucherId = (int)$_SESSION['hpms_last_refund_voucher'];
    unset($_SESSION['hpms_last_refund_voucher']);
    require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';
    $lastRefundVoucher = BillingOperation::getRefundVoucherById($voucherId);
}

// Handle Status Updates, Edits & Deletions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'call_patient') {
        $result = PatientController::handleCallPatient($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'update_status') {
        $result = PatientController::handleUpdateQueueStatus($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'quick_check_in') {
        $result = PatientController::handleQuickCheckIn($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_queue') {
        $result = PatientController::handleEditQueueItem($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_queue') {
        $result = PatientController::handleDeleteQueueItem($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'refund_patient_credit') {
        $result = PatientController::handleRefundPatientCredit($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Filters (Role-Scoped)
$statusFilter   = sanitizeString($_GET['status'] ?? 'all');
$deptFilter     = sanitizeString($_GET['dept'] ?? '');
$searchQuery    = sanitizeString($_GET['search'] ?? '');
$currentUser    = getCurrentUser();
$isDoctorRole   = (($currentUser['role'] ?? '') === ROLE_DOCTOR);

if ($isDoctorRole) {
    $scopedDoctorId = (int)$currentUser['id'];
} else {
    $scopedDoctorId = !empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;
}

$kpis    = PatientOperation::getPatientSummaryKPIs($scopedDoctorId);
$queue   = PatientOperation::getQueue($statusFilter === 'all' ? null : $statusFilter, $scopedDoctorId, $deptFilter ?: null, $searchQuery ?: null);
$doctors = PatientOperation::getDoctorsList();

// Fetch per-doctor queue counts today
$pdo = getDBConnection();
$docCounts = [];
foreach ($doctors as $doc) {
    $dId = (int)$doc['id'];
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = :id AND DATE(queued_at) = CURDATE() AND status != 'cancelled'");
    $stmtC->execute([':id' => $dId]);
    $docCounts[$dId] = (int)$stmtC->fetchColumn();
}

$pageTitle = 'Queue Management - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Patient Queue';
$activePage = 'queue';

include __DIR__ . '/../components/header.php';
?>

<!-- Canvas -->
<main class="p-4 sm:p-6 lg:p-lg pb-6 flex-1 overflow-y-auto bg-background custom-scrollbar">
    <div class="mb-lg flex flex-col sm:flex-row justify-between items-start sm:items-end gap-md">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Queue Management</h2>
        </div>
        <div class="flex flex-wrap gap-sm w-full sm:w-auto">
            <?php if ($isDoctorRole): ?>
                <a href="doctor_dashboard.php" class="flex-1 sm:flex-none justify-center px-md py-2 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-md hover:bg-primary-container hover:text-on-primary-container transition-colors flex items-center gap-xs shadow-sm font-bold cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">stethoscope</span>
                    Doctor Dashboard
                </a>
            <?php else: ?>
                <a href="reception.php" class="flex-1 sm:flex-none justify-center px-md py-2 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-md hover:bg-primary-container hover:text-on-primary-container transition-colors flex items-center gap-xs shadow-sm font-bold cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">desk</span>
                    Reception Desk
                </a>
            <?php endif; ?>
            <a href="patient_registration.php" class="flex-1 sm:flex-none justify-center px-md py-2 border border-outline-variant text-on-surface font-label-md text-xs sm:text-label-md rounded-md hover:bg-surface-container transition-colors flex items-center gap-xs font-medium">
                <span class="material-symbols-outlined text-[18px]">groups</span>
                Patients
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-md p-3 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-2 shadow-xs">
            <span class="material-symbols-outlined text-error text-[18px]">error</span>
            <p><?php echo e($errorMessage); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-md p-3 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-2 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[18px]">check_circle</span>
            <p><?php echo e($successMessage); ?></p>
        </div>
    <?php endif; ?>

    <!-- Summary KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-md">
        <div class="bg-surface border border-outline-variant rounded-xl p-3.5 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-on-surface-variant uppercase">Waiting in Queue</p>
                <p class="text-xl font-bold text-primary mt-0.5"><?php echo number_format($kpis['waiting_in_queue']); ?></p>
            </div>
            <span class="material-symbols-outlined text-primary text-2xl">hourglass_top</span>
        </div>
        <div class="bg-surface border border-outline-variant rounded-xl p-3.5 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-on-surface-variant uppercase">In Consultation</p>
                <p class="text-xl font-bold text-tertiary mt-0.5"><?php echo number_format($kpis['in_consultation']); ?></p>
            </div>
            <span class="material-symbols-outlined text-tertiary text-2xl">stethoscope</span>
        </div>
        <div class="bg-surface border border-outline-variant rounded-xl p-3.5 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-on-surface-variant uppercase">Completed Today</p>
                <p class="text-xl font-bold text-secondary mt-0.5"><?php echo number_format($kpis['completed_today']); ?></p>
            </div>
            <span class="material-symbols-outlined text-secondary text-2xl">task_alt</span>
        </div>
        <div class="bg-surface border border-outline-variant rounded-xl p-3.5 shadow-sm flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-on-surface-variant uppercase">Total Patients</p>
                <p class="text-xl font-bold text-on-surface mt-0.5"><?php echo number_format($kpis['total_patients']); ?></p>
            </div>
            <span class="material-symbols-outlined text-on-surface-variant text-2xl">groups</span>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="bg-surface rounded-xl border border-outline-variant overflow-hidden shadow-sm flex flex-col">
        <!-- Filters Toolbar -->
        <form method="GET" action="queue_management.php" class="p-3 border-b border-outline-variant flex flex-wrap gap-2 justify-between items-center bg-surface-bright">
            <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
                <!-- Instant Search Bar -->
                <div class="relative w-full sm:w-60 max-w-xs">
                    <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[17px] pointer-events-none">search</span>
                    <input type="text" 
                           id="queue-search-input" 
                           name="search" 
                           value="<?php echo e($searchQuery); ?>" 
                           placeholder="Search token, name, MRN..." 
                           autocomplete="off"
                           class="w-full bg-surface border border-outline-variant rounded-lg pl-8 pr-7 py-1 text-xs text-on-surface focus:border-primary outline-none transition-colors">
                    <button type="button" 
                            id="queue-search-clear-btn" 
                            onclick="clearQueueSearch()" 
                            class="<?php echo empty($searchQuery) ? 'hidden ' : ''; ?>absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface p-0.5 cursor-pointer" 
                            title="Clear search">
                        <span class="material-symbols-outlined text-[14px]">close</span>
                    </button>
                </div>

                <select name="status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded px-2.5 py-1 text-xs text-on-surface outline-none">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Status: All</option>
                    <option value="waiting" <?php echo $statusFilter === 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                    <option value="in_consultation" <?php echo $statusFilter === 'in_consultation' ? 'selected' : ''; ?>>In Consultation</option>
                    <option value="on_hold" <?php echo $statusFilter === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                    <option value="in_lab" <?php echo $statusFilter === 'in_lab' ? 'selected' : ''; ?>>In Lab</option>
                    <option value="lab_completed" <?php echo $statusFilter === 'lab_completed' ? 'selected' : ''; ?>>Lab Ready</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
                <select name="dept" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded px-2.5 py-1 text-xs text-on-surface outline-none">
                    <option value="">All Departments</option>
                    <option value="General OPD" <?php echo $deptFilter === 'General OPD' ? 'selected' : ''; ?>>General OPD</option>
                    <option value="Cardiology OPD" <?php echo $deptFilter === 'Cardiology OPD' ? 'selected' : ''; ?>>Cardiology OPD</option>
                    <option value="Neurology OPD" <?php echo $deptFilter === 'Neurology OPD' ? 'selected' : ''; ?>>Neurology OPD</option>
                    <option value="Endocrinology OPD" <?php echo $deptFilter === 'Endocrinology OPD' ? 'selected' : ''; ?>>Endocrinology OPD</option>
                </select>
                <?php if ($isDoctorRole): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded bg-primary/10 border border-primary/20 text-primary font-bold text-xs">
                        <span class="material-symbols-outlined text-[15px]">stethoscope</span>
                        My Queue: <?php echo e($currentUser['full_name'] ?? 'Doctor'); ?>
                    </span>
                <?php else: ?>
                    <select name="doctor_id" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded px-2.5 py-1 text-xs text-on-surface outline-none">
                        <option value="">All Doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo (int)$doc['id']; ?>" <?php echo ((int)$scopedDoctorId === (int)$doc['id']) ? 'selected' : ''; ?>>
                                <?php echo e($doc['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <a href="queue_management.php" class="p-1 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant flex items-center justify-center cursor-pointer" title="Refresh Live Queue">
                    <span class="material-symbols-outlined text-[16px]">refresh</span>
                </a>
            </div>
        </form>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant">
                    <tr>
                        <th class="py-2.5 px-3 font-semibold">Token &amp; Priority</th>
                        <th class="py-2.5 px-3 font-semibold">Patient Name &amp; MRN</th>
                        <th class="py-2.5 px-3 font-semibold">Assigned Doctor</th>
                        <th class="py-2.5 px-3 font-semibold">Department</th>
                        <th class="py-2.5 px-3 font-semibold">Status</th>
                        <th class="py-2.5 px-3 font-semibold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="queue-table-tbody" class="font-body-sm text-xs text-on-surface divide-y divide-outline-variant">
                    <?php if (empty($queue)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl mb-1 text-outline">queue</span>
                                <p class="font-semibold">No patients found in the active queue.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($queue as $item): ?>
                            <?php
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
                                        <?php if (!$isDoctorRole): ?>
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
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- EDIT QUEUE ITEM MODAL -->
<div id="edit-queue-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[22px]">edit</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Queue Assignment</h3>
            </div>
            <button type="button" onclick="closeEditQueueModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="queue_management.php" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_queue">
            <input type="hidden" id="edit_queue_id" name="queue_id" value="">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface-variant mb-0.5">Patient</label>
                <input id="edit_patient_name" readonly class="w-full bg-surface-container border border-outline-variant rounded p-2 text-xs font-bold text-on-surface outline-none" type="text">
            </div>

            <!-- Line Separator: Doctor Reassignment -->
            <div class="border-t border-outline-variant/60 pt-3">
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Reassign Doctor</label>
                <select id="edit_doctor_id" name="doctor_id" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none">
                    <option value="" data-fee="10.00">-- Next Available Doctor ($10.00) --</option>
                    <option disabled>──────────────────────────────────────────────────</option>
                    <?php foreach ($doctors as $i => $doc): ?>
                        <?php if ($i > 0): ?>
                            <option disabled>──────────────────────────────────────────────────</option>
                        <?php endif; ?>
                        <option value="<?php echo (int)$doc['id']; ?>" data-fee="<?php echo number_format((float)($doc['consultation_fee'] ?? 10.00), 2, '.', ''); ?>">
                            <?php echo e($doc['full_name']); ?>  |  $<?php echo number_format((float)($doc['consultation_fee'] ?? 10.00), 2); ?> (<?php echo e($doc['professional_title'] ?: 'General Practice'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Line Separator: Department & Priority -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Department</label>
                        <select id="edit_department" name="department" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                            <option value="General OPD">General OPD</option>
                            <option value="Cardiology OPD">Cardiology OPD</option>
                            <option value="Neurology OPD">Neurology OPD</option>
                            <option value="Endocrinology OPD">Endocrinology OPD</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Priority</label>
                        <select id="edit_priority" name="priority" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs font-semibold">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="emergency">Emergency</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Dynamic Consultation Fee & Adjustment Summary -->
            <div id="reassign_fee_box" class="p-3 rounded-xl border border-outline-variant bg-surface-container-low/70 text-xs space-y-2">
                <div class="flex justify-between items-center text-[11px] text-on-surface-variant font-medium">
                    <span>Paid Consultation Fee:</span>
                    <span id="reassign_paid_display" class="font-bold text-on-surface font-mono">$0.00</span>
                </div>
                <div class="flex justify-between items-center text-[11px] text-on-surface-variant font-medium">
                    <span>New Doctor Consultation Fee:</span>
                    <span id="reassign_new_fee_display" class="font-bold text-on-surface font-mono">$10.00</span>
                </div>
                
                <div id="reassign_fee_badge" class="p-2.5 rounded-lg text-xs font-semibold"></div>

                <!-- Overpayment Choice: Visible ONLY on Downgrade -->
                <div id="reassign_overpayment_options" class="hidden pt-2 border-t border-outline-variant/70 space-y-2">
                    <p class="text-[11px] font-bold text-on-surface flex items-center gap-1">
                        <span class="material-symbols-outlined text-secondary text-[16px]">payments</span>
                        Select Overpayment Resolution:
                    </p>
                    <label class="flex items-start gap-2 text-[11px] text-on-surface cursor-pointer p-2 rounded-lg bg-surface border border-outline-variant/60 hover:bg-surface-container">
                        <input type="radio" name="overpayment_action" value="credit" checked class="mt-0.5 text-primary">
                        <div>
                            <span class="font-bold text-primary">Habka A: Ku shub Baaqiga Bukaanka (Patient Account Credit)</span>
                            <p class="text-[10px] text-on-surface-variant mt-0.5">Lacagta celinta ah waxaa loogu dari doonaa baaqiga bukaanka, toosna loogu jari doonaa farmashiyaha ama shaybaarka.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-2 text-[11px] text-on-surface cursor-pointer p-2 rounded-lg bg-surface border border-outline-variant/60 hover:bg-surface-container">
                        <input type="radio" name="overpayment_action" value="refund" class="mt-0.5 text-primary">
                        <div>
                            <span class="font-bold text-secondary">Habka B: Soo saar Cash Refund Voucher (Lacag Celin Caddaan ah)</span>
                            <p class="text-[10px] text-on-surface-variant mt-0.5">Cashier-ku lacagta caddaanka ah ayuu ku celinayaa oo rasiid refund ah daabacayaa.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Existing Patient Credit Box & Cash-Out Option -->
            <div id="reassign_patient_credit_box" class="hidden p-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-xs space-y-1.5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-1.5 text-emerald-950 dark:text-emerald-200">
                        <span class="material-symbols-outlined text-emerald-600 text-[20px]">account_balance_wallet</span>
                        <span class="font-bold">Patient Wallet Credit:</span>
                        <span id="reassign_patient_credit_display" class="font-mono font-extrabold text-sm text-emerald-700 dark:text-emerald-300">$0.00</span>
                    </div>
                    <button type="button" onclick="submitCreditRefundVoucher()" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] rounded-lg shadow-xs flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-[15px]">receipt_long</span>
                        Cash Refund Voucher
                    </button>
                </div>
                <p class="text-[10px] text-emerald-800/80 dark:text-emerald-300/80">
                    Bukaan-kan wuxuu leeyahay lacag u taal nidaamka. Waxaad toos uga dhigi kartaa Cash Refund Voucher haddii uu lacagta caddaan ahaan u rabo.
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeEditQueueModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Update Queue</button>
            </div>
        </form>

        <form id="form-refund-credit" method="POST" action="queue_management.php" class="hidden">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="refund_patient_credit">
            <input type="hidden" name="redirect" value="queue_management.php">
            <input type="hidden" id="refund_credit_queue_id" name="queue_id" value="">
        </form>
    </div>
</div>

<script>
    let currentPatientPaidFee = 10.00;
    let currentPatientCredit = 0.00;

    function openEditQueueModal(queueId, patientName, docId, dept, priority, paidAmount, currentFee, accountCredit) {
        document.getElementById('edit_queue_id').value = queueId;
        document.getElementById('edit_patient_name').value = patientName;
        document.getElementById('edit_doctor_id').value = docId;
        document.getElementById('edit_department').value = dept;
        document.getElementById('edit_priority').value = priority;

        currentPatientPaidFee = parseFloat(paidAmount !== undefined && paidAmount !== null ? paidAmount : 10.00);
        document.getElementById('reassign_paid_display').textContent = '$' + currentPatientPaidFee.toFixed(2);

        currentPatientCredit = parseFloat(accountCredit !== undefined && accountCredit !== null ? accountCredit : 0.00);
        const creditBox = document.getElementById('reassign_patient_credit_box');
        if (creditBox) {
            if (currentPatientCredit > 0.005) {
                document.getElementById('reassign_patient_credit_display').textContent = '$' + currentPatientCredit.toFixed(2);
                document.getElementById('refund_credit_queue_id').value = queueId;
                creditBox.classList.remove('hidden');
            } else {
                creditBox.classList.add('hidden');
            }
        }
        
        calculateReassignFee();
        document.getElementById('edit-queue-modal').classList.remove('hidden');
    }

    function submitCreditRefundVoucher() {
        if (confirm('Ma hubtaa inaad lacagtan celinta ah ($' + currentPatientCredit.toFixed(2) + ') u soo saarto Cash Refund Voucher caddaan ah?')) {
            document.getElementById('form-refund-credit').submit();
        }
    }

    function calculateReassignFee() {
        const docSelect = document.getElementById('edit_doctor_id');
        const selectedOpt = docSelect.options[docSelect.selectedIndex];
        const newFee = parseFloat(selectedOpt && selectedOpt.getAttribute('data-fee') ? selectedOpt.getAttribute('data-fee') : '10.00');
        
        document.getElementById('reassign_new_fee_display').textContent = '$' + newFee.toFixed(2);
        
        const badge = document.getElementById('reassign_fee_badge');
        const overpayBox = document.getElementById('reassign_overpayment_options');
        const diff = newFee - currentPatientPaidFee;
        
        if (diff > 0.005) {
            badge.className = 'p-2 rounded text-xs font-semibold bg-amber-500/15 border border-amber-500/30 text-amber-800 dark:text-amber-300';
            badge.innerHTML = `<span class="material-symbols-outlined text-[16px] align-middle mr-1">warning</span> Additional Fee Due: +$${diff.toFixed(2)} (Sent to Billing / Cashier)`;
            overpayBox.classList.add('hidden');
        } else if (diff < -0.005) {
            const overpay = Math.abs(diff);
            badge.className = 'p-2 rounded text-xs font-semibold bg-secondary/15 border border-secondary/30 text-secondary';
            badge.innerHTML = `<span class="material-symbols-outlined text-[16px] align-middle mr-1">savings</span> Overpayment to Return: -$${overpay.toFixed(2)}`;
            overpayBox.classList.remove('hidden');
        } else {
            badge.className = 'p-2 rounded text-xs font-medium bg-surface-container border border-outline-variant text-on-surface-variant';
            badge.innerHTML = `<span class="material-symbols-outlined text-[16px] align-middle mr-1">check_circle</span> No Fee Adjustment Needed ($0.00)`;
            overpayBox.classList.add('hidden');
        }
    }

    function closeEditQueueModal() {
        document.getElementById('edit-queue-modal').classList.add('hidden');
    }
</script>

<!-- PRINT QUEUE TOKEN MODAL -->
<div id="print-token-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 class="font-headline-sm text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-primary text-[20px]">confirmation_number</span>
                Queue Token Slip
            </h3>
            <button type="button" onclick="closePrintTokenModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Slip Card -->
        <div id="printable-token-slip" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono text-center space-y-2 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2">
                <h4 class="font-bold text-base tracking-wide uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p class="text-[10px] text-gray-600">Main Outpatient Clinic • Queue System</p>
                <p class="text-[9px] text-gray-500">Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?> • <?php echo htmlspecialchars(HOSPITAL_ADDRESS); ?></p>
            </div>

            <div class="py-2">
                <span class="text-[10px] uppercase font-bold text-gray-600 tracking-wider">Your Queue Token</span>
                <div id="pt-token" class="text-4xl font-extrabold tracking-wider my-1 text-black">T-001</div>
                <div id="pt-pos-dept" class="text-[11px] font-bold text-gray-700">Pos #1 • General OPD</div>
            </div>

            <div class="border-t border-b border-dashed border-gray-300 py-2 text-left text-[11px] space-y-1">
                <div class="flex justify-between"><span class="text-gray-500">Patient:</span> <span id="pt-name" class="font-bold">Ahmed Warsame</span></div>
                <div class="flex justify-between"><span class="text-gray-500">MRN:</span> <span id="pt-mrn" class="font-bold">MRN-2026-0042</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Phone:</span> <span id="pt-phone" class="font-bold">(555) 012-9900</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Doctor:</span> <span id="pt-doctor" class="font-bold">Dr. Michael Chen</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Priority:</span> <span id="pt-priority" class="font-bold uppercase">NORMAL</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Fee:</span> <span id="pt-fee" class="font-bold">$10.00 (Consultation)</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Time:</span> <span id="pt-time" class="font-bold">Aug 31, 2026 12:30 PM</span></div>
            </div>

            <div class="pt-1 text-[9px] text-gray-600 leading-tight">
                <p class="font-semibold">Fadlan sug inta lagaaga yeerayo lambarkaaga.</p>
                <p class="mt-0.5">Please take a seat until your token is called.</p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <button type="button" onclick="closePrintTokenModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Close</button>
            <button type="button" onclick="executePrintToken()" class="px-4 py-1.5 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1 cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Ticket
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printable-token-slip, #printable-token-slip *,
    #printable-refund-slip, #printable-refund-slip * {
        visibility: visible !important;
    }
    #printable-token-slip, #printable-refund-slip {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 80mm !important;
        margin: 0 !important;
        padding: 10px !important;
        border: none !important;
        box-shadow: none !important;
        background: white !important;
        color: black !important;
    }
}
</style>

<?php if ($lastRefundVoucher): ?>
<!-- PRINT CASH REFUND VOUCHER MODAL -->
<div id="print-refund-modal" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 class="font-headline-sm text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-secondary text-[20px]">receipt_long</span>
                Cash Refund Voucher
            </h3>
            <button type="button" onclick="closePrintRefundModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <div id="printable-refund-slip" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono text-center space-y-2 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2">
                <h4 class="font-bold text-base tracking-wide uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p class="text-[11px] text-gray-600">Patient Cash Refund Voucher</p>
            </div>

            <div class="py-2">
                <p class="text-[11px] text-gray-500 uppercase tracking-wider">Voucher Number</p>
                <h2 class="text-xl font-extrabold text-black font-sans my-0.5 tracking-tight"><?php echo e($lastRefundVoucher['voucher_number'] ?? 'RV-000'); ?></h2>
                <span class="inline-block px-2.5 py-0.5 text-[10px] font-bold uppercase rounded-full bg-emerald-100 text-emerald-800">
                    CASH REFUND AUTHORIZED
                </span>
            </div>

            <div class="text-left text-xs space-y-1.5 border-t border-b border-dashed border-gray-300 py-2.5">
                <div class="flex justify-between">
                    <span class="text-gray-500">Patient:</span>
                    <span class="font-bold"><?php echo e($lastRefundVoucher['patient_name'] ?? ''); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">MRN:</span>
                    <span><?php echo e($lastRefundVoucher['mrn'] ?? ''); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Refund Amount:</span>
                    <span class="font-bold text-emerald-700 text-sm">$<?php echo number_format((float)($lastRefundVoucher['amount'] ?? 0), 2); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Reason:</span>
                    <span class="text-[10px] text-right truncate max-w-[170px]"><?php echo e($lastRefundVoucher['reason'] ?? 'Consultation Downgrade'); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Authorized By:</span>
                    <span><?php echo e($lastRefundVoucher['issued_by_name'] ?? 'Reception / Cashier'); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Date:</span>
                    <span><?php echo date('M d, Y g:i A', strtotime($lastRefundVoucher['created_at'] ?? 'now')); ?></span>
                </div>
            </div>

            <p class="text-[10px] text-gray-500 pt-1 leading-tight">
                Cash returned from cashier drawer. Please keep this slip for hospital records.
            </p>
        </div>

        <div class="flex justify-end gap-2 pt-1 border-t border-outline-variant">
            <button type="button" onclick="closePrintRefundModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">
                Close
            </button>
            <button type="button" onclick="window.print()" class="flex items-center gap-1.5 px-4 py-1.5 rounded bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Voucher
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

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

        <form id="hold-patient-form" method="POST" action="queue_management.php" class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" id="hold_queue_id" name="queue_id" value="">
            <input type="hidden" name="status" value="on_hold">
            <input type="hidden" id="hold_redirect" name="redirect" value="queue_management.php">

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
    function printQueueTokenTicket(data) {
        document.getElementById('pt-token').textContent = data.token || 'T-001';
        document.getElementById('pt-pos-dept').textContent = `Pos #${data.pos || data.queue_position || '1'} • ${data.dept || data.department || 'General OPD'}`;
        document.getElementById('pt-name').textContent = data.name || data.patient_name || 'Walk-in Patient';
        document.getElementById('pt-mrn').textContent = data.mrn || 'N/A';
        document.getElementById('pt-phone').textContent = data.phone || 'N/A';
        document.getElementById('pt-doctor').textContent = data.doctor || data.doctor_name || 'Next Available Doctor';
        document.getElementById('pt-priority').textContent = (data.priority || 'NORMAL').toUpperCase();
        document.getElementById('pt-fee').textContent = data.fee ? `$${parseFloat(data.fee).toFixed(2)} (Consultation)` : (data.consultation_fee ? `$${parseFloat(data.consultation_fee).toFixed(2)} (Consultation)` : '$10.00 (Consultation)');
        document.getElementById('pt-time').textContent = data.time || data.date_time || new Date().toLocaleString();

        document.getElementById('print-token-modal').classList.remove('hidden');
    }

    function closePrintTokenModal() {
        document.getElementById('print-token-modal').classList.add('hidden');
    }

    function closePrintRefundModal() {
        const modal = document.getElementById('print-refund-modal');
        if (modal) modal.classList.add('hidden');
    }

    function executePrintRefund() {
        window.print();
    }

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

    function applyQueueClientFilter() {
        const input = document.getElementById('queue-search-input');
        const clearBtn = document.getElementById('queue-search-clear-btn');
        if (!input) return;
        const query = input.value.trim().toLowerCase();
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', query.length === 0);
        }
        const tbody = document.getElementById('queue-table-tbody');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr');
        let matched = 0;
        let existingNoMatch = document.getElementById('queue-no-match-tr');

        rows.forEach(r => {
            if (r.id === 'queue-no-match-tr') return;
            const txt = r.textContent.toLowerCase();
            const show = !query || txt.includes(query);
            r.style.display = show ? '' : 'none';
            if (show) matched++;
        });

        if (matched === 0 && query.length > 0) {
            if (!existingNoMatch) {
                existingNoMatch = document.createElement('tr');
                existingNoMatch.id = 'queue-no-match-tr';
                existingNoMatch.innerHTML = `
                    <td colspan="6" class="py-8 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-3xl mb-1 text-outline">person_search</span>
                        <p class="font-semibold">Wax bukaan ah oo ku habboon baaritaankaaga lama helin.</p>
                        <p class="text-xs text-on-surface-variant mt-0.5">No patients matching "${query.replace(/</g, '&lt;')}".</p>
                    </td>
                `;
                tbody.appendChild(existingNoMatch);
            } else {
                existingNoMatch.style.display = '';
            }
        } else if (existingNoMatch) {
            existingNoMatch.style.display = 'none';
        }
    }

    function clearQueueSearch() {
        const input = document.getElementById('queue-search-input');
        if (!input) return;
        input.value = '';
        if (window.location.search.includes('search=')) {
            input.form.submit();
        } else {
            applyQueueClientFilter();
            input.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($printTokenPayload)): ?>
        printQueueTokenTicket(<?php echo json_encode($printTokenPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
        <?php endif; ?>

        const qInput = document.getElementById('queue-search-input');
        if (qInput) {
            qInput.addEventListener('input', applyQueueClientFilter);
        }

        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'hospital_queue',
                targetSelector: '#queue-table-tbody',
                params: {
                    department: '<?php echo e($deptFilter); ?>',
                    doctor_id: '<?php echo (int)($scopedDoctorId ?? 0); ?>',
                    status: '<?php echo e($statusFilter); ?>',
                    search: '<?php echo e($searchQuery); ?>'
                },
                intervalMs: 3500,
                notifyOnNew: true,
                onUpdate: function() {
                    applyQueueClientFilter();
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
