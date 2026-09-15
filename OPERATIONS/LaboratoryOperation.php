<?php
/**
 * MedCore Systems - Diagnostic Laboratory Operations Engine
 * Handles diagnostic test catalogs, specimen tracking, result recording, and billing integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/BillingOperation.php';
require_once __DIR__ . '/PatientOperation.php';

class LaboratoryOperation
{
    /**
     * Ensures laboratory diagnostic categories table exists.
     * Strictly production mode: Does NOT seed any mock categories.
     */
    public static function seedLabCategoriesIfEmpty(): void
    {
        $pdo = getDBConnection();
        // Ensure table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `lab_categories` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(255) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_lab_cat_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * Retrieves all registered laboratory categories.
     *
     * @return array
     */
    public static function getLabCategories(): array
    {
        self::seedLabCategoriesIfEmpty();
        $pdo = getDBConnection();
        $stmt = $pdo->query("SELECT * FROM lab_categories ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    /**
     * Registers a new diagnostic laboratory category.
     *
     * @param string $name
     * @param string|null $description
     * @return int Category ID
     */
    public static function addLabCategory(string $name, ?string $description = null): int
    {
        $pdo = getDBConnection();
        self::seedLabCategoriesIfEmpty();

        $trimmedName = trim($name);
        if (empty($trimmedName)) {
            throw new InvalidArgumentException('Category name cannot be empty.');
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM lab_categories WHERE LOWER(name) = LOWER(?)");
        $stmtCheck->execute([$trimmedName]);
        $existingId = $stmtCheck->fetchColumn();
        if ($existingId) {
            return (int)$existingId;
        }

        $stmt = $pdo->prepare("INSERT INTO lab_categories (name, description) VALUES (?, ?)");
        $stmt->execute([$trimmedName, !empty($description) ? trim($description) : null]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves all registered laboratory categories along with the count of linked tests.
     *
     * @return array
     */
    public static function getLabCategoriesWithCount(): array
    {
        self::seedLabCategoriesIfEmpty();
        $pdo = getDBConnection();
        $sql = "
            SELECT c.*, COUNT(t.id) as test_count
            FROM lab_categories c
            LEFT JOIN lab_tests_catalog t ON t.category = c.name
            GROUP BY c.id, c.name, c.description, c.created_at
            ORDER BY c.name ASC
        ";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Retrieves a single lab category by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getLabCategoryById(int $id): ?array
    {
        self::seedLabCategoriesIfEmpty();
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM lab_categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Updates an existing diagnostic laboratory category.
     *
     * @param int $id
     * @param string $name
     * @param string|null $description
     * @return bool
     */
    public static function updateLabCategory(int $id, string $name, ?string $description = null): bool
    {
        $pdo = getDBConnection();
        self::seedLabCategoriesIfEmpty();

        $trimmedName = trim($name);
        if (empty($trimmedName)) {
            throw new InvalidArgumentException('Category name cannot be empty.');
        }

        $oldCat = self::getLabCategoryById($id);
        if (!$oldCat) {
            throw new RuntimeException('Laboratory category not found.');
        }
        $oldName = $oldCat['name'];

        $stmtCheck = $pdo->prepare("SELECT id FROM lab_categories WHERE LOWER(name) = LOWER(?) AND id != ?");
        $stmtCheck->execute([$trimmedName, $id]);
        if ($stmtCheck->fetchColumn()) {
            throw new RuntimeException('A laboratory category with this name already exists.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE lab_categories SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$trimmedName, !empty($description) ? trim($description) : null, $id]);

            if ($oldName !== $trimmedName) {
                $stmtTests = $pdo->prepare("UPDATE lab_tests_catalog SET category = ? WHERE category = ?");
                $stmtTests->execute([$trimmedName, $oldName]);
            }

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Deletes a laboratory category if no diagnostic tests are linked.
     *
     * @param int $id
     * @return bool
     */
    public static function deleteLabCategory(int $id): bool
    {
        $pdo = getDBConnection();
        self::seedLabCategoriesIfEmpty();

        $cat = self::getLabCategoryById($id);
        if (!$cat) {
            return false;
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM lab_tests_catalog WHERE category = ?");
        $stmtCount->execute([$cat['name']]);
        $count = (int)$stmtCount->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException("Cannot delete category '{$cat['name']}' because it has {$count} diagnostic test(s) linked to it. Please reassign or delete the tests first.");
        }

        $stmt = $pdo->prepare("DELETE FROM lab_categories WHERE id = ?");
        return $stmt->execute([$id]);
    }


    /**
     * Ensures laboratory test catalog table exists.
     * Strictly production mode: Does NOT seed any mock tests.
     */
    public static function seedLabCatalogIfEmpty(): void
    {
        self::seedLabCategoriesIfEmpty();
        $pdo = getDBConnection();
        try {
            $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('lab_catalog_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        } catch (Exception $e) {}
    }

    /**
     * Generates a unique test code for a diagnostic lab test.
     *
     * @param string $testName
     * @return string
     */
    public static function generateLabTestCode(string $testName): string
    {
        $pdo = getDBConnection();
        $cleaned = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $testName));
        $prefix = substr($cleaned, 0, 4) ?: 'TEST';
        $code = "LAB-{$prefix}";

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_tests_catalog WHERE test_code = ?");
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $code;
        }

        $i = 1;
        do {
            $candidate = "LAB-{$prefix}-{$i}";
            $stmt->execute([$candidate]);
            $exists = (int)$stmt->fetchColumn() > 0;
            $i++;
        } while ($exists);

        return $candidate;
    }

    /**
     * Adds a new diagnostic test to the master laboratory catalog.
     *
     * @param array $data
     * @return int Created Lab Test ID
     */
    public static function createLabTest(array $data): int
    {
        $testName = trim($data['test_name'] ?? '');
        if (empty($testName)) {
            throw new InvalidArgumentException('Diagnostic test name is required.');
        }

        $price = max(0.0, (float)($data['price'] ?? 10.00));
        $category = trim($data['category'] ?? 'General Laboratory') ?: 'General Laboratory';
        $turnaround = max(1, (int)($data['turnaround_minutes'] ?? 30));
        $specimenType = trim($data['specimen_type'] ?? 'Venous Blood / Serum') ?: 'Venous Blood / Serum';
        $normalRange = trim($data['normal_range'] ?? 'Negative / Normal Reference');

        $pdo = getDBConnection();

        $testCode = !empty($data['test_code']) ? strtoupper(trim($data['test_code'])) : self::generateLabTestCode($testName);

        $stmt = $pdo->prepare("
            INSERT INTO lab_tests_catalog (test_code, test_name, category, price, turnaround_minutes, specimen_type, normal_range, is_active)
            VALUES (:code, :name, :cat, :price, :turnaround, :specimen, :norm_range, 1)
        ");

        $stmt->execute([
            ':code'       => $testCode,
            ':name'       => $testName,
            ':cat'        => $category,
            ':price'      => $price,
            ':turnaround' => $turnaround,
            ':specimen'   => $specimenType,
            ':norm_range' => $normalRange,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves all active tests from the lab catalog.
     *
     * @return array
     */
    public static function getLabTestCatalog(): array
    {
        $pdo = getDBConnection();
        return $pdo->query("SELECT * FROM lab_tests_catalog WHERE is_active = 1 ORDER BY category ASC, test_name ASC")->fetchAll();
    }

    /**
     * Retrieves all lab tests from the catalog with optional search, category, and status filtering.
     *
     * @param string $search
     * @param string $category
     * @param string $status
     * @return array
     */
    public static function getAllLabCatalogTests(string $search = '', string $category = '', string $status = ''): array
    {
        $pdo = getDBConnection();

        $sql = "SELECT * FROM lab_tests_catalog WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (test_name LIKE :search OR test_code LIKE :search_code OR category LIKE :search_cat)";
            $params[':search']      = '%' . $search . '%';
            $params[':search_code'] = '%' . $search . '%';
            $params[':search_cat']  = '%' . $search . '%';
        }

        if (!empty($category) && $category !== 'all') {
            $sql .= " AND category = :category";
            $params[':category'] = $category;
        }

        if ($status === 'active') {
            $sql .= " AND is_active = 1";
        } elseif ($status === 'inactive') {
            $sql .= " AND is_active = 0";
        }

        $sql .= " ORDER BY category ASC, test_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retrieves a single lab test by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getLabTestById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM lab_tests_catalog WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Updates an existing lab test in the catalog.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateLabTest(int $id, array $data): bool
    {
        $testName = trim($data['test_name'] ?? '');
        if (empty($testName)) {
            throw new InvalidArgumentException('Diagnostic test name is required.');
        }

        $price = max(0.0, (float)($data['price'] ?? 10.00));
        $category = trim($data['category'] ?? 'General Laboratory') ?: 'General Laboratory';
        $turnaround = max(1, (int)($data['turnaround_minutes'] ?? 30));
        $specimenType = trim($data['specimen_type'] ?? 'Venous Blood / Serum') ?: 'Venous Blood / Serum';
        $normalRange = trim($data['normal_range'] ?? 'Negative / Normal Reference');
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            UPDATE lab_tests_catalog 
            SET test_name = :name, 
                category = :category, 
                price = :price, 
                turnaround_minutes = :turnaround, 
                specimen_type = :specimen, 
                normal_range = :normal_range, 
                is_active = :is_active
            WHERE id = :id
        ");

        return $stmt->execute([
            ':name'         => $testName,
            ':category'     => $category,
            ':price'        => $price,
            ':turnaround'   => $turnaround,
            ':specimen'     => $specimenType,
            ':normal_range' => $normalRange,
            ':is_active'    => $isActive,
            ':id'           => $id,
        ]);
    }

    /**
     * Toggles the active status of a lab test.
     *
     * @param int $id
     * @return bool
     */
    public static function toggleLabTestStatus(int $id): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE lab_tests_catalog SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Deletes a diagnostic lab test from the catalog.
     * Permanently deletes the test definition from catalog.
     *
     * @param int $id
     * @return bool
     */
    public static function deleteLabTest(int $id): bool
    {
        $pdo = getDBConnection();
        $test = self::getLabTestById($id);
        if (!$test) {
            return false;
        }

        // Mark system setting so auto-seeder NEVER resurrects deleted tests
        try {
            $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('lab_catalog_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        } catch (Exception $e) {}

        // Clean hard delete from catalog
        $stmt = $pdo->prepare("DELETE FROM lab_tests_catalog WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Computes KPI summary statistics for the Lab Tests Catalog.
     *
     * @return array
     */
    public static function getLabCatalogKPIs(): array
    {
        $pdo = getDBConnection();

        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_tests,
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active_tests,
                COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) as inactive_tests,
                COALESCE(AVG(turnaround_minutes), 30) as avg_turnaround,
                COUNT(DISTINCT category) as total_categories
            FROM lab_tests_catalog
        ");
        $row = $stmt->fetch();

        return [
            'total_tests'      => (int)($row['total_tests'] ?? 0),
            'active_tests'     => (int)($row['active_tests'] ?? 0),
            'inactive_tests'   => (int)($row['inactive_tests'] ?? 0),
            'avg_turnaround'   => round((float)($row['avg_turnaround'] ?? 30)),
            'total_categories' => (int)($row['total_categories'] ?? 0),
        ];
    }

    /**
     * Generates a unique diagnostic lab order number.
     *
     * @return string
     */
    public static function generateLabOrderNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');
        do {
            $rand = str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $num = "LAB-{$year}-{$rand}";
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE order_number = ?");
            $stmt->execute([$num]);
            $exists = (int)$stmt->fetchColumn() > 0;
        } while ($exists);

        return $num;
    }

    /**
     * Orders diagnostic lab tests for a patient, updates the queue to 'in_lab',
     * and automatically generates an itemized billing invoice.
     *
     * @param int $patientId
     * @param int $doctorId
     * @param int|null $consultationId
     * @param int|null $queueId
     * @param array $testItems Array of strings or arrays ['test_name' => ..., 'price' => ...]
     * @param string $clinicalNotes
     * @param string $priority 'routine'|'urgent'|'stat'
     * @param int $userId
     * @return array ['order_ids' => array, 'invoice_id' => int, 'total_price' => float]
     */
    public static function createLabOrders(
        int $patientId,
        int $doctorId,
        ?int $consultationId,
        ?int $queueId,
        array $testItems,
        string $clinicalNotes = '',
        string $priority = 'routine',
        int $userId = 1
    ): array {
        if (empty($testItems)) {
            throw new InvalidArgumentException('Please select at least one diagnostic laboratory test.');
        }

        $pdo = getDBConnection();

        $catalog = self::getLabTestCatalog();
        $catalogMap = [];
        foreach ($catalog as $catItem) {
            $catalogMap[$catItem['test_name']] = $catItem;
            $catalogMap[$catItem['test_code']] = $catItem;
        }

        $pdo->beginTransaction();

        try {
            $createdOrders = [];
            $invoiceLineItems = [];
            $totalAmount = 0.0;

            // Fetch Token Number if queue is specified
            $tokenNumber = 'T-LAB';
            if ($queueId) {
                $stmtQ = $pdo->prepare("SELECT token_number FROM patient_queues WHERE id = ?");
                $stmtQ->execute([$queueId]);
                $tNum = $stmtQ->fetchColumn();
                if ($tNum) {
                    $tokenNumber = $tNum;
                }
            }

            foreach ($testItems as $item) {
                $testName = is_array($item) ? ($item['test_name'] ?? '') : (string)$item;
                $testName = trim($testName);
                if (empty($testName)) {
                    continue;
                }

                $price = 10.00;
                if (isset($catalogMap[$testName])) {
                    $price = (float)$catalogMap[$testName]['price'];
                } elseif (is_array($item) && isset($item['price'])) {
                    $price = (float)$item['price'];
                }

                $orderNumber = self::generateLabOrderNumber();

                $stmt = $pdo->prepare("
                    INSERT INTO lab_orders (
                        order_number, patient_id, doctor_id, consultation_id, queue_id,
                        test_name, test_price, clinical_notes, priority, status
                    ) VALUES (
                        :ord_num, :pat_id, :doc_id, :cns_id, :q_id,
                        :test_name, :price, :notes, :priority, 'pending'
                    )
                ");

                $stmt->execute([
                    ':ord_num'   => $orderNumber,
                    ':pat_id'    => $patientId,
                    ':doc_id'    => $doctorId,
                    ':cns_id'    => $consultationId,
                    ':q_id'      => $queueId,
                    ':test_name' => $testName,
                    ':price'     => $price,
                    ':notes'     => $clinicalNotes,
                    ':priority'  => in_array($priority, ['routine', 'urgent', 'stat'], true) ? $priority : 'routine',
                ]);

                $orderId = (int)$pdo->lastInsertId();
                $createdOrders[] = $orderId;
                $totalAmount += $price;

                $invoiceLineItems[] = [
                    'order_id'  => $orderId,
                    'test_name' => $testName,
                    'price'     => $price,
                ];
            }

            // 1. Update Queue Status to 'in_lab'
            if ($queueId) {
                $stmtQUpdate = $pdo->prepare("UPDATE patient_queues SET status = 'in_lab' WHERE id = ?");
                $stmtQUpdate->execute([$queueId]);
            }

            // 2. Automatically Generate Itemized Laboratory Billing Invoice
            $invoiceId = BillingOperation::createLabInvoice(
                $patientId,
                $doctorId,
                $invoiceLineItems,
                $totalAmount,
                $tokenNumber,
                $userId
            );

            $pdo->commit();

            return [
                'order_ids'   => $createdOrders,
                'invoice_id'  => $invoiceId,
                'total_price' => $totalAmount,
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retrieves the live laboratory worklist with full patient, doctor, and billing payment details.
     *
     * @param string|null $statusFilter
     * @param string|null $panelFilter
     * @param string $search
     * @return array
     */
    public static function getLabWorklist(?string $statusFilter = null, ?string $panelFilter = null, string $search = ''): array
    {
        $pdo = getDBConnection();

        $where = [];
        $params = [];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $where[] = "l.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($panelFilter) && $panelFilter !== 'all') {
            $where[] = "(c.category = :category OR l.test_name LIKE :panel_like)";
            $params[':category'] = $panelFilter;
            $params[':panel_like'] = "%{$panelFilter}%";
        }

        if (!empty($search)) {
            $where[] = "(p.first_name LIKE :s1 OR p.last_name LIKE :s2 OR p.mrn LIKE :s3 OR l.order_number LIKE :s4 OR l.test_name LIKE :s5)";
            $params[':s1'] = "%{$search}%";
            $params[':s2'] = "%{$search}%";
            $params[':s3'] = "%{$search}%";
            $params[':s4'] = "%{$search}%";
            $params[':s5'] = "%{$search}%";
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "
            SELECT 
                l.id as order_id,
                l.order_number,
                l.patient_id,
                l.doctor_id,
                l.consultation_id,
                l.queue_id,
                l.test_name,
                l.test_price,
                l.clinical_notes,
                l.priority,
                l.status,
                l.result_summary,
                l.result_values,
                l.technician_id,
                l.sample_collected_at,
                l.completed_at,
                l.created_at,
                p.mrn,
                p.gender,
                p.dob,
                p.blood_group,
                p.allergies,
                p.medical_history,
                TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as patient_age,
                CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                p.phone as patient_phone,
                uDoc.full_name as doctor_name,
                uDoc.professional_title as doctor_title,
                uTech.full_name as technician_name,
                c.category as test_category,
                c.specimen_type,
                c.normal_range,
                c.turnaround_minutes,
                cns.subjective_notes,
                cns.objective_findings,
                cns.assessment_diagnosis,
                cns.secondary_diagnosis,
                cns.treatment_plan,
                v.systolic,
                v.diastolic,
                v.heart_rate,
                v.temperature,
                v.respiratory_rate,
                v.spo2_oxygen,
                v.weight_kg,
                v.height_cm,
                v.bmi,
                v.chief_complaint as vitals_complaint,
                v.triage_level,
                (
                    SELECT ii.invoice_id 
                    FROM invoice_items ii 
                    JOIN invoices inv ON ii.invoice_id = inv.id 
                    WHERE ii.item_name = l.test_name AND inv.patient_id = l.patient_id 
                    ORDER BY inv.id DESC LIMIT 1
                ) as billing_invoice_id,
                (
                    SELECT inv.payment_status 
                    FROM invoice_items ii 
                    JOIN invoices inv ON ii.invoice_id = inv.id 
                    WHERE ii.item_name = l.test_name AND inv.patient_id = l.patient_id 
                    ORDER BY inv.id DESC LIMIT 1
                ) as payment_status,
                (
                    SELECT q.billing_status
                    FROM patient_queues q
                    WHERE q.id = l.queue_id
                    LIMIT 1
                ) as queue_billing_status
            FROM lab_orders l
            JOIN patients p ON l.patient_id = p.id
            LEFT JOIN users uDoc ON l.doctor_id = uDoc.id
            LEFT JOIN users uTech ON l.technician_id = uTech.id
            LEFT JOIN lab_tests_catalog c ON l.test_name = c.test_name
            LEFT JOIN consultations cns ON l.consultation_id = cns.id
            LEFT JOIN (
                SELECT pv.*
                FROM patient_vitals pv
                INNER JOIN (
                    SELECT patient_id, MAX(id) as max_id
                    FROM patient_vitals
                    GROUP BY patient_id
                ) latest_pv ON pv.id = latest_pv.max_id
            ) v ON p.id = v.patient_id
            {$whereSql}
            ORDER BY 
                l.priority = 'stat' DESC, 
                l.priority = 'urgent' DESC, 
                l.status = 'pending' DESC, 
                l.status = 'sample_collected' DESC, 
                l.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Checks if a laboratory order has been paid at the cashier desk.
     *
     * @param int $orderId
     * @return bool
     */
    public static function isLabOrderPaid(int $orderId): bool
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT inv.payment_status, inv.due_amount, l.test_price,
                   q.billing_status as queue_billing_status
            FROM lab_orders l
            LEFT JOIN invoice_items ii ON ii.item_reference_id = l.id AND ii.item_type IN ('lab_test', 'lab')
            LEFT JOIN invoices inv ON ii.invoice_id = inv.id
            LEFT JOIN patient_queues q ON l.queue_id = q.id
            WHERE l.id = ?
            ORDER BY inv.id DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['payment_status'])) {
            // Fallback check by test_name and patient_id
            $stmtFallback = $pdo->prepare("
                SELECT inv.payment_status, inv.due_amount,
                       q.billing_status as queue_billing_status
                FROM lab_orders l
                JOIN invoice_items ii ON ii.item_name = l.test_name
                JOIN invoices inv ON ii.invoice_id = inv.id AND inv.patient_id = l.patient_id
                LEFT JOIN patient_queues q ON l.queue_id = q.id
                WHERE l.id = ?
                ORDER BY inv.id DESC
                LIMIT 1
            ");
            $stmtFallback->execute([$orderId]);
            $row = $stmtFallback->fetch();
        }

        if ($row) {
            if ($row['payment_status'] === 'paid' || $row['payment_status'] === 'partial' || (float)$row['due_amount'] <= 0.001) {
                return true;
            }
            return false;
        }

        // If no invoice was found and price is 0, consider paid
        $stmtPrice = $pdo->prepare("SELECT test_price FROM lab_orders WHERE id = ?");
        $stmtPrice->execute([$orderId]);
        $price = (float)$stmtPrice->fetchColumn();
        return ($price <= 0.001);
    }

    /**
     * Marks specimen as collected by the laboratory technician.
     *
     * @param int $orderId
     * @param int $technicianId
     * @return bool
     */
    public static function collectSpecimen(int $orderId, int $technicianId = 1): bool
    {
        if (!self::isLabOrderPaid($orderId)) {
            throw new RuntimeException('Payment Required: Patient has not paid the laboratory investigation fee at Cashier. Please collect payment before processing specimen.');
        }

        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            UPDATE lab_orders
            SET status = 'sample_collected',
                technician_id = :tech_id,
                sample_collected_at = NOW()
            WHERE id = :id
        ");
        return $stmt->execute([
            ':tech_id' => $technicianId,
            ':id'      => $orderId,
        ]);
    }

    /**
     * Records laboratory findings, diagnostic values, and marks order completed.
     * Automatically transitions patient queue to 'lab_completed' so doctor can make final diagnosis.
     *
     * @param int $orderId
     * @param string $resultSummary
     * @param string|null $resultValues
     * @param int $technicianId
     * @return bool
     */
    public static function recordTestResults(
        int $orderId,
        string $resultSummary,
        ?string $resultValues = null,
        int $technicianId = 1
    ): bool {
        if (!self::isLabOrderPaid($orderId)) {
            throw new RuntimeException('Payment Required: Patient has not paid the laboratory investigation fee at Cashier. Results cannot be released before payment.');
        }

        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $stmtFetch = $pdo->prepare("SELECT patient_id, queue_id FROM lab_orders WHERE id = ?");
            $stmtFetch->execute([$orderId]);
            $order = $stmtFetch->fetch();
            if (!$order) {
                throw new InvalidArgumentException("Lab order #{$orderId} not found.");
            }

            $stmt = $pdo->prepare("
                UPDATE lab_orders
                SET status = 'completed',
                    result_summary = :summary,
                    result_values = :values,
                    technician_id = :tech_id,
                    completed_at = NOW()
                WHERE id = :id
            ");

            $stmt->execute([
                ':summary' => trim($resultSummary),
                ':values'  => $resultValues ? trim($resultValues) : null,
                ':tech_id' => $technicianId,
                ':id'      => $orderId,
            ]);

            // If a queue item is associated, update status to 'lab_completed'
            if (!empty($order['queue_id'])) {
                $queueId = (int)$order['queue_id'];
                // Check if any other lab orders for this queue are still pending
                $stmtCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM lab_orders 
                    WHERE queue_id = ? AND status IN ('pending', 'sample_collected', 'in_progress')
                ");
                $stmtCheck->execute([$queueId]);
                $pendingCount = (int)$stmtCheck->fetchColumn();

                if ($pendingCount === 0) {
                    $stmtQ = $pdo->prepare("UPDATE patient_queues SET status = 'lab_completed' WHERE id = ?");
                    $stmtQ->execute([$queueId]);
                }
            }

            $pdo->commit();
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Fetches completed lab results for a patient (displayed in the Doctor Consultation Workspace).
     *
     * @param int $patientId
     * @param int $limit
     * @return array
     */
    public static function getPatientLabResults(int $patientId, int $limit = 10): array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.order_number,
                l.test_name,
                l.test_price,
                l.clinical_notes,
                l.priority,
                l.status,
                l.result_summary,
                l.result_values,
                l.completed_at,
                l.created_at,
                uDoc.full_name as doctor_name,
                uTech.full_name as technician_name,
                c.category,
                c.normal_range,
                c.specimen_type
            FROM lab_orders l
            LEFT JOIN users uDoc ON l.doctor_id = uDoc.id
            LEFT JOIN users uTech ON l.technician_id = uTech.id
            LEFT JOIN lab_tests_catalog c ON l.test_name = c.test_name
            WHERE l.patient_id = :pat_id AND l.status = 'completed'
            ORDER BY l.completed_at DESC, l.id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':pat_id', $patientId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Retrieves summary KPI metrics for the Laboratory Dashboard.
     *
     * @return array
     */
    public static function getLabSummaryKPIs(): array
    {
        $pdo = getDBConnection();

        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status IN ('pending', 'sample_collected', 'in_progress') THEN 1 ELSE 0 END), 0) as pending_samples,
                COALESCE(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END), 0) as in_progress,
                COALESCE(SUM(CASE WHEN priority = 'stat' AND status != 'completed' THEN 1 ELSE 0 END), 0) as stat_orders,
                COALESCE(SUM(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END), 0) as completed_today
            FROM lab_orders
        ");
        $res = $stmt->fetch();

        return [
            'pending_samples' => (int)($res['pending_samples'] ?? 0),
            'in_progress'     => (int)($res['in_progress'] ?? 0),
            'stat_orders'     => (int)($res['stat_orders'] ?? 0),
            'completed_today' => (int)($res['completed_today'] ?? 0),
            'turnaround_time' => 20, // avg minutes
        ];
    }

    /**
     * Retrieves all lab orders for a specific patient.
     *
     * @param int $patientId
     * @return array
     */
    public static function getPatientLabOrders(int $patientId): array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.order_number,
                l.patient_id,
                l.doctor_id,
                l.consultation_id,
                l.queue_id,
                l.test_name,
                l.test_price,
                l.clinical_notes,
                l.priority,
                l.status,
                l.result_summary as results,
                l.result_values as lab_notes,
                l.created_at as ordered_at,
                l.completed_at,
                c.test_code,
                c.specimen_type,
                c.normal_range,
                u.full_name as ordered_by_name
            FROM lab_orders l
            LEFT JOIN lab_tests_catalog c ON l.test_name = c.test_name
            LEFT JOIN users u ON l.doctor_id = u.id
            WHERE l.patient_id = ?
            ORDER BY l.id DESC
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetchAll();
    }
}
