<?php
/**
 * Migration script to create medication_price_logs table in HPMS database.
 */
declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';

try {
    $pdo = getDBConnection();
    echo "Creating medication_price_logs table if not exists...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `medication_price_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `medication_id` INT UNSIGNED NOT NULL,
            `old_price` DECIMAL(10,2) NOT NULL,
            `new_price` DECIMAL(10,2) NOT NULL,
            `changed_by` INT UNSIGNED NULL,
            `reason` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_mpl_med` (`medication_id`),
            KEY `idx_mpl_user` (`changed_by`),
            CONSTRAINT `fk_mpl_med` FOREIGN KEY (`medication_id`) REFERENCES `medications` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_mpl_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "[PASS] medication_price_logs table created successfully.\n";
} catch (Exception $e) {
    echo "[FAIL] Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
