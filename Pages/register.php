<?php
/**
 * MedCore Systems - Staff Registration Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';
require_once __DIR__ . '/../OPERATIONS/UserOperation.php';
require_once __DIR__ . '/../CONTROLS/AuthController.php';

initSecureSession();

if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    safeRedirect(getRoleDefaultPage($currentUser['role'] ?? ''));
}

$errorMessage = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AuthController::register($_POST);
    if (isset($result['error'])) {
        $errorMessage = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport">
    <title>Staff Registration - MedCore Systems</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Courier+Prime:wght@400;700&amp;display=swap" rel="stylesheet">
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "surface": "#f7f9ff",
                        "primary": "#0041a2",
                        "primary-container": "#0b57d0",
                        "on-primary": "#ffffff",
                        "on-surface": "#181c20",
                        "on-surface-variant": "#424654",
                        "secondary": "#006b5e",
                        "secondary-fixed": "#97f3e2",
                        "on-secondary-fixed": "#00201b",
                        "tertiary": "#733700",
                        "error": "#ba1a1a",
                        "error-container": "#ffdad6",
                        "on-error-container": "#93000a",
                        "background": "#f7f9ff",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f1f4fa",
                        "surface-container": "#ebeef4",
                        "outline": "#737785",
                        "outline-variant": "#c3c6d6",
                        "primary-fixed": "#dae2ff",
                        "on-primary-fixed": "#001847"
                    },
                    "fontFamily": {
                        "body": ["Inter", "sans-serif"],
                        "code": ["Courier Prime", "monospace"]
                    }
                }
            }
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .material-symbols-outlined.fill {
            font-variation-settings: 'FILL' 1;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-surface-container-low via-background to-primary-fixed/20 text-on-surface font-body min-h-screen flex items-center justify-center p-4 py-8">

    <div class="max-w-xl w-full my-auto">
        <!-- Brand Header -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-primary text-on-primary shadow-lg shadow-primary/20 mb-2">
                <span class="material-symbols-outlined text-[32px] fill">local_hospital</span>
            </div>
            <h1 class="text-2xl font-bold text-primary tracking-tight">MedCore Systems</h1>
            <p class="text-xs text-on-surface-variant mt-1">Staff Access &amp; Clinical Operations Registration</p>
        </div>

        <!-- Registration Card -->
        <div class="bg-surface-container-lowest border border-outline-variant/80 rounded-2xl shadow-xl p-6 sm:p-8 relative">
            <div class="flex items-center justify-between pb-4 mb-5 border-b border-outline-variant/60">
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-on-surface">Staff Registration</h2>
                    <p class="text-xs text-on-surface-variant">Create your account to access the hospital management portal.</p>
                </div>
                <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-primary-fixed text-on-primary-fixed">
                    Hospital Staff
                </span>
            </div>

            <!-- Error Banner -->
            <?php if (!empty($errorMessage)): ?>
                <div class="mb-4 p-3 rounded-lg bg-error-container border border-error/30 text-on-error-container text-xs flex items-start gap-2">
                    <span class="material-symbols-outlined text-[18px] text-error shrink-0 mt-0.5">error</span>
                    <div>
                        <p class="font-bold">Registration Notice</p>
                        <p class="mt-0.5"><?php echo e($errorMessage); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form method="POST" action="register.php" class="space-y-4">
                <!-- CSRF Token -->
                <?php echo csrfField(); ?>

                <!-- Name & Professional Title -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Full Name *</label>
                        <input name="full_name" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-colors" placeholder="e.g. Dr. Emily Stone" required type="text" value="<?php echo e($_POST['full_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Professional Title *</label>
                        <input name="professional_title" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-colors" placeholder="e.g. MD, RN, PharmD, Admin" required type="text" value="<?php echo e($_POST['professional_title'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Role Selector (Full Width) -->
                <div>
                    <label class="block text-xs font-semibold text-on-surface-variant mb-1">Role *</label>
                    <div class="relative">
                        <select name="role" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2.5 pl-3 pr-8 text-xs sm:text-sm text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-colors appearance-none cursor-pointer" required>
                            <option value="" disabled <?php echo empty($_POST['role']) ? 'selected' : ''; ?>>Select your role</option>
                            <option value="doctor" <?php echo (($_POST['role'] ?? '') === 'doctor') ? 'selected' : ''; ?>>Doctor</option>
                            <option value="pharmacy" <?php echo (($_POST['role'] ?? '') === 'pharmacy') ? 'selected' : ''; ?>>Pharmacy</option>
                            <option value="reception_cashier" <?php echo (($_POST['role'] ?? '') === 'reception_cashier') ? 'selected' : ''; ?>>Reception and Cashier</option>
                            <option value="laboratory" <?php echo (($_POST['role'] ?? '') === 'laboratory') ? 'selected' : ''; ?>>Laboratory</option>
                            <option value="manager" <?php echo (($_POST['role'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager</option>
                            <option value="superadmin_ict" <?php echo (($_POST['role'] ?? '') === 'superadmin_ict') ? 'selected' : ''; ?>>Superadmin ICT</option>
                        </select>
                        <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px] pointer-events-none">expand_more</span>
                    </div>
                </div>

                <!-- Email & Phone -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Work Email *</label>
                        <input name="email" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary outline-none" placeholder="name@medcore.org" required type="email" value="<?php echo e($_POST['email'] ?? ''); ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Phone Number</label>
                        <input name="phone" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary outline-none" placeholder="(555) 019-2834" type="tel" value="<?php echo e($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Password & Confirm Password -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Password *</label>
                        <input name="password" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary outline-none" placeholder="Min. 8 characters" required type="password" minlength="8">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-on-surface-variant mb-1">Confirm Password *</label>
                        <input name="confirm_password" class="w-full bg-surface-container-low border border-outline-variant rounded-lg py-2 px-3 text-xs sm:text-sm text-on-surface placeholder:text-outline focus:border-primary outline-none" placeholder="Confirm password" required type="password" minlength="8">
                    </div>
                </div>

                <!-- Policy Agreement -->
                <div class="pt-1">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input name="agree_policy" type="checkbox" value="1" required class="rounded border-outline-variant text-primary focus:ring-primary mt-0.5" <?php echo !empty($_POST['agree_policy']) ? 'checked' : ''; ?>>
                        <span class="text-xs text-on-surface-variant leading-tight">
                            I certify that the provided information is accurate and agree to adhere to HIPAA patient privacy protocols and hospital data governance policies.
                        </span>
                    </label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="w-full bg-primary hover:bg-primary-container text-on-primary font-semibold text-sm py-3 rounded-lg shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center gap-2 cursor-pointer mt-2">
                    <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                    Submit Registration
                </button>
            </form>
        </div>

        <!-- Back to Login -->
        <p class="text-center text-xs text-on-surface-variant mt-5">
            Already have an account?
            <a href="login.php" class="text-primary font-bold hover:underline ml-1">Sign In</a>
        </p>
    </div>
</body>
</html>
