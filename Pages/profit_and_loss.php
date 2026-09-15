<?php
/**
 * MedCore Systems - Income Statement (Profit & Loss / P&L)
 * Comprehensive revenue, COGS, operating expense breakdown and net earnings report.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

// Date range filters
$filter = sanitizeString($_GET['filter'] ?? 'this_month');
$today = date('Y-m-d');

switch ($filter) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        break;
    case 'this_quarter':
        $currentMonth = (int)date('n');
        $quarterStartMonth = (int)(floor(($currentMonth - 1) / 3) * 3 + 1);
        $startDate = date('Y-') . sprintf('%02d-01', $quarterStartMonth);
        $endDate = $today;
        break;
    case 'this_year':
        $startDate = date('Y-01-01');
        $endDate = $today;
        break;
    case 'custom':
        $startDate = !empty($_GET['start_date']) ? sanitizeString($_GET['start_date']) : date('Y-m-01');
        $endDate = !empty($_GET['end_date']) ? sanitizeString($_GET['end_date']) : $today;
        break;
    case 'this_month':
    default:
        $startDate = date('Y-m-01');
        $endDate = $today;
        break;
}

$report = AccountingOperation::getProfitAndLossReport($startDate, $endDate);
$rev = $report['revenues'];
$cogs = $report['cogs'];
$exp = $report['expenses'];

$pageTitle = 'Profit & Loss Statement - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Profit & Loss';
$activePage = 'accounting';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Header Title & Filter Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="accounting_dashboard.php" class="text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-[22px]">arrow_back</span>
                </a>
                <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[28px]">trending_up</span>
                    Profit &amp; Loss Statement
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                Period: <strong class="text-on-surface"><?php echo date('M d, Y', strtotime($startDate)); ?></strong> to <strong class="text-on-surface"><?php echo date('M d, Y', strtotime($endDate)); ?></strong>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Report
            </button>
        </div>
    </div>

    <!-- Period Quick Selector -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-4 mb-6 shadow-xs flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-1.5 text-xs font-semibold">
            <a href="profit_and_loss.php?filter=today" class="px-3 py-1.5 rounded-lg <?php echo $filter === 'today' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> transition-colors">
                Today
            </a>
            <a href="profit_and_loss.php?filter=this_month" class="px-3 py-1.5 rounded-lg <?php echo $filter === 'this_month' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> transition-colors">
                This Month
            </a>
            <a href="profit_and_loss.php?filter=this_quarter" class="px-3 py-1.5 rounded-lg <?php echo $filter === 'this_quarter' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> transition-colors">
                This Quarter
            </a>
            <a href="profit_and_loss.php?filter=this_year" class="px-3 py-1.5 rounded-lg <?php echo $filter === 'this_year' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> transition-colors">
                Year to Date
            </a>
        </div>

        <!-- Custom Date Range Form -->
        <form method="GET" action="profit_and_loss.php" class="flex flex-wrap items-center gap-2 text-xs">
            <input type="hidden" name="filter" value="custom">
            <span class="text-on-surface-variant font-medium">Custom:</span>
            <input type="date" name="start_date" value="<?php echo e($startDate); ?>" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
            <span class="text-on-surface-variant">to</span>
            <input type="date" name="end_date" value="<?php echo e($endDate); ?>" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
            <button type="submit" class="px-3 py-1.5 bg-primary text-on-primary rounded-lg font-bold hover:bg-primary-container transition-colors cursor-pointer">
                Apply
            </button>
        </form>
    </div>

    <!-- Income Statement Sheet Container (Print Ready) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-6 sm:p-8 shadow-sm max-w-4xl mx-auto space-y-6">
        <!-- Hospital Branding Header -->
        <div class="text-center border-b border-outline-variant pb-6">
            <h1 class="text-xl sm:text-2xl font-bold text-primary font-headline-md"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h1>
            <p class="text-xs sm:text-sm font-semibold text-on-surface mt-0.5">STATEMENT OF PROFIT AND LOSS</p>
            <p class="text-xs text-on-surface-variant mt-1">For the period: <?php echo date('F d, Y', strtotime($startDate)); ?> — <?php echo date('F d, Y', strtotime($endDate)); ?></p>
            <p class="text-[11px] text-on-surface-variant font-mono mt-0.5">Currency: USD ($)</p>
        </div>

        <!-- Statement Table -->
        <div class="space-y-6 text-xs sm:text-sm">
            <!-- 1. OPERATING REVENUES -->
            <div>
                <div class="bg-surface-container-low p-2.5 rounded-lg font-bold text-on-surface uppercase tracking-wider text-[11px] flex justify-between">
                    <span>1. Operating Revenues</span>
                    <span>Amount (USD)</span>
                </div>
                <div class="divide-y divide-outline-variant/50 mt-1">
                    <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                        <span class="text-on-surface-variant font-medium">4010 - Pharmacy Sales Revenue</span>
                        <span class="font-mono text-on-surface">$<?php echo number_format((float)$rev['pharmacy_sales'], 2); ?></span>
                    </div>
                    <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                        <span class="text-on-surface-variant font-medium">4020 - Consultation Fees</span>
                        <span class="font-mono text-on-surface">$<?php echo number_format((float)$rev['consultation_fees'], 2); ?></span>
                    </div>
                    <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                        <span class="text-on-surface-variant font-medium">4030 - Laboratory &amp; Diagnostic Fees</span>
                        <span class="font-mono text-on-surface">$<?php echo number_format((float)$rev['laboratory_fees'], 2); ?></span>
                    </div>
                    <?php if (!empty($rev['other_income']) && (float)$rev['other_income'] > 0): ?>
                    <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                        <span class="text-on-surface-variant font-medium">4090 - Other Operating Income</span>
                        <span class="font-mono text-on-surface font-semibold">$<?php echo number_format((float)$rev['other_income'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="py-2 px-3 bg-primary-fixed/20 rounded-lg flex justify-between font-bold text-on-surface mt-1 border-t border-primary/20">
                    <span>Total Operating Revenue</span>
                    <span class="font-mono text-primary text-sm font-bold">$<?php echo number_format((float)$rev['total_revenue'], 2); ?></span>
                </div>
            </div>

            <!-- 2. COST OF GOODS SOLD (COGS) -->
            <div>
                <div class="bg-surface-container-low p-2.5 rounded-lg font-bold text-on-surface uppercase tracking-wider text-[11px] flex justify-between">
                    <span>2. Cost of Goods Sold (COGS)</span>
                    <span>Amount (USD)</span>
                </div>
                <div class="divide-y divide-outline-variant/50 mt-1">
                    <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                        <span class="text-on-surface-variant font-medium">5010 - Cost of Dispensed Medications</span>
                        <span class="font-mono text-amber-600">$<?php echo number_format((float)$cogs['dispensed_medications'], 2); ?></span>
                    </div>
                </div>
                <div class="py-2 px-3 bg-amber-500/10 rounded-lg flex justify-between font-bold text-on-surface mt-1 border-t border-amber-500/20">
                    <span>Total Cost of Goods Sold</span>
                    <span class="font-mono text-amber-600 font-bold">$<?php echo number_format((float)$cogs['total_cogs'], 2); ?></span>
                </div>
            </div>

            <!-- GROSS PROFIT -->
            <div class="p-3 bg-surface-container rounded-xl flex justify-between items-center font-bold text-sm border border-outline-variant">
                <span class="text-on-surface">GROSS PROFIT</span>
                <span class="font-mono text-primary font-bold text-base">$<?php echo number_format((float)$report['gross_profit'], 2); ?></span>
            </div>

            <!-- 3. OPERATING EXPENSES -->
            <div>
                <div class="bg-surface-container-low p-2.5 rounded-lg font-bold text-on-surface uppercase tracking-wider text-[11px] flex justify-between">
                    <span>3. Operating Expenses</span>
                    <span>Amount (USD)</span>
                </div>
                <div class="divide-y divide-outline-variant/50 mt-1">
                    <?php if (empty($exp['breakdown'])): ?>
                        <div class="py-3 px-3 text-on-surface-variant text-center italic">
                            No operating expenses recorded for this date period.
                        </div>
                    <?php else: ?>
                        <?php foreach ($exp['breakdown'] as $eb): ?>
                            <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest transition-colors">
                                <span class="text-on-surface-variant font-medium">
                                    <strong class="font-mono"><?php echo e($eb['account_code']); ?></strong> - <?php echo e($eb['account_name']); ?>
                                </span>
                                <span class="font-mono text-error font-semibold">$<?php echo number_format((float)$eb['total_amount'], 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="py-2 px-3 bg-error-container/30 rounded-lg flex justify-between font-bold text-on-surface mt-1 border-t border-error/20">
                    <span>Total Operating Expenses</span>
                    <span class="font-mono text-error text-sm font-bold">$<?php echo number_format((float)$exp['total_expenses'], 2); ?></span>
                </div>
            </div>

            <!-- 4. NET PROFIT / LOSS -->
            <div class="p-4 rounded-xl <?php echo $report['net_profit'] >= 0 ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container'; ?> flex justify-between items-center font-bold text-base sm:text-lg shadow-sm border border-outline-variant">
                <div>
                    <span class="block">NET OPERATING PROFIT / (LOSS)</span>
                </div>
                <span class="font-mono text-xl sm:text-2xl font-bold">
                    $<?php echo number_format((float)$report['net_profit'], 2); ?>
                </span>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../components/footer.php'; ?>
