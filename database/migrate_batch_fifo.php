<?php
/**
 * Migration script to upgrade HPMS database schema for Batch/Lot Inventory Tracking & FIFO Costing
 */
require_once __DIR__ . '/../CONFIG/database.php';

try {
    $pdo = getDBConnection();
    echo "Starting Batch/Lot Inventory Tracking migration...\n";

    // 1. Check and add columns to medicine_batches
    $columnsMB = $pdo->query("SHOW COLUMNS FROM medicine_batches")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('purchase_transaction_id', $columnsMB, true)) {
        echo "Adding purchase_transaction_id column to medicine_batches...\n";
        $pdo->exec("ALTER TABLE medicine_batches ADD COLUMN purchase_transaction_id INT(10) UNSIGNED NULL AFTER purchase_id");
    }

    if (!in_array('unit_cost', $columnsMB, true)) {
        echo "Adding unit_cost column to medicine_batches...\n";
        $pdo->exec("ALTER TABLE medicine_batches ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantity_remaining");
        $pdo->exec("UPDATE medicine_batches SET unit_cost = cost_price WHERE unit_cost = 0.00");
    }

    if (!in_array('status', $columnsMB, true)) {
        echo "Adding status column to medicine_batches...\n";
        $pdo->exec("ALTER TABLE medicine_batches ADD COLUMN status ENUM('active', 'depleted', 'expired', 'written_off') NOT NULL DEFAULT 'active' AFTER received_date");
        $pdo->exec("UPDATE medicine_batches SET status = CASE WHEN quantity_remaining > 0 THEN 'active' ELSE 'depleted' END");
    }

    // Link existing batches' purchase_transaction_id if purchases have matching journal entries
    $pdo->exec("
        UPDATE medicine_batches mb
        JOIN journal_entries je ON je.reference_type = 'supplier_restock' AND je.reference_id = mb.purchase_id
        SET mb.purchase_transaction_id = je.id
        WHERE mb.purchase_transaction_id IS NULL AND mb.purchase_id IS NOT NULL
    ");

    // 2. Create medicine_batch_movements table
    echo "Creating medicine_batch_movements table if not exists...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `medicine_batch_movements` (
            `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `batch_id` INT(10) UNSIGNED NOT NULL,
            `movement_type` ENUM('dispense', 'purchase', 'adjustment', 'write_off') NOT NULL,
            `quantity` INT NOT NULL,
            `unit_cost` DECIMAL(10,2) NOT NULL,
            `total_cost` DECIMAL(10,2) NOT NULL,
            `reference_transaction_id` VARCHAR(100) NULL,
            `notes` VARCHAR(255) NULL,
            `created_by` INT(10) UNSIGNED NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_batch_movements_batch` (`batch_id`),
            KEY `idx_batch_movements_ref` (`reference_transaction_id`),
            CONSTRAINT `fk_mbm_batch` FOREIGN KEY (`batch_id`) REFERENCES `medicine_batches` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_mbm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Check indexes on medicine_batches
    $indexesMB = $pdo->query("SHOW INDEX FROM medicine_batches")->fetchAll(PDO::FETCH_ASSOC);
    $hasCompositeIndex = false;
    foreach ($indexesMB as $idx) {
        if ($idx['Key_name'] === 'idx_batch_fifo_lookup') {
            $hasCompositeIndex = true;
            break;
        }
    }
    if (!$hasCompositeIndex) {
        echo "Adding composite index idx_batch_fifo_lookup on medicine_batches...\n";
        $pdo->exec("ALTER TABLE medicine_batches ADD INDEX idx_batch_fifo_lookup (medication_id, status, expiry_date, received_date)");
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
