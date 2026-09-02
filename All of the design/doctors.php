<?php
$pageTitle = 'Doctors & Clinical Staff - MedCore Systems';
$headerTitle = 'MedCore Management - Doctors';
$activePage = 'doctors';

include __DIR__ . '/components/header.php';
?>

<!-- Doctors Directory Main Canvas -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-20 lg:pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Doctors &amp; Clinical Staff</h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">Manage physician roster, room assignments, schedules, and register new doctors.</p>
            </div>
            <!-- Add New Doctor Trigger Button -->
            <button type="button" onclick="openDoctorModal()" class="w-full sm:w-auto flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">person_add</span>
                Register New Doctor
            </button>
        </div>

        <!-- Metric Summary Bento Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Total Physicians</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1">24</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">stethoscope</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">On Duty Today</p>
                    <p class="font-display-lg text-2xl font-bold text-secondary mt-1">16</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">check_circle</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">In Consultation</p>
                    <p class="font-display-lg text-2xl font-bold text-primary mt-1">8</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-fixed text-on-primary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">medical_services</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Clinical Departments</p>
                    <p class="font-display-lg text-2xl font-bold text-tertiary mt-1">9</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">domain</span>
                </div>
            </div>
        </div>

        <!-- Doctors Directory Table Container -->
        <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
            <!-- Table Toolbar & Search Filters -->
            <div class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto flex-1 max-w-xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input id="doctor-search-input" onkeyup="filterDoctors()" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none" placeholder="Search doctor by name, specialty, room, or license..." type="text">
                    </div>
                    <select id="specialty-filter" onchange="filterDoctors()" class="bg-surface border border-outline-variant rounded-lg px-3 py-1.5 text-xs text-on-surface outline-none">
                        <option value="">All Specialties</option>
                        <option value="General Practice">General Practice</option>
                        <option value="Pediatrics">Pediatrics</option>
                        <option value="Orthopedics">Orthopedics</option>
                        <option value="Pulmonology">Pulmonology</option>
                        <option value="Cardiology">Cardiology</option>
                        <option value="Dermatology">Dermatology</option>
                    </select>
                </div>
                <div class="flex items-center gap-sm">
                    <span class="text-xs text-on-surface-variant">Showing 6 of 24 physicians</span>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[800px]">
                    <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant sticky top-0">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Doctor Name &amp; ID</th>
                            <th class="py-3 px-4 font-semibold">Specialization</th>
                            <th class="py-3 px-4 font-semibold">Room &amp; License</th>
                            <th class="py-3 px-4 font-semibold">Duty Schedule</th>
                            <th class="py-3 px-4 font-semibold">Active Queue</th>
                            <th class="py-3 px-4 font-semibold">Status</th>
                            <th class="py-3 px-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="doctor-table-body" class="font-body-sm text-xs sm:text-body-sm text-on-surface divide-y divide-outline-variant">
                        <!-- Doctor 1: Dr. Alan Carter -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-sm shrink-0">
                                        AC
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. Alan Carter, MD</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0101</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary-fixed/40 text-on-primary-fixed font-label-md text-[11px] font-semibold">
                                    General Practice
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 302</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-89412</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Mon - Fri</p>
                                <p class="text-[11px] text-on-surface-variant">08:00 AM - 04:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-primary">4</span> <span class="text-xs text-on-surface-variant">waiting</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Available
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="doctor_dashboard.php" class="px-2.5 py-1 bg-primary text-on-primary hover:bg-primary-container rounded text-xs font-medium transition-colors">Dashboard</a>
                                    <a href="queue_management.php" class="px-2 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Doctor 2: Dr. Brenda Lee -->
                        <tr class="bg-surface-container-low/30 hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-secondary-container text-on-secondary-container flex items-center justify-center font-bold text-sm shrink-0">
                                        BL
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. Brenda Lee, MD</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0102</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-secondary-fixed-dim/40 text-on-secondary-container font-label-md text-[11px] font-semibold">
                                    Pediatrics
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 204</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-77192</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Mon - Fri</p>
                                <p class="text-[11px] text-on-surface-variant">09:00 AM - 05:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-error">8</span> <span class="text-xs text-on-surface-variant">waiting (High)</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-primary-fixed text-on-primary-fixed font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-primary"></span> In Consult
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Doctor 3: Dr. Sam Patel -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center font-bold text-sm shrink-0">
                                        SP
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. Sam Patel, MS Ortho</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0103</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-tertiary-fixed-dim/40 text-on-tertiary-container font-label-md text-[11px] font-semibold">
                                    Orthopedics
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 410</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-66231</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Tue - Sat</p>
                                <p class="text-[11px] text-on-surface-variant">08:00 AM - 02:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-on-surface">0</span> <span class="text-xs text-on-surface-variant">waiting</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Available
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Doctor 4: Dr. Maya Lin -->
                        <tr class="bg-surface-container-low/30 hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-surface-variant text-on-surface flex items-center justify-center font-bold text-sm shrink-0">
                                        ML
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. Maya Lin, MD, FCCP</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0104</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary-fixed/40 text-on-primary-fixed font-label-md text-[11px] font-semibold">
                                    Pulmonology
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 308</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-99120</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Mon - Thu</p>
                                <p class="text-[11px] text-on-surface-variant">10:00 AM - 06:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-on-surface">2</span> <span class="text-xs text-on-surface-variant">waiting</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-surface-variant text-on-surface-variant font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-outline"></span> On Break
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Doctor 5: Dr. David Kim -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-sm shrink-0">
                                        DK
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. David Kim, FACC</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0105</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-primary-fixed/40 text-on-primary-fixed font-label-md text-[11px] font-semibold">
                                    Cardiology
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 105</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-44510</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Mon - Fri</p>
                                <p class="text-[11px] text-on-surface-variant">08:00 AM - 03:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-primary">3</span> <span class="text-xs text-on-surface-variant">waiting</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Available
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Doctor 6: Dr. Rachel Adams -->
                        <tr class="bg-surface-container-low/30 hover:bg-surface-container-low transition-colors group opacity-80">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-10 h-10 rounded-full bg-surface-variant text-on-surface flex items-center justify-center font-bold text-sm shrink-0">
                                        RA
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Dr. Rachel Adams, FAAD</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">DOC-2023-0106</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface-variant font-label-md text-[11px] font-semibold">
                                    Dermatology
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-semibold">Room 212</p>
                                <p class="font-code-md text-[11px] text-on-surface-variant">LIC-MED-33821</p>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface">Wed - Sun</p>
                                <p class="text-[11px] text-on-surface-variant">09:00 AM - 05:00 PM</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-bold text-on-surface">-</span> <span class="text-xs text-on-surface-variant">off</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-surface-container text-on-surface-variant font-label-md text-[10px] font-semibold">
                                    Off Duty
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Schedule</a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer & Pagination -->
            <div class="p-3 border-t border-outline-variant flex flex-col sm:flex-row justify-between items-center gap-2 bg-surface-bright font-body-sm text-xs text-on-surface-variant">
                <div>Showing 1-6 of 24 doctors</div>
                <div class="flex gap-1">
                    <button class="px-2.5 py-1 rounded border border-outline-variant hover:bg-surface-container disabled:opacity-50" disabled>Prev</button>
                    <button class="px-2.5 py-1 rounded bg-primary text-on-primary font-bold">1</button>
                    <button class="px-2.5 py-1 rounded border border-outline-variant hover:bg-surface-container">2</button>
                    <button class="px-2.5 py-1 rounded border border-outline-variant hover:bg-surface-container">3</button>
                    <span class="px-1 py-1">...</span>
                    <button class="px-2.5 py-1 rounded border border-outline-variant hover:bg-surface-container">Next</button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ========================================== -->
<!-- REGISTER NEW DOCTOR MODAL DIALOG           -->
<!-- ========================================== -->
<div id="doctor-modal" class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/60 backdrop-blur-xs hidden opacity-0 transition-opacity duration-300" onclick="handleDoctorBackdropClick(event)">
    <div class="max-w-3xl w-full max-h-[92vh] overflow-y-auto bg-surface rounded-2xl border border-outline-variant shadow-2xl custom-scrollbar p-4 sm:p-lg" onclick="event.stopPropagation()">
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between pb-md border-b border-outline-variant mb-lg">
            <div class="flex items-center gap-sm">
                <span class="material-symbols-outlined text-primary text-[28px]">stethoscope</span>
                <div>
                    <h3 class="font-display-lg text-lg sm:text-display-lg font-bold text-on-background">Register New Doctor</h3>
                    <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant">Add a new physician or specialist to the clinical operations roster.</p>
                </div>
            </div>
            <div class="flex items-center gap-sm">
                <!-- Auto-Generated Doctor ID Tag -->
                <div class="hidden sm:flex bg-surface-container-low border border-outline-variant rounded-lg p-2 items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">badge</span>
                    <div>
                        <p class="font-label-md text-[10px] text-on-surface-variant uppercase">Doctor ID</p>
                        <p class="font-code-md text-xs font-bold text-on-surface">DOC-2023-0194</p>
                    </div>
                </div>
                <button type="button" onclick="closeDoctorModal()" class="p-2 rounded-full text-on-surface-variant hover:bg-surface-container transition-colors cursor-pointer" title="Close">
                    <span class="material-symbols-outlined text-[24px]">close</span>
                </button>
            </div>
        </div>

        <!-- Doctor Registration Form -->
        <form class="space-y-md sm:space-y-lg" onsubmit="handleDoctorSubmit(event)">
            <!-- Personal & Professional Information Card -->
            <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm">
                <div class="flex items-center gap-sm mb-md pb-sm border-b border-outline-variant">
                    <span class="material-symbols-outlined text-primary">account_circle</span>
                    <h4 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-background">Doctor Details</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-md">
                    <!-- Full Name -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Full Name (with Title) *</label>
                        <input id="modal-doctor-name" class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="e.g. Dr. Emily Stone" required type="text" value="Dr. Emily Stone">
                    </div>
                    <!-- Specialization -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Specialization / Department *</label>
                        <select class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" required>
                            <option disabled value="">Select Department</option>
                            <option value="General Practice" selected>General Practice</option>
                            <option value="Pediatrics">Pediatrics</option>
                            <option value="Orthopedics">Orthopedics</option>
                            <option value="Pulmonology">Pulmonology</option>
                            <option value="Cardiology">Cardiology</option>
                            <option value="Dermatology">Dermatology</option>
                            <option value="Neurology">Neurology</option>
                            <option value="Obstetrics & Gynecology">Obstetrics &amp; Gynecology</option>
                        </select>
                    </div>
                    <!-- Qualifications -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Medical Qualifications *</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="e.g. MD, MBBS, FRCP" required type="text" value="MD, MBBS">
                    </div>
                    <!-- Medical License Number -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">License / Registration Number *</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none font-code-md" placeholder="e.g. LIC-MED-99481" required type="text" value="LIC-MED-99481">
                    </div>
                </div>
            </section>

            <!-- Room & Contact Details Card -->
            <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm">
                <div class="flex items-center gap-sm mb-md pb-sm border-b border-outline-variant">
                    <span class="material-symbols-outlined text-primary">meeting_room</span>
                    <h4 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-background">Room Assignment &amp; Contact</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-md">
                    <!-- Consultation Room -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Assigned Room *</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="e.g. Room 304" required type="text" value="Room 304">
                    </div>
                    <!-- Primary Phone -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Phone Number *</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="(555) 019-4821" required type="tel" value="(555) 019-4821">
                    </div>
                    <!-- Consultation Fee -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Standard Consult Fee ($)</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" type="number" step="0.01" value="30.00">
                    </div>
                    <!-- Duty Days -->
                    <div class="sm:col-span-2 space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Duty Days</label>
                        <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="e.g. Mon, Tue, Wed, Thu, Fri" type="text" value="Mon, Tue, Wed, Thu, Fri">
                    </div>
                    <!-- Shift Hours -->
                    <div class="space-y-1">
                        <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Shift Hours</label>
                        <select class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none">
                            <option value="Morning (08:00 AM - 04:00 PM)" selected>Morning (08:00 AM - 04:00 PM)</option>
                            <option value="Evening (01:00 PM - 09:00 PM)">Evening (01:00 PM - 09:00 PM)</option>
                            <option value="Night (08:00 PM - 08:00 AM)">Night (08:00 PM - 08:00 AM)</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-center justify-end gap-sm pt-sm border-t border-outline-variant">
                <button type="button" onclick="closeDoctorModal()" class="w-full sm:w-auto px-md py-2.5 border border-outline-variant text-on-surface font-label-md text-xs sm:text-label-md rounded-lg hover:bg-surface-container transition-colors cursor-pointer">
                    Cancel
                </button>
                <button type="submit" class="w-full sm:w-auto px-lg py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer flex items-center justify-center gap-xs">
                    <span class="material-symbols-outlined text-[18px]">how_to_reg</span>
                    Register &amp; Activate Doctor
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Doctor Modal & Filter Scripts -->
<script>
    function openDoctorModal() {
        const modal = document.getElementById('doctor-modal');
        if (modal) {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modal.classList.add('opacity-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }
    }

    function closeDoctorModal() {
        const modal = document.getElementById('doctor-modal');
        if (modal) {
            modal.classList.remove('opacity-100');
            modal.classList.add('opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
            document.body.style.overflow = '';
        }
    }

    function handleDoctorBackdropClick(event) {
        if (event.target.id === 'doctor-modal') {
            closeDoctorModal();
        }
    }

    function handleDoctorSubmit(event) {
        event.preventDefault();
        const name = document.getElementById('modal-doctor-name')?.value || 'Doctor';
        alert(name + ' Registered Successfully and added to active roster!');
        closeDoctorModal();
    }

    // Client-side search and specialty filter
    function filterDoctors() {
        const query = document.getElementById('doctor-search-input').value.toLowerCase();
        const specialty = document.getElementById('specialty-filter').value.toLowerCase();
        const rows = document.querySelectorAll('#doctor-table-body tr');

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            const matchesQuery = text.includes(query);
            const matchesSpecialty = !specialty || text.includes(specialty);
            row.style.display = (matchesQuery && matchesSpecialty) ? '' : 'none';
        });
    }

    // Modal ESC key listener
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeDoctorModal();
        }
    });
</script>

<?php include __DIR__ . '/components/footer.php'; ?>
