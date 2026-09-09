<?php
/**
 * MedCore Systems - Real Inventory Management & Supplier Restock
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../CONTROLS/InventoryController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_PHARMACY]);

// Auto-seed default medications & suppliers if table is fresh
try {
    InventoryOperation::seedDefaultInventoryIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS INVENTORY SEED ERROR] ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'restock') {
        $result = InventoryController::handleRestock($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'pay_supplier') {
        $result = InventoryController::handlePaySupplierDebt($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'create_supplier') {
        $result = InventoryController::handleCreateSupplier($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'update_selling_price') {
        $result = InventoryController::handleUpdateSellingPrice($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search & Filter Parameters
$searchQuery    = sanitizeString($_GET['search'] ?? '');
$categoryFilter = sanitizeString($_GET['category'] ?? '');
$statusFilter   = sanitizeString($_GET['status'] ?? '');

// Fetch Live Data
$kpis                 = InventoryOperation::getInventorySummaryKPIs();
$medications          = InventoryOperation::getAllMedications($searchQuery, $categoryFilter, $statusFilter);
$suppliers            = InventoryOperation::getAllSuppliers();
$supplierDebts        = InventoryOperation::getOutstandingSupplierDebts();
$disbursementAccounts = AccountingOperation::getDisbursementAccountsWithBalances();
$reconciliation       = PharmacyOperation::reconcileInventoryAssetWithBatches();
$viewMode             = sanitizeString($_GET['view'] ?? 'summary');
$batchStatusFilter    = sanitizeString($_GET['batch_status'] ?? '');
$allBatches           = ($viewMode === 'batches') ? PharmacyOperation::getAllBatches(!empty($batchStatusFilter) ? $batchStatusFilter : null) : [];
$recentMovements      = ($viewMode === 'movements') ? PharmacyOperation::getBatchMovements() : [];

$pageTitle = 'Inventory Management - MedCore Systems';
$headerTitle = 'MedCore Management - Pharmacy Inventory';
$activePage = 'inventory';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-margin-desktop pb-6 bg-surface-container-low custom-scrollbar">
    <!-- Page Header & Actions -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Inventory Management</h2>
            <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-1">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Inventory Manager'); ?></strong> • Manage pharmacy stock, track expirations, and restock medications.
            </p>
        </div>
        <div class="flex flex-wrap gap-sm w-full sm:w-auto">
            <button type="button" onclick="openAddSupplierModal()" class="flex-1 sm:flex-none justify-center bg-surface border border-outline-variant hover:bg-surface-container-high text-on-surface px-3 sm:px-4 py-2 rounded-lg font-label-md text-xs sm:text-label-md transition-colors flex items-center gap-1.5 font-bold cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[16px] text-primary">domain_add</span>
                + Add Supplier
            </button>
            <button type="button" onclick="openSupplierDebtModal()" class="flex-1 sm:flex-none justify-center bg-surface border border-error text-error px-3 sm:px-4 py-2 rounded-lg font-label-md text-xs sm:text-label-md hover:bg-error-container transition-colors flex items-center gap-2 font-medium cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-sm">receipt_long</span>
                Supplier Debts (<?php echo count($supplierDebts); ?>)
            </button>
            <button type="button" onclick="openRestockModal()" class="flex-1 sm:flex-none justify-center bg-primary text-on-primary px-3 sm:px-4 py-2 rounded-lg font-label-md text-xs sm:text-label-md hover:bg-primary-container hover:text-on-primary-container transition-colors flex items-center gap-2 shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-sm">add_box</span>
                Add / Restock Stock
            </button>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-lg p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Inventory Operation Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-lg p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Success</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Bento Grid Layout for Summary Cards (Real MySQL Live Counts) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 sm:gap-gutter mb-lg">
        <!-- Total Items -->
        <div class="bg-surface p-4 sm:p-lg rounded-xl border border-outline-variant flex flex-col justify-between shadow-sm">
            <div class="flex items-center gap-2 text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-lg">category</span>
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Total Stock Items</span>
            </div>
            <div class="flex items-end justify-between">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-surface font-bold"><?php echo number_format($kpis['total_items']); ?></span>
                <span class="text-secondary font-label-md text-[11px] sm:text-label-md bg-secondary-fixed text-on-secondary-fixed px-2 py-0.5 sm:py-1 rounded font-semibold">Active in Catalog</span>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <div class="bg-error-container p-4 sm:p-lg rounded-xl border border-error/20 flex flex-col justify-between shadow-sm">
            <div class="flex items-center gap-2 text-on-error-container mb-2">
                <span class="material-symbols-outlined text-lg">warning</span>
                <span class="font-label-md text-xs text-on-error-container uppercase tracking-wider font-semibold">Low / Out of Stock</span>
            </div>
            <div class="flex items-end justify-between">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-error-container font-bold"><?php echo number_format($kpis['low_stock'] + $kpis['out_of_stock']); ?></span>
                <a href="?status=low_stock" class="text-error font-label-md text-xs sm:text-label-md underline cursor-pointer font-bold">Filter Items</a>
            </div>
        </div>

        <!-- Expiring Soon -->
        <div class="bg-tertiary-fixed p-4 sm:p-lg rounded-xl border border-tertiary/20 flex flex-col justify-between shadow-sm">
            <div class="flex items-center gap-2 text-on-tertiary-fixed mb-2">
                <span class="material-symbols-outlined text-lg">event_busy</span>
                <span class="font-label-md text-xs text-on-tertiary-fixed uppercase tracking-wider font-semibold">Expiring &lt; 30 Days</span>
            </div>
            <div class="flex items-end justify-between">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-tertiary-fixed font-bold"><?php echo number_format($kpis['expiring_soon']); ?></span>
                <span class="text-tertiary font-label-md text-xs sm:text-label-md font-bold">Priority Batches</span>
            </div>
        </div>

        <!-- Supplier Payables / Debts -->
        <div class="bg-surface p-4 sm:p-lg rounded-xl border border-outline-variant flex flex-col justify-between shadow-sm">
            <div class="flex items-center gap-2 text-on-surface-variant mb-2">
                <span class="material-symbols-outlined text-lg">account_balance_wallet</span>
                <span class="font-label-md text-xs text-on-surface-variant uppercase tracking-wider font-semibold">Supplier Debt Total</span>
            </div>
            <div class="flex items-end justify-between">
                <span class="font-display-lg text-2xl sm:text-display-lg text-on-surface font-bold">$<?php echo number_format($kpis['total_supplier_debt'], 2); ?></span>
                <button type="button" onclick="openSupplierDebtModal()" class="text-primary font-label-md text-xs sm:text-label-md font-semibold underline cursor-pointer">Settle Debts</button>
            </div>
        </div>
    </div>

    <!-- GL Account 1200 Inventory Reconciliation Status -->
    <div class="mb-lg p-3.5 sm:p-4 rounded-xl <?php echo $reconciliation['is_reconciled'] ? 'bg-secondary-fixed/30 border border-secondary/40 text-on-secondary-fixed-variant' : 'bg-error-container border border-error/40 text-on-error-container'; ?> flex flex-col md:flex-row md:items-center justify-between gap-3 shadow-xs">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined <?php echo $reconciliation['is_reconciled'] ? 'text-secondary' : 'text-error'; ?> text-[24px] shrink-0 mt-0.5">
                <?php echo $reconciliation['is_reconciled'] ? 'verified' : 'gpp_bad'; ?>
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-bold text-xs sm:text-sm">FIFO Batch Ledger &amp; GL Account 1200 Reconciliation</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $reconciliation['is_reconciled'] ? 'bg-secondary text-on-secondary' : 'bg-error text-on-error'; ?>">
                        <?php echo $reconciliation['is_reconciled'] ? '100% In-Sync' : 'Discrepancy Detected'; ?>
                    </span>
                </div>
                <p class="text-[11px] sm:text-xs opacity-90 mt-0.5">
                    Active Batches Valuation: <strong>$<?php echo number_format((float)$reconciliation['batch_inventory_valuation'], 2); ?></strong>
                    &bull; GL Pharmacy Inventory Asset (1200): <strong>$<?php echo number_format((float)$reconciliation['gl_inventory_balance'], 2); ?></strong>
                    <?php if (!$reconciliation['is_reconciled']): ?>
                        &bull; Variance: <strong class="text-error font-bold">$<?php echo number_format(abs((float)$reconciliation['discrepancy']), 2); ?></strong>
                    <?php else: ?>
                        &bull; Variance: <strong class="text-secondary font-bold">$0.00</strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1 bg-surface/80 border border-outline-variant px-2.5 py-1 rounded-lg text-[11px] font-semibold text-on-surface">
                <span class="material-symbols-outlined text-[14px] text-primary">swap_vert</span>
                FIFO Option A: Expiry ASC &rarr; Received ASC
            </span>
        </div>
    </div>

    <!-- View Switcher Tabs -->
    <div class="flex items-center gap-2 mb-md border-b border-outline-variant pb-2">
        <a href="inventory_management.php?view=summary" class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?php echo ($viewMode === 'summary') ? 'bg-primary text-on-primary shadow-xs' : 'bg-surface text-on-surface-variant hover:bg-surface-container-high border border-outline-variant'; ?>">
            <span class="material-symbols-outlined text-[16px]">inventory_2</span>
            Medication Summary
        </a>
        <a href="inventory_management.php?view=batches" class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?php echo ($viewMode === 'batches') ? 'bg-primary text-on-primary shadow-xs' : 'bg-surface text-on-surface-variant hover:bg-surface-container-high border border-outline-variant'; ?>">
            <span class="material-symbols-outlined text-[16px]">layers</span>
            FIFO Batches &amp; Expiry
        </a>
        <a href="inventory_management.php?view=movements" class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 <?php echo ($viewMode === 'movements') ? 'bg-primary text-on-primary shadow-xs' : 'bg-surface text-on-surface-variant hover:bg-surface-container-high border border-outline-variant'; ?>">
            <span class="material-symbols-outlined text-[16px]">history</span>
            Stock Movement Audit Log
        </a>
    </div>

    <!-- VIEW 1: MEDICATION SUMMARY VIEW -->
    <?php if ($viewMode === 'summary'): ?>
    <div class="bg-surface rounded-xl border border-outline-variant overflow-hidden flex flex-col shadow-sm">
        <!-- Table Filters / Toolbar -->
        <form method="GET" action="inventory_management.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
            <input type="hidden" name="view" value="summary">
            <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto flex-1 max-w-xl">
                <div class="relative flex-1 min-w-[200px]">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                    <input name="search" value="<?php echo e($searchQuery); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none" placeholder="Search by medicine name, SKU, or generic..." type="text">
                </div>
                <select name="category" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-md px-3 py-1.5 font-body-sm text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none">
                    <option value="">All Categories</option>
                    <option value="Antibiotics" <?php echo $categoryFilter === 'Antibiotics' ? 'selected' : ''; ?>>Antibiotics</option>
                    <option value="Analgesics" <?php echo $categoryFilter === 'Analgesics' ? 'selected' : ''; ?>>Analgesics</option>
                    <option value="Cardiovascular" <?php echo $categoryFilter === 'Cardiovascular' ? 'selected' : ''; ?>>Cardiovascular</option>
                    <option value="Respiratory" <?php echo $categoryFilter === 'Respiratory' ? 'selected' : ''; ?>>Respiratory</option>
                    <option value="Endocrinology" <?php echo $categoryFilter === 'Endocrinology' ? 'selected' : ''; ?>>Endocrinology</option>
                    <option value="Gastroenterology" <?php echo $categoryFilter === 'Gastroenterology' ? 'selected' : ''; ?>>Gastroenterology</option>
                </select>
                <select name="status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-md px-3 py-1.5 font-body-sm text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none">
                    <option value="">Status: All</option>
                    <option value="in_stock" <?php echo $statusFilter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                    <option value="low_stock" <?php echo $statusFilter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out_of_stock" <?php echo $statusFilter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                    Filter
                </button>
                <a href="inventory_management.php?view=summary" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant transition-colors flex items-center justify-center cursor-pointer" title="Reset Filters">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                </a>
            </div>
        </form>

        <!-- Live MySQL Medication Table -->
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead class="bg-surface-container-low font-label-md text-xs text-on-surface-variant sticky top-0">
                    <tr>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Medicine Name</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">SKU / Code</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Category</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold w-40 sm:w-48">Stock Level</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Nearest Expiry</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right" title="Next batch to dispense — cost (FIFO priority sequence)">Cost Price (Next Batch)</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right" title="Medication-level retail selling price for patient billing">Selling Price</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-center">Status</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-center">Restock</th>
                    </tr>
                </thead>
                <tbody class="font-body-sm text-xs divide-y divide-outline-variant text-on-surface">
                    <?php if (empty($medications)): ?>
                        <tr>
                            <td colspan="9" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl mb-1 text-outline">search_off</span>
                                <p class="font-semibold">No medications found matching your filter criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($medications as $med): ?>
                            <?php
                                $stock = (int)$med['current_stock'];
                                $minAlert = (int)$med['min_stock_alert'];
                                $stockPercentage = min(100, (int)(($stock / max(1, $minAlert * 3)) * 100));
                                
                                if ($stock <= 0) {
                                    $statusBadge = '<span class="inline-block bg-error text-on-error px-2 py-0.5 rounded-full font-label-md text-[10px] font-bold">Out of Stock</span>';
                                    $barColor = 'bg-error';
                                } elseif ($stock <= $minAlert) {
                                    $statusBadge = '<span class="inline-block bg-error-container text-on-error-container px-2 py-0.5 rounded-full font-label-md text-[10px] font-bold">Low Stock</span>';
                                    $barColor = 'bg-error';
                                } else {
                                    $statusBadge = '<span class="inline-block bg-secondary-fixed text-on-secondary-fixed-variant px-2 py-0.5 rounded-full font-label-md text-[10px] font-bold">In Stock</span>';
                                    $barColor = 'bg-secondary';
                                }

                                $expiryStr = !empty($med['nearest_expiry']) ? date('M d, Y', strtotime($med['nearest_expiry'])) : 'No Active Batch';
                                $nextBatchCost = (float)($med['next_fifo_cost'] ?? $med['cost_price']);
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors group">
                                <td class="py-2.5 px-3 sm:px-4">
                                    <div class="font-bold text-on-surface"><?php echo e($med['name']); ?></div>
                                    <div class="text-on-surface-variant text-[11px]"><?php echo e($med['dosage_form'] ?: 'Unit Form'); ?></div>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 font-mono text-on-surface-variant"><?php echo e($med['med_code']); ?></td>
                                <td class="py-2.5 px-3 sm:px-4"><?php echo e($med['category']); ?></td>
                                <td class="py-2.5 px-3 sm:px-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-full bg-surface-variant rounded-full h-2 overflow-hidden">
                                            <div class="<?php echo $barColor; ?> h-2 rounded-full" style="width: <?php echo $stockPercentage; ?>%"></div>
                                        </div>
                                        <span class="text-[11px] font-bold w-10 text-right <?php echo ($stock <= $minAlert) ? 'text-error' : 'text-on-surface'; ?>">
                                            <?php echo number_format($stock); ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 font-medium"><?php echo e($expiryStr); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right text-on-surface-variant font-mono" title="Next batch to dispense — FIFO cost">$<?php echo number_format($nextBatchCost, 2); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right font-bold text-on-surface font-mono" title="Medication-level retail selling price">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <span>$<?php echo number_format((float)$med['unit_price'], 2); ?></span>
                                        <button type="button" onclick="openEditPriceModal(<?php echo (int)$med['id']; ?>, '<?php echo e(addslashes($med['name'])); ?>', <?php echo (float)$med['unit_price']; ?>)" class="text-on-surface-variant/60 hover:text-primary p-0.5 rounded cursor-pointer transition-colors" title="Change Retail Selling Price">
                                            <span class="material-symbols-outlined text-[15px]">edit</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 text-center">
                                    <?php echo $statusBadge; ?>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" onclick="prepareRestockForMed(<?php echo (int)$med['id']; ?>, '<?php echo e(addslashes($med['name'])); ?>', <?php echo (float)$med['cost_price']; ?>, <?php echo (float)$med['unit_price']; ?>)" class="text-primary hover:bg-primary-fixed p-1.5 rounded transition-colors cursor-pointer" title="Restock this medication">
                                            <span class="material-symbols-outlined text-[18px]">add_shopping_cart</span>
                                        </button>
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

    <!-- VIEW 2: FIFO BATCHES & EXPIRY VIEW -->
    <?php if ($viewMode === 'batches'): ?>
    <div class="bg-surface rounded-xl border border-outline-variant overflow-hidden flex flex-col shadow-sm">
        <!-- Batches Filters / Toolbar -->
        <form method="GET" action="inventory_management.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
            <input type="hidden" name="view" value="batches">
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-primary text-[18px]">layers</span>
                    FIFO Batch Master Ledger
                </span>
                <select name="batch_status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-md px-3 py-1.5 font-body-sm text-xs text-on-surface focus:border-primary outline-none">
                    <option value="">All Batch Statuses</option>
                    <option value="active" <?php echo $batchStatusFilter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="depleted" <?php echo $batchStatusFilter === 'depleted' ? 'selected' : ''; ?>>Depleted (Sold Out)</option>
                    <option value="expired" <?php echo $batchStatusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="written_off" <?php echo $batchStatusFilter === 'written_off' ? 'selected' : ''; ?>>Written Off</option>
                </select>
            </div>
            <div class="flex gap-2">
                <a href="inventory_management.php?view=batches" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant transition-colors flex items-center justify-center cursor-pointer" title="Reset Filters">
                    <span class="material-symbols-outlined text-sm">refresh</span>
                </a>
            </div>
        </form>

        <!-- FIFO Batches Table -->
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[850px]">
                <thead class="bg-surface-container-low font-label-md text-xs text-on-surface-variant sticky top-0">
                    <tr>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">FIFO Rank</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Batch #</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Medication</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Supplier</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Received Date</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Expiry &amp; Countdown</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold w-36">Remaining / Total</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right">Unit Cost</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right">Batch Valuation</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="font-body-sm text-xs divide-y divide-outline-variant text-on-surface">
                    <?php if (empty($allBatches)): ?>
                        <tr>
                            <td colspan="10" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl mb-1 text-outline">layers_clear</span>
                                <p class="font-semibold">No medicine batches found.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                            $fifoCounter = 1;
                            foreach ($allBatches as $b): 
                                $days = (int)($b['days_to_expiry'] ?? 0);
                                if ($days < 0) {
                                    $expiryBadge = '<span class="inline-flex items-center gap-1 bg-error text-on-error px-2 py-0.5 rounded-full text-[10px] font-bold">Expired (' . abs($days) . 'd ago)</span>';
                                } elseif ($days <= 30) {
                                    $expiryBadge = '<span class="inline-flex items-center gap-1 bg-amber-500/20 text-amber-700 border border-amber-300 dark:border-amber-700 px-2 py-0.5 rounded-full text-[10px] font-bold"><span class="material-symbols-outlined text-[12px]">warning</span>' . $days . 'd left</span>';
                                } elseif ($days <= 90) {
                                    $expiryBadge = '<span class="inline-flex items-center gap-1 bg-secondary-fixed/40 text-on-secondary-fixed-variant px-2 py-0.5 rounded-full text-[10px] font-semibold">' . $days . 'd left</span>';
                                } else {
                                    $expiryBadge = '<span class="inline-flex items-center gap-1 bg-surface-container-high text-on-surface px-2 py-0.5 rounded-full text-[10px]">' . date('M d, Y', strtotime($b['expiry_date'])) . '</span>';
                                }

                                $status = $b['status'];
                                if ($status === 'active') {
                                    $statusTag = '<span class="px-2 py-0.5 bg-secondary-fixed text-on-secondary-fixed-variant rounded-full text-[10px] font-bold">Active</span>';
                                    $rankTag = '<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary text-on-primary font-bold text-[11px] shadow-2xs">#' . $fifoCounter++ . '</span>';
                                } elseif ($status === 'depleted') {
                                    $statusTag = '<span class="px-2 py-0.5 bg-surface-container-high text-on-surface-variant rounded-full text-[10px] font-bold">Depleted</span>';
                                    $rankTag = '<span class="text-on-surface-variant text-[11px]">—</span>';
                                } elseif ($status === 'expired') {
                                    $statusTag = '<span class="px-2 py-0.5 bg-error text-on-error rounded-full text-[10px] font-bold">Expired</span>';
                                    $rankTag = '<span class="text-error text-[11px] font-bold">EXP</span>';
                                } else {
                                    $statusTag = '<span class="px-2 py-0.5 bg-tertiary-fixed text-on-tertiary-fixed rounded-full text-[10px] font-bold">' . e(ucfirst($status)) . '</span>';
                                    $rankTag = '<span class="text-on-surface-variant text-[11px]">—</span>';
                                }

                                $rem = (int)$b['quantity_remaining'];
                                $tot = max(1, (int)$b['quantity_received']);
                                $pct = min(100, (int)(($rem / $tot) * 100));
                                $unitCost = (float)($b['unit_cost'] > 0 ? $b['unit_cost'] : $b['cost_price']);
                                $batchVal = (float)$b['batch_value'];
                        ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2.5 px-3 sm:px-4 text-center"><?php echo $rankTag; ?></td>
                                <td class="py-2.5 px-3 sm:px-4 font-mono font-bold text-primary"><?php echo e($b['batch_number']); ?></td>
                                <td class="py-2.5 px-3 sm:px-4">
                                    <div class="font-bold text-on-surface"><?php echo e($b['medication_name']); ?></div>
                                    <div class="text-on-surface-variant text-[11px]"><?php echo e($b['med_code']); ?> &bull; <?php echo e($b['dosage_form'] ?: 'Unit'); ?></div>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 text-on-surface-variant"><?php echo e($b['supplier_name'] ?? 'Direct Restock'); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 font-mono text-[11px]"><?php echo date('Y-m-d', strtotime($b['received_date'])); ?></td>
                                <td class="py-2.5 px-3 sm:px-4"><?php echo $expiryBadge; ?></td>
                                <td class="py-2.5 px-3 sm:px-4">
                                    <div class="flex items-center justify-between text-[11px] font-bold mb-1">
                                        <span class="<?php echo ($rem === 0) ? 'text-on-surface-variant' : 'text-on-surface'; ?>"><?php echo number_format($rem); ?></span>
                                        <span class="text-on-surface-variant font-normal">/ <?php echo number_format($tot); ?></span>
                                    </div>
                                    <div class="w-full bg-surface-variant rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-primary h-1.5 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 sm:px-4 text-right font-mono text-on-surface-variant">$<?php echo number_format($unitCost, 2); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right font-mono font-bold text-on-surface">$<?php echo number_format($batchVal, 2); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-center"><?php echo $statusTag; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- VIEW 3: STOCK MOVEMENT AUDIT LOG VIEW -->
    <?php if ($viewMode === 'movements'): ?>
    <div class="bg-surface rounded-xl border border-outline-variant overflow-hidden flex flex-col shadow-sm">
        <div class="p-3 sm:p-md border-b border-outline-variant flex justify-between items-center bg-surface-bright">
            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-primary text-[18px]">history</span>
                Immutable Stock Movement &amp; FIFO Costing Audit Log
            </span>
            <a href="inventory_management.php?view=movements" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant transition-colors flex items-center justify-center cursor-pointer" title="Refresh Log">
                <span class="material-symbols-outlined text-sm">refresh</span>
            </a>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse min-w-[900px]">
                <thead class="bg-surface-container-low font-label-md text-xs text-on-surface-variant sticky top-0">
                    <tr>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Date &amp; Time</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Batch #</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Medication</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Movement Type</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right">Quantity</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right">Unit Cost</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold text-right">Total Impact</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Reference</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Operator</th>
                        <th class="py-3 px-3 sm:px-4 border-b border-outline-variant font-semibold">Notes</th>
                    </tr>
                </thead>
                <tbody class="font-body-sm text-xs divide-y divide-outline-variant text-on-surface">
                    <?php if (empty($recentMovements)): ?>
                        <tr>
                            <td colspan="10" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl mb-1 text-outline">manage_search</span>
                                <p class="font-semibold">No stock movements recorded yet.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentMovements as $m): 
                            $type = $m['movement_type'];
                            if ($type === 'purchase') {
                                $typeBadge = '<span class="px-2 py-0.5 bg-secondary-fixed text-on-secondary-fixed-variant rounded-full text-[10px] font-bold">+ Restock</span>';
                                $qtyDisplay = '<span class="font-bold text-secondary">+' . number_format((int)$m['quantity']) . '</span>';
                            } elseif ($type === 'sale_dispense') {
                                $typeBadge = '<span class="px-2 py-0.5 bg-primary-fixed text-on-primary-fixed rounded-full text-[10px] font-bold">- Dispense</span>';
                                $qtyDisplay = '<span class="font-bold text-primary">-' . number_format((int)$m['quantity']) . '</span>';
                            } elseif ($type === 'expired_writeoff') {
                                $typeBadge = '<span class="px-2 py-0.5 bg-error text-on-error rounded-full text-[10px] font-bold">Write-Off</span>';
                                $qtyDisplay = '<span class="font-bold text-error">-' . number_format((int)$m['quantity']) . '</span>';
                            } else {
                                $typeBadge = '<span class="px-2 py-0.5 bg-tertiary-fixed text-on-tertiary-fixed rounded-full text-[10px] font-bold">' . e(ucfirst($type)) . '</span>';
                                $qtyDisplay = '<span class="font-bold">' . number_format((int)$m['quantity']) . '</span>';
                            }
                        ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2.5 px-3 sm:px-4 font-mono text-[11px]"><?php echo date('Y-m-d H:i', strtotime($m['created_at'])); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 font-mono font-bold text-primary"><?php echo e($m['batch_number']); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 font-semibold text-on-surface"><?php echo e($m['medication_name']); ?></td>
                                <td class="py-2.5 px-3 sm:px-4"><?php echo $typeBadge; ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right"><?php echo $qtyDisplay; ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right font-mono text-on-surface-variant">$<?php echo number_format((float)$m['unit_cost'], 2); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-right font-mono font-bold text-on-surface">$<?php echo number_format((float)$m['total_cost'], 2); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 font-mono text-xs text-primary"><?php echo e($m['reference_transaction_id'] ?: '—'); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-on-surface-variant"><?php echo e($m['user_name'] ?? 'System'); ?></td>
                                <td class="py-2.5 px-3 sm:px-4 text-on-surface-variant text-[11px] max-w-xs truncate" title="<?php echo e($m['notes']); ?>"><?php echo e($m['notes'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- MODAL 1: Add Stock / Restock from Supplier -->
<div id="restock-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-xl w-full max-h-[90vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[26px]">inventory_2</span>
                <h3 class="font-headline-sm text-lg font-bold text-on-surface">Add Stock / Restock from Supplier</h3>
            </div>
            <button type="button" onclick="closeRestockModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[22px]">close</span>
            </button>
        </div>

        <form id="restock-form" method="POST" action="inventory_management.php" class="space-y-4" onsubmit="return handleRestockSubmit(event)">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="restock">
            <input type="hidden" name="confirm_price_update" id="modal-confirm-price-update" value="0">

            <!-- 1. Medication Selection -->
            <div>
                <label class="block font-semibold text-xs text-on-surface mb-1">Medication *</label>
                <select id="modal-med-select" name="medication_id" onchange="toggleNewMedFields()" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                    <option value="">-- Choose Existing Medication or Add New --</option>
                    <option value="0">+ Add Brand New Medication</option>
                    <?php foreach ($medications as $m): ?>
                        <option value="<?php echo (int)$m['id']; ?>" data-cost="<?php echo (float)$m['cost_price']; ?>" data-price="<?php echo (float)$m['unit_price']; ?>">
                            <?php echo e($m['name']); ?> (<?php echo e($m['med_code']); ?>) — In Stock: <?php echo (int)$m['current_stock']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- New Medication Form Fields (Hidden by default) -->
            <div id="new-med-fields" class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant/80 hidden space-y-3">
                <p class="font-bold text-xs text-primary">New Medication Details</p>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Medicine Name *</label>
                        <input id="new-med-name" name="new_med_name" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="e.g. Ciprofloxacin 500mg" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Category *</label>
                        <input name="category" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="e.g. Antibiotics" type="text" value="Antibiotics">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Dosage Form</label>
                        <input name="dosage_form" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="e.g. Tablet, 100/box" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Min Stock Alert</label>
                        <input name="min_stock_alert" class="w-full border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="20" type="number" value="20">
                    </div>
                </div>
            </div>

            <!-- 2. Supplier Selection -->
            <div>
                <label class="block font-semibold text-xs text-on-surface mb-1">Supplier / Vendor *</label>
                <select id="modal-supplier-select" name="supplier_id" onchange="toggleNewSupplierFields()" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                    <option value="">-- Choose Supplier --</option>
                    <option value="0">+ Add New Supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['name']); ?> (<?php echo e($s['phone']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- New Supplier Form Fields -->
            <div id="new-supplier-fields" class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant/80 hidden space-y-3">
                <p class="font-bold text-xs text-primary">New Supplier Details</p>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Supplier Name *</label>
                        <input name="new_supplier_name" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="e.g. MedLink Pharma" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Phone Number *</label>
                        <input name="supplier_phone" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface" placeholder="e.g. (555) 019-9988" type="text">
                    </div>
                </div>
            </div>

            <!-- 3. Batch, Expiry, Quantity & Costs -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-xs text-on-surface mb-1">Batch Number</label>
                    <input id="modal-batch-number" name="batch_number" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-mono text-on-surface" placeholder="e.g. BT-9942" type="text">
                </div>
                <div>
                    <label class="block font-semibold text-xs text-on-surface mb-1">Expiry Date *</label>
                    <input id="modal-expiry-date" name="expiry_date" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface" type="date" value="<?php echo date('Y-m-d', strtotime('+2 years')); ?>">
                </div>
                <div>
                    <label class="block font-semibold text-xs text-on-surface mb-1">Quantity Received *</label>
                    <input id="modal-qty" name="quantity" required oninput="calcRestockFinancials()" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-bold text-on-surface" type="number" min="1" placeholder="e.g. 50">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-xs text-on-surface mb-1">Cost Price ($ per unit) *</label>
                    <input id="modal-cost-price" name="cost_price" required step="0.01" oninput="calcRestockFinancials()" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-semibold" type="number" placeholder="0.00">
                </div>
                <div>
                    <label class="block font-semibold text-xs text-on-surface mb-1 flex items-center justify-between">
                        <span>Selling Price ($ per unit)</span>
                        <span id="current-selling-price-badge" class="text-[11px] text-on-surface-variant font-normal hidden">Current: $<span id="current-price-val" class="font-bold">0.00</span></span>
                    </label>
                    <input id="modal-selling-price" name="unit_price" step="0.01" oninput="checkSellingPriceDiff()" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-semibold" type="number" placeholder="0.00">
                </div>
            </div>

            <!-- Selling Price Update Confirmation Prompt Box (Shown if entered selling price != current selling price) -->
            <div id="price-change-prompt-box" class="hidden p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 space-y-2">
                <div class="flex items-start gap-2">
                    <span class="material-symbols-outlined text-amber-500 text-[20px] shrink-0 mt-0.5">warning</span>
                    <div class="text-xs text-on-surface flex-1">
                        <p class="font-bold text-amber-600 dark:text-amber-400">Selling Price Difference Detected</p>
                        <p class="text-[11px] text-on-surface-variant mt-0.5" id="price-diff-message">
                            This medicine's current selling price is $<span id="diff-old-price" class="font-bold">0.00</span>. Update it to $<span id="diff-new-price" class="font-bold">0.00</span> for all future sales?
                        </p>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-1 border-t border-amber-500/20 text-xs">
                    <label class="flex items-center gap-2 cursor-pointer font-medium select-none">
                        <input type="checkbox" id="modal-confirm-price-checkbox" onchange="togglePriceConfirmCheck(this.checked)" class="rounded border-outline-variant text-primary focus:ring-primary h-4 w-4">
                        <span class="text-[11px] text-on-surface font-semibold">Yes, update medicine selling price for all future sales</span>
                    </label>
                    <button type="button" onclick="revertToCurrentSellingPrice()" class="text-[11px] text-primary hover:underline cursor-pointer font-semibold">
                        Keep Current Price ($<span id="revert-price-val">0.00</span>)
                    </button>
                </div>
            </div>

            <!-- 4. Financials & Payment Schedule (Cash / Partial / Credit) -->
            <div class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant space-y-3">
                <div class="flex items-center justify-between">
                    <p class="font-bold text-xs text-on-surface flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px] text-primary">payments</span>
                        Payment &amp; Debt Terms (Xaaladda Lacag-Bixinta)
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Total Cost</label>
                        <input id="modal-total-cost" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs" value="$0.00" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Paid Now ($)</label>
                        <input id="modal-paid-amount" name="paid_amount" step="0.01" min="0" oninput="calcRestockFinancials()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-bold text-secondary" placeholder="0.00" value="0.00" type="number">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Remaining Debt ($)</label>
                        <input id="modal-due-amount" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs text-error" value="$0.00" type="text">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Disbursement Account *</label>
                        <select id="modal-payment-method" name="payment_method" onchange="calcRestockFinancials()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                            <option value="" data-bal="0">-- Please Select Disbursement Account --</option>
                            <option value="cash" data-bal="<?php echo (float)$disbursementAccounts['cash']['balance']; ?>">
                                1010 - Cash on Hand (Available: $<?php echo number_format((float)$disbursementAccounts['cash']['balance'], 2); ?>)
                            </option>
                            <option value="mobile" data-bal="<?php echo (float)$disbursementAccounts['mobile']['balance']; ?>">
                                1020 - Mobile Money (Available: $<?php echo number_format((float)$disbursementAccounts['mobile']['balance'], 2); ?>)
                            </option>
                            <option value="bank" data-bal="<?php echo (float)$disbursementAccounts['bank']['balance']; ?>">
                                1030 - Bank Account (Available: $<?php echo number_format((float)$disbursementAccounts['bank']['balance'], 2); ?>)
                            </option>
                        </select>
                        <p id="account-balance-warning" class="text-[11px] text-error font-bold hidden mt-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[14px]">warning</span>
                            <span id="account-balance-warning-text">Insufficient funds in selected account!</span>
                        </p>
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Notes / Reference</label>
                        <input name="notes" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Invoice #PO-991" type="text">
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeRestockModal()" class="px-4 py-2 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                    Confirm Stock Intake
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Outstanding Supplier Debts & Payables Ledger -->
<div id="supplier-debt-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-2xl w-full max-h-[90vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-error text-[26px]">receipt_long</span>
                <div>
                    <h3 class="font-headline-sm text-lg font-bold text-on-surface">Supplier Payables (Deymaha Shirkadaha)</h3>
                    <p class="text-xs text-on-surface-variant">Outstanding purchase debts awaiting settlement.</p>
                </div>
            </div>
            <button type="button" onclick="closeSupplierDebtModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[22px]">close</span>
            </button>
        </div>

        <?php if (empty($supplierDebts)): ?>
            <div class="py-8 text-center text-on-surface-variant">
                <span class="material-symbols-outlined text-4xl text-secondary mb-2">task_alt</span>
                <p class="font-bold text-sm">All Supplier Accounts are Fully Settled!</p>
                <p class="text-xs text-on-surface-variant mt-1">There are no outstanding debts due to any pharmaceutical vendors.</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($supplierDebts as $debt): ?>
                    <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant/80 shadow-xs flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-code-md font-bold text-primary text-xs"><?php echo e($debt['po_number']); ?></span>
                                <span class="bg-error-container text-on-error-container text-[10px] font-bold px-2 py-0.5 rounded-full capitalize"><?php echo e($debt['payment_status']); ?></span>
                            </div>
                            <h4 class="font-bold text-xs sm:text-sm text-on-surface mt-1"><?php echo e($debt['supplier_name']); ?></h4>
                            <p class="text-[11px] text-on-surface-variant">Date: <?php echo e($debt['purchase_date']); ?> • Phone: <?php echo e($debt['supplier_phone']); ?></p>
                        </div>
                        <div class="text-right flex flex-col items-end w-full sm:w-auto">
                            <p class="text-xs text-on-surface-variant">Total: $<?php echo number_format((float)$debt['net_amount'], 2); ?> | Paid: $<?php echo number_format((float)$debt['paid_amount'], 2); ?></p>
                            <p class="font-bold text-sm text-error mt-0.5">Due Balance: $<?php echo number_format((float)$debt['due_amount'], 2); ?></p>
                            <form method="POST" action="inventory_management.php" class="flex items-center gap-2 mt-2">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="pay_supplier">
                                <input type="hidden" name="purchase_id" value="<?php echo (int)$debt['id']; ?>">
                                <input name="amount_paid" step="0.01" min="0.01" max="<?php echo (float)$debt['due_amount']; ?>" value="<?php echo (float)$debt['due_amount']; ?>" class="w-24 bg-surface border border-outline-variant rounded px-2 py-1 text-xs font-bold" type="number" required>
                                <select name="payment_method" required class="bg-surface border border-outline-variant rounded px-1.5 py-1 text-xs font-semibold text-on-surface">
                                    <option value="">-- Select Account --</option>
                                    <option value="cash">1010 - Cash ($<?php echo number_format((float)$disbursementAccounts['cash']['balance'], 2); ?>)</option>
                                    <option value="mobile">1020 - Mobile ($<?php echo number_format((float)$disbursementAccounts['mobile']['balance'], 2); ?>)</option>
                                    <option value="bank">1030 - Bank ($<?php echo number_format((float)$disbursementAccounts['bank']['balance'], 2); ?>)</option>
                                </select>
                                <button type="submit" class="px-3 py-1 bg-error hover:bg-on-error-container text-on-error text-xs font-bold rounded shadow-xs cursor-pointer">
                                    Pay Debt
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    let activeMedicineCurrentPrice = null;

    function openRestockModal() {
        document.getElementById('restock-modal').classList.remove('hidden');
    }
    function closeRestockModal() {
        document.getElementById('restock-modal').classList.add('hidden');
    }
    function openSupplierDebtModal() {
        document.getElementById('supplier-debt-modal').classList.remove('hidden');
    }
    function closeSupplierDebtModal() {
        document.getElementById('supplier-debt-modal').classList.add('hidden');
    }

    function toggleNewMedFields() {
        const select = document.getElementById('modal-med-select');
        const newFields = document.getElementById('new-med-fields');
        const selectedOpt = select.options[select.selectedIndex];

        if (select.value === '0') {
            newFields.classList.remove('hidden');
            document.getElementById('modal-cost-price').value = '';
            document.getElementById('modal-selling-price').value = '';
            activeMedicineCurrentPrice = null;
            document.getElementById('current-selling-price-badge').classList.add('hidden');
            document.getElementById('price-change-prompt-box').classList.add('hidden');
            document.getElementById('modal-confirm-price-update').value = '1';
            document.getElementById('modal-confirm-price-checkbox').checked = false;
        } else if (select.value === '') {
            newFields.classList.add('hidden');
            document.getElementById('modal-cost-price').value = '';
            document.getElementById('modal-selling-price').value = '';
            activeMedicineCurrentPrice = null;
            document.getElementById('current-selling-price-badge').classList.add('hidden');
            document.getElementById('price-change-prompt-box').classList.add('hidden');
            document.getElementById('modal-confirm-price-update').value = '0';
            document.getElementById('modal-confirm-price-checkbox').checked = false;
        } else {
            newFields.classList.add('hidden');
            if (selectedOpt && selectedOpt.dataset.cost !== undefined) {
                document.getElementById('modal-cost-price').value = parseFloat(selectedOpt.dataset.cost).toFixed(2);
                const p = parseFloat(selectedOpt.dataset.price);
                activeMedicineCurrentPrice = isNaN(p) ? 0 : p;
                document.getElementById('modal-selling-price').value = activeMedicineCurrentPrice.toFixed(2);
                document.getElementById('current-price-val').innerText = activeMedicineCurrentPrice.toFixed(2);
                document.getElementById('revert-price-val').innerText = activeMedicineCurrentPrice.toFixed(2);
                document.getElementById('current-selling-price-badge').classList.remove('hidden');
            }
            document.getElementById('price-change-prompt-box').classList.add('hidden');
            document.getElementById('modal-confirm-price-update').value = '0';
            document.getElementById('modal-confirm-price-checkbox').checked = false;
        }
        calcRestockFinancials();
    }

    function checkSellingPriceDiff() {
        if (activeMedicineCurrentPrice === null) {
            document.getElementById('price-change-prompt-box').classList.add('hidden');
            return;
        }
        const enteredPrice = parseFloat(document.getElementById('modal-selling-price').value);
        if (isNaN(enteredPrice) || enteredPrice <= 0 || Math.abs(enteredPrice - activeMedicineCurrentPrice) < 0.001) {
            document.getElementById('price-change-prompt-box').classList.add('hidden');
            document.getElementById('modal-confirm-price-update').value = '0';
            document.getElementById('modal-confirm-price-checkbox').checked = false;
        } else {
            document.getElementById('diff-old-price').innerText = activeMedicineCurrentPrice.toFixed(2);
            document.getElementById('diff-new-price').innerText = enteredPrice.toFixed(2);
            document.getElementById('revert-price-val').innerText = activeMedicineCurrentPrice.toFixed(2);
            document.getElementById('price-change-prompt-box').classList.remove('hidden');
            const isChecked = document.getElementById('modal-confirm-price-checkbox').checked;
            document.getElementById('modal-confirm-price-update').value = isChecked ? '1' : '0';
        }
    }

    function togglePriceConfirmCheck(checked) {
        document.getElementById('modal-confirm-price-update').value = checked ? '1' : '0';
    }

    function revertToCurrentSellingPrice() {
        if (activeMedicineCurrentPrice !== null) {
            document.getElementById('modal-selling-price').value = activeMedicineCurrentPrice.toFixed(2);
            checkSellingPriceDiff();
        }
    }

    function handleRestockSubmit(event) {
        if (activeMedicineCurrentPrice !== null) {
            const enteredPrice = parseFloat(document.getElementById('modal-selling-price').value);
            if (!isNaN(enteredPrice) && enteredPrice > 0 && Math.abs(enteredPrice - activeMedicineCurrentPrice) >= 0.001) {
                const isConfirmed = document.getElementById('modal-confirm-price-checkbox').checked;
                if (!isConfirmed) {
                    const ok = confirm(
                        `This medicine's current selling price is $${activeMedicineCurrentPrice.toFixed(2)}. Update it to $${enteredPrice.toFixed(2)} for all future sales?\n\n` +
                        `• Click OK to confirm updating the selling price for all future sales.\n` +
                        `• Click CANCEL to keep the existing selling price ($${activeMedicineCurrentPrice.toFixed(2)}).`
                    );
                    if (ok) {
                        document.getElementById('modal-confirm-price-update').value = '1';
                        document.getElementById('modal-confirm-price-checkbox').checked = true;
                    } else {
                        document.getElementById('modal-confirm-price-update').value = '0';
                        document.getElementById('modal-confirm-price-checkbox').checked = false;
                        document.getElementById('modal-selling-price').value = activeMedicineCurrentPrice.toFixed(2);
                    }
                }
            }
        }
        return true;
    }

    function toggleNewSupplierFields() {
        const select = document.getElementById('modal-supplier-select');
        const newFields = document.getElementById('new-supplier-fields');
        if (select.value === '0') {
            newFields.classList.remove('hidden');
        } else {
            newFields.classList.add('hidden');
        }
    }

    function prepareRestockForMed(id, name, cost, price) {
        const select = document.getElementById('modal-med-select');
        select.value = id;
        document.getElementById('modal-cost-price').value = parseFloat(cost).toFixed(2);
        
        const p = parseFloat(price);
        activeMedicineCurrentPrice = isNaN(p) ? 0 : p;
        document.getElementById('modal-selling-price').value = activeMedicineCurrentPrice.toFixed(2);
        document.getElementById('current-price-val').innerText = activeMedicineCurrentPrice.toFixed(2);
        document.getElementById('revert-price-val').innerText = activeMedicineCurrentPrice.toFixed(2);
        document.getElementById('current-selling-price-badge').classList.remove('hidden');
        document.getElementById('price-change-prompt-box').classList.add('hidden');
        document.getElementById('modal-confirm-price-update').value = '0';
        document.getElementById('modal-confirm-price-checkbox').checked = false;

        if (!document.getElementById('modal-qty').value) {
            document.getElementById('modal-qty').value = '50';
        }
        document.getElementById('modal-paid-amount').value = '0.00';
        document.getElementById('modal-payment-method').value = '';
        toggleNewMedFields();
        calcRestockFinancials();
        openRestockModal();
    }

    function calcRestockFinancials() {
        const qty = parseFloat(document.getElementById('modal-qty').value) || 0;
        const cost = parseFloat(document.getElementById('modal-cost-price').value) || 0;
        const total = qty * cost;
        document.getElementById('modal-total-cost').value = '$' + total.toFixed(2);

        const paidInput = document.getElementById('modal-paid-amount');
        let paid = parseFloat(paidInput.value);
        if (isNaN(paid) || paid < 0) {
            paid = 0;
        }

        const due = Math.max(0, total - paid);
        document.getElementById('modal-due-amount').value = '$' + due.toFixed(2);

        const paySelect = document.getElementById('modal-payment-method');
        const warnBox   = document.getElementById('account-balance-warning');
        const warnText  = document.getElementById('account-balance-warning-text');

        if (paid > 0) {
            paySelect.required = true;
            const selectedOpt = paySelect.options[paySelect.selectedIndex];
            const availBal = selectedOpt ? (parseFloat(selectedOpt.dataset.bal) || 0) : 0;

            if (paySelect.value !== '' && paid > availBal) {
                warnBox.classList.remove('hidden');
                warnText.innerText = `Insufficient funds in selected account! Available: $${availBal.toFixed(2)}, Required: $${paid.toFixed(2)}`;
                paySelect.classList.add('border-error');
            } else {
                warnBox.classList.add('hidden');
                paySelect.classList.remove('border-error');
            }
        } else {
            paySelect.required = false;
            warnBox.classList.add('hidden');
            paySelect.classList.remove('border-error');
        }
    }

    function openAddSupplierModal() {
        document.getElementById('add-supplier-modal').classList.remove('hidden');
    }

    function closeAddSupplierModal() {
        document.getElementById('add-supplier-modal').classList.add('hidden');
    }

    function openEditPriceModal(id, name, currentPrice) {
        document.getElementById('edit-price-med-id').value = id;
        document.getElementById('modal-edit-price-med-name').innerText = name;
        document.getElementById('edit-price-current-display').innerText = '$' + parseFloat(currentPrice).toFixed(2);
        document.getElementById('edit-price-new-input').value = parseFloat(currentPrice).toFixed(2);
        document.getElementById('edit-price-modal').classList.remove('hidden');
    }

    function closeEditPriceModal() {
        document.getElementById('edit-price-modal').classList.add('hidden');
    }
</script>

<!-- MODAL: Add New Supplier -->
<div id="add-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">domain_add</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Register New Supplier / Vendor</h3>
                    <p class="text-xs text-on-surface-variant">Add a pharmaceutical company or medicine distributor to the database.</p>
                </div>
            </div>
            <button type="button" onclick="closeAddSupplierModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="inventory_management.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_supplier">
            <input type="hidden" name="redirect" value="inventory_management.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Supplier / Company Name *</label>
                <input name="name" type="text" required placeholder="e.g. MedSource Pharma International" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Contact Person</label>
                    <input name="contact_person" type="text" placeholder="e.g. Hassan Ali" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number *</label>
                    <input name="phone" type="text" required placeholder="e.g. (555) 012-3344" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address</label>
                    <input name="email" type="email" placeholder="e.g. orders@medsource.org" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Office / Warehouse Address</label>
                    <input name="address" type="text" placeholder="e.g. Airport Rd, Industrial Zone" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeAddSupplierModal()" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Supplier to Database
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Medication Selling Price -->
<div id="edit-price-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">sell</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Update Retail Selling Price</h3>
                    <p class="text-xs text-on-surface-variant font-medium" id="modal-edit-price-med-name">Medicine Name</p>
                </div>
            </div>
            <button type="button" onclick="closeEditPriceModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="inventory_management.php" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_selling_price">
            <input type="hidden" name="medication_id" id="edit-price-med-id" value="">

            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant/60 flex items-center justify-between">
                <span class="text-xs font-semibold text-on-surface-variant">Current Retail Price:</span>
                <span class="text-sm font-mono font-bold text-on-surface" id="edit-price-current-display">$0.00</span>
            </div>

            <div>
                <label class="block font-semibold text-xs text-on-surface mb-1">New Retail Selling Price ($) *</label>
                <input id="edit-price-new-input" name="unit_price" step="0.01" min="0.01" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface font-semibold focus:border-primary outline-none" type="number" placeholder="0.00">
            </div>

            <div>
                <label class="block font-semibold text-xs text-on-surface mb-1">Reason for Price Change *</label>
                <input name="reason" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none" type="text" placeholder="e.g. Supplier cost increase / Price revision">
            </div>

            <p class="text-[11px] text-on-surface-variant flex items-start gap-1">
                <span class="material-symbols-outlined text-[14px] text-primary shrink-0 mt-0.5">info</span>
                <span>This price will immediately apply to all future prescription dispensing and walk-in sales. Every update is tracked in the price audit log.</span>
            </p>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeEditPriceModal()" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Selling Price
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>
