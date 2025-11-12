import { apiFetch } from './api-client.js';
import { showToast, showConfirm } from './notifications.js';
import { escapeHtml } from './planer-utils.js';
import { initializeMyCommunityPosts } from './dashboard-my-posts.js';

function getWeekAndYear(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    return { week: weekNo, year: d.getUTCFullYear() };
}

function getDateOfISOWeek(week, year) {
    const simple = new Date(Date.UTC(year, 0, 1 + (week - 1) * 7));
    const dow = simple.getUTCDay();
    const ISOweekStart = simple;
    ISOweekStart.setUTCDate(simple.getUTCDate() - (dow || 7) + 1);
    return new Date(ISOweekStart.getUTCFullYear(), ISOweekStart.getUTCMonth(), ISOweekStart.getUTCDate());
}

function formatTimeSlot(period) {
    const times = [
        "08:00", "08:55", "09:40", "10:35", "11:20",
        "13:05", "13:50", "14:45", "15:30", "16:25"
    ];
    return times[period - 1] || '??:??';
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

function formatShortTime(timeString) {
    if (!timeString) return '';
    const parts = timeString.split(':');
    if (parts.length >= 2) {
        return `${parts[0]}:${parts[1]}`;
    }
    return timeString;
}

const dashboardState = {
    stammdaten: null,
    currentTimetable: [],
    currentSubstitutions: [],
    studentNotes: {}, 
    currentPublishStatus: { student: false, teacher: false }
};

const days = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag"];
const timeSlotsDisplay = [
     "08:00 - 08:45", "08:55 - 09:40", "09:40 - 10:25", "10:35 - 11:20",
     "11:20 - 12:05", "13:05 - 13:50", "13:50 - 14:35", "14:45 - 15:30",
     "15:30 - 16:15", "16:25 - 17:10"
];

const userRole = window.APP_CONFIG.userRole;
const today = new Date();
const todayDateString = today.toISOString().split('T')[0];
const todayDayOfWeek = (today.getDay() === 0) ? 7 : today.getDay(); 

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
    let studentNotes = {}; 
    try {
        const planUrl = `${window.APP_CONFIG.baseUrl}/api/dashboard/weekly-data?year=${year}&week=${week}`;
        const planResponse = await apiFetch(planUrl);
        if (!planResponse.success || !planResponse.data) {
            throw new Error(planResponse.message || "Plandaten konnten nicht geladen werden.");
        }
        timetable = planResponse.data.timetable || [];
        substitutions = planResponse.data.substitutions || [];
        appointments = planResponse.data.appointments || [];
        studentNotes = (userRole === 'schueler') ? (planResponse.data.studentNotes || {}) : {}; 
        dashboardState.currentTimetable = timetable;
        dashboardState.currentSubstitutions = substitutions;
        dashboardState.studentNotes = studentNotes; 
        renderWeeklyTimetable(timetable, substitutions, studentNotes); 
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
        renderTodaySchedule(timetable, substitutions, academicEvents, appointments, studentNotes); 
    } catch (error) { 
        console.error("Fehler beim Laden der Wochendaten (kritisch):", error);
        timetableContainer.innerHTML = `<p class="message error">${error.message || 'Der Wochenplan konnte nicht geladen werden.'}</p>`;
        if (todayScheduleContainer) {
            todayScheduleContainer.innerHTML = `<p class="message error small">Heutiger Plan nicht verfügbar.</p>`;
        }
    }
};

const renderTodaySchedule = (weeklyTimetable, weeklySubstitutions, academicEvents, appointments, studentNotes) => {
    if (!todayScheduleContainer) return;
    const currentDayNum = todayDayOfWeek;
    if (currentDayNum < 1 || currentDayNum > 5) {
        todayScheduleContainer.innerHTML = '<p class="message info" style="padding: 10px; margin: 0;">Heute ist kein Schultag. Genieße die freie Zeit! 🎉</p>';
        return;
    }
    const PERIOD_END_TIMES = [
        845, 940, 1025, 1120, 1205, 
        1350, 1435, 1530, 1615, 1710  
    ];
    const now = new Date();
    const timeFormatter = new Intl.DateTimeFormat('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin', 
        hour12: false
    });
    const parts = timeFormatter.format(now).split(':'); 
    const currentHHMM = parseInt(parts[0], 10) * 100 + parseInt(parts[1], 10); 
    const todaysEntries = weeklyTimetable.filter(entry => entry.day_of_week == currentDayNum);
    const todaysSubstitutions = weeklySubstitutions.filter(sub => sub.date === todayDateString);
    const todaysEvents = (academicEvents || []).filter(event => event.due_date === todayDateString);
    const todaysAppointments = (appointments || []).filter(app => app.appointment_date === todayDateString);
    let combinedSchedule = [];
    for (let period = 1; period <= timeSlotsDisplay.length; period++) {
        const periodEndTime = PERIOD_END_TIMES[period - 1]; 
        if (currentHHMM > periodEndTime) {
            continue; 
        }
        const substitution = todaysSubstitutions.find(sub => sub.period_number === period);
        const regularEntry = todaysEntries.find(entry => entry.period_number === period);
        const noteKey = `${todayDayOfWeek}-${period}`; 
        const note = studentNotes[noteKey] || ''; 
        if (substitution) {
            combinedSchedule.push({
                sortKey: period * 10,
                period: period,
                time: formatTimeSlot(period),
                type: substitution.substitution_type,
                id: substitution.substitution_id, 
                class_id: substitution.class_id, 
                subject: substitution.new_subject_shortcut || regularEntry?.subject_shortcut || (substitution.substitution_type === 'Sonderevent' ? 'EVENT' : '---'),
                mainText: substitution.substitution_type === 'Vertretung'
                    ? (userRole === 'teacher' ? (substitution.class_name || regularEntry?.class_name) : substitution.new_teacher_shortcut)
                    : (substitution.substitution_type === 'Entfall' ? '' : (regularEntry ? (userRole === 'schueler' ? regularEntry.teacher_shortcut : regularEntry.class_name) : '')),
                room: substitution.new_room_name || regularEntry?.room_name || '',
                comment: substitution.comment || '',
                note: note, 
                icsType: (substitution.substitution_type === 'Sonderevent') ? 'sub' : null 
            });
        } else if (regularEntry) {
            combinedSchedule.push({
                sortKey: period * 10,
                period: period,
                time: formatTimeSlot(period),
                type: 'regular',
                id: regularEntry.entry_id,
                class_id: regularEntry.class_id, 
                subject: regularEntry.subject_shortcut || '---',
                mainText: userRole === 'schueler' ? regularEntry.teacher_shortcut : regularEntry.class_name,
                room: regularEntry.room_name || '---',
                comment: regularEntry.comment || '',
                note: note, 
                icsType: null
            });
        }
    }
    todaysEvents.forEach(event => {
        combinedSchedule.push({
            sortKey: 1, 
            time: 'Ganztägig',
            type: event.event_type,
            subject: event.title,
            mainText: event.subject_shortcut || (userRole === 'schueler' ? `${event.teacher_first_name} ${event.teacher_last_name}` : ''),
            room: '',
            comment: event.description || '',
            note: '', 
            id: event.event_id, 
            icsType: 'acad' 
        });
    });
    todaysAppointments.forEach(app => {
        const appTime = formatShortTime(app.appointment_time);
        const sortKeyTime = parseInt(appTime.replace(':', ''), 10); 
        const timeParts = app.appointment_time.split(':'); 
        const duration = parseInt(app.duration, 10) || 15; 
        if (timeParts.length >= 2) {
            const startH = parseInt(timeParts[0], 10);
            const startM = parseInt(timeParts[1], 10);
            const endM = startM + duration; 
            const endH = startH + Math.floor(endM / 60); 
            const finalEndM = endM % 60; 
            const endHHMM = (endH * 100) + finalEndM; 
            if (currentHHMM > endHHMM) {
                return; 
            }
        }
        let mainText = '';
        if (userRole === 'lehrer') {
            mainText = app.class_name 
                ? `Klasse: ${escapeHtml(app.class_name)} (ID: ${escapeHtml(app.class_id)})` 
                : 'Schüler';
        }
        combinedSchedule.push({
            sortKey: sortKeyTime,
            time: appTime,
            type: 'appointment',
            class_id: app.class_id, 
            subject: userRole === 'schueler' ? `Sprechstunde (${escapeHtml(app.teacher_shortcut || app.teacher_name)})` : `Sprechstunde (${escapeHtml(app.student_name)})`,
            mainText: mainText, 
            room: app.location || 'N/A', 
            comment: app.notes || '',
            note: '', 
            id: app.appointment_id,
            icsType: null 
        });
    });
    combinedSchedule.sort((a, b) => a.sortKey - b.sortKey);
    if (combinedSchedule.length === 0) {
        todayScheduleContainer.innerHTML = '<p class="message info" style="padding: 10px; margin: 0;">Für heute sind keine Einträge (mehr) vorhanden.</p>';
        return;
    }
    const icsIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2M2 1a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1H2zM14 15H2a1 1 0 0 1-1-1V5h14v9a1 1 0 0 1-1 1M9 7.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5zM6.5 9a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 0 1h-4a.5.5 0 0 1-.5-.5m-3 2a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5z"/></svg>`;
    const noteIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 0A1.5 1.5 0 0 0 0 1.5V13a1 1 0 0 0 1 1V1.5a.5.5 0 0 1 .5-.5H14a1 1 0 0 0-1-1zM3.5 2A1.5 1.5 0 0 0 2 3.5v11A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 12.5 2zM3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5z"/></svg>`;
    todayScheduleContainer.innerHTML = combinedSchedule.map(item => {
        const typeClass = `type-${item.type.replace(' ', '')}`;
        let commentHtml = '';
        if (item.comment) {
            commentHtml += `<small class="entry-comment" title="${escapeHtml(item.comment)}">📝 ${escapeHtml(item.comment)}</small>`;
        }
        if (item.note) {
            commentHtml += `<small class="entry-note" title="${escapeHtml(item.note)}">${noteIcon} ${escapeHtml(item.note)}</small>`;
        }
        let detailsHtml = `<strong>${escapeHtml(item.subject)}</strong>`;
        if(item.mainText && item.type !== 'Entfall') {
            if (userRole === 'lehrer' && item.type !== 'appointment' && item.class_id) {
                detailsHtml += `<span>${escapeHtml(item.mainText)} (ID: ${escapeHtml(item.class_id)})</span>`;
            } else {
                detailsHtml += `<span>${escapeHtml(item.mainText)}</span>`;
            }
        }
        if(item.room && item.type !== 'Entfall') detailsHtml += `<span>${escapeHtml(item.room)}</span>`;
        let actionButton = '';
        if (item.type === 'appointment') {
            actionButton = `<button class="btn btn-danger btn-small cancel-appointment-btn" data-id="${item.id}" title="Termin stornieren">&times;</button>`;
        }
        let icsButtonHtml = '';
        if (item.icsType === 'acad') { 
            const icsUrl = `${window.APP_CONFIG.baseUrl}/ics/event/acad/${item.id}`;
            icsButtonHtml = `<a href="${icsUrl}" class="btn-ics" title="Zum Kalender hinzufügen">${icsIcon}</a>`;
        } else if (item.icsType === 'sub') { 
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

const renderWeeklyTimetable = (weeklyTimetableData, allWeeklySubstitutions, studentNotes) => {
    const processedCellKeys = new Set();
    const blockSpans = new Map();
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
                if (parseInt(subs[i + 1].period_number) !== parseInt(subs[i].period_number) + 1) {
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
                        processedCellKeys.add(`${dayNum}-${parseInt(startSub.period_number) + i}`);
                    }
                }
            }
        }
    });
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
            const dayNum = dayIndex + 1; 
            const cellKey = `${dayNum}-${period}`;
            const noteKey = cellKey; 
            if (processedCellKeys.has(cellKey)) { return; }
            let cellContent = '', cellClass = 'empty', dataAttrs = `data-day="${dayNum}" data-period="${period}"`, style = '';
            const span = blockSpans.get(cellKey);
            if (span) {
                style = `grid-row: span ${span};`;
                cellClass += ' block-start';
            }
            const substitution = allWeeklySubstitutions.find(s => s.day_of_week == dayNum && s.period_number == period);
            const entryToRender = weeklyTimetableData.find(e => e.day_of_week == dayNum && e.period_number == period);
            const note = (userRole === 'schueler' && studentNotes[noteKey]) ? studentNotes[noteKey] : null; 
            dataAttrs = `data-day="${dayNum}" data-period="${period}"`; 
            if (substitution) {
                cellClass = `has-entry substitution-${substitution.substitution_type}`;
                dataAttrs += ` data-substitution-id="${substitution.substitution_id}"`;
                if (substitution.comment) dataAttrs += ` data-comment="${escapeHtml(substitution.comment)}"`;
                const regularEntry = entryToRender; 
                if(regularEntry) { 
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
                    note, 
                    substitution.class_id 
                );
            } else if (entryToRender) {
                cellClass = 'has-entry';
                dataAttrs += ` data-entry-id="${entryToRender.entry_id}"`;
                dataAttrs += ` data-class-id="${entryToRender.class_id}"`;
                if (entryToRender.block_id) dataAttrs += ` data-block-id="${entryToRender.block_id}"`;
                const mainText = userRole === 'schueler' ? entryToRender.teacher_shortcut : entryToRender.class_name;
                cellContent += createCellEntryHtml(entryToRender.subject_shortcut, mainText, entryToRender.room_name, entryToRender.comment, null, note, entryToRender.class_id); 
            } else if (period >= startHour && period <= endHour) {
                if (period === startHour || period === endHour) {
                    cellClass = 'default-entry';
                    cellContent = createCellEntryHtml('FU', 'Förderunterricht', '', '', null, null, null); 
                } else {
                }
            } else {
            }
            if (note) {
                cellClass += ' has-note';
            }
            gridHTML += `<div class="grid-cell ${cellClass}" ${dataAttrs} style="${style}">${cellContent}</div>`;
        });
    });
    gridHTML += '</div>';
    timetableContainer.innerHTML = gridHTML;
};
const createCellEntryHtml = (subject, mainText, room, comment = null, substitutionType = null, note = null, class_id = null) => {
    let commentHtml = comment ? `<small class="entry-comment" title="${escapeHtml(comment)}">📝 ${escapeHtml(comment.substring(0, 15))}${comment.length > 15 ? '...' : ''}</small>` : '';
    let roomHtml = room ? `<small class="entry-room">${escapeHtml(room)}</small>` : '';
    let mainHtml = ''; 
    if (mainText) {
        if (userRole === 'lehrer' && class_id) { 
            mainHtml = `<span>${escapeHtml(mainText)} (ID: ${escapeHtml(class_id)})</span>`;
        } else {
            mainHtml = `<span>${escapeHtml(mainText)}</span>`;
        }
    }
    let subjectHtml = subject ? `<strong>${escapeHtml(subject)}</strong>` : '';
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
    return `<div class="cell-entry">${noteHtml}${subjectHtml}${mainHtml}${roomHtml}${commentHtml}</div>`;
};
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
    dashboardState.stammdaten = window.APP_CONFIG.settings.stammdaten || {
        subjects: [], teachers: [], rooms: [], classes: []
    };
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
    initializeTabbedInterface();
    if (userRole === 'schueler') {
        initializeAppointmentBooking();
    }
    initializePlanDetailModal(timetableContainer, detailModal, detailCloseBtn, noteRow, noteInput, noteSaveBtn, noteSpinner);
    populateSelectors();
    loadAndRenderWeeklyData(); 
    loadAnnouncements(); 
    if(userRole === 'admin') {
        const printExportActions = document.querySelector('.print-export-actions');
        if(printExportActions) printExportActions.style.display = 'none';
        const icalBox = document.querySelector('.ical-subscription-box');
        if(icalBox) icalBox.style.display = 'none';
    }
}

// --- FUNKTIONEN FÜR SPRECHSTUNDEN (SCHÜLER) ---
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
    let datePickerInstance = null; // Variable für die Flatpickr-Instanz
    let allTeacherSlots = []; // Cache für die Slots des ausgewählten Lehrers

    // 1. Flatpickr (Kalender) initialisieren
    if(datePicker) {
        // Zerstöre alte Instanz, falls vorhanden (wichtig für Hot-Reloading)
        if (datePicker._flatpickr) {
            datePicker._flatpickr.destroy();
        }
        
        datePickerInstance = flatpickr(datePicker, {
            locale: "de", // Deutsche Lokalisierung (wird in header.php geladen)
            minDate: "today",
            dateFormat: "Y-m-d", // Format, das die API versteht
            altInput: true, // Zeigt ein benutzerfreundliches Format an
            altFormat: "d.m.Y",
            disable: [() => true], // Startet komplett deaktiviert
            onChange: function(selectedDates, dateStr, instance) {
                // Dies wird ausgelöst, wenn ein (aktivierter) Tag ausgewählt wird
                if (selectedDates.length > 0) {
                    renderSlotsForDate(dateStr, allTeacherSlots);
                }
            },
        });
        // datePicker.disabled = true; // KORREKTUR: Entfernt. Steuerung nur über flatpickr 'disable'.
    } else {
        console.error("Element #appointment-date-picker nicht gefunden!");
        return; // Abbruch, wenn das Hauptelement fehlt
    }

    // 2. Live-Suche für Lehrer
    teacherSearchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        resetSlotSelection();
        
        // Kalender zurücksetzen und deaktivieren
        datePickerInstance.clear();
        // KORREKTUR: Nicht mehr komplett sperren, sondern nur die aktivierten Tage löschen.
        // ALT: datePickerInstance.set('disable', [() => true]);
        datePickerInstance.set('enable', []); // Setzt die aktivierten Tage zurück (deaktiviert effektiv alles)
        
        datePicker.placeholder = "Bitte zuerst einen Lehrer auswählen.";
        allTeacherSlots = [];

        selectedTeacherIdInput.value = '';
        
        const query = teacherSearchInput.value.trim();
        if (query.length < 2) {
            teacherSearchResults.innerHTML = '';
            teacherSearchResults.classList.remove('visible');
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/teacher/search-colleagues?query=${encodeURIComponent(query)}`);
                if (response.success && response.data) {
                    const filteredTeachers = response.data.filter(t => t.teacher_shortcut !== 'SGL');
                    if (filteredTeachers.length > 0) {
                        teacherSearchResults.innerHTML = filteredTeachers.map(teacher => {
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

    // 3. Auswahl eines Lehrers -> LÄDT SLOTS UND AKTIVIERT KALENDER
    teacherSearchResults.addEventListener('click', async (e) => {
        const item = e.target.closest('.search-result-item');
        if (!item || !item.dataset.id) return;
        
        selectedTeacherIdInput.value = item.dataset.id; 
        teacherSearchInput.value = item.dataset.name;
        teacherSearchResults.innerHTML = '';
        teacherSearchResults.classList.remove('visible');
        
        // Kalender und Slots auf Ladezustand setzen
        // datePicker.disabled = true; // KORREKTUR: Entfernt
        datePicker.placeholder = "Lade verfügbare Tage...";
        slotsContainer.innerHTML = '<div class="loading-spinner small"></div>';
        resetSlotSelection();

        try {
            const teacherId = selectedTeacherIdInput.value;
            // Alle verfügbaren Slots für diesen Lehrer holen
            const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/student/upcoming-slots?teacher_id=${teacherId}`);
            
            if (response.success && response.data) {
                allTeacherSlots = response.data || [];
                // Ein Set von einzigartigen Datumswerten erstellen
                const availableDates = [...new Set(allTeacherSlots.map(slot => slot.date))];
                
                // Flatpickr-Instanz aktualisieren, um NUR DIESE TAGE zu aktivieren
                datePickerInstance.set('enable', availableDates);
                
                // datePicker.disabled = false; // KORREKTUR: Entfernt
                datePicker.placeholder = "Datum auswählen...";
                slotsContainer.innerHTML = '<small class="form-hint">Bitte ein verfügbares Datum wählen.</small>';

            } else {
                throw new Error(response.message || 'Fehler beim Laden der Slots.');
            }
        } catch (error) {
            slotsContainer.innerHTML = `<small class="form-hint error-hint">${escapeHtml(error.message)}</small>`;
            datePicker.placeholder = "Fehler beim Laden";
        }
    });

    // 4. (ENTFERNT) Der alte 'change'-Listener auf datePicker wird durch Flatpickr's onChange ersetzt.

    // 5. NEUE Funktion: Rendert die Slots für ein ausgewähltes Datum
    function renderSlotsForDate(selectedDate, allSlots) {
        resetSlotSelection(); // Alte Auswahl löschen
        
        // Slots für das gewählte Datum filtern
        const slotsForDate = allSlots.filter(slot => slot.date === selectedDate);

        if (slotsForDate.length > 0) {
            slotsContainer.innerHTML = slotsForDate.map(slot => {
                const locationHtml = slot.location ? `<small>${escapeHtml(slot.location)}</small>` : '<small><i>Kein Ort</i></small>';
                return `
                    <button type="button" class="btn-slot" 
                            data-time="${slot.time}" 
                            data-duration="${slot.duration}"
                            data-availability-id="${slot.availability_id}"
                            data-location="${escapeHtml(slot.location || '')}">
                        <strong>${escapeHtml(slot.display)} Uhr</strong>
                        ${locationHtml}
                    </button>
                `;
            }).join('');
        } else {
            // Sollte dank 'enable' nicht passieren, aber als Fallback
            slotsContainer.innerHTML = '<small class="form-hint">Keine freien Termine an diesem Tag.</small>';
        }
    }
    
    // 6. Auswahl eines Slots
    slotsContainer.addEventListener('click', (e) => {
        const button = e.target.closest('.btn-slot');
        if (!button) return;
        
        slotsContainer.querySelectorAll('.btn-slot').forEach(btn => btn.classList.remove('selected'));
        button.classList.add('selected');
        
        selectedSlot = {
            time: button.dataset.time,
            duration: button.dataset.duration,
            availability_id: button.dataset.availabilityId,
            location: button.dataset.location
        };
        
        notesContainer.style.display = 'block';
        bookButton.disabled = false;
    });

    // 7. Formular absenden (Buchen)
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!selectedSlot || !selectedTeacherIdInput.value || !datePicker.value) {
            showToast("Bitte Lehrer, Datum und einen Slot auswählen.", "error");
            return;
        }

        const location = selectedSlot.location; 

        bookButton.disabled = true;
        bookSpinner.style.display = 'block';
        
        const body = {
            teacher_id: selectedTeacherIdInput.value,
            date: datePicker.value, // Flatpickr stellt sicher, dass dies YYYY-MM-DD ist
            time: selectedSlot.time,
            duration: selectedSlot.duration,
            availability_id: selectedSlot.availability_id,
            location: location, 
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
                datePickerInstance.clear();
                datePickerInstance.set('disable', [() => true]);
                // datePicker.disabled = true; // KORREKTUR: Entfernt
                datePicker.placeholder = "Bitte zuerst einen Lehrer auswählen.";
                resetSlotSelection();
                loadAndRenderWeeklyData(); // Lädt "Mein Tag" neu, um Termin anzuzeigen
            }
        } catch (error) {
             console.error("Fehler beim Buchen:", error);
             if (error.message.includes('gebu')) { // Slot wurde zwischenzeitlich gebucht
                 // Lade Slots für den Lehrer neu, um den Kalender zu aktualisieren
                 teacherSearchResults.dispatchEvent(new Event('click', { target: document.querySelector(`.search-result-item[data-id="${selectedTeacherIdInput.value}"]`) }));
             }
        } finally {
            bookButton.disabled = false;
            bookSpinner.style.display = 'none';
        }
    });

    // 8. Hilfsfunktion zum Zurücksetzen der Slot-Auswahl
    const resetSlotSelection = () => {
        selectedSlot = null;
        slotsContainer.innerHTML = '<small class="form-hint">Bitte Lehrer und Datum auswählen.</small>';
        notesContainer.style.display = 'none';
        if (notesInput) notesInput.value = '';
        if (bookButton) bookButton.disabled = true;
    };

    // 9. Klick ausserhalb schliesst die Lehrersuche
    document.addEventListener('click', (e) => {
        if (widget && !widget.contains(e.target)) {
            teacherSearchResults.classList.remove('visible');
        }
    });
}
// --- ENDE SPRECHSTUNDEN-FUNKTIONEN ---


function initializeTabbedInterface() {
    const wrapper = document.querySelector('.dashboard-wrapper');
    if (!wrapper) return;
    const tabContainer = wrapper.querySelector('.tab-navigation');
    const contentContainer = wrapper.querySelector('.tab-content');
    if (!tabContainer || !contentContainer) return;
    const tabButtons = tabContainer.querySelectorAll('.tab-button');
    const tabContents = contentContainer.querySelectorAll('.dashboard-section');
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
            if (!loadedTabs[targetId]) {
                if (targetId === 'section-community-board') {
                    if (window.initializeDashboardCommunity) {
                        window.initializeDashboardCommunity();
                    }
                }
                else if (targetId === 'section-my-posts') {
                    if (initializeMyCommunityPosts) { 
                        initializeMyCommunityPosts();
                    }
                }
                else if (targetId === 'section-attendance' || targetId === 'section-events' || targetId === 'section-office-hours' || targetId === 'section-colleague-search') {
                     if (window.initializeTeacherCockpit) {
                         window.initializeTeacherCockpit(); 
                         loadedTabs['section-attendance'] = true;
                         loadedTabs['section-events'] = true;
                         loadedTabs['section-office-hours'] = true;
                         loadedTabs['section-colleague-search'] = true;
                     }
                }
                loadedTabs[targetId] = true; 
            }
        }
    });
}
function initializePlanDetailModal(timetableContainer, modal, closeBtn, noteRow, noteInput, noteSaveBtn, noteSpinner) {
    if (!timetableContainer || !modal || !closeBtn) {
        console.warn("Detail-Modal-Initialisierung übersprungen: Elemente fehlen.", {timetableContainer, modal, closeBtn});
        return;
    }
    const state = {
        currentSlotKey: null, 
        isSaving: false
    };
    const close = () => modal.classList.remove('visible');
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', (e) => {
        if (e.target.id === 'plan-detail-modal') {
            close();
        }
    });
    timetableContainer.addEventListener('click', (e) => {
        const cell = e.target.closest('.grid-cell.has-entry');
        if (!cell || e.target.closest('a') || cell.classList.contains('dragging')) {
            return;
        }
        const { stammdaten, currentTimetable, currentSubstitutions, studentNotes } = dashboardState;
        const day = cell.dataset.day;
        const period = cell.dataset.period;
        const entryId = cell.dataset.entryId;
        const blockId = cell.dataset.blockId;
        const substitutionId = cell.dataset.substitutionId;
        state.currentSlotKey = `${day}-${period}`; 
        let data = {}; 
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
        } else if (entry) { 
            status = "Regulär";
            statusClass = "status-regular";
            data.subject = entry.subject_shortcut;
            data.teacher = entry.teacher_shortcut;
            data.room = entry.room_name;
            data.class = entry.class_name;
            data.comment = entry.comment || '';
        } else {
            return; 
        }
        document.getElementById('detail-status').textContent = status;
        document.getElementById('detail-status').className = `detail-value ${statusClass}`;
        const span = blockId ? (currentTimetable.filter(e => e.block_id === blockId).length) : 1;
        let timeText;
        if (span > 1) {
            const startPeriod = parseInt(period);
            const endPeriod = startPeriod + span - 1;
            const startTime = formatTimeSlot(startPeriod);
            const endTime = timeSlotsDisplay[endPeriod - 1]?.split(' - ')[1] || '??:??'; 
            timeText = `${days[day-1]}, ${startPeriod}. - ${endPeriod}. Stunde (${startTime} - ${endTime})`; 
        } else {
            timeText = `${days[day-1]}, ${period}. Stunde (${timeSlotsDisplay[period-1]})`; 
        }
        document.getElementById('detail-time').textContent = timeText;
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
        if (userRole === 'schueler' && noteRow && noteInput) {
            const noteKey = state.currentSlotKey;
            const currentNote = studentNotes[noteKey] || '';
            noteInput.value = currentNote;
            noteRow.style.display = 'flex'; 
            if(noteSaveBtn) noteSaveBtn.disabled = false;
            if(noteSpinner) noteSpinner.style.display = 'none';
        }
        modal.classList.add('visible');
    });
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
                    if (content) {
                        dashboardState.studentNotes[state.currentSlotKey] = content;
                    } else {
                        delete dashboardState.studentNotes[state.currentSlotKey];
                    }
                    renderWeeklyTimetable(dashboardState.currentTimetable, dashboardState.currentSubstitutions, dashboardState.studentNotes);
                    renderTodaySchedule(dashboardState.currentTimetable, dashboardState.currentSubstitutions, [], [], dashboardState.studentNotes); 
                    close(); 
                }
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