/**
 * qb-file-manager.js
 */

document.addEventListener("DOMContentLoaded", function() {
    // --- DOM Elements ---
    const qbBreadcrumbs = document.getElementById("qb-breadcrumbs");
    const qbFileListContainer = document.getElementById("qb-file-list-container"); // This is the wrapper div for the grid
    const qbFileEditorArea = document.getElementById("qb-file-editor-area");
    const qbEditingFilename = document.getElementById("qb-editing-filename");
    const qbEditorTextarea = document.getElementById("qb-editor-textarea");
    const qbSaveFileBtn = document.getElementById("qb-save-file-btn");
    const qbCancelEditBtn = document.getElementById("qb-cancel-edit-btn");

    const qbCreateFolderForm = document.getElementById("qb-create-folder-form");
    const qbCreateFileForm = document.getElementById("qb-create-file-form");
    const qbUploadFileForm = document.getElementById("qb-upload-file-form");

    const confirmModal = document.getElementById("qb-confirm-modal");
    const confirmDeleteBtn = document.getElementById("qb-confirm-delete");
    const cancelDeleteBtn = document.getElementById("qb-cancel-delete");
    const modalItemNameSpan = document.getElementById("qb-modal-item-name");
    const notificationArea = document.getElementById("qb-notification-area");

    // --- State Variables ---
    let currentPath = ""; // Relative path from WordPress root
    let itemToDeletePath = ""; // Path of the item currently pending deletion

    // --- Utility Functions ---

    /**
     * Shows a custom notification message.
     * @param {string} message The message to display.
     * @param {string} type 'success', 'error', or 'warning'.
     * @param {number} duration Duration in milliseconds (default: 5000).
     */
    function showNotification(message, type, duration = 5000) {
        const notification = document.createElement("div");
        notification.classList.add("qb-notification", type);
        let icon = '';
        if (type === 'success') icon = '<span class="dashicons dashicons-yes"></span>';
        else if (type === 'error') icon = '<span class="dashicons dashicons-warning"></span>';
        else if (type === 'warning') icon = '<span class="dashicons dashicons-info"></span>';

        notification.innerHTML = `${icon} ${message}`;
        notificationArea.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.classList.add("show");
        }, 10); // Small delay for transition to work

        // Animate out and remove
        setTimeout(() => {
            notification.classList.remove("show");
            notification.classList.add("hide");
            notification.addEventListener('transitionend', () => {
                notification.remove();
            }, { once: true });
        }, duration);
    }

    /**
     * Displays the confirmation modal.
     * @param {string} itemName The name of the item to be deleted.
     */
    function showConfirmModal(itemName) {
        modalItemNameSpan.textContent = itemName;
        confirmModal.classList.remove("hidden");
        confirmModal.classList.add("visible");
    }

    /**
     * Hides the confirmation modal.
     */
    function hideConfirmModal() {
        confirmModal.classList.remove("visible");
        confirmModal.classList.add("hidden");
        itemToDeletePath = ""; // Clear the stored path
    }

    /**
     * Shows the loading spinner in the file list.
     */
    function showLoading() {
        // Update the loading message for the grid layout
        qbFileListContainer.innerHTML = `<ul class="qb-file-grid"><li class="qb-empty-folder-message"><span class="qb-loading-spinner"></span> Loading files...</li></ul>`;
        qbBreadcrumbs.innerHTML = `<span class="qb-loading-spinner"></span> Loading...`;
    }

    /**
     * Hides the main file manager interface and shows the editor.
     */
    function showEditor() {
        document.querySelector('.qb-header-actions-top').classList.add('hidden'); // Hide top actions
        qbFileListContainer.classList.add('hidden'); // Hide the grid container
        qbFileEditorArea.classList.remove('hidden'); // Show the editor area
    }

    /**
     * Hides the editor and shows the main file manager interface.
     */
    function hideEditor() {
        qbFileEditorArea.classList.add('hidden'); // Hide the editor area
        document.querySelector('.qb-header-actions-top').classList.remove('hidden'); // Show top actions
        qbFileListContainer.classList.remove('hidden'); // Show the grid container
        qbEditorTextarea.value = ''; // Clear textarea
        qbEditingFilename.textContent = ''; // Clear filename
    }

    /**
     * Renders the breadcrumbs based on the current path.
     */
    function renderBreadcrumbs() {
        let breadcrumbsHtml = `<a href="#" data-path="" class="qb-breadcrumb-link">Root</a>`;
        let pathParts = currentPath.split('/').filter(part => part !== '');
        let currentBreadcrumbPath = "";

        pathParts.forEach((part, index) => {
            currentBreadcrumbPath += (index > 0 ? '/' : '') + part;
            breadcrumbsHtml += ` <span class="qb-breadcrumb-separator">/</span> `;
            breadcrumbsHtml += `<a href="#" data-path="${currentBreadcrumbPath}" class="qb-breadcrumb-link">${part}</a>`;
        });
        qbBreadcrumbs.innerHTML = breadcrumbsHtml;

        // Add event listeners to new breadcrumb links
        qbBreadcrumbs.querySelectorAll('.qb-breadcrumb-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const targetPath = e.target.dataset.path || e.target.closest('a').dataset.path;
                loadDirectory(targetPath);
            });
        });
    }

    /**
     * Renders the file list using a grid layout.
     * @param {Array} items An array of file/folder objects.
     */
    function renderFileList(items) {
        let gridItemsHtml = '';

        // Add parent directory item if not at root
        if (currentPath !== "") {
            const parentPath = currentPath.split('/').slice(0, -1).join('/');
            gridItemsHtml += `
                <li class="qb-grid-item qb-grid-item-parent">
                    <a href="#" class="qb-parent-dir-link" data-path="${parentPath}">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <span class="qb-item-name">.. (Parent Directory)</span>
                    </a>
                </li>
            `;
        }

        if (items.length === 0 && currentPath !== "") {
            gridItemsHtml += `<li class="qb-empty-folder-message">This folder is empty.</li>`;
        } else if (items.length === 0 && currentPath === "") {
             gridItemsHtml += `<li class="qb-empty-folder-message">The root directory is empty or unreadable.</li>`;
        }

        items.forEach(item => {
            const isDir = item.type === 'folder';
            let iconClass = '';
            let itemTypeClass = ''; // New class for folder/file type for CSS targeting
            let actionsHtml = '';

            if (isDir) {
                iconClass = 'dashicons dashicons-category qb-icon-folder';
                itemTypeClass = 'qb-type-folder'; // Add this class for CSS to hide size
            } else {
                // Determine icon based on file type
                switch (item.type) {
                    case 'image':
                        iconClass = 'dashicons dashicons-format-image qb-icon-file type-image';
                        break;
                    case 'pdf':
                        iconClass = 'dashicons dashicons-media-document qb-icon-file type-pdf';
                        break;
                    case 'code':
                        iconClass = 'dashicons dashicons-media-code qb-icon-file type-code';
                        // ONLY add edit button for 'code' type files
                        actionsHtml += `
                            <button class="qb-button qb-button-edit qb-edit-btn" data-path="${item.relative_path}" data-name="${item.name}">
                                EDIT
                            </button>
                        `;
                        break;
                    case 'archive':
                        iconClass = 'dashicons dashicons-media-archive qb-icon-file type-archive';
                        break;
                    default:
                        iconClass = 'dashicons dashicons-media-default qb-icon-file type-other';
                }
                itemTypeClass = 'qb-type-file'; // Add this class for CSS
            }

            // Delete button is always present and positioned absolutely
            actionsHtml += `
                <button class="qb-button qb-button-delete qb-delete-btn" data-path="${item.relative_path}" data-name="${item.name}">
                    DELETE
                </button>
            `;

            gridItemsHtml += `
                <li class="qb-grid-item ${itemTypeClass}" ${isDir ? `data-path="${item.relative_path}"` : ''}>
                    <div class="qb-item-icon-wrapper">
                        <span class="${iconClass}"></span>
                    </div>
                    <span class="qb-item-name">${item.name}</span>
                    <div class="qb-item-details">
                        <span>Size: ${item.size}</span>
                        <span>Modified: ${item.modified}</span>
                    </div>
                    <div class="qb-item-actions">
                        ${actionsHtml}
                    </div>
                </li>
            `;
        });

        // If there are no items (and not a parent directory link), display empty message
        if (items.length === 0 && currentPath !== "") {
            qbFileListContainer.innerHTML = `<ul class="qb-file-grid"><li class="qb-empty-folder-message">This folder is empty.</li></ul>`;
        } else if (items.length === 0 && currentPath === "") {
            qbFileListContainer.innerHTML = `<ul class="qb-file-grid"><li class="qb-empty-folder-message">The root directory is empty or unreadable.</li></ul>`;
        } else {
            qbFileListContainer.innerHTML = `<ul class="qb-file-grid">${gridItemsHtml}</ul>`;
        }

        addEventListenersToGridItems(); // Call the updated function to attach listeners
    }

    /**
     * Adds event listeners to dynamically created grid elements (items, buttons).
     */
    function addEventListenersToGridItems() {
        // Folder navigation links (now on the whole grid item if it's a folder)
        // Ensure that the click listener is on the <li> for folders, or the <a> for parent directory
        qbFileListContainer.querySelectorAll('.qb-grid-item.qb-type-folder, .qb-parent-dir-link').forEach(element => {
            element.addEventListener('click', (e) => {
                e.preventDefault();
                // For .qb-grid-item.qb-type-folder, data-path is on the <li> itself.
                // For .qb-parent-dir-link, data-path is on the <a> itself.
                const targetPath = e.currentTarget.dataset.path;
                if (targetPath !== undefined) {
                    loadDirectory(targetPath);
                }
            });
        });

        // Delete buttons
        qbFileListContainer.querySelectorAll('.qb-delete-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation(); // Prevent click from bubbling up to parent li/div
                const clickedButton = e.target.closest('.qb-delete-btn');
                if (clickedButton) {
                    itemToDeletePath = clickedButton.dataset.path;
                    const itemName = clickedButton.dataset.name;

                    if (!itemToDeletePath) {
                        showNotification("Error: Delete button has no data-path attribute.", "error");
                        return;
                    }
                    if (!itemName) {
                        showNotification("Error: Delete button has no data-name attribute.", "error");
                        return;
                    }

                    showConfirmModal(itemName);
                } else {
                    showNotification("Error: Clicked element is not a delete button.", "error");
                }
            });
        });

        // Edit buttons
        qbFileListContainer.querySelectorAll('.qb-edit-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation(); // Prevent click from bubbling up to parent li/div
                const filePath = e.target.dataset.path || e.target.closest('button').dataset.path;
                const fileName = e.target.dataset.name || e.target.closest('button').dataset.name;
                loadAndEditFile(filePath, fileName);
            });
        });
    }

    /**
     * Makes an AJAX request.
     * @param {string} action The WordPress AJAX action.
     * @param {FormData|object} data The data to send.
     * @param {boolean} isFormData True if data is FormData.
     * @returns {Promise<object>} A promise that resolves with the JSON response.
     */
    async function ajaxRequest(action, data, isFormData = false) {
        const formData = isFormData ? data : new FormData();
        if (!isFormData) {
            for (const key in data) {
                formData.append(key, data[key]);
            }
        }
        formData.append('action', action);
        formData.append('nonce', qbFileManager.nonce);

        try {
            const response = await fetch(qbFileManager.ajax_url, {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status}. Server response snippet: ${errorText.substring(0, 200)}...`);
            }

            const result = await response.json();
            if (result.success) {
                return result;
            } else {
                throw new Error(result.data.message || 'An unknown error occurred.');
            }
        } catch (error) {
            showNotification(`AJAX Error: ${error.message}`, 'error');
            throw error;
        }
    }

    // --- Core File Manager Operations ---

    /**
     * Loads and displays the contents of a directory.
     * @param {string} path The relative path to the directory.
     */
    async function loadDirectory(path) {
        showLoading();
        currentPath = path;
        renderBreadcrumbs(); // Update breadcrumbs immediately

        try {
            const response = await ajaxRequest('qb_fm_list_items', { dir: path });
            renderFileList(response.data.items);
        } catch (error) {
            showNotification(`Failed to load directory: ${error.message}`, 'error');
            qbFileListContainer.innerHTML = `<ul class="qb-file-grid"><li class="qb-empty-folder-message">Error loading directory: ${error.message}</li></ul>`;
        }
    }

    /**
     * Handles creating a new folder.
     */
    qbCreateFolderForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const folderNameInput = this.querySelector('input[name="new_folder_name"]');
        const folderName = folderNameInput.value.trim();

        if (!folderName) {
            showNotification("Folder name cannot be empty.", "warning");
            return;
        }

        try {
            const response = await ajaxRequest('qb_fm_create_folder', {
                dir: currentPath,
                name: folderName
            });
            showNotification(response.data.message, 'success');
            folderNameInput.value = ''; // Clear input
            loadDirectory(currentPath); // Refresh directory
        } catch (error) {
            showNotification(`Failed to create folder: ${error.message}`, 'error');
        }
    });

    /**
     * Handles creating a new file.
     */
    qbCreateFileForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const fileNameInput = this.querySelector('input[name="new_file_name"]');
        const fileName = fileNameInput.value.trim();

        if (!fileName) {
            showNotification("File name cannot be empty.", "warning");
            return;
        }

        try {
            const response = await ajaxRequest('qb_fm_create_file', {
                dir: currentPath,
                name: fileName
            });
            showNotification(response.data.message, 'success');
            fileNameInput.value = ''; // Clear input
            loadDirectory(currentPath); // Refresh directory
        } catch (error) {
            showNotification(`Failed to create file: ${error.message}`, 'error');
        }
    });

    /**
     * Handles file uploads.
     */
    qbUploadFileForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const fileInput = this.querySelector('input[name="file_upload"]');
        if (fileInput.files.length === 0) {
            showNotification("Please select a file to upload.", "warning");
            return;
        }

        const formData = new FormData();
        formData.append('file_upload', fileInput.files[0]);
        formData.append('dir', currentPath);

        try {
            const response = await ajaxRequest('qb_fm_upload_file', formData, true);
            showNotification(response.data.message, 'success');
            fileInput.value = ''; // Clear input
            loadDirectory(currentPath); // Refresh directory
        } catch (error) {
            showNotification(`Failed to upload file: ${error.message}`, 'error');
        }
    });

    /**
     * Handles deleting an item (file or folder).
     */
    confirmDeleteBtn.addEventListener("click", async function() {
        const pathToDelete = itemToDeletePath; // Capture it here

        hideConfirmModal(); // Now hide the modal and clear itemToDeletePath

        if (!pathToDelete) {
            showNotification("No item selected for deletion.", "error");
            return;
        }

        try {
            const response = await ajaxRequest('qb_fm_delete_item', { path: pathToDelete });
            showNotification(response.data.message, 'success');
            loadDirectory(currentPath); // Refresh directory
        } catch (error) {
            showNotification(`Failed to delete: ${error.message}`, 'error');
        }
    });

    cancelDeleteBtn.addEventListener("click", hideConfirmModal); // Cancel button hides modal

    // Close modal if clicking outside
    confirmModal.addEventListener("click", function(e) {
        if (e.target === confirmModal) {
            hideConfirmModal();
        }
    });

    /**
     * Loads file content into the editor.
     * @param {string} filePath The relative path to the file.
     * @param {string} fileName The name of the file.
     */
    async function loadAndEditFile(filePath, fileName) {
        showEditor(); // Show editor area, hide file list
        qbEditingFilename.textContent = fileName;
        qbEditorTextarea.value = 'Loading file content...'; // Show loading message

        try {
            const response = await ajaxRequest('qb_fm_get_file_content', { path: filePath });
            qbEditorTextarea.value = response.data.content;
            qbEditorTextarea.dataset.filePath = filePath; // Store path for saving
        } catch (error) {
            showNotification(`Failed to load file content: ${error.message}`, 'error');
            qbEditorTextarea.value = `Error: ${error.message}`;
        }
    }

    /**
     * Handles saving file content from the editor.
     */
    qbSaveFileBtn.addEventListener('click', async function() {
        const filePath = qbEditorTextarea.dataset.filePath;
        const fileContent = qbEditorTextarea.value;

        if (!filePath) {
            showNotification("No file selected for saving.", "error");
            return;
        }

        try {
            const response = await ajaxRequest('qb_fm_save_file_content', {
                path: filePath,
                content: fileContent
            });
            showNotification(response.data.message, 'success');
            hideEditor(); // Go back to file list
            loadDirectory(currentPath); // Refresh list (though content doesn't change, good practice)
        } catch (error) {
            showNotification(`Failed to save file: ${error.message}`, 'error');
        }
    });

    /**
     * Handles canceling file editing.
     */
    qbCancelEditBtn.addEventListener('click', function() {
        hideEditor();
        loadDirectory(currentPath); // Re-load current directory
    });


    // --- Initial Load ---
    loadDirectory(""); // Load the root directory on page load
});
