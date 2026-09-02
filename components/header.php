<?php
/**
 * MedCore Systems - Reusable Header Component
 */

declare(strict_types=1);

require_once __DIR__ . '/../CONFIG/database.php';
require_once __DIR__ . '/../CONFIG/session.php';
require_once __DIR__ . '/../CONFIG/security.php';
require_once __DIR__ . '/../CONFIG/auth.php';

initSecureSession();
requireLogin();

$currentUser = getCurrentUser();
$userName    = $currentUser['full_name'] ?? 'Staff Member';
$userRole    = $currentUser['role'] ?? 'Staff';
$userTitle   = $currentUser['professional_title'] ?? getRoleDisplayName($userRole);
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) : 'MedCore Systems - Clinical Operations'; ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;family=Courier+Prime:wght@400;700&amp;display=swap" rel="stylesheet">
    <script src="<?php echo (strpos($_SERVER['PHP_SELF'] ?? '', '/Pages/') !== false) ? '../assets/js/live_sync.js' : 'assets/js/live_sync.js'; ?>"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "on-error": "#ffffff",
                        "surface": "#f7f9ff",
                        "on-primary-container": "#ced9ff",
                        "error": "#ba1a1a",
                        "background": "#f7f9ff",
                        "tertiary-container": "#974a00",
                        "on-primary-fixed": "#001847",
                        "secondary-fixed": "#97f3e2",
                        "on-tertiary": "#ffffff",
                        "on-secondary": "#ffffff",
                        "on-secondary-fixed-variant": "#005047",
                        "on-secondary-container": "#006f62",
                        "on-error-container": "#93000a",
                        "primary-fixed-dim": "#b2c5ff",
                        "on-secondary-fixed": "#00201b",
                        "tertiary-fixed": "#ffdcc6",
                        "on-primary-fixed-variant": "#0040a1",
                        "surface-container": "#ebeef4",
                        "on-background": "#181c20",
                        "primary-fixed": "#dae2ff",
                        "primary-container": "#0b57d0",
                        "on-surface-variant": "#424654",
                        "on-primary": "#ffffff",
                        "on-tertiary-fixed": "#311300",
                        "secondary-fixed-dim": "#7ad7c6",
                        "surface-bright": "#f7f9ff",
                        "error-container": "#ffdad6",
                        "surface-container-lowest": "#ffffff",
                        "surface-tint": "#0856cf",
                        "primary": "#0041a2",
                        "surface-variant": "#dfe3e8",
                        "on-tertiary-fixed-variant": "#723600",
                        "inverse-surface": "#2d3135",
                        "secondary": "#006b5e",
                        "inverse-on-surface": "#eef1f7",
                        "on-surface": "#181c20",
                        "surface-container-highest": "#dfe3e8",
                        "tertiary-fixed-dim": "#ffb786",
                        "surface-container-low": "#f1f4fa",
                        "surface-dim": "#d7dae0",
                        "inverse-primary": "#b2c5ff",
                        "tertiary": "#733700",
                        "outline": "#737785",
                        "on-tertiary-container": "#ffd1b4",
                        "secondary-container": "#94f0df",
                        "surface-container-high": "#e5e8ee",
                        "outline-variant": "#c3c6d6"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.25rem",
                        "lg": "0.5rem",
                        "xl": "0.75rem",
                        "full": "9999px"
                    },
                    "spacing": {
                        "base": "4px",
                        "margin-mobile": "12px",
                        "lg": "24px",
                        "gutter": "16px",
                        "xs": "4px",
                        "md": "16px",
                        "margin-desktop": "32px",
                        "sm": "8px",
                        "xl": "32px"
                    },
                    "fontFamily": {
                        "body-md": ["Inter", "sans-serif"],
                        "headline-lg": ["Inter", "sans-serif"],
                        "label-md": ["Inter", "sans-serif"],
                        "body-lg": ["Inter", "sans-serif"],
                        "headline-sm": ["Inter", "sans-serif"],
                        "display-lg": ["Inter", "sans-serif"],
                        "body-sm": ["Inter", "sans-serif"],
                        "headline-md": ["Inter", "sans-serif"],
                        "code-md": ["Courier Prime", "monospace"]
                    },
                    "fontSize": {
                        "body-md": ["14px", { "lineHeight": "20px", "fontWeight": "400" }],
                        "headline-lg": ["28px", { "lineHeight": "36px", "fontWeight": "600" }],
                        "label-md": ["12px", { "lineHeight": "16px", "letterSpacing": "0.05em", "fontWeight": "600" }],
                        "body-lg": ["16px", { "lineHeight": "24px", "fontWeight": "400" }],
                        "headline-sm": ["18px", { "lineHeight": "26px", "fontWeight": "600" }],
                        "display-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
                        "body-sm": ["13px", { "lineHeight": "18px", "fontWeight": "400" }],
                        "headline-md": ["22px", { "lineHeight": "30px", "fontWeight": "600" }],
                        "code-md": ["14px", { "lineHeight": "20px", "fontWeight": "400" }]
                    }
                },
            },
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .material-symbols-outlined.fill {
            font-variation-settings: 'FILL' 1;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: #c3c6d6;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background-color: #737785;
        }
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f4fa;
        }
        ::-webkit-scrollbar-thumb {
            background: #c3c6d6;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #737785;
        }
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        /* Collapsed sidebar styling */
        .sidebar-collapsed #app-sidebar {
            width: 5rem !important; /* 80px */
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        .sidebar-collapsed #app-sidebar .sidebar-text {
            display: none !important;
        }
        .sidebar-collapsed #app-sidebar ul li a {
            justify-content: center !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        .sidebar-collapsed #app-sidebar .sidebar-logout-btn {
            justify-content: center !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        .sidebar-collapsed #main-content-wrapper {
            margin-left: 5rem !important; /* 80px */
        }
        @media (max-width: 1023px) {
            .sidebar-collapsed #main-content-wrapper {
                margin-left: 0 !important;
            }
        }
    </style>
</head>
<body class="bg-background text-on-surface font-body-md text-body-md h-full overflow-hidden flex">

<?php include __DIR__ . '/sidebar.php'; ?>

<!-- Main Content Area Wrapper -->
<div id="main-content-wrapper" class="flex-1 flex flex-col ml-0 lg:ml-64 w-full min-w-0 h-screen overflow-hidden transition-all duration-300">
    <!-- TopNavBar with Burger Menu Button -->
    <header class="bg-surface dark:bg-surface-dim border-b border-outline-variant dark:border-outline flex justify-between items-center w-full px-3 sm:px-lg py-sm docked top-0 sticky z-30 shrink-0">
        <!-- Left: Burger Menu Button & Brand/Page Title -->
        <div class="flex items-center gap-2 sm:gap-md min-w-0">
            <button id="header-burger-btn" type="button" onclick="toggleSidebar()" class="p-2 -ml-1 text-on-surface-variant hover:bg-surface-container-low rounded-lg transition-colors shrink-0 flex items-center justify-center cursor-pointer" title="Toggle Sidebar Menu" aria-label="Toggle Sidebar Menu">
                <span class="material-symbols-outlined text-[26px]">menu</span>
            </button>
            <span class="font-headline-sm text-sm sm:text-headline-sm font-bold text-primary dark:text-primary-fixed truncate">
                <?php echo isset($headerTitle) ? htmlspecialchars($headerTitle) : 'MedCore Management'; ?>
            </span>
        </div>

        <!-- Right: Search + User Details + Prominent Sign Out Action -->
        <div class="flex items-center gap-2 sm:gap-md shrink-0">
            <!-- Responsive Search -->
            <div class="relative hidden md:block">
                <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px] pointer-events-none">search</span>
                <input class="pl-8 pr-3 py-1.5 rounded-full bg-surface-container-low border border-outline-variant focus:border-primary focus:ring-1 focus:ring-primary font-body-sm text-xs w-36 lg:w-56 placeholder-on-surface-variant outline-none transition-all duration-200" placeholder="Search..." type="text">
            </div>

            <!-- Notifications Button -->
            <button class="p-1.5 sm:p-2 rounded-lg hover:bg-surface-container-low transition-colors text-on-surface-variant relative" title="Notifications">
                <span class="material-symbols-outlined text-[20px] sm:text-[22px]">notifications</span>
                <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-error rounded-full ring-2 ring-surface"></span>
            </button>

            <!-- User Info Badge -->
            <div class="hidden sm:flex flex-col items-end pl-1 pr-1 border-r border-outline-variant/60">
                <span class="font-bold text-xs text-on-surface truncate max-w-[130px]"><?php echo e($userName); ?></span>
                <span class="text-[10px] text-primary font-medium"><?php echo e(getRoleDisplayName($userRole)); ?></span>
            </div>

            <!-- Prominent Sign Out Button in Header -->
            <a href="logout.php" class="flex items-center gap-1 sm:gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-lg bg-error-container/50 hover:bg-error-container text-on-error-container hover:text-error border border-error/20 font-semibold text-xs transition-colors shadow-xs" title="Sign Out of MedCore Systems">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                <span class="hidden sm:inline">Sign Out</span>
            </a>
        </div>
    </header>
