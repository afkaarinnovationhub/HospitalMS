<?php
/**
 * MedCore Systems - Authentication & Role-Based Authorization
 * Manages user authentication state, session identity, and route access control.
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/security.php';

// Valid Hospital Roles
const ROLE_DOCTOR            = 'doctor';
const ROLE_PHARMACY          = 'pharmacy';
const ROLE_RECEPTION_CASHIER = 'reception_cashier';
const ROLE_LABORATORY        = 'laboratory';
const ROLE_MANAGER           = 'manager';
const ROLE_SUPERADMIN_ICT    = 'superadmin_ict';

const ALL_ROLES = [
    ROLE_DOCTOR,
    ROLE_PHARMACY,
    ROLE_RECEPTION_CASHIER,
    ROLE_LABORATORY,
    ROLE_MANAGER,
    ROLE_SUPERADMIN_ICT,
];

/**
 * Checks if the current request is from an authenticated user.
 *
 * @return bool
 */
function isLoggedIn(): bool
{
    initSecureSession();
    return !empty($_SESSION['hpms_user_id']) && !empty($_SESSION['hpms_user_role']);
}

/**
 * Returns the currently authenticated user's profile directly from database,
 * ensuring real-time accuracy if the user's name or title is updated.
 *
 * @param bool $forceFreshFromDb
 * @return array|null
 */
function getCurrentUser(bool $forceFreshFromDb = true): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $userId = (int)($_SESSION['hpms_user_id'] ?? 0);
    if ($userId > 0 && $forceFreshFromDb) {
        try {
            require_once __DIR__ . '/database.php';
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, professional_title, account_status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user && ($user['account_status'] ?? 'active') === 'active') {
                // Update session memory to keep it synchronized with DB
                $_SESSION['hpms_username']           = $user['username'];
                $_SESSION['hpms_full_name']          = $user['full_name'];
                $_SESSION['hpms_email']              = $user['email'];
                $_SESSION['hpms_user_role']          = $user['role'];
                $_SESSION['hpms_professional_title'] = $user['professional_title'] ?? '';
                return $user;
            }
        } catch (Exception $e) {
            // Fallback to session data on transient error
        }
    }

    return [
        'id'                 => $_SESSION['hpms_user_id'],
        'username'           => $_SESSION['hpms_username'] ?? '',
        'full_name'          => $_SESSION['hpms_full_name'] ?? '',
        'email'              => $_SESSION['hpms_email'] ?? '',
        'role'               => $_SESSION['hpms_user_role'] ?? '',
        'professional_title' => $_SESSION['hpms_professional_title'] ?? '',
    ];
}

/**
 * Enforces authentication. If the user is not logged in, redirects to login page.
 *
 * @param string $redirectUrl
 * @return void
 */
function requireLogin(string $redirectUrl = 'login.php'): void
{
    if (defined('HPMS_TESTING')) {
        return;
    }
    if (!isLoggedIn()) {
        setFlashMessage('error', 'Please log in to access this page.');
        safeRedirect($redirectUrl);
    }
}

/**
 * Checks whether the logged-in user possesses one of the permitted roles.
 *
 * @param string|array $roles Single role or array of permitted roles
 * @return bool
 */
function hasRole(string|array $roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    $currentRole = $_SESSION['hpms_user_role'] ?? '';

    // Superadmin has access across all modules
    if ($currentRole === ROLE_SUPERADMIN_ICT) {
        return true;
    }

    if (is_array($roles)) {
        return in_array($currentRole, $roles, true);
    }

    return $currentRole === $roles;
}

/**
 * Enforces role-based authorization. If user role is not authorized, redirects with an alert.
 *
 * @param string|array $allowedRoles
 * @param string|null $forbiddenRedirect
 * @return void
 */
function requireRole(string|array $allowedRoles, ?string $forbiddenRedirect = null): void
{
    requireLogin();

    if (!hasRole($allowedRoles)) {
        $currentRole = $_SESSION['hpms_user_role'] ?? '';
        $redirectUrl = $forbiddenRedirect ?: getRoleDefaultPage($currentRole);
        setFlashMessage('error', 'Access denied. You do not have permission to access that section.');
        safeRedirect($redirectUrl);
    }
}

/**
 * Returns the array of allowed navigation module IDs for a given role.
 *
 * @param string $role
 * @return array
 */
function getUserAllowedNavItems(string $role): array
{
    // 1. SuperAdmin: Full access to all modules including User Management
    if ($role === ROLE_SUPERADMIN_ICT) {
        return [
            'dashboard', 'users', 'reception', 'doctor_dashboard', 'doctors', 'patients',
            'queue', 'consultations', 'laboratory', 'lab_catalog', 'lab_categories', 'pharmacy', 'inventory', 'suppliers',
            'billing', 'accounting', 'expenses', 'reports', 'patient_debts', 'hospital_debts'
        ];
    }

    // 2. Manager: All modules including staff User Management (except Doctors specialty management)
    if ($role === ROLE_MANAGER) {
        return [
            'dashboard', 'users', 'reception', 'doctor_dashboard', 'patients',
            'queue', 'consultations', 'laboratory', 'lab_catalog', 'lab_categories', 'pharmacy', 'inventory', 'suppliers',
            'billing', 'accounting', 'expenses', 'reports', 'patient_debts', 'hospital_debts'
        ];
    }

    // 3. Doctor: Doctor View, Patients Directory, Queue, Consultations
    if ($role === ROLE_DOCTOR) {
        return [
            'doctor_dashboard', 'patients', 'queue', 'consultations'
        ];
    }

    // 4. Pharmacy: Reception, Patients, Queue, Pharmacy, Billing, Inventory, Suppliers
    if ($role === ROLE_PHARMACY) {
        return [
            'pharmacy', 'reception', 'patients', 'queue', 'inventory', 'suppliers', 'billing', 'patient_debts', 'hospital_debts'
        ];
    }

    // 5. Reception / Cashier: Reception, Patients, Queue, Billing, Patient Debts
    if ($role === ROLE_RECEPTION_CASHIER) {
        return [
            'reception', 'patients', 'queue', 'billing', 'patient_debts'
        ];
    }

    // 6. Laboratory: Laboratory Worklist, Tests Catalog, and Categories
    if ($role === ROLE_LABORATORY) {
        return [
            'laboratory', 'lab_catalog', 'lab_categories'
        ];
    }

    return [];
}

/**
 * Maps a role to its default operational landing dashboard.
 *
 * @param string $role
 * @return string
 */
function getRoleDefaultPage(string $role): string
{
    return match ($role) {
        ROLE_DOCTOR            => 'doctor_dashboard.php',
        ROLE_RECEPTION_CASHIER => 'reception.php',
        ROLE_PHARMACY          => 'pharmacy_dispensing_prescription.php',
        ROLE_LABORATORY        => 'laboratory_dashboard.php',
        ROLE_MANAGER           => 'dashboard.php',
        ROLE_SUPERADMIN_ICT    => 'dashboard.php',
        default                => 'dashboard.php',
    };
}

/**
 * Returns a human-friendly role name.
 *
 * @param string $role
 * @return string
 */
function getRoleDisplayName(string $role): string
{
    return match ($role) {
        ROLE_DOCTOR            => 'Doctor / Clinician',
        ROLE_RECEPTION_CASHIER => 'Reception & Cashier',
        ROLE_PHARMACY          => 'Pharmacy Specialist',
        ROLE_LABORATORY        => 'Laboratory Technologist',
        ROLE_MANAGER           => 'Hospital Manager',
        ROLE_SUPERADMIN_ICT    => 'Superadmin ICT',
        default                => ucfirst($role),
    };
}
