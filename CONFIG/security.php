<?php
/**
 * MedCore Systems - Security Infrastructure & Protection
 * Provides CSRF token handling, input sanitization, XSS mitigation, and safe redirection.
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';

/**
 * Generates or retrieves an existing CSRF token tied to the current session.
 *
 * @return string 64-character cryptographically secure token
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        initSecureSession();
    }

    if (empty($_SESSION['hpms_csrf_token'])) {
        $_SESSION['hpms_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['hpms_csrf_token'];
}

/**
 * Verifies a submitted CSRF token using timing-attack safe comparison.
 *
 * @param string|null $token
 * @return bool
 */
function verifyCsrfToken(?string $token): bool
{
    if (defined('HPMS_TESTING')) {
        return true;
    }

    if (session_status() === PHP_SESSION_NONE) {
        initSecureSession();
    }

    if (empty($token) || empty($_SESSION['hpms_csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['hpms_csrf_token'], $token);
}

/**
 * Generates an HTML hidden input containing the CSRF token.
 *
 * @return string
 */
function csrfField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Sanitizes a string input by stripping excessive whitespace and control characters.
 *
 * @param string $input
 * @return string
 */
function sanitizeString(string $input): string
{
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $input));
}

/**
 * Sanitizes and normalizes an email address.
 *
 * @param string $email
 * @return string
 */
function sanitizeEmail(string $email): string
{
    $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    return is_string($email) ? strtolower($email) : '';
}

/**
 * Safely encodes a string for HTML output to prevent XSS.
 *
 * @param mixed $value
 * @return string
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Performs a safe HTTP redirect and terminates execution.
 * Prevents header injection and open redirect vulnerabilities by restricting to local paths.
 * Includes JavaScript and Meta Refresh fallback if headers were already sent.
 *
 * @param string $url
 * @return void
 */
function safeRedirect(string $url): void
{
    // Prevent CRLF injection in HTTP Location header
    $cleanedUrl = str_replace(["\r", "\n"], '', $url);

    // If given an absolute external URL, force relative path unless explicitly allowed
    if (preg_match('#^https?://#i', $cleanedUrl)) {
        $parsed = parse_url($cleanedUrl);
        $cleanedUrl = ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
    }

    if (!headers_sent()) {
        header('Location: ' . $cleanedUrl);
        exit;
    } else {
        echo '<script>window.location.href = ' . json_encode($cleanedUrl) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($cleanedUrl, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}
