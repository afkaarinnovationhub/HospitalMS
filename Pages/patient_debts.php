<?php
/**
 * MedCore Systems - Patient Debts & Accounts Receivable Ledger
 * Provides a clean, focused, and fast interface for tracking patient debts,
 * outstanding balances, and reviewing complete clinical billing history and payments.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_RECEPTION_CASHIER, ROLE_PHARMACY]);

$currentUser = getCurrentUser();
$userRole    = $currentUser['role'] ?? 'Staff';
$userId      = (int)($currentUser['id'] ?? 1);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Patient Debt Installment Settlement
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'pay_patient_debt') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Security token invalid or expired. Please refresh and try again.';
    } else {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amountPaid = (float)($_POST['amount_paid'] ?? 0);
        $paymentMethod = sanitizeString($_POST['payment_method'] ?? 'cash');
        $notes = sanitizeString($_POST['notes'] ?? '');

        if ($invoiceId <= 0 || $amountPaid <= 0.001) {
            $errorMessage = 'Please provide a valid invoice and a positive payment amount.';
        } else {
            try {
                $payResult = BillingOperation::processInvoicePayment(
                    $invoiceId,
                    $amountPaid,
                    $paymentMethod,
                    $notes ?: 'Patient debt installment collection',
                    $userId,
                    null,
                    true
                );

                $recNum = $payResult['receipt_number'] ?: 'REC-SETTLED';
                $remDue = number_format((float)$payResult['remaining_due'], 2);
                $paidFormatted = number_format((float)$payResult['amount_paid'], 2);
                setFlashMessage('success', "Installment of \${$paidFormatted} recorded successfully! Receipt: {$recNum}. Remaining balance: \${$remDue}");
                header('Location: patient_debts.php?filter=' . urlencode($_GET['filter'] ?? 'debtors_only') . '&search=' . urlencode($_GET['search'] ?? '') . '&receipt=' . urlencode($recNum));
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

$metrics = AccountingOperation::getPatientDebtsSummaryMetrics();
$records = AccountingOperation::getPatientDebtsLedger($filter, $search);

// Pre-load AR Statements for fast instant modal rendering
$arStatements = [];
foreach ($records as $row) {
    $pId = !empty($row['patient_id']) ? (int)$row['patient_id'] : null;
    $invId = !empty($row['invoice_id']) ? (int)$row['invoice_id'] : (int)($row['latest_invoice_id'] ?? 0);
    $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
    if (!isset($arStatements[$stmtKey])) {
        $stmtData = AccountingOperation::getCustomerARStatement($pId, $invId);
        if ($stmtData) {
            $arStatements[$stmtKey] = $stmtData;
        }
    }
}

// Check if a receipt was recently issued to auto-open printable receipt modal
$recentReceiptNumber = sanitizeString($_GET['receipt'] ?? '');
$recentReceipt = null;
if (!empty($recentReceiptNumber)) {
    $pdo = getDBConnection();
    $stmtRc = $pdo->prepare("
        SELECT 
            ip.*, 
            i.invoice_number, 
            i.customer_name, 
            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name, 
            p.mrn, 
            u.full_name as cashier_name
        FROM invoice_payments ip
        JOIN invoices i ON ip.invoice_id = i.id
        LEFT JOIN patients p ON ip.patient_id = p.id
        LEFT JOIN users u ON ip.received_by = u.id
        WHERE ip.receipt_number = ?
    ");
    $stmtRc->execute([$recentReceiptNumber]);
    $recentReceipt = $stmtRc->fetch();
}

$pageTitle = 'Patient Debts Ledger - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Patient Debts';
$activePage = 'patient_debts';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-10 bg-background custom-scrollbar">

    <!-- Notifications -->
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
                <p class="font-bold">Payment Settled</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top Header: Title & Action Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[24px]">request_quote</span>
                </div>
                <div>
                    <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface">
                        Patient Debts Ledger
                    </h2>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Directory
            </button>
            <a href="billing_payments.php" class="px-4 py-2 bg-primary hover:bg-primary-container text-on-primary rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-xs">
                <span class="material-symbols-outlined text-[18px]">payments</span>
                Cashier Desk (Billing)
            </a>
        </div>
    </div>

    <!-- KPI Summary Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- 1. Total Outstanding Debt -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-error/30 shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-error/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">money_off</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-error uppercase tracking-wider">Total Outstanding Debt</span>
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

        <!-- 2. Patients in Debt -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-outline-variant shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-primary/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">group</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-primary uppercase tracking-wider">Patients in Debt</span>
                <span class="p-1.5 rounded-lg bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-[18px]">person_search</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-on-surface font-mono">
                    <?php echo $metrics['debtor_count']; ?> 
                </h3>
            </div>
        </div>

        <!-- 3. Total Invoiced Amount -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-outline-variant shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-on-surface-variant/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">receipt_long</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Total Invoiced Amount</span>
                <span class="p-1.5 rounded-lg bg-surface-container text-on-surface-variant">
                    <span class="material-symbols-outlined text-[18px]">receipt</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-on-surface font-mono">
                    $<?php echo number_format($metrics['total_invoiced'], 2); ?>
                </h3>
            </div>
        </div>

        <!-- 4. Total Collected / Paid -->
        <div class="p-4 sm:p-5 rounded-2xl bg-surface border border-secondary/30 shadow-xs relative overflow-hidden group">
            <div class="absolute -right-3 -bottom-3 text-secondary/10 pointer-events-none group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined text-[72px]">check_circle</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-secondary uppercase tracking-wider">Total Collected / Paid</span>
                <span class="p-1.5 rounded-lg bg-secondary/15 text-secondary">
                    <span class="material-symbols-outlined text-[18px]">verified</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl sm:text-3xl font-bold text-secondary font-mono">
                    $<?php echo number_format($metrics['total_collected'], 2); ?>
                </h3>
            </div>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs mb-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- Filter Tabs -->
            <div class="flex flex-wrap items-center gap-1.5 p-1 bg-surface-container rounded-xl text-xs font-semibold w-fit">
                <a href="patient_debts.php?filter=debtors_only" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'debtors_only') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1 text-error">warning</span>
                    Active Debtors Only
                </a>
                <a href="patient_debts.php?filter=high_debt" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'high_debt') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1 text-amber-500">trending_up</span>
                    High Debt (> $50)
                </a>
                <a href="patient_debts.php?filter=all" 
                   class="px-3 py-1.5 rounded-lg transition-colors <?php echo ($filter === 'all') ? 'bg-surface font-bold text-primary shadow-xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">
                    <span class="material-symbols-outlined text-[15px] align-middle mr-1">folder_shared</span>
                    All Patient Accounts
                </a>
            </div>

            <!-- Instant Live Search Box -->
            <div class="relative w-full md:w-72">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                <input id="debt-table-search" 
                       type="text" 
                       value="<?php echo e($search); ?>"
                       oninput="filterDebtsTable(this.value)"
                       placeholder="Search name, phone, or MRN..." 
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

    <!-- Patients Debt Ledger Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-outline-variant flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">table_rows</span>
                    Patient Debt Ledger
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
                        <th class="py-3 px-4">Patient Name</th>
                        <th class="py-3 px-4">MRN &amp; Contact</th>
                        <th class="py-3 px-4 text-center">Invoices</th>
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
                                    <p class="font-bold text-sm text-on-surface">No outstanding patient debts!</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <?php 
                                $pId = !empty($row['patient_id']) ? (int)$row['patient_id'] : 0;
                                $invId = !empty($row['invoice_id']) ? (int)$row['invoice_id'] : (int)($row['latest_invoice_id'] ?? 0);
                                $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
                                $name = $row['patient_name'] ?: 'Unknown Debtor';
                                $phone = $row['phone'] ?: 'N/A';
                                $mrn = $row['mrn'] ?: 'N/A';
                                $due = (float)$row['balance_due'];
                                $invoiced = (float)$row['total_invoiced'];
                                $paid = (float)$row['total_paid'];
                                $initial = strtoupper(substr($name, 0, 1) ?: 'P');
                                $isDebt = ($due > 0.005);
                                $latestInv = (int)($row['latest_invoice_id'] ?? 0);
                            ?>
                            <tr class="debt-row hover:bg-surface-container-low transition-colors group cursor-pointer"
                                data-name="<?php echo e(strtolower($name)); ?>"
                                data-phone="<?php echo e(strtolower($phone)); ?>"
                                data-mrn="<?php echo e(strtolower($mrn)); ?>"
                                data-due="<?php echo $due; ?>"
                                onclick="openCustomerStatementModal('<?php echo $stmtKey; ?>')">
                                
                                <!-- Patient Info -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full <?php echo $isDebt ? 'bg-amber-500/20 text-amber-700 dark:text-amber-300' : 'bg-secondary/20 text-secondary'; ?> font-bold text-xs flex items-center justify-center shrink-0">
                                            <?php echo $initial; ?>
                                        </div>
                                        <div class="min-w-0">
                                            <span class="font-bold text-on-surface group-hover:text-primary transition-colors block truncate text-xs sm:text-sm">
                                                <?php echo e($name); ?>
                                            </span>
                                            <span class="text-[11px] text-on-surface-variant block">
                                                <?php echo ($row['record_type'] === 'patient') ? 'Registered Patient' : 'Walk-in Customer'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <!-- MRN & Phone -->
                                <td class="py-3 px-4 font-mono">
                                    <span class="text-xs font-bold text-primary block"><?php echo e($mrn); ?></span>
                                    <span class="text-[11px] text-on-surface-variant flex items-center gap-1 mt-0.5">
                                        <span class="material-symbols-outlined text-[13px]">phone</span>
                                        <?php echo e($phone); ?>
                                    </span>
                                </td>

                                <!-- Invoices Count -->
                                <td class="py-3 px-4 text-center font-mono font-bold text-on-surface">
                                    <span class="px-2 py-0.5 rounded-full bg-surface-container text-xs">
                                        <?php echo (int)$row['total_invoices_count']; ?>
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
                                        <?php if ($isDebt): ?>
                                            <?php 
                                                $unpaidInvId = (int)($row['latest_unpaid_invoice_id'] ?? $latestInv);
                                                $unpaidInvNum = $row['latest_unpaid_invoice_number'] ?? ('INV-' . $unpaidInvId);
                                            ?>
                                            <button type="button" 
                                                    onclick="event.stopPropagation(); openPayPatientDebtModal('<?php echo $stmtKey; ?>', <?php echo $unpaidInvId; ?>, '<?php echo e(addslashes($name)); ?>', '<?php echo e(addslashes($unpaidInvNum)); ?>', <?php echo $due; ?>)"
                                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-xs flex items-center gap-1.5 cursor-pointer hover:shadow-md"
                                                    title="Collect Payment in Installments or in Full">
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
<!-- MODAL: Unified Customer AR Statement & Running Balance Ledger               -->
<!-- ========================================================================= -->
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
                <button type="button" 
                        id="stmt-pay-debt-btn"
                        onclick="payDebtFromStatement()" 
                        class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer transition-colors shadow-xs">
                    <span class="material-symbols-outlined text-[16px]">payments</span>
                    Pay Debt
                </button>
                <button type="button" onclick="printCustomerStatement()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer transition-colors shadow-2xs">
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
                    <h4 class="font-bold text-base text-primary uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                    <p class="text-xs text-on-surface-variant">Patient Billing &amp; Revenue Accounts Office • Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?></p>
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

            <!-- Unified Customer Balance Detail / Running Statement Ledger -->
            <div class="space-y-2 mt-4">
                <div class="overflow-x-auto custom-scrollbar border border-outline-variant rounded-xl bg-white text-gray-900">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b-2 border-gray-800 text-gray-800 font-bold uppercase text-[11px] bg-gray-50">
                                <th class="py-2.5 px-3">Type</th>
                                <th class="py-2.5 px-3">Date</th>
                                <th class="py-2.5 px-3">Num</th>
                                <th class="py-2.5 px-3">Account</th>
                                <th class="py-2.5 px-3 text-right font-mono">Amount</th>
                                <th class="py-2.5 px-3 text-right font-mono">Balance</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-ledger-tbody" class="divide-y divide-gray-200 text-xs">
                            <!-- Injected dynamically by JS -->
                        </tbody>
                        <tfoot id="stmt-ledger-tfoot" class="border-t-2 border-gray-800 font-bold bg-gray-50 text-xs">
                            <!-- Dynamic summary rows injected by JS -->
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Official Medical Invoice Document View                             -->
<!-- ========================================================================= -->
<div id="invoice-document-modal" class="fixed inset-0 z-[60] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-xl w-full p-6 shadow-2xl custom-scrollbar max-h-[92vh] overflow-y-auto space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px] text-primary">description</span>
                Official Medical Invoice
            </span>
            <button type="button" onclick="closeInvoiceDocumentModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Invoice Area -->
        <div id="printable-invoice-document" class="space-y-4 text-xs bg-white text-black p-5 rounded-xl border border-gray-300 font-mono">
            <div class="text-center border-b border-gray-300 pb-3 space-y-0.5">
                <h3 class="font-bold text-base text-gray-900 uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h3>
                <p class="text-[11px] text-gray-600">Specialist Care &amp; Clinical Operations</p>
                <p class="text-[10px] text-gray-500"><?php echo htmlspecialchars(HOSPITAL_PHONE); ?> &bull; Mogadishu, Somalia</p>
                <div class="flex justify-between items-center border-t border-dashed border-gray-300 mt-2 pt-2 text-xs font-bold text-primary">
                    <span id="inv-doc-number">Invoice #: -</span>
                    <span id="inv-doc-date" class="text-gray-500 font-normal">Date: -</span>
                </div>
            </div>

            <!-- Demographics -->
            <div class="grid grid-cols-2 gap-2 text-xs text-gray-700">
                <div>
                    <span class="text-gray-500 block">Patient:</span>
                    <strong id="inv-doc-patient" class="text-gray-900 font-sans font-bold text-sm">-</strong>
                    <p id="inv-doc-mrn" class="text-[11px] text-gray-500 mt-0.5">MRN: -</p>
                </div>
                <div class="text-right">
                    <span class="text-gray-500 block">Phone:</span>
                    <span id="inv-doc-phone" class="font-bold text-gray-900">-</span>
                    <p id="inv-doc-billtype" class="text-[11px] text-gray-500 mt-0.5">Clinical Bill</p>
                </div>
            </div>

            <!-- Itemized Table -->
            <table class="w-full border-t border-b border-gray-300 text-xs my-2">
                <thead>
                    <tr class="border-b border-gray-300 text-gray-600">
                        <th class="py-1 text-left">DESCRIPTION</th>
                        <th class="py-1 text-right">QTY</th>
                        <th class="py-1 text-right">UNIT ($)</th>
                        <th class="py-1 text-right">TOTAL ($)</th>
                    </tr>
                </thead>
                <tbody id="inv-doc-items-tbody" class="divide-y divide-gray-200">
                    <!-- Items injected by JS -->
                </tbody>
            </table>

            <!-- Financial Totals -->
            <div class="space-y-1 text-right text-xs">
                <div class="flex justify-between text-gray-600 font-bold">
                    <span>Subtotal:</span>
                    <span id="inv-doc-subtotal">$0.00</span>
                </div>
                <div id="inv-doc-discount-row" class="flex justify-between text-amber-700 font-bold hidden">
                    <span>Discount:</span>
                    <span id="inv-doc-discount">-$0.00</span>
                </div>
                <div class="flex justify-between text-gray-900 font-bold text-sm border-t border-gray-200 pt-1">
                    <span>Net Total:</span>
                    <span id="inv-doc-net">$0.00</span>
                </div>
                <div class="flex justify-between text-green-700 font-bold">
                    <span>Paid to Date:</span>
                    <span id="inv-doc-paid">$0.00</span>
                </div>
                <div class="flex justify-between text-red-600 font-bold text-sm border-t border-gray-300 pt-1">
                    <span>Balance Due:</span>
                    <span id="inv-doc-due">$0.00</span>
                </div>
            </div>

            <div class="text-center pt-3 border-t border-gray-200 text-[10px] text-gray-500 space-y-0.5">
                <p>Thank you for choosing <?php echo htmlspecialchars(HOSPITAL_NAME); ?>.</p>
                <p id="inv-doc-footer-status" class="font-bold text-amber-700">⚠️ OUTSTANDING BALANCE DUE</p>
            </div>
        </div>

        <div class="pt-2 border-t border-outline-variant flex justify-end gap-2">
            <button type="button" onclick="closeInvoiceDocumentModal()" class="px-3.5 py-1.5 bg-surface-container text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
            <button type="button" onclick="printInvoiceDocument()" class="px-4 py-1.5 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Invoice
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Official Payment Receipt Document View                             -->
<!-- ========================================================================= -->
<div id="receipt-document-modal" class="fixed inset-0 z-[60] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar max-h-[92vh] overflow-y-auto space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px] text-secondary">receipt_long</span>
                Official Payment Receipt
            </span>
            <button type="button" onclick="closeReceiptDocumentModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Receipt Slip Area -->
        <div id="printable-receipt-document" class="space-y-4 text-xs bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono">
            <div class="text-center border-b border-gray-300 pb-3 space-y-0.5">
                <h3 class="font-bold text-base text-gray-900 uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h3>
                <p class="text-[11px] text-gray-600">Specialist Care &amp; Clinical Operations</p>
                <p class="text-[10px] text-gray-500"><?php echo htmlspecialchars(HOSPITAL_PHONE); ?> &bull; Mogadishu, Somalia</p>
                <div class="border-t border-dashed border-gray-300 mt-2 pt-2">
                    <p class="font-bold text-xs text-gray-900">PAYMENT RECEIPT / CONFIRMATION SLIP</p>
                    <p id="rec-doc-number" class="text-xs font-bold text-primary mt-0.5">REC: -</p>
                    <p id="rec-doc-date" class="text-[10px] text-gray-500">Date: -</p>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="space-y-1.5 text-xs text-gray-800">
                <div class="flex justify-between"><span class="text-gray-500">Patient:</span> <strong id="rec-doc-patient" class="text-gray-900 font-sans font-bold">-</strong></div>
                <div class="flex justify-between"><span class="text-gray-500">MRN:</span> <span id="rec-doc-mrn">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Invoice Ref:</span> <span id="rec-doc-inv" class="font-bold">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Payment Method:</span> <span id="rec-doc-method" class="font-bold uppercase text-gray-900">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Received By:</span> <span id="rec-doc-cashier">-</span></div>
            </div>

            <!-- Financial Settlement Box -->
            <div class="border-t border-b border-dashed border-gray-300 py-2.5 space-y-1 font-bold">
                <div class="flex justify-between text-gray-600"><span>Previous Balance:</span> <span id="rec-doc-prev" class="font-mono">$0.00</span></div>
                <div class="flex justify-between text-green-700 text-sm border-t border-gray-200 pt-1"><span>Amount Paid:</span> <span id="rec-doc-paid" class="font-mono">+$0.00</span></div>
                <div class="flex justify-between text-red-600"><span>Remaining Balance:</span> <span id="rec-doc-rem" class="font-mono">$0.00</span></div>
            </div>

            <div id="rec-doc-notes-container" class="text-[10px] text-gray-600 hidden">
                <span class="text-gray-500">Notes:</span> <span id="rec-doc-notes">-</span>
            </div>

            <div class="text-center pt-2 border-t border-dashed border-gray-300 text-[10px] text-gray-500 space-y-0.5">
                <p class="font-bold text-green-700">✓ PAYMENT VERIFIED &amp; ACCOUNT CREDITED</p>
                <p>Thank you for your payment.</p>
            </div>
        </div>

        <div class="pt-2 border-t border-outline-variant flex justify-end gap-2">
            <button type="button" onclick="closeReceiptDocumentModal()" class="px-3.5 py-1.5 bg-surface-container text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
            <button type="button" onclick="printReceiptDocument()" class="px-4 py-1.5 bg-secondary text-on-secondary rounded-xl text-xs font-bold hover:bg-secondary-container shadow-xs cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Receipt Slip
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: Quick Patient Debt Installment Repayment                             -->
<!-- ========================================================================= -->
<div id="pay-patient-debt-modal" class="fixed inset-0 z-[60] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-5 sm:p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-emerald-500/15 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">payments</span>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-on-surface">Collect Patient Debt</h3>
                    <p class="text-[11px] text-on-surface-variant">Record debt payment installment or full settlement</p>
                </div>
            </div>
            <button type="button" onclick="closePayPatientDebtModal()" class="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-on-surface cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">close</span>
            </button>
        </div>

        <form method="POST" action="patient_debts.php" class="space-y-4" id="pay-debt-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <input type="hidden" name="action" value="pay_patient_debt">
            <input type="hidden" name="invoice_id" id="pay-invoice-id" value="0">

            <!-- Summary Patient & Debt Badge -->
            <div class="p-3.5 rounded-xl bg-surface-container border border-outline-variant/60 space-y-2">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-[10px] text-on-surface-variant block uppercase font-bold tracking-wider">Patient / Debtor</span>
                        <span class="font-bold text-sm text-on-surface" id="pay-patient-name">-</span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] text-on-surface-variant block uppercase font-bold tracking-wider">Outstanding Due</span>
                        <span class="font-mono font-bold text-base text-error" id="pay-outstanding-due">$0.00</span>
                    </div>
                </div>

                <!-- Invoice Reference or Selector -->
                <div class="pt-2 border-t border-outline-variant/50">
                    <label class="text-[11px] font-bold text-on-surface-variant block mb-1">Target Invoice for Payment:</label>
                    <div id="pay-invoice-select-container" class="hidden">
                        <select id="pay-invoice-select" 
                                onchange="onPayInvoiceSelectChange(this)"
                                class="w-full bg-surface border border-outline-variant rounded-lg px-2.5 py-1.5 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none">
                            <!-- Populated if multiple unpaid invoices exist -->
                        </select>
                    </div>
                    <div id="pay-invoice-static-badge" class="flex items-center gap-1.5 text-xs font-mono font-bold text-primary">
                        <span class="material-symbols-outlined text-[15px]">description</span>
                        <span id="pay-invoice-num">-</span>
                    </div>
                </div>
            </div>

            <!-- Amount to Pay Input -->
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-bold text-on-surface flex items-center gap-1">
                        Amount to Pay ($)
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
                           oninput="updatePatientPaymentCalc()"
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
                    <span>Installment Paid (Qaybta la dhiibayo):</span>
                    <span class="font-mono font-bold" id="calc-paying-amt">-$0.00</span>
                </div>
                <div class="border-t border-outline-variant/60 pt-1 flex justify-between items-center">
                    <span class="font-bold text-on-surface">Remaining Balance (Hadhaaga):</span>
                    <span class="font-mono font-bold text-sm text-error" id="calc-rem-bal">$0.00</span>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-on-surface">Payment Method</label>
                <select name="payment_method" class="w-full bg-surface border border-outline-variant rounded-xl px-3 py-2 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                    <option value="cash">Cash (Khasnadda)</option>
                    <option value="mobile">Mobile Money (EVC Plus / Zaad / Sahal)</option>
                    <option value="bank">Bank Transfer (Commercial Banks)</option>
                    <option value="card">Credit / Debit Card</option>
                </select>
            </div>

            <!-- Notes -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-on-surface">Notes / Memo (Optional)</label>
                <input type="text" name="notes" placeholder="e.g. 1st installment, paid by guarantor" class="w-full bg-surface border border-outline-variant rounded-xl px-3 py-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closePayPatientDebtModal()" class="px-4 py-2 rounded-xl border border-outline-variant text-xs font-semibold hover:bg-surface-container cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold flex items-center gap-1.5 cursor-pointer shadow-xs">
                    <span class="material-symbols-outlined text-[16px]">check</span>
                    Confirm &amp; Record Installment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: Unified Ledger, Statement & Instant Receipt Handling          -->
<!-- ========================================================================= -->
<script>
    const arStatementsData = <?php echo json_encode($arStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let activeStatementKey = null;
    let currentDebtDue = 0.0;

    function formatDateLedger(rawDate) {
        if (!rawDate) return '-';
        const d = new Date(rawDate);
        if (isNaN(d.getTime())) {
            return rawDate.substring(0, 10);
        }
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const yyyy = d.getFullYear();
        return `${mm}/${dd}/${yyyy}`;
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

    function openCustomerStatementModal(stmtKey) {
        activeStatementKey = stmtKey;
        let stmt = arStatementsData[stmtKey];

        if (!stmt) {
            // Fallback: fetch live if not preloaded
            const isPatient = stmtKey.startsWith('p_');
            const targetId = stmtKey.replace(/^[a-z]+_/, '');
            let url = '../api/live_sync.php?module=patient_debt_statement&' + (isPatient ? 'patient_id=' : 'invoice_id=') + encodeURIComponent(targetId);

            fetch(url)
                .then(r => r.json())
                .then(res => {
                    if (res && res.status === 'success' && res.statement) {
                        arStatementsData[stmtKey] = res.statement;
                        renderStatementModal(stmtKey, res.statement);
                    } else {
                        alert('Statement data could not be loaded.');
                    }
                })
                .catch(err => {
                    console.error('[STATEMENT ERROR]', err);
                    alert('Error loading statement records.');
                });
            return;
        }

        renderStatementModal(stmtKey, stmt);
    }

    function renderStatementModal(stmtKey, stmt) {
        const p = stmt.patient || {};
        document.getElementById('stmt-patient-title').innerText = (p.name || 'Debtor') + ' - Statement';
        document.getElementById('stmt-patient-subtitle').innerText = (p.type || 'Registered Patient') + ' Financial Statement';
        document.getElementById('stmt-patient-name').innerText = p.name || 'Customer';
        document.getElementById('stmt-patient-mrn').innerText = 'MRN / ID: ' + (p.mrn || 'Walk-in');
        document.getElementById('stmt-patient-phone').innerText = 'Phone: ' + (p.phone || 'N/A');
        document.getElementById('stmt-patient-address').innerText = 'Address: ' + (p.address || 'N/A');

        document.getElementById('stmt-patient-tot-invoiced').innerText = '$' + parseFloat(stmt.total_invoiced || 0).toFixed(2);
        document.getElementById('stmt-patient-tot-paid').innerText = '$' + parseFloat(stmt.total_paid || 0).toFixed(2);
        document.getElementById('stmt-patient-tot-due').innerText = '$' + parseFloat(stmt.total_due || 0).toFixed(2);

        // Control Pay Debt Button in Statement Header
        const payBtn = document.getElementById('stmt-pay-debt-btn');
        if (parseFloat(stmt.total_due || 0) > 0.005) {
            payBtn.classList.remove('hidden');
        } else {
            payBtn.classList.add('hidden');
        }

        // Build Unified Chronological Ledger
        const ledgerItems = [];

        // 1. Invoices (initial debt portion accrued to Accounts Receivable)
        (stmt.invoices || []).forEach(inv => {
            const debtPaymentsOnInv = (stmt.payments || []).filter(p => parseInt(p.invoice_id) === parseInt(inv.id)).reduce((s, p) => s + parseFloat(p.amount_paid || 0), 0);
            const initialDebt = parseFloat(inv.due_amount || 0) + debtPaymentsOnInv;

            if (initialDebt <= 0.005) {
                return;
            }

            const rawDate = inv.created_at || '';
            ledgerItems.push({
                type: 'Invoice',
                rawDate: rawDate,
                date: formatDateLedger(rawDate),
                num: inv.invoice_number || ('INV-' + inv.id),
                account: 'Accounts Recei...',
                amount: initialDebt,
                isPayment: false,
                invoiceId: inv.id,
                itemSummary: inv.item_summary || (inv.bill_type + ' Bill')
            });
        });

        // 2. Payments (installments collected: negative reduction to AR)
        (stmt.payments || []).forEach(pay => {
            const rawDate = pay.paid_at || '';
            ledgerItems.push({
                type: 'Payment',
                rawDate: rawDate,
                date: formatDateLedger(rawDate),
                num: pay.receipt_number || ('REC-' + pay.id),
                account: 'Accounts Recei...',
                amount: -Math.abs(parseFloat(pay.amount_paid || 0)),
                isPayment: true,
                payId: pay.id,
                invoiceNumber: pay.invoice_number || ''
            });
        });

        // 3. Sort chronologically ascending
        ledgerItems.sort((a, b) => {
            if (a.rawDate < b.rawDate) return -1;
            if (a.rawDate > b.rawDate) return 1;
            if (a.type === 'Invoice' && b.type !== 'Invoice') return -1;
            if (a.type !== 'Invoice' && b.type === 'Invoice') return 1;
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
                        <p class="text-[11px] text-gray-500">This debtor has no active credit invoices or installments.</p>
                    </td>
                </tr>
            `;
        } else {
            // Group Heading: Customer / Debtor Name
            const grpTr = document.createElement('tr');
            grpTr.className = 'bg-gray-50/80 border-b border-gray-200';
            grpTr.innerHTML = `
                <td colspan="6" class="py-2.5 px-3 font-extrabold text-gray-900 text-sm tracking-tight">
                    ${escapeHtml(p.name || 'Customer')}
                </td>
            `;
            tbody.appendChild(grpTr);

            // Render each chronological transaction line
            ledgerItems.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-blue-50/60 cursor-pointer transition-colors group';

                if (item.isPayment) {
                    tr.onclick = () => viewReceiptDocument(stmtKey, item.payId);
                    tr.title = 'Click to view and print official payment receipt slip';
                    tr.innerHTML = `
                        <td class="py-2 px-3 font-semibold text-gray-800">Payment</td>
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewReceiptDocument('${stmtKey}', '${item.payId}')" 
                                    class="font-mono font-bold text-emerald-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">receipt_long</span>
                                ${escapeHtml(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600 truncate max-w-[150px]">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-emerald-700">-${Math.abs(item.amount).toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                } else {
                    tr.onclick = () => viewInvoiceDocument(stmtKey, item.invoiceId);
                    tr.title = 'Click to view and print official medical invoice';
                    tr.innerHTML = `
                        <td class="py-2 px-3 font-semibold text-gray-800">Invoice</td>
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewInvoiceDocument('${stmtKey}', ${item.invoiceId})" 
                                    class="font-mono font-bold text-blue-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">description</span>
                                ${escapeHtml(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600 truncate max-w-[150px]">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-gray-900">${item.amount.toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                }
                tbody.appendChild(tr);
            });
        }

        // 6. Render Ledger Summary Footers
        const tfoot = document.getElementById('stmt-ledger-tfoot');
        const finalBalance = (ledgerItems.length > 0) ? ledgerItems[ledgerItems.length - 1].balance : parseFloat(stmt.total_due || 0);
        const finalBalFormatted = Math.abs(finalBalance).toFixed(2);
        tfoot.innerHTML = `
            <tr class="border-t border-gray-400 font-bold">
                <td colspan="4" class="py-2 px-3 text-gray-900">Total ${escapeHtml(p.name || 'Customer')}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
            </tr>
            <tr class="border-b-4 border-double border-gray-900 bg-gray-100/70 font-extrabold">
                <td colspan="4" class="py-2 px-3 uppercase tracking-wider text-gray-900">TOTAL</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-gray-900">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-gray-900">${finalBalFormatted}</td>
            </tr>
        `;

        document.getElementById('customer-statement-modal').classList.remove('hidden');
    }

    function closeCustomerStatementModal() {
        document.getElementById('customer-statement-modal').classList.add('hidden');
    }

    function printCustomerStatement() {
        const target = document.getElementById('printable-customer-statement-area');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // --- INVOICE DOCUMENT VIEW HANDLERS ---
    function viewInvoiceDocument(stmtKey, invoiceId) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt) return;
        const inv = (stmt.invoices || []).find(i => parseInt(i.id) === parseInt(invoiceId));
        if (!inv) {
            alert('Invoice details could not be found.');
            return;
        }

        const p = stmt.patient || {};
        document.getElementById('inv-doc-number').textContent = 'Invoice #: ' + inv.invoice_number;
        document.getElementById('inv-doc-date').textContent = 'Date: ' + (inv.created_at ? inv.created_at.substring(0, 16) : '-');
        document.getElementById('inv-doc-patient').textContent = p.name || inv.customer_name || 'Patient';
        document.getElementById('inv-doc-mrn').textContent = p.mrn || 'N/A';
        document.getElementById('inv-doc-phone').textContent = p.phone || inv.customer_phone || 'N/A';
        document.getElementById('inv-doc-billtype').textContent = (inv.bill_type || 'Clinical') + ' Bill';

        // Populate items
        const tbody = document.getElementById('inv-doc-items-tbody');
        tbody.innerHTML = '';
        if (inv.items && inv.items.length > 0) {
            inv.items.forEach(it => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="py-1.5 text-gray-800">
                        <strong class="text-xs">${escapeHtml(it.item_name)}</strong>
                        ${it.item_description ? `<span class="block text-[10px] text-gray-500">${escapeHtml(it.item_description)}</span>` : ''}
                    </td>
                    <td class="py-1.5 text-right font-mono">${parseInt(it.quantity) || 1}</td>
                    <td class="py-1.5 text-right font-mono">$${parseFloat(it.unit_price).toFixed(2)}</td>
                    <td class="py-1.5 text-right font-mono font-bold">$${parseFloat(it.total_price).toFixed(2)}</td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-1.5 text-gray-800"><strong class="text-xs">${escapeHtml(inv.item_summary || (inv.bill_type + ' charges'))}</strong></td>
                <td class="py-1.5 text-right font-mono">1</td>
                <td class="py-1.5 text-right font-mono">$${parseFloat(inv.net_total).toFixed(2)}</td>
                <td class="py-1.5 text-right font-mono font-bold">$${parseFloat(inv.net_total).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        }

        document.getElementById('inv-doc-subtotal').textContent = '$' + parseFloat(inv.subtotal || inv.net_total).toFixed(2);
        const discRow = document.getElementById('inv-doc-discount-row');
        if (inv.discount && parseFloat(inv.discount) > 0.001) {
            document.getElementById('inv-doc-discount').textContent = '-$' + parseFloat(inv.discount).toFixed(2);
            discRow.classList.remove('hidden');
        } else {
            discRow.classList.add('hidden');
        }
        document.getElementById('inv-doc-net').textContent = '$' + parseFloat(inv.net_total).toFixed(2);
        document.getElementById('inv-doc-paid').textContent = '$' + parseFloat(inv.paid_amount).toFixed(2);
        document.getElementById('inv-doc-due').textContent = '$' + parseFloat(inv.due_amount).toFixed(2);

        const dueVal = parseFloat(inv.due_amount || 0);
        document.getElementById('inv-doc-footer-status').textContent = (dueVal <= 0.005) 
            ? '✓ PAID IN FULL — Thank You' 
            : `⚠️ OUTSTANDING BALANCE DUE: $${dueVal.toFixed(2)}`;

        document.getElementById('invoice-document-modal').classList.remove('hidden');
    }

    function closeInvoiceDocumentModal() {
        document.getElementById('invoice-document-modal').classList.add('hidden');
    }

    function printInvoiceDocument() {
        const target = document.getElementById('printable-invoice-document');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // --- RECEIPT DOCUMENT VIEW HANDLERS ---
    function viewReceiptDocument(stmtKey, payId) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt) return;
        const pay = (stmt.payments || []).find(p => String(p.id) === String(payId) || String(p.receipt_number) === String(payId));
        if (!pay) {
            alert('Receipt details could not be found.');
            return;
        }

        const p = stmt.patient || {};
        document.getElementById('rec-doc-number').textContent = pay.receipt_number || ('REC-' + pay.id);
        document.getElementById('rec-doc-date').textContent = 'Date: ' + (pay.paid_at || '-');
        document.getElementById('rec-doc-patient').textContent = p.name || 'Patient';
        document.getElementById('rec-doc-mrn').textContent = p.mrn || 'N/A';
        document.getElementById('rec-doc-inv').textContent = pay.invoice_number || '-';
        document.getElementById('rec-doc-method').textContent = (pay.payment_method || 'CASH').toUpperCase();
        document.getElementById('rec-doc-cashier').textContent = pay.cashier_name || 'Cashier Desk';

        document.getElementById('rec-doc-prev').textContent = '$' + parseFloat(pay.previous_balance || 0).toFixed(2);
        document.getElementById('rec-doc-paid').textContent = '+$' + parseFloat(pay.amount_paid || 0).toFixed(2);
        document.getElementById('rec-doc-rem').textContent = '$' + parseFloat(pay.remaining_balance || 0).toFixed(2);

        const notesBox = document.getElementById('rec-doc-notes-container');
        if (pay.notes && pay.notes.trim() !== '') {
            document.getElementById('rec-doc-notes').textContent = pay.notes;
            notesBox.classList.remove('hidden');
        } else {
            notesBox.classList.add('hidden');
        }

        document.getElementById('receipt-document-modal').classList.remove('hidden');
    }

    function closeReceiptDocumentModal() {
        document.getElementById('receipt-document-modal').classList.add('hidden');
    }

    function printReceiptDocument() {
        const target = document.getElementById('printable-receipt-document');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // =========================================================================
    // QUICK DEBT PAYMENT MODAL HANDLERS
    // =========================================================================
    function openPayPatientDebtModal(stmtKey, invoiceId, patientName, invoiceNum, dueAmount) {
        currentDebtDue = parseFloat(dueAmount || 0);
        document.getElementById('pay-patient-name').textContent = patientName || 'Patient';
        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);

        const stmt = arStatementsData[stmtKey];
        const unpaidInvoices = stmt ? (stmt.invoices || []).filter(i => parseFloat(i.due_amount || 0) > 0.005) : [];

        const selectContainer = document.getElementById('pay-invoice-select-container');
        const selectEl = document.getElementById('pay-invoice-select');
        const staticBadge = document.getElementById('pay-invoice-static-badge');

        if (unpaidInvoices.length > 1) {
            selectEl.innerHTML = '';
            unpaidInvoices.forEach(inv => {
                const opt = document.createElement('option');
                opt.value = inv.id;
                opt.textContent = `${inv.invoice_number} (Due: $${parseFloat(inv.due_amount).toFixed(2)})`;
                opt.setAttribute('data-due', inv.due_amount);
                if (parseInt(inv.id) === parseInt(invoiceId)) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
            selectContainer.classList.remove('hidden');
            staticBadge.classList.add('hidden');
            document.getElementById('pay-invoice-id').value = selectEl.value;
            currentDebtDue = parseFloat(selectEl.options[selectEl.selectedIndex].getAttribute('data-due') || dueAmount);
        } else {
            selectContainer.classList.add('hidden');
            staticBadge.classList.remove('hidden');
            document.getElementById('pay-invoice-num').textContent = invoiceNum || ('INV-' + invoiceId);
            document.getElementById('pay-invoice-id').value = invoiceId;
        }

        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = currentDebtDue.toFixed(2);
        amtInput.max = currentDebtDue;

        updatePatientPaymentCalc();
        document.getElementById('pay-patient-debt-modal').classList.remove('hidden');
        amtInput.focus();
        amtInput.select();
    }

    function onPayInvoiceSelectChange(sel) {
        const opt = sel.options[sel.selectedIndex];
        const due = parseFloat(opt.getAttribute('data-due') || 0);
        document.getElementById('pay-invoice-id').value = sel.value;
        currentDebtDue = due;
        document.getElementById('pay-outstanding-due').textContent = '$' + currentDebtDue.toFixed(2);
        
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = currentDebtDue.toFixed(2);
        amtInput.max = currentDebtDue;
        updatePatientPaymentCalc();
    }

    function payDebtFromStatement() {
        if (!activeStatementKey) return;
        const stmt = arStatementsData[activeStatementKey];
        if (!stmt) return;
        const unpaid = (stmt.invoices || []).filter(i => parseFloat(i.due_amount || 0) > 0.005);
        if (unpaid.length === 0) {
            alert('This customer has no outstanding invoice debt.');
            return;
        }
        const target = unpaid[0];
        const p = stmt.patient || {};
        openPayPatientDebtModal(activeStatementKey, target.id, p.name, target.invoice_number, target.due_amount);
    }

    function closePayPatientDebtModal() {
        document.getElementById('pay-patient-debt-modal').classList.add('hidden');
    }

    function setQuickPaymentAmount(ratio) {
        const amt = Math.max(0.01, round2(currentDebtDue * ratio));
        const amtInput = document.getElementById('pay-amount-input');
        amtInput.value = amt.toFixed(2);
        updatePatientPaymentCalc();
    }

    function updatePatientPaymentCalc() {
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

    function round2(val) {
        return Math.round((val + Number.EPSILON) * 100) / 100;
    }

    // --- SEARCH FILTER ---
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
            const phone = row.getAttribute('data-phone') || '';
            const mrn = row.getAttribute('data-mrn') || '';

            if (q === '' || name.includes(q) || phone.includes(q) || mrn.includes(q)) {
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

    // Auto-display official receipt modal if a debt payment was just recorded
    <?php if (!empty($recentReceipt)): ?>
    document.addEventListener('DOMContentLoaded', () => {
        const rc = <?php echo json_encode($recentReceipt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        document.getElementById('rec-doc-number').textContent = rc.receipt_number || ('REC-' + rc.id);
        document.getElementById('rec-doc-date').textContent = 'Date: ' + (rc.paid_at || '-');
        document.getElementById('rec-doc-patient').textContent = rc.patient_name || rc.customer_name || 'Patient';
        document.getElementById('rec-doc-mrn').textContent = rc.mrn || 'N/A';
        document.getElementById('rec-doc-inv').textContent = rc.invoice_number || '-';
        document.getElementById('rec-doc-method').textContent = (rc.payment_method || 'CASH').toUpperCase();
        document.getElementById('rec-doc-cashier').textContent = rc.cashier_name || 'Cashier Desk';

        document.getElementById('rec-doc-prev').textContent = '$' + parseFloat(rc.previous_balance || 0).toFixed(2);
        document.getElementById('rec-doc-paid').textContent = '+$' + parseFloat(rc.amount_paid || 0).toFixed(2);
        document.getElementById('rec-doc-rem').textContent = '$' + parseFloat(rc.remaining_balance || 0).toFixed(2);

        const notesBox = document.getElementById('rec-doc-notes-container');
        if (rc.notes && rc.notes.trim() !== '') {
            document.getElementById('rec-doc-notes').textContent = rc.notes;
            notesBox.classList.remove('hidden');
        }

        document.getElementById('receipt-document-modal').classList.remove('hidden');
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
