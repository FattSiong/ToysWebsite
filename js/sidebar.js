document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;

    // Function to close sidebar
    const closeSidebar = () => {
        body.classList.remove('sidebar-open');
    };

    // Toggle button click
    if (toggleBtn) {
        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent immediate closing
            body.classList.toggle('sidebar-open');
        });
    }

    // Close when clicking overlay
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Close when clicking Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
    });
});