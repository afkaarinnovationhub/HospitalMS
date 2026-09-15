<?php
/**
 * MedCore Systems - General Ledger Trial Balance
 * Displays all accounts with Debit and Credit balances to ensure ledger equilibrium.
 * Includes support for manual balanced general journal adjustments.
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

// Handle Manual Journal Post
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'record_journal') {
        $result = AccountingController::handleRecordManualJournal($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

$asOfDate = !empty($_GET['as_of_date']) ? sanitizeString($_GET['as_of_date']) : date('Y-m-d');
$tb = AccountingOperation::getTrialBalanceReport($asOfDate);
$allAccounts = AccountingOperation::getAllAccounts();

$pageTitle = 'Trial Balance - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Trial Balance';
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
                <p class="font-bold">Journal Post Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Journal Entry Posted</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Title & Filter Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="accounting_dashboard.php" class="text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-[22px]">arrow_back</span>
                </a>
                <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[28px]">account_tree</span>
                    Trial Balance
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                As of <strong class="text-on-surface"><?php echo date('M d, Y', strtotime($asOfDate)); ?></strong>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openJournalModal()" class="px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">edit_document</span>
                + Post Journal
            </button>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print
            </button>
        </div>
    </div>

    <!-- Trial Balance Table Card (Print Ready) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-6 sm:p-8 shadow-sm max-w-4xl mx-auto space-y-6">
        <!-- Hospital Branding Header -->
        <div class="text-center border-b border-outline-variant pb-4">
            <h1 class="text-xl sm:text-2xl font-bold text-primary font-headline-md"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h1>
            <p class="text-xs sm:text-sm font-semibold text-on-surface mt-0.5">GENERAL LEDGER TRIAL BALANCE</p>
            <p class="text-xs text-on-surface-variant mt-0.5">As of: <?php echo date('F d, Y', strtotime($asOfDate)); ?></p>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="border-b-2 border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Account Code</th>
                        <th class="py-3 px-3">Account Name</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3 text-right">Debit ($ USD)</th>
                        <th class="py-3 px-3 text-right">Credit ($ USD)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($tb['rows'])): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-on-surface-variant">
                                No active account balances found in the ledger.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tb['rows'] as $r): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-2.5 px-3 font-mono font-bold text-primary"><?php echo e($r['account_code']); ?></td>
                                <td class="py-2.5 px-3 font-semibold text-on-surface"><?php echo e($r['account_name']); ?></td>
                                <td class="py-2.5 px-3 text-on-surface-variant capitalize text-xs"><?php echo e($r['account_type']); ?></td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold <?php echo $r['debit'] > 0 ? 'text-on-surface' : 'text-on-surface-variant/40'; ?>">
                                    <?php echo $r['debit'] > 0 ? '$' . number_format((float)$r['debit'], 2) : '-'; ?>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold <?php echo $r['credit'] > 0 ? 'text-on-surface' : 'text-on-surface-variant/40'; ?>">
                                    <?php echo $r['credit'] > 0 ? '$' . number_format((float)$r['credit'], 2) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-outline font-bold text-sm bg-surface-container">
                        <td colspan="3" class="py-3 px-3 uppercase text-on-surface">TOTALS</td>
                        <td class="py-3 px-3 text-right font-mono text-primary text-base font-bold">
                            $<?php echo number_format((float)$tb['total_debits'], 2); ?>
                        </td>
                        <td class="py-3 px-3 text-right font-mono text-primary text-base font-bold">
                            $<?php echo number_format((float)$tb['total_credits'], 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Ledger Reconciliation Status -->
        <div class="p-3.5 rounded-xl <?php echo $tb['is_balanced'] ? 'bg-secondary-fixed/40 border border-secondary/40 text-on-secondary-fixed-variant' : 'bg-error-container text-on-error-container'; ?> flex items-center justify-between text-xs font-semibold">
            <span class="flex items-center gap-2">
                <span class="material-symbols-outlined text-[20px]"><?php echo $tb['is_balanced'] ? 'verified' : 'error'; ?></span>
                <?php if ($tb['is_balanced']): ?>
                    <strong>Trial Balance is in Perfect Equilibrium:</strong> Total Debits ($<?php echo number_format((float)$tb['total_debits'], 2); ?>) equals Total Credits ($<?php echo number_format((float)$tb['total_credits'], 2); ?>).
                <?php else: ?>
                    <strong>Trial Balance Discrepancy:</strong> Difference of $<?php echo number_format((float)$tb['difference'], 2); ?> between Debits and Credits.
                <?php endif; ?>
            </span>
            <span class="font-mono text-xs font-bold uppercase">Difference: $<?php echo number_format((float)$tb['difference'], 2); ?></span>
        </div>
    </div>
</main>

<!-- MODAL: Post Manual Double-Entry General Journal -->
<div id="journal-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-2xl w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit_document</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Post General Journal Entry</h3>
                    <p class="text-xs text-on-surface-variant">Manual balanced double-entry transaction (Debits = Credits).</p>
                </div>
            </div>
            <button type="button" onclick="closeJournalModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="trial_balance.php" class="space-y-4" onsubmit="return validateJournalBalance()">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="record_journal">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Entry Date *</label>
                    <input name="entry_date" type="date" required value="<?php echo date('Y-m-d'); ?>" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description / Memo *</label>
                    <input name="description" type="text" required placeholder="e.g. Month-end depreciation adjustment" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <!-- Line Items Container -->
            <div class="space-y-2">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-on-surface">Journal Lines (Debits &amp; Credits)</span>
                    <button type="button" onclick="addJournalLine()" class="text-xs text-primary font-bold hover:underline flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-[16px]">add_circle</span> Add Line
                    </button>
                </div>

                <div id="journal-lines-container" class="space-y-2">
                    <!-- Line 1: Debit Line -->
                    <div class="journal-row grid grid-cols-12 gap-2 p-2 bg-surface-container-low rounded-xl border border-outline-variant items-center">
                        <div class="col-span-6">
                            <select name="account_ids[]" required class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs text-on-surface" onchange="calculateJournalTotals()">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($allAccounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>">
                                        [<?php echo e($acc['account_code']); ?>] <?php echo e($acc['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-3">
                            <input name="debits[]" type="number" step="0.01" min="0" placeholder="Debit ($)" value="" class="debit-input w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-mono font-bold text-on-surface" oninput="calculateJournalTotals()">
                        </div>
                        <div class="col-span-3">
                            <input name="credits[]" type="number" step="0.01" min="0" placeholder="Credit ($)" value="" class="credit-input w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-mono font-bold text-on-surface" oninput="calculateJournalTotals()">
                        </div>
                    </div>

                    <!-- Line 2: Credit Line -->
                    <div class="journal-row grid grid-cols-12 gap-2 p-2 bg-surface-container-low rounded-xl border border-outline-variant items-center">
                        <div class="col-span-6">
                            <select name="account_ids[]" required class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs text-on-surface" onchange="calculateJournalTotals()">
                                <option value="">-- Select Account --</option>
                                <?php foreach ($allAccounts as $acc): ?>
                                    <option value="<?php echo (int)$acc['id']; ?>">
                                        [<?php echo e($acc['account_code']); ?>] <?php echo e($acc['account_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-span-3">
                            <input name="debits[]" type="number" step="0.01" min="0" placeholder="Debit ($)" value="" class="debit-input w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-mono font-bold text-on-surface" oninput="calculateJournalTotals()">
                        </div>
                        <div class="col-span-3">
                            <input name="credits[]" type="number" step="0.01" min="0" placeholder="Credit ($)" value="" class="credit-input w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-mono font-bold text-on-surface" oninput="calculateJournalTotals()">
                        </div>
                    </div>
                </div>

                <!-- Totals & Difference Bar -->
                <div class="p-3 bg-surface-container rounded-xl border border-outline-variant flex justify-between items-center text-xs font-mono font-bold">
                    <span>Totals:</span>
                    <div class="flex gap-4">
                        <span>Debits: $<span id="modal-total-debit">0.00</span></span>
                        <span>Credits: $<span id="modal-total-credit">0.00</span></span>
                        <span id="modal-balance-status" class="text-error">Out of Balance</span>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeJournalModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" id="submit-journal-btn" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer">
                    Post Journal Entry
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openJournalModal() {
        document.getElementById('journal-modal').classList.remove('hidden');
    }
    function closeJournalModal() {
        document.getElementById('journal-modal').classList.add('hidden');
    }

    function calculateJournalTotals() {
        let debits = 0;
        let credits = 0;
        document.querySelectorAll('.debit-input').forEach(inp => {
            const v = parseFloat(inp.value);
            if (!isNaN(v)) debits += v;
        });
        document.querySelectorAll('.credit-input').forEach(inp => {
            const v = parseFloat(inp.value);
            if (!isNaN(v)) credits += v;
        });

        document.getElementById('modal-total-debit').innerText = debits.toFixed(2);
        document.getElementById('modal-total-credit').innerText = credits.toFixed(2);

        const statusElem = document.getElementById('modal-balance-status');
        const diff = Math.abs(debits - credits);
        if (diff < 0.01 && debits > 0) {
            statusElem.innerText = 'Balanced ✓';
            statusElem.className = 'text-secondary';
        } else {
            statusElem.innerText = 'Diff: $' + diff.toFixed(2);
            statusElem.className = 'text-error';
        }
    }

    function validateJournalBalance() {
        const deb = parseFloat(document.getElementById('modal-total-debit').innerText) || 0;
        const cred = parseFloat(document.getElementById('modal-total-credit').innerText) || 0;
        if (deb <= 0 || cred <= 0 || Math.abs(deb - cred) > 0.01) {
            alert('Journal entry must be balanced! Debits must equal Credits and be greater than 0.');
            return false;
        }
        return true;
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
