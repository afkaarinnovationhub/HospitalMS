<?php
/**
 * MedCore Systems - Accounts Receivable (AR) & Patient Debt Ledger
 * Tracks all outstanding patient receivables, debt aging, and installment collections.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/AccountingOperation.php';
require_once __DIR__ . '/../OPERATIONS/PharmacyOperation.php';
require_once __DIR__ . '/../CONTROLS/PharmacyController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

$ar = AccountingOperation::getAccountsReceivableReport();
$aging = $ar['aging'];
$allAccounts = AccountingOperation::getAllCustomerAccountsWithFinancials();

// Filter accounts to ONLY those with Current Debt > $0.00
$debtorAccounts = array_values(array_filter($allAccounts, function ($acc) {
    return (float)($acc['balance_due'] ?? 0) > 0.005;
}));

// Pre-load AR Statements for Active Debtor Accounts
$arStatements = [];
foreach ($debtorAccounts as $acc) {
    $pId = !empty($acc['patient_id']) ? (int)$acc['patient_id'] : null;
    $invId = (int)($acc['invoice_id'] ?? 0);
    $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
    if (!isset($arStatements[$stmtKey])) {
        $stmtData = AccountingOperation::getCustomerARStatement($pId, $invId);
        if ($stmtData) {
            $arStatements[$stmtKey] = $stmtData;
        }
    }
}

$pageTitle = 'Accounts Receivable (AR) & Patient Statements - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Accounts Receivable';
$activePage = 'accounting';

include __DIR__ . '/../components/header.php';
?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-lg pb-6 bg-background custom-scrollbar">
    <!-- Notifications -->
    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
            <div>
                <p class="font-bold">Receivable Error</p>
                <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="mb-4 p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
            <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
            <div>
                <p class="font-bold">Debt Payment Collected</p>
                <p class="mt-0.5"><?php echo e($successMessage); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Title & Action Toolbar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <div class="flex items-center gap-2">
                <a href="accounting_dashboard.php" class="text-on-surface-variant hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-[22px]">arrow_back</span>
                </a>
                <h2 class="font-headline-md text-xl sm:text-2xl font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600 text-[28px]">person_pin</span>
                    Accounts Receivable
                </h2>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface font-bold rounded-xl text-xs flex items-center gap-1.5 transition-colors cursor-pointer shadow-xs">
                <span class="material-symbols-outlined text-[18px]">print</span>
                Print Report
            </button>
        </div>
    </div>

    <!-- Aging Summary KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total AR -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-on-surface-variant">Total Receivables</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$ar['total_receivable'], 2); ?></h3>
            </div>
        </div>

        <!-- 0 - 30 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-secondary">Current (0 - 30 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-secondary font-mono">$<?php echo number_format((float)$aging['current_0_30'], 2); ?></h3>
            </div>
        </div>

        <!-- 31 - 60 Days -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-amber-500">Aging (31 - 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-amber-600 font-mono">$<?php echo number_format((float)$aging['aging_31_60'], 2); ?></h3>
            </div>
        </div>

        <!-- 60+ Days Overdue -->
        <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex flex-col justify-between">
            <span class="text-xs font-semibold text-error">Overdue (> 60 Days)</span>
            <div class="mt-3">
                <h3 class="text-2xl font-bold text-error font-mono">$<?php echo number_format((float)$aging['over_60_days'], 2); ?></h3>
            </div>
        </div>
    </div>

    <!-- Simplified Outstanding Patient Debts List (Primary List) -->
    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs space-y-4 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-outline-variant pb-3">
            <div>
                <h3 class="font-bold text-sm text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[20px]">person_pin</span>
                    Outstanding Patient Debts
                </h3>
            </div>
            <span class="text-xs font-bold text-error bg-error-container/40 px-2.5 py-1 rounded-full w-fit">
                <?php echo count($debtorAccounts); ?> Debtor Account(s)
            </span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                        <th class="py-3 px-4">Patient / Customer Name</th>
                        <th class="py-3 px-4 text-right font-mono">Current Debt ($)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/60">
                    <?php if (empty($debtorAccounts)): ?>
                        <tr>
                            <td colspan="2" class="py-10 text-center text-on-surface-variant">
                                <span class="material-symbols-outlined text-[36px] text-secondary block mb-1">check_circle</span>
                                <p class="font-bold text-sm text-on-surface">No outstanding patient debts!</p>
                                <p class="text-xs text-on-surface-variant mt-0.5">All customer accounts are fully settled ($0.00 balance).</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($debtorAccounts as $acc): ?>
                            <?php 
                                $pId = !empty($acc['patient_id']) ? (int)$acc['patient_id'] : null;
                                $invId = (int)($acc['invoice_id'] ?? 0);
                                $stmtKey = $pId ? ('p_' . $pId) : ('inv_' . $invId);
                                $due = (float)$acc['balance_due'];
                            ?>
                            <tr onclick="openCustomerStatementModal('<?php echo $stmtKey; ?>')" 
                                class="hover:bg-primary/5 cursor-pointer transition-colors group">
                                <td class="py-3.5 px-4 font-bold text-on-surface group-hover:text-primary transition-colors">
                                    <div class="flex items-center gap-2.5">
                                        <span class="material-symbols-outlined text-primary text-[20px]">
                                            <?php echo $pId ? 'person' : 'shopping_bag'; ?>
                                        </span>
                                        <span class="text-sm font-semibold"><?php echo e($acc['customer_name']); ?></span>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 text-right font-mono font-bold text-error text-base">
                                    $<?php echo number_format($due, 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL: Patient & Customer AR Financial Statement -->
<div id="customer-statement-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-4xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl space-y-4 custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">receipt_long</span>
                <div>
                    <h3 id="stmt-patient-title" class="font-headline-sm text-base font-bold text-on-surface">Patient Financial Statement</h3>
                    <p id="stmt-patient-subtitle" class="text-xs text-on-surface-variant">Accounts Receivable &amp; Clinical Invoices</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printCustomerStatement()" class="px-3 py-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-on-surface rounded-lg text-xs font-bold flex items-center gap-1 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">print</span>
                    Print Statement
                </button>
                <button type="button" onclick="closeCustomerStatementModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">close</span>
                </button>
            </div>
        </div>

        <!-- Printable Statement Canvas -->
        <div id="printable-customer-statement-area" class="space-y-4 bg-surface p-4 rounded-xl border border-outline-variant">
            <div class="flex justify-between items-start border-b border-outline-variant pb-3">
                <div>
                    <h4 class="font-bold text-base text-primary uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h4>
                    <p class="text-xs text-on-surface-variant">Patient Billing &amp; Revenue Accounts Office • Tel: <?php echo htmlspecialchars(HOSPITAL_PHONE); ?></p>
                </div>
                <div class="text-right text-xs">
                    <p class="font-bold text-on-surface">Statement Date: <?php echo date('M d, Y'); ?></p>
                    <p class="text-on-surface-variant">Patient AR Ledger</p>
                </div>
            </div>

            <!-- Patient Info & Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 rounded-xl bg-surface-container-lowest border border-outline-variant">
                <div class="text-xs space-y-1">
                    <p class="font-bold text-on-surface text-sm" id="stmt-patient-name">Patient Name</p>
                    <p class="text-on-surface-variant" id="stmt-patient-mrn">MRN / ID: N/A</p>
                    <p class="text-on-surface-variant" id="stmt-patient-phone">Phone: N/A</p>
                    <p class="text-on-surface-variant" id="stmt-patient-address">Address: N/A</p>
                </div>
                <div class="flex justify-end gap-3 text-right">
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-on-surface-variant text-[10px] uppercase font-bold block">Total Invoiced</span>
                        <strong id="stmt-patient-tot-invoiced" class="text-sm font-mono font-bold text-on-surface">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-secondary text-[10px] uppercase font-bold block">Total Paid</span>
                        <strong id="stmt-patient-tot-paid" class="text-sm font-mono font-bold text-secondary">$0.00</strong>
                    </div>
                    <div class="p-2.5 rounded-lg bg-surface border border-outline-variant text-xs">
                        <span class="text-error text-[10px] uppercase font-bold block">Balance Due</span>
                        <strong id="stmt-patient-tot-due" class="text-sm font-mono font-bold text-error">$0.00</strong>
                    </div>
                </div>
            </div>

            <!-- Unified Customer Balance Detail / Running Statement Ledger -->
            <div class="space-y-2 mt-4">
                <div class="overflow-x-auto custom-scrollbar border border-outline-variant rounded-xl bg-white text-gray-900">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b-2 border-gray-800 text-gray-800 font-bold uppercase text-[11px] bg-gray-50">
                                <th class="py-2.5 px-3">Type</th>
                                <th class="py-2.5 px-3">Date</th>
                                <th class="py-2.5 px-3">Num</th>
                                <th class="py-2.5 px-3">Account</th>
                                <th class="py-2.5 px-3 text-right font-mono">Amount</th>
                                <th class="py-2.5 px-3 text-right font-mono">Balance</th>
                            </tr>
                        </thead>
                        <tbody id="stmt-ledger-tbody" class="divide-y divide-gray-200 text-xs">
                            <!-- Injected dynamically by JS -->
                        </tbody>
                        <tfoot id="stmt-ledger-tfoot" class="border-t-2 border-gray-800 font-bold bg-gray-50 text-xs">
                            <!-- Dynamic summary rows injected by JS -->
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const arStatementsData = <?php echo json_encode($arStatements, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function openCustomerStatementModal(stmtKey) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt || !stmt.patient) {
            alert('Statement data for this debtor could not be found.');
            return;
        }

        const p = stmt.patient;
        document.getElementById('stmt-patient-title').innerText = p.name + ' - Statement';
        document.getElementById('stmt-patient-subtitle').innerText = (p.type || 'Patient') + ' Financial Statement';
        document.getElementById('stmt-patient-name').innerText = p.name;
        document.getElementById('stmt-patient-mrn').innerText = 'MRN / ID: ' + (p.mrn || 'Walk-in');
        document.getElementById('stmt-patient-phone').innerText = 'Phone: ' + (p.phone || 'N/A');
        document.getElementById('stmt-patient-address').innerText = 'Address: ' + (p.address || 'N/A');

        document.getElementById('stmt-patient-tot-invoiced').innerText = '$' + parseFloat(stmt.total_invoiced).toFixed(2);
        document.getElementById('stmt-patient-tot-paid').innerText = '$' + parseFloat(stmt.total_paid).toFixed(2);
        document.getElementById('stmt-patient-tot-due').innerText = '$' + parseFloat(stmt.total_due).toFixed(2);

        // Build Unified Chronological Ledger (Invoices + Payments)
        const ledgerItems = [];

        // 1. Invoices (debts accrued: positive addition to AR)
        (stmt.invoices || []).forEach(inv => {
            // Calculate total subsequent debt installment payments for this invoice
            const debtPaymentsOnInv = (stmt.payments || []).filter(p => parseInt(p.invoice_id) === parseInt(inv.id)).reduce((s, p) => s + parseFloat(p.amount_paid || 0), 0);
            const initialDebt = parseFloat(inv.due_amount || 0) + debtPaymentsOnInv;

            // Only include invoices that generated debt in Accounts Receivable
            if (initialDebt <= 0.005) {
                return;
            }

            const rawDate = inv.created_at || '';
            ledgerItems.push({
                type: 'Invoice',
                rawDate: rawDate,
                date: formatDateLedgerAR(rawDate),
                num: inv.invoice_number || ('INV-' + inv.id),
                account: 'Accounts Recei...',
                amount: initialDebt,
                isPayment: false,
                invoiceId: inv.id,
                itemSummary: inv.item_summary || (inv.bill_type + ' Bill')
            });
        });

        // 2. Payments (installments / settlements collected: negative deduction to AR)
        (stmt.payments || []).forEach(pay => {
            const rawDate = pay.paid_at || '';
            ledgerItems.push({
                type: 'Payment',
                rawDate: rawDate,
                date: formatDateLedgerAR(rawDate),
                num: pay.receipt_number || ('REC-' + pay.id),
                account: 'Accounts Recei...',
                amount: -Math.abs(parseFloat(pay.amount_paid || 0)),
                isPayment: true,
                payId: pay.id,
                invoiceNumber: pay.invoice_number || ''
            });
        });

        // 3. Sort chronologically ascending (earliest to latest)
        ledgerItems.sort((a, b) => {
            if (a.rawDate < b.rawDate) return -1;
            if (a.rawDate > b.rawDate) return 1;
            if (a.type === 'Invoice' && b.type !== 'Invoice') return -1;
            if (a.type !== 'Invoice' && b.type === 'Invoice') return 1;
            return 0;
        });

        // 4. Compute running balance step-by-step
        let runningBalance = 0.0;
        ledgerItems.forEach(item => {
            runningBalance += item.amount;
            item.balance = runningBalance;
        });

        // 5. Render Ledger Rows into tbody
        const tbody = document.getElementById('stmt-ledger-tbody');
        tbody.innerHTML = '';

        if (ledgerItems.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="py-8 text-center text-gray-500">
                        <span class="material-symbols-outlined text-[32px] text-emerald-600 block mb-1">check_circle</span>
                        <p class="font-bold text-xs text-gray-800">No ledger transactions found</p>
                        <p class="text-[11px] text-gray-500">This debtor has no invoices or payments recorded.</p>
                    </td>
                </tr>
            `;
        } else {
            // Group Heading: Customer / Debtor Name (matches Caalami Hospital in reference image)
            const grpTr = document.createElement('tr');
            grpTr.className = 'bg-gray-50/80 border-b border-gray-200';
            grpTr.innerHTML = `
                <td colspan="6" class="py-2.5 px-3 font-extrabold text-gray-900 text-sm tracking-tight">
                    ${escapeHtmlAR(p.name || 'Customer')}
                </td>
            `;
            tbody.appendChild(grpTr);

            // Render each chronological transaction line
            ledgerItems.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-blue-50/60 cursor-pointer transition-colors group';

                if (item.isPayment) {
                    tr.onclick = () => viewReceiptDocument(stmtKey, item.payId);
                    tr.title = 'Click to view and print official payment receipt slip';
                    tr.innerHTML = `
                        <td class="py-2 px-3 font-semibold text-gray-800">Payment</td>
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewReceiptDocument('${stmtKey}', '${item.payId}')" 
                                    class="font-mono font-bold text-emerald-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">receipt_long</span>
                                ${escapeHtmlAR(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600 truncate max-w-[150px]">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-emerald-700">-${Math.abs(item.amount).toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                } else {
                    tr.onclick = () => viewInvoiceDocument(stmtKey, item.invoiceId);
                    tr.title = 'Click to view and print official medical invoice';
                    tr.innerHTML = `
                        <td class="py-2 px-3 font-semibold text-gray-800">Invoice</td>
                        <td class="py-2 px-3 text-gray-600 font-mono">${item.date}</td>
                        <td class="py-2 px-3">
                            <button type="button" 
                                    onclick="event.stopPropagation(); viewInvoiceDocument('${stmtKey}', ${item.invoiceId})" 
                                    class="font-mono font-bold text-blue-700 hover:underline inline-flex items-center gap-1 cursor-pointer">
                                <span class="material-symbols-outlined text-[13px]">description</span>
                                ${escapeHtmlAR(item.num)}
                            </button>
                        </td>
                        <td class="py-2 px-3 text-gray-600 truncate max-w-[150px]">${item.account}</td>
                        <td class="py-2 px-3 text-right font-mono font-semibold text-gray-900">${item.amount.toFixed(2)}</td>
                        <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${Math.abs(item.balance).toFixed(2)}</td>
                    `;
                }
                tbody.appendChild(tr);
            });
        }

        // 6. Render Ledger Summary Footers (matching user reference image)
        const tfoot = document.getElementById('stmt-ledger-tfoot');
        const finalBalance = (ledgerItems.length > 0) ? ledgerItems[ledgerItems.length - 1].balance : parseFloat(stmt.total_due || 0);
        const finalBalFormatted = Math.abs(finalBalance).toFixed(2);
        tfoot.innerHTML = `
            <tr class="border-t border-gray-400 font-bold">
                <td colspan="4" class="py-2 px-3 text-gray-900">Total ${escapeHtmlAR(p.name || 'Customer')}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-bold text-gray-900">${finalBalFormatted}</td>
            </tr>
            <tr class="border-b-4 border-double border-gray-900 bg-gray-100/70 font-extrabold">
                <td colspan="4" class="py-2 px-3 uppercase tracking-wider text-gray-900">TOTAL</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-gray-900">${finalBalFormatted}</td>
                <td class="py-2 px-3 text-right font-mono font-extrabold text-gray-900">${finalBalFormatted}</td>
            </tr>
        `;

        document.getElementById('customer-statement-modal').classList.remove('hidden');
    }

    function formatDateLedgerAR(raw) {
        if (!raw) return '-';
        const s = raw.substring(0, 10);
        const parts = s.split('-');
        if (parts.length === 3) {
            return `${parts[1]}/${parts[2]}/${parts[0]}`; // MM/DD/YYYY format matching sample image
        }
        return s;
    }

    function closeCustomerStatementModal() {
        document.getElementById('customer-statement-modal').classList.add('hidden');
    }

    function printCustomerStatement() {
        const target = document.getElementById('printable-customer-statement-area');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // --- INVOICE DOCUMENT VIEW HANDLERS ---
    function viewInvoiceDocument(stmtKey, invoiceId) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt) return;
        const inv = (stmt.invoices || []).find(i => parseInt(i.id) === parseInt(invoiceId));
        if (!inv) {
            alert('Invoice details could not be found.');
            return;
        }

        const p = stmt.patient || {};
        document.getElementById('inv-doc-number').textContent = 'Invoice #: ' + inv.invoice_number;
        document.getElementById('inv-doc-date').textContent = 'Date: ' + (inv.created_at ? inv.created_at.substring(0, 16) : '-');
        document.getElementById('inv-doc-patient').textContent = p.name || inv.customer_name || 'Patient';
        document.getElementById('inv-doc-mrn').textContent = p.mrn || 'N/A';
        document.getElementById('inv-doc-phone').textContent = p.phone || inv.customer_phone || 'N/A';
        document.getElementById('inv-doc-billtype').textContent = (inv.bill_type || 'Clinical') + ' Bill';

        // Populate items
        const tbody = document.getElementById('inv-doc-items-tbody');
        tbody.innerHTML = '';
        if (inv.items && inv.items.length > 0) {
            inv.items.forEach(it => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="py-1.5 text-gray-800">
                        <strong class="text-xs">${escapeHtmlAR(it.item_name)}</strong>
                        ${it.item_description ? `<span class="block text-[10px] text-gray-500">${escapeHtmlAR(it.item_description)}</span>` : ''}
                    </td>
                    <td class="py-1.5 text-right font-mono">${parseInt(it.quantity) || 1}</td>
                    <td class="py-1.5 text-right font-mono">$${parseFloat(it.unit_price).toFixed(2)}</td>
                    <td class="py-1.5 text-right font-mono font-bold">$${parseFloat(it.total_price).toFixed(2)}</td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-1.5 text-gray-800"><strong class="text-xs">${escapeHtmlAR(inv.item_summary || (inv.bill_type + ' charges'))}</strong></td>
                <td class="py-1.5 text-right font-mono">1</td>
                <td class="py-1.5 text-right font-mono">$${parseFloat(inv.net_total).toFixed(2)}</td>
                <td class="py-1.5 text-right font-mono font-bold">$${parseFloat(inv.net_total).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        }

        document.getElementById('inv-doc-subtotal').textContent = '$' + parseFloat(inv.subtotal || inv.net_total).toFixed(2);
        const discRow = document.getElementById('inv-doc-discount-row');
        if (inv.discount && parseFloat(inv.discount) > 0.001) {
            document.getElementById('inv-doc-discount').textContent = '-$' + parseFloat(inv.discount).toFixed(2);
            discRow.classList.remove('hidden');
        } else {
            discRow.classList.add('hidden');
        }
        document.getElementById('inv-doc-net').textContent = '$' + parseFloat(inv.net_total).toFixed(2);
        document.getElementById('inv-doc-paid').textContent = '$' + parseFloat(inv.paid_amount).toFixed(2);
        document.getElementById('inv-doc-due').textContent = '$' + parseFloat(inv.due_amount).toFixed(2);

        const dueVal = parseFloat(inv.due_amount || 0);
        document.getElementById('inv-doc-footer-status').textContent = (dueVal <= 0.005) 
            ? '✓ PAID IN FULL — Thank You' 
            : `⚠️ OUTSTANDING BALANCE DUE: $${dueVal.toFixed(2)}`;

        document.getElementById('invoice-document-modal').classList.remove('hidden');
    }

    function closeInvoiceDocumentModal() {
        document.getElementById('invoice-document-modal').classList.add('hidden');
    }

    function printInvoiceDocument() {
        const target = document.getElementById('printable-invoice-document');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    // --- RECEIPT DOCUMENT VIEW HANDLERS ---
    function viewReceiptDocument(stmtKey, payId) {
        const stmt = arStatementsData[stmtKey];
        if (!stmt) return;
        const pay = (stmt.payments || []).find(p => String(p.id) === String(payId) || String(p.receipt_number) === String(payId));
        if (!pay) {
            alert('Receipt details could not be found.');
            return;
        }

        const p = stmt.patient || {};
        document.getElementById('rec-doc-number').textContent = pay.receipt_number || ('REC-' + pay.id);
        document.getElementById('rec-doc-date').textContent = 'Date: ' + (pay.paid_at || '-');
        document.getElementById('rec-doc-patient').textContent = p.name || 'Patient';
        document.getElementById('rec-doc-mrn').textContent = p.mrn || 'N/A';
        document.getElementById('rec-doc-inv').textContent = pay.invoice_number || '-';
        document.getElementById('rec-doc-method').textContent = (pay.payment_method || 'CASH').toUpperCase();
        document.getElementById('rec-doc-cashier').textContent = pay.cashier_name || 'Cashier Desk';

        document.getElementById('rec-doc-prev').textContent = '$' + parseFloat(pay.previous_balance || 0).toFixed(2);
        document.getElementById('rec-doc-paid').textContent = '+$' + parseFloat(pay.amount_paid || 0).toFixed(2);
        document.getElementById('rec-doc-rem').textContent = '$' + parseFloat(pay.remaining_balance || 0).toFixed(2);

        const notesBox = document.getElementById('rec-doc-notes-container');
        if (pay.notes && pay.notes.trim() !== '') {
            document.getElementById('rec-doc-notes').textContent = pay.notes;
            notesBox.classList.remove('hidden');
        } else {
            notesBox.classList.add('hidden');
        }

        document.getElementById('receipt-document-modal').classList.remove('hidden');
    }

    function closeReceiptDocumentModal() {
        document.getElementById('receipt-document-modal').classList.add('hidden');
    }

    function printReceiptDocument() {
        const target = document.getElementById('printable-receipt-document');
        target.classList.add('print-target-active');
        window.print();
        target.classList.remove('print-target-active');
    }

    function escapeHtmlAR(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
</script>

<!-- MODAL: Invoice Document View -->
<div id="invoice-document-modal" class="fixed inset-0 z-[60] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[92vh] overflow-y-auto space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px] text-primary">description</span>
                Official Medical Invoice
            </span>
            <button type="button" onclick="closeInvoiceDocumentModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Invoice Sheet Area -->
        <div id="printable-invoice-document" class="space-y-4 text-xs bg-white text-black p-5 rounded-xl border border-gray-200">
            <div class="text-center border-b border-gray-300 pb-3">
                <h3 class="font-bold text-base text-gray-900 uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h3>
                <p class="text-[11px] text-gray-600"><?php echo htmlspecialchars(defined('HOSPITAL_TAGLINE') ? HOSPITAL_TAGLINE : 'Clinical Services & Outpatient Department'); ?></p>
                <p class="text-[10px] text-gray-500 font-mono"><?php echo htmlspecialchars(HOSPITAL_PHONE); ?> &bull; <?php echo htmlspecialchars(HOSPITAL_ADDRESS); ?></p>
                <div class="mt-2 flex justify-between text-[11px] font-mono border-t border-dashed border-gray-300 pt-1.5">
                    <span id="inv-doc-number" class="font-bold text-primary">INV: -</span>
                    <span id="inv-doc-date" class="text-gray-600">Date: -</span>
                </div>
            </div>

            <!-- Patient Demographic Info -->
            <div class="space-y-1 text-xs text-gray-700">
                <div class="flex justify-between"><span class="text-gray-500">Patient:</span> <strong id="inv-doc-patient" class="text-gray-900">-</strong></div>
                <div class="flex justify-between"><span class="text-gray-500">MRN / ID:</span> <span id="inv-doc-mrn" class="font-mono">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Phone:</span> <span id="inv-doc-phone" class="font-mono">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Bill Type:</span> <span id="inv-doc-billtype" class="capitalize font-semibold text-gray-900">-</span></div>
            </div>

            <!-- Itemized Table -->
            <div class="border-t border-b border-gray-300 py-2">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-gray-500 border-b border-gray-200 text-[10px] uppercase font-bold">
                            <th class="py-1">Description</th>
                            <th class="py-1 text-right">Qty</th>
                            <th class="py-1 text-right">Unit ($)</th>
                            <th class="py-1 text-right">Total ($)</th>
                        </tr>
                    </thead>
                    <tbody id="inv-doc-items-tbody" class="divide-y divide-gray-100">
                        <!-- Items injected by JS -->
                    </tbody>
                </table>
            </div>

            <!-- Summary -->
            <div class="space-y-1 font-bold text-xs text-gray-800">
                <div class="flex justify-between"><span class="text-gray-600">Subtotal:</span> <span id="inv-doc-subtotal" class="font-mono">$0.00</span></div>
                <div id="inv-doc-discount-row" class="flex justify-between text-green-700 hidden"><span>Discount:</span> <span id="inv-doc-discount" class="font-mono">-$0.00</span></div>
                <div class="flex justify-between border-t border-gray-200 pt-1 font-extrabold text-gray-900"><span>Net Total:</span> <span id="inv-doc-net" class="font-mono">$0.00</span></div>
                <div class="flex justify-between text-green-700"><span>Paid to Date:</span> <span id="inv-doc-paid" class="font-mono">$0.00</span></div>
                <div class="flex justify-between text-red-600 font-extrabold text-sm"><span>Balance Due:</span> <span id="inv-doc-due" class="font-mono">$0.00</span></div>
            </div>

            <div class="text-center pt-3 border-t border-gray-300 text-[10px] text-gray-500">
                <p>Thank you for choosing <?php echo htmlspecialchars(HOSPITAL_NAME); ?>.</p>
                <p class="font-mono mt-0.5" id="inv-doc-footer-status">Official Clinical Billing Document</p>
            </div>
        </div>

        <div class="pt-2 border-t border-outline-variant flex justify-end gap-2">
            <button type="button" onclick="closeInvoiceDocumentModal()" class="px-3.5 py-1.5 bg-surface-container text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
            <button type="button" onclick="printInvoiceDocument()" class="px-4 py-1.5 bg-primary text-on-primary rounded-xl text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Invoice
            </button>
        </div>
    </div>
</div>

<!-- MODAL: Receipt Document View -->
<div id="receipt-document-modal" class="fixed inset-0 z-[60] bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-md w-full p-6 shadow-2xl custom-scrollbar max-h-[92vh] overflow-y-auto space-y-4">
        <div class="flex justify-between items-center pb-2 border-b border-outline-variant">
            <span class="text-xs font-bold text-on-surface flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[18px] text-secondary">receipt_long</span>
                Official Payment Receipt
            </span>
            <button type="button" onclick="closeReceiptDocumentModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Printable Receipt Slip Area -->
        <div id="printable-receipt-document" class="space-y-4 text-xs bg-white text-black p-5 rounded-xl border border-dashed border-gray-300 font-mono">
            <div class="text-center border-b border-gray-300 pb-3 space-y-0.5">
                <h3 class="font-bold text-base text-gray-900 uppercase"><?php echo htmlspecialchars(HOSPITAL_NAME); ?></h3>
                <p class="text-[11px] text-gray-600"><?php echo htmlspecialchars(defined('HOSPITAL_TAGLINE') ? HOSPITAL_TAGLINE : 'Revenue & Accounts Receivable Department'); ?></p>
                <p class="text-[10px] text-gray-500"><?php echo htmlspecialchars(HOSPITAL_PHONE); ?> &bull; <?php echo htmlspecialchars(HOSPITAL_ADDRESS); ?></p>
                <div class="border-t border-dashed border-gray-300 mt-2 pt-2">
                    <p class="font-bold text-xs text-gray-900">PAYMENT RECEIPT / CONFIRMATION SLIP</p>
                    <p id="rec-doc-number" class="text-xs font-bold text-primary mt-0.5">REC: -</p>
                    <p id="rec-doc-date" class="text-[10px] text-gray-500">Date: -</p>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="space-y-1.5 text-xs text-gray-800">
                <div class="flex justify-between"><span class="text-gray-500">Patient:</span> <strong id="rec-doc-patient" class="text-gray-900 font-sans font-bold">-</strong></div>
                <div class="flex justify-between"><span class="text-gray-500">MRN:</span> <span id="rec-doc-mrn">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Invoice Ref:</span> <span id="rec-doc-inv" class="font-bold">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Payment Method:</span> <span id="rec-doc-method" class="font-bold uppercase text-gray-900">-</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Received By:</span> <span id="rec-doc-cashier">-</span></div>
            </div>

            <!-- Financial Settlement Box -->
            <div class="border-t border-b border-dashed border-gray-300 py-2.5 space-y-1 font-bold">
                <div class="flex justify-between text-gray-600"><span>Previous Balance:</span> <span id="rec-doc-prev" class="font-mono">$0.00</span></div>
                <div class="flex justify-between text-green-700 text-sm border-t border-gray-200 pt-1"><span>Amount Paid:</span> <span id="rec-doc-paid" class="font-mono">+$0.00</span></div>
                <div class="flex justify-between text-red-600"><span>Remaining Balance:</span> <span id="rec-doc-rem" class="font-mono">$0.00</span></div>
            </div>

            <div id="rec-doc-notes-container" class="text-[10px] text-gray-600 hidden">
                <span class="text-gray-500">Notes:</span> <span id="rec-doc-notes">-</span>
            </div>

            <div class="text-center pt-2 border-t border-dashed border-gray-300 text-[10px] text-gray-500 space-y-0.5">
                <p class="font-bold text-green-700">✓ PAYMENT VERIFIED &amp; ACCOUNT CREDITED</p>
                <p>Thank you for your payment.</p>
            </div>
        </div>

        <div class="pt-2 border-t border-outline-variant flex justify-end gap-2">
            <button type="button" onclick="closeReceiptDocumentModal()" class="px-3.5 py-1.5 bg-surface-container text-on-surface rounded-xl text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                Close
            </button>
            <button type="button" onclick="printReceiptDocument()" class="px-4 py-1.5 bg-secondary text-on-secondary rounded-xl text-xs font-bold hover:bg-secondary-container shadow-xs cursor-pointer flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">print</span>
                Print Receipt Slip
            </button>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    .print-target-active, .print-target-active * {
        visibility: visible !important;
    }
    .print-target-active {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 20px !important;
        background: white !important;
        color: black !important;
        box-shadow: none !important;
        border: none !important;
    }
}
</style>

<?php include __DIR__ . '/../components/footer.php'; ?>
