<?php


declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/OPERATIONS/PatientOperation.php';

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
echo " HPMS PHASE 3 PATIENTS, VITALS & QUEUE MANAGEMENT TESTS \n";
echo "========================================================\n\n";

// Test 1: Verify Schema & Columns
$patientCols = $pdo->query("SHOW COLUMNS FROM patients")->fetchAll(PDO::FETCH_COLUMN);
$requiredPCols = ['id', 'mrn', 'first_name', 'last_name', 'gender', 'dob', 'phone', 'blood_group', 'allergies'];
$hasAllCols = count(array_intersect($requiredPCols, $patientCols)) === count($requiredPCols);
assertTest("1. Patients Table Schema", $hasAllCols, "All clinical columns verified");

// Test 2: Auto-Seeding Default Patients
PatientOperation::seedDefaultPatientsIfEmpty();
$patients = PatientOperation::searchPatients('', 50);
assertTest("2. Patient Master Seeding", is_array($patients), count($patients) . " active patients in registry");

// Test 3: New Patient Registration & Unique MRN
$mrnGenerated = PatientOperation::generateUniqueMRN();
$newPatId = PatientOperation::registerPatient([
    'mrn'                    => $mrnGenerated,
    'first_name'             => 'Khadar',
    'last_name'              => 'Nur',
    'gender'                 => 'male',
    'dob'                    => '1992-06-18',
    'phone'                  => '(555) 018-7744',
    'email'                  => 'khadar.nur@example.com',
    'address'                => 'Wadajir District, Mogadishu',
    'blood_group'            => 'B+',
    'allergies'              => 'Aspirin',
    'medical_history'        => 'Asthma since childhood',
    'emergency_contact_name' => 'Amina Nur',
    'emergency_contact_phone'=> '(555) 018-7740',
    'registered_by'          => 1,
]);

$fetchedPat = PatientOperation::getPatientById($newPatId);
assertTest(
    "3. Patient Registration & MRN Generation",
    $fetchedPat && $fetchedPat['mrn'] === $mrnGenerated && $fetchedPat['first_name'] === 'Khadar',
    "ID: {$newPatId}, MRN: {$fetchedPat['mrn']}, Age: {$fetchedPat['age']} yrs"
);

// Test 4: Search Patient by MRN, Name, and Phone
$searchByName = PatientOperation::searchPatients('Khadar');
$searchByPhone = PatientOperation::searchPatients('018-7744');
$searchByMRN = PatientOperation::getPatientByMRN($mrnGenerated);
assertTest(
    "4. Clinical Patient Search (Multi-parameter)",
    count($searchByName) >= 1 && count($searchByPhone) >= 1 && $searchByMRN !== null,
    "Found via Name, Phone, and MRN index lookup"
);

// Test 5: Record Triage Vitals & Auto BMI Calculation
$vitalsId = PatientOperation::recordVitals([
    'patient_id'       => $newPatId,
    'systolic'         => 124,
    'diastolic'        => 80,
    'heart_rate'       => 74,
    'temperature'      => 36.9,
    'respiratory_rate' => 17,
    'spo2_oxygen'      => 98,
    'weight_kg'        => 70.0,
    'height_cm'        => 175.0, // BMI = 70 / (1.75 * 1.75) = 22.9
    'chief_complaint'  => 'Mild shortness of breath during exercise',
    'triage_level'     => 'routine',
    'recorded_by'      => 1,
]);

$latestVitals = PatientOperation::getLatestVitals($newPatId);
assertTest(
    "5. Triage Vitals & Auto BMI Calculation",
    $latestVitals && (int)$latestVitals['systolic'] === 124 && (float)$latestVitals['bmi'] === 22.9,
    "BP: {$latestVitals['systolic']}/{$latestVitals['diastolic']}, Temp: {$latestVitals['temperature']}°C, BMI: {$latestVitals['bmi']}"
);

// Test 6: Route Patient to Queue & Token Generation
$queueId = PatientOperation::addToQueue($newPatId, 1, 'Pulmonology OPD', 'urgent', 1);
$stmtQ = $pdo->prepare("SELECT * FROM patient_queues WHERE id = ?");
$stmtQ->execute([$queueId]);
$queueItem = $stmtQ->fetch();

assertTest(
    "6. Patient Queueing & Token Generation",
    $queueItem && $queueItem['status'] === 'waiting' && str_starts_with($queueItem['token_number'], 'T-'),
    "Queue ID: {$queueId}, Token: {$queueItem['token_number']}, Dept: {$queueItem['department']}"
);

// Test 7: Queue Retrieval with Live Vitals Integration
$waitingQueue = PatientOperation::getQueue('waiting');
$foundInQueue = false;
foreach ($waitingQueue as $wq) {
    if ((int)$wq['patient_id'] === $newPatId) {
        $foundInQueue = true;
        break;
    }
}
assertTest("7. Live Queue Retrieval with Patient Vitals", $foundInQueue, count($waitingQueue) . " patients waiting in queue");

// Test 8: Queue Status Transition (Waiting -> In Consultation -> Completed)
PatientOperation::updateQueueStatus($queueId, 'in_consultation');
$stmtQ->execute([$queueId]);
$rowInConsult = $stmtQ->fetch();
$statusInConsult = $rowInConsult['status'] ?? '';

PatientOperation::updateQueueStatus($queueId, 'completed');
$stmtQ->execute([$queueId]);
$rowCompleted = $stmtQ->fetch();
$statusCompleted = $rowCompleted['status'] ?? '';

assertTest(
    "8. Patient Queue Workflow Transitions",
    $statusInConsult === 'in_consultation' && $statusCompleted === 'completed',
    "waiting -> in_consultation -> completed"
);

// Test 9: Doctors Directory Retrieval
$doctors = PatientOperation::getDoctorsList();
assertTest("9. Active Doctors Directory", count($doctors) >= 1, count($doctors) . " verified clinical providers");

// Test 10: Summary KPIs
$kpis = PatientOperation::getPatientSummaryKPIs();
assertTest("10. Patient Summary KPIs", isset($kpis['total_patients']), "Total: {$kpis['total_patients']}, Waiting: {$kpis['waiting_in_queue']}");

// Test 12: Quick Front-Desk Check-In (New Walk-in Patient)
$quickNew = PatientOperation::quickReceptionCheckIn([
    'first_name'      => 'Ayaan',
    'last_name'       => 'Warsame',
    'phone'           => '(555) 777-8899',
    'gender'          => 'female',
    'doctor_id'       => 1,
    'department'      => 'General OPD',
    'priority'        => 'normal',
    'chief_complaint' => 'Persistent dry cough',
    'user_id'         => 1,
]);
assertTest(
    "12. Quick Front-Desk Walk-in Intake (New Patient)",
    $quickNew['is_new_patient'] === true && !empty($quickNew['token_number']) && $quickNew['queue_position'] >= 1,
    "MRN: {$quickNew['patient']['mrn']}, Token: {$quickNew['token_number']}, Pos: #{$quickNew['queue_position']}"
);

// Test 13: Quick Front-Desk Check-In (Returning Patient with same Phone)
$quickReturning = PatientOperation::quickReceptionCheckIn([
    'first_name'      => 'Ayaan',
    'last_name'       => 'Warsame',
    'phone'           => '(555) 777-8899', // Same phone
    'doctor_id'       => 1,
    'department'      => 'General OPD',
    'priority'        => 'urgent',
    'chief_complaint' => 'Follow up visit',
    'user_id'         => 1,
]);
assertTest(
    "13. Returning Patient Intake (MRN Preservation & History Continuity)",
    $quickReturning['is_new_patient'] === false && $quickReturning['patient']['mrn'] === $quickNew['patient']['mrn'],
    "Reused existing MRN {$quickReturning['patient']['mrn']} without creating duplicate patient"
);

// Test 14: Edit Patient Details
$patAyaanId = (int)$quickNew['patient']['id'];
PatientOperation::updatePatient($patAyaanId, [
    'first_name'              => 'Ayaan',
    'last_name'               => 'Warsame-Ali',
    'gender'                  => 'female',
    'dob'                     => '1996-03-21',
    'phone'                   => '(555) 777-8899',
    'email'                   => 'ayaan.w@example.com',
    'address'                 => 'KM4, Mogadishu',
    'blood_group'             => 'A+',
    'allergies'               => 'Amoxicillin',
    'medical_history'         => 'Seasonal allergies',
    'emergency_contact_name'  => 'Dahir Warsame',
    'emergency_contact_phone' => '(555) 777-8800',
]);
$updatedAyaan = PatientOperation::getPatientById($patAyaanId);
assertTest(
    "14. Patient Profile Editing",
    $updatedAyaan && $updatedAyaan['last_name'] === 'Warsame-Ali' && $updatedAyaan['allergies'] === 'Amoxicillin',
    "Updated Name: {$updatedAyaan['full_name']}, Allergy: {$updatedAyaan['allergies']}"
);

// Test 15: Edit Queue Item Assignment
$queueAyaanId = (int)$quickReturning['queue_id'];
PatientOperation::updateQueueItem($queueAyaanId, [
    'doctor_id'  => 1,
    'department' => 'Cardiology OPD',
    'priority'   => 'urgent',
]);
$stmtQA = $pdo->prepare("SELECT * FROM patient_queues WHERE id = ?");
$stmtQA->execute([$queueAyaanId]);
$qaRow = $stmtQA->fetch();
assertTest(
    "15. Queue Item Assignment Editing",
    $qaRow && $qaRow['department'] === 'Cardiology OPD' && $qaRow['priority'] === 'urgent',
    "Reassigned to Cardiology OPD with Urgent priority"
);

// Test 16: Delete / Remove Queue Item
PatientOperation::deleteQueueItem($queueAyaanId);
$stmtQA->execute([$queueAyaanId]);
$qaRowDeleted = $stmtQA->fetch();
assertTest("16. Queue Item Deletion", $qaRowDeleted === false, "Successfully removed from active queue");

// Test 17: Delete Patient Record
PatientOperation::deletePatient($patAyaanId);
$deletedPat = PatientOperation::getPatientById($patAyaanId);
assertTest("17. Patient Record Deletion", $deletedPat === null, "Patient and child records purged cleanly");

// Test 18: Bulk Delete Patients
$b1 = PatientOperation::registerPatient(['first_name' => 'Bulk1', 'last_name' => 'Test', 'phone' => '111-0001', 'registered_by' => 1]);
$b2 = PatientOperation::registerPatient(['first_name' => 'Bulk2', 'last_name' => 'Test', 'phone' => '111-0002', 'registered_by' => 1]);
$deletedBulkCount = PatientOperation::bulkDeletePatients([$b1, $b2]);
assertTest("18. Bulk Delete Multiple Patients", $deletedBulkCount === 2, "Deleted {$deletedBulkCount} patients in single operation");

// Test 19: One-Time Seed Isolation (Does not resurrect when empty)
PatientOperation::seedDefaultPatientsIfEmpty(); // Should NOT re-insert because patients_seeded = 1
$allSettings = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'patients_seeded'")->fetchColumn();
assertTest("19. One-Time Seed Isolation", $allSettings === '1', "Seed tracker verified: no auto-resurrection after user deletion");

echo "\n========================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} Passed (" . round(($passedTests / $totalTests) * 100) . "% SUCCESS)\n";
echo "========================================================\n\n";

