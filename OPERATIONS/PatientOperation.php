<?php
/**
 * MedCore Systems - Patient Clinical Operations Engine
 * Handles patient registration, MRN generation, triage vitals, queue routing, and comprehensive medical records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';

class PatientOperation
{
    /**
     * Generates a unique Medical Record Number (MRN).
     *
     * @return string
     */
    public static function generateUniqueMRN(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');

        do {
            $randomDigits = str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $mrn = "PAT-{$year}-{$randomDigits}";

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE mrn = ?");
            $stmt->execute([$mrn]);
            $exists = (int)$stmt->fetchColumn() > 0;
        } while ($exists);

        return $mrn;
    }

    /**
     * Registers a new patient into the master clinical directory.
     *
     * @param array $data
     * @return int Created Patient ID
     */
    public static function registerPatient(array $data): int
    {
        $pdo = getDBConnection();

        $mrn = !empty($data['mrn']) ? trim($data['mrn']) : self::generateUniqueMRN();
        $firstName = trim($data['first_name'] ?? '');
        $lastName  = trim($data['last_name'] ?? '');
        $genderRaw = $data['gender'] ?? 'male';
        $gender    = in_array($genderRaw, ['male', 'female', 'other'], true) ? $genderRaw : 'male';
        $dob       = !empty($data['dob']) ? $data['dob'] : null;
        $phone     = trim($data['phone'] ?? '');
        $email     = !empty($data['email']) ? trim($data['email']) : null;
        $address   = !empty($data['address']) ? trim($data['address']) : null;
        $bloodGroup = !empty($data['blood_group']) ? trim($data['blood_group']) : null;
        $allergies = !empty($data['allergies']) ? trim($data['allergies']) : null;
        $medicalHistory = !empty($data['medical_history']) ? trim($data['medical_history']) : null;
        $emergencyName  = !empty($data['emergency_contact_name']) ? trim($data['emergency_contact_name']) : null;
        $emergencyPhone = !empty($data['emergency_contact_phone']) ? trim($data['emergency_contact_phone']) : null;
        $registeredBy   = !empty($data['registered_by']) ? (int)$data['registered_by'] : null;

        if (empty($firstName) || empty($lastName) || empty($phone)) {
            throw new InvalidArgumentException('First name, last name, and phone number are required.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO patients (mrn, first_name, last_name, gender, dob, phone, email, address, blood_group, allergies, medical_history, emergency_contact_name, emergency_contact_phone, registered_by)
            VALUES (:mrn, :first_name, :last_name, :gender, :dob, :phone, :email, :address, :blood_group, :allergies, :medical_history, :emergency_name, :emergency_phone, :registered_by)
        ");

        $stmt->execute([
            ':mrn'            => $mrn,
            ':first_name'     => $firstName,
            ':last_name'      => $lastName,
            ':gender'         => $gender,
            ':dob'            => $dob,
            ':phone'          => $phone,
            ':email'          => $email,
            ':address'        => $address,
            ':blood_group'    => $bloodGroup,
            ':allergies'      => $allergies,
            ':medical_history'=> $medicalHistory,
            ':emergency_name' => $emergencyName,
            ':emergency_phone'=> $emergencyPhone,
            ':registered_by'  => $registeredBy,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Updates an existing patient's details.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updatePatient(int $id, array $data): bool
    {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            UPDATE patients
            SET first_name = :first_name,
                last_name = :last_name,
                gender = :gender,
                dob = :dob,
                phone = :phone,
                email = :email,
                address = :address,
                blood_group = :blood_group,
                allergies = :allergies,
                medical_history = :medical_history,
                emergency_contact_name = :emergency_name,
                emergency_contact_phone = :emergency_phone
            WHERE id = :id
        ");

        return $stmt->execute([
            ':first_name'     => trim($data['first_name']),
            ':last_name'      => trim($data['last_name']),
            ':gender'         => $data['gender'] ?? 'male',
            ':dob'            => $data['dob'],
            ':phone'          => trim($data['phone']),
            ':email'          => !empty($data['email']) ? trim($data['email']) : null,
            ':address'        => !empty($data['address']) ? trim($data['address']) : null,
            ':blood_group'    => !empty($data['blood_group']) ? trim($data['blood_group']) : null,
            ':allergies'      => !empty($data['allergies']) ? trim($data['allergies']) : 'None known',
            ':medical_history'=> !empty($data['medical_history']) ? trim($data['medical_history']) : null,
            ':emergency_name' => !empty($data['emergency_contact_name']) ? trim($data['emergency_contact_name']) : null,
            ':emergency_phone'=> !empty($data['emergency_contact_phone']) ? trim($data['emergency_contact_phone']) : null,
            ':id'             => $id,
        ]);
    }

    /**
     * Retrieves a patient by ID with latest vitals attached.
     *
     * @param int $id
     * @return array|null
     */
    public static function getPatientById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                   CONCAT(p.first_name, ' ', p.last_name) as full_name
            FROM patients p
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $patient = $stmt->fetch();
        if ($patient) {
            $patient['vitals'] = self::getLatestVitals((int)$patient['id']);
        }
        return $patient ?: null;
    }

    /**
     * Retrieves a patient by MRN with latest vitals attached.
     *
     * @param string $mrn
     * @return array|null
     */
    public static function getPatientByMRN(string $mrn): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                   CONCAT(p.first_name, ' ', p.last_name) as full_name
            FROM patients p
            WHERE p.mrn = :mrn
            LIMIT 1
        ");
        $stmt->execute([':mrn' => trim($mrn)]);
        $patient = $stmt->fetch();
        if ($patient) {
            $patient['vitals'] = self::getLatestVitals((int)$patient['id']);
        }
        return $patient ?: null;
    }

    /**
     * Searches patients by name, MRN, or phone number with optional doctor assignment filtering.
     *
     * @param string $query
     * @param int $limit
     * @param int|null $doctorId
     * @return array
     */
    public static function searchPatients(string $query = '', int $limit = 50, ?int $doctorId = null): array
    {
        $pdo = getDBConnection();
        $trimmed = trim($query);

        $docWhere = "";
        $params = [];

        if ($doctorId !== null) {
            $docWhere = " AND (EXISTS (SELECT 1 FROM patient_queues pq WHERE pq.patient_id = p.id AND pq.doctor_id = :doc_id) 
                           OR EXISTS (SELECT 1 FROM consultations c WHERE c.patient_id = p.id AND c.doctor_id = :doc_id_c))";
            $params[':doc_id'] = $doctorId;
            $params[':doc_id_c'] = $doctorId;
        }

        if ($trimmed === '') {
            $sql = "
                SELECT p.*, 
                       TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                       CONCAT(p.first_name, ' ', p.last_name) as full_name
                FROM patients p
                WHERE 1=1 {$docWhere}
                ORDER BY p.created_at DESC
                LIMIT :limit
            ";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $like = "%{$trimmed}%";
        $sql = "
            SELECT p.*, 
                   TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                   CONCAT(p.first_name, ' ', p.last_name) as full_name
            FROM patients p
            WHERE (p.first_name LIKE :q1 
               OR p.last_name LIKE :q2 
               OR p.mrn LIKE :q3 
               OR p.phone LIKE :q4)
               {$docWhere}
            ORDER BY p.created_at DESC
            LIMIT :limit
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':q1', $like, PDO::PARAM_STR);
        $stmt->bindValue(':q2', $like, PDO::PARAM_STR);
        $stmt->bindValue(':q3', $like, PDO::PARAM_STR);
        $stmt->bindValue(':q4', $like, PDO::PARAM_STR);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Records triage vitals for a patient.
     *
     * @param array $data
     * @return int Created Vitals ID
     */
    public static function recordVitals(array $data): int
    {
        $pdo = getDBConnection();

        $patientId = (int)$data['patient_id'];
        $systolic  = !empty($data['systolic']) ? (int)$data['systolic'] : null;
        $diastolic = !empty($data['diastolic']) ? (int)$data['diastolic'] : null;
        $heartRate = !empty($data['heart_rate']) ? (int)$data['heart_rate'] : null;
        $temperature = !empty($data['temperature']) ? (float)$data['temperature'] : null;
        $respiratoryRate = !empty($data['respiratory_rate']) ? (int)$data['respiratory_rate'] : null;
        $spo2 = !empty($data['spo2_oxygen']) ? (int)$data['spo2_oxygen'] : null;
        $weight = !empty($data['weight_kg']) ? (float)$data['weight_kg'] : null;
        $height = !empty($data['height_cm']) ? (float)$data['height_cm'] : null;

        // Auto-calculate BMI if height and weight provided: BMI = weight (kg) / (height (m))^2
        $bmi = null;
        if ($weight && $height && $height > 0) {
            $heightMeters = $height / 100.0;
            $bmi = round($weight / ($heightMeters * $heightMeters), 1);
        }

        $chiefComplaint = !empty($data['chief_complaint']) ? trim($data['chief_complaint']) : null;
        $triageLevel    = in_array($data['triage_level'] ?? 'routine', ['routine', 'urgent', 'emergency'], true) ? $data['triage_level'] : 'routine';
        $recordedBy     = !empty($data['recorded_by']) ? (int)$data['recorded_by'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO patient_vitals (patient_id, systolic, diastolic, heart_rate, temperature, respiratory_rate, spo2_oxygen, weight_kg, height_cm, bmi, chief_complaint, triage_level, recorded_by)
            VALUES (:patient_id, :systolic, :diastolic, :heart_rate, :temperature, :respiratory_rate, :spo2, :weight, :height, :bmi, :chief_complaint, :triage_level, :recorded_by)
        ");

        $stmt->execute([
            ':patient_id'       => $patientId,
            ':systolic'         => $systolic,
            ':diastolic'        => $diastolic,
            ':heart_rate'       => $heartRate,
            ':temperature'      => $temperature,
            ':respiratory_rate' => $respiratoryRate,
            ':spo2'             => $spo2,
            ':weight'           => $weight,
            ':height'           => $height,
            ':bmi'              => $bmi,
            ':chief_complaint'  => $chiefComplaint,
            ':triage_level'     => $triageLevel,
            ':recorded_by'      => $recordedBy,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves the latest vitals for a patient.
     *
     * @param int $patientId
     * @return array|null
     */
    public static function getLatestVitals(int $patientId): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT v.*, u.full_name as recorded_by_name
            FROM patient_vitals v
            LEFT JOIN users u ON v.recorded_by = u.id
            WHERE v.patient_id = :id
            ORDER BY v.recorded_at DESC
            LIMIT 1
        ");
        $stmt->execute([':id' => $patientId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Generates a daily sequential token number starting from 1 (e.g. T-001, T-002, T-003).
     * Automatically resets to 1 each day based on CURDATE().
     *
     * @return string
     */
    public static function generateDailyTokenNumber(): string
    {
        $pdo = getDBConnection();
        $stmt = $pdo->query("SELECT COUNT(*) FROM patient_queues WHERE DATE(queued_at) = CURDATE()");
        $todayCount = (int)$stmt->fetchColumn();
        return 'T-' . str_pad((string)($todayCount + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Adds a patient to the doctor waiting queue.
     *
     * @param int $patientId
     * @param int|null $doctorId
     * @param string $department
     * @param string $priority 'normal'|'urgent'|'emergency'
     * @param int $queuedBy
     * @return int Created Queue ID
     */
    public static function addToQueue(int $patientId, ?int $doctorId, string $department = 'General OPD', string $priority = 'normal', int $queuedBy = 1): int
    {
        $pdo = getDBConnection();

        // If patient already has an active queue entry (waiting/in_consultation), reassign that entry to avoid duplicates
        $stmtExisting = $pdo->prepare("
            SELECT id FROM patient_queues 
            WHERE patient_id = :pat_id AND status IN ('waiting', 'in_consultation') 
            ORDER BY id DESC LIMIT 1
        ");
        $stmtExisting->execute([':pat_id' => $patientId]);
        $existingId = $stmtExisting->fetchColumn();

        if ($existingId) {
            $stmtUpd = $pdo->prepare("
                UPDATE patient_queues 
                SET doctor_id = :doc_id,
                    department = :dept,
                    priority = :priority
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':doc_id'   => $doctorId ?: null,
                ':dept'     => $department ?: 'General OPD',
                ':priority' => in_array($priority, ['normal', 'urgent', 'emergency'], true) ? $priority : 'normal',
                ':id'       => (int)$existingId,
            ]);
            return (int)$existingId;
        }

        // Generate daily resetting token number (e.g. T-001, T-002) for new visits
        $token = self::generateDailyTokenNumber();

        $stmt = $pdo->prepare("
            INSERT INTO patient_queues (token_number, patient_id, doctor_id, department, priority, status, queued_by)
            VALUES (:token, :patient_id, :doctor_id, :department, :priority, 'waiting', :queued_by)
        ");

        $stmt->execute([
            ':token'      => $token,
            ':patient_id' => $patientId,
            ':doctor_id'  => $doctorId ?: null,
            ':department' => $department ?: 'General OPD',
            ':priority'   => in_array($priority, ['normal', 'urgent', 'emergency'], true) ? $priority : 'normal',
            ':queued_by'  => $queuedBy,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves active patient queue list with patient and triage vitals data.
     *
     * @param string|array|null $status 'waiting'|'in_consultation'|'completed'|'all' or array of options
     * @param int|null $doctorId
     * @param string|null $department
     * @return array
     */
    public static function getQueue($status = 'waiting', ?int $doctorId = null, ?string $department = null, ?string $search = null): array
    {
        if (is_array($status)) {
            $options = $status;
            $status     = $options['status'] ?? 'waiting';
            $doctorId   = !empty($options['doctor_id']) ? (int)$options['doctor_id'] : $doctorId;
            $department = !empty($options['department']) ? $options['department'] : $department;
            $search     = !empty($options['search']) ? $options['search'] : $search;
        }

        $pdo = getDBConnection();

        $where = [];
        $params = [];

        if (is_array($status)) {
            $placeholders = [];
            foreach ($status as $idx => $st) {
                $pName = ":st_{$idx}";
                $placeholders[] = $pName;
                $params[$pName] = $st;
            }
            $where[] = "q.status IN (" . implode(',', $placeholders) . ")";
        } elseif ($status === 'active') {
            $where[] = "q.status IN ('waiting', 'in_consultation', 'on_hold', 'in_lab', 'lab_completed')";
        } elseif ($status && $status !== 'all') {
            $where[] = "q.status = :status";
            $params[':status'] = $status;
        }

        if ($doctorId) {
            $where[] = "(q.doctor_id = :doc_id OR q.doctor_id IS NULL)";
            $params[':doc_id'] = $doctorId;
        }

        if ($department) {
            $where[] = "q.department = :dept";
            $params[':dept'] = $department;
        }

        if (!empty($search)) {
            $sVal = '%' . trim($search) . '%';
            $where[] = "(p.first_name LIKE :s1 OR p.last_name LIKE :s2 OR CONCAT(p.first_name, ' ', p.last_name) LIKE :s3 OR p.mrn LIKE :s4 OR p.phone LIKE :s5 OR q.token_number LIKE :s6)";
            $params[':s1'] = $sVal;
            $params[':s2'] = $sVal;
            $params[':s3'] = $sVal;
            $params[':s4'] = $sVal;
            $params[':s5'] = $sVal;
            $params[':s6'] = $sVal;
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT 
                q.*,
                p.mrn,
                p.first_name,
                p.last_name,
                p.gender,
                p.dob,
                p.phone,
                p.blood_group,
                p.allergies,
                COALESCE(p.account_credit, 0.00) as account_credit,
                TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                u.full_name as doctor_name,
                COALESCE(u.consultation_fee, 10.00) as current_doctor_fee,
                inv.id as invoice_id,
                inv.net_total as invoice_total,
                COALESCE(inv.paid_amount, 0.00) as invoice_paid,
                COALESCE(inv.due_amount, 0.00) as invoice_due,
                COALESCE(inv.payment_status, 'pending') as invoice_status,
                v.systolic,
                v.diastolic,
                v.heart_rate,
                v.temperature,
                v.spo2_oxygen,
                v.chief_complaint,
                v.triage_level
            FROM patient_queues q
            JOIN patients p ON q.patient_id = p.id
            LEFT JOIN users u ON q.doctor_id = u.id
            LEFT JOIN invoices inv ON inv.queue_id = q.id AND inv.bill_type = 'consultation'
            LEFT JOIN (
                SELECT v1.* 
                FROM patient_vitals v1
                INNER JOIN (
                    SELECT patient_id, MAX(recorded_at) as max_date 
                    FROM patient_vitals 
                    GROUP BY patient_id
                ) v2 ON v1.patient_id = v2.patient_id AND v1.recorded_at = v2.max_date
            ) v ON p.id = v.patient_id
            {$whereSql}
            ORDER BY 
                FIELD(q.status, 'in_consultation', 'waiting', 'on_hold', 'lab_completed', 'in_lab', 'completed', 'cancelled'),
                FIELD(q.priority, 'emergency', 'urgent', 'normal'),
                q.queued_at ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Updates queue status (e.g. 'in_consultation', 'completed', 'cancelled').
     *
     * @param int $queueId
     * @param string $newStatus
     * @return bool
     */
    public static function updateQueueStatus(int $queueId, string $newStatus): bool
    {
        $pdo = getDBConnection();
        $allowed = ['waiting', 'in_consultation', 'on_hold', 'completed', 'cancelled'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException("Invalid queue status '{$newStatus}'.");
        }

        if ($newStatus === 'in_consultation') {
            return self::callPatientForDoctor($queueId);
        }

        $compSql = ($newStatus === 'completed') ? ', completed_at = NOW()' : '';

        $stmt = $pdo->prepare("
            UPDATE patient_queues 
            SET status = :status {$compSql}
            WHERE id = :id
        ");

        return $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $queueId,
        ]);
    }

    /**
     * Calls a specific patient for a doctor encounter.
     * Moves any previous active encounter for this doctor to 'on_hold' so the queue is never blocked.
     *
     * @param int $queueId
     * @param int|null $doctorId
     * @return bool
     */
    public static function callPatientForDoctor(int $queueId, ?int $doctorId = null): bool
    {
        $pdo = getDBConnection();

        if ($doctorId === null) {
            $stmtDoc = $pdo->prepare("SELECT doctor_id FROM patient_queues WHERE id = ?");
            $stmtDoc->execute([$queueId]);
            $doctorId = (int)$stmtDoc->fetchColumn();
        }

        if ($doctorId > 0) {
            $stmtHold = $pdo->prepare("
                UPDATE patient_queues 
                SET status = 'on_hold' 
                WHERE doctor_id = :doc_id AND status = 'in_consultation' AND id != :queue_id
            ");
            $stmtHold->execute([
                ':doc_id'   => $doctorId,
                ':queue_id' => $queueId,
            ]);
        }

        $stmtCall = $pdo->prepare("
            UPDATE patient_queues 
            SET status = 'in_consultation', called_at = NOW() 
            WHERE id = :queue_id
        ");
        return $stmtCall->execute([':queue_id' => $queueId]);
    }

    /**
     * Retrieves all active clinical doctors from users table.
     *
     * @return array
     */
    public static function getDoctorsList(): array
    {
        $pdo = getDBConnection();
        return $pdo->query("
            SELECT id, full_name, username, role, professional_title, consultation_fee, account_status 
            FROM users 
            WHERE role = 'doctor' AND account_status = 'active' 
            ORDER BY full_name ASC
        ")->fetchAll();
    }

    /**
     * Quick Front-Desk Check-in:
     * Takes basic info (Name, Phone, Doctor) -> Auto creates or looks up permanent patient record with MRN ->
     * Computes queue number/position for the doctor -> Routes to doctor queue!
     *
     * @param array $data
     * @return array
     */
    public static function quickReceptionCheckIn(array $data): array
    {
        $pdo = getDBConnection();
        $firstName = trim($data['first_name'] ?? '');
        $lastName  = trim($data['last_name'] ?? '');
        $phone     = trim($data['phone'] ?? '');
        $genderRaw = $data['gender'] ?? 'male';
        $gender    = in_array($genderRaw, ['male', 'female', 'other'], true) ? $genderRaw : 'male';
        $doctorId   = !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null;
        $department = !empty($data['department']) ? trim($data['department']) : 'General OPD';
        $rawPriority = $data['priority'] ?? 'normal';
        $priority    = in_array($rawPriority, ['normal', 'urgent', 'emergency'], true) ? $rawPriority : 'normal';
        $chiefComplaint = trim($data['chief_complaint'] ?? '');
        $userId    = !empty($data['user_id']) ? (int)$data['user_id'] : 1;

        // Custom or Dynamic Doctor Consultation Fee
        $consultationFee = isset($data['consultation_fee']) ? max(0.0, (float)$data['consultation_fee']) : null;
        if ($consultationFee === null) {
            if ($doctorId) {
                $stmtF = $pdo->prepare("SELECT consultation_fee FROM users WHERE id = ?");
                $stmtF->execute([$doctorId]);
                $docFee = $stmtF->fetchColumn();
                $consultationFee = ($docFee !== false && $docFee !== null) ? (float)$docFee : 10.00;
            } else {
                $consultationFee = 10.00;
            }
        }

        if (empty($firstName) || empty($phone)) {
            throw new InvalidArgumentException('Patient name and phone number are required for quick intake.');
        }

        // 1. Check if patient already exists by phone or MRN
        $existingPatient = null;
        if (!empty($data['mrn'])) {
            $existingPatient = self::getPatientByMRN($data['mrn']);
        }
        if (!$existingPatient && !empty($phone)) {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE phone = :phone LIMIT 1");
            $stmt->execute([':phone' => $phone]);
            $existingPatient = $stmt->fetch() ?: null;
        }

        $isNew = false;
        if ($existingPatient) {
            $patientId = (int)$existingPatient['id'];
            $patient = self::getPatientById($patientId);
        } else {
            $isNew = true;
            // If last name is empty, split first name or set to 'Patient'
            if (empty($lastName)) {
                $parts = explode(' ', $firstName, 2);
                $firstName = $parts[0];
                $lastName  = $parts[1] ?? 'Patient';
            }
            $patientId = self::registerPatient([
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'gender'        => $gender,
                'dob'           => !empty($data['dob']) ? $data['dob'] : null,
                'phone'         => $phone,
                'registered_by' => $userId,
            ]);
            $patient = self::getPatientById($patientId);
        }

        // Optional: Record quick vitals if provided
        if (!empty($data['systolic']) || !empty($chiefComplaint) || !empty($data['temperature'])) {
            self::recordVitals([
                'patient_id'      => $patientId,
                'systolic'        => !empty($data['systolic']) ? (int)$data['systolic'] : null,
                'diastolic'       => !empty($data['diastolic']) ? (int)$data['diastolic'] : null,
                'heart_rate'      => !empty($data['heart_rate']) ? (int)$data['heart_rate'] : null,
                'temperature'     => !empty($data['temperature']) ? (float)$data['temperature'] : null,
                'chief_complaint' => $chiefComplaint ?: 'Quick walk-in check-in',
                'triage_level'    => $priority,
                'recorded_by'     => $userId,
            ]);
        }

        // 2. Calculate Doctor's queue position for today
        $docTodayCount = 0;
        if ($doctorId) {
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE doctor_id = :doc_id AND DATE(queued_at) = CURDATE() AND status != 'cancelled'");
            $stmtCount->execute([':doc_id' => $doctorId]);
            $docTodayCount = (int)$stmtCount->fetchColumn();
        }
        $queuePosition = $docTodayCount + 1;

        // 3. Add to Queue
        $queueId = self::addToQueue($patientId, $doctorId, $department, $priority, $userId);

        $stmtQ = $pdo->prepare("SELECT * FROM patient_queues WHERE id = ?");
        $stmtQ->execute([$queueId]);
        $queueRecord = $stmtQ->fetch();

        // 4. Auto-Generate Consultation Fee Invoice for cashiering
        require_once __DIR__ . '/BillingOperation.php';
        $invoiceId = 0;
        try {
            $invoiceId = BillingOperation::createConsultationInvoice(
                $patientId,
                $queueId,
                $doctorId,
                $consultationFee,
                $queueRecord['token_number'],
                $userId
            );
        } catch (Exception $e) {
            error_log('[HPMS INTAKE BILLING ERROR] ' . $e->getMessage());
        }

        $doctorName = 'Next Available Doctor';
        if ($doctorId) {
            $stmtDoc = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmtDoc->execute([$doctorId]);
            $dName = $stmtDoc->fetchColumn();
            if ($dName) {
                $doctorName = $dName;
            }
        }

        return [
            'patient'           => $patient,
            'queue'             => $queueRecord,
            'queue_id'          => $queueId,
            'invoice_id'        => $invoiceId,
            'token_number'      => $queueRecord['token_number'],
            'queue_position'    => $queuePosition,
            'doctor_name'       => $doctorName,
            'department'        => $department,
            'priority'          => $priority,
            'consultation_fee'  => $consultationFee,
            'is_new_patient'    => $isNew,
        ];
    }

    /**
     * Safely deletes a patient and cascade records.
     *
     * @param int $id
     * @return bool
     */
    public static function deletePatient(int $id): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("DELETE FROM patients WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Removes an entry from the patient queue.
     *
     * @param int $queueId
     * @return bool
     */
    public static function deleteQueueItem(int $queueId): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("DELETE FROM patient_queues WHERE id = :id");
        return $stmt->execute([':id' => $queueId]);
    }

    /**
     * Updates an existing queue assignment (Doctor, Dept, Priority).
     *
     * @param int $queueId
     * @param array $data
     * @return bool
     */
    public static function updateQueueItem(int $queueId, array $data): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            UPDATE patient_queues
            SET doctor_id = :doc_id,
                department = :dept,
                priority = :priority
            WHERE id = :id
        ");
        return $stmt->execute([
            ':doc_id'   => !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null,
            ':dept'     => trim($data['department'] ?? 'General OPD'),
            ':priority' => in_array($data['priority'] ?? 'normal', ['normal', 'urgent', 'emergency'], true) ? $data['priority'] : 'normal',
            ':id'       => $queueId,
        ]);
    }

    /**
     * Retrieves summary KPIs for Reception / Patients module with optional doctor scoping.
     *
     * @param int|null $doctorId
     * @return array
     */
    public static function getPatientSummaryKPIs(?int $doctorId = null): array
    {
        $pdo = getDBConnection();

        if ($doctorId !== null) {
            $stmtTot = $pdo->prepare("
                SELECT COUNT(DISTINCT p.id) FROM patients p
                WHERE EXISTS (SELECT 1 FROM patient_queues pq WHERE pq.patient_id = p.id AND pq.doctor_id = :d1)
                   OR EXISTS (SELECT 1 FROM consultations c WHERE c.patient_id = p.id AND c.doctor_id = :d2)
            ");
            $stmtTot->execute([':d1' => $doctorId, ':d2' => $doctorId]);
            $totalPatients = (int)$stmtTot->fetchColumn();

            $stmtReg = $pdo->prepare("
                SELECT COUNT(DISTINCT p.id) FROM patients p
                WHERE (EXISTS (SELECT 1 FROM patient_queues pq WHERE pq.patient_id = p.id AND pq.doctor_id = :d1)
                   OR EXISTS (SELECT 1 FROM consultations c WHERE c.patient_id = p.id AND c.doctor_id = :d2))
                   AND DATE(p.created_at) = CURDATE()
            ");
            $stmtReg->execute([':d1' => $doctorId, ':d2' => $doctorId]);
            $todayRegistered = (int)$stmtReg->fetchColumn();

            $stmtW = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE status IN ('waiting', 'on_hold', 'triaged', 'in_lab') AND doctor_id = ?");
            $stmtW->execute([$doctorId]);
            $waitingInQueue = (int)$stmtW->fetchColumn();

            $stmtC = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE status = 'in_consultation' AND doctor_id = ?");
            $stmtC->execute([$doctorId]);
            $inConsultation = (int)$stmtC->fetchColumn();

            $stmtD = $pdo->prepare("SELECT COUNT(*) FROM patient_queues WHERE status = 'completed' AND doctor_id = ? AND DATE(completed_at) = CURDATE()");
            $stmtD->execute([$doctorId]);
            $completedToday = (int)$stmtD->fetchColumn();
        } else {
            $totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
            $todayRegistered = (int)$pdo->query("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            $waitingInQueue = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status IN ('waiting', 'on_hold', 'triaged', 'in_lab')")->fetchColumn();
            $inConsultation = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status = 'in_consultation'")->fetchColumn();
            $completedToday = (int)$pdo->query("SELECT COUNT(*) FROM patient_queues WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn();
        }

        return [
            'total_patients'    => $totalPatients,
            'today_registered'  => $todayRegistered,
            'waiting_in_queue'  => $waitingInQueue,
            'in_consultation'   => $inConsultation,
            'completed_today'   => $completedToday,
        ];
    }

    /**
     * Bulk deletes multiple patients by array of IDs.
     *
     * @param array $ids
     * @return int Number of deleted patients
     */
    public static function bulkDeletePatients(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $pdo = getDBConnection();
        $validIds = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($validIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM patients WHERE id IN ($placeholders)");
        $stmt->execute(array_values($validIds));
        return $stmt->rowCount();
    }

    /**
     * Seeds realistic initial patients, triage vitals, and queue items once.
     * Never auto-reseeds if user has deleted records.
     */
    public static function seedDefaultPatientsIfEmpty(bool $force = false): void
    {
        $pdo = getDBConnection();
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('patients_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        } catch (Exception $e) {}
        return; // Strictly production mode: Never seed mock patients.
    }

    private static function _legacySeedDefaultPatients(): void
    {
        $pdo = getDBConnection();
        $adminUser = 1;
        $doctorUser = 1;
        if (false) {
            $adminUser = (int)($pdo->query("SELECT id FROM users WHERE role = 'superadmin_ict' OR role = 'manager' LIMIT 1")->fetchColumn() ?: 1);
            $doctorUser = (int)($pdo->query("SELECT id FROM users WHERE role = 'doctor' LIMIT 1")->fetchColumn() ?: $adminUser);

            // 1. Patient: Michael Chen (Featured Profile)
            $p1Id = self::registerPatient([
                'mrn'                    => 'PAT-2023-0892',
                'first_name'             => 'Michael',
                'last_name'              => 'Chen',
                'gender'                 => 'male',
                'dob'                    => '1982-04-12',
                'phone'                  => '(555) 234-5678',
                'email'                  => 'm.chen@example.com',
                'address'                => '742 Evergreen Terrace, Springfield',
                'blood_group'            => 'O+',
                'allergies'              => 'Penicillin (Severe hives/rash)',
                'medical_history'        => 'Hypertension diagnosed 2021, Mild intermittent asthma',
                'emergency_contact_name' => 'Sarah Chen (Spouse)',
                'emergency_contact_phone'=> '(555) 234-5679',
                'registered_by'          => $adminUser,
            ]);

            self::recordVitals([
                'patient_id'       => $p1Id,
                'systolic'         => 128,
                'diastolic'        => 82,
                'heart_rate'       => 72,
                'temperature'      => 36.8,
                'respiratory_rate' => 16,
                'spo2_oxygen'      => 98,
                'weight_kg'        => 74.5,
                'height_cm'        => 178.0,
                'chief_complaint'  => 'Follow-up for blood pressure monitoring and mild respiratory cough',
                'triage_level'     => 'routine',
                'recorded_by'      => $adminUser,
            ]);

            self::addToQueue($p1Id, $doctorUser, 'Cardiology OPD', 'normal', $adminUser);

            // 2. Patient: Elena Rodriguez
            $p2Id = self::registerPatient([
                'mrn'                    => 'PAT-2023-0889',
                'first_name'             => 'Elena',
                'last_name'              => 'Rodriguez',
                'gender'                 => 'female',
                'dob'                    => '1990-11-23',
                'phone'                  => '(555) 876-5432',
                'email'                  => 'elena.r@example.com',
                'address'                => '1204 Pine Valley Rd, Springfield',
                'blood_group'            => 'A+',
                'allergies'              => 'None known',
                'medical_history'        => 'Chronic migraine, seasonal allergies',
                'emergency_contact_name' => 'Carlos Rodriguez (Father)',
                'emergency_contact_phone'=> '(555) 876-5430',
                'registered_by'          => $adminUser,
            ]);

            self::recordVitals([
                'patient_id'       => $p2Id,
                'systolic'         => 118,
                'diastolic'        => 76,
                'heart_rate'       => 68,
                'temperature'      => 37.1,
                'respiratory_rate' => 18,
                'spo2_oxygen'      => 99,
                'weight_kg'        => 62.0,
                'height_cm'        => 165.0,
                'chief_complaint'  => 'Severe recurring frontal headache for 3 days',
                'triage_level'     => 'urgent',
                'recorded_by'      => $adminUser,
            ]);

            self::addToQueue($p2Id, $doctorUser, 'Neurology OPD', 'urgent', $adminUser);

            // 3. Patient: Robert Wilson
            $p3Id = self::registerPatient([
                'mrn'                    => 'PAT-2023-0890',
                'first_name'             => 'Robert',
                'last_name'              => 'Wilson',
                'gender'                 => 'male',
                'dob'                    => '1965-08-15',
                'phone'                  => '(555) 345-6789',
                'email'                  => 'rwilson@example.com',
                'address'                => '88 Elmhurst St, Springfield',
                'blood_group'            => 'B+',
                'allergies'              => 'Sulfa drugs',
                'medical_history'        => 'Type 2 Diabetes Mellitus, Dyslipidemia',
                'emergency_contact_name' => 'Emma Wilson (Daughter)',
                'emergency_contact_phone'=> '(555) 345-6780',
                'registered_by'          => $adminUser,
            ]);

            self::recordVitals([
                'patient_id'       => $p3Id,
                'systolic'         => 136,
                'diastolic'        => 88,
                'heart_rate'       => 80,
                'temperature'      => 36.6,
                'respiratory_rate' => 16,
                'spo2_oxygen'      => 97,
                'weight_kg'        => 86.5,
                'height_cm'        => 175.0,
                'chief_complaint'  => 'Routine diabetic checkup and HbA1c review',
                'triage_level'     => 'routine',
                'recorded_by'      => $adminUser,
            ]);

            self::addToQueue($p3Id, $doctorUser, 'Endocrinology OPD', 'normal', $adminUser);

            // 4. Patient: Fatima Jama
            $p4Id = self::registerPatient([
                'mrn'                    => 'PAT-2026-1044',
                'first_name'             => 'Fatima',
                'last_name'              => 'Jama',
                'gender'                 => 'female',
                'dob'                    => '1995-02-14',
                'phone'                  => '(555) 019-2233',
                'email'                  => 'fatima.j@example.com',
                'address'                => '45 Peace Avenue, Mogadishu',
                'blood_group'            => 'O-',
                'allergies'              => 'None known',
                'medical_history'        => 'None',
                'emergency_contact_name' => 'Hassan Jama (Brother)',
                'emergency_contact_phone'=> '(555) 019-2230',
                'registered_by'          => $adminUser,
            ]);

            self::recordVitals([
                'patient_id'       => $p4Id,
                'systolic'         => 115,
                'diastolic'        => 74,
                'heart_rate'       => 70,
                'temperature'      => 36.7,
                'respiratory_rate' => 15,
                'spo2_oxygen'      => 99,
                'weight_kg'        => 58.0,
                'height_cm'        => 163.0,
                'chief_complaint'  => 'General annual health wellness check',
                'triage_level'     => 'routine',
                'recorded_by'      => $adminUser,
            ]);

            self::addToQueue($p4Id, $doctorUser, 'General OPD', 'normal', $adminUser);
        }

        // Record that seeding occurred so it never happens again
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('patients_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        } catch (Exception $e) {
            // ignore
        }
    }
}
