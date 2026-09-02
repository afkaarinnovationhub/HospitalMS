<?php
/**
 * MedCore Systems - Executive Hospital Operations Dashboard
 * 100% Live Real-Time Clinical & Operational Feed from Database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

$pdo = getDBConnection();
$currentUser = getCurrentUser();

// 1. EXECUTIVE HOSPITAL KPIS (LIVE DATABASE METRICS)
$totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
$todayNewPatients = (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()")->fetchColumn();

$waitingQueue = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status IN ('waiting', 'triaged', 'in_lab')")->fetchColumn();
$inConsultation = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status = 'in_consultation'")->fetchColumn();
$completedToday = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn();

$pendingLabOrders = (int)$pdo->query("SELECT COUNT(*) FROM lab_orders WHERE status IN ('pending', 'ordered', 'sample_collected')")->fetchColumn();
$urgentLabOrders = (int)$pdo->query("SELECT COUNT(*) FROM lab_orders WHERE priority = 'urgent' AND status IN ('pending', 'ordered', 'sample_collected')")->fetchColumn();
$completedLabToday = (int)$pdo->query("SELECT COUNT(*) FROM lab_orders WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn();

$pendingPrescriptions = (int)$pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'pending'")->fetchColumn();
$dispensedToday = (int)$pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'dispensed' AND DATE(dispensed_at) = CURDATE()")->fetchColumn();

$unsettledBillsCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE due_amount > 0")->fetchColumn();
$unsettledBillsSum = (float)$pdo->query("SELECT COALESCE(SUM(due_amount), 0) FROM invoices WHERE due_amount > 0")->fetchColumn();

$todayRevenue = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE DATE(paid_at) = CURDATE()")->fetchColumn();
$lowStockMedications = (int)$pdo->query("SELECT COUNT(*) FROM medications WHERE status = 'low_stock' OR current_stock <= 10")->fetchColumn();

// 2. ACTIVE DOCTORS ON DUTY & QUEUE LOADS
$stmtDocs = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.phone, u.professional_title as department, u.consultation_fee,
           (SELECT COUNT(*) FROM patient_queues pq WHERE pq.doctor_id = u.id AND pq.status IN ('waiting', 'triaged', 'in_lab')) as waiting_count,
           (SELECT COUNT(*) FROM patient_queues pq WHERE pq.doctor_id = u.id AND pq.status = 'in_consultation') as in_consult_count,
           (SELECT COUNT(*) FROM patient_queues pq WHERE pq.doctor_id = u.id AND pq.status = 'completed' AND DATE(pq.completed_at) = CURDATE()) as completed_today
    FROM users u
    WHERE u.role = 'doctor' AND u.account_status = 'active'
    ORDER BY u.full_name ASC
");
$doctorsList = $stmtDocs->fetchAll();

// 3. LIVE PATIENT ENCOUNTERS & QUEUE ACTIVITY (TODAY'S STREAM)
$stmtActivity = $pdo->query("
    SELECT pq.*, p.first_name, p.last_name, p.mrn, p.gender, p.dob, p.phone as patient_phone,
           u.full_name as doctor_name, u.professional_title as doctor_dept,
           c.id as consultation_id, c.assessment_diagnosis,
           (SELECT COUNT(*) FROM lab_orders lo WHERE lo.patient_id = p.id AND lo.status IN ('pending', 'ordered', 'sample_collected')) as active_labs,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_mrn = p.mrn AND rx.status = 'pending') as pending_rx,
           (SELECT COUNT(*) FROM invoices inv WHERE inv.patient_id = p.id AND inv.due_amount > 0) as pending_bills
    FROM patient_queues pq
    JOIN patients p ON pq.patient_id = p.id
    LEFT JOIN users u ON pq.doctor_id = u.id
    LEFT JOIN consultations c ON pq.id = c.queue_id
    ORDER BY 
        CASE 
            WHEN pq.status = 'in_consultation' THEN 1
            WHEN pq.priority = 'emergency' AND pq.status != 'completed' THEN 2
            WHEN pq.priority = 'urgent' AND pq.status != 'completed' THEN 3
            WHEN pq.status = 'waiting' THEN 4
            ELSE 5
        END,
        pq.queued_at DESC
    LIMIT 20
");
$liveActivity = $stmtActivity->fetchAll();

$pageTitle = 'Executive Hospital Dashboard - MedCore Systems';
$headerTitle = 'MedCore Hospital Management';
$activePage = 'dashboard';

include __DIR__ . '/../components/header.php';
?>

<!-- Executive Dashboard Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">

    <!-- Top Welcome & Quick Actions Bar -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
        <div>
            <h1 class="font-headline-lg text-xl sm:text-2xl font-bold text-on-surface mt-0.5">
                Hospital Command Center
            </h1>
            <p class="font-body-md text-xs text-on-surface-variant">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Staff Member'); ?></strong> • Today is <?php echo date('l, F d, Y'); ?>
            </p>
        </div>

        <!-- Direct Quick-Action Operations -->
        <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
            <a href="reception.php" class="px-3.5 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-container shadow-xs flex items-center gap-1.5 transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                Quick Check-In
            </a>
            <a href="patient_registration.php" class="px-3 py-2 bg-surface-container border border-outline-variant text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high flex items-center gap-1.5 transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px] text-primary">groups</span>
                Patients Directory
            </a>
            <a href="billing_payments.php" class="px-3 py-2 bg-surface-container border border-outline-variant text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high flex items-center gap-1.5 transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px] text-tertiary">point_of_sale</span>
                Cashier Billing
            </a>
        </div>
    </div>

    <!-- Real-Time Metrics Strip (5 Executive KPIs) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        
        <!-- 1. Total Registered Patients -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:border-primary/40 transition-all">
            <div class="flex justify-between items-start">
                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Patients Directory</span>
                <span class="w-8 h-8 rounded-full bg-primary-fixed/40 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">groups</span>
                </span>
            </div>
            <div class="mt-2">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-on-surface font-mono"><?php echo number_format($totalPatients); ?></span>
                    <span class="text-[11px] font-bold text-secondary font-mono">+<?php echo $todayNewPatients; ?> today</span>
                </div>
                <p class="text-[10px] text-on-surface-variant mt-0.5">Master patient database records</p>
            </div>
        </div>

        <!-- 2. Active Clinical OPD Queue -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:border-primary/40 transition-all">
            <div class="flex justify-between items-start">
                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">OPD Live Queue</span>
                <span class="w-8 h-8 rounded-full bg-secondary-fixed/40 text-secondary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">hourglass_top</span>
                </span>
            </div>
            <div class="mt-2">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-on-surface font-mono"><?php echo $waitingQueue; ?></span>
                    <span class="text-[11px] font-bold text-primary font-mono"><?php echo $inConsultation; ?> in consult</span>
                </div>
                <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo $completedToday; ?> patient(s) seen today</p>
            </div>
        </div>

        <!-- 3. Laboratory Diagnostics Worklist -->
        <div class="bg-surface border <?php echo $urgentLabOrders > 0 ? 'border-error/40 bg-error-container/10' : 'border-outline-variant'; ?> rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:border-primary/40 transition-all">
            <div class="flex justify-between items-start">
                <span class="text-[11px] font-bold <?php echo $urgentLabOrders > 0 ? 'text-error' : 'text-on-surface-variant'; ?> uppercase tracking-wider">Diagnostic Lab</span>
                <span class="w-8 h-8 rounded-full <?php echo $urgentLabOrders > 0 ? 'bg-error-container text-on-error-container' : 'bg-primary-fixed/40 text-primary'; ?> flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">biotech</span>
                </span>
            </div>
            <div class="mt-2">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-on-surface font-mono"><?php echo $pendingLabOrders; ?></span>
                    <?php if ($urgentLabOrders > 0): ?>
                        <span class="text-[11px] font-bold text-error font-mono animate-pulse"><?php echo $urgentLabOrders; ?> URGENT</span>
                    <?php else: ?>
                        <span class="text-[11px] font-bold text-secondary font-mono"><?php echo $completedLabToday; ?> done</span>
                    <?php endif; ?>
                </div>
                <p class="text-[10px] text-on-surface-variant mt-0.5">Pending laboratory test orders</p>
            </div>
        </div>

        <!-- 4. Pharmacy Prescriptions -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:border-primary/40 transition-all">
            <div class="flex justify-between items-start">
                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Pharmacy Rx</span>
                <span class="w-8 h-8 rounded-full bg-tertiary-fixed/40 text-tertiary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">prescriptions</span>
                </span>
            </div>
            <div class="mt-2">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-on-surface font-mono"><?php echo $pendingPrescriptions; ?></span>
                    <span class="text-[11px] font-bold text-secondary font-mono"><?php echo $dispensedToday; ?> dispensed</span>
                </div>
                <p class="text-[10px] text-on-surface-variant mt-0.5">
                    <?php echo $lowStockMedications > 0 ? "<span class='text-amber-600 font-bold'>{$lowStockMedications} low stock</span>" : 'Stock levels optimal'; ?>
                </p>
            </div>
        </div>

        <!-- 5. Today's Collections & Cashier Dues -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 flex flex-col justify-between shadow-xs hover:border-primary/40 transition-all">
            <div class="flex justify-between items-start">
                <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider">Cashier Collections</span>
                <span class="w-8 h-8 rounded-full bg-secondary-fixed/40 text-secondary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px]">payments</span>
                </span>
            </div>
            <div class="mt-2">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-secondary font-mono">$<?php echo number_format($todayRevenue, 2); ?></span>
                </div>
                <p class="text-[10px] text-on-surface-variant mt-0.5">
                    <?php if ($unsettledBillsSum > 0): ?>
                        Due: <strong class="text-error font-mono">$<?php echo number_format($unsettledBillsSum, 2); ?></strong> (<?php echo $unsettledBillsCount; ?> bills)
                    <?php else: ?>
                        All invoices settled
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Main Content Layout (Left: Live Clinical Activity, Right: Doctor Queue & Operations) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- LEFT COLUMN: Live Hospital Encounters Feed (8 Cols) -->
        <div class="lg:col-span-8 bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">personal_injury</span>
                        Live Patient Encounters &amp; Clinical Routing
                    </h3>
                    <p class="text-[11px] text-on-surface-variant">Real-time patient progression across Triage, Doctors, Lab, Pharmacy &amp; Billing.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="queue_management.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-0.5">
                        Full Queue Board &rarr;
                    </a>
                </div>
            </div>

            <!-- Encounters Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                            <th class="py-2.5 px-3">Token &amp; Patient</th>
                            <th class="py-2.5 px-3">MRN Code</th>
                            <th class="py-2.5 px-3">Assigned Doctor</th>
                            <th class="py-2.5 px-3">Arrival Time</th>
                            <th class="py-2.5 px-3 text-center">Status</th>
                            <th class="py-2.5 px-3 text-center">Pending Work</th>
                            <th class="py-2.5 px-3 text-right">Routing Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/60">
                        <?php if (empty($liveActivity)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[40px] text-on-surface-variant/40 block mb-2">airline_seat_recline_normal</span>
                                    <p class="font-bold text-sm text-on-surface">No Patients in Queue Today</p>
                                    <p class="text-xs text-on-surface-variant mt-1">The clinical reception queue is currently clear.</p>
                                    <div class="mt-4 flex justify-center gap-2">
                                        <a href="reception.php" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs">
                                            + Check-In New Patient
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($liveActivity as $row): 
                                $statusBadge = match ($row['status']) {
                                    'in_consultation' => 'bg-primary-fixed text-primary border-primary/40',
                                    'in_lab'          => 'bg-amber-500/20 text-amber-700 border-amber-500/40',
                                    'triaged'         => 'bg-amber-500/20 text-amber-600 border-amber-500/30',
                                    'waiting'         => 'bg-surface-container text-on-surface-variant border-outline-variant',
                                    'completed'       => 'bg-secondary-fixed/40 text-secondary border-secondary/30',
                                    default           => 'bg-surface-container text-on-surface-variant',
                                };

                                $priorityBadge = match ($row['priority']) {
                                    'emergency' => 'bg-error text-on-error font-bold',
                                    'urgent'    => 'bg-amber-500 text-white font-bold',
                                    default     => 'hidden',
                                };
                            ?>
                                <tr class="hover:bg-surface-container-low transition-colors">
                                    <td class="py-2.5 px-3">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono font-bold text-primary bg-primary-fixed/30 px-1.5 py-0.5 rounded text-[10px]">
                                                <?php echo e($row['token_number']); ?>
                                            </span>
                                            <?php if ($row['priority'] !== 'normal'): ?>
                                                <span class="text-[9px] uppercase px-1.5 py-0.2 rounded-full <?php echo $priorityBadge; ?>">
                                                    <?php echo e($row['priority']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="font-bold text-on-surface block mt-0.5">
                                            <?php echo e($row['first_name'] . ' ' . $row['last_name']); ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono text-[11px] text-on-surface-variant">
                                        <?php echo e($row['mrn']); ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="font-semibold text-on-surface block"><?php echo e($row['doctor_name'] ?: 'Unassigned OPD'); ?></span>
                                        <span class="text-[10px] text-on-surface-variant"><?php echo e($row['doctor_dept'] ?: ($row['department'] ?? 'General')); ?></span>
                                    </td>
                                    <td class="py-2.5 px-3 text-on-surface-variant text-[11px]">
                                        <?php echo date('g:i A', strtotime($row['queued_at'])); ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border capitalize inline-flex items-center gap-1 <?php echo $statusBadge; ?>">
                                            <?php if ($row['status'] === 'in_consultation'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-primary animate-ping"></span>
                                            <?php endif; ?>
                                            <?php echo str_replace('_', ' ', e($row['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <div class="flex items-center justify-center gap-1">
                                            <?php if ((int)$row['active_labs'] > 0): ?>
                                                <span class="bg-amber-500/20 text-amber-700 text-[10px] font-bold px-1.5 py-0.5 rounded flex items-center gap-0.5" title="Pending Laboratory Orders">
                                                    <span class="material-symbols-outlined text-[12px]">biotech</span>
                                                    <?php echo (int)$row['active_labs']; ?> Lab
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((int)$row['pending_rx'] > 0): ?>
                                                <span class="bg-primary-fixed/30 text-primary text-[10px] font-bold px-1.5 py-0.5 rounded flex items-center gap-0.5" title="Pending Pharmacy Prescription">
                                                    <span class="material-symbols-outlined text-[12px]">prescriptions</span>
                                                    Rx
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((int)$row['pending_bills'] > 0): ?>
                                                <span class="bg-error-container text-on-error-container text-[10px] font-bold px-1.5 py-0.5 rounded flex items-center gap-0.5" title="Unsettled Billing Dues">
                                                    <span class="material-symbols-outlined text-[12px]">receipt</span>
                                                    Due
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((int)$row['active_labs'] === 0 && (int)$row['pending_rx'] === 0 && (int)$row['pending_bills'] === 0): ?>
                                                <span class="text-[10px] text-on-surface-variant font-mono">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="doctor_dashboard.php" class="px-2 py-1 bg-surface-container hover:bg-primary hover:text-on-primary rounded text-[11px] font-bold transition-all" title="Open Doctor Chart">
                                                Consult
                                            </a>
                                            <?php if ((int)$row['active_labs'] > 0): ?>
                                                <a href="laboratory_dashboard.php" class="px-2 py-1 bg-amber-500/20 text-amber-700 hover:bg-amber-500 hover:text-white rounded text-[11px] font-bold transition-all" title="View Lab Orders">
                                                    Lab
                                                </a>
                                            <?php endif; ?>
                                            <?php if ((int)$row['pending_bills'] > 0): ?>
                                                <a href="billing_payments.php" class="px-2 py-1 bg-tertiary-fixed text-on-tertiary-container hover:bg-tertiary hover:text-on-tertiary rounded text-[11px] font-bold transition-all" title="Collect Payment">
                                                    Pay
                                                </a>
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

        <!-- RIGHT COLUMN: Doctors Queue Load & Department Status (4 Cols) -->
        <div class="lg:col-span-4 space-y-6">

            <!-- 1. DOCTORS ACTIVE QUEUE & DUTY LOAD -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-3">
                <div class="flex items-center justify-between border-b border-outline-variant pb-3">
                    <div>
                        <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[20px]">stethoscope</span>
                            Doctors on Duty &amp; Queue Load
                        </h3>
                        <p class="text-[11px] text-on-surface-variant">Live clinical waiting rooms per physician.</p>
                    </div>
                    <a href="doctors.php" class="text-xs text-primary font-bold hover:underline">Manage &rarr;</a>
                </div>

                <div class="space-y-2.5 max-h-[280px] overflow-y-auto custom-scrollbar">
                    <?php if (empty($doctorsList)): ?>
                        <div class="p-4 text-center text-on-surface-variant text-xs">
                            No doctors registered in the system.
                        </div>
                    <?php else: ?>
                        <?php foreach ($doctorsList as $doc): ?>
                            <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant hover:border-primary/40 transition-all flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0">
                                        <?php echo strtoupper(substr($doc['full_name'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-xs text-on-surface"><?php echo e($doc['full_name']); ?></h4>
                                        <p class="text-[10px] text-on-surface-variant flex items-center gap-1">
                                            <span><?php echo e($doc['department'] ?: 'General OPD'); ?></span>
                                            • <strong class="text-secondary font-mono">$<?php echo number_format((float)($doc['consultation_fee'] ?? 15.0), 2); ?></strong>
                                        </p>
                                    </div>
                                </div>

                                <div class="text-right flex flex-col items-end shrink-0">
                                    <div class="flex items-center gap-1">
                                        <?php if ((int)$doc['waiting_count'] > 0): ?>
                                            <span class="font-bold font-mono text-xs px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-600 border border-amber-500/30">
                                                <?php echo (int)$doc['waiting_count']; ?> waiting
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-secondary font-bold bg-secondary-fixed/30 px-2 py-0.5 rounded-full">
                                                Available
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[10px] text-on-surface-variant mt-0.5">
                                        <?php echo (int)$doc['completed_today']; ?> seen today
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. DEPARTMENTAL OPERATIONS SNAPSHOT -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-3">
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2 border-b border-outline-variant pb-3">
                    <span class="material-symbols-outlined text-secondary text-[20px]">domain</span>
                    Hospital Department Overview
                </h3>

                <div class="grid grid-cols-2 gap-2 text-xs">
                    <!-- Triage -->
                    <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant">
                        <span class="text-[10px] text-on-surface-variant font-semibold uppercase block">OPD Triage</span>
                        <p class="font-bold text-on-surface text-sm mt-0.5"><?php echo $waitingQueue + $inConsultation; ?> Active</p>
                        <span class="text-[10px] text-secondary font-semibold">Vitals recorded</span>
                    </div>

                    <!-- Laboratory -->
                    <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant">
                        <span class="text-[10px] text-on-surface-variant font-semibold uppercase block">Diagnostics Lab</span>
                        <p class="font-bold text-on-surface text-sm mt-0.5"><?php echo $pendingLabOrders; ?> Pending</p>
                        <span class="text-[10px] text-primary font-semibold"><?php echo $completedLabToday; ?> tests done today</span>
                    </div>

                    <!-- Pharmacy -->
                    <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant">
                        <span class="text-[10px] text-on-surface-variant font-semibold uppercase block">Pharmacy POS</span>
                        <p class="font-bold text-on-surface text-sm mt-0.5"><?php echo $pendingPrescriptions; ?> Prescriptions</p>
                        <span class="text-[10px] text-secondary font-semibold"><?php echo $dispensedToday; ?> dispensed today</span>
                    </div>

                    <!-- Finance & Billing -->
                    <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant">
                        <span class="text-[10px] text-on-surface-variant font-semibold uppercase block">Today's Revenue</span>
                        <p class="font-bold text-secondary text-sm mt-0.5 font-mono">$<?php echo number_format($todayRevenue, 2); ?></p>
                        <a href="accounting_dashboard.php" class="text-[10px] text-primary font-bold hover:underline">View P&amp;L &rarr;</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php include __DIR__ . '/../components/footer.php'; ?>
