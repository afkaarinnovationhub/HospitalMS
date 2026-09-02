<?php
/**
 * MedCore Systems - Accounts Receivable (AR) & Patient Debt Ledger
 * Tracks all outstanding patient receivables, debt aging, and installment collections.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/../CONTROLS/PharmacyController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

$ar = AccountingOperation::getAccountsReceivableReport();
$aging = $ar['aging'];
$debtors = $ar['debtors'];
$recentPayments = $ar['recent_payments'];
$allAccounts = AccountingOperation::getAllCustomerAccountsWithFinancials();

// Pre-load AR Statements for Debtors and All Accounts
$arStatements = [];
foreach ($debtors as $d) {
    $pId = !empty($d['patient_id']) ? (int)$d['patient_id'] : null;
    $invId = (int)($d['invoice_id'] ?? ($d['sale_id'] ?? 0));
    $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
    if (!isset($arStatements[$stmtKey])) {
        $stmtData = AccountingOperation::getCustomerARStatement($pId, $invId);
        if ($stmtData) {
            $arStatements[$stmtKey] = $stmtData;
        }
    }
}

foreach ($allAccounts as $acc) {
    $pId = !empty($acc['patient_id']) ? (int)$acc['patient_id'] : null;
    $invId = (int)($acc['invoice_id'] ?? 0);
    $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
    if (!isset($arStatements[$stmtKey])) {
        $stmtData = AccountingOperation::getCustomerARStatement($pId, $invId);
        if ($stmtData) {
            $arStatements[$stmtKey] = $stmtData;
        }
    }
}

foreach ($recentPayments as $rp) {
    $pId = !empty($rp['patient_id']) ? (int)$rp['patient_id'] : null;
    $invId = (int)($rp['invoice_id'] ?? 0);
    $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
    if (!isset($arStatements[$stmtKey])) {
        $stmtData = AccountingOperation::getCustomerARStatement($pId, $invId);
        if ($stmtData) {
            $arStatements[$stmtKey] = $stmtData;
        }
    }
}

$pageTitle = 'Accounts Receivable (AR) & Patient Statements - MedCore Systems';
$headerTitle = 'MedCore Management - Accounts Receivable';
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
                <p class="font-bold">Receivable Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Debt Payment Collected</p>
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
                    <span class="material-symbols-outlined text-amber-600 text-[28px]">person_pin</span>
                    Accounts Receivable (Deymaha Bukaanka)
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                Outstanding patient receivables, debt aging, and installment payment collections.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Report
            </button>
        </div>
    </div>

    <!-- Aging Summary KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total AR -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Total Patient Debt (AR)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$ar['total_receivable'], 2); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">Across <?php echo $ar['debtor_count']; ?> active debtor(s)</p>
            </div>
        </div>

        <!-- 0 - 30 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-secondary">Current (0 - 30 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-secondary font-mono">$<?php echo number_format((float)$aging['current_0_30'], 2); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">Recent outpatient &amp; pharmacy credit</p>
            </div>
        </div>

        <!-- 31 - 60 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-amber-500">Aging (31 - 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$aging['aging_31_60'], 2); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">Due for follow-up reminders</p>
            </div>
        </div>

        <!-- 60+ Days Overdue -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-error">Overdue (> 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$aging['over_60_days'], 2); ?></h3>
                <p class="text-[11px] text-on-surface-variant mt-1">High-priority collections</p>
            </div>
        </div>
    </div>

    <!-- Active Patient Debtors Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">receipt_long</span>
                    Patient Debt Ledger (Bukaanada Lacagtu ku Baaqiga Tahay)
                </h3>
                <p class="text-[11px] text-on-surface-variant">Live accounts receivable from pharmacy dispensing and clinical bills.</p>
            </div>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Invoice #</th>
                        <th class="py-3 px-3">Patient Name</th>
                        <th class="py-3 px-3">Contact</th>
                        <th class="py-3 px-3">Date</th>
                        <th class="py-3 px-3">Total ($)</th>
                        <th class="py-3 px-3">Paid ($)</th>
                        <th class="py-3 px-3">Balance Due ($)</th>
                        <th class="py-3 px-3">Aging</th>
                        <th class="py-3 px-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($debtors)): ?>
                        <tr>
                            <td colspan="9" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[32px] text-secondary block mb-1">check_circle</span>
                                No outstanding patient debts! All receivables are fully settled.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($debtors as $d): ?>
                            <?php 
                                $ageDays = (int)$d['age_days'];
                                $badgeColor = ($ageDays <= 30) ? 'bg-secondary-fixed text-on-secondary-fixed' : (($ageDays <= 60) ? 'bg-amber-500/20 text-amber-700' : 'bg-error-container text-on-error-container');
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-mono font-bold text-primary"><?php echo e($d['invoice_number']); ?></td>
                                <td class="py-3 px-3">
                                    <span class="font-bold text-on-surface block"><?php echo e($d['customer_name']); ?></span>
                                    <span class="text-[10px] text-on-surface-variant font-mono"><?php echo e($d['patient_mrn'] ?? ($d['mrn'] ?? 'Walk-in')); ?></span>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant font-mono"><?php echo e($d['customer_phone'] ?? ($d['patient_phone'] ?? 'N/A')); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant"><?php echo date('M d, Y', strtotime($d['created_at'])); ?></td>
                                <td class="py-3 px-3 font-mono text-on-surface">$<?php echo number_format((float)($d['net_amount'] ?? $d['total_amount']), 2); ?></td>
                                <td class="py-3 px-3 font-mono text-secondary">$<?php echo number_format((float)($d['paid_amount'] ?? $d['amount_paid']), 2); ?></td>
                                <td class="py-3 px-3 font-mono font-bold text-error text-sm">$<?php echo number_format((float)($d['due_amount'] ?? $d['amount_due']), 2); ?></td>
                                <td class="py-3 px-3">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold <?php echo $badgeColor; ?>">
                                        <?php echo $ageDays; ?> days
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <?php 
                                        $pId = !empty($d['patient_id']) ? (int)$d['patient_id'] : null;
                                        $invId = (int)($d['invoice_id'] ?? ($d['sale_id'] ?? 0));
                                        $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
                                    ?>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" 
                                                onclick="openCustomerStatementModal('<?php echo $stmtKey; ?>')" 
                                                class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-semibold transition-colors cursor-pointer flex items-center gap-1 shadow-2xs"
                                                title="View Full AR Statement &amp; Payments">
                                            <span class="material-symbols-outlined text-[15px] text-primary">receipt_long</span>
                                            Statement
                                        </button>
                                        <a href="billing_payments.php?invoice_id=<?php echo $invId; ?>" 
                                           class="px-2.5 py-1 bg-primary hover:bg-primary-container text-on-primary rounded-lg text-xs font-bold transition-colors cursor-pointer flex items-center gap-1 shadow-xs"
                                           title="Proceed to Billing &amp; Cashier Desk to collect payment">
                                            <span class="material-symbols-outlined text-[15px]">payments</span>
                                            Pay ($<?php echo number_format((float)($d['due_amount'] ?? $d['amount_due']), 2); ?>)
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- All Patient & Customer Accounts & Statements Directory (Both Active Debt & Fully Settled) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">folder_shared</span>
                    Patient &amp; Customer Accounts &amp; Statements Directory
                </h3>
                <p class="text-[11px] text-on-surface-variant">Complete clinical &amp; walk-in billing ledger. View statements for any patient anytime (Active Debt &amp; Fully Settled).</p>
            </div>
            <span class="text-xs font-bold text-on-surface-variant bg-surface-container px-2.5 py-1 rounded-full w-fit">
                <?php echo count($allAccounts); ?> Account(s)
            </span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Patient / Customer Name</th>
                        <th class="py-3 px-3">Contact</th>
                        <th class="py-3 px-3">Account Type / ID</th>
                        <th class="py-3 px-3 text-center">Invoices</th>
                        <th class="py-3 px-3 font-mono">Total Billed ($)</th>
                        <th class="py-3 px-3 font-mono">Total Paid ($)</th>
                        <th class="py-3 px-3 font-mono">Current Debt ($)</th>
                        <th class="py-3 px-3 text-center">Status</th>
                        <th class="py-3 px-3 text-right">Statement</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($allAccounts)): ?>
                        <tr>
                            <td colspan="9" class="py-6 text-center text-on-surface-variant">
                                No billing accounts found in database.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allAccounts as $acc): ?>
                            <?php 
                                $pId = !empty($acc['patient_id']) ? (int)$acc['patient_id'] : null;
                                $invId = (int)($acc['invoice_id'] ?? 0);
                                $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
                                $due = (float)$acc['balance_due'];
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-bold text-on-surface">
                                    <div class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-primary text-[18px]">
                                            <?php echo $pId ? 'person' : 'shopping_bag'; ?>
                                        </span>
                                        <span><?php echo e($acc['customer_name']); ?></span>
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant font-mono"><?php echo e($acc['customer_phone'] ?: 'N/A'); ?></td>
                                <td class="py-3 px-3">
                                    <span class="font-mono text-on-surface block"><?php echo e($acc['identifier']); ?></span>
                                    <span class="text-[10px] text-on-surface-variant"><?php echo e($acc['account_type']); ?></span>
                                </td>
                                <td class="py-3 px-3 text-center font-mono font-bold text-on-surface"><?php echo (int)$acc['total_invoices_count']; ?></td>
                                <td class="py-3 px-3 font-mono text-on-surface">$<?php echo number_format((float)$acc['total_invoiced'], 2); ?></td>
                                <td class="py-3 px-3 font-mono text-secondary font-semibold">$<?php echo number_format((float)$acc['total_paid'], 2); ?></td>
                                <td class="py-3 px-3 font-mono font-bold <?php echo ($due > 0) ? 'text-error' : 'text-on-surface-variant'; ?>">
                                    $<?php echo number_format($due, 2); ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if ($due > 0): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-error-container text-on-error-container">IN DEBT</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary-fixed text-on-secondary-fixed">SETTLED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <button type="button" 
                                            onclick="openCustomerStatementModal('<?php echo $stmtKey; ?>')" 
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

    <!-- Recent Debt Collections History -->
    <?php if (!empty($recentPayments)): ?>
        <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
            <h3 class="font-bold text-sm text-on-surface flex items-center gap-2 border-b border-outline-variant pb-3">
                <span class="material-symbols-outlined text-secondary text-[20px]">history</span>
                Recent Debt Installment Collections
            </h3>
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-lowest">
                            <th class="py-2 px-3">Receipt Date</th>
                            <th class="py-2 px-3">Invoice #</th>
                            <th class="py-2 px-3">Patient</th>
                            <th class="py-2 px-3">Payment Method</th>
                            <th class="py-2 px-3">Received By</th>
                            <th class="py-2 px-3 text-right">Amount Collected</th>
                            <th class="py-2 px-3 text-right">Statement</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/60">
                        <?php foreach ($recentPayments as $rp): ?>
                            <?php 
                                $pId = !empty($rp['patient_id']) ? (int)$rp['patient_id'] : null;
                                $invId = (int)($rp['invoice_id'] ?? 0);
                                $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2 px-3 text-on-surface-variant"><?php echo date('M d, Y g:i A', strtotime($rp['received_at'])); ?></td>
                                <td class="py-2 px-3 font-mono font-bold text-primary"><?php echo e($rp['invoice_number']); ?></td>
                                <td class="py-2 px-3 font-semibold text-on-surface"><?php echo e(!empty($rp['customer_name']) ? $rp['customer_name'] : 'Walk-in Patient'); ?></td>
                                <td class="py-2 px-3 capitalize"><span class="bg-surface-container px-2 py-0.5 rounded font-bold"><?php echo e($rp['payment_method']); ?></span></td>
                                <td class="py-2 px-3 text-on-surface-variant"><?php echo e($rp['receiver_name'] ?: 'Cashier'); ?></td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-secondary">+$<?php echo number_format((float)$rp['amount_paid'], 2); ?></td>
                                <td class="py-2 px-3 text-right">
                                    <button type="button" 
                                            onclick="openCustomerStatementModal('<?php echo $stmtKey; ?>')" 
                                            class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-primary rounded-lg text-xs font-bold transition-colors cursor-pointer inline-flex items-center gap-1 shadow-2xs">
                                        <span class="material-symbols-outlined text-[15px]">description</span>
                                        Statement
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL: Patient & Customer AR Financial Statement -->
<div id="customer-statement-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-4xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl space-y-4 custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">receipt_long</span>
                <div>
                    <h3 id="stmt-patient-title" class="font-headline-sm text-base font-bold text-on-surface">Patient Financial Statement</h3>
                    <p id="stmt-patient-subtitle" class="text-xs text-on-surface-variant">Accounts Receivable &amp; Clinical Invoices</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printCustomerStatement()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
                <button type="button" onclick="closeCustomerStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
        </div>

        <!-- Printable Statement Canvas -->
        <div id="printable-customer-statement-area" class="space-y-4 bg-surface p-4 rounded-xl border border-outline-variant">
            <div class="flex justify-between items-start border-b border-outline-variant pb-3">
                <div>
                    <h4 class="font-bold text-base text-primary">MEDCORE HOSPITAL SYSTEMS</h4>
                    <p class="text-xs text-on-surface-variant">Patient Billing &amp; Revenue Accounts Office</p>
                </div>
                <div class="text-right text-xs">
                    <p class="font-bold text-on-surface">Statement Date: <?php echo date('M d, Y'); ?></p>
                    <p class="text-on-surface-variant">Patient AR Ledger</p>
                </div>
            </div>

            <!-- Patient Info & Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 rounded-xl bg-surface-container-lowest border border-outline-variant">
                <div class="text-xs space-y-1">
                    <p class="font-bold text-on-surface text-sm" id="stmt-patient-name">Patient Name</p>
                    <p class="text-on-surface-variant" id="stmt-patient-mrn">MRN / ID: N/A</p>
                    <p class="text-on-surface-variant" id="stmt-patient-phone">Phone: N/A</p>
                    <p class="text-on-surface-variant" id="stmt-patient-address">Address: N/A</p>
                </div>
                <div class="flex justify-end gap-3 text-right">
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-on-surface-variant text-[10px] uppercase font-bold block">Total Invoiced</span>
                        <strong id="stmt-patient-tot-invoiced" class="text-sm font-mono font-bold text-on-surface">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-secondary text-[10px] uppercase font-bold block">Total Paid</span>
                        <strong id="stmt-patient-tot-paid" class="text-sm font-mono font-bold text-secondary">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-error text-[10px] uppercase font-bold block">Balance Due</span>
                        <strong id="stmt-patient-tot-due" class="text-sm font-mono font-bold text-error">$0.00</strong>
                    </div>
                </div>
            </div>

            <!-- Tabular Invoices & Clinical Encounters -->
            <div class="space-y-2">
                <h5 class="font-bold text-xs text-on-surface uppercase tracking-wider flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px] text-primary">receipt</span>
                    Clinical &amp; Pharmacy Invoices
                </h5>
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-surface-container-low text-on-surface-variant font-bold border-b border-outline-variant">
                                <th class="py-2 px-2">Invoice #</th>
                                <th class="py-2 px-2">Date</th>
                                <th class="py-2 px-2">Service / Items Description</th>
                                <th class="py-2 px-2">Total ($)</th>
                                <th class="py-2 px-2">Paid ($)</th>
                                <th class="py-2 px-2 text-error">Due ($)</th>
                                <th class="py-2 px-2">Status</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-patient-invoices-tbody" class="divide-y divide-outline-variant/60">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabular Payment Receipts -->
            <div class="space-y-2 pt-2 border-t border-outline-variant">
                <h5 class="font-bold text-xs text-on-surface uppercase tracking-wider flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px] text-secondary">payments</span>
                    Receipts &amp; Payments Received
                </h5>
                <div class="overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-surface-container-low text-on-surface-variant font-bold border-b border-outline-variant">
                                <th class="py-2 px-2">Receipt Date</th>
                                <th class="py-2 px-2">Invoice #</th>
                                <th class="py-2 px-2">Method</th>
                                <th class="py-2 px-2">Received By</th>
                                <th class="py-2 px-2">Notes</th>
                                <th class="py-2 px-2 text-right">Amount Received</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-patient-payments-tbody" class="divide-y divide-outline-variant/60">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const arStatementsData = <?php echo json_encode($arStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function openCustomerStatementModal(stmtKey) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt || !stmt.patient) {
            alert('Statement data for this debtor could not be found.');
            return;
        }

        const p = stmt.patient;
        document.getElementById('stmt-patient-title').innerText = p.name + ' - Statement';
        document.getElementById('stmt-patient-subtitle').innerText = (p.type || 'Patient') + ' Financial Statement';
        document.getElementById('stmt-patient-name').innerText = p.name;
        document.getElementById('stmt-patient-mrn').innerText = 'MRN / ID: ' + (p.mrn || 'Walk-in');
        document.getElementById('stmt-patient-phone').innerText = 'Phone: ' + (p.phone || 'N/A');
        document.getElementById('stmt-patient-address').innerText = 'Address: ' + (p.address || 'N/A');

        document.getElementById('stmt-patient-tot-invoiced').innerText = '$' + parseFloat(stmt.total_invoiced).toFixed(2);
        document.getElementById('stmt-patient-tot-paid').innerText = '$' + parseFloat(stmt.total_paid).toFixed(2);
        document.getElementById('stmt-patient-tot-due').innerText = '$' + parseFloat(stmt.total_due).toFixed(2);

        // Render Invoices
        const invTbody = document.getElementById('stmt-patient-invoices-tbody');
        invTbody.innerHTML = '';
        if (!stmt.invoices || stmt.invoices.length === 0) {
            invTbody.innerHTML = '<tr><td colspan="7" class="py-3 text-center text-on-surface-variant">No invoices recorded for this debtor.</td></tr>';
        } else {
            stmt.invoices.forEach(inv => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low';
                const statusBadge = (inv.payment_status === 'paid') 
                    ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary-fixed text-on-secondary-fixed">PAID</span>' 
                    : ((parseFloat(inv.due_amount) > 0) ? '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-error-container text-on-error-container">DUE</span>' : '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-container">SETTLED</span>');
                
                tr.innerHTML = `
                    <td class="py-2 px-2 font-mono font-bold text-primary">${inv.invoice_number}</td>
                    <td class="py-2 px-2 text-on-surface-variant">${inv.created_at.substring(0, 10)}</td>
                    <td class="py-2 px-2">
                        <strong class="text-on-surface capitalize">${inv.bill_type} Bill</strong>
                        <span class="text-[10px] text-on-surface-variant block">${inv.item_summary || '-'}</span>
                    </td>
                    <td class="py-2 px-2 font-mono text-on-surface">$${parseFloat(inv.net_total).toFixed(2)}</td>
                    <td class="py-2 px-2 font-mono text-secondary">$${parseFloat(inv.paid_amount).toFixed(2)}</td>
                    <td class="py-2 px-2 font-mono font-bold text-error">$${parseFloat(inv.due_amount).toFixed(2)}</td>
                    <td class="py-2 px-2">${statusBadge}</td>
                `;
                invTbody.appendChild(tr);
            });
        }

        // Render Payments
        const payTbody = document.getElementById('stmt-patient-payments-tbody');
        payTbody.innerHTML = '';
        if (!stmt.payments || stmt.payments.length === 0) {
            payTbody.innerHTML = '<tr><td colspan="6" class="py-3 text-center text-on-surface-variant">No debt payments received yet for this debtor.</td></tr>';
        } else {
            stmt.payments.forEach(pay => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-surface-container-low';
                tr.innerHTML = `
                    <td class="py-2 px-2 text-on-surface-variant">${pay.paid_at || '-'}</td>
                    <td class="py-2 px-2 font-mono font-bold text-primary">${pay.invoice_number}</td>
                    <td class="py-2 px-2 capitalize"><span class="bg-surface-container px-2 py-0.5 rounded font-bold">${pay.payment_method}</span></td>
                    <td class="py-2 px-2 text-on-surface-variant">${pay.cashier_name || 'Cashier'}</td>
                    <td class="py-2 px-2 text-on-surface-variant">${pay.notes || '-'}</td>
                    <td class="py-2 px-2 text-right font-mono font-bold text-secondary">+$${parseFloat(pay.paid_amount).toFixed(2)}</td>
                `;
                payTbody.appendChild(tr);
            });
        }

        document.getElementById('customer-statement-modal').classList.remove('hidden');
    }

    function closeCustomerStatementModal() {
        document.getElementById('customer-statement-modal').classList.add('hidden');
    }

    function printCustomerStatement() {
        const printContent = document.getElementById('printable-customer-statement-area').innerHTML;
        const originalContent = document.body.innerHTML;
        
        document.body.innerHTML = `
            <div style="padding: 20px; font-family: monospace;">
                ${printContent}
            </div>
        `;
        window.print();
        document.body.innerHTML = originalContent;
        location.reload();
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
