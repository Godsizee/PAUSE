// public/assets/js/teacher-cockpit.js
import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';

/**
 * Escapes HTML special characters.
 * @param {string} unsafe
 * @returns {string}
 */
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

// NEU: Formatiert HH:MM:SS zu HH:MM
function formatShortTime(timeString) {
    if (!timeString) return '';
    const parts = timeString.split(':');
    if (parts.length >= 2) {
        return `${parts[0]}:${parts[1]}`;
    }
    return timeString; // Fallback
}
// NEU: Wandelt 1-5 in Wochentage um
function formatDayOfWeek(dayNum) {
    const days = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag'];
    const index = parseInt(dayNum, 10) - 1;
    return days[index] || 'Unbekannt';
}

// ▼▼▼ KORREKTUR: Fehlende Funktion hinzugefügt ▼▼▼
/**
 * Formatiert einen YYYY-MM-DD Datum in ein lesbares deutsches Format.
 * @param {string} dateString - YYYY-MM-DD
 * @returns {string} TT.MM.YYYY
 */
function formatGermanDate(dateString) {
    if (!dateString) return '';
    try {
        const parts = dateString.split('-');
        if (parts.length === 3) {
            return `${parts[2]}.${parts[1]}.${parts[0]}`;
        }
        return dateString;
    } catch(e) {
        return dateString;
    }
}
// ▲▲▲ ENDE KORREKTUR ▲▲▲


// Globale Variable für den Controller der Anwesenheitsliste
let attendanceController = null;

export function initializeTeacherCockpit() {
    const cockpit = document.getElementById('teacher-cockpit');
    if (!cockpit || window.APP_CONFIG.userRole !== 'lehrer') {
        return; // Nur für Lehrer initialisieren
    }

    // --- Feature: Kollege finden ---
    const searchInput = document.getElementById('colleague-search-input');
    const searchResults = document.getElementById('colleague-search-results');
    const resultDisplay = document.getElementById('colleague-result-display');
    const resultSpinner = resultDisplay.querySelector('.loading-spinner');
    const resultParagraph = resultDisplay.querySelector('p');
    // KORREKTUR: Cockpit-Container für Klick-Außerhalb-Erkennung
    const findColleagueFeature = document.getElementById('find-colleague-feature');


    let searchTimeout;
    let selectedTeacherId = null;

    const handleSearchInput = () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(async () => {
            const query = searchInput.value.trim();
            if (query.length < 2) {
                searchResults.innerHTML = '';
                searchResults.classList.remove('visible');
                return;
            }
            
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/search-colleagues?query=${encodeURIComponent(query)}`);
                if (response.success && response.data) {
                      const filteredTeachers = response.data.filter(t => t.teacher_shortcut !== 'SGL');
                      if (filteredTeachers.length > 0) {
                        searchResults.innerHTML = filteredTeachers.map(teacher => `
                            <div class="search-result-item" data-id="${teacher.teacher_id}" data-name="${escapeHtml(teacher.first_name)} ${escapeHtml(teacher.last_name)} (${escapeHtml(teacher.teacher_shortcut)})">
                                <strong>${escapeHtml(teacher.last_name)}, ${escapeHtml(teacher.first_name)}</strong> (${escapeHtml(teacher.teacher_shortcut)})
                            </div>
                        `).join('');
                        searchResults.classList.add('visible');
                    } else {
                        searchResults.innerHTML = '<div class="search-result-item none">Keine Treffer</div>';
                        searchResults.classList.add('visible');
                    }
                } else {
                    searchResults.innerHTML = '<div class="search-result-item none">Keine Treffer</div>';
                    searchResults.classList.add('visible');
                }
            } catch (error) {
                console.error("Fehler bei Lehrersuche:", error);
                searchResults.innerHTML = `<div class="search-result-item none">Fehler: ${escapeHtml(error.message)}</div>`;
                searchResults.classList.add('visible');
            }
        }, 300); // 300ms Verzögerung
    };

    const handleResultClick = async (e) => {
        const item = e.target.closest('.search-result-item');
        if (!item || !item.dataset.id) return;

        selectedTeacherId = item.dataset.id;
        const selectedName = item.dataset.name;

        // UI-Vorbereitung
        searchInput.value = selectedName; // Feld füllen
        searchResults.innerHTML = ''; // Ergebnisse schließen
        searchResults.classList.remove('visible');
        resultDisplay.style.display = 'flex';
        if(resultSpinner) resultSpinner.style.display = 'block';
        if(resultParagraph) resultParagraph.innerHTML = `Suche nach ${escapeHtml(selectedName)}...`;

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/find-colleague?teacher_id=${selectedTeacherId}`);
            if (response.success) {
                // Antwort anzeigen
                if(resultParagraph) resultParagraph.innerHTML = `
                    <strong>${escapeHtml(selectedName)}:</strong><br>
                    ${escapeHtml(response.data.message)}
                `;
            } else {
                throw new Error(response.message);
            }
        } catch (error) {
            console.error("Fehler bei Standortabfrage:", error);
            if(resultParagraph) resultParagraph.innerHTML = `<span class="text-danger">Fehler: ${escapeHtml(error.message)}</span>`;
        } finally {
            if(resultSpinner) resultSpinner.style.display = 'none';
        }
    };

    if (searchInput && searchResults && resultDisplay && findColleagueFeature) {
        searchInput.addEventListener('input', handleSearchInput);
        searchResults.addEventListener('click', handleResultClick);
        // KORREKTUR: Klick außerhalb des Widgets schließt Dropdown
        document.addEventListener('click', (e) => {
            if (!findColleagueFeature.contains(e.target)) {
                searchResults.classList.remove('visible');
            }
        });
    }

    // --- Feature: Digitale Anwesenheit ---
    const attendanceContainer = document.getElementById('attendance-feature');
    if (attendanceContainer) {
        attendanceController = new AttendanceController(attendanceContainer);
        attendanceController.loadCurrentLesson();
    }
    
    // --- Feature: Aufgaben/Klausuren ---
    const eventsContainer = document.getElementById('academic-events-feature');
    if (eventsContainer) {
        const eventsController = new AcademicEventsController(eventsContainer);
        eventsController.initialize();
    }

    // --- Feature: Sprechstunden verwalten ---
    const officeHoursContainer = document.getElementById('office-hours-feature');
    if (officeHoursContainer) {
        const officeHoursController = new OfficeHoursController(officeHoursContainer);
        officeHoursController.initialize();
    }
}


/**
 * Klasse zur Verwaltung der Anwesenheits-Logik
 */
class AttendanceController {
    constructor(containerElement) {
        this.container = containerElement;
        this.lessonDisplay = document.getElementById('attendance-current-lesson');
        this.listContainer = document.getElementById('attendance-list-container');
        this.studentList = document.getElementById('attendance-student-list');
        this.saveButton = document.getElementById('save-attendance-btn');
        this.saveSpinner = document.getElementById('attendance-save-spinner');
        this.saveContext = null; 
        
        this.bindEvents();
    }

    bindEvents() {
        if (this.saveButton) {
            this.saveButton.addEventListener('click', () => this.handleSaveAttendance());
        }
        
        if (this.studentList) {
            this.studentList.addEventListener('click', (e) => {
                const button = e.target.closest('.btn-toggle');
                if (!button) return;
                
                const li = button.closest('.student-row');
                if (!li) return;
                const newStatus = button.dataset.status;
                
                li.dataset.status = newStatus;
                
                li.querySelectorAll('.btn-toggle').forEach(btn => {
                    btn.classList.remove('active');
                });
                button.classList.add('active');
            });
        }
    }

    async loadCurrentLesson() {
        if (!this.lessonDisplay || !this.listContainer) return;
        
        this.lessonDisplay.innerHTML = '<div class="loading-spinner small"></div><p>Prüfe aktuelle Stunde...</p>';
        this.listContainer.style.display = 'none';

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/current-lesson`);
            if (!response.success) throw new Error(response.message);

            const { status, lesson, students, attendance, context } = response.data;

            if (status === 'Unterricht' || status === 'Vertretung') {
                this.lessonDisplay.innerHTML = `
                    <p class="lesson-info">
                        Aktuelle Stunde: <strong>${escapeHtml(lesson.class_name)} (${escapeHtml(lesson.subject_shortcut || lesson.new_subject_shortcut || '?')})</strong>
                        in Raum <strong>${escapeHtml(lesson.room_name || lesson.new_room_name || '?')}</strong>
                        (${status === 'Vertretung' ? 'Vertretung' : 'Regulär'})
                    </p>
                `;
                this.renderStudentList(students, attendance);
                this.listContainer.style.display = 'block';
                this.saveContext = {
                    class_id: lesson.class_id,
                    date: context.date,
                    period_number: context.period
                };
                
            } else if (status === 'Freistunde') {
                this.lessonDisplay.innerHTML = '<p class="message info" style="margin: 0; padding: 0; background: transparent; border: none;">Sie haben jetzt eine Freistunde.</p>';
            } else {
                this.lessonDisplay.innerHTML = '<p class="message info" style="margin: 0; padding: 0; background: transparent; border: none;">Aktuell findet kein Unterricht statt.</p>';
            }

        } catch (error) {
            console.error("Fehler beim Laden der aktuellen Stunde:", error);
            this.lessonDisplay.innerHTML = `<p class="message error">Fehler: ${escapeHtml(error.message)}</p>`;
        }
    }

    renderStudentList(students, attendance) {
        if (!students || students.length === 0) {
            this.studentList.innerHTML = '<li>Keine Schüler für diese Klasse gefunden.</li>';
            this.saveButton.disabled = true;
            return;
        }

        this.studentList.innerHTML = students.map(student => {
            const currentStatus = attendance[student.user_id] || 'anwesend'; 
            
            return `
                <li class="student-row" data-student-id="${student.user_id}" data-status="${currentStatus}">
                    <span class="student-name">${escapeHtml(student.last_name)}, ${escapeHtml(student.first_name)}</span>
                    <div class="attendance-toggles">
                        <button class="btn-toggle status-anwesend ${currentStatus === 'anwesend' ? 'active' : ''}" data-status="anwesend">A</button>
                        <button class="btn-toggle status-abwesend ${currentStatus === 'abwesend' ? 'active' : ''}" data-status="abwesend">F</button>
                        <button class="btn-toggle status-verspaetet ${currentStatus === 'verspaetet' ? 'active' : ''}" data-status="verspaetet">V</button>
                    </div>
                </li>
            `;
        }).join('');
        this.saveButton.disabled = false;
    }

    async handleSaveAttendance() {
        if (!this.saveContext) {
            showToast("Fehler: Keine Stundendaten zum Speichern vorhanden.", "error");
            return;
        }

        this.saveButton.disabled = true;
        this.saveSpinner.style.display = 'block';

        const studentsStatus = [];
        this.studentList.querySelectorAll('.student-row').forEach(row => {
            studentsStatus.push({
                student_id: row.dataset.studentId,
                status: row.dataset.status
            });
        });

        const body = {
            ...this.saveContext,
            students: studentsStatus
        };

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/attendance/save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            if (response.success) {
                showToast(response.message, 'success');
            }
        } catch (error) {
            console.error("Fehler beim Speichern der Anwesenheit:", error);
        } finally {
            this.saveButton.disabled = false;
            this.saveSpinner.style.display = 'none';
        }
    }
}

/**
 * Klasse zur Verwaltung der Aufgaben/Klausuren-Logik im Cockpit
 */
class AcademicEventsController {
    constructor(containerElement) {
        this.container = containerElement;
        this.form = document.getElementById('academic-event-form');
        this.classSelect = document.getElementById('event-class-id');
        this.subjectSelect = document.getElementById('event-subject-id');
        this.eventList = document.getElementById('teacher-event-list');
        this.saveButton = document.getElementById('save-event-btn');
        this.saveSpinner = document.getElementById('event-save-spinner');
        
        this.teacherClasses = [];
        this.allSubjects = [];
    }

    async initialize() {
        if (!this.form) return;
        
        this.bindEvents();
        await this.loadPrerequisites();
        await this.loadTeacherEvents();
    }

    bindEvents() {
        this.form.addEventListener('submit', (e) => this.handleSaveEvent(e));
        
        this.eventList.addEventListener('click', (e) => {
            const deleteButton = e.target.closest('.delete-event-btn');
            if (deleteButton) {
                const eventId = deleteButton.dataset.eventId;
                const eventTitle = deleteButton.dataset.eventTitle;
                this.handleDeleteEvent(eventId, eventTitle);
            }
        });
    }

    async loadPrerequisites() {
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/prerequisites`);
            
            if (response.success && response.data) {
                this.allSubjects = response.data.subjects || [];
                this.teacherClasses = response.data.classes || [];

                this.subjectSelect.innerHTML = '<option value="">Kein Fach</option>' + 
                    this.allSubjects.map(s => 
                        `<option value="${s.subject_id}">${escapeHtml(s.subject_name)} (${escapeHtml(s.subject_shortcut)})</option>`
                    ).join('');
                
                if (this.teacherClasses.length > 0) {
                    this.classSelect.innerHTML = '<option value="">-- Klasse wählen --</option>' + 
                        this.teacherClasses.map(c => 
                            `<option value="${c.class_id}">${escapeHtml(c.class_name)} (ID: ${c.class_id})</option>`
                        ).join('');
                } else {
                    this.classSelect.innerHTML = '<option value="">Keine Klassen gefunden</option>';
                    this.form.querySelector('button[type="submit"]').disabled = true;
                    showToast("Es wurden keine Klassen gefunden, die Sie unterrichten.", "info", 5000);
                }
            } else {
                throw new Error(response.message || "Stammdaten konnten nicht geladen werden.");
            }
        } catch (error) {
            console.error("Fehler beim Laden der Voraussetzungen für Events:", error);
            this.classSelect.innerHTML = '<option value="">Fehler</option>';
            this.subjectSelect.innerHTML = '<option value="">Fehler</option>';
            showToast(`Fehler beim Laden der Klassen/Fächer: ${error.message}`, 'error');
        }
    }

    async loadTeacherEvents() {
        this.eventList.innerHTML = '<div class="loading-spinner small"></div>';
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/events`);
            if (response.success && response.data) {
                this.renderEventList(response.data);
            } else {
                throw new Error(response.message || "Events konnten nicht geladen werden.");
            }
        } catch (error) {
            console.error("Fehler beim Laden der Lehrer-Events:", error);
            this.eventList.innerHTML = `<p class="message error small">${escapeHtml(error.message)}</p>`;
        }
    }

    renderEventList(events) {
        if (events.length === 0) {
            this.eventList.innerHTML = '<p class="message info small" style="margin: 10px;">Keine Einträge für die nächsten 14 Tage gefunden.</p>';
            return;
        }

        const groups = events.reduce((acc, event) => {
            const date = event.due_date;
            if (!acc[date]) {
                acc[date] = [];
            }
            acc[date].push(event);
            return acc;
        }, {});

        let html = '';
        const sortedDates = Object.keys(groups).sort();

        for (const date of sortedDates) {
            html += `<div class="event-group">
                        <div class="event-group-date">${escapeHtml(formatGermanDate(date))}</div>
                        <ul class="event-list-items">`;
            
            groups[date].forEach(event => {
                let icon = 'ℹ️'; // Info
                if (event.event_type === 'klausur') icon = '🎓'; // Klausur
                if (event.event_type === 'aufgabe') icon = '📚'; // Aufgabe
                
                html += `
                    <li class="event-list-item type-${escapeHtml(event.event_type)}" data-event-id="${event.event_id}">
                        <span class="event-icon">${icon}</span>
                        <div class="event-details">
                            <strong>${escapeHtml(event.title)}</strong>
                            <span>
                                ${escapeHtml(event.class_name)} 
                                ${event.subject_shortcut ? `(${escapeHtml(event.subject_shortcut)})` : ''}
                            </span>
                            ${event.description ? `<small>${escapeHtml(event.description)}</small>` : ''}
                        </div>
                        <button class="btn btn-danger btn-small delete-event-btn" 
                                title="Eintrag löschen"
                                data-event-id="${event.event_id}" 
                                data-event-title="${escapeHtml(event.title)}">
                            &times;
                        </button>
                    </li>
                `;
            });
            
            html += `</ul></div>`;
        }
        this.eventList.innerHTML = html;
    }

    async handleSaveEvent(e) {
        e.preventDefault();
        this.saveButton.disabled = true;
        this.saveSpinner.style.display = 'block';

        const formData = new FormData(this.form);
        const data = Object.fromEntries(formData.entries());
        
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/events/create`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            if (response.success) {
                showToast(response.message, 'success');
                this.form.reset();
                this.loadTeacherEvents();
            }
        } catch (error) {
            console.error("Fehler beim Speichern des Events:", error);
        } finally {
            this.saveButton.disabled = false;
            this.saveSpinner.style.display = 'none';
        }
    }

    async handleDeleteEvent(eventId, eventTitle) {
        if (!eventId) return;

        if (await showConfirm("Eintrag löschen", `Möchten Sie den Eintrag "${eventTitle}" wirklich löschen?`)) {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/events/delete`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ event_id: eventId })
                });

                if (response.success) {
                    showToast(response.message, 'success');
                    this.loadTeacherEvents();
                }
            } catch (error) {
                console.error("Fehler beim Löschen des Events:", error);
            }
        }
    }
}

/**
 * NEU: Klasse zur Verwaltung der Sprechstunden-Logik im Cockpit
 */
class OfficeHoursController {
    constructor(containerElement) {
        this.container = containerElement;
        this.form = document.getElementById('office-hours-form');
        this.listContainer = document.getElementById('teacher-office-hours-list');
        this.saveButton = document.getElementById('save-office-hours-btn');
        this.saveSpinner = document.getElementById('office-hours-save-spinner');
    }

    async initialize() {
        if (!this.form) return;
        this.bindEvents();
        await this.loadOfficeHours();
    }

    bindEvents() {
        this.form.addEventListener('submit', (e) => this.handleSave(e));
        
        this.listContainer.addEventListener('click', (e) => {
            const deleteButton = e.target.closest('.delete-office-hour-btn');
            if (deleteButton) {
                const availabilityId = deleteButton.dataset.id;
                this.handleDelete(availabilityId);
            }
        });
    }

    async loadOfficeHours() {
        this.listContainer.innerHTML = '<div class="loading-spinner small"></div>';
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/office-hours`);
            if (response.success && response.data) {
                this.renderList(response.data);
            } else {
                throw new Error(response.message || "Sprechzeiten konnten nicht geladen werden.");
            }
        } catch (error) {
            console.error("Fehler beim Laden der Sprechzeiten:", error);
            this.listContainer.innerHTML = `<p class="message error small">${escapeHtml(error.message)}</p>`;
        }
    }

    renderList(availabilities) {
        if (availabilities.length === 0) {
            this.listContainer.innerHTML = '<p class="message info small" style="margin: 0;">Keine Sprechzeitenfenster definiert.</p>';
            return;
        }
        
        this.listContainer.innerHTML = availabilities.map(av => `
            <div class="office-hour-item" data-id="${av.availability_id}">
                <span>
                    <strong>${escapeHtml(formatDayOfWeek(av.day_of_week))}</strong>, 
                    ${escapeHtml(formatShortTime(av.start_time))} - ${escapeHtml(formatShortTime(av.end_time))} Uhr 
                    (${escapeHtml(av.slot_duration)} Min. Slots)
                </span>
                <button class="btn btn-danger btn-small delete-office-hour-btn" 
                        title="Fenster löschen" 
                        data-id="${av.availability_id}">
                    &times;
                </button>
            </div>
        `).join('');
    }

    async handleSave(e) {
        e.preventDefault();
        this.saveButton.disabled = true;
        this.saveSpinner.style.display = 'block';

        const formData = new FormData(this.form);
        const data = Object.fromEntries(formData.entries());

        try {
            // Validierung: Startzeit muss vor Endzeit liegen
            if (data.start_time >= data.end_time) {
                throw new Error("Startzeit muss vor der Endzeit liegen.");
            }

            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/office-hours/save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            if (response.success) {
                showToast(response.message, 'success');
                this.form.reset();
                this.loadOfficeHours(); // Liste neu laden
            }
        } catch (error) {
            console.error("Fehler beim Speichern der Sprechzeit:", error);
            showToast(error.message, 'error'); // Zeige Fehler als Toast
        } finally {
            this.saveButton.disabled = false;
            this.saveSpinner.style.display = 'none';
        }
    }

    async handleDelete(availabilityId) {
        if (!availabilityId) return;

        if (await showConfirm("Sprechzeit löschen", `Möchten Sie dieses Zeitfenster wirklich löschen? Zukünftige Termine in diesem Fenster werden möglicherweise mit entfernt.`)) {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/office-hours/delete`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ availability_id: availabilityId })
                });

                if (response.success) {
                    showToast(response.message, 'success');
                    this.loadOfficeHours(); // Liste neu laden
                }
            } catch (error) {
                console.error("Fehler beim Löschen der Sprechzeit:", error);
            }
        }
    }
}