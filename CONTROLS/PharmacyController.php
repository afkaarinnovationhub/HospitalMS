<?php
/**
 * MedCore Systems - Pharmacy & POS Controller
 * Handles prescription dispensing, walk-in direct sales, customer discounts, and patient debt collections.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';

class PharmacyController
{
    /**
     * Handles prescription fulfillment & dispensing.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleDispense(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $prescriptionId  = (int)($post['prescription_id'] ?? 0);
            $dispenseQtys    = is_array($post['dispense_qty'] ?? null) ? $post['dispense_qty'] : [];
            $pharmacistNotes = sanitizeString($post['pharmacist_notes'] ?? '');
            $discountAmount  = (float)($post['discount_amount'] ?? 0);
            $creditApplied   = (float)($post['credit_applied'] ?? 0);
            $paidAmountInput = isset($post['paid_amount']) ? (float)$post['paid_amount'] : null;
            $paymentMethod   = $post['payment_method'] ?? 'cash';
            $customerPhone   = sanitizeString($post['customer_phone'] ?? '');

            if ($prescriptionId <= 0) {
                return ['error' => 'Invalid prescription specified for dispensing.'];
            }

            $saleId = PharmacyOperation::dispensePrescription(
                $prescriptionId,
                $dispenseQtys,
                $pharmacistNotes,
                $discountAmount,
                $paidAmountInput,
                $paymentMethod,
                $customerPhone,
                $userId,
                $creditApplied
            );

            // Fetch prescription and sale data to prime distinct receipt
            $rxData = PharmacyOperation::getPrescriptionById($prescriptionId);
            $saleData = ($saleId > 0) ? PharmacyOperation::getSaleById($saleId) : null;

            $_SESSION['hpms_pharmacy_receipt'] = [
                'type'             => 'prescription',
                'title'            => 'DOCTOR PRESCRIPTION DISPENSING RECEIPT',
                'header'           => 'Central Pharmacy & Dispensing Unit',
                'badge'            => 'DOCTOR PRESCRIPTION DISPENSED',
                'stamp'            => '✓ MEDICATIONS DISPENSED & VERIFIED',
                'token'            => $rxData['token_number'] ?? '',
                'name'             => $rxData['patient_name'] ?? ($saleData['customer_name'] ?? 'Prescription Patient'),
                'mrn'              => $rxData['mrn'] ?? 'N/A',
                'phone'            => $customerPhone ?: ($rxData['phone'] ?? 'N/A'),
                'doctor'           => $rxData['doctor_name'] ?? 'Attending Clinician',
                'department'       => 'Hospital Pharmacy',
                'items'            => $saleData['items'] ?? ($rxData['items'] ?? []),
                'subtotal'         => (float)($saleData['total_amount'] ?? ($rxData['subtotal'] ?? 0)),
                'discount'         => (float)$discountAmount,
                'credit_applied'   => (float)($saleData['credit_applied'] ?? $creditApplied),
                'net_total'        => (float)($saleData['net_amount'] ?? 0),
                'paid_amount'      => (float)($saleData['paid_amount'] ?? ($paidAmountInput ?? 0)),
                'due_amount'       => (float)($saleData['due_amount'] ?? 0),
                'payment_method'   => strtoupper($paymentMethod),
                'invoice_number'   => $saleData['invoice_number'] ?? ($rxData['rx_number'] ?? ($rxData['prescription_number'] ?? ('RX-' . $prescriptionId))),
                'notice'           => 'Fadlan u qaado daawooyinka sida dhakhtarku kuu qoray.',
                'subnotice'        => 'Take medications strictly according to doctor instructions.',
                'date_time'        => date('M d, Y g:i A'),
            ];

            setFlashMessage('success', 'Prescription processed successfully! Dispensed medication stock has been deducted.');
            if (!defined('HPMS_TESTING')) {
                safeRedirect('pharmacy_dispensing_prescription.php');
            }
            return ['sale_id' => $saleId];

        } catch (Exception $e) {
            error_log('[HPMS PHARMACY CTRL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles marking a prescription as External Purchase (Outsourced / Patient buying elsewhere).
     * Zero stock is deducted, zero charge billed to patient, and official prescription slip is primed for print.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleExternalPurchase(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_PHARMACY]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);
        $prescriptionId = (int)($post['prescription_id'] ?? 0);
        $notes = sanitizeString($post['pharmacist_notes'] ?? '');

        if ($prescriptionId <= 0) {
            return ['error' => 'Invalid prescription selected.'];
        }

        try {
            PharmacyOperation::markPrescriptionExternal($prescriptionId, $notes, $userId);

            // Fetch prescription data to prime official medical prescription slip
            $rxData = PharmacyOperation::getPrescriptionById($prescriptionId);

            $_SESSION['hpms_pharmacy_receipt'] = [
                'type'             => 'external_prescription',
                'title'            => 'OFFICIAL MEDICAL PRESCRIPTION (RIKHEETO DAWO)',
                'header'           => HOSPITAL_NAME . ' - Medical Prescription',
                'badge'            => 'EXTERNAL PHARMACY PURCHASE (BANNAANKA)',
                'stamp'            => '✓ AUTHORIZED MEDICAL PRESCRIPTION',
                'token'            => $rxData['rx_number'] ?? '',
                'name'             => $rxData['patient_name'] ?? 'Prescription Patient',
                'mrn'              => $rxData['patient_mrn'] ?? 'N/A',
                'phone'            => $rxData['patient_phone_dir'] ?? 'N/A',
                'doctor'           => $rxData['doctor_name'] ?? 'Attending Clinician',
                'department'       => 'Outpatient Pharmacy Desk',
                'items'            => $rxData['items'] ?? [],
                'subtotal'         => 0.00,
                'discount'         => 0.00,
                'credit_applied'   => 0.00,
                'net_total'        => 0.00,
                'paid_amount'      => 0.00,
                'due_amount'       => 0.00,
                'payment_method'   => 'EXTERNAL PURCHASE ($0.00)',
                'invoice_number'   => $rxData['rx_number'] ?? ('RX-' . $prescriptionId),
                'notice'           => 'Rikheetadani waxay ansax ku tahay farmashiye kasta oo dibadda ah.',
                'subnotice'        => 'This prescription is officially authorized for patient fulfillment at external pharmacies.',
                'date_time'        => date('M d, Y g:i A'),
                'is_external'      => true,
            ];

            setFlashMessage('success', "Daawada " . ($rxData['rx_number'] ?? '') . " waxaa loo calaamadeeyay in bukaanku bannaanka ka iibsanayo. Rikheetadii rasmiga ahayd waa la diyaariyay.");
            if (!defined('HPMS_TESTING')) {
                safeRedirect('pharmacy_dispensing_prescription.php');
            }
            return ['success' => true];

        } catch (Exception $e) {
            error_log('[HPMS PHARMACY EXTERNAL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles direct Walk-in (Over-The-Counter) POS sales.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleWalkInSale(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $patientId      = !empty($post['patient_id']) ? (int)$post['patient_id'] : null;
            $rawCustName    = trim((string)($post['customer_name'] ?? ''));
            if ($rawCustName === '' && !empty($post['patient_search_query'])) {
                $rawCustName = trim((string)$post['patient_search_query']);
            }
            $customerName   = sanitizeString($rawCustName !== '' ? $rawCustName : 'Walk-in Customer');
            $customerPhone  = sanitizeString(trim((string)($post['customer_phone'] ?? '')));
            $discountAmount = (float)($post['discount_amount'] ?? 0);
            $paidAmount     = (float)($post['paid_amount'] ?? 0);
            $paymentMethod  = $post['payment_method'] ?? 'cash';

            // Parse selected medication items
            $medicationIds = $post['medication_id'] ?? [];
            $quantities    = $post['quantity'] ?? [];

            $items = [];
            if (is_array($medicationIds)) {
                foreach ($medicationIds as $idx => $mId) {
                    $mId = (int)$mId;
                    $qty = (int)($quantities[$idx] ?? 0);
                    if ($mId > 0 && $qty > 0) {
                        $items[] = [
                            'medication_id' => $mId,
                            'quantity'      => $qty,
                        ];
                    }
                }
            }

            if (empty($items)) {
                return ['error' => 'Please select at least one medication and specify a valid quantity.'];
            }

            $saleId = PharmacyOperation::createWalkInSale([
                'patient_id'      => $patientId,
                'customer_name'   => $customerName,
                'customer_phone'  => $customerPhone,
                'discount_amount' => $discountAmount,
                'paid_amount'     => $paidAmount,
                'payment_method'  => $paymentMethod,
                'cashier_id'      => $userId,
            ], $items);

            $saleData = PharmacyOperation::getSaleById($saleId);

            $patientMrn = 'Walk-in Customer';
            $actualPatientId = (int)($saleData['patient_id'] ?? $patientId ?? 0);
            if ($actualPatientId > 0) {
                $stmtMrn = getDBConnection()->prepare("SELECT mrn, first_name, last_name, phone FROM patients WHERE id = ?");
                $stmtMrn->execute([$actualPatientId]);
                $foundPat = $stmtMrn->fetch(PDO::FETCH_ASSOC);
                if ($foundPat) {
                    $patientMrn = $foundPat['mrn'] ?? 'N/A';
                    if (empty($customerName) || $customerName === 'Walk-in Customer') {
                        $customerName = trim(($foundPat['first_name'] ?? '') . ' ' . ($foundPat['last_name'] ?? ''));
                    }
                    if (empty($customerPhone)) {
                        $customerPhone = $foundPat['phone'] ?? '';
                    }
                }
            }

            $_SESSION['hpms_pharmacy_receipt'] = [
                'type'             => 'walk_in',
                'title'            => 'DIRECT PHARMACY / OTC RETAIL SALE',
                'header'           => 'Pharmacy Retail & OTC Counter',
                'badge'            => 'DIRECT OTC RETAIL SALE',
                'stamp'            => '✓ OTC PURCHASE COMPLETED',
                'token'            => '',
                'name'             => $saleData['customer_name'] ?? $customerName,
                'mrn'              => $patientMrn,
                'phone'            => ($saleData['customer_phone'] ?? $customerPhone) ?: 'N/A',
                'doctor'           => 'N/A (Direct OTC)',
                'department'       => 'Pharmacy Retail',
                'items'            => $saleData['items'] ?? [],
                'subtotal'         => (float)($saleData['total_amount'] ?? 0),
                'discount'         => (float)$discountAmount,
                'net_total'        => (float)($saleData['net_amount'] ?? 0),
                'paid_amount'      => (float)$paidAmount,
                'due_amount'       => (float)($saleData['due_amount'] ?? 0),
                'payment_method'   => strtoupper($paymentMethod),
                'invoice_number'   => $saleData['invoice_number'] ?? ('POS-' . $saleId),
                'notice'           => 'Iib toos ah oo Farmashiye (Direct OTC Purchase).',
                'subnotice'        => 'Thank you for your purchase.',
                'date_time'        => date('M d, Y g:i A'),
            ];

            setFlashMessage('success', 'Walk-in sale processed successfully! Stock deducted and POS invoice generated.');
            if (!defined('HPMS_TESTING')) {
                safeRedirect('pharmacy_dispensing_prescription.php');
            }
            return ['sale_id' => $saleId];

        } catch (Exception $e) {
            error_log('[HPMS WALK-IN SALE CTRL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles collecting an installment payment toward an existing patient / customer debt.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCollectPatientDebt(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $saleId        = (int)($post['sale_id'] ?? 0);
            $amountPaid    = (float)($post['amount_paid'] ?? 0);
            $paymentMethod = $post['payment_method'] ?? 'cash';
            $notes         = sanitizeString($post['notes'] ?? '');

            if ($saleId <= 0 || $amountPaid <= 0) {
                return ['error' => 'Please provide a valid invoice ID and payment amount.'];
            }

            PharmacyOperation::collectPatientDebtPayment($saleId, $amountPaid, $paymentMethod, $notes, $userId);

            setFlashMessage('success', sprintf('Patient payment of $%.2f collected successfully! Balance updated.', $amountPaid));
            safeRedirect('pharmacy_dispensing_prescription.php');
            return null;

        } catch (Exception $e) {
            error_log('[HPMS PATIENT DEBT CTRL ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
