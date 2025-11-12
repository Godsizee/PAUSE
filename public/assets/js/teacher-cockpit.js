import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

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

let attendanceController = null;

export function initializeTeacherCockpit() {
    const cockpit = document.getElementById('teacher-cockpit');
    if (!cockpit || window.APP_CONFIG.userRole !== 'lehrer') {
        return; 
    }

    // --- Kollegensuche ---
    const searchInput = document.getElementById('colleague-search-input');
    const searchResults = document.getElementById('colleague-search-results');
    const resultDisplay = document.getElementById('colleague-result-display');
    const resultSpinner = resultDisplay.querySelector('.loading-spinner');
    const resultParagraph = resultDisplay.querySelector('p');
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
        }, 300); 
    };

    const handleResultClick = async (e) => {
        const item = e.target.closest('.search-result-item');
        if (!item || !item.dataset.id) return;
        
        selectedTeacherId = item.dataset.id;
        const selectedName = item.dataset.name;
        
        searchInput.value = selectedName; 
        searchResults.innerHTML = ''; 
        searchResults.classList.remove('visible');

        resultDisplay.style.display = 'flex';
        if(resultSpinner) resultSpinner.style.display = 'block';
        if(resultParagraph) resultParagraph.innerHTML = `Suche nach ${escapeHtml(selectedName)}...`;

        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/find-colleague?teacher_id=${selectedTeacherId}`);
            if (response.success) {
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
        
        document.addEventListener('click', (e) => {
            if (!findColleagueFeature.contains(e.target)) {
                searchResults.classList.remove('visible');
            }
        });
    }

    // --- Anwesenheit ---
    const attendanceContainer = document.getElementById('attendance-feature');
    if (attendanceContainer) {
        attendanceController = new AttendanceController(attendanceContainer);
        attendanceController.loadCurrentLesson();
    }

    // --- Aufgaben & Klausuren ---
    const eventsContainer = document.getElementById('academic-events-feature');
    if (eventsContainer) {
        const eventsController = new AcademicEventsController(eventsContainer);
        eventsController.initialize();
    }
    
    // --- Sprechzeiten ---
    const officeHoursContainer = document.getElementById('office-hours-feature');
    if (officeHoursContainer) {
        const officeHoursController = new OfficeHoursController(officeHoursContainer);
        officeHoursController.initialize();
    }
}

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
                let icon = 'ℹ️'; 
                if (event.event_type === 'klausur') icon = '🎓'; 
                if (event.event_type === 'aufgabe') icon = '📚'; 
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

class OfficeHoursController {
    constructor(containerElement) {
        this.container = containerElement;
        this.form = document.getElementById('office-hours-form');
        this.listContainer = document.getElementById('teacher-office-hours-list');
        this.saveButton = document.getElementById('save-office-hours-btn');
        this.saveSpinner = document.getElementById('office-hours-save-spinner');
        
        // NEU: Elemente für den "Gebucht"-Tab
        this.innerTabButtons = this.container.querySelectorAll('.inner-tabs .tab-button');
        this.innerTabContents = this.container.querySelectorAll('.inner-tab-content .dashboard-section');
        this.bookedListContainer = document.getElementById('booked-appointments-list-container');
        this.bookedCountSpan = document.getElementById('booked-appointments-count');
        this.bookedListLoaded = false;
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

        // NEU: Tab-Logik binden
        this.innerTabButtons.forEach(button => {
            button.addEventListener('click', () => this.handleInnerTabClick(button));
        });

        // NEU: Klick-Handler für Stornieren-Buttons in der "Gebucht"-Liste
        this.bookedListContainer.addEventListener('click', (e) => {
            const cancelButton = e.target.closest('.cancel-appointment-btn');
            if (cancelButton) {
                const appointmentId = cancelButton.dataset.id;
                const item = cancelButton.closest('.booked-appointment-item');
                const title = item ? item.querySelector('.appointment-student-name').textContent : 'diesen Termin';
                this.handleCancelBookedAppointment(appointmentId, title, item);
            }
        });
    }

    // NEU: Logik zum Umschalten der inneren Tabs
    handleInnerTabClick(button) {
        this.innerTabButtons.forEach(btn => btn.classList.remove('active'));
        this.innerTabContents.forEach(content => content.classList.remove('active'));
        
        button.classList.add('active');
        const targetId = button.dataset.target;
        const targetContent = document.getElementById(targetId);
        
        if (targetContent) {
            targetContent.classList.add('active');
        }

        // Lade die gebuchten Termine, wenn der Tab zum ersten Mal aktiviert wird
        if (targetId === 'office-hours-booked-view' && !this.bookedListLoaded) {
            this.loadBookedAppointments();
        }
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

    // NEU: Lädt die gebuchten Termine
    async loadBookedAppointments() {
        if (this.bookedListLoaded) return; 
        this.bookedListLoaded = true; // Verhindert doppeltes Laden
        this.bookedListContainer.innerHTML = '<div class="loading-spinner"></div>';
        
        try {
            // API-Endpunkt (sortiert nach 'ASC' für Zukunft)
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/all-appointments?sort=ASC`);
            
            if (response.success && response.data) {
                // Die API gibt 'future' und 'past' zurück. Wir wollen nur 'future'.
                const futureAppointments = response.data.future || [];
                this.renderBookedAppointmentsList(futureAppointments);
            } else {
                throw new Error(response.message || "Gebuchte Termine konnten nicht geladen werden.");
            }
        } catch (error) {
            console.error("Fehler beim Laden der gebuchten Termine:", error);
            this.bookedListContainer.innerHTML = `<p class="message error">${escapeHtml(error.message)}</p>`;
            this.bookedListLoaded = false; // Erlaube erneuten Ladeversuch
        }
    }

    renderList(availabilities) {
        if (availabilities.length === 0) {
            this.listContainer.innerHTML = '<p class="message info small" style="margin: 0; text-align: center;">Keine Sprechzeitenfenster definiert.</p>';
            return;
        }

        this.listContainer.innerHTML = availabilities.map(av => `
            <div class="office-hour-item" data-id="${av.availability_id}">
                <div class="office-hour-details">
                    <span class="office-hour-day-badge">${escapeHtml(formatDayOfWeek(av.day_of_week).substring(0, 2))}</span>
                    <div class="office-hour-time-info">
                        <strong>${escapeHtml(formatShortTime(av.start_time))} - ${escapeHtml(formatShortTime(av.end_time))} Uhr</strong>
                        <span>${escapeHtml(av.location)} • (${escapeHtml(av.slot_duration)} Min. Slots)</span>
                    </div>
                </div>
                <button class="btn btn-danger btn-small delete-office-hour-btn" 
                        title="Fenster löschen" 
                        data-id="${av.availability_id}">
                    &times;
                </button>
            </div>
        `).join('');
    }

    // NEU: Rendert die Liste der gebuchten Termine
    renderBookedAppointmentsList(appointments) {
        if (this.bookedCountSpan) {
            this.bookedCountSpan.textContent = `(${appointments.length})`;
        }

        if (appointments.length === 0) {
            this.bookedListContainer.innerHTML = '<p class="message info" style="margin: 0; text-align: center;">Keine zukünftigen Termine gebucht.</p>';
            return;
        }

        // Wir zeigen nur zukünftige Termine an. Die API liefert bereits vorsortiert (ASC).
        const now = new Date();
        // Setzt die Zeit auf Mitternacht, um heutige Termine einzuschließen
        now.setHours(0, 0, 0, 0); 
        
        const futureAppointments = appointments.filter(app => {
            const appDate = new Date(app.appointment_date);
            return appDate >= now;
        });

        if (this.bookedCountSpan) {
             this.bookedCountSpan.textContent = `(${futureAppointments.length})`;
        }

        if (futureAppointments.length === 0) {
             this.bookedListContainer.innerHTML = '<p class="message info" style="margin: 0; text-align: center;">Keine zukünftigen Termine gebucht.</p>';
             return;
        }

        this.bookedListContainer.innerHTML = futureAppointments.map(app => {
            const date = formatGermanDate(app.appointment_date);
            const time = formatShortTime(app.appointment_time);
            const student = escapeHtml(app.student_name);
            const className = escapeHtml(app.class_name) || 'N/A';
            const classId = escapeHtml(app.class_id) || 'N/A';
            const location = escapeHtml(app.location) || 'N/A';
            const notes = app.notes ? escapeHtml(app.notes) : 'Keine Notizen';

            return `
            <div class="booked-appointment-item" data-id="${app.appointment_id}">
                <div class="appointment-time-info">
                    <span class="appointment-date">${date}</span>
                    <span class="appointment-time">${time} Uhr</span>
                </div>
                <div class="appointment-details">
                    <strong class="appointment-student-name">${student}</strong>
                    <span class="appointment-class">Klasse: ${className} (ID: ${classId})</span>
                    <span class="appointment-location">Ort: ${location}</span>
                </div>
                <div class="appointment-notes">
                    <strong>Notiz des Schülers:</strong>
                    <p>${notes}</p>
                </div>
                <div class="appointment-actions">
                    <button class="btn btn-danger btn-small cancel-appointment-btn" data-id="${app.appointment_id}" title="Termin stornieren">
                        Stornieren
                    </button>
                </div>
            </div>
            `;
        }).join('');
    }

    async handleSave(e) {
        e.preventDefault();
        this.saveButton.disabled = true;
        this.saveSpinner.style.display = 'block';

        const formData = new FormData(this.form);
        const data = Object.fromEntries(formData.entries());

        try {
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
                // Set default slot duration back
                this.form.querySelector('#office-slot-duration').value = 15;
                this.loadOfficeHours(); 
            }
        } catch (error) {
            console.error("Fehler beim Speichern der Sprechzeit:", error);
            showToast(error.message, 'error'); 
        } finally {
            this.saveButton.disabled = false;
            this.saveSpinner.style.display = 'none';
        }
    }

    async handleDelete(availabilityId) {
        if (!availabilityId) return;

        if (await showConfirm("Sprechzeit löschen", `Möchten Sie dieses Zeitfenster wirklich löschen? Zukünftige Termine in diesem Fenster werden ebenfalls entfernt.`)) {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/office-hours/delete`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ availability_id: availabilityId })
                });

                if (response.success) {
                    showToast(response.message, 'success');
                    this.loadOfficeHours(); 
                }
            } catch (error) {
                console.error("Fehler beim Löschen der Sprechzeit:", error);
            }
        }
    }
    
    // NEU: Storniert einen gebuchten Termin
    async handleCancelBookedAppointment(appointmentId, title, itemElement) {
        if (await showConfirm("Termin stornieren", `Möchten Sie den Termin mit "${escapeHtml(title)}" wirklich stornieren?`)) {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/appointment/cancel`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ appointment_id: appointmentId })
                });
                if (response.success) {
                    showToast(response.message, 'success');
                    // Element aus der Liste entfernen
                    if (itemElement) {
                        itemElement.style.transition = 'opacity 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                        itemElement.style.opacity = '0';
                        itemElement.style.height = '0px';
                        itemElement.style.paddingTop = '0';
                        itemElement.style.paddingBottom = '0';
                        itemElement.style.margin = '0';
                        setTimeout(() => {
                            itemElement.remove();
                            // "Mein Tag" Tab neu laden, falls der Termin dort angezeigt wurde
                            if(window.dashboardApp && typeof window.dashboardApp.loadAndRenderWeeklyData === 'function') {
                                // Wir rufen die Haupt-Datenladefunktion auf, da diese "Mein Tag" aktualisiert
                                window.dashboardApp.loadAndRenderWeeklyData(false); // false = Ansicht nicht zurücksetzen
                            }
                            // Zähler aktualisieren
                            const remaining = this.bookedListContainer.querySelectorAll('.booked-appointment-item').length;
                            if (this.bookedCountSpan) this.bookedCountSpan.textContent = `(${remaining})`;
                            if (remaining === 0) {
                                this.renderBookedAppointmentsList([]); // "Leer"-Nachricht anzeigen
                            }
                        }, 300);
                    } else {
                         this.loadBookedAppointments(); // Fallback: Liste neu laden
                    }
                }
            } catch (error) {
                console.error("Fehler beim Stornieren:", error);
            }
        }
    }
}