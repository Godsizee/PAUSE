// public/assets/js/admin-users.js
// KORRIGIERT: Fehlendes 'export' in Zeile 4 hinzugefügt.

import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js'; // Import notification functions

export function initializeAdminUsers() { // <-- DIESES 'export' HAT GEFEHLT
    const userManagement = document.getElementById('user-management');
    if (!userManagement) return;

    const tableBody = userManagement.querySelector('#users-table tbody');
    const form = userManagement.querySelector('#user-form');
    const formTitle = userManagement.querySelector('#user-form-title');
    const userIdInput = userManagement.querySelector('#user_id');
    const passwordInput = userManagement.querySelector('#password');
    const cancelBtn = userManagement.querySelector('#cancel-edit-user');

    // Role specific fields
    const roleSelect = userManagement.querySelector('#role');
    const classSelectContainer = userManagement.querySelector('#class-select-container');
    const classSelect = userManagement.querySelector('#class_id');
    const teacherSelectContainer = userManagement.querySelector('#teacher-select-container');
    const teacherSelect = userManagement.querySelector('#teacher_id');
    // NEU: Community Ban Checkbox
    const communityBanContainer = userManagement.querySelector('#community-ban-container');
    const communityBanCheckbox = userManagement.querySelector('#is_community_banned');


    let allData = {}; // To store roles, classes, teachers

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        userIdInput.value = '';
        formTitle.textContent = 'Neuen Benutzer anlegen';
        passwordInput.setAttribute('required', 'required');
        passwordInput.parentElement.querySelector('label').textContent = 'Passwort*';
        cancelBtn.style.display = 'none';
        toggleRoleSpecificFields(); // Reset visibility
    };

    const toggleRoleSpecificFields = () => {
        const selectedRole = roleSelect.value;
        classSelectContainer.style.display = selectedRole === 'schueler' ? 'block' : 'none';
        teacherSelectContainer.style.display = selectedRole === 'lehrer' ? 'block' : 'none';
        // NEU: Zeige Ban-Checkbox nur für Schüler
        communityBanContainer.style.display = selectedRole === 'schueler' ? 'block' : 'none';


        // Reset selections when role changes
        if (selectedRole !== 'schueler') {
            classSelect.value = '';
            communityBanCheckbox.checked = false; // NEU
        }
        if (selectedRole !== 'lehrer') {
            teacherSelect.value = '';
        }
    };

    const renderTable = (users) => {
        const currentAdminId = window.APP_CONFIG.userId; // Holt die ID des Admins

        // KORREKTUR: HTML für die neue Spalte "Community" hinzugefügt
        tableBody.innerHTML = users.length > 0 ? users.map(user => {
            let details = '';
            if (user.role === 'schueler' && user.class_name) {
                details = `Klasse: ${user.class_name}`;
            } else if (user.role === 'lehrer' && user.teacher_name) {
                details = `Lehrerprofil: ${user.teacher_name}`;
            }

            // NEU: Community-Status
            let communityStatus = '-';
            if (user.role === 'schueler') {
                communityStatus = user.is_community_banned == 1 
                    ? '<span style="color: var(--color-danger); font-weight: 600;">Gesperrt</span>' 
                    : '<span style="color: var(--color-success);">Aktiv</span>';
            }

            // NEU: Logik für Impersonate-Button
            const canImpersonate = (user.user_id != currentAdminId); // Admin kann sich nicht selbst imitieren
            const impersonateButton = canImpersonate
                ? `<button class="btn btn-secondary btn-small impersonate-user-btn" data-id="${user.user_id}" data-username="${escapeHtml(user.username)}" data-role="${escapeHtml(user.role)}" title="Anmelden als ${escapeHtml(user.username)}">
                       Anmelden als
                   </button>`
                : ``; // Zeige keinen Button an, wenn man es selbst ist

            return `
                <tr data-id="${user.user_id}" data-user='${JSON.stringify(user)}'>
                    <td>${user.user_id}</td>
                    <td><strong>${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)}</strong><br><small>${escapeHtml(user.username)}</small></td>
                    <td>${user.role}</td>
                    <td>${details}</td>
                    <td>${communityStatus}</td>
                    <td class="actions">
                        ${impersonateButton}
                        <button class="btn btn-warning btn-small edit-user">Bearbeiten</button>
                        <button class="btn btn-danger btn-small delete-user">Löschen</button>
                    </td>
                </tr>
            `;
        }).join('') : '<tr><td colspan="6">Keine Benutzer gefunden.</td></tr>'; // KORREKTUR: colspan="6"
    };

    const populateSelects = (data) => {
        allData = data; // Store for later use
        roleSelect.innerHTML = data.roles.map(r => `<option value="${r}">${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('');
        
        // KORRIGIERT: Zeigt jetzt ID und Name an (und nutzt escapeHtml)
        classSelect.innerHTML = '<option value="">Keine Klasse</option>' + data.classes.map(c => 
            `<option value="${c.class_id}">${c.class_id} - ${escapeHtml(c.class_name)}</option>`
        ).join('');
        
        // KORRIGIERT: Zeigt jetzt Name und Kürzel an (und nutzt escapeHtml)
        teacherSelect.innerHTML = '<option value="">Kein Lehrerprofil</option>' + data.teachers.map(t => 
            `<option value="${t.teacher_id}">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)} (${escapeHtml(t.teacher_shortcut)})</option>`
        ).join('');
    };

    const loadUsers = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users`);
            if (response.success) {
                renderTable(response.data.users);
                populateSelects(response.data);
                // After populating, ensure the correct fields are shown based on the initial role value (might be pre-selected)
                toggleRoleSpecificFields();
            }
             // Error handled by apiFetch
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="6" class="message error">Fehler beim Laden der Benutzer.</td></tr>'; // KORREKTUR: colspan="6"
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/users/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/users/update`;

        try {
            // KORREKTUR: admin-users.js sendet FormData, NICHT JSON.
            // Der Controller MUSS $_POST lesen.
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if (response.success) {
                // Use imported function directly
                showToast(response.message, 'success');
                resetForm();
                loadUsers(); // Reload table and selects
            }
             // Error handled by apiFetch
        } catch (error) { /* Handled by apiFetch */ }
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


            // Populate form
            userIdInput.value = user.user_id;
            form.querySelector('#username').value = user.username;
            form.querySelector('#email').value = user.email;
            form.querySelector('#first_name').value = user.first_name;
            form.querySelector('#last_name').value = user.last_name;
            form.querySelector('#birth_date').value = user.birth_date;
            roleSelect.value = user.role;
            // Ensure selects are populated before setting value
            if (allData.classes) classSelect.value = user.class_id || '';
            if (allData.teachers) teacherSelect.value = user.teacher_id || '';
            
            // NEU: Setze den Status der Ban-Checkbox
            if (communityBanCheckbox) {
                communityBanCheckbox.checked = (user.is_community_banned == 1);
            }

            toggleRoleSpecificFields(); // Show/hide fields based on populated role
            form.querySelector('#username').focus(); // Focus first editable field
        }

        if (target.classList.contains('delete-user')) {
            // Use imported function directly
            if (await showConfirm('Benutzer löschen', `Sind Sie sicher, dass Sie ${escapeHtml(user.first_name)} ${escapeHtml(user.last_name)} löschen möchten?`)) {
                // KORREKTUR: Muss FormData senden, da handleApiRequest im Controller $_POST erwartet
                const formData = new FormData();
                formData.append('user_id', id);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/users/delete`, { method: 'POST', body: formData });
                    if (response.success) {
                        // Use imported function directly
                        showToast(response.message, 'success');
                        loadUsers(); // Reload table
                    }
                     // Error handled by apiFetch
                } catch (error) { /* Handled by apiFetch */ }
            }
        }
        
        // NEU: Event-Listener für Impersonate-Button
        if (target.classList.contains('impersonate-user-btn')) {
            // Holt Daten aus dem Button/Row, da 'user' veraltet sein könnte
            const username = target.closest('tr').dataset.user ? JSON.parse(target.closest('tr').dataset.user).username : 'Benutzer';
            const role = target.closest('tr').dataset.user ? JSON.parse(target.closest('tr').dataset.user).role : 'unbekannt';
            await handleImpersonateUser(id, username, role);
        }
    });

    roleSelect.addEventListener('change', toggleRoleSpecificFields);
    cancelBtn.addEventListener('click', resetForm);

    loadUsers(); // Initial load
}

/**
 * NEU: Startet die Impersonation für einen Benutzer.
 * Diese Funktion sendet JSON, daher muss der Controller (impersonateUserApi) JSON erwarten.
 */
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
            // WICHTIG: Diese Route erwartet JSON, basierend auf unserem Controller-Setup
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ user_id: userId }) // Sende die ID als JSON
        });

        if (response.success && response.redirectUrl) {
            showToast(`Sie sind jetzt als ${escapeHtml(username)} angemeldet.`, 'success');
            // Weiterleitung zum Dashboard des Benutzers
            window.location.href = response.redirectUrl;
        }
    } catch (error) {
        console.error('Impersonation failed:', error);
        // Fehler wird bereits von apiFetch als Toast angezeigt
    }
}

// Helper-Funktion (falls nicht global verfügbar)
function escapeHtml(str) {
    if (str === null || typeof str === 'undefined') return '';
    return str.toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}