<?php
/**
 * MedCore Systems - Hospital Executive & Operational Reporting Engine
 * Aggregates real-time database metrics across clinical consultations, laboratory diagnostics,
 * pharmacy dispensaries, inventory valuations, operational expenses, and general ledger finances.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';

class ReportOperation
{
    /**
     * Helper to build date range SQL filter and parameters.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @param string $column
     * @return array [string $sqlCondition, array $params]
     */
    private static function buildDateFilter(?string $fromDate, ?string $toDate, string $column = 'created_at'): array
    {
        $sql = '';
        $params = [];

        if (!empty($fromDate) && !empty($toDate)) {
            $sql = " AND DATE({$column}) BETWEEN ? AND ?";
            $params = [$fromDate, $toDate];
        } elseif (!empty($fromDate)) {
            $sql = " AND DATE({$column}) >= ?";
            $params = [$fromDate];
        } elseif (!empty($toDate)) {
            $sql = " AND DATE({$column}) <= ?";
            $params = [$toDate];
        }

        return [$sql, $params];
    }

    /**
     * Retrieves Executive KPI metrics for a specified date range.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getExecutiveKpis(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();

        // 1. Total Invoiced Revenue collected
        [$invFilter, $invParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtInv = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE 1=1 {$invFilter}");
        $stmtInv->execute($invParams);
        $invoicedRevenue = (float)$stmtInv->fetchColumn();

        // 2. Direct Pharmacy OTC Cash Sales
        [$pharmFilter, $pharmParams] = self::buildDateFilter($fromDate, $toDate, 'ps.created_at');
        $stmtPharmDirect = $pdo->prepare("SELECT COALESCE(SUM(ps.paid_amount), 0) FROM pharmacy_sales ps WHERE ps.sale_type = 'walk_in' {$pharmFilter}");
        $stmtPharmDirect->execute($pharmParams);
        $pharmDirectRevenue = (float)$stmtPharmDirect->fetchColumn();

        $totalRevenue = $invoicedRevenue + $pharmDirectRevenue;

        // 3. Consultations Count
        [$consFilter, $consParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtCons = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE 1=1 {$consFilter}");
        $stmtCons->execute($consParams);
        $totalConsultations = (int)$stmtCons->fetchColumn();

        // 4. Laboratory Tests Count & Diagnostic Revenue
        [$labFilter, $labParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtLab = $pdo->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed_orders,
                COALESCE(SUM(test_price), 0) as total_lab_revenue
            FROM lab_orders 
            WHERE 1=1 {$labFilter}
        ");
        $stmtLab->execute($labParams);
        $labStats = $stmtLab->fetch() ?: ['total_orders' => 0, 'completed_orders' => 0, 'total_lab_revenue' => 0];

        // 5. Pharmacy Sales, COGS & Margin
        [$pharmAllFilter, $pharmAllParams] = self::buildDateFilter($fromDate, $toDate, 'ps.created_at');
        $stmtPharmAll = $pdo->prepare("
            SELECT 
                COALESCE(SUM(psi.total_price), 0) as total_pharm_sales,
                COALESCE(SUM(psi.quantity * m.cost_price), 0) as total_pharm_cogs,
                COALESCE(SUM(psi.quantity), 0) as total_units_dispensed
            FROM pharmacy_sale_items psi
            JOIN medications m ON psi.medication_id = m.id
            JOIN pharmacy_sales ps ON psi.sale_id = ps.id
            WHERE 1=1 {$pharmAllFilter}
        ");
        $stmtPharmAll->execute($pharmAllParams);
        $pharmStats = $stmtPharmAll->fetch() ?: ['total_pharm_sales' => 0, 'total_pharm_cogs' => 0, 'total_units_dispensed' => 0];

        $pharmacySalesTotal = (float)$pharmStats['total_pharm_sales'];
        $pharmacyCogs       = (float)$pharmStats['total_pharm_cogs'];
        $pharmacyMargin     = $pharmacySalesTotal - $pharmacyCogs;

        // 6. Hospital Operating Expenses
        [$expFilter, $expParams] = self::buildDateFilter($fromDate, $toDate, 'expense_date');
        $stmtExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM hospital_expenses WHERE 1=1 {$expFilter}");
        $stmtExp->execute($expParams);
        $totalExpenses = (float)$stmtExp->fetchColumn();

        // 7. Total Unique Patient Encounters
        [$queueFilter, $queueParams] = self::buildDateFilter($fromDate, $toDate, 'queued_at');
        $stmtPatients = $pdo->prepare("
            SELECT COUNT(DISTINCT patient_id) 
            FROM patient_queues 
            WHERE 1=1 {$queueFilter}
        ");
        $stmtPatients->execute($queueParams);
        $totalEncounters = (int)$stmtPatients->fetchColumn();

        // 8. Net Operating Profit
        $netProfit = $totalRevenue - $totalExpenses - $pharmacyCogs;

        return [
            'total_revenue'          => $totalRevenue,
            'invoiced_revenue'       => $invoicedRevenue,
            'total_consultations'    => $totalConsultations,
            'total_lab_orders'       => (int)$labStats['total_orders'],
            'completed_lab_orders'   => (int)$labStats['completed_orders'],
            'lab_revenue'            => (float)$labStats['total_lab_revenue'],
            'pharmacy_sales_total'   => $pharmacySalesTotal,
            'pharmacy_cogs'          => $pharmacyCogs,
            'pharmacy_margin'        => $pharmacyMargin,
            'units_dispensed'        => (int)$pharmStats['total_units_dispensed'],
            'total_expenses'         => $totalExpenses,
            'total_encounters'       => $totalEncounters,
            'net_profit'             => $netProfit,
        ];
    }

    /**
     * Calculates 6-month monthly financial trends (Revenue vs Expenses).
     *
     * @param int $months Number of past months (default: 6)
     * @return array
     */
    public static function getMonthlyFinancialTrends(int $months = 6): array
    {
        $pdo = getDBConnection();
        $results = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} month"));
            $monthEnd   = date('Y-m-t', strtotime("-{$i} month"));
            $monthLabel = date('M Y', strtotime("-{$i} month"));

            // Revenue in month
            $stmtRev = $pdo->prepare("
                SELECT COALESCE(SUM(paid_amount), 0) 
                FROM invoices 
                WHERE DATE(created_at) BETWEEN ? AND ?
            ");
            $stmtRev->execute([$monthStart, $monthEnd]);
            $monthRev = (float)$stmtRev->fetchColumn();

            // Direct pharmacy sales in month
            $stmtPharm = $pdo->prepare("
                SELECT COALESCE(SUM(paid_amount), 0) 
                FROM pharmacy_sales 
                WHERE sale_type = 'walk_in' AND DATE(created_at) BETWEEN ? AND ?
            ");
            $stmtPharm->execute([$monthStart, $monthEnd]);
            $monthRev += (float)$stmtPharm->fetchColumn();

            // Expenses in month
            $stmtExp = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) 
                FROM hospital_expenses 
                WHERE DATE(expense_date) BETWEEN ? AND ?
            ");
            $stmtExp->execute([$monthStart, $monthEnd]);
            $monthExp = (float)$stmtExp->fetchColumn();

            $results[] = [
                'month'    => $monthLabel,
                'revenue'  => $monthRev,
                'expenses' => $monthExp,
                'profit'   => $monthRev - $monthExp,
            ];
        }

        return $results;
    }

    /**
     * Retrieves patient encounter volume breakdown by hospital department.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getDepartmentVolumeBreakdown(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();

        // 1. Consultations Volume
        [$cFilter, $cParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE 1=1 {$cFilter}");
        $stmtC->execute($cParams);
        $consults = (int)$stmtC->fetchColumn();

        // 2. Lab Orders Volume
        [$lFilter, $lParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtL = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE 1=1 {$lFilter}");
        $stmtL->execute($lParams);
        $labs = (int)$stmtL->fetchColumn();

        // 3. Pharmacy Sales Volume
        [$pFilter, $pParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtP = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_sales WHERE 1=1 {$pFilter}");
        $stmtP->execute($pParams);
        $pharmacy = (int)$stmtP->fetchColumn();

        $total = $consults + $labs + $pharmacy;

        return [
            'consultations' => $consults,
            'laboratory'    => $labs,
            'pharmacy'      => $pharmacy,
            'total'         => $total,
            'consult_pct'   => $total > 0 ? round(($consults / $total) * 100, 1) : 0,
            'lab_pct'       => $total > 0 ? round(($labs / $total) * 100, 1) : 0,
            'pharm_pct'     => $total > 0 ? round(($pharmacy / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Generates Physician Clinical Productivity Report.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getPhysicianProductivityReport(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();
        [$cFilter, $cParams] = self::buildDateFilter($fromDate, $toDate, 'c.created_at');

        $sql = "
            SELECT 
                u.id as doctor_id,
                u.full_name as doctor_name,
                u.professional_title,
                COALESCE(u.consultation_fee, 10.00) as consultation_fee,
                COUNT(c.id) as total_consultations,
                COALESCE(SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END), 0) as completed_consultations,
                COUNT(DISTINCT c.patient_id) as unique_patients,
                COALESCE(SUM(CASE WHEN c.status = 'completed' THEN COALESCE(u.consultation_fee, 10.00) ELSE 0 END), 0) as total_revenue_generated
            FROM users u
            LEFT JOIN consultations c ON c.doctor_id = u.id {$cFilter}
            WHERE u.role = 'doctor'
            GROUP BY u.id
            ORDER BY total_consultations DESC, u.full_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($cParams);

        return $stmt->fetchAll();
    }

    /**
     * Retrieves Top Patient Clinical Diagnoses & Morbidity Summary.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @param int $limit
     * @return array
     */
    public static function getTopDiagnosesReport(?string $fromDate = null, ?string $toDate = null, int $limit = 6): array
    {
        $pdo = getDBConnection();
        [$cFilter, $cParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');

        $sql = "
            SELECT 
                assessment_diagnosis as diagnosis,
                COUNT(*) as case_count
            FROM consultations
            WHERE assessment_diagnosis IS NOT NULL AND TRIM(assessment_diagnosis) != '' {$cFilter}
            GROUP BY assessment_diagnosis
            ORDER BY case_count DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($cParams);

        return $stmt->fetchAll();
    }

    /**
     * Generates Laboratory Diagnostic Utilization and Revenue Report.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getLaboratoryAnalyticsReport(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();
        [$lFilter, $lParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');

        $sql = "
            SELECT 
                test_name,
                test_price,
                COUNT(*) as total_requested,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as total_completed,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as total_pending,
                COALESCE(SUM(CASE WHEN result_summary LIKE '%abnormal%' OR result_summary LIKE '%positive%' OR result_summary LIKE '%reactive%' THEN 1 ELSE 0 END), 0) as abnormal_findings,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN test_price ELSE 0 END), 0) as total_revenue
            FROM lab_orders
            WHERE 1=1 {$lFilter}
            GROUP BY test_name, test_price
            ORDER BY total_requested DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($lParams);

        return $stmt->fetchAll();
    }

    /**
     * Generates Top Dispensed Medications and Pharmacy Profitability Report.
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @param int $limit
     * @return array
     */
    public static function getPharmacySalesReport(?string $fromDate = null, ?string $toDate = null, int $limit = 10): array
    {
        $pdo = getDBConnection();
        [$pFilter, $pParams] = self::buildDateFilter($fromDate, $toDate, 'ps.created_at');

        $sql = "
            SELECT 
                m.id as medication_id,
                m.med_code,
                m.name as medication_name,
                m.category,
                COALESCE(SUM(psi.quantity), 0) as total_quantity_dispensed,
                COALESCE(SUM(psi.total_price), 0) as total_gross_sales,
                COALESCE(SUM(psi.quantity * m.cost_price), 0) as total_cogs,
                COALESCE(SUM(psi.total_price - (psi.quantity * m.cost_price)), 0) as gross_profit
            FROM pharmacy_sale_items psi
            JOIN pharmacy_sales ps ON psi.sale_id = ps.id
            JOIN medications m ON psi.medication_id = m.id
            WHERE 1=1 {$pFilter}
            GROUP BY m.id, m.med_code, m.name, m.category
            ORDER BY total_quantity_dispensed DESC, total_gross_sales DESC
            LIMIT {$limit}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($pParams);

        return $stmt->fetchAll();
    }

    /**
     * Generates Live Inventory Valuation, Stock Health & Expiry Alerts.
     *
     * @return array
     */
    public static function getInventoryValuationSummary(): array
    {
        $pdo = getDBConnection();

        // 1. Medications Catalog Summary
        $stmtMeds = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                COALESCE(SUM(current_stock), 0) as total_units_in_stock,
                COALESCE(SUM(current_stock * cost_price), 0) as total_valuation_cost,
                COALESCE(SUM(current_stock * unit_price), 0) as total_valuation_retail,
                COALESCE(SUM(CASE WHEN current_stock <= min_stock_alert AND current_stock > 0 THEN 1 ELSE 0 END), 0) as low_stock_count,
                COALESCE(SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END), 0) as out_of_stock_count
            FROM medications
        ");
        $medSummary = $stmtMeds->fetch() ?: [
            'total_items'            => 0,
            'total_units_in_stock'   => 0,
            'total_valuation_cost'   => 0,
            'total_valuation_retail' => 0,
            'low_stock_count'        => 0,
            'out_of_stock_count'     => 0,
        ];

        // 2. Batches Expiring within 90 Days
        $stmtExpiry = $pdo->query("
            SELECT 
                COUNT(*) as expiring_batch_count,
                COALESCE(SUM(quantity_remaining), 0) as expiring_units
            FROM medicine_batches
            WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
              AND quantity_remaining > 0
        ");
        $expirySummary = $stmtExpiry->fetch() ?: ['expiring_batch_count' => 0, 'expiring_units' => 0];

        return [
            'total_items'            => (int)$medSummary['total_items'],
            'total_units_in_stock'   => (int)$medSummary['total_units_in_stock'],
            'total_valuation_cost'   => (float)$medSummary['total_valuation_cost'],
            'total_valuation_retail' => (float)$medSummary['total_valuation_retail'],
            'potential_markup_gain'  => (float)$medSummary['total_valuation_retail'] - (float)$medSummary['total_valuation_cost'],
            'low_stock_count'        => (int)$medSummary['low_stock_count'],
            'out_of_stock_count'     => (int)$medSummary['out_of_stock_count'],
            'expiring_batch_count'   => (int)$expirySummary['expiring_batch_count'],
            'expiring_units'         => (int)$expirySummary['expiring_units'],
        ];
    }

    /**
     * Retrieves Revenue Streams Distribution (Consultation vs Laboratory vs Pharmacy).
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getRevenueStreamsBreakdown(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();
        [$iFilter, $iParams] = self::buildDateFilter($fromDate, $toDate, 'i.created_at');

        $sql = "
            SELECT 
                ii.item_type,
                COALESCE(SUM(ii.total_price), 0) as stream_total
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.id
            WHERE 1=1 {$iFilter}
            GROUP BY ii.item_type
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($iParams);
        $rows = $stmt->fetchAll();

        $streams = [
            'consultation' => 0.0,
            'lab'          => 0.0,
            'medication'   => 0.0,
            'other'        => 0.0,
        ];

        foreach ($rows as $r) {
            $type = strtolower($r['item_type'] ?? 'other');
            if ($type === 'lab_test' || $type === 'laboratory') {
                $type = 'lab';
            }
            if (isset($streams[$type])) {
                $streams[$type] += (float)$r['stream_total'];
            } else {
                $streams['other'] += (float)$r['stream_total'];
            }
        }

        // Add direct pharmacy walk-in sales to medication stream
        [$pFilter, $pParams] = self::buildDateFilter($fromDate, $toDate, 'created_at');
        $stmtP = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM pharmacy_sales WHERE sale_type = 'walk_in' {$pFilter}");
        $stmtP->execute($pParams);
        $streams['medication'] += (float)$stmtP->fetchColumn();

        $total = array_sum($streams);

        return [
            'consultation' => $streams['consultation'],
            'lab'          => $streams['lab'],
            'medication'   => $streams['medication'],
            'other'        => $streams['other'],
            'total'        => $total,
            'consult_pct'  => $total > 0 ? round(($streams['consultation'] / $total) * 100, 1) : 0,
            'lab_pct'      => $total > 0 ? round(($streams['lab'] / $total) * 100, 1) : 0,
            'med_pct'      => $total > 0 ? round(($streams['medication'] / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Retrieves Payment Method Collections Breakdown (Cash vs Bank vs Mobile).
     *
     * @param string|null $fromDate
     * @param string|null $toDate
     * @return array
     */
    public static function getPaymentMethodsBreakdown(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = getDBConnection();
        $methods = [
            'cash'   => 0.0,
            'bank'   => 0.0,
            'mobile' => 0.0,
        ];

        // 1. Collections from Invoices
        [$invFilter, $invParams] = self::buildDateFilter($fromDate, $toDate, 'paid_at');
        $stmtInv = $pdo->prepare("
            SELECT 
                payment_method,
                COALESCE(SUM(paid_amount), 0) as method_total
            FROM invoices 
            WHERE paid_amount > 0 AND paid_at IS NOT NULL {$invFilter}
            GROUP BY payment_method
        ");
        $stmtInv->execute($invParams);
        foreach ($stmtInv->fetchAll() as $r) {
            $m = strtolower($r['payment_method'] ?? 'cash');
            if ($m === 'card') $m = 'bank';
            if (isset($methods[$m])) {
                $methods[$m] += (float)$r['method_total'];
            } else {
                $methods['cash'] += (float)$r['method_total'];
            }
        }

        // 2. Collections from Patient Sale Payments (Pharmacy debt collection)
        [$spFilter, $spParams] = self::buildDateFilter($fromDate, $toDate, 'received_at');
        $stmtSp = $pdo->prepare("
            SELECT 
                payment_method,
                COALESCE(SUM(amount_paid), 0) as method_total
            FROM sale_payments
            WHERE 1=1 {$spFilter}
            GROUP BY payment_method
        ");
        $stmtSp->execute($spParams);
        foreach ($stmtSp->fetchAll() as $r) {
            $m = strtolower($r['payment_method'] ?? 'cash');
            if ($m === 'card') $m = 'bank';
            if (isset($methods[$m])) {
                $methods[$m] += (float)$r['method_total'];
            } else {
                $methods['cash'] += (float)$r['method_total'];
            }
        }

        $grandTotal = array_sum($methods);

        return [
            'cash'        => $methods['cash'],
            'bank'        => $methods['bank'],
            'mobile'      => $methods['mobile'],
            'total'       => $grandTotal,
            'cash_pct'    => $grandTotal > 0 ? round(($methods['cash'] / $grandTotal) * 100, 1) : 0,
            'bank_pct'    => $grandTotal > 0 ? round(($methods['bank'] / $grandTotal) * 100, 1) : 0,
            'mobile_pct'  => $grandTotal > 0 ? round(($methods['mobile'] / $grandTotal) * 100, 1) : 0,
        ];
    }

    /**
     * Generates Live Operational Activity Stream (Latest Clinical & Financial Events).
     *
     * @param int $limit
     * @return array
     */
    public static function getRecentAuditActivities(int $limit = 8): array
    {
        $pdo = getDBConnection();
        $activities = [];

        // 1. Recent Invoices Paid
        $stmtInv = $pdo->query("
            SELECT i.invoice_number, i.net_total as total_amount, i.paid_amount, i.payment_status, i.created_at, p.first_name, p.last_name
            FROM invoices i
            LEFT JOIN patients p ON i.patient_id = p.id
            ORDER BY i.created_at DESC
            LIMIT 4
        ");
        foreach ($stmtInv->fetchAll() as $inv) {
            $pName = trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? '')) ?: 'Walk-in Patient';
            $activities[] = [
                'type'        => 'invoice',
                'title'       => "Invoice {$inv['invoice_number']} - \${$inv['paid_amount']}",
                'description' => "Billing payment received for patient {$pName}.",
                'time'        => $inv['created_at'],
                'icon'        => 'receipt',
                'color'       => 'text-primary',
            ];
        }

        // 2. Recent Lab Results Completed
        $stmtLab = $pdo->query("
            SELECT lo.test_name, lo.status, lo.created_at, p.first_name, p.last_name
            FROM lab_orders lo
            LEFT JOIN patients p ON lo.patient_id = p.id
            WHERE lo.status = 'completed'
            ORDER BY lo.created_at DESC
            LIMIT 4
        ");
        foreach ($stmtLab->fetchAll() as $lab) {
            $pName = trim(($lab['first_name'] ?? '') . ' ' . ($lab['last_name'] ?? '')) ?: 'Patient';
            $activities[] = [
                'type'        => 'laboratory',
                'title'       => "Lab Diagnostic: {$lab['test_name']}",
                'description' => "Completed diagnostic investigation for {$pName}.",
                'time'        => $lab['created_at'],
                'icon'        => 'biotech',
                'color'       => 'text-secondary',
            ];
        }

        // Sort combined activities by timestamp DESC
        usort($activities, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));

        return array_slice($activities, 0, $limit);
    }
}
