<?php
/**
 * MedCore Systems - User Database Operations
 * Handles user record querying, creation, password hashing, and authentication persistence using Prepared Statements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/auth.php';

class UserOperation
{
    /**
     * Finds a single user by primary ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function findUserById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, email, password_hash, role, professional_title, consultation_fee, phone, account_status, last_login_at, created_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Finds a user by either username or email address.
     *
     * @param string $loginIdentifier
     * @return array|null
     */
    public static function findUserByLogin(string $loginIdentifier): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, email, password_hash, role, professional_title, consultation_fee, phone, account_status, last_login_at, created_at
            FROM users
            WHERE username = :identifier OR email = :identifier_email
            LIMIT 1
        ");
        $stmt->execute([
            ':identifier'       => $loginIdentifier,
            ':identifier_email' => $loginIdentifier,
        ]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Finds a user by email address.
     *
     * @param string $email
     * @return array|null
     */
    public static function findUserByEmail(string $email): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, email, password_hash, role, professional_title, consultation_fee, phone, account_status, last_login_at, created_at
            FROM users 
            WHERE email = :email 
            LIMIT 1
        ");
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Finds a user by username.
     *
     * @param string $username
     * @return array|null
     */
    public static function findUserByUsername(string $username): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT id, full_name, username, email, password_hash, role, professional_title, consultation_fee, phone, account_status, last_login_at, created_at
            FROM users 
            WHERE username = :username 
            LIMIT 1
        ");
        $stmt->execute([':username' => trim($username)]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Creates a new user record with securely hashed password.
     *
     * @param array $data ['full_name', 'username', 'email', 'password', 'role', 'professional_title', 'phone']
     * @return int The newly inserted user ID
     * @throws InvalidArgumentException|RuntimeException
     */
    public static function createUser(array $data): int
    {
        $fullName          = trim($data['full_name'] ?? '');
        $username          = trim($data['username'] ?? '');
        $email             = strtolower(trim($data['email'] ?? ''));
        $plainPassword     = $data['password'] ?? '';
        $role              = $data['role'] ?? '';
        $professionalTitle = trim($data['professional_title'] ?? '');
        $phone             = trim($data['phone'] ?? '');
        $accountStatus     = $data['account_status'] ?? 'active';

        // Validate required fields
        if (empty($fullName) || empty($username) || empty($email) || empty($plainPassword) || empty($role)) {
            throw new InvalidArgumentException('All required fields (full name, username, email, password, role) must be provided.');
        }

        if (!in_array($role, ALL_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role specified.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address format.');
        }

        if (strlen($plainPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        // Check uniqueness
        if (self::findUserByEmail($email)) {
            throw new InvalidArgumentException('A user with this email address already exists.');
        }

        if (self::findUserByUsername($username)) {
            throw new InvalidArgumentException('A user with this username already exists.');
        }

        // Secure password hashing
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, username, email, password_hash, role, professional_title, phone, account_status)
            VALUES (:full_name, :username, :email, :password_hash, :role, :professional_title, :phone, :account_status)
        ");

        $stmt->execute([
            ':full_name'          => $fullName,
            ':username'           => $username,
            ':email'              => $email,
            ':password_hash'      => $passwordHash,
            ':role'               => $role,
            ':professional_title' => $professionalTitle ?: null,
            ':phone'              => $phone ?: null,
            ':account_status'     => $accountStatus,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Updates the last login timestamp for a user.
     *
     * @param int $userId
     * @return bool
     */
    public static function updateLastLogin(int $userId): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $userId]);
    }

    /**
     * Updates a user's password with a new secure hash.
     *
     * @param int $userId
     * @param string $newPassword
     * @return bool
     */
    public static function updateUserPassword(int $userId, string $newPassword): bool
    {
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        return $stmt->execute([
            ':hash' => $passwordHash,
            ':id'   => $userId,
        ]);
    }

    /**
     * Retrieves all users matching optional filters (search, role, status).
     *
     * @param string|null $search
     * @param string|null $role
     * @param string|null $status
     * @return array
     */
    public static function getAllUsers(?string $search = '', ?string $role = '', ?string $status = ''): array
    {
        $pdo = getDBConnection();
        $sql = "
            SELECT id, full_name, username, email, role, professional_title, consultation_fee, phone, account_status, last_login_at, created_at
            FROM users
            WHERE 1=1
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ? OR professional_title LIKE ?)";
            $term = "%{$search}%";
            $params = array_merge($params, [$term, $term, $term, $term, $term]);
        }

        if (!empty($role) && $role !== 'all') {
            $sql .= " AND role = ?";
            $params[] = $role;
        }

        if (!empty($status) && $status !== 'all') {
            $sql .= " AND account_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Updates an existing user record.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateUser(int $id, array $data): bool
    {
        $pdo = getDBConnection();
        $user = self::findUserById($id);
        if (!$user) {
            throw new InvalidArgumentException("User with ID {$id} not found.");
        }

        $fullName          = trim($data['full_name'] ?? $user['full_name']);
        $username          = trim($data['username'] ?? $user['username']);
        $email             = strtolower(trim($data['email'] ?? $user['email']));
        $role              = $data['role'] ?? $user['role'];
        $professionalTitle = trim($data['professional_title'] ?? ($user['professional_title'] ?? ''));
        $phone             = trim($data['phone'] ?? ($user['phone'] ?? ''));
        $accountStatus     = $data['account_status'] ?? $user['account_status'];
        $fee               = isset($data['consultation_fee']) ? (float)$data['consultation_fee'] : null;

        if (empty($fullName) || empty($username) || empty($email) || empty($role)) {
            throw new InvalidArgumentException('Full name, username, email, and role are required.');
        }

        if (!in_array($role, ALL_ROLES, true)) {
            throw new InvalidArgumentException('Invalid user role specified.');
        }

        if (!in_array($accountStatus, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Invalid account status.');
        }

        // Check uniqueness on email / username if changed
        if ($email !== $user['email']) {
            $existing = self::findUserByEmail($email);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new InvalidArgumentException('A user with this email address already exists.');
            }
        }

        if ($username !== $user['username']) {
            $existing = self::findUserByUsername($username);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new InvalidArgumentException('A user with this username already exists.');
            }
        }

        $sql = "
            UPDATE users
            SET full_name = :full_name,
                username = :username,
                email = :email,
                role = :role,
                professional_title = :professional_title,
                phone = :phone,
                account_status = :account_status,
                consultation_fee = :fee
        ";
        $params = [
            ':full_name'          => $fullName,
            ':username'           => $username,
            ':email'              => $email,
            ':role'               => $role,
            ':professional_title' => $professionalTitle ?: null,
            ':phone'              => $phone ?: null,
            ':account_status'     => $accountStatus,
            ':fee'                => ($role === ROLE_DOCTOR) ? $fee : null,
            ':id'                 => $id,
        ];

        // Optional password update
        if (!empty($data['password'])) {
            $newPass = $data['password'];
            if (strlen($newPass) < 8) {
                throw new InvalidArgumentException('New password must be at least 8 characters long.');
            }
            $sql .= ", password_hash = :pass";
            $params[':pass'] = password_hash($newPass, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Toggles a user's account status between 'active' and 'inactive'.
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public static function toggleUserStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Invalid account status.');
        }

        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE users SET account_status = :status WHERE id = :id");
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * Deletes a user record.
     *
     * @param int $id
     * @return bool
     */
    public static function deleteUser(int $id): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Populates standard default clinical and administrative users if the database is currently empty.
     */
    public static function seedDefaultUsersIfEmpty(): void
    {
        $pdo = getDBConnection();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

        if ($count === 0) {
            $defaultUsers = [
                [
                    'full_name'          => 'Superadmin ICT',
                    'username'           => 'admin',
                    'email'              => 'admin@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_SUPERADMIN_ICT,
                    'professional_title' => 'Lead Systems Administrator',
                    'phone'              => '+252 61 000-0001',
                ],
                [
                    'full_name'          => 'Dr. Alan Carter',
                    'username'           => 'doctor_alan',
                    'email'              => 'alan.carter@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_DOCTOR,
                    'professional_title' => 'MD, General Practice',
                    'phone'              => '+252 61 019-2834',
                ],
                [
                    'full_name'          => 'Reception Desk 1',
                    'username'           => 'reception',
                    'email'              => 'reception@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_RECEPTION_CASHIER,
                    'professional_title' => 'Intake & Front Desk Officer',
                    'phone'              => '+252 61 011-2233',
                ],
                [
                    'full_name'          => 'Pharmacy Lead',
                    'username'           => 'pharmacy',
                    'email'              => 'pharmacy@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_PHARMACY,
                    'professional_title' => 'PharmD, Chief Pharmacist',
                    'phone'              => '+252 61 014-5566',
                ],
                [
                    'full_name'          => 'Laboratory Diagnostics',
                    'username'           => 'lab_tech',
                    'email'              => 'lab@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_LABORATORY,
                    'professional_title' => 'Senior Medical Technologist',
                    'phone'              => '+252 61 017-7788',
                ],
                [
                    'full_name'          => 'Clinical Operations Manager',
                    'username'           => 'manager',
                    'email'              => 'manager@cibaarhospital.so',
                    'password'           => 'password123',
                    'role'               => ROLE_MANAGER,
                    'professional_title' => 'Hospital Operations Director',
                    'phone'              => '+252 61 019-9900',
                ],
            ];

            foreach ($defaultUsers as $user) {
                self::createUser($user);
            }
        }
    }
}
