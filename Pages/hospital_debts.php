<?php
/**
 * MedCore Systems - Hospital Debts & Accounts Payable Ledger
 * Provides a clean, focused, and fast interface for tracking hospital debts,
 * outstanding vendor restock balances, and reviewing supplier purchase statements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/../CONTROLS/InventoryController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_PHARMACY]);

$currentUser = getCurrentUser();
$userRole    = $currentUser['role'] ?? 'Staff';
$userId      = (int)($currentUser['id'] ?? 1);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Debt Settlement / Supplier Payment Action
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'pay_supplier_debt') {
    $result = InventoryController::handleSupplierDebtPayment($_POST);
    if (isset($result['error'])) {
        $errorMessage = $result['error'];
    } else {
        $successMessage = getFlashMessage('success') ?: 'Supplier debt installment payment recorded successfully!';
    }
}

$filter = sanitizeString($_GET['filter'] ?? 'debtors_only');
if (!in_array($filter, ['debtors_only', 'high_debt', 'all'], true)) {
    $filter = 'debtors_only';
}
$search = sanitizeString($_GET['search'] ?? '');

$metrics = AccountingOperation::getHospitalDebtsSummaryMetrics();
$records = AccountingOperation::getHospitalDebtsLedger($filter, $search);

$pageTitle = 'Hospital Debts Ledger - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Hospital Debts';
$activePage = 'hospital_debts';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-10 bg-background custom-scrollbar">

    <!-- Flash Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Debt Payment Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Payment Recorded</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top Header: Title & Action Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 rounded-xl bg-error-container/50 text-error flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[24px]">credit_card</span>
                </div>
                <div>
                    <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface">
                        Hospital Debts Ledger
                    </h2>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Directory
            </button>
            <a href="suppliers.php" class="px-4 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">local_shipping</span>
                Manage Suppliers
            </a>
            <a href="inventory_management.php" class="px-4 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">add_shopping_cart</span>
                New Restock Order
            </a>
        </div>
    </div>

    <!-- KPI Summary Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- 1. Total Hospital Debt -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-error/30 shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-error/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">money_off</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-error uppercase tracking-wider">Total Hospital Debt (AP)</span>
                <span class="p-1.5 rounded-lg bg-error-container/50 text-error">
                    <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-error font-mono">
                    $<?php echo number_format($metrics['total_debt_due'], 2); ?>
                </h3>
                
            </div>
        </div>

        <!-- 2. Active Creditors (Suppliers Owed) -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-outline-variant shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-primary/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">store</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-primary uppercase tracking-wider">Active Creditors</span>
                <span class="p-1.5 rounded-lg bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-[18px]">local_shipping</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-on-surface font-mono">
                    <?php echo $metrics['creditors_count']; ?> <span class="text-sm font-normal text-on-surface-variant">suppliers</span>
                </h3>
            </div>
        </div>

        <!-- 3. Total Purchased Amount -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-outline-variant shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-on-surface-variant/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">receipt_long</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Total Purchased Amount</span>
                <span class="p-1.5 rounded-lg bg-surface-container text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px]">shopping_bag</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-on-surface font-mono">
                    $<?php echo number_format($metrics['total_purchased'], 2); ?>
                </h3>
            </div>
        </div>

        <!-- 4. Total Paid to Suppliers -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-secondary/30 shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-secondary/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">check_circle</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-secondary uppercase tracking-wider">Total Paid to Suppliers</span>
                <span class="p-1.5 rounded-lg bg-secondary/15 text-secondary">
                    <span class="material-symbols-outlined text-[18px]">verified</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-secondary font-mono">
                    $<?php echo number_format($metrics['total_paid'], 2); ?>
                </h3>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs mb-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- Filter Tabs -->
            <div class="flex flex-wrap items-center gap-1.5 p-1 bg-surface-container rounded-xl text-xs font-semibold w-fit">
                <a href="hospital_debts.php?filter=debtors_only" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'debtors_only') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1 text-error">warning</span>
                    Active Creditors Only
                </a>
                <a href="hospital_debts.php?filter=high_debt" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'high_debt') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1 text-amber-500">trending_up</span>
                    High Balance (> $500)
                </a>
                <a href="hospital_debts.php?filter=all" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'all') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1">folder_shared</span>
                    All Supplier Accounts
                </a>
            </div>

            <!-- Instant Live Search Box -->
            <div class="relative w-full md:w-72">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                <input id="supplier-table-search" 
                       type="text" 
                       value="<?php echo e($search); ?>"
                       oninput="filterSuppliersTable(this.value)"
                       placeholder="Search supplier, contact, phone..." 
                       class="w-full pl-9 pr-8 py-2 bg-surface-container-low border border-outline-variant rounded-xl text-xs text-on-surface placeholder:text-on-surface-variant focus:border-primary outline-none transition-colors">
                <button id="clear-table-search" 
                        type="button" 
                        onclick="clearTableSearch()" 
                        class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">close</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Suppliers Debt Ledger Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">table_rows</span>
                    Hospital Accounts Payable Ledger
                </h3>
               
            </div>
            <span id="results-count-badge" class="px-2.5 py-1 rounded-full text-xs font-mono font-bold bg-surface-container text-on-surface-variant w-fit">
                <?php echo count($records); ?> records
            </span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low select-none">
                        <th class="py-3 px-4">Supplier / Vendor</th>
                        <th class="py-3 px-4">Contact &amp; Phone</th>
                        <th class="py-3 px-4 text-center">Purchase Orders</th>
                        <th class="py-3 px-4 font-mono">Total Billed</th>
                        <th class="py-3 px-4 font-mono">Total Paid</th>
                        <th class="py-3 px-4 font-mono text-error">Balance Due ($)</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="supplier-table-body" class="divide-y divide-outline-variant/60">
                    <?php if (empty($records)): ?>
                        <tr id="empty-state-row">
                            <td colspan="8" class="py-12 text-center text-on-surface-variant">
                                <div class="max-w-xs mx-auto space-y-2">
                                    <div class="w-12 h-12 rounded-full bg-secondary/15 text-secondary flex items-center justify-center mx-auto">
                                        <span class="material-symbols-outlined text-[28px]">check_circle</span>
                                    </div>
                                    <p class="font-bold text-sm text-on-surface">No outstanding hospital debts!</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <?php 
                                $sId = (int)$row['supplier_id'];
                                $name = $row['supplier_name'] ?: 'Unknown Vendor';
                                $contact = $row['contact_person'] ?: 'Distributor';
                                $phone = $row['supplier_phone'] ?: 'N/A';
                                $email = $row['supplier_email'] ?: '';
                                $due = (float)$row['balance_due'];
                                $invoiced = (float)$row['total_invoiced'];
                                $paid = (float)$row['total_paid'];
                                $initial = strtoupper(substr($name, 0, 1) ?: 'S');
                                $isDebt = ($due > 0.005);
                                $latestPO = (int)($row['latest_purchase_id'] ?? 0);
                            ?>
                            <tr class="supplier-row hover:bg-surface-container-low transition-colors group cursor-pointer"
                                data-name="<?php echo e(strtolower($name)); ?>"
                                data-contact="<?php echo e(strtolower($contact)); ?>"
                                data-phone="<?php echo e(strtolower($phone)); ?>"
                                data-due="<?php echo $due; ?>"
                                onclick="handleRowClick(event, <?php echo $sId; ?>)">
                                
                                <!-- Supplier Info -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full <?php echo $isDebt ? 'bg-error-container text-on-error-container' : 'bg-secondary/20 text-secondary'; ?> font-bold text-xs flex items-center justify-center shrink-0">
                                            <?php echo $initial; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <span class="font-bold text-on-surface group-hover:text-primary transition-colors block truncate text-xs sm:text-sm">
                                                <?php echo e($name); ?>
                                            </span>
                                            <span class="text-[11px] text-on-surface-variant block">
                                                <?php echo e($email ?: 'Verified Vendor'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Contact & Phone -->
                                <td class="py-3 px-4">
                                    <span class="text-xs font-semibold text-on-surface block"><?php echo e($contact); ?></span>
                                    <span class="text-[11px] text-on-surface-variant font-mono flex items-center gap-1 mt-0.5">
                                        <span class="material-symbols-outlined text-[13px]">call</span>
                                        <?php echo e($phone); ?>
                                    </span>
                                </td>

                                <!-- Purchase Orders Count -->
                                <td class="py-3 px-4 text-center font-mono font-bold text-on-surface">
                                    <span class="px-2 py-0.5 rounded-full bg-surface-container text-xs">
                                        <?php echo (int)$row['total_purchases_count']; ?>
                                    </span>
                                </td>

                                <!-- Total Invoiced -->
                                <td class="py-3 px-4 font-mono text-on-surface font-semibold">
                                    $<?php echo number_format($invoiced, 2); ?>
                                </td>

                                <!-- Total Paid -->
                                <td class="py-3 px-4 font-mono text-secondary font-semibold">
                                    $<?php echo number_format($paid, 2); ?>
                                </td>

                                <!-- Balance Due (Highlighted) -->
                                <td class="py-3 px-4 font-mono font-bold text-sm">
                                    <?php if ($isDebt): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-error-container text-on-error-container font-mono font-bold">
                                            <span class="material-symbols-outlined text-[14px]">warning</span>
                                            $<?php echo number_format($due, 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-on-surface-variant">$0.00</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3 px-4">
                                    <?php if ($isDebt): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-error/15 text-error border border-error/30 inline-flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-error animate-pulse"></span>
                                            OUTSTANDING
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-secondary/15 text-secondary border border-secondary/30 inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[12px]">check</span>
                                            SETTLED
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="py-3 px-4 text-right" onclick="event.stopPropagation()">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" 
                                                onclick="loadAndOpenStatement(<?php echo $sId; ?>)"
                                                class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high border border-outline-variant text-primary rounded-lg text-xs font-bold transition-colors cursor-pointer flex items-center gap-1 shadow-2xs"
                                                title="View Full Supplier Statement">
                                            <span class="material-symbols-outlined text-[15px]">receipt_long</span>
                                            Full Statement
                                        </button>
                                        <?php if ($isDebt && $latestPO > 0): ?>
                                            <button type="button"
                                                    onclick="openPayModal(<?php echo $sId; ?>, '<?php echo e(addslashes($name)); ?>', <?php echo $latestPO; ?>, <?php echo $due; ?>)"
                                                    class="px-2.5 py-1 bg-primary hover:bg-primary-container text-on-primary rounded-lg text-xs font-bold transition-colors cursor-pointer flex items-center gap-1 shadow-xs"
                                                    title="Disburse Payment to Settle Debt">
                                                <span class="material-symbols-outlined text-[15px]">payments</span>
                                                Pay ($<?php echo number_format($due, 2); ?>)
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
</main>

<!-- ========================================================================= -->
<!-- MODAL 1: Full Supplier Financial Statement & Audit Ledger                 -->
<!-- ========================================================================= -->
<div id="supplier-statement-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-4xl w-full max-h-[90vh] overflow-y-auto p-5 sm:p-6 shadow-2xl space-y-4 custom-scrollbar">
        
        <!-- Modal Header -->
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[22px]">receipt_long</span>
                </div>
                <div>
                    <h3 id="stmt-modal-supplier-name" class="font-headline-sm text-base sm:text-lg font-bold text-on-surface">
                        Supplier Financial Statement
                    </h3>
                    <p class="text-xs text-on-surface-variant">Vendor Restock Invoices &amp; Payment Disbursement Ledger</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printStatementSlip()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1.5 cursor-pointer transition-colors shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
                <button type="button" onclick="closeStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1.5 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[22px]">close</span>
                </button>
            </div>
        </div>

        <!-- Printable Statement Body -->
        <div id="printable-statement-area" class="space-y-4">
            <!-- Hospital Brand Header (Prints on physical sheet) -->
            <div class="p-4 rounded-xl bg-surface-container-lowest border border-outline-variant flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                <div>
                    <h4 class="font-bold text-base text-primary uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                    <p class="text-xs text-on-surface-variant">Pharmacy Inventory &amp; Accounts Payable Office • Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?></p>
                </div>
                <div class="text-left sm:text-right text-xs">
                    <p class="font-bold text-on-surface">Statement Date: <span class="font-mono"><?php echo date('M d, Y'); ?></span></p>
                    <p class="text-on-surface-variant">Supplier Credit Statement</p>
                </div>
            </div>

            <!-- Supplier Demographics & Summary Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-surface-container-low border border-outline-variant">
                <div class="space-y-1 text-xs">
                    <p class="text-[10px] uppercase font-bold text-on-surface-variant">Vendor Profile</p>
                    <h4 id="stmt-name" class="text-sm font-bold text-on-surface">Loading...</h4>
                    <p class="text-on-surface-variant flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">person</span>
                        Contact: <strong id="stmt-contact" class="text-on-surface">-</strong>
                    </p>
                    <p class="text-on-surface-variant font-mono flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">call</span>
                        Phone: <strong id="stmt-phone" class="text-on-surface">-</strong>
                    </p>
                    <p class="text-on-surface-variant flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">location_on</span>
                        Address: <span id="stmt-address">-</span>
                    </p>
                </div>

                <div class="flex items-center justify-start md:justify-end gap-2.5 flex-wrap">
                    <div class="p-3 rounded-xl bg-surface border border-outline-variant text-right min-w-[100px]">
                        <span class="text-[10px] text-on-surface-variant uppercase font-bold block">Total Invoiced</span>
                        <strong id="stmt-tot-invoiced" class="text-sm sm:text-base font-mono font-bold text-on-surface">$0.00</strong>
                    </div>
                    <div class="p-3 rounded-xl bg-surface border border-secondary/30 text-right min-w-[100px]">
                        <span class="text-[10px] text-secondary uppercase font-bold block">Total Paid</span>
                        <strong id="stmt-tot-paid" class="text-sm sm:text-base font-mono font-bold text-secondary">$0.00</strong>
                    </div>
                    <div class="p-3 rounded-xl bg-surface border border-error/30 text-right min-w-[110px]">
                        <span class="text-[10px] text-error uppercase font-bold block">Balance Due</span>
                        <strong id="stmt-tot-due" class="text-sm sm:text-base font-mono font-bold text-error">$0.00</strong>
                    </div>
                </div>
            </div>

            <!-- Tab 1: Purchase Orders Breakdown -->
            <div class="space-y-2">
                <h5 class="font-bold text-xs text-on-surface uppercase tracking-wider flex items-center gap-1.5 border-b border-outline-variant pb-1.5">
                    <span class="material-symbols-outlined text-[16px] text-primary">local_shipping</span>
                    Purchase Orders &amp; Restock Invoices
                </h5>
                <div class="overflow-x-auto custom-scrollbar border border-outline-variant rounded-xl">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-surface-container-low border-b border-outline-variant text-on-surface-variant font-bold">
                                <th class="py-2.5 px-3">PO #</th>
                                <th class="py-2.5 px-3">Purchase Date</th>
                                <th class="py-2.5 px-3">Items / Batch</th>
                                <th class="py-2.5 px-3 font-mono">Billed ($)</th>
                                <th class="py-2.5 px-3 font-mono">Paid ($)</th>
                                <th class="py-2.5 px-3 font-mono text-error">Due ($)</th>
                                <th class="py-2.5 px-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-purchases-tbody" class="divide-y divide-outline-variant/60 font-body-sm">
                            <tr>
                                <td colspan="7" class="py-6 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[20px] animate-spin align-middle mr-1">progress_activity</span>
                                    Loading statement data...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 2: Payments & Disbursements History -->
            <div class="space-y-2 pt-2">
                <h5 class="font-bold text-xs text-on-surface uppercase tracking-wider flex items-center gap-1.5 border-b border-outline-variant pb-1.5">
                    <span class="material-symbols-outlined text-[16px] text-secondary">history</span>
                    Disbursement &amp; Payment History Ledger
                </h5>
                <div class="overflow-x-auto custom-scrollbar border border-outline-variant rounded-xl">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-surface-container-low border-b border-outline-variant text-on-surface-variant font-bold">
                                <th class="py-2.5 px-3">Disbursement Date</th>
                                <th class="py-2.5 px-3">PO Reference #</th>
                                <th class="py-2.5 px-3">Payment Method</th>
                                <th class="py-2.5 px-3">Disbursed By</th>
                                <th class="py-2.5 px-3">Notes / Memo</th>
                                <th class="py-2.5 px-3 text-right font-mono text-secondary">Amount Paid ($)</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-payments-tbody" class="divide-y divide-outline-variant/60 font-body-sm">
                            <tr>
                                <td colspan="6" class="py-4 text-center text-on-surface-variant">
                                    No disbursements recorded for this vendor.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Printable Sign-off (Visible on print) -->
            <div class="pt-6 border-t border-outline-variant grid grid-cols-2 gap-8 text-xs text-on-surface-variant">
                <div>
                    <p class="font-semibold text-on-surface mb-6">Vendor / Supplier Representative:</p>
                    <div class="border-b border-dashed border-outline-variant w-48"></div>
                </div>
                <div class="text-right">
                    <p class="font-semibold text-on-surface mb-6">Hospital Accounts &amp; Finance Officer:</p>
                    <div class="border-b border-dashed border-outline-variant w-48 ml-auto"></div>
                </div>
            </div>
        </div>

        <!-- Modal Footer Actions -->
        <div class="flex flex-col sm:flex-row justify-between items-center gap-2 pt-3 border-t border-outline-variant">
            <span class="text-[11px] text-on-surface-variant">
                Official accounts payable records from MedCore HPMS.
            </span>
            <div class="flex items-center gap-2">
                <button type="button" onclick="closeStatementModal()" class="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                    Close
                </button>
                <button type="button" onclick="printStatementSlip()" class="px-4 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xs">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: Record Supplier Debt Payment (Settle Payables)                   -->
<!-- ========================================================================= -->
<div id="pay-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-5 sm:p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">payments</span>
                </div>
                <div>
                    <h3 class="font-headline-sm text-sm font-bold text-on-surface">Record Supplier Debt Payment</h3>
                    <p class="text-[11px] text-on-surface-variant">Disburse funds to settle outstanding vendor restock balance</p>
                </div>
            </div>
            <button type="button" onclick="closePayModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="hospital_debts.php" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="pay_supplier_debt">
            <input type="hidden" name="redirect" value="hospital_debts.php">
            <input type="hidden" id="pay-purchase-id" name="purchase_id" value="">

            <div class="p-3 bg-surface-container rounded-xl border border-outline-variant space-y-1">
                <span class="text-[10px] text-on-surface-variant uppercase font-bold">Supplier Account</span>
                <p id="pay-supplier-display-name" class="text-xs font-bold text-on-surface">Supplier Name</p>
                <p class="text-[11px] text-error font-mono font-semibold">
                    Outstanding Debt: <span id="pay-supplier-display-due">$0.00</span>
                </p>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Payment Amount ($ USD) *</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant font-mono font-bold">$</span>
                    <input id="pay-amount-input" 
                           type="number" 
                           step="0.01" 
                           min="0.01" 
                           required 
                           name="amount_paid" 
                           class="w-full pl-7 pr-3 py-2 bg-surface-container-low border border-outline-variant rounded-xl text-xs font-mono font-bold text-primary focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Disbursement Account *</label>
                <select name="payment_method" required class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                    <option value="cash">Cash on Hand (1010)</option>
                    <option value="mobile">Mobile Money EVC / Zaad (1020)</option>
                    <option value="bank">Bank Account (1030)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Notes / Transaction Memo (Optional)</label>
                <input type="text" 
                       name="notes" 
                       placeholder="e.g. Check #4021 or EVC transfer memo..." 
                       class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closePayModal()" class="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-5 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold shadow-xs flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                    Confirm &amp; Record Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: Filtering, Live Statement Fetching, & Printing                -->
<!-- ========================================================================= -->
<script>
    function handleRowClick(event, supplierId) {
        if (event.target.closest('button') || event.target.closest('a')) {
            return;
        }
        loadAndOpenStatement(supplierId);
    }

    function filterSuppliersTable(query) {
        const q = query.trim().toLowerCase();
        const clearBtn = document.getElementById('clear-table-search');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', q === '');
        }

        const rows = document.querySelectorAll('#supplier-table-body tr.supplier-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const contact = row.getAttribute('data-contact') || '';
            const phone = row.getAttribute('data-phone') || '';

            if (q === '' || name.includes(q) || contact.includes(q) || phone.includes(q)) {
                row.classList.remove('hidden');
                visibleCount++;
            } else {
                row.classList.add('hidden');
            }
        });

        const countBadge = document.getElementById('results-count-badge');
        if (countBadge) {
            countBadge.textContent = visibleCount + ' records';
        }
    }

    function clearTableSearch() {
        const input = document.getElementById('supplier-table-search');
        if (input) {
            input.value = '';
            filterSuppliersTable('');
            input.focus();
        }
    }

    function openPayModal(supplierId, supplierName, purchaseId, dueAmount) {
        document.getElementById('pay-purchase-id').value = purchaseId;
        document.getElementById('pay-supplier-display-name').textContent = supplierName;
        document.getElementById('pay-supplier-display-due').textContent = '$' + parseFloat(dueAmount).toFixed(2);
        document.getElementById('pay-amount-input').value = parseFloat(dueAmount).toFixed(2);
        document.getElementById('pay-amount-input').max = parseFloat(dueAmount).toFixed(2);
        document.getElementById('pay-supplier-modal').classList.remove('hidden');
    }

    function closePayModal() {
        document.getElementById('pay-supplier-modal').classList.add('hidden');
    }

    function loadAndOpenStatement(supplierId) {
        if (!supplierId || supplierId <= 0) {
            alert('Statement is not available for this record.');
            return;
        }

        const modal = document.getElementById('supplier-statement-modal');
        modal.classList.remove('hidden');

        // Reset display while loading
        document.getElementById('stmt-name').textContent = 'Loading statement data...';
        document.getElementById('stmt-contact').textContent = '...';
        document.getElementById('stmt-phone').textContent = '...';
        document.getElementById('stmt-address').textContent = '...';
        document.getElementById('stmt-tot-invoiced').textContent = '$0.00';
        document.getElementById('stmt-tot-paid').textContent = '$0.00';
        document.getElementById('stmt-tot-due').textContent = '$0.00';

        const purTbody = document.getElementById('stmt-purchases-tbody');
        purTbody.innerHTML = `
            <tr>
                <td colspan="7" class="py-8 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[24px] animate-spin align-middle mr-1 text-primary">progress_activity</span>
                    Loading full vendor restock orders and payment history...
                </td>
            </tr>
        `;

        const payTbody = document.getElementById('stmt-payments-tbody');
        payTbody.innerHTML = `
            <tr>
                <td colspan="6" class="py-4 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px] animate-spin align-middle mr-1">progress_activity</span>
                    Loading disbursement transactions...
                </td>
            </tr>
        `;

        fetch('../api/live_sync.php?module=supplier_debt_statement&supplier_id=' + encodeURIComponent(supplierId))
            .then(res => res.json())
            .then(data => {
                if (data && data.status === 'success' && data.statement) {
                    renderStatementData(data.statement);
                } else {
                    purTbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-error font-semibold">Unable to load supplier statement records.</td></tr>';
                    payTbody.innerHTML = '<tr><td colspan="6" class="py-4 text-center text-on-surface-variant">No records found.</td></tr>';
                }
            })
            .catch(err => {
                console.error('[STATEMENT FETCH ERROR]', err);
                purTbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-error font-semibold">A technical error occurred while fetching statement data.</td></tr>';
            });
    }

    function renderStatementData(stmt) {
        const s = stmt.supplier || {};
        document.getElementById('stmt-name').textContent = s.name || 'Vendor';
        document.getElementById('stmt-modal-supplier-name').textContent = 'Supplier Statement: ' + (s.name || 'Account');
        document.getElementById('stmt-contact').textContent = s.contact_person || 'N/A';
        document.getElementById('stmt-phone').textContent = s.phone || 'N/A';
        document.getElementById('stmt-address').textContent = s.address || 'Somalia';

        document.getElementById('stmt-tot-invoiced').textContent = '$' + parseFloat(stmt.total_invoiced || 0).toFixed(2);
        document.getElementById('stmt-tot-paid').textContent = '$' + parseFloat(stmt.total_paid || 0).toFixed(2);
        document.getElementById('stmt-tot-due').textContent = '$' + parseFloat(stmt.total_due || 0).toFixed(2);

        // Render Purchases Table
        const purTbody = document.getElementById('stmt-purchases-tbody');
        purTbody.innerHTML = '';

        if (!stmt.purchases || stmt.purchases.length === 0) {
            purTbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-on-surface-variant">No restock purchase orders recorded for this supplier.</td></tr>';
        } else {
            stmt.purchases.forEach(pur => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low transition-colors';
                
                const dueVal = parseFloat(pur.due_amount || 0);
                const statusBadge = (pur.payment_status === 'paid' || dueVal <= 0.005)
                    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary/15 text-secondary border border-secondary/30">PAID</span>'
                    : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-error-container text-on-error-container border border-error/30">DUE</span>';

                const dateStr = pur.purchase_date ? pur.purchase_date.substring(0, 10) : (pur.created_at ? pur.created_at.substring(0, 10) : '-');
                const medName = pur.medication_name ? escapeHtml(pur.medication_name) : (pur.notes ? escapeHtml(pur.notes) : 'Medication Restock');
                const batchStr = pur.batch_number ? `Batch: ${escapeHtml(pur.batch_number)} (${pur.batch_quantity || 0} units)` : '';

                tr.innerHTML = `
                    <td class="py-2.5 px-3 font-mono font-bold text-primary">${escapeHtml(pur.po_number)}</td>
                    <td class="py-2.5 px-3 font-mono text-on-surface-variant">${dateStr}</td>
                    <td class="py-2.5 px-3">
                        <strong class="text-on-surface block text-xs">${medName}</strong>
                        ${batchStr ? `<span class="text-[11px] text-on-surface-variant block mt-0.5">${batchStr}</span>` : ''}
                    </td>
                    <td class="py-2.5 px-3 font-mono font-semibold text-on-surface">$${parseFloat(pur.net_amount).toFixed(2)}</td>
                    <td class="py-2.5 px-3 font-mono font-semibold text-secondary">$${parseFloat(pur.paid_amount).toFixed(2)}</td>
                    <td class="py-2.5 px-3 font-mono font-bold text-error">$${dueVal.toFixed(2)}</td>
                    <td class="py-2.5 px-3 text-center">${statusBadge}</td>
                `;
                purTbody.appendChild(tr);
            });
        }

        // Render Payments History Table
        const payTbody = document.getElementById('stmt-payments-tbody');
        payTbody.innerHTML = '';

        if (!stmt.payments || stmt.payments.length === 0) {
            payTbody.innerHTML = '<tr><td colspan="6" class="py-4 text-center text-on-surface-variant">No disbursement records found for this vendor.</td></tr>';
        } else {
            stmt.payments.forEach(pay => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low transition-colors';
                const method = (pay.payment_method || 'Cash').toUpperCase();

                tr.innerHTML = `
                    <td class="py-2.5 px-3 font-mono text-on-surface-variant">${escapeHtml(pay.paid_at || '-')}</td>
                    <td class="py-2.5 px-3 font-mono font-bold text-primary">${escapeHtml(pay.po_number || '-')}</td>
                    <td class="py-2.5 px-3">
                        <span class="px-2 py-0.5 rounded font-bold text-[10px] bg-surface-container text-on-surface uppercase border border-outline-variant">
                            ${method}
                        </span>
                    </td>
                    <td class="py-2.5 px-3 text-on-surface-variant font-medium">${escapeHtml(pay.paid_by_name || 'Disbursement Officer')}</td>
                    <td class="py-2.5 px-3 text-on-surface-variant text-[11px]">${escapeHtml(pay.notes || '-')}</td>
                    <td class="py-2.5 px-3 text-right font-mono font-bold text-secondary">
                        +$${parseFloat(pay.amount_paid).toFixed(2)}
                    </td>
                `;
                payTbody.appendChild(tr);
            });
        }
    }

    function closeStatementModal() {
        document.getElementById('supplier-statement-modal').classList.add('hidden');
    }

    function printStatementSlip() {
        const content = document.getElementById('printable-statement-area').innerHTML;
        const printWindow = window.open('', '_blank', 'width=850,height=900');
        if (!printWindow) {
            alert('Please allow popups to print the statement slip.');
            return;
        }

        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Supplier Statement - <?php echo htmlspecialchars(HOSPITAL_NAME); ?></title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                        color: #111827;
                        background: #fff;
                        padding: 30px;
                        margin: 0;
                        font-size: 13px;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 10px;
                        margin-bottom: 15px;
                        font-size: 12px;
                    }
                    th, td {
                        border: 1px solid #e5e7eb;
                        padding: 7px 10px;
                        text-align: left;
                    }
                    th {
                        background-color: #f9fafb;
                        font-weight: bold;
                    }
                    .font-mono {
                        font-family: monospace;
                    }
                    .text-right {
                        text-align: right;
                    }
                    .text-center {
                        text-align: center;
                    }
                    .text-error {
                        color: #b91c1c;
                        font-weight: bold;
                    }
                    .text-secondary {
                        color: #047857;
                        font-weight: bold;
                    }
                    .grid {
                        display: flex;
                        justify-content: space-between;
                        gap: 20px;
                    }
                    @media print {
                        body { padding: 15px; }
                        button { display: none !important; }
                    }
                </style>
            </head>
            <body>
                ${content}
                <script>
                    window.onload = function() {
                        window.print();
                        setTimeout(() => window.close(), 500);
                    };
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
