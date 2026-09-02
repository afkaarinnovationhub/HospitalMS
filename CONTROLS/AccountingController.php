<?php
/**
 * MedCore Systems - Accounting & Financial Management Controller
 * Handles CSRF validation, authorization, and requests for COA, Expenses, and Ledger Journals.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';

class AccountingController
{
    /**
     * Handles creating a new account in Chart of Accounts.
     */
    public static function handleCreateAccount(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        try {
            $code = sanitizeString($post['account_code'] ?? '');
            $name = sanitizeString($post['account_name'] ?? '');
            $type = sanitizeString($post['account_type'] ?? 'expense');
            $category = sanitizeString($post['category'] ?? 'operating_expense');
            $desc = sanitizeString($post['description'] ?? '');

            $accountId = AccountingOperation::createAccount([
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
                'category'     => $category,
                'description'  => $desc,
            ]);

            setFlashMessage('success', "Account [{$code} - {$name}] created successfully in Chart of Accounts.");
            safeRedirect('chart_of_accounts.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS COA CREATE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing an existing account in Chart of Accounts.
     */
    public static function handleEditAccount(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        try {
            $id = (int)($post['account_id'] ?? 0);
            $name = sanitizeString($post['account_name'] ?? '');
            $type = sanitizeString($post['account_type'] ?? 'expense');
            $category = sanitizeString($post['category'] ?? 'operating_expense');
            $desc = sanitizeString($post['description'] ?? '');
            $isActive = isset($post['is_active']) ? 1 : 0;

            if ($id <= 0) {
                return ['error' => 'Invalid account selected for editing.'];
            }

            AccountingOperation::updateAccount($id, [
                'account_name' => $name,
                'account_type' => $type,
                'category'     => $category,
                'description'  => $desc,
                'is_active'    => $isActive,
            ]);

            setFlashMessage('success', "Account [{$name}] updated successfully.");
            safeRedirect('chart_of_accounts.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS COA EDIT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles recording a new hospital operating expense.
     */
    public static function handleRecordExpense(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $accountId = (int)($post['account_id'] ?? 0);
            $amount = (float)($post['amount'] ?? 0);
            $paymentMethod = $post['payment_method'] ?? 'cash';
            $payee = sanitizeString($post['payee'] ?? '');
            $expenseDate = !empty($post['expense_date']) ? $post['expense_date'] : date('Y-m-d');
            $description = sanitizeString($post['description'] ?? '');
            $receiptRef = sanitizeString($post['receipt_ref'] ?? '');

            if ($accountId <= 0 || $amount <= 0 || empty($payee)) {
                return ['error' => 'Please provide a valid expense category, positive amount, and payee name.'];
            }

            $expenseId = AccountingOperation::recordExpense([
                'account_id'     => $accountId,
                'amount'         => $amount,
                'payment_method' => $paymentMethod,
                'payee'          => $payee,
                'expense_date'   => $expenseDate,
                'description'    => $description,
                'receipt_ref'    => $receiptRef,
            ], $userId);

            setFlashMessage('success', sprintf('Expense of $%.2f recorded successfully and posted to the general ledger.', $amount));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'expenses.php';
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS EXPENSE CONTROLLER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles recording a manual balanced double-entry journal entry.
     */
    public static function handleRecordManualJournal(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $entryDate = !empty($post['entry_date']) ? $post['entry_date'] : date('Y-m-d');
            $description = sanitizeString($post['description'] ?? 'Manual General Journal Entry');
            $accountIds = $post['account_ids'] ?? [];
            $debits = $post['debits'] ?? [];
            $credits = $post['credits'] ?? [];
            $memos = $post['memos'] ?? [];

            $items = [];
            foreach ($accountIds as $idx => $accId) {
                $accId = (int)$accId;
                $deb = (float)($debits[$idx] ?? 0);
                $cred = (float)($credits[$idx] ?? 0);
                $memo = sanitizeString($memos[$idx] ?? '');

                if ($accId > 0 && ($deb > 0 || $cred > 0)) {
                    $items[] = [
                        'account_id' => $accId,
                        'debit'      => $deb,
                        'credit'     => $cred,
                        'memo'       => $memo ?: $description,
                    ];
                }
            }

            if (count($items) < 2) {
                return ['error' => 'A journal entry requires at least two lines with valid debit and credit values.'];
            }

            $journalId = AccountingOperation::recordJournalEntry(
                $entryDate,
                'manual_journal',
                null,
                $description,
                $items,
                $userId
            );

            setFlashMessage('success', 'General Journal Entry posted successfully to the ledger.');
            safeRedirect('trial_balance.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS MANUAL JOURNAL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles recording founder/owner capital investment deposit.
     */
    public static function handleRecordCapitalInvestment(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $amount = (float)($post['amount'] ?? 0);
            $depositAccount = sanitizeString($post['deposit_account'] ?? '1010');
            $investorName = sanitizeString($post['investor_name'] ?? 'Founder / Shareholder');
            $notes = sanitizeString($post['notes'] ?? 'Initial capital investment');
            $depositDate = !empty($post['deposit_date']) ? $post['deposit_date'] : date('Y-m-d');

            if ($amount <= 0) {
                return ['error' => 'Please enter a valid investment amount greater than zero.'];
            }

            $journalId = AccountingOperation::recordCapitalInvestment(
                $amount,
                $depositAccount,
                $investorName,
                $notes,
                $depositDate,
                $userId
            );

            $accName = match($depositAccount) {
                '1020' => 'Mobile Money (EVC/Zaad)',
                '1030' => 'Bank Account',
                default => 'Cash Drawer (Khasnadda)',
            };

            setFlashMessage('success', "Capital Investment of \$" . number_format($amount, 2) . " credited to Owner's Capital & deposited into [{$accName}] successfully.");
            
            $redirect = !empty($post['redirect_to']) ? sanitizeString($post['redirect_to']) : 'accounting_dashboard.php';
            safeRedirect($redirect);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS CAPITAL INJECTION ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
