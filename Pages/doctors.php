<?php
/**
 * MedCore Systems - Real Doctors Directory & Staff Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/DoctorOperation.php';
require_once __DIR__ . '/../CONTROLS/DoctorController.php';

initSecureSession();
requireLogin();
requireRole([ROLE_SUPERADMIN_ICT]);

$errorMessage = null;
$successMessage = getFlashMessage('success');
$flashError = getFlashMessage('error');
if ($flashError) {
    $errorMessage = $flashError;
}

// Handle Form Submissions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_doctor') {
        $result = DoctorController::handleCreateDoctor($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'edit_doctor') {
        $result = DoctorController::handleEditDoctor($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    } elseif ($action === 'delete_doctor') {
        $result = DoctorController::handleDeleteDoctor($_POST);
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        }
    }
}

// Search & Filter
$searchQuery  = sanitizeString($_GET['search'] ?? '');
$statusFilter = sanitizeString($_GET['status'] ?? 'all');

$doctors = DoctorOperation::getAllDoctors($searchQuery, $statusFilter);

$totalDocs = count($doctors);
$activeDocs = count(array_filter($doctors, fn($d) => $d['account_status'] === 'active'));
$totalWaiting = array_sum(array_column($doctors, 'waiting_count'));
$totalCompleted = array_sum(array_column($doctors, 'completed_today_count'));

$pageTitle = 'Doctors & Clinical Staff - MedCore Systems';
$headerTitle = 'MedCore Management - Doctors';
$activePage = 'doctors';

include __DIR__ . '/../components/header.php';
?>

<!-- Doctors Directory Main Canvas -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Doctors &amp; Clinical Staff Directory</h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">Manage physician accounts, assign specialties, and monitor consultation workloads.</p>
            </div>
            <!-- Add New Doctor Trigger Button -->
            <button type="button" onclick="openDoctorModal()" class="w-full sm:w-auto flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">person_add</span>
                Register New Doctor
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

        <!-- Metric Summary Bento Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Total Physicians</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1"><?php echo $totalDocs; ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">stethoscope</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Active &amp; On Duty</p>
                    <p class="font-display-lg text-2xl font-bold text-secondary mt-1"><?php echo $activeDocs; ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">check_circle</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Patients Waiting Today</p>
                    <p class="font-display-lg text-2xl font-bold text-primary mt-1"><?php echo $totalWaiting; ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-fixed text-on-primary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">hourglass_top</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Completed Consultations</p>
                    <p class="font-display-lg text-2xl font-bold text-tertiary mt-1"><?php echo $totalCompleted; ?></p>
                </div>
                <div class="w-10 h-10 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">task_alt</span>
                </div>
            </div>
        </div>

        <!-- Doctors Directory Table Container -->
        <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
            <!-- Table Toolbar & Search Filters -->
            <form method="GET" action="doctors.php" class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto flex-1 max-w-xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input name="search" value="<?php echo e($searchQuery); ?>" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none" placeholder="Search doctor by name, username, or specialty..." type="text">
                    </div>
                    <select name="status" onchange="this.form.submit()" class="bg-surface border border-outline-variant rounded-lg px-3 py-1.5 text-xs text-on-surface outline-none">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active Only</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="flex items-center gap-sm">
                    <button type="submit" class="px-3 py-1.5 bg-surface-container border border-outline-variant text-on-surface text-xs font-semibold rounded-lg hover:bg-surface-container-high transition-colors cursor-pointer">
                        Filter
                    </button>
                    <a href="doctors.php" class="p-1.5 text-on-surface-variant hover:bg-surface-container rounded border border-outline-variant transition-colors flex items-center justify-center cursor-pointer" title="Reset Filters">
                        <span class="material-symbols-outlined text-sm">refresh</span>
                    </a>
                </div>
            </form>

            <!-- Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[800px]">
                    <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant sticky top-0">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Doctor Name &amp; Username</th>
                            <th class="py-3 px-4 font-semibold">Specialization / Title</th>
                            <th class="py-3 px-4 font-semibold">Consultation Fee</th>
                            <th class="py-3 px-4 font-semibold">Contact Details</th>
                            <th class="py-3 px-4 font-semibold">Today's Workload</th>
                            <th class="py-3 px-4 font-semibold">Account Status</th>
                            <th class="py-3 px-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="font-body-sm text-xs sm:text-body-sm text-on-surface divide-y divide-outline-variant">
                        <?php if (empty($doctors)): ?>
                            <tr>
                                <td colspan="7" class="py-8 text-center text-on-surface-variant">
                                    <span class="material-symbols-outlined text-3xl mb-1 text-outline">person_search</span>
                                    <p class="font-semibold">No doctors found in the directory.</p>
                                    <p class="text-xs mt-1">Click "Register New Doctor" above to onboard your medical staff.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($doctors as $d): ?>
                                <?php
                                    $initials = strtoupper(substr($d['full_name'], 0, 2));
                                    $isActive = $d['account_status'] === 'active';
                                    $fee = (float)($d['consultation_fee'] ?? 10.00);
                                ?>
                                <tr class="hover:bg-surface-container-low transition-colors group">
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-sm">
                                            <div class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0">
                                                <?php echo e($initials); ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-on-surface"><?php echo e($d['full_name']); ?></p>
                                                <span class="font-code-md text-[11px] text-on-surface-variant">@<?php echo e($d['username']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="font-semibold text-primary"><?php echo e($d['professional_title'] ?: 'General Practice'); ?></p>
                                        <p class="text-[11px] text-on-surface-variant capitalize"><?php echo e($d['role']); ?></p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-mono font-bold text-secondary text-xs px-2.5 py-1 bg-secondary-fixed/40 rounded-lg inline-block border border-secondary/20">
                                            $<?php echo number_format($fee, 2); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="text-xs"><?php echo e($d['email']); ?></p>
                                        <p class="font-mono text-[11px] text-on-surface-variant"><?php echo e($d['phone'] ?: 'No phone'); ?></p>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <span class="bg-primary-fixed text-on-primary-fixed text-xs px-2 py-0.5 rounded font-bold" title="Waiting in Queue">
                                                <?php echo (int)$d['waiting_count']; ?> Waiting
                                            </span>
                                            <span class="bg-surface-container text-on-surface text-xs px-2 py-0.5 rounded font-medium" title="Completed Today">
                                                <?php echo (int)$d['completed_today_count']; ?> Done
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center gap-1 text-xs font-bold text-secondary bg-secondary-fixed/50 px-2.5 py-0.5 rounded-full">
                                                <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 text-xs font-bold text-on-surface-variant bg-surface-container px-2.5 py-0.5 rounded-full capitalize">
                                                <?php echo e($d['account_status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <button type="button" 
                                                    onclick="openEditDoctorModal(<?php echo htmlspecialchars(json_encode($d), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                    class="p-1 text-on-surface-variant hover:text-primary hover:bg-surface-container rounded transition-colors cursor-pointer" 
                                                    title="Edit Doctor">
                                                <span class="material-symbols-outlined text-[17px]">edit</span>
                                            </button>
                                            <form method="POST" action="doctors.php" class="inline" onsubmit="return confirm('Are you sure you want to remove Dr. <?php echo e(addslashes($d['full_name'])); ?>?');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_doctor">
                                                <input type="hidden" name="doctor_id" value="<?php echo (int)$d['id']; ?>">
                                                <button type="submit" class="p-1 text-on-surface-variant hover:text-error hover:bg-error-container/30 rounded transition-colors cursor-pointer" title="Delete Doctor">
                                                    <span class="material-symbols-outlined text-[17px]">delete</span>
                                                </button>
                                            </form>
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

<!-- MODAL 1: Register New Doctor -->
<div id="doctor-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[26px]">person_add</span>
                <div>
                    <h3 class="font-headline-sm text-base font-bold text-on-surface">Register New Doctor</h3>
                    <p class="text-xs text-on-surface-variant">Create a physician staff account with medical credentials.</p>
                </div>
            </div>
            <button type="button" onclick="closeDoctorModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="doctors.php" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_doctor">

            <!-- Section 1: Basic Identity & Login Credentials -->
            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Doctor Full Name *</label>
                <input name="full_name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Dr. Mohamed Abdi" type="text">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Username *</label>
                    <input name="username" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. dr_mohamed" type="text">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Password *</label>
                    <input name="password" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="Min. 6 chars" type="password">
                </div>
            </div>

            <!-- Line Separator: Contact Details -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address *</label>
                        <input name="email" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. dr.mohamed@gmail.com" type="email">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number</label>
                        <input name="phone" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. +25261 XXXXXXX" type="text">
                    </div>
                </div>
            </div>

            <!-- Line Separator: Clinical Specialty & Billing Fee -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Specialization / Department *</label>
                        <input name="professional_title" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface focus:border-primary outline-none" placeholder="e.g. Senior Cardiologist" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Consultation Fee ($ USD) *</label>
                        <input name="consultation_fee" type="number" step="0.50" min="0" value="10.00" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs font-mono font-bold text-on-surface focus:border-primary outline-none" placeholder="10.00">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Status</label>
                        <select name="account_status" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                            <option value="active">Active (On Duty)</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeDoctorModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm flex items-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-[16px]">how_to_reg</span>
                    Save &amp; Register Doctor
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Edit Doctor Profile -->
<div id="edit-doctor-modal" class="fixed inset-0 z-50 bg-black/60 hidden backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-surface rounded-2xl border border-outline-variant max-w-lg w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl custom-scrollbar">
        <div class="flex justify-between items-center pb-3 border-b border-outline-variant mb-4">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-[24px]">edit</span>
                <h3 class="font-headline-sm text-base font-bold text-on-surface">Edit Doctor Profile</h3>
            </div>
            <button type="button" onclick="closeEditDoctorModal()" class="text-on-surface-variant hover:text-on-surface p-1 rounded-lg cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <form method="POST" action="doctors.php" class="space-y-3.5">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit_doctor">
            <input type="hidden" id="edit_doc_id" name="doctor_id" value="">

            <!-- Section 1: Doctor Identity -->
            <div>
                <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Doctor Full Name *</label>
                <input id="edit_doc_name" name="full_name" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
            </div>

            <!-- Line Separator: Contact Details -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Email Address *</label>
                        <input id="edit_doc_email" name="email" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="email">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Phone Number</label>
                        <input id="edit_doc_phone" name="phone" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
                    </div>
                </div>
            </div>

            <!-- Line Separator: Specialty & Fee -->
            <div class="border-t border-outline-variant/60 pt-3">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Specialization / Department *</label>
                        <input id="edit_doc_title" name="professional_title" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" type="text">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Consultation Fee ($ USD) *</label>
                        <input id="edit_doc_fee" name="consultation_fee" type="number" step="0.50" min="0" required class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs font-mono font-bold text-on-surface outline-none focus:border-primary" placeholder="10.00">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-on-surface mb-0.5">Account Status</label>
                        <select id="edit_doc_status" name="account_status" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Line Separator: Password Reset -->
            <div class="border-t border-outline-variant/60 pt-3">
                <label class="block text-[11px] text-on-surface-variant mb-0.5">Reset Password (leave blank to keep current)</label>
                <input name="password" class="w-full bg-surface-container-low border border-outline-variant rounded p-2 text-xs text-on-surface outline-none focus:border-primary" placeholder="New password" type="password">
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-outline-variant">
                <button type="button" onclick="closeEditDoctorModal()" class="px-3 py-1.5 rounded border border-outline-variant text-xs font-semibold hover:bg-surface-container-low cursor-pointer">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-primary hover:bg-primary-container text-on-primary text-xs font-bold shadow-sm cursor-pointer">Update Doctor</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDoctorModal() {
        document.getElementById('doctor-modal').classList.remove('hidden');
    }
    function closeDoctorModal() {
        document.getElementById('doctor-modal').classList.add('hidden');
    }
    function openEditDoctorModal(d) {
        document.getElementById('edit_doc_id').value = d.id;
        document.getElementById('edit_doc_name').value = d.full_name || '';
        document.getElementById('edit_doc_email').value = d.email || '';
        document.getElementById('edit_doc_phone').value = d.phone || '';
        document.getElementById('edit_doc_title').value = d.professional_title || '';
        document.getElementById('edit_doc_fee').value = d.consultation_fee ? parseFloat(d.consultation_fee).toFixed(2) : '10.00';
        document.getElementById('edit_doc_status').value = d.account_status || 'active';
        document.getElementById('edit-doctor-modal').classList.remove('hidden');
    }
    function closeEditDoctorModal() {
        document.getElementById('edit-doctor-modal').classList.add('hidden');
    }
</script>

<?php include __DIR__ . '/../components/footer.php'; ?>
