<?php
/**
 * MedCore Systems - Hospital Billing & Payments Engine
 * Manages consultation fees, pharmacy prescription invoices, cashier settlement,
 * receipt issuance, and real-time double-entry general ledger synchronization.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/AccountingOperation.php';

class BillingOperation
{
    /**
     * Generates a unique sequential Invoice Number (e.g. INV-2026-0001).
     */
    public static function generateInvoiceNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');

        $stmt = $pdo->query("SELECT MAX(id) FROM invoices");
        $maxId = (int)$stmt->fetchColumn();
        $nextId = $maxId + 1;

        return sprintf("INV-%s-%04d", $year, $nextId);
    }

    /**
     * Ensures the invoice_payments table exists in the database.
     */
    public static function ensureInvoicePaymentsTable(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $pdo = getDBConnection();
        try {
            $check = $pdo->query("SELECT 1 FROM `invoice_payments` LIMIT 1");
            if ($check !== false) {
                $ensured = true;
                return;
            }
        } catch (Throwable $e) {
            // Table doesn't exist yet
        }

        // MySQL DDL statements (like CREATE TABLE) trigger an implicit COMMIT of any active transaction.
        // Therefore, never execute DDL if a transaction is currently in progress.
        if (!$pdo->inTransaction()) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `invoice_payments` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `invoice_id` INT UNSIGNED NOT NULL,
                    `patient_id` INT UNSIGNED NULL,
                    `receipt_number` VARCHAR(50) NOT NULL,
                    `previous_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    `amount_paid` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    `remaining_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
                    `payment_method` ENUM('cash', 'mobile', 'card', 'bank', 'insurance', 'credit') NOT NULL DEFAULT 'cash',
                    `received_by` INT UNSIGNED NOT NULL DEFAULT 1,
                    `notes` TEXT NULL,
                    `paid_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_inv_pay_inv` (`invoice_id`),
                    INDEX `idx_inv_pay_pat` (`patient_id`),
                    INDEX `idx_inv_pay_date` (`paid_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $ensured = true;
        }
    }

    /**
     * Generates a unique sequential Receipt Number (e.g. REC-2026-0001).
     */
    public static function generateReceiptNumber(): string
    {
        $pdo = getDBConnection();
        $year = date('Y');
        self::ensureInvoicePaymentsTable();

        $stmt = $pdo->query("SELECT MAX(id) FROM invoice_payments");
        $maxId = (int)$stmt->fetchColumn();
        $nextId = $maxId + 1;

        return sprintf("REC-%s-%04d", $year, $nextId);
    }

    /**
     * Creates a consultation fee invoice when a patient is checked in & issued a token.
     */
    public static function createConsultationInvoice(
        int $patientId,
        ?int $queueId = null,
        ?int $doctorId = null,
        float $fee = 10.00,
        ?string $tokenNumber = null,
        ?int $userId = null
    ): int {
        $pdo = getDBConnection();

        // Retrieve patient info
        $stmtP = $pdo->prepare("SELECT first_name, last_name, phone, mrn FROM patients WHERE id = ?");
        $stmtP->execute([$patientId]);
        $patient = $stmtP->fetch();
        if (!$patient) {
            throw new InvalidArgumentException("Patient ID {$patientId} not found.");
        }

        $customerName = trim($patient['first_name'] . ' ' . $patient['last_name']);
        $customerPhone = $patient['phone'] ?? '';

        // Doctor info if provided
        $docDesc = 'General Outpatient Consultation';
        if ($doctorId) {
            $stmtD = $pdo->prepare("SELECT full_name, professional_title FROM users WHERE id = ?");
            $stmtD->execute([$doctorId]);
            $doc = $stmtD->fetch();
            if ($doc) {
                $docDesc = sprintf("Consultation with %s (%s)", $doc['full_name'], $doc['professional_title'] ?? 'Medical Provider');
            }
        }

        $invoiceNumber = self::generateInvoiceNumber();

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $isFree = ($fee <= 0.0);
            $payStatus = $isFree ? 'paid' : 'pending';
            $dueAmount = $isFree ? 0.00 : $fee;
            $paidAt = $isFree ? date('Y-m-d H:i:s') : null;

            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, patient_id, queue_id, bill_type, customer_name,
                    customer_phone, token_number, subtotal, discount, tax,
                    net_total, paid_amount, due_amount, payment_status, paid_at, notes
                ) VALUES (
                    ?, ?, ?, 'consultation', ?,
                    ?, ?, ?, 0.00, 0.00,
                    ?, 0.00, ?, ?, ?, ?
                )
            ");
            $stmtInv->execute([
                $invoiceNumber,
                $patientId,
                $queueId,
                $customerName,
                $customerPhone,
                $tokenNumber,
                $fee,
                $fee,
                $dueAmount,
                $payStatus,
                $paidAt,
                $docDesc,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO invoice_items (
                    invoice_id, item_type, item_reference_id, item_name,
                    item_description, quantity, unit_price, total_price
                ) VALUES (
                    ?, 'consultation', ?, 'Doctor Consultation Fee',
                    ?, 1, ?, ?
                )
            ");
            $stmtItem->execute([
                $invoiceId,
                $doctorId,
                $docDesc,
                $fee,
                $fee,
            ]);

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $invoiceId;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS BILLING INVOICE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates an itemized diagnostic laboratory invoice for ordered tests.
     */
    public static function createLabInvoice(
        int $patientId,
        int $doctorId,
        array $items,
        float $totalAmount,
        string $tokenNumber = 'T-LAB',
        ?int $userId = 1
    ): int {
        $pdo = getDBConnection();

        $stmtP = $pdo->prepare("SELECT first_name, last_name, phone, mrn FROM patients WHERE id = ?");
        $stmtP->execute([$patientId]);
        $patient = $stmtP->fetch();
        if (!$patient) {
            throw new InvalidArgumentException("Patient ID {$patientId} not found.");
        }

        $customerName = trim($patient['first_name'] . ' ' . $patient['last_name']);
        $customerPhone = $patient['phone'] ?? '';

        $invoiceNumber = self::generateInvoiceNumber();

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $isFree = ($totalAmount <= 0.0);
            $payStatus = $isFree ? 'paid' : 'pending';
            $dueAmount = $isFree ? 0.00 : $totalAmount;
            $paidAt = $isFree ? date('Y-m-d H:i:s') : null;

            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, patient_id, bill_type, customer_name,
                    customer_phone, token_number, subtotal, discount, tax,
                    net_total, paid_amount, due_amount, payment_status, paid_at, notes
                ) VALUES (
                    ?, ?, 'lab', ?,
                    ?, ?, ?, 0.00, 0.00,
                    ?, 0.00, ?, ?, ?, 'Diagnostic Laboratory Tests'
                )
            ");
            $stmtInv->execute([
                $invoiceNumber,
                $patientId,
                $customerName,
                $customerPhone,
                $tokenNumber,
                $totalAmount,
                $totalAmount,
                $dueAmount,
                $payStatus,
                $paidAt,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO invoice_items (
                    invoice_id, item_type, item_reference_id, item_name,
                    item_description, quantity, unit_price, total_price
                ) VALUES (
                    ?, 'lab_test', ?, ?,
                    'Laboratory Diagnostic Test', 1, ?, ?
                )
            ");

            foreach ($items as $it) {
                $testName = $it['test_name'] ?? 'Diagnostic Test';
                $orderId  = !empty($it['order_id']) ? (int)$it['order_id'] : null;
                $price    = (float)($it['price'] ?? 0.00);

                $stmtItem->execute([
                    $invoiceId,
                    $orderId,
                    $testName,
                    $price,
                    $price,
                ]);
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $invoiceId;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS LAB BILLING INVOICE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a pharmacy medication invoice for a prescription order or direct fulfillment.
     */
    public static function createPharmacyInvoice(
        int $prescriptionId,
        array $items,
        float $discount = 0.00,
        ?string $customerPhone = null,
        ?int $userId = null
    ): int {
        $pdo = getDBConnection();

        $stmtRx = $pdo->prepare("
            SELECT rx.*, p.id as patient_id, p.phone as patient_phone
            FROM prescriptions rx
            LEFT JOIN patients p ON rx.patient_mrn = p.mrn
            WHERE rx.id = ?
        ");
        $stmtRx->execute([$prescriptionId]);
        $rx = $stmtRx->fetch();
        if (!$rx) {
            throw new InvalidArgumentException("Prescription ID {$prescriptionId} not found.");
        }

        $patientId = !empty($rx['patient_id']) ? (int)$rx['patient_id'] : null;
        $customerName = $rx['patient_name'];
        $phone = $customerPhone ?: ($rx['patient_phone'] ?? '');

        // Calculate subtotal from items
        $subtotal = 0.0;
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $subtotal += ($qty * $unitPrice);
        }

        $discount = max(0, min($subtotal, $discount));
        $netTotal = max(0, $subtotal - $discount);
        $invoiceNumber = self::generateInvoiceNumber();

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $stmtInv = $pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, patient_id, prescription_id, bill_type, customer_name,
                    customer_phone, subtotal, discount, tax, net_total,
                    paid_amount, due_amount, payment_status, notes
                ) VALUES (
                    ?, ?, ?, 'pharmacy', ?,
                    ?, ?, ?, 0.00, ?,
                    0.00, ?, 'pending', 'Prescription Medication Dispense'
                )
            ");
            $stmtInv->execute([
                $invoiceNumber,
                $patientId,
                $prescriptionId,
                $customerName,
                $phone,
                $subtotal,
                $discount,
                $netTotal,
                $netTotal,
            ]);
            $invoiceId = (int)$pdo->lastInsertId();

            // Insert line items
            $stmtItem = $pdo->prepare("
                INSERT INTO invoice_items (
                    invoice_id, item_type, item_reference_id, item_name,
                    item_description, quantity, unit_price, total_price
                ) VALUES (
                    ?, 'medication', ?, ?,
                    ?, ?, ?, ?
                )
            ");

            foreach ($items as $item) {
                $medId = (int)($item['medication_id'] ?? 0);
                $name = $item['name'] ?? ($item['medication_name'] ?? 'Medication Item');
                $desc = $item['dosage_instructions'] ?? '';
                $qty = (int)($item['quantity'] ?? 1);
                $uPrice = (float)($item['unit_price'] ?? 0);
                $tPrice = (float)($item['total_price'] ?? ($qty * $uPrice));

                $stmtItem->execute([
                    $invoiceId,
                    $medId,
                    $name,
                    $desc,
                    $qty,
                    $uPrice,
                    $tPrice,
                ]);
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $invoiceId;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS PHARMACY INVOICE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Settle or record payment on an invoice (supports cash, mobile, card, or credit split).
     * Synchronizes double-entry accounting in General Ledger and unlocks queued patient orders.
     */
    public static function processInvoicePayment(
        int $invoiceId,
        float $amountPaid,
        string $paymentMethod = 'cash',
        string $notes = '',
        ?int $cashierId = 1,
        ?float $cogsCost = null,
        bool $isDebtInstallment = false
    ): array {
        $pdo = getDBConnection();
        AccountingOperation::seedChartOfAccountsIfEmpty();

        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new InvalidArgumentException("Invoice ID {$invoiceId} not found.");
        }

        $currentPaid = (float)$invoice['paid_amount'];
        $currentDue  = (float)$invoice['due_amount'];
        $netTotal    = (float)$invoice['net_total'];

        // Fix #1: Genuine $0 payment allowed (no 0.01 floor)
        $effectivePayment = max(0.00, min($currentDue, (float)$amountPaid));
        $newPaidTotal     = round($currentPaid + $effectivePayment, 2);
        $newDueTotal      = max(0.00, round($netTotal - $newPaidTotal, 2));

        $newStatus = ($newDueTotal <= 0.00) ? 'paid' : 'partial';

        // Check if this invoice already had initial revenue/AR accrual
        $hasInitialAccrual = ($currentPaid > 0.00) || !empty($invoice['paid_at']);
        if (!$hasInitialAccrual) {
            $stmtCheckJe = $pdo->prepare("
                SELECT COUNT(*) 
                FROM journal_entries 
                WHERE reference_type IN ('consultation_fee', 'lab_fee', 'pharmacy_sale', 'patient_billing', 'patient_debt_payment') 
                  AND reference_id = ?
            ");
            $stmtCheckJe->execute([$invoiceId]);
            $hasInitialAccrual = ((int)$stmtCheckJe->fetchColumn() > 0);
        }

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // 1. Update Invoice Table
            $stmtUpd = $pdo->prepare("
                UPDATE invoices 
                SET paid_amount = :paid,
                    due_amount = :due,
                    payment_status = :status,
                    payment_method = :method,
                    paid_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), ' | ', :notes)
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':paid'   => $newPaidTotal,
                ':due'    => $newDueTotal,
                ':status' => $newStatus,
                ':method' => $paymentMethod,
                ':notes'  => $notes ?: 'Payment settled by cashier',
                ':id'     => $invoiceId,
            ]);

            // 1b. Record installment row in invoice_payments table (Running Balance Ledger)
            // ONLY record if this is a subsequent debt collection / installment
            $receiptNumber = null;
            $previousBalance = $currentDue;
            $remainingBalance = $newDueTotal;

            if ($effectivePayment > 0.00 && $isDebtInstallment) {
                self::ensureInvoicePaymentsTable();
                $receiptNumber = self::generateReceiptNumber();

                $stmtInsertPay = $pdo->prepare("
                    INSERT INTO invoice_payments 
                        (invoice_id, patient_id, receipt_number, previous_balance, amount_paid, remaining_balance, payment_method, received_by, notes, paid_at)
                    VALUES 
                        (:invoice_id, :patient_id, :receipt_number, :prev_bal, :amount_paid, :rem_bal, :method, :received_by, :notes, NOW())
                ");
                $stmtInsertPay->execute([
                    ':invoice_id'      => $invoiceId,
                    ':patient_id'     => !empty($invoice['patient_id']) ? (int)$invoice['patient_id'] : null,
                    ':receipt_number' => $receiptNumber,
                    ':prev_bal'       => $previousBalance,
                    ':amount_paid'    => $effectivePayment,
                    ':rem_bal'        => $remainingBalance,
                    ':method'         => in_array($paymentMethod, ['cash', 'mobile', 'card', 'bank', 'insurance', 'credit']) ? $paymentMethod : 'cash',
                    ':received_by'    => $cashierId ?: 1,
                    ':notes'          => $notes ?: 'Patient debt installment collection',
                ]);
            }

            // 2. If Consultation Invoice, update queue billing status when checkout is confirmed by cashier
            if (!empty($invoice['queue_id'])) {
                $pdo->prepare("
                    UPDATE patient_queues 
                    SET billing_status = 'paid' 
                    WHERE id = ?
                ")->execute([$invoice['queue_id']]);
            }

            // 3. Post Balanced Double-Entry Journal to General Ledger (Option A: Full Accrual)
            // Determine Revenue Account
            $revenueAccountCode = match ($invoice['bill_type']) {
                'consultation'      => '4020', // Consultation Fees Revenue
                'pharmacy', 'walk_in' => '4010', // Pharmacy Sales Revenue
                'lab', 'laboratory' => '4030', // Laboratory Fees Revenue
                default             => '4090', // Other Clinical Revenue
            };
            $revAcc  = AccountingOperation::getAccountByCode($revenueAccountCode);
            $arAcc   = AccountingOperation::getAccountByCode('1100'); // Accounts Receivable - Patients
            $cogsAcc = AccountingOperation::getAccountByCode('5010'); // Cost of Dispensed Medications
            $invAcc  = AccountingOperation::getAccountByCode('1200'); // Pharmacy Inventory Asset

            // Determine Cash/Mobile/Bank Asset Account
            $assetAccountCode = match ($paymentMethod) {
                'mobile'       => '1020', // Mobile Money (EVC / Zaad)
                'bank', 'card' => '1030', // Bank Account (Commercial Banks)
                default        => '1010', // Cash on Hand (Khasnadda)
            };
            $cashAcc = AccountingOperation::getAccountByCode($assetAccountCode);

            if (!$hasInitialAccrual) {
                // --- INITIAL INVOICE SETTLEMENT (OPTION A FULL ACCRUAL) ---
                $journalItems = [];

                // A. Dr Cash/Mobile/Bank for amount actually paid now (omitted if $effectivePayment == 0)
                if ($effectivePayment > 0.00 && $cashAcc) {
                    $journalItems[] = [
                        'account_id' => (int)$cashAcc['id'],
                        'debit'      => $effectivePayment,
                        'credit'     => 0.00,
                        'memo'       => "Payment for {$invoice['invoice_number']} via {$paymentMethod}",
                    ];
                }

                // B. Dr Accounts Receivable (1100) for amount owed (omitted if paid in full)
                if ($newDueTotal > 0.00 && $arAcc) {
                    $journalItems[] = [
                        'account_id' => (int)$arAcc['id'],
                        'debit'      => $newDueTotal,
                        'credit'     => 0.00,
                        'memo'       => "Accounts Receivable (Patient Debt) for {$invoice['invoice_number']} [{$invoice['customer_name']}]",
                    ];
                }

                // C. Cr Revenue (4010/4020/4030) for the FULL invoiced amount (net_total), always
                if ($netTotal > 0.00 && $revAcc) {
                    $journalItems[] = [
                        'account_id' => (int)$revAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $netTotal,
                        'memo'       => "Revenue recognized for {$invoice['bill_type']} [{$invoice['customer_name']}]",
                    ];
                }

                // D. For Pharmacy sales/dispenses: Dr COGS (5010) / Cr Pharmacy Inventory (1200)
                if (in_array($invoice['bill_type'], ['pharmacy', 'walk_in'], true) && $cogsAcc && $invAcc) {
                    // If COGS cost was not directly provided, check batch movements first, then batch unit costs
                    if ($cogsCost === null) {
                        $cogsCost = 0.0;
                        // 1. Check if exact FIFO batch movements exist for this invoice
                        $stmtMbm = $pdo->prepare("
                            SELECT COALESCE(SUM(total_cost), 0)
                            FROM medicine_batch_movements
                            WHERE reference_transaction_id = ? AND movement_type = 'dispense'
                        ");
                        $stmtMbm->execute([$invoice['invoice_number']]);
                        $mbmCost = (float)$stmtMbm->fetchColumn();

                        if ($mbmCost > 0.0) {
                            $cogsCost = $mbmCost;
                        } else {
                            // 2. Fallback: compute from active batches
                            $stmtItems = $pdo->prepare("SELECT item_reference_id, quantity FROM invoice_items WHERE invoice_id = ? AND item_type = 'medication'");
                            $stmtItems->execute([$invoiceId]);
                            $mItems = $stmtItems->fetchAll();
                            foreach ($mItems as $mi) {
                                $stmtBatchCost = $pdo->prepare("
                                    SELECT CASE WHEN unit_cost > 0 THEN unit_cost ELSE cost_price END 
                                    FROM medicine_batches 
                                    WHERE medication_id = ? AND status = 'active' 
                                    ORDER BY expiry_date ASC, id ASC LIMIT 1
                                ");
                                $stmtBatchCost->execute([$mi['item_reference_id']]);
                                $unitCost = (float)($stmtBatchCost->fetchColumn() ?: 0.0);
                                if ($unitCost <= 0.0) {
                                    $stmtMedCost = $pdo->prepare("SELECT cost_price FROM medications WHERE id = ?");
                                    $stmtMedCost->execute([$mi['item_reference_id']]);
                                    $unitCost = (float)($stmtMedCost->fetchColumn() ?: 0.0);
                                }
                                $cogsCost += ($unitCost * (int)$mi['quantity']);
                            }
                        }
                    }

                    if ($cogsCost > 0.00) {
                        $journalItems[] = [
                            'account_id' => (int)$cogsAcc['id'],
                            'debit'      => round($cogsCost, 2),
                            'credit'     => 0.00,
                            'memo'       => "Cost of Goods Sold (COGS) for {$invoice['invoice_number']}",
                        ];
                        $journalItems[] = [
                            'account_id' => (int)$invAcc['id'],
                            'debit'      => 0.00,
                            'credit'     => round($cogsCost, 2),
                            'memo'       => "Pharmacy Inventory Asset reduction for {$invoice['invoice_number']}",
                        ];
                    }
                }

                $refType = match ($invoice['bill_type']) {
                    'consultation'      => 'consultation_fee',
                    'lab', 'laboratory' => 'lab_fee',
                    default             => 'pharmacy_sale',
                };

                if (!empty($journalItems)) {
                    AccountingOperation::recordJournalEntry(
                        date('Y-m-d'),
                        $refType,
                        $invoiceId,
                        "Billing Accrual [{$invoice['invoice_number']}]: {$invoice['customer_name']} (Net: \${$netTotal}, Paid: \${$effectivePayment}, Due: \${$newDueTotal})",
                        $journalItems,
                        $cashierId ?? 1
                    );
                }

            } else {
                // --- SUBSEQUENT PATIENT DEBT SETTLEMENT (PAYING DOWN AR) ---
                if ($effectivePayment > 0.00 && $cashAcc && $arAcc) {
                    $journalItems = [
                        [
                            'account_id' => (int)$cashAcc['id'],
                            'debit'      => $effectivePayment,
                            'credit'     => 0.00,
                            'memo'       => "Patient debt collection for {$invoice['invoice_number']}",
                        ],
                        [
                            'account_id' => (int)$arAcc['id'],
                            'debit'      => 0.00,
                            'credit'     => $effectivePayment,
                            'memo'       => "Accounts Receivable clearance for {$invoice['invoice_number']}",
                        ],
                    ];

                    AccountingOperation::recordJournalEntry(
                        date('Y-m-d'),
                        'patient_debt_payment',
                        $invoiceId,
                        "Patient Debt Settlement: \${$effectivePayment} collected for {$invoice['invoice_number']} ({$invoice['customer_name']})",
                        $journalItems,
                        $cashierId ?? 1
                    );
                }
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'invoice_id'        => $invoiceId,
                'invoice_number'    => $invoice['invoice_number'],
                'receipt_number'    => $receiptNumber ?? null,
                'previous_balance'  => $previousBalance ?? $currentDue,
                'amount_paid'       => $effectivePayment,
                'remaining_due'     => $newDueTotal,
                'status'            => $newStatus,
            ];

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS PROCESS PAYMENT ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancels an unpaid or pending invoice, clears its due balance,
     * and cancels linked waiting queues or pending lab/prescription orders.
     *
     * @param int $invoiceId
     * @param string $reason
     * @param int|null $userId
     * @return bool
     */
    public static function cancelInvoice(int $invoiceId, string $reason = 'Cancelled by cashier', ?int $userId = 1): bool
    {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new InvalidArgumentException("Invoice ID {$invoiceId} not found.");
        }

        if ($invoice['payment_status'] === 'cancelled') {
            return true;
        }

        if ($invoice['payment_status'] === 'paid' && (float)$invoice['due_amount'] <= 0.0) {
            throw new RuntimeException("Cannot cancel a fully settled/paid invoice #{$invoice['invoice_number']}. Use financial credit note or refund instead.");
        }

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // 1. Update invoice status to 'cancelled' and clear remaining due
            $cancellationNote = sprintf("[Cancelled by User #%d on %s: %s]", $userId ?? 1, date('M d, Y g:i A'), $reason);
            $stmtUpd = $pdo->prepare("
                UPDATE invoices 
                SET payment_status = 'cancelled',
                    due_amount = 0.00,
                    notes = CONCAT(COALESCE(notes, ''), ' | ', :note)
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':note' => $cancellationNote,
                ':id'   => $invoiceId,
            ]);

            // 2. If Consultation Invoice with a linked waiting queue, cancel the queue ticket
            if (!empty($invoice['queue_id'])) {
                $pdo->prepare("
                    UPDATE patient_queues 
                    SET status = 'cancelled' 
                    WHERE id = ? AND status IN ('waiting', 'in_consultation')
                ")->execute([$invoice['queue_id']]);
            }

            // 3. If Laboratory Invoice, cancel any pending uncollected lab orders for this patient/queue
            if (in_array($invoice['bill_type'], ['lab', 'laboratory'], true)) {
                if (!empty($invoice['patient_id'])) {
                    $pdo->prepare("
                        UPDATE lab_orders 
                        SET status = 'cancelled', clinical_notes = CONCAT(COALESCE(clinical_notes, ''), ' | Cancelled by billing cashier: ', :reason)
                        WHERE patient_id = :pat_id AND status = 'pending'
                    ")->execute([
                        ':reason' => $reason,
                        ':pat_id' => $invoice['patient_id'],
                    ]);
                }
            }

            // 4. If Pharmacy Invoice with linked prescription, cancel un-dispensed prescription
            if ($invoice['bill_type'] === 'pharmacy' && !empty($invoice['prescription_id'])) {
                $pdo->prepare("
                    UPDATE prescriptions 
                    SET status = 'cancelled' 
                    WHERE id = ? AND status = 'pending'
                ")->execute([$invoice['prescription_id']]);
            }

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return true;

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS CANCEL INVOICE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves full invoice details including line items.
     */
    public static function getInvoiceById(int $invoiceId): ?array
    {
        $pdo = getDBConnection();

        $stmt = $pdo->prepare("
            SELECT inv.*, u.full_name as cashier_name,
                   q.token_number as queue_token, q.department, q.priority as queue_priority,
                   p.mrn, p.gender, p.blood_group,
                   doc.full_name as doctor_name
            FROM invoices inv
            LEFT JOIN users u ON inv.cashier_id = u.id
            LEFT JOIN patient_queues q ON inv.queue_id = q.id
            LEFT JOIN patients p ON inv.patient_id = p.id
            LEFT JOIN users doc ON q.doctor_id = doc.id
            WHERE inv.id = ?
        ");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return null;
        }

        $stmtItems = $pdo->prepare("
            SELECT * FROM invoice_items 
            WHERE invoice_id = ? 
            ORDER BY id ASC
        ");
        $stmtItems->execute([$invoiceId]);
        $invoice['items'] = $stmtItems->fetchAll();

        return $invoice;
    }

    /**
     * Retrieves the queue of active invoices awaiting payment.
     */
    public static function getPendingInvoicesQueue(?string $billType = null, ?string $search = ''): array
    {
        $pdo = getDBConnection();

        $sql = "
            SELECT inv.*, p.mrn, q.token_number as queue_token, q.department
            FROM invoices inv
            LEFT JOIN patients p ON inv.patient_id = p.id
            LEFT JOIN patient_queues q ON inv.queue_id = q.id
            WHERE inv.paid_at IS NULL AND inv.payment_status = 'pending'
        ";
        $params = [];

        if (!empty($billType) && $billType !== 'all') {
            $sql .= " AND inv.bill_type = ?";
            $params[] = $billType;
        }

        if (!empty($search)) {
            $sql .= " AND (inv.invoice_number LIKE ? OR inv.customer_name LIKE ? OR p.mrn LIKE ? OR inv.token_number LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY inv.id DESC LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retrieves paid and settled invoices history.
     */
    public static function getPaidInvoicesHistory(?string $startDate = null, ?string $endDate = null, int $limit = 50): array
    {
        $pdo = getDBConnection();

        $startDate = $startDate ?: date('Y-m-01');
        $endDate   = $endDate ?: date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT inv.*, u.full_name as cashier_name, p.mrn
            FROM invoices inv
            LEFT JOIN users u ON inv.cashier_id = u.id
            LEFT JOIN patients p ON inv.patient_id = p.id
            WHERE (inv.payment_status = 'paid' OR inv.paid_at IS NOT NULL) AND DATE(inv.paid_at) BETWEEN ? AND ?
            ORDER BY inv.paid_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $startDate);
        $stmt->bindValue(2, $endDate);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Retrieves live Billing KPIs for the cashier summary banner.
     */
    public static function getBillingSummaryKPIs(): array
    {
        $pdo = getDBConnection();
        $today = date('Y-m-d');

        // 1. Total Collections Today
        $stmtCol = $pdo->prepare("
            SELECT COALESCE(SUM(paid_amount), 0)
            FROM invoices
            WHERE DATE(paid_at) = ? AND payment_status IN ('paid', 'partial')
        ");
        $stmtCol->execute([$today]);
        $collectedToday = (float)$stmtCol->fetchColumn();

        // 2. Total Invoices Generated Today
        $stmtBilled = $pdo->prepare("
            SELECT COALESCE(SUM(net_total), 0)
            FROM invoices
            WHERE DATE(created_at) = ?
        ");
        $stmtBilled->execute([$today]);
        $billedToday = (float)$stmtBilled->fetchColumn();

        // 3. Pending Invoices Count
        $stmtPending = $pdo->query("
            SELECT COUNT(*), COALESCE(SUM(due_amount), 0)
            FROM invoices
            WHERE paid_at IS NULL AND payment_status = 'pending'
        ");
        $pendingData = $stmtPending->fetch(PDO::FETCH_NUM);
        $pendingCount = (int)($pendingData[0] ?? 0);
        $uncollectedDue = (float)($pendingData[1] ?? 0);

        return [
            'collected_today' => $collectedToday,
            'billed_today'    => $billedToday,
            'pending_count'   => $pendingCount,
            'uncollected_due' => $uncollectedDue,
        ];
    }

    /**
     * Adjusts consultation fee and invoice when a patient is reassigned to another doctor.
     * Supports:
     * 1. Fee Upgrade (+balance due sent to billing/cashier with partial status)
     * 2. Fee Downgrade:
     *    - Option A: Credit to Patient Account (patients.account_credit)
     *    - Option B: Cash Refund Voucher (refund_vouchers + balanced GL cash refund entry)
     * 3. Pending Invoice (direct adjustment of net_total & due_amount)
     * 4. Equal Fee (update doctor description)
     *
     * @param int $queueId
     * @param int $patientId
     * @param int|null $newDoctorId
     * @param string $overpaymentAction 'credit' or 'refund'
     * @param int $userId
     * @return array
     */
    public static function adjustConsultationFeeOnReassignment(
        int $queueId,
        int $patientId,
        ?int $newDoctorId,
        string $overpaymentAction = 'credit',
        int $userId = 1
    ): array {
        $pdo = getDBConnection();

        // 1. Determine New Doctor Name & Consultation Fee
        $newDocFee = 10.00;
        $newDocName = 'Next Available Doctor';
        $newDocTitle = 'General OPD';

        if ($newDoctorId) {
            $stmtD = $pdo->prepare("SELECT full_name, professional_title, consultation_fee FROM users WHERE id = ?");
            $stmtD->execute([$newDoctorId]);
            $doc = $stmtD->fetch();
            if ($doc) {
                $newDocName = $doc['full_name'];
                $newDocTitle = $doc['professional_title'] ?: 'General Practice';
                $newDocFee = (float)$doc['consultation_fee'];
            }
        }
        $docDesc = sprintf("Consultation with %s (%s)", $newDocName, $newDocTitle);

        // 2. Find Active Consultation Invoice for this Queue Item
        $stmtInv = $pdo->prepare("
            SELECT * FROM invoices 
            WHERE (queue_id = :qid OR (patient_id = :pid AND DATE(created_at) = CURDATE() AND bill_type = 'consultation'))
              AND bill_type = 'consultation'
            ORDER BY id DESC LIMIT 1
        ");
        $stmtInv->execute([':qid' => $queueId, ':pid' => $patientId]);
        $inv = $stmtInv->fetch();

        if (!$inv) {
            // If no invoice existed yet, create one
            $tokenStmt = $pdo->prepare("SELECT token_number FROM patient_queues WHERE id = ?");
            $tokenStmt->execute([$queueId]);
            $tok = $tokenStmt->fetchColumn() ?: null;
            $newInvId = self::createConsultationInvoice($patientId, $queueId, $newDoctorId, $newDocFee, $tok, $userId);
            return [
                'action'        => 'created',
                'invoice_id'    => $newInvId,
                'new_fee'       => $newDocFee,
                'status'        => ($newDocFee <= 0) ? 'paid' : 'pending',
                'balance_due'   => $newDocFee,
            ];
        }

        $invId       = (int)$inv['id'];
        $currentPaid = (float)$inv['paid_amount'];
        $currentDue  = (float)$inv['due_amount'];
        $currentStat = $inv['payment_status'];

        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            // Case 1: Prior Invoice was Pending (No money paid yet)
            if ($currentStat === 'pending' || $currentPaid <= 0.00) {
                $newDue = $newDocFee;
                $newStat = ($newDocFee <= 0.0) ? 'paid' : 'pending';

                $pdo->prepare("
                    UPDATE invoices
                    SET subtotal = :subtotal,
                        net_total = :net_total,
                        due_amount = :due,
                        payment_status = :status,
                        notes = :notes
                    WHERE id = :id
                ")->execute([
                    ':subtotal'  => $newDocFee,
                    ':net_total' => $newDocFee,
                    ':due'       => $newDue,
                    ':status'    => $newStat,
                    ':notes'     => $docDesc . ' [Reassigned]',
                    ':id'        => $invId,
                ]);

                $pdo->prepare("
                    UPDATE invoice_items
                    SET item_name = :name,
                        item_description = :desc,
                        unit_price = :uprice,
                        total_price = :tprice
                    WHERE invoice_id = :id AND item_type = 'consultation'
                ")->execute([
                    ':name'   => 'Consultation - ' . $newDocName,
                    ':desc'   => $docDesc,
                    ':uprice' => $newDocFee,
                    ':tprice' => $newDocFee,
                    ':id'     => $invId,
                ]);

                if ($ownsTransaction && $pdo->inTransaction()) { $pdo->commit(); }

                return [
                    'action'      => 'pending_updated',
                    'invoice_id'  => $invId,
                    'new_fee'     => $newDocFee,
                    'paid'        => 0.00,
                    'balance_due' => $newDue,
                    'status'      => $newStat,
                ];
            }

            // Case 2: Upgrade (New Doctor Fee > Previously Paid Amount)
            if ($newDocFee > $currentPaid) {
                $balanceDue = round($newDocFee - $currentPaid, 2);

                $pdo->prepare("
                    UPDATE invoices
                    SET subtotal = :subtotal,
                        net_total = :net_total,
                        due_amount = :due,
                        payment_status = 'partial',
                        notes = CONCAT(COALESCE(notes, ''), ' | Reassigned to ', :doc, ' [+$', :diff, ' due]')
                    WHERE id = :id
                ")->execute([
                    ':subtotal'  => $newDocFee,
                    ':net_total' => $newDocFee,
                    ':due'       => $balanceDue,
                    ':doc'       => $newDocName,
                    ':diff'      => number_format($balanceDue, 2),
                    ':id'        => $invId,
                ]);

                $pdo->prepare("
                    UPDATE invoice_items
                    SET item_name = :name,
                        item_description = :desc,
                        unit_price = :uprice,
                        total_price = :tprice
                    WHERE invoice_id = :id AND item_type = 'consultation'
                ")->execute([
                    ':name'   => 'Consultation - ' . $newDocName,
                    ':desc'   => $docDesc,
                    ':uprice' => $newDocFee,
                    ':tprice' => $newDocFee,
                    ':id'     => $invId,
                ]);

                // Update queue billing status to partial
                $pdo->prepare("UPDATE patient_queues SET billing_status = 'partial' WHERE id = ?")->execute([$queueId]);

                if ($ownsTransaction && $pdo->inTransaction()) { $pdo->commit(); }

                return [
                    'action'      => 'upgrade_partial',
                    'invoice_id'  => $invId,
                    'new_fee'     => $newDocFee,
                    'paid'        => $currentPaid,
                    'balance_due' => $balanceDue,
                    'status'      => 'partial',
                ];
            }

            // Case 3: Downgrade (New Doctor Fee < Previously Paid Amount)
            if ($newDocFee < $currentPaid) {
                $overpayment = round($currentPaid - $newDocFee, 2);

                // Update invoice to match new fee as fully paid
                $pdo->prepare("
                    UPDATE invoices
                    SET subtotal = :subtotal,
                        net_total = :net_total,
                        paid_amount = :paid_amt,
                        due_amount = 0.00,
                        payment_status = 'paid',
                        notes = CONCAT(COALESCE(notes, ''), ' | Reassigned to ', :doc, ' [-$', :diff, ' overpayment handled]')
                    WHERE id = :id
                ")->execute([
                    ':subtotal'  => $newDocFee,
                    ':net_total' => $newDocFee,
                    ':paid_amt'  => $newDocFee,
                    ':doc'       => $newDocName,
                    ':diff'      => number_format($overpayment, 2),
                    ':id'        => $invId,
                ]);

                $pdo->prepare("
                    UPDATE invoice_items
                    SET item_name = :name,
                        item_description = :desc,
                        unit_price = :uprice,
                        total_price = :tprice
                    WHERE invoice_id = :id AND item_type = 'consultation'
                ")->execute([
                    ':name'   => 'Consultation - ' . $newDocName,
                    ':desc'   => $docDesc,
                    ':uprice' => $newDocFee,
                    ':tprice' => $newDocFee,
                    ':id'     => $invId,
                ]);

                if ($overpaymentAction === 'credit') {
                    // Option A: Credit to Patient Account
                    $pdo->prepare("
                        UPDATE patients 
                        SET account_credit = account_credit + :cred 
                        WHERE id = :pid
                    ")->execute([
                        ':cred' => $overpayment,
                        ':pid'  => $patientId,
                    ]);

                    if ($ownsTransaction && $pdo->inTransaction()) { $pdo->commit(); }

                    return [
                        'action'         => 'downgrade_credit',
                        'invoice_id'     => $invId,
                        'new_fee'        => $newDocFee,
                        'paid'           => $newDocFee,
                        'overpayment'    => $overpayment,
                        'credit_applied' => true,
                        'status'         => 'paid',
                    ];
                } else {
                    // Option B: Cash Refund Voucher
                    $voucherNumber = 'RV-' . date('Ymd') . '-' . str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
                    $stmtV = $pdo->prepare("
                        INSERT INTO refund_vouchers (voucher_number, patient_id, invoice_id, queue_id, amount, refund_type, reason, issued_by)
                        VALUES (:vnum, :pid, :invid, :qid, :amt, 'cash', :reason, :uid)
                    ");
                    $stmtV->execute([
                        ':vnum'   => $voucherNumber,
                        ':pid'    => $patientId,
                        ':invid'  => $invId,
                        ':qid'    => $queueId,
                        ':amt'    => $overpayment,
                        ':reason' => "Consultation Downgrade: Reassigned to {$newDocName}",
                        ':uid'    => $userId,
                    ]);
                    $voucherId = (int)$pdo->lastInsertId();

                    // Record double-entry General Ledger transaction for cash refund
                    try {
                        require_once __DIR__ . '/AccountingOperation.php';
                        AccountingOperation::seedChartOfAccountsIfEmpty();
                        $revAcc  = AccountingOperation::getAccountByCode('4020'); // Consultation Fees Revenue
                        $cashAcc = AccountingOperation::getAccountByCode('1010'); // Cash on Hand
                        if ($revAcc && $cashAcc) {
                            AccountingOperation::recordJournalEntry(
                                date('Y-m-d'),
                                'consultation_fee',
                                $invId,
                                "Cash refund of $" . number_format($overpayment, 2) . " for doctor reassignment [Voucher #{$voucherNumber}]",
                                [
                                    ['account_id' => (int)$revAcc['id'],  'debit' => $overpayment, 'credit' => 0.00, 'memo' => "Consultation Fee Refund - {$voucherNumber}"],
                                    ['account_id' => (int)$cashAcc['id'], 'debit' => 0.00, 'credit' => $overpayment, 'memo' => "Cash refund disbursed to patient"],
                                ],
                                $userId
                            );
                        }
                    } catch (Exception $jeEx) {
                        error_log('[HPMS REFUND GL ERROR] ' . $jeEx->getMessage());
                    }

                    if ($ownsTransaction && $pdo->inTransaction()) { $pdo->commit(); }

                    return [
                        'action'         => 'downgrade_refund',
                        'invoice_id'     => $invId,
                        'new_fee'        => $newDocFee,
                        'paid'           => $newDocFee,
                        'overpayment'    => $overpayment,
                        'voucher_id'     => $voucherId,
                        'voucher_number' => $voucherNumber,
                        'refund_issued'  => true,
                        'status'         => 'paid',
                    ];
                }
            }

            // Case 4: Equal Fee ($newDocFee == $currentPaid)
            $pdo->prepare("
                UPDATE invoices 
                SET notes = CONCAT(COALESCE(notes, ''), ' | Reassigned to ', :doc)
                WHERE id = :id
            ")->execute([
                ':doc' => $newDocName,
                ':id'  => $invId,
            ]);

            $pdo->prepare("
                UPDATE invoice_items
                SET item_name = :name,
                    item_description = :desc
                WHERE invoice_id = :id AND item_type = 'consultation'
            ")->execute([
                ':name' => 'Consultation - ' . $newDocName,
                ':desc' => $docDesc,
                ':id'   => $invId,
            ]);

            if ($ownsTransaction && $pdo->inTransaction()) { $pdo->commit(); }

            return [
                'action'     => 'equal_fee',
                'invoice_id' => $invId,
                'new_fee'    => $newDocFee,
                'paid'       => $currentPaid,
                'status'     => $currentStat,
            ];

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[HPMS REASSIGN FEE ADJUST ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves refund voucher details with patient and issuer info.
     */
    public static function getRefundVoucherById(int $voucherId): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT rv.*, 
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   p.mrn, p.phone as patient_phone,
                   u.full_name as issued_by_name
            FROM refund_vouchers rv
            JOIN patients p ON rv.patient_id = p.id
            LEFT JOIN users u ON rv.issued_by = u.id
            WHERE rv.id = ?
        ");
        $stmt->execute([$voucherId]);
        return $stmt->fetch() ?: null;
    }
}

