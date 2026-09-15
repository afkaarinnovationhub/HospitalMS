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
     * Ensures prescriptions.status ENUM supports 'external_purchase'.
     */
    public static function ensurePrescriptionStatusEnumSupportsExternal(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo = getDBConnection();
            $stmt = $pdo->query("SHOW COLUMNS FROM prescriptions LIKE 'status'");
            $col = $stmt->fetch();
            if ($col && strpos((string)$col['Type'], 'external_purchase') === false) {
                $pdo->exec("ALTER TABLE prescriptions MODIFY COLUMN status ENUM('pending','partially_dispensed','dispensed','cancelled','external_purchase') NOT NULL DEFAULT 'pending'");
            }
        } catch (Exception $e) {
            error_log('[HPMS ENSURE PRESCRIPTION STATUS ERROR] ' . $e->getMessage());
        }
    }

    /**
     * Fetches all pending or partially dispensed electronic doctor prescriptions.
     *
     * @return array
     */
    public static function getPendingPrescriptionsQueue(): array
    {
        $pdo = getDBConnection();
        self::ensurePrescriptionStatusEnumSupportsExternal();
        $sql = "
            SELECT 
                p.*,
                COUNT(pi.id) as item_count,
                GROUP_CONCAT(m.name SEPARATOR ', ') as medication_names
            FROM prescriptions p
            LEFT JOIN prescription_items pi ON p.id = pi.prescription_id
            LEFT JOIN medications m ON pi.medication_id = m.id
            WHERE p.status = 'pending'
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Marks a doctor prescription as External Purchase (Patient chooses to purchase elsewhere).
     * Zero stock is deducted from hospital inventory, zero charge billed to patient.
     *
     * @param int $prescriptionId
     * @param string|null $notes
     * @param int $userId
     * @return bool
     */
    public static function markPrescriptionExternal(int $prescriptionId, ?string $notes = null, int $userId = 1): bool
    {
        $pdo = getDBConnection();
        self::ensurePrescriptionStatusEnumSupportsExternal();

        $prescription = self::getPrescriptionById($prescriptionId);
        if (!$prescription) {
            throw new InvalidArgumentException('Prescription not found.');
        }

        if (!in_array($prescription['status'], ['pending', 'partially_dispensed'], true)) {
            throw new InvalidArgumentException('This prescription is already marked as ' . $prescription['status'] . '.');
        }

        $formattedNotes = trim($notes ?: 'Bukaanka ayaa doortay inuu daawada ka soo gato farmashiye dibadda ah (External Purchase).');

        $stmt = $pdo->prepare("
            UPDATE prescriptions
            SET status = 'external_purchase',
                pharmacist_notes = :notes,
                dispensed_by = :user_id,
                dispensed_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            ':notes'   => $formattedNotes,
            ':user_id' => $userId,
            ':id'      => $prescriptionId,
        ]);
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

            // 3. Generate Pharmacy Invoice/Sale Reference Number
            $invoiceNumber = 'PHARM-RX-' . date('Y') . '-' . str_pad((string)$prescriptionId, 4, '0', STR_PAD_LEFT) . '-' . substr(uniqid(), -3);

            // 4. Deduct ONLY the physically dispensed quantities from medications & batches using FIFO
            $stmtUpdateItem = $pdo->prepare("
                UPDATE prescription_items 
                SET quantity_dispensed = quantity_dispensed + :qty_disp,
                    quantity_remaining = GREATEST(0, quantity_remaining - :qty_disp2)
                WHERE id = :item_id
            ");

            $totalDispensedCost = 0.0;
            foreach ($itemsToDispense as $dispItem) {
                $totalDispensedCost += self::deductMedicationStock(
                    $pdo, 
                    $dispItem['medication_id'], 
                    $dispItem['qty_to_dispense'], 
                    $invoiceNumber, 
                    $dispensedBy
                );

                $stmtUpdateItem->execute([
                    ':qty_disp'  => $dispItem['qty_to_dispense'],
                    ':qty_disp2' => $dispItem['qty_to_dispense'],
                    ':item_id'   => $dispItem['prescription_item_id'],
                ]);
            }

            // 5. Determine new prescription status (all remaining == 0 => dispensed, else partially_dispensed)
            $stmtCheckRemaining = $pdo->prepare("
                SELECT SUM(quantity_remaining) 
                FROM prescription_items 
                WHERE prescription_id = :id
            ");
            $stmtCheckRemaining->execute([':id' => $prescriptionId]);
            $totalRemainingAfter = (int)$stmtCheckRemaining->fetchColumn();

            // Finalize prescription so it cleanly leaves the Pending Queue
            // Any items entered with 0 or left unfulfilled are concluded per patient decision.
            $newStatus = 'dispensed';

            $noteParts = [];
            if ($totalRemainingAfter > 0) {
                $noteParts[] = "Partially dispensed ({$totalRemainingAfter} units unfulfilled/external)";
            }
            if (!empty($pharmacistNotes)) {
                $noteParts[] = $pharmacistNotes;
            }
            $finalNotes = !empty($noteParts) ? implode(' • ', $noteParts) : 'Dispensed to patient';

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
                ':notes'   => $finalNotes,
                ':user_id' => $dispensedBy,
                ':id'      => $prescriptionId,
            ]);

            // 6. Create Pharmacy Sale Record for this Dispensing Event
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
                        $dispensedBy,
                        $totalDispensedCost
                    );
                    if ($paid > 0) {
                        BillingOperation::processInvoicePayment(
                            $invoiceId,
                            $paid,
                            $paymentMethod ?: 'cash',
                            "Prescription Fulfillment [{$prescription['rx_number']}]",
                            $dispensedBy
                        );
                    }
                } else {
                    BillingOperation::processInvoicePayment(
                        $invoiceId,
                        $paid,
                        $paymentMethod ?: 'cash',
                        "Prescription Fulfillment [{$prescription['rx_number']}]",
                        $dispensedBy,
                        $totalDispensedCost
                    );
                }
            } catch (Exception $e) {
                error_log('[HPMS PHARMACY BILLING SYNC ERROR] ' . $e->getMessage());
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $saleId;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

            // 2.1 Resolve or Auto-Register Patient for Credit Debt Tracking
            $patientId = !empty($saleData['patient_id']) ? (int)$saleData['patient_id'] : null;

            if ($patientId > 0) {
                $stmtP = $pdo->prepare("SELECT first_name, last_name, phone, mrn FROM patients WHERE id = ?");
                $stmtP->execute([$patientId]);
                $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
                if ($pRow) {
                    $pFullName = trim(($pRow['first_name'] ?? '') . ' ' . ($pRow['last_name'] ?? ''));
                    if (empty($customerName) || strtolower($customerName) === 'walk-in customer') {
                        $customerName = $pFullName;
                    }
                    if (empty($customerPhone)) {
                        $customerPhone = $pRow['phone'] ?? '';
                    }
                }
            } elseif ($dueAmount > 0.005) {
                // Customer is taking debt: auto-resolve by phone or auto-register so debt can be tracked and found later
                if (!empty($customerPhone)) {
                    $stmtFind = $pdo->prepare("SELECT id, first_name, last_name FROM patients WHERE phone = ? LIMIT 1");
                    $stmtFind->execute([$customerPhone]);
                    $foundP = $stmtFind->fetch(PDO::FETCH_ASSOC);
                    if ($foundP) {
                        $patientId = (int)$foundP['id'];
                        if (empty($customerName) || strtolower($customerName) === 'walk-in customer') {
                            $customerName = trim(($foundP['first_name'] ?? '') . ' ' . ($foundP['last_name'] ?? ''));
                        }
                    }
                }

                if (!$patientId) {
                    require_once __DIR__ . '/PatientOperation.php';
                    $trimmedName = trim((string)$customerName);
                    if (empty($trimmedName) || strtolower($trimmedName) === 'walk-in customer') {
                        if (!empty($customerPhone)) {
                            $firstName = 'Walk-in';
                            $lastName  = $customerPhone;
                        } else {
                            $firstName = 'Walk-in';
                            $lastName  = 'Debtor';
                        }
                    } else {
                        $nameParts = preg_split('/\s+/', $trimmedName, 2);
                        $firstName = !empty($nameParts[0]) ? $nameParts[0] : 'Walk-in';
                        $lastName  = !empty($nameParts[1]) ? $nameParts[1] : 'Debtor';
                    }

                    $resolvedPhone = !empty($customerPhone) ? $customerPhone : ('252-' . random_int(1000000, 9999999));

                    $patientId = PatientOperation::registerPatient([
                        'first_name'    => $firstName,
                        'last_name'     => $lastName,
                        'phone'         => $resolvedPhone,
                        'gender'        => 'other',
                        'address'       => 'Walk-in OTC Debtor',
                        'registered_by' => $cashierId,
                    ]);

                    $customerName = trim($firstName . ' ' . $lastName);
                    $customerPhone = $resolvedPhone;
                }
            }

            // 3. Generate POS Invoice / Sale Reference Number
            $invoiceNumber = 'POS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

            // 4. Deduct stock & compute exact batch FIFO COGS
            $totalCostPrice = 0.0;
            foreach ($validatedItems as $vItem) {
                $totalCostPrice += self::deductMedicationStock(
                    $pdo, 
                    $vItem['medication_id'], 
                    $vItem['quantity'], 
                    $invoiceNumber, 
                    $cashierId
                );
            }

            // 5. Create Sale Record
            $stmtSale = $pdo->prepare("
                INSERT INTO pharmacy_sales (invoice_number, sale_type, patient_id, customer_name, customer_phone, total_amount, discount_amount, net_amount, paid_amount, due_amount, payment_status, cashier_id)
                VALUES (:inv, 'walk_in', :patient_id, :cust_name, :phone, :total, :discount, :net, :paid, :due, :status, :cashier)
            ");
            $stmtSale->execute([
                ':inv'        => $invoiceNumber,
                ':patient_id' => $patientId,
                ':cust_name'  => $customerName ?: 'Walk-in Customer',
                ':phone'      => $customerPhone,
                ':total'      => $totalAmount,
                ':discount'   => $discountAmount,
                ':net'        => $netAmount,
                ':paid'       => $paidAmount,
                ':due'        => $dueAmount,
                ':status'     => $paymentStatus,
                ':cashier'    => $cashierId,
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

            // 6. Record Initial Payment (if any)
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

            // 7. Auto-Sync Unified Invoice into Billing Hub and Post Option A GL Accrual + COGS
            require_once __DIR__ . '/BillingOperation.php';
            try {
                $stmtInv = $pdo->prepare("
                    INSERT INTO invoices (invoice_number, bill_type, patient_id, customer_name, customer_phone, subtotal, discount, net_total, paid_amount, due_amount, payment_method, payment_status, cashier_id, paid_at)
                    VALUES (:inv, 'pharmacy', :patient_id, :cust, :phone, :sub, :disc, :net, 0.00, :due, :method, 'pending', :cashier, NULL)
                ");
                $stmtInv->execute([
                    ':inv'        => $invoiceNumber,
                    ':patient_id' => $patientId,
                    ':cust'       => $customerName ?: 'Walk-in Customer',
                    ':phone'      => $customerPhone,
                    ':sub'        => $totalAmount,
                    ':disc'       => $discountAmount,
                    ':net'        => $netAmount,
                    ':due'        => $netAmount,
                    ':method'     => $paymentMethod,
                    ':cashier'    => $cashierId,
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

                // Shared Option A Accrual & COGS posting logic via unified BillingOperation
                BillingOperation::processInvoicePayment(
                    $invoiceId,
                    $paidAmount,
                    $paymentMethod,
                    "POS Walk-in Pharmacy Sale: {$invoiceNumber} - " . ($customerName ?: 'Walk-in Customer'),
                    $cashierId,
                    $totalCostPrice
                );
            } catch (Exception $e) {
                error_log('[HPMS POS INVOICE & GL SYNC ERROR] ' . $e->getMessage());
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $saleId;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
     * Deducts quantity from medication batch levels using strict FIFO costing
     * (Option A: earliest expiry date first, falling back to earliest received date).
     * 
     * Records an audit movement in medicine_batch_movements for each batch drawn from.
     * Calculates and returns the exact Cost of Goods Sold (COGS) across batches.
     * Rejects sale with a clear RuntimeException if unexpired active batch stock is insufficient.
     *
     * @param PDO $pdo
     * @param int $medicationId
     * @param int $quantityToDeduct
     * @param string|null $referenceTransactionId
     * @param int|null $userId
     * @return float Total Cost of Goods Sold (COGS) for the deducted units
     */
    public static function deductMedicationStock(
        PDO $pdo, 
        int $medicationId, 
        int $quantityToDeduct, 
        ?string $referenceTransactionId = null, 
        ?int $userId = null
    ): float {
        if ($quantityToDeduct <= 0) {
            return 0.0;
        }

        // 1. Fetch all active batches for this medication ordered by confirmed Option A FIFO:
        // Expiry date ASC (earliest expiry first), falling back to received date ASC, then id ASC
        $stmtBatches = $pdo->prepare("
            SELECT id, batch_number, quantity_remaining, unit_cost, cost_price, expiry_date, received_date
            FROM medicine_batches
            WHERE medication_id = :med_id 
              AND status = 'active' 
              AND quantity_remaining > 0
            ORDER BY 
              CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC, 
              expiry_date ASC, 
              received_date ASC, 
              id ASC
            FOR UPDATE
        ");
        $stmtBatches->execute([':med_id' => $medicationId]);
        $batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

        // 2. Validate sufficient unexpired batch inventory (Rule 6: Never allow negative inventory)
        $totalBatchStock = 0;
        foreach ($batches as $b) {
            $totalBatchStock += (int)$b['quantity_remaining'];
        }

        if ($totalBatchStock < $quantityToDeduct) {
            $stmtName = $pdo->prepare("SELECT name FROM medications WHERE id = ?");
            $stmtName->execute([$medicationId]);
            $medName = $stmtName->fetchColumn() ?: "Medication #{$medicationId}";

            throw new RuntimeException(sprintf(
                'Insufficient unexpired batch stock for "%s". Requested: %d units, Available in active batches: %d units. Sale rejected to prevent negative inventory.',
                $medName,
                $quantityToDeduct,
                $totalBatchStock
            ));
        }

        // 3. Draw from oldest unexpired batch first (FIFO)
        $remainingToDeduct = $quantityToDeduct;
        $totalCost = 0.0;

        $stmtUpdateBatch = $pdo->prepare("
            UPDATE medicine_batches 
            SET quantity_remaining = :qty, 
                status = :status 
            WHERE id = :id
        ");

        $stmtMovement = $pdo->prepare("
            INSERT INTO medicine_batch_movements (
                batch_id, movement_type, quantity, unit_cost, total_cost, reference_transaction_id, notes, created_by
            )
            VALUES (
                :batch_id, 'dispense', :quantity, :unit_cost, :total_cost, :ref_tx, :notes, :created_by
            )
        ");

        foreach ($batches as $batch) {
            if ($remainingToDeduct <= 0) {
                break;
            }

            $batchQty = (int)$batch['quantity_remaining'];
            $batchCost = (float)($batch['unit_cost'] > 0 ? $batch['unit_cost'] : $batch['cost_price']);
            $batchId = (int)$batch['id'];

            if ($batchQty <= $remainingToDeduct) {
                $drawQty = $batchQty;
                $newRemaining = 0;
                $newStatus = 'depleted';
            } else {
                $drawQty = $remainingToDeduct;
                $newRemaining = $batchQty - $remainingToDeduct;
                $newStatus = 'active';
            }

            $lineCost = round($drawQty * $batchCost, 2);
            $totalCost += $lineCost;
            $remainingToDeduct -= $drawQty;

            // Update batch quantity and status
            $stmtUpdateBatch->execute([
                ':qty'    => $newRemaining,
                ':status' => $newStatus,
                ':id'     => $batchId,
            ]);

            // Record audit movement for this specific batch slice
            $stmtMovement->execute([
                ':batch_id'    => $batchId,
                ':quantity'    => $drawQty,
                ':unit_cost'   => $batchCost,
                ':total_cost'  => $lineCost,
                ':ref_tx'      => $referenceTransactionId,
                ':notes'       => "Dispensed {$drawQty} units (Batch {$batch['batch_number']})",
                ':created_by'  => $userId,
            ]);
        }

        // 4. Update physical on-hand stock in medications table to stay in sync
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

        return round($totalCost, 2);
    }

    /**
     * Built-in reconciliation check: verifies that General Ledger Account 1200 
     * (Pharmacy Inventory Asset) equals SUM(quantity_remaining × unit_cost) across all active batches.
     *
     * @return array
     */
    public static function reconcileInventoryAssetWithBatches(): array
    {
        $pdo = getDBConnection();

        // 1. Active batches valuation
        $stmtBatches = $pdo->query("
            SELECT 
                COALESCE(SUM(quantity_remaining * CASE WHEN unit_cost > 0 THEN unit_cost ELSE cost_price END), 0) AS total_batch_value,
                COALESCE(SUM(quantity_remaining), 0) AS total_batch_quantity,
                COUNT(*) AS total_active_batches
            FROM medicine_batches
            WHERE status = 'active' AND quantity_remaining > 0
        ");
        $batchRow = $stmtBatches->fetch(PDO::FETCH_ASSOC);
        $batchValuation     = round((float)($batchRow['total_batch_value'] ?? 0.0), 2);
        $batchQuantity      = (int)($batchRow['total_batch_quantity'] ?? 0);
        $activeBatchesCount = (int)($batchRow['total_active_batches'] ?? 0);

        // 2. General Ledger Account 1200 balance: SUM(debit - credit)
        $stmtGL = $pdo->query("
            SELECT COALESCE(SUM(ji.debit - ji.credit), 0) AS gl_balance
            FROM journal_items ji
            JOIN chart_of_accounts a ON ji.account_id = a.id
            WHERE a.account_code = '1200'
        ");
        $glBalance = round((float)$stmtGL->fetchColumn(), 2);

        $discrepancy = round($glBalance - $batchValuation, 2);
        $isReconciled = (abs($discrepancy) < 0.01);

        // 3. Detailed per-medication breakdown
        $stmtMeds = $pdo->query("
            SELECT 
                m.id,
                m.med_code,
                m.name,
                m.current_stock,
                COALESCE(b.batch_qty, 0) AS batch_qty,
                COALESCE(b.batch_value, 0.00) AS batch_value
            FROM medications m
            LEFT JOIN (
                SELECT 
                    medication_id,
                    SUM(quantity_remaining) AS batch_qty,
                    SUM(quantity_remaining * CASE WHEN unit_cost > 0 THEN unit_cost ELSE cost_price END) AS batch_value
                FROM medicine_batches
                WHERE status = 'active' AND quantity_remaining > 0
                GROUP BY medication_id
            ) b ON m.id = b.medication_id
            ORDER BY m.name ASC
        ");
        $medications = $stmtMeds->fetchAll(PDO::FETCH_ASSOC);

        return [
            'account_code'              => '1200',
            'account_name'              => 'Pharmacy Inventory Asset',
            'gl_balance'                => $glBalance,
            'gl_inventory_balance'      => $glBalance,
            'batch_valuation'           => $batchValuation,
            'batch_inventory_valuation' => $batchValuation,
            'batch_quantity'            => $batchQuantity,
            'active_batches_count'      => $activeBatchesCount,
            'discrepancy'               => $discrepancy,
            'is_reconciled'             => $isReconciled,
            'breakdown'                 => $medications,
        ];
    }

    /**
     * Retrieves active batches for a given medication ordered by FIFO.
     *
     * @param int $medicationId
     * @return array
     */
    public static function getActiveBatchesByMedication(int $medicationId): array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT 
                b.*,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
                ROUND(b.quantity_remaining * CASE WHEN b.unit_cost > 0 THEN b.unit_cost ELSE b.cost_price END, 2) AS batch_value
            FROM medicine_batches b
            WHERE b.medication_id = ? AND b.status = 'active' AND b.quantity_remaining > 0
            ORDER BY 
              CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END ASC, 
              b.expiry_date ASC, 
              b.received_date ASC, 
              b.id ASC
        ");
        $stmt->execute([$medicationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves all batches across the system with optional status filter.
     *
     * @param string|null $status
     * @return array
     */
    public static function getAllBatches(?string $status = null): array
    {
        $pdo = getDBConnection();
        $where = $status ? "WHERE b.status = :status" : "";
        $sql = "
            SELECT 
                b.*,
                m.name AS medication_name,
                m.med_code,
                m.dosage_form,
                s.name AS supplier_name,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry,
                ROUND(b.quantity_remaining * CASE WHEN b.unit_cost > 0 THEN b.unit_cost ELSE b.cost_price END, 2) AS batch_value
            FROM medicine_batches b
            JOIN medications m ON b.medication_id = m.id
            LEFT JOIN suppliers s ON b.supplier_id = s.id
            {$where}
            ORDER BY b.expiry_date ASC, b.received_date ASC, b.id ASC
        ";
        $stmt = $pdo->prepare($sql);
        if ($status) {
            $stmt->execute([':status' => $status]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves audit movements for a batch or transaction.
     *
     * @param int|null $batchId
     * @param string|null $refId
     * @return array
     */
    public static function getBatchMovements(?int $batchId = null, ?string $refId = null): array
    {
        $pdo = getDBConnection();
        $conditions = [];
        $params = [];

        if ($batchId) {
            $conditions[] = "m.batch_id = :bid";
            $params[':bid'] = $batchId;
        }
        if ($refId) {
            $conditions[] = "m.reference_transaction_id = :ref";
            $params[':ref'] = $refId;
        }

        $where = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";
        $sql = "
            SELECT 
                m.*,
                b.batch_number,
                med.name AS medication_name,
                u.full_name AS user_name
            FROM medicine_batch_movements m
            JOIN medicine_batches b ON m.batch_id = b.id
            JOIN medications med ON b.medication_id = med.id
            LEFT JOIN users u ON m.created_by = u.id
            {$where}
            ORDER BY m.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Seeds initial default pending prescriptions for realistic clinical simulation if empty.
     */
    public static function seedDefaultPrescriptionsIfEmpty(): void
    {
        $pdo = getDBConnection();
        try {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('prescriptions_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        } catch (Exception $e) {}
        return; // Strictly production mode: Never seed mock prescriptions.
    }

    private static function _legacySeedDefaultPrescriptions(): void
    {
        $pdo = getDBConnection();
        if (false) {

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
