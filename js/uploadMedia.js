/* ../js/uploadMedia.js */

document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-upload');
    const previewContainer = document.getElementById('preview-container');
    
    // We use a DataTransfer object to manipulate the files in the input
    const dataTransfer = new DataTransfer();

    // --- 1. Drag and Drop Events ---
    
    // Prevent default browser behaviors for drag events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    // Add visual cue when dragging over
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
    });

    // Remove visual cue when drag leaves or drops
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
    });

    // Handle dropped files
    dropZone.addEventListener('drop', handleDrop, false);

    // Handle standard click selection
    fileInput.addEventListener('change', handleFiles, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        processFiles(files);
    }

    function handleFiles(e) {
        const files = this.files;
        processFiles(files);
    }

    // --- 2. File Processing & Preview Generation ---

    function processFiles(files) {
        if (!files || files.length === 0) return;

        Array.from(files).forEach(file => {
            // Validate Type
            if (!file.type.startsWith('image/') && !file.type.startsWith('video/')) {
                alert('Skipped invalid file: ' + file.name);
                return;
            }

            // Add to DataTransfer (preparing for form submission)
            dataTransfer.items.add(file);

            // Generate Preview
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onloadend = function() {
                const isVideo = file.type.startsWith('video/');
                createPreviewElement(reader.result, isVideo, file.name);
            }
        });

        // Update the actual input element's files property
        fileInput.files = dataTransfer.files;
    }

    // Create HTML matching your product_form.css
    function createPreviewElement(src, isVideo, fileName) {
        const div = document.createElement('div');
        div.classList.add('preview-item');

        // Close/Remove Button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.classList.add('preview-remove-btn');
        removeBtn.innerHTML = '×';
        removeBtn.onclick = function() {
            removeFileFromList(fileName, div);
        };

        // Media Element
        let media;
        if (isVideo) {
            media = document.createElement('video');
            media.src = src;
            media.controls = false; // Thumbnail usually doesn't need controls immediately
        } else {
            media = document.createElement('img');
            media.src = src;
        }

        // Badges (e.g., VIDEO tag)
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

    // --- 3. Removing Files from the "New Uploads" List ---

    function removeFileFromList(fileName, previewElement) {
        // Create a new DataTransfer to filter out the deleted file
        const newDataTransfer = new DataTransfer();
        
        Array.from(dataTransfer.files).forEach(file => {
            if (file.name !== fileName) {
                newDataTransfer.items.add(file);
            }
        });

        // Update the main DataTransfer and the Input
        dataTransfer.items.clear();
        Array.from(newDataTransfer.files).forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;

        // Remove the visual preview
        previewElement.remove();
    }
});

// --- 4. Function for Existing Database Media (Server-side deletion) ---
// This function needs to be global because it's called onclick in the PHP HTML
window.markForDeletion = function(btn, mediaId) {
    if (!confirm('Delete this saved media?')) return;

    const item = btn.closest('.preview-item');
    const deleteInput = document.getElementById('deleted_media_ids');

    // Add ID to hidden input
    let currentIds = deleteInput.value ? deleteInput.value.split(',') : [];
    currentIds.push(mediaId);
    deleteInput.value = currentIds.join(',');

    // Visual removal
    item.style.opacity = '0';
    setTimeout(() => item.remove(), 300);
};