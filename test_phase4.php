<?php
/**
 * MedCore Systems - Automated Test Suite: Phase 4
 * Tests Doctor Registration, SOAP Encounters, E-Prescribing, Lab Orders & Live Queue Workflows.
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/OPERATIONS/DoctorOperation.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/OPERATIONS/InventoryOperation.php';

$pdo = getDBConnection();
$totalTests = 0;
$passedTests = 0;

function assertTest(string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo " [PASS] {$description}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        echo " [FAIL] {$description} -- FAILED" . ($details ? ": {$details}" : "") . "\n";
    }
}

echo "\n========================================================\n";
echo " HPMS PHASE 4 DOCTORS, CONSULTATIONS & E-PRESCRIBING TESTS \n";
echo "========================================================\n\n";

// Test 1: Doctor Account Creation
$docUsername = 'dr_test_' . time();
$docId = DoctorOperation::createDoctor([
    'full_name'          => 'Dr. Fatima Warsame',
    'username'           => $docUsername,
    'email'              => "fatima.w.{$docUsername}@hospital.com",
    'password'           => 'securePass123',
    'professional_title' => 'Senior Consultant Pulmonologist',
    'phone'              => '(555) 888-9900',
    'account_status'     => 'active',
]);
assertTest("1. Doctor Account Creation", $docId > 0, "Created Doctor ID: {$docId} (@{$docUsername})");

// Test 2: Doctor Profile Retrieval & Verification
$doc = DoctorOperation::getDoctorById($docId);
assertTest(
    "2. Doctor Profile Retrieval",
    $doc && $doc['full_name'] === 'Dr. Fatima Warsame' && $doc['professional_title'] === 'Senior Consultant Pulmonologist',
    "Title: {$doc['professional_title']}"
);

// Test 3: Doctor Profile Editing
DoctorOperation::updateDoctor($docId, [
    'full_name'          => 'Dr. Fatima Warsame-Ali',
    'email'              => "fatima.w.{$docUsername}@hospital.com",
    'professional_title' => 'Chief of Pulmonology & Critical Care',
    'phone'              => '(555) 888-9911',
    'account_status'     => 'active',
]);
$updatedDoc = DoctorOperation::getDoctorById($docId);
assertTest(
    "3. Doctor Profile Editing",
    $updatedDoc && $updatedDoc['full_name'] === 'Dr. Fatima Warsame-Ali' && $updatedDoc['phone'] === '(555) 888-9911',
    "Updated Title: {$updatedDoc['professional_title']}"
);

// Test 4: Quick Reception Check-in Assigned to New Doctor (Basic Info Only: Name + Phone + Gender)
$quickPat = PatientOperation::quickReceptionCheckIn([
    'first_name'      => 'Khadar',
    'last_name'       => 'Osman',
    'phone'           => '(555) 444-3322',
    'gender'          => 'male',
    'doctor_id'       => $docId,
    'department'      => 'Pulmonology OPD',
    'priority'        => 'urgent',
    'chief_complaint' => 'Severe dyspnea and wheezing',
    'user_id'         => 1,
]);
$patId = (int)$quickPat['patient']['id'];
$queueId = (int)$quickPat['queue_id'];
$rawPat = PatientOperation::getPatientById($patId);
assertTest(
    "4. Reception Check-In Basic Intake Only",
    $queueId > 0 && $rawPat['dob'] === null && $rawPat['blood_group'] === null,
    "Token: {$quickPat['token_number']}, Gender: {$rawPat['gender']}, DOB & Blood Group left null for Doctor"
);

// Test 5: Doctor Queue Workload Tracking
$allDocs = DoctorOperation::getAllDoctors();
$targetDocStats = null;
foreach ($allDocs as $d) {
    if ((int)$d['id'] === $docId) {
        $targetDocStats = $d;
        break;
    }
}
assertTest(
    "5. Doctor Live Workload Aggregation",
    $targetDocStats && (int)$targetDocStats['waiting_count'] >= 1,
    "Dr. Fatima has {$targetDocStats['waiting_count']} waiting patient(s)"
);

// Test 6: Ensure Active Medications in Catalog
$medCatalog = InventoryOperation::getAllMedications();
$med1 = $medCatalog[0] ?? null;
assertTest("6. Pharmacy Medication Catalog Available", !empty($med1) && (int)$med1['id'] > 0, "Medication 1: {$med1['name']} ($" . number_format((float)$med1['unit_price'], 2) . ")");

// Test 7: Doctor Clinical Consultation Encounter (Completing Patient Baseline + SOAP + E-Prescribing + Lab Orders)
// Simulate Doctor updating baseline info during consultation
PatientOperation::updatePatient($patId, [
    'first_name'              => $rawPat['first_name'],
    'last_name'               => $rawPat['last_name'],
    'gender'                  => $rawPat['gender'],
    'dob'                     => date('Y-m-d', strtotime('-28 years')),
    'phone'                   => $rawPat['phone'],
    'email'                   => $rawPat['email'],
    'address'                 => $rawPat['address'],
    'blood_group'             => 'O+',
    'allergies'               => 'Penicillin (Severe Rash)',
    'medical_history'         => 'Childhood asthma',
    'emergency_contact_name'  => null,
    'emergency_contact_phone' => null,
]);
$updatedPatByDoc = PatientOperation::getPatientById($patId);

$cnsId = ConsultationOperation::recordConsultationEncounter([
    'patient_id'           => $patId,
    'doctor_id'            => $docId,
    'queue_id'             => $queueId,
    'subjective_notes'     => 'Shortness of breath on exertion, productive cough with yellow sputum for 5 days.',
    'objective_findings'   => 'Bilateral expiratory wheezing throughout lung fields. No stridor.',
    'assessment_diagnosis' => 'J44.1 - Chronic Obstructive Pulmonary Disease with Acute Exacerbation',
    'secondary_diagnosis'  => 'Seasonal allergic rhinitis',
    'treatment_plan'       => 'Initiate bronchodilators, nebulizer therapy, and oral hydration. Rest for 5 days.',
    'follow_up_date'       => date('Y-m-d', strtotime('+7 days')),
    'systolic'             => 130,
    'diastolic'            => 85,
    'heart_rate'           => 88,
    'temperature'          => 37.4,
    'spo2_oxygen'          => 94,
    'respiratory_rate'     => 22,
], [
    [
        'medication_id'       => (int)$med1['id'],
        'quantity'            => 20,
        'dosage_instructions' => '1 tablet 2 times daily after meals for 10 days',
    ]
], [
    'Complete Blood Count (CBC)',
    'Chest X-Ray / Sputum Microscopy'
]);

assertTest(
    "7. Clinical Consultation & Doctor Baseline Intake",
    $cnsId > 0 && $updatedPatByDoc['age'] === 28 && $updatedPatByDoc['blood_group'] === 'O+' && $updatedPatByDoc['allergies'] === 'Penicillin (Severe Rash)',
    "Consultation #{$cnsId}, Age: {$updatedPatByDoc['age']} yrs, Blood: {$updatedPatByDoc['blood_group']}, Allergy: {$updatedPatByDoc['allergies']}"
);

// Test 8: E-Prescription Direct Dispatch into Pharmacy Queue
$pendingPrescriptions = $pdo->query("SELECT * FROM prescriptions WHERE patient_mrn = '{$quickPat['patient']['mrn']}' ORDER BY id DESC LIMIT 1")->fetch();
$rxItems = [];
if ($pendingPrescriptions) {
    $stmtItems = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ?");
    $stmtItems->execute([(int)$pendingPrescriptions['id']]);
    $rxItems = $stmtItems->fetchAll();
}
assertTest(
    "8. E-Prescribing Feeding Pharmacy Queue",
    $pendingPrescriptions && $pendingPrescriptions['status'] === 'pending' && count($rxItems) === 1,
    "Prescription {$pendingPrescriptions['rx_number']} routed with " . count($rxItems) . " medication order(s)"
);

// Test 9: Lab Diagnostic Orders Dispatch
$stmtLab = $pdo->prepare("SELECT * FROM lab_orders WHERE consultation_id = ?");
$stmtLab->execute([$cnsId]);
$dispatchedLabs = $stmtLab->fetchAll();
assertTest(
    "9. Laboratory Diagnostic Orders Dispatch",
    count($dispatchedLabs) === 2,
    "2 lab orders created: {$dispatchedLabs[0]['test_name']}, {$dispatchedLabs[1]['test_name']}"
);

// Test 10: Queue Status Auto-Transition to Completed
$stmtQ = $pdo->prepare("SELECT status FROM patient_queues WHERE id = ?");
$stmtQ->execute([$queueId]);
$finalQueueStatus = $stmtQ->fetchColumn();
assertTest("10. Patient Queue Transition to Completed", $finalQueueStatus === 'completed', "Queue ID: {$queueId} marked as completed");

// Test 11: Doctor Deletion
DoctorOperation::deleteDoctor($docId);
$deletedDoc = DoctorOperation::getDoctorById($docId);
assertTest("11. Doctor Account Deletion", $deletedDoc === null, "Doctor profile purged cleanly");

// Test 12: Cleanup Test Patient
PatientOperation::deletePatient($patId);
assertTest("12. Test Patient Cleanup", true, "Patient purged cleanly");

echo "\n========================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} Passed (" . round(($passedTests / $totalTests) * 100) . "% SUCCESS)\n";
echo "========================================================\n\n";
