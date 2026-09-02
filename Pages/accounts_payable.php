<?php
/**
 * MedCore Systems - Accounts Payable (AP) & Supplier Debt Ledger
 * Tracks all outstanding vendor restock payables and supplier installment disbursements.
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
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Supplier Actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'pay_supplier') {
        $result = InventoryController::handleSupplierDebtPayment($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

$ap = AccountingOperation::getAccountsPayableReport();
$payables = $ap['payables'];
$recentPayments = $ap['recent_payments'];

$allSuppliers = InventoryOperation::getAllSuppliers();
$supplierStatements = [];
foreach ($allSuppliers as $s) {
    $stmtData = InventoryOperation::getSupplierStatement((int)$s['id']);
    if ($stmtData) {
        $supplierStatements[(int)$s['id']] = $stmtData;
    }
}

$pageTitle = 'Accounts Payable (AP) & Supplier Statements - MedCore Systems';
$headerTitle = 'MedCore Management - Accounts Payable';
$activePage = 'accounting';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Payable Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Supplier Payment Recorded</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Title & Action Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="accounting_dashboard.php" class="text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-[22px]">arrow_back</span>
                </a>
                <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-error text-[28px]">store</span>
                    Accounts Payable (Deymaha Shirkadaha Daawada)
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                Outstanding liabilities owed to pharmaceutical distributors and vendors.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="suppliers.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-xs transition-all">
                <span class="material-symbols-outlined text-[18px]">local_shipping</span>
                Manage Suppliers
            </a>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Report
            </button>
        </div>
    </div>

    <!-- Top Summary Banner -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Total Payables Due (AP)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$ap['total_payable'], 2); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">Owed to <?php echo $ap['supplier_count']; ?> pharmaceutical vendor(s)</p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Active Vendor Restocks</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-on-surface font-mono"><?php echo count($payables); ?></h3>
                <p class="text-[11px] text-secondary font-semibold mt-1 flex items-center gap-1">
                    <span class="material-symbols-outlined text-[14px]">local_shipping</span>
                    Stock received &amp; on shelf
                </p>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Registered Suppliers</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-primary font-mono"><?php echo count($allSuppliers); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">Active pharmaceutical distributors</p>
            </div>
        </div>
    </div>

    <!-- Active Vendor Payables Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">receipt</span>
                    Supplier Payables Ledger (Deymaha Shirkadaha lagu Leeyahay)
                </h3>
                <p class="text-[11px] text-on-surface-variant">Invoices generated upon warehouse medicine restock.</p>
            </div>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Restock PO #</th>
                        <th class="py-3 px-3">Supplier Name</th>
                        <th class="py-3 px-3">Contact</th>
                        <th class="py-3 px-3">Invoice Date</th>
                        <th class="py-3 px-3">Total Cost ($)</th>
                        <th class="py-3 px-3">Paid ($)</th>
                        <th class="py-3 px-3">Balance Due ($)</th>
                        <th class="py-3 px-3">Age</th>
                        <th class="py-3 px-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($payables)): ?>
                        <tr>
                            <td colspan="9" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[32px] text-secondary block mb-1">check_circle</span>
                                No outstanding supplier payables! All vendor restock invoices are fully paid.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payables as $p): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-mono font-bold text-primary"><?php echo e($p['po_number']); ?></td>
                                <td class="py-3 px-3">
                                    <span class="font-bold text-on-surface block"><?php echo e($p['supplier_name']); ?></span>
                                    <span class="text-[10px] text-on-surface-variant"><?php echo e($p['contact_person'] ?: 'Distributor'); ?></span>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant font-mono"><?php echo e($p['supplier_phone'] ?: 'N/A'); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                                <td class="py-3 px-3 font-mono text-on-surface">$<?php echo number_format((float)$p['total_cost'], 2); ?></td>
                                <td class="py-3 px-3 font-mono text-secondary">$<?php echo number_format((float)$p['paid_amount'], 2); ?></td>
                                <td class="py-3 px-3 font-mono font-bold text-error text-sm">$<?php echo number_format((float)$p['due_amount'], 2); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant font-medium"><?php echo (int)$p['age_days']; ?> days</td>
                                <td class="py-3 px-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" 
                                                onclick="openSupplierStatementModal(<?php echo (int)$p['supplier_id']; ?>)" 
                                                class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-semibold transition-colors cursor-pointer flex items-center gap-1 shadow-2xs"
                                                title="View Full Supplier Statement">
                                            <span class="material-symbols-outlined text-[15px] text-primary">receipt_long</span>
                                            Statement
                                        </button>
                                        <button type="button" 
                                                onclick="openPaySupplierModal(<?php echo (int)$p['purchase_id']; ?>, <?php echo (int)$p['supplier_id']; ?>, '<?php echo e(addslashes($p['supplier_name'])); ?>', '<?php echo e($p['po_number']); ?>', <?php echo (float)$p['due_amount']; ?>)" 
                                                class="px-2.5 py-1 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container transition-colors cursor-pointer flex items-center gap-1 shadow-xs">
                                            <span class="material-symbols-outlined text-[15px]">credit_card</span>
                                            Pay
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

    <!-- Registered Suppliers & Statements Directory -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-secondary text-[20px]">domain</span>
                    Registered Suppliers &amp; Statements Directory
                </h3>
                <p class="text-[11px] text-on-surface-variant">Live supplier master catalog with statement ledger overview.</p>
            </div>
            <button type="button" onclick="openAddSupplierModal()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1 transition-colors cursor-pointer w-fit">
                <span class="material-symbols-outlined text-[16px] text-primary">add</span>
                + Add Supplier
            </button>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Supplier Name</th>
                        <th class="py-3 px-3">Contact Person</th>
                        <th class="py-3 px-3">Phone &amp; Email</th>
                        <th class="py-3 px-3">Total Purchases ($)</th>
                        <th class="py-3 px-3">Total Paid ($)</th>
                        <th class="py-3 px-3">Current Debt ($)</th>
                        <th class="py-3 px-3 text-right">Statement</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($allSuppliers)): ?>
                        <tr>
                            <td colspan="7" class="py-6 text-center text-on-surface-variant">
                                No suppliers registered in database yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allSuppliers as $sup): ?>
                            <?php 
                                $sId = (int)$sup['id'];
                                $sStmt = $supplierStatements[$sId] ?? null;
                                $totInvoiced = $sStmt ? (float)$sStmt['total_invoiced'] : 0.0;
                                $totPaid = $sStmt ? (float)$sStmt['total_paid'] : 0.0;
                                $totDue = $sStmt ? (float)$sStmt['total_due'] : 0.0;
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-bold text-on-surface">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-primary text-[18px]">local_pharmacy</span>
                                        <span><?php echo e($sup['name']); ?></span>
                                    </div>
                                    <?php if (!empty($sup['address'])): ?>
                                        <span class="text-[10px] text-on-surface-variant block mt-0.5"><?php echo e($sup['address']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant font-medium"><?php echo e($sup['contact_person'] ?: 'N/A'); ?></td>
                                <td class="py-3 px-3">
                                    <span class="font-mono text-on-surface block"><?php echo e($sup['phone']); ?></span>
                                    <?php if (!empty($sup['email'])): ?>
                                        <span class="text-[10px] text-on-surface-variant"><?php echo e($sup['email']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-on-surface">$<?php echo number_format($totInvoiced, 2); ?></td>
                                <td class="py-3 px-3 font-mono text-secondary">$<?php echo number_format($totPaid, 2); ?></td>
                                <td class="py-3 px-3 font-mono font-bold <?php echo ($totDue > 0) ? 'text-error' : 'text-secondary'; ?>">
                                    $<?php echo number_format($totDue, 2); ?>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <button type="button" 
                                            onclick="openSupplierStatementModal(<?php echo $sId; ?>)" 
                                            class="px-3 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-primary rounded-lg text-xs font-bold transition-colors cursor-pointer inline-flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px]">description</span>
                                        View Statement
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Supplier Payment History -->
    <?php if (!empty($recentPayments)): ?>
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
            <h3 class="font-bold text-sm text-on-surface flex items-center gap-2 border-b border-outline-variant pb-3">
                <span class="material-symbols-outlined text-secondary text-[20px]">history</span>
                Recent Supplier Disbursements &amp; Payments
            </h3>
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-lowest">
                            <th class="py-2 px-3">Disbursement Date</th>
                            <th class="py-2 px-3">Restock PO #</th>
                            <th class="py-2 px-3">Supplier Name</th>
                            <th class="py-2 px-3">Payment Method</th>
                            <th class="py-2 px-3">Disbursed By</th>
                            <th class="py-2 px-3 text-right">Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/60">
                        <?php foreach ($recentPayments as $sp): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2 px-3 text-on-surface-variant"><?php echo date('M d, Y g:i A', strtotime($sp['paid_at'])); ?></td>
                                <td class="py-2 px-3 font-mono font-bold text-primary"><?php echo e($sp['po_number'] ?? 'N/A'); ?></td>
                                <td class="py-2 px-3 font-semibold text-on-surface"><?php echo e($sp['supplier_name']); ?></td>
                                <td class="py-2 px-3 capitalize"><span class="bg-surface-container px-2 py-0.5 rounded font-bold"><?php echo e($sp['payment_method']); ?></span></td>
                                <td class="py-2 px-3 text-on-surface-variant"><?php echo e($sp['payer_name'] ?: 'Finance Dept'); ?></td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-error">-$<?php echo number_format((float)$sp['amount_paid'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL 1: Pay Supplier Debt -->
<div id="pay-supplier-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">payments</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Pay Supplier Invoice</h3>
                    <p class="text-xs text-on-surface-variant">Disburse payment to pharmaceutical distributor.</p>
                </div>
            </div>
            <button type="button" onclick="closePaySupplierModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="accounts_payable.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="pay_supplier">
            <input type="hidden" name="transaction_id" id="modal-tx-id" value="">
            <input type="hidden" name="supplier_id" id="modal-supp-id" value="">

            <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant">
                <p class="text-xs text-on-surface-variant">Supplier: <strong id="modal-supp-name" class="text-on-surface font-bold"></strong></p>
                <p class="text-xs text-on-surface-variant mt-0.5">PO Ref: <strong id="modal-po-num" class="font-mono text-primary"></strong></p>
                <p class="text-xs text-on-surface-variant mt-0.5">Total Amount Owed: <strong id="modal-supp-due" class="font-mono font-bold text-error text-sm"></strong></p>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Payment Amount ($ USD) *</label>
                <input name="amount_paid" id="modal-supp-pay-input" type="number" step="0.01" min="0.01" required placeholder="0.00" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none text-base">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Disbursement Method *</label>
                <select name="payment_method" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    <option value="bank">Bank Transfer (Commercial Bank)</option>
                    <option value="cash">Cash Disbursement (Khasnad)</option>
                    <option value="mobile">Mobile Money (Zaad / EVC)</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Payment Reference / Cheque #</label>
                <input name="notes" type="text" placeholder="e.g. Bank Wire Ref #99214" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closePaySupplierModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer">
                    Disburse Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Comprehensive Supplier Statement / Ledger View -->
<div id="supplier-statement-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-3xl w-full p-6 shadow-2xl max-h-[90vh] flex flex-col custom-scrollbar">
        <!-- Header -->
        <div class="flex justify-between items-start pb-3 border-b border-outline-variant mb-4 shrink-0">
            <div class="flex items-center gap-2">
                <div class="p-2 bg-primary/10 text-primary rounded-xl">
                    <span class="material-symbols-outlined text-[24px]">description</span>
                </div>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface" id="stmt-supp-name">Supplier Statement</h3>
                    <p class="text-xs text-on-surface-variant" id="stmt-supp-contact">Transaction statement and payment history</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printSupplierStatement()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
                <button type="button" onclick="closeSupplierStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
        </div>

        <!-- Statement Content Body -->
        <div id="printable-supplier-statement" class="overflow-y-auto custom-scrollbar flex-1 space-y-4 pr-1">
            <!-- Supplier Profile Card -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-surface-container-low rounded-xl border border-outline-variant text-xs">
                <div>
                    <span class="text-on-surface-variant block text-[10px]">Company Name:</span>
                    <strong class="text-on-surface font-bold" id="stmt-info-name">-</strong>
                </div>
                <div>
                    <span class="text-on-surface-variant block text-[10px]">Phone &amp; Email:</span>
                    <span class="font-mono text-on-surface" id="stmt-info-phone">-</span>
                </div>
                <div>
                    <span class="text-on-surface-variant block text-[10px]">Address:</span>
                    <span class="text-on-surface" id="stmt-info-address">-</span>
                </div>
            </div>

            <!-- Summary KPI Badges -->
            <div class="grid grid-cols-3 gap-3 text-center">
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant">
                    <span class="text-[11px] text-on-surface-variant block">Total Purchases Invoiced</span>
                    <strong class="text-base font-mono text-on-surface" id="stmt-tot-invoiced">$0.00</strong>
                </div>
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant">
                    <span class="text-[11px] text-on-surface-variant block">Total Disbursed / Paid</span>
                    <strong class="text-base font-mono text-secondary" id="stmt-tot-paid">$0.00</strong>
                </div>
                <div class="p-3 bg-surface-container-low rounded-xl border border-outline-variant">
                    <span class="text-[11px] text-on-surface-variant block">Current Net Balance Due</span>
                    <strong class="text-base font-mono text-error" id="stmt-tot-due">$0.00</strong>
                </div>
            </div>

            <!-- Section 1: Purchase Orders (Invoices) -->
            <div class="space-y-2">
                <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5 border-b border-outline-variant pb-1.5">
                    <span class="material-symbols-outlined text-[16px] text-primary">shopping_bag</span>
                    1. Warehouse Restock &amp; Purchase Orders (Invoices)
                </h4>
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-outline-variant bg-surface-container-lowest text-on-surface-variant font-bold">
                                <th class="py-1.5 px-2">PO #</th>
                                <th class="py-1.5 px-2">Date</th>
                                <th class="py-1.5 px-2">Medication / Batch</th>
                                <th class="py-1.5 px-2">Total ($)</th>
                                <th class="py-1.5 px-2">Paid ($)</th>
                                <th class="py-1.5 px-2">Due ($)</th>
                                <th class="py-1.5 px-2">Status</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-purchases-tbody" class="divide-y divide-outline-variant/50">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section 2: Payment Disbursements History -->
            <div class="space-y-2">
                <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5 border-b border-outline-variant pb-1.5">
                    <span class="material-symbols-outlined text-[16px] text-secondary">payments</span>
                    2. Payment Disbursements History
                </h4>
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-outline-variant bg-surface-container-lowest text-on-surface-variant font-bold">
                                <th class="py-1.5 px-2">Payment Date</th>
                                <th class="py-1.5 px-2">PO Ref #</th>
                                <th class="py-1.5 px-2">Method</th>
                                <th class="py-1.5 px-2">Disbursed By</th>
                                <th class="py-1.5 px-2">Notes</th>
                                <th class="py-1.5 px-2 text-right">Amount Paid ($)</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-payments-tbody" class="divide-y divide-outline-variant/50">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const supplierStatementsData = <?php echo json_encode($supplierStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function openPaySupplierModal(txId, suppId, suppName, poNum, amountDue) {
        document.getElementById('modal-tx-id').value = txId;
        document.getElementById('modal-supp-id').value = suppId;
        document.getElementById('modal-supp-name').innerText = suppName;
        document.getElementById('modal-po-num').innerText = poNum;
        document.getElementById('modal-supp-due').innerText = '$' + amountDue.toFixed(2);
        document.getElementById('modal-supp-pay-input').value = amountDue.toFixed(2);
        document.getElementById('modal-supp-pay-input').max = amountDue.toFixed(2);
        document.getElementById('pay-supplier-modal').classList.remove('hidden');
    }

    function closePaySupplierModal() {
        document.getElementById('pay-supplier-modal').classList.add('hidden');
    }

    function openSupplierStatementModal(supplierId) {
        const stmt = supplierStatementsData[supplierId];
        if (!stmt || !stmt.supplier) {
            alert('Supplier statement data not found.');
            return;
        }

        const s = stmt.supplier;
        document.getElementById('stmt-supp-name').innerText = s.name + ' - Statement';
        document.getElementById('stmt-supp-contact').innerText = 'Contact: ' + (s.contact_person || 'N/A') + ' | Phone: ' + s.phone;
        document.getElementById('stmt-info-name').innerText = s.name;
        document.getElementById('stmt-info-phone').innerText = s.phone + (s.email ? ' | ' + s.email : '');
        document.getElementById('stmt-info-address').innerText = s.address || 'N/A';

        document.getElementById('stmt-tot-invoiced').innerText = '$' + parseFloat(stmt.total_invoiced).toFixed(2);
        document.getElementById('stmt-tot-paid').innerText = '$' + parseFloat(stmt.total_paid).toFixed(2);
        document.getElementById('stmt-tot-due').innerText = '$' + parseFloat(stmt.total_due).toFixed(2);

        // Render Purchases
        const pTbody = document.getElementById('stmt-purchases-tbody');
        pTbody.innerHTML = '';
        if (!stmt.purchases || stmt.purchases.length === 0) {
            pTbody.innerHTML = '<tr><td colspan="7" class="py-3 text-center text-on-surface-variant">No purchase orders recorded for this supplier.</td></tr>';
        } else {
            stmt.purchases.forEach(p => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low';
                const statusBadge = (p.payment_status === 'paid') 
                    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary-fixed text-on-secondary-fixed">PAID</span>' 
                    : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-error-container text-on-error-container">DUE</span>';
                
                tr.innerHTML = `
                    <td class="py-2 px-2 font-mono font-bold text-primary">${p.po_number}</td>
                    <td class="py-2 px-2 text-on-surface-variant">${p.purchase_date}</td>
                    <td class="py-2 px-2">
                        <strong class="text-on-surface">${p.medication_name || 'Restock Item'}</strong>
                        ${p.batch_number ? '<span class="text-[10px] text-on-surface-variant block font-mono">Batch: ' + p.batch_number + '</span>' : ''}
                    </td>
                    <td class="py-2 px-2 font-mono text-on-surface">$${parseFloat(p.net_amount).toFixed(2)}</td>
                    <td class="py-2 px-2 font-mono text-secondary">$${parseFloat(p.paid_amount).toFixed(2)}</td>
                    <td class="py-2 px-2 font-mono font-bold text-error">$${parseFloat(p.due_amount).toFixed(2)}</td>
                    <td class="py-2 px-2">${statusBadge}</td>
                `;
                pTbody.appendChild(tr);
            });
        }

        // Render Payments
        const payTbody = document.getElementById('stmt-payments-tbody');
        payTbody.innerHTML = '';
        if (!stmt.payments || stmt.payments.length === 0) {
            payTbody.innerHTML = '<tr><td colspan="6" class="py-3 text-center text-on-surface-variant">No disbursement payments recorded yet.</td></tr>';
        } else {
            stmt.payments.forEach(pay => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low';
                tr.innerHTML = `
                    <td class="py-2 px-2 text-on-surface-variant">${pay.paid_at}</td>
                    <td class="py-2 px-2 font-mono font-bold text-primary">${pay.po_number || 'N/A'}</td>
                    <td class="py-2 px-2 capitalize"><span class="bg-surface-container px-2 py-0.5 rounded font-bold">${pay.payment_method}</span></td>
                    <td class="py-2 px-2 text-on-surface-variant">${pay.paid_by_name || 'Finance Dept'}</td>
                    <td class="py-2 px-2 text-on-surface-variant">${pay.notes || '-'}</td>
                    <td class="py-2 px-2 text-right font-mono font-bold text-error">-$${parseFloat(pay.amount_paid).toFixed(2)}</td>
                `;
                payTbody.appendChild(tr);
            });
        }

        document.getElementById('supplier-statement-modal').classList.remove('hidden');
    }

    function closeSupplierStatementModal() {
        document.getElementById('supplier-statement-modal').classList.add('hidden');
    }

    function printSupplierStatement() {
        window.print();
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
