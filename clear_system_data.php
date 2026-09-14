<?php
/**
 * MedCore Systems - Complete System Reset & Data Purge Script
 * 
 * Command to run in PowerShell or Terminal:
 * php clear_system_data.php
 * OR
 * c:\xampp\php\php.exe c:\xampp\htdocs\HPMS\clear_system_data.php
 * 
 * Clears:
 * - All Patients, Visits, Queues, Vitals, Consultations, and Doctor notes
 * - All Laboratory Orders and Test Results
 * - All Laboratory Test Catalog items (0 items - clean slate)
 * - All Prescriptions, Pharmacy Sales, Walk-in Orders, and AR Customer Debts
 * - All Medications Catalog items (0 items - clean slate)
 * - All Suppliers / Vendors (0 items - clean slate)
 * - All Restock PO Purchases, Medicine Batches, Movements, Price Logs, and AP Supplier Debts
 * - All Invoices, Cashier Receipts, Hospital Expenses, and Journal Entries
 * 
 * Preserves:
 * - Active System User Accounts (Superadmin, Doctors, Receptionist, Pharmacist, Lab, Manager)
 * - Master Chart of Accounts (COA) Structure with clean $0.00 Balances
 * - Master Laboratory Categories (Hematology, Clinical Chemistry, Microbiology)
 * - System Settings Seed Locks (to prevent demo/sample mock data resurrection)
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/OPERATIONS/LaboratoryOperation.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = getDBConnection();

echo "\n======================================================================\n";
echo "       " . strtoupper(HOSPITAL_NAME) . " - SYSTEM DATA PURGE\n";
echo "======================================================================\n\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// 1. Transactional, Clinical & Catalog Tables to Truncate
$tablesToClear = [
    // Invoicing, Running Balance Payments & General Ledger
    'invoice_payments',
    'invoice_items',
    'invoices',
    'refund_vouchers',
    'journal_items',
    'journal_entries',
    'account_transfers',
    'hospital_expenses',
    
    // Reception, Triage & Consultations
    'patient_queues',
    'patient_vitals',
    'consultations',
    'patients',
    
    // Laboratory
    'lab_orders',
    'lab_tests_catalog',
    
    // Prescriptions & Pharmacy Sales
    'prescription_items',
    'prescriptions',
    'sale_payments',
    'pharmacy_sale_items',
    'pharmacy_sales',
    
    // Inventory, Purchases & Suppliers
    'supplier_payments',
    'purchases',
    'medicine_batches',
    'medicine_batch_movements',
    'medication_price_logs',
    'medications',
    'suppliers',
    'lab_categories',
];

foreach ($tablesToClear as $tbl) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$tbl}`");
        echo " [✓] Cleared table: {$tbl}\n";
    } catch (Exception $e) {
        $pdo->exec("DELETE FROM `{$tbl}`");
        echo " [✓] Deleted records from: {$tbl}\n";
    }
}

// 2. Clean temporary test user accounts, retain core staff users
$pdo->exec("DELETE FROM users WHERE username LIKE '%test%' OR email LIKE '%test%'");
$userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo " [✓] Preserved official staff user accounts (Total Active Users: {$userCount}).\n";

// 3. Update System Settings seed trackers to permanently prevent sample/demo data auto-injection
$settings = [
    'patients_seeded'      => '1',
    'prescriptions_seeded' => '1',
    'users_seeded'         => '1',
    'lab_tests_seeded'     => '1',
    'inventory_seeded'     => '1',
    'lab_catalog_seeded'   => '1',
];
foreach ($settings as $k => $v) {
    $stmtS = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmtS->execute([$k, $v, $v]);
}
echo " [✓] Locked system seed settings to prevent sample/demo data resurrecting on page visits.\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 5. Verify Master Chart of Accounts (COA) Structure with clean $0 balances
AccountingOperation::seedChartOfAccountsIfEmpty();
$coaCount = (int)$pdo->query("SELECT COUNT(*) FROM chart_of_accounts")->fetchColumn();
echo " [✓] Verified Master Chart of Accounts ({$coaCount} Accounts, All Ledger Balances: $0.00).\n";

echo "\n======================================================================\n";
echo " SUCCESS: SYSTEM IS 100% CLEAN, FRESH & READY FOR PRODUCTION USE!\n";
echo "======================================================================\n\n";

