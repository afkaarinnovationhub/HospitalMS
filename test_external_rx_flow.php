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

echo "=== HPMS EXTERNAL PRESCRIPTION PURCHASE VERIFICATION TEST ===\n\n";

// 1. Fetch test patient and medication
$patient = $pdo->query("SELECT id, mrn, first_name, last_name FROM patients ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$med = $pdo->query("SELECT id, name, current_stock, unit_price FROM medications ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    echo "ERROR: Missing patient.\n";
    exit(1);
}
if (!$med) {
    echo "ERROR: Missing medication.\n";
    exit(1);
}

$patientName = trim($patient['first_name'] . ' ' . $patient['last_name']);
$initialStock = (int)$med['current_stock'];
$initialSalesCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales")->fetchColumn();

echo "Patient: {$patientName} (MRN: {$patient['mrn']})\n";
echo "Medication: {$med['name']} (Initial Stock: {$initialStock})\n\n";

// 2. Insert test prescription in 'pending' status
$rxNumber = 'RX-TEST-' . random_int(10000, 99999);
$stmtRx = $pdo->prepare("
    INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes)
    VALUES (:rx, :pname, :pmrn, 'Dr. Ahmed Adam Isak', 'pending', 'None known', 'Prescribed during clinical encounter')
");
$stmtRx->execute([
    ':rx'    => $rxNumber,
    ':pname' => $patientName,
    ':pmrn'  => $patient['mrn'],
]);
$rxId = (int)$pdo->lastInsertId();

$stmtItem = $pdo->prepare("
    INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
    VALUES (:rx_id, :med_id, 2, 0, 2, 'Take 1 tablet twice daily after meals', 2, :price, :total)
");
$stmtItem->execute([
    ':rx_id' => $rxId,
    ':med_id'=> (int)$med['id'],
    ':price' => (float)$med['unit_price'],
    ':total' => 2 * (float)$med['unit_price'],
]);

echo "-> Created Pending Prescription #{$rxId} ({$rxNumber}) with 2 units of {$med['name']}.\n";

// Verify it is in pending queue
$queueBefore = PharmacyOperation::getPendingPrescriptionsQueue();
$foundInQueueBefore = false;
foreach ($queueBefore as $q) {
    if ((int)$q['id'] === $rxId) {
        $foundInQueueBefore = true;
        break;
    }
}
if (!$foundInQueueBefore) {
    echo "FAIL: Prescription should be in pending queue before external purchase!\n";
    exit(1);
}
echo "PASS: Prescription is visible in pending pharmacy queue.\n\n";

// 3. Mark Prescription as External Purchase
echo "Executing: markPrescriptionExternal(#{$rxId})...\n";
$notes = "Bukaanka ayaa doortay inuu daawada ka soo gato farmashiye dibadda ah (External Purchase test).";
$res = PharmacyOperation::markPrescriptionExternal($rxId, $notes, 1);

if (!$res) {
    echo "FAIL: markPrescriptionExternal returned false!\n";
    exit(1);
}

// 4. Verify Database Changes
$rxAfter = PharmacyOperation::getPrescriptionById($rxId);
echo "Prescription #{$rxId} after external purchase:\n";
echo " - status:           {$rxAfter['status']}\n";
echo " - pharmacist_notes: {$rxAfter['pharmacist_notes']}\n";
echo " - dispensed_at:     {$rxAfter['dispensed_at']}\n";

if ($rxAfter['status'] !== 'external_purchase') {
    echo "FAIL: Prescription status is not 'external_purchase'!\n";
    exit(1);
}
echo "PASS: Prescription status updated to 'external_purchase'!\n\n";

// 5. Verify Zero Inventory Stock Deduction
$stmtStockCheck = $pdo->prepare("SELECT current_stock FROM medications WHERE id = ?");
$stmtStockCheck->execute([(int)$med['id']]);
$stockAfter = (int)$stmtStockCheck->fetchColumn();

echo "Inventory Stock Check:\n";
echo " - Stock Before: {$initialStock}\n";
echo " - Stock After:  {$stockAfter}\n";

if ($stockAfter !== $initialStock) {
    echo "FAIL: Inventory stock was deducted for external purchase! Expected {$initialStock}, got {$stockAfter}\n";
    exit(1);
}
echo "PASS: Zero stock deduction verified! Hospital inventory remains intact.\n\n";

// 6. Verify Zero Hospital Sales / Debt Incurred
$salesCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales")->fetchColumn();
if ($salesCountAfter !== $initialSalesCount) {
    echo "FAIL: A pharmacy sale record was incorrectly created!\n";
    exit(1);
}
echo "PASS: Zero hospital sales / zero debt verified!\n\n";

// 7. Verify Queue Removal
$queueAfter = PharmacyOperation::getPendingPrescriptionsQueue();
$foundInQueueAfter = false;
foreach ($queueAfter as $q) {
    if ((int)$q['id'] === $rxId) {
        $foundInQueueAfter = true;
        break;
    }
}
if ($foundInQueueAfter) {
    echo "FAIL: Prescription should no longer appear in pending queue!\n";
    exit(1);
}
echo "PASS: Prescription cleanly exited the active pharmacy dispensing queue!\n\n";

// Clean up test rows
$pdo->prepare("DELETE FROM prescription_items WHERE prescription_id = ?")->execute([$rxId]);
$pdo->prepare("DELETE FROM prescriptions WHERE id = ?")->execute([$rxId]);
echo "Cleaned up test prescription.\n\n";
echo "=== ALL TESTS PASSED SUCCESSFULLY! ===\n";
