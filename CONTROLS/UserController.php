<?php
/**
 * MedCore Systems - User Management Controller
 * Handles user account creation, editing, active/inactive status toggling, and deletion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/UserOperation.php';

class UserController
{
    /**
     * Handles creating a new user account.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCreateUser(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        try {
            $fullName          = sanitizeString($post['full_name'] ?? '');
            $username          = sanitizeString($post['username'] ?? '');
            $email             = sanitizeEmail($post['email'] ?? '');
            $password          = $post['password'] ?? '';
            $role              = sanitizeString($post['role'] ?? '');
            $professionalTitle = sanitizeString($post['professional_title'] ?? '');
            $phone             = sanitizeString($post['phone'] ?? '');
            $accountStatus     = sanitizeString($post['account_status'] ?? 'active');
            $consultationFee   = isset($post['consultation_fee']) && $post['consultation_fee'] !== '' ? (float)$post['consultation_fee'] : 10.00;

            if (empty($fullName) || empty($username) || empty($email) || empty($password) || empty($role)) {
                return ['error' => 'Please provide full name, username, email, password, and role.'];
            }

            if (!in_array($role, ALL_ROLES, true)) {
                return ['error' => 'Invalid user role selected.'];
            }

            if (strlen($password) < 8) {
                return ['error' => 'Password must be at least 8 characters long.'];
            }

            $userId = UserOperation::createUser([
                'full_name'          => $fullName,
                'username'           => $username,
                'email'              => $email,
                'password'           => $password,
                'role'               => $role,
                'professional_title' => $professionalTitle,
                'phone'              => $phone,
                'account_status'     => $accountStatus,
            ]);

            // If doctor role, update consultation fee
            if ($role === ROLE_DOCTOR && $userId > 0) {
                $pdo = getDBConnection();
                $stmtFee = $pdo->prepare("UPDATE users SET consultation_fee = ? WHERE id = ?");
                $stmtFee->execute([$consultationFee, $userId]);
            }

            setFlashMessage('success', sprintf('User account "%s" created successfully!', $fullName));
            if (!defined('HPMS_TESTING')) {
                safeRedirect('user_management.php');
            }
            return ['user_id' => $userId];

        } catch (Exception $e) {
            error_log('[HPMS CREATE USER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing an existing user account.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleEditUser(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        try {
            $userId            = (int)($post['user_id'] ?? 0);
            $fullName          = sanitizeString($post['full_name'] ?? '');
            $username          = sanitizeString($post['username'] ?? '');
            $email             = sanitizeEmail($post['email'] ?? '');
            $role              = sanitizeString($post['role'] ?? '');
            $professionalTitle = sanitizeString($post['professional_title'] ?? '');
            $phone             = sanitizeString($post['phone'] ?? '');
            $accountStatus     = sanitizeString($post['account_status'] ?? 'active');
            $newPassword       = $post['password'] ?? '';
            $consultationFee   = isset($post['consultation_fee']) && $post['consultation_fee'] !== '' ? (float)$post['consultation_fee'] : 10.00;

            if ($userId <= 0) {
                return ['error' => 'Invalid user ID specified.'];
            }

            $currentSessionUser = getCurrentUser();
            $currentUserId = (int)($currentSessionUser['id'] ?? 0);

            // Safety guard: Cannot deactivate own logged-in account
            if ($currentUserId === $userId && $accountStatus === 'inactive') {
                return ['error' => 'You cannot deactivate your own active session account.'];
            }

            UserOperation::updateUser($userId, [
                'full_name'          => $fullName,
                'username'           => $username,
                'email'              => $email,
                'role'               => $role,
                'professional_title' => $professionalTitle,
                'phone'              => $phone,
                'account_status'     => $accountStatus,
                'password'           => $newPassword,
                'consultation_fee'   => $consultationFee,
            ]);

            setFlashMessage('success', sprintf('User account "%s" updated successfully!', $fullName));
            if (!defined('HPMS_TESTING')) {
                safeRedirect('user_management.php');
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS EDIT USER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles toggling user active/inactive status.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleToggleStatus(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $userId    = (int)($post['user_id'] ?? 0);
            $newStatus = sanitizeString($post['status'] ?? '');

            if ($userId <= 0 || !in_array($newStatus, ['active', 'inactive'], true)) {
                return ['error' => 'Invalid parameters for status toggle.'];
            }

            $currentSessionUser = getCurrentUser();
            $currentUserId = (int)($currentSessionUser['id'] ?? 0);

            // Safety guard: Cannot deactivate own logged-in account
            if ($currentUserId === $userId && $newStatus === 'inactive') {
                return ['error' => 'Security Protection: You cannot deactivate your own active session account.'];
            }

            UserOperation::toggleUserStatus($userId, $newStatus);

            $statusLabel = ($newStatus === 'active') ? 'activated' : 'deactivated';
            setFlashMessage('success', "User account status successfully {$statusLabel}.");
            if (!defined('HPMS_TESTING')) {
                safeRedirect('user_management.php');
            }
            return ['success' => true, 'status' => $newStatus];

        } catch (Exception $e) {
            error_log('[HPMS TOGGLE STATUS ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles deleting a user account.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteUser(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $userId = (int)($post['user_id'] ?? 0);
            if ($userId <= 0) {
                return ['error' => 'Invalid user ID for deletion.'];
            }

            $currentSessionUser = getCurrentUser();
            $currentUserId = (int)($currentSessionUser['id'] ?? 0);

            if ($currentUserId === $userId) {
                return ['error' => 'Security Protection: You cannot delete your own account while logged in.'];
            }

            UserOperation::deleteUser($userId);

            setFlashMessage('success', 'User account permanently removed.');
            if (!defined('HPMS_TESTING')) {
                safeRedirect('user_management.php');
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS DELETE USER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
