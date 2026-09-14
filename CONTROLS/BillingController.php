<?php
/**
 * MedCore Systems - Billing & Payments Controller
 * Handles CSRF validation, authorization, invoice payment processing, and cashier checkout.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';

class BillingController
{
    /**
     * Handles processing an invoice payment.
     */
    public static function handleProcessPayment(array $post): ?array
    {
        initSecureSession();
        requireLogin();

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $cashierId = (int)($currentUser['id'] ?? 1);

        try {
            $invoiceId     = (int)($post['invoice_id'] ?? 0);
            $paidAmount    = (float)($post['paid_amount'] ?? 0);
            $paymentMethod = sanitizeString($post['payment_method'] ?? 'cash');
            $notes         = sanitizeString($post['notes'] ?? '');

            if ($invoiceId <= 0 || $paidAmount < 0) {
                return ['error' => 'Please provide a valid invoice and a non-negative payment amount.'];
            }

            $result = BillingOperation::processInvoicePayment(
                $invoiceId,
                $paidAmount,
                $paymentMethod,
                $notes,
                $cashierId
            );

            // Fetch updated invoice with details to prime paid queue token slip
            $invDetails = BillingOperation::getInvoiceById($invoiceId);
            if ($invDetails) {
                $billType = $invDetails['bill_type'] ?? 'general';
                $token = $invDetails['queue_token'] ?: ($invDetails['token_number'] ?: '');

                $receiptConfig = match ($billType) {
                    'lab' => [
                        'type'        => 'lab',
                        'title'       => 'LABORATORY INVESTIGATION & LAB PASS',
                        'header'      => 'Diagnostic Laboratory Department',
                        'badge'       => 'OFFICIAL LAB INVESTIGATION PASS',
                        'stamp'       => '✓ CLEARED FOR SPECIMEN COLLECTION & TESTING',
                        'notice'      => 'Warqaddan u gee qeybta Shaybaarka (Lab) si baaritaanka lagaaga qaado.',
                        'subnotice'   => 'Present this slip at Laboratory Unit for test analysis.',
                    ],
                    'pharmacy' => [
                        'type'        => 'pharmacy',
                        'title'       => 'DOCTOR PRESCRIPTION DISPENSING RECEIPT',
                        'header'      => 'Central Pharmacy & Dispensing Unit',
                        'badge'       => 'PRESCRIPTION DISPENSING RECEIPT',
                        'stamp'       => '✓ MEDICATIONS DISPENSED & VERIFIED',
                        'notice'      => 'Fadlan u qaado daawooyinka sida dhakhtarku kuu qoray.',
                        'subnotice'   => 'Take prescribed medications according to clinical dosage.',
                    ],
                    'walk_in' => [
                        'type'        => 'walk_in',
                        'title'       => 'DIRECT PHARMACY / OTC RETAIL SALE',
                        'header'      => 'Pharmacy Retail & OTC Counter',
                        'badge'       => 'DIRECT OTC RETAIL SALE',
                        'stamp'       => '✓ OTC PURCHASE VERIFIED',
                        'notice'      => 'Iib toos ah oo Farmashiye (Direct OTC Purchase).',
                        'subnotice'   => 'Thank you for your purchase.',
                    ],
                    default => [
                        'type'        => 'consultation',
                        'title'       => 'CONSULTATION INTAKE & QUEUE PASS',
                        'header'      => 'Front Desk Reception & Triage',
                        'badge'       => 'CLINICAL CONSULTATION & QUEUE PASS',
                        'stamp'       => '✓ CLEARED FOR DOCTOR CONSULTATION',
                        'notice'      => 'Fadlan fariiso qeybta sugitaanka inta lagaaga yeerayo lambarkaaga.',
                        'subnotice'   => 'Please wait in the clinic lobby until your token is called.',
                    ],
                };

                $_SESSION['hpms_print_paid_token'] = [
                    'bill_type'        => $billType,
                    'receipt_type'     => $receiptConfig['type'],
                    'receipt_title'    => $receiptConfig['title'],
                    'header_dept'      => $receiptConfig['header'],
                    'badge_text'       => $receiptConfig['badge'],
                    'clearance_stamp'  => $receiptConfig['stamp'],
                    'notice'           => $receiptConfig['notice'],
                    'subnotice'        => $receiptConfig['subnotice'],
                    'token'            => $token,
                    'name'             => $invDetails['customer_name'],
                    'mrn'              => $invDetails['mrn'] ?: 'N/A',
                    'phone'            => $invDetails['customer_phone'] ?: 'N/A',
                    'doctor'           => $invDetails['doctor_name'] ?: 'General OPD',
                    'department'       => $invDetails['department'] ?: 'General OPD',
                    'priority'         => ucfirst($invDetails['queue_priority'] ?? 'Normal'),
                    'items'            => $invDetails['items'] ?? [],
                    'subtotal'         => (float)$invDetails['subtotal'],
                    'discount'         => (float)$invDetails['discount'],
                    'net_total'        => (float)$invDetails['net_total'],
                    'paid_amount'      => (float)$paidAmount,
                    'due_amount'       => (float)$invDetails['due_amount'],
                    'payment_method'   => ((float)$paidAmount <= 0.001) ? 'DEBT / A/R 1100' : strtoupper($paymentMethod),
                    'invoice_number'   => $result['invoice_number'],
                    'payment_status'   => ((float)$invDetails['due_amount'] <= 0.001) 
                        ? 'PAID & VERIFIED' 
                        : (((float)$paidAmount <= 0.001) ? 'CLEARED ON CREDIT (DEBT)' : 'PARTIAL PAYMENT'),
                    'clearance_stamp'  => ((float)$invDetails['due_amount'] <= 0.001)
                        ? $receiptConfig['stamp']
                        : (((float)$paidAmount <= 0.001) ? '✓ CLEARED ON CREDIT (DEBT RECORDED)' : '✓ PARTIAL PAYMENT RECORDED'),
                    'notice'           => ((float)$paidAmount <= 0.001)
                        ? 'Biilkan waxaa loo fasaxay Deyn Bukaanka (A/R 1100). Fadlan fariiso qeybta sugitaanka.'
                        : $receiptConfig['notice'],
                    'subnotice'        => ((float)$paidAmount <= 0.001)
                        ? 'Cleared on Credit / Accounts Receivable 1100. Please proceed to clinical area.'
                        : $receiptConfig['subnotice'],
                    'date_time'        => date('M d, Y g:i A'),
                ];
            }

            if ((float)$paidAmount <= 0.001) {
                $successMsg = sprintf(
                    'Invoice #%s approved on 100%% Debt (Accounts Receivable 1100: $%.2f). Patient cleared for clinical service.',
                    $result['invoice_number'],
                    (float)$invDetails['due_amount']
                );
            } else {
                $successMsg = sprintf(
                    'Payment of $%.2f processed successfully for Invoice #%s.',
                    $result['amount_paid'],
                    $result['invoice_number']
                );
                if (!empty($invDetails['due_amount']) && (float)$invDetails['due_amount'] > 0.001) {
                    $successMsg .= sprintf(' Haraaga ($%.2f) waxaa loo diiwaangeliyay Deyn (Accounts Receivable 1100).', (float)$invDetails['due_amount']);
                }
            }
            $successMsg .= ' ' . ($_SESSION['hpms_print_paid_token']['receipt_title'] ?? 'Receipt') . ' is ready for printing.';
            setFlashMessage('success', $successMsg);

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : "billing_payments.php?invoice_id={$invoiceId}&paid=1";
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return $result;

        } catch (Exception $e) {
            error_log('[HPMS BILLING CONTROLLER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Handles cancelling / voiding an unpaid or pending billing invoice.
     *
     * @param array $post
     * @return array|null
     */
    public static function handleCancelInvoice(array $post): ?array
    {
        initSecureSession();
        requireLogin();
        requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_RECEPTION_CASHIER]);

        if (!verifyCsrfToken($post['csrf_token'] ?? null)) {
            return ['error' => 'Security token invalid or expired. Please refresh and try again.'];
        }

        $currentUser = getCurrentUser();
        $userId = (int)($currentUser['id'] ?? 1);

        try {
            $invoiceId = (int)($post['invoice_id'] ?? 0);
            $reasonPreset = sanitizeString($post['cancel_reason_preset'] ?? '');
            $customReason = sanitizeString($post['cancel_reason_custom'] ?? '');

            $finalReason = !empty($customReason) ? $customReason : ($reasonPreset ?: 'Patient abandoned visit / service cancelled');

            if ($invoiceId <= 0) {
                return ['error' => 'Please provide a valid invoice ID to cancel.'];
            }

            $inv = BillingOperation::getInvoiceById($invoiceId);
            if (!$inv) {
                return ['error' => "Invoice #{$invoiceId} not found."];
            }

            BillingOperation::cancelInvoice($invoiceId, $finalReason, $userId);

            setFlashMessage('success', sprintf('Invoice #%s has been successfully cancelled and removed from active billing queue.', $inv['invoice_number']));

            $redirectUrl = !empty($post['redirect']) ? $post['redirect'] : 'billing_payments.php';
            if (!defined('HPMS_TESTING')) {
                safeRedirect($redirectUrl);
            }
            return ['success' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $inv['invoice_number']];

        } catch (Exception $e) {
            error_log('[HPMS CANCEL INVOICE CONTROLLER ERROR] ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
