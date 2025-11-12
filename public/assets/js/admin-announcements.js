import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js'; 
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
    const csrfTokenInput = managementContainer.querySelector('input[name="_csrf_token"]'); 
    const userRole = window.APP_CONFIG.userRole;
    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        announcementIdInput.value = '';
        formTitle.textContent = 'Neue Ankündigung erstellen';
        cancelBtn.style.display = 'none';
        if (targetClassSelect) targetClassSelect.disabled = false;
        if (targetCheckboxes) targetCheckboxes.forEach(cb => { cb.checked = false; cb.disabled = false; });
        if (targetErrorHint) targetErrorHint.style.display = 'none';
        if (userRole === 'lehrer' && targetClassSelect) {
             targetClassSelect.required = true;
        }
        if (currentAttachmentInfo) currentAttachmentInfo.style.display = 'none';
        if (currentAttachmentLink) {
            currentAttachmentLink.href = '#';
            currentAttachmentLink.textContent = '';
        }
        if (removeAttachmentCheckbox) removeAttachmentCheckbox.checked = false;
        if (attachmentInput) attachmentInput.value = ''; 
    };
    const handleTargetSelectionChange = (event) => {
        if (!targetClassSelect || !targetCheckboxes.length) return; 
        const isCheckboxEvent = event && event.target.type === 'checkbox';
        const isSelectEvent = event && event.target === targetClassSelect;
        let checkedCount = 0;
        targetCheckboxes.forEach(cb => { if (cb.checked) checkedCount++; });
        if (isCheckboxEvent) {
             if (event.target.checked) {
                 targetCheckboxes.forEach(cb => {
                     if (cb !== event.target) cb.checked = false;
                 });
                 targetClassSelect.value = ''; 
                 targetClassSelect.disabled = true;
                 checkedCount = 1; 
             } else {
                 if (checkedCount === 0) {
                     targetClassSelect.disabled = false;
                 }
             }
        } else if (isSelectEvent) {
             if (targetClassSelect.value !== '') {
                 targetCheckboxes.forEach(cb => {
                     cb.checked = false;
                     cb.disabled = true; 
                 });
                 checkedCount = 0;
             } else {
                 targetCheckboxes.forEach(cb => cb.disabled = false);
             }
        } else {
             if (checkedCount > 0) {
                 targetClassSelect.value = '';
                 targetClassSelect.disabled = true;
                 if (checkedCount > 1) { 
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
        if (targetErrorHint) {
            targetErrorHint.style.display = (targetClassSelect.value === '' && checkedCount === 0) ? 'block' : 'none';
        }
    };
    if (targetClassSelect && targetCheckboxes.length) {
        targetClassSelect.addEventListener('change', handleTargetSelectionChange);
        targetCheckboxes.forEach(cb => cb.addEventListener('change', handleTargetSelectionChange));
        handleTargetSelectionChange(); 
    }
    const renderTable = (announcements) => {
         console.log("Rendering table (client-side update - currently basic)");
    };
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        if (userRole === 'admin' || userRole === 'planer') {
            const classSelected = formData.get('target_class_id') && formData.get('target_class_id') !== '';
            const globalChecked = formData.get('target_global') === '1';
            const teacherChecked = formData.get('target_teacher') === '1';
            const planerChecked = formData.get('target_planer') === '1';
            const checkedCount = [globalChecked, teacherChecked, planerChecked].filter(Boolean).length;
            if (!classSelected && checkedCount !== 1) {
                if (targetErrorHint) targetErrorHint.style.display = 'block';
                showToast('Bitte eine Klasse ODER genau eine Zielgruppe (Global, Lehrer, Planer) auswählen.', 'error');
                return; 
            } else {
                 if (targetErrorHint) targetErrorHint.style.display = 'none';
            }
             if (!globalChecked) formData.delete('target_global');
             if (!teacherChecked) formData.delete('target_teacher');
             if (!planerChecked) formData.delete('target_planer');
             if (checkedCount > 0) formData.delete('target_class_id'); 
        }
        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/announcements/create`
            : `${window.APP_CONFIG.baseUrl}/api/announcements/update`; 
         if (!formData.has('_csrf_token') && csrfTokenInput) {
             formData.append('_csrf_token', csrfTokenInput.value);
         }
        try {
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                showToast(response.message, 'success');
                resetForm();
                window.location.reload();
            }
        } catch(error) {
             console.error("Form submission error:", error);
        }
    });
    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;
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
             showToast('Bearbeiten ist derzeit nicht implementiert.', 'info');
        }
        if (target.classList.contains('delete-announcement')) {
            const canModify = in_array(userRole, ['admin', 'planer']) || (userRole === 'lehrer' && announcementData.user_id == window.APP_CONFIG.userId); 
Dienstag
             if (!canModify) return;
            if (await showConfirm('Ankündigung löschen', `Sind Sie sicher, dass Sie "${escapeHtml(announcementData.title)}" löschen möchten? Zugehörige Dateien werden ebenfalls entfernt.`)) {
                const deleteFormData = new FormData();
                deleteFormData.append('announcement_id', id);
Dienstag
                 if (!deleteFormData.has('_csrf_token') && csrfTokenInput) {
                     deleteFormData.append('_csrf_token', csrfTokenInput.value);
                 }
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/announcements/delete`, { method: 'POST', body: deleteFormData });
Dienstag
                    if(response.success) {
                        showToast(response.message, 'success');
                        row.remove(); 
          tuesday
                        if (tableBody.rows.length === 0) {
                             window.location.reload(); 
A
                        }
                    }
d
                } catch(error) {
                     console.error("Delete error:", error);
                }
            }
        }
    });
    cancelBtn.addEventListener('click', resetForm);
    handleTargetSelectionChange();
}
function in_array(needle, haystack) {
    return haystack.indexOf(needle) > -1;
}