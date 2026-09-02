<?php
/**
 * MedCore Systems - Centralized Database Connection Configuration
 * Uses PDO with Prepared Statements and strict error handling.
 */

declare(strict_types=1);

// Database configuration constants
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_NAME')) define('DB_NAME', 'hpms_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a singleton PDO database connection instance.
 * Automatically initializes the database and required tables on first connection if needed.
 *
 * @return PDO
 * @throws RuntimeException
 */
function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Ensure Phase 2 tables exist
            initializeDatabaseTables($pdo);
        } catch (PDOException $e) {
            // If the database does not exist yet (Error 1049), attempt to create it
            if ($e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                initializeDatabase();
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                initializeDatabaseTables($pdo);
            } else {
                throw $e;
            }
        }

        return $pdo;
    } catch (PDOException $e) {
        error_log('[HPMS DB ERROR] Connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Please ensure the database server is running.');
    }
}

/**
 * Creates the database schema if missing.
 */
function initializeDatabase(): void
{
    try {
        $serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
        $serverPdo = new PDO($serverDsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $serverPdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci;',
            DB_NAME,
            DB_CHARSET,
            DB_CHARSET
        ));
    } catch (PDOException $e) {
        error_log('[HPMS DB INIT ERROR] ' . $e->getMessage());
        throw new RuntimeException('Could not initialize the database schema.');
    }
}

/**
 * Creates all required HPMS tables if they do not already exist.
 */
function initializeDatabaseTables(PDO $pdo): void
{
    $schema = "
        -- 1. Users
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `full_name` VARCHAR(150) NOT NULL,
            `username` VARCHAR(80) NOT NULL UNIQUE,
            `email` VARCHAR(150) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `role` ENUM('doctor', 'pharmacy', 'reception_cashier', 'laboratory', 'manager', 'superadmin_ict') NOT NULL,
            `professional_title` VARCHAR(100) NULL,
            `consultation_fee` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
            `phone` VARCHAR(30) NULL,
            `account_status` ENUM('active', 'inactive', 'suspended', 'pending') NOT NULL DEFAULT 'active',
            `last_login_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_users_role` (`role`),
            INDEX `idx_users_status` (`account_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 2. Suppliers
        CREATE TABLE IF NOT EXISTS `suppliers` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `contact_person` VARCHAR(100) NULL,
            `phone` VARCHAR(30) NOT NULL,
            `email` VARCHAR(150) NULL,
            `address` TEXT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 3. Medications
        CREATE TABLE IF NOT EXISTS `medications` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `med_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `generic_name` VARCHAR(150) NULL,
            `category` VARCHAR(100) NOT NULL,
            `dosage_form` VARCHAR(50) NOT NULL,
            `unit_price` DECIMAL(10,2) NOT NULL,
            `cost_price` DECIMAL(10,2) NOT NULL,
            `min_stock_alert` INT UNSIGNED DEFAULT 20,
            `current_stock` INT UNSIGNED DEFAULT 0,
            `status` ENUM('in_stock', 'low_stock', 'out_of_stock') DEFAULT 'in_stock',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_med_category` (`category`),
            INDEX `idx_med_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 4. Purchases (Supplier Payables)
        CREATE TABLE IF NOT EXISTS `purchases` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `supplier_id` INT UNSIGNED NOT NULL,
            `po_number` VARCHAR(50) NOT NULL UNIQUE,
            `total_amount` DECIMAL(10,2) NOT NULL,
            `discount` DECIMAL(10,2) DEFAULT 0.00,
            `net_amount` DECIMAL(10,2) NOT NULL,
            `paid_amount` DECIMAL(10,2) DEFAULT 0.00,
            `due_amount` DECIMAL(10,2) NOT NULL,
            `payment_status` ENUM('paid', 'partial', 'credit') NOT NULL,
            `purchase_date` DATE NOT NULL,
            `notes` TEXT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 5. Medicine Batches
        CREATE TABLE IF NOT EXISTS `medicine_batches` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `medication_id` INT UNSIGNED NOT NULL,
            `supplier_id` INT UNSIGNED NULL,
            `purchase_id` INT UNSIGNED NULL,
            `batch_number` VARCHAR(80) NOT NULL,
            `quantity_received` INT UNSIGNED NOT NULL,
            `quantity_remaining` INT UNSIGNED NOT NULL,
            `cost_price` DECIMAL(10,2) NOT NULL,
            `expiry_date` DATE NOT NULL,
            `received_date` DATE NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`medication_id`) REFERENCES `medications`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE SET NULL,
            INDEX `idx_batch_expiry` (`expiry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 6. Supplier Payments
        CREATE TABLE IF NOT EXISTS `supplier_payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `purchase_id` INT UNSIGNED NOT NULL,
            `amount_paid` DECIMAL(10,2) NOT NULL,
            `payment_method` ENUM('cash', 'bank', 'mobile') NOT NULL DEFAULT 'cash',
            `notes` VARCHAR(255) NULL,
            `paid_by` INT UNSIGNED NOT NULL,
            `paid_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`paid_by`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 7. Prescriptions
        CREATE TABLE IF NOT EXISTS `prescriptions` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `rx_number` VARCHAR(50) NOT NULL UNIQUE,
            `patient_name` VARCHAR(150) NOT NULL,
            `patient_mrn` VARCHAR(50) NOT NULL,
            `doctor_name` VARCHAR(150) NOT NULL,
            `status` ENUM('pending', 'partially_dispensed', 'dispensed', 'cancelled') NOT NULL DEFAULT 'pending',
            `allergy_alert` VARCHAR(255) NULL,
            `pharmacist_notes` TEXT NULL,
            `dispensed_by` INT UNSIGNED NULL,
            `dispensed_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_rx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 8. Prescription Items
        CREATE TABLE IF NOT EXISTS `prescription_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `prescription_id` INT UNSIGNED NOT NULL,
            `medication_id` INT UNSIGNED NOT NULL,
            `dosage_instructions` VARCHAR(150) NOT NULL,
            `quantity_prescribed` INT UNSIGNED NOT NULL DEFAULT 1,
            `quantity_dispensed` INT UNSIGNED NOT NULL DEFAULT 0,
            `quantity_remaining` INT UNSIGNED NOT NULL DEFAULT 1,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(10,2) NOT NULL,
            `total_price` DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`medication_id`) REFERENCES `medications`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 9. Pharmacy Sales (Receivables & Walk-ins)
        CREATE TABLE IF NOT EXISTS `pharmacy_sales` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
            `sale_type` ENUM('prescription', 'walk_in') NOT NULL,
            `prescription_id` INT UNSIGNED NULL,
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_phone` VARCHAR(30) NULL,
            `total_amount` DECIMAL(10,2) NOT NULL,
            `discount_amount` DECIMAL(10,2) DEFAULT 0.00,
            `net_amount` DECIMAL(10,2) NOT NULL,
            `paid_amount` DECIMAL(10,2) DEFAULT 0.00,
            `due_amount` DECIMAL(10,2) NOT NULL,
            `payment_status` ENUM('paid', 'partial', 'credit') NOT NULL,
            `cashier_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`),
            INDEX `idx_sale_status` (`payment_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 10. Pharmacy Sale Items
        CREATE TABLE IF NOT EXISTS `pharmacy_sale_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `sale_id` INT UNSIGNED NOT NULL,
            `medication_id` INT UNSIGNED NOT NULL,
            `quantity` INT UNSIGNED NOT NULL,
            `unit_price` DECIMAL(10,2) NOT NULL,
            `total_price` DECIMAL(10,2) NOT NULL,
            `prescription_item_id` INT UNSIGNED NULL,
            `dosage_instructions` VARCHAR(255) NULL,
            FOREIGN KEY (`sale_id`) REFERENCES `pharmacy_sales`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`medication_id`) REFERENCES `medications`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 11. Patient Sale Payments (Debt Collection)
        CREATE TABLE IF NOT EXISTS `sale_payments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `sale_id` INT UNSIGNED NOT NULL,
            `amount_paid` DECIMAL(10,2) NOT NULL,
            `payment_method` ENUM('cash', 'card', 'mobile') NOT NULL DEFAULT 'cash',
            `notes` VARCHAR(255) NULL,
            `received_by` INT UNSIGNED NOT NULL,
            `received_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`sale_id`) REFERENCES `pharmacy_sales`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`received_by`) REFERENCES `users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 12. Patients Master Directory
        CREATE TABLE IF NOT EXISTS `patients` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `mrn` VARCHAR(50) NOT NULL UNIQUE,
            `first_name` VARCHAR(100) NOT NULL,
            `last_name` VARCHAR(100) NOT NULL,
            `gender` ENUM('male', 'female', 'other') NOT NULL,
            `dob` DATE NULL,
            `phone` VARCHAR(30) NOT NULL,
            `email` VARCHAR(100) NULL,
            `address` VARCHAR(255) NULL,
            `blood_group` VARCHAR(10) NULL,
            `allergies` VARCHAR(255) NULL,
            `medical_history` TEXT NULL,
            `emergency_contact_name` VARCHAR(150) NULL,
            `emergency_contact_phone` VARCHAR(30) NULL,
            `registered_by` INT UNSIGNED NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_patient_phone` (`phone`),
            INDEX `idx_patient_mrn` (`mrn`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 13. Patient Vitals & Clinical Triage
        CREATE TABLE IF NOT EXISTS `patient_vitals` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `patient_id` INT UNSIGNED NOT NULL,
            `systolic` INT UNSIGNED NULL,
            `diastolic` INT UNSIGNED NULL,
            `heart_rate` INT UNSIGNED NULL,
            `temperature` DECIMAL(4,1) NULL,
            `respiratory_rate` INT UNSIGNED NULL,
            `spo2_oxygen` INT UNSIGNED NULL,
            `weight_kg` DECIMAL(5,2) NULL,
            `height_cm` DECIMAL(5,2) NULL,
            `bmi` DECIMAL(4,1) NULL,
            `chief_complaint` TEXT NULL,
            `triage_level` ENUM('routine', 'urgent', 'emergency') DEFAULT 'routine',
            `recorded_by` INT UNSIGNED NULL,
            `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_vitals_patient` (`patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 14. Patient Queue Management
        CREATE TABLE IF NOT EXISTS `patient_queues` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `token_number` VARCHAR(20) NOT NULL,
            `patient_id` INT UNSIGNED NOT NULL,
            `doctor_id` INT UNSIGNED NULL,
            `department` VARCHAR(100) NOT NULL DEFAULT 'General OPD',
            `priority` ENUM('normal', 'urgent', 'emergency') NOT NULL DEFAULT 'normal',
            `status` ENUM('waiting', 'in_consultation', 'in_lab', 'lab_completed', 'completed', 'cancelled') NOT NULL DEFAULT 'waiting',
            `queued_by` INT UNSIGNED NULL,
            `queued_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `called_at` DATETIME NULL,
            `completed_at` DATETIME NULL,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`queued_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_queue_status` (`status`),
            INDEX `idx_queue_doc` (`doctor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 15. System Settings & Initial Seed Tracker
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` VARCHAR(80) PRIMARY KEY,
            `setting_value` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 16. Doctor Consultations & Clinical Encounters
        CREATE TABLE IF NOT EXISTS `consultations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `consultation_number` VARCHAR(50) NOT NULL UNIQUE,
            `patient_id` INT UNSIGNED NOT NULL,
            `doctor_id` INT UNSIGNED NOT NULL,
            `queue_id` INT UNSIGNED NULL,
            `subjective_notes` TEXT NULL,
            `objective_findings` TEXT NULL,
            `assessment_diagnosis` VARCHAR(255) NOT NULL,
            `secondary_diagnosis` VARCHAR(255) NULL,
            `treatment_plan` TEXT NULL,
            `follow_up_date` DATE NULL,
            `status` ENUM('draft', 'completed') DEFAULT 'completed',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`),
            FOREIGN KEY (`queue_id`) REFERENCES `patient_queues`(`id`) ON DELETE SET NULL,
            INDEX `idx_cns_patient` (`patient_id`),
            INDEX `idx_cns_doctor` (`doctor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 17a. Laboratory Diagnostic Categories / Panels
        CREATE TABLE IF NOT EXISTS `lab_categories` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `description` VARCHAR(255) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_lab_cat_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 17. Master Diagnostic Lab Tests Catalog
        CREATE TABLE IF NOT EXISTS `lab_tests_catalog` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `test_code` VARCHAR(30) NOT NULL UNIQUE,
            `test_name` VARCHAR(150) NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 10.00,
            `turnaround_minutes` INT UNSIGNED NOT NULL DEFAULT 30,
            `specimen_type` VARCHAR(80) NOT NULL DEFAULT 'Blood',
            `normal_range` VARCHAR(150) NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_lab_code` (`test_code`),
            INDEX `idx_lab_cat` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 18. Laboratory Diagnostic Orders
        CREATE TABLE IF NOT EXISTS `lab_orders` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(50) NOT NULL UNIQUE,
            `patient_id` INT UNSIGNED NOT NULL,
            `doctor_id` INT UNSIGNED NOT NULL,
            `consultation_id` INT UNSIGNED NULL,
            `queue_id` INT UNSIGNED NULL,
            `test_name` VARCHAR(150) NOT NULL,
            `test_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `clinical_notes` TEXT NULL,
            `priority` ENUM('routine', 'urgent', 'stat') DEFAULT 'routine',
            `status` ENUM('pending', 'sample_collected', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            `result_summary` TEXT NULL,
            `result_values` TEXT NULL,
            `technician_id` INT UNSIGNED NULL,
            `sample_collected_at` DATETIME NULL,
            `completed_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`doctor_id`) REFERENCES `users`(`id`),
            FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_lab_patient` (`patient_id`),
            INDEX `idx_lab_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 18. Chart of Accounts (COA)
        CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `account_code` VARCHAR(20) NOT NULL UNIQUE,
            `account_name` VARCHAR(150) NOT NULL,
            `account_type` ENUM('asset', 'liability', 'equity', 'revenue', 'cogs', 'expense') NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_coa_code` (`account_code`),
            INDEX `idx_coa_type` (`account_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 19. General Ledger Journal Entries (Double-Entry Header)
        CREATE TABLE IF NOT EXISTS `journal_entries` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `entry_number` VARCHAR(50) NOT NULL UNIQUE,
            `entry_date` DATE NOT NULL,
            `reference_type` ENUM('pharmacy_sale', 'prescription_dispense', 'supplier_restock', 'supplier_payment', 'patient_debt_payment', 'expense', 'manual_journal', 'consultation_fee', 'lab_fee') NOT NULL DEFAULT 'manual_journal',
            `reference_id` INT UNSIGNED NULL,
            `description` VARCHAR(255) NOT NULL,
            `total_debit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_credit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_je_date` (`entry_date`),
            INDEX `idx_je_ref` (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 20. Journal Items (Debit & Credit Lines)
        CREATE TABLE IF NOT EXISTS `journal_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `journal_entry_id` INT UNSIGNED NOT NULL,
            `account_id` INT UNSIGNED NOT NULL,
            `debit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `credit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `memo` VARCHAR(255) NULL,
            FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
            INDEX `idx_ji_entry` (`journal_entry_id`),
            INDEX `idx_ji_account` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 21. Hospital Expenses
        CREATE TABLE IF NOT EXISTS `hospital_expenses` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `expense_number` VARCHAR(50) NOT NULL UNIQUE,
            `account_id` INT UNSIGNED NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `payment_method` ENUM('cash', 'bank', 'mobile') NOT NULL DEFAULT 'cash',
            `payee` VARCHAR(150) NOT NULL,
            `expense_date` DATE NOT NULL,
            `description` VARCHAR(255) NOT NULL,
            `receipt_ref` VARCHAR(100) NULL,
            `recorded_by` INT UNSIGNED NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON DELETE RESTRICT,
            FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_exp_date` (`expense_date`),
            INDEX `idx_exp_account` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 22. Invoices & Billing Hub
        CREATE TABLE IF NOT EXISTS `invoices` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
            `patient_id` INT UNSIGNED NULL,
            `queue_id` INT UNSIGNED NULL,
            `prescription_id` INT UNSIGNED NULL,
            `consultation_id` INT UNSIGNED NULL,
            `bill_type` ENUM('consultation', 'pharmacy', 'laboratory', 'lab', 'walk_in', 'combined') NOT NULL DEFAULT 'consultation',
            `customer_name` VARCHAR(150) NOT NULL,
            `customer_phone` VARCHAR(30) NULL,
            `token_number` VARCHAR(20) NULL,
            `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tax` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `net_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `due_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` ENUM('cash', 'mobile', 'card', 'insurance', 'credit') NOT NULL DEFAULT 'cash',
            `payment_status` ENUM('pending', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
            `cashier_id` INT UNSIGNED NULL,
            `notes` TEXT NULL,
            `paid_at` DATETIME NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`queue_id`) REFERENCES `patient_queues`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
            INDEX `idx_inv_status` (`payment_status`),
            INDEX `idx_inv_type` (`bill_type`),
            INDEX `idx_inv_patient` (`patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        -- 23. Invoice Itemized Charges
        CREATE TABLE IF NOT EXISTS `invoice_items` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `invoice_id` INT UNSIGNED NOT NULL,
            `item_type` ENUM('consultation', 'medication', 'lab_test', 'service') NOT NULL DEFAULT 'service',
            `item_reference_id` INT UNSIGNED NULL,
            `item_name` VARCHAR(150) NOT NULL,
            `item_description` VARCHAR(255) NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
            INDEX `idx_inv_item` (`invoice_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    try {
        $pdo->exec($schema);

        // Safe column migrations for pharmacy_sale_items
        $cols = $pdo->query("SHOW COLUMNS FROM `pharmacy_sale_items` LIKE 'prescription_item_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `pharmacy_sale_items` ADD COLUMN `prescription_item_id` INT UNSIGNED NULL AFTER `total_price`");
        }
        $cols2 = $pdo->query("SHOW COLUMNS FROM `pharmacy_sale_items` LIKE 'dosage_instructions'")->fetchAll();
        if (empty($cols2)) {
            $pdo->exec("ALTER TABLE `pharmacy_sale_items` ADD COLUMN `dosage_instructions` VARCHAR(255) NULL AFTER `prescription_item_id`");
        }

        // Safe column migrations for pharmacy_sales credit_applied
        $colsSaleCredit = $pdo->query("SHOW COLUMNS FROM `pharmacy_sales` LIKE 'credit_applied'")->fetchAll();
        if (empty($colsSaleCredit)) {
            $pdo->exec("ALTER TABLE `pharmacy_sales` ADD COLUMN `credit_applied` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
        }

        // Safe column migrations for patients account_credit
        $colsCredit = $pdo->query("SHOW COLUMNS FROM `patients` LIKE 'account_credit'")->fetchAll();
        if (empty($colsCredit)) {
            $pdo->exec("ALTER TABLE `patients` ADD COLUMN `account_credit` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `address`");
        }

        // 24. Refund Vouchers
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `refund_vouchers` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `voucher_number` VARCHAR(50) NOT NULL UNIQUE,
                `patient_id` INT UNSIGNED NOT NULL,
                `invoice_id` INT UNSIGNED NULL,
                `queue_id` INT UNSIGNED NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `refund_type` ENUM('cash', 'credit') NOT NULL DEFAULT 'cash',
                `reason` VARCHAR(255) NOT NULL,
                `issued_by` INT UNSIGNED NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE SET NULL,
                FOREIGN KEY (`issued_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                INDEX `idx_rv_patient` (`patient_id`),
                INDEX `idx_rv_voucher` (`voucher_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {
        error_log('[HPMS TABLE INIT ERROR] ' . $e->getMessage());
    }
}

