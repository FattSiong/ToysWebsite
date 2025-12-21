/**
 * BulkController
 * Handles the logic for Select All, Row Selection, and Header Toggling
 * Updated to handle dynamic row removal (MutationObserver)
 */
class BulkController {
    constructor() {
        this.bulkForm = document.getElementById('bulkForm');
        // If the form doesn't exist on this page (e.g., Dashboard), stop execution
        if (!this.bulkForm) return;

        this.selectAllDefault = document.getElementById('selectAllDefault');
        this.selectAllBulk = document.getElementById('selectAllBulk');

        this.tbody = document.querySelector('.data-table tbody');

        this.defaultHeader = document.getElementById('default-header');
        this.bulkHeader = document.getElementById('bulk-header');
        this.selectedCountSpan = document.getElementById('selected-count');
        this.bulkActionInput = document.getElementById('bulk-action-input');

        this.initListeners();
    }

    initListeners() {
        // 1. Event Delegation for Row Checkboxes
        this.bulkForm.addEventListener('change', (e) => {
            if (e.target.classList.contains('row-checkbox')) {
                this.updateState();
            }
        });

        // 2. Select All Listeners
        if (this.selectAllDefault) {
            this.selectAllDefault.addEventListener('change', (e) => this.toggleAll(e.target.checked));
        }

        if (this.selectAllBulk) {
            this.selectAllBulk.addEventListener('change', (e) => this.toggleAll(e.target.checked));
        }

        // 3. MutationObserver: Watch for rows being removed (e.g. via Switch Toggle)
        // This ensures the header hides if the last item is removed
        if (this.tbody) {
            const observer = new MutationObserver((mutations) => {
                this.updateState();
            });
            observer.observe(this.tbody, { childList: true });
        }

        // 4. Bind Bulk Buttons (Activate, Deactivate, etc.)
        const bulkBtns = document.querySelectorAll('.btn-bulk');
        bulkBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = btn.getAttribute('data-action');
                const confirmMsg = btn.getAttribute('data-confirm');

                if (confirmMsg && !confirm(confirmMsg)) {
                    return;
                }

                // Set the hidden input value and submit
                if (this.bulkActionInput) {
                    this.bulkActionInput.value = action;
                    this.bulkForm.submit();
                }
            });
        });
    }

    toggleAll(isChecked) {
        // Always query the current visible checkboxes
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => cb.checked = isChecked);

        if (this.selectAllDefault) this.selectAllDefault.checked = isChecked;
        if (this.selectAllBulk) this.selectAllBulk.checked = isChecked;

        this.updateState();
    }

    updateState() {
        // 1. Get all currently visible checkboxes in the DOM
        const checkboxes = document.querySelectorAll('.row-checkbox');

        // 2. Count how many are checked
        const selected = Array.from(checkboxes).filter(cb => cb.checked);
        const count = selected.length;
        const total = checkboxes.length;

        // 3. Update Text
        if (this.selectedCountSpan) {
            this.selectedCountSpan.textContent = count + (count === 1 ? ' Selected' : ' Selected');
        }

        // 4. Toggle Headers
        if (count > 0) {
            // Show Bulk, Hide Default
            if (this.defaultHeader) this.defaultHeader.classList.add('header-hidden');
            if (this.bulkHeader) this.bulkHeader.classList.add('header-visible');

            // Sync Master Checkbox
            const allChecked = total > 0 && count === total;
            if (this.selectAllBulk) this.selectAllBulk.checked = allChecked;
            if (this.selectAllDefault) this.selectAllDefault.checked = allChecked;

        } else {
            // Show Default, Hide Bulk
            if (this.defaultHeader) this.defaultHeader.classList.remove('header-hidden');
            if (this.bulkHeader) this.bulkHeader.classList.remove('header-visible');

            // Reset masters
            if (this.selectAllDefault) this.selectAllDefault.checked = false;
            if (this.selectAllBulk) this.selectAllBulk.checked = false;
        }
    }
}

// --- INITIALIZATION ---
document.addEventListener('DOMContentLoaded', () => {
    new BulkController();
});