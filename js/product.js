document.addEventListener('DOMContentLoaded', function () {

    // ==========================================
    // 1. PRODUCT FORM (Media Preview)
    // ==========================================
    const uploadInput = document.getElementById('file-upload');
    if (uploadInput) {
        if (typeof enableMediaPreview === 'function') {
            enableMediaPreview('file-upload', 'drop-zone', 'preview-container');
        } else {
            console.error("Warning: enableMediaPreview function (from uploadMedia.js) not found.");
        }
    }

    // ==========================================
    // 2. PRODUCT LIST (Status Toggle)
    // ==========================================
    const toggles = document.querySelectorAll('.toggle-status');
    const dataTable = document.querySelector('.data-table');
    const currentTab = dataTable ? dataTable.getAttribute('data-current-tab') : 'All';

    toggles.forEach(toggle => {
        toggle.addEventListener('change', function () {
            const productId = this.getAttribute('data-id');
            const newDbStatus = this.checked ? 'Active' : 'Inactive';
            const newUiText = this.checked ? 'Activated' : 'Deactivated';
            const statusLabel = this.closest('td').querySelector('.status-text');
            const row = document.getElementById('row-' + productId);

            // Temporarily disable while processing
            this.disabled = true;

            fetch('product_list.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: productId, status: newDbStatus })
            })
                .then(response => response.json())
                .then(data => {
                    this.disabled = false;
                    if (data.success) {
                        // Update UI attributes
                        this.setAttribute('data-current-status', newDbStatus);
                        if (statusLabel) statusLabel.textContent = newUiText;

                        // Update global badges and row visibility
                        if (typeof updateBadgesLocal === 'function') updateBadgesLocal();
                        handleRowVisibility(row, currentTab, newDbStatus);
                    } else {
                        // Revert on failure
                        this.checked = !this.checked;
                        alert('Failed to update status: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    this.disabled = false;
                    this.checked = !this.checked;
                    console.error('Status update error:', error);
                });
        });
    });

    // ==========================================
    // 3. ARCHIVE PRODUCT (Delete Button)
    // ==========================================
    const deleteBtns = document.querySelectorAll('.delete-btn');

    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const productId = this.getAttribute('data-id');
            const row = document.getElementById('row-' + productId);

            if (confirm('Are you sure you want to delete this product? It will be moved to the Archived tab.')) {
                fetch('product_list.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: productId, status: 'Archived' })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (typeof updateBadgesLocal === 'function') updateBadgesLocal();
                            handleRowVisibility(row, currentTab, 'Archived');
                        } else {
                            alert('Failed to archive: ' + (data.message || 'Error'));
                        }
                    })
                    .catch(error => {
                        console.error('Archive error:', error);
                        alert('An error occurred while archiving.');
                    });
            }
        });
    });

    // ==========================================
    // 4. PRICE FILTER UI
    // ==========================================
    const priceBtn = document.getElementById('priceBtn');
    const priceInputs = document.getElementById('priceInputs');
    const closePriceBtn = document.getElementById('closePriceBtn');
    const minInput = document.getElementById('minInput');
    const maxInput = document.getElementById('maxInput');
    const filterForm = document.getElementById('filterForm');

    if (priceBtn) {
        priceBtn.addEventListener('click', () => {
            priceBtn.style.display = 'none';
            priceInputs.style.display = 'flex';
            minInput.focus();
        });

        closePriceBtn.addEventListener('click', () => {
            minInput.value = '';
            maxInput.value = '';

            // If filters were previously applied, clear them by submitting form
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('min_price') || urlParams.has('max_price')) {
                filterForm.submit();
            } else {
                priceInputs.style.display = 'none';
                priceBtn.style.display = 'flex';
            }
        });
    }

    // ==========================================
    // 5. HELPER FUNCTIONS
    // ==========================================

    function updateBadgesLocal() {
        if (!document.querySelector('.tabs-container')) return;

        fetch('?action=get_counts')
            .then(res => res.ok ? res.json() : Promise.reject('Network error'))
            .then(data => {
                if (document.getElementById('badge-all')) document.getElementById('badge-all').textContent = data.total;
                if (document.getElementById('badge-active')) document.getElementById('badge-active').textContent = data.active_count;
                if (document.getElementById('badge-inactive')) document.getElementById('badge-inactive').textContent = data.inactive_count;
                if (document.getElementById('badge-archived')) document.getElementById('badge-archived').textContent = data.archived_count;
            })
            .catch(err => console.error('Error updating badges:', err));
    }

    function handleRowVisibility(row, currentTab, newStatus) {
        if (!row) return;

        let shouldHide = false;

        // Determine visibility based on logic
        if (currentTab === 'Active' && newStatus !== 'Active') shouldHide = true;
        if (currentTab === 'Inactive' && newStatus !== 'Inactive') shouldHide = true;
        if (currentTab === 'Archived' && newStatus !== 'Archived') shouldHide = true;
        if (currentTab === 'All' && newStatus === 'Archived') shouldHide = true;

        if (shouldHide) {
            row.style.transition = 'opacity 0.5s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                checkEmptyTable();
            }, 500);
        }
    }

    function checkEmptyTable() {
        const tbody = document.querySelector('.data-table tbody');
        if (tbody && tbody.children.length === 0) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = parseInt(urlParams.get('page')) || 1;

            if (currentPage > 1) {
                // Go back a page if this page is now empty
                urlParams.set('page', currentPage - 1);
                window.location.search = urlParams.toString();
            } else {
                // Show "No products" message
                const noDataRow = document.createElement('tr');
                noDataRow.innerHTML = '<td colspan="6" style="text-align:center; padding: 30px; color: #888;">No products found.</td>';
                tbody.appendChild(noDataRow);
            }
        }
    }
});

/**
 * Handles the "Remove" button click for EXISTING media items.
 * Exposed globally for onclick attributes.
 */
function markForDeletion(element, mediaId) {
    if (confirm('Are you sure you want to delete this media? It will be removed when you click "Save Product".')) {
        const item = element.closest('.preview-item');
        if (item) {
            item.style.display = 'none';
        }

        const deleteInput = document.getElementById('deleted_media_ids');
        if (deleteInput) {
            let currentIds = deleteInput.value ? deleteInput.value.split(',') : [];
            currentIds.push(mediaId);
            deleteInput.value = currentIds.join(',');
        }
    }
}