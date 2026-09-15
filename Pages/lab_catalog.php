<?php


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
    if ($action === 'add_lab_test') {
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

// Catalog Filters
$catSearch   = !empty($_GET['search']) ? sanitizeString($_GET['search']) : '';
$catCategory = !empty($_GET['category']) ? sanitizeString($_GET['category']) : 'all';
$catStatus   = !empty($_GET['status']) ? sanitizeString($_GET['status']) : 'all';

$catalogList   = LaboratoryOperation::getAllLabCatalogTests($catSearch, $catCategory, $catStatus);
$catKPIs       = LaboratoryOperation::getLabCatalogKPIs();
$labCategories = LaboratoryOperation::getLabCategories();
$labKpis       = LaboratoryOperation::getLabSummaryKPIs();

$pageTitle = 'Laboratory Test Catalog - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Test Catalog';
$activePage = 'lab_catalog';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Laboratory Catalog Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Catalog Action Error</p>
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
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-md mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[28px]">science</span>
                Laboratory Test Catalog
            </h2>
        </div>
        <div class="flex flex-wrap items-center gap-sm w-full sm:w-auto">
            <button type="button" onclick="openAddLabTestModal()" class="flex-1 sm:flex-none px-4 py-2 bg-primary hover:bg-primary-container text-on-primary font-label-md text-xs rounded-xl transition-colors flex items-center justify-center gap-1.5 font-bold shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                Add New Test
            </button>
            <button onclick="window.location.reload();" class="flex-1 sm:flex-none px-3 py-2 border border-outline-variant text-on-surface font-label-md text-xs rounded-xl hover:bg-surface-container transition-colors flex items-center justify-center gap-1.5 font-semibold cursor-pointer shadow-xs" title="Refresh Page">
                <span class="material-symbols-outlined text-[16px]">refresh</span>
            </button>
        </div>
    </div>

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
            </div>
        </div>
    </div>

    <!-- Master Diagnostic Tests Catalog Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl overflow-hidden shadow-sm flex flex-col">
        <!-- Table Toolbar & Filters (Redundant '+ Add Test' button removed as requested) -->
        <form method="GET" action="lab_catalog.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-bright gap-2 sm:gap-sm">
            <div class="flex flex-wrap items-center gap-2 flex-1 max-w-3xl">
                <div class="relative flex-1 min-w-[200px]">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                    <input name="search" value="<?php echo e($catSearch); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface focus:border-primary outline-none" placeholder="Search test name, code, or department..." type="text">
                </div>

                <select name="category" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                    <option value="all" <?php echo $catCategory === 'all' ? 'selected' : ''; ?>>All Categories / Panels</option>
                    <?php foreach ($labCategories as $cat): ?>
                        <option value="<?php echo e($cat['name']); ?>" <?php echo $catCategory === $cat['name'] ? 'selected' : ''; ?>>
                            <?php echo e($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg py-1.5 px-3 font-body-sm text-xs text-on-surface outline-none">
                    <option value="all" <?php echo $catStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="active" <?php echo $catStatus === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="inactive" <?php echo $catStatus === 'inactive' ? 'selected' : ''; ?>>Inactive Only</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                    Filter
                </button>
                <a href="lab_catalog.php" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded-lg border border-outline-variant transition-colors flex items-center justify-center" title="Reset Filters">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                </a>
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
                                <td class="py-3 px-4 text-on-surface font-medium">
                                    <?php echo e($t['specimen_type']); ?>
                                </td>
                                <td class="py-3 px-4 font-mono text-on-surface">
                                    <?php echo (int)$t['turnaround_minutes']; ?> min
                                </td>
                                <td class="py-3 px-4 font-mono text-[11px] text-on-surface-variant max-w-xs truncate" title="<?php echo e($t['normal_range']); ?>">
                                    <?php echo e($t['normal_range'] ?: '--'); ?>
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
                                                title="Edit Test Details &amp; Pricing">
                                            <span class="material-symbols-outlined text-[15px] text-primary">edit</span>
                                            <span>Edit</span>
                                        </button>

                                        <!-- Toggle Status Form -->
                                        <form method="POST" action="lab_catalog.php" class="inline" onsubmit="return confirm('Are you sure you want to <?php echo $isActive ? 'deactivate' : 'activate'; ?> this diagnostic test?');">
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
                                        <form method="POST" action="lab_catalog.php" class="inline" onsubmit="return confirm('Are you sure you want to delete \'<?php echo e(addslashes($t['test_name'])); ?>\' from the catalog?');">
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
</main>

<!-- MODAL: Add New Diagnostic Lab Test to Catalog -->
<div id="add-lab-test-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[24px]">add_circle</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Add Diagnostic Lab Test</h3>
            </div>
            <button type="button" onclick="closeAddLabTestModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="lab_catalog.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_lab_test">
            <input type="hidden" name="redirect" value="lab_catalog.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Diagnostic Test Name *</label>
                <input name="test_name" required type="text" placeholder="e.g. Complete Blood Count (CBC)" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category *</label>
                <select name="category" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    <?php if (empty($labCategories)): ?>
                        <option value="" disabled selected>No categories defined</option>
                    <?php else: ?>
                        <?php foreach ($labCategories as $cat): ?>
                            <option value="<?php echo e($cat['name']); ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Price (USD $) *</label>
                    <input name="price" type="number" step="0.5" min="0" value="10.00" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Turnaround Time (mins)</label>
                    <input name="turnaround_minutes" type="number" min="1" value="30" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeAddLabTestModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
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

        <form method="POST" action="lab_catalog.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_lab_test">
            <input type="hidden" id="edit_test_id" name="test_id" value="">
            <input type="hidden" name="redirect" value="lab_catalog.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Diagnostic Test Name *</label>
                <input name="test_name" id="edit_test_name" required type="text" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category *</label>
                <select name="category" id="edit_category" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    <?php if (empty($labCategories)): ?>
                        <option value="" disabled selected>No categories defined</option>
                    <?php else: ?>
                        <?php foreach ($labCategories as $cat): ?>
                            <option value="<?php echo e($cat['name']); ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Price (USD $) *</label>
                    <input name="price" id="edit_price" type="number" step="0.5" min="0" required class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Turnaround Time (mins)</label>
                    <input name="turnaround_minutes" id="edit_turnaround_minutes" type="number" min="1" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Availability Status</label>
                <select name="is_active" id="edit_is_active" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
                    <option value="1">Active (Available for Doctor Orders)</option>
                    <option value="0">Inactive / Disabled (Hidden from Orders)</option>
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
        document.getElementById('edit_category').value = test.category || '';
        document.getElementById('edit_price').value = parseFloat(test.price || 0).toFixed(2);
        document.getElementById('edit_turnaround_minutes').value = test.turnaround_minutes || 30;
        document.getElementById('edit_is_active').value = (test.is_active == 1) ? '1' : '0';
        document.getElementById('edit-lab-test-modal').classList.remove('hidden');
    }

    function closeEditLabTestModal() {
        document.getElementById('edit-lab-test-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
