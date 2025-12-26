$(document).ready(function () {
    
    // --- HELPER: UPDATE STATS ---
    function updateStats(stats) {
        if (!stats) return;
        $('#stat-total').text(parseInt(stats.total).toLocaleString());
        $('#stat-pending').text(parseInt(stats.pending).toLocaleString());
        $('#stat-processing').text(parseInt(stats.processing).toLocaleString());
        $('#stat-shipped').text(parseInt(stats.shipped).toLocaleString());
        $('#stat-delivered').text(parseInt(stats.delivered).toLocaleString());
        $('#stat-completed').text(parseInt(stats.completed).toLocaleString());
        $('#stat-cancel-refund').text(parseInt(stats.cancel_refund).toLocaleString());
    }

    // --- 1. HANDLE STATUS CHANGE (Dropdown) ---
    window.updateStatus = function (selectElement) {
        const form = $(selectElement).closest('form');
        const row = $(selectElement).closest('tr');
        const statusTextSpan = row.find('.text-status');
        const originalStatus = selectElement.defaultValue;
        const newStatus = selectElement.value;

        if (!confirm('Are you sure you want to change status to ' + newStatus + '?')) {
            selectElement.value = originalStatus;
            return;
        }

        $.ajax({
            url: 'order_list.php',
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    updateStatusBadge(statusTextSpan, newStatus);
                    selectElement.defaultValue = newStatus;
                    // Update stats card
                    updateStats(response.stats);
                } else {
                    alert('Error updating status: ' + (response.message || 'Unknown error'));
                    selectElement.value = originalStatus;
                }
            },
            error: function () {
                alert('System error occurred.');
                selectElement.value = originalStatus;
            }
        });
    };

    function updateStatusBadge(element, status) {
        element.text(status);
        element.removeClass('status-green status-blue status-orange status-red status-gray');

        if (status === 'Completed') element.addClass('status-green');
        else if (status === 'Pending') element.addClass('status-orange');
        else if (['Processing', 'Shipped', 'Delivered'].includes(status)) element.addClass('status-blue');
        else element.addClass('status-red');
    }

    // --- 2. MODAL LOGIC ---
    function loadOrderDetails(orderId) {
        const modal = $('#order-details-modal');
        const modalBody = modal.find('.modal-body');

        modalBody.html('<div style="text-align:center; padding:50px;"><i class="icon fa fa-spinner fa-spin"></i> Loading order details...</div>');

        $.ajax({
            url: 'order_details.php',
            type: 'GET',
            data: { id: orderId },
            success: function (html) {
                modalBody.html(html);
            },
            error: function () {
                modalBody.html('<div class="error-box">Failed to load order details.</div>');
            }
        });
    }

    $('.view-order-btn').click(function (e) {
        e.preventDefault();
        const orderId = $(this).data('id');
        const modal = $('#order-details-modal');

        modal.addClass('open');
        loadOrderDetails(orderId);
    });

    // --- 3. HANDLE STATUS UPDATE INSIDE MODAL (AJAX) ---
    $(document).on('submit', '.status-update-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const orderId = form.find('input[name="order_id"]').val();
        const newStatus = form.find('input[name="new_status"]').val();
        const btn = form.find('button[type="submit"]');
        const originalText = btn.text();

        btn.prop('disabled', true).text('Updating...');

        $.ajax({
            url: 'order_list.php',
            type: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadOrderDetails(orderId);
                    const row = $('button[data-id="' + orderId + '"]').closest('tr');
                    const statusTextSpan = row.find('.text-status');
                    updateStatusBadge(statusTextSpan, newStatus);
                    // Update stats card
                    updateStats(response.stats);
                } else {
                    alert('Error: ' + (response.message || 'Update failed'));
                    btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Request failed');
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // --- 4. CLOSE MODAL LOGIC ---
    const modal = document.getElementById('order-details-modal');
    const closeBtn = document.getElementById('closeOrderModalBtn');
    const cancelBtn = document.getElementById('cancelOrderModalBtn');

    function closeModal() {
        modal.classList.remove('open');
    }

    // Event Listeners for explicit buttons
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    // Close on overlay click
    window.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // --- 6. REFUND SEARCH AUTO-SUBMIT ---
    $('#refundSearchInput').on('change', function() {
        $('#searchForm').submit();
    });
});