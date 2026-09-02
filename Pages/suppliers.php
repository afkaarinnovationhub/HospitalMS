<?php
/**
 * MedCore Systems - Suppliers & Vendors Management Hub
 * Handles supplier master catalog, contact directories, financial ledger statements, and full CRUD operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/../CONTROLS/InventoryController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_PHARMACY]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_supplier') {
        $result = InventoryController::handleCreateSupplier($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_supplier') {
        $result = InventoryController::handleEditSupplier($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_supplier') {
        $result = InventoryController::handleDeleteSupplier($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search & Filter parameters
$searchQuery  = sanitizeString($_GET['search'] ?? '');
$statusFilter = sanitizeString($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'in_debt', 'settled'], true)) {
    $statusFilter = 'all';
}

$kpis = InventoryOperation::getSupplierSummaryKPIs();
$suppliers = InventoryOperation::getSuppliersWithFinancials($searchQuery ?: null, $statusFilter);

$pageTitle = 'Suppliers & Vendors Directory - MedCore Systems';
$headerTitle = 'MedCore Management - Suppliers Hub';
$activePage = 'suppliers';

include __DIR__ . '/../components/header.php';
?>

<!-- Suppliers Management Main Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Notice / Action Alert</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Operation Successful</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Page Header & Action Bar -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[28px]">local_shipping</span>
                Suppliers &amp; Vendor Management
            </h2>
            <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-1">
                Pharmaceutical vendors, wholesale distributors, procurement ledgers, and supplier debt records.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            <a href="inventory_management.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all shadow-xs">
                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
                Restock Inventory
            </a>
            <a href="accounts_payable.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all shadow-xs">
                <span class="material-symbols-outlined text-[18px]">account_balance</span>
                Accounts Payable
            </a>
            <button type="button" onclick="openAddSupplierModal()" class="px-4 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold flex items-center gap-1.5 transition-all shadow-sm cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">domain_add</span>
                + Register New Supplier
            </button>
        </div>
    </div>

    <!-- 4 Modern Bento KPI Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Total Suppliers</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-on-surface font-mono mt-1"><?php echo number_format($kpis['total_suppliers']); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-0.5">Master directory records</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">domain</span>
            </div>
        </div>

        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Active Vendors</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-secondary font-mono mt-1"><?php echo number_format($kpis['active_vendors']); ?></h3>
                <p class="text-[11px] text-secondary mt-0.5 font-semibold">With purchase orders</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-secondary/10 text-secondary flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">verified</span>
            </div>
        </div>

        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Restock PO Orders</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-on-surface font-mono mt-1"><?php echo number_format($kpis['total_pos']); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-0.5">Total procurement batches</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-surface-container-high text-on-surface flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">receipt_long</span>
            </div>
        </div>

        <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Outstanding AP Debt</p>
                <h3 class="text-2xl sm:text-3xl font-extrabold text-error font-mono mt-1">$<?php echo number_format($kpis['total_payable_debt'], 2); ?></h3>
                <p class="text-[11px] text-error mt-0.5 font-semibold">Unsettled vendor balance</p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-error-container/40 text-error flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[24px]">account_balance_wallet</span>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-4 mb-6 shadow-xs">
        <form method="GET" action="suppliers.php" class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
            <div class="relative flex-1">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                <input type="text" name="search" value="<?php echo e($searchQuery); ?>" placeholder="Search by supplier name, contact person, phone, email, or city..." class="w-full pl-10 pr-4 py-2 bg-surface-container-low border border-outline-variant rounded-xl text-xs sm:text-sm text-on-surface focus:border-primary outline-none transition-colors">
            </div>

            <div class="flex items-center gap-2">
                <select name="status" onchange="this.form.submit()" class="bg-surface-container-low border border-outline-variant rounded-xl px-3 py-2 text-xs font-semibold text-on-surface focus:border-primary outline-none cursor-pointer">
                    <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Suppliers (<?php echo count($suppliers); ?>)</option>
                    <option value="in_debt" <?php echo ($statusFilter === 'in_debt') ? 'selected' : ''; ?>>Suppliers with Debt (Due &gt; $0)</option>
                    <option value="settled" <?php echo ($statusFilter === 'settled') ? 'selected' : ''; ?>>Fully Settled ($0 Due)</option>
                </select>

                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-container transition-colors cursor-pointer">
                    Filter
                </button>

                <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                    <a href="suppliers.php" class="px-3 py-2 bg-surface-container border border-outline-variant text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high transition-colors" title="Clear Filters">
                        Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Master Suppliers Bento Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl shadow-sm overflow-hidden mb-6">
        <div class="p-4 border-b border-outline-variant flex flex-wrap justify-between items-center bg-surface-bright gap-2">
            <div>
                <h3 class="font-headline-sm text-sm font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">store</span>
                    Registered Suppliers &amp; Procurement Ledgers
                </h3>
                <p class="text-[11px] text-on-surface-variant">Manage contact information, view financial accounts, edit details, or remove vendors.</p>
            </div>
            <span class="text-xs font-bold text-on-surface-variant bg-surface-container px-2.5 py-1 rounded-full">
                Showing <?php echo count($suppliers); ?> vendor(s)
            </span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-lowest">
                        <th class="py-3 px-4">Supplier Company</th>
                        <th class="py-3 px-3">Contact Person</th>
                        <th class="py-3 px-3">Phone &amp; Email</th>
                        <th class="py-3 px-3">Location / Address</th>
                        <th class="py-3 px-3 text-center">Restock POs</th>
                        <th class="py-3 px-3 font-mono">Total Supplied ($)</th>
                        <th class="py-3 px-3 font-mono">Total Paid ($)</th>
                        <th class="py-3 px-3 font-mono text-error">Balance Due ($)</th>
                        <th class="py-3 px-3 text-center">Status</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="10" class="py-10 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[36px] text-secondary block mb-2">domain_disabled</span>
                                <p class="font-bold text-sm text-on-surface">No suppliers match your search query.</p>
                                <p class="text-xs text-on-surface-variant mt-1">Register a new vendor or clear current filter criteria.</p>
                                <button type="button" onclick="openAddSupplierModal()" class="mt-4 px-4 py-2 bg-primary text-on-primary rounded-xl text-xs font-bold inline-flex items-center gap-1.5 shadow-xs cursor-pointer">
                                    <span class="material-symbols-outlined text-[16px]">domain_add</span>
                                    Register First Supplier
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $sup): ?>
                            <?php 
                                $sId = (int)$sup['id'];
                                $poCount = (int)$sup['purchase_count'];
                                $totInvoiced = (float)$sup['total_invoiced'];
                                $totPaid = (float)$sup['total_paid'];
                                $totDue = (float)$sup['total_due'];
                                
                                $statusBadge = 'bg-secondary-fixed text-on-secondary-fixed';
                                $statusText  = 'Settled';
                                if ($totDue > 0) {
                                    $statusBadge = 'bg-error-container text-on-error-container font-bold';
                                    $statusText  = 'In Debt';
                                } elseif ($poCount === 0) {
                                    $statusBadge = 'bg-surface-container text-on-surface-variant';
                                    $statusText  = 'New / Inactive';
                                }
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold text-xs shrink-0">
                                            <?php echo strtoupper(substr($sup['name'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <span class="font-bold text-on-surface text-xs sm:text-sm block"><?php echo e($sup['name']); ?></span>
                                            <span class="text-[10px] text-on-surface-variant font-mono">ID: SUP-<?php echo str_pad((string)$sId, 4, '0', STR_PAD_LEFT); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-semibold text-on-surface block"><?php echo e($sup['contact_person'] ?: 'Representative'); ?></span>
                                    <span class="text-[10px] text-on-surface-variant">Vendor Liaison</span>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="font-mono text-on-surface block"><?php echo e($sup['phone'] ?: 'N/A'); ?></span>
                                    <span class="text-[10px] text-on-surface-variant block"><?php echo e($sup['email'] ?: 'No email on file'); ?></span>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant">
                                    <?php echo e($sup['address'] ?: 'Somalia / Main Office'); ?>
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-on-surface">
                                    <?php echo $poCount; ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-on-surface">
                                    $<?php echo number_format($totInvoiced, 2); ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-secondary font-semibold">
                                    $<?php echo number_format($totPaid, 2); ?>
                                </td>
                                <td class="py-3 px-3 font-mono font-bold <?php echo ($totDue > 0) ? 'text-error text-sm' : 'text-on-surface-variant'; ?>">
                                    $<?php echo number_format($totDue, 2); ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $statusBadge; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" 
                                                onclick="openEditSupplierModal(<?php echo htmlspecialchars(json_encode($sup), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-primary/10 hover:text-primary text-on-surface rounded-lg text-xs font-semibold transition-colors cursor-pointer flex items-center gap-1 shadow-2xs" 
                                                title="Edit Supplier Details">
                                            <span class="material-symbols-outlined text-[15px]">edit</span>
                                            Edit
                                        </button>
                                        <button type="button" 
                                                onclick="openDeleteSupplierModal(<?php echo $sId; ?>, '<?php echo e(addslashes($sup['name'])); ?>', <?php echo $poCount; ?>)" 
                                                class="p-1 text-on-surface-variant hover:text-error hover:bg-error-container/30 rounded-lg transition-colors cursor-pointer" 
                                                title="Delete Supplier">
                                            <span class="material-symbols-outlined text-[17px]">delete</span>
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
</main>

<!-- MODAL 1: Register New Supplier -->
<div id="add-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[26px]">domain_add</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Register New Supplier</h3>
                    <p class="text-xs text-on-surface-variant">Add a pharmaceutical distributor or medical vendor.</p>
                </div>
            </div>
            <button type="button" onclick="closeAddSupplierModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="suppliers.php" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_supplier">
            <input type="hidden" name="redirect" value="suppliers.php">

            <!-- Section 1: Company Name & Contact Person -->
            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Supplier / Company Name *</label>
                <input name="name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Mogadishu Pharma Distributors" type="text">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Contact Person / Representative</label>
                <input name="contact_person" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Ali Warsame (Sales Manager)" type="text">
            </div>

            <!-- Line Separator: Contact Details -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number *</label>
                        <input name="phone" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. +252 61 XXXXXXX" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address</label>
                        <input name="email" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. orders@mogadishupharma.com" type="email">
                    </div>
                </div>
            </div>

            <!-- Line Separator: Physical Address -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Physical Address / City / Hub</label>
                    <input name="address" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Bakara Market, Zone 4, Mogadishu" type="text">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeAddSupplierModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Supplier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Edit Supplier Details -->
<div id="edit-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[26px]">edit</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Supplier Information</h3>
                    <p class="text-xs text-on-surface-variant">Update contact details and address.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditSupplierModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="suppliers.php" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_supplier">
            <input type="hidden" id="edit_supplier_id" name="supplier_id" value="">
            <input type="hidden" name="redirect" value="suppliers.php">

            <!-- Section 1: Company & Contact -->
            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Supplier / Company Name *</label>
                <input id="edit_supplier_name" name="name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" type="text">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Contact Person / Representative</label>
                <input id="edit_supplier_contact" name="contact_person" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" type="text">
            </div>

            <!-- Line Separator: Contact Info -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number *</label>
                        <input id="edit_supplier_phone" name="phone" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address</label>
                        <input id="edit_supplier_email" name="email" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" type="email">
                    </div>
                </div>
            </div>

            <!-- Line Separator: Address -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Physical Address / City / Hub</label>
                    <input id="edit_supplier_address" name="address" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" type="text">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeEditSupplierModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Update Supplier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Delete Supplier Confirmation -->
<div id="delete-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center gap-3 text-error">
            <span class="material-symbols-outlined text-[32px]">warning</span>
            <div>
                <h3 class="font-bold text-base text-on-surface">Delete Supplier</h3>
                <p class="text-xs text-on-surface-variant">Are you sure you want to remove this vendor?</p>
            </div>
        </div>

        <div class="p-3.5 rounded-xl bg-surface-container border border-outline-variant text-xs text-on-surface space-y-1">
            <p><strong class="text-on-surface">Supplier:</strong> <span id="del_supplier_name" class="font-bold text-primary"></span></p>
            <p id="del_purchase_warning" class="text-error font-semibold hidden">
                ⚠️ This supplier has <span id="del_po_count"></span> purchase order(s). Deleting is blocked to protect historical accounting ledgers.
            </p>
        </div>

        <form method="POST" action="suppliers.php" class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_supplier">
            <input type="hidden" id="del_supplier_id" name="supplier_id" value="">
            <input type="hidden" name="redirect" value="suppliers.php">

            <button type="button" onclick="closeDeleteSupplierModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
            <button id="del_confirm_btn" type="submit" class="px-4 py-2 rounded-lg bg-error hover:bg-error/90 text-on-error text-xs font-bold shadow-xs cursor-pointer">
                Confirm Delete
            </button>
        </form>
    </div>
</div>

<script>
    function openAddSupplierModal() {
        document.getElementById('add-supplier-modal').classList.remove('hidden');
    }

    function closeAddSupplierModal() {
        document.getElementById('add-supplier-modal').classList.add('hidden');
    }

    function openEditSupplierModal(s) {
        document.getElementById('edit_supplier_id').value = s.id;
        document.getElementById('edit_supplier_name').value = s.name || '';
        document.getElementById('edit_supplier_contact').value = s.contact_person || '';
        document.getElementById('edit_supplier_phone').value = s.phone || '';
        document.getElementById('edit_supplier_email').value = s.email || '';
        document.getElementById('edit_supplier_address').value = s.address || '';
        document.getElementById('edit-supplier-modal').classList.remove('hidden');
    }

    function closeEditSupplierModal() {
        document.getElementById('edit-supplier-modal').classList.add('hidden');
    }

    function openDeleteSupplierModal(id, name, poCount) {
        document.getElementById('del_supplier_id').value = id;
        document.getElementById('del_supplier_name').innerText = name;
        
        const warningEl = document.getElementById('del_purchase_warning');
        const countEl = document.getElementById('del_po_count');
        const confirmBtn = document.getElementById('del_confirm_btn');

        if (poCount > 0) {
            countEl.innerText = poCount;
            warningEl.classList.remove('hidden');
            confirmBtn.disabled = true;
            confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
        } else {
            warningEl.classList.add('hidden');
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }

        document.getElementById('delete-supplier-modal').classList.remove('hidden');
    }

    function closeDeleteSupplierModal() {
        document.getElementById('delete-supplier-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
