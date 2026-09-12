<?php
/**
 * MedCore Systems - Real Front Desk Reception & Quick Patient Intake
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
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_RECEPTION_CASHIER, ROLE_PHARMACY]);

// Auto-seed default patients if table is fresh
try {
    PatientOperation::seedDefaultPatientsIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS RECEPTION SEED ERROR] ' . $e->getMessage());
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

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'quick_check_in') {
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
    } elseif ($action === 'update_status') {
        $result = PatientController::handleUpdateQueueStatus($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

$kpis     = PatientOperation::getPatientSummaryKPIs();
$queue    = PatientOperation::getQueue('all');
$doctors  = PatientOperation::getDoctorsList();

// Fetch per-doctor queue counts today
$pdo = getDBConnection();
$docCounts = [];
foreach ($doctors as $doc) {
    $dId = (int)$doc['id'];
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = :id AND DATE(queued_at) = CURDATE() AND status != 'cancelled'");
    $stmtC->execute([':id' => $dId]);
    $docCounts[$dId] = (int)$stmtC->fetchColumn();
}

$pageTitle = 'Front Desk Reception & Intake - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Reception Desk';
$activePage = 'reception';

include __DIR__ . '/../components/header.php';
?>

<!-- Front Desk Reception Main Canvas -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Front Desk Reception &amp; Quick Intake</h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                    Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Receptionist'); ?></strong>
                </p>
            </div>
            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-sm w-full md:w-auto">
                <button type="button" onclick="openQuickIntakeModal()" class="flex-1 sm:flex-none flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-bold cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">confirmation_number</span>
                    + Quick Check-In
                </button>
                <a href="patient_registration.php" class="flex-1 sm:flex-none flex items-center justify-center gap-xs px-md py-2.5 border border-outline-variant text-on-surface font-label-md text-xs sm:text-label-md rounded-lg hover:bg-surface-container-low transition-colors font-medium">
                    <span class="material-symbols-outlined text-[20px]">groups</span>
                    Patients Directory
                </a>
            </div>
        </div>

        <!-- Alert Notifications -->
        <?php if (!empty($errorMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
                <div>
                    <p class="font-bold">Reception Alert</p>
                    <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
                <div>
                    <p class="font-bold">Check-In Successful</p>
                    <p class="mt-0.5"><?php echo e($successMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Reception Metric Summary Bento Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Today's Registered</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1"><?php echo number_format($kpis['today_registered']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">how_to_reg</span>
                </div>
            </div>

            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Waiting in Lobby</p>
                    <p class="font-display-lg text-2xl font-bold text-secondary mt-1"><?php echo number_format($kpis['waiting_in_queue']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">chair</span>
                </div>
            </div>

            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">In Consultation</p>
                    <p class="font-display-lg text-2xl font-bold text-primary mt-1"><?php echo number_format($kpis['in_consultation']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-fixed text-on-primary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">stethoscope</span>
                </div>
            </div>

            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Completed Today</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1"><?php echo number_format($kpis['completed_today']); ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-surface-container-high text-on-surface flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">task_alt</span>
                </div>
            </div>
        </div>

        <!-- Returning Patient Live Search & Instant Check-in Bar -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-sm space-y-3 relative">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                    <div class="w-10 h-10 rounded-xl bg-primary-container text-on-primary-container flex items-center justify-center shadow-xs">
                        <span class="material-symbols-outlined text-[22px]">person_search</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-sm sm:text-base text-on-surface flex items-center gap-2">
                            Raadinta Bukaanka Hore
                        </h3>
                    </div>
                </div>
            </div>

            <!-- Search input with live autocomplete dropdown -->
            <div class="relative">
                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-[20px] pointer-events-none">search</span>
                <input type="text" 
                       id="global-patient-search" 
                       oninput="handleGlobalPatientSearch(this.value)" 
                       placeholder="Qor magaca bukaanka ama telefoonka" 
                       autocomplete="off" 
                       class="w-full pl-10 pr-10 py-2.5 bg-surface-container-low border border-outline-variant rounded-xl text-xs sm:text-sm text-on-surface focus:border-primary focus:bg-surface outline-none transition-all shadow-inner">
                <button type="button" id="clear-global-search" onclick="clearGlobalPatientSearch()" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[18px]">close</span>
                </button>
                
                <!-- Live Search Dropdown Container -->
                <div id="global-search-results" class="hidden absolute z-30 left-0 right-0 top-full mt-1.5 bg-surface border border-outline-variant rounded-xl shadow-2xl overflow-hidden max-h-80 overflow-y-auto custom-scrollbar divide-y divide-outline-variant/60">
                    <!-- Dynamic items rendered via JS -->
                </div>
            </div>
        </div>

        <!-- Main Functional Layout (8 Cols Intake Worklist + 4 Cols Live Doctor Rooms) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-lg items-start">
            <!-- Left Column: Patient Intake Worklist & Check-in Table -->
            <div class="lg:col-span-8 flex flex-col gap-md">
                <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
                    <!-- Table Toolbar -->
                    <div class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[20px]">view_list</span>
                            <span class="text-xs sm:text-sm font-bold text-on-surface">Today's Reception Intake Queue</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="bg-primary-fixed text-on-primary-fixed text-[10px] font-bold px-2.5 py-0.5 rounded-full"><?php echo count($queue); ?> Patients Checked In</span>
                        </div>
                    </div>

                    <!-- Intake Table -->
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse min-w-[650px]">
                            <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant">
                                <tr>
                                    <th class="py-2.5 px-3 font-semibold">Token &amp; Priority</th>
                                    <th class="py-2.5 px-3 font-semibold">Patient Details &amp; MRN</th>
                                    <th class="py-2.5 px-3 font-semibold">Assigned Doctor</th>
                                    <th class="py-2.5 px-3 font-semibold">Department</th>
                                    <th class="py-2.5 px-3 font-semibold">Status</th>
                                    <th class="py-2.5 px-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reception-queue-tbody" class="font-body-sm text-xs divide-y divide-outline-variant">
                                <?php if (empty($queue)): ?>
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-on-surface-variant">
                                            <span class="material-symbols-outlined text-3xl mb-1 text-outline">queue</span>
                                            <p class="font-semibold">No patients in the reception queue right now.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($queue as $q): ?>
                                        <?php
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
                                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded-md" title="Patient has wallet credit available">
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
                                                <div class="flex flex-col gap-1">
                                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full capitalize <?php echo $statusBadge; ?>">
                                                        <?php echo str_replace('_', ' ', $q['status']); ?>
                                                    </span>
                                                    <?php if (($q['billing_status'] ?? '') === 'paid'): ?>
                                                        <span class="text-[9px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/15 border border-emerald-500/30 px-1.5 py-0.5 rounded text-center">
                                                            ✓ Paid
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-[9px] font-bold text-amber-800 dark:text-amber-300 bg-amber-500/15 border border-amber-500/30 px-1.5 py-0.5 rounded text-center" title="Awaiting fee payment at cashier">
                                                            🔒 Unpaid
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
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
                                                            title="Print Queue Token Slip">
                                                        <span class="material-symbols-outlined text-[17px]">print</span>
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
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column: Live Doctor Rooms & Workload -->
            <div class="lg:col-span-4 flex flex-col gap-md">
                <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm space-y-3">
                    <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
                        <h3 class="font-headline-sm text-sm text-on-surface font-bold">Doctor Workload Today</h3>
                        <a href="queue_management.php" class="text-xs text-primary font-bold hover:underline">Full Board →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($doctors as $doc): ?>
                            <?php $cnt = $docCounts[(int)$doc['id']] ?? 0; ?>
                            <div class="p-2.5 rounded-lg bg-surface-container-lowest border border-outline-variant flex justify-between items-center">
                                <div>
                                    <p class="font-bold text-xs text-on-surface"><?php echo e($doc['full_name']); ?></p>
                                    <p class="text-[11px] text-on-surface-variant capitalize"><?php echo e($doc['role']); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="bg-primary-fixed text-on-primary-fixed text-xs px-2.5 py-0.5 rounded-full font-bold">
                                        <?php echo $cnt; ?> <?php echo $cnt === 1 ? 'patient' : 'patients'; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- MODAL 1: Quick Patient Check-In & Token Issuance (Centered & Unified Form) -->
<div id="quick-intake-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-4 sm:p-6 shadow-2xl max-h-[88vh] overflow-y-auto custom-scrollbar my-auto transition-all">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-3">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">confirmation_number</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Quick Patient Check-In</h3>
                </div>
            </div>
            <button type="button" onclick="closeQuickIntakeModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form id="quick-intake-form" method="POST" action="reception.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="quick_check_in">
            <input type="hidden" id="intake_patient_id" name="patient_id" value="">

            <!-- Unified Optional Patient Search Bar -->
            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/80 space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-[11px] font-bold text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[16px]">person_search</span>
                        Raadi Bukaan Hore (Optional)
                    </label>
                </div>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-[18px] pointer-events-none">search</span>
                    <input type="text" 
                           id="modal-patient-search" 
                           oninput="handleModalPatientSearch(this.value)" 
                           placeholder="Baar bukaan hore si xogtiisu toos ugu buuxsanto..." 
                           autocomplete="off" 
                           class="w-full pl-9 pr-8 py-2 bg-surface border border-outline-variant rounded-lg text-xs text-on-surface focus:border-primary outline-none shadow-xs">
                    <button type="button" id="clear-modal-search" onclick="clearModalPatientSearch()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface p-0.5 rounded cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">close</span>
                    </button>
                    <!-- Modal Autocomplete Dropdown -->
                    <div id="modal-search-results" class="hidden absolute z-30 left-0 right-0 top-full mt-1 bg-surface border border-outline-variant rounded-xl shadow-xl overflow-hidden max-h-52 overflow-y-auto custom-scrollbar divide-y divide-outline-variant/60"></div>
                </div>

                <!-- Selected Patient Notification Pill (Visible when an existing patient is selected) -->
                <div id="selected-patient-card" class="hidden p-2.5 rounded-lg border border-primary/40 bg-primary-fixed/20 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="material-symbols-outlined text-primary text-[18px] shrink-0">check_circle</span>
                        <div class="min-w-0 text-xs">
                            <span class="font-bold text-on-surface" id="card-patient-name">--</span>
                            <span id="card-patient-mrn" class="font-mono text-[10px] font-bold bg-primary text-on-primary px-1.5 py-0.2 rounded ml-1">--</span>
                            <span id="card-patient-credit-badge" class="hidden text-[10px] font-bold text-emerald-800 dark:text-emerald-300 ml-1">
                                • Credit: <span id="card-patient-credit-val">$0.00</span>
                            </span>
                        </div>
                    </div>
                    <button type="button" onclick="clearSelectedPatient()" class="text-[10px] font-bold text-error hover:bg-error/10 px-2 py-0.5 rounded border border-error/30 cursor-pointer shrink-0">
                        Ka saar
                    </button>
                </div>
            </div>

            <!-- Patient Core Details Inputs (Populated if searched, or filled directly for new patient) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-0.5">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">First Name *</label>
                    <input id="intake_first_name" name="first_name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Hassan" type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Last Name</label>
                    <input id="intake_last_name" name="last_name" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Ali" type="text">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number *</label>
                    <input id="intake_phone" name="phone" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. 25261..." type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Gender</label>
                    <select id="intake_gender" name="gender" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <!-- Line Separator: Doctor Routing & Queue Assignment -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="p-3.5 bg-primary-fixed/20 rounded-xl border border-primary/30 space-y-3">
                    <p class="font-bold text-xs text-primary flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">stethoscope</span>
                        Doctor &amp; Department Routing
                    </p>
                    
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Select Doctor *</label>
                        <select id="reception_doc_select" name="doctor_id" onchange="updateReceptionFee(this)" required class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface font-semibold focus:border-primary outline-none">
                            <option value="">-- Choose Doctor --</option>
                            <option disabled>──────────────────────────────────────────────────</option>
                            <?php foreach ($doctors as $i => $doc): ?>
                                <?php 
                                    $cnt = $docCounts[(int)$doc['id']] ?? 0; 
                                    $docFee = (float)($doc['consultation_fee'] ?? 10.00);
                                    $docSpecialty = !empty($doc['professional_title']) ? $doc['professional_title'] : 'General Practice';
                                ?>
                                <?php if ($i > 0): ?>
                                    <option disabled>──────────────────────────────────────────────────</option>
                                <?php endif; ?>
                                <option value="<?php echo (int)$doc['id']; ?>" data-fee="<?php echo $docFee; ?>" data-dept="<?php echo e($docSpecialty); ?>">
                                    <?php echo e($doc['full_name']); ?>  |  <?php echo e($docSpecialty); ?>  |  $<?php echo number_format($docFee, 2); ?> (<?php echo $cnt; ?> in queue)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Inner Line Separator: Consultation Fee -->
                    <div class="border-t border-primary/20 pt-2.5">
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-[11px] font-bold text-on-surface">Consultation / Token Fee ($ USD) *</label>
                            <span class="text-[10px] text-on-surface-variant">Editable override for discounts or waivers</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 font-bold text-xs text-on-surface-variant">$</span>
                                <input id="reception_intake_fee" name="consultation_fee" type="number" step="0.50" min="0" required class="w-full pl-7 pr-3 py-2 bg-surface border border-outline-variant rounded-lg text-xs font-mono font-bold text-primary focus:border-primary outline-none text-base">
                            </div>
                            <button type="button" onclick="setReceptionFee(0)" class="px-2.5 py-1.5 bg-surface-container hover:bg-surface-container-high text-[10px] font-bold rounded-lg text-on-surface cursor-pointer border border-outline-variant">Free ($0)</button>
                            <button type="button" onclick="setReceptionFee(10)" class="px-2.5 py-1.5 bg-surface-container hover:bg-surface-container-high text-[10px] font-bold rounded-lg text-on-surface cursor-pointer border border-outline-variant">$10</button>
                            <button type="button" onclick="setReceptionFee(20)" class="px-2.5 py-1.5 bg-surface-container hover:bg-surface-container-high text-[10px] font-bold rounded-lg text-on-surface cursor-pointer border border-outline-variant">$20</button>
                            <button type="button" onclick="setReceptionFee(30)" class="px-2.5 py-1.5 bg-surface-container hover:bg-surface-container-high text-[10px] font-bold rounded-lg text-on-surface cursor-pointer border border-outline-variant">$30</button>
                        </div>
                    </div>


                    <!-- Hidden auto-resolved Department from doctor -->
                    <input type="hidden" id="reception_intake_dept" name="department" value="">

                    <!-- Inner Line Separator: Priority & Symptoms -->
                    <div class="border-t border-primary/20 pt-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-semibold text-on-surface-variant mb-0.5">Priority Level</label>
                                <select name="priority" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-semibold">
                                    <option value="normal">Normal</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="emergency">Emergency Priority</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-on-surface-variant mb-0.5">Reason for Visit / Symptoms (Optional)</label>
                                <input name="chief_complaint" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs" placeholder="e.g. Headache, fever..." type="text">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeQuickIntakeModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">confirmation_number</span>
                    Issue Token &amp; Assign to Doctor
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Edit Queue Item -->
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

        <form method="POST" action="reception.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_queue">
            <input type="hidden" name="redirect" value="reception.php">
            <input type="hidden" id="edit_queue_id" name="queue_id" value="">

            <div>
                <label class="block text-[11px] text-on-surface-variant mb-0.5">Patient</label>
                <input id="edit_patient_name" readonly class="w-full bg-surface-container border border-outline-variant rounded p-2 text-xs font-bold text-on-surface outline-none" type="text">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Reassign Doctor</label>
                <select id="edit_doctor_id" name="doctor_id" onchange="calculateReassignFee()" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
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

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Department</label>
                <select id="edit_department" name="department" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                    <option value="General OPD">General OPD</option>
                    <option value="Cardiology OPD">Cardiology OPD</option>
                    <option value="Neurology OPD">Neurology OPD</option>
                    <option value="Endocrinology OPD">Endocrinology OPD</option>
                    <option value="Pediatrics OPD">Pediatrics OPD</option>
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

            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeEditQueueModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-1.5 rounded bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Update Queue</button>
            </div>
        </form>

        <form id="form-refund-credit" method="POST" action="reception.php" class="hidden">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="refund_patient_credit">
            <input type="hidden" name="redirect" value="reception.php">
            <input type="hidden" id="refund_credit_queue_id" name="queue_id" value="">
        </form>
    </div>
</div>

<script>
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function openQuickIntakeModal() {
        document.getElementById('quick-intake-modal').classList.remove('hidden');
        const input = document.getElementById('modal-patient-search');
        if (input && !document.getElementById('intake_patient_id').value) {
            setTimeout(() => input.focus(), 60);
        }
    }

    function closeQuickIntakeModal() {
        document.getElementById('quick-intake-modal').classList.add('hidden');
        const modalResults = document.getElementById('modal-search-results');
        if (modalResults) modalResults.classList.add('hidden');
    }

    // --- Search Handlers with Debounce ---
    let globalSearchTimeout = null;
    function handleGlobalPatientSearch(query) {
        const clearBtn = document.getElementById('clear-global-search');
        const resultsBox = document.getElementById('global-search-results');
        const trimmed = query.trim();

        if (clearBtn) {
            clearBtn.classList.toggle('hidden', trimmed === '');
        }

        if (trimmed.length === 0) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
            return;
        }

        clearTimeout(globalSearchTimeout);
        globalSearchTimeout = setTimeout(() => {
            fetchPatients(trimmed, function(patients) {
                renderSearchResults(patients, resultsBox, true);
            });
        }, 220);
    }

    function clearGlobalPatientSearch() {
        const input = document.getElementById('global-patient-search');
        if (input) input.value = '';
        const clearBtn = document.getElementById('clear-global-search');
        if (clearBtn) clearBtn.classList.add('hidden');
        const resultsBox = document.getElementById('global-search-results');
        if (resultsBox) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
        }
    }

    let modalSearchTimeout = null;
    function handleModalPatientSearch(query) {
        const clearBtn = document.getElementById('clear-modal-search');
        const resultsBox = document.getElementById('modal-search-results');
        const trimmed = query.trim();

        if (clearBtn) {
            clearBtn.classList.toggle('hidden', trimmed === '');
        }

        if (trimmed.length === 0) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
            return;
        }

        clearTimeout(modalSearchTimeout);
        modalSearchTimeout = setTimeout(() => {
            fetchPatients(trimmed, function(patients) {
                renderSearchResults(patients, resultsBox, false);
            });
        }, 220);
    }

    function clearModalPatientSearch() {
        const input = document.getElementById('modal-patient-search');
        if (input) input.value = '';
        const clearBtn = document.getElementById('clear-modal-search');
        if (clearBtn) clearBtn.classList.add('hidden');
        const resultsBox = document.getElementById('modal-search-results');
        if (resultsBox) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
        }
    }

    function fetchPatients(query, callback) {
        fetch('../api/live_sync.php?module=search_patients&q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                if (data && data.status === 'success') {
                    callback(data.patients || []);
                } else {
                    callback([]);
                }
            })
            .catch(err => {
                console.error('[PATIENT SEARCH ERROR]', err);
                callback([]);
            });
    }

    // Cache active patients for safe selection by ID
    let currentSearchResults = {};

    function renderSearchResults(patients, container, isGlobal) {
        if (!patients || patients.length === 0) {
            container.innerHTML = `
                <div class="p-4 text-center text-on-surface-variant text-xs space-y-1">
                    <span class="material-symbols-outlined text-outline text-[22px]">person_off</span>
                    <p class="font-semibold">Ma jiro bukaan aad raadineyso.</p>
                </div>
            `;
            container.classList.remove('hidden');
            return;
        }

        currentSearchResults = {};
        patients.forEach(p => { currentSearchResults[p.id] = p; });

        let html = '';
        patients.forEach(p => {
            const fullName = escapeHtml(p.full_name || (p.first_name + ' ' + p.last_name));
            const mrn = escapeHtml(p.mrn || '');
            const phone = escapeHtml(p.phone || 'No phone');
            const gender = (p.gender || 'male').toLowerCase();
            const genderIcon = gender === 'female' ? 'female' : 'male';
            const ageStr = (p.age !== null && p.age !== undefined && p.age !== '') ? (p.age + ' yrs') : '';
            const blood = p.blood_group ? `<span class="px-1.5 py-0.2 text-[10px] font-bold rounded bg-surface-container-high border border-outline-variant text-on-surface">${escapeHtml(p.blood_group)}</span>` : '';
            const credit = parseFloat(p.account_credit || 0);
            const creditBadge = credit > 0.005 ? `<span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-500/20 border border-emerald-500/30 px-1.5 py-0.2 rounded"><span class="material-symbols-outlined text-[12px]">account_balance_wallet</span> Credit: $${credit.toFixed(2)}</span>` : '';
            const initial = (fullName.trim().charAt(0) || 'P').toUpperCase();

            html += `
            <div onclick="selectPatientById(${p.id}, ${isGlobal})" class="p-3 hover:bg-primary-fixed/20 transition-colors cursor-pointer flex items-center justify-between gap-2.5 group">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-full bg-primary text-on-primary font-bold text-xs flex items-center justify-center shrink-0 shadow-xs">
                        ${initial}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <h4 class="font-bold text-xs text-on-surface group-hover:text-primary transition-colors truncate">${fullName}</h4>
                            <span class="font-mono text-[10px] font-bold bg-surface-container-high px-1.5 py-0.2 rounded text-on-surface-variant">${mrn}</span>
                            ${blood}
                            ${creditBadge}
                        </div>
                        <p class="text-[11px] text-on-surface-variant font-mono mt-0.5 flex items-center gap-1.5 flex-wrap">
                            <span class="flex items-center gap-0.5"><span class="material-symbols-outlined text-[13px]">${genderIcon}</span> ${gender}</span>
                            ${ageStr ? `<span>• ${ageStr}</span>` : ''}
                            <span>• 📞 ${phone}</span>
                        </p>
                    </div>
                </div>
                <button type="button" class="shrink-0 px-2.5 py-1 bg-primary text-on-primary group-hover:bg-primary-container group-hover:text-on-primary-container text-[11px] font-bold rounded-lg transition-colors flex items-center gap-1 shadow-xs cursor-pointer">
                    <span class="material-symbols-outlined text-[13px]">check</span>
                    Dooro
                </button>
            </div>
            `;
        });

        container.innerHTML = html;
        container.classList.remove('hidden');
    }

    function selectPatientById(patientId, isGlobal) {
        const patient = currentSearchResults[patientId];
        if (!patient) return;

        if (isGlobal) {
            openQuickIntakeModal();
        }
        selectPatientInModal(patient);
        clearGlobalPatientSearch();
    }

    function selectPatientInModal(patient) {
        document.getElementById('intake_patient_id').value = patient.id || '';
        document.getElementById('intake_first_name').value = patient.first_name || '';
        document.getElementById('intake_last_name').value = patient.last_name || '';
        document.getElementById('intake_phone').value = patient.phone || '';
        if (patient.gender && document.getElementById('intake_gender')) {
            document.getElementById('intake_gender').value = patient.gender;
        }

        // Populate Selected Patient Pill
        const fullName = (patient.first_name || '') + ' ' + (patient.last_name || '');
        const nameEl = document.getElementById('card-patient-name');
        if (nameEl) nameEl.textContent = fullName.trim();

        const mrnEl = document.getElementById('card-patient-mrn');
        if (mrnEl) mrnEl.textContent = patient.mrn || 'NO MRN';

        const credit = parseFloat(patient.account_credit || 0);
        const creditBox = document.getElementById('card-patient-credit-badge');
        if (creditBox) {
            if (credit > 0.005) {
                const creditVal = document.getElementById('card-patient-credit-val');
                if (creditVal) creditVal.textContent = '$' + credit.toFixed(2);
                creditBox.classList.remove('hidden');
            } else {
                creditBox.classList.add('hidden');
            }
        }

        const card = document.getElementById('selected-patient-card');
        if (card) card.classList.remove('hidden');

        const modalResults = document.getElementById('modal-search-results');
        if (modalResults) modalResults.classList.add('hidden');

        const searchInput = document.getElementById('modal-patient-search');
        if (searchInput) searchInput.value = '';

        const clearBtn = document.getElementById('clear-modal-search');
        if (clearBtn) clearBtn.classList.add('hidden');

        // Focus doctor selection
        const docSelect = document.getElementById('reception_doc_select');
        if (docSelect) docSelect.focus();
    }

    function clearSelectedPatient() {
        document.getElementById('intake_patient_id').value = '';
        document.getElementById('intake_first_name').value = '';
        document.getElementById('intake_last_name').value = '';
        document.getElementById('intake_phone').value = '';
        const card = document.getElementById('selected-patient-card');
        if (card) card.classList.add('hidden');
        const input = document.getElementById('modal-patient-search');
        if (input) {
            input.value = '';
            input.focus();
        }
    }

    // Dismiss dropdowns when clicked outside
    document.addEventListener('click', function(e) {
        const globalSearchBox = document.getElementById('global-patient-search');
        const globalResults = document.getElementById('global-search-results');
        if (globalResults && !globalResults.contains(e.target) && e.target !== globalSearchBox) {
            globalResults.classList.add('hidden');
        }

        const modalSearchBox = document.getElementById('modal-patient-search');
        const modalResults = document.getElementById('modal-search-results');
        if (modalResults && !modalResults.contains(e.target) && e.target !== modalSearchBox) {
            modalResults.classList.add('hidden');
        }
    });

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
    function updateReceptionFee(sel) {
        const opt = sel.options[sel.selectedIndex];
        const fee = opt ? opt.getAttribute('data-fee') : '10.00';
        if (fee !== null && fee !== undefined && fee !== '') {
            document.getElementById('reception_intake_fee').value = parseFloat(fee).toFixed(2);
        }
        const dept = opt ? opt.getAttribute('data-dept') : '';
        const deptInput = document.getElementById('reception_intake_dept');
        if (deptInput && dept) {
            deptInput.value = dept;
        }
    }
    function setReceptionFee(val) {
        document.getElementById('reception_intake_fee').value = parseFloat(val).toFixed(2);
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
                <p class="text-[10px] text-gray-600">Main Outpatient Clinic • Reception</p>
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

    function executePrintToken() {
        window.print();
    }

    function closePrintRefundModal() {
        const modal = document.getElementById('print-refund-modal');
        if (modal) modal.classList.add('hidden');
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'reception_queue',
                targetSelector: '#reception-queue-tbody',
                intervalMs: 3500,
                notifyOnNew: true
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
