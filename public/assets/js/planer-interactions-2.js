 import * as DOM from './planer-dom.js';
 import { getState, updateState, clearSelectionState, setSelectionState } from './planer-state.js';
 import { getWeekAndYear, getDateOfISOWeek, getDateForDayInWeek, escapeHtml } from './planer-utils.js';
 // Corrected import: Import saveEntry and saveSubstitution from planer-api
 import { loadPlanData, publishWeek, checkConflicts, saveEntry, deleteEntry, saveSubstitution, deleteSubstitution, copyWeek, loadTemplates, createTemplate, applyTemplate, deleteTemplate, loadTemplateDetails, saveTemplate } from './planer-api.js';
 import { renderTimetable } from './planer-timetable.js';
 import { populateYearSelector, populateWeekSelector, populateTemplateSelects, showTemplateView, showConflicts, hideConflicts, populateAllModalSelects } from './planer-ui.js';
 // Import notifications
 import { showToast, showConfirm } from './notifications.js';
 // Import functions from part 1
 import { setDefaultSelectors, handleCellClick, initializeEntryModal, openModal, closeModal, switchMode, updateSubstitutionFields, updateDeleteButtonVisibility } from './planer-interactions-1.js';

 /**
  * Initialisiert alle Event-Listener und Hauptinteraktionen für die Planer-Oberfläche.
  */
 export function initializePlanerInteractions() {

     // --- Setze Standardwerte für Selektoren ---
     setDefaultSelectors();
     // --- Initialisiere Modal-Logik (Submit, Delete etc.) ---
     initializeEntryModal(); // From part 1

     // --- Allgemeine UI-Handler ---
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

     // --- Top-Level Event Listeners ---
     DOM.viewModeSelector.addEventListener('change', handleViewModeChange);
     DOM.classSelector.addEventListener('change', loadPlanData);
     DOM.teacherSelector.addEventListener('change', loadPlanData);
     DOM.yearSelector.addEventListener('change', loadPlanData);
     DOM.weekSelector.addEventListener('change', loadPlanData);
     DOM.dateSelector.addEventListener('change', handleDateOrWeekChange);

     DOM.publishStudentBtn.addEventListener('click', () => handlePublishAction('student', true));
     DOM.unpublishStudentBtn.addEventListener('click', () => handlePublishAction('student', false));
     DOM.publishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', true));
     DOM.unpublishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', false));

     // --- Timetable Grid Interaktionen (Click for selection, Double Click for modal) ---
     DOM.timetableContainer.addEventListener('click', (e) => {
         const cell = e.target.closest('.grid-cell');
         const state = getState();
         // Ignore clicks outside cells or if no class/teacher is selected
         if (!cell || !(state.selectedClassId || state.selectedTeacherId)) {
             clearSelectionState(state.selection.cells); // Clear selection if clicking outside valid area
             return;
         }
         // KORREKTUR: Übergebe den Container an handleCellClick
         handleCellClick(cell, DOM.timetableContainer);
 
         // Open modal on double click if a cell is selected
         if (e.detail === 2 && getState().selection.cells.length > 0) {
             openModal(false); // Open regular entry modal ('false' means not template editor)
         }
     });


     // --- Drag & Drop ---
     DOM.timetableContainer.addEventListener('dragstart', (e) => {
         const cell = e.target.closest('.grid-cell[draggable="true"]');
         const state = getState();
         if (!cell || !(state.selectedClassId || state.selectedTeacherId)) {
             e.preventDefault(); return;
         }

         const entryId = cell.dataset.entryId;
         const blockId = cell.dataset.blockId;
         const substitutionId = cell.dataset.substitutionId;

         let entryData = null; // Data of the dragged item (first entry of block, or single)
         let entryType = null;
         let span = 1;
         let blockStartCell = cell; // Cell representing the start of the block/entry
         let originalSubIds = []; // Stores IDs for each hour of a substitution block
         let originalRegularEntryId = null; // Store ID of underlying regular entry/block start
         let originalRegularBlockId = null;
         let underlyingRegularEntries = []; // *** Store ALL underlying entries ***

         if (substitutionId) {
             entryType = 'substitution';
             // Find the actual start cell of the substitution block in the DOM
             let currentPeriodCheck = parseInt(cell.dataset.period);
             while (currentPeriodCheck > 1) {
                 const prevCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${cell.dataset.day}'][data-period='${currentPeriodCheck - 1}']`);
                 // Adjusted check: Use substitutionId from the initial cell if moving within the block
                 // Important: Check if prevCell even has a substitution ID before comparing
                 if (prevCell && prevCell.dataset.substitutionId && prevCell.dataset.substitutionId === substitutionId) {
                     blockStartCell = prevCell;
                     currentPeriodCheck--;
                 } else {
                     break;
                 }
             }
             // Get data associated with the start cell
             entryData = state.currentSubstitutions.find(s => s.substitution_id == blockStartCell.dataset.substitutionId);
             
             if (!entryData) { // Fallback if entryData not found (should not happen)
                 console.error("DragStart Error: Could not find substitution data for start cell.", blockStartCell.dataset);
                 e.preventDefault();
                 return;
             }

             // Calculate span based on DOM grid-row style (most reliable way)
             if (blockStartCell && blockStartCell.style.gridRow) {
                 const spanMatch = blockStartCell.style.gridRow.match(/span\s*(\d+)/);
                 if (spanMatch && spanMatch[1]) {
                     span = parseInt(spanMatch[1]);
                 }
             } else {
                 // Fallback: check consecutive entries in data
                 const relatedSubs = state.currentSubstitutions.filter(s =>
                     s.day_of_week == blockStartCell.dataset.day &&
                     s.class_id == entryData.class_id &&
                     s.substitution_type == entryData.substitution_type &&
                     s.new_teacher_id == entryData.new_teacher_id &&
                     s.new_subject_id == entryData.new_subject_id &&
                     s.new_room_id == entryData.new_room_id &&
                     s.comment == entryData.comment
                 ).sort((a, b) => a.period_number - b.period_number);

                 let consecutiveCount = 0;
                 if (relatedSubs.length > 0) {
                     const startPeriodInBlock = parseInt(blockStartCell.dataset.period);
                     const startIndex = relatedSubs.findIndex(s => s.period_number == startPeriodInBlock);
                     if (startIndex > -1) {
                         consecutiveCount = 1;
                         for (let i = startIndex; i < relatedSubs.length - 1; i++) {
                             if (relatedSubs[i+1].period_number == relatedSubs[i].period_number + 1) {
                                 consecutiveCount++;
                             } else {
                                 break;
                             }
                         }
                     }
                 }
                 span = consecutiveCount > 0 ? consecutiveCount : 1;
             }
             // *** ENDE SPAN-ERMITTLUNG ***


             // Store all original substitution IDs AND find underlying regular entry info
             originalSubIds = []; // Reset array
             underlyingRegularEntries = []; // Reset array

             for (let i = 0; i < span; i++) {
                 const currentPeriod = parseInt(blockStartCell.dataset.period) + i;
                 // Find the cell in the DOM for this hour
                 const cellInBlock = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${blockStartCell.dataset.day}'][data-period='${currentPeriod}']`);
                 // Find the data entry for this substitution hour
                 const subForThisHour = state.currentSubstitutions.find(s => 
                     s.day_of_week == blockStartCell.dataset.day &&
                     s.period_number == currentPeriod &&
                     // Check properties against the *first* entry's data to ensure it's the same block
                     s.class_id == entryData.class_id &&
                     s.substitution_type == entryData.substitution_type &&
                     s.new_teacher_id == entryData.new_teacher_id &&
                     s.new_subject_id == entryData.new_subject_id &&
                     s.new_room_id == entryData.new_room_id &&
                     s.comment == entryData.comment
                 );

                 if (subForThisHour) {
                     originalSubIds.push(subForThisHour.substitution_id); // Push the ID found in the data
                 } else {
                     // Fallback to DOM cell ID if data search fails (less reliable)
                     const subIdForThisHour = cellInBlock?.dataset?.substitutionId;
                     if (subIdForThisHour) {
                         originalSubIds.push(subIdForThisHour);
                         console.warn(`DragStart: Using fallback DOM ID for sub period ${currentPeriod}`);
                     } else {
                         console.error(`DragStart: Could not find substitution ID for period ${currentPeriod} in dragged block (Sub ID group: ${entryData?.substitution_id}).`);
                         originalSubIds.push(null); // Add null placeholder
                     }
                 }


                 // Find underlying regular entry for this specific hour
                 const regularEntry = state.currentTimetable.find(e => e.day_of_week == blockStartCell.dataset.day && e.period_number == currentPeriod);
                 if (regularEntry) {
                     underlyingRegularEntries.push(regularEntry); // Store the full entry
                     if (i === 0) { // Store first entry's IDs for API check
                         originalRegularBlockId = regularEntry.block_id || null;
                         originalRegularEntryId = regularEntry.entry_id || null;
                     }
                 } else {
                     underlyingRegularEntries.push(null); // Push null if no underlying entry
                 }
             }

         } else if (blockId) {
             entryType = 'block';
             entryData = state.currentTimetable.find(e => e.block_id == blockId);
             blockStartCell = DOM.timetableContainer.querySelector(`.grid-cell[data-block-id='${blockId}'][style*='grid-row: span']`) || cell;
             const periods = state.currentTimetable.filter(e => e.block_id == blockId).map(e => parseInt(e.period_number));
             span = periods.length > 0 ? Math.max(...periods) - Math.min(...periods) + 1 : 1;
             originalRegularBlockId = blockId; // Store the block ID itself

         } else if (entryId) {
             entryType = 'entry';
             entryData = state.currentTimetable.find(e => e.entry_id == entryId);
             blockStartCell = cell;
             span = 1;
             originalRegularEntryId = entryId; // Store the entry ID itself
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
                 originalRegularBlockId: originalRegularBlockId,  // Store underlying block ID (of first hour)
                 underlyingRegularEntries: underlyingRegularEntries || [] // *** Store ALL underlying entries ***
             }
         });
         console.log("Drag Start Data:", getState().dragData); // Log drag data

         setTimeout(() => {
             for (let i = 0; i < span; i++) {
                 const currentPeriod = parseInt(blockStartCell.dataset.period) + i;
                 const cellInBlock = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${blockStartCell.dataset.day}'][data-period='${currentPeriod}']`);
                 if (cellInBlock) {
                     cellInBlock.classList.add('dragging');
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
              if (targetCell && targetCell.classList.contains('has-entry') ) {
                  let isSelf = false; // Is the occupied cell part of the item being dragged?
                  const draggedItemType = state.dragData.type;
                  
                  // *** KORRIGIERTE isSelf LOGIK ***
                  if (draggedItemType === 'substitution') {
                      // Check if the target substitution ID is one of the IDs being dragged
                      isSelf = targetCell.dataset.substitutionId && state.dragData.originalSubIds.includes(targetCell.dataset.substitutionId);
                  } else if (draggedItemType === 'block') {
                      // Check if the target cell belongs to the dragged block
                      isSelf = targetCell.dataset.blockId && targetCell.dataset.blockId === state.dragData.originalRegularBlockId;
                  } else if (draggedItemType === 'entry') {
                      // Check if the target cell is the dragged entry
                      isSelf = targetCell.dataset.entryId && targetCell.dataset.entryId === state.dragData.originalRegularEntryId;
                  }
                  // *** ENDE KORREKTUR ***

                  if (!isSelf) { // If occupied by something else
                      cell.classList.add('drop-target-invalid');
                      const conflictType = targetCell.dataset.substitutionId ? "Vertretung" : "Unterricht";
                      cell.dataset.conflictError = `KONFLIKT (Slot belegt): In diesem Zeitraum existiert bereits ${conflictType}.`;
                      return; // Stop further checks if occupied
                  }
              }
          }


         // --- Conflict check via API (only for regular entries/blocks AND substitutions if underlying entry exists) ---
         // Substitutions moving to an empty slot don't need API check.
         // If moving a substitution AND the underlying regular entry exists, we need to check conflicts for the regular entry move.
         let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);

         if (!needsApiCheck) {
             cell.classList.add('drop-target-valid');
             updateState({ lastConflictCheckPromise: Promise.resolve({ success: true, conflicts: [] }) }); // Mock promise
             return;
         }

         // Prepare data for API conflict check (for the REGULAR entry being moved, even if dragging substitution)
          // Find the actual regular entry data using the stored IDs
          const regularEntryData = state.dragData.originalRegularBlockId
             ? state.currentTimetable.find(e => e.block_id === state.dragData.originalRegularBlockId)
             : state.currentTimetable.find(e => e.entry_id == state.dragData.originalRegularEntryId);

         // Only run API check if we found the underlying regular entry data
         if (!regularEntryData) {
             // If dragging a substitution without an underlying entry, treat as valid
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
             room_id: regularEntryData.room_id,     // Use room from the actual regular entry
             class_id: regularEntryData.class_id,   // Use class from the actual regular entry
             // Exclude the original entry/block being moved from the check
             entry_id: state.dragData.originalRegularEntryId,
             block_id: state.dragData.originalRegularBlockId,
         };

         try {
             // Store the promise in state immediately
             const conflictCheckPromise = checkConflicts(checkData);
              updateState({ lastConflictCheckPromise: conflictCheckPromise });
             // Await the result
             await conflictCheckPromise;
             // If the promise resolves without error, it's valid (checkConflicts throws on error)
             // Check if we are still hovering over the same cell
              if (cell === getState().lastDragOverCell) {
                  cell.classList.add('drop-target-valid');
              }
         } catch (error) {
             // If checkConflicts throws an error, it's invalid
              if (cell === getState().lastDragOverCell) {
                  cell.classList.add('drop-target-invalid');
                  cell.dataset.conflictError = error.message; // Store the conflict message
              }
         }
     });

     DOM.timetableContainer.addEventListener('dragleave', (e) => {
         const cell = e.target.closest('.grid-cell');
         const state = getState();
         // Remove styling only if leaving the cell we were last over
         if (cell && cell === state.lastDragOverCell) {
             cell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
             delete cell.dataset.conflictError;
             updateState({ lastDragOverCell: null }); // Reset last hovered cell
         }
     });

     DOM.timetableContainer.addEventListener('dragend', (e) => {
         // Clean up visual styles from all potentially dragged cells
          const draggedCells = DOM.timetableContainer.querySelectorAll('.grid-cell.dragging');
          draggedCells.forEach(cell => cell.classList.remove('dragging'));

         DOM.timetableContainer.classList.remove('is-dragging'); // Remove grid container class

         const state = getState();
         // Clean up target styling from the last hovered cell
         if (state.lastDragOverCell) {
             state.lastDragOverCell.classList.remove('drop-target', 'drop-target-valid', 'drop-target-invalid');
             delete state.lastDragOverCell.dataset.conflictError;
         }
         // Reset drag-related state
         updateState({ dragData: null, lastDragOverCell: null, lastConflictCheckPromise: null });
     });

     DOM.timetableContainer.addEventListener('drop', async (e) => {
         e.preventDefault(); // Prevent default drop behavior
         const cell = e.target.closest('.grid-cell');
         const state = getState();
         if (!cell || !state.dragData) return; // Exit if no drop target or no drag data

         // --- Final validation before saving ---
         // 1. Check for explicit invalid marker from dragover
         if (cell.classList.contains('drop-target-invalid')) {
             const errorMessage = cell.dataset.conflictError || "Ablegen nicht möglich: Konflikt.";
             showToast(errorMessage.split("\n")[0], 'error', 4000); // Show first line of error
             return;
         }
         // 2. Check if the cell wasn't marked valid (e.g., dragover check pending or failed silently)
         if (!cell.classList.contains('drop-target-valid')) {
             // Attempt to re-validate using the stored promise if available
             try {
                 let needsApiCheck = state.dragData.type !== 'substitution' || (state.dragData.originalRegularEntryId || state.dragData.originalRegularBlockId);
                 if (state.lastConflictCheckPromise && needsApiCheck) {
                     await state.lastConflictCheckPromise; // Wait for the check result
                 } else if (needsApiCheck) {
                     // If no promise exists and API check is needed, assume invalid
                     showToast("Ablegen nicht möglich: Konfliktprüfung unvollständig.", 'error');
                     return;
                 }
                  // If it's a substitution moving to empty or over itself, basic checks suffice
             } catch (error) {
                 // Conflict confirmed by awaiting the promise
                 showToast(error.message.split("\n")[0], 'error', 4000);
                 return;
             }
         }
          // 3. Re-check vertical fit (redundant but safe)
          const targetStartPeriod = parseInt(cell.dataset.period);
          const targetEndPeriod = targetStartPeriod + state.dragData.span - 1;
          if (targetEndPeriod > DOM.timeSlots.length) {
              showToast("Ablegen nicht möglich: Eintrag passt nicht in den Plan.", 'error');
              return;
          }

         // --- Prepare save data ---
         const targetDay = cell.dataset.day;
         const entryData = state.dragData.data; // Data of the first entry
         const savePromises = []; // Array to hold all promises for saving

         const currentYear = DOM.yearSelector.value;
         const currentWeek = DOM.weekSelector.value;
         const newDate = getDateForDayInWeek(targetDay, currentYear, currentWeek);

         console.log("--- Drop Event ---"); // Log drop event start
         console.log("Drag Data:", state.dragData); // Log the full drag data
         console.log("Target Cell:", { day: targetDay, period: targetStartPeriod }); // Log target cell

         // --- Handle Moving Substitution (and potentially underlying regular entry) ---
         if (state.dragData.type === 'substitution') {
             const originalSubIds = state.dragData.originalSubIds || [];
             const underlyingRegularEntries = state.dragData.underlyingRegularEntries || [];
             console.log("Moving Substitution Block - Original Sub IDs:", originalSubIds);
             console.log("Moving underlying regular entries:", underlyingRegularEntries);

             // 1. Move the underlying regular entries/blocks
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

                 // Calculate the span of this specific regular entry/block
                 let regularEntrySpan = 1;
                 if (regularEntry.block_id) {
                     const periods = state.currentTimetable
                         .filter(e => e.block_id === regularEntry.block_id)
                         .map(e => parseInt(e.period_number));
                     regularEntrySpan = periods.length > 0 ? Math.max(...periods) - Math.min(...periods) + 1 : 1;
                 }
                 
                 // Calculate new start/end period for this regular entry
                 // Find the *original* period for this regular entry
                 const originalPeriodForThisEntry = parseInt(regularEntry.period_number);
                 // Calculate its offset from the *start* of the dragged block
                 const periodOffset = originalPeriodForThisEntry - state.dragData.originalPeriod;
                 
                 const newStartPeriod = targetStartPeriod + periodOffset;
                 const newEndPeriod = newStartPeriod + regularEntrySpan - 1;

                 // Check if the move is valid (within bounds, etc.) - simple check
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

             // 2. Move each substitution entry
             for (let i = 0; i < state.dragData.span; i++) {
                 const currentTargetPeriod = targetStartPeriod + i;
                 const originalSubId = originalSubIds[i]; // Use the ID stored for this index

                 console.log(`Processing hour ${i + 1}/${state.dragData.span}: TargetPeriod=${currentTargetPeriod}, OriginalSubId=${originalSubId}`);

                 if (!originalSubId) {
                     console.error(`Drop Error: Missing original substitution ID for index ${i} in dragged block.`);
                     showToast(`Fehler beim Verschieben von Stunde (Index ${i}). Original-ID fehlt.`, 'error');
                     continue; // Skip this part
                 }

                 // Find the full original data using the ID (needed for details like comment etc.)
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
                 };
                  console.log(`Substitution Save Data (ID: ${originalSubId}):`, subSaveData);
                 savePromises.push(saveSubstitution(subSaveData));
             }

         }
         // --- Handle Moving Regular Entry/Block ---
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

         // --- Execute all save operations ---
         try {
              console.log(`Executing ${savePromises.length} save operations...`);
             const results = await Promise.all(savePromises);
             console.log("Save Results:", results);
             // Check if all promises resolved successfully
             const success = results.every(response => response && response.success);

             if (success) {
                 showToast("Eintrag erfolgreich verschoben.", 'success');
                 loadPlanData(); // Reload grid
             } else {
                 // Find the first error message if any promise failed
                 const firstErrorResult = results.find(r => !(r && r.success));
                 const firstError = firstErrorResult?.message; // Get message from the failed response
                 console.error("Fehler beim Speichern (Promise.all):", firstErrorResult);
                 throw new Error(firstError || "Unbekannter Fehler beim Verschieben.");
             }
         } catch (error) {
             // Error handled by apiFetch for individual calls, or thrown above for Promise.all failure
             console.error("Drop save error (catch):", error); // Log the specific error
             // Reload grid anyway to revert visual changes on error
             loadPlanData();
         }
     });


     // --- Action Modal Handlers (Copy, Templates) ---
     initializeActionModals(); // From this file

      // --- Template Editor Grid Handler ---
      DOM.templateEditorGridContainer.addEventListener('click', (e) => {
          const cell = e.target.closest('.grid-cell.template-cell');
          if (!cell) {
              clearSelectionState(getState().selection.cells); // Clear selection if clicking outside cells
              return;
          }
          // KORREKTUR: Übergebe den Template-Container an handleCellClick
          handleCellClick(cell, DOM.templateEditorGridContainer);

          // Open modal on double click
          if (e.detail === 2) {
              openModal(true); // 'true' = Template-Editor mode
          }
      });

     // --- Starte den Ladevorgang --- // Moved to planer-dashboard.js
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
     // Close modals on overlay click
     DOM.manageTemplatesModal.addEventListener('click', (e) => { if (e.target.id === 'manage-templates-modal') closeManageTemplatesModal(); });
     DOM.applyTemplateModal.addEventListener('click', (e) => { if (e.target.id === 'apply-template-modal') closeApplyTemplateModal(); });

     // Submit: Create template from current week
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

      // Submit: Apply selected template to current week
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

     // --- Template Editor Buttons ---
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
          renderTimetable(getState(), true); // Render empty grid in editor mode
          showTemplateView('editor');
      });

      DOM.backToTemplateListBtn.addEventListener('click', () => {
          // TODO: Add warning if changes are unsaved
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
                 const templates = await loadTemplates(); // Reload all templates
                 renderTemplatesList(templates); // Re-render the list
                 showTemplateView('list'); // Switch back to list view
             }
             // Errors (like duplicate name) handled by apiFetch
         } catch (error) { /* Error already shown */ }
      });
 }

 /** Rendert die Liste der Vorlagen im Verwalten-Modal */
 export const renderTemplatesList = (templates) => {
     if (!DOM.manageTemplatesList) return;
     if (!Array.isArray(templates)) { // Add check if templates is an array
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
     // Add event listeners AFTER rendering
     DOM.manageTemplatesList.querySelectorAll('.delete-template').forEach(button => {
         button.addEventListener('click', handleDeleteTemplateClick);
     });
     // NEU: Event listener for Edit buttons
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
             
             // Render the grid with the template entries
             renderTimetable(getState(), true); 
             // Switch to the editor view
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
                 const updatedTemplates = await loadTemplates(); // Reload templates and return data
                 renderTemplatesList(updatedTemplates); // Render updated list
             }
              // Errors handled by apiFetch
         } catch (error) { /* Error already shown */ }
     }
 }

