 import * as DOM from './planer-dom.js';
 import { getState, updateState, clearSelectionState, setSelectionState } from './planer-state.js';
 import { getWeekAndYear, getDateOfISOWeek, getDateForDayInWeek, escapeHtml } from './planer-utils.js';
 // Import API functions used locally
 // KORREKTUR: loadPlanData hinzugefügt
 import { checkConflicts, saveEntry, deleteEntry, saveSubstitution, deleteSubstitution, loadPlanData } from './planer-api.js';
 // KORRIGIERTER IMPORT: populateYearSelector und populateWeekSelector hinzugefügt
 import { showConflicts, hideConflicts, populateYearSelector, populateWeekSelector } from './planer-ui.js';
 // Import notifications
 import { showToast, showConfirm } from './notifications.js';

 // --- Interaktionen Teil 1: Modal-Logik ---

 /**
  * Definiert die debounced Conflict Check Funktion auf Modulebene,
  * damit sie von openModal, switchMode und initializeEntryModal aufgerufen werden kann.
  */
 const debouncedConflictCheck = () => {
     clearTimeout(getState().conflictCheckTimeout);
     updateState({
         conflictCheckTimeout: setTimeout(async () => {
             const state = getState();
             // Konfliktprüfung nur im 'regular' Modus und NICHT im Template-Editor
             if (state.activeMode !== 'regular' || (DOM.form && DOM.form.querySelector('#modal_editing_template').value === 'true')) {
                 hideConflicts();
                 return;
             }
 
             if (!state.selection || state.selection.cells.length === 0) {
                 hideConflicts();
                 return;
             }
 
             const startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
             const endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
 
             const data = {
                 year: DOM.yearSelector.value,
                 calendar_week: DOM.weekSelector.value,
                 day_of_week: DOM.form.querySelector('#modal_day_of_week').value,
                 start_period_number: startPeriod,
                 end_period_number: endPeriod,
                 teacher_id: DOM.form.querySelector('#teacher_id').value,
                 room_id: DOM.form.querySelector('#room_id').value,
                 class_id: state.editingClassId, // Use the stored class ID
                 entry_id: DOM.form.querySelector('#entry_id').value || null,
                 block_id: DOM.form.querySelector('#block_id').value || null,
             };
 
             // Don't check if essential data is missing
             if (!data.teacher_id || !data.room_id || !data.class_id || !data.day_of_week || !data.start_period_number) {
                 hideConflicts();
                 return;
             }
 
             try {
                 // This will throw an error if conflicts exist, handled by apiFetch and catch block
                 await checkConflicts(data);
                 // If checkConflicts didn't throw, hide any previous warnings
                 hideConflicts();
             } catch (error) {
                 // Error is already handled by apiFetch (shows toast)
                 // We need to show the conflict details in the modal
                 console.error("Fehler bei Konfliktprüfung (gefangen):", error);
                 if (error.message) {
                     showConflicts(error.message.split("\n"));
                 } else {
                     showConflicts(["Unbekannter Fehler bei der Konfliktprüfung."]);
                 }
             }
         }, 300) // 300ms debounce time
     });
 };
 
 /** Setzt die Haupt-Selektoren auf die aktuelle Woche */
 export function setDefaultSelectors() {
     const today = new Date();
     const { week, year } = getWeekAndYear(today);
     populateYearSelector(DOM.yearSelector, year);
     populateWeekSelector(DOM.weekSelector, week);
 
     const dayOfWeek = today.getDay();
     if (dayOfWeek >= 1 && dayOfWeek <= 5) {
         DOM.dateSelector.value = today.toISOString().split('T')[0];
     } else {
         DOM.dateSelector.value = '';
     }
 }
 
 /** * Behandelt Klicks auf Zellen im Grid
  * @param {HTMLElement} cell - Die angeklickte Zelle
  * @param {HTMLElement} container - Der Grid-Container (timetableContainer oder templateEditorGridContainer)
  */
 export function handleCellClick(cell, container) {
     if (!container) { // Fallback, falls Container nicht übergeben wird
         container = DOM.timetableContainer;
     }
     const clickedDay = cell.dataset.day;
     const clickedPeriod = parseInt(cell.dataset.period);
     const state = getState();
 
     // Prevent re-selection actions if the same single cell is clicked again (unless empty)
     if (state.selection.cells.length === 1 && state.selection.cells[0] === cell && !cell.classList.contains('empty')) {
         // Allow opening modal on double click, handled elsewhere
         return;
     }
 
     const startCellData = state.selection.start ? state.selection.start.cell.dataset : {};
     // Check if the clicked cell belongs to the same block/entry/substitution group as the start cell
     const isSameGroupAsStart = (startCellData.blockId && cell.dataset.blockId === startCellData.blockId) ||
                               (startCellData.entryId && cell.dataset.entryId === startCellData.entryId) ||
                               (startCellData.substitutionId && cell.dataset.substitutionId === startCellData.substitutionId); // Added substitution check
 
     const startIsEmpty = !startCellData.entryId && !startCellData.blockId && !startCellData.substitutionId;
     const currentIsEmpty = !cell.dataset.entryId && !cell.dataset.blockId && !cell.dataset.substitutionId;
 
     // Allow selecting multiple empty cells or multiple cells of the same group
     // Reset selection if:
     // 1. No start cell exists OR
     // 2. Different day OR
     // 3. Clicked cell is FU/empty placeholder OR
     // 4. Start and current cell are not empty AND not part of the same group
     if (
         !state.selection.start ||
         clickedDay !== state.selection.start.day ||
         cell.classList.contains('default-entry') || // Prevent selecting FU cells
         (!isSameGroupAsStart && !(startIsEmpty && currentIsEmpty))
     ) {
         clearSelectionState(state.selection.cells); // Clear previous visual selection
         setSelectionState({ start: { day: clickedDay, period: clickedPeriod, cell: cell }, end: null, cells: [cell] });
         cell.classList.add('selected');
         return;
     }
 
     // --- Extend selection ---
     state.selection.end = { day: clickedDay, period: clickedPeriod };
     const startPeriod = Math.min(state.selection.start.period, state.selection.end.period);
     const endPeriod = Math.max(state.selection.start.period, state.selection.end.period);
 
     // Clear previous visual selection
     state.selection.cells.forEach(c => c.classList.remove('selected'));
     const newSelectionCells = [];
 
     // Select cells within the new range
     for (let p = startPeriod; p <= endPeriod; p++) {
         // *** KORREKTUR: Verwende den übergebenen 'container' ***
         const cellToSelect = container.querySelector(`.grid-cell[data-day='${clickedDay}'][data-period='${p}']`);
         if (cellToSelect) {
             const currentCellData = cellToSelect.dataset;
             // Ensure the cells being added to the range are compatible (either all empty or all part of the initial group)
             const isCurrentSameGroup = (startCellData.blockId && currentCellData.blockId === startCellData.blockId) ||
                                        (startCellData.entryId && currentCellData.entryId === startCellData.entryId) ||
                                        (startCellData.substitutionId && currentCellData.substitutionId === startCellData.substitutionId);
             const isCurrentEmpty = !currentCellData.entryId && !currentCellData.blockId && !currentCellData.substitutionId;
 
             // If the start was empty, only allow selecting other empty cells
             // If the start was not empty, only allow selecting cells from the same group
             if ((startIsEmpty && !isCurrentEmpty) || (!startIsEmpty && !isCurrentSameGroup)) {
                 // Incompatible cell found in range, reset selection to the *currently clicked* cell
                 clearSelectionState(state.selection.cells.concat(newSelectionCells)); // Clear old and potentially partially new selection
                 setSelectionState({ start: { day: clickedDay, period: clickedPeriod, cell: cell }, end: null, cells: [cell] }); // Select only the clicked cell
                 cell.classList.add('selected');
                 return; // Stop extending
             }
 
             cellToSelect.classList.add('selected');
             newSelectionCells.push(cellToSelect);
         }
     }
     // Update state with the new selection range
     state.selection.cells = newSelectionCells;
     // Ensure start cell and period reflect the actual start of the selected block
     state.selection.start.cell = newSelectionCells[0];
     state.selection.start.period = parseInt(newSelectionCells[0].dataset.period);
     setSelectionState(state.selection); // Update the global state
 }
 
 
 /** Logik für das Haupt-Eintragsmodal */
 export function initializeEntryModal() {
     // --- Konfliktprüfung ---
     // Die Funktion debouncedConflictCheck ist jetzt auf Modulebene definiert.
     // Wir fügen hier nur die Event-Listener hinzu.
 
     DOM.conflictCheckFields.forEach(field => {
         field.addEventListener('change', debouncedConflictCheck);
     });
     // --- Ende Konfliktprüfung ---
 
 
     DOM.modalTabs.forEach(tab => tab.addEventListener('click', () => switchMode(tab.dataset.mode)));
     DOM.substitutionTypeSelect.addEventListener('change', () => {
         updateSubstitutionFields();
         hideConflicts(); // Hide conflicts when switching substitution type
     });
 
     // --- Form Submit ---
     DOM.form.addEventListener('submit', async (e) => {
         e.preventDefault();
         // Prevent saving if conflict warning is visible
         if (DOM.conflictWarningBox && DOM.conflictWarningBox.style.display !== 'none') {
             showToast("Speichern nicht möglich: Es bestehen Konflikte.", 'error');
             return;
         }
 
         const formData = new FormData(DOM.form);
         const state = getState();
 
         // Clean up form data based on mode
         if (state.activeMode === 'regular') {
             formData.set('comment', DOM.regularCommentInput.value); // Ensure correct comment is set
             // Remove substitution-specific fields
             formData.delete('substitution_type');
             formData.delete('new_teacher_id');
             formData.delete('new_subject_id');
             formData.delete('new_room_id');
             formData.delete('original_subject_id'); // Make sure this is not sent
         } else { // Substitution mode
             formData.set('comment', DOM.substitutionCommentInput.value); // Ensure correct comment is set
         }
 
         const data = Object.fromEntries(formData.entries());
         let promise;
 
         if (state.activeMode === 'substitution') {
             // Add date and potentially original subject ID for substitution save
             const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
             data.date = getDateForDayInWeek(dayOfWeek, DOM.yearSelector.value, DOM.weekSelector.value);
 
             // Find original subject ID from the first selected cell's regular entry
             const startCell = state.selection.cells[0];
             const entryId = startCell.dataset.entryId || '';
             const blockId = startCell.dataset.blockId || '';
             // Find the regular entry associated with the first cell of the selection
             const regularEntry = blockId
                 ? state.currentTimetable.find(e => e.block_id === blockId && e.day_of_week == dayOfWeek && e.period_number == startCell.dataset.period)
                 : state.currentTimetable.find(e => e.entry_id == entryId);
 
             data.original_subject_id = regularEntry?.subject_id || null;
             data.class_id = state.editingClassId; // Ensure class_id is set for substitution
             if (!data.class_id) {
                 showToast("Fehler: Klasse für Vertretung konnte nicht ermittelt werden.", 'error');
                 return; // Stop if class ID is missing
             }
             promise = saveSubstitution(data);
         } else { // Regulärer Modus
             // Add multi-period info, year, week, and class_id
             const startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
             const endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
             data.start_period_number = startPeriod;
             data.end_period_number = endPeriod;
             data.year = DOM.yearSelector.value;
             data.calendar_week = DOM.weekSelector.value;
             data.class_id = state.editingClassId; // Verwende die gespeicherte Klasse
             if (!data.class_id) {
                 // If class ID is still null/undefined here (e.g., in teacher mode without selecting a class in template editor)
                  if (state.currentViewMode === 'class' && state.selectedClassId) {
                      data.class_id = state.selectedClassId;
                  } else if (DOM.form.querySelector('#modal_editing_template').value === 'true') {
                      data.class_id = DOM.modal.querySelector('#template_class_id').value || '0'; // Use selected or default '0' for template
                  }
                  else {
                      showToast("Fehler: Konnte Klasse nicht ermitteln.", 'error');
                      return; // Prevent saving without class_id
                  }
             }
              // Ensure class_id is '0' if it's explicitly set to that (e.g., from template editor default)
             if (data.class_id === '0') data.class_id = '0'; // Explicitly keep '0' if selected
 
             promise = saveEntry(data);
         }
 
         // Execute save and handle response
         try {
             const response = await promise;
             if (response.success) {
                 showToast("Änderungen erfolgreich gespeichert.", 'success');
                 closeModal();
                 loadPlanData(); // Reload data after saving
             }
             // Errors are handled by apiFetch
         } catch (error) { /* Error already shown by apiFetch */ }
     });
 
     // --- Delete Button ---
     DOM.deleteBtn.addEventListener('click', async () => {
         let body, confirmMsg;
         const state = getState();
         const promises = []; // Array für mehrere Lösch-Promises
 
         if (state.activeMode === 'substitution') {
             const subId = DOM.form.querySelector('#substitution_id').value;
             if (!subId) {
                 showToast("Fehler: Keine Vertretungs-ID gefunden.", 'error');
                 return;
             }
             
             confirmMsg = 'Soll diese Vertretung UND die zugrundeliegende reguläre Stunde gelöscht werden?';
             
             // 1. Promise: Vertretung löschen
             promises.push(deleteSubstitution(subId));
 
             // 2. Promise: Zugrundeliegenden regulären Eintrag/Block finden und löschen
             const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
             const period = DOM.form.querySelector('#modal_period_number').value;
             
             // Finde den regulären Eintrag, der zu dieser Zelle gehört
             // Verwende die gespeicherte editingClassId, um den richtigen Eintrag im Lehrermodus zu finden
             const regularEntry = state.currentTimetable.find(e => 
                 e.day_of_week == dayOfWeek && 
                 e.period_number == period &&
                 e.class_id == state.editingClassId // Stelle sicher, dass wir den Eintrag der richtigen Klasse löschen
             );
 
             if (regularEntry) {
                 if (regularEntry.block_id) {
                     body = { block_id: regularEntry.block_id };
                     console.log("Lösche auch regulären Block:", body);
                     promises.push(deleteEntry(body));
                 } else if (regularEntry.entry_id) {
                     body = { entry_id: regularEntry.entry_id };
                     console.log("Lösche auch reguläre Stunde:", body);
                     promises.push(deleteEntry(body));
                 }
             } else {
                  console.log("Keine zugrundeliegende reguläre Stunde zum Mitlöschen gefunden.");
             }
             
             // Führe beide Löschvorgänge aus
             if (await showConfirm("Löschen bestätigen", confirmMsg)) {
                 try {
                     const responses = await Promise.all(promises);
                     // Prüfe ob alle erfolgreich waren
                     const success = responses.every(r => r && r.success);
                     if (success) {
                         showToast("Einträge erfolgreich gelöscht.", 'success');
                         closeModal();
                         loadPlanData();
                     } else {
                         throw new Error("Einige Einträge konnten nicht gelöscht werden.");
                     }
                 } catch (error) { /* Fehler wird von apiFetch oder Promise.all behandelt */ }
             }
 
         } else { // Regular mode
             const blockId = DOM.form.querySelector('#block_id').value;
             const entryId = DOM.form.querySelector('#entry_id').value;
             if (blockId) {
                 body = { block_id: blockId };
                 confirmMsg = 'Soll dieser gesamte Block wirklich gelöscht werden?';
             } else if (entryId) {
                 body = { entry_id: entryId };
                 confirmMsg = 'Soll diese reguläre Stunde wirklich gelöscht werden?';
             } else {
                 showToast("Kein Eintrag zum Löschen ausgewählt.", 'error'); return;
             }
             
             // Nur ein Promise für regulären Modus
             if (await showConfirm("Löschen bestätigen", confirmMsg)) {
                 try {
                     const response = await deleteEntry(body);
                     if (response.success) {
                         showToast("Eintrag erfolgreich gelöscht.", 'success');
                         closeModal();
                         loadPlanData(); // Reload data after deleting
                     }
                 } catch (error) { /* Error already shown */ }
             }
         }
     });
 
     // --- Cancel / Close Modal ---
     DOM.modal.addEventListener('click', (e) => {
         // Close if clicking outside the modal box or on the cancel button
         if (e.target.id === 'timetable-modal' || e.target.id === 'modal-cancel-btn') {
             closeModal();
         }
     });
 }
 
 
 /** Öffnet das Eintragsmodal und füllt es basierend auf der Auswahl */
 export function openModal(isTemplateEdit = false) {
     const state = getState();
     if (state.selection.cells.length === 0) return; // Don't open if nothing is selected
 
     DOM.form.reset(); // Clear previous data
     hideConflicts(); // Clear old conflicts
 
     const startCell = state.selection.cells[0];
     const endCell = state.selection.cells[state.selection.cells.length - 1];
     const { day, period } = startCell.dataset; // Use start cell for day/period context
     let { entryId, substitutionId, blockId, classId } = startCell.dataset; // Get IDs from start cell
 
     // Override IDs if in template edit mode
     if (isTemplateEdit) {
         entryId = startCell.dataset.templateEntryId || null; // Use template ID
         substitutionId = null; // No substitutions in templates
         blockId = startCell.dataset.blockId || null; // This is actually block_ref for templates
         DOM.form.querySelector('#modal_editing_template').value = 'true';
     } else {
         DOM.form.querySelector('#modal_editing_template').value = 'false';
     }
 
     // Set hidden fields
     DOM.form.querySelector('#modal_day_of_week').value = day;
     DOM.form.querySelector('#modal_period_number').value = period; // Base period for single selection
     // WICHTIG: Leere #entry_id und #block_id Felder; sie werden unten neu befüllt
     DOM.form.querySelector('#entry_id').value = '';
     DOM.form.querySelector('#block_id').value = '';
     DOM.form.querySelector('#substitution_id').value = substitutionId || '';
 
     // Set Modal Title
     let title;
     if (state.selection.cells.length > 1) {
         const startTime = DOM.timeSlots[startCell.dataset.period - 1].split(' - ')[0];
         const endTime = DOM.timeSlots[endCell.dataset.period - 1].split(' - ')[1];
         title = `Block bearbeiten (${DOM.days[day-1]}, ${startTime} - ${endTime})`;
     } else {
         title = `Eintrag bearbeiten (${DOM.days[day-1]}, ${DOM.timeSlots[startCell.dataset.period - 1]})`;
     }
     DOM.modalTitle.textContent = title;
 
     // --- Determine entry data and mode ---
     let entryToEdit = null;
     let regularEntryForSub = null;
     let editingClassId = null;
     let modeToSwitchTo = 'regular'; // Default mode
 
     if (isTemplateEdit) {
         DOM.modal.querySelector('#template-class-select-container').style.display = 'block'; // Show class select for templates
         // Find template entry data
         const templateData = state.currentTemplateEditorData || []; // Use current editor data
         if (blockId) entryToEdit = templateData.find(e => e.block_ref === blockId);
         else if (entryId) entryToEdit = templateData.find(e => e.template_entry_id == entryId);
         editingClassId = entryToEdit ? entryToEdit.class_id : '0'; // Default to '0' if new template entry
         modeToSwitchTo = 'regular'; // Templates only use regular mode
         DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'none'; // Hide substitution tab
     } else { // Not template editor
         DOM.modal.querySelector('#template-class-select-container').style.display = 'none'; // Hide class select
         DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'block'; // Show substitution tab
 
         if (substitutionId) {
             entryToEdit = state.currentSubstitutions.find(s => s.substitution_id == substitutionId);
             if(entryToEdit) {
                 // Find the corresponding regular entry for context
                 const subDayNum = entryToEdit.day_of_week; // Use pre-calculated day_of_week
                 regularEntryForSub = state.currentTimetable.find(e => e.day_of_week == subDayNum && e.period_number == entryToEdit.period_number && e.class_id == entryToEdit.class_id);
                 
                 // Fallback: Manchmal ist die Zelle im DOM die einzige Quelle (z.B. wenn Lehrer-Ansicht)
                 if(!regularEntryForSub) {
                      const originalCell = DOM.timetableContainer.querySelector(`.grid-cell[data-day='${subDayNum}'][data-period='${entryToEdit.period_number}']`);
                      if (originalCell?.dataset?.blockId) {
                          regularEntryForSub = state.currentTimetable.find(e => e.block_id === originalCell.dataset.blockId);
                      } else if(originalCell?.dataset?.entryId) {
                           regularEntryForSub = state.currentTimetable.find(e => e.entry_id == originalCell.dataset.entryId);
                      }
                 }
                 editingClassId = entryToEdit.class_id; // Use the class ID from the substitution
             }
             modeToSwitchTo = 'substitution';
         } else if (blockId) {
             entryToEdit = state.currentTimetable.find(e => e.block_id === blockId);
             if(entryToEdit) editingClassId = entryToEdit.class_id;
             modeToSwitchTo = 'regular';
         } else if (entryId) {
             entryToEdit = state.currentTimetable.find(e => e.entry_id == entryId);
             if(entryToEdit) editingClassId = entryToEdit.class_id;
             modeToSwitchTo = 'regular';
         } else { // New entry
              if(state.currentViewMode === 'class') editingClassId = state.selectedClassId;
              // classId is from the cell dataset, might be null in teacher view for empty cells
              else editingClassId = classId || null; // Use cell's classId if available (teacher view) or null
              modeToSwitchTo = 'regular';
         }
     }
     updateState({ editingClassId: editingClassId }); // Store the class ID being edited
 
      // *** NEU: Setze entry_id und block_id NACH der Logik ***
      if (regularEntryForSub) {
         // Wenn wir eine Vertretung bearbeiten, speichere die IDs des zugrundeliegenden Eintrags
         DOM.form.querySelector('#entry_id').value = regularEntryForSub.entry_id || '';
         DOM.form.querySelector('#block_id').value = regularEntryForSub.block_id || '';
     } else if (entryToEdit && modeToSwitchTo === 'regular') {
         // Wenn wir einen regulären Eintrag bearbeiten
         DOM.form.querySelector('#entry_id').value = isTemplateEdit ? (entryToEdit.template_entry_id || '') : (entryToEdit.entry_id || '');
         DOM.form.querySelector('#block_id').value = isTemplateEdit ? (entryToEdit.block_ref || '') : (entryToEdit.block_id || '');
     } else {
         // Neuer Eintrag
         DOM.form.querySelector('#entry_id').value = '';
         DOM.form.querySelector('#block_id').value = '';
     }
     // substitutionId wurde bereits oben gesetzt
     // *** ENDE NEU ***
 
     // --- Fill form fields based on determined entry data and mode ---
     switchMode(modeToSwitchTo, isTemplateEdit); // Switch tabs *before* filling
 
     if (modeToSwitchTo === 'regular') {
         if(entryToEdit){ // Editing existing regular entry
             DOM.form.querySelector('#subject_id').value = entryToEdit.subject_id;
             DOM.form.querySelector('#teacher_id').value = entryToEdit.teacher_id;
             DOM.form.querySelector('#room_id').value = entryToEdit.room_id;
             DOM.regularCommentInput.value = entryToEdit.comment || '';
              if (isTemplateEdit) DOM.modal.querySelector('#template_class_id').value = entryToEdit.class_id || '0';
         } else { // Creating new regular entry
             DOM.regularCommentInput.value = '';
             // Pre-select first options if available
             const { stammdaten } = getState();
             if (stammdaten.subjects?.[0]) DOM.form.querySelector('#subject_id').value = stammdaten.subjects[0].subject_id;
             if (stammdaten.teachers?.[0]) DOM.form.querySelector('#teacher_id').value = stammdaten.teachers[0].teacher_id;
             if (stammdaten.rooms?.[0]) DOM.form.querySelector('#room_id').value = stammdaten.rooms[0].room_id;
             if (isTemplateEdit) DOM.modal.querySelector('#template_class_id').value = editingClassId || '0';
         }
     } else if (modeToSwitchTo === 'substitution') {
          // Pre-fill regular fields with original entry data (disabled visually but needed for context)
          DOM.form.querySelector('#subject_id').value = regularEntryForSub?.subject_id || '';
          DOM.form.querySelector('#teacher_id').value = regularEntryForSub?.teacher_id || '';
          DOM.form.querySelector('#room_id').value = regularEntryForSub?.room_id || '';
          DOM.form.querySelector('#original_subject_id').value = regularEntryForSub?.subject_id || ''; // Hidden field
          DOM.regularCommentInput.value = regularEntryForSub?.comment || ''; // Show original comment
 
          if(entryToEdit){ // Editing existing substitution
              DOM.form.querySelector('#substitution_type').value = entryToEdit.substitution_type;
              DOM.form.querySelector('#new_teacher_id').value = entryToEdit.new_teacher_id || '';
              DOM.form.querySelector('#new_subject_id').value = entryToEdit.new_subject_id || '';
              DOM.form.querySelector('#new_room_id').value = entryToEdit.new_room_id || '';
              DOM.substitutionCommentInput.value = entryToEdit.comment || '';
          } else { // Creating new substitution for a regular entry
              DOM.form.querySelector('#substitution_type').value = 'Vertretung'; // Default to 'Vertretung'
              DOM.substitutionCommentInput.value = '';
              // Pre-fill with original data where applicable
              DOM.form.querySelector('#new_subject_id').value = regularEntryForSub?.subject_id || '';
              DOM.form.querySelector('#new_room_id').value = regularEntryForSub?.room_id || '';
              DOM.form.querySelector('#new_teacher_id').value = ''; // Default to empty teacher
          }
          updateSubstitutionFields(); // Show/hide relevant fields
     }
 
     updateDeleteButtonVisibility(); // Show/hide delete button based on context
     DOM.modal.classList.add('visible'); // Show the modal
     // *** KORREKTUR: Verwende die debouncedConflictCheck-Funktion ***
     if (!isTemplateEdit) debouncedConflictCheck(); // Perform initial conflict check if not in template editor
 }
 
 
 /** Schließt das Eintragsmodal */
 export function closeModal() {
     clearSelectionState(getState().selection.cells); // Clear visual selection in grid
     DOM.modal.classList.remove('visible'); // Hide modal
     hideConflicts(); // Clear conflict messages
     updateState({ editingClassId: null }); // Clear editing class ID
     // Reset template editor flag and class select visibility
     if (DOM.form) { // Add check if DOM.form exists
         DOM.form.querySelector('#modal_editing_template').value = 'false';
         const templateClassSelect = DOM.form.querySelector('#template-class-select-container');
         if (templateClassSelect) {
             templateClassSelect.style.display = 'none';
         }
     }
 }
 
 
 /** Wechselt den Modus (Tab) im Eintragsmodal */
 export function switchMode(mode, isTemplateEditor = false) {
     updateState({ activeMode: mode }); // Update global state
 
     // Toggle active classes for tabs and content panes
     DOM.modalTabs.forEach(tab => tab.classList.toggle('active', tab.dataset.mode === mode));
     DOM.regularFields.classList.toggle('active', mode === 'regular');
     DOM.substitutionFields.classList.toggle('active', mode === 'substitution');
 
     hideConflicts(); // Hide conflicts when switching mode
 
     if (mode === 'substitution') {
         // --- Pre-fill context for substitution ---
         const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
         if (!dayOfWeek) { // Should not happen if modal opened correctly
             showToast("Kann keine Vertretung ohne ausgewählten Tag erstellen.", 'error');
             // Force back to regular mode if day is missing
             setTimeout(() => switchMode('regular', isTemplateEditor), 0);
             return;
         }
         // Store the date for saving later
         updateState({ selectedDate: getDateForDayInWeek(dayOfWeek, DOM.yearSelector.value, DOM.weekSelector.value) });
 
         // Find the original regular entry data based on the first selected cell
         const { selection, currentTimetable } = getState();
         const startCell = selection.cells[0];
         // *** KORREKTUR: Verwende die bereits im Formular gespeicherten IDs ***
         const entryId = DOM.form.querySelector('#entry_id').value || '';
         const blockId = DOM.form.querySelector('#block_id').value || '';
 
         // Find the regular entry associated with this time slot
         const regularEntry = blockId
             ? currentTimetable.find(e => e.block_id === blockId) // Finde *irgendeinen* Eintrag des Blocks
             : currentTimetable.find(e => e.entry_id == entryId);
 
         // Store the original subject ID (important for substitution logic)
         DOM.form.querySelector('#original_subject_id').value = regularEntry?.subject_id || '';
 
         // Pre-fill substitution fields if creating a *new* substitution
         if (!DOM.form.querySelector('#substitution_id').value) {
             DOM.form.querySelector('#new_subject_id').value = regularEntry?.subject_id || '';
             DOM.form.querySelector('#new_room_id').value = regularEntry?.room_id || '';
             DOM.form.querySelector('#new_teacher_id').value = ''; // Default new teacher to empty
             DOM.substitutionCommentInput.value = ''; // Clear comment for new substitution
         }
         updateSubstitutionFields(); // Show/hide fields based on the selected substitution type
     }
     updateDeleteButtonVisibility(); // Update delete button visibility based on mode/IDs
     // *** KORREKTUR: Verwende die debouncedConflictCheck-Funktion ***
     if (!isTemplateEditor) debouncedConflictCheck(); // Check conflicts unless in template editor
 }
 
 
 /** Zeigt/Versteckt Felder im Vertretungs-Tab basierend auf dem Typ */
 export function updateSubstitutionFields() {
     const type = DOM.substitutionTypeSelect.value;
     DOM.modal.querySelectorAll('#substitution-details .sub-field').forEach(field => {
         const types = field.dataset.types ? JSON.parse(field.dataset.types) : [];
         field.style.display = types.includes(type) ? 'block' : 'none';
     });
 }
 
 /** Zeigt/Versteckt den Löschen-Button im Modal */
 export function updateDeleteButtonVisibility() {
     const entryId = DOM.form.querySelector('#entry_id').value;
     const substitutionId = DOM.form.querySelector('#substitution_id').value;
     const blockId = DOM.form.querySelector('#block_id').value;
     const isTemplateEdit = DOM.form.querySelector('#modal_editing_template').value === 'true';
 
     // Can delete if:
     // - In substitution mode AND have a substitutionId
     // - In regular mode AND (have an entryId OR blockId)
     // AND NOT in template edit mode (template entries deleted via manage modal)
     const canDelete = !isTemplateEdit && (
         (getState().activeMode === 'substitution' && substitutionId) ||
         (getState().activeMode === 'regular' && (entryId || blockId))
     );
     // *** KORREKTUR: Tippfehler 'SO' zu 'DOM' ***
     DOM.deleteBtn.style.display = canDelete ? 'block' : 'none';
 }

