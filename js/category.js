document.addEventListener('DOMContentLoaded', () => {
    // ==========================================
    // 1. ELEMENT SELECTION
    // ==========================================
    const modal = document.getElementById('productModal');
    const modalTbody = document.getElementById('modal-tbody');
    const selectedTbody = document.getElementById('selected-tbody');
    const emptyState = document.getElementById('empty-state');
    const selectedTable = document.getElementById('selected-table');
    const hiddenContainer = document.getElementById('hidden-inputs-container');

    // Filter Elements
    const modalSearch = document.getElementById('modalSearch');
    const modalCategory = document.getElementById('modalCategory');
    const modalPriceBtn = document.getElementById('modalPriceBtn');
    const modalPriceInputs = document.getElementById('modalPriceInputs');
    const modalClosePriceBtn = document.getElementById('modalClosePriceBtn');
    const modalMinPrice = document.getElementById('modalMinPrice');
    const modalMaxPrice = document.getElementById('modalMaxPrice');

    // Buttons
    const headerAddBtn = document.getElementById('headerAddBtn');
    const emptyAddBtn = document.getElementById('emptyAddBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelModalBtn = document.getElementById('cancelModalBtn');
    const addSelectedBtn = document.getElementById('addSelectedBtn');
    const selectAllModal = document.getElementById('selectAllModal');

    // ==========================================
    // 2. INITIALIZATION & LISTENERS
    // ==========================================
    renderMainTable();

    // Open/Close Modal
    headerAddBtn.addEventListener('click', openModal);
    emptyAddBtn.addEventListener('click', openModal);
    closeModalBtn.addEventListener('click', closeModal);
    cancelModalBtn.addEventListener('click', closeModal);

    // Actions
    addSelectedBtn.addEventListener('click', saveModalSelection);

    // Filters
    modalSearch.addEventListener('input', filterModalList);
    modalCategory.addEventListener('change', filterModalList);
    modalMinPrice.addEventListener('input', filterModalList);
    modalMaxPrice.addEventListener('input', filterModalList);

    // Price Toggle UI
    modalPriceBtn.addEventListener('click', () => {
        modalPriceBtn.style.display = 'none';
        modalPriceInputs.style.display = 'flex';
        modalMinPrice.focus();
    });

    modalClosePriceBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        modalMinPrice.value = '';
        modalMaxPrice.value = '';
        modalPriceInputs.style.display = 'none';
        modalPriceBtn.style.display = 'flex';
        filterModalList(); // Reset price filter immediately
    });

    // Select All
    selectAllModal.addEventListener('change', function () {
        const checkboxes = modalTbody.querySelectorAll('.modal-chk');
        checkboxes.forEach(chk => {
            // Only select visible rows (respecting filters)
            if (chk.closest('tr').style.display !== 'none') {
                chk.checked = this.checked;
            }
        });
    });

    // ==========================================
    // 3. MAIN PAGE FUNCTIONS
    // ==========================================
    function renderMainTable() {
        selectedTbody.innerHTML = '';
        hiddenContainer.innerHTML = '';

        if (!currentSelection || currentSelection.length === 0) {
            emptyState.style.display = 'flex';
            selectedTable.style.display = 'none';
            headerAddBtn.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        selectedTable.style.display = 'table';
        headerAddBtn.style.display = 'flex'; // Show "Add +" in header

        currentSelection.forEach((id, index) => {
            const product = allProducts.find(p => String(p.product_id) === String(id));
            if (!product) return;

            let imgSrc = 'https://placehold.co/40?text=No+Img';
            if (product.file_path) {
                // Ensure path is correct for admin folder
                imgSrc = product.file_path.startsWith('../') ? product.file_path : '../' + product.file_path;
            }

            let statusText = product.status === 'Active' ? 'Activated' :
                product.status === 'Archived' ? 'Archived' : 'Deactivated';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>
                    <div class="product-cell">
                        <img src="${imgSrc}" class="product-thumb" alt="Img">
                        <span>${product.name}</span>
                    </div>
                </td>
                <td>$${parseFloat(product.price).toFixed(2)}</td>
                <td>${statusText}</td>
                <td>
                    <button type="button" class="action-btn delete-btn" data-id="${id}" title="Remove">
                        <i class="icon fa-trash"></i>
                    </button>
                </td>
            `;
            selectedTbody.appendChild(tr);

            // Add hidden input for form submission
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = id;
            hiddenContainer.appendChild(input);
        });

        // Re-attach delete listeners
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                removeProduct(this.getAttribute('data-id'));
            });
        });
    }

    function removeProduct(id) {
        currentSelection = currentSelection.filter(pid => String(pid) !== String(id));
        renderMainTable();
    }

    // ==========================================
    // 4. MODAL FUNCTIONS
    // ==========================================
    function openModal() {
        modal.classList.add('open');
        renderModalList();

        // Reset Inputs
        modalSearch.value = '';
        modalCategory.value = '';
        modalMinPrice.value = '';
        modalMaxPrice.value = '';
        modalPriceInputs.style.display = 'none';
        modalPriceBtn.style.display = 'flex';

        // Focus search
        setTimeout(() => modalSearch.focus(), 100);
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    function renderModalList() {
        modalTbody.innerHTML = '';

        allProducts.forEach((product, index) => {
            const isChecked = currentSelection.includes(String(product.product_id));

            let imgSrc = 'https://placehold.co/40?text=No+Img';
            if (product.file_path) {
                imgSrc = product.file_path.startsWith('../') ? product.file_path : '../' + product.file_path;
            }

            const tr = document.createElement('tr');
            tr.className = 'modal-row';

            // Data attributes for efficient filtering
            tr.dataset.name = product.name.toLowerCase();
            tr.dataset.price = parseFloat(product.price);
            // Handle potentially null category_ids
            tr.dataset.cats = product.category_ids ? product.category_ids : '';

            tr.innerHTML = `
                <td><input type="checkbox" class="modal-chk" value="${product.product_id}" ${isChecked ? 'checked' : ''}></td>
                <td>${index + 1}</td>
                <td>
                    <div class="product-cell">
                        <img src="${imgSrc}" class="product-thumb" alt="Img">
                        <span>${product.name}</span>
                    </div>
                </td>
                <td>$${parseFloat(product.price).toFixed(2)}</td>
                <td>${product.stock_quantity}</td>
            `;
            modalTbody.appendChild(tr);
        });
    }

    function filterModalList() {
        const searchTerm = modalSearch.value.toLowerCase();
        const categoryId = modalCategory.value; // String
        const minPrice = parseFloat(modalMinPrice.value) || 0;
        const maxPrice = parseFloat(modalMaxPrice.value) || Infinity;

        const rows = modalTbody.querySelectorAll('.modal-row');

        rows.forEach(row => {
            const name = row.dataset.name;
            const price = parseFloat(row.dataset.price);
            const productCats = row.dataset.cats.split(','); // Array of strings

            let isMatch = true;

            // 1. Search Name
            if (searchTerm && !name.includes(searchTerm)) isMatch = false;

            // 2. Category Filter
            // We check if the selected categoryId exists in the product's category list
            if (isMatch && categoryId && !productCats.includes(categoryId)) isMatch = false;

            // 3. Price Filter
            if (isMatch && (price < minPrice || price > maxPrice)) isMatch = false;

            row.style.display = isMatch ? '' : 'none';
        });
    }

    function saveModalSelection() {
        const checkboxes = modalTbody.querySelectorAll('.modal-chk:checked');
        const newSelection = [];
        checkboxes.forEach(chk => {
            newSelection.push(chk.value);
        });

        currentSelection = newSelection;
        renderMainTable();
        closeModal();
    }
});