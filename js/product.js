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
    const badgeActive = document.getElementById('badge-active');
    const badgeInactive = document.getElementById('badge-inactive');
    const badgeAll = document.getElementById('badge-all');
    const badgeArchived = document.getElementById('badge-archived');
    
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

                        // 3. Update Badges Logic
                        updateBadges(oldStatus, newDbStatus);

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

    // Helper to update badges
    function updateBadges(oldStatus, newStatus) {
        // Get current counts (safely)
        let activeCount = parseInt(badgeActive ? badgeActive.textContent : 0) || 0;
        let inactiveCount = parseInt(badgeInactive ? badgeInactive.textContent : 0) || 0;
        let archivedCount = parseInt(badgeArchived ? badgeArchived.textContent : 0) || 0;
        let allCount = parseInt(badgeAll ? badgeAll.textContent : 0) || 0;

        // Decrease Old Status Count
        if (oldStatus === 'Active') activeCount--;
        else if (oldStatus === 'Inactive') inactiveCount--;
        else if (oldStatus === 'Archived') archivedCount--;

        // Increase New Status Count
        if (newStatus === 'Active') activeCount++;
        else if (newStatus === 'Inactive') inactiveCount++;
        else if (newStatus === 'Archived') archivedCount++;

        // Update 'All' Count
        // 'All' usually includes Active + Inactive, but excludes Archived.
        // We recalculate based on if the item is moving IN or OUT of the 'All' scope.
        const wasInAll = (oldStatus !== 'Archived');
        const isInAll = (newStatus !== 'Archived');

        if (wasInAll && !isInAll) allCount--; // Moved to Archive
        if (!wasInAll && isInAll) allCount++; // Restored from Archive

        // Apply new values
        if (badgeActive) badgeActive.textContent = Math.max(0, activeCount);
        if (badgeInactive) badgeInactive.textContent = Math.max(0, inactiveCount);
        if (badgeArchived) badgeArchived.textContent = Math.max(0, archivedCount);
        if (badgeAll) badgeAll.textContent = Math.max(0, allCount);
    }

    // Helper to hide row if it no longer belongs in current tab
    function handleRowVisibility(row, tab, newStatus) {
        if (!row) return;

        let shouldRemove = false;

        if (tab === 'Active' && newStatus !== 'Active') shouldRemove = true;
        if (tab === 'Inactive' && newStatus !== 'Inactive') shouldRemove = true;
        if (tab === 'Archived' && newStatus !== 'Archived') shouldRemove = true;
        
        // If tab is 'All', generally we keep it unless it became Archived (which usually hides it from All)
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
    function checkEmptyTable() {
        const tbody = document.querySelector('.data-table tbody');
        if (tbody && tbody.children.length === 0) {
            const noDataRow = document.createElement('tr');
            noDataRow.innerHTML = '<td colspan="6" style="text-align:center; padding: 30px; color: #888;">No products found.</td>';
            tbody.appendChild(noDataRow);
        }
    }

    // --- 3. ARCHIVE PRODUCT LOGIC (Delete Button) ---
    const deleteBtns = document.querySelectorAll('.delete-btn');

    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const productId = this.getAttribute('data-id');
            const currentStatus = this.getAttribute('data-status');
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
                            updateBadges(currentStatus, 'Archived');
                            
                            // Handle Row Visibility
                            // If we archive, we generally remove it from Active/Inactive/All views
                            // If we are ALREADY in Archived view, we technically shouldn't see the delete button, 
                            // but if we did, we wouldn't remove it.
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
        // Show inputs when button clicked
        priceBtn.addEventListener('click', function() {
            priceBtn.style.display = 'none';
            priceInputs.style.display = 'flex';
            minInput.focus();
        });

        // Close inputs (and clear filter if it was active)
        closePriceBtn.addEventListener('click', function() {
            minInput.value = '';
            maxInput.value = '';
            // Check if PHP passed a value indicating filter was active (needs reload)
            // We can check URL params or a data attribute, or just check input values
            // Since inputs are cleared now, we might need to submit if there WAS a value.
            // Simple way: just hide. If user wants to reset, they cleared inputs.
            // If the URL has params, we should submit.
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
 * Handles the "Remove" button click for EXISTING media items (loaded from DB).
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