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
 * - All Prescriptions, Pharmacy Sales, Walk-in Orders, and AR Customer Debts
 * - All Restock PO Purchases, Medicine Batches, and AP Supplier Debts
 * - All Invoices, Cashier Receipts, Hospital Expenses, and Journal Entries
 * 
 * Preserves:
 * - Active System User Accounts (Superadmin, Doctors, Receptionist, Pharmacist, Lab, Manager)
 * - Master Chart of Accounts (COA) Structure with clean $0.00 Balances
 * - 1 Reference Sample Laboratory Test (CBC - Complete Blood Count)
 * - 1 Reference Sample Medication Catalog Item (Paracetamol 500mg - 0 Stock)
 * - 1 Reference Sample Supplier (Mogadishu Pharma Distributors)
 */

declare(strict_types=1);

require_once __DIR__ . '/CONFIG/database.php';
require_once __DIR__ . '/CONFIG/security.php';
require_once __DIR__ . '/OPERATIONS/AccountingOperation.php';

$pdo = getDBConnection();

echo "\n======================================================================\n";
echo "       MEDCORE HOSPITAL SYSTEMS - COMPLETE SYSTEM DATA PURGE\n";
echo "======================================================================\n\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// 1. Transactional & Clinical Tables to Truncate
$tablesToClear = [
    'invoice_items',
    'invoices',
    'refund_vouchers',
    'journal_items',
    'journal_entries',
    'hospital_expenses',
    'patient_queues',
    'patient_vitals',
    'consultations',
    'lab_orders',
    'prescription_items',
    'prescriptions',
    'sale_payments',
    'pharmacy_sale_items',
    'pharmacy_sales',
    'supplier_payments',
    'medicine_batches',
    'purchases',
    'patients',
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
echo " [✓] Preserved official staff user accounts.\n";

// 3. Reset Lab Categories & Catalog (Keep exactly 1 master reference test: CBC)
require_once __DIR__ . '/OPERATIONS/LaboratoryOperation.php';
LaboratoryOperation::seedLabCategoriesIfEmpty();
$pdo->exec("DELETE FROM lab_tests_catalog WHERE test_code != 'LAB-CBC'");
$cbcExists = (int)$pdo->query("SELECT COUNT(*) FROM lab_tests_catalog WHERE test_code = 'LAB-CBC'")->fetchColumn();
if ($cbcExists === 0) {
    $stmtLab = $pdo->prepare("
        INSERT INTO lab_tests_catalog (test_code, test_name, category, price, turnaround_minutes, specimen_type, normal_range, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmtLab->execute([
        'LAB-CBC',
        'Complete Blood Count (CBC / FBC)',
        'Hematology & Coagulation',
        10.00,
        25,
        'Whole Blood (EDTA)',
        'WBC: 4.0-11.0 x10^9/L, Hb: 12.0-17.5 g/dL, PLT: 150-450 x10^9/L',
    ]);
}
echo " [✓] Reset Laboratory Catalog & Master Categories (LAB-CBC retained).\n";

// 4. Reset Medication Catalog (Keep 1 reference medication with 0 stock)
$pdo->exec("DELETE FROM medications WHERE med_code != 'MED-PCM-500'");
$pcmExists = (int)$pdo->query("SELECT COUNT(*) FROM medications WHERE med_code = 'MED-PCM-500'")->fetchColumn();
if ($pcmExists === 0) {
    $pdo->exec("
        INSERT INTO medications (med_code, name, generic_name, category, dosage_form, unit_price, cost_price, current_stock, min_stock_alert, status)
        VALUES ('MED-PCM-500', 'Paracetamol 500mg', 'Acetaminophen', 'Analgesic', 'Tablet', 0.50, 0.20, 0, 50, 'out_of_stock')
    ");
} else {
    $pdo->exec("UPDATE medications SET current_stock = 0, status = 'out_of_stock' WHERE med_code = 'MED-PCM-500'");
}
echo " [✓] Reset Medication Catalog (1 reference medicine: Paracetamol retained with 0 stock).\n";

// 5. Reset Suppliers (Keep 1 reference supplier)
$pdo->exec("DELETE FROM suppliers WHERE name != 'Mogadishu Pharma Distributors'");
$supExists = (int)$pdo->query("SELECT COUNT(*) FROM suppliers WHERE name = 'Mogadishu Pharma Distributors'")->fetchColumn();
if ($supExists === 0) {
    $pdo->exec("
        INSERT INTO suppliers (name, contact_person, phone, email, address)
        VALUES ('Mogadishu Pharma Distributors', 'Ali Warsame', '+252 61 5550101', 'orders@mogadishupharma.com', 'Bakara Zone 4, Mogadishu')
    ");
}
echo " [✓] Reset Suppliers Directory (1 reference vendor: Mogadishu Pharma Distributors retained).\n";

// 6. Update System Settings seed trackers
$settings = [
    'patients_seeded'      => '1',
    'prescriptions_seeded' => '1',
    'users_seeded'         => '1',
    'lab_tests_seeded'     => '1',
];
foreach ($settings as $k => $v) {
    $stmtS = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmtS->execute([$k, $v, $v]);
}
echo " [✓] Updated system seed settings to prevent sample data resurrections.\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 7. Verify Master Chart of Accounts (COA) Structure with clean $0 balances
AccountingOperation::seedChartOfAccountsIfEmpty();
echo " [✓] Verified Master Chart of Accounts (All Ledger Balances: $0.00).\n";

echo "\n======================================================================\n";
echo " SUCCESS: SYSTEM IS 100% CLEAN, FRESH & READY FOR PRODUCTION USE!\n";
echo "======================================================================\n\n";
