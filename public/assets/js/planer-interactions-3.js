import * as DOM from './planer-dom.js';
import { getState, updateState, clearSelectionState } from './planer-state.js';
import { checkConflicts, saveEntry, deleteEntry, saveSubstitution, deleteSubstitution, loadPlanData } from './planer-api.js';
import { renderTimetableGrid } from './planer-timetable.js';
import { getDateForDayInWeek } from './planer-utils.js';
import { showToast } from './notifications.js';
export function initializeGridInteractions() {
    const grid = DOM.timetableContainer;
    if (!grid) return;
    grid.addEventListener('dragstart', handleDragStart);
    grid.addEventListener('dragover', handleDragOver);
    grid.addEventListener('dragend', handleDragEnd);
    grid.addEventListener('drop', handleDrop);
}
function handleDragStart(e) {
    const cell = e.target.closest('.grid-cell');
    const entryElement = cell ? cell.querySelector('.planner-entry') : null;
    const state = getState();
    if (!cell || !entryElement || !cell.classList.contains('has-entry') || !(state.selectedClassId || state.selectedTeacherId)) {
        e.preventDefault();
        return;
    }
    const entryId = entryElement.dataset.entryId;
    const blockId = entryElement.dataset.blockId;
    const substitutionId = entryElement.dataset.substitutionId;
    let entryData = null; 
    let entryType = null;
    let span = 1;
    let blockStartCell = cell; 
    let originalSubIds = []; 
    let originalRegularEntryId = null; 
    let originalRegularBlockId = null;
    let underlyingRegularEntries = []; 
    const currentDay = cell.dataset.day;
    const currentPeriod = parseInt(cell.dataset.period);
    const cellKey = `${currentDay}-${currentPeriod}`; // state.timetable und state.substitutions verwenden diesen Key
    if (substitutionId) {
        entryType = 'substitution';
        const subsInCell = (state.substitutions[cellKey] || []);
        entryData = subsInCell.find(s => s.substitution_id == substitutionId);
        if (!entryData) {
            console.error("DragStart Error: Konnte Vertretungsdaten nicht finden.", substitutionId);
            e.preventDefault(); return;
        }
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
                if (startSub.substitution_id == substitutionId) {
                    span = subsInBlock.length;
                    entryData = startSub; 
                    blockStartCell = cell;
                } else {
                    span = subsInBlock.length;
                    entryData = startSub;
                    blockStartCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${startSub.day_of_week}'][data-period='${startSub.period_number}']`);
                }
            }
        }
        originalSubIds = [];
        underlyingRegularEntries = [];
        const allSubsMap = state.substitutions;
        const allTimetableMap = state.timetable;
        for (let i = 0; i < span; i++) {
            const periodToCheck = parseInt(entryData.period_number) + i;
            const keyToCheck = `${entryData.day_of_week}-${periodToCheck}`;
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
                originalSubIds.push(null);
                underlyingRegularEntries.push(null);
                console.warn(`DragStart: Konnte Vertretung für Periode ${periodToCheck} im Block nicht finden.`);
            }
        }
    } else if (blockId) {
        entryType = 'block';
        const entriesInCell = (state.timetable[cellKey] || []);
        entryData = entriesInCell.find(e => e.block_id == blockId);
        if (!entryData) { e.preventDefault(); return; }
        const allEntriesInBlock = state.currentTimetable.filter(e => e.block_id === blockId);
        if (allEntriesInBlock.length > 0) {
             allEntriesInBlock.sort((a, b) => a.period_number - b.period_number);
             const startEntry = allEntriesInBlock[0];
             span = parseInt(allEntriesInBlock[allEntriesInBlock.length - 1].period_number) - parseInt(startEntry.period_number) + 1;
             if (startEntry.entry_id !== entryData.entry_id) {
                 blockStartCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${startEntry.day_of_week}'][data-period='${startEntry.period_number}']`);
                 entryData = startEntry; // Drag-Daten auf den Start des Blocks setzen
             }
        } else {
            span = 1;
        }
        originalRegularBlockId = blockId;
    } else if (entryId) {
        entryType = 'entry';
        const entriesInCell = (state.timetable[cellKey] || []);
        entryData = entriesInCell.find(e => e.entry_id == entryId);
        if (!entryData) { e.preventDefault(); return; }
        span = 1;
        originalRegularEntryId = entryId;
    }
    if (!entryData) {
        console.error("DragStart Error: Konnte Eintragsdaten nicht finden.", { entryId, blockId, substitutionId });
        e.preventDefault();
        return;
     }
    clearSelectionState(state.selection.cells);
    updateState({
        dragData: {
            type: entryType,
            data: entryData, // Enthält immer den *Start*-Eintrag eines Blocks
            span: span,
            originalDay: blockStartCell.dataset.day,
            originalPeriod: parseInt(blockStartCell.dataset.period),
            originalSubIds: originalSubIds, // Array von IDs für Vertretungsblöcke
            originalRegularEntryId: originalRegularEntryId, // ID für Einzeleintrag
            originalRegularBlockId: originalRegularBlockId, // ID für Block
            underlyingRegularEntries: underlyingRegularEntries || [] // Reguläre Einträge *unter* einer Vertretung
        }
    });
    setTimeout(() => {
        if (blockStartCell) blockStartCell.classList.add('dragging');
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
}
async function handleDragOver(e) {
    e.preventDefault(); 
    const cell = e.target.closest('.grid-cell');
    const state = getState();
    if (!cell || !state.dragData || cell === state.lastDragOverCell) return;
    if (state.lastDragOverCell) {
        state.lastDragOverCell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
        delete state.lastDragOverCell.dataset.conflictError;
    }
    updateState({ lastDragOverCell: cell }); 
    cell.classList.add('drop-target'); 
    const targetDay = cell.dataset.day;
    const targetStartPeriod = parseInt(cell.dataset.period);
    const targetEndPeriod = targetStartPeriod + state.dragData.span - 1;
    if (targetEndPeriod > DOM.timeSlots.length) {
        cell.classList.add('drop-target-invalid');
        cell.dataset.conflictError = "Eintrag passt nicht auf den Plan (zu lang).";
        return;
    }
    for (let p = targetStartPeriod; p <= targetEndPeriod; p++) {
        const targetCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${targetDay}'][data-period='${p}']`);
        if (targetCell && (targetCell.classList.contains('has-entry') || targetCell.classList.contains('has-substitution')) ) {
            let isSelf = false; 
            const draggedItemType = state.dragData.type;
            if (draggedItemType === 'substitution') {
                const targetSubElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-substitution-id]'));
                isSelf = targetSubElements.some(el => state.dragData.originalSubIds.includes(parseInt(el.dataset.substitutionId)));
            } else if (draggedItemType === 'block') {
                const targetEntryElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-block-id]'));
                isSelf = targetEntryElements.some(el => el.dataset.blockId === state.dragData.originalRegularBlockId);
            } else if (draggedItemType === 'entry') {
                const targetEntryElements = Array.from(targetCell.querySelectorAll('.planner-entry[data-entry-id]'));
                isSelf = targetEntryElements.some(el => el.dataset.entryId == state.dragData.originalRegularEntryId);
            }
            if (!isSelf) { 
                cell.classList.add('drop-target-invalid');
                const conflictType = targetCell.classList.contains('has-substitution') ? "Vertretung" : "Unterricht";
                cell.dataset.conflictError = `KONFLIKT (Slot belegt): In diesem Zeitraum existiert bereits ${conflictType}.`;
                return;
            }
        }
    }
    let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);
    if (!needsApiCheck) {
        cell.classList.add('drop-target-valid');
        updateState({ lastConflictCheckPromise: Promise.resolve({ success: true, conflicts: [] }) }); 
        return;
    }
    const regularEntryData = state.dragData.originalRegularBlockId
        ? (state.currentTimetable.find(e => e.block_id == state.dragData.originalRegularBlockId) || (state.dragData.underlyingRegularEntries[0] || null))
        : (state.currentTimetable.find(e => e.entry_id == state.dragData.originalRegularEntryId) || (state.dragData.underlyingRegularEntries[0] || null));
    if (!regularEntryData) {
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
        teacher_id: regularEntryData.teacher_id, 
        room_id: regularEntryData.room_id,    
        class_id: regularEntryData.class_id,    
        entry_id: state.dragData.originalRegularEntryId,
        block_id: state.dragData.originalRegularBlockId,
    };
    try {
        const conflictCheckPromise = checkConflicts(checkData);
        updateState({ lastConflictCheckPromise: conflictCheckPromise });
        await conflictCheckPromise;
        if (cell === getState().lastDragOverCell) {
            cell.classList.add('drop-target-valid');
        }
    } catch (error) {
        if (cell === getState().lastDragOverCell) {
            cell.classList.add('drop-target-invalid');
            cell.dataset.conflictError = error.message; 
        }
    }
}
function handleDragEnd(e) {
    const draggedCells = DOM.timetableContainer.querySelectorAll('.grid-cell.dragging');
    draggedCells.forEach(cell => cell.classList.remove('dragging'));
    DOM.timetableContainer.classList.remove('is-dragging'); 
    const state = getState();
    if (state.lastDragOverCell) {
        state.lastDragOverCell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
        delete state.lastDragOverCell.dataset.conflictError;
    }
    updateState({ dragData: null, lastDragOverCell: null, lastConflictCheckPromise: null });
}
async function handleDrop(e) {
    e.preventDefault();
    const cell = e.target.closest('.grid-cell');
    const state = getState();
    if (!cell || !state.dragData) return;
    if (cell.classList.contains('drop-target-invalid')) {
        const errorMessage = cell.dataset.conflictError || "Ablegen nicht möglich: Konflikt.";
        showToast(errorMessage.split("\n")[0], 'error', 4000); 
        return;
    }
    if (!cell.classList.contains('drop-target-valid')) {
        try {
            let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);
            if (state.lastConflictCheckPromise && needsApiCheck) {
                await state.lastConflictCheckPromise; 
            } else if (needsApiCheck) {
                showToast("Ablegen nicht möglich: Konfliktprüfung unvollständig.", 'error');
                return;
            }
        } catch (error) {
            showToast(error.message.split("\n")[0], 'error', 4000);
            return;
        }
    }
    const targetStartPeriod = parseInt(cell.dataset.period);
    const targetEndPeriod = targetStartPeriod + state.dragData.span - 1;
    if (targetEndPeriod > DOM.timeSlots.length) {
        showToast("Ablegen nicht möglich: Eintrag passt nicht in den Plan.", 'error');
        return;
    }
    const targetDay = cell.dataset.day;
    const entryData = state.dragData.data; 
    const savePromises = []; 
    const currentYear = DOM.yearSelector.value;
    const currentWeek = DOM.weekSelector.value;
    const newDate = getDateForDayInWeek(targetDay, currentYear, currentWeek);
    const mainContent = document.querySelector('.dashboard-content');
    const scrollY = mainContent ? mainContent.scrollTop : window.scrollY;
    if (state.dragData.type === 'substitution') {
        const originalSubIds = state.dragData.originalSubIds || [];
        const underlyingRegularEntries = state.dragData.underlyingRegularEntries || [];
        const movedRegularIds = new Set(); 
        for (let i = 0; i < underlyingRegularEntries.length; i++) {
            const regularEntry = underlyingRegularEntries[i];
            if (!regularEntry) continue; 
            const idToMove = regularEntry.block_id || regularEntry.entry_id;
            const idType = regularEntry.block_id ? 'block_id' : 'entry_id';
            if (!idToMove || movedRegularIds.has(idToMove)) {
                continue; // Verhindert doppeltes Verschieben von Blöcken
            }
            movedRegularIds.add(idToMove);
            let regularEntrySpan = 1;
            if (regularEntry.block_id) {
                const periods = state.currentTimetable
                    .filter(e => e.block_id === regularEntry.block_id)
                    .map(e => parseInt(e.period_number));
                regularEntrySpan = periods.length > 0 ? Math.max(...periods) - Math.min(...periods) + 1 : 1;
            }
            const originalPeriodForThisEntry = parseInt(regularEntry.period_number);
            const periodOffset = originalPeriodForThisEntry - state.dragData.originalPeriod;
            const newStartPeriod = targetStartPeriod + periodOffset;
            const newEndPeriod = newStartPeriod + regularEntrySpan - 1;
            if (newEndPeriod > DOM.timeSlots.length) {
                console.warn(`Überspringe Verschiebung des regulären Eintrags ${idToMove}, würde aus dem Raster fallen.`);
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
            savePromises.push(saveEntry(regularSaveData));
        }
        for (let i = 0; i < state.dragData.span; i++) {
            const currentTargetPeriod = targetStartPeriod + i;
            const originalSubId = originalSubIds[i]; 
            if (!originalSubId) {
                console.error(`Drop Error: Fehlende Original-Sub-ID für Index ${i}.`);
                showToast(`Fehler beim Verschieben (Index ${i}). Original-ID fehlt.`, 'error');
                continue; 
            }
            const originalSubData = state.currentSubstitutions.find(s => s.substitution_id == originalSubId);
            if (!originalSubData) {
                console.error(`Drop Error: Original-Sub-Daten für ID ${originalSubId} nicht gefunden.`);
                showToast(`Fehler beim Verschieben (ID ${originalSubId}). Originaldaten nicht gefunden.`, 'error');
                continue; 
            }
            const subSaveData = {
                ...originalSubData, 
                substitution_id: originalSubData.substitution_id, 
                date: newDate, 
                period_number: currentTargetPeriod, 
            };
            savePromises.push(saveSubstitution(subSaveData));
        }
    } else {
        const saveData = {
            entry_id: state.dragData.originalRegularEntryId, 
            block_id: state.dragData.originalRegularBlockId, 
            year: currentYear,
            calendar_week: currentWeek,
            day_of_week: targetDay,
            start_period_number: targetStartPeriod,
            end_period_number: targetEndPeriod,
            class_id: entryData.class_id, 
            teacher_id: entryData.teacher_id,
            subject_id: entryData.subject_id,
            room_id: entryData.room_id,
            comment: entryData.comment || null
        };
        savePromises.push(saveEntry(saveData)); 
    }
    try {
        const results = await Promise.all(savePromises);
        const success = results.every(response => response && response.success);
        if (success) {
            showToast("Eintrag erfolgreich verschoben.", 'success');
            await loadPlanData(); // Lade das Grid neu (await)
        } else {
            const firstErrorResult = results.find(r => !(r && r.success));
            const firstError = firstErrorResult?.message; 
            console.error("Fehler beim Speichern (Promise.all):", firstErrorResult);
            await loadPlanData(); // Lade auch bei Fehler neu (await)
            throw new Error(firstError || "Unbekannter Fehler beim Verschieben.");
        }
    } catch (error) {
        console.error("Drop save error (catch):", error);
    } finally {
        requestAnimationFrame(() => {
            if (mainContent) {
                mainContent.scrollTop = scrollY;
            } else {
                window.scrollTo(0, scrollY);
            }
        });
    }
}