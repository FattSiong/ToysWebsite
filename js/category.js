document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
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

    // Initial Render
    renderMainTable();

    // --- Event Listeners ---
    headerAddBtn.addEventListener('click', openModal);
    emptyAddBtn.addEventListener('click', openModal);

    document.getElementById('closeModalBtn').addEventListener('click', closeModal);
    document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
    document.getElementById('addSelectedBtn').addEventListener('click', saveModalSelection);

    // Filter Listeners
    modalSearch.addEventListener('input', filterModalList);
    modalCategory.addEventListener('change', filterModalList);
    modalMinPrice.addEventListener('input', filterModalList);
    modalMaxPrice.addEventListener('input', filterModalList);

    // Price Toggle Logic
    modalPriceBtn.addEventListener('click', () => {
        modalPriceBtn.style.display = 'none';
        modalPriceInputs.style.display = 'flex';
        modalMinPrice.focus();
    });

    modalClosePriceBtn.addEventListener('click', (e) => {
        e.stopPropagation(); // prevent weird bubbling
        modalMinPrice.value = '';
        modalMaxPrice.value = '';
        modalPriceInputs.style.display = 'none';
        modalPriceBtn.style.display = 'flex';
        filterModalList(); // Reset price filter
    });

    // Select All Logic
    document.getElementById('selectAllModal').addEventListener('change', function () {
        const checkboxes = modalTbody.querySelectorAll('.modal-chk');
        checkboxes.forEach(chk => {
            if (chk.closest('tr').style.display !== 'none') {
                chk.checked = this.checked;
            }
        });
    });

    // ----------------------
    // MAIN PAGE FUNCTIONS
    // ----------------------
    function renderMainTable() {
        selectedTbody.innerHTML = '';
        hiddenContainer.innerHTML = '';

        if (!currentSelection || currentSelection.length === 0) {
            emptyState.style.display = 'flex'; // Changed to flex to center content
            selectedTable.style.display = 'none';
            headerAddBtn.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        selectedTable.style.display = 'table';
        headerAddBtn.style.display = 'flex';

        currentSelection.forEach((id, index) => {
            const product = allProducts.find(p => String(p.product_id) === String(id));
            if (!product) return;

            let imgSrc = 'https://placehold.co/40?text=No+Img';
            if (product.file_path) {
                // Handle relative paths if needed, mimicking PHP logic
                imgSrc = product.file_path.startsWith('../') ? product.file_path : '../' + product.file_path;
            }

            let statusText;
            if (product.status === 'Active') {
                statusText = 'Activated';
            } else if (product.status === 'Archived') {
                statusText = 'Archived';
            } else {
                statusText = 'Deactivated';
            }

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>
                    <div class="prod-cell">
                        <img src="${imgSrc}" class="prod-thumb">
                        <span>${product.name}</span>
                    </div>
                </td>
                <td>$${parseFloat(product.price).toFixed(2)}</td>
                <td>${statusText}</td>
                <td>
                    <!-- UPDATED: Use same classes as admin list -->
                    <button type="button" class="action-btn delete-btn" data-id="${id}" title="Remove">
                        <i class="icon fa-trash"></i>
                    </button>
                </td>
            `;
            selectedTbody.appendChild(tr);

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'product_ids[]';
            input.value = id;
            hiddenContainer.appendChild(input);
        });

        // Add listeners to new delete buttons
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

    // ----------------------
    // MODAL FUNCTIONS
    // ----------------------
    function openModal() {
        modal.classList.add('open');
        renderModalList();
        // Reset Filters
        modalSearch.value = '';
        modalCategory.value = '';
        modalMinPrice.value = '';
        modalMaxPrice.value = '';
        modalPriceInputs.style.display = 'none';
        modalPriceBtn.style.display = 'flex';

        modalSearch.focus();
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

            // Store data attributes for filtering
            tr.dataset.name = product.name.toLowerCase();
            tr.dataset.price = parseFloat(product.price);
            tr.dataset.cats = product.category_ids ? product.category_ids.split(',') : [];

            tr.innerHTML = `
                <td><input type="checkbox" class="modal-chk" value="${product.product_id}" ${isChecked ? 'checked' : ''}></td>
                <td>${index + 1}</td>
                <td>
                    <div class="prod-cell">
                        <img src="${imgSrc}" class="prod-thumb">
                        <span>${product.name}</span>
                    </div>
                </td>
                <td>$${parseFloat(product.price).toFixed(2)}</td>
                <td>${product.stock_quantity}</td>
            `;
            modalTbody.appendChild(tr);
        });
    }

    // --- FILTER LOGIC ---
    function filterModalList() {
        const searchTerm = modalSearch.value.toLowerCase();
        const categoryId = modalCategory.value;
        const minPrice = parseFloat(modalMinPrice.value) || 0;
        const maxPrice = parseFloat(modalMaxPrice.value) || Infinity;

        const rows = modalTbody.querySelectorAll('.modal-row');

        rows.forEach(row => {
            const name = row.dataset.name;
            const price = parseFloat(row.dataset.price);
            // category_ids is a comma-separated string in the dataset, handled as array
            const productCats = row.dataset.cats.split(',');

            let isMatch = true;

            // 1. Search
            if (searchTerm && !name.includes(searchTerm)) {
                isMatch = false;
            }

            // 2. Category
            // Note: productCats array might contain strings, categoryId is string.
            if (categoryId && !productCats.includes(categoryId)) {
                isMatch = false;
            }

            // 3. Price
            if (price < minPrice || price > maxPrice) {
                isMatch = false;
            }

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