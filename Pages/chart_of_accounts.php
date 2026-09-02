<?php
/**
 * MedCore Systems - Chart of Accounts (COA) Directory
 * Master financial account structure, dynamic custom account creation, and ledger classification.
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

AccountingOperation::seedChartOfAccountsIfEmpty();

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions (Create / Edit Account)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_account') {
        $result = AccountingController::handleCreateAccount($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_account') {
        $result = AccountingController::handleEditAccount($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Filter by Account Type
$typeFilter = sanitizeString($_GET['type'] ?? 'all');
$validTypes = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];
$typeParam = in_array($typeFilter, $validTypes, true) ? $typeFilter : null;

$accounts = AccountingOperation::getAllAccounts($typeParam);

$pageTitle = 'Chart of Accounts (COA) - MedCore Systems';
$headerTitle = 'MedCore Management - Chart of Accounts';
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
                <p class="font-bold">COA Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Account Saved</p>
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
                    <span class="material-symbols-outlined text-primary text-[28px]">list_alt</span>
                    Chart of Accounts (COA) Directory
                </h2>
            </div>
            <p class="font-body-sm text-xs sm:text-sm text-on-surface-variant mt-0.5 ml-7">
                Structured ledger classification: Assets, Liabilities, Equity, Revenues, COGS, and Expenses.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openCreateAccountModal()" class="px-3.5 py-2 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">add_circle</span>
                Add Custom Account
            </button>
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print COA
            </button>
        </div>
    </div>

    <!-- Type Tabs Filter -->
    <div class="flex items-center gap-1 border-b border-outline-variant pb-2 mb-6 overflow-x-auto custom-scrollbar text-xs font-semibold">
        <a href="chart_of_accounts.php?type=all" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'all' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            All Accounts (<?php echo count(AccountingOperation::getAllAccounts()); ?>)
        </a>
        <a href="chart_of_accounts.php?type=asset" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'asset' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            Assets (1000s)
        </a>
        <a href="chart_of_accounts.php?type=liability" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'liability' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            Liabilities (2000s)
        </a>
        <a href="chart_of_accounts.php?type=equity" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'equity' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            Equity (3000s)
        </a>
        <a href="chart_of_accounts.php?type=revenue" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'revenue' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            Revenues (4000s)
        </a>
        <a href="chart_of_accounts.php?type=cogs" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'cogs' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            COGS (5000s)
        </a>
        <a href="chart_of_accounts.php?type=expense" class="px-3 py-1.5 rounded-lg <?php echo $typeFilter === 'expense' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0 transition-colors">
            Expenses (6000s)
        </a>
    </div>

    <!-- Chart of Accounts Table Card -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">account_tree</span>
                    Master Accounts Directory (<?php echo count($accounts); ?> active accounts)
                </h3>
                <p class="text-[11px] text-on-surface-variant">General ledger structure configured for hospital billing and operations.</p>
            </div>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-3">Account Code</th>
                        <th class="py-3 px-3">Account Name</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3">Category Classification</th>
                        <th class="py-3 px-3">Description</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                No accounts found in this category.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($accounts as $acc): ?>
                            <?php 
                                $typeBadge = match ($acc['account_type']) {
                                    'asset'     => 'bg-primary-fixed text-on-primary-fixed',
                                    'liability' => 'bg-error-container text-on-error-container',
                                    'equity'    => 'bg-secondary-fixed text-on-secondary-fixed',
                                    'revenue'   => 'bg-tertiary-fixed text-on-tertiary-fixed',
                                    'cogs'      => 'bg-amber-500/20 text-amber-700',
                                    default     => 'bg-surface-container text-on-surface',
                                };
                            ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="py-3 px-3 font-mono font-bold text-primary text-sm"><?php echo e($acc['account_code']); ?></td>
                                <td class="py-3 px-3 font-bold text-on-surface"><?php echo e($acc['account_name']); ?></td>
                                <td class="py-3 px-3">
                                    <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full <?php echo $typeBadge; ?>">
                                        <?php echo e($acc['account_type']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-on-surface-variant capitalize"><?php echo str_replace('_', ' ', e($acc['category'])); ?></td>
                                <td class="py-3 px-3 text-on-surface-variant truncate max-w-xs"><?php echo e($acc['description'] ?: '-'); ?></td>
                                <td class="py-3 px-3">
                                    <span class="inline-flex items-center gap-1 text-[10px] text-secondary font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Active
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <button type="button" 
                                            onclick="openEditAccountModal(<?php echo (int)$acc['id']; ?>, '<?php echo e($acc['account_code']); ?>', '<?php echo e(addslashes($acc['account_name'])); ?>', '<?php echo e($acc['account_type']); ?>', '<?php echo e($acc['category']); ?>', '<?php echo e(addslashes($acc['description'] ?? '')); ?>')" 
                                            class="p-1 text-on-surface-variant hover:text-primary hover:bg-surface-container rounded transition-colors cursor-pointer" 
                                            title="Edit Account Details">
                                        <span class="material-symbols-outlined text-[17px]">edit</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL 1: Create Custom Account -->
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

        <form method="POST" action="chart_of_accounts.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="redirect" value="chart_of_accounts.php">

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
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category Classification</label>
                <input name="category" type="text" placeholder="e.g. operating_expense" value="operating_expense" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description</label>
                <textarea name="description" rows="2" placeholder="Brief explanation of this account's purpose..." class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none"></textarea>
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

<!-- MODAL 2: Edit Existing Account -->
<div id="edit-account-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit_note</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Account</h3>
                    <p class="text-xs text-on-surface-variant">Update Chart of Accounts details.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditAccountModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="chart_of_accounts.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_account">
            <input type="hidden" name="account_id" id="edit-modal-id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Code</label>
                    <input id="edit-modal-code" type="text" disabled class="w-full bg-surface-container border border-outline-variant rounded-lg p-2 text-xs font-mono font-bold text-on-surface-variant">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Type *</label>
                    <select name="account_type" id="edit-modal-type" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="expense">Expense</option>
                        <option value="revenue">Revenue</option>
                        <option value="asset">Asset</option>
                        <option value="liability">Liability</option>
                        <option value="equity">Equity</option>
                        <option value="cogs">COGS</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Name *</label>
                <input name="account_name" id="edit-modal-name" type="text" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Category Classification</label>
                <input name="category" id="edit-modal-category" type="text" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Description</label>
                <textarea name="description" id="edit-modal-desc" rows="2" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none resize-none"></textarea>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeEditAccountModal()" class="px-3 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer">
                    Update Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateAccountModal() {
        document.getElementById('create-account-modal').classList.remove('hidden');
    }
    function closeCreateAccountModal() {
        document.getElementById('create-account-modal').classList.add('hidden');
    }
    function openEditAccountModal(id, code, name, type, category, desc) {
        document.getElementById('edit-modal-id').value = id;
        document.getElementById('edit-modal-code').value = code;
        document.getElementById('edit-modal-name').value = name;
        document.getElementById('edit-modal-type').value = type;
        document.getElementById('edit-modal-category').value = category;
        document.getElementById('edit-modal-desc').value = desc;
        document.getElementById('edit-account-modal').classList.remove('hidden');
    }
    function closeEditAccountModal() {
        document.getElementById('edit-account-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
