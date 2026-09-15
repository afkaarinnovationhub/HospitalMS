</div>
<!-- End Main Content Area Wrapper -->

<!-- Core Responsive & Collapsible Sidebar Scripts -->
<script>
    // Initialize sidebar state from localStorage on load
    (function() {
        if (window.innerWidth >= 1024) {
            const isCollapsed = (localStorage.getItem('hpms_sidebar_collapsed') || localStorage.getItem('medcore_sidebar_collapsed')) === 'true';
            if (isCollapsed) {
                document.documentElement.classList.add('sidebar-collapsed');
                const collapseIcon = document.getElementById('sidebar-collapse-icon');
                if (collapseIcon) collapseIcon.textContent = 'menu';
            }
        }
    })();

    function toggleSidebar() {
        if (window.innerWidth < 1024) {
            // Mobile / Tablet: toggle off-canvas drawer
            const sidebar = document.getElementById('app-sidebar');
            if (sidebar.classList.contains('-translate-x-full')) {
                openMobileSidebar();
            } else {
                closeMobileSidebar();
            }
        } else {
            // Desktop: toggle mini / collapsed mode
            const html = document.documentElement;
            const isCollapsed = html.classList.toggle('sidebar-collapsed');
            localStorage.setItem('hpms_sidebar_collapsed', isCollapsed ? 'true' : 'false');
            
            const collapseIcon = document.getElementById('sidebar-collapse-icon');
            if (collapseIcon) {
                collapseIcon.textContent = isCollapsed ? 'menu' : 'menu_open';
            }
        }
    }

    function openMobileSidebar() {
        const sidebar = document.getElementById('app-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        if (sidebar && backdrop) {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');
            backdrop.classList.remove('hidden');
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                backdrop.classList.add('opacity-100');
            }, 10);
            document.body.style.overflow = 'hidden';
        }
    }

    function closeMobileSidebar() {
        const sidebar = document.getElementById('app-sidebar');
        const backdrop = document.getElementById('sidebar-backdrop');
        if (sidebar && backdrop) {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.remove('opacity-100');
            backdrop.classList.add('opacity-0');
            setTimeout(() => {
                backdrop.classList.add('hidden');
            }, 300);
            document.body.style.overflow = '';
        }
    }

    // Keyboard ESC listener
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeMobileSidebar();
        }
    });

    // Handle viewport resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            closeMobileSidebar();
        }
    });
</script>

</body>
</html>
