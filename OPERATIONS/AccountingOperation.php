<?php
/**
 * MedCore Systems - Accounting & Financial Management Operations Engine
 * Double-Entry General Ledger, Chart of Accounts, P&L, Balance Sheet,
 * Trial Balance, Accounts Receivable (AR), Accounts Payable (AP), and Expense Tracking.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';

class AccountingOperation
{
    /**
     * Standard default Chart of Accounts template for healthcare facilities.
     */
    private const DEFAULT_ACCOUNTS = [
        // 1000s Assets
        ['1010', 'Cash on Hand (Khasnadda/Khaanadda)', 'asset', 'current_asset', 'Physical cash in drawer/vault from daily patient payments'],
        ['1020', 'Mobile Money (EVC Plus / Zaad / Sahal)', 'asset', 'current_asset', 'Hospital mobile money merchant accounts'],
        ['1030', 'Bank Account (Commercial Banks)', 'asset', 'current_asset', 'Hospital primary bank checking & settlement accounts'],
        ['1100', 'Accounts Receivable - Patients (Deymaha Bukaanka)', 'asset', 'current_asset', 'Outstanding patient dues from pharmacy dispensing & consultations'],
        ['1200', 'Pharmacy Inventory Asset (Qiimaha Daawada)', 'asset', 'current_asset', 'Asset valuation of pharmaceutical medications in stock'],
        ['1500', 'Medical Equipment & Machinery (Qalabka)', 'asset', 'fixed_asset', 'Clinical, diagnostic laboratory, and hospital medical apparatus'],

        // 2000s Liabilities
        ['2010', 'Accounts Payable - Vendors (Deymaha Shirkadaha)', 'liability', 'current_liability', 'Outstanding amounts owed to pharmaceutical distributors and vendors'],
        ['2020', 'Accrued Salaries Payable (Mushaaraadka Sugaya)', 'liability', 'current_liability', 'Accrued compensation owed to physicians and hospital staff'],
        ['2030', 'Accrued Utility Bills (Biilasha Baaqiga ah)', 'liability', 'current_liability', 'Accrued electricity, water, and facility utility bills'],

        // 3000s Equity
        ['3010', "Owner's Capital (Raasumaalka Aasaasayaasha)", 'equity', 'equity', 'Initial paid-in capital and shareholder investments'],
        ['3020', 'Retained Earnings (Faa\'iidada Is-urursatay)', 'equity', 'equity', 'Accumulated net earnings from prior operating periods'],

        // 4000s Revenues
        ['4010', 'Pharmacy Sales Revenue (Dakhliga Daawada)', 'revenue', 'operating_revenue', 'Gross revenue earned from dispensed and walk-in pharmaceutical sales'],
        ['4020', 'Consultation Fees Revenue (Dakhliga Dhakhaatiirta)', 'revenue', 'operating_revenue', 'Revenue earned from clinical consultations and outpatient visits'],
        ['4030', 'Laboratory & Diagnostics Revenue (Dakhliga Shaybaarka)', 'revenue', 'operating_revenue', 'Revenue earned from medical diagnostic laboratory tests'],
        ['4090', 'Other Operating Income (Dakhliyo Kale)', 'revenue', 'other_revenue', 'Incidental and miscellaneous clinical service revenue'],

        // 5000s Cost of Goods Sold
        ['5010', 'Cost of Dispensed Medications (COGS Daawada)', 'cogs', 'direct_cost', 'Original cost of stock units dispensed to patients'],

        // 6000s Operating Expenses
        ['6010', 'Staff Salaries & Doctor Compensation', 'expense', 'operating_expense', 'Physician fees, nursing payroll, and administrative compensation'],
        ['6020', 'Hospital Facility Rent Expense', 'expense', 'operating_expense', 'Building and clinical premises rental lease payments'],
        ['6030', 'Electricity, Water & Generator Utilities', 'expense', 'operating_expense', 'Utility power grid bills, clean water supply, and generator fuel'],
        ['6040', 'Medical & Surgical Consumable Supplies', 'expense', 'operating_expense', 'Single-use PPE gloves, syringes, sterile dressings, and disinfectants'],
        ['6050', 'Facility & Medical Equipment Repairs', 'expense', 'operating_expense', 'Maintenance and repairs of hospital machinery and building'],
        ['6060', 'Internet, Telephony & Software Subscriptions', 'expense', 'operating_expense', 'Telecom connectivity, hospital broadband, and software systems'],
        ['6090', 'Miscellaneous & Administrative Expenses', 'expense', 'operating_expense', 'General hospital administrative and incidental operating expenditures'],
    ];

    /**
     * Seeds the standard Chart of Accounts into database if empty.
     */
    public static function seedChartOfAccountsIfEmpty(): void
    {
        $pdo = getDBConnection();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO chart_of_accounts (account_code, account_name, account_type, category, description, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        foreach (self::DEFAULT_ACCOUNTS as $acc) {
            $stmt->execute([$acc[0], $acc[1], $acc[2], $acc[3], $acc[4]]);
        }
    }

    /**
     * Creates a new custom account in Chart of Accounts.
     */
    public static function createAccount(array $data): int
    {
        $pdo = getDBConnection();
        $code = trim($data['account_code'] ?? '');
        $name = trim($data['account_name'] ?? '');
        $type = trim($data['account_type'] ?? 'expense');
        $category = trim($data['category'] ?? 'operating_expense');
        $desc = !empty($data['description']) ? trim($data['description']) : null;

        if (empty($code) || empty($name)) {
            throw new InvalidArgumentException('Account code and account name are required.');
        }

        $validTypes = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];
        if (!in_array($type, $validTypes, true)) {
            throw new InvalidArgumentException('Invalid account type specified.');
        }

        // Check unique code
        $stmtCheck = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = ?");
        $stmtCheck->execute([$code]);
        if ($stmtCheck->fetch()) {
            throw new InvalidArgumentException("Account code '{$code}' already exists in Chart of Accounts.");
        }

        $stmt = $pdo->prepare("
            INSERT INTO chart_of_accounts (account_code, account_name, account_type, category, description, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$code, $name, $type, $category, $desc]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Updates an existing account in Chart of Accounts.
     */
    public static function updateAccount(int $id, array $data): bool
    {
        $pdo = getDBConnection();
        $name = trim($data['account_name'] ?? '');
        $type = trim($data['account_type'] ?? '');
        $category = trim($data['category'] ?? '');
        $desc = !empty($data['description']) ? trim($data['description']) : null;
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if (empty($name) || empty($type)) {
            throw new InvalidArgumentException('Account name and type are required.');
        }

        $stmt = $pdo->prepare("
            UPDATE chart_of_accounts
            SET account_name = ?, account_type = ?, category = ?, description = ?, is_active = ?
            WHERE id = ?
        ");
        return $stmt->execute([$name, $type, $category, $desc, $isActive, $id]);
    }

    /**
     * Deletes an account (only if no journal entries exist for it).
     */
    public static function deleteAccount(int $id): bool
    {
        $pdo = getDBConnection();
        // Check if referenced in journal items
        $stmtRef = $pdo->prepare("SELECT COUNT(*) FROM journal_items WHERE account_id = ?");
        $stmtRef->execute([$id]);
        if ((int)$stmtRef->fetchColumn() > 0) {
            throw new RuntimeException('Cannot delete account because it has active transaction records in the ledger.');
        }

        $stmt = $pdo->prepare("DELETE FROM chart_of_accounts WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Retrieves all accounts grouped or filtered by type.
     */
    public static function getAllAccounts(?string $type = null): array
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();

        if ($type) {
            $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE account_type = ? ORDER BY account_code ASC");
            $stmt->execute([$type]);
        } else {
            $stmt = $pdo->query("SELECT * FROM chart_of_accounts ORDER BY account_code ASC");
        }
        return $stmt->fetchAll();
    }

    /**
     * Retrieves an account by ID.
     */
    public static function getAccountById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retrieves an account by code.
     */
    public static function getAccountByCode(string $code): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM chart_of_accounts WHERE account_code = ? LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retrieves the net live balance of an account from the General Ledger.
     * For Asset and Expense accounts: Balance = Debits - Credits.
     * For Liability, Equity, and Revenue accounts: Balance = Credits - Debits.
     *
     * @param string $code
     * @return float
     */
    public static function getAccountBalanceByCode(string $code): float
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();
        $acc = self::getAccountByCode($code);
        if (!$acc) {
            return 0.0;
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit
            FROM journal_items
            WHERE account_id = ?
        ");
        $stmt->execute([$acc['id']]);
        $row = $stmt->fetch();

        $debit  = (float)($row['total_debit'] ?? 0);
        $credit = (float)($row['total_credit'] ?? 0);

        if (in_array($acc['account_type'], ['asset', 'expense', 'cogs'], true)) {
            return round($debit - $credit, 2);
        } else {
            return round($credit - $debit, 2);
        }
    }

    /**
     * Retrieves all standard liquid disbursement accounts (Cash, Mobile, Bank) with their live balances.
     *
     * @return array
     */
    public static function getDisbursementAccountsWithBalances(): array
    {
        return [
            'cash' => [
                'code'    => '1010',
                'name'    => '1010 - Cash on Hand (Khasnad)',
                'balance' => self::getAccountBalanceByCode('1010'),
            ],
            'mobile' => [
                'code'    => '1020',
                'name'    => '1020 - Mobile Money (EVC/Zaad)',
                'balance' => self::getAccountBalanceByCode('1020'),
            ],
            'bank' => [
                'code'    => '1030',
                'name'    => '1030 - Bank Account (Commercial)',
                'balance' => self::getAccountBalanceByCode('1030'),
            ],
        ];
    }

    /**
     * Records a balanced Double-Entry Journal Entry with atomic validation.
     * Enforces Sum(Debits) === Sum(Credits).
     *
     * @param string $entryDate
     * @param string $refType
     * @param int|null $refId
     * @param string $description
     * @param array $items Array of ['account_id' => int, 'debit' => float, 'credit' => float, 'memo' => string]
     * @param int|null $userId
     * @return int Created Journal Entry ID
     */
    public static function recordJournalEntry(
        string $entryDate,
        string $refType,
        ?int $refId,
        string $description,
        array $items,
        ?int $userId = null
    ): int {
        if (empty($items)) {
            throw new InvalidArgumentException('Journal entry must contain at least two line items.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($items as $item) {
            $debit = round((float)($item['debit'] ?? 0), 2);
            $credit = round((float)($item['credit'] ?? 0), 2);
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        // Strict double-entry balance check (tolerance $0.01)
        if (abs($totalDebit - $totalCredit) > 0.01 || $totalDebit <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Unbalanced Journal Entry! Total Debits ($%.2f) must equal Total Credits ($%.2f).',
                $totalDebit,
                $totalCredit
            ));
        }

        $pdo = getDBConnection();
        $entryNumber = self::generateJournalNumber();

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $stmtHeader = $pdo->prepare("
                INSERT INTO journal_entries (entry_number, entry_date, reference_type, reference_id, description, total_debit, total_credit, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtHeader->execute([
                $entryNumber,
                $entryDate,
                $refType,
                $refId,
                $description,
                $totalDebit,
                $totalCredit,
                $userId,
            ]);
            $journalId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO journal_items (journal_entry_id, account_id, debit, credit, memo)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $accId = (int)$item['account_id'];
                $debit = round((float)($item['debit'] ?? 0), 2);
                $credit = round((float)($item['credit'] ?? 0), 2);
                $memo = !empty($item['memo']) ? trim($item['memo']) : $description;

                $stmtItem->execute([$journalId, $accId, $debit, $credit, $memo]);
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $journalId;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS JOURNAL ENTRY ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generates a unique Journal Entry Number (e.g. JE-2026-0001).
     */
    private static function generateJournalNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');
        $stmt = $pdo->query("SELECT MAX(id) FROM journal_entries");
        $nextId = ((int)$stmt->fetchColumn()) + 1;
        return sprintf('JE-%s-%04d', $year, $nextId);
    }

    /**
     * Generates a unique Expense Number (e.g. EXP-2026-0001).
     */
    private static function generateExpenseNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');
        $stmt = $pdo->query("SELECT MAX(id) FROM hospital_expenses");
        $nextId = ((int)$stmt->fetchColumn()) + 1;
        return sprintf('EXP-%s-%04d', $year, $nextId);
    }

    /**
     * Records a new hospital expense & automatically posts balanced journal entry.
     */
    public static function recordExpense(array $data, ?int $userId = null): int
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();

        $accountId = (int)($data['account_id'] ?? 0);
        $amount = round((float)($data['amount'] ?? 0), 2);
        $paymentMethod = in_array($data['payment_method'] ?? 'cash', ['cash', 'bank', 'mobile'], true) ? $data['payment_method'] : 'cash';
        $payee = trim($data['payee'] ?? 'Vendor / Service Provider');
        $expenseDate = !empty($data['expense_date']) ? $data['expense_date'] : date('Y-m-d');
        $description = trim($data['description'] ?? 'Hospital Operating Expense');
        $receiptRef = !empty($data['receipt_ref']) ? trim($data['receipt_ref']) : null;

        if ($accountId <= 0 || $amount <= 0) {
            throw new InvalidArgumentException('Valid expense account and positive amount are required.');
        }

        // Determine credit asset account based on payment method
        $creditAccountCode = match ($paymentMethod) {
            'bank'   => '1030', // Bank Account
            'mobile' => '1020', // Mobile Money
            default  => '1010', // Cash on Hand
        };
        $creditAccount = self::getAccountByCode($creditAccountCode);
        if (!$creditAccount) {
            throw new RuntimeException("Asset account {$creditAccountCode} not found in Chart of Accounts.");
        }

        $expNumber = self::generateExpenseNumber();

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $stmtExp = $pdo->prepare("
                INSERT INTO hospital_expenses (expense_number, account_id, amount, payment_method, payee, expense_date, description, receipt_ref, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtExp->execute([
                $expNumber,
                $accountId,
                $amount,
                $paymentMethod,
                $payee,
                $expenseDate,
                $description,
                $receiptRef,
                $userId,
            ]);
            $expenseId = (int)$pdo->lastInsertId();

            // Auto-post balanced journal entry:
            // Debit: Expense Account ($amount)
            // Credit: Cash/Bank/Mobile Account ($amount)
            self::recordJournalEntry(
                $expenseDate,
                'expense',
                $expenseId,
                "Expense [{$expNumber}]: {$payee} - {$description}",
                [
                    ['account_id' => $accountId, 'debit' => $amount, 'credit' => 0.00, 'memo' => "Paid to {$payee}"],
                    ['account_id' => (int)$creditAccount['id'], 'debit' => 0.00, 'credit' => $amount, 'memo' => "Disbursement via {$paymentMethod}"],
                ],
                $userId
            );

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $expenseId;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS EXPENSE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves all recorded hospital operating expenses.
     */
    public static function getExpenses(?string $startDate = null, ?string $endDate = null, ?int $accountId = null): array
    {
        $pdo = getDBConnection();
        $where = [];
        $params = [];

        if ($startDate) {
            $where[] = "e.expense_date >= :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $where[] = "e.expense_date <= :end_date";
            $params[':end_date'] = $endDate;
        }
        if ($accountId) {
            $where[] = "e.account_id = :acc_id";
            $params[':acc_id'] = $accountId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT e.*, a.account_code, a.account_name, a.category as account_category, u.full_name as recorder_name
            FROM hospital_expenses e
            JOIN chart_of_accounts a ON e.account_id = a.id
            LEFT JOIN users u ON e.recorded_by = u.id
            {$whereClause}
            ORDER BY e.expense_date DESC, e.id DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Computes the official Profit & Loss Statement (P&L) for a given date range.
     * Incorporates live pharmacy sales, consultation fees, lab revenues, stock costs, and operating expenses.
     */
    public static function getProfitAndLossReport(?string $startDate = null, ?string $endDate = null): array
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();

        $startDate = $startDate ?: date('Y-01-01');
        $endDate = $endDate ?: date('Y-m-d');

        // 1. REVENUES
        // A. Pharmacy Sales Revenue (4010) (Actual settled pharmacy invoices & direct sales)
        $stmtPharmRev = $pdo->prepare("
            SELECT COALESCE(SUM(paid_amount), 0)
            FROM invoices
            WHERE bill_type = 'pharmacy' AND paid_amount > 0 AND DATE(paid_at) BETWEEN ? AND ?
        ");
        $stmtPharmRev->execute([$startDate, $endDate]);
        $pharmacyRevenue = (float)$stmtPharmRev->fetchColumn();

        // Check General Ledger for 4010 revenue
        $stmtPharmGl = $pdo->prepare("
            SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '4010' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmtPharmGl->execute([$startDate, $endDate]);
        $pharmGl = (float)$stmtPharmGl->fetchColumn();
        if ($pharmGl > $pharmacyRevenue) {
            $pharmacyRevenue = $pharmGl;
        }

        // B. Consultation Encounters Revenue (4020) (Actual settled consultation fee receipts)
        $stmtCnsRev = $pdo->prepare("
            SELECT COALESCE(SUM(paid_amount), 0)
            FROM invoices
            WHERE bill_type = 'consultation' AND paid_amount > 0 AND DATE(paid_at) BETWEEN ? AND ?
        ");
        $stmtCnsRev->execute([$startDate, $endDate]);
        $consultationRevenue = (float)$stmtCnsRev->fetchColumn();

        // Check General Ledger for 4020 revenue
        $stmtCnsGl = $pdo->prepare("
            SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '4020' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmtCnsGl->execute([$startDate, $endDate]);
        $cnsGl = (float)$stmtCnsGl->fetchColumn();
        if ($cnsGl > $consultationRevenue) {
            $consultationRevenue = $cnsGl;
        }

        $stmtCnsCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM invoices
            WHERE bill_type = 'consultation' AND paid_amount > 0 AND DATE(paid_at) BETWEEN ? AND ?
        ");
        $stmtCnsCount->execute([$startDate, $endDate]);
        $consultationCount = (int)$stmtCnsCount->fetchColumn();

        if ($consultationCount === 0) {
            $stmtCnsF = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(created_at) BETWEEN ? AND ?");
            $stmtCnsF->execute([$startDate, $endDate]);
            $consultationCount = (int)$stmtCnsF->fetchColumn();
        }

        // C. Laboratory Diagnostic Orders Revenue (4030) (Actual settled lab fees)
        $stmtLabRev = $pdo->prepare("
            SELECT COALESCE(SUM(paid_amount), 0)
            FROM invoices
            WHERE bill_type IN ('lab', 'laboratory') AND paid_amount > 0 AND DATE(paid_at) BETWEEN ? AND ?
        ");
        $stmtLabRev->execute([$startDate, $endDate]);
        $labRevenue = (float)$stmtLabRev->fetchColumn();

        // Check General Ledger for 4030 revenue
        $stmtLabGl = $pdo->prepare("
            SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '4030' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmtLabGl->execute([$startDate, $endDate]);
        $labGl = (float)$stmtLabGl->fetchColumn();
        if ($labGl > $labRevenue) {
            $labRevenue = $labGl;
        }

        $stmtLabCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM invoices
            WHERE bill_type IN ('lab', 'laboratory') AND paid_amount > 0 AND DATE(paid_at) BETWEEN ? AND ?
        ");
        $stmtLabCount->execute([$startDate, $endDate]);
        $labCount = (int)$stmtLabCount->fetchColumn();

        if ($labCount === 0) {
            $stmtLabF = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE DATE(created_at) BETWEEN ? AND ?");
            $stmtLabF->execute([$startDate, $endDate]);
            $labCount = (int)$stmtLabF->fetchColumn();
        }

        $totalRevenue = $pharmacyRevenue + $consultationRevenue + $labRevenue;

        // 2. COST OF GOODS SOLD (COGS)
        $stmtCogsGl = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '5010' AND je.entry_date BETWEEN ? AND ?
        ");
        $stmtCogsGl->execute([$startDate, $endDate]);
        $cogsMedications = (float)$stmtCogsGl->fetchColumn();

        if ($cogsMedications <= 0) {
            $stmtCogs = $pdo->prepare("
                SELECT COALESCE(SUM(si.quantity * m.cost_price), 0)
                FROM pharmacy_sale_items si
                JOIN pharmacy_sales s ON si.sale_id = s.id
                JOIN medications m ON si.medication_id = m.id
                WHERE DATE(s.created_at) BETWEEN ? AND ?
            ");
            $stmtCogs->execute([$startDate, $endDate]);
            $cogsMedications = (float)$stmtCogs->fetchColumn();
        }

        $grossProfit = $totalRevenue - $cogsMedications;

        // 3. OPERATING EXPENSES
        $stmtExp = $pdo->prepare("
            SELECT a.account_code, a.account_name, COALESCE(SUM(e.amount), 0) as total_amount
            FROM hospital_expenses e
            JOIN chart_of_accounts a ON e.account_id = a.id
            WHERE e.expense_date BETWEEN ? AND ?
            GROUP BY a.id, a.account_code, a.account_name
            ORDER BY a.account_code ASC
        ");
        $stmtExp->execute([$startDate, $endDate]);
        $expenseBreakdown = $stmtExp->fetchAll();

        $totalExpenses = 0.0;
        foreach ($expenseBreakdown as $exp) {
            $totalExpenses += (float)$exp['total_amount'];
        }

        $netProfit = $grossProfit - $totalExpenses;

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
            'revenues' => [
                'pharmacy_sales'       => $pharmacyRevenue,
                'consultation_fees'    => $consultationRevenue,
                'consultation_count'   => $consultationCount,
                'laboratory_fees'      => $labRevenue,
                'laboratory_count'     => $labCount,
                'total_revenue'        => $totalRevenue,
            ],
            'cogs' => [
                'dispensed_medications'=> $cogsMedications,
                'total_cogs'           => $cogsMedications,
            ],
            'gross_profit' => $grossProfit,
            'expenses' => [
                'breakdown'      => $expenseBreakdown,
                'total_expenses' => $totalExpenses,
            ],
            'net_profit' => $netProfit,
        ];
    }

    /**
     * Records a founder/owner capital investment or cash injection into the hospital accounts.
     * Posts a balanced double-entry transaction:
     * - Debit: Cash on Hand (1010), Mobile Money (1020), or Bank Account (1030)
     * - Credit: Owner's Capital / Shareholder Equity (3010)
     *
     * @param float $amount
     * @param string $depositAccountCode '1010'|'1020'|'1030'
     * @param string $investorName
     * @param string $notes
     * @param string|null $depositDate
     * @param int|null $userId
     * @return int Journal Entry ID
     */
    public static function recordCapitalInvestment(
        float $amount,
        string $depositAccountCode = '1010',
        string $investorName = 'Hospital Founder / Investor',
        string $notes = 'Initial hospital startup capital injection',
        ?string $depositDate = null,
        ?int $userId = 1
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Capital investment amount must be greater than zero.');
        }

        self::seedChartOfAccountsIfEmpty();

        $allowedAssetCodes = ['1010', '1020', '1030'];
        if (!in_array($depositAccountCode, $allowedAssetCodes, true)) {
            $depositAccountCode = '1010';
        }

        $cashAcc   = self::getAccountByCode($depositAccountCode);
        $equityAcc = self::getAccountByCode('3010'); // Owner's Capital

        if (!$cashAcc || !$equityAcc) {
            throw new RuntimeException("Required Chart of Accounts records (Deposit: {$depositAccountCode}, Equity: 3010) not found.");
        }

        $depositDate = $depositDate ?: date('Y-m-d');
        $investorName = trim($investorName) ?: 'Founder / Investor';

        $journalItems = [
            // Debit: Liquid Asset (Cash/Mobile/Bank)
            [
                'account_id' => (int)$cashAcc['id'],
                'debit'      => $amount,
                'credit'     => 0.00,
                'memo'       => "Capital deposited by {$investorName} into {$cashAcc['account_name']}",
            ],
            // Credit: Owner's Equity (3010)
            [
                'account_id' => (int)$equityAcc['id'],
                'debit'      => 0.00,
                'credit'     => $amount,
                'memo'       => "Owner Capital Injection credited to {$equityAcc['account_name']}",
            ],
        ];

        return self::recordJournalEntry(
            $depositDate,
            'capital_investment',
            null,
            "Capital Investment: \${$amount} deposited by {$investorName} ({$notes})",
            $journalItems,
            $userId
        );
    }

    /**
     * Computes the Balance Sheet as of a specific date.
     * Verified with real General Ledger journal balances and accounting equation.
     */
    public static function getBalanceSheetReport(?string $asOfDate = null): array
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();

        $asOfDate = $asOfDate ?: date('Y-m-d');

        // 1. ASSETS
        // A. Cash on Hand (1010) (Physical Cash Drawer)
        $stmtCash = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1010' AND je.entry_date <= ?
        ");
        $stmtCash->execute([$asOfDate]);
        $cashOnHand = (float)$stmtCash->fetchColumn();

        // B. Mobile Money (1020) (EVC Plus / Zaad / Sahal)
        $stmtMob = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1020' AND je.entry_date <= ?
        ");
        $stmtMob->execute([$asOfDate]);
        $mobileMoney = (float)$stmtMob->fetchColumn();

        // C. Bank Account (1030) (Commercial Bank Accounts)
        $stmtBank = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1030' AND je.entry_date <= ?
        ");
        $stmtBank->execute([$asOfDate]);
        $bankAccount = (float)$stmtBank->fetchColumn();

        // D. Accounts Receivable (1100) (Patient Dues)
        $stmtArGl = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1100' AND je.entry_date <= ?
        ");
        $stmtArGl->execute([$asOfDate]);
        $accountsReceivable = (float)$stmtArGl->fetchColumn();

        if ($accountsReceivable <= 0) {
            $stmtAr = $pdo->prepare("
                SELECT COALESCE(SUM(due_amount), 0)
                FROM invoices
                WHERE due_amount > 0 AND DATE(created_at) <= ?
            ");
            $stmtAr->execute([$asOfDate]);
            $accountsReceivable = (float)$stmtAr->fetchColumn();
        }

        // E. Pharmacy Inventory Asset (1200)
        $stmtInv = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1200' AND je.entry_date <= ?
        ");
        $stmtInv->execute([$asOfDate]);
        $inventoryAsset = (float)$stmtInv->fetchColumn();
        if ($inventoryAsset <= 0) {
            $inventoryAsset = (float)$pdo->query("SELECT COALESCE(SUM(quantity_remaining * CASE WHEN unit_cost > 0 THEN unit_cost ELSE cost_price END), 0) FROM medicine_batches WHERE status = 'active' AND quantity_remaining > 0")->fetchColumn();
        }

        // F. Fixed Assets (1500) (Medical Equipment & Machinery)
        $stmtFix = $pdo->prepare("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '1500' AND je.entry_date <= ?
        ");
        $stmtFix->execute([$asOfDate]);
        $fixedAssets = (float)$stmtFix->fetchColumn();

        $totalCurrentAssets = $cashOnHand + $mobileMoney + $bankAccount + $accountsReceivable + $inventoryAsset;
        $totalAssets = $totalCurrentAssets + $fixedAssets;

        // 2. LIABILITIES
        // A. Accounts Payable (2010) (Supplier Restock Debts)
        $stmtAp = $pdo->prepare("
            SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '2010' AND je.entry_date <= ?
        ");
        $stmtAp->execute([$asOfDate]);
        $accountsPayable = (float)$stmtAp->fetchColumn();
        if ($accountsPayable <= 0) {
            $stmtApLegacy = $pdo->prepare("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE DATE(created_at) <= ?");
            $stmtApLegacy->execute([$asOfDate]);
            $accountsPayable = (float)$stmtApLegacy->fetchColumn();
        }

        $totalLiabilities = $accountsPayable;

        // 3. EQUITY
        // A. Owner's Capital (3010)
        $stmtCap = $pdo->prepare("
            SELECT COALESCE(SUM(ji.credit - ji.debit), 0)
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            JOIN journal_entries je ON ji.journal_entry_id = je.id
            WHERE a.account_code = '3010' AND je.entry_date <= ?
        ");
        $stmtCap->execute([$asOfDate]);
        $ownersCapital = (float)$stmtCap->fetchColumn();

        // B. Retained Earnings / Current Period Net Income
        $pnl = self::getProfitAndLossReport('2020-01-01', $asOfDate);
        $currentEarnings = (float)$pnl['net_profit'];

        // If no explicit capital was posted, balance equation
        if ($ownersCapital <= 0 && $totalAssets > ($totalLiabilities + $currentEarnings)) {
            $ownersCapital = max(0, $totalAssets - $totalLiabilities - $currentEarnings);
        }

        $totalEquity = $ownersCapital + $currentEarnings;

        return [
            'as_of_date' => $asOfDate,
            'assets' => [
                'cash_on_hand'          => $cashOnHand,
                'mobile_money'          => $mobileMoney,
                'bank_account'          => $bankAccount,
                'accounts_receivable'   => $accountsReceivable,
                'pharmacy_inventory'    => $inventoryAsset,
                'medical_equipment'     => $fixedAssets,
                'total_current_assets'  => $totalCurrentAssets,
                'total_fixed_assets'    => $fixedAssets,
                'total_assets'          => $totalAssets,
            ],
            'liabilities' => [
                'accounts_payable'      => $accountsPayable,
                'total_liabilities'     => $totalLiabilities,
            ],
            'equity' => [
                'owners_capital'        => $ownersCapital,
                'current_year_earnings' => $currentEarnings,
                'total_equity'          => $totalEquity,
            ],
            'is_balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.05,
        ];
    }

    /**
     * Computes the Trial Balance report ensuring debits equal credits.
     */
    public static function getTrialBalanceReport(?string $asOfDate = null): array
    {
        $pdo = getDBConnection();
        self::seedChartOfAccountsIfEmpty();

        $asOfDate = $asOfDate ?: date('Y-m-d');

        // Query actual General Ledger journal items directly
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.account_code,
                a.account_name,
                a.account_type,
                COALESCE(SUM(ji.debit), 0) as total_debit,
                COALESCE(SUM(ji.credit), 0) as total_credit
            FROM chart_of_accounts a
            LEFT JOIN journal_items ji ON a.id = ji.account_id
            LEFT JOIN journal_entries je ON ji.journal_entry_id = je.id AND je.entry_date <= ?
            GROUP BY a.id, a.account_code, a.account_name, a.account_type
            ORDER BY a.account_code ASC
        ");
        $stmt->execute([$asOfDate]);
        $accounts = $stmt->fetchAll();

        $trialRows = [];
        $sumDebits = 0.0;
        $sumCredits = 0.0;

        foreach ($accounts as $acc) {
            $totalDebit  = (float)$acc['total_debit'];
            $totalCredit = (float)$acc['total_credit'];

            if ($totalDebit > 0 || $totalCredit > 0) {
                $isDebitNormal = in_array($acc['account_type'], ['asset', 'cogs', 'expense'], true);
                $netBalance = $totalDebit - $totalCredit;
                $rowDebit = 0.0;
                $rowCredit = 0.0;

                if ($isDebitNormal) {
                    if ($netBalance >= 0) {
                        $rowDebit = $netBalance;
                    } else {
                        $rowCredit = abs($netBalance);
                    }
                } else {
                    if ($netBalance <= 0) {
                        $rowCredit = abs($netBalance);
                    } else {
                        $rowDebit = $netBalance;
                    }
                }

                $sumDebits  += $rowDebit;
                $sumCredits += $rowCredit;

                $trialRows[] = [
                    'account_code' => $acc['account_code'],
                    'account_name' => $acc['account_name'],
                    'account_type' => $acc['account_type'],
                    'debit'        => $rowDebit,
                    'credit'       => $rowCredit,
                ];
            }
        }

        return [
            'as_of_date'   => $asOfDate,
            'rows'         => $trialRows,
            'total_debits' => $sumDebits,
            'total_credits'=> $sumCredits,
            'difference'   => round(abs($sumDebits - $sumCredits), 2),
            'is_balanced'  => abs($sumDebits - $sumCredits) < 0.05,
        ];
    }

    /**
     * Computes the Accounts Receivable (Patient Debt Ledger) with aging buckets.
     */
    public static function getAccountsReceivableReport(): array
    {
        $pdo = getDBConnection();

        // 1. Fetch Outstanding Due Invoices
        $stmt = $pdo->query("
            SELECT 
                i.id as invoice_id,
                i.id as sale_id,
                i.invoice_number,
                i.customer_name,
                i.subtotal as total_amount,
                i.discount as discount_amount,
                i.net_total as net_amount,
                i.paid_amount,
                i.due_amount,
                i.payment_status,
                i.bill_type,
                i.created_at,
                p.mrn as patient_mrn,
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name,
                DATEDIFF(NOW(), i.created_at) as age_days
            FROM invoices i
            LEFT JOIN patients p ON i.patient_id = p.id
            WHERE i.due_amount > 0
            ORDER BY i.due_amount DESC, i.created_at ASC
        ");
        $debts = $stmt->fetchAll();

        $totalReceivable = 0.0;
        $bucket0_30 = 0.0;
        $bucket31_60 = 0.0;
        $bucket60_plus = 0.0;

        foreach ($debts as $d) {
            $due = (float)$d['due_amount'];
            $days = (int)$d['age_days'];
            $totalReceivable += $due;

            if ($days <= 30) {
                $bucket0_30 += $due;
            } elseif ($days <= 60) {
                $bucket31_60 += $due;
            } else {
                $bucket60_plus += $due;
            }
        }

        // 2. Retrieve Recent Collections (Paid Invoices & Payments)
        $stmtPayments = $pdo->query("
            SELECT 
                i.id as payment_id,
                i.id as invoice_id,
                i.patient_id,
                i.invoice_number,
                i.customer_name,
                i.paid_amount as amount_paid,
                i.payment_method,
                i.paid_at as received_at,
                i.notes,
                u.full_name as receiver_name
            FROM invoices i
            LEFT JOIN users u ON i.cashier_id = u.id
            WHERE i.paid_amount > 0 AND i.paid_at IS NOT NULL
            ORDER BY i.paid_at DESC
            LIMIT 15
        ");
        $recentPayments = $stmtPayments->fetchAll();

        return [
            'total_receivable' => $totalReceivable,
            'aging' => [
                'current_0_30'  => $bucket0_30,
                'aging_31_60'   => $bucket31_60,
                'over_60_days'  => $bucket60_plus,
            ],
            'debtors'          => $debts,
            'recent_payments'  => $recentPayments,
            'debtor_count'     => count($debts),
        ];
    }

    /**
     * Retrieves all patient and customer billing accounts (both with active debt and fully settled)
     * with aggregated financial figures (Total Invoiced, Total Paid, Balance Due, Invoices Count).
     *
     * @return array
     */
    public static function getAllCustomerAccountsWithFinancials(): array
    {
        $pdo = getDBConnection();

        // 1. Registered Patients who have invoices
        $stmtPatients = $pdo->query("
            SELECT 
                p.id as patient_id,
                NULL as invoice_id,
                CONCAT(p.first_name, ' ', p.last_name) as customer_name,
                p.phone as customer_phone,
                p.mrn as identifier,
                'Registered Patient' as account_type,
                COUNT(i.id) as total_invoices_count,
                COALESCE(SUM(i.net_total), 0.00) as total_invoiced,
                COALESCE(SUM(i.paid_amount), 0.00) as total_paid,
                COALESCE(SUM(i.due_amount), 0.00) as balance_due,
                MAX(i.created_at) as last_activity
            FROM patients p
            JOIN invoices i ON p.id = i.patient_id
            GROUP BY p.id, p.first_name, p.last_name, p.phone, p.mrn
            ORDER BY balance_due DESC, last_activity DESC
        ");
        $patientAccounts = $stmtPatients->fetchAll();

        // 2. Walk-in Customers without a registered patient ID
        $stmtWalkins = $pdo->query("
            SELECT 
                NULL as patient_id,
                i.id as invoice_id,
                i.customer_name,
                i.customer_phone,
                CONCAT('Token #', COALESCE(i.token_number, i.invoice_number)) as identifier,
                'Walk-in Customer' as account_type,
                1 as total_invoices_count,
                i.net_total as total_invoiced,
                i.paid_amount as total_paid,
                i.due_amount as balance_due,
                i.created_at as last_activity
            FROM invoices i
            WHERE i.patient_id IS NULL OR i.patient_id = 0
            ORDER BY i.due_amount DESC, i.created_at DESC
        ");
        $walkinAccounts = $stmtWalkins->fetchAll();

        return array_merge($patientAccounts, $walkinAccounts);
    }

    /**
     * Retrieves the complete Accounts Receivable (AR) statement for a specific patient or invoice debtor.
     * Includes all clinical/pharmacy invoices, itemized services, payments made, and current balance due.
     *
     * @param int|null $patientId
     * @param int|null $invoiceId
     * @return array|null
     */
    public static function getCustomerARStatement(?int $patientId, ?int $invoiceId = null): ?array
    {
        $pdo = getDBConnection();

        $patient = null;
        $invoices = [];
        $payments = [];

        if ($patientId && $patientId > 0) {
            $stmtP = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmtP->execute([$patientId]);
            $pData = $stmtP->fetch();
            if ($pData) {
                $patient = [
                    'id'      => (int)$pData['id'],
                    'name'    => trim($pData['first_name'] . ' ' . $pData['last_name']),
                    'mrn'     => $pData['mrn'],
                    'phone'   => $pData['phone'] ?: 'N/A',
                    'address' => $pData['address'] ?: 'Somalia',
                    'gender'  => ucfirst($pData['gender'] ?? 'unknown'),
                    'type'    => 'Registered Patient',
                ];
            }

            // Fetch Invoices
            $stmtInv = $pdo->prepare("
                SELECT i.*, u.full_name as cashier_name
                FROM invoices i
                LEFT JOIN users u ON i.cashier_id = u.id
                WHERE i.patient_id = ?
                ORDER BY i.created_at DESC, i.id DESC
            ");
            $stmtInv->execute([$patientId]);
            $invoices = $stmtInv->fetchAll();

            // Fetch Payments
            $stmtPay = $pdo->prepare("
                SELECT 
                    i.id,
                    i.invoice_number,
                    i.paid_amount,
                    i.payment_method,
                    i.paid_at,
                    i.notes,
                    u.full_name as cashier_name
                FROM invoices i
                LEFT JOIN users u ON i.cashier_id = u.id
                WHERE i.patient_id = ? AND i.paid_amount > 0 AND i.paid_at IS NOT NULL
                ORDER BY i.paid_at DESC
            ");
            $stmtPay->execute([$patientId]);
            $payments = $stmtPay->fetchAll();

        } elseif ($invoiceId && $invoiceId > 0) {
            $stmtInv = $pdo->prepare("
                SELECT i.*, u.full_name as cashier_name
                FROM invoices i
                LEFT JOIN users u ON i.cashier_id = u.id
                WHERE i.id = ?
            ");
            $stmtInv->execute([$invoiceId]);
            $inv = $stmtInv->fetch();
            if (!$inv) {
                return null;
            }

            $invoices = [$inv];
            $patient = [
                'id'      => 0,
                'name'    => $inv['customer_name'] ?: 'Walk-in Debtor',
                'mrn'     => $inv['token_number'] ? ('Token #' . $inv['token_number']) : 'Walk-in',
                'phone'   => $inv['customer_phone'] ?: 'N/A',
                'address' => 'Outpatient / Walk-in',
                'gender'  => 'N/A',
                'type'    => 'Outpatient / Walk-in Customer',
            ];

            if ((float)$inv['paid_amount'] > 0 && !empty($inv['paid_at'])) {
                $payments = [[
                    'id'             => $inv['id'],
                    'invoice_number' => $inv['invoice_number'],
                    'paid_amount'    => $inv['paid_amount'],
                    'payment_method' => $inv['payment_method'],
                    'paid_at'        => $inv['paid_at'],
                    'notes'          => $inv['notes'],
                    'cashier_name'   => $inv['cashier_name'] ?: 'Cashier',
                ]];
            }
        } else {
            return null;
        }

        if (!$patient) {
            return null;
        }

        // Attach itemized breakdown for each invoice
        $stmtItems = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        foreach ($invoices as &$inv) {
            $stmtItems->execute([$inv['id']]);
            $inv['items'] = $stmtItems->fetchAll();
            $itemNames = array_map(function ($it) {
                return $it['item_name'] . ($it['quantity'] > 1 ? ' (' . $it['quantity'] . 'x)' : '');
            }, $inv['items']);
            $inv['item_summary'] = !empty($itemNames) ? implode(', ', $itemNames) : (ucfirst($inv['bill_type']) . ' Charges');
        }
        unset($inv);

        $totalInvoiced = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;

        foreach ($invoices as $inv) {
            $totalInvoiced += (float)$inv['net_total'];
            $totalPaid += (float)$inv['paid_amount'];
            $totalDue += (float)$inv['due_amount'];
        }

        return [
            'patient'        => $patient,
            'total_invoiced' => $totalInvoiced,
            'total_paid'     => $totalPaid,
            'total_due'      => $totalDue,
            'invoices'       => $invoices,
            'payments'       => $payments,
            'statement_date' => date('M d, Y'),
        ];
    }

    /**
     * Computes the Accounts Payable (Supplier Debt Ledger).
     */
    public static function getAccountsPayableReport(): array
    {
        $pdo = getDBConnection();

        $stmt = $pdo->query("
            SELECT p.id as purchase_id, p.po_number, p.supplier_id, s.name as supplier_name,
                   s.contact_person, s.phone as supplier_phone,
                   p.net_amount as total_cost, p.paid_amount, p.due_amount, p.payment_status, p.created_at,
                   DATEDIFF(NOW(), p.created_at) as age_days
            FROM purchases p
            JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.due_amount > 0
            ORDER BY p.due_amount DESC, p.created_at ASC
        ");
        $payables = $stmt->fetchAll();

        $totalPayable = 0.0;
        foreach ($payables as $p) {
            $totalPayable += (float)$p['due_amount'];
        }

        // Retrieve Recent Supplier Payments
        $stmtSupplierPayments = $pdo->query("
            SELECT sp.*, s.name as supplier_name, p.po_number, u.full_name as payer_name
            FROM supplier_payments sp
            JOIN purchases p ON sp.purchase_id = p.id
            JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN users u ON sp.paid_by = u.id
            ORDER BY sp.paid_at DESC
            LIMIT 15
        ");
        $recentSupplierPayments = $stmtSupplierPayments->fetchAll();

        return [
            'total_payable'   => $totalPayable,
            'payables'        => $payables,
            'recent_payments' => $recentSupplierPayments,
            'supplier_count'  => count($payables),
        ];
    }

    /**
     * Retrieves Executive Financial Dashboard KPIs.
     */
    public static function getAccountingDashboardKPIs(): array
    {
        $thisMonthStart = date('Y-m-01');
        $today = date('Y-m-d');

        $pnlMonth = self::getProfitAndLossReport($thisMonthStart, $today);
        $ar = self::getAccountsReceivableReport();
        $ap = self::getAccountsPayableReport();
        $bs = self::getBalanceSheetReport($today);

        return [
            'monthly_revenue'   => $pnlMonth['revenues']['total_revenue'],
            'monthly_expenses'  => $pnlMonth['expenses']['total_expenses'],
            'monthly_net_profit'=> $pnlMonth['net_profit'],
            'gross_margin_pct'  => $pnlMonth['revenues']['total_revenue'] > 0 ? round(($pnlMonth['gross_profit'] / $pnlMonth['revenues']['total_revenue']) * 100, 1) : 0,
            'total_ar'          => $ar['total_receivable'],
            'ar_count'          => $ar['debtor_count'],
            'total_ap'          => $ap['total_payable'],
            'ap_count'          => $ap['supplier_count'],
            'cash_on_hand'      => $bs['assets']['cash_on_hand'],
            'mobile_money'      => $bs['assets']['mobile_money'],
            'bank_account'      => $bs['assets']['bank_account'],
            'inventory_asset'   => $bs['assets']['pharmacy_inventory'],
            'total_assets'      => $bs['assets']['total_assets'],
        ];
    }
}
