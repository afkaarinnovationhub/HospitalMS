<?php
/**
 * MedCore Systems - Laboratory Categories & Panels Management
 * Standalone directory for managing diagnostic test departments, panels, and specialties.
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
    if ($action === 'add_lab_category') {
        $result = LaboratoryController::handleCreateLabCategory($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'update_lab_category') {
        $result = LaboratoryController::handleUpdateLabCategory($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_lab_category') {
        $result = LaboratoryController::handleDeleteLabCategory($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search Filter
$searchQuery = !empty($_GET['search']) ? sanitizeString($_GET['search']) : '';

$categoriesWithCount = LaboratoryOperation::getLabCategoriesWithCount();
if (!empty($searchQuery)) {
    $categoriesWithCount = array_filter($categoriesWithCount, function ($cat) use ($searchQuery) {
        return stripos($cat['name'], $searchQuery) !== false || stripos($cat['description'] ?? '', $searchQuery) !== false;
    });
}

// Summary KPIs
$totalCategories = count($categoriesWithCount);
$totalTestsLinked = 0;
foreach ($categoriesWithCount as $c) {
    $totalTestsLinked += (int)($c['test_count'] ?? 0);
}

$labKpis = LaboratoryOperation::getLabSummaryKPIs();
$catKPIs = LaboratoryOperation::getLabCatalogKPIs();

$pageTitle = 'Laboratory Categories - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Lab Categories';
$activePage = 'lab_categories';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Laboratory Categories Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Category Action Error</p>
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
                <span class="material-symbols-outlined text-primary text-[28px]">category</span>
                Laboratory Categories
            </h2>
        </div>
        <div class="flex flex-wrap items-center gap-sm w-full sm:w-auto">
            <button type="button" onclick="openAddCategoryModal()" class="flex-1 sm:flex-none px-4 py-2 bg-primary hover:bg-primary-container text-on-primary font-label-md text-xs rounded-xl transition-colors flex items-center justify-center gap-1.5 font-bold shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                Add New Category
            </button>
            <button onclick="window.location.reload();" class="flex-1 sm:flex-none px-3 py-2 border border-outline-variant text-on-surface font-label-md text-xs rounded-xl hover:bg-surface-container transition-colors flex items-center justify-center gap-1.5 font-semibold cursor-pointer shadow-xs" title="Refresh Page">
                <span class="material-symbols-outlined text-[16px]">refresh</span>
            </button>
        </div>
    </div>

    <!-- Summary KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-md mb-lg sm:mb-xl">
        <!-- Metric 1: Total Categories -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Total Categories / Panels</span>
                <div class="w-9 h-9 rounded-full bg-primary-fixed/50 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">category</span>
                </div>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $totalCategories; ?></span>
            </div>
        </div>

        <!-- Metric 2: Total Tests Linked -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Catalog Tests Assigned</span>
                <div class="w-9 h-9 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">science</span>
                </div>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-3xl text-secondary font-bold"><?php echo $totalTestsLinked; ?></span>
            </div>
        </div>

        <!-- Metric 3: Active Status -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 flex flex-col gap-sm shadow-xs">
            <div class="flex justify-between items-start">
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Active Diagnostics Scope</span>
                <div class="w-9 h-9 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">verified</span>
                </div>
            </div>
            <div class="flex items-end gap-sm">
                <span class="font-display-lg text-2xl sm:text-3xl text-on-surface font-bold"><?php echo $totalCategories; ?></span>
            </div>
        </div>
    </div>

    <!-- Categories Directory Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl overflow-hidden shadow-sm flex flex-col">
        <!-- Toolbar & Search -->
        <form method="GET" action="lab_categories.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-bright gap-2 sm:gap-sm">
            <div class="relative flex-1 max-w-md min-w-[220px]">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                <input name="search" value="<?php echo e($searchQuery); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs text-on-surface focus:border-primary outline-none" placeholder="Search category name or description..." type="text">
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                    Filter
                </button>
                <a href="lab_categories.php" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded-lg border border-outline-variant transition-colors flex items-center justify-center" title="Reset Search">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                </a>
            </div>
        </form>

        <!-- Categories Table -->
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant">
                    <tr>
                        <th class="py-3 px-4 font-semibold">Category / Panel Name</th>
                        <th class="py-3 px-4 font-semibold">Diagnostic Scope &amp; Description</th>
                        <th class="py-3 px-4 font-semibold text-center">Linked Tests</th>
                        <th class="py-3 px-4 font-semibold">Created Date</th>
                        <th class="py-3 px-4 font-semibold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="font-body-sm text-xs divide-y divide-outline-variant">
                    <?php if (empty($categoriesWithCount)): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-4xl mb-2 text-outline">category</span>
                                <p class="font-semibold text-sm">No laboratory categories found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categoriesWithCount as $cat): ?>
                            <?php $testCount = (int)($cat['test_count'] ?? 0); ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                            <span class="material-symbols-outlined text-[18px]">category</span>
                                        </div>
                                        <div>
                                            <p class="font-bold text-on-surface text-sm"><?php echo e($cat['name']); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 max-w-sm">
                                    <p class="text-xs text-on-surface-variant"><?php echo e($cat['description'] ?: 'Standard diagnostic laboratory category.'); ?></p>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <a href="lab_catalog.php?category=<?php echo urlencode($cat['name']); ?>" class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary hover:bg-primary/20 transition-colors" title="View tests in this category">
                                        <span class="material-symbols-outlined text-[13px]">science</span>
                                        <span><?php echo $testCount; ?> test<?php echo $testCount === 1 ? '' : 's'; ?></span>
                                    </a>
                                </td>
                                <td class="py-3 px-4 text-on-surface-variant font-mono text-[11px]">
                                    <?php echo !empty($cat['created_at']) ? date('M d, Y', strtotime($cat['created_at'])) : '--'; ?>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <!-- Edit Category Button -->
                                        <button type="button" 
                                                onclick='openEditCategoryModal(<?php echo json_encode($cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-semibold rounded-lg text-xs flex items-center gap-1 cursor-pointer transition-colors shadow-2xs"
                                                title="Edit Category Name &amp; Description">
                                            <span class="material-symbols-outlined text-[15px] text-primary">edit</span>
                                            <span>Edit</span>
                                        </button>

                                        <!-- Delete Category Form -->
                                        <form method="POST" action="lab_categories.php" class="inline" onsubmit="return confirm('Are you sure you want to delete category \'<?php echo e(addslashes($cat['name'])); ?>\'?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_lab_category">
                                            <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                                            <button type="submit" 
                                                    class="p-1 rounded-lg border border-outline-variant hover:bg-error-container text-outline hover:text-error transition-colors cursor-pointer"
                                                    title="Delete Category">
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

<!-- MODAL: Add New Laboratory Category -->
<div id="add-category-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">category</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Add Laboratory Category</h3>
            </div>
            <button type="button" onclick="closeAddCategoryModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="lab_categories.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="add_lab_category">
            <input type="hidden" name="redirect" value="lab_categories.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category Name *</label>
                <input name="name" required type="text" placeholder="e.g. Immunology &amp; Serology" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description / Diagnostic Scope (Optional)</label>
                <textarea name="description" rows="3" placeholder="Brief description of investigations under this category..." class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeAddCategoryModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Save Category
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Laboratory Category -->
<div id="edit-category-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Laboratory Category</h3>
            </div>
            <button type="button" onclick="closeEditCategoryModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="lab_categories.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_lab_category">
            <input type="hidden" id="edit_cat_id" name="category_id" value="">
            <input type="hidden" name="redirect" value="lab_categories.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category Name *</label>
                <input name="name" id="edit_cat_name" required type="text" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description / Diagnostic Scope (Optional)</label>
                <textarea name="description" id="edit_cat_description" rows="3" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeEditCategoryModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddCategoryModal() {
        document.getElementById('add-category-modal').classList.remove('hidden');
    }

    function closeAddCategoryModal() {
        document.getElementById('add-category-modal').classList.add('hidden');
    }

    function openEditCategoryModal(cat) {
        document.getElementById('edit_cat_id').value = cat.id || '';
        document.getElementById('edit_cat_name').value = cat.name || '';
        document.getElementById('edit_cat_description').value = cat.description || '';
        document.getElementById('edit-category-modal').classList.remove('hidden');
    }

    function closeEditCategoryModal() {
        document.getElementById('edit-category-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
