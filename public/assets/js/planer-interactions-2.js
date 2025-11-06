// public/assets/js/planer-interactions-2.js
// KORRIGIERT: Drag-Start (dragstart) Logik. Berechnet Span/IDs jetzt korrekt aus dem State.

import * as DOM from './planer-dom.js';
import { getState, updateState, clearSelectionState, setSelectionState } from './planer-state.js';
import { getWeekAndYear, getDateOfISOWeek, getDateForDayInWeek, escapeHtml } from './planer-utils.js';
// KORREKTUR: Import 'renderTimetableGrid'
import { loadPlanData, publishWeek, checkConflicts, saveEntry, deleteEntry, saveSubstitution, deleteSubstitution, copyWeek, loadTemplates, createTemplate, applyTemplate, deleteTemplate, loadTemplateDetails, saveTemplate } from './planer-api.js';
// KORREKTUR: Import 'renderTimetableGrid'
import { renderTimetableGrid } from './planer-timetable.js';
import { populateYearSelector, populateWeekSelector, populateTemplateSelects, showTemplateView, showConflicts, hideConflicts, populateAllModalSelects } from './planer-ui.js';
// Import notifications
import { showToast, showConfirm } from './notifications.js';
// Import functions from part 1
import { setDefaultSelectors, handleCellClick, initializeEntryModal, openModal, closeModal, switchMode, updateSubstitutionFields, updateDeleteButtonVisibility } from './planer-interactions-1.js';

/**
 * Initialisiert alle Event-Listener und Hauptinteraktionen für die Planer-Oberfläche.
 */
export function initializePlanerInteractions() {

    // Setze Standard-Selektoren
    setDefaultSelectors();
    // Initialisiere Modal-Logik
    initializeEntryModal(); // From part 1

    // UI-Handler
    const handleDateOrWeekChange = () => {
        const dateVal = DOM.dateSelector.value;
        if (dateVal) {
            const dateObj = new Date(dateVal + 'T00:00:00'); // Ensure local time interpretation
            const { week, year } = getWeekAndYear(dateObj);
            // If date change resulted in a new week/year, update selectors and reload
            if (DOM.yearSelector.value != year || DOM.weekSelector.value != week) {
                DOM.yearSelector.value = year;
                DOM.weekSelector.value = week;
                loadPlanData(); // Reload plan for the new week/year
                return; // Prevent double loading
            }
        }
        // If only week/year changed, or date didn't change the week/year, just reload
        loadPlanData();
    };
    const handleViewModeChange = () => {
        updateState({ currentViewMode: DOM.viewModeSelector.value });
        if (getState().currentViewMode === 'class') {
            DOM.classSelectorContainer.classList.remove('hidden');
            DOM.teacherSelectorContainer.classList.add('hidden');
            DOM.teacherSelector.value = ''; // Reset teacher selection
        } else {
            DOM.classSelectorContainer.classList.add('hidden');
            DOM.teacherSelectorContainer.classList.remove('hidden');
            DOM.classSelector.value = ''; // Reset class selection
        }
        loadPlanData(); // Load data for the new view mode
    };
    const handlePublishAction = async (target, publish = true) => {
        const year = DOM.yearSelector.value;
        const week = DOM.weekSelector.value;
        if (!year || !week) {
            showToast("Bitte Jahr und KW auswählen.", 'error');
            return;
        }
        try {
            const response = await publishWeek(target, publish); // API call from planer-api.js
            if (response.success) {
                showToast(response.message, 'success');
                loadPlanData(); // Reload data to reflect new publish status
            }
            // Errors handled by apiFetch
        } catch(error) {}
    };

    // Event Listeners
    DOM.viewModeSelector.addEventListener('change', handleViewModeChange);
    // Wrapper für loadPlanData()
    DOM.classSelector.addEventListener('change', () => loadPlanData());
    DOM.teacherSelector.addEventListener('change', () => loadPlanData());
    DOM.yearSelector.addEventListener('change', handleDateOrWeekChange);
    DOM.weekSelector.addEventListener('change', handleDateOrWeekChange);
    DOM.dateSelector.addEventListener('change', handleDateOrWeekChange);

    DOM.publishStudentBtn.addEventListener('click', () => handlePublishAction('student', true));
    DOM.unpublishStudentBtn.addEventListener('click', () => handlePublishAction('student', false));
    DOM.publishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', true));
    DOM.unpublishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', false));

    // Grid Interaktionen (Click/DblClick)
    DOM.timetableContainer.addEventListener('click', (e) => {
        const cell = e.target.closest('.grid-cell');
        const state = getState();
        // Ignore clicks outside cells or if no class/teacher is selected
        if (!cell || !(state.selectedClassId || state.selectedTeacherId)) {
            clearSelectionState(state.selection.cells); // Clear selection if clicking outside valid area
            return;
        }
        handleCellClick(cell, DOM.timetableContainer);

        // Modal bei Doppelklick
        if (e.detail === 2) {
            openModal(e, false);
        }
    });


    // --- Drag & Drop ---
    DOM.timetableContainer.addEventListener('dragstart', (e) => {
        // Ziele auf .planner-entry
        const entryElement = e.target.closest('.planner-entry');
        const cell = e.target.closest('.grid-cell');
        const state = getState();

        // Prüfe, ob Zelle draggbar ist (hat Inhalt) UND ob wir auf ein Entry geklickt haben
        if (!cell || !entryElement || !cell.classList.contains('has-entry') || !(state.selectedClassId || state.selectedTeacherId)) {
            e.preventDefault(); return;
        }

        // Drag-Daten werden in renderTimetableGrid gesetzt

        const entryId = entryElement.dataset.entryId;
        const blockId = entryElement.dataset.blockId;
        const substitutionId = entryElement.dataset.substitutionId;

        let entryData = null; // Data of the dragged item (first entry of block, or single)
        let entryType = null;
        let span = 1;
        let blockStartCell = cell; // Cell representing the start of the block/entry
        let originalSubIds = []; // Stores IDs for each hour of a substitution block
        let originalRegularEntryId = null; // Store ID of underlying regular entry/block start
        let originalRegularBlockId = null;
        let underlyingRegularEntries = []; // *** Store ALL underlying entries ***

        const currentDay = cell.dataset.day;
        const currentPeriod = parseInt(cell.dataset.period);
        const cellKey = cell.dataset.cellKey;

        if (substitutionId) {
            entryType = 'substitution';
            // Finde den spezifischen Eintrag aus der Map
            const subsInCell = (state.substitutions[cellKey] || []);
            entryData = subsInCell.find(s => s.substitution_id == substitutionId);
            
            if (!entryData) {
                console.error("DragStart Error: Could not find substitution data for dragged item.", substitutionId);
                e.preventDefault(); return;
            }
            
            // Span für Vertretung neu berechnen
            const key = `${entryData.date}-${entryData.class_id}-${entryData.substitution_type}-${entryData.comment || ''}-${entryData.new_room_id || ''}-${entryData.new_teacher_id || ''}-${entryData.new_subject_id || ''}`;
            const substitutionBlocks = new Map();
            state.currentSubstitutions.forEach(sub => {
                if (!sub.day_of_week) return;
                const subKey = `${sub.date}-${sub.class_id}-${sub.substitution_type}-${sub.comment || ''}-${sub.new_room_id || ''}-${sub.new_teacher_id || ''}-${sub.new_subject_id || ''}`;
                if (!substitutionBlocks.has(subKey)) substitutionBlocks.set(subKey, []);
                substitutionBlocks.get(subKey).push(sub);
            });

            const subsInBlock = substitutionBlocks.get(key) || [];
            if (subsInBlock.length > 1) {
                subsInBlock.sort((a, b) => a.period_number - b.period_number);
                let isConsecutive = true;
                for (let i = 0; i < subsInBlock.length - 1; i++) {
                    if (parseInt(subsInBlock[i + 1].period_number) !== parseInt(subsInBlock[i].period_number) + 1) {
                        isConsecutive = false; break;
                    }
                }
                if (isConsecutive) {
                    const startSub = subsInBlock[0];
                    // Prüfe, ob der geklickte Eintrag der Start-Eintrag ist
                    if (startSub.substitution_id == substitutionId) {
                        span = subsInBlock.length;
                        entryData = startSub; // Stelle sicher, dass wir den Start-Eintrag ziehen
                        blockStartCell = cell;
                    } else {
                        // Teil eines Blocks gezogen. Finde Start-Zelle.
                        span = subsInBlock.length;
                        entryData = startSub;
                        blockStartCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${startSub.day_of_week}'][data-period='${startSub.period_number}']`);
                    }
                }
            }
            // ENDE SPAN (Sub)

            originalSubIds = [];
            underlyingRegularEntries = [];
            const allSubsMap = state.substitutions;
            const allTimetableMap = state.timetable;

            for (let i = 0; i < span; i++) {
                const periodToCheck = parseInt(entryData.period_number) + i;
                const keyToCheck = `${entryData.day_of_week}-${periodToCheck}`;
                
                // Finde die Vertretung für diese Stunde (basierend auf Ähnlichkeit)
                const subForThisHour = (allSubsMap[keyToCheck] || []).find(s =>
                    s.class_id == entryData.class_id &&
                    s.substitution_type == entryData.substitution_type &&
                    s.new_teacher_id == entryData.new_teacher_id &&
                    s.new_subject_id == entryData.new_subject_id &&
                    s.new_room_id == entryData.new_room_id &&
                    s.comment == entryData.comment
                );

                if (subForThisHour) {
                    originalSubIds.push(subForThisHour.substitution_id);
                    // Finde zugrundeliegenden regulären Eintrag
                    const regularEntry = (allTimetableMap[keyToCheck] || []).find(e =>
                        e.class_id == subForThisHour.class_id &&
                        e.subject_id == subForThisHour.original_subject_id
                    );
                    if (regularEntry) {
                        underlyingRegularEntries.push(regularEntry);
                        if (i === 0) {
                            originalRegularBlockId = regularEntry.block_id || null;
                            originalRegularEntryId = regularEntry.entry_id || null;
                        }
                    } else {
                        underlyingRegularEntries.push(null);
                    }
                } else {
                    // Fallback
                    originalSubIds.push(null);
                    underlyingRegularEntries.push(null);
                    console.warn(`DragStart: Konnte Vertretung für Periode ${periodToCheck} im Block nicht finden.`);
                }
            }

        } else if (blockId) {
            entryType = 'block';
            // Finde in Map
            const entriesInCell = (state.timetable[cellKey] || []);
            entryData = entriesInCell.find(e => e.block_id == blockId);
            if (!entryData) { e.preventDefault(); return; }
            
            // Span für Regulären Block neu berechnen
            const allEntriesInBlock = state.currentTimetable.filter(e => e.block_id === blockId);
            if (allEntriesInBlock.length > 0) {
                 allEntriesInBlock.sort((a, b) => a.period_number - b.period_number);
                 const startEntry = allEntriesInBlock[0];
                 span = parseInt(allEntriesInBlock[allEntriesInBlock.length - 1].period_number) - parseInt(startEntry.period_number) + 1;
                 if (startEntry.entry_id !== entryData.entry_id) {
                     blockStartCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${startEntry.day_of_week}'][data-period='${startEntry.period_number}']`);
                     entryData = startEntry; // Ziehe den *Start* des Blocks
                 }
            } else {
                span = 1;
            }
            // ENDE SPAN (Block)

            originalRegularBlockId = blockId;

        } else if (entryId) {
            entryType = 'entry';
            // Finde in Map
            const entriesInCell = (state.timetable[cellKey] || []);
            entryData = entriesInCell.find(e => e.entry_id == entryId);
            if (!entryData) { e.preventDefault(); return; }
            
            span = 1;
            originalRegularEntryId = entryId;
        }

        if (!entryData) {
            console.error("DragStart Error: Could not find entry data for dragged item.", { entryId, blockId, substitutionId });
            e.preventDefault();
            return;
         }

        clearSelectionState(state.selection.cells);
        updateState({
            dragData: {
                type: entryType,
                data: entryData,
                span: span,
                originalDay: blockStartCell.dataset.day,
                originalPeriod: parseInt(blockStartCell.dataset.period),
                originalSubIds: originalSubIds, // Store substitution IDs
                originalRegularEntryId: originalRegularEntryId, // Store underlying entry ID (of first hour)
                originalRegularBlockId: originalRegularBlockId,  // Store underlying block ID (of first hour)
                underlyingRegularEntries: underlyingRegularEntries || [] // *** Store ALL underlying entries ***
            }
        });
        console.log("Drag Start Data:", getState().dragData); // Log drag data

        setTimeout(() => {
            // Markiere die Zelle als 'dragging'
            // KORREKTUR: Verwende 'blockStartCell', da 'cell' nur der Klick-Ursprung sein könnte
            if (blockStartCell) blockStartCell.classList.add('dragging');
            
            // Wenn es ein Block ist, markiere alle Zellen des Blocks
            if (span > 1) {
                for (let i = 1; i < span; i++) {
                    const nextPeriod = parseInt(blockStartCell.dataset.period) + i;
                    const cellInBlock = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${blockStartCell.dataset.day}'][data-period='${nextPeriod}']`);
                    if (cellInBlock) {
                        cellInBlock.classList.add('dragging');
                    }
                }
            }
        }, 0);
        DOM.timetableContainer.classList.add('is-dragging');
    });

    DOM.timetableContainer.addEventListener('dragover', async (e) => {
        e.preventDefault(); // Necessary to allow dropping
        const cell = e.target.closest('.grid-cell');
        const state = getState();

        // Ignore if not over a valid cell, no drag data, or same cell as before
        if (!cell || !state.dragData || cell === state.lastDragOverCell) return;

        // Clear previous target styling
        if (state.lastDragOverCell) {
            state.lastDragOverCell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
            delete state.lastDragOverCell.dataset.conflictError;
        }
        updateState({ lastDragOverCell: cell }); // Store current cell
        cell.classList.add('drop-target'); // Basic target styling

        const targetDay = cell.dataset.day;
        const targetStartPeriod = parseInt(cell.dataset.period);
        const targetEndPeriod = targetStartPeriod + state.dragData.span - 1;

        // --- Basic validity checks ---
        // Check if it fits vertically
        if (targetEndPeriod > DOM.timeSlots.length) {
            cell.classList.add('drop-target-invalid');
            cell.dataset.conflictError = "Eintrag passt nicht auf den Plan (zu lang).";
            return;
        }

        // Check if any target cell is already occupied (by a DIFFERENT entry/block/sub)
         for (let p = targetStartPeriod; p <= targetEndPeriod; p++) {
             const targetCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${targetDay}'][data-period='${p}']`);
             // Check if targetCell exists AND has an entry (ignore placeholders like FU)
             if (targetCell && (targetCell.classList.contains('has-entry') || targetCell.classList.contains('has-substitution')) ) {
                 let isSelf = false; // Is the occupied cell part of the item being dragged?
                 const draggedItemType = state.dragData.type;
                
                 // isSelf Logik
                 if (draggedItemType === 'substitution') {
                     // Finde Sub-Einträge
                     const targetSubElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-substitution-id]'));
                     // Prüfe, ob Eintrag zu gezogenen IDs gehört
                     isSelf = targetSubElements.some(el => state.dragData.originalSubIds.includes(parseInt(el.dataset.substitutionId)));
                 } else if (draggedItemType === 'block') {
                     const targetEntryElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-block-id]'));
                     isSelf = targetEntryElements.some(el => el.dataset.blockId === state.dragData.originalRegularBlockId);
                 } else if (draggedItemType === 'entry') {
                     const targetEntryElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-entry-id]'));
                     isSelf = targetEntryElements.some(el => el.dataset.entryId === state.dragData.originalRegularEntryId);
                 }
                 // ENDE isSelf Logik

                 if (!isSelf) { // Von anderem Eintrag belegt
                     cell.classList.add('drop-target-invalid');
                     const conflictType = targetCell.classList.contains('has-substitution') ? "Vertretung" : "Unterricht";
                     cell.dataset.conflictError = `KONFLIKT (Slot belegt): In diesem Zeitraum existiert bereits ${conflictType}.`;
                     return;
                 }
             }
         }


        // API-Konfliktprüfung (nur für reguläre Einträge oder Vertretungen mit regulärer Basis)
        let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);

        if (!needsApiCheck) {
            cell.classList.add('drop-target-valid');
            updateState({ lastConflictCheckPromise: Promise.resolve({ success: true, conflicts: [] }) }); // Mock promise
            return;
        }

        // Bereite Daten für API-Prüfung vor (basierend auf regulärem Eintrag)
         // Verwende state.currentTimetable
         const regularEntryData = state.dragData.originalRegularBlockId
            ? (state.currentTimetable.find(e => e.block_id == state.dragData.originalRegularBlockId) || (state.dragData.underlyingRegularEntries[0] || null))
            : (state.currentTimetable.find(e => e.entry_id == state.dragData.originalRegularEntryId) || (state.dragData.underlyingRegularEntries[0] || null));

        // API-Prüfung nur, wenn reguläre Daten gefunden wurden
        if (!regularEntryData) {
            // Vertretung ohne Basis ist gültig (kein Check)
            cell.classList.add('drop-target-valid');
            updateState({ lastConflictCheckPromise: Promise.resolve({ success: true, conflicts: [] }) });
            return;
        }


        const checkData = {
            year: DOM.yearSelector.value,
            calendar_week: DOM.weekSelector.value,
            day_of_week: targetDay,
            start_period_number: targetStartPeriod,
            end_period_number: targetEndPeriod,
            teacher_id: regularEntryData.teacher_id, // Use teacher from the actual regular entry
            room_id: regularEntryData.room_id,     // Use room from the actual regular entry
            class_id: regularEntryData.class_id,   // Use class from the actual regular entry
            // Original-Eintrag von Prüfung ausschließen
            entry_id: state.dragData.originalRegularEntryId,
            block_id: state.dragData.originalRegularBlockId,
        };

        try {
            // Promise im State speichern
            const conflictCheckPromise = checkConflicts(checkData);
             updateState({ lastConflictCheckPromise: conflictCheckPromise });
            await conflictCheckPromise;
            // Wenn Promise resolved = gültig
             // Prüfe, ob Hover noch aktiv ist
             if (cell === getState().lastDragOverCell) {
                 cell.classList.add('drop-target-valid');
             }
        } catch (error) {
            // Wenn Promise rejected = ungültig
             if (cell === getState().lastDragOverCell) {
                 cell.classList.add('drop-target-invalid');
                 cell.dataset.conflictError = error.message; // Store the conflict message
             }
        }
    });

    DOM.timetableContainer.addEventListener('dragleave', (e) => {
        const cell = e.target.closest('.grid-cell');
        const state = getState();
        // Styling entfernen, wenn Zelle verlassen wird
        if (cell && cell === state.lastDragOverCell) {
            cell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
            delete cell.dataset.conflictError;
            updateState({ lastDragOverCell: null }); // Reset last hovered cell
        }
    });

    DOM.timetableContainer.addEventListener('dragend', (e) => {
        // Drag-Styling entfernen
         const draggedCells = DOM.timetableContainer.querySelectorAll('.grid-cell.dragging');
         draggedCells.forEach(cell => cell.classList.remove('dragging'));

        DOM.timetableContainer.classList.remove('is-dragging'); // Remove grid container class

        const state = getState();
        // Target-Styling entfernen
        if (state.lastDragOverCell) {
            state.lastDragOverCell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
            delete state.lastDragOverCell.dataset.conflictError;
        }
        // Drag-State zurücksetzen
        updateState({ dragData: null, lastDragOverCell: null, lastConflictCheckPromise: null });
    });

    DOM.timetableContainer.addEventListener('drop', async (e) => {
        e.preventDefault();
        const cell = e.target.closest('.grid-cell');
        const state = getState();
        // Abbruch, wenn kein Ziel oder keine Daten
        if (!cell || !state.dragData) return;

        // --- Finale Validierung ---
        // 1. Auf 'invalid' Marker prüfen
        if (cell.classList.contains('drop-target-invalid')) {
            const errorMessage = cell.dataset.conflictError || "Ablegen nicht möglich: Konflikt.";
            showToast(errorMessage.split("\n")[0], 'error', 4000); // Show first line of error
            return;
        }
        // 2. Prüfen, ob 'valid' Marker fehlt
        if (!cell.classList.contains('drop-target-valid')) {
            // Erneute Prüfung (Promise)
            try {
                let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);
                if (state.lastConflictCheckPromise && needsApiCheck) {
                    await state.lastConflictCheckPromise; // Wait for the check result
                } else if (needsApiCheck) {
                    // Kein Promise + API-Check nötig = ungültig
                    showToast("Ablegen nicht möglich: Konfliktprüfung unvollständig.", 'error');
                    return;
                }
                 // If it's a substitution moving to empty or over itself, basic checks suffice
            } catch (error) {
                // Konflikt durch Promise bestätigt
                showToast(error.message.split("\n")[0], 'error', 4000);
                return;
            }
        }
         // 3. Vertikale Passform prüfen
         const targetStartPeriod = parseInt(cell.dataset.period);
         const targetEndPeriod = targetStartPeriod + state.dragData.span - 1;
         if (targetEndPeriod > DOM.timeSlots.length) {
             showToast("Ablegen nicht möglich: Eintrag passt nicht in den Plan.", 'error');
             return;
         }

        // --- Speichern vorbereiten ---
        const targetDay = cell.dataset.day;
        const entryData = state.dragData.data; // Data of the first entry
        const savePromises = []; // Array to hold all promises for saving

        const currentYear = DOM.yearSelector.value;
        const currentWeek = DOM.weekSelector.value;
        const newDate = getDateForDayInWeek(targetDay, currentYear, currentWeek);

        console.log("--- Drop Event ---"); // Log drop event start
        console.log("Drag Data:", state.dragData); // Log the full drag data
        console.log("Target Cell:", { day: targetDay, period: targetStartPeriod }); // Log target cell

        // --- Fall 1: Vertretung verschieben ---
        if (state.dragData.type === 'substitution') {
            const originalSubIds = state.dragData.originalSubIds || [];
            const underlyingRegularEntries = state.dragData.underlyingRegularEntries || [];
            console.log("Moving Substitution Block - Original Sub IDs:", originalSubIds);
            console.log("Moving underlying regular entries:", underlyingRegularEntries);

            // 1. Reguläre Basis-Einträge verschieben
            const movedRegularIds = new Set(); // Track moved regular entries/blocks
            for (let i = 0; i < underlyingRegularEntries.length; i++) {
                const regularEntry = underlyingRegularEntries[i];
                if (!regularEntry) continue; // Skip if underlying entry was null

                const idToMove = regularEntry.block_id || regularEntry.entry_id;
                const idType = regularEntry.block_id ? 'block_id' : 'entry_id';
                
                if (!idToMove || movedRegularIds.has(idToMove)) {
                    continue; // Skip if no ID or already processed (part of a block)
                }
                
                movedRegularIds.add(idToMove);

                // Span des Basis-Eintrags berechnen
                let regularEntrySpan = 1;
                if (regularEntry.block_id) {
                    const periods = state.currentTimetable
                        .filter(e => e.block_id === regularEntry.block_id)
                        .map(e => parseInt(e.period_number));
                    regularEntrySpan = periods.length > 0 ? Math.max(...periods) - Math.min(...periods) + 1 : 1;
                }
                
                // Neue Periode für Basis-Eintrag berechnen
                const originalPeriodForThisEntry = parseInt(regularEntry.period_number);
                const periodOffset = originalPeriodForThisEntry - state.dragData.originalPeriod;
                
                const newStartPeriod = targetStartPeriod + periodOffset;
                const newEndPeriod = newStartPeriod + regularEntrySpan - 1;

                // Prüfung auf Grid-Grenzen
                if (newEndPeriod > DOM.timeSlots.length) {
                    console.warn(`Skipping move of underlying entry ${idToMove}, would fall off grid.`);
                    continue;
                }

                const regularSaveData = {
                    entry_id: (idType === 'entry_id' ? idToMove : null),
                    block_id: (idType === 'block_id' ? idToMove : null),
                    year: currentYear,
                    calendar_week: currentWeek,
                    day_of_week: targetDay,
                    start_period_number: newStartPeriod,
                    end_period_number: newEndPeriod,
                    class_id: regularEntry.class_id,
                    teacher_id: regularEntry.teacher_id,
                    subject_id: regularEntry.subject_id,
                room_id: regularEntry.room_id,
                    comment: regularEntry.comment || null
                };
                console.log("Moving underlying regular entry:", regularSaveData);
                savePromises.push(saveEntry(regularSaveData));
            }

            // 2. Vertretungs-Einträge verschieben
            for (let i = 0; i < state.dragData.span; i++) {
                const currentTargetPeriod = targetStartPeriod + i;
                const originalSubId = originalSubIds[i]; // Use the ID stored for this index

                console.log(`Processing hour ${i + 1}/${state.dragData.span}: TargetPeriod=${currentTargetPeriod}, OriginalSubId=${originalSubId}`);

                if (!originalSubId) {
                    console.error(`Drop Error: Missing original substitution ID for index ${i} in dragged block.`);
                    showToast(`Fehler beim Verschieben von Stunde (Index ${i}). Original-ID fehlt.`, 'error');
                    continue; // Skip this part
                }

                // Finde Original-Daten der Vertretung
                // Verwende state.currentSubstitutions
                const originalSubData = state.currentSubstitutions.find(s => s.substitution_id == originalSubId);

                if (!originalSubData) {
                    console.error(`Drop Error: Could not find original substitution data for ID ${originalSubId}.`);
                    showToast(`Fehler beim Verschieben von Stunde mit ID ${originalSubId}. Originaldaten nicht gefunden.`, 'error');
                    continue; // Skip this part
                }

                const subSaveData = {
                    ...originalSubData, // Copy all existing details
                    substitution_id: originalSubData.substitution_id, // The ID of the entry being updated
                    date: newDate, // The NEW date
                    period_number: currentTargetPeriod, // The NEW period
                    // Alle anderen Felder (new_teacher_id etc.) werden von ...originalSubData übernommen
                };
                 console.log(`Substitution Save Data (ID: ${originalSubId}):`, subSaveData);
                savePromises.push(saveSubstitution(subSaveData));
            }

        }
        // --- Fall 2: Regulären Eintrag/Block verschieben ---
        else {
             console.log("Moving Regular Entry/Block:", { id: state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId, type: state.dragData.type });
            const saveData = {
                entry_id: state.dragData.originalRegularEntryId, // Use stored original ID
                block_id: state.dragData.originalRegularBlockId, // Use stored original ID
                year: currentYear,
                calendar_week: currentWeek,
                day_of_week: targetDay,
                start_period_number: targetStartPeriod,
                end_period_number: targetEndPeriod,
                class_id: entryData.class_id, // Data from the first entry of the block
                teacher_id: entryData.teacher_id,
                subject_id: entryData.subject_id,
                room_id: entryData.room_id,
                comment: entryData.comment || null
            };
             console.log("Regular Save Data:", saveData);
            savePromises.push(saveEntry(saveData)); // Single promise for regular entry/block
        }

        // --- Alle Speicher-Aktionen ausführen ---
        try {
             console.log(`Executing ${savePromises.length} save operations...`);
            const results = await Promise.all(savePromises);
            console.log("Save Results:", results);
            // Prüfe Erfolg
            const success = results.every(response => response && response.success);

            if (success) {
                showToast("Eintrag erfolgreich verschoben.", 'success');
                loadPlanData(); // Reload grid
            } else {
                // Finde ersten Fehler
                const firstErrorResult = results.find(r => !(r && r.success));
                const firstError = firstErrorResult?.message; // Get message from the failed response
                console.error("Fehler beim Speichern (Promise.all):", firstErrorResult);
                throw new Error(firstError || "Unbekannter Fehler beim Verschieben.");
            }
        } catch (error) {
            // Fehlerbehandlung
            console.error("Drop save error (catch):", error); // Log the specific error
            // Grid bei Fehler neuladen
            loadPlanData();
        }
    });


    // --- Aktions-Modals (Kopieren, Vorlagen) ---
    initializeActionModals(); // From this file

     // --- Template-Editor Grid-Handler ---
     DOM.templateEditorGridContainer.addEventListener('click', (e) => {
         const cell = e.target.closest('.grid-cell.template-cell');
         if (!cell) {
             clearSelectionState(getState().selection.cells); // Clear selection if clicking outside cells
             return;
         }
         handleCellClick(cell, DOM.templateEditorGridContainer);

         // Modal bei Doppelklick
         if (e.detail === 2) {
             openModal(e, true);
         }
     });

    // Ladevorgang (ausgelagert)
    // loadInitialData().then(() => { ... });
}


/**
 * Initialisiert die Event-Listener für die Aktions-Modals (Kopieren, Vorlagen).
 */
function initializeActionModals() {
    // --- Kopiermodal ---
    const openCopyModal = () => {
        const state = getState();
        if (!state.selectedClassId && !state.selectedTeacherId) {
            showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen.", 'error');
            return;
        }
        DOM.copySourceDisplay.value = `KW ${DOM.weekSelector.value} / ${DOM.yearSelector.value}`;
        // Calculate next week for default target
        const currentMonday = getDateOfISOWeek(parseInt(DOM.weekSelector.value), parseInt(DOM.yearSelector.value));
        const nextWeekDate = new Date(currentMonday.getTime() + 7 * 24 * 60 * 60 * 1000);
        const { week: nextWeek, year: nextYear } = getWeekAndYear(nextWeekDate);
        populateYearSelector(DOM.copyTargetYear, nextYear);
        populateWeekSelector(DOM.copyTargetWeek, nextWeek);
        DOM.copyWeekModal.classList.add('visible');
    };
    const closeCopyModal = () => DOM.copyWeekModal.classList.remove('visible');

    DOM.copyWeekBtn.addEventListener('click', openCopyModal);
    DOM.copyWeekCancelBtn.addEventListener('click', closeCopyModal);
    DOM.copyWeekModal.addEventListener('click', (e) => { if (e.target.id === 'copy-week-modal') closeCopyModal(); });

    DOM.copyWeekForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const state = getState();
        const sourceYear = parseInt(DOM.yearSelector.value);
        const sourceWeek = parseInt(DOM.weekSelector.value);
        const targetYear = parseInt(DOM.copyTargetYear.value);
        const targetWeek = parseInt(DOM.copyTargetWeek.value);

        if (sourceYear === targetYear && sourceWeek === targetWeek) {
            showToast("Fehler: Quell- und Zielwoche dürfen nicht identisch sein.", 'error');
            return;
        }
        const entityName = state.currentViewMode === 'class'
            ? `Klasse ${state.selectedClassId}`
            : `Lehrer ${DOM.teacherSelector.options[DOM.teacherSelector.selectedIndex]?.text || state.selectedTeacherId}`; // Add fallback

        if (await showConfirm("Kopieren bestätigen", `Sind Sie sicher, dass Sie den Plan für '${escapeHtml(entityName)}' von KW ${sourceWeek}/${sourceYear} nach KW ${targetWeek}/${targetYear} kopieren möchten? Alle Einträge in der Zielwoche werden überschrieben.`)) {
            const body = { sourceYear, sourceWeek, targetYear, targetWeek, classId: state.selectedClassId, teacherId: state.selectedTeacherId };
            try {
                const response = await copyWeek(body);
                if (response.success) {
                    showToast(response.message, 'success');
                    closeCopyModal();
                    // Switch view to the target week
                    DOM.yearSelector.value = targetYear;
                    DOM.weekSelector.value = targetWeek;
                    loadPlanData();
                }
                // Errors handled by apiFetch
            } catch (error) { /* Error already shown */ }
        }
    });

    // --- Vorlagen-Modals ---
    const openManageTemplatesModal = async () => {
        DOM.manageTemplatesForm.reset();
        const templates = await loadTemplates(); // Lädt Vorlagen neu und gibt Daten zurück
        renderTemplatesList(templates); // Rendert die Liste
        showTemplateView('list'); // Start in list view
        DOM.manageTemplatesModal.classList.add('visible');
    };
    const closeManageTemplatesModal = () => DOM.manageTemplatesModal.classList.remove('visible');
    const openApplyTemplateModal = async () => {
        const state = getState();
        if (!state.selectedClassId && !state.selectedTeacherId) {
            showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen.", 'error');
            return;
        }
        await loadTemplates(); // Stellt sicher, dass Liste aktuell ist (rendert Select neu)
        DOM.applyTemplateForm.reset();
        DOM.applyTemplateModal.classList.add('visible');
    };
    const closeApplyTemplateModal = () => DOM.applyTemplateModal.classList.remove('visible');

    DOM.createTemplateBtn.addEventListener('click', () => {
        const state = getState();
        if (!state.selectedClassId && !state.selectedTeacherId) {
            showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen, um eine Vorlage zu erstellen.", 'error');
            return;
        }
        // Prüfe flaches Array
        if (state.currentTimetable.length === 0) {
            showToast("Die aktuelle Woche enthält keine Einträge zum Speichern als Vorlage.", 'info');
            return;
        }
        openManageTemplatesModal(); // Opens the manage modal where the create form is
    });
    DOM.applyTemplateBtn.addEventListener('click', openApplyTemplateModal);
    DOM.manageTemplatesBtn.addEventListener('click', openManageTemplatesModal);
    DOM.manageTemplatesCloseBtn.addEventListener('click', closeManageTemplatesModal);
    DOM.applyTemplateCancelBtn.addEventListener('click', closeApplyTemplateModal);
    // Modals bei Klick daneben schließen
    DOM.manageTemplatesModal.addEventListener('click', (e) => { if (e.target.id === 'manage-templates-modal') closeManageTemplatesModal(); });
    DOM.applyTemplateModal.addEventListener('click', (e) => { if (e.target.id === 'apply-template-modal') closeApplyTemplateModal(); });

    // Submit: Vorlage aus Woche erstellen
    DOM.manageTemplatesForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const templateNameInput = DOM.manageTemplatesForm.querySelector('#template-name');
        const templateDescriptionInput = DOM.manageTemplatesForm.querySelector('#template-description');

        const templateName = templateNameInput ? templateNameInput.value.trim() : '';
        const templateDescription = templateDescriptionInput ? templateDescriptionInput.value.trim() : '';

        if (!templateName) {
            showToast("Bitte geben Sie einen Namen für die Vorlage ein.", 'error');
            return;
        }
        const state = getState();
        const body = {
            name: templateName,
            description: templateDescription || null,
            sourceYear: DOM.yearSelector.value,
            sourceWeek: DOM.weekSelector.value,
            sourceClassId: state.selectedClassId,
            sourceTeacherId: state.selectedTeacherId
        };
        try {
            const response = await createTemplate(body);
            if (response.success) {
                showToast(response.message, 'success');
                DOM.manageTemplatesForm.reset();
                const templates = await loadTemplates(); // Reload list and return data
                renderTemplatesList(templates); // Render updated list
            }
             // Errors (like duplicate name) handled by apiFetch
        } catch (error) { /* Error already shown */ }
    });

     // Submit: Vorlage anwenden
    DOM.applyTemplateForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const templateId = DOM.applyTemplateSelect.value;
        if (!templateId) {
            showToast("Bitte wählen Sie eine Vorlage aus.", 'error');
            return;
        }
        const targetYear = DOM.yearSelector.value;
        const targetWeek = DOM.weekSelector.value;
        if (!targetYear || !targetWeek) return; // Should not happen
        const state = getState();

        if (await showConfirm("Vorlage anwenden", "Sind Sie sicher? Alle Einträge für die aktuelle Auswahl in dieser Woche werden überschrieben.")) {
            const body = {
                templateId: templateId,
                targetYear: targetYear,
                targetWeek: targetWeek,
                targetClassId: state.selectedClassId,
                targetTeacherId: state.selectedTeacherId
            };
            try {
                const response = await applyTemplate(body);
                if (response.success) {
                    showToast(response.message, 'success');
                    closeApplyTemplateModal();
                    loadPlanData(); // Reload grid with applied template
                }
                 // Errors handled by apiFetch
            } catch (error) { /* Error already shown */ }
        }
    });

    // --- Template-Editor Buttons ---
     DOM.createEmptyTemplateBtn.addEventListener('click', () => {
         updateState({ 
            currentTemplateEditorData: [], // Start with empty data
            currentEditingTemplateId: null // Ensure no ID is set
         }); 
         DOM.templateEditorTitle.textContent = 'Neue leere Vorlage erstellen';
         const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
         const descInput = DOM.manageTemplatesModal.querySelector('#template-editor-description');
         if(nameInput) nameInput.value = '';
         if(descInput) descInput.value = '';
         populateAllModalSelects(getState().stammdaten); // Ensure modal selects are populated
         renderTimetableGrid(getState(), true);
         showTemplateView('editor');
     });

     DOM.backToTemplateListBtn.addEventListener('click', () => {
         // TODO: Warnen bei ungespeicherten Änderungen
         showTemplateView('list');
         updateState({ 
            currentTemplateEditorData: null, // Clear editor data
            currentEditingTemplateId: null // Clear editing ID
        }); 
     });

     DOM.saveTemplateEditorBtn.addEventListener('click', async () => {
        const state = getState();
        const name = DOM.manageTemplatesModal.querySelector('#template-editor-name').value.trim();
        const description = DOM.manageTemplatesModal.querySelector('#template-editor-description').value.trim();
        const templateId = state.currentEditingTemplateId; // Get ID (null if new)
        const entries = state.currentTemplateEditorData || [];

        if (!name) {
            showToast("Bitte einen Vorlagennamen eingeben.", 'error');
            return;
        }

        const templateData = {
            template_id: templateId,
            name: name,
            description: description || null,
            entries: entries
        };

        try {
            const response = await saveTemplate(templateData);
            if (response.success) {
                showToast(response.message, 'success');
                updateState({ currentTemplateEditorData: null, currentEditingTemplateId: null });
                const templates = await loadTemplates();
                renderTemplatesList(templates);
                showTemplateView('list');
            }
            // Errors (like duplicate name) handled by apiFetch
        } catch (error) { /* Error already shown */ }
     });
}

/** Rendert die Liste der Vorlagen im Verwalten-Modal */
export const renderTemplatesList = (templates) => {
    if (!DOM.manageTemplatesList) return;
    if (!Array.isArray(templates)) {
        console.error("renderTemplatesList: Input 'templates' is not an array.", templates);
        DOM.manageTemplatesList.innerHTML = '<p class="message error">Fehler beim Anzeigen der Vorlagen.</p>';
        return;
    }
    if (templates.length === 0) {
        DOM.manageTemplatesList.innerHTML = '<p>Keine Vorlagen vorhanden.</p>';
        return;
    }
    DOM.manageTemplatesList.innerHTML = `
        <table class="data-table templates-list-table">
            <thead><tr><th>Name</th><th>Beschreibung</th><th>Aktion</th></tr></thead>
            <tbody>
                ${templates.map(t => `
                    <tr data-id="${t.template_id}">
                        <td>${escapeHtml(t.name)}</td>
                        <td>${escapeHtml(t.description) || '-'}</td>
                        <td class="actions">
                            <button class="btn btn-warning btn-small edit-template" data-id="${t.template_id}">Bearbeiten</button>
                            <button class="btn btn-danger btn-small delete-template" data-name="${escapeHtml(t.name)}">Löschen</button>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    // Event Listeners (nach Render)
    DOM.manageTemplatesList.querySelectorAll('.delete-template').forEach(button => {
        button.addEventListener('click', handleDeleteTemplateClick);
    });
    // Edit-Buttons
    DOM.manageTemplatesList.querySelectorAll('.edit-template').forEach(button => {
        button.addEventListener('click', handleEditTemplateClick);
    });
};

/** Behandelt Klick auf "Vorlage bearbeiten" */
async function handleEditTemplateClick(e) {
    const button = e.target;
    const templateId = button.dataset.id;
    if (!templateId) return;

    try {
        const response = await loadTemplateDetails(templateId);
        if (response.success && response.data) {
            const { template, entries } = response.data;
            // Populate state for the editor
            updateState({
                currentTemplateEditorData: entries || [],
                currentEditingTemplateId: template.template_id
            });
            // Set editor form fields
            DOM.templateEditorTitle.textContent = 'Vorlage bearbeiten';
            const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
            const descInput = DOM.manageTemplatesModal.querySelector('#template-editor-description');
            if (nameInput) nameInput.value = template.name;
            if (descInput) descInput.value = template.description || '';
            
            renderTimetableGrid(getState(), true);
            showTemplateView('editor');
        }
    } catch (error) { /* Error handled by apiFetch */ }
}


/** Behandelt Klick auf "Vorlage löschen" */
async function handleDeleteTemplateClick(e) {
    const button = e.target;
    const row = button.closest('tr');
    if (!row) return; // Should not happen
    const templateId = row.dataset.id;
    const templateName = button.dataset.name;

    if (await showConfirm("Vorlage löschen", `Sind Sie sicher, dass Sie die Vorlage "${templateName}" endgültig löschen möchten?`)) {
        try {
            const response = await deleteTemplate(templateId);
            if (response.success) {
                showToast(response.message, 'success');
                const updatedTemplates = await loadTemplates();
                renderTemplatesList(updatedTemplates);
            }
             // Errors handled by apiFetch
        } catch (error) { /* Error already shown */ }
    }
}

