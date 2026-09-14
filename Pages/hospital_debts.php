<?php
/**
 * MedCore Systems - Hospital Debts & Accounts Payable Ledger
 * Provides a clean, focused, and fast interface for tracking hospital debts,
 * outstanding vendor restock balances, and reviewing complete supplier purchase statements.
 * Standardized to mirror the exact clean architecture and running balance ledger of patient_debts.php.
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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security token invalid or expired. Please refresh and try again.';
    } else {
        $purchaseId    = (int)($_POST['purchase_id'] ?? 0);
        $amountPaid    = (float)($_POST['amount_paid'] ?? 0);
        $paymentMethod = sanitizeString($_POST['payment_method'] ?? 'cash');
        $notes         = sanitizeString($_POST['notes'] ?? '');

        if ($purchaseId <= 0 || $amountPaid <= 0.001) {
            $errorMessage = 'Please provide a valid purchase order and a positive payment amount.';
        } else {
            try {
                $payId = InventoryOperation::recordSupplierPayment(
                    $purchaseId,
                    $amountPaid,
                    $paymentMethod,
                    $notes ?: 'Supplier debt installment payment',
                    $userId
                );

                $paidFormatted = number_format($amountPaid, 2);
                $voucherNum = (is_int($payId) && $payId > 0) ? ('VOUCH-' . str_pad((string)$payId, 4, '0', STR_PAD_LEFT)) : 'VOUCH-DISBURSED';
                setFlashMessage('success', "Disbursement installment of \${$paidFormatted} recorded successfully! Voucher: {$voucherNum}");
                
                $redirectUrl = 'hospital_debts.php?filter=' . urlencode($_GET['filter'] ?? 'debtors_only') . '&search=' . urlencode($_GET['search'] ?? '');
                if (is_int($payId) && $payId > 0) {
                    $redirectUrl .= '&voucher=' . $payId;
                }
                header('Location: ' . $redirectUrl);
                exit;
            } catch (Exception $e) {
                $errorMessage = 'Payment processing failed: ' . $e->getMessage();
            }
        }
    }
}

$filter = sanitizeString($_GET['filter'] ?? 'debtors_only');
if (!in_array($filter, ['debtors_only', 'high_debt', 'all'], true)) {
    $filter = 'debtors_only';
}
$search = sanitizeString($_GET['search'] ?? '');

$metrics = AccountingOperation::getHospitalDebtsSummaryMetrics();
$records = AccountingOperation::getHospitalDebtsLedger($filter, $search);

// Pre-load Supplier Statements for fast instant modal rendering
$supplierStatements = [];
foreach ($records as $row) {
    $sId = (int)($row['supplier_id'] ?? 0);
    if ($sId > 0 && !isset($supplierStatements[$sId])) {
        $stmtData = InventoryOperation::getSupplierStatement($sId);
        if ($stmtData) {
            $supplierStatements[$sId] = $stmtData;
        }
    }
}

// Check if a voucher was recently issued to auto-open printable voucher modal
$recentVoucherId = (int)($_GET['voucher'] ?? 0);
$recentVoucher = null;
if ($recentVoucherId > 0) {
    $pdo = getDBConnection();
    $stmtVc = $pdo->prepare("
        SELECT 
            sp.*, 
            p.po_number, 
            p.supplier_id,
            s.name as supplier_name, 
            s.contact_person, 
            s.phone as supplier_phone,
            u.full_name as paid_by_name
        FROM supplier_payments sp
        JOIN purchases p ON sp.purchase_id = p.id
        JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN users u ON sp.paid_by = u.id
        WHERE sp.id = ?
    ");
    $stmtVc->execute([$recentVoucherId]);
    $recentVoucher = $stmtVc->fetch();
}

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
                <p class="font-bold">Debt Operation Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Disbursement Settled</p>
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
                    <p class="text-xs text-on-surface-variant">Accounts Payable &amp; Vendor Restock Ledger</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Directory
            </button>
            <a href="accounts_payable.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">account_balance</span>
                Accounts Payable (AP)
            </a>
            <a href="suppliers.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">local_shipping</span>
                Manage Suppliers
            </a>
            <a href="inventory_management.php" class="px-4 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">add_shopping_cart</span>
                New Restock Order
            </a>
        </div>
    </div>

    <!-- KPI Summary Stat Cards (Matching patient_debts.php Architecture) -->
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
                    High Debt (> $500)
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
                <input id="debt-table-search" 
                       type="text" 
                       value="<?php echo e($search); ?>"
                       oninput="filterDebtsTable(this.value)"
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
                <tbody id="debt-table-body" class="divide-y divide-outline-variant/60">
                    <?php if (empty($records)): ?>
                        <tr id="empty-state-row">
                            <td colspan="8" class="py-12 text-center text-on-surface-variant">
                                <div class="max-w-xs mx-auto space-y-2">
                                    <div class="w-12 h-12 rounded-full bg-secondary/15 text-secondary flex items-center justify-center mx-auto">
                                        <span class="material-symbols-outlined text-[28px]">check_circle</span>
                                    </div>
                                    <p class="font-bold text-sm text-on-surface">No outstanding hospital debts!</p>
                                    <p class="text-xs text-on-surface-variant">All supplier restock orders are fully settled.</p>
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
                                $latestPO = (int)($row['latest_unpaid_purchase_id'] ?? ($row['latest_purchase_id'] ?? 0));
                                $latestPONum = $row['latest_unpaid_po_number'] ?? ('PO-' . $latestPO);
                            ?>
                            <tr class="debt-row hover:bg-surface-container-low transition-colors group cursor-pointer"
                                data-name="<?php echo e(strtolower($name)); ?>"
                                data-contact="<?php echo e(strtolower($contact)); ?>"
                                data-phone="<?php echo e(strtolower($phone)); ?>"
                                data-due="<?php echo $due; ?>"
                                onclick="openSupplierStatementModal(<?php echo $sId; ?>)">
                                
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
                                        <span class="material-symbols-outlined text-[13px]">phone</span>
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
                                        <?php if ($isDebt && $latestPO > 0): ?>
                                            <button type="button" 
                                                    onclick="event.stopPropagation(); openPaySupplierDebtModal(<?php echo $sId; ?>, <?php echo $latestPO; ?>, '<?php echo e(addslashes($name)); ?>', '<?php echo e(addslashes($latestPONum)); ?>', <?php echo $due; ?>)"
                                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-xs flex items-center gap-1.5 cursor-pointer hover:shadow-md"
                                                    title="Disburse Payment to Settle Supplier Debt">
                                                <span class="material-symbols-outlined text-[16px]">payments</span>
                                                Pay Debt ($<?php echo number_format($due, 2); ?>)
                                            </button>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-secondary bg-secondary/10 rounded-lg">
                                                <span class="material-symbols-outlined text-[15px]">check_circle</span>
                                                Settled
                                            </span>
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
<!-- MODAL 1: Unified Supplier AP Statement & Running Balance Ledger           -->
<!-- ========================================================================= -->
<div id="supplier-statement-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-4xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl space-y-4 custom-scrollbar">
        <!-- Modal Top Bar -->
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[26px]">store</span>
                <div>
                    <h3 id="stmt-modal-supplier-name" class="font-headline-sm text-base sm:text-lg font-bold text-on-surface">Supplier Financial Statement</h3>
                    <p class="text-xs text-on-surface-variant">Consolidated accounts payable ledger with running debt balance.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" 
                        id="stmt-pay-debt-btn"
                        onclick="payDebtFromStatement()" 
                        class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer transition-colors shadow-xs">
                    <span class="material-symbols-outlined text-[16px]">payments</span>
                    Pay Debt
                </button>
                <button type="button" onclick="printSupplierStatement()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
                <button type="button" onclick="closeSupplierStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[22px]">close</span>
                </button>
            </div>
        </div>

        <!-- Statement Printable Card -->
        <div id="printable-supplier-statement-area" class="bg-white text-black p-6 rounded-xl border border-gray-200 font-sans space-y-4 shadow-sm">
            <!-- Header Info -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-gray-200 pb-3">
                <div>
                    <h4 class="font-bold text-lg text-gray-900 tracking-tight"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                    <p class="text-xs text-gray-600">Pharmacy Supplies &amp; Accounts Payable Office • Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?></p>
                </div>
                <div class="text-left sm:text-right text-xs">
                    <p class="font-bold text-gray-900">Statement Date: <span class="font-mono"><?php echo date('M d, Y'); ?></span></p>
                    <p class="text-gray-500">Official Vendor AP Statement</p>
                </div>
            </div>

            <!-- Supplier Demographics & Financial Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 rounded-xl bg-gray-50 border border-gray-200 text-xs">
                <div class="space-y-1">
                    <p class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Vendor Account</p>
                    <h4 id="stmt-supplier-name-text" class="text-sm font-bold text-gray-900">--</h4>
                    <p class="text-gray-600">Contact: <span id="stmt-contact-text" class="font-semibold text-gray-900">--</span></p>
                    <p class="text-gray-600 font-mono">Phone: <span id="stmt-phone-text" class="text-gray-900">--</span></p>
                    <p class="text-gray-600">Address: <span id="stmt-address-text" class="text-gray-900">--</span></p>
                </div>
                <div class="flex items-center justify-start md:justify-end gap-3 flex-wrap">
                    <div class="p-2.5 rounded-lg bg-white border border-gray-200 text-right min-w-[95px]">
                        <span class="text-[10px] text-gray-500 uppercase font-bold block">Total Billed</span>
                        <strong id="stmt-tot-invoiced" class="text-sm font-mono font-bold text-gray-900">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white border border-emerald-200 text-right min-w-[95px]">
                        <span class="text-[10px] text-emerald-700 uppercase font-bold block">Total Paid</span>
                        <strong id="stmt-tot-paid" class="text-sm font-mono font-bold text-emerald-700">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-white border border-red-200 text-right min-w-[105px]">
                        <span class="text-[10px] text-red-600 uppercase font-bold block">Balance Due</span>
                        <strong id="stmt-tot-due" class="text-sm font-mono font-bold text-red-600">$0.00</strong>
                    </div>
                </div>
            </div>

            <!-- Running Balance Ledger Table (Matching Accounts Receivable Architecture) -->
            <div class="overflow-x-auto custom-scrollbar border border-gray-200 rounded-xl">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200 text-gray-700 font-bold">
                            <th class="py-2.5 px-3">Date</th>
                            <th class="py-2.5 px-3">Transaction</th>
                            <th class="py-2.5 px-3">Number / Ref</th>
                            <th class="py-2.5 px-3">Account</th>
                            <th class="py-2.5 px-3 text-right">Amount ($)</th>
                            <th class="py-2.5 px-3 text-right">Balance ($)</th>
                        </tr>
                    </thead>
                    <tbody id="stmt-ledger-tbody" class="divide-y divide-gray-200 font-sans text-xs">
                        <!-- Dynamic Ledger Rows -->
                    </tbody>
                    <tfoot id="stmt-ledger-tfoot" class="border-t-2 border-gray-800 font-bold bg-gray-50 text-xs">
                        <!-- Dynamic summary rows injected by JS -->
                    </tfoot>
                </table>
            </div>

            <p class="text-[10px] text-gray-500 italic text-center pt-2">
                Click any Purchase Order or Payment transaction line above to view and print the official underlying document.
            </p>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: Printable Purchase Order Document (PO Invoice View)              -->
<!-- ========================================================================= -->
<div id="po-document-modal" class="fixed inset-0 z-[70] bg-black/70 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full max-h-[90vh] overflow-y-auto p-5 sm:p-6 shadow-2xl space-y-4 custom-scrollbar">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[22px]">local_shipping</span>
                <h3 class="font-headline-sm text-sm font-bold text-on-surface">Purchase Order Document</h3>
            </div>
            <button type="button" onclick="closePODocumentModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable PO Document Slip -->
        <div id="printable-po-doc" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-sans space-y-3 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2 text-center">
                <h4 class="font-bold text-base uppercase tracking-wide"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p class="text-[11px] text-gray-600">Pharmacy Inventory &amp; Procurement Unit</p>
                <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-gray-100 border border-gray-300 rounded inline-block text-gray-800 mt-1">
                    PURCHASE ORDER INVOICE
                </span>
            </div>

            <div class="border-b border-dashed border-gray-300 py-2 text-left text-xs space-y-1">
                <div class="flex justify-between"><span class="text-gray-500">Supplier:</span> <span id="po-doc-supplier" class="font-bold text-gray-900">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Contact / Phone:</span> <span id="po-doc-contact" class="font-mono font-medium">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">PO Number:</span> <span id="po-doc-number" class="font-mono font-bold text-blue-700">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Purchase Date:</span> <span id="po-doc-date" class="font-mono">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Purchased By:</span> <span id="po-doc-purchaser">--</span></div>
            </div>

            <!-- Itemized Products Table -->
            <div class="py-1">
                <p class="text-[10px] uppercase font-bold text-gray-500 mb-1">Medication Batches Received:</p>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <table class="w-full text-left text-[11px]">
                        <thead class="bg-gray-100 text-gray-700 font-bold border-b border-gray-200">
                            <tr>
                                <th class="py-1.5 px-2">Item / Medication</th>
                                <th class="py-1.5 px-2 text-center">Qty</th>
                                <th class="py-1.5 px-2 text-right">Cost ($)</th>
                                <th class="py-1.5 px-2 text-right">Total ($)</th>
                            </tr>
                        </thead>
                        <tbody id="po-doc-items-tbody" class="divide-y divide-gray-100">
                            <!-- Items -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Financials Breakdown -->
            <div class="border-t border-dashed border-gray-300 pt-2 text-xs space-y-1">
                <div class="flex justify-between"><span class="text-gray-600">Subtotal:</span> <span id="po-doc-subtotal" class="font-mono font-bold">$0.00</span></div>
                <div id="po-doc-disc-row" class="flex justify-between text-gray-600"><span>Discount:</span> <span id="po-doc-discount" class="font-mono text-emerald-700">-$0.00</span></div>
                <div class="flex justify-between font-bold text-sm border-t border-gray-200 pt-1">
                    <span>Net Total Billed:</span> <span id="po-doc-net" class="font-mono">$0.00</span>
                </div>
                <div class="flex justify-between text-emerald-700"><span>Paid Upfront:</span> <span id="po-doc-paid" class="font-mono">-$0.00</span></div>
                <div class="flex justify-between font-bold text-red-600 border-t border-dashed border-gray-200 pt-1">
                    <span>Remaining Due:</span> <span id="po-doc-due" class="font-mono text-sm">$0.00</span>
                </div>
            </div>

            <div class="text-[10px] text-gray-500 text-center pt-2">
                Official stock procurement record verified in MedCore HPMS.
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="closePODocumentModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                Back to Statement
            </button>
            <button type="button" onclick="printPODocument()" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container transition-colors flex items-center gap-1 cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Document
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: Printable Disbursement Voucher (Payment Receipt View)            -->
<!-- ========================================================================= -->
<div id="disbursement-voucher-modal" class="fixed inset-0 z-[70] bg-black/70 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full max-h-[90vh] overflow-y-auto p-5 sm:p-6 shadow-2xl space-y-4 custom-scrollbar">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[22px]">receipt_long</span>
                <h3 class="font-headline-sm text-sm font-bold text-on-surface">Payment Voucher Slip</h3>
            </div>
            <button type="button" onclick="closeDisbursementVoucherModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Voucher Slip -->
        <div id="printable-voucher-doc" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono text-center space-y-2.5 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2">
                <h4 class="font-bold text-base uppercase tracking-wide"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p class="text-[10px] text-gray-600">Disbursement &amp; Supplier Accounts Office</p>
                <p class="text-[9px] text-gray-500">Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?></p>
            </div>

            <div class="pt-1">
                <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-emerald-100 border border-emerald-300 rounded inline-block text-emerald-800">
                    ✓ SUPPLIER DISBURSEMENT VOUCHER
                </span>
            </div>

            <div class="py-1">
                <span class="text-[9px] uppercase font-bold text-gray-500 tracking-wider">Amount Disbursed</span>
                <div id="vouch-doc-amount" class="text-2xl font-extrabold tracking-wider my-0.5 text-black">$0.00</div>
                <span id="vouch-doc-method" class="text-[10px] font-bold text-gray-700 bg-gray-100 px-2 py-0.5 rounded border border-gray-200 inline-block uppercase">CASH</span>
            </div>

            <div class="border-t border-b border-dashed border-gray-300 py-2 text-left text-[11px] space-y-1">
                <div class="flex justify-between"><span class="text-gray-500">Voucher #:</span> <span id="vouch-doc-num" class="font-bold text-gray-900">VOUCH-0001</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Supplier:</span> <span id="vouch-doc-supplier" class="font-bold text-gray-900">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Against PO #:</span> <span id="vouch-doc-po" class="font-bold font-mono text-blue-700">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Disbursement Date:</span> <span id="vouch-doc-date">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Disbursed By:</span> <span id="vouch-doc-payer">--</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Memo / Notes:</span> <span id="vouch-doc-notes" class="text-gray-800 truncate max-w-[180px]">--</span></div>
            </div>

            <div class="pt-2 text-left text-[10px] space-y-4">
                <div class="flex justify-between gap-4 pt-3">
                    <div class="border-t border-dashed border-gray-400 pt-1 text-center w-28">
                        <span class="text-[9px] text-gray-600 block">Received By (Vendor)</span>
                    </div>
                    <div class="border-t border-dashed border-gray-400 pt-1 text-center w-28">
                        <span class="text-[9px] text-gray-600 block">Authorized Finance</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="closeDisbursementVoucherModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                Back to Statement
            </button>
            <button type="button" onclick="printDisbursementVoucher()" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container transition-colors flex items-center gap-1 cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Voucher
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: Quick Supplier Debt Installment Disbursement                     -->
<!-- ========================================================================= -->
<div id="pay-supplier-debt-modal" class="fixed inset-0 z-[70] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-5 sm:p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">payments</span>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-on-surface">Disburse Supplier Payment</h3>
                    <p class="text-[11px] text-on-surface-variant">Settle outstanding vendor restock balance</p>
                </div>
            </div>
            <button type="button" onclick="closePaySupplierDebtModal()" class="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-on-surface cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>

        <form method="POST" action="hospital_debts.php" class="space-y-4" id="pay-supplier-debt-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <input type="hidden" name="action" value="pay_supplier_debt">
            <input type="hidden" name="purchase_id" id="pay-purchase-id" value="0">

            <!-- Summary Supplier & Debt Badge -->
            <div class="p-3.5 rounded-xl bg-surface-container border border-outline-variant/60 space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-[10px] text-on-surface-variant block uppercase font-bold tracking-wider">Supplier / Vendor</span>
                        <span class="font-bold text-sm text-on-surface" id="pay-supplier-name">-</span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-on-surface-variant block uppercase font-bold tracking-wider">Outstanding Debt</span>
                        <span class="font-mono font-bold text-base text-error" id="pay-outstanding-due">$0.00</span>
                    </div>
                </div>

                <!-- PO Reference or Selector -->
                <div class="pt-2 border-t border-outline-variant/50">
                    <label class="text-[11px] font-bold text-on-surface-variant block mb-1">Target Purchase Order for Settlement:</label>
                    <div id="pay-po-select-container" class="hidden">
                        <select id="pay-po-select" 
                                onchange="onPayPOSuplierSelectChange(this)"
                                class="w-full bg-surface border border-outline-variant rounded-lg px-2.5 py-1.5 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none">
                            <!-- Populated if multiple unpaid POs exist -->
                        </select>
                    </div>
                    <div id="pay-po-static-badge" class="flex items-center gap-1.5 text-xs font-mono font-bold text-primary">
                        <span class="material-symbols-outlined text-[15px]">description</span>
                        <span id="pay-po-num">-</span>
                    </div>
                </div>
            </div>

            <!-- Amount to Pay Input -->
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-on-surface flex items-center gap-1">
                        Amount to Disburse ($)
                        <span class="text-error">*</span>
                    </label>
                    <div class="flex items-center gap-1">
                        <button type="button" onclick="setQuickPaymentAmount(1.0)" class="text-[10px] px-2 py-0.5 rounded bg-primary/10 text-primary hover:bg-primary/20 font-bold cursor-pointer">100% Full</button>
                        <button type="button" onclick="setQuickPaymentAmount(0.5)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container text-on-surface hover:bg-surface-container-high font-bold cursor-pointer">50%</button>
                        <button type="button" onclick="setQuickPaymentAmount(0.25)" class="text-[10px] px-2 py-0.5 rounded bg-surface-container text-on-surface hover:bg-surface-container-high font-bold cursor-pointer">25%</button>
                    </div>
                </div>
                <div class="relative">
                    <span class="absolute left-3 top-2.5 text-on-surface-variant font-mono font-bold">$</span>
                    <input type="number" 
                           step="0.01" 
                           min="0.01" 
                           name="amount_paid" 
                           id="pay-amount-input" 
                           required 
                           oninput="updateSupplierPaymentCalc()"
                           class="w-full bg-surface border border-outline-variant rounded-xl pl-8 pr-3 py-2 text-base font-mono font-bold text-on-surface focus:border-primary outline-none transition-colors">
                </div>
            </div>

            <!-- Live Running Balance Calculation Preview -->
            <div class="p-3 rounded-xl bg-surface-container-low border border-outline-variant space-y-1.5 text-xs">
                <div class="flex justify-between text-on-surface-variant">
                    <span>Previous Balance (Deyntii hore):</span>
                    <span class="font-mono font-semibold" id="calc-prev-bal">$0.00</span>
                </div>
                <div class="flex justify-between text-secondary">
                    <span>Disbursement (Qaybta la bixinayo):</span>
                    <span class="font-mono font-bold" id="calc-paying-amt">-$0.00</span>
                </div>
                <div class="border-t border-outline-variant/60 pt-1 flex justify-between items-center">
                    <span class="font-bold text-on-surface">Remaining Debt (Hadhaaga):</span>
                    <span class="font-mono font-bold text-sm text-error" id="calc-rem-bal">$0.00</span>
                </div>
            </div>

            <!-- Payment Method (Asset Accounts) -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-on-surface">Disbursement Account (Laga jarayo)</label>
                <select name="payment_method" class="w-full bg-surface border border-outline-variant rounded-xl px-3 py-2 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                    <option value="cash">Cash on Hand (1010 - Khasnadda)</option>
                    <option value="mobile">Mobile Money (1020 - EVC Plus / Zaad / Sahal)</option>
                    <option value="bank">Bank Account (1030 - Commercial Banks)</option>
                </select>
            </div>

            <!-- Notes -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-on-surface">Notes / Memo (Optional)</label>
                <input type="text" name="notes" placeholder="e.g. Installment for batch restock, check #4421" class="w-full bg-surface border border-outline-variant rounded-xl px-3 py-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closePaySupplierDebtModal()" class="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xs">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Confirm &amp; Record Disbursement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: Unified Ledger, Statement & Instant Voucher Handling          -->
<!-- ========================================================================= -->
<script>
    const preloadedSupplierStatements = <?php echo json_encode($supplierStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let currentActiveSupplierId = null;
    let currentDebtDue = 0.0;

    function formatDateLedger(dateStr) {
        if (!dateStr) return '--';
        try {
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr.split(' ')[0] || dateStr;
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const m = months[d.getMonth()];
            const day = String(d.getDate()).padStart(2, '0');
            const y = d.getFullYear();
            return `${m} ${day}, ${y}`;
        } catch (e) {
            return dateStr;
        }
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

    function round2(val) {
        return Math.round((val + Number.EPSILON) * 100) / 100;
    }

    function openSupplierStatementModal(supplierId) {
        currentActiveSupplierId = supplierId;
        let stmt = preloadedSupplierStatements[supplierId];

        if (!stmt) {
            alert('Statement data not found for this supplier.');
            return;
        }

        renderSupplierStatementModal(supplierId, stmt);
    }

    function renderSupplierStatementModal(supplierId, stmt) {
        const sup = stmt.supplier || {};
        document.getElementById('stmt-modal-supplier-name').textContent = (sup.name || 'Supplier') + ' - Financial Statement';
        document.getElementById('stmt-supplier-name-text').textContent = sup.name || 'N/A';
        document.getElementById('stmt-contact-text').textContent = sup.contact_person || 'N/A';
        document.getElementById('stmt-phone-text').textContent = sup.phone || 'N/A';
        document.getElementById('stmt-address-text').textContent = sup.address || 'Somalia';

        const totInvoiced = parseFloat(stmt.total_invoiced || 0);
        const totPaid = parseFloat(stmt.total_paid || 0);
        const totDue = parseFloat(stmt.total_due || 0);

        document.getElementById('stmt-tot-invoiced').textContent = '$' + totInvoiced.toFixed(2);
        document.getElementById('stmt-tot-paid').textContent = '$' + totPaid.toFixed(2);
        document.getElementById('stmt-tot-due').textContent = '$' + totDue.toFixed(2);

        // Control Pay Debt Button in Statement Header
        const payBtn = document.getElementById('stmt-pay-debt-btn');
        if (totDue > 0.005) {
            payBtn.classList.remove('hidden');
        } else {
            payBtn.classList.add('hidden');
        }

        // Build Chronological Running Balance Ledger
        const ledgerItems = [];

        // 1. Add Purchases (Invoices)
        (stmt.purchases || []).forEach(p => {
            const rawDate = p.purchase_date || p.created_at || '';
            ledgerItems.push({
                type: 'Purchase Order',
                rawDate: rawDate,
                date: formatDateLedger(rawDate),
                num: p.po_number || ('PO-' + p.id),
                account: 'Accounts Payable',
                amount: parseFloat(p.net_amount || p.total_cost || 0),
                isPayment: false,
                purchaseId: p.id,
                itemSummary: p.item_summary || 'Medication Restock'
            });
        });

        // 2. Add Supplier Disbursements (Payments)
        (stmt.payments || []).forEach(pay => {
            const rawDate = pay.paid_at || '';
            ledgerItems.push({
                type: 'Payment',
                rawDate: rawDate,
                date: formatDateLedger(rawDate),
                num: 'VOUCH-' + String(pay.id).padStart(4, '0'),
                account: 'Accounts Payable',
                amount: -Math.abs(parseFloat(pay.amount_paid || 0)),
                isPayment: true,
                payId: pay.id,
                poNumber: pay.po_number || ''
            });
        });

        // 3. Sort chronologically ascending
        ledgerItems.sort((a, b) => {
            if (a.rawDate < b.rawDate) return -1;
            if (a.rawDate > b.rawDate) return 1;
            if (a.type === 'Purchase Order' && b.type !== 'Purchase Order') return -1;
            if (a.type !== 'Purchase Order' && b.type === 'Purchase Order') return 1;
            return 0;
        });

        // 4. Compute running balance
        let runningBalance = 0.0;
        ledgerItems.forEach(item => {
            runningBalance += item.amount;
            item.balance = runningBalance;
        });

        // 5. Render rows into tbody
        const tbody = document.getElementById('stmt-ledger-tbody');
        tbody.innerHTML = '';

        if (ledgerItems.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="py-8 text-center text-gray-500">
                        <span class="material-symbols-outlined text-[32px] text-emerald-600 block mb-1">check_circle</span>
                        <p class="font-bold text-xs text-gray-800">No ledger transactions found</p>
                        <p class="text-[11px] text-gray-500">This supplier has no restock purchases or payments.</p>
                    </td>
                </tr>
            `;
        } else {
            // Group Heading: Supplier Name
            const grpTr = document.createElement('tr');
            grpTr.className = 'bg-gray-50/80 border-b border-gray-200';
            grpTr.innerHTML = `
                <td colspan="6" class="py-2.5 px-3 font-extrabold text-gray-900 text-sm tracking-tight">
                    ${escapeHtml(sup.name || 'Supplier Account')}
                </td>
            `;
            tbody.appendChild(grpTr);

            // Render each chronological transaction line
            ledgerItems.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-blue-50/60 cursor-pointer transition-colors group';

                if (item.isPayment) {
                    tr.onclick = () => viewDisbursementVoucher(supplierId, item.payId);
                    tr.title = 'Click to view and print official disbursement voucher';
                    tr.innerHTML = `
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3 font-semibold text-gray-800 flex items-center gap-1">
                            <span class="material-symbols-outlined text-emerald-600 text-[14px]">payments</span>
                            Payment
                        </td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewDisbursementVoucher(${supplierId}, ${item.payId})" 
                                    class="font-mono font-bold text-emerald-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">receipt_long</span>
                                ${escapeHtml(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-emerald-700">-${Math.abs(item.amount).toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                } else {
                    tr.onclick = () => viewPODocument(supplierId, item.purchaseId);
                    tr.title = 'Click to view and print official purchase order document';
                    tr.innerHTML = `
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3 font-semibold text-gray-800 flex items-center gap-1">
                            <span class="material-symbols-outlined text-blue-600 text-[14px]">local_shipping</span>
                            Purchase Order
                        </td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewPODocument(${supplierId}, ${item.purchaseId})" 
                                    class="font-mono font-bold text-blue-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">description</span>
                                ${escapeHtml(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-gray-900">${item.amount.toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                }
                tbody.appendChild(tr);
            });
        }

        // 6. Render Ledger Summary Footers
        const tfoot = document.getElementById('stmt-ledger-tfoot');
        const finalBalance = (ledgerItems.length > 0) ? ledgerItems[ledgerItems.length - 1].balance : totDue;
        const finalBalFormatted = Math.abs(finalBalance).toFixed(2);
        tfoot.innerHTML = `
            <tr class="border-t border-gray-400 font-bold">
                <td colspan="4" class="py-2 px-3 text-gray-900">Total ${escapeHtml(sup.name || 'Supplier Account')}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
            </tr>
            <tr class="border-b-4 border-double border-gray-900 bg-gray-100/70 font-extrabold">
                <td colspan="4" class="py-2 px-3 uppercase tracking-wider text-gray-900">TOTAL ENDING BALANCE DUE</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-red-600">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-red-600">${finalBalFormatted}</td>
            </tr>
        `;

        document.getElementById('supplier-statement-modal').classList.remove('hidden');
    }

    function closeSupplierStatementModal() {
        document.getElementById('supplier-statement-modal').classList.add('hidden');
    }

    function printSupplierStatement() {
        const target = document.getElementById('printable-supplier-statement-area');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // View Purchase Order Document Modal
    function viewPODocument(supplierId, purchaseId) {
        const stmt = preloadedSupplierStatements[supplierId];
        if (!stmt) return;

        const pur = (stmt.purchases || []).find(p => parseInt(p.id) === parseInt(purchaseId));
        if (!pur) {
            alert('Purchase Order details not found.');
            return;
        }

        const sup = stmt.supplier || {};
        document.getElementById('po-doc-supplier').textContent = sup.name || 'Supplier';
        document.getElementById('po-doc-contact').textContent = (sup.contact_person || 'N/A') + ' • ' + (sup.phone || '');
        document.getElementById('po-doc-number').textContent = pur.po_number || ('PO-' + pur.id);
        document.getElementById('po-doc-date').textContent = pur.purchase_date || pur.created_at || 'N/A';
        document.getElementById('po-doc-purchaser').textContent = pur.purchaser_name || 'Pharmacist / Admin';

        const itemsTbody = document.getElementById('po-doc-items-tbody');
        itemsTbody.innerHTML = '';

        if (pur.items && Array.isArray(pur.items) && pur.items.length > 0) {
            pur.items.forEach(it => {
                const tr = document.createElement('tr');
                const cost = parseFloat(it.cost_price || it.unit_cost || 0);
                const qty = parseInt(it.quantity_received || it.quantity || 1);
                const tot = parseFloat(it.line_total || (cost * qty));

                tr.innerHTML = `
                    <td class="py-1.5 px-2">
                        <span class="font-bold text-gray-900 block">${escapeHtml(it.medication_name || 'Medication')}</span>
                        <span class="text-[9px] text-gray-500 font-mono">Batch: ${escapeHtml(it.batch_number || 'N/A')} • Exp: ${escapeHtml(it.expiry_date || 'N/A')}</span>
                    </td>
                    <td class="py-1.5 px-2 text-center font-mono">${qty}</td>
                    <td class="py-1.5 px-2 text-right font-mono">$${cost.toFixed(2)}</td>
                    <td class="py-1.5 px-2 text-right font-mono font-bold">$${tot.toFixed(2)}</td>
                `;
                itemsTbody.appendChild(tr);
            });
        } else {
            itemsTbody.innerHTML = `
                <tr>
                    <td colspan="4" class="py-3 text-center text-gray-400 italic">No batch itemization details found</td>
                </tr>
            `;
        }

        const subtotal = parseFloat(pur.total_amount || pur.net_amount || 0);
        const discount = parseFloat(pur.discount || 0);
        const net = parseFloat(pur.net_amount || subtotal);
        const paid = parseFloat(pur.paid_amount || 0);
        const due = parseFloat(pur.due_amount || 0);

        document.getElementById('po-doc-subtotal').textContent = '$' + subtotal.toFixed(2);
        const discRow = document.getElementById('po-doc-disc-row');
        if (discount > 0.005) {
            document.getElementById('po-doc-discount').textContent = '-$' + discount.toFixed(2);
            discRow.classList.remove('hidden');
        } else {
            discRow.classList.add('hidden');
        }
        document.getElementById('po-doc-net').textContent = '$' + net.toFixed(2);
        document.getElementById('po-doc-paid').textContent = '-$' + paid.toFixed(2);
        document.getElementById('po-doc-due').textContent = '$' + due.toFixed(2);

        document.getElementById('po-document-modal').classList.remove('hidden');
    }

    function closePODocumentModal() {
        document.getElementById('po-document-modal').classList.add('hidden');
    }

    function printPODocument() {
        const target = document.getElementById('printable-po-doc');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // View Disbursement Voucher Modal
    function viewDisbursementVoucher(supplierId, paymentId) {
        const stmt = preloadedSupplierStatements[supplierId];
        if (!stmt) return;

        const pay = (stmt.payments || []).find(p => parseInt(p.id) === parseInt(paymentId));
        if (!pay) {
            alert('Disbursement voucher details not found.');
            return;
        }

        const sup = stmt.supplier || {};
        const amount = parseFloat(pay.amount_paid || 0);

        document.getElementById('vouch-doc-amount').textContent = '$' + amount.toFixed(2);
        document.getElementById('vouch-doc-num').textContent = 'VOUCH-' + String(pay.id).padStart(4, '0');
        document.getElementById('vouch-doc-method').textContent = (pay.payment_method || 'CASH').toUpperCase();
        document.getElementById('vouch-doc-supplier').textContent = sup.name || 'Supplier';
        document.getElementById('vouch-doc-po').textContent = pay.po_number || 'General Clearance';
        document.getElementById('vouch-doc-date').textContent = pay.paid_at || 'N/A';
        document.getElementById('vouch-doc-payer').textContent = pay.paid_by_name || 'Hospital Finance Officer';
        document.getElementById('vouch-doc-notes').textContent = pay.notes || 'Supplier debt installment payment';

        document.getElementById('disbursement-voucher-modal').classList.remove('hidden');
    }

    function closeDisbursementVoucherModal() {
        document.getElementById('disbursement-voucher-modal').classList.add('hidden');
    }

    function printDisbursementVoucher() {
        const target = document.getElementById('printable-voucher-doc');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // Quick Supplier Debt Payment Modal Handlers
    function openPaySupplierDebtModal(supplierId, purchaseId, supplierName, poNumber, dueAmount) {
        currentDebtDue = parseFloat(dueAmount || 0);
        document.getElementById('pay-supplier-name').textContent = supplierName || 'Supplier';
        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);

        const stmt = preloadedSupplierStatements[supplierId];
        const unpaidPurchases = stmt ? (stmt.purchases || []).filter(p => parseFloat(p.due_amount || 0) > 0.005) : [];

        const selectContainer = document.getElementById('pay-po-select-container');
        const selectEl = document.getElementById('pay-po-select');
        const staticBadge = document.getElementById('pay-po-static-badge');

        if (unpaidPurchases.length > 1) {
            selectEl.innerHTML = '';
            unpaidPurchases.forEach(pur => {
                const opt = document.createElement('option');
                opt.value = pur.id;
                opt.textContent = `${pur.po_number || ('PO-' + pur.id)} (Due: $${parseFloat(pur.due_amount).toFixed(2)})`;
                opt.setAttribute('data-due', pur.due_amount);
                if (parseInt(pur.id) === parseInt(purchaseId)) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
            selectContainer.classList.remove('hidden');
            staticBadge.classList.add('hidden');
            document.getElementById('pay-purchase-id').value = selectEl.value;
            currentDebtDue = parseFloat(selectEl.options[selectEl.selectedIndex].getAttribute('data-due') || dueAmount);
        } else {
            selectContainer.classList.add('hidden');
            staticBadge.classList.remove('hidden');
            document.getElementById('pay-po-num').textContent = poNumber || ('PO-' + purchaseId);
            document.getElementById('pay-purchase-id').value = purchaseId;
        }

        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = currentDebtDue.toFixed(2);
        amtInput.max = currentDebtDue;

        updateSupplierPaymentCalc();
        document.getElementById('pay-supplier-debt-modal').classList.remove('hidden');
        amtInput.focus();
        amtInput.select();
    }

    function onPayPOSuplierSelectChange(sel) {
        const opt = sel.options[sel.selectedIndex];
        const due = parseFloat(opt.getAttribute('data-due') || 0);
        document.getElementById('pay-purchase-id').value = sel.value;
        currentDebtDue = due;
        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);
        
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = currentDebtDue.toFixed(2);
        amtInput.max = currentDebtDue;
        updateSupplierPaymentCalc();
    }

    function payDebtFromStatement() {
        if (!currentActiveSupplierId) return;
        const stmt = preloadedSupplierStatements[currentActiveSupplierId];
        if (!stmt) return;
        const unpaid = (stmt.purchases || []).filter(p => parseFloat(p.due_amount || 0) > 0.005);
        if (unpaid.length === 0) {
            alert('This supplier has no outstanding restock debt.');
            return;
        }
        const target = unpaid[0];
        const sup = stmt.supplier || {};
        openPaySupplierDebtModal(currentActiveSupplierId, target.id, sup.name, target.po_number, target.due_amount);
    }

    function closePaySupplierDebtModal() {
        document.getElementById('pay-supplier-debt-modal').classList.add('hidden');
    }

    function setQuickPaymentAmount(ratio) {
        const amt = Math.max(0.01, round2(currentDebtDue * ratio));
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = amt.toFixed(2);
        updateSupplierPaymentCalc();
    }

    function updateSupplierPaymentCalc() {
        const amtInput = document.getElementById('pay-amount-input');
        let paying = parseFloat(amtInput.value || 0);
        if (isNaN(paying) || paying < 0) paying = 0;

        const rem = Math.max(0, round2(currentDebtDue - paying));

        document.getElementById('calc-prev-bal').textContent = '$' + currentDebtDue.toFixed(2);
        document.getElementById('calc-paying-amt').textContent = '-$' + paying.toFixed(2);
        
        const remEl = document.getElementById('calc-rem-bal');
        if (rem <= 0.005) {
            remEl.innerHTML = '<span class="text-secondary font-bold">FULL SETTLEMENT ($0.00)</span>';
        } else {
            remEl.textContent = '$' + rem.toFixed(2);
        }
    }

    // Live Search Filter
    function filterDebtsTable(query) {
        const q = query.trim().toLowerCase();
        const clearBtn = document.getElementById('clear-table-search');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', q === '');
        }

        const rows = document.querySelectorAll('#debt-table-body tr.debt-row');
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
        const input = document.getElementById('debt-table-search');
        if (input) {
            input.value = '';
            filterDebtsTable('');
            input.focus();
        }
    }

    // Auto-display official voucher modal if a debt payment was just recorded
    <?php if (!empty($recentVoucher)): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const vc = <?php echo json_encode($recentVoucher, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        document.getElementById('vouch-doc-amount').textContent = '$' + parseFloat(vc.amount_paid || 0).toFixed(2);
        document.getElementById('vouch-doc-num').textContent = 'VOUCH-' + String(vc.id).padStart(4, '0');
        document.getElementById('vouch-doc-method').textContent = (vc.payment_method || 'CASH').toUpperCase();
        document.getElementById('vouch-doc-supplier').textContent = vc.supplier_name || 'Supplier';
        document.getElementById('vouch-doc-po').textContent = vc.po_number || 'Purchase Order';
        document.getElementById('vouch-doc-date').textContent = vc.paid_at || 'N/A';
        document.getElementById('vouch-doc-payer').textContent = vc.paid_by_name || 'Hospital Finance Officer';
        document.getElementById('vouch-doc-notes').textContent = vc.notes || 'Supplier debt installment payment';

        document.getElementById('disbursement-voucher-modal').classList.remove('hidden');
    });
    <?php endif; ?>
</script>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    .print-target-active, .print-target-active * {
        visibility: visible !important;
    }
    .print-target-active {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 20px !important;
        background: white !important;
        color: black !important;
        z-index: 999999 !important;
        border: none !important;
        box-shadow: none !important;
    }
    .print-target-active table {
        border-collapse: collapse !important;
        width: 100% !important;
    }
    .print-target-active th, .print-target-active td {
        border: 1px solid #ddd !important;
        padding: 6px 8px !important;
    }
    .print-target-active button {
        display: none !important;
    }
}
</style>

<?php include __DIR__ . '/../components/footer.php'; ?>
