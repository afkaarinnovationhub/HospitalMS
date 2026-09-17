<?php
/**
 * MedCore Systems - Real Pharmacy Dispensing & Point of Sale (POS)
 * Supports full & partial prescription dispensing, discounts, and customer debt ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/InventoryOperation.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/../CONTROLS/PharmacyController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER, ROLE_PHARMACY]);

// Auto-seed default prescriptions and inventory if fresh
try {
    InventoryOperation::seedDefaultInventoryIfEmpty();
    PharmacyOperation::seedDefaultPrescriptionsIfEmpty();
} catch (Exception $e) {
    error_log('[HPMS PHARMACY SEED ERROR] ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

$pharmacyReceiptPayload = $_SESSION['hpms_pharmacy_receipt'] ?? null;
if (isset($_SESSION['hpms_pharmacy_receipt'])) {
    unset($_SESSION['hpms_pharmacy_receipt']);
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'dispense') {
        $result = PharmacyController::handleDispense($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'walk_in_sale') {
        $result = PharmacyController::handleWalkInSale($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'collect_patient_debt') {
        $result = PharmacyController::handleCollectPatientDebt($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'external_purchase') {
        $result = PharmacyController::handleExternalPurchase($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Fetch Pending Prescriptions Queue
$queue = PharmacyOperation::getPendingPrescriptionsQueue();

// Determine Selected Prescription
$selectedRxId = (int)($_GET['rx_id'] ?? 0);
if ($selectedRxId <= 0 && !empty($queue)) {
    $selectedRxId = (int)$queue[0]['id'];
}

$activePrescription = ($selectedRxId > 0) ? PharmacyOperation::getPrescriptionById($selectedRxId) : null;
$allMedications     = InventoryOperation::getMedicationsForSale();


$pageTitle = 'Pharmacy Dispensing - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Pharmacy';
$activePage = 'pharmacy';

include __DIR__ . '/../components/header.php';
?>

<!-- Pharmacy Dispensing Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Header & Action Buttons -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-md mb-lg">
        <div>
            <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Pharmacy Dispensing &amp; E-Prescriptions</h2>
            <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Pharmacist'); ?></strong>
            </p>
        </div>
        <div class="flex flex-wrap gap-sm w-full sm:w-auto">

            <button type="button" onclick="openWalkInModal()" class="flex-1 sm:flex-none justify-center px-3 py-2 bg-secondary text-on-secondary font-label-md text-xs rounded-lg hover:bg-on-secondary-container transition-colors flex items-center gap-1.5 font-semibold shadow-xs cursor-pointer">
                <span class="material-symbols-outlined text-[18px]">point_of_sale</span>
                + Direct Walk-in / OTC Sale
            </button>
            <a href="inventory_management.php" class="flex-1 sm:flex-none justify-center px-3 py-2 border border-primary text-primary font-label-md text-xs rounded-lg hover:bg-surface-container-low transition-colors flex items-center gap-1.5 font-medium">
                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
                Check Stock
            </a>
        </div>
    </div>

    <!-- Alert Banners -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-lg p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Pharmacy Alert</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-lg p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Operation Completed</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active Prescription Dispensing Card (Featured) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 sm:gap-lg mb-xl">
        <!-- Left: Current Selected Prescription (8 cols on desktop) -->
        <div class="lg:col-span-8 flex flex-col gap-md">
            <?php if (!$activePrescription): ?>
                <div class="bg-surface border border-outline-variant rounded-xl p-8 text-center shadow-sm">
                    <span class="material-symbols-outlined text-4xl text-secondary mb-2">check_circle</span>
                    <h3 class="font-bold text-base text-on-surface">Queue is Clear!</h3>
                    <button type="button" onclick="openWalkInModal()" class="mt-4 px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-semibold cursor-pointer">
                        Open Walk-in Direct Sale
                    </button>
                </div>
            <?php else: ?>
                <div class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm">
                    <!-- Prescription Top Bar -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-sm border-b border-outline-variant pb-md mb-md">
                        <div>
                            <div class="flex items-center gap-sm">
                                <span class="font-code-md font-bold text-primary text-base sm:text-lg"><?php echo e($activePrescription['rx_number']); ?></span>
                                <?php if ($activePrescription['status'] === 'partially_dispensed'): ?>
                                    <span class="bg-tertiary-fixed text-on-tertiary-fixed font-label-md text-[10px] sm:text-xs px-2.5 py-0.5 rounded-full font-bold">Partially Dispensed</span>
                                <?php else: ?>
                                    <span class="bg-primary-container text-on-primary-container font-label-md text-[10px] sm:text-xs px-2.5 py-0.5 rounded-full font-bold">Ready to Dispense</span>
                                <?php endif; ?>
                            </div>
                            <p class="font-body-sm text-xs text-on-surface-variant mt-1">Prescribed by <?php echo e($activePrescription['doctor_name']); ?> • <?php echo date('M d, Y g:i A', strtotime($activePrescription['created_at'])); ?></p>
                        </div>
                        <div class="text-left sm:text-right">
                            <p class="font-body-md text-xs sm:text-body-md font-bold text-on-surface">Patient: <?php echo e($activePrescription['patient_name']); ?></p>
                            <p class="font-body-sm text-[11px] text-on-surface-variant font-code-md"><?php echo e($activePrescription['patient_mrn']); ?></p>
                        </div>
                    </div>

                    <!-- Drug Allergy Cross-Check Banner -->
                    <?php if (!empty($activePrescription['allergy_alert']) && strtolower($activePrescription['allergy_alert']) !== 'none known'): ?>
                        <div class="p-3 rounded-lg bg-error-container/40 border border-error/30 flex flex-col sm:flex-row items-start sm:items-center gap-2 mb-md">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-error text-[20px]">warning</span>
                                <span class="font-label-md text-xs font-bold text-on-error-container uppercase">Drug Allergy Cross-Check:</span>
                            </div>
                            <div class="flex-1">
                                <span class="font-body-sm text-xs text-on-error-container">Patient documented allergy: <strong><?php echo e($activePrescription['allergy_alert']); ?></strong>. Verify prescribed items safety.</span>
                            </div>
                            <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-[10px] px-2 py-0.5 rounded font-bold self-end sm:self-auto">SAFETY VERIFIED</span>
                        </div>
                    <?php endif; ?>

                    <!-- Dispense Form wrapping interactive table and checkout -->
                    <form id="form-rx-dispense" method="POST" action="pharmacy_dispensing_prescription.php" class="space-y-4">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="dispense">
                        <input type="hidden" name="prescription_id" value="<?php echo (int)$activePrescription['id']; ?>">

                        <!-- Prescribed Items Table with Partial Dispense Quantity Adjuster -->
                        <div class="overflow-x-auto custom-scrollbar border border-outline-variant rounded-lg mb-md">
                            <table class="w-full text-left border-collapse min-w-[650px]">
                                <thead class="bg-surface-container-low font-label-md text-xs text-on-surface-variant border-b border-outline-variant">
                                    <tr>
                                        <th class="py-2.5 px-3 font-semibold">Medication</th>
                                        <th class="py-2.5 px-3 font-semibold">Dosage Instructions</th>
                                        <th class="py-2.5 px-3 font-semibold text-center">Ordered</th>
                                        <th class="py-2.5 px-3 font-semibold text-center">Dispensed</th>
                                        <th class="py-2.5 px-3 font-semibold text-center w-28 bg-primary-fixed/20 text-primary">Dispense Now</th>
                                        <th class="py-2.5 px-3 font-semibold">Stock on Shelf</th>
                                        <th class="py-2.5 px-3 font-semibold text-right">Unit Price</th>
                                        <th class="py-2.5 px-3 font-semibold text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody class="font-body-sm text-xs divide-y divide-outline-variant">
                                    <?php 
                                        $initialSubtotal = 0.0;
                                        $allAvailable = true;
                                    ?>
                                    <?php foreach ($activePrescription['items'] as $item): ?>
                                        <?php 
                                            $itemId = (int)$item['id'];
                                            $qtyPrescribed = (int)$item['quantity_prescribed'];
                                            $qtyDispensed  = (int)$item['quantity_dispensed'];
                                            $qtyRemaining  = (int)$item['quantity_remaining'];
                                            $unitPrice     = (float)$item['unit_price'];
                                            $currentStock  = (int)$item['current_stock'];
                                            $isAvail       = ($currentStock >= $qtyRemaining);
                                            if (!$isAvail && $currentStock <= 0) $allAvailable = false;

                                            $defaultDispenseQty = min($qtyRemaining, $currentStock > 0 ? $qtyRemaining : 0);
                                            $lineTotal = round($defaultDispenseQty * $unitPrice, 2);
                                            $initialSubtotal += $lineTotal;

                                            $dosageForm = strtolower($item['dosage_form'] ?? '');
                                            $unitType = (str_contains($dosageForm, 'bottle')) ? 'bottle' : ((str_contains($dosageForm, 'inhaler')) ? 'can' : 'unit');
                                        ?>
                                        <tr class="hover:bg-surface-container-low/50 transition-colors">
                                            <td class="py-3 px-3">
                                                <p class="font-bold text-on-surface"><?php echo e($item['medication_name']); ?></p>
                                                <p class="text-[11px] text-on-surface-variant font-code-md"><?php echo e($item['med_code']); ?> • <?php echo e($item['dosage_form']); ?></p>
                                            </td>
                                            <td class="py-3 px-3 text-on-surface-variant"><?php echo e($item['dosage_instructions']); ?></td>
                                            <td class="py-3 px-3 text-center font-semibold text-on-surface"><?php echo $qtyPrescribed; ?></td>
                                            <td class="py-3 px-3 text-center text-on-surface-variant"><?php echo $qtyDispensed; ?></td>
                                            <td class="py-3 px-3 text-center bg-primary-fixed/10">
                                                <?php if ($qtyRemaining <= 0): ?>
                                                    <span class="text-secondary font-bold text-xs">Completed</span>
                                                <?php else: ?>
                                                    <div class="flex items-center justify-center gap-1">
                                                        <input 
                                                            type="number" 
                                                            name="dispense_qty[<?php echo $itemId; ?>]" 
                                                            id="disp-qty-<?php echo $itemId; ?>" 
                                                            class="dispense-qty-input w-16 bg-surface border border-primary/40 focus:border-primary rounded px-2 py-1 text-center font-bold text-xs text-primary outline-none"
                                                            min="0" 
                                                            max="<?php echo $qtyRemaining; ?>" 
                                                            value="<?php echo $defaultDispenseQty; ?>"
                                                            data-unit-price="<?php echo $unitPrice; ?>"
                                                            data-remaining="<?php echo $qtyRemaining; ?>"
                                                            data-id="<?php echo $itemId; ?>"
                                                            oninput="recalcRxDispensing()"
                                                        >
                                                    </div>
                                                    <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo $qtyRemaining; ?> rem.</p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-3">
                                                <?php if ($currentStock >= $qtyRemaining): ?>
                                                    <span class="inline-flex items-center gap-1 text-secondary font-semibold whitespace-nowrap">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> <?php echo $currentStock; ?> on shelf
                                                    </span>
                                                <?php elseif ($currentStock > 0): ?>
                                                    <span class="inline-flex items-center gap-1 text-tertiary font-semibold whitespace-nowrap">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-tertiary"></span> Low: <?php echo $currentStock; ?> avail
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 text-error font-semibold whitespace-nowrap">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-error"></span> Out of stock (0)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-3 text-right text-on-surface">$<?php echo number_format($unitPrice, 2); ?></td>
                                            <td class="py-3 px-3 text-right font-bold text-on-surface">
                                                $<span id="line-total-<?php echo $itemId; ?>"><?php echo number_format($lineTotal, 2); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-surface-container-lowest font-bold text-xs border-t border-outline-variant">
                                    <tr>
                                        <td colspan="7" class="py-2.5 px-3 text-right text-on-surface-variant">Dispensed Subtotal:</td>
                                        <td class="py-2.5 px-3 text-right text-primary text-sm font-bold">$<span id="rx-subtotal-display"><?php echo number_format($initialSubtotal, 2); ?></span></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Patient Wallet Credit Banner -->
                        <?php $patCredit = (float)($activePrescription['account_credit'] ?? 0.00); ?>
                        <?php if ($patCredit > 0.005): ?>
                            <div class="p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-emerald-600 text-[24px]">account_balance_wallet</span>
                                    <div>
                                        <p class="font-bold text-xs text-emerald-950 dark:text-emerald-200 flex items-center gap-1.5">
                                            Baaqi Bukaanka u Yaalla (Patient Account Credit)
                                            <span class="text-[10px] font-extrabold bg-emerald-600 text-white px-2 py-0.5 rounded-full font-mono">$<?php echo number_format($patCredit, 2); ?> Available</span>
                                        </p>
                                        <p class="text-[11px] text-emerald-800 dark:text-emerald-300 mt-0.5">
                                            Bukaankani wuxuu nidaamka ku leeyahay lacag u dhigan oo aad uga jari karto biilka daawada.
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="hidden" id="avail-patient-credit" value="<?php echo $patCredit; ?>">
                                    <button type="button" onclick="applyFullPatientCredit()" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold flex items-center gap-1 shadow-xs cursor-pointer">
                                        <span class="material-symbols-outlined text-[15px]">savings</span>
                                        Isticmaal Baaqiga ($<?php echo number_format($patCredit, 2); ?>)
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <input type="hidden" id="avail-patient-credit" value="0.00">
                        <?php endif; ?>

                        <!-- Financials, Discount & Payment Schedule for this Patient -->
                        <div class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant space-y-3">
                            <div class="flex justify-between items-center">
                                <p class="font-bold text-xs text-primary flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[16px]">payments</span>
                                    Prescription Billing &amp; Payment.
                                </p>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Subtotal</label>
                                    <input id="rx-subtotal" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs" value="$<?php echo number_format($initialSubtotal, 2); ?>" type="text">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Discount ($)</label>
                                    <input id="rx-discount" name="discount_amount" step="0.01" oninput="recalcRxDispensing()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="0.00" value="0.00" type="number">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-emerald-800 dark:text-emerald-300 mb-0.5 font-semibold flex items-center gap-0.5">
                                        <span class="material-symbols-outlined text-[13px]">account_balance_wallet</span> Credit ($)
                                    </label>
                                    <input id="rx-credit" name="credit_applied" step="0.01" min="0" max="<?php echo $patCredit; ?>" oninput="recalcRxDispensing()" class="w-full bg-emerald-500/10 border border-emerald-500/40 rounded p-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-300 outline-none" placeholder="0.00" value="0.00" type="number">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Net Payable</label>
                                    <input id="rx-net" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs text-primary" value="$<?php echo number_format($initialSubtotal, 2); ?>" type="text">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Paid Now ($)</label>
                                    <input id="rx-paid" name="paid_amount" step="0.01" oninput="recalcRxDispensing()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-bold text-secondary" placeholder="0.00" value="<?php echo number_format($initialSubtotal, 2, '.', ''); ?>" type="number">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Remaining Debt ($)</label>
                                    <input id="rx-due" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs text-error" value="$0.00" type="text">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Payment Method</label>
                                    <select name="payment_method" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs">
                                        <option value="cash">Cash on Hand (Khasnadda)</option>
                                        <option value="mobile">Mobile Money (EVC Plus / E-Dahab)</option>
                                        <option value="card">Bank Account (Commercial Banks)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-on-surface-variant mb-0.5 font-semibold">Patient Phone (Required if Debt)</label>
                                    <input name="customer_phone" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs text-on-surface" placeholder="e.g. (555) 019-9900" type="text">
                                </div>
                            </div>
                        </div>

                        <!-- Pharmacist Counseling Notes & Buttons -->
                        <div class="space-y-3">
                            <div>
                                <label class="block font-label-md text-xs text-on-surface-variant mb-xs font-semibold">Pharmacist Counseling Notes</label>
                                <input name="pharmacist_notes" class="w-full bg-surface-container-lowest border border-outline-variant rounded-lg py-2 px-3 font-body-sm text-xs text-on-surface outline-none focus:border-primary" placeholder="Counseled on dosage & completing cycle..." type="text" value="<?php echo e($activePrescription['pharmacist_notes'] ?? ''); ?>">
                            </div>
                            
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 pt-2 border-t border-outline-variant/60">
                                <!-- Left Actions: External Purchase & Slip Print -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" onclick="openExternalPurchaseModal()" class="px-3.5 py-2 bg-amber-500/10 hover:bg-amber-500/20 text-amber-900 dark:text-amber-200 border border-amber-500/40 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer shadow-xs" title="Bukaanka ayaa doortay inuu daawada meel kale ka soo gato">
                                        <span class="material-symbols-outlined text-[17px] text-amber-600 dark:text-amber-400">storefront</span>
                                        Bannaanka Ayuu Ka Gadanayaa (External)
                                    </button>
                                    <button type="button" onclick="printActivePrescriptionSlip()" class="px-3 py-2 bg-surface-container-low hover:bg-surface-container border border-outline-variant text-on-surface rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 cursor-pointer shadow-xs" title="Daabac warqadda rasmiga ah ee dhakhtarku qoray">
                                        <span class="material-symbols-outlined text-[17px] text-primary">description</span>
                                        Print Prescription (Rikheto)
                                    </button>
                                </div>

                                <!-- Right Actions: Print Labels & Confirm Dispense -->
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" onclick="window.print();" class="px-3 py-2 border border-outline-variant text-on-surface font-label-md text-xs rounded-lg hover:bg-surface-container-low transition-colors flex items-center gap-1 font-medium cursor-pointer">
                                        <span class="material-symbols-outlined text-[16px]">print</span>
                                        Print Labels
                                    </button>
                                    <button type="submit" id="btn-dispense-submit" class="px-4 py-2 bg-primary text-on-primary font-label-md text-xs font-bold rounded-lg hover:bg-primary-container transition-colors shadow-sm flex items-center gap-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                        Confirm &amp; Dispense
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Incoming Prescription Queue (4 cols on desktop) -->
        <div class="lg:col-span-4 flex flex-col gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm h-full flex flex-col">
                <div class="flex justify-between items-center pb-sm border-b border-outline-variant mb-sm">
                    <h3 class="font-headline-sm text-sm text-on-surface font-bold">Incoming Queue</h3>
                    <span class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-[10px] px-2 py-0.5 rounded-full font-bold"><?php echo count($queue); ?> Orders</span>
                </div>
                <div id="pharmacy-queue-container" class="flex-1 space-y-sm overflow-y-auto custom-scrollbar max-h-[500px]">
                    <?php if (empty($queue)): ?>
                        <p class="text-xs text-on-surface-variant py-4 text-center">No orders in queue</p>
                    <?php else: ?>
                        <?php foreach ($queue as $q): ?>
                            <?php 
                                $isSelected = ($selectedRxId === (int)$q['id']); 
                                $isPartial = ($q['status'] === 'partially_dispensed');
                            ?>
                            <a href="?rx_id=<?php echo (int)$q['id']; ?>" class="block p-sm rounded border transition-colors <?php echo $isSelected ? 'bg-primary-fixed/20 border-primary/40 shadow-xs' : 'bg-surface-container-lowest border-outline-variant hover:border-primary/40'; ?>">
                                <div class="flex justify-between items-start">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-code-md text-xs font-bold <?php echo $isSelected ? 'text-primary' : 'text-on-surface'; ?>"><?php echo e($q['rx_number']); ?></span>
                                        <?php if ($isPartial): ?>
                                            <span class="bg-tertiary-fixed text-on-tertiary-fixed text-[9px] font-bold px-1.5 py-0.2 rounded">Partial</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[10px] font-semibold text-primary"><?php echo date('g:i A', strtotime($q['created_at'])); ?></span>
                                </div>
                                <p class="font-body-sm text-xs font-semibold text-on-surface mt-1"><?php echo e($q['patient_name']); ?></p>
                                <p class="text-[11px] text-on-surface-variant truncate"><?php echo (int)$q['item_count']; ?> items • <?php echo e($q['medication_names'] ?: 'Prescription medications'); ?></p>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- MODAL 1: Direct Walk-in / Over-The-Counter (OTC) POS Sale -->
<div id="walkin-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-2xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-secondary text-[26px]">point_of_sale</span>
                <div>
                    <h3 class="font-headline-sm text-lg font-bold text-on-surface">Walk-in / Direct OTC Pharmacy Sale</h3>
                </div>
            </div>
            <button type="button" onclick="closeWalkInModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[22px]">close</span>
            </button>
        </div>

        <form method="POST" action="pharmacy_dispensing_prescription.php" class="space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="walk_in_sale">

            <!-- Hidden Patient ID if selected from directory -->
            <input type="hidden" name="patient_id" id="pos-patient-id" value="">

            <!-- Customer Identity & Patient Search (Matching Reception UX) -->
            <div class="p-3.5 bg-surface-container-lowest rounded-xl border border-outline-variant space-y-3">
                <!-- Patient Live Search Bar -->
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label class="block font-bold text-xs text-primary flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[17px]">person_search</span>
                            Baar Bukaan Hore / Registered Patient
                        </label>
                    </div>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-[18px] pointer-events-none">search</span>
                        <input type="text" 
                               id="pos-patient-search" 
                               name="patient_search_query"
                               oninput="handlePosPatientSearch(this.value)" 
                               placeholder="Qor magaca bukaanka, taleefankiisa ama MRN si aad u doorato..." 
                               autocomplete="off" 
                               class="w-full pl-9 pr-8 py-2 bg-surface border border-outline-variant rounded-lg text-xs text-on-surface focus:border-primary outline-none shadow-xs">
                        <button type="button" id="clear-pos-search" onclick="clearPosPatientSearch()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant hover:text-on-surface p-0.5 rounded cursor-pointer">
                            <span class="material-symbols-outlined text-[16px]">close</span>
                        </button>
                        <!-- Autocomplete Dropdown -->
                        <div id="pos-search-results" class="hidden absolute z-30 left-0 right-0 top-full mt-1 bg-surface border border-outline-variant rounded-xl shadow-2xl overflow-hidden max-h-56 overflow-y-auto custom-scrollbar divide-y divide-outline-variant/60"></div>
                    </div>
                </div>

                <!-- Selected Patient Notification Pill / Card -->
                <div id="pos-selected-patient-card" class="hidden p-2.5 rounded-lg border border-primary/40 bg-primary-fixed/20 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="material-symbols-outlined text-primary text-[20px] shrink-0">account_circle</span>
                        <div class="min-w-0 text-xs">
                            <span class="font-bold text-on-surface" id="pos-card-patient-name">--</span>
                            <span id="pos-card-patient-mrn" class="font-mono text-[10px] font-bold bg-primary text-on-primary px-1.5 py-0.2 rounded ml-1">--</span>
                            <span id="pos-card-patient-debt-badge" class="hidden text-[10px] font-bold text-error bg-error-container text-on-error-container px-2 py-0.5 rounded ml-1.5 inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-[13px]">warning</span>
                                Deynta ku maqan: <span id="pos-card-patient-debt-val" class="font-mono font-bold">$0.00</span>
                            </span>
                        </div>
                    </div>
                    <button type="button" onclick="clearPosSelectedPatient()" class="text-[10px] font-bold text-error hover:bg-error/10 px-2 py-0.5 rounded border border-error/30 cursor-pointer shrink-0">
                        Ka saar / Beddel
                    </button>
                </div>

                <!-- Customer Name & Phone (Auto-filled if patient selected, or filled manually for new customer) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-outline-variant/40">
                    <div>
                        <label class="block font-semibold text-xs text-on-surface mb-1">Customer / Patient Name</label>
                        <input id="pos_customer_name" name="customer_name" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Ahmed Warsame" type="text">
                    </div>
                    <div>
                        <label class="block font-semibold text-xs text-on-surface mb-1">Customer Phone (Required if Debt / Partial)</label>
                        <input id="pos_customer_phone" name="customer_phone" class="w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. (555) 012-9900" type="text">
                    </div>
                </div>
            </div>

            <!-- Medication Items Selector -->
            <div class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant space-y-3">
                <div class="flex justify-between items-center">
                    <p class="font-bold text-xs text-primary flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">medication</span>
                        Select Medications
                    </p>
                    <button type="button" onclick="addMedicationRow()" class="text-xs text-primary font-bold hover:underline flex items-center gap-0.5 cursor-pointer">
                        + Add Another Item
                    </button>
                </div>

                <div id="pos-items-container" class="space-y-2">
                    <div class="pos-row grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-6">
                            <select name="medication_id[]" onchange="calcPosTotals()" class="pos-med-select w-full bg-surface border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" required>
                                <option value="">-- Choose Medicine --</option>
                                <?php if (empty($allMedications)): ?>
                                    <option value="" disabled>⚠️ No medications in stock (Stock: 0 - Please restock via Inventory)</option>
                                <?php else: ?>
                                    <?php foreach ($allMedications as $m): ?>
                                        <option value="<?php echo (int)$m['id']; ?>" data-price="<?php echo (float)$m['unit_price']; ?>" data-stock="<?php echo (int)$m['current_stock']; ?>">
                                            <?php echo e($m['name']); ?> ($<?php echo number_format((float)$m['unit_price'], 2); ?>) — Stock: <?php echo (int)$m['current_stock']; ?> units
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-span-3">
                            <input name="quantity[]" oninput="calcPosTotals()" class="pos-qty w-full bg-surface border border-outline-variant rounded p-2 text-xs font-bold" type="number" min="1" value="1" placeholder="Qty" required>
                        </div>
                        <div class="col-span-2 text-right font-bold text-xs pos-line-total text-on-surface">$0.00</div>
                        <div class="col-span-1 text-center">
                            <button type="button" onclick="removePosRow(this)" class="text-error hover:opacity-80 p-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financials, Discount & Payment Schedule -->
            <div class="p-3 bg-surface-container-lowest rounded-xl border border-outline-variant space-y-3">
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Subtotal</label>
                        <input id="pos-subtotal" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs" value="$0.00" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Discount ($)</label>
                        <input id="pos-discount" name="discount_amount" step="0.01" oninput="calcPosTotals()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-bold" placeholder="0.00" value="0.00" type="number">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Net Total Payable</label>
                        <input id="pos-net" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs text-primary" value="$0.00" type="text">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Paid Now ($)</label>
                        <input id="pos-paid" name="paid_amount" step="0.01" oninput="calcPosTotals()" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs font-bold text-secondary" placeholder="0.00" value="0.00" type="number">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Balance Due (Deyn)</label>
                        <input id="pos-due" readonly class="w-full bg-surface-container-low border border-outline-variant rounded p-1.5 font-bold text-xs text-error" value="$0.00" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] text-on-surface-variant mb-0.5">Payment Method</label>
                        <select name="payment_method" class="w-full bg-surface border border-outline-variant rounded p-1.5 text-xs">
                            <option value="cash">Cash</option>
                            <option value="mobile">Mobile (EVC / Zaad)</option>
                            <option value="card">Card / POS</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeWalkInModal()" class="px-4 py-2 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-secondary hover:bg-on-secondary-container text-on-secondary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">receipt</span>
                    Complete POS Sale &amp; Dispense
                </button>
            </div>
        </form>
    </div>
</div>



<script>
    function openWalkInModal() {
        document.getElementById('walkin-modal').classList.remove('hidden');
        const paidInput = document.getElementById('pos-paid');
        if (paidInput) {
            paidInput.dataset.dirty = 'false';
            paidInput.value = '';
        }
        calcPosTotals();
    }
    function closeWalkInModal() {
        document.getElementById('walkin-modal').classList.add('hidden');
        clearPosPatientSearch();
    }

    // =========================================================================
    // POS PATIENT LIVE SEARCH & AUTOCOMPLETE HANDLERS (Matching Reception UX)
    // =========================================================================
    let posSearchTimeout = null;
    let posSearchResultsMap = {};

    function handlePosPatientSearch(val) {
        const trimmed = (val || '').trim();
        const clearBtn = document.getElementById('clear-pos-search');
        const resultsBox = document.getElementById('pos-search-results');
        const selectedPid = document.getElementById('pos-patient-id')?.value;

        // Auto-sync into customer name input if user is typing a new customer and no existing patient is locked
        if (!selectedPid) {
            const nameInput = document.getElementById('pos_customer_name');
            if (nameInput) {
                nameInput.value = val;
            }
        }

        if (clearBtn) {
            clearBtn.classList.toggle('hidden', trimmed.length === 0);
        }

        if (trimmed.length === 0) {
            if (resultsBox) {
                resultsBox.classList.add('hidden');
                resultsBox.innerHTML = '';
            }
            return;
        }

        clearTimeout(posSearchTimeout);
        posSearchTimeout = setTimeout(() => {
            fetch('../api/live_sync.php?module=search_patients&q=' + encodeURIComponent(trimmed))
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        renderPosSearchResults(data.patients || []);
                    } else {
                        renderPosSearchResults([]);
                    }
                })
                .catch(err => {
                    console.error('[POS PATIENT SEARCH ERROR]', err);
                    renderPosSearchResults([]);
                });
        }, 200);
    }

    function renderPosSearchResults(patients) {
        const resultsBox = document.getElementById('pos-search-results');
        if (!resultsBox) return;

        if (!patients || patients.length === 0) {
            resultsBox.innerHTML = `
                <div class="p-3 text-center text-on-surface-variant text-xs space-y-0.5">
                    <span class="material-symbols-outlined text-outline text-[20px]">person_off</span>
                    <p class="font-semibold">Bukaan lama helin. Geli magaca iyo taleefanka hoose si cusub loogu diiwaangeliyo.</p>
                </div>
            `;
            resultsBox.classList.remove('hidden');
            return;
        }

        posSearchResultsMap = {};
        patients.forEach(p => { posSearchResultsMap[p.id] = p; });

        let html = '';
        patients.forEach(p => {
            const fullName = p.full_name || (p.first_name + ' ' + p.last_name);
            const mrn = p.mrn || '';
            const phone = p.phone || 'No phone';
            const curDebt = parseFloat(p.current_debt || 0);
            const debtBadge = curDebt > 0.005 
                ? `<span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-error bg-error/15 px-2 py-0.5 rounded font-mono"><span class="material-symbols-outlined text-[12px]">warning</span> Deyn: $${curDebt.toFixed(2)}</span>` 
                : `<span class="text-[10px] font-semibold text-secondary bg-secondary/10 px-2 py-0.5 rounded">Debt Free</span>`;
            const initial = (fullName.trim().charAt(0) || 'P').toUpperCase();

            html += `
                <div onclick="selectPosPatient(${p.id})" class="p-2.5 hover:bg-primary-fixed/20 transition-colors cursor-pointer flex items-center justify-between gap-2 group">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="w-7 h-7 rounded-full bg-primary text-on-primary font-bold text-xs flex items-center justify-center shrink-0 shadow-xs">
                            ${initial}
                        </div>
                        <div class="min-w-0 text-xs">
                            <div class="font-bold text-on-surface truncate group-hover:text-primary transition-colors">${escapeHtml(fullName)}</div>
                            <div class="text-[10px] text-on-surface-variant font-mono">MRN: ${escapeHtml(mrn)} • Tel: ${escapeHtml(phone)}</div>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        ${debtBadge}
                    </div>
                </div>
            `;
        });

        resultsBox.innerHTML = html;
        resultsBox.classList.remove('hidden');
    }

    function selectPosPatient(patientId) {
        const p = posSearchResultsMap[patientId];
        if (!p) return;

        document.getElementById('pos-patient-id').value = p.id;
        const nameVal = p.full_name || (p.first_name + ' ' + p.last_name);
        document.getElementById('pos_customer_name').value = nameVal;
        document.getElementById('pos_customer_phone').value = p.phone || '';

        // Populate Selected Card
        document.getElementById('pos-card-patient-name').textContent = nameVal;
        document.getElementById('pos-card-patient-mrn').textContent = p.mrn || 'N/A';

        const curDebt = parseFloat(p.current_debt || 0);
        const debtBadge = document.getElementById('pos-card-patient-debt-badge');
        const debtVal = document.getElementById('pos-card-patient-debt-val');
        if (curDebt > 0.005) {
            debtVal.textContent = '$' + curDebt.toFixed(2);
            debtBadge.classList.remove('hidden');
        } else {
            debtBadge.classList.add('hidden');
        }

        document.getElementById('pos-selected-patient-card').classList.remove('hidden');
        clearPosPatientSearch();
    }

    function clearPosSelectedPatient() {
        document.getElementById('pos-patient-id').value = '';
        document.getElementById('pos_customer_name').value = '';
        document.getElementById('pos_customer_phone').value = '';
        document.getElementById('pos-selected-patient-card').classList.add('hidden');
        const sInput = document.getElementById('pos-patient-search');
        if (sInput) {
            sInput.value = '';
            sInput.focus();
        }
        const clearBtn = document.getElementById('clear-pos-search');
        if (clearBtn) clearBtn.classList.add('hidden');
        const resultsBox = document.getElementById('pos-search-results');
        if (resultsBox) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
        }
    }

    function clearPosPatientSearch() {
        const input = document.getElementById('pos-patient-search');
        if (input) input.value = '';
        const clearBtn = document.getElementById('clear-pos-search');
        if (clearBtn) clearBtn.classList.add('hidden');
        const resultsBox = document.getElementById('pos-search-results');
        if (resultsBox) {
            resultsBox.classList.add('hidden');
            resultsBox.innerHTML = '';
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Close search dropdown on outside click
    document.addEventListener('click', function(e) {
        const searchInput = document.getElementById('pos-patient-search');
        const resultsBox = document.getElementById('pos-search-results');
        if (resultsBox && !resultsBox.classList.contains('hidden')) {
            if (searchInput && !searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                resultsBox.classList.add('hidden');
            }
        }
    });


    function applyFullPatientCredit() {
        const subtotalInput = document.getElementById('rx-subtotal');
        const subtotal = parseFloat(subtotalInput ? subtotalInput.value.replace('$', '') : 0) || 0;
        const discountInput = document.getElementById('rx-discount');
        const discount = parseFloat(discountInput ? discountInput.value : 0) || 0;
        const netBeforeCredit = Math.max(0, subtotal - discount);

        const maxCredit = parseFloat(document.getElementById('avail-patient-credit')?.value || 0) || 0;
        const creditToApply = Math.min(netBeforeCredit, maxCredit);

        const creditInput = document.getElementById('rx-credit');
        if (creditInput) {
            creditInput.value = creditToApply.toFixed(2);
            const paidInput = document.getElementById('rx-paid');
            if (paidInput) paidInput.dataset.dirty = 'false';
            recalcRxDispensing();
        }
    }

    function recalcRxDispensing() {
        let subtotal = 0;
        const inputs = document.querySelectorAll('.dispense-qty-input');

        inputs.forEach(input => {
            const qty = Math.max(0, parseInt(input.value) || 0);
            const maxRem = parseInt(input.dataset.remaining) || 0;
            const unitPrice = parseFloat(input.dataset.unitPrice) || 0;
            const itemId = input.dataset.id;

            const effectiveQty = Math.min(qty, maxRem);
            const lineTotal = effectiveQty * unitPrice;

            const lineTotalElem = document.getElementById('line-total-' + itemId);
            if (lineTotalElem) {
                lineTotalElem.textContent = lineTotal.toFixed(2);
            }
            subtotal += lineTotal;
        });

        const subtotalDisplay = document.getElementById('rx-subtotal-display');
        if (subtotalDisplay) subtotalDisplay.textContent = subtotal.toFixed(2);

        const subtotalInput = document.getElementById('rx-subtotal');
        if (subtotalInput) subtotalInput.value = '$' + subtotal.toFixed(2);

        const discountInput = document.getElementById('rx-discount');
        const discount = parseFloat(discountInput ? discountInput.value : 0) || 0;
        const netBeforeCredit = Math.max(0, subtotal - discount);

        // Calculate and cap patient credit
        const creditInput = document.getElementById('rx-credit');
        let credit = parseFloat(creditInput ? creditInput.value : 0) || 0;
        const maxCreditAvail = parseFloat(document.getElementById('avail-patient-credit')?.value || 0) || 0;
        credit = Math.min(credit, maxCreditAvail);
        credit = Math.min(credit, netBeforeCredit);
        if (creditInput && creditInput.value !== '' && parseFloat(creditInput.value) > credit) {
            creditInput.value = credit.toFixed(2);
        }

        const netAfterCredit = Math.max(0, netBeforeCredit - credit);

        const netInput = document.getElementById('rx-net');
        if (netInput) netInput.value = '$' + netAfterCredit.toFixed(2);

        const paidInput = document.getElementById('rx-paid');
        if (paidInput) {
            if (paidInput.dataset.dirty !== 'true') {
                paidInput.value = netAfterCredit > 0 ? netAfterCredit.toFixed(2) : '0.00';
            }
            const paid = parseFloat(paidInput.value) || 0;
            const due = Math.max(0, netAfterCredit - paid);
            const dueInput = document.getElementById('rx-due');
            if (dueInput) dueInput.value = '$' + due.toFixed(2);
        }
    }

    const rxPaidInput = document.getElementById('rx-paid');
    if (rxPaidInput) {
        rxPaidInput.addEventListener('input', function() {
            this.dataset.dirty = 'true';
            recalcRxDispensing();
        });
    }

    function addMedicationRow() {
        const container = document.getElementById('pos-items-container');
        const firstRow = container.querySelector('.pos-row');
        const newRow = firstRow.cloneNode(true);
        newRow.querySelector('.pos-med-select').selectedIndex = 0;
        newRow.querySelector('.pos-qty').value = 1;
        newRow.querySelector('.pos-line-total').textContent = '$0.00';
        container.appendChild(newRow);
    }

    function removePosRow(btn) {
        const rows = document.querySelectorAll('.pos-row');
        if (rows.length > 1) {
            btn.closest('.pos-row').remove();
            calcPosTotals();
        } else {
            alert('At least one medication item is required.');
        }
    }

    function calcPosTotals() {
        let subtotal = 0;
        const rows = document.querySelectorAll('.pos-row');
        rows.forEach(row => {
            const select = row.querySelector('.pos-med-select');
            const qtyInput = row.querySelector('.pos-qty');
            let qty = parseFloat(qtyInput.value) || 0;
            const opt = select.options[select.selectedIndex];
            const price = (opt && opt.dataset.price) ? parseFloat(opt.dataset.price) : 0;
            const maxStock = (opt && opt.dataset.stock) ? parseInt(opt.dataset.stock) : 0;

            if (opt && opt.value !== '' && maxStock > 0 && qty > maxStock) {
                alert(`Cannot dispense more than available stock (${maxStock} units) for ${opt.textContent.trim().split('(')[0]}`);
                qty = maxStock;
                qtyInput.value = maxStock;
            }

            const lineTotal = price * qty;
            row.querySelector('.pos-line-total').textContent = '$' + lineTotal.toFixed(2);
            subtotal += lineTotal;
        });

        document.getElementById('pos-subtotal').value = '$' + subtotal.toFixed(2);
        const discount = parseFloat(document.getElementById('pos-discount').value) || 0;
        const net = Math.max(0, subtotal - discount);
        document.getElementById('pos-net').value = '$' + net.toFixed(2);

        const paidInput = document.getElementById('pos-paid');
        if (paidInput) {
            if (paidInput.dataset.dirty !== 'true' && (paidInput.value === '' || paidInput.value === null)) {
                paidInput.value = net > 0 ? net.toFixed(2) : '0.00';
            }
            let paid = parseFloat(paidInput.value);
            if (isNaN(paid) || paid < 0) {
                paid = 0;
            }
            const due = Math.max(0, net - paid);
            document.getElementById('pos-due').value = '$' + due.toFixed(2);

            const phoneInput = document.getElementById('pos_customer_phone');
            const nameInput = document.getElementById('pos_customer_name');
            if (due > 0.005) {
                if (phoneInput) {
                    phoneInput.required = true;
                    phoneInput.classList.add('border-primary');
                }
                if (nameInput) {
                    nameInput.required = true;
                    nameInput.classList.add('border-primary');
                }
            } else {
                if (phoneInput) {
                    phoneInput.required = false;
                    phoneInput.classList.remove('border-primary');
                }
                if (nameInput) {
                    nameInput.required = false;
                    nameInput.classList.remove('border-primary');
                }
            }
        }
    }

    const posPaidElem = document.getElementById('pos-paid');
    if (posPaidElem) {
        posPaidElem.addEventListener('input', function() {
            this.dataset.dirty = 'true';
            calcPosTotals();
        });
    }

    // POS Form Validation: Ensure debtors are properly registered with Name & Phone
    const walkInForm = document.querySelector('#walkin-modal form');
    if (walkInForm) {
        walkInForm.addEventListener('submit', function(e) {
            const sVal = (document.getElementById('pos-patient-search')?.value || '').trim();
            const nameInput = document.getElementById('pos_customer_name');
            const phoneInput = document.getElementById('pos_customer_phone');
            const dueVal = parseFloat((document.getElementById('pos-due')?.value || '0').replace('$', '')) || 0;

            if (nameInput && !nameInput.value.trim() && sVal) {
                nameInput.value = sVal;
            }

            if (dueVal > 0.005) {
                const finalName = nameInput ? nameInput.value.trim() : '';
                const finalPhone = phoneInput ? phoneInput.value.trim() : '';

                if (!finalName || finalName.toLowerCase() === 'walk-in customer') {
                    e.preventDefault();
                    alert('Fadlan geli magaca macaamiilka/bukaanka si deynta loogu diiwaangeliyo bukaan ahaan (Customer name is required for credit sales).');
                    if (nameInput) nameInput.focus();
                    return false;
                }
                if (!finalPhone) {
                    e.preventDefault();
                    alert('Fadlan geli lambarka taleefanka macaamiilka si deynta loogu xiriiriyo bukaanka (Phone number is required for credit sales).');
                    if (phoneInput) phoneInput.focus();
                    return false;
                }
            }
        });
    }

    // Dispense Form Validation: If all items are 0, prompt to use External Purchase button
    const rxDispenseForm = document.getElementById('form-rx-dispense');
    if (rxDispenseForm) {
        rxDispenseForm.addEventListener('submit', function(e) {
            let totalQtyDispense = 0;
            document.querySelectorAll('.dispense-qty-input').forEach(inp => {
                totalQtyDispense += Math.max(0, parseInt(inp.value) || 0);
            });
            if (totalQtyDispense <= 0) {
                e.preventDefault();
                alert("Dhammaan daawooyinka waxaa ku qoran 0 xabbo. Haddii bukaanku dhammaan daawooyinka meel kale ka gadanayo, fadlan riix badhanka 'Bannaanka Ayuu Ka Gadanayaa (External)'.");
                return false;
            }
        });
    }
</script>

<!-- MODAL: External Pharmacy Purchase Confirmation -->
<div id="modal-external-purchase" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl max-w-lg w-full border border-outline-variant shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="p-4 sm:p-5 border-b border-outline-variant flex justify-between items-center bg-amber-500/10">
            <div class="flex items-center gap-2.5">
                <div class="w-10 h-10 rounded-xl bg-amber-600 text-white flex items-center justify-center shadow-xs">
                    <span class="material-symbols-outlined text-[22px]">storefront</span>
                </div>
                <div>
                    <h3 class="font-bold text-sm sm:text-base text-on-surface">Iibsi Dibadda ah (External Pharmacy Purchase)</h3>
                    <p class="text-xs text-on-surface-variant">Bukaanka ayaa doortay inuu daawada meel kale ka soo gato</p>
                </div>
            </div>
            <button type="button" onclick="closeExternalPurchaseModal()" class="w-8 h-8 rounded-full hover:bg-surface-container text-on-surface-variant flex items-center justify-center cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="pharmacy_dispensing_prescription.php" class="p-4 sm:p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="external_purchase">
            <input type="hidden" name="prescription_id" value="<?php echo (int)($activePrescription['id'] ?? 0); ?>">

            <div class="p-3.5 bg-surface-container-low rounded-xl border border-outline-variant text-xs space-y-1.5">
                <div class="flex justify-between font-bold">
                    <span class="text-on-surface-variant">Bukaanka:</span>
                    <span class="text-on-surface font-mono"><?php echo e($activePrescription['patient_name'] ?? ''); ?> (<?php echo e($activePrescription['patient_mrn'] ?? ''); ?>)</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Prescription Number:</span>
                    <span class="text-primary font-mono font-bold"><?php echo e($activePrescription['rx_number'] ?? ''); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Dhakhtarka Qoray:</span>
                    <span class="text-on-surface font-semibold"><?php echo e($activePrescription['doctor_name'] ?? ''); ?></span>
                </div>
            </div>

            <div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl text-xs space-y-1 text-amber-950 dark:text-amber-200">
                <p class="font-bold flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px] text-amber-600">info</span>
                    Xeerarka Iibsiga Dibadda (Outsourced Dispensing):
                </p>
                <ul class="list-disc list-inside text-[11px] text-amber-800 dark:text-amber-300 space-y-0.5 pl-1">
                    <li>Wax daawo ah lagama jarayo Bakhaarka Isbitaalka (Zero Stock Deduction).</li>
                    <li>Wax biil ah laguma dalacayo bukaanka ($0.00 Cost).</li>
                    <li>Daawadu waxay si toos ah uga baxaysaa liiska sugitaanka farmashiyaha.</li>
                    <li>Waxaa toos loo daabacayaa <strong>Rikheeto Rasmi ah</strong> oo bukaanku meel kasta ugala soo bixi karo.</li>
                </ul>
            </div>

            <div>
                <label class="block text-xs font-bold text-on-surface mb-1">Qoraalka Farmashiistaha / Sababta (Counseling Notes)</label>
                <input type="text" name="pharmacist_notes" 
                       value="Bukaanka ayaa doortay inuu daawada ka soo gato farmashiye dibadda ah (External Purchase)" 
                       class="w-full bg-surface-container-lowest border border-outline-variant rounded-xl p-2.5 text-xs text-on-surface outline-none focus:border-primary">
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
                <button type="button" onclick="closeExternalPurchaseModal()" class="px-4 py-2 bg-surface border border-outline-variant rounded-xl text-xs font-semibold hover:bg-surface-container cursor-pointer">
                    Ka Noqo (Cancel)
                </button>
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold flex items-center gap-1.5 shadow-sm cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">check_circle</span>
                    Xaqiiji &amp; Diyaari Rikheetada
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Distinct Pharmacy Dispensing & OTC Receipt -->
<div id="pharmacy-receipt-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <h3 class="font-headline-sm text-sm font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-secondary text-[20px]">local_pharmacy</span>
                <span id="prx-modal-title">Pharmacy Receipt</span>
            </h3>
            <button type="button" onclick="closePharmacyReceiptModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Thermal Slip Card -->
        <div id="printable-pharmacy-receipt" class="bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono text-center space-y-2 shadow-inner">
            <div class="border-b border-dashed border-gray-300 pb-2">
                <h4 class="font-bold text-base tracking-wide uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                <p id="prx-header" class="text-[10px] text-gray-600">Central Pharmacy &amp; Dispensing Unit</p>
                <p class="text-[9px] text-gray-500">Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?> • <?php echo htmlspecialchars(HOSPITAL_ADDRESS); ?></p>
            </div>

            <!-- Receipt Category Badge -->
            <div class="pt-1">
                <span id="prx-badge-text" class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-gray-100 border border-gray-300 rounded inline-block text-gray-800">
                    DOCTOR PRESCRIPTION DISPENSED
                </span>
            </div>

            <!-- Queue Token if prescribed patient -->
            <div id="prx-token-container" class="py-1">
                <span class="text-[9px] uppercase font-bold text-gray-500 tracking-wider">Queue Token #</span>
                <div id="prx-token" class="text-3xl font-extrabold tracking-wider my-0.5 text-black">T-001</div>
            </div>

            <!-- Status Stamp -->
            <div class="inline-block bg-green-100 text-green-800 text-[10px] px-2 py-0.5 rounded font-bold border border-green-300">
                <span id="prx-status">✓ MEDICATIONS DISPENSED &amp; VERIFIED</span>
            </div>

            <!-- Patient / Customer Info -->
            <div class="border-t border-b border-dashed border-gray-300 py-2 text-left text-[11px] space-y-1">
                <div class="flex justify-between"><span class="text-gray-500">Customer/Patient:</span> <span id="prx-name" class="font-bold">Ahmed Warsame</span></div>
                <div id="prx-mrn-row" class="flex justify-between"><span class="text-gray-500">MRN:</span> <span id="prx-mrn" class="font-bold">MRN-2026-0042</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Phone:</span> <span id="prx-phone" class="font-bold">(555) 012-9900</span></div>
                <div id="prx-doc-row" class="flex justify-between"><span class="text-gray-500">Doctor:</span> <span id="prx-doctor" class="font-bold">Dr. Michael Chen</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Invoice / Rx #:</span> <span id="prx-inv" class="font-bold">RX-2026-0012</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Date/Time:</span> <span id="prx-time" class="font-bold">Aug 31, 2026 12:30 PM</span></div>
            </div>

            <!-- Itemized Medications Dispensed -->
            <div class="border-b border-dashed border-gray-300 pb-2 text-left text-[10px] space-y-1">
                <div class="font-bold text-gray-700 uppercase text-[9px] border-b border-gray-200 pb-0.5 mb-1">Medications &amp; Dosage Instructions:</div>
                <div id="prx-items-list" class="space-y-1.5"></div>
            </div>

            <!-- Financial Summary -->
            <div class="border-b border-dashed border-gray-300 pb-2 text-[11px] font-bold space-y-0.5 text-left">
                <div class="flex justify-between"><span class="text-gray-600">Total Amount:</span> <span id="prx-total" class="font-mono">$15.00</span></div>
                <div id="prx-discount-row" class="flex justify-between text-secondary hidden"><span>Discount:</span> <span id="prx-discount" class="font-mono">$0.00</span></div>
                <div id="prx-credit-row" class="flex justify-between text-emerald-700 hidden"><span>Credit Applied:</span> <span id="prx-credit" class="font-mono">$0.00</span></div>
                <div class="flex justify-between text-green-700"><span>Amount Paid:</span> <span id="prx-paid" class="font-mono">$15.00 (CASH)</span></div>
                <div id="prx-due-row" class="flex justify-between text-red-600 hidden"><span>Balance Due:</span> <span id="prx-due" class="font-mono">$0.00</span></div>
            </div>

            <div class="pt-1 text-[9px] text-gray-600 leading-tight">
                <p id="prx-notice" class="font-semibold text-green-900">Fadlan u qaado daawooyinka sida dhakhtarku kuu qoray.</p>
                <p id="prx-subnotice" class="mt-0.5">Keep medications in a cool, dry place. Keep out of reach of children.</p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-end gap-2 pt-2 border-t border-outline-variant">
            <button type="button" onclick="closePharmacyReceiptModal()" class="px-3 py-1.5 rounded-lg border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Close</button>
            <button type="button" onclick="executePrintPharmacyReceipt()" class="px-4 py-1.5 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1 cursor-pointer">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Pharmacy Receipt
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printable-pharmacy-receipt, #printable-pharmacy-receipt * {
        visibility: visible !important;
    }
    #printable-pharmacy-receipt {
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
    function printPharmacyReceipt(data) {
        document.getElementById('prx-modal-title').textContent = data.title || 'Pharmacy Receipt';
        document.getElementById('prx-header').textContent = data.header || 'Central Pharmacy & Dispensing Unit';
        document.getElementById('prx-badge-text').textContent = data.badge || (data.type === 'walk_in' ? 'DIRECT OTC RETAIL SALE' : 'DOCTOR PRESCRIPTION DISPENSED');
        
        const tokenElem = document.getElementById('prx-token');
        const tokenCont = document.getElementById('prx-token-container');
        if (data.token && data.token.trim() !== '') {
            tokenElem.textContent = data.token;
            tokenCont.classList.remove('hidden');
        } else {
            tokenCont.classList.add('hidden');
        }

        document.getElementById('prx-status').textContent = data.stamp || '✓ MEDICATIONS DISPENSED & VERIFIED';
        document.getElementById('prx-name').textContent = data.name || 'Walk-in Customer';
        
        const mrnRow = document.getElementById('prx-mrn-row');
        if (data.mrn && data.mrn !== 'N/A' && data.mrn !== 'Walk-in Customer') {
            document.getElementById('prx-mrn').textContent = data.mrn;
            mrnRow.classList.remove('hidden');
        } else {
            mrnRow.classList.add('hidden');
        }

        document.getElementById('prx-phone').textContent = data.phone || 'N/A';
        
        const docRow = document.getElementById('prx-doc-row');
        if (data.doctor && data.doctor !== 'N/A (Direct OTC)') {
            document.getElementById('prx-doctor').textContent = data.doctor;
            docRow.classList.remove('hidden');
        } else {
            docRow.classList.add('hidden');
        }

        document.getElementById('prx-inv').textContent = data.invoice_number || 'N/A';
        document.getElementById('prx-time').textContent = data.date_time || new Date().toLocaleString();

        // Populate items
        const itemsList = document.getElementById('prx-items-list');
        itemsList.innerHTML = '';
        if (data.items && Array.isArray(data.items) && data.items.length > 0) {
            data.items.forEach(it => {
                const itemDiv = document.createElement('div');
                itemDiv.className = 'border-b border-gray-100 pb-1 last:border-0';
                
                const medName = it.medication_name || it.name || 'Medication';
                const qty = it.quantity || it.qty_to_dispense || it.quantity_dispensed || 1;
                const price = it.total_price ? `$${parseFloat(it.total_price).toFixed(2)}` : (it.unit_price ? `$${(parseFloat(it.unit_price) * qty).toFixed(2)}` : '');
                const dosage = it.dosage_instructions ? `<div class="text-[9px] text-gray-500 italic pl-2">↳ ${it.dosage_instructions}</div>` : '';

                itemDiv.innerHTML = `
                    <div class="flex justify-between font-bold text-[10px]">
                        <span>• ${medName} (x${qty})</span>
                        <span class="font-mono">${price}</span>
                    </div>
                    ${dosage}
                `;
                itemsList.appendChild(itemDiv);
            });
        } else {
            itemsList.innerHTML = '<div class="text-gray-400 italic">No item details available</div>';
        }

        const totalAmt = data.net_total !== undefined ? `$${parseFloat(data.net_total).toFixed(2)}` : (data.subtotal ? `$${parseFloat(data.subtotal).toFixed(2)}` : '$0.00');
        document.getElementById('prx-total').textContent = totalAmt;

        const discRow = document.getElementById('prx-discount-row');
        if (data.discount && parseFloat(data.discount) > 0.001) {
            document.getElementById('prx-discount').textContent = `-$${parseFloat(data.discount).toFixed(2)}`;
            discRow.classList.remove('hidden');
        } else {
            discRow.classList.add('hidden');
        }

        const creditRow = document.getElementById('prx-credit-row');
        if (data.credit_applied && parseFloat(data.credit_applied) > 0.001) {
            document.getElementById('prx-credit').textContent = `-$${parseFloat(data.credit_applied).toFixed(2)}`;
            creditRow.classList.remove('hidden');
        } else {
            creditRow.classList.add('hidden');
        }

        const paidAmt = data.paid_amount ? `$${parseFloat(data.paid_amount).toFixed(2)}` : '$0.00';
        const method = data.payment_method ? ` (${data.payment_method})` : '';
        document.getElementById('prx-paid').textContent = paidAmt + method;

        const dueRow = document.getElementById('prx-due-row');
        if (data.due_amount && parseFloat(data.due_amount) > 0.01) {
            document.getElementById('prx-due').textContent = `$${parseFloat(data.due_amount).toFixed(2)}`;
            dueRow.classList.remove('hidden');
        } else {
            dueRow.classList.add('hidden');
        }

        document.getElementById('prx-notice').textContent = data.notice || 'Fadlan u qaado daawooyinka sida dhakhtarku kuu qoray.';
        document.getElementById('prx-subnotice').textContent = data.subnotice || 'Keep medications in a cool, dry place.';

        if (data.is_external) {
            document.getElementById('prx-total').textContent = '$0.00 (External Purchase)';
            document.getElementById('prx-paid').textContent = '$0.00 (External Fulfillment)';
        }

        document.getElementById('pharmacy-receipt-modal').classList.remove('hidden');
    }

    function closePharmacyReceiptModal() {
        document.getElementById('pharmacy-receipt-modal').classList.add('hidden');
    }

    function executePrintPharmacyReceipt() {
        window.print();
    }

    function openExternalPurchaseModal() {
        const modal = document.getElementById('modal-external-purchase');
        if (modal) modal.classList.remove('hidden');
    }

    function closeExternalPurchaseModal() {
        const modal = document.getElementById('modal-external-purchase');
        if (modal) modal.classList.add('hidden');
    }

    function printActivePrescriptionSlip() {
        <?php if (!empty($activePrescription)): ?>
        const rxSlip = {
            type: 'external_prescription',
            title: 'OFFICIAL MEDICAL PRESCRIPTION (RIKHEETO DAWO)',
            header: <?php echo json_encode(HOSPITAL_NAME . ' - Medical Prescription'); ?>,
            badge: 'OFFICIAL MEDICAL PRESCRIPTION',
            stamp: '✓ AUTHORIZED MEDICAL PRESCRIPTION',
            token: <?php echo json_encode($activePrescription['rx_number']); ?>,
            name: <?php echo json_encode($activePrescription['patient_name']); ?>,
            mrn: <?php echo json_encode($activePrescription['patient_mrn']); ?>,
            phone: <?php echo json_encode($activePrescription['patient_phone_dir'] ?? 'N/A'); ?>,
            doctor: <?php echo json_encode($activePrescription['doctor_name']); ?>,
            department: 'Outpatient Pharmacy Desk',
            items: <?php echo json_encode($activePrescription['items'] ?? []); ?>,
            subtotal: 0.00,
            discount: 0.00,
            credit_applied: 0.00,
            net_total: 0.00,
            paid_amount: 0.00,
            due_amount: 0.00,
            payment_method: 'EXTERNAL PHARMACY FULFILLMENT ($0.00)',
            invoice_number: <?php echo json_encode($activePrescription['rx_number']); ?>,
            notice: 'Rikheetadani waxay ansax ku tahay farmashiye kasta oo dibadda ah.',
            subnotice: 'This prescription is officially authorized for patient fulfillment at external pharmacies.',
            date_time: <?php echo json_encode(date('M d, Y g:i A')); ?>,
            is_external: true
        };
        printPharmacyReceipt(rxSlip);
        <?php endif; ?>
    }

    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($pharmacyReceiptPayload)): ?>
        printPharmacyReceipt(<?php echo json_encode($pharmacyReceiptPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
        <?php endif; ?>

        if (typeof window.initLiveSync === 'function') {
            window.initLiveSync({
                module: 'pharmacy_queue',
                targetSelector: '#pharmacy-queue-container',
                params: { rx_id: '<?php echo (int)($selectedRxId ?? 0); ?>' },
                intervalMs: 3500,
                notifyOnNew: true
            });
        }
    });
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
