<?php
/**
 * MedCore Systems - Real Billing & Cashiering Hub
 * Comprehensive Outpatient & Pharmacy billing, cashier checkout, receipt issuance,
 * and automated general ledger accounting synchronization.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/BillingOperation.php';
require_once __DIR__ . '/../CONTROLS/BillingController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_RECEPTION_CASHIER, ROLE_PHARMACY]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

$printPaidTokenPayload = $_SESSION['hpms_print_paid_token'] ?? null;
unset($_SESSION['hpms_print_paid_token']);

// Handle Form Submissions (e.g. Cashier Payment or Cancellation)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'process_payment') {
        $result = BillingController::handleProcessPayment($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'cancel_invoice') {
        $result = BillingController::handleCancelInvoice($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Filter and search parameters
$typeFilter  = sanitizeString($_GET['type'] ?? 'all');
$searchQuery = sanitizeString($_GET['search'] ?? '');

$kpis = BillingOperation::getBillingSummaryKPIs();
$pendingQueue = BillingOperation::getPendingInvoicesQueue($typeFilter, $searchQuery);

// Determine active selected invoice
$selectedInvId = (int)($_GET['invoice_id'] ?? 0);
if ($selectedInvId <= 0 && !empty($pendingQueue)) {
    $selectedInvId = (int)$pendingQueue[0]['id'];
}

$activeInvoice = ($selectedInvId > 0) ? BillingOperation::getInvoiceById($selectedInvId) : null;
$paidHistory   = BillingOperation::getPaidInvoicesHistory(date('Y-m-01'), date('Y-m-d'), 10);

$pageTitle = 'Billing & Payments Hub - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Billing & Payments';
$activePage = 'billing';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Billing Alert</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Payment Settled</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>





    <!-- Main 2-Column Billing Workspace -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-6">
        <!-- LEFT COLUMN: Pending Invoices Queue (5 Cols) -->
        <div class="lg:col-span-5 bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col space-y-3">
            <div class="flex items-center justify-between border-b border-outline-variant pb-3">
                <div>
                    <h3 class="font-bold text-sm text-on-surface flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">queue</span>
                        Pending Bills Queue (<?php echo count($pendingQueue); ?>)
                    </h3>
                </div>
            </div>

            <!-- Search & Filter Tabs -->
            <form method="GET" action="billing_payments.php" class="space-y-2">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-2.5 top-2 text-[18px] text-on-surface-variant">search</span>
                    <input type="text" name="search" value="<?php echo e($searchQuery); ?>" placeholder="Search Name, MRN, Token, Invoice #..." class="w-full bg-surface-container-low border border-outline-variant rounded-xl pl-8 pr-3 py-1.5 text-xs text-on-surface outline-none focus:border-primary">
                </div>
                <div class="flex items-center gap-1 overflow-x-auto custom-scrollbar pb-1 text-xs">
                    <a href="billing_payments.php?type=all" class="px-2.5 py-1 rounded-lg <?php echo $typeFilter === 'all' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0">
                        All
                    </a>
                    <a href="billing_payments.php?type=consultation" class="px-2.5 py-1 rounded-lg <?php echo $typeFilter === 'consultation' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0">
                        Consultation
                    </a>
                    <a href="billing_payments.php?type=pharmacy" class="px-2.5 py-1 rounded-lg <?php echo $typeFilter === 'pharmacy' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0">
                        Pharmacy
                    </a>
                    <a href="billing_payments.php?type=lab" class="px-2.5 py-1 rounded-lg <?php echo $typeFilter === 'lab' ? 'bg-primary text-on-primary font-bold' : 'text-on-surface-variant hover:bg-surface-container'; ?> shrink-0">
                        Laboratory
                    </a>
                </div>
            </form>

            <!-- Invoices List -->
            <div id="billing-pending-queue-container" class="space-y-2 overflow-y-auto max-h-[550px] custom-scrollbar pr-1">
                <?php if (empty($pendingQueue)): ?>
                    <div class="py-12 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-[36px] text-on-surface-variant/40 block mb-1">done_all</span>
                        <p class="text-xs font-bold">No pending bills in queue!</p>
                        <p class="text-[11px] text-on-surface-variant mt-0.5">All patient charges have been collected.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendingQueue as $inv): ?>
                        <?php 
                            $isSelected = ($activeInvoice && (int)$activeInvoice['id'] === (int)$inv['id']);
                            $typeBadge = match ($inv['bill_type']) {
                                'consultation'      => 'bg-primary-fixed/40 text-primary border-primary/30',
                                'pharmacy'          => 'bg-purple-500/20 text-purple-700 border-purple-300 dark:border-purple-800',
                                'lab', 'laboratory' => 'bg-secondary-fixed/50 text-secondary border-secondary/40',
                                default             => 'bg-surface-container text-on-surface border-outline-variant',
                            };
                        ?>
                        <a href="billing_payments.php?invoice_id=<?php echo (int)$inv['id']; ?>&type=<?php echo e($typeFilter); ?>" 
                           class="block p-3 rounded-xl border transition-all cursor-pointer <?php echo $isSelected ? 'bg-primary/10 border-primary shadow-xs' : 'bg-surface-container-lowest border-outline-variant hover:bg-surface-container-low'; ?>">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="font-mono font-bold text-xs text-primary"><?php echo e($inv['invoice_number']); ?></span>
                                        <?php if (!empty($inv['token_number'])): ?>
                                            <span class="bg-amber-500/20 text-amber-800 dark:text-amber-300 font-mono font-bold text-[10px] px-1.5 py-0.5 rounded">
                                                <?php echo e($inv['token_number']); ?>
                                            </span>
                                        <?php endif; ?>

                                    </div>
                                    <h4 class="font-bold text-xs text-on-surface mt-0.5"><?php echo e($inv['customer_name']); ?></h4>
                                    <p class="text-[10px] text-on-surface-variant"><?php echo e($inv['mrn'] ?: 'Outpatient'); ?> • <?php echo date('g:i A', strtotime($inv['created_at'])); ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="font-mono font-bold text-sm text-error block">$<?php echo number_format((float)$inv['due_amount'], 2); ?></span>
                                    <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded-full border <?php echo $typeBadge; ?>">
                                        <?php echo e($inv['bill_type']); ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Active Invoice Details & Cashier Payment Form (7 Cols) -->
        <div class="lg:col-span-7 space-y-4">
            <?php if (!$activeInvoice): ?>
                <div class="bg-surface border border-outline-variant rounded-2xl p-12 text-center shadow-xs">
                    <span class="material-symbols-outlined text-[48px] text-on-surface-variant/30 block mb-2">receipt</span>
                    <h3 class="font-bold text-sm text-on-surface">No Invoice Selected</h3>
                    <p class="text-xs text-on-surface-variant mt-1">Select an invoice from the queue or create a new check-in.</p>
                </div>
            <?php else: ?>
                <!-- Invoice Header Card -->
                <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-outline-variant pb-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-base text-primary"><?php echo e($activeInvoice['invoice_number']); ?></span>
                                <span class="text-xs px-2.5 py-0.5 rounded-full font-bold uppercase <?php echo $activeInvoice['payment_status'] === 'paid' ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container'; ?>">
                                    <?php echo e($activeInvoice['payment_status']); ?>
                                </span>
                            </div>
                            <p class="text-xs text-on-surface-variant mt-0.5">
                                Issued: <strong class="text-on-surface"><?php echo date('M d, Y g:i A', strtotime($activeInvoice['created_at'])); ?></strong>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if (!empty($activeInvoice['queue_token']) || !empty($activeInvoice['token_number'])): ?>
                                <button type="button" 
                                        onclick="printPaidQueueTokenTicket(<?php echo htmlspecialchars(json_encode([
                                            'token'            => $activeInvoice['queue_token'] ?: $activeInvoice['token_number'],
                                            'name'             => $activeInvoice['customer_name'],
                                            'mrn'              => $activeInvoice['mrn'] ?: 'N/A',
                                            'phone'            => $activeInvoice['customer_phone'] ?: 'N/A',
                                            'doctor'           => $activeInvoice['doctor_name'] ?: 'General OPD',
                                            'department'       => $activeInvoice['department'] ?: 'General OPD',
                                            'priority'         => ucfirst($activeInvoice['queue_priority'] ?? 'Normal'),
                                            'subtotal'         => (float)$activeInvoice['subtotal'],
                                            'net_total'        => (float)$activeInvoice['net_total'],
                                            'paid_amount'      => (float)$activeInvoice['paid_amount'],
                                            'due_amount'       => (float)$activeInvoice['due_amount'],
                                            'payment_method'   => ((float)$activeInvoice['paid_amount'] <= 0.001) ? 'DEBT / A/R 1100' : strtoupper($activeInvoice['payment_method'] ?: 'CASH'),
                                            'invoice_number'   => $activeInvoice['invoice_number'],
                                            'payment_status'   => ((float)$activeInvoice['due_amount'] <= 0.001 && (float)$activeInvoice['paid_amount'] > 0) ? 'PAID & VERIFIED' : (((float)$activeInvoice['paid_amount'] > 0) ? 'PARTIAL PAYMENT' : 'CLEARED ON CREDIT (DEBT)'),
                                            'clearance_stamp'  => ((float)$activeInvoice['due_amount'] <= 0.001 && (float)$activeInvoice['paid_amount'] > 0) ? '✓ PAID IN FULL & VERIFIED' : (((float)$activeInvoice['paid_amount'] > 0) ? '✓ PARTIAL PAYMENT RECORDED' : '✓ CLEARED ON CREDIT (DEBT RECORDED)'),
                                            'notice'           => ((float)$activeInvoice['paid_amount'] <= 0.001) ? 'Biilkan waxaa loo fasaxay Deyn Bukaanka (A/R 1100). Fadlan fariiso qeybta sugitaanka.' : 'Lacagta waa la xaqiijiyay. Fadlan fariiso qeybta sugitaanka.',
                                            'subnotice'        => ((float)$activeInvoice['paid_amount'] <= 0.001) ? 'Cleared on Credit / Accounts Receivable 1100. Please proceed to clinical area.' : 'Payment verified. Please proceed to service area.',
                                            'date_time'        => date('M d, Y g:i A', strtotime($activeInvoice['paid_at'] ?: $activeInvoice['created_at'])),
                                        ]), ENT_QUOTES, 'UTF-8'); ?>)" 
                                        class="px-3 py-1.5 bg-primary text-on-primary hover:bg-primary-container rounded-xl text-xs font-bold flex items-center gap-1 transition-colors cursor-pointer shadow-xs">
                                    <span class="material-symbols-outlined text-[16px]">confirmation_number</span>
                                    Print Queue Slip
                                </button>
                            <?php endif; ?>
                            <button type="button" onclick="openReceiptModal()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-xl text-xs font-bold flex items-center gap-1 transition-colors cursor-pointer">
                                <span class="material-symbols-outlined text-[16px]">print</span>
                                Print Receipt
                            </button>
                        </div>
                    </div>

                    <!-- Patient & Encounter Snapshot -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-surface-container-lowest rounded-xl border border-outline-variant text-xs">
                        <div>
                            <span class="text-on-surface-variant text-[11px]">Patient Name:</span>
                            <p class="font-bold text-on-surface"><?php echo e($activeInvoice['customer_name']); ?></p>
                            <span class="text-[10px] text-on-surface-variant font-mono"><?php echo e($activeInvoice['mrn'] ?: 'Walk-in / Outpatient'); ?></span>
                        </div>
                        <div>
                            <span class="text-on-surface-variant text-[11px]">Queue Token &amp; Room:</span>
                            <p class="font-bold text-primary font-mono"><?php echo e($activeInvoice['queue_token'] ?: ($activeInvoice['token_number'] ?: 'Direct Bill')); ?></p>
                            <span class="text-[10px] text-on-surface-variant"><?php echo e($activeInvoice['department'] ?? 'Clinical OPD'); ?></span>
                        </div>
                        <div>
                            <span class="text-on-surface-variant text-[11px]">Assigned Doctor:</span>
                            <p class="font-bold text-on-surface"><?php echo e($activeInvoice['doctor_name'] ?? 'Attending Clinician'); ?></p>
                            <span class="text-[10px] text-secondary font-semibold">Consultation Unit</span>
                        </div>
                    </div>



                    <!-- Itemized Charges Table -->
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-2 px-3">Item Description</th>
                                    <th class="py-2 px-3 text-right">Qty</th>
                                    <th class="py-2 px-3 text-right">Unit Price</th>
                                    <th class="py-2 px-3 text-right">Total ($)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/50">
                                <?php if (empty($activeInvoice['items'])): ?>
                                    <tr>
                                        <td colspan="4" class="py-4 text-center text-on-surface-variant">
                                            [<?php echo e($activeInvoice['bill_type']); ?>] General charge
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($activeInvoice['items'] as $item): ?>
                                        <tr class="hover:bg-surface-container-lowest">
                                            <td class="py-2.5 px-3">
                                                <span class="font-bold text-on-surface block"><?php echo e($item['item_name']); ?></span>
                                                <?php if (!empty($item['item_description'])): ?>
                                                    <span class="text-[11px] text-on-surface-variant"><?php echo e($item['item_description']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2.5 px-3 text-right font-mono"><?php echo (int)$item['quantity']; ?></td>
                                            <td class="py-2.5 px-3 text-right font-mono">$<?php echo number_format((float)$item['unit_price'], 2); ?></td>
                                            <td class="py-2.5 px-3 text-right font-mono font-bold text-on-surface">$<?php echo number_format((float)$item['total_price'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary & Net Calculation -->
                    <div class="p-3.5 bg-surface-container-low rounded-xl border border-outline-variant space-y-1.5 text-xs font-semibold">
                        <div class="flex justify-between text-on-surface-variant">
                            <span>Gross Subtotal:</span>
                            <span class="font-mono text-on-surface">$<?php echo number_format((float)$activeInvoice['subtotal'], 2); ?></span>
                        </div>
                        <?php if ((float)$activeInvoice['discount'] > 0): ?>
                            <div class="flex justify-between text-secondary">
                                <span>Discount Applied:</span>
                                <span class="font-mono">-$<?php echo number_format((float)$activeInvoice['discount'], 2); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex justify-between text-on-surface-variant">
                            <span>Tax (0% Medical Exemption):</span>
                            <span class="font-mono text-on-surface">$0.00</span>
                        </div>
                        <div class="pt-1.5 border-t border-outline-variant flex justify-between font-bold text-sm text-on-surface">
                            <span>Total Bill:</span>
                            <span class="font-mono text-primary">$<?php echo number_format((float)$activeInvoice['net_total'], 2); ?></span>
                        </div>
                        <div class="flex justify-between text-secondary">
                            <span>Amount Paid:</span>
                            <span class="font-mono">+$<?php echo number_format((float)$activeInvoice['paid_amount'], 2); ?></span>
                        </div>
                        <div class="pt-1 border-t border-outline-variant flex justify-between font-bold text-sm text-error">
                            <span>Remaining Balance Due:</span>
                            <span class="font-mono text-base">$<?php echo number_format((float)$activeInvoice['due_amount'], 2); ?></span>
                        </div>
                    </div>

                    <!-- Cashier Payment Form (Only active if due_amount > 0) -->
                    <?php if ((float)$activeInvoice['due_amount'] > 0): ?>
                        <form method="POST" action="billing_payments.php" class="p-4 bg-surface-container rounded-2xl border border-primary/30 space-y-3">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="process_payment">
                            <input type="hidden" name="invoice_id" value="<?php echo (int)$activeInvoice['id']; ?>">
                            <input type="hidden" name="redirect" value="billing_payments.php?invoice_id=<?php echo (int)$activeInvoice['id']; ?>">

                            <div class="flex items-center justify-between">
                                <h4 class="font-bold text-xs text-on-surface flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-primary text-[18px]">point_of_sale</span>
                                    Cashier Checkout &amp; Settle
                                </h4>
                                <button type="button" onclick="setFullPayment(<?php echo (float)$activeInvoice['due_amount']; ?>)" class="text-[11px] text-primary font-bold hover:underline cursor-pointer">
                                    Pay Full Amount ($<?php echo number_format((float)$activeInvoice['due_amount'], 2); ?>)
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Amount to Pay ($ USD) *</label>
                                    <input name="paid_amount" id="checkout-amount-input" type="number" step="0.01" min="0.00" max="<?php echo (float)$activeInvoice['due_amount']; ?>" value="<?php echo (float)$activeInvoice['due_amount']; ?>" required class="w-full bg-surface border border-outline-variant rounded-lg p-2 text-sm font-mono font-bold text-on-surface focus:border-primary outline-none">
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Payment Method *</label>
                                    <select name="payment_method" id="checkout-payment-method" required class="w-full bg-surface border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
                                        <option value="cash">Cash on Hand (Khasnadda)</option>
                                        <option value="mobile">Mobile Money (EVC Plus / E-Dahab)</option>
                                        <option value="bank">Bank Account (Commercial Banks)</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Cashier Notes / Reference</label>
                                <input name="notes" type="text" placeholder="e.g. Received via Zaad Ref #8821" class="w-full bg-surface border border-outline-variant rounded-lg p-1.5 text-xs text-on-surface focus:border-primary outline-none">
                            </div>

                            <div class="flex flex-col sm:flex-row items-center gap-2 pt-1">
                                <button type="button" onclick="openCancelInvoiceModal()" class="w-full sm:w-auto px-4 py-2.5 bg-error-container hover:bg-error/20 text-on-error-container font-bold rounded-xl text-xs flex items-center justify-center gap-1.5 border border-error/30 transition-all cursor-pointer shadow-2xs">
                                    <span class="material-symbols-outlined text-[18px] text-error">cancel</span>
                                    Cancel Bill
                                </button>
                                <button type="submit" class="w-full sm:flex-1 py-2.5 bg-primary hover:bg-primary-container text-on-primary font-bold rounded-xl text-xs flex items-center justify-center gap-1.5 shadow-xs transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-[18px]">check_circle</span>
                                    Confirm Payment &amp; Post to Accounts
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="p-3 bg-secondary-fixed/30 rounded-xl border border-secondary/30 text-center text-xs font-bold text-on-secondary-fixed flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[20px] text-secondary">verified</span>
                            This invoice is fully settled and posted to the General Ledger.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>


</main>

<!-- MODAL: Printable Official Hospital Receipt -->
<?php if ($activeInvoice): ?>
<div id="receipt-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant mb-4">
            <span class="text-xs font-bold text-on-surface">Official Medical Receipt</span>
            <button type="button" onclick="closeReceiptModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Receipt Sheet Content (Print Target) -->
        <div id="printable-receipt" class="space-y-4 text-xs">
            <div class="text-center border-b border-outline-variant pb-3">
                <h3 class="font-headline-md font-bold text-base text-primary"><?php echo e(HOSPITAL_NAME); ?></h3>
                <p class="text-[11px] text-on-surface-variant"><?php echo e(defined('HOSPITAL_TAGLINE') ? HOSPITAL_TAGLINE : 'Outpatient & Clinical Services'); ?></p>
                <p class="text-[10px] text-on-surface-variant font-mono"><?php echo e(HOSPITAL_PHONE); ?> &bull; <?php echo e(HOSPITAL_ADDRESS); ?></p>
                <p class="text-[10px] text-on-surface-variant font-mono mt-1">Receipt #: <?php echo e($activeInvoice['invoice_number']); ?></p>
                <p class="text-[10px] text-on-surface-variant">Date: <?php echo date('M d, Y g:i A'); ?></p>
            </div>

            <div class="space-y-1 text-xs">
                <div class="flex justify-between"><span class="text-on-surface-variant">Patient:</span> <strong class="text-on-surface"><?php echo e($activeInvoice['customer_name']); ?></strong></div>
                <div class="flex justify-between"><span class="text-on-surface-variant">MRN:</span> <span class="font-mono"><?php echo e($activeInvoice['mrn'] ?: 'N/A'); ?></span></div>
                <?php if (!empty($activeInvoice['token_number'])): ?>
                    <div class="flex justify-between"><span class="text-on-surface-variant">Queue Token:</span> <span class="font-mono font-bold text-primary"><?php echo e($activeInvoice['token_number']); ?></span></div>
                <?php endif; ?>
                <div class="flex justify-between"><span class="text-on-surface-variant">Doctor:</span> <span><?php echo e($activeInvoice['doctor_name'] ?? 'General OPD'); ?></span></div>
            </div>

            <div class="border-t border-b border-outline-variant py-2 space-y-1">
                <?php foreach ($activeInvoice['items'] as $it): ?>
                    <div class="flex justify-between text-xs">
                        <span><?php echo e($it['item_name']); ?> (x<?php echo (int)$it['quantity']; ?>)</span>
                        <span class="font-mono font-bold">$<?php echo number_format((float)$it['total_price'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="space-y-1 font-bold text-xs">
                <div class="flex justify-between"><span>Subtotal:</span> <span class="font-mono">$<?php echo number_format((float)$activeInvoice['subtotal'], 2); ?></span></div>
                <div class="flex justify-between text-secondary"><span>Amount Paid:</span> <span class="font-mono">$<?php echo number_format((float)$activeInvoice['paid_amount'], 2); ?></span></div>
                <div class="flex justify-between text-error"><span>Balance Due:</span> <span class="font-mono">$<?php echo number_format((float)$activeInvoice['due_amount'], 2); ?></span></div>
            </div>

            <div class="text-center pt-3 border-t border-outline-variant text-[10px] text-on-surface-variant">
                <p>Thank you for choosing <?php echo e(HOSPITAL_NAME); ?>.</p>
                <p class="font-mono mt-0.5">Payment Verified &amp; Ledger Synchronized</p>
            </div>
        </div>

        <div class="pt-4 border-t border-outline-variant flex justify-end gap-2">
            <button type="button" onclick="closeReceiptModal()" class="px-3 py-1.5 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
            <button type="button" onclick="window.print()" class="px-4 py-1.5 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    function setFullPayment(amt) {
        document.getElementById('checkout-amount-input').value = amt.toFixed(2);
    }
    function openReceiptModal() {
        const m = document.getElementById('receipt-modal');
        if (m) m.classList.remove('hidden');
    }
    function closeReceiptModal() {
        const m = document.getElementById('receipt-modal');
        if (m) m.classList.add('hidden');
    }
</script>

<!-- MODAL: Printable Paid Queue Token / Lab Pass / Pharmacy Receipt -->
<div id="print-paid-token-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 class="font-headline-sm text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-secondary text-[20px]">check_circle</span>
                <span id="ppt-modal-title">Payment Verified &amp; Pass</span>
            </h3>
            <button type="button" onclick="closePaidTokenModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Slip Card -->
        <div id="printable-paid-token-slip" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono text-center space-y-2 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2">
                <h4 class="font-bold text-base tracking-wide uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p id="ppt-header-dept" class="text-[10px] text-gray-600">Main Outpatient Clinic • Cashier &amp; Triage</p>
                <p class="text-[9px] text-gray-500">Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?> • <?php echo htmlspecialchars(HOSPITAL_ADDRESS); ?></p>
            </div>

            <!-- Receipt Category Badge -->
            <div class="pt-1">
                <span id="ppt-badge-text" class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-gray-100 border border-gray-300 rounded inline-block text-gray-800">
                    CLINICAL CONSULTATION PASS
                </span>
            </div>

            <!-- Big Token if present -->
            <div id="ppt-token-container" class="py-1">
                <span class="text-[9px] uppercase font-bold text-gray-500 tracking-wider">Queue Token #</span>
                <div id="ppt-token" class="text-3xl font-extrabold tracking-wider my-0.5 text-black">T-001</div>
            </div>

            <!-- Clearance Stamp -->
            <div class="inline-block bg-green-100 text-green-800 text-[10px] px-2 py-0.5 rounded font-bold border border-green-300">
                <span id="ppt-status">PAID &amp; VERIFIED</span>
            </div>

            <div class="border-t border-b border-dashed border-gray-300 py-2 text-left text-[11px] space-y-1">
                <div class="flex justify-between"><span class="text-gray-500">Patient:</span> <span id="ppt-name" class="font-bold">Ahmed Warsame</span></div>
                <div class="flex justify-between"><span class="text-gray-500">MRN:</span> <span id="ppt-mrn" class="font-bold">MRN-2026-0042</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Phone:</span> <span id="ppt-phone" class="font-bold">(555) 012-9900</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Doctor:</span> <span id="ppt-doctor" class="font-bold">Dr. Michael Chen</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Dept:</span> <span id="ppt-dept" class="font-bold">General OPD</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Invoice #:</span> <span id="ppt-inv" class="font-bold">INV-2026-0012</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Date/Time:</span> <span id="ppt-time" class="font-bold">Aug 31, 2026 12:30 PM</span></div>
            </div>

            <!-- Itemized list if any (e.g. Lab tests or Prescriptions) -->
            <div id="ppt-items-container" class="border-b border-dashed border-gray-300 pb-2 text-left text-[10px] space-y-1 hidden">
                <div class="font-bold text-gray-700 uppercase text-[9px] border-b border-gray-200 pb-0.5 mb-1">Itemized Services / Tests / Rx:</div>
                <div id="ppt-items-list" class="space-y-0.5"></div>
            </div>

            <div class="border-b border-dashed border-gray-300 pb-2 text-[11px] font-bold space-y-0.5 text-left">
                <div class="flex justify-between"><span class="text-gray-600">Total Bill:</span> <span id="ppt-total" class="font-mono">$0.00</span></div>
                <div id="ppt-paid-row" class="flex justify-between text-green-700"><span>Paid Now:</span> <span id="ppt-paid" class="font-mono">$0.00</span></div>
                <div id="ppt-due-row" class="flex justify-between text-red-600 hidden"><span>Balance Due:</span> <span id="ppt-due" class="font-mono">$0.00</span></div>
            </div>

            <div class="pt-1 text-[9px] text-gray-600 leading-tight">
                <p id="ppt-notice" class="font-semibold text-green-900">Lacagta waa la xaqiijiyay. Fadlan fariiso qeybta sugitaanka.</p>
                <p id="ppt-subnotice" class="mt-0.5">Payment verified. Please proceed to service area.</p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <button type="button" onclick="closePaidTokenModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Close</button>
            <button type="button" onclick="executePrintPaidToken()" class="px-4 py-1.5 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1 cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Official Slip
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Cancel / Void Invoice -->
<?php if ($activeInvoice): ?>
<div id="cancel-invoice-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <div class="p-2 rounded-xl bg-error-container text-error flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">cancel</span>
                </div>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Cancel Billing Invoice</h3>
                    <p class="text-xs text-on-surface-variant font-mono font-bold text-error"><?php echo e($activeInvoice['invoice_number']); ?></p>
                </div>
            </div>
            <button type="button" onclick="closeCancelInvoiceModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <div class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant text-xs space-y-1">
            <p class="text-on-surface"><strong>Patient:</strong> <?php echo e($activeInvoice['customer_name']); ?></p>
            <p class="text-on-surface"><strong>Bill Type:</strong> <span class="uppercase font-bold text-primary"><?php echo e($activeInvoice['bill_type']); ?></span></p>
            <p class="text-on-surface"><strong>Outstanding Due:</strong> <span class="font-mono font-bold text-error">$<?php echo number_format((float)$activeInvoice['due_amount'], 2); ?></span></p>
            <p class="text-[11px] text-on-surface-variant pt-1 border-t border-outline-variant">
                Cancelling will set the invoice balance to $0.00, remove it from the pending queue, and cancel any uncollected orders.
            </p>
        </div>

        <form method="POST" action="billing_payments.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="cancel_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo (int)$activeInvoice['id']; ?>">
            <input type="hidden" name="redirect" value="billing_payments.php">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Select Cancellation Reason *</label>
                <select name="cancel_reason_preset" id="cancel_reason_preset" onchange="toggleCustomReason(this.value)" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none font-semibold">
                    <option value="Patient abandoned visit / decided not to proceed">Patient abandoned visit / decided not to proceed</option>
                    <option value="Doctor cancelled / modified clinical order">Doctor cancelled / modified clinical order</option>
                    <option value="Duplicate or incorrect billing entry">Duplicate or incorrect billing entry</option>
                    <option value="Patient unable to pay / requested cancellation">Patient unable to pay / requested cancellation</option>
                    <option value="custom">Other custom reason...</option>
                </select>
            </div>

            <div id="custom-reason-container" class="hidden">
                <label class="block text-[11px] font-semibold text-on-surface mb-1">Enter Custom Reason *</label>
                <input type="text" name="cancel_reason_custom" id="cancel_reason_custom" placeholder="Specify reason for cancelling this bill..." class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg p-2 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeCancelInvoiceModal()" class="px-3.5 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">
                    Keep Invoice
                </button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-error hover:bg-error/90 text-on-error text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">cancel</span>
                    Yes, Cancel Bill
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printable-paid-token-slip, #printable-paid-token-slip *,
    #printable-receipt, #printable-receipt * {
        visibility: visible !important;
    }
    #printable-paid-token-slip, #printable-receipt {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 80mm !important;
        margin: 0 !important;
        padding: 10px !important;
        border: none !important;
        box-shadow: none !important;
        background: white !important;
        color: black !important;
    }
}
</style>

<script>
    function setFullPayment(due) {
        const input = document.getElementById('checkout-amount-input');
        if (input) {
            input.value = parseFloat(due).toFixed(2);
        }
    }

    function openCancelInvoiceModal() {
        const modal = document.getElementById('cancel-invoice-modal');
        if (modal) modal.classList.remove('hidden');
    }

    function closeCancelInvoiceModal() {
        const modal = document.getElementById('cancel-invoice-modal');
        if (modal) modal.classList.add('hidden');
    }

    function toggleCustomReason(val) {
        const container = document.getElementById('custom-reason-container');
        if (!container) return;
        if (val === 'custom') {
            container.classList.remove('hidden');
            const input = document.getElementById('cancel_reason_custom');
            if (input) input.focus();
        } else {
            container.classList.add('hidden');
        }
    }

    function printPaidQueueTokenTicket(data) {
        document.getElementById('ppt-modal-title').textContent = data.receipt_title || 'Payment Verified & Pass';
        document.getElementById('ppt-header-dept').textContent = data.header_dept || (data.department ? `Department: ${data.department}` : 'Main Outpatient Clinic • Cashier');
        document.getElementById('ppt-badge-text').textContent = data.badge_text || (data.bill_type === 'lab' ? 'OFFICIAL LAB INVESTIGATION PASS' : (data.bill_type === 'pharmacy' ? 'PRESCRIPTION DISPENSING RECEIPT' : 'CLINICAL CONSULTATION PASS'));
        
        const tokenElem = document.getElementById('ppt-token');
        const tokenCont = document.getElementById('ppt-token-container');
        if (data.token && data.token.trim() !== '') {
            tokenElem.textContent = data.token;
            tokenCont.classList.remove('hidden');
        } else {
            tokenCont.classList.add('hidden');
        }

        document.getElementById('ppt-status').textContent = data.payment_status || (data.clearance_stamp || 'PAID & VERIFIED');
        document.getElementById('ppt-name').textContent = data.name || data.customer_name || 'Walk-in Patient';
        document.getElementById('ppt-mrn').textContent = data.mrn || 'N/A';
        document.getElementById('ppt-phone').textContent = data.phone || data.customer_phone || 'N/A';
        document.getElementById('ppt-doctor').textContent = data.doctor || data.doctor_name || 'Attending Clinician';
        document.getElementById('ppt-dept').textContent = data.department || 'General OPD';
        document.getElementById('ppt-inv').textContent = data.invoice_number || 'N/A';
        document.getElementById('ppt-time').textContent = data.time || data.date_time || new Date().toLocaleString();

        const itemsContainer = document.getElementById('ppt-items-container');
        const itemsList = document.getElementById('ppt-items-list');
        itemsList.innerHTML = '';
        if (data.items && Array.isArray(data.items) && data.items.length > 0) {
            data.items.forEach(it => {
                const itemDiv = document.createElement('div');
                itemDiv.className = 'flex justify-between text-[10px]';
                const qtyStr = it.quantity && parseInt(it.quantity) > 1 ? ` (x${it.quantity})` : '';
                const priceStr = it.total_price ? `$${parseFloat(it.total_price).toFixed(2)}` : (it.unit_price ? `$${parseFloat(it.unit_price).toFixed(2)}` : '');
                itemDiv.innerHTML = `<span>• ${it.item_name || it.test_name}${qtyStr}</span><span class="font-mono font-bold">${priceStr}</span>`;
                itemsList.appendChild(itemDiv);
            });
            itemsContainer.classList.remove('hidden');
        } else {
            itemsContainer.classList.add('hidden');
        }

        const paidVal = (data.paid_amount !== undefined && data.paid_amount !== null && !isNaN(parseFloat(data.paid_amount)))
            ? parseFloat(data.paid_amount)
            : 0.0;
        const netVal = (data.net_total !== undefined && data.net_total !== null && !isNaN(parseFloat(data.net_total)))
            ? parseFloat(data.net_total)
            : ((data.total_bill !== undefined) ? parseFloat(data.total_bill) : paidVal);
        const dueVal = (data.due_amount !== undefined && data.due_amount !== null && !isNaN(parseFloat(data.due_amount)))
            ? parseFloat(data.due_amount)
            : Math.max(0, netVal - paidVal);

        document.getElementById('ppt-total').textContent = `$${netVal.toFixed(2)}`;

        const paidRow = document.getElementById('ppt-paid-row');
        const paidEl = document.getElementById('ppt-paid');
        if (paidVal <= 0.001) {
            paidEl.textContent = `$0.00 (DEBT / AR 1100)`;
            if (paidRow) {
                paidRow.className = 'flex justify-between text-amber-700 font-bold';
            }
        } else {
            const method = data.payment_method ? ` (${data.payment_method})` : '';
            paidEl.textContent = `$${paidVal.toFixed(2)}${method}`;
            if (paidRow) {
                paidRow.className = 'flex justify-between text-green-700 font-bold';
            }
        }

        const dueRow = document.getElementById('ppt-due-row');
        if (dueVal > 0.005) {
            document.getElementById('ppt-due').textContent = `$${dueVal.toFixed(2)}`;
            dueRow.classList.remove('hidden');
        } else {
            dueRow.classList.add('hidden');
        }

        document.getElementById('ppt-notice').textContent = data.notice || (paidVal <= 0.001 ? 'Biilkan waxaa loo fasaxay Deyn Bukaanka (A/R 1100).' : 'Lacagta waa la xaqiijiyay. Fadlan fariiso qeybta sugitaanka.');
        document.getElementById('ppt-subnotice').textContent = data.subnotice || (paidVal <= 0.001 ? 'Cleared on Credit / Accounts Receivable 1100.' : 'Payment verified. Please proceed to service area.');

        document.getElementById('print-paid-token-modal').classList.remove('hidden');
    }

    function closePaidTokenModal() {
        document.getElementById('print-paid-token-modal').classList.add('hidden');
    }

    function executePrintPaidToken() {
        window.print();
    }

    document.addEventListener('DOMContentLoaded', function() {


        <?php if (!empty($printPaidTokenPayload)): ?>
        printPaidQueueTokenTicket(<?php echo json_encode($printPaidTokenPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
        <?php endif; ?>

        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'billing_queue',
                targetSelector: '#billing-pending-queue-container',
                intervalMs: 3500,
                notifyOnNew: true
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
