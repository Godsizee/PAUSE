/* public/assets/js/admin-stammdaten.js */
import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml } from './planer-utils.js';

function initializeTabbedInterface() {
    const stammdatenManagement = document.getElementById('stammdaten-management');
    if (!stammdatenManagement) return;

    const tabButtons = stammdatenManagement.querySelectorAll('.tab-button');
    const tabContents = stammdatenManagement.querySelectorAll('.dashboard-section');

    const initializers = {
        'subjects-section': initializeSubjectManagement,
        'rooms-section': initializeRoomManagement,
        'teachers-section': initializeTeacherManagement,
        'classes-section': initializeClassManagement,
    };

    const handleTabClick = (button) => {
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(content => content.classList.remove('active'));
        button.classList.add('active');
        const targetId = button.dataset.target;
        const targetContent = document.getElementById(targetId);

        if (targetContent) {
            targetContent.classList.add('active');
            if (!targetContent.dataset.initialized) {
                const initFunc = initializers[targetId];
                if (typeof initFunc === 'function') {
                    initFunc();
                    targetContent.dataset.initialized = 'true';
                }
            }
            const firstInput = targetContent.querySelector('form input[type="text"], form input[type="email"], form input[type="number"]');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 50);
            }
        }
    };

    tabButtons.forEach(button => {
        button.addEventListener('click', () => handleTabClick(button));
    });

    const initiallyActiveButton = stammdatenManagement.querySelector('.tab-button.active');
    if (initiallyActiveButton) {
        handleTabClick(initiallyActiveButton);
    }
}

function initializeSubjectManagement() {
    const section = document.getElementById('subjects-section');
    if (!section) return;
    const tableBody = section.querySelector('#subjects-table tbody');
    const form = section.querySelector('#subject-form');
    const formTitle = section.querySelector('#subject-form-container h4');
    const subjectIdInput = section.querySelector('#subject_id');
    const subjectNameInput = section.querySelector('#subject_name');
    const subjectShortcutInput = section.querySelector('#subject_shortcut');
    const cancelBtn = section.querySelector('#cancel-edit-subject');

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        subjectIdInput.value = '';
        formTitle.textContent = 'Neues Fach anlegen';
        cancelBtn.style.display = 'none';
    };

    const renderTable = (subjects) => {
        tableBody.innerHTML = subjects.length > 0 ? subjects.map(subject => {
            const id = escapeHtml(subject.subject_id);
            const name = escapeHtml(subject.subject_name);
            const shortcut = escapeHtml(subject.subject_shortcut);
            return `
            <tr data-id="${id}">
                <td data-label="ID">${id}</td>
                <td data-label="Fachname">${name}</td>
                <td data-label="Kürzel">${shortcut}</td>
                <td class="actions" data-label="Aktionen">
                    <button class="btn btn-warning btn-small edit-subject" data-name="${name}" data-shortcut="${shortcut}">Bearbeiten</button>
                    <button class="btn btn-danger btn-small delete-subject">Löschen</button>
                </td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="4">Keine Fächer gefunden.</td></tr>';
    };

    const loadSubjects = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/subjects`);
            if (response.success) {
                renderTable(response.data);
            }
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="4" class="message error">Fehler beim Laden der Fächer.</td></tr>';
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/subjects/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/subjects/update`;
        try {
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadSubjects();
            }
        } catch(error) {}
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;

        if (target.classList.contains('edit-subject')) {
            form.dataset.mode = 'update';
            subjectIdInput.value = id;
            subjectNameInput.value = target.dataset.name;
            subjectShortcutInput.value = target.dataset.shortcut;
            formTitle.textContent = 'Fach bearbeiten';
            cancelBtn.style.display = 'inline-block';
            subjectNameInput.focus();
        }

        if (target.classList.contains('delete-subject')) {
            if (await showConfirm('Fach löschen', `Sind Sie sicher, dass Sie das Fach '${escapeHtml(target.closest('tr').cells[1].textContent)}' endgültig löschen möchten?`)) {
                const formData = new FormData();
                formData.append('subject_id', id);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/subjects/delete`, { method: 'POST', body: formData });
                    if(response.success) {
                        showToast(response.message, 'success');
                        loadSubjects();
                    }
                } catch(error) {}
            }
        }
    });

    cancelBtn.addEventListener('click', resetForm);
    loadSubjects();
}

function initializeRoomManagement() {
    const section = document.getElementById('rooms-section');
    if (!section) return;
    const tableBody = section.querySelector('#rooms-table tbody');
    const form = section.querySelector('#room-form');
    const formTitle = section.querySelector('#room-form-container h4');
    const roomIdInput = section.querySelector('#room_id');
    const roomNameInput = section.querySelector('#room_name');
    const cancelBtn = section.querySelector('#cancel-edit-room');

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        roomIdInput.value = '';
        formTitle.textContent = 'Neuen Raum anlegen';
        cancelBtn.style.display = 'none';
    };

    const renderTable = (rooms) => {
        tableBody.innerHTML = rooms.length > 0 ? rooms.map(room => {
            const id = escapeHtml(room.room_id);
            const name = escapeHtml(room.room_name);
            return `
            <tr data-id="${id}">
                <td data-label="ID">${id}</td>
                <td data-label="Raumname">${name}</td>
                <td class="actions" data-label="Aktionen">
                    <button class="btn btn-warning btn-small edit-room" data-name="${name}">Bearbeiten</button>
                    <button class="btn btn-danger btn-small delete-room">Löschen</button>
                </td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="3">Keine Räume gefunden.</td></tr>';
    };

    const loadRooms = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/rooms`);
            if (response.success) {
                renderTable(response.data);
            }
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="3" class="message error">Fehler beim Laden der Räume.</td></tr>';
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/rooms/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/rooms/update`;
        try {
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadRooms();
            }
        } catch(error) {}
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;

        if (target.classList.contains('edit-room')) {
            form.dataset.mode = 'update';
            roomIdInput.value = id;
            roomNameInput.value = target.dataset.name;
            formTitle.textContent = 'Raum bearbeiten';
            cancelBtn.style.display = 'inline-block';
            roomNameInput.focus();
        }

        if (target.classList.contains('delete-room')) {
            if (await showConfirm('Raum löschen', `Sind Sie sicher, dass Sie den Raum '${escapeHtml(target.closest('tr').cells[1].textContent)}' endgültig löschen möchten?`)) {
                const formData = new FormData();
                formData.append('room_id', id);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/rooms/delete`, { method: 'POST', body: formData });
                    if(response.success) {
                        showToast(response.message, 'success');
                        loadRooms();
                    }
                } catch(error) {}
            }
        }
    });

    cancelBtn.addEventListener('click', resetForm);
    loadRooms();
}

function initializeTeacherManagement() {
    const section = document.getElementById('teachers-section');
    if (!section) return;
    const tableBody = section.querySelector('#teachers-table tbody');
    const form = section.querySelector('#teacher-form');
    const formTitle = section.querySelector('#teacher-form-container h4');
    const teacherIdInput = section.querySelector('#teacher_id');
    const cancelBtn = section.querySelector('#cancel-edit-teacher');

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        teacherIdInput.value = '';
        formTitle.textContent = 'Lehrer anlegen/bearbeiten';
        cancelBtn.style.display = 'none';
    };

    const renderTable = (teachers) => {
        tableBody.innerHTML = teachers.length > 0 ? teachers.map(t => {
            const id = escapeHtml(t.teacher_id);
            const shortcut = escapeHtml(t.teacher_shortcut);
            const firstName = escapeHtml(t.first_name);
            const lastName = escapeHtml(t.last_name);
            const email = escapeHtml(t.email || '');
            return `
            <tr data-id="${id}">
                <td data-label="ID">${id}</td>
                <td data-label="Kürzel">${shortcut}</td>
                <td data-label="Vorname">${firstName}</td>
                <td data-label="Nachname">${lastName}</td>
                <td data-label="E-Mail">${email}</td>
                <td class="actions" data-label="Aktionen">
                    <button class="btn btn-warning btn-small edit-teacher">Bearbeiten</button>
                    <button class="btn btn-danger btn-small delete-teacher">Löschen</button>
                </td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="6">Keine Lehrer gefunden.</td></tr>';
    };

    const loadTeachers = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/teachers`);
            if (response.success) {
                renderTable(response.data);
            }
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="6" class="message error">Fehler beim Laden der Lehrer.</td></tr>';
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/teachers/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/teachers/update`;
        try {
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadTeachers();
            }
        } catch(error) {}
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;

        if (target.classList.contains('edit-teacher')) {
            form.dataset.mode = 'update';
            teacherIdInput.value = id;
            form.querySelector('#teacher_shortcut').value = row.cells[1].textContent;
            form.querySelector('#first_name').value = row.cells[2].textContent;
            form.querySelector('#last_name').value = row.cells[3].textContent;
            form.querySelector('#email').value = row.cells[4].textContent;
            formTitle.textContent = 'Lehrer bearbeiten';
            cancelBtn.style.display = 'inline-block';
            form.querySelector('#teacher_shortcut').focus();
        }

        if (target.classList.contains('delete-teacher')) {
            if (await showConfirm('Lehrer löschen', `Sind Sie sicher, dass Sie '${escapeHtml(row.cells[2].textContent)} ${escapeHtml(row.cells[3].textContent)}' endgültig löschen möchten? Dies kann Stundenpläne beeinflussen.`)) {
                const formData = new FormData();
                formData.append('teacher_id', id);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/teachers/delete`, { method: 'POST', body: formData });
                    if(response.success) {
                        showToast(response.message, 'success');
                        loadTeachers();
                    }
                } catch(error) {}
            }
        }
    });

    cancelBtn.addEventListener('click', resetForm);
    loadTeachers();
}

function initializeClassManagement() {
    const section = document.getElementById('classes-section');
    if (!section) return;
    const tableBody = section.querySelector('#classes-table tbody');
    const form = section.querySelector('#class-form');
    const formTitle = section.querySelector('#class-form-container h4');
    const classIdInput = section.querySelector('#class_id_input');
    const classIdHiddenInput = section.querySelector('#class_id_hidden');
    const classNameInput = section.querySelector('#class_name');
    const teacherSelect = section.querySelector('#class_teacher_id');
    const cancelBtn = section.querySelector('#cancel-edit-class');

    const resetForm = () => {
        form.dataset.mode = 'create';
        form.reset();
        classIdHiddenInput.value = '';
        formTitle.textContent = 'Neue Klasse anlegen';
        cancelBtn.style.display = 'none';
        classIdInput.readOnly = false;
        classIdInput.disabled = false;
    };

    const renderTable = (classes) => {
        tableBody.innerHTML = classes.length > 0 ? classes.map(c => {
            const id = escapeHtml(c.class_id);
            const name = escapeHtml(c.class_name);
            const teacherName = escapeHtml(c.teacher_name || 'Kein Klassenlehrer');
            return `
            <tr data-id="${id}" data-teacher-id="${escapeHtml(c.class_teacher_id || '')}">
                <td data-label="ID">${id}</td>
                <td data-label="Klassen-Akronym">${name}</td>
                <td data-label="Klassenlehrer">${teacherName}</td>
                <td class="actions" data-label="Aktionen">
                    <button class="btn btn-warning btn-small edit-class" data-name="${name}">Bearbeiten</button>
                    <button class="btn btn-danger btn-small delete-class">Löschen</button>
                </td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="4">Keine Klassen gefunden.</td></tr>';
    };

    const loadTeachersForSelect = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/teachers`);
            if (response.success) {
                teacherSelect.innerHTML = '<option value="">Kein Klassenlehrer</option>' + response.data.map(t =>
                    `<option value="${escapeHtml(t.teacher_id)}">${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)} (${escapeHtml(t.teacher_shortcut)})</option>`
                ).join('');
            }
        } catch (error) {
            teacherSelect.innerHTML = '<option value="">Lehrer konnten nicht geladen werden</option>';
        }
    };

    const loadClasses = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/classes`);
            if (response.success) {
                renderTable(response.data);
            }
        } catch (error) {
            tableBody.innerHTML = '<tr><td colspan="4" class="message error">Fehler beim Laden der Klassen.</td></tr>';
        }
    };

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const mode = form.dataset.mode;
        const formData = new FormData(form);
        
        // Korrekte ID basierend auf dem Modus setzen
        if (mode === 'update') {
            formData.set('class_id_hidden', classIdHiddenInput.value); // Nutzt die versteckte ID
            formData.delete('class_id_input'); // Entfernt das sichtbare (ggf. deaktivierte) ID-Feld
        } else {
            // class_id_input wird bereits durch FormData erfasst
            formData.delete('class_id_hidden'); // Entfernt das leere versteckte Feld
        }

        const url = mode === 'create'
            ? `${window.APP_CONFIG.baseUrl}/api/admin/classes/create`
            : `${window.APP_CONFIG.baseUrl}/api/admin/classes/update`;
        try {
            const response = await apiFetch(url, { method: 'POST', body: formData });
            if(response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadClasses();
            }
        } catch(error) {}
    });

    tableBody.addEventListener('click', async (e) => {
        const target = e.target;
        const row = target.closest('tr');
        if (!row) return;
        const id = row.dataset.id;

        if (target.classList.contains('edit-class')) {
            form.dataset.mode = 'update';
            classIdInput.value = id;
            classIdInput.readOnly = true;
            classIdInput.disabled = true;
            classIdHiddenInput.value = id;
            classNameInput.value = target.dataset.name;
            teacherSelect.value = row.dataset.teacherId;
            formTitle.textContent = 'Klasse bearbeiten';
            cancelBtn.style.display = 'inline-block';
            classNameInput.focus();
        }

        if (target.classList.contains('delete-class')) {
            if (await showConfirm('Klasse löschen', `Sind Sie sicher, dass Sie die Klasse '${escapeHtml(row.cells[1].textContent)}' endgültig löschen möchten? Dies kann Stundenpläne und Benutzerzuweisungen beeinflussen.`)) {
                const formData = new FormData();
                formData.append('class_id', id);
                try {
                    const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/admin/classes/delete`, { method: 'POST', body: formData });
                    if(response.success) {
                        showToast(response.message, 'success');
                        loadClasses();
                    }
                } catch(error) {}
            }
        }
    });

    cancelBtn.addEventListener('click', resetForm);
    loadTeachersForSelect();
    loadClasses();
}

export function initializeAdminStammdaten() {
    initializeTabbedInterface();
}