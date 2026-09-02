<?php
/**
 * MedCore Systems - Patient Clinical Controller
 * Handles request validation, security token verification, patient onboarding, triage vitals, and queue management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';

class PatientController
{
    /**
     * Handles new patient registration with optional inline triage vitals & queue routing.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleRegister(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $firstName = sanitizeString($post['first_name'] ?? '');
            $lastName  = sanitizeString($post['last_name'] ?? '');
            $gender    = in_array($post['gender'] ?? 'male', ['male', 'female', 'other'], true) ? $post['gender'] : 'male';
            $dob       = sanitizeString($post['dob'] ?? '');
            $phone     = sanitizeString($post['phone'] ?? '');
            $email     = sanitizeEmail($post['email'] ?? '');
            $address   = sanitizeString($post['address'] ?? '');
            $bloodGroup = sanitizeString($post['blood_group'] ?? '');
            $allergies = sanitizeString($post['allergies'] ?? 'None known');
            $medicalHistory = sanitizeString($post['medical_history'] ?? '');
            $emergencyName  = sanitizeString($post['emergency_contact_name'] ?? '');
            $emergencyPhone = sanitizeString($post['emergency_contact_phone'] ?? '');

            if (empty($firstName) || empty($lastName) || empty($phone)) {
                return ['error' => 'First name, last name, and phone number are required.'];
            }

            // 1. Register Patient
            $patientId = PatientOperation::registerPatient([
                'first_name'              => $firstName,
                'last_name'               => $lastName,
                'gender'                  => $gender,
                'dob'                     => $dob,
                'phone'                   => $phone,
                'email'                   => $email,
                'address'                 => $address,
                'blood_group'             => $bloodGroup,
                'allergies'               => $allergies,
                'medical_history'         => $medicalHistory,
                'emergency_contact_name'  => $emergencyName,
                'emergency_contact_phone' => $emergencyPhone,
                'registered_by'           => $userId,
            ]);

            $patient = PatientOperation::getPatientById($patientId);

            // 2. Optional: Record Initial Triage Vitals if provided
            if (!empty($post['systolic']) || !empty($post['temperature']) || !empty($post['chief_complaint'])) {
                PatientOperation::recordVitals([
                    'patient_id'       => $patientId,
                    'systolic'         => !empty($post['systolic']) ? (int)$post['systolic'] : null,
                    'diastolic'        => !empty($post['diastolic']) ? (int)$post['diastolic'] : null,
                    'heart_rate'       => !empty($post['heart_rate']) ? (int)$post['heart_rate'] : null,
                    'temperature'      => !empty($post['temperature']) ? (float)$post['temperature'] : null,
                    'respiratory_rate' => !empty($post['respiratory_rate']) ? (int)$post['respiratory_rate'] : null,
                    'spo2_oxygen'      => !empty($post['spo2_oxygen']) ? (int)$post['spo2_oxygen'] : null,
                    'weight_kg'        => !empty($post['weight_kg']) ? (float)$post['weight_kg'] : null,
                    'height_cm'        => !empty($post['height_cm']) ? (float)$post['height_cm'] : null,
                    'chief_complaint'  => sanitizeString($post['chief_complaint'] ?? ''),
                    'triage_level'     => $post['triage_level'] ?? 'routine',
                    'recorded_by'      => $userId,
                ]);
            }

            // 3. Optional: Route to Doctor Queue if requested
            if (!empty($post['route_to_queue'])) {
                $doctorId   = !empty($post['doctor_id']) ? (int)$post['doctor_id'] : null;
                $department = sanitizeString($post['department'] ?? 'General OPD');
                $priority   = $post['priority'] ?? 'normal';
                PatientOperation::addToQueue($patientId, $doctorId, $department, $priority, $userId);
            }

            setFlashMessage('success', sprintf('Patient "%s %s" registered successfully with MRN: %s', $firstName, $lastName, $patient['mrn']));
            safeRedirect("patient_profile_michael_chen.php?id={$patientId}");
            return null;

        } catch (Exception $e) {
            error_log('[HPMS PATIENT REGISTER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles recording vitals for an existing patient.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleRecordVitals(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $patientId = (int)($post['patient_id'] ?? 0);
            if ($patientId <= 0) {
                return ['error' => 'Invalid patient specified for vitals recording.'];
            }

            PatientOperation::recordVitals([
                'patient_id'       => $patientId,
                'systolic'         => !empty($post['systolic']) ? (int)$post['systolic'] : null,
                'diastolic'        => !empty($post['diastolic']) ? (int)$post['diastolic'] : null,
                'heart_rate'       => !empty($post['heart_rate']) ? (int)$post['heart_rate'] : null,
                'temperature'      => !empty($post['temperature']) ? (float)$post['temperature'] : null,
                'respiratory_rate' => !empty($post['respiratory_rate']) ? (int)$post['respiratory_rate'] : null,
                'spo2_oxygen'      => !empty($post['spo2_oxygen']) ? (int)$post['spo2_oxygen'] : null,
                'weight_kg'        => !empty($post['weight_kg']) ? (float)$post['weight_kg'] : null,
                'height_cm'        => !empty($post['height_cm']) ? (float)$post['height_cm'] : null,
                'chief_complaint'  => sanitizeString($post['chief_complaint'] ?? ''),
                'triage_level'     => $post['triage_level'] ?? 'routine',
                'recorded_by'      => $userId,
            ]);

            setFlashMessage('success', 'Clinical vitals recorded successfully.');
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : "patient_profile_michael_chen.php?id={$patientId}";
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS VITALS ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles adding an existing patient to the doctor waiting queue.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleQueuePatient(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        if (($currentUser['role'] ?? '') === ROLE_DOCTOR) {
            return ['error' => 'Doctors are not authorized to route or transfer patients. Please direct the patient to Reception for doctor reassignment.'];
        }

        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $patientId  = (int)($post['patient_id'] ?? 0);
            $doctorId   = !empty($post['doctor_id']) ? (int)$post['doctor_id'] : null;
            $department = sanitizeString($post['department'] ?? 'General OPD');
            $priority   = $post['priority'] ?? 'normal';

            if ($patientId <= 0) {
                return ['error' => 'Invalid patient selected for queue.'];
            }

            $queueId = PatientOperation::addToQueue($patientId, $doctorId, $department, $priority, $userId);

            setFlashMessage('success', 'Patient queue routed successfully.');
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'queue_management.php';
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS QUEUE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles rapid front-desk patient intake & instant doctor queue token issuance.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleQuickCheckIn(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $firstName = sanitizeString($post['first_name'] ?? '');
            $lastName  = sanitizeString($post['last_name'] ?? '');
            $phone     = sanitizeString($post['phone'] ?? '');
            $gender    = in_array($post['gender'] ?? 'male', ['male', 'female', 'other'], true) ? $post['gender'] : 'male';
            $doctorId  = !empty($post['doctor_id']) ? (int)$post['doctor_id'] : null;
            $department = sanitizeString($post['department'] ?? 'General OPD');
            $priority   = $post['priority'] ?? 'normal';
            $complaint  = sanitizeString($post['chief_complaint'] ?? '');
            $fee        = isset($post['consultation_fee']) && $post['consultation_fee'] !== '' ? max(0.0, (float)$post['consultation_fee']) : null;

            if (empty($firstName) || empty($phone)) {
                return ['error' => 'Patient name and phone number are required.'];
            }

            $result = PatientOperation::quickReceptionCheckIn([
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'phone'             => $phone,
                'gender'            => $gender,
                'doctor_id'         => $doctorId,
                'department'        => $department,
                'priority'          => $priority,
                'chief_complaint'   => $complaint,
                'consultation_fee'  => $fee,
                'user_id'           => $userId,
            ]);

            $pName     = $result['patient']['full_name'];
            $token     = $result['token_number'];
            $mrn       = $result['patient']['mrn'];
            $pos       = $result['queue_position'];
            $invoiceId = (int)($result['invoice_id'] ?? 0);

            setFlashMessage('success', sprintf(
                'Token "%s" generated for %s (MRN: %s). Consultation bill generated — please collect payment at Cashier desk before issuing token slip.',
                $token,
                $pName,
                $mrn
            ));

            if (!empty($post['redirect'])) {
                $redirectUrl = $post['redirect'];
            } elseif ($invoiceId > 0) {
                $redirectUrl = "billing_payments.php?invoice_id={$invoiceId}";
            } else {
                $redirectUrl = 'billing_payments.php';
            }

            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return $result;

        } catch (Exception $e) {
            error_log('[HPMS QUICK INTAKE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing patient details.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleEditPatient(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $patientId = (int)($post['patient_id'] ?? 0);
            if ($patientId <= 0) {
                return ['error' => 'Invalid patient specified for editing.'];
            }

            PatientOperation::updatePatient($patientId, [
                'first_name'              => sanitizeString($post['first_name'] ?? ''),
                'last_name'               => sanitizeString($post['last_name'] ?? ''),
                'gender'                  => $post['gender'] ?? 'male',
                'dob'                     => $post['dob'] ?? date('Y-m-d', strtotime('-30 years')),
                'phone'                   => sanitizeString($post['phone'] ?? ''),
                'email'                   => sanitizeEmail($post['email'] ?? ''),
                'address'                 => sanitizeString($post['address'] ?? ''),
                'blood_group'             => sanitizeString($post['blood_group'] ?? ''),
                'allergies'               => sanitizeString($post['allergies'] ?? 'None known'),
                'medical_history'         => sanitizeString($post['medical_history'] ?? ''),
                'emergency_contact_name'  => sanitizeString($post['emergency_contact_name'] ?? ''),
                'emergency_contact_phone' => sanitizeString($post['emergency_contact_phone'] ?? ''),
            ]);

            setFlashMessage('success', 'Patient record updated successfully.');
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : "patient_profile_michael_chen.php?id={$patientId}";
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS EDIT PATIENT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting a patient record.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeletePatient(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $patientId = (int)($post['patient_id'] ?? 0);
            if ($patientId <= 0) {
                return ['error' => 'Invalid patient specified for deletion.'];
            }

            PatientOperation::deletePatient($patientId);

            setFlashMessage('success', 'Patient record removed successfully.');
            safeRedirect('patient_registration.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS DELETE PATIENT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles bulk deletion of multiple selected patients.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleBulkDeletePatients(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $patientIds = $post['patient_ids'] ?? [];
            if (!is_array($patientIds) || empty($patientIds)) {
                return ['error' => 'No patients selected for deletion.'];
            }

            $deletedCount = PatientOperation::bulkDeletePatients($patientIds);

            setFlashMessage('success', sprintf('%d %s deleted successfully.', $deletedCount, $deletedCount === 1 ? 'patient' : 'patients'));
            safeRedirect('patient_registration.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS BULK DELETE PATIENT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing a queue assignment.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleEditQueueItem(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $queueId = (int)($post['queue_id'] ?? 0);
            if ($queueId <= 0) {
                return ['error' => 'Invalid queue item specified.'];
            }

            $currentUser = getCurrentUser();
            $userId      = (int)($currentUser['id'] ?? 1);
            $newDoctorId = !empty($post['doctor_id']) ? (int)$post['doctor_id'] : null;
            $department  = sanitizeString($post['department'] ?? 'General OPD');
            $priority    = $post['priority'] ?? 'normal';
            $overpayAct  = in_array($post['overpayment_action'] ?? '', ['credit', 'refund'], true) ? $post['overpayment_action'] : 'credit';

            // Find patient id for this queue item
            $pdo = getDBConnection();
            $stmtQ = $pdo->prepare("SELECT patient_id FROM patient_queues WHERE id = ?");
            $stmtQ->execute([$queueId]);
            $patientId = (int)$stmtQ->fetchColumn();

            // 1. Update queue item
            PatientOperation::updateQueueItem($queueId, [
                'doctor_id'  => $newDoctorId,
                'department' => $department,
                'priority'   => $priority,
            ]);

            // 2. Adjust consultation fee & invoice
            require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';
            $adjResult = BillingOperation::adjustConsultationFeeOnReassignment(
                $queueId,
                $patientId,
                $newDoctorId,
                $overpayAct,
                $userId
            );

            // Construct informative notification
            $msg = 'Queue assignment updated successfully.';
            if ($adjResult['action'] === 'upgrade_partial') {
                $msg .= sprintf(' Fee upgraded to $%.2f. Balance due of $%.2f sent to Billing.', $adjResult['new_fee'], $adjResult['balance_due']);
            } elseif ($adjResult['action'] === 'downgrade_credit') {
                $msg .= sprintf(' Fee adjusted to $%.2f. Overpayment of $%.2f credited to Patient Account Balance.', $adjResult['new_fee'], $adjResult['overpayment']);
            } elseif ($adjResult['action'] === 'downgrade_refund') {
                $msg .= sprintf(' Fee adjusted to $%.2f. Cash Refund Voucher #%s issued for $%.2f.', $adjResult['new_fee'], $adjResult['voucher_number'], $adjResult['overpayment']);
                $_SESSION['hpms_last_refund_voucher'] = $adjResult['voucher_id'];
            } elseif ($adjResult['action'] === 'pending_updated') {
                $msg .= sprintf(' Consultation fee updated to $%.2f (pending cashier payment).', $adjResult['new_fee']);
            }

            setFlashMessage('success', $msg);
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'queue_management.php';
            safeRedirect($redirectUrl);
            return $adjResult;

        } catch (Exception $e) {
            error_log('[HPMS EDIT QUEUE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Converts a patient's existing account_credit balance into an official Cash Refund Voucher.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleRefundPatientCredit(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $queueId = (int)($post['queue_id'] ?? 0);
            if ($queueId <= 0) {
                return ['error' => 'Invalid queue item specified.'];
            }

            $currentUser = getCurrentUser();
            $userId = (int)($currentUser['id'] ?? 1);

            $pdo = getDBConnection();
            $stmtQ = $pdo->prepare("
                SELECT q.*, p.id as patient_id, p.first_name, p.last_name, p.account_credit 
                FROM patient_queues q 
                JOIN patients p ON q.patient_id = p.id 
                WHERE q.id = ?
            ");
            $stmtQ->execute([$queueId]);
            $qData = $stmtQ->fetch();
            if (!$qData) {
                return ['error' => 'Queue item not found.'];
            }

            $credit = (float)($qData['account_credit'] ?? 0.00);
            if ($credit <= 0.001) {
                return ['error' => 'This patient has no available credit balance to cash out.'];
            }

            $patientId = (int)$qData['patient_id'];

            // Find related consultation invoice if any
            $stmtInv = $pdo->prepare("
                SELECT id FROM invoices 
                WHERE queue_id = ? AND bill_type = 'consultation' 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtInv->execute([$queueId]);
            $invId = $stmtInv->fetchColumn() ?: null;

            // Generate Voucher
            $voucherNumber = 'RV-' . date('Ymd') . '-' . str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
            $stmtV = $pdo->prepare("
                INSERT INTO refund_vouchers (voucher_number, patient_id, invoice_id, queue_id, amount, refund_type, reason, issued_by)
                VALUES (:vnum, :pid, :invid, :qid, :amt, 'cash', 'Credit Balance Cash Out', :uid)
            ");
            $stmtV->execute([
                ':vnum'  => $voucherNumber,
                ':pid'   => $patientId,
                ':invid' => $invId,
                ':qid'   => $queueId,
                ':amt'   => $credit,
                ':uid'   => $userId,
            ]);
            $voucherId = (int)$pdo->lastInsertId();

            // Deduct patient account_credit
            $pdo->prepare("UPDATE patients SET account_credit = 0.00 WHERE id = ?")->execute([$patientId]);

            // Record GL double entry
            require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
            AccountingOperation::seedChartOfAccountsIfEmpty();
            $revAcc  = AccountingOperation::getAccountByCode('4020'); // Consultation Revenue
            $cashAcc = AccountingOperation::getAccountByCode('1010'); // Cash on Hand
            if ($revAcc && $cashAcc) {
                AccountingOperation::recordJournalEntry(
                    date('Y-m-d'),
                    'consultation_fee',
                    $invId ? (int)$invId : $voucherId,
                    "Cash refund voucher #{$voucherNumber} disbursed ($" . number_format($credit, 2) . ")",
                    [
                        ['account_id' => (int)$revAcc['id'],  'debit' => $credit, 'credit' => 0.00, 'memo' => "Patient Credit Cash Refund - {$voucherNumber}"],
                        ['account_id' => (int)$cashAcc['id'], 'debit' => 0.00, 'credit' => $credit, 'memo' => "Cash refund disbursed to patient"],
                    ],
                    $userId
                );
            }

            $_SESSION['hpms_last_refund_voucher'] = $voucherId;
            setFlashMessage('success', sprintf('Cash Refund Voucher #%s generated for $%.2f. Cash successfully disbursed from drawer.', $voucherNumber, $credit));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'queue_management.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['voucher_id' => $voucherId];

        } catch (Exception $e) {
            error_log('[HPMS REFUND CREDIT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting/cancelling an item from the queue.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteQueueItem(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $queueId = (int)($post['queue_id'] ?? 0);
            if ($queueId <= 0) {
                return ['error' => 'Invalid queue item specified.'];
            }

            PatientOperation::deleteQueueItem($queueId);

            setFlashMessage('success', 'Patient removed from queue successfully.');
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'reception.php';
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS DELETE QUEUE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles transitioning queue status (waiting -> in_consultation -> completed -> cancelled).
     *
     * @param array $post
     * @return array|null
     */
    public static function handleUpdateQueueStatus(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $queueId = (int)($post['queue_id'] ?? 0);
            $status  = sanitizeString($post['status'] ?? '');

            if ($queueId <= 0 || !in_array($status, ['waiting', 'in_consultation', 'completed', 'cancelled'], true)) {
                return ['error' => 'Invalid queue item or status specified.'];
            }

            PatientOperation::updateQueueStatus($queueId, $status);

            setFlashMessage('success', sprintf('Patient queue status updated to "%s".', ucfirst(str_replace('_', ' ', $status))));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'queue_management.php';
            safeRedirect($redirectUrl);
            return null;

        } catch (Exception $e) {
            error_log('[HPMS UPDATE QUEUE STATUS ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}

