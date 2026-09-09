<?php
/**
 * MedCore Systems - Real Hospital Analytics & Executive Reporting Engine
 * Comprehensive database-driven reporting across clinical operations, physician productivity,
 * laboratory diagnostics, pharmacy dispensary, inventory health, and financial revenue cycles.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/ReportOperation.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

// Determine Date Range Filter
$rangePreset = sanitizeString($_GET['range'] ?? 'month');
$fromDate = null;
$toDate = null;

$today = date('Y-m-d');
switch ($rangePreset) {
    case 'today':
        $fromDate = $today;
        $toDate = $today;
        $rangeLabel = "Today (" . date('M d, Y') . ")";
        break;
    case 'week':
        $fromDate = date('Y-m-d', strtotime('-7 days'));
        $toDate = $today;
        $rangeLabel = "Last 7 Days (" . date('M d', strtotime('-7 days')) . " - " . date('M d, Y') . ")";
        break;
    case 'month':
        $fromDate = date('Y-m-01');
        $toDate = date('Y-m-t');
        $rangeLabel = "This Month (" . date('F Y') . ")";
        break;
    case 'year':
        $fromDate = date('Y-01-01');
        $toDate = date('Y-12-31');
        $rangeLabel = "This Year (" . date('Y') . ")";
        break;
    case 'all':
        $fromDate = null;
        $toDate = null;
        $rangeLabel = "All Time Recorded History";
        break;
    case 'custom':
        $customFrom = sanitizeString($_GET['from_date'] ?? '');
        $customTo   = sanitizeString($_GET['to_date'] ?? '');
        if (!empty($customFrom) && !empty($customTo)) {
            $fromDate = $customFrom;
            $toDate   = $customTo;
            $rangeLabel = "Custom Range (" . date('M d, Y', strtotime($customFrom)) . " - " . date('M d, Y', strtotime($customTo)) . ")";
        } else {
            $fromDate = date('Y-m-01');
            $toDate   = date('Y-m-t');
            $rangeLabel = "This Month (" . date('F Y') . ")";
            $rangePreset = 'month';
        }
        break;
    default:
        $fromDate = date('Y-m-01');
        $toDate   = date('Y-m-t');
        $rangeLabel = "This Month (" . date('F Y') . ")";
        $rangePreset = 'month';
        break;
}

// Active Tab
$activeTab = sanitizeString($_GET['tab'] ?? 'overview');

// 1. Fetch Executive KPIs
$kpis = ReportOperation::getExecutiveKpis($fromDate, $toDate);

// 2. Fetch Monthly Trends (Last 6 Months)
$monthlyTrends = ReportOperation::getMonthlyFinancialTrends(6);
$maxMonthlyRev = max(array_merge([1], array_column($monthlyTrends, 'revenue'), array_column($monthlyTrends, 'expenses')));

// 3. Department Volume Breakdown
$deptVolume = ReportOperation::getDepartmentVolumeBreakdown($fromDate, $toDate);

// 4. Physician Productivity
$physicians = ReportOperation::getPhysicianProductivityReport($fromDate, $toDate);

// 5. Top Diagnoses
$topDiagnoses = ReportOperation::getTopDiagnosesReport($fromDate, $toDate, 6);

// 6. Laboratory Diagnostics
$labAnalytics = ReportOperation::getLaboratoryAnalyticsReport($fromDate, $toDate);

// 7. Pharmacy Top Sales
$pharmacyTopSales = ReportOperation::getPharmacySalesReport($fromDate, $toDate, 10);

// 8. Inventory Valuation
$inventorySummary        = ReportOperation::getInventoryValuationSummary();
$pharmacyInventoryReport = ($activeTab === 'pharmacy') ? ReportOperation::getPharmacyInventorySummaryReport() : [];
$pharmacyBatchReport     = ($activeTab === 'pharmacy') ? ReportOperation::getPharmacyBatchDetailReport() : [];

// 9. Revenue Streams & Payment Methods
$revenueStreams = ReportOperation::getRevenueStreamsBreakdown($fromDate, $toDate);
$paymentMethods = ReportOperation::getPaymentMethodsBreakdown($fromDate, $toDate);

// 10. Recent Activity Feed
$recentActivities = ReportOperation::getRecentAuditActivities(8);

// Handle CSV Export
if (($_GET['action'] ?? '') === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="MedCore_Hospital_Report_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');

    // Header
    fputcsv($output, ['MedCore Systems - Executive Hospital Performance Report']);
    fputcsv($output, ['Reporting Period', $rangeLabel]);
    fputcsv($output, ['Generated Date', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    // KPI Summary
    fputcsv($output, ['--- EXECUTIVE SUMMARY METRICS ---']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Hospital Revenue', '$' . number_format($kpis['total_revenue'], 2)]);
    fputcsv($output, ['Total Patient Encounters', $kpis['total_encounters']]);
    fputcsv($output, ['Total Consultations', $kpis['total_consultations']]);
    fputcsv($output, ['Completed Lab Tests', $kpis['completed_lab_orders']]);
    fputcsv($output, ['Pharmacy Gross Sales', '$' . number_format($kpis['pharmacy_sales_total'], 2)]);
    fputcsv($output, ['Pharmacy Gross Profit', '$' . number_format($kpis['pharmacy_margin'], 2)]);
    fputcsv($output, ['Hospital Operating Expenses', '$' . number_format($kpis['total_expenses'], 2)]);
    fputcsv($output, ['Net Operating Profit', '$' . number_format($kpis['net_profit'], 2)]);
    fputcsv($output, []);

    // Physician Productivity
    fputcsv($output, ['--- PHYSICIAN PRODUCTIVITY ---']);
    fputcsv($output, ['Doctor Name', 'Title / Specialty', 'Consultation Fee', 'Consultations', 'Completed', 'Unique Patients', 'Revenue Generated']);
    foreach ($physicians as $doc) {
        fputcsv($output, [
            $doc['doctor_name'],
            $doc['professional_title'] ?: 'Clinical Staff',
            '$' . number_format((float)$doc['consultation_fee'], 2),
            $doc['total_consultations'],
            $doc['completed_consultations'],
            $doc['unique_patients'],
            '$' . number_format((float)$doc['total_revenue_generated'], 2),
        ]);
    }
    fputcsv($output, []);

    // Lab Diagnostics
    fputcsv($output, ['--- LABORATORY DIAGNOSTIC SUMMARY ---']);
    fputcsv($output, ['Test Name', 'Standard Fee', 'Total Requested', 'Completed', 'Pending', 'Abnormal Rate', 'Revenue']);
    foreach ($labAnalytics as $lab) {
        fputcsv($output, [
            $lab['test_name'],
            '$' . number_format((float)$lab['test_price'], 2),
            $lab['total_requested'],
            $lab['total_completed'],
            $lab['total_pending'],
            $lab['abnormal_findings'],
            '$' . number_format((float)$lab['total_revenue'], 2),
        ]);
    }
    fputcsv($output, []);

    // Top Dispensed Medicines
    fputcsv($output, ['--- TOP DISPENSED MEDICATIONS ---']);
    fputcsv($output, ['Med Code', 'Medication Name', 'Category', 'Quantity Dispensed', 'Gross Sales', 'COGS', 'Gross Profit']);
    foreach ($pharmacyTopSales as $med) {
        fputcsv($output, [
            $med['med_code'],
            $med['med_name'] ?? $med['medication_name'],
            $med['category'],
            $med['total_quantity_dispensed'],
            '$' . number_format((float)$med['total_gross_sales'], 2),
            '$' . number_format((float)$med['total_cogs'], 2),
            '$' . number_format((float)$med['gross_profit'], 2),
        ]);
    }

    fclose($output);
    exit;
}

$pageTitle = 'Executive Reports & Analytics - MedCore Systems';
$headerTitle = 'MedCore Management - Reports';
$activePage = 'reports';

include __DIR__ . '/../components/header.php';
?>

<!-- Custom Print Styling -->
<style>
    @media print {
        body { background: #fff !important; color: #000 !important; }
        #app-sidebar, #app-header, #filter-toolbar, #tab-navigation, .no-print { display: none !important; }
        main { padding: 0 !important; margin: 0 !important; overflow: visible !important; }
        .print-only { display: block !important; }
        .tab-content { display: block !important; margin-bottom: 24px !important; }
        .shadow-xs, .shadow-sm, .shadow-md { box-shadow: none !important; }
        .border { border: 1px solid #ddd !important; }
        table { width: 100% !important; border-collapse: collapse !important; font-size: 11px !important; }
        th, td { border: 1px solid #ccc !important; padding: 6px 8px !important; }
        .page-break { page-break-after: always; }
    }
    .print-only { display: none; }
</style>

<!-- Reports & Analytics Main Canvas -->
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-margin-desktop pb-6 bg-background custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-6">

        <!-- Printable Header (Visible Only When Printed) -->
        <div class="print-only mb-6 pb-4 border-b-2 border-primary">
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-2xl font-bold text-primary">MedCore Systems Hospital</h1>
                    <p class="text-xs text-gray-600 font-medium">Executive Clinical, Operational &amp; Financial Performance Report</p>
                </div>
                <div class="text-right text-xs text-gray-600 font-mono">
                    <p><strong>Reporting Period:</strong> <?php echo e($rangeLabel); ?></p>
                    <p><strong>Generated At:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </div>
        </div>

        <!-- Page Header & Action Bar -->
        <div id="filter-toolbar" class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 bg-surface border border-outline-variant rounded-2xl p-4 sm:p-5 shadow-xs">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[28px]">assessment</span>
                    Hospital Reports &amp; Analytics
                </h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-0.5">
                    Welcome back, <strong class="text-primary font-bold"><?php echo e($currentUser['full_name'] ?? 'Executive Auditor'); ?></strong> &bull; Period: <span class="font-bold text-primary font-mono"><?php echo e($rangeLabel); ?></span>
                </p>
            </div>

            <!-- Date Range Controls & Export Actions -->
            <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
                <!-- Preset Quick Buttons -->
                <div class="flex items-center bg-surface-container-low border border-outline-variant rounded-xl p-1 text-xs">
                    <a href="report.php?range=today&tab=<?php echo urlencode($activeTab); ?>" class="px-2.5 py-1.5 rounded-lg font-semibold transition-colors <?php echo ($rangePreset === 'today') ? 'bg-primary text-on-primary shadow-2xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">Today</a>
                    <a href="report.php?range=week&tab=<?php echo urlencode($activeTab); ?>" class="px-2.5 py-1.5 rounded-lg font-semibold transition-colors <?php echo ($rangePreset === 'week') ? 'bg-primary text-on-primary shadow-2xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">7 Days</a>
                    <a href="report.php?range=month&tab=<?php echo urlencode($activeTab); ?>" class="px-2.5 py-1.5 rounded-lg font-semibold transition-colors <?php echo ($rangePreset === 'month') ? 'bg-primary text-on-primary shadow-2xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">This Month</a>
                    <a href="report.php?range=year&tab=<?php echo urlencode($activeTab); ?>" class="px-2.5 py-1.5 rounded-lg font-semibold transition-colors <?php echo ($rangePreset === 'year') ? 'bg-primary text-on-primary shadow-2xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">This Year</a>
                    <a href="report.php?range=all&tab=<?php echo urlencode($activeTab); ?>" class="px-2.5 py-1.5 rounded-lg font-semibold transition-colors <?php echo ($rangePreset === 'all') ? 'bg-primary text-on-primary shadow-2xs' : 'text-on-surface-variant hover:text-on-surface'; ?>">All Time</a>
                </div>

                <!-- Custom Date Trigger Button -->
                <button type="button" onclick="document.getElementById('custom-date-modal').classList.remove('hidden')" class="px-3 py-2 bg-surface-container-low border border-outline-variant hover:border-primary rounded-xl text-xs font-semibold text-on-surface flex items-center gap-1 cursor-pointer" title="Select Custom Date Range">
                    <span class="material-symbols-outlined text-[16px]">calendar_today</span>
                    <span>Custom</span>
                </button>

                <!-- Export CSV Button -->
                <a href="report.php?action=export_csv&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" class="px-3 py-2 bg-surface-container hover:bg-surface-container-high border border-outline-variant rounded-xl text-xs font-bold text-on-surface transition-colors flex items-center gap-1 cursor-pointer shadow-2xs" title="Export as CSV Spreadsheet">
                    <span class="material-symbols-outlined text-[17px] text-secondary">file_download</span>
                    <span>Export CSV</span>
                </a>

                <!-- Print Report Button -->
                <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-container rounded-xl text-xs font-bold transition-colors flex items-center gap-1.5 shadow-2xs cursor-pointer font-sans" title="Print Executive Summary">
                    <span class="material-symbols-outlined text-[17px]">print</span>
                    <span>Print Report</span>
                </button>
            </div>
        </div>

        <!-- Executive KPI Overview Bento Cards (Real Database Data) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 sm:gap-4">
            <!-- 1. Total Hospital Revenue -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Total Revenue</span>
                    <div class="h-8 w-8 rounded-lg bg-primary-container text-on-primary-container flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">payments</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold text-primary">$<?php echo number_format($kpis['total_revenue'], 2); ?></div>
                    <p class="text-[10px] text-on-surface-variant mt-0.5">Collected in period</p>
                </div>
            </div>

            <!-- 2. Total Patient Encounters -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Encounters</span>
                    <div class="h-8 w-8 rounded-lg bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">groups</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold text-on-surface"><?php echo number_format($kpis['total_encounters']); ?></div>
                    <p class="text-[10px] text-secondary font-medium mt-0.5"><?php echo $kpis['total_consultations']; ?> Doctor Consults</p>
                </div>
            </div>

            <!-- 3. Lab Tests Completed -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Lab Diagnostics</span>
                    <div class="h-8 w-8 rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-300 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">biotech</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold text-on-surface"><?php echo number_format($kpis['completed_lab_orders']); ?></div>
                    <p class="text-[10px] text-on-surface-variant mt-0.5">$<?php echo number_format($kpis['lab_revenue'], 2); ?> Revenue</p>
                </div>
            </div>

            <!-- 4. Pharmacy Dispensary Sales -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Pharmacy Sales</span>
                    <div class="h-8 w-8 rounded-lg bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">medication</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold text-on-surface">$<?php echo number_format($kpis['pharmacy_sales_total'], 2); ?></div>
                    <p class="text-[10px] text-emerald-600 font-medium mt-0.5">+$<?php echo number_format($kpis['pharmacy_margin'], 2); ?> Margin</p>
                </div>
            </div>

            <!-- 5. Hospital Expenses -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Operating Expenses</span>
                    <div class="h-8 w-8 rounded-lg bg-error-container text-on-error-container flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">receipt_long</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold text-error">$<?php echo number_format($kpis['total_expenses'], 2); ?></div>
                    <p class="text-[10px] text-on-surface-variant mt-0.5">Hospital disbursements</p>
                </div>
            </div>

            <!-- 6. Net Hospital Profit -->
            <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <span class="text-on-surface-variant font-label-md text-[10px] uppercase tracking-wider font-bold">Net Profit</span>
                    <div class="h-8 w-8 rounded-lg <?php echo ($kpis['net_profit'] >= 0) ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error/20 text-error'; ?> flex items-center justify-center">
                        <span class="material-symbols-outlined text-[18px]">trending_up</span>
                    </div>
                </div>
                <div>
                    <div class="font-mono text-xl sm:text-2xl font-bold <?php echo ($kpis['net_profit'] >= 0) ? 'text-secondary' : 'text-error'; ?>">
                        $<?php echo number_format($kpis['net_profit'], 2); ?>
                    </div>
                    <p class="text-[10px] text-on-surface-variant mt-0.5">Net clinical surplus</p>
                </div>
            </div>
        </div>

        <!-- 5-Tab Navigation Bar -->
        <div id="tab-navigation" class="border-b border-outline-variant flex items-center gap-1 sm:gap-2 overflow-x-auto custom-scrollbar pb-px">
            <a href="report.php?tab=overview&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" 
               class="px-4 py-2.5 rounded-t-xl font-bold text-xs sm:text-sm whitespace-nowrap transition-all border-b-2 flex items-center gap-1.5 <?php echo ($activeTab === 'overview') ? 'border-primary text-primary bg-surface shadow-2xs font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-container-low'; ?>">
                <span class="material-symbols-outlined text-[18px]">dashboard</span>
                Executive Overview
            </a>

            <a href="report.php?tab=physicians&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" 
               class="px-4 py-2.5 rounded-t-xl font-bold text-xs sm:text-sm whitespace-nowrap transition-all border-b-2 flex items-center gap-1.5 <?php echo ($activeTab === 'physicians') ? 'border-primary text-primary bg-surface shadow-2xs font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-container-low'; ?>">
                <span class="material-symbols-outlined text-[18px]">stethoscope</span>
                Physician Productivity
            </a>

            <a href="report.php?tab=laboratory&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" 
               class="px-4 py-2.5 rounded-t-xl font-bold text-xs sm:text-sm whitespace-nowrap transition-all border-b-2 flex items-center gap-1.5 <?php echo ($activeTab === 'laboratory') ? 'border-primary text-primary bg-surface shadow-2xs font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-container-low'; ?>">
                <span class="material-symbols-outlined text-[18px]">biotech</span>
                Laboratory Diagnostics
            </a>

            <a href="report.php?tab=pharmacy&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" 
               class="px-4 py-2.5 rounded-t-xl font-bold text-xs sm:text-sm whitespace-nowrap transition-all border-b-2 flex items-center gap-1.5 <?php echo ($activeTab === 'pharmacy') ? 'border-primary text-primary bg-surface shadow-2xs font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-container-low'; ?>">
                <span class="material-symbols-outlined text-[18px]">inventory_2</span>
                Pharmacy &amp; Inventory Health
            </a>

            <a href="report.php?tab=finance&range=<?php echo urlencode($rangePreset); ?>&from_date=<?php echo urlencode($fromDate ?? ''); ?>&to_date=<?php echo urlencode($toDate ?? ''); ?>" 
               class="px-4 py-2.5 rounded-t-xl font-bold text-xs sm:text-sm whitespace-nowrap transition-all border-b-2 flex items-center gap-1.5 <?php echo ($activeTab === 'finance') ? 'border-primary text-primary bg-surface shadow-2xs font-semibold' : 'border-transparent text-on-surface-variant hover:text-on-surface hover:bg-surface-container-low'; ?>">
                <span class="material-symbols-outlined text-[18px]">account_balance</span>
                Financial Cycles &amp; Streams
            </a>
        </div>

        <!-- ================= TAB 1: EXECUTIVE OVERVIEW ================= -->
        <?php if ($activeTab === 'overview'): ?>
            <div class="space-y-6 tab-content">
                <!-- Charts Section: Revenue vs Expenses (Real Monthly Trends) & Department Volume -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Dynamic Bar Chart: Monthly Financial Comparison -->
                    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs flex flex-col justify-between">
                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <h3 class="font-headline-sm text-base font-bold text-on-surface">Revenue vs. Operating Expenses</h3>
                                <p class="text-xs text-on-surface-variant">Live monthly performance for the past 6 months</p>
                            </div>
                            <span class="text-xs font-mono font-bold text-primary bg-primary/10 px-2.5 py-1 rounded-lg">6 Months</span>
                        </div>

                        <!-- Bar Graph Container -->
                        <div class="h-64 w-full bg-surface-container-low rounded-xl border border-outline-variant/60 p-4 flex flex-col justify-end relative">
                            <div class="flex items-end justify-between gap-2 h-full w-full pt-4">
                                <?php foreach ($monthlyTrends as $m): ?>
                                    <?php 
                                        $revHeight = $maxMonthlyRev > 0 ? max(6, round(($m['revenue'] / $maxMonthlyRev) * 100)) : 6;
                                        $expHeight = $maxMonthlyRev > 0 ? max(6, round(($m['expenses'] / $maxMonthlyRev) * 100)) : 6;
                                    ?>
                                    <div class="flex-1 flex flex-col items-center justify-end h-full group">
                                        <div class="flex items-end justify-center gap-1 w-full h-full pb-1">
                                            <!-- Revenue Bar -->
                                            <div class="w-full max-w-[16px] bg-primary rounded-t transition-all group-hover:brightness-110 relative" style="height: <?php echo $revHeight; ?>%" title="<?php echo $m['month']; ?> Revenue: $<?php echo number_format($m['revenue'], 2); ?>">
                                            </div>
                                            <!-- Expense Bar -->
                                            <div class="w-full max-w-[16px] bg-error/70 rounded-t transition-all group-hover:brightness-110 relative" style="height: <?php echo $expHeight; ?>%" title="<?php echo $m['month']; ?> Expenses: $<?php echo number_format($m['expenses'], 2); ?>">
                                            </div>
                                        </div>
                                        <span class="text-[10px] font-mono text-on-surface-variant truncate w-full text-center mt-1"><?php echo $m['month']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Legend -->
                        <div class="flex justify-center items-center gap-6 mt-4 pt-3 border-t border-outline-variant">
                            <div class="flex items-center gap-2 text-xs text-on-surface font-medium">
                                <div class="w-3.5 h-3.5 rounded bg-primary"></div> Revenue Generated
                            </div>
                            <div class="flex items-center gap-2 text-xs text-on-surface font-medium">
                                <div class="w-3.5 h-3.5 rounded bg-error/70"></div> Hospital Expenses
                            </div>
                        </div>
                    </div>

                    <!-- Department Volume & Encounters Breakdown -->
                    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs flex flex-col justify-between">
                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <h3 class="font-headline-sm text-base font-bold text-on-surface">Patient Volume by Clinical Department</h3>
                                <p class="text-xs text-on-surface-variant">Total: <span class="font-bold text-on-surface font-mono"><?php echo number_format($deptVolume['total']); ?> Encounters</span></p>
                            </div>
                            <span class="text-xs font-mono font-bold text-secondary bg-secondary-fixed px-2.5 py-1 rounded-lg">Real Load</span>
                        </div>

                        <div class="h-64 w-full bg-surface-container-low rounded-xl border border-outline-variant/60 p-5 flex flex-col justify-center space-y-4">
                            <!-- Outpatient Consultations -->
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-primary text-[16px]">stethoscope</span>
                                        Outpatient Consultations
                                    </span>
                                    <span class="font-mono text-primary"><?php echo $deptVolume['consultations']; ?> (<?php echo $deptVolume['consult_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-primary h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $deptVolume['consult_pct']; ?>%"></div>
                                </div>
                            </div>

                            <!-- Laboratory Diagnostics -->
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-secondary text-[16px]">biotech</span>
                                        Laboratory Diagnostics
                                    </span>
                                    <span class="font-mono text-secondary"><?php echo $deptVolume['laboratory']; ?> (<?php echo $deptVolume['lab_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-secondary h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $deptVolume['lab_pct']; ?>%"></div>
                                </div>
                            </div>

                            <!-- Pharmacy Dispensary -->
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-tertiary text-[16px]">medication</span>
                                        Pharmacy Dispensary
                                    </span>
                                    <span class="font-mono text-tertiary"><?php echo $deptVolume['pharmacy']; ?> (<?php echo $deptVolume['pharm_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2.5 overflow-hidden">
                                    <div class="bg-tertiary h-2.5 rounded-full transition-all duration-500" style="width: <?php echo $deptVolume['pharm_pct']; ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-center items-center gap-6 mt-4 pt-3 border-t border-outline-variant">
                            <div class="flex items-center gap-1.5 text-xs text-on-surface font-medium"><div class="w-3 h-3 rounded bg-primary"></div> Consults</div>
                            <div class="flex items-center gap-1.5 text-xs text-on-surface font-medium"><div class="w-3 h-3 rounded bg-secondary"></div> Laboratory</div>
                            <div class="flex items-center gap-1.5 text-xs text-on-surface font-medium"><div class="w-3 h-3 rounded bg-tertiary"></div> Pharmacy</div>
                        </div>
                    </div>
                </div>

                <!-- Live Hospital Activity Stream -->
                <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-[22px]">history</span>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface">Live Hospital Activity &amp; Clinical Audit Stream</h3>
                        </div>
                        <span class="text-xs text-on-surface-variant">Real-time audit</span>
                    </div>

                    <?php if (empty($recentActivities)): ?>
                        <div class="py-8 text-center text-on-surface-variant text-xs">
                            <span class="material-symbols-outlined text-[32px] block mb-1">hourglass_empty</span>
                            No hospital operational activities recorded in this timeframe yet.
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php foreach ($recentActivities as $act): ?>
                                <div class="flex items-start gap-3 p-3 rounded-xl bg-surface-container-low border border-outline-variant/60">
                                    <div class="h-9 w-9 rounded-lg bg-surface border border-outline-variant flex items-center justify-center <?php echo $act['color']; ?> shrink-0 shadow-2xs">
                                        <span class="material-symbols-outlined text-[20px]"><?php echo $act['icon']; ?></span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex justify-between items-center gap-2">
                                            <h4 class="text-xs font-bold text-on-surface truncate"><?php echo e($act['title']); ?></h4>
                                            <span class="text-[10px] text-on-surface-variant font-mono shrink-0"><?php echo date('M d, H:i', strtotime($act['time'])); ?></span>
                                        </div>
                                        <p class="text-[11px] text-on-surface-variant mt-0.5"><?php echo e($act['description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= TAB 2: PHYSICIAN PRODUCTIVITY ================= -->
        <?php if ($activeTab === 'physicians'): ?>
            <div class="space-y-6 tab-content">
                <!-- Physician Workload & Revenue Table -->
                <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-outline-variant flex justify-between items-center bg-surface-bright">
                        <div>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[22px]">stethoscope</span>
                                Physician Clinical Productivity &amp; Consultations
                            </h3>
                            <p class="text-xs text-on-surface-variant">Breakdown of patient volumes, completed consults, and clinical revenue generated per physician.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-3 px-4">Physician Name</th>
                                    <th class="py-3 px-4">Specialty / Title</th>
                                    <th class="py-3 px-4">Consultation Fee</th>
                                    <th class="py-3 px-4">Total Assigned</th>
                                    <th class="py-3 px-4">Completed Consults</th>
                                    <th class="py-3 px-4">Unique Patients</th>
                                    <th class="py-3 px-4 text-right">Revenue Generated</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/60">
                                <?php if (empty($physicians)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                            No physician clinical encounters recorded for this period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($physicians as $doc): ?>
                                        <tr class="hover:bg-surface-container-low transition-colors">
                                            <td class="py-3 px-4 font-bold text-on-surface flex items-center gap-2">
                                                <div class="h-7 w-7 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[11px]">
                                                    Dr
                                                </div>
                                                <?php echo e($doc['doctor_name']); ?>
                                            </td>
                                            <td class="py-3 px-4 text-on-surface-variant"><?php echo e($doc['professional_title'] ?: 'General Practice'); ?></td>
                                            <td class="py-3 px-4 font-mono font-semibold text-secondary">$<?php echo number_format((float)$doc['consultation_fee'], 2); ?></td>
                                            <td class="py-3 px-4 font-mono font-bold"><?php echo $doc['total_consultations']; ?></td>
                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-secondary-fixed text-on-secondary-fixed">
                                                    <?php echo $doc['completed_consultations']; ?> Completed
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 font-mono"><?php echo $doc['unique_patients']; ?></td>
                                            <td class="py-3 px-4 text-right font-mono font-bold text-primary">$<?php echo number_format((float)$doc['total_revenue_generated'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Patient Diagnoses & Morbidity -->
                <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-secondary text-[22px]">diagnosis</span>
                            Top Patient Clinical Diagnoses &amp; Morbidity Trends
                        </h3>
                        <span class="text-xs text-on-surface-variant">Top Recorded Conditions</span>
                    </div>

                    <?php if (empty($topDiagnoses)): ?>
                        <div class="py-6 text-center text-on-surface-variant text-xs">
                            No diagnoses recorded yet in the selected period.
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            <?php foreach ($topDiagnoses as $diag): ?>
                                <div class="p-3.5 rounded-xl bg-surface-container-low border border-outline-variant/60 flex justify-between items-center">
                                    <div class="min-w-0 pr-2">
                                        <h4 class="text-xs font-bold text-on-surface truncate"><?php echo e($diag['diagnosis']); ?></h4>
                                        <p class="text-[10px] text-on-surface-variant mt-0.5">Clinical Condition</p>
                                    </div>
                                    <div class="h-8 px-2.5 rounded-lg bg-primary text-on-primary font-mono font-bold text-xs flex items-center justify-center shrink-0">
                                        <?php echo $diag['case_count']; ?> cases
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= TAB 3: LABORATORY DIAGNOSTICS ================= -->
        <?php if ($activeTab === 'laboratory'): ?>
            <div class="space-y-6 tab-content">
                <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-outline-variant flex justify-between items-center bg-surface-bright">
                        <div>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary text-[22px]">biotech</span>
                                Laboratory Diagnostic Tests &amp; Pathology Utilization
                            </h3>
                            <p class="text-xs text-on-surface-variant">Detailed breakdown of investigations conducted, pending orders, abnormal findings, and diagnostic revenue.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-3 px-4">Diagnostic Test Name</th>
                                    <th class="py-3 px-4">Standard Fee</th>
                                    <th class="py-3 px-4">Total Requested</th>
                                    <th class="py-3 px-4">Completed Tests</th>
                                    <th class="py-3 px-4">Pending Tests</th>
                                    <th class="py-3 px-4">Abnormal Findings</th>
                                    <th class="py-3 px-4 text-right">Diagnostic Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/60">
                                <?php if (empty($labAnalytics)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                            No laboratory test investigations performed in this timeframe.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($labAnalytics as $lab): ?>
                                        <tr class="hover:bg-surface-container-low transition-colors">
                                            <td class="py-3 px-4 font-bold text-on-surface"><?php echo e($lab['test_name']); ?></td>
                                            <td class="py-3 px-4 font-mono font-semibold text-secondary">$<?php echo number_format((float)$lab['test_price'], 2); ?></td>
                                            <td class="py-3 px-4 font-mono font-bold"><?php echo $lab['total_requested']; ?></td>
                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-secondary-fixed text-on-secondary-fixed">
                                                    <?php echo $lab['total_completed']; ?> Completed
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 font-mono"><?php echo $lab['total_pending']; ?></td>
                                            <td class="py-3 px-4 font-mono <?php echo ($lab['abnormal_findings'] > 0) ? 'text-amber-600 font-bold' : 'text-on-surface-variant'; ?>">
                                                <?php echo $lab['abnormal_findings']; ?>
                                            </td>
                                            <td class="py-3 px-4 text-right font-mono font-bold text-primary">$<?php echo number_format((float)$lab['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= TAB 4: PHARMACY & INVENTORY HEALTH ================= -->
        <?php if ($activeTab === 'pharmacy'): ?>
            <div class="space-y-6 tab-content">
                <!-- Inventory Health Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs">
                        <span class="text-[11px] font-semibold text-on-surface-variant block">Total Stock Valuation (Cost)</span>
                        <h4 class="text-2xl font-bold text-on-surface font-mono mt-1">$<?php echo number_format($inventorySummary['total_valuation_cost'], 2); ?></h4>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">Asset investment at wholesale price</p>
                    </div>

                    <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs">
                        <span class="text-[11px] font-semibold text-on-surface-variant block">Total Retail Stock Value</span>
                        <h4 class="text-2xl font-bold text-secondary font-mono mt-1">$<?php echo number_format($inventorySummary['total_valuation_retail'], 2); ?></h4>
                        <p class="text-[10px] text-secondary font-medium mt-0.5">+$<?php echo number_format($inventorySummary['potential_markup_gain'], 2); ?> Potential Margin</p>
                    </div>

                    <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs">
                        <span class="text-[11px] font-semibold text-on-surface-variant block">Low &amp; Out of Stock Alerts</span>
                        <h4 class="text-2xl font-bold <?php echo ($inventorySummary['low_stock_count'] > 0 || $inventorySummary['out_of_stock_count'] > 0) ? 'text-amber-600' : 'text-on-surface'; ?> font-mono mt-1">
                            <?php echo $inventorySummary['low_stock_count']; ?> Low / <?php echo $inventorySummary['out_of_stock_count']; ?> Empty
                        </h4>
                        <p class="text-[10px] text-on-surface-variant mt-0.5">Requires restock purchase order</p>
                    </div>

                    <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs">
                        <span class="text-[11px] font-semibold text-on-surface-variant block">Expiring Within 90 Days</span>
                        <h4 class="text-2xl font-bold <?php echo ($inventorySummary['expiring_batch_count'] > 0) ? 'text-error' : 'text-on-surface'; ?> font-mono mt-1">
                            <?php echo $inventorySummary['expiring_batch_count']; ?> Batches
                        </h4>
                        <p class="text-[10px] text-on-surface-variant mt-0.5"><?php echo $inventorySummary['expiring_units']; ?> Units nearing expiry date</p>
                    </div>
                </div>

                <!-- Top Dispensed Medications Table -->
                <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-outline-variant flex justify-between items-center bg-surface-bright">
                        <div>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-tertiary text-[22px]">medication</span>
                                Top Dispensed Medications &amp; Pharmacy Profit Margins
                            </h3>
                            <p class="text-xs text-on-surface-variant">Top selling pharmaceutical items, cost of goods sold (COGS), and dispensary profits.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-3 px-4">Med Code</th>
                                    <th class="py-3 px-4">Medication Name</th>
                                    <th class="py-3 px-4">Category</th>
                                    <th class="py-3 px-4">Units Dispensed</th>
                                    <th class="py-3 px-4">Gross Revenue</th>
                                    <th class="py-3 px-4">COGS (Cost)</th>
                                    <th class="py-3 px-4 text-right">Gross Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/60">
                                <?php if (empty($pharmacyTopSales)): ?>
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                            No medications dispensed in this timeframe.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pharmacyTopSales as $med): ?>
                                        <tr class="hover:bg-surface-container-low transition-colors">
                                            <td class="py-3 px-4 font-mono font-bold text-primary"><?php echo e($med['med_code']); ?></td>
                                            <td class="py-3 px-4 font-bold text-on-surface"><?php echo e($med['med_name'] ?? $med['medication_name']); ?></td>
                                            <td class="py-3 px-4 text-on-surface-variant"><?php echo e($med['category']); ?></td>
                                            <td class="py-3 px-4 font-mono font-bold"><?php echo $med['total_quantity_dispensed']; ?></td>
                                            <td class="py-3 px-4 font-mono font-semibold">$<?php echo number_format((float)$med['total_gross_sales'], 2); ?></td>
                                            <td class="py-3 px-4 font-mono text-error">$<?php echo number_format((float)$med['total_cogs'], 2); ?></td>
                                            <td class="py-3 px-4 text-right font-mono font-bold text-secondary">+$<?php echo number_format((float)$med['gross_profit'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Medication Stock & FIFO Valuation Summary Table -->
                <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-outline-variant flex justify-between items-center bg-surface-bright">
                        <div>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[22px]">inventory_2</span>
                                Pharmacy Stock Valuation &amp; Weighted Cost Summary
                            </h3>
                            <p class="text-xs text-on-surface-variant">Live catalog valuation, weighted average acquisition cost, and active batch inventory asset breakdown.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-3 px-4">Med Code</th>
                                    <th class="py-3 px-4">Medication Name</th>
                                    <th class="py-3 px-4">Category</th>
                                    <th class="py-3 px-4 text-center">In Stock</th>
                                    <th class="py-3 px-4 text-center">Active Batches</th>
                                    <th class="py-3 px-4 text-right">Weighted Cost</th>
                                    <th class="py-3 px-4 text-right">Retail Price</th>
                                    <th class="py-3 px-4 text-right">Asset Valuation</th>
                                    <th class="py-3 px-4">Nearest Expiry</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/60">
                                <?php if (empty($pharmacyInventoryReport)): ?>
                                    <tr>
                                        <td colspan="9" class="py-8 text-center text-on-surface-variant">
                                            No medication catalog records found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pharmacyInventoryReport as $m): ?>
                                        <tr class="hover:bg-surface-container-low transition-colors">
                                            <td class="py-3 px-4 font-mono font-bold text-primary"><?php echo e($m['med_code']); ?></td>
                                            <td class="py-3 px-4 font-bold text-on-surface">
                                                <?php echo e($m['name']); ?>
                                                <span class="block text-[11px] font-normal text-on-surface-variant"><?php echo e($m['dosage_form'] ?: 'Unit'); ?></span>
                                            </td>
                                            <td class="py-3 px-4 text-on-surface-variant"><?php echo e($m['category']); ?></td>
                                            <td class="py-3 px-4 text-center font-bold font-mono <?php echo ((int)$m['current_stock'] <= 0) ? 'text-error' : 'text-on-surface'; ?>">
                                                <?php echo number_format((int)$m['current_stock']); ?>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-container-high text-on-surface">
                                                    <?php echo (int)$m['active_batch_count']; ?> Batches
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-right font-mono text-on-surface-variant">$<?php echo number_format((float)$m['weighted_avg_cost'], 2); ?></td>
                                            <td class="py-3 px-4 text-right font-mono font-semibold text-on-surface">$<?php echo number_format((float)$m['selling_price'], 2); ?></td>
                                            <td class="py-3 px-4 text-right font-mono font-bold text-primary">$<?php echo number_format((float)$m['total_inventory_value'], 2); ?></td>
                                            <td class="py-3 px-4 text-on-surface-variant">
                                                <?php echo !empty($m['nearest_expiry']) ? date('M d, Y', strtotime($m['nearest_expiry'])) : '<span class="text-outline">No Active Batch</span>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Granular Active Batches & Expiry Ledger Table -->
                <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
                    <div class="p-5 border-b border-outline-variant flex justify-between items-center bg-surface-bright">
                        <div>
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary text-[22px]">layers</span>
                                FIFO Active Batches &amp; Expiry Schedule (Option A Sequence)
                            </h3>
                            <p class="text-xs text-on-surface-variant">Granular lot tracking showing exact FIFO drawing order sorted by earliest expiration date first.</p>
                        </div>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                                    <th class="py-3 px-4">Batch #</th>
                                    <th class="py-3 px-4">Medication Name</th>
                                    <th class="py-3 px-4">Supplier</th>
                                    <th class="py-3 px-4">Received Date</th>
                                    <th class="py-3 px-4">Expiry Date</th>
                                    <th class="py-3 px-4">Countdown</th>
                                    <th class="py-3 px-4 text-center">Remaining / Recv</th>
                                    <th class="py-3 px-4 text-right">Unit Cost</th>
                                    <th class="py-3 px-4 text-right">Batch Value</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-outline-variant/60">
                                <?php if (empty($pharmacyBatchReport)): ?>
                                    <tr>
                                        <td colspan="10" class="py-8 text-center text-on-surface-variant">
                                            No active medicine batches found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pharmacyBatchReport as $b): 
                                        $days = (int)($b['days_to_expiry'] ?? 0);
                                        if ($days < 0) {
                                            $countdownBadge = '<span class="px-2 py-0.5 bg-error text-on-error rounded-full text-[10px] font-bold">Expired</span>';
                                        } elseif ($days <= 30) {
                                            $countdownBadge = '<span class="px-2 py-0.5 bg-amber-500/20 text-amber-700 border border-amber-300 dark:border-amber-700 rounded-full text-[10px] font-bold">' . $days . ' days left</span>';
                                        } elseif ($days <= 90) {
                                            $countdownBadge = '<span class="px-2 py-0.5 bg-secondary-fixed/40 text-on-secondary-fixed-variant rounded-full text-[10px] font-semibold">' . $days . ' days left</span>';
                                        } else {
                                            $countdownBadge = '<span class="text-on-surface-variant">' . $days . ' days</span>';
                                        }
                                    ?>
                                        <tr class="hover:bg-surface-container-low transition-colors">
                                            <td class="py-3 px-4 font-mono font-bold text-primary"><?php echo e($b['batch_number']); ?></td>
                                            <td class="py-3 px-4 font-bold text-on-surface">
                                                <?php echo e($b['medication_name']); ?>
                                                <span class="block text-[11px] font-normal text-on-surface-variant"><?php echo e($b['med_code']); ?></span>
                                            </td>
                                            <td class="py-3 px-4 text-on-surface-variant"><?php echo e($b['supplier_name'] ?? 'Direct Restock'); ?></td>
                                            <td class="py-3 px-4 font-mono text-[11px]"><?php echo date('Y-m-d', strtotime($b['received_date'])); ?></td>
                                            <td class="py-3 px-4 font-medium"><?php echo date('M d, Y', strtotime($b['expiry_date'])); ?></td>
                                            <td class="py-3 px-4"><?php echo $countdownBadge; ?></td>
                                            <td class="py-3 px-4 text-center font-mono font-semibold">
                                                <?php echo (int)$b['quantity_remaining']; ?> / <?php echo (int)$b['quantity_received']; ?>
                                            </td>
                                            <td class="py-3 px-4 text-right font-mono text-on-surface-variant">$<?php echo number_format((float)$b['unit_cost'], 2); ?></td>
                                            <td class="py-3 px-4 text-right font-mono font-bold text-secondary">$<?php echo number_format((float)$b['batch_value'], 2); ?></td>
                                            <td class="py-3 px-4 text-center">
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo ($b['status'] === 'active') ? 'bg-secondary-fixed text-on-secondary-fixed-variant' : 'bg-surface-container-high text-on-surface-variant'; ?>">
                                                    <?php echo ucfirst(e($b['status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= TAB 5: FINANCIAL CYCLES & STREAMS ================= -->
        <?php if ($activeTab === 'finance'): ?>
            <div class="space-y-6 tab-content">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Revenue Streams Breakdown -->
                    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-[22px]">pie_chart</span>
                                Hospital Revenue Streams
                            </h3>
                            <span class="text-xs font-mono font-bold text-primary">$<?php echo number_format($revenueStreams['total'], 2); ?> Total</span>
                        </div>

                        <div class="space-y-3.5">
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Outpatient Doctor Consultations</span>
                                    <span class="font-mono text-primary">$<?php echo number_format($revenueStreams['consultation'], 2); ?> (<?php echo $revenueStreams['consult_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-primary h-2 rounded-full" style="width: <?php echo $revenueStreams['consult_pct']; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Laboratory Diagnostics</span>
                                    <span class="font-mono text-secondary">$<?php echo number_format($revenueStreams['lab'], 2); ?> (<?php echo $revenueStreams['lab_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-secondary h-2 rounded-full" style="width: <?php echo $revenueStreams['lab_pct']; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Pharmacy Medicine Sales</span>
                                    <span class="font-mono text-tertiary">$<?php echo number_format($revenueStreams['medication'], 2); ?> (<?php echo $revenueStreams['med_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-tertiary h-2 rounded-full" style="width: <?php echo $revenueStreams['med_pct']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods Distribution -->
                    <div class="bg-surface border border-outline-variant rounded-2xl p-5 shadow-xs">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-headline-sm text-base font-bold text-on-surface flex items-center gap-2">
                                <span class="material-symbols-outlined text-secondary text-[22px]">account_balance_wallet</span>
                                Payment Collections by Gateway
                            </h3>
                            <span class="text-xs font-mono font-bold text-secondary">$<?php echo number_format($paymentMethods['total'], 2); ?> Total</span>
                        </div>

                        <div class="space-y-3.5">
                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Cash Drawer (Cash on Hand)</span>
                                    <span class="font-mono text-primary">$<?php echo number_format($paymentMethods['cash'], 2); ?> (<?php echo $paymentMethods['cash_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-primary h-2 rounded-full" style="width: <?php echo $paymentMethods['cash_pct']; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Commercial Bank Transfer</span>
                                    <span class="font-mono text-secondary">$<?php echo number_format($paymentMethods['bank'], 2); ?> (<?php echo $paymentMethods['bank_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-secondary h-2 rounded-full" style="width: <?php echo $paymentMethods['bank_pct']; ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-xs mb-1 font-semibold">
                                    <span class="text-on-surface">Mobile Money (EVC Plus / Zaad / Sahal)</span>
                                    <span class="font-mono text-tertiary">$<?php echo number_format($paymentMethods['mobile'], 2); ?> (<?php echo $paymentMethods['mobile_pct']; ?>%)</span>
                                </div>
                                <div class="w-full bg-surface-variant rounded-full h-2">
                                    <div class="bg-tertiary h-2 rounded-full" style="width: <?php echo $paymentMethods['mobile_pct']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Printable Official Signatures Block (Visible When Printed) -->
        <div class="print-only mt-12 pt-8 border-t border-gray-300">
            <div class="grid grid-cols-2 gap-12">
                <div class="text-center">
                    <div class="border-b border-gray-400 w-48 mx-auto mb-2"></div>
                    <p class="font-bold text-xs text-gray-800">Chief Medical Officer (CMO)</p>
                    <p class="text-[10px] text-gray-500">Clinical Verification &amp; Quality Audit</p>
                </div>
                <div class="text-center">
                    <div class="border-b border-gray-400 w-48 mx-auto mb-2"></div>
                    <p class="font-bold text-xs text-gray-800">Hospital Financial Director (CFO)</p>
                    <p class="text-[10px] text-gray-500">Executive Accounting &amp; Revenue Reconciliation</p>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- MODAL: Custom Date Range Selector -->
<div id="custom-date-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-sm w-full p-6 shadow-2xl">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[22px]">date_range</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Select Custom Date Range</h3>
            </div>
            <button type="button" onclick="document.getElementById('custom-date-modal').classList.add('hidden')" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="GET" action="report.php" class="space-y-4">
            <input type="hidden" name="range" value="custom">
            <input type="hidden" name="tab" value="<?php echo e($activeTab); ?>">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">From Date (Start)</label>
                <input type="date" name="from_date" required value="<?php echo e($fromDate ?? date('Y-m-01')); ?>" class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2.5 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-1">To Date (End)</label>
                <input type="date" name="to_date" required value="<?php echo e($toDate ?? date('Y-m-d')); ?>" class="w-full bg-surface-container-low border border-outline-variant rounded-xl p-2.5 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('custom-date-modal').classList.add('hidden')" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">filter_alt</span>
                    Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>
