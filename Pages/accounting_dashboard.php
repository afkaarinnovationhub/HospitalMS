<?php
/**
 * MedCore Systems - Accounting & Financial Management Dashboard
 * Executive Overview, Key Financial Indicators (P&L, Balance Sheet, AR, AP, Expenses),
 * and dynamic financial reporting hub.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../CONTROLS/AccountingController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

// Auto-seed chart of accounts if first run
AccountingOperation::seedChartOfAccountsIfEmpty();

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions (e.g. Quick Expense, Capital Injection from modal)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_expense') {
        $result = AccountingController::handleRecordExpense($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'create_account') {
        $result = AccountingController::handleCreateAccount($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'record_capital') {
        $result = AccountingController::handleRecordCapitalInvestment($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'record_transfer') {
        $result = AccountingController::handleRecordTransfer($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Retrieve Real Accounting KPIs & Reports
$kpis = AccountingOperation::getAccountingDashboardKPIs();
$pnl = AccountingOperation::getProfitAndLossReport(date('Y-m-01'), date('Y-m-d'));
$ar = AccountingOperation::getAccountsReceivableReport();
$ap = AccountingOperation::getAccountsPayableReport();
$recentExpenses = AccountingOperation::getExpenses(date('Y-m-01'), date('Y-m-d'));
$expenseAccounts = AccountingOperation::getAllAccounts('expense');
$liquidAccounts = AccountingOperation::getLiquidMoneyAccounts();
$recentTransfers = AccountingOperation::getAccountTransfers(null, null, 10);

$pageTitle = 'Accounting & Finance Dashboard - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Accounting & Finance';
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
                <p class="font-bold">Accounting Alert</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Transaction Recorded</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Title & Quick Actions -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[28px]">account_balance</span>
                Accounting &amp; Finance
            </h2>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Financial Accountant'); ?></strong>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openTransferModal()" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">swap_horiz</span>
                Transfer Money
            </button>
            <button type="button" onclick="openCapitalModal()" class="px-3.5 py-2 bg-secondary hover:bg-on-secondary-container text-on-secondary font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span>
                Capital / Investment
            </button>
            <button type="button" onclick="openExpenseModal()" class="px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">receipt_long</span>
                Record Expense
            </button>
            <button type="button" onclick="openCreateAccountModal()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                New Account
            </button>
        </div>
    </div>

    <!-- Accounting Navigation Tabs -->
    <div class="flex items-center gap-1 border-b border-outline-variant pb-2 mb-6 overflow-x-auto custom-scrollbar text-xs font-semibold">
        <a href="accounting_dashboard.php" class="px-3.5 py-1.5 bg-primary text-on-primary rounded-lg font-bold flex items-center gap-1.5 shrink-0 shadow-xs">
            <span class="material-symbols-outlined text-[16px]">dashboard</span>
            Overview
        </a>
        <a href="profit_and_loss.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">trending_up</span>
            Profit &amp; Loss
        </a>
        <a href="balance_sheet.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">balance</span>
            Balance Sheet
        </a>
        <a href="trial_balance.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">account_tree</span>
            Trial Balance
        </a>
        <a href="accounts_receivable.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">person_pin</span>
            Accounts Receivable
        </a>
        <a href="accounts_payable.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">store</span>
            Accounts Payable
        </a>
        <a href="expenses.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">payments</span>
            Expenses
        </a>
        <a href="chart_of_accounts.php" class="px-3.5 py-1.5 text-on-surface-variant hover:text-on-surface hover:bg-surface-container rounded-lg flex items-center gap-1.5 shrink-0 transition-colors">
            <span class="material-symbols-outlined text-[16px]">list_alt</span>
            Chart of Accounts
        </a>
    </div>

    <!-- Executive Financial KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- 1. Monthly Revenue -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-on-surface-variant">Revenue (This Month)</span>
                <span class="w-8 h-8 rounded-full bg-primary-fixed/40 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">payments</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-on-surface font-mono">$<?php echo number_format((float)$kpis['monthly_revenue'], 2); ?></h3>
            </div>
        </div>

        <!-- 2. Monthly Expenses -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-on-surface-variant">Operating Expenses (MTD)</span>
                <span class="w-8 h-8 rounded-full bg-error-container text-on-error-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">receipt_long</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$kpis['monthly_expenses'], 2); ?></h3>
            </div>
        </div>

        <!-- 3. Net Profit / Loss -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-on-surface-variant">Net Profit (MTD)</span>
                <span class="w-8 h-8 rounded-full <?php echo $kpis['monthly_net_profit'] >= 0 ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container'; ?> flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]"><?php echo $kpis['monthly_net_profit'] >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl font-bold <?php echo $kpis['monthly_net_profit'] >= 0 ? 'text-secondary' : 'text-error'; ?> font-mono">
                    $<?php echo number_format((float)$kpis['monthly_net_profit'], 2); ?>
                </h3>
            </div>
        </div>

        <!-- 4. Accounts Receivable (Patient Debts) -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-on-surface-variant">Accounts Receivable (AR)</span>
                <span class="w-8 h-8 rounded-full bg-amber-500/20 text-amber-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">pending_actions</span>
                </span>
            </div>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$kpis['total_ar'], 2); ?></h3>
            </div>
        </div>
    </div>

    <!-- Secondary Row: Financial Position & Quick Statement Breakdowns -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        <!-- P&L Summary Breakdown (7 cols) -->
        <div class="lg:col-span-7 bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
            <div class="flex items-center justify-between border-b border-outline-variant pb-3">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary text-[20px]">donut_small</span>
                        Income Statement Summary (Current Month)
                    </h3>
                    <p class="text-[11px] text-on-surface-variant">From <?php echo date('M 01, Y'); ?> to <?php echo date('M d, Y'); ?></p>
                </div>
                <a href="profit_and_loss.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-0.5">
                    Full P&amp;L Report &rarr;
                </a>
            </div>

            <div class="space-y-2.5 text-xs">
                <!-- Revenue line -->
                <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant flex justify-between items-center">
                    <span class="font-semibold text-on-surface flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-primary"></span>
                        1. Total Clinical Revenues
                    </span>
                    <span class="font-bold font-mono text-primary text-sm">+$<?php echo number_format((float)$pnl['revenues']['total_revenue'], 2); ?></span>
                </div>

                <!-- COGS line -->
                <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant flex justify-between items-center">
                    <span class="font-semibold text-on-surface flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        2. Cost of Goods Sold (Dispensed Medications)
                    </span>
                    <span class="font-bold font-mono text-amber-600 text-sm">-$<?php echo number_format((float)$pnl['cogs']['total_cogs'], 2); ?></span>
                </div>

                <!-- Gross Profit line -->
                <div class="p-2.5 rounded-xl bg-primary-fixed/20 border border-primary/30 flex justify-between items-center font-bold">
                    <span class="text-on-surface">3. Gross Profit</span>
                    <span class="font-mono text-primary text-sm">$<?php echo number_format((float)$pnl['gross_profit'], 2); ?></span>
                </div>

                <!-- Operating Expenses line -->
                <div class="p-2.5 rounded-xl bg-surface-container-lowest border border-outline-variant flex justify-between items-center">
                    <span class="font-semibold text-on-surface flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-error"></span>
                        4. Total Operating Expenses
                    </span>
                    <span class="font-bold font-mono text-error text-sm">-$<?php echo number_format((float)$pnl['expenses']['total_expenses'], 2); ?></span>
                </div>

                <!-- Net Profit line -->
                <div class="p-3 rounded-xl <?php echo $pnl['net_profit'] >= 0 ? 'bg-secondary-fixed/40 border border-secondary/40' : 'bg-error-container border border-error/40'; ?> flex justify-between items-center font-bold text-sm">
                    <span class="text-on-surface">5. Net Operating Profit</span>
                    <span class="font-mono <?php echo $pnl['net_profit'] >= 0 ? 'text-secondary' : 'text-error'; ?>">
                        $<?php echo number_format((float)$pnl['net_profit'], 2); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Working Capital & Balance Sheet Summary (5 cols) -->
        <div class="lg:col-span-5 bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
            <div class="flex items-center justify-between border-b border-outline-variant pb-3">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                        <span class="material-symbols-outlined text-secondary text-[20px]">account_balance_wallet</span>
                        Hospital Working Capital
                    </h3>
                </div>
                <a href="balance_sheet.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-0.5">
                    Balance Sheet &rarr;
                </a>
            </div>

            <div class="space-y-2 text-xs">
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Cash on Hand</span>
                    <span class="font-mono font-bold text-on-surface">$<?php echo number_format((float)($kpis['cash_on_hand'] ?? 0), 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Mobile Money</span>
                    <span class="font-mono font-bold text-on-surface">$<?php echo number_format((float)($kpis['mobile_money'] ?? 0), 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Bank Account</span>
                    <span class="font-mono font-bold text-on-surface">$<?php echo number_format((float)($kpis['bank_account'] ?? 0), 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Pharmacy Inventory</span>
                    <span class="font-mono font-bold text-on-surface">$<?php echo number_format((float)$kpis['inventory_asset'], 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Accounts Receivable</span>
                    <span class="font-mono font-bold text-amber-600">$<?php echo number_format((float)$kpis['total_ar'], 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2 rounded-lg bg-surface-container-lowest border border-outline-variant">
                    <span class="text-on-surface-variant font-medium">Accounts Payable</span>
                    <span class="font-mono font-bold text-error">$<?php echo number_format((float)$kpis['total_ap'], 2); ?></span>
                </div>
                <div class="flex justify-between items-center p-2.5 rounded-lg bg-primary-fixed/20 border border-primary/30 font-bold">
                    <span class="text-on-surface">Total Asset Valuation</span>
                    <span class="font-mono text-primary">$<?php echo number_format((float)$kpis['total_assets'], 2); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Expenses Table -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">history_edu</span>
                    Recent Operating Expenses
                </h3>
            </div>
            <a href="expenses.php" class="text-xs text-primary font-bold hover:underline flex items-center gap-0.5">
                View All Expenses &rarr;
            </a>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-lowest">
                        <th class="py-2.5 px-3">Expense #</th>
                        <th class="py-2.5 px-3">Date</th>
                        <th class="py-2.5 px-3">Account Category</th>
                        <th class="py-2.5 px-3">Payee</th>
                        <th class="py-2.5 px-3">Description</th>
                        <th class="py-2.5 px-3">Method</th>
                        <th class="py-2.5 px-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($recentExpenses)): ?>
                        <tr>
                            <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[32px] text-on-surface-variant/40 block mb-1">receipt_long</span>
                                No operating expenses recorded for this month yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (array_slice($recentExpenses, 0, 8) as $exp): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2.5 px-3 font-mono font-bold text-primary"><?php echo e($exp['expense_number']); ?></td>
                                <td class="py-2.5 px-3 text-on-surface-variant"><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                                <td class="py-2.5 px-3 font-semibold text-on-surface">
                                    <span class="font-mono text-[10px] text-on-surface-variant"><?php echo e($exp['account_code']); ?></span> - <?php echo e($exp['account_name']); ?>
                                </td>
                                <td class="py-2.5 px-3 text-on-surface"><?php echo e($exp['payee']); ?></td>
                                <td class="py-2.5 px-3 text-on-surface-variant truncate max-w-xs"><?php echo e($exp['description']); ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="bg-surface-container text-on-surface text-[10px] uppercase font-bold px-2 py-0.5 rounded-full">
                                        <?php echo e($exp['payment_method']); ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-error">
                                    $<?php echo number_format((float)$exp['amount'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Inter-Account Money Transfers Card -->
    <div class="rounded-2xl bg-surface border border-outline-variant p-4 sm:p-5 shadow-xs mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">swap_horiz</span>
                </span>
                <div>
                    <h3 class="font-headline-sm text-sm sm:text-base font-bold text-on-surface">Recent Inter-Account Money Transfers</h3>
                    <p class="text-[11px] text-on-surface-variant">Real-time ledger audit trail of funds moved between Cash, Mobile Money &amp; Bank Accounts (Contra Entries).</p>
                </div>
            </div>
            <button type="button" onclick="openTransferModal()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs flex items-center gap-1 shadow-xs cursor-pointer self-start sm:self-auto transition-colors">
                <span class="material-symbols-outlined text-[16px]">add</span>
                New Transfer
            </button>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-[11px] font-bold text-on-surface-variant uppercase tracking-wider bg-surface-container-low/50">
                        <th class="py-2.5 px-3">Transfer #</th>
                        <th class="py-2.5 px-3">Date</th>
                        <th class="py-2.5 px-3">From Account</th>
                        <th class="py-2.5 px-3 text-center">Flow</th>
                        <th class="py-2.5 px-3">To Account</th>
                        <th class="py-2.5 px-3">Reference / Notes</th>
                        <th class="py-2.5 px-3">Transferred By</th>
                        <th class="py-2.5 px-3 text-right">Amount ($)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($recentTransfers)): ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-3xl text-outline mb-1">sync_alt</span>
                                <p class="text-xs">No inter-account money transfers recorded yet.</p>
                                <button type="button" onclick="openTransferModal()" class="mt-2 text-primary font-bold hover:underline text-xs cursor-pointer">
                                    + Click here to record your first transfer
                                </button>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentTransfers as $trf): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2.5 px-3 font-mono font-bold text-primary">
                                    <?php echo e($trf['transfer_number']); ?>
                                </td>
                                <td class="py-2.5 px-3 text-on-surface-variant whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($trf['transfer_date'])); ?>
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-on-surface whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 text-error">
                                        <span class="material-symbols-outlined text-[14px]">arrow_upward</span>
                                        [<?php echo e($trf['from_code']); ?>] <?php echo e($trf['from_name']); ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-emerald-600 text-[18px]">east</span>
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-on-surface whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 text-emerald-600">
                                        <span class="material-symbols-outlined text-[14px]">arrow_downward</span>
                                        [<?php echo e($trf['to_code']); ?>] <?php echo e($trf['to_name']); ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 text-on-surface-variant max-w-xs truncate">
                                    <?php if (!empty($trf['reference_number'])): ?>
                                        <span class="font-mono font-bold text-[10px] bg-surface-container px-1.5 py-0.5 rounded text-on-surface">Ref: <?php echo e($trf['reference_number']); ?></span>
                                    <?php endif; ?>
                                    <?php echo e($trf['notes'] ?: ''); ?>
                                </td>
                                <td class="py-2.5 px-3 text-on-surface-variant whitespace-nowrap">
                                    <?php echo e($trf['created_by_name'] ?? 'Accountant'); ?>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-emerald-600 text-sm whitespace-nowrap">
                                    $<?php echo number_format((float)$trf['amount'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL 1: Record Operating Expense -->
<div id="record-expense-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">receipt_long</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Record Hospital Expense</h3>
                    <p class="text-xs text-on-surface-variant">Post operating expense directly to General Ledger.</p>
                </div>
            </div>
            <button type="button" onclick="closeExpenseModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="accounting_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_expense">
            <input type="hidden" name="redirect" value="accounting_dashboard.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Expense Category (Account) *</label>
                <select name="account_id" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                    <option value="">-- Select Expense Account --</option>
                    <?php foreach ($expenseAccounts as $ea): ?>
                        <option value="<?php echo (int)$ea['id']; ?>">
                            [<?php echo e($ea['account_code']); ?>] <?php echo e($ea['account_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Amount ($ USD) *</label>
                    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Payment Method *</label>
                    <select name="payment_method" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="cash">Cash on Hand (Khasnad)</option>
                        <option value="mobile">Mobile Money (Zaad / EVC)</option>
                        <option value="bank">Bank Account</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Payee (Qofka/Shirkadda La Siiyay) *</label>
                    <input name="payee" type="text" required placeholder="e.g. Landlord, Utility Corp, Technician" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Expense Date *</label>
                    <input name="expense_date" type="date" required value="<?php echo date('Y-m-d'); ?>" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description &amp; Notes</label>
                <input name="description" type="text" placeholder="e.g. Facility monthly rent for current month" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeExpenseModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer">
                    Record &amp; Post Entry
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Create Custom Account in Chart of Accounts -->
<div id="create-account-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">add_card</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Add Account to COA</h3>
                    <p class="text-xs text-on-surface-variant">Create a custom financial ledger account.</p>
                </div>
            </div>
            <button type="button" onclick="closeCreateAccountModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="accounting_dashboard.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="redirect" value="accounting_dashboard.php">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Code *</label>
                    <input name="account_code" type="text" required placeholder="e.g. 6070" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Type *</label>
                    <select name="account_type" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="expense">Expense (Kharash)</option>
                        <option value="revenue">Revenue (Dakhli)</option>
                        <option value="asset">Asset (Hanti)</option>
                        <option value="liability">Liability (Deymo)</option>
                        <option value="equity">Equity (Raasumaal)</option>
                        <option value="cogs">COGS (Direct Cost)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Name *</label>
                <input name="account_name" type="text" required placeholder="e.g. Waste Management &amp; Sanitization" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description</label>
                <textarea name="description" rows="2" placeholder="Brief description of this account..." class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none"></textarea>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeCreateAccountModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer">
                    Save Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Record Founder / Owner Capital Investment Injection -->
<div id="capital-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[26px]">account_balance_wallet</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Owner Capital / Investment Injection</h3>
                    <p class="text-xs text-on-surface-variant">Deposit startup capital or cash injection into hospital accounts.</p>
                </div>
            </div>
            <button type="button" onclick="closeCapitalModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="accounting_dashboard.php" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_capital">
            <input type="hidden" name="redirect_to" value="accounting_dashboard.php">

            <div class="p-3 bg-secondary-fixed/30 rounded-xl border border-secondary/30 text-xs text-on-secondary-fixed-variant flex items-start gap-2.5">
                <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">info</span>
                <div>
                    <strong class="font-bold">Double-Entry Ledger Posting:</strong>
                    <p class="mt-0.5">Debits selected cash/bank asset and credits <strong>3010 - Owner's Capital (Raasumaalka)</strong>.</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Investment Amount ($ USD) *</label>
                <div class="relative">
                    <span class="absolute left-3 top-2.5 font-bold text-on-surface-variant text-sm">$</span>
                    <input name="amount" type="number" step="0.01" min="1" required placeholder="10000.00" class="w-full bg-surface-container-low border border-outline-variant rounded-xl pl-7 pr-3 py-2 text-sm font-mono font-bold text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-1">Deposit Destination *</label>
                    <select name="deposit_account" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                        <option value="1010">1010 - Cash on Hand (Khasnadda)</option>
                        <option value="1020">1020 - Mobile Money (EVC / Zaad)</option>
                        <option value="1030">1030 - Bank Account (Commercial)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-1">Deposit Date *</label>
                    <input name="deposit_date" type="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Founder / Investor Name *</label>
                <input name="investor_name" type="text" required placeholder="e.g. Dr. Ahmed Keynan &amp; Founding Partners" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Memo / Purpose Description</label>
                <textarea name="notes" rows="2" placeholder="e.g. Initial hospital startup capital and medication purchase reserve" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none"></textarea>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeCapitalModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-secondary text-on-secondary rounded-lg text-xs font-bold hover:bg-on-secondary-container shadow-xs cursor-pointer flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                    Confirm &amp; Deposit Capital
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Transfer Money Between Accounts (Inter-Account Contra Entry) -->
<div id="transfer-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="w-9 h-9 rounded-xl bg-emerald-500/15 text-emerald-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">swap_horiz</span>
                </span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Transfer Money Between Accounts</h3>
                    <p class="text-xs text-on-surface-variant">Move funds between Mobile Money, Bank &amp; Cash (Contra Entry).</p>
                </div>
            </div>
            <button type="button" onclick="closeTransferModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="accounting_dashboard.php" class="space-y-4" onsubmit="return validateTransferForm(this);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_transfer">
            <input type="hidden" name="redirect_to" value="accounting_dashboard.php">

            <div class="p-3 bg-emerald-500/10 rounded-xl border border-emerald-500/20 text-xs text-emerald-900 dark:text-emerald-300 flex items-start gap-2.5">
                <span class="material-symbols-outlined text-emerald-600 text-[20px] shrink-0 mt-0.5">verified</span>
                <div>
                    <strong class="font-bold">Zero Transfer Fee (1-to-1 Balanced Contra Entry):</strong>
                    <p class="mt-0.5">Direct ledger transfer. Credits source account and debits destination account without fee deductions.</p>
                </div>
            </div>

            <!-- Source Account -->
            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Source Account (Laga Qaaday) *</label>
                <select name="from_account_id" id="transfer_from_account" required class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2.5 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                    <option value="">-- Select Liquid Account (Cash / Mobile / Bank) --</option>
                    <?php foreach ($liquidAccounts as $acc): ?>
                        <?php $bal = AccountingOperation::getAccountBalanceByCode($acc['account_code']); ?>
                        <option value="<?php echo (int)$acc['id']; ?>" data-balance="<?php echo $bal; ?>">
                            [<?php echo e($acc['account_code']); ?>] <?php echo e($acc['account_name']); ?> &bull; Available: $<?php echo number_format($bal, 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Destination Account -->
            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Destination Account (Loo Diray) *</label>
                <select name="to_account_id" id="transfer_to_account" required class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2.5 text-xs font-semibold text-on-surface focus:border-primary outline-none">
                    <option value="">-- Select Destination Account (Cash / Mobile / Bank) --</option>
                    <?php foreach ($liquidAccounts as $acc): ?>
                        <?php $bal = AccountingOperation::getAccountBalanceByCode($acc['account_code']); ?>
                        <option value="<?php echo (int)$acc['id']; ?>">
                            [<?php echo e($acc['account_code']); ?>] <?php echo e($acc['account_name']); ?> &bull; Current: $<?php echo number_format($bal, 2); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-on-surface mb-1">Transfer Amount ($ USD) *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2.5 font-bold text-on-surface-variant text-sm">$</span>
                        <input name="amount" id="transfer_amount" type="number" step="0.01" min="0.01" required placeholder="500.00" class="w-full bg-surface-container-low border border-outline-variant rounded-xl pl-7 pr-3 py-2 text-sm font-mono font-bold text-on-surface focus:border-primary outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-on-surface mb-1">Transfer Date *</label>
                    <input name="transfer_date" type="date" value="<?php echo date('Y-m-d'); ?>" required class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Reference / Trx ID (EVC Statement ID / Slip #)</label>
                <input name="reference_number" type="text" placeholder="e.g. EVC-98124458 / Slip #44192" class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Purpose / Notes Description</label>
                <textarea name="notes" rows="2" placeholder="e.g. Daily Mobile Money sales settlement into Main Bank Account" class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2 text-xs text-on-surface focus:border-primary outline-none resize-none"></textarea>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeTransferModal()" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs cursor-pointer flex items-center gap-1.5 transition-colors">
                    <span class="material-symbols-outlined text-[16px]">sync_alt</span>
                    Confirm &amp; Post Transfer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openExpenseModal() {
        document.getElementById('record-expense-modal').classList.remove('hidden');
    }
    function closeExpenseModal() {
        document.getElementById('record-expense-modal').classList.add('hidden');
    }
    function openCreateAccountModal() {
        document.getElementById('create-account-modal').classList.remove('hidden');
    }
    function closeCreateAccountModal() {
        document.getElementById('create-account-modal').classList.add('hidden');
    }
    function openCapitalModal() {
        document.getElementById('capital-modal').classList.remove('hidden');
    }
    function closeCapitalModal() {
        document.getElementById('capital-modal').classList.add('hidden');
    }
    function openTransferModal() {
        document.getElementById('transfer-modal').classList.remove('hidden');
    }
    function closeTransferModal() {
        document.getElementById('transfer-modal').classList.add('hidden');
    }
    function validateTransferForm(form) {
        const fromSelect = form.querySelector('#transfer_from_account');
        const toSelect = form.querySelector('#transfer_to_account');
        const amountInput = form.querySelector('#transfer_amount');

        if (fromSelect.value === toSelect.value) {
            alert('Source and destination accounts cannot be the same. Please select two different accounts.');
            return false;
        }

        const selectedOpt = fromSelect.options[fromSelect.selectedIndex];
        const availableBalance = parseFloat(selectedOpt.getAttribute('data-balance') || '0');
        const transferAmount = parseFloat(amountInput.value || '0');

        if (transferAmount > availableBalance) {
            if (!confirm('Warning: The requested transfer amount ($' + transferAmount.toFixed(2) + ') exceeds the recorded balance ($' + availableBalance.toFixed(2) + ') of the source account. Do you still want to proceed?')) {
                return false;
            }
        }
        return true;
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
