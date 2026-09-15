<?php
/**
 * MedCore Systems - Accounts Payable (AP) & Supplier Statements
 * Tracks all outstanding vendor payables, debt aging, and supplier installment disbursements.
 * Standardized to match the clean 2-column architecture and running balance ledger of Accounts Receivable.
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

// Handle Supplier Payment Actions
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
$aging = $ap['aging'] ?? [
    'current_0_30' => 0.0,
    'aging_31_60'  => 0.0,
    'over_60_days' => 0.0,
];

// Fetch grouped supplier accounts with debt financials
$allSupplierAccounts = AccountingOperation::getHospitalDebtsLedger('debtors_only');

// Filter to ONLY those with Current Debt > $0.00
$creditorAccounts = array_values(array_filter($allSupplierAccounts, function ($acc) {
    return (float)($acc['balance_due'] ?? 0) > 0.005;
}));

// Pre-load Supplier Statements for Active Creditor Accounts
$supplierStatements = [];
foreach ($creditorAccounts as $acc) {
    $sId = (int)($acc['supplier_id'] ?? 0);
    if ($sId > 0 && !isset($supplierStatements[$sId])) {
        $stmtData = InventoryOperation::getSupplierStatement($sId);
        if ($stmtData) {
            $supplierStatements[$sId] = $stmtData;
        }
    }
}

$pageTitle = 'Accounts Payable (AP) & Supplier Statements - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Accounts Payable';
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
                    Accounts Payable
                </h2>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="suppliers.php" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-xs transition-all">
                <span class="material-symbols-outlined text-[18px]">local_shipping</span>
                Manage Suppliers
            </a>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Report
            </button>
        </div>
    </div>

    <!-- Aging Summary KPI Cards (Matching Accounts Receivable Architecture) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total AP -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Total Payables Due</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$ap['total_payable'], 2); ?></h3>
            </div>
        </div>

        <!-- 0 - 30 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-secondary">Current (0 - 30 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-secondary font-mono">$<?php echo number_format((float)$aging['current_0_30'], 2); ?></h3>
            </div>
        </div>

        <!-- 31 - 60 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-amber-500">Aging (31 - 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$aging['aging_31_60'], 2); ?></h3>
            </div>
        </div>

        <!-- 60+ Days Overdue -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-error">Overdue (> 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$aging['over_60_days'], 2); ?></h3>
            </div>
        </div>
    </div>

    <!-- Simplified Outstanding Supplier Payables List (Primary List - Matching AR 2-Column Pattern) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">store</span>
                    Outstanding Supplier Payables
                </h3>
            </div>
            <span class="text-xs font-bold text-error bg-error-container/40 px-2.5 py-1 rounded-full w-fit">
                <?php echo count($creditorAccounts); ?> Creditor Account(s)
            </span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-4">Supplier / Vendor Name</th>
                        <th class="py-3 px-4 text-right font-mono">Current Debt ($)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($creditorAccounts)): ?>
                        <tr>
                            <td colspan="2" class="py-10 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[36px] text-secondary block mb-1">check_circle</span>
                                <p class="font-bold text-sm text-on-surface">No outstanding supplier payables!</p>
                                <p class="text-xs text-on-surface-variant mt-0.5">All vendor restock accounts are fully settled ($0.00 balance).</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($creditorAccounts as $acc): ?>
                            <?php 
                                $sId = (int)$acc['supplier_id'];
                                $due = (float)$acc['balance_due'];
                            ?>
                            <tr onclick="openSupplierStatementModal(<?php echo $sId; ?>)" 
                                class="hover:bg-primary/5 cursor-pointer transition-colors group">
                                <td class="py-3.5 px-4 font-bold text-on-surface group-hover:text-primary transition-colors">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-8 h-8 rounded-full bg-error-container text-on-error-container font-bold text-xs flex items-center justify-center shrink-0">
                                            <?php echo strtoupper(substr($acc['supplier_name'] ?: 'S', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <span class="text-sm font-semibold block"><?php echo e($acc['supplier_name']); ?></span>
                                            <span class="text-[10px] text-on-surface-variant font-normal">
                                                Contact: <?php echo e($acc['contact_person'] ?: 'Distributor'); ?> • Tel: <?php echo e($acc['supplier_phone'] ?: 'N/A'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-error text-base">
                                    $<?php echo number_format($due, 2); ?>
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
<!-- MODAL 1: Supplier AP Financial Statement (Unified Running Balance Ledger) -->
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
                <button type="button" onclick="window.print()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-2xs">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print
                </button>
                <button type="button" onclick="closeSupplierStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[22px]">close</span>
                </button>
            </div>
        </div>

        <!-- Statement Printable Card -->
        <div id="printable-statement-card" class="bg-white text-black p-6 rounded-xl border border-gray-200 font-sans space-y-4 shadow-sm">
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

            <!-- Running Balance Ledger Table (Matching User's Preferred AR Table) -->
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
                    <tfoot class="bg-gray-50 border-t-2 border-gray-300 font-bold text-xs">
                        <tr>
                            <td colspan="5" class="py-2.5 px-3 text-right text-gray-800 uppercase tracking-wider">Total Ending Balance Due:</td>
                            <td id="stmt-ledger-foot-due" class="py-2.5 px-3 text-right font-mono text-red-600 text-sm">$0.00</td>
                        </tr>
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
            <button type="button" onclick="window.print()" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container transition-colors flex items-center gap-1 cursor-pointer shadow-xs">
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
            <button type="button" onclick="window.print()" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container transition-colors flex items-center gap-1 cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Voucher
            </button>
        </div>
    </div>
</div>

<script>
    const preloadedSupplierStatements = <?php echo json_encode($supplierStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let currentActiveStatement = null;

    function openSupplierStatementModal(supplierId) {
        const stmt = preloadedSupplierStatements[supplierId];
        if (!stmt) {
            alert('Statement data not found for this supplier.');
            return;
        }
        currentActiveStatement = stmt;

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
        document.getElementById('stmt-ledger-foot-due').textContent = '$' + totDue.toFixed(2);

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

        document.getElementById('supplier-statement-modal').classList.remove('hidden');
    }

    function closeSupplierStatementModal() {
        document.getElementById('supplier-statement-modal').classList.add('hidden');
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
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
