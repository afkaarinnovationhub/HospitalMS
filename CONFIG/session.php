<?php
/**
 * MedCore Systems - Hardened Session Management
 * Provides session initialization with secure cookie parameters, fixation protection,
 * inactivity timeout, and flash message support.
 */

declare(strict_types=1);

if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes of inactivity
}

/**
 * Initializes a hardened PHP session with security-oriented cookie flags.
 */
function initSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );

        if (!headers_sent()) {
            session_name('HPMS_SESSID');

            session_set_cookie_params([
                'lifetime' => 0,              // Session cookie persists until browser closes
                'path'     => '/',
                'domain'   => '',             // Current domain
                'secure'   => $isHttps,       // Only send over HTTPS if active
                'httponly' => true,           // Inaccessible via JavaScript Document.cookie
                'samesite' => 'Lax',          // Mitigates CSRF on cross-site requests
            ]);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_start();
        } elseif (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    // Check for session expiration due to inactivity
    if (isset($_SESSION['hpms_user_id'])) {
        $now = time();
        if (isset($_SESSION['hpms_last_activity']) && ($now - $_SESSION['hpms_last_activity']) > SESSION_TIMEOUT_SECONDS) {
            destroySession();
            setFlashMessage('error', 'Your session has expired due to inactivity. Please log in again.');
            return;
        }
        $_SESSION['hpms_last_activity'] = $now;
    }
}

/**
 * Regenerates the session ID to prevent session fixation attacks upon login.
 */
function regenerateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/**
 * Completely destroys the current session and clears the session cookie.
 */
function destroySession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            session_start();
        } else {
            @session_start();
        }
    }

    $_SESSION = [];
    if (function_exists('session_unset')) {
        session_unset();
    }

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
        unset($_COOKIE[session_name()]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Sets a flash message for single-use feedback in the UI.
 *
 * @param string $type e.g. 'success', 'error', 'warning', 'info'
 * @param string $message
 */
function setFlashMessage(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        initSecureSession();
    }
    $_SESSION['hpms_flash'][$type] = $message;
}

/**
 * Retrieves and clears a flash message.
 *
 * @param string $type
 * @return string|null
 */
function getFlashMessage(string $type): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        initSecureSession();
    }

    if (isset($_SESSION['hpms_flash'][$type])) {
        $message = $_SESSION['hpms_flash'][$type];
        unset($_SESSION['hpms_flash'][$type]);
        return $message;
    }

    return null;
}

/**
 * Checks if a flash message of a specific type exists.
 *
 * @param string $type
 * @return bool
 */
function hasFlashMessage(string $type): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        initSecureSession();
    }
    return !empty($_SESSION['hpms_flash'][$type]);
}
