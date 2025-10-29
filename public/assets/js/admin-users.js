import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js'; // Import notification functions

export function initializeAdminUsers() {
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

            return `
                <tr data-id="${user.user_id}" data-user='${JSON.stringify(user)}'>
                    <td>${user.user_id}</td>
                    <td><strong>${user.first_name} ${user.last_name}</strong><br><small>${user.username}</small></td>
                    <td>${user.role}</td>
                    <td>${details}</td>
                    <td>${communityStatus}</td>
                    <td class="actions">
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
        classSelect.innerHTML = '<option value="">Keine Klasse</option>' + data.classes.map(c => `<option value="${c.class_id}">${c.class_name}</option>`).join('');
        teacherSelect.innerHTML = '<option value="">Kein Lehrerprofil</option>' + data.teachers.map(t => `<option value="${t.teacher_id}">${t.first_name} ${t.last_name} (${t.teacher_shortcut})</option>`).join('');
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
            if (await showConfirm('Benutzer löschen', `Sind Sie sicher, dass Sie ${user.first_name} ${user.last_name} löschen möchten?`)) {
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
    });

    roleSelect.addEventListener('change', toggleRoleSpecificFields);
    cancelBtn.addEventListener('click', resetForm);

    loadUsers(); // Initial load
}