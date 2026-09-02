<?php
/**
 * MedCore Systems - Balance Sheet (Statement of Financial Position)
 * Summarizes hospital Assets, Liabilities, and Equity as of a specific date.
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

$asOfDate = !empty($_GET['as_of_date']) ? sanitizeString($_GET['as_of_date']) : date('Y-m-d');
$bs = AccountingOperation::getBalanceSheetReport($asOfDate);
$assets = $bs['assets'];
$liab = $bs['liabilities'];
$equity = $bs['equity'];

$pageTitle = 'Balance Sheet - MedCore Systems';
$headerTitle = 'MedCore Management - Balance Sheet';
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
                    <span class="material-symbols-outlined text-secondary text-[28px]">balance</span>
                    Statement of Financial Position (Balance Sheet)
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                As of <strong class="text-on-surface"><?php echo date('F d, Y', strtotime($asOfDate)); ?></strong>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="balance_sheet.php" class="flex items-center gap-2 text-xs">
                <span class="text-on-surface-variant font-medium">As of Date:</span>
                <input type="date" name="as_of_date" value="<?php echo e($asOfDate); ?>" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
                <button type="submit" class="px-3 py-1.5 bg-primary text-on-primary rounded-lg font-bold hover:bg-primary-container transition-colors cursor-pointer">
                    Apply
                </button>
            </form>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print
            </button>
        </div>
    </div>

    <!-- Balance Sheet Container (Print Ready) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-6 sm:p-8 shadow-sm max-w-4xl mx-auto space-y-6">
        <!-- Hospital Branding Header -->
        <div class="text-center border-b border-outline-variant pb-6">
            <h1 class="text-xl sm:text-2xl font-bold text-primary font-headline-md">MedCore Healthcare Systems</h1>
            <p class="text-xs sm:text-sm font-semibold text-on-surface mt-0.5">STATEMENT OF FINANCIAL POSITION (BALANCE SHEET)</p>
            <p class="text-xs text-on-surface-variant mt-1">As of: <?php echo date('F d, Y', strtotime($asOfDate)); ?></p>
            <p class="text-[11px] text-on-surface-variant font-mono mt-0.5">Accounting Equation: Assets = Liabilities + Equity</p>
        </div>

        <!-- 2 Column Layout: Assets (Left) vs Liabilities & Equity (Right) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs sm:text-sm">
            <!-- LEFT COLUMN: ASSETS -->
            <div class="space-y-4">
                <div class="bg-surface-container-low p-2.5 rounded-lg font-bold text-on-surface uppercase tracking-wider text-[11px] flex justify-between">
                    <span>1. ASSETS (Hantida Isbitaalka)</span>
                    <span>USD ($)</span>
                </div>

                <!-- Current Assets -->
                <div>
                    <h4 class="font-bold text-xs text-primary px-2 mb-1">A. Current Assets</h4>
                    <div class="divide-y divide-outline-variant/50">
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant font-medium">1010 - Cash on Hand (Khasnadda)</span>
                            <span class="font-mono text-on-surface font-semibold">$<?php echo number_format((float)$assets['cash_on_hand'], 2); ?></span>
                        </div>
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant font-medium">1020 - Mobile Money (EVC / Zaad)</span>
                            <span class="font-mono text-on-surface font-semibold">$<?php echo number_format((float)$assets['mobile_money'], 2); ?></span>
                        </div>
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant font-medium">1030 - Bank Account (Commercial Banks)</span>
                            <span class="font-mono text-on-surface font-semibold">$<?php echo number_format((float)($assets['bank_account'] ?? 0), 2); ?></span>
                        </div>
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant font-medium">1100 - Accounts Receivable (Patient Debts)</span>
                            <span class="font-mono text-amber-600 font-semibold">$<?php echo number_format((float)$assets['accounts_receivable'], 2); ?></span>
                        </div>
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant font-medium">1200 - Pharmacy Inventory Asset Value</span>
                            <span class="font-mono text-on-surface font-semibold">$<?php echo number_format((float)$assets['pharmacy_inventory'], 2); ?></span>
                        </div>
                    </div>
                    <div class="py-1.5 px-3 bg-surface-container rounded-lg flex justify-between font-semibold text-xs text-on-surface mt-1">
                        <span>Total Current Assets</span>
                        <span class="font-mono">$<?php echo number_format((float)$assets['total_current_assets'], 2); ?></span>
                    </div>
                </div>

                <!-- Fixed Assets -->
                <div>
                    <h4 class="font-bold text-xs text-primary px-2 mb-1">B. Non-Current / Fixed Assets</h4>
                    <div class="divide-y divide-outline-variant/50">
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant">1500 - Medical Equipment &amp; Machinery</span>
                            <span class="font-mono text-on-surface">$<?php echo number_format((float)$assets['medical_equipment'], 2); ?></span>
                        </div>
                    </div>
                    <div class="py-1.5 px-3 bg-surface-container rounded-lg flex justify-between font-semibold text-xs text-on-surface mt-1">
                        <span>Total Fixed Assets</span>
                        <span class="font-mono">$<?php echo number_format((float)$assets['total_fixed_assets'], 2); ?></span>
                    </div>
                </div>

                <!-- TOTAL ASSETS -->
                <div class="p-3 bg-primary-fixed/30 rounded-xl flex justify-between items-center font-bold text-sm border border-primary/30 mt-4">
                    <span class="text-on-surface uppercase">TOTAL ASSETS</span>
                    <span class="font-mono text-primary font-bold text-base">$<?php echo number_format((float)$assets['total_assets'], 2); ?></span>
                </div>
            </div>

            <!-- RIGHT COLUMN: LIABILITIES & EQUITY -->
            <div class="space-y-4">
                <div class="bg-surface-container-low p-2.5 rounded-lg font-bold text-on-surface uppercase tracking-wider text-[11px] flex justify-between">
                    <span>2. LIABILITIES &amp; EQUITY</span>
                    <span>USD ($)</span>
                </div>

                <!-- Liabilities -->
                <div>
                    <h4 class="font-bold text-xs text-error px-2 mb-1">A. Current Liabilities (Deymaha)</h4>
                    <div class="divide-y divide-outline-variant/50">
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant">2010 - Accounts Payable (Vendor/Supplier Debts)</span>
                            <span class="font-mono text-error font-semibold">$<?php echo number_format((float)$liab['accounts_payable'], 2); ?></span>
                        </div>
                    </div>
                    <div class="py-1.5 px-3 bg-error-container/30 rounded-lg flex justify-between font-semibold text-xs text-on-surface mt-1">
                        <span>Total Liabilities</span>
                        <span class="font-mono text-error font-bold">$<?php echo number_format((float)$liab['total_liabilities'], 2); ?></span>
                    </div>
                </div>

                <!-- Equity -->
                <div>
                    <h4 class="font-bold text-xs text-secondary px-2 mb-1">B. Hospital Equity (Raasumaalka)</h4>
                    <div class="divide-y divide-outline-variant/50">
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant">3010 - Owner's Paid-in Capital</span>
                            <span class="font-mono text-on-surface">$<?php echo number_format((float)$equity['owners_capital'], 2); ?></span>
                        </div>
                        <div class="py-2 px-3 flex justify-between hover:bg-surface-container-lowest">
                            <span class="text-on-surface-variant">3020 - Retained Earnings (YTD Net Income)</span>
                            <span class="font-mono <?php echo $equity['current_year_earnings'] >= 0 ? 'text-secondary' : 'text-error'; ?> font-semibold">
                                $<?php echo number_format((float)$equity['current_year_earnings'], 2); ?>
                            </span>
                        </div>
                    </div>
                    <div class="py-1.5 px-3 bg-surface-container rounded-lg flex justify-between font-semibold text-xs text-on-surface mt-1">
                        <span>Total Equity</span>
                        <span class="font-mono font-bold">$<?php echo number_format((float)$equity['total_equity'], 2); ?></span>
                    </div>
                </div>

                <!-- TOTAL LIABILITIES & EQUITY -->
                <div class="p-3 bg-secondary-fixed/30 rounded-xl flex justify-between items-center font-bold text-sm border border-secondary/30 mt-4">
                    <span class="text-on-surface uppercase">TOTAL LIABILITIES &amp; EQUITY</span>
                    <span class="font-mono text-secondary font-bold text-base">$<?php echo number_format((float)($liab['total_liabilities'] + $equity['total_equity']), 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Balance Reconciliation Badge -->
        <div class="p-3 rounded-xl <?php echo $bs['is_balanced'] ? 'bg-secondary-fixed/40 border border-secondary/40 text-on-secondary-fixed-variant' : 'bg-error-container text-on-error-container'; ?> flex items-center justify-between text-xs font-semibold">
            <span class="flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px]"><?php echo $bs['is_balanced'] ? 'verified' : 'warning'; ?></span>
                <?php if ($bs['is_balanced']): ?>
                    <strong>Balanced Ledger:</strong> Total Assets ($<?php echo number_format((float)$assets['total_assets'], 2); ?>) equals Total Liabilities &amp; Equity ($<?php echo number_format((float)($liab['total_liabilities'] + $equity['total_equity']), 2); ?>).
                <?php else: ?>
                    <strong>Unbalanced Ledger:</strong> Difference detected between Assets and Liabilities + Equity.
                <?php endif; ?>
            </span>
            <span class="font-mono uppercase font-bold text-[11px]">GAAP Compliant</span>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../components/footer.php'; ?>
