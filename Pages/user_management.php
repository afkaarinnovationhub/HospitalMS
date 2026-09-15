<?php
/**
 * MedCore Systems - User Accounts & Staff Management
 * Complete user administration, role assignment, profile editing, and active/inactive status controls.
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/UserOperation.php';
require_once __DIR__ . '/../CONTROLS/UserController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT, ROLE_MANAGER]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_user') {
        $result = UserController::handleCreateUser($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_user') {
        $result = UserController::handleEditUser($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'toggle_status') {
        $result = UserController::handleToggleStatus($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_user') {
        $result = UserController::handleDeleteUser($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search & Filter Parameters
$searchQuery  = sanitizeString($_GET['search'] ?? '');
$roleFilter   = sanitizeString($_GET['role'] ?? 'all');
$statusFilter = sanitizeString($_GET['status'] ?? 'all');

$users = UserOperation::getAllUsers($searchQuery, $roleFilter, $statusFilter);

$totalUsers    = count($users);
$activeUsers   = count(array_filter($users, fn($u) => $u['account_status'] === 'active'));
$inactiveUsers = count(array_filter($users, fn($u) => $u['account_status'] === 'inactive'));

$currentUser = getCurrentUser();
$currentUserId = (int)($currentUser['id'] ?? 0);
$isSuperAdmin = ($currentUser['role'] ?? '') === ROLE_SUPERADMIN_ICT;

$pageTitle = 'User Management - ' . HOSPITAL_NAME;
$headerTitle = HOSPITAL_NAME . ' - Staff Users';
$activePage = 'users';

include __DIR__ . '/../components/header.php';
?>

<!-- User Management Main Canvas -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[28px]">manage_accounts</span>
                    User Accounts &amp; Staff Management
                </h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">
                    Manage system logins, assign operational roles, control active/inactive account access, and reset passwords.
                </p>
            </div>
            <!-- Add New User Trigger Button -->
            <button type="button" onclick="openCreateUserModal()" class="w-full sm:w-auto flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-xl hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">person_add</span>
                Register New User
            </button>
        </div>

        <!-- Alert Notifications -->
        <?php if (!empty($errorMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-error-container border border-error/30 text-on-error-container text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-error text-[20px] shrink-0 mt-0.5">error</span>
                <div>
                    <p class="font-bold">Error Alert</p>
                    <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="p-3 sm:p-4 rounded-xl bg-secondary-fixed/40 border border-secondary/30 text-on-secondary-fixed-variant text-xs sm:text-sm flex items-start gap-3 shadow-xs">
                <span class="material-symbols-outlined text-secondary text-[20px] shrink-0 mt-0.5">check_circle</span>
                <div>
                    <p class="font-bold">Operation Successful</p>
                    <p class="mt-0.5"><?php echo e($successMessage); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Metric Summary Badges -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-on-surface-variant block">Total Staff Accounts</span>
                    <h3 class="text-2xl font-bold text-on-surface font-mono mt-1"><?php echo $totalUsers; ?></h3>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">Across all hospital departments</p>
                </div>
                <div class="h-12 w-12 rounded-xl bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">group</span>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-on-surface-variant block">Active Logins</span>
                    <h3 class="text-2xl font-bold text-secondary font-mono mt-1"><?php echo $activeUsers; ?></h3>
                    <p class="text-[11px] text-secondary font-medium mt-0.5 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[13px]">check_circle</span>
                        Access enabled
                    </p>
                </div>
                <div class="h-12 w-12 rounded-xl bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">verified_user</span>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-surface border border-outline-variant shadow-xs flex items-center justify-between">
                <div>
                    <span class="text-xs font-semibold text-on-surface-variant block">Inactive / Blocked</span>
                    <h3 class="text-2xl font-bold <?php echo ($inactiveUsers > 0) ? 'text-error' : 'text-on-surface-variant'; ?> font-mono mt-1"><?php echo $inactiveUsers; ?></h3>
                    <p class="text-[11px] text-on-surface-variant mt-0.5">Suspended staff logins</p>
                </div>
                <div class="h-12 w-12 rounded-xl bg-error-container text-on-error-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[24px]">block</span>
                </div>
            </div>
        </div>

        <!-- Search & Filter Controls -->
        <div class="bg-surface border border-outline-variant rounded-2xl p-4 shadow-xs">
            <form method="GET" action="user_management.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                <div class="sm:col-span-6 relative">
                    <span class="material-symbols-outlined absolute left-3 top-2.5 text-on-surface-variant text-[20px]">search</span>
                    <input type="text" name="search" value="<?php echo e($searchQuery); ?>" placeholder="Search by name, username, email, phone, or title..." class="w-full bg-surface-container-low border border-outline-variant rounded-xl pl-10 pr-4 py-2 text-xs text-on-surface focus:border-primary outline-none">
                </div>

                <div class="sm:col-span-3">
                    <select name="role" class="w-full bg-surface-container-low border border-outline-variant rounded-xl px-3 py-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="all" <?php echo ($roleFilter === 'all') ? 'selected' : ''; ?>>All Roles</option>
                        <option value="superadmin_ict" <?php echo ($roleFilter === 'superadmin_ict') ? 'selected' : ''; ?>>Superadmin ICT</option>
                        <option value="doctor" <?php echo ($roleFilter === 'doctor') ? 'selected' : ''; ?>>Doctor / Clinician</option>
                        <option value="reception_cashier" <?php echo ($roleFilter === 'reception_cashier') ? 'selected' : ''; ?>>Reception &amp; Cashier</option>
                        <option value="pharmacy" <?php echo ($roleFilter === 'pharmacy') ? 'selected' : ''; ?>>Pharmacy Specialist</option>
                        <option value="laboratory" <?php echo ($roleFilter === 'laboratory') ? 'selected' : ''; ?>>Laboratory Technologist</option>
                        <option value="manager" <?php echo ($roleFilter === 'manager') ? 'selected' : ''; ?>>Hospital Manager</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <select name="status" class="w-full bg-surface-container-low border border-outline-variant rounded-xl px-3 py-2 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active Only</option>
                        <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive Only</option>
                    </select>
                </div>

                <div class="sm:col-span-1 flex gap-2">
                    <button type="submit" class="w-full bg-primary text-on-primary rounded-xl py-2 text-xs font-bold hover:bg-primary-container transition-colors cursor-pointer flex items-center justify-center" title="Filter Users">
                        <span class="material-symbols-outlined text-[18px]">filter_list</span>
                    </button>
                    <?php if (!empty($searchQuery) || $roleFilter !== 'all' || $statusFilter !== 'all'): ?>
                        <a href="user_management.php" class="p-2 bg-surface-container border border-outline-variant rounded-xl text-on-surface-variant hover:text-on-surface flex items-center justify-center cursor-pointer" title="Reset Filters">
                            <span class="material-symbols-outlined text-[18px]">close</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Users Directory Table -->
        <div class="bg-surface border border-outline-variant rounded-2xl shadow-xs overflow-hidden">
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-outline-variant text-on-surface-variant font-bold bg-surface-container-low">
                            <th class="py-3 px-4">Staff Member</th>
                            <th class="py-3 px-4">Username &amp; Contact</th>
                            <th class="py-3 px-4">Assigned Role</th>
                            <th class="py-3 px-4">Department / Title</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/60">
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-[36px] text-on-surface-variant block mb-1">person_search</span>
                                    No staff accounts found matching the criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <?php 
                                    $uId = (int)$u['id'];
                                    $isSelf = ($currentUserId === $uId);
                                    $isActive = ($u['account_status'] === 'active');
                                    
                                    // Color badge per role
                                    $roleBadgeClass = match($u['role']) {
                                        'superadmin_ict'    => 'bg-purple-500/15 text-purple-700 border border-purple-300 dark:border-purple-800',
                                        'doctor'            => 'bg-blue-500/15 text-blue-700 border border-blue-300 dark:border-blue-800',
                                        'reception_cashier' => 'bg-teal-500/15 text-teal-700 border border-teal-300 dark:border-teal-800',
                                        'pharmacy'          => 'bg-emerald-500/15 text-emerald-700 border border-emerald-300 dark:border-emerald-800',
                                        'laboratory'        => 'bg-amber-500/15 text-amber-700 border border-amber-300 dark:border-amber-800',
                                        'manager'           => 'bg-indigo-500/15 text-indigo-700 border border-indigo-300 dark:border-indigo-800',
                                        default             => 'bg-surface-container text-on-surface-variant',
                                    };

                                    // Initials
                                    $words = explode(' ', $u['full_name']);
                                    $initials = '';
                                    foreach (array_slice($words, 0, 2) as $w) {
                                        $initials .= strtoupper(substr($w, 0, 1));
                                    }
                                ?>
                                <tr class="hover:bg-surface-container-low transition-colors">
                                    <!-- Staff Member Profile -->
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="h-9 w-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0 shadow-xs">
                                                <?php echo htmlspecialchars($initials ?: 'ST'); ?>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-bold text-on-surface text-sm"><?php echo e($u['full_name']); ?></span>
                                                    <?php if ($isSelf): ?>
                                                        <span class="text-[10px] px-1.5 py-0.2 rounded bg-primary/10 text-primary font-bold">You</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-[10px] text-on-surface-variant font-mono block mt-0.5">Joined: <?php echo date('M d, Y', strtotime($u['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Username & Contact -->
                                    <td class="py-3 px-4">
                                        <div class="space-y-0.5">
                                            <div class="flex items-center gap-1 font-mono font-semibold text-primary">
                                                <span class="material-symbols-outlined text-[14px]">alternate_email</span>
                                                <span><?php echo e($u['username']); ?></span>
                                            </div>
                                            <div class="text-[11px] text-on-surface-variant"><?php echo e($u['email']); ?></div>
                                            <?php if (!empty($u['phone'])): ?>
                                                <div class="text-[10px] font-mono text-on-surface-variant"><?php echo e($u['phone']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Assigned Role -->
                                    <td class="py-3 px-4">
                                        <span class="text-[11px] px-2.5 py-1 rounded-lg font-bold inline-block <?php echo $roleBadgeClass; ?>">
                                            <?php echo htmlspecialchars(getRoleDisplayName($u['role'])); ?>
                                        </span>
                                        <?php if ($u['role'] === 'doctor' && isset($u['consultation_fee'])): ?>
                                            <span class="text-[10px] text-secondary font-mono font-bold block mt-1">Fee: $<?php echo number_format((float)$u['consultation_fee'], 2); ?></span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Department / Title -->
                                    <td class="py-3 px-4 text-on-surface font-medium">
                                        <?php echo e($u['professional_title'] ?: 'Clinical Staff'); ?>
                                    </td>

                                    <!-- Status -->
                                    <td class="py-3 px-4">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-secondary-fixed text-on-secondary-fixed">
                                                <span class="h-1.5 w-1.5 rounded-full bg-secondary"></span>
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-error-container text-on-error-container">
                                                <span class="h-1.5 w-1.5 rounded-full bg-error"></span>
                                                Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="py-3 px-4 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <!-- Toggle Status Button -->
                                            <?php if (!$isSelf): ?>
                                                <form method="POST" action="user_management.php" class="inline">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $uId; ?>">
                                                    <input type="hidden" name="status" value="<?php echo $isActive ? 'inactive' : 'active'; ?>">
                                                    <?php if ($isActive): ?>
                                                        <button type="submit" onclick="return confirm('Are you sure you want to deactivate <?php echo e(addslashes($u['full_name'])); ?>? They will be unable to log in.')" class="px-2.5 py-1 bg-surface-container border border-outline-variant hover:bg-error-container hover:text-on-error-container text-on-surface rounded-lg text-xs font-semibold transition-colors cursor-pointer flex items-center gap-1" title="Deactivate User">
                                                            <span class="material-symbols-outlined text-[15px] text-error">block</span>
                                                            Deactivate
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" onclick="return confirm('Activate login access for <?php echo e(addslashes($u['full_name'])); ?>?')" class="px-2.5 py-1 bg-secondary text-on-secondary rounded-lg text-xs font-bold hover:bg-on-secondary-container transition-colors cursor-pointer flex items-center gap-1 shadow-2xs" title="Activate User">
                                                            <span class="material-symbols-outlined text-[15px]">check_circle</span>
                                                            Activate
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Edit User Button -->
                                            <button type="button" 
                                                    onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                    class="p-1.5 bg-surface-container border border-outline-variant hover:bg-surface-container-high text-primary rounded-lg transition-colors cursor-pointer" 
                                                    title="Edit User Profile &amp; Role">
                                                <span class="material-symbols-outlined text-[17px]">edit</span>
                                            </button>

                                            <!-- Delete User Button (SuperAdmin Only) -->
                                            <?php if ($isSuperAdmin && !$isSelf): ?>
                                                <form method="POST" action="user_management.php" class="inline" onsubmit="return confirm('PERMANENT ACTION: Are you sure you want to completely delete <?php echo e(addslashes($u['full_name'])); ?>?');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $uId; ?>">
                                                    <button type="submit" class="p-1.5 bg-surface-container border border-outline-variant hover:bg-error-container text-error rounded-lg transition-colors cursor-pointer" title="Delete User">
                                                        <span class="material-symbols-outlined text-[17px]">delete</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- MODAL: Register New User -->
<div id="create-user-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">person_add</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Register New Staff Account</h3>
                    <p class="text-xs text-on-surface-variant">Create a system login with assigned operational privileges.</p>
                </div>
            </div>
            <button type="button" onclick="closeCreateUserModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="user_management.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_user">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Full Name *</label>
                <input name="full_name" type="text" required placeholder="e.g. Dr. Ahmed Warsame" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Username *</label>
                    <input name="username" type="text" required placeholder="e.g. ahmed_w" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs font-mono text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address *</label>
                    <input name="email" type="email" required placeholder="e.g. ahmed@cibaarhospital.so" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Password (Min 8 Chars) *</label>
                    <input name="password" type="password" required minlength="8" placeholder="••••••••" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">System Role *</label>
                    <select name="role" id="create-user-role" onchange="toggleFeeInput('create')" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="doctor">Doctor / Clinician</option>
                        <option value="reception_cashier">Reception &amp; Cashier</option>
                        <option value="pharmacy">Pharmacy Specialist</option>
                        <option value="laboratory">Laboratory Technologist</option>
                        <option value="manager">Hospital Manager</option>
                        <?php if ($isSuperAdmin): ?>
                            <option value="superadmin_ict">Superadmin ICT</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div id="create-fee-container" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Consultation Fee ($ USD)</label>
                    <input name="consultation_fee" type="number" step="0.50" min="0" placeholder="10.00" value="10.00" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Initial Account Status</label>
                    <select name="account_status" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="active">Active (Login Enabled)</option>
                        <option value="inactive">Inactive (Suspended)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Professional Title / Specialty</label>
                    <input name="professional_title" type="text" placeholder="e.g. MD, Pediatrician / Intake Officer" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number</label>
                    <input name="phone" type="text" placeholder="e.g. (555) 012-4455" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeCreateUserModal()" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">how_to_reg</span>
                    Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit User -->
<div id="edit-user-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full p-6 shadow-2xl custom-scrollbar max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">manage_accounts</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Staff Account</h3>
                    <p class="text-xs text-on-surface-variant">Update profile, change roles, or reset login password.</p>
                </div>
            </div>
            <button type="button" onclick="closeEditUserModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="user_management.php" class="space-y-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit-user-id" value="">

            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Full Name *</label>
                <input name="full_name" id="edit-full-name" type="text" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Username *</label>
                    <input name="username" id="edit-username" type="text" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs font-mono text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address *</label>
                    <input name="email" id="edit-email" type="email" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">New Password (Leave blank to keep current)</label>
                    <input name="password" id="edit-password" type="password" minlength="8" placeholder="••••••••" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">System Role *</label>
                    <select name="role" id="edit-role" onchange="toggleFeeInput('edit')" required class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="doctor">Doctor / Clinician</option>
                        <option value="reception_cashier">Reception &amp; Cashier</option>
                        <option value="pharmacy">Pharmacy Specialist</option>
                        <option value="laboratory">Laboratory Technologist</option>
                        <option value="manager">Hospital Manager</option>
                        <?php if ($isSuperAdmin): ?>
                            <option value="superadmin_ict">Superadmin ICT</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div id="edit-fee-container">
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Consultation Fee ($ USD)</label>
                    <input name="consultation_fee" id="edit-consultation-fee" type="number" step="0.50" min="0" placeholder="10.00" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Status *</label>
                    <select name="account_status" id="edit-status" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                        <option value="active">Active (Login Enabled)</option>
                        <option value="inactive">Inactive (Suspended)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Professional Title</label>
                    <input name="professional_title" id="edit-title" type="text" placeholder="e.g. Senior Medical Officer" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number</label>
                    <input name="phone" id="edit-phone" type="text" placeholder="e.g. (555) 012-4455" class="w-full bg-surface-container-low border border-outline-variant rounded-lg p-2.5 text-xs text-on-surface focus:border-primary outline-none">
                </div>
            </div>

            <div class="pt-3 border-t border-outline-variant flex justify-end gap-2">
                <button type="button" onclick="closeEditUserModal()" class="px-3.5 py-2 bg-surface-container text-on-surface rounded-lg text-xs font-semibold hover:bg-surface-container-high cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-bold hover:bg-primary-container shadow-xs cursor-pointer flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateUserModal() {
        document.getElementById('create-user-modal').classList.remove('hidden');
        toggleFeeInput('create');
    }

    function closeCreateUserModal() {
        document.getElementById('create-user-modal').classList.add('hidden');
    }

    function openEditUserModal(user) {
        document.getElementById('edit-user-id').value = user.id;
        document.getElementById('edit-full-name').value = user.full_name || '';
        document.getElementById('edit-username').value = user.username || '';
        document.getElementById('edit-email').value = user.email || '';
        document.getElementById('edit-password').value = '';
        document.getElementById('edit-role').value = user.role || 'doctor';
        document.getElementById('edit-title').value = user.professional_title || '';
        document.getElementById('edit-phone').value = user.phone || '';
        document.getElementById('edit-status').value = user.account_status || 'active';
        document.getElementById('edit-consultation-fee').value = user.consultation_fee ? parseFloat(user.consultation_fee).toFixed(2) : '10.00';

        toggleFeeInput('edit');
        document.getElementById('edit-user-modal').classList.remove('hidden');
    }

    function closeEditUserModal() {
        document.getElementById('edit-user-modal').classList.add('hidden');
    }

    function toggleFeeInput(formType) {
        if (formType === 'create') {
            const role = document.getElementById('create-user-role').value;
            const feeCont = document.getElementById('create-fee-container');
            if (role === 'doctor') {
                feeCont.classList.remove('hidden');
            } else {
                feeCont.classList.add('hidden');
            }
        } else if (formType === 'edit') {
            const role = document.getElementById('edit-role').value;
            const feeCont = document.getElementById('edit-fee-container');
            if (role === 'doctor') {
                feeCont.classList.remove('hidden');
            } else {
                feeCont.classList.add('hidden');
            }
        }
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
