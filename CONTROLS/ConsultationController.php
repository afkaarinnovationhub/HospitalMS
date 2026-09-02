<?php
/**
 * MedCore Systems - Clinical Consultation Controller
 * Handles clinical encounter documentation, SOAP note recording, direct prescription dispatch to pharmacy, and lab ordering.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';

class ConsultationController
{
    /**
     * Handles saving doctor consultation notes, vital signs, prescriptions, and lab orders.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleSaveConsultation(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $doctorId = (int)($currentUser['id'] ?? 1);

        try {
            $patientId = (int)($post['patient_id'] ?? 0);
            $queueId   = !empty($post['queue_id']) ? (int)$post['queue_id'] : null;

            if ($patientId <= 0) {
                return ['error' => 'Invalid patient selected for clinical encounter.'];
            }

            // 0. Update Patient Baseline Information entered by Doctor (DOB/Age, Blood Group, Allergies, Medical History)
            $currentPat = PatientOperation::getPatientById($patientId);
            if ($currentPat) {
                $updatePatData = [];

                if (!empty($post['dob'])) {
                    $updatePatData['dob'] = $post['dob'];
                } elseif (!empty($post['age_years'])) {
                    $ageY = max(0, (int)$post['age_years']);
                    $updatePatData['dob'] = date('Y-m-d', strtotime("-{$ageY} years"));
                }

                if (!empty($post['blood_group'])) {
                    $updatePatData['blood_group'] = sanitizeString($post['blood_group']);
                }

                if (isset($post['allergies']) && trim($post['allergies']) !== '') {
                    $updatePatData['allergies'] = sanitizeString($post['allergies']);
                }

                if (isset($post['medical_history']) && trim($post['medical_history']) !== '') {
                    $updatePatData['medical_history'] = sanitizeString($post['medical_history']);
                }

                if (!empty($updatePatData)) {
                    PatientOperation::updatePatient($patientId, array_merge([
                        'first_name'              => $currentPat['first_name'],
                        'last_name'               => $currentPat['last_name'],
                        'gender'                  => $currentPat['gender'],
                        'dob'                     => $currentPat['dob'],
                        'phone'                   => $currentPat['phone'],
                        'email'                   => $currentPat['email'],
                        'address'                 => $currentPat['address'],
                        'blood_group'             => $currentPat['blood_group'],
                        'allergies'               => $currentPat['allergies'],
                        'medical_history'         => $currentPat['medical_history'],
                        'emergency_contact_name'  => $currentPat['emergency_contact_name'],
                        'emergency_contact_phone' => $currentPat['emergency_contact_phone'],
                    ], $updatePatData));
                }
            }

            // 1. Extract Encounter Data
            $encounterData = [
                'patient_id'           => $patientId,
                'doctor_id'            => $doctorId,
                'queue_id'             => $queueId,
                'subjective_notes'     => sanitizeString($post['subjective_notes'] ?? ''),
                'objective_findings'   => sanitizeString($post['objective_findings'] ?? ''),
                'assessment_diagnosis' => sanitizeString($post['assessment_diagnosis'] ?? 'General Consultation / Clinical Assessment'),
                'secondary_diagnosis'  => sanitizeString($post['secondary_diagnosis'] ?? ''),
                'treatment_plan'       => sanitizeString($post['treatment_plan'] ?? ''),
                'follow_up_date'       => !empty($post['follow_up_date']) ? $post['follow_up_date'] : null,
                'systolic'             => !empty($post['systolic']) ? (int)$post['systolic'] : null,
                'diastolic'            => !empty($post['diastolic']) ? (int)$post['diastolic'] : null,
                'heart_rate'           => !empty($post['heart_rate']) ? (int)$post['heart_rate'] : null,
                'temperature'          => !empty($post['temperature']) ? (float)$post['temperature'] : null,
                'respiratory_rate'     => !empty($post['respiratory_rate']) ? (int)$post['respiratory_rate'] : null,
                'spo2_oxygen'          => !empty($post['spo2_oxygen']) ? (int)$post['spo2_oxygen'] : null,
                'weight_kg'            => !empty($post['weight_kg']) ? (float)$post['weight_kg'] : null,
                'height_cm'            => !empty($post['height_cm']) ? (float)$post['height_cm'] : null,
            ];

            // 2. Extract Prescriptions (Medications)
            $prescriptionItems = [];
            if (!empty($post['med_ids']) && is_array($post['med_ids'])) {
                foreach ($post['med_ids'] as $idx => $medId) {
                    $medId = (int)$medId;
                    if ($medId > 0) {
                        $qty = (int)($post['med_qtys'][$idx] ?? 1);
                        $dosage = sanitizeString($post['med_dosages'][$idx] ?? 'Take as directed');
                        $prescriptionItems[] = [
                            'medication_id'       => $medId,
                            'quantity'            => max(1, $qty),
                            'dosage_instructions' => $dosage,
                        ];
                    }
                }
            }

            // 3. Extract Lab Test Orders
            $labOrders = [];
            if (!empty($post['lab_tests']) && is_array($post['lab_tests'])) {
                foreach ($post['lab_tests'] as $idx => $testName) {
                    $testName = sanitizeString($testName);
                    if (!empty($testName)) {
                        $priority = $post['lab_priorities'][$idx] ?? 'routine';
                        $notes    = sanitizeString($post['lab_notes'][$idx] ?? 'Routine clinical test');
                        $labOrders[] = [
                            'test_name' => $testName,
                            'priority'  => $priority,
                            'notes'     => $notes,
                        ];
                    }
                }
            }

            $consultationId = ConsultationOperation::recordConsultationEncounter($encounterData, $prescriptionItems, $labOrders);

            setFlashMessage('success', 'Consultation encounter completed successfully! Prescriptions routed to Pharmacy and Lab orders dispatched.');
            safeRedirect("doctor_dashboard.php");
            return null;

        } catch (Exception $e) {
            error_log('[HPMS CONSULTATION CONTROLLER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
