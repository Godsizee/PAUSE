// public/assets/js/planer-timetable.js
// MODIFIZIERT: Redundante, fehlerhafte Neudeklaration von 'days' entfernt.
// MODIFIZIERT: Verwendung von 'timeSlotsDisplay' auf das importierte 'timeSlots' vereinheitlicht.
// KORRIGIERT: Syntaktische Fehler (eingestreute Zeichen wie 'source:', 'section:', 's') entfernt.

import { getState } from './planer-state.js';
import { days, timeSlots, timetableContainer } from './planer-dom.js';
import { escapeHtml } from './planer-utils.js'; // Importiere escapeHtml

/**
 * Erstellt und rendert das gesamte Stundenplan-Raster im DOM.
 * @param {object} [overrideState] - Optionaler State (wird im Template-Editor verwendet)
 * @param {boolean} [isTemplateEditor=false] - Flag für den Template-Editor-Modus
 */
export function renderTimetableGrid(overrideState = null, isTemplateEditor = false) {
    const container = isTemplateEditor ? document.getElementById('template-editor-grid-container') : timetableContainer;
    if (!container) return;

    // KORREKTUR: State über die Funktion holen oder Override verwenden
    const state = overrideState || getState();

    // KORREKTUR: Stelle sicher, dass stammdaten und settings vorhanden sind
    const stammdaten = state.stammdaten || {};
    const settings = (stammdaten && stammdaten.settings) ? stammdaten.settings : (window.APP_CONFIG.settings || {});
    const startHour = parseInt(settings.default_start_hour, 10) || 1;
    const endHour = parseInt(settings.default_end_hour, 10) || 10;
    
    const grid = document.createElement('div');
    grid.className = 'timetable-grid'; // Verwende die CSS-Grid-Klasse
    if (isTemplateEditor) {
        grid.classList.add('template-editor-grid');
    } else {
        grid.id = 'timetable-grid'; // ID für Drag&Drop-Listener
    }

    let gridHTML = '';

    // 1. Header-Zeile (Zeit + Tage)
    gridHTML += '<div class="grid-header period-header">Zeit</div>';
    days.forEach(dayName => { // Verwendet importierte 'days'
        gridHTML += `<div class="grid-header">${dayName}</div>`;
    });

    // 2. Zeit-Spalte (Stunden)
    // KORREKTUR: Verwendet importierte 'timeSlots'
    for (let period = 1; period <= timeSlots.length; period++) {
        if (isTemplateEditor && (period < startHour || period > endHour)) {
            continue;
        }
        // KORREKTUR: Verwendet importierte 'timeSlots'
        gridHTML += `<div class="grid-header period-header" style="grid-row: ${period + 1};">
            <div class="time-slot-period">${period}. Std</div>
            <div class="time-slot-time">${timeSlots[period - 1]}</div>
        </div>`;
    }

    // 3. Datenzellen vorbereiten (inkl. Block-Logik)
    const processedCellKeys = new Set();
    const blockSpans = new Map();
    // KORREKTUR: Verwende die flachen Arrays (currentTimetable/currentSubstitutions) für die Block-Berechnung
    const dataToRender = isTemplateEditor ? (state.currentTemplateEditorData || []) : (state.currentTimetable || []);
    const subsToRender = isTemplateEditor ? [] : (state.currentSubstitutions || []);
    // KORREKTUR: Verwende die Maps (timetable/substitutions) für das Füllen der Zellen
    const stateTimetableMap = isTemplateEditor ? null : (state.timetable || {}); // Map für schnellen Zugriff
    const stateSubMap = isTemplateEditor ? null : (state.substitutions || {}); // Map für schnellen Zugriff

    // 3a. Reguläre Blöcke (verwende dataToRender = flaches Array)
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
            // KORREKTUR: Span-Berechnung muss Perioden-Strings in Zahlen umwandeln
            const span = parseInt(entries[entries.length - 1].period_number) - parseInt(startEntry.period_number) + 1;
            blockSpans.set(`${startEntry.day_of_week}-${startEntry.period_number}`, span);
            for (let i = 1; i < span; i++) {
                processedCellKeys.add(`${startEntry.day_of_week}-${parseInt(startEntry.period_number) + i}`);
            }
        });
    }
    // 3b. Vertretungs-Blöcke (verwende subsToRender = flaches Array)
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


    // 4. Zellen-HTML generieren
    // KORREKTUR: Verwendet importierte 'timeSlots'
    for (let period = 1; period <= timeSlots.length; period++) {
        // Logik für Template-Editor (ausblenden)
        if (isTemplateEditor && (period < startHour || period > endHour)) {
            continue;
        }
        
        // KORREKTUR: Verwendet importierte 'days'
        for (let day = 1; day <= days.length; day++) {
            const cellKey = `${day}-${period}`;
            
            // Überspringe Zellen, die Teil eines Blocks sind (außer der Startzelle)
            if (processedCellKeys.has(cellKey)) continue;

            // KORREKTUR: Hole die Arrays aus den Maps
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

            // KORREKTUR: Iteriere über Vertretungen (haben Vorrang)
            if (subs.length > 0) {
                cellClass += ' has-substitution';
                dataAttrs += ` draggable="true"`; // Zelle draggbar machen
                subs.forEach(sub => {
                    // Verwende die erste Sub-ID für die Zelle, falls mehrere vorhanden sind
                    if (!dataAttrs.includes('data-substitution-id')) {
                        dataAttrs += ` data-substitution-id="${sub.substitution_id}"`;
                    }
                    // Füge die Klasse nur einmal hinzu, auch bei mehreren Vertretungen
                    const typeClass = `substitution-${sub.substitution_type.toLowerCase()}`;
                    if (!cellClass.includes(typeClass)) {
                        cellClass += ` ${typeClass}`;
                    }
                    cellContent += createSubstitutionElement(sub, state).outerHTML;
                });
            // KORREKTUR: Iteriere über reguläre Einträge
            } else if (entries.length > 0) {
                cellClass += ' has-entry';
                dataAttrs += ` draggable="true"`; // Zelle draggbar machen
                entries.forEach(entry => {
                    if (isTemplateEditor) {
                        // Verwende die erste ID für die Zelle
                        if (!dataAttrs.includes('data-template-entry-id')) {
                            dataAttrs += ` data-template-entry-id="${entry.template_entry_id}"`;
                            if (entry.block_ref) dataAttrs += ` data-block-id="${entry.block_ref}"`;
                        }
                    } else {
                        // Verwende die erste ID für die Zelle
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

            // KORREKTUR: Container .cell-entries-container wird jetzt verwendet
            gridHTML += `<div class="${cellClass}" ${dataAttrs} style="${style}">
                            <div class="cell-entries-container">${cellContent}</div>
                        </div>`;
        }
    }

    grid.innerHTML = gridHTML;
    
    // Altes Grid ersetzen
    container.innerHTML = '';
    container.appendChild(grid);
}


/**
 * Erstellt ein einzelnes HTML-Element (DIV) für einen regulären Stundenplaneintrag.
* @param {object} entry - Das Eintragsobjekt.
 * @param {boolean} [isTemplateEditor=false] - Flag für den Template-Editor-Modus
 * @param {object} state - Der aktuelle Anwendungsstatus (wird benötigt)
 * @returns {HTMLDivElement}
 */
function createTimetableElement(entry, isTemplateEditor = false, state) {
    const entryElement = document.createElement('div');
    entryElement.className = 'planner-entry timetable-entry';
    
    // WICHTIG: IDs für die Interaktion speichern
    if (isTemplateEditor) {
        entryElement.dataset.templateEntryId = entry.template_entry_id; // ID aus Template-Tabelle
        if (entry.block_ref) {
            entryElement.dataset.blockId = entry.block_ref; // Verwende block_ref im Editor
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
        if (entry.class_id == 0) className = 'Alle'; // Spezialfall für "Keine Klasse" im Template
    } else {
        subjectShortcut = entry.subject_shortcut || '---';
        teacherShortcut = entry.teacher_shortcut || '---';
        roomName = entry.room_name || '---';
        className = entry.class_name || 'N/A';
    }
    
    // KORREKTUR: Logik für Lehreransicht (Klasse + ID)
    let mainHtml = '';
    if (isTeacherView) {
        const classDisplay = escapeHtml(className);
        // Zeige (ID: 0) nicht an, wenn es "Keine Klasse" ist (Template-Editor)
        if (entry.class_id && entry.class_id != 0) {
            mainHtml = `<div class="entry-line entry-main">${classDisplay} (ID: ${escapeHtml(entry.class_id)})</div>`;
        } else {
            mainHtml = `<div class="entry-line entry-main">${classDisplay}</div>`;
        }
    } else {
        mainHtml = `<div class="entry-line entry-main">${escapeHtml(teacherShortcut)}</div>`;
    }
    // ENDE KORREKTUR

    entryElement.innerHTML = `
        <div class="entry-line entry-subject">${escapeHtml(subjectShortcut)}</div>
        ${mainHtml}
        <div class="entry-line entry-room">${escapeHtml(roomName)}</div>
    `;

    if (entry.comment) {
        entryElement.classList.add('has-comment');
        entryElement.title = `Kommentar: ${escapeHtml(entry.comment)}`;
    }

    // Abwesenheits-Check (nur im normalen Modus)
    // KORREKTUR: Prüfung auf state.stammdaten.absences
    if (!isTemplateEditor && entry.teacher_id && state.stammdaten.absences && state.stammdaten.absences.length > 0) {
        let entryDate;
        try {
            // KORREKTUR: Verwende state.selectedYear/Week statt DOM
            const dto = new Date();
            // KORREKTUR: Verwende setISODate (UTC-basiert, aber erzeugt lokales Datumsobjekt)
            dto.setUTCFullYear(state.selectedYear);
            dto.setUTCMonth(0); // Jan
            dto.setUTCDate(1); // 1. Jan
            // Finde den Montag der ersten Woche
            let dayOfWeek = dto.getUTCDay();
            let firstMonday = (dayOfWeek <= 1) ? (2 - dayOfWeek) : (9 - dayOfWeek);
            dto.setUTCDate(firstMonday);
            // Füge die Wochen hinzu
            dto.setUTCDate(dto.getUTCDate() + (state.selectedWeek - 1) * 7);
            // Füge den Tag hinzu
            dto.setUTCDate(dto.getUTCDate() + (entry.day_of_week - 1));
            
            entryDate = dto.toISOString().split('T')[0];
        } catch(e) {
            console.error("Datumsberechnung fehlgeschlagen", e);
            entryDate = null;
        }

        if(entryDate) {
            const absence = isTeacherAbsent(entry.teacher_id, entryDate, state); // State übergeben
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

/**
 * Erstellt ein einzelnes HTML-Element (DIV) für einen Vertretungseintrag.
 * @param {object} sub - Das Vertretungsobjekt.
 * @param {object} state - Der aktuelle Anwendungsstatus.
 * @returns {HTMLDivElement}
 */
function createSubstitutionElement(sub, state) {
    const entryElement = document.createElement('div');
    entryElement.className = `planner-entry substitution-entry ${sub.substitution_type.toLowerCase()}`;
    
    // WICHTIG: IDs für die Interaktion speichern
    entryElement.dataset.substitutionId = sub.substitution_id;
    entryElement.dataset.day = sub.day_of_week;
    entryElement.dataset.period = sub.period_number;
    entryElement.dataset.date = sub.date;
    // Füge auch die zugrundeliegende Eintrags-ID hinzu (falls vorhanden), um das Modal zu füllen
    const originalEntry = getOriginalEntry(sub, state); // KORREKTUR: state übergeben
    if (originalEntry) {
        entryElement.dataset.entryId = originalEntry.entry_id;
        if(originalEntry.block_id) entryElement.dataset.blockId = originalEntry.block_id;
    }


    const isTeacherView = state.currentViewMode === 'teacher';
    let subjectText = '---';
    let mainText = '---';
    let roomText = '---';
    let typeText = sub.substitution_type;

    // KORREKTUR: Logik für Lehreransicht (Klasse + ID)
    let classDisplay = escapeHtml(sub.class_name || 'N/A');
    // Zeige ID nur an, wenn sie vorhanden und nicht 0 ist
    if (sub.class_id && sub.class_id != 0) {
        classDisplay += ` (ID: ${escapeHtml(sub.class_id)})`;
    }
    // ENDE KORREKTUR

    switch (sub.substitution_type) {
        case 'Vertretung':
            subjectText = sub.new_subject_shortcut || sub.original_subject_shortcut || '---';
            // KORREKTUR: Verwende classDisplay
            mainText = isTeacherView ? classDisplay : (sub.new_teacher_shortcut || '!!!');
            roomText = sub.new_room_name || '---';
            break;
        case 'Raumänderung':
            typeText = 'Raum'; // Kürzer
            subjectText = sub.original_subject_shortcut || '---';
            // KORREKTUR: Verwende classDisplay
            mainText = isTeacherView ? classDisplay : (getOriginalTeacher(sub, state) || '---'); // KORREKTUR: state übergeben
            roomText = sub.new_room_name || '!!!';
            break;
        case 'Entfall':
            subjectText = sub.original_subject_shortcut || '---';
            // KORREKTUR: Verwende classDisplay
            mainText = `(${isTeacherView ? classDisplay : (getOriginalTeacher(sub, state) || '---')})`; // KORREKTUR: state übergeben
            roomText = '---';
            break;
        case 'Sonderevent':
            typeText = 'Event'; // Kürzer
            subjectText = sub.new_subject_shortcut || 'EVENT';
            mainText = sub.comment ? (sub.comment.substring(0, 10) + '...') : 'Info';
            roomText = sub.new_room_name || '---';
            break;
    }

    entryElement.innerHTML = `
        <div class="entry-line sub-type">${escapeHtml(typeText)}</div>
        <div class="entry-line entry-subject">${escapeHtml(subjectText)}</div>
        <div class="entry-line entry-main">${mainText}</div> <!-- mainText ist bereits escaped oder HTML -->
        <div class="entry-line entry-room">${escapeHtml(roomText)}</div>
    `;
    
    if (sub.comment && sub.substitution_type !== 'Sonderevent') {
         entryElement.title = `Kommentar: ${escapeHtml(sub.comment)}`;
    }

    return entryElement;
}


/**
 * Prüft, ob ein Lehrer an einem bestimmten Datum abwesend ist.
 * @param {number} teacherId 
 * @param {string} dateString (YYYY-MM-DD)
 * @param {object} state - Der aktuelle Anwendungsstatus.
* @returns {object|null} - Das Abwesenheitsobjekt oder null.
 */
function isTeacherAbsent(teacherId, dateString, state) {
    if (!teacherId || !dateString) return null;
    
    // KORREKTUR: Zugriff auf state.stammdaten.absences
    const absences = (state.stammdaten && state.stammdaten.absences) ? state.stammdaten.absences : [];
    if (absences.length === 0) return null;

    // Konvertiere Datum in ein Objekt für einfachen Vergleich
    // (UTC, um Zeitzonenprobleme beim reinen Datumsvergleich zu vermeiden)
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

/**
 * Findet den ursprünglichen regulären Eintrag für eine Vertretung.
 * @param {object} sub 
 * @param {object} state - Der aktuelle Anwendungsstatus.
 * @returns {object|null}
 */
function getOriginalEntry(sub, state) {
    // KORREKTUR: state.timetable ist jetzt eine Map
    const cellKey = `${sub.day_of_week}-${sub.period_number}`;
    const entries = state.timetable[cellKey] || [];
    
    // Finde den Eintrag, der zur Klasse UND Fach passt
    const originalEntry = entries.find(e => 
        e.class_id == sub.class_id && 
        e.subject_id == sub.original_subject_id
    );

    return originalEntry || null;
}

/**
 * Versucht, das Kürzel des ursprünglichen Lehrers einer Vertretung zu finden.
 * @param {object} sub 
 * @param {object} state - Der aktuelle Anwendungsstatus.
 * @returns {string|null} - Das Kürzel des Lehrers oder null.
 */
function getOriginalTeacher(sub, state) {
    // KORREKTUR: state übergeben
    const originalEntry = getOriginalEntry(sub, state);
    if (originalEntry) {
        // Versuche, das Kürzel aus dem Eintrag zu holen
        if (originalEntry.teacher_shortcut) {
            return originalEntry.teacher_shortcut;
        }
        // Fallback: Suche in Stammdaten (falls shortcut fehlt)
        // KORREKTUR: Zugriff auf state.stammdaten.teachers
        const teacher = (state.stammdaten.teachers || []).find(t => t.teacher_id == originalEntry.teacher_id);
        if (teacher) {
            return teacher.teacher_shortcut;
        }
    }
    return null; // Konnte nicht gefunden werden
}