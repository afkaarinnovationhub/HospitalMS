<?php
/**
 * MedCore Systems - Inventory & Restock Controller
 * Handles medication additions, supplier purchases, restock intake, and supplier debt payments.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';

class InventoryController
{
    /**
     * Handles adding new stock or restocking from a supplier.
     *
     * @param array $post
     * @return array|null Returns error array if failed, otherwise performs safe redirect
     */
    public static function handleRestock(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        // 1. Verify CSRF
        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $medicationId = (int)($post['medication_id'] ?? 0);
            $supplierId   = (int)($post['supplier_id'] ?? 0);

            // Handle new medication if "new" selected
            if ($medicationId === 0 && !empty($post['new_med_name'])) {
                $medCode = trim($post['new_med_code'] ?? ('MED-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $post['new_med_name']), 0, 3)) . '-' . random_int(100, 999)));
                $medicationId = InventoryOperation::createMedication([
                    'med_code'        => $medCode,
                    'name'            => sanitizeString($post['new_med_name']),
                    'generic_name'    => sanitizeString($post['new_generic_name'] ?? ''),
                    'category'        => sanitizeString($post['category'] ?? 'General'),
                    'dosage_form'     => sanitizeString($post['dosage_form'] ?? 'Tablet'),
                    'unit_price'      => (float)($post['unit_price'] ?? 0),
                    'cost_price'      => (float)($post['cost_price'] ?? 0),
                    'min_stock_alert' => (int)($post['min_stock_alert'] ?? 20),
                    'current_stock'   => 0,
                ]);
            }

            // Handle new supplier if "new" selected
            if ($supplierId === 0 && !empty($post['new_supplier_name'])) {
                $supplierId = InventoryOperation::createSupplier([
                    'name'           => sanitizeString($post['new_supplier_name']),
                    'contact_person' => sanitizeString($post['supplier_contact'] ?? ''),
                    'phone'          => sanitizeString($post['supplier_phone'] ?? '(555) 000-0000'),
                    'email'          => sanitizeEmail($post['supplier_email'] ?? ''),
                ]);
            }

            if ($medicationId <= 0 || $supplierId <= 0) {
                return ['error' => 'Please select or provide valid medication and supplier details.'];
            }

            $quantity      = (int)($post['quantity'] ?? 0);
            $costPrice     = (float)($post['cost_price'] ?? 0);
            $unitPrice     = (float)($post['unit_price'] ?? 0);
            $discount      = (float)($post['discount'] ?? 0);
            $paidAmount    = (float)($post['paid_amount'] ?? 0);
            $paymentMethod = trim($post['payment_method'] ?? '');
            $batchNumber   = sanitizeString($post['batch_number'] ?? '');
            $expiryDate    = $post['expiry_date'] ?? date('Y-m-d', strtotime('+2 years'));
            $notes         = sanitizeString($post['notes'] ?? '');

            if ($quantity <= 0) {
                return ['error' => 'Quantity must be at least 1 unit.'];
            }

            if ($costPrice <= 0) {
                return ['error' => 'Cost price must be greater than zero.'];
            }

            if ($paidAmount > 0 && empty($paymentMethod)) {
                return ['error' => 'Please select a disbursement account (Cash, Mobile Money, or Bank) for the upfront payment.'];
            }

            $confirmPriceUpdate = !empty($post['confirm_price_update']) && ($post['confirm_price_update'] === '1' || $post['confirm_price_update'] === true || $post['confirm_price_update'] === 1);

            $purchaseId = InventoryOperation::recordPurchaseOrder([
                'medication_id'        => $medicationId,
                'supplier_id'          => $supplierId,
                'quantity'             => $quantity,
                'cost_price'           => $costPrice,
                'unit_price'           => $unitPrice,
                'confirm_price_update' => $confirmPriceUpdate,
                'discount'             => $discount,
                'paid_amount'          => $paidAmount,
                'payment_method'       => $paymentMethod ?: 'cash',
                'batch_number'         => $batchNumber,
                'expiry_date'          => $expiryDate,
                'notes'                => $notes,
                'created_by'           => $userId,
            ]);

            setFlashMessage('success', "Stock added successfully! ({$quantity} units received and added to inventory).");
            if (!defined('HPMS_TESTING')) {
                safeRedirect('inventory_management.php');
            }
            return ['purchase_id' => $purchaseId];

        } catch (Exception $e) {
            error_log('[HPMS INVENTORY CTRL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles paying an installment towards an outstanding supplier debt.
     *
     * @param array $post
     * @return array|null
     */
    public static function handlePaySupplierDebt(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $purchaseId    = (int)($post['purchase_id'] ?? ($post['transaction_id'] ?? 0));
            $amountPaid    = (float)($post['amount_paid'] ?? 0);
            $paymentMethod = trim($post['payment_method'] ?? '');
            $notes         = sanitizeString($post['notes'] ?? '');

            if ($purchaseId <= 0 || $amountPaid <= 0) {
                return ['error' => 'Please provide a valid purchase ID and payment amount.'];
            }

            if (empty($paymentMethod)) {
                return ['error' => 'Please select a disbursement account to settle this debt.'];
            }

            InventoryOperation::recordSupplierPayment($purchaseId, $amountPaid, $paymentMethod, $notes, $userId);

            setFlashMessage('success', sprintf('Payment of $%.2f to supplier recorded successfully!', $amountPaid));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'inventory_management.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS SUPPLIER DEBT CTRL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Alias for handlePaySupplierDebt.
     */
    public static function handleSupplierDebtPayment(array $post): ?array
    {
        return self::handlePaySupplierDebt($post);
    }

    /**
     * Handles standalone supplier registration.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCreateSupplier(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $name    = sanitizeString($post['name'] ?? '');
            $phone   = sanitizeString($post['phone'] ?? '');
            $contact = sanitizeString($post['contact_person'] ?? '');
            $email   = sanitizeEmail($post['email'] ?? '');
            $address = sanitizeString($post['address'] ?? '');

            if (empty($name) || empty($phone)) {
                return ['error' => 'Supplier name and phone number are required.'];
            }

            $supplierId = InventoryOperation::createSupplier([
                'name'           => $name,
                'contact_person' => $contact,
                'phone'          => $phone,
                'email'          => $email,
                'address'        => $address,
            ]);

            setFlashMessage('success', sprintf('Supplier "%s" registered successfully in database!', $name));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'suppliers.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['supplier_id' => $supplierId, 'name' => $name];

        } catch (Exception $e) {
            error_log('[HPMS CREATE SUPPLIER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles editing an existing supplier record.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleEditSupplier(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $id      = (int)($post['supplier_id'] ?? 0);
            $name    = sanitizeString($post['name'] ?? '');
            $phone   = sanitizeString($post['phone'] ?? '');
            $contact = sanitizeString($post['contact_person'] ?? '');
            $email   = sanitizeEmail($post['email'] ?? '');
            $address = sanitizeString($post['address'] ?? '');

            if ($id <= 0) {
                return ['error' => 'Invalid supplier ID provided.'];
            }

            if (empty($name) || empty($phone)) {
                return ['error' => 'Supplier company name and phone number are required.'];
            }

            InventoryOperation::updateSupplier($id, [
                'name'           => $name,
                'contact_person' => $contact,
                'phone'          => $phone,
                'email'          => $email,
                'address'        => $address,
            ]);

            setFlashMessage('success', sprintf('Supplier "%s" details updated successfully!', $name));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'suppliers.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS EDIT SUPPLIER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles safe deletion of a supplier.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDeleteSupplier(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        try {
            $id = (int)($post['supplier_id'] ?? 0);
            if ($id <= 0) {
                return ['error' => 'Invalid supplier ID specified.'];
            }

            $supplier = InventoryOperation::getSupplierById($id);
            $name = $supplier ? $supplier['name'] : 'Supplier';

            InventoryOperation::deleteSupplier($id);

            setFlashMessage('success', sprintf('Supplier "%s" was deleted successfully from the system.', $name));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'suppliers.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS DELETE SUPPLIER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles independent selling price update from medication catalog.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleUpdateSellingPrice(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $medicationId = (int)($post['medication_id'] ?? 0);
            $newPrice     = (float)($post['unit_price'] ?? 0);
            $reason       = sanitizeString($post['reason'] ?? 'Manual catalog price adjustment');

            if ($medicationId <= 0) {
                return ['error' => 'Invalid medication selected.'];
            }

            if ($newPrice <= 0) {
                return ['error' => 'Selling price must be greater than zero.'];
            }

            InventoryOperation::updateMedicationSellingPrice(
                $medicationId,
                $newPrice,
                $userId,
                $reason
            );

            setFlashMessage('success', sprintf('Medication selling price successfully updated to $%.2f.', $newPrice));
            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'inventory_management.php?view=summary';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS UPDATE PRICE ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}

