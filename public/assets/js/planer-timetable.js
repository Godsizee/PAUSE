import { getState } from './planer-state.js';
import { days, timeSlots, timetableContainer } from './planer-dom.js';
import { escapeHtml } from './planer-utils.js'; 
export function renderTimetableGrid(overrideState = null, isTemplateEditor = false) {
    const container = isTemplateEditor ? document.getElementById('template-editor-grid-container') : timetableContainer;
    if (!container) return;
    const state = overrideState || getState();
    const stammdaten = state.stammdaten || {};
    const settings = (stammdaten && stammdaten.settings) ? stammdaten.settings : (window.APP_CONFIG.settings || {});
    const startHour = parseInt(settings.default_start_hour, 10) || 1;
    const endHour = parseInt(settings.default_end_hour, 10) || 10;
    const grid = document.createElement('div');
    grid.className = 'timetable-grid'; 
    if (isTemplateEditor) {
        grid.classList.add('template-editor-grid');
    } else {
        grid.id = 'timetable-grid'; 
    }
    let gridHTML = '';
    gridHTML += '<div class="grid-header period-header">Zeit</div>';
    days.forEach(dayName => { 
        gridHTML += `<div class="grid-header">${dayName}</div>`;
    });
    for (let period = 1; period <= timeSlots.length; period++) {
        if (isTemplateEditor && (period < startHour || period > endHour)) {
            continue;
        }
        gridHTML += `<div class="grid-header period-header" style="grid-row: ${period + 1};">
            <div class="time-slot-period">${period}. Std</div>
            <div class="time-slot-time">${timeSlots[period - 1]}</div>
        </div>`;
    }
    const processedCellKeys = new Set();
    const blockSpans = new Map();
    const dataToRender = isTemplateEditor ? (state.currentTemplateEditorData || []) : (state.currentTimetable || []);
    const subsToRender = isTemplateEditor ? [] : (state.currentSubstitutions || []);
    const stateTimetableMap = isTemplateEditor ? null : (state.timetable || {}); 
    const stateSubMap = isTemplateEditor ? null : (state.substitutions || {}); 
    if (dataToRender.length > 0) {
        const blocks = new Map();
        dataToRender.forEach(entry => {
            const blockKey = isTemplateEditor ? entry.block_ref : entry.block_id;
            if (blockKey) {
                if (!blocks.has(blockKey)) blocks.set(blockKey, []);
                blocks.get(blockKey).push(entry);
            }
        });
        blocks.forEach(entries => {
            if (entries.length === 0) return;
            entries.sort((a, b) => a.period_number - b.period_number);
            const startEntry = entries[0];
            const span = parseInt(entries[entries.length - 1].period_number) - parseInt(startEntry.period_number) + 1;
            blockSpans.set(`${startEntry.day_of_week}-${startEntry.period_number}`, span);
            for (let i = 1; i < span; i++) {
                processedCellKeys.add(`${startEntry.day_of_week}-${parseInt(startEntry.period_number) + i}`);
            }
        });
    }
    if (subsToRender.length > 0) {
        const substitutionBlocks = new Map();
        subsToRender.forEach(sub => {
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
    }
    for (let period = 1; period <= timeSlots.length; period++) {
        if (isTemplateEditor && (period < startHour || period > endHour)) {
            continue;
        }
        for (let day = 1; day <= days.length; day++) {
            const cellKey = `${day}-${period}`;
            if (processedCellKeys.has(cellKey)) continue;
            const entries = isTemplateEditor ? dataToRender.filter(e => e.day_of_week == day && e.period_number == period) : (stateTimetableMap[cellKey] || []);
            const subs = isTemplateEditor ? [] : (stateSubMap[cellKey] || []);
            let cellClass = 'grid-cell';
            if (isTemplateEditor) cellClass += ' template-cell';
            let cellContent = '';
            let dataAttrs = `data-day="${day}" data-period="${period}" data-cell-key="${cellKey}"`;
            let style = `grid-row: ${period + 1}; grid-column: ${day + 1};`;
            const span = blockSpans.get(cellKey);
            if (span) {
                style += `grid-row: ${period + 1} / span ${span};`;
                cellClass += ' block-start';
            }
            if (subs.length > 0) {
                cellClass += ' has-substitution';
                dataAttrs += ` draggable="true"`; 
                subs.forEach(sub => {
                    if (!dataAttrs.includes('data-substitution-id')) {
                        dataAttrs += ` data-substitution-id="${sub.substitution_id}"`;
                    }
                    const typeClass = `substitution-${sub.substitution_type.toLowerCase()}`;
                    if (!cellClass.includes(typeClass)) {
                        cellClass += ` ${typeClass}`;
                    }
                    cellContent += createSubstitutionElement(sub, state).outerHTML;
                });
            } else if (entries.length > 0) {
                cellClass += ' has-entry';
                dataAttrs += ` draggable="true"`; 
                entries.forEach(entry => {
                    if (isTemplateEditor) {
                        if (!dataAttrs.includes('data-template-entry-id')) {
                            dataAttrs += ` data-template-entry-id="${entry.template_entry_id}"`;
                            if (entry.block_ref) dataAttrs += ` data-block-id="${entry.block_ref}"`;
                        }
                    } else {
                        if (!dataAttrs.includes('data-entry-id')) {
                            dataAttrs += ` data-entry-id="${entry.entry_id}"`;
                            if (entry.block_id) dataAttrs += ` data-block-id="${entry.block_id}"`;
                        }
                    }
                    cellContent += createTimetableElement(entry, isTemplateEditor, state).outerHTML;
                });
            } else {
                cellClass += ' is-empty';
                if (!isTemplateEditor && (period === startHour || period === endHour)) {
                    cellClass += ' default-entry';
                    cellContent = `<div class="planner-entry default-entry" style="pointer-events: none;"><strong>FU</strong></div>`;
                } else {
                    cellContent = `<span class="sr-only">Kein Eintrag für ${days[day-1]}, ${period}. Stunde</span>`;
                }
            }
            gridHTML += `<div class="${cellClass}" ${dataAttrs} style="${style}">
                            <div class="cell-entries-container">${cellContent}</div>
                        </div>`;
        }
    }
    grid.innerHTML = gridHTML;
    container.innerHTML = '';
    container.appendChild(grid);
}
function createTimetableElement(entry, isTemplateEditor = false, state) {
    const entryElement = document.createElement('div');
    entryElement.className = 'planner-entry timetable-entry';
    if (isTemplateEditor) {
        entryElement.dataset.templateEntryId = entry.template_entry_id; 
        if (entry.block_ref) {
            entryElement.dataset.blockId = entry.block_ref; 
        }
    } else {
        entryElement.dataset.entryId = entry.entry_id;
        if (entry.block_id) {
            entryElement.dataset.blockId = entry.block_id;
        }
    }
    const isTeacherView = state.currentViewMode === 'teacher';
    const stammdaten = state.stammdaten || {};
    let subjectShortcut, teacherShortcut, roomName, className;
    if (isTemplateEditor) {
        subjectShortcut = (stammdaten.subjects?.find(s => s.subject_id == entry.subject_id) || {}).subject_shortcut || 'F?';
        teacherShortcut = (stammdaten.teachers?.find(t => t.teacher_id == entry.teacher_id) || {}).teacher_shortcut || 'L?';
        roomName = (stammdaten.rooms?.find(r => r.room_id == entry.room_id) || {}).room_name || 'R?';
        className = (stammdaten.classes?.find(c => c.class_id == entry.class_id) || {}).class_name || 'K?';
        if (entry.class_id == 0) className = 'Alle'; 
    } else {
        subjectShortcut = entry.subject_shortcut || '---';
        teacherShortcut = entry.teacher_shortcut || '---';
        roomName = entry.room_name || '---';
        className = entry.class_name || 'N/A';
    }
    let mainHtml = '';
    if (isTeacherView) {
        const classDisplay = escapeHtml(className);
        if (entry.class_id && entry.class_id != 0) {
            mainHtml = `<div class="entry-line entry-main">${classDisplay} (ID: ${escapeHtml(entry.class_id)})</div>`;
        } else {
            mainHtml = `<div class="entry-line entry-main">${classDisplay}</div>`;
        }
    } else {
        mainHtml = `<div class="entry-line entry-main">${escapeHtml(teacherShortcut)}</div>`;
    }
    entryElement.innerHTML = `
        <div class="entry-line entry-subject">${escapeHtml(subjectShortcut)}</div>
        ${mainHtml}
        <div class="entry-line entry-room">${escapeHtml(roomName)}</div>
    `;
    if (entry.comment) {
        entryElement.classList.add('has-comment');
        entryElement.title = `Kommentar: ${escapeHtml(entry.comment)}`;
    }
    if (!isTemplateEditor && entry.teacher_id && state.stammdaten.absences && state.stammdaten.absences.length > 0) {
        let entryDate;
        try {
            const dto = new Date();
            dto.setUTCFullYear(state.selectedYear);
            dto.setUTCMonth(0); 
            dto.setUTCDate(1); 
            let dayOfWeek = dto.getUTCDay();
            let firstMonday = (dayOfWeek <= 1) ? (2 - dayOfWeek) : (9 - dayOfWeek);
            dto.setUTCDate(firstMonday);
            dto.setUTCDate(dto.getUTCDate() + (state.selectedWeek - 1) * 7);
            dto.setUTCDate(dto.getUTCDate() + (entry.day_of_week - 1));
            entryDate = dto.toISOString().split('T')[0];
        } catch(e) {
            console.error("Datumsberechnung fehlgeschlagen", e);
            entryDate = null;
        }
        if(entryDate) {
            const absence = isTeacherAbsent(entry.teacher_id, entryDate, state); 
            if (absence) {
                entryElement.classList.add('is-absent');
                const warning = document.createElement('small');
                warning.className = 'absence-warning';
                warning.textContent = `(Lehrer abwesend: ${escapeHtml(absence.reason)})`;
                entryElement.appendChild(warning);
                entryElement.title = `Lehrer abwesend: ${escapeHtml(absence.reason)}`;
            }
        }
    }
    return entryElement;
}
function createSubstitutionElement(sub, state) {
    const entryElement = document.createElement('div');
    entryElement.className = `planner-entry substitution-entry ${sub.substitution_type.toLowerCase()}`;
    entryElement.dataset.substitutionId = sub.substitution_id;
    entryElement.dataset.day = sub.day_of_week;
    entryElement.dataset.period = sub.period_number;
    entryElement.dataset.date = sub.date;
    const originalEntry = getOriginalEntry(sub, state); 
    if (originalEntry) {
        entryElement.dataset.entryId = originalEntry.entry_id;
        if(originalEntry.block_id) entryElement.dataset.blockId = originalEntry.block_id;
    }
    const isTeacherView = state.currentViewMode === 'teacher';
    let subjectText = '---';
    let mainText = '---';
    let roomText = '---';
    let typeText = sub.substitution_type;
    let classDisplay = escapeHtml(sub.class_name || 'N/A');
    if (sub.class_id && sub.class_id != 0) {
        classDisplay += ` (ID: ${escapeHtml(sub.class_id)})`;
    }
    switch (sub.substitution_type) {
        case 'Vertretung':
            subjectText = sub.new_subject_shortcut || sub.original_subject_shortcut || '---';
            mainText = isTeacherView ? classDisplay : (sub.new_teacher_shortcut || '!!!');
            roomText = sub.new_room_name || '---';
            break;
        case 'Raumänderung':
            typeText = 'Raum'; 
            subjectText = sub.original_subject_shortcut || '---';
            mainText = isTeacherView ? classDisplay : (getOriginalTeacher(sub, state) || '---'); 
            roomText = sub.new_room_name || '!!!';
            break;
        case 'Entfall':
            subjectText = sub.original_subject_shortcut || '---';
            mainText = `(${isTeacherView ? classDisplay : (getOriginalTeacher(sub, state) || '---')})`; 
            roomText = '---';
            break;
        case 'Sonderevent':
            typeText = 'Event'; 
            subjectText = sub.new_subject_shortcut || 'EVENT';
            mainText = sub.comment ? (sub.comment.substring(0, 10) + '...') : 'Info';
            roomText = sub.new_room_name || '---';
            break;
    }
    entryElement.innerHTML = `
        <div class="entry-line sub-type">${escapeHtml(typeText)}</div>
        <div class="entry-line entry-subject">${escapeHtml(subjectText)}</div>
        <div class="entry-line entry-main">${mainText}</div> 
        <div class="entry-line entry-room">${escapeHtml(roomText)}</div>
    `;
    if (sub.comment && sub.substitution_type !== 'Sonderevent') {
         entryElement.title = `Kommentar: ${escapeHtml(sub.comment)}`;
    }
    return entryElement;
}
function isTeacherAbsent(teacherId, dateString, state) {
    if (!teacherId || !dateString) return null;
    const absences = (state.stammdaten && state.stammdaten.absences) ? state.stammdaten.absences : [];
    if (absences.length === 0) return null;
    try {
        const checkDate = new Date(dateString + 'T00:00:00Z');
        if (isNaN(checkDate.getTime())) {
            console.warn(`isTeacherAbsent: Ungültiges Datum ${dateString}`);
            return null;
        }
        for (const absence of absences) {
            if (absence.teacher_id == teacherId) { 
                const startDate = new Date(absence.start_date + 'T00:00:00Z');
                const endDate = new Date(absence.end_date + 'T00:00:00Z');
                if (checkDate >= startDate && checkDate <= endDate) {
                    return absence;
                }
            }
        }
    } catch (e) {
        console.error("Fehler beim Prüfen der Abwesenheit:", e);
    }
    return null;
}
function getOriginalEntry(sub, state) {
    const cellKey = `${sub.day_of_week}-${sub.period_number}`;
    const entries = state.timetable[cellKey] || [];
    const originalEntry = entries.find(e => 
        e.class_id == sub.class_id && 
        e.subject_id == sub.original_subject_id
    );
    return originalEntry || null;
}
function getOriginalTeacher(sub, state) {
    const originalEntry = getOriginalEntry(sub, state);
    if (originalEntry) {
        if (originalEntry.teacher_shortcut) {
            return originalEntry.teacher_shortcut;
        }
        const teacher = (state.stammdaten.teachers || []).find(t => t.teacher_id == originalEntry.teacher_id);
        if (teacher) {
            return teacher.teacher_shortcut;
        }
    }
    return null; 
}