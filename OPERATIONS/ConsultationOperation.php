<?php
/**
 * MedCore Systems - Clinical Consultation & Encounter Engine
 * Handles doctor clinical documentation (SOAP notes, diagnoses), E-Prescribing, and Lab ordering.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/PatientOperation.php';

class ConsultationOperation
{
    /**
     * Generates a unique Consultation Encounter Number.
     *
     * @return string
     */
    public static function generateConsultationNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');

        do {
            $randomDigits = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $num = "CNS-{$year}-{$randomDigits}";

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE consultation_number = ?");
            $stmt->execute([$num]);
            $exists = (int)$stmt->fetchColumn() > 0;
        } while ($exists);

        return $num;
    }

    /**
     * Generates a unique Lab Order Number.
     *
     * @return string
     */
    public static function generateLabOrderNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');

        do {
            $randomDigits = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $num = "LAB-{$year}-{$randomDigits}";

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE order_number = ?");
            $stmt->execute([$num]);
            $exists = (int)$stmt->fetchColumn() > 0;
        } while ($exists);

        return $num;
    }

    /**
     * Finds an active draft consultation for a patient / queue encounter.
     *
     * @param int $patientId
     * @param int|null $queueId
     * @return array|null
     */
    public static function getActiveDraftConsultation(int $patientId, ?int $queueId = null): ?array
    {
        $pdo = getDBConnection();
        if ($queueId) {
            $stmt = $pdo->prepare("
                SELECT * FROM consultations 
                WHERE patient_id = :pat_id AND queue_id = :q_id AND status = 'draft'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':pat_id' => $patientId, ':q_id' => $queueId]);
            $draft = $stmt->fetch();
            if ($draft) {
                return $draft;
            }
        }

        $stmt = $pdo->prepare("
            SELECT * FROM consultations 
            WHERE patient_id = :pat_id AND status = 'draft' AND DATE(created_at) = CURDATE()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':pat_id' => $patientId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Saves or updates a draft consultation encounter when doctor orders lab tests or temporarily saves.
     *
     * @param array $encounterData
     * @return int Draft Consultation ID
     */
    public static function saveDraftConsultation(array $encounterData): int
    {
        $pdo = getDBConnection();
        $patientId = (int)$encounterData['patient_id'];
        $doctorId  = (int)($encounterData['doctor_id'] ?? 1);
        $queueId   = !empty($encounterData['queue_id']) ? (int)$encounterData['queue_id'] : null;

        $subjective    = trim($encounterData['subjective_notes'] ?? '');
        $objective     = trim($encounterData['objective_findings'] ?? '');
        $assessment    = trim($encounterData['assessment_diagnosis'] ?? 'Under Diagnostic & Lab Investigation');
        $secondary     = !empty($encounterData['secondary_diagnosis']) ? trim($encounterData['secondary_diagnosis']) : null;
        $treatmentPlan = trim($encounterData['treatment_plan'] ?? '');
        $followUpDate  = !empty($encounterData['follow_up_date']) ? $encounterData['follow_up_date'] : null;

        // Check if an existing draft exists for this queue/patient
        $existingDraft = self::getActiveDraftConsultation($patientId, $queueId);

        if ($existingDraft) {
            $cnsId = (int)$existingDraft['id'];
            $stmtUp = $pdo->prepare("
                UPDATE consultations
                SET doctor_id = :doc_id,
                    subjective_notes = :subjective,
                    objective_findings = :objective,
                    assessment_diagnosis = :assessment,
                    secondary_diagnosis = :secondary,
                    treatment_plan = :plan,
                    follow_up_date = :follow_up
                WHERE id = :id
            ");
            $stmtUp->execute([
                ':doc_id'     => $doctorId,
                ':subjective' => $subjective,
                ':objective'  => $objective,
                ':assessment' => $assessment,
                ':secondary'  => $secondary,
                ':plan'       => $treatmentPlan,
                ':follow_up'  => $followUpDate,
                ':id'         => $cnsId,
            ]);
            return $cnsId;
        }

        $cnsNumber = self::generateConsultationNumber();
        $stmtIns = $pdo->prepare("
            INSERT INTO consultations (consultation_number, patient_id, doctor_id, queue_id, subjective_notes, objective_findings, assessment_diagnosis, secondary_diagnosis, treatment_plan, follow_up_date, status)
            VALUES (:cns_num, :patient_id, :doctor_id, :queue_id, :subjective, :objective, :assessment, :secondary, :plan, :follow_up, 'draft')
        ");
        $stmtIns->execute([
            ':cns_num'    => $cnsNumber,
            ':patient_id' => $patientId,
            ':doctor_id'  => $doctorId,
            ':queue_id'   => $queueId,
            ':subjective' => $subjective,
            ':objective'  => $objective,
            ':assessment' => $assessment,
            ':secondary'  => $secondary,
            ':plan'       => $treatmentPlan,
            ':follow_up'  => $followUpDate,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Saves a complete doctor consultation encounter:
     * 1. Records clinical SOAP notes & diagnosis in `consultations`.
     * 2. Records exam vitals in `patient_vitals`.
     * 3. Generates E-Prescription feeding the Pharmacy Dispensing Queue (`prescriptions` & `prescription_items`).
     * 4. Generates Lab Test Orders (`lab_orders`).
     * 5. Updates Patient Queue status to 'completed'.
     *
     * @param array $encounterData
     * @param array $prescriptionItems
     * @param array $labTestNames
     * @return int Created Consultation ID
     */
    public static function recordConsultationEncounter(array $encounterData, array $prescriptionItems = [], array $labTestNames = []): int
    {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $patientId = (int)$encounterData['patient_id'];
            $doctorId  = (int)$encounterData['doctor_id'];
            $queueId   = !empty($encounterData['queue_id']) ? (int)$encounterData['queue_id'] : null;

            $patient = PatientOperation::getPatientById($patientId);
            if (!$patient) {
                throw new InvalidArgumentException('Patient record not found.');
            }

            $stmtDoc = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmtDoc->execute([$doctorId]);
            $doctorName = $stmtDoc->fetchColumn() ?: 'Attending Physician';

            $cnsNumber = self::generateConsultationNumber();
            $subjective = trim($encounterData['subjective_notes'] ?? '');
            $objective  = trim($encounterData['objective_findings'] ?? '');
            $assessment = trim($encounterData['assessment_diagnosis'] ?? 'General Consultation / Clinical Assessment');
            $secondary  = !empty($encounterData['secondary_diagnosis']) ? trim($encounterData['secondary_diagnosis']) : null;
            $treatmentPlan = trim($encounterData['treatment_plan'] ?? '');
            $followUpDate = !empty($encounterData['follow_up_date']) ? $encounterData['follow_up_date'] : null;

            // Check if draft already exists for this encounter
            $existingDraft = self::getActiveDraftConsultation($patientId, $queueId);

            if ($existingDraft) {
                $consultationId = (int)$existingDraft['id'];
                $cnsNumber = $existingDraft['consultation_number'];
                $stmtCns = $pdo->prepare("
                    UPDATE consultations 
                    SET doctor_id = :doctor_id,
                        subjective_notes = :subjective,
                        objective_findings = :objective,
                        assessment_diagnosis = :assessment,
                        secondary_diagnosis = :secondary,
                        treatment_plan = :plan,
                        follow_up_date = :follow_up,
                        status = 'completed'
                    WHERE id = :id
                ");
                $stmtCns->execute([
                    ':doctor_id'  => $doctorId,
                    ':subjective' => $subjective,
                    ':objective'  => $objective,
                    ':assessment' => $assessment,
                    ':secondary'  => $secondary,
                    ':plan'       => $treatmentPlan,
                    ':follow_up'  => $followUpDate,
                    ':id'         => $consultationId,
                ]);
            } else {
                // 1. Insert Consultation Record
                $stmtCns = $pdo->prepare("
                    INSERT INTO consultations (consultation_number, patient_id, doctor_id, queue_id, subjective_notes, objective_findings, assessment_diagnosis, secondary_diagnosis, treatment_plan, follow_up_date, status)
                    VALUES (:cns_num, :patient_id, :doctor_id, :queue_id, :subjective, :objective, :assessment, :secondary, :plan, :follow_up, 'completed')
                ");

                $stmtCns->execute([
                    ':cns_num'    => $cnsNumber,
                    ':patient_id' => $patientId,
                    ':doctor_id'  => $doctorId,
                    ':queue_id'   => $queueId,
                    ':subjective' => $subjective,
                    ':objective'  => $objective,
                    ':assessment' => $assessment,
                    ':secondary'  => $secondary,
                    ':plan'       => $treatmentPlan,
                    ':follow_up'  => $followUpDate,
                ]);

                $consultationId = (int)$pdo->lastInsertId();
            }

            // 2. Record Vitals if provided
            if (!empty($encounterData['systolic']) || !empty($encounterData['temperature']) || !empty($encounterData['heart_rate'])) {
                PatientOperation::recordVitals([
                    'patient_id'       => $patientId,
                    'systolic'         => !empty($encounterData['systolic']) ? (int)$encounterData['systolic'] : null,
                    'diastolic'        => !empty($encounterData['diastolic']) ? (int)$encounterData['diastolic'] : null,
                    'heart_rate'       => !empty($encounterData['heart_rate']) ? (int)$encounterData['heart_rate'] : null,
                    'temperature'      => !empty($encounterData['temperature']) ? (float)$encounterData['temperature'] : null,
                    'respiratory_rate' => !empty($encounterData['respiratory_rate']) ? (int)$encounterData['respiratory_rate'] : null,
                    'spo2_oxygen'      => !empty($encounterData['spo2_oxygen']) ? (int)$encounterData['spo2_oxygen'] : null,
                    'weight_kg'        => !empty($encounterData['weight_kg']) ? (float)$encounterData['weight_kg'] : null,
                    'height_cm'        => !empty($encounterData['height_cm']) ? (float)$encounterData['height_cm'] : null,
                    'chief_complaint'  => $subjective ?: 'Doctor consultation exam',
                    'triage_level'     => 'routine',
                    'recorded_by'      => $doctorId,
                ]);
            }

            // 3. Generate E-Prescription if medications prescribed
            if (!empty($prescriptionItems)) {
                $year = date('Y');
                $rxNumber = "RX-{$year}-" . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                $allergyAlert = (!empty($patient['allergies']) && strtolower($patient['allergies']) !== 'none known') ? $patient['allergies'] : null;

                $stmtRx = $pdo->prepare("
                    INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes)
                    VALUES (:rx_num, :pat_name, :pat_mrn, :doc_name, 'pending', :allergy, :notes)
                ");

                $stmtRx->execute([
                    ':rx_num'   => $rxNumber,
                    ':pat_name' => $patient['full_name'],
                    ':pat_mrn'  => $patient['mrn'],
                    ':doc_name' => $doctorName,
                    ':allergy'  => $allergyAlert,
                    ':notes'    => 'Prescribed during consultation ' . $cnsNumber,
                ]);

                $rxId = (int)$pdo->lastInsertId();

                $stmtItem = $pdo->prepare("
                    INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price)
                    VALUES (:rx_id, :med_id, :dosage, :qty_pres, 0, :qty_rem, :qty, :unit_price, :total_price)
                ");

                $stmtMed = $pdo->prepare("SELECT unit_price FROM medications WHERE id = ?");

                foreach ($prescriptionItems as $item) {
                    $medId = (int)$item['medication_id'];
                    $qty   = max(1, (int)($item['quantity'] ?? 1));
                    $dosage = trim($item['dosage_instructions'] ?? 'Take as directed');

                    $stmtMed->execute([$medId]);
                    $unitPrice = (float)$stmtMed->fetchColumn() ?: 10.00;
                    $totalPrice = $qty * $unitPrice;

                    $stmtItem->execute([
                        ':rx_id'       => $rxId,
                        ':med_id'      => $medId,
                        ':dosage'      => $dosage,
                        ':qty_pres'    => $qty,
                        ':qty_rem'     => $qty,
                        ':qty'         => $qty,
                        ':unit_price'  => $unitPrice,
                        ':total_price' => $totalPrice,
                    ]);
                }
            }

            // 4. Generate Lab Orders if diagnostic tests ordered
            if (!empty($labTestNames)) {
                $stmtLab = $pdo->prepare("
                    INSERT INTO lab_orders (order_number, patient_id, doctor_id, consultation_id, test_name, clinical_notes, priority, status)
                    VALUES (:order_num, :patient_id, :doctor_id, :cns_id, :test_name, :notes, :priority, 'pending')
                ");

                foreach ($labTestNames as $test) {
                    $testName = is_array($test) ? ($test['test_name'] ?? '') : (string)$test;
                    $notes    = is_array($test) ? ($test['notes'] ?? '') : 'Routine clinical investigation';
                    $priority = is_array($test) ? ($test['priority'] ?? 'routine') : 'routine';

                    if (!empty(trim($testName))) {
                        $labOrderNum = self::generateLabOrderNumber();
                        $stmtLab->execute([
                            ':order_num'   => $labOrderNum,
                            ':patient_id'  => $patientId,
                            ':doctor_id'   => $doctorId,
                            ':cns_id'      => $consultationId,
                            ':test_name'   => trim($testName),
                            ':notes'       => $notes,
                            ':priority'    => in_array($priority, ['routine', 'urgent', 'stat'], true) ? $priority : 'routine',
                        ]);
                    }
                }
            }

            // 5. Complete Queue Item
            if ($queueId) {
                PatientOperation::updateQueueStatus($queueId, 'completed');
            }

            // 6. Mark any prior pending follow-up appointment for this patient as completed
            try {
                $stmtFUp = $pdo->prepare("
                    UPDATE consultations
                    SET follow_up_status = 'completed',
                        follow_up_completed_at = CURRENT_TIMESTAMP
                    WHERE patient_id = :patient_id
                      AND id != :current_cns_id
                      AND follow_up_date IS NOT NULL
                      AND (follow_up_status = 'pending' OR follow_up_status IS NULL)
                      AND follow_up_date <= CURDATE()
                ");
                $stmtFUp->execute([
                    ':patient_id'     => $patientId,
                    ':current_cns_id' => $consultationId,
                ]);
            } catch (Exception $eFu) {
                error_log('[HPMS FOLLOW-UP STATUS COMPLETE ERROR] ' . $eFu->getMessage());
            }

            $pdo->commit();
            return $consultationId;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS CONSULTATION ENCOUNTER ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves all consultations for a patient.
     *
     * @param int $patientId
     * @return array
     */
    public static function getConsultationsByPatient(int $patientId): array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as doctor_name, u.professional_title as doctor_title
            FROM consultations c
            JOIN users u ON c.doctor_id = u.id
            WHERE c.patient_id = :id
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([':id' => $patientId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieves doctor's dashboard statistics for today.
     *
     * @param int $doctorId
     * @return array
     */
    public static function getDoctorDashboardStats(int $doctorId): array
    {
        $pdo = getDBConnection();

        $waitingCount = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = {$doctorId} AND status IN ('waiting', 'on_hold') AND DATE(queued_at) = CURDATE()")->fetchColumn();
        $onHoldCount = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = {$doctorId} AND status = 'on_hold' AND DATE(queued_at) = CURDATE()")->fetchColumn();
        $completedToday = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = {$doctorId} AND status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn();
        $inConsultation = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = {$doctorId} AND status = 'in_consultation'")->fetchColumn();
        $totalPatientsToday = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = {$doctorId} AND DATE(queued_at) = CURDATE()")->fetchColumn();

        return [
            'waiting_patients'    => $waitingCount,
            'on_hold_count'       => $onHoldCount,
            'completed_today'     => $completedToday,
            'in_consultation'     => $inConsultation,
            'total_today'         => $totalPatientsToday,
        ];
    }
}
