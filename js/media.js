/**
 * Enables Drag & Drop and Preview functionality for file inputs.
 * * @param {string} fileInputId - ID of the hidden file input (e.g., 'file-upload')
 * @param {string} dropZoneId - ID of the label/div acting as drop zone (e.g., 'drop-zone')
 * @param {string} previewContainerId - ID of the div to show previews (e.g., 'preview-container')
 */
function enableMediaPreview(fileInputId, dropZoneId, previewContainerId) {
    const fileInput = document.getElementById(fileInputId);
    const dropZone = document.getElementById(dropZoneId);
    const previewContainer = document.getElementById(previewContainerId);

    if (!fileInput || !dropZone || !previewContainer) {
        console.error('Media Preview: Missing elements.', { fileInput, dropZone, previewContainer });
        return;
    }

    // 1. Persistent Storage (The "Virtual List")
    // This object keeps track of ALL files added so far.
    const dataTransfer = new DataTransfer();

    // --- Drag and Drop Events ---
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
    });

    // Handle Drop
    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) {
            addFiles(files);
        }
    }

    // Handle "Browse" Button Click
    fileInput.addEventListener('change', function () {
        // When user selects files, 'this.files' has the NEW files.
        // We grab them, add to our list, and then push the FULL list back.
        if (this.files.length > 0) {
            // We verify if these files are already in our dataTransfer to avoid loops
            // (Setting fileInput.files manually doesn't usually trigger 'change', but safety first)
            let isNew = false;
            if (dataTransfer.files.length !== this.files.length) isNew = true;

            if (isNew || dataTransfer.files.length === 0) {
                addFiles(this.files);
            }
        }
    });

    // --- Logic to Add Files to List ---
    function addFiles(files) {
        for (let i = 0; i < files.length; i++) {
            const file = files[i];

            // Duplicate Check: Don't add if name & size match an existing file
            let duplicate = false;
            for (let j = 0; j < dataTransfer.files.length; j++) {
                if (dataTransfer.files[j].name === file.name && dataTransfer.files[j].size === file.size) {
                    duplicate = true;
                    break;
                }
            }

            if (!duplicate) {
                dataTransfer.items.add(file);
            }
        }

        // Sync our Virtual List to the Actual Input
        fileInput.files = dataTransfer.files;

        // Re-render previews
        renderPreviews();
    }

    // --- Logic to Remove a File ---
    window.removePreviewFile = function (index) {
        const newDataTransfer = new DataTransfer();
        const files = dataTransfer.files;

        // Rebuild list excluding the removed index
        for (let i = 0; i < files.length; i++) {
            if (i !== index) {
                newDataTransfer.items.add(files[i]);
            }
        }

        // Update Global List
        dataTransfer.items.clear();
        for (let i = 0; i < newDataTransfer.files.length; i++) {
            dataTransfer.items.add(newDataTransfer.files[i]);
        }

        // Sync to Input
        fileInput.files = dataTransfer.files;

        // Re-render
        renderPreviews();
    }

    // --- Render Logic ---
    function renderPreviews() {
        previewContainer.innerHTML = '';
        const files = dataTransfer.files;

        if (files) {
            Array.from(files).forEach((file, index) => {
                const reader = new FileReader();
                const div = document.createElement('div');
                div.className = 'preview-item';

                // Add Remove Button
                const removeBtn = document.createElement('button');
                removeBtn.className = 'preview-remove-btn';
                removeBtn.innerHTML = '×';
                removeBtn.type = 'button'; // Prevent form submit
                removeBtn.onclick = function () {
                    window.removePreviewFile(index);
                };
                div.appendChild(removeBtn);

                reader.onload = function (e) {
                    let element;
                    let badgeText = '';

                    if (file.type.startsWith('image/')) {
                        element = document.createElement('img');
                        badgeText = 'NEW IMG';
                    } else if (file.type.startsWith('video/')) {
                        element = document.createElement('video');
                        element.muted = true;
                        element.controls = false;
                        badgeText = 'NEW VIDEO';
                    }

                    if (element) {
                        element.src = e.target.result;
                        div.appendChild(element);

                        // Add Badge
                        const badge = document.createElement('span');
                        badge.className = 'preview-type-badge';
                        badge.style.background = '#28a745';
                        badge.innerText = badgeText;
                        div.appendChild(badge);

                        previewContainer.appendChild(div);
                    }
                }

                reader.readAsDataURL(file);
            });
        }
    }
}