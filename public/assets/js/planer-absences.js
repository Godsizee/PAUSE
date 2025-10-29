import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml, getWeekAndYear, getDateOfISOWeek } from './planer-utils.js';
// KORREKTUR: FullCalendar wird als globales Objekt (aus header.php) verwendet, nicht als ES-Modul importiert.
// import { FullCalendar } from './fullcalendar-index.js'; // ENTFERNT

/**
 * Escapes HTML special characters.
 * @param {string} unsafe
 * @returns {string}
 */
// function escapeHtml(unsafe) { ... } // Duplikate Funktion, da sie schon in planer-utils ist. Besser aus planer-utils importieren.

/**
 * Formatiert HH:MM:SS zu HH:MM.
 * @param {string} timeString - HH:MM:SS
 * @returns {string} HH:MM
 */
function formatShortTime(timeString) {
    if (!timeString) return '';
    const parts = timeString.split(':');
    if (parts.length >= 2) {
        return `${parts[0]}:${parts[1]}`;
    }
    return timeString; // Fallback
}

/**
 * Wandelt 1-5 in Wochentage um.
 * @param {string|number} dayNum
 * @returns {string}
 */
function formatDayOfWeek(dayNum) {
    const days = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
    const index = parseInt(dayNum, 10) - 1;
    return days[index] || 'Unbekannt';
}

/**
 * Hauptinitialisierungsfunktion, die jetzt exportiert wird.
 */
export function initializePlanerAbsences() {
    const container = document.getElementById('absence-management');
    if (!container) return;

    // KORREKTUR: Suche Elemente innerhalb des 'container'-Elements statt 'document'
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

    // KORREKTUR: Prüfe, ob das globale FullCalendar-Objekt existiert.
    if (typeof FullCalendar === 'undefined' || typeof FullCalendar.Calendar === 'undefined') {
        console.error("FullCalendar ist nicht geladen. Stellen Sie sicher, dass es in header.php eingebunden ist.");
        if (calendarEl) {
            calendarEl.innerHTML = '<p class="message error">Kalender-Bibliothek konnte nicht geladen werden.</p>';
        }
        return;
    }

    // --- NEUE DEBUGGING-LOGS ---
    console.log("planer-absences.js: Initialisierung läuft...");
    console.log("container:", container); // Zeigt den gefundenen Container
    console.log("calendarEl (gesucht in container):", calendarEl);
    console.log("form (gesucht in container):", form);
    console.log("teacherSelect (gesucht in container):", teacherSelect);
    // --- ENDE DEBUGGING-LOGS ---

    if (!calendarEl || !form || !teacherSelect) {
        console.error("Erforderliche Elemente für das Abwesenheits-Management fehlen.");
        // --- NEUE DEBUGGING-LOGS ---
        if (!calendarEl) console.error("Element '#absence-calendar' nicht gefunden.");
        if (!form) console.error("Element '#absence-form' nicht gefunden.");
        if (!teacherSelect) console.error("Element '#absence-teacher-id' nicht gefunden.");
        // --- ENDE DEBUGGING-LOGS ---
        return;
    }

    /**
     * Setzt das Formular in den "Erstellen"-Modus zurück.
     */
    const resetForm = () => {
        form.reset();
        absenceIdInput.value = '';
        formTitle.textContent = 'Neue Abwesenheit eintragen';
        saveButton.textContent = 'Speichern';
        deleteButton.style.display = 'none';
        cancelEditButton.style.display = 'none';
        
        // Setze Start- und Enddatum zurück (falls sie durch Kalenderauswahl gesetzt wurden)
        startDateInput.value = '';
        endDateInput.value = '';
    };

    /**
     * Lädt die Abwesenheiten und zeigt sie im Kalender an.
     */
    const loadAbsences = async () => {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/absences`);
            if (response.success && response.data) {
                const events = response.data.map(abs => ({
                    id: abs.absence_id,
                    title: `${abs.teacher_shortcut}: ${abs.reason}`,
                    start: abs.start_date,
                    end: new Date(new Date(abs.end_date).getTime() + 86400000).toISOString().split('T')[0], // Enddatum ist exklusiv in FullCalendar
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

    /**
     * Initialisiert den FullCalendar.
     */
    const initializeCalendar = () => {
        // KORREKTUR: Verwende das globale FullCalendar-Objekt
        calendarInstance = new FullCalendar.Calendar(calendarEl, {
            locale: 'de', // Deutsches Sprachpaket
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
            selectable: true, // Erlaube das Auswählen von Tagen
            select: (selectionInfo) => {
                // Bei Auswahl eines Zeitraums, fülle das Formular
                resetForm();
                startDateInput.value = selectionInfo.startStr;
                // FullCalendar's Enddatum ist exklusiv. Wir müssen einen Tag abziehen.
                const endDate = new Date(new Date(selectionInfo.endStr).getTime() - 86400000);
                endDateInput.value = endDate.toISOString().split('T')[0];
                formTitle.textContent = 'Neue Abwesenheit eintragen';
                teacherSelect.focus();
            },
            eventClick: (clickInfo) => {
                // Bei Klick auf ein Event, fülle das Formular zum Bearbeiten
                const event = clickInfo.event;
                formTitle.textContent = 'Abwesenheit bearbeiten';
                absenceIdInput.value = event.id;
                teacherSelect.value = event.extendedProps.teacher_id;
                reasonSelect.value = event.extendedProps.reason;
                startDateInput.value = event.startStr;
                
                // Enddatum ist exklusiv, ziehe einen Tag ab für die Anzeige
                const endDate = new Date(new Date(event.endStr).getTime() - 86400000);
                endDateInput.value = endDate.toISOString().split('T')[0];

                saveButton.textContent = 'Aktualisieren';
                deleteButton.style.display = 'inline-block';
                cancelEditButton.style.display = 'inline-block';
            }
        });
        calendarInstance.render();
        loadAbsences(); // Lade Events nach der Initialisierung
    };

    /**
     * Behandelt das Speichern (Erstellen/Aktualisieren) einer Abwesenheit.
     */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        saveButton.disabled = true;
        saveSpinner.style.display = 'inline-block';

        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const url = `${window.APP_CONFIG.baseUrl}/api/planer/absences/save`;

        try {
            // KORREKTUR: Validierung Start-/Enddatum
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
                loadAbsences(); // Kalender neu laden
            }
            // Fehler wird von apiFetch als Toast angezeigt
        } catch (error) {
            console.error("Fehler beim Speichern der Abwesenheit:", error);
            // Fehler-Toast wird bereits von apiFetch angezeigt (oder hier, falls Validierung fehlschlägt)
            if (!error.message.includes('API')) { // Zeige Validierungsfehler
                 showToast(error.message, 'error');
            }
        } finally {
            saveButton.disabled = false;
            saveSpinner.style.display = 'none';
        }
    });

    /**
     * Behandelt das Löschen einer Abwesenheit.
     */
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
                    loadAbsences(); // Kalender neu laden
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

    /**
     * Behandelt das Abbrechen des Bearbeitungsmodus.
     */
    cancelEditButton.addEventListener('click', () => {
        resetForm();
    });

    // --- Initialisierung ---
    // (Lehrer-Select wurde bereits durch PHP/Stammdaten geladen)
    initializeCalendar();
}

