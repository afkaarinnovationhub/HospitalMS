/**
 * MedCore Systems - Real-Time Dashboard Live Sync Engine
 * Automatically synchronizes operational dashboards (Doctor Queues, Lab Orders, Pharmacy Rx, Cashier Bills)
 * without requiring manual page refreshes.
 */

(function() {
    'use strict';

    // Toast Container Singleton
    function getToastContainer() {
        let container = document.getElementById('hpms-live-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'hpms-live-toast-container';
            container.className = 'fixed top-16 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none';
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * Shows a non-intrusive floating toast alert for real-time events.
     */
    window.showLiveNotification = function(title, message, icon = 'notifications_active', type = 'primary') {
        const container = getToastContainer();
        const toast = document.createElement('div');
        toast.className = `pointer-events-auto transform transition-all duration-300 translate-x-full opacity-0 p-3.5 rounded-2xl shadow-xl border flex items-start gap-3 bg-surface text-on-surface border-primary/30 backdrop-blur-md`;

        const iconBg = (type === 'success') ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-primary text-on-primary';

        toast.innerHTML = `
            <div class="h-8 w-8 rounded-xl ${iconBg} flex items-center justify-center shrink-0 shadow-xs">
                <span class="material-symbols-outlined text-[18px]">${icon}</span>
            </div>
            <div class="flex-1 min-w-0 pr-1">
                <p class="text-xs font-bold text-on-surface leading-tight">${title}</p>
                <p class="text-[11px] text-on-surface-variant mt-0.5 leading-snug">${message}</p>
            </div>
            <button type="button" class="text-on-surface-variant hover:text-on-surface p-0.5 rounded cursor-pointer" onclick="this.parentElement.remove()">
                <span class="material-symbols-outlined text-[16px]">close</span>
            </button>
        `;

        container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.remove('translate-x-full', 'opacity-0');
        });

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast && toast.parentNode) {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    };

    /**
     * Initializes background real-time sync for a specific DOM container.
     */
    window.initLiveSync = function(config) {
        const {
            module,
            targetSelector,
            intervalMs = 3500,
            params = {},
            onUpdate = null,
            notifyOnNew = true
        } = config;

        let currentChecksum = '';
        let timer = null;
        let isPolling = false;
        let isFirstRun = true;

        async function poll() {
            if (document.visibilityState === 'hidden') {
                return; // Conserve resources when tab is hidden
            }

            if (isPolling) return;
            isPolling = true;

            try {
                const queryParams = new URLSearchParams({
                    module: module,
                    checksum: currentChecksum,
                    ...params
                });

                const apiBasePath = window.location.pathname.includes('/Pages/') ? '../api/live_sync.php' : 'api/live_sync.php';
                const response = await fetch(`${apiBasePath}?${queryParams.toString()}`, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const res = await response.json();

                if (res.status === 'ok' && res.changed) {
                    currentChecksum = res.checksum || '';

                    const container = document.querySelector(targetSelector);
                    if (container && res.html !== undefined) {
                        // Smoothly replace content
                        container.innerHTML = res.html;

                        // Visual highlight indicator
                        container.classList.add('transition-all', 'duration-500');
                        container.style.backgroundColor = 'rgba(11, 87, 208, 0.05)';
                        setTimeout(() => {
                            container.style.backgroundColor = '';
                        }, 800);

                        // Trigger user notification if not first load
                        if (!isFirstRun && notifyOnNew) {
                            if (module === 'doctor_queue' && res.first_patient) {
                                window.showLiveNotification(
                                    'New Patient in Queue',
                                    `Token ${res.first_patient.token} • ${res.first_patient.name} is waiting for consultation.`,
                                    'person_add',
                                    'primary'
                                );
                            } else if (module === 'consultation_lab_results') {
                                window.showLiveNotification(
                                    'Lab Diagnostics Updated',
                                    'Laboratory results or test orders for this patient have been updated.',
                                    'science',
                                    'success'
                                );
                            } else if (module === 'laboratory_worklist') {
                                window.showLiveNotification(
                                    'New Lab Diagnostic Order',
                                    'A new verified laboratory specimen order has been added to your worklist.',
                                    'biotech',
                                    'primary'
                                );
                            } else if (module === 'pharmacy_queue') {
                                window.showLiveNotification(
                                    'New Doctor Prescription',
                                    'A new doctor prescription has been received in the dispensing queue.',
                                    'prescriptions',
                                    'primary'
                                );
                            } else if (module === 'billing_queue') {
                                window.showLiveNotification(
                                    'New Cashier Bill Pending',
                                    'A new consultation, lab, or pharmacy invoice is awaiting cashier payment.',
                                    'point_of_sale',
                                    'primary'
                                );
                            }
                        }
                    }

                    if (typeof onUpdate === 'function') {
                        onUpdate(res);
                    }
                }
            } catch (err) {
                // Silent fail for network drops; poller will retry automatically
                console.debug('[HPMS LiveSync Debug]', err);
            } finally {
                isPolling = false;
                isFirstRun = false;
            }
        }

        // Run immediately
        poll();

        // Set recurring timer
        timer = setInterval(poll, Math.max(2500, intervalMs));

        // Resume immediately when user focuses back on window
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                poll();
            }
        });

        return {
            stop: () => clearInterval(timer),
            refresh: () => poll()
        };
    };

})();
