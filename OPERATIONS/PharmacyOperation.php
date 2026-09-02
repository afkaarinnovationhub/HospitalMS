<?php
/**
 * MedCore Systems - Pharmacy Operations Engine
 * Handles electronic doctor prescriptions, partial split dispensing, OTC point of sale, and customer debt ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';

class PharmacyOperation
{
    /**
     * Fetches all pending or partially dispensed electronic doctor prescriptions.
     *
     * @return array
     */
    public static function getPendingPrescriptionsQueue(): array
    {
        $pdo = getDBConnection();
        $sql = "
            SELECT 
                p.*,
                COUNT(pi.id) as item_count,
                GROUP_CONCAT(m.name SEPARATOR ', ') as medication_names
            FROM prescriptions p
            LEFT JOIN prescription_items pi ON p.id = pi.prescription_id
            LEFT JOIN medications m ON pi.medication_id = m.id
            WHERE p.status IN ('pending', 'partially_dispensed')
            GROUP BY p.id
            ORDER BY FIELD(p.status, 'pending', 'partially_dispensed'), p.created_at DESC
        ";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Retrieves a single prescription with all prescribed medication items and live stock statuses.
     *
     * @param int $id
     * @return array|null
     */
    public static function getPrescriptionById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT p.*,
                   pat.id as patient_id,
                   COALESCE(pat.account_credit, 0.00) as account_credit,
                   pat.phone as patient_phone_dir
            FROM prescriptions p
            LEFT JOIN patients pat ON p.patient_mrn = pat.mrn
            WHERE p.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $prescription = $stmt->fetch();

        if (!$prescription) {
            return null;
        }

        $stmtItems = $pdo->prepare("
            SELECT 
                pi.*,
                COALESCE(pi.quantity_prescribed, pi.quantity) as quantity_prescribed,
                COALESCE(pi.quantity_dispensed, 0) as quantity_dispensed,
                COALESCE(pi.quantity_remaining, pi.quantity) as quantity_remaining,
                m.name as medication_name,
                m.generic_name,
                m.med_code,
                m.category,
                m.dosage_form,
                m.current_stock,
                (m.current_stock >= COALESCE(pi.quantity_remaining, pi.quantity)) as is_stock_available
            FROM prescription_items pi
            JOIN medications m ON pi.medication_id = m.id
            WHERE pi.prescription_id = :id
        ");
        $stmtItems->execute([':id' => $id]);
        $prescription['items'] = $stmtItems->fetchAll();

        return $prescription;
    }

    /**
     * Confirms and dispenses a doctor's prescription with full support for:
     * - Partial dispensing (custom quantity per item)
     * - Physical inventory stock deduction of ONLY the dispensed quantity
     * - Billing, discounts, patient account credit deduction, and customer installment debt tracking.
     *
     * @param int $prescriptionId
     * @param array $dispenseQuantities Mapping of [prescription_item_id => qty_to_dispense]
     * @param string|null $pharmacistNotes
     * @param float $discountAmount
     * @param float|null $paidAmount
     * @param string $paymentMethod
     * @param string|null $customerPhone
     * @param int $dispensedBy
     * @param float $creditApplied
     * @return int Created sale ID
     */
    public static function dispensePrescription(
        int $prescriptionId,
        array $dispenseQuantities,
        ?string $pharmacistNotes,
        float $discountAmount,
        ?float $paidAmount,
        string $paymentMethod,
        ?string $customerPhone,
        int $dispensedBy,
        float $creditApplied = 0.00
    ): int {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $prescription = self::getPrescriptionById($prescriptionId);

            if (!$prescription) {
                throw new InvalidArgumentException('Prescription not found.');
            }

            if (!in_array($prescription['status'], ['pending', 'partially_dispensed'], true)) {
                throw new InvalidArgumentException('This prescription has already been completed (' . $prescription['status'] . ').');
            }

            // 1. Verify stock and quantities to dispense for each item
            $itemsToDispense = [];
            $totalAmount = 0.0;

            foreach ($prescription['items'] as $item) {
                $itemId = (int)$item['id'];
                $remaining = (int)$item['quantity_remaining'];

                if ($remaining <= 0) {
                    continue; // Already fulfilled in previous visit
                }

                // If explicit quantity provided, use it; otherwise dispense all remaining
                $qtyToDispense = isset($dispenseQuantities[$itemId]) ? (int)$dispenseQuantities[$itemId] : $remaining;

                if ($qtyToDispense <= 0) {
                    continue; // Skipped by pharmacist this visit
                }

                if ($qtyToDispense > $remaining) {
                    throw new InvalidArgumentException(sprintf(
                        'Cannot dispense %d units of "%s". Only %d units remaining on this prescription.',
                        $qtyToDispense,
                        $item['medication_name'],
                        $remaining
                    ));
                }

                if ($item['current_stock'] < $qtyToDispense) {
                    throw new RuntimeException(sprintf(
                        'Insufficient physical stock on shelf for "%s". Requested: %d, Available: %d.',
                        $item['medication_name'],
                        $qtyToDispense,
                        $item['current_stock']
                    ));
                }

                $unitPrice = (float)$item['unit_price'];
                $lineTotal = round($qtyToDispense * $unitPrice, 2);
                $totalAmount += $lineTotal;

                $itemsToDispense[] = [
                    'prescription_item_id' => $itemId,
                    'medication_id'        => (int)$item['medication_id'],
                    'medication_name'      => $item['medication_name'],
                    'dosage_instructions'  => $item['dosage_instructions'] ?? 'Take as directed',
                    'qty_to_dispense'      => $qtyToDispense,
                    'unit_price'           => $unitPrice,
                    'line_total'           => $lineTotal,
                ];
            }

            if (empty($itemsToDispense)) {
                throw new InvalidArgumentException('Please specify at least 1 medication quantity to dispense.');
            }

            // 2. Financial Breakdown
            $discount   = max(0.0, $discountAmount);
            $netAmount  = max(0.0, $totalAmount - $discount);

            // Deduct Patient Account Credit if applied
            $patCreditAvail = (float)($prescription['account_credit'] ?? 0.0);
            $creditToUse    = min($netAmount, max(0.0, min($creditApplied, $patCreditAvail)));
            $netAfterCredit = max(0.0, round($netAmount - $creditToUse, 2));

            $paidInput  = ($paidAmount !== null) ? (float)$paidAmount : $netAfterCredit;
            $paid       = min($netAfterCredit, max(0.0, $paidInput));
            $dueAmount  = max(0.0, round($netAfterCredit - $paid, 2));

            if ($dueAmount <= 0.001) {
                $paymentStatus = 'paid';
            } elseif ($paid > 0 || $creditToUse > 0) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'credit';
            }

            $phone = trim($customerPhone ?: ('MRN: ' . $prescription['patient_mrn']));

            // Deduct from patients.account_credit
            if (!empty($prescription['patient_id']) && $creditToUse > 0) {
                $stmtDeductCredit = $pdo->prepare("UPDATE patients SET account_credit = GREATEST(0, account_credit - :cred) WHERE id = :pid");
                $stmtDeductCredit->execute([
                    ':cred' => $creditToUse,
                    ':pid'  => (int)$prescription['patient_id'],
                ]);
            }

            // 3. Deduct ONLY the physically dispensed quantities from medications & batches
            $stmtUpdateItem = $pdo->prepare("
                UPDATE prescription_items 
                SET quantity_dispensed = quantity_dispensed + :qty_disp,
                    quantity_remaining = GREATEST(0, quantity_remaining - :qty_disp2)
                WHERE id = :item_id
            ");

            foreach ($itemsToDispense as $dispItem) {
                self::deductMedicationStock($pdo, $dispItem['medication_id'], $dispItem['qty_to_dispense']);

                $stmtUpdateItem->execute([
                    ':qty_disp'  => $dispItem['qty_to_dispense'],
                    ':qty_disp2' => $dispItem['qty_to_dispense'],
                    ':item_id'   => $dispItem['prescription_item_id'],
                ]);
            }

            // 4. Determine new prescription status (all remaining == 0 => dispensed, else partially_dispensed)
            $stmtCheckRemaining = $pdo->prepare("
                SELECT SUM(quantity_remaining) 
                FROM prescription_items 
                WHERE prescription_id = :id
            ");
            $stmtCheckRemaining->execute([':id' => $prescriptionId]);
            $totalRemainingAfter = (int)$stmtCheckRemaining->fetchColumn();

            $newStatus = ($totalRemainingAfter <= 0) ? 'dispensed' : 'partially_dispensed';

            $stmtUpdateRx = $pdo->prepare("
                UPDATE prescriptions 
                SET status = :status,
                    pharmacist_notes = :notes,
                    dispensed_by = :user_id,
                    dispensed_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdateRx->execute([
                ':status'  => $newStatus,
                ':notes'   => $pharmacistNotes ?: ($newStatus === 'dispensed' ? 'Fully dispensed to patient' : 'Partially dispensed to patient'),
                ':user_id' => $dispensedBy,
                ':id'      => $prescriptionId,
            ]);

            // 5. Create Pharmacy Sale Record for this Dispensing Event
            $invoiceNumber = 'PHARM-RX-' . date('Y') . '-' . str_pad((string)$prescriptionId, 4, '0', STR_PAD_LEFT) . '-' . substr(uniqid(), -3);
            $stmtSale = $pdo->prepare("
                INSERT INTO pharmacy_sales (invoice_number, sale_type, prescription_id, customer_name, customer_phone, total_amount, discount_amount, credit_applied, net_amount, paid_amount, due_amount, payment_status, cashier_id)
                VALUES (:inv, 'prescription', :rx_id, :cust_name, :phone, :total, :discount, :credit_applied, :net, :paid, :due, :status, :cashier)
            ");
            $stmtSale->execute([
                ':inv'            => $invoiceNumber,
                ':rx_id'          => $prescriptionId,
                ':cust_name'      => $prescription['patient_name'],
                ':phone'          => $phone,
                ':total'          => $totalAmount,
                ':discount'       => $discount,
                ':credit_applied' => $creditToUse,
                ':net'            => $netAmount,
                ':paid'           => round($paid + $creditToUse, 2),
                ':due'            => $dueAmount,
                ':status'         => $paymentStatus,
                ':cashier'        => $dispensedBy,
            ]);
            $saleId = (int)$pdo->lastInsertId();

            // 6. Record Upfront Payment if made
            if ($creditToUse > 0) {
                $stmtPayCredit = $pdo->prepare("
                    INSERT INTO sale_payments (sale_id, amount_paid, payment_method, notes, received_by)
                    VALUES (:sale_id, :amount_paid, 'credit', :notes, :received_by)
                ");
                $stmtPayCredit->execute([
                    ':sale_id'     => $saleId,
                    ':amount_paid' => $creditToUse,
                    ':notes'       => 'Deducted from Patient Account Credit ($' . number_format($creditToUse, 2) . ')',
                    ':received_by' => $dispensedBy,
                ]);
            }

            if ($paid > 0) {
                $stmtPay = $pdo->prepare("
                    INSERT INTO sale_payments (sale_id, amount_paid, payment_method, notes, received_by)
                    VALUES (:sale_id, :amount_paid, :payment_method, :notes, :received_by)
                ");
                $stmtPay->execute([
                    ':sale_id'        => $saleId,
                    ':amount_paid'    => $paid,
                    ':payment_method' => in_array($paymentMethod, ['cash', 'card', 'mobile'], true) ? $paymentMethod : 'cash',
                    ':notes'          => ($newStatus === 'partially_dispensed') ? 'Partial prescription dispense payment' : 'Prescription fulfillment payment',
                    ':received_by'    => $dispensedBy,
                ]);
            }

            // 7. Insert Sale Items for this event (persisting exact prescription_item_id & dosage)
            $stmtSaleItem = $pdo->prepare("
                INSERT INTO pharmacy_sale_items (sale_id, medication_id, quantity, unit_price, total_price, prescription_item_id, dosage_instructions)
                VALUES (:sale_id, :med_id, :qty, :price, :total, :pi_id, :dosage)
            ");
            foreach ($itemsToDispense as $dispItem) {
                $stmtSaleItem->execute([
                    ':sale_id' => $saleId,
                    ':med_id'  => $dispItem['medication_id'],
                    ':qty'     => $dispItem['qty_to_dispense'],
                    ':price'   => $dispItem['unit_price'],
                    ':total'   => $dispItem['line_total'],
                    ':pi_id'   => $dispItem['prescription_item_id'] ?? null,
                    ':dosage'  => $dispItem['dosage_instructions'] ?? 'Take as directed',
                ]);
            }

            // 8. Auto-Sync Invoice into Billing Hub for cashiering & Accounting sync
            require_once __DIR__ . '/BillingOperation.php';
            try {
                $invItems = [];
                foreach ($itemsToDispense as $dispItem) {
                    $invItems[] = [
                        'medication_id'       => $dispItem['medication_id'],
                        'medication_name'     => $dispItem['medication_name'] ?? 'Medication',
                        'dosage_instructions' => $dispItem['dosage_instructions'] ?? 'Prescribed dose',
                        'quantity'            => $dispItem['qty_to_dispense'],
                        'unit_price'          => $dispItem['unit_price'],
                    ];
                }
                $invoiceId = BillingOperation::createPharmacyInvoice(
                    $prescriptionId,
                    $invItems,
                    $discount,
                    $phone,
                    $dispensedBy
                );
                if ($creditToUse > 0) {
                    BillingOperation::processInvoicePayment(
                        $invoiceId,
                        $creditToUse,
                        'credit',
                        "Prescription Credit Settlement [{$prescription['rx_number']}]",
                        $dispensedBy
                    );
                }
                if ($paid > 0) {
                    BillingOperation::processInvoicePayment(
                        $invoiceId,
                        $paid,
                        $paymentMethod,
                        "Prescription Fulfillment [{$prescription['rx_number']}]",
                        $dispensedBy
                    );
                }
            } catch (Exception $e) {
                error_log('[HPMS PHARMACY BILLING SYNC ERROR] ' . $e->getMessage());
            }

            $pdo->commit();
            return $saleId;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS DISPENSE ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Processes a direct Walk-in (Over-The-Counter) Sale.
     * Supports discounts, cash/partial/credit payments, and auto stock deduction.
     *
     * @param array $saleData ['customer_name', 'customer_phone', 'discount_amount', 'paid_amount', 'payment_method', 'cashier_id']
     * @param array $items Array of ['medication_id' => int, 'quantity' => int]
     * @return int Sale ID
     */
    public static function createWalkInSale(array $saleData, array $items): int
    {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            if (empty($items)) {
                throw new InvalidArgumentException('Please select at least one medication to sell.');
            }

            $customerName   = trim($saleData['customer_name'] ?? 'Walk-in Customer');
            $customerPhone  = trim($saleData['customer_phone'] ?? '');
            $discountAmount = max(0.0, (float)($saleData['discount_amount'] ?? 0.0));
            $paidAmount     = (float)($saleData['paid_amount'] ?? 0.0);
            $paymentMethod  = in_array($saleData['payment_method'] ?? 'cash', ['cash', 'card', 'mobile'], true) ? $saleData['payment_method'] : 'cash';
            $cashierId      = (int)($saleData['cashier_id'] ?? 1);

            // 1. Calculate items total and verify stock
            $totalAmount = 0.0;
            $validatedItems = [];

            foreach ($items as $item) {
                $medId = (int)($item['medication_id'] ?? 0);
                $qty   = (int)($item['quantity'] ?? 0);

                if ($medId <= 0 || $qty <= 0) continue;

                $med = InventoryOperation::getMedicationById($medId);
                if (!$med) {
                    throw new InvalidArgumentException('Selected medication does not exist.');
                }

                if ($med['current_stock'] < $qty) {
                    throw new RuntimeException(sprintf(
                        'Insufficient stock for "%s". Required: %d, Available: %d.',
                        $med['name'],
                        $qty,
                        $med['current_stock']
                    ));
                }

                $price = (float)$med['unit_price'];
                $itemTotal = $price * $qty;
                $totalAmount += $itemTotal;

                $validatedItems[] = [
                    'medication_id' => $medId,
                    'quantity'      => $qty,
                    'unit_price'    => $price,
                    'total_price'   => $itemTotal,
                ];
            }

            if (empty($validatedItems)) {
                throw new InvalidArgumentException('No valid medication items were provided.');
            }

            // 2. Financial Breakdown
            $netAmount = max(0.0, $totalAmount - $discountAmount);
            $paidAmount = min($netAmount, max(0.0, $paidAmount));
            $dueAmount  = max(0.0, $netAmount - $paidAmount);

            if ($dueAmount <= 0.001) {
                $paymentStatus = 'paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'credit';
            }

            if ($paymentStatus !== 'paid' && empty($customerPhone)) {
                throw new InvalidArgumentException('Customer phone number is required when extending credit or partial debt.');
            }

            // 3. Deduct stock
            foreach ($validatedItems as $vItem) {
                self::deductMedicationStock($pdo, $vItem['medication_id'], $vItem['quantity']);
            }

            // 4. Create Sale Record
            $invoiceNumber = 'POS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $stmtSale = $pdo->prepare("
                INSERT INTO pharmacy_sales (invoice_number, sale_type, customer_name, customer_phone, total_amount, discount_amount, net_amount, paid_amount, due_amount, payment_status, cashier_id)
                VALUES (:inv, 'walk_in', :cust_name, :phone, :total, :discount, :net, :paid, :due, :status, :cashier)
            ");
            $stmtSale->execute([
                ':inv'       => $invoiceNumber,
                ':cust_name' => $customerName ?: 'Walk-in Customer',
                ':phone'     => $customerPhone,
                ':total'     => $totalAmount,
                ':discount'  => $discountAmount,
                ':net'       => $netAmount,
                ':paid'      => $paidAmount,
                ':due'       => $dueAmount,
                ':status'    => $paymentStatus,
                ':cashier'   => $cashierId,
            ]);
            $saleId = (int)$pdo->lastInsertId();

            // 5. Insert Sale Items
            $stmtItem = $pdo->prepare("
                INSERT INTO pharmacy_sale_items (sale_id, medication_id, quantity, unit_price, total_price)
                VALUES (:sale_id, :med_id, :qty, :price, :total)
            ");
            foreach ($validatedItems as $vItem) {
                $stmtItem->execute([
                    ':sale_id' => $saleId,
                    ':med_id'  => $vItem['medication_id'],
                    ':qty'     => $vItem['quantity'],
                    ':price'   => $vItem['unit_price'],
                    ':total'   => $vItem['total_price'],
                ]);
            }

            // 6. Calculate Total Cost Price for COGS & Inventory Asset reduction
            $totalCostPrice = 0.0;
            foreach ($validatedItems as $vItem) {
                $medRec = InventoryOperation::getMedicationById($vItem['medication_id']);
                $itemCost = (float)($medRec['cost_price'] ?? 0);
                $totalCostPrice += ($itemCost * $vItem['quantity']);
            }

            // 7. Record Initial Payment (if any)
            if ($paidAmount > 0) {
                $stmtPay = $pdo->prepare("
                    INSERT INTO sale_payments (sale_id, amount_paid, payment_method, notes, received_by)
                    VALUES (:sale_id, :amount_paid, :payment_method, 'Initial POS payment', :received_by)
                ");
                $stmtPay->execute([
                    ':sale_id'        => $saleId,
                    ':amount_paid'    => $paidAmount,
                    ':payment_method' => $paymentMethod,
                    ':received_by'    => $cashierId,
                ]);
            }

            // 8. Auto-Sync Unified Invoice into Billing Hub
            require_once __DIR__ . '/BillingOperation.php';
            try {
                $stmtInv = $pdo->prepare("
                    INSERT INTO invoices (invoice_number, bill_type, customer_name, customer_phone, subtotal, discount, net_total, paid_amount, due_amount, payment_method, payment_status, cashier_id, paid_at)
                    VALUES (:inv, 'walk_in', :cust, :phone, :sub, :disc, :net, :paid, :due, :method, :status, :cashier, :paid_at)
                ");
                $stmtInv->execute([
                    ':inv'     => $invoiceNumber,
                    ':cust'    => $customerName ?: 'Walk-in Customer',
                    ':phone'   => $customerPhone,
                    ':sub'     => $totalAmount,
                    ':disc'    => $discountAmount,
                    ':net'     => $netAmount,
                    ':paid'    => $paidAmount,
                    ':due'     => $dueAmount,
                    ':method'  => $paymentMethod,
                    ':status'  => $paymentStatus,
                    ':cashier' => $cashierId,
                    ':paid_at' => $paidAmount > 0 ? date('Y-m-d H:i:s') : null,
                ]);
                $invoiceId = (int)$pdo->lastInsertId();

                $stmtInvItem = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, item_type, item_reference_id, item_name, item_description, quantity, unit_price, total_price)
                    VALUES (:inv_id, 'medication', :ref_id, :name, 'Walk-in pharmacy sale item', :qty, :price, :total)
                ");
                foreach ($validatedItems as $vItem) {
                    $medRec = InventoryOperation::getMedicationById($vItem['medication_id']);
                    $stmtInvItem->execute([
                        ':inv_id' => $invoiceId,
                        ':ref_id' => $vItem['medication_id'],
                        ':name'   => $medRec['name'] ?? 'Medication',
                        ':qty'    => $vItem['quantity'],
                        ':price'  => $vItem['unit_price'],
                        ':total'  => $vItem['total_price'],
                    ]);
                }
            } catch (Exception $e) {
                error_log('[HPMS POS INVOICE SYNC ERROR] ' . $e->getMessage());
            }

            // 9. Post Balanced Double-Entry Journal Entry to General Ledger
            require_once __DIR__ . '/AccountingOperation.php';
            try {
                AccountingOperation::seedChartOfAccountsIfEmpty();
                $cashAccCode = match ($paymentMethod) {
                    'mobile' => '1020',
                    'bank', 'card' => '1030',
                    default => '1010',
                };
                $cashAcc = AccountingOperation::getAccountByCode($cashAccCode);
                $arAcc   = AccountingOperation::getAccountByCode('1100'); // Accounts Receivable - Patients
                $revAcc  = AccountingOperation::getAccountByCode('4010'); // Pharmacy Sales Revenue
                $cogsAcc = AccountingOperation::getAccountByCode('5010'); // Cost of Dispensed Medications
                $invAcc  = AccountingOperation::getAccountByCode('1200'); // Pharmacy Inventory Asset

                $journalLines = [];

                // A. Revenue Recognition & Payment / Receivable ($netAmount)
                if ($paidAmount > 0 && $cashAcc) {
                    $journalLines[] = [
                        'account_id' => (int)$cashAcc['id'],
                        'debit'      => $paidAmount,
                        'credit'     => 0.00,
                        'memo'       => "POS Cash collected for sale {$invoiceNumber}",
                    ];
                }
                if ($dueAmount > 0 && $arAcc) {
                    $journalLines[] = [
                        'account_id' => (int)$arAcc['id'],
                        'debit'      => $dueAmount,
                        'credit'     => 0.00,
                        'memo'       => "POS Patient Credit (AR) for sale {$invoiceNumber} ({$customerName})",
                    ];
                }
                if ($netAmount > 0 && $revAcc) {
                    $journalLines[] = [
                        'account_id' => (int)$revAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $netAmount,
                        'memo'       => "Pharmacy Sales Revenue for POS sale {$invoiceNumber}",
                    ];
                }

                // B. Inventory Cost Depletion (COGS)
                if ($totalCostPrice > 0 && $cogsAcc && $invAcc) {
                    $journalLines[] = [
                        'account_id' => (int)$cogsAcc['id'],
                        'debit'      => $totalCostPrice,
                        'credit'     => 0.00,
                        'memo'       => "Cost of Goods Sold (COGS) for POS sale {$invoiceNumber}",
                    ];
                    $journalLines[] = [
                        'account_id' => (int)$invAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $totalCostPrice,
                        'memo'       => "Inventory Asset reduction for POS sale {$invoiceNumber}",
                    ];
                }

                if (!empty($journalLines)) {
                    AccountingOperation::recordJournalEntry(
                        date('Y-m-d'),
                        'pharmacy_sale',
                        $saleId,
                        "POS Walk-in Pharmacy Sale: {$invoiceNumber} - {$customerName}",
                        $journalLines,
                        $cashierId
                    );
                }
            } catch (Exception $e) {
                error_log('[HPMS POS GL SYNC ERROR] ' . $e->getMessage());
            }

            $pdo->commit();
            return $saleId;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS POS ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves a pharmacy sale with all itemized lines.
     *
     * @param int $saleId
     * @return array|null
     */
    public static function getSaleById(int $saleId): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM pharmacy_sales WHERE id = ?");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $stmtItems = $pdo->prepare("
            SELECT psi.*, m.name as medication_name, m.med_code as medication_code, 
                   COALESCE(psi.dosage_instructions, pi.dosage_instructions, 'Take as directed') as dosage_instructions
            FROM pharmacy_sale_items psi
            JOIN medications m ON psi.medication_id = m.id
            LEFT JOIN prescription_items pi ON psi.prescription_item_id = pi.id
            WHERE psi.sale_id = :sale_id
            GROUP BY psi.id
        ");
        $stmtItems->execute([
            ':sale_id' => $saleId,
        ]);
        $sale['items'] = $stmtItems->fetchAll();

        return $sale;
    }

    /**
     * Records a partial or full patient debt collection towards a pharmacy sale invoice.
     *
     * @param int $saleId
     * @param float $amountPaid
     * @param string $paymentMethod
     * @param string|null $notes
     * @param int $receivedBy
     * @return bool
     */
    public static function collectPatientDebtPayment(int $saleId, float $amountPaid, string $paymentMethod, ?string $notes, int $receivedBy): bool
    {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT * FROM pharmacy_sales WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $saleId]);
            $sale = $stmt->fetch();

            if (!$sale) {
                throw new InvalidArgumentException('Pharmacy sale invoice not found.');
            }

            $currentDue = (float)$sale['due_amount'];
            if ($currentDue <= 0.001) {
                throw new InvalidArgumentException('This invoice is already fully paid.');
            }

            if ($amountPaid <= 0 || $amountPaid > ($currentDue + 0.001)) {
                throw new InvalidArgumentException('Payment amount must be greater than $0 and cannot exceed outstanding due balance of $' . number_format($currentDue, 2));
            }

            // 1. Insert Payment
            $stmtPay = $pdo->prepare("
                INSERT INTO sale_payments (sale_id, amount_paid, payment_method, notes, received_by)
                VALUES (:sale_id, :amount_paid, :payment_method, :notes, :received_by)
            ");
            $stmtPay->execute([
                ':sale_id'        => $saleId,
                ':amount_paid'    => $amountPaid,
                ':payment_method' => in_array($paymentMethod, ['cash', 'card', 'mobile'], true) ? $paymentMethod : 'cash',
                ':notes'          => $notes ?: 'Patient debt installment collection',
                ':received_by'    => $receivedBy,
            ]);

            // 2. Update Sale Balance
            $newPaid = (float)$sale['paid_amount'] + $amountPaid;
            $newDue  = max(0.0, (float)$sale['net_amount'] - $newPaid);
            $newStatus = ($newDue <= 0.001) ? 'paid' : 'partial';

            $stmtUpdate = $pdo->prepare("
                UPDATE pharmacy_sales 
                SET paid_amount = :paid,
                    due_amount = :due,
                    payment_status = :status
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':paid'   => $newPaid,
                ':due'    => $newDue,
                ':status' => $newStatus,
                ':id'     => $saleId,
            ]);

            // 3. Update Invoices table if exists
            $stmtUpdateInv = $pdo->prepare("
                UPDATE invoices
                SET paid_amount = paid_amount + :paid,
                    due_amount = GREATEST(0, due_amount - :paid_due),
                    payment_status = :status,
                    paid_at = NOW()
                WHERE invoice_number = :inv
            ");
            $stmtUpdateInv->execute([
                ':paid'     => $amountPaid,
                ':paid_due' => $amountPaid,
                ':status'   => $newStatus,
                ':inv'      => $sale['invoice_number'],
            ]);

            // 4. Post Double-Entry Journal for Patient Debt Collection
            require_once __DIR__ . '/AccountingOperation.php';
            try {
                AccountingOperation::seedChartOfAccountsIfEmpty();
                $cashAccCode = match ($paymentMethod) {
                    'mobile' => '1020',
                    'bank', 'card' => '1030',
                    default => '1010',
                };
                $cashAcc = AccountingOperation::getAccountByCode($cashAccCode);
                $arAcc   = AccountingOperation::getAccountByCode('1100'); // Accounts Receivable - Patients

                if ($cashAcc && $arAcc && $amountPaid > 0) {
                    AccountingOperation::recordJournalEntry(
                        date('Y-m-d'),
                        'patient_debt_collection',
                        $saleId,
                        "Patient Debt Settlement: \${$amountPaid} collected for {$sale['invoice_number']} ({$sale['customer_name']})",
                        [
                            [
                                'account_id' => (int)$cashAcc['id'],
                                'debit'      => $amountPaid,
                                'credit'     => 0.00,
                                'memo'       => "Cash received from customer debt settlement ({$sale['invoice_number']})",
                            ],
                            [
                                'account_id' => (int)$arAcc['id'],
                                'debit'      => 0.00,
                                'credit'     => $amountPaid,
                                'memo'       => "Accounts Receivable clearance for ({$sale['invoice_number']})",
                            ],
                        ],
                        $receivedBy
                    );
                }
            } catch (Exception $e) {
                error_log('[HPMS COLLECT DEBT GL ERROR] ' . $e->getMessage());
            }

            $pdo->commit();
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS COLLECT DEBT ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetches all pharmacy sales with outstanding patient debt.
     *
     * @return array
     */
    public static function getOutstandingPatientDebts(): array
    {
        $pdo = getDBConnection();
        $sql = "
            SELECT 
                s.*,
                p.rx_number
            FROM pharmacy_sales s
            LEFT JOIN prescriptions p ON s.prescription_id = p.id
            WHERE s.due_amount > 0 AND s.payment_status IN ('partial', 'credit')
            ORDER BY s.created_at DESC
        ";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Deducts quantity from medication master stock and batch levels using FIFO.
     *
     * @param PDO $pdo
     * @param int $medicationId
     * @param int $quantityToDeduct
     */
    private static function deductMedicationStock(PDO $pdo, int $medicationId, int $quantityToDeduct): void
    {
        // 1. Decrement overall stock in medications table
        $stmtMed = $pdo->prepare("
            UPDATE medications 
            SET current_stock = GREATEST(0, current_stock - :qty)
            WHERE id = :id
        ");
        $stmtMed->execute([
            ':qty' => $quantityToDeduct,
            ':id'  => $medicationId,
        ]);

        $pdo->prepare("
            UPDATE medications 
            SET status = CASE 
                WHEN current_stock <= 0 THEN 'out_of_stock'
                WHEN current_stock <= min_stock_alert THEN 'low_stock'
                ELSE 'in_stock'
            END
            WHERE id = ?
        ")->execute([$medicationId]);

        // 2. Deduct from batches using FIFO (earliest expiry first)
        $stmtBatches = $pdo->prepare("
            SELECT id, quantity_remaining 
            FROM medicine_batches 
            WHERE medication_id = :med_id AND quantity_remaining > 0 
            ORDER BY expiry_date ASC
            FOR UPDATE
        ");
        $stmtBatches->execute([':med_id' => $medicationId]);
        $batches = $stmtBatches->fetchAll();

        $remainingToDeduct = $quantityToDeduct;
        $stmtUpdateBatch = $pdo->prepare("UPDATE medicine_batches SET quantity_remaining = :qty WHERE id = :id");

        foreach ($batches as $batch) {
            if ($remainingToDeduct <= 0) break;

            $batchQty = (int)$batch['quantity_remaining'];
            if ($batchQty <= $remainingToDeduct) {
                $stmtUpdateBatch->execute([':qty' => 0, ':id' => $batch['id']]);
                $remainingToDeduct -= $batchQty;
            } else {
                $stmtUpdateBatch->execute([':qty' => $batchQty - $remainingToDeduct, ':id' => $batch['id']]);
                $remainingToDeduct = 0;
            }
        }
    }

    /**
     * Seeds initial default pending prescriptions for realistic clinical simulation if empty.
     */
    public static function seedDefaultPrescriptionsIfEmpty(): void
    {
        $pdo = getDBConnection();

        try {
            $isSeeded = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'prescriptions_seeded'")->fetchColumn();
            if ($isSeeded === '1') {
                return; // Already initialized once. Never auto-reseed if user deleted records!
            }
        } catch (Exception $e) {}

        $rxCount = (int)$pdo->query("SELECT COUNT(*) FROM prescriptions")->fetchColumn();

        if ($rxCount === 0) {
            // Mark seeded so it never auto-seeds again when cleared
            try {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('prescriptions_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
            } catch (Exception $e) {}

            // Fetch available medication IDs
            $azithromycin = InventoryOperation::getMedicationByCode('MED-AZI-500');
            $salbutamol   = InventoryOperation::getMedicationByCode('MED-SAL-100');
            $lisinopril   = InventoryOperation::getMedicationByCode('MED-LIS-010');
            $metformin    = InventoryOperation::getMedicationByCode('MED-MET-850');
            $ibuprofen    = InventoryOperation::getMedicationByCode('MED-IBU-400');

            if (!$azithromycin || !$salbutamol) {
                InventoryOperation::seedDefaultInventoryIfEmpty();
                $azithromycin = InventoryOperation::getMedicationByCode('MED-AZI-500');
                $salbutamol   = InventoryOperation::getMedicationByCode('MED-SAL-100');
                $lisinopril   = InventoryOperation::getMedicationByCode('MED-LIS-010');
                $metformin    = InventoryOperation::getMedicationByCode('MED-MET-850');
                $ibuprofen    = InventoryOperation::getMedicationByCode('MED-IBU-400');
            }

            // Prescription 1: Michael Chen (Featured)
            $stmt = $pdo->prepare("
                INSERT INTO prescriptions (rx_number, patient_name, patient_mrn, doctor_name, status, allergy_alert, pharmacist_notes)
                VALUES (?, ?, ?, ?, 'pending', ?, ?)
            ");
            $stmt->execute(['RX-2026-8942', 'Michael Chen', 'PAT-2023-0892', 'Dr. Alan Carter', 'Penicillin (Severe hives/rash)', 'Verify completed 5-day cycle.']);
            $rx1 = (int)$pdo->lastInsertId();

            if ($azithromycin) {
                $azPrice = (float)$azithromycin['unit_price'];
                $pdo->prepare("INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$rx1, $azithromycin['id'], '1 tab daily x 5 days (1 Z-Pack)', 1, 0, 1, 1, $azPrice, $azPrice]);
            }
            if ($salbutamol) {
                $salPrice = (float)$salbutamol['unit_price'];
                $pdo->prepare("INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$rx1, $salbutamol['id'], '2 puffs Q4-6H PRN (1 Canister)', 1, 0, 1, 1, $salPrice, $salPrice]);
            }

            // Prescription 2: Elena Rodriguez (30 Tablets for 30 days)
            $stmt->execute(['RX-2026-8941', 'Elena Rodriguez', 'PAT-2023-0889', 'Dr. Alan Carter', 'None known', null]);
            $rx2 = (int)$pdo->lastInsertId();
            if ($lisinopril) {
                $lisPrice = (float)$lisinopril['unit_price'];
                $pdo->prepare("INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$rx2, $lisinopril['id'], '1 tablet every morning (30-day course)', 30, 0, 30, 30, $lisPrice, round(30 * $lisPrice, 2)]);
            }

            // Prescription 3: Robert Wilson (60 Tablets for 30 days)
            $stmt->execute(['RX-2026-8940', 'Robert Wilson', 'PAT-2023-0890', 'Dr. Maya Lin', 'Sulfa drugs', null]);
            $rx3 = (int)$pdo->lastInsertId();
            if ($metformin) {
                $metPrice = (float)$metformin['unit_price'];
                $pdo->prepare("INSERT INTO prescription_items (prescription_id, medication_id, dosage_instructions, quantity_prescribed, quantity_dispensed, quantity_remaining, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$rx3, $metformin['id'], '1 tablet twice daily with meals (60 tabs)', 60, 0, 60, 60, $metPrice, round(60 * $metPrice, 2)]);
            }
        }
    }
}
