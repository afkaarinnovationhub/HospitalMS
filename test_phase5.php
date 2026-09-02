<?php
/**
 * MedCore Systems - Phase 5 Automated Test Suite: Accounting & Financial Management
 * Verifies Double-Entry General Ledger, Chart of Accounts, P&L, Balance Sheet,
 * Trial Balance, Accounts Receivable (AR), Accounts Payable (AP), and Expenses.
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/OPERATIONS/InventoryOperation.php';

echo "========================================================\n";
echo " HPMS PHASE 5 ACCOUNTING & FINANCIAL MANAGEMENT TESTS \n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$name}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] {$name}" . ($details ? " - FAILED: {$details}" : "") . "\n";
    }
}

$pdo = getDBConnection();

// Test 1: Chart of Accounts Auto-Seeding
AccountingOperation::seedChartOfAccountsIfEmpty();
$accounts = AccountingOperation::getAllAccounts();
assertTest(
    "1. Chart of Accounts Auto-Seeding",
    count($accounts) >= 17,
    "Found " . count($accounts) . " accounts in Master Chart of Accounts"
);

// Test 2: Dynamic Custom Account Creation
$uniqueCode = '69' . rand(10, 99);
while (AccountingOperation::getAccountByCode($uniqueCode)) {
    $uniqueCode = '69' . rand(10, 99);
}
$customAccId = AccountingOperation::createAccount([
    'account_code' => $uniqueCode,
    'account_name' => 'Medical Biohazard & Waste Disposal',
    'account_type' => 'expense',
    'category'     => 'operating_expense',
    'description'  => 'Clinical incinerator and hazardous waste handling',
]);
$customAcc = AccountingOperation::getAccountById($customAccId);
assertTest(
    "2. Dynamic Account Creation",
    $customAccId > 0 && $customAcc && $customAcc['account_code'] === $uniqueCode,
    "Created Account [{$uniqueCode} - {$customAcc['account_name']}]"
);

// Test 3: Account Editing & Retrieval
AccountingOperation::updateAccount($customAccId, [
    'account_name' => 'Biohazard Waste & Environmental Sanitization',
    'account_type' => 'expense',
    'category'     => 'operating_expense',
    'description'  => 'Updated waste management contract',
    'is_active'    => 1,
]);
$updatedAcc = AccountingOperation::getAccountByCode($uniqueCode);
assertTest(
    "3. Account Editing & Retrieval by Code",
    $updatedAcc && $updatedAcc['account_name'] === 'Biohazard Waste & Environmental Sanitization',
    "Updated Name: {$updatedAcc['account_name']}"
);

// Test 4: Strict Double-Entry Balanced Journal Post
$cashAcc = AccountingOperation::getAccountByCode('1010');
$revAcc  = AccountingOperation::getAccountByCode('4090');

$balancedJournalId = AccountingOperation::recordJournalEntry(
    date('Y-m-d'),
    'manual_journal',
    null,
    'Test Balanced Cash Injection',
    [
        ['account_id' => (int)$cashAcc['id'], 'debit' => 500.00, 'credit' => 0.00, 'memo' => 'Debit Cash'],
        ['account_id' => (int)$revAcc['id'],  'debit' => 0.00, 'credit' => 500.00, 'memo' => 'Credit Revenue'],
    ],
    1
);
assertTest("4. Balanced Double-Entry Journal Post", $balancedJournalId > 0, "Created Journal Entry ID: {$balancedJournalId}");

// Test 5: Unbalanced Journal Rejection (Defense Check)
$unbalancedCaught = false;
try {
    AccountingOperation::recordJournalEntry(
        date('Y-m-d'),
        'manual_journal',
        null,
        'Test Illegal Unbalanced Entry',
        [
            ['account_id' => (int)$cashAcc['id'], 'debit' => 500.00, 'credit' => 0.00],
            ['account_id' => (int)$revAcc['id'],  'debit' => 0.00, 'credit' => 300.00], // Discrepancy $200
        ],
        1
    );
} catch (InvalidArgumentException $e) {
    $unbalancedCaught = true;
}
assertTest("5. Unbalanced Journal Defense", $unbalancedCaught, "Blocked unbalanced entry (Debits != Credits)");

// Test 6: Hospital Operating Expense Recording & Auto-Journal Creation
$rentAcc = AccountingOperation::getAccountByCode('6020');
$expenseId = AccountingOperation::recordExpense([
    'account_id'     => (int)$rentAcc['id'],
    'amount'         => 1200.00,
    'payment_method' => 'cash',
    'payee'          => 'Prime Medical Plaza Landlord',
    'expense_date'   => date('Y-m-d'),
    'description'    => 'Monthly clinical building lease',
    'receipt_ref'    => 'RENT-AUG-2026',
], 1);

$allExpenses = AccountingOperation::getExpenses(date('Y-m-01'), date('Y-m-d'), (int)$rentAcc['id']);
assertTest(
    "6. Expense Recording & Auto-Ledger Posting",
    $expenseId > 0 && count($allExpenses) >= 1,
    "Recorded Expense ID: {$expenseId}, Amount: $1,200.00 (Rent)"
);

// Test 7: Profit & Loss Statement (P&L) Computation
$pnl = AccountingOperation::getProfitAndLossReport(date('Y-01-01'), date('Y-m-d'));
$grossProfitMatches = round((float)$pnl['gross_profit'], 2) === round((float)$pnl['revenues']['total_revenue'] - (float)$pnl['cogs']['total_cogs'], 2);
$netProfitMatches = round((float)$pnl['net_profit'], 2) === round((float)$pnl['gross_profit'] - (float)$pnl['expenses']['total_expenses'], 2);
assertTest(
    "7. Profit & Loss (P&L) Net Income Computation",
    $grossProfitMatches && $netProfitMatches,
    "Revenue: $" . number_format((float)$pnl['revenues']['total_revenue'], 2) . ", Expenses: $" . number_format((float)$pnl['expenses']['total_expenses'], 2) . ", Net Profit: $" . number_format((float)$pnl['net_profit'], 2)
);

// Test 8: Balance Sheet Equation Verification (Assets = Liabilities + Equity)
$bs = AccountingOperation::getBalanceSheetReport(date('Y-m-d'));
assertTest(
    "8. Balance Sheet Equation Verification",
    $bs['is_balanced'],
    "Assets ($" . number_format((float)$bs['assets']['total_assets'], 2) . ") = Liab ($" . number_format((float)$bs['liabilities']['total_liabilities'], 2) . ") + Equity ($" . number_format((float)$bs['equity']['total_equity'], 2) . ")"
);

// Test 9: Trial Balance Equilibrium Verification
$tb = AccountingOperation::getTrialBalanceReport(date('Y-m-d'));
assertTest(
    "9. Trial Balance Equilibrium Verification",
    $tb['is_balanced'],
    "Total Debits: $" . number_format((float)$tb['total_debits'], 2) . ", Total Credits: $" . number_format((float)$tb['total_credits'], 2)
);

// Test 10: Accounts Receivable (AR) Real-Time Synchronization
$ar = AccountingOperation::getAccountsReceivableReport();
assertTest(
    "10. Accounts Receivable (AR) Ledger Tracking",
    is_array($ar) && isset($ar['total_receivable']) && $ar['total_receivable'] >= 0,
    "Total Outstanding Patient Receivables: $" . number_format((float)$ar['total_receivable'], 2)
);

// Test 11: Accounts Payable (AP) Real-Time Synchronization
$ap = AccountingOperation::getAccountsPayableReport();
assertTest(
    "11. Accounts Payable (AP) Ledger Tracking",
    is_array($ap) && isset($ap['total_payable']) && $ap['total_payable'] >= 0,
    "Total Outstanding Supplier Payables: $" . number_format((float)$ap['total_payable'], 2)
);

// Test 12: Executive Accounting Dashboard KPIs
$kpis = AccountingOperation::getAccountingDashboardKPIs();
assertTest(
    "12. Executive Accounting KPIs Aggregation",
    isset($kpis['monthly_revenue'], $kpis['monthly_expenses'], $kpis['monthly_net_profit']),
    "MTD Revenue: $" . number_format((float)$kpis['monthly_revenue'], 2) . ", Margin: {$kpis['gross_margin_pct']}%"
);

// Cleanup Custom Test Account
AccountingOperation::deleteAccount($customAccId);

echo "\n========================================================\n";
echo " TEST RESULTS: {$passCount} / " . ($passCount + $failCount) . " Passed (" . round(($passCount / ($passCount + $failCount)) * 100) . "% SUCCESS)\n";
echo "========================================================\n\n";
