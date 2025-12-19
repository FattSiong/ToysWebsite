// Wait for DOM to load
document.addEventListener('DOMContentLoaded', function () {

    // --- 1. PRODUCT FORM LOGIC (Media Preview) ---
    if (document.getElementById('file-upload')) {
        if (typeof enableMediaPreview === 'function') {
            enableMediaPreview('file-upload', 'drop-zone', 'preview-container');
        } else {
            console.error("Error: enableMediaPreview function not found.");
        }
    }

    // --- 2. PRODUCT LIST LOGIC (Status Toggle) ---
    const toggles = document.querySelectorAll('.toggle-status');
    
    // Get the current tab from the table attribute we added
    const tableEl = document.querySelector('.data-table');
    const currentTab = tableEl ? tableEl.getAttribute('data-current-tab') : 'All';

    toggles.forEach(toggle => {
        toggle.addEventListener('change', function () {
            const productId = this.getAttribute('data-id');
            // Get the OLD status before the change
            const oldStatus = this.getAttribute('data-current-status');
            
            // Determine NEW status based on checkbox state
            const newDbStatus = this.checked ? 'Active' : 'Inactive';
            const newUiText = this.checked ? 'Activated' : 'Deactivated';
            
            const statusLabel = this.closest('td').querySelector('.status-text');
            const row = document.getElementById('row-' + productId);

            this.disabled = true;

            const updateUrl = 'product_list.php';

            fetch(updateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: productId, status: newDbStatus })
            })
                .then(response => response.json())
                .then(data => {
                    this.disabled = false;
                    if (data.success) {
                        // 1. Update the stored current status for next time
                        this.setAttribute('data-current-status', newDbStatus);

                        // 2. Update Text Label
                        if (statusLabel) {
                            statusLabel.textContent = newUiText;
                        }

                        // 3. Update Badges (Fetch from server)
                        if (typeof updateBadgesLocal === 'function') {
                            updateBadgesLocal();
                        }

                        // 4. Handle Row Visibility (Move to other tab)
                        handleRowVisibility(row, currentTab, newDbStatus);

                    } else {
                        // Revert checkbox if failed
                        this.checked = !this.checked;
                        alert('Failed: ' + (data.message || 'Error'));
                    }
                })
                .catch(error => {
                    this.disabled = false;
                    this.checked = !this.checked;
                    console.error(error);
                });
        });
    });

    // Helper to update badges by fetching fresh counts from the server
    function updateBadgesLocal() {
        if (!document.querySelector('.tabs-container')) return;

        fetch('?action=get_counts')
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if(document.getElementById('badge-all')) document.getElementById('badge-all').textContent = data.total;
                if(document.getElementById('badge-active')) document.getElementById('badge-active').textContent = data.active_count;
                if(document.getElementById('badge-inactive')) document.getElementById('badge-inactive').textContent = data.inactive_count;
                if(document.getElementById('badge-archived')) document.getElementById('badge-archived').textContent = data.archived_count;
            })
            .catch(err => console.error('Error updating badges:', err));
    }

    // Helper to hide row if it no longer belongs in current tab
    function handleRowVisibility(row, tab, newStatus) {
        if (!row) return;

        let shouldRemove = false;

        if (tab === 'Active' && newStatus !== 'Active') shouldRemove = true;
        if (tab === 'Inactive' && newStatus !== 'Inactive') shouldRemove = true;
        if (tab === 'Archived' && newStatus !== 'Archived') shouldRemove = true;
        
        // If tab is 'All', generally we keep it unless it became Archived
        if (tab === 'All' && newStatus === 'Archived') shouldRemove = true;

        if (shouldRemove) {
            row.style.transition = 'opacity 0.5s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                checkEmptyTable(); // Check if table is empty after removal
            }, 500);
        }
    }

    // Helper to check if table is empty and show "No products found"
    // Also handles pagination redirection if page becomes empty
    function checkEmptyTable() {
        const tbody = document.querySelector('.data-table tbody');
        if (tbody && tbody.children.length === 0) {
            // Check if we are on a page > 1 using URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = parseInt(urlParams.get('page')) || 1;

            if (currentPage > 1) {
                // Determine previous page
                const prevPage = currentPage - 1;
                urlParams.set('page', prevPage);
                // Reload page to the previous page
                window.location.search = urlParams.toString();
            } else {
                // If we are on page 1, show the "No products found" message
                const noDataRow = document.createElement('tr');
                noDataRow.innerHTML = '<td colspan="6" style="text-align:center; padding: 30px; color: #888;">No products found.</td>';
                tbody.appendChild(noDataRow);
            }
        }
    }

    // --- 3. ARCHIVE PRODUCT LOGIC (Delete Button) ---
    const deleteBtns = document.querySelectorAll('.delete-btn');

    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const productId = this.getAttribute('data-id');
            const row = document.getElementById('row-' + productId);
            const tableEl = document.querySelector('.data-table');
            const currentTab = tableEl ? tableEl.getAttribute('data-current-tab') : 'All';

            if (confirm('Are you sure you want to delete this product? It will be moved to the Archived tab.')) {
                const updateUrl = 'product_list.php';

                fetch(updateUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: productId, status: 'Archived' })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update Badges
                            updateBadgesLocal();
                            
                            // Handle Row Visibility
                            handleRowVisibility(row, currentTab, 'Archived');

                        } else {
                            alert('Failed to archive: ' + (data.message || 'Error'));
                        }
                    })
                    .catch(error => {
                        console.error(error);
                        alert('An error occurred.');
                    });
            }
        });
    });

    // --- PRICE FILTER UI ---
    const priceBtn = document.getElementById('priceBtn');
    const priceInputs = document.getElementById('priceInputs');
    const closePriceBtn = document.getElementById('closePriceBtn');
    const minInput = document.getElementById('minInput');
    const maxInput = document.getElementById('maxInput');
    const form = document.getElementById('filterForm');

    if (priceBtn) {
        priceBtn.addEventListener('click', function() {
            priceBtn.style.display = 'none';
            priceInputs.style.display = 'flex';
            minInput.focus();
        });

        closePriceBtn.addEventListener('click', function() {
            minInput.value = '';
            maxInput.value = '';
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('min_price') || urlParams.has('max_price')) {
                form.submit();
            } else {
                priceInputs.style.display = 'none';
                priceBtn.style.display = 'flex';
            }
        });
    }
});

/**
 * Handles the "Remove" button click for EXISTING media items.
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