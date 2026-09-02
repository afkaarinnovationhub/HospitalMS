<?php
/**
 * MedCore Systems - Real Diagnostic Laboratory Dashboard & Worklist
 * Real-time specimen tracking, diagnostic analyzers, result entry, and doctor notification.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/../CONTROLS/LaboratoryController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_LABORATORY, ROLE_DOCTOR]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'collect_specimen') {
        $result = LaboratoryController::handleCollectSpecimen($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'save_results') {
        $result = LaboratoryController::handleSaveLabResults($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'add_lab_test') {
        $result = LaboratoryController::handleCreateLabTest($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'update_lab_test') {
        $result = LaboratoryController::handleUpdateLabTest($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'toggle_lab_test') {
        $result = LaboratoryController::handleToggleLabTestStatus($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_lab_test') {
        $result = LaboratoryController::handleDeleteLabTest($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'add_lab_category') {
        $result = LaboratoryController::handleCreateLabCategory($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Active Tab
$activeTab = !empty($_GET['tab']) ? sanitizeString($_GET['tab']) : 'worklist';

// Worklist Filters & Search
$statusFilter = !empty($_GET['status']) ? sanitizeString($_GET['status']) : 'all';
$panelFilter  = !empty($_GET['panel']) ? sanitizeString($_GET['panel']) : 'all';
$searchQuery  = !empty($_GET['search']) ? sanitizeString($_GET['search']) : '';

// Catalog Filters
$catSearch   = !empty($_GET['cat_search']) ? sanitizeString($_GET['cat_search']) : '';
$catCategory = !empty($_GET['cat_category']) ? sanitizeString($_GET['cat_category']) : 'all';
$catStatus   = !empty($_GET['cat_status']) ? sanitizeString($_GET['cat_status']) : 'all';

$kpis          = LaboratoryOperation::getLabSummaryKPIs();
$worklist      = LaboratoryOperation::getLabWorklist($statusFilter, $panelFilter, $searchQuery);
$catalog       = LaboratoryOperation::getLabTestCatalog();
$catalogList   = LaboratoryOperation::getAllLabCatalogTests($catSearch, $catCategory, $catStatus);
$catKPIs       = LaboratoryOperation::getLabCatalogKPIs();
$labCategories = LaboratoryOperation::getLabCategories();

$pageTitle = 'Laboratory Worklist & Diagnostics - MedCore Systems';
$headerTitle = 'MedCore Management - Laboratory';
$activePage = 'laboratory';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Laboratory Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Laboratory Action Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Success</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-md mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[28px]">biotech</span>
                Laboratory Diagnostics &amp; Specimen Worklist
            </h2>
            <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Laboratory Technologist'); ?></strong> • Real-time specimen collection, diagnostic findings, and clinical verification.
            </p>
        </div>
        <div class="flex flex-wrap gap-sm w-full sm:w-auto">
            <button type="button" onclick="openAddLabTestModal()" class="flex-1 sm:flex-none px-3.5 py-2 bg-secondary hover:bg-on-secondary-container text-on-secondary font-label-md text-xs rounded-xl transition-colors flex items-center justify-center gap-1.5 font-bold shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">add_circle</span>
                Add New Test
            </button>
            <button onclick="window.location.reload();" class="flex-1 sm:flex-none px-3.5 py-2 border border-outline-variant text-on-surface font-label-md text-xs rounded-xl hover:bg-surface-container transition-colors flex items-center justify-center gap-1.5 font-bold cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[16px]">refresh</span>
                Refresh Worklist
            </button>
        </div>
    </div>

    <!-- Segmented Navigation Tabs / Slider -->
    <div class="flex items-center gap-2 p-1.5 bg-surface border border-outline-variant rounded-2xl w-full sm:w-fit mb-lg shadow-xs overflow-x-auto custom-scrollbar">
        <a href="laboratory_dashboard.php?tab=worklist" class="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer <?php echo $activeTab === 'worklist' ? 'bg-primary text-on-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high'; ?>">
            <span class="material-symbols-outlined text-[18px]">biotech</span>
            <span>Diagnostic Worklist &amp; Orders</span>
            <?php 
                $pendingOrdersCount = (int)($kpis['pending_samples'] ?? 0);
                if ($pendingOrdersCount > 0): 
            ?>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $activeTab === 'worklist' ? 'bg-on-primary text-primary' : 'bg-primary/20 text-primary'; ?>">
                    <?php echo $pendingOrdersCount; ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="laboratory_dashboard.php?tab=catalog" class="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer <?php echo $activeTab === 'catalog' ? 'bg-primary text-on-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high'; ?>">
            <span class="material-symbols-outlined text-[18px]">science</span>
            <span>Diagnostic Tests Catalog &amp; Master Pricing</span>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $activeTab === 'catalog' ? 'bg-on-primary text-primary' : 'bg-secondary/20 text-secondary'; ?>">
                <?php echo $catKPIs['total_tests']; ?> Tests
            </span>
        </a>
    </div>

    <?php if ($activeTab === 'worklist'): ?>
        <!-- WORKLIST TAB CONTENT -->

        <!-- Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md mb-lg sm:mb-xl">
            <!-- Metric 1: Pending Samples -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Pending Samples</span>
                    <div class="w-9 h-9 rounded-full bg-primary-fixed/50 text-primary flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">biotech</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $kpis['pending_samples']; ?></span>
                    <span class="font-body-sm text-xs text-on-surface-variant mb-1">Awaiting analysis</span>
                </div>
            </div>

            <!-- Metric 2: Completed Today -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Completed Today</span>
                    <div class="w-9 h-9 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">task_alt</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $kpis['completed_today']; ?></span>
                    <span class="font-body-sm text-xs text-secondary mb-1 flex items-center font-semibold">
                        <span class="material-symbols-outlined text-[15px]">verified</span> Results released
                    </span>
                </div>
            </div>

            <!-- Metric 3: Turnaround Time -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Avg Turnaround</span>
                    <div class="w-9 h-9 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">timer</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $kpis['turnaround_time']; ?> <span class="text-xs font-normal text-on-surface-variant">min</span></span>
                    <span class="font-body-sm text-xs text-secondary mb-1">Fast track</span>
                </div>
            </div>

            <!-- Metric 4: STAT / Urgent Orders -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">STAT / Urgent Orders</span>
                    <div class="w-9 h-9 rounded-full bg-error-container text-on-error-container flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">e911_emergency</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-error font-bold"><?php echo $kpis['stat_orders']; ?></span>
                    <span class="font-body-sm text-xs text-on-surface-variant mb-1">High priority</span>
                </div>
            </div>
        </div>

        <!-- Main Laboratory Worklist Table -->
        <div class="bg-surface border border-outline-variant rounded-2xl overflow-hidden shadow-sm flex flex-col">
            <!-- Table Toolbar & Filters -->
            <form method="GET" action="laboratory_dashboard.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-bright gap-2 sm:gap-sm">
                <input type="hidden" name="tab" value="worklist">
                <div class="flex flex-wrap items-center gap-2 flex-1 max-w-2xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input name="search" value="<?php echo e($searchQuery); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface focus:border-primary outline-none" placeholder="Search order #, patient name, or MRN..." type="text">
                    </div>

                    <select name="panel" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                        <option value="all" <?php echo $panelFilter === 'all' ? 'selected' : ''; ?>>All Diagnostic Panels</option>
                        <option value="Hematology" <?php echo $panelFilter === 'Hematology' ? 'selected' : ''; ?>>Hematology</option>
                        <option value="Parasitology" <?php echo $panelFilter === 'Parasitology' ? 'selected' : ''; ?>>Parasitology (Malaria/Stool)</option>
                        <option value="Biochemistry" <?php echo $panelFilter === 'Biochemistry' ? 'selected' : ''; ?>>Biochemistry &amp; Organ Panels</option>
                        <option value="Serology & Immunology" <?php echo $panelFilter === 'Serology & Immunology' ? 'selected' : ''; ?>>Serology &amp; Widal</option>
                        <option value="Urinalysis" <?php echo $panelFilter === 'Urinalysis' ? 'selected' : ''; ?>>Urinalysis</option>
                        <option value="Radiology & Diagnostics" <?php echo $panelFilter === 'Radiology & Diagnostics' ? 'selected' : ''; ?>>Radiology / X-Ray</option>
                    </select>

                    <select name="status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending Collection</option>
                        <option value="sample_collected" <?php echo $statusFilter === 'sample_collected' ? 'selected' : ''; ?>>Sample Collected</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed / Verified</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                        Filter
                    </button>
                    <a href="laboratory_dashboard.php?tab=worklist" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded-lg border border-outline-variant transition-colors flex items-center justify-center" title="Reset Filters">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                </div>
            </form>

            <!-- Worklist Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[900px]">
                    <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Order # &amp; Priority</th>
                            <th class="py-3 px-4 font-semibold">Patient &amp; MRN</th>
                            <th class="py-3 px-4 font-semibold">Diagnostic Test &amp; Panel</th>
                            <th class="py-3 px-4 font-semibold">Ordering Doctor</th>
                            <th class="py-3 px-4 font-semibold">Billing Status</th>
                            <th class="py-3 px-4 font-semibold">Test Status</th>
                            <th class="py-3 px-4 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="lab-worklist-tbody" class="font-body-sm text-xs divide-y divide-outline-variant">
                        <?php if (empty($worklist)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-outline">biotech</span>
                                    <p class="font-semibold text-sm">No diagnostic laboratory orders found.</p>
                                    <p class="text-xs mt-0.5">When doctors order lab investigations during patient consultations, they will appear here.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($worklist as $order): ?>
                                <?php
                                    $prioClass = 'bg-surface-container text-on-surface';
                                    if ($order['priority'] === 'stat') $prioClass = 'bg-error text-on-error font-bold';
                                    elseif ($order['priority'] === 'urgent') $prioClass = 'bg-error-container text-on-error-container font-bold';

                                    $isPaid = ($order['payment_status'] === 'paid');
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
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-mono font-bold text-primary"><?php echo e($order['order_number']); ?></span>
                                            <span class="text-[9px] uppercase px-1.5 py-0.2 rounded font-bold <?php echo $prioClass; ?>">
                                                <?php echo e($order['priority']); ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo date('M d, g:i A', strtotime($order['created_at'])); ?></p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <a href="patient_profile_michael_chen.php?id=<?php echo (int)$order['patient_id']; ?>" class="font-bold text-on-surface hover:text-primary hover:underline">
                                            <?php echo e($order['patient_name']); ?>
                                        </a>
                                        <p class="text-[11px] text-on-surface-variant font-mono"><?php echo e($order['mrn']); ?> • <?php echo e($order['gender']); ?></p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="font-bold text-on-surface"><?php echo e($order['test_name']); ?></p>
                                        <p class="text-[11px] text-primary"><?php echo e($order['test_category'] ?: 'General Laboratory'); ?> • <span class="font-mono font-semibold text-secondary">$<?php echo number_format((float)$order['test_price'], 2); ?></span></p>
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
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- CATALOG TAB CONTENT -->

        <!-- Catalog Metrics Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md mb-lg sm:mb-xl">
            <!-- Metric 1: Total Catalog Tests -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Total Diagnostic Tests</span>
                    <div class="w-9 h-9 rounded-full bg-primary-fixed/50 text-primary flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">science</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $catKPIs['total_tests']; ?></span>
                    <span class="font-body-sm text-xs text-on-surface-variant mb-1">Catalog items</span>
                </div>
            </div>

            <!-- Metric 2: Active Tests -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Active &amp; Available</span>
                    <div class="w-9 h-9 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">check_circle</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-secondary font-bold"><?php echo $catKPIs['active_tests']; ?></span>
                    <span class="font-body-sm text-xs text-on-surface-variant mb-1">Ready for doctor orders</span>
                </div>
            </div>

            <!-- Metric 3: Inactive / Disabled -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Disabled / Inactive</span>
                    <div class="w-9 h-9 rounded-full bg-surface-container-high text-on-surface-variant flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">block</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface-variant font-bold"><?php echo $catKPIs['inactive_tests']; ?></span>
                    <span class="font-body-sm text-xs text-on-surface-variant mb-1">Hidden from orders</span>
                </div>
            </div>

            <!-- Metric 4: Average Turnaround -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
                <div class="flex justify-between items-start">
                    <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Avg Turnaround Time</span>
                    <div class="w-9 h-9 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">timer</span>
                    </div>
                </div>
                <div class="flex items-end gap-sm">
                    <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $catKPIs['avg_turnaround']; ?> <span class="text-xs font-normal text-on-surface-variant">min</span></span>
                    <span class="font-body-sm text-xs text-secondary mb-1">Average result speed</span>
                </div>
            </div>
        </div>

        <!-- Master Diagnostic Tests Catalog Table -->
        <div class="bg-surface border border-outline-variant rounded-2xl overflow-hidden shadow-sm flex flex-col">
            <!-- Table Toolbar & Filters -->
            <form method="GET" action="laboratory_dashboard.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-bright gap-2 sm:gap-sm">
                <input type="hidden" name="tab" value="catalog">
                <div class="flex flex-wrap items-center gap-2 flex-1 max-w-3xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input name="cat_search" value="<?php echo e($catSearch); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface focus:border-primary outline-none" placeholder="Search test name, code, or department..." type="text">
                    </div>

                    <select name="cat_category" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                        <option value="all" <?php echo $catCategory === 'all' ? 'selected' : ''; ?>>All Categories / Panels</option>
                        <?php foreach ($labCategories as $cat): ?>
                            <option value="<?php echo e($cat['name']); ?>" <?php echo $catCategory === $cat['name'] ? 'selected' : ''; ?>>
                                <?php echo e($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="cat_status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                        <option value="all" <?php echo $catStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $catStatus === 'active' ? 'selected' : ''; ?>>Active Only</option>
                        <option value="inactive" <?php echo $catStatus === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                        Filter
                    </button>
                    <a href="laboratory_dashboard.php?tab=catalog" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded-lg border border-outline-variant transition-colors flex items-center justify-center" title="Reset Filters">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                    <button type="button" onclick="openAddLabCategoryModal()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold text-xs rounded-lg transition-colors flex items-center gap-1 shadow-2xs cursor-pointer">
                        <span class="material-symbols-outlined text-[16px] text-primary">category</span>
                        + Add Category
                    </button>
                    <button type="button" onclick="openAddLabTestModal()" class="px-3 py-1.5 bg-secondary hover:bg-on-secondary-container text-on-secondary font-bold text-xs rounded-lg transition-colors flex items-center gap-1 shadow-xs cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">add</span>
                        + Add Test
                    </button>
                </div>
            </form>

            <!-- Catalog Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[900px]">
                    <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Test Code</th>
                            <th class="py-3 px-4 font-semibold">Diagnostic Test Name &amp; Category</th>
                            <th class="py-3 px-4 font-semibold">Specimen Type</th>
                            <th class="py-3 px-4 font-semibold">Turnaround</th>
                            <th class="py-3 px-4 font-semibold">Normal / Reference Range</th>
                            <th class="py-3 px-4 font-semibold">Standard Fee ($)</th>
                            <th class="py-3 px-4 font-semibold">Status</th>
                            <th class="py-3 px-4 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="font-body-sm text-xs divide-y divide-outline-variant">
                        <?php if (empty($catalogList)): ?>
                            <tr>
                                <td colspan="8" class="py-12 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-outline">science</span>
                                    <p class="font-semibold text-sm">No diagnostic laboratory tests found matching filter.</p>
                                    <p class="text-xs mt-0.5">Click "Add New Test" to register a new investigation into the master catalog.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($catalogList as $t): ?>
                                <?php
                                    $isActive = ((int)$t['is_active'] === 1);
                                    $statusBadge = $isActive 
                                        ? 'bg-secondary-fixed/40 text-on-secondary-fixed-variant border-secondary/30' 
                                        : 'bg-surface-container-high text-on-surface-variant border-outline-variant';
                                ?>
                                <tr class="hover:bg-surface-container-low transition-colors">
                                    <td class="py-3 px-4">
                                        <span class="font-mono font-bold text-primary bg-primary/10 px-2 py-0.5 rounded border border-primary/20">
                                            <?php echo e($t['test_code']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="font-bold text-on-surface text-sm"><?php echo e($t['test_name']); ?></p>
                                        <p class="text-[11px] text-on-surface-variant flex items-center gap-1 mt-0.5">
                                            <span class="material-symbols-outlined text-[13px] text-primary">category</span>
                                            <?php echo e($t['category']); ?>
                                        </p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 rounded-lg bg-surface-container border border-outline-variant text-[11px] font-semibold text-on-surface flex items-center gap-1 w-fit">
                                            <span class="material-symbols-outlined text-[13px] text-error">bloodtype</span>
                                            <?php echo e($t['specimen_type'] ?: 'Venous Blood / Serum'); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-semibold text-on-surface flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[14px] text-tertiary">timer</span>
                                            <?php echo (int)$t['turnaround_minutes']; ?> mins
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 max-w-xs">
                                        <p class="text-xs text-on-surface font-mono truncate" title="<?php echo e($t['normal_range'] ?? ''); ?>">
                                            <?php echo e($t['normal_range'] ?: 'Negative / Normal Reference'); ?>
                                        </p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-mono font-bold text-sm text-secondary">
                                            $<?php echo number_format((float)$t['price'], 2); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="text-[10px] px-2.5 py-0.5 rounded-full font-bold border capitalize <?php echo $statusBadge; ?>">
                                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- Edit Test Button -->
                                            <button type="button" 
                                                    onclick='openEditLabTestModal(<?php echo json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                    class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold rounded-lg text-xs flex items-center gap-1 cursor-pointer transition-colors shadow-2xs"
                                                    title="Edit Test Details & Pricing">
                                                <span class="material-symbols-outlined text-[15px] text-primary">edit</span>
                                                <span>Edit</span>
                                            </button>

                                            <!-- Toggle Status Form -->
                                            <form method="POST" action="laboratory_dashboard.php" class="inline" onsubmit="return confirm('Are you sure you want to <?php echo $isActive ? 'deactivate' : 'activate'; ?> this diagnostic test?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="toggle_lab_test">
                                                <input type="hidden" name="test_id" value="<?php echo (int)$t['id']; ?>">
                                                <button type="submit" 
                                                        class="p-1 rounded-lg border border-outline-variant hover:bg-surface-container transition-colors cursor-pointer text-xs font-semibold <?php echo $isActive ? 'text-error hover:bg-error-container/30' : 'text-secondary hover:bg-secondary-fixed/40'; ?>"
                                                        title="<?php echo $isActive ? 'Deactivate Test' : 'Activate Test'; ?>">
                                                    <span class="material-symbols-outlined text-[16px]">
                                                        <?php echo $isActive ? 'toggle_on' : 'toggle_off'; ?>
                                                    </span>
                                                </button>
                                            </form>

                                            <!-- Delete Test Form -->
                                            <form method="POST" action="laboratory_dashboard.php" class="inline" onsubmit="return confirm('Are you sure you want to delete \'<?php echo e(addslashes($t['test_name'])); ?>\' from the catalog?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_lab_test">
                                                <input type="hidden" name="test_id" value="<?php echo (int)$t['id']; ?>">
                                                <button type="submit" 
                                                        class="p-1 rounded-lg border border-outline-variant hover:bg-error-container text-outline hover:text-error transition-colors cursor-pointer"
                                                        title="Delete Test from Catalog">
                                                    <span class="material-symbols-outlined text-[16px]">delete</span>
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
    <?php endif; ?>
</main>

<!-- MODAL 1: Enter Diagnostic Lab Results -->
<div id="result-entry-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[24px]">assignment_turned_in</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Record Diagnostic Findings</h3>
                    <p class="text-xs text-on-surface-variant">Enter verified laboratory results and technician notes.</p>
                </div>
            </div>
            <button type="button" onclick="closeResultModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="laboratory_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_results">
            <input type="hidden" id="modal_order_id" name="order_id" value="">

            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant space-y-1">
                <p class="text-xs text-on-surface-variant">Patient: <strong id="modal_patient_name" class="text-on-surface font-bold"></strong></p>
                <p class="text-xs text-on-surface-variant">Test Name: <strong id="modal_test_name" class="text-primary font-bold"></strong></p>
                <p class="text-[11px] text-on-surface-variant">Reference Range: <span id="modal_normal_range" class="font-mono text-on-surface"></span></p>
                <p id="modal_doc_notes_container" class="text-[11px] text-on-surface-variant italic pt-1 border-t border-outline-variant/60">
                    Doctor Notes: <span id="modal_doc_notes" class="text-on-surface"></span>
                </p>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Diagnostic Findings &amp; Summary (Natiijada Baaritaanka) *</label>
                <textarea name="result_summary" id="modal_result_summary" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none" rows="3" placeholder="e.g. POSITIVE for Plasmodium falciparum (+2 trophozoites seen). Hemoglobin is reduced at 10.4 g/dL."></textarea>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Detailed Values / Numerical Markers (Optional)</label>
                <textarea name="result_values" id="modal_result_values" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-mono text-on-surface focus:border-primary outline-none resize-none" rows="2" placeholder="e.g. Hb: 10.4 g/dL | WBC: 11,200 | Platelets: 210,000 | RBC: 4.1"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeResultModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-secondary hover:bg-on-secondary-container text-on-secondary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">verified</span>
                    Verify &amp; Release to Doctor
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: View Released Findings -->
<div id="view-result-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[24px]">verified</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Verified Diagnostic Result</h3>
            </div>
            <button type="button" onclick="closeViewModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <div class="space-y-3 text-xs">
            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant">
                <p class="text-xs text-on-surface-variant">Patient: <strong id="view_patient_name" class="text-on-surface font-bold"></strong></p>
                <p class="text-xs text-on-surface-variant mt-0.5">Test: <strong id="view_test_name" class="text-primary font-bold"></strong></p>
                <p class="text-[10px] text-on-surface-variant mt-0.5">Verified by: <span id="view_tech_name" class="text-on-surface font-semibold"></span> on <span id="view_time" class="text-on-surface"></span></p>
            </div>

            <div>
                <p class="font-bold text-[11px] text-on-surface mb-1">Diagnostic Interpretation / Findings:</p>
                <div id="view_summary" class="p-3 bg-surface-container-lowest rounded-lg border border-outline-variant text-on-surface whitespace-pre-line font-medium"></div>
            </div>

            <div id="view_values_box">
                <p class="font-bold text-[11px] text-on-surface mb-1">Detailed Parameter Values:</p>
                <div id="view_values" class="p-2.5 bg-surface-container-lowest rounded-lg border border-outline-variant font-mono text-[11px] text-on-surface whitespace-pre-line"></div>
            </div>
        </div>

        <div class="flex justify-end pt-4 border-t border-outline-variant mt-4">
            <button type="button" onclick="closeViewModal()" class="px-4 py-1.5 bg-surface-container text-on-surface font-bold rounded-lg text-xs hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
        </div>
    </div>
</div>

<!-- MODAL 3: Doctor Clinical Handover (Baseline Info, Vitals & SOAP Notes) -->
<div id="doctor-handover-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-2xl w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto space-y-4">
        <!-- Header -->
        <div class="flex justify-between items-start pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-primary-fixed/40 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">clinical_notes</span>
                </div>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Doctor Clinical Handover &amp; Notes</h3>
                    <p class="text-xs text-on-surface-variant">
                        Patient: <strong id="dh_patient_name" class="text-on-surface font-bold"></strong> 
                        (<span id="dh_mrn" class="font-mono text-primary"></span> • <span id="dh_gender"></span> • <span id="dh_age"></span> yrs)
                    </p>
                </div>
            </div>
            <button type="button" onclick="closeDoctorHandoverModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Ordering Doctor & Test Banner -->
        <div class="p-3 bg-secondary-fixed/20 border border-secondary/30 rounded-xl flex justify-between items-center text-xs">
            <div>
                <p class="text-[11px] text-on-surface-variant">Ordering Doctor:</p>
                <p class="font-bold text-on-surface text-sm" id="dh_doctor_name"></p>
                <p class="text-[10px] text-on-surface-variant" id="dh_doctor_title"></p>
            </div>
            <div class="text-right">
                <p class="text-[11px] text-on-surface-variant">Requested Diagnostic Test:</p>
                <p class="font-bold text-primary text-sm" id="dh_test_name"></p>
            </div>
        </div>

        <!-- Section 1: Baseline Info & Medical History -->
        <div class="bg-surface-container-low border border-outline-variant rounded-xl p-3.5 space-y-2">
            <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5 border-b border-outline-variant/60 pb-1.5">
                <span class="material-symbols-outlined text-[16px] text-primary">person_search</span>
                Patient Baseline Info &amp; Medical History (Xogta Bukaanka ee Dhakhtarka)
            </h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs pt-1">
                <div>
                    <span class="text-[10px] uppercase font-semibold text-on-surface-variant block">Blood Group:</span>
                    <strong id="dh_blood_group" class="text-primary font-bold"></strong>
                </div>
                <div class="sm:col-span-2">
                    <span class="text-[10px] uppercase font-semibold text-error block">Drug Allergies:</span>
                    <strong id="dh_allergies" class="text-error font-semibold"></strong>
                </div>
                <div class="sm:col-span-3">
                    <span class="text-[10px] uppercase font-semibold text-on-surface-variant block">Chronic Medical History:</span>
                    <p id="dh_medical_history" class="text-on-surface font-medium mt-0.5"></p>
                </div>
            </div>
        </div>

        <!-- Section 2: Clinical Examination & Vitals -->
        <div class="bg-surface-container-low border border-outline-variant rounded-xl p-3.5 space-y-2">
            <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5 border-b border-outline-variant/60 pb-1.5">
                <span class="material-symbols-outlined text-[16px] text-primary">vital_signs</span>
                Clinical Physical Examination &amp; Vitals (Calaamadaha Muhiimka ah)
            </h4>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs pt-1">
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Blood Pressure</span>
                    <span id="dh_bp" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Heart Rate</span>
                    <span id="dh_hr" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Temperature</span>
                    <span id="dh_temp" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">SpO2 Oxygen</span>
                    <span id="dh_spo2" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Resp Rate</span>
                    <span id="dh_rr" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Weight</span>
                    <span id="dh_weight" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">Height</span>
                    <span id="dh_height" class="font-bold text-on-surface text-xs">--</span>
                </div>
                <div class="p-2 bg-surface rounded-lg border border-outline-variant text-center">
                    <span class="text-[9px] uppercase font-bold text-on-surface-variant block">BMI</span>
                    <span id="dh_bmi" class="font-bold text-on-surface text-xs">--</span>
                </div>
            </div>
        </div>

        <!-- Section 3: SOAP Clinical Notes & Assessment -->
        <div class="bg-surface-container-low border border-outline-variant rounded-xl p-3.5 space-y-2.5">
            <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5 border-b border-outline-variant/60 pb-1.5">
                <span class="material-symbols-outlined text-[16px] text-primary">clinical_notes</span>
                SOAP Clinical Encounter Notes (Qoraallada Dhakhtarka)
            </h4>

            <div>
                <span class="text-[10px] uppercase font-bold text-on-surface-variant block mb-0.5">Subjective (Chief Complaint &amp; HPI):</span>
                <p id="dh_subjective" class="p-2.5 bg-surface rounded-lg border border-outline-variant text-xs text-on-surface whitespace-pre-line"></p>
            </div>

            <div>
                <span class="text-[10px] uppercase font-bold text-on-surface-variant block mb-0.5">Objective (Physical Examination Findings):</span>
                <p id="dh_objective" class="p-2.5 bg-surface rounded-lg border border-outline-variant text-xs text-on-surface whitespace-pre-line"></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                    <span class="text-[10px] uppercase font-bold text-primary block mb-0.5">Primary Assessment / Diagnosis:</span>
                    <p id="dh_assessment" class="p-2 bg-surface rounded-lg border border-outline-variant text-xs font-bold text-primary"></p>
                </div>
                <div>
                    <span class="text-[10px] uppercase font-bold text-on-surface-variant block mb-0.5">Secondary Diagnosis:</span>
                    <p id="dh_secondary" class="p-2 bg-surface rounded-lg border border-outline-variant text-xs text-on-surface"></p>
                </div>
            </div>

            <div>
                <span class="text-[10px] uppercase font-bold text-secondary block mb-0.5">Doctor Instructions for Lab Technologist:</span>
                <p id="dh_clinical_notes" class="p-2.5 bg-secondary-fixed/20 rounded-lg border border-secondary/30 text-xs text-on-surface italic font-medium"></p>
            </div>
        </div>

        <!-- Footer Actions -->
        <div class="flex justify-end pt-2 border-t border-outline-variant">
            <button type="button" onclick="closeDoctorHandoverModal()" class="px-4 py-2 bg-surface-container text-on-surface font-bold rounded-xl text-xs hover:bg-surface-container-high cursor-pointer shadow-xs">
                Close Clinical Handover
            </button>
        </div>
    </div>
</div>

<script>
    function openDoctorHandoverModal(data) {
        document.getElementById('dh_patient_name').innerText = data.patient_name || '';
        document.getElementById('dh_mrn').innerText = data.mrn || '';
        document.getElementById('dh_gender').innerText = data.gender || '';
        document.getElementById('dh_age').innerText = data.patient_age || 'N/A';
        document.getElementById('dh_blood_group').innerText = data.blood_group || 'Not Recorded';
        document.getElementById('dh_allergies').innerText = data.allergies || 'None Recorded';
        document.getElementById('dh_medical_history').innerText = data.medical_history || 'None Recorded';
        document.getElementById('dh_doctor_name').innerText = data.doctor_name || 'Attending Doctor';
        document.getElementById('dh_doctor_title').innerText = data.doctor_title || 'Clinician';
        document.getElementById('dh_test_name').innerText = data.test_name || '';

        // Vitals
        document.getElementById('dh_bp').innerText = (data.systolic && data.diastolic) ? (data.systolic + '/' + data.diastolic + ' mmHg') : '--';
        document.getElementById('dh_hr').innerText = data.heart_rate ? (data.heart_rate + ' bpm') : '--';
        document.getElementById('dh_temp').innerText = data.temperature ? (data.temperature + ' °C') : '--';
        document.getElementById('dh_spo2').innerText = data.spo2_oxygen ? (data.spo2_oxygen + ' %') : '--';
        document.getElementById('dh_rr').innerText = data.respiratory_rate ? (data.respiratory_rate + ' /min') : '--';
        document.getElementById('dh_weight').innerText = data.weight_kg ? (data.weight_kg + ' kg') : '--';
        document.getElementById('dh_height').innerText = data.height_cm ? (data.height_cm + ' cm') : '--';
        document.getElementById('dh_bmi').innerText = data.bmi ? data.bmi : '--';

        // SOAP
        document.getElementById('dh_subjective').innerText = data.subjective_notes || 'None recorded.';
        document.getElementById('dh_objective').innerText = data.objective_findings || 'None recorded.';
        document.getElementById('dh_assessment').innerText = data.assessment_diagnosis || 'Under Investigation';
        document.getElementById('dh_secondary').innerText = data.secondary_diagnosis || 'None';
        document.getElementById('dh_clinical_notes').innerText = data.clinical_notes || 'Routine clinical investigation.';

        document.getElementById('doctor-handover-modal').classList.remove('hidden');
    }

    function closeDoctorHandoverModal() {
        document.getElementById('doctor-handover-modal').classList.add('hidden');
    }

    function openResultModal(orderId, patientName, testName, normalRange, docNotes) {
        document.getElementById('modal_order_id').value = orderId;
        document.getElementById('modal_patient_name').innerText = patientName;
        document.getElementById('modal_test_name').innerText = testName;
        document.getElementById('modal_normal_range').innerText = normalRange || 'N/A';
        document.getElementById('modal_doc_notes').innerText = docNotes || 'No specific notes.';
        document.getElementById('modal_result_summary').value = '';
        document.getElementById('modal_result_values').value = '';
        document.getElementById('result-entry-modal').classList.remove('hidden');
    }

    function closeResultModal() {
        document.getElementById('result-entry-modal').classList.add('hidden');
    }

    function viewResultModal(patientName, testName, summary, values, techName, timeStr) {
        document.getElementById('view_patient_name').innerText = patientName;
        document.getElementById('view_test_name').innerText = testName;
        document.getElementById('view_summary').innerText = summary;
        document.getElementById('view_tech_name').innerText = techName;
        document.getElementById('view_time').innerText = timeStr;

        const valBox = document.getElementById('view_values_box');
        if (values && values.trim() !== '') {
            document.getElementById('view_values').innerText = values;
            valBox.classList.remove('hidden');
        } else {
            valBox.classList.add('hidden');
        }

        document.getElementById('view-result-modal').classList.remove('hidden');
    }

    function closeViewModal() {
        document.getElementById('view-result-modal').classList.add('hidden');
    }

    function openAddLabCategoryModal() {
        document.getElementById('add-lab-category-modal').classList.remove('hidden');
    }

    function closeAddLabCategoryModal() {
        document.getElementById('add-lab-category-modal').classList.add('hidden');
    }

    function openAddLabTestModal() {
        document.getElementById('add-lab-test-modal').classList.remove('hidden');
    }

    function closeAddLabTestModal() {
        document.getElementById('add-lab-test-modal').classList.add('hidden');
    }

    function openEditLabTestModal(test) {
        document.getElementById('edit_test_id').value = test.id || '';
        document.getElementById('edit_test_code_badge').innerText = test.test_code || 'LAB-TEST';
        document.getElementById('edit_test_name').value = test.test_name || '';
        document.getElementById('edit_category').value = test.category || 'General Diagnostic Laboratory';
        document.getElementById('edit_price').value = parseFloat(test.price || 0).toFixed(2);
        document.getElementById('edit_specimen_type').value = test.specimen_type || 'Venous Blood / Serum';
        document.getElementById('edit_turnaround_minutes').value = test.turnaround_minutes || 30;
        document.getElementById('edit_normal_range').value = test.normal_range || '';
        document.getElementById('edit_is_active').value = (test.is_active == 1) ? '1' : '0';
        document.getElementById('edit-lab-test-modal').classList.remove('hidden');
    }

    function closeEditLabTestModal() {
        document.getElementById('edit-lab-test-modal').classList.add('hidden');
    }
</script>

<!-- MODAL: Add New Diagnostic Lab Category / Panel -->
<div id="add-lab-category-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">category</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Add Laboratory Category</h3>
            </div>
            <button type="button" onclick="closeAddLabCategoryModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="laboratory_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_lab_category">
            <input type="hidden" name="redirect" value="laboratory_dashboard.php?tab=catalog">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category Name *</label>
                <input name="name" required type="text" placeholder="e.g. Endocrinology &amp; Hormones" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description / Diagnostic Scope (Optional)</label>
                <textarea name="description" rows="3" placeholder="Brief description of the diagnostic investigations under this category..." class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeAddLabCategoryModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Save Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Add New Diagnostic Lab Test to Catalog -->
<div id="add-lab-test-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[24px]">add_circle</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Add New Diagnostic Lab Test</h3>
            </div>
            <button type="button" onclick="closeAddLabTestModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="laboratory_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_lab_test">
            <input type="hidden" name="redirect" value="laboratory_dashboard.php?tab=catalog">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Diagnostic Test Name *</label>
                <input name="test_name" required type="text" placeholder="e.g. Hepatitis B Surface Antigen (HBsAg)" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category / Panel</label>
                    <select name="category" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <?php foreach ($labCategories as $cat): ?>
                            <option value="<?php echo e($cat['name']); ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Price (USD $)</label>
                    <input name="price" type="number" step="0.5" min="0" value="10.00" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Specimen Type</label>
                    <input name="specimen_type" type="text" placeholder="e.g. Serum / Blood" value="Venous Blood / Serum" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Turnaround (mins)</label>
                    <input name="turnaround_minutes" type="number" min="5" value="25" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Normal / Reference Range</label>
                <input name="normal_range" type="text" placeholder="e.g. Non-Reactive / Negative" value="Negative / Non-Reactive" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeAddLabTestModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-secondary hover:bg-on-secondary-container text-on-secondary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">add_circle</span>
                    Save Test to Catalog
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Existing Diagnostic Lab Test -->
<div id="edit-lab-test-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Diagnostic Test</h3>
                    <p class="text-xs text-on-surface-variant">Code: <span id="edit_test_code_badge" class="font-mono font-bold text-primary"></span></p>
                </div>
            </div>
            <button type="button" onclick="closeEditLabTestModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="laboratory_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_lab_test">
            <input type="hidden" id="edit_test_id" name="test_id" value="">
            <input type="hidden" name="redirect" value="laboratory_dashboard.php?tab=catalog">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Diagnostic Test Name *</label>
                <input name="test_name" id="edit_test_name" required type="text" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category / Panel</label>
                    <select name="category" id="edit_category" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <?php foreach ($labCategories as $cat): ?>
                            <option value="<?php echo e($cat['name']); ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Price (USD $)</label>
                    <input name="price" id="edit_price" type="number" step="0.5" min="0" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Specimen Type</label>
                    <input name="specimen_type" id="edit_specimen_type" type="text" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Turnaround (mins)</label>
                    <input name="turnaround_minutes" id="edit_turnaround_minutes" type="number" min="1" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Normal / Reference Range</label>
                <input name="normal_range" id="edit_normal_range" type="text" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Availability Status</label>
                <select name="is_active" id="edit_is_active" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
                    <option value="1">Active (Available for Doctor Consultation Orders)</option>
                    <option value="0">Inactive / Disabled (Hidden from Doctor Orders)</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeEditLabTestModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'laboratory_worklist',
                targetSelector: '#lab-worklist-tbody',
                params: {
                    status: '<?php echo e($statusFilter); ?>',
                    panel: '<?php echo e($panelFilter); ?>',
                    search: '<?php echo e($searchQuery); ?>'
                },
                intervalMs: 3500,
                notifyOnNew: true
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
