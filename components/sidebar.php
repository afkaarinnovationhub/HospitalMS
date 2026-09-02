<?php
/**
 * MedCore Systems - Reusable Responsive Sidebar Component
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/auth.php';

$activePage = $activePage ?? 'dashboard';
$currentUser = getCurrentUser() ?? [
    'full_name'          => 'Staff Member',
    'role'               => 'Staff',
    'professional_title' => 'Clinical Operations',
];

$navItems = [
    [
        'id'    => 'dashboard',
        'label' => 'Dashboard',
        'icon'  => 'dashboard',
        'url'   => 'dashboard.php',
    ],
    [
        'id'    => 'users',
        'label' => 'Users',
        'icon'  => 'manage_accounts',
        'url'   => 'user_management.php',
    ],
    [
        'id'    => 'reception',
        'label' => 'Reception',
        'icon'  => 'desk',
        'url'   => 'reception.php',
    ],
    [
        'id'    => 'doctor_dashboard',
        'label' => 'Doctor View',
        'icon'  => 'stethoscope',
        'url'   => 'doctor_dashboard.php',
    ],
    [
        'id'    => 'doctors',
        'label' => 'Doctors',
        'icon'  => 'clinical_notes',
        'url'   => 'doctors.php',
    ],
    [
        'id'    => 'patients',
        'label' => 'Patients',
        'icon'  => 'person',
        'url'   => 'patient_registration.php',
    ],
    [
        'id'    => 'queue',
        'label' => 'Queue',
        'icon'  => 'group_add',
        'url'   => 'queue_management.php',
    ],
    [
        'id'    => 'consultations',
        'label' => 'Consultations',
        'icon'  => 'medical_services',
        'url'   => 'consultation_michael_chen.php',
    ],
    [
        'id'    => 'laboratory',
        'label' => 'Laboratory',
        'icon'  => 'biotech',
        'url'   => 'laboratory_dashboard.php',
    ],
    [
        'id'    => 'pharmacy',
        'label' => 'Pharmacy',
        'icon'  => 'medication',
        'url'   => 'pharmacy_dispensing_prescription.php',
    ],
    [
        'id'    => 'inventory',
        'label' => 'Inventory',
        'icon'  => 'inventory_2',
        'url'   => 'inventory_management.php',
    ],
    [
        'id'    => 'suppliers',
        'label' => 'Suppliers',
        'icon'  => 'local_shipping',
        'url'   => 'suppliers.php',
    ],
    [
        'id'    => 'billing',
        'label' => 'Billing',
        'icon'  => 'payments',
        'url'   => 'billing_payments.php',
    ],
    [
        'id'    => 'accounting',
        'label' => 'Accounting',
        'icon'  => 'account_balance',
        'url'   => 'accounting_dashboard.php',
    ],
    [
        'id'    => 'expenses',
        'label' => 'Expenses',
        'icon'  => 'receipt_long',
        'url'   => 'expenses.php',
    ],
    [
        'id'    => 'reports',
        'label' => 'Reports',
        'icon'  => 'assessment',
        'url'   => 'report.php',
    ],
];

$userRole = $currentUser['role'] ?? 'superadmin_ict';
$allowedModuleIds = getUserAllowedNavItems($userRole);
$filteredNavItems = array_filter($navItems, function ($item) use ($allowedModuleIds) {
    return in_array($item['id'], $allowedModuleIds, true);
});
$brandHomeUrl = getRoleDefaultPage($userRole);
?>
<!-- Mobile Backdrop Overlay -->
<div id="sidebar-backdrop" class="fixed inset-0 bg-black/50 z-40 hidden backdrop-blur-xs transition-opacity duration-300 opacity-0 lg:hidden" onclick="closeMobileSidebar()"></div>

<!-- SideNavBar (Responsive Off-Canvas on Mobile/Tablet, Collapsible/Pinned on Desktop) -->
<nav id="app-sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-surface dark:bg-surface-dim border-r border-outline-variant dark:border-outline flex flex-col h-full py-lg px-md transform -translate-x-full lg:translate-x-0 transition-all duration-300 ease-in-out shadow-2xl lg:shadow-none overflow-hidden">
    <!-- Sidebar Header: Brand Logo & Mobile-Only Close Button -->
    <div class="mb-lg flex items-center justify-between gap-2 pb-sm border-b border-outline-variant">
        <a href="<?php echo htmlspecialchars($brandHomeUrl); ?>" class="flex items-center gap-sm min-w-0">
            <span class="material-symbols-outlined text-primary dark:text-primary-fixed text-[32px] fill shrink-0">local_hospital</span>
            <div class="sidebar-text transition-opacity duration-200">
                <h1 class="font-headline-md text-base sm:text-headline-md font-bold text-primary dark:text-primary-fixed leading-tight truncate">MedCore Systems</h1>
                <p class="font-label-md text-[11px] text-on-surface-variant truncate">Clinical Operations</p>
            </div>
        </a>
        <!-- Close Button (Visible ONLY on Mobile/Tablet, completely removed on Desktop) -->
        <button id="mobile-sidebar-close" type="button" onclick="closeMobileSidebar()" class="lg:hidden p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors shrink-0 flex items-center justify-center cursor-pointer" title="Close Sidebar" aria-label="Close Sidebar">
            <span class="material-symbols-outlined text-[24px]">close</span>
        </button>
    </div>
    
    <!-- Navigation Links (Role-Scoped) -->
    <ul class="flex flex-col gap-xs flex-1 overflow-y-auto custom-scrollbar">
        <?php foreach ($filteredNavItems as $item): ?>
            <?php 
                $isActive = ($activePage === $item['id']);
                $activeClass = $isActive 
                    ? 'text-primary dark:text-primary-fixed-dim font-bold bg-surface-container dark:bg-surface-container-low' 
                    : 'text-on-surface-variant dark:text-surface-variant hover:bg-surface-container dark:hover:bg-surface-container-low';
                $iconFill = $isActive ? 'fill' : '';
            ?>
            <li>
                <a class="flex items-center gap-sm px-md py-sm rounded transition-colors group relative <?php echo $activeClass; ?>" href="<?php echo htmlspecialchars($item['url']); ?>" title="<?php echo htmlspecialchars($item['label']); ?>">
                    <span class="material-symbols-outlined shrink-0 text-[22px] <?php echo $iconFill; ?>"><?php echo htmlspecialchars($item['icon']); ?></span>
                    <span class="font-label-md text-label-md sidebar-text whitespace-nowrap transition-opacity duration-200"><?php echo htmlspecialchars($item['label']); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- User Profile & Sign Out Box in Sidebar Footer -->
    <div class="pt-sm border-t border-outline-variant mt-auto flex flex-col gap-2">
        <!-- User Info Card -->
        <div class="flex items-center gap-sm min-w-0 p-1 rounded-lg bg-surface-container-low/50">
            <div class="h-9 w-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0">
                <?php
                    $initials = '';
                    $words = explode(' ', $currentUser['full_name'] ?? 'Staff');
                    foreach (array_slice($words, 0, 2) as $w) {
                        $initials .= strtoupper(substr($w, 0, 1));
                    }
                    echo htmlspecialchars($initials ?: 'ST');
                ?>
            </div>
            <div class="min-w-0 flex-1 sidebar-text transition-opacity duration-200">
                <p class="font-body-sm text-xs font-semibold text-on-surface truncate"><?php echo htmlspecialchars($currentUser['full_name'] ?? 'Staff'); ?></p>
                <p class="font-label-md text-[10px] text-on-surface-variant truncate"><?php echo htmlspecialchars(getRoleDisplayName($currentUser['role'] ?? 'Staff')); ?></p>
            </div>
        </div>

        <!-- Sidebar Sign Out Button -->
        <a href="logout.php" class="sidebar-logout-btn flex items-center gap-sm px-md py-2 rounded-lg text-error hover:bg-error-container/40 transition-colors font-medium text-xs border border-error/10" title="Sign Out of MedCore Systems">
            <span class="material-symbols-outlined text-[20px] shrink-0">logout</span>
            <span class="sidebar-text font-label-md text-xs whitespace-nowrap">Sign Out</span>
        </a>
    </div>
</nav>
