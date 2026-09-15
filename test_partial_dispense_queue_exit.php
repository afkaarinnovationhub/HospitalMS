<?php
declare(strict_types=1);

define('HPMS_TESTING', true);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/session.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/CONFIG/auth.php';
require_once __DIR__ . '/OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/CONTROLS/PharmacyController.php';

$pdo = getDBConnection();
PharmacyOperation::ensurePrescriptionStatusEnumSupportsExternal();
InventoryOperation::seedDefaultInventoryIfEmpty();
PatientOperation::seedDefaultPatientsIfEmpty();

echo "=== HPMS PARTIAL DISPENSE & QUEUE EXIT VERIFICATION TEST ===\n\n";

// 1. Fetch test patient and 2 medications
$patient = $pdo->query("SELECT id, mrn, first_name, last_name FROM patients ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$meds = $pdo->query("SELECT id, name, current_stock, unit_price FROM medications ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);

if (!$patient) {
    echo "ERROR: Missing patient.\n";
    exit(1);
}

if (count($meds) < 2) {
    $pdo->prepare("
        INSERT INTO medications (med_code, name, generic_name, category, dosage_form, unit_price, cost_price, min_stock_alert, current_stock, status)
        VALUES ('MED-TEST-2', 'Amoxicillin 500mg', 'Amoxicillin', 'Antibiotic', 'Capsule', 1.00, 0.40, 20, 100, 'in_stock')
    ")->execute();
    $meds = $pdo->query("SELECT id, name, current_stock, unit_price FROM medications ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
}

$med1 = $meds[0];
$med2 = $meds[1];
$pname = trim($patient['first_name'] . ' ' . $patient['last_name']);

$stock1Before = (int)$med1['current_stock'];
$stock2Before = (int)$med2['current_stock'];

echo "Patient: {$pname} (MRN: {$patient['mrn']})\n";
echo "Med 1: {$med1['name']} (Stock: {$stock1Before}, Price: \${$med1['unit_price']})\n";
echo "Med 2: {$med2['name']} (Stock: {$stock2Before}, Price: \${$med2['unit_price']})\n\n";

// 2. Insert test prescription with status 'pending'
$rxNumber = 'RX-TEST-SPLIT-' . random_int(10000, 99999);
$stmtRx = $pdo->prepare("
    INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes)
    VALUES (:rx, :pname, :pmrn, 'Dr. Ahmed Adam Isak', 'pending', 'None known', 'Prescribed during clinical encounter')
");
$stmtRx->execute([
    ':rx'    => $rxNumber,
    ':pname' => $pname,
    ':pmrn'  => $patient['mrn'],
]);
$rxId = (int)$pdo->lastInsertId();

// Item 1: 30 prescribed
$stmtItem1 = $pdo->prepare("
    INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
    VALUES (:rx_id, :med_id, 30, 0, 30, '1 tablet daily in the morning', 30, :price, :total)
");
$stmtItem1->execute([
    ':rx_id' => $rxId,
    ':med_id'=> (int)$med1['id'],
    ':price' => (float)$med1['unit_price'],
    ':total' => 30 * (float)$med1['unit_price'],
]);
$item1Id = (int)$pdo->lastInsertId();

// Item 2: 20 prescribed
$stmtItem2 = $pdo->prepare("
    INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
    VALUES (:rx_id, :med_id, 20, 0, 20, '1 tablet at night', 20, :price, :total)
");
$stmtItem2->execute([
    ':rx_id' => $rxId,
    ':med_id'=> (int)$med2['id'],
    ':price' => (float)$med2['unit_price'],
    ':total' => 20 * (float)$med2['unit_price'],
]);
$item2Id = (int)$pdo->lastInsertId();

echo "-> Created Pending Rx #{$rxId} ({$rxNumber}) with 30 of Med 1 and 20 of Med 2.\n";

// 3. Verify it is visible in pending queue
$queueBefore = PharmacyOperation::getPendingPrescriptionsQueue();
$foundInQueueBefore = false;
foreach ($queueBefore as $q) {
    if ((int)$q['id'] === $rxId) {
        $foundInQueueBefore = true;
        break;
    }
}
if (!$foundInQueueBefore) {
    echo "FAIL: Prescription #{$rxId} should be in pending queue before dispensing!\n";
    exit(1);
}
echo "PASS: Prescription is in pending pharmacy queue.\n\n";

// 4. Dispense: Med 1 = 10 units (out of 30), Med 2 = 0 units (out of 20)
echo "Executing dispense: Med 1 = 10 units, Med 2 = 0 units (skipped/external)...\n";
$dispenseQuantities = [
    $item1Id => 10,
    $item2Id => 0,
];

// Ensure Med 1 has at least 150 stock and an active batch in medicine_batches
$pdo->prepare("UPDATE medications SET current_stock = 150 WHERE id = ?")->execute([$med1['id']]);
$pdo->prepare("
    INSERT INTO medicine_batches (medication_id, batch_number, quantity_received, quantity_remaining, unit_cost, cost_price, expiry_date, received_date, status)
    VALUES (?, 'BATCH-TEST-001', 150, 150, 1.00, 1.00, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), CURDATE(), 'active')
")->execute([$med1['id']]);
$batchId = (int)$pdo->lastInsertId();
$med1['current_stock'] = 150;
$stock1Before = 150;

$expectedCharge = 10 * (float)$med1['unit_price'];

$saleId = PharmacyOperation::dispensePrescription(
    $rxId,
    $dispenseQuantities,
    "Bukaanka ayaa doortay 10 xabbo oo Med 1 ah, Med 2-na wuxuu ka doortay inuu bannaanka ka gato.",
    0.00, // discount
    $expectedCharge,
    'cash',
    null,
    1,
    0.00
);

echo "Dispensed successfully! Created Sale ID: {$saleId}\n\n";

// 5. Verify Database Status
$rxAfter = PharmacyOperation::getPrescriptionById($rxId);
echo "Prescription #{$rxId} status after dispensing:\n";
echo " - status:           {$rxAfter['status']}\n";
echo " - pharmacist_notes: {$rxAfter['pharmacist_notes']}\n";

if ($rxAfter['status'] !== 'dispensed') {
    echo "FAIL: Expected status to be 'dispensed', got '{$rxAfter['status']}'!\n";
    exit(1);
}
echo "PASS: Prescription status is 'dispensed'!\n\n";

// 6. Verify Queue Removal (CRITICAL USER REQUIREMENT)
$queueAfter = PharmacyOperation::getPendingPrescriptionsQueue();
$foundInQueueAfter = false;
foreach ($queueAfter as $q) {
    if ((int)$q['id'] === $rxId) {
        $foundInQueueAfter = true;
        break;
    }
}
if ($foundInQueueAfter) {
    echo "FAIL: Prescription is STILL in the pending queue! It should have exited!\n";
    exit(1);
}
echo "PASS: Prescription has CLEANLY EXITED the Pending Queue!\n\n";

// 7. Verify Inventory Stock
$stock1After = (int)$pdo->query("SELECT current_stock FROM medications WHERE id = {$med1['id']}")->fetchColumn();
$stock2After = (int)$pdo->query("SELECT current_stock FROM medications WHERE id = {$med2['id']}")->fetchColumn();

echo "Inventory Stock Verification:\n";
echo " - Med 1: Before={$stock1Before}, After={$stock1After} (Expected: " . ($stock1Before - 10) . ")\n";
echo " - Med 2: Before={$stock2Before}, After={$stock2After} (Expected: {$stock2Before} - untouched)\n";

if ($stock1After !== ($stock1Before - 10)) {
    echo "FAIL: Med 1 stock deduction incorrect!\n";
    exit(1);
}
if ($stock2After !== $stock2Before) {
    echo "FAIL: Med 2 stock should not have been deducted!\n";
    exit(1);
}
echo "PASS: Exact stock deduction verified! Med 1 deducted by 10, Med 2 untouched.\n\n";

// 8. Verify Sale Total
$sale = $pdo->query("SELECT total_amount, net_amount, paid_amount, due_amount FROM pharmacy_sales WHERE id = {$saleId}")->fetch(PDO::FETCH_ASSOC);
echo "Financial Verification:\n";
echo " - Sale Net:  \${$sale['net_amount']} (Expected: \${$expectedCharge})\n";
echo " - Sale Paid: \${$sale['paid_amount']} (Expected: \${$expectedCharge})\n";
echo " - Sale Due:  \${$sale['due_amount']} (Expected: \$0.00)\n";

if ((float)$sale['net_amount'] !== (float)$expectedCharge) {
    echo "FAIL: Sale amount mismatch!\n";
    exit(1);
}
echo "PASS: Patient was charged ONLY for the 10 units taken!\n\n";

// Clean up test data
$pdo->prepare("DELETE FROM pharmacy_sale_items WHERE sale_id = ?")->execute([$saleId]);
$pdo->prepare("DELETE FROM pharmacy_sales WHERE id = ?")->execute([$saleId]);
$pdo->prepare("DELETE FROM prescription_items WHERE prescription_id = ?")->execute([$rxId]);
$pdo->prepare("DELETE FROM prescriptions WHERE id = ?")->execute([$rxId]);
$pdo->prepare("DELETE FROM medicine_batch_movements WHERE batch_id = ?")->execute([$batchId]);
$pdo->prepare("DELETE FROM medicine_batches WHERE id = ?")->execute([$batchId]);
$pdo->prepare("UPDATE medications SET current_stock = ? WHERE id = ?")->execute([$stock1Before, $med1['id']]);

echo "=== ALL PARTIAL DISPENSE & QUEUE EXIT TESTS PASSED 100%! ===\n";
