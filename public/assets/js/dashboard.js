// public/assets/js/dashboard.js
import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml } from './planer-utils.js';
// NEU: Importiere die Lazy-Loading-Funktion für "Meine Beiträge"
import { initializeMyCommunityPosts } from './dashboard-my-posts.js';


/**
 * Helper function to get week and year for a date according to ISO 8601.
 * @param {Date} date - The date object.
 * @returns {{week: number, year: number}} - Calendar week and year.
 */
function getWeekAndYear(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    return { week: weekNo, year: d.getUTCFullYear() };
}

/**
 * Gets the date of the Monday of a given calendar week and year.
 * @param {number} week - The calendar week.
 * @param {number} year - The year.
 * @returns {Date} The Date object for Monday (local time).
*/
function getDateOfISOWeek(week, year) {
    const simple = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
    const dow = simple.getUTCDay();
    const ISOweekStart = simple;
    ISOweekStart.setUTCDate(simple.getUTCDate() - (dow || 7) + 1);
    return new Date(ISOweekStart.getUTCFullYear(), ISOweekStart.getUTCMonth(), ISOweekStart.getUTCDate());
}

/**
 * Formats a time slot index (1-based) into HH:MM (start time).
 * @param {number} period - The period number (1 to 10).
 * @returns {string} Formatted time string like "08:00".
 */
function formatTimeSlot(period) {
    const times = [
        "08:00", "08:55", "09:40", "10:35", "11:20",
        "13:05", "13:50", "14:45", "15:30", "16:25"
    ];
    return times[period - 1] || '??:??';
}

/**
 * Formats a YYYY-MM-DD Datum in ein lesbares deutsches Format.
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
    return timeString;
}

// NEU: Globaler State für Dashboard-Daten (Stammdaten, Pläne)
const dashboardState = {
    stammdaten: null,
    currentTimetable: [],
    currentSubstitutions: [],
    studentNotes: {}, // NEU: Objekt für Notizen
    currentPublishStatus: { student: false, teacher: false }
    // currentViewMode, selectedClassId, etc. werden in planer-state.js verwaltet,
    // aber wir brauchen sie hier nicht, da das Dashboard seine eigene Logik hat.
};

// --- Module-Level Constants (MOVED HERE) ---
const days = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag"];
const timeSlotsDisplay = [
     "08:00 - 08:45", "08:55 - 09:40", "09:40 - 10:25", "10:35 - 11:20",
     "11:20 - 12:05", "13:05 - 13:50", "13:50 - 14:35", "14:45 - 15:30",
     "15:30 - 16:15", "16:25 - 17:10"
];
// --- Module-Level DOM Elements & Constants (MOVED HERE) ---
const userRole = window.APP_CONFIG.userRole;
const today = new Date();
const todayDateString = today.toISOString().split('T')[0];
const todayDayOfWeek = (today.getDay() === 0) ? 7 : today.getDay(); // 1=Mo

// --- DOM Elements (defined at module scope) ---
const yearSelector = document.getElementById('year-selector');
const weekSelector = document.getElementById('week-selector');
const planHeaderInfo = document.getElementById('plan-header-info');
const timetableContainer = document.getElementById('timetable-container');
const announcementsList = document.getElementById('announcements-list');
const todayScheduleContainer = document.getElementById('today-schedule-container');
const icalUrlInput = document.getElementById('ical-url');
const copyIcalUrlButton = document.getElementById('copy-ical-url');
const pdfButton = document.getElementById('export-pdf-btn');
const printableSection = document.getElementById('weekly-timetable-section-printable');
const detailModal = document.getElementById('plan-detail-modal');
const detailCloseBtn = document.getElementById('plan-detail-close-btn');
const noteRow = document.getElementById('detail-notes-row');
const noteInput = document.getElementById('detail-notes-input');
const noteSaveBtn = document.getElementById('plan-detail-save-note-btn');
const noteSpinner = document.getElementById('note-save-spinner');

/**
 * Loads and renders announcements into the sidebar, now using content_html.
 */
const loadAnnouncements = async () => {
    if (!announcementsList) return;
    announcementsList.innerHTML = '<div class="loading-spinner"></div>';
    try {
        const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/announcements`);
        if (response.success && response.data) {
            if (response.data.length === 0) {
                announcementsList.innerHTML = '<p class="message info" style="padding: 20px; margin: 0; text-align: center;">Keine aktuellen Ankündigungen.</p>';
                return;
            }
            announcementsList.style.padding = '0';
            announcementsList.innerHTML = response.data.map(item => {
                let targetInfo = '';
                let visibilityText = item.is_global ? 'Global' : 'Klasse';
                let badgeClass = item.is_global ? 'global' : 'class';
                if (!item.is_global && item.target_class_name) {
                    targetInfo = ` (Klasse: ${escapeHtml(item.target_class_name)})`;
                }

                const attachmentLink = item.file_url
                    ? `<p class="announcement-attachment"><a href="${escapeHtml(item.file_url)}" target="_blank" download>📎 Anhang herunterladen</a></p>`
                    : '';

                const contentHtml = item.content_html || '<p><em>Kein Inhalt.</em></p>';

                return `
                <div class="announcement-item">
                    <div class="announcement-header">
                        <div class="announcement-title-meta">
                            <strong>${escapeHtml(item.title)}</strong> <small>Von ${escapeHtml(item.author_name)}${targetInfo} • ${new Date(item.created_at).toLocaleDateString('de-DE')}</small>
                        </div>
                        <span class="announcement-badge ${badgeClass}">${visibilityText}</span>
                    </div>
                    <div class="announcement-content"> ${contentHtml} </div>
                    ${attachmentLink}
                </div>
                `;
            }).join('');
        } else {
            throw new Error(response.message || "Ankündigungen konnten nicht geladen werden.");
        }
    } catch (error) {
        console.error("Announcement loading error:", error);
        announcementsList.innerHTML = `<p class="message error" style="margin: 20px;">${error.message || 'Ankündigungen konnten nicht geladen werden.'}</p>`;
    }
};

/**
 * Populates year and week selector dropdowns.
 */
const populateSelectors = () => {
    if (!yearSelector || !weekSelector) return; 
    const currentYear = new Date().getFullYear();
    let yearOptions = '';
    for (let i = currentYear - 1; i <= currentYear + 1; i++) {
        yearOptions += `<option value="${i}">${i}</option>`;
    }
    yearSelector.innerHTML = yearOptions;

    let weekOptions = '';
    for (let i = 1; i <= 53; i++) {
        weekOptions += `<option value="${i}">KW ${i}</option>`;
    }
    weekSelector.innerHTML = weekOptions;

    const { week, year } = getWeekAndYear(today);
    yearSelector.value = year;
    weekSelector.value = week;
};

/**
 * Loads all data for the selected week and renders both weekly and daily views.
 */
const loadAndRenderWeeklyData = async () => {
    if (!yearSelector || !weekSelector) return; 
    
    const year = yearSelector.value;
    const week = weekSelector.value;

    timetableContainer.innerHTML = '<div class="loading-spinner"></div>';
    if (todayScheduleContainer) {
        todayScheduleContainer.innerHTML = '<div class="loading-spinner small"></div>';
    }

    const monday = getDateOfISOWeek(Number(week), Number(year));
    const friday = new Date(monday.getTime() + 4 * 24 * 60 * 60 * 1000);
    planHeaderInfo.textContent = `Stundenplan für die ${week}. Kalenderwoche (${monday.toLocaleDateString('de-DE')} - ${friday.toLocaleDateString('de-DE')})`;

    let timetable = [];
    let substitutions = [];
    let academicEvents = [];
    let appointments = [];
    let studentNotes = {}; // NEU: Notizen hier empfangen

    try {
        // --- Schritt 1: Plandaten & Termine (Kritisch) ---
        const planUrl = `${window.APP_CONFIG.baseUrl}/api/dashboard/weekly-data?year=${year}&week=${week}`;
        const planResponse = await apiFetch(planUrl);
        
        if (!planResponse.success || !planResponse.data) {
            throw new Error(planResponse.message || "Plandaten konnten nicht geladen werden.");
        }

        timetable = planResponse.data.timetable || [];
        substitutions = planResponse.data.substitutions || [];
        appointments = planResponse.data.appointments || [];
        studentNotes = (userRole === 'schueler') ? (planResponse.data.studentNotes || {}) : {}; // NEU

        
        // NEU: Daten im globalen State speichern
        dashboardState.currentTimetable = timetable;
        dashboardState.currentSubstitutions = substitutions;
        dashboardState.studentNotes = studentNotes; // NEU

        // --- Schritt 2: Wochenplan sofort rendern ---
        renderWeeklyTimetable(timetable, substitutions, studentNotes); // NEU: Notizen übergeben

        // --- Schritt 3: Events (Zusätzlich, nur für Schüler) ---
        if (userRole === 'schueler') {
            try {
                const eventsUrl = `${window.APP_CONFIG.baseUrl}/api/student/events?year=${year}&week=${week}`;
                const eventsResponse = await apiFetch(eventsUrl);
                if (eventsResponse.success && eventsResponse.data) {
                    academicEvents = eventsResponse.data;
                } else {
                    console.warn("Zusatz-Events (Aufgaben/Klausuren) konnten nicht geladen werden:", eventsResponse.message || "Unbekannter Fehler");
                }
            } catch (eventError) {
                console.error("Fehler beim Laden der Events:", eventError);
            }
        }

        // --- Schritt 4: "Mein Tag" rendern (mit Plandaten, Events UND Terminen) ---
        renderTodaySchedule(timetable, substitutions, academicEvents, appointments, studentNotes); // NEU: Notizen übergeben

    } catch (error) { 
        console.error("Fehler beim Laden der Wochendaten (kritisch):", error);
        timetableContainer.innerHTML = `<p class="message error">${error.message || 'Der Wochenplan konnte nicht geladen werden.'}</p>`;
        if (todayScheduleContainer) {
            todayScheduleContainer.innerHTML = `<p class="message error small">Heutiger Plan nicht verfügbar.</p>`;
        }
    }
};

/**
 * Extracts and renders today's schedule into the "Mein Tag" container.
 */
const renderTodaySchedule = (weeklyTimetable, weeklySubstitutions, academicEvents, appointments, studentNotes) => {
    if (!todayScheduleContainer) return;

    const currentDayNum = todayDayOfWeek;
    if (currentDayNum < 1 || currentDayNum > 5) {
        todayScheduleContainer.innerHTML = '<p class="message info" style="padding: 10px; margin: 0;">Heute ist kein Schultag. Genieße die freie Zeit! 🎉</p>';
        return;
    }

    // A. Ermittle die aktuelle Zeit in HHMM (z.B. 1030 für 10:30)
    const PERIOD_END_TIMES = [
        845, 940, 1025, 1120, 1205, // Vormittag
        1350, 1435, 1530, 1615, 1710  // Nachmittag (basierend auf Standard-Definition)
    ];
    const now = new Date();
    // Hole die Zeit in der korrekten Zeitzone (Europe/Berlin)
    const timeFormatter = new Intl.DateTimeFormat('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin', 
        hour12: false
    });
    const parts = timeFormatter.format(now).split(':'); // z.B. ["11", "21"]
    const currentHHMM = parseInt(parts[0], 10) * 100 + parseInt(parts[1], 10); // z.B. 1121


    const todaysEntries = weeklyTimetable.filter(entry => entry.day_of_week == currentDayNum);
    const todaysSubstitutions = weeklySubstitutions.filter(sub => sub.date === todayDateString);
    const todaysEvents = (academicEvents || []).filter(event => event.due_date === todayDateString);
    const todaysAppointments = (appointments || []).filter(app => app.appointment_date === todayDateString);

    let combinedSchedule = [];

    // 1. Reguläre Einträge und Vertretungen
    for (let period = 1; period <= timeSlotsDisplay.length; period++) {
        const periodEndTime = PERIOD_END_TIMES[period - 1]; // z.B. 845

        // *** NEUE FILTERLOGIK: Überspringe, wenn die Stunde vorbei ist ***
        if (currentHHMM > periodEndTime) {
            continue; 
        }
        // *** ENDE NEUE LOGIK ***

        const substitution = todaysSubstitutions.find(sub => sub.period_number === period);
        const regularEntry = todaysEntries.find(entry => entry.period_number === period);
        const noteKey = `${todayDayOfWeek}-${period}`; // NEU
        const note = studentNotes[noteKey] || ''; // NEU

        if (substitution) {
            combinedSchedule.push({
                sortKey: period * 10,
                period: period,
                time: formatTimeSlot(period),
                type: substitution.substitution_type,
                id: substitution.substitution_id, // ID für ICS-Link (Sonderevent)
                subject: substitution.new_subject_shortcut || regularEntry?.subject_shortcut || (substitution.substitution_type === 'Sonderevent' ? 'EVENT' : '---'),
                mainText: substitution.substitution_type === 'Vertretung'
                    ? (userRole === 'teacher' ? (substitution.class_name || regularEntry?.class_name) : substitution.new_teacher_shortcut)
                    : (substitution.substitution_type === 'Entfall' ? '' : (regularEntry ? (userRole === 'schueler' ? regularEntry.teacher_shortcut : regularEntry.class_name) : '')),
                room: substitution.new_room_name || regularEntry?.room_name || '',
                comment: substitution.comment || '',
                note: note, // NEU
                icsType: (substitution.substitution_type === 'Sonderevent') ? 'sub' : null // ICS-Typ für Sonderevent
            });
        } else if (regularEntry) {
            combinedSchedule.push({
                sortKey: period * 10,
                period: period,
                time: formatTimeSlot(period),
                type: 'regular',
                id: regularEntry.entry_id,
                subject: regularEntry.subject_shortcut || '---',
                mainText: userRole === 'schueler' ? regularEntry.teacher_shortcut : regularEntry.class_name,
                room: regularEntry.room_name || '---',
                comment: regularEntry.comment || '',
                note: note, // NEU
                icsType: null
            });
        }
    }
    
    // 2. Klausuren/Aufgaben (Ganztägig - werden immer angezeigt)
    todaysEvents.forEach(event => {
        combinedSchedule.push({
            sortKey: 1, // Ganztägig, an den Anfang
            time: 'Ganztägig',
            type: event.event_type,
            subject: event.title,
            mainText: event.subject_shortcut || (userRole === 'schueler' ? `${event.teacher_first_name} ${event.teacher_last_name}` : ''),
            room: '',
            comment: event.description || '',
            note: '', // NEU (Keine Notizen für Events)
            id: event.event_id, // ID für ICS-Link
            icsType: 'acad' // Typ für ICS-Link
        });
    });

    // 3. Sprechstunden (Termine)
    todaysAppointments.forEach(app => {
        const appTime = formatShortTime(app.appointment_time);
        const sortKeyTime = parseInt(appTime.replace(':', ''), 10); // z.B. 1400

        // *** NEUE FILTERLOGIK für Termine (Sprechstunden) ***
        const timeParts = app.appointment_time.split(':'); // "14:00:00"
        const duration = parseInt(app.duration, 10) || 15; // z.B. 15
        
        if (timeParts.length >= 2) {
            const startH = parseInt(timeParts[0], 10);
            const startM = parseInt(timeParts[1], 10);
            
            // Simuliere das Enddatum
            const endM = startM + duration; // z.B. 0 + 15 = 15
            const endH = startH + Math.floor(endM / 60); // z.B. 14 + 0 = 14
            const finalEndM = endM % 60; // z.B. 15
            
            const endHHMM = (endH * 100) + finalEndM; // z.B. 1400 + 15 = 1415

            if (currentHHMM > endHHMM) {
                return; // Dieser Termin ist vorbei, überspringe ihn
            }
        }
        // *** ENDE NEUE LOGIK ***

        combinedSchedule.push({
            sortKey: sortKeyTime,
            time: appTime,
            type: 'appointment',
            subject: userRole === 'schueler' ? `Sprechstunde (${escapeHtml(app.teacher_shortcut || app.teacher_name)})` : `Sprechstunde (${escapeHtml(app.student_name)})`,
            mainText: userRole === 'lehrer' ? (app.class_name ? `Klasse: ${escapeHtml(app.class_name)}` : 'Schüler') : '',
            room: 'Sprechzimmer',
            comment: app.notes || '',
            note: '', // NEU (Keine Notizen für Termine)
            id: app.appointment_id,
            icsType: null 
        });
    });

    combinedSchedule.sort((a, b) => a.sortKey - b.sortKey);

    if (combinedSchedule.length === 0) {
        todayScheduleContainer.innerHTML = '<p class="message info" style="padding: 10px; margin: 0;">Für heute sind keine Einträge (mehr) vorhanden.</p>';
        return;
    }

    // SVG-Icon für den Kalender-Download
    const icsIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2M2 1a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1H2zM14 15H2a1 1 0 0 1-1-1V5h14v9a1 1 0 0 1-1 1M9 7.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zM6.5 9a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 0 1h-4a.5.5 0 0 1-.5-.5m-3 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5z"/></svg>`;
    // NEU: SVG-Icon für Notiz
    const noteIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 0A1.5 1.5 0 0 0 0 1.5V13a1 1 0 0 0 1 1V1.5a.5.5 0 0 1 .5-.5H14a1 1 0 0 0-1-1zM3.5 2A1.5 1.5 0 0 0 2 3.5v11A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 12.5 2zM3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5z"/></svg>`;


    todayScheduleContainer.innerHTML = combinedSchedule.map(item => {
        const typeClass = `type-${item.type.replace(' ', '')}`;
        
        // NEU: Kombiniere Kommentar und Notiz
        let commentHtml = '';
        if (item.comment) {
            commentHtml += `<small class="entry-comment" title="${escapeHtml(item.comment)}">📝 ${escapeHtml(item.comment)}</small>`;
        }
        if (item.note) {
            commentHtml += `<small class="entry-note" title="${escapeHtml(item.note)}">${noteIcon} ${escapeHtml(item.note)}</small>`;
        }

        
        let detailsHtml = `<strong>${escapeHtml(item.subject)}</strong>`;
        if(item.mainText && item.type !== 'Entfall') detailsHtml += `<span>${escapeHtml(item.mainText)}</span>`;
        if(item.room && item.type !== 'Entfall') detailsHtml += `<span>${escapeHtml(item.room)}</span>`;

        let actionButton = '';
        if (item.type === 'appointment') {
            actionButton = `<button class="btn btn-danger btn-small cancel-appointment-btn" data-id="${item.id}" title="Termin stornieren">&times;</button>`;
        }

        let icsButtonHtml = '';
        if (item.icsType === 'acad') { // Für Aufgaben, Klausuren, Infos
            const icsUrl = `${window.APP_CONFIG.baseUrl}/ics/event/acad/${item.id}`;
            icsButtonHtml = `<a href="${icsUrl}" class="btn-ics" title="Zum Kalender hinzufügen">${icsIcon}</a>`;
        } else if (item.icsType === 'sub') { // Für Sonderevents
            const icsUrl = `${window.APP_CONFIG.baseUrl}/ics/event/sub/${item.id}`;
            icsButtonHtml = `<a href="${icsUrl}" class="btn-ics" title="Zum Kalender hinzufügen">${icsIcon}</a>`;
        }

        return `
        <div class="today-entry ${typeClass}">
            <div class="time">${escapeHtml(item.time)}</div>
            <div class="details">
                ${detailsHtml}
                ${commentHtml}
            </div>
            <div class="entry-actions">
                <span class="type-badge ${typeClass}">${item.type === 'regular' ? 'Plan' : (item.type === 'klausur' ? 'Klausur' : (item.type === 'aufgabe' ? 'Aufgabe' : (item.type === 'info' ? 'Info' : (item.type === 'appointment' ? 'Termin' : item.type))))}</span>
                ${icsButtonHtml}
                ${actionButton}
            </div>
        </div>
        `;
    }).join('');

    todayScheduleContainer.querySelectorAll('.cancel-appointment-btn').forEach(btn => {
        btn.addEventListener('click', handleCancelAppointment);
    });
};

/**
 * Renders the weekly timetable grid.
 */
const renderWeeklyTimetable = (weeklyTimetableData, allWeeklySubstitutions, studentNotes) => {
    const processedCellKeys = new Set();
    const blockSpans = new Map();

    // 1. Reguläre Blöcke
    const blocks = new Map();
    weeklyTimetableData.forEach(entry => {
        if (entry.block_id) {
            if (!blocks.has(entry.block_id)) blocks.set(entry.block_id, []);
            blocks.get(entry.block_id).push(entry);
        }
    });
    blocks.forEach(entries => {
        if (entries.length === 0) return;
        entries.sort((a, b) => a.period_number - b.period_number);
        const startEntry = entries[0];
        const span = entries[entries.length - 1].period_number - startEntry.period_number + 1;
        blockSpans.set(`${startEntry.day_of_week}-${startEntry.period_number}`, span);
        for (let i = 1; i < span; i++) {
            processedCellKeys.add(`${startEntry.day_of_week}-${startEntry.period_number + i}`);
        }
    });

    // 2. Vertretungs-Blöcke
    const substitutionBlocks = new Map();
    allWeeklySubstitutions.forEach(sub => {
        if (!sub.day_of_week) return;
        const key = `${sub.date}-${sub.class_id}-${sub.substitution_type}-${sub.comment || ''}-${sub.new_room_id || ''}-${sub.new_teacher_id || ''}-${sub.new_subject_id || ''}`;
        if (!substitutionBlocks.has(key)) substitutionBlocks.set(key, []);
        substitutionBlocks.get(key).push(sub);
    });
    substitutionBlocks.forEach(subs => {
        if (subs.length > 1) { 
            subs.sort((a, b) => a.period_number - b.period_number);
            let isConsecutive = true;
            for (let i = 0; i < subs.length - 1; i++) {
                if (subs[i + 1].period_number !== subs[i].period_number + 1) {
                    isConsecutive = false; break;
                }
            }
            if (isConsecutive) { 
                const startSub = subs[0];
                const span = subs.length;
                const dayNum = startSub.day_of_week;
                if (dayNum) {
                    blockSpans.set(`${dayNum}-${startSub.period_number}`, span);
                    for (let i = 1; i < span; i++) {
                        processedCellKeys.add(`${dayNum}-${startSub.period_number + i}`);
                    }
                }
            }
        }
    });

    // 3. Grid HTML rendern
    let gridHTML = '<div class="timetable-grid">';
    gridHTML += '<div class="grid-header">Zeit</div>';
    days.forEach(day => gridHTML += `<div class="grid-header">${day}</div>`);

    const settings = window.APP_CONFIG.settings || {};
    const startHour = parseInt(settings.default_start_hour, 10) || 1;
    const endHour = parseInt(settings.default_end_hour, 10) || 10;

    timeSlotsDisplay.forEach((slot, index) => {
        const period = index + 1;
        gridHTML += `<div class="grid-header period-header">${slot}</div>`;

        days.forEach((day, dayIndex) => {
            const dayNum = dayIndex + 1; // 1=Mon, ..., 5=Fri
            const cellKey = `${dayNum}-${period}`;
            const noteKey = cellKey; // NEU

            if (processedCellKeys.has(cellKey)) { return; }

            let cellContent = '', cellClass = 'empty', dataAttrs = `data-day="${dayNum}" data-period="${period}"`, style = '';
            const span = blockSpans.get(cellKey);
            if (span) {
                style = `grid-row: span ${span};`;
                cellClass += ' block-start';
            }

            const substitution = allWeeklySubstitutions.find(s => s.day_of_week == dayNum && s.period_number == period);
            const entryToRender = weeklyTimetableData.find(e => e.day_of_week == dayNum && e.period_number == period);
            const note = (userRole === 'schueler' && studentNotes[noteKey]) ? studentNotes[noteKey] : null; // NEU

            dataAttrs = `data-day="${dayNum}" data-period="${period}"`; // Basis-Attribute

            if (substitution) {
                cellClass = `has-entry substitution-${substitution.substitution_type}`;
                dataAttrs += ` data-substitution-id="${substitution.substitution_id}"`;
                if (substitution.comment) dataAttrs += ` data-comment="${escapeHtml(substitution.comment)}"`;
                const regularEntry = entryToRender; 
                if(regularEntry) { // Füge reguläre IDs hinzu, falls vorhanden
                    dataAttrs += ` data-entry-id="${regularEntry.entry_id}"`;
                    if (regularEntry.block_id) dataAttrs += ` data-block-id="${regularEntry.block_id}"`;
                }
                dataAttrs += ` data-class-id="${substitution.class_id}"`; 
                cellContent = createCellEntryHtml(
                    substitution.substitution_type === 'Vertretung'
                        ? (substitution.new_subject_shortcut || regularEntry?.subject_shortcut)
                        : (substitution.substitution_type === 'Sonderevent' ? 'EVENT' : regularEntry?.subject_shortcut),
                    substitution.substitution_type === 'Vertretung'
                        ? (userRole === 'teacher' ? (substitution.class_name || regularEntry?.class_name) : substitution.new_teacher_shortcut)
                        : (substitution.substitution_type === 'Entfall' ? 'Entfällt' : (regularEntry ? (userRole === 'schueler' ? regularEntry.teacher_shortcut : regularEntry.class_name) : '---')),
                    substitution.new_room_name || regularEntry?.room_name,
                    substitution.comment, 
                    substitution.substitution_type,
                    note // NEU
                );
            } else if (entryToRender) {
                cellClass = 'has-entry';
                dataAttrs += ` data-entry-id="${entryToRender.entry_id}"`;
                dataAttrs += ` data-class-id="${entryToRender.class_id}"`;
                if (entryToRender.block_id) dataAttrs += ` data-block-id="${entryToRender.block_id}"`;
                const mainText = userRole === 'schueler' ? entryToRender.teacher_shortcut : entryToRender.class_name;
                cellContent += createCellEntryHtml(entryToRender.subject_shortcut, mainText, entryToRender.room_name, entryToRender.comment, null, note); // NEU
            } else if (period >= startHour && period <= endHour) {
                // KORREKTUR: Logik für FU
                if (period === startHour || period === endHour) {
                    cellClass = 'default-entry';
                    cellContent = createCellEntryHtml('FU', 'Förderunterricht', '', ''); // FU anzeigen
                } else {
                    // Bleibt leer (cellClass = 'empty')
                }
            } else {
                // Außerhalb des Rasters (z.B. Stunde 11, 12)
                // Bleibt leer (cellClass = 'empty')
            }
            
            // NEU: Füge Notiz-Indikator hinzu, wenn Notiz vorhanden ist
            if (note) {
                cellClass += ' has-note';
            }

            gridHTML += `<div class="grid-cell ${cellClass}" ${dataAttrs} style="${style}">${cellContent}</div>`;
        });
    });

    gridHTML += '</div>';
    timetableContainer.innerHTML = gridHTML;
};

/**
 * Helper to create HTML content for a single cell entry.
 */
const createCellEntryHtml = (subject, mainText, room, comment = null, substitutionType = null, note = null) => {
    let commentHtml = comment ? `<small class="entry-comment" title="${escapeHtml(comment)}">📝 ${escapeHtml(comment.substring(0, 15))}${comment.length > 15 ? '...' : ''}</small>` : '';
    let roomHtml = room ? `<small class="entry-room">${escapeHtml(room)}</small>` : '';
    let mainHtml = mainText ? `<span>${escapeHtml(mainText)}</span>` : '';
    let subjectHtml = subject ? `<strong>${escapeHtml(subject)}</strong>` : '';
    // NEU: Notiz-Icon (SVG)
    const noteIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 0A1.5 1.5 0 0 0 0 1.5V13a1 1 0 0 0 1 1V1.5a.5.5 0 0 1 .5-.5H14a1 1 0 0 0-1-1zM3.5 2A1.5 1.5 0 0 0 2 3.5v11A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 12.5 2zM3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5z"/></svg>`;
    let noteHtml = (note && userRole === 'schueler') ? `<small class="entry-note" title="${escapeHtml(note)}">${noteIcon}</small>` : '';


    if (substitutionType === 'Entfall') {
        subjectHtml = `<strong>${escapeHtml(subject)}</strong>`;
        mainHtml = `<span>Entfällt</span>`;
        roomHtml = '';
        commentHtml = comment ? `<small class="entry-comment" title="${escapeHtml(comment)}">📝 ${escapeHtml(comment.substring(0, 15))}${comment.length > 15 ? '...' : ''}</small>` : '';
    }
    if (substitutionType === 'Raumänderung') {
        roomHtml = room ? `<small class="entry-room" style="font-weight:bold; color: var(--color-warning);">${escapeHtml(room)}</small>` : '';
    }
    if (substitutionType === 'Sonderevent') {
        subjectHtml = `<strong>EVENT</strong>`;
        const safeComment = escapeHtml(comment);
        mainHtml = safeComment ? `<span title="${safeComment}">${safeComment.substring(0, 20)}${safeComment.length > 20 ? '...' : ''}</span>` : `<span>Sonderveranst.</span>`;
        commentHtml = '';
    }

    // NEU: Setze Notiz-Icon in die Zelle
    return `<div class="cell-entry">${noteHtml}${subjectHtml}${mainHtml}${roomHtml}${commentHtml}</div>`;
};

/** Function to handle PDF export by redirecting to server */
const handlePdfExport = () => {
    const year = yearSelector.value;
    const week = weekSelector.value;
    if (!year || !week) {
        showToast("Bitte Jahr und KW auswählen.", 'error');
        return;
    }
    const pdfUrl = `${window.APP_CONFIG.baseUrl}/pdf/timetable/${year}/${week}`;
    window.open(pdfUrl, '_blank');
};

/** Storniert einen Termin */
const handleCancelAppointment = async (e) => {
    const button = e.target.closest('.cancel-appointment-btn');
    if (!button) return;
    
    const appointmentId = button.dataset.id;
    const entryElement = button.closest('.today-entry');
    const title = entryElement.querySelector('.details strong').textContent;

    if (await showConfirm("Termin stornieren", `Möchten Sie den Termin "${escapeHtml(title)}" wirklich stornieren?`)) {
        button.disabled = true;
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/appointment/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ appointment_id: appointmentId })
            });

            if (response.success) {
                showToast(response.message, 'success');
                entryElement.style.transition = 'opacity 0.3s ease, height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
                entryElement.style.opacity = '0';
                entryElement.style.height = '0px';
                entryElement.style.paddingTop = '0';
                entryElement.style.paddingBottom = '0';
                entryElement.style.margin = '0';
                setTimeout(() => {
                    entryElement.remove();
                    if (todayScheduleContainer && todayScheduleContainer.childElementCount === 0) {
                        renderTodaySchedule([], [], [], [], {});
                    }
                }, 300);
            }
        } catch (error) {
            console.error("Fehler beim Stornieren:", error);
            button.disabled = false;
        }
    }
};


export function initializeDashboard() {
    const container = document.querySelector('.dashboard-wrapper');
    if (!container) return;
    
    // Lade Stammdaten sofort
    dashboardState.stammdaten = window.APP_CONFIG.settings.stammdaten || {
        subjects: [], teachers: [], rooms: [], classes: []
    };

    // --- Event Listeners ---
    if (yearSelector) yearSelector.addEventListener('change', loadAndRenderWeeklyData);
    if (weekSelector) weekSelector.addEventListener('change', loadAndRenderWeeklyData);
    if (pdfButton) pdfButton.addEventListener('click', handlePdfExport);

    if (copyIcalUrlButton && icalUrlInput) {
        copyIcalUrlButton.addEventListener('click', async () => {
            try {
                icalUrlInput.select();
                icalUrlInput.setSelectionRange(0, 99999);
                let copied = false;
                try { copied = document.execCommand('copy'); } catch(err) { copied = false; }
                if (copied) {
                    showToast('iCal URL in Zwischenablage kopiert!', 'success', 2000);
                } else if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(icalUrlInput.value);
                    showToast('iCal URL in Zwischenablage kopiert!', 'success', 2000);
                } else {
                    throw new Error('Copy command failed and Clipboard API not available.');
                }
            } catch (err) {
                console.error('Fehler beim Kopieren der iCal URL: ', err);
                showToast('Kopieren fehlgeschlagen. Bitte manuell kopieren.', 'error');
            }
            if (window.getSelection) { window.getSelection().removeAllRanges(); }
            else if (document.selection) { document.selection.empty(); }
            icalUrlInput.blur();
        });
    }

    // --- Tab-Interface Initialisierung ---
    initializeTabbedInterface();

    // --- Initialisierung des Sprechstunden-Widgets (nur für Schüler) ---
    if (userRole === 'schueler') {
        initializeAppointmentBooking();
    }
    
    // --- NEU: Event-Listener für Wochenplan-Detail-Modal ---
    initializePlanDetailModal(timetableContainer, detailModal, detailCloseBtn, noteRow, noteInput, noteSaveBtn, noteSpinner);

    // --- Initialization ---
    populateSelectors();
    loadAndRenderWeeklyData(); // Lädt "Mein Tag" und "Wochenplan"
    loadAnnouncements(); // Lädt Ankündigungen

    // Hide elements for admin if applicable
    if(userRole === 'admin') {
        const printExportActions = document.querySelector('.print-export-actions');
        if(printExportActions) printExportActions.style.display = 'none';
        const icalBox = document.querySelector('.ical-subscription-box');
        if(icalBox) icalBox.style.display = 'none';
    }
}


/**
 * Initialisiert das Sprechstunden-Buchungs-Widget für Schüler.
 */
function initializeAppointmentBooking() {
    const widget = document.getElementById('appointment-booking-widget');
    if (!widget) return;

    const form = document.getElementById('appointment-booking-form');
    const teacherSearchInput = document.getElementById('teacher-search-input');
    const teacherSearchResults = document.getElementById('teacher-search-results');
    const selectedTeacherIdInput = document.getElementById('selected-teacher-id');
    const datePicker = document.getElementById('appointment-date-picker');
    const slotsContainer = document.getElementById('available-slots-container');
    const notesContainer = document.getElementById('appointment-notes-container');
    const notesInput = document.getElementById('appointment-notes');
    const bookButton = document.getElementById('book-appointment-btn');
    const bookSpinner = document.getElementById('appointment-book-spinner');

    let searchTimeout;
    let selectedSlot = null; 

    datePicker.min = new Date().toISOString().split('T')[0];

    // --- 1. Lehrer-Suche ---
    teacherSearchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        resetSlotSelection();
        datePicker.disabled = true;
        datePicker.value = '';
        selectedTeacherIdInput.value = '';

        const query = teacherSearchInput.value.trim();
        if (query.length < 2) {
            teacherSearchResults.innerHTML = '';
            teacherSearchResults.classList.remove('visible');
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                // KORREKTUR: API-Endpunkt angepasst (search-colleagues statt -teachers)
                // KORREKTUR: Sende die Stammdaten-ID (teacher_id), nicht die user_id
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/search-colleagues?query=${encodeURIComponent(query)}`);
                if (response.success && response.data) {
                    const filteredTeachers = response.data.filter(t => t.teacher_shortcut !== 'SGL');
                    if (filteredTeachers.length > 0) {
                        teacherSearchResults.innerHTML = filteredTeachers.map(teacher => {
                            // KORREKTUR: Verwende teacher.teacher_id (Stammdaten-ID)
                            return `
                                <div class="search-result-item" data-id="${teacher.teacher_id}" data-name="${escapeHtml(teacher.first_name)} ${escapeHtml(teacher.last_name)} (${escapeHtml(teacher.teacher_shortcut)})">
                                    <strong>${escapeHtml(teacher.last_name)}, ${escapeHtml(teacher.first_name)}</strong> (${escapeHtml(teacher.teacher_shortcut)})
                                </div>
                            `;
                        }).join('');
                        teacherSearchResults.classList.add('visible');
                    } else {
                        teacherSearchResults.innerHTML = '<div class="search-result-item none">Keine Treffer</div>';
                        teacherSearchResults.classList.add('visible');
                    }
                } else {
                    teacherSearchResults.innerHTML = '<div class="search-result-item none">Keine Treffer</div>';
                    teacherSearchResults.classList.add('visible');
                }
            } catch (error) {
                console.error("Fehler bei Lehrersuche:", error);
                teacherSearchResults.innerHTML = `<div class="search-result-item none">Fehler: ${escapeHtml(error.message)}</div>`;
                teacherSearchResults.classList.add('visible');
            }
        }, 300);
    });

    // --- 2. Lehrer-Auswahl ---
    teacherSearchResults.addEventListener('click', (e) => {
        const item = e.target.closest('.search-result-item');
        if (!item || !item.dataset.id) return;

        selectedTeacherIdInput.value = item.dataset.id; // Speichert die teacher_id (Stammdaten)
        teacherSearchInput.value = item.dataset.name;
        teacherSearchResults.innerHTML = '';
        teacherSearchResults.classList.remove('visible');
        
        datePicker.disabled = false;
        datePicker.focus();
    });

    // --- 3. Datums-Auswahl (lädt Slots) ---
    datePicker.addEventListener('change', async () => {
        resetSlotSelection();
        const teacherId = selectedTeacherIdInput.value; // Dies ist die teacher_id (Stammdaten)
        const date = datePicker.value;

        if (!teacherId || !date) return;
        
        const dayOfWeek = new Date(date + 'T00:00:00').getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            slotsContainer.innerHTML = '<small class="form-hint error-hint">Sprechstunden finden nur Mo-Fr statt.</small>';
            return;
        }

        slotsContainer.innerHTML = '<div class="loading-spinner small"></div>';
        
        try {
            // KORREKTUR: Sendet teacher_id (Stammdaten-ID)
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/student/available-slots?teacher_id=${teacherId}&date=${date}`);
            if (response.success && response.data) {
                if (response.data.length > 0) {
                    slotsContainer.innerHTML = response.data.map(slot => `
                        <button type="button" class="btn-slot" data-time="${slot.time}" data-duration="${slot.duration}">
                            ${escapeHtml(slot.display)}
                        </button>
                    `).join('');
                } else {
                    slotsContainer.innerHTML = '<small class="form-hint">Keine freien Termine an diesem Tag.</small>';
                }
            } else {
                throw new Error(response.message || 'Fehler beim Laden der Slots.');
            }
        } catch (error) {
            slotsContainer.innerHTML = `<small class="form-hint error-hint">${escapeHtml(error.message)}</small>`;
        }
    });

    // --- 4. Slot-Auswahl ---
    slotsContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.btn-slot');
        if (!button) return;

        slotsContainer.querySelectorAll('.btn-slot').forEach(btn => btn.classList.remove('selected'));
        
        button.classList.add('selected');
        selectedSlot = {
            time: button.dataset.time,
            duration: button.dataset.duration
        };
        
        notesContainer.style.display = 'block';
        bookButton.disabled = false;
    });
    
    const resetSlotSelection = () => {
        selectedSlot = null;
        slotsContainer.innerHTML = '<small class="form-hint">Bitte Lehrer und Datum wählen.</small>';
        notesContainer.style.display = 'none';
        if (notesInput) notesInput.value = '';
        if (bookButton) bookButton.disabled = true;
    };

    // --- 5. Buchen ---
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!selectedSlot || !selectedTeacherIdInput.value || !datePicker.value) {
            showToast("Bitte Lehrer, Datum und einen Slot auswählen.", "error");
            return;
        }

        bookButton.disabled = true;
        bookSpinner.style.display = 'block';

        const body = {
            // KORREKTUR: Sendet teacher_id (Stammdaten-ID)
            teacher_id: selectedTeacherIdInput.value,
            date: datePicker.value,
            time: selectedSlot.time,
            duration: selectedSlot.duration,
            notes: notesInput.value.trim() || null
        };
        
        try {
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/student/book-appointment`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            if (response.success) {
                showToast(response.message, 'success');
                form.reset();
                selectedTeacherIdInput.value = '';
                datePicker.disabled = true;
                resetSlotSelection();
                loadAndRenderWeeklyData(); // Lädt "Mein Tag" neu
            }
        } catch (error) {
             console.error("Fehler beim Buchen:", error);
             if (error.message.includes('gebu')) {
                 datePicker.dispatchEvent(new Event('change')); // Löst Neuladen der Slots aus
             }
        } finally {
            bookButton.disabled = false;
            bookSpinner.style.display = 'none';
        }
    });

    document.addEventListener('click', (e) => {
        if (widget && !widget.contains(e.target)) {
            teacherSearchResults.classList.remove('visible');
        }
    });
}

/**
 * NEU: Initialisiert das Tab-Interface.
 */
function initializeTabbedInterface() {
    const wrapper = document.querySelector('.dashboard-wrapper');
    if (!wrapper) return;
    const tabContainer = wrapper.querySelector('.tab-navigation');
    const contentContainer = wrapper.querySelector('.tab-content');
    if (!tabContainer || !contentContainer) return;

    const tabButtons = tabContainer.querySelectorAll('.tab-button');
    const tabContents = contentContainer.querySelectorAll('.dashboard-section');

    // Tabs, die bereits initial geladen werden
    const loadedTabs = {
        'section-my-day': true,
        'section-weekly-plan': true,
        'section-announcements': true,
    };


    tabContainer.addEventListener('click', (e) => {
        const clickedButton = e.target.closest('.tab-button');
        if (!clickedButton || clickedButton.classList.contains('active')) return;
        
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(content => content.classList.remove('active'));

        clickedButton.classList.add('active');
        const targetId = clickedButton.dataset.target;
        const targetContent = document.getElementById(targetId);

        if (targetContent) {
            targetContent.classList.add('active');
            
            // Prüfen, ob der Tab-Inhalt "lazy loaded" werden muss
            if (!loadedTabs[targetId]) {
                // Lazy loading für Community Board (Schüler)
                if (targetId === 'section-community-board') {
                    if (window.initializeDashboardCommunity) {
                        window.initializeDashboardCommunity();
                    }
                }
                // NEU: Lazy loading für "Meine Beiträge" (Schüler)
                else if (targetId === 'section-my-posts') {
                    if (initializeMyCommunityPosts) { // Verwende die importierte Funktion
                        initializeMyCommunityPosts();
                    }
                }
                // Lazy loading für Lehrer-Tabs
                else if (targetId === 'section-attendance' || targetId === 'section-events' || targetId === 'section-office-hours' || targetId === 'section-colleague-search') {
                     if (window.initializeTeacherCockpit) {
                         // Diese Funktion initialisiert ALLE Lehrer-Tabs beim ersten Klick auf einen von ihnen
                         window.initializeTeacherCockpit(); 
                         // Markiere alle Lehrer-Tabs als geladen, um Mehrfach-Initialisierung zu vermeiden
                         loadedTabs['section-attendance'] = true;
                         loadedTabs['section-events'] = true;
                         loadedTabs['section-office-hours'] = true;
                         loadedTabs['section-colleague-search'] = true;
                     }
                }
                
                loadedTabs[targetId] = true; // Markiere diesen Tab als geladen
            }
        }
    });
}

/**
 * NEU: Initialisiert das Modal für die Stundendetails.
 */
function initializePlanDetailModal(timetableContainer, modal, closeBtn, noteRow, noteInput, noteSaveBtn, noteSpinner) {
    if (!timetableContainer || !modal || !closeBtn) {
        console.warn("Detail-Modal-Initialisierung übersprungen: Elemente fehlen.", {timetableContainer, modal, closeBtn});
        return;
    }

    const state = {
        currentSlotKey: null, // z.B. "1-2" (day-period)
        isSaving: false
    };

    const close = () => modal.classList.remove('visible');

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', (e) => {
        if (e.target.id === 'plan-detail-modal') {
            close();
        }
    });

    // Event-Listener am Haupt-Grid-Container (nur für den Wochenplan-Tab)
    timetableContainer.addEventListener('click', (e) => {
        const cell = e.target.closest('.grid-cell.has-entry');
        if (!cell || e.target.closest('a') || cell.classList.contains('dragging')) {
            return;
        }
        
        // Daten aus dem globalen State holen
        const { stammdaten, currentTimetable, currentSubstitutions, studentNotes } = dashboardState;
        
        // IDs aus der Zelle extrahieren
        const day = cell.dataset.day;
        const period = cell.dataset.period;
        const entryId = cell.dataset.entryId;
        const blockId = cell.dataset.blockId;
        const substitutionId = cell.dataset.substitutionId;

        state.currentSlotKey = `${day}-${period}`; // Speichere den Slot für das Speichern der Notiz

        let data = {}; // Hier sammeln wir die Infos
        let status = "Regulär";
        let statusClass = "status-regular";

        let entry = null;
        if (blockId) {
            entry = currentTimetable.find(e => e.block_id === blockId && e.day_of_week == day);
        } else if (entryId) {
            entry = currentTimetable.find(e => e.entry_id == entryId);
        }
        
        let substitution = substitutionId ? currentSubstitutions.find(s => s.substitution_id == substitutionId) : null;

        if (substitution) {
            const regularEntry = entry; 
            status = substitution.substitution_type;
            statusClass = `status-${substitution.substitution_type.toLowerCase()}`;
            
            data.subject = substitution.new_subject_shortcut || regularEntry?.subject_shortcut || 'N/A';
            data.teacher = substitution.new_teacher_shortcut || (userRole === 'schueler' ? regularEntry?.teacher_shortcut : null) || 'N/A';
            data.room = substitution.new_room_name || regularEntry?.room_name || 'N/A';
            data.class = substitution.class_name || regularEntry?.class_name || 'N/A';
            data.comment = substitution.comment || regularEntry?.comment || '';
        
        } else if (entry) { // Regulärer Eintrag
            status = "Regulär";
            statusClass = "status-regular";
            data.subject = entry.subject_shortcut;
            data.teacher = entry.teacher_shortcut;
            data.room = entry.room_name;
            data.class = entry.class_name;
            data.comment = entry.comment || '';
        } else {
            return; // Nichts zu anzeigen (FU oder leer)
        }

        // Fülle das Modal
        document.getElementById('detail-status').textContent = status;
        document.getElementById('detail-status').className = `detail-value ${statusClass}`;
        
        // Zeit-Logik (prüft auf Block)
        // KORREKTUR: Verwende die globale Variable 'timeSlotsDisplay'
        const span = blockId ? (currentTimetable.filter(e => e.block_id === blockId).length) : 1;
        let timeText;
        if (span > 1) {
            const startPeriod = parseInt(period);
            const endPeriod = startPeriod + span - 1;
            const startTime = formatTimeSlot(startPeriod);
            const endTime = timeSlotsDisplay[endPeriod - 1]?.split(' - ')[1] || '??:??'; // KORRIGIERT
            timeText = `${days[day-1]}, ${startPeriod}. - ${endPeriod}. Stunde (${startTime} - ${endTime})`; // KORRIGIERT
        } else {
            timeText = `${days[day-1]}, ${period}. Stunde (${timeSlotsDisplay[period-1]})`; // KORRIGIERT
        }
        document.getElementById('detail-time').textContent = timeText;
        
        // Volle Namen aus Stammdaten holen (falls vorhanden)
        const subjectFull = stammdaten.subjects?.find(s => s.subject_shortcut === data.subject)?.subject_name || data.subject;
        const teacherFull = stammdaten.teachers?.find(t => t.teacher_shortcut === data.teacher);
        const teacherName = teacherFull ? `${teacherFull.first_name} ${teacherFull.last_name} (${teacherFull.teacher_shortcut})` : (data.teacher || 'N/A');
        
        document.getElementById('detail-subject').textContent = subjectFull || 'N/A';
        
        const teacherRow = document.getElementById('detail-teacher');
        const classRow = document.getElementById('detail-class');
        
        if (userRole === 'schueler' && teacherRow) {
            teacherRow.textContent = teacherName;
        } else if (userRole === 'lehrer' && classRow) {
            classRow.textContent = data.class || 'N/A';
        }
        document.getElementById('detail-room').textContent = data.room || 'N/A';

        const commentRow = document.getElementById('detail-comment-row');
        if (data.comment) {
            document.getElementById('detail-comment').textContent = data.comment;
            commentRow.style.display = 'flex';
        } else {
            commentRow.style.display = 'none';
        }

        // NEU: Notiz-Logik für Schüler
        if (userRole === 'schueler' && noteRow && noteInput) {
            const noteKey = state.currentSlotKey;
            const currentNote = studentNotes[noteKey] || '';
            noteInput.value = currentNote;
            noteRow.style.display = 'flex'; // Mache die Notiz-Sektion sichtbar
            if(noteSaveBtn) noteSaveBtn.disabled = false;
            if(noteSpinner) noteSpinner.style.display = 'none';
        }

        modal.classList.add('visible');
    });

    // NEU: Event-Listener für Notiz-Speichern (nur für Schüler)
    if (userRole === 'schueler' && noteSaveBtn && noteInput && noteSpinner) {
        noteSaveBtn.addEventListener('click', async () => {
            if (state.isSaving || !state.currentSlotKey) return;
            state.isSaving = true;
            noteSaveBtn.disabled = true;
            noteSpinner.style.display = 'inline-block';

            const [day, period] = state.currentSlotKey.split('-');
            const year = yearSelector.value;
            const week = weekSelector.value;
            const content = noteInput.value.trim();

            const body = {
                year: parseInt(year),
                calendar_week: parseInt(week),
                day_of_week: parseInt(day),
                period_number: parseInt(period),
                note_content: content
            };

            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/student/note/save`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                if (response.success) {
                    showToast('Notiz gespeichert!', 'success');
                    // Aktualisiere den globalen Notiz-State
                    if (content) {
                        dashboardState.studentNotes[state.currentSlotKey] = content;
                    } else {
                        delete dashboardState.studentNotes[state.currentSlotKey];
                    }
                    // Rendere die Grids neu, um Notiz-Indikatoren zu aktualisieren
                    renderWeeklyTimetable(dashboardState.currentTimetable, dashboardState.currentSubstitutions, dashboardState.studentNotes);
                    renderTodaySchedule(dashboardState.currentTimetable, dashboardState.currentSubstitutions, [], [], dashboardState.studentNotes); // TODO: Events/Appointments müssten hier neu geladen oder übergeben werden
                    close(); // Schließe das Modal nach dem Speichern
                }
                // Fehler wird von apiFetch als Toast angezeigt
            } catch (error) {
                console.error("Fehler beim Speichern der Notiz:", error);
            } finally {
                state.isSaving = false;
                noteSaveBtn.disabled = false;
                noteSpinner.style.display = 'none';
            }
        });
    }
}

