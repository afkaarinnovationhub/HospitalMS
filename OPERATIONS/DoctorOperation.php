<?php
/**
 * MedCore Systems - Doctor Directory & Staff Operations Engine
 * Handles doctor registration, specialty management, workload tracking, and staff management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/security.php';

class DoctorOperation
{
    /**
     * Registers a new doctor account in the users directory.
     *
     * @param array $data
     * @return int Created Doctor User ID
     */
    public static function createDoctor(array $data): int
    {
        $pdo = getDBConnection();

        $fullName = trim($data['full_name'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? 'doctor123';
        $title    = trim($data['professional_title'] ?? 'General Practitioner');
        $fee      = max(0.0, (float)($data['consultation_fee'] ?? 10.00));
        $phone    = trim($data['phone'] ?? '');
        $status   = in_array($data['account_status'] ?? 'active', ['active', 'inactive', 'suspended', 'pending'], true) ? $data['account_status'] : 'active';

        if (empty($fullName) || empty($username) || empty($email)) {
            throw new InvalidArgumentException('Doctor name, username, and email address are required.');
        }

        // Check for duplicate username or email
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username OR email = :email");
        $stmtCheck->execute([':username' => $username, ':email' => $email]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            throw new InvalidArgumentException('A staff account with this username or email already exists.');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, username, email, password_hash, role, professional_title, consultation_fee, phone, account_status)
            VALUES (:name, :username, :email, :hash, 'doctor', :title, :fee, :phone, :status)
        ");

        $stmt->execute([
            ':name'     => $fullName,
            ':username' => $username,
            ':email'    => $email,
            ':hash'     => $passwordHash,
            ':title'    => $title,
            ':fee'      => $fee,
            ':phone'    => $phone,
            ':status'   => $status,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Updates an existing doctor's profile and specialty.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateDoctor(int $id, array $data): bool
    {
        $pdo = getDBConnection();

        $fullName = trim($data['full_name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $title    = trim($data['professional_title'] ?? 'General Practitioner');
        $fee      = isset($data['consultation_fee']) ? max(0.0, (float)$data['consultation_fee']) : 10.00;
        $phone    = trim($data['phone'] ?? '');
        $status   = in_array($data['account_status'] ?? 'active', ['active', 'inactive', 'suspended'], true) ? $data['account_status'] : 'active';

        $passSql = '';
        $params = [
            ':name'   => $fullName,
            ':email'  => $email,
            ':title'  => $title,
            ':fee'    => $fee,
            ':phone'  => $phone,
            ':status' => $status,
            ':id'     => $id,
        ];

        if (!empty($data['password'])) {
            $passSql = ', password_hash = :hash';
            $params[':hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = :name,
                email = :email,
                professional_title = :title,
                consultation_fee = :fee,
                phone = :phone,
                account_status = :status
                {$passSql}
            WHERE id = :id
        ");

        return $stmt->execute($params);
    }

    /**
     * Deletes a doctor account safely cleaning related consultation/queue references.
     *
     * @param int $id
     * @return bool
     */
    public static function deleteDoctor(int $id): bool
    {
        $pdo = getDBConnection();
        $pdo->prepare("DELETE FROM lab_orders WHERE doctor_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM consultations WHERE doctor_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE patient_queues SET doctor_id = NULL WHERE doctor_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'doctor'");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Retrieves a doctor by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getDoctorById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, full_name, username, email, role, professional_title, consultation_fee, phone, account_status, created_at FROM users WHERE id = :id AND role = 'doctor' LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retrieves all doctors with live patient queue counts for today.
     *
     * @param string $search
     * @param string $statusFilter
     * @return array
     */
    public static function getAllDoctors(string $search = '', string $statusFilter = ''): array
    {
        $pdo = getDBConnection();

        $where = ["u.role = 'doctor'"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(u.full_name LIKE :q1 OR u.username LIKE :q2 OR u.professional_title LIKE :q3)";
            $params[':q1'] = "%{$search}%";
            $params[':q2'] = "%{$search}%";
            $params[':q3'] = "%{$search}%";
        }

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $where[] = "u.account_status = :status";
            $params[':status'] = $statusFilter;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT 
                u.id, 
                u.full_name, 
                u.username, 
                u.email, 
                u.role, 
                u.professional_title, 
                u.consultation_fee,
                u.phone, 
                u.account_status, 
                u.created_at,
                (
                    SELECT COUNT(*) 
                    FROM patient_queues q 
                    WHERE q.doctor_id = u.id 
                      AND DATE(q.queued_at) = CURDATE() 
                      AND q.status = 'waiting'
                ) as waiting_count,
                (
                    SELECT COUNT(*) 
                    FROM patient_queues q 
                    WHERE q.doctor_id = u.id 
                      AND DATE(q.queued_at) = CURDATE() 
                      AND q.status = 'completed'
                ) as completed_today_count,
                (
                    SELECT COUNT(*) 
                    FROM patient_queues q 
                    WHERE q.doctor_id = u.id 
                      AND DATE(q.queued_at) = CURDATE()
                ) as total_today_count
            FROM users u
            WHERE {$whereSql}
            ORDER BY u.full_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
