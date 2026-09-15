<?php
declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/OPERATIONS/InventoryOperation.php';

$pdo = getDBConnection();
PharmacyOperation::ensurePrescriptionStatusEnumSupportsExternal();
PatientOperation::seedDefaultPatientsIfEmpty();
InventoryOperation::seedDefaultInventoryIfEmpty();

$action = $argv[1] ?? 'setup';

if ($action === 'setup') {
    // Ensure test patient exists
    $patient = $pdo->query("SELECT id, mrn, first_name, last_name FROM patients WHERE mrn = 'PAT-2026-0042' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        $patient = $pdo->query("SELECT id, mrn, first_name, last_name FROM patients ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }
    $pname = trim($patient['first_name'] . ' ' . $patient['last_name']);
    $pmrn = $patient['mrn'];

    // Get 2 medications
    $meds = $pdo->query("SELECT id, name, unit_price FROM medications ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $med1 = $meds[0];
    $med2 = $meds[1] ?? $meds[0];

    // 1. Create a Pending Prescription to load in POS
    $rxNumPending = 'RX-2026-PENDING';
    $pdo->prepare("DELETE FROM prescriptions WHERE rx_number = ?")->execute([$rxNumPending]);
    $stmtRx1 = $pdo->prepare("
        INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes, created_at)
        VALUES (?, ?, ?, 'Dr. Ahmed Adam Isak', 'pending', 'Penicillin (Severe hives/rash)', 'Prescribed during clinical consultation', NOW())
    ");
    $stmtRx1->execute([$rxNumPending, $pname, $pmrn]);
    $rxPendingId = (int)$pdo->lastInsertId();

    $stmtItem1 = $pdo->prepare("
        INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
        VALUES (?, ?, 30, 0, 30, '1 tablet daily in the morning', 30, ?, ?)
    ");
    $stmtItem1->execute([$rxPendingId, $med1['id'], $med1['unit_price'], 30 * (float)$med1['unit_price']]);

    $stmtItem2 = $pdo->prepare("
        INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
        VALUES (?, ?, 20, 0, 20, '1 tablet at bedtime if needed', 20, ?, ?)
    ");
    $stmtItem2->execute([$rxPendingId, $med2['id'], $med2['unit_price'], 20 * (float)$med2['unit_price']]);

    // 2. Create an External Purchase Prescription for Patient Profile Tab
    $rxNumExternal = 'RX-2026-EXT-009';
    $pdo->prepare("DELETE FROM prescriptions WHERE rx_number = ?")->execute([$rxNumExternal]);
    $stmtRx2 = $pdo->prepare("
        INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes, created_at, dispensed_at)
        VALUES (?, ?, ?, 'Dr. Ahmed Adam Isak', 'external_purchase', 'Penicillin (Severe hives/rash)', 'Bukaanka ayaa doortay inuu daawada ka soo gato farmashiye dibadda ah (External Pharmacy). Rikheeto rasmi ah ayaa loo daabacay.', DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR))
    ");
    $stmtRx2->execute([$rxNumExternal, $pname, $pmrn]);
    $rxExtId = (int)$pdo->lastInsertId();

    $stmtItem3 = $pdo->prepare("
        INSERT INTO prescription_items (prescription_id, medication_id, quantity_prescribed, quantity_dispensed, quantity_remaining, dosage_instructions, quantity, unit_price, total_price)
        VALUES (?, ?, 30, 0, 30, '1 tablet daily in the morning (30 days)', 30, ?, ?)
    ");
    $stmtItem3->execute([$rxExtId, $med1['id'], $med1['unit_price'], 30 * (float)$med1['unit_price']]);

    // Setup session
    ini_set('session.save_path', 'C:/xampp/tmp');
    session_name('HPMS_SESSID');
    session_id('antigravitytestsession');
    session_start();
    $_SESSION['hpms_user_id'] = 1;
    $_SESSION['hpms_user_role'] = 'superadmin_ict';
    $_SESSION['hpms_user_name'] = 'Dr. Ahmed (Admin)';
    $_SESSION['hpms_last_activity'] = time();
    session_write_close();

    echo json_encode([
        'success' => true,
        'patient_id' => $patient['id'],
        'patient_mrn' => $pmrn,
        'patient_name' => $pname,
        'pending_rx_id' => $rxPendingId,
        'pending_rx_number' => $rxNumPending,
        'external_rx_id' => $rxExtId,
        'external_rx_number' => $rxNumExternal
    ]);
}
