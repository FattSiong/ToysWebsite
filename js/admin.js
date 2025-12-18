document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.querySelector('.sidebar');
    const toggleBtn = document.querySelector('#sidebarToggle');
    const overlay = document.querySelector('#sidebarOverlay');
    const body = document.body;

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            body.classList.toggle('sidebar-open');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            body.classList.remove('sidebar-open');
        });
    }
});