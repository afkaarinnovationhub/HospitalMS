<?php
/**
 * MedCore Systems - Doctor Controller
 * Handles doctor account creation, profile modifications, and directory management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/DoctorOperation.php';

class DoctorController
{
    /**
     * Handles adding a new doctor account.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCreateDoctor(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $fullName = sanitizeString($post['full_name'] ?? '');
            $username = sanitizeString($post['username'] ?? '');
            $email    = sanitizeEmail($post['email'] ?? '');
            $password = $post['password'] ?? 'doctor123';
            $title    = sanitizeString($post['professional_title'] ?? 'General Practitioner');
            $fee      = max(0.0, (float)($post['consultation_fee'] ?? 10.00));
            $phone    = sanitizeString($post['phone'] ?? '');
            $status   = in_array($post['account_status'] ?? 'active', ['active', 'inactive', 'suspended'], true) ? $post['account_status'] : 'active';

            if (empty($fullName) || empty($username) || empty($email)) {
                return ['error' => 'Full name, username, and email address are required.'];
            }

            DoctorOperation::createDoctor([
                'full_name'          => $fullName,
                'username'           => $username,
                'email'              => $email,
                'password'           => $password,
                'professional_title' => $title,
                'consultation_fee'   => $fee,
                'phone'              => $phone,
                'account_status'     => $status,
            ]);

            setFlashMessage('success', sprintf('Doctor "%s" registered successfully with Consultation Fee $%.2f!', $fullName, $fee));
            safeRedirect('doctors.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS CREATE DOCTOR ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing a doctor's details.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleEditDoctor(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $doctorId = (int)($post['doctor_id'] ?? 0);
            if ($doctorId <= 0) {
                return ['error' => 'Invalid doctor specified for editing.'];
            }

            DoctorOperation::updateDoctor($doctorId, [
                'full_name'          => sanitizeString($post['full_name'] ?? ''),
                'email'              => sanitizeEmail($post['email'] ?? ''),
                'professional_title' => sanitizeString($post['professional_title'] ?? ''),
                'consultation_fee'   => max(0.0, (float)($post['consultation_fee'] ?? 10.00)),
                'phone'              => sanitizeString($post['phone'] ?? ''),
                'account_status'     => $post['account_status'] ?? 'active',
                'password'           => !empty($post['password']) ? $post['password'] : null,
            ]);

            setFlashMessage('success', 'Doctor details updated successfully.');
            safeRedirect('doctors.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS EDIT DOCTOR ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting a doctor account.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteDoctor(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $doctorId = (int)($post['doctor_id'] ?? 0);
            if ($doctorId <= 0) {
                return ['error' => 'Invalid doctor specified for deletion.'];
            }

            DoctorOperation::deleteDoctor($doctorId);

            setFlashMessage('success', 'Doctor account removed successfully.');
            safeRedirect('doctors.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS DELETE DOCTOR ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
