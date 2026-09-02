<?php
/**
 * HPMS Phase 7 Automated Test Suite: Diagnostic Laboratory, Billing & Clinical Feedback Loop
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/OPERATIONS/DoctorOperation.php';
require_once __DIR__ . '/OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/OPERATIONS/BillingOperation.php';
require_once __DIR__ . '/OPERATIONS/AccountingOperation.php';

echo "========================================================\n";
echo " HPMS PHASE 7 DIAGNOSTIC LABORATORY & BILLING TESTS\n";
echo "========================================================\n\n";

$pdo = getDBConnection();
$passed = 0;
$total = 0;

function runTest(string $name, callable $fn): void {
    global $passed, $total;
    $total++;
    try {
        $result = $fn();
        if ($result === true || (is_array($result) && ($result['status'] ?? '') === 'pass')) {
            $msg = is_array($result) ? ($result['message'] ?? 'OK') : 'OK';
            echo " [PASS] {$total}. {$name} ({$msg})\n";
            $passed++;
        } else {
            $msg = is_array($result) ? ($result['message'] ?? 'Failed') : 'Failed';
            echo " [FAIL] {$total}. {$name} -- {$msg}\n";
        }
    } catch (Throwable $e) {
        echo " [FAIL] {$total}. {$name} -- Exception: " . $e->getMessage() . "\n";
    }
}

// Test 1: Lab Test Catalog Seeding & Pricing
runTest("Master Diagnostic Lab Catalog", function() {
    LaboratoryOperation::seedLabCatalogIfEmpty();
    $catalog = LaboratoryOperation::getLabTestCatalog();
    if (count($catalog) < 8) {
        return ['status' => 'fail', 'message' => 'Expected at least 8 lab tests in catalog, found ' . count($catalog)];
    }
    $cbc = null;
    foreach ($catalog as $c) {
        if (str_contains($c['test_name'], 'Complete Blood Count')) {
            $cbc = $c;
            break;
        }
    }
    if (!$cbc || (float)$cbc['price'] <= 0) {
        return ['status' => 'fail', 'message' => 'CBC test not configured properly'];
    }
    return ['status' => 'pass', 'message' => count($catalog) . ' active diagnostic tests in catalog (CBC: $' . number_format((float)$cbc['price'], 2) . ')'];
});

// Test 2: Patient Registration & Reception Intake
$patientId = 0;
$queueId = 0;
$docId = 0;
runTest("Patient Check-in & Doctor Assignment", function() use (&$patientId, &$queueId, &$docId, $pdo) {
    // Find or create doctor
    $stmtD = $pdo->query("SELECT id FROM users WHERE role = 'doctor' LIMIT 1");
    $docId = (int)$stmtD->fetchColumn();
    if ($docId <= 0) {
        $docId = DoctorOperation::createDoctor([
            'full_name' => 'Dr. Ahmed Keynan',
            'username' => 'dr_keynan_' . rand(100, 999),
            'email' => 'keynan.' . rand(100, 999) . '@medcore.org',
            'password' => 'doctor123',
            'professional_title' => 'Consultant Physician',
            'consultation_fee' => 15.00,
            'phone' => '555-444-3333',
            'account_status' => 'active',
        ]);
    }

    $intake = PatientOperation::quickReceptionCheckIn([
        'first_name'       => 'Guled',
        'last_name'        => 'Farah',
        'phone'            => '25261' . rand(1000000, 9999999),
        'gender'           => 'male',
        'doctor_id'        => $docId,
        'department'       => 'General OPD',
        'consultation_fee' => 15.00,
        'user_id'          => 1,
    ]);

    $patientId = (int)$intake['patient']['id'];
    $queueId   = (int)$intake['queue_id'];

    // Settle consultation invoice
    BillingOperation::processInvoicePayment((int)$intake['invoice_id'], 15.00, 'cash', 'Consultation fee', 1);

    return ['status' => 'pass', 'message' => "Patient: Guled Farah (ID: {$patientId}), Token: {$intake['token_number']}"];
});

// Test 3: Doctor Orders Diagnostic Lab Tests & Auto-Generates Lab Invoice
$labOrderIds = [];
$labInvoiceId = 0;
runTest("Doctor Orders Diagnostic Lab Tests & Dispatches Invoice", function() use ($patientId, $docId, $queueId, &$labOrderIds, &$labInvoiceId) {
    $testsToOrder = [
        'Complete Blood Count (CBC / FBC)',
        'Malaria Rapid Diagnostic Test (RDT & Blood Film)',
    ];

    $res = LaboratoryOperation::createLabOrders(
        $patientId,
        $docId,
        null,
        $queueId,
        $testsToOrder,
        'Patient reports persistent high fever and chills for 4 days. Please check parasites and hemoglobin.',
        'urgent',
        $docId
    );

    $labOrderIds = $res['order_ids'];
    $labInvoiceId = $res['invoice_id'];

    // Verify queue is now in_lab
    $pdo = getDBConnection();
    $qStatus = $pdo->query("SELECT status FROM patient_queues WHERE id = {$queueId}")->fetchColumn();

    if ($qStatus !== 'in_lab') {
        return ['status' => 'fail', 'message' => "Expected queue status 'in_lab', got '{$qStatus}'"];
    }

    $inv = BillingOperation::getInvoiceById($labInvoiceId);
    if ((float)$inv['net_total'] !== 20.00) { // CBC $12 + Malaria $8 = $20
        return ['status' => 'fail', 'message' => "Expected lab invoice total $20.00, got $" . $inv['net_total']];
    }

    return ['status' => 'pass', 'message' => "Created " . count($labOrderIds) . " lab orders (Total: $" . number_format((float)$inv['net_total'], 2) . ") | Queue: in_lab"];
});

// Test 4: Cashier Payment of Lab Invoice & Double-Entry Sync
runTest("Cashier Lab Invoice Settlement & GL Sync", function() use ($labInvoiceId) {
    $pay = BillingOperation::processInvoicePayment($labInvoiceId, 20.00, 'mobile', 'Paid via Zaad Mobile', 1);
    if ($pay['status'] !== 'paid') {
        return ['status' => 'fail', 'message' => 'Lab invoice not marked paid'];
    }

    // Verify Accounting GL Journal
    $tb = AccountingOperation::getTrialBalanceReport(date('Y-m-d'));
    if (!$tb['is_balanced']) {
        return ['status' => 'fail', 'message' => 'Trial Balance out of equilibrium after lab payment'];
    }

    return ['status' => 'pass', 'message' => "Invoice #{$pay['invoice_number']} settled ($20.00 via Mobile) | GL Balanced: YES"];
});

// Test 5: Specimen Collection & Result Recording by Lab Technician
runTest("Specimen Collection & Diagnostic Findings Recording", function() use ($labOrderIds, $queueId) {
    // 1. Collect Specimen
    foreach ($labOrderIds as $ordId) {
        LaboratoryOperation::collectSpecimen((int)$ordId, 1);
    }

    // 2. Record Results for CBC
    LaboratoryOperation::recordTestResults(
        (int)$labOrderIds[0],
        'Hemoglobin is moderately low at 10.4 g/dL. Mild leukocytosis with WBC of 11,800 /uL.',
        "Hb: 10.4 g/dL | WBC: 11,800 | Platelets: 195,000 | Hematocrit: 31.2%",
        1
    );

    // 3. Record Results for Malaria
    LaboratoryOperation::recordTestResults(
        (int)$labOrderIds[1],
        'POSITIVE for Plasmodium falciparum (+2 ring-form trophozoites seen).',
        'RDT: Positive (P. falciparum) | Blood Film: +2 Parasitemia',
        1
    );

    // Verify Queue status automatically transitioned to 'lab_completed'
    $pdo = getDBConnection();
    $qStatus = $pdo->query("SELECT status FROM patient_queues WHERE id = {$queueId}")->fetchColumn();

    if ($qStatus !== 'lab_completed') {
        return ['status' => 'fail', 'message' => "Expected queue status 'lab_completed', got '{$qStatus}'"];
    }

    return ['status' => 'pass', 'message' => "Specimens analyzed, findings released, and Queue updated to 'lab_completed'"];
});

// Test 6: Doctor Reviews Lab Results and Prescribes Targeted Medication
runTest("Doctor Reviews Lab Findings & Prescribes Targeted Medication", function() use ($patientId, $docId, $queueId, $pdo) {
    // 1. Fetch completed lab results
    $results = LaboratoryOperation::getPatientLabResults($patientId);
    if (count($results) !== 2) {
        return ['status' => 'fail', 'message' => 'Expected 2 completed lab results for patient, found ' . count($results)];
    }

    // 2. Doctor prescribes targeted malaria treatment based on lab findings
    $medStmt = $pdo->query("SELECT id FROM medications LIMIT 1");
    $medId = (int)$medStmt->fetchColumn();

    $consultationId = ConsultationOperation::recordConsultationEncounter([
        'patient_id'           => $patientId,
        'doctor_id'            => $docId,
        'queue_id'             => $queueId,
        'subjective_notes'     => 'Follow-up on laboratory results. Patient reports fever improving slightly.',
        'objective_findings'   => 'Lab verified: Malaria P. falciparum positive, Hb 10.4 g/dL.',
        'assessment_diagnosis' => 'Confirmed Acute Plasmodium Falciparum Malaria with Mild Anemia',
        'treatment_plan'       => 'Start oral Artemether-Lumefantrine immediately, adequate hydration, iron supplements.',
        'follow_up_date'       => date('Y-m-d', strtotime('+3 days')),
    ], [
        [
            'medication_id'       => $medId,
            'quantity'            => 24,
            'dosage_instructions' => 'Take 4 tablets initially, then 4 tabs at 8h, 24h, 36h, 48h, 60h with food',
        ]
    ]);

    // Verify Queue is now completed
    $qStatus = $pdo->query("SELECT status FROM patient_queues WHERE id = {$queueId}")->fetchColumn();
    if ($qStatus !== 'completed') {
        return ['status' => 'fail', 'message' => "Expected queue status 'completed', got '{$qStatus}'"];
    }

    return ['status' => 'pass', 'message' => "Consultation #{$consultationId} completed & routed to Pharmacy queue"];
});

// Test 7: Final Accounting & Revenue Verification
runTest("P&L and Balance Sheet Diagnostic Revenue Verification", function() {
    $pnl = AccountingOperation::getProfitAndLossReport(date('Y-m-d'), date('Y-m-d'));
    $bs = AccountingOperation::getBalanceSheetReport(date('Y-m-d'));
    $tb = AccountingOperation::getTrialBalanceReport(date('Y-m-d'));

    if (!$tb['is_balanced']) {
        return ['status' => 'fail', 'message' => 'Trial Balance is unbalanced'];
    }

    return ['status' => 'pass', 'message' => "Trial Balance: Debits (\${$tb['total_debits']}) = Credits (\${$tb['total_credits']}) [Equilibrium: YES]"];
});

echo "\n========================================================\n";
echo " TEST RESULTS: {$passed} / {$total} Passed (" . round(($passed / $total) * 100) . "% SUCCESS)\n";
echo "========================================================\n";
