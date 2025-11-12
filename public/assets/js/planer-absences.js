import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml, getWeekAndYear, getDateOfISOWeek } from './planer-utils.js';
function formatShortTime(timeString) {
    if (!timeString) return '';
    const parts = timeString.split(':');
    if (parts.length >= 2) {
        return `${parts[0]}:${parts[1]}`;
    }
    return timeString; 
}
function formatDayOfWeek(dayNum) {
    const days = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
    const index = parseInt(dayNum, 10) - 1;
    return days[index] || 'Unbekannt';
}
export function initializePlanerAbsences() {
    const container = document.getElementById('absence-management');
    if (!container) return;
    const calendarEl = container.querySelector('#absence-calendar');
    const form = container.querySelector('#absence-form');
    const formTitle = container.querySelector('#absence-form-title');
    const teacherSelect = container.querySelector('#absence-teacher-id');
    const reasonSelect = container.querySelector('#absence-reason');
    const startDateInput = container.querySelector('#absence-start-date');
    const endDateInput = container.querySelector('#absence-end-date');
    const absenceIdInput = container.querySelector('#absence-id');
    const saveButton = container.querySelector('#absence-save-btn');
    const cancelEditButton = container.querySelector('#absence-cancel-edit-btn');
    const deleteButton = container.querySelector('#absence-delete-btn');
    const saveSpinner = container.querySelector('#absence-save-spinner');
    let calendarInstance = null;
    if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
        console.error("FullCalendar ist nicht geladen. Stellen Sie sicher, dass es in header.php eingebunden ist.");
        if (calendarEl) {
            calendarEl.innerHTML = '<p class="message error">Kalender-Bibliothek konnte nicht geladen werden.</p>';
        }
        return;
    }
    console.log("planer-absences.js: Initialisierung läuft...");
    console.log("container:", container); 
    console.log("calendarEl (gesucht in container):", calendarEl);
    console.log("form (gesucht in container):", form);
    console.log("teacherSelect (gesucht in container):", teacherSelect);
    if (!calendarEl || !form || !teacherSelect) {
        console.error("Erforderliche Elemente für das Abwesenheits-Management fehlen.");
        if (!calendarEl) console.error("Element '#absence-calendar' nicht gefunden.");
        if (!form) console.error("Element '#absence-form' nicht gefunden.");
        if (!teacherSelect) console.error("Element '#absence-teacher-id' nicht gefunden.");
        return;
    }
    const resetForm = () => {
        form.reset();
        absenceIdInput.value = '';
        formTitle.textContent = 'Neue Abwesenheit eintragen';
        saveButton.textContent = 'Speichern';
        deleteButton.style.display = 'none';
        cancelEditButton.style.display = 'none';
        startDateInput.value = '';
        endDateInput.value = '';
    };
    const loadAbsences = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/absences`);
            if (response.success && response.data) {
                const events = response.data.map(abs => ({
                    id: abs.absence_id,
                    title: `${abs.teacher_shortcut}: ${abs.reason}`,
                    start: abs.start_date,
                    end: new Date(new Date(abs.end_date).getTime() + 86400000).toISOString().split('T')[0], 
                    allDay: true,
                    classNames: [`absence-${abs.reason.toLowerCase()}`],
                    extendedProps: {
                        teacher_id: abs.teacher_id,
                        reason: abs.reason
                    }
                }));
                if (calendarInstance) {
                    calendarInstance.removeAllEvents();
                    calendarInstance.addEventSource(events);
                }
            } else {
                throw new Error(response.message || "Abwesenheiten konnten nicht geladen werden.");
            }
        } catch (error) {
            console.error("Fehler beim Laden der Abwesenheiten:", error);
            showToast(error.message, 'error');
        }
    };
    const initializeCalendar = () => {
        calendarInstance = new FullCalendar.Calendar(calendarEl, {
            locale: 'de', 
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listWeek'
            },
            buttonText: {
                today: 'Heute',
                month: 'Monat',
                list: 'Liste'
            },
            selectable: true, 
            select: (selectionInfo) => {
                resetForm();
                startDateInput.value = selectionInfo.startStr;
                const endDate = new Date(new Date(selectionInfo.endStr).getTime() - 86400000);
                endDateInput.value = endDate.toISOString().split('T')[0];
                formTitle.textContent = 'Neue Abwesenheit eintragen';
                teacherSelect.focus();
            },
            eventClick: (clickInfo) => {
                const event = clickInfo.event;
                formTitle.textContent = 'Abwesenheit bearbeiten';
                absenceIdInput.value = event.id;
                teacherSelect.value = event.extendedProps.teacher_id;
                reasonSelect.value = event.extendedProps.reason;
                startDateInput.value = event.startStr;
                const endDate = new Date(new Date(event.endStr).getTime() - 86400000);
                endDateInput.value = endDate.toISOString().split('T')[0];
                saveButton.textContent = 'Aktualisieren';
                deleteButton.style.display = 'inline-block';
                cancelEditButton.style.display = 'inline-block';
            }
        });
        calendarInstance.render();
        loadAbsences(); 
    };
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        saveButton.disabled = true;
        saveSpinner.style.display = 'inline-block';
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const url = `${window.APP_CONFIG.baseUrl}/api/planer/absences/save`;
        try {
            if (data.start_date > data.end_date) {
                throw new Error("Das Startdatum darf nicht nach dem Enddatum liegen.");
            }
            const response = await apiFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (response.success) {
                showToast(response.message, 'success');
                resetForm();
                loadAbsences(); 
            }
        } catch (error) {
            console.error("Fehler beim Speichern der Abwesenheit:", error);
            if (!error.message.includes('API')) { 
                 showToast(error.message, 'error');
            }
        } finally {
            saveButton.disabled = false;
            saveSpinner.style.display = 'none';
        }
    });
    deleteButton.addEventListener('click', async () => {
        const absenceId = absenceIdInput.value;
        if (!absenceId) return;
        if (await showConfirm("Löschen bestätigen", "Möchten Sie diese Abwesenheit wirklich löschen?")) {
            saveButton.disabled = true;
            deleteButton.disabled = true;
            saveSpinner.style.display = 'inline-block';
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/absences/delete`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ absence_id: absenceId })
                });
                if (response.success) {
                    showToast(response.message, 'success');
                    resetForm();
                    loadAbsences(); 
                }
            } catch (error) {
                console.error("Fehler beim Löschen:", error);
            } finally {
                saveButton.disabled = false;
                deleteButton.disabled = false;
                saveSpinner.style.display = 'none';
            }
        }
    });
    cancelEditButton.addEventListener('click', () => {
        resetForm();
    });
    initializeCalendar();
}