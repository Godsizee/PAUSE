        import * as DOM from './planer-dom.js';
        import { escapeHtml, getDateForDayInWeek } from './planer-utils.js'; // getDateForDayInWeek importieren
        import { getState } from './planer-state.js'; // Import getState

        /**
         * Rendert das Haupt-Stundenplan-Grid oder das Template-Editor-Grid.
        * @param {object} state - Das aktuelle Anwendungs-State-Objekt.
        * @param {boolean} [isTemplateEditor=false] - Ob das Grid für den Template-Editor gerendert wird.
        */
        export const renderTimetable = (state, isTemplateEditor = false) => {
            console.log("Starte renderTimetable...", { isTemplateEditor }); // Logging hinzugefügt
            const container = isTemplateEditor ? DOM.templateEditorGridContainer : DOM.timetableContainer;
            if (!container) {
                console.error("renderTimetable: Container nicht gefunden!", { isTemplateEditor });
                return;
            }

            // Determine data source based on mode
            const timetableData = isTemplateEditor ? (state.currentTemplateEditorData || []) : state.currentTimetable;
            const substitutionsData = isTemplateEditor ? [] : state.currentSubstitutions;
            // NEU: Abwesenheiten aus dem State holen
            const absencesData = isTemplateEditor ? [] : (state.stammdaten?.absences || []);
            const viewMode = isTemplateEditor ? 'class' : state.currentViewMode; // Default to class view for template editor
            const { stammdaten } = state; // Get Stammdaten from state
            // KORREKTUR: userRole aus globalem Config-Objekt holen
            const userRole = window.APP_CONFIG.userRole || 'guest';

            console.log("Daten für Rendering:", { timetableData, substitutionsData, absencesData, viewMode }); // Logging hinzugefügt

            // --- Block Processing (same as before) ---
            const processedCellKeys = new Set();
            const blockSpans = new Map();

            // 1. Process regular blocks (using block_id or block_ref)
            const blocks = new Map();
            timetableData.forEach(entry => {
                const blockIdentifier = isTemplateEditor ? entry.block_ref : entry.block_id;
                if (blockIdentifier) {
                    if (!blocks.has(blockIdentifier)) blocks.set(blockIdentifier, []);
                    blocks.get(blockIdentifier).push(entry);
                }
            });
            blocks.forEach(entries => {
                if (entries.length > 0) { // Ensure block has entries
                    entries.sort((a, b) => a.period_number - b.period_number);
                    const startEntry = entries[0];
                    const span = entries[entries.length - 1].period_number - startEntry.period_number + 1;
                    blockSpans.set(`${startEntry.day_of_week}-${startEntry.period_number}`, span);
                    // Mark cells covered by the block as processed
                    for (let i = 1; i < span; i++) {
                        processedCellKeys.add(`${startEntry.day_of_week}-${startEntry.period_number + i}`);
                    }
                }
            });

            // 2. Process substitution blocks (only in main dashboard view)
            if (!isTemplateEditor) {
                const substitutionBlocks = new Map();
                substitutionsData.forEach(sub => {
                    // day_of_week should already be calculated in the API response or planer-api.js
                    if (!sub.day_of_week) return;
                    // Group potentially related substitutions (same day, class, type, comment, new room etc.)
                    const key = `${sub.date}-${sub.class_id}-${sub.substitution_type}-${sub.comment || ''}-${sub.new_room_id || ''}-${sub.new_teacher_id || ''}-${sub.new_subject_id || ''}`;
                    if (!substitutionBlocks.has(key)) substitutionBlocks.set(key, []);
                    substitutionBlocks.get(key).push(sub);
                });
                substitutionBlocks.forEach(subs => {
                    if (subs.length > 1) { // Only process groups with more than one entry
                        subs.sort((a, b) => a.period_number - b.period_number);
                        let isConsecutive = true;
                        // Check if periods are consecutive
                        for (let i = 0; i < subs.length - 1; i++) {
                            if (subs[i + 1].period_number !== subs[i].period_number + 1) {
                                isConsecutive = false; break;
                            }
                        }
                        if (isConsecutive) { // If consecutive, treat as a block
                            const startSub = subs[0];
                            const span = subs.length;
                            const dayNum = startSub.day_of_week;
                            if (dayNum) {
                                blockSpans.set(`${dayNum}-${startSub.period_number}`, span);
                                // Mark cells covered by the substitution block as processed
                                for (let i = 1; i < span; i++) {
                                    processedCellKeys.add(`${dayNum}-${startSub.period_number + i}`);
                                }
                            }
                        }
                    }
                });
            }

            // 3. Render Grid HTML
            let gridHTML = `<div class="timetable-grid ${isTemplateEditor ? 'template-editor-grid' : ''}">`;
            // Add header row (Time + Days)
            gridHTML += '<div class="grid-header"></div>'; // Empty top-left cell
            DOM.days.forEach(day => gridHTML += `<div class="grid-header">${day}</div>`); // Day headers

            // NEU: Aktuelles Jahr und Woche für Datumsberechnung holen
            const currentYear = DOM.yearSelector ? DOM.yearSelector.value : new Date().getFullYear();
            const currentWeek = DOM.weekSelector ? DOM.weekSelector.value : 1;

            // Add rows for each time slot
            DOM.timeSlots.forEach((slot, index) => {
                const period = index + 1;
                // Add time slot header for the row
                gridHTML += `<div class="grid-header period-header">${slot}</div>`;

                // Add cells for each day in the current row
                DOM.days.forEach((day, dayIndex) => {
                    const dayNum = dayIndex + 1; // 1=Mon, ..., 5=Fri
                    const cellKey = `${dayNum}-${period}`;
                    const noteKey = cellKey; // NEU

                    // Skip rendering if this cell is covered by a block starting earlier
                    if (processedCellKeys.has(cellKey)) { return; }

                    // --- Prepare cell data ---
                    let cellContent = '', cellClass = 'empty', dataAttrs = `data-day="${dayNum}" data-period="${period}"`, style = '';
                    if (isTemplateEditor) cellClass += ' template-cell'; // Add class for template editor cells

                    // Check if this cell is the start of a block
                    const span = blockSpans.get(cellKey);
                    if (span) {
                        style = `grid-row: span ${span};`; // Apply rowspan styling
                        cellClass += ' block-start'; // Add class for block start
                    }

                    // Find substitution or regular entry for this cell
                    const substitution = isTemplateEditor ? null : substitutionsData.find(s => s.day_of_week == dayNum && s.period_number == period);
                    const entryToRender = timetableData.find(e => e.day_of_week == dayNum && e.period_number == period);
                    // KORREKTUR: Verwende die definierte userRole Variable und prüfe, ob studentNotes existiert
                    const note = (userRole === 'schueler' && state.studentNotes && state.studentNotes[noteKey]) ? state.studentNotes[noteKey] : null;

                    // NEU: Abwesenheitsprüfung (nur im Haupt-Dashboard, nicht im Template-Editor)
                    let isTeacherAbsent = false;
                    if (!isTemplateEditor && entryToRender && entryToRender.teacher_id) {
                        const entryDate = getDateForDayInWeek(dayNum, currentYear, currentWeek);
                        isTeacherAbsent = absencesData.some(abs => 
                            abs.teacher_id == entryToRender.teacher_id && 
                            entryDate >= abs.start_date && 
                            entryDate <= abs.end_date
                        );
                    }


                    dataAttrs = `data-day="${dayNum}" data-period="${period}"`; // Basis-Attribute

                    // --- Populate cell content and attributes ---
                    if (substitution) { // Substitution exists
                        cellClass = `has-entry substitution-${substitution.substitution_type}`;
                        dataAttrs += ` data-substitution-id="${substitution.substitution_id}"`;
                        if (substitution.comment) dataAttrs += ` data-comment="${escapeHtml(substitution.comment)}"`;
                        dataAttrs += ` draggable="true"`; // Substitutions are draggable
                        const regularEntry = entryToRender; // Find corresponding regular entry for context
                        dataAttrs += ` data-class-id="${substitution.class_id}"`; // Add class ID for context

                        // Generate HTML content for the substitution cell
                        // KORREKTUR: 'note' als 7. Argument übergeben
                        cellContent = createCellEntryHtml(
                            // Determine subject (new, original, or default)
                            substitution.substitution_type === 'Vertretung' ? (substitution.new_subject_shortcut || regularEntry?.subject_shortcut) : (substitution.substitution_type === 'Sonderevent' ? 'EVENT' : regularEntry?.subject_shortcut),
                            // Determine main text (teacher/class or status)
                            substitution.substitution_type === 'Vertretung'
                                ? (viewMode === 'teacher' ? (substitution.class_name || regularEntry?.class_name) : substitution.new_teacher_shortcut)
                                : (substitution.substitution_type === 'Entfall' ? 'Entfällt' : (regularEntry ? (viewMode === 'class' ? regularEntry.teacher_shortcut : regularEntry.class_name) : '---')),
                            // Determine room (new or original)
                            substitution.new_room_name || regularEntry?.room_name,
                            substitution.comment, // Substitution comment
                            substitution.substitution_type, // Type for specific styling
                            false, // isTeacherAbsent (false für Vertretungen)
                            note // note
                        );
                    } else if (entryToRender) { // Regular entry exists
                        cellClass = 'has-entry';
                        // NEU: Klasse hinzufügen, wenn Lehrer abwesend ist
                        if (isTeacherAbsent) {
                            cellClass += ' teacher-absent';
                        }
                        // Add entry/block IDs and class ID as data attributes
                        dataAttrs += isTemplateEditor ? ` data-template-entry-id="${entryToRender.template_entry_id}"` : ` data-entry-id="${entryToRender.entry_id}"`;
                        dataAttrs += ` data-class-id="${entryToRender.class_id}"`;
                        const blockIdentifier = isTemplateEditor ? entryToRender.block_ref : entryToRender.block_id;
                        if (blockIdentifier) dataAttrs += ` data-block-id="${blockIdentifier}"`;
                        dataAttrs += ` draggable="true"`; // Regular entries/blocks are draggable
                        
                        // Get display names from Stammdaten if in template editor, otherwise use pre-joined names
                        const subject = isTemplateEditor ? (stammdaten.subjects?.find(s => s.subject_id == entryToRender.subject_id)?.subject_shortcut || '?!') : entryToRender.subject_shortcut;
                        const teacher = isTemplateEditor ? (stammdaten.teachers?.find(t => t.teacher_id == entryToRender.teacher_id)?.teacher_shortcut || '?!') : entryToRender.teacher_shortcut;
                        const room = isTemplateEditor ? (stammdaten.rooms?.find(r => r.room_id == entryToRender.room_id)?.room_name || '?!') : entryToRender.room_name;
                        const className = isTemplateEditor ? (stammdaten.classes?.find(c => c.class_id == entryToRender.class_id)?.class_name || '?!') : entryToRender.class_name;
                        // Determine main text based on view mode
                        const mainText = viewMode === 'class' ? teacher : className;
                        
                        // Generate HTML content for the regular cell
                        // KORREKTUR: 'isTeacherAbsent' als 6. und 'note' als 7. Argument übergeben
                        cellContent += createCellEntryHtml(subject, mainText, room, entryToRender.comment, null, isTeacherAbsent, note);
                    } else if (!isTemplateEditor && (period === 1 || period === 10)) { // Default entry (FU) - only in main dashboard
                        cellClass = 'default-entry';
                        // KORREKTUR: Argumente korrekt übergeben
                        cellContent = createCellEntryHtml('FU', 'Förderunterricht', '', '', null, false, null); // FU anzeigen
                        dataAttrs += ` draggable="false"`; // FU is not draggable
                    } else {
                        // Empty cell (draggable only in template editor for adding new entries)
                        if (isTemplateEditor) dataAttrs += ` draggable="false"`; // Empty cells in template editor are not draggable sources
                        else dataAttrs += ` draggable="false"`; // Empty cells in main view are not draggable
                    }

                    // Append the cell HTML to the grid row
                    gridHTML += `<div class="grid-cell ${cellClass}" ${dataAttrs} style="${style}">${cellContent}</div>`;
                });
            });

            gridHTML += '</div>'; // Close timetable-grid
            container.innerHTML = gridHTML; // Set the generated HTML into the container
            console.log("renderTimetable abgeschlossen."); // Logging hinzugefügt
        };


        /**
         * Erstellt das HTML-Markup für den Inhalt einer einzelnen Zelle.
        * @param {string} subject - Fach-Kürzel
        * @param {string} mainText - Lehrer-Kürzel oder Klassenname oder Status ('Entfällt')
        * @param {string} room - Raumname
        * @param {string} [comment=null] - Optionaler Kommentar
        * @param {string} [substitutionType=null] - Optionaler Vertretungstyp
        * @param {boolean} [isTeacherAbsent=false] - NEU: Flag für Abwesenheit
        * @param {string} [note=null] - NEU: Flag für Notiz
        * @returns {string} - Das gerenderte HTML für die Zelle
        */
        export const createCellEntryHtml = (subject, mainText, room, comment = null, substitutionType = null, isTeacherAbsent = false, note = null) => {
            // Escape all input values to prevent XSS
            const safeSubject = escapeHtml(subject);
            const safeMainText = escapeHtml(mainText);
            const safeRoom = escapeHtml(room);
            const safeComment = escapeHtml(comment);
        
            // Prepare HTML parts
            let commentHtml = safeComment ? `<small class="entry-comment" title="${safeComment}">📝 ${safeComment.substring(0, 15)}${safeComment.length > 15 ? '...' : ''}</small>` : '';
            let roomHtml = safeRoom ? `<small class="entry-room">${safeRoom}</small>` : '';
            let mainHtml = safeMainText ? `<span>${safeMainText}</span>` : '';
            let subjectHtml = safeSubject ? `<strong>${safeSubject}</strong>` : '';
            // NEU: HTML für Abwesenheitswarnung
            let absenceHtml = isTeacherAbsent ? `<small class="absence-warning" title="Lehrer ist als abwesend gemeldet!">⚠️ Lehrer abwesend</small>` : '';

            // NEU: Notiz-Icon (SVG)
            const noteIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1.5 0A1.5 1.5 0 0 0 0 1.5V13a1 1 0 0 0 1 1V1.5a.5.5 0 0 1 .5-.5H14a1 1 0 0 0-1-1zM3.5 2A1.5 1.5 0 0 0 2 3.5v11A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-11A1.5 1.5 0 0 0 12.5 2zM3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5z"/></svg>`;
            // KORREKTUR: Verwende window.APP_CONFIG.userRole
            let noteHtml = (note && window.APP_CONFIG.userRole === 'schueler') ? `<small class="entry-note" title="${escapeHtml(note)}">${noteIcon}</small>` : '';


            // Adjust HTML based on substitution type
            if (substitutionType === 'Entfall') {
                // Display "Entfällt" prominently, keep original subject, hide room/original main text
                subjectHtml = `<strong>${safeSubject}</strong>`; // Keep original subject visible
                mainHtml = `<span>Entfällt</span>`; // Indicate cancellation
                roomHtml = ''; // Hide room for cancellation
                // Use comment field for potential original teacher/class info if needed, or hide it
                commentHtml = safeComment ? `<small class="entry-comment" title="${safeComment}">📝 ${safeComment.substring(0, 15)}${safeComment.length > 15 ? '...' : ''}</small>` : ''; // Keep substitution comment
                absenceHtml = ''; // Keine Abwesenheitswarnung bei Entfall
            }
            if (substitutionType === 'Raumänderung') {
                // Highlight the new room
                roomHtml = safeRoom ? `<small class="entry-room" style="font-weight:bold; color: var(--color-warning);">${safeRoom}</small>` : '';
                // Bei Raumänderung ist der Lehrer ja anwesend (hoffentlich)
                absenceHtml = '';
            }
            if (substitutionType === 'Sonderevent') {
                subjectHtml = `<strong>EVENT</strong>`; // Use EVENT as subject
                // Use comment as the main description, or fallback text
                mainHtml = safeComment ? `<span title="${safeComment}">${safeComment.substring(0, 20)}${safeComment.length > 20 ? '...' : ''}</span>` : `<span>Sonderveranst.</span>`;
                commentHtml = ''; // Comment is already displayed as main text
                absenceHtml = ''; // Keine Abwesenheitswarnung bei Sonderevent
            }
        
            // Combine parts into the final cell entry HTML
            // NEU: absenceHtml hinzugefügt
            // KORREKTUR: noteHtml hinzugefügt
            return `<div class="cell-entry">${noteHtml}${subjectHtml}${mainHtml}${roomHtml}${commentHtml}${absenceHtml}</div>`;
        };

