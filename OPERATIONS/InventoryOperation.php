<?php
/**
 * MedCore Systems - Inventory & Supplier Management Database Operations
 * Handles medications catalog, batch management, supplier purchases (cash/partial/credit),
 * stock auto-adjustments, and supplier debt repayment tracking using prepared statements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/AccountingOperation.php';

class InventoryOperation
{
    /**
     * Retrieves all medications with optional search and filtering.
     *
     * @param string $search
     * @param string $category
     * @param string $status
     * @return array
     */
    public static function getAllMedications(string $search = '', string $category = '', string $status = ''): array
    {
        $pdo = getDBConnection();
        $sql = "
            SELECT 
                m.*,
                (SELECT MIN(b.expiry_date) FROM medicine_batches b WHERE b.medication_id = m.id AND b.quantity_remaining > 0) as nearest_expiry,
                COALESCE(
                    (SELECT CASE WHEN b.unit_cost > 0 THEN b.unit_cost ELSE b.cost_price END 
                     FROM medicine_batches b 
                     WHERE b.medication_id = m.id AND b.quantity_remaining > 0 AND b.status = 'active'
                     ORDER BY CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END ASC, b.expiry_date ASC, b.received_date ASC, b.id ASC 
                     LIMIT 1),
                    m.cost_price
                ) as next_fifo_cost
            FROM medications m
            WHERE 1=1
        ";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (m.name LIKE :search OR m.med_code LIKE :search_code OR m.generic_name LIKE :search_gen)";
            $params[':search']      = '%' . $search . '%';
            $params[':search_code'] = '%' . $search . '%';
            $params[':search_gen']  = '%' . $search . '%';
        }

        if (!empty($category)) {
            $sql .= " AND m.category = :category";
            $params[':category'] = $category;
        }

        if ($status === 'in_stock' || $status === 'available') {
            $sql .= " AND m.current_stock > 0";
        } elseif ($status === 'low_stock') {
            $sql .= " AND m.current_stock > 0 AND m.current_stock <= m.min_stock_alert";
        } elseif ($status === 'out_of_stock') {
            $sql .= " AND m.current_stock <= 0";
        } elseif (!empty($status)) {
            $sql .= " AND m.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY m.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retrieves all medications available for dispensing / POS sale (current_stock > 0).
     *
     * @return array
     */
    public static function getMedicationsForSale(): array
    {
        $pdo = getDBConnection();
        return $pdo->query("
            SELECT m.*, 
                   (SELECT MIN(b.expiry_date) FROM medicine_batches b WHERE b.medication_id = m.id AND b.quantity_remaining > 0) as nearest_expiry
            FROM medications m 
            WHERE m.current_stock > 0 
            ORDER BY m.name ASC
        ")->fetchAll();
    }

    /**
     * Finds a single medication by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getMedicationById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM medications WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $med = $stmt->fetch();
        return $med ?: null;
    }

    /**
     * Finds a single medication by SKU / Med Code.
     *
     * @param string $medCode
     * @return array|null
     */
    public static function getMedicationByCode(string $medCode): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM medications WHERE med_code = :code LIMIT 1");
        $stmt->execute([':code' => trim($medCode)]);
        $med = $stmt->fetch();
        return $med ?: null;
    }

    /**
     * Creates a new medication item in the master catalog.
     *
     * @param array $data
     * @return int
     */
    public static function createMedication(array $data): int
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO medications (med_code, name, generic_name, category, dosage_form, unit_price, cost_price, min_stock_alert, current_stock, status)
            VALUES (:med_code, :name, :generic_name, :category, :dosage_form, :unit_price, :cost_price, :min_stock_alert, :current_stock, :status)
        ");

        $stock = (int)($data['current_stock'] ?? 0);
        $minAlert = (int)($data['min_stock_alert'] ?? 20);
        $status = ($stock <= 0) ? 'out_of_stock' : (($stock <= $minAlert) ? 'low_stock' : 'in_stock');

        $stmt->execute([
            ':med_code'        => trim($data['med_code']),
            ':name'            => trim($data['name']),
            ':generic_name'    => !empty($data['generic_name']) ? trim($data['generic_name']) : null,
            ':category'        => trim($data['category']),
            ':dosage_form'     => trim($data['dosage_form'] ?? 'Tablet'),
            ':unit_price'      => (float)($data['unit_price'] ?? 0),
            ':cost_price'      => (float)($data['cost_price'] ?? 0),
            ':min_stock_alert' => $minAlert,
            ':current_stock'   => $stock,
            ':status'          => $status,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves all suppliers.
     *
     * @return array
     */
    public static function getAllSuppliers(): array
    {
        $pdo = getDBConnection();
        return $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();
    }

    /**
     * Creates a new supplier.
     *
     * @param array $data
     * @return int
     */
    public static function createSupplier(array $data): int
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (name, contact_person, phone, email, address)
            VALUES (:name, :contact_person, :phone, :email, :address)
        ");
        $stmt->execute([
            ':name'           => trim($data['name']),
            ':contact_person' => !empty($data['contact_person']) ? trim($data['contact_person']) : null,
            ':phone'          => trim($data['phone']),
            ':email'          => !empty($data['email']) ? trim($data['email']) : null,
            ':address'        => !empty($data['address']) ? trim($data['address']) : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves a single supplier by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function getSupplierById(int $id): ?array
    {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch();
        return $supplier ?: null;
    }

    /**
     * Updates an existing supplier record.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateSupplier(int $id, array $data): bool
    {
        $pdo = getDBConnection();
        $name    = trim($data['name'] ?? '');
        $contact = !empty($data['contact_person']) ? trim($data['contact_person']) : null;
        $phone   = trim($data['phone'] ?? '');
        $email   = !empty($data['email']) ? trim($data['email']) : null;
        $address = !empty($data['address']) ? trim($data['address']) : null;

        if (empty($name) || empty($phone)) {
            throw new InvalidArgumentException('Supplier name and phone number are required.');
        }

        $stmt = $pdo->prepare("
            UPDATE suppliers
            SET name = :name,
                contact_person = :contact_person,
                phone = :phone,
                email = :email,
                address = :address
            WHERE id = :id
        ");

        return $stmt->execute([
            ':name'           => $name,
            ':contact_person' => $contact,
            ':phone'          => $phone,
            ':email'          => $email,
            ':address'        => $address,
            ':id'             => $id,
        ]);
    }

    /**
     * Deletes a supplier safely. If existing purchase orders are attached,
     * deletion is prevented to protect accounting records.
     *
     * @param int $id
     * @return bool
     */
    public static function deleteSupplier(int $id): bool
    {
        $pdo = getDBConnection();
        
        // Check for attached purchase orders
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ?");
        $stmtCheck->execute([$id]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            throw new RuntimeException('Cannot delete supplier because purchase order history and financial ledgers exist for this vendor.');
        }

        $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Retrieves suppliers with aggregated financial figures (Total Invoiced, Total Paid, Total Due, PO Count).
     *
     * @param string|null $search
     * @param string $statusFilter 'all' | 'in_debt' | 'settled'
     * @return array
     */
    public static function getSuppliersWithFinancials(?string $search = null, string $statusFilter = 'all'): array
    {
        $pdo = getDBConnection();

        $whereClauses = [];
        $params = [];

        if (!empty($search)) {
            $searchWild = '%' . trim($search) . '%';
            $whereClauses[] = "(s.name LIKE :s1 OR s.contact_person LIKE :s2 OR s.phone LIKE :s3 OR s.email LIKE :s4 OR s.address LIKE :s5)";
            $params[':s1'] = $searchWild;
            $params[':s2'] = $searchWild;
            $params[':s3'] = $searchWild;
            $params[':s4'] = $searchWild;
            $params[':s5'] = $searchWild;
        }

        $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "
            SELECT 
                s.id,
                s.name,
                s.contact_person,
                s.phone,
                s.email,
                s.address,
                s.created_at,
                COUNT(p.id) as purchase_count,
                COALESCE(SUM(p.net_amount), 0.00) as total_invoiced,
                COALESCE(SUM(p.paid_amount), 0.00) as total_paid,
                COALESCE(SUM(p.due_amount), 0.00) as total_due
            FROM suppliers s
            LEFT JOIN purchases p ON s.id = p.supplier_id
            {$whereSql}
            GROUP BY s.id, s.name, s.contact_person, s.phone, s.email, s.address, s.created_at
        ";

        if ($statusFilter === 'in_debt') {
            $sql .= " HAVING total_due > 0 ";
        } elseif ($statusFilter === 'settled') {
            $sql .= " HAVING total_due = 0 AND total_invoiced > 0 ";
        }

        $sql .= " ORDER BY s.name ASC ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retrieves summary KPIs for the Supplier Management dashboard.
     *
     * @return array
     */
    public static function getSupplierSummaryKPIs(): array
    {
        $pdo = getDBConnection();

        $totalSuppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
        
        $activeVendors = (int)$pdo->query("
            SELECT COUNT(DISTINCT supplier_id) 
            FROM purchases 
            WHERE supplier_id IS NOT NULL
        ")->fetchColumn();

        $totalPOs = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE supplier_id IS NOT NULL")->fetchColumn();

        $totalPayableDebt = (float)$pdo->query("
            SELECT COALESCE(SUM(due_amount), 0.00) 
            FROM purchases 
            WHERE due_amount > 0
        ")->fetchColumn();

        $totalDisbursed = (float)$pdo->query("
            SELECT COALESCE(SUM(amount_paid), 0.00) 
            FROM supplier_payments
        ")->fetchColumn();

        return [
            'total_suppliers'    => $totalSuppliers,
            'active_vendors'     => $activeVendors,
            'total_pos'          => $totalPOs,
            'total_payable_debt' => $totalPayableDebt,
            'total_disbursed'    => $totalDisbursed,
        ];
    }

    /**
     * Retrieves the complete transaction statement/ledger for a specific supplier.
     * Includes all purchase orders, medications supplied, and payment disbursements.
     *
     * @param int $supplierId
     * @return array|null
     */
    public static function getSupplierStatement(int $supplierId): ?array
    {
        $pdo = getDBConnection();
        $stmtSup = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
        $stmtSup->execute([$supplierId]);
        $supplier = $stmtSup->fetch();
        if (!$supplier) {
            return null;
        }

        // 1. Fetch Purchase Orders from this supplier
        $stmtPurchases = $pdo->prepare("
            SELECT p.*, mb.batch_number, mb.expiry_date, mb.quantity_received as batch_quantity, m.name as medication_name, m.med_code
            FROM purchases p
            LEFT JOIN medicine_batches mb ON mb.purchase_id = p.id
            LEFT JOIN medications m ON mb.medication_id = m.id
            WHERE p.supplier_id = ?
            ORDER BY p.purchase_date DESC, p.id DESC
        ");
        $stmtPurchases->execute([$supplierId]);
        $purchases = $stmtPurchases->fetchAll();

        // 2. Fetch Payment Disbursements for this supplier
        $stmtPayments = $pdo->prepare("
            SELECT sp.*, p.po_number, u.full_name as paid_by_name
            FROM supplier_payments sp
            JOIN purchases p ON sp.purchase_id = p.id
            LEFT JOIN users u ON sp.paid_by = u.id
            WHERE p.supplier_id = ?
            ORDER BY sp.paid_at DESC, sp.id DESC
        ");
        $stmtPayments->execute([$supplierId]);
        $payments = $stmtPayments->fetchAll();

        // Calculate summary
        $totalInvoiced = 0.0;
        $totalPaid = 0.0;
        $totalDue = 0.0;

        foreach ($purchases as $pur) {
            $totalInvoiced += (float)$pur['net_amount'];
            $totalPaid += (float)$pur['paid_amount'];
            $totalDue += (float)$pur['due_amount'];
        }

        return [
            'supplier'       => $supplier,
            'purchases'      => $purchases,
            'payments'       => $payments,
            'total_invoiced' => $totalInvoiced,
            'total_paid'     => $totalPaid,
            'total_due'      => $totalDue,
        ];
    }

    /**
     * Records a supplier purchase order / restock intake with full, partial, or credit payment.
     * Automatically increments medication inventory stock and creates batch tracking record.
     *
     * @param array $data
     * @return int Purchase ID
     */
    /**
     * Alias for recordPurchaseOrder
     */
    public static function createPurchase(array $data): int
    {
        return self::recordPurchaseOrder($data);
    }

    public static function recordPurchaseOrder(array $data): int
    {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $medicationId = (int)$data['medication_id'];
            $supplierId   = (int)$data['supplier_id'];
            $quantity     = (int)$data['quantity'];
            $costPrice    = (float)$data['cost_price'];
            $sellingPrice = (float)($data['unit_price'] ?? $data['selling_price'] ?? 0);
            $batchNumber  = trim($data['batch_number'] ?? ('BT-' . strtoupper(bin2hex(random_bytes(3)))));
            $expiryDate   = $data['expiry_date'] ?? date('Y-m-d', strtotime('+2 years'));
            $purchaseDate = $data['purchase_date'] ?? date('Y-m-d');
            $discount     = (float)($data['discount'] ?? 0);
            $paidAmount   = (float)($data['paid_amount'] ?? 0);
            $paymentMethod = $data['payment_method'] ?? 'cash';
            $notes        = $data['notes'] ?? null;
            $userId       = (int)($data['created_by'] ?? 1);

            $totalAmount = $quantity * $costPrice;
            $netAmount   = max(0.0, $totalAmount - $discount);
            $paidAmount  = min($netAmount, max(0.0, $paidAmount));
            $dueAmount   = max(0.0, $netAmount - $paidAmount);

            // Strict Balance validation if paying upfront
            if ($paidAmount > 0) {
                if (empty($paymentMethod) || !in_array($paymentMethod, ['cash', 'bank', 'mobile'], true)) {
                    throw new InvalidArgumentException('Please select a valid disbursement account (Cash, Mobile Money, or Bank) for the payment.');
                }

                $assetAccountCode = match ($paymentMethod) {
                    'mobile' => '1020',
                    'bank'   => '1030',
                    default  => '1010',
                };
                $accountNames = [
                    '1010' => 'Cash on Hand (1010)',
                    '1020' => 'Mobile Money EVC/Zaad (1020)',
                    '1030' => 'Bank Account (1030)',
                ];
                $accLabel = $accountNames[$assetAccountCode] ?? $assetAccountCode;

                $currentBal = AccountingOperation::getAccountBalanceByCode($assetAccountCode);
                if ($paidAmount > $currentBal) {
                    throw new InvalidArgumentException(sprintf(
                        'Insufficient funds in %s! Available balance: $%.2f, Required: $%.2f. Please choose another account or record purchase with $0.00 upfront payment (Deyn).',
                        $accLabel,
                        $currentBal,
                        $paidAmount
                    ));
                }
            }

            // Determine status
            if ($dueAmount <= 0.001) {
                $paymentStatus = 'paid';
            } elseif ($paidAmount > 0) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'credit';
            }

            // Generate PO Number
            $poNumber = 'PO-' . date('Y') . '-' . str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);

            // 1. Insert Purchase Record
            $stmtPurchase = $pdo->prepare("
                INSERT INTO purchases (supplier_id, po_number, total_amount, discount, net_amount, paid_amount, due_amount, payment_status, purchase_date, notes, created_by)
                VALUES (:supplier_id, :po_number, :total_amount, :discount, :net_amount, :paid_amount, :due_amount, :payment_status, :purchase_date, :notes, :created_by)
            ");
            $stmtPurchase->execute([
                ':supplier_id'    => $supplierId,
                ':po_number'      => $poNumber,
                ':total_amount'   => $totalAmount,
                ':discount'       => $discount,
                ':net_amount'     => $netAmount,
                ':paid_amount'    => $paidAmount,
                ':due_amount'     => $dueAmount,
                ':payment_status' => $paymentStatus,
                ':purchase_date'  => $purchaseDate,
                ':notes'          => $notes,
                ':created_by'     => $userId,
            ]);
            $purchaseId = (int)$pdo->lastInsertId();

            // 2. If upfront payment made, record in supplier_payments
            if ($paidAmount > 0) {
                $stmtPay = $pdo->prepare("
                    INSERT INTO supplier_payments (purchase_id, amount_paid, payment_method, notes, paid_by)
                    VALUES (:purchase_id, :amount_paid, :payment_method, :notes, :paid_by)
                ");
                $stmtPay->execute([
                    ':purchase_id'   => $purchaseId,
                    ':amount_paid'   => $paidAmount,
                    ':payment_method' => in_array($paymentMethod, ['cash', 'bank', 'mobile'], true) ? $paymentMethod : 'cash',
                    ':notes'         => 'Initial purchase payment',
                    ':paid_by'       => $userId,
                ]);
            }

            // 3. Post Balanced Double-Entry Journal to General Ledger FIRST to get journal_entry_id
            AccountingOperation::seedChartOfAccountsIfEmpty();
            $invAcc = AccountingOperation::getAccountByCode('1200'); // Pharmacy Inventory Asset
            $apAcc  = AccountingOperation::getAccountByCode('2010'); // Accounts Payable (Vendors)
            
            $assetAccountCode = match ($paymentMethod) {
                'mobile' => '1020', // Mobile Money
                'bank'   => '1030', // Bank Account
                default  => '1010', // Cash on Hand
            };
            $cashAcc = AccountingOperation::getAccountByCode($assetAccountCode);

            $journalEntryId = null;
            if ($invAcc && $netAmount > 0) {
                $journalItems = [];

                // Debit: Inventory Asset for total net purchased value (quantity × unit_cost)
                $journalItems[] = [
                    'account_id' => (int)$invAcc['id'],
                    'debit'      => $netAmount,
                    'credit'     => 0.00,
                    'memo'       => "Restock: {$quantity}x items [{$batchNumber}] (PO: {$poNumber})",
                ];

                // Credit: Cash/Mobile/Bank for upfront payment
                if ($paidAmount > 0 && $cashAcc) {
                    $journalItems[] = [
                        'account_id' => (int)$cashAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $paidAmount,
                        'memo'       => "Payment made via {$paymentMethod} for PO {$poNumber}",
                    ];
                }

                // Credit: Accounts Payable (Vendors) for remaining debt
                if ($dueAmount > 0 && $apAcc) {
                    $journalItems[] = [
                        'account_id' => (int)$apAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $dueAmount,
                        'memo'       => "Supplier debt recorded for PO {$poNumber}",
                    ];
                }

                $journalEntryId = AccountingOperation::recordJournalEntry(
                    $purchaseDate,
                    'supplier_restock',
                    $purchaseId,
                    "Medication Restock [{$poNumber}]: Net \${$netAmount} (Paid: \${$paidAmount}, Due: \${$dueAmount})",
                    $journalItems,
                    $userId
                );
            }

            // 4. Insert Batch Record (Linked to purchase journal entry)
            // Never overwrite or average into existing batches — each batch is independent
            $batchNumFinal = !empty($batchNumber) ? $batchNumber : ('BT-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -4)));
            $stmtBatch = $pdo->prepare("
                INSERT INTO medicine_batches (
                    medication_id, supplier_id, purchase_id, purchase_transaction_id, 
                    batch_number, quantity_received, quantity_remaining, unit_cost, cost_price, 
                    expiry_date, received_date, status
                )
                VALUES (
                    :medication_id, :supplier_id, :purchase_id, :purchase_transaction_id, 
                    :batch_number, :quantity_received, :quantity_remaining, :unit_cost, :cost_price, 
                    :expiry_date, :received_date, 'active'
                )
            ");
            $stmtBatch->execute([
                ':medication_id'           => $medicationId,
                ':supplier_id'             => $supplierId ?: null,
                ':purchase_id'             => $purchaseId,
                ':purchase_transaction_id' => $journalEntryId,
                ':batch_number'            => $batchNumFinal,
                ':quantity_received'       => $quantity,
                ':quantity_remaining'      => $quantity,
                ':unit_cost'               => $costPrice,
                ':cost_price'              => $costPrice,
                ':expiry_date'             => $expiryDate,
                ':received_date'           => $purchaseDate,
            ]);
            $batchId = (int)$pdo->lastInsertId();

            // Record Movement Audit Trail for this purchase
            $stmtMbm = $pdo->prepare("
                INSERT INTO medicine_batch_movements (
                    batch_id, movement_type, quantity, unit_cost, total_cost, reference_transaction_id, notes, created_by
                )
                VALUES (
                    :batch_id, 'purchase', :quantity, :unit_cost, :total_cost, :ref_tx, :notes, :created_by
                )
            ");
            $stmtMbm->execute([
                ':batch_id'    => $batchId,
                ':quantity'    => $quantity,
                ':unit_cost'   => $costPrice,
                ':total_cost'  => round($quantity * $costPrice, 2),
                ':ref_tx'      => $poNumber,
                ':notes'       => "Restock batch {$batchNumFinal} for PO {$poNumber}",
                ':created_by'  => $userId,
            ]);

            // 5. Update Medication Current Stock only (Never silently overwrite selling_price!)
            $stmtUpdateStock = $pdo->prepare("
                UPDATE medications 
                SET current_stock = current_stock + :qty
                WHERE id = :id
            ");
            $stmtUpdateStock->execute([
                ':qty' => $quantity,
                ':id'  => $medicationId,
            ]);

            // Only update medication-level selling_price if explicitly confirmed by staff
            $confirmPriceUpdate = !empty($data['confirm_price_update']);
            if ($confirmPriceUpdate && $sellingPrice > 0) {
                $stmtCurPrice = $pdo->prepare("SELECT unit_price FROM medications WHERE id = ?");
                $stmtCurPrice->execute([$medicationId]);
                $oldPrice = (float)$stmtCurPrice->fetchColumn();

                if (abs($sellingPrice - $oldPrice) > 0.001) {
                    $stmtUpdatePrice = $pdo->prepare("UPDATE medications SET unit_price = ? WHERE id = ?");
                    $stmtUpdatePrice->execute([$sellingPrice, $medicationId]);

                    self::logSellingPriceChange(
                        $medicationId,
                        $oldPrice,
                        $sellingPrice,
                        $userId,
                        "Updated during restock {$poNumber} (Staff explicitly confirmed update for all future sales)"
                    );
                }
            }

            $pdo->prepare("
                UPDATE medications 
                SET status = CASE 
                    WHEN current_stock <= 0 THEN 'out_of_stock'
                    WHEN current_stock <= min_stock_alert THEN 'low_stock'
                    ELSE 'in_stock'
                END
                WHERE id = ?
            ")->execute([$medicationId]);

            $pdo->commit();
            return $purchaseId;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS RESTOCK ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Records an installment payment toward an outstanding supplier debt.
     *
     * @param int $purchaseId
     * @param float $amountPaid
     * @param string $paymentMethod
     * @param string|null $notes
     * @param int $paidBy
     * @return bool
     */
    public static function recordSupplierPayment(int $purchaseId, float $amountPaid, string $paymentMethod, ?string $notes, int $paidBy): bool
    {
        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $purchaseId]);
            $purchase = $stmt->fetch();

            if (!$purchase) {
                throw new InvalidArgumentException('Purchase record not found.');
            }

            if ($purchase['due_amount'] <= 0) {
                throw new InvalidArgumentException('This purchase is already fully paid.');
            }

            $paymentAmount = min((float)$purchase['due_amount'], (float)$amountPaid);
            if ($paymentAmount <= 0) {
                throw new InvalidArgumentException('Payment amount must be greater than zero.');
            }

            if (empty($paymentMethod) || !in_array($paymentMethod, ['cash', 'bank', 'mobile'], true)) {
                throw new InvalidArgumentException('Please select a valid disbursement account (Cash, Mobile Money, or Bank) to settle this debt.');
            }

            $assetAccountCode = match ($paymentMethod) {
                'mobile' => '1020',
                'bank'   => '1030',
                default  => '1010',
            };
            $accountNames = [
                '1010' => 'Cash on Hand (1010)',
                '1020' => 'Mobile Money EVC/Zaad (1020)',
                '1030' => 'Bank Account (1030)',
            ];
            $accLabel = $accountNames[$assetAccountCode] ?? $assetAccountCode;

            $currentBal = AccountingOperation::getAccountBalanceByCode($assetAccountCode);
            if ($paymentAmount > $currentBal) {
                throw new InvalidArgumentException(sprintf(
                    'Insufficient funds in %s! Available balance: $%.2f, Required to pay: $%.2f. Please select another account with sufficient funds.',
                    $accLabel,
                    $currentBal,
                    $paymentAmount
                ));
            }

            // 1. Insert payment transaction
            $stmtPay = $pdo->prepare("
                INSERT INTO supplier_payments (purchase_id, amount_paid, payment_method, notes, paid_by)
                VALUES (:purchase_id, :amount_paid, :payment_method, :notes, :paid_by)
            ");
            $stmtPay->execute([
                ':purchase_id'   => $purchaseId,
                ':amount_paid'   => $paymentAmount,
                ':payment_method' => in_array($paymentMethod, ['cash', 'bank', 'mobile'], true) ? $paymentMethod : 'cash',
                ':notes'         => $notes ?: 'Supplier debt installment payment',
                ':paid_by'       => $paidBy,
            ]);

            // 2. Update purchase balance
            $newPaidAmount = (float)$purchase['paid_amount'] + $paymentAmount;
            $newDueAmount  = max(0.0, (float)$purchase['due_amount'] - $paymentAmount);
            $newStatus     = ($newDueAmount <= 0.001) ? 'paid' : 'partial';

            $stmtUpdate = $pdo->prepare("
                UPDATE purchases 
                SET paid_amount = :paid, due_amount = :due, payment_status = :status
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':paid'   => $newPaidAmount,
                ':due'    => $newDueAmount,
                ':status' => $newStatus,
                ':id'     => $purchaseId,
            ]);

            // 3. Post Double-Entry Journal for Supplier Debt Repayment
            AccountingOperation::seedChartOfAccountsIfEmpty();
            $apAcc = AccountingOperation::getAccountByCode('2010'); // Accounts Payable (Vendors)
            $assetAccountCode = match ($paymentMethod) {
                'mobile' => '1020', // Mobile Money
                'bank'   => '1030', // Bank Account
                default  => '1010', // Cash on Hand
            };
            $cashAcc = AccountingOperation::getAccountByCode($assetAccountCode);

            if ($apAcc && $cashAcc && $paymentAmount > 0) {
                $journalItems = [
                    // Debit: Accounts Payable (reduces supplier debt liability)
                    [
                        'account_id' => (int)$apAcc['id'],
                        'debit'      => $paymentAmount,
                        'credit'     => 0.00,
                        'memo'       => "Supplier debt clearance for PO {$purchase['po_number']}",
                    ],
                    // Credit: Cash/Mobile/Bank (reduces cash asset)
                    [
                        'account_id' => (int)$cashAcc['id'],
                        'debit'      => 0.00,
                        'credit'     => $paymentAmount,
                        'memo'       => "Paid via {$paymentMethod} for PO {$purchase['po_number']}",
                    ],
                ];

                AccountingOperation::recordJournalEntry(
                    date('Y-m-d'),
                    'supplier_payment',
                    $purchaseId,
                    "Supplier Debt Repayment [PO: {$purchase['po_number']}]: \${$paymentAmount} via {$paymentMethod}",
                    $journalItems,
                    $paidBy
                );
            }

            $pdo->commit();
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[HPMS SUPPLIER PAY ERROR] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieves all supplier purchase debts (where due_amount > 0).
     *
     * @return array
     */
    public static function getOutstandingSupplierDebts(): array
    {
        $pdo = getDBConnection();
        $sql = "
            SELECT 
                p.*,
                s.name as supplier_name,
                s.phone as supplier_phone
            FROM purchases p
            JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.due_amount > 0
            ORDER BY p.due_amount DESC, p.purchase_date ASC
        ";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Returns summary metric counts for the Inventory Dashboard.
     *
     * @return array
     */
    public static function getInventorySummaryKPIs(): array
    {
        $pdo = getDBConnection();

        $totalItems = (int)$pdo->query("SELECT COUNT(*) FROM medications")->fetchColumn();
        $lowStock   = (int)$pdo->query("SELECT COUNT(*) FROM medications WHERE current_stock <= min_stock_alert AND current_stock > 0")->fetchColumn();
        $outOfStock = (int)$pdo->query("SELECT COUNT(*) FROM medications WHERE current_stock <= 0")->fetchColumn();
        $expiring   = (int)$pdo->query("SELECT COUNT(DISTINCT medication_id) FROM medicine_batches WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity_remaining > 0")->fetchColumn();
        $totalSupplierDebt = (float)$pdo->query("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE due_amount > 0")->fetchColumn();

        return [
            'total_items'         => $totalItems,
            'low_stock'           => $lowStock,
            'out_of_stock'        => $outOfStock,
            'expiring_soon'       => $expiring,
            'total_supplier_debt' => $totalSupplierDebt,
        ];
    }

    /**
     * Acknowledges default inventory seed state without inserting mock medications.
     * Preserves a strictly user-managed medication catalog.
     */
    public static function seedDefaultInventoryIfEmpty(): void
    {
        $pdo = getDBConnection();
        try {
            $isSeeded = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'inventory_seeded'")->fetchColumn();
            if ($isSeeded === '1') {
                return; // Already acknowledged. Never reseed if user deleted records!
            }
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('inventory_seeded', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        } catch (Exception $e) {
            // Table might not exist in early migration
        }
    }

    /**
     * Logs an explicit selling price change for audit purposes.
     *
     * @param int $medicationId
     * @param float $oldPrice
     * @param float $newPrice
     * @param int|null $changedBy
     * @param string|null $reason
     * @return int Inserted log ID
     */
    public static function logSellingPriceChange(
        int $medicationId,
        float $oldPrice,
        float $newPrice,
        ?int $changedBy,
        ?string $reason = null
    ): int {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            INSERT INTO medication_price_logs (medication_id, old_price, new_price, changed_by, reason)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $medicationId,
            $oldPrice,
            $newPrice,
            $changedBy,
            $reason ?: 'Manual price update',
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Retrieves the selling price change audit trail for a medication.
     *
     * @param int|null $medicationId
     * @param int $limit
     * @return array
     */
    public static function getPriceChangeLogs(?int $medicationId = null, int $limit = 50): array
    {
        $pdo = getDBConnection();
        $where = $medicationId ? "WHERE mpl.medication_id = :mid" : "";
        $sql = "
            SELECT 
                mpl.*,
                m.name as medication_name,
                m.med_code,
                u.full_name as changed_by_name
            FROM medication_price_logs mpl
            JOIN medications m ON mpl.medication_id = m.id
            LEFT JOIN users u ON mpl.changed_by = u.id
            {$where}
            ORDER BY mpl.created_at DESC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        if ($medicationId) {
            $stmt->execute([':mid' => $medicationId]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Updates the medicine-level selling price (unit_price) independently from catalog
     * with mandatory audit logging.
     *
     * @param int $medicationId
     * @param float $newPrice
     * @param int|null $changedBy
     * @param string|null $reason
     * @return bool
     */
    public static function updateMedicationSellingPrice(
        int $medicationId,
        float $newPrice,
        ?int $changedBy,
        ?string $reason = null
    ): bool {
        if ($newPrice <= 0) {
            throw new InvalidArgumentException('Selling price must be greater than zero.');
        }

        $pdo = getDBConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT unit_price FROM medications WHERE id = ? FOR UPDATE");
            $stmt->execute([$medicationId]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                throw new InvalidArgumentException("Medication ID {$medicationId} not found.");
            }

            $oldPrice = (float)$current;
            if (abs($newPrice - $oldPrice) < 0.001) {
                $pdo->rollBack();
                return true;
            }

            $stmtUpdate = $pdo->prepare("UPDATE medications SET unit_price = ? WHERE id = ?");
            $stmtUpdate->execute([$newPrice, $medicationId]);

            self::logSellingPriceChange(
                $medicationId,
                $oldPrice,
                $newPrice,
                $changedBy,
                $reason ?: 'Direct catalog price update'
            );

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
