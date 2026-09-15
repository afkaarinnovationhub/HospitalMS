<?php
/**
 * MedCore Systems - Laboratory Web Controller
 * Handles HTTP requests, CSRF verification, and workflow dispatch for diagnostic laboratory orders.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/LaboratoryOperation.php';
require_once __DIR__ . '/../OPERATIONS/PatientOperation.php';
require_once __DIR__ . '/../OPERATIONS/ConsultationOperation.php';

class LaboratoryController
{
    /**
     * Handles ordering diagnostic lab tests from the Doctor Consultation Workspace.
     * Automatically saves patient baseline info, clinical vitals, and draft SOAP consultation.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleOrderLabTests(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $doctorId = (int)($currentUser['id'] ?? 1);

        try {
            $patientId = (int)($post['patient_id'] ?? 0);
            $queueId   = !empty($post['queue_id']) ? (int)$post['queue_id'] : null;

            if ($patientId <= 0) {
                return ['error' => 'Invalid patient selected for laboratory order.'];
            }

            $labTests = $post['lab_tests'] ?? [];
            if (empty($labTests) || !is_array($labTests)) {
                return ['error' => 'Please select at least one diagnostic laboratory test.'];
            }

            $notes    = sanitizeString($post['clinical_notes'] ?? ($post['lab_notes'] ?? ''));
            $priority = sanitizeString($post['priority'] ?? 'routine');

            // 1. Update Patient Baseline Information if provided
            $bloodGroup     = sanitizeString($post['blood_group'] ?? '');
            $allergies      = sanitizeString($post['allergies'] ?? '');
            $medicalHistory = sanitizeString($post['medical_history'] ?? '');
            $ageYears       = !empty($post['age_years']) ? (int)$post['age_years'] : null;

            $pdo = getDBConnection();
            $updateFields = [];
            $updateParams = [':id' => $patientId];

            if ($bloodGroup !== '') {
                $updateFields[] = "blood_group = :bg";
                $updateParams[':bg'] = $bloodGroup;
            }
            if ($allergies !== '') {
                $updateFields[] = "allergies = :allg";
                $updateParams[':allg'] = $allergies;
            }
            if ($medicalHistory !== '') {
                $updateFields[] = "medical_history = :med_hist";
                $updateParams[':med_hist'] = $medicalHistory;
            }
            if ($ageYears !== null && $ageYears >= 0) {
                $updateFields[] = "dob = DATE_SUB(CURDATE(), INTERVAL :age_yr YEAR)";
                $updateParams[':age_yr'] = $ageYears;
            }

            if (!empty($updateFields)) {
                $sqlUpPatient = "UPDATE patients SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $stmtUpP = $pdo->prepare($sqlUpPatient);
                $stmtUpP->execute($updateParams);
            }

            // 2. Record Vitals if provided
            if (!empty($post['systolic']) || !empty($post['temperature']) || !empty($post['heart_rate']) || !empty($post['spo2_oxygen'])) {
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
                    'chief_complaint'  => sanitizeString($post['subjective_notes'] ?? 'Doctor consultation triage'),
                    'triage_level'     => 'routine',
                    'recorded_by'      => $doctorId,
                ]);
            }

            // 3. Save Draft Consultation Record
            $cnsId = ConsultationOperation::saveDraftConsultation([
                'patient_id'           => $patientId,
                'doctor_id'            => $doctorId,
                'queue_id'             => $queueId,
                'subjective_notes'     => sanitizeString($post['subjective_notes'] ?? ''),
                'objective_findings'   => sanitizeString($post['objective_findings'] ?? ''),
                'assessment_diagnosis' => sanitizeString($post['assessment_diagnosis'] ?? 'Under Diagnostic & Lab Investigation'),
                'secondary_diagnosis'  => sanitizeString($post['secondary_diagnosis'] ?? ''),
                'treatment_plan'       => sanitizeString($post['treatment_plan'] ?? ''),
                'follow_up_date'       => sanitizeString($post['follow_up_date'] ?? ''),
            ]);

            // 4. Create Lab Orders
            $res = LaboratoryOperation::createLabOrders(
                $patientId,
                $doctorId,
                $cnsId,
                $queueId,
                $labTests,
                $notes,
                $priority,
                $doctorId
            );

            setFlashMessage('success', sprintf(
                'Diagnostic lab order dispatched successfully (%d test(s) - Total: $%.2f). Invoice sent to Billing.',
                count($res['order_ids']),
                $res['total_price']
            ));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'doctor_dashboard.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return $res;

        } catch (Exception $e) {
            error_log('[HPMS LAB ORDER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles specimen collection action by laboratory technician.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCollectSpecimen(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $techId = (int)($currentUser['id'] ?? 1);

        try {
            $orderId = (int)($post['order_id'] ?? 0);
            if ($orderId <= 0) {
                return ['error' => 'Invalid laboratory order specified.'];
            }

            LaboratoryOperation::collectSpecimen($orderId, $techId);

            setFlashMessage('success', 'Specimen marked as collected. Ready for diagnostic analysis.');
            safeRedirect('laboratory_dashboard.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS LAB SPECIMEN ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles entering test findings, diagnostic values, and submitting results to doctor.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleSaveLabResults(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $techId = (int)($currentUser['id'] ?? 1);

        try {
            $orderId = (int)($post['order_id'] ?? 0);
            if ($orderId <= 0) {
                return ['error' => 'Invalid laboratory order specified.'];
            }

            $summary = sanitizeString($post['result_summary'] ?? '');
            $values  = sanitizeString($post['result_values'] ?? '');

            if (empty($summary)) {
                return ['error' => 'Diagnostic test findings and result summary are required.'];
            }

            LaboratoryOperation::recordTestResults($orderId, $summary, $values, $techId);

            setFlashMessage('success', 'Laboratory results verified and released! Doctor can now review findings.');
            safeRedirect('laboratory_dashboard.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS LAB RESULT ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles adding a new diagnostic test to the master lab catalog.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCreateLabTest(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $testName = sanitizeString($post['test_name'] ?? '');
            if (empty($testName)) {
                return ['error' => 'Diagnostic test name is required.'];
            }

            $price = !empty($post['price']) ? (float)$post['price'] : 10.00;
            $category = sanitizeString($post['category'] ?? 'General Laboratory');
            $specimenType = sanitizeString($post['specimen_type'] ?? 'Standard Specimen');
            $turnaround = !empty($post['turnaround_minutes']) ? (int)$post['turnaround_minutes'] : 30;
            $normalRange = sanitizeString($post['normal_range'] ?? 'Standard Reference');

            $testId = LaboratoryOperation::createLabTest([
                'test_name'          => $testName,
                'price'              => $price,
                'category'           => $category ?: 'General Laboratory',
                'specimen_type'      => $specimenType ?: 'Standard Specimen',
                'turnaround_minutes' => $turnaround,
                'normal_range'       => $normalRange ?: 'Standard Reference',
            ]);

            setFlashMessage('success', sprintf('Diagnostic lab test "%s" successfully added to catalog.', $testName));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_catalog.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['test_id' => $testId, 'test_name' => $testName];

        } catch (Exception $e) {
            error_log('[HPMS CREATE LAB TEST ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing / updating an existing diagnostic lab test.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleUpdateLabTest(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $testId = (int)($post['test_id'] ?? 0);
            if ($testId <= 0) {
                return ['error' => 'Invalid diagnostic test ID specified.'];
            }

            $testName = sanitizeString($post['test_name'] ?? '');
            if (empty($testName)) {
                return ['error' => 'Diagnostic test name is required.'];
            }

            $existing = LaboratoryOperation::getLabTestById($testId);
            $price = isset($post['price']) ? (float)$post['price'] : ($existing['price'] ?? 10.00);
            $category = sanitizeString($post['category'] ?? ($existing['category'] ?? 'General Laboratory'));
            $specimenType = isset($post['specimen_type']) ? sanitizeString($post['specimen_type']) : ($existing['specimen_type'] ?? 'Standard Specimen');
            $turnaround = !empty($post['turnaround_minutes']) ? (int)$post['turnaround_minutes'] : ($existing['turnaround_minutes'] ?? 30);
            $normalRange = isset($post['normal_range']) ? sanitizeString($post['normal_range']) : ($existing['normal_range'] ?? 'Standard Reference');
            $isActive = isset($post['is_active']) ? (int)$post['is_active'] : (isset($existing['is_active']) ? (int)$existing['is_active'] : 1);

            LaboratoryOperation::updateLabTest($testId, [
                'test_name'          => $testName,
                'price'              => $price,
                'category'           => $category ?: 'General Laboratory',
                'specimen_type'      => $specimenType ?: 'Standard Specimen',
                'turnaround_minutes' => $turnaround,
                'normal_range'       => $normalRange ?: 'Standard Reference',
                'is_active'          => $isActive,
            ]);

            setFlashMessage('success', sprintf('Diagnostic lab test "%s" successfully updated.', $testName));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_catalog.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'test_id' => $testId];

        } catch (Exception $e) {
            error_log('[HPMS UPDATE LAB TEST ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles quick active/inactive status toggle for a lab test.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleToggleLabTestStatus(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $testId = (int)($post['test_id'] ?? 0);
            if ($testId <= 0) {
                return ['error' => 'Invalid diagnostic test ID specified.'];
            }

            LaboratoryOperation::toggleLabTestStatus($testId);
            setFlashMessage('success', 'Diagnostic lab test status updated successfully.');

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_catalog.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'test_id' => $testId];

        } catch (Exception $e) {
            error_log('[HPMS TOGGLE LAB TEST ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting a diagnostic lab test from the master catalog.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteLabTest(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $testId = (int)($post['test_id'] ?? 0);
            if ($testId <= 0) {
                return ['error' => 'Invalid diagnostic test ID specified.'];
            }

            LaboratoryOperation::deleteLabTest($testId);
            setFlashMessage('success', 'Diagnostic lab test deleted successfully.');

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_catalog.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'test_id' => $testId];

        } catch (Exception $e) {
            error_log('[HPMS DELETE LAB TEST ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles adding a new diagnostic laboratory category / panel.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCreateLabCategory(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $name = sanitizeString($post['name'] ?? ($post['category_name'] ?? ''));
            if (empty($name)) {
                return ['error' => 'Laboratory category name is required.'];
            }

            $description = sanitizeString($post['description'] ?? '');

            $catId = LaboratoryOperation::addLabCategory($name, $description);

            setFlashMessage('success', sprintf('Laboratory category "%s" successfully registered.', $name));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_categories.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['category_id' => $catId, 'name' => $name];

        } catch (Exception $e) {
            error_log('[HPMS CREATE LAB CATEGORY ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles updating an existing diagnostic laboratory category.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleUpdateLabCategory(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $catId = (int)($post['category_id'] ?? 0);
            if ($catId <= 0) {
                return ['error' => 'Invalid laboratory category ID specified.'];
            }

            $name = sanitizeString($post['name'] ?? ($post['category_name'] ?? ''));
            if (empty($name)) {
                return ['error' => 'Laboratory category name is required.'];
            }

            $description = sanitizeString($post['description'] ?? '');

            LaboratoryOperation::updateLabCategory($catId, $name, $description);

            setFlashMessage('success', sprintf('Laboratory category "%s" updated successfully.', $name));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_categories.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'category_id' => $catId, 'name' => $name];

        } catch (Exception $e) {
            error_log('[HPMS UPDATE LAB CATEGORY ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting a diagnostic laboratory category.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteLabCategory(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $catId = (int)($post['category_id'] ?? 0);
            if ($catId <= 0) {
                return ['error' => 'Invalid laboratory category ID specified.'];
            }

            LaboratoryOperation::deleteLabCategory($catId);

            setFlashMessage('success', 'Laboratory category deleted successfully.');

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'lab_categories.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'category_id' => $catId];

        } catch (Exception $e) {
            error_log('[HPMS DELETE LAB CATEGORY ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
