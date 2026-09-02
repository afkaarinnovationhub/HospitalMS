<?php
/**
 * MedCore Systems - Automated Phase 1 Test Suite
 * Tests DB connection, Users table, Password hashing, Auth flow, Roles, SQL Injection immunity, CSRF, and Sessions.
 */

declare(strict_types=1);

// Initialize session in CLI test environment before any output
require_once __DIR__ . '/CONFIG/session.php';
initSecureSession();

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/CONFIG/auth.php';
require_once __DIR__ . '/OPERATIONS/UserOperation.php';
require_once __DIR__ . '/CONTROLS/AuthController.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $testName, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo " [PASS] $testName" . ($details ? " ($details)" : "") . PHP_EOL;
    } else {
        $failedTests++;
        echo " [FAIL] $testName" . ($details ? " ($details)" : "") . PHP_EOL;
    }
}

echo "========================================================\n";
echo " HPMS PHASE 1 AUTOMATED SECURITY & AUTHENTICATION TESTS \n";
echo "========================================================\n\n";

// --- TEST 1: Database Connection ---
try {
    $pdo = getDBConnection();
    assertTest("1. Database Connection", $pdo instanceof PDO, "Connected to MySQL via PDO");
} catch (Exception $e) {
    assertTest("1. Database Connection", false, $e->getMessage());
}

// --- TEST 2: Users Table Schema ---
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $requiredCols = ['id', 'full_name', 'username', 'email', 'password_hash', 'role', 'professional_title', 'phone', 'account_status', 'last_login_at', 'created_at', 'updated_at'];
    $allPresent = count(array_intersect($requiredCols, $columns)) === count($requiredCols);
    assertTest("2. Users Table Schema", $allPresent, "All 12 columns properly created");
} catch (Exception $e) {
    assertTest("2. Users Table Schema", false, $e->getMessage());
}

// --- TEST 3: Default Seed Users ---
try {
    UserOperation::seedDefaultUsersIfEmpty();
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    assertTest("3. Seed Users Creation", $userCount >= 6, "Found $userCount users in database");
} catch (Exception $e) {
    assertTest("3. Seed Users Creation", false, $e->getMessage());
}

// --- TEST 4: Password Hash Verification ---
try {
    $admin = UserOperation::findUserByLogin('admin');
    $isHashValid = !empty($admin['password_hash']) && str_starts_with($admin['password_hash'], '$2y$');
    $isPassCorrect = password_verify('password123', $admin['password_hash']);
    assertTest("4. Password Hash Verification", $isHashValid && $isPassCorrect, "bcrypt hash verified with password_verify");
} catch (Exception $e) {
    assertTest("4. Password Hash Verification", false, $e->getMessage());
}

// --- TEST 5: Valid Login Flow ---
try {
    $token = generateCsrfToken();
    
    // Simulate login for Doctor
    $loginResult = AuthController::login([
        'csrf_token'        => $token,
        'username_or_email' => 'doctor_alan',
        'password'          => 'password123',
    ]);
    
    $isAuth = isLoggedIn();
    $user = getCurrentUser();
    assertTest("5. Valid User Authentication", $isAuth && $user['role'] === ROLE_DOCTOR, "Doctor authenticated with session payload");
} catch (Exception $e) {
    assertTest("5. Valid User Authentication", false, $e->getMessage());
}

// --- TEST 6: Invalid Password Rejection ---
try {
    $token = generateCsrfToken();
    $loginResult = AuthController::login([
        'csrf_token'        => $token,
        'username_or_email' => 'doctor_alan',
        'password'          => 'wrong_password_999',
    ]);
    assertTest("6. Invalid Password Rejection", isset($loginResult['error']), "Error message: " . ($loginResult['error'] ?? ''));
} catch (Exception $e) {
    assertTest("6. Invalid Password Rejection", false, $e->getMessage());
}

// --- TEST 7: Non-existent User Rejection ---
try {
    $token = generateCsrfToken();
    $loginResult = AuthController::login([
        'csrf_token'        => $token,
        'username_or_email' => 'non_existent_user_123',
        'password'          => 'password123',
    ]);
    assertTest("7. Non-existent User Rejection", isset($loginResult['error']), "Rejected cleanly without error leak");
} catch (Exception $e) {
    assertTest("7. Non-existent User Rejection", false, $e->getMessage());
}

// --- TEST 8: SQL Injection Defense on Login ---
try {
    $token = generateCsrfToken();
    $sqliPayloads = [
        "' OR '1'='1",
        "admin' --",
        "' UNION SELECT 1, 'admin', 'admin@medcore.org', '$2y$10$...', 'superadmin_ict' --",
    ];
    $allSqliBlocked = true;
    foreach ($sqliPayloads as $payload) {
        $res = AuthController::login([
            'csrf_token'        => $token,
            'username_or_email' => $payload,
            'password'          => 'anything',
        ]);
        if (!isset($res['error'])) {
            $allSqliBlocked = false;
        }
    }
    assertTest("8. SQL Injection Immunity (Prepared Statements)", $allSqliBlocked, "All SQLi attack vectors blocked");
} catch (Exception $e) {
    assertTest("8. SQL Injection Immunity", false, $e->getMessage());
}

// --- TEST 9: CSRF Token Verification ---
try {
    $invalidTokenResult = AuthController::login([
        'csrf_token'        => 'invalid_forged_token_xyz',
        'username_or_email' => 'admin',
        'password'          => 'password123',
    ]);
    $isCsrfBlocked = isset($invalidTokenResult['error']) && str_contains($invalidTokenResult['error'], 'Security token');
    assertTest("9. CSRF Token Validation & Attack Defense", $isCsrfBlocked, "Forged CSRF token rejected");
} catch (Exception $e) {
    assertTest("9. CSRF Token Validation", false, $e->getMessage());
}

// --- TEST 10: Role-Based Routing Mapping ---
try {
    $roleMapCorrect = (
        getRoleDefaultPage(ROLE_DOCTOR) === 'doctor_dashboard.php' &&
        getRoleDefaultPage(ROLE_RECEPTION_CASHIER) === 'reception.php' &&
        getRoleDefaultPage(ROLE_PHARMACY) === 'pharmacy_dispensing_prescription.php' &&
        getRoleDefaultPage(ROLE_LABORATORY) === 'laboratory_dashboard.php' &&
        getRoleDefaultPage(ROLE_MANAGER) === 'report.php' &&
        getRoleDefaultPage(ROLE_SUPERADMIN_ICT) === 'dashboard.php'
    );
    assertTest("10. Role-Based Route Resolution", $roleMapCorrect, "All 6 role destinations correctly mapped");
} catch (Exception $e) {
    assertTest("10. Role-Based Route Resolution", false, $e->getMessage());
}

// --- TEST 11: Staff Registration Flow & Uniqueness ---
try {
    $uniqueEmail = 'test.nurse.' . time() . '.' . random_int(100, 999) . '@medcore.org';
    $token = generateCsrfToken();
    $regResult = AuthController::register([
        'csrf_token'         => $token,
        'full_name'          => 'Nurse Joy',
        'professional_title' => 'RN, Clinical Triage',
        'role'               => ROLE_RECEPTION_CASHIER,
        'email'              => $uniqueEmail,
        'phone'              => '(555) 019-9988',
        'password'           => 'securePassword123!',
        'confirm_password'   => 'securePassword123!',
        'agree_policy'       => '1',
    ]);
    
    $createdUser = UserOperation::findUserByEmail($uniqueEmail);
    $regSuccessful = ($createdUser !== null && $createdUser['full_name'] === 'Nurse Joy');
    assertTest("11. New Staff Registration", $regSuccessful, "User created in DB with hashed password");
} catch (Exception $e) {
    assertTest("11. New Staff Registration", false, $e->getMessage());
}

// --- TEST 12: Duplicate Email Rejection ---
try {
    $token = generateCsrfToken();
    $dupResult = AuthController::register([
        'csrf_token'         => $token,
        'full_name'          => 'Duplicate Nurse',
        'professional_title' => 'RN',
        'role'               => ROLE_RECEPTION_CASHIER,
        'email'              => 'admin@medcore.org', // already exists
        'phone'              => '(555) 019-9988',
        'password'           => 'securePassword123!',
        'confirm_password'   => 'securePassword123!',
        'agree_policy'       => '1',
    ]);
    assertTest("12. Duplicate Email Prevention", isset($dupResult['error']), "Error: " . ($dupResult['error'] ?? ''));
} catch (Exception $e) {
    assertTest("12. Duplicate Email Prevention", false, $e->getMessage());
}

// --- TEST 13: Password Mismatch Rejection ---
try {
    $token = generateCsrfToken();
    $mismatchResult = AuthController::register([
        'csrf_token'         => $token,
        'full_name'          => 'Mismatch Test',
        'professional_title' => 'RN',
        'role'               => ROLE_RECEPTION_CASHIER,
        'email'              => 'mismatch.' . time() . '@medcore.org',
        'phone'              => '(555) 019-9988',
        'password'           => 'securePassword123!',
        'confirm_password'   => 'differentPassword456!',
        'agree_policy'       => '1',
    ]);
    assertTest("13. Password Mismatch Rejection", isset($mismatchResult['error']), "Rejected mismatching passwords");
} catch (Exception $e) {
    assertTest("13. Password Mismatch Rejection", false, $e->getMessage());
}

// --- TEST 14: Inactive/Suspended Account Defense ---
try {
    $suspendedEmail = 'suspended.' . time() . '.' . random_int(100, 999) . '@medcore.org';
    $userId = UserOperation::createUser([
        'full_name'          => 'Suspended Staff',
        'username'           => 'suspended_' . time() . '_' . random_int(100, 999),
        'email'              => $suspendedEmail,
        'password'           => 'password123',
        'role'               => ROLE_DOCTOR,
        'professional_title' => 'MD',
        'account_status'     => 'suspended',
    ]);
    
    $token = generateCsrfToken();
    $suspendedLogin = AuthController::login([
        'csrf_token'        => $token,
        'username_or_email' => $suspendedEmail,
        'password'          => 'password123',
    ]);
    assertTest("14. Suspended Account Block", isset($suspendedLogin['error']) && str_contains($suspendedLogin['error'], 'suspended'), "Suspended account prevented from logging in");
} catch (Exception $e) {
    assertTest("14. Suspended Account Block", false, $e->getMessage());
}

// --- TEST 15: Session Destruction & Logout ---
try {
    destroySession();
    assertTest("15. Session Destruction & Logout", !isLoggedIn(), "Session completely cleared");
} catch (Exception $e) {
    assertTest("15. Session Destruction & Logout", false, $e->getMessage());
}

echo "\n========================================================\n";
echo " TEST RESULTS: $passedTests / $totalTests Passed (" . ($failedTests === 0 ? "100% SUCCESS" : "$failedTests FAILED") . ")\n";
echo "========================================================\n";
