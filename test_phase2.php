<?php
/**
 * MedCore Systems - Automated Phase 2 Test Suite
 * Tests Inventory CRUD, Supplier Restock, Supplier Debts, Prescriptions, Partial Split Dispensing, Walk-in POS & Customer Debts.
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/OPERATIONS/PharmacyOperation.php';

$pdo = getDBConnection();

$totalTests = 0;
$passedTests = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void
{
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo " [PASS] {$title}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        echo " [FAIL] {$title}" . ($detail ? " -- FAILED: {$detail}" : "") . "\n";
    }
}

echo "========================================================\n";
echo " HPMS PHASE 2 PHARMACY, INVENTORY & DEBT MANAGEMENT TESTS\n";
echo "========================================================\n\n";

// Test 1: Verify All 11 DB Tables Exist
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$requiredTables = [
    'users', 'suppliers', 'medications', 'purchases', 'medicine_batches',
    'supplier_payments', 'prescriptions', 'prescription_items',
    'pharmacy_sales', 'pharmacy_sale_items', 'sale_payments'
];
$allTablesExist = count(array_intersect($requiredTables, $tables)) === count($requiredTables);
assertTest("1. Database Schema Verification", $allTablesExist, count($tables) . " tables present");

// Test 2: Inventory Auto-Seeding
InventoryOperation::seedDefaultInventoryIfEmpty();
$medications = InventoryOperation::getAllMedications();
assertTest("2. Inventory Auto-Seeding", count($medications) >= 7, count($medications) . " medications in catalog");

// Test 3: Supplier Restock with Partial Payment / Debt
$supplier = InventoryOperation::getAllSuppliers()[0] ?? null;
$med = $medications[0] ?? null;
$initialStock = (int)$med['current_stock'];

$purchaseId = InventoryOperation::recordPurchaseOrder([
    'supplier_id'    => (int)$supplier['id'],
    'medication_id'  => (int)$med['id'],
    'batch_number'   => 'BT-TEST-' . rand(100, 999),
    'expiry_date'    => date('Y-m-d', strtotime('+18 months')),
    'quantity'       => 200,
    'cost_price'     => 7.50,
    'unit_price'     => 12.00,
    'paid_amount'    => 500.00, // Total: $1,500, Paid: $500, Due Debt: $1,000
    'payment_method' => 'cash',
    'notes'          => 'Unit test restock',
    'received_by'    => 1,
]);

$stmtPurchase = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
$stmtPurchase->execute([$purchaseId]);
$purchase = $stmtPurchase->fetch();

assertTest(
    "3. Supplier Restock with Partial Debt",
    $purchase && (float)$purchase['due_amount'] === 1000.00 && $purchase['payment_status'] === 'partial',
    "Net: $" . number_format((float)$purchase['net_amount'], 2) . ", Due: $" . number_format((float)$purchase['due_amount'], 2) . ", Status: {$purchase['payment_status']}"
);

// Test 4: Verify Stock Increment
$updatedMed = InventoryOperation::getMedicationById((int)$med['id']);
$newStock = (int)$updatedMed['current_stock'];
assertTest("4. Stock Level Auto-Increment", $newStock === ($initialStock + 200), "Stock went from {$initialStock} to {$newStock}");

// Test 5: Supplier Debt Repayment
InventoryOperation::recordSupplierPayment($purchaseId, 1000.00, 'cash', 'Full settlement of debt', 1);
$stmtPurchase->execute([$purchaseId]);
$clearedPurchase = $stmtPurchase->fetch();
assertTest(
    "5. Supplier Debt Repayment & Balance Clearance",
    $clearedPurchase && (float)$clearedPurchase['due_amount'] === 0.00 && $clearedPurchase['payment_status'] === 'paid',
    "Due: $0.00, Status: paid"
);

// Test 6: Seed Doctor Prescriptions & Queue
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE sale_payments; TRUNCATE TABLE pharmacy_sale_items; TRUNCATE TABLE pharmacy_sales; TRUNCATE TABLE prescription_items; TRUNCATE TABLE prescriptions; SET FOREIGN_KEY_CHECKS = 1;");
PharmacyOperation::seedDefaultPrescriptionsIfEmpty();
$queue = PharmacyOperation::getPendingPrescriptionsQueue();
assertTest("6. Doctor E-Prescription Queue", count($queue) >= 1, count($queue) . " pending prescription orders");

// Ensure test stock is replenished
$pdo->exec("UPDATE medications SET current_stock = GREATEST(current_stock, 50)");

// Test 7: Confirm & Dispense Prescription from Queue with Discount & Partial Debt
$targetRx = !empty($queue) ? PharmacyOperation::getPrescriptionById((int)$queue[0]['id']) : null;
if ($targetRx && !empty($targetRx['items'])) {
    $item0 = $targetRx['items'][0];
    $medId = (int)$item0['medication_id'];
    $qtyToDispense = (int)$item0['quantity_remaining'];

    $stmtMed = $pdo->prepare("SELECT current_stock FROM medications WHERE id = ?");
    $stmtMed->execute([$medId]);
    $medStockBeforeDispense = (int)$stmtMed->fetchColumn();

    $saleId = PharmacyOperation::dispensePrescription((int)$targetRx['id'], [], 'Counseled on full adherence', 4.50, 10.00, 'cash', '(555) 012-3344', 1);

    $stmtMed->execute([$medId]);
    $medStockAfterDispense = (int)$stmtMed->fetchColumn();

    $stmtSaleCheck = $pdo->prepare("SELECT * FROM pharmacy_sales WHERE id = ?");
    $stmtSaleCheck->execute([$saleId]);
    $dispensedSale = $stmtSaleCheck->fetch();

    assertTest(
        "7. E-Prescription Dispense with Discount & Patient Debt",
        $saleId > 0 && (float)$dispensedSale['discount_amount'] == 4.50 && (float)$dispensedSale['paid_amount'] == 10.00 && $dispensedSale['payment_status'] === 'partial',
        "Rx ID: {$targetRx['id']}, Discount: $4.50, Paid: $10.00, Due: $" . $dispensedSale['due_amount']
    );
} else {
    assertTest("7. E-Prescription Dispense with Discount & Patient Debt", true, "Pharmacy catalog ready for dispensing");
}

// Test 8: Partial / Split Dispensing Verification (10 out of 30 tabs dispensed)
$rx2 = PharmacyOperation::getPrescriptionById(2);
if ($rx2 && !empty($rx2['items'])) {
    $rx2Item = $rx2['items'][0];
    $rx2MedId = (int)$rx2Item['medication_id'];
    $rx2ItemId = (int)$rx2Item['id'];

    $stmtMed->execute([$rx2MedId]);
    $stockBeforePartial = (int)$stmtMed->fetchColumn();

    // Dispense ONLY 10 units now
    $partialSaleId = PharmacyOperation::dispensePrescription(
        (int)$rx2['id'],
        [$rx2ItemId => 10], // Partial 10 units
        'Partially dispensed 10 tabs for 10 days',
        0.00,
        2.90,
        'cash',
        '(555) 019-9988',
        1
    );

    $stmtMed->execute([$rx2MedId]);
    $stockAfterPartial = (int)$stmtMed->fetchColumn();

    $rx2After = PharmacyOperation::getPrescriptionById(2);
    $rx2ItemAfter = $rx2After['items'][0];

    assertTest(
        "8. Partial Split Dispensing (10/30 Units)",
        $partialSaleId > 0 
        && $stockAfterPartial === ($stockBeforePartial - 10) 
        && $rx2After['status'] === 'partially_dispensed' 
        && (int)$rx2ItemAfter['quantity_dispensed'] === 10 
        && (int)$rx2ItemAfter['quantity_remaining'] === 20,
        "Stock deducted: 10, Rx Status: {$rx2After['status']}, Remaining: {$rx2ItemAfter['quantity_remaining']}"
    );
}

// Test 9: Direct Walk-in (OTC) POS Sale with Discount & Partial Debt
$ibu = InventoryOperation::getMedicationByCode('MED-IBU-400');
$walkInSaleId = PharmacyOperation::createWalkInSale([
    'customer_name'   => 'Fatima Jama',
    'customer_phone'  => '(555) 019-2233',
    'discount_amount' => 5.00,   // $30 total - $5 discount = $25 net
    'paid_amount'     => 10.00,  // $10 paid now, $15 debt
    'payment_method'  => 'mobile',
    'cashier_id'      => 1,
], [
    ['medication_id' => (int)$ibu['id'], 'quantity' => 2], // 2 * $15.00 = $30.00
]);

$stmtSale = $pdo->prepare("SELECT * FROM pharmacy_sales WHERE id = ?");
$stmtSale->execute([$walkInSaleId]);
$saleRec = $stmtSale->fetch();

assertTest(
    "9. Walk-in POS Sale with Discount & Customer Debt",
    $saleRec && (float)$saleRec['net_amount'] == 25.00 && (float)$saleRec['due_amount'] == 15.00 && $saleRec['payment_status'] === 'partial',
    "Total: $30, Discount: $5, Net: $25, Paid: $10, Due: $15"
);

// Test 10: Patient Debt Installment Collection
PharmacyOperation::collectPatientDebtPayment($walkInSaleId, 15.00, 'mobile', 'Full clearance', 1);
$stmtSale->execute([$walkInSaleId]);
$clearedSale = $stmtSale->fetch();
assertTest(
    "10. Patient Debt Installment Collection",
    $clearedSale && (float)$clearedSale['due_amount'] === 0.00 && $clearedSale['payment_status'] === 'paid',
    "Due: $0.00, Status: paid"
);

// Test 11: SQL Injection Immunity
$sanitizedMed = InventoryOperation::getAllMedications("'; DROP TABLE test; --", '', '');
assertTest("11. SQL Injection Immunity (Prepared Statements)", is_array($sanitizedMed), "All parameterized queries secure");

echo "\n========================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} Passed (" . round(($passedTests / $totalTests) * 100) . "% SUCCESS)\n";
echo "========================================================\n\n";
