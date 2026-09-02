<?php
/**
 * MedCore Systems - Authentication Controller
 * Orchestrates login, registration, and logout workflows, input validation, CSRF verification, and role routing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/UserOperation.php';

class AuthController
{
    /**
     * Handles user sign-in submission.
     *
     * @param array $post
     * @return array|null Returns error array if validation fails, otherwise performs safe redirect
     */
    public static function login(array $post): ?array
    {
        initSecureSession();

        // 1. Verify CSRF Token
        $token = $post['csrf_token'] ?? null;
        if (!verifyCsrfToken($token)) {
            return ['error' => 'Security token invalid or expired. Please refresh the page and try again.'];
        }

        // 2. Extract & Sanitize Inputs
        $loginIdentifier = sanitizeString($post['username_or_email'] ?? '');
        $password        = $post['password'] ?? '';

        if (empty($loginIdentifier) || empty($password)) {
            return ['error' => 'Please provide both your email/username and password.'];
        }

        // 3. Query User from Database
        try {
            $user = UserOperation::findUserByLogin($loginIdentifier);

            if (!$user) {
                // Constant-time dummy verification against timing attacks
                password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012345');
                return ['error' => 'Invalid email/username or password.'];
            }

            // 4. Verify Account Status
            if ($user['account_status'] !== 'active') {
                return ['error' => sprintf('Your account is currently %s. Please contact ICT Support.', htmlspecialchars($user['account_status']))];
            }

            // 5. Verify Password Hash
            if (!password_verify($password, $user['password_hash'])) {
                return ['error' => 'Invalid email/username or password.'];
            }

            // 6. Security: Check if password needs rehash (e.g. algorithm upgraded)
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                UserOperation::updateUserPassword((int)$user['id'], $password);
            }

            // 7. Regenerate Session ID to Prevent Session Fixation
            regenerateSession();

            // 8. Store Minimal Identity in Session
            $_SESSION['hpms_user_id']            = (int)$user['id'];
            $_SESSION['hpms_username']           = $user['username'];
            $_SESSION['hpms_full_name']          = $user['full_name'];
            $_SESSION['hpms_email']              = $user['email'];
            $_SESSION['hpms_user_role']          = $user['role'];
            $_SESSION['hpms_professional_title'] = $user['professional_title'] ?? '';
            $_SESSION['hpms_last_activity']      = time();

            // 9. Update Last Login Timestamp
            UserOperation::updateLastLogin((int)$user['id']);

            // 10. Route to Role-Specific Dashboard
            $destination = getRoleDefaultPage($user['role']);
            if (!defined('HPMS_TESTING')) {
                safeRedirect($destination);
            }
            return null;

        } catch (Exception $e) {
            error_log('[HPMS AUTH ERROR] ' . $e->getMessage());
            return ['error' => 'An unexpected server error occurred during login. Please try again later.'];
        }
    }

    /**
     * Handles new staff registration submission.
     *
     * @param array $post
     * @return array|null Returns error array if validation fails, otherwise redirects to login
     */
    public static function register(array $post): ?array
    {
        initSecureSession();

        // 1. Verify CSRF Token
        $token = $post['csrf_token'] ?? null;
        if (!verifyCsrfToken($token)) {
            return ['error' => 'Security token invalid or expired. Please refresh the page and try again.'];
        }

        // 2. Extract & Sanitize Inputs
        $fullName          = sanitizeString($post['full_name'] ?? '');
        $professionalTitle = sanitizeString($post['professional_title'] ?? '');
        $role              = sanitizeString($post['role'] ?? '');
        $email             = sanitizeEmail($post['email'] ?? '');
        $phone             = sanitizeString($post['phone'] ?? '');
        $password          = $post['password'] ?? '';
        $confirmPassword   = $post['confirm_password'] ?? '';
        $termsAgreed       = !empty($post['agree_policy']);

        // 3. Validation
        if (!$termsAgreed) {
            return ['error' => 'You must agree to the data governance and HIPAA patient privacy policies.'];
        }

        if (empty($fullName) || empty($professionalTitle) || empty($role) || empty($email) || empty($password)) {
            return ['error' => 'All required fields marked with an asterisk must be filled.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Please enter a valid work email address.'];
        }

        if (!in_array($role, ALL_ROLES, true)) {
            return ['error' => 'The selected role is invalid.'];
        }

        if (strlen($password) < 8) {
            return ['error' => 'Password must be at least 8 characters in length.'];
        }

        if ($password !== $confirmPassword) {
            return ['error' => 'Password and Confirm Password do not match.'];
        }

        // Generate a clean username from email prefix
        $emailParts = explode('@', $email);
        $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', $emailParts[0]);
        $username = $baseUsername ?: 'user_' . bin2hex(random_bytes(3));

        // Check if username already exists and append random suffix if needed
        if (UserOperation::findUserByUsername($username)) {
            $username .= '_' . random_int(100, 999);
        }

        // 4. Create User Record
        try {
            UserOperation::createUser([
                'full_name'          => $fullName,
                'username'           => $username,
                'email'              => $email,
                'password'           => $password,
                'role'               => $role,
                'professional_title' => $professionalTitle,
                'phone'              => $phone,
                'account_status'     => 'active',
            ]);

            safeRedirect('login.php?registered=1');
            return null;

        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (Exception $e) {
            error_log('[HPMS REGISTRATION ERROR] ' . $e->getMessage());
            return ['error' => 'An error occurred while creating your account. Please try again.'];
        }
    }

    /**
     * Handles user sign-out and session destruction.
     *
     * @return void
     */
    public static function logout(): void
    {
        destroySession();
        safeRedirect('login.php?logged_out=1');
    }
}
