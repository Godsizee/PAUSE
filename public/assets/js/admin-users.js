/* public/assets/js/admin-users.js */
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

export function initializeAdminUsers() {
    const userManagement = document.getElementById('user-management');
    if (!userManagement) return;

    const tableBody = userManagement.querySelector('#users-table tbody');
    const form = userManagement.querySelector('#user-form');
    const formTitle = userManagement.querySelector('#user-form-title');
    const userIdInput = userManagement.querySelector('#user_id');
    const passwordInput = userManagement.querySelector('#password');
    const cancelBtn = userManagement.querySelector('#cancel-edit-user');
    const roleSelect = userManagement.querySelector('#role');
    const classSelectContainer = userManagement.querySelector('#class-select-container');
    const classSelect = userManagement.querySelector('#class_id');
    const teacherSelectContainer = userManagement.querySelector('#teacher-select-container');
    const teacherSelect = userManagement.querySelector('#teacher_id');
    const communityBanContainer = userManagement.querySelector('#community-ban-container');
    const communityBanCheckbox = userManagement.querySelector('#is_community_banned');

    // Import-Formular Elemente
    const importForm = document.getElementById('user-import-form');
    const importButton = document.getElementById('user-import-btn');
    const importResultsContainer = document.getElementById('import-results-container');
    const importResultsPre = document.getElementById('import-results');

    let allData = {}; // Speichert Rollen, Klassen, Lehrer

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        userIdInput.value = '';
        formTitle.textContent = 'Neuen Benutzer anlegen';
        passwordInput.setAttribute('required', 'required');
        passwordInput.parentElement.querySelector('label').textContent = 'Passwort*';
        cancelBtn.style.display = 'none';
        toggleRoleSpecificFields();
    };

    const toggleRoleSpecificFields = () => {
        const selectedRole = roleSelect.value;
        classSelectContainer.style.display = selectedRole === 'schueler' ? 'block' : 'none';
        teacherSelectContainer.style.display = selectedRole === 'lehrer' ? 'block' : 'none';
        communityBanContainer.style.display = selectedRole === 'schueler' ? 'block' : 'none';

        if (selectedRole !== 'schueler') {
            classSelect.value = '';
            if(communityBanCheckbox) communityBanCheckbox.checked = false;
        }
        if (selectedRole !== 'lehrer') {
            teacherSelect.value = '';
        }
    };

    const renderTable = (users) => {
        const currentAdminId = window.APP_CONFIG.userId;
        tableBody.innerHTML = users.length > 0 ? users.map(user => {
            let details = '';
            if (user.role === 'schueler' && user.class_name) {
                details = `Klasse: ${escapeHtml(user.class_name)}`;
            } else if (user.role === 'lehrer' && user.teacher_name) {
                details = `Lehrerprofil: ${escapeHtml(user.teacher_name)}`;
            }

            let communityStatus = '-';
            if (user.role === 'schueler') {
                communityStatus = user.is_community_banned == 1
                    ? '<span style="color: var(--color-danger); font-weight: 600;">Gesperrt</span>'
                    : '<span style="color: var(--color-success);">Aktiv</span>';
            }

            const canImpersonate = (user.user_id != currentAdminId);
            const impersonateButton = canImpersonate
                ? `<button class="btn btn-secondary btn-small impersonate-user-btn" data-id="${user.user_id}" data-username="${escapeHtml(user.username)}" data-role="${escapeHtml(user.role)}" title="Anmelden als ${escapeHtml(user.username)}">
                       Anmelden als
                   </button>`
                : ``;

            // data-label Attribute hinzugefügt
            return `
                <tr data-id="${user.user_id}" data-user='${JSON.stringify(user)}'>
                    <td data-label="ID">${user.user_id}</td>
                    <td data-label="Name"><strong>${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</strong><br><small>${escapeHtml(user.username)}</small></td>
                    <td data-label="Rolle">${user.role}</td>
                    <td data-label="Details">${details}</td>
                    <td data-label="Community">${communityStatus}</td>
                    <td class="actions" data-label="Aktionen">
                        ${impersonateButton}
                        <button class="btn btn-warning btn-small edit-user">Bearbeiten</button>
                        <button class="btn btn-danger btn-small delete-user">Löschen</button>
                    </td>
                </tr>
            `;
        }).join('') : '<tr><td colspan="6" style="text-align: center; padding: 20px;">Keine Benutzer gefunden.</td></tr>';
    };

    const populateSelects = (data) => {
        allData = data;
        roleSelect.innerHTML = data.roles.map(r => `<option value="${r}">${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('');
        classSelect.innerHTML = '<option value="">Keine Klasse</option>' + data.classes.map(c => `<option value="${c.class_id}">${escapeHtml(c.class_name)}</option>`).join('');
        teacherSelect.innerHTML = '<option value="">Kein Lehrerprofil</option>' + data.teachers.map(t => `<option value="${t.teacher_id}">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)} (${escapeHtml(t.teacher_shortcut)})</option>`).join('');
    };

    const loadUsers = async () => {
        tableBody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px;"><div class="loading-spinner"></div></td></tr>';
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users`);
            if (response.success) {
                renderTable(response.data.users);
                populateSelects(response.data);
                toggleRoleSpecificFields();
            }
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="6" class="message error">Fehler beim Laden der Benutzer.</td></tr>';
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        
        const formData = new FormData(form);
        let data = Object.fromEntries(formData.entries());

        data.is_community_banned = data.is_community_banned ? 1 : 0;
        
        if (data.role !== 'schueler') {
            data.class_id = null;
            data.is_community_banned = 0;
        }
        if (data.role !== 'lehrer') {
            data.teacher_id = null;
        }
        
        if (mode === 'update' && !data.password) {
            delete data.password;
        } else if (mode === 'create' && !data.password) {
             showToast("Passwort ist beim Erstellen erforderlich.", 'error');
             return;
        }

        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/users/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/users/update`;

        try {
            const response = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadUsers();
            }
        } catch (error) { }
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;
        let user;
        try {
            user = JSON.parse(row.dataset.user);
        } catch(e) {
            console.error("Could not parse user data from row:", row.dataset.user);
            return;
        }

        if (target.classList.contains('edit-user')) {
            form.dataset.mode = 'update';
            formTitle.textContent = 'Benutzer bearbeiten';
            cancelBtn.style.display = 'inline-block';
            passwordInput.removeAttribute('required');
            passwordInput.parentElement.querySelector('label').textContent = 'Neues Passwort';
            
            userIdInput.value = user.user_id;
            form.querySelector('#username').value = user.username;
            form.querySelector('#email').value = user.email;
            form.querySelector('#first_name').value = user.first_name;
            form.querySelector('#last_name').value = user.last_name;
            form.querySelector('#birth_date').value = user.birth_date;
            roleSelect.value = user.role;

            toggleRoleSpecificFields(); // WICHTIG: Zuerst aufrufen, um die Selects anzuzeigen

            if (allData.classes) classSelect.value = user.class_id || '';
            if (allData.teachers) teacherSelect.value = user.teacher_id || '';
            if (communityBanCheckbox) {
                communityBanCheckbox.checked = (user.is_community_banned == 1);
            }
            
            form.querySelector('#username').focus();
        }

        if (target.classList.contains('delete-user')) {
            if (await showConfirm('Benutzer löschen', `Sind Sie sicher, dass Sie ${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)} löschen möchten?`)) {
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users/delete`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ user_id: id })
                    });
                    if (response.success) {
                        showToast(response.message, 'success');
                        loadUsers();
                    }
                } catch (error) { }
            }
        }

        if (target.classList.contains('impersonate-user-btn')) {
            const username = target.dataset.username;
            const role = target.dataset.role;
            await handleImpersonateUser(id, username, role);
        }
    });
    
    if (importForm) {
        importForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            importButton.disabled = true;
            importButton.textContent = 'Importiere...';
            importResultsContainer.style.display = 'none';
            importResultsPre.textContent = '';

            const formData = new FormData(importForm);
            
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users/import`, {
                    method: 'POST',
                    body: formData
                    // CSRF-Token wird vom apiFetch-Handler für FormData hinzugefügt
                });
                
                if (response.success && response.data) {
                    const { successCount, errors } = response.data;
                    let resultText = `Erfolgreich importiert: ${successCount}\n`;
                    resultText += `Fehlgeschlagen/Übersprungen: ${errors.length}\n`;
                    if (errors.length > 0) {
                        resultText += "\nFehlerdetails:\n" + errors.join('\n');
                    }
                    importResultsPre.textContent = resultText;
                    importResultsContainer.style.display = 'block';
                    showToast(`Import abgeschlossen: ${successCount} erfolgreich, ${errors.length} Fehler.`, 'success');
                    loadUsers(); // Benutzertabelle neu laden
                }
            } catch (error) {
                importResultsPre.textContent = `Fehler beim Import:\n${error.message}`;
                importResultsContainer.style.display = 'block';
            } finally {
                importButton.disabled = false;
                importButton.textContent = 'Import starten';
                importForm.reset();
            }
        });
    }

    roleSelect.addEventListener('change', toggleRoleSpecificFields);
    cancelBtn.addEventListener('click', resetForm);
    loadUsers();
}

async function handleImpersonateUser(userId, username, role) {
    if (!await showConfirm(
        'Als Benutzer anmelden?',
        `Möchten Sie sich wirklich als <strong>${escapeHtml(username)}</strong> (Rolle: ${escapeHtml(role)}) anmelden? Sie werden vom Admin-Konto abgemeldet.`
    )) {
        return;
    }

    try {
        const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users/impersonate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ user_id: userId })
        });

        if (response.success && response.redirectUrl) {
            showToast(`Sie sind jetzt als ${escapeHtml(username)} angemeldet.`, 'success');
            window.location.href = response.redirectUrl;
        }
    } catch (error) {
        console.error('Impersonation failed:', error);
    }
}