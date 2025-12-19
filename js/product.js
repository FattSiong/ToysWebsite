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

    toggles.forEach(toggle => {
        toggle.addEventListener('change', function () {
            const productId = this.getAttribute('data-id');
            const newDbStatus = this.checked ? 'Active' : 'Inactive';
            const newUiText = this.checked ? 'Activated' : 'Deactivated';
            const statusLabel = this.closest('td').querySelector('.status-text');

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
                        // Update Text
                        if (statusLabel) {
                            statusLabel.textContent = newUiText;
                            statusLabel.style.color = '';
                        }

                        // Update Count Badges
                        if (badgeActive && badgeInactive) {
                            let activeCount = parseInt(badgeActive.textContent) || 0;
                            let inactiveCount = parseInt(badgeInactive.textContent) || 0;

                            if (newDbStatus === 'Active') {
                                badgeActive.textContent = activeCount + 1;
                                badgeInactive.textContent = Math.max(0, inactiveCount - 1);
                            } else {
                                badgeActive.textContent = Math.max(0, activeCount - 1);
                                badgeInactive.textContent = inactiveCount + 1;
                            }
                        }
                    } else {
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

    // --- 3. ARCHIVE PRODUCT LOGIC (Delete Button) ---
    const deleteBtns = document.querySelectorAll('.delete-btn');

    deleteBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const productId = this.getAttribute('data-id');
            // Get the current status before archiving to update badges correctly
            const currentStatus = this.getAttribute('data-status');

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
                            // Remove the row from the table
                            const row = document.getElementById('row-' + productId);
                            if (row) {
                                row.style.transition = 'opacity 0.5s';
                                row.style.opacity = '0';
                                setTimeout(() => row.remove(), 500);
                            }

                            // Update Badges
                            // 1. Decrease the specific status count
                            if (currentStatus === 'Active' && badgeActive) {
                                badgeActive.textContent = Math.max(0, (parseInt(badgeActive.textContent) || 0) - 1);
                            } else if (currentStatus === 'Inactive' && badgeInactive) {
                                badgeInactive.textContent = Math.max(0, (parseInt(badgeInactive.textContent) || 0) - 1);
                            }

                            // 2. Decrease "All" count (since All excludes Archived)
                            if (badgeAll) {
                                badgeAll.textContent = Math.max(0, (parseInt(badgeAll.textContent) || 0) - 1);
                            }

                            // 3. Increase "Archived" count
                            if (badgeArchived) {
                                badgeArchived.textContent = (parseInt(badgeArchived.textContent) || 0) + 1;
                            }

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

    const priceBtn = document.getElementById('priceBtn');
    const priceInputs = document.getElementById('priceInputs');
    const closePriceBtn = document.getElementById('closePriceBtn');
    const minInput = document.getElementById('minInput');
    const maxInput = document.getElementById('maxInput');
    const form = document.getElementById('filterForm');

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
        if ("<?= $has_price ?>") {
            form.submit(); 
        } else {
            priceInputs.style.display = 'none';
            priceBtn.style.display = 'flex';
        }
    });
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