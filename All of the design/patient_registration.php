<?php
$pageTitle = 'Patients Directory & Registration - MedCore Systems';
$headerTitle = 'MedCore Management - Patients';
$activePage = 'patients';

include __DIR__ . '/components/header.php';
?>

<!-- Patients Directory Main View -->
<main class="flex-1 overflow-y-auto bg-background p-4 sm:p-6 lg:p-margin-desktop pb-20 lg:pb-6 custom-scrollbar">
    <div class="max-w-7xl mx-auto space-y-md sm:space-y-lg">
        <!-- Page Header & Action Bar -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-md">
            <div>
                <h2 class="font-headline-lg text-xl sm:text-headline-lg font-bold text-on-surface">Patients Directory</h2>
                <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant mt-xs">Manage patient records, clinical charts, and register new patients.</p>
            </div>
            <!-- Add New Patient Trigger Button -->
            <button type="button" onclick="openRegistrationModal()" class="w-full sm:w-auto flex items-center justify-center gap-xs px-md py-2.5 bg-primary text-on-primary font-label-md text-xs sm:text-label-md rounded-lg hover:bg-primary-container hover:text-on-primary-container transition-colors shadow-sm font-semibold cursor-pointer">
                <span class="material-symbols-outlined text-[20px]">person_add</span>
                Add New Patient
            </button>
        </div>

        <!-- Metric Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-md">
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Total Patients</p>
                    <p class="font-display-lg text-2xl font-bold text-on-surface mt-1">1,420</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">groups</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Registered Today</p>
                    <p class="font-display-lg text-2xl font-bold text-secondary mt-1">18</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">how_to_reg</span>
                </div>
            </div>
            <div class="bg-surface border border-outline-variant rounded-xl p-4 shadow-sm flex items-center justify-between">
                <div>
                    <p class="font-label-md text-xs text-on-surface-variant uppercase font-semibold">Allergy Alerts</p>
                    <p class="font-display-lg text-2xl font-bold text-error mt-1">64</p>
                </div>
                <div class="w-10 h-10 rounded-full bg-error-container text-on-error-container flex items-center justify-center">
                    <span class="material-symbols-outlined text-[20px]">warning</span>
                </div>
            </div>
        </div>

        <!-- Patients Directory Table Card -->
        <div class="bg-surface border border-outline-variant rounded-xl shadow-sm overflow-hidden flex flex-col">
            <!-- Table Toolbar / Filters -->
            <div class="p-3 sm:p-md border-b border-outline-variant flex flex-wrap gap-2 sm:gap-4 justify-between items-center bg-surface-bright">
                <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto flex-1 max-w-xl">
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[18px]">search</span>
                        <input id="patient-search-input" onkeyup="filterPatients()" class="w-full pl-9 pr-3 py-1.5 rounded-lg bg-surface border border-outline-variant text-xs sm:text-body-sm text-on-surface focus:border-primary outline-none" placeholder="Search by patient name, MRN, phone..." type="text">
                    </div>
                    <select class="bg-surface border border-outline-variant rounded-lg px-3 py-1.5 text-xs text-on-surface outline-none">
                        <option>All Genders</option>
                        <option>Male</option>
                        <option>Female</option>
                    </select>
                </div>
                <div class="flex items-center gap-sm">
                    <span class="text-xs text-on-surface-variant">Showing 5 of 1,420</span>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left border-collapse min-w-[750px]">
                    <thead class="bg-surface-container-low border-b border-outline-variant font-label-md text-xs text-on-surface-variant sticky top-0">
                        <tr>
                            <th class="py-3 px-4 font-semibold">Patient Name &amp; ID</th>
                            <th class="py-3 px-4 font-semibold">Age / Gender</th>
                            <th class="py-3 px-4 font-semibold">Contact</th>
                            <th class="py-3 px-4 font-semibold">Medical Brief</th>
                            <th class="py-3 px-4 font-semibold">Status</th>
                            <th class="py-3 px-4 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="patient-table-body" class="font-body-sm text-xs sm:text-body-sm text-on-surface divide-y divide-outline-variant">
                        <!-- Patient 1: Michael Chen -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-9 h-9 rounded-full bg-primary-container text-on-primary-container flex items-center justify-center font-bold text-xs shrink-0">
                                        MC
                                    </div>
                                    <div>
                                        <a href="patient_profile_michael_chen.php" class="font-bold text-on-surface hover:text-primary hover:underline flex items-center gap-1">
                                            Michael Chen
                                            <span class="material-symbols-outlined text-[14px] text-primary">open_in_new</span>
                                        </a>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">PAT-2023-0892</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">34 yrs • Male</td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-medium">+1 (555) 019-2834</p>
                                <p class="text-[11px] text-on-surface-variant">Metropolis</p>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="px-2 py-0.5 rounded bg-surface-container font-label-md text-[10px] font-bold">O+</span>
                                    <span class="px-2 py-0.5 rounded bg-error-container text-on-error-container font-label-md text-[10px] font-bold">Allergy: Penicillin</span>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed-variant font-label-md text-[10px] font-semibold">
                                    <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span> Active
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="patient_profile_michael_chen.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Profile</a>
                                    <a href="consultation_michael_chen.php" class="px-2.5 py-1 bg-primary text-on-primary hover:bg-primary-container rounded text-xs font-medium transition-colors">Consult</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Patient 2: Sarah Jenkins -->
                        <tr class="bg-surface-container-low/30 hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-9 h-9 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center font-bold text-xs shrink-0">
                                        SJ
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Sarah Jenkins</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">PAT-2023-0891</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">28 yrs • Female</td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-medium">+1 (555) 018-9281</p>
                                <p class="text-[11px] text-on-surface-variant">Oakland</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded bg-surface-container font-label-md text-[10px] font-bold">A+</span>
                                <span class="text-on-surface-variant text-[11px] ml-1">No allergies</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-primary-fixed text-on-primary-fixed font-label-md text-[10px] font-semibold">
                                    Checked In
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="queue_management.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Queue</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Patient 3: Robert Wilson -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-9 h-9 rounded-full bg-tertiary-fixed text-on-tertiary-fixed flex items-center justify-center font-bold text-xs shrink-0">
                                        RW
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Robert Wilson</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">PAT-2023-0890</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">48 yrs • Male</td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-medium">+1 (555) 017-1109</p>
                                <p class="text-[11px] text-on-surface-variant">Riverside</p>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="px-2 py-0.5 rounded bg-surface-container font-label-md text-[10px] font-bold">B+</span>
                                    <span class="px-2 py-0.5 rounded bg-error-container text-on-error-container font-label-md text-[10px] font-bold">Allergy: Sulfa</span>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-surface-variant text-on-surface-variant font-label-md text-[10px] font-semibold">
                                    Waiting
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="billing_payments.php" class="px-2.5 py-1 bg-surface-container hover:bg-surface-container-high rounded text-xs text-on-surface font-medium transition-colors">Billing</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Patient 4: Elena Rodriguez -->
                        <tr class="bg-surface-container-low/30 hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-9 h-9 rounded-full bg-surface-variant text-on-surface flex items-center justify-center font-bold text-xs shrink-0">
                                        ER
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">Elena Rodriguez</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">PAT-2023-0889</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">29 yrs • Female</td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-medium">+1 (555) 014-5538</p>
                                <p class="text-[11px] text-on-surface-variant">San Jose</p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded bg-surface-container font-label-md text-[10px] font-bold">AB+</span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-md text-[10px] font-semibold">
                                    In Consult
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="consultation_michael_chen.php" class="px-2.5 py-1 bg-primary text-on-primary hover:bg-primary-container rounded text-xs font-medium transition-colors">Consult</a>
                                </div>
                            </td>
                        </tr>

                        <!-- Patient 5: James Smith -->
                        <tr class="hover:bg-surface-container-low transition-colors group">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-sm">
                                    <div class="w-9 h-9 rounded-full bg-error-container text-on-error-container flex items-center justify-center font-bold text-xs shrink-0">
                                        JS
                                    </div>
                                    <div>
                                        <p class="font-bold text-on-surface">James Smith</p>
                                        <p class="font-code-md text-[11px] text-on-surface-variant">PAT-2023-0888</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4">61 yrs • Male</td>
                            <td class="py-3 px-4">
                                <p class="text-on-surface font-medium">+1 (555) 012-8823</p>
                                <p class="text-[11px] text-on-surface-variant">Metropolis</p>
                            </td>
                            <td class="py-3 px-4">
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="px-2 py-0.5 rounded bg-surface-container font-label-md text-[10px] font-bold">O-</span>
                                    <span class="px-2 py-0.5 rounded bg-error-container text-on-error-container font-label-md text-[10px] font-bold">Aspirin</span>
                                </div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-error-container text-on-error-container font-label-md text-[10px] font-bold">
                                    Lab Alert
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="laboratory_dashboard.php" class="px-2.5 py-1 bg-error text-on-error rounded text-xs font-medium transition-colors">Lab Results</a>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer / Pagination -->
            <div class="p-3 border-t border-outline-variant flex flex-col sm:flex-row justify-between items-center gap-2 bg-surface-bright font-body-sm text-xs text-on-surface-variant">
                <div>Showing 1-5 of 1,420 registered patients</div>
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
<!-- PATIENT REGISTRATION MODAL DIALOG          -->
<!-- ========================================== -->
<div id="registration-modal" class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/60 backdrop-blur-xs hidden opacity-0 transition-opacity duration-300" onclick="handleModalBackdropClick(event)">
    <div class="max-w-4xl w-full max-h-[92vh] overflow-y-auto bg-surface rounded-2xl border border-outline-variant shadow-2xl custom-scrollbar p-4 sm:p-lg" onclick="event.stopPropagation()">
        
        <!-- Modal Top Header -->
        <div class="flex items-center justify-between pb-md border-b border-outline-variant mb-lg">
            <div class="flex items-center gap-sm">
                <span class="material-symbols-outlined text-primary text-[28px]">person_add</span>
                <div>
                    <h3 class="font-display-lg text-lg sm:text-display-lg font-bold text-on-background">New Patient Registration</h3>
                    <p class="font-body-md text-xs sm:text-body-md text-on-surface-variant">Fields marked with an asterisk (*) are required for registration.</p>
                </div>
            </div>
            <div class="flex items-center gap-sm">
                <!-- Auto-Generated Patient ID Tag -->
                <div class="hidden sm:flex bg-surface-container-low border border-outline-variant rounded-lg p-2 items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-[18px]">tag</span>
                    <div>
                        <p class="font-label-md text-[10px] text-on-surface-variant uppercase">Patient ID</p>
                        <p class="font-code-md text-xs font-bold text-on-surface">MC-2023-8942A</p>
                    </div>
                </div>
                <button type="button" onclick="closeRegistrationModal()" class="p-2 rounded-full text-on-surface-variant hover:bg-surface-container transition-colors cursor-pointer" title="Close">
                    <span class="material-symbols-outlined text-[24px]">close</span>
                </button>
            </div>
        </div>

        <!-- Registration Form (Preserved identically to your exact design) -->
        <form class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start" onsubmit="handleRegistrationSubmit(event)">
            <!-- Left Column: Primary Data (Takes 8 cols on desktop) -->
            <div class="lg:col-span-8 flex flex-col gap-gutter">
                <!-- Personal Info Card -->
                <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm">
                    <div class="flex items-center gap-sm mb-md pb-sm border-b border-outline-variant">
                        <span class="material-symbols-outlined text-primary">account_circle</span>
                        <h4 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-background">Personal Information</h4>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-md">
                        <!-- Full Name -->
                        <div class="sm:col-span-2 space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Full Name *</label>
                            <input id="modal-patient-name" class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="e.g. Michael Chen" required type="text" value="Michael Chen">
                        </div>
                        <!-- DOB -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Date of Birth *</label>
                            <div class="relative">
                                <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" required type="date" value="1989-06-14">
                            </div>
                        </div>
                        <!-- Age (Calculated) -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Age</label>
                            <input class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface-variant font-body-md text-xs sm:text-body-md py-2 px-3 cursor-not-allowed outline-none" disabled placeholder="Auto-calculated" readonly type="text" value="34 yrs">
                        </div>
                        <!-- Gender -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Gender *</label>
                            <select class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" required>
                                <option disabled value="">Select Gender</option>
                                <option value="male" selected>Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                                <option value="undisclosed">Prefer not to say</option>
                            </select>
                        </div>
                        <!-- Phone -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Primary Phone *</label>
                            <div class="flex">
                                <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-outline-variant bg-surface-container-low text-on-surface-variant font-label-md text-xs sm:text-label-md">+1</span>
                                <input class="flex-1 min-w-0 rounded-none rounded-r-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" placeholder="(555) 019-2834" required type="tel" value="(555) 019-2834">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Contact Details Card -->
                <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg shadow-sm">
                    <div class="flex items-center gap-sm mb-md pb-sm border-b border-outline-variant">
                        <span class="material-symbols-outlined text-primary">location_on</span>
                        <h4 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-background">Contact &amp; Address</h4>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-md">
                        <!-- Address Line 1 -->
                        <div class="sm:col-span-2 space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Street Address</label>
                            <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors outline-none" placeholder="123 Pinecrest Avenue, Apt 4B" type="text" value="123 Pinecrest Avenue, Apt 4B">
                        </div>
                        <!-- City -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">City</label>
                            <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" placeholder="Metropolis" type="text" value="Metropolis">
                        </div>
                        <!-- Postal Code -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Postal / Zip Code</label>
                            <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" placeholder="90210" type="text" value="90210">
                        </div>
                    </div>
                    <!-- Emergency Contact Subsection -->
                    <div class="mt-md pt-md border-t border-outline-variant border-dashed">
                        <h5 class="font-label-md text-xs text-on-surface-variant mb-sm uppercase font-semibold">Emergency Contact</h5>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-md">
                            <div class="space-y-1">
                                <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Name</label>
                                <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" placeholder="Linda Chen (Spouse)" type="text" value="Linda Chen (Spouse)">
                            </div>
                            <div class="space-y-1">
                                <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Phone Number</label>
                                <input class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none" placeholder="(555) 019-9944" type="tel" value="(555) 019-9944">
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Right Column: Secondary Data & Actions (Takes 4 cols on desktop) -->
            <div class="lg:col-span-4 flex flex-col gap-gutter">
                <!-- Medical History Brief Card -->
                <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-lg relative overflow-hidden shadow-sm">
                    <div class="absolute -right-4 -top-4 w-24 h-24 bg-error-container rounded-full opacity-20 blur-xl pointer-events-none"></div>
                    <div class="flex items-center gap-sm mb-md pb-sm border-b border-outline-variant relative z-10">
                        <span class="material-symbols-outlined text-error">monitor_heart</span>
                        <h4 class="font-headline-sm text-base sm:text-headline-sm font-bold text-on-background">Medical Brief</h4>
                    </div>
                    <div class="space-y-md relative z-10">
                        <!-- Blood Group -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block">Blood Group</label>
                            <select class="w-full rounded-lg border border-outline-variant bg-surface focus:border-primary focus:ring-1 focus:ring-primary font-body-md text-xs sm:text-body-md text-on-surface py-2 px-3 transition-colors outline-none">
                                <option disabled value="">Unknown</option>
                                <option value="a+">A+</option>
                                <option value="a-">A-</option>
                                <option value="b+">B+</option>
                                <option value="b-">B-</option>
                                <option value="ab+">AB+</option>
                                <option value="ab-">AB-</option>
                                <option value="o+" selected>O Positive (O+)</option>
                                <option value="o-">O-</option>
                            </select>
                        </div>
                        <!-- Known Allergies -->
                        <div class="space-y-1">
                            <label class="font-label-md text-xs sm:text-label-md text-on-surface block flex items-center justify-between">
                                <span>Known Allergies</span>
                                <span class="text-error font-normal text-xs flex items-center gap-1 font-semibold">
                                    <span class="material-symbols-outlined text-[14px]">warning</span> Critical
                                </span>
                            </label>
                            <textarea class="w-full rounded-lg border border-outline-variant bg-surface focus:border-error focus:ring-1 focus:ring-error font-body-md text-xs sm:text-body-md text-on-surface placeholder:text-outline py-2 px-3 transition-colors resize-none outline-none" placeholder="List any known allergies to medications, food, or environment..." rows="3">Penicillin (Severe hives/rash)</textarea>
                        </div>
                    </div>
                </section>

                <!-- Action Bar -->
                <section class="bg-surface border border-outline-variant rounded-xl p-4 sm:p-md flex flex-col gap-sm shadow-sm z-20">
                    <p class="font-body-sm text-xs text-on-surface-variant text-center mb-1">Review all details before submission.</p>
                    <button type="submit" class="w-full flex items-center justify-center gap-2 bg-primary text-on-primary font-label-md text-xs sm:text-label-md py-3 px-4 rounded-lg hover:bg-primary-container hover:text-on-primary-container active:scale-[0.98] transition-all shadow-sm font-semibold cursor-pointer">
                        <span class="material-symbols-outlined text-sm fill">person_add</span>
                        Register &amp; Add to Queue
                    </button>
                    <button type="button" onclick="alert('Patient registered without queueing.'); closeRegistrationModal();" class="w-full flex items-center justify-center gap-2 bg-transparent border border-primary text-primary font-label-md text-xs sm:text-label-md py-2.5 px-4 rounded-lg hover:bg-surface-container-low active:scale-[0.98] transition-all font-medium cursor-pointer">
                        Register Only
                    </button>
                    <button type="button" onclick="closeRegistrationModal()" class="w-full flex items-center justify-center gap-2 text-on-surface-variant font-label-md text-xs sm:text-label-md py-2 px-4 rounded-lg hover:bg-surface-container-low transition-colors mt-1 cursor-pointer">
                        Cancel
                    </button>
                </section>
            </div>
        </form>
    </div>
</div>

<!-- Modal Control Script -->
<script>
    function openRegistrationModal() {
        const modal = document.getElementById('registration-modal');
        if (modal) {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modal.classList.add('opacity-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }
    }

    function closeRegistrationModal() {
        const modal = document.getElementById('registration-modal');
        if (modal) {
            modal.classList.remove('opacity-100');
            modal.classList.add('opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
            document.body.style.overflow = '';
        }
    }

    function handleModalBackdropClick(event) {
        if (event.target.id === 'registration-modal') {
            closeRegistrationModal();
        }
    }

    function handleRegistrationSubmit(event) {
        event.preventDefault();
        const name = document.getElementById('modal-patient-name')?.value || 'Patient';
        alert('Patient "' + name + '" Registered Successfully and added to queue!');
        closeRegistrationModal();
    }

    // Quick client-side filter
    function filterPatients() {
        const query = document.getElementById('patient-search-input').value.toLowerCase();
        const rows = document.querySelectorAll('#patient-table-body tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    }

    // Modal ESC key listener
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeRegistrationModal();
        }
    });
</script>

<?php include __DIR__ . '/components/footer.php'; ?>
