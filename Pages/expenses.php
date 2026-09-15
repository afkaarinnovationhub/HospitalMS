<?php
/**
 * MedCore Systems - Hospital Operating Expenses Tracker
 * Comprehensive logging, category breakdown, and general ledger synchronization for clinical expenses.
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

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Record Expense POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_expense') {
        $result = AccountingController::handleRecordExpense($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Filters
$startDate = !empty($_GET['start_date']) ? sanitizeString($_GET['start_date']) : date('Y-m-01');
$endDate   = !empty($_GET['end_date']) ? sanitizeString($_GET['end_date']) : date('Y-m-d');
$accFilter = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;

$expenses = AccountingOperation::getExpenses($startDate, $endDate, $accFilter);
$expenseAccounts = AccountingOperation::getAllAccounts('expense');

$totalExpenseAmount = 0.0;
foreach ($expenses as $e) {
    $totalExpenseAmount += (float)$e['amount'];
}

$pageTitle = 'Operating Expenses - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Expenses';
$activePage = 'expenses';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Expense Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Expense Recorded</p>
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
                    <span class="material-symbols-outlined text-primary text-[28px]">payments</span>
                    Operating Expenses
                </h2>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openExpenseModal()" class="px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                + New Expense
            </button>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-4 mb-6 shadow-xs">
        <form method="GET" action="expenses.php" class="flex flex-wrap items-center gap-3 text-xs">
            <div>
                <label class="block text-[11px] text-on-surface-variant font-semibold mb-0.5">Start Date:</label>
                <input type="date" name="start_date" value="<?php echo e($startDate); ?>" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
            </div>
            <div>
                <label class="block text-[11px] text-on-surface-variant font-semibold mb-0.5">End Date:</label>
                <input type="date" name="end_date" value="<?php echo e($endDate); ?>" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
            </div>
            <div>
                <label class="block text-[11px] text-on-surface-variant font-semibold mb-0.5">Expense Category:</label>
                <select name="account_id" class="bg-surface-container-low border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface">
                    <option value="">All Categories</option>
                    <?php foreach ($expenseAccounts as $ea): ?>
                        <option value="<?php echo (int)$ea['id']; ?>" <?php echo $accFilter === (int)$ea['id'] ? 'selected' : ''; ?>>
                            [<?php echo e($ea['account_code']); ?>] <?php echo e($ea['account_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pt-4">
                <button type="submit" class="px-4 py-1.5 bg-primary text-on-primary rounded-lg font-bold hover:bg-primary-container transition-colors cursor-pointer">
                    Filter Expenses
                </button>
                <a href="expenses.php" class="px-3 py-1.5 bg-surface-container text-on-surface rounded-lg font-medium hover:bg-surface-container-high transition-colors ml-1">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Expenses Table Card -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">receipt_long</span>
                    Disbursements Ledger (<?php echo count($expenses); ?> records)
                </h3>
                <p class="text-[11px] text-on-surface-variant">From <?php echo date('M d, Y', strtotime($startDate)); ?> to <?php echo date('M d, Y', strtotime($endDate)); ?></p>
            </div>
            <div class="font-bold text-xs text-on-surface bg-error-container/30 px-3 py-1.5 rounded-xl border border-error/20">
                Period Total: <strong class="text-error font-mono text-sm">$<?php echo number_format($totalExpenseAmount, 2); ?></strong>
            </div>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Expense #</th>
                        <th class="py-3 px-3">Date</th>
                        <th class="py-3 px-3">Account Category</th>
                        <th class="py-3 px-3">Payee</th>
                        <th class="py-3 px-3">Description</th>
                        <th class="py-3 px-3">Method</th>
                        <th class="py-3 px-3">Recorded By</th>
                        <th class="py-3 px-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[32px] text-on-surface-variant/40 block mb-1">receipt_long</span>
                                No operating expenses found matching the selected filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $exp): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-mono font-bold text-primary"><?php echo e($exp['expense_number']); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant"><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                                <td class="py-3 px-3 font-semibold text-on-surface">
                                    <span class="font-mono text-[10px] text-on-surface-variant">[<?php echo e($exp['account_code']); ?>]</span> <?php echo e($exp['account_name']); ?>
                                </td>
                                <td class="py-3 px-3 font-medium text-on-surface"><?php echo e($exp['payee']); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant truncate max-w-xs"><?php echo e($exp['description']); ?></td>
                                <td class="py-3 px-3">
                                    <span class="bg-surface-container text-on-surface text-[10px] uppercase font-bold px-2 py-0.5 rounded-full">
                                        <?php echo e($exp['payment_method']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant"><?php echo e($exp['recorder_name'] ?: 'Finance Dept'); ?></td>
                                <td class="py-3 px-3 text-right font-mono font-bold text-error text-sm">
                                    $<?php echo number_format((float)$exp['amount'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-outline-variant font-bold text-xs bg-surface-container">
                        <td colspan="7" class="py-2.5 px-3 uppercase text-on-surface">TOTAL EXPENSES</td>
                        <td class="py-2.5 px-3 text-right font-mono text-error text-sm font-bold">
                            $<?php echo number_format($totalExpenseAmount, 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</main>

<!-- MODAL: Record Operating Expense -->
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

        <form method="POST" action="expenses.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_expense">
            <input type="hidden" name="redirect" value="expenses.php">

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
                    <input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface font-bold focus:border-primary outline-none text-base">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Disbursement Method *</label>
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
                    <input name="payee" type="text" required placeholder="e.g. Landlord, Electric Company, Supplier" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Expense Date *</label>
                    <input name="expense_date" type="date" required value="<?php echo date('Y-m-d'); ?>" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description &amp; Purpose</label>
                <input name="description" type="text" placeholder="e.g. Electricity bill for main clinical ward" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
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

<script>
    function openExpenseModal() {
        document.getElementById('record-expense-modal').classList.remove('hidden');
    }
    function closeExpenseModal() {
        document.getElementById('record-expense-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
