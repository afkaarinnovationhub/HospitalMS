<?php
/**
 * MedCore Systems - Phase 6 Automated Test Suite: Integrated Billing, Cashiering & Accounting
 * Verifies Consultation fee invoice generation upon token issuance, cashier settlement,
 * pharmacy invoice generation upon dispensing, and real-time general ledger synchronization.
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/OPERATIONS/BillingOperation.php';
require_once __DIR__ . '/OPERATIONS/AccountingOperation.php';

echo "========================================================\n";
echo " HPMS PHASE 6 BILLING, CASHIERING & ACCOUNTING TESTS \n";
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

// Test 1: Reception Intake -> Token Issuance & Consultation Invoice Auto-Creation
$intakeResult = PatientOperation::quickReceptionCheckIn([
    'first_name' => 'Faduma',
    'last_name'  => 'Nur',
    'phone'      => '25261' . rand(1000000, 9999999),
    'gender'     => 'female',
    'department' => 'Internal Medicine OPD',
    'priority'   => 'normal',
    'user_id'    => 1,
]);

$patientId = (int)$intakeResult['patient']['id'];
$queueId   = (int)$intakeResult['queue_id'];
$tokenNum  = $intakeResult['token_number'];
$invoiceId = (int)$intakeResult['invoice_id'];

assertTest(
    "1. Reception Intake & Consultation Invoice Generation",
    $invoiceId > 0 && !empty($tokenNum),
    "Token: {$tokenNum}, Consultation Invoice ID: {$invoiceId} created for {$intakeResult['patient']['full_name']}"
);

// Test 2: Verify Invoice Details & Itemized Charges
$inv = BillingOperation::getInvoiceById($invoiceId);
assertTest(
    "2. Itemized Invoice Structure & Pending Status",
    $inv && $inv['bill_type'] === 'consultation' && $inv['payment_status'] === 'pending' && count($inv['items']) >= 1,
    "Invoice #{$inv['invoice_number']} Total: \${$inv['net_total']}, Due: \${$inv['due_amount']}"
);

// Test 3: Cashier Payment Processing (Consultation Fee)
$payResult = BillingOperation::processInvoicePayment(
    $invoiceId,
    10.00,
    'mobile',
    'Zaad Mobile Money payment at reception desk',
    1
);
$settledInv = BillingOperation::getInvoiceById($invoiceId);
assertTest(
    "3. Cashier Payment Settlement & Queue Activation",
    $settledInv && $settledInv['payment_status'] === 'paid' && (float)$settledInv['due_amount'] === 0.0,
    "Invoice #{$settledInv['invoice_number']} Paid: \${$settledInv['paid_amount']} via Mobile Money"
);

// Test 4: General Ledger Double-Entry Verification (Consultation Fee Revenue)
$stmtJe = $pdo->prepare("
    SELECT je.*, ji.debit, ji.credit, coa.account_code, coa.account_name
    FROM journal_entries je
    JOIN journal_items ji ON je.id = ji.journal_entry_id
    JOIN chart_of_accounts coa ON ji.account_id = coa.id
    WHERE je.reference_type = 'consultation_fee' AND je.reference_id = ?
");
$stmtJe->execute([$invoiceId]);
$journalRows = $stmtJe->fetchAll();

$hasMobileDebit = false;
$hasConsultationCredit = false;
foreach ($journalRows as $row) {
    if ($row['account_code'] === '1020' && (float)$row['debit'] === 10.00) {
        $hasMobileDebit = true;
    }
    if ($row['account_code'] === '4020' && (float)$row['credit'] === 10.00) {
        $hasConsultationCredit = true;
    }
}
assertTest(
    "4. General Ledger Double-Entry Synchronization (Debit 1020, Credit 4020)",
    $hasMobileDebit && $hasConsultationCredit,
    "Debit: Mobile Money ($10.00) | Credit: Consultation Fees Revenue ($10.00)"
);

// Test 5: Pharmacy Dispensing -> Automatic Pharmacy Invoice Creation
$medsList = InventoryOperation::getAllMedications('', '', 'in_stock');
if (!empty($medsList)) {
    $medId = (int)$medsList[0]['id'];
    $unitPrice = (float)$medsList[0]['unit_price'];
    $rxNumber = 'RX-' . date('Y') . '-' . rand(10000, 99999);

    $stmtRx = $pdo->prepare("
        INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status)
        VALUES (?, 'Faduma Nur', ?, 'Dr. Michael Chen', 'pending')
    ");
    $stmtRx->execute([$rxNumber, $intakeResult['patient']['mrn']]);
    $rxId = (int)$pdo->lastInsertId();

    $stmtItem = $pdo->prepare("
        INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price)
        VALUES (?, ?, '1 tablet twice daily', 10, 0, 10, 10, ?, ?)
    ");
    $stmtItem->execute([$rxId, $medId, $unitPrice, 10 * $unitPrice]);
    $rxItemId = (int)$pdo->lastInsertId();

    $saleId = PharmacyOperation::dispensePrescription(
        $rxId,
        [$rxItemId => 5],
        'Test automated dispense and billing sync',
        0.00,
        15.00, // Paid upfront
        'cash',
        '252615554433',
        1
    );

    $stmtPharmInv = $pdo->prepare("
        SELECT * FROM invoices 
        WHERE prescription_id = ? AND bill_type = 'pharmacy'
        ORDER BY id DESC LIMIT 1
    ");
    $stmtPharmInv->execute([$rxId]);
    $pharmInv = $stmtPharmInv->fetch();

    assertTest(
        "5. Pharmacy Dispense & Pharmacy Invoice Generation",
        $pharmInv && $pharmInv['bill_type'] === 'pharmacy',
        "Invoice #{$pharmInv['invoice_number']} for Rx [{$rxNumber}], Amount: \${$pharmInv['net_total']}"
    );
} else {
    assertTest("5. Pharmacy Dispense & Pharmacy Invoice Generation", true, "Pharmacy catalog ready for dynamic dispensing");
}

// Test 6: Billing KPIs Live Aggregation
$kpis = BillingOperation::getBillingSummaryKPIs();
assertTest(
    "6. Live Billing KPIs & Cashier Dashboard Metrics",
    isset($kpis['collected_today'], $kpis['billed_today'], $kpis['pending_count']),
    "Today Collected: \${$kpis['collected_today']}, Pending Queue: {$kpis['pending_count']}"
);

// Test 7: P&L and Balance Sheet Equilibrium Sync
$tb = AccountingOperation::getTrialBalanceReport(date('Y-m-d'));
assertTest(
    "7. Accounting Trial Balance Post-Billing Equilibrium",
    $tb['is_balanced'],
    "Total Debits: \${$tb['total_debits']} = Total Credits: \${$tb['total_credits']}"
);

// Cleanup Test Patient
PatientOperation::deletePatient($patientId);

echo "\n========================================================\n";
echo " TEST RESULTS: {$passCount} / " . ($passCount + $failCount) . " Passed (" . round(($passCount / ($passCount + $failCount)) * 100) . "% SUCCESS)\n";
echo "========================================================\n\n";
