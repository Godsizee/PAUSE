import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js'; // Import notification functions

/**
 * Escapes HTML special characters to prevent XSS.
 * @param {string} unsafe - The string to escape.
 * @returns {string} The escaped string.
 */
function escapeHtml(unsafe) {
    if (unsafe === null || typeof unsafe === 'undefined') return '';
  	 return String(unsafe)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
}


export function initializeAdminAnnouncements() {
    const managementContainer = document.getElementById('announcements-management');
    if (!managementContainer) return;

    const tableBody = managementContainer.querySelector('#announcements-table tbody');
    const form = managementContainer.querySelector('#announcement-form');
    const formTitle = managementContainer.querySelector('#announcement-form-title');
    const announcementIdInput = managementContainer.querySelector('#announcement_id');
    const targetClassSelect = managementContainer.querySelector('#target_class_id');
    const targetCheckboxes = managementContainer.querySelectorAll('.checkbox-group input[type="checkbox"]');
    const targetGlobalCheckbox = managementContainer.querySelector('#target_global');
    const targetTeacherCheckbox = managementContainer.querySelector('#target_teacher');
    const targetPlanerCheckbox = managementContainer.querySelector('#target_planer');
    const targetErrorHint = managementContainer.querySelector('#target-error');
    const cancelBtn = managementContainer.querySelector('#cancel-edit-announcement');
    const attachmentInput = managementContainer.querySelector('#attachment');
    const currentAttachmentInfo = managementContainer.querySelector('#current-attachment-info');
    const currentAttachmentLink = managementContainer.querySelector('#current-attachment-link');
    const removeAttachmentCheckbox = managementContainer.querySelector('#remove_attachment');
    const csrfTokenInput = managementContainer.querySelector('input[name="_csrf_token"]'); // Corrected name

    const userRole = window.APP_CONFIG.userRole;

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        announcementIdInput.value = '';
        formTitle.textContent = 'Neue Ankündigung erstellen';
        cancelBtn.style.display = 'none';

        // Reset targeting fields specifically for admin/planer
        if (targetClassSelect) targetClassSelect.disabled = false;
        if (targetCheckboxes) targetCheckboxes.forEach(cb => { cb.checked = false; cb.disabled = false; });
        if (targetErrorHint) targetErrorHint.style.display = 'none';

        // Lehrer view specific reset
        if (userRole === 'lehrer' && targetClassSelect) {
             targetClassSelect.required = true;
        }

        // Reset attachment fields
        if (currentAttachmentInfo) currentAttachmentInfo.style.display = 'none';
        if (currentAttachmentLink) {
            currentAttachmentLink.href = '#';
            currentAttachmentLink.textContent = '';
        }
        if (removeAttachmentCheckbox) removeAttachmentCheckbox.checked = false;
        if (attachmentInput) attachmentInput.value = ''; // Clear file input
    };

    // --- Targeting Logic ---
    const handleTargetSelectionChange = (event) => {
        if (!targetClassSelect || !targetCheckboxes.length) return; // Only run for admin/planer

        const isCheckboxEvent = event && event.target.type === 'checkbox';
        const isSelectEvent = event && event.target === targetClassSelect;

        let checkedCount = 0;
        targetCheckboxes.forEach(cb => { if (cb.checked) checkedCount++; });

        if (isCheckboxEvent) {
             // If a checkbox is checked, ensure only one is checked and clear/disable class select
             if (event.target.checked) {
                 targetCheckboxes.forEach(cb => {
                     if (cb !== event.target) cb.checked = false;
                 });
                 targetClassSelect.value = ''; // Clear class selection
                 targetClassSelect.disabled = true;
                 checkedCount = 1; // We just checked one
             } else {
                 // If the last checkbox was unchecked, re-enable class select
                 if (checkedCount === 0) {
                     targetClassSelect.disabled = false;
                 }
             }
        } else if (isSelectEvent) {
             // If a class is selected, uncheck and disable all checkboxes
             if (targetClassSelect.value !== '') {
                 targetCheckboxes.forEach(cb => {
                     cb.checked = false;
                     cb.disabled = true; // Disable checkboxes if class is chosen
                 });
                 checkedCount = 0;
             } else {
                 // If '-- Klasse wählen --' is selected, re-enable checkboxes
                 targetCheckboxes.forEach(cb => cb.disabled = false);
             }
        } else {
             // Initial load or reset: Sync state
             if (checkedCount > 0) {
                 targetClassSelect.value = '';
                 targetClassSelect.disabled = true;
                 if (checkedCount > 1) { // Ensure only one is checked on load (shouldn't happen)
                     targetCheckboxes[0].checked = true;
                     for(let i=1; i<targetCheckboxes.length; i++) targetCheckboxes[i].checked = false;
                 }
             } else if (targetClassSelect.value !== '') {
                 targetCheckboxes.forEach(cb => { cb.checked = false; cb.disabled = true; });
             } else {
                 targetClassSelect.disabled = false;
                 targetCheckboxes.forEach(cb => cb.disabled = false);
             }
        }
        // Validation Hint (Show if nothing is selected)
        if (targetErrorHint) {
            targetErrorHint.style.display = (targetClassSelect.value === '' && checkedCount === 0) ? 'block' : 'none';
        }

    };

    if (targetClassSelect && targetCheckboxes.length) {
        targetClassSelect.addEventListener('change', handleTargetSelectionChange);
        targetCheckboxes.forEach(cb => cb.addEventListener('change', handleTargetSelectionChange));
        handleTargetSelectionChange(); // Initial check
    }
     // --- End Targeting Logic ---


    const renderTable = (announcements) => {
         // This function needs to be updated based on the new table structure in the PHP file
         // It's less critical now as the initial render happens server-side.
         // You might want to update rows dynamically after create/update/delete instead of full re-render.
         // For now, leave it empty or log a message, as initial load is SSR.
         console.log("Rendering table (client-side update - currently basic)");
         // Basic example if needed later:
         /*
         tableBody.innerHTML = announcements.length > 0 ? announcements.map(item => {
              // ... generate row HTML based on item data and new columns ...
              return `<tr>...</tr>`;
         }).join('') : `<tr><td colspan="6">Keine Ankündigungen gefunden.</td></tr>`;
         */
    };


    // Load function might not be needed if initial load is always server-side
    // const loadAnnouncements = async () => { ... };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);

        // --- Target Validation (for Admin/Planer) ---
        if (userRole === 'admin' || userRole === 'planer') {
            const classSelected = formData.get('target_class_id') && formData.get('target_class_id') !== '';
            const globalChecked = formData.get('target_global') === '1';
            const teacherChecked = formData.get('target_teacher') === '1';
            const planerChecked = formData.get('target_planer') === '1';
            const checkedCount = [globalChecked, teacherChecked, planerChecked].filter(Boolean).length;

            if (!classSelected && checkedCount !== 1) {
                if (targetErrorHint) targetErrorHint.style.display = 'block';
                // Use imported function directly
                showToast('Bitte eine Klasse ODER genau eine Zielgruppe (Global, Lehrer, Planer) auswählen.', 'error');
                return; // Stop submission
            } else {
                 if (targetErrorHint) targetErrorHint.style.display = 'none';
            }
             // Clear unchecked checkboxes from form data if needed (usually not necessary)
             if (!globalChecked) formData.delete('target_global');
             if (!teacherChecked) formData.delete('target_teacher');
             if (!planerChecked) formData.delete('target_planer');
             if (checkedCount > 0) formData.delete('target_class_id'); // Don't send class_id if a checkbox is checked
        }
        // --- End Target Validation ---

        // Lehrer specific handling (already done via hidden input + required on select)

        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/announcements/create`
            : `${window.APP_CONFIG.baseUrl}/api/announcements/update`; // Update endpoint needs implementation

         // Add CSRF token manually if not already added by apiFetch for FormData
         if (!formData.has('_csrf_token') && csrfTokenInput) {
             formData.append('_csrf_token', csrfTokenInput.value);
         }


        try {
             // apiFetch should handle FormData correctly including CSRF in body
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                // Use imported function directly
                showToast(response.message, 'success');
                resetForm();
                // Instead of loadAnnouncements(), maybe just add/update the row in the table?
                // For simplicity, reload the page to see changes
                window.location.reload();
            }
            // Error handled by apiFetch
        } catch(error) {
             console.error("Form submission error:", error);
             // Maybe refresh CSRF token if it failed due to token mismatch
        }
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;

        const id = row.dataset.id;
        // Get data from data attributes
        const announcementData = {
            announcement_id: id,
            title: row.dataset.title,
            content: row.dataset.content,
            is_global: row.dataset.isGlobal === '1',
            class_id: row.dataset.classId || null,
            file_path: row.dataset.filePath || null,
            user_id: row.dataset.userId
        };

        if (target.classList.contains('edit-announcement')) {
            // Edit is disabled for now, but logic would go here
             // Use imported function directly
             showToast('Bearbeiten ist derzeit nicht implementiert.', 'info');

             /* // Logic if Edit were enabled:
             form.dataset.mode = 'update';
             formTitle.textContent = 'Ankündigung bearbeiten';
             cancelBtn.style.display = 'inline-block';

             announcementIdInput.value = announcementData.announcement_id;
             form.querySelector('#title').value = announcementData.title;
             form.querySelector('#content').value = announcementData.content;

            // Reset and set targeting based on loaded data (for admin/planer)
             if (userRole === 'admin' || userRole === 'planer') {
                 targetClassSelect.value = '';
                 targetCheckboxes.forEach(cb => cb.checked = false);

                 if (announcementData.is_global) {
                     // We can't know if it was originally 'lehrer' or 'planer' vs 'all'
                     targetGlobalCheckbox.checked = true;
                 } else if (announcementData.class_id) {
                     targetClassSelect.value = announcementData.class_id;
                 }
                 handleTargetSelectionChange(); // Update UI based on loaded data
             } else if (userRole === 'lehrer') {
                  targetClassSelect.value = announcementData.class_id || '';
Dienstag
             }


            // Handle attachment display
            if (attachmentInput) {
                 attachmentInput.value = '';
Dienstag
                 if (announcementData.file_path) {
                     const fileUrl = `${window.APP_CONFIG.baseUrl}/${announcementData.file_path.startsWith('/') ? announcementData.file_path.substring(1) : announcementData.file_path}`;
                     currentAttachmentInfo.style.display = 'block';
                     currentAttachmentLink.href = fileUrl;
                     currentAttachmentLink.textContent = announcementData.file_path.split('/').pop(); // Show filename
                tuesday
                     removeAttachmentCheckbox.checked = false;
                 } else {
                     currentAttachmentInfo.style.display = 'none';
                 }
            }


             form.querySelector('#title').focus();
             */

        }

        if (target.classList.contains('delete-announcement')) {
            // Permission check already done in PHP for button visibility, but double-check here if needed
            const canModify = in_array(userRole, ['admin', 'planer']) || (userRole === 'lehrer' && announcementData.user_id == window.APP_CONFIG.userId); // Assuming userId is available globally
Dienstag
             if (!canModify) return;

            // Use imported function directly
            if (await showConfirm('Ankündigung löschen', `Sind Sie sicher, dass Sie "${escapeHtml(announcementData.title)}" löschen möchten? Zugehörige Dateien werden ebenfalls entfernt.`)) {
                const deleteFormData = new FormData();
                deleteFormData.append('announcement_id', id);
Dienstag
                 // Add CSRF token manually if not already added by apiFetch for FormData
                 if (!deleteFormData.has('_csrf_token') && csrfTokenInput) {
                     deleteFormData.append('_csrf_token', csrfTokenInput.value);
                 }


                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/announcements/delete`, { method: 'POST', body: deleteFormData });
Dienstag
                    if(response.success) {
                        // Use imported function directly
                        showToast(response.message, 'success');
                        row.remove(); // Remove row directly
          tuesday
                         // Optionally check if table is now empty
                        if (tableBody.rows.length === 0) {
                            // Display a message or reload
                             window.location.reload(); // Reload if empty for simplicity
A
                        }
                    }
                     // Error handled by apiFetch
d
                } catch(error) {
                     console.error("Delete error:", error);
                     // Maybe refresh CSRF token
                }
            }
        }
    });

    cancelBtn.addEventListener('click', resetForm);

    // Initial load is handled by PHP SSR, no need for loadAnnouncements() on init
    // Initial setup for targeting fields
    handleTargetSelectionChange();
}

// Helper function to check if an element is in an array
function in_array(needle, haystack) {
    return haystack.indexOf(needle) > -1;
}

// Ensure APP_CONFIG includes userId if needed for client-side permission checks
// Example: Add this to pages/partials/header.php within the script tag
// userId: <?php echo $_SESSION['user_id'] ?? 'null'; ?>,
