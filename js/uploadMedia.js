/**
 * Initializes the Drag-and-Drop Media Uploader.
 * This function is called from product.js.
 * * @param {string} inputId - ID of the file input (e.g., 'file-upload')
 * @param {string} dropZoneId - ID of the label/div (e.g., 'drop-zone')
 * @param {string} previewContainerId - ID of the container for thumbnails
 */
function enableMediaPreview(inputId, dropZoneId, previewContainerId) {
    const fileInput = document.getElementById(inputId);
    const dropZone = document.getElementById(dropZoneId);
    const previewContainer = document.getElementById(previewContainerId);

    if (!fileInput || !dropZone || !previewContainer) return;

    // Use DataTransfer to manipulate the file list (allows adding/removing files)
    const dataTransfer = new DataTransfer();

    // ==========================================
    // 1. DRAG & DROP EVENTS
    // ==========================================

    // Prevent default browser behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    // Add visual cue
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
    });

    // Remove visual cue
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
    });

    // Handle Drop
    dropZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        processFiles(files);
    }, false);

    // Handle Click Selection
    fileInput.addEventListener('change', function () {
        processFiles(this.files);
    }, false);

    // ==========================================
    // 2. PROCESS & PREVIEW
    // ==========================================
    function processFiles(files) {
        if (!files || files.length === 0) return;

        Array.from(files).forEach(file => {
            // Validate Type
            if (!file.type.startsWith('image/') && !file.type.startsWith('video/')) {
                alert('Skipped invalid file type: ' + file.name);
                return;
            }

            // Check for duplicates (optional but good UI)
            let isDuplicate = false;
            for (let i = 0; i < dataTransfer.files.length; i++) {
                if (dataTransfer.files[i].name === file.name && dataTransfer.files[i].size === file.size) {
                    isDuplicate = true;
                    break;
                }
            }
            if (isDuplicate) return;

            // Add to DataTransfer
            dataTransfer.items.add(file);

            // Generate Preview
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = function () {
                const isVideo = file.type.startsWith('video/');
                createPreviewElement(reader.result, isVideo, file.name);
            }
        });

        // Update the actual input's files
        fileInput.files = dataTransfer.files;
    }

    function createPreviewElement(src, isVideo, fileName) {
        const div = document.createElement('div');
        div.classList.add('preview-item');

        // Remove Button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.classList.add('preview-remove-btn');
        removeBtn.innerHTML = '×';
        removeBtn.title = 'Remove upload';
        removeBtn.onclick = function () {
            removeFileFromList(fileName, div);
        };

        // Media Element
        let media;
        if (isVideo) {
            media = document.createElement('video');
            media.src = src;
            media.controls = false; // Thumbnails shouldn't autoplay or have controls
        } else {
            media = document.createElement('img');
            media.src = src;
        }

        // Badges
        if (isVideo) {
            const badge = document.createElement('span');
            badge.classList.add('preview-type-badge');
            badge.innerText = 'VIDEO';
            div.appendChild(badge);
        }

        div.appendChild(removeBtn);
        div.appendChild(media);
        previewContainer.appendChild(div);
    }

    // ==========================================
    // 3. REMOVE FROM UPLOAD QUEUE
    // ==========================================
    function removeFileFromList(fileName, previewElement) {
        const newDataTransfer = new DataTransfer();

        // Copy all files except the one being removed
        Array.from(dataTransfer.files).forEach(file => {
            if (file.name !== fileName) {
                newDataTransfer.items.add(file);
            }
        });

        // Update Global Objects
        dataTransfer.items.clear();
        Array.from(newDataTransfer.files).forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;

        // Remove Visuals
        previewElement.remove();
    }
}

/**
 * Handles server-side deletion of EXISTING media.
 * This must be globally accessible for inline onclick events in PHP.
 */
window.markForDeletion = function (btn, mediaId) {
    if (!confirm('Are you sure you want to delete this saved media? It will be removed when you save.')) return;

    const item = btn.closest('.preview-item');
    const deleteInput = document.getElementById('deleted_media_ids');

    // Add ID to hidden input
    let currentIds = deleteInput.value ? deleteInput.value.split(',') : [];
    if (!currentIds.includes(String(mediaId))) {
        currentIds.push(mediaId);
        deleteInput.value = currentIds.join(',');
    }

    // Visual removal (hide until saved)
    item.style.transition = 'opacity 0.3s';
    item.style.opacity = '0';
    setTimeout(() => {
        item.style.display = 'none';
    }, 300);
};